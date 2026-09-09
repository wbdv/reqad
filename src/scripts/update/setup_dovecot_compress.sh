#!/bin/bash
# Enable transparent mail compression in Dovecot 2.4.
#
# This is the 2.4 equivalent of the old 2.3 'zlib' plugin config:
#
#     protocol imap { mail_plugins = $mail_plugins zlib }
#     protocol pop3 { mail_plugins = $mail_plugins zlib }
#     service imap { vsz_limit = 1024MB }
#
# Three things changed in 2.4 and each one silently breaks a copy-pasted 2.3
# block:
#
#   * The plugin was RENAMED. There is no zlib plugin any more — it is
#     'mail_compress' (/usr/lib64/dovecot/lib20_mail_compress_plugin.so).
#     Naming 'zlib' makes Dovecot fail to start with "Couldn't load required
#     plugin".
#   * mail_plugins is no longer a space-separated string you append to with
#     $mail_plugins. It is a named boolean list: mail_plugins { x = yes }.
#     Several files can each switch one on and they merge — which is why this
#     file can set mail_compress while fts.conf sets fts/fts_flatcurve.
#   * The write setting was 'zlib_save = gz' / 'zlib_save_level = 6'. It is now
#     'mail_compress_write_method = gz', and the level moved out to a global
#     'compress_gz_level'. An unknown setting is a hard parse error in 2.4.
#
# Loading globally rather than per-protocol
# -----------------------------------------
# The 2.3 config above enabled the plugin for imap and pop3 only. Do not copy
# that. Any process that READS a message body has to be able to decompress it,
# and on this stack that is more than those two: lmtp (Sieve rules that match on
# body, and the autoresponders), indexer-worker (FTS/flatcurve indexing), and
# doveadm (backups, search, force-resync). A process without the plugin does not
# error usefully — it reads gzip bytes as if they were the message. So it goes
# in the global mail_plugins block, and imap/pop3 inherit it.
#
# vsz_limit
# ---------
# Decompressing costs address space, and the default here is default_vsz_limit
# = 256M. 1G for imap matches the 2.3 config this replaces.
#
# What this does NOT do
# ---------------------
# It does not turn on compression of newly delivered mail
# (mail_compress_write_method stays unset). Reqad compresses OLD mail from cron
# instead — see scripts/compress_old_mail — so that recent mail stays plain and
# cheap to scan. Reading compressed mail is what this config enables, and it is
# a prerequisite for that cron job: turn this on FIRST, or every message the
# cron job compresses becomes unreadable.
#
# Config placement: its own file, /etc/dovecot/compress.conf, pulled in by an
# !include_try from local.conf — same reasoning as fts.conf. conf.d/ is wiped
# and rewritten wholesale by migrate_dovecot_2.4.sh; anything left there is
# deleted as a 2.3 leftover on its next self-heal run.
#
# OFF BY DEFAULT. Running this with no arguments ENABLES compression, so it is
# not what an install or an update calls — a new server must not come up with a
# feature nobody asked for. post_reqad_install.sh calls `--repair`, which
# re-applies the config only on a server that already uses compression and
# otherwise does nothing. Enable it deliberately, from the Dovecot settings page
# in the panel or by running this by hand.
#
# Modes:
#   (none)     enable, or re-apply with a different --vsz
#   --repair   enable ONLY if this server already uses compression, else no-op
#   --disable  remove the config; refused while any mailbox holds gzipped mail
#   --status   print enabled/disabled, the vsz in force, and how many mailboxes
#              hold compressed mail (this is what the panel reads)
#   --vsz N    address-space limit for imap/pop3, e.g. 512M, 1024M, 2G
#
# Safe to re-run: idempotent, and it rolls itself back if doveconf or Dovecot
# reject the result. No-op when email is disabled in server-software.ini, when
# Dovecot is absent, or when Dovecot is not 2.4.

set -uo pipefail

INI="/usr/local/reqad/etc/server-software.ini"
COMPRESS_CONF="/etc/dovecot/compress.conf"
LOCAL_CONF="/etc/dovecot/local.conf"
INCLUDE_LINE="!include_try /etc/dovecot/compress.conf"
MARKER="/var/lib/reqad/compressed-mailboxes"
ARCHIVE_CONF="/usr/local/reqad/etc/mail-archive.conf"

# Address space allowed to an imap/pop3 process. Decompressing costs address
# space and the stack default is default_vsz_limit = 256M; a process that hits
# the ceiling is killed mid-session. Overridable with --vsz so the panel's
# Dovecot settings page can raise it without owning the file format.
VSZ_LIMIT="1024M"

