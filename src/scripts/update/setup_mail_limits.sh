#!/bin/bash
# Outbound sending limits for exim.
#
#   1. Maximum hourly email by domain relayed   (default 200)
#   2. Maximum failed messages a domain may send per hour
#
# Both count per SENDING DOMAIN, and one setting governs every domain on the
# box rather than each domain carrying its own value.
#
# The second one counts PERMANENT failures only, and that is the whole design.
# The obvious implementation — count anything that did not get delivered —
# counts deferrals too, and a deferral is re-counted on every retry: one
# message to an over-quota mailbox has the remote MTA answer 452 a dozen times
# over a couple of days, and the sending domain is throttled for something it
# did once. Here a "failure" is taken from the msg:fail:* events, which fire
# once per recipient at the moment the bounce is generated. Retries of a
# deferred message never touch the counter.
#
# What this installs, all of it inside marker blocks so it can be removed or
# re-applied cleanly:
#
#   main section    the macros the panel edits, plus acl_not_smtp and
#                   event_action
#   acl_check_rcpt  the counting and enforcement rules, spliced ABOVE the
#                   `accept hosts = +relay_from_hosts` / `accept authenticated`
#                   pair — those two accept out of the ACL, so anything below
#                   them never sees a submission, which is the only traffic
#                   worth limiting
#   new ACLs        acl_reqad_fail    (called from event_action)
#                   acl_reqad_notsmtp (called for sendmail(8) injections)
#
# Counting is exim's own `ratelimit` condition, so the state lives in exim's
# hints DB at /var/spool/exim/db/ratelimit and is tidied by the stock
# /etc/cron.daily/exim-tidydb. Nothing in the panel keeps a counter.
#
# Every tunable is an exim MACRO rather than a value baked into the ACL text.
# Macros are ordinary `NAME = value` lines at column zero, which is exactly what
# the panel's mail_setting_write() already edits, so changing a limit goes
# through the same validate / backup / revert / reload path as every other mail
# setting and this script never has to run again.
#
# Idempotent: it strips its own marker blocks first and rebuilds them, so
# re-running converges on byte-identical text and an upgrade only has to edit
# the heredocs below. Splices on content, not line numbers. The result is
# validated with `exim -bV -C` on a root-owned scratch copy and is not installed
# at all if exim rejects it. Values an admin has already set are carried across
# a rebuild rather than reset to the defaults.
#
# Three modes, set by REQAD_LIMIT_MODE:
#   freeze  accept the mail and hold it in the queue for review (default)
#   defer   451, the sending client keeps it and retries
#   deny    550, the sender gets a bounce
#
# Usage:  bash /usr/local/reqad/scripts/update/setup_mail_limits.sh [exim.conf]
#         (no argument = /etc/exim/exim.conf, and exim is reloaded on success)
#         --remove    take the blocks back out again

set -u
export LC_ALL=C

REMOVE=0
if [ "${1-}" = "--remove" ]; then REMOVE=1; shift; fi

TARGET=${1:-/etc/exim/exim.conf}
LIVE=/etc/exim/exim.conf
STAMP=$(date +%Y%m%d-%H%M%S)

[ "$(id -u)" -eq 0 ] || { echo "ERROR: must run as root" >&2; exit 1; }
[ -f "$TARGET" ] || { echo "ERROR: $TARGET not found" >&2; exit 1; }

# Anchors. Each must match exactly once, or the config has drifted somewhere we
# cannot reason about and we would rather do nothing than guess.
RCPT_ANCHOR='  accept  hosts         = +relay_from_hosts'
ACL_ANCHOR='begin routers'
MARKER='# --- managed by Reqad ---'

CAND=$(mktemp); TMP=$(mktemp); MAIN=$(mktemp); RCPT=$(mktemp); ACLS=$(mktemp) || exit 1
trap 'rm -f "$CAND" "$TMP" "$MAIN" "$RCPT" "$ACLS"' EXIT

# Values the admin has already set. The managed block is stripped and rebuilt
# from scratch on every run, so without this a package update would quietly put
# everyone's limits back to the shipped defaults -- turning a tuned server back
# to 200/25 without saying so. Anything missing or malformed falls back to the
# default; every value is re-validated here because it is about to be pasted
# into a config file.
macro_now() { sed -n "s/^$1[ \t]*=[ \t]*//p" "$TARGET" | head -1; }

