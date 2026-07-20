#!/bin/bash

VERSION='0.0.2 - May 30, 2022'

WHITE='\033[1;37m'
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BLUE='\033[0;33m'
NC='\033[0m' # No Color

echo -ne "${YELLOW}"
echo -e "________________________________________________________________________________\n"
echo " Install Apache 2.4 + PHP 7.4 (mod_php + mod_ruid2) + MariaDB 10.3"
echo -e "________________________________________________________________________________\n"
echo -ne "${NC}"

if [ "$EUID" -ne 0 ]
    then echo "Please run as root"  1>&2
    exit 1
fi

echo "
- Apache   (2.4)      
- PHP      (7.4)
- MariaDB  (10.3)
"




#######################################################################################
#
#  httpd

if ! systemctl is-active --quiet httpd; then
    if [ "$(systemctl list-unit-files "httpd.service" | grep "httpd.service" | awk {'print $2'})" == "enabled" ]; then
        (systemctl start httpd) >> ./install_apache24.log 2>&1
    fi
fi
if ! systemctl is-active --quiet httpd; then
echo -n "Installing Apache                    "

#install httpd 1.x from v106
sed -i "/\[epel\]/aexclude=httpd*" /etc/yum.repos.d/epel.repo
(yum install -y httpd mod_ssl mod_ruid2 certbot-apache) >> ./install_apache24.log 2>&1
#curl -s https://ssl-config.mozilla.org/ffdhe2048.txt > /etc/httpd/dhparams.pem
#sed -i 's/\/var\/log\/httpd\/\*\.log/\/var\/log\/httpd\/\*log/' /etc/logrotate.d/httpd
truncate -s 0 /etc/httpd/conf.d/default*

(systemctl enable httpd --now) >> ./install_apache24.log 2>&1 
if systemctl is-active --quiet httpd; then
    echo -e "[ ${GREEN}OK${NC} ]"
else
    echo -e "[ ${RED}ERROR${NC} ]"
    systemctl status httpd
    exit
fi
else
    echo -ne "[${GREEN} RUNNING ${NC}] "
#    echo -n `httpd -v`
    echo -n 'Apache '
    echo -n `rpm -qi httpd | grep 'Version' | awk {'print $3'}`
fi
echo


#######################################################################################
#
#  php (mod_php)

echo -n "Installing php (mod_php)                  "
(yum-config-manager --enable remi-php74) >> ./install_apache24.log 2>&1
(yum-config-manager --enable remi) >> ./install_apache24.log 2>&1

#(yum install -y php-fpm php-xml php-pdo php-mysqlnd php-mysql php-mbstring php-pear php-gd php-mcrypt php-intl php-process  php-soap php-pecl-redis php-pecl-igbinary php-common php-json php-cli php-xml php-bcmath  php-pecl-zip php-opcache composer php-sodium) >> ./install_apache24.log 2>&1
(yum install -y php php-xml php-pdo php-mysqlnd php-mysql php-mbstring php-pear php-gd php-mcrypt php-intl php-process  php-soap php-pecl-redis php-pecl-igbinary php-common php-json php-cli php-xml php-bcmath  php-pecl-zip php-opcache composer php-sodium) >> ./install_apache24.log 2>&1

sed -i 's/;date.timezone =/date.timezone = Europe\/Bucharest/' /etc/php.ini
sed -i 's/memory_limit = 128M/memory_limit = 2048M/' /etc/php.ini
sed -i 's/post_max_size = 8M/post_max_size = 64M/' /etc/php.ini
sed -i 's/upload_max_filesize = 2M/upload_max_filesize = 64M/' /etc/php.ini
sed -i 's/; max_input_vars = 1000/max_input_vars = 5000/' /etc/php.ini
sed -i 's/max_execution_time = 30/max_execution_time = 3600/' /etc/php.ini
sed -i 's/error_reporting = E_ALL & ~E_DEPRECATED & ~E_STRICT/error_reporting = E_ALL \& ~E_NOTICE \& ~E_DEPRECATED \& ~E_STRICT/' /etc/php.ini

sed -i 's/;opcache.save_comments=1/opcache.save_comments=1/' /etc/php.d/10-opcache.ini
sed -i 's/opcache.interned_strings_buffer=8/opcache.interned_strings_buffer=32/' /etc/php.d/10-opcache.ini
sed -i 's/opcache.memory_consumption=128/opcache.memory_consumption=1024/' /etc/php.d/10-opcache.ini
sed -i 's/opcache.max_accelerated_files=4000/opcache.max_accelerated_files=1000000/' /etc/php.d/10-opcache.ini

chmod -R a+rwx /var/lib/php/session/

