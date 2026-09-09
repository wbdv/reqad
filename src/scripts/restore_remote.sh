#!/bin/bash
# Restore from a backup produced by backup_remote.sh, straight off the backup
# server, without downloading the whole archive first.
#
# Three modes, because "restore" means three different things:
#
#   --mode account      the account is GONE; rebuild it completely.
#   --mode public_html  the account is LIVE; put its public_html back.
#   --mode database     the account is LIVE; put one database back.
#
# Why not just restore.sh? Because restore.sh only ever recreates a DELETED
# account — it refuses when the user, its uid or the panel row still exists — and
# because it needs a local file: it reads the archive three times (once for the
# root folder name, once for everything but the homedir, once for the homedir),
# which no pipe can serve. Mode 'account' therefore fetches and hands over to it
# unchanged; the other two modes never touch local disk at all, which is the only
# way to restore one file out of a 48 GB archive on a box with less free space
# than that.
#
# Usage:
#   restore_remote.sh --mode MODE --user U --date YYYY-MM-DD [options]
#
#     --db NAME        database to restore            (--mode database)
#     --replace        public_html: move the live tree aside first, so the result
#                      is exactly the backup. Default is a merge, which leaves
#                      files created since the backup in place.
#     --drop           database: DROP and recreate before importing, so the result
#                      is exactly the backup. Default imports over the top.
#     --no-predump     database: skip the safety dump of the current contents
#     --keep-archive   account: keep the fetched archive in ~reqad/backup
#     --verify         account: gzip -t the fetched archive (a second full pass)
#     --work-dir DIR   account: where to fetch (default /usr/local/reqad/backup)
#     --token HEX16    messages.db token, for the panel's async toast
#     --dest PATH      remote prefix holding the dated dirs
#     --host/--port/--ssh-user/--key   override the stored credentials
#     --dry-run        print what would run, change nothing
#
# Exit: 0 ok, 1 failed, 2 bad usage, 3 refused by a guard.

set -u
export LC_ALL=C

REQAD=/usr/local/reqad
DEFINES="${REQAD}/public_html/defines.php"
SQLITE="/usr/bin/sqlite3 -init /dev/null -batch -noheader -list"
DB_FILE="${REQAD}/db/reqad.db"
MSG_DB="${REQAD}/db/messages.db"
MYSQL='sudo mysql --defaults-extra-file=/root/.my.cnf'
MYSQLDUMP='sudo /usr/bin/mysqldump --defaults-extra-file=/root/.my.cnf'
[ -x /usr/bin/mariadb-dump ] && MYSQLDUMP='sudo /usr/bin/mariadb-dump --defaults-extra-file=/root/.my.cnf'
# unpigz is measurably faster than gzip -d on a multi-GB stream
if [ -x /usr/bin/unpigz ]; then UNGZ='/usr/bin/unpigz -c'; else UNGZ='/usr/bin/gzip -dc'; fi

MODE=''; USER_ARG=''; DATE=''; DB=''
REPLACE=0; DROP=0; PREDUMP=1; KEEP_ARCHIVE=0; VERIFY=0
WORK_DIR="${REQAD}/backup"
TOKEN=''; DRY=0
SSH_HOST=''; SSH_USER=''; SSH_PORT=''; SSH_KEY=''; DEST=''
CFG_HOST=''; CFG_USER=''; CFG_PORT=''; CFG_KEY=''; CFG_DEST=''

