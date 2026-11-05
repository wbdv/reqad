#!/bin/bash
# How many mail apps can be connected at once: the process_limit of Dovecot's
# imap-login and pop3-login services.
#
# Dovecot runs its login services in "high-security" mode by default: one
# imap-login process per connection, and on a TLS connection (993, or 143 after
# STARTTLS) that process stays for the WHOLE session, passing the encrypted
# traffic to the imap process. So the number of imap-login processes is the
# number of connected mail apps - phones and desktop clients sit connected (IDLE)
# all day. The limit is default_process_limit = 100, and once it is full Dovecot
# drops every new connection:
#
#   master: Warning: service(imap-login): process_limit (100) reached,
#           client connections are being dropped
#
# Mail apps see the server as down, and webmail says "Connection to storage
# server failed" (its own IMAP connection is dropped the same way). A server
# with a few hundred mailboxes reaches 100 easily.
#
# The file /etc/dovecot/login-limits.conf is owned by this script and pulled in
# by an !include_try from local.conf, like limits.conf (setup_dovecot_vsz.sh):
# conf.d/ is not an option, migrate_dovecot_2.4.sh wipes and rewrites it.
# The imap / pop3 services themselves allow 1024 processes in Dovecot 2.4; they
# are raised too only when the login limit goes above that, never lowered.
#
# Modes:
#   --apply       (default, run on every update) a server with no limit set gets
#                 1024; a limit already set (here or by hand) is left alone
#   --limit N     set the limit (16-20000) for imap-login and pop3-login
#   --status      print "login=<limit in force>", "default=<yes|no>" and
#                 "used=<imap-login processes now>" (the panel reads these)
#   --reset       remove the file and the include: back to Dovecot's 100
#   --no-reload   write and check the config, leave the reload to the caller
#
# A reload is enough: the master builds the services again with the new limit
# and keeps every open connection. Rolls back if doveconf rejects the result.
# No-op when email is disabled in server-software.ini or Dovecot is missing.

set -uo pipefail

INI="/usr/local/reqad/etc/server-software.ini"
CONF="/etc/dovecot/login-limits.conf"
LOCAL_CONF="/etc/dovecot/local.conf"
INCLUDE_LINE="!include_try /etc/dovecot/login-limits.conf"
INCLUDE_NOTE="# Connected mail apps at most (setup_dovecot_login_limits.sh)"
# A hand-made conf.d file with the same settings, as suggested before this
# script existed; taken over by --apply so the value is in one place.
HANDMADE="/etc/dovecot/conf.d/21-reqad-login-limits.conf"
DEFAULT_LIMIT=1024

LIMIT=""
MODE="apply"
RELOAD=1

while [ $# -gt 0 ]; do
    case "$1" in
        --apply)     MODE="apply"; shift ;;
        --limit)     MODE="set"; LIMIT="${2:-}"; shift 2 ;;
        --status)    MODE="status"; shift ;;
        --reset)     MODE="reset"; shift ;;
        --no-reload) RELOAD=0; shift ;;
        --help|-h)   sed -n '2,/^set -uo/p' "$0" | sed 's/^# \{0,1\}//; $d'; exit 0 ;;
        *) echo "Unknown option: $1" >&2; exit 2 ;;
    esac
done

if [ "$(id -u)" -ne 0 ]; then
    echo "  This script must be run as root"
    exit 1
fi

if ! command -v doveconf >/dev/null 2>&1; then
    [ "$MODE" = "status" ] && { echo "login=default"; echo "default=yes"; echo "used=0"; exit 0; }
    echo "  Dovecot not installed, skipping the login limits"
    exit 0
fi

# process_limit of a service as Dovecot sees it, '' when not set explicitly.
svc_limit() {
    doveconf -n 2>/dev/null | sed -n "/^service $1 {/,/^}/p" \
        | grep -oP '^\s*process_limit\s*=\s*\K[0-9]+' | tail -1
}

# --- status: from the LIVE config, not from the file -------------------------
if [ "$MODE" = "status" ]; then
    v=$(svc_limit imap-login)
    if [ -n "$v" ]; then
        echo "login=$v"; echo "default=no"
    else
        d=$(doveconf -h default_process_limit 2>/dev/null)
        echo "login=${d:-100}"; echo "default=yes"
    fi
    echo "used=$(ps -C imap-login --no-headers 2>/dev/null | wc -l)"
    exit 0
fi

if [ -f "$INI" ]; then
    EMAIL=$(grep -oP '^email=\K.*' "$INI" | tr -d '[:space:]')
    if [ "${EMAIL:-1}" = "0" ]; then
        echo "  Email disabled in server-software.ini, skipping the login limits"
        exit 0
    fi
fi

reload_dovecot() {
    [ "$RELOAD" -eq 1 ] || return 0
    systemctl is-active --quiet dovecot || return 0
    systemctl reload dovecot
}

remove_include() {
    [ -f "$LOCAL_CONF" ] || return 0
    sed -i "\|^${INCLUDE_LINE}\$|d; \|^${INCLUDE_NOTE}\$|d" "$LOCAL_CONF"
}

# Append the include, with one blank line before it only when the file does not
# already end in one (a reset followed by a set must not pile them up).
add_include() {
    if [ -s "$LOCAL_CONF" ] && [ -n "$(tail -n1 "$LOCAL_CONF")" ]; then
        echo >> "$LOCAL_CONF"
    fi
    printf '%s\n%s\n' "$INCLUDE_NOTE" "$INCLUDE_LINE" >> "$LOCAL_CONF"
}

