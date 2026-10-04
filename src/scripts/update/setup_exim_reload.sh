#!/bin/bash
# Give exim.service an ExecReload.
#
# Stock exim.service (RHEL/Rocky) defines ExecStart and nothing else, so
# `systemctl reload exim` answers "Job type reload is not applicable for unit
# exim.service" and exits 3. The panel reloads exim after every mail-config
# save, and that failure was silent: the file on disk changed, the daemon went
# on running the configuration it started with, and the panel reported success.
#
# Exim re-reads its configuration on SIGHUP (the daemon re-execs itself), which
# is exactly what a reload should be — no dropped connections, unlike the
# restart the panel now has to fall back to when this drop-in is missing.
#
# Idempotent: writes the drop-in only when it differs, and only reloads systemd
# when something changed.
#
# Usage:  bash /usr/local/reqad/scripts/update/setup_exim_reload.sh

set -u
export LC_ALL=C

[ "$(id -u)" -eq 0 ] || { echo "ERROR: must run as root" >&2; exit 1; }

DIR=/etc/systemd/system/exim.service.d
FILE=$DIR/reqad-reload.conf

read -r -d '' WANT <<'CONF' || true
# Installed by Reqad (scripts/update/setup_exim_reload.sh).
# exim re-reads its config on SIGHUP; without this the unit has no reload verb
# at all and every `systemctl reload exim` fails.
[Service]
ExecReload=/bin/kill -HUP $MAINPID
CONF

if [ -f "$FILE" ] && [ "$(cat "$FILE")" = "$WANT" ]; then
    echo "$FILE is already in place — nothing to do."
    exit 0
fi

mkdir -p "$DIR"
printf '%s\n' "$WANT" > "$FILE"
chmod 644 "$FILE"
systemctl daemon-reload

if systemctl is-active --quiet exim && ! systemctl reload exim; then
    echo "WARNING: exim is running but still refused a reload." >&2
    exit 1
fi
echo "Installed $FILE — 'systemctl reload exim' now sends SIGHUP."
