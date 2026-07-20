#!/bin/bash

DOMAIN=$1

openssl req -nodes -newkey rsa:2048 -keyout /etc/ssl/certs/${DOMAIN}.key -out /etc/ssl/certs/${DOMAIN}.csr -subj "/C=XX/ST=XX/L=City/O=Org/OU=server/CN=${DOMAIN}/emailAddress=me@example.com"

if [ -f "/etc/ssl/certs/${DOMAIN}.csr" ]; then
    (openssl x509 -req -days 365 -in /etc/ssl/certs/${DOMAIN}.csr -signkey /etc/ssl/certs/${DOMAIN}.key -out /etc/ssl/certs/${DOMAIN}.crt && rm -f /etc/ssl/certs/${DOMAIN}.csr) > /dev/null 2>&1
    if [ -f "/etc/ssl/certs/${DOMAIN}.crt" ]; then
       exit 0;
       #echo "Self-signed certificate was successfully created"; 
       #cat /etc/ssl/certs/${DOMAIN}.crt
    else
        rm -f /etc/ssl/certs/${DOMAIN}.key
    fi
    rm -f /etc/ssl/certs/${DOMAIN}.csr
fi
