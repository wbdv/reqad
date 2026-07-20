#!/bin/bash

VERSION='0.0.4 - Jan 11, 2022'

WHITE='\033[1;37m'
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BLUE='\033[0;33m'
NC='\033[0m' # No Color

echo -ne "${YELLOW}"
echo -e "________________________________________________________________________________\n"
echo " Magento 2.4.3 - server install"
echo -e "________________________________________________________________________________\n"
echo -ne "${NC}"

if [ "$EUID" -ne 0 ]
    then echo "Please run as root"  1>&2
    exit 1
fi

echo "
- Nginx    (1.21)      
- PHP      (7.4)
- MariaDB  (10.4)
- Varnish  (6.5) 
- composer (2.x)
- Redis    (6.x)
- ElasticSearch (7.x)
- RabbitMQ (3.x)
"




#######################################################################################
#
#  nginx

if ! systemctl is-active --quiet nginx; then
    if [ "$(systemctl list-unit-files "nginx.service" | grep "nginx.service" | awk {'print $2'})" == "enabled" ]; then
        (systemctl start nginx) >> ./install_magento234.log 2>&1
    fi
fi
if ! systemctl is-active --quiet nginx; then
echo -n "Installing Nginx                    "

#install nginx 1.x from v106
sed -i "/\[epel\]/aexclude=nginx*" /etc/yum.repos.d/epel.repo
(yum install -y nginx certbot-nginx) >> ./install_magento234.log 2>&1
curl -s https://ssl-config.mozilla.org/ffdhe2048.txt > /etc/nginx/dhparams.pem
sed -i 's/\/var\/log\/nginx\/\*\.log/\/var\/log\/nginx\/\*log/' /etc/logrotate.d/nginx
truncate -s 0 /etc/nginx/conf.d/default*

echo "
#load_module modules/ngx_http_modsecurity_module.so;
#load_module modules/ngx_http_geoip2_module.so;
#load_module modules/ngx_pagespeed.so;

user  nginx;
worker_processes  12;

error_log  /var/log/nginx/error.log warn;
pid        /var/run/nginx.pid;

worker_rlimit_nofile 33000;
events {
    worker_connections  16384;
}


