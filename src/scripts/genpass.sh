#!/bin/bash

GREY='\033[1;30m'
NC='\033[0m' # No Color

PASSWORD=$1
if [ "${PASSWORD}" == "" ]; then
        PASSWORD=$(head -n 10 /dev/urandom | tr -cd '[:alnum:]!@#%^&*()+-0123456789' | paste -sd - | sed 's/[\t, ]//g' | cut -b -16)
fi
SALT=$(openssl rand -base64 32 | cut -b -16)
echo -e "${GREY} Password: ${NC}" ${PASSWORD}
echo -e "${GREY}     Salt: ${NC}" ${SALT}
echo -ne "${GREY}     Hash: ${NC} "
openssl passwd -6 -salt ${SALT} ${PASSWORD}
