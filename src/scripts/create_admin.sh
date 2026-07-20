#!/bin/bash
echo "User: admin"
pass=$(openssl passwd -6 -noverify)
sed -i '/^admin:/d' /usr/local/reqad/nginx_auth
echo "admin:${pass}" >>  /usr/local/reqad/nginx_auth