http {
    include       /etc/nginx/mime.types;
    default_type  application/octet-stream;
    
#    geoip2 /usr/share/GeoIP/GeoLite2-Country.mmdb {
#        \$geoip2_data_country_code default=US source=\$remote_addr country iso_code;
#    }
#
#    log_format  main  '\$remote_addr \$geoip2_data_country_code - \$remote_user [\$time_local] \$request '
#                      '\$status \$body_bytes_sent \$http_referer '
#                      '\$http_user_agent \$http_x_forwarded_for';


    log_format  main  '\$remote_addr - \$remote_user [\$time_local] \"\$request\" '
                      '\$status \$body_bytes_sent \"\$http_referer\" '
                      '\"\$http_user_agent\" \"\$http_x_forwarded_for\"';

    access_log  /var/log/nginx/access.log  main;


    sendfile                        on;
    #sendfile                      off;
    #tcp_nopush                     on;

    keepalive_timeout               65;

    fastcgi_buffer_size            1024k;
    fastcgi_buffers                1024 4k;

    fastcgi_connect_timeout        600s;
    fastcgi_send_timeout           600s;
    fastcgi_read_timeout           600s;

    proxy_buffer_size              512k;
    proxy_buffers                  8 1024k;
    proxy_busy_buffers_size        1024k;

    proxy_connect_timeout          3600;
    proxy_send_timeout             3600;
    proxy_read_timeout             3600;
    send_timeout                   3600;
    
    open_file_cache                max=200000 inactive=20s;
    open_file_cache_errors         on;
    open_file_cache_min_uses       2;
    open_file_cache_valid          30s;
    port_in_redirect               off;
    reset_timedout_connection      on;
    server_name_in_redirect        off;
    server_names_hash_bucket_size  1024;
    server_names_hash_max_size     1024;
    tcp_nodelay                    on;
    tcp_nopush                     on;
    types_hash_max_size            2048;

    gzip  on;
    gzip_disable \"msie6\";

    gzip_comp_level 6;
    gzip_min_length 1100;
    gzip_buffers 16 8k;
    gzip_proxied any;
    gzip_types
        text/plain
        text/css
        text/js
        text/xml
        text/javascript
        application/javascript
        application/x-javascript
        application/json
        application/xml
        application/xml+rss
        image/svg+xml;
    gzip_vary on;
    
    brotli on;
    brotli_static on;
    brotli_buffers 16 8k;
    brotli_comp_level 6;
    brotli_types
        text/css
        text/javascript
        text/xml
        text/plain
        text/x-component
        application/javascript
        application/x-javascript
        application/json
        application/xml
        application/rss+xml
        application/vnd.ms-fontobject
        font/truetype
        font/opentype
        image/svg+xml;
        
    ssl_session_cache    shared:SSL:50m;
    ssl_session_timeout  24h;
    ssl_protocols        TLSv1.2 TLSv1.3;
    ssl_ciphers          'ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-ECDSA-AES128-SHA:ECDHE-ECDSA-AES256-SHA:ECDHE-ECDSA-AES128-SHA256:ECDHE-ECDSA-AES256-SHA384:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-RSA-AES128-SHA:ECDHE-RSA-AES256-SHA:ECDHE-RSA-AES128-SHA256:ECDHE-RSA-AES256-SHA384';
    ssl_prefer_server_ciphers  on;
    resolver 8.8.8.8 8.8.4.4 valid=300s;
    resolver_timeout 30s;
  
    #set_real_ip_from 103.21.244.0/22;
    #set_real_ip_from 103.22.200.0/22;
    #set_real_ip_from 103.31.4.0/22;
    #set_real_ip_from 104.16.0.0/12;
    #set_real_ip_from 108.162.192.0/18;
    #set_real_ip_from 131.0.72.0/22;
    #set_real_ip_from 141.101.64.0/18;
    #set_real_ip_from 162.158.0.0/15;
    #set_real_ip_from 172.64.0.0/13;
    #set_real_ip_from 173.245.48.0/20;
    #set_real_ip_from 188.114.96.0/20;
    #set_real_ip_from 190.93.240.0/20;
    #set_real_ip_from 197.234.240.0/22;
    #set_real_ip_from 198.41.128.0/17;
    #real_ip_header CF-Connecting-IP;
    #real_ip_header X-Forwarded-For;
    #deny IP; 

    include /etc/nginx/conf.d/*.conf;
}" > /etc/nginx/nginx.conf

(systemctl enable nginx --now) >> ./install_magento234.log 2>&1 
if systemctl is-active --quiet nginx; then
    echo -e "[ ${GREEN}OK${NC} ]"
else
    echo -e "[ ${RED}ERROR${NC} ]"
    systemctl status nginx
    exit
fi
else
    echo -ne "[${GREEN} RUNNING ${NC}] "
#    echo -n `nginx -v`
    echo -n 'Nginx '
    echo -n `rpm -qi nginx | grep 'Version' | awk {'print $3'}`
fi
echo


#######################################################################################
#
#  php-fpm

if ! systemctl is-active --quiet php-fpm; then
    if [ "$(systemctl list-unit-files "php-fpm.service" | grep "php-fpm.service" | awk {'print $2'})" == "enabled" ]; then
        (systemctl start php-fpm) >> ./install_magento234.log 2>&1
    fi
fi
if ! systemctl is-active --quiet php-fpm; then
echo -n "Installing php-fpm                  "
(yum-config-manager --enable remi-php74) >> ./install_magento234.log 2>&1
(yum-config-manager --enable remi) >> ./install_magento234.log 2>&1

(yum install -y php-fpm php-xml php-pdo php-mysqlnd php-mysql php-mbstring php-pear php-gd php-mcrypt php-intl php-process  php-soap php-pecl-redis php-pecl-igbinary php-common php-json php-cli php-xml php-bcmath  php-pecl-zip php-opcache composer php-sodium) >> ./install_magento234.log 2>&1

sed -i 's/;date.timezone =/date.timezone = Europe\/Bucharest/' /etc/php.ini
sed -i 's/memory_limit = 128M/memory_limit = 2048M/' /etc/php.ini
sed -i 's/post_max_size = 8M/post_max_size = 64M/' /etc/php.ini
sed -i 's/upload_max_filesize = 2M/upload_max_filesize = 64M/' /etc/php.ini
sed -i 's/; max_input_vars = 1000/max_input_vars = 5000/' /etc/php.ini
sed -i 's/max_execution_time = 30/max_execution_time = 3600/' /etc/php.ini
sed -i 's/error_reporting = E_ALL & ~E_DEPRECATED & ~E_STRICT/error_reporting = E_ALL \& ~E_NOTICE \& ~E_DEPRECATED \& ~E_STRICT/' /etc/php.ini


echo "[www]
user = nginx
group = nginx

listen = /run/php-fpm.sock
listen.allowed_clients = 127.0.0.1
listen.owner = nginx
listen.group = nginx
listen.mode = 0660

pm = dynamic
pm.max_children = 50
pm.start_servers = 1
pm.min_spare_servers = 1
pm.max_spare_servers = 1
" > /etc/php-fpm.d/www.conf


sed -i 's/;opcache.save_comments=1/opcache.save_comments=1/' /etc/php.d/10-opcache.ini
sed -i 's/opcache.interned_strings_buffer=8/opcache.interned_strings_buffer=32/' /etc/php.d/10-opcache.ini
sed -i 's/opcache.memory_consumption=128/opcache.memory_consumption=1024/' /etc/php.d/10-opcache.ini
sed -i 's/opcache.max_accelerated_files=4000/opcache.max_accelerated_files=1000000/' /etc/php.d/10-opcache.ini

chmod -R a+rwx /var/lib/php/session/
(systemctl enable php-fpm --now) >> ./install_magento234.log 2>&1

if systemctl is-active --quiet php-fpm; then
    echo -e "[ ${GREEN}OK${NC} ]"
else
    echo -e "[ ${RED}ERROR${NC} ]"
    systemctl status php-fpm 
    exit
fi
else
    echo -ne "[${GREEN} RUNNING ${NC}] "
#    echo -n `php-fpm -v | head -n 1`
    echo -n 'PHP '
    echo -n `rpm -qi php-fpm | grep 'Version' | awk {'print $3'}`
fi
echo




#######################################################################################
#
#   mariadb

if ! systemctl is-active --quiet mariadb; then
    if [ "$(systemctl list-unit-files "mariadb.service" | grep "mariadb.service" | awk {'print $2'})" == "enabled" ]; then
        (systemctl start mariadb) >> ./install_magento234.log 2>&1
    fi
fi
if ! systemctl is-active --quiet mariadb; then
echo -n "Installing MariaDB                  "

echo '# MariaDB 10.4 CentOS repository list
# https://downloads.mariadb.org/mariadb/repositories/
[mariadb]
name = MariaDB
baseurl = https://mirrors.chroot.ro/mariadb/yum/10.4/centos7-amd64
#baseurl = https://yum.mariadb.org/10.4/centos7-amd64
gpgkey=https://yum.mariadb.org/RPM-GPG-KEY-MariaDB
gpgcheck=1' > /etc/yum.repos.d/MariaDB.repo
(yum install -y MariaDB-server MariaDB-client which perl-Text-Template) >> ./install_magento234.log 2>&1
(systemctl enable --now mariadb) >> ./install_magento234.log 2>&1

PASSWORD=`head -n 10 /dev/urandom | tr -cd '[:alnum:]!@#$%^&*()+-0123456789' | paste -sd - | sed 's/[\t, ]//g' | cut -b -20`

SECURE_MYSQL=$(expect -c "

set timeout 10
spawn mysql_secure_installation

expect \"Enter current password for root\"
send \"\r\"

expect \"Switch to unix_socket authentication\"
send \"n\r\"

expect \"Change the root password?\"
send \"y\r\"

expect \"New password\"
send \"${PASSWORD}\r\"

expect \"Re-enter new password\"
send \"${PASSWORD}\r\"

expect \"Remove anonymous users?\"
send \"y\r\"

expect \"Disallow root login remotely?\"
send \"y\r\"

expect \"Remove test database and access to it?\"
send \"y\r\"

expect \"Reload privilege tables now?\"
send \"y\r\"

expect eof
")

(echo "$SECURE_MYSQL") >> ./install_magento234.log 2>&1

echo "[client]
#host=
#database=
user=\"root\"
password=\"${PASSWORD}\"
" > /root/.my.cnf

(systemctl stop mariadb) >> ./install_magento234.log 2>&1
sed -i "/\[mysqld\]/a\ \nlog-error = /var/log/mysqld.log\nbind-address = 127.0.0.1\nlocal-infile = 0\nperformance-schema = 0\nsymbolic-links = 0\nmax_connections = 200\ntable_open_cache=10000\nquery_cache_size=0\nquery_cache_type=0\nquery_cache_limit=256M\nmax_allowed_packet=128M" /etc/my.cnf.d/server.cnf
sed -i "s/LimitNOFILE=/LimitNOFILE=160255/" /usr/lib/systemd/system/mariadb.service
touch /var/log/mysqld.log
chown mysql:mysql /var/log/mysqld.log
(systemctl daemon-reload) >> ./install_magento234.log 2>&1
(systemctl start mariadb) >> ./install_magento234.log 2>&1

wget -q https://github.com/major/MySQLTuner-perl/raw/master/mysqltuner.pl
sed -i 's/"color"          => 0/"color"          => 1/' mysqltuner.pl
chmod +x mysqltuner.pl

if systemctl is-active --quiet mariadb; then
    echo -e "[ ${GREEN}OK${NC} ]"
else
    echo -e "[ ${RED}ERROR${NC} ]"
    systemctl status mariadb 
    exit
fi
else
    echo -ne "[${GREEN} RUNNING ${NC}] "
#    echo -n `mysql -V | head -n 1`
    echo -n 'MariaDB '
    echo -n `rpm -qi MariaDB-server | grep 'Version' | awk {'print $3'}`

fi
echo




#######################################################################################
#
#   varnish

if ! systemctl is-active --quiet varnish; then
    if [ "$(systemctl list-unit-files "varnish.service" | grep "varnish.service" | awk {'print $2'})" == "enabled" ]; then
        (systemctl start varnish) >> ./install_magento234.log 2>&1
    fi
fi
if ! systemctl is-active --quiet varnish; then
echo -n "Installing varnish                  "

(curl -s https://packagecloud.io/install/repositories/varnishcache/varnish65/script.rpm.sh | sudo bash) >> ./install_magento234.log 2>&1
(yum install -y varnish) >> ./install_magento234.log 2>&1
sed -i 's/ExecStart=\/usr\/sbin\/varnishd -a :6081 -f \/etc\/varnish\/default.vcl -s malloc,256m/ExecStart=\/usr\/sbin\/varnishd -a 127.0.0.1:6081 -f \/etc\/varnish\/default.vcl -p http_resp_hdr_len=65536 -s malloc,1024m -T localhost:6082/' /usr/lib/systemd/system/varnish.service

cat << EOF > /etc/varnish/default.vcl
# VCL version 5.0 is not supported so it should be 4.0 even though actually used Varnish version is 6
vcl 4.0;

import std;
# The minimal Varnish version is 6.0
# For SSL offloading, pass the following header in your proxy server or load balancer: 'X-Forwarded-Proto: https'

backend default {
    .host = "127.0.0.1";
    .port = "8080";
    .first_byte_timeout = 600s;
    .connect_timeout = 10s;
    .probe = {
        .url = "/health_check.php";
        .timeout = 2s;
        .interval = 5s;
        .window = 10;
        .threshold = 5;
   }
}

acl purge {
    "localhost";
}

sub vcl_recv {
    if (req.restarts > 0) {
        set req.hash_always_miss = true;
    }

    if (req.method == "PURGE") {
        if (client.ip !~ purge) {
            return (synth(405, "Method not allowed"));
        }
        # To use the X-Pool header for purging varnish during automated deployments, make sure the X-Pool header
        # has been added to the response in your backend server config. This is used, for example, by the
        # capistrano-magento2 gem for purging old content from varnish during it's deploy routine.
        if (!req.http.X-Magento-Tags-Pattern && !req.http.X-Pool) {
            return (synth(400, "X-Magento-Tags-Pattern or X-Pool header required"));
        }
        if (req.http.X-Magento-Tags-Pattern) {
          ban("obj.http.X-Magento-Tags ~ " + req.http.X-Magento-Tags-Pattern);
        }
        if (req.http.X-Pool) {
          ban("obj.http.X-Pool ~ " + req.http.X-Pool);
        }
        return (synth(200, "Purged"));
    }

    if (req.method != "GET" &&
        req.method != "HEAD" &&
        req.method != "PUT" &&
        req.method != "POST" &&
        req.method != "TRACE" &&
        req.method != "OPTIONS" &&
        req.method != "DELETE") {
          /* Non-RFC2616 or CONNECT which is weird. */
          return (pipe);
    }

    # We only deal with GET and HEAD by default
    if (req.method != "GET" && req.method != "HEAD") {
        return (pass);
    }

    # Bypass shopping cart, checkout and search requests
    if (req.url ~ "/checkout" || req.url ~ "/catalogsearch") {
        return (pass);
    }

    # Bypass health check requests
    if (req.url ~ "/pub/health_check.php") {
        return (pass);
    }

    # Set initial grace period usage status
    set req.http.grace = "none";

    # normalize url in case of leading HTTP scheme and domain
    set req.url = regsub(req.url, "^http[s]?://", "");

    # collect all cookies
    std.collect(req.http.Cookie);

    # Compression filter. See https://www.varnish-cache.org/trac/wiki/FAQ/Compression
    if (req.http.Accept-Encoding) {
        if (req.url ~ "\.(jpg|jpeg|png|gif|gz|tgz|bz2|tbz|mp3|ogg|swf|flv)$") {
            # No point in compressing these
            unset req.http.Accept-Encoding;
        } elsif (req.http.Accept-Encoding ~ "gzip") {
            set req.http.Accept-Encoding = "gzip";
        } elsif (req.http.Accept-Encoding ~ "deflate" && req.http.user-agent !~ "MSIE") {
            set req.http.Accept-Encoding = "deflate";
        } else {
            # unknown algorithm
            unset req.http.Accept-Encoding;
        }
    }

    # Remove all marketing get parameters to minimize the cache objects
    if (req.url ~ "(\?|&)(gclid|cx|ie|cof|siteurl|zanpid|origin|fbclid|mc_[a-z]+|utm_[a-z]+|_bta_[a-z]+)=") {
        set req.url = regsuball(req.url, "(gclid|cx|ie|cof|siteurl|zanpid|origin|fbclid|mc_[a-z]+|utm_[a-z]+|_bta_[a-z]+)=[-_A-z0-9+()%.]+&?", "");
        set req.url = regsub(req.url, "[?|&]+$", "");
    }

    # Static files caching
    if (req.url ~ "^/(pub/)?(media|static)/") {
        # Static files should not be cached by default
        return (pass);

        # But if you use a few locales and don't use CDN you can enable caching static files by commenting previous line (#return (pass);) and uncommenting next 3 lines
        #unset req.http.Https;
        #unset req.http.X-Forwarded-Proto;
        #unset req.http.Cookie;
    }

    # Authenticated GraphQL requests should not be cached by default
    if (req.url ~ "/graphql" && req.http.Authorization ~ "^Bearer") {
        return (pass);
    }

    return (hash);
}

