#!/bin/bash
# Update existing Apache vhost configs in /etc/httpd/conf.d/ to:
#   - Add mail.<domain> to ServerAlias in the :80 VirtualHost only
#   - Add "Require all granted" inside <Directory public_html> blocks if missing
#   - Add PHP open_basedir, error_log, and security settings to :443 block if missing
# Safe to run multiple times — skips files already up to date.

if [ "$(id -u)" -ne 0 ]; then
    echo "This script must be run as root"
    exit 1
fi

DB="/usr/local/reqad/db/reqad.db"
CONF_DIR="/etc/httpd/conf.d"
changed=0

for conf in "$CONF_DIR"/*.conf; do
    # Skip non-vhost configs (no ServerName directive)
    grep -q "ServerName" "$conf" 2>/dev/null || continue

    domain=$(basename "$conf" .conf)

    # Look up the Linux user for this domain
    user=$(sqlite3 "$DB" "SELECT user FROM accounts WHERE domain='${domain}' LIMIT 1;" 2>/dev/null)
    if [ -z "$user" ]; then
        echo "  SKIP:    $(basename "$conf") (domain not in DB)"
        continue
    fi

    checksum_before=$(md5sum "$conf")

    # 1. Add mail.<domain> to ServerAlias inside the :80 VirtualHost block only
    if ! grep -qE "^\s*ServerAlias\s+.*mail\.${domain}" "$conf"; then
        sed -i "/^<VirtualHost[[:space:]][^>]*:80>/,/^<\/VirtualHost>/ {
            /^\s*ServerAlias\s/ s/$/ mail.${domain}/
        }" "$conf"
    fi

    # 2. Add "Require all granted" inside <Directory .../public_html> blocks if missing
    if ! grep -q "Require all granted" "$conf"; then
        sed -i "/^<Directory[[:space:]].*public_html/,/^<\/Directory>/ {
            /^<\/Directory>/ i\\    Require all granted
        }" "$conf"
    fi

    # 3. Add PHP settings if open_basedir is missing
    #    Insert before </VirtualHost> in the :443 block (or the only block for plain HTTP)
    if ! grep -q "open_basedir" "$conf"; then
        php_block="    php_admin_value open_basedir          /home/${user}
    php_admin_value disable_functions     show_source,system,shell_exec,passthru,exec,popen,proc_open
    php_admin_value error_log             /home/${user}/logs/${domain}-error.log
    php_admin_flag  log_errors            on
    php_admin_value sys_temp_dir          /home/${user}/tmp
    php_admin_value upload_tmp_dir        /home/${user}/tmp
    php_admin_value memory_limit          2048M
    php_value       session.save_handler  files
    php_value       session.save_path     /home/${user}/tmp"

        # Insert before the closing </VirtualHost> of the :443 block
        # (last </VirtualHost> in the file covers both plain-HTTP and SSL cases)
        python3 -c "
import sys
content = open('${conf}').read()
block = '''${php_block}'''
# Insert before the last </VirtualHost>
idx = content.rfind('</VirtualHost>')
if idx == -1:
    sys.exit(0)
content = content[:idx] + block + '\n' + content[idx:]
open('${conf}', 'w').write(content)
"
    fi

    # 4. Ensure ~/logs and ~/tmp exist
    for dir in "/home/${user}/logs" "/home/${user}/tmp"; do
        if [ ! -d "$dir" ]; then
            mkdir -p "$dir"
            chown "${user}:${user}" "$dir"
            chmod 750 "$dir"
        fi
    done

    checksum_after=$(md5sum "$conf")
    if [ "$checksum_before" != "$checksum_after" ]; then
        echo "  Updated: $(basename "$conf")"
        changed=$((changed + 1))
    else
        echo "  OK:      $(basename "$conf")"
    fi
done

if [ "$changed" -gt 0 ]; then
    echo "Restarting httpd..."
    systemctl restart httpd
fi

echo "Done ($changed file(s) updated)."
