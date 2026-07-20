#!/bin/bash
# Restore a full hosting account from a backup tarball produced by backup.sh.
# Recreates the system user (original uid/gid + password), homedir, databases,
# web/php config, SSL, email and cron from a single backup-<user>/ archive.
#
# Refuses to touch a live account: if the user, its uid, or its domain already
# exist, it aborts WITHOUT making any change.
#
# Usage: restore.sh /path/to/backup_<user>_<date>.tar.gz [messages.db-token]
#   The optional 16-hex token lets a backgrounded run (from the panel) post its
#   final result to db/messages.db so the Backup page can show a toast.

WHITE='\033[1;29m'
GREEN='\033[0;32m'
RED='\033[0;31m'
NC='\033[0m'

ARCHIVE="$1"
TOKEN="$2"

REQAD='/usr/local/reqad'
SQLITE='/usr/local/bin/sqlite3 -batch -noheader -list'
DB_FILE="${REQAD}/db/reqad.db"
MSG_DB="${REQAD}/db/messages.db"
MYSQL='sudo mysql --defaults-extra-file=/root/.my.cnf'

# Post a result to the flash-message queue (same table obtain_letsencrypt_cert.sh
# and the web app use) when a token was supplied — powers the async toast.
post_msg() {   # $1 = type (success|error|info)  $2 = message
	[ -z "${TOKEN}" ] && return
	echo "${TOKEN}" | grep -qE '^[0-9a-f]{16}$' || return
	local m="${2//\'/\'\'}"
	${SQLITE} "${MSG_DB}" "CREATE TABLE IF NOT EXISTS messages (token TEXT PRIMARY KEY, type TEXT NOT NULL DEFAULT 'info', message TEXT NOT NULL, seen INTEGER NOT NULL DEFAULT 0, created INTEGER NOT NULL);" 2>/dev/null
	${SQLITE} "${MSG_DB}" "INSERT OR REPLACE INTO messages (token,type,message,seen,created) VALUES ('${TOKEN}','$1','${m}',0,strftime('%s','now'));" 2>/dev/null
}

die() { echo -e "${RED}Error: $1${NC}"; post_msg error "$1"; [ -n "${STAGE}" ] && sudo rm -rf "${STAGE}"; exit 1; }

if [ "${ARCHIVE}" == "" ]; then
	echo "Usage: $(basename $0) /path/to/backup_<user>_<date>.tar.gz"
	exit 0
fi
[ -f "${ARCHIVE}" ] || die "archive '${ARCHIVE}' not found."

# ---- identify the account from the single root folder ----------------------
ROOTDIR=$(tar tzf "${ARCHIVE}" 2>/dev/null | head -1 | cut -d/ -f1)
case "${ROOTDIR}" in
	backup-*) USER="${ROOTDIR#backup-}";;
	*) die "'${ARCHIVE}' is not a reqad backup (root folder is '${ROOTDIR}', expected backup-<user>).";;
esac
[ -n "${USER}" ] || die "could not determine the account user from the archive."

# ---- stage everything except the (large) homedir --------------------------
STAGE=$(mktemp -d "${TMPDIR:-/var/tmp}/reqad-restore.${USER}.XXXXXX") || die "cannot create temp dir."
tar xzf "${ARCHIVE}" -C "${STAGE}" --exclude "${ROOTDIR}/homedir" 2>/dev/null || die "failed to extract archive."
R="${STAGE}/${ROOTDIR}"

[ -f "${R}/meta/passwd" ] || die "archive has no meta/passwd — it is a db-only backup and cannot recreate an account."

# system identity (passwd: name:x:uid:gid:gecos:home:shell)
IFS=: read -r PNAME _ PUID PGID _ PHOME PSHELL < "${R}/meta/passwd"
# panel row (account.tsv: id|user|domain|disk_quota|has_email|status|created_at|dkim_selector)
IFS='|' read -r AID AUSER ADOMAIN AQUOTA AHASEMAIL ASTATUS ACREATED ADKIM < "${R}/meta/account.tsv"
DOMAIN="${ADOMAIN}"
[ -n "${DOMAIN}" ] || die "could not determine the account domain from meta/account.tsv."
[ -z "${PHOME}" ] && PHOME="/home/${USER}"
[ -z "${PSHELL}" ] && PSHELL="/bin/bash"
[ -z "${AQUOTA}" ] && AQUOTA=0
[ -z "${AHASEMAIL}" ] && AHASEMAIL=0
[ -z "${ASTATUS}" ] && ASTATUS="active"
[ -z "${ADKIM}" ] && ADKIM="default"