sub vcl_hash {
    if (req.http.cookie ~ "X-Magento-Vary=") {
        hash_data(regsub(req.http.cookie, "^.*?X-Magento-Vary=([^;]+);*.*$", "\1"));
    }

    # For multi site configurations to not cache each other's content
    if (req.http.host) {
        hash_data(req.http.host);
    } else {
        hash_data(server.ip);
    }

    # To make sure http users don't see ssl warning
    if (req.http.X-Forwarded-Proto) {
        hash_data(req.http.X-Forwarded-Proto);
    }


    if (req.url ~ "/graphql") {
        call process_graphql_headers;
    }
}

sub process_graphql_headers {
    if (req.http.Store) {
        hash_data(req.http.Store);
    }
    if (req.http.Content-Currency) {
        hash_data(req.http.Content-Currency);
    }
}

sub vcl_backend_response {

    set beresp.grace = 3d;

    if (beresp.http.content-type ~ "text") {
        set beresp.do_esi = true;
    }

    if (bereq.url ~ "\.js$" || beresp.http.content-type ~ "text") {
        set beresp.do_gzip = true;
    }

    if (beresp.http.X-Magento-Debug) {
        set beresp.http.X-Magento-Cache-Control = beresp.http.Cache-Control;
    }

    # cache only successfully responses and 404s
    if (beresp.status != 200 && beresp.status != 404) {
        set beresp.ttl = 0s;
        set beresp.uncacheable = true;
        return (deliver);
    } elsif (beresp.http.Cache-Control ~ "private") {
        set beresp.uncacheable = true;
        set beresp.ttl = 86400s;
        return (deliver);
    }

    # validate if we need to cache it and prevent from setting cookie
    if (beresp.ttl > 0s && (bereq.method == "GET" || bereq.method == "HEAD")) {
        unset beresp.http.set-cookie;
    }

   # If page is not cacheable then bypass varnish for 2 minutes as Hit-For-Pass
   if (beresp.ttl <= 0s ||
       beresp.http.Surrogate-control ~ "no-store" ||
       (!beresp.http.Surrogate-Control &&
       beresp.http.Cache-Control ~ "no-cache|no-store") ||
       beresp.http.Vary == "*") {
        # Mark as Hit-For-Pass for the next 2 minutes
        set beresp.ttl = 120s;
        set beresp.uncacheable = true;
    }

    return (deliver);
}

