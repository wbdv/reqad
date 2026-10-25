#!/bin/bash
# Remove the IP-based password bypass from the panel vhost (port 2087).
#
# The panel's `location /` shipped with
#     satisfy any;  allow 127.0.0.1;  [allow <server ip>;]  deny all;  auth_basic ...
# `satisfy any` means EITHER the allow list OR the password lets a request in, so
# every request that originates on the server itself skipped the password. That
# includes every hosting account: a compromised WordPress (PHP curl), a cron job
# or an SSH user could drive the whole panel - and the panel runs as `reqad`,
# which has sudo to root. Requests from the box are not trustworthy on a server
# that runs other people's code.
#
# This drops `satisfy any` and the allow/deny lines that sit directly in the
# panel's `location /` (nested locations are not touched), so the password is
# required for every client. It also adds an unauthenticated, nginx-only
# `location = /reqad-health` and points monit's 2087 check at it: monit used
# to fetch `/` (the whole dashboard, every 30s) and relied on the bypass - with
# the password required it would see 401 and restart the panel in a loop.
#
# Idempotent. Backs up every changed file to /var/lib/reqad/vhost-backups/ and
# restores them all if `nginx -t` (or `monit -t`) fails.
#
# Usage: fix_panel_ip_bypass.sh [--dry-run]

if [ "$(id -u)" -ne 0 ]; then
    echo "This script must be run as root"
    exit 1
fi

DRY=0
[ "$1" = "--dry-run" ] && DRY=1

CONF_DIR=/etc/reqad/conf.d
NGINX_CONF=/etc/reqad/nginx.conf
BACKUP_DIR=/var/lib/reqad/vhost-backups/panel-ip-bypass-$(date +%Y%m%d%H%M%S)

[ -d "$CONF_DIR" ] || exit 0
command -v nginx >/dev/null 2>&1 || exit 0

# ── 1. panel vhost(s) ─────────────────────────────────────────────────────────
CHANGED=$(python3 - "$CONF_DIR" "$BACKUP_DIR" "$DRY" <<'PY'
import os, re, sys, shutil

conf_dir, backup_dir, dry = sys.argv[1], sys.argv[2], sys.argv[3] == '1'

LOC_ROOT = re.compile(r'^([ \t]*)location[ \t]+/[ \t]*\{[ \t]*(#.*)?$')
BYPASS   = re.compile(r'^[ \t]*(satisfy[ \t]+any|allow[ \t]+\S+|deny[ \t]+all)[ \t]*;[ \t]*(#.*)?$')
HEALTH = (
    "{ind}# Unauthenticated liveness probe for monit - nginx only, no PHP, no data.\n"
    "{ind}location = /reqad-health {{\n"
    "{ind}    access_log off;\n"
    "{ind}    default_type text/plain;\n"
    "{ind}    return 200 \"ok\\n\";\n"
    "{ind}}}\n\n")

def strip_braces(line):
    """Line without comments/quoted strings, for brace counting."""
    line = re.sub(r'"(?:\\.|[^"\\])*"|\'(?:\\.|[^\'\\])*\'', '', line)
    return line.split('#', 1)[0]

for name in sorted(os.listdir(conf_dir)):
    if not name.endswith('.conf'):
        continue
    path = os.path.join(conf_dir, name)
    with open(path) as f:
        lines = f.read().split('\n')

    out, removed = [], []
    depth = 0          # brace depth of the whole file
    root_depth = None  # depth INSIDE the panel's `location /`, while in it
    has_health = any('location = /reqad-health' in l for l in lines)
    added_health = False

    for line in lines:
        m = LOC_ROOT.match(line)
        if m and root_depth is None:
            if not has_health and not added_health:
                out.append(HEALTH.format(ind=m.group(1)).rstrip('\n'))
                out.append('')
                added_health = True
            out.append(line)
            depth += 1
            root_depth = depth
            continue
        if root_depth is not None and depth == root_depth and BYPASS.match(line):
            removed.append(line.strip())
            continue
        out.append(line)
        s = strip_braces(line)
        depth += s.count('{') - s.count('}')
        if root_depth is not None and depth < root_depth:
            root_depth = None

    if not removed and not added_health:
        continue
    print(path + ': ' + ('removed ' + ', '.join(removed) if removed else 'no bypass')
          + ('; added /reqad-health' if added_health else ''), file=sys.stderr)
    if dry:
        continue
    os.makedirs(backup_dir, exist_ok=True)
    shutil.copy2(path, os.path.join(backup_dir, name))
    st = os.stat(path)
    tmp = path + '.reqad-tmp'
    with open(tmp, 'w') as f:
        f.write('\n'.join(out))
    os.chown(tmp, st.st_uid, st.st_gid)
    os.chmod(tmp, st.st_mode & 0o7777)
    os.replace(tmp, path)
    print(name)
PY
)

if [ "$DRY" = 1 ]; then
    echo "fix_panel_ip_bypass: dry run, nothing written"
elif [ -n "$CHANGED" ]; then
    if nginx -t -c "$NGINX_CONF" >/dev/null 2>&1; then
        systemctl reload reqad 2>/dev/null || systemctl restart reqad
        echo "fix_panel_ip_bypass: panel vhost updated, password now required for every client"
    else
        echo "fix_panel_ip_bypass: nginx -t failed, restoring the previous panel vhost(s)"
        nginx -t -c "$NGINX_CONF"
        for f in $CHANGED; do cp -a "$BACKUP_DIR/$f" "$CONF_DIR/$f"; done
        exit 1
    fi
fi

# ── 2. monit: probe /reqad-health instead of the dashboard ───────────────────
# Only once the vhost actually serves it (a dry run just reports).
if [ "$DRY" = 0 ]; then
    grep -qs 'location = /reqad-health' "$CONF_DIR"/*.conf || exit 0
fi
command -v monit >/dev/null 2>&1 || exit 0

for f in /etc/monit.d/* /etc/monitrc; do
    [ -f "$f" ] || continue
    grep -qE 'port[[:space:]]+2087[[:space:]]+protocol[[:space:]]+https[[:space:]]+for' "$f" || continue
    if [ "$DRY" = 1 ]; then
        echo "$f: would probe /reqad-health"
        continue
    fi
    mkdir -p "$BACKUP_DIR"
    cp -a "$f" "$BACKUP_DIR/monit-$(basename "$f")"
    sed -i -E 's|(port[[:space:]]+2087[[:space:]]+protocol[[:space:]]+https)[[:space:]]+for|\1 request "/reqad-health" for|' "$f"
    if monit -t >/dev/null 2>&1; then
        monit reload >/dev/null 2>&1
        echo "fix_panel_ip_bypass: $f now probes /reqad-health"
    else
        cp -a "$BACKUP_DIR/monit-$(basename "$f")" "$f"
        echo "fix_panel_ip_bypass: monit -t failed, restored $f"
    fi
done
exit 0