echo -e "${WHITE}Restore account '${USER}' (domain ${DOMAIN}, uid ${PUID})${NC}"

# ---- refuse to overwrite a live account ------------------------------------
id "${USER}" >/dev/null 2>&1 && die "system user '${USER}' already exists — refusing to overwrite."
EXIST_UID=$(getent passwd "${PUID}" | cut -d: -f1)
[ -n "${EXIST_UID}" ] && die "uid ${PUID} is already taken by user '${EXIST_UID}' — cannot restore with the original uid."
EXIST_GID=$(getent group "${PGID}" | cut -d: -f1)
[ -n "${EXIST_GID}" ] && [ "${EXIST_GID}" != "${USER}" ] && die "gid ${PGID} is already taken by group '${EXIST_GID}'."
DUP=$(${SQLITE} "${DB_FILE}" "SELECT count(*) FROM accounts WHERE user='${USER}' OR domain='${DOMAIN}'")
[ "${DUP}" != "0" ] && die "an account with user '${USER}' or domain '${DOMAIN}' already exists in reqad.db."

# ===========================================================================
# 1. system user (original uid/gid + exact password hash)
# ===========================================================================
echo -e "\n${WHITE}── system user ──${NC}"
[ -z "${EXIST_GID}" ] && sudo groupadd -g "${PGID}" "${USER}"
sudo useradd -u "${PUID}" -g "${PGID}" -d "${PHOME}" -s "${PSHELL}" -M "${USER}" || die "useradd failed."
# restore the exact password (hash field 2 of /etc/shadow)
if [ -s "${R}/meta/shadow" ]; then
	SHASH=$(cut -d: -f2 "${R}/meta/shadow")
	[ -n "${SHASH}" ] && echo "${USER}:${SHASH}" | sudo chpasswd -e
fi

# ===========================================================================
# 2. homedir (streamed straight from the archive into /home/<user>)
# ===========================================================================
echo -e "${WHITE}── homedir ──${NC}"
sudo mkdir -p "${PHOME}"
sudo tar xzf "${ARCHIVE}" -C /home \
	--transform "s,^${ROOTDIR}/homedir,${USER}," \
	"${ROOTDIR}/homedir" 2>/dev/null
sudo chown -R "${PUID}:${PGID}" "${PHOME}"
sudo restorecon -R "${PHOME}" 2>/dev/null
echo "restored ${PHOME}"

# ===========================================================================
# 3. databases (create + import + grants)
# ===========================================================================
if [ -d "${R}/databases" ]; then
	echo -e "${WHITE}── databases ──${NC}"
	[ -f "${R}/databases/_create_databases.sql" ] && ${MYSQL} < "${R}/databases/_create_databases.sql"
	for f in "${R}/databases/"*.sql; do
		[ -e "${f}" ] || continue
		b=$(basename "${f}")
		case "${b}" in _*) continue;; esac      # skip _create_databases.sql / _grants.sql
		db="${b%.sql}"
		echo "import ${db}"
		${MYSQL} "${db}" < "${f}"
	done
	[ -f "${R}/databases/_grants.sql" ] && ${MYSQL} < "${R}/databases/_grants.sql"
fi

# ===========================================================================
# 4. web / php config
# ===========================================================================
echo -e "${WHITE}── web/php config ──${NC}"
[ -f "${R}/config/nginx-${DOMAIN}.conf" ]   && sudo cp -p "${R}/config/nginx-${DOMAIN}.conf"   "/etc/nginx/conf.d/${DOMAIN}.conf"   && echo "nginx vhost"
[ -f "${R}/config/httpd-${DOMAIN}.conf" ]   && sudo cp -p "${R}/config/httpd-${DOMAIN}.conf"   "/etc/httpd/conf.d/${DOMAIN}.conf"   && echo "apache vhost"
[ -f "${R}/config/php-fpm-${DOMAIN}.conf" ] && sudo cp -p "${R}/config/php-fpm-${DOMAIN}.conf" "/etc/php-fpm.d/${DOMAIN}.conf"       && echo "php-fpm pool"

