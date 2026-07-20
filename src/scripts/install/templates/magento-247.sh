#!/bin/bash

VERSION='0.0.1 - Aug 13, 2024'

WHITE='\033[1;37m'
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BLUE='\033[0;33m'
NC='\033[0m' # No Color

echo -ne "${YELLOW}"
echo -e "┌──────────────────────────────────────────────────────────────────────────────┐"
echo "│ Magento 2.4.7 install - script version ${VERSION}                  │"
echo -e "└──────────────────────────────────────────────────────────────────────────────┘"
echo -ne "${NC}"

if [ "$EUID" -ne 0 ]
    then echo "Please run as root"  1>&2
    exit 1
fi

echo " - Nginx    (1.2x)
 - PHP      (8.2)
 - MariaDB  (10.6)
 - Varnish  (7.5)
 - Composer (2.7)
 - Redis    (7.2)
 - OpenSearch (2.12)
 - RabbitMQ (3.13)
"

# [server_php]
# nginx
# php-8.2
# #php-8.3
# mariadb-10.6-client
# #redis-7.2
# #rabbitmq-3.13
# 
# [server_db]
# mariadb-10.6-server
#
# [server_varnish]
# varnish-7.5
# opensearch-2.12
# #opensearch-8.11


#######################################################################################
#
#  nginx

if ! systemctl is-active --quiet nginx; then
    if [ "$(systemctl list-unit-files "nginx.service" | grep "nginx.service" | awk {'print $2'})" == "enabled" ]; then
        (systemctl start nginx) >> ./install_reqad.log 2>&1
    fi
fi

if ! systemctl is-active --quiet nginx; then
	echo -n "Installing Nginx                    "

	if [ ! -f "/etc/yum.repos.d/reqad.repo" ]; then
		echo '[reqad]
name=Reqad EL$releasever - $basearch
baseurl=https://repo.reqad.net/el$releasever/RPMS/$basearch
enabled=1
gpgcheck=0' > /etc/yum.repos.d/reqad.repo
	fi
	(yum-config-manager --enable reqad) >> ./install_reqad.log 2>&1
	(dnf -y module disable nginx) >> ./install_reqad.log 2>&1
	(dnf -y install nginx) >> ./install_reqad.log 2>&1
	sed -i 's/\/var\/log\/nginx\/\*\.log/\/var\/log\/nginx\/\*log/' /etc/logrotate.d/nginx
	truncate -s 0 /etc/nginx/conf.d/default*
	curl -s https://ssl-config.mozilla.org/ffdhe2048.txt > /etc/nginx/dhparams.pem
	curl -s https://repo.reqad.net/nginx.txt > /etc/nginx/nginx.conf
	curl -s https://repo.reqad.net/nginx-vhost.txt > /etc/nginx/conf.d/domain.dom.conf.template
	#curl -s https://repo.reqad.net/nginx-fastcgi_params.txt > /etc/nginx/fastcgi_params
	(systemctl enable nginx --now) >> ./install_reqad.log 2>&1

	if systemctl is-active --quiet nginx; then
	    echo -ne "[ ${GREEN}OK${NC} ]"
	else
	    echo -e "[ ${RED}ERROR${NC} ]"
	    systemctl status nginx
	    exit
	fi
