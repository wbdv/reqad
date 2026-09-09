#!/bin/bash
#
# cron_edit.sh — replace or delete a single line in a crontab file.
#
#   cron_edit.sh <cron_file> <old_line> [new_line]
#
# With <new_line>, the matching line is replaced in place (keeping its position
# in the file).  Without it, the line is deleted.
#
# Matching is whitespace-normalised: runs of spaces/tabs collapse to one space
# and the line is trimmed before comparing, because the panel rebuilds the line
# from parsed fields while the file may use padding (" 0  8  *  *  * cmd").
# Only the FIRST match is touched, so duplicate entries are not all removed.
#
# Ownership and mode of the target file are preserved (crontabs in
# /var/spool/cron are 0600 and owned by their user) and the swap is atomic.
#
# Exits: 0 ok, 2 bad usage/path, 3 line not found, 4 write failed.

set -u

CRON_FILE="${1:-}"
OLD_LINE="${2:-}"
NEW_LINE="${3-}"

if [ -z "$CRON_FILE" ] || [ -z "$OLD_LINE" ]; then
	echo "usage: $0 <cron_file> <old_line> [new_line]" >&2
	exit 2
fi

# Only /etc/crontab or a user spool file — no traversal, no arbitrary paths.
case "$CRON_FILE" in
	/etc/crontab) ;;
	/var/spool/cron/*)
		user="${CRON_FILE#/var/spool/cron/}"
		if ! [[ "$user" =~ ^[a-zA-Z0-9._-]+$ ]]; then
			echo "Error: invalid crontab user." >&2
			exit 2
		fi
		;;
	*)
		echo "Error: refusing to edit $CRON_FILE" >&2
		exit 2
		;;
esac

if [ ! -f "$CRON_FILE" ]; then
	echo "Error: $CRON_FILE does not exist." >&2
	exit 3
fi

# Scratch file lives in a root-only directory — /tmp is writable by every
# hosting account, and a predictable name there is a symlink attack.
TMPDIR_SAFE=/var/lib/reqad
mkdir -p "$TMPDIR_SAFE" && chmod 0700 "$TMPDIR_SAFE" 2>/dev/null
TMPFILE=$(mktemp "$TMPDIR_SAFE/crontab.XXXXXX") || exit 4
trap 'rm -f "$TMPFILE"' EXIT

awk -v old="$OLD_LINE" -v new="$NEW_LINE" '
	function norm(s) {
		gsub(/[ \t]+/, " ", s)
		sub(/^ /, "", s)
		sub(/ $/, "", s)
		return s
	}
	{
		if (!done && norm($0) == norm(old)) {
			done = 1
			if (new != "") print new
			next
		}
		print
	}
	END { if (!done) exit 3 }
' "$CRON_FILE" > "$TMPFILE"
rc=$?

if [ $rc -eq 3 ]; then
	echo "Error: cron entry not found in $CRON_FILE." >&2
	exit 3
elif [ $rc -ne 0 ]; then
	echo "Error: failed to rewrite $CRON_FILE." >&2
	exit 4
fi

chown --reference="$CRON_FILE" "$TMPFILE" 2>/dev/null
chmod --reference="$CRON_FILE" "$TMPFILE" 2>/dev/null
mv -f "$TMPFILE" "$CRON_FILE" || exit 4
trap - EXIT
command -v restorecon >/dev/null 2>&1 && restorecon "$CRON_FILE" >/dev/null 2>&1

exit 0
