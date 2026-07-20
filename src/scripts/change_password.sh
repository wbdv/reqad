#!/bin/bash
GREY='\033[1;30m'
RED='\033[0;31m'
NC='\033[0m' # No Color

USER=$1
FILE="/usr/local/reqad/nginx_auth"
if [ "${USER}" == "" ];then
	echo "Syntax: $0 <user>"
	exit
fi
if [ "$(cat ${FILE} | awk -F: {'print $1'} | grep ${USER})" == "" ]; then
	echo "User ${USER} does not exists in ${FILE}" 
	exit
fi 
echo -ne "${GREY}Password:${NC} "
read -s PASSWORD

if [ "${PASSWORD}" == "" ]; then
    PASSWORD=$(head -n 10 /dev/urandom | tr -cd '[:alnum:]!@#%^&*()+-0123456789' | paste -sd - | sed 's/[\t, ]//g' | cut -b -16)
	echo "<empty>"; echo "Generating a random passwod ..."
	echo -e "${GREY}Password:${NC}" ${PASSWORD}
else
	#echo ${PASSWORD} | sed 's/./*/g'
	echo ${PASSWORD}
fi

SALT=$(openssl rand -base64 32 | cut -b -16)
HASHPASS=$(openssl passwd -6 -salt ${SALT} ${PASSWORD})

sed -i "/^${USER}:/d" ${FILE}
#echo -e "${USER}:${HASHPASS}" | tee --append ${FILE}
echo -e "${USER}:${HASHPASS}"
echo -e "${USER}:${HASHPASS}" >> ${FILE}

if [ "$(cat ${FILE} | awk -F: {'print $1'} | grep ${USER})" != "" ]; then
	echo "Password for user ${USER} was successfully changed."
	exit 0
else
	echo -e "${RED}Error:${NC} user ${USER} does not exists in ${FILE}" 
	exit 1
fi 
