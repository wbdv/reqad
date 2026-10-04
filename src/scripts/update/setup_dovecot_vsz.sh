#!/bin/bash
# Set the address-space limit for the mail processes that read message bodies:
# imap, pop3 and indexer-worker (service { vsz_limit }).
#
# Why this is its own script and its own file
# -------------------------------------------
# This setting used to live in /etc/dovecot/compress.conf, written by
# setup_dovecot_compress.sh. That was wrong: the limit has nothing to do with
# compression. Decompressing a message is only ONE of the things that costs an
# imap process address space — a large mailbox, a FETCH of a huge message, or a
# flatcurve FTS search will walk into the same ceiling on a server that has
# never compressed a byte. But because the value was written to compress.conf,
# and compress.conf only exists while compression is enabled, the panel's
# "Memory limit per IMAP/POP3 process" field silently did nothing on every
# server with compression off: the save was validated and then dropped on the
# floor, and dovecot kept killing imap children at the 256M default:
#
#   Fatal: master: service(imap): child 41430 returned error 83 (Out of memory
#   (service imap { vsz_limit=256 MB }, you may need to increase it)
#
# So the limit gets its own file, /etc/dovecot/limits.conf, owned start to
# finish by this script and pulled in by an !include_try from local.conf — the
# same arrangement as fts.conf and compress.conf. conf.d/ is not an option: it
# is wiped and rewritten wholesale by migrate_dovecot_2.4.sh.
#
# Ownership is one-way. setup_dovecot_compress.sh calls THIS script when it
# needs a limit raised; this script never calls back into it. It does reach into
# compress.conf for exactly one thing — stripping the legacy vsz blocks a
# pre-1.0.48 server still has — because two files setting the same key leaves
# the winner decided by include order.
#
# Modes:
#   --vsz N      set the limit, e.g. 512M, 1024M, 2G  (the default mode)
#   --status     print "vsz=<value in force>" or "vsz=default" (the panel reads this)
#   --reset      remove the file and the include; back to dovecot's own default
#   --migrate    move a legacy limit out of compress.conf; no-op if there is none
#   --no-restart apply and validate, but leave the restart to the caller
#
# A service{} setting is read by the dovecot MASTER process at startup, so this
# restarts dovecot rather than reloading it: `systemctl reload dovecot` re-reads
# the config but does not re-apply vsz_limit to the service definitions, which
# is its own way of looking like the setting "did not save".
#
# Safe to re-run: idempotent, and it rolls back if doveconf or dovecot reject
# the result. No-op when email is disabled in server-software.ini or when
# dovecot is not installed.

set -uo pipefail

INI="/usr/local/reqad/etc/server-software.ini"
LIMITS_CONF="/etc/dovecot/limits.conf"
COMPRESS_CONF="/etc/dovecot/compress.conf"
LOCAL_CONF="/etc/dovecot/local.conf"
INCLUDE_LINE="!include_try /etc/dovecot/limits.conf"

VSZ_LIMIT=""
MODE="set"
RESTART=1

while [ $# -gt 0 ]; do
    case "$1" in
        --vsz)        VSZ_LIMIT="${2:-}"; shift 2 ;;
        --status)     MODE="status"; shift ;;
        --reset)      MODE="reset"; shift ;;
        --migrate)    MODE="migrate"; shift ;;
        --no-restart) RESTART=0; shift ;;
        --help|-h)    sed -n '2,/^set -uo/p' "$0" | sed 's/^# \{0,1\}//; $d'; exit 0 ;;
        *) echo "Unknown option: $1" >&2; exit 2 ;;
    esac
done

if [ "$(id -u)" -ne 0 ]; then
    echo "  This script must be run as root"
    exit 1
fi

