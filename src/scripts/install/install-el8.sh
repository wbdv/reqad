#!/bin/bash

VERSION='0.0.27 - Jul 16, 2026'

SSH_KEY=''
MM_LICENSE_KEY=''
EMAIL=''

WHITE='\033[1;37m'
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BLUE='\033[0;33m'
NC='\033[0m' # No Color

echo -ne "${YELLOW}"
echo -e "┌──────────────────────────────────────────────────────────────────────────────┐"
echo "│ Reqad install script version ${VERSION}                           │"
echo -e "└──────────────────────────────────────────────────────────────────────────────┘\n"
echo -ne "${NC}"

if [ "$EUID" -ne 0 ]
    then echo "Please run as root"  1>&2
    exit 1
fi

if [ "$SSH_PORT" == "" ]
	then SSH_PORT="22"
fi

if [ "$TIMEZONE" == "" ]
	then TIMEZONE='UTC'
fi

if [ "$PHP_VERSION" == "" ]
	then PHP_VERSION='8.3'
fi
export PHP_VERSION

# Email stack (exim/dovecot) is opt-in: WITH_EMAIL=true|yes|1
case "$(echo "${WITH_EMAIL}" | tr '[:upper:]' '[:lower:]')" in
	1|true|yes|y|on) WITH_EMAIL=1 ;;
	*) WITH_EMAIL=0 ;;
esac

IP=`ip address show | grep 'scope global' | grep 'inet ' | head -n 1 | awk {'print $2'} | awk -F/ {'print $1'}`
#HOSTNAME=`hostname`
HOSTNAME=`hostnamectl status | grep 'Static hostname:' | awk {'print $3'}`
#REVERSE=`hostname -i`
#REVERSE=$(dig +short A ${HOSTNAME})
REVERSE=$(hostname -A | awk {'print $1'})
ARCH=`hostnamectl status | grep 'Architecture:' | awk {'print $2'}`
#OS=`hostnamectl status | grep 'Operating System:' | awk {'print $3 " " $4 " " $5'} | awk -F. {'print $1'}`
#OS=`cat /etc/os-release | grep 'ROCKY_SUPPORT_PRODUCT=' | awk -F\" {'print $2'}`
OS=`cat /etc/os-release | grep 'PRETTY_NAME=' | awk -F\" {'print $2'} | awk -F. {'print $1'}`
MEMORY=`free -hmw --si | grep 'Mem:' | awk {'print $2'}`
CPU=`lscpu | grep -e '^Model name' | awk -F\: {'print $2'} | awk '{$1=$1};1'`
VCORE=`lscpu | grep -e '^CPU(s):' | awk -F\: '{print $2}' | awk '{$1=$1};1'`
DISKSPACE=`lsblk | grep 'disk' | awk {'print $4'}`
#SESTATUS=`sestatus | awk {'print $3'}`
SESTATUS=$(grep -E '^SELINUX=' /etc/selinux/config | awk -F= {'print $2'})

echo "        IP Address: ${IP}"
echo "          Hostname: ${REVERSE}"
hostnamectl status | grep -E --color=none 'Static hostname|Operating System|Kernel|Architecture|Virtualization' | sed 's/^/  /'
echo "         Processor: ${CPU}"
echo "            vCores: ${VCORE}"
echo "            Memory: ${MEMORY}"
echo "        Disk Space: ${DISKSPACE}"

if [[ "${ARCH}" != "x86-64" ]]
    then echo -e "\n ${RED}ERROR: Detected architecture ${ARCH}. Only x86-64 architecture is supported.${NC}\n"  1>&2
    exit 1
fi

if [[ "$(rpm -E %rhel 2>/dev/null)" != "8" ]]
    then echo -e "\n ${RED}ERROR: Detected operating system: ${OS}. Only Enterprise Linux 8 (Rocky, AlmaLinux, RHEL, Oracle, CentOS Stream) is supported.${NC}\n" 1>&2
    exit 1
fi

#if [[ "${REVERSE}" == "" ]]; then
#    REVERSE='unknown'
#fi

if [[ "${HOSTNAME}" != "${REVERSE}" ]]
    then echo -e "\n ${YELLOW}SET: hostname to ${REVERSE} instead of ${HOSTNAME}\n" 1>&2
    (hostnamectl set-hostname ${REVERSE}) >> ./install_reqad.log 2>&1
	HOSTNAME=$(hostnamectl status | grep 'Static hostname:' | awk {'print $3'})
fi

echo -e "${GREEN}"
echo -e "┌──────────────────────────────────────────────────────────────────────────────┐"
echo -e "│ All ok, proceed to instalation.                                              │"
echo -e "└──────────────────────────────────────────────────────────────────────────────┘\n"
echo -ne "${NC}"

