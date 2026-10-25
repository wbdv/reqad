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
SQLITE='/usr/bin/sqlite3 -init /dev/null -batch -noheader -list'
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
# default limits for its SSH, cron and terminal sessions (not in the archive)
sudo /usr/local/reqad/scripts/session_limits.sh set "${USER}" default >/dev/null 2>&1
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
# owner only: the archive keeps each file's group, and the docroots
# (public_html, addon sites) must stay <user>:<web server> or the sites answer
# 404 - a chown -R user:user took that away from every restored site
sudo chown -R "${PUID}" "${PHOME}"
# a group this server does not have came back as a bare number: the account's then
sudo find "${PHOME}" -xdev -nogroup -exec chgrp -h "${PGID}" {} + 2>/dev/null
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

	# databases without the "<user>_" prefix that were assigned to the account
	# on the Databases page: give them back to it (unless another account has
	# been given the same name here in the meantime)
	if [ -f "${R}/databases/_assigned.txt" ]; then
		while read -r adb; do
			[[ "${adb}" =~ ^[A-Za-z0-9_]{1,64}$ ]] || continue
			${SQLITE} "${DB_FILE}" "INSERT OR IGNORE INTO db_owners (dbname, user, created) VALUES ('${adb}', '${USER}', strftime('%s','now'))" 2>/dev/null
			AOWN=$(${SQLITE} "${DB_FILE}" "SELECT user FROM db_owners WHERE dbname='${adb}'" 2>/dev/null)
			if [ "${AOWN}" = "${USER}" ]; then echo "assigned ${adb} to ${USER}"
			else echo -e "${RED}note: ${adb} is assigned to '${AOWN}' on this server, left as it is${NC}"; fi
		done < "${R}/databases/_assigned.txt"
	fi

	# backup_remote.sh leaves databases over --db-max-mb out of the archive and
	# streams their dumps to the backup server instead. The empty database and
	# its grants have just been recreated above, so only the data is missing —
	# say so loudly rather than reporting a complete restore.
	if [ -s "${R}/databases/_streamed_separately.txt" ]; then
		echo -e "${RED}── databases restored EMPTY (dumps are on the backup server) ──${NC}"
		while read -r bigdb; do
			[ -n "${bigdb}" ] || continue
			echo "  ${bigdb}   import with:  zcat ${bigdb}.sql.gz | mysql ${bigdb}"
		done < "${R}/databases/_streamed_separately.txt"
		echo "  (databases/${USER}/<db>.sql.gz in the same dated remote backup dir)"
	fi
	# excluded on the Exclusions tab when the backup was taken: no dump exists
	# anywhere, the empty database and its grants are all there is
	if [ -s "${R}/excludes.txt" ] && grep -q '^db	' "${R}/excludes.txt"; then
		echo -e "${RED}── databases restored EMPTY (excluded from this backup) ──${NC}"
		grep '^db	' "${R}/excludes.txt" | cut -f2 | sed 's/^/  /'
	fi
fi

