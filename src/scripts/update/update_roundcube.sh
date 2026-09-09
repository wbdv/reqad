#!/bin/bash
# Update Roundcubemail to the latest stable release.
# Preserves: config/, logs/, temp/, and any custom plugins.
# Roundcube 1.7+ uses a public_html/ subdirectory as document root.
# Roundcube is installed at /usr/local/reqad/roundcubemail/ (outside public_html).

INSTALL_DIR="/usr/local/reqad/roundcubemail"
WORK_DIR="/usr/local/reqad/upgrade-roundcube"
INISET="$INSTALL_DIR/program/include/iniset.php"

# --- One-time migration: move from old location inside public_html ---
OLD_DIR="/usr/local/reqad/public_html/roundcubemail"
if [ -d "$OLD_DIR" ] && [ ! -d "$INSTALL_DIR" ]; then
    echo "Migrating Roundcube from $OLD_DIR to $INSTALL_DIR ..."
    mv "$OLD_DIR" "$INSTALL_DIR"
    rm -f /usr/local/reqad/public_html/webmail
    echo "Migration done."
fi

# --- Update nginx webmail location if still using old config ---
update_nginx_webmail() {
    local conf
    conf=$(grep -rl "location /webmail" /etc/reqad/conf.d/ /etc/nginx/conf.d/ 2>/dev/null | grep '\.conf$' | head -1)
    [ -z "$conf" ] && return 0
    grep -q "rc_script" "$conf" 2>/dev/null && return 0

    echo "Updating nginx webmail location in $conf ..."
    python3 - "$conf" <<'PYEOF'
import sys

conf_path = sys.argv[1]
with open(conf_path) as f:
    content = f.read()

NEW_BLOCK = (
    "    location /webmail/ {\n"
    "        alias /usr/local/reqad/roundcubemail/public_html/;\n"
    "        index index.php;\n"
    "        try_files $uri $uri/ /webmail/index.php?$args;\n"
    "\n"
    "        location ~ ^/webmail/(.+\\.php)(/.*)?$ {\n"
    "            set $rc_script /usr/local/reqad/roundcubemail/public_html/$1;\n"
    "            if (!-f $rc_script) {\n"
    "                return 404;\n"
    "            }\n"
    "            fastcgi_pass php-fpm-reqad;\n"
    "            fastcgi_index index.php;\n"
    "            include fastcgi_params;\n"
    "            fastcgi_param SCRIPT_FILENAME $rc_script;\n"
    "            fastcgi_param SCRIPT_NAME /webmail/$1;\n"
    "            fastcgi_param PATH_INFO $2;\n"
    "            fastcgi_param DOCUMENT_ROOT /usr/local/reqad/roundcubemail/public_html;\n"
    "            fastcgi_keep_conn on;\n"
    "        }\n"
    "    }"
)

def replace_webmail_block(text, new_block):
    idx = text.find('location /webmail')
    if idx == -1:
        return text
    brace = text.find('{', idx)
    if brace == -1:
        return text
    depth, pos = 0, brace
    while pos < len(text):
        if text[pos] == '{':
            depth += 1
        elif text[pos] == '}':
            depth -= 1
            if depth == 0:
                break
        pos += 1
    line_start = text.rfind('\n', 0, idx) + 1
    return text[:line_start] + new_block + '\n' + text[pos + 1:]

new_content = replace_webmail_block(content, NEW_BLOCK)
with open(conf_path, 'w') as f:
    f.write(new_content)
print("Done.")
PYEOF

    nginx -t 2>/dev/null && systemctl reload nginx 2>/dev/null || true
}


