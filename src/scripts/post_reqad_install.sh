#!/bin/bash
hostname=$(hostname)
password=`head -n 10 /dev/urandom | tr -cd '[:alnum:]!@#$%^&*()+-0123456789' | paste -sd - | sed 's/[\t, ]//g' | cut -b -20`

WHITE='\033[1;37m'
GREY='\033[1;30m'
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BLUE='\033[0;33m'
NC='\033[0m' # No Color
[ -w /dev/tty ] && TTY=/dev/tty || TTY=/dev/null

sed -i '/^reqad /d' /etc/sudoers
sed -i '/^root/a\reqad   ALL=(ALL)   NOPASSWD:ALL' /etc/sudoers

if ! [ -f /etc/letsencrypt/options-ssl-nginx.conf ]; then
    cat > /etc/letsencrypt/options-ssl-nginx.conf <<EOF
# This file contains important security parameters. If you modify this file
# manually, Certbot will be unable to automatically provide future security
# updates. Instead, Certbot will print and log an error message with a path to
# the up-to-date file that you will need to refer to when manually updating
# this file.

ssl_session_cache shared:le_nginx_SSL:10m;
ssl_session_timeout 1440m;
ssl_session_tickets off;

ssl_protocols TLSv1.2 TLSv1.3;
ssl_prefer_server_ciphers off;

ssl_ciphers "ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384";
EOF
fi

if ! [ -f /etc/letsencrypt/live/${hostname}/fullchain.pem ]; then
    echo "Register certbot with a default address";
    certbot register -n --agree-tos --email root@${hostname}
    echo "Obtain a new certificate for ${hostname}";
    nginx_is_running=$(netstat -nap | grep ':80' | grep tcp | grep LISTEN | grep '/nginx:' | wc -l)
    if [ $nginx_is_running -ge 1 ]; then
	certbot certonly -n --nginx -d ${hostname}
    else
	certbot certonly -n --standalone -d ${hostname}
	sed -i 's/authenticator = standalone/authenticator = nginx\ninstaller = nginx/' /etc/letsencrypt/renewal/${hostname}.conf
    fi
fi

cat > /etc/letsencrypt/renewal-hooks/post/reqad.sh <<'RENEWAL_EOF'
#!/bin/bash
# Patch DKIM_DOMAIN macro: fall back to From header domain for null-sender messages (e.g. autoresponder replies)
if ! grep -q 'h_from' /etc/exim/exim.conf 2>/dev/null; then
    python3 << 'EOF'
conf = open('/etc/exim/exim.conf').read()
old = 'DKIM_DOMAIN = ${lookup{$sender_address_domain}dsearch{/etc/exim/domains/}}'
new = 'DKIM_DOMAIN = ${if eq{$sender_address_domain}{}{${lookup{${lc:${domain:$h_from:}}}dsearch{/etc/exim/domains/}}}{${lookup{$sender_address_domain}dsearch{/etc/exim/domains/}}}}'
if old in conf:
    conf = conf.replace(old, new, 1)
    open('/etc/exim/exim.conf', 'w').write(conf)
EOF
    systemctl restart exim
fi
# certificates readable by all, private keys by root + exim only (never chmod -R a+rx: it opened every key)
bash /usr/local/reqad/scripts/update/fix_letsencrypt_perms.sh
/usr/local/reqad/scripts/update_email_sni
systemctl restart reqad
RENEWAL_EOF
chmod 0755 /etc/letsencrypt/renewal-hooks/post/reqad.sh
# older releases left every Let's Encrypt private key readable by all local users
bash /usr/local/reqad/scripts/update/fix_letsencrypt_perms.sh


if [ -f /etc/reqad/nginx.conf.rpmnew ]; then
    /bin/mv /etc/reqad/nginx.conf /etc/reqad/nginx.conf.old
    /bin/mv /etc/reqad/nginx.conf.rpmnew /etc/reqad/nginx.conf
fi

#if [ -f /etc/reqad/conf.d/virtualhostname.conf.sample.rpmnew ]; then
#    /bin/mv /etc/reqad/conf.d/${hostname}.conf /etc/reqad/conf.d/${hostname}.conf.old
#    /bin/cp /etc/reqad/conf.d/virtualhostname.conf.sample.rpmnew /etc/reqad/conf.d/virtualhostname.conf.sample
#fi

if ! [ -f /etc/reqad/conf.d/${hostname}.conf ]; then
    if [ -f /etc/reqad/conf.d/virtualhostname.conf.sample ]; then
        sed -i "s/%HOSTNAME%/${hostname}/" /etc/reqad/conf.d/virtualhostname.conf.sample
        /bin/mv /etc/reqad/conf.d/virtualhostname.conf.sample /etc/reqad/conf.d/${hostname}.conf
    fi
fi

#VERSION=$(rpm -q --qf "%{VERSION}\n" reqad)
#if [ ${VERSION} == "1.0.26" ]; then
#    echo "Update config files to ${VERSION}\n";

( echo "--- dovecot/conf.d/10-ssl.conf.orig        2025-09-05 10:50:41.479494626 +0300
> +++ dovecot/conf.d/10-ssl.conf     2025-09-05 10:50:56.233543977 +0300
> @@ -12,6 +12,8 @@
>  ssl_cert = </etc/pki/dovecot/certs/dovecot.pem
>  ssl_key = </etc/pki/dovecot/private/dovecot.pem
>
> +\!include_try /etc/dovecot/sni.conf
> +
>  # If key file is password protected, give the password here. Alternatively
>  # give it when starting dovecot with -p parameter. Since this file is often
>  # world-readable, you may want to place this setting instead to a different" | patch -d /etc/dovecot -p 1 -ltN
touch /etc/dovecot/sni.conf
# Patch DKIM_DOMAIN macro: fall back to From header domain for null-sender messages (e.g. autoresponder replies)
if ! grep -q 'h_from' /etc/exim/exim.conf 2>/dev/null; then
    python3 << 'EOF'
conf = open('/etc/exim/exim.conf').read()
old = 'DKIM_DOMAIN = ${lookup{$sender_address_domain}dsearch{/etc/exim/domains/}}'
new = 'DKIM_DOMAIN = ${if eq{$sender_address_domain}{}{${lookup{${lc:${domain:$h_from:}}}dsearch{/etc/exim/domains/}}}{${lookup{$sender_address_domain}dsearch{/etc/exim/domains/}}}}'
if old in conf:
    conf = conf.replace(old, new, 1)
    open('/etc/exim/exim.conf', 'w').write(conf)
EOF
    systemctl restart exim
fi
/usr/local/reqad/scripts/update_email_sni
) > /dev/null 2>&1

# exim.conf's `virtual` router does `dsearch;/etc/exim/virtual`; if the
# directory is missing, every delivery panics ("failed to open /etc/exim/virtual
# for directory search") and the whole queue freezes.
if [ -d /etc/exim ] && [ ! -d /etc/exim/virtual ]; then
    mkdir -p /etc/exim/virtual
    chmod 755 /etc/exim/virtual
fi

if [ -f /etc/exim/exim.conf ]; then
# fix exim.conf for root mail forwarding via pipe
# 1) alias pipes get their own transport -- see the call to
#    scripts/update/fix_system_alias_pipe.sh further down. The old fix here
#    uncommented `user = exim` on the system_aliases ROUTER, which never had any
#    effect: address_pipe sets its own user, and a transport's user overrides the
#    uid the router passes it. Left as it is on servers that already have it; the
#    transport decides.
# address_pipe: ensure user uses lsearch* userdomains lookup (idempotent)
if ! egrep -A 8 '^address_pipe:' /etc/exim/exim.conf | grep -v '^\s*#' | grep -q 'lsearch\* {/etc/exim/userdomains}'; then
    python3 << 'EOF'
import re
conf = open('/etc/exim/exim.conf').read()
conf = re.sub(
    r'(?m)(^address_pipe:.*?)(\n[ \t]+user[ \t]*=[ \t]*[^\n]+)',
    r'\1\n  user = "${lookup{$domain_data}lsearch* {/etc/exim/userdomains}{$value}}"',
    conf, count=1, flags=re.DOTALL
)
open('/etc/exim/exim.conf', 'w').write(conf)
EOF
    systemctl restart exim
