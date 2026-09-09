#!/bin/bash
# Migrate Dovecot from 2.3 to 2.4.
#
# Strategy (do NOT try to auto-migrate the 2.3 config — it produces a broken
# 2.4 config and leaves .rpmnew files behind):
#   1. Preserve only what is host-specific: sni.conf, users (email accounts),
#      and dh.pem. Everything else is replaced with known-good 2.4 config.
#   2. Move the whole 2.3 /etc/dovecot aside BEFORE the package upgrade so the
#      RPM lays down clean 2.4 defaults with no .rpmnew/.rpmsave noise.
#   3. Install/upgrade to 2.4, then write our own 2.4 config files over the
#      defaults and restore sni.conf + users + dh.pem.
#
# IMPORTANT: conf.d is wiped and rebuilt from scratch below. A 2.3 install ships
# ~24 conf.d files (auth-*.conf.ext, 15-mailboxes.conf, 90-*.conf, …); the 2.4
# RPM owns only 8. If any 2.3 leftover survives in conf.d (RPM restoring a
# %config file, a dnf module quirk, or a re-run after a partial migration) the
# '!include_try conf.d/*.conf' below drags it back in and the 2.4 parse fails.
# So we never trust the dir to be clean — we remove it and recreate it.
#
# Idempotent & self-healing: if already on 2.4 with clean config it exits 0; if
# already on 2.4 but old 2.3 config is still present, it re-runs the config
# rewrite to repair it (without touching the package or host data).

set -euo pipefail

usage() {
    cat <<'USAGE'
Usage: migrate_dovecot_2.4.sh [options]

  (no options)   Upgrade and/or repair only what is actually wrong: install 2.4
                 if the box is on 2.3, pick up a newer 2.4 build if one is
                 available, and rewrite the config only if conf.d is missing or
                 carrying strays, or dovecot.conf's version declaration has
                 fallen behind the packages. Exits 0 saying "nothing to do" when
                 the config is already complete.

  --recreate     Recreate ALL config files from scratch, whatever their current
  --force        state: conf.d/ is wiped and rebuilt, dovecot.conf and local.conf
                 are rewritten. Use this when you want a known-good config rather
                 than a minimal repair -- e.g. after hand-editing, or when a
                 setting was added to this script that an existing box lacks.
                 (--recreate and --force are the same switch.)

                 Host-specific data is NOT recreated: sni.conf, /etc/dovecot/users
                 and dh.pem are preserved, and the whole of /etc/dovecot is copied
                 to /etc/dovecot.backup-<timestamp> first.

  --no-upgrade   Config only -- never install or upgrade a package. Combine with
                 --recreate to rewrite the config of a box whose packages you do
                 not want touched.

  -h, --help     This text.
USAGE
}

FORCE=0
DO_PKG=1
for a in "$@"; do
    case "$a" in
        --force|--recreate) FORCE=1 ;;
        --no-upgrade)       DO_PKG=0 ;;
        -h|--help)          usage; exit 0 ;;
        *) echo "unknown option: $a" >&2; echo >&2; usage >&2; exit 1 ;;
    esac
done

BACKUP="/etc/dovecot.backup-2.3"
REPO="/etc/yum.repos.d/dovecot.repo"
# dovecot_config_version / dovecot_storage_version declare which release the
# config was WRITTEN for; dovecot then keeps that release's defaults for any
# setting whose default has since changed. Hardcoding 2.4.1 meant a box upgraded
# to 2.4.5 kept running on 2.4.1 semantics forever ("33 default setting changes
# since version 2.4.1" in `doveconf -n`). Derive it from the installed package
# instead, so the declaration tracks the binary.
#
# Bumping the STORAGE version is the one with on-disk consequences. Measured on
# 2.4.1 -> 2.4.5 with this maildir layout: of 851 effective settings exactly
# three move, and none of them apply here --
#   lazy_expunge_only_last_instance  no  -> yes  (plugin not loaded)
#   mailbox_directory_name_legacy    yes -> no   (no mailbox_directory_name set)
#   passdb_default_password_scheme   PLAIN -> CRYPT (passdb sets CRYPT already)
# `doveadm mailbox list` returned an identical folder set for all 17 mailboxes
# under both. Re-check this if the storage layout ever stops being maildir.
installed_dovecot_ver() {
    local v
    v=$(rpm -q --qf '%{VERSION}' dovecot 2>/dev/null || true)
    case "$v" in
        2.4*) echo "$v" ;;
        *)    echo "2.4.1" ;;   # not installed / unreadable: safest floor
    esac
}
DOVECOT_VER=$(installed_dovecot_ver)

# The 2.4 config we lay down: exactly these files belong in conf.d. Anything
# else is a 2.3 leftover that would be included by '!include_try conf.d/*.conf'.
EXPECTED_CONFD="10-auth.conf 10-mail.conf 10-managesieve.conf 10-master.conf 10-ssl.conf 20-imap.conf 20-lmtp.conf 20-managesieve.conf 20-pop3.conf 20-submission.conf 90-sieve.conf"

# The conf.d files this script actually writes. EXPECTED_CONFD above is the
# wider "allowed to exist" set (it also covers RPM-owned files); this is the set
# that must be PRESENT for the config to be complete. A 2.4 box can be free of
# strays and still be missing half of these — that is exactly how a server ends
# up on 2.4 with no 90-sieve.conf, reported as "clean, nothing to do".
# The protocol files are added below, per protocol package actually installed:
# `protocols { imap = yes }` without dovecot-imapd is not a parse error, it is a
# startup failure (master cannot exec /usr/libexec/dovecot/imap), so what we
# write has to follow what is installed.
WRITTEN_CONFD="10-auth.conf 10-mail.conf 10-master.conf 10-ssl.conf 20-submission.conf"
# 20-managesieve.conf and 90-sieve.conf are only written when Pigeonhole is
# installed: `sieve_script` and `protocols { sieve = yes }` are provided BY that
# plugin, so writing them without it is a fatal config error —
#   Unknown section name: sieve_script
# and dovecot refuses to start. HAVE_SIEVE is decided below.
SIEVE_CONFD="20-managesieve.conf 90-sieve.conf"

