#!/bin/bash
# Re-issue / expand an account's Let's Encrypt certificate to a new SAN set.
# $1 = comma-separated domain list; the first entry is the cert lineage / main domain
#      (e.g. example.com,www.example.com,alias.example.com,mail.example.com)
# $2 = optional messages.db token: if given, post the outcome to the flash-message
#      queue so the panel can show a toast when issuance finishes.
#
# Uses --expand --cert-name <main> so the existing lineage is replaced with exactly
# this domain set (adds aliases / mail, drops removed ones) non-interactively.

DOMAINS="$1"
MAIN="${DOMAINS%%,*}"      # first domain = lineage name

# brief settle time for freshly-created alias DNS records to be answerable
sleep 15
sudo certbot --non-interactive --nginx --expand --cert-name "$MAIN" -d "$DOMAINS" >> /usr/local/reqad/log/debug_log 2>&1
RC=$?

# Refresh Dovecot/exim SNI certs in case mail.<domain> coverage changed.
sudo /usr/local/reqad/scripts/update_email_sni >> /usr/local/reqad/log/debug_log 2>&1

if [ -n "$2" ]; then
    DB=/usr/local/reqad/db/messages.db
    SQLITE="/usr/bin/sqlite3 -init /dev/null"
    $SQLITE "$DB" "CREATE TABLE IF NOT EXISTS messages (token TEXT PRIMARY KEY, type TEXT NOT NULL DEFAULT 'info', message TEXT NOT NULL, seen INTEGER NOT NULL DEFAULT 0, created INTEGER NOT NULL);"
    if [ $RC -eq 0 ]; then
        $SQLITE "$DB" "INSERT OR REPLACE INTO messages (token,type,message,seen,created) VALUES ('$2','success','SSL certificate updated for $MAIN.',0,strftime('%s','now'));"
    else
        $SQLITE "$DB" "INSERT OR REPLACE INTO messages (token,type,message,seen,created) VALUES ('$2','error','SSL certificate could not be updated for $MAIN. Check that the alias domains resolve to this server, then retry from the account page.',0,strftime('%s','now'));"
    fi
fi