fi

# update exim.conf logs for SNI
sed -i 's/^log_selector = +all_parents +arguments +incoming_port +received_recipients +smtp_connection +subject -retry_defer +smtp_protocol_error +smtp_syntax_error +tls_certificate_verified$/log_selector = +all_parents +arguments +incoming_port +received_recipients +smtp_connection +subject -retry_defer +smtp_protocol_error +smtp_syntax_error +tls_certificate_verified +tls_sni/' /etc/exim/exim.conf
fi

if [ "$(grep 'error_page 497' /etc/reqad/conf.d/${hostname}.conf)" == "" ]; then
	echo "Update /etc/reqad/conf.d/${hostname}.conf";
        sed -i "/ssl_certificate_key.*/a\ \n\t# If they come here using HTTP, bounce them to the correct scheme\n\terror_page 497 https://\$server_name:\$server_port\$request_uri;" /etc/reqad/conf.d/${hostname}.conf
fi

#fi

# reqad panel FPM socket renamed: /run/php-fpm-reqad.sock -> /run/reqad-php-fpm.sock
# (panel now runs under its own dedicated reqad-php-fpm master). Fix existing vhosts on upgrade;
# nginx (reqad.service) is reloaded later in this script.
if grep -q '/run/php-fpm-reqad.sock' /etc/reqad/conf.d/${hostname}.conf 2>/dev/null; then
    echo "Update reqad panel FPM socket in /etc/reqad/conf.d/${hostname}.conf";
    sed -i 's|/run/php-fpm-reqad.sock|/run/reqad-php-fpm.sock|g' /etc/reqad/conf.d/${hostname}.conf
fi

# disable csf auto-updates 
sed -i 's/AUTO_UPDATES = "1"/AUTO_UPDATES = "0"/' /etc/csf/csf.conf

# update database
DB_VER=$(sqlite3 /usr/local/reqad/db/reqad.db "select value from settings where name='db-version'")
if [ "$DB_VER" = "" ]; then
    echo "Update database to 1020 (v 1.0.20)";
    cat /usr/local/reqad/db/1020.sql | sqlite3 /usr/local/reqad/db/reqad.db
    DB_VER="1020"
fi

if [ "$DB_VER" -lt 1027 ] 2>/dev/null; then
    echo "Update database to 1027 (v 1.0.27)";
    cat /usr/local/reqad/db/1027.sql | sqlite3 /usr/local/reqad/db/reqad.db
fi

if [ "$DB_VER" -lt 1028 ] 2>/dev/null; then
    echo "Update database to 1028 (autoresponders table)";
    cat /usr/local/reqad/db/1028.sql | sqlite3 /usr/local/reqad/db/reqad.db
    mkdir -p /etc/exim/autoreply
    chown exim:mail /etc/exim/autoreply
    chmod 750 /etc/exim/autoreply
fi

if [ "$DB_VER" -lt 1029 ] 2>/dev/null; then
    echo "Update database to 1029 (wordpress path column)";
    cat /usr/local/reqad/db/1029.sql | sqlite3 /usr/local/reqad/db/reqad.db
fi

if [ "$DB_VER" -lt 1030 ] 2>/dev/null; then
    echo "Update database to 1030 (aliases table)";
    cat /usr/local/reqad/db/1030.sql | sqlite3 /usr/local/reqad/db/reqad.db
fi

if [ "$DB_VER" -lt 1031 ] 2>/dev/null; then
    echo "Update database to 1031 (email_filters table)";
    cat /usr/local/reqad/db/1031.sql | sqlite3 /usr/local/reqad/db/reqad.db
fi

# 1032 REPLACES the emails table (drop + recreate), it does not alter it. The
# old rows were a 2024 seed that nothing had refreshed since, so they are not
# carried over — the table is now purely a du -skm cache that re-measures on
# demand, and the mailbox inventory is /etc/dovecot/users.
if [ "$DB_VER" -lt 1032 ] 2>/dev/null; then
    echo "Update database to 1032 (emails table demoted to a disk-usage cache)";
    cat /usr/local/reqad/db/1032.sql | sqlite3 /usr/local/reqad/db/reqad.db
fi

# 1033 adds webmail_tickets, the single-use ticket store behind webmail
# auto-login (dovecot master user + the Roundcube reqad_autologin plugin).
if [ "$DB_VER" -lt 1033 ] 2>/dev/null; then
    echo "Update database to 1033 (webmail auto-login tickets)";
    cat /usr/local/reqad/db/1033.sql | sqlite3 /usr/local/reqad/db/reqad.db
fi

# 1034 DROPS the `errors` table. SMTP delivery failures are a log, not app
# state: every row was re-derived from a line of exim's main.log, so the table
# was a second copy of a log that already existed -- one that grew without
# bound and put the database under a write every minute to hold it. They are
# collected in log/smtp_errors.log now.
#
# The rescue runs first and has to: some of those rows are older than the
# main.log the collector rescans, so once the table is gone they cannot be
# re-derived. It only carries over rows with a real recipient address (the
# retired collector misparsed most of the rest into rows recording a
# recipient of "error" or "from").
if [ "$DB_VER" -lt 1034 ] 2>/dev/null; then
    echo "Update database to 1034 (SMTP errors move to log/smtp_errors.log)";
    bash /usr/local/reqad/scripts/update/migrate_smtp_errors_log.sh
    cat /usr/local/reqad/db/1034.sql | sqlite3 /usr/local/reqad/db/reqad.db
fi

# 1035 adds db_owners: databases without an "<account>_" prefix assigned to an
# account on the Databases page, so its backups and restores include them.
if [ "$DB_VER" -lt 1035 ] 2>/dev/null; then
    echo "Update database to 1035 (database to account assignments)";
    cat /usr/local/reqad/db/1035.sql | sqlite3 /usr/local/reqad/db/reqad.db
fi

# 1036 adds backup_excludes: per-account and server-wide rules for what the
# nightly and manual account backups leave out (Backup > Exclusions).
if [ "$DB_VER" -lt 1036 ] 2>/dev/null; then
    echo "Update database to 1036 (backup exclusions)";
    cat /usr/local/reqad/db/1036.sql | sqlite3 /usr/local/reqad/db/reqad.db
fi

# 1037 adds the web login tables (auth_*): admins, sessions, 2FA recovery
# codes, login attempts, csf bans, security log. The panel keeps using nginx
# basic auth until `reqad auth mode web` switches the vhost; the existing
# nginx_auth password is imported on first use.
if [ "$DB_VER" -lt 1037 ] 2>/dev/null; then
    echo "Update database to 1037 (web login)";
    cat /usr/local/reqad/db/1037.sql | sqlite3 /usr/local/reqad/db/reqad.db
fi

# 1038: web login "sudo mode" (auth_sessions.sudo_until - sensitive changes
# need a password confirmation within 10 minutes) and remembered 2FA devices
# (auth_devices.trusted_until - 7 days).
if [ "$DB_VER" -lt 1038 ] 2>/dev/null; then
    echo "Update database to 1038 (sudo mode, remembered devices)";
    cat /usr/local/reqad/db/1038.sql | sqlite3 /usr/local/reqad/db/reqad.db
fi

# 1039: web login new devices - every browser that logs in is remembered
# (auth_devices), emailed confirmation codes for new ones (auth_challenges).
if [ "$DB_VER" -lt 1039 ] 2>/dev/null; then
    echo "Update database to 1039 (new-device email codes)";
    cat /usr/local/reqad/db/1039.sql | sqlite3 /usr/local/reqad/db/reqad.db
fi

# 1040: web login passkeys (auth_passkeys, auth_principals.webauthn_user).
if [ "$DB_VER" -lt 1040 ] 2>/dev/null; then
    echo "Update database to 1040 (passkeys)";
    cat /usr/local/reqad/db/1040.sql | sqlite3 /usr/local/reqad/db/reqad.db
fi