sub vcl_deliver {
    if (resp.http.X-Magento-Debug) {
        if (resp.http.x-varnish ~ " ") {
            set resp.http.X-Magento-Cache-Debug = "HIT";
            set resp.http.Grace = req.http.grace;
        } else {
            set resp.http.X-Magento-Cache-Debug = "MISS";
        }
    } else {
        unset resp.http.Age;
    }

    # Not letting browser to cache non-static files.
    if (resp.http.Cache-Control !~ "private" && req.url !~ "^/(pub/)?(media|static)/") {
        set resp.http.Pragma = "no-cache";
        set resp.http.Expires = "-1";
        set resp.http.Cache-Control = "no-store, no-cache, must-revalidate, max-age=0";
    }

    unset resp.http.X-Magento-Debug;
    unset resp.http.X-Magento-Tags;
    unset resp.http.X-Powered-By;
    unset resp.http.Server;
    unset resp.http.X-Varnish;
    unset resp.http.Via;
    unset resp.http.Link;
}

sub vcl_hit {
    if (obj.ttl >= 0s) {
        # Hit within TTL period
        return (deliver);
    }
    if (std.healthy(req.backend_hint)) {
        if (obj.ttl + 300s > 0s) {
            # Hit after TTL expiration, but within grace period
            set req.http.grace = "normal (healthy server)";
            return (deliver);
        } else {
            # Hit after TTL and grace expiration
            return (restart);
        }
    } else {
        # server is not healthy, retrieve from cache
        set req.http.grace = "unlimited (unhealthy server)";
        return (deliver);
    }
}
EOF

