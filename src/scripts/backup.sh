#!/bin/bash
WHITE='\033[1;29m'
GREY='\033[1;30m'
GREEN='\033[0;32m'
RED='\033[0;31m'
NC='\033[0m' # No Color

USER=$1
shift 2>/dev/null

if [ "${USER}" == "" ]; then
   echo "Usage: $(basename $0) user [-w] [-m] [-d] [--stage-only DIR] [--db-max-mb N]"
   echo "  -w website (files w/o mail, config, ssl, cron, meta, DNS zone)"
   echo "  -m email   (mail folder, exim/dovecot settings, email DNS records)"
   echo "  -d databases     (-f legacy alias for -w -m)"
   echo "  --stage-only DIR  build backup-<user>/ inside DIR and stop (no tarball,"
   echo "                    no cleanup) - used by backup_remote.sh, which tars the"
   echo "                    staged tree and the homedir straight into an ssh pipe"
   echo "  --db-max-mb N     do not dump databases larger than N MB; list them in"
   echo "                    databases/_streamed_separately.txt instead"
   exit 0
fi

if [ "$(id -u ${USER} 2>/dev/null)" == "" ]; then
   echo "User does not exists"
   exit 0
fi

# ---- what to include -------------------------------------------------------
# -w website : homedir minus mail/, web/php config, ssl, cron, meta, DNS zone
# -m email   : homedir mail/ only, exim/dovecot settings, email DNS records
# -d database
# -f         : legacy alias = -w -m (full account files)
INCLUDE_WEB=0
INCLUDE_MAIL=0
INCLUDE_DB=0
STAGE_ONLY=''
DB_MAX_MB=0
while [ $# -gt 0 ]; do
	case "${1}" in
		-w) INCLUDE_WEB=1 ;;
		-m) INCLUDE_MAIL=1 ;;
		-d) INCLUDE_DB=1 ;;
		-f) INCLUDE_WEB=1; INCLUDE_MAIL=1 ;;
		--stage-only) STAGE_ONLY="${2}"; shift ;;
		--db-max-mb)  DB_MAX_MB="${2}"; shift ;;
	esac
	shift
done
# default (no flag): website only, matching the previous "files only" default
if [ ${INCLUDE_WEB} -eq 0 ] && [ ${INCLUDE_MAIL} -eq 0 ] && [ ${INCLUDE_DB} -eq 0 ]; then
	INCLUDE_WEB=1
fi
# homedir is streamed when either the website or the mail bundle is selected
INCLUDE_HOME=0
if [ ${INCLUDE_WEB} -eq 1 ] || [ ${INCLUDE_MAIL} -eq 1 ]; then
	INCLUDE_HOME=1
fi

DATE=$(date +%Y-%m-%d_%H%M)
REQAD='/usr/local/reqad'
PHP='/usr/bin/php82'
# -list/-noheader force plain output regardless of the panel's ~/.sqliterc (box mode)
# stock EL sqlite (what install-el*.sh installs); -init /dev/null ignores
# ~/.sqliterc, whose ".mode box" EL8's 3.26 rejects and which would corrupt
# the plain output parsed here
SQLITE='/usr/bin/sqlite3 -init /dev/null -batch -noheader -list'
DB_FILE="${REQAD}/db/reqad.db"
MYSQL='sudo mysql --defaults-extra-file=/root/.my.cnf'
MYSQLDUMP='sudo /usr/bin/mysqldump --defaults-extra-file=/root/.my.cnf'
if [ -f '/usr/bin/mariadb-dump' ]; then
	MYSQLDUMP='sudo /usr/bin/mariadb-dump --defaults-extra-file=/root/.my.cnf'
fi

# one domain per account (unique constraint in the accounts table)
DOMAIN=$(${SQLITE} "${DB_FILE}" "SELECT domain FROM accounts WHERE user='${USER}'" 2>/dev/null)
DNS_PROVIDER=$(${SQLITE} "${DB_FILE}" "SELECT value FROM settings WHERE name='dns-provider'" 2>/dev/null)

echo "User: ${USER}"
echo "Domain: ${DOMAIN}"

# ---- staging tree: a single root 'backup-<user>/' --------------------------
# Built on the same filesystem as the final archive (not /tmp) so big DB dumps
# don't hit a small tmpfs. Some files land root-owned (sudo cp), so the final
# cleanup uses sudo.
if [ -n "${STAGE_ONLY}" ]; then
	# caller owns the directory and the cleanup (backup_remote.sh streams from it)
	mkdir -p "${STAGE_ONLY}"
	BUILD="${STAGE_ONLY}"
