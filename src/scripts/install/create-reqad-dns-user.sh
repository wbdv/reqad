#!/bin/bash
# Run on the cPanel primary DNS server as root.
# Creates a dedicated system user for one Reqad VPS to manage its DNS zones via SSH.
# Each Reqad VPS should have its own user.
# First run also performs one-time pdns.conf setup (idempotent).
#
# Usage: ./create-reqad-dns-user.sh <username> <vps-ip> [/path/to/pubkey.pub]

WHITE='\033[1;37m'
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
NC='\033[0m'

if [ "$EUID" -ne 0 ]; then
    echo "Please run as root" 1>&2
    exit 1
fi

USER_NAME="$1"
VPS_IP="$2"
PUBKEY_FILE="$3"

if [ -z "$USER_NAME" ] || [ -z "$VPS_IP" ]; then
    echo "Usage: $0 <username> <vps-ip> [/path/to/pubkey.pub]" 1>&2
    exit 1
fi

if ! echo "$USER_NAME" | grep -qE '^[a-z][a-z0-9_-]{1,30}$'; then
    echo "Error: invalid username '$USER_NAME' (lowercase letters, digits, - _ allowed, 2-31 chars)" 1>&2
    exit 1
fi

if ! echo "$VPS_IP" | grep -qE '^[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}$'; then
    echo "Error: invalid IP address '$VPS_IP'" 1>&2
    exit 1
fi

if [ -n "$PUBKEY_FILE" ] && [ ! -f "$PUBKEY_FILE" ]; then
    echo "Error: public key file '$PUBKEY_FILE' not found" 1>&2
    exit 1
fi

# Detect pdns_control path
PDNS_CONTROL=$(command -v pdns_control 2>/dev/null || find /usr -name pdns_control 2>/dev/null | head -1)
if [ -z "$PDNS_CONTROL" ]; then
    echo "Error: pdns_control not found. Is PowerDNS installed on this server?" 1>&2
    exit 1
fi
echo -e "  ${GREEN}pdns_control${NC} $PDNS_CONTROL"

PDNS_CONF="/etc/pdns/pdns.conf"
NAMED_WRAPPER="/etc/pdns/named-wrapper.conf"
REQAD_ZONES_CONF="/etc/named.conf.reqad"

# One-time setup: redirect bind-config to a wrapper that includes our custom file
if grep -q "^bind-config=/etc/named.conf$" "$PDNS_CONF" 2>/dev/null; then
    echo -e "  ${YELLOW}One-time setup:${NC} redirecting bind-config to wrapper..."
    cat > "$NAMED_WRAPPER" <<EOF
include "/etc/named.conf";
include "${REQAD_ZONES_CONF}";
EOF
    touch "$REQAD_ZONES_CONF"
    sed -i "s|^bind-config=/etc/named.conf\$|bind-config=${NAMED_WRAPPER}|" "$PDNS_CONF"
    systemctl restart pdns
    if systemctl is-active --quiet pdns; then
        echo -e "  ${GREEN}pdns restarted OK${NC}"
    else
        echo -e "  ${RED}pdns failed to restart — check /etc/pdns/named-wrapper.conf${NC}" 1>&2
        exit 1
    fi
else
    echo -e "  ${GREEN}bind-config${NC} already set (skipping one-time setup)"
fi

# Ensure shared group for multi-VPS file access
groupadd -f reqad-dns
chown root:reqad-dns "$REQAD_ZONES_CONF"
chmod 664 "$REQAD_ZONES_CONF"

# Create user
if id "$USER_NAME" &>/dev/null; then
    echo -e "  ${YELLOW}Warning:${NC} user '$USER_NAME' already exists — skipping useradd"
else
    useradd -m -d "/home/$USER_NAME" -s /bin/bash "$USER_NAME"
    echo -e "  ${GREEN}Created user${NC} $USER_NAME"
fi
usermod -aG reqad-dns "$USER_NAME"

HOME_DIR="/home/$USER_NAME"
mkdir -p "$HOME_DIR/bin" "$HOME_DIR/etc" "$HOME_DIR/log"

# Write the wrapper script
cat > "$HOME_DIR/bin/reqad-dns-manage.sh" <<WRAPPER_EOF
#!/bin/bash
ZONES_FILE="\$HOME/etc/reqad-zones.list"
REQAD_CONF="${REQAD_ZONES_CONF}"
PDNS_CONTROL="${PDNS_CONTROL}"
LOG="\$HOME/log/reqad-dns.log"

log() { echo "\$(date '+%Y-%m-%d %H:%M:%S') \$*" >> "\$LOG"; }

CMD=\$(echo "\$SSH_ORIGINAL_COMMAND" | awk '{print \$1}')
ZONE=\$(echo "\$SSH_ORIGINAL_COMMAND" | awk '{print \$2}')

if ! echo "\$ZONE" | grep -qE '^[a-zA-Z0-9][a-zA-Z0-9.-]+\.[a-zA-Z]{2,}\$'; then
    log "invalid zone name: \$ZONE"
    echo "Error: invalid zone name"
    exit 1
fi

