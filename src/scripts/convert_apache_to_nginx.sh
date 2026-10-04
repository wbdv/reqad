#!/bin/bash
#
# convert_apache_to_nginx.sh — move a Reqad server from the apache_modphp
# template to nginx_php-fpm.
#
# What it does, in order:
#   1. preflight: template must be apache_*, php-fpm installed; nginx is
#      installed from the reqad repo when missing, and nginx.conf replaced with
#      the Reqad one (scripts/install/config/nginx.txt) when it is not
#   2. backup of every config it touches (restored automatically on failure)
#   3. for every hosting account and every additional domain:
#        - /etc/nginx/conf.d/<domain>.conf built from the apache vhost
#          (server names, document root, certificate)
#        - php-fpm pool: created for mod_php accounts (carrying over the
#          php_admin_value/php_value lines of the vhost), existing pools of fpm
#          accounts get their group switched apache -> nginx
#   4. nginx -t and php-fpm -t, then: stop+disable httpd, restart php-fpm,
#      enable+start nginx
#   5. template=nginx_php-fpm and httpd -> nginx in etc/server-software.ini
#   6. /etc/letsencrypt/renewal/*.conf: authenticator/installer apache -> nginx
#   7. list of .htaccess files per account — nginx ignores them, each one has to
#      be reviewed (and translated into the vhost if it matters)
#
# The apache vhosts in /etc/httpd/conf.d are left in place (httpd is only
# disabled), so going back is: restore the backup, start httpd.
#
set -u

PATH_REQAD="$(cd "$(dirname "$0")/.." && pwd)"
INI="${PATH_REQAD}/etc/server-software.ini"
DB="${PATH_REQAD}/db/reqad.db"
HTTPD_DIR="/etc/httpd/conf.d"
NGINX_DIR="/etc/nginx/conf.d"
AUTOCONFIG_SNIPPET="/etc/nginx/reqad-autoconfig.conf"
TS="$(date '+%Y%m%d-%H%M%S')"
WORKDIR="/root/reqad-apache-to-nginx-${TS}"
REPORT="${PATH_REQAD}/log/htaccess-report-${TS}.txt"

DRYRUN=0
ASSUME_YES=0
FORCE=0
HTACCESS_ONLY=0

usage() {
    cat <<EOF
Convert this server from the apache_modphp template to nginx_php-fpm.

Usage: $0 [options]

Options:
  --dry-run         build every file in ${WORKDIR%-*}-<date>/staged and stop there;
                    nothing on the server is changed
  --yes             do not ask for confirmation before switching web servers
  --force           overwrite /etc/nginx/conf.d/<domain>.conf files that already
                    exist (default: keep them)
  --htaccess-only   only write the .htaccess report
  -h, --help        this help
EOF
}

for arg in "$@"; do
    case "$arg" in
        --dry-run)       DRYRUN=1 ;;
        --yes|-y)        ASSUME_YES=1 ;;
        --force)         FORCE=1 ;;
        --htaccess-only) HTACCESS_ONLY=1 ;;
        -h|--help)       usage; exit 0 ;;
        *)               echo "Error: unknown argument '$arg'"; usage; exit 1 ;;
    esac
done

die()  { echo "Error: $*" >&2; exit 1; }
warn() { echo "  Warning: $*"; WARNINGS+=("$*"); }
WARNINGS=()

[ "$(id -u)" -eq 0 ] || die "You can only run this script as root."
[ -f "$DB" ]  || die "Missing SQLite database $DB"
[ -f "$INI" ] || die "Missing $INI"

# .sqliterc forces box mode, and /usr/local/bin/sqlite3 is not on every server
sq() { /usr/bin/sqlite3 -init /dev/null -batch -noheader -list "$DB" "$1" 2>/dev/null; }

ini_get() {
    grep -E "^[[:space:]]*$1[[:space:]]*=" "$INI" | head -n1 | cut -d= -f2- | tr -d '[:space:]'
}

# ============================================================ .htaccess report
#
# nginx does not read .htaccess. Each file is classified so the admin can tell
# at a glance which ones matter: the stock root WordPress block is already
# covered by the nginx template (try_files ... /index.php), everything else
# needs a look.

WP_DEFAULT_LINES='<ifmodule mod_rewrite.c>
rewriteengine on
rewriterule .* - [e=http_authorization:%{http:authorization}]
rewritebase /
rewriterule ^index\.php$ - [l]
rewritecond %{request_filename} !-f
rewritecond %{request_filename} !-d
rewriterule . /index.php [l]
</ifmodule>'