# --- reset ----------------------------------------------------------------------
if [ "$MODE" = "reset" ]; then
    [ -f "$CONF" ] && mv -f "$CONF" "${CONF}.removed-$(date +%Y%m%d-%H%M%S)"
    remove_include
    if ! doveconf -n >/dev/null 2>&1; then
        echo "  ERROR: doveconf rejected the configuration after removing $CONF"
        exit 1
    fi
    reload_dovecot || { echo "  ERROR: Dovecot did not reload"; exit 1; }
    echo "  Mail login limit back to the Dovecot default."
    exit 0
fi

# --- apply: the update path ---------------------------------------------------
if [ "$MODE" = "apply" ]; then
    if [ -f "$HANDMADE" ]; then
        LIMIT=$(grep -oP '^\s*process_limit\s*=\s*\K[0-9]+' "$HANDMADE" | head -1)
        mv -f "$HANDMADE" "/etc/dovecot/21-reqad-login-limits.conf.moved-$(date +%Y%m%d-%H%M%S)"
        echo "  Moving the limit from $HANDMADE into $CONF ..."
        [ -n "$LIMIT" ] || LIMIT=$DEFAULT_LIMIT
    elif [ -f "$CONF" ]; then
        # ours, already there: only make sure local.conf still pulls it in
        if [ -f "$LOCAL_CONF" ] && ! grep -qF "$INCLUDE_LINE" "$LOCAL_CONF"; then
            add_include
            if doveconf -n >/dev/null 2>&1; then
                reload_dovecot || true
                echo "  Login limit include put back into $LOCAL_CONF"
            else
                remove_include
                echo "  WARNING: doveconf rejected $CONF, include left out"
            fi
        fi
        exit 0
    elif [ -n "$(svc_limit imap-login)" ]; then
        echo "  imap-login process_limit is set elsewhere ($(svc_limit imap-login)), leaving it"
        exit 0
    else
        LIMIT=$DEFAULT_LIMIT
    fi
fi

# --- set ------------------------------------------------------------------------
if ! [[ "$LIMIT" =~ ^[0-9]{1,5}$ ]] || [ "$LIMIT" -lt 16 ] || [ "$LIMIT" -gt 20000 ]; then
    echo "  Invalid limit '$LIMIT' (expected 16-20000)" >&2
    exit 2
fi

BACKUP_CONF=""
if [ -f "$CONF" ]; then
    BACKUP_CONF="${CONF}.prev"
    cp -a "$CONF" "$BACKUP_CONF"
fi

echo "  Writing $CONF (login process_limit $LIMIT) ..."
cat > "$CONF" <<'EOF'
# Connected mail apps at most - managed by Reqad
# (scripts/update/setup_dovecot_login_limits.sh). Edits here are overwritten;
# set it from the panel's Dovecot settings page instead.
#
# One login process per connection, kept for the whole session on TLS, so this
# is how many IMAP (and POP3) connections can be open at once. When it is full
# Dovecot drops new connections ("process_limit (N) reached, client connections
# are being dropped") and webmail says "Connection to storage server failed".
service imap-login {
  process_limit = __LIMIT__
}
service pop3-login {
  process_limit = __LIMIT__
}
EOF
# The imap / pop3 services allow 1024 in Dovecot 2.4; a login limit above that
# is useless unless they follow. Below it they are left at their own default.
if [ "$LIMIT" -gt 1024 ]; then
    cat >> "$CONF" <<'EOF'

# Each logged-in connection is also an imap / pop3 process.
service imap {
  process_limit = __LIMIT__
}
service pop3 {
  process_limit = __LIMIT__
}
EOF
fi
sed -i "s/__LIMIT__/${LIMIT}/g" "$CONF"
chown root:root "$CONF"
chmod 0644 "$CONF"

ADDED_INCLUDE=0
if [ -f "$LOCAL_CONF" ]; then
    if ! grep -qF "$INCLUDE_LINE" "$LOCAL_CONF"; then
        add_include
        ADDED_INCLUDE=1
    fi
else
    echo "  WARNING: $LOCAL_CONF missing - creating it with the include"
    printf '%s\n%s\n' "$INCLUDE_NOTE" "$INCLUDE_LINE" > "$LOCAL_CONF"
    chmod 0644 "$LOCAL_CONF"
    ADDED_INCLUDE=1
fi

rollback() {
    if [ -n "$BACKUP_CONF" ]; then
        mv -f "$BACKUP_CONF" "$CONF"
    else
        rm -f "$CONF"
    fi
    [ "$ADDED_INCLUDE" -eq 1 ] && remove_include
}

if ! doveconf -n >/dev/null 2>&1; then
    echo "  ERROR: doveconf rejected the new configuration - rolling back"
    doveconf -n 2>&1 | tail -5 | sed 's/^/    /'
    rollback
    exit 1
fi
rm -f "${CONF}.prev"

LIVE=$(svc_limit imap-login)
if [ "$LIVE" != "$LIMIT" ]; then
    echo "  WARNING: doveconf shows imap-login process_limit '${LIVE:-unset}', not $LIMIT - another file sets it later"
fi

if ! reload_dovecot; then
    echo "  ERROR: Dovecot did not reload"
    exit 1
fi
if [ "$RELOAD" -eq 0 ]; then
    echo "  Login limit staged at $LIMIT; the caller reloads dovecot."
else
    echo "  Mail login limit is now $LIMIT connections (imap-login, pop3-login)."
fi
exit 0
