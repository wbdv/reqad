#!/bin/bash
#
# cron_helper.sh - an account's crontab, for app/functions/cron.php (the
# account panel's Cron jobs, the API). Root (via sudo -n); the jobs
# themselves only ever run as the account.
#
#   cron_helper.sh get <user>          "sha1 <hash>" (or "sha1 none" when the
#                                      account has no crontab), then the file
#   cron_helper.sh put <user> <hash>   replace the crontab with stdin, but only
#                                      while it still has <hash> ("none": there
#                                      was none) - exit 5 when someone changed
#                                      it meanwhile. Owner and 0600 kept,
#                                      SHELL= kept in step with the account's
#                                      shell (account_shell.sh cron-sync), the
#                                      run wrapper installed
#   cron_helper.sh run <user> <id>     start job <id> now (its line in the
#                                      crontab, paused or not), as the account,
#                                      with the crontab's SHELL= like crond:
#                                      a transient unit reqad-cron-<user>-<id>
#                                      in the account's user slice
#   cron_helper.sh install             install /usr/local/bin/reqad-cron
#
# Exits: 0 ok, 2 bad use, 3 job not found, 4 failed, 5 changed meanwhile,
# 6 the job is running already.

set -u
export LC_ALL=C
SRC=/usr/local/reqad/bin/reqad-cron
WRAP=/usr/local/bin/reqad-cron
SPOOL=/var/spool/cron
JAILS=/usr/local/reqad/scripts/account_shell.sh

die() { echo "cron_helper: $2" >&2; exit "$1"; }
[ "$(id -u)" = 0 ] || die 2 "run as root"

install_wrapper() {
	cmp -s "$SRC" "$WRAP" && return 0
	install -o root -g root -m 0755 "$SRC" "$WRAP.new" && mv -f "$WRAP.new" "$WRAP"
}

# a hosting account: a valid name, uid 1000 or more, home /home/<user>
acct() {
	[[ "$1" =~ ^[a-z][a-z0-9]{0,31}$ ]] || die 2 "not a valid account name"
	local line
	line=$(getent passwd "$1") || die 2 "no such account"
	IFS=: read -r _n _x UID_ GID_ _g HOME_ SHELL_ <<< "$line"
	[ "$UID_" -ge 1000 ] && [ "$UID_" -ne 65534 ] || die 2 "not a hosting account"
	[ "$HOME_" = "/home/$1" ] || die 2 "unexpected home folder"
}

case "${1:-}" in
get)
	acct "${2:-}"
	f=$SPOOL/$2
	if [ -f "$f" ] && [ ! -L "$f" ]; then
		echo "sha1 $(sha1sum < "$f" | cut -c1-40)"
		cat "$f"
	else
		echo "sha1 none"
	fi
	;;
put)
	acct "${2:-}"
	want=${3:-}
	[[ "$want" =~ ^([0-9a-f]{40}|none)$ ]] || die 2 "usage: $0 put <user> <hash>"
	f=$SPOOL/$2
	[ -L "$f" ] && die 4 "the crontab is a symlink"
	install_wrapper || die 4 "could not install $WRAP"
	tmp=$(mktemp "$SPOOL/.reqad-$2.XXXXXX") || die 4 "no scratch file"
	trap 'rm -f "$tmp"' EXIT
	head -c 262144 > "$tmp"
	grep -q $'\r' "$tmp" && die 2 "carriage returns are not allowed"
	exec 7>>/var/lib/reqad/.cron-$2.lock
	flock 7
	if [ -f "$f" ]; then have=$(sha1sum < "$f" | cut -c1-40); else have=none; fi
	[ "$have" = "$want" ] || die 5 "the crontab was changed meanwhile"
	chown "$UID_:$GID_" "$tmp" && chmod 0600 "$tmp" || die 4 "could not set the owner"
	mv -f "$tmp" "$f" || die 4 "could not write the crontab"
	trap - EXIT
	command -v restorecon >/dev/null 2>&1 && restorecon "$f" >/dev/null 2>&1
	[ -x "$JAILS" ] && "$JAILS" cron-sync "$2" >/dev/null 2>&1
	touch "$SPOOL"
	;;
run)
	acct "${2:-}"
	id=${3:-}
	[[ "$id" =~ ^[a-z0-9]{8}$ ]] || die 2 "usage: $0 run <user> <id>"
	f=$SPOOL/$2
	[ -f "$f" ] && [ ! -L "$f" ] || die 3 "no crontab"
	install_wrapper || die 4 "could not install $WRAP"
	# the job's line: 5 schedule fields, then the wrapper with this id
	line=$(sed -E 's/^#reqad-off //' "$f" | grep -E "^[^#[:space:]]+([[:space:]]+[^[:space:]]+){4}[[:space:]]+$WRAP $id " | head -1)
	[ -n "$line" ] || die 3 "no such job"
	cmd=$(sed -E 's/^[^[:space:]]+([[:space:]]+[^[:space:]]+){4}[[:space:]]+//' <<< "$line")
	cmd=${cmd//\\%/%}
	sh=$(grep -E '^[[:space:]]*SHELL[[:space:]]*=' "$f" | tail -1 | sed -E 's/^[[:space:]]*SHELL[[:space:]]*=[[:space:]]*//')
	[ -n "$sh" ] || sh=/bin/sh
	grep -qx "$sh" /etc/shells || [ "$sh" = /bin/sh ] || die 4 "unknown shell $sh"
	unit=reqad-cron-$2-$id
	systemctl is-active --quiet "$unit" && die 6 "it is running already"
	systemctl reset-failed "$unit" >/dev/null 2>&1
	systemd-run --quiet --no-block --collect --unit="$unit" --uid="$UID_" --gid="$GID_" \
		--slice="user-$UID_.slice" -p WorkingDirectory="$HOME_" \
		--setenv=HOME="$HOME_" --setenv=USER="$2" --setenv=LOGNAME="$2" --setenv=SHELL="$sh" \
		--setenv=PATH=/usr/bin:/bin --setenv=LANG=C.UTF-8 \
		-- "$sh" -c "$cmd" || die 4 "it could not be started"
	;;
install)
	install_wrapper || die 4 "could not install $WRAP"
	;;
*)
	die 2 "usage: $0 get|put|run|install ..."
	;;
esac
exit 0