# 1041: health checks (health_items, health_dismissed) for "Needs attention".
if [ "$DB_VER" -lt 1041 ] 2>/dev/null; then
    echo "Update database to 1041 (health checks)";
    cat /usr/local/reqad/db/1041.sql | sqlite3 /usr/local/reqad/db/reqad.db
fi

# 1042: REST API tokens and call log (api_tokens, api_log); api-enabled = 0.
if [ "$DB_VER" -lt 1042 ] 2>/dev/null; then
    echo "Update database to 1042 (REST API)";
    cat /usr/local/reqad/db/1042.sql | sqlite3 /usr/local/reqad/db/reqad.db
fi

# 1043: Git deployments (deployments, deploy_runs).
if [ "$DB_VER" -lt 1043 ] 2>/dev/null; then
    echo "Update database to 1043 (Git deployments)";
    cat /usr/local/reqad/db/1043.sql | sqlite3 /usr/local/reqad/db/reqad.db
fi

# 1044: Node.js / Python apps (apps).
if [ "$DB_VER" -lt 1044 ] 2>/dev/null; then
    echo "Update database to 1044 (apps)";
    cat /usr/local/reqad/db/1044.sql | sqlite3 /usr/local/reqad/db/reqad.db
fi

# 1045: AI assistants over MCP (api_tokens.kind, mcp-enabled).
if [ "$DB_VER" -lt 1045 ] 2>/dev/null; then
    echo "Update database to 1045 (mcp)";
    cat /usr/local/reqad/db/1045.sql | sqlite3 /usr/local/reqad/db/reqad.db
fi

# 1046: webmail auto-login sessions are tied to the panel session that opened
# them (webmail_tickets.session_id).
if [ "$DB_VER" -lt 1046 ] 2>/dev/null; then
    echo "Update database to 1046 (webmail auto-login follows panel logout)";
    cat /usr/local/reqad/db/1046.sql | sqlite3 /usr/local/reqad/db/reqad.db
fi
# 1047: phpMyAdmin logins of the account panel (sql_logins), dropped with
# the panel session that opened them.
if [ "$DB_VER" -lt 1047 ] 2>/dev/null; then
    echo "Update database to 1047 (phpMyAdmin from the account panel)";
    cat /usr/local/reqad/db/1047.sql | sqlite3 /usr/local/reqad/db/reqad.db
fi
# 1048: messages to hosting accounts (contact address confirmed on the
# account panel's Profile page; which kinds; what was sent).
if [ "$DB_VER" -lt 1048 ] 2>/dev/null; then
    echo "Update database to 1048 (messages to hosting accounts)";
    cat /usr/local/reqad/db/1048.sql | sqlite3 /usr/local/reqad/db/reqad.db
fi
# 1049: WordPress updates that run in the background (WP Toolkit > Updates).
if [ "$DB_VER" -lt 1049 ] 2>/dev/null; then
    echo "Update database to 1049 (WordPress update jobs)";
    cat /usr/local/reqad/db/1049.sql | sqlite3 /usr/local/reqad/db/reqad.db
fi
# 1050: email on or off per addon site (subdomains off by default).
if [ "$DB_VER" -lt 1050 ] 2>/dev/null; then
    echo "Update database to 1050 (email per addon site)";
    cat /usr/local/reqad/db/1050.sql | sqlite3 /usr/local/reqad/db/reqad.db
fi
# 1051: WordPress auto-updates (WP Toolkit > Auto-updates).
if [ "$DB_VER" -lt 1051 ] 2>/dev/null; then
    echo "Update database to 1051 (WordPress auto-updates)";
    cat /usr/local/reqad/db/1051.sql | sqlite3 /usr/local/reqad/db/reqad.db
fi
# 1052: WordPress copies (WP Toolkit > Clone or staging), the kind of a background job.
if [ "$DB_VER" -lt 1052 ] 2>/dev/null; then
    echo "Update database to 1052 (WordPress clone or staging)";
    cat /usr/local/reqad/db/1052.sql | sqlite3 /usr/local/reqad/db/reqad.db
fi
# 1053: restores an account starts from its own backups (account panel > Backups).
if [ "$DB_VER" -lt 1053 ] 2>/dev/null; then
    echo "Update database to 1053 (restores from backups)";
    cat /usr/local/reqad/db/1053.sql | sqlite3 /usr/local/reqad/db/reqad.db
fi
# 1054: a WordPress site's own page cache rules (WordPress Toolkit > Performance).
if [ "$DB_VER" -lt 1054 ] 2>/dev/null; then
    echo "Update database to 1054 (page cache rules)";
    cat /usr/local/reqad/db/1054.sql | sqlite3 /usr/local/reqad/db/reqad.db
fi
# 1055: DNS API keys - other servers keep their zones here (Settings > DNS API keys).
if [ "$DB_VER" -lt 1055 ] 2>/dev/null; then
    echo "Update database to 1055 (DNS API keys)";
    cat /usr/local/reqad/db/1055.sql | sqlite3 /usr/local/reqad/db/reqad.db
fi

cp -a /usr/local/reqad/public_html/defines.default.php /usr/local/reqad/public_html/defines.php

# server-software.ini is not packaged (that would produce a .rpmnew on every
# update). Seed it from the shipped template on first install; on updates the
# live file is left alone and update_ini.sh below adds any new keys.
if [ ! -f /usr/local/reqad/etc/server-software.ini ]; then
	cp -a /usr/local/reqad/etc/server-software.ini.default \
	      /usr/local/reqad/etc/server-software.ini
fi
# Leftover from the releases that still packaged the live ini as
# %config(noreplace) — rpm never removes a .rpmnew, and none is produced now.
rm -f /usr/local/reqad/etc/server-software.ini.rpmnew

# The apache template was renamed apache_mod_php -> apache_modphp (the vhost
# generator has always been templates/apache_modphp.php). Older installs still
# carry the old spelling in server-software.ini, which makes every consumer of
# the template= key fall through its "unknown template" branch.
if [ -f /usr/local/reqad/etc/server-software.ini ] && \
   grep -q 'apache_mod_php' /usr/local/reqad/etc/server-software.ini; then
	echo "Renaming template apache_mod_php -> apache_modphp in server-software.ini";
	sed -i 's/apache_mod_php/apache_modphp/g' /usr/local/reqad/etc/server-software.ini
fi