# enable (default) | disable | status | repair
MODE="enable"

while [ $# -gt 0 ]; do
    case "$1" in
        --vsz)     VSZ_LIMIT="${2:-}"; shift 2 ;;
        --disable) MODE="disable"; shift ;;
        --status)  MODE="status"; shift ;;
        --repair)  MODE="repair"; shift ;;
        --help|-h) sed -n '2,/^set -uo/p' "$0" | sed 's/^# \{0,1\}//; $d'; exit 0 ;;
        *) echo "Unknown option: $1" >&2; exit 2 ;;
    esac
done

if ! [[ "$VSZ_LIMIT" =~ ^[0-9]{1,6}[KMG]?$ ]]; then
    echo "  Invalid --vsz value '$VSZ_LIMIT' (expected e.g. 512M, 1024M, 2G)" >&2
    exit 2
fi

if [ "$(id -u)" -ne 0 ]; then
    echo "  This script must be run as root"
    exit 1
fi

# --- repair: re-apply, but ONLY where compression is already in use ----------
# This is the mode post_reqad_install.sh calls, and the distinction matters: an
# update must never turn a feature ON that the admin did not ask for, and mail
# compression is off by default on a new install. But it must also not leave a
# server that DOES use it broken — a dovecot package migration rewrites
# /etc/dovecot, and a server whose mail is already gzipped needs the plugin back
# or every one of those messages is served to clients as raw gzip bytes.
#
# So: no-op unless there is evidence this server uses compression. Three
# independent signals, because each covers a case the others miss:
#   1. compress.conf is present   — configured, possibly needing a refresh
#   2. the marker is non-empty    — mail IS gzipped on disk. This is the one
#                                   that must win even if every config file was
#                                   lost, because the mail cannot be read without
#                                   the plugin.
#   3. mail-archive.conf lists a mailbox — the admin opted in, so the nightly job
#                                   is about to start compressing.
if [ "$MODE" = "repair" ]; then
    IN_USE=0
    [ -f "$COMPRESS_CONF" ] && IN_USE=1
    [ -s "$MARKER" ] && IN_USE=1
    if [ -r "$ARCHIVE_CONF" ] && grep -qE '^[[:space:]]*[^#[:space:]]' "$ARCHIVE_CONF" 2>/dev/null; then
        IN_USE=1
    fi
    if [ "$IN_USE" -eq 0 ]; then
        echo "  Mail compression not in use on this server — leaving it off."
        exit 0
    fi
    # Keep whatever limit is already configured; only fall back to the default
    # when re-creating the file from nothing (signal 2 or 3 above).
    if [ -f "$COMPRESS_CONF" ]; then
        # Anchored at the start of the line so this reads the setting and not the
        # COMMENT above it, which mentions "default_vsz_limit = 256M." — that
        # sentence's full stop came back as part of the value, produced a config
        # doveconf rejected, and the rollback then took compression down on a
        # server that was using it. The pattern also refuses anything that is not
        # a plain size, so a hand-edited file cannot inject a command line here.
        # head BEFORE tr: the file sets vsz_limit twice (imap and pop3), and
        # squeezing whitespace first joined both lines into "2048M2048M".
        CUR_VSZ=$(grep -oP '^[[:space:]]*vsz_limit[[:space:]]*=[[:space:]]*\K[0-9]{1,6}[KMG]?[[:space:]]*$' "$COMPRESS_CONF" 2>/dev/null | head -1 | tr -d '[:space:]')
        if [ -n "${CUR_VSZ:-}" ]; then
            VSZ_LIMIT="$CUR_VSZ"
        else
            echo "  (could not read the current vsz_limit — using $VSZ_LIMIT)"
        fi
    fi
    MODE="enable"
fi

# --- status: report what is in force, for the panel -------------------------
# Answers from the LIVE config rather than from the file's presence: an admin
# who edited local.conf by hand can have compress.conf on disk and no plugin
# loaded, and the toggle must show what dovecot is actually doing.
if [ "$MODE" = "status" ]; then
    if doveconf -n 2>/dev/null | grep -q 'mail_compress'; then
        echo "enabled"
    else
        echo "disabled"
    fi
    v=$(doveconf -n 2>/dev/null | sed -n '/^service imap {/,/^}/p' | grep -oP 'vsz_limit\s*=\s*\K\S+')
    echo "vsz=${v:-default}"
    # Mailboxes known to hold gzipped messages. Turning the plugin off while
    # this is non-empty serves those messages to clients as raw gzip.
    if [ -s "$MARKER" ]; then
        echo "compressed_mailboxes=$(grep -c . "$MARKER")"
    else
        echo "compressed_mailboxes=0"
    fi
    exit 0
