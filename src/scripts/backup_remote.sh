#!/bin/bash
# Full-server remote backup: every account streamed one by one to a backup
# server over ssh, nothing large ever spooled on this disk.
#
# The panel's backup.sh writes a tarball into ~/backup and the admin downloads
# it. That does not scale to a nightly full-server run: a box with 200 GB of
# homedirs needs 200 GB of free space to back up 200 GB. This script instead
# pipes tar straight into ssh, the way the cPanel nightly does, so the only
# thing that touches local disk is the small per-account metadata staging tree
# (config, ssl, meta, dns, email, and database dumps below --db-max-mb).
#
# It does NOT reimplement what goes into an account backup: it calls
#   backup.sh <user> -w -m -d --stage-only <dir> --db-max-mb <N>
# and tars that staged tree together with /home/<user> into the pipe, so the
# archive that lands on the backup server is byte-for-byte the layout restore.sh
# already understands (a single root folder backup-<user>/).
#
# Databases at or over --db-max-mb are left out of that archive and dumped
# straight into their own ssh pipe (databases/<user>/<db>.sql.gz). Their
# CREATE DATABASE and their grants still ride inside the account archive, so a
# restore rebuilds the empty database and only the data is piped back in.
#
# Remote layout (dated directories straight in the ssh user's home by default,
# since a per-server backup account already isolates this host; --dest adds a
# prefix where one account holds several servers):
#
#   <dest>/<YYYY-MM-DD>/
#       system/system_files.tar.gz    /etc, cron spool, rpm db, root ssh keys, pdns
#       system/reqad-server.tar.gz    panel db, sieve tree, defines.php, ini
#       accounts/<user>.tar.gz        full account, restore.sh-compatible
#       databases/<user>/<db>.sql.gz  only databases >= --db-max-mb
#       MANIFEST.txt
#
# Usage:
#   backup_remote.sh [options]
#     --user U          back up only this account (repeatable)
#     --dest PATH       remote directory holding the dated backup dirs (default:
#                       the ssh user's home; relative unless it starts with /)
#     --db-max-mb N     stream databases >= N MB separately (default 1024, 0=off)
#     --max-load N      refuse to start when the 1-min load is >= N (default 30)
#     --cool-load N     pause between accounts while load is >= N (default 8)
#     --min-free-kb N   require N KB free on the backup server (default: as much
#                       as /home currently uses)
#     --keep N          after a successful run, delete all but the newest N date
#                       directories under <dest>  (default: keep everything)
#     --no-system       skip the system/ bundles
#     --no-db           skip databases entirely
#     --mail ADDR       email a report to ADDR when the run fails
#     --host / --port / --ssh-user / --key    override the defines.php settings
#     --dry-run         show what would run, transfer nothing
#
# Connection settings default to the ones the panel already stores in
# public_html/defines.php ($backup_server, $backup_user, $backup_sshport,
# $backup_sshkey) — the same ones the Backup DB page uses.
#
# Cron (nightly at 02:15):
#   15 2 * * * root /usr/local/reqad/scripts/backup_remote.sh >> /usr/local/reqad/log/backup_remote.log 2>&1

set -u
export LC_ALL=C

REQAD=/usr/local/reqad
DEFINES="${REQAD}/public_html/defines.php"
# stock EL sqlite (what install-el*.sh actually installs — /usr/local/bin/sqlite3
# is a hand-built binary that exists on some boxes and not others). -init
# /dev/null ignores ~/.sqliterc, whose ".mode box" is both newer than EL8's
# 3.26 and wrong for output this script parses; -noheader -list pins the format.
SQLITE="/usr/bin/sqlite3 -init /dev/null -batch -noheader -list"
DB_FILE="${REQAD}/db/reqad.db"
MYSQL='mysql --defaults-extra-file=/root/.my.cnf'
MYSQLDUMP='/usr/bin/mysqldump --defaults-extra-file=/root/.my.cnf'
[ -x /usr/bin/mariadb-dump ] && MYSQLDUMP='/usr/bin/mariadb-dump --defaults-extra-file=/root/.my.cnf'
LOCK=/var/run/reqad-backup-remote.lock
HOST_SHORT=$(hostname -s)
DATE=$(date -I)