(
	bash /usr/local/reqad/scripts/update/setup_reqad_fpm.sh
	bash /usr/local/reqad/scripts/update/update_ini.sh
	bash /usr/local/reqad/scripts/update/update_roundcube.sh
	# Roundcube is excluded from the RPM, so its plugin is placed from the
	# packaged copy under scripts/mail/roundcube/ rather than shipped in place.
	bash /usr/local/reqad/scripts/update/install-webmail-autologin.sh
	# Webmail brute-force protection: reqad_guard plugin (same packaged-copy
	# route), Roundcube login_rate_limit/log_logins, nginx limit on login POSTs,
	# an lfd rule (CUSTOMn_LOG + regex.custom.pm) blocks IPs that keep failing.
	bash /usr/local/reqad/scripts/update/setup_webmail_guard.sh --apply
	bash /usr/local/reqad/scripts/update/update_user_phpfpm.sh
	bash /usr/local/reqad/scripts/update/update_default_nginx_host.sh
	bash /usr/local/reqad/scripts/update/update_certbot_hostname.sh
	bash /usr/local/reqad/scripts/update/update_default_apache_host.sh
	bash /usr/local/reqad/scripts/update/update_wetty_shell.sh

	# Autoresponders moved from Exim's Sieve (no `variables` extension, so the
	# original subject could not be interpolated) to Pigeonhole. This is a no-op
	# unless the admin already opted into LMTP delivery via setup_dovecot_sieve.sh;
	# servers still on exim appendfile keep the old backend and keep working.
	bash /usr/local/reqad/scripts/update/setup_autoresponder_sieve.sh

	# "Pipe to a program" filter action (vnd.dovecot.pipe). Same shape as the line
	# above: a no-op unless the server is already on Pigeonhole delivery, and it
	# only appends settings + creates /var/lib/reqad/sieve/bin. No dnf, no
	# delivery-path change, rolls back if dovecot rejects the config.
	bash /usr/local/reqad/scripts/update/setup_sieve_pipe.sh

	# Skip-RBL exemption list. The old /etc/exim/skiprblhosts was hand-maintained
	# and recorded nothing about where any address came from, so this works out
	# which entries the ESP catalogue now covers and keeps the rest as the
	# operator's own additions. One-shot: it does nothing once the file on disk is
	# a generated one. Leaves exim.conf alone -- applying the list to the dnslists
	# conditions is a deliberate choice made in the panel.
	bash /usr/local/reqad/scripts/update/seed_skiprbl_extra.sh || true

	# First install, or the migration declined to run: make sure the list exists
	# so a later "Apply to every blocklist check" has something to point at.
	if [ ! -s /etc/exim/skiprblhosts ]; then
		/usr/local/reqad/scripts/mail/build-skiprbl.sh --quiet || true
	fi

	# Keep dovecot's duplicate database out of the IMAP folder list. Home IS the
	# maildir root in this layout, so ~/.dovecot.lda-dupes was showing up in every
	# client as a mailbox. Config-only + removal of the empty junk mailboxes.
	bash /usr/local/reqad/scripts/update/fix_maildir_dotfiles.sh

	# Restore the IMAP hierarchy separator to '.'. migrate_dovecot_2.4.sh wrote
	# "separator = /", but the storage is Maildir++ where '.' is the on-disk
	# separator. That renamed every nested folder out from under existing clients,
	# made '.' illegal in a mailbox name ("Character not allowed in mailbox name:
	# '.'" when a client asked for a name it had cached), and invalidated dotted
	# entries in subscriptions files so LSUB returned INBOX only. Config-only plus
	# a '/'->'.' rewrite of subscriptions; no mail is touched, rolls back if
	# dovecot rejects the config.
	bash /usr/local/reqad/scripts/update/fix_imap_separator.sh

	# Drop ::1 from the ManageSieve listener. It used to be written as
	# "listen = 127.0.0.1, ::1", and on a box with IPv6 disabled dovecot cannot
	# bind ::1 — which is FATAL, not a warning ("bind(::1, 4190) failed" ->
	# "Fatal: Failed to start listeners"), so the entire mail server stays down
	# over a loopback-only service. Also repoints roundcube's managesieve plugin
	# at 127.0.0.1:4190, since 'localhost' resolves to ::1 first on a dual-stack
	# box. Config-only, no-op once IPv4-only, rolls back if dovecot objects.
	bash /usr/local/reqad/scripts/update/fix_managesieve_listen.sh

	# Advertise SPECIAL-USE (RFC 6154). Without it the server never tells clients
	# which folder is Sent/Drafts/Trash/Junk, so each one guesses — Apple Mail
	# creates its own "Sent Messages" and sent mail goes missing from "Sent".
	# Config-only, no-op once declared, rolls back if dovecot objects. The
	# already-split folders are merged separately and by hand:
	#     bash /usr/local/reqad/scripts/update/merge_duplicate_sent.sh [--apply]
	# (that one MOVES mail, so it is deliberately not run from an update).
	bash /usr/local/reqad/scripts/update/setup_special_use.sh

	# Older setup_spam_score_logging.sh truncated the distro's stock exim.conf
	# (local-only exim, email=0) from the commented-out SpamAssassin example to
	# EOF -- no routers, no transports, every address "Unrouteable", root mail
	# never reached the /etc/aliases pipe. Puts back the newest backup that still
	# has its routers; the idempotent exim scripts below then re-apply. Must run
	# before them. No-op on a complete config.
	bash /usr/local/reqad/scripts/update/repair_truncated_exim_conf.sh

	# Put the SpamAssassin score of every inbound message in the exim main log,
	# and tag messages with the cPanel-compatible X-Spam-* header set
	# (X-Spam-Status / X-Spam-Score / X-Spam-Bar / X-Spam-Flag / X-Spam-Report),
	# which is what the panel's email-filter builder already offers. The old
	# config hung its log_message off an `accept`, where exim never writes it, so
	# no score reached the log at all. Scanning becomes inbound-only: authenticated
	# submissions and localhost injections skip it. It also scans as the hosting
	# account instead of "nobody", so SpamAssassin finally loads that account's
	# ~/.spamassassin/user_prefs -- the whitelist/blocklist the Spam Filters page
	# writes was read by nothing at this stage before. Config-only, idempotent
	# (it normalises the block, so a half-applied config is upgraded), and
	# validated with `exim -bV -C` before it replaces anything.
	#
	# It also RETIRES the spamcheck router + spam_check transport, which piped
	# every message through `spamc -u <account>` and re-injected it -- a second
	# full scan, a second message id and a second set of log lines per message,
	# pointless now that the ACL scans as the account. Locally generated mail
	# (cron, PHP mail(), P=local) never reaches the DATA ACL and is therefore no
	# longer scanned, same as cPanel. And it raises the skip-if-too-big cut-off
	# from 100 KB to 512 KB, which is where most real mail was falling through
	# unscanned.
	bash /usr/local/reqad/scripts/update/setup_spam_score_logging.sh

	# The other half of that change: spamd was started with `-u spamd`, which
	# makes it drop root and run as that one user for every message, so it can
	# never setuid to the account being scanned and never reads its
	# ~/.spamassassin/user_prefs -- the Spam Filters whitelist/blocklist, mode
	# 0600 and owned by the account. Nothing logs this: exim still says
	# "SpamAssassin as <account>", because that text is exim's, not spamd's.
	# Strips -u/-g/-x from SPAMDOPTIONS and restarts spamd, rolling back if it
	# does not come up. No-op where spamd already runs as root.
	bash /usr/local/reqad/scripts/update/setup_spamd_per_user.sh

	# Stock exim.service has no ExecReload, so `systemctl reload exim` fails with
	# "Job type reload is not applicable" -- and the panel reloads exim after
	# every mail-config save. The failure was silent: file changed, daemon went
	# on running the old configuration, panel reported success. Installs a
	# drop-in that sends SIGHUP, which is how exim re-reads its config.
	bash /usr/local/reqad/scripts/update/setup_exim_reload.sh

	# Outbound sending limits: the two cPanel controls (max hourly mail per
	# domain relayed, max failed messages per hour), global rather than
	# per-domain, plus a switch for mail injected with sendmail(8) or relayed
	# through localhost without authenticating. Installs the macros the panel
	# edits (Email > Exim > Sending limits) and the ACL rules that read them,
	# all inside marker blocks; counting is exim's own ratelimit condition.
	# Ships in FREEZE mode: over-limit mail is accepted and held in the queue
	# for review rather than refused, so nothing bounces while you tune it.
	# Values an admin has already set are preserved across an update.
	# Idempotent, splices on content, validated with `exim -bV -C` first.
	bash /usr/local/reqad/scripts/update/setup_mail_limits.sh

	# Mail to root (cron output, mdadm/smart warnings, Reqad's own backup
	# reports) piped to the panel's forwarder deferred on every server with the
	# forwarding switch on: system_aliases handed the pipe to address_pipe, which
	# takes its user from the recipient domain's owner, and root@<hostname> has
	# no owner -- "Failed to find user """ and the mail sat in the queue until it
	# timed out. Gives /etc/aliases pipes their own transport with a fixed user.
	# Idempotent, validated with `exim -bV -C` before it installs anything.
	bash /usr/local/reqad/scripts/update/fix_system_alias_pipe.sh

	# Mail to root while forwarding is off (no root: line in /etc/aliases, the
	# default on a new server) went to local_delivery as root, which never_users
	# refuses, so every cron/lfd/monit/sudo message sat in the queue for days.
	# A router before localuser now pipes it, as reqad, to
	# scripts/root_mail_log.sh: /var/log/reqad/root-mail, 10 MB plus one older
	# copy, 0600. An alias or /root/.forward still wins. Idempotent, validated
	# with `exim -bV -C` before it installs anything.
	bash /usr/local/reqad/scripts/update/setup_root_mail_log.sh

	# Don't forward mail marked as spam. The forwards router passed on everything
	# SpamAssassin tagged X-Spam-Flag: YES (5-20 points; over 20 is refused at
	# DATA) to Gmail/Outlook, which scores this server's IP for it. Adds the
	# REQAD_NO_FORWARD_SPAM macro (default yes, panel: Email > Exim > Settings >
	# Forwarding) and folds it into the router's condition -- a declined router,
	# so `unseen` still delivers the local mailbox copy. Keeps an admin's value.
	# Idempotent, validated with `exim -bV -C` before it installs anything.
	bash /usr/local/reqad/scripts/update/setup_forward_spam_gate.sh

	# Mail of a hosted domain handled by another provider (Domains > DNS zone >
	# Mail provider on 2083): exim delivered mail the server itself sends to
	# such a domain into the mailboxes here, because /etc/exim/domains/<domain>
	# makes it local. local_domains now leaves out the domains listed in
	# /etc/exim/remotedomains (created empty). Only the stock line is changed.
	# Idempotent, validated with `exim -bV -C` before it installs anything.
	bash /usr/local/reqad/scripts/update/setup_exim_remote_domains.sh

	# `dnf update` upgrades what is installed and never ADDS a subpackage, so a
	# server that never installed dovecot-sieve stays without it through every
	# update — and the filter config then fails to parse. Report only: dnf cannot
	# run from here (this transaction holds the rpmdb lock).
	bash /usr/local/reqad/scripts/update/ensure_dovecot_packages.sh

	# Dovecot mail compression (the 2.4 replacement for the 2.3 'zlib' plugin).
	# OFF BY DEFAULT — a new install must not come up with it enabled, so this is
	# --repair, NOT a bare call (which would enable it).
	#
	# --repair no-ops unless the server already uses compression, and re-applies
	# the config where it does. Running it every update is deliberate rather than
	# wasteful: a Dovecot package migration rewrites /etc/dovecot, and if
	# compress.conf is lost on a server that already has gzipped mail on disk,
	# every one of those messages is served to clients as raw gzip bytes. It keeps
	# whatever vsz_limit was already configured rather than resetting it.
	#
	# Unlike the FTS/Sieve scripts below it needs no dnf, so there is no rpmdb lock
	# to deadlock on. Idempotent, self-validating, rolls back if doveconf or
	# Dovecot reject the result.
	#
	# Admins enable it from the Dovecot settings page in the panel, or by running
	# the script by hand with no arguments.
	bash /usr/local/reqad/scripts/update/setup_dovecot_compress.sh --repair

	# The imap/pop3 vsz_limit used to live in compress.conf, so it could only be
	# set while compression was on. It has its own file now
	# (/etc/dovecot/limits.conf). --migrate lifts a legacy value out of
	# compress.conf and is a no-op on a server that has none, so this is safe to
	# run unconditionally. It must come AFTER the compression repair above, which
	# is what rewrites compress.conf without the old blocks.
	bash /usr/local/reqad/scripts/update/setup_dovecot_vsz.sh --migrate

	# The mailbox list driving scripts/compress_old_mail. Created empty (comments
	# only) so the nightly cron job is a no-op until an admin opts a mailbox in;
	# never overwritten, since it holds the admin's choices.
	if [ ! -f /usr/local/reqad/etc/mail-archive.conf ]; then
		cp -a /usr/local/reqad/etc/mail-archive.conf.default \
		      /usr/local/reqad/etc/mail-archive.conf
	fi
	# Dovecot 2.4 full-text search (flatcurve/Xapian) is OPT-IN — do NOT uncomment.
	# It needs 'dnf install dovecot-flatcurve', and we are inside an rpm
	# transaction here: the rpmdb lock is already held, so dnf from a %post
	# scriptlet fails or deadlocks. (It would also restart Dovecot and index every
	# mailbox — the admin's call, not an update's.) The script detects a running
	# transaction and refuses anyway. Enable it by hand instead:
	#     bash /usr/local/reqad/scripts/update/setup_dovecot_fts.sh
	# Idempotent, no-ops unless Dovecot 2.4 is installed, and rolls itself back if
	# doveconf rejects the config — safe to re-run, and worth re-running after a
	# Dovecot package migration (which rewrites /etc/dovecot).
	#bash /usr/local/reqad/scripts/update/setup_dovecot_fts.sh

	# Dovecot LMTP + Pigeonhole Sieve (the three-tier email filters) is also
	# OPT-IN — do NOT uncomment. It runs dnf, and we hold the rpmdb lock here, and
	# it changes the live mail delivery path (exim appendfile -> dovecot LMTP),
	# which is an admin decision. It refuses to run inside an rpm transaction
	# anyway. Enable by hand:
	#     bash /usr/local/reqad/scripts/update/setup_dovecot_sieve.sh --check
	#     bash /usr/local/reqad/scripts/update/setup_dovecot_sieve.sh
	# Idempotent, gated on check_lmtp_readiness.sh, rolls back if dovecot or exim
	# reject the config.
	#bash /usr/local/reqad/scripts/update/setup_dovecot_sieve.sh

	# Sieve script dirs are safe to create unconditionally (no dnf, no restart);
	# a missing domain script is a no-op for pigeonhole.
	#
	# These live under /var/lib/reqad/, NOT /var/lib/dovecot/: the dovecot spec
	# ships `%attr(0750,dovecot,dovecot) /var/lib/dovecot`, so every dovecot
	# package upgrade resets that mode and the LMTP process (which setuids to
	# the mailbox owner) loses +x on it. Local delivery then defers with
	# 451 "Temporarily unable to access necessary Sieve scripts".
	mkdir -p /var/lib/reqad/sieve/domains /var/lib/reqad/sieve/autoresponders
	chown -R dovecot:dovecot /var/lib/reqad/sieve 2>/dev/null
	chmod 755 /var/lib/reqad/sieve /var/lib/reqad/sieve/domains /var/lib/reqad/sieve/autoresponders 2>/dev/null

	# Move any scripts written under the old location, and repoint a live
	# 90-sieve.conf that still refers to it. Both are no-ops on fresh installs.
	if [ -d /var/lib/dovecot/sieve ]; then
		cp -a /var/lib/dovecot/sieve/. /var/lib/reqad/sieve/ 2>/dev/null
		rm -rf /var/lib/dovecot/sieve
		chown -R dovecot:dovecot /var/lib/reqad/sieve 2>/dev/null
	fi
	if [ -f /etc/dovecot/conf.d/90-sieve.conf ] && \
	   grep -q '/var/lib/dovecot/sieve' /etc/dovecot/conf.d/90-sieve.conf; then
		sed -i 's#/var/lib/dovecot/sieve#/var/lib/reqad/sieve#g' \
			/etc/dovecot/conf.d/90-sieve.conf
		systemctl reload dovecot 2>/dev/null || systemctl restart dovecot 2>/dev/null
	fi
	# Remove clean() from a live defines.php — it now lives in modules/functions.php.
	# On installs whose defines.php is preserved across updates, the old definition
	# would clash with the new one and fatal with "Cannot redeclare clean()".
	# Idempotent: does nothing if clean() is no longer present.
	DEFINES="/usr/local/reqad/public_html/defines.php"
	if [ -f "$DEFINES" ] && grep -q '^[[:space:]]*function[[:space:]]\+clean[[:space:]]*(' "$DEFINES"; then
		sed -i '/^[[:space:]]*function[[:space:]]\+clean[[:space:]]*(/,/^[[:space:]]*}/d' "$DEFINES"
		grep -q 'clean() moved to modules/functions.php' "$DEFINES" || \
			sed -i "/^define('_PATH'/i /* clean() moved to modules/functions.php */\n" "$DEFINES"
		echo "  removed clean() from defines.php (now in modules/functions.php)"
	fi
) >> /usr/local/reqad/log/post_install.log 2>&1

