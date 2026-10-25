#!/bin/bash
# Block / unblock an IP in csf for failed panel logins (called by the panel via
# sudo from app/auth/auth.php; also usable by hand).
#
#   auth_ban.sh temp   <ip> <seconds> [comment]   csf -td (temporary, all ports)
#   auth_ban.sh perm   <ip> [comment]             csf -d  (csf.deny)
#   auth_ban.sh remove <ip>                       csf -tr and csf -dr
#
# Refuses anything that is not a single IP address, the server's own
# addresses, and any IP listed in csf.allow or csf.ignore - an allowlisted
# admin must never be locked out by a typo storm.

[ "$(id -u)" -eq 0 ] || { echo "must run as root"; exit 1; }

ACTION=$1
IP=$2

valid_ip() {
    python3 -c 'import ipaddress,sys; ipaddress.ip_address(sys.argv[1])' "$1" 2>/dev/null
}

valid_ip "$IP" || { echo "not an IP address: $IP"; exit 2; }
command -v csf >/dev/null 2>&1 || { echo "csf not installed - not blocked in the firewall"; exit 0; }

if [ "$ACTION" = temp ] || [ "$ACTION" = perm ]; then
    for own in 127.0.0.1 ::1 $(hostname -I 2>/dev/null); do
        [ "$IP" = "$own" ] && { echo "refused: $IP is this server"; exit 3; }
    done
    # Plain IP / CIDR entries (the first word of a line); advanced
    # tcp|in|d=..|s=.. rules are port-specific and do not exempt.
    HIT=$(python3 - "$IP" /etc/csf/csf.allow /etc/csf/csf.ignore <<'PY'
import ipaddress, sys
ip = ipaddress.ip_address(sys.argv[1])
for path in sys.argv[2:]:
    try:
        lines = open(path).read().splitlines()
    except OSError:
        continue
    for line in lines:
        word = line.split('#', 1)[0].strip().split()
        if not word:
            continue
        try:
            if ip in ipaddress.ip_network(word[0], strict=False):
                print(path); sys.exit(0)
        except ValueError:
            pass
PY
)
    [ -n "$HIT" ] && { echo "refused: $IP is allowlisted in $HIT"; exit 3; }
fi

case "$ACTION" in
    temp)
        SECS=$3
        [[ "$SECS" =~ ^[0-9]+$ ]] && [ "$SECS" -ge 60 ] && [ "$SECS" -le 2592000 ] || { echo "bad duration: $SECS"; exit 2; }
        COMMENT=$(printf '%s' "${4:-Reqad panel login failures}" | tr -cd '[:alnum:] ._:()-' | cut -c1-80)
        csf -td "$IP" "$SECS" "$COMMENT" 2>&1 | tail -1
        ;;
    perm)
        COMMENT=$(printf '%s' "${3:-Reqad panel login failures}" | tr -cd '[:alnum:] ._:()-' | cut -c1-80)
        csf -d "$IP" "$COMMENT" 2>&1 | tail -1
        ;;
    remove)
        csf -tr "$IP" >/dev/null 2>&1
        csf -dr "$IP" 2>&1 | tail -1
        ;;
    *)
        echo "usage: $0 temp <ip> <seconds> [comment] | perm <ip> [comment] | remove <ip>"
        exit 2
        ;;
esac
exit 0
