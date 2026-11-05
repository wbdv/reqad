#!/bin/bash
# Webmail (Roundcube) on a port of its own, 2096, instead of the panel's 2087.
#
# Mailbox owners then never need port 2087, so the panel can be closed to all
# but the admin's addresses (Settings > Security > "Only these addresses can
# reach the panel") and webmail keeps working for everybody. It also puts
# webmail on another origin than the admin panel: a script in a mail message
# that got past Roundcube's filter cannot read or drive panel pages.
#
#   1. The panel's vhost (/etc/reqad/conf.d/<hostname>.conf, the server with
#      a `listen ... 2087`) gets a second server between the markers
#      "# reqad-webmail-port BEGIN/END", appended to the same file: names,
#      listen lines (2087 -> 2096), log files and the certificate are copied
#      from the 2087 server on every run. Being in the same file, it follows
#      update_hostname_cert.sh and rename_hostname.sh, which edit the whole
#      file. It serves only /webmail/ (the login rate limit of
#      setup_webmail_guard.sh included when its zone exists); / goes to
#      /webmail/, anything else is 404. No `location / {` on purpose: the
#      scripts that edit the panel's own `location /` must not find it.
#      Other servers of the file lose their listen on 2096 (one left with
#      none is removed): nginx would answer with the first of them, and the
#      2087 redirect below would then loop.
#   2. The 2087 server's `location /webmail/` becomes a 307 redirect to 2096
#      (a POST, the auto-login ticket, keeps its body), so old links work.
#   3. csf: 2096 added to TCP_IN and TCP6_IN.
#   4. The account panel (reqad-account, 2083): when its version knows about
#      this port (setup/nginx-webmail-redirect.conf.in exists), its own
#      /webmail/ becomes the same redirect. Its setup does the same on its
#      next update; an older reqad-account keeps serving webmail on 2083.
#   5. The sites' /webmail links (reqad email webmail-links) are written again
#      so they point at 2096.
#
# The panel finds out whether webmail has its port from the marker in the
# vhost (webmail_port_ready() in app/functions/emails.php).
#
# Runs on every update (post-install, after setup_webmail_guard.sh).
# Idempotent; backs up changed files to /var/lib/reqad/vhost-backups/ and
# puts them back when nginx -t fails.
#
# Usage: setup_webmail_port.sh [--dry-run] [--remove] [--no-firewall]
#   --remove       webmail back on 2087 (and 2083), port 2096 closed in csf

[ "$(id -u)" -eq 0 ] || { echo "This script must be run as root"; exit 1; }

MODE=install
DRY=0
FIREWALL=1
for a in "$@"; do
    case "$a" in
        --remove)      MODE=remove ;;
        --dry-run)     DRY=1 ;;
        --no-firewall) FIREWALL=0 ;;
        *) echo "usage: $0 [--dry-run] [--remove] [--no-firewall]"; exit 2 ;;
    esac
done

PORT=2096
R=/usr/local/reqad
RC=$R/roundcubemail/public_html
CONF_DIR=/etc/reqad/conf.d
NGINX_CONF=/etc/reqad/nginx.conf
GUARD_ZONE=$CONF_DIR/00-reqad-webmail.conf
ACCT_CONF=$CONF_DIR/reqad-account.conf
ACCT_SETUP=/usr/local/reqad-account/setup
BACKUP_DIR=/var/lib/reqad/vhost-backups/webmail-port-$(date +%Y%m%d%H%M%S)
say() { echo "webmail port: $*"; }

[ -d "$CONF_DIR" ] || { say "no $CONF_DIR, nothing to do"; exit 0; }
if [ "$MODE" = install ] && [ ! -f "$RC/index.php" ]; then
    say "Roundcube is not installed ($RC), nothing to do"
    exit 0
fi

