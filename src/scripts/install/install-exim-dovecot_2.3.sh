#!/bin/bash

VERSION='0.0.3 - Sep 5, 2025'
ROUNDCUBE_VER='1.6.9'

WHITE='\033[1;37m'
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BLUE='\033[0;33m'
NC='\033[0m' # No Color

echo -ne "${YELLOW}"
echo -e "┌──────────────────────────────────────────────────────────────────────────────┐"
echo "│ Reqad install script for exim / dovecot / spamassassin v${VERSION}  │"
echo -e "└──────────────────────────────────────────────────────────────────────────────┘\n"
echo -ne "${NC}"

if [ "$EUID" -ne 0 ]
    then echo "Please run as root"  1>&2
    exit 1
fi



#######################################################################################
#
#  exim

if [ -f /etc/exim/exim.conf ]; then
	EXIM_LOCAL=$(grep local_interfaces /etc/exim/exim.conf | sed 's/ //g' | awk -F= {'print $2'})
	if [ "127.0.0.1.25" == "${EXIM_LOCAL}" ]; then
		echo "Exim is configured to run on local interface, reconfigure it.";
	fi
elif ! systemctl is-active --quiet exim; then
    if [ "$(systemctl list-unit-files "exim.service" | grep "exim.service" | awk {'print $2'})" == "enabled" ]; then
        (systemctl start exim) >> ./install_reqad.log 2>&1
    fi
fi

if ! systemctl is-active --quiet exim; then
    echo -n "Installing exim                     "
	(dnf -y install exim) >> ./install_reqad.log 2>&1
elif [ "127.0.0.1.25" == "${EXIM_LOCAL}" ]; then
    echo -n "Reconfigure exim                    "
	(mv /etc/exim/exim.conf /etc/exim/exim.conf.bkp ) >> ./install_reqad.log 2>&1
fi

(
	mkdir -p /etc/exim/domains /etc/exim/forwards /etc/exim/keys
	touch /etc/exim/userdomains
	echo "if not first_delivery
then
  finish
endif
" > /etc/exim/system_filter
	curl -s https://repo.reqad.net/trustedmailhosts.txt > /etc/exim/trustedmailhosts
	curl -s https://repo.reqad.net/exim.txt > /etc/exim/exim.conf
    SRS_SECRET=`head -n 10 /dev/urandom | tr -cd 'a-z0-9' | paste -sd - | sed 's/[\t, ]//g' | cut -b -32`
	sed -i "s/SRS_SECRET = $/SRS_SECRET = ${SRS_SECRET}/" /etc/exim/exim.conf
) >> ./install_reqad.log 2>&1

if ! systemctl is-active --quiet exim  || [ "127.0.0.1.25" == "${EXIM_LOCAL}" ]; then
	(systemctl enable --now exim.service) >> ./install_reqad.log 2>&1
    if systemctl is-active --quiet exim; then
        echo -ne "[ ${GREEN}OK${NC} ]"
    else
        echo -e "[ ${RED}ERROR${NC} ]"
        systemctl status exim
        exit
    fi
else
    echo -ne "[${GREEN} RUNNING ${NC}] "
    echo -n 'exim '
    echo -n `rpm -qi exim | grep 'Version' | awk {'print $3'}`
fi
echo



#######################################################################################
#
#  spamassassin

if [ "$(systemctl is-active spamassassin)" == "inactive" ]; then
	#echo "spamassassin is not active"
    if [ "$(systemctl list-unit-files "spamassassin.service" | grep "spamassassin.service" | awk {'print $2'})" == "disabled" ]; then
		#echo -n "Enabling spamassassin                "
        (systemctl enable --now spamassassin) >> ./install_reqad.log 2>&1
        #echo -e "[ ${GREEN}OK${NC} ]"
	else
		#echo -n "Starting spamassassin                "
        (systemctl start spamassassin) >> ./install_reqad.log 2>&1
        #echo -e "[ ${GREEN}OK${NC} ]"
    fi
