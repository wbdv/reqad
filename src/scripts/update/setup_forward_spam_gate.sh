#!/bin/bash
# Don't forward mail that SpamAssassin has already marked as spam.
#
# Every inbound message is scanned once, in acl_check_data, and tagged
# `X-Spam-Flag: YES` or `NO`. Anything over 20 points is refused there and
# never arrives. Everything between the threshold and 20 is accepted, and the
# stock `forwards` router then sends it on to wherever the address forwards --
# Gmail, Outlook -- from this server's IP. Those providers score the IP for the
# spam, and before long all mail from the server lands in junk or is refused.
#
# This gates the forwards router on that header. Nothing is scanned a second
# time: the router reads the verdict the DATA ACL already wrote. The router
# stays `unseen`, so the address still goes on to the routers below it:
#
#   address with a mailbox     the spam is delivered there (Junk), not forwarded
#   forward-only address       it reaches the dropper router and is discarded
#
# Neither case bounces, which matters: a bounce to the forged sender of a spam
# is backscatter.
#
# The gate is a router condition, so a suppressed forward is simply a router
# that declines: nothing is logged for it. The spam verdict itself is already in
# main.log ("SpamAssassin as <account> detected message as spam (7.6)").
# `:blackhole:` looks like the obvious way to log it and must NOT be used: a
# redirect router that accepts the address stops it there despite `unseen`, and
# the mailbox copy is lost with the forward.
#
# The switch is the macro REQAD_NO_FORWARD_SPAM (yes|no), a plain column-zero
# `NAME = value` line the panel edits through the ordinary settings path
# (Email > Exim > Settings > Forwarding). Default yes. A value already set is
# kept across a re-run.
#
# The header cannot be forged past the gate: acl_check_data strips any
# X-Spam-* header an unauthenticated sender supplies before it scans.
#
# Idempotent, splices on content rather than line numbers, and validates the
# result with `exim -bV -C` on a root-owned scratch copy before installing it.
#
# Usage:  bash /usr/local/reqad/scripts/update/setup_forward_spam_gate.sh [exim.conf]
#         (no argument = /etc/exim/exim.conf, and exim is reloaded on success)

set -u
export LC_ALL=C

LIVE=/etc/exim/exim.conf
TARGET=${1:-$LIVE}
STAMP=$(date +%Y%m%d-%H%M%S)
MACRO=REQAD_NO_FORWARD_SPAM

[ "$(id -u)" -eq 0 ] || { echo "ERROR: must run as root" >&2; exit 1; }
[ -f "$TARGET" ] || { echo "ERROR: $TARGET not found" >&2; exit 1; }

# An exim.conf without the stock forwards router is not one of ours.
grep -qE '^forwards:[[:space:]]*$' "$TARGET" || {
    echo "No forwards router in $TARGET — nothing to do."; exit 0; }

CAND=$(mktemp) || exit 1
trap 'rm -f "$CAND" "$CAND.new" "$CAND.gate"' EXIT

# Carry an admin's choice across; anything else becomes the default.
CUR=$(sed -n "s/^$MACRO[ \t]*=[ \t]*//p" "$TARGET" | head -1 | tr -d ' \t')
case "$CUR" in yes|no) ;; *) CUR=yes ;; esac

# ── 1. the macro, in its own marker block, before the first `begin` ─────────
# Stripped and rebuilt every run (with the blank line after it, so the result
# is byte-identical on a re-run). A bare line the panel may have written into
# its own managed block is removed too, or there would be two definitions and
# exim refuses a macro defined twice.
perl -0777 -pe '
  s/^\#\#\# --- Reqad managed \(forward-spam\) --- \#\#\#\n.*?^\#\#\# --- end Reqad managed \(forward-spam\) --- \#\#\#\n\n?//gms;
  s/^REQAD_NO_FORWARD_SPAM[ \t]*=.*\n//gm;
' "$TARGET" > "$CAND" || { echo "ERROR: strip failed" >&2; exit 1; }

BEGIN_AT=$(grep -nE '^begin[[:space:]]+[a-z]+' "$CAND" | head -1 | cut -d: -f1)
[ -n "$BEGIN_AT" ] || { echo "ERROR: no 'begin' section in $TARGET" >&2; exit 1; }

BLOCK="### --- Reqad managed (forward-spam) --- ###
# yes = the forwards router does not pass on mail tagged X-Spam-Flag: YES.
# Edited from the panel: Email > Exim > Settings > Forwarding.
$MACRO = $CUR
### --- end Reqad managed (forward-spam) --- ###
"
awk -v at="$BEGIN_AT" -v block="$BLOCK" 'NR == at { printf "%s\n", block } { print }' \
    "$CAND" > "$CAND.new" && mv "$CAND.new" "$CAND"

