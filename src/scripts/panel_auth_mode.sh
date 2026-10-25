#!/bin/bash
# Switch the panel (port 2087) between nginx basic auth and the web login.
#
#   panel_auth_mode.sh status
#   panel_auth_mode.sh web      login page, sessions, 2FA, brute-force protection
#   panel_auth_mode.sh basic    back to the browser's password prompt
#
# Normally run through `reqad auth mode ...`, which first makes sure an admin
# login exists. nginx enforces the login in BOTH modes:
#   basic  auth_basic on `location /` (unchanged from before)
#   web    auth_request /_reqad_auth on `location /` - every request under it
#          (PHP, phpMyAdmin, /wetty, /monit, /websocket) needs an active
#          session; only /login, /dist, /images and favicon are served
#          without one. PHP gets REQAD_AUTH=web and checks the session again.
#
# All edits are marked "reqad-auth-web" so `basic` can take them out exactly.
# Backs up the vhost, restores it if nginx -t fails.

[ "$(id -u)" -eq 0 ] || { echo "This script must be run as root"; exit 1; }

MODE=$1
CONF_DIR=/etc/reqad/conf.d
NGINX_CONF=/etc/reqad/nginx.conf
ZONE_FILE=$CONF_DIR/00-reqad-auth.conf
DB=/usr/local/reqad/db/reqad.db
BACKUP_DIR=/var/lib/reqad/vhost-backups/panel-auth-$(date +%Y%m%d%H%M%S)

