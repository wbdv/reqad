#!/bin/bash
# Create the default Apache host for the server hostname if it doesn't exist.
# Uses Let's Encrypt cert if available, otherwise self-signed.

if [ "$(id -u)" -ne 0 ]; then
    echo "  This script must be run as root"
    exit 1
fi

CONF_DIR="/etc/httpd/conf.d"

if [ ! -d "$CONF_DIR" ]; then
    echo "  Apache not installed, skipping"
    exit 0
fi

HOSTNAME=$(hostname)
CONF="${CONF_DIR}/${HOSTNAME}.conf"

if [ -f "$CONF" ]; then
    echo "  ${CONF} already exists, skipping"
    exit 0
fi

echo "  Creating ${CONF}..."

if [ -f "/etc/letsencrypt/live/${HOSTNAME}/cert.pem" ]; then
    SSL_BLOCK="    Include /etc/letsencrypt/options-ssl-apache.conf
    SSLCertificateFile /etc/letsencrypt/live/${HOSTNAME}/cert.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/${HOSTNAME}/privkey.pem
    SSLCertificateChainFile /etc/letsencrypt/live/${HOSTNAME}/chain.pem"
else
    if [ ! -f "/etc/ssl/certs/${HOSTNAME}.crt" ] || [ ! -f "/etc/ssl/certs/${HOSTNAME}.key" ]; then
        bash /usr/local/reqad/scripts/genselfsigned.sh "$HOSTNAME"
    fi
    SSL_BLOCK="    SSLEngine on
    SSLCertificateFile /etc/ssl/certs/${HOSTNAME}.crt
    SSLCertificateKeyFile /etc/ssl/certs/${HOSTNAME}.key"
fi

cat > "$CONF" << EOF
<VirtualHost 0.0.0.0:80>
<Directory "/var/www/html">
    AllowOverride All
    Options MultiViews Indexes SymLinksIfOwnerMatch IncludesNoExec FollowSymLinks
    Require all granted
</Directory>

    ServerAdmin    webmaster@${HOSTNAME}
    ServerName     ${HOSTNAME}
    DocumentRoot   /var/www/html
    UseCanonicalName Off
    Options -ExecCGI -Includes
    ErrorLog logs/error_log
    CustomLog logs/${HOSTNAME}_log combined
    RewriteEngine on
    RewriteCond %{SERVER_NAME} =${HOSTNAME}
    RewriteRule ^ https://%{SERVER_NAME}%{REQUEST_URI} [END,NE,R=permanent]
</VirtualHost>

<IfModule mod_ssl.c>
<VirtualHost 0.0.0.0:443>
<Directory "/var/www/html">
    AllowOverride All
    Options MultiViews Indexes SymLinksIfOwnerMatch IncludesNoExec FollowSymLinks
    Require all granted
</Directory>

    ServerAdmin    webmaster@${HOSTNAME}
    ServerName     ${HOSTNAME}
    DocumentRoot   /var/www/html
    UseCanonicalName Off
    Options -ExecCGI -Includes
    ErrorLog logs/error_log
    CustomLog logs/${HOSTNAME}_log combined
    <IfModule mod_ruid2.c>
        RUidGid nobody nobody
    </IfModule>
${SSL_BLOCK}
</VirtualHost>
</IfModule>
EOF

echo "  Testing httpd config..."
if apachectl configtest 2>/dev/null; then
    systemctl reload httpd
    echo "  Done — ${HOSTNAME} default host created and httpd reloaded."
else
    echo "  ERROR: httpd config test failed — reload skipped"
    apachectl configtest
    exit 1
fi
