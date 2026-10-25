#!/bin/bash
# Stop sudo from asking sssd for its rules on servers that don't run sssd.
#
# Rocky/Alma cloud images ship the authselect profile "sssd with-sudo", which
# writes
#
#     sudoers:    files sss
#
# into /etc/nsswitch.conf, but they don't install sssd itself. Every sudo call
# then fails to read the sss source and sudo logs - and mails root -
#
#     problem with defaults entries ; PWD=... ; USER=root ;
#
# The panel and its cron jobs call sudo many times a minute, so a fresh install
# fills the mail queue with "*** SECURITY information for <host> ***" messages
# (hundreds within hours) and /var/log/secure with the same line.
#
# The fix takes sss out of the sudoers source, only when sssd is not set up
# (no sssd binary, or no sssd.conf / conf.d/*.conf): a server that really gets
# its sudo rules from LDAP/IPA through sssd is left alone.
#   - authselect profile with the with-sudo feature: `authselect disable-feature
#     with-sudo` (authselect writes nsswitch.conf; later pam/authselect updates
#     keep the choice, since it is stored in /etc/authselect/authselect.conf).
#   - nsswitch.conf not managed by authselect: the sss word is removed from the
#     sudoers line by hand (backup next to it).
#
# Idempotent: changes nothing when the sudoers line has no sss.
#
# Usage: fix_sudo_nsswitch.sh [--dry-run]

if [ "$(id -u)" -ne 0 ]; then
    echo "This script must be run as root"
    exit 1
fi

DRY=0
[ "$1" = "--dry-run" ] && DRY=1

NSS=/etc/nsswitch.conf
[ -e "$NSS" ] || { echo "sudo nsswitch: no $NSS - nothing to do"; exit 0; }

# The line nsswitch actually uses (the first one; a symlink is followed)
line=$(grep -m1 -E '^[[:space:]]*sudoers:' "$NSS")
if ! printf '%s\n' "$line" | grep -qwE 'sss'; then
    echo "sudo nsswitch: OK (${line:-no sudoers line, files only})"
    exit 0
fi

if [ -x /usr/sbin/sssd ] && { [ -s /etc/sssd/sssd.conf ] || ls /etc/sssd/conf.d/*.conf >/dev/null 2>&1; }; then
    echo "sudo nsswitch: sssd is configured here - leaving '$line' alone"
    exit 0
fi

if command -v authselect >/dev/null 2>&1 && authselect current >/dev/null 2>&1; then
    if authselect current 2>/dev/null | grep -qx -- '- with-sudo'; then
        if [ $DRY = 1 ]; then
            echo "sudo nsswitch: would run 'authselect disable-feature with-sudo' (sssd is not set up)"
            exit 0
        fi
        if authselect disable-feature with-sudo >/dev/null; then
            echo "sudo nsswitch: authselect feature with-sudo disabled (sssd is not set up) - now: $(grep -m1 -E '^[[:space:]]*sudoers:' "$NSS" || echo 'files only')"
            exit 0
        fi
        echo "sudo nsswitch: WARNING - authselect disable-feature with-sudo failed" >&2
        exit 1
    fi
    # authselect manages the file but the sss comes from somewhere else
    # (a custom profile): editing the file would be undone by the next
    # apply-changes, so only report it.
    echo "sudo nsswitch: WARNING - '$line' comes from authselect profile $(authselect current 2>/dev/null | sed -n 's/^Profile ID: //p') without with-sudo; edit the profile" >&2
    exit 0
fi

# Not managed by authselect: drop the sss word from the sudoers line
REAL=$(readlink -f "$NSS")
if [ $DRY = 1 ]; then
    echo "sudo nsswitch: would remove sss from '$line' in $REAL"
    exit 0
fi
cp -a "$REAL" "$REAL.reqad-bak-$(date +%Y%m%d%H%M%S)"
sed -i -E '/^[[:space:]]*sudoers:/{s/[[:space:]]+sss\b//g; s/(sudoers:[[:space:]]*)sss[[:space:]]*/\1/}' "$REAL"
grep -qE '^[[:space:]]*sudoers:[[:space:]]*$' "$REAL" && sed -i -E 's/^([[:space:]]*sudoers:)[[:space:]]*$/\1    files/' "$REAL"
echo "sudo nsswitch: removed sss from the sudoers line - now: $(grep -m1 -E '^[[:space:]]*sudoers:' "$REAL")"