else
	mkdir -p ~/backup/
	BUILD=$(mktemp -d ~/backup/.build_${USER}_${DATE}.XXXXXX)
fi
ROOT="${BUILD}/backup-${USER}"
mkdir -p "${ROOT}"

# helper: dump the DNS zone (or its email subset) if a provider is configured
dump_dns() {   # $1 = out file, $2 = extra flag (--email-only or empty)
	[ -z "${DOMAIN}" ] && return
	[ -z "${DNS_PROVIDER}" ] && return
	[ "${DNS_PROVIDER}" == "none" ] && return
	mkdir -p "${ROOT}/dns"
	${PHP} ${REQAD}/scripts/dump_dns_zone.php --domain="${DOMAIN}" --out="$1" $2 >> ${REQAD}/log/backup.log 2>&1
}

# ===========================================================================
# Databases  (-d)
# ===========================================================================
if [ ${INCLUDE_DB} -eq 1 ]; then
	echo -e "\n${WHITE}──────────────────────────────────────────────────────────────${NC}"
	echo -e "${WHITE}Backup databases${NC}"
	echo -e "${WHITE}──────────────────────────────────────────────────────────────${NC}"

	echo -n "Databases: "
	DBS=$(${MYSQL} -Ns -e "show databases like \"${USER}\_%\"")
	if [ "${DBS}" == "" ]; then
		echo '-'
	else
		echo ${DBS} | xargs echo | sed 's/ /, /'
		mkdir -p "${ROOT}/databases"

		# per-database dump (data + schema, no CREATE DATABASE)
		for DB in ${DBS}; do
			# CREATE DATABASE (with the real charset/collation) — written for every
			# database, including one skipped by --db-max-mb below, so a restore can
			# always rebuild the empty database.
			read CS COL < <(${MYSQL} -Ns -e "SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='${DB}'")
			echo "CREATE DATABASE IF NOT EXISTS \`${DB}\` CHARACTER SET ${CS:-utf8mb4} COLLATE ${COL:-utf8mb4_general_ci};" >> "${ROOT}/databases/_create_databases.sql"

			# --db-max-mb: a big database is left out of the staged tree so the
			# caller can stream its dump straight to the backup server instead of
			# spooling a multi-GB .sql on this disk first. Its CREATE DATABASE and
			# its grants are still written below, so a restore rebuilds the empty
			# database and only the data has to be piped back in.
			if [ "${DB_MAX_MB}" -gt 0 ]; then
				DB_MB=$(${MYSQL} -Ns -e "SELECT COALESCE(ROUND(SUM(data_length+index_length)/1048576),0) FROM information_schema.TABLES WHERE TABLE_SCHEMA='${DB}'" 2>/dev/null)
				if [ -n "${DB_MB}" ] && [ "${DB_MB}" -ge "${DB_MAX_MB}" ]; then
					echo "Skip database ${DB} (${DB_MB} MB >= ${DB_MAX_MB} MB) - streamed separately"
					echo "${DB}" >> "${ROOT}/databases/_streamed_separately.txt"
					continue
				fi
			fi
			echo "Dump database ${DB}"
			${MYSQLDUMP} --opt --lock-tables=false --single-transaction "${DB}" > "${ROOT}/databases/${DB}.sql"
			if [ ! -s "${ROOT}/databases/${DB}.sql" ]; then
				echo "Warning: ${DB}.sql is empty."
			fi
		done

		# Grants scoped to THIS account's databases only (the old wp_/wc_ grep
		# leaked grants from other accounts). Find every grantee that holds a
		# privilege on a <user>_% database and export its CREATE USER + GRANTs.
		: > "${ROOT}/databases/_grants.sql"
		${MYSQL} -Ns -e "SELECT DISTINCT User, Host FROM mysql.db WHERE Db LIKE '${USER}\_%'" | \
		while IFS=$'\t' read -r GU GH; do
			[ "${GU}" == "" ] && continue
			echo "-- ${GU}@${GH}" >> "${ROOT}/databases/_grants.sql"
			${MYSQL} -Ns -e "SHOW CREATE USER '${GU}'@'${GH}'" 2>/dev/null | \
				sed 's/^CREATE USER /CREATE USER IF NOT EXISTS /; s/$/;/' >> "${ROOT}/databases/_grants.sql"
			${MYSQL} -Ns -e "SHOW GRANTS FOR '${GU}'@'${GH}'" 2>/dev/null | \
				sed 's/$/;/' >> "${ROOT}/databases/_grants.sql"
		done
		echo "FLUSH PRIVILEGES;" >> "${ROOT}/databases/_grants.sql"
	fi