htaccess_classify() {
    local f="$1" body tags=()
    # directives only: no comments, no blank lines, trimmed, lowercased
    body="$(sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' -e '/^#/d' -e '/^$/d' "$f" 2>/dev/null | tr '[:upper:]' '[:lower:]')"

    if [ -z "$body" ]; then
        echo "empty"
        return
    fi
    if [ -z "$(grep -vxF -f <(echo "$WP_DEFAULT_LINES") <<<"$body")" ]; then
        echo "wordpress-default (covered by the nginx template)"
        return
    fi

    grep -qE '^php_(admin_)?(value|flag)'                                 <<<"$body" && tags+=("PHP settings -> php-fpm pool")
    grep -qE '^(authtype|authuserfile|authname|require (valid-user|user))' <<<"$body" && tags+=("password protection")
    grep -qE '^(deny from|allow from|order |require (all|ip|not|host))'   <<<"$body" && tags+=("access control")
    grep -qE '^(rewriterule|rewritecond|redirect|redirectmatch)'          <<<"$body" && tags+=("rewrites/redirects")
    grep -qE '^(header|expires|addtype|addhandler|sethandler|errordocument|directoryindex|options|setenv|addoutputfilter|<files)' <<<"$body" \
        && tags+=("headers/handlers/other")
    grep -qE '^# begin wordpress' <(tr '[:upper:]' '[:lower:]' <"$f") && tags+=("wordpress (modified or subdirectory)")
    [ ${#tags[@]} -eq 0 ] && tags+=("other")

    local IFS=';'
    echo "REVIEW: ${tags[*]}" | sed 's/;/; /g'
}

htaccess_report() {
    local out="$1" user home total=0 review=0
    {
        echo "# .htaccess files on $(hostname) — $(date '+%Y-%m-%d %H:%M:%S')"
        echo "# nginx ignores .htaccess. Files marked REVIEW carry rules that must be"
        echo "# moved into /etc/nginx/conf.d/<domain>.conf (or the php-fpm pool) by hand."
        echo
    } > "$out"

    while IFS='|' read -r user domain; do
        [ -n "$user" ] || continue
        home="/home/${user}"
        [ -d "$home" ] || continue
        local files n=0 r=0 cls
        files="$(find "$home" -xdev \
                    \( -path "${home}/mail" -o -path "${home}/.cache" -o -path "${home}/tmp" \
                       -o -path "${home}/.trash" -o -path "${home}/.npm" -o -path "${home}/.composer" \) -prune \
                    -o -type f -name '.htaccess' -print 2>/dev/null | sort)"
        {
            echo "== ${user} (${domain})"
            if [ -z "$files" ]; then
                echo "   (no .htaccess files)"
            else
                while IFS= read -r f; do
                    cls="$(htaccess_classify "$f")"
                    n=$((n + 1))
                    [[ "$cls" == REVIEW:* ]] && r=$((r + 1))
                    printf '   %-8s %s\n' "$(awk 'END { print NR }' "$f") ln" "$f"
                    printf '            %s\n' "$cls"
                done <<<"$files"
            fi
            echo "   -> ${n} file(s), ${r} to review"
            echo
        } >> "$out"
        total=$((total + n))
        review=$((review + r))
    done < <(sq "SELECT user, domain FROM accounts ORDER BY user;")

    echo "# total: ${total} file(s), ${review} to review" >> "$out"
    chmod 0600 "$out"
    echo "  .htaccess report: ${out} (${total} file(s), ${review} to review)"
}

if [ "$HTACCESS_ONLY" -eq 1 ]; then
    htaccess_report "$REPORT"
    exit 0
fi

# ================================================================= preflight
echo "Preflight checks"

TEMPLATE="$(ini_get template)"
case "$TEMPLATE" in
    apache_*) echo "  template ............ ${TEMPLATE}" ;;
    nginx_*)  die "template in $INI is already '${TEMPLATE}', nothing to convert." ;;
    *)        die "unknown template '${TEMPLATE}' in $INI." ;;
esac

# The Reqad nginx build is the one with brotli compiled in; the Reqad nginx.conf
# (scripts/install/config/nginx.txt) and the vhosts (allow_methods) need it.
NEED_NGINX=0
NEED_NGINX_CONF=0
if ! command -v nginx >/dev/null 2>&1; then
    NEED_NGINX=1
    echo "  nginx ............... missing, will be installed from the reqad repo"
elif ! nginx -V 2>&1 | grep -q brotli; then
    NEED_NGINX=1
    echo "  nginx ............... $(rpm -q nginx) is not the Reqad build, will be replaced"
else
    echo "  nginx ............... $(nginx -v 2>&1 | sed 's/.*nginx\///')"
fi
if [ "$NEED_NGINX" -eq 1 ] || ! grep -q 'brotli on' /etc/nginx/nginx.conf 2>/dev/null; then
    NEED_NGINX_CONF=1
    echo "  nginx.conf .......... will be replaced with scripts/install/config/nginx.txt"
fi
[ -f "${PATH_REQAD}/scripts/install/config/nginx.txt" ] || die "missing ${PATH_REQAD}/scripts/install/config/nginx.txt"

# mail client autoconfig snippet every main vhost includes
NEED_AUTOCONFIG=0
INCLUDE_AUTOCONFIG=0
if [ -f "$AUTOCONFIG_SNIPPET" ]; then
    INCLUDE_AUTOCONFIG=1
elif [ -x "${PATH_REQAD}/scripts/update/setup_autoconfig.sh" ]; then
    INCLUDE_AUTOCONFIG=1
    NEED_AUTOCONFIG=1
    echo "  autoconfig .......... ${AUTOCONFIG_SNIPPET} will be created (setup_autoconfig.sh)"
fi

[ -x /usr/sbin/php-fpm ] || die "php-fpm (default PHP) is not installed: dnf install php-fpm"

PHP_DEFAULT="$(ini_get php)"
[ -n "$PHP_DEFAULT" ] || die "No 'php=' entry in $INI (run scripts/update_php_versions)."
echo "  default PHP ......... ${PHP_DEFAULT}"

rpm -q python3-certbot-nginx >/dev/null 2>&1 || NEED_CERTBOT_NGINX=1
[ "${NEED_CERTBOT_NGINX:-0}" -eq 1 ] && echo "  certbot nginx plugin  missing, will be installed"

# ---------------------------------------------------------- domain inventory
# DOMAINS rows: kind|user|domain|has_email   (kind = main or addon)
DOMAINS=()
while IFS='|' read -r user domain has_email; do
    [ -n "$user" ] || continue
    DOMAINS+=("main|${user}|${domain}|${has_email}")
done < <(sq "SELECT user, domain, has_email FROM accounts ORDER BY user;")

