#!/bin/bash

VERSION='0.0.1 - May 2025'

WHITE='\033[1;37m'
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BLUE='\033[0;33m'
NC='\033[0m'

echo -ne "${YELLOW}"
echo -e "┌───────────────────────────────────────────────────────────────────────────┐"
echo "│ PowerDNS hidden-master install script version ${VERSION}            │"
echo -e "└───────────────────────────────────────────────────────────────────────────┘\n"
echo -ne "${NC}"

if [ "$EUID" -ne 0 ]; then
    echo "Please run as root" 1>&2
    exit 1
fi

# Prompt for cPanel primary DNS server IP
while true; do
    read -r -p "Enter cPanel primary DNS server IP: " CPANEL_IP
    if echo "$CPANEL_IP" | grep -qE '^[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}$'; then
        break
    fi
    echo "Invalid IP address. Try again."
done

PDNS_API_KEY=$(head -n 10 /dev/urandom | tr -cd 'A-Za-z0-9' | paste -sd - | sed 's/[\t, ]//g' | cut -b -40)

pdns_conf_set() {
    local key="$1" val="$2"
    if grep -qE "^${key}=" /etc/pdns/pdns.conf; then
        sed -i "s|^${key}=.*|${key}=${val}|" /etc/pdns/pdns.conf
    elif grep -qE "^# ${key}=" /etc/pdns/pdns.conf; then
        sed -i "s|^# ${key}=.*|${key}=${val}|" /etc/pdns/pdns.conf
    else
        echo "${key}=${val}" >> /etc/pdns/pdns.conf
    fi
}

if ! systemctl is-active --quiet pdns; then
    if [ "$(systemctl list-unit-files "pdns.service" | grep "pdns.service" | awk '{print $2}')" == "enabled" ]; then
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
    PDNS_FRESH_INSTALL=1
else
    # pdns already running — read existing API key
    PDNS_API_KEY=$(grep '^api-key=' /etc/pdns/pdns.conf | cut -d= -f2-)
    PDNS_FRESH_INSTALL=0
fi

# Apply hidden-master settings
pdns_conf_set "also-notify"    "$CPANEL_IP"
pdns_conf_set "allow-axfr-ips" "$CPANEL_IP"

(systemctl restart pdns) >> ./install_reqad.log 2>&1

echo -n "PowerDNS hidden-master configuration  ";
if systemctl is-active --quiet pdns; then
    echo -e "[ ${GREEN}OK${NC} ]"
    if [ "$PDNS_FRESH_INSTALL" == "1" ]; then
        echo "  Fresh install completed."
    else
        echo "  Updated existing installation."
    fi
else
    echo -e "[ ${RED}ERROR${NC} ]"
    systemctl status pdns
    exit 1
fi

# Generate SSH keypair for cPanel DNS sync
SSH_KEY="/usr/local/reqad/etc/cpanel_dns_rsa"

echo -n "SSH keypair for cPanel DNS             "
if [ ! -f "$SSH_KEY" ]; then
    ssh-keygen -t rsa -b 4096 -f "$SSH_KEY" -N "" -q
    chown reqad:reqad "$SSH_KEY" "$SSH_KEY.pub"
    chmod 600 "$SSH_KEY"
    echo -e "[ ${GREEN}CREATED${NC} ]"
else
    echo -e "[ ${YELLOW}EXISTS${NC} ]  (using existing key)"
fi

VPS_IP=$(ip route get 8.8.8.8 2>/dev/null | awk 'NR==1{print $7}')

echo
echo -e "${WHITE}PowerDNS hidden-master setup complete.${NC}"
echo
echo "  API endpoint : http://127.0.0.1:8081"
echo "  API key      : ${PDNS_API_KEY}"
echo "  SSH key      : ${SSH_KEY}"
echo "  cPanel notify: ${CPANEL_IP}"
echo "  VPS IP       : ${VPS_IP:-<check with: ip addr>}"
echo
echo -e "${YELLOW}Next steps on the cPanel primary DNS server:${NC}"
echo "  1. Copy the public key below to the cPanel server (e.g. /tmp/reqad_vps.pub)"
echo "  2. Run: ./create-reqad-dns-user.sh <username> ${VPS_IP:-<this-vps-ip>} /tmp/reqad_vps.pub"
echo "  3. In Reqad UI: Settings → DNS Settings → select PowerDNS → Hidden master mode"
echo "     Fill: NS1/NS2, SSH host (${CPANEL_IP}), SSH user, SSH key (${SSH_KEY})"
echo
echo -e "${GREEN}Public key:${NC}"
cat "${SSH_KEY}.pub"
echo
