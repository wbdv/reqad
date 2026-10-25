#!/bin/bash
# Route /api/ on the panel vhost (port 2087) to the REST API (app/api/index.php),
# and /pdns/ to the DNS API for other servers (app/api/pdns.php: a PowerDNS-
# compatible API with DNS API keys, Settings > DNS API keys).
#
# The API authenticates with Bearer tokens only, so its location sits OUTSIDE
# `location /`: no basic auth, no auth_request session check. The Cookie
# header is dropped, so a panel session can never authenticate an API call
# (nothing for a cross-site request to ride on). Rate limited per address
# (zone reqad_api, 120 requests a minute, burst 60; the DNS API: zone
# reqad_pdns, 600 a minute, burst 300 - another server's panel makes a few
# calls for each record it changes).
#
# The block is always in place; whether the API answers is the panel setting
# api-enabled (off on new and updated servers until turned on in Settings >
# API access or with `reqad api on`) - while off every request gets 403. The
# DNS API likewise answers only while dns-api-enabled is on.
#
# Idempotent: the block between the "reqad-api" markers is rewritten on every
# run, so changes reach existing servers. Backs up the vhost, restores it if
# nginx -t fails.
#
# Usage: setup_panel_api.sh [--remove] [--dry-run]

[ "$(id -u)" -eq 0 ] || { echo "This script must be run as root"; exit 1; }

MODE=install
DRY=0
for a in "$@"; do
    case "$a" in
        --remove)  MODE=remove ;;
        --dry-run) DRY=1 ;;
        *) echo "usage: $0 [--remove] [--dry-run]"; exit 2 ;;
    esac
done

CONF_DIR=/etc/reqad/conf.d
NGINX_CONF=/etc/reqad/nginx.conf
ZONE_FILE=$CONF_DIR/00-reqad-api.conf
BACKUP_DIR=/var/lib/reqad/vhost-backups/panel-api-$(date +%Y%m%d%H%M%S)

[ -d "$CONF_DIR" ] || { echo "setup_panel_api: no $CONF_DIR, nothing to do"; exit 0; }

