#!/bin/bash

VERSION='0.0.6 - Jul 17, 2026'

# Default php version
if [ "$PHP_VERSION" == "" ]
    then PHP_VERSION='8.3'
fi


# Detect EL major version (8 or 9) to pick the right repos / packages
EL=$(rpm -E %rhel)
if [ "$EL" = "9" ]; then
    REQAD_REPO_RPM="https://repo.reqad.net/el9/RPMS/noarch/reqad-repo-1.0.1-1.el9.noarch.rpm"
    REMI_RPM="https://rpms.remirepo.net/enterprise/remi-release-9.rpm"
else
    REQAD_REPO_RPM="https://repo.reqad.net/el8/RPMS/x86_64/reqad-repo-1.0.0-1.el8.noarch.rpm"
    REMI_RPM="https://rpms.remirepo.net/enterprise/remi-release-8.rpm"
fi

WHITE='\033[1;37m'
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BLUE='\033[0;33m'
NC='\033[0m' # No Color

echo -ne "${YELLOW}"
echo -e "┌──────────────────────────────────────────────────────────────────────────────┐"
echo "│ Reqad install script for nginx / php            version ${VERSION} │"
echo -e "└──────────────────────────────────────────────────────────────────────────────┘\n"
echo -ne "${NC}"

if [ "$EUID" -ne 0 ]
    then echo "Please run as root"  1>&2
    exit 1
fi

HOSTNAME=`hostnamectl status | grep 'Static hostname:' | awk {'print $3'}`

#######################################################################################
#
#  nginx

if ! systemctl is-active --quiet nginx; then
    if [ "$(systemctl list-unit-files "nginx.service" | grep "nginx.service" | awk {'print $2'})" == "enabled" ]; then
        (systemctl start nginx) >> ./install_reqad.log 2>&1
    fi
fi

if ! systemctl is-active --quiet nginx; then
    echo -n "Installing nginx                    "

    if [ ! -f "/etc/yum.repos.d/reqad.repo" ]; then
		(dnf install -y ${REQAD_REPO_RPM}) >> ./install_reqad.log 2>&1
	fi
	(
    	yum-config-manager --enable reqad
    	# Force nginx to resolve from the reqad repo, not the distro appstream.
    	# Distro-agnostic across all EL8/EL9 (Rocky, Alma, RHEL, Oracle, CentOS Stream):
    	# disable the nginx module (EL8) and exclude nginx from whatever repo id
    	# contains "appstream" (EL9 non-modular) rather than editing a named repo file.
    	dnf -y module reset nginx
    	dnf -y module disable nginx
    	ASREPO=$(dnf -q repolist --all 2>/dev/null | awk 'tolower($1) ~ /appstream/{print $1; exit}')
    	[ -n "${ASREPO}" ] && dnf config-manager --save --setopt="${ASREPO}.exclude=nginx*"
    	dnf -y install nginx
		sed -i 's/\/var\/log\/nginx\/\*\.log/\/var\/log\/nginx\/\*log/' /etc/logrotate.d/nginx
	    truncate -s 0 /etc/nginx/conf.d/default*
	    curl -s https://ssl-config.mozilla.org/ffdhe2048.txt > /etc/nginx/dhparams.pem
	    curl -s https://repo.reqad.net/nginx.txt > /etc/nginx/nginx.conf
	    #curl -s https://repo.reqad.net/nginx-vhost.txt > /etc/nginx/conf.d/domain.dom.conf.template
	    #curl -s https://repo.reqad.net/nginx-fastcgi_params.txt > /etc/nginx/fastcgi_params
	    curl -s https://repo.reqad.net/nginx-hostname.txt > /etc/nginx/conf.d/${HOSTNAME}.conf
		sed -i "s/%HOSTNAME%/${HOSTNAME}/" /etc/nginx/conf.d/${HOSTNAME}.conf
	) >> ./install_reqad.log 2>&1
    (systemctl enable nginx --now) >> ./install_reqad.log 2>&1

    if systemctl is-active --quiet nginx; then
        echo -ne "[ ${GREEN}OK${NC} ]"
    else
        echo -e "[ ${RED}ERROR${NC} ]"
        systemctl status nginx
        exit
    fi
else
	if [ ! "$(grep 'brotli on' /etc/nginx/nginx.conf)" ]; then
		echo "Fixing nginx config";
    	(
			sed -i 's/\/var\/log\/nginx\/\*\.log/\/var\/log\/nginx\/\*log/' /etc/logrotate.d/nginx
	    	truncate -s 0 /etc/nginx/conf.d/default*
		    curl -s https://ssl-config.mozilla.org/ffdhe2048.txt > /etc/nginx/dhparams.pem
		    curl -s https://repo.reqad.net/nginx.txt > /etc/nginx/nginx.conf
		    curl -s https://repo.reqad.net/nginx-hostname.txt > /etc/nginx/conf.d/${HOSTNAME}.conf
			sed -i "s/%HOSTNAME%/${HOSTNAME}/" /etc/nginx/conf.d/${HOSTNAME}.conf
			nginx -t
		) >> ./install_reqad.log 2>&1
		echo "Restarting nginx";
    	(systemctl restart nginx) >> ./install_reqad.log 2>&1
	fi
    echo -ne "[${GREEN} RUNNING ${NC}] "
    echo -n 'nginx '
    echo -n `rpm -qi nginx | grep 'Version' | awk {'print $3'}`
fi
echo



#######################################################################################
#
#  php-fpm

if ! systemctl is-active --quiet php-fpm; then
    if [ "$(systemctl list-unit-files "php-fpm.service" | grep "php-fpm.service" | awk {'print $2'})" == "enabled" ]; then
        (systemctl start php-fpm) >> ./install_reqad.log 2>&1
    fi
fi
if ! systemctl is-active --quiet php-fpm; then
echo -n "Installing php                      "
(
    dnf install -y ${REMI_RPM}
    yum-config-manager --enable remi
    dnf -y module reset php
    dnf -y module enable php:remi-${PHP_VERSION}
    dnf install -y php-fpm php-xml php-pdo php-mysqlnd php-mysql php-mbstring php-pear php-gd php-mcrypt php-intl php-process \
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

    echo "[www]
user = nginx
group = nginx

listen = /run/php-fpm.sock
listen.allowed_clients = 127.0.0.1
listen.owner = nginx
listen.group = nginx
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

    chmod -R a+rwx /var/lib/php/session/
    systemctl enable php-fpm --now
) >> ./install_reqad.log 2>&1

if systemctl is-active --quiet php-fpm; then
    echo -ne "[ ${GREEN}OK${NC} ]"
else
    echo -e "[ ${RED}ERROR${NC} ]"
    systemctl status php-fpm
    exit
fi
else
    echo -ne "[${GREEN} RUNNING ${NC}] "
    echo -n 'php '
    echo -n `rpm -qi php-fpm | grep 'Version' | awk {'print $3'}`
fi
echo


TEMPLATE=$(grep -e '^template=' /usr/local/reqad/etc/server-software.ini | awk -F= {'print $2'})
if [ "${TEMPLATE}" != "nginx_php-fpm" ]; then
    echo -n "Updating template in Reqad config   ";
    (
        sed -i "/^template=/d" /usr/local/reqad/etc/server-software.ini
        sed -i '/\[reqad\]/a\template=nginx_php-fpm' /usr/local/reqad/etc/server-software.ini
    ) >> ./install_reqad.log 2>&1
    echo -e "[ ${GREEN}OK${NC} ]"
fi
