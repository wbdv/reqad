#!/bin/bash
# Rename the server hostname and every config file that carries the old name.
#
#   rename_hostname.sh <new.host.name> [--dry-run] [--yes] [--no-cert] [--delete-old-cert]
#
# Handles: hostnamectl / /etc/hosts, nginx vhost, reqad panel vhost, apache
# vhost, log files, monit, exim primary_hostname, exim+dovecot SNI, certbot
# certificate for the new name, motd.
#
# Every file it touches is copied to /root/reqad-hostname-rename-<timestamp>/ first.

set -u

DRY=0
ASSUME_YES=0
DO_CERT=1
DELETE_OLD_CERT=0
NEW=""

usage() {
    cat <<EOF
Usage: $0 <new.host.name> [options]

Options:
  --dry-run           Show what would be done, change nothing
  -y, --yes           Do not ask for confirmation
  --no-cert           Do not try to obtain a Let's Encrypt cert for the new name
  --delete-old-cert   Also run 'certbot delete' for the old hostname cert
  -h, --help          This help
EOF
}

while [ $# -gt 0 ]; do
    case "$1" in
        --dry-run)         DRY=1 ;;
        -y|--yes)          ASSUME_YES=1 ;;
        --no-cert)         DO_CERT=0 ;;
        --delete-old-cert) DELETE_OLD_CERT=1 ;;
        -h|--help)         usage; exit 0 ;;
        -*)                echo "  Unknown option: $1"; usage; exit 1 ;;
        *)
            if [ -n "$NEW" ]; then echo "  Too many arguments"; usage; exit 1; fi
            NEW="$1"
            ;;
    esac
    shift
done

if [ "$(id -u)" -ne 0 ]; then
    echo "  This script must be run as root"
    exit 1
fi

if [ -z "$NEW" ]; then
    usage
    exit 1
fi

# --- validate ---------------------------------------------------------------

NEW=$(echo "$NEW" | tr '[:upper:]' '[:lower:]' | sed 's/\.$//')

if ! echo "$NEW" | grep -qE '^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$'; then
    echo "  '$NEW' is not a valid fully qualified hostname"
    exit 1
fi
if [ ${#NEW} -gt 253 ]; then
    echo "  '$NEW' is too long"
    exit 1
fi

OLD=$(hostname -f 2>/dev/null || hostname)
OLD_SHORT="${OLD%%.*}"
NEW_SHORT="${NEW%%.*}"

if [ -z "$OLD" ]; then
    echo "  Could not determine the current hostname"
    exit 1
fi
if [ "$OLD" = "$NEW" ]; then
    echo "  Hostname is already '$NEW', nothing to do"
    exit 0
fi

# regex-safe version of the old name, for sed
OLD_RE=$(printf '%s' "$OLD" | sed 's/[.[\*^$\/]/\\&/g')

INI="/usr/local/reqad/etc/server-software.ini"
TEMPLATE=""
[ -f "$INI" ] && TEMPLATE=$(grep -oP '^template=\K.*' "$INI" | tr -d '[:space:]')

STAMP=$(date +%Y%m%d-%H%M%S)
BACKUP_DIR="/root/reqad-hostname-rename-${STAMP}"

echo
echo "  Old hostname : ${OLD}"
echo "  New hostname : ${NEW}"
echo "  Web template : ${TEMPLATE:-unknown}"
echo "  Backups      : ${BACKUP_DIR}"
[ $DRY -eq 1 ] && echo "  Mode         : DRY RUN (no changes)"
echo

# Warn (do not block) if the new name does not resolve to one of our IPs.
NEW_IP=$(getent ahostsv4 "$NEW" 2>/dev/null | awk '{print $1; exit}')
if [ -z "$NEW_IP" ]; then
    echo "  WARNING: '${NEW}' does not resolve in DNS — Let's Encrypt will fail for it"
elif ! ip -4 addr show 2>/dev/null | grep -qw "$NEW_IP"; then
    echo "  WARNING: '${NEW}' resolves to ${NEW_IP}, which is not an IP of this server"
fi

if [ $ASSUME_YES -eq 0 ] && [ $DRY -eq 0 ]; then
    if [ -t 0 ]; then
        printf "  Proceed with the rename? [y/N] "
        read -r ANSWER
        case "$ANSWER" in
            y|Y|yes|YES) ;;
            *) echo "  Aborted."; exit 0 ;;
        esac
        echo
    else
        echo "  Not a terminal and --yes was not given — aborting."
        exit 1
    fi
