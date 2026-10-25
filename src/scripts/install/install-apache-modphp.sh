#!/bin/bash

VERSION='0.0.3 - Jul 28, 2026'

# Default php version
if [ "$PHP_VERSION" == "" ]
    then PHP_VERSION='8.3'
fi

# Which Apache template (install-apache-phpfpm.sh sets apache_phpfpm):
#   apache_modphp  prefork + mod_php + mod_ruid2; php-fpm per account optional
#   apache_phpfpm  event MPM, no mod_php: every site's PHP in its own php-fpm pool
APACHE_TEMPLATE="${APACHE_TEMPLATE:-apache_modphp}"
case "$APACHE_TEMPLATE" in
    apache_modphp|apache_phpfpm) ;;
    *) echo "Unknown APACHE_TEMPLATE '${APACHE_TEMPLATE}' (apache_modphp | apache_phpfpm)" 1>&2; exit 1 ;;
esac
# the package that carries the PHP version: mod_php is the php package
PHP_PKG=php; [ "$APACHE_TEMPLATE" == "apache_phpfpm" ] && PHP_PKG=php-fpm

WHITE='\033[1;37m'
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BLUE='\033[0;33m'
NC='\033[0m' # No Color

echo -ne "${YELLOW}"
echo -e "┌──────────────────────────────────────────────────────────────────────────────┐"
echo "│ Reqad install script for apache / php           version ${VERSION} │"
echo -e "└──────────────────────────────────────────────────────────────────────────────┘\n"
echo -ne "${NC}"

if [ "$EUID" -ne 0 ]
    then echo "Please run as root"  1>&2
    exit 1
fi



#######################################################################################
#
#  apache with mod_php

MODDIR='/etc/httpd/conf.modules.d'

# Comment out every LoadModule in a conf.modules.d file. Files differ per EL
# release (el9 has no 00-lua.conf / 10-h2.conf / 10-proxy_h2.conf), so skip
# what is not there.
httpd_modules_off() {
	local file="${MODDIR}/$1"
	[ -f "${file}" ] || return 0
	sed -i -E 's/^[[:space:]]*LoadModule[[:space:]]/#&/' "${file}"
}

# Comment (off) or uncomment (on) a single module, whatever state it is in now.
httpd_module() {
	local state="$1" file="${MODDIR}/$2" module="$3"
	[ -f "${file}" ] || return 0
	if [ "${state}" == "on" ]; then
		sed -i -E "s|^[[:space:]]*#+[[:space:]]*(LoadModule[[:space:]]+${module}[[:space:]])|\1|" "${file}"
	else
		sed -i -E "s|^[[:space:]]*(LoadModule[[:space:]]+${module}[[:space:]])|#\1|" "${file}"
	fi
}

if ! systemctl is-active --quiet httpd; then
    if [ "$(systemctl list-unit-files "httpd.service" | grep "httpd.service" | awk {'print $2'})" == "enabled" ]; then
        (systemctl start httpd) >> ./install_reqad.log 2>&1
    fi
fi

if ! systemctl is-active --quiet httpd; then
    echo -n "Installing httpd                    "

    (
		if [ "$APACHE_TEMPLATE" == "apache_modphp" ]; then
			dnf -y install httpd mod_ssl mod_ruid2 python3-certbot-apache
		else
			dnf -y install httpd mod_ssl python3-certbot-apache
		fi

        if [ ! -f "/etc/letsencrypt/options-ssl-apache.conf" ]; then
            cat /usr/local/reqad/scripts/install/config/options-ssl-apache.txt > /etc/letsencrypt/options-ssl-apache.conf
        fi

		if [ "$(grep 'SetHandler server-status' /etc/httpd/conf/httpd.conf | awk {'print $2'})" != "server-status" ]; then
			sed -i '/EnableSendfile on/a \\n<Location "\/server-status">\n\tSetHandler server-status\n\tRequire ip 127.0.0.1 ::1\n<\/Location>' /etc/httpd/conf/httpd.conf
		fi

		# remove default 443 virtual host
		sed -i '/## SSL Virtual Host Context/,$ d' /etc/httpd/conf.d/ssl.conf
	) >> ./install_reqad.log 2>&1
    (systemctl enable httpd --now) >> ./install_reqad.log 2>&1

    if systemctl is-active --quiet httpd; then
        echo -ne "[ ${GREEN}OK${NC} ]"
    else
        echo -e "[ ${RED}ERROR${NC} ]"
        systemctl status httpd
        exit
    fi
else
    echo -ne "[${GREEN} RUNNING ${NC}] "
    echo -n 'httpd '
    echo -n `rpm -qi httpd | grep 'Version' | awk {'print $3'}`
fi
echo


