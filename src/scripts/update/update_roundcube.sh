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

update_nginx_webmail

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
