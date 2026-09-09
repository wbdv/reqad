#!/bin/bash
# Restore the IMAP hierarchy separator to '.' to match the Maildir++ layout.
#
# THE BUG
# Reqad stores mail as Maildir++: a folder is ~/.Name and a child of it is
# ~/.Parent.Child — '.' is the on-disk separator. Dovecot 2.3 set no separator
# and inherited '.' from the layout, so the IMAP name and the disk name agreed.
#
# migrate_dovecot_2.4.sh wrote "separator = /" into conf.d/10-mail.conf, and
# that one line breaks three things at once for every existing mailbox:
#
#   * Nested folders are renamed out from under clients: a folder every client
#     had cached as "Parent.Child" is now advertised as "Parent/Child".
#   * '.' becomes illegal in a mailbox name — it is the physical separator, so
#     the storage layer refuses it. A client asking for a name it cached before
#     the migration gets
#         Character not allowed in mailbox name: '.'
#     which Apple Mail surfaces as "could not be moved to the mailbox ...".
#   * Subscriptions written under 2.3 hold dotted names, which are now invalid
#     and get dropped from LSUB — so a client that lists by subscription sees
#     INBOX and nothing else. That is why the breakage looked random: it
#     depended on whether a given client used LIST or LSUB, and deleting and
#     re-adding the account did not help, because the bad state is server-side.
#
# THE FIX
#   1. separator = .   in /etc/dovecot/conf.d/10-mail.conf
#   2. Repair subscriptions files written while '/' was in effect: an entry
#      "Parent/Child" is rewritten to "Parent.Child". '/' can never occur in a
#      real folder name (it was the separator), so this is unambiguous, and
#      entries that already use '.' are left as they are.
#
# Safe to call from post_reqad_install.sh and safe to re-run:
#   * no-op unless /etc/dovecot/conf.d/10-mail.conf exists
#   * no-op if the separator is already '.'
#   * every file edited is backed up first
#   * rolls back if doveconf or dovecot reject the result
#
# Usage:  bash /usr/local/reqad/scripts/update/fix_imap_separator.sh

set -u
export LC_ALL=C

CONF=/etc/dovecot/conf.d/10-mail.conf
STAMP=$(date +%Y%m%d-%H%M%S)

[ "$(id -u)" -eq 0 ] || { echo "ERROR: must run as root" >&2; exit 1; }

if [ ! -f "$CONF" ]; then
    echo "No $CONF — nothing to do."
    exit 0
fi

# ── 1. the config ───────────────────────────────────────────────────────────
if ! grep -qE '^[[:space:]]*separator[[:space:]]*=[[:space:]]*/' "$CONF"; then
    echo "Separator already correct in $CONF."
else
    cp -a "$CONF" "$CONF.bak-$STAMP"
    sed -i 's|^\([[:space:]]*\)separator[[:space:]]*=[[:space:]]*/[[:space:]]*$|\1separator = .|' "$CONF"

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
    echo "Separator set to '.' in $CONF. Backup: $CONF.bak-$STAMP"
fi

# Confirm dovecot really is serving '.', not just that the file says so — a
# stray separator elsewhere in the config would win and this fix would be a lie.
eff=$(doveconf -n 2>/dev/null | awk '/^namespace inbox \{/,/^\}/' | \
      sed -n 's/^[[:space:]]*separator[[:space:]]*=[[:space:]]*\(.*\)$/\1/p')
if [ -n "$eff" ] && [ "$eff" != "." ]; then
    echo "WARNING: dovecot is still using separator '$eff' — something else in" >&2
    echo "         the config is overriding $CONF. Not touching subscriptions." >&2
    exit 1
fi

# ── 2. subscriptions written while '/' was the separator ────────────────────
# Line 1 is the "V<TAB>2" version header and blank lines are structural; only
# name lines are rewritten.
# Dovecot writes this file in two formats, and they must be treated
# differently — getting this wrong corrupts folder names:
#
#   v1  no header, first line is already a mailbox name. The namespace
#       separator is stored LITERALLY, so a file written while '/' was in
#       effect holds "Parent/Child" and does need rewriting.
#   v2  first line is "V<TAB>2". The separator is stored as a TAB, so the file
#       is separator-agnostic and is ALREADY correct — rewriting '/' here would
#       corrupt a folder whose name legitimately contains a slash.
#
# So: skip v2 entirely, and in v1 rewrite every line including the first.
fixed=0
while IFS= read -r f; do
    [ -n "$f" ] || continue

    # v2 files need no repair.
    IFS= read -r first < "$f" || continue
    [ "$first" = "$(printf 'V\t2')" ] && continue

    # Nothing to do unless a name actually contains '/'.
    grep -q '/' "$f" || continue

    cp -a "$f" "$f.bak-$STAMP"
    awk '$0=="" { print; next } { gsub(/\//, "."); print }' \
        "$f.bak-$STAMP" > "$f"
    # Preserve the mailbox owner — these files are read as the mail user.
    chown --reference="$f.bak-$STAMP" "$f"
    chmod --reference="$f.bak-$STAMP" "$f"
    echo "  repaired subscriptions: $f"
    fixed=$((fixed + 1))
done < <(find /home -maxdepth 5 -name subscriptions -type f 2>/dev/null)

echo "Repaired $fixed subscriptions file(s)."
echo
echo "Mail clients cache the separator, so each one needs a resync before the"
echo "folder list is right again: in most clients toggling the account off and"
echo "on is enough. No mail is moved or deleted by this script."
echo "Done."
