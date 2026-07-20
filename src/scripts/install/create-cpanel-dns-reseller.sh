#!/bin/bash
# Run on the cPanel server as root.
# Creates a WHM reseller account for Reqad DNS management.
# No hosting package or public domain needed — a placeholder domain is required
# by cPanel's account system but doesn't need to resolve publicly.
#
# Usage: ./create-cpanel-dns-reseller.sh <username> <placeholder-domain>
# Example: ./create-cpanel-dns-reseller.sh reqad-dns reqad-dns.yourmainserver.com

WHITE='\033[1;37m'
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
NC='\033[0m'

WHMAPI="/usr/local/cpanel/bin/whmapi1"

if [ "$EUID" -ne 0 ]; then
    echo "Please run as root" 1>&2
    exit 1
fi

if [ ! -x "$WHMAPI" ]; then
    echo "Error: whmapi1 not found at $WHMAPI — is this a cPanel server?" 1>&2
    exit 1
fi

USERNAME="$1"
PLACEHOLDER_DOMAIN="$2"
ACL_NAME="reqad-dns-acl"
TOKEN_NAME="reqad-dns-token"

if [ -z "$USERNAME" ] || [ -z "$PLACEHOLDER_DOMAIN" ]; then
    echo "Usage: $0 <username> <placeholder-domain>" 1>&2
    echo "Example: $0 reqad-dns reqad-dns.$(hostname)" 1>&2
    exit 1
fi

if ! echo "$USERNAME" | grep -qE '^[a-z][a-z0-9_-]{1,15}$'; then
    echo "Error: invalid username (lowercase letters, digits, - _ allowed, 2-16 chars)" 1>&2
    exit 1
fi

whmapi1_ok() {
    local output="$1"
    echo "$output" | grep -q 'result: 1'
}

echo -e "${WHITE}Setting up cPanel DNS reseller account for Reqad${NC}"
echo

# ── 1. Create account ──────────────────────────────────────────────────────────
PASSWORD=$(openssl rand -base64 32 | tr -d '+/=' | cut -c1-24)

echo -n "Creating account '$USERNAME'            "
if $WHMAPI --output=json createacct \
        username="$USERNAME" \
        domain="$PLACEHOLDER_DOMAIN" \
        password="$PASSWORD" \
        ip=n \
        hasshell=n \
        maxpop=0 \
        maxftp=0 \
        maxsql=0 \
        maxsub=0 \
        maxpark=0 \
        maxaddon=0 2>/dev/null | python3 -c "
import sys, json
d = json.load(sys.stdin)
ok = d.get('metadata', {}).get('result', 0) == 1
print('ok' if ok else 'fail:' + d.get('metadata', {}).get('reason', '?'))
sys.exit(0 if ok else 1)
"; then
    echo -e "[ ${GREEN}OK${NC} ]"
else
    # Account may already exist — check
    if $WHMAPI --output=json accountsummary user="$USERNAME" 2>/dev/null | python3 -c "
import sys, json; d=json.load(sys.stdin); sys.exit(0 if d.get('metadata',{}).get('result',0)==1 else 1)
" 2>/dev/null; then
        echo -e "[ ${YELLOW}EXISTS${NC} ] (already exists, continuing)"
    else
        echo -e "[ ${RED}ERROR${NC} ]"
        exit 1
    fi
fi

# ── 2. Grant reseller status ───────────────────────────────────────────────────
echo -n "Granting reseller status               "
OUT=$($WHMAPI setupreseller user="$USERNAME" makeowner=0 2>/dev/null)
if whmapi1_ok "$OUT"; then
    echo -e "[ ${GREEN}OK${NC} ]"
else
    echo -e "[ ${RED}ERROR${NC} ]"
    echo "$OUT"
    exit 1
fi

# ── 3. Apply DNS ACL directly to reseller ─────────────────────────────────────
echo -n "Setting DNS permissions on '$USERNAME' "
OUT=$($WHMAPI setreselleracls \
        user="$USERNAME" \
        "create-dns=1" \
        "kill-dns=1" \
        "edit-dns=1" 2>/dev/null)
if whmapi1_ok "$OUT"; then
    echo -e "[ ${GREEN}OK${NC} ]"
else
    echo -e "[ ${RED}ERROR${NC} ]"
    echo "$OUT"
    exit 1
fi

# ── 5. Create WHM API token ────────────────────────────────────────────────────
echo -n "Creating API token                     "
TOKEN=$($WHMAPI --output=json api_token_create \
        token_name="$TOKEN_NAME" \
        user="$USERNAME" 2>/dev/null | python3 -c "
import sys, json
d = json.load(sys.stdin)
token = d.get('data', {}).get('token', '')
print(token)
sys.exit(0 if token else 1)
" 2>/dev/null)

if [ -n "$TOKEN" ]; then
    echo -e "[ ${GREEN}OK${NC} ]"
else
    echo -e "[ ${YELLOW}MANUAL${NC} ] (user= param not supported on this cPanel version)"
    TOKEN="<create manually: WHM → Development → Manage API Tokens, switch to user '$USERNAME'>"
fi

# ── Summary ────────────────────────────────────────────────────────────────────
SERVER_IP=$(ip route get 8.8.8.8 2>/dev/null | awk 'NR==1{print $7}')

echo
echo -e "${WHITE}Done. Paste these into Reqad Settings → DNS Settings (cPanel provider):${NC}"
echo
echo "  Server  : ${SERVER_IP:-<this-server-ip>}"
echo "  Username: $USERNAME"
echo "  Token   : $TOKEN"
echo
echo "The account uses a placeholder domain ($PLACEHOLDER_DOMAIN)."
echo "It can create/manage DNS zones for all Reqad-hosted domains."