fi

[ $DRY -eq 0 ] && mkdir -p "$BACKUP_DIR"

# --- helpers ----------------------------------------------------------------

run() {
    if [ $DRY -eq 1 ]; then
        echo "    [dry-run] $*"
    else
        "$@"
    fi
}

backup() {
    # backup <file> — flattened copy into $BACKUP_DIR
    local f="$1"
    [ -e "$f" ] || return 0
    [ $DRY -eq 1 ] && return 0
    cp -a "$f" "${BACKUP_DIR}/$(echo "${f#/}" | tr '/' '_')" 2>/dev/null
}

# Replace every occurrence of the old hostname inside a file.
substitute() {
    local f="$1"
    [ -f "$f" ] || return 0
    grep -q "$OLD_RE" "$f" 2>/dev/null || return 0
    backup "$f"
    if [ $DRY -eq 1 ]; then
        echo "    [dry-run] sed -i s/${OLD}/${NEW}/g ${f}  ($(grep -c "$OLD_RE" "$f") match(es))"
    else
        sed -i "s/${OLD_RE}/${NEW}/g" "$f"
        echo "    updated ${f}"
    fi
}

# Rename <dir>/<old>.conf* to <dir>/<new>.conf* and substitute inside.
rename_conf() {
    local dir="$1"
    local label="$2"
    [ -d "$dir" ] || { echo "  ${label}: ${dir} not present, skipping"; return 0; }
    local found=0 f base newf
    for f in "${dir}/${OLD}.conf"*; do
        [ -e "$f" ] || continue
        found=1
        base=$(basename "$f")
        newf="${dir}/${NEW}${base#${OLD}}"
        substitute "$f"
        if [ $DRY -eq 1 ]; then
            echo "    [dry-run] mv ${f} ${newf}"
        else
            mv -f "$f" "$newf" && echo "    renamed  ${f} -> ${newf}"
        fi
    done
    [ $found -eq 0 ] && echo "  ${label}: no ${OLD}.conf in ${dir}, skipping"
    return 0
}

# Rename <dir>/<old>_log* to <dir>/<new>_log*
rename_logs() {
    local dir="$1"
    [ -d "$dir" ] || return 0
    local f base newf n=0
    for f in "${dir}/${OLD}_log"*; do
        [ -e "$f" ] || continue
        base=$(basename "$f")
        newf="${dir}/${NEW}${base#${OLD}}"
        if [ $DRY -eq 0 ]; then
            mv -f "$f" "$newf" || continue
        fi
        n=$((n + 1))
    done
    [ $n -eq 0 ] && return 0
    if [ $DRY -eq 1 ]; then
        echo "    [dry-run] ${dir}: ${n} log file(s) ${OLD}_log* -> ${NEW}_log*"
    else
        echo "    ${dir}: renamed ${n} log file(s) to ${NEW}_log*"
    fi
}

# Point the SSL directives of a vhost at a given cert/key pair.
point_nginx_cert() {
    local f="$1" cert="$2" key="$3"
    [ -f "$f" ] || return 0
    if [ $DRY -eq 1 ]; then
        echo "    [dry-run] point ${f} at ${cert}"
        return 0
    fi
    backup "$f"
    sed -i -e "s#^\([[:space:]]*\)ssl_certificate_key[[:space:]].*;#\1ssl_certificate_key ${key};#" \
           -e "s#^\([[:space:]]*\)ssl_certificate[[:space:]].*;#\1ssl_certificate ${cert};#" "$f"
    echo "    ${f} now uses ${cert}"
}

point_apache_cert() {
    local f="$1" cert="$2" key="$3" chain="$4"
    [ -f "$f" ] || return 0
    if [ $DRY -eq 1 ]; then
        echo "    [dry-run] point ${f} at ${cert}"
        return 0
    fi
    backup "$f"
    sed -i -e "s#^\([[:space:]]*\)SSLCertificateFile[[:space:]].*#\1SSLCertificateFile ${cert}#" \
           -e "s#^\([[:space:]]*\)SSLCertificateKeyFile[[:space:]].*#\1SSLCertificateKeyFile ${key}#" "$f"
    if [ -n "$chain" ]; then
        # (re)activate the chain line, whether it is currently commented out or not
        sed -i -e "s|^\([[:space:]]*\)#\?[[:space:]]*SSLCertificateChainFile[[:space:]].*|\1SSLCertificateChainFile ${chain}|" "$f"
    else
        # a self-signed cert has no chain — comment the line out
        sed -i -e "s|^\([[:space:]]*\)SSLCertificateChainFile[[:space:]]|\1#SSLCertificateChainFile |" "$f"
    fi
}

service_active() { systemctl is-active --quiet "$1" 2>/dev/null; }

reload_service() {
    local svc="$1"
    service_active "$svc" || { echo "  ${svc} is not running, skipping reload"; return 0; }
    run systemctl reload "$svc" && echo "  reloaded ${svc}"
}

# --- 1. hostname ------------------------------------------------------------

echo "  [1/10] Setting system hostname..."
backup /etc/hostname
run hostnamectl set-hostname "$NEW"
[ $DRY -eq 0 ] && echo "    hostname is now $(hostname -f 2>/dev/null || hostname)"

echo "  [2/10] Updating /etc/hosts..."
if grep -q "$OLD_RE" /etc/hosts 2>/dev/null; then
    substitute /etc/hosts
else
    echo "    no ${OLD} entry in /etc/hosts, nothing to change"
fi

# --- 3. web server vhosts ---------------------------------------------------

echo "  [3/10] Renaming web server config files..."
rename_conf /etc/nginx/conf.d "nginx"
rename_conf /etc/reqad/conf.d "reqad panel"
rename_conf /etc/httpd/conf.d "apache"

echo "  [4/10] Renaming log files..."
rename_logs /var/log/nginx
rename_logs /var/log/reqad
rename_logs /var/log/httpd

# --- 5. certificate ---------------------------------------------------------

echo "  [5/10] Sorting out the SSL certificate for ${NEW}..."

NGINX_CONF="/etc/nginx/conf.d/${NEW}.conf"
REQAD_CONF="/etc/reqad/conf.d/${NEW}.conf"
HTTPD_CONF="/etc/httpd/conf.d/${NEW}.conf"

LE_DIR="/etc/letsencrypt/live/${NEW}"
SS_CRT="/etc/ssl/certs/${NEW}.crt"
SS_KEY="/etc/ssl/certs/${NEW}.key"

use_selfsigned() {
    if [ ! -f "$SS_CRT" ] || [ ! -f "$SS_KEY" ]; then
        echo "    generating self-signed certificate for ${NEW}..."
        run bash /usr/local/reqad/scripts/genselfsigned.sh "$NEW"
        if [ $DRY -eq 0 ] && { [ ! -f "$SS_CRT" ] || [ ! -f "$SS_KEY" ]; }; then
            echo "    ERROR: could not create a self-signed certificate for ${NEW}"
            echo "           the vhosts keep their current cert paths — fix this by hand"
            return 1
        fi
    fi
    point_nginx_cert  "$NGINX_CONF" "$SS_CRT" "$SS_KEY"
    point_nginx_cert  "$REQAD_CONF" "$SS_CRT" "$SS_KEY"
    point_apache_cert "$HTTPD_CONF" "$SS_CRT" "$SS_KEY" ""
}

use_letsencrypt() {
    point_nginx_cert  "$NGINX_CONF" "${LE_DIR}/fullchain.pem" "${LE_DIR}/privkey.pem"
    point_nginx_cert  "$REQAD_CONF" "${LE_DIR}/fullchain.pem" "${LE_DIR}/privkey.pem"
    point_apache_cert "$HTTPD_CONF" "${LE_DIR}/cert.pem" "${LE_DIR}/privkey.pem" "${LE_DIR}/chain.pem"
}

if [ -d "$LE_DIR" ]; then
    echo "    Let's Encrypt cert for ${NEW} already exists"
    use_letsencrypt
    NEED_LE=0
else
    echo "    no Let's Encrypt cert for ${NEW} yet — using a self-signed one for now"
    use_selfsigned
    NEED_LE=1
fi

# --- 6. test and reload web servers ----------------------------------------

echo "  [6/10] Testing and reloading web servers..."
if command -v nginx >/dev/null 2>&1; then
    if [ $DRY -eq 1 ]; then
        echo "    [dry-run] nginx -t && systemctl reload nginx"
    elif nginx -t >/dev/null 2>&1; then
        service_active nginx && systemctl reload nginx && echo "    nginx reloaded"
    else
        echo "    ERROR: nginx config test failed — not reloading:"
        nginx -t
    fi

    if [ -f /etc/reqad/nginx.conf ]; then
        if [ $DRY -eq 1 ]; then
            echo "    [dry-run] nginx -t -c /etc/reqad/nginx.conf && systemctl reload reqad"
        elif nginx -t -c /etc/reqad/nginx.conf >/dev/null 2>&1; then
            service_active reqad && systemctl reload reqad && echo "    reqad panel reloaded"
        else
            echo "    ERROR: reqad panel config test failed — not reloading:"
            nginx -t -c /etc/reqad/nginx.conf
        fi
    fi
fi
if [ -f "$HTTPD_CONF" ] && command -v apachectl >/dev/null 2>&1; then
    if [ $DRY -eq 1 ]; then
        echo "    [dry-run] apachectl configtest && systemctl reload httpd"
    elif apachectl configtest >/dev/null 2>&1; then
        service_active httpd && systemctl reload httpd && echo "    httpd reloaded"
    else
        echo "    ERROR: httpd config test failed — not reloading:"
        apachectl configtest
    fi
fi

# --- 7. obtain a real certificate ------------------------------------------

echo "  [7/10] Let's Encrypt certificate..."
if [ $DO_CERT -eq 0 ]; then
    echo "    --no-cert given, skipping"
elif [ "${NEED_LE}" -eq 0 ]; then
    echo "    already have a cert for ${NEW}, skipping"
elif ! command -v certbot >/dev/null 2>&1; then
    echo "    certbot is not installed, skipping — ${NEW} stays on the self-signed cert"
else
    case "$TEMPLATE" in
        apache_modphp) PLUGIN="apache" ;;
        *)             PLUGIN="nginx"  ;;
    esac
    echo "    requesting a certificate for ${NEW} with the ${PLUGIN} plugin..."
    if [ $DRY -eq 1 ]; then
        echo "    [dry-run] certbot certonly -n --${PLUGIN} -d ${NEW}"
    else
        if certbot certonly -n --"${PLUGIN}" -d "$NEW"; then
            echo "    certificate obtained"
            use_letsencrypt
            nginx -t >/dev/null 2>&1 && service_active nginx && systemctl reload nginx
            [ -f /etc/reqad/nginx.conf ] && nginx -t -c /etc/reqad/nginx.conf >/dev/null 2>&1 \
                && service_active reqad && systemctl reload reqad
            if [ -f "$HTTPD_CONF" ] && command -v apachectl >/dev/null 2>&1; then
                apachectl configtest >/dev/null 2>&1 && service_active httpd && systemctl reload httpd
            fi
            NEED_LE=0
        else
            echo "    WARNING: certbot failed — ${NEW} stays on the self-signed certificate."
            echo "             Fix DNS, then run: certbot certonly -n --${PLUGIN} -d ${NEW}"
            echo "             and re-run this script (it will pick up the new cert)."
        fi
    fi
