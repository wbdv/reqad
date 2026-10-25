#!/bin/bash
# Reqad DNS cluster: turn this machine into a secondary DNS server of a Reqad
# server (the primary). Run it as root on the secondary; the primary's panel
# (Settings > DNS cluster) shows the exact command with its own values.
#
#   pdns-secondary-setup.sh --primary=IP[:port][,IP...] --catalog=NAME
#
# What it does (again on every run, so a second run changes the primaries):
#   - installs PowerDNS with the SQLite backend (Rocky / Alma / RHEL 8-10:
#     EPEL; Debian / Ubuntu: apt), version 4.7 or newer is needed;
#   - writes /etc/pdns/pdns.conf (the old one is kept as pdns.conf.<date>):
#     a secondary that accepts NOTIFY only from the primary, serves on port 53
#     and gives zone transfers to nobody;
#   - adds the primary's catalog zone as a consumer: every zone in it is made
#     here as a secondary zone and copied from the primary, new zones appear
#     and removed zones go away by themselves;
#   - opens port 53 (TCP and UDP) in firewalld when it runs;
#   - starts PowerDNS and lists the zones it has copied.
# No zone data is changed by hand here: the primary is the only place to edit.

set -u
PRIMARY=''; CATALOG=''
for a in "$@"; do
    case "$a" in
        --primary=*) PRIMARY=${a#--primary=} ;;
        --catalog=*) CATALOG=${a#--catalog=} ;;
        *) echo "unknown option: $a"; exit 2 ;;
    esac
done
[ "$(id -u)" -eq 0 ] || { echo "Run this as root."; exit 1; }
[ -n "$PRIMARY" ] && [ -n "$CATALOG" ] || { echo "usage: $0 --primary=IP[:port][,IP...] --catalog=NAME"; exit 2; }
echo "$PRIMARY" | grep -qE '^[][0-9a-fA-F:.,]+$' || { echo "--primary: addresses only (IP or IP:port, comma separated)"; exit 2; }
echo "$CATALOG" | grep -qE '^[a-z0-9.-]+$' || { echo "--catalog: a zone name"; exit 2; }
CATALOG=${CATALOG%.}

say() { echo -e "\033[1;37m== $*\033[0m"; }
die() { echo -e "\033[0;31m$*\033[0m"; exit 1; }

# --- memory -----------------------------------------------------------------
# PowerDNS needs little memory, but dnf reading EPEL's package lists needs
# about 500 MB: a small VM without swap gets a 1 GB swap file (kept).
MEM_MB=$(awk '/^MemTotal/ {print int($2/1024)}' /proc/meminfo)
if [ "$MEM_MB" -lt 1500 ] && [ -z "$(swapon --show=NAME --noheadings 2>/dev/null)" ] && [ ! -e /swapfile ]; then
    say "Adding a 1 GB swap file (this machine has ${MEM_MB} MB of memory and no swap)"
    if fallocate -l 1G /swapfile 2>/dev/null || dd if=/dev/zero of=/swapfile bs=1M count=1024 status=none; then
        chmod 600 /swapfile && mkswap /swapfile >/dev/null && swapon /swapfile && echo '/swapfile none swap defaults 0 0' >> /etc/fstab
    fi
fi

# --- packages ---------------------------------------------------------------
if command -v dnf >/dev/null; then
    if ! rpm -q pdns pdns-backend-sqlite sqlite >/dev/null 2>&1; then
        say "Installing PowerDNS"
        rpm -q epel-release >/dev/null 2>&1 || dnf -y install epel-release || die "Could not install epel-release."
        dnf -y install pdns pdns-backend-sqlite sqlite bind-utils || die "Could not install PowerDNS."
    fi
    PDNS_USER=pdns; CONF_DIR=/etc/pdns; DB=/var/lib/pdns/pdns.db; SVC=pdns
    SCHEMA=$(rpm -ql pdns pdns-backend-sqlite 2>/dev/null | grep '/schema.sqlite3.sql$' | head -1)
elif command -v apt-get >/dev/null; then
    if ! dpkg -s pdns-server pdns-backend-sqlite3 sqlite3 >/dev/null 2>&1; then
        say "Installing PowerDNS"
        DEBIAN_FRONTEND=noninteractive apt-get -y update && DEBIAN_FRONTEND=noninteractive apt-get -y install pdns-server pdns-backend-sqlite3 sqlite3 dnsutils || die "Could not install PowerDNS."
    fi
    PDNS_USER=pdns; CONF_DIR=/etc/powerdns; DB=/var/lib/powerdns/pdns.db; SVC=pdns
    SCHEMA=$(dpkg -L pdns-backend-sqlite3 2>/dev/null | grep '/schema.sqlite3.sql$' | head -1)
    rm -f "$CONF_DIR"/pdns.d/bind.conf 2>/dev/null       # Debian's default bind backend
