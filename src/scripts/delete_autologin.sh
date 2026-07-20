#!/bin/bash
if id "$1" >/dev/null 2>&1; then
	echo "[delete_autologin.sh] user: $1i file: /home/$1/public_html/wp-content/mu-plugins/autologin.php" >> /usr/local/reqad/log/debug_log
	sleep 10 && sudo /bin/rm -f "/home/$1/public_html/wp-content/mu-plugins/autologin.php" && sudo /usr/bin/rmdir "/home/$1/public_html/wp-content/mu-plugins/"
else
	echo "[delete_autologin.sh] error: user not found" >> /usr/local/reqad/log/debug_log
fi
