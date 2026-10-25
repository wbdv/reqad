#!/bin/bash
# Restart the panel's own PHP services so they run the PHP that is on disk.
#
# The panel (reqad-php-fpm) and, when installed, the account panel
# (reqad-account-php-fpm, reqad-account-broker) run the remi PHP that
# /opt/reqad/php-current points at. When dnf updates that PHP, the package
# restarts only its own phpNN-php-fpm, so these keep running the old binary.
# The reqad RPM's file trigger on /opt/remi calls this with --defer.
#
#   panel_php_restart.sh           restart now
#   panel_php_restart.sh --defer   restart from a transient unit, after no
#                                  dnf/yum/rpm is left inside these services
#
# Why wait: a dnf started by the panel (PHP Modules) runs inside
# reqad-php-fpm's cgroup, and restarting the service would kill that dnf in
# the middle of its transaction.
#
# Services are found by what they run (ExecStart under /opt/reqad/php-current),
# so an add-on's service is covered without being named here.

SELF=$(readlink -f "$0")
MAX_WAIT=1800

if [ "$1" = "--defer" ]; then
    command -v systemd-run >/dev/null 2>&1 || exit 0
    [ -d /run/systemd/system ] || exit 0
    exec systemd-run --quiet --collect \
        --unit="reqad-panel-php-restart-$(date +%s)-$$" \
        --description="Restart the panel's PHP after a PHP update" \
        /bin/bash "$SELF"
fi

PHP_ROOT=$(readlink -f /opt/reqad/php-current 2>/dev/null)
[ -n "$PHP_ROOT" ] || exit 0

# Running services on our PHP whose main process still maps PHP files that
# were replaced on disk (binary or extensions). A service started after the
# update is left alone, so a second run (reqad's own post-install already
# restarts the panel) changes nothing.
units() {
    local u pid
    systemctl list-units --type=service --state=running --no-legend --plain 'reqad*' 2>/dev/null \
        | awk '{print $1}' | while read -r u; do
            systemctl show -p ExecStart --value "$u" 2>/dev/null \
                | grep -q '/opt/reqad/php-current/' || continue
            pid=$(systemctl show -p MainPID --value "$u" 2>/dev/null)
            [ -n "$pid" ] && [ "$pid" != 0 ] || continue
            grep -qF "$PHP_ROOT/" "/proc/$pid/maps" 2>/dev/null || continue
            grep -F "$PHP_ROOT/" "/proc/$pid/maps" | grep -q ' (deleted)$' && echo "$u"
        done
}

# True while a package manager runs inside one of the services' cgroups.
pkg_busy() {
    local u cg f pid comm
    for u in $UNITS; do
        cg=$(systemctl show -p ControlGroup --value "$u" 2>/dev/null)
        [ -n "$cg" ] || continue
        for f in "/sys/fs/cgroup/systemd$cg/cgroup.procs" "/sys/fs/cgroup$cg/cgroup.procs"; do
            [ -r "$f" ] || continue
            while read -r pid; do
                comm=$(cat "/proc/$pid/comm" 2>/dev/null)
                case "$comm" in
                    dnf|yum|rpm|dnf-automatic) return 0 ;;
                esac
            done < "$f"
        done
    done
    return 1
}

UNITS=$(units)
[ -n "$UNITS" ] || exit 0

waited=0
while pkg_busy && [ "$waited" -lt "$MAX_WAIT" ]; do
    sleep 2
    waited=$((waited + 2))
done
if pkg_busy; then
    echo "a package manager is still running inside $UNITS after ${MAX_WAIT}s; not restarting" >&2
    echo "restart by hand when it is done: systemctl restart $UNITS" >&2
    exit 1
fi

for u in $UNITS; do
    if systemctl try-restart "$u"; then
        echo "restarted $u"
    else
        echo "could not restart $u" >&2
    fi
done
exit 0
