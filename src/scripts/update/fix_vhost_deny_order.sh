#!/bin/bash
# Move the "deny hidden files" and "deny PHP in uploads" blocks of existing
# nginx vhosts ABOVE the PHP handler.
#
# The account vhost template nests all three inside `location /` and used to
# place the denies AFTER `location ~ \.php$`. nginx tries nested regex
# locations in file order and stops at the first match, so a request for
# /wp-content/uploads/shell.php hit the PHP handler and ran -- the uploads deny
# never fired. New vhosts get the fixed order from the template; this script
# fixes the ones already on disk.
#
# Only the exact template blocks are moved, only within the same parent block,
# and only when they sit after the PHP handler, so it is idempotent. Every
# changed file is backed up first; if `nginx -t` fails, all of them are put back.
#
# Usage: fix_vhost_deny_order.sh [--dry-run]

if [ "$(id -u)" -ne 0 ]; then
    echo "This script must be run as root"
    exit 1
fi

DRY=0
[ "$1" = "--dry-run" ] && DRY=1

CONF_DIR=/etc/nginx/conf.d
BACKUP_DIR=/var/lib/reqad/vhost-backups/deny-order-$(date +%Y%m%d%H%M%S)

[ -d "$CONF_DIR" ] || exit 0
command -v nginx >/dev/null 2>&1 || exit 0

CHANGED=$(python3 - "$CONF_DIR" "$BACKUP_DIR" "$DRY" <<'PY'
import os, re, sys, shutil

conf_dir, backup_dir, dry = sys.argv[1], sys.argv[2], sys.argv[3] == '1'

# A deny block, with the template's comment lines above it if present.
DENY = re.compile(
    r'(?:[ \t]*#[^\n]*\n)*'
    r'[ \t]*location[ \t]+(?:~[ \t]+/\\\.|~\*[ \t]+/\(\?:uploads\|files\)/\.\*\\\.php\$)[ \t]*\{[ \t]*\n'
    r'[ \t]*deny[ \t]+all;[ \t]*\n'
    r'[ \t]*\}[ \t]*\n')
PHP_LOC = re.compile(r'^([ \t]*)location[ \t]+~[ \t]+\\\.php\$[ \t]*\{', re.M)

def block_end(text, start):
    """Index just past the '}' closing the block whose '{' is at/after start."""
    i = text.index('{', start)
    depth = 0
    while i < len(text):
        c = text[i]
        if c == '{': depth += 1
        elif c == '}':
            depth -= 1
            if depth == 0:
                return i + 1
        i += 1
    return -1

def balanced(s):
    """True if s never closes more braces than it opens and ends level."""
    d = 0
    for c in s:
        if c == '{': d += 1
        elif c == '}':
            d -= 1
            if d < 0: return False
    return d == 0

def fix(text):
    moved = 0
    while True:
        did = False
        for php in PHP_LOC.finditer(text):
            end = block_end(text, php.start())
            if end < 0:
                continue
            # a deny block after the PHP handler, in the same parent block
            for m in DENY.finditer(text, end):
                between = text[end:m.start()]
                if not balanced(between):
                    break
                # the block moves with the comment lines directly above it,
                # re-indented to the PHP handler's level (relative indent kept)
                lines = m.group(0).rstrip('\n').split('\n')
                loc = next(l for l in lines if l.strip().startswith('location'))
                old_ind = loc[:len(loc) - len(loc.lstrip())]
                new_ind = php.group(1)
                body = '\n'.join(new_ind + (l[len(old_ind):] if l.startswith(old_ind) else l.lstrip()) for l in lines)
                # drop the block (plus the blank line before it, if any)
                cut_from = m.start()
                if text[cut_from-2:cut_from] == '\n\n':
                    cut_from -= 1
                removed = text[:cut_from] + text[m.end():]
                text = removed[:php.start()] + body + '\n\n' + removed[php.start():]
                moved += 1
                did = True
                break
            if did:
                break
        if not did:
            return text, moved

changed = []
for name in sorted(os.listdir(conf_dir)):
    path = os.path.join(conf_dir, name)
    if not name.endswith('.conf') or not os.path.isfile(path):
        continue
    with open(path) as f:
        orig = f.read()
    new, n = fix(orig)
    if n == 0 or new == orig:
        continue
    changed.append(path)
    print(path, n, file=sys.stderr)
    if dry:
        continue
    os.makedirs(backup_dir, mode=0o700, exist_ok=True)
    shutil.copy2(path, os.path.join(backup_dir, name))
    st = os.stat(path)
    with open(path, 'w') as f:
        f.write(new)
    os.chown(path, st.st_uid, st.st_gid)
    os.chmod(path, st.st_mode & 0o7777)
print('\n'.join(changed))
PY
)

if [ -z "$CHANGED" ]; then
    echo "  vhost deny order: nothing to fix"
    exit 0
fi

if [ "$DRY" = "1" ]; then
    echo "  vhost deny order: would fix $(echo "$CHANGED" | wc -l) vhost(s)"
    exit 0
fi

if nginx -t >/dev/null 2>&1; then
    systemctl reload nginx
    echo "  vhost deny order: fixed $(echo "$CHANGED" | wc -l) vhost(s), backups in $BACKUP_DIR"
else
    echo "  vhost deny order: nginx -t failed, restoring originals from $BACKUP_DIR"
    for f in $CHANGED; do
        cp -a "$BACKUP_DIR/$(basename "$f")" "$f"
    done
    nginx -t
    exit 1
fi
