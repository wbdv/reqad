#!/bin/bash

# Install APCu (php-pecl-apcu) for every PHP version Reqad manages.
# Run manually as root after an RPM upgrade:   ./install-apcu.sh
#
# Versions come from [reqad] php_versions / php in server-software.ini, which is
# Reqad's authoritative list of installed PHP versions. The default version uses
# the unprefixed Remi module package (php-pecl-apcu); other versions use the
# Remi SCL prefix (e.g. php82-php-pecl-apcu).

VERSION='0.0.1 - Jun 04, 2026'

WHITE='\033[1;37m'
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
NC='\033[0m' # No Color

echo -ne "${YELLOW}"
echo -e "┌──────────────────────────────────────────────────────────────────────────────┐"
echo "│ Reqad install APCu for all PHP versions         version ${VERSION} │"
echo -e "└──────────────────────────────────────────────────────────────────────────────┘\n"
echo -ne "${NC}"

if [ "$EUID" -ne 0 ]; then
    echo "Please run as root" 1>&2
    exit 1
fi

INI="/usr/local/reqad/etc/server-software.ini"
LOG="./install_reqad.log"

if [ ! -f "$INI" ]; then
    echo -e "[ ${RED}ERROR${NC} ] cannot find $INI"
    exit 1
fi

DEFAULT_PHP=$(grep -E '^php=' "$INI" | head -n1 | cut -d= -f2 | tr -d ' ')
PHP_VERSIONS=$(grep -E '^php_versions=' "$INI" | head -n1 | cut -d= -f2 | tr ',' ' ')

if [ -z "$PHP_VERSIONS" ]; then
    echo -e "[ ${RED}ERROR${NC} ] no php_versions found in $INI"
    exit 1
fi

# install_apcu <label> <package> <fpm-service>
install_apcu() {
    local label="$1" pkg="$2" service="$3"
    printf "APCu for %-14s " "$label"

    if rpm -q "$pkg" >/dev/null 2>&1; then
        echo -e "[ ${GREEN}already installed${NC} ]"
    elif dnf install -y "$pkg" >> "$LOG" 2>&1; then
        echo -e "[ ${GREEN}OK${NC} ]"
    else
        echo -e "[ ${RED}ERROR${NC} ] (see $LOG)"
        return
    fi

    # Restart the fpm pool (if running) so the extension is loaded
    if systemctl is-active --quiet "$service"; then
        systemctl restart "$service" >> "$LOG" 2>&1 \
            && echo -e "    restarted ${service}" \
            || echo -e "    ${RED}failed to restart ${service}${NC}"
    fi
}

for v in $PHP_VERSIONS; do
    if [ "$v" == "$DEFAULT_PHP" ]; then
        install_apcu "PHP $v (default)" "php-pecl-apcu" "php-fpm"
    else
        suffix=$(echo "$v" | tr -d '.')
        install_apcu "PHP $v" "php${suffix}-php-pecl-apcu" "php${suffix}-php-fpm"
    fi
done

echo
echo -e "[ ${GREEN}Done${NC} ]"