# --- Enable the managesieve plugin (Roundcube ⇄ Reqad filter interop) --------
# Reqad's Email Filters "Per mailbox" tier and Roundcube's Settings → Filters
# edit the SAME Sieve script, so the script NAME has to match what Reqad reads
# and writes (ef-helper.sh: SCRIPT_NAME="reqad"). Left at the plugin default
# ('managesieve') Roundcube would quietly create a second script and the two UIs
# would disagree about what the filters are.
#
# Gated on the server actually speaking ManageSieve: on a box still delivering
# with exim appendfile there is nothing on :4190, and a Filters tab that only
# ever errors is worse than no Filters tab.
#
# Idempotent, and it never overwrites an existing plugin config — an admin who
# has tuned it keeps their file.
enable_managesieve() {
    local cfg="$INSTALL_DIR/config/config.inc.php"
    local pcfg="$INSTALL_DIR/plugins/managesieve/config.inc.php"

    [ -f "$cfg" ] || return 0
    [ -d "$INSTALL_DIR/plugins/managesieve" ] || return 0

    if ! rpm -q dovecot-managesieved >/dev/null 2>&1 \
       || [ ! -f /etc/dovecot/conf.d/20-managesieve.conf ]; then
        echo "ManageSieve not set up on this server — leaving the roundcube plugin off."
        return 0
    fi

    if [ ! -f "$pcfg" ]; then
        echo "Writing $pcfg ..."
        cat > "$pcfg" <<'MSCFG'
<?php
/* Managed by Reqad (scripts/update/update_roundcube.sh).
 *
 * managesieve_script_name MUST stay 'reqad': it is the script Reqad's Email
 * Filters page reads and writes, and both UIs have to edit the same one.
 *
 * Plain connection on purpose — dovecot's managesieve listener is bound to
 * loopback and roundcube runs on this same host, so nothing leaves the machine.
 * Dovecot treats a local connection as secured, so cleartext auth is allowed
 * without STARTTLS.
 *
 * 127.0.0.1, not 'localhost': the listener is IPv4-only (binding ::1 is fatal
 * to dovecot on a box with IPv6 disabled), and on a dual-stack box 'localhost'
 * resolves to ::1 first.
 */
$config['managesieve_host'] = '127.0.0.1:4190';
$config['managesieve_script_name'] = 'reqad';

/* Autoresponders are Reqad's, rendered from the autoresponders table into a
 * separate dovecot "before" script. Roundcube's vacation UI would write a
 * SECOND vacation rule into the user's own script and the mailbox would
 * auto-reply twice, so both tabs stay off. */
$config['managesieve_vacation'] = 0;
$config['managesieve_forward']  = 0;

/* Same fallback Reqad has: a script the rule parser cannot model is editable as
 * raw source rather than silently rewritten. */
$config['managesieve_raw_editor'] = true;
MSCFG
        chown reqad:reqad "$pcfg" 2>/dev/null || true
    fi

    if grep -q "['\"]managesieve['\"]" "$cfg"; then
        return 0                       # already in the plugin list
    fi

    echo "Enabling the managesieve plugin in $cfg ..."
    cp -a "$cfg" "$cfg.bak-$(date +%Y%m%d-%H%M%S)"
    python3 - "$cfg" <<'PYEOF'
import re, sys

path = sys.argv[1]
src  = open(path).read()

# Roundcube's config uses either the short array syntax or array(...); match the
# plugins assignment and append to whatever is already in it. Editing the list
# in place beats appending a second $config['plugins'] line, which would win and
# silently drop the plugins that were already enabled.
m = re.search(r"\$config\['plugins'\]\s*=\s*(\[|array\()", src)
if not m:
    sys.exit("no \$config['plugins'] assignment found")

open_ch  = '[' if m.group(1) == '[' else '('
close_ch = ']' if open_ch == '[' else ')'
depth, i = 0, m.end() - 1
while i < len(src):
    if src[i] == open_ch:
        depth += 1
    elif src[i] == close_ch:
        depth -= 1
        if depth == 0:
            break
    i += 1
else:
    sys.exit("unterminated plugins list")

inner = src[m.end():i].rstrip()
sep   = '' if (inner == '' or inner.endswith(',')) else ','
addition = (sep + "\n    // Sieve filters, shared with Reqad's Email Filters page (per-mailbox\n"
            "    // tier). The script name must stay 'reqad' — see\n"
            "    // plugins/managesieve/config.inc.php\n"
            "    'managesieve',\n")
open(path, 'w').write(src[:m.end()] + inner + addition + src[i:])
PYEOF

    if ! /usr/bin/php82 -l "$cfg" >/dev/null 2>&1; then
        echo "ERROR: the edited config does not parse — restoring the backup." >&2
        cp -a "$(ls -t "$cfg".bak-* | head -1)" "$cfg"
        return 1
    fi
    echo "managesieve enabled."
}

