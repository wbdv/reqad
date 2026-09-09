#!/bin/bash
# Put the SpamAssassin score of every inbound message in the exim main log, and
# scan as the hosting account rather than as "nobody".
#
# Two problems in the stock config, both silent:
#
#  1. The SpamAssassin section of acl_check_data ended with
#         accept
#                 log_message = "SpamAssassin score: $spam_score"
#     which logs NOTHING — exim writes log_message for warn, deny and defer,
#     never for accept. Every message was scanned and the score thrown away.
#
#  2. It scanned as the fixed user "nobody", so ~/.spamassassin/user_prefs —
#     the whitelist/blocklist the panel's Spam Filters page writes and
#     import_cpanel_spam.php imports — was read by nothing at this stage.
#
# After this script the block is cPanel-shaped: one `warn` per verdict, each
# with its own log_message, scanning as the account that owns the recipient
# domain, and adding the header set cPanel writes (and that the panel's email
# filter builder already offers):
#
#   X-Spam-Status: Yes, score=7.6      X-Spam-Score: 76   (score x 10)
#   X-Spam-Bar: ++++++                 X-Spam-Flag: YES
#   X-Spam-Report / X-Ham-Report       X-Spam-Subject: ***SPAM*** <subject>
#
#   main.log:  SpamAssassin as <account> detected message as spam (7.6)
#
# The account is captured per recipient in acl_check_rcpt: $domain exists only
# there, and by the time the DATA ACL runs a message may have several
# recipients. A message addressed to two different accounts falls back to
# nobody rather than applying one customer's whitelist to another's mail.
#
# Scanning is inbound-only: authenticated submissions and localhost injections
# accept out of the ACL before the scan, so outgoing mail is neither scored nor
# tagged. Forged X-Spam-*/X-Ham-* headers are stripped from inbound mail first,
# otherwise a spammer writes his own "X-Spam-Flag: NO" and walks past every
# user filter.
#
# It also retires the `spamcheck` router and its `spam_check` transport, which
# piped every message through `spamc -u <account>` and re-injected it with
# `exim -oMr spam-scanned -bS`. That was the ONLY place per-account preferences
# used to be applied (the ACL scanned as nobody), and it cost a second full
# scan, a second message id and a second set of log lines per message. With the
# ACL scanning as the account, the second pass is pure waste. Consequence:
# locally generated mail (cron, PHP mail(), P=local) never reaches the DATA ACL,
# so it is no longer spam scanned at all — same as cPanel.
#
# And it raises the "too large to scan" cut-off from 100 KB to 512 KB (cPanel's
# figure): at 100 KB most real mail skipped the scan and got no score at all.
#
# The script NORMALISES the block rather than patching it: it recognises both
# the stock config and a config patched by an earlier version of this script,
# and rewrites either into the current canonical text. So it is idempotent, it
# upgrades a half-applied config, and a future edit to the block only has to
# change the text below. It splices on content, not line numbers, so a config
# that has drifted elsewhere is fine. The result is validated with
# `exim -bV -C` on a root-owned scratch copy and is not installed at all if
# exim rejects it.
#
# Usage:  bash /usr/local/reqad/scripts/update/setup_spam_score_logging.sh [exim.conf]
#         (no argument = /etc/exim/exim.conf, and exim is reloaded on success)

set -u
export LC_ALL=C

TARGET=${1:-/etc/exim/exim.conf}
LIVE=/etc/exim/exim.conf
STAMP=$(date +%Y%m%d-%H%M%S)

[ "$(id -u)" -eq 0 ] || { echo "ERROR: must run as root" >&2; exit 1; }
[ -f "$TARGET" ] || { echo "ERROR: $TARGET not found" >&2; exit 1; }

# Start of the block: the stock comment, or the canonical first line once this
# script has run. End: the stock X-Spam-Report continuation, or the ham
# log_message (whose scan user differs between script versions, hence a regex).
START_OLD='  # Run SpamAssassin, but allow for it to fail or time out. Add a warning message'
START_NEW='  # SpamAssassin — inbound mail only.'
END_OLD='                       X-Spam-Report: $spam_report'
END_NEW_RE='^ +log_message = SpamAssassin as .* detected message as NOT spam'

if ! grep -qF "$START_OLD" "$TARGET" && ! grep -qF "$START_NEW" "$TARGET"; then
    echo "ERROR: SpamAssassin block not found in $TARGET (hand-customised?)" >&2
    exit 1
fi

BLOCK=$(mktemp); RCPT_BLOCK=$(mktemp); CAND=$(mktemp); TMP=$(mktemp) || exit 1
trap 'rm -f "$BLOCK" "$RCPT_BLOCK" "$CAND" "$TMP"' EXIT

