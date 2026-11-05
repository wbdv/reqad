#!/bin/bash
# Keeps the hostname's own vhosts on a certificate that exists:
#   /etc/reqad/conf.d/<hostname>.conf   the panel, port 2087 (the account panel
#                                       on 2083 copies its paths)
#   /etc/nginx/conf.d/<hostname>.conf   the hostname's default nginx host
#
# - A vhost that points at a missing file (Let's Encrypt failed at install:
#   port 80 not reachable from outside, DNS not ready yet...) gets the hostname's
#   Let's Encrypt certificate if there is one by now, else a self-signed one, so
#   the panel can start at all.
# - A vhost on the self-signed one goes back to Let's Encrypt once a certificate
#   for the hostname exists.
# Any other certificate (one the admin chose) is left alone. Each changed file
# is tested with nginx -t and put back when the test fails. Safe to run on every
# post-install; must run before the scripts that test the panel's nginx config.
#
# Usage: update_hostname_cert.sh [--dry-run]

if [ "$(id -u)" -ne 0 ]; then
    echo "  This script must be run as root"
    exit 1
fi

DRY=0
[ "$1" = "--dry-run" ] && DRY=1

HOST=$(hostname)
LE_CRT="/etc/letsencrypt/live/${HOST}/fullchain.pem"
LE_KEY="/etc/letsencrypt/live/${HOST}/privkey.pem"
SS_CRT="/etc/ssl/certs/${HOST}.crt"
SS_KEY="/etc/ssl/certs/${HOST}.key"

have_le() { [ -f "$LE_CRT" ] && [ -f "$LE_KEY" ]; }

# conf_path FILE DIRECTIVE -> the first path given to DIRECTIVE in FILE
conf_path() {
    grep -m1 -E "^[[:space:]]*$2[[:space:]]" "$1" 2>/dev/null | awk '{print $2}' | tr -d ';'
}

make_selfsigned() {
    [ -f "$SS_CRT" ] && [ -f "$SS_KEY" ] && return 0
    echo "  generating a self-signed certificate for ${HOST}"
    bash /usr/local/reqad/scripts/genselfsigned.sh "$HOST" >/dev/null 2>&1
    [ -f "$SS_KEY" ] && chmod 0600 "$SS_KEY"
    [ -f "$SS_CRT" ] && [ -f "$SS_KEY" ]
}

# fix FILE SERVICE TEST_COMMAND...
# SERVICE is reloaded after a change when it is running.
fix() {
    local f="$1" svc="$2"; shift 2
    [ -f "$f" ] || return 0

    local crt key new_crt new_key why
    crt=$(conf_path "$f" ssl_certificate)
    key=$(conf_path "$f" ssl_certificate_key)
    [ -n "$crt" ] || return 0

    if [ "$crt" = "$SS_CRT" ] && have_le; then
        new_crt=$LE_CRT; new_key=$LE_KEY
        why="a Let's Encrypt certificate for ${HOST} exists now"
    elif [ ! -f "$crt" ] || [ ! -f "$key" ]; then
        why="${crt} does not exist"
        if have_le; then
            new_crt=$LE_CRT; new_key=$LE_KEY
        else
            new_crt=$SS_CRT; new_key=$SS_KEY
        fi
    else
        echo "  ${f}: certificate ${crt} is in place, nothing to do"
        return 0
    fi

    if [ $DRY -eq 1 ]; then
        echo "  [dry-run] ${f}: would use ${new_crt} (${why})"
        return 0
    fi

    if [ "$new_crt" = "$SS_CRT" ] && ! make_selfsigned; then
        echo "  ERROR: could not create a self-signed certificate for ${HOST}; ${f} left as it is"
        return 1
    fi

    local bak
    bak=$(mktemp) || return 1
    cp -a "$f" "$bak"
    sed -i -e "s#^\([[:space:]]*\)ssl_certificate_key[[:space:]].*;#\1ssl_certificate_key ${new_key};#" \
           -e "s#^\([[:space:]]*\)ssl_certificate[[:space:]].*;#\1ssl_certificate ${new_crt};#" "$f"

    if "$@" >/dev/null 2>&1; then
        echo "  ${f}: now uses ${new_crt} (${why})"
        systemctl is-active --quiet "$svc" && systemctl reload "$svc"
    else
        cp -a "$bak" "$f"
        echo "  ${f}: nginx -t failed with ${new_crt}, left as it was"
    fi
    rm -f "$bak"
}

fix "/etc/reqad/conf.d/${HOST}.conf" reqad /usr/sbin/nginx -t -c /etc/reqad/nginx.conf
fix "/etc/nginx/conf.d/${HOST}.conf" nginx /usr/sbin/nginx -t
exit 0
