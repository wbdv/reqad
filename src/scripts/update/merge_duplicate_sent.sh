#!/bin/bash
# Merge Apple Mail's "Sent Messages" into the canonical "Sent" folder.
#
# WHY THESE EXIST
# The server used to advertise no SPECIAL-USE flags, so Apple Mail did not know
# which folder was Sent and created its own "Sent Messages". Accounts that were
# read by both an iPhone and webmail therefore ended up with TWO sent folders,
# each holding half the sent mail — which is what "mail sends but is not in
# Sent" actually looks like on disk.
#
# setup_special_use.sh stops NEW mail from splitting. This merges what already
# split, so the history ends up in one place.
#
# DRY RUN BY DEFAULT. It only reports what it would do. Pass --apply to move
# mail. This is the one script here that touches messages, so:
#   * messages are MOVED with doveadm (not copied and deleted), one folder at a
#     time, and the source folder is re-counted afterwards
#   * the emptied "Sent Messages" is removed ONLY after it verifiably holds 0
#     messages; if anything is left behind the folder is kept and reported
#   * an account whose "Sent Messages" is already empty just has the empty
#     folder removed
#   * nothing is deleted from "Sent" under any circumstance
#
# Usage:
#   bash /usr/local/reqad/scripts/update/merge_duplicate_sent.sh           # report
#   bash /usr/local/reqad/scripts/update/merge_duplicate_sent.sh --apply   # do it

set -u
export LC_ALL=C

SRC="Sent Messages"
DST="Sent"
APPLY=0
[ "${1:-}" = "--apply" ] && APPLY=1

[ "$(id -u)" -eq 0 ] || { echo "ERROR: must run as root" >&2; exit 1; }
command -v doveadm >/dev/null || { echo "ERROR: doveadm not found" >&2; exit 1; }

[ "$APPLY" -eq 1 ] || echo "DRY RUN — nothing will be changed. Re-run with --apply to move mail."
echo

count_in() {  # user, mailbox -> message count ("" on error)
    doveadm mailbox status -u "$1" messages "$2" 2>/dev/null | sed -n 's/.*messages=\([0-9]*\).*/\1/p'
}

total_accounts=0
total_moved=0
problems=0

for d in /home/*/mail/*/*/; do
    [ -d "$d/.$SRC" ] || continue
    [ -d "$d/.$DST" ] || continue

    user="$(basename "$d")@$(basename "$(dirname "$d")")"
    n=$(count_in "$user" "$SRC")
    [ -n "$n" ] || { echo "  ?? $user — cannot read '$SRC', skipping"; problems=$((problems+1)); continue; }

    total_accounts=$((total_accounts + 1))
    dstn=$(count_in "$user" "$DST")
    echo "  $user: '$SRC'=$n  '$DST'=${dstn:-?}"

    if [ "$APPLY" -eq 0 ]; then
        continue
    fi

    if [ "$n" -gt 0 ]; then
        if ! doveadm move -u "$user" "$DST" mailbox "$SRC" all 2>&1; then
            echo "     move FAILED — leaving this account untouched"
            problems=$((problems+1))
            continue
        fi
        left=$(count_in "$user" "$SRC")
        if [ "${left:-1}" -ne 0 ]; then
            echo "     $left message(s) still in '$SRC' — folder KEPT, needs a look"
            problems=$((problems+1))
            continue
        fi
        total_moved=$((total_moved + n))
        echo "     moved $n message(s) -> '$DST'"
    fi

    # Only ever reached when '$SRC' is verified empty.
    if doveadm mailbox delete -u "$user" "$SRC" >/dev/null 2>&1; then
        echo "     removed empty '$SRC'"
    else
        echo "     could not remove '$SRC' (kept)"
        problems=$((problems+1))
    fi
done

echo
echo "Accounts with both folders: $total_accounts"
[ "$APPLY" -eq 1 ] && echo "Messages moved: $total_moved"
[ "$problems" -gt 0 ] && echo "Needs attention: $problems"
[ "$APPLY" -eq 0 ] && echo "Re-run with --apply to perform the merge."
echo "Done."