if [ -f /etc/exim/exim.conf ]; then
# Migrate exim.conf to use wildlsearch SNI lookup (idempotent)
if ! grep -q 'wildlsearch.*sni_certs' /etc/exim/exim.conf 2>/dev/null; then
    python3 << 'EOF'
import re
conf = open('/etc/exim/exim.conf').read()
conf = re.sub(
    r'tls_certificate\s*=\s*\${if and.*?\n\}',
    'tls_certificate = ${lookup{$tls_in_sni}wildlsearch{/etc/exim/sni_certs.db}{$value}{/etc/pki/tls/certs/exim.pem}}',
    conf, flags=re.DOTALL
)
conf = re.sub(
    r'tls_privatekey\s*=\s*\${if and.*?\n\}',
    'tls_privatekey  = ${lookup{$tls_in_sni}wildlsearch{/etc/exim/sni_keys.db}{$value}{/etc/pki/tls/private/exim.pem}}',
    conf, flags=re.DOTALL
)
lines = conf.splitlines(keepends=True)
lines = [l for l in lines if not re.match(r'\s*tls_(certificate|privatekey)\s*=', l)]
conf = ''.join(lines)
if not re.search(r'^tls_certificate\s*=', conf, re.MULTILINE):
    insert = ('tls_certificate = ${lookup{$tls_in_sni}wildlsearch{/etc/exim/sni_certs.db}{$value}{/etc/pki/tls/certs/exim.pem}}\n'
              'tls_privatekey  = ${lookup{$tls_in_sni}wildlsearch{/etc/exim/sni_keys.db}{$value}{/etc/pki/tls/private/exim.pem}}\n')
    conf = re.sub(
        r'(tls_advertise_hosts\s*=.*\n)',
        lambda m: m.group(1) + insert,
        conf
    )