# --- status: answer from the LIVE config ------------------------------------
# Not from the file's presence: an admin can have limits.conf on disk and a
# hand-edited local.conf overriding it further down, and the panel has to show
# what dovecot is actually enforcing.
if [ "$MODE" = "status" ]; then
    CONF=$(doveconf -n 2>/dev/null)
    svc_vsz() { printf '%s\n' "$CONF" | sed -n "/^service $1 {/,/^}/p" | grep -oP 'vsz_limit\s*=\s*\K\S+'; }

    v=$(svc_vsz imap)
    echo "vsz=${v:-default}"

    # Whether all three services actually agree. They can disagree on a server
    # someone set up by hand -- setting imap and pop3 but not indexer-worker is
    # the obvious way to do it, and it was the interim advice given for v228
    # before indexer-worker was part of this file. The panel needs to know,
    # because it skips the write when the value you picked already matches, and
    # matching on imap alone would leave indexer-worker at 256M permanently with
    # the page reporting the limit as set. Reported rather than silently
    # repaired: this script writes only when it is asked to.
    UNIFORM=yes
    for s in pop3 indexer-worker; do
        [ "$(svc_vsz "$s")" = "$v" ] || UNIFORM=no
    done
    echo "uniform=$UNIFORM"
    exit 0
fi

# --- Skip when the email stack is disabled for this install ------------------
if [ -f "$INI" ]; then
    EMAIL=$(grep -oP '^email=\K.*' "$INI" | tr -d '[:space:]')
    if [ "${EMAIL:-1}" = "0" ]; then
        echo "  Email disabled in server-software.ini, skipping vsz_limit setup"
        exit 0
    fi
fi

if ! rpm -q dovecot >/dev/null 2>&1 && ! command -v doveconf >/dev/null 2>&1; then
    echo "  Dovecot not installed, skipping vsz_limit setup"
    exit 0
fi

# --- Strip the legacy blocks out of compress.conf ---------------------------
# Pre-1.0.48 compress.conf carried "service imap { vsz_limit = N }" itself.
# Leaving it there means two files set the same key and include order picks the
# winner. Drop the block, and the contiguous run of comment lines directly above
# it that described it, but ONLY when the block contains nothing except the
# vsz_limit line — a block someone has added anything else to is left alone.
strip_legacy_vsz() {
    [ -f "$COMPRESS_CONF" ] || return 0
    grep -qE '^[[:space:]]*vsz_limit[[:space:]]*=' "$COMPRESS_CONF" || return 0

    local tmp="${COMPRESS_CONF}.vszstrip.$$"
    awk '
        # Buffer comment paragraphs: they belong to whatever follows, so they
        # are only emitted once we know the next block survives.
        /^[[:space:]]*#/ { cbuf[++cn] = $0; next }
        /^[[:space:]]*$/ { if (cn) { cbuf[++cn] = $0 } else { print } ; next }

        /^service (imap|pop3) \{[[:space:]]*$/ {
            block = $0; bn = 0; only_vsz = 1
            while ((getline line) > 0) {
                if (line ~ /^\}/) break
                bn++
                if (line !~ /^[[:space:]]*vsz_limit[[:space:]]*=/ && line !~ /^[[:space:]]*$/)
                    only_vsz = 0
                body[bn] = line
            }
            if (only_vsz && bn > 0) { cn = 0; next }   # drop block + its comments
            for (i = 1; i <= cn; i++) print cbuf[i]
            cn = 0
            print block
            for (i = 1; i <= bn; i++) print body[i]
            print "}"
            next
        }

        { for (i = 1; i <= cn; i++) print cbuf[i]; cn = 0; print }
        END { for (i = 1; i <= cn; i++) print cbuf[i] }
    ' "$COMPRESS_CONF" > "$tmp" || { rm -f "$tmp"; return 0; }

    if [ -s "$tmp" ]; then
        cat >> "$tmp" <<'EOF'

# The imap/pop3 vsz_limit used to be set here. It is not a compression setting —
# it applies just as much on a server that never compresses — so it moved to
# /etc/dovecot/limits.conf (scripts/update/setup_dovecot_vsz.sh).
EOF
        cp -a "$COMPRESS_CONF" "${COMPRESS_CONF}.pre-vsz-split"
        cat "$tmp" > "$COMPRESS_CONF"
        echo "  Removed the legacy vsz_limit blocks from $COMPRESS_CONF"
    fi
    rm -f "$tmp"
}

