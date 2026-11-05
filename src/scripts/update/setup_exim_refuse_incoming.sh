#!/bin/bash
# Mailboxes that refuse incoming mail ("Refuse incoming mail" on a mailbox).
#
# The list is /etc/exim/refuse_incoming, one "address: text" line per mailbox,
# written by the panel (email_refuse_set() in app/functions/emails.php). This
# adds a router in front of the local ones that fails a listed address with
# its text:
#
#   refuse_incoming:
#     driver = redirect
#     data = :fail: <text>
#
# Exim runs the routers while the sending server names the recipient
# (`require verify = recipient` in acl_check_rcpt), so the sender gets
# "550 <text>" there and then: nothing is accepted, and this server never
# sends a bounce for it (a bounce to the forged sender of a spam is
# backscatter). Mail made on this server itself (a site's mail(), cron) and
# mail from logged-in users skips that check; it fails when it is delivered
# and its sender, a local one, gets the bounce. The mailbox itself stays:
# it can still log in and send.
#
# The router is a marker block placed right before virtual_autoreply (else
# forwards), so a refused address gets no autoresponder answer and no
# forward. Stripped and rebuilt on every run, validated with `exim -bV -C` on
# a root-owned scratch copy before it is installed. Idempotent.
#
# Usage:  bash /usr/local/reqad/scripts/update/setup_exim_refuse_incoming.sh [--dry-run] [exim.conf]
#         (no file = /etc/exim/exim.conf, and exim is reloaded on a change)

set -u
export LC_ALL=C

DRY=0
[ "${1:-}" = "--dry-run" ] && { DRY=1; shift; }
LIVE=/etc/exim/exim.conf
TARGET=${1:-$LIVE}
LIST=/etc/exim/refuse_incoming
STAMP=$(date +%Y%m%d%H%M%S)

[ "$(id -u)" -eq 0 ] || { echo "ERROR: must run as root" >&2; exit 1; }
[ -f "$TARGET" ] || { echo "No $TARGET - nothing to do."; exit 0; }

# where the block goes: before the autoresponder router, else the forwards one
ANCHOR=virtual_autoreply
grep -qE "^$ANCHOR:[[:space:]]*$" "$TARGET" || ANCHOR=forwards
grep -qE "^$ANCHOR:[[:space:]]*$" "$TARGET" || {
    echo "No virtual_autoreply or forwards router in $TARGET - not a Reqad exim.conf, left alone."; exit 0; }

# the list exists before exim reads a config that names it
if [ ! -f "$LIST" ]; then
    if [ $DRY -eq 1 ]; then echo "Would create $LIST"; else
        install -m 0644 -o root -g root /dev/null "$LIST" && echo "Created $LIST"
    fi
fi

CAND=$(mktemp) || exit 1
trap 'rm -f "$CAND" "$CAND.new"' EXIT

perl -0777 -pe '
  s/^\#\#\# --- Reqad managed \(refuse-incoming\) --- \#\#\#\n.*?^\#\#\# --- end Reqad managed \(refuse-incoming\) --- \#\#\#\n\n?//gms;
' "$TARGET" > "$CAND" || { echo "ERROR: strip failed" >&2; exit 1; }

BLOCK='### --- Reqad managed (refuse-incoming) --- ###
# Mailboxes set to refuse incoming mail: /etc/exim/refuse_incoming, one
# "address: text" line each, written by the panel. Failing here while the
# recipient is verified gives the sender "550 <text>" at RCPT time.
refuse_incoming:
  driver = redirect
  domains = +local_domains
  allow_fail
  data = ${if exists{/etc/exim/refuse_incoming}{${lookup{${lc:$local_part@$domain}}lsearch{/etc/exim/refuse_incoming}{:fail: $value}}}}
### --- end Reqad managed (refuse-incoming) --- ###
'
awk -v anchor="$ANCHOR" -v block="$BLOCK" '
  !done && $0 ~ "^" anchor ":[ \t]*$" { printf "%s\n", block; done = 1 }
  { print }
' "$CAND" > "$CAND.new" && mv "$CAND.new" "$CAND" || { echo "ERROR: insert failed" >&2; exit 1; }

[ "$(grep -c '^refuse_incoming:' "$CAND")" = 1 ] || { echo "ERROR: the router is not in the candidate exactly once" >&2; exit 1; }

if cmp -s "$CAND" "$TARGET"; then
    echo "$TARGET already has the refuse_incoming router - nothing to do."; exit 0
fi
if [ $DRY -eq 1 ]; then
    echo "Would change $TARGET:"; diff "$TARGET" "$CAND"; exit 0
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

cp -a "$TARGET" "$TARGET.bak-refuse-$STAMP"
cat "$CAND" > "$TARGET"
echo "refuse_incoming router installed in $TARGET (before $ANCHOR; backup: $TARGET.bak-refuse-$STAMP)"

if [ "$TARGET" = "$LIVE" ]; then
    systemctl reload exim 2>/dev/null || systemctl restart exim 2>/dev/null \
        || { echo "WARNING: could not reload exim - do it by hand." >&2; exit 1; }
    echo "exim reloaded."
fi
exit 0
