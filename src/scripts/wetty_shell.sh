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

# Shell access (Accounts > Manage): jailed accounts get their sandbox, SFTP-only
# accounts no terminal. Anything else gets bash - hosting accounts often have
# /sbin/nologin as their shell - unless full shell access is off on this
# server (root_access=0): then the sandbox.
login_shell=$(getent passwd "$user" | cut -d: -f7)
root_access=$(grep -E '^[[:space:]]*root_access[[:space:]]*=' /usr/local/reqad/etc/server-software.ini 2>/dev/null | tail -1 | cut -d= -f2 | tr -d '[:space:]')
if [ -n "$root_access" ] && [ "$root_access" != 1 ] && [ "$login_shell" != /usr/local/bin/reqad-sftponly ]; then
	exec su -s /usr/local/bin/reqad-jailshell - "$user"
fi
case "$login_shell" in
	/usr/local/bin/reqad-jailshell)
		exec su -s /usr/local/bin/reqad-jailshell - "$user" ;;
	/usr/local/bin/reqad-sftponly)
		echo "$user has no shell access (SFTP only), so no terminal."
		echo "Change it in Accounts > Manage > Shell access."
		sleep 8
		exit 1 ;;
esac
exec su -s /bin/bash - "$user"