# --- migrate: lift an existing limit out of compress.conf, then stop ---------
# For the update path. Does nothing at all on a server that has no legacy
# block, so post_reqad_install.sh can call it unconditionally.
if [ "$MODE" = "migrate" ]; then
    if [ ! -f "$COMPRESS_CONF" ] || ! grep -qE '^[[:space:]]*vsz_limit[[:space:]]*=' "$COMPRESS_CONF"; then
        exit 0
    fi
    # Anchored, and the value pattern refuses anything that is not a plain size,
    # so the sentence "default_vsz_limit = 256M." in the comments above cannot
    # come back as a value with a full stop attached — that produced a config
    # doveconf rejected the last time this was read loosely.
    # head BEFORE tr: the file sets it twice (imap and pop3) and squeezing
    # whitespace first joined the two lines into "1024M1024M".
    VSZ_LIMIT=$(grep -oP '^[[:space:]]*vsz_limit[[:space:]]*=[[:space:]]*\K[0-9]{1,6}[KMG]?[[:space:]]*$' "$COMPRESS_CONF" 2>/dev/null | head -1 | tr -d '[:space:]')
    if [ -z "${VSZ_LIMIT:-}" ]; then
        echo "  Could not read the legacy vsz_limit from $COMPRESS_CONF — leaving it alone"
        exit 0
    fi
    echo "  Migrating vsz_limit $VSZ_LIMIT out of compress.conf into $LIMITS_CONF ..."
    MODE="set"
fi

# --- reset: remove the file and the include ---------------------------------
if [ "$MODE" = "reset" ]; then
    if [ -f "$LIMITS_CONF" ]; then
        cp -a "$LIMITS_CONF" "${LIMITS_CONF}.removed-$(date +%Y%m%d-%H%M%S)"
        rm -f "$LIMITS_CONF"
    fi
    [ -f "$LOCAL_CONF" ] && sed -i "\|^${INCLUDE_LINE}\$|d; \|^# IMAP/POP3 process memory limit\$|d" "$LOCAL_CONF"
    if ! doveconf -n >/dev/null 2>&1; then
        echo "  ERROR: doveconf rejected the configuration after removing $LIMITS_CONF"
        exit 1
    fi
    if [ "$RESTART" -eq 1 ] && systemctl is-active --quiet dovecot; then
        systemctl restart dovecot || { echo "  ERROR: Dovecot failed to restart"; exit 1; }
    fi
    echo "  Mail process memory limit reset to the dovecot default."
    exit 0
fi

# --- set ---------------------------------------------------------------------
if [ -z "$VSZ_LIMIT" ]; then
    echo "  Nothing to do: pass --vsz N (e.g. --vsz 512M), --status, --reset or --migrate" >&2
    exit 2
fi
if ! [[ "$VSZ_LIMIT" =~ ^[0-9]{1,6}[KMG]?$ ]]; then
    echo "  Invalid --vsz value '$VSZ_LIMIT' (expected e.g. 512M, 1024M, 2G)" >&2
    exit 2
fi

strip_legacy_vsz

BACKUP_CONF=""
if [ -f "$LIMITS_CONF" ]; then
    BACKUP_CONF="${LIMITS_CONF}.prev"
    cp -a "$LIMITS_CONF" "$BACKUP_CONF"
fi

echo "  Writing $LIMITS_CONF (vsz_limit ${VSZ_LIMIT}) ..."
cat > "$LIMITS_CONF" <<'EOF'
# Mail process memory limits — managed by Reqad
# (scripts/update/setup_dovecot_vsz.sh). Edits here are overwritten; set it from
# the panel's Dovecot settings page instead.
#
# One value for every process that reads or indexes a message body: imap, pop3
# and indexer-worker. They all inherit the same default_vsz_limit and they all
# fail the same way, so setting one and not the others just moves the error.

# Address space a single imap process may use. Dovecot's own default is
# default_vsz_limit = 256M, and a process that walks into the ceiling is KILLED
# mid-session — the user sees the connection drop while opening a large message
# or running a search, and the mail log carries:
#     Fatal: master: service(imap): child NNN returned error 83 (Out of memory
#     (service imap { vsz_limit=256 MB }, you may need to increase it)
# This is address space, not resident memory: raising it does not hand the
# process more RAM, it stops dovecot refusing the mapping. Large mailboxes, FTS
# searches and reading compressed mail all push against it.
service imap {
  vsz_limit = __VSZ__
}

