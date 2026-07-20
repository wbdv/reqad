#!/bin/bash

VERSION='0.0.1 - Mar 05, 2025'

WHITE='\033[1;37m'
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BLUE='\033[0;33m'
NC='\033[0m' # No Color

echo -ne "${YELLOW}"
echo -e "┌──────────────────────────────────────────────────────────────────────────────┐"
echo "│ Reqad install script for wp-cli                 version ${VERSION} │"
echo -e "└──────────────────────────────────────────────────────────────────────────────┘\n"
echo -ne "${NC}"

if [ "$EUID" -ne 0 ]
    then echo "Please run as root"  1>&2
    exit 1
fi



#######################################################################################
#
#  wp-cli

if [ ! -f "/usr/local/bin/wp" ]; then
    echo -n "Installing wp-cli                 "
    (
		curl -s -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
		chmod +x /usr/local/bin/wp
    ) >> ./install_reqad.log 2>&1
	echo -e "[ ${GREEN}OK${NC} ]"
fi

if [ $(grep -e '^wptoolkit=' /usr/local/reqad/etc/server-software.ini | wc -l) -eq 0 ]; then
	#sed -i '/\[reqad\]/a\wptoolkit=1' /usr/local/reqad/etc/server-software.ini
	sed -i '/accounts=/a\wptoolkit=1' /usr/local/reqad/etc/server-software.ini
fi