fi

# ===========================================================================
# Website  (-w): homedir minus mail/, web/php config, ssl, cron, meta, DNS zone
# ===========================================================================
if [ ${INCLUDE_WEB} -eq 1 ]; then

	# ---- web / php config -------------------------------------------------
	mkdir -p "${ROOT}/config"
	[ -f "/etc/nginx/conf.d/${DOMAIN}.conf" ] && sudo cp -p "/etc/nginx/conf.d/${DOMAIN}.conf" "${ROOT}/config/nginx-${DOMAIN}.conf"
	[ -f "/etc/httpd/conf.d/${DOMAIN}.conf" ] && sudo cp -p "/etc/httpd/conf.d/${DOMAIN}.conf" "${ROOT}/config/httpd-${DOMAIN}.conf"
	[ -f "/etc/php-fpm.d/${DOMAIN}.conf" ]    && sudo cp -p "/etc/php-fpm.d/${DOMAIN}.conf"    "${ROOT}/config/php-fpm-${DOMAIN}.conf"

	# ---- SSL certificates -------------------------------------------------
	mkdir -p "${ROOT}/ssl"
	if [ -d "/etc/letsencrypt/live/${DOMAIN}" ]; then
		# -L dereferences the live/ symlinks so the real cert + key are captured
		sudo cp -rL "/etc/letsencrypt/live/${DOMAIN}" "${ROOT}/ssl/letsencrypt-${DOMAIN}"
	fi
	[ -f "/etc/ssl/certs/${DOMAIN}.crt" ] && sudo cp -p "/etc/ssl/certs/${DOMAIN}.crt" "${ROOT}/ssl/${DOMAIN}.crt"
	[ -f "/etc/ssl/certs/${DOMAIN}.key" ] && sudo cp -p "/etc/ssl/certs/${DOMAIN}.key" "${ROOT}/ssl/${DOMAIN}.key"

	# ---- cron -------------------------------------------------------------
	# /var/spool/cron is 0700 root, so the test must run via sudo (reqad can't stat it)
	if sudo test -f "/var/spool/cron/${USER}"; then
		mkdir -p "${ROOT}/cron"
		sudo cp -p "/var/spool/cron/${USER}" "${ROOT}/cron/crontab"
	fi

	# ---- meta: identity + panel row needed to recreate the account on restore --
	# (the homedir + dovecot lines hardcode the original uid/gid, so restore must
	#  recreate the user with the same ids and the same password hash)
	mkdir -p "${ROOT}/meta"
	getent passwd "${USER}" > "${ROOT}/meta/passwd"
	getent group  "${USER}" > "${ROOT}/meta/group"
	sudo grep "^${USER}:" /etc/shadow > "${ROOT}/meta/shadow" 2>/dev/null
	${SQLITE} "${DB_FILE}" "SELECT id, user, domain, disk_quota, has_email, status, created_at, dkim_selector FROM accounts WHERE user='${USER}'" > "${ROOT}/meta/account.tsv" 2>/dev/null

	# ---- full DNS zone dump ----------------------------------------------
	dump_dns "${ROOT}/dns/zone.json"
fi

