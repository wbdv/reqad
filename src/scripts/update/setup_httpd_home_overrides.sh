#!/bin/bash
# Apache: the same .htaccess rights for /home/*/public_html as the site vhosts.
#
# httpd's stock /etc/httpd/conf.d/userdir.conf has
#     <Directory "/home/*/public_html">
#         AllowOverride FileInfo AuthConfig Limit Indexes
# which applies to that folder in EVERY vhost. An addon domain or subdomain
# whose folder is inside public_html (~/public_html/<site>) reads the main
# site's ~/public_html/.htaccess too (Apache reads the .htaccess of each
# parent folder), but its own <Directory> covers only its folder - so there
# the main site's .htaccess falls under userdir.conf, and a line it may not
# hold ("Options -Indexes", common, and what cPanel accounts bring along)
# answers 500 for the whole addon site, while the main site works.
#
# This sets that AllowOverride to what the site vhosts allow (scripts/adddomain):
#   php-fpm (Apache reads the files as apache): All Options=Indexes,MultiViews,
#           SymLinksIfOwnerMatch,IncludesNOEXEC (no FollowSymLinks, see
#           setup_symlink_protection.sh)
#   mod_php: All
# userdir.conf is a %config(noreplace) file of httpd(-core): an httpd update
# keeps it and writes userdir.conf.rpmnew. Runs on every update; idempotent;
# the old file is put back when httpd -t fails.
#
# Usage: setup_httpd_home_overrides.sh [--dry-run] [--remove]
#   --remove  back to the stock line

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

CONF=/etc/httpd/conf.d/userdir.conf
say() { echo "  public_html .htaccess rights: $*"; }

if ! command -v httpd >/dev/null 2>&1 || [ ! -f "$CONF" ]; then
    say "no Apache userdir.conf, nothing to do"
    exit 0
fi

. /usr/local/reqad/scripts/web_caps.sh
web_caps
if [ "$REMOVE" = "1" ]; then
    WANT='AllowOverride FileInfo AuthConfig Limit Indexes'
elif [ "$PHP_MODE" = fpm ]; then
    WANT='AllowOverride All Options=Indexes,MultiViews,SymLinksIfOwnerMatch,IncludesNOEXEC'
else
    WANT='AllowOverride All'
fi
NOTE='# Reqad: the same .htaccess rights as the site vhosts (scripts/update/setup_httpd_home_overrides.sh)'

# the file with the AllowOverride line of the public_html block set to $WANT
# (and our note above it, or without it for --remove)
NEW=$(awk -v want="$WANT" -v note="$NOTE" -v remove="$REMOVE" '
    /^[[:space:]]*<Directory[[:space:]]+"?\/home\/\*\/public_html"?[[:space:]]*>/ { inblk = 1; print; next }
    inblk && /^[[:space:]]*<\/Directory>/ { inblk = 0; print; next }
    inblk && index($0, note) { next }
    inblk && /^[[:space:]]*AllowOverride[[:space:]]/ {
        match($0, /^[[:space:]]*/); ind = substr($0, 1, RLENGTH)
        if (remove != "1") print ind note
        print ind want; found = 1; next
    }
    { print }
    END { if (!found) exit 3 }' "$CONF")
rc=$?
if [ "$rc" = 3 ]; then
    say "$CONF has no AllowOverride for /home/*/public_html, nothing to do"
    exit 0
fi
OLD=$(cat "$CONF")
if [ "$NEW" = "$OLD" ]; then
    say "already up to date"
    exit 0
fi
if [ "$DRY" = "1" ]; then
    say "would set \"$WANT\" for /home/*/public_html in $CONF"
    exit 0
fi

BAK=/var/lib/reqad/httpd-backups
mkdir -p "$BAK" && chmod 0700 "$BAK"
cp -p "$CONF" "$BAK/userdir.conf.$(date +%Y%m%d-%H%M%S)"
printf '%s\n' "$NEW" > "$CONF"
if httpd -t >/dev/null 2>&1; then
    systemctl is-active --quiet httpd && systemctl reload httpd
    say "set \"$WANT\" for /home/*/public_html"
else
    printf '%s\n' "$OLD" > "$CONF"
    say "httpd -t failed, $CONF put back"
    httpd -t
    exit 1
fi
exit 0
