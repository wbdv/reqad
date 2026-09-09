#!/bin/bash
# Rebuild a WHOLE server from one dated backup_remote.sh backup.
#
# This is the disaster-recovery path: fresh box, reqad installed, backup-server
# credentials configured, no accounts yet. It restores, in this order:
#
#   1. the panel database   — swapped in, so the settings table (DNS provider and
#                             its API token, feature config) comes back. The
#                             accounts rows are then cleared, because restore.sh
#                             re-inserts each one authoritatively and refuses to
#                             run while a row already exists.
#   2. every account        — restore_remote.sh --mode account, one at a time.
#                             Not reimplemented here: that script already fetches,
#                             space-checks, hands over to restore.sh and streams
#                             back the oversized databases.
#   3. panel state          — backup_server.sh --restore (email filters, the
#                             global/per-domain Sieve scripts, pipe programs)
#                             plus server-software.ini, which backup_server.sh
#                             backs up but has no restore step for.
#   4. system files         — /etc, cron spool, /root/.ssh, pdns data, unpacked
#                             over /. LAST, and never earlier: this tarball
#                             carries /etc/passwd and /etc/shadow, and with those
#                             in place restore.sh sees every account's user
#                             already existing and refuses all of them.
#   5. service restarts     — /etc has just been replaced under running daemons.
#
# WHY STEP 4 IS GATED ON PACKAGE VERSIONS
# Config formats belong to package versions. Dropping an el8 /etc/dovecot onto an
# el9 box, or a dovecot 2.3 config onto 2.4, produces a server that does not
# start. So the package list captured with the backup is compared against this
# box first, and a mismatch skips step 4 rather than failing the restore — the
# accounts are already back by then, and /etc is the part you can rebuild by hand.
#
# Usage:
#   restore_server.sh --date YYYY-MM-DD [options]
#
#     --system-files MODE  none | safe | everything      (default: safe)
#                          safe        = everything except the host's own
#                                        identity (network config, fstab,
#                                        hostname, machine-id, ssh host keys) —
#                                        the files that belong to THIS box and
#                                        would cut it off the network if replaced
#                          everything  = also those. Only for restoring onto the
#                                        same hardware/IP as the source box.
#                          var/lib/rpm is excluded in BOTH modes, always: it is
#                          in the backup as a record of what was installed, and
#                          writing it over this box's rpm database would leave
#                          rpm describing packages that are not here.
#     --force-packages     restore system files even if the versions differ
#     --only U1,U2         restore only these accounts (default: all of them)
#     --skip-accounts      panel/system only, no accounts
#     --keep-archives      keep each fetched account archive in the work dir
#     --work-dir DIR       where account archives are fetched (default ~reqad/backup)
#     --token HEX16        messages.db token, for the panel's toast
#     --dest PATH          remote prefix holding the dated dirs
#     --host/--port/--ssh-user/--key   override the stored credentials
#     --dry-run            print what would run, change nothing
#     --force              skip the "this server must be empty" guard (dangerous)
#
# Progress is appended to log/restore_server.status as tab-separated records, so
# the panel can poll a long run; the human log goes to log/restore_server.log.
#
# Exit: 0 ok, 1 failed, 2 bad usage, 3 refused by a guard.

set -u
export LC_ALL=C

REQAD=/usr/local/reqad
DEFINES="${REQAD}/public_html/defines.php"
SQLITE="/usr/bin/sqlite3 -init /dev/null -batch -noheader -list"
DB_FILE="${REQAD}/db/reqad.db"
MSG_DB="${REQAD}/db/messages.db"
INI="${REQAD}/etc/server-software.ini"
STATUS="${REQAD}/log/restore_server.status"
if [ -x /usr/bin/unpigz ]; then UNGZ='/usr/bin/unpigz -c'; else UNGZ='/usr/bin/gzip -dc'; fi

DATE=''; TOKEN=''; DRY=0; FORCE=0; FORCE_PKG=0
SYSMODE=safe; ONLY=''; SKIP_ACCOUNTS=0; KEEP_ARCHIVES=0
WORK_DIR="${REQAD}/backup"
SSH_HOST=''; SSH_USER=''; SSH_PORT=''; SSH_KEY=''; DEST=''
CFG_HOST=''; CFG_USER=''; CFG_PORT=''; CFG_KEY=''; CFG_DEST=''

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"; }

