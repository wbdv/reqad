#!/bin/bash
# Update phpMyAdmin to the latest stable release.
# Preserves: config.inc.php and tmp/.

set -e

INSTALL_DIR="/usr/local/reqad/public_html/phpmyadmin"
WORK_DIR="/usr/local/reqad/upgrade-phpmyadmin"

# --- Get installed version ---
PKG_JSON="$INSTALL_DIR/package.json"
if [ ! -f "$PKG_JSON" ]; then
    echo "ERROR: phpMyAdmin not found at $INSTALL_DIR"
    exit 1
fi

INSTALLED=$(grep -oP '"version":\s*"\K[^"]+' "$PKG_JSON" | head -1)
if [ -z "$INSTALLED" ]; then
    echo "ERROR: Could not determine installed phpMyAdmin version."
    exit 1
fi

# --- Get latest available version from GitHub ---
LATEST_TAG=$(curl -sfL "https://api.github.com/repos/phpmyadmin/phpmyadmin/releases/latest" \
    | grep -oP '"tag_name":\s*"\K[^"]+')

if [ -z "$LATEST_TAG" ]; then
    echo "ERROR: Could not fetch latest phpMyAdmin version from GitHub."
    exit 1
fi

# Convert tag RELEASE_5_2_3 → 5.2.3
LATEST=$(echo "$LATEST_TAG" | sed 's/^RELEASE_//; s/_/./g')

echo "Installed : $INSTALLED"
echo "Available : $LATEST"

if [ "$INSTALLED" = "$LATEST" ]; then
    echo "phpMyAdmin is already up to date."
    exit 0
fi

echo "Update available: $INSTALLED -> $LATEST"

# --- Download ---
TARBALL="phpMyAdmin-${LATEST}-english.tar.gz"
URL="https://files.phpmyadmin.net/phpMyAdmin/${LATEST}/${TARBALL}"

rm -rf "$WORK_DIR"
mkdir -p "$WORK_DIR"
cd "$WORK_DIR"

echo "Downloading $URL ..."
wget -q -O "$TARBALL" "$URL"

echo "Extracting ..."
tar xzf "$TARBALL"

EXTRACTED_DIR="$WORK_DIR/phpMyAdmin-${LATEST}-english"
if [ ! -d "$EXTRACTED_DIR" ]; then
    echo "ERROR: Expected directory $EXTRACTED_DIR not found after extraction."
    exit 1
fi

# --- Backup config before overwriting ---
echo "Backing up config ..."
cp -a "$INSTALL_DIR/config.inc.php" "$WORK_DIR/config.inc.php.bak"

# --- Copy new files over existing installation ---
echo "Installing update ..."
rsync -a --exclude='config.inc.php' --exclude='tmp/' \
    "$EXTRACTED_DIR/" "$INSTALL_DIR/"

# Restore config
cp -a "$WORK_DIR/config.inc.php.bak" "$INSTALL_DIR/config.inc.php"

# Ensure tmp dir exists
mkdir -p "$INSTALL_DIR/tmp"

# --- Fix ownership ---
chown -R reqad:reqad "$INSTALL_DIR" 2>/dev/null || true

# --- Cleanup ---
cd /
rm -rf "$WORK_DIR"

echo "phpMyAdmin updated to $LATEST successfully."
