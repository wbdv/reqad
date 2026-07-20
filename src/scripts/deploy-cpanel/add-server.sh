#!/bin/bash
# Register a Reqad VPS server with the DNS agent.
# Run as root on the cPanel server.
# Usage: ./add-server.sh <name> <reqad-vps-ip> <token>

GREEN='\033[0;32m'
RED='\033[0;31m'
NC='\033[0m'

AGENT_DIR="/home/reqad-agent"
DB="$AGENT_DIR/agent.db"
PORT=2089

if [ "$EUID" -ne 0 ]; then echo "Please run as root" >&2; exit 1; fi

NAME="$1"
IP="$2"
TOKEN="$3"

if [ -z "$NAME" ] || [ -z "$IP" ] || [ -z "$TOKEN" ]; then
    echo "Usage: $0 <name> <reqad-vps-ip> <token>" >&2
    echo "  name  : short label, e.g. v182"
    echo "  ip    : Reqad VPS IP, e.g. 85.9.27.182"
    echo "  token : secret token (generate with: openssl rand -hex 20)"
    exit 1
fi

if ! echo "$IP" | grep -qE '^[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}$'; then
    echo "Error: invalid IP address '$IP'" >&2; exit 1
fi

if [ ! -f "$DB" ]; then
    echo "Error: database not found at $DB — run setup-agent.sh first" >&2; exit 1
fi

# Insert server into database using Python (safe parameterized insert)
export REG_NAME="$NAME" REG_IP="$IP" REG_TOKEN="$TOKEN" REG_DB="$DB"
python3 << 'PYEOF'
import sqlite3, os, sys
conn = sqlite3.connect(os.environ['REG_DB'])
try:
    conn.execute('INSERT INTO servers (name, ip, token) VALUES (?, ?, ?)',
                 (os.environ['REG_NAME'], os.environ['REG_IP'], os.environ['REG_TOKEN']))
    conn.commit()
except sqlite3.IntegrityError as e:
    print(f"Error: {e} (server with this IP or token already exists)", file=sys.stderr)
    sys.exit(1)
PYEOF
[ $? -ne 0 ] && exit 1

# Add per-IP CSF allow rule for agent port
if [ -f /etc/csf/csf.allow ]; then
    echo "tcp|in|d=${PORT}|s=${IP}  # reqad-agent: $NAME" >> /etc/csf/csf.allow
    csf -r > /dev/null 2>&1 && echo -e "  CSF rule added for $IP → port $PORT"
fi

echo
echo -e "${GREEN}Server '$NAME' registered.${NC}"
echo "  IP    : $IP"
echo "  Token : $TOKEN"
echo
echo "In Reqad UI → DNS Settings → PowerDNS → Hidden master:"
echo "  Agent URL   : https://$(hostname):${PORT}"
echo "  Agent token : $TOKEN"
echo
echo "Test connection from Reqad VPS:"
echo "  curl -sk https://$(hostname):${PORT}/"