fi

# --- disable: remove the config, but never on top of compressed mail ---------
if [ "$MODE" = "disable" ]; then
    if [ -s "$MARKER" ]; then
        echo "  REFUSING to disable: $(grep -c . "$MARKER") mailbox(es) still hold gzipped messages."
        echo "  Without the plugin dovecot hands those to clients as raw gzip bytes."
        echo "  Decompress them first:"
        echo "      /usr/local/reqad/scripts/compress_old_mail --decompress --all"
        exit 1
    fi
    if [ -f "$COMPRESS_CONF" ]; then
        cp -a "$COMPRESS_CONF" "${COMPRESS_CONF}.disabled-$(date +%Y%m%d-%H%M%S)"
        rm -f "$COMPRESS_CONF"
    fi
    [ -f "$LOCAL_CONF" ] && sed -i "\|^${INCLUDE_LINE}\$|d; \|^# Mail compression (mail_compress)\$|d" "$LOCAL_CONF"
    if ! doveconf -n >/dev/null 2>&1; then
        echo "  ERROR: doveconf rejected the configuration after removing compression"
        exit 1
    fi
    if systemctl is-active --quiet dovecot; then
        systemctl restart dovecot || { echo "  ERROR: Dovecot failed to restart"; exit 1; }
    fi
    echo "  Dovecot mail compression disabled."
    exit 0
fi

# --- Skip when the email stack is disabled for this install ------------------
if [ -f "$INI" ]; then
    EMAIL=$(grep -oP '^email=\K.*' "$INI" | tr -d '[:space:]')
    if [ "${EMAIL:-1}" = "0" ]; then
        echo "  Email disabled in server-software.ini, skipping mail compression"
        exit 0
    fi
fi

# --- Require Dovecot 2.4 -----------------------------------------------------
DOVECOT_VER=$(rpm -q --qf '%{VERSION}' dovecot 2>/dev/null)
if [ -z "$DOVECOT_VER" ]; then
    echo "  Dovecot not installed, skipping mail compression setup"
    exit 0
fi
if [[ "$DOVECOT_VER" != 2.4* ]]; then
    echo "  Dovecot $DOVECOT_VER is not 2.4 — this config is 2.4-only, skipping"
    exit 0
fi

# The .so must exist before we name it, or Dovecot refuses to start. It is part
# of the dovecot core package, so this is a sanity check rather than a real
# branch — but a stripped-down build would fail here instead of at restart.
if ! ls /usr/lib64/dovecot/lib*_mail_compress_plugin.so >/dev/null 2>&1; then
    echo "  WARNING: mail_compress plugin not found in /usr/lib64/dovecot — not enabled"
    exit 0
fi

# --- Write /etc/dovecot/compress.conf ---------------------------------------
BACKUP_CONF=""
if [ -f "$COMPRESS_CONF" ]; then
    BACKUP_CONF="${COMPRESS_CONF}.prev"
    cp -a "$COMPRESS_CONF" "$BACKUP_CONF"
fi

echo "  Writing $COMPRESS_CONF ..."
cat > "$COMPRESS_CONF" <<'EOF'
# Mail compression — managed by Reqad (scripts/update/setup_dovecot_compress.sh).
# Edits here are overwritten on package update; use a separate include instead.

# The 2.4 name for what 2.3 called the 'zlib' plugin. Enabled globally, not per
# protocol: imap and pop3 are not the only processes that read message bodies —
# lmtp (Sieve body tests, autoresponders), indexer-worker (FTS) and doveadm
# (backup, search, force-resync) all do, and a process without the plugin reads
# the gzip bytes as though they were the message rather than failing loudly.
#
# This block MERGES with the other mail_plugins blocks (fts.conf); it does not
# replace them. In 2.4 mail_plugins is a named boolean list, not the 2.3
# space-separated string you appended to with $mail_plugins.
mail_plugins {
  mail_compress = yes
}

# imap and pop3 have no protocol{} block of their own, so they inherit the list
# above. lmtp DOES have one (setup_dovecot_sieve.sh writes
# 'protocol lmtp { mail_plugins { sieve = yes } }'), and in 2.4 a named list
# inside a protocol filter REPLACES the global list for that protocol rather
# than adding to it — verify with:
#     doveconf -n -f protocol=lmtp mail_plugins
# So lmtp has to be named explicitly or delivery runs without the plugin. Two
# blocks for the same filter coming from different files DO merge, which is why
# this can be stated here without touching the Sieve config.
protocol lmtp {
  mail_plugins {
    mail_compress = yes
  }
}

