#!/bin/bash
# Give /etc/aliases pipes their own exim transport.
#
# The panel's root-mail forwarder (Settings > Notifications) installs
#
#     root: "|/usr/local/reqad/scripts/forward_root_mail.php"
#
# into /etc/aliases, and the stock system_aliases router hands that pipe to the
# address_pipe transport. address_pipe picks the delivery user out of the
# recipient domain's owner:
#
#     user = ${lookup{$domain_data}lsearch* {/etc/exim/userdomains}{$value}}
#
# which is right for mail to a hosted domain and wrong for mail to root. Mail to
# root is addressed to the server itself (root@<hostname>), that domain matches
# the bare `@` in local_domains rather than dsearch;/etc/exim/domains, so
# $domain_data is empty, the lookup finds nothing, and every message defers with
#
#   Failed to find user "" from expanded string
#   "${lookup{$domain_data}lsearch* {/etc/exim/userdomains}{$value}}"
#   for the address_pipe transport
#
# The messages sit in the queue until they time out: cron output, mdadm and smart
# warnings, Reqad's own backup reports — exactly the mail you installed the
# forwarder to see.
#
# The fix is a second pipe transport with a fixed user, used by system_aliases
# only. A fixed user is safe there and nowhere else: /etc/aliases is writable by
# root alone, whereas the .forward files that keep using address_pipe belong to
# the account holders, so their pipes must keep running as the account.
#
# The user is `reqad` because that is who owns what the forwarder touches — the
# panel database it reads the SMTP credentials from, and the rate-limit counter
# it writes. Exim refuses to run a pipe as root in any case (never_users).
#
# Also chowns an existing rate-limit counter to reqad: on servers where it was
# first written by a hand-run of the script as root, the forwarder can no longer
# update it and the hourly cap stops working.
#
# Idempotent, splices on content rather than line numbers, and validates the
# result with `exim -bV -C` on a root-owned scratch copy before installing it.
#
# Usage:  bash /usr/local/reqad/scripts/update/fix_system_alias_pipe.sh [exim.conf]
#         (no argument = /etc/exim/exim.conf, and exim is reloaded on success)

set -u
export LC_ALL=C

LIVE=/etc/exim/exim.conf
TARGET=${1:-$LIVE}
STAMP=$(date +%Y%m%d%H%M%S)
RATE=/usr/local/reqad/etc/reqad_rootmail_rate
FWD=/usr/local/reqad/scripts/forward_root_mail.php

[ -f "$TARGET" ] || { echo "ERROR: $TARGET not found" >&2; exit 1; }

# Nothing to splice into: an exim.conf without the stock alias router is not
# one of ours, so leave it alone rather than guess.
grep -qE '^system_aliases:[[:space:]]*$' "$TARGET" || {
    echo "No system_aliases router in $TARGET — nothing to do."; exit 0; }

# Re-running must converge: if the transport is already defined, only the router
# line (if anything) still needs changing.
HAVE_TP=0
grep -qE '^system_alias_pipe:[[:space:]]*$' "$TARGET" && HAVE_TP=1

CAND=$(mktemp) || exit 1
BLOCK=$(mktemp) || exit 1
trap 'rm -f "$CAND" "$BLOCK"' EXIT

cat > "$BLOCK" <<'TRANSPORT'
# Pipes that come out of /etc/aliases. They cannot use address_pipe, which
# derives its user from the recipient domain's owner in /etc/exim/userdomains:
# mail to root is addressed to the server's own hostname, which has no owner, so
# the lookup yields "" and the delivery defers. /etc/aliases is root-writable
# only, so a fixed user is safe here in a way it is not for the .forward pipes
# that keep using address_pipe.
#
# return_fail_output rather than return_output: the forwarder is silent on
# success, and returning output on a success would mail it to the sender — who,
# for root's own cron mail, is root, straight back through this same alias.
system_alias_pipe:
  driver = pipe
  user = reqad
  group = reqad
  return_fail_output

TRANSPORT

# 1. point the system_aliases router at the new transport (that router only —
#    the `forwards` and `userforward` routers keep address_pipe)
# 2. define the transport just above address_pipe
awk -v blockfile="$BLOCK" -v have_tp="$HAVE_TP" '
BEGIN {
    while ((getline line < blockfile) > 0) block = block line "\n"
    close(blockfile)
    inrouter = 0; added = (have_tp == "1")
}
/^system_aliases:[[:space:]]*$/ { inrouter = 1; print; next }
inrouter && /^[^[:space:]#]/    { inrouter = 0 }
inrouter && /^[[:space:]]*pipe_transport[[:space:]]*=[[:space:]]*address_pipe[[:space:]]*$/ {
    sub(/address_pipe/, "system_alias_pipe")
}
/^address_pipe:[[:space:]]*$/ && !added { printf "%s", block; added = 1 }
{ print }
' "$TARGET" > "$CAND" || { echo "ERROR: could not rewrite $TARGET" >&2; exit 1; }

# Belt and braces: the transport must exist and the router must reference it.
grep -qE '^system_alias_pipe:[[:space:]]*$' "$CAND" || {
    echo "ERROR: system_alias_pipe transport missing from the candidate config" >&2; exit 1; }
awk '/^system_aliases:[[:space:]]*$/ { r = 1; next }
     r && /^[^[:space:]#]/          { r = 0 }
     r && /pipe_transport[[:space:]]*=[[:space:]]*system_alias_pipe/ { ok = 1 }
     END { exit ok ? 0 : 1 }' "$CAND" || {
    echo "ERROR: system_aliases still does not use system_alias_pipe" >&2; exit 1; }

if cmp -s "$CAND" "$TARGET"; then
    echo "$TARGET already routes alias pipes through system_alias_pipe — nothing to do."
else
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

    cp -a "$TARGET" "$TARGET.bak-aliaspipe-$STAMP"
    cat "$CAND" > "$TARGET"
    echo "Alias pipes now run under system_alias_pipe in $TARGET (backup: $TARGET.bak-aliaspipe-$STAMP)"

    if [ "$TARGET" = "$LIVE" ]; then
        systemctl reload exim 2>/dev/null || systemctl restart exim 2>/dev/null \
            || { echo "WARNING: could not reload exim — do it by hand." >&2; exit 1; }
        echo "exim reloaded."
    fi
fi

# The forwarder's hourly counter has to be writable by the user the pipe now
# runs as. On servers where it was first written by a hand-run as root, the
# counter stops advancing and the cap silently stops applying.
if [ -e "$RATE" ] && [ "$(stat -c %U "$RATE" 2>/dev/null)" != "reqad" ]; then
    chown reqad:reqad "$RATE" && echo "Fixed ownership of $RATE"
fi

# And exim has to be able to exec the forwarder as reqad. It stays owned by
# root — the panel also runs as reqad and must not be able to rewrite a script
# exim executes — with the group moved from exim to reqad. The matching line at
# the end of the RPM's post-install script sets the same ownership; keep the two
# together if either changes.
if [ -f "$FWD" ] && [ "$(stat -c %U:%G "$FWD" 2>/dev/null)" != "root:reqad" ]; then
    chown root:reqad "$FWD" && chmod 0750 "$FWD" && echo "Fixed ownership of $FWD"
fi

exit 0