# pigz keeps the compression off the critical path on a multi-core box; plain
# gzip is a drop-in when it is not installed. --rsyncable costs a little ratio
# and makes consecutive nightly archives dedup/rsync well on the backup server.
if [ -x /usr/bin/pigz ]; then
    GZIP='/usr/bin/pigz --processes 4 --blocksize 4096 --rsyncable'
else
    GZIP='/usr/bin/gzip --rsyncable'
fi

# ─── defaults, then settings table, then defines.php, then flags ────────────
# The panel's Backup page writes these into the settings table (like the DNS API
# tokens). defines.php is the older home — the panel has never written it, and an
# RPM update never rewrites it, so an install that predates that page still has
# its credentials only there. Settings win; defines.php fills what they leave empty.
SSH_HOST=''; SSH_USER=''; SSH_PORT=''; SSH_KEY=''; DEST_CFG=''
if [ -r "${DB_FILE}" ]; then
    setting() { ${SQLITE} "${DB_FILE}" "SELECT value FROM settings WHERE name='$1'" 2>/dev/null; }
    SSH_HOST=$(setting backup-remote-host)
    SSH_USER=$(setting backup-remote-user)
    SSH_PORT=$(setting backup-remote-port)
    SSH_KEY=$(setting backup-remote-key)
    DEST_CFG=$(setting backup-remote-dest)
fi
# defines.php is PHP, so read it with sed rather than sourcing it
if [ -r "${DEFINES}" ]; then
    php_var() { sed -n "s/^[[:space:]]*\\\$$1[[:space:]]*=[[:space:]]*['\"]\([^'\"]*\)['\"].*/\1/p" "${DEFINES}" | tail -1; }
    [ -n "${SSH_USER}" ] || SSH_USER=$(php_var backup_user)
    [ -n "${SSH_HOST}" ] || SSH_HOST=$(php_var backup_server)
    [ -n "${SSH_PORT}" ] || SSH_PORT=$(php_var backup_sshport)
    [ -n "${SSH_KEY}" ]  || SSH_KEY=$(php_var backup_sshkey)
fi
[ -n "${SSH_PORT}" ] || SSH_PORT=22

DEST="${DEST_CFG}"
DB_MAX_MB=1024
MAX_LOAD=30
COOL_LOAD=8
MIN_FREE_KB=''
KEEP=0
DO_SYSTEM=1
DO_DB=1
MAILTO=''
DRY=0
ONLY_USERS=()
STAGE_BASE=/var/tmp/reqad-backup

while [ $# -gt 0 ]; do
    case "$1" in
        --user)        ONLY_USERS+=("$2"); shift 2 ;;
        --dest)        DEST="$2"; shift 2 ;;
        --db-max-mb)   DB_MAX_MB="$2"; shift 2 ;;
        --max-load)    MAX_LOAD="$2"; shift 2 ;;
        --cool-load)   COOL_LOAD="$2"; shift 2 ;;
        --min-free-kb) MIN_FREE_KB="$2"; shift 2 ;;
        --keep)        KEEP="$2"; shift 2 ;;
        --no-system)   DO_SYSTEM=0; shift ;;
        --no-db)       DO_DB=0; shift ;;
        --mail)        MAILTO="$2"; shift 2 ;;
        --host)        SSH_HOST="$2"; shift 2 ;;
        --port)        SSH_PORT="$2"; shift 2 ;;
        --ssh-user)    SSH_USER="$2"; shift 2 ;;
        --key)         SSH_KEY="$2"; shift 2 ;;
        --stage-dir)   STAGE_BASE="$2"; shift 2 ;;
        --dry-run)     DRY=1; shift ;;
        -h|--help)     sed -n '2,60p' "$0"; exit 0 ;;
        *) echo "unknown option: $1" >&2; exit 2 ;;
    esac
done

# No --dest: the dated directories live directly in the ssh user's home, which
# is what a dedicated per-server backup account gives you already.
if [ -n "${DEST}" ]; then
    REMOTE_DIR="${DEST}/${DATE}"
else
    REMOTE_DIR="${DATE}"
fi
[ -n "${SSH_PORT}" ] || SSH_PORT=22