for f in "$HTTPD_DIR"/*.conf; do
    [ -f "$f" ] || continue
    u="$(head -n1 "$f" | sed -nE 's/^#[[:space:]]*Additional domain of account[[:space:]]+([a-z][a-z0-9]{1,15})\b.*adddomain.*/\1/p')"
    [ -n "$u" ] && DOMAINS+=("addon|${u}|$(basename "$f" .conf)|0")
done

[ ${#DOMAINS[@]} -gt 0 ] || die "no accounts found in $DB."
echo "  domains ............. ${#DOMAINS[@]} ($(printf '%s\n' "${DOMAINS[@]}" | grep -c '^main|') accounts, $(printf '%s\n' "${DOMAINS[@]}" | grep -c '^addon|') additional)"

# apache vhosts that belong to nobody we know about: reported, not converted
KNOWN_FILES=" $(printf '%s\n' "${DOMAINS[@]}" | awk -F'|' '{printf "%s.conf %s-le-ssl.conf ", $3, $3}') $(hostname).conf "
UNKNOWN_VHOSTS=()
for f in "$HTTPD_DIR"/*.conf; do
    [ -f "$f" ] || continue
    b="$(basename "$f")"
    grep -qiE '^[[:space:]]*<VirtualHost' "$f" || continue
    [[ "$KNOWN_FILES" == *" $b "* ]] || UNKNOWN_VHOSTS+=("$f")
done

# ============================================================ file tracking
#
# Every file written on the server goes through install_file, which records
# whether it was created or replaced; rollback undoes exactly that.

STAGE="${WORKDIR}/staged"
ORIG="${WORKDIR}/original"
CHANGELOG="${WORKDIR}/changes.log"
mkdir -p "$STAGE" "$ORIG"
chmod 0700 "$WORKDIR"
: > "$CHANGELOG"

install_file() {   # install_file <staged file> <target> [mode]
    local src="$1" dst="$2" mode="${3:-0644}"
    if [ -e "$dst" ]; then
        mkdir -p "${ORIG}$(dirname "$dst")"
        cp -a "$dst" "${ORIG}${dst}"
        echo "M ${dst}" >> "$CHANGELOG"
    else
        echo "C ${dst}" >> "$CHANGELOG"
    fi
    install -m "$mode" -o root -g root "$src" "$dst"
}

rollback() {
    echo "Rolling back configuration changes..."
    tac "$CHANGELOG" | while read -r op path; do
        case "$op" in
            C) rm -f "$path"; echo "  removed  $path" ;;
            M) cp -a "${ORIG}${path}" "$path"; echo "  restored $path" ;;
        esac
    done
}

# ======================================================= apache vhost parser
#
# Prints tab separated records from <domain>.conf (+ the -le-ssl.conf certbot
# may have split off): NAME, DOCROOT, SSL, CERT, KEY, SOCKET, PHP.

parse_apache_vhost() {
    local domain="$1" files=()
    [ -f "${HTTPD_DIR}/${domain}.conf" ]        && files+=("${HTTPD_DIR}/${domain}.conf")
    [ -f "${HTTPD_DIR}/${domain}-le-ssl.conf" ] && files+=("${HTTPD_DIR}/${domain}-le-ssl.conf")
    [ ${#files[@]} -gt 0 ] || return 1

    awk '
        { line = $0; sub(/^[ \t]+/, "", line) }
        line ~ /^#/ || line == "" { next }
        { d = tolower($1) }
        d ~ /^<virtualhost/ && $0 ~ /:443>/  { print "SSL\t1" }
        d == "servername"   { print "NAME\t" $2 }
        d == "serveralias"  { for (i = 2; i <= NF; i++) print "NAME\t" $i }
        d == "documentroot" { v = $2; gsub(/"/, "", v); print "DOCROOT\t" v }
        d == "sslcertificatefile"    { print "CERT\t" $2 }
        d == "sslcertificatekeyfile" { print "KEY\t" $2 }
        d == "sethandler" && $0 ~ /proxy:unix:/ {
            match($0, /proxy:unix:[^|"]+/)
            print "SOCKET\t" substr($0, RSTART + 11, RLENGTH - 11)
        }
        d ~ /^php_(admin_)?(value|flag)$/ {
            v = line
            sub(/^[^ \t]+[ \t]+[^ \t]+[ \t]*/, "", v)
            sub(/[ \t]+$/, "", v)
            print "PHP\t" d "\t" $2 "\t" v
        }
    ' "${files[@]}"
}

