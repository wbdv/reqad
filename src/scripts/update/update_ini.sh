#!/bin/bash
# Ensure server-software.ini has required keys/values with defaults.
# Safe to run on every post-install — only adds missing entries.

INI="/usr/local/reqad/etc/server-software.ini"

# ── helpers ──────────────────────────────────────────────────────────────────

# Add key=value after the [section] block if the key is absent
add_key_if_missing() {
    local section="$1" key="$2" value="$3"
    if ! awk -v s="$section" -v k="$key" '
        /^\[/ { in_s = ($0 == "[" s "]") }
        in_s && /^[^#]/ && $0 ~ "^" k "=" { found=1 }
        END { exit !found }
    ' "$INI"; then
        # Insert after the [section] line
        sed -i "/^\[$section\]/a $key=$value" "$INI"
        echo "  added [$section] $key=$value"
    fi
}

# Add a token to a comma-separated list value if absent
add_to_list_if_missing() {
    local section="$1" key="$2" token="$3"
    # Check if the key exists in section at all
    local in_section=0 found=0
    while IFS= read -r line; do
        [[ "$line" =~ ^\[.*\] ]] && { [[ "$line" == "[$section]" ]] && in_section=1 || in_section=0; continue; }
        if [[ $in_section -eq 1 && "$line" =~ ^$key= ]]; then
            found=1
            break
        fi
    done < "$INI"

    if [[ $found -eq 0 ]]; then
        # Key doesn't exist at all — add it
        sed -i "/^\[$section\]/a $key=$token" "$INI"
        echo "  added [$section] $key=$token"
        return
    fi

    # Key exists — check if token already in the value
    if ! awk -v s="$section" -v k="$key" -v t="$token" '
        /^\[/ { in_s = ($0 == "[" s "]") }
        in_s && /^[^#]/ {
            if ($0 ~ "^" k "=") {
                val = substr($0, index($0,"=")+1)
                n = split(val, a, /[, ]+/)
                for (i=1;i<=n;i++) if (a[i]==t) { found=1; exit }
            }
        }
        END { exit !found }
    ' "$INI"; then
        # Append token to the list
        sed -i "/^\[$section\]/,/^\[/ s/^\($key=.*\)$/\1, $token/" "$INI"
        echo "  added '$token' to [$section] $key"
    fi
}

# ── [reqad] defaults ──────────────────────────────────────────────────────────

echo "Checking [reqad] section..."
add_key_if_missing "reqad" "backup"      "1"
add_key_if_missing "reqad" "backupdb"    "0"
add_key_if_missing "reqad" "terminal"    "0"
add_key_if_missing "reqad" "root_access" "1"
add_key_if_missing "reqad" "wptoolkit"   "1"
add_key_if_missing "reqad" "transfer"    "0"
add_key_if_missing "reqad" "filemanager" "1"

# ── [systemd] list entries ────────────────────────────────────────────────────

echo "Checking [systemd] section..."
add_to_list_if_missing "systemd" "services" "vnstat"
add_to_list_if_missing "systemd" "services" "reqad-php-fpm"
add_to_list_if_missing "systemd" "timers"   "fstrim"
add_to_list_if_missing "systemd" "timers"   "sysstat-collect"
add_to_list_if_missing "systemd" "timers"   "sysstat-summary"

echo "Done."
