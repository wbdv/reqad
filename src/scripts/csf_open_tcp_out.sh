#!/bin/bash
#
# csf_open_tcp_out.sh — make sure csf lets an outgoing TCP port through.
#
# The remote backup talks to its server over ssh, often on a custom port. csf
# drops outbound traffic to ports missing from TCP_OUT, and that failure looks
# exactly like a backup server that is down (the connection just hangs), so the
# port is opened here instead of left for the admin to discover.
#
# Usage: csf_open_tcp_out.sh <port>
#
# The port is appended to TCP_OUT, and to TCP6_OUT when csf runs with IPV6 on
# (the backup host may well resolve to an IPv6 address). A port already covered
# — listed, or inside a range like 1000:2000 — is left alone. Nothing else in
# csf.conf is touched.
#
# Prints one status word: nocsf | unchanged | updated | error
#
# Run as root (the panel calls it via sudo).

CSF_CONF=/etc/csf/csf.conf

if [ "$EUID" -ne 0 ]; then echo "error"; echo "must run as root" >&2; exit 1; fi

port="${1:-}"
if ! [[ "$port" =~ ^[0-9]{1,5}$ ]] || (( 10#$port < 1 || 10#$port > 65535 )); then
	echo "error"; echo "invalid port: ${port}" >&2; exit 1
fi
port=$((10#$port))

if [ ! -f "$CSF_CONF" ] || ! command -v csf >/dev/null 2>&1; then
	echo "nocsf"
	exit 0
fi

# the value of a csf.conf list option, e.g. "22,80,1000:2000" (last one wins, as in csf)
conf_value() {
	sed -n "s/^[[:space:]]*$1[[:space:]]*=[[:space:]]*\"\([^\"]*\)\".*/\1/p" "$CSF_CONF" | tail -1
}

port_in_list() {   # $1 = port, $2 = list
	local r
	local -a spec
	IFS=',' read -ra spec <<< "$2"
	for r in "${spec[@]}"; do
		r="${r//[[:space:]]/}"
		[ -n "$r" ] || continue
		case "$r" in
			*:*) [[ "${r%%:*}" =~ ^[0-9]+$ && "${r##*:}" =~ ^[0-9]+$ ]] || continue
			     (( $1 >= 10#${r%%:*} && $1 <= 10#${r##*:} )) && return 0 ;;
			*)   [[ "$r" =~ ^[0-9]+$ ]] && (( $1 == 10#$r )) && return 0 ;;
		esac
	done
	return 1
}

options=(TCP_OUT)
[ "$(conf_value IPV6)" = "1" ] && options+=(TCP6_OUT)

tmp=$(mktemp) || { echo "error"; exit 1; }
trap 'rm -f "$tmp"' EXIT
cat "$CSF_CONF" > "$tmp"

changed=0
for opt in "${options[@]}"; do
	# an option that is missing from csf.conf is not ours to invent
	grep -qE "^[[:space:]]*${opt}[[:space:]]*=" "$tmp" || continue
	list=$(conf_value "$opt")
	port_in_list "$port" "$list" && continue
	new="${list:+${list},}${port}"
	sed -i "s/^\([[:space:]]*${opt}[[:space:]]*=[[:space:]]*\)\"[^\"]*\"/\1\"${new}\"/" "$tmp"
	changed=1
done

if [ "$changed" -eq 0 ]; then
	echo "unchanged"
	exit 0
fi

# Write in place so csf.conf keeps its owner, mode and inode.
cp -a "$CSF_CONF" "${CSF_CONF}.reqad-bak"
cat "$tmp" > "$CSF_CONF"
if csf -r >/dev/null 2>&1; then
	echo "updated"
else
	cat "${CSF_CONF}.reqad-bak" > "$CSF_CONF"
	csf -r >/dev/null 2>&1
	echo "error"; echo "csf -r failed — csf.conf restored" >&2; exit 1
fi
