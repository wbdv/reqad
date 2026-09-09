#!/bin/bash
# Switch local delivery from exim appendfile to dovecot LMTP + Pigeonhole Sieve.
# This is what makes the three-tier email filters (global / domain / account)
# possible, and it is the only way per-account filters can be managed from
# Roundcube and IMAP clients (ManageSieve).
#
# OPT-IN — deliberately NOT called from post_reqad_install.sh:
#   * it runs dnf, and a %post scriptlet already holds the rpmdb lock
#   * it changes the live mail delivery path, which is an admin decision
#
# Idempotent and safe to re-run. Refuses to proceed unless the pre-flight
# reconciliation passes, and rolls back if dovecot or exim reject the config.
#
# Usage:  bash /usr/local/reqad/scripts/update/setup_dovecot_sieve.sh
#         bash .../setup_dovecot_sieve.sh --check    # report only, change nothing

set -u
export LC_ALL=C

CHECK_ONLY=0
[ "${1:-}" = "--check" ] && CHECK_ONLY=1

EXIM_CONF=/etc/exim/exim.conf
CONFD=/etc/dovecot/conf.d
STAMP=$(date +%Y%m%d-%H%M%S)

die() { echo "ERROR: $*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "must run as root"

# Do not fight the rpmdb lock (same reasoning as setup_dovecot_fts.sh).
if pgrep -x 'rpm|dnf|yum' >/dev/null 2>&1 || [ -e /var/lib/rpm/.rpm.lock ] && fuser /var/lib/rpm/.rpm.lock >/dev/null 2>&1; then
    echo "An rpm/dnf transaction is in progress — run this by hand afterwards."
    exit 0
fi

rpm -q dovecot >/dev/null 2>&1 || { echo "Dovecot not installed — nothing to do."; exit 0; }
case "$(rpm -q --qf '%{VERSION}' dovecot 2>/dev/null)" in
    2.4*) ;;
    *) die "expected dovecot 2.4.x; run migrate_dovecot_2.4.sh first" ;;
esac

# ── 1. pre-flight ────────────────────────────────────────────────────────────
echo "== Pre-flight reconciliation =="
if ! bash /usr/local/reqad/scripts/update/check_lmtp_readiness.sh; then
    die "not ready for LMTP delivery — fix the FAIL items above, then re-run"
fi

if [ "$CHECK_ONLY" -eq 1 ]; then
    echo; echo "--check given: stopping before making changes."
    exit 0
fi

# ── 2. packages ──────────────────────────────────────────────────────────────
echo "== Installing sieve packages =="
rpm -q dovecot-sieve >/dev/null 2>&1 && rpm -q dovecot-managesieved >/dev/null 2>&1 \
    || dnf -y install dovecot-sieve dovecot-managesieved || die "package install failed"

# ── 3. dovecot config ────────────────────────────────────────────────────────
echo "== Writing dovecot sieve config =="
mkdir -p "$CONFD"
cp -a "$CONFD" "/etc/dovecot/conf.d.bak-$STAMP" 2>/dev/null

# sieve plugin must be loaded for lmtp, or LMTP delivers straight to the maildir
# and every filter tier is silently skipped with no error anywhere.
cat > "$CONFD/20-lmtp.conf" <<'EOF'
# Enable LMTP protocol — exim delivers local mail here (transport dovecot_lmtp).
protocols {
  lmtp = yes
}

protocol lmtp {
  mail_plugins {
    sieve = yes
  }
}
EOF

# ManageSieve binds to 127.0.0.1 ONLY, never ::1: a box with IPv6 disabled has no
# ::1 and dovecot treats a listener it cannot bind as FATAL —
#   master: Error: bind(::1, 4190) failed: Cannot assign requested address
#   master: Fatal: Failed to start listeners
# — which takes the whole mail server down. Everything that talks ManageSieve
# here (roundcube, doveadm) is local and uses 127.0.0.1.

cat > "$CONFD/20-managesieve.conf" <<'EOF'
# ManageSieve, bound to loopback: roundcube connects over localhost and reqad
# uses "doveadm sieve" locally. To let external IMAP clients (thunderbird et al.)
# manage filters, drop the "listen" line AND open 4190 in the firewall —
# ssl = required already forces STARTTLS.
protocols {
  sieve = yes
}

