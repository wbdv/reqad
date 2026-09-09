#!/bin/bash
# Back up the SERVER-level state that per-account backups deliberately leave out.
#
# backup.sh is per account: it captures a mailbox's own Sieve script (it lives
# inside the maildir) and the domain filter rows for that account's domain. What
# it does NOT capture, because it belongs to no single account:
#
#   * the panel database  — every email_filters row (global AND every domain),
#     accounts, settings, autoresponders
#   * /var/lib/reqad/sieve — the global filter script, the per-domain scripts,
#     the rendered autoresponder scripts, and the pipe programs in sieve/bin
#   * etc/server-software.ini — feature flags, php versions, service list
#
# Rebuild a box without this and the domain filters only come back if somebody
# restores every account, and the global tier does not come back at all.
#
# INCLUDES public_html/defines.php — the panel's own config, and the one file an
# RPM update never rewrites. It carries live credentials (backup server, DNS API
# tokens), so the archive is created mode 0600 and is worth treating like a
# password store: it is downloadable from the panel's Backup page by anyone who
# can reach the panel, and every copy of it outlives a credential rotation.
#
# Usage:
#   backup_server.sh                 create ~/backup/backup_server_<date>.tar.gz
#   backup_server.sh --restore FILE  merge an archive back into this server
#   backup_server.sh --restore FILE --dry-run    show what would change
#
# The restore MERGES rather than overwrites: filter rows are replayed with
# INSERT OR IGNORE and the scripts are reinstalled through ef-helper, which
# recompiles them at their final path. The database itself is only ever placed
# NEXT to the live one — swapping a running panel's db is the admin's call.

set -u
export LC_ALL=C

REQAD=/usr/local/reqad
DB_FILE="$REQAD/db/reqad.db"
SIEVE_DIR=/var/lib/reqad/sieve
INI="$REQAD/etc/server-software.ini"
DEFINES="$REQAD/public_html/defines.php"
EF_HELPER="$REQAD/scripts/email-filters/ef-helper.sh"
SQLITE="/usr/bin/sqlite3 -init /dev/null -batch -noheader -list"
DATE=$(date +%Y-%m-%d_%H%M)
OUTDIR=~/backup

die() { echo "ERROR: $*" >&2; exit 1; }

MODE=create
ARCHIVE=""
DRY=0
while [ $# -gt 0 ]; do
    case "$1" in
        --restore) MODE=restore; ARCHIVE="${2:-}"; shift 2 || true ;;
        --dry-run) DRY=1; shift ;;
        -h|--help) sed -n '2,30p' "$0"; exit 0 ;;
        *) die "unknown option: $1" ;;
    esac
done