# ─── progress, for the panel ────────────────────────────────────────────────
# Append-only: a partial line from a killed run is the last line and is simply
# ignored by the reader, whereas a rewritten status file can be read mid-write.
# The reader keeps the LAST record for each (type,key).
st() { [ "${DRY}" -eq 1 ] && return 0; printf '%s\t%s\n' "$(date +%s)" "$*" >> "${STATUS}" 2>/dev/null; }

post_msg() {   # $1 = type (success|error|info)  $2 = message
	[ -z "${TOKEN}" ] && return
	echo "${TOKEN}" | grep -qE '^[0-9a-f]{16}$' || return
	local m="${2//\'/\'\'}"
	${SQLITE} "${MSG_DB}" "CREATE TABLE IF NOT EXISTS messages (token TEXT PRIMARY KEY, type TEXT NOT NULL DEFAULT 'info', message TEXT NOT NULL, seen INTEGER NOT NULL DEFAULT 0, created INTEGER NOT NULL);" 2>/dev/null
	${SQLITE} "${MSG_DB}" "INSERT OR REPLACE INTO messages (token,type,message,seen,created) VALUES ('${TOKEN}','$1','${m}',0,strftime('%s','now'));" 2>/dev/null
}
die()       { log "ERROR: $1"; st "end failed $1"; post_msg error "$1"; exit 1; }
guard_die() { log "REFUSED: $1"; st "end failed $1"; post_msg error "$1"; exit 3; }
usage_die() { echo "$(basename "$0"): $1" >&2; exit 2; }

valid_user() { [[ "$1" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]]; }
valid_date() { [[ "$1" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]] && date -d "$1" +%F >/dev/null 2>&1; }

while [ $# -gt 0 ]; do
	case "$1" in
		--date)          DATE="${2:-}"; shift 2 ;;
		--system-files)  SYSMODE="${2:-}"; shift 2 ;;
		--force-packages) FORCE_PKG=1; shift ;;
		--only)          ONLY="${2:-}"; shift 2 ;;
		--skip-accounts) SKIP_ACCOUNTS=1; shift ;;
		--keep-archives) KEEP_ARCHIVES=1; shift ;;
		--work-dir)      WORK_DIR="${2:-}"; shift 2 ;;
		--token)         TOKEN="${2:-}"; shift 2 ;;
		--dest)          DEST="${2:-}"; shift 2 ;;
		--host)          SSH_HOST="${2:-}"; shift 2 ;;
		--port)          SSH_PORT="${2:-}"; shift 2 ;;
		--ssh-user)      SSH_USER="${2:-}"; shift 2 ;;
		--key)           SSH_KEY="${2:-}"; shift 2 ;;
		--dry-run)       DRY=1; shift ;;
		--force)         FORCE=1; shift ;;
		-h|--help)       sed -n '2,62p' "$0"; exit 0 ;;
		*) usage_die "unknown option: $1" ;;
	esac
done

valid_date "${DATE}" || usage_die "invalid --date (expected YYYY-MM-DD)"
case "${SYSMODE}" in none|safe|everything) ;; *) usage_die "--system-files must be none, safe or everything" ;; esac
[ -n "${TOKEN}" ] && { [[ "${TOKEN}" =~ ^[0-9a-f]{16}$ ]] || usage_die "invalid --token"; }
ONLY_LIST=()
if [ -n "${ONLY}" ]; then
	IFS=, read -r -a ONLY_LIST <<< "${ONLY}"
	for u in "${ONLY_LIST[@]}"; do valid_user "${u}" || usage_die "invalid user in --only: ${u}"; done
fi

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
if [ "$(id -u)" -eq 0 ]; then
	rsh() { ssh "${SSH_OPTS[@]}" "${SSH_USER}@${SSH_HOST}" "$@"; }
else
	rsh() { sudo -n ssh "${SSH_OPTS[@]}" "${SSH_USER}@${SSH_HOST}" "$@"; }
fi

RD="${DATE}"; [ -n "${DEST}" ] && RD="${DEST%/}/${DATE}"

