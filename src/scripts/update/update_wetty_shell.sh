#!/bin/bash
# Keep an existing wetty install pointed at Reqad's packaged entrypoint.
#
# wetty is started with a fixed "-c <script>" and gets no per-request context,
# so it used to open an unconditional root shell. The terminal page now picks
# the target user first and hands it off through /run/reqad/terminal-target;
# scripts/wetty_shell.sh consumes it and su's.
#
# Two migrations happen here, both one-time and both idempotent:
#
#   1. The entrypoint used to be an untracked wrapper at /root/wetty/shell.sh.
#      The unit now calls the packaged script directly; the wrapper is removed.
#   2. The unit used to live in /etc/systemd/system and launch wetty through
#      "pnpm start". The RPM now ships /usr/lib/systemd/system/wetty.service
#      which runs node directly. A unit in /etc overrides /usr/lib, so the old
#      one is backed up and removed to let the packaged unit take effect.
#
# No-op if wetty was never installed. New installs go through
# scripts/install/install-wetty.sh instead.

ETC_UNIT=/etc/systemd/system/wetty.service
PKG_UNIT=/usr/lib/systemd/system/wetty.service
ENTRYPOINT=/usr/local/reqad/scripts/wetty_shell.sh

changed=0

# ── 1. Drop the old untracked wrapper ────────────────────────────────────────
if [ -e /root/wetty/shell.sh ] || compgen -G "/root/wetty/shell.sh.bkp-*" > /dev/null; then
	rm -f /root/wetty/shell.sh /root/wetty/shell.sh.bkp-*
	echo "removed obsolete /root/wetty/shell.sh wrapper"
fi

# ── 2. Retire a hand-made /etc unit in favour of the packaged one ────────────
if [ -f "$ETC_UNIT" ] && [ -f "$PKG_UNIT" ]; then
	# Only step aside for our own unit — never clobber something unrecognisable
	if grep -q "wetty" "$ETC_UNIT"; then
		BKP="/root/wetty.service.bkp-$(date +%F-%H%M%S)"
		cp -a "$ETC_UNIT" "$BKP"
		rm -f "$ETC_UNIT"
		echo "replaced $ETC_UNIT with the packaged unit (old copy: $BKP)"
		echo "  NOTE: any local customisation (port, host) must be re-applied as a"
		echo "        drop-in: systemctl edit wetty"
		changed=1
	fi
fi

[ -f "$PKG_UNIT" ] || exit 0

# ── 3. Make sure -c still points at the packaged entrypoint ──────────────────
# Only relevant for a unit we could not replace above (admin drop-in, or a
# /etc unit on a build without the packaged one).
ACTIVE_UNIT="$ETC_UNIT"
[ -f "$ACTIVE_UNIT" ] || ACTIVE_UNIT="$PKG_UNIT"

if [ -w "$ACTIVE_UNIT" ] && ! grep -qE "^ExecStart=.*-c[[:space:]]+$ENTRYPOINT([[:space:]]|\$)" "$ACTIVE_UNIT"; then
	if grep -qE "^ExecStart=.*[[:space:]]-c[[:space:]]" "$ACTIVE_UNIT"; then
		sed -i -E "/^ExecStart=/ s#([[:space:]]-c[[:space:]]+)[^[:space:]]+#\1$ENTRYPOINT#" "$ACTIVE_UNIT"
	else
		sed -i -E "/^ExecStart=/ s#[[:space:]]*\$# -c $ENTRYPOINT#" "$ACTIVE_UNIT"
	fi
	echo "wetty entrypoint set to $ENTRYPOINT in $ACTIVE_UNIT"
	changed=1
fi

# ── 4. Reload / restart only if something moved ──────────────────────────────
if [ "$changed" -eq 1 ]; then
	systemctl daemon-reload
	if systemctl is-active --quiet wetty; then
		systemctl restart wetty && echo "wetty restarted"
	fi
fi

exit 0