# The panel vhost(s): files with a `location / {` that we manage.
# the 2087 vhost(s) only: the account panel (2083, reqad-account) is a server
# of this nginx too and has a login of its own
VHOSTS=$(for f in $(grep -lE '^[[:space:]]*location[[:space:]]+/[[:space:]]*\{' "$CONF_DIR"/*.conf 2>/dev/null); do
    grep -qE '^[[:space:]]*listen[[:space:]][^;]*\b2087\b' "$f" && echo "$f"; done)
[ -n "$VHOSTS" ] || { echo "no panel vhost found in $CONF_DIR"; exit 1; }

current_mode() {
    if grep -qs 'reqad-auth-web BEGIN' $VHOSTS; then echo web; else echo basic; fi
}

case "$MODE" in
    status) current_mode; exit 0 ;;
    web|basic) ;;
    *) echo "usage: $0 status|web|basic"; exit 2 ;;
esac

if [ "$(current_mode)" = "$MODE" ]; then
    echo "panel login is already: $MODE"
    exit 0
fi

if [ "$MODE" = web ]; then
    N=$(/usr/bin/sqlite3 -init /dev/null -batch -noheader -list "$DB" \
        "SELECT COUNT(*) FROM auth_principals WHERE type='admin' AND status='active' AND pw_hash<>''" 2>/dev/null)
    if [ "${N:-0}" -lt 1 ]; then
        echo "refused: no admin login exists yet - run 'reqad auth mode web' (imports nginx_auth) or 'reqad auth passwd'"
        exit 1
    fi
fi
# limit_req_zone lives at http level (conf.d/*.conf is included there). Written
# in both modes and on every run, so it is always defined while the vhost uses it.
cat > "$ZONE_FILE" <<'EOF'
# Managed by scripts/panel_auth_mode.sh - rate limit for the panel login form
# (the app's own lockout handles password guessing; this caps raw request rate).
limit_req_zone $binary_remote_addr zone=reqad_login:1m rate=30r/m;
limit_req_status 429;
EOF

mkdir -p "$BACKUP_DIR"
for f in $VHOSTS; do cp -a "$f" "$BACKUP_DIR/"; done

python3 - "$MODE" $VHOSTS <<'PY'
import re, sys

mode, files = sys.argv[1], sys.argv[2:]
TAG = '# reqad-auth-web'

SERVER_BLOCK = '''{i}# reqad-auth-web BEGIN - managed by scripts/panel_auth_mode.sh
{i}location = /_reqad_auth {{
{i}    internal;
{i}    fastcgi_pass php-fpm-reqad;
{i}    include fastcgi_params;
{i}    fastcgi_param SCRIPT_FILENAME /usr/local/reqad/app/auth/endpoint/check.php;
{i}    fastcgi_param REQAD_AUTH web;
{i}    fastcgi_pass_request_body off;
{i}    fastcgi_param CONTENT_LENGTH "";
{i}}}
{i}location @reqad_login {{
{i}    if ($http_x_requested_with = "XMLHttpRequest") {{ return 401; }}
{i}    return 302 $scheme://$http_host/login/?next=$request_uri;
{i}}}
{i}location ^~ /login {{
{i}    limit_req zone=reqad_login burst=20 nodelay;
{i}    fastcgi_pass php-fpm-reqad;
{i}    include fastcgi_params;
{i}    fastcgi_param SCRIPT_FILENAME $document_root/index.php;
{i}    fastcgi_param SCRIPT_NAME /index.php;
{i}    fastcgi_param REQAD_AUTH web;
{i}}}
{i}# static files the login page needs; no PHP is executed under them
{i}location ^~ /dist/ {{
{i}    location ~ \\.php$ {{ return 404; }}
{i}    expires 7d;
{i}}}
{i}location ^~ /images/ {{
{i}    location ~ \\.php$ {{ return 404; }}
{i}    expires 7d;
{i}}}
{i}location = /favicon.ico {{ expires 7d; }}
{i}# reqad-auth-web END
'''
ROOT_BLOCK = '''{i}# reqad-auth-web BEGIN
{i}auth_request /_reqad_auth;
{i}error_page 401 = @reqad_login;
{i}# reqad-auth-web END
'''

LOC_ROOT = re.compile(r'^([ \t]*)location[ \t]+/[ \t]*\{')
LOC_PHP  = re.compile(r'^[ \t]*location[ \t]+~[^{]*\\\.php')
AUTH     = re.compile(r'^([ \t]*)(auth_basic(_user_file)?[ \t].*;)')
INC      = re.compile(r'^([ \t]*)include[ \t]+fastcgi_params;')

def braces(line):
    line = re.sub(r'"(?:\\.|[^"\\])*"', '', line).split('#', 1)[0]
    return line.count('{') - line.count('}')

def to_web(lines):
    out, depth, root_depth, in_php = [], 0, None, None
    for line in lines:
        m = LOC_ROOT.match(line)
        if m and root_depth is None:
            ind = m.group(1)
            out.extend(SERVER_BLOCK.format(i=ind).splitlines())
            out.append('')
            out.append(line)
            depth += braces(line)
            root_depth = depth
            out.extend(ROOT_BLOCK.format(i=ind + '    ').splitlines())
            continue
        if root_depth is not None and depth >= root_depth:
            a = AUTH.match(line)
            if a and depth == root_depth:
                out.append(a.group(1) + '#reqad-web# ' + a.group(2))
                continue
            if LOC_PHP.match(line):
                in_php = depth + braces(line)
            if in_php is not None and depth == in_php:
                i = INC.match(line)
                if i:
                    out.append(line)
                    out.append(i.group(1) + 'fastcgi_param REQAD_AUTH web; ' + TAG)
                    depth += braces(line)
                    continue
        out.append(line)
        depth += braces(line)
        if in_php is not None and depth < in_php:
            in_php = None
        if root_depth is not None and depth < root_depth:
            root_depth = None
    return out

def to_basic(lines):
    out, skip, eat_blank = [], False, False
    for line in lines:
        if 'reqad-auth-web BEGIN' in line:
            skip = True
            eat_blank = 'managed by' in line    # the server block is followed by one blank line
            continue
        if skip:
            if 'reqad-auth-web END' in line:
                skip = False
            continue
        if eat_blank:
            eat_blank = False
            if line.strip() == '':
                continue
        if line.rstrip().endswith(TAG):
            continue
        out.append(re.sub(r'^([ \t]*)#reqad-web# ', r'\1', line))
    return out

for path in files:
    lines = open(path).read().split('\n')
    new = to_web(lines) if mode == 'web' else to_basic(lines)
    if mode == 'web' and not any('#reqad-web# auth_basic' in l for l in new):
        print(path + ': WARNING - no auth_basic found in location /', file=sys.stderr)
    open(path, 'w').write('\n'.join(new))
    print(path)
PY

if nginx -t -c "$NGINX_CONF" >/dev/null 2>&1; then
    systemctl reload reqad
    echo "panel login is now: $MODE (backup in $BACKUP_DIR)"
else
    echo "nginx -t failed - restoring the previous vhost"
    nginx -t -c "$NGINX_CONF" 2>&1 | tail -5
    for f in $VHOSTS; do cp -a "$BACKUP_DIR/$(basename "$f")" "$f"; done
    systemctl reload reqad
    exit 1
fi