cat > "$BLOCK" <<'BLOCK_END'
  # SpamAssassin — inbound mail only.
  #
  # Mail our own users submit (authenticated) and anything injected from the
  # local host is accepted here, before the scan: it is on its way OUT, and an
  # X-Spam-* header on outgoing mail only confuses the receiving side.
  #
  # Everything else is scanned once, as the hosting account that owns the
  # recipient domain, and tagged with the same header set cPanel writes — these
  # are the names the panel's email filter builder offers:
  #
  #   X-Spam-Status: Yes, score=7.6      X-Spam-Score: 76   (score x 10)
  #   X-Spam-Bar: ++++++                 X-Spam-Level: ****** (X-Spam-Flag: YES)
  #   X-Spam-Report / X-Ham-Report       X-Spam-Subject: ***SPAM*** <subject>
  #
  # Both verdicts also reach /var/log/exim/main.log as
  #   SpamAssassin as <account> detected message as (NOT) spam (<score>)
  # which is what puts the score of every inbound message in the mail log.
  # Note that log_message is only ever written for warn/deny/defer — hanging it
  # off an `accept` (as this config used to) logs nothing at all.

  # Strip any X-Spam-*/X-Ham-* header the sender supplied. Without this a
  # spammer can forge "X-Spam-Flag: NO" and walk straight past every filter.
  warn    !authenticated = *
          !hosts     = +relay_from_hosts
          remove_header = X-Spam-Subject : X-Spam-Status : X-Spam-Score : \
                          X-Spam-Bar : X-Spam-Report : X-Spam-Flag : \
                          X-Spam-Level : X-Spam-Checker-Version : \
                          X-Spam-Note : X-Ham-Report

  # Outbound / local submission: no scan, no headers, no log line.
  accept  authenticated = *
  accept  hosts      = +relay_from_hosts

  # Scan as the account the mail is addressed to, so SpamAssassin loads that
  # account's ~/.spamassassin/user_prefs — the Spam Filters whitelist and
  # blocklist. acl_m_sauser is captured per recipient in acl_check_rcpt and is
  # empty only for a message that never passed through that ACL.
  warn    set acl_m_sauser = ${if eq{$acl_m_sauser}{}{nobody}{$acl_m_sauser}}

  # Run SpamAssassin, but allow for it to fail or time out; defer_ok means a
  # dead spamd lets mail through rather than bouncing it.
  warn    spam       = ${acl_m_sauser}/defer_ok
          set acl_m_spam = yes

  accept  condition  = ${if !def:spam_score_int {1}}
          add_header = X-Spam-Note: SpamAssassin invocation failed

  # Over threshold: tag as spam and log the score.
  warn    condition  = ${if eq{$acl_m_spam}{yes}{yes}{no}}
          add_header = X-Spam-Subject: ***SPAM*** $h_Subject:
          add_header = X-Spam-Status: Yes, score=$spam_score
          add_header = X-Spam-Score: $spam_score_int
          add_header = X-Spam-Bar: $spam_bar
          add_header = X-Spam-Level: ${sg{${sg{$spam_bar}{\N[^+]\N}{}}}{\N\+\N}{*}}
          add_header = X-Spam-Report: ${sg{${from_utf8:${sg{$spam_report}{\N\n \n\N}{\n}}}}{[[:^ascii:]]}{_}}
          add_header = X-Spam-Flag: YES
          log_message = SpamAssassin as $acl_m_sauser detected message as spam ($spam_score)

  # Under threshold: same headers, ham verdict, still logged.
  warn    condition  = ${if eq{$acl_m_spam}{yes}{no}{yes}}
          add_header = X-Spam-Status: No, score=$spam_score
          add_header = X-Spam-Score: $spam_score_int
          add_header = X-Spam-Bar: $spam_bar
          add_header = X-Spam-Level: ${sg{${sg{$spam_bar}{\N[^+]\N}{}}}{\N\+\N}{*}}
          add_header = X-Ham-Report: ${sg{${from_utf8:${sg{$spam_report}{\N\n \n\N}{\n}}}}{[[:^ascii:]]}{_}}
          add_header = X-Spam-Flag: NO
          log_message = SpamAssassin as $acl_m_sauser detected message as NOT spam ($spam_score)
BLOCK_END

cat > "$RCPT_BLOCK" <<'RCPT_END'
  # Remember which hosting account this recipient belongs to, so the DATA ACL
  # can run SpamAssassin as that user and pick up its user_prefs. It has to be
  # captured HERE: $domain exists only per recipient, and by the time the DATA
  # ACL runs the message may have several.
  #
  # A message addressed to two different accounts has no single correct set of
  # preferences, so it falls back to nobody (site rules only) rather than
  # applying one customer's whitelist to another customer's mail.
  warn    set acl_m_rcptuser = ${lookup{$domain}lsearch{/etc/exim/userdomains}{$value}{nobody}}
          set acl_m_sauser   = ${if or{{eq{$acl_m_sauser}{}}{eq{$acl_m_sauser}{$acl_m_rcptuser}}}{$acl_m_rcptuser}{nobody}}

RCPT_END