# ===========================================================================
# Email  (-m): mail folder (in homedir/), exim/dovecot settings, email DNS
# ===========================================================================
if [ ${INCLUDE_MAIL} -eq 1 ]; then
	mkdir -p "${ROOT}/email"
	[ -f "/etc/exim/domains/${DOMAIN}" ]  && sudo cp -p "/etc/exim/domains/${DOMAIN}"  "${ROOT}/email/exim-domain"
	[ -f "/etc/exim/forwards/${DOMAIN}" ] && sudo cp -p "/etc/exim/forwards/${DOMAIN}" "${ROOT}/email/exim-forwards"
	if [ -f "/etc/exim/keys/${DOMAIN}.private.key" ] || [ -f "/etc/exim/keys/${DOMAIN}.public.key" ]; then
		mkdir -p "${ROOT}/email/dkim"
		sudo cp -p /etc/exim/keys/${DOMAIN}.private.key "${ROOT}/email/dkim/" 2>/dev/null
		sudo cp -p /etc/exim/keys/${DOMAIN}.public.key  "${ROOT}/email/dkim/" 2>/dev/null
	fi
	sudo grep "^${DOMAIN}:" /etc/exim/userdomains > "${ROOT}/email/userdomains-entry" 2>/dev/null
	# NOTE: the panel's `emails` table is NOT backed up. Since db/1032.sql it is
	# only a du -skm cache keyed by address (the mailbox inventory is
	# /etc/dovecot/users, captured below as email/dovecot-users), and restoring a
	# cache is meaningless — it re-measures itself on first use.
	# dovecot mailbox auth lines (passwd-style, hashed) for this domain — needed to
	# restore working mailboxes (maildirs themselves ride along in homedir/mail/)
	sudo grep "@${DOMAIN}:" /etc/dovecot/users > "${ROOT}/email/dovecot-users" 2>/dev/null

	# ---- email filters: domain tier ---------------------------------------
	# Per-account Sieve needs no code here. Home IS the maildir root, so a
	# mailbox's ~/sieve/ and ~/.dovecot.sieve ride along inside homedir/mail/.
	# The global tier is server-wide, not part of any one account, so it is
	# deliberately left out of an account backup.
	# Only the .sieve source is taken. The .svbin beside it records the path it
	# was compiled from, so a copied binary would be permanently stale — restore
	# recompiles instead.
	if sudo test -f "/var/lib/reqad/sieve/domains/${DOMAIN}.sieve"; then
		sudo cp -p "/var/lib/reqad/sieve/domains/${DOMAIN}.sieve" "${ROOT}/email/sieve-domain.sieve"
	fi
	# The rows that script was rendered from. Dumped as ready-to-run INSERTs via
	# quote() so the JSON in conditions/actions survives verbatim, quotes and all.
	${SQLITE} "${DB_FILE}" "SELECT 'INSERT OR IGNORE INTO email_filters (scope,target,name,enabled,priority,match_type,conditions,actions,stop) VALUES ('||quote(scope)||','||quote(target)||','||quote(name)||','||enabled||','||priority||','||quote(match_type)||','||quote(conditions)||','||quote(actions)||','||stop||');' FROM email_filters WHERE scope='domain' AND target='${DOMAIN}'" > "${ROOT}/email/email_filters.sql" 2>/dev/null
	[ -s "${ROOT}/email/email_filters.sql" ] || rm -f "${ROOT}/email/email_filters.sql"

	# ---- autoresponders ---------------------------------------------------
	# The `autoresponders` table is the truth; the live artefact (a Pigeonhole
	# vacation script under /var/lib/reqad/sieve/autoresponders/, or the legacy
	# /etc/exim/autoreply file) is rendered FROM it. Backing up the rows rather
	# than the artefact lets restore pick whichever backend the target machine
	# actually runs — see autoresponder_backend() in modules/functions.php.
	${SQLITE} "${DB_FILE}" "SELECT 'INSERT OR IGNORE INTO autoresponders (user,domain,subject,message,date_from,date_to,created_at) VALUES ('||quote(user)||','||quote(domain)||','||quote(subject)||','||quote(message)||','||quote(date_from)||','||quote(date_to)||','||quote(created_at)||');' FROM autoresponders WHERE domain='${DOMAIN}'" > "${ROOT}/email/autoresponders.sql" 2>/dev/null
	[ -s "${ROOT}/email/autoresponders.sql" ] || rm -f "${ROOT}/email/autoresponders.sql"

	# ---- email DNS records (MX / SPF / DKIM / DMARC) ----------------------
	dump_dns "${ROOT}/dns/dns-email.json" --email-only
fi

