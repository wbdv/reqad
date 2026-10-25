#!/bin/bash
# index.php before index.html / index.htm on Apache, for every site.
#
# The stock httpd.conf lists "DirectoryIndex index.html" and php.conf adds
# "DirectoryIndex index.php" AFTER it (in one context DirectoryIndex lines add
# to the list), so a folder with both an index.html and an index.php (a
# placeholder page left next to a WordPress install) showed the index.html.
# nginx vhosts already say "index index.php index.html index.htm".
#
# /etc/httpd/conf.d/00-reqad-directory-index.conf, server-wide: a lone
# "DirectoryIndex disabled" empties the list first, then the Reqad order.
# A vhost or .htaccess with its own DirectoryIndex still wins (the PHP-off
# block of a vhost sets index.html index.htm).
#
# Applies wherever httpd is installed: Apache in front (apache_* templates)
# and Apache behind nginx (nginx_apache_php-fpm), which answers the folder
# requests nginx passes on. Idempotent; the old file is put back when
# httpd -t fails.
#
# Usage: setup_directory_index.sh [--dry-run] [--remove]

if [ "$(id -u)" -ne 0 ]; then
    echo "This script must be run as root"
    exit 1
fi

DRY=0; REMOVE=0
for a in "$@"; do
    case "$a" in
        --dry-run) DRY=1 ;;
        --remove)  REMOVE=1 ;;
        *) echo "Usage: $0 [--dry-run] [--remove]"; exit 1 ;;
    esac
done

if [ ! -d /etc/httpd/conf.d ] || ! command -v httpd >/dev/null 2>&1; then
    echo "  directory index: no Apache, nothing to do"
    exit 0
fi

CONF=/etc/httpd/conf.d/00-reqad-directory-index.conf
OLD=""
[ -f "$CONF" ] && OLD=$(cat "$CONF")
if [ "$REMOVE" = "1" ]; then
    NEW=""
else
    NEW=$(cat <<'EOF'
# index.php first -- managed by scripts/update/setup_directory_index.sh.
# httpd.conf lists index.html and php.conf adds index.php after it; a lone
# "disabled" empties that list, then the order every Reqad site uses.
<IfModule dir_module>
    DirectoryIndex disabled
    DirectoryIndex index.php index.html index.htm
</IfModule>
EOF
)
fi

if [ "$NEW" = "$OLD" ]; then
    echo "  directory index: apache already up to date"
    exit 0
fi
if [ "$DRY" = "1" ]; then
    [ "$REMOVE" = "1" ] && echo "  directory index: would remove $CONF" || echo "  directory index: would write $CONF"
    exit 0
fi

if [ "$REMOVE" = "1" ]; then
    rm -f "$CONF"
else
    printf '%s\n' "$NEW" > "$CONF"
    chmod 0644 "$CONF"
fi
if httpd -t >/dev/null 2>&1; then
    systemctl is-active --quiet httpd && systemctl reload httpd
    [ "$REMOVE" = "1" ] && echo "  directory index: apache back to the stock order" || echo "  directory index: apache serves index.php first"
else
    echo "  directory index: httpd -t failed, restoring"
    if [ -n "$OLD" ]; then printf '%s\n' "$OLD" > "$CONF"; else rm -f "$CONF"; fi
    httpd -t
    exit 1
fi