# ================================================================ php-fpm
find_pool() {   # existing pool file of <domain> in any php version, or ""
    find /etc/php-fpm.d /etc/opt/remi/*/php-fpm.d -maxdepth 1 -name "$1.conf" 2>/dev/null | head -n1
}

pool_service() {
    case "$1" in
        /etc/opt/remi/*) echo "$(echo "$1" | cut -d/ -f5)-php-fpm" ;;
        *)               echo "php-fpm" ;;
    esac
}

pool_fpm_binary() {
    case "$1" in
        /etc/opt/remi/*) echo "/opt/remi/$(echo "$1" | cut -d/ -f5)/root/usr/sbin/php-fpm" ;;
        *)               echo "/usr/sbin/php-fpm" ;;
    esac
}

# set (or add) "key = value" in a pool file; key is e.g. php_admin_value[memory_limit]
set_pool_value() {
    local file="$1"
    K="$2" V="$3" awk '
        BEGIN { k = ENVIRON["K"]; v = ENVIRON["V"] }
        {
            t = $0; sub(/^;+/, "", t)
            n = index(t, "="); key = (n ? substr(t, 1, n - 1) : "")
            gsub(/[ \t]+/, "", key)
            if (!done && key == k) { print k " = " v; done = 1; next }
            print
        }
        END { if (!done) print k " = " v }
    ' "$file" > "${file}.tmp" && mv "${file}.tmp" "$file"
}

pool_content() {   # same pool the nginx_php-fpm template writes
    local domain="$1" user="$2" socket="$3"
    cat <<EOF
[${domain}]
user = ${user}
group = nginx
listen = ${socket}
listen.owner = ${user}
listen.group = nginx
listen.allowed_clients = 127.0.0.1

pm = dynamic
pm.max_children = 200
pm.start_servers = 10
pm.min_spare_servers = 5
pm.max_spare_servers = 50
pm.process_idle_timeout = 1s;
pm.max_requests = 1000

ping.path = /ping
slowlog = /var/log/php-fpm/${domain}-slow.log
chdir = /

php_admin_value[disable_functions] = show_source, system, shell_exec, passthru, exec, popen, proc_open
php_admin_value[open_basedir] = /home/${user}
;php_admin_value[error_reporting] = E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT
php_admin_value[error_log] = "/home/${user}/logs/${domain}-error.log"
php_admin_flag[log_errors] = on
php_admin_value[sys_temp_dir] = "/home/${user}/tmp"
php_admin_value[upload_tmp_dir] = "/home/${user}/tmp"
php_admin_value[memory_limit] = 2048M
php_value[session.save_handler] = files
php_value[session.save_path] = "/home/${user}/tmp"
php_value[soap.wsdl_cache_dir]  = /var/lib/php/wsdlcache
EOF
}

# ================================================================== nginx
# Bodies mirror scripts/templates/nginx_php-fpm.php (main domain) and
# scripts/adddomain (additional domain) so the panel edits them the same way.

nginx_main_body() {
    local domain="$1" docroot="$2" upstream="$3"
    cat <<EOF
	error_log /var/log/nginx/${domain}_log;
	access_log /var/log/nginx/${domain}_log;

EOF
    if [ "$INCLUDE_AUTOCONFIG" -eq 1 ]; then
        cat <<EOF
    # Mail client autoconfiguration (Thunderbird / Outlook). Static files
    # generated by scripts/update_autoconfig; see the snippet for why the
    # locations are shaped the way they are.
    include ${AUTOCONFIG_SNIPPET};

EOF
    fi
    cat <<EOF
   	root        ${docroot};
   	autoindex   off;
   	index       index.php index.html index.htm;
   	include /etc/nginx/reqad-error-pages.conf;

    # Allow OPTIONS for Wordpress
    allow_methods "^(GET|POST|HEAD|OPTIONS)\$";

  	location / {

		# wordpress rewrite
        location /wp-json {
            rewrite ^/wp-json(.*)\$ /?rest_route=\$1;
        }

        try_files \$uri \$uri/ /index.php\$is_args\$args;

        # Deny all attempts to access hidden files such as .htaccess, .htpasswd, .DS_Store
        location ~ /\\. {
            deny all;
        }

        # Deny access to any files with a .php extension in the uploads directory
        # Works in sub-directory installs and also in multisite network
        # Must stay ABOVE the PHP handler: nested regex locations match in file
        # order, so placed after the .php location this deny never fired.
        location ~* /(?:uploads|files)/.*\\.php\$ {
            deny all;
        }

        location ~ \\.php\$ {
            try_files                   \$uri =404;
            fastcgi_split_path_info     ^(.+\\.php)(/.+)\$;
            fastcgi_intercept_errors    off;

            include         /etc/nginx/fastcgi_params;
            fastcgi_index   index.php;
            fastcgi_param   SCRIPT_FILENAME     \$document_root\$fastcgi_script_name;
            fastcgi_pass    ${upstream};
        }

        location ~* \\.(js|css|png|jpg|jpeg|gif|ico|woff2)\$ {
            expires max;
            log_not_found off;
        }
        location = /favicon.ico {
            log_not_found off;
            access_log off;
        }

        location = /robots.txt {
            allow all;
            log_not_found off;
            access_log off;
        }
    }
EOF
}

nginx_addon_body() {
    local domain="$1" docroot="$2" upstream="$3"
    cat <<EOF
    error_log  /var/log/nginx/${domain}_log;
    access_log /var/log/nginx/${domain}_log;

    root        ${docroot};
    autoindex   off;
    index       index.php index.html index.htm;
    include /etc/nginx/reqad-error-pages.conf;

    # Allow OPTIONS for Wordpress
    allow_methods "^(GET|POST|HEAD|OPTIONS)\$";

    location / {

        # wordpress rewrite
        location /wp-json {
            rewrite ^/wp-json(.*)\$ /?rest_route=\$1;
        }

        try_files \$uri \$uri/ /index.php\$is_args\$args;

        # Deny all attempts to access hidden files such as .htaccess, .htpasswd, .DS_Store
        location ~ /\\. {
            deny all;
        }

        # Deny access to any files with a .php extension in the uploads directory
        # Works in sub-directory installs and also in multisite network
        # Must stay ABOVE the PHP handler: nested regex locations match in file
        # order, so placed after the .php location this deny never fired.
        location ~* /(?:uploads|files)/.*\\.php\$ {
            deny all;
        }

        location ~ \\.php\$ {
            try_files                   \$uri =404;
            fastcgi_split_path_info     ^(.+\\.php)(/.+)\$;
            fastcgi_intercept_errors    off;

            include         /etc/nginx/fastcgi_params;
            fastcgi_index   index.php;
            fastcgi_param   SCRIPT_FILENAME     \$document_root\$fastcgi_script_name;
            fastcgi_pass    ${upstream};
        }

        location ~* \\.(js|css|png|jpg|jpeg|gif|ico|woff2)\$ {
            expires max;
            log_not_found off;
        }
        location = /favicon.ico {
            log_not_found off;
            access_log off;
        }

        location = /robots.txt {
            allow all;
            log_not_found off;
            access_log off;
        }
    }
EOF
}

nginx_vhost() {   # kind domain docroot names upstream listen ssl cert key marker
    local kind="$1" domain="$2" docroot="$3" names="$4" upstream="$5" listen="$6"
    local ssl="$7" cert="$8" key="$9" marker="${10}" body

    [ -n "$marker" ] && echo "$marker"
    [[ "$listen" == /* ]] && listen="unix:${listen}"
    cat <<EOF
upstream ${upstream} {
    server   ${listen};
}

EOF
    if [ "$kind" = "main" ]; then body="$(nginx_main_body "$domain" "$docroot" "$upstream")"
    else                          body="$(nginx_addon_body "$domain" "$docroot" "$upstream")"
    fi

    if [ "$ssl" -eq 1 ]; then
        cat <<EOF
server {
	listen 80;
	access_log off;
	error_log off;
	server_name ${names};
	return 301 https://\$host\$request_uri;
}

server {
	listen 443 ssl;
	http2 on;

	server_name ${names};
	ssl_certificate ${cert};
	ssl_certificate_key ${key};

${body}
}
EOF
    else
        cat <<EOF
server {
	listen 80;
	server_name ${names};

${body}
}
EOF
    fi
}

# ============================================================ build configs
echo
echo "Building nginx vhosts and php-fpm pools in ${STAGE}"

# planned actions: "vhost|<staged>|<target>" "pool|<staged>|<target>"
PLAN=()
FPM_SERVICES=()
declare -A SEEN_POOL

for row in "${DOMAINS[@]}"; do
    IFS='|' read -r kind user domain has_email <<<"$row"
    if ! [[ "$user" =~ ^[a-z][a-z0-9]{1,15}$ ]] || ! [[ "$domain" =~ ^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$ ]]; then
        warn "skipping invalid account row '${user}' / '${domain}'"
        continue
    fi

    NAMES=() DOCROOT='' SSL=0 CERT='' KEY='' SOCKET='' PHPLINES=()
    if parsed="$(parse_apache_vhost "$domain")"; then
        while IFS=$'\t' read -r k a b c; do
            case "$k" in
                NAME)    a="${a,,}"; NAMES+=("${a%%:*}") ;;
                DOCROOT) [ -z "$DOCROOT" ] && DOCROOT="$a" ;;
                SSL)     SSL=1 ;;
                CERT)    CERT="$a" ;;
                KEY)     KEY="$a" ;;
                SOCKET)  SOCKET="$a" ;;
                PHP)     PHPLINES+=("${a}"$'\t'"${b}"$'\t'"${c}") ;;
            esac
        done <<<"$parsed"
    else
        warn "${domain}: no apache vhost in ${HTTPD_DIR}, using defaults"
        SSL=1
        NAMES=("$domain" "www.${domain}")
    fi

    if [ "$kind" = "main" ]; then
        [ -n "$DOCROOT" ] || DOCROOT="/home/${user}/public_html"
        UPSTREAM="php-fpm-${user}"
        DEF_SOCKET="/run/php-fpm-${user}.sock"
        # mail.<domain> follows has_email, like account_server_names() does
        case "${has_email,,}" in 1|true|on|yes) HAS_EMAIL=1 ;; *) HAS_EMAIL=0 ;; esac
    else
        [ -n "$DOCROOT" ] || DOCROOT="/home/${user}/${domain}"
        UPSTREAM="php-fpm-${user}-${domain}"
        DEF_SOCKET="/run/php-fpm-${user}-${domain}.sock"
        HAS_EMAIL=0
    fi

    # server_name: domain first, then everything apache answered for, no dups
    SERVER_NAMES="$domain"
    for n in "${NAMES[@]}"; do
        [ "$n" = "$domain" ] && continue
        [ "$kind" = "main" ] && [ "$n" = "mail.${domain}" ] && continue
        [[ " $SERVER_NAMES " == *" $n "* ]] || SERVER_NAMES+=" $n"
    done
    [ "$HAS_EMAIL" -eq 1 ] && SERVER_NAMES+=" mail.${domain}"

    # certificate: nginx wants the full chain in ssl_certificate
    if [ "$SSL" -eq 1 ]; then
        if [[ "$CERT" == /etc/letsencrypt/live/*/cert.pem ]]; then
            CERT="${CERT%/cert.pem}/fullchain.pem"
        fi
        if [ -z "$CERT" ] || [ -z "$KEY" ] || [ ! -f "$CERT" ] || [ ! -f "$KEY" ]; then
            if [ -f "/etc/letsencrypt/live/${domain}/fullchain.pem" ]; then
                CERT="/etc/letsencrypt/live/${domain}/fullchain.pem"
                KEY="/etc/letsencrypt/live/${domain}/privkey.pem"
            else
                if [ ! -f "/etc/ssl/certs/${domain}.crt" ] || [ ! -f "/etc/ssl/certs/${domain}.key" ]; then
                    if [ "$DRYRUN" -eq 0 ]; then
                        "${PATH_REQAD}/scripts/genselfsigned.sh" "$domain" >/dev/null 2>&1
                    else
                        warn "${domain}: no certificate, a self-signed one would be generated"
                    fi
                fi
                CERT="/etc/ssl/certs/${domain}.crt"
                KEY="/etc/ssl/certs/${domain}.key"
            fi
        fi
    fi

    # ---------------------------------------------------------- php-fpm pool
    POOL="$(find_pool "$domain")"
    if [ -n "$POOL" ]; then
        # already fpm under apache: keep version and socket, fix the group
        LISTEN="$(sed -nE 's/^[[:space:]]*listen[[:space:]]*=[[:space:]]*([^[:space:]]+).*/\1/p' "$POOL" | head -n1)"
        [ -n "$LISTEN" ] || LISTEN="$DEF_SOCKET"
        HANDLER="fpm $(pool_service "$POOL")"
        staged="${STAGE}${POOL}"
        mkdir -p "$(dirname "$staged")"
        sed -E -e 's/^([[:space:]]*group[[:space:]]*=[[:space:]]*)apache[[:space:]]*$/\1nginx/' \
               -e 's/^([[:space:]]*listen\.group[[:space:]]*=[[:space:]]*)apache[[:space:]]*$/\1nginx/' \
               "$POOL" > "$staged"
        if ! cmp -s "$POOL" "$staged"; then
            PLAN+=("pool|${staged}|${POOL}")
            FPM_SERVICES+=("$(pool_service "$POOL")")
        fi
    else
        # mod_php: new pool on the default PHP, carrying over the vhost php_* values
        POOL="/etc/php-fpm.d/${domain}.conf"
        LISTEN="${SOCKET:-$DEF_SOCKET}"
        HANDLER="mod_php -> fpm php-fpm"
        staged="${STAGE}${POOL}"
        mkdir -p "$(dirname "$staged")"
        pool_content "$domain" "$user" "$LISTEN" > "$staged"
        for pl in "${PHPLINES[@]}"; do
            IFS=$'\t' read -r directive key value <<<"$pl"
            # apache quotes are fine in the pool too, but keep the list style of the template
            [ "$key" = "disable_functions" ] && value="$(echo "$value" | sed -e 's/"//g' -e 's/[[:space:]]*,[[:space:]]*/, /g')"
            set_pool_value "$staged" "${directive}[${key}]" "$value"
        done
        PLAN+=("pool|${staged}|${POOL}")
        FPM_SERVICES+=("php-fpm")
    fi
    if [ -n "${SEEN_POOL[$LISTEN]:-}" ]; then
        warn "${domain}: socket ${LISTEN} is also used by ${SEEN_POOL[$LISTEN]}"
    fi
    SEEN_POOL[$LISTEN]="$domain"

    # ------------------------------------------------------------ nginx vhost
    TARGET="${NGINX_DIR}/${domain}.conf"
    MARKER=''
    if [ "$kind" = "addon" ]; then
        MARKER="$(head -n1 "${HTTPD_DIR}/${domain}.conf")"
    fi
    staged="${STAGE}${TARGET}"
    mkdir -p "$(dirname "$staged")"
    nginx_vhost "$kind" "$domain" "$DOCROOT" "$SERVER_NAMES" "$UPSTREAM" "$LISTEN" \
                "$SSL" "$CERT" "$KEY" "$MARKER" > "$staged"

    if [ -e "$TARGET" ] && [ "$FORCE" -eq 0 ]; then
        VHOST_NOTE="kept existing ${TARGET} (use --force to replace)"
    else
        PLAN+=("vhost|${staged}|${TARGET}")
        VHOST_NOTE="${TARGET}"
    fi

    # logs/ and tmp/ are referenced by the pool (error_log, sys_temp_dir)
    for d in "/home/${user}/logs" "/home/${user}/tmp"; do
        [ -d "$d" ] || PLAN+=("dir|${user}|${d}")
    done

    printf '  %-5s %-12s %s\n' "$kind" "$user" "$domain"
    printf '        names:   %s\n' "$SERVER_NAMES"
    printf '        root:    %s\n' "$DOCROOT"
    printf '        ssl:     %s\n' "$([ "$SSL" -eq 1 ] && echo "$CERT" || echo "none")"
    printf '        php:     %s (%s)\n' "$HANDLER" "$LISTEN"
    printf '        vhost:   %s\n' "$VHOST_NOTE"