(systemctl daemon-reload) >> ./install_magento234.log 2>&1
(systemctl enable --now varnish) >> ./install_magento234.log 2>&1

if systemctl is-active --quiet varnish; then
    echo -e "[ ${GREEN}OK${NC} ]"
else
    echo -e "[ ${RED}ERROR${NC} ]"
    systemctl status varnish 
    exit
fi
else
    echo -ne "[${GREEN} RUNNING ${NC}] "
    #echo -n `varnishd -V 2>&1 | head -n 1`
    echo -n 'Varnish '
    echo -n `rpm -qi varnish | grep 'Version' | awk {'print $3'}`

fi
echo




#######################################################################################
#
#   redis


if ! systemctl is-active --quiet redis; then
    if [ "$(systemctl list-unit-files "redis.service" | grep "redis.service" | awk {'print $2'})" == "enabled" ]; then
        (systemctl start redis) >> ./install_magento234.log 2>&1
    fi
fi
if ! systemctl is-active --quiet redis; then
echo -n "Installing redis                    "
(yum install -y redis) >> ./install_magento234.log 2>&1

sed -i 's/daemonize no/daemonize yes/' /etc/redis.conf
sed -i 's/pidfile \/var\/run\/redis_6379.pid/pidfile \/var\/run\/redis\/redis.pid/' /etc/redis.conf
sed -i 's/# maxmemory <bytes>/maxmemory 16GB/' /etc/redis.conf
sed -i 's/# maxmemory-policy noeviction/maxmemory-policy volatile-ttl/' /etc/redis.conf

