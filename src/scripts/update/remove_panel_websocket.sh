#!/bin/bash
# Removes the panel's old `location /websocket/` block (a proxy to
# 127.0.0.1:2122) from the panel vhost. It served the Wordfence scan console
# (websocat), which the panel no longer has; nothing listens on 2122 now.
#
# Only an active block whose proxy_pass points at 127.0.0.1:2122 is removed,
# from the 2087 panel vhost(s) in /etc/reqad/conf.d (never 00-* files or the
# account panel's reqad-account.conf). Commented-out lines are left alone.
# The old file is kept in /var/lib/reqad/vhost-backups/ and put back when
# nginx -t fails. Idempotent: does nothing once the block is gone.
#
# Usage: remove_panel_websocket.sh [--dry-run]

[ "$(id -u)" -eq 0 ] || { echo "This script must be run as root"; exit 1; }

DRY=0
case "$1" in
    '') ;;
    --dry-run) DRY=1 ;;
    *) echo "usage: $0 [--dry-run]"; exit 2 ;;
esac

CONF_DIR=/etc/reqad/conf.d
NGINX_CONF=/etc/reqad/nginx.conf
BACKUP_DIR=/var/lib/reqad/vhost-backups/panel-websocket-$(date +%Y%m%d%H%M%S)
say() { echo "panel websocket: $*"; }

[ -d "$CONF_DIR" ] || exit 0

VHOSTS=''
for f in "$CONF_DIR"/*.conf; do
    case "$(basename "$f")" in 00-*|reqad-account.conf) continue ;; esac
    grep -qE '^[[:space:]]*listen[[:space:]][^;]*\b2087\b' "$f" 2>/dev/null && VHOSTS="$VHOSTS $f"
done
[ -n "$VHOSTS" ] || exit 0

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

# Writes the changed copies into $TMP (same base name); unchanged files are skipped.
python3 - "$TMP" $VHOSTS <<'PY'
import os, re, sys
tmp, files = sys.argv[1], sys.argv[2:]
start = re.compile(r'^\s*location\s+/websocket/\s*\{')
for path in files:
    lines = open(path).read().split('\n')
    out, i, changed = [], 0, False
    while i < len(lines):
        if start.match(lines[i]):
            # find the closing brace of this block (no nested blocks expected,
            # but count them anyway); comments do not count
            depth, j = 0, i
            while j < len(lines):
                code = lines[j].split('#', 1)[0]
                depth += code.count('{') - code.count('}')
                if depth == 0:
                    break
                j += 1
            block = lines[i:j + 1]
            if j < len(lines) and any(re.match(r'^\s*proxy_pass\s+http://127\.0\.0\.1:2122/?\s*;', l) for l in block):
                # drop one blank line left behind before the next block
                if out and out[-1].strip() == '' and j + 1 < len(lines) and lines[j + 1].strip() == '':
                    j += 1
                i = j + 1
                changed = True
                continue
        out.append(lines[i])
        i += 1
    if changed:
        with open(os.path.join(tmp, os.path.basename(path)), 'w') as fh:
            fh.write('\n'.join(out))
PY

CHANGED=$(ls -A "$TMP")
[ -n "$CHANGED" ] || exit 0

if [ $DRY = 1 ]; then
    for n in $CHANGED; do
        say "[dry-run] would change $CONF_DIR/$n:"
        diff -u "$CONF_DIR/$n" "$TMP/$n" | sed 's/^/    /'
    done
    exit 0
fi

mkdir -p "$BACKUP_DIR"
for n in $CHANGED; do
    cp -a "$CONF_DIR/$n" "$BACKUP_DIR/"
    cat "$TMP/$n" > "$CONF_DIR/$n"
done
if nginx -t -c "$NGINX_CONF" >/dev/null 2>&1; then
    systemctl reload reqad
    say "removed the old /websocket/ location (backup in $BACKUP_DIR)"
else
    say "nginx -t failed - putting the previous config back"
    nginx -t -c "$NGINX_CONF" 2>&1 | tail -5
    for n in $CHANGED; do cp -a "$BACKUP_DIR/$n" "$CONF_DIR/$n"; done
    systemctl reload reqad
    exit 1
fi
