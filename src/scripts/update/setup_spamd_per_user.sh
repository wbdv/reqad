#!/bin/bash
# Let spamd load per-account SpamAssassin preferences.
#
# scripts/install/install-exim-dovecot.sh used to write
#
#     SPAMDOPTIONS="-c -m5 -H -u spamd"
#
# and `-u` is the whole problem: it makes spamd DROP ROOT and run as that one
# user for every message. It can then no longer setuid to the account a scan is
# requested for, so it never reads /home/<acct>/.spamassassin/user_prefs — the
# whitelist/blocklist the panel's Spam Filters page writes (mode 0600, owned by
# the account, so an unprivileged spamd cannot even open it). Every message is
# scored against the site rules only, silently.
#
# Nothing in the logs says so, which is what makes it nasty: exim still reports
# "SpamAssassin as <account> detected message as spam", because that text comes
# from exim's own acl_m_sauser, not from anything spamd did with it.
#
# Verified on v182, same message, same spamd binary:
#     spamd as root, account has whitelist_from   ->  901.5
#     spamd started with -u,   same prefs         -> 1001.5   (prefs ignored)
#
# So spamd has to run as root and switch per request — which is exactly how
# cPanel runs it. This script strips -u/--username, -g/--groupname and
# -x/--nouser-config from SPAMDOPTIONS and restarts spamd, rolling back if it
# fails to come up.
#
# The alternative, keeping spamd unprivileged and pointing it at
# --virtual-config-dir, is deliberately NOT used: it would move preferences out
# of ~account/.spamassassin, which is where the Spam Filters page, sa_lists_put()
# and import_cpanel_spam.php all read and write them.
#
# Worth knowing: per-account bayes and auto-whitelist DBs now live in each
# account's home, so they count against that account's disk quota (cPanel has
# the same property).
#
# Pairs with setup_spam_score_logging.sh, which is what teaches exim to ask for
# the account in the first place. Either half alone does nothing useful.
#
# Usage:  bash /usr/local/reqad/scripts/update/setup_spamd_per_user.sh

set -u
export LC_ALL=C

CONF=/etc/sysconfig/spamassassin
STAMP=$(date +%Y%m%d-%H%M%S)

[ "$(id -u)" -eq 0 ] || { echo "ERROR: must run as root" >&2; exit 1; }

if ! systemctl list-unit-files spamassassin.service >/dev/null 2>&1; then
    echo "No spamassassin.service on this server — nothing to do."
    exit 0
fi

spamd_user() { ps -C spamd -o user= 2>/dev/null | head -1 | tr -d ' '; }

if [ ! -f "$CONF" ]; then
    # No options file: the packaged default already runs spamd as root.
    if [ "$(spamd_user)" = "root" ] || [ -z "$(spamd_user)" ]; then
        echo "$CONF absent and spamd is not running as a fixed user — nothing to do."
        exit 0
    fi
    echo "WARNING: spamd runs as '$(spamd_user)' but $CONF does not exist;" >&2
    echo "         per-account preferences will not load. Check the unit file." >&2
    exit 1
fi

OPTS=$(grep -m1 '^SPAMDOPTIONS=' "$CONF" | sed 's/^SPAMDOPTIONS=//; s/^"//; s/"$//')
if [ -z "$OPTS" ]; then
    echo "No SPAMDOPTIONS line in $CONF — leaving it alone."
    exit 0
fi

# Strip the options that pin spamd to one identity or switch user configs off.
NEW=$(echo " $OPTS " | sed -E "
    s/ -u +[^ ]+ / /g;         s/ --username(=| +)[^ ]+ / /g;
    s/ -g +[^ ]+ / /g;         s/ --groupname(=| +)[^ ]+ / /g;
    s/ -x / /g;                s/ --nouser-config / /g;
" | sed -E 's/^ +//; s/ +$//; s/  +/ /g')

if [ "$NEW" = "$OPTS" ]; then
    echo "spamd already runs per-account (SPAMDOPTIONS: $OPTS) — nothing to do."
    [ "$(spamd_user)" = "root" ] || echo "NOTE: spamd is running as '$(spamd_user)'; restart it to pick up $CONF." >&2
    exit 0
fi

# -c makes spamd create a missing ~/.spamassassin as the account, which is what
# the Spam Filters page then edits. Keep it if it was not already there.
case " $NEW " in *" -c "*) ;; *) NEW="-c $NEW" ;; esac

echo "SPAMDOPTIONS: \"$OPTS\""
echo "          ->  \"$NEW\""

cp -a "$CONF" "$CONF.bak-peruser-$STAMP"
if grep -q '^SPAMDOPTIONS=' "$CONF"; then
    sed -i "s|^SPAMDOPTIONS=.*|SPAMDOPTIONS=\"$NEW\"|" "$CONF"
else
    echo "SPAMDOPTIONS=\"$NEW\"" >> "$CONF"
fi

if ! systemctl restart spamassassin; then
    echo "ERROR: spamd failed to restart — rolling back $CONF" >&2
    cp -a "$CONF.bak-peruser-$STAMP" "$CONF"
    systemctl restart spamassassin
    exit 1
fi

for i in 1 2 3 4 5 6 7 8 9 10; do
    [ "$(systemctl is-active spamassassin)" = "active" ] && break
    sleep 1
done

if [ "$(systemctl is-active spamassassin)" != "active" ]; then
    echo "ERROR: spamassassin is not active after restart — rolling back" >&2
    cp -a "$CONF.bak-peruser-$STAMP" "$CONF"
    systemctl restart spamassassin
    exit 1
fi

echo "spamd restarted (backup: $CONF.bak-peruser-$STAMP)"
if [ "$(spamd_user)" = "root" ]; then
    echo "spamd now runs as root and switches per request; account preferences will load."
    echo "Check it with:  spamc -u <account> -c < /path/to/message"
else
    echo "WARNING: spamd is running as '$(spamd_user)', not root — something else" >&2
    echo "         (a systemd drop-in, or User= in the unit) is pinning it." >&2
    exit 1
fi
