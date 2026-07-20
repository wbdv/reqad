#!/bin/bash

VERSION='0.1.0 - May 24, 2026'

WHITE='\033[1;37m'
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BLUE='\033[0;33m'
NC='\033[0m' # No Color

echo -ne "${YELLOW}"
echo -e "┌──────────────────────────────────────────────────────────────────────────────┐"
echo "│ Reqad install script for exim / dovecot / spamassassin v${VERSION} │"
echo -e "└──────────────────────────────────────────────────────────────────────────────┘\n"
echo -ne "${NC}"

if [ "$EUID" -ne 0 ]
    then echo "Please run as root"  1>&2
    exit 1
fi



#######################################################################################
#
#  exim

if [ -f /etc/exim/exim.conf ]; then
	EXIM_LOCAL=$(grep local_interfaces /etc/exim/exim.conf | sed 's/ //g' | awk -F= {'print $2'})
	if [ "127.0.0.1.25" == "${EXIM_LOCAL}" ]; then
		echo "Exim is configured to run on local interface, reconfigure it.";
	fi
elif ! systemctl is-active --quiet exim; then
    if [ "$(systemctl list-unit-files "exim.service" | grep "exim.service" | awk {'print $2'})" == "enabled" ]; then
        (systemctl start exim) >> ./install_reqad.log 2>&1
    fi
fi

if ! systemctl is-active --quiet exim; then
    echo -n "Installing exim                     "
	(dnf -y install exim) >> ./install_reqad.log 2>&1
elif [ "127.0.0.1.25" == "${EXIM_LOCAL}" ]; then
    echo -n "Reconfigure exim                    "
	(mv /etc/exim/exim.conf /etc/exim/exim.conf.bkp ) >> ./install_reqad.log 2>&1
fi

(
	mkdir -p /etc/exim/domains /etc/exim/forwards /etc/exim/keys
	touch /etc/exim/userdomains
	echo "if not first_delivery
then
  finish
endif
" > /etc/exim/system_filter
	curl -s https://repo.reqad.net/trustedmailhosts.txt > /etc/exim/trustedmailhosts
	curl -s https://repo.reqad.net/exim.txt > /etc/exim/exim.conf
    SRS_SECRET=`head -n 10 /dev/urandom | tr -cd 'a-z0-9' | paste -sd - | sed 's/[\t, ]//g' | cut -b -32`
	sed -i "s/SRS_SECRET = $/SRS_SECRET = ${SRS_SECRET}/" /etc/exim/exim.conf
) >> ./install_reqad.log 2>&1

if ! systemctl is-active --quiet exim  || [ "127.0.0.1.25" == "${EXIM_LOCAL}" ]; then
	(systemctl enable --now exim.service) >> ./install_reqad.log 2>&1
    if systemctl is-active --quiet exim; then
        echo -ne "[ ${GREEN}OK${NC} ]"
    else
        echo -e "[ ${RED}ERROR${NC} ]"
        systemctl status exim
        exit
    fi
else
    echo -ne "[${GREEN} RUNNING ${NC}] "
    echo -n 'exim '
    echo -n `rpm -qi exim | grep 'Version' | awk {'print $3'}`
fi
echo



#######################################################################################
#
#  spamassassin

if [ "$(systemctl is-active spamassassin)" == "inactive" ]; then
	#echo "spamassassin is not active"
    if [ "$(systemctl list-unit-files "spamassassin.service" | grep "spamassassin.service" | awk {'print $2'})" == "disabled" ]; then
		#echo -n "Enabling spamassassin                "
        (systemctl enable --now spamassassin) >> ./install_reqad.log 2>&1
        #echo -e "[ ${GREEN}OK${NC} ]"
	else
		#echo -n "Starting spamassassin                "
        (systemctl start spamassassin) >> ./install_reqad.log 2>&1
        #echo -e "[ ${GREEN}OK${NC} ]"
    fi
fi

if [ "$(systemctl is-active spamassassin)" == "inactive" ]; then
    echo -n "Installing spamassassin             "
	(
		dnf install -y spamassassin
		groupadd -r spamd
		useradd -r -g spamd -s /sbin/nologin -d /var/lib/spamassassin spamd
		mkdir -p /var/lib/spamassassin
		chown spamd:spamd /var/lib/spamassassin
		echo 'SPAMDOPTIONS="-c -m5 -H -u spamd"' > /etc/sysconfig/spamassassin
		curl -s https://repo.reqad.net/spamassassin_local.txt > /etc/mail/spamassassin/local.cf
		/usr/bin/sa-update -v
	) >> ./install_reqad.log 2>&1
