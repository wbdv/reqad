#!/bin/bash
# Accept and send mail with lines up to 10000 bytes.
#
# Exim's stock configuration refuses any message with a line longer than 998
# bytes (the RFC 5322 limit) at the end of DATA:
#
#   rejected after DATA: maximum allowed line length is 998 octets, got 1012
#
# Real mail breaks that limit all the time: Outlook writes a 1012-byte line into
# some messages with attachments, and invoicing systems and newsletters send
# HTML with lines of 1-4 KB. Other servers accept these, so after a migration
# customers suddenly could not send their invoices, and did not receive some.
#
# Exim does not re-wrap long lines, and since 4.95 an smtp transport refuses to
# send a line longer than its `message_linelength_limit` (default 998) - the
# message bounces instead. So the limit is raised in both places, to the same
# value, or mail accepted at DATA would bounce on the way out:
#
#   1. the `deny condition = ${if > {$max_received_linelength}{998}}` rule in
#      acl_check_data (and the number in its message)
#   2. every smtp transport: `message_linelength_limit = 10000`; the stock
#      `message_size_limit = ${if > {$max_received_linelength}{998} {1}{0}}`
#      trick (a 1-byte size limit for long-lined messages) is replaced by it
#
# Limits already above 10000 are left as they are. Works on the Reqad config and
# on the distro's stock exim.conf (local-only exim, email=0).
#
# Idempotent, changes only those lines, and validates the result with
# `exim -bV -C` on a root-owned scratch copy before installing it.
#
# Usage:  bash /usr/local/reqad/scripts/update/setup_exim_line_length.sh [--dry-run] [exim.conf]
#         (no path = /etc/exim/exim.conf, and exim is reloaded on success)

set -u
export LC_ALL=C

LIMIT=10000
LIVE=/etc/exim/exim.conf
DRY=0
TARGET=$LIVE
for a in "$@"; do
    case "$a" in
        --dry-run) DRY=1 ;;
        -*) echo "Usage: $0 [--dry-run] [exim.conf]" >&2; exit 2 ;;
        *) TARGET=$a ;;
    esac
done
STAMP=$(date +%Y%m%d-%H%M%S)

[ -f "$TARGET" ] || { echo "No $TARGET - nothing to do."; exit 0; }
grep -qE '^begin[[:space:]]+transports' "$TARGET" || {
    echo "No transports section in $TARGET - nothing to do."; exit 0; }

CAND=$(mktemp) || exit 1
trap 'rm -f "$CAND"' EXIT

# Transports are buffered one at a time (a transport starts at a `name:` line in
# column 0 and ends at the next one or at the next `begin`), so a smtp transport
# without a limit gets one right after its `driver = smtp` line.
awk -v limit="$LIMIT" '
function flush(   i, n) {
    if (nbuf == 0) return
    for (i = 1; i <= nbuf; i++) {
        print buf[i]
        if (smtp && !has && i == drv) {
            n = buf[i]; sub(/[^[:space:]].*$/, "", n)
            print n "message_linelength_limit = " limit
        }
    }
    nbuf = 0; smtp = 0; has = 0; drv = 0
}
/^begin[[:space:]]/ { flush(); intp = ($2 == "transports"); print; next }
!intp {
    # the ACL rule and the number in its message
    if ($0 ~ /\$max_received_linelength\}\{[0-9]+\}\}/) {
        v = $0; sub(/^.*\$max_received_linelength\}\{/, "", v); sub(/\}.*$/, "", v)
        if (v + 0 < limit + 0) {
            sub(/\$max_received_linelength\}\{[0-9]+\}\}/, "$max_received_linelength}{" limit "}}")
            old = v
        }
    }
    if (old != "" && $0 ~ ("maximum allowed line length is " old " octets"))
        sub("maximum allowed line length is " old " octets", "maximum allowed line length is " limit " octets")
    print; next
}
/^[A-Za-z0-9_]+:[[:space:]]*$/ { flush() }
{
    line = $0
    if (line ~ /^[[:space:]]*driver[[:space:]]*=[[:space:]]*smtp[[:space:]]*$/) { smtp = 1; drv = nbuf + 1 }
    if (line ~ /^[[:space:]]*message_size_limit[[:space:]]*=[[:space:]]*\$\{if > \{\$max_received_linelength\}\{[0-9]+\} \{1\}\{0\}\}[[:space:]]*$/) {
        ind = line; sub(/[^[:space:]].*$/, "", ind)
        line = ind "message_linelength_limit = " limit
    }
    if (line ~ /^[[:space:]]*message_linelength_limit[[:space:]]*=/) {
        v = line; sub(/^[^=]*=[[:space:]]*/, "", v); sub(/[[:space:]]*$/, "", v)
        if (v ~ /^[0-9]+$/ && v + 0 < limit + 0) sub(/=.*$/, "= " limit, line)
        has = 1
    }
    buf[++nbuf] = line
}
END { flush() }
' "$TARGET" > "$CAND" || { echo "ERROR: could not rewrite $TARGET" >&2; exit 1; }

# The splice must not lose a section (exim -bV passes a config without routers).
for s in routers transports; do
    if grep -qE "^begin[[:space:]]+$s" "$TARGET" && ! grep -qE "^begin[[:space:]]+$s" "$CAND"; then
        echo "ERROR: 'begin $s' missing from the candidate config, nothing changed" >&2; exit 1
    fi
done
[ "$(wc -l < "$CAND")" -ge "$(wc -l < "$TARGET")" ] || {
    echo "ERROR: the candidate config is shorter than $TARGET, nothing changed" >&2; exit 1; }

if cmp -s "$CAND" "$TARGET"; then
    echo "$TARGET already allows lines up to $LIMIT bytes - nothing to do."
    exit 0
fi

if [ $DRY -eq 1 ]; then
    diff -u "$TARGET" "$CAND"
    exit 0
fi

# exim only reads a -C config it trusts: root-owned, not group/world writable.
VDIR=/var/lib/reqad/eximcfg.$$
mkdir -p "$VDIR" && cp "$CAND" "$VDIR/exim.conf" \
  && chown -R root:root "$VDIR" && chmod 755 "$VDIR" && chmod 644 "$VDIR/exim.conf"
VOUT=$(exim -bV -C "$VDIR/exim.conf" 2>&1); VRC=$?
rm -rf "$VDIR"
if [ $VRC -ne 0 ]; then
    echo "ERROR: exim rejected the new config, nothing changed:" >&2
    echo "$VOUT" >&2
    exit 1
fi

cp -a "$TARGET" "$TARGET.bak-linelength-$STAMP"
cat "$CAND" > "$TARGET"
echo "Lines up to $LIMIT bytes are now accepted and sent in $TARGET (backup: $TARGET.bak-linelength-$STAMP)"

if [ "$TARGET" = "$LIVE" ]; then
    systemctl reload exim 2>/dev/null || systemctl restart exim 2>/dev/null \
        || { echo "WARNING: could not reload exim - do it by hand." >&2; exit 1; }
    echo "exim reloaded."
fi
exit 0
