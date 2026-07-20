#!/bin/bash

VERSION='0.0.1 - Apr 7, 2025 '

WHITE='\033[1;37m'
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BLUE='\033[0;33m'
NC='\033[0m' # No Color

echo -ne "${YELLOW}"
echo -e "┌──────────────────────────────────────────────────────────────────────────────┐"
echo "│ PowerDNS install script version ${VERSION}                         │"
echo -e "└──────────────────────────────────────────────────────────────────────────────┘\n"
echo -ne "${NC}"

if [ "$EUID" -ne 0 ]
    then echo "Please run as root"  1>&2
    exit 1
fi

PDNS_API_KEY=$(head -n 10 /dev/urandom | tr -cd 'A-Za-z0-9' | paste -sd - | sed 's/[\t, ]//g' | cut -b -40)

if ! systemctl is-active --quiet pdns; then
    if [ "$(systemctl list-unit-files "pdns.service" | grep "pdns.service" | awk {'print $2'})" == "enabled" ]; then
        (systemctl start pdns) >> ./install_reqad.log 2>&1
    fi
fi

if ! systemctl is-active --quiet pdns; then

	(
		dnf install -y pdns sqlite pdns-backend-sqlite
		sed -i 's/^# master=no/master=yes/' /etc/pdns/pdns.conf
		sed -i 's/^# api=no/api=yes/' /etc/pdns/pdns.conf
		sed -i "s/^# api-key=/api-key=${PDNS_API_KEY}/" /etc/pdns/pdns.conf
		sed -i "s/^launch=bind/#launch=bind\nlaunch=gsqlite3\ngsqlite3-database=\/var\/lib\/pdns\/pdns.db/" /etc/pdns/pdns.conf
		sqlite3 /var/lib/pdns/pdns.db < /usr/share/doc/pdns/schema.sqlite3.sql
		chown pdns:pdns /var/lib/pdns/pdns.db
    	systemctl enable pdns --now
	) >> ./install_reqad.log 2>&1

	echo -n "Installing PowerDNS                 ";
    if systemctl is-active --quiet pdns; then
        echo -e "[ ${GREEN}OK${NC} ]"
		echo "PowerDNS Server address: http://127.0.0.1:8081"
		echo "PowerDNS API Key: ${PDNS_API_KEY}"
    else
        echo -e "[ ${RED}ERROR${NC} ]"
        systemctl status pdns
        exit
    fi
else
	echo -n "PowerDNS status                      ";
    echo -ne "[${GREEN} RUNNING ${NC}] "
    echo -n 'pdns '
    echo -n `rpm -qi pdns | grep 'Version' | awk {'print $3'}`
fi
echo