# ===========================================================================
# Preflight
# ===========================================================================
[ "$(id -u)" -eq 0 ] || die "restore_server.sh must run as root"

# root_access is the panel's switch for "this install may touch root-owned
# things". A whole-server restore is the largest such thing there is, so it is
# honoured here too and not only in the UI that hides the button.
ROOT_OK=$(sed -n 's/^[[:space:]]*root_access[[:space:]]*=[[:space:]]*\([0-9]\).*/\1/p' "${INI}" 2>/dev/null | tail -1)
[ -z "${ROOT_OK}" ] && ROOT_OK=1
[ "${ROOT_OK}" = "1" ] || guard_die "root_access=0 in server-software.ini — a full server restore is disabled on this install"

# One at a time, and never alongside a running backup.
LOCK=/var/run/reqad-restore-server.lock
exec 9>"${LOCK}" 2>/dev/null && { flock -n 9 || die "another full server restore is already running"; }

# The empty-server guard. A full restore recreates users with their ORIGINAL
# uid/gid and replaces /etc wholesale; run against a populated box it collides
# with every one of them. restore.sh refuses per account, but by then the panel
# database has already been swapped.
ACCT_N=$(${SQLITE} "${DB_FILE}" "SELECT count(*) FROM accounts" 2>/dev/null)
[ -n "${ACCT_N}" ] || ACCT_N=0
if [ "${FORCE}" -eq 0 ] && [ "${ACCT_N}" != "0" ]; then
	guard_die "this server already has ${ACCT_N} account(s) — a full server restore only runs on an empty server. Restore accounts individually instead."
fi

: > "${STATUS}" 2>/dev/null
chown reqad:reqad "${STATUS}" 2>/dev/null || true
st "run ${DATE} $$"
st "phase preflight running"
log "full server restore from ${DATE} | ${SSH_USER}@${SSH_HOST}:${SSH_PORT} | system-files=${SYSMODE}"
[ "${DRY}" -eq 1 ] && log "DRY RUN — nothing will be changed"

rsh true >/dev/null 2>&1 || die "cannot reach the backup server ${SSH_USER}@${SSH_HOST}:${SSH_PORT}"
rsh "test -d '${RD}'" || die "there is no ${DATE} backup on the backup server"

MANIFEST=$(rsh "cat '${RD}/MANIFEST.txt' 2>/dev/null")
[ -n "${MANIFEST}" ] || die "the ${DATE} backup has no MANIFEST.txt — the run did not finish, refusing to restore from it"
MFAILED=$(echo "${MANIFEST}" | sed -n 's/^failed[[:space:]]*:[[:space:]]*//p')
[ "${MFAILED}" = "none" ] || log "NOTE: that backup recorded failures: ${MFAILED}"

