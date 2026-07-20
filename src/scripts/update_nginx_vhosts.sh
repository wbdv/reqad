#!/bin/bash
# Update existing nginx vhost configs in /etc/nginx/conf.d/ to:
#   - Add mail.<domain> to the server_name in the :80 server block if missing
# Safe to run multiple times — skips files already up to date.

if [ "$(id -u)" -ne 0 ]; then
    echo "This script must be run as root"
    exit 1
fi

CONF_DIR="/etc/nginx/conf.d"
changed=0

for conf in "$CONF_DIR"/*.conf; do
    # Skip non-vhost configs (no server_name directive)
    grep -q "server_name" "$conf" 2>/dev/null || continue

    domain=$(basename "$conf" .conf)

    # Skip if mail.<domain> already present in the :80 block
    # (awk check: only look between listen 80 and listen 443)
    already=$(awk '
        /listen 80/  { in80=1 }
        /listen 443/ { in80=0 }
        in80 && /server_name/ && /mail\.'"$domain"'/ { found=1 }
        END { print found+0 }
    ' "$conf")

    [ "$already" -eq 1 ] && echo "  OK:      $(basename "$conf")" && continue

    checksum_before=$(md5sum "$conf")

    # Add mail.<domain> to server_name line inside the :80 block only
    awk -v domain="$domain" '
        /listen 80/  { in80=1 }
        /listen 443/ { in80=0 }
        in80 && /server_name/ && !/mail\./ {
            sub(/;$/, " mail." domain ";")
        }
        { print }
    ' "$conf" > "$conf.tmp" && mv "$conf.tmp" "$conf"

    checksum_after=$(md5sum "$conf")
    if [ "$checksum_before" != "$checksum_after" ]; then
        echo "  Updated: $(basename "$conf")"
        changed=$((changed + 1))
    else
        echo "  OK:      $(basename "$conf")"
    fi
done

if [ "$changed" -gt 0 ]; then
    echo "Testing nginx config..."
    if nginx -t 2>/dev/null; then
        echo "Reloading nginx..."
        systemctl reload nginx
    else
        echo "ERROR: nginx config test failed — reload skipped"
        exit 1
    fi
fi

echo "Done ($changed file(s) updated)."
