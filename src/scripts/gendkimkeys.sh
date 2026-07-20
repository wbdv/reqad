#!/bin/bash
rm -f /etc/exim/keys/$1.private.key /etc/exim/keys/$1.public.key
openssl genrsa -out /etc/exim/keys/$1.private.key 2048
openssl rsa -in /etc/exim/keys/$1.private.key -out /etc/exim/keys/$1.public.key -pubout -outform PEM
chown exim:mail /etc/exim/keys/$1.private.key /etc/exim/keys/$1.public.key
chmod g+r /etc/exim/keys/$1.private.key /etc/exim/keys/$1.public.key
echo ""
echo "Add the following record in DNS zone for $1:"
cat /etc/exim/keys/$1.public.key | sed 's/-----BEGIN PUBLIC KEY-----/default._domainkey\tIN\tTXT\tv=DKIM1;t=s;p=/' | sed 's/-----END PUBLIC KEY-----//' | tr -d "\n" && echo
