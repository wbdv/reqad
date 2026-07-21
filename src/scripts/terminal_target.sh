#!/bin/bash
#
# terminal_target.sh — hand off the terminal target user to wetty.
#
# The panel cannot tell wetty which user to open a shell for: wetty is started
# with a fixed "-c scripts/wetty_shell.sh" command and gets no per-request
# context. So the panel calls this helper (via sudo) to drop a one-shot target
# file that wetty_shell.sh reads and consumes on the next connection.
#
#   terminal_target.sh set <user>   validate <user> and write the target file
#   terminal_target.sh get          print the target user, then consume the file
#
# The user is validated here, not in PHP, so a stale/forged file can never open
# a shell for an account Reqad does not manage. root is only accepted when
# root_access=1 in server-software.ini.

_PATH=/usr/local/reqad
TARGET_DIR=/run/reqad
TARGET_FILE="$TARGET_DIR/terminal-target"
TTL=60

action="$1"
user="$2"

case "$action" in
	set)
		# usernames are unix accounts — no shell metacharacters allowed
		if ! [[ "$user" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]]; then
			echo "Error: invalid user" >&2
			exit 1
		fi

		if [ "$user" = "root" ]; then
			root_access=$(grep -E '^root_access=' "$_PATH/etc/server-software.ini" | head -1 | cut -d= -f2 | tr -d '[:space:]')
			# root_access defaults to on when the flag is absent
			if [ -n "$root_access" ] && [ "$root_access" != "1" ]; then
				echo "Error: root access is disabled" >&2
				exit 1
			fi
		else
			found=$(sqlite3 -batch -noheader -list "$_PATH/db/reqad.db" \
				"SELECT user FROM accounts WHERE user='$user';" 2>/dev/null)
			if [ "$found" != "$user" ]; then
				echo "Error: no such account" >&2
				exit 1
			fi
		fi

		if ! id "$user" >/dev/null 2>&1; then
			echo "Error: no such system user" >&2
			exit 1
		fi

		mkdir -p "$TARGET_DIR"
		chmod 700 "$TARGET_DIR"
		umask 077
		echo "$user" > "$TARGET_FILE"
		chmod 600 "$TARGET_FILE"
		echo "OK"
		;;

	get)
		[ -f "$TARGET_FILE" ] || exit 1
		# a target older than the TTL is a leftover, not this click
		age=$(( $(date +%s) - $(stat -c %Y "$TARGET_FILE") ))
		user=$(cat "$TARGET_FILE")
		rm -f "$TARGET_FILE"
		[ "$age" -gt "$TTL" ] && exit 1
		[[ "$user" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]] || exit 1
		echo "$user"
		;;

	*)
		echo "Usage: $0 set <user> | get" >&2
		exit 1
		;;
esac