# ─── create ──────────────────────────────────────────────────────────────────
if [ "$MODE" = create ]; then
    [ -f "$DB_FILE" ] || die "no panel database at $DB_FILE"
    mkdir -p "$OUTDIR"
    BUILD=$(mktemp -d "$OUTDIR/.build_server_${DATE}.XXXXXX") || die "cannot create temp dir"
    ROOT="$BUILD/reqad-server"
    # NOT "backup-<user>": restore.sh keys off that prefix, and this archive is
    # not an account. It refuses this one with a clear message instead.
    mkdir -p "$ROOT/db" "$ROOT/sieve"

    # .backup takes a consistent snapshot of a live database; cp does not.
    /usr/bin/sqlite3 -init /dev/null "$DB_FILE" ".backup '$ROOT/db/reqad.db'" \
        || die "could not snapshot $DB_FILE"

    # Replayable INSERTs, so a restore can merge into a live db instead of
    # replacing it. quote() keeps the JSON in conditions/actions verbatim.
    ${SQLITE} "$DB_FILE" "SELECT 'INSERT OR IGNORE INTO email_filters (scope,target,name,enabled,priority,match_type,conditions,actions,stop) VALUES ('||quote(scope)||','||quote(target)||','||quote(name)||','||enabled||','||priority||','||quote(match_type)||','||quote(conditions)||','||quote(actions)||','||stop||');' FROM email_filters" \
        > "$ROOT/db/email_filters.sql" 2>/dev/null
    [ -s "$ROOT/db/email_filters.sql" ] || rm -f "$ROOT/db/email_filters.sql"

    # Sieve sources and pipe programs. .svbin is skipped on purpose: a compiled
    # binary records the path it was built from, so a copied one is permanently
    # stale — the restore recompiles instead.
    if [ -d "$SIEVE_DIR" ]; then
        sudo tar cf - --exclude='*.svbin' -C "$(dirname "$SIEVE_DIR")" "$(basename "$SIEVE_DIR")" \
            | tar xf - -C "$ROOT/sieve" --strip-components=1 2>/dev/null
    fi

    [ -f "$INI" ] && cp -p "$INI" "$ROOT/server-software.ini"
    [ -f "$DEFINES" ] && cp -p "$DEFINES" "$ROOT/defines.php"

    filters=$(${SQLITE} "$DB_FILE" "SELECT count(*) FROM email_filters" 2>/dev/null || echo 0)
    progs=$(ls -1 "$ROOT/sieve/bin" 2>/dev/null | wc -l)
    cat > "$ROOT/MANIFEST" <<MAN
Reqad server-level backup
created : $(date -Is)
host    : $(hostname -f 2>/dev/null || hostname)

contents
  db/reqad.db           panel database snapshot (sqlite .backup)
  db/email_filters.sql  $filters filter row(s), replayable with INSERT OR IGNORE
  sieve/                global + per-domain + autoresponder Sieve SOURCES
  sieve/bin/            $progs pipe program(s)
  server-software.ini   feature flags
  defines.php           panel config INCLUDING LIVE CREDENTIALS

This archive contains credentials (backup server, DNS API tokens). It is created
mode 0600 — keep it that way, and treat copies of it like a password store.

restore:  $REQAD/scripts/backup_server.sh --restore <this file>
MAN

    OUT="$OUTDIR/backup_server_${DATE}.tar.gz"
    sudo tar czf "$OUT" -C "$BUILD" reqad-server || die "could not create $OUT"
    sudo chown reqad:reqad "$OUT" 2>/dev/null || true
    # It carries defines.php, so it carries credentials.
    chmod 600 "$OUT"
    sudo rm -rf "$BUILD"
    echo "Created $OUT"
    echo "  $filters filter row(s), $progs pipe program(s)"
    exit 0
fi

# ─── restore ─────────────────────────────────────────────────────────────────
[ -n "$ARCHIVE" ] || die "--restore needs an archive"
[ -f "$ARCHIVE" ] || die "archive '$ARCHIVE' not found"
ROOTDIR=$(tar tzf "$ARCHIVE" 2>/dev/null | head -1 | cut -d/ -f1)
[ "$ROOTDIR" = "reqad-server" ] \
    || die "'$ARCHIVE' is not a server backup (root folder is '$ROOTDIR'). Account archives are restored with restore.sh."

STAGE=$(mktemp -d "${TMPDIR:-/var/tmp}/reqad-server-restore.XXXXXX") || die "cannot create temp dir"
trap 'rm -rf "$STAGE"' EXIT
tar xzf "$ARCHIVE" -C "$STAGE" || die "failed to extract archive"
R="$STAGE/reqad-server"
[ -f "$R/MANIFEST" ] && sed 's/^/  /' "$R/MANIFEST"