# pop3 RETR pulls whole messages and has the same cost profile as an IMAP FETCH.
service pop3 {
  vsz_limit = __VSZ__
}

# The FTS indexer is the process that most often hits the ceiling FIRST, and
# raising only imap hides that rather than fixing it: an imap session asks the
# indexer to index a mailbox, the indexer dies on its own limit, and what the
# mail log shows is
#     indexer-worker(user): Fatal: master: service(indexer-worker): child NNN
#     returned error 83 (Out of memory (service indexer-worker { vsz_limit=256 MB })
#     imap(user): Error: Mailbox INBOX: indexer failed to index mailbox
# — an imap-side error caused by a limit that is not imap's. It indexes whole
# message bodies and walks the maildir uidlist for the entire mailbox in one go
# (maildir_uidlist_sync_next_uid -> p_strdup is where a big INBOX runs out), so
# it needs the same headroom as a FETCH, and it inherits default_vsz_limit = 256M
# exactly like the others.
service indexer-worker {
  vsz_limit = __VSZ__
}
EOF
# The heredoc is quoted so nothing in it expands and a stray $ in a comment
# cannot break the file. The one value that varies is patched in afterwards.
sed -i "s/__VSZ__/${VSZ_LIMIT}/g" "$LIMITS_CONF"
chown root:root "$LIMITS_CONF"
chmod 0644 "$LIMITS_CONF"

# --- Make sure local.conf pulls it in ----------------------------------------
if [ -f "$LOCAL_CONF" ]; then
    if ! grep -qF "$INCLUDE_LINE" "$LOCAL_CONF"; then
        echo "  Adding the include to $LOCAL_CONF ..."
        printf '\n# IMAP/POP3 process memory limit\n%s\n' "$INCLUDE_LINE" >> "$LOCAL_CONF"
    fi
else
    echo "  WARNING: $LOCAL_CONF missing — creating it with the include"
    printf '# IMAP/POP3 process memory limit\n%s\n' "$INCLUDE_LINE" > "$LOCAL_CONF"
    chmod 0644 "$LOCAL_CONF"
fi

rollback() {
    if [ -n "$BACKUP_CONF" ]; then
        mv -f "$BACKUP_CONF" "$LIMITS_CONF"
    else
        sed -i "\|^${INCLUDE_LINE}\$|d; \|^# IMAP/POP3 process memory limit\$|d" "$LOCAL_CONF"
        rm -f "$LIMITS_CONF"
    fi
}

if ! doveconf -n >/dev/null 2>&1; then
    echo "  ERROR: doveconf rejected the new configuration — rolling back"
    doveconf -n 2>&1 | tail -5 | sed 's/^/    /'
    rollback
    exit 1
fi
rm -f "${LIMITS_CONF}.prev"

if [ "$RESTART" -eq 0 ]; then
    echo "  vsz_limit staged at ${VSZ_LIMIT}; caller will restart dovecot."
    exit 0
fi

# A service{} setting is read by the master process at startup: reload is not
# enough, and reloading here is exactly how this would look like it had not
# saved.
if systemctl is-active --quiet dovecot; then
    echo "  Restarting Dovecot ..."
    if ! systemctl restart dovecot; then
        echo "  ERROR: Dovecot failed to restart — rolling back"
        rollback
        systemctl restart dovecot || true
        exit 1
    fi
fi

# --- Verify it is actually in force ------------------------------------------
# doveconf accepting the file only proves it parses.
LIVE=$(doveconf -n 2>/dev/null | sed -n '/^service imap {/,/^}/p' | grep -oP 'vsz_limit\s*=\s*\K\S+')
LIVE_IDX=$(doveconf -n 2>/dev/null | sed -n '/^service indexer-worker {/,/^}/p' | grep -oP 'vsz_limit\s*=\s*\K\S+')
if [ -n "${LIVE:-}" ]; then
    echo "  Mail process memory limit is now ${LIVE} (imap/pop3), ${LIVE_IDX:-unset} (indexer-worker)."
else
    echo "  WARNING: no vsz_limit visible in 'doveconf -n' for service imap — check $LOCAL_CONF includes $LIMITS_CONF"
    exit 1
fi
exit 0