fi

# Old renewal config
if [ -f "/etc/letsencrypt/renewal/${OLD}.conf" ]; then
    if [ $DELETE_OLD_CERT -eq 1 ]; then
        echo "    deleting the old certificate for ${OLD}..."
        run certbot delete -n --cert-name "$OLD"
    else
        echo "    the certificate for ${OLD} was left in place"
        echo "    (remove it with: certbot delete --cert-name ${OLD})"
    fi
fi

# Make sure the renewal config for the new name uses the right plugin
if [ -x /usr/local/reqad/scripts/update/update_certbot_hostname.sh ]; then
    run bash /usr/local/reqad/scripts/update/update_certbot_hostname.sh
fi

# --- 8. mail ----------------------------------------------------------------

echo "  [8/10] Mail (exim / dovecot)..."

# primary_hostname / qualify_domain, only when explicitly set to the old name
if [ -f /etc/exim/exim.conf ]; then
    if grep -qE "^[[:space:]]*(primary_hostname|qualify_domain)[[:space:]]*=[[:space:]]*${OLD_RE}[[:space:]]*$" /etc/exim/exim.conf; then
        substitute /etc/exim/exim.conf
    else
        echo "    exim.conf does not pin the hostname, nothing to change"
    fi
fi

# SNI maps for exim + dovecot are generated, so regenerate them
if [ -x /usr/local/reqad/scripts/update_email_sni ]; then
    echo "    regenerating exim/dovecot SNI maps..."
    run /usr/local/reqad/scripts/update_email_sni