log()  { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"; }

# ─── async result for the panel (copied from restore.sh) ────────────────────
post_msg() {   # $1 = type (success|error|info)  $2 = message
	[ -z "${TOKEN}" ] && return
	echo "${TOKEN}" | grep -qE '^[0-9a-f]{16}$' || return
	local m="${2//\'/\'\'}"
	${SQLITE} "${MSG_DB}" "CREATE TABLE IF NOT EXISTS messages (token TEXT PRIMARY KEY, type TEXT NOT NULL DEFAULT 'info', message TEXT NOT NULL, seen INTEGER NOT NULL DEFAULT 0, created INTEGER NOT NULL);" 2>/dev/null
	${SQLITE} "${MSG_DB}" "INSERT OR REPLACE INTO messages (token,type,message,seen,created) VALUES ('${TOKEN}','$1','${m}',0,strftime('%s','now'));" 2>/dev/null
}
die()       { log "ERROR: $1"; post_msg error "$1"; exit 1; }
guard_die() { log "REFUSED: $1"; post_msg error "$1"; exit 3; }
usage_die() { echo "$(basename "$0"): $1" >&2; exit 2; }

# ─── validation ─────────────────────────────────────────────────────────────
# The caller is a web page. Nothing below may reach a local OR a remote shell
# without passing one of these first: remote commands are assembled by string
# interpolation inside single quotes, so these whitelists — which contain no
# quote, backslash, $ or backtick — are what makes that safe.
valid_user() { [[ "$1" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]]; }
valid_date() { [[ "$1" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]] && date -d "$1" +%F >/dev/null 2>&1; }
valid_db()   { [[ "$1" =~ ^[A-Za-z0-9_]{1,64}$ ]]; }

while [ $# -gt 0 ]; do
	case "$1" in
		--mode)         MODE="${2:-}"; shift 2 ;;
		--user)         USER_ARG="${2:-}"; shift 2 ;;
		--date)         DATE="${2:-}"; shift 2 ;;
		--db)           DB="${2:-}"; shift 2 ;;
		--replace)      REPLACE=1; shift ;;
		--drop)         DROP=1; shift ;;
		--no-predump)   PREDUMP=0; shift ;;
		--keep-archive) KEEP_ARCHIVE=1; shift ;;
		--verify)       VERIFY=1; shift ;;
		--work-dir)     WORK_DIR="${2:-}"; shift 2 ;;
		--token)        TOKEN="${2:-}"; shift 2 ;;
		--dest)         DEST="${2:-}"; shift 2 ;;
		--host)         SSH_HOST="${2:-}"; shift 2 ;;
		--port)         SSH_PORT="${2:-}"; shift 2 ;;
		--ssh-user)     SSH_USER="${2:-}"; shift 2 ;;
		--key)          SSH_KEY="${2:-}"; shift 2 ;;
		--dry-run)      DRY=1; shift ;;
		-h|--help)      sed -n '2,45p' "$0"; exit 0 ;;
		*) usage_die "unknown option: $1" ;;
	esac
done

case "${MODE}" in
	account|public_html|database) ;;
	*) usage_die "--mode must be account, public_html or database" ;;
esac
valid_user "${USER_ARG}" || usage_die "invalid --user"
valid_date "${DATE}"     || usage_die "invalid --date (expected YYYY-MM-DD)"
if [ "${MODE}" = database ]; then
	valid_db "${DB}" || usage_die "invalid --db"
	# a database may only be restored into the account that owns it — the same
	# rule backup.sh uses when it decides what to dump ("<user>_%")
	[ "${DB}" = "${USER_ARG}" ] || [[ "${DB}" == "${USER_ARG}_"* ]] \
		|| usage_die "database '${DB}' does not belong to account '${USER_ARG}'"
fi
[ -n "${TOKEN}" ] && { [[ "${TOKEN}" =~ ^[0-9a-f]{16}$ ]] || usage_die "invalid --token"; }
U="${USER_ARG}"

# ─── credentials: settings table, then defines.php, then flags ──────────────
if [ -r "${DB_FILE}" ]; then
	setting() { ${SQLITE} "${DB_FILE}" "SELECT value FROM settings WHERE name='$1'" 2>/dev/null; }
	CFG_HOST=$(setting backup-remote-host); CFG_USER=$(setting backup-remote-user)
	CFG_PORT=$(setting backup-remote-port); CFG_KEY=$(setting backup-remote-key)
	CFG_DEST=$(setting backup-remote-dest)
fi
if [ -r "${DEFINES}" ]; then
	php_var() { sed -n "s/^[[:space:]]*\\\$$1[[:space:]]*=[[:space:]]*['\"]\([^'\"]*\)['\"].*/\1/p" "${DEFINES}" | tail -1; }
	[ -n "${CFG_USER}" ] || CFG_USER=$(php_var backup_user)
	[ -n "${CFG_HOST}" ] || CFG_HOST=$(php_var backup_server)
	[ -n "${CFG_PORT}" ] || CFG_PORT=$(php_var backup_sshport)
	[ -n "${CFG_KEY}" ]  || CFG_KEY=$(php_var backup_sshkey)
