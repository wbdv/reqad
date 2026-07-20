#!/bin/bash
# Reqad DNS Agent — one-time setup on cPanel server.
# Run as root.

set -e

WHITE='\033[1;37m'
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
NC='\033[0m'

AGENT_USER="reqad-agent"
AGENT_DIR="/home/$AGENT_USER"
AGENT_PORT=2089

# cPanel wildcard certificate — update these paths if they change after renewal
CERT_FILE="/var/cpanel/ssl/system/certs/_wildcard__webindex_ro_b9cbc_b9575_1780765636_aebe9dc91498840993684a16b36d0dab.crt"
KEY_FILE="/var/cpanel/ssl/system/keys/b9cbc_b9575_d70611b48efa22e0b405f5b3f411a1f0.key"

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

# ── Checks ─────────────────────────────────────────────────────────────────────

if [ "$EUID" -ne 0 ]; then
    echo "Please run as root" >&2
    exit 1
fi

if [ ! -x /usr/local/cpanel/bin/whmapi1 ]; then
    echo "Error: whmapi1 not found — is this a cPanel server?" >&2
    exit 1
fi

if ! python3 -c "import sqlite3, ssl, http.server" 2>/dev/null; then
    echo "Error: Python 3 with required stdlib modules not found" >&2
    exit 1
fi

echo -e "${WHITE}Reqad DNS Agent setup${NC}"
echo

# ── System user ────────────────────────────────────────────────────────────────

echo -n "Creating user '$AGENT_USER'            "
if id "$AGENT_USER" &>/dev/null; then
    echo -e "[ ${YELLOW}EXISTS${NC} ]"
else
    useradd -r -m -d "$AGENT_DIR" -s /bin/bash "$AGENT_USER"
    echo -e "[ ${GREEN}OK${NC} ]"
fi

# ── Deploy files ───────────────────────────────────────────────────────────────

echo -n "Deploying agent files                  "
cp "$SCRIPT_DIR/agent.py" "$AGENT_DIR/agent.py"
chmod 700 "$AGENT_DIR/agent.py"
cp "$SCRIPT_DIR/add-server.sh" "$AGENT_DIR/add-server.sh"
chmod 700 "$AGENT_DIR/add-server.sh"
echo -e "[ ${GREEN}OK${NC} ]"

# ── Config file ────────────────────────────────────────────────────────────────

echo -n "Writing agent.conf                     "
if [ ! -f "$CERT_FILE" ]; then
    echo -e "[ ${RED}ERROR${NC} ] cert not found: $CERT_FILE" >&2
    exit 1
fi
if [ ! -f "$KEY_FILE" ]; then
    echo -e "[ ${RED}ERROR${NC} ] key not found: $KEY_FILE" >&2
    exit 1
fi
cat > "$AGENT_DIR/agent.conf" <<EOF
{
    "cert_file": "$CERT_FILE",
    "key_file":  "$KEY_FILE",
    "port":      $AGENT_PORT,
    "host":      "0.0.0.0"
}
EOF
echo -e "[ ${GREEN}OK${NC} ]"

# ── Database ───────────────────────────────────────────────────────────────────

echo -n "Initializing database                  "
if [ ! -f "$AGENT_DIR/agent.db" ]; then
    python3 - "$AGENT_DIR/agent.db" <<'PYEOF'
import sqlite3, sys
conn = sqlite3.connect(sys.argv[1])
conn.executescript("""
    CREATE TABLE IF NOT EXISTS servers (
        id    INTEGER PRIMARY KEY,
        name  TEXT    NOT NULL,
        ip    TEXT    NOT NULL UNIQUE,
        token TEXT    NOT NULL UNIQUE
    );
    CREATE TABLE IF NOT EXISTS domains (
        domain    TEXT    NOT NULL,
        server_id INTEGER NOT NULL,
        UNIQUE(domain),
        FOREIGN KEY (server_id) REFERENCES servers(id)
    );
""")
conn.commit()
PYEOF
    echo -e "[ ${GREEN}CREATED${NC} ]"
else
    echo -e "[ ${YELLOW}EXISTS${NC} ]"
fi

# ── Ownership ──────────────────────────────────────────────────────────────────

chown -R "$AGENT_USER:$AGENT_USER" "$AGENT_DIR"
chmod 700 "$AGENT_DIR"

# ── Sudoers ────────────────────────────────────────────────────────────────────

echo -n "Writing sudoers entry                  "
SUDOERS_FILE="/etc/sudoers.d/reqad-agent"
cat > "$SUDOERS_FILE" <<EOF
$AGENT_USER ALL=(root) NOPASSWD: /usr/local/cpanel/bin/whmapi1 adddns *, /usr/local/cpanel/bin/whmapi1 killdns *, /usr/local/cpanel/bin/whmapi1 dumpzone *, /usr/local/cpanel/bin/whmapi1 parse_dns_zone *, /usr/local/cpanel/bin/whmapi1 mass_edit_dns_zone *
EOF
chmod 440 "$SUDOERS_FILE"
echo -e "[ ${GREEN}OK${NC} ]"

# ── CSF firewall ───────────────────────────────────────────────────────────────

echo -n "Opening port $AGENT_PORT in CSF        "
if [ -f /etc/csf/csf.conf ]; then
    # Add port to TCP_IN if not already there
    if ! grep -q ",$AGENT_PORT," /etc/csf/csf.conf && ! grep -qE "TCP_IN.*\b$AGENT_PORT\b" /etc/csf/csf.conf; then
        sed -i "s/^TCP_IN = \"\(.*\)\"/TCP_IN = \"\1,$AGENT_PORT\"/" /etc/csf/csf.conf
    fi
    echo -e "[ ${GREEN}OK${NC} ] (per-IP rules added via add-server.sh)"
else
    echo -e "[ ${YELLOW}SKIP${NC} ] (CSF not found)"
fi

# ── Systemd service ────────────────────────────────────────────────────────────

echo -n "Installing systemd service             "
cat > /etc/systemd/system/reqad-agent.service <<EOF
[Unit]
Description=Reqad DNS Agent
After=network.target

[Service]
Type=simple
User=$AGENT_USER
WorkingDirectory=$AGENT_DIR
ExecStart=/usr/bin/python3 $AGENT_DIR/agent.py
Restart=always
RestartSec=5
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable reqad-agent --now
if systemctl is-active --quiet reqad-agent; then
    echo -e "[ ${GREEN}OK${NC} ]"
else
    echo -e "[ ${RED}ERROR${NC} ] check: journalctl -u reqad-agent" >&2
    exit 1
fi

# ── Done ───────────────────────────────────────────────────────────────────────

SERVER_IP=$(ip route get 8.8.8.8 2>/dev/null | awk 'NR==1{print $7}')

echo
echo -e "${WHITE}Setup complete.${NC}"
echo
echo "  Agent URL : https://${SERVER_IP:-<this-server-ip>}:${AGENT_PORT}"
echo "  Status    : systemctl status reqad-agent"
echo "  Logs      : $AGENT_DIR/agent.log"
echo "              journalctl -u reqad-agent -f"
echo
echo "Next: register the Reqad VPS:"
echo "  $AGENT_DIR/add-server.sh <name> <reqad-vps-ip> <token>"
echo "  Example: $AGENT_DIR/add-server.sh v182 85.9.27.182 $(openssl rand -hex 20)"