MODE=$(macro_now REQAD_LIMIT_MODE)
HOURLY=$(macro_now REQAD_MAX_HOURLY)
FAILURES=$(macro_now REQAD_MAX_FAILURES)
LOCALMAIL=$(macro_now REQAD_LOCALMAIL)
EXEMPT=$(sed -n 's/^domainlist[ \t]\+reqad_nolimit[ \t]*=[ \t]*//p' "$TARGET" | head -1)

# `warn` -- count and log but deliver anyway -- was the original third mode and
# is retired: `freeze` does the same counting and logging but holds the mail
# instead of letting it go, which is what an admin wanted from it anyway.
[ "$MODE" = "warn" ] && MODE=freeze

case "$MODE" in      freeze|defer|deny) ;; *) MODE=freeze ;;    esac
case "$LOCALMAIL" in accept|warn|deny)  ;; *) LOCALMAIL=warn ;; esac
printf '%s' "$HOURLY"   | grep -qxE '[0-9]{1,7}' || HOURLY=200
printf '%s' "$FAILURES" | grep -qxE '[0-9]{1,7}' || FAILURES=25
printf '%s' "$EXEMPT"   | grep -qxE '[A-Za-z0-9.:*?+@ _/-]{0,1024}' || EXEMPT=''

# ── 1. strip any previous version of our blocks ──────────────────────────────
# Also eats the blank line that followed each block, so enable -> disable is a
# byte-identical round trip rather than a slow accumulation of empty lines.
perl -0777 -pe '
  s/^[ \t]*\#\#\# --- Reqad managed \(send-limits[^)]*\) --- \#\#\#\n.*?^[ \t]*\#\#\# --- end Reqad managed \(send-limits[^)]*\) --- \#\#\#\n\n?//gms;
' "$TARGET" > "$CAND" || { echo "ERROR: strip failed" >&2; exit 1; }

if [ "$REMOVE" -eq 1 ]; then
    if cmp -s "$CAND" "$TARGET"; then
        echo "No Reqad sending-limit blocks in $TARGET — nothing to do."
        exit 0
    fi
else

# ── 2. main-section block: macros + the two main options ─────────────────────
cat > "$MAIN" <<'MAIN_END'
### --- Reqad managed (send-limits) --- ###
# Outbound sending limits. Edited from the panel: Email > Exim > Sending limits.
# The rules that read these live in acl_check_rcpt, acl_reqad_fail and
# acl_reqad_notsmtp, all marked with the same "Reqad managed (send-limits...)"
# banner. Do not rename a macro without changing them too.
#
#   REQAD_LIMIT_MODE    freeze = accept and hold in the queue | defer = 451
#                       | deny = 550
#   REQAD_MAX_HOURLY    recipients per hour per sending domain, 0 = unlimited
#   REQAD_MAX_FAILURES  permanent delivery failures per hour, 0 = unlimited
#   REQAD_LOCALMAIL     accept | warn | deny — mail injected with sendmail(8)
#                       and unauthenticated relay from the local host
#   reqad_nolimit       sending domains exempt from all of it
REQAD_LIMIT_MODE = @MODE@
REQAD_MAX_HOURLY = @HOURLY@
REQAD_MAX_FAILURES = @FAILURES@
REQAD_LOCALMAIL = @LOCALMAIL@
domainlist reqad_nolimit = @EXEMPT@
acl_not_smtp = acl_reqad_notsmtp
event_action = ${if match{$event_name}{\N^msg:fail:\N}{${acl{acl_reqad_fail}}}}
### --- end Reqad managed (send-limits) --- ###
MAIN_END

# The heredoc is quoted so the exim expansions in it survive the shell; fill in
# the preserved values afterwards. Each has been validated above, so none of
# them can carry a sed metacharacter.
sed -i -e "s|@MODE@|$MODE|" -e "s|@HOURLY@|$HOURLY|" -e "s|@FAILURES@|$FAILURES|" \
       -e "s|@LOCALMAIL@|$LOCALMAIL|" -e "s|@EXEMPT@|$EXEMPT|" "$MAIN"

# An event_action already in use is not ours to take over — it would silently
# stop whatever it was doing. Same for acl_not_smtp.
for opt in event_action acl_not_smtp; do
    if grep -qE "^${opt}[ \t]*=" "$CAND"; then
        echo "ERROR: $opt is already set in $TARGET — remove it first, or merge by hand." >&2
        exit 1
    fi
done

if grep -qF "$MARKER" "$CAND"; then
    awk -v m="$MARKER" -v bf="$MAIN" '
      !done && $0 == m { print; print ""; while ((getline l < bf) > 0) print l; close(bf); done = 1; next }
      { print }
    ' "$CAND" > "$TMP" && cat "$TMP" > "$CAND"
