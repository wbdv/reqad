#!/bin/bash
# Update the certbot renewal config for the server hostname from standalone
# to the correct authenticator/installer based on the configured template.
# Safe to run on every post-install.

if [ "$(id -u)" -ne 0 ]; then
    echo "  This script must be run as root"
    exit 1
fi

HOSTNAME=$(hostname)
RENEWAL="/etc/letsencrypt/renewal/${HOSTNAME}.conf"
INI="/usr/local/reqad/etc/server-software.ini"

if [ ! -f "$RENEWAL" ]; then
    echo "  No certbot renewal config for ${HOSTNAME}, skipping"
    exit 0
fi

# Determine authenticator/installer from template setting
TEMPLATE=$(grep -oP '^template=\K.*' "$INI" | tr -d '[:space:]')

case "$TEMPLATE" in
    nginx_php-fpm)  PLUGIN="nginx"  ;;
    apache_mod_php) PLUGIN="apache" ;;
    *)
        echo "  Unknown template '${TEMPLATE}', skipping"
        exit 0
        ;;
esac

CURRENT_AUTH=$(grep -oP '^authenticator\s*=\s*\K\S+' "$RENEWAL")
CURRENT_INST=$(grep -oP '^installer\s*=\s*\K\S+' "$RENEWAL")

if [ "$CURRENT_AUTH" = "$PLUGIN" ] && [ "$CURRENT_INST" = "$PLUGIN" ]; then
    echo "  ${HOSTNAME}: certbot already set to ${PLUGIN}, skipping"
    exit 0
fi

echo "  ${HOSTNAME}: updating certbot from '${CURRENT_AUTH}' to '${PLUGIN}'..."

sed -i "s|^authenticator\s*=.*|authenticator = ${PLUGIN}|" "$RENEWAL"
sed -i "s|^installer\s*=.*|installer = ${PLUGIN}|"         "$RENEWAL"

echo "  Done."