done

# --------------------------------------------------- letsencrypt renewal plan
RENEWALS=()
for f in /etc/letsencrypt/renewal/*.conf; do
    [ -f "$f" ] || continue
    grep -qE '^[[:space:]]*(authenticator|installer)[[:space:]]*=[[:space:]]*apache[[:space:]]*$' "$f" || continue
    staged="${STAGE}${f}"
    mkdir -p "$(dirname "$staged")"
    sed -E -e 's/^([[:space:]]*authenticator[[:space:]]*=[[:space:]]*)apache[[:space:]]*$/\1nginx/' \
           -e 's/^([[:space:]]*installer[[:space:]]*=[[:space:]]*)apache[[:space:]]*$/\1nginx/' \
           "$f" > "$staged"
    RENEWALS+=("${staged}|${f}")
done
echo
echo "  letsencrypt renewals to switch to nginx: ${#RENEWALS[@]}"

# ------------------------------------------------------------ ini plan
staged_ini="${STAGE}${INI}"
mkdir -p "$(dirname "$staged_ini")"
awk '
    /^[ \t]*template[ \t]*=/ && !done { print "template=nginx_php-fpm"; done = 1; next }
    # services=...: httpd becomes nginx, without listing nginx twice
    /^[ \t]*services[ \t]*=/ {
        n = index($0, "="); out = ""; split("", seen)
        c = split(substr($0, n + 1), list, ",")
        for (i = 1; i <= c; i++) {
            s = list[i]; gsub(/^[ \t]+|[ \t]+$/, "", s)
            if (s == "httpd") s = "nginx"
            if (s == "" || (s in seen)) continue
            seen[s] = 1; out = out (out == "" ? "" : ", ") s
        }
        print substr($0, 1, n) out; next
    }
    { print }
' "$INI" > "$staged_ini"

echo
if [ ${#UNKNOWN_VHOSTS[@]} -gt 0 ]; then
    echo "  apache vhosts NOT converted (not a Reqad account or additional domain):"
    printf '    %s\n' "${UNKNOWN_VHOSTS[@]}"
    echo
fi

if [ "$DRYRUN" -eq 1 ]; then
    htaccess_report "${WORKDIR}/htaccess-report.txt"
    echo
    echo "Dry run: every file that would be written is under ${STAGE}"
    echo "Nothing on the server was changed."
    exit 0
fi

# ================================================================= confirm
if [ "$ASSUME_YES" -eq 0 ]; then
    echo "This will stop and disable httpd and serve every site with nginx + php-fpm."
    read -r -p "Continue? [y/N] " answer
    [[ "$answer" =~ ^[Yy] ]] || { echo "Aborted, nothing changed."; rm -rf "$WORKDIR"; exit 1; }
fi

# ================================================================== backup
echo
echo "Backing up to ${WORKDIR}/backup.tar.gz"
tar -czf "${WORKDIR}/backup.tar.gz" --ignore-failed-read \
    "$HTTPD_DIR" /etc/nginx /etc/php-fpm.d /etc/opt/remi/*/php-fpm.d /etc/letsencrypt/renewal "$INI" 2>/dev/null