service managesieve-login {
  inet_listener sieve {
    port   = 4190
    listen = 127.0.0.1
  }
}
EOF

# active_path deliberately has NO leading dot: mail_path/mail_inbox_path are ~/,
# so the home IS the maildir root and dovecot treats ~/.<name> as an IMAP folder
# — the conventional ~/.dovecot.sieve makes maildir stat ~/.dovecot.sieve/tmp
# and fail with "Not a directory".
# NOTE: `stop` does NOT short-circuit between scripts in pigeonhole; only
# `discard` ends the sequence. The tiers are additive, not hierarchical.
cat > "$CONFD/90-sieve.conf" <<'EOF'
# Sieve (Pigeonhole) — managed by Reqad.
#
# Three tiers, evaluated in this order:
#   1. global   — server-wide, rendered by Reqad from the email_filters table
#   2. domain   — per-domain,  rendered by Reqad from the email_filters table
#   3. personal — per-mailbox, the user's own script (ManageSieve-editable)
#
# Tiers 1 and 2 are "before" scripts. NOTE: `stop` does NOT cross script
# boundaries — it ends only its own script and the sequence continues to the
# next tier (measured). Only `discard` terminates the whole sequence. The tiers
# are therefore additive, not hierarchical; this differs from cPanel.
#
# Scripts live under /var/lib/reqad/, NOT /var/lib/dovecot/: the dovecot spec
# ships `%attr(0750,dovecot,dovecot) /var/lib/dovecot`, so every package upgrade
# resets that mode and the LMTP process (which setuids to the mailbox owner)
# loses +x on it — delivery then fails with a 451 "Temporarily unable to access
# necessary Sieve scripts". A chmod there would be undone by the next upgrade.
#
# NOTE: Dovecot 2.4 syntax (named sieve_script blocks). The 90-sieve.conf in the
# RPM's example-config is stale 2.3 `plugin { sieve = ... }` syntax — do not copy it.

# active_path deliberately has NO leading dot. mail_path/mail_inbox_path are ~/,
# so the home IS the maildir root and dovecot treats ~/.<name> as an IMAP folder
# — the conventional ~/.dovecot.sieve makes maildir stat ~/.dovecot.sieve/tmp and
# fail with "Not a directory". ~/dovecot.sieve sits outside the folder namespace.
# It is also kept out of ~/sieve/ so it is not listed as a script named "active".
sieve_script personal {
  driver      = file
  path        = ~/sieve
  active_path = ~/dovecot.sieve
}

sieve_script global {
  type   = before
  driver = file
  path   = /var/lib/reqad/sieve/reqad-global.sieve
}

sieve_script domain {
  type   = before
  driver = file
  path   = /var/lib/reqad/sieve/domains/%{user | domain}.sieve
}

# 4th tier, evaluated last of the "before" scripts: the per-mailbox vacation
# reply, rendered by Reqad from the autoresponders table. Declared after global
# and domain so an admin rule that discards a message suppresses the auto-reply.
# %{user} is the full login name (user@domain), which is how Reqad names the file.
sieve_script autoresponder {
  type   = before
  driver = file
  path   = /var/lib/reqad/sieve/autoresponders/%{user}.sieve
}

# "Pipe to a program" actions — vnd.dovecot.pipe, from the sieve_extprograms
# plugin (ships with dovecot in lib90_sieve_extprograms_plugin.so). The Sieve
# argument is a FILENAME resolved inside sieve_pipe_bin_dir, never a path, so a
# filter can only run programs an administrator has installed there. The panel
# lists that directory and never writes to it.
# MEASURED on 2.4.5: `sieve_extensions = +vnd.dovecot.pipe` is read BEFORE the
# plugin registers the extension, so it is dropped with "ignored unknown
# extension" on every delivery and `require "vnd.dovecot.pipe"` then fails.
# sieve_global_extensions is applied after the plugin loads and does work — and
# it restricts the extension to the admin tiers: a mailbox owner's own script is
# refused with "its use is restricted to global scripts", which is the boundary
# we want (piping runs a program as the mailbox's system user).
sieve_plugins           = sieve_extprograms
sieve_global_extensions = vnd.dovecot.pipe
sieve_pipe_bin_dir      = /var/lib/reqad/sieve/bin
EOF

