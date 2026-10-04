#!/bin/bash
# auto_update.sh — unattended nightly update of the Reqad packages.
#
# Scheduled from Settings -> Automatic updates as an /etc/crontab line (see
# public_html/modules/auto_update_cron.php), so the entry is also visible and
# removable on the Cron page. Only packages named reqad* are updated — the
# panel and its installed add-ons; dnf still pulls in whatever newer
# dependencies those require, and leaves every other package alone.
#
# Each run is bracketed by
#   === <date> start
#   === <date> end rc=<dnf exit code> updated=<comma-separated NEVRAs or empty>
# which auto_update_status() in functions.php parses for the "Last run" row.
# Keep that format.

LOG=/usr/local/reqad/log/auto_update.log
LOCK=/run/reqad_auto_update.lock

# one run is a few KB; trim instead of keeping years of "Nothing to do."
if [ -f "$LOG" ] && [ "$(wc -l < "$LOG")" -gt 5000 ]; then
	tail -n 2000 "$LOG" > "$LOG.tmp" && mv -f "$LOG.tmp" "$LOG"
fi

exec >> "$LOG" 2>&1

# a slow mirror can make a run outlast the next one, or it can meet a dnf
# started by hand; dnf has its own lock, this one just keeps the log readable
exec 9> "$LOCK"
if ! flock -n 9; then
	echo "=== $(date '+%F %T') skipped: a previous run is still active"
	exit 0
fi

echo "=== $(date '+%F %T') start"
before=$(rpm -qa 'reqad*' | sort)
# quoted: cron runs this from /root, and an unquoted reqad* would glob
# against whatever files happen to be there
/usr/bin/dnf --refresh -y update 'reqad*'
rc=$?
after=$(rpm -qa 'reqad*' | sort)
updated=$(comm -13 <(echo "$before") <(echo "$after") | paste -sd, -)
echo "=== $(date '+%F %T') end rc=$rc updated=$updated"
exit $rc
