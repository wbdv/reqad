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
	# systemd gives the unit no HOME, so a bare "cd" fails with "HOME not set"
	# and bash would also skip ~/.bashrc. Take it from passwd and export it.
	# -l for parity with the su - below: both branches get a login shell.
	HOME=$(getent passwd root | cut -d: -f6)
	export HOME="${HOME:-/root}"
	cd "$HOME" || cd /
	exec bash -l
fi

# hosting accounts often have /sbin/nologin as their shell
exec su -s /bin/bash - "$user"
