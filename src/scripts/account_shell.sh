#!/bin/bash
# Shell access of a hosting account: full, jailed or none (SFTP only).
#
# The account's login shell is the state:
#   full    /bin/bash
#   jailed  /usr/local/bin/reqad-jailshell   bash in a sandbox (bin/reqad-jailshell)
#   none    /usr/local/bin/reqad-sftponly    SFTP only over SSH
# Cron does not use the login shell, so for jailed and none the account's
# crontab gets SHELL=/usr/local/bin/reqad-jailshell as its first line (any
# other SHELL= line is dropped); full removes it again. The web terminal
# follows the login shell (scripts/wetty_shell.sh). Sessions already open keep
# the shell they started with until they end.
#
# root_access=0 in server-software.ini: no full shell for any account. `set
# full` is refused, cron jobs of every account run in the sandbox (also those
# of accounts with a shell Reqad did not set), and `restrict` switches the
# hosting accounts that still have a full shell to jailed.
#
# Usage:
#   account_shell.sh get <user>                   full | jailed | none | other:<shell>
#   account_shell.sh set <user> full|jailed|none
#   account_shell.sh cron-sync <user>             SHELL= line to match (after a cron edit)
#   account_shell.sh install                      jail shell in /usr/local/bin + /etc/shells
#   account_shell.sh restrict                     root_access=0: full shells -> jailed (post-install)

if [ "$(id -u)" -ne 0 ]; then
    echo "This script must be run as root"
    exit 1
fi

SRC=/usr/local/reqad/bin/reqad-jailshell
JAIL=/usr/local/bin/reqad-jailshell
SFTP=/usr/local/bin/reqad-sftponly
CRON_MARK='# Reqad: jailed shell access - cron jobs run in the sandbox too'
INI=/usr/local/reqad/etc/server-software.ini
DB=/usr/local/reqad/db/reqad.db

# root_access=1 (or no such line) allows full shell access
full_allowed() {
    local v
    v=$(grep -E '^[[:space:]]*root_access[[:space:]]*=' "$INI" 2>/dev/null | tail -1 | cut -d= -f2 | tr -d '[:space:]')
    [ -z "$v" ] || [ "$v" = 1 ]
}

acct_ok() {
    local uid
    uid=$(id -u "$1" 2>/dev/null) || return 1
    [ "$uid" -ge 1000 ] && [ -d "/var/spool/cron" ]
}

mode_of() {
    case "$(getent passwd "$1" | cut -d: -f7)" in
        /bin/bash|/usr/bin/bash) echo full ;;
        "$JAIL") echo jailed ;;
        "$SFTP") echo none ;;
        *) echo "other:$(getent passwd "$1" | cut -d: -f7)" ;;
    esac
}

install_shell() {
    # bubblewrap is a dependency of the reqad package; never dnf from here
    # (post-install runs inside the rpm transaction)
    command -v bwrap >/dev/null 2>&1 || { echo "account shell: bubblewrap is missing (dnf install bubblewrap)"; return 1; }
    if ! cmp -s "$SRC" "$JAIL"; then
        install -o root -g root -m 0755 "$SRC" "$JAIL.new" && mv -f "$JAIL.new" "$JAIL"
        echo "account shell: $JAIL installed"
    fi
    [ "$(readlink "$SFTP")" = reqad-jailshell ] || ln -sfn reqad-jailshell "$SFTP"
    for s in "$JAIL" "$SFTP"; do
        grep -qx "$s" /etc/shells || echo "$s" >> /etc/shells
    done
}

cron_sync() {
    local user=$1 f=/var/spool/cron/$1 mode tmp
    mode=$(mode_of "$user")
    [ -f "$f" ] || return 0
    tmp=$(mktemp)
    # drop our line + mark, and (jailed/none) every other SHELL= line too;
    # without full shell access on this server every account's cron is jailed
    if [ "$mode" = jailed ] || [ "$mode" = none ] || ! full_allowed; then
        { echo "$CRON_MARK"; echo "SHELL=$JAIL"
          grep -vxF "$CRON_MARK" "$f" | grep -v '^[[:space:]]*SHELL[[:space:]]*='; } > "$tmp"
    else
        grep -vxF "$CRON_MARK" "$f" | grep -vx "SHELL=$JAIL" > "$tmp"
    fi
    if ! cmp -s "$tmp" "$f"; then
        cat "$tmp" > "$f"        # keeps owner and mode 0600
        touch /var/spool/cron    # crond rereads changed spools
    fi
    rm -f "$tmp"
}

case "$1" in
get)
    acct_ok "$2" || { echo "not a hosting account: $2"; exit 1; }
    mode_of "$2"
    ;;
set)
    user=$2 mode=$3
    acct_ok "$user" || { echo "not a hosting account: $user"; exit 1; }
    case "$mode" in
        full)   sh=/bin/bash ;;
        jailed) sh=$JAIL ;;
        none)   sh=$SFTP ;;
        *) echo "usage: $0 set <user> full|jailed|none"; exit 2 ;;
    esac
    if [ "$mode" = full ] && ! full_allowed; then
        echo "Full shell access is off on this server (root_access=0): choose jailed or none."
        exit 1
    fi
    if [ "$mode" != full ]; then install_shell || exit 1; fi
    if [ "$(getent passwd "$user" | cut -d: -f7)" != "$sh" ]; then
        usermod -s "$sh" "$user" || exit 1
    fi
    cron_sync "$user"
    case "$mode" in
        full)   echo "$user: full shell access" ;;
        jailed) echo "$user: jailed shell (own home only)" ;;
        none)   echo "$user: no shell, SFTP only" ;;
    esac
    ;;
cron-sync)
    acct_ok "$2" || exit 0
    cron_sync "$2"
    ;;
install)
    install_shell
    ;;
restrict)
    # hosting accounts only (the panel's accounts table), not other users of the box
    full_allowed && exit 0
    [ -f "$DB" ] || exit 0
    n=0
    for user in $(/usr/bin/sqlite3 -init /dev/null -batch -noheader -list "$DB" 'SELECT user FROM accounts' 2>/dev/null); do
        acct_ok "$user" || continue
        if [ "$(mode_of "$user")" = full ]; then
            install_shell >/dev/null || exit 1
            usermod -s "$JAIL" "$user" || continue
            echo "account shell: $user switched from full to jailed (root_access=0)"
            n=$((n + 1))
        fi
        cron_sync "$user"
    done
    [ "$n" -gt 0 ] && echo "account shell: $n account(s) moved to the jailed shell; open SSH sessions keep bash until they end"
    exit 0
    ;;
*)
    sed -n '/^# Usage:/,/^$/s/^# \{0,1\}//p' "$0"
    exit 2
    ;;
esac