open('/etc/exim/exim.conf', 'w').write(conf)
EOF
    systemctl restart exim
fi
# Patch virtual_autoreply router: dsearch detaint for local_part + Sieve vacation options (idempotent)
if ! grep -q 'sieve_vacation_directory' /etc/exim/exim.conf 2>/dev/null; then
    python3 -c "
import re
conf = open('/etc/exim/exim.conf').read()
# Build the replacement block
new_block = (
    'virtual_autoreply:\n'
    '  driver = redirect\n'
    '  domains = dsearch;/etc/exim/autoreply\n'
    '  hide_child_in_errmsg\n'
    '  ignore_eacces\n'
    '  ignore_enotdir\n'
    '  reply_transport = autoreply\n'
    '  no_verify\n'
    r'  require_files = /etc/exim/autoreply/\${domain_data}/\${lookup{\$local_part}dsearch{/etc/exim/autoreply/\${domain_data}}{\$value}fail}' + '\n'
    r'  file = /etc/exim/autoreply/\${domain_data}/\${lookup{\$local_part}dsearch{/etc/exim/autoreply/\${domain_data}}{\$value}fail}' + '\n'
    '  allow_filter\n'
    r'  sieve_useraddress = \${local_part}@\${domain}' + '\n'
    r'  sieve_vacation_directory = /etc/exim/autoreply/\${domain_data}' + '\n'
    '  user = exim\n'
    '  group = mail\n'
    '  unseen\n'
    '  no_expn\n'
)
conf = re.sub(r'virtual_autoreply:.*?(?=\n\S|\Z)', new_block.rstrip(), conf, count=1, flags=re.DOTALL)
open('/etc/exim/exim.conf', 'w').write(conf)
"
    systemctl restart exim
fi
# Patch autoreply transport: add return_path so DKIM signing works on re-injected replies (idempotent)
if ! grep -q 'return_path = \${local_part}@\${domain}' /etc/exim/exim.conf 2>/dev/null; then
    sed -i '/^autoreply:/{n;n;n;s/\(  from = \${local_part}@\${domain}\)/\1\n  return_path = ${local_part}@${domain}/}' /etc/exim/exim.conf
    systemctl restart exim
fi
# Patch DKIM_DOMAIN macro: fall back to From header domain for null-sender messages (e.g. autoresponder replies)
if ! grep -q 'h_from' /etc/exim/exim.conf 2>/dev/null; then
    python3 << 'EOF'
conf = open('/etc/exim/exim.conf').read()
old = 'DKIM_DOMAIN = ${lookup{$sender_address_domain}dsearch{/etc/exim/domains/}}'
new = 'DKIM_DOMAIN = ${if eq{$sender_address_domain}{}{${lookup{${lc:${domain:$h_from:}}}dsearch{/etc/exim/domains/}}}{${lookup{$sender_address_domain}dsearch{/etc/exim/domains/}}}}'
if old in conf:
    conf = conf.replace(old, new, 1)
    open('/etc/exim/exim.conf', 'w').write(conf)
EOF
    systemctl restart exim
fi
/usr/local/reqad/scripts/update_email_sni >> /usr/local/reqad/log/post_install.log 2>&1
fi

rm -f /usr/local/reqad/log/dashboard_*.cache

# Apache: certbot put the https vhost of sites that had no :443 block into
# <site>-le-ssl.conf, which the panel never reads or edits (certificate shown
# missing, suspension / redirects / PHP settings missed https, deleting the
# site left it serving). Move each into its site's own <site>.conf; the panel
# does the same after its own certbot runs. Before setup_autoconfig, so that
# sees the merged files. No-op without httpd; tests each site with httpd -t
# and puts it back on a failure; backups in /var/lib/reqad/vhost-backups/.
bash /usr/local/reqad/scripts/update/merge_le_ssl_vhosts.sh 2>&1 | tee -a /usr/local/reqad/log/post_install.log

# Mail client autoconfiguration (Thunderbird / Outlook). Installs the nginx
# location snippet and the apache Directory grant, backfills the include into
# vhosts that predate the feature, and generates the static XML files into
# /var/lib/reqad/autoconfig. Run unconditionally, not only when email is on:
# the snippet is harmless without mail (every request 404s, which is what makes
# Thunderbird fall through to the ISPDB), and this way the vhost include is
# already in place if the admin enables email later. Safe to re-run; rolls the
# vhosts back by itself if the config test fails.
bash /usr/local/reqad/scripts/update/setup_autoconfig.sh >> /usr/local/reqad/log/post_install.log 2>&1

# Advanced Config version-history backups live here (created on first save,
# pruned to the last 5 per file). Ensure the dir exists and is reqad-owned.
mkdir -p /usr/local/reqad/backup/config

# Debug page dumps (_shot_*.html) were accidentally packaged in 1.0.45-4 ..
# 1.0.46-2 and installed into the docroot. The rpm upgrade normally unlinks
# them itself, but remove any that survived (locally modified copies are left
# behind by rpm). Harmless no-op on clean installs.
rm -f /usr/local/reqad/public_html/_shot_*.html

# csf CVE-2026-67402 / CPANEL-55649: untrusted text (a PTR record, a GeoIP name,
# a comment relayed by a cluster peer) reaches the comment field of csf.deny /
# csf.allow / csf.ignore / csf.tempban, which are read back an entry per physical
# line - so a line separator in it appends a whole attacker-chosen rule instead
# of corrupting the one being written. Upstream csf is EOL (configserver.com is
# gone, cPanel took it over and does not publish sources), so the fix is ours to
# carry: scripts/patch_csf_cve.pl holds the edits, and the csf.tgz we host is
# already repacked with them, so this only brings existing servers up.
#
# Silent no-op when csf is absent or already patched. Refuses and leaves the tree
# untouched on a csf it does not recognise, and rolls back if the result stops
# compiling - never leaves a half-patched firewall behind.
bash /usr/local/reqad/scripts/update/patch_csf_cve_2026_67402.sh 2>&1 | tee -a /usr/local/reqad/log/post_install.log

# Account vhosts used to list the "deny hidden files" and "deny PHP in uploads"
# blocks AFTER `location ~ \.php$` inside `location /`. nginx matches nested
# regex locations in file order, so PHP in wp-content/uploads was executed and
# the deny never fired. The template is fixed for new accounts; this moves the
# blocks above the PHP handler in existing vhosts (idempotent, backs up to
# /var/lib/reqad/vhost-backups/, restores everything if nginx -t fails).
bash /usr/local/reqad/scripts/update/fix_vhost_deny_order.sh 2>&1 | tee -a /usr/local/reqad/log/post_install.log

