#!/bin/bash
# Declare the autoresponder Sieve tier in dovecot.
#
# Autoresponders used to be Exim Sieve filters under /etc/exim/autoreply/, run
# by the virtual_autoreply router. Exim's Sieve engine has no `variables`
# extension, so the original subject could not be interpolated into the reply.
# Pigeonhole has it, so the vacation script moved there — see
# sieve_render_autoresponder() in public_html/modules/functions.php.
#
# Safe to call from post_reqad_install.sh and safe to re-run:
#   * it does nothing unless 90-sieve.conf already exists, i.e. unless the admin
#     has opted into LMTP delivery with setup_dovecot_sieve.sh
#   * the panel switches over only when the block is present AND exim actually
#     delivers through dovecot_lmtp (autoresponder_backend()), so a server that
#     has 90-sieve.conf but still delivers with exim appendfile — e.g. one that
#     ran migrate_dovecot_2.4.sh but not setup_dovecot_sieve.sh — keeps the old
#     backend and keeps auto-replying
#   * it rolls back if doveconf rejects the result
#
# Usage:  bash /usr/local/reqad/scripts/update/setup_autoresponder_sieve.sh

set -u
export LC_ALL=C

CONF=/etc/dovecot/conf.d/90-sieve.conf
AR_DIR=/var/lib/reqad/sieve/autoresponders
STAMP=$(date +%Y%m%d-%H%M%S)

[ "$(id -u)" -eq 0 ] || { echo "ERROR: must run as root" >&2; exit 1; }

# Nothing to do on a server that has not been switched to dovecot LMTP + sieve.
if [ ! -f "$CONF" ]; then
    echo "No $CONF — server is not on Pigeonhole delivery, leaving autoresponders on the exim backend."
    exit 0
fi

# LMTP drops to the mailbox owner, so every uid must be able to traverse into
# /var/lib/reqad to reach sieve/. 0711 = traverse without listing. Heals installs
# where the dir was created 0700 and delivery fails with "unable to access
# necessary Sieve scripts".
mkdir -p /var/lib/reqad
chmod 0711 /var/lib/reqad

mkdir -p "$AR_DIR"
chown dovecot:dovecot "$AR_DIR"
chmod 755 "$AR_DIR"

if grep -q '^sieve_script autoresponder' "$CONF"; then
    echo "Autoresponder sieve tier already declared."
    exit 0
fi

cp -a "$CONF" "$CONF.bak-$STAMP"

# Appended LAST on purpose. Before-scripts run in declaration order, and the
# chain stops at the first action that cancels the implicit keep — so a global
# or per-domain rule that discards spam must get its say BEFORE the vacation
# reply is generated. Declared first, the autoresponder happily answers spam.
cat >> "$CONF" <<'BLOCK'

# 4th tier, evaluated last of the "before" scripts: the per-mailbox vacation
# reply, rendered by Reqad from the autoresponders table. Declared after global
# and domain so an admin rule that discards a message suppresses the auto-reply.
# %{user} is the full login name (user@domain), which is how Reqad names the file.
sieve_script autoresponder {
  type   = before
  driver = file
  path   = /var/lib/reqad/sieve/autoresponders/%{user}.sieve
}
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

echo "Autoresponder sieve tier declared. Backup: $CONF.bak-$STAMP"
echo "Existing autoresponders migrate on the next manage_autoresponders.php run (<=5 min)."