chmod 0600 "${WORKDIR}/backup.tar.gz"

# =================================================================== nginx
#
# nginx must come from the reqad repo, not the distro one. The repo side is the
# same as scripts/install/install-nginx-php.sh: the nginx module is disabled
# (EL8) and nginx is excluded from whatever repo id contains "appstream".
#
# A distro nginx is usually already installed — the reqad package requires
# nginx, and on an apache server nothing forced the reqad build. It cannot be
# replaced through dnf: "dnf remove nginx" takes the reqad package with it, and
# where the appstream build has the higher epoch dnf will not "upgrade" to ours.
# So the reqad rpm is downloaded first (nothing is touched if that fails), then
# only the distro nginx packages are removed with rpm --nodeps — which leaves
# the reqad package alone — and the downloaded rpm is installed.

install_nginx() {
    local el repo_rpm asrepo rpmfile old=() log="${WORKDIR}/dnf.log" rpmdir="${WORKDIR}/rpms"
    el="$(rpm -E %rhel)"
    if [ "$el" = "9" ]; then
        repo_rpm="https://repo.reqad.net/el9/RPMS/noarch/reqad-repo-1.0.1-1.el9.noarch.rpm"
    else
        repo_rpm="https://repo.reqad.net/el8/RPMS/x86_64/reqad-repo-1.0.0-1.el8.noarch.rpm"
    fi
    {
        [ -f /etc/yum.repos.d/reqad.repo ] || dnf install -y "$repo_rpm"
        rpm -q dnf-plugins-core >/dev/null 2>&1 || dnf install -y dnf-plugins-core
        dnf config-manager --set-enabled reqad
        dnf -y module reset nginx
        dnf -y module disable nginx
        asrepo="$(dnf -q repolist --all 2>/dev/null | awk 'tolower($1) ~ /appstream/ { print $1; exit }')"
        [ -n "$asrepo" ] && dnf config-manager --save --setopt="${asrepo}.exclude=nginx*"
        mkdir -p "$rpmdir"
        dnf download --disablerepo='*' --enablerepo=reqad --arch="$(uname -m)" --destdir="$rpmdir" nginx
    } >> "$log" 2>&1

    rpmfile="$(ls -1 "$rpmdir"/nginx-[0-9]*.rpm 2>/dev/null | grep -v -- '-debuginfo' | sort -V | tail -n1)"
    if [ -z "$rpmfile" ]; then
        echo "  could not download nginx from the reqad repo" >&2
        return 1
    fi
    echo "  downloaded $(basename "$rpmfile")"

    # the distro nginx and its dynamic modules (they are built against its ABI);
    # nginx-filesystem stays, other packages may own files through it
    mapfile -t old < <(rpm -qa --qf '%{NAME}\n' 'nginx' 'nginx-mod-*' 'nginx-all-modules' | sort -u)
    if [ ${#old[@]} -gt 0 ]; then
        systemctl stop nginx >/dev/null 2>&1
        echo "  removing (rpm --nodeps, reqad stays installed): ${old[*]}"
        rpm -e --nodeps "${old[@]}" >> "$log" 2>&1 || { echo "  rpm -e failed" >&2; return 1; }
    fi

    dnf -y install "$rpmfile" >> "$log" 2>&1
    command -v nginx >/dev/null 2>&1 && nginx -V 2>&1 | grep -q brotli
}

if [ "$NEED_NGINX" -eq 1 ]; then
    echo "Installing nginx from the reqad repo"
    if ! install_nginx; then
        if command -v nginx >/dev/null 2>&1; then
            die "nginx could not be installed from the reqad repo, see ${WORKDIR}/dnf.log. Nothing else was changed."
        fi
        die "the distro nginx was removed but the reqad build did not install, see ${WORKDIR}/dnf.log.
       Install it by hand with: dnf install ${WORKDIR}/rpms/nginx-*.rpm — httpd is still serving, nothing else was changed."
    fi
    sed -i 's/\/var\/log\/nginx\/\*\.log/\/var\/log\/nginx\/\*log/' /etc/logrotate.d/nginx 2>/dev/null
    echo "  installed $(rpm -q nginx)"
fi

if [ "$NEED_NGINX_CONF" -eq 1 ]; then
    install_file "${PATH_REQAD}/scripts/install/config/nginx.txt" /etc/nginx/nginx.conf 0644
    echo "  wrote /etc/nginx/nginx.conf"
    # the package's default server would claim port 80 before the vhosts
    : > "${STAGE}/empty.conf"
    for f in "$NGINX_DIR"/default*.conf; do
        [ -s "$f" ] || continue
        install_file "${STAGE}/empty.conf" "$f" 0644
        echo "  emptied $f"
    done
fi

if [ "$NEED_AUTOCONFIG" -eq 1 ]; then
    "${PATH_REQAD}/scripts/update/setup_autoconfig.sh" 2>&1 | sed 's/^ */  /'
    [ -f "$AUTOCONFIG_SNIPPET" ] || die "setup_autoconfig.sh did not create ${AUTOCONFIG_SNIPPET}. No vhost was written yet."
fi

# shared error pages every vhost includes (no server name in nginx's pages)
if [ ! -f /etc/nginx/reqad-error-pages.conf ]; then
    bash "${PATH_REQAD}/scripts/update/setup_error_pages.sh" 2>&1 | sed 's/^ */  /'
    [ -f /etc/nginx/reqad-error-pages.conf ] || die "setup_error_pages.sh did not create /etc/nginx/reqad-error-pages.conf. No vhost was written yet."
fi

# ================================================================= install
echo "Installing configs"
for p in "${PLAN[@]}"; do
    IFS='|' read -r what a b <<<"$p"
    case "$what" in
        vhost|pool) install_file "$a" "$b" 0644; echo "  wrote $b" ;;
        dir)        if [ ! -d "$b" ]; then
                        mkdir -p "$b" && chown "${a}:${a}" "$b" && chmod 0750 "$b"
                        echo "  created $b"
                    fi ;;
    esac