fi
if [ "$(systemctl is-active spamassassin)" == "inactive" ]; then
	(systemctl enable --now spamassassin.service) >> ./install_reqad.log 2>&1
	if [ "$(systemctl is-active spamassassin)" == "active" ]; then
        echo -ne "[ ${GREEN}OK${NC} ]"
    else
        echo -e "[ ${RED}ERROR${NC} ]"
        systemctl status spamassassin
        exit
    fi
else
    echo -ne "[${GREEN} RUNNING ${NC}] "
    echo -n 'spamassassin '
    echo -n `rpm -qi spamassassin | grep 'Version' | awk {'print $3'}`
fi
echo




#######################################################################################
#
#  dovecot 2.4

# Note: is-active is only "inactive" for a unit that was never started — a unit
# whose start failed reports "failed". Test for "not active" instead, so a re-run
# after a failed start still (re)configures dovecot and reports honestly.
if ! systemctl is-active --quiet dovecot; then
    if [ "$(systemctl list-unit-files "dovecot.service" | grep "dovecot.service" | awk {'print $2'})" == "enabled" ]; then
        (systemctl start dovecot) >> ./install_reqad.log 2>&1
    fi
fi

if ! systemctl is-active --quiet dovecot; then
    echo -n "Installing dovecot                  "
	(
		# Detect OS major version for repo selection
		OS_VER=$(grep -oP '(?<=^VERSION_ID=")\d+' /etc/os-release 2>/dev/null \
		    || grep -oP '\b[89]\b' /etc/redhat-release | head -1)

		if [ "${OS_VER}" -ge 9 ]; then
		    cat > /etc/yum.repos.d/dovecot.repo <<'EOF'
[dovecot-2.4-latest]
name=Dovecot 2.4 RHEL $releasever - $basearch
baseurl=http://repo.dovecot.org/ce-2.4-latest/rhel/$releasever/RPMS/$basearch
gpgkey=https://repo.dovecot.org/DOVECOT-REPO-GPG-2.4
gpgcheck=1
enabled=1
EOF
		else
		    cat > /etc/yum.repos.d/dovecot.repo <<'EOF'
[dovecot-2.4.1]
name=Dovecot 2.4.1 RHEL $releasever - $basearch
baseurl=http://repo.dovecot.org/ce-2.4.1/rhel/$releasever/RPMS/$basearch
gpgkey=https://repo.dovecot.org/DOVECOT-REPO-GPG-2.3
gpgcheck=1
enabled=1
EOF
		fi

		# dovecot-submissiond is intentionally NOT installed — exim is the MSA on
		# ports 587/465. We still write a disabling 20-submission.conf below so the
		# protocol stays off even if the package gets pulled in later.
		dnf -y install dovecot dovecot-imapd dovecot-pop3d dovecot-lmtpd

		# Dovecot submission conflicts with exim on ports 587/465. Overwrite (don't
		# rm) so that if dovecot-submissiond is ever installed, RPM keeps our file
		# and drops its default as 20-submission.conf.rpmnew instead of enabling it.
		echo '# Submission disabled — exim is the MSA on 587/465 (no protocols block = off)' \
		    > /etc/dovecot/conf.d/20-submission.conf

		# conf.d/10-auth.conf defaults to PAM; clear it — local.conf provides all auth config
		echo '# Auth handled by local.conf' > /etc/dovecot/conf.d/10-auth.conf

		# auth-client socket must be readable by exim (in the mail group) for SMTP
		# AUTH. The 2.4 default leaves it 0600 dovecot:root — set it mail:mail 0660.
		cat > /etc/dovecot/conf.d/10-master.conf <<'MASTEREOF'
service auth {
  unix_listener auth-client {
    mode = 0660
    user = mail
    group = mail
  }
}
MASTEREOF

		# Dovecot 2.4 requires version headers as the very first settings
		if ! grep -q '^dovecot_config_version' /etc/dovecot/dovecot.conf; then
		    sed -i '1s/^/dovecot_config_version = 2.4.1\ndovecot_storage_version = 2.4.1\n/' \
		        /etc/dovecot/dovecot.conf
		fi

		# local.conf is not included by default in RHEL packages — add it
		if ! grep -q 'local.conf' /etc/dovecot/dovecot.conf; then
		    echo '!include_try local.conf' >> /etc/dovecot/dovecot.conf
		fi

		# Write local.conf with all site-specific overrides.
		cat > /etc/dovecot/local.conf <<'LOCALEOF'
# SSL
ssl = required
ssl_server_cert_file = /etc/pki/dovecot/certs/dovecot.pem
ssl_server_key_file = /etc/pki/dovecot/private/dovecot.pem
ssl_server_dh_file = /etc/dovecot/dh.pem
ssl_min_protocol = TLSv1.2
ssl_server_prefer_ciphers = client
!include_try /etc/dovecot/sni.conf

# Mail — home dir is the Maildir root; INBOX = ~/cur+new+tmp, subfolders = ~/.FolderName/
mail_path = ~/
mail_inbox_path = ~/

# Auth mechanisms
auth_mechanisms = plain login

passdb passwd-file {
  passwd_file_path = /etc/dovecot/users
  default_password_scheme = CRYPT
}
userdb passwd-file {
  passwd_file_path = /etc/dovecot/users
}
LOCALEOF

		# Generate DH params (dsaparam is fast; used for TLS 1.2 DHE cipher suites)
		[ -f /etc/dovecot/dh.pem ] || openssl dhparam -dsaparam -out /etc/dovecot/dh.pem 4096

		# Self-signed default cert. The dovecot 2.4 RPM no longer generates one on
		# install (2.3's %post did), so ssl_server_cert_file above would point at a
		# missing file and doveconf fails fatally. Reqad replaces this with per-domain
		# Let's Encrypt certs via SNI (update_email_sni); this is just the fallback.
		if [ ! -f /etc/pki/dovecot/certs/dovecot.pem ]; then
		    mkdir -p /etc/pki/dovecot/certs /etc/pki/dovecot/private
		    openssl req -new -x509 -nodes -days 3650 \
		        -subj "/CN=$(hostname -f 2>/dev/null || hostname)/O=Reqad" \
		        -out /etc/pki/dovecot/certs/dovecot.pem \
		        -keyout /etc/pki/dovecot/private/dovecot.pem
		    chmod 0600 /etc/pki/dovecot/private/dovecot.pem
		fi

		touch /etc/dovecot/users
		touch /etc/dovecot/sni.conf
		usermod -G dovecot,mail,exim,mysyslog dovecot
		/usr/local/reqad/scripts/update_email_sni
	) >> ./install_reqad.log 2>&1