if [ "$1" == "--skip-update" ]; then
    echo -e "  ~~~ skip dnf update and packages install ~~~\n";
else
    echo -n "Updating linux rpm                  ";
	(dnf clean all) >> ./install_reqad.log 2>&1
	(dnf --refresh makecache && dnf -y update) >> ./install_reqad.log 2>&1
	echo -e "[ ${GREEN}DONE${NC} ]"
	if [ ! -f "/etc/yum.repos.d/epel.repo" ]; then
	    echo -n "Install epel repository             ";
	    (dnf install -y epel-release) >> ./install_reqad.log 2>&1
	    echo -e "[ ${GREEN}DONE${NC} ]"
	fi

	RPMNR=$(rpm -qa | grep -E "^binutils|^dnf-utils|^git-2|^wget|^policycoreutils-python-utils|^pigz|^htop|^screen|^tar|^bind-utils|^unzip|^mlocate|^net-tools|^e2fsprogs|^bzip2-1|^curl|^cloud-init|^cloud-utils-growpart|^vim-enhanced|^systemd-timesyncd|^expect|^rsyslog|^pv|^dialog|^sqlite-3|^tmpwatch|^iptables-1|^iptables-libs|^bash-completion|^sysstat|^vnstat" | wc -l)
	if [ "${RPMNR}" -lt 30 ]; then
	    echo -n "Install the missing packages        ";
	    (dnf install -y wget) >> ./install_reqad.log 2>&1
	    (dnf install -y bash-completion binutils bind-utils bzip2 cloud-init cloud-utils-growpart curl dialog dnf-utils e2fsprogs expect git htop iptables iptables-libs mlocate net-tools pigz policycoreutils-python-utils pv rsyslog screen sqlite systemd-timesyncd sysstat tar tmpwatch unzip vim-enhanced vnstat) >> ./install_reqad.log 2>&1
	    echo -e "[ ${GREEN}DONE${NC} ]"
	fi
fi

if [ ! -f "/root/.vimrc" ]; then
    echo "
