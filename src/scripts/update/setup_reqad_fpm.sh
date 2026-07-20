#!/bin/bash
# setup_reqad_fpm.sh — provision a dedicated, version-agnostic PHP-FPM master for the reqad panel.
#
# Why: the reqad panel needs shell_exec, but it shared the php82-php-fpm master and so inherited
# disable_functions from the global /etc/opt/remi/php82/php.ini — which the panel UI itself rewrites
# (modules/php_settings.php). A pool-level php_admin_value[disable_functions] can only ADD functions,
# never re-enable one already disabled in php.ini, so removing shell_exec there has no effect.
# Fix: run reqad under its own FPM master with its own private php.ini that the UI never touches.
#
# All version-specific paths funnel through two symlinks, so migrating 8.2 -> 8.3 is just:
#   dnf install php83 php83-php-fpm <matching extensions>
#   ln -sfn /opt/remi/php83/root /opt/reqad/php-current
#   ln -sfn /etc/opt/remi/php83  /etc/reqad/php-current
#   systemctl restart reqad-php-fpm
# ...with no edits to the unit or any config this script writes.
#
# One-time provisioning: this runs from post-install on every RPM update, but provisions only once.
# If the systemd unit already exists the master is set up, so it exits early and never touches the
# private php.ini, pool config, or unit again — manual edits and version migrations are preserved.
# (Future changes to an already-provisioned master should ship as their own scripts/update/*.sh.)

set -u

REQAD_PHP_VER="${REQAD_PHP_VER:-php82}"   # default runtime; a migration just repoints the symlinks

ETC_DIR="/etc/reqad/php-fpm"
PRIV_INI="$ETC_DIR/php.ini"
FPM_CONF="$ETC_DIR/reqad-php-fpm.conf"    # single file: [global] + the [reqad] pool inline
UNIT="/etc/systemd/system/reqad-php-fpm.service"
OPT_LINK="/opt/reqad/php-current"
ETC_LINK="/etc/reqad/php-current"
SOCK="/run/reqad-php-fpm.sock"
PIDFILE="/run/reqad-php-fpm.pid"
SESSION_DIR="/usr/local/reqad/tmp/sessions"   # private, reqad-only (0700) — not under public_html

# Canonical disable_functions for the reqad panel: empty (no functions disabled).
REQAD_DISABLE_FUNCTIONS=""

echo "== reqad dedicated PHP-FPM setup (version: $REQAD_PHP_VER) =="

# Provision only once: if the dedicated master is already set up, leave it (and any manual edits to
# its php.ini / pool config) untouched on subsequent RPM updates.
if [ -f "$UNIT" ]; then
    echo "  reqad-php-fpm already provisioned ($UNIT exists) — skipping setup"
    exit 0
fi

mkdir -p "$ETC_DIR" /opt/reqad /var/log/php-fpm

# --- version pointer symlinks (create only if missing → survive a manual version migration) ---
[ -e "$OPT_LINK" ] || ln -sfn "/opt/remi/$REQAD_PHP_VER/root" "$OPT_LINK"
[ -e "$ETC_LINK" ] || ln -sfn "/etc/opt/remi/$REQAD_PHP_VER" "$ETC_LINK"
echo "  $OPT_LINK -> $(readlink "$OPT_LINK")"
echo "  $ETC_LINK -> $(readlink "$ETC_LINK")"

FPM_BIN="$OPT_LINK/usr/sbin/php-fpm"
if [ ! -x "$FPM_BIN" ]; then
    echo "  ERROR: php-fpm binary not found at $FPM_BIN — is $REQAD_PHP_VER installed?" >&2
    exit 1
fi

# --- private php.ini (create only if missing → preserve manual edits) ---
if [ ! -f "$PRIV_INI" ]; then
    SRC_INI="$ETC_LINK/php.ini"
    if [ ! -f "$SRC_INI" ]; then
        echo "  ERROR: source php.ini not found at $SRC_INI" >&2
        exit 1
    fi
    cp -a "$SRC_INI" "$PRIV_INI"
    echo "  created $PRIV_INI from $SRC_INI"
fi
# Always pin disable_functions in the private ini (deterministic regardless of global state).
if grep -qE "^;?[[:space:]]*disable_functions[[:space:]]*=" "$PRIV_INI"; then
    sed -i "s|^;*[[:space:]]*disable_functions[[:space:]]*=.*|disable_functions = ${REQAD_DISABLE_FUNCTIONS}|" "$PRIV_INI"
else
    echo "disable_functions = ${REQAD_DISABLE_FUNCTIONS}" >> "$PRIV_INI"
fi
echo "  pinned disable_functions in private php.ini (empty — no functions disabled)"

# --- combined master + pool config (one file: [global] then the [reqad] pool inline) ---
# Rebuild if it doesn't exist, or is still in the old split layout (an `include` line / no [reqad]).
if [ ! -f "$FPM_CONF" ] || grep -qE "^[[:space:]]*include" "$FPM_CONF" || ! grep -q "^\[reqad\]" "$FPM_CONF"; then
    # Carry the pool body over from any prior layout (preserves manual pm.* tuning); else use template.
    POOL_SRC=""
    for c in "$ETC_DIR/fpm.d/reqad.conf" /etc/opt/remi/php*/php-fpm.d/reqad.conf; do
        [ -f "$c" ] && { POOL_SRC="$c"; break; }
    done
    POOL_BODY="$(mktemp)"
    if [ -n "$POOL_SRC" ]; then
        # take from the [reqad] line onward → drops any stray leading [global] block
        sed -n '/^\[reqad\]/,$p' "$POOL_SRC" > "$POOL_BODY"
    fi
    if [ ! -s "$POOL_BODY" ]; then
        cat > "$POOL_BODY" <<EOF
