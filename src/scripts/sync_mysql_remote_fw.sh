#!/bin/bash
#
# sync_mysql_remote_fw.sh — keep csf's MySQL allow rules in step with MariaDB.
#
# The Databases page grants remote access by creating `user`@`<ip>` accounts.
# Those are useless while the firewall still drops the MySQL port, so this
# helper derives one csf.allow rule per remote IP straight from MariaDB:
#
#   tcp|in|d=3306|s=203.0.113.7 # reqad-mysql-remote
#
# It is a full sync, not an add/remove: every tagged line is rewritten from the
# current account list, so a failed or interrupted panel action can never leave
# a stale opening behind — the next run corrects it. Lines without the tag are
# never touched. Only literal IPv4/IPv6 hosts become rules; wildcard (%) and
# hostname accounts created outside the panel are ignored.
#
# Prints one status word: nocsf | unchanged | updated | error
#
# Run as root (the panel calls it via sudo).

CSF_ALLOW=/etc/csf/csf.allow
TAG='# reqad-mysql-remote'

if [ "$EUID" -ne 0 ]; then echo "error"; echo "must run as root" >&2; exit 1; fi

if [ ! -f "$CSF_ALLOW" ] || ! command -v csf >/dev/null 2>&1; then
	echo "nocsf"
	exit 0
fi

port=$(mysql -NB -e 'SELECT @@port' 2>/dev/null)
if ! [[ "$port" =~ ^[0-9]{1,5}$ ]]; then
	echo "error"; echo "cannot read MariaDB port" >&2; exit 1
fi

hosts=$(mysql -NB -e "SELECT DISTINCT Host FROM mysql.user
	WHERE Host NOT IN ('localhost','127.0.0.1','::1') ORDER BY Host" 2>/dev/null)
if [ $? -ne 0 ]; then
	echo "error"; echo "cannot read MariaDB accounts" >&2; exit 1
fi

valid_ipv4() {
	[[ "$1" =~ ^([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})$ ]] || return 1
	local o
	for o in "${BASH_REMATCH[@]:1}"; do (( 10#$o <= 255 )) || return 1; done
	return 0
}
valid_ipv6() {
	[[ "$1" == *:* && "$1" =~ ^[0-9A-Fa-f:.]{2,45}$ ]]
}

tmp=$(mktemp) || { echo "error"; exit 1; }
trap 'rm -f "$tmp"' EXIT

grep -vF "$TAG" "$CSF_ALLOW" > "$tmp"
while IFS= read -r h; do
	[ -z "$h" ] && continue
	if valid_ipv4 "$h" || valid_ipv6 "$h"; then
		echo "tcp|in|d=${port}|s=${h} ${TAG}" >> "$tmp"
	fi
done <<< "$hosts"

if cmp -s "$tmp" "$CSF_ALLOW"; then
	echo "unchanged"
	exit 0
fi

# Write in place so csf.allow keeps its owner, mode and inode.
cat "$tmp" > "$CSF_ALLOW"
if csf -r >/dev/null 2>&1; then
	echo "updated"
else
	echo "error"; echo "csf -r failed" >&2; exit 1
fi
