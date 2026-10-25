#!/bin/bash
# Write the WordPress page cache blocks of existing nginx vhosts again, from
# the current version (wp_nginx_cache_fragments()) and each site's own rules
# (WordPress Toolkit > Performance > Page cache rules).
#
# The current blocks serve links that carry only campaign tags (utm_*, gclid,
# fbclid...) from the cache, with the tags kept away from PHP, and leave out
# every wp-*.php page and /basket. Sites that had the cache on before keep
# their old blocks until this runs (or until the cache is switched off and on).
#
# Only vhosts with a `# BEGIN reqad-nginx-cache` block are touched; a site
# whose blocks are already current is left alone, so it is safe to re-run.
# Each changed file is copied to /var/lib/reqad/vhost-backups/page-cache-<date>/
# first and put back when `nginx -t` fails. One nginx reload at the end.
#
# Usage: update_wp_cache_blocks.sh [--dry-run]

if [ "$(id -u)" -ne 0 ]; then
    echo "This script must be run as root"
    exit 1
fi
[ -d /etc/nginx/conf.d ] || exit 0
command -v nginx >/dev/null 2>&1 || exit 0
grep -lq '^# BEGIN reqad-nginx-cache$' /etc/nginx/conf.d/*.conf 2>/dev/null || exit 0

if [ "$1" = "--dry-run" ]; then
    exec /usr/local/reqad/bin/reqad wordpress cache-refresh --dry-run
fi
exec /usr/local/reqad/bin/reqad wordpress cache-refresh --quiet
