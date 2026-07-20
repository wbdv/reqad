#!/bin/bash
# Re-issue an account's Let's Encrypt certificate via the DNS-01 challenge.
# Needed when the SAN set includes a wildcard (*.domain), which HTTP-01 cannot do.
# $1 = comma-separated domain list; the first entry is the cert lineage / main domain
#      (e.g. example.com,www.example.com,*.example.com,mail.example.com)
# $2 = optional messages.db token: post the outcome to the flash-message queue.
# $3 = optional "staging" -> use the Let's Encrypt staging environment (for testing;
#      issues an untrusted cert but does not consume production rate limits).
#
# All names are validated via DNS-01 using certbot_dns_hook.php, which writes/removes
# the _acme-challenge TXT records through the configured DNS provider. --cert-name keeps
# the existing lineage path so the vhost's ssl_certificate directives need no change.

DOMAINS="$1"
MAIN="${DOMAINS%%,*}"
HOOK="/usr/local/reqad/scripts/certbot_dns_hook.php"
LOG=/usr/local/reqad/log/debug_log

STAGING=""
[ "$3" = "staging" ] && STAGING="--staging"

# turn the comma list into repeated -d args
DARGS=""
IFS=',' read -ra NAMES <<< "$DOMAINS"
for n in "${NAMES[@]}"; do
    [ -n "$n" ] && DARGS="$DARGS -d $n"
done

echo "[$(date '+%F %T')] reissue-dns $MAIN : $DOMAINS $STAGING" >> "$LOG"

sudo certbot certonly --non-interactive --agree-tos $STAGING \
    --manual --preferred-challenges dns \
    --manual-auth-hook "php $HOOK auth" \
    --manual-cleanup-hook "php $HOOK cleanup" \
    --cert-name "$MAIN" --expand $DARGS >> "$LOG" 2>&1
RC=$?

# The lineage path is unchanged; just reload so nginx serves the new SAN set.
sudo systemctl reload nginx >> "$LOG" 2>&1
# Refresh Dovecot/exim SNI certs in case mail.<domain> coverage changed.
sudo /usr/local/reqad/scripts/update_email_sni >> "$LOG" 2>&1

if [ -n "$2" ]; then
    DB=/usr/local/reqad/db/messages.db
    SQLITE="/usr/bin/sqlite3 -init /dev/null"
    $SQLITE "$DB" "CREATE TABLE IF NOT EXISTS messages (token TEXT PRIMARY KEY, type TEXT NOT NULL DEFAULT 'info', message TEXT NOT NULL, seen INTEGER NOT NULL DEFAULT 0, created INTEGER NOT NULL);"
    if [ $RC -eq 0 ]; then
        $SQLITE "$DB" "INSERT OR REPLACE INTO messages (token,type,message,seen,created) VALUES ('$2','success','Wildcard SSL certificate updated for $MAIN.',0,strftime('%s','now'));"
    else
        $SQLITE "$DB" "INSERT OR REPLACE INTO messages (token,type,message,seen,created) VALUES ('$2','error','Wildcard SSL certificate could not be updated for $MAIN. Check the DNS provider and that the zone is managed here, then retry.',0,strftime('%s','now'));"
    fi
fi