[reqad]
user = reqad
group = reqad
listen = $SOCK
listen.allowed_clients = 127.0.0.1
listen.owner = reqad
listen.group = reqad
listen.mode = 0660

pm = dynamic
pm.max_children = 50
pm.start_servers = 2
pm.min_spare_servers = 2
pm.max_spare_servers = 4
slowlog = /var/log/php-fpm/reqad-slow.log
php_admin_value[error_log] = /usr/local/reqad/log/error_log
php_admin_flag[log_errors] = on
php_admin_flag[display_errors] = off
php_admin_flag[short_open_tag] = on
php_value[session.save_handler] = files
php_value[session.save_path]    = $SESSION_DIR

clear_env = no
catch_workers_output = yes
process.dumpable = yes
EOF
    fi
    # Normalize: disable_functions now lives in the private php.ini; pin the socket + slowlog paths.
    sed -i '/php_admin_value\[disable_functions\]/d; /^;[[:space:]]*remove shell_exec/d' "$POOL_BODY"
    sed -i "s|^listen[[:space:]]*=.*|listen = $SOCK|; s|^slowlog[[:space:]]*=.*|slowlog = /var/log/php-fpm/reqad-slow.log|" "$POOL_BODY"

    {
        echo "[global]"
        echo "pid = $PIDFILE"
        echo "error_log = /usr/local/reqad/log/fpm_error_log"
        echo
        cat "$POOL_BODY"
    } > "$FPM_CONF"
    rm -f "$POOL_BODY"
    echo "  wrote combined $FPM_CONF"
fi

# Drop the obsolete split layout and any copy left in the shared FPM dirs.
[ -d "$ETC_DIR/fpm.d" ] && rm -rf "$ETC_DIR/fpm.d" && echo "  removed obsolete $ETC_DIR/fpm.d"
for d in /etc/opt/remi/php*/php-fpm.d; do
    [ -f "$d/reqad.conf" ] && rm -f "$d/reqad.conf" && echo "  removed shared $d/reqad.conf"
done

# --- private session dir (isolated from other pools; not under public_html) ---
# The reqad pool (panel + phpMyAdmin + roundcube + sqadmin) keeps its sessions here, 0700/reqad,
# instead of the world-readable shared /var/lib/php/session. PHP self-GC (gc_probability) cleans it.
mkdir -p "$SESSION_DIR"
chown reqad:reqad /usr/local/reqad/tmp "$SESSION_DIR" 2>/dev/null
chmod 700 "$SESSION_DIR"
# Point the pool at it, idempotently (covers an existing/ carried-over config we didn't rebuild).
if grep -qE '^[[:space:]]*php_value\[session.save_path\]' "$FPM_CONF"; then
    sed -i "s|^[[:space:]]*php_value\[session.save_path\].*|php_value[session.save_path]    = $SESSION_DIR|" "$FPM_CONF"
else
    sed -i "/^\[reqad\]/a php_value[session.save_path]    = $SESSION_DIR" "$FPM_CONF"
fi
echo "  session.save_path -> $SESSION_DIR (0700, reqad:reqad)"

# --- systemd unit (always overwrite — reqad-owned template; no version string, only symlinks) ---
cat > "$UNIT" <<EOF
[Unit]
Description=Reqad panel PHP-FPM (private php.ini, shell_exec enabled, isolated from UI php.ini edits)
After=network.target

[Service]
Type=simple
Environment=PHP_INI_SCAN_DIR=$ETC_LINK/php.d
ExecStart=$OPT_LINK/usr/sbin/php-fpm --nodaemonize -c $PRIV_INI --fpm-config $FPM_CONF
ExecReload=/bin/kill -USR2 \$MAINPID
PIDFile=$PIDFILE

[Install]
WantedBy=multi-user.target
EOF
echo "  wrote $UNIT"

# --- activate ---
systemctl daemon-reload
# Drop the reqad pool from the shared master if it still serves one, so it releases the old socket.
if systemctl is-active --quiet "${REQAD_PHP_VER}-php-fpm"; then
    systemctl restart "${REQAD_PHP_VER}-php-fpm"
    echo "  restarted ${REQAD_PHP_VER}-php-fpm"
fi
systemctl enable reqad-php-fpm >/dev/null 2>&1
systemctl restart reqad-php-fpm

sleep 1
if systemctl is-active --quiet reqad-php-fpm && [ -S "$SOCK" ]; then
    echo "  reqad-php-fpm RUNNING, socket $SOCK present"
    echo "Done."
else
    echo "  ERROR: reqad-php-fpm did not start cleanly" >&2
    systemctl status reqad-php-fpm --no-pager -l 2>&1 | head -20
    exit 1
fi