# Generic error pages for every site (no server name, 403 answered as 404) and
# fastcgi_intercept_errors off, so WordPress keeps its own 404/maintenance
# pages. nginx snippet + vhost includes, Apache conf.d file; idempotent,
# backs up vhosts, restores on a failed nginx -t / httpd -t.
bash /usr/local/reqad/scripts/update/setup_error_pages.sh 2>&1 | tee -a /usr/local/reqad/log/post_install.log

# Apache: index.php before index.html / index.htm (the stock httpd.conf +
# php.conf order is the other way round; nginx vhosts already list index.php
# first). Server-wide conf.d file, httpd -t checked; no-op without httpd.
bash /usr/local/reqad/scripts/update/setup_directory_index.sh 2>&1 | tee -a /usr/local/reqad/log/post_install.log

# Apache: the stock userdir.conf gives /home/*/public_html fewer .htaccess
# rights than the site vhosts, so an addon site in ~/public_html/<site> answered
# 500 when the main site's .htaccess had "Options ...". Same rights as the
# vhosts; httpd -t checked; no-op without httpd.
bash /usr/local/reqad/scripts/update/setup_httpd_home_overrides.sh 2>&1 | tee -a /usr/local/reqad/log/post_install.log

# The cron used to chmod -R a+rwx /var/cache/nginx, /var/cache/reqad and
# /var/lib/php/session every minute, so any account could overwrite another
# site's cached pages or read its session ids. The cron lines are gone; this
# resets the trees to 0700/0600 and the session dir to 1733. Idempotent.
bash /usr/local/reqad/scripts/update/fix_cache_perms.sh 2>&1 | tee -a /usr/local/reqad/log/post_install.log

# Re-render the WP Toolkit Security tab's nginx rules for sites that have some,
# so rule changes in an update reach them without pressing Apply (e.g. blocked
# paths now answer 404, not 403). No-op for sites already current.
/opt/reqad/php-current/usr/bin/php -c /etc/reqad/php-fpm/php.ini /usr/local/reqad/scripts/update/refresh_wp_security.php 2>&1 | tee -a /usr/local/reqad/log/post_install.log

# Same for the WP Toolkit page cache blocks: campaign links (utm_*, gclid...)
# served from the cache, each site's own cache rules (db 1054). No-op for
# sites already current; old files kept in /var/lib/reqad/vhost-backups/.
bash /usr/local/reqad/scripts/update/update_wp_cache_blocks.sh 2>&1 | tee -a /usr/local/reqad/log/post_install.log

# The panel vhost used `satisfy any; allow 127.0.0.1; [allow <server ip>;]`, so any
# request from the server itself skipped the password - including every hosting
# account (hacked plugin, cron, SSH), and the panel runs as reqad (sudo to root).
# Drops the bypass from `location /`, adds an unauthenticated nginx-only
# /reqad-health and points monit's 2087 check at it (it fetched / and would now
# get 401 and restart the panel in a loop). Idempotent; restores on nginx -t failure.
bash /usr/local/reqad/scripts/update/fix_panel_ip_bypass.sh 2>&1 | tee -a /usr/local/reqad/log/post_install.log

# Defence in depth for the same hole: a csf post hook rejects connections from
# regular-user uids (>= 1000, i.e. hosting accounts) to the panel ports on
# loopback - 2087/2083, wetty 3000, monit 2812, websocat 2122 - which otherwise
# answer any local client directly. Applied at once, survives csf -r. No-op without csf.
bash /usr/local/reqad/scripts/update/setup_panel_loopback_guard.sh 2>&1 | tee -a /usr/local/reqad/log/post_install.log

# REST API: route /api/ on the panel vhost to app/api/index.php, outside the
# panel login (Bearer tokens only, Cookie header dropped, rate limited). The API
# itself stays off until turned on in Settings > API access / `reqad api on`.
# Idempotent; restores the vhost if nginx -t fails.
bash /usr/local/reqad/scripts/update/setup_panel_api.sh 2>&1 | tee -a /usr/local/reqad/log/post_install.log

# Git deploy webhooks: https://<hostname>/.reqad-deploy/<id> on the hostname
# vhost (nginx in front), passed over loopback to the API, which checks the
# Git host's signature. Idempotent; restores the vhost if nginx -t fails.
bash /usr/local/reqad/scripts/update/setup_deploy_webhook.sh 2>&1 | tee -a /usr/local/reqad/log/post_install.log

# /usr/local/reqad (the reqad home) was 0755 with its secrets world-readable:
# db/reqad.db (DNS/SMTP/backup credentials), nginx_auth (admin hash) and
# backup/*.tar.gz (every account's files, mail and DB dumps). Top dir -> 0750,
# secrets 0600, backup/ 0700. Runs after the defines.php copy (cp -a resets it).
bash /usr/local/reqad/scripts/update/fix_panel_tree_perms.sh 2>&1 | tee -a /usr/local/reqad/log/post_install.log

# Mailbox password hashes readable by dovecot and root only: /etc/dovecot/users
# 0640 root:dovecot (was 0644), migration copies 0600; reverted if dovecot can no
# longer look up a mailbox.
bash /usr/local/reqad/scripts/update/fix_dovecot_users_perms.sh 2>&1 | tee -a /usr/local/reqad/log/post_install.log

# Kernel settings that keep hosting accounts from spying on each other:
# ptrace only of own children, no planted-file races in /tmp (protected_regular
# / protected_fifos), kernel log and addresses for root only. Written to
# /etc/sysctl.d/90-reqad.conf and applied at once; override in 99-local.conf.
bash /usr/local/reqad/scripts/update/setup_sysctl_hardening.sh 2>&1 | tee -a /usr/local/reqad/log/post_install.log

# Cloud images ship authselect "sssd with-sudo" (sudoers: files sss) without
# sssd: every sudo call then logs and mails root "problem with defaults
# entries", hundreds a day into the mail queue. Takes sss out of the sudoers
# source unless sssd is really configured.
bash /usr/local/reqad/scripts/update/fix_sudo_nsswitch.sh 2>&1 | tee -a /usr/local/reqad/log/post_install.log

# Each user sees only their own processes (/proc hidepid=2 in /etc/fstab, applied
# by remount); root and group reqadproc (reqad, polkitd) still see all of them.
bash /usr/local/reqad/scripts/update/setup_proc_hidepid.sh 2>&1 | tee -a /usr/local/reqad/log/post_install.log

# Account permissions: docroot <user>:<web> 0750 (tmp/ and logs/ 0770 on
# mod_php), pools in the account's own group instead of the shared web group,
# wp-config.php not world-readable. Writes a restore script before changing
# anything (/var/lib/reqad/perms-restore-<time>.sh); a no-op once done.
bash /usr/local/reqad/scripts/update/harden_account_perms.sh --apply --quiet 2>&1 | tee -a /usr/local/reqad/log/post_install.log

# Symbolic links: a site may only follow a link to a file of the same owner
# (nginx disable_symlinks; Apache on php-fpm templates: no FollowSymLinks, not
# even from .htaccess), so an account cannot serve another account's files.
bash /usr/local/reqad/scripts/update/setup_symlink_protection.sh 2>&1 | tee -a /usr/local/reqad/log/post_install.log

# Account pools still on the old defaults (pm = dynamic, 10 idle workers per site,
# up to 200) -> pm = ondemand, 20 per site, 10 s idle stop, 300 s stuck-script kill.
# Hand-tuned pools are left alone; a no-op once done.
bash /usr/local/reqad/scripts/update/tune_fpm_pools.sh --apply 2>&1 | tee -a /usr/local/reqad/log/post_install.log

# CPU / memory / process caps for each hosting account's SSH, cron and terminal
# sessions (user-<uid>.slice drop-ins: 100% CPU, 2 GB or 25% of RAM, 512
# processes); accounts with their own limits or "no limit" are left alone.
# Hits are logged to /var/log/reqad/session-limits.log via rsyslog.
bash /usr/local/reqad/scripts/update/setup_session_limits.sh --apply 2>&1 | tee -a /usr/local/reqad/log/post_install.log