# Echo any file we are supposed to write that is not on disk.
missing_confd() {
    local base
    for base in $WRITTEN_CONFD; do
        [ -f "/etc/dovecot/conf.d/$base" ] || echo "$base"
    done
}

# Echo the basename of any conf.d file that is NOT in our known-good 2.4 set.
stray_confd() {
    [ -d /etc/dovecot/conf.d ] || return 0
    local f base
    # Only *.conf matters: that is what `!include_try conf.d/*.conf` pulls in.
    # Timestamped backups (90-sieve.conf.bak-20260829, and the ones the sieve
    # setup scripts leave behind) are inert, and flagging them used to send this
    # script off to "repair" a perfectly good config.
    for f in /etc/dovecot/conf.d/*.conf; do
        [ -e "$f" ] || continue
        base=$(basename "$f")
        case " $EXPECTED_CONFD " in
            *" $base "*) ;;        # expected — ok
            *) echo "$base" ;;     # stray/old 2.3 file
        esac
    done
}

# Echo a reason per problem with /etc/dovecot/dovecot.conf itself. conf.d is not
# the whole config: the main file carries the version declaration and the two
# !include_try lines, and nothing else was ever checking it. A box could have a
# complete conf.d and still be running an untouched 2.3-era dovecot.conf, or a
# 2.4.1 declaration long after the packages moved to 2.4.5.
mainconf_issues() {
    local f=/etc/dovecot/dovecot.conf
    if [ ! -f "$f" ]; then echo "dovecot.conf is missing"; return 0; fi
    local cv sv
    cv=$(sed -n 's/^[[:space:]]*dovecot_config_version[[:space:]]*=[[:space:]]*//p'  "$f" | head -1)
    sv=$(sed -n 's/^[[:space:]]*dovecot_storage_version[[:space:]]*=[[:space:]]*//p' "$f" | head -1)
    if [ -z "$cv" ] || [ -z "$sv" ]; then
        echo "dovecot.conf has no version declaration (pre-2.4 file)"
    elif [ "$cv" != "$DOVECOT_VER" ] || [ "$sv" != "$DOVECOT_VER" ]; then
        echo "dovecot.conf declares ${cv:-?}/${sv:-?}, packages are $DOVECOT_VER"
    fi
    grep -q '^!include_try conf.d/\*\.conf' "$f" || echo "dovecot.conf does not include conf.d/"
    grep -q '^!include_try local.conf'        "$f" || echo "dovecot.conf does not include local.conf"
}

# --- sni.conf: 2.3 syntax on a 2.4 box --------------------------------------
# sni.conf holds the per-domain certificates and is host data, so it is
# preserved rather than rewritten -- but this script has always copied it
# forward VERBATIM, which is how a migrated box ends up on 2.4 carrying 2.3
# syntax that dovecot refuses to parse:
#   Fatal: Error in configuration file /etc/dovecot/sni.conf line 4:
#          ssl_cert: Unknown setting: ssl_cert
# It stays invisible until something makes dovecot re-read the file -- and then
# it fails the config check and rolls back a rebuild that was fine.
# 2.4 changed two things:
#   ssl_cert = </path  ->  ssl_server_cert_file = /path   (no `<` read-file prefix)
#   ssl_key  = </path  ->  ssl_server_key_file  = /path
# and local_name no longer takes a quoted space-separated list, so
#   local_name "mail.example.com example.com" { ... }
# becomes one block per name -- which is what scripts/update_email_sni emits on
# a 2.4 box, so a converted file matches what the panel would regenerate.
SNI_GEN=/usr/local/reqad/scripts/update_email_sni

sni_is_23() {
    [ -f "$1" ] || return 1
    grep -qE '^[[:space:]]*ssl_(cert|key)[[:space:]]*=' "$1" && return 0
    grep -qE '^[[:space:]]*local_name[[:space:]]+"[^"]*[[:space:]]' "$1" && return 0
    return 1
}