else
    # fall back to a plain substitution
    substitute /etc/exim/sni_certs.db
    substitute /etc/exim/sni_keys.db
    substitute /etc/dovecot/sni.conf
fi

if service_active exim; then
    run systemctl restart exim && [ $DRY -eq 0 ] && echo "    exim restarted"
fi
if service_active dovecot; then
    run systemctl restart dovecot && [ $DRY -eq 0 ] && echo "    dovecot restarted"
fi

# --- 9. monit + motd --------------------------------------------------------

echo "  [9/10] monit and motd..."
if [ -d /etc/monit.d ]; then
    for f in /etc/monit.d/*; do
        [ -f "$f" ] || continue
        substitute "$f"
        # MariaDB writes its pid file as <short hostname>.pid
        if grep -q "/var/lib/mysql/${OLD_SHORT}\.pid" "$f" 2>/dev/null; then
            backup "$f"
            if [ $DRY -eq 1 ]; then
                echo "    [dry-run] ${f}: ${OLD_SHORT}.pid -> ${NEW_SHORT}.pid"
            else
                sed -i "s#/var/lib/mysql/${OLD_SHORT}\.pid#/var/lib/mysql/${NEW_SHORT}.pid#g" "$f"
                echo "    updated mysql pidfile path in ${f}"
            fi
        fi
    done
    service_active monit && run systemctl reload monit
fi

if [ -x /usr/local/reqad/scripts/update_motd.sh ]; then
    run bash /usr/local/reqad/scripts/update_motd.sh
    [ $DRY -eq 0 ] && echo "    motd regenerated"
fi

# --- 10. leftovers ----------------------------------------------------------

echo "  [10/10] Scanning for leftover references to ${OLD}..."
if [ $DRY -eq 1 ]; then
    echo "    skipped — nothing was changed, so everything would still match"
    LEFTOVERS=""
else
LEFTOVERS=$(grep -rlI "$OLD" /etc /usr/local/reqad/etc 2>/dev/null \
            | grep -vE '/(\.git|letsencrypt/(archive|live|accounts)|.*\.bak.*|dovecot\.backup-)' \
            | sort -u)
if [ -n "$LEFTOVERS" ]; then
    echo "    The following files still mention ${OLD} — review them by hand:"
    echo "$LEFTOVERS" | sed 's/^/      /'
else
    echo "    none found"
fi
fi

echo
if [ $DRY -eq 1 ]; then
    echo "  Dry run finished — nothing was changed."
else
    echo "  Done. Hostname is now $(hostname -f 2>/dev/null || hostname)."
    echo "  Backups of every modified file: ${BACKUP_DIR}"
    if [ "${NEED_LE}" -eq 1 ]; then
        echo "  NOTE: ${NEW} is on a SELF-SIGNED certificate — see the message above."
    fi
    echo "  Log out and back in for the shell prompt to pick up the new name."
fi
echo