#######################################################################################
#
#  httpd modules
#
#  Runs on every invocation, not only on a fresh httpd install: a re-run (or a
#  first run that died halfway) must still end up with the same module set.
#  Every edit below is idempotent.

echo -n "Configuring httpd modules           "
MODSUM_BEFORE=$(cat ${MODDIR}/*.conf 2>/dev/null | md5sum)
(
	if [ "$APACHE_TEMPLATE" == "apache_modphp" ]; then
		# mpm_prefork — required by mod_php (and mod_ruid2)
		httpd_module on  00-mpm.conf mpm_prefork_module
		httpd_module off 00-mpm.conf mpm_event_module
	else
		# mpm_event — PHP runs in php-fpm, so apache can use threads
		httpd_module on  00-mpm.conf mpm_event_module
		httpd_module off 00-mpm.conf mpm_prefork_module
	fi
	httpd_module off 00-mpm.conf mpm_worker_module

	# disable what we don't serve with
	httpd_module off 00-base.conf cache_module
	httpd_module off 00-base.conf cache_disk_module
	httpd_module off 00-base.conf cache_socache_module
	httpd_module off 00-base.conf suexec_module
	httpd_module off 00-base.conf watchdog_module
	httpd_modules_off 00-dav.conf
	httpd_modules_off 00-lua.conf
	httpd_modules_off 00-proxy.conf
	httpd_modules_off 01-cgi.conf
	httpd_modules_off 10-h2.conf
	httpd_modules_off 10-proxy_h2.conf

	# ...then re-enable the two proxy modules we do need: accounts running a
	# non-default php version (or the default one with the fpm handler) are
	# served over SetHandler proxy:unix:/run/php-fpm-<user>.sock
	httpd_module on 00-proxy.conf proxy_module
	httpd_module on 00-proxy.conf proxy_fcgi_module
) >> ./install_reqad.log 2>&1

if [ "$(cat ${MODDIR}/*.conf 2>/dev/null | md5sum)" != "${MODSUM_BEFORE}" ]; then
	(systemctl is-active --quiet httpd && systemctl restart httpd) >> ./install_reqad.log 2>&1
fi

# index.php before index.html / index.htm (the stock order is the other way
# round; the rpm's post-install ran before httpd was here)
bash /usr/local/reqad/scripts/update/setup_directory_index.sh >> ./install_reqad.log 2>&1

if grep -qE '^[[:space:]]*LoadModule[[:space:]]+proxy_module' ${MODDIR}/00-proxy.conf 2>/dev/null \
	&& grep -qE '^[[:space:]]*LoadModule[[:space:]]+proxy_fcgi_module' ${MODDIR}/00-proxy.conf 2>/dev/null; then
	echo -e "[ ${GREEN}OK${NC} ]"
else
	echo -e "[ ${RED}ERROR${NC} ]"
	echo "mod_proxy / mod_proxy_fcgi could not be enabled in ${MODDIR}/00-proxy.conf"
	exit
fi



#######################################################################################
#
#  php (mod_php)

EXISTING_PHP=$(rpm -qi ${PHP_PKG} | grep 'Version' | awk {'print $3'} | awk -F. {'print $1"."$2'})
if [ "${EXISTING_PHP}" != "${PHP_VERSION}" ]; then
echo -n "Installing php                      "
(
    # remi-release must match the EL release — the reqad rpm pulls it in already,
    # only install it if it is somehow missing
    if ! rpm -q remi-release > /dev/null 2>&1; then
        OS_VER=$(grep -oP '(?<=^VERSION_ID=")\d+' /etc/os-release 2>/dev/null \
            || grep -oP '\b[89]\b' /etc/redhat-release | head -1)
        dnf install -y https://rpms.remirepo.net/enterprise/remi-release-${OS_VER}.rpm
    fi
    yum-config-manager --enable remi
    dnf -y module reset php
    dnf -y module enable php:remi-${PHP_VERSION}
    # php-fpm is not a dependency of mod_php, but accounts can be switched to the
    # fpm handler on the default php version (pool in /etc/php-fpm.d/) — install it
    # mod_php is the php package; apache_phpfpm leaves it out
    PHP_MOD=php; [ "$APACHE_TEMPLATE" == "apache_phpfpm" ] && PHP_MOD=
    dnf install -y ${PHP_MOD} php-fpm php-xml php-pdo php-mysqlnd php-mysql php-mbstring php-pear php-gd php-mcrypt php-intl php-process \
    php-soap php-pecl-redis php-pecl-igbinary php-common php-json php-cli php-bcmath  php-pecl-zip php-opcache php-sodium php-pecl-apcu

    sed -i 's/;date.timezone =/date.timezone = Europe\/Bucharest/' /etc/php.ini
    sed -i 's/memory_limit = 128M/memory_limit = 2048M/' /etc/php.ini
    sed -i 's/post_max_size = 8M/post_max_size = 64M/' /etc/php.ini
    sed -i 's/upload_max_filesize = 2M/upload_max_filesize = 64M/' /etc/php.ini
    sed -i 's/; max_input_vars = 1000/max_input_vars = 5000/' /etc/php.ini
    sed -i 's/max_execution_time = 30/max_execution_time = 3600/' /etc/php.ini
    sed -i 's/error_reporting = E_ALL & ~E_DEPRECATED & ~E_STRICT/error_reporting = E_ALL \& ~E_NOTICE \& ~E_DEPRECATED \& ~E_STRICT/' /etc/php.ini
    sed -i 's/short_open_tag = Off/short_open_tag = On/' /etc/php.ini
    sed -i 's/expose_php = On/expose_php = Off/' /etc/php.ini
    sed -i 's/;mail.force_extra_parameters =.*/mail.force_extra_parameters = 1/' /etc/php.ini

    # apache_phpfpm: the stock conf.d/php.conf hands .php outside the account
    # vhosts (the server's default host) to /run/php-fpm/www.sock, so apache
    # must reach it; it still runs as nobody, which cannot read the docroots
    WWW_LISTEN=/run/php-fpm.sock; WWW_GROUP=nobody
    [ "$APACHE_TEMPLATE" == "apache_phpfpm" ] && { WWW_LISTEN=/run/php-fpm/www.sock; WWW_GROUP=apache; }
    echo "[www]
user = nobody
group = nobody
listen = ${WWW_LISTEN}
listen.allowed_clients = 127.0.0.1
listen.owner = nobody
listen.group = ${WWW_GROUP}
listen.mode = 0660

pm = ondemand
pm.max_children = 50
pm.start_servers = 1
pm.min_spare_servers = 1
pm.max_spare_servers = 1
" > /etc/php-fpm.d/www.conf

    sed -i 's/;opcache.save_comments=1/opcache.save_comments=1/' /etc/php.d/10-opcache.ini
    sed -i 's/opcache.interned_strings_buffer=8/opcache.interned_strings_buffer=32/' /etc/php.d/10-opcache.ini
    sed -i 's/opcache.memory_consumption=128/opcache.memory_consumption=1024/' /etc/php.d/10-opcache.ini
    sed -i 's/opcache.max_accelerated_files=4000/opcache.max_accelerated_files=1000000/' /etc/php.d/10-opcache.ini

    chmod 1733 /var/lib/php/session
	dnf erase -y php-pecl-mysql
	systemctl restart httpd
) >> ./install_reqad.log 2>&1


