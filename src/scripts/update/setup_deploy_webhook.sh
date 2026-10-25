#!/bin/bash
# Receive Git push webhooks for deployments on the server's hostname, port 443:
#     https://<hostname>/.reqad-deploy/<id>
#
# GitHub, GitLab, Gitea/Forgejo and Bitbucket call that address on every push.
# nginx passes the request to the panel's API on 127.0.0.1:2087
# (/api/v1/hooks/deploy/<id>, app/api/api.php -> deploy_webhook()), which
# checks the signature made with the deployment's secret and queues the
# deploy. Port 2087 can stay closed to the internet (panel IP restriction):
# the hook goes over loopback.
#
# Writes /etc/nginx/reqad-deploy-hook.conf (the location) and includes it in
# the 443 server block of /etc/nginx/conf.d/<hostname>.conf, the vhost made by
# update_default_nginx_host.sh (which includes it too). nginx in front only;
# with Apache in front the API's deploy endpoint is the way to trigger.
# Idempotent; restores the files if nginx -t fails.
#
# Usage: setup_deploy_webhook.sh [--remove] [--dry-run]

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

. /usr/local/reqad/scripts/web_caps.sh
web_caps
if [ "$WEB_FRONT" != nginx ]; then
    echo "setup_deploy_webhook: the web server in front is not nginx - skipped (use the API to trigger deploys)"
    exit 0
fi

HOST=$(hostname)
VHOST=/etc/nginx/conf.d/$HOST.conf
SNIPPET=/etc/nginx/reqad-deploy-hook.conf
INCLUDE='include /etc/nginx/reqad-deploy-hook*.conf;'

read -r -d '' WANT <<EOT
# Managed by scripts/update/setup_deploy_webhook.sh - Git push webhooks for
# deployments, passed to the panel's API over loopback, which checks the
# signature. Nothing else of the panel is reachable through here.
location ^~ /.reqad-deploy/ {
    client_max_body_size 10m;
    proxy_pass https://127.0.0.1:2087/api/v1/hooks/deploy/;
    proxy_set_header Host $HOST;
    proxy_set_header X-Forwarded-For \$remote_addr;
    proxy_set_header Cookie "";
    proxy_ssl_server_name on;
    proxy_ssl_name $HOST;
    proxy_read_timeout 60s;
}
EOT

if [ "$MODE" = remove ]; then
    [ -f "$SNIPPET" ] || { echo "setup_deploy_webhook: not installed"; exit 0; }
    [ "$DRY" = 1 ] && { echo "setup_deploy_webhook: would remove $SNIPPET (dry run)"; exit 0; }
    rm -f "$SNIPPET"     # the include is a wildcard: it matches nothing now
    nginx -t >/dev/null 2>&1 && systemctl reload nginx
    echo "setup_deploy_webhook: removed"
    exit 0
fi

[ -f "$VHOST" ] || { echo "setup_deploy_webhook: no hostname vhost $VHOST - run update_default_nginx_host.sh first"; exit 0; }

CHANGE=0
[ "$(cat "$SNIPPET" 2>/dev/null)" = "$WANT" ] || CHANGE=1
grep -qF "$INCLUDE" "$VHOST" || CHANGE=2
if [ "$CHANGE" = 0 ]; then
    echo "setup_deploy_webhook: up to date"
    exit 0
fi
if [ "$DRY" = 1 ]; then
    echo "setup_deploy_webhook: would write $SNIPPET and include it in $VHOST (dry run)"
    exit 0
fi

BK=/var/lib/reqad/vhost-backups/deploy-hook-$(date +%Y%m%d%H%M%S)
mkdir -p "$BK"
cp -a "$VHOST" "$BK/"
[ -f "$SNIPPET" ] && cp -a "$SNIPPET" "$BK/"

printf '%s\n' "$WANT" > "$SNIPPET"
chmod 0644 "$SNIPPET"
if ! grep -qF "$INCLUDE" "$VHOST"; then
    # into the 443 server block, before its first location
    python3 - "$VHOST" "$INCLUDE" <<'PY'
import re, sys
path, inc = sys.argv[1], sys.argv[2]
lines = open(path).read().split('\n')
out, in443, done = [], False, False
for line in lines:
    if re.match(r'^\s*listen\s+(\[::\]:)?443\b', line):
        in443 = True
    if in443 and not done and re.match(r'^(\s*)location\b', line):
        ind = re.match(r'^(\s*)', line).group(1)
        out.append(ind + '# Git deploy webhooks (scripts/update/setup_deploy_webhook.sh)')
        out.append(ind + inc)
        out.append('')
        done = True
    out.append(line)
if not done:
    sys.exit('no 443 server block with a location found')
open(path, 'w').write('\n'.join(out))
PY
    [ $? -eq 0 ] || { cp -a "$BK/$(basename "$VHOST")" "$VHOST"; echo "setup_deploy_webhook: could not edit $VHOST"; exit 1; }
fi

if nginx -t >/dev/null 2>&1; then
    systemctl reload nginx
    echo "setup_deploy_webhook: https://$HOST/.reqad-deploy/<id> is ready (backup in $BK)"
else
    echo "setup_deploy_webhook: nginx -t failed - restoring"
    nginx -t 2>&1 | tail -3
    cp -a "$BK/$(basename "$VHOST")" "$VHOST"
    if [ -f "$BK/$(basename "$SNIPPET")" ]; then cp -a "$BK/$(basename "$SNIPPET")" "$SNIPPET"; else rm -f "$SNIPPET"; fi
    systemctl reload nginx
    exit 1
fi
