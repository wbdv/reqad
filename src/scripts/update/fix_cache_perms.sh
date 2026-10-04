#!/bin/bash
# Take world write access off the nginx cache, the panel's nginx cache and the
# shared PHP session directory.
#
# /etc/cron.d/reqad used to run, every minute,
#
#     chmod -R a+rwx /var/cache/nginx/
#     chmod -R a+rwx /var/cache/reqad/
#     chmod -R a+rwx /var/lib/php/session/
#
# which left every cache entry and session file mode 0777:
#   * /var/cache/nginx     any account could overwrite a cached page of another
#                          site (fcgi-<zone>/…) and nginx would serve it to every
#                          visitor until the entry expired
#   * /var/cache/reqad     the same for the panel's own nginx
#   * /var/lib/php/session any account could read, or just list, the session ids
#                          of every site still using the shared directory (the
#                          older per-account pools) and log in as that visitor
#
# None of it is needed. nginx creates its cache and temp trees as the worker
# user with 0700/0600 itself, and the panel purges with sudo. The session
# directory only needs the Debian layout, 1733: everyone can create a file in it,
# nobody but root can list it, and the sticky bit stops one pool from deleting or
# renaming another pool's files. PHP creates session files 0600.
#
# The cron lines are gone from config/cron; this fixes what they left behind.
# Safe to call from post_reqad_install.sh and safe to re-run.

NGINX_CACHE=/var/cache/nginx
REQAD_CACHE=/var/cache/reqad
SESSIONS=/var/lib/php/session

# Worker user of the site nginx (the `user` directive), nginx if unset.
NGINX_USER=$(awk '$1 == "user" { sub(/;$/, "", $2); print $2; exit }' /etc/nginx/nginx.conf 2>/dev/null)
id "${NGINX_USER:-nginx}" >/dev/null 2>&1 || NGINX_USER=nginx
NGINX_USER=${NGINX_USER:-nginx}

# Owner 0700 dirs / 0600 files, which is what nginx itself creates.
lock_down() {
    local dir=$1 owner=$2
    chown -R "$owner" "$dir"
    find "$dir" -type d ! -perm 0700 -exec chmod 0700 {} +
    find "$dir" -type f ! -perm 0600 -exec chmod 0600 {} +
}

if [ -d "$NGINX_CACHE" ]; then
    # The top dir stays root 0755 (as the nginx package ships it); everything
    # under it belongs to the worker user.
    chown root:root "$NGINX_CACHE"
    chmod 0755 "$NGINX_CACHE"
    for d in "$NGINX_CACHE"/*/; do
        [ -d "$d" ] && lock_down "${d%/}" "$NGINX_USER"
    done
    echo "fix_cache_perms: $NGINX_CACHE locked down (owner $NGINX_USER)"
fi

if [ -d "$REQAD_CACHE" ] && id reqad >/dev/null 2>&1; then
    lock_down "$REQAD_CACHE" reqad
    echo "fix_cache_perms: $REQAD_CACHE locked down (owner reqad)"
fi

if [ -d "$SESSIONS" ]; then
    chmod 1733 "$SESSIONS"
    find "$SESSIONS" -maxdepth 1 -type f -name 'sess_*' ! -perm 0600 -exec chmod 0600 {} +
    echo "fix_cache_perms: $SESSIONS set to 1733, session files 0600"
fi

exit 0