# ===========================================================================
# 5. SSL (verbatim — see LE renewal re-setup near the end)
# ===========================================================================
echo -e "${WHITE}── ssl ──${NC}"
LE_RESTORED=0
[ -f "${R}/ssl/${DOMAIN}.crt" ] && sudo cp -p "${R}/ssl/${DOMAIN}.crt" "/etc/ssl/certs/${DOMAIN}.crt"
[ -f "${R}/ssl/${DOMAIN}.key" ] && sudo cp -p "${R}/ssl/${DOMAIN}.key" "/etc/ssl/certs/${DOMAIN}.key"
if [ -d "${R}/ssl/letsencrypt-${DOMAIN}" ]; then
	# Restore the cert files verbatim so the site serves HTTPS immediately. These
	# are plain copies (not certbot's archive/+live symlinks), so auto-renewal is
	# re-established below by re-issuing the cert once nginx is back up.
	sudo mkdir -p "/etc/letsencrypt/live/${DOMAIN}"
	sudo cp -rp "${R}/ssl/letsencrypt-${DOMAIN}/." "/etc/letsencrypt/live/${DOMAIN}/"
	LE_RESTORED=1
fi

# ===========================================================================
# 6. email (exim + dovecot auth + panel rows)
# ===========================================================================
if [ -d "${R}/email" ]; then
	echo -e "${WHITE}── email ──${NC}"
	if [ -f "${R}/email/exim-domain" ]; then
		sudo cp -p "${R}/email/exim-domain" "/etc/exim/domains/${DOMAIN}"
		sudo chown exim:exim "/etc/exim/domains/${DOMAIN}" 2>/dev/null
	fi
	if [ -f "${R}/email/exim-forwards" ]; then
		sudo cp -p "${R}/email/exim-forwards" "/etc/exim/forwards/${DOMAIN}"
		sudo chown exim:exim "/etc/exim/forwards/${DOMAIN}" 2>/dev/null
	fi
	if [ -d "${R}/email/dkim" ]; then
		sudo cp -p "${R}/email/dkim/." /etc/exim/keys/ 2>/dev/null
		sudo chown exim:mail /etc/exim/keys/${DOMAIN}.private.key /etc/exim/keys/${DOMAIN}.public.key 2>/dev/null
	fi
	# userdomains: add the line if absent
	if [ -s "${R}/email/userdomains-entry" ]; then
		UDLINE=$(cat "${R}/email/userdomains-entry")
		sudo grep -qxF "${UDLINE}" /etc/exim/userdomains 2>/dev/null || echo "${UDLINE}" | sudo tee -a /etc/exim/userdomains >/dev/null
	fi
	# dovecot users: append each mailbox line whose email isn't already present
	if [ -s "${R}/email/dovecot-users" ]; then
		while IFS= read -r line; do
			[ -z "${line}" ] && continue
			mail=${line%%:*}
			sudo grep -q "^${mail}:" /etc/dovecot/users 2>/dev/null || echo "${line}" | sudo tee -a /etc/dovecot/users >/dev/null
		done < "${R}/email/dovecot-users"
		echo "restored dovecot mailbox auth lines"
	fi
	# panel emails rows (email|status|created_at)
	if [ -s "${R}/email/_email_accounts.txt" ]; then
		while IFS='|' read -r em st cr; do
			[ -z "${em}" ] && continue
			${SQLITE} "${DB_FILE}" "INSERT OR IGNORE INTO emails (email, disk_usage, disk_quota, status, created_at) VALUES ('${em}', 0, 0, '${st:-active}', '${cr}')"
		done < "${R}/email/_email_accounts.txt"
	fi
fi

# ===========================================================================
# 7. reqad.db accounts row
# ===========================================================================
echo -e "${WHITE}── panel account row ──${NC}"
${SQLITE} "${DB_FILE}" "INSERT INTO accounts (id, user, domain, disk_usage, disk_quota, has_email, status, created_at, dkim_selector) VALUES (${AID}, '${AUSER}', '${ADOMAIN}', 0, ${AQUOTA}, ${AHASEMAIL}, '${ASTATUS}', '${ACREATED}', '${ADKIM}')"

