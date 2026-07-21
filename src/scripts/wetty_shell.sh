#!/bin/bash
#
# wetty entrypoint. The panel picks the target user before opening the iframe
# and hands it off through /run/reqad/terminal-target; consume it here.
# No valid target means no shell — do not silently fall back to root.

user=$(/usr/local/reqad/scripts/terminal_target.sh get 2>/dev/null)

if [ -z "$user" ]; then
	echo "No terminal session was requested, or it has expired."
	echo "Open the terminal again from the Reqad panel."
	sleep 5
	exit 1
fi

if [ "$user" = "root" ]; then
	cd && exec bash
fi

# hosting accounts often have /sbin/nologin as their shell
exec su -s /bin/bash - "$user"
