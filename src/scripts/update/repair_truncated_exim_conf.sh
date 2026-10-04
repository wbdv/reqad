#!/bin/bash
# Restore an exim.conf that lost its routers and transports.
#
# setup_spam_score_logging.sh used to splice from the SpamAssassin header
# comment to the X-Spam-Report line. On the distro's stock exim.conf — the
# local-only exim of an email=0 server — that whole section is a commented-out
# example, the end line never matched, and everything from there to EOF was
# dropped: the rest of acl_check_data, every router, every transport. `exim -bV`
# accepts a config with no routers, so it was installed, and from then on every
# address was "Unrouteable address" — root's mail never reached the
# /etc/aliases pipe, and senders failed verification ("Sender verify failed").
#
# The script took a backup before writing (exim.conf.bak-spamscore-*), and every
# other Reqad exim update script does the same, so the last good state is on
# disk. This picks the newest backup (by mtime: cp -a keeps the mtime of the
# config it copied) that still has a routers section and that exim accepts, and
# puts it back. The update scripts that run after this one in the post-install
# are idempotent and re-apply whatever came later (sending limits, alias pipe
# transport), now to a complete config.
#
# Does nothing when exim.conf has its routers section.
#
# Usage:  bash /usr/local/reqad/scripts/update/repair_truncated_exim_conf.sh [exim.conf]
#         (no argument = /etc/exim/exim.conf, and exim is reloaded on success)

set -u
export LC_ALL=C

LIVE=/etc/exim/exim.conf
TARGET=${1:-$LIVE}
STAMP=$(date +%Y%m%d-%H%M%S)

[ "$(id -u)" -eq 0 ] || { echo "ERROR: must run as root" >&2; exit 1; }
[ -f "$TARGET" ] || exit 0

grep -q '^begin routers' "$TARGET" && exit 0

echo "WARNING: $TARGET has no routers section — looking for the last good backup."

GOOD=""
VDIR=/var/lib/reqad/eximcfg.$$
while IFS= read -r f; do
    grep -q '^begin routers' "$f" && grep -q '^begin transports' "$f" || continue
    # exim only reads a -C config it trusts: root-owned, not group/world writable.
    mkdir -p "$VDIR" && cp "$f" "$VDIR/exim.conf" \
      && chown -R root:root "$VDIR" && chmod 755 "$VDIR" && chmod 644 "$VDIR/exim.conf"
    exim -bV -C "$VDIR/exim.conf" >/dev/null 2>&1; VRC=$?
    rm -rf "$VDIR"
    [ $VRC -eq 0 ] && { GOOD=$f; break; }
done < <(ls -1t "$TARGET".bak-* "$TARGET".pre-* 2>/dev/null)

if [ -z "$GOOD" ]; then
    echo "ERROR: no usable backup of $TARGET found. All mail on this server is" >&2
    echo "       unrouteable until exim.conf is restored by hand." >&2
    exit 1
fi

cp -a "$TARGET" "$TARGET.bak-truncated-$STAMP"
cat "$GOOD" > "$TARGET"
echo "Restored $TARGET from $GOOD (broken copy kept as $TARGET.bak-truncated-$STAMP)"

if [ "$TARGET" = "$LIVE" ]; then
    systemctl reload exim 2>/dev/null || systemctl restart exim 2>/dev/null \
        || { echo "WARNING: could not reload exim — do it by hand." >&2; exit 1; }
    echo "exim reloaded. Frozen mail can be retried with: exim -qff"
fi

exit 0
