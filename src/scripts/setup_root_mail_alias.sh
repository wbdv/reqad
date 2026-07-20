#!/bin/bash
# setup_root_mail_alias.sh enable|disable
# Adds or removes the root mail pipe alias in /etc/aliases
ACTION=${1:-enable}
ALIASES=/etc/aliases
SCRIPT="/usr/local/reqad/scripts/forward_root_mail.php"

# Remove all existing root: lines (ours or old msmtp)
sed -i '/^root:/Id' "$ALIASES"

if [ "$ACTION" = "enable" ]; then
    echo "root: \"|${SCRIPT}\"" >> "$ALIASES"
fi

/usr/bin/newaliases
echo "Done"