rm -f /etc/redis/redis.conf
ln -s /etc/redis.conf /etc/redis/redis.conf

(systemctl enable --now redis) >> ./install_magento234.log 2>&1

if systemctl is-active --quiet redis; then
    echo -e "[ ${GREEN}OK${NC} ]"
else
    echo -e "[ ${RED}ERROR${NC} ]"
    systemctl status redis 
    exit
fi
else
    echo -ne "[${GREEN} RUNNING ${NC}] "
    #echo -n `redis-server -v 2>&1 | head -n 1 | awk {'print $1" "$2" "$3'}`
    echo -n 'Redis '
    echo -n `rpm -qi redis | grep 'Version' | awk {'print $3'}`
fi
echo




#######################################################################################
#
#   elasticsearch

if ! systemctl is-active --quiet elasticsearch; then
    if [ "$(systemctl list-unit-files "elasticsearch.service" | grep "elasticsearch.service" | awk {'print $2'})" == "enabled" ]; then
        (systemctl start elasticsearch) >> ./install_magento234.log 2>&1
    fi
fi
if ! systemctl is-active --quiet elasticsearch; then
echo -n "Installing ElasticSearch            "

echo "[elasticsearch]
name=Elasticsearch repository for 7.x packages
baseurl=https://artifacts.elastic.co/packages/7.x/yum
gpgcheck=1
gpgkey=https://artifacts.elastic.co/GPG-KEY-elasticsearch
enabled=1
autorefresh=1
type=rpm-md" > /etc/yum.repos.d/elasticsearch.repo

