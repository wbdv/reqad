#!/bin/bash
# Refuse mail whose sender domain is on the Spamhaus Domain Block List (DBL),
# and make every Spamhaus IP blocklist count only real listings.
#
# 1. Sender domain check. A block of its own at the top of acl_check_data asks
#    dbl.spamhaus.org about the envelope sender's domain. A spammer can move to
#    new IP addresses within the hour (they are on no IP list yet), but the
#    domain in the mail stays the same. Only these answers count:
#        127.0.1.2 spam domain   127.0.1.4 phishing
#        127.0.1.5 malware       127.0.1.6 botnet controller
#    "Abused legitimate" domains (127.0.1.102-106: a real site that was hacked)
#    and error answers (127.0.1.255, 127.255.255.x) are ignored. Skipped for:
#    mail without a sender (bounces), logged-in users, +relay_from_hosts and -
#    when the server uses it - the skip-RBL list (/etc/exim/skiprblhosts).
#    A DNS failure counts as "not listed" (exim's default), so mail keeps flowing.
#
# 2. Answer filter on Spamhaus IP lists. A Spamhaus list queried through a
#    public resolver (8.8.8.8, 1.1.1.1...) either answers nothing or answers an
#    error code such as 127.255.255.254 - and exim counts ANY answer as a
#    listing, so one such answer would refuse all incoming mail. Every bare
#    zen/sbl/xbl/pbl item in a `dnslists =` line gets `=<real listing codes>`.
#    A filter already written by hand is left alone.
#
# 3. A `dbl.spamhaus.org...` item found in any other dnslists line is taken out
#    (the domain check lives in its own block). Asked about an IP address, DBL
#    answers 127.0.1.255 for everything, which would refuse all mail.
#
# On/off: the setting `exim-dbl` (off = no block). The panel switch (Email >
# Exim > Blocklists) saves it and runs this script; --on / --off do the same
# from the shell. Without either the script follows the setting (default on),
# which is how post-install calls it, so an admin's "off" survives updates.
#
# Idempotent, splices on content, validated with `exim -bV -C` on a root-owned
# copy before anything is installed, backup exim.conf.bak-dbl-<time>.
#
# Usage:  bash setup_exim_dbl.sh [--on|--off] [exim.conf]
#         (no file = /etc/exim/exim.conf, and exim is reloaded on a change)

set -u
export LC_ALL=C

LIVE=/etc/exim/exim.conf
DB=/usr/local/reqad/db/reqad.db
SQL="/usr/bin/sqlite3 -init /dev/null -batch -noheader -list"
SKIPRBL=/etc/exim/skiprblhosts
STAMP=$(date +%Y%m%d-%H%M%S)

WANT=''
TARGET=$LIVE
for a in "$@"; do
    case "$a" in
        --on)  WANT=on ;;
        --off) WANT=off ;;
        -h|--help) sed -n '2,38p' "$0"; exit 0 ;;
        -*) echo "unknown option: $a" >&2; exit 2 ;;
        *)  TARGET=$a ;;
    esac
done

[ "$(id -u)" -eq 0 ] || { echo "ERROR: must run as root" >&2; exit 1; }
[ -f "$TARGET" ] || { echo "No $TARGET - nothing to do."; exit 0; }
grep -qE '^acl_check_data:[[:space:]]*$' "$TARGET" || {
    echo "No acl_check_data in $TARGET - not a Reqad exim.conf, nothing to do."; exit 0; }

# the setting: --on/--off save it (as reqad: the panel owns the database file)
if [ -n "$WANT" ] && [ -f "$DB" ]; then
    runuser -u reqad -- $SQL "$DB" "INSERT INTO settings (name, value) VALUES ('exim-dbl', '$WANT')
        ON CONFLICT(name) DO UPDATE SET value = excluded.value, updated_at = CURRENT_TIMESTAMP" \
        || { echo "ERROR: could not save the setting exim-dbl" >&2; exit 1; }
fi
if [ -z "$WANT" ]; then
    WANT=$([ -f "$DB" ] && $SQL "$DB" "SELECT value FROM settings WHERE name = 'exim-dbl'" 2>/dev/null)
    [ "$WANT" = off ] || WANT=on
fi

CAND=$(mktemp) || exit 1
trap 'rm -f "$CAND" "$CAND.new"' EXIT

