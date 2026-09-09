#!/bin/bash
# Advertise SPECIAL-USE mailbox flags (RFC 6154) to mail clients.
#
# THE BUG
# Reqad's dovecot config declared no `mailbox` blocks at all, so the server told
# clients nothing about which folder is Sent, Drafts, Trash or Junk. Every
# client then has to GUESS, and they guess differently:
#
#   * Apple Mail does not guess — it creates its own set: "Sent Messages",
#     "Deleted Messages", "Notes". So mail sent from an iPhone lands in
#     "Sent Messages" while the user and webmail look in "Sent" and find it
#     empty. That is the "sent mail is missing" complaint, and it also leaves
#     accounts carrying two Sent folders with half the mail in each.
#   * Roundcube uses its own configured names, so webmail and phone disagree
#     about the same account.
#
# Declaring the flags makes every client use the SAME folders, and is also what
# lets a client show the right icon per folder.
#
# CHOICE OF JUNK FOLDER
# Both `spam` (cPanel-era, and where the mail actually is) and `Junk` (created
# by newer clients) exist in the wild. `spam` is flagged \Junk because that is
# where spam has actually been accumulating — on the server this was written
# for, `spam` held 2612 messages against 231 in `Junk`. Nothing is moved or
# deleted either way; a client that was using `Junk` simply starts using `spam`,
# and the old folder keeps its mail. Swap the two names below if a given server
# is the other way round.
#
# Safe to call from post_reqad_install.sh and safe to re-run:
#   * no-op unless /etc/dovecot/conf.d/10-mail.conf exists
#   * no-op if special_use is already declared
#   * no-op (with a warning) if there is no `namespace inbox` block to edit
#   * backs the file up first and rolls back if doveconf or dovecot object
#   * `auto = subscribe` CREATES a missing folder on first access; it never
#     deletes, renames or moves anything
#
# Usage:  bash /usr/local/reqad/scripts/update/setup_special_use.sh

set -u
export LC_ALL=C

CONF=/etc/dovecot/conf.d/10-mail.conf
STAMP=$(date +%Y%m%d-%H%M%S)

[ "$(id -u)" -eq 0 ] || { echo "ERROR: must run as root" >&2; exit 1; }

if [ ! -f "$CONF" ]; then
    echo "No $CONF — nothing to do."
    exit 0
fi

if grep -q 'special_use' "$CONF"; then
    echo "SPECIAL-USE flags already declared in $CONF."
    exit 0
fi

if ! grep -qE '^[[:space:]]*namespace[[:space:]]+inbox[[:space:]]*\{' "$CONF"; then
    echo "WARNING: no 'namespace inbox' block in $CONF — not editing it." >&2
    exit 0
fi

cp -a "$CONF" "$CONF.bak-$STAMP"

# Rewrite the namespace block, PRESERVING whatever separator is configured
# rather than assuming one — a server still on the old '/' needs
# fix_imap_separator.sh, and silently rewriting it here would hide that.
python3 - "$CONF" <<'PY'
import re, sys

path = sys.argv[1]
src  = open(path).read()

m = re.search(r'^[ \t]*namespace[ \t]+inbox[ \t]*\{.*?^\}[ \t]*$',
              src, re.S | re.M)
if not m:
    sys.exit("could not match the namespace inbox block")
old = m.group(0)

sep = re.search(r'^\s*separator\s*=\s*(\S+)\s*$', old, re.M)
sep = sep.group(1) if sep else '.'

# name, special-use flag, whether to auto-create+subscribe it
boxes = [
    ("Drafts",  r"\Drafts",  True),
    ("Sent",    r"\Sent",    True),
    ("Trash",   r"\Trash",   True),
    ("spam",    r"\Junk",    True),
    # Archive is flagged but NOT auto-created: only some accounts have one and
    # there is no reason for an update to conjure it into the rest.
    ("Archive",  r"\Archive", False),
    # Thunderbird's default archive folder is "Archives", not "Archive". Flag it
    # too so an account that already has one is not left with an unrecognised
    # folder. A second \Archive is harmless because archiving is user-initiated
    # — unlike \Sent, which a client WRITES to on every send, so "Sent Messages"
    # is deliberately NOT flagged here: two \Sent folders would let clients keep
    # splitting sent mail. Merge that one instead (merge_duplicate_sent.sh).
    ("Archives", r"\Archive", False),
]

body = ["namespace inbox {", "   separator = %s" % sep, "   inbox = yes"]
for name, use, auto in boxes:
    body.append("")
    body.append("   mailbox %s {" % name)
    body.append("      special_use = %s" % use)
    if auto:
        body.append("      auto = subscribe")
    body.append("   }")
body.append("}")

open(path, "w").write(src.replace(old, "\n".join(body)))
PY

if [ $? -ne 0 ]; then
    echo "rewrite failed — rolling back" >&2
    mv -f "$CONF.bak-$STAMP" "$CONF"
    exit 1
fi

if ! doveconf -n >/dev/null 2>&1; then
    echo "doveconf rejected the new config — rolling back" >&2
    doveconf -n 2>&1 | grep -i fatal >&2
    mv -f "$CONF.bak-$STAMP" "$CONF"
    exit 1
fi

systemctl reload dovecot 2>/dev/null || systemctl restart dovecot || {
    echo "dovecot failed to reload — rolling back" >&2
    mv -f "$CONF.bak-$STAMP" "$CONF"
    systemctl restart dovecot
    exit 1
}

echo "SPECIAL-USE flags declared. Backup: $CONF.bak-$STAMP"
doveconf -n 2>/dev/null | awk '/^namespace inbox \{/,/^\}/'
echo
echo "Clients cache the folder list — each needs a resync (toggle the account"
echo "off and on) before it starts using the flagged folders."
echo "Done."
