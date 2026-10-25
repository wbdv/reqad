#!/bin/bash
# Let a hosted domain's mail be handled by another provider (Google Workspace,
# Microsoft 365, Zoho Mail, ...) while the domain stays on this server.
#
# exim treats a domain as local when /etc/exim/domains/<domain> exists, so
# mail sent FROM this server to it (a website's contact form, a mailbox here
# writing to a colleague) was delivered into the mailboxes here even after the
# domain's MX moved to the other provider. This adds a list of such domains,
# /etc/exim/remotedomains (one per line, written by the panel: Domains > DNS
# zone > Mail provider on 2083), and changes three stock lines:
#
#   domainlist local_domains = @ : localhost : localhost.localdomain : ! lsearch;/etc/exim/remotedomains : dsearch;/etc/exim/domains
#       a listed domain is not local: its mail goes to its MX
#   domainlist relay_to_domains = lsearch;/etc/exim/remotedomains
#       mail for it that still arrives here (other servers keep the old MX
#       until its TTL runs out) is taken and passed on to the new MX, instead
#       of being refused as relaying
#   dnslookup router: self = defer
#       while this server still sees the old MX (its own address), such mail
#       waits in the queue and is tried again, instead of being frozen; the
#       same for a domain whose MX points here without being hosted here
#
# Its mailboxes, forwarders and DKIM key stay as they are: the mail the server
# sends for the domain is still signed, and switching back to "This server"
# only removes the line.
#
# Changes only the stock lines (an admin's own local_domains or
# relay_to_domains is left alone), creates the list file, validates the result
# with `exim -bV -C` on a root-owned scratch copy before installing it.
# Idempotent.
#
# Usage:  bash /usr/local/reqad/scripts/update/setup_exim_remote_domains.sh [--dry-run] [exim.conf]
#         (no file = /etc/exim/exim.conf, and exim is reloaded on success)

set -u
export LC_ALL=C

DRY=0
[ "${1:-}" = "--dry-run" ] && { DRY=1; shift; }
LIVE=/etc/exim/exim.conf
TARGET=${1:-$LIVE}
LIST=/etc/exim/remotedomains
STAMP=$(date +%Y%m%d%H%M%S)
STOCK='domainlist local_domains = @ : localhost : localhost.localdomain : dsearch;/etc/exim/domains'
WANT='domainlist local_domains = @ : localhost : localhost.localdomain : ! lsearch;/etc/exim/remotedomains : dsearch;/etc/exim/domains'

[ -f "$TARGET" ] || { echo "No $TARGET - nothing to do."; exit 0; }

# the list must exist before exim reads a config that names it (lsearch on a
# missing file defers every message to a local domain)
if [ ! -f "$LIST" ]; then
    if [ $DRY -eq 1 ]; then echo "Would create $LIST"; else
        install -m 0644 -o root -g root /dev/null "$LIST" && echo "Created $LIST"
        id exim >/dev/null 2>&1 && chown exim:exim "$LIST"
    fi
fi

RSTOCK='domainlist relay_to_domains ='
RWANT='domainlist relay_to_domains = lsearch;/etc/exim/remotedomains'

if ! grep -qxF "$STOCK" "$TARGET" && ! grep -qxF "$WANT" "$TARGET"; then
    echo "local_domains in $TARGET is not the stock line - left alone. To use the Mail provider switch, add"
    echo "  ! lsearch;$LIST"
    echo "before dsearch;/etc/exim/domains in it by hand (and lsearch;$LIST to relay_to_domains)."
    exit 0
fi

CAND=$(mktemp) || exit 1
trap 'rm -f "$CAND"' EXIT
# does the dnslookup router set self itself? (a router ends at the next line
# that starts in column 1 and is not a comment)
HASSELF=$(awk '/^dnslookup:[[:space:]]*$/ { r = 1; next }
               r && /^[^[:space:]#]/ { r = 0 }
               r && /^[[:space:]]+self[[:space:]]*=/ { print 1; exit }' "$TARGET")
# 1. local_domains, 2. an empty relay_to_domains, 3. self = defer in the
#    dnslookup router, right after its domains line
awk -v stock="$STOCK" -v want="$WANT" -v rstock="$RSTOCK" -v rwant="$RWANT" -v hasself="${HASSELF:-0}" '
$0 == stock  { print want; next }
$0 == rstock { print rwant; next }
/^dnslookup:[[:space:]]*$/ { inr = 1; print; next }
inr && /^[^[:space:]#]/ { inr = 0 }
{ print }
inr && hasself != "1" && !done && /^[[:space:]]+domains[[:space:]]*=[[:space:]]*![[:space:]]*\+local_domains[[:space:]]*$/ { print "  self = defer"; done = 1 }
' "$TARGET" > "$CAND" || { echo "ERROR: could not rewrite $TARGET" >&2; exit 1; }

if cmp -s "$CAND" "$TARGET"; then
    echo "$TARGET already reads $LIST - nothing to do."; exit 0
fi
# never install a truncated file: at most one line more (self = defer)
OLD=$(wc -l < "$TARGET"); NEW=$(wc -l < "$CAND")
if [ "$NEW" -lt "$OLD" ] || [ "$NEW" -gt $((OLD + 1)) ] || ! grep -qxF "$WANT" "$CAND"; then
    echo "ERROR: the candidate config is not what was expected, nothing changed" >&2; exit 1
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

cp -a "$TARGET" "$TARGET.bak-remotedomains-$STAMP"
cat "$CAND" > "$TARGET"
echo "local_domains in $TARGET now leaves out the domains in $LIST (backup: $TARGET.bak-remotedomains-$STAMP)"

if [ "$TARGET" = "$LIVE" ]; then
    systemctl reload exim 2>/dev/null || systemctl restart exim 2>/dev/null \
        || { echo "WARNING: could not reload exim - do it by hand." >&2; exit 1; }
    echo "exim reloaded."
fi
exit 0