else
    awk -v bf="$MAIN" '
      !done && /^begin[ \t]+[a-z]+/ {
          print "# --- managed by Reqad ---"; print ""
          while ((getline l < bf) > 0) print l
          close(bf); print ""; done = 1
      }
      { print }
    ' "$CAND" > "$TMP" && cat "$TMP" > "$CAND"
fi

# ── 3. acl_check_rcpt block ──────────────────────────────────────────────────
cat > "$RCPT" <<'RCPT_END'
  ### --- Reqad managed (send-limits rcpt) --- ###
  # Outbound rate limiting. This has to sit ABOVE the two accepts below: they
  # take submission traffic straight out of the ACL, so a limit placed after
  # them would only ever see inbound mail.
  #
  # The key is the sending domain taken from the LOGIN, not from the envelope
  # sender: untrusted_set_sender = * is set on this server, so MAIL FROM is
  # whatever the client felt like typing and would be trivial to rotate.
  warn    authenticated = *
          set acl_m_senddom = ${lc:${if match{$authenticated_id}{@}\
                                      {${domain:$authenticated_id}}\
                                      {$sender_address_domain}}}
          set acl_m_exempt  = ${if match_domain{$acl_m_senddom}{+reqad_nolimit}{yes}{no}}

  # Exactly ONE of the next three runs, chosen by the mode macro, and it both
  # counts this recipient and decides what to do about it.
  #
  # It cannot be split into "read the counter, then count" — the obvious shape,
  # with a /noupdate read first and an update afterwards. Exim caches a
  # ratelimit result per key for the whole ACL run: the second condition on the
  # same key finds a "pre-computed rate" and never touches the database, so the
  # counter silently stops incrementing and nothing is ever over the limit.
  #
  # The enforcing verbs use /leaky, so a recipient that is REFUSED is not also
  # counted — otherwise a client that retries hard keeps pushing its own rate
  # up and extends its block indefinitely. Log-only mode uses /strict, where
  # the count is a true record of what was sent and every message over the
  # limit produces a log line.
  defer   authenticated = *
          condition   = ${if eq{REQAD_LIMIT_MODE}{defer}}
          condition   = ${if eq{$acl_m_exempt}{no}}
          condition   = ${if >{REQAD_MAX_HOURLY}{0}}
          ratelimit   = REQAD_MAX_HOURLY / 1h / leaky / per_rcpt / $acl_m_senddom
          message     = Hourly outbound message limit reached for $acl_m_senddom — try again later
          log_message = REQAD LIMIT hourly: $acl_m_senddom over REQAD_MAX_HOURLY/1h (rate $sender_rate), deferred

  deny    authenticated = *
          condition   = ${if eq{REQAD_LIMIT_MODE}{deny}}
          condition   = ${if eq{$acl_m_exempt}{no}}
          condition   = ${if >{REQAD_MAX_HOURLY}{0}}
          ratelimit   = REQAD_MAX_HOURLY / 1h / leaky / per_rcpt / $acl_m_senddom
          message     = Hourly outbound message limit reached for $acl_m_senddom
          log_message = REQAD LIMIT hourly: $acl_m_senddom over REQAD_MAX_HOURLY/1h (rate $sender_rate), rejected

  # Freeze mode. The message is ACCEPTED — the sender sees 250, nothing
  # bounces and nothing is lost — and then held in the queue undelivered until
  # someone releases it from the Mail Queue page. Reversible enforcement; the
  # costs are that a flood lands in the spool instead of being turned away at
  # the door, that the sender is told nothing, and that held mail is discarded
  # once timeout_frozen_after expires (7d as shipped).
  #
  # Two ordering rules are load-bearing here. `control = freeze` must come
  # AFTER the ratelimit condition: on a warn, exim applies the modifiers it
  # reaches before a failing condition, so a control placed above the
  # ratelimit would freeze every message rather than the ones over the limit.
  # And /strict rather than the /leaky the rejecting modes use, because the
  # mail WAS accepted and so has to be counted.
  #
  # Freezing is per MESSAGE even though this ACL runs per recipient: one
  # recipient over the limit holds the whole message.
  warn    authenticated = *
          condition   = ${if eq{REQAD_LIMIT_MODE}{freeze}}
          condition   = ${if eq{$acl_m_exempt}{no}}
          condition   = ${if >{REQAD_MAX_HOURLY}{0}}
          ratelimit   = REQAD_MAX_HOURLY / 1h / strict / per_rcpt / $acl_m_senddom
          control     = freeze
          log_message = REQAD LIMIT hourly: $acl_m_senddom over REQAD_MAX_HOURLY/1h (rate $sender_rate), frozen

  # Permanent failures. These only ever READ the counter (/noupdate): it is
  # acl_reqad_fail, off the msg:fail:* events, that does the counting. Without
  # /noupdate every recipient would count as a failure.
  defer   authenticated = *
          condition   = ${if eq{REQAD_LIMIT_MODE}{defer}}
          condition   = ${if eq{$acl_m_exempt}{no}}
          condition   = ${if >{REQAD_MAX_FAILURES}{0}}
          ratelimit   = REQAD_MAX_FAILURES / 1h / per_cmd / noupdate / fail-$acl_m_senddom
          message     = Too many failed deliveries from $acl_m_senddom in the last hour — try again later
          log_message = REQAD LIMIT failures: $acl_m_senddom over REQAD_MAX_FAILURES/1h (rate $sender_rate), deferred

  deny    authenticated = *
          condition   = ${if eq{REQAD_LIMIT_MODE}{deny}}
          condition   = ${if eq{$acl_m_exempt}{no}}
          condition   = ${if >{REQAD_MAX_FAILURES}{0}}
          ratelimit   = REQAD_MAX_FAILURES / 1h / per_cmd / noupdate / fail-$acl_m_senddom
          message     = Too many failed deliveries from $acl_m_senddom in the last hour
          log_message = REQAD LIMIT failures: $acl_m_senddom over REQAD_MAX_FAILURES/1h (rate $sender_rate), rejected

  warn    authenticated = *
          condition   = ${if eq{REQAD_LIMIT_MODE}{freeze}}
          condition   = ${if eq{$acl_m_exempt}{no}}
          condition   = ${if >{REQAD_MAX_FAILURES}{0}}
          ratelimit   = REQAD_MAX_FAILURES / 1h / per_cmd / noupdate / fail-$acl_m_senddom
          control     = freeze
          log_message = REQAD LIMIT failures: $acl_m_senddom over REQAD_MAX_FAILURES/1h (rate $sender_rate), frozen

  # Unauthenticated relay from the local host. PHPMailer pointed at
  # localhost:25 is the SMTP twin of mail(), so it answers to the same switch —
  # otherwise blocking sendmail(8) just moves the spam one port over.
  deny    !authenticated = *
          hosts       = +relay_from_hosts
          condition   = ${if eq{REQAD_LOCALMAIL}{deny}}
          message     = Unauthenticated local relay is disabled on this server. Send through authenticated SMTP on port 587.
          log_message = REQAD LIMIT local: blocked unauthenticated relay from $sender_host_address <$sender_address>

  warn    !authenticated = *
          hosts       = +relay_from_hosts
          condition   = ${if eq{REQAD_LOCALMAIL}{warn}}
          log_message = REQAD LIMIT local: unauthenticated relay from $sender_host_address <$sender_address> (would be blocked)
  ### --- end Reqad managed (send-limits rcpt) --- ###