# Compression of NEWLY SAVED mail is deliberately left off. Reqad compresses
# mail only once it is old, from cron (scripts/compress_old_mail), so recent
# mail stays uncompressed and cheap for FTS indexing and spam/virus scanning to
# read. To compress on save instead, uncomment — gz is the only method the
# cron job's on-disk format matches:
#mail_compress_write_method = gz

# Deflate level used by anything that does compress (2.3 called this
# zlib_save_level). 6 is the gzip default: past it the ratio barely moves and
# CPU climbs sharply on mail.
compress_gz_level = 6

# Decompressing a large message costs address space, and the default ceiling is
# default_vsz_limit = 256M. An imap process that hits it is killed mid-session.
# The panel sets this from the Dovecot settings page; 1024M matches the limit
# this stack ran with under 2.3.
service imap {
  vsz_limit = __VSZ__
}

# pop3 fetches whole messages too, and RETR on a compressed mailbox has the same
# cost profile as an IMAP FETCH.
service pop3 {
  vsz_limit = __VSZ__
}
EOF
# The heredoc above is quoted, so nothing in it expands and the file cannot be
# broken by a stray $ in a comment. The one value that has to vary is patched in
# afterwards.
sed -i "s/__VSZ__/${VSZ_LIMIT}/g" "$COMPRESS_CONF"
chown root:root "$COMPRESS_CONF"
chmod 0644 "$COMPRESS_CONF"

# --- Make sure local.conf pulls it in ----------------------------------------
if [ -f "$LOCAL_CONF" ]; then
    if ! grep -qF "$INCLUDE_LINE" "$LOCAL_CONF"; then
        echo "  Adding compression include to $LOCAL_CONF ..."
        printf '\n# Mail compression (mail_compress)\n%s\n' "$INCLUDE_LINE" >> "$LOCAL_CONF"
    fi
else
    echo "  WARNING: $LOCAL_CONF missing — creating it with the compression include"
    printf '# Mail compression (mail_compress)\n%s\n' "$INCLUDE_LINE" > "$LOCAL_CONF"
    chmod 0644 "$LOCAL_CONF"
fi

rollback() {
    if [ -n "$BACKUP_CONF" ]; then
        # There WAS a working config here. Put it back and keep the include —
        # stripping it would turn a failed re-apply into an outage for whoever
        # already had compression on, which is worse than the change not landing.
        mv -f "$BACKUP_CONF" "$COMPRESS_CONF"
    else
        # Nothing here before: undo the whole thing, include line and all.
        sed -i "\|^${INCLUDE_LINE}\$|d; \|^# Mail compression (mail_compress)\$|d" "$LOCAL_CONF"
        rm -f "$COMPRESS_CONF"
    fi
}

# --- Validate, then restart --------------------------------------------------
if ! doveconf -n >/dev/null 2>&1; then
    echo "  ERROR: doveconf rejected the new configuration — rolling back"
    doveconf -n 2>&1 | tail -5 | sed 's/^/    /'
    rollback
    exit 1
fi
rm -f "${COMPRESS_CONF}.prev"

if systemctl is-active --quiet dovecot; then
    echo "  Restarting Dovecot ..."
    if ! systemctl restart dovecot; then
        echo "  ERROR: Dovecot failed to restart — disabling compression again"
        rollback
        systemctl restart dovecot || true
        exit 1
    fi
fi

# --- Verify the plugin actually loaded ---------------------------------------
# doveconf accepting the file only proves it parses. Confirm the setting really
# is in the running config.
if doveconf -n 2>/dev/null | grep -q 'mail_compress'; then
    echo "  Dovecot mail compression enabled (mail_compress, imap/pop3 vsz_limit ${VSZ_LIMIT})."
else
    echo "  WARNING: mail_compress is not visible in 'doveconf -n' — check $COMPRESS_CONF"
    exit 1
fi

echo "  Dovecot can now READ compressed messages. Nothing is compressed yet:"
echo "  that is done from cron by /usr/local/reqad/scripts/compress_old_mail,"
echo "  driven by the mailbox list in /usr/local/reqad/etc/mail-archive.conf"
echo "  (empty by default = no mailbox is touched)."
exit 0