echo -n 'PHP '
echo -n `rpm -qi php | grep 'Version' | awk {'print $3'}`
echo



#######################################################################################
#
#   mariadb

if ! systemctl is-active --quiet mariadb; then
    if [ "$(systemctl list-unit-files "mariadb.service" | grep "mariadb.service" | awk {'print $2'})" == "enabled" ]; then
        (systemctl start mariadb) >> ./install_apache24.log 2>&1
    fi
fi
if ! systemctl is-active --quiet mariadb; then
echo -n "Installing MariaDB                  "

echo '# MariaDB 10.3 CentOS repository list
# https://downloads.mariadb.org/mariadb/repositories/
[mariadb]
name = MariaDB
baseurl = https://mirrors.chroot.ro/mariadb/yum/10.3/centos7-amd64
#baseurl = https://yum.mariadb.org/10.3/centos7-amd64
gpgkey=https://yum.mariadb.org/RPM-GPG-KEY-MariaDB
gpgcheck=1' > /etc/yum.repos.d/MariaDB.repo
(yum install -y MariaDB-server MariaDB-client which perl-Text-Template) >> ./install_apache24.log 2>&1
(systemctl enable --now mariadb) >> ./install_apache24.log 2>&1

PASSWORD=`head -n 10 /dev/urandom | tr -cd '[:alnum:]!@#$%^&*()+-0123456789' | paste -sd - | sed 's/[\t, ]//g' | cut -b -20`

SECURE_MYSQL=$(expect -c "

set timeout 10
spawn mysql_secure_installation

expect \"Enter current password for root\"
send \"\r\"

expect \"Switch to unix_socket authentication\"
send \"n\r\"

expect \"Change the root password?\"
send \"y\r\"

expect \"New password\"
send \"${PASSWORD}\r\"

expect \"Re-enter new password\"
send \"${PASSWORD}\r\"

expect \"Remove anonymous users?\"
send \"y\r\"

expect \"Disallow root login remotely?\"
send \"y\r\"

expect \"Remove test database and access to it?\"
send \"y\r\"

expect \"Reload privilege tables now?\"
send \"y\r\"

expect eof
")

(echo "$SECURE_MYSQL") >> ./install_apache24.log 2>&1

echo "[client]
#host=
#database=
user=\"root\"
password=\"${PASSWORD}\"
" > /root/.my.cnf

(systemctl stop mariadb) >> ./install_apache24.log 2>&1
sed -i "/\[mysqld\]/a\ \nlog-error = /var/log/mysqld.log\nbind-address = 127.0.0.1\nlocal-infile = 0\nperformance-schema = 0\nsymbolic-links = 0\nmax_connections = 200\ntable_open_cache=10000\nquery_cache_size=0\nquery_cache_type=0\nquery_cache_limit=256M\nmax_allowed_packet=128M" /etc/my.cnf.d/server.cnf
sed -i "s/LimitNOFILE=/LimitNOFILE=160255/" /usr/lib/systemd/system/mariadb.service
touch /var/log/mysqld.log
chown mysql:mysql /var/log/mysqld.log
(systemctl daemon-reload) >> ./install_apache24.log 2>&1
(systemctl start mariadb) >> ./install_apache24.log 2>&1

wget -q https://github.com/major/MySQLTuner-perl/raw/master/mysqltuner.pl
sed -i 's/"color"          => 0/"color"          => 1/' mysqltuner.pl
chmod +x mysqltuner.pl

if systemctl is-active --quiet mariadb; then
    echo -e "[ ${GREEN}OK${NC} ]"
else
    echo -e "[ ${RED}ERROR${NC} ]"
    systemctl status mariadb 
    exit
fi
else
    echo -ne "[${GREEN} RUNNING ${NC}] "
#    echo -n `mysql -V | head -n 1`
    echo -n 'MariaDB '
    echo -n `rpm -qi MariaDB-server | grep 'Version' | awk {'print $3'}`

fi
echo

    mkdir -p '/usr/local/reqad/etc/';
    ini_file='/usr/local/reqad/etc/server-software.ini';
    echo '[reqad]
template=apache-2.4
accounts=-1
quota=0

[web]' > $ini_file;
    echo -n 'apache=' >> $ini_file;
    echo `rpm -qi httpd | grep 'Version' | awk {'print $3'}` >> $ini_file;
    echo -n 'php=' >> $ini_file;
    echo `rpm -qi php-fpm | grep 'Version' | awk {'print $3'}` >> $ini_file;
    echo -n 'mariadb=' >> $ini_file;
    echo `rpm -qi MariaDB-server | grep 'Version' | awk {'print $3'}` >> $ini_file;