done

# the server hostname vhost (panel default site)
if [ ! -f "${NGINX_DIR}/$(hostname).conf" ] && [ -x "${PATH_REQAD}/scripts/update/update_default_nginx_host.sh" ]; then
    echo "C ${NGINX_DIR}/$(hostname).conf" >> "$CHANGELOG"
    "${PATH_REQAD}/scripts/update/update_default_nginx_host.sh" 2>&1 | grep -v -i 'reload\|not active' | sed 's/^ */  /'
fi

# ------------------------------------------------------------ config tests
echo "Testing configuration"
if ! nginx -t >/dev/null 2>&1; then
    echo "Error: nginx configuration test failed:" >&2
    nginx -t 2>&1 | sed 's/^/    /' >&2
    rollback
    exit 1
fi
echo "  nginx -t ok"

FPM_SERVICES+=("php-fpm")
mapfile -t FPM_SERVICES < <(printf '%s\n' "${FPM_SERVICES[@]}" | sort -u)
for svc in "${FPM_SERVICES[@]}"; do
    if [ "$svc" = "php-fpm" ]; then bin=/usr/sbin/php-fpm
    else                            bin="/opt/remi/${svc%-php-fpm}/root/usr/sbin/php-fpm"
    fi
    [ -x "$bin" ] || continue
    if ! "$bin" -t >/dev/null 2>&1; then
        echo "Error: ${svc} configuration test failed:" >&2
        "$bin" -t 2>&1 | sed 's/^/    /' >&2
        rollback
        exit 1
    fi
    echo "  ${svc} -t ok"
