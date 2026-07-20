#!/bin/bash

VERSION='0.0.18 - Apr 17, 2022'

SSH_PORT='1422'
SSH_KEY=''
MM_LICENSE_KEY=''

WHITE='\033[1;37m'
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BLUE='\033[0;33m'
NC='\033[0m' # No Color

echo -ne "${YELLOW}"
echo -e "________________________________________________________________________________\n"
echo " Reqad install script version ${VERSION}"
echo -e "________________________________________________________________________________\n"
echo -ne "${NC}"

if [ "$EUID" -ne 0 ]
    then echo "Please run as root"  1>&2
    exit 1
fi

IP=`ip address show | grep 'scope global' | grep 'inet ' | head -n 1 | awk {'print $2'} | awk -F/ {'print $1'}`
#HOSTNAME=`hostname`
HOSTNAME=`hostnamectl status | grep 'Static hostname:' | awk {'print $3'}`
#REVERSE=`hostname -i`
REVERSE=`dig +short A ${HOSTNAME}`
ARCH=`hostnamectl status | grep 'Architecture:' | awk {'print $2'}`
OS=`hostnamectl status | grep 'Operating System:' | awk {'print $3 " " $4 " " $5'}`
MEMORY=`free -hmw --si | grep 'Mem:' | awk {'print $2'}`
CPU=`lscpu | grep 'Model name' | awk -F\: {'print $2'} | awk '{$1=$1};1'`
VCORE=`lscpu | grep -e '^CPU(s):' | awk -F\: '{print $2}' | awk '{$1=$1};1'`
DISKSPACE=`lsblk | grep 'disk' | awk {'print $4'}`
SESTATUS=`sestatus | awk {'print $3'}`

echo "        IP Address: ${IP}"
hostnamectl status | grep -E --color=none 'Static hostname|Operating System|Kernel|Architecture|Virtualization'
echo "         Processor: ${CPU}"
echo "            vCores: ${VCORE}"
echo "            Memory: ${MEMORY}"
echo "        Disk Space: ${DISKSPACE}"

if [[ "${ARCH}" != "x86-64" ]]
    then echo -e "\n ${RED}ERROR: Detected architecture ${ARCH}. Only x86-64 architecture is supported.${NC}\n"  1>&2
    exit 1
fi

if [[ "${OS}" != "CentOS Linux 7" ]]
    then echo -e "\n ${RED}ERROR: Detected operating system: ${OS}. Only CentOS Linux 7 is supported.${NC}\n" 1>&2
    exit 1
fi

if [[ "${REVERSE}" == "" ]]; then
    REVERSE='unknown'
fi
if [[ "${IP}" != "${REVERSE}" ]]
    then echo -e "\n ${RED}ERROR: Reverse IP for ${HOSTNAME} is ${REVERSE} instead of ${IP}.${NC}\n" 1>&2
    exit 1
fi

echo -e "${GREEN}"
echo -e "________________________________________________________________________________\n"
echo -e " All ok, proceed to instalation."
echo -e "________________________________________________________________________________\n"
echo -ne "${NC}"


if [ "$1" == "--skip-update" ]; then
    echo "... skip yum update and install ...";
else
if [ ! -f "/etc/yum.repos.d/epel.repo" ]; then
    echo -n "Install epel                        ";
    (yum install -y epel-release) >> ./install_reqad.log 2>&1
    echo -e "[ ${GREEN}DONE${NC} ]"
fi

RPMNR=`rpm -qa | grep -E "^pigz|^htop|^git|^vim-7|^wget|^screen|^mlocate|^bind-utils|^yum-utils|^cronie|^unzip|^net-tools|^e2fsprogs|^bzip2|^curl|^cloud-utils-growpart|^expect|^bash-completion|^bash-completion-extras" | wc -l`
if [ "${RPMNR}" -lt 21 ]; then
    echo -n "Install the missing packages        ";
    (yum install -y pigz htop git vim wget screen mlocate bind-utils yum-utils cronie unzip net-tools e2fsprogs bzip2 curl cloud-utils-growpart deltarpm expect bash-completion bash-completion-extras) >> ./install_reqad.log 2>&1
    echo -e "[ ${GREEN}DONE${NC} ]"
fi

