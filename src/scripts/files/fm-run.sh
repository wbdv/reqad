#!/bin/bash
# Reqad file manager: run fm-helper.php AS a hosting account, never as root.
#
#   sudo -n /usr/local/reqad/scripts/files/fm-run.sh <user> [<seconds>]
#
# Called by app/functions/files.php (2087, the API, the account panel's
# broker) with the request on stdin (see fm-helper.php). Everything the
# helper does to files happens with the account's own uid, gid and groups,
# so the kernel's permissions are the boundary: it can reach what the
# account's own website code and SSH login can reach, and nothing else.
#
# Only <user> comes from the caller, and it must be a regular login of this
# server (uid >= 1000) whose home is /home/<user>, owned by it. The helper is
# handed over as an open file (fd 3): the account cannot read /usr/local/reqad
# itself, and does not need to.
#
# <seconds>: how long it may run (default 290, at most 21600: 3600 for uploads and
# downloads of big files, 21600 for restoring from a backup). Exit 2 = refused here;
# otherwise the helper's own.

set -u
export LC_ALL=C

user="${1:-}"
secs="${2:-290}"
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
helper="$here/fm-helper.php"
php=/opt/reqad/php-current/usr/bin/php

die() { echo "fm-run: $*" >&2; exit 2; }

[[ "$user" =~ ^[a-z][a-z0-9]{0,31}$ ]] || die "not a valid account name"
[[ "$secs" =~ ^[0-9]{1,5}$ ]] && [ "$secs" -ge 5 ] && [ "$secs" -le 21600 ] || die "bad time limit"
[ "$EUID" = 0 ] || die "must run as root (through sudo)"

# /etc/passwd read by bash itself (no extra programs: this runs on every
# click in the file manager); the helper checks the home folder's owner
uid=''
while IFS=: read -r name _x u g _gecos h _shell; do
	if [ "$name" = "$user" ]; then uid=$u; gid=$g; home=$h; break; fi
done </etc/passwd
[ -n "$uid" ] || die "no such user"
[ "$uid" -ge 1000 ] && [ "$uid" -ne 65534 ] || die "not a hosting account"
[ "$home" = "/home/$user" ] || die "unexpected home folder"
[ -d "$home" ] && [ ! -L "$home" ] || die "home folder missing"
[ -f "$helper" ] && [ -x "$php" ] || die "helper missing"

cd "$home" || die "cannot enter the home folder"
# --no-new-privs: nothing the helper starts (zip, tar, cp, rm) can gain
# rights through a setuid program; env -i: none of root's environment.
exec 3<"$helper"
exec /usr/bin/setpriv --reuid="$uid" --regid="$gid" --init-groups --no-new-privs -- \
	/usr/bin/env -i PATH=/usr/bin:/bin HOME="$home" USER="$user" LANG=C.UTF-8 \
	/usr/bin/timeout -k 5 "$secs" \
	"$php" -n -d memory_limit=256M -d display_errors=stderr -d log_errors=0 /dev/fd/3 "$home"