fi
[ -n "${SSH_HOST}" ] || SSH_HOST="${CFG_HOST}"
[ -n "${SSH_USER}" ] || SSH_USER="${CFG_USER}"
[ -n "${SSH_PORT}" ] || SSH_PORT="${CFG_PORT}"
[ -n "${SSH_KEY}" ]  || SSH_KEY="${CFG_KEY}"
[ -n "${DEST}" ]     || DEST="${CFG_DEST}"
[ -n "${SSH_PORT}" ] || SSH_PORT=22
[ -n "${SSH_HOST}" ] || die "no backup server configured"
[ -n "${SSH_USER}" ] || die "no backup user configured"
[[ "${DEST}" =~ ^[A-Za-z0-9._/-]*$ ]] || die "invalid remote destination '${DEST}'"

SSH_OPTS=( -p "${SSH_PORT}" -o BatchMode=yes -o StrictHostKeyChecking=accept-new
           -o ServerAliveInterval=30 -o ServerAliveCountMax=6 -o Compression=no )
[ -n "${SSH_KEY}" ] && SSH_OPTS+=( -i "${SSH_KEY}" )
# The panel runs this as 'reqad', and the ssh key belongs to root — hence sudo.
# When already root (cron, or an admin at a shell) sudo would add nothing, and
# skipping it keeps the caller's PATH, which is what makes this testable.
if [ "$(id -u)" -eq 0 ]; then
	rsh() { ssh "${SSH_OPTS[@]}" "${SSH_USER}@${SSH_HOST}" "$@"; }
else
	rsh() { sudo -n ssh "${SSH_OPTS[@]}" "${SSH_USER}@${SSH_HOST}" "$@"; }
fi

RD="${DATE}"; [ -n "${DEST}" ] && RD="${DEST%/}/${DATE}"
ARCH="${RD}/accounts/${U}.tar.gz"
ROOTDIR="backup-${U}"
GZ="${RD}/databases/${U}/${DB}.sql.gz"

# tar closes the pipe as soon as it has the member it wanted, and ssh then dies
# of SIGPIPE. That is success, not failure, so the consumer (tar/mysql) is what
# decides — the opposite of backup_remote.sh, where the producer is authoritative.
EARLY_EXIT_OK=0
consumer_ok() {   # $1 = label, then PIPESTATUS
	local label="$1"; shift
	local -a st=( "$@" )
	local last="${st[$(( ${#st[@]} - 1 ))]}"
	if [ "${last}" -ne 0 ]; then log "FAILED: ${label} (consumer exit ${last})"; return 1; fi
	if [ "${st[0]}" -ne 0 ] && [ "${EARLY_EXIT_OK}" -eq 0 ]; then
		log "FAILED: ${label} (ssh exit ${st[0]} — the transfer was truncated)"; return 1
	fi
	return 0
}

# ─── guards ─────────────────────────────────────────────────────────────────
panel_row() { ${SQLITE} "${DB_FILE}" "SELECT count(*) FROM accounts WHERE user='${U}'" 2>/dev/null; }
case "${MODE}" in
	account)
		# restore.sh refuses a live account too, but only after the whole archive
		# has been downloaded. Refuse here, before spending an hour on it.
		id -u "${U}" >/dev/null 2>&1 && guard_die "system user '${U}' still exists — 'account' mode only rebuilds a deleted account"
		[ "$(panel_row)" != "0" ] && guard_die "reqad.db still has an accounts row for '${U}' — delete the account properly first"
		[ -e "/home/${U}" ] && guard_die "/home/${U} still exists — refusing to restore on top of it"
		;;
	public_html|database)
		id -u "${U}" >/dev/null 2>&1 || guard_die "system user '${U}' does not exist — restore the whole account first"
		;;
esac

# one restore per account at a time
LOCK="/var/run/reqad-restore-remote.${U}.lock"
exec 9>"${LOCK}" 2>/dev/null || LOCK=""
[ -n "${LOCK}" ] && { flock -n 9 || die "another restore for '${U}' is already running"; }

log "mode ${MODE} | account ${U} | backup ${DATE} | ${SSH_USER}@${SSH_HOST}:${SSH_PORT}"
[ "${DRY}" -eq 1 ] && log "DRY RUN — nothing will be changed"

