#!/bin/bash

VERSION='0.0.2 - May 29, 2026'

WHITE='\033[1;37m'
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BLUE='\033[0;33m'
NC='\033[0m' # No Color

echo -ne "${YELLOW}"
echo -e "┌──────────────────────────────────────────────────────────────────────────────┐"
echo "│ Reqad install script for monit                  version ${VERSION} │"
echo -e "└──────────────────────────────────────────────────────────────────────────────┘\n"
echo -ne "${NC}"

if [ "$EUID" -ne 0 ]
    then echo "Please run as root"  1>&2
    exit 1
fi



#######################################################################################
#
#  mont

if [ "$(systemctl is-active monit)" == "inactive" ]; then
        #echo "Dovecot is not active"
    if [ "$(systemctl list-unit-files "monit.service" | grep "monit.service" | awk {'print $2'})" == "disabled" ]; then
                #echo -n "Enabling monit                "
        (systemctl enable --now monit) >> ./install_reqad.log 2>&1
        #echo -e "[ ${GREEN}OK${NC} ]"
        else
                #echo -n "Starting monit                "
        (systemctl start monit) >> ./install_reqad.log 2>&1
        #echo -e "[ ${GREEN}OK${NC} ]"
    fi
fi

if [ "$(systemctl is-active monit)" == "inactive" ]; then
    echo -n "Installing monit                  "
        (
                dnf -y install monit
                curl -s -o /etc/monit.d/custom https://repo.reqad.net/monit.txt
                SHORTHOST=$(hostname -s)
                sed -i "s/\$HOST/${SHORTHOST}/" /etc/monit.d/custom
                #sed -i 's/^set httpd port/#set httpd port/' /etc/monitrc
                #sed -i 's/^    use address localhost/#    use address localhost/' /etc/monitrc
                #sed -i 's/^    allow localhost/#    allow localhost/' /etc/monitrc
                sed -i 's/^    allow admin:monit/#    allow admin:monit/' /etc/monitrc
        ) >> ./install_reqad.log 2>&1
fi
if [ "$(systemctl is-active monit)" == "inactive" ]; then
        (systemctl enable --now monit.service) >> ./install_reqad.log 2>&1
        if [ "$(systemctl is-active monit)" == "active" ]; then
        echo -ne "[ ${GREEN}OK${NC} ]"
    else
        echo -e "[ ${RED}ERROR${NC} ]"
        systemctl status monit
        exit
    fi
else
    echo -ne "[${GREEN} RUNNING ${NC}] "
    echo -n 'monit '
    echo -n `rpm -qi monit | grep 'Version' | awk {'print $3'}`
fi
echo