# The panel vhost: the file with the 2087 server (never 00-* or the account
# panel's file, which has a server of its own).
VHOSTS=''
for f in "$CONF_DIR"/*.conf; do
    case "$(basename "$f")" in 00-*|reqad-account.conf) continue ;; esac
    grep -qE '^[[:space:]]*listen[[:space:]][^;]*\b2087\b' "$f" 2>/dev/null && VHOSTS="$VHOSTS $f"
done
[ -n "$VHOSTS" ] || { say "no panel vhost (listen 2087) found in $CONF_DIR"; exit 0; }

was_ready=0
grep -qs '# reqad-webmail-port BEGIN' $VHOSTS && was_ready=1

LIMIT=0
grep -qs 'zone=reqad_webmail_login' "$GUARD_ZONE" && LIMIT=1

# the account panel's /webmail/: only when its version has the redirect
ACCT=''
if [ -f "$ACCT_CONF" ] && [ -f "$ACCT_SETUP/nginx-webmail-redirect.conf.in" ] && [ -f "$ACCT_SETUP/nginx-webmail.conf.in" ] \
   && grep -q 'location /webmail/' "$ACCT_CONF"; then
    ACCT=$ACCT_CONF
fi

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

python3 - "$TMP" "$MODE" "$PORT" "$LIMIT" "$R" "$ACCT_SETUP" "$ACCT" $VHOSTS <<'PY'
import os, re, sys

tmp, mode, port, limit, R, acct_setup, acct = sys.argv[1:8]
vhosts = sys.argv[8:]
RC = R + '/roundcubemail/public_html'
BEGIN = '# reqad-webmail-port BEGIN'
END = '# reqad-webmail-port END'
MARK = '# reqad-webmail-port:'

def code(line):
    """The line without quoted strings and comments, for brace counting."""
    line = re.sub(r'"(?:\\.|[^"\\])*"|\'(?:\\.|[^\'\\])*\'', '', line)
    return line.split('#', 1)[0]

def delta(line):
    c = code(line)
    return c.count('{') - c.count('}')

def block_end(lines, i):
    """Index of the line that closes the block opened on line i."""
    d, opened = 0, False
    for j in range(i, len(lines)):
        for ch in code(lines[j]):
            if ch == '{':
                d += 1
                opened = True
            elif ch == '}':
                d -= 1
            if opened and d == 0:
                return j
    return None

def servers(lines):
    """(start, end) of each top-level server block."""
    out, d, i = [], 0, 0
    while i < len(lines):
        if d == 0 and re.match(r'^\s*server\s*\{', code(lines[i])):
            j = block_end(lines, i)
            if j is None:
                break
            out.append((i, j))
            i = j + 1
            continue
        d += delta(lines[i])
        i += 1
    return out

def direct(lines, s, e):
    """Lines directly inside a block (depth 1), with their index."""
    out, d = [], 0
    for k in range(s, e + 1):
        if d == 1 and k != s:
            out.append((k, lines[k]))
        d += delta(lines[k])
    return out

def webmail_location(lines, s, e):
    """(start, end) of `location /webmail/ {` directly in the block, the
    comment lines of ours just above it included; None if there is none."""
    for k, l in direct(lines, s, e):
        if re.match(r'^\s*location\s+/webmail/?\s*\{', l):
            j = block_end(lines, k)
            a = k
            while a - 1 > s and lines[a - 1].strip().startswith(MARK):
                a -= 1
            return a, j
    return None

def full_location(ind):
    rows = [
        'location /webmail/ {',
        '    alias %s/;' % RC,
        '    index index.php;',
        '    try_files $uri $uri/ /webmail/index.php?$args;',
        '',
        '    location ~ ^/webmail/(.+\\.php)(/.*)?$ {',
    ]
    if limit == '1':
        rows += ['        # reqad: webmail login rate limit (setup_webmail_guard.sh)',
                 '        limit_req zone=reqad_webmail_login burst=10 nodelay;']
    rows += [
        '        set $rc_script %s/$1;' % RC,
        '        if (!-f $rc_script) {',
        '            return 404;',
        '        }',
        '        fastcgi_pass php-fpm-reqad;',
        '        fastcgi_index index.php;',
        '        include fastcgi_params;',
        '        fastcgi_param SCRIPT_FILENAME $rc_script;',
        '        fastcgi_param SCRIPT_NAME /webmail/$1;',
        '        fastcgi_param PATH_INFO $2;',
        '        fastcgi_param DOCUMENT_ROOT %s;' % RC,
        '        fastcgi_keep_conn on;',
        '    }',
        '}',
    ]
    return [(ind + r) if r else '' for r in rows]

def redirect_location(ind):
    return [ind + MARK + ' webmail is served on port %s (scripts/update/setup_webmail_port.sh);' % port,
            ind + MARK + ' 307 keeps a POST (the auto-login ticket) on the way there.',
            ind + 'location /webmail/ {',
            ind + '    return 307 https://$host:%s$request_uri;' % port,
            ind + '}']

def webmail_server(lines, s, e):
    keep = []
    for k, l in direct(lines, s, e):
        t = l.strip()
        if re.match(r'^listen\s', t):
            if re.search(r'\b2087\b', t):
                keep.append(re.sub(r'\b2087\b', port, t))
        elif re.match(r'^(server_name|access_log|error_log|http2|ssl_\w+|client_max_body_size)\s', t):
            keep.append(t)
        elif re.match(r'^include\s+\S*ssl\S*\s*;', t):
            keep.append(t)
    out = [BEGIN + ' - managed by scripts/update/setup_webmail_port.sh',
           '# Webmail (Roundcube) on a port of its own, so the panel\'s 2087 can be open',
           '# to the admin\'s addresses only while mailbox owners still reach webmail.',
           '# Nothing of the panel is served here. Names, listen lines, logs and the',
           '# certificate are copied from the 2087 server above on every update.',
           'server {']
    out += ['    ' + t for t in keep]
    out += ['    # plain http on this port: send it to https',
            '    error_page 497 https://$host:%s$request_uri;' % port,
            '    # relative redirects, so they keep the port (port_in_redirect is off)',
            '    absolute_redirect off;',
            '',
            '    location = / {',
            '        return 302 /webmail/;',
            '    }',
            '    location = /webmail {',
            '        return 301 /webmail/;',
            '    }']
    out += full_location('    ')
    out += ['    location ^~ / {',
            '        return 404;',
            '    }',
            '}',
            END]
    return out

def strip_ours(lines):
    out, skip = [], False
    for l in lines:
        if l.startswith(BEGIN):
            skip = True
            while out and out[-1].strip() == '':
                out.pop()
            continue
        if skip:
            if l.startswith(END):
                skip = False
            continue
        out.append(l)
    return out

def free_port(lines, path):
    """Take the webmail port out of the other servers of the file: nginx
    answers a name on a port with its FIRST server, so an older one there
    (say a hand-made 2096 -> 2087 redirect) hides ours and the 307 of 2087
    sends the browser round in a loop. A server left without a listen line
    goes (nginx would put it on port 80)."""
    for s, e in reversed(servers(lines)):
        listens = [(k, l) for k, l in direct(lines, s, e) if re.match(r'^\s*listen\s', l)]
        mine = [k for k, l in listens if re.search(r'\b%s\b' % port, code(l))]
        if not mine:
            continue
        if len(mine) == len(listens):
            a = s
            while a > 0 and lines[a - 1].strip().startswith('#'):
                a -= 1
            del lines[a:e + 1]
            if 0 < a < len(lines) and lines[a].strip() == '' and lines[a - 1].strip() == '':
                del lines[a]
            print('webmail port: %s: removed an older server on port %s (lines %d-%d)' % (path, port, a + 1, e + 1), file=sys.stderr)
        else:
            for k in reversed(mine):
                del lines[k]
            print('webmail port: %s: took port %s out of the server at line %d' % (path, port, s + 1), file=sys.stderr)
    return lines

def panel(path):
    old = open(path).read()
    lines = strip_ours(old.split('\n'))
    if mode == 'install':
        lines = free_port(lines, path)
    srv = [se for se in servers(lines)
           if any(re.match(r'^\s*listen\s[^;]*\b2087\b', l) for _, l in direct(lines, se[0], se[1]))]
    if not srv:
        return old, None
    s, e = srv[0]
    ind = '    '
    loc = webmail_location(lines, s, e)
    if loc:
        m = re.match(r'^(\s*)', lines[loc[0]])
        ind = m.group(1) or ind
    new_loc = redirect_location(ind) if mode == 'install' else full_location(ind)
    if loc:
        lines[loc[0]:loc[1] + 1] = new_loc
    else:
        lines[e:e] = [''] + new_loc
    if mode == 'install':
        s, e = [se for se in servers(lines)
                if any(re.match(r'^\s*listen\s[^;]*\b2087\b', l) for _, l in direct(lines, se[0], se[1]))][0]
        block = webmail_server(lines, s, e)
        while lines and lines[-1].strip() == '':
            lines.pop()
        lines += [''] + block + ['']
    return old, '\n'.join(lines)

def account(path):
    """The account panel's /webmail/: the redirect (install) or its own
    Roundcube location (remove), from reqad-account's templates."""
    old = open(path).read()
    lines = old.split('\n')
    name = 'nginx-webmail-redirect.conf.in' if mode == 'install' else 'nginx-webmail.conf.in'
    tpl = open(os.path.join(acct_setup, name)).read().replace('@REQAD@', R).split('\n')
    k = next((i for i, l in enumerate(tpl) if re.match(r'^\s*location\s+/webmail/', l)), None)
    if k is None:
        return old, None
    j = block_end(tpl, k)
    want = tpl[k:j + 1]
    for i, l in enumerate(lines):
        if re.match(r'^\s*location\s+/webmail/?\s*\{', l):
            e = block_end(lines, i)
            if e is None:
                return old, None
            lines[i:e + 1] = want
            return old, '\n'.join(lines)
    return old, None

jobs = [(p, panel) for p in vhosts]
if acct:
    jobs.append((acct, account))
for path, fn in jobs:
    old, new = fn(path)
    if new is None:
        print('webmail port: %s: nothing to change found' % path, file=sys.stderr)
        continue
    if new != old:
        open(os.path.join(tmp, os.path.basename(path)), 'w').write(new)
PY
[ $? -eq 0 ] || { say "could not read the panel's nginx config, nothing changed"; exit 1; }

csf_port() {    # csf_port add|del
    local f=/etc/csf/csf.conf changed=0 key line ports
    [ -f "$f" ] || return 0
    for key in TCP_IN TCP6_IN; do
        line=$(grep -E "^${key}[[:space:]]*=" "$f" || true)
        [ -n "$line" ] || continue
        ports=$(echo "$line" | sed -E 's/^[^"]*"([^"]*)".*/\1/')
        if [ "$1" = add ]; then
            echo ",$ports," | grep -q ",$PORT," && continue
            ports="${ports:+$ports,}$PORT"
        else
            echo ",$ports," | grep -q ",$PORT," || continue
            ports=$(echo ",$ports," | sed "s/,$PORT,/,/; s/^,//; s/,\$//")
        fi
        if [ $DRY = 1 ]; then
            say "[dry-run] csf: would $( [ "$1" = add ] && echo add $PORT to || echo take $PORT out of ) $key"
            continue
        fi
        sed -i -E "s/^(${key}[[:space:]]*=[[:space:]]*\")[^\"]*\"/\1${ports}\"/" "$f"
        changed=1
    done
    if [ $changed = 1 ] && command -v csf >/dev/null; then
        csf -r >/dev/null 2>&1 || say "csf -r failed, check csf"
        say "csf: port $PORT $( [ "$1" = add ] && echo opened || echo closed )"
    fi
}