if [ "$DRY" -eq 1 ]; then
    echo
    echo "DRY RUN — nothing written. Would:"
    [ -s "$R/db/email_filters.sql" ] && echo "  * replay $(grep -c INSERT "$R/db/email_filters.sql") filter row(s) (INSERT OR IGNORE)"
    if [ -f "$R/defines.php" ]; then
        if [ -f "$DEFINES" ]; then echo "  * place defines.php NEXT to the existing one (never over it)"
        else echo "  * restore defines.php (this box has none)"; fi
    fi
    [ -f "$R/sieve/reqad-global.sieve" ] && echo "  * reinstall the global filter script"
    for f in "$R"/sieve/domains/*.sieve; do
        [ -e "$f" ] || continue
        echo "  * reinstall the domain script for $(basename "${f%.sieve}")"
    done
    n=$(ls -1 "$R/sieve/bin" 2>/dev/null | wc -l)
    [ "$n" -gt 0 ] && echo "  * install $n pipe program(s) into $SIEVE_DIR/bin"
    echo "  * copy the database snapshot NEXT to the live one (never over it)"
    exit 0
fi

[ "$(id -u)" -eq 0 ] || die "restore must run as root"

# 1. filter rows — merged, never replacing what is already there
if [ -s "$R/db/email_filters.sql" ]; then
    /usr/bin/sqlite3 -init /dev/null "$DB_FILE" < "$R/db/email_filters.sql" 2>/dev/null \
        && echo "restored filter rows"
fi

# 2. scripts — installed through ef-helper so each is compiled AT its final path
if [ -s "$R/sieve/reqad-global.sieve" ]; then
    if sudo "$EF_HELPER" system-put global < "$R/sieve/reqad-global.sieve" >/dev/null 2>&1; then
        echo "restored the global filter script"
    else
        echo "WARNING: the global script did not compile — rows are restored, no script is active."
    fi
fi
for f in "$R"/sieve/domains/*.sieve; do
    [ -e "$f" ] || continue
    d=$(basename "${f%.sieve}")
    if sudo "$EF_HELPER" system-put domain "$d" < "$f" >/dev/null 2>&1; then
        echo "restored the domain script for $d"
    else
        echo "WARNING: the domain script for $d did not compile."
    fi
done

# 3. pipe programs — executables, so modes matter
if [ -d "$R/sieve/bin" ] && [ -n "$(ls -A "$R/sieve/bin" 2>/dev/null)" ]; then
    mkdir -p "$SIEVE_DIR/bin"
    cp -p "$R/sieve/bin/." "$SIEVE_DIR/bin/" -r
    chown -R dovecot:dovecot "$SIEVE_DIR/bin"
    chmod 755 "$SIEVE_DIR/bin"
    echo "restored $(ls -1 "$SIEVE_DIR/bin" | wc -l) pipe program(s)"
fi

# 4. autoresponder scripts are RENDERED from the autoresponders table, so the
#    reconciler rebuilds them for whichever backend this machine runs.
[ -x "$REQAD/scripts/manage_autoresponders.php" ] && "$REQAD/scripts/manage_autoresponders.php" >/dev/null 2>&1

# 5. defines.php — restored only onto a box that has none (a rebuild); where one
#    already exists it is placed alongside, because the live file is the working
#    panel's credentials and this archive's may be older.
if [ -f "$R/defines.php" ]; then
    if [ ! -f "$DEFINES" ]; then
        cp -p "$R/defines.php" "$DEFINES"
        chown reqad:reqad "$DEFINES" 2>/dev/null || true
        chmod 640 "$DEFINES"
        echo "restored defines.php (none was present)"
    else
        cp -p "$R/defines.php" "$DEFINES.from-backup-$(date +%Y%m%d-%H%M%S)"
        echo "defines.php already exists — the backup copy was placed next to it, not swapped in"
    fi
fi

# 6. the database snapshot is placed next to the live one, never over it
if [ -f "$R/db/reqad.db" ]; then
    cp -p "$R/db/reqad.db" "$REQAD/db/reqad.db.from-backup-$(date +%Y%m%d-%H%M%S)"
    echo "database snapshot copied next to the live one (not swapped in)"
fi

echo "Done."
