#!/bin/bash
# Kernel settings that keep one hosting account from spying on another.
#
# Every account runs its PHP, cron jobs and SSH sessions as its own Linux user
# on a shared kernel. A few kernel defaults on EL8/EL9 still let such a user
# look at or trip up processes and files of others:
#
#   kernel.yama.ptrace_scope = 1   a process may only debug (ptrace) its own
#                                  children - not attach to another of the
#                                  user's processes, which is how a hacked site
#                                  reads passwords out of a running program.
#                                  php-fpm's slowlog (master -> its workers)
#                                  and root keep working.
#   fs.protected_regular = 1       in sticky world-writable directories (/tmp,
#   fs.protected_fifos = 1         /var/tmp, /dev/shm) a file or FIFO planted
#                                  by someone else is not opened by a program
#                                  that meant to create its own - the classic
#                                  /tmp race against root's scripts. Same
#                                  values systemd sets on newer distributions.
#   kernel.dmesg_restrict = 1      the kernel log (hardware, addresses, other
#                                  users' crashes) is for root only.
#   kernel.kptr_restrict = 1       kernel addresses are hidden from non-root
#                                  (helps exploits; already 1 on most installs).
#   fs.suid_dumpable = 0           no core dumps of setuid programs, which can
#                                  hold root's secrets (the default; pinned).
#
# Written to /etc/sysctl.d/90-reqad.conf and applied at once - no reboot. The
# file is regenerated on every run; to change a value, put your own line in a
# later file such as /etc/sysctl.d/99-local.conf, which wins.
#
# Usage: setup_sysctl_hardening.sh [--dry-run|--remove]

if [ "$(id -u)" -ne 0 ]; then
    echo "This script must be run as root"
    exit 1
fi

MODE=apply
[ "$1" = "--dry-run" ] && MODE=dry
[ "$1" = "--remove" ]  && MODE=remove

CONF=/etc/sysctl.d/90-reqad.conf
SETTINGS="kernel.yama.ptrace_scope=1
fs.protected_regular=1
fs.protected_fifos=1
kernel.dmesg_restrict=1
kernel.kptr_restrict=1
fs.suid_dumpable=0"

if [ "$MODE" = "remove" ]; then
    if [ -f "$CONF" ]; then
        rm -f "$CONF"
        # back to the values the other files (or the kernel) give
        sysctl --system >/dev/null 2>&1
        echo "sysctl hardening removed ($CONF); the defaults apply again"
    else
        echo "sysctl hardening: not installed"
    fi
    exit 0
fi

new=$(
    echo "# Managed by Reqad (scripts/update/setup_sysctl_hardening.sh) - regenerated"
    echo "# on update. Override a value in /etc/sysctl.d/99-local.conf, not here."
    echo "$SETTINGS" | while IFS='=' read -r key val; do
        # skip keys this kernel does not have (yama is missing on some builds)
        [ -e "/proc/sys/$(echo "$key" | tr . /)" ] && echo "$key = $val"
    done
)

changes=0
while IFS='=' read -r key val; do
    f="/proc/sys/$(echo "$key" | tr . /)"
    [ -e "$f" ] || { echo "  $key: not available on this kernel - skipped"; continue; }
    cur=$(cat "$f" 2>/dev/null)
    if [ "$cur" != "$val" ]; then
        echo "  $key: $cur -> $val"
        changes=$((changes + 1))
    fi
done <<< "$SETTINGS"

if [ "$MODE" = "dry" ]; then
    [ "$changes" -eq 0 ] && echo "sysctl hardening: nothing to change" || echo "sysctl hardening: $changes value(s) would change (dry run)"
    exit 0
fi

if [ -f "$CONF" ] && [ "$(cat "$CONF")" = "$new" ] && [ "$changes" -eq 0 ]; then
    echo "sysctl hardening: up to date"
    exit 0
fi

printf '%s\n' "$new" > "$CONF"
chmod 0644 "$CONF"
# --system, not -p: a later file (99-local.conf) that overrides one of these
# must keep winning, exactly as it will at the next boot
sysctl --system >/dev/null 2>&1 || sysctl -p "$CONF" >/dev/null
echo "sysctl hardening: $CONF written, $changes value(s) changed"