# The panel vhost(s): files with a `location / {` that listen on 2087. Other
# servers of this nginx (the account panel on 2083, reqad-account) have a
# /api/ of their own: a block found there (versions before 16.10.2026 put it
# in every vhost) is taken out again.
VHOSTS=''
OTHERS=''
for f in $(grep -lE '^[[:space:]]*location[[:space:]]+/[[:space:]]*\{' "$CONF_DIR"/*.conf 2>/dev/null); do
    if grep -qE '^[[:space:]]*listen[[:space:]][^;]*\b2087\b' "$f"; then VHOSTS="$VHOSTS $f"
    elif grep -q 'reqad-api BEGIN' "$f"; then OTHERS="$OTHERS $f"; fi
done
[ -n "$VHOSTS$OTHERS" ] || { echo "setup_panel_api: no panel vhost found in $CONF_DIR"; exit 0; }

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

ARGS=''
for f in $VHOSTS; do ARGS="$ARGS $MODE:$f"; done
for f in $OTHERS; do ARGS="$ARGS remove:$f"; done
python3 - "$TMP" $ARGS <<'PY'
import os, re, sys

tmp, files = sys.argv[1], sys.argv[2:]

BLOCK = '''{i}# reqad-api BEGIN - managed by scripts/update/setup_panel_api.sh
{i}# REST API: Bearer tokens only (app/api/), outside the panel login; answers
{i}# only while the API is turned on (Settings > API access).
{i}location ^~ /api/ {{
{i}    limit_req zone=reqad_api burst=60 nodelay;
{i}    limit_req_status 429;
{i}    client_max_body_size 1m;
{i}    fastcgi_pass php-fpm-reqad;
{i}    include fastcgi_params;
{i}    fastcgi_param SCRIPT_FILENAME /usr/local/reqad/app/api/index.php;
{i}    fastcgi_param SCRIPT_NAME /api/index.php;
{i}    fastcgi_param HTTP_COOKIE "";
{i}    fastcgi_read_timeout 300s;
{i}}}
{i}# DNS API for other servers: PowerDNS-compatible, X-API-Key = a DNS API key
{i}# (app/functions/dns_keys.php); answers only while it is turned on.
{i}location ^~ /pdns/ {{
{i}    limit_req zone=reqad_pdns burst=300 nodelay;
{i}    limit_req_status 429;
{i}    client_max_body_size 8m;
{i}    fastcgi_pass php-fpm-reqad;
{i}    include fastcgi_params;
{i}    fastcgi_param SCRIPT_FILENAME /usr/local/reqad/app/api/pdns.php;
{i}    fastcgi_param SCRIPT_NAME /pdns/index.php;
{i}    fastcgi_param HTTP_COOKIE "";
{i}    fastcgi_read_timeout 300s;
{i}}}
{i}# reqad-api END
'''

LOC_ROOT = re.compile(r'^([ \t]*)location[ \t]+/[ \t]*\{')

def strip(lines):
    out, skip, eat_blank = [], False, False
    for line in lines:
        if 'reqad-api BEGIN' in line:
            skip, eat_blank = True, True
            continue
        if skip:
            if 'reqad-api END' in line:
                skip = False
            continue
        if eat_blank:
            eat_blank = False
            if line.strip() == '':
                continue
        out.append(line)
    return out

def add(lines):
    out, done = [], False
    for line in lines:
        m = LOC_ROOT.match(line)
        if m and not done:
            out.extend(BLOCK.format(i=m.group(1)).splitlines())
            out.append('')
            done = True
        out.append(line)
    return out

for arg in files:
    mode, path = arg.split(':', 1)
    old = open(path).read()
    lines = strip(old.split('\n'))
    if mode == 'install':
        lines = add(lines)
    new = '\n'.join(lines)
    if new != old:
        open(os.path.join(tmp, os.path.basename(path)), 'w').write(new)
        print(path)
PY

CHANGED=$(ls -A "$TMP")
ZONE='# Managed by scripts/update/setup_panel_api.sh - rate limits for the REST API (/api/) and the DNS API (/pdns/).
limit_req_zone $binary_remote_addr zone=reqad_api:1m rate=120r/m;
limit_req_zone $binary_remote_addr zone=reqad_pdns:1m rate=600r/m;'
ZONE_OLD=$(cat "$ZONE_FILE" 2>/dev/null)

if [ "$MODE" = install ] && [ "$ZONE_OLD" != "$ZONE" ]; then ZONE_CHANGE=1; else ZONE_CHANGE=0; fi
if [ "$MODE" = remove ] && [ -f "$ZONE_FILE" ]; then ZONE_CHANGE=1; fi

if [ -z "$CHANGED" ] && [ "$ZONE_CHANGE" = 0 ]; then
    echo "setup_panel_api: the panel vhost is up to date"
    exit 0
fi
if [ "$DRY" = 1 ]; then
    echo "setup_panel_api: would update ${CHANGED:-nothing but the rate-limit zone} (dry run)"
    exit 0
fi

mkdir -p "$BACKUP_DIR"
for f in $VHOSTS $OTHERS; do cp -a "$f" "$BACKUP_DIR/"; done
[ -f "$ZONE_FILE" ] && cp -a "$ZONE_FILE" "$BACKUP_DIR/"

# The vhost uses the zone, so the zone is written first and removed last.
[ "$MODE" = install ] && printf '%s\n' "$ZONE" > "$ZONE_FILE"
for f in $CHANGED; do
    cat "$TMP/$f" > "$CONF_DIR/$f"     # keeps owner and mode
done
[ "$MODE" = remove ] && rm -f "$ZONE_FILE"

if nginx -t -c "$NGINX_CONF" >/dev/null 2>&1; then
    systemctl reload reqad
    echo "setup_panel_api: done ($MODE; backup in $BACKUP_DIR)"
else
    echo "setup_panel_api: nginx -t failed - restoring the previous vhost"
    nginx -t -c "$NGINX_CONF" 2>&1 | tail -5
    for f in $VHOSTS $OTHERS; do cp -a "$BACKUP_DIR/$(basename "$f")" "$f"; done
    if [ -f "$BACKUP_DIR/$(basename "$ZONE_FILE")" ]; then cp -a "$BACKUP_DIR/$(basename "$ZONE_FILE")" "$ZONE_FILE"; else rm -f "$ZONE_FILE"; fi
    systemctl reload reqad
    exit 1
fi