alias vi='vim'
PS1='\[\033[01;31m\]\u\[\033[01;90m\].\[\033[01;32m\]\h \[\033[01;96m\]\w \[\033[01;90m\]\\\$ \[\033[00m\]'
" >> /root/.bashrc

    alias vi='vim'
	PS1='\[\033[01;31m\]\u\[\033[01;90m\].\[\033[01;32m\]\h \[\033[01;96m\]\w \[\033[01;90m\]\\\$ \[\033[00m\]'

    (wget -q https://www.vim.org/scripts/download_script.php?src_id=19394 -O /usr/share/vim/vim80/syntax/nginx.vim) >> ./install_reqad.log 2>&1
    (wget -q https://raw.githubusercontent.com/tmatilai/vim-monit/master/syntax/monitrc.vim -O /usr/share/vim/vim80/syntax/monit.vim) >> ./install_reqad.log 2>&1
    echo 'syntax on
autocmd BufRead,BufNewFile /etc/php-fpm.conf,/etc/php-fpm.d/*.conf set syntax=dosini
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

#=[ disable selinux ]=================================================================================================
if [[ "${SESTATUS}" != "disabled" ]]; then
    echo -n "Disable SELINUX                     "
    sed -i 's/SELINUX=enforcing/SELINUX=disabled/' /etc/selinux/config
    sed -i 's/SELINUX=permissive/SELINUX=disabled/' /etc/selinux/config
    setenforce 0
    echo -e "[ ${GREEN}DONE${NC} ]"
fi

#=[ qemu & fstrim ]===================================================================================================
    (dnf install -y qemu-guest-agent && systemctl enable --now qemu-guest-agent && systemctl enable --now fstrim.timer) >> ./install_reqad.log  2>&1

#=[ sysstat & vnstat ]===================================================================================================
    (systemctl enable --now sysstat.service sysstat-collect.timer sysstat-summary.timer vnstat.service) >> ./install_reqad.log  2>&1

#=[ ssh ]=============================================================================================================
(dnf install -y openssh-server) >> ./install_reqad.log 2>&1
if [ ! -f "/root/.ssh/authorized_keys" ]; then
    echo "${SSH_KEY}" >> /root/.ssh/authorized_keys
    chmod go-rwx .ssh/authorized_keys
fi

if [ ! -f "/root/.ssh/id_rsa" ]; then
    (ssh-keygen -f /root/.ssh/id_rsa -N "") >> ./install_reqad.log 2>&1
fi

(mkdir -p /var/log/sssd/) >> ./install_reqad.log 2>&1
sed -i 's/#Port 22/Port '"${SSH_PORT}"'/' /etc/ssh/sshd_config
sed -i 's/#ListenAddress 0.0.0.0/ListenAddress 0.0.0.0/' /etc/ssh/sshd_config
sed -i 's/PasswordAuthentication yes/PasswordAuthentication no/' /etc/ssh/sshd_config
sed -i 's/#PermitRootLogin yes/PermitRootLogin prohibit-password/' /etc/ssh/sshd_config
sed -i 's/#PermitRootLogin prohibit-password/PermitRootLogin prohibit-password/' /etc/ssh/sshd_config
sed -i 's/PasswordAuthentication yes/PasswordAuthentication no/' /etc/ssh/sshd_config
sed -i 's/#PasswordAuthentication yes/PasswordAuthentication no/' /etc/ssh/sshd_config
sed -i 's/#PasswordAuthentication no/PasswordAuthentication no/' /etc/ssh/sshd_config
sed -i 's/#UseDNS no/UseDNS no/' /etc/ssh/sshd_config
sed -i 's/#UseDNS yes/UseDNS no/' /etc/ssh/sshd_config

(systemctl enable sshd) >> ./install_reqad.log 2>&1
(systemctl restart sshd) >> ./install_reqad.log 2>&1
echo "Note: SSH port changed to ${SSH_PORT} and password authentication is disabled."

#=[ disable ipv6 ]====================================================================================================
#echo "net.ipv6.conf.all.disable_ipv6 = 1
#net.ipv6.conf.default.disable_ipv6 = 1" >> /etc/sysctl.conf
#sysctl -p

#=[ csf ]=============================================================================================================
if [ ! -d "/etc/csf/" ]; then
echo -n "Install Configserver Firewall (csf) "
(dnf -y install ipset perl-libwww-perl perl-Net-SSLeay perl-IO-Socket-SSL perl-LWP-Protocol-https perl-GDGraph perl-Math-BigInt perl-Crypt-SSLeay) >> ./install_reqad.log 
(wget -q https://repo.reqad.net/csf.tgz) >> ./install_reqad.log 2>&1
(tar xzf csf.tgz) >> ./install_reqad.log 2>&1
cd csf
(./csftest.pl) >> ../install_reqad.log 2>&1
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
sed -i 's/DROP_LOGGING = "1"/DROP_LOGGING = "0"/' /etc/csf/csf.conf
sed -i 's/AUTO_UPDATES = "1"/AUTO_UPDATES = "0"/' /etc/csf/csf.conf
sed -i 's/LF_IPSET = "0"/LF_IPSET = "1"/' /etc/csf/csf.conf

# add 465 port to TCP_OUT
if grep "TCP_OUT" /etc/csf/csf.conf | grep -qv ",465,"; then
    sed -i 's/,587,/,465,587,/' /etc/csf/csf.conf
fi

if [[ "${MM_LICENSE_KEY}" != "" ]]; then
	sed -i 's/MM_LICENSE_KEY = ""/MM_LICENSE_KEY = "'${MM_LICENSE_KEY}'"/' /etc/csf/csf.conf
	sed -i 's/CC_SRC = "2"/CC_SRC = "1"/' /etc/csf/csf.conf
fi

#sed -i 's/CC_DENY = ""/CC_DENY = "XX"/' /etc/csf/csf.conf
#sed -i 's/CC_IGNORE = ""/CC_IGNORE = "XX"/' /etc/csf/csf.conf

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
" >> /etc/csf/csf.ignore

wget -q -O /etc/csf/cpanel.cloudflare.ignore https://www.cloudflare.com/ips-v4

# add port 2087 to csf TCP_IN
tcpinports=(`egrep '^TCP_IN =' /etc/csf/csf.conf | awk -F= {'print $2'} | sed 's/[ "]//g' | sed 's/,/ /g'`)
if [[ ! " ${tcpinports[*]} " =~ 2087 ]]; then
    sed -i 's/^TCP_IN = "\(.*\)"/TCP_IN = "\1,2087"/' /etc/csf/csf.conf
fi

(csf -ra) >> ./install_reqad.log 2>&1
echo -e "[ ${GREEN}DONE${NC} ]"
fi

#==[ timezone ]==============================================
LOCALTIMEZONE=$(timedatectl show --property="Timezone" | awk -F= {'print $2'})
if [[ "${TIMEZONE}" != "${LOCALTIMEZONE}" ]]; then
    echo -n "Setting timezone to ${TIMEZONE}                 " | cut -b 1-36 | echo -n "$(</dev/stdin)"
	timedatectl set-timezone ${TIMEZONE} 
	localectl set-locale LANG="en_GB.UTF-8"
    echo -e "[ ${GREEN}DONE${NC} ]"
fi

#==[ certbot ]===============================================
if [ ! -d "/etc/letsencrypt/" ]; then
    echo -n "Install certbot                     "
    (dnf install -y certbot python3-certbot-nginx) >> ./install_reqad.log 2>&1
	if [[ "${EMAIL}" != "" ]]; then
    	(certbot -n --agree-tos -m ${EMAIL} register) >> ./install_reqad.log 2>&1
	else
    	(certbot -n --agree-tos --register-unsafely-without-email register) >> ./install_reqad.log 2>&1
	fi
    (systemctl enable --now certbot-renew.timer) >> ./install_reqad.log 2>&1
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

#==[ exim local ]=============================================
IS_EXIM=$(rpm -qa | grep -E "^exim\-4" | wc -l)
if [ "${IS_EXIM}" -lt 1 ]; then
	echo -n "Install exim (local connections)    ";
	(dnf install -y exim) >> ./install_reqad.log 2>&1
	sed -i '/primary_hostname =/a\ \ndisable_ipv6 = true\nlocal_interfaces = 127.0.0.1.25' /etc/exim/exim.conf
	(systemctl enable --now exim) >> ./install_reqad.log 2>&1 
	(csf -ra) >> ./install_reqad.log 2>&1
	echo -e "[ ${GREEN}DONE${NC} ]"
fi


#==[ reqad ]==================================================
if ! systemctl is-active --quiet reqad; then
    if [ "$(systemctl list-unit-files "reqad.service" | grep "reqad.service" | awk {'print $2'})" == "enabled" ]; then
        (systemctl start reqad) >> ./install_reqad.log 2>&1
    fi
fi

if ! systemctl is-active --quiet reqad; then

	(dnf install -y https://rpms.remirepo.net/enterprise/remi-release-8.rpm) >> ./install_reqad.log 2>&1
	(dnf install -y https://repo.reqad.net/el8/RPMS/x86_64/reqad-repo-1.0.1-1.el8.noarch.rpm) >> ./install_reqad.log 2>&1
	(dnf -y module disable mysql) >> ./install_reqad.log 2>&1
	(dnf -y module enable mariadb) >> ./install_reqad.log 2>&1

	# nginx must come from the reqad repo, not appstream. reqad requires nginx,
	# so disable/exclude the appstream nginx BEFORE installing reqad, otherwise
	# dnf pulls stock nginx as a dependency and install-nginx-php.sh is skipped.
	# Distro-agnostic: detect the appstream repo id (Rocky/Alma/RHEL/Oracle/CentOS
	# Stream all contain "appstream" in the id) instead of editing a named repo file.
	(
		yum-config-manager --enable reqad
		dnf -y module reset nginx
		dnf -y module disable nginx
		ASREPO=$(dnf -q repolist --all 2>/dev/null | awk 'tolower($1) ~ /appstream/{print $1; exit}')
		[ -n "${ASREPO}" ] && dnf config-manager --save --setopt="${ASREPO}.exclude=nginx*"
	) >> ./install_reqad.log 2>&1

	(dnf -y install reqad) >> ./install_reqad.log 2>&1
	#echo -e "[ ${GREEN}DONE${NC} ]"

    (systemctl enable reqad --now) >> ./install_reqad.log 2>&1

	echo -n "Installing Reqad                    ";
    if systemctl is-active --quiet reqad; then
        echo -e "[ ${GREEN}OK${NC} ]"
    else
        echo -e "[ ${RED}ERROR${NC} ]"
        systemctl status reqad
        exit
    fi
else
	echo -n "Reqad status                        ";
    echo -ne "[${GREEN} RUNNING ${NC}] "
    echo -n 'reqad '
    echo -n `rpm -qi reqad | grep 'Version' | awk {'print $3'}`
fi
echo

install_email() {
	if [ "${WITH_EMAIL}" == "1" ]; then
		/usr/local/reqad/scripts/install/install-exim-dovecot.sh
	else
		echo -n "Email stack (exim/dovecot)          ";
		echo -e "[ ${YELLOW}SKIPPED${NC} ]"
		sed -i 's/^email=.*/email=0/' /usr/local/reqad/etc/server-software.ini
	fi
}

if [ "${TEMPLATE}" == "" ]
        then
        TEMPLATE="nginx_php-fpm"
fi

if [ "${TEMPLATE}" == "nginx_php-fpm" ]
	then
	/usr/local/reqad/scripts/install/install-nginx-php.sh && \
	/usr/local/reqad/scripts/install/install-mariadb.sh && \
	install_email && \
	/usr/local/reqad/scripts/install/install-wp-cli.sh && \
	/usr/local/reqad/scripts/install/install-monit.sh
fi

if [ "${TEMPLATE}" == "apache_modphp" ]
	then
	/usr/local/reqad/scripts/install/install-apache-modphp.sh && \
	/usr/local/reqad/scripts/install/install-mariadb.sh && \
	install_email && \
	/usr/local/reqad/scripts/install/install-wp-cli.sh && \
	/usr/local/reqad/scripts/install/install-monit.sh
fi