log()  { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"; }
die()  { log "ERROR: $*"; notify "backup aborted" "$*"; exit 1; }

FAILED=()
notify() {   # $1 = subject tail, $2 = body
    [ -n "${MAILTO}" ] || return 0
    printf 'To: %s\nSubject: [%s] Reqad remote backup: %s\n\n%s\n' \
        "${MAILTO}" "${HOST_SHORT}" "$1" "$2" | /usr/sbin/sendmail -t 2>/dev/null
}

# ─── ssh plumbing ───────────────────────────────────────────────────────────
SSH_OPTS=( -p "${SSH_PORT}" -o BatchMode=yes -o StrictHostKeyChecking=accept-new
           -o ServerAliveInterval=30 -o ServerAliveCountMax=6 -o Compression=no )
[ -n "${SSH_KEY}" ] && SSH_OPTS+=( -i "${SSH_KEY}" )

rsh() {   # run a command on the backup server
    ssh "${SSH_OPTS[@]}" "${SSH_USER}@${SSH_HOST}" "$@"
}

# Stream stdin into a file on the backup server. Everything large goes through
# here, so this is the one place that decides how a remote file gets written.
rput() {   # $1 = remote path (relative to the ssh user's home unless absolute)
    rsh "cat > '$1'"
}

# tar|gzip|ssh in one place so every transfer reports the failure of ANY stage
# of the pipe — without this a tar that dies half way still leaves a valid-
# looking .tar.gz on the backup server, because ssh itself exited 0.
# tar exits 1 when a file changed or vanished underneath it. On a live server
# that happens constantly (dovecot writing into a maildir, a session file being
# rotated) and the archive is still good, so stage 1 is allowed to warn when the
# caller passes tar-warn-ok. Exit 2 is a real tar error and always fails.
pipe_status_ok() {   # $1 = label  $2 = 1 if a tar warning is acceptable  $3.. = PIPESTATUS
    local label="$1" tar_warn_ok="$2"; shift 2
    local first="$1" st rc=0
    shift
    if [ "${first}" -eq 1 ] && [ "${tar_warn_ok}" -eq 1 ]; then
        log "note: ${label} — tar reported changed/vanished files (normal on a live server)"
    elif [ "${first}" -ne 0 ]; then
        log "FAILED: ${label} (exit ${first} from the producer)"
        rc=1
    fi
    for st in "$@"; do
        [ "${st}" -eq 0 ] && continue
        log "FAILED: ${label} (exit ${st} in the pipeline)"
        rc=1
    done
    return ${rc}
}

# ─── preflight ──────────────────────────────────────────────────────────────
[ "$(id -u)" -eq 0 ] || die "must run as root (it reads /etc/shadow, cron spools and every homedir)"
[ -n "${SSH_HOST}" ] || die "no backup server configured — set \$backup_server in defines.php or pass --host"
[ -n "${SSH_USER}" ] || die "no backup user configured — set \$backup_user in defines.php or pass --ssh-user"
[ -x "${REQAD}/scripts/backup.sh" ] || die "${REQAD}/scripts/backup.sh not found"
# An older backup.sh IGNORES unknown options: it would quietly build a full local
# tarball in ~/backup, exit 0, and leave no staged tree — after which the stream
# below carries the homedir alone and the archive on the backup server has no
# meta/, no config, no databases. Refuse instead.
grep -q -- '--stage-only' "${REQAD}/scripts/backup.sh" \
    || die "${REQAD}/scripts/backup.sh does not support --stage-only — update it (and restore.sh/backup_server.sh) on this server before backing up remotely"

# The helper scripts hardcode the sqlite3 they call. A HALF-deployed update (new
# backup.sh, stale backup_server.sh) then dies mid-run on a server that has no
# /usr/local/bin/sqlite3 — that binary is hand-built and owned by no package, so
# most servers do not have it. Check every path they name before starting.
for helper in backup.sh backup_server.sh restore.sh; do
    [ -f "${REQAD}/scripts/${helper}" ] || continue
    for sq in $(grep -oh '/[a-z/]*bin/sqlite3' "${REQAD}/scripts/${helper}" | sort -u); do
        [ -x "${sq}" ] || die "${helper} calls ${sq}, which does not exist here — that copy is stale, deploy the current one (it should use /usr/bin/sqlite3)"
    done
done

# one run at a time: a nightly that overruns must not be joined by the next one
exec 9>"${LOCK}"
flock -n 9 || die "another backup_remote.sh run holds ${LOCK}"

LOAD=$(awk -F. '{print $1}' /proc/loadavg)
if [ "${LOAD}" -ge "${MAX_LOAD}" ]; then
    die "load too high (${LOAD} >= ${MAX_LOAD})"
fi

if [ "${DRY}" -eq 1 ]; then
    log "DRY RUN — no data will be transferred"
fi

log "backup server : ${SSH_USER}@${SSH_HOST}:${SSH_PORT}${SSH_KEY:+ (key ${SSH_KEY})}"
log "remote dir    : ${REMOTE_DIR}"
log "compressor    : ${GZIP%% *}"

# csf: the outbound port has to be open or the ssh never leaves this box. That
# failure looks exactly like a backup server that is down — the connection just
# hangs and times out — so name the real cause instead of letting ssh guess.
CSF_CONF=/etc/csf/csf.conf
if [ -r "${CSF_CONF}" ]; then
    csf_port_open() {   # $1 = port, $2 = a TCP_OUT-style list ("22,80,1000:2000")
        local p="$1" r
        local -a spec
        IFS=',' read -ra spec <<< "$2"
        for r in "${spec[@]}"; do
            r="${r//[[:space:]]/}"
            [ -n "${r}" ] || continue
            case "${r}" in
                *:*) [ "${p}" -ge "${r%%:*}" ] && [ "${p}" -le "${r##*:}" ] && return 0 ;;
                *)   [ "${p}" = "${r}" ] && return 0 ;;
            esac
        done
        return 1
    }
    TCP_OUT=$(sed -n 's/^[[:space:]]*TCP_OUT[[:space:]]*=[[:space:]]*"\([^"]*\)".*/\1/p' "${CSF_CONF}" | tail -1)
    if [ -n "${TCP_OUT}" ] && ! csf_port_open "${SSH_PORT}" "${TCP_OUT}"; then
        die "csf blocks outbound port ${SSH_PORT} — add it to TCP_OUT in ${CSF_CONF} and run 'csf -r'"
    fi