# ===========================================================================
# 4. web / php config
# ===========================================================================
echo -e "${WHITE}── web/php config ──${NC}"
[ -f "${R}/config/nginx-${DOMAIN}.conf" ]   && sudo cp -p "${R}/config/nginx-${DOMAIN}.conf"   "/etc/nginx/conf.d/${DOMAIN}.conf"   && echo "nginx vhost"
[ -f "${R}/config/httpd-${DOMAIN}.conf" ]   && sudo cp -p "${R}/config/httpd-${DOMAIN}.conf"   "/etc/httpd/conf.d/${DOMAIN}.conf"   && echo "apache vhost"
[ -f "${R}/config/php-fpm-${DOMAIN}.conf" ] && sudo cp -p "${R}/config/php-fpm-${DOMAIN}.conf" "/etc/php-fpm.d/${DOMAIN}.conf"       && echo "php-fpm pool"
# a pool of another PHP version goes back to where it came from, when that version is installed here
restore_pool() {   # $1 = pool file  $2 = file holding its original path  $3 = site
	local PP
	PP=$(head -n1 "$2")
	case "${PP}" in
		/etc/php-fpm.d/*.conf|/etc/opt/remi/php[0-9][0-9]/php-fpm.d/*.conf) ;;
		*) echo "WARNING: $3: odd PHP pool path '${PP}' - pool not restored"; return ;;
	esac
	if [ -d "$(dirname "${PP}")" ]; then
		sudo cp -p "$1" "${PP}" && echo "php-fpm pool of $3 (${PP})"
	else
		echo "WARNING: $3 used a PHP version this server does not have ($(dirname "${PP}")) - its pool was not restored; pick a PHP version for it in the panel."
	fi
}
[ -f "${R}/config/php-pool-${DOMAIN}.conf" ] && [ -s "${R}/config/php-pool-${DOMAIN}.path" ] && restore_pool "${R}/config/php-pool-${DOMAIN}.conf" "${R}/config/php-pool-${DOMAIN}.path" "${DOMAIN}"

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
# 5b. addon domains and subdomains (vhost, PHP pool, certificate; their files
#     came back with the homedir). One that exists here already is skipped.
# ===========================================================================
LE_ADDONS=''
ADDONS_DONE=''
ADDONS_SKIPPED=''
if [ -d "${R}/addons" ]; then
	echo -e "${WHITE}── addon domains ──${NC}"
	for AO in "${R}"/addons/*/; do
		[ -d "${AO}" ] || continue
		AD=$(basename "${AO}")
		echo "${AD}" | grep -qE '^[a-z0-9.-]+$' || continue
		INUSE=$(${SQLITE} "${DB_FILE}" "SELECT count(*) FROM accounts WHERE domain='${AD}'" 2>/dev/null)
		if [ -e "/etc/nginx/conf.d/${AD}.conf" ] || [ -e "/etc/httpd/conf.d/${AD}.conf" ] || [ "${INUSE:-0}" != "0" ]; then
			echo "WARNING: ${AD} is already on this server - skipped (its files are in the home folder)."
			ADDONS_SKIPPED="${ADDONS_SKIPPED} ${AD}"
			continue
		fi
		[ -f "${AO}nginx.conf" ] && sudo cp -p "${AO}nginx.conf" "/etc/nginx/conf.d/${AD}.conf"
		[ -f "${AO}httpd.conf" ] && sudo cp -p "${AO}httpd.conf" "/etc/httpd/conf.d/${AD}.conf"
		[ -f "${AO}pool.conf" ] && [ -s "${AO}pool.path" ] && restore_pool "${AO}pool.conf" "${AO}pool.path" "${AD}"
		[ -f "${AO}ssl.crt" ] && sudo cp -p "${AO}ssl.crt" "/etc/ssl/certs/${AD}.crt"
		[ -f "${AO}ssl.key" ] && sudo cp -p "${AO}ssl.key" "/etc/ssl/certs/${AD}.key"
		if [ -d "${AO}letsencrypt" ]; then
			sudo mkdir -p "/etc/letsencrypt/live/${AD}"
			sudo cp -rp "${AO}letsencrypt/." "/etc/letsencrypt/live/${AD}/"
			LE_ADDONS="${LE_ADDONS} ${AD}"
		fi
		sudo restorecon -F "/etc/nginx/conf.d/${AD}.conf" "/etc/httpd/conf.d/${AD}.conf" "/etc/ssl/certs/${AD}.crt" "/etc/ssl/certs/${AD}.key" 2>/dev/null
		ADDONS_DONE="${ADDONS_DONE} ${AD}"
		echo "${AD}"
	done
fi

# docroots <user>:<web server of THIS server> (the backup may come from a server with another one)
[ -x "${REQAD}/scripts/update/harden_account_perms.sh" ] && sudo "${REQAD}/scripts/update/harden_account_perms.sh" --apply --user="${USER}" --quiet >/dev/null 2>&1

# ===========================================================================
# 6. email (exim + dovecot auth + panel rows)
# ===========================================================================
# One mail domain's settings from $2 (email/ for the main domain,
# email/domains/<domain>/ for the account's other mail domains).
restore_mail_domain() {   # $1 = domain  $2 = folder
	local D="$1" IN="$2"
	if [ -f "${IN}/exim-domain" ]; then
		sudo cp -p "${IN}/exim-domain" "/etc/exim/domains/${D}"
		sudo chown exim:exim "/etc/exim/domains/${D}" 2>/dev/null
	fi
	if [ -f "${IN}/exim-forwards" ]; then
		sudo cp -p "${IN}/exim-forwards" "/etc/exim/forwards/${D}"
		sudo chown exim:exim "/etc/exim/forwards/${D}" 2>/dev/null
	fi
	if [ -d "${IN}/dkim" ]; then
		sudo cp -p "${IN}"/dkim/*.key /etc/exim/keys/ 2>/dev/null
		sudo chown exim:mail /etc/exim/keys/${D}.private.key /etc/exim/keys/${D}.public.key 2>/dev/null
	fi
	# userdomains: add the line if absent
	if [ -s "${IN}/userdomains-entry" ]; then
		UDLINE=$(cat "${IN}/userdomains-entry")
		sudo grep -qxF "${UDLINE}" /etc/exim/userdomains 2>/dev/null || echo "${UDLINE}" | sudo tee -a /etc/exim/userdomains >/dev/null
	fi
	# its mail handled by another provider: back on /etc/exim/remotedomains
	if [ -s "${IN}/remote-mail" ] && [ -f /etc/exim/remotedomains ]; then
		sudo grep -qxF "${D}" /etc/exim/remotedomains 2>/dev/null || echo "${D}" | sudo tee -a /etc/exim/remotedomains >/dev/null
	fi
	# dovecot users: append each mailbox line whose email isn't already present
	if [ -s "${IN}/dovecot-users" ]; then
		while IFS= read -r line; do
			[ -z "${line}" ] && continue
			mail=${line%%:*}
			sudo grep -q "^${mail}:" /etc/dovecot/users 2>/dev/null || echo "${line}" | sudo tee -a /etc/dovecot/users >/dev/null
		done < "${IN}/dovecot-users"
		echo "restored dovecot mailbox auth lines"
	fi

	# ---- email filters: domain tier ---------------------------------------
	# Per-account Sieve scripts need nothing here — ~/sieve and ~/.dovecot.sieve
	# live inside the maildir and came back with homedir/mail/ above.
	if [ -s "${IN}/email_filters.sql" ]; then
		${SQLITE} "${DB_FILE}" < "${IN}/email_filters.sql" 2>/dev/null
		echo "restored domain email filter rows"
	fi
	# Install the rendered script through ef-helper rather than copying it: the
	# helper writes it dovecot-owned and recompiles it AT ITS FINAL PATH, which a
	# .svbin records and a copied binary would get wrong.
	if [ -s "${IN}/sieve-domain.sieve" ]; then
		if sudo "${REQAD}/scripts/email-filters/ef-helper.sh" system-put domain "${D}" < "${IN}/sieve-domain.sieve" >/dev/null 2>&1; then
			echo "restored domain Sieve filter script"
		else
			echo "WARNING: domain Sieve script failed to compile — the filter rows were"
			echo "         restored, but no script is active. Re-save the filters in the panel."
		fi
	fi

	# ---- autoresponders ---------------------------------------------------
	if [ -s "${IN}/autoresponders.sql" ]; then
		${SQLITE} "${DB_FILE}" < "${IN}/autoresponders.sql" 2>/dev/null
		# Re-render the live artefact from the rows just restored. This is the same
		# reconciliation cron runs every 5 minutes, so the autoresponder works
		# immediately instead of up to 5 minutes later — and it renders for THIS
		# machine's backend (Pigeonhole vacation or legacy exim), not the source
		# machine's. Date-scoped responders outside their window stay dormant.
		sudo "${REQAD}/scripts/manage_autoresponders.php" >/dev/null 2>&1
		echo "restored autoresponders"
	fi
}

if [ -d "${R}/email" ]; then
	echo -e "${WHITE}── email ──${NC}"
	restore_mail_domain "${DOMAIN}" "${R}/email"
	for MI in "${R}"/email/domains/*/; do
		[ -d "${MI}" ] || continue
		MD=$(basename "${MI}")
		echo "${MD}" | grep -qE '^[a-z0-9.-]+$' || continue
		case " ${ADDONS_SKIPPED} " in *" ${MD} "*) echo "WARNING: mail of ${MD} skipped (the domain is on this server already)"; continue ;; esac
		if sudo grep -qE "^${MD//./\\.}:" /etc/exim/userdomains 2>/dev/null; then
			echo "WARNING: mail of ${MD} skipped (another account receives its mail here)"
			continue
		fi
		echo "mail domain ${MD}:"
		restore_mail_domain "${MD}" "${MI%/}"
	done
	# The panel's `emails` table is deliberately NOT restored. Since db/1032.sql it
	# is only a du -skm cache keyed by address, rebuilt on demand from
	# /etc/dovecot/users — the mailbox auth lines restored just above are the real
	# inventory. Archives written before 1032 still carry email/_email_accounts.txt;
	# it is ignored, and its disk_quota/status/created_at columns no longer exist.
fi

# ===========================================================================
# 7. reqad.db accounts row
# ===========================================================================
echo -e "${WHITE}── panel account row ──${NC}"
${SQLITE} "${DB_FILE}" "INSERT INTO accounts (id, user, domain, disk_usage, disk_quota, has_email, status, created_at, dkim_selector) VALUES (${AID}, '${AUSER}', '${ADOMAIN}', 0, ${AQUOTA}, ${AHASEMAIL}, '${ASTATUS}', '${ACREATED}', '${ADKIM}')"
# The account's backup exclusion rules. Only lines of exactly the shape
# backup.sh writes, for this user, are run: the file comes from an archive, and
# anything else in it must not reach reqad.db. (A pattern can never hold a
# quote — backup_excludes.sh check refuses it — and a note's are doubled.)
if [ -s "${R}/meta/backup_excludes.sql" ]; then
	grep -E "^INSERT OR IGNORE INTO backup_excludes \(user,kind,pattern,scope,note,created\) VALUES \('${USER}','(path|db)','[^']+','(both|nightly|manual)','([^']|'')*',[0-9]+\);$" \
		"${R}/meta/backup_excludes.sql" | ${SQLITE} "${DB_FILE}" 2>/dev/null \
		&& echo "backup exclusion rules restored"
fi

# ===========================================================================
# 8. cron
# ===========================================================================
if [ -f "${R}/cron/crontab" ]; then
	echo -e "${WHITE}── cron ──${NC}"
	sudo cp "${R}/cron/crontab" "/var/spool/cron/${USER}"
	sudo chown "${USER}" "/var/spool/cron/${USER}"
	sudo chmod 600 "/var/spool/cron/${USER}"
	sudo restorecon "/var/spool/cron/${USER}" 2>/dev/null
	# jobs made in the account panel run through /usr/local/bin/reqad-cron
	grep -qF '/usr/local/bin/reqad-cron ' "${R}/cron/crontab" && sudo /usr/local/reqad/scripts/cron/cron_helper.sh install
	# jailed accounts: SHELL= kept in step with the restored shell
	sudo /usr/local/reqad/scripts/account_shell.sh cron-sync "${USER}" >/dev/null 2>&1
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
for svc in php-fpm $(systemctl list-units --type=service --state=active --no-legend 'php[0-9][0-9]-php-fpm.service' 2>/dev/null | awk '{print $1}' | sed 's/\.service$//') nginx httpd exim dovecot; do
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

for AD in ${LE_ADDONS}; do
	sudo systemctl is-active --quiet nginx || sudo systemctl is-active --quiet httpd || break
	# the names the restored site answers to (server_name / ServerAlias), as certbot's -d list
	NAMES=$( (sudo grep -m1 -E '^[[:space:]]*server_name[[:space:]]' "/etc/nginx/conf.d/${AD}.conf" 2>/dev/null | sed 's/^[[:space:]]*server_name[[:space:]]*//; s/;.*//') \
		|| true)
	[ -z "${NAMES}" ] && NAMES="${AD}"
	LIST=$(echo "${NAMES}" | tr -s ' ' '\n' | grep -E '^[a-z0-9.-]+$' | paste -sd, -)
	( cd "${REQAD}/scripts" && ./obtain_letsencrypt_cert.sh "${LIST}" >/dev/null 2>&1 & )
done

# mail TLS names for the restored mail domains
[ -d "${R}/email" ] && sudo "${REQAD}/scripts/update_email_sni" >/dev/null 2>&1 &

# recompute disk usage in the background (best effort)
[ -x "${REQAD}/scripts/update_disk_usage" ] && sudo "${REQAD}/scripts/update_disk_usage" >/dev/null 2>&1 &

# ---- what the backup never had ---------------------------------------------
# excludes.txt (written by backup.sh) lists what was left out on purpose. Say
# it here, or a restore with no node_modules/ looks like a broken one.
EXCL_NOTE=''
if [ -s "${R}/excludes.txt" ]; then
	EXCL_N=$(grep -vc '^#' "${R}/excludes.txt")
	echo -e "\n${RED}── not in this backup (excluded when it was taken) ──${NC}"
	grep -v '^#' "${R}/excludes.txt" | while IFS=$'\t' read -r k w d; do
		case "${k}" in
			rule)     echo "  ${w}   (exclusion rule, ${d})" ;;
			mount)    # the mount point itself was excluded too; recreate it empty
			          # so remounting needs no mkdir
			          if [[ "${w}" != *..* ]]; then
			              sudo mkdir -p "${PHOME}/${w}" && sudo chown "${PUID}:${PGID}" "${PHOME}/${w}"
			          fi
			          echo "  ${w}/   (mounted filesystem: ${d} — mount point recreated empty, remount it)" ;;
			marker)   echo "  ${w}/   (folder held a .nobackup file — only the folder was restored)" ;;
			cachedir) echo "  ${w}/   (cache folder, CACHEDIR.TAG)" ;;
			db)       echo "  database ${w}   (restored empty)" ;;
			*)        echo "  ${w}   (${k})" ;;
		esac
	done
	EXCL_NOTE=" ${EXCL_N} excluded item(s) were not in the backup: $(grep -v '^#' "${R}/excludes.txt" | cut -f2 | head -5 | paste -sd ',' | sed 's/,/, /g')$([ "${EXCL_N}" -gt 5 ] && echo ', ...')."
fi

sudo rm -rf "${STAGE}"
echo -e "\n${GREEN}Done. Account '${USER}' (${DOMAIN}) restored from $(basename ${ARCHIVE}).${NC}"
[ "${LE_RESTORED}" -eq 1 ] && echo "Let's Encrypt auto-renewal re-issue was launched in the background (check log/debug_log)."
[ -n "${ADDONS_DONE}" ] && echo "Addon domains restored:${ADDONS_DONE}"
[ -n "${ADDONS_SKIPPED}" ] && echo "Addon domains skipped (already on this server):${ADDONS_SKIPPED}"
ADDON_NOTE=''
[ -n "${ADDONS_SKIPPED}" ] && ADDON_NOTE=" Skipped (already on this server):${ADDONS_SKIPPED}."
post_msg success "Account '${USER}' (${DOMAIN}) restored from $(basename ${ARCHIVE}).${EXCL_NOTE}${ADDON_NOTE}"
exit 0