# Jailed / SFTP-only shell access (Accounts > Manage > Shell access): keep the
# root-owned copy of bin/reqad-jailshell in /usr/local/bin current (the panel
# tree is 0750, accounts cannot run it from there) and listed in /etc/shells.
bash /usr/local/reqad/scripts/account_shell.sh install 2>&1 | tee -a /usr/local/reqad/log/post_install.log
# root_access=0 in server-software.ini allows no full shell: accounts that still
# have one get the jailed shell, and every account's cron runs in the sandbox.
# No-op with root_access=1.
bash /usr/local/reqad/scripts/account_shell.sh restrict 2>&1 | tee -a /usr/local/reqad/log/post_install.log

# Cron jobs made in the account panel run through /usr/local/bin/reqad-cron
# (records each run, as the account): keep that root-owned copy of
# bin/reqad-cron current (scripts/cron/cron_helper.sh).
bash /usr/local/reqad/scripts/cron/cron_helper.sh install 2>&1 | tee -a /usr/local/reqad/log/post_install.log

# MySQL connection limit per database user of each hosting account (MAX_USER_
# CONNECTIONS, default 30, per account in Accounts > Advanced Config > Limits),
# so one site cannot take all of max_connections. Only users not yet at their
# account's value are changed; a no-op once done.
/usr/local/reqad/bin/reqad account limits --sync-db --quiet 2>&1 | tee -a /usr/local/reqad/log/post_install.log

chown -R reqad:reqad /usr/local/reqad/

# scripts executed via sudo or by exim must be owned by root so the web process cannot tamper with them
# Group reqad, not exim: the alias pipe runs as reqad (system_alias_pipe), which
# is also who owns the panel db it reads and the rate counter it writes. Owner
# stays root so the panel cannot rewrite a script exim executes.
chown root:reqad /usr/local/reqad/scripts/forward_root_mail.php
chmod 0750 /usr/local/reqad/scripts/forward_root_mail.php
# Same for the root mail logger the root_mail_log exim transport runs as reqad.
chown root:reqad /usr/local/reqad/scripts/root_mail_log.sh
chmod 0750 /usr/local/reqad/scripts/root_mail_log.sh
chown root:exim /usr/local/reqad/scripts/setup_root_mail_alias.sh
chmod 750 /usr/local/reqad/scripts/forward_root_mail.php
chmod 750 /usr/local/reqad/scripts/setup_root_mail_alias.sh

# send telemetry after install/update
/usr/local/reqad/scripts/telemetry.php > /dev/null 2>&1 &

# link reqad cli
chmod 755 /usr/local/reqad/bin/reqad
ln -sf /usr/local/reqad/bin/reqad /usr/bin/reqad

echo
echo -e "${WHITE}┌───────────────────────────────────────────────────────────┐" | tee $TTY > /dev/null
echo -e "│${YELLOW} Reqad web interface                                       ${WHITE}│" | tee $TTY > /dev/null
echo    "├───────────────────────────────────────────────────────────┤" | tee $TTY > /dev/null
echo -e "│${GREY} Address: ${WHITE} https://${hostname}:2087/                                        " | cut -c 1-74 -z | tee $TTY > /dev/null ; echo "│" | tee $TTY > /dev/null

FIRST_LOGIN=0
if ! [ -s /usr/local/reqad/nginx_auth ]; then
    FIRST_LOGIN=1
    echo -n 'reqad:' > /usr/local/reqad/nginx_auth
    echo "${password}" | openssl passwd -6 --stdin >> /usr/local/reqad/nginx_auth
    echo -e "│${GREY} User:    ${WHITE} reqad                                                            " | cut -c 1-74 -z | tee $TTY > /dev/null; echo "│" | tee $TTY > /dev/null
    echo -e "│${GREY} Password:${WHITE} ${password}                                                      " | cut -c 1-74 -z | tee $TTY > /dev/null; echo "│" | tee $TTY > /dev/null
else
    echo  -e "│${NC} use the existing user / password                          ${WHITE}│" | tee $TTY > /dev/null
fi

echo -e "└───────────────────────────────────────────────────────────┘" | tee $TTY > /dev/null
echo -e "${NC}" | tee $TTY > /dev/null

# New installs start with the web login page (sessions, 2FA, brute-force
# protection, csf bans); updates keep the mode the server already has, so a
# running server is never switched unattended. Either way: reqad auth mode web|basic.
if [ "$FIRST_LOGIN" = "1" ]; then
    if /usr/local/reqad/bin/reqad auth mode web >> /usr/local/reqad/log/post_install.log 2>&1; then
        echo "Panel login: web login page (set up two-factor authentication under Settings > Security)"
    else
        echo "Panel login: web login could not be enabled (see log/post_install.log) - the browser password prompt is used"
    fi
fi

/usr/bin/systemctl preset reqad.service
if systemctl is-active --quiet reqad.service; then
    /usr/bin/systemctl reload reqad.service
fi
/usr/bin/systemctl enable --now reqad.service

if systemctl is-active --quiet reqad.service; then
    echo -e "[ ${GREEN}RUNNING${NC} ] reqad service is up and running."
else
    echo -e "[ ${RED}NOT RUNNING${NC} ] reqad service is NOT running."
    /usr/bin/systemctl status reqad.service
fi

# Write correct www.conf for all installed remi PHP-FPM versions
for fpm_conf_dir in /etc/opt/remi/php*/php-fpm.d/; do
    [ -d "$fpm_conf_dir" ] || continue
    ver=$(echo "$fpm_conf_dir" | sed 's|/etc/opt/remi/\(php[0-9]*\)/.*|\1|')
    svc="${ver}-php-fpm"
    echo "[www]
user = nobody
group = nobody
listen = /run/php-fpm-default_${ver}.sock
listen.allowed_clients = 127.0.0.1
listen.owner = nobody
listen.group = nobody
listen.mode = 0000

pm = ondemand
pm.max_children = 1
pm.start_servers = 0
pm.min_spare_servers = 0
pm.max_spare_servers = 0
" > "${fpm_conf_dir}www.conf"
    /usr/bin/systemctl enable "${svc}"
    /usr/bin/systemctl restart "${svc}"
done

if systemctl is-active --quiet php82-php-fpm.service; then
    echo -e "[ ${GREEN}RUNNING${NC} ] php82-php-fpm service was successfully restarted."
else
    echo -e "[ ${RED}NOT RUNNING${NC} ] php82-php-fpm service is NOT running."
    /usr/bin/systemctl status php82-php-fpm.service
fi

# The account panel's broker (rpm reqad-account) loads core's code once when it
# starts and forks per request, so it would keep running the old code until a
# restart. try-restart does nothing when it is not installed or not running.
/usr/bin/systemctl try-restart reqad-account-broker.service >/dev/null 2>&1 || true

# moved to config/cron
#cat > /etc/cron.d/reqad << 'EOF'
# 23 */2 *  *  * root /usr/local/reqad/scripts/update_disk_usage > /dev/null 2>&1
# 23 */2 *  *  * root /usr/local/reqad/scripts/update_email_usage > /dev/null 2>&1
#  *  *  *  *  * root /usr/local/reqad/scripts/update_php_versions >> /usr/local/reqad/log/php_versions.log 2>&1
# 25 */2 *  *  * root /usr/local/reqad/scripts/scan_for_wordpress >> /usr/local/reqad/log/scan_wordpress.log 2>&1
#  *  *  *  *  * root /usr/local/reqad/scripts/exim_error_parser.php > /dev/null 2>&1
#  0 */6 *  *  * root /usr/local/reqad/scripts/refresh_php_module_cache.php >> /usr/local/reqad/log/php_module_cache.log 2>&1
#  *  *  *  *  * root /usr/bin/chmod -R a+rwx /var/cache/nginx/ > /dev/null 2>&1
#  *  *  *  *  * root /usr/bin/chmod -R a+rwx /var/cache/reqad/ > /dev/null 2>&1
#  *  *  *  *  * root /usr/bin/chmod -R a+rwx /var/lib/php/session/ > /dev/null 2>&1
#EOF
#/usr/bin/systemctl restart crond
