#!/bin/bash
# Install the Roundcube auto-login plugin.
#
# Roundcube is NOT part of the reqad RPM -- build_rpm.sh excludes roundcubemail/
# and does not sync it -- so the plugin cannot live in its plugins directory and
# be packaged. The canonical copy ships under scripts/mail/roundcube/ (which IS
# synced) and this script places it, on every install and update.
#
# Idempotent. Does nothing if Roundcube is not installed. Called from
# post_reqad_install.sh, and again by the panel when the feature is switched on.

set -u
export LC_ALL=C

SRC="/usr/local/reqad/scripts/mail/roundcube/reqad_autologin.php"
RC_DIR="/usr/local/reqad/roundcubemail"
DEST_DIR="$RC_DIR/plugins/reqad_autologin"
CONFIG="$RC_DIR/config/config.inc.php"
PLUGIN="reqad_autologin"

say() { echo "reqad_autologin: $*"; }

[ -f "$SRC" ]    || { say "packaged copy missing at $SRC, skipping"; exit 0; }
[ -d "$RC_DIR" ] || { say "roundcube not installed at $RC_DIR, skipping"; exit 0; }
[ -f "$CONFIG" ] || { say "roundcube has no config.inc.php yet, skipping"; exit 0; }

mkdir -p "$DEST_DIR"
cp -f "$SRC" "$DEST_DIR/reqad_autologin.php"
chown -R reqad:reqad "$DEST_DIR" 2>/dev/null || true
chmod 0644 "$DEST_DIR/reqad_autologin.php"

if grep -q "['\"]$PLUGIN['\"]" "$CONFIG"; then
    say "already enabled in roundcube config"
    exit 0
fi

# Register the plugin in $config['plugins'].
#
# Done in python3 (which update_roundcube.sh already depends on) rather than awk
# + `php -l`, for two reasons found the hard way:
#   * an rpm %post scriptlet has a minimal PATH, so gating the write on `php -l`
#     silently DISCARDED the edit on any server where neither `php` nor `php82`
#     resolved -- the plugin file was installed but never registered;
#   * the old awk looked for a line that was just "];", so a single-line array
#     (`$config['plugins'] = ['archive', 'zipdownload'];`) was left untouched
#     while the script still reported success.
# This walks the real bracket structure, handles both [...] and array(...), and
# the result is VERIFIED below before it replaces anything.
cp -a "$CONFIG" "$CONFIG.bak-$(date +%Y%m%d-%H%M%S)"

python3 - "$CONFIG" "$CONFIG.new" "$PLUGIN" <<'PYEOF'
import re, sys

src, dst, plugin = sys.argv[1], sys.argv[2], sys.argv[3]
s = open(src, encoding='utf-8', errors='surrogateescape').read()

m = re.search(r"\$config\s*\[\s*['\"]plugins['\"]\s*\]\s*=\s*(\[|array\s*\()", s)
if not m:
    sys.exit(3)                                  # no plugins array at all

opener = '[' if m.group(1).startswith('[') else '('
closer = ']' if opener == '[' else ')'

# walk to the matching close bracket, skipping quoted strings and comments
i, depth = m.end() - 1, 0
n = len(s)
while i < n:
    c = s[i]
    if c in ('"', "'"):
        q = c
        i += 1
        while i < n and s[i] != q:
            i += 2 if s[i] == '\\' else 1
    elif s.startswith('//', i) or s.startswith('#', i):
        i = s.find('\n', i)
        if i == -1:
            break
        continue
    elif s.startswith('/*', i):
        j = s.find('*/', i)
        i = (j + 2) if j != -1 else n
        continue
    elif c == opener:
        depth += 1
    elif c == closer:
        depth -= 1
        if depth == 0:
            break
    i += 1

if i >= n or depth != 0:
    sys.exit(4)                                  # unbalanced / truncated config

head, tail = s[:i], s[i:]
# keep the file valid whether or not the last element already has a comma
stripped = head.rstrip()
sep = '' if stripped.endswith((opener, ',')) else ','
indent = '    '
out = stripped + sep + '\n' + indent + "'" + plugin + "',   // Reqad webmail auto-login\n" + tail

open(dst, 'w', encoding='utf-8', errors='surrogateescape').write(out)
PYEOF
rc=$?

if [ $rc -ne 0 ] || [ ! -s "$CONFIG.new" ]; then
    rm -f "$CONFIG.new"
    say "could not edit $CONFIG (code $rc) -- add '$PLUGIN' to \$config['plugins'] by hand" >&2
    exit 0
fi

# Verify before replacing: the plugin must actually be there, and if a php
# binary happens to exist the file must still parse. Never gate on php being
# found -- only on what it says when it IS found.
if ! grep -q "['\"]$PLUGIN['\"]" "$CONFIG.new"; then
    rm -f "$CONFIG.new"
    say "edit did not register the plugin, leaving $CONFIG untouched" >&2
    exit 0
fi

for php in php php82 php83 php81 php80 /usr/bin/php /opt/remi/php82/root/usr/bin/php; do
    if command -v "$php" >/dev/null 2>&1; then
        if ! "$php" -l "$CONFIG.new" >/dev/null 2>&1; then
            rm -f "$CONFIG.new"
            say "edited config failed the PHP syntax check, leaving $CONFIG untouched" >&2
            exit 0
        fi
        break
    fi
done

mv -f "$CONFIG.new" "$CONFIG"
chown reqad:reqad "$CONFIG" 2>/dev/null || true
say "enabled in roundcube config"
exit 0