RCPT_END

grep -cxF "$RCPT_ANCHOR" "$CAND" | grep -qx 1 || {
    echo "ERROR: '$RCPT_ANCHOR' not found exactly once in $TARGET (hand-customised?)" >&2; exit 1; }

awk -v anchor="$RCPT_ANCHOR" -v bf="$RCPT" '
  !done && $0 == anchor { while ((getline l < bf) > 0) print l; close(bf); done = 1 }
  { print }
' "$CAND" > "$TMP" && cat "$TMP" > "$CAND"

# ── 4. the two new ACLs, at the end of the ACL section ───────────────────────
cat > "$ACLS" <<'ACLS_END'
### --- Reqad managed (send-limits acls) --- ###
acl_reqad_fail:
  # Called from event_action for the msg:fail:* events only — a message that
  # has PERMANENTLY failed for this recipient and is about to bounce. It fires
  # once per recipient, never again.
  #
  # Both fail events count: msg:fail:delivery is a transport that gave up (a
  # 5xx from the remote), msg:fail:internal is a failure before any transport
  # ran, which is what "Unrouteable address" — a typo'd or dead domain, the
  # commonest thing in a spam run — actually raises.
  #
  # Deferrals (msg:defer, msg:rcpt:defer) are deliberately not counted. Counting
  # them is what makes this kind of limit misfire: mail to a single over-quota
  # mailbox is retried for days, each retry counts, and a domain that sent one
  # message ends up throttled.
  #
  # This runs in the delivery process, where $authenticated_id is long gone, so
  # the key is the envelope sender domain. For anything that is not forging its
  # sender that is the same string acl_check_rcpt counted under.
  # `exim -Mrm` raises msg:fail:internal as well, with "message removed by
  # <user>" as the reason — so an admin clearing the queue from the panel's
  # Mail Queue page would otherwise count a failure against every sender in
  # it, and could trip the limit for domains that did nothing wrong.
  accept  condition = ${if match{$event_data}{\N^message removed\N}}

  warn    condition = ${if def:sender_address_domain}
          ratelimit = REQAD_MAX_FAILURES / 1h / strict / per_cmd / fail-${lc:$sender_address_domain}
  accept

