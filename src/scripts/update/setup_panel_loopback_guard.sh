#!/bin/bash
# Stop hosting accounts from connecting to the panel's ports over loopback.
#
# The panel (2087, later 2083) and the services behind it listen on, or are
# reachable through, the loopback interface: wetty on 127.0.0.1:3000 runs a
# shell for whichever user the panel last picked, monit on 127.0.0.1:2812
# accepts any local client (`allow localhost`), and the wordfence console uses
# websocat on 2122. A connection from the server to its OWN public IP also
# travels over `lo`. So code running as a hosting account - a hacked plugin, a
# cron job, an SSH session - could talk to all of them directly, skipping the
# panel's nginx and its authentication.
#
# This installs a csf post hook that rejects NEW connections to those ports on
# `lo` from any process whose uid is in the regular-user range (>= 1000). Root,
# reqad, nginx, monit and the other system users (< 1000) are unaffected, and
# nothing changes for traffic from outside the server.
#
# The same chain keeps Node.js/Python apps (Accounts > Deployments) private to their
# account: apps listen on 127.0.0.1 in APP_PORTS, and only the app's own
# account (and nginx, a system user) may connect to its port. The hook reads
# the port -> uid pairs from /etc/reqad/app-ports at run time, so app changes
# just run the hook again (app_ports_apply() in app/functions/apps.php).
#
#   /usr/local/csf/bin/reqad-post.sh  root-owned, 0700, regenerated every run
#   csfpost.sh                        one managed line that calls it
#
# The rules are applied immediately as well, so no csf restart is needed.
# Idempotent; a no-op when csf is not installed.
#
# Usage: setup_panel_loopback_guard.sh [--dry-run|--remove]

if [ "$(id -u)" -ne 0 ]; then
    echo "This script must be run as root"
    exit 1
fi

MODE=apply
[ "$1" = "--dry-run" ] && MODE=dry
[ "$1" = "--remove" ]  && MODE=remove

CSF_CONF=/etc/csf/csf.conf
HOOK=/usr/local/csf/bin/reqad-post.sh
MARK='# reqad: panel loopback guard (managed by setup_panel_loopback_guard.sh)'
# 8080, 8443: Apache behind nginx (nginx_apache_php-fpm) - straight to it would skip
# nginx's rules and let a local client claim any visitor address (X-Real-IP)
PORTS="2087,2083,3000,2122,2812,8080,8443"
UID_RANGE="1000-4294967294"
APP_PORTS="30000:39999"
APP_PORTS_FILE=/etc/reqad/app-ports

[ -f "$CSF_CONF" ] && [ -d /usr/local/csf/bin ] || { echo "setup_panel_loopback_guard: csf not installed, skipped"; exit 0; }

csf_conf() { sed -n -E "s/^$1[[:space:]]*=[[:space:]]*\"([^\"]*)\".*/\1/p" "$CSF_CONF" | head -1; }
IPT=$(csf_conf IPTABLES);   IPT=${IPT:-/sbin/iptables}
IPT6=$(csf_conf IP6TABLES); IPT6=${IPT6:-/sbin/ip6tables}
IPV6=$(csf_conf IPV6)

# csf runs /usr/local/csf/bin/csfpost.sh if present, else /etc/csf/csfpost.sh.
if   [ -f /usr/local/csf/bin/csfpost.sh ]; then POST=/usr/local/csf/bin/csfpost.sh
elif [ -f /etc/csf/csfpost.sh ];          then POST=/etc/csf/csfpost.sh
else                                           POST=/usr/local/csf/bin/csfpost.sh
fi

if [ "$MODE" = dry ]; then
    echo "would write $HOOK (ports $PORTS, uids $UID_RANGE, iptables $IPT${IPV6:+, ip6tables $IPT6})"
    grep -qF "$MARK" "$POST" 2>/dev/null && echo "$POST already calls it" || echo "would add the hook call to $POST"
    exit 0
fi

if [ "$MODE" = remove ]; then
    [ -f "$POST" ] && sed -i "\|$MARK|,+1d" "$POST"
    rm -f "$HOOK"
    for T in "$IPT" "$IPT6"; do
        [ -x "$T" ] || continue
        while $T -w -D OUTPUT -o lo -j REQADLO 2>/dev/null; do :; done
        $T -w -F REQADLO 2>/dev/null; $T -w -X REQADLO 2>/dev/null
    done
    echo "setup_panel_loopback_guard: removed"
    exit 0
fi

# ── hook script ──────────────────────────────────────────────────────────────
TMP=$(mktemp "$HOOK.XXXXXX")
cat > "$TMP" <<EOF
#!/bin/bash
# Managed by Reqad (scripts/update/setup_panel_loopback_guard.sh) - regenerated
# on every update, local edits are lost. Rejects connections from hosting
# accounts (uid $UID_RANGE) to the panel's ports over loopback.
PORTS="$PORTS"
for T in "$IPT" $( [ "$IPV6" = "1" ] && echo "\"$IPT6\"" ); do
    [ -x "\$T" ] || continue
    \$T -w -N REQADLO 2>/dev/null
    \$T -w -F REQADLO
    # --syn: only a connection's first packet. Without it the SYN-ACK an
    # account's app sends back to an ephemeral port that falls in
    # $APP_PORTS (the kernel's range starts at 32768) was rejected, and about
    # one connection in four to an app - nginx's included - hung.
    \$T -w -A REQADLO -p tcp --syn -m multiport --dports "\$PORTS" -m owner --uid-owner $UID_RANGE -j REJECT --reject-with tcp-reset
    # apps: each port is for its own account only ("<port> <uid>" per line)
    if [ -r "$APP_PORTS_FILE" ]; then
        while read -r P U; do
            case "\$P\$U" in *[!0-9]*|'') continue ;; esac
            \$T -w -A REQADLO -p tcp --syn --dport "\$P" -m owner --uid-owner "\$U" -j RETURN
        done < "$APP_PORTS_FILE"
    fi
    \$T -w -A REQADLO -p tcp --syn --dport $APP_PORTS -m owner --uid-owner $UID_RANGE -j REJECT --reject-with tcp-reset
    \$T -w -C OUTPUT -o lo -j REQADLO 2>/dev/null || \$T -w -I OUTPUT 1 -o lo -j REQADLO
done
exit 0
EOF
chown root:root "$TMP"; chmod 0700 "$TMP"
mv -f "$TMP" "$HOOK"

# ── csfpost.sh: one managed call ─────────────────────────────────────────────
if [ ! -f "$POST" ]; then
    printf '#!/bin/bash\n' > "$POST"
    chmod 0700 "$POST"
fi
if ! grep -qF "$MARK" "$POST"; then
    printf '\n%s\n[ -x %s ] && %s\n' "$MARK" "$HOOK" "$HOOK" >> "$POST"
fi

# ── apply now ────────────────────────────────────────────────────────────────
if "$HOOK" && $IPT -w -C OUTPUT -o lo -j REQADLO 2>/dev/null; then
    echo "setup_panel_loopback_guard: hosting accounts can no longer reach ports $PORTS on loopback"
else
    echo "setup_panel_loopback_guard: WARNING - rules not active, check $HOOK"
fi
exit 0