# lmtp socket must be openable by exim (runs as exim, in the mail group); the
# 2.4 default is 0600 dovecot:root. DirectAdmin guides use 0666 — do not copy.
if ! grep -q '^service lmtp' "$CONFD/10-master.conf" 2>/dev/null; then
    cat >> "$CONFD/10-master.conf" <<'EOF'

service lmtp {
  unix_listener lmtp {
    mode  = 0660
    user  = mail
    group = mail
  }
}
EOF
fi

# LMTP drops to the mailbox owner, so every uid must be able to traverse into
# /var/lib/reqad to reach sieve/ and volatile/. 0711 = traverse without listing.
mkdir -p /var/lib/reqad
chmod 0711 /var/lib/reqad

# Volatile dir for mail_volatile_path (lock files). Sticky like /tmp: dovecot
# creates the per-user subdir as the mailbox owner, so the parent must be
# writable by all of them without letting them touch each other's.
mkdir -p /var/lib/reqad/volatile
chmod 1777 /var/lib/reqad/volatile

mkdir -p /var/lib/reqad/sieve/domains /var/lib/reqad/sieve/autoresponders /var/lib/reqad/sieve/bin
chown -R dovecot:dovecot /var/lib/reqad/sieve
chmod 755 /var/lib/reqad/sieve /var/lib/reqad/sieve/domains /var/lib/reqad/sieve/autoresponders /var/lib/reqad/sieve/bin

if ! doveconf -n >/dev/null 2>&1; then
    echo "doveconf rejected the new config — rolling back" >&2
    rm -rf "$CONFD"; mv "/etc/dovecot/conf.d.bak-$STAMP" "$CONFD"
    die "dovecot config invalid, rolled back"
fi
systemctl restart dovecot || die "dovecot failed to restart"

# ── 4. exim transport ────────────────────────────────────────────────────────
echo "== Switching exim local delivery to LMTP =="
cp -a "$EXIM_CONF" "$EXIM_CONF.bak-$STAMP"

if ! grep -q '^dovecot_lmtp:' "$EXIM_CONF"; then
    # exim IS in the mail group but does not apply supplementary groups when
    # dropping privileges for a transport — user/group must be named explicitly
    # or it gets EACCES on the socket. batch_max = 1 avoids the Envelope-To
    # Bcc-exposure that LMTP batching can cause.
    python3 - "$EXIM_CONF" <<'PY'
import io, sys
p = sys.argv[1]
s = io.open(p, encoding='utf-8', errors='surrogateescape').read()
t = """dovecot_lmtp:
  driver               = lmtp
  socket               = /var/run/dovecot/lmtp
  batch_max            = 1
  rcpt_include_affixes = true
  user                 = exim
  group                = mail

"""
s = s.replace("virtual_users_trans:\n", t + "virtual_users_trans:\n", 1)
io.open(p, 'w', encoding='utf-8', errors='surrogateescape').write(s)
PY
fi

sed -i '/^virtual_user:/,/^$/ s/^  transport = virtual_users_trans$/  transport = dovecot_lmtp/' "$EXIM_CONF"

if ! exim -bV >/dev/null 2>&1; then
    echo "exim rejected the new config — rolling back" >&2
    cp -a "$EXIM_CONF.bak-$STAMP" "$EXIM_CONF"
    die "exim config invalid, rolled back"
fi
systemctl restart exim || die "exim failed to restart"

# ── 5. report ────────────────────────────────────────────────────────────────
echo
echo "Done. Local delivery now goes exim -> dovecot LMTP -> pigeonhole sieve."
exim -oMr spam-scanned -bt postmaster@localhost 2>/dev/null | tail -1
echo "Backups: $EXIM_CONF.bak-$STAMP and /etc/dovecot/conf.d.bak-$STAMP"
echo "Rollback: set the virtual_user router back to 'transport = virtual_users_trans'"
echo "          and restart exim."
