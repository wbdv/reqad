#!/bin/bash
# Check that dovecot's subpackages match dovecot itself, and that the Sieve
# plugin is present on a server that is using Sieve.
#
# `dnf update` only ever upgrades packages that are ALREADY installed — it never
# adds a subpackage. So a server that has never installed dovecot-sieve keeps
# not having it through every future update, and the panel's Email Filters page
# then writes config dovecot cannot parse:
#
#     Unknown section name: sieve_script
#
# The mirror image is version skew: dovecot upgraded to 2.4.5 from one repo
# while dovecot-sieve stays at 2.4.1 from another. A plugin built against a
# different version fails to load, and delivery breaks with it.
#
# CHECKS ONLY by default — it is called from the RPM's post-install, where
# running dnf is impossible anyway (the rpmdb lock is held by the transaction
# that is calling it), and where a surprise package install would be rude.
# Run it with --fix to act on what it reports.
#
# Usage:  ensure_dovecot_packages.sh          report
#         ensure_dovecot_packages.sh --fix    install / upgrade what is missing

set -u
export LC_ALL=C

FIX=0
[ "${1:-}" = "--fix" ] && FIX=1

rpm -q dovecot >/dev/null 2>&1 || exit 0        # no dovecot, nothing to say

DV=$(rpm -q --qf '%{VERSION}-%{RELEASE}' dovecot)

# ── 1. subpackages that do not match dovecot itself ─────────────────────────
SKEWED=""
while IFS='|' read -r name vr; do
    [ -n "$name" ] || continue
    [ "$name" = "dovecot" ] && continue
    case "$name" in *-debuginfo|*-debugsource) continue ;; esac
    [ "$vr" = "$DV" ] || SKEWED="$SKEWED $name"
done < <(rpm -qa 'dovecot*' --qf '%{NAME}|%{VERSION}-%{RELEASE}\n')

# ── 2. the Sieve plugin, on a server that is using Sieve ────────────────────
# "Using Sieve" = the panel's filter tiers are declared, or local delivery
# already goes through dovecot. Either way the plugin has to be there.
WANTS_SIEVE=0
[ -f /etc/dovecot/conf.d/90-sieve.conf ] && WANTS_SIEVE=1
grep -qs 'transport = dovecot_lmtp' /etc/exim/exim.conf && WANTS_SIEVE=1

MISSING=""
if [ "$WANTS_SIEVE" -eq 1 ]; then
    rpm -q dovecot-sieve        >/dev/null 2>&1 || MISSING="$MISSING dovecot-sieve"
    rpm -q dovecot-managesieved >/dev/null 2>&1 || MISSING="$MISSING dovecot-managesieved"
fi

# ── 2b. protocol daemons the config claims to run ───────────────────────────
# In 2.4 each protocol is its own package, and `dnf update` never ADDS one — so
# a box that reached 2.4 without dovecot-imapd stays without it forever while
# the panel keeps offering IMAP. Found in the field: dovecot 2.4.5 with no
# dovecot-imapd installed at all.
#
# The trigger is the config, not a guess: if conf.d declares the protocol, the
# package that provides its binary must be there. A server that genuinely does
# not run POP3 has no 20-pop3.conf and is not nagged about it.
for proto in imap:imapd pop3:pop3d lmtp:lmtpd; do
    p_name=${proto%%:*}
    p_pkg=dovecot-${proto##*:}
    f=/etc/dovecot/conf.d/20-${p_name}.conf
    if [ -f "$f" ] && grep -qE "^[[:space:]]*${p_name}[[:space:]]*=[[:space:]]*yes" "$f"; then
        rpm -q "$p_pkg" >/dev/null 2>&1 || MISSING="$MISSING $p_pkg"
    fi
done

# ── 3. a config still declaring an older release ────────────────────────────
# dovecot_config_version / dovecot_storage_version say which release the config
# was written for, and dovecot keeps THAT release's defaults for every setting
# whose default has since changed. dovecot.conf is %config(noreplace), so a
# package upgrade never touches it and the declaration silently rots: a box on
# 2.4.5 keeps running 2.4.1 semantics. dnf cannot fix this, so it is reported
# separately and never joins the install transaction below.
STALE_CFG=""
if [ -f /etc/dovecot/dovecot.conf ]; then
    DV_VER=${DV%%-*}
    CFG_VER=$(sed -n 's/^[[:space:]]*dovecot_config_version[[:space:]]*=[[:space:]]*//p' \
                  /etc/dovecot/dovecot.conf | head -1)
    STO_VER=$(sed -n 's/^[[:space:]]*dovecot_storage_version[[:space:]]*=[[:space:]]*//p' \
                  /etc/dovecot/dovecot.conf | head -1)
    if [ "$CFG_VER" != "$DV_VER" ] || [ "$STO_VER" != "$DV_VER" ]; then
        STALE_CFG="${CFG_VER:-none}/${STO_VER:-none}"
    fi
fi

if [ -n "$STALE_CFG" ]; then
    echo "WARNING: /etc/dovecot/dovecot.conf declares $STALE_CFG but dovecot is ${DV%%-*};"
    echo "         it is still running the older release's defaults. Fix with:"
    echo "           bash /usr/local/reqad/scripts/update/migrate_dovecot_2.4.sh"
fi

if [ -z "$SKEWED" ] && [ -z "$MISSING" ]; then
    if [ "$FIX" -eq 1 ] && [ -z "$STALE_CFG" ]; then
        echo "dovecot packages are consistent ($DV); nothing to do."
    fi
    exit 0
fi

[ -n "$SKEWED" ] && echo "WARNING: these dovecot packages do not match dovecot $DV:$SKEWED"
[ -n "$MISSING" ] && echo "WARNING: the dovecot config needs packages that are not installed:$MISSING"

if [ "$FIX" -eq 0 ]; then
    echo "         fix with: bash /usr/local/reqad/scripts/update/ensure_dovecot_packages.sh --fix"
    exit 0
fi

# Never fight the rpmdb lock — the post-install path holds it.
if pgrep -x 'rpm|dnf|yum' >/dev/null 2>&1; then
    echo "An rpm/dnf transaction is in progress — run this again afterwards."
    exit 0
fi

[ "$(id -u)" -eq 0 ] || { echo "ERROR: --fix must run as root" >&2; exit 1; }

# One transaction, so dnf can only pick a set that agrees with itself.
if ! dnf -y install $MISSING $SKEWED; then
    echo "ERROR: dnf could not align the dovecot packages." >&2
    echo "       If the version you need is only in reqad-test, enable that repo." >&2
    exit 1
fi

if ! doveconf -n >/dev/null 2>&1; then
    echo "WARNING: dovecot config does not parse after the package change:" >&2
    { doveconf -n 2>&1 | head -5; } || true
    echo "         Not restarting. Fix the config, then: systemctl restart dovecot" >&2
    exit 1
fi

systemctl restart dovecot && echo "dovecot restarted on $(rpm -q --qf '%{VERSION}-%{RELEASE}' dovecot)."