fi

if ! systemctl is-active --quiet dovecot; then
	(systemctl enable --now dovecot.service) >> ./install_reqad.log 2>&1
	if systemctl is-active --quiet dovecot; then
        echo -ne "[ ${GREEN}OK${NC} ]"
    else
        echo -e "[ ${RED}ERROR${NC} ]"
        systemctl status dovecot
        exit
    fi
else
    echo -ne "[${GREEN} RUNNING ${NC}] "
    echo -n 'dovecot '
    echo -n `rpm -qi dovecot | grep 'Version' | awk {'print $3'}`
fi
echo

if [ "$(grep -e '^email=0$' /usr/local/reqad/etc/server-software.ini)" == "email=0" ]; then
    echo -n "Enabling email in Reqad config      ";
    ( sed -i 's/email=0/email=1/' /usr/local/reqad/etc/server-software.ini ) >> ./install_reqad.log 2>&1
    echo -e "[ ${GREEN}OK${NC} ]"
fi

#######################################################################################
#
#  roundcube (installed outside public_html — webroot is roundcubemail/public_html/)

ROUNDCUBE_INSTALL_DIR="/usr/local/reqad/roundcubemail"

echo -n "Installing Roundcube Mail           "
if [ -f "${ROUNDCUBE_INSTALL_DIR}/config/config.inc.php" ]; then
    echo -e "[ ${RED}SKIP${NC} ] ${ROUNDCUBE_INSTALL_DIR}/config/config.inc.php already exists"