# ── 1+3. strip our block, then fix the other dnslists lines ─────────────────
# perl keeps it in one pass: the block (markers included) goes, and in every
# uncommented `dnslists =` line DBL items are dropped and bare Spamhaus IP
# lists get their answer filter. A line left with no items is commented out,
# the same way the panel disables a list.
perl -0777 -pe '
  s/^[ \t]*\#\#\# --- Reqad managed \(sender-domain-dbl\) --- \#\#\#\n.*?^[ \t]*\#\#\# --- end Reqad managed \(sender-domain-dbl\) --- \#\#\#\n//gms;
  my $zen = "127.0.0.2,127.0.0.3,127.0.0.4,127.0.0.5,127.0.0.6,127.0.0.7,127.0.0.9,127.0.0.10,127.0.0.11";
  s{^([ \t]*)dnslists([ \t]*=[ \t]*)(.*)$}{
      my ($ind, $eq, $list) = ($1, $2, $3);
      my @out;
      foreach my $i (split /\s*:\s*/, $list) {
          $i =~ s/^\s+|\s+$//g;
          next if $i eq "";
          next if $i =~ m{^dbl\.spamhaus\.org(?:[=/!&]|$)}i;
          $i .= "=$zen" if $i =~ m{^(?:[a-z0-9-]+\.)?(?:zen|sbl|xbl|pbl)\.(?:spamhaus\.org|dq\.spamhaus\.net)$}i;
          push @out, $i;
      }
      my $new = @out ? "${ind}dnslists = " . join(" : ", @out) : "${ind}# dnslists =   (disabled by Reqad)";
      my $orig_items = join(" : ", grep { $_ ne "" } map { my $x = $_; $x =~ s/^\s+|\s+$//g; $x } split /\s*:\s*/, $list);
      (@out && join(" : ", @out) eq $orig_items) ? "${ind}dnslists${eq}${list}" : $new;
  }gme;
' "$TARGET" > "$CAND" || { echo "ERROR: rewrite failed" >&2; exit 1; }

# ── 2. the block, straight after `acl_check_data:` ──────────────────────────
if [ "$WANT" = on ]; then
    # the skip-RBL guard only when the server uses it (the panel adds or removes
    # it on every dnslists line at once: Email > Exim > Blocklists > Trusted senders)
    GUARD=''
    if [ -f "$SKIPRBL" ] && grep -qE "^[[:space:]]*!hosts[[:space:]]*=[[:space:]]*$SKIPRBL[[:space:]]*$" "$CAND"; then
        GUARD="          # exempt known senders before the lookup (Reqad: skip-RBL)
          !hosts = $SKIPRBL
"
    fi
    BLOCK="  ### --- Reqad managed (sender-domain-dbl) --- ###
  # Sender domain on the Spamhaus Domain Block List: spam, phishing, malware,
  # botnet. Hacked real sites (127.0.1.1xx) and error answers do not count.
  # Switch: Email > Exim > Blocklists, or scripts/update/setup_exim_dbl.sh --off
  deny    message    = rejected because the sender domain \$sender_address_domain is listed at \$dnslist_domain see \$dnslist_text
          log_message = sender domain \$sender_address_domain listed at \$dnslist_domain (\$dnslist_value)
          !authenticated = *
          !hosts = +relay_from_hosts
          condition = \${if def:sender_address_domain}
${GUARD}          dnslists = dbl.spamhaus.org=127.0.1.2,127.0.1.4,127.0.1.5,127.0.1.6/\$sender_address_domain
  ### --- end Reqad managed (sender-domain-dbl) --- ###
"
    awk -v block="$BLOCK" '{ print } /^acl_check_data:[ \t]*$/ && !done { printf "%s", block; done = 1 }' \
        "$CAND" > "$CAND.new" && mv "$CAND.new" "$CAND" || { echo "ERROR: insert failed" >&2; exit 1; }
    [ "$(grep -c '### --- Reqad managed (sender-domain-dbl)' "$CAND")" = 1 ] || {
        echo "ERROR: the block is not in the candidate exactly once" >&2; exit 1; }
fi

if cmp -s "$CAND" "$TARGET"; then
    echo "$TARGET is already up to date (sender domain check: $WANT) - nothing to do."
    exit 0
fi

# exim only reads a -C config it trusts: root-owned, not group/world writable
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

cp -a "$TARGET" "$TARGET.bak-dbl-$STAMP"
cat "$CAND" > "$TARGET"
echo "Sender domain check $WANT, Spamhaus answer filter applied in $TARGET (backup: $TARGET.bak-dbl-$STAMP)"

if [ "$TARGET" = "$LIVE" ]; then
    if systemctl reload exim 2>/dev/null || systemctl restart exim; then
        echo "exim reloaded."
    else
        echo "WARNING: could not reload exim - do it by hand." >&2
        exit 1
    fi
fi