VPS_IP=\$(echo "\$SSH_CLIENT" | awk '{print \$1}')
log "cmd=\$CMD zone=\$ZONE vps=\$VPS_IP"

case "\$CMD" in
    add)
        if grep -qF "# BEGIN zone \${ZONE}" "\$REQAD_CONF" 2>/dev/null; then
            log "zone already in conf"
            echo "OK"
            exit 0
        fi
        echo "\$ZONE" >> "\$ZONES_FILE"
        sort -u "\$ZONES_FILE" -o "\$ZONES_FILE"
        cat >> "\$REQAD_CONF" <<ZONE_EOF

# BEGIN zone \${ZONE}
zone "\${ZONE}" {
    type slave;
    masters { \${VPS_IP}; };
    file "/var/named/\${ZONE}";
    allow-notify { \${VPS_IP}; };
};
# END zone \${ZONE}
ZONE_EOF
        RELOAD_OUT=\$(sudo "\$PDNS_CONTROL" reload 2>&1)
        RELOAD_RC=\$?
        log "reload rc=\$RELOAD_RC out=\$RELOAD_OUT"
        if [ \$RELOAD_RC -ne 0 ]; then
            sed -i "/# BEGIN zone \${ZONE}/,/# END zone \${ZONE}/d" "\$REQAD_CONF"
            sed -i "/^\${ZONE}\$/d" "\$ZONES_FILE"
            echo "Error: pdns_control reload failed"
            exit 1
        fi
        echo "OK"
        ;;
    delete)
        if ! grep -qxF "\$ZONE" "\$ZONES_FILE" 2>/dev/null; then
            log "rejected: zone not in list"
            echo "Error: zone \$ZONE is not managed by this server"
            exit 1
        fi
        sed -i "/# BEGIN zone \${ZONE}/,/# END zone \${ZONE}/d" "\$REQAD_CONF"
        sed -i "/^\${ZONE}\$/d" "\$ZONES_FILE"
        RELOAD_OUT=\$(sudo "\$PDNS_CONTROL" reload 2>&1)
        RELOAD_RC=\$?
        log "reload rc=\$RELOAD_RC out=\$RELOAD_OUT"
        if [ \$RELOAD_RC -ne 0 ]; then
            log "warning: reload failed after delete, zone may still be active"
        fi
        echo "OK"
        ;;
    *)
        log "unknown command: \$CMD"
        echo "Error: unknown command"
        exit 1
        ;;
esac
WRAPPER_EOF

chmod 700 "$HOME_DIR/bin/reqad-dns-manage.sh"
touch "$HOME_DIR/etc/reqad-zones.list"

# Sudoers: only pdns_control reload
SUDOERS_FILE="/etc/sudoers.d/reqad-${USER_NAME}"
cat > "$SUDOERS_FILE" <<SUDOERS_EOF
${USER_NAME} ALL=(root) NOPASSWD: ${PDNS_CONTROL} reload
SUDOERS_EOF
chmod 440 "$SUDOERS_FILE"
echo -e "  ${GREEN}Sudoers${NC}  $SUDOERS_FILE"

# SSH setup
mkdir -p "$HOME_DIR/.ssh"
chmod 700 "$HOME_DIR/.ssh"

AUTH_KEYS="$HOME_DIR/.ssh/authorized_keys"
FORCED_CMD="command=\"$HOME_DIR/bin/reqad-dns-manage.sh\",from=\"${VPS_IP}\",no-pty,no-port-forwarding,no-X11-forwarding,no-agent-forwarding"

if [ -n "$PUBKEY_FILE" ]; then
    PUBKEY=$(cat "$PUBKEY_FILE")
    echo "${FORCED_CMD} ${PUBKEY}" >> "$AUTH_KEYS"
    chmod 600 "$AUTH_KEYS"
    echo -e "  ${GREEN}SSH key${NC}   written to $AUTH_KEYS"
else
    echo -e "  ${YELLOW}SSH key${NC}   not provided — add it manually:"
    echo
    echo "  Append to $AUTH_KEYS:"
    echo "  ${FORCED_CMD} <paste-pubkey-here>"
    echo
fi

chown -R "$USER_NAME:$USER_NAME" "$HOME_DIR"

echo
echo -e "${GREEN}Done.${NC} User '$USER_NAME' set up for VPS IP $VPS_IP"
echo "  Zones conf : $REQAD_ZONES_CONF"
echo "  Zones list : $HOME_DIR/etc/reqad-zones.list"
echo "  Wrapper    : $HOME_DIR/bin/reqad-dns-manage.sh"
echo "  Sudoers    : $SUDOERS_FILE"
echo
echo "In Reqad Settings → DNS Settings → Hidden master:"
echo "  SSH host : <this-server-ip>"
echo "  SSH user : $USER_NAME"
echo "  SSH key  : /usr/local/reqad/etc/cpanel_dns_rsa"
echo
echo "Test from the Reqad VPS:"
echo "  ssh -i /usr/local/reqad/etc/cpanel_dns_rsa -p 1422 ${USER_NAME}@<this-server-ip> 'add test.example.com'"
