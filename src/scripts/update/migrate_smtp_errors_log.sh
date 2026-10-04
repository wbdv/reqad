#!/bin/bash
#
# migrate_smtp_errors_log.sh -- move the `errors` table into log/smtp_errors.log
#
# SMTP delivery failures were logged into an `errors` table in the panel
# database. They are a log, not app state, so they now live in a log file and
# db/1034.sql drops the table. This runs first and rescues whatever the table
# still holds -- some of those rows are older than the exim main.log the
# collector rescans, so they cannot be re-derived once the table is gone.
#
# The old rows are merged with anything already collected and the result is
# sorted by date, because the log file is oldest-first and the collector's
# dedup takes the newest line as its high-water mark: appending old rows to
# the end would stall it.
#
# Only rows whose recipient is address-shaped are carried over. The retired
# collector built each row with a two-stage awk split that lost the field it
# was aiming for on any line it did not expect, so most of the table is rows
# recording a recipient of "error", "from" or "to" -- misparses, with the
# reason column holding a fragment of a log line. Importing those would just
# move the noise; the rewritten parser cannot produce them.
#
# Safe to re-run: it does nothing once the table is gone, and nothing on an
# install that never had one.
#
# Reqad -- https://www.reqad.com/

set -u
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
export PATH

REQAD=/usr/local/reqad
DB="$REQAD/db/reqad.db"
LOG="$REQAD/log/smtp_errors.log"
SQLITE=/usr/bin/sqlite3

[ -r "$DB" ]     || { echo "migrate_smtp_errors_log: no panel database, skipping"; exit 0; }
[ -x "$SQLITE" ] || { echo "migrate_smtp_errors_log: $SQLITE missing" >&2; exit 1; }

have=$("$SQLITE" -init /dev/null -batch -noheader -list "$DB" \
       "SELECT count(*) FROM sqlite_master WHERE type='table' AND name='errors';" 2>/dev/null)
if [ "${have:-0}" = "0" ]; then
	echo "migrate_smtp_errors_log: no errors table, nothing to migrate"
	exit 0
fi

tmp=$(mktemp "${LOG}.migrate.XXXXXX") || exit 1
trap 'rm -f "$tmp"' EXIT

# `|` is the field separator and the message is the last field, so only the
# first two columns need cleaning. Empty rows are dropped rather than logged.
"$SQLITE" -init /dev/null -batch -noheader -list -separator '|' "$DB" \
	"SELECT date, replace(email,'|',' '), replace(replace(errmsg, char(13), ' '), char(10), ' ')
	   FROM errors
	  WHERE date <> '' AND errmsg <> ''
	    AND email LIKE '%_@_%._%' AND email NOT LIKE '% %'
	  ORDER BY date;" >> "$tmp" 2>/dev/null

rows=$(wc -l < "$tmp")
[ -f "$LOG" ] && cat "$LOG" >> "$tmp"

# stable sort on the timestamp only: two failures in the same second keep the
# order they were written in, and -u collapses rows the collector already has
sort -u -o "$tmp" "$tmp"
sort -s -t'|' -k1,1 -o "$tmp" "$tmp"

mv -f "$tmp" "$LOG"
trap - EXIT
chmod 644 "$LOG"
chown reqad:reqad "$LOG" 2>/dev/null

echo "migrate_smtp_errors_log: moved $rows deliverable-address rows into $LOG"