update_nginx_webmail
enable_managesieve

set -e

# --- Get installed version ---
if [ ! -f "$INISET" ]; then
    echo "ERROR: Roundcube not found at $INSTALL_DIR"
    exit 1
fi

INSTALLED=$(grep -oP "define\('RCMAIL_VERSION',\s*'\K[^']+" "$INISET")
if [ -z "$INSTALLED" ]; then
    echo "ERROR: Could not determine installed Roundcube version."
    exit 1
fi

# --- Get latest available version from GitHub ---
LATEST=$(curl -sfL "https://api.github.com/repos/roundcube/roundcubemail/releases/latest" \
    | grep -oP '"tag_name":\s*"\K[^"]+')

if [ -z "$LATEST" ]; then
    echo "ERROR: Could not fetch latest Roundcube version from GitHub."
    exit 1
fi

echo "Installed : $INSTALLED"
echo "Available : $LATEST"

# Strip leading 'v' if present for comparison
LATEST_VER="${LATEST#v}"

# --- Always run DB migrations (idempotent; catches schema gaps even when files are current) ---
if [ -x "$INSTALL_DIR/bin/update.sh" ]; then
    echo "Running DB migrations ..."
    /usr/bin/php82 "$INSTALL_DIR/bin/update.sh" --version="$INSTALLED" 2>/dev/null || true
fi

if [ "$INSTALLED" = "$LATEST_VER" ]; then
    echo "Roundcube is already up to date."
    exit 0
fi

echo "Update available: $INSTALLED -> $LATEST_VER"

# --- Download ---
TARBALL="roundcubemail-${LATEST_VER}-complete.tar.gz"
URL="https://github.com/roundcube/roundcubemail/releases/download/${LATEST}/roundcubemail-${LATEST_VER}-complete.tar.gz"

rm -rf "$WORK_DIR"
mkdir -p "$WORK_DIR"
cd "$WORK_DIR"

echo "Downloading $URL ..."
wget -q -O "$TARBALL" "$URL"

echo "Extracting ..."
tar xzf "$TARBALL"

EXTRACTED_DIR="$WORK_DIR/roundcubemail-${LATEST_VER}"
if [ ! -d "$EXTRACTED_DIR" ]; then
    echo "ERROR: Expected directory $EXTRACTED_DIR not found after extraction."
    exit 1
fi

# --- Backup config before overwriting ---
echo "Backing up config ..."
cp -a "$INSTALL_DIR/config" "$WORK_DIR/config_backup"

# --- Copy new files over existing installation ---
echo "Installing update ..."
rsync -a --exclude='config/' --exclude='logs/' --exclude='temp/' --exclude='vendor/' \
    "$EXTRACTED_DIR/" "$INSTALL_DIR/"

# Merge new vendor into existing (update Roundcube's own deps, keep plugin extras)
rsync -a "$EXTRACTED_DIR/vendor/" "$INSTALL_DIR/vendor/"

# Restore config in case rsync touched it
cp -a "$WORK_DIR/config_backup/." "$INSTALL_DIR/config/"

# --- Run DB migrations via Roundcube's own updater ---
if [ -x "$INSTALL_DIR/bin/update.sh" ]; then
    echo "Running DB migrations ..."
    /usr/bin/php82 "$INSTALL_DIR/bin/update.sh" --version="$INSTALLED" 2>/dev/null || true
fi

# --- Fix ownership ---
chown -R reqad:reqad "$INSTALL_DIR" 2>/dev/null || true

# --- Cleanup ---
cd /
rm -rf "$WORK_DIR"

echo "Roundcube updated to $LATEST_VER successfully."
