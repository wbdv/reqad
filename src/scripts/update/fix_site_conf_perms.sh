#!/bin/bash
# Account vhosts and php-fpm pools: root:root 0644, like every other file in
# conf.d / php-fpm.d.
#
# The account templates (nginx_php-fpm, apache_modphp, magento) wrote a new
# account's vhost and pool through a tempnam() file and `sudo mv` / `sudo cp`,
# so the file kept the temp file's mode 0600 - and, with `mv` from the panel,
# its owner reqad. nginx and php-fpm read them as root, so the sites worked,
# but the panel (user reqad) could not read them and fell back to one `sudo cat`
# per account: ~20 ms a row in the accounts list, which then filled in row by
# row. The templates now use `install -m 0644 -o root -g root` (as adddomain
# and the newer templates already did); this fixes the files made before.
#
# Only the files named after an account's main domain are touched (addon
# domains were always written 0644): <conf.d>/<domain>.conf for nginx and
# Apache, and the <domain>.conf pool under /etc/php-fpm.d and every remi
# /etc/opt/remi/php*/php-fpm.d. Symlinks are left alone. Nothing is reloaded:
# only the mode and owner change. Safe to call from post_reqad_install.sh and
# safe to re-run.
#
#   --dry-run   only list what would change

DRY=no
[ "$1" = "--dry-run" ] && DRY=yes

DB=/usr/local/reqad/db/reqad.db
SQL="/usr/bin/sqlite3 -init /dev/null -batch -noheader -list"
[ -f "$DB" ] && [ -x /usr/bin/sqlite3 ] || exit 0

changed=0
while read -r domain; do
    # domain names only: nothing in the name may lead out of the folder
    [[ "$domain" =~ ^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$ ]] || continue
    for f in /etc/nginx/conf.d/"$domain".conf /etc/httpd/conf.d/"$domain".conf \
             /etc/php-fpm.d/"$domain".conf /etc/opt/remi/php*/php-fpm.d/"$domain".conf; do
        [ -f "$f" ] && [ ! -L "$f" ] || continue
        [ "$(stat -c '%a %U %G' "$f")" = "644 root root" ] && continue
        if [ "$DRY" = yes ]; then
            echo "fix_site_conf_perms: would set root:root 0644 on $f ($(stat -c '%U:%G %a' "$f"))"
        else
            chown root:root "$f" && chmod 0644 "$f"
        fi
        changed=$((changed + 1))
    done
done < <($SQL "$DB" "SELECT lower(domain) FROM accounts" 2>/dev/null)

if [ "$DRY" = yes ]; then
    echo "fix_site_conf_perms: $changed file(s) would change"
elif [ "$changed" -gt 0 ]; then
    echo "fix_site_conf_perms: $changed vhost / pool file(s) set to root:root 0644"
fi
exit 0
