#!/bin/bash
# Update PHP-FPM config files for all hosting accounts with required php settings.
# Sets security, logging, and temp dir values based on each account's user/domain.
# Searches across all installed PHP versions. Safe to run on every post-install.

DB="/usr/local/reqad/db/reqad.db"

if [ "$(id -u)" -ne 0 ]; then
    echo "  This script must be run as root"
    exit 1
fi

if [ ! -f "$DB" ]; then
    echo "  Database not found: $DB, skipping"
    exit 0
fi

set_php_value() {
    local file="$1" key="$2" value="$3" commented="${4:-false}" only_if_missing="${5:-false}"

    # Escape key for regex (brackets and dots are special)
    local key_re
    key_re=$(printf '%s' "$key" | sed 's/\./\\./g; s/\[/\\[/g; s/\]/\\]/g')

    # Escape & for sed replacement (& means "matched string" in sed)
    local value_sed
    value_sed=$(printf '%s' "$value" | sed 's/&/\\&/g')

    local new_line new_line_sed
    if [ "$commented" = "true" ]; then
        new_line=";${key} = ${value}"
        new_line_sed=";${key} = ${value_sed}"
    else
        new_line="${key} = ${value}"
        new_line_sed="${key} = ${value_sed}"
    fi

    # only_if_missing: skip if the key already has an active (uncommented) value
    if [ "$only_if_missing" = "true" ] && grep -qE "^${key_re}\s*=" "$file" 2>/dev/null; then
        return
    fi

    if grep -qE "^;?${key_re}\s*=" "$file" 2>/dev/null; then
        sed -i "s|^;*${key_re}\s*=.*|${new_line_sed}|" "$file"
    else
        echo "${new_line}" >> "$file"
    fi
}

echo "Updating user PHP-FPM configs..."

restarted_versions=()

while IFS='|' read -r user domain; do
    [ -z "$user" ] && continue

    # Find the conf file across all installed PHP versions (remi + system default)
    conf=$(find /etc/php-fpm.d/ /etc/opt/remi/*/php-fpm.d/ -maxdepth 1 -name "${domain}.conf" 2>/dev/null | head -1)
    [ -z "$conf" ] && continue

    # Derive service name: remi paths → phpXX-php-fpm, system path → php-fpm
    if [[ "$conf" == /etc/opt/remi/* ]]; then
        php_ver=$(echo "$conf" | sed 's|/etc/opt/remi/\([^/]*\)/.*|\1|')
        php_svc="${php_ver}-php-fpm"
    else
        php_ver="php"
        php_svc="php-fpm"
    fi

    checksum_before=$(md5sum "$conf")

    for dir in "/home/${user}/logs" "/home/${user}/tmp"; do
        if [ ! -d "$dir" ]; then
            mkdir -p "$dir"
            chown "${user}:nginx" "$dir"
            chmod 750 "$dir"
        fi
    done

    # Always enforce: values derived from the account's user/domain paths
    set_php_value "$conf" "php_admin_value[open_basedir]"      "/home/${user}"
    set_php_value "$conf" "php_admin_value[error_log]"         "\"/home/${user}/logs/${domain}-error.log\""
    set_php_value "$conf" "php_admin_value[sys_temp_dir]"      "\"/home/${user}/tmp\""
    set_php_value "$conf" "php_admin_value[upload_tmp_dir]"    "\"/home/${user}/tmp\""
    set_php_value "$conf" "php_value[session.save_path]"       "\"/home/${user}/tmp\""
    # Only set if not already customised by the admin
    set_php_value "$conf" "php_admin_value[disable_functions]" "show_source, system, shell_exec, passthru, exec, popen, proc_open" false true
    set_php_value "$conf" "php_admin_value[error_reporting]"   "E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT"       false true
    set_php_value "$conf" "php_admin_flag[log_errors]"         "on"                                                                false true
    set_php_value "$conf" "php_admin_value[memory_limit]"      "2048M"                                                            false true
    set_php_value "$conf" "php_value[session.save_handler]"    "files"                                                            false true

    checksum_after=$(md5sum "$conf")
    if [ "$checksum_before" != "$checksum_after" ]; then
        echo "  ${domain} (${user}) [${php_svc}] — updated"
        restarted_versions+=("${php_svc}")
    else
        echo "  ${domain} (${user}) [${php_svc}] — OK"
    fi

done < <(sqlite3 "$DB" "SELECT user, domain FROM accounts;")

# Restart only the PHP-FPM services that had configs updated
if [ ${#restarted_versions[@]} -gt 0 ]; then
    unique_services=($(printf '%s\n' "${restarted_versions[@]}" | sort -u))
    for svc in "${unique_services[@]}"; do
        echo "Restarting ${svc}..."
        systemctl restart "${svc}"
    done
fi

echo "Done."