fi

if [ "$(systemctl is-active spamassassin)" == "inactive" ]; then
    echo -n "Installing spamassassin             "
	(
		dnf install -y spamassassin
		groupadd -r spamd
		useradd -r -g spamd -s /sbin/nologin -d /var/lib/spamassassin spamd
		mkdir -p /var/lib/spamassassin
		chown spamd:spamd /var/lib/spamassassin
		echo 'SPAMDOPTIONS="-c -m5 -H -u spamd"' > /etc/sysconfig/spamassassin
		curl -s https://repo.reqad.net/spamassassin_local.txt > /etc/mail/spamassassin/local.cf 
		/usr/bin/sa-update -v
	) >> ./install_reqad.log 2>&1
fi
if [ "$(systemctl is-active spamassassin)" == "inactive" ]; then
	(systemctl enable --now spamassassin.service) >> ./install_reqad.log 2>&1
	if [ "$(systemctl is-active spamassassin)" == "active" ]; then
        echo -ne "[ ${GREEN}OK${NC} ]"
    else
        echo -e "[ ${RED}ERROR${NC} ]"
        systemctl status spamassassin
        exit
    fi
else
    echo -ne "[${GREEN} RUNNING ${NC}] "
    echo -n 'spamassassin '
    echo -n `rpm -qi spamassassin | grep 'Version' | awk {'print $3'}`
fi
echo




#######################################################################################
#
#  dovecot

if [ "$(systemctl is-active dovecot)" == "inactive" ]; then
	echo "Dovecot is not active"
    if [ "$(systemctl list-unit-files "dovecot.service" | grep "dovecot.service" | awk {'print $2'})" == "disabled" ]; then
		echo -n "Enabling dovecot                "
        (systemctl enable --now dovecot) >> ./install_reqad.log 2>&1
        #echo -e "[ ${GREEN}OK${NC} ]"
	else
		#echo -n "Starting dovecot                "
        (systemctl start dovecot) >> ./install_reqad.log 2>&1
        #echo -e "[ ${GREEN}OK${NC} ]"
    fi
fi

if [ "$(systemctl is-active dovecot)" == "inactive" ]; then
    echo -n "Installing dovecot                  "
	(
		echo '[dovecot-2.3-latest]
name=Dovecot 2.3 RHEL $releasever - $basearch
baseurl=http://repo.dovecot.org/ce-2.3-latest/rhel/$releasever/RPMS/$basearch
gpgkey=https://repo.dovecot.org/DOVECOT-REPO-GPG
gpgcheck=1
enabled=1
' > /etc/yum.repos.d/dovecot.repo
        #curl -s -o /etc/pki/rpm-gpg/DOVECOT-REPO-GPG https://repo.dovecot.org/DOVECOT-REPO-GPG-2.3
        curl -s -o /etc/pki/rpm-gpg/DOVECOT-REPO-GPG https://repo.reqad.net/DOVECOT-REPO-GPG
        rpm --import /etc/pki/rpm-gpg/DOVECOT-REPO-GPG
        mkdir /etc/dovecot
		dnf -y install dovecot patch
		touch /etc/dovecot/users
		curl -s -o dovecot.patch https://repo.reqad.net/dovecot.patch	
		patch -d /etc/dovecot -p 1 -ltN < dovecot.patch
		rm -f dovecot.patch /etc/dovecot/dh.pem
		openssl dhparam -dsaparam -out /etc/dovecot/dh.pem 4096
		touch /etc/dovecot/sni.conf
		usermod -G mail,exim,mysyslog dovecot
		/usr/local/reqad/scripts/update_email_sni
	) >> ./install_reqad.log 2>&1
fi
if [ "$(systemctl is-active dovecot)" == "inactive" ]; then
	(systemctl enable --now dovecot.service) >> ./install_reqad.log 2>&1
	if [ "$(systemctl is-active dovecot)" == "active" ]; then
        echo -ne "[ ${GREEN}OK${NC} ]"
    else
        echo -e "[ ${RED}ERROR${NC} ]"
        systemctl status dovecot
        exit
    fi
