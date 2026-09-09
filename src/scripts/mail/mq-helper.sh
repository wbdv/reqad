#!/bin/bash
# Reqad mail queue — the single privileged entry point for exim queue operations.
#
# Called from PHP as `sudo -n /usr/local/reqad/scripts/mail/mq-helper.sh <verb> ...`
# with every argument escapeshellarg'd. The reqad user has NOPASSWD:ALL, so
# argument validation HERE is the only real boundary — no caller-supplied string
# ever reaches exim unvalidated, and every path is a fixed constant.
#
# Verbs
#   count                          -> number of messages in the queue (exim -bpc)
#   list [all|frozen] [pattern]    -> one TSV row per message:
#                                     id \t age \t size \t sender \t frozen \t rcpt,rcpt
#                                     `pattern` is a substring matched against
#                                     sender and recipients (case-insensitive).
#   view <id>                      -> headers, then a blank line, then the body
#                                     (body capped at 300 lines / 64 KB)
#   subjects <id> [<id> ...]       -> one "id \t subject" row per message, for
#                                     the rows currently on screen only
#   deliver <id>                   -> force a delivery attempt, verbose log on stdout
#   remove <id>                    -> delete the message from the queue
#   freeze <id> | thaw <id>        -> exim -Mf / -Mt
#   runq                           -> run the whole queue, forcing frozen messages
#   purge-frozen                   -> remove every frozen message
#
# Exit 0 on success. On failure, exit non-zero with the reason on stderr.

set -u
export LC_ALL=C

EXIM="/usr/sbin/exim"
EXIQGREP="/usr/sbin/exiqgrep"
BODY_MAX=65536
BODY_MAX_LINES=300

die() { echo "$*" >&2; exit 1; }

[ -x "$EXIM" ] || die "exim not found at $EXIM"

# ── validation ───────────────────────────────────────────────────────────────
# Exim message IDs are three base-62 chunks. Widths changed in 4.97 (the middle
# chunk grew), so match generously on length but strictly on the alphabet.
valid_msgid() {
    case "$1" in
        *[!A-Za-z0-9-]* ) return 1 ;;
    esac
    printf '%s' "$1" | grep -qE '^[A-Za-z0-9]{6}-[A-Za-z0-9]{6,20}-[A-Za-z0-9]{2,6}$'
}

need_msgid() {
    [ $# -ge 1 ] || die "missing message id"
    valid_msgid "$1" || die "invalid message id"
}

# ── verbs ────────────────────────────────────────────────────────────────────
verb="${1-}"
[ -n "$verb" ] || die "missing verb"
shift || true

case "$verb" in

count)
    "$EXIM" -bpc 2>/dev/null || die "could not read the queue"
    ;;

list)
    filter="${1-all}"
    pattern="${2-}"
    case "$filter" in
        all|frozen) ;;
        *) die "invalid filter" ;;
    esac

    # exiqgrep -z narrows to frozen without us having to re-implement the
    # spool scan; -bp is still the source of the row detail either way.
    if [ "$filter" = frozen ]; then
        [ -x "$EXIQGREP" ] || die "exiqgrep not found"
        ids=$("$EXIQGREP" -z -i 2>/dev/null)
        [ -n "$ids" ] || exit 0
    fi

    "$EXIM" -bp 2>/dev/null | awk -v want="$filter" -v pat="$pattern" '
        function flush(   rc) {
            if (id == "") return
            if (want == "frozen" && frozen == 0) { reset(); return }
            rc = rcpt
            if (pat != "") {
                hay = tolower(sender " " rc)
                if (index(hay, tolower(pat)) == 0) { reset(); return }
            }
            printf "%s\t%s\t%s\t%s\t%s\t%s\n", id, age, size, sender, frozen, rc
            reset()
        }
        function reset() { id=""; age=""; size=""; sender=""; frozen=0; rcpt="" }
        BEGIN { reset() }
        # exim -bp formats a message as:
        #   "39h   626 1x0Nen-...-3Egi <sender> (user) *** frozen ***"
        # followed by its recipients, indented by 10 spaces. The age field is
        # RIGHT-ALIGNED, so anything under 10 units starts with a space (" 0m
        # 30K ...") -- matching /^[0-9]/ silently dropped every message younger
        # than ten minutes, which are exactly the ones worth looking at. Split
        # on indent depth instead, which does not depend on the age format.
        {
            t = $0
            sub(/^[[:space:]]+/, "", t)
            if (t == "") { next }
            indent = length($0) - length(t)
        }
        indent < 8 {
            nf = split(t, F, /[[:space:]]+/)
            if (nf < 3) next
            flush()
            age  = F[1]
            size = F[2]
            id   = F[3]
            sender = (nf >= 4) ? F[4] : ""
            gsub(/^</, "", sender); gsub(/>$/, "", sender)
            if (sender == "") sender = "<>"
            frozen = (index($0, "*** frozen ***") > 0) ? 1 : 0
            next
        }
        # recipient lines are indented; "D " marks one already delivered
        {
            if (id == "") next
            line = t
            sub(/^D /, "", line)
            if (line == "") next
            rcpt = (rcpt == "") ? line : rcpt "," line
            next
        }
        END { flush() }
    '
    ;;

view)
    need_msgid "${1-}"
    "$EXIM" -Mvh "$1" 2>&1 || die "could not read the message headers"
    echo
    # a queued message can be enormous; cap by lines as well as bytes so the
    # modal stays readable and the JSON stays small
    "$EXIM" -Mvb "$1" 2>&1 | head -n "$BODY_MAX_LINES" | head -c "$BODY_MAX"
    ;;

subjects)
    # Only ever called for one page of rows, never the whole queue: this is a
    # spool read per message, unlike `list`, which is a single exim -bp.
    [ $# -le 200 ] || die "too many message ids"
    for m in "$@"; do
        valid_msgid "$m" || continue
        # spool header lines are "NNN<flag> Header: value"; take the first
        # Subject line only (a folded header continues on indented lines)
        subj=$("$EXIM" -Mvh "$m" 2>/dev/null \
               | sed -n 's/^[0-9][0-9][0-9]. Subject: //p' \
               | head -1 \
               | tr -d '\t\r')
        printf '%s\t%s\n' "$m" "$subj"
    done
    ;;

deliver)
    need_msgid "${1-}"
    # -v puts the delivery transcript on stderr; the caller wants to see it, so
    # fold it into stdout. A failed delivery is normal output, not an error.
    "$EXIM" -v -M "$1" 2>&1
    exit 0
    ;;

remove)
    need_msgid "${1-}"
    "$EXIM" -Mrm "$1" 2>&1 || die "could not remove the message"
    ;;

freeze)
    need_msgid "${1-}"
    "$EXIM" -Mf "$1" 2>&1 || die "could not freeze the message"
    ;;

thaw)
    need_msgid "${1-}"
    "$EXIM" -Mt "$1" 2>&1 || die "could not thaw the message"
    ;;

runq)
    # -qff forces a run over every message, frozen ones included.
    "$EXIM" -qff 2>&1
    exit 0
    ;;

purge-frozen)
    [ -x "$EXIQGREP" ] || die "exiqgrep not found"
    ids=$("$EXIQGREP" -z -i 2>/dev/null)
    [ -n "$ids" ] || { echo "0"; exit 0; }
    n=0
    for m in $ids; do
        valid_msgid "$m" || continue
        "$EXIM" -Mrm "$m" >/dev/null 2>&1 && n=$((n + 1))
    done
    echo "$n"
    ;;

*)
    die "unknown verb: $verb"
    ;;
esac
