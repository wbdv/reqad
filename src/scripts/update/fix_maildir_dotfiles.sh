#!/bin/bash
# Keep dovecot's own dotfiles out of the IMAP folder list.
#
# Reqad's layout sets mail_path = ~/ and mail_inbox_path = ~/, so a mailbox's
# HOME *is* its maildir root. Maildir++ reads every ~/.name as a folder, so
# dovecot's duplicate database showed up in every mail client as
#
#     dovecot
#     dovecot/lda-dupes
#     dovecot/lda-dupes/locks
#
# and the ".locks" one, being a real directory, was even indexed into a maildir
# of its own (cur/new/tmp, dovecot-uidlist). This is the same class of bug as
# the ~/.dovecot.sieve collision fixed when the Sieve tiers landed.
#
# Two settings fix it, both applied to /etc/dovecot/local.conf:
#   maildir { stat_dirs = yes }   the lister checks a ".name" really is a
#                                 directory, so the .dovecot.lda-dupes FILE is
#                                 no longer listed as a folder
#   mail_volatile_path            moves lock directories out of the maildir
#                                 entirely — that is what ".locks" was
#
# Duplicate suppression keeps working: verified with two identical deliveries,
# the second still logs "discarded duplicate vacation response".
#
# Safe to call from post_reqad_install.sh and safe to re-run:
#   * no-op unless /etc/dovecot/local.conf exists
#   * no-op if the settings are already there
#   * leftover .dovecot.lda-dupes.locks maildirs are removed ONLY when they hold
#     no messages; one with mail in it is reported and left alone
#   * rolls back if doveconf or dovecot reject the result
#
# Usage:  bash /usr/local/reqad/scripts/update/fix_maildir_dotfiles.sh

set -u
export LC_ALL=C

CONF=/etc/dovecot/local.conf
VOLATILE=/var/lib/reqad/volatile
STAMP=$(date +%Y%m%d-%H%M%S)

[ "$(id -u)" -eq 0 ] || { echo "ERROR: must run as root" >&2; exit 1; }

if [ ! -f "$CONF" ]; then
    echo "No $CONF — nothing to do."
    exit 0
fi

mkdir -p "$VOLATILE"
chmod 1777 "$VOLATILE"

if grep -q '^mail_volatile_path' "$CONF"; then
    echo "Volatile path already configured."
else
    cp -a "$CONF" "$CONF.bak-$STAMP"
    cat >> "$CONF" <<'BLOCK'

# Home IS the maildir root here (mail_path = ~/), so dovecot reads every ~/.name
# as an IMAP folder — which turned its own duplicate database into junk
# mailboxes visible in every client: dovecot/lda-dupes and
# dovecot/lda-dupes/locks, the latter even acquiring cur/new/tmp and an index.
#   * stat_dirs makes the lister check that a ".name" really is a directory, so
#     the .dovecot.lda-dupes FILE stops being listed as a folder.
#   * mail_volatile_path moves lock directories out of the maildir altogether,
#     which is what the ".locks" mailbox was.
maildir {
  stat_dirs = yes
}
mail_volatile_path = /var/lib/reqad/volatile/%{user}
BLOCK

    if ! doveconf -n >/dev/null 2>&1; then
        echo "doveconf rejected the new config — rolling back" >&2
        mv -f "$CONF.bak-$STAMP" "$CONF"
        exit 1
    fi
    systemctl reload dovecot 2>/dev/null || systemctl restart dovecot || {
        echo "dovecot failed to reload — rolling back" >&2
        mv -f "$CONF.bak-$STAMP" "$CONF"
        systemctl restart dovecot
        exit 1
    }
    echo "Configured. Backup: $CONF.bak-$STAMP"
fi

# ── clean up what the old layout already created ────────────────────────────
# These directories only ever held lock files; dovecot turned them into maildirs
# by listing them. Anything with actual mail in it is left alone and reported —
# a mailbox named "dovecot/lda-dupes/locks" should not contain mail, so if one
# does, something else is going on and it deserves a human.
removed=0
kept=0
while IFS= read -r d; do
    [ -n "$d" ] || continue
    n=$(find "$d/cur" "$d/new" -type f 2>/dev/null | wc -l)
    if [ "$n" -eq 0 ]; then
        rm -rf "$d"
        removed=$((removed + 1))
    else
        echo "  LEFT ALONE (holds $n message(s)): $d"
        kept=$((kept + 1))
    fi
done < <(find /home -maxdepth 5 -name '.dovecot.lda-dupes.locks' -type d 2>/dev/null)

echo "Removed $removed stale lock mailbox(es); $kept left alone."

# Subscriptions to the junk folders would resurrect them in clients' folder
# lists even though the mailboxes are gone.
subs=$(grep -rl 'lda-dupes' /home/*/mail/*/*/subscriptions 2>/dev/null || true)
if [ -n "$subs" ]; then
    echo "$subs" | while IFS= read -r f; do
        [ -n "$f" ] || continue
        sed -i.bak-"$STAMP" '/lda-dupes/d' "$f"
        echo "  cleaned subscriptions: $f"
    done
fi

echo "Done."