# ── 2. the forwards router's condition ──────────────────────────────────────
# The router already carries one condition, the retired spamcheck router's
# `!eq{$received_protocol}{spam-scanned}` guard (dead since that router went,
# kept here so this cannot change what it did). The gate is folded into that
# same line, so the router never depends on how exim combines two `condition`
# options.
#
# Rebuilt on every run so a re-run converges: every condition line in the
# router (continuations included) and our own comment lines are dropped, and
# the gated condition is written straight after `data`. Only lines inside the
# forwards router are touched -- from `forwards:` to the next blank line.
#
# A `data` line that mentions the macro is the short-lived first version of
# this gate, which turned spam into :blackhole:. That was wrong: a router that
# accepts an address does not pass it on despite `unseen`, so an address with
# both a mailbox and a forward lost its mailbox copy too. Put back to stock.
cat > "$CAND.gate" <<'GATE_END'
  # Reqad: skip the forward when the message is marked as spam and
  # REQAD_NO_FORWARD_SPAM is yes. Declining (not :blackhole:) keeps unseen working.
  condition = ${if or{{eq{$received_protocol}{spam-scanned}}{and{{eq{REQAD_NO_FORWARD_SPAM}{yes}}{eqi{$h_X-Spam-Flag:}{YES}}}}}{no}{yes}}
GATE_END
awk -v gf="$CAND.gate" '
  # consume a value and its continuation lines; all of its text is left in `seen`
  function swallow() { seen = $0; while ($0 ~ /\\[ \t]*$/ && (getline) > 0) seen = seen "\n" $0 }
  /^forwards:[ \t]*$/ { inr = 1; print; next }
  inr && /^[ \t]*$/   { inr = 0 }
  inr && /^[ \t]*#/ && (/REQAD_NO_FORWARD_SPAM/ || /Reqad: skip the forward/) { next }
  inr && /^[ \t]+condition[ \t]*=/ { swallow(); next }
  inr && /^[ \t]+data[ \t]*=/ {
      if ($0 ~ /REQAD_NO_FORWARD_SPAM/ || $0 ~ /\\[ \t]*$/) {
          orig = $0; swallow()
          if (seen ~ /REQAD_NO_FORWARD_SPAM/)
              print "  data = ${lookup{$local_part}lsearch{/etc/exim/forwards/${domain_data}}}"
          else { print orig; print "  UNEXPECTED_MULTILINE_DATA" }
      } else print
      while ((getline l < gf) > 0) print l; close(gf)
      next
  }
  { print }
' "$CAND" > "$CAND.new" && mv "$CAND.new" "$CAND" || { echo "ERROR: router rewrite failed" >&2; exit 1; }
rm -f "$CAND.gate"
grep -q UNEXPECTED_MULTILINE_DATA "$CAND" && {
    echo "ERROR: the forwards router has a multi-line data option this script does not know; edit it by hand" >&2; exit 1; }

# ── 3. post-conditions ──────────────────────────────────────────────────────
[ "$(grep -c "^$MACRO = " "$CAND")" = 1 ] || {
    echo "ERROR: $MACRO is not defined exactly once in the candidate" >&2; exit 1; }
awk '/^forwards:/{f=1} f&&/^[ \t]*$/{exit} f' "$CAND" | grep -qE "^[ \t]+condition = .*eq\{$MACRO\}\{yes\}" || {
    echo "ERROR: the forwards router was not gated" >&2; exit 1; }
[ "$(awk '/^forwards:/{f=1} f&&/^[ \t]*$/{exit} f' "$CAND" | grep -cE '^[ \t]+(data|condition)[ \t]*=')" = 2 ] || {
    echo "ERROR: the forwards router does not have exactly one data and one condition line" >&2; exit 1; }

if cmp -s "$CAND" "$TARGET"; then
    echo "$TARGET is already up to date — nothing to do."
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

cp -a "$TARGET" "$TARGET.bak-fwdspam-$STAMP"
cat "$CAND" > "$TARGET"
echo "Forward spam gate installed in $TARGET ($MACRO = $CUR; backup: $TARGET.bak-fwdspam-$STAMP)"

if [ "$TARGET" = "$LIVE" ]; then
    if systemctl reload exim 2>/dev/null || systemctl restart exim; then
        echo "exim reloaded."
    else
        echo "WARNING: could not reload exim — do it by hand." >&2
        exit 1
    fi
fi
