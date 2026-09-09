#!/bin/bash
# Drop ::1 from the ManageSieve listener so dovecot starts on IPv6-less boxes.
#
# THE BUG
# 20-managesieve.conf used to be written with
#     listen = 127.0.0.1, ::1
# On a server where IPv6 is disabled there is no ::1 to bind, and dovecot does
# not degrade — a listener it cannot bind is FATAL:
#     master: Error: bind(::1, 4190) failed: Cannot assign requested address
#     master: Error: service(managesieve-login): listen(::1, 4190) failed: ...
#     master: Fatal: Failed to start listeners
# so the whole mail server refuses to start over a loopback-only service.
# It also bites servers that had IPv6 when the config was written and lost it
# later, which no install-time probe can catch.
#
# THE FIX
#   1. listen = 127.0.0.1   in /etc/dovecot/conf.d/20-managesieve.conf
#   2. Point roundcube's managesieve plugin at 127.0.0.1:4190 instead of
#      localhost:4190 — on a dual-stack box "localhost" resolves to ::1 first,
#      which nothing listens on any more.
# Everything that speaks ManageSieve here (roundcube, doveadm) is local, so
# nothing needs the IPv6 loopback.
#
# Safe to call from post_reqad_install.sh and safe to re-run:
#   * no-op unless the config exists and actually mentions ::1
#   * the file is backed up first
#   * rolls back if doveconf rejects the result
#   * dovecot is only restarted when it is already running
#
# Usage:  bash /usr/local/reqad/scripts/update/fix_managesieve_listen.sh

set -u

CONF=/etc/dovecot/conf.d/20-managesieve.conf
RC_MS=/usr/local/reqad/roundcubemail/plugins/managesieve/config.inc.php
STAMP=$(date +%Y%m%d%H%M%S)

fix_roundcube() {
    [ -f "$RC_MS" ] || return 0
    grep -q "'localhost:4190'" "$RC_MS" || return 0
    cp -a "$RC_MS" "$RC_MS.bak-$STAMP"
    sed -i "s/'localhost:4190'/'127.0.0.1:4190'/" "$RC_MS"
    echo "  roundcube managesieve_host -> 127.0.0.1:4190"
}

if [ ! -f "$CONF" ]; then
    echo "ManageSieve not configured on this server — nothing to do."
    fix_roundcube
    exit 0
fi

if ! grep -q '::1' "$CONF"; then
    echo "ManageSieve listener already IPv4-only — nothing to do."
    fix_roundcube
    exit 0
fi

echo "Removing ::1 from the ManageSieve listener ..."
cp -a "$CONF" "$CONF.bak-$STAMP" || { echo "backup failed" >&2; exit 1; }

# Only the listen line is touched: "127.0.0.1, ::1" / "::1, 127.0.0.1" / "::1".
sed -i -E '/^[[:space:]]*listen[[:space:]]*=/ {
    s/[[:space:]]*,[[:space:]]*::1//g
    s/::1[[:space:]]*,[[:space:]]*//g
    s/^([[:space:]]*listen[[:space:]]*=[[:space:]]*)::1[[:space:]]*$/\1127.0.0.1/
}' "$CONF"

if grep -q '::1' "$CONF"; then
    echo "unexpected ::1 left in $CONF — rolling back" >&2
    cp -a "$CONF.bak-$STAMP" "$CONF"
    exit 1
fi

if ! doveconf >/dev/null 2>&1; then
    echo "dovecot rejected the new config — rolling back" >&2
    cp -a "$CONF.bak-$STAMP" "$CONF"
    exit 1
fi

fix_roundcube

if systemctl is-active --quiet dovecot; then
    systemctl restart dovecot || {
        echo "dovecot failed to restart — rolling back" >&2
        cp -a "$CONF.bak-$STAMP" "$CONF"
        systemctl restart dovecot
        exit 1
    }
    echo "dovecot restarted."
else
    echo "dovecot is not running — start it with: systemctl start dovecot"
fi

echo "Done. Backup: $CONF.bak-$STAMP"