else
    echo -ne "[${GREEN} RUNNING ${NC}] "
    echo -n 'dovecot '
    echo -n `rpm -qi dovecot | grep 'Version' | awk {'print $3'}`
fi
echo

if [ "$(grep -e '^email=0$' /usr/local/reqad/etc/server-software.ini)" == "email=0" ]; then
    echo -n "Enabling email in Reqad config      ";
    ( sed -i 's/email=0/email=1/' /usr/local/reqad/etc/server-software.ini ) >> ./install_reqad.log 2>&1
    echo -e "[ ${GREEN}OK${NC} ]"
fi

echo -n "Installing Roundcube Mail           ";
if [ -f /usr/local/reqad/public_html/roundcubemail/config/config.inc.php ]; then
    echo -e "[ ${RED}SKIP${NC} ] /usr/local/reqad/public_html/roundcubemail/config/config.inc.php already exists"
else
	(
	rm -rf roundcubemail-${ROUNDCUBE_VER}.tar.gz roundcubemail-${ROUNDCUBE_VER} /usr/local/reqad/public_html/roundcubemail
	curl -L -o roundcubemail-${ROUNDCUBE_VER}.tar.gz https://github.com/roundcube/roundcubemail/releases/download/1.6.9/roundcubemail-1.6.9-complete.tar.gz
	tar xzf roundcubemail-${ROUNDCUBE_VER}.tar.gz
	rm -f roundcubemail-${ROUNDCUBE_VER}.tar.gz
	mv -v roundcubemail-${ROUNDCUBE_VER} /usr/local/reqad/public_html/roundcubemail
	ln -sv roundcubemail /usr/local/reqad/public_html/webmail 
	cp -v /usr/local/reqad/public_html/roundcubemail/config/config.inc.php.sample /usr/local/reqad/public_html/roundcubemail/config/config.inc.php

	PASSWORD=`head -n 10 /dev/urandom | tr -cd '[:alnum:]0123456789' | paste -sd - | sed 's/[\t, ]//g' | cut -b -16`
	DB_ROUNDCUBE=`/usr/bin/mysql --defaults-extra-file=/root/.my.cnf -se "SHOW DATABASES WHERE Database='roundcube'"`

	if [ "${DB_ROUNDCUBE}" != "roundcube" ]; then
		echo "Create database and import structure"
		/usr/bin/mysql --defaults-extra-file=/root/.my.cnf -e "CREATE DATABASE roundcube;";
		/usr/bin/mysql --defaults-extra-file=/root/.my.cnf roundcube < /usr/local/reqad/public_html/roundcubemail/SQL/mysql.initial.sql 
	else
		echo "Database already exists"
	fi
	/usr/bin/mysql --defaults-extra-file=/root/.my.cnf -e "GRANT ALL ON roundcube.* TO roundcube@localhost IDENTIFIED BY '${PASSWORD}'";

	sed -i "s/roundcube:pass@localhost\/roundcubemail/roundcube:${PASSWORD}@localhost\/roundcube/" /usr/local/reqad/public_html/roundcubemail/config/config.inc.php
	echo "

\$config['password_driver']='dovecot_passwdfile';
\$config['password_dovecot_passwdfile_path'] = '/etc/dovecot/users';
\$config['password_algorithm'] = 'sha512-crypt';

// LOGGING
\$config['log_driver'] = 'syslog';
\$config['syslog_facility'] = LOG_MAIL;
" >> /usr/local/reqad/public_html/roundcubemail/config/config.inc.php

	chown -R reqad:reqad /usr/local/reqad/public_html/roundcubemail
	chown -h reqad:reqad /usr/local/reqad/public_html/webmail
	) >> ./install_reqad.log 2>&1
    echo -e "[ ${GREEN}OK${NC} ]"
fi