# ===========================================================================
# summary.txt — web stack + versions (best effort)
# ===========================================================================
{
	echo "Reqad backup summary"
	echo "===================="
	echo "User:    ${USER}"
	echo "Domain:  ${DOMAIN}"
	echo "Date:    $(date '+%Y-%m-%d %H:%M:%S %z')"
	echo "Host:    $(hostname)"
	INC=""
	[ ${INCLUDE_WEB} -eq 1 ]  && INC="website"
	[ ${INCLUDE_MAIL} -eq 1 ] && INC="${INC:+${INC}, }email"
	[ ${INCLUDE_DB} -eq 1 ]   && INC="${INC:+${INC}, }databases"
	echo "Bundles: ${INC}"
	echo "DNS provider: ${DNS_PROVIDER:-none}"
	echo
	if [ -f "/etc/nginx/conf.d/${DOMAIN}.conf" ]; then
		echo "Web stack: nginx + php-fpm"
	elif [ -f "/etc/httpd/conf.d/${DOMAIN}.conf" ]; then
		echo "Web stack: apache (mod_php)"
	else
		echo "Web stack: unknown (no vhost found for ${DOMAIN})"
	fi
	[ -f "/etc/php-fpm.d/${DOMAIN}.conf" ] && echo "PHP pool:  /etc/php-fpm.d/${DOMAIN}.conf"
	echo
	echo "Versions:"
	echo "  nginx:   $(nginx -v 2>&1)"
	echo "  apache:  $(httpd -v 2>&1 | head -1)"
	echo "  db:      $(mysql --version 2>&1)"
	echo "  php:     $(php -v 2>&1 | head -1)"
	echo "  php (available): $(ls -d /opt/remi/php*/ 2>/dev/null | sed 's#/opt/remi/##;s#/##' | xargs echo) (system: $(rpm -q --qf '%{VERSION}' php-fpm 2>/dev/null))"
	echo
	if [ ${INCLUDE_HOME} -eq 1 ]; then
		echo -n "Homedir usage: "
		sudo du -skh "/home/${USER}" 2>/dev/null | awk '{print $1}'
	fi
	if [ ${INCLUDE_DB} -eq 1 ] && [ "${DBS}" != "" ]; then
		echo "Databases: $(echo ${DBS} | xargs echo | sed 's/ /, /g')"
	fi
	if [ ${INCLUDE_WEB} -eq 1 ] || [ ${INCLUDE_MAIL} -eq 1 ]; then
		echo
		echo "SECURITY: this backup contains system and/or email account password"
		echo "hashes (meta/shadow, email/dovecot-users, databases/_grants.sql), like a"
		echo "cPanel cpmove. Treat the downloaded tarball as sensitive."
	fi
} > "${ROOT}/summary.txt" 2>/dev/null

# --stage-only: the staged tree IS the deliverable. No tarball, no cleanup -
# backup_remote.sh tars ${ROOT} and /home/<user> together into an ssh pipe and
# removes the directory itself afterwards.
if [ -n "${STAGE_ONLY}" ]; then
	echo "Staged ${ROOT}"
	exit 0
fi

# ===========================================================================
# Create the archive — a SINGLE root folder 'backup-<user>/'
# ===========================================================================
ARCHIVE=~/backup/backup_${USER}_${DATE}.tar.gz
if [ ${INCLUDE_HOME} -eq 1 ]; then
	echo -e "\n${WHITE}──────────────────────────────────────────────────────────────${NC}"
	echo -e "${WHITE}Backup homedir files${NC}"
	echo -e "${WHITE}──────────────────────────────────────────────────────────────${NC}"
	echo -n "Disk usage: "
	sudo du -skh "/home/${USER}"
	echo -n "Creating archive $(basename ${ARCHIVE}) "

	# /home/<user> is streamed straight into backup-<user>/homedir/ via --transform
	# (no temp copy). The transform is anchored on ^<user> so it only rewrites the
	# /home members, not the already-prefixed backup-<user>/... members; 'S'/'H'
	# flags keep symlink/hardlink targets untouched.
	#   website+email  → full homedir
	#   website only   → homedir excluding mail/
	#   email only     → only mail/  (into homedir/mail/)
	EXCLUDES=()
	TRANSFORMS=( --transform "s,^${USER}/,backup-${USER}/homedir/,SH" --transform "s,^${USER}\$,backup-${USER}/homedir,SH" )
	HOMESRC=( -C /home "${USER}" )
	if [ ${INCLUDE_WEB} -eq 1 ] && [ ${INCLUDE_MAIL} -eq 0 ]; then
		EXCLUDES=( --exclude "${USER}/mail" )
	elif [ ${INCLUDE_WEB} -eq 0 ] && [ ${INCLUDE_MAIL} -eq 1 ]; then
		TRANSFORMS=( --transform "s,^${USER}/mail/,backup-${USER}/homedir/mail/,SH" --transform "s,^${USER}/mail\$,backup-${USER}/homedir/mail,SH" )
		HOMESRC=( -C /home "${USER}/mail" )
	fi

	sudo tar czf "${ARCHIVE}" "${EXCLUDES[@]}" "${TRANSFORMS[@]}" \
		-C "${BUILD}" "backup-${USER}" \
		"${HOMESRC[@]}"
	sudo chown reqad:reqad "${ARCHIVE}"
	echo "Done!"
else
	# no homedir (e.g. databases-only) — archive just the staged tree
	echo -n "Creating archive $(basename ${ARCHIVE}) "
	sudo tar czf "${ARCHIVE}" -C "${BUILD}" "backup-${USER}"
	sudo chown reqad:reqad "${ARCHIVE}"
	echo "Done!"
fi

# cleanup (staging tree contains root-owned files from sudo cp)
sudo rm -rf "${BUILD}"