# 1. Normalise the SpamAssassin block in acl_check_data.
awk -v s1="$START_OLD" -v s2="$START_NEW" -v e1="$END_OLD" -v e2re="$END_NEW_RE" -v bf="$BLOCK" '
  !skip && !done && ($0 == s1 || $0 == s2) {
      while ((getline line < bf) > 0) print line
      close(bf); skip = 1; next
  }
  skip && ($0 == e1 || $0 ~ e2re) { skip = 0; done = 1; next }
  skip { next }
  { print }
' "$TARGET" > "$CAND" || { echo "ERROR: splice failed" >&2; exit 1; }

# 2. Capture the account per recipient, at the end of acl_check_rcpt.
RCPT_ANCHOR='  # At this point, the address has passed all the checks that have been'
if ! grep -q 'set acl_m_rcptuser' "$CAND"; then
    grep -qF "$RCPT_ANCHOR" "$CAND" || { echo "ERROR: end of acl_check_rcpt not found in $TARGET" >&2; exit 1; }
    awk -v anchor="$RCPT_ANCHOR" -v bf="$RCPT_BLOCK" '
      !done && $0 == anchor { while ((getline line < bf) > 0) print line; close(bf); done = 1 }
      { print }
    ' "$CAND" > "$TMP" && cat "$TMP" > "$CAND"
fi

#    A config written by the first version of this script has a leftover rule
#    of dashes just above the block (the old block started with it); drop it so
#    every server converges on byte-identical text.
perl -0777 -i -pe 's/\n  \# -{10,}\n(  \# SpamAssassin — inbound mail only\.\n)/\n$1/' "$CAND"

# 3. Raise the "too large to scan" cut-off. At 100 KB most real mail skipped the
#    scan entirely — no score, no headers, no log line. cPanel scans to 512 KB.
sed -i 's|^  accept  condition  = ${if >={$message_size}{100000} {1}}$|  accept  condition  = ${if >={$message_size}{512000} {1}}|' "$CAND"

# 4. Retire the spamcheck router and its spam_check transport (see the comment
#    they are replaced with). Each block runs to the next blank line.
if grep -q '^spamcheck:$' "$CAND"; then
    awk '
      /^spamcheck:$/ {
          print "# The spamcheck router used to pipe EVERY message through `spamc -u <account>`"
          print "# and re-inject it with `exim -oMr spam-scanned -bS`. That second pass was the"
          print "# only place per-account SpamAssassin preferences were ever applied, because"
          print "# acl_check_data scanned as \"nobody\" — and it cost a full second scan, a second"
          print "# message id and a second set of log lines for every single message."
          print "#"
          print "# acl_check_data now scans as the account itself (acl_m_sauser), so the second"
          print "# pass bought nothing but load and doubled-up logs. Removed 2026-09-03 by"
          print "# scripts/update/setup_spam_score_logging.sh."
          print "#"
          print "# Consequence worth knowing: LOCALLY generated mail (cron, PHP mail(), P=local)"
          print "# never reaches the DATA ACL, so it is no longer spam scanned at all. That is"
          print "# what cPanel does too. It also makes the !eq{$received_protocol}{spam-scanned}"
          print "# guard on the forwards router above dead — harmless, and left alone rather"
          print "# than churn a delivery router for a comment."
          skip = 1; next
      }
      /^spam_check:$/ {
          print "# spam_check: the pipe transport for the spamcheck router above. Removed with it."
          skip = 1; next
      }
      skip && $0 == "" { skip = 0 }
      skip { next }
      { print }
    ' "$CAND" > "$TMP" && cat "$TMP" > "$CAND"
fi

# 5. Drop the dead log_message on the closing accept: exim never writes it, and
#    leaving it there makes it look as though the score is already logged.
perl -0777 -i -pe 's/\n  accept\n          log_message = "SpamAssassin score: \$spam_score"\n/\n  accept\n/' "$CAND"

for want in 'X-Spam-Bar: \$spam_bar' 'spam       = \${acl_m_sauser}/defer_ok' 'set acl_m_rcptuser' 'X-Spam-Level:'; do
    grep -q "$want" "$CAND" || { echo "ERROR: expected '$want' missing from candidate config" >&2; exit 1; }
done

grep -q '^spamcheck:$' "$CAND" && { echo "ERROR: spamcheck router still present" >&2; exit 1; }

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

cp -a "$TARGET" "$TARGET.bak-spamscore-$STAMP"
cat "$CAND" > "$TARGET"
echo "Patched $TARGET (backup: $TARGET.bak-spamscore-$STAMP)"

if [ "$TARGET" = "$LIVE" ]; then
    if systemctl reload exim 2>/dev/null || systemctl restart exim; then
        echo "exim reloaded. Per-account scores now land in /var/log/exim/main.log:"
        echo "  grep 'detected message as' /var/log/exim/main.log | tail"
    else
        echo "WARNING: could not reload exim — do it by hand." >&2
        exit 1
    fi
fi