(yum install -y elasticsearch) >> ./install_magento234.log 2>&1
echo "cluster.routing.allocation.disk.threshold_enabled: false
discovery.type: single-node
" >> /etc/elasticsearch/elasticsearch.yml

mkdir -p /etc/elasticsearch/jvm.options.d/
echo "-Xms2g
-Xmx2g" > /etc/elasticsearch/jvm.options.d/memory.options


(systemctl daemon-reload) >> ./install_magento234.log 2>&1
(systemctl enable --now elasticsearch) >> ./install_magento234.log 2>&1
#curl -s -XPUT 'http://localhost:9200/_settings' -H 'Content-Type: application/json' -d '{ "index" : { "number_of_replicas" : 0 } }'

(curl -s -XPUT "http://localhost:9200/_template/default_template" -H 'Content-Type: application/json' -d'{ "index_patterns": ["*"], "order": -1, "settings": { "index": { "number_of_shards": "6", "number_of_replicas": 0 } } }') >> ./install_magento234.log 2>&1 

if systemctl is-active --quiet elasticsearch; then
    echo -e "[ ${GREEN}OK${NC} ]"
else
    echo -e "[ ${RED}ERROR${NC} ]"
    systemctl status elasticsearch 
    exit
fi
else
    echo -ne "[${GREEN} RUNNING ${NC}] "
    echo -n 'ElasticSearch '
    echo -n `rpm -qi elasticsearch | grep 'Version' | awk {'print $3'}`
