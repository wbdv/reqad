#!/bin/bash
# $1 = comma-separated domain list (e.g. example.com,www.example.com)
# $2 = optional messages.db token: if given, post the outcome to the flash-message
#      queue so the panel (accounts list) can show a toast when issuance finishes.
sleep 30 && sudo certbot --non-interactive --nginx -d $1 >> ../log/debug_log 2>&1
RC=$?

if [ -n "$2" ]; then
    DB=/usr/local/reqad/db/messages.db
    DOMAIN="${1%%,*}"   # first domain in the list (strip ,www.…)
    # -init /dev/null: ignore any user ~/.sqliterc (avoids unrelated CLI noise).
    SQLITE="/usr/bin/sqlite3 -init /dev/null"
    # messages.db is created lazily by the web app; make sure the table exists.
    $SQLITE "$DB" "CREATE TABLE IF NOT EXISTS messages (token TEXT PRIMARY KEY, type TEXT NOT NULL DEFAULT 'info', message TEXT NOT NULL, seen INTEGER NOT NULL DEFAULT 0, created INTEGER NOT NULL);"
    if [ $RC -eq 0 ]; then
        $SQLITE "$DB" "INSERT OR REPLACE INTO messages (token,type,message,seen,created) VALUES ('$2','success','SSL certificate installed for $DOMAIN.',0,strftime('%s','now'));"
    else
        $SQLITE "$DB" "INSERT OR REPLACE INTO messages (token,type,message,seen,created) VALUES ('$2','error','SSL certificate could not be issued for $DOMAIN. A self-signed certificate is in use; check the SSL page.',0,strftime('%s','now'));"
    fi
fi