else
    die "This script knows dnf (Rocky, Alma, RHEL) and apt (Debian, Ubuntu) only."
fi
VER=$(pdns_server --version 2>&1 | grep -oE 'Authoritative Server [0-9]+\.[0-9]+' | grep -oE '[0-9.]+$' | head -1)
case "$VER" in 4.[0-6]|3.*|'') die "PowerDNS $VER is too old: catalog zones need 4.7 or newer." ;; esac

# --- database ---------------------------------------------------------------
mkdir -p "$(dirname "$DB")"
if [ ! -s "$DB" ]; then
    [ -n "$SCHEMA" ] || die "The SQLite schema of PowerDNS was not found."
    sqlite3 "$DB" < "$SCHEMA" || die "Could not make $DB."
fi
chown "$PDNS_USER:$PDNS_USER" "$DB" "$(dirname "$DB")"

# --- configuration ------------------------------------------------------------
# NOTIFY is accepted from the primary's addresses (no port)
FROM=$(echo "$PRIMARY" | tr ',' '\n' | sed -E 's/^\[([^]]+)\](:[0-9]+)?$/\1/; s/^([0-9.]+):[0-9]+$/\1/' | paste -sd, -)
NEW=$(mktemp)
cat > "$NEW" <<EOF
# PowerDNS secondary of a Reqad DNS cluster - written by pdns-secondary-setup.sh
# The zones come from the primary ($PRIMARY) through its catalog zone
# $CATALOG; change them there, not here.
launch=gsqlite3
gsqlite3-database=$DB
setuid=$PDNS_USER
setgid=$PDNS_USER
local-address=0.0.0.0, ::
local-port=53
secondary=yes
allow-notify-from=$FROM
allow-axfr-ips=127.0.0.0/8,::1
xfr-cycle-interval=15
api=no
webserver=no
version-string=anonymous
security-poll-suffix=
EOF
if ! cmp -s "$NEW" "$CONF_DIR/pdns.conf"; then
    [ -f "$CONF_DIR/pdns.conf" ] && cp -a "$CONF_DIR/pdns.conf" "$CONF_DIR/pdns.conf.$(date +%Y%m%d%H%M%S)"
    cat "$NEW" > "$CONF_DIR/pdns.conf"
    chmod 640 "$CONF_DIR/pdns.conf"; chgrp "$PDNS_USER" "$CONF_DIR/pdns.conf" 2>/dev/null
fi
rm -f "$NEW"

# --- port 53 -----------------------------------------------------------------
if ss -lnup 2>/dev/null | grep -E ':53\s' | grep -qv pdns_server; then
    ss -lnup | grep -E ':53\s' | grep -v pdns_server
    die "Another program listens on port 53 (above). Stop it first (e.g. systemd-resolved's stub: DNSStubListener=no)."
fi
if systemctl is-active --quiet firewalld; then
    firewall-cmd -q --permanent --add-service=dns && firewall-cmd -q --reload && say "firewalld: port 53 open"
elif [ -f /etc/csf/csf.conf ]; then
    echo "csf runs here: make sure TCP_IN and UDP_IN have port 53."
fi

say "Starting PowerDNS"
systemctl enable "$SVC" >/dev/null 2>&1
systemctl restart "$SVC" || die "PowerDNS did not start: journalctl -u $SVC"
for i in $(seq 1 15); do pdns_control rping >/dev/null 2>&1 && break; sleep 1; done

# --- the catalog -------------------------------------------------------------
PRIMS=$(echo "$PRIMARY" | tr ',' ' ')
if pdnsutil list-all-zones 2>/dev/null | grep -qx "$CATALOG"; then
    pdnsutil change-secondary-zone-primary "$CATALOG" $PRIMS >/dev/null
else
    pdnsutil create-secondary-zone "$CATALOG" $PRIMS >/dev/null || die "Could not add the catalog zone."
fi
pdnsutil set-kind "$CATALOG" consumer >/dev/null || die "Could not make $CATALOG a catalog (consumer) zone."
pdns_control retrieve "$CATALOG" >/dev/null 2>&1

say "Waiting for the zones (up to 60 s)"
n=0
for i in $(seq 1 30); do
    sleep 2
    n=$(pdnsutil list-all-zones 2>/dev/null | grep -vxc "$CATALOG")
    [ "$n" -gt 0 ] && [ "$i" -gt 5 ] && break
done
echo "Zones copied from the primary: $n"
pdnsutil list-all-zones 2>/dev/null | grep -vx "$CATALOG" | head -20
[ "$n" -gt 20 ] && echo "..."
if [ "$n" -eq 0 ]; then
    echo "None yet. Check that the primary can reach this machine on port 53 and this machine the primary,"
    echo "and the log: journalctl -u $SVC -n 30"
fi
say "Done. The primary's panel (Settings > DNS cluster) shows whether every zone is in step."
