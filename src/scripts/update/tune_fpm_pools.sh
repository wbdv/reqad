#!/bin/bash
# Move hosting-account php-fpm pools from the old Reqad defaults to on-demand
# workers with per-site limits.
#
# Old default: pm = dynamic with start_servers = 10, so every site kept ten PHP
# workers in memory with no visitors at all, and max_children = 200 let one
# busy or attacked site start 200 PHP processes and starve the rest. New:
#
#   pm = ondemand                     workers start with the first request
#   pm.process_idle_timeout = 10s     ... and stop after 10 s without one
#   pm.max_children = 20              at most 20 at once per site
#   pm.max_requests = 500             a worker is recycled after 500 requests
#   request_terminate_timeout = 300s  a stuck script is killed after 5 minutes
#
# Only pools still on the old defaults (pm = dynamic, max_children = 200,
# start_servers = 10) are changed; a pool tuned by hand is left alone. Each
# php-fpm version is config-tested before its reload, and its pool files are
# put back if the test fails. Backups: /var/lib/reqad/fpm-pools-<time>/.
#
# Usage: tune_fpm_pools.sh [--dry-run|--apply]   (default: --dry-run)

if [ "$(id -u)" -ne 0 ]; then
    echo "This script must be run as root"
    exit 1
fi

MODE=dry
case "$1" in
    ''|--dry-run) MODE=dry ;;
    --apply)      MODE=apply ;;
    *) echo "usage: $0 [--dry-run|--apply]"; exit 2 ;;
esac

BACKUP=/var/lib/reqad/fpm-pools-$(date +%Y%m%d-%H%M%S)
val() { sed -n "s/^[[:space:]]*$2[[:space:]]*=[[:space:]]*\([^[:space:];]*\).*/\1/p" "$1" | head -1; }
declare -A FILES=()      # service -> pool files changed
n=0; kept=0

for f in /etc/php-fpm.d/*.conf /etc/opt/remi/*/php-fpm.d/*.conf; do
    [ -f "$f" ] || continue
    u=$(val "$f" user)
    uid=$(id -u "$u" 2>/dev/null) || continue
    [ "$uid" -ge 1000 ] || continue                 # www, the panel, system pools
    if [ "$(val "$f" pm)" != dynamic ] || [ "$(val "$f" 'pm\.max_children')" != 200 ] \
       || [ "$(val "$f" 'pm\.start_servers')" != 10 ]; then
        echo "  $f  custom settings - left alone"
        kept=$((kept + 1))
        continue
    fi
    echo "  $f  dynamic/200/10  ->  ondemand/20"
    n=$((n + 1))
    case "$f" in
        /etc/opt/remi/php*/php-fpm.d/*) svc="$(echo "$f" | sed 's|/etc/opt/remi/\(php[0-9]*\)/.*|\1|')-php-fpm" ;;
        *) svc=php-fpm ;;
    esac
    FILES[$svc]="${FILES[$svc]} $f"
    [ "$MODE" = apply ] || continue
    mkdir -p "$BACKUP"; cp -a "$f" "$BACKUP/$(echo "$f" | tr / _)"
    python3 - "$f" <<'PY'
import re, sys
f = sys.argv[1]
s = open(f).read()
# drop the dynamic-only knobs and the old values, then add the new block where pm was
for k in ('pm.start_servers', 'pm.min_spare_servers', 'pm.max_spare_servers',
          'pm.process_idle_timeout', 'pm.max_children', 'pm.max_requests',
          'request_terminate_timeout'):
    s = re.sub(r'(?m)^[ \t]*' + re.escape(k) + r'[ \t]*=.*\n', '', s)
block = ('; workers start with the first request and stop after 10 s idle, so a quiet\n'
         '; site holds no memory; 20 at once per site, a stuck script is killed at 300 s\n'
         'pm = ondemand\npm.max_children = 20\npm.process_idle_timeout = 10s\n'
         'pm.max_requests = 500\nrequest_terminate_timeout = 300s\n')
s = re.sub(r'(?m)^[ \t]*pm[ \t]*=[ \t]*dynamic[^\n]*\n', block, s, count=1)
open(f, 'w').write(s)
PY
done

echo
if [ "$MODE" = dry ]; then
    echo "Would change $n pool(s); $kept left alone. Run with --apply."
    exit 0
fi

for svc in "${!FILES[@]}"; do
    case "$svc" in
        php-fpm) bin=/usr/sbin/php-fpm ;;
        *)       bin=/opt/remi/${svc%-php-fpm}/root/usr/sbin/php-fpm ;;
    esac
    if [ -x "$bin" ] && ! "$bin" -t >/dev/null 2>&1; then
        echo "$svc: config test FAILED - its pool files are put back"
        for f in ${FILES[$svc]}; do cp -a "$BACKUP/$(echo "$f" | tr / _)" "$f"; done
        continue
    fi
    # a restart, not a reload: the old dynamic workers have to go
    systemctl restart "$svc" && echo "$svc restarted"
done
echo "Changed $n pool(s); $kept left alone. Backup: $BACKUP"