# wait for apache to reload, otherwise status = reloading
sleep 3

EXISTING_PHP=$(rpm -qi ${PHP_PKG} | grep 'Version' | awk {'print $3'} | awk -F. {'print $1"."$2'})

if [ "${EXISTING_PHP}" == "${PHP_VERSION}" ] && [ "$(systemctl is-active httpd)" == "active" ]; then
    echo -ne "[ ${GREEN}OK${NC} ]"
else
    echo -e "[ ${RED}ERROR${NC} ]"
    systemctl status httpd
    exit
fi
else
    echo -ne "[${GREEN} RUNNING ${NC}] "
    echo -n 'php '
    echo -n `rpm -qi ${PHP_PKG} | grep 'Version' | awk {'print $3'}`
fi
echo


#######################################################################################
#
#  php-fpm
#
#  mod_php serves the default php version, but an account can be switched to the
#  fpm handler (pool in /etc/php-fpm.d/, socket /run/php-fpm-<user>.sock), so the
#  default php-fpm master has to be enabled and running — it is also listed in
#  services= in server-software.ini and shows up on the Services page.

echo -n "Enabling php-fpm                    "
if ! systemctl is-active --quiet php-fpm; then
    (systemctl enable php-fpm --now) >> ./install_reqad.log 2>&1
fi

if systemctl is-active --quiet php-fpm; then
    echo -e "[ ${GREEN}OK${NC} ]"
else
    echo -e "[ ${RED}ERROR${NC} ]"
    systemctl status php-fpm
    exit
fi


TEMPLATE=$(grep -e '^template=' /usr/local/reqad/etc/server-software.ini | awk -F= {'print $2'})
if [ "${TEMPLATE}" != "${APACHE_TEMPLATE}" ]; then
    echo -n "Updating template and services in Reqad config   ";
    (
        sed -i "/^template=/d" /usr/local/reqad/etc/server-software.ini
        sed -i "/\[reqad\]/a\template=${APACHE_TEMPLATE}" /usr/local/reqad/etc/server-software.ini
		sed -i -E 's/^services=(.*)nginx, (.*)/services=\1httpd, \2/' /usr/local/reqad/etc/server-software.ini
    ) >> ./install_reqad.log 2>&1
    echo -e "[ ${GREEN}OK${NC} ]"
fi