sni_to_24() {
    awk '
    /^[[:space:]]*local_name[[:space:]]/ {
        line = $0
        sub(/^[[:space:]]*local_name[[:space:]]+/, "", line)
        sub(/[[:space:]]*\{[[:space:]]*$/, "", line)
        gsub(/"/, "", line)
        nn = split(line, nm, /[[:space:]]+/)
        inblock = 1; body = ""
        next
    }
    inblock && /^[[:space:]]*\}/ {
        for (i = 1; i <= nn; i++) printf "local_name %s {\n%s}\n\n", nm[i], body
        inblock = 0; body = ""
        next
    }
    inblock {
        l = $0
        sub(/^[[:space:]]*ssl_cert[[:space:]]*=[[:space:]]*</, "  ssl_server_cert_file = ", l)
        sub(/^[[:space:]]*ssl_key[[:space:]]*=[[:space:]]*</,  "  ssl_server_key_file = ",  l)
        sub(/^[[:space:]]*ssl_cert[[:space:]]*=[[:space:]]*/,  "  ssl_server_cert_file = ", l)
        sub(/^[[:space:]]*ssl_key[[:space:]]*=[[:space:]]*/,   "  ssl_server_key_file = ",  l)
        body = body l "\n"
        next
    }
    { print }
    ' "$1" > "$2"
}

# The generator is authoritative -- it reads the accounts table and the live
# vhosts and already emits the right spelling for the installed dovecot -- so
# run it, and only rewrite the file in place if it cannot (email disabled in
# server-software.ini, no DB, not installed).
fix_sni() {
    local f=/etc/dovecot/sni.conf
    sni_is_23 "$f" || return 0
    echo "  sni.conf still uses 2.3 syntax (ssl_cert = <...), which 2.4 rejects."
    if [ -x "$SNI_GEN" ]; then
        echo "  Regenerating it with $SNI_GEN ..."
        # Its own `systemctl restart dovecot` can fail here (we may be mid-rewrite);
        # harmless, the restart below is the one that counts.
        "$SNI_GEN" >/dev/null 2>&1 || true
        if ! sni_is_23 "$f"; then echo "  Regenerated."; return 0; fi
        echo "  Generator did not update it — converting in place instead."
    fi
    cp -a "$f" "$f.bak-23-$(date +%Y%m%d-%H%M%S)"
    sni_to_24 "$f" "$f.tmp24"
    mv -f "$f.tmp24" "$f"
    chown root:root "$f"; chmod 0644 "$f"
    echo "  Converted to 2.4 syntax (original kept alongside as sni.conf.bak-23-*)."
}

# --- Root check ---
[ "$(id -u)" -eq 0 ] || { echo "ERROR: Must run as root."; exit 1; }

CURRENT_VER=$(rpm -q --qf '%{VERSION}' dovecot 2>/dev/null || echo "")

# --- Pigeonhole (sieve) present? --------------------------------------------
# The config this script writes declares the Sieve tiers, which only parse when
# dovecot-sieve/dovecot-managesieved are installed. Install them unless the
# caller said not to touch packages; if they are still absent, the sieve config
# is skipped rather than written into a fatal error.
# Every subpackage this config needs. In 2.4 each protocol is its own package
# and `dnf update` NEVER adds one — it only upgrades what is already installed —
# so a box that reached 2.4 without dovecot-imapd stays without it through every
# future update, and the panel's IMAP settings quietly do nothing. Found in the
# field on a live server: dovecot 2.4.5 with no dovecot-imapd at all.
#
# dovecot-submissiond is deliberately NOT here: exim is the MSA on 587/465 and
# 20-submission.conf below disables the protocol on purpose.
REQUIRED_PKGS="dovecot-imapd dovecot-pop3d dovecot-lmtpd dovecot-sieve dovecot-managesieved"

# Install whatever is missing, in ONE transaction pinned to the version dovecot
# itself is at. Separate transactions (or an unpinned install) is how a box ends
# up with dovecot 2.4.5 and dovecot-sieve 2.4.1 from a different repo — a plugin
# built against another release fails to load and takes delivery down with it.
ensure_subpackages() {
    local missing="" p
    for p in $REQUIRED_PKGS; do
        rpm -q "$p" >/dev/null 2>&1 || missing="$missing $p"
    done
    [ -n "$missing" ] || return 0

    if [ "$DO_PKG" -eq 0 ]; then
        echo "  WARNING: missing dovecot subpackages:$missing"
        echo "           --no-upgrade was given, so they are NOT installed; the"
        echo "           config below is written for what IS installed."
        return 0
    fi

    local dv
    dv=$(rpm -q --qf '%{VERSION}-%{RELEASE}' dovecot 2>/dev/null || true)
    echo "Missing dovecot subpackages:$missing"
    echo "  Installing at dovecot's own version ($dv)..."

    local want="" ok=1
    for p in $missing; do want="$want $p-$dv"; done
    if ! dnf -y install $want >/dev/null 2>&1; then
        # Version-pinned install fails when the subpackage is only in another
        # repo at a different build. Retry unpinned rather than give up: a
        # present-but-skewed package is still better than a missing one, and
        # ensure_dovecot_packages.sh reports the skew afterwards.
        echo "  Version-pinned install failed; retrying unpinned..."
        dnf -y install $missing >/dev/null 2>&1 || ok=0
    fi

    local still=""
    for p in $missing; do
        rpm -q "$p" >/dev/null 2>&1 || still="$still $p"
    done
    if [ -n "$still" ]; then
        echo "  WARNING: could not install:$still"
        echo "           The matching protocol/filter config will be SKIPPED so"
        echo "           dovecot still starts. Install them and re-run."
    else
        echo "  Installed."
    fi
    [ "$ok" -eq 1 ] || true

    # Packages changed, so the config has to be rewritten to match: enabling a
    # protocol means writing its conf.d file.
    PKG_ADDED=1
}

PKG_ADDED=0
if rpm -q dovecot >/dev/null 2>&1; then
    ensure_subpackages
fi

# What the config may declare = what is actually installed. Each flag gates both
# the conf.d file written below and the "is this config complete?" check above.
# NOTE the if/then. With `set -euo pipefail`, `[ test ] && VAR=1` as a whole
# statement ENDS THE SCRIPT the moment the test is false — the exact shape that
# would abort this file on the first box missing a subpackage, which is the case
# it exists to handle.
detect_protocol_pkgs() {
    HAVE_SIEVE=0
    if rpm -q dovecot-sieve >/dev/null 2>&1 && rpm -q dovecot-managesieved >/dev/null 2>&1; then HAVE_SIEVE=1; fi
    HAVE_IMAP=0; if rpm -q dovecot-imapd >/dev/null 2>&1; then HAVE_IMAP=1; fi
    HAVE_POP3=0; if rpm -q dovecot-pop3d >/dev/null 2>&1; then HAVE_POP3=1; fi
    HAVE_LMTP=0; if rpm -q dovecot-lmtpd >/dev/null 2>&1; then HAVE_LMTP=1; fi
}
detect_protocol_pkgs

if [ "$HAVE_IMAP"  -eq 1 ]; then WRITTEN_CONFD="$WRITTEN_CONFD 20-imap.conf"; fi
if [ "$HAVE_POP3"  -eq 1 ]; then WRITTEN_CONFD="$WRITTEN_CONFD 20-pop3.conf"; fi
if [ "$HAVE_LMTP"  -eq 1 ]; then WRITTEN_CONFD="$WRITTEN_CONFD 20-lmtp.conf"; fi
if [ "$HAVE_SIEVE" -eq 1 ]; then WRITTEN_CONFD="$WRITTEN_CONFD $SIEVE_CONFD"; fi

# A protocol file left on disk for a package that is NOT installed is worse than
# a missing one: dovecot parses it, enables the protocol, and then fails to
# start. Treat it exactly like a stray so the repair below removes it.
UNWANTED_CONFD=""
if [ "$HAVE_IMAP"  -eq 0 ]; then UNWANTED_CONFD="$UNWANTED_CONFD 20-imap.conf"; fi
if [ "$HAVE_POP3"  -eq 0 ]; then UNWANTED_CONFD="$UNWANTED_CONFD 20-pop3.conf"; fi
if [ "$HAVE_LMTP"  -eq 0 ]; then UNWANTED_CONFD="$UNWANTED_CONFD 20-lmtp.conf"; fi
if [ "$HAVE_SIEVE" -eq 0 ]; then UNWANTED_CONFD="$UNWANTED_CONFD $SIEVE_CONFD 10-managesieve.conf"; fi
for b in $UNWANTED_CONFD; do
    EXPECTED_CONFD=$(echo " $EXPECTED_CONFD " | sed "s/ $b / /g")
done

# Adding a package changes what the config must say, so force the rewrite.
if [ "$PKG_ADDED" -eq 1 ]; then FORCE=1; fi

echo "Protocols available: imap=$([ $HAVE_IMAP -eq 1 ] && echo yes || echo NO) pop3=$([ $HAVE_POP3 -eq 1 ] && echo yes || echo NO) lmtp=$([ $HAVE_LMTP -eq 1 ] && echo yes || echo NO) sieve=$([ $HAVE_SIEVE -eq 1 ] && echo yes || echo NO)"

# --- Decide: full package migration, config-only repair, or nothing ---------
UPGRADE_PKG=1
if [[ "$CURRENT_VER" == 2.4* ]]; then
    UPGRADE_PKG=0

    # ── in-2.4 upgrades (2.4.1 → 2.4.5 …) ───────────────────────────────────
    # Not a migration: same config, newer packages. The version is NOT hardcoded
    # — whichever configured repo offers the newest build wins, so this keeps
    # working when the EL8 2.4.5 backport moves from reqad-test to reqad.
    if [ "$DO_PKG" -eq 1 ]; then
        CUR_NVR=$(rpm -q --qf '%{VERSION}-%{RELEASE}' dovecot 2>/dev/null || echo "")
        AVAIL=$(dnf -q --showduplicates list available dovecot 2>/dev/null \
                | awk '$1 ~ /^dovecot(\.|$)/ {print $2}' | sed 's/^[0-9]*://' \
                | grep '^2\.4' | sort -V | tail -1)
        if [ -n "$AVAIL" ] && [ "$AVAIL" != "$CUR_NVR" ] \
           && [ "$(printf '%s\n%s\n' "$CUR_NVR" "$AVAIL" | sort -V | tail -1)" = "$AVAIL" ]; then
            echo "Dovecot $CUR_NVR → $AVAIL available; upgrading packages..."
            DOVECOT_PKGS=$(rpm -qa 'dovecot*' --qf '%{NAME} ' 2>/dev/null || true)
            dnf -y upgrade $DOVECOT_PKGS 2>&1 || {
                echo "ERROR: package upgrade failed — config left untouched." >&2
                exit 1
            }
            CURRENT_VER=$(rpm -q --qf '%{VERSION}' dovecot 2>/dev/null || echo "$CURRENT_VER")
            echo "Now on: $(rpm -q --qf '%{VERSION}-%{RELEASE}' dovecot)"
            # A package upgrade can drop .rpmnew files and re-enable protocols
            # whose binaries are not installed (submission is the usual one), so
            # always rewrite the config after one.
            FORCE=1
        fi
    fi

    # An in-2.4 package upgrade above changed what DOVECOT_VER should be, so
    # re-read it before comparing the declaration against it.
    DOVECOT_VER=$(installed_dovecot_ver)

    STRAY=$(stray_confd)
    MISS=$(missing_confd)
    MAIN=$(mainconf_issues)
    if [ -z "$STRAY" ] && [ -z "$MISS" ] && [ -z "$MAIN" ] && [ "$FORCE" -eq 0 ]; then
        echo "Dovecot is already on 2.4 ($CURRENT_VER) and config is complete. Nothing to do."
        exit 0
    fi
    echo "Dovecot is already on 2.4 ($CURRENT_VER); repairing config (packages left as-is):"
    if [ -n "$STRAY" ]; then echo "  files that do not belong:"; echo "$STRAY" | sed 's/^/    conf.d\//'; fi
    if [ -n "$MISS" ];  then echo "  files that are MISSING:";   echo "$MISS"  | sed 's/^/    conf.d\//'; fi
    if [ -n "$MAIN" ];  then echo "  dovecot.conf:";             echo "$MAIN"  | sed 's/^/    /'; fi
    if [ -z "$STRAY$MISS$MAIN" ]; then echo "  nothing was wrong -- recreating all config files on request"; fi

    # Roll back to what is on disk NOW, not to a years-old 2.3 backup.
    REPAIR_BACKUP="/etc/dovecot.backup-$(date +%Y%m%d-%H%M%S)"
    cp -a /etc/dovecot "$REPAIR_BACKUP"
    echo "  Current config saved to $REPAIR_BACKUP"
elif [[ "$CURRENT_VER" != 2.3* ]]; then
    echo "ERROR: Expected Dovecot 2.3.x or 2.4.x, found '${CURRENT_VER:-not installed}'."
    exit 1
else
    echo "Dovecot $CURRENT_VER → 2.4 migration"
fi

# --- Detect OS major version ---
OS_VER=$(grep -oP '(?<=^VERSION_ID=")\d+' /etc/os-release 2>/dev/null \
    || grep -oP '\b[89]\b' /etc/redhat-release | head -1)
echo "OS major version: $OS_VER"

# --- Stop the running daemon before touching its config ---
systemctl stop dovecot 2>/dev/null || true

if [[ "$UPGRADE_PKG" -eq 1 ]]; then
    # --- Move the entire 2.3 config aside -----------------------------------
    # Removing the live config dir before the upgrade means the RPM reinstalls
    # its %config files cleanly (no .rpmnew, since the on-disk files are absent).
    # We keep the dir as a backup and restore sni.conf / users / dh.pem from it.
    echo "Backing up /etc/dovecot/ → $BACKUP ..."
    rm -rf "$BACKUP"
    mv /etc/dovecot "$BACKUP"
    echo "Backup done."

    # --- Update repo --------------------------------------------------------
    echo "Updating $REPO ..."
    if [ "${OS_VER}" -ge 9 ]; then
        cat > "$REPO" <<'EOF'
[dovecot-2.4-latest]
name=Dovecot 2.4 RHEL $releasever - $basearch
baseurl=http://repo.dovecot.org/ce-2.4-latest/rhel/$releasever/RPMS/$basearch
gpgkey=https://repo.dovecot.org/DOVECOT-REPO-GPG-2.4
gpgcheck=1
enabled=1
EOF
    else
        cat > "$REPO" <<'EOF'
[dovecot-2.4.1]
name=Dovecot 2.4.1 RHEL $releasever - $basearch
baseurl=http://repo.dovecot.org/ce-2.4.1/rhel/$releasever/RPMS/$basearch
gpgkey=https://repo.dovecot.org/DOVECOT-REPO-GPG-2.3
gpgcheck=1
enabled=1
EOF
    fi
    echo "Repo updated."

    # --- Upgrade Dovecot and install 2.4 sub-packages -----------------------
    # In 2.4, IMAP/POP3/LMTP/submission are separate packages.
    # dovecot-submissiond is intentionally NOT installed — exim is the MSA on
    # ports 587/465 (a disabling 20-submission.conf is written below regardless).
    echo "Upgrading Dovecot and installing 2.4 sub-packages..."
    DOVECOT_PKGS=$(rpm -qa 'dovecot-*' --qf '%{NAME} ' 2>/dev/null || true)
    dnf install -y dovecot $DOVECOT_PKGS $REQUIRED_PKGS 2>&1

    # The protocol flags were read before this install; what is on the box has
    # just changed, so read them again before deciding what config to write.
    detect_protocol_pkgs
    echo "Protocols available: imap=$([ $HAVE_IMAP -eq 1 ] && echo yes || echo NO) pop3=$([ $HAVE_POP3 -eq 1 ] && echo yes || echo NO) lmtp=$([ $HAVE_LMTP -eq 1 ] && echo yes || echo NO) sieve=$([ $HAVE_SIEVE -eq 1 ] && echo yes || echo NO)"

    NEW_VER=$(rpm -q --qf '%{VERSION}' dovecot 2>/dev/null || echo "unknown")
    echo "Upgraded to: $NEW_VER"
else
    NEW_VER="$CURRENT_VER"
fi

# --- Clean any stray .rpmnew/.rpmsave (belt-and-suspenders) ------------------
find /etc/dovecot \( -name '*.rpmnew' -o -name '*.rpmsave' \) -delete 2>/dev/null || true

# --- Write known-good 2.4 config --------------------------------------------
# Authoritatively reset conf.d. The pre-upgrade backup already holds the 2.3
# originals; wiping guarantees the ONLY files '!include_try conf.d/*.conf' can
# pull in are the eight we write below — no 2.3 leftovers, whatever their origin.
echo "Writing 2.4 configuration files..."
rm -rf /etc/dovecot/conf.d
mkdir -p /etc/dovecot/conf.d

# The 2.3 path installs the packages above, so the version is only knowable now.
DOVECOT_VER=$(installed_dovecot_ver)
echo "Declaring dovecot_config_version / dovecot_storage_version = $DOVECOT_VER"

cat > /etc/dovecot/dovecot.conf <<EOF
## Dovecot configuration shipped with redhat packages

dovecot_config_version = $DOVECOT_VER
dovecot_storage_version = $DOVECOT_VER

protocols =

!include_try conf.d/*.conf
!include_try local.conf
EOF

cat > /etc/dovecot/local.conf <<'EOF'
# SSL
ssl = required
ssl_server_cert_file = /etc/pki/dovecot/certs/dovecot.pem
ssl_server_key_file = /etc/pki/dovecot/private/dovecot.pem
ssl_server_dh_file = /etc/dovecot/dh.pem
ssl_min_protocol = TLSv1.2
ssl_server_prefer_ciphers = client
!include_try /etc/dovecot/sni.conf

# Mail — override conf.d/10-mail.conf defaults
mail_path = ~/
mail_inbox_path = ~/

# Home IS the maildir root here (mail_path = ~/), so dovecot reads every ~/.name
# as an IMAP folder — which turned its own duplicate database into junk
# mailboxes visible in every client: dovecot/lda-dupes and
# dovecot/lda-dupes/locks, the latter even acquiring cur/new/tmp and an index.
#   * stat_dirs makes the lister check that a ".name" really is a directory, so
#     the .dovecot.lda-dupes FILE stops being listed as a folder.
#   * mail_volatile_path moves lock directories out of the maildir altogether,
#     which is what the ".locks" mailbox was.
# Duplicate suppression keeps working — verified: a second vacation reply to the
# same sender is still "discarded duplicate vacation response".
maildir {
  stat_dirs = yes
}
mail_volatile_path = /var/lib/reqad/volatile/%{user}

# Auth
auth_mechanisms = plain login

passdb passwd-file {
  passwd_file_path = /etc/dovecot/users
  default_password_scheme = CRYPT
}
userdb passwd-file {
  passwd_file_path = /etc/dovecot/users
}

# Full-text search (flatcurve) — written by scripts/update/setup_dovecot_fts.sh.
# Deliberately its own file outside conf.d: conf.d is wiped and rebuilt below,
# but fts.conf survives, and include_try means no error when FTS isn't set up.
!include_try /etc/dovecot/fts.conf

# Mail compression (mail_compress) — written by
# scripts/update/setup_dovecot_compress.sh. Same reasoning as fts.conf: its own
# file outside conf.d so it survives the wipe-and-rebuild below.
!include_try /etc/dovecot/compress.conf
EOF

cat > /etc/dovecot/conf.d/10-auth.conf <<'EOF'
# Auth handled by local.conf
EOF

# Submission conflicts with exim on ports 587/465. Overwrite (don't rm) so that
# if dovecot-submissiond is ever installed, RPM keeps our file and drops its
# default as 20-submission.conf.rpmnew instead of enabling the protocol.
cat > /etc/dovecot/conf.d/20-submission.conf <<'EOF'
# Submission disabled — exim is the MSA on 587/465 (no protocols block = off)
EOF

# auth-client socket must be readable by exim (runs as exim, in the mail group)
# for SMTP AUTH. The 2.4 default leaves it 0600 dovecot:root, which breaks exim
# auth — restore the 2.3 behaviour: owned by mail, mode 0660.
cat > /etc/dovecot/conf.d/10-master.conf <<'EOF'
service auth {
  unix_listener auth-client {
    mode = 0660
    user = mail
    group = mail
  }
}

# Local delivery goes exim -> LMTP -> dovecot so that Pigeonhole Sieve runs.
# The 2.4 default socket is 0600 dovecot:root, which exim (running as exim, in
# the mail group) cannot open. NOTE: DirectAdmin guides use mode 0666 here —
# world-writable, do not copy.
service lmtp {
  unix_listener lmtp {
    mode  = 0660
    user  = mail
    group = mail
  }
}
EOF

cat > /etc/dovecot/conf.d/10-mail.conf <<'EOF'
mail_driver = maildir
mail_home = %h
mail_path = ~/mail
mail_inbox_path = /var/mail/%n
mailbox_list_utf8 = yes

namespace inbox {
   # Maildir++ stores a folder as ~/.Name and a child as ~/.Parent.Child, so
   # '.' is the on-disk separator and MUST also be the IMAP one. 2.3 set no
   # separator and got '.' by default; writing '/' here renamed every nested
   # folder out from under existing clients (Parent.Child -> Parent/Child) and
   # made '.' illegal in a mailbox name, so a client asking for a name it had
   # cached got "Character not allowed in mailbox name: '.'" and fell back to
   # showing INBOX only. See scripts/update/fix_imap_separator.sh.
   separator = .
   inbox = yes

   # Advertise SPECIAL-USE (RFC 6154). Without these the server tells clients
   # nothing about which folder is Sent/Drafts/Trash/Junk, so each one guesses
   # differently — Apple Mail stops guessing and creates its OWN "Sent
   # Messages"/"Deleted Messages", which is why sent mail goes missing from
   # "Sent" and accounts end up with two of each. `spam` (not `Junk`) carries
   # \Junk: it is the cPanel-era folder where the mail actually accumulates.
   # See scripts/update/setup_special_use.sh.
   mailbox Drafts {
      special_use = \Drafts
      auto = subscribe
   }
   mailbox Sent {
      special_use = \Sent
      auto = subscribe
   }
   mailbox Trash {
      special_use = \Trash
      auto = subscribe
   }
   mailbox spam {
      special_use = \Junk
      auto = subscribe
   }
   mailbox Archive {
      special_use = \Archive
   }
   # Thunderbird names its archive "Archives". A second \Archive is harmless
   # (archiving is user-initiated); "Sent Messages" is deliberately NOT flagged,
   # because a client WRITES to \Sent on every send and two of them would keep
   # splitting sent mail.
   mailbox Archives {
      special_use = \Archive
   }
}
EOF

cat > /etc/dovecot/conf.d/10-ssl.conf <<'EOF'
#ssl_server {
#   cert_file = /etc/pki/tls/certs/ssl-cert-snakeoil.pem
#   key_file = /etc/pki/tls/private/ssl-cert-snakeoil.key
#}
EOF

# Each protocol file is written only when its package is installed. Enabling a
# protocol whose binary is absent is not caught by doveconf -- the config parses
# and dovecot then fails at startup on the missing /usr/libexec/dovecot/<proto>.
if [ "$HAVE_IMAP" -eq 1 ]; then
cat > /etc/dovecot/conf.d/20-imap.conf <<'EOF'
# Enable IMAP protocol
protocols {
  imap = yes
}
EOF
else
    echo "  Skipping 20-imap.conf (dovecot-imapd not installed) — IMAP will be OFF."
fi

if [ "$HAVE_LMTP" -eq 1 ]; then
cat > /etc/dovecot/conf.d/20-lmtp.conf <<'EOF'
# Enable LMTP protocol — exim delivers local mail here (transport dovecot_lmtp).
protocols {
  lmtp = yes
}

# The sieve plugin MUST be loaded for the lmtp service. Without it LMTP delivers
# straight to the maildir and every filter tier is silently skipped — no error.
protocol lmtp {
  mail_plugins {
    sieve = yes
  }
}
EOF
else
    echo "  Skipping 20-lmtp.conf (dovecot-lmtpd not installed) — local delivery"
    echo "  cannot use LMTP, so no Sieve filter runs on inbound mail."
fi

# ManageSieve. conf.d is wiped above, which also removes the RPM-owned
# 10-managesieve.conf, so enable the protocol here rather than relying on it.
# Bound to loopback: roundcube connects over localhost and reqad uses
# `doveadm sieve` locally. To allow external IMAP clients (thunderbird et al.)
# to manage filters, drop the `listen` line AND open 4190 in the firewall —
# ssl = required already forces STARTTLS.
# Only when Pigeonhole is installed: `protocols { sieve = yes }` and the
# `sieve_script` sections below are provided BY that plugin, so writing them
# without it is a fatal "Unknown section name: sieve_script" and dovecot will
# not start.
if [ "$HAVE_SIEVE" -eq 1 ]; then
# ManageSieve binds to 127.0.0.1 ONLY, never ::1: a box with IPv6 disabled has no
# ::1 and dovecot treats a listener it cannot bind as FATAL —
#   master: Error: bind(::1, 4190) failed: Cannot assign requested address
#   master: Fatal: Failed to start listeners
# — which takes the whole mail server down. Everything that talks ManageSieve
# here (roundcube, doveadm) is local and uses 127.0.0.1.

cat > /etc/dovecot/conf.d/20-managesieve.conf <<'EOF'
protocols {
  sieve = yes
}

service managesieve-login {
  inet_listener sieve {
    port   = 4190
    listen = 127.0.0.1
  }
}
EOF

# Sieve: three tiers, evaluated global -> domain -> personal.
# NOTE: active_path deliberately has NO leading dot. mail_path/mail_inbox_path
# are ~/, so the home IS the maildir root and dovecot treats ~/.<name> as an
# IMAP folder — the conventional ~/.dovecot.sieve makes maildir stat
# ~/.dovecot.sieve/tmp and fail with "Not a directory".
# NOTE: `stop` does NOT short-circuit between scripts in pigeonhole; only
# `discard` ends the sequence. The tiers are additive, not hierarchical.
cat > /etc/dovecot/conf.d/90-sieve.conf <<'EOF'
# Sieve (Pigeonhole) — managed by Reqad.
#
# Three tiers, evaluated in this order:
#   1. global   — server-wide, rendered by Reqad from the email_filters table
#   2. domain   — per-domain,  rendered by Reqad from the email_filters table
#   3. personal — per-mailbox, the user's own script (ManageSieve-editable)
#
# Tiers 1 and 2 are "before" scripts. NOTE: `stop` does NOT cross script
# boundaries — it ends only its own script and the sequence continues to the
# next tier (measured). Only `discard` terminates the whole sequence. The tiers
# are therefore additive, not hierarchical; this differs from cPanel.
#
# Scripts live under /var/lib/reqad/, NOT /var/lib/dovecot/: the dovecot spec
# ships `%attr(0750,dovecot,dovecot) /var/lib/dovecot`, so every package upgrade
# resets that mode and the LMTP process (which setuids to the mailbox owner)
# loses +x on it — delivery then fails with a 451 "Temporarily unable to access
# necessary Sieve scripts". A chmod there would be undone by the next upgrade.
#
# NOTE: Dovecot 2.4 syntax (named sieve_script blocks). The 90-sieve.conf in the
# RPM's example-config is stale 2.3 `plugin { sieve = ... }` syntax — do not copy it.

# active_path deliberately has NO leading dot. mail_path/mail_inbox_path are ~/,
# so the home IS the maildir root and dovecot treats ~/.<name> as an IMAP folder
# — the conventional ~/.dovecot.sieve makes maildir stat ~/.dovecot.sieve/tmp and
# fail with "Not a directory". ~/dovecot.sieve sits outside the folder namespace.
# It is also kept out of ~/sieve/ so it is not listed as a script named "active".
sieve_script personal {
  driver      = file
  path        = ~/sieve
  active_path = ~/dovecot.sieve
}

sieve_script global {
  type   = before
  driver = file
  path   = /var/lib/reqad/sieve/reqad-global.sieve
}

sieve_script domain {
  type   = before
  driver = file
  path   = /var/lib/reqad/sieve/domains/%{user | domain}.sieve
}

# 4th tier, evaluated last of the "before" scripts: the per-mailbox vacation
# reply, rendered by Reqad from the autoresponders table. Declared after global
# and domain so an admin rule that discards a message suppresses the auto-reply.
# %{user} is the full login name (user@domain), which is how Reqad names the file.
sieve_script autoresponder {
  type   = before
  driver = file
  path   = /var/lib/reqad/sieve/autoresponders/%{user}.sieve
}

# "Pipe to a program" actions — vnd.dovecot.pipe, from the sieve_extprograms
# plugin (ships with dovecot in lib90_sieve_extprograms_plugin.so). The Sieve
# argument is a FILENAME resolved inside sieve_pipe_bin_dir, never a path, so a
# filter can only run programs an administrator has installed there. The panel
# lists that directory and never writes to it.
# MEASURED on 2.4.5: `sieve_extensions = +vnd.dovecot.pipe` is read BEFORE the
# plugin registers the extension, so it is dropped with "ignored unknown
# extension" on every delivery and `require "vnd.dovecot.pipe"` then fails.
# sieve_global_extensions is applied after the plugin loads and does work — and
# it restricts the extension to the admin tiers: a mailbox owner's own script is
# refused with "its use is restricted to global scripts", which is the boundary
# we want (piping runs a program as the mailbox's system user).
sieve_plugins           = sieve_extprograms
sieve_global_extensions = vnd.dovecot.pipe
sieve_pipe_bin_dir      = /var/lib/reqad/sieve/bin
EOF
else
    echo "  Skipping Sieve config (dovecot-sieve/dovecot-managesieved not installed)."
fi

# LMTP drops to the mailbox owner, so every uid must be able to traverse into
# /var/lib/reqad to reach sieve/ and volatile/. 0711 = traverse without listing.
mkdir -p /var/lib/reqad
chmod 0711 /var/lib/reqad

# Volatile dir for mail_volatile_path (lock files). Sticky like /tmp: dovecot
# creates the per-user subdir as the mailbox owner, so the parent must be
# writable by all of them without letting them touch each other's.
mkdir -p /var/lib/reqad/volatile
chmod 1777 /var/lib/reqad/volatile

mkdir -p /var/lib/reqad/sieve/domains /var/lib/reqad/sieve/autoresponders /var/lib/reqad/sieve/bin
chown -R dovecot:dovecot /var/lib/reqad/sieve
chmod 755 /var/lib/reqad/sieve /var/lib/reqad/sieve/domains /var/lib/reqad/sieve/autoresponders /var/lib/reqad/sieve/bin

if [ "$HAVE_POP3" -eq 1 ]; then
cat > /etc/dovecot/conf.d/20-pop3.conf <<'EOF'
# Enable POP3 protocol
protocols {
  pop3 = yes
}
EOF
else
    echo "  Skipping 20-pop3.conf (dovecot-pop3d not installed) — POP3 will be OFF."
fi

# --- Restore host-specific files --------------------------------------------
# Only on a real package migration (when we moved the live /etc/dovecot aside).
# On a config-only repair the live sni.conf/users are the current ones — leave
# them be, don't overwrite with a possibly stale 2.3 backup.
if [[ "$UPGRADE_PKG" -eq 1 ]]; then
    # sni.conf is regenerated by Reqad but restore it so SSL keeps working until
    # the next regeneration; users is the email-accounts password file; fts.conf
    for f in sni.conf users; do
        if [ -f "$BACKUP/$f" ]; then
            cp -a "$BACKUP/$f" "/etc/dovecot/$f"
            echo "  Restored $f"
        else
            echo "  WARNING: $BACKUP/$f not found — skipping"
        fi
    done

    # fts.conf is the flatcurve full-text-search config. Legitimately absent if
    # FTS was never set up on this box — setup_dovecot_fts.sh recreates it on
    # the next post-install — so this is optional, not a warning.
    if [ -f "$BACKUP/fts.conf" ]; then
        cp -a "$BACKUP/fts.conf" /etc/dovecot/fts.conf
        echo "  Restored fts.conf"
    fi

    # compress.conf is the mail_compress config. Also legitimately absent (the
    # feature is opt-in), but restoring it is not optional where it exists: any
    # mail already gzipped on disk is unreadable without the plugin, so losing
    # this file turns stored messages into gzip bytes served as message bodies.
    if [ -f "$BACKUP/compress.conf" ]; then
        cp -a "$BACKUP/compress.conf" /etc/dovecot/compress.conf
        echo "  Restored compress.conf"
    fi
fi

# dh.pem: keep the live one; otherwise restore from backup or generate fresh.
if [ ! -f /etc/dovecot/dh.pem ]; then
    if [ -f "$BACKUP/dh.pem" ]; then
        cp -a "$BACKUP/dh.pem" /etc/dovecot/dh.pem
        echo "  Restored dh.pem"
    else
        echo "  Generating dh.pem (this can take a minute)..."
        openssl dhparam -out /etc/dovecot/dh.pem 2048
    fi
fi

# Self-signed default cert. A 2.3 box already has one, but a box that never had a
# cert (or went straight to 2.4) would be missing it, and local.conf's
# ssl_server_cert_file points here — without it doveconf fails fatally. Reqad
# replaces this with per-domain Let's Encrypt certs via SNI; this is the fallback.
if [ ! -f /etc/pki/dovecot/certs/dovecot.pem ]; then
    echo "  Generating self-signed dovecot cert..."
    mkdir -p /etc/pki/dovecot/certs /etc/pki/dovecot/private
    openssl req -new -x509 -nodes -days 3650 \
        -subj "/CN=$(hostname -f 2>/dev/null || hostname)/O=Reqad" \
        -out /etc/pki/dovecot/certs/dovecot.pem \
        -keyout /etc/pki/dovecot/private/dovecot.pem
    chmod 0600 /etc/pki/dovecot/private/dovecot.pem
fi

chown -R root:root /etc/dovecot

# --- Group membership -------------------------------------------------------
# dovecot must share groups with exim/mail/mysyslog so the auth-client socket
# (mail:mail) and mail spool/log access work. Lost on package upgrade.
usermod -G dovecot,mail,exim,mysyslog dovecot

# --- Restart and verify -----------------------------------------------------
# Roll back to the RIGHT backup: the pre-upgrade 2.3 tree on a migration, and
# the config we copied aside seconds ago on a repair. Restoring a 2.3 config
# onto a 2.4.x install (which the old code did whenever /etc/dovecot.backup-2.3
# still existed) leaves the server worse off than the failure did.
rollback() {
    local src=""
    if [[ "$UPGRADE_PKG" -eq 1 ]] && [ -d "$BACKUP" ]; then
        src="$BACKUP"
    elif [ -n "${REPAIR_BACKUP:-}" ] && [ -d "${REPAIR_BACKUP:-}" ]; then
        src="$REPAIR_BACKUP"
    fi
    [ -n "$src" ] || { echo "No backup to restore from."; return; }
    echo "Restoring $src ..."
    rm -rf /etc/dovecot
    cp -a "$src" /etc/dovecot
    systemctl restart dovecot || true
}

# sni.conf is host data we copied forward untouched; if it is still 2.3 syntax
# the config below cannot parse, through no fault of the config. Repair it first
# or the rollback undoes a perfectly good rewrite.
fix_sni

# Validate BEFORE bouncing the service. A config dovecot cannot parse should
# never cost a restart — the old order stopped a working server, failed to come
# back up, and only then rolled back.
echo "Checking the new configuration..."
if ! doveconf -n >/dev/null 2>&1; then
    echo "ERROR: the new configuration does not parse:"
    doveconf -n 2>&1 | head -10 || true
    rollback
    exit 1
fi

echo "Restarting Dovecot..."
if ! systemctl restart dovecot; then
    echo "ERROR: Dovecot failed to start."
    # `|| true` is load-bearing: with `set -euo pipefail` a failing doveconf here
    # aborted the script before rollback ever ran, leaving the broken config live.
    { doveconf -n 2>&1 | head -10; } || true
    rollback
    exit 1
fi

if systemctl is-active --quiet dovecot; then
    systemctl enable dovecot 2>/dev/null || true
    echo "Dovecot $NEW_VER is running."
    if [[ "$UPGRADE_PKG" -eq 1 ]]; then
        echo "Migration complete. Old 2.3 config backed up at $BACKUP"
    else
        echo "Config repair complete. Previous config: ${REPAIR_BACKUP:-none}"
        echo
        echo "NOTE: this writes conf.d/90-sieve.conf (the filter tiers), but it does"
        echo "      NOT switch local delivery to dovecot LMTP. Until you run"
        echo "      setup_dovecot_sieve.sh, exim still delivers with appendfile and"
        echo "      no Sieve filter runs."
    fi
else
    echo "ERROR: Dovecot not active after restart."
    rollback
    exit 1
fi