# ===========================================================================
# 8. cron
# ===========================================================================
if [ -f "${R}/cron/crontab" ]; then
	echo -e "${WHITE}── cron ──${NC}"
	sudo cp "${R}/cron/crontab" "/var/spool/cron/${USER}"
	sudo chown "${USER}" "/var/spool/cron/${USER}"
	sudo chmod 600 "/var/spool/cron/${USER}"
	sudo restorecon "/var/spool/cron/${USER}" 2>/dev/null
fi

# ===========================================================================
# 9. DNS — re-apply the zone via the configured provider
# ===========================================================================
DNS_PROVIDER=$(${SQLITE} "${DB_FILE}" "SELECT value FROM settings WHERE name='dns-provider'" 2>/dev/null)
if [ -n "${DNS_PROVIDER}" ] && [ "${DNS_PROVIDER}" != "none" ]; then
	DNS_FILE=""
	[ -f "${R}/dns/zone.json" ] && DNS_FILE="${R}/dns/zone.json"                         # full zone (website bundle)
	[ -z "${DNS_FILE}" ] && [ -f "${R}/dns/dns-email.json" ] && DNS_FILE="${R}/dns/dns-email.json"   # else email-only subset
	if [ -n "${DNS_FILE}" ]; then
		echo -e "${WHITE}── dns: re-applying zone via ${DNS_PROVIDER} ──${NC}"
		if /usr/bin/php82 "${REQAD}/scripts/restore_dns_zone.php" --domain="${DOMAIN}" --file="${DNS_FILE}" 2>>"${REQAD}/log/debug_log"; then
			echo "DNS zone re-applied"
		else
			echo "DNS re-apply reported an error (see log/debug_log)"
		fi
	fi
fi

# ---- relabel restored /etc files + restart services ------------------------
sudo restorecon -F "/etc/nginx/conf.d/${DOMAIN}.conf" "/etc/httpd/conf.d/${DOMAIN}.conf" \
	"/etc/php-fpm.d/${DOMAIN}.conf" "/etc/ssl/certs/${DOMAIN}.crt" "/etc/ssl/certs/${DOMAIN}.key" 2>/dev/null

echo -e "\n${WHITE}── restart services ──${NC}"
# Only reload services that are ALREADY running, so a restore never starts a
# dormant unit (e.g. httpd on an nginx box would grab port 80 from nginx).
for svc in php-fpm nginx httpd exim dovecot; do
	if sudo systemctl is-active --quiet "${svc}"; then
		sudo systemctl reload-or-restart "${svc}" 2>/dev/null && echo "reloaded ${svc}"
	fi
done

# ---- Let's Encrypt: re-establish auto-renewal ------------------------------
# The certs were restored verbatim (serving works now), but certbot's renewal
# state (archive/ + renewal/<domain>.conf) is gone. Re-issue once, in the
# background, now that nginx is back up and the vhost is live. If DNS doesn't yet
# point here certbot just fails and the verbatim certs keep serving until retry.
# Reuses obtain_letsencrypt_cert.sh (sleeps 30s, then `certbot --nginx`).
if [ "${LE_RESTORED}" -eq 1 ] && sudo systemctl is-active --quiet nginx; then
	echo -e "${WHITE}── ssl: re-issuing Let's Encrypt cert in background to restore auto-renewal ──${NC}"
	( cd "${REQAD}/scripts" && ./obtain_letsencrypt_cert.sh "${DOMAIN},www.${DOMAIN}" >/dev/null 2>&1 & )
fi

# recompute disk usage in the background (best effort)
[ -x "${REQAD}/scripts/update_disk_usage" ] && sudo "${REQAD}/scripts/update_disk_usage" >/dev/null 2>&1 &

sudo rm -rf "${STAGE}"
echo -e "\n${GREEN}Done. Account '${USER}' (${DOMAIN}) restored from $(basename ${ARCHIVE}).${NC}"
[ "${LE_RESTORED}" -eq 1 ] && echo "Let's Encrypt auto-renewal re-issue was launched in the background (check log/debug_log)."
post_msg success "Account '${USER}' (${DOMAIN}) restored from $(basename ${ARCHIVE})."
exit 0