# accounts present in the backup
mapfile -t USERS < <(rsh "ls -1 '${RD}/accounts/' 2>/dev/null" | sed -n 's/\.tar\.gz$//p' | sort)
if [ ${#ONLY_LIST[@]} -gt 0 ]; then
	SEL=()
	for u in "${ONLY_LIST[@]}"; do
		for a in "${USERS[@]}"; do [ "${a}" = "${u}" ] && SEL+=( "${u}" ); done
	done
	[ ${#SEL[@]} -eq ${#ONLY_LIST[@]} ] || die "--only names an account that is not in the ${DATE} backup"
	USERS=( "${SEL[@]}" )
fi
[ "${SKIP_ACCOUNTS}" -eq 1 ] && USERS=()
for u in "${USERS[@]:-}"; do [ -z "${u}" ] || valid_user "${u}" || die "backup contains an unusable account name: ${u}"; done
log "${#USERS[@]} account(s) to restore"

# Free space, from the per-account indexes rather than a guess. restore_remote.sh
# re-checks per account; this is the up-front "will the whole thing fit" answer,
# so a 22-account restore does not stop at number 19.
IDX=$(rsh "cat '${RD}'/accounts/*.index 2>/dev/null")
TOTAL_HOME_KB=$(echo "${IDX}" | awk '$1=="home_kb"{s+=$2} END{print s+0}')
MAX_ARCH_KB=$(echo "${IDX}" | awk '$1=="archive_bytes"{if($2>m)m=$2} END{printf "%d", (m+1023)/1024}')
HOME_FREE_KB=$(df -Pk /home | awk 'NR==2{print $4}')
NEED_KB=$(( TOTAL_HOME_KB + MAX_ARCH_KB + 1048576 ))
log "space: homedirs ${TOTAL_HOME_KB} KB + largest archive ${MAX_ARCH_KB} KB + 1 GB = ${NEED_KB} KB needed, ${HOME_FREE_KB} KB free on /home"
if [ "${SKIP_ACCOUNTS}" -eq 0 ] && [ "${HOME_FREE_KB}" -lt "${NEED_KB}" ]; then
	die "not enough free space: /home has ${HOME_FREE_KB} KB, the restore needs about ${NEED_KB} KB"
fi
st "phase preflight ok ${#USERS[@]} account(s), ${TOTAL_HOME_KB} KB of homedirs"

# ===========================================================================
# Package compatibility — decides whether step 4 runs at all
# ===========================================================================
SYS_TGZ="${RD}/system/system_files.tar.gz"
PKG_NOTE=''
if [ "${SYSMODE}" != none ]; then
	st "phase packages running"
	rsh "test -f '${SYS_TGZ}'" || { SYSMODE=none; PKG_NOTE="the backup has no system_files.tar.gz"; }
fi
if [ "${SYSMODE}" != none ]; then
	# backup_remote.sh uploads these two small files separately so this check is
	# instant; older backups predate that, and for those they are dug out of the
	# bundle instead (--occurrence=1 stops tar once it has them).
	SRC_OS=$(rsh "cat '${RD}/system/os-release' 2>/dev/null")
	SRC_PKG=$(rsh "cat '${RD}/system/rpmlist.txt' 2>/dev/null")
	if [ -z "${SRC_PKG}" ]; then
		log "this backup predates the separate package list — reading it out of system_files.tar.gz"
		TMPX=$(mktemp -d /var/tmp/reqad-pkgcheck.XXXXXX)
		rsh "cat '${SYS_TGZ}'" 2>/dev/null | ${UNGZ} 2>/dev/null \
			| tar -x -f - -C "${TMPX}" --occurrence=1 --warning=no-timestamp \
			      root/rpmlist.txt etc/os-release 2>/dev/null
		[ -s "${TMPX}/root/rpmlist.txt" ] && SRC_PKG=$(cat "${TMPX}/root/rpmlist.txt")
		[ -z "${SRC_OS}" ] && [ -s "${TMPX}/etc/os-release" ] && SRC_OS=$(cat "${TMPX}/etc/os-release")
		rm -rf "${TMPX}"
	fi

	PKG_DIFF=()
	# 1. the OS major version. Nothing else matters if this differs: el8 and el9
	#    config formats are not interchangeable.
	SRC_EL=$(echo "${SRC_OS}" | sed -n 's/^VERSION_ID="\?\([0-9]*\).*/\1/p' | head -1)
	THIS_EL=$(sed -n 's/^VERSION_ID="\?\([0-9]*\).*/\1/p' /etc/os-release | head -1)
	if [ -n "${SRC_EL}" ] && [ -n "${THIS_EL}" ] && [ "${SRC_EL}" != "${THIS_EL}" ]; then
		PKG_DIFF+=( "OS major version: backup is EL${SRC_EL}, this box is EL${THIS_EL}" )
	fi
	# 2. the packages whose configuration lives in the paths this bundle carries.
	#    VERSION only, not release: a rebuild for the same version keeps the same
	#    config format, and requiring the release to match would make this useless.
	if [ -n "${SRC_PKG}" ]; then
		nvr_to_nv() {   # NVRA lines (name-version-release.arch) -> "name version"
			sed -E 's/\.(x86_64|noarch|i686|aarch64)$//' \
			| sed -E 's/^(.+)-([^-]+)-([^-]+)$/\1 \2/'
		}
		# Matched by PATTERN, not by a hand-written list of names: the per-version
		# php packages are php82-php-fpm (not php82-fpm) and MariaDB is capitalised
		# upstream, so a literal list quietly compares nothing. Only packages that
		# OWN configuration under the paths this bundle carries are compared, and
		# only their VERSION -- a rebuild at the same version keeps the same config
		# format, and requiring the release to match would make this always fail.
		PKGRE='^(reqad|nginx|httpd|exim|dovecot|dovecot-pigeonhole|pigeonhole|MariaDB-server|mariadb-server|pdns|pdns-backend-[a-z0-9]+|php-fpm|php[0-9]+-php-fpm|opendkim|clamav|spamassassin)$'
		SRC_NV=$(mktemp /var/tmp/reqad-pkg-src.XXXXXX)
		NOW_NV=$(mktemp /var/tmp/reqad-pkg-now.XXXXXX)
		echo "${SRC_PKG}" | nvr_to_nv | sort -u > "${SRC_NV}"
		rpm -qa          | nvr_to_nv | sort -u > "${NOW_NV}"
		# A package installed HERE but absent from the backup is not reported: the
		# backup simply carries no config for it, which breaks nothing.
		while IFS= read -r d; do
			[ -n "${d}" ] && PKG_DIFF+=( "${d}" )
		done < <(awk -v re="${PKGRE}" '
			NR==FNR { if ($1 ~ re) src[$1]=$2; next }
			        { if ($1 ~ re) now[$1]=$2 }
			END {
				for (n in src) {
					if (!(n in now))          print n ": " src[n] " in the backup, not installed here";
					else if (src[n] != now[n]) print n ": " src[n] " in the backup, " now[n] " here";
				}
			}' "${SRC_NV}" "${NOW_NV}" | sort)
		NCHECKED=$(awk -v re="${PKGRE}" '$1 ~ re' "${NOW_NV}" | wc -l)
		log "package check: compared ${NCHECKED} config-owning package(s)"
		rm -f "${SRC_NV}" "${NOW_NV}"
	else
		PKG_DIFF+=( "the backup carries no package list — cannot verify compatibility" )
	fi

	if [ ${#PKG_DIFF[@]} -eq 0 ]; then
		log "package check: the config-owning packages match this box"
		st "phase packages ok versions match"
	else
		log "package check: ${#PKG_DIFF[@]} difference(s):"
		for d in "${PKG_DIFF[@]}"; do log "    ${d}"; done
		if [ "${FORCE_PKG}" -eq 1 ]; then
			log "--force-packages given — restoring system files anyway"
			st "phase packages ok ${#PKG_DIFF[@]} difference(s), forced"
		else
			PKG_NOTE="package versions differ (${PKG_DIFF[0]}) — pass --force-packages to restore /etc anyway"
			SYSMODE=none
			st "phase packages failed ${PKG_NOTE}"
		fi
	fi
fi

# ===========================================================================
# 1. panel database — swapped in, then the accounts rows cleared
# ===========================================================================
st "phase paneldb running"
mkdir -p "${WORK_DIR}" || die "cannot create ${WORK_DIR}"
SRV_TGZ="${RD}/system/reqad-server.tar.gz"
SRV_LOCAL="${WORK_DIR}/reqad-server_${DATE}.tar.gz"
SRV_STAGE=''
if rsh "test -f '${SRV_TGZ}'"; then
	if [ "${DRY}" -eq 1 ]; then
		log "would fetch ${SRV_TGZ} and swap in its db/reqad.db"
	else
		rsh "cat '${SRV_TGZ}'" > "${SRV_LOCAL}" || die "could not fetch ${SRV_TGZ}"
		chmod 600 "${SRV_LOCAL}"
		SRV_STAGE=$(mktemp -d /var/tmp/reqad-srvrestore.XXXXXX)
		tar xzf "${SRV_LOCAL}" -C "${SRV_STAGE}" || die "could not unpack the panel state archive"
		R="${SRV_STAGE}/reqad-server"
		if [ -f "${R}/db/reqad.db" ]; then
			cp -p "${DB_FILE}" "${DB_FILE}.before-restore-$(date +%Y%m%d-%H%M%S)" 2>/dev/null || true
			install -o reqad -g reqad -m 640 "${R}/db/reqad.db" "${DB_FILE}" \
				|| die "could not install the restored panel database"
			# restore.sh refuses while a row exists and re-inserts each one from
			# the archive's meta/account.tsv, so the rows must go — everything
			# else in the database (settings, email_filters, wordpress) stays.
			${SQLITE} "${DB_FILE}" "DELETE FROM accounts;" 2>/dev/null
			# ...and the credentials that are actually reaching the backup server
			# win over whatever the snapshot held, or the rest of this run would
			# try to fetch 22 archives through stale settings.
			for kv in "backup-remote-host ${SSH_HOST}" "backup-remote-user ${SSH_USER}" \
			          "backup-remote-port ${SSH_PORT}" "backup-remote-key ${SSH_KEY}" \
			          "backup-remote-dest ${DEST}"; do
				k="${kv%% *}"; v="${kv#* }"; v="${v//\'/\'\'}"
				${SQLITE} "${DB_FILE}" "INSERT INTO settings (name,value) VALUES ('${k}','${v}') ON CONFLICT(name) DO UPDATE SET value=excluded.value;" 2>/dev/null
			done
			log "panel database restored (accounts rows cleared for restore.sh)"
			st "phase paneldb ok settings and filters restored"
		else
			log "the panel state archive has no database snapshot"
			st "phase paneldb failed no database in the archive"
		fi
	fi
else
	log "no system/reqad-server.tar.gz in this backup — panel state cannot be restored"
	st "phase paneldb failed not in this backup"
fi

# ===========================================================================
# 2. accounts
# ===========================================================================
OK_USERS=(); BAD_USERS=()
st "phase accounts running 0/${#USERS[@]}"
i=0
for u in "${USERS[@]:-}"; do
	[ -z "${u}" ] && continue
	i=$(( i + 1 ))
	st "phase accounts running ${i}/${#USERS[@]}"
	st "account ${u} running"
	log "── account ${i}/${#USERS[@]}: ${u} ──"
	ARGS=( --mode account --user "${u}" --date "${DATE}"
	       --host "${SSH_HOST}" --port "${SSH_PORT}" --ssh-user "${SSH_USER}"
	       --work-dir "${WORK_DIR}" )
	[ -n "${SSH_KEY}" ] && ARGS+=( --key "${SSH_KEY}" )
	[ -n "${DEST}" ]    && ARGS+=( --dest "${DEST}" )
	[ "${KEEP_ARCHIVES}" -eq 1 ] && ARGS+=( --keep-archive )
	[ "${DRY}" -eq 1 ] && ARGS+=( --dry-run )
	# Each account is independent, so one failure does not stop the other 21:
	# the run reports which ones failed and they are retried individually.
	if "${REQAD}/scripts/restore_remote.sh" "${ARGS[@]}" 2>&1 | sed 's/^/    /'; then
		OK_USERS+=( "${u}" ); st "account ${u} ok"
	else
		BAD_USERS+=( "${u}" ); st "account ${u} failed see log/restore_server.log"
		log "FAILED: account ${u}"
	fi
done
st "phase accounts ok ${#OK_USERS[@]} restored, ${#BAD_USERS[@]} failed"

# ===========================================================================
# 3. panel state: filters, Sieve, pipe programs, server-software.ini
# ===========================================================================
st "phase panelstate running"
if [ -n "${SRV_STAGE}" ] && [ -f "${SRV_LOCAL}" ]; then
	"${REQAD}/scripts/backup_server.sh" --restore "${SRV_LOCAL}" 2>&1 | sed 's/^/    /'
	# server-software.ini is in the archive but backup_server.sh has no restore
	# step for it (it merges into LIVE boxes, where replacing the feature flags
	# would be wrong). Here the box is a rebuild, so it is taken verbatim.
	if [ -f "${SRV_STAGE}/reqad-server/server-software.ini" ]; then
		cp -p "${INI}" "${INI}.before-restore-$(date +%Y%m%d-%H%M%S)" 2>/dev/null || true
		install -o reqad -g reqad -m 644 "${SRV_STAGE}/reqad-server/server-software.ini" "${INI}" \
			&& log "restored server-software.ini"
	fi
	st "phase panelstate ok"
elif [ "${DRY}" -eq 1 ]; then
	log "would run backup_server.sh --restore and put back server-software.ini"
	st "phase panelstate ok dry run"
else
	st "phase panelstate failed panel state archive was not fetched"
fi
[ -n "${SRV_STAGE}" ] && rm -rf "${SRV_STAGE}"

# ===========================================================================
# 4. system files — /etc and friends, over the top, LAST
# ===========================================================================
if [ "${SYSMODE}" = none ]; then
	log "system files: skipped${PKG_NOTE:+ — ${PKG_NOTE}}"
	st "phase sysfiles skipped ${PKG_NOTE:-not requested}"
else
	st "phase sysfiles running"
	# Always excluded: this box's rpm database describes what is actually
	# installed HERE. The backup's copy is a record of the old box, and writing
	# it over the live one makes every later rpm/dnf operation wrong.
	EXCL=( --exclude='var/lib/rpm' --exclude='var/lib/rpm/*' )
	if [ "${SYSMODE}" = safe ]; then
		# The files that identify THIS machine rather than the service config.
		# Restoring these onto different hardware is how a remote restore ends
		# with a box that never comes back on the network.
		for e in etc/fstab etc/crypttab etc/mtab etc/hostname etc/hosts etc/resolv.conf \
		         'etc/sysconfig/network*' 'etc/NetworkManager/*' etc/machine-id \
		         'etc/ssh/ssh_host_*' etc/ssh/sshd_config 'etc/udev/rules.d/*' \
		         etc/default/grub 'etc/grub.d/*' 'etc/sysconfig/network-scripts/*'; do
			EXCL+=( --exclude="${e}" )
		done
	fi
	if [ "${DRY}" -eq 1 ]; then
		log "would extract ${SYS_TGZ} over / (${SYSMODE}) with ${#EXCL[@]} exclusion(s)"
		st "phase sysfiles ok dry run"
	else
		log "extracting system files over / (mode ${SYSMODE})"
		set -o pipefail
		rsh "cat '${SYS_TGZ}'" | ${UNGZ} | tar -x -f - -C / -p --numeric-owner \
			--warning=no-timestamp --warning=no-unknown-keyword "${EXCL[@]}"
		SYSRC=$?
		set +o pipefail
		if [ ${SYSRC} -eq 0 ]; then
			log "system files restored"
			st "phase sysfiles ok mode ${SYSMODE}"
		else
			log "FAILED: system file extraction exited ${SYSRC}"
			st "phase sysfiles failed tar exited ${SYSRC}"
		fi
	fi
fi

# ===========================================================================
# 5. services — /etc has just changed under them
# ===========================================================================
if [ "${SYSMODE}" != none ] && [ "${DRY}" -eq 0 ]; then
	st "phase services running"
	systemctl daemon-reload 2>/dev/null
	RESTARTED=()
	for s in mariadb mysqld named pdns exim dovecot nginx httpd reqad-php-fpm \
	         php-fpm php74-php-fpm php82-php-fpm php83-php-fpm php84-php-fpm php85-php-fpm; do
		systemctl list-unit-files "${s}.service" >/dev/null 2>&1 || continue
		systemctl is-enabled "${s}" >/dev/null 2>&1 || continue
		systemctl restart "${s}" >/dev/null 2>&1 && RESTARTED+=( "${s}" ) || log "note: ${s} did not restart"
	done
	log "restarted: ${RESTARTED[*]:-nothing}"
	st "phase services ok ${RESTARTED[*]:-nothing}"
fi

# ===========================================================================
[ "${KEEP_ARCHIVES}" -eq 0 ] && rm -f "${SRV_LOCAL}"

SUMMARY="${#OK_USERS[@]} account(s) restored from ${DATE}"
[ ${#BAD_USERS[@]} -gt 0 ] && SUMMARY="${SUMMARY}; FAILED: ${BAD_USERS[*]}"
[ -n "${PKG_NOTE}" ] && SUMMARY="${SUMMARY}; system files skipped (${PKG_NOTE})"
log "DONE — ${SUMMARY}"
if [ ${#BAD_USERS[@]} -gt 0 ]; then
	st "end partial ${SUMMARY}"
	post_msg error "Server restore finished with errors: ${SUMMARY}"
	exit 1
fi
st "end ok ${SUMMARY}"
post_msg success "Server restore finished: ${SUMMARY}"
exit 0
