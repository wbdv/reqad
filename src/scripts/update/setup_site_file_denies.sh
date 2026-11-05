#!/bin/bash
# Files no website should serve: hidden files and folders (.git, .env ...) and
# the instruction files of AI coding assistants (CLAUDE.md, AGENTS.md,
# GEMINI.md), which customers now upload with their projects and which can
# describe a site's internals.
#
# nginx: every site vhost in /etc/nginx/conf.d already has the template's
#   "location ~ /\. { deny all; }" block. After each one this adds
#     - a commented-out alternative that leaves /.well-known/ reachable, for an
#       ACME client other than certbot (certbot adds its own challenge location)
#     - location ~* /(claude[^/]*|agents|gemini)\.md$ { return 404; }
#   New vhosts get the same from the templates. Changed files are backed up
#   first; when nginx -t fails, all of them are put back.
#
# Apache: stock httpd only blocks .ht* files, so .git/ and .env were served on
#   Apache sites. This writes /etc/httpd/conf.d/reqad-site-denies.conf (server
#   level, so every vhost inherits it) ONCE: when the file is already there it
#   is left alone, so an admin's change to it (the .well-known line) stays.
#   Removed again when httpd -t fails.
#
# Runs on every update; idempotent.
# Usage: setup_site_file_denies.sh [--dry-run]

if [ "$(id -u)" -ne 0 ]; then
    echo "This script must be run as root"
    exit 1
fi

DRY=0
for a in "$@"; do
    case "$a" in
        --dry-run) DRY=1 ;;
        *) echo "Usage: $0 [--dry-run]"; exit 1 ;;
    esac
done

say() { echo "  site file denies: $*"; }
RC=0

# ---------------------------------------------------------------- nginx
CONF_DIR=/etc/nginx/conf.d
BACKUP_DIR=/var/lib/reqad/vhost-backups/site-denies-$(date +%Y%m%d%H%M%S)

if [ ! -d "$CONF_DIR" ] || ! command -v nginx >/dev/null 2>&1; then
    say "no nginx, nothing to do there"
elif ! nginx -t >/dev/null 2>&1; then
    say "nginx -t already fails, vhosts left alone"
    RC=1
else
    CHANGED=$(python3 - "$CONF_DIR" "$BACKUP_DIR" "$DRY" <<'PY'
import os, re, sys, shutil

conf_dir, backup_dir, dry = sys.argv[1], sys.argv[2], sys.argv[3] == '1'

# the template's hidden-files block, unless our lines already follow it
BLOCK = re.compile(
    r'^([ \t]*)location[ \t]+~[ \t]+/\\\.[ \t]*\{[ \t]*\n'
    r'[ \t]*deny[ \t]+all;[ \t]*\n'
    r'[ \t]*\}[ \t]*\n'
    r'(?![ \t]*# An ACME client other than certbot)', re.M)

def add(m):
    i = m.group(1)
    return (m.group(0)
        + i + '# An ACME client other than certbot (certbot adds its own challenge location)\n'
        + i + '# needs /.well-known/: use this line instead of the block above\n'
        + i + '# location ~ /\\.(?!well-known/) { deny all; }\n'
        + '\n'
        + i + '# Instruction files of AI coding assistants (CLAUDE.md, AGENTS.md, GEMINI.md)\n'
        + i + 'location ~* /(claude[^/]*|agents|gemini)\\.md$ {\n'
        + i + '    return 404;\n'
        + i + '}\n')

changed = []
for name in sorted(os.listdir(conf_dir)):
    path = os.path.join(conf_dir, name)
    if not name.endswith('.conf') or not os.path.isfile(path) or os.path.islink(path):
        continue
    with open(path) as f:
        orig = f.read()
    new, n = BLOCK.subn(add, orig)
    if n == 0 or new == orig:
        continue
    changed.append(path)
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
    N=$(printf '%s' "$CHANGED" | grep -c .)
    if [ "$N" = 0 ]; then
        say "nginx vhosts already up to date"
    elif [ "$DRY" = 1 ]; then
        say "would add the rules to $N nginx vhost(s):"
        printf '    %s\n' $CHANGED
    elif nginx -t >/dev/null 2>&1; then
        systemctl is-active --quiet nginx && systemctl reload nginx
        say "added the rules to $N nginx vhost(s), backups in $BACKUP_DIR"
    else
        say "nginx -t failed, putting the vhosts back from $BACKUP_DIR"
        for f in $CHANGED; do
            cp -a "$BACKUP_DIR/$(basename "$f")" "$f"
        done
        nginx -t
        RC=1
    fi
fi

# ---------------------------------------------------------------- Apache
HCONF=/etc/httpd/conf.d/reqad-site-denies.conf

if ! command -v httpd >/dev/null 2>&1 || [ ! -d /etc/httpd/conf.d ]; then
    say "no Apache, nothing to do there"
elif [ -e "$HCONF" ]; then
    say "$HCONF already there, left as it is"
elif [ "$DRY" = 1 ]; then
    say "would write $HCONF"
elif ! httpd -t >/dev/null 2>&1; then
    say "httpd -t already fails, $HCONF not written"
    RC=1
else
    cat > "$HCONF" <<'EOF'
# Reqad: files no website on this server should serve; every vhost inherits
# these. Written once by /usr/local/reqad/scripts/update/setup_site_file_denies.sh:
# updates leave this file alone, so a change made here stays.

# Hidden files and folders (.git, .env, .svn ...): stock httpd blocks only .ht*
<DirectoryMatch "/\.">
    Require all denied
</DirectoryMatch>
# An ACME client other than certbot (certbot adds its own challenge rules)
# needs /.well-known/: use this block instead of the one above
# <DirectoryMatch "/\.(?!well-known(/|$))">
#     Require all denied
# </DirectoryMatch>

<FilesMatch "^\.">
    Require all denied
</FilesMatch>

# Instruction files of AI coding assistants (CLAUDE.md, AGENTS.md, GEMINI.md)
<IfModule alias_module>
    RedirectMatch 404 "(?i)/(claude[^/]*|agents|gemini)\.md$"
</IfModule>
EOF
    chmod 0644 "$HCONF"
    if httpd -t >/dev/null 2>&1; then
        systemctl is-active --quiet httpd && systemctl reload httpd
        say "wrote $HCONF"
    else
        rm -f "$HCONF"
        say "httpd -t failed, $HCONF removed"
        RC=1
    fi
fi

exit $RC