fi

rsh true || die "cannot ssh to ${SSH_USER}@${SSH_HOST}:${SSH_PORT}"

# free space: default to "at least as much as /home is using". Compression means
# that is generous, which is the point — a backup that fills the backup server
# half way through is worse than one that refuses to start.
if [ -z "${MIN_FREE_KB}" ]; then
    MIN_FREE_KB=$(df -Pk /home | awk 'NR==2 {print $3}')
fi
FREE_KB=$(rsh "df -Pk ." | awk 'NR==2 {print $4}')
if [ -n "${FREE_KB}" ] && [ "${FREE_KB}" -lt "${MIN_FREE_KB}" ]; then
    die "not enough free space on ${SSH_HOST}: ${FREE_KB} KB free, ${MIN_FREE_KB} KB required"
fi
log "free on backup server: ${FREE_KB} KB (required ${MIN_FREE_KB} KB)"

# ─── account list ───────────────────────────────────────────────────────────
if [ ${#ONLY_USERS[@]} -gt 0 ]; then
    USERS=( "${ONLY_USERS[@]}" )
else
    # the panel's accounts table is the inventory; a row whose system user is
    # gone (half-deleted account) is skipped rather than failing the run
    mapfile -t USERS < <(${SQLITE} "${DB_FILE}" "SELECT user FROM accounts ORDER BY user")
fi
[ ${#USERS[@]} -gt 0 ] || die "no accounts to back up"
log "accounts      : ${#USERS[@]}"

if [ "${DRY}" -eq 0 ]; then
    rsh "mkdir -p '${REMOTE_DIR}/accounts' '${REMOTE_DIR}/databases' '${REMOTE_DIR}/system'" \
        || die "cannot create ${REMOTE_DIR} on ${SSH_HOST}"
fi

# wait for the box to calm down between accounts — a nightly backup must not be
# the reason a customer's site times out
cool_down() {
    local l
    l=$(awk -F. '{print $1}' /proc/loadavg)
    while [ "${l}" -ge "${COOL_LOAD}" ]; do
        log "waiting for load to drop (${l} >= ${COOL_LOAD})"
        sleep 30
        l=$(awk -F. '{print $1}' /proc/loadavg)
    done
}

# ─── system bundles ─────────────────────────────────────────────────────────
if [ "${DO_SYSTEM}" -eq 1 ]; then
    log "── system files ──"
    # the installed package list, so a rebuild starts from the same set
    rpm -qa | sort > /root/rpmlist.txt

    # only the paths that exist on this box (pdns vs named, apache vs nginx, …)
    SYS_PATHS=()
    # /etc carries exim, dovecot, nginx, httpd, letsencrypt, reqad, php-fpm.d AND
    # /etc/opt/remi/php*/ (the per-version php.ini + pools). root/.my.cnf is named
    # explicitly because backup.sh and restore.sh both run mysql through it — the
    # rest of /root is 1.7 GB of scratch and is deliberately not backed up.
    for p in etc var/spool/cron var/lib/rpm root/.ssh root/.my.cnf root/rpmlist.txt var/lib/pdns etc/pdns var/lib/reqad; do
        [ -e "/${p}" ] && SYS_PATHS+=( "${p}" )
    done

    # Also pushed as two standalone files, because restore_server.sh has to read
    # them BEFORE it decides whether unpacking /etc onto a rebuilt box is safe.
    # They are inside the bundle as well, but root/rpmlist.txt sits behind
    # var/lib/rpm in it, so digging them out means streaming a few hundred MB.
    if [ "${DRY}" -eq 0 ]; then
        rput "${REMOTE_DIR}/system/rpmlist.txt" < /root/rpmlist.txt \
            || log "note: could not write system/rpmlist.txt"
        [ -f /etc/os-release ] && { rput "${REMOTE_DIR}/system/os-release" < /etc/os-release \
            || log "note: could not write system/os-release"; }
    fi

    if [ "${DRY}" -eq 1 ]; then
        log "would stream: tar ${SYS_PATHS[*]} → ${REMOTE_DIR}/system/system_files.tar.gz"
    else
        tar cf - --warning=no-file-changed --warning=no-file-removed \
            -C / "${SYS_PATHS[@]}" \
            | ${GZIP} | rput "${REMOTE_DIR}/system/system_files.tar.gz"
        pipe_status_ok "system_files.tar.gz" 1 "${PIPESTATUS[@]}" || FAILED+=("system_files")
    fi

    # panel state (db, sieve tree, defines.php, ini) — backup_server.sh already
    # knows exactly what belongs in there, so let it build the tarball and only
    # push the result. It is small; there is nothing to stream.
    log "── panel state ──"
    if [ "${DRY}" -eq 1 ]; then
        log "would run backup_server.sh and push it to ${REMOTE_DIR}/system/reqad-server.tar.gz"
    else
        SRV_LOG=$(mktemp /var/tmp/reqad-srvbackup.XXXXXX)
        "${REQAD}/scripts/backup_server.sh" > "${SRV_LOG}" 2>&1
        SRV_OUT=$(sed -n 's/^Created //p' "${SRV_LOG}")
        if [ -n "${SRV_OUT}" ] && [ -f "${SRV_OUT}" ]; then
            rput "${REMOTE_DIR}/system/reqad-server.tar.gz" < "${SRV_OUT}" \
                && rm -f "${SRV_OUT}" \
                || FAILED+=("reqad-server")
        else
            log "FAILED: backup_server.sh produced no archive"
            [ -s "${SRV_LOG}" ] && tail -10 "${SRV_LOG}" | sed 's/^/    /'
            FAILED+=("reqad-server")
        fi
        rm -f "${SRV_LOG}"
    fi
fi

# ─── accounts ───────────────────────────────────────────────────────────────
for U in "${USERS[@]}"; do
    [ -n "${U}" ] || continue
    if ! id -u "${U}" >/dev/null 2>&1; then
        log "skip ${U}: no such system user"
        continue
    fi
    cool_down
    log "── account ${U} ──"

    if [ "${DRY}" -eq 1 ]; then
        log "would stage ${U} and stream → ${REMOTE_DIR}/accounts/${U}.tar.gz"
        continue
    fi

    STAGE=$(mktemp -d "${STAGE_BASE}.XXXXXX" 2>/dev/null) || { mkdir -p "$(dirname "${STAGE_BASE}")"; STAGE=$(mktemp -d "${STAGE_BASE}.XXXXXX"); }

    # everything except the homedir: config, ssl, cron, meta, dns, email and the
    # database dumps under the threshold
    DBFLAG=()
    [ "${DO_DB}" -eq 1 ] && DBFLAG=( -d --db-max-mb "${DB_MAX_MB}" )
    STAGE_LOG="${STAGE}/.stage.log"
    "${REQAD}/scripts/backup.sh" "${U}" -w -m "${DBFLAG[@]}" --stage-only "${STAGE}" > "${STAGE_LOG}" 2>&1
    STAGE_RC=$?
    # exit 0 is not enough: backup.sh exits 0 on "user does not exist" too, and an
    # empty tree means the archive would be a homedir with no account metadata —
    # which restore.sh cannot rebuild an account from. Never stream that.
    if [ ${STAGE_RC} -ne 0 ] || [ ! -d "${STAGE}/backup-${U}" ]; then
        log "FAILED: staging ${U} (exit ${STAGE_RC}, backup-${U} $([ -d "${STAGE}/backup-${U}" ] && echo present || echo missing))"
        [ -s "${STAGE_LOG}" ] && tail -20 "${STAGE_LOG}" | sed 's/^/    /'
        FAILED+=("${U}:stage")
        rm -rf "${STAGE}"
        continue
    fi
    rm -f "${STAGE_LOG}"

    # staged tree + /home/<user> in ONE tar, straight into the ssh pipe. The
    # transform rewrites /home/<user> to backup-<user>/homedir/ exactly the way
    # backup.sh does, so restore.sh sees the archive it expects; S/H keep symlink
    # and hardlink targets untouched.
    #
    # ORDER IS LOAD-BEARING: the staged tree goes in FIRST, the homedir second.
    # That puts backup-<user>/databases/*.sql at the very front of the archive, so
    # restore_remote.sh --mode database can pull one dump out with
    # `tar --occurrence=1` after reading a few hundred MB instead of all 48 GB.
    # Swap these two sources and that restore silently becomes a full-archive read.
    tar cf - --warning=no-file-changed --warning=no-file-removed \
        -C "${STAGE}" "backup-${U}" \
        --transform "s,^${U}/,backup-${U}/homedir/,SH" \
        --transform "s,^${U}\$,backup-${U}/homedir,SH" \
        -C /home "${U}" \
        | ${GZIP} | rput "${REMOTE_DIR}/accounts/${U}.tar.gz"
    if pipe_status_ok "${U}.tar.gz" 1 "${PIPESTATUS[@]}"; then
        log "streamed ${U}.tar.gz"
    else
        FAILED+=("${U}:archive")
    fi

    # databases too big to spool locally — dumped straight into their own pipe
    BIGLIST="${STAGE}/backup-${U}/databases/_streamed_separately.txt"
    if [ "${DO_DB}" -eq 1 ] && [ -s "${BIGLIST}" ]; then
        rsh "mkdir -p '${REMOTE_DIR}/databases/${U}'" || FAILED+=("${U}:dbdir")
        while read -r DB; do
            [ -n "${DB}" ] || continue
            log "streaming database ${DB}"
            ${MYSQLDUMP} --opt --lock-tables=false --single-transaction "${DB}" 2>/dev/null \
                | ${GZIP} | rput "${REMOTE_DIR}/databases/${U}/${DB}.sql.gz"
            pipe_status_ok "${DB}.sql.gz" 0 "${PIPESTATUS[@]}" || FAILED+=("${U}:${DB}")
        done < "${BIGLIST}"
    fi

    # ── per-account index ─────────────────────────────────────────────────
    # ~1 KB of plain text beside the archive, listing what is IN that archive.
    # Without it the panel cannot answer "what can I restore for this account?"
    # without streaming a multi-GB archive back just to run `tar tz`. Written only
    # after a successful stream (an index must never advertise a truncated
    # archive) and before the staging tree is removed, since it reads databases/.
    if [[ " ${FAILED[*]:-} " != *" ${U}:archive "* ]]; then
        ARCH_BYTES=$(rsh "stat -c %s '${REMOTE_DIR}/accounts/${U}.tar.gz'" 2>/dev/null)
        {
            echo "user ${U}"
            echo "date ${DATE}"
            echo "domain $(${SQLITE} "${DB_FILE}" "SELECT domain FROM accounts WHERE user='${U}'" 2>/dev/null)"
            echo "archive_bytes ${ARCH_BYTES:-0}"
            # one du of the whole homedir — enough for the restore space check.
            # A du per top-level entry would re-walk the biggest homedirs a second
            # time every night, which is not worth a prettier listing.
            echo "home_kb $(du -sk "/home/${U}" 2>/dev/null | cut -f1)"
            # top-level homedir entries: what a partial restore can be offered for
            for e in "/home/${U}"/*; do
                [ -e "${e}" ] || continue
                n=$(basename "${e}")
                if   [ -L "${e}" ]; then t=link
                elif [ -d "${e}" ]; then t=dir
                else                     t=file
                fi
                echo "home ${n} ${t}"
            done
            # databases, and WHICH of the two places each dump ended up in
            if [ -d "${STAGE}/backup-${U}/databases" ]; then
                for f in "${STAGE}/backup-${U}/databases"/*.sql; do
                    [ -e "${f}" ] || continue
                    b=$(basename "${f}" .sql)
                    case "${b}" in _*) continue;; esac
                    echo "db ${b} $(( ($(stat -c %s "${f}") + 1023) / 1024 )) archive"
                done
                if [ -s "${BIGLIST}" ]; then
                    while read -r bigdb; do
                        [ -n "${bigdb}" ] || continue
                        bz=$(rsh "stat -c %s '${REMOTE_DIR}/databases/${U}/${bigdb}.sql.gz'" 2>/dev/null)
                        echo "db ${bigdb} $(( ${bz:-0} / 1024 )) remote"
                    done < "${BIGLIST}"
                fi
            fi
        } | rput "${REMOTE_DIR}/accounts/${U}.index" || log "note: could not write the index for ${U}"
    fi

    # the staging tree holds shadow/grants/dovecot hashes — do not leave it behind
    rm -rf "${STAGE}"
done

# ─── manifest + rotation ────────────────────────────────────────────────────
if [ "${DRY}" -eq 0 ]; then
    {
        echo "Reqad full remote backup"
        echo "host    : $(hostname -f 2>/dev/null || hostname)"
        echo "date    : $(date -Is)"
        echo "accounts: ${#USERS[@]}"
        echo "db-max  : ${DB_MAX_MB} MB (larger databases are under databases/<user>/)"
        echo "failed  : ${FAILED[*]:-none}"
        echo
        echo "restore an account:  reqad/scripts/restore.sh accounts/<user>.tar.gz"
        echo "restore panel state: reqad/scripts/backup_server.sh --restore system/reqad-server.tar.gz"
    } | rput "${REMOTE_DIR}/MANIFEST.txt"

    # Rotation runs only on a clean run: pruning old backups right after a run
    # that half failed is how you end up with nothing to restore from.
    if [ "${KEEP}" -gt 0 ] && [ ${#FAILED[@]} -eq 0 ]; then
        OLD=$(rsh "ls -1d '${DEST:-.}'/20*-*-* 2>/dev/null | sort | head -n -${KEEP}")
        if [ -n "${OLD}" ]; then
            log "rotating: removing $(echo "${OLD}" | wc -l) old backup(s)"
            echo "${OLD}" | while read -r d; do
                [ -n "${d}" ] && rsh "rm -rf '${d}'"
            done
        fi
    elif [ "${KEEP}" -gt 0 ]; then
        log "rotation skipped — the run had failures, old backups are kept"
    fi
fi

if [ ${#FAILED[@]} -gt 0 ]; then
    log "DONE with ${#FAILED[@]} failure(s): ${FAILED[*]}"
    notify "${#FAILED[@]} failure(s)" "Remote backup to ${SSH_HOST}:${REMOTE_DIR} finished with failures:

${FAILED[*]}

See ${REQAD}/log/backup_remote.log on ${HOST_SHORT}."
    exit 1
fi

log "DONE — ${#USERS[@]} account(s) → ${SSH_USER}@${SSH_HOST}:${REMOTE_DIR}"
exit 0
