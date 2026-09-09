#!/bin/bash
# Enable the "Pipe to a program" filter action (vnd.dovecot.pipe).
#
# Reqad's email filters gained a pipe action (cPanel parity). It needs
# Pigeonhole's extprograms plugin turned on, which servers already running
# Pigeonhole will not pick up from setup_dovecot_sieve.sh — that one only writes
# 90-sieve.conf on a fresh switch-over. Hence this small, idempotent add-on, in
# the same shape as setup_autoresponder_sieve.sh.
#
# MEASURED on dovecot 2.4.5:
#   * `sieve_extensions = +vnd.dovecot.pipe` is read BEFORE the plugin registers
#     the extension. It is dropped with "ignored unknown extension" on every
#     delivery and `require "vnd.dovecot.pipe"` then fails. Do not use it.
#   * `sieve_global_extensions` is applied after the plugin loads and works. It
#     also restricts pipe to the global/domain (before) scripts: a mailbox's own
#     script is refused with "its use is restricted to global scripts". That is
#     the boundary we want, since a pipe runs a program as the mailbox's system
#     user — so the panel offers the action on the admin tiers only.
#
# The Sieve argument is a FILENAME resolved inside sieve_pipe_bin_dir, never a
# path, so a filter can only run what an administrator installed there. The
# panel lists that directory and never writes to it.
#
# Safe to call from post_reqad_install.sh and safe to re-run:
#   * no-op unless 90-sieve.conf exists (server not on Pigeonhole delivery)
#   * no-op if the settings are already there
#   * rolls back if doveconf or dovecot reject the result
#
# Usage:  bash /usr/local/reqad/scripts/update/setup_sieve_pipe.sh

set -u
export LC_ALL=C

CONF=/etc/dovecot/conf.d/90-sieve.conf
BIN_DIR=/var/lib/reqad/sieve/bin
STAMP=$(date +%Y%m%d-%H%M%S)

[ "$(id -u)" -eq 0 ] || { echo "ERROR: must run as root" >&2; exit 1; }

if [ ! -f "$CONF" ]; then
    echo "No $CONF — server is not on Pigeonhole delivery, nothing to do."
    exit 0
fi

# The bin dir is harmless on its own: an empty dir just means no program can be
# named, and the panel says so.
mkdir -p "$BIN_DIR"
chown dovecot:dovecot "$BIN_DIR"
chmod 755 "$BIN_DIR"

if grep -q '^sieve_plugins.*sieve_extprograms' "$CONF"; then
    echo "Sieve pipe action already enabled."
    exit 0
fi

cp -a "$CONF" "$CONF.bak-$STAMP"

cat >> "$CONF" <<'BLOCK'

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
BLOCK

if ! doveconf -n >/dev/null 2>&1; then
    echo "doveconf rejected the new config — rolling back" >&2
    mv -f "$CONF.bak-$STAMP" "$CONF"
    exit 1
fi

systemctl reload dovecot 2>/dev/null || systemctl restart dovecot || {
    echo "dovecot failed to reload — rolling back" >&2
    mv -f "$CONF.bak-$STAMP" "$CONF"
    systemctl restart dovecot
    exit 1
}

echo "Sieve pipe action enabled. Backup: $CONF.bak-$STAMP"
echo "Install programs (root, executable) in $BIN_DIR to make them selectable."
