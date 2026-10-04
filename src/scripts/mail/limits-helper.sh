#!/bin/bash
# Reqad outbound sending limits — the privileged entry point for the counters.
#
# The counters are exim's own ratelimit hints DB (/var/spool/exim/db/ratelimit),
# written by the ACL rules that scripts/update/setup_mail_limits.sh installs.
# Nothing else keeps a count, so this is the only way for the panel to show or
# clear one.
#
# Called from PHP as `sudo -n /usr/local/reqad/scripts/mail/limits-helper.sh <verb> ...`
# with every argument escapeshellarg'd. The reqad user has NOPASSWD:ALL, so
# argument validation HERE is the only real boundary: no caller-supplied string
# is ever pasted into a key, it is REBUILT from a validated domain plus a
# fixed prefix, and every path is a constant.
#
# Verbs
#   status                     -> "installed" or "not-installed", exit 0 either way
#   list                       -> one TSV row per counter:
#                                 domain \t kind \t rate \t updated
#                                 kind is `hourly` or `failures`
#   reset <kind> <domain>      -> clear one counter
#   reset-all                  -> clear every counter
#
# A word on `rate`: exim's ratelimit is an exponentially smoothed average over
# the period, not a tally. Two messages in quick succession read as 1.92, not 2.
# It is the same number the ACL compares against the limit, so what the panel
# shows is exactly what exim is enforcing — but it is a rate, not a receipt.
#
# Exit 0 on success. On failure, exit non-zero with the reason on stderr.

set -u
export LC_ALL=C

SPOOL=/var/spool/exim
DB="$SPOOL/db/ratelimit"
DUMPDB=/usr/sbin/exim_dumpdb
FIXDB=/usr/sbin/exim_fixdb
CONF=/etc/exim/exim.conf
MARK='### --- Reqad managed (send-limits) --- ###'

die() { echo "$*" >&2; exit 1; }

# The two key shapes the ACLs write. Kept here as the single source of truth:
# a caller names a kind, never a key.
key_for() {
    case "$1" in
        hourly)   printf '1h/per_rcpt/%s' "$2" ;;
        failures) printf '1h/per_cmd/fail-%s' "$2" ;;
        *)        die "invalid counter kind" ;;
    esac
}

valid_domain() {
    case "$1" in
        *[!a-zA-Z0-9.-]* | '' | -* | .* ) return 1 ;;
    esac
    [ "${#1}" -le 253 ]
}

verb="${1-}"
[ -n "$verb" ] || die "missing verb"
shift || true

case "$verb" in

status)
    if grep -qF "$MARK" "$CONF" 2>/dev/null; then echo installed; else echo not-installed; fi
    ;;

list)
    # No DB yet simply means nothing has been counted since the last tidy —
    # an empty list, not an error.
    [ -f "$DB" ] || exit 0
    [ -x "$DUMPDB" ] || die "exim_dumpdb not found at $DUMPDB"
    "$DUMPDB" "$SPOOL" ratelimit 2>/dev/null | awk -F'key: ' '
      # 10-Sep-2026 12:28:00.552168 rate:      1.920 key: 1h/per_cmd/fail-webx.ro
      NF < 2 { next }
      {
        key = $2
        head = $1
        sub(/[ \t]+$/, "", key)
        rate = head
        sub(/^.*rate:[ \t]*/, "", rate)
        sub(/[ \t]+$/, "", rate)
        when = head
        sub(/[ \t]+rate:.*$/, "", when)
        sub(/\.[0-9]+$/, "", when)

        kind = ""
        if (key ~ /^1h\/per_rcpt\//)          { kind = "hourly";   dom = substr(key, 13) }
        else if (key ~ /^1h\/per_cmd\/fail-/) { kind = "failures"; dom = substr(key, 17) }
        else next
        if (dom == "") next
        printf "%s\t%s\t%s\t%s\n", dom, kind, rate, when
      }'
    ;;

reset)
    [ $# -ge 2 ] || die "usage: reset <hourly|failures> <domain>"
    valid_domain "$2" || die "invalid domain"
    [ -f "$DB" ] || exit 0
    [ -x "$FIXDB" ] || die "exim_fixdb not found at $FIXDB"
    k=$(key_for "$1" "$2")
    # exim_fixdb is interactive only, and takes two lines per delete: the
    # record key on its own selects the record, `d` then deletes the selected
    # one. `d <key>` is not a thing — it answers "value missing".
    out=$(printf '%s\nd\nq\n' "$k" | "$FIXDB" "$SPOOL" ratelimit 2>&1) \
        || die "exim_fixdb failed: $out"
    case "$out" in
        *"not found"*) die "no counter for that domain" ;;
        *deleted*)     ;;
        *)             die "exim_fixdb did not delete the record: $out" ;;
    esac
    ;;

reset-all)
    [ -f "$DB" ] || exit 0
    [ -x "$FIXDB" ] || die "exim_fixdb not found at $FIXDB"
    # Delete by key rather than unlinking the file: exim keeps the DB open and
    # would go on writing to a deleted inode until the next reload.
    "$DUMPDB" "$SPOOL" ratelimit 2>/dev/null \
      | sed -n 's/^.*key: \(1h\/per_\(rcpt\|cmd\)\/[A-Za-z0-9.:_-]*\)[ \t]*$/\1/p' \
      | { while read -r k; do printf '%s\nd\n' "$k"; done; printf 'q\n'; } \
      | "$FIXDB" "$SPOOL" ratelimit >/dev/null 2>&1
    ;;

*)
    die "unknown verb"
    ;;
esac