done

# ================================================================= switch
echo "Switching web server"
systemctl disable --now httpd >/dev/null 2>&1
echo "  httpd stopped and disabled"

switch_failed() {
    echo "Error: $*" >&2
    rollback
    for svc in "${FPM_SERVICES[@]}"; do systemctl restart "${svc}.service" >/dev/null 2>&1; done
    systemctl stop nginx >/dev/null 2>&1
    systemctl enable --now httpd >/dev/null 2>&1 && echo "httpd started again." >&2
    exit 1
}

for svc in "${FPM_SERVICES[@]}"; do
    systemctl enable "${svc}.service" >/dev/null 2>&1
    systemctl restart "${svc}.service" || switch_failed "failed to restart ${svc}"
    echo "  ${svc} restarted"
done

systemctl enable nginx >/dev/null 2>&1
systemctl restart nginx || switch_failed "nginx failed to start (journalctl -u nginx)"
echo "  nginx enabled and started"

# ======================================================= reqad + certbot
INI_OWNER="$(stat -c '%U:%G' "$INI")"
install_file "$staged_ini" "$INI" 0644
chown "$INI_OWNER" "$INI"
echo "  ${INI}: template=nginx_php-fpm"

if [ "${NEED_CERTBOT_NGINX:-0}" -eq 1 ]; then
    if dnf -y install python3-certbot-nginx >/dev/null 2>&1; then
        echo "  installed python3-certbot-nginx"
    else
        warn "could not install python3-certbot-nginx — certificate renewals will fail until it is installed"
    fi
fi

for r in "${RENEWALS[@]}"; do
    IFS='|' read -r staged target <<<"$r"
    install_file "$staged" "$target" 0644
    echo "  $(basename "$target"): authenticator/installer -> nginx"
done

# ================================================================= report
echo
htaccess_report "$REPORT"

echo
echo "Done. httpd is disabled, the apache vhosts are still in ${HTTPD_DIR}."
echo "Backup and the list of changed files: ${WORKDIR}"
echo "Check the renewals with: certbot renew --dry-run"
if [ ${#WARNINGS[@]} -gt 0 ]; then
    echo
    echo "Warnings:"
    printf '  - %s\n' "${WARNINGS[@]}"
fi