else
    echo -ne "[${GREEN} RUNNING ${NC}] "
    echo -n 'Nginx '
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
echo -n "Installing PHP                      "
(dnf install -y https://rpms.remirepo.net/enterprise/remi-release-9.rpm) >> ./install_reqad.log 2>&1
(yum-config-manager --enable remi) >> ./install_reqad.log 2>&1
(dnf -y module reset php) >> ./install_reqad.log 2>&1
(dnf -y module enable php:remi-8.2) >> ./install_reqad.log 2>&1
(dnf install -y php-fpm php-xml php-pdo php-mysqlnd php-mysql php-mbstring php-pear php-gd php-mcrypt php-intl php-process  php-soap php-pecl-redis php-pecl-igbinary php-common php-json php-cli php-bcmath  php-pecl-zip php-opcache php-sodium) >> ./install_reqad.log 2>&1

sed -i 's/;date.timezone =/date.timezone = Europe\/Bucharest/' /etc/php.ini
sed -i 's/memory_limit = 128M/memory_limit = 2048M/' /etc/php.ini
sed -i 's/post_max_size = 8M/post_max_size = 64M/' /etc/php.ini
sed -i 's/upload_max_filesize = 2M/upload_max_filesize = 64M/' /etc/php.ini
sed -i 's/; max_input_vars = 1000/max_input_vars = 5000/' /etc/php.ini
sed -i 's/max_execution_time = 30/max_execution_time = 3600/' /etc/php.ini
sed -i 's/error_reporting = E_ALL & ~E_DEPRECATED & ~E_STRICT/error_reporting = E_ALL \& ~E_NOTICE \& ~E_DEPRECATED \& ~E_STRICT/' /etc/php.ini
sed -i 's/short_open_tag = Off/short_open_tag = On/' /etc/php.ini

echo "[www]
user = nginx
group = nginx

listen = /run/php-fpm.sock
listen.allowed_clients = 127.0.0.1
listen.owner = nginx
listen.group = nginx
listen.mode = 0660

pm = dynamic
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
(systemctl enable php-fpm --now) >> ./install_reqad.log 2>&1

if systemctl is-active --quiet php-fpm; then
    echo -ne "[ ${GREEN}OK${NC} ]"
else
    echo -e "[ ${RED}ERROR${NC} ]"
    systemctl status php-fpm
    exit
fi
else
    echo -ne "[${GREEN} RUNNING ${NC}] "
#    echo -n `php-fpm -v | head -n 1`
    echo -n 'PHP '
    echo -n `rpm -qi php-fpm | grep 'Version' | awk {'print $3'}`
fi
echo




#######################################################################################
#
#   mariadb

if ! systemctl is-active --quiet mariadb; then
    if [ "$(systemctl list-unit-files "mariadb.service" | grep "mariadb.service" | awk {'print $2'})" == "enabled" ]; then
        (systemctl start mariadb) >> ./install_reqad.log 2>&1
    fi
fi
if ! systemctl is-active --quiet mariadb; then
echo -n "Installing MariaDB                  "

curl -s https://downloads.mariadb.com/MariaDB/mariadb_repo_setup > mariadb_repo_setup
chmod +x mariadb_repo_setup
(rm -vf /etc/yum.repos.d/mariadb.repo*) >> ./install_reqad.log 2>&1
(./mariadb_repo_setup --mariadb-server-version=10.6) >> ./install_reqad.log 2>&1
#(dnf install -y MariaDB-client) >> ./install_reqad.log 2>&1
(dnf install -y MariaDB-client MariaDB-server MariaDB-client MariaDB-backup) >> ./install_reqad.log 2>&1
(systemctl enable --now mariadb) >> ./install_reqad.log 2>&1

PASSWORD=`head -n 10 /dev/urandom | tr -cd '[:alnum:]!@#$%^&*()+-0123456789' | paste -sd - | sed 's/[\t, ]//g' | cut -b -20`

#mariadb-secure-installation
#SECURE_MYSQL=$(expect -c "
#
#set timeout 10
#spawn mysql_secure_installation
#
#expect \"Enter current password for root (enter for none):\"
#send \"n\r\"
#expect \"Switch to unix_socket authentication \[Y/n\] \"
#send \"n\r\"
#expect \"Change the root password? \[Y/n\] \"
#send \"y\r\"
#expect \"New password: \"
#send \"${PASSWORD}\r\"
#expect \"Re-enter new password: \"
#send \"${PASSWORD}\r\"
#expect \"Remove anonymous users? \[Y/n\] \"
#send \"y\r\"
#expect \"Disallow root login remotely? \[Y/n\] \"
#send \"y\r\"
#expect \"Remove test database and access to it? \[Y/n\] \"
#send \"y\r\"
#expect \"Reload privilege tables now? \[Y/n\] \"
#send \"y\r\"
#expect eof
#")
#(echo "$SECURE_MYSQL") >> ./install_reqad.log 2>&1

mysqladmin -u root password "${PASSWORD}"

echo "[client]
#host=
#database=
user=\"root\"
password=\"${PASSWORD}\"
" > /root/.my.cnf

mysql -e "DELETE FROM mysql.db WHERE Db='test' OR Db='test\_%'"
mysql -e "FLUSH PRIVILEGES"

(systemctl stop mariadb) >> ./install_reqad.log 2>&1
sed -i "/\[mysqld\]/a\ \nlog-error = /var/log/mysqld.log\nbind-address = 127.0.0.1\nlocal-infile = 0\nperformance-schema = 0\nsymbolic-links = 0\nmax_connections = 50\n\nkey_buffer_size = 32M\njoin_buffer_size = 512M\n\nquery_cache_size = 256M\nquery_cache_type = 1\nquery_cache_limit = 256M\n\ntable_open_cache = 32000\ntable_open_cache_instances = 8\ntable_definition_cache = 60000\n\nmax-connect-errors = 1000000\nmax-allowed-packet = 256M\nwait-timeout = 28800\nconnect_timeout = 300\n\nthread_handling = 'pool-of-threads'\nthread_cache_size = 32\ninnodb_buffer_pool_size = 400M\ninnodb_log_file_size = 128M\ninnodb_log_buffer_size = 100M\n\noptimizer_switch='rowid_filter=off'" /etc/my.cnf.d/server.cnf
sed -i "s/LimitNOFILE=32768/LimitNOFILE=512231/" /usr/lib/systemd/system/mariadb.service
touch /var/log/mysqld.log
chown mysql:mysql /var/log/mysqld.log
(systemctl daemon-reload) >> ./install_reqad.log 2>&1
(systemctl start mariadb) >> ./install_reqad.log 2>&1

(dnf install -y which perl-Text-Template perl-diagnostics.noarch) >> ./install_reqad.log 2>&1
curl -s https://raw.githubusercontent.com/major/MySQLTuner-perl/master/mysqltuner.pl > mysqltuner.pl
sed -i 's/"color"          => 0/"color"          => 1/' mysqltuner.pl
sed -i 's/"color"               => 0/"color"               => 1/' mysqltuner.pl
chmod +x mysqltuner.pl


if systemctl is-active --quiet mariadb; then
    echo -ne "[ ${GREEN}OK${NC} ]"
else
    echo -e "[ ${RED}ERROR${NC} ]"
    systemctl status mariadb
    exit
fi
else
    echo -ne "[${GREEN} RUNNING ${NC}] "
#    echo -n `mysql -V | head -n 1`
    echo -n 'MariaDB '
    echo -n `rpm -qi MariaDB-server | grep -E '^Version' | awk {'print $3'}`
fi
echo



#######################################################################################
#
#   varnish

if ! systemctl is-active --quiet varnish; then
    if [ "$(systemctl list-unit-files "varnish.service" | grep "varnish.service" | awk {'print $2'})" == "enabled" ]; then
        (systemctl start varnish) >> ./install_reqad.log 2>&1
    fi
fi
if ! systemctl is-active --quiet varnish; then
	echo -n "Installing varnish                  "

	(rm -vf /etc/yum.repos.d/varnishcache_varnish75.repo) >> ./install_reqad.log 2>&1
	(curl -s https://packagecloud.io/install/repositories/varnishcache/varnish75/script.rpm.sh | sudo bash) >> ./install_reqad.log 2>&1
	(dnf install -y varnish) >> ./install_reqad.log 2>&1

	curl -s https://repo.reqad.net/varnish.txt > /etc/varnish/default.vcl
	sed -i 's/10.0.0.x/127.0.0.1/' /etc/varnish/default.vcl

	sed -i 's/-a :6081 \\/-a 127.0.0.1:6081 \\/' /usr/lib/systemd/system/varnish.service
	sed -i '/-a localhost:8443,PROXY \\/d' /usr/lib/systemd/system/varnish.service
	sed -i "/-p feature=+http2 \\\\/a\      -p http_resp_hdr_len=65536 \\\\" /usr/lib/systemd/system/varnish.service
	sed -i 's/-s malloc,256m/-s malloc,1024m/' /usr/lib/systemd/system/varnish.service

	(systemctl daemon-reload) >> ./install_reqad.log 2>&1
	(systemctl enable --now varnish) >> ./install_reqad.log 2>&1

	if systemctl is-active --quiet varnish; then
		echo -ne "[ ${GREEN}OK${NC} ]"
	else
		echo -e "[ ${RED}ERROR${NC} ]"
		systemctl status varnish
		exit
	fi
else
    echo -ne "[${GREEN} RUNNING ${NC}] "
    #echo -n `varnishd -V 2>&1 | head -n 1`
    echo -n 'Varnish '
    echo -n `rpm -qi varnish | grep -E '^Version' | awk {'print $3'}`
fi
echo



#######################################################################################
#
#   composer

COMPOSER_VER=$(rpm -qi composer | grep -E '^Version' | awk {'print $3'} | awk -F. {'print $1 "." $2'})
if [[ "${COMPOSER_VER}" != "2.7" ]]; then
	echo -n "Installing composer                 "
	(dnf install -y composer-2.7* python3-dnf-plugin-versionlock) >> ./install_reqad.log 2>&1
	(dnf versionlock composer-2.7*) >> ./install_reqad.log 2>&1
	echo -e "[ ${GREEN}OK${NC} ]"
else
	echo -e "[ ${GREEN}INSTALLED${NC} ] Composer ${COMPOSER_VER}"
fi



#######################################################################################
#
#   opensearch

if ! systemctl is-active --quiet opensearch; then
    if [ "$(systemctl list-unit-files "opensearch.service" | grep "opensearch.service" | awk {'print $2'})" == "enabled" ]; then
        (systemctl start opensearch) >> ./install_reqad.log 2>&1
    fi
fi
if ! systemctl is-active --quiet opensearch; then
echo -n "Installing OpenSearch               "

curl -s https://artifacts.opensearch.org/releases/bundle/opensearch/2.x/opensearch-2.x.repo > /etc/yum.repos.d/opensearch-2.x.repo

PASSWORD=$(head -n 10 /dev/urandom | tr -cd '[:alnum:]0123456789' | paste -sd - | sed 's/[\t, ]//g' | cut -b -20)
echo ${PASSWORD}
(env OPENSEARCH_INITIAL_ADMIN_PASSWORD=${PASSWORD} dnf install -y opensearch-2.12*) >> ./install_reqad.log 2>&1

echo "plugins.security.disabled: true
cluster.routing.allocation.disk.threshold_enabled: false
discovery.type: single-node
" >> /etc/opensearch/opensearch.yml
sed 's/plugins.security.ssl.http.enabled: true/plugins.security.ssl.http.enabled: false/' /etc/opensearch/opensearch.yml

echo "-Xms4g
-Xmx4g" > /etc/opensearch/jvm.options 

(systemctl daemon-reload) >> ./install_reqad.log 2>&1
(systemctl enable --now opensearch) >> ./install_reqad.log 2>&1
#curl -s -XPUT 'http://localhost:9200/_settings' -H 'Content-Type: application/json' -d '{ "index" : { "number_of_replicas" : 0 } }'

(curl -s -XPUT "http://localhost:9200/_template/default_template" -H 'Content-Type: application/json' -d'{ "index_patterns": ["*"], "order": -1, "settings": { "index": { "number_of_shards": "6", "number_of_replicas": 0 } } }') >> ./install_reqad.log 2>&1

if systemctl is-active --quiet opensearch; then
    echo -ne "[ ${GREEN}OK${NC} ]"
else
    echo -e "[ ${RED}ERROR${NC} ]"
    systemctl status opensearch
    exit
fi
else
    echo -ne "[${GREEN} RUNNING ${NC}] "
    echo -n 'ElasticSearch '
    echo -n `rpm -qi opensearch | grep 'Version' | awk {'print $3'}`
fi



echo