rsh true >/dev/null 2>&1 || die "cannot reach the backup server ${SSH_USER}@${SSH_HOST}:${SSH_PORT}"
rsh "test -d '${RD}'" || die "there is no ${DATE} backup on the backup server"
if [ "${MODE}" != database ] || ! rsh "test -f '${GZ}'"; then
	rsh "test -f '${ARCH}'" || die "the ${DATE} backup has no archive for account '${U}'"
fi

# ===========================================================================
# Mode 1 — entire account: fetch, then hand to restore.sh unchanged
# ===========================================================================
if [ "${MODE}" = account ]; then
	ARCH_BYTES=$(rsh "stat -c %s '${ARCH}'" 2>/dev/null)
	[[ "${ARCH_BYTES}" =~ ^[0-9]+$ ]] || die "cannot stat the archive on the backup server"
	ARCH_KB=$(( ARCH_BYTES / 1024 ))

	# expanded size from the index backup_remote.sh writes; 3x the archive if the
	# backup predates it
	HOME_KB=$(rsh "sed -n 's/^home_kb //p' '${RD}/accounts/${U}.index' 2>/dev/null" | head -1)
	[[ "${HOME_KB}" =~ ^[0-9]+$ ]] || HOME_KB=$(( ARCH_KB * 3 ))

	mkdir -p "${WORK_DIR}" 2>/dev/null || sudo mkdir -p "${WORK_DIR}"
	FREE_KB=$(df -Pk "${WORK_DIR}" | awk 'NR==2{print $4}')
	WORK_DEV=$(df -Pk "${WORK_DIR}" | awk 'NR==2{print $1}')
	HOME_DEV=$(df -Pk /home | awk 'NR==2{print $1}')
	SLACK_KB=1048576
	if [ "${WORK_DEV}" = "${HOME_DEV}" ]; then
		# same filesystem: the archive AND the homedir it expands into must fit
		NEED_KB=$(( ARCH_KB + HOME_KB + SLACK_KB ))
	else
		NEED_KB=$(( ARCH_KB + SLACK_KB ))
		HFREE_KB=$(df -Pk /home | awk 'NR==2{print $4}')
		[ "${HFREE_KB}" -lt $(( HOME_KB + SLACK_KB )) ] && guard_die \
			"/home has ${HFREE_KB} KB free; the restored homedir needs about $(( HOME_KB + SLACK_KB )) KB"
	fi
	[ "${FREE_KB}" -lt "${NEED_KB}" ] && guard_die \
		"not enough free space in ${WORK_DIR}: ${FREE_KB} KB free, ${NEED_KB} KB needed (archive ${ARCH_KB} KB + expanded homedir ${HOME_KB} KB + 1 GB). Free space or use --work-dir on another filesystem."

	# named so it matches the Backup page's own regex — with --keep-archive it
	# simply appears in the Local backup list, downloadable and restorable there
	LOCAL="${WORK_DIR}/backup_${U}_${DATE}.tar.gz"
	if [ "${DRY}" -eq 1 ]; then
		log "would fetch ${ARCH} (${ARCH_KB} KB) → ${LOCAL}, then run restore.sh"
		exit 0
	fi

	log "fetching ${ARCH_KB} KB → ${LOCAL}"
	rsh "cat '${ARCH}'" > "${LOCAL}"
	consumer_ok "fetch ${U}.tar.gz" "${PIPESTATUS[@]}" || { rm -f "${LOCAL}"; die "the download failed"; }
	LOCAL_BYTES=$(stat -c %s "${LOCAL}" 2>/dev/null)
	# a size compare catches the realistic failure (a truncated stream) for free;
	# gzip -t would cost a second full decompression pass, hence --verify
	[ "${LOCAL_BYTES}" = "${ARCH_BYTES}" ] || { rm -f "${LOCAL}"; die "the download was truncated (${LOCAL_BYTES} of ${ARCH_BYTES} bytes)"; }
	if [ "${VERIFY}" -eq 1 ]; then
		${UNGZ} < "${LOCAL}" > /dev/null 2>&1 || { rm -f "${LOCAL}"; die "the fetched archive fails its gzip integrity check"; }
	fi
	chmod 600 "${LOCAL}" 2>/dev/null

	# restore.sh is handed NO token: it would post its toast when it finishes,
	# while the big databases below are still to come. One message, at the end.
	log "running restore.sh"
	"${REQAD}/scripts/restore.sh" "${LOCAL}"
	RC=$?
	[ "${KEEP_ARCHIVE}" -eq 1 ] || rm -f "${LOCAL}"
	[ ${RC} -ne 0 ] && die "restore.sh failed for '${U}' — see ${REQAD}/log/restore_remote.log"

	# databases that were too big to ride inside the archive. restore.sh has just
	# created them empty from _create_databases.sql and restored their grants, so
	# only the data is missing — stream it back in.
	FAILED_DB=()
	for BIGDB in $(rsh "ls -1 '${RD}/databases/${U}/' 2>/dev/null" | sed -n 's/\.sql\.gz$//p'); do
		valid_db "${BIGDB}" || continue
		log "streaming database ${BIGDB} back in"
		rsh "cat '${RD}/databases/${U}/${BIGDB}.sql.gz'" | ${UNGZ} | ${MYSQL} "${BIGDB}"
		consumer_ok "import ${BIGDB}" "${PIPESTATUS[@]}" || FAILED_DB+=("${BIGDB}")
	done
	if [ ${#FAILED_DB[@]} -gt 0 ]; then
		die "account '${U}' was restored, but these databases did not import: ${FAILED_DB[*]}"
	fi
	log "DONE"
	post_msg success "Account '${U}' was restored from the ${DATE} remote backup."
	exit 0
fi

# ===========================================================================
# Mode 2 — public_html only, streamed, nothing stored locally
# ===========================================================================
if [ "${MODE}" = public_html ]; then
	PHOME=$(getent passwd "${U}" | cut -d: -f6)
	[ -n "${PHOME}" ] || die "cannot determine the home directory of '${U}'"
	[ -L "${PHOME}/public_html" ] && guard_die "${PHOME}/public_html is a symlink — refusing to extract through it"
	PUID=$(id -u "${U}"); PGID=$(id -g "${U}")
	STAMP=$(date +%Y%m%d-%H%M%S)

	if [ "${DRY}" -eq 1 ]; then
		log "would stream ${ARCH} and extract ${ROOTDIR}/homedir/public_html → ${PHOME}/public_html"
		[ "${REPLACE}" -eq 1 ] && log "would first move the live tree to ${PHOME}/public_html.prerestore-${STAMP}"
		exit 0
	fi

	if [ "${REPLACE}" -eq 1 ] && [ -d "${PHOME}/public_html" ]; then
		# a rename is free and instantly reversible; deleting 40 GB is a decision
		# for a human, so the old tree is kept until someone removes it
		sudo mv "${PHOME}/public_html" "${PHOME}/public_html.prerestore-${STAMP}" \
			|| die "could not move the live public_html aside"
		log "live tree moved to ${PHOME}/public_html.prerestore-${STAMP}"
	fi
	sudo mkdir -p "${PHOME}/public_html"

	TARERR=$(mktemp /var/tmp/reqad-restore.XXXXXX)
	# -C /home + this transform is byte-identical to restore.sh's homedir step, so
	# there is exactly one such expression in the codebase to get wrong.
	rsh "cat '${ARCH}'" | ${UNGZ} \
		| sudo tar -x -f - -C /home \
			--transform "s,^${ROOTDIR}/homedir,${U},S" \
			--no-same-owner --numeric-owner \
			"${ROOTDIR}/homedir/public_html" 2>"${TARERR}"
	STATUS=( "${PIPESTATUS[@]}" )
	if grep -q 'Not found in archive' "${TARERR}" 2>/dev/null; then
		rm -f "${TARERR}"; die "the ${DATE} backup of '${U}' contains no public_html"
	fi
	if ! consumer_ok "restore public_html for ${U}" "${STATUS[@]}"; then
		sed 's/^/    /' "${TARERR}" >&2; rm -f "${TARERR}"; die "the public_html restore failed"
	fi
	rm -f "${TARERR}"

	# the uid in the archive is whatever the account had when it was backed up;
	# the live account is the authority now
	sudo chown -R "${PUID}:${PGID}" "${PHOME}/public_html"
	sudo restorecon -R "${PHOME}/public_html" 2>/dev/null
	log "DONE"
	if [ "${REPLACE}" -eq 1 ]; then
		post_msg success "public_html for '${U}' was restored from the ${DATE} backup. The previous files are in public_html.prerestore-${STAMP}."
	else
		post_msg success "public_html for '${U}' was restored from the ${DATE} backup (merged — files created since then were left in place)."
	fi
	exit 0
fi

# ===========================================================================
# Mode 3 — one database, from wherever that dump actually is
# ===========================================================================
SRC=archive
rsh "test -f '${GZ}'" && SRC=remote
log "database ${DB} comes from ${SRC}"

if [ "${DRY}" -eq 1 ]; then
	if [ "${SRC}" = remote ]; then log "would stream ${GZ} into mysql ${DB}"
	else log "would pull ${ROOTDIR}/databases/${DB}.sql out of ${ARCH} into mysql ${DB}"; fi
	exit 0
fi

EXISTS=$(${MYSQL} -Ns -e "SHOW DATABASES LIKE '${DB}'" 2>/dev/null)
if [ -z "${EXISTS}" ] || [ "${DROP}" -eq 1 ]; then
	# reuse the archive's own CREATE DATABASE so charset and collation match.
	# --occurrence=1 makes tar stop at the first match: the staged tree is at the
	# FRONT of the archive, so this reads a few hundred MB, not the whole thing.
	TMPD=$(mktemp -d /var/tmp/reqad-restore.${U}.XXXXXX)
	EARLY_EXIT_OK=1
	rsh "cat '${ARCH}'" | ${UNGZ} \
		| tar -x -f - -C "${TMPD}" --occurrence=1 "${ROOTDIR}/databases/_create_databases.sql" 2>/dev/null
	consumer_ok "read _create_databases.sql" "${PIPESTATUS[@]}" || log "note: no _create_databases.sql in this archive"
	EARLY_EXIT_OK=0
	CREATE=$(grep -F "\`${DB}\`" "${TMPD}/${ROOTDIR}/databases/_create_databases.sql" 2>/dev/null | head -1)
	rm -rf "${TMPD}"
	[ "${DROP}" -eq 1 ] && ${MYSQL} -e "DROP DATABASE IF EXISTS \`${DB}\`"
	if [ -n "${CREATE}" ]; then ${MYSQL} -e "${CREATE}"
	else ${MYSQL} -e "CREATE DATABASE IF NOT EXISTS \`${DB}\` CHARACTER SET utf8mb4"; fi
fi

# safety net: what is in there right now, before it is overwritten
if [ "${PREDUMP}" -eq 1 ] && [ -n "${EXISTS}" ]; then
	PRE="${REQAD}/backup/prerestore-${DB}-$(date +%Y%m%d-%H%M%S).sql.gz"
	mkdir -p "${REQAD}/backup" 2>/dev/null
	if ${MYSQLDUMP} --opt --lock-tables=false --single-transaction "${DB}" 2>/dev/null | gzip > "${PRE}"; then
		chmod 600 "${PRE}" 2>/dev/null
		log "pre-restore dump written to ${PRE}"
	else
		rm -f "${PRE}"; log "warning: could not take a pre-restore dump of ${DB}"
	fi
fi

if [ "${SRC}" = remote ]; then
	rsh "cat '${GZ}'" | ${UNGZ} | ${MYSQL} "${DB}"
	consumer_ok "import ${DB}" "${PIPESTATUS[@]}" || die "importing '${DB}' failed"
else
	# -O sends the member to stdout, so a multi-GB .sql never lands on disk
	EARLY_EXIT_OK=1
	rsh "cat '${ARCH}'" | ${UNGZ} \
		| tar -x -O -f - --occurrence=1 "${ROOTDIR}/databases/${DB}.sql" \
		| ${MYSQL} "${DB}"
	consumer_ok "import ${DB}" "${PIPESTATUS[@]}" || die "importing '${DB}' failed"
	EARLY_EXIT_OK=0
fi

log "DONE"
post_msg success "Database '${DB}' was restored from the ${DATE} remote backup of '${U}'."
exit 0