CHANGED=$(ls -A "$TMP")
if [ -z "$CHANGED" ]; then
    if [ "$MODE" = install ]; then say "webmail is on port $PORT already"; else say "webmail is not on a port of its own, nothing to do"; fi
elif [ $DRY = 1 ]; then
    for n in $CHANGED; do
        f="$CONF_DIR/$n"
        say "[dry-run] would change $f:"
        diff -u "$f" "$TMP/$n" | sed 's/^/    /'
    done
else
    mkdir -p "$BACKUP_DIR"
    for n in $CHANGED; do
        cp -a "$CONF_DIR/$n" "$BACKUP_DIR/"
        cat "$TMP/$n" > "$CONF_DIR/$n"
    done
    if nginx -t -c "$NGINX_CONF" >/dev/null 2>&1; then
        systemctl reload reqad
        if [ "$MODE" = install ]; then say "webmail is on https://$(hostname):$PORT/webmail/ now (backup in $BACKUP_DIR)"
        else say "webmail is back on the panel's port (backup in $BACKUP_DIR)"; fi
    else
        say "nginx -t failed - putting the previous config back"
        nginx -t -c "$NGINX_CONF" 2>&1 | tail -5
        for n in $CHANGED; do cp -a "$BACKUP_DIR/$n" "$CONF_DIR/$n"; done
        systemctl reload reqad
        exit 1
    fi
fi

if [ $FIREWALL = 1 ]; then
    if [ "$MODE" = install ]; then csf_port add; else csf_port del; fi
fi

[ $DRY = 1 ] && exit 0

# another file with a server on the port and the same name wins over ours
if [ "$MODE" = install ] && nginx -t -c "$NGINX_CONF" 2>&1 | grep -q "conflicting server name.*:$PORT\b"; then
    say "WARNING: another nginx server answers on port $PORT for the same name, webmail may not be reachable:"
    nginx -t -c "$NGINX_CONF" 2>&1 | grep "conflicting server name.*:$PORT\b" | sed 's/^/    /'
fi

# the sites' /webmail address follows the port
is_ready=0
grep -qs '# reqad-webmail-port BEGIN' $VHOSTS && is_ready=1
if [ "$is_ready" != "$was_ready" ] && [ -x "$R/bin/reqad" ]; then
    "$R/bin/reqad" email webmail-links >/dev/null 2>&1 || say "the sites' /webmail links were not updated: run reqad email webmail-links"
fi
exit 0
