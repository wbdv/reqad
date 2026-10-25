#!/bin/bash
# Make the mailbox password file readable for dovecot only.
#
# /etc/dovecot/users holds every mailbox with its password hash (SHA512-crypt)
# and was created 0644, so any hosting account - an SSH session, a cron job, a
# hacked PHP script - could copy all hashes and try to crack them offline.
# Only dovecot's auth process (user dovecot) and root (the panel, through sudo)
# read it, so it becomes 0640 root:dovecot, the same as master-users next to
# it. The panel's writes (tee -a, sed -i as root) keep the mode and owner.
#
# Copies left by the dovecot 2.3 -> 2.4 migration (/etc/dovecot.backup-*/users)
# hold the same hashes and are not read by anything: 0600 root:root.
#
# After the change dovecot must still find a mailbox (doveadm user); if it
# does not, the old mode is put back.
#
# Usage: fix_dovecot_users_perms.sh [--dry-run]

if [ "$(id -u)" -ne 0 ]; then
    echo "This script must be run as root"
    exit 1
fi
DRY=0; [ "$1" = "--dry-run" ] && DRY=1

USERS=/etc/dovecot/users
say() { echo "dovecot users: $*"; }

if [ ! -f "$USERS" ]; then
    say "$USERS not found (mail not set up) - nothing to do"
    exit 0
fi
getent group dovecot >/dev/null || { say "no dovecot group - left as it is"; exit 0; }

state() { stat -c '%a %U:%G' "$1"; }

# the main file
if [ "$(state "$USERS")" != "640 root:dovecot" ]; then
    if [ "$DRY" = 1 ]; then
        say "would change $USERS from $(state "$USERS") to 640 root:dovecot"
    else
        old_mode=$(stat -c %a "$USERS"); old_own=$(stat -c %U:%G "$USERS")
        chown root:dovecot "$USERS" && chmod 0640 "$USERS"
        # dovecot must still read it: look up the first mailbox
        first=$(awk -F: '$1 !~ /^#/ && $1 ~ /@/ {print $1; exit}' "$USERS")
        if [ -n "$first" ] && command -v doveadm >/dev/null 2>&1 && systemctl is-active --quiet dovecot \
           && ! doveadm user "$first" >/dev/null 2>&1; then
            chown "$old_own" "$USERS"; chmod "$old_mode" "$USERS"
            say "dovecot could not read $USERS as 640 root:dovecot - put back to $old_mode $old_own"
            exit 1
        fi
        say "$USERS is now 640 root:dovecot${first:+ (dovecot still finds $first)}"
    fi
fi

# leftover copies from the 2.3 -> 2.4 migration
for f in /etc/dovecot.backup-*/users; do
    [ -f "$f" ] || continue
    [ "$(state "$f")" = "600 root:root" ] && continue
    if [ "$DRY" = 1 ]; then
        say "would change $f from $(state "$f") to 600 root:root"
    else
        chown root:root "$f" && chmod 0600 "$f" && say "$f is now 600 root:root"
    fi
done
exit 0
