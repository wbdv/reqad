#!/bin/bash

VERSION='0.0.7 - Mar 23, 2026'
MARIADB_VERSION='10.6'
PHPMYADMIN_VERSION='5.2.3'

WHITE='\033[1;37m'
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BLUE='\033[0;33m'
NC='\033[0m' # No Color

echo -ne "${YELLOW}"
echo -e "┌──────────────────────────────────────────────────────────────────────────────┐"
echo "│ Reqad install script for mariadb / phpmyadmin   version ${VERSION} │"
echo -e "└──────────────────────────────────────────────────────────────────────────────┘\n"
echo -ne "${NC}"

if [ "$EUID" -ne 0 ]
    then echo "Please run as root"  1>&2
    exit 1
fi

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

	(
		curl -L https://downloads.mariadb.com/MariaDB/mariadb_repo_setup > mariadb_repo_setup
		chmod +x mariadb_repo_setup
		rm -f /etc/yum.repos.d/mariadb.repo.old*
		./mariadb_repo_setup --mariadb-server-version=${MARIADB_VERSION} --skip-maxscale
		rm -f mariadb_repo_setup
		#dnf config-manager --set-disabled mariadb-maxscale
		dnf install -y MariaDB-client MariaDB-server MariaDB-client MariaDB-backup
	) >> ./install_reqad.log 2>&1 

	(systemctl enable --now mariadb) >> ./install_reqad.log 2>&1

	PASSWORD=`head -n 10 /dev/urandom | tr -cd '[:alnum:]@#%^*()+-0123456789' | paste -sd - | sed 's/[\t, ]//g' | cut -b -20`

	mysqladmin -u root password "${PASSWORD}"

	echo "[client]
#host=
#database=
user=\"root\"
password=\"${PASSWORD}\"
" > /root/.my.cnf

	mysql -e "DELETE FROM mysql.db WHERE Db='test' OR Db='test\_%'"
	mysql -e "FLUSH PRIVILEGES"
	mysql -e "DROP DATABASE test"

	(systemctl stop mariadb) >> ./install_reqad.log 2>&1
	sed -i "/\[mysqld\]/a\ \nlog-error = /var/log/mysqld.log\nbind-address = 127.0.0.1\nlocal-infile = 0\nperformance-schema = 0\nsymbolic-links = 0\nmax_connections = 50\n\nkey_buffer_size = 8M\njoin_buffer_size = 16M\n\ntmp_table_size = 256M\nmax_heap_table_size = 256M\n\nquery_cache_size = 256M\nquery_cache_type = 1\nquery_cache_limit = 256M\n\ntable_open_cache = 32000\ntable_open_cache_instances = 8\ntable_definition_cache = 60000\n\nmax-connect-errors = 1000000\nmax-allowed-packet = 256M\nwait-timeout = 28800\nconnect_timeout = 300\n\nthread_handling = 'pool-of-threads'\nthread_cache_size = 32\ninnodb_buffer_pool_size = 512M\ninnodb_log_file_size = 128M\ninnodb_log_buffer_size = 400M\n\noptimizer_switch='rowid_filter=off'" /etc/my.cnf.d/server.cnf
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
		echo -e "[ ${GREEN}OK${NC} ]"
	else
		echo -e "[ ${RED}ERROR${NC} ]"
		systemctl status mariadb
		exit
	fi

	if [ ! -d /usr/local/reqad/public_html/phpmyadmin/ ]; then
		echo -n "Installing phpMyAdmin               "
		(
			curl https://files.phpmyadmin.net/phpMyAdmin/${PHPMYADMIN_VERSION}/phpMyAdmin-${PHPMYADMIN_VERSION}-english.tar.gz > phpMyAdmin-${PHPMYADMIN_VERSION}-english.tar.gz
			rm -rf phpMyAdmin-${PHPMYADMIN_VERSION}-english
			tar xzf phpMyAdmin-${PHPMYADMIN_VERSION}-english.tar.gz
			rm -f phpMyAdmin-${PHPMYADMIN_VERSION}-english.tar.gz
			chown -R reqad:reqad phpMyAdmin-${PHPMYADMIN_VERSION}-english
			mv phpMyAdmin-${PHPMYADMIN_VERSION}-english /usr/local/reqad/public_html/phpmyadmin
	    	curl -s https://repo.reqad.net/phpmyadmin_config.txt > /usr/local/reqad/public_html/phpmyadmin/config.inc.php
			BLOWFISH=`head -n 10 /dev/urandom | tr -cd 'a-z0-9' | paste -sd - | sed 's/[\t, ]//g' | cut -b -32`
			sed -i "s/\$cfg\['blowfish_secret'\] = ''/\$cfg['blowfish_secret'] = '${BLOWFISH}'/" /usr/local/reqad/public_html/phpmyadmin/config.inc.php
			PASSWORD=$(echo "${PASSWORD}" | sed 's"/"\\\/"g')
			sed -i "s/\$cfg\['Servers'\]\[\$i\]\['password'\] = ''/\$cfg['Servers'][\$i]['password'] = '${PASSWORD}'/" /usr/local/reqad/public_html/phpmyadmin/config.inc.php
			mkdir -p /var/lib/php/session
			chmod -R a+rwx /var/lib/php/session
			mysql < /usr/local/reqad/public_html/phpmyadmin/sql/create_tables.sql
		) >> ./install_reqad.log 2>&1
    	echo -e "[ ${GREEN}OK${NC} ]"
	fi
else

    echo -ne "[${GREEN} RUNNING ${NC}] "
#    echo -n `mysql -V | head -n 1`
    echo -n 'MariaDB '
    echo -n `rpm -qi MariaDB-server | grep -E '^Version' | awk {'print $3'}`
fi
echo