else
	(
		ROUNDCUBE_VER=$(curl -sfL "https://api.github.com/repos/roundcube/roundcubemail/releases/latest" \
		    | grep -oP '"tag_name":\s*"\K[^"]+' | sed 's/^v//')
		if [ -z "${ROUNDCUBE_VER}" ]; then
		    echo "ERROR: Could not fetch latest Roundcube version from GitHub."
		    exit 1
		fi
		echo "Latest Roundcube version: ${ROUNDCUBE_VER}"

		TARBALL="roundcubemail-${ROUNDCUBE_VER}-complete.tar.gz"
		rm -rf "${TARBALL}" "roundcubemail-${ROUNDCUBE_VER}" "${ROUNDCUBE_INSTALL_DIR}"
		curl -L -o "${TARBALL}" \
		    "https://github.com/roundcube/roundcubemail/releases/download/${ROUNDCUBE_VER}/${TARBALL}"
		tar xzf "${TARBALL}"
		rm -f "${TARBALL}"
		mv "roundcubemail-${ROUNDCUBE_VER}" "${ROUNDCUBE_INSTALL_DIR}"

		cp "${ROUNDCUBE_INSTALL_DIR}/config/config.inc.php.sample" \
		   "${ROUNDCUBE_INSTALL_DIR}/config/config.inc.php"

		PASSWORD=$(head -n 10 /dev/urandom | tr -cd '[:alnum:]' | cut -b -16)
		DB_ROUNDCUBE=$(/usr/bin/mysql --defaults-extra-file=/root/.my.cnf \
		    -se "SHOW DATABASES WHERE Database='roundcube'")

		if [ "${DB_ROUNDCUBE}" != "roundcube" ]; then
		    echo "Creating roundcube database"
		    /usr/bin/mysql --defaults-extra-file=/root/.my.cnf \
		        -e "CREATE DATABASE roundcube;"
		    /usr/bin/mysql --defaults-extra-file=/root/.my.cnf \
		        roundcube < "${ROUNDCUBE_INSTALL_DIR}/SQL/mysql.initial.sql"
		else
		    echo "Database already exists"
		fi
		/usr/bin/mysql --defaults-extra-file=/root/.my.cnf \
		    -e "GRANT ALL ON roundcube.* TO roundcube@localhost IDENTIFIED BY '${PASSWORD}';"

		sed -i "s|roundcube:pass@localhost/roundcubemail|roundcube:${PASSWORD}@localhost/roundcube|" \
		    "${ROUNDCUBE_INSTALL_DIR}/config/config.inc.php"

		# IMAP: use IMAPS (port 993); disable peer verification for local self-signed cert.
		# Usernames are full email addresses — Dovecot verifies password against /etc/dovecot/users.
		# SMTP: submit via exim on port 25 from localhost — no auth required.
		sed -i \
		    "s|'imap_host'.*|'imap_host'] = 'ssl://localhost';|" \
		    "${ROUNDCUBE_INSTALL_DIR}/config/config.inc.php"
		sed -i \
		    "s|'smtp_host'.*|'smtp_host'] = 'localhost:25';|" \
		    "${ROUNDCUBE_INSTALL_DIR}/config/config.inc.php"

		cat >> "${ROUNDCUBE_INSTALL_DIR}/config/config.inc.php" <<PHP

// IMAP: disable SSL peer verification for self-signed localhost cert
\$config['imap_conn_options'] = [
    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true],
];

// SMTP: no auth (exim accepts local submissions without credentials)
\$config['smtp_user'] = '';
\$config['smtp_pass'] = '';
\$config['smtp_conn_options'] = [
    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true],
];

// Password plugin: write changed passwords to /etc/dovecot/users as SHA512-CRYPT hashes
\$config['password_driver']='dovecot_passwdfile';
\$config['password_dovecot_passwdfile_path'] = '/etc/dovecot/users';
\$config['password_algorithm'] = 'sha512-crypt';

// LOGGING
\$config['log_driver'] = 'syslog';
\$config['syslog_facility'] = LOG_MAIL;
PHP

		chown -R reqad:reqad "${ROUNDCUBE_INSTALL_DIR}"
	) >> ./install_reqad.log 2>&1
    echo -e "[ ${GREEN}OK${NC} ]"
fi