fi
echo




#######################################################################################
#
#   rabbitmq

if ! systemctl is-active --quiet rabbitmq-server; then
    if [ "$(systemctl list-unit-files "rabbitmq-server.service" | grep "rabbitmq-server.service" | awk {'print $2'})" == "enabled" ]; then
        (systemctl start rabbitmq-server) >> ./install_magento234.log 2>&1
    fi
fi
if ! systemctl is-active --quiet rabbitmq-server; then
echo -n "Installing RabbitMQ                 "
(curl -s https://packagecloud.io/install/repositories/rabbitmq/erlang/script.rpm.sh | sudo bash) >> ./install_magento234.log 2>&1
(curl -s https://packagecloud.io/install/repositories/rabbitmq/rabbitmq-server/script.rpm.sh | sudo bash) >> ./install_magento234.log 2>&1
(yum install -y rabbitmq-server) >> ./install_magento234.log 2>&1
echo "listeners.tcp.local = 127.0.0.1:5672" > /etc/rabbitmq/rabbitmq.conf
(systemctl enable --now rabbitmq-server) >> ./install_magento234.log 2>&1
if systemctl is-active --quiet rabbitmq-server; then
    echo -e "[ ${GREEN}OK${NC} ]"
else
    echo -e "[ ${RED}ERROR${NC} ]"
    systemctl status rabbitmq-server
    exit
fi
else
    echo -ne "[${GREEN} RUNNING ${NC}] "
    echo -n 'RabbitMQ '
    echo -n `rpm -qi rabbitmq-server | grep 'Version' | awk {'print $3'}`
fi
echo


    mkdir -p '/usr/local/reqad/etc/';
    ini_file='/usr/local/reqad/etc/server-software.ini';
    echo '[reqad]
template=magento-2.4.3
accounts=1
quota=0

[web]' > $ini_file;
    echo -n 'nginx=' >> $ini_file;
    echo `rpm -qi nginx | grep 'Version' | awk {'print $3'}` >> $ini_file;
    echo -n 'php=' >> $ini_file;
    echo `rpm -qi php-fpm | grep 'Version' | awk {'print $3'}` >> $ini_file;
    echo -n 'mariadb=' >> $ini_file;
    echo `rpm -qi MariaDB-server | grep 'Version' | awk {'print $3'}` >> $ini_file;
    echo -n 'varnish=' >> $ini_file;
    echo `rpm -qi varnish | grep 'Version' | awk {'print $3'}` >> $ini_file;
    echo -n 'redis=' >> $ini_file;
    echo `rpm -qi redis | grep 'Version' | awk {'print $3'}` >> $ini_file;
    echo -n 'elasticsearch=' >> $ini_file;
    echo `rpm -qi elasticsearch | grep 'Version' | awk {'print $3'}` >> $ini_file;
    echo -n 'rabbidmq=' >> $ini_file;
    echo `rpm -qi rabbitmq-server | grep 'Version' | awk {'print $3'}` >> $ini_file;