acl_reqad_notsmtp:
  # Mail injected with sendmail(8): PHP's mail(), a cron job, a shell script.
  # $sender_ident is the login name of the process that called sendmail, and
  # PHP-FPM pools run as the account user, so system mail is told apart from
  # site mail without having to trust anything the message says.
  #
  # Enforced here rather than with disable_functions or sendmail_path in the
  # PHP pools, because no application can route around exim.
  accept  condition = ${if match{$sender_ident}{\N^(root|exim|mailnull|reqad|nobody)$\N}}

  deny    condition   = ${if eq{REQAD_LOCALMAIL}{deny}}
          message     = Direct mail submission is disabled on this server. Send through authenticated SMTP on localhost port 587.
          log_message = REQAD LIMIT local: blocked sendmail submission by $sender_ident <$sender_address>

  warn    condition   = ${if eq{REQAD_LOCALMAIL}{warn}}
          log_message = REQAD LIMIT local: sendmail submission by $sender_ident <$sender_address> (would be blocked)

  accept
### --- end Reqad managed (send-limits acls) --- ###

ACLS_END

grep -cxF "$ACL_ANCHOR" "$CAND" | grep -qx 1 || {
    echo "ERROR: '$ACL_ANCHOR' not found exactly once in $TARGET" >&2; exit 1; }

awk -v anchor="$ACL_ANCHOR" -v bf="$ACLS" '
  !done && $0 == anchor { while ((getline l < bf) > 0) print l; close(bf); done = 1 }
  { print }
' "$CAND" > "$TMP" && cat "$TMP" > "$CAND"

# ── 5. post-conditions ───────────────────────────────────────────────────────
for want in '^REQAD_MAX_HOURLY = ' '^REQAD_MAX_FAILURES = ' '^REQAD_LIMIT_MODE = ' \
            '^REQAD_LOCALMAIL = ' '^acl_reqad_fail:$' '^acl_reqad_notsmtp:$' \
            '^event_action = ' '^acl_not_smtp = '; do
    grep -qE "$want" "$CAND" || { echo "ERROR: expected '$want' missing from candidate config" >&2; exit 1; }
done
# The rcpt block is worthless below the accepts; prove it landed above them.
rcpt_at=$(grep -n 'Reqad managed (send-limits rcpt)' "$CAND" | head -1 | cut -d: -f1)
acc_at=$(grep -nxF "$RCPT_ANCHOR" "$CAND" | head -1 | cut -d: -f1)
[ -n "$rcpt_at" ] && [ -n "$acc_at" ] && [ "$rcpt_at" -lt "$acc_at" ] || {
    echo "ERROR: rate-limit block did not land above the relay accept" >&2; exit 1; }

fi   # end of the build half; --remove skips straight here

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

cp -a "$TARGET" "$TARGET.bak-sendlimits-$STAMP"
cat "$CAND" > "$TARGET"
if [ "$REMOVE" -eq 1 ]; then
    echo "Removed the sending-limit blocks from $TARGET (backup: $TARGET.bak-sendlimits-$STAMP)"
else
    echo "Installed sending limits in $TARGET (backup: $TARGET.bak-sendlimits-$STAMP)"
fi

if [ "$TARGET" = "$LIVE" ]; then
    if systemctl reload exim 2>/dev/null || systemctl restart exim; then
        echo "exim reloaded. Limits are in log-only mode until you change"
        echo "REQAD_LIMIT_MODE in the panel: Email > Exim > Sending limits."
        echo "  grep 'REQAD LIMIT' /var/log/exim/main.log"
    else
        echo "WARNING: could not reload exim — do it by hand." >&2
        exit 1
    fi
fi
