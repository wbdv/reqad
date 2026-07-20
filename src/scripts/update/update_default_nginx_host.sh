#!/bin/bash
# Create the default nginx host for the server hostname if it doesn't exist.
# Uses Let's Encrypt cert if available, otherwise self-signed.

if [ "$(id -u)" -ne 0 ]; then
    echo "This script must be run as root"
    exit 1
fi

HOSTNAME=$(hostname)
CONF="/etc/nginx/conf.d/${HOSTNAME}.conf"

if [ -f "$CONF" ]; then
    echo "  ${CONF} already exists, skipping"
    exit 0
fi

echo "  Creating ${CONF}..."

if [ -f "/etc/letsencrypt/live/${HOSTNAME}/fullchain.pem" ]; then
    SSL_CERT="ssl_certificate /etc/letsencrypt/live/${HOSTNAME}/fullchain.pem;"
    SSL_KEY="ssl_certificate_key /etc/letsencrypt/live/${HOSTNAME}/privkey.pem;"
else
    if [ ! -f "/etc/ssl/certs/${HOSTNAME}.crt" ] || [ ! -f "/etc/ssl/certs/${HOSTNAME}.key" ]; then
        bash /usr/local/reqad/scripts/genselfsigned.sh "$HOSTNAME"
    fi
    SSL_CERT="ssl_certificate /etc/ssl/certs/${HOSTNAME}.crt;"
    SSL_KEY="ssl_certificate_key /etc/ssl/certs/${HOSTNAME}.key;"
fi

cat > "$CONF" << EOF
upstream php-fpm-nginx {
    server unix:/run/php-fpm-nginx.sock;
}

server {
    listen 80;
    server_name ${HOSTNAME};
    access_log /var/log/nginx/${HOSTNAME}_log;
    error_log /var/log/nginx/${HOSTNAME}_log;
    return 301 https://\$host\$request_uri;
}

server {
    listen 443 ssl;
    http2 on;
    server_name ${HOSTNAME};
    access_log /var/log/nginx/${HOSTNAME}_log;
    error_log /var/log/nginx/${HOSTNAME}_log;
    ${SSL_CERT}
    ${SSL_KEY}

    root /var/www/html;
    index index.php index.html index.htm;

    location / {
        try_files \$uri \$uri/ /index.php?\$args;

        location ~ [^/]\.php(/|$) {
            fastcgi_split_path_info ^(.+?\.php)(/.*)$;
            if (!-f \$document_root\$fastcgi_script_name) {
                return 404;
            }

            fastcgi_pass php-fpm-nginx;
            fastcgi_index index.php;
            include fastcgi_params;
            fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
            fastcgi_keep_conn on;
        }

        location ~ /\.ht {
            deny all;
        }
    }
}
EOF

echo "  Testing nginx config..."
if nginx -t 2>/dev/null; then
    systemctl reload nginx
    echo "  Done — ${HOSTNAME} default host created and nginx reloaded."
else
    echo "  ERROR: nginx config test failed — reload skipped"
    nginx -t
    exit 1
fi
