#!/bin/bash
echo "[delete_user.sh] delete user: $1" >> /usr/local/reqad/log/debug_log

# restart_services.sh is called in parallel from delete_account.php (sleep 3 then restart).
# Wait long enough for it to have restarted php-fpm without this user's pool,
# so the master won't respawn workers for the deleted user after we pkill.
sleep 8

pkill -9 -u "$1" 2>/dev/null

crontab -r -u "$1" 2>/dev/null

# Remove this account's Advanced Config version-history backups (panel-side).
# Sanitize to [a-z0-9] to match config_backup_dir() in functions.php.
SAFEUSER=$(echo "$1" | tr -cd 'a-z0-9')
if [ -n "$SAFEUSER" ]; then
    rm -rf "/usr/local/reqad/backup/config/$SAFEUSER" 2>/dev/null
fi

output=$(userdel "$1" 2>&1)
if id "$1" >/dev/null 2>&1; then
    echo "[delete_user.sh] userdel failed: $output" >> /usr/local/reqad/log/debug_log
else
    echo "[delete_user.sh] done" >> /usr/local/reqad/log/debug_log
fi