if [ ! -f "/root/.vimrc" ]; then
    echo "alias vi='vim'
    PS1=\"\[\033[01;92m\]\u@\h \W]# \[\033[00m\]\"
    " >> /root/.bashrc

    alias vi='vim'
    PS1="\[\033[01;92m\]\u@\h \W]# \[\033[00m\]"

    (wget -q https://www.vim.org/scripts/download_script.php?src_id=19394 -O /usr/share/vim/vim74/syntax/nginx.vim) >> ./install_reqad.log 2>&1
    (wget -q https://raw.githubusercontent.com/tmatilai/vim-monit/master/syntax/monitrc.vim -O /usr/share/vim/vim74/syntax/monit.vim) >> ./install_reqad.log 2>&1
    echo 'syntax on
autocmd BufRead,BufNewFile /etc/php-fpm.conf set syntax=dosini
autocmd BufRead,BufNewFile /etc/php-fpm.d/*.conf set syntax=dosini
autocmd BufRead,BufNewFile /etc/nginx/*,/etc/nginx/conf.d/*,/usr/local/nginx/conf/*,*/conf/nginx.conf set syntax=nginx
autocmd BufRead,BufNewFile /etc/monit.d/*,/etc/monitrc set syntax=monit
set background=light
highlight Comment ctermfg=darkgrey
filetype plugin indent on
set tabstop=4
" when indenting with '\>', use 4 spaces width
set shiftwidth=4
" On pressing tab, insert 4 spaces
set expandtab
set paste
' > /root/.vimrc
fi

#=[ yum updates ]=====================================================================================================
echo -n "Check for CentOS updates            "
(yum makecache fast) >> ./install_reqad.log
(yum -y update) >> ./install_reqad.log
echo -e "[ ${GREEN}DONE${NC} ]"

#=[ disable selinux ]=================================================================================================
if [[ "${SESTATUS}" != "disabled" ]]; then
    echo -n "Disable SELINUX                     "
    sed -i 's/SELINUX=enforcing/SELINUX=disabled/' /etc/selinux/config
    sed -i 's/SELINUX=permissive/SELINUX=disabled/' /etc/selinux/config
    setenforce 0
    echo -e "[ ${GREEN}DONE${NC} ]"
fi

#=[ qemu & fstrim ]===================================================================================================
    (yum install -y qemu-guest-agent && systemctl enable --now qemu-guest-agent && systemctl enable --now fstrim.timer) >> ./install_reqad.log  2>&1
#fstrim -a
fi

#=[ ssh ]=============================================================================================================
if [ ! -f "/root/.ssh/authorized_keys" ] || [ ! -f "/root/.ssh/id_rsa" ]; then
    (ssh-keygen -f /root/.ssh/id_rsa -N "") >> ./install_reqad.log 2>&1
    (yum install -y openssh-server) >> ./install_reqad.log 2>&1
    sed -i 's/#Port 22/Port '"${SSH_PORT}"'/' /etc/ssh/sshd_config
    sed -i 's/#ListenAddress 0.0.0.0/ListenAddress 0.0.0.0/' /etc/ssh/sshd_config
    sed -i 's/PasswordAuthentication yes/PasswordAuthentication no/' /etc/ssh/sshd_config
    sed -i 's/#PermitRootLogin yes/PermitRootLogin without-password/' /etc/ssh/sshd_config
    sed -i 's/#UseDNS yes/UseDNS no/' /etc/ssh/sshd_config

    echo "${SSH_KEY}" >> /root/.ssh/authorized_keys
    chmod go-rwx .ssh/authorized_keys

    (systemctl enable sshd) >> ./install_reqad.log 2>&1
    (systemctl restart sshd) >> ./install_reqad.log 2>&1
fi

#=[ disable ipv6 ]====================================================================================================
#echo "net.ipv6.conf.all.disable_ipv6 = 1
#net.ipv6.conf.default.disable_ipv6 = 1" >> /etc/sysctl.conf
#sysctl -p

#=[ csf ]=============================================================================================================
if [ ! -d "/etc/csf/" ]; then
echo -n "Install Configserv Firewall (csf)   "
(yum install -y wget perl-libwww-perl perl-Crypt-SSLeay perl-IO-Socket-SSL perl-LWP-Protocol-https) >> ./install_reqad.log 
(wget -q download.configserver.com/csf.tgz) >> ./install_reqad.log
(tar xzf csf.tgz) >> ./install_reqad.log
cd csf
#./csftest.pl
(./install.sh) >> ../install_reqad.log 2>&1
sed -i 's/TESTING = "1"/TESTING = "0"/' /etc/csf/csf.conf
sed -i 's/PT_LIMIT = "60"/PT_LIMIT = "0"/' /etc/csf/csf.conf
sed -i 's/PT_USERMEM = "512"/PT_USERMEM = "0"/' /etc/csf/csf.conf
sed -i 's/PT_USERTIME = "1800"/PT_USERTIME = "0"/' /etc/csf/csf.conf
sed -i 's/RESTRICT_SYSLOG = "0"/RESTRICT_SYSLOG = "3"/' /etc/csf/csf.conf
sed -i 's/SMTPAUTH_LOG = "\/var\/log\/secure"/SMTPAUTH_LOG = "\/var\/log\/exim\/main.log"/' /etc/csf/csf.conf
sed -i 's/CUSTOM1_LOG = "\/var\/log\/customlog"/CUSTOM1_LOG = "\/var\/log\/exim\/reject.log"/' /etc/csf/csf.conf
sed -i 's/LF_PERMBLOCK_ALERT = "1"/LF_PERMBLOCK_ALERT = "0"/' /etc/csf/csf.conf
sed -i 's/LF_NETBLOCK_ALERT = "1"/LF_NETBLOCK_ALERT = "0"/' /etc/csf/csf.conf
sed -i 's/LF_EMAIL_ALERT = "1"/LF_EMAIL_ALERT = "0"/' /etc/csf/csf.conf
sed -i 's/LF_TEMP_EMAIL_ALERT = "1"/LF_TEMP_EMAIL_ALERT = "0"/' /etc/csf/csf.conf


sed -i 's/MM_LICENSE_KEY = ""/MM_LICENSE_KEY = "'${MM_LICENSE_KEY}'"/' /etc/csf/csf.conf
#sed -i 's/CC_DENY = ""/CC_DENY = "CN,UA"/' /etc/csf/csf.conf
sed -i 's/CC_IGNORE = ""/CC_IGNORE = "RO"/' /etc/csf/csf.conf

cd ..
rm -rf csf*


echo "exe:/usr/sbin/nginx
exe:/usr/sbin/httpd
exe:/usr/sbin/mysqld
exe:/usr/libexec/mysqld
exe:/usr/bin/redis-server
exe:/usr/sbin/exim
exe:/usr/sbin/php-fpm
exe:/usr/sbin/varnishd
exe:/usr/libexec/dovecot/imap-login
exe:/usr/libexec/dovecot/pop3-login
exe:/usr/share/elasticsearch/jdk/bin/java
" >> /etc/csf/csf.pignore

echo "Include /etc/csf/cpanel.cloudflare.ignore
35.173.69.86	#freshping.io
18.179.133.14	#freshping.io
34.246.131.0	#freshping.io
13.251.205.206	#freshping.io
52.60.140.174	#freshping.io
13.55.57.184	#freshping.io
52.42.49.200	#freshping.io
13.232.175.73	#freshping.io
CENSE_KEY18.228.60.182 	#freshping.io
18.130.156.195	#freshping.io
" >> /etc/csf/csf.ignore

wget -q -O /etc/csf/cpanel.cloudflare.ignore https://www.cloudflare.com/ips-v4

echo "51.195.0.0/16           # OVH - do not delete
54.36.0.0/16            # OVH / AhrefsBot (FR/France/-) - do not delete
114.119.128.0/18        # Huawei (SG/Singapore/-) - do not delete
46.229.168.0/24         # SemRush (US/United States/-) - do not delete
185.191.171.0/24        # SemRush (CY/Cyprus) - do not delete
85.208.98.0/24          # SemRush (CY/Cyprus) - do not delete
54.173.235.254          # Rogerbot (US/United States/ec2-54-173-235-254.compute-1.amazonaws.com) - do not delete
3.89.142.158            # Rogerbot - do not delete
216.244.66.224/27       # Dotbot - do not delete
107.158.43.0/24         # Buyproxies.org - do not delete

46.4.68.227             # Webmeup - do not delete
88.198.17.136           # Webmeup - do not delete
176.9.4.111             # Webmeup - do not delete
176.9.4.106             # Webmeup - do not delete
176.9.4.102             # Webmeup - do not delete
176.9.9.125             # Webmeup - do not delete
176.9.4.108             # Webmeup - do not delete
176.9.9.94              # Webmeup - do not delete
176.9.4.105             # Webmeup - do not delete
176.9.4.107             # Webmeup - do not delete
176.9.1.234             # Webmeup - do not delete
176.9.1.27              # Webmeup - do not delete
46.4.122.197            # Webmeup - do not delete
46.4.122.196            # Webmeup - do not delete
176.9.5.87              # Webmeup - do not delete
176.9.4.101             # Webmeup - do not delete
176.9.2.212             # Webmeup - do not delete
46.4.122.146            # Webmeup - do not delete
78.46.93.48             # Webmeup - do not delete
94.130.64.80            # Webmeup - do not delete
94.130.18.162           # Webmeup - do not delete
94.130.9.166            # Webmeup - do not delete
94.130.9.115            # Webmeup - do not delete
94.130.9.185            # Webmeup - do not delete
94.130.10.89            # Webmeup - do not delete
94.130.9.106            # Webmeup - do not delete
94.130.66.99            # Webmeup - do not delete
94.130.64.96            # Webmeup - do not delete
94.130.9.116            # Webmeup - do not delete
94.130.16.50            # Webmeup - do not delete
94.130.18.151           # Webmeup - do not delete
94.130.12.118           # Webmeup - do not delete
94.130.66.60            # Webmeup - do not delete
94.130.9.183            # Webmeup - do not delete
94.130.18.161           # Webmeup - do not delete
94.130.18.160           # Webmeup - do not delete
94.130.16.31            # Webmeup - do not delete
94.130.18.163           # Webmeup - do not delete
178.63.13.146           # Webmeup - do not delete
94.130.34.225           # Webmeup - do not delete
116.202.128.228         # Webmeup - do not delete
136.243.21.181          # Webmeup - do not delete
168.119.4.44            # Webmeup - do not delete
49.12.131.247           # Webmeup - do not delete
78.46.86.157            # Webmeup - do not delete
136.243.103.251         # Webmeup - do not delete
136.243.104.54          # Webmeup - do not delete
188.40.110.183          # Webmeup - do not delete
78.46.62.249            # Webmeup - do not delete
78.46.62.238            # Webmeup - do not delete
157.90.177.229          # Webmeup - do not delete

" >> /etc/csf/csf.deny

# add port 2087 to csf TCP_IN
tcpinports=(`egrep '^TCP_IN =' /etc/csf/csf.conf | awk -F= {'print $2'} | sed 's/[ "]//g' | sed 's/,/ /g'`)
if [[ ! " ${tcpinports[*]} " =~ 2087 ]]; then
    sed -i 's/^TCP_IN = "\(.*\)"/TCP_IN = "\1,2087"/' /etc/csf/csf.conf
fi

(csf -r) >> ./install_reqad.log 
echo -e "[ ${GREEN}DONE${NC} ]"
fi

#==[ certbot ]===============================================
if [ ! -d "/etc/letsencrypt/" ]; then
    echo -n "Install certbot                     "
    (yum install -y certbot) >> ./install_reqad.log 2>&1
    #certbot -n --agree-tos -m ${EMAIL} register
    (certbot -n --agree-tos --register-unsafely-without-email register) >> ./install_reqad.log 2>&1
# todo renew
#    (systemctl enable --now certbot-renew.service certbot-renew.timer) >> ./install_reqad.log 2>&1
#    certbot certonly --nginx -d v234.webindex.ro --post-hook "systemctl restart nginx-reqad"
    echo -e "[ ${GREEN}DONE${NC} ]"
fi

if [ ! -d "/etc/letsencrypt/live/${HOSTNAME}/" ]; then
    echo -n "Get cert for ${HOSTNAME}               " | cut -b 1-36 | echo -n "$(</dev/stdin)"
    (certbot certonly -n --standalone -d ${HOSTNAME}) >> ./install_reqad.log 2>&1
    if [ -d "/etc/letsencrypt/live/${HOSTNAME}/" ]; then
        echo -e "[ ${GREEN}DONE${NC} ]"
    else
        echo -e "[ ${RED}ERROR${NC} ]"
    fi
fi

echo -n "Install Reqad Control Panel         "
if ! systemctl is-active --quiet nginx-reqad; then
(yum install -y https://rpms.remirepo.net/enterprise/remi-release-7.rpm) >> ./install_reqad.log 2>&1
(yum-config-manager --enable remi) >> ./install_reqad.log 2>&1

sed -i "/\[remi\]/aexclude=composer*" /etc/yum.repos.d/remi.repo

echo '[v106]
name=v106
baseurl=https://v106.webindex.ro/repo
enabled=1
gpgcheck=0' > /etc/yum.repos.d/v106.repo

#useradd -r -m -d /usr/local/reqad reqad
(yum install -y nginx-reqad php80-php-common php80-php-fpm php80-php-pdo php80-php-cli php80-php-process php80-php-xml sudo) >> ./install_reqad.log 2>&1
echo "reqad   ALL=(ALL)       NOPASSWD:ALL
" >> /etc/sudoers

> /etc/opt/remi/php80/php-fpm.d/www.conf

echo "[reqad]
user = reqad
group = reqad
listen = /run/php-fpm-reqad.sock
listen.allowed_clients = 127.0.0.1
listen.owner = reqad
listen.group = reqad
listen.mode = 0660

pm = dynamic
pm.max_children = 50
pm.start_servers = 2
pm.min_spare_servers = 2
pm.max_spare_servers = 5
slowlog = /var/opt/remi/php80/log/php-fpm/www-slow.log
php_admin_value[error_log] = /var/opt/remi/php80/log/php-fpm/www-error.log
php_admin_flag[log_errors] = on
php_admin_flag[short_open_tag] = on
php_value[session.save_handler] = files
php_value[session.save_path]    = /var/opt/remi/php80/lib/php/session
php_value[soap.wsdl_cache_dir]  = /var/opt/remi/php80/lib/php/wsdlcache
" > /etc/opt/remi/php80/php-fpm.d/reqad.conf
chmod -R a+rwx /var/opt/remi/php80/lib/php/session

(systemctl enable --now php80-php-fpm) >> ./install_reqad.log 2>&1

> /usr/local/nginx-reqad/conf.d/default.conf

echo "server {
    listen       0.0.0.0:2086;
    server_name  ${HOSTNAME};
    access_log off;
    error_log off;
    return         301 https://\$server_name:2087\$request_uri;
}

server {
    listen       0.0.0.0:2087 ssl http2;
    ssl_certificate      /etc/letsencrypt/live/${HOSTNAME}/fullchain.pem;
    ssl_certificate_key  /etc/letsencrypt/live/${HOSTNAME}/privkey.pem;
    server_name  ${HOSTNAME};
    access_log off;
    error_log off;
    root /usr/local/reqad/public_html;
    index index.php index.html index.htm;

    client_max_body_size 500M;

    location / {
        auth_basic            \"Restricted\";
        auth_basic_user_file  /usr/local/reqad/nginx_auth;

        try_files \$uri \$uri/ /index.php?\$args;

        location ~.*\.(3gp|gif|jpg|jpeg|png|ico|wmv|avi|asf|asx|mpg|mpeg|mp4|pls|mp3|mid|wav|swf|flv|html|htm|txt|js|css|exe|zip|tar|rar|gz|tgz|bz2|uha|7z|doc|docx|xls|xlsx|pdf|iso)\$ {
                  expires 7d;
                  try_files \$uri @backend;
        }
        location ~ [^/]\.php(/|\$) {
            fastcgi_split_path_info ^(.+?\.php)(/.*)\$;
            if (!-f \$document_root\$fastcgi_script_name) {
                return 404;
            }

            fastcgi_pass unix:/run/php-fpm-reqad.sock;
            #fastcgi_pass 127.0.0.1:9000;
            fastcgi_index index.php;
            include fastcgi_params;
            fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
            #fastcgi_intercept_errors on;
        }

        location /monit/ {
            rewrite ^/monit/(.*) /\$1 break;
            proxy_ignore_client_abort on;
            proxy_pass   https://127.0.0.1:2812;
            proxy_redirect  https://127.0.0.1:2812 /monit;
        }

        location /phpmyadmin/ {
           location ~.*\.(3gp|gif|jpg|jpeg|png|ico|wmv|avi|asf|asx|mpg|mpeg|mp4|pls|mp3|mid|wav|swf|flv|html|htm|txt|js|css|exe|zip|tar|rar|gz|tgz|bz2|uha|7z|doc|docx|xls|xlsx|pdf|iso)\$ {
                 expires 7d;
                 try_files \$uri @backend;
           }
           location ~ .*\.(php)?\$ {
                  proxy_pass http://127.0.0.1:8008;
                  include proxy.inc;
           }
           error_page 405 = @backend;
           proxy_pass http://127.0.0.1:8008;
           include proxy.inc;
        }
#           location /server-status {
#               location ~.*\.(3gp|gif|jpg|jpeg|png|ico|wmv|avi|asf|asx|mpg|mpeg|mp4|pls|mp3|mid|wav|swf|flv|html|htm|txt|js|css|exe|zip|tar|rar|gz|tgz|bz2|uha|7z|doc|docx|xls|xlsx|pdf|iso)\$ {
#                     expires 7d;
#                     try_files \$uri @backend;
#              }
#               error_page 405 = @backend;
#               proxy_pass http://127.0.0.1:8008;
#               include proxy.inc;
#            }
        location ~ /\.ht {
            deny all;
        }
    }
    location /webmail/ {
           location ~.*\.(3gp|gif|jpg|jpeg|png|ico|wmv|avi|asf|asx|mpg|mpeg|mp4|pls|mp3|mid|wav|swf|flv|html|htm|txt|js|css|exe|zip|tar|rar|gz|tgz|bz2|uha|7z|doc|docx|xls|xlsx|pdf|iso)\$ {
                  expires 7d;
                  try_files \$uri @backend;
           }
           error_page 405 = @backend;
           proxy_pass http://127.0.0.1:8008;
           include proxy.inc;
    }
    location /load.php {
           error_page 405 = @backend;
           proxy_pass http://127.0.0.1:8008;
           include proxy.inc;
    }
    location /mysql.php {
           error_page 405 = @backend;
           proxy_pass http://127.0.0.1:8008;
           include proxy.inc;
    }
    location /qsum.txt {
           error_page 405 = @backend;
           proxy_pass http://127.0.0.1:8008;
           include proxy.inc;
    }
    location /df.txt {
           error_page 405 = @backend;
           proxy_pass http://127.0.0.1:8008;
           include proxy.inc;
    }
    location @backend {
        internal;
        proxy_pass http://127.0.0.1:8008;
        include proxy.inc;
    }
}
" > /usr/local/nginx-reqad/conf.d/${HOSTNAME}.conf


echo "proxy_connect_timeout 59s;
proxy_send_timeout   600;
proxy_read_timeout   600;
proxy_buffer_size    64k;
proxy_buffers     16 32k;
proxy_busy_buffers_size 64k;
proxy_temp_file_write_size 64k;
proxy_pass_header Set-Cookie;
proxy_redirect     off;
proxy_hide_header  Vary;
proxy_set_header   Accept-Encoding '';
proxy_ignore_headers Cache-Control Expires;
proxy_set_header   Referer \$http_referer;
proxy_set_header   Host   \$host;
proxy_set_header   Cookie \$http_cookie;
proxy_set_header   X-Real-IP  \$remote_addr;
proxy_set_header X-Forwarded-Host \$host;
proxy_set_header X-Forwarded-Server \$host;
proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
" > /usr/local/nginx-reqad/proxy.inc

(systemctl enable nginx-reqad) >> ./install_reqad.log 2>&1
(systemctl restart nginx-reqad) >> ./install_reqad.log 2>&1
echo -e "[ ${GREEN}DONE${NC} ]"
else
    echo -e "[ ${RED}ALREADY RUNNING${NC} ]"
fi

mkdir -p /usr/local/reqad/
touch /usr/local/reqad/nginx_auth
PASSWORD=`head -n 10 /dev/urandom | tr -cd '[:alnum:]!@#$%^&*()+-0123456789' | paste -sd - | sed 's/[\t, ]//g' | cut -b -20`
echo -n 'reqad:' > /usr/local/reqad/nginx_auth
echo "${PASSWORD}" | /usr/bin/openssl11 passwd -6 --stdin >> /usr/local/reqad/nginx_auth
mkdir -p /usr/local/reqad/public_html

#if [ ! -f "/usr/local/reqad/public_html/index.php" ] && [ ! -f "/usr/local/reqad/public_html/index.html" ]; then
if [ ! -f "/usr/local/reqad/public_html/index.php" ]; then
    /bin/cp -R src/* /usr/local/reqad/
    chown -R reqad:reqad /usr/local/reqad/
fi
chown -R reqad:reqad /usr/local/reqad/

echo -n "Check nginx-reqad service running   "
if systemctl is-active --quiet nginx-reqad; then
    echo -e "[ ${GREEN}RUNNING${NC} ]"
    echo "

Reqad is now installed. Continue server install on web interface:
https://${HOSTNAME}:2087/
user: reqad
pass: ${PASSWORD}

"
else
    echo -e "[ ${RED}NOT RUNNING${NC} ]"
    systemctl status nginx-reqad
fi
