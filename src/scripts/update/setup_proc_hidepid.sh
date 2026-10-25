#!/bin/bash
# Hide other users' processes from hosting accounts.
#
# By default every user sees every process on the server - `ps aux` or a look
# through /proc shows which sites exist, which scripts the other accounts run
# and with which command-line arguments (database names, sometimes passwords
# passed to mysqldump or curl). Mounting /proc with hidepid=2 shows each user
# only their own processes; root and the members of one group still see all:
#
#   group reqadproc   reqad (the panel checks its root-run background jobs
#                     by pid: backups, WordPress scans, add-on jobs) and
#                     polkitd (polkit asks /proc who is calling systemctl).
#
# The option goes into /etc/fstab (a `proc /proc` line; systemd remounts /proc
# with it at boot) and is applied at once with a remount - no reboot.
#
# Usage: setup_proc_hidepid.sh [--dry-run|--remove]

if [ "$(id -u)" -ne 0 ]; then
    echo "This script must be run as root"
    exit 1
fi

MODE=apply
[ "$1" = "--dry-run" ] && MODE=dry
[ "$1" = "--remove" ]  && MODE=remove

GROUP=reqadproc
MEMBERS="reqad polkitd"
FSTAB=/etc/fstab
MARK='# reqad: hide other users processes (scripts/update/setup_proc_hidepid.sh)'

current() { awk '$2 == "/proc" && $3 == "proc" {print $4}' /proc/mounts | head -1; }

if [ "$MODE" = remove ]; then
    if grep -q "^proc[[:space:]]\+/proc[[:space:]]" "$FSTAB"; then
        sed -i "\|^$MARK\$|d; \|^proc[[:space:]]\+/proc[[:space:]]|d" "$FSTAB"
    fi
    mount -o remount,nosuid,nodev,noexec,hidepid=0 /proc
    echo "hidepid removed: every user sees every process again ($(current))"
    exit 0
fi

# group and members
if ! getent group "$GROUP" >/dev/null; then
    [ "$MODE" = dry ] && echo "  would create group $GROUP" || groupadd -r "$GROUP"
fi
for u in $MEMBERS; do
    id "$u" >/dev/null 2>&1 || continue
    if ! id -nG "$u" | tr ' ' '\n' | grep -qx "$GROUP"; then
        [ "$MODE" = dry ] && echo "  would add $u to $GROUP" || usermod -aG "$GROUP" "$u"
    fi
done
GID=$(getent group "$GROUP" | cut -d: -f3)

# numeric gid: mount does not look group names up for proc
line="proc /proc proc nosuid,nodev,noexec,hidepid=2,gid=${GID:-$GROUP} 0 0"
if [ "$MODE" = dry ]; then
    echo "  /proc now: $(current)"
    grep -q "^$line\$" "$FSTAB" || echo "  would set in $FSTAB: $line"
    echo "  would remount /proc with hidepid=2,gid=${GROUP}"
    exit 0
fi

if ! grep -q "^$line\$" "$FSTAB"; then
    sed -i "\|^$MARK\$|d; \|^proc[[:space:]]\+/proc[[:space:]]|d" "$FSTAB"
    printf '%s\n%s\n' "$MARK" "$line" >> "$FSTAB"
fi
mount -o "remount,nosuid,nodev,noexec,hidepid=2,gid=${GID}" /proc
echo "hidepid: /proc is $(current)"

# The panel's php-fpm workers (user reqad) only get the new group after a
# restart; reload is not enough, the master keeps its old credentials.
if systemctl is-active --quiet reqad-php-fpm 2>/dev/null; then
    systemctl restart reqad-php-fpm && echo "hidepid: reqad-php-fpm restarted (group $GROUP)"
fi
if systemctl is-active --quiet polkit 2>/dev/null; then
    systemctl restart polkit && echo "hidepid: polkit restarted (group $GROUP)"
fi
