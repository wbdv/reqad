# web_caps.sh - what the configured web server template means, for shell scripts.
# Source it, then call web_caps [server-software.ini]. It sets:
#   TEMPLATE     the template name from etc/server-software.ini
#   WEB_FRONT    nginx | httpd   the server on :80/:443 (vhosts, certificates, SNI)
#   WEB_BACKEND  none  | httpd   a second server behind the front, on 127.0.0.1 only
#   PHP_MODE     fpm   | modphp  modphp: mod_php is the default PHP handler
#   WEB_PHP      nginx | httpd   the server whose vhost holds the PHP handler
# Same rules as web_caps() in public_html/modules/functions.php: the template's
# scripts/templates/<template>.ini, else the name decides (apache_* = httpd +
# mod_php, nginx_* = nginx + php-fpm). An unknown name leaves WEB_FRONT empty,
# so callers can refuse it.
#
# Per server (nginx | httpd): web_vhost_dir, web_user (the server's Unix user).
# Docroots and pool sockets belong to the group of the FRONT server's user: it
# serves the static files, and a backend (Apache behind nginx) is a member.

WEB_CAPS_ROOT="${WEB_CAPS_ROOT:-/usr/local/reqad}"

_web_cap() {
    sed -n "s/^[[:space:]]*$2[[:space:]]*=[[:space:]]*//p" "$1" 2>/dev/null | head -n1 | tr -d '[:space:]'
}

web_caps() {
    local ini="${1:-$WEB_CAPS_ROOT/etc/server-software.ini}" f v
    TEMPLATE=$(_web_cap "$ini" template)
    case "$TEMPLATE" in
        apache_*) WEB_FRONT=httpd; WEB_BACKEND=none; PHP_MODE=modphp ;;
        nginx_*)  WEB_FRONT=nginx; WEB_BACKEND=none; PHP_MODE=fpm ;;
        *)        WEB_FRONT='';    WEB_BACKEND=none; PHP_MODE=fpm ;;
    esac
    f="$WEB_CAPS_ROOT/scripts/templates/${TEMPLATE##*/}.ini"
    if [ -n "$TEMPLATE" ] && [ -r "$f" ]; then
        v=$(_web_cap "$f" front);   case "$v" in nginx|httpd) WEB_FRONT=$v ;; esac
        v=$(_web_cap "$f" backend); case "$v" in none|httpd)  WEB_BACKEND=$v ;; esac
        v=$(_web_cap "$f" php);     case "$v" in fpm|modphp)  PHP_MODE=$v ;; esac
    fi
    WEB_PHP=$WEB_FRONT
    [ "$WEB_BACKEND" != none ] && WEB_PHP=$WEB_BACKEND
    return 0
}

web_vhost_dir() { if [ "$1" = httpd ]; then echo /etc/httpd/conf.d; else echo /etc/nginx/conf.d; fi; }
web_user()      { if [ "$1" = httpd ]; then echo apache; else echo nginx; fi; }
