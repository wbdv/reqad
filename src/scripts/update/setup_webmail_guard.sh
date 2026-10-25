#!/bin/bash
# Brute-force protection for webmail (Roundcube on the panel's /webmail/).
#
# Webmail logins reach dovecot from 127.0.0.1, so the firewall's own IMAP
# login watch never sees the real address. Three layers instead:
#
#   1. Roundcube: at most 3 failed logins per minute per mailbox
#      (login_rate_limit) and the userlogins log (log_logins).
#   2. nginx (panel instance): login POSTs to /webmail/ limited per IP,
#      10 a minute with a burst of 10, the rest answered 429.
#   3. The plugin reqad_guard writes each failed login as a JSON line to
#      roundcubemail/logs/reqad-guard.log; csf's lfd watches that file (a free
#      CUSTOMn_LOG slot in csf.conf) with a rule in /usr/local/csf/bin/
#      regex.custom.pm: 10 failures from one IP within LF_INTERVAL -> csf
#      temporary block of 1 h, made permanent by lfd's own LF_PERMBLOCK rule
#      after repeats. csf.ignore (and csf.allow with IGNORE_ALLOW) is never
#      blocked. Blocks are listed and lifted in Settings > Security > Blocked
#      addresses (they carry the tag "reqadwebmail").
#
#      The rule reads only the IP, at the fixed start of the line the plugin
#      writes ({"t":...,"ip":"..."), before the typed login name; JSON escapes
#      newlines, so a crafted login name cannot forge a line.
#
# Usage: setup_webmail_guard.sh [--dry-run|--apply|--remove]   (default: --dry-run)

if [ "$(id -u)" -ne 0 ]; then
    echo "This script must be run as root"
    exit 1
fi

MODE=dry
case "$1" in
    ''|--dry-run) MODE=dry ;;
    --apply)      MODE=apply ;;
    --remove)     MODE=remove ;;
    *) echo "usage: $0 [--dry-run|--apply|--remove]"; exit 2 ;;
esac

R=/usr/local/reqad
RC=$R/roundcubemail
CONFIG=$RC/config/config.inc.php
PLUGIN_SRC=$R/scripts/mail/roundcube/reqad_guard.php
PLUGIN_DIR=$RC/plugins/reqad_guard
ZONE=/etc/reqad/conf.d/00-reqad-webmail.conf
NGINX_CONF=/etc/reqad/nginx.conf
UNIT=/etc/systemd/system/reqad-webmail-guard.service   # the earlier watcher, removed
CSF_CONF=/etc/csf/csf.conf
REGEX=/usr/local/csf/bin/regex.custom.pm
RX_BEGIN='# --- Reqad: webmail login failures (scripts/update/setup_webmail_guard.sh) ---'
RX_END='# --- end Reqad webmail login failures ---'
GUARD_LOG=$RC/logs/reqad-guard.log
LOGROTATE=/etc/logrotate.d/reqad-webmail
BEGIN='// --- Reqad: webmail brute-force protection (scripts/update/setup_webmail_guard.sh) ---'
END='// --- end Reqad webmail brute-force protection ---'
LIMIT_MARK='# reqad: webmail login rate limit (setup_webmail_guard.sh)'
say() { echo "webmail guard: $*"; }

# the CUSTOMn_LOG slot that holds our log, else the first one still on the default
lfd_slot() {
    local n
    n=$(sed -n "s|^CUSTOM\([1-9]\)_LOG = \"$GUARD_LOG\"\$|\1|p" "$CSF_CONF" | head -1)
    [ -n "$n" ] || n=$(sed -n 's|^CUSTOM\([1-9]\)_LOG = "/var/log/customlog"$|\1|p' "$CSF_CONF" | head -1)
    echo "$n"
}

lfd_block() {      # $1 = slot
    cat <<EOF
$RX_BEGIN
# Failed webmail logins, one JSON line each, written by the Roundcube plugin
# reqad_guard: {"t":<time>,"ip":"<client ip>","user":"...","code":N}
    if ((\$globlogs{CUSTOM$1_LOG}{\$lgfile}) and (\$line =~ /^\\{"t":\\d+,"ip":"([0-9A-Fa-f:.]+)",/)) {
        return ("Failed webmail login from",\$1,"reqadwebmail","10","","3600","0");
    }
$RX_END
EOF
}

lfd_restart_or_revert() {    # restores csf.conf + regex.custom.pm from .wg-bak if lfd fails
    if perl -c "$REGEX" >/dev/null 2>&1 && systemctl restart lfd && sleep 2 && systemctl is-active --quiet lfd; then
        rm -f "$CSF_CONF.wg-bak" "$REGEX.wg-bak"
        return 0
    fi
    say "lfd did not start with the new rule - putting the previous csf files back"
    [ -f "$CSF_CONF.wg-bak" ] && mv -f "$CSF_CONF.wg-bak" "$CSF_CONF"
    [ -f "$REGEX.wg-bak" ] && mv -f "$REGEX.wg-bak" "$REGEX"
    systemctl restart lfd
    return 1
}

lfd_remove() {
    [ -f "$REGEX" ] || return 0
    local changed=0
    cp -a "$CSF_CONF" "$CSF_CONF.wg-bak"; cp -a "$REGEX" "$REGEX.wg-bak"
    if grep -qxF "$RX_BEGIN" "$REGEX"; then
        sed -i "\|^$RX_BEGIN\$|,\|^$RX_END\$|d" "$REGEX"; changed=1
    fi
    if grep -q "^CUSTOM[1-9]_LOG = \"$GUARD_LOG\"" "$CSF_CONF"; then
        sed -i "s|^\(CUSTOM[1-9]_LOG\) = \"$GUARD_LOG\"|\1 = \"/var/log/customlog\"|" "$CSF_CONF"; changed=1
    fi
    if [ "$changed" = 1 ]; then lfd_restart_or_revert && say "lfd rule removed"; else rm -f "$CSF_CONF.wg-bak" "$REGEX.wg-bak"; fi
}

# the first version ran its own watcher service; lfd does that job now
remove_old_watcher() {
    if [ -f "$UNIT" ]; then
        systemctl disable --now reqad-webmail-guard.service >/dev/null 2>&1
        rm -f "$UNIT"
        systemctl daemon-reload
        say "old reqad-webmail-guard.service removed (lfd watches the log now)"
    fi
    rm -f "$R/scripts/webmail_guard.php"
}

panel_vhosts() { grep -l 'location /webmail/' /etc/reqad/conf.d/*.conf 2>/dev/null | grep -v '/00-'; }

nginx_test_reload() {      # $1: files to restore on failure (from .wg-bak copies)
    if nginx -t -c "$NGINX_CONF" >/dev/null 2>&1; then
        systemctl reload reqad
        return 0
    fi
    say "nginx -t failed - putting the previous config back"
    nginx -t -c "$NGINX_CONF" 2>&1 | tail -3
    for f in $1; do
        if [ -f "$f.wg-bak" ]; then mv -f "$f.wg-bak" "$f"; else rm -f "$f"; fi
    done
    systemctl reload reqad
    return 1
}

if [ ! -f "$CONFIG" ]; then
    say "Roundcube is not installed ($CONFIG missing) - nothing to do"
    exit 0
fi

# --- remove ------------------------------------------------------------------
if [ "$MODE" = remove ]; then
    remove_old_watcher
    rm -f "$LOGROTATE"
    lfd_remove
    sed -i "\|^$BEGIN\$|,\|^$END\$|d" "$CONFIG"
    rm -rf "$PLUGIN_DIR"
    files="$ZONE"
    for v in $(panel_vhosts); do
        cp -a "$v" "$v.wg-bak"; sed -i "\|$LIMIT_MARK|d; \|limit_req zone=reqad_webmail_login|d" "$v"; files="$files $v"
    done
    [ -f "$ZONE" ] && cp -a "$ZONE" "$ZONE.wg-bak" && rm -f "$ZONE"
    nginx_test_reload "$files" && rm -f $(for f in $files; do echo "$f.wg-bak"; done)
    say "removed (failed logins already recorded stay in the panel's login history)"
    exit 0
fi

# --- 1. plugin + Roundcube settings -------------------------------------------
block="$BEGIN
\$config['log_logins'] = true;
\$config['login_rate_limit'] = 3;
if (!in_array('reqad_guard', \$config['plugins'] ?? [], true)) {
    \$config['plugins'][] = 'reqad_guard';
}
$END"
cur=$(sed -n "\|^$BEGIN\$|,\|^$END\$|p" "$CONFIG")
if [ "$MODE" = dry ]; then
    cmp -s "$PLUGIN_SRC" "$PLUGIN_DIR/reqad_guard.php" || say "would install the plugin in $PLUGIN_DIR"
    [ "$cur" = "$block" ] || say "would set log_logins, login_rate_limit and enable reqad_guard in $CONFIG"
else
    mkdir -p "$PLUGIN_DIR"
    if ! cmp -s "$PLUGIN_SRC" "$PLUGIN_DIR/reqad_guard.php"; then
        install -m 0644 "$PLUGIN_SRC" "$PLUGIN_DIR/reqad_guard.php"
        chown -R reqad:reqad "$PLUGIN_DIR"
        say "plugin installed"
    fi
    if [ "$cur" != "$block" ]; then
        cp -a "$CONFIG" "$CONFIG.bak-$(date +%Y%m%d-%H%M%S)"
        sed -i "\|^$BEGIN\$|,\|^$END\$|d" "$CONFIG"
        printf '\n%s\n' "$block" >> "$CONFIG"
        # never leave Roundcube with a config it cannot parse
        PHP=/opt/reqad/php-current/usr/bin/php
        if [ -x "$PHP" ] && ! "$PHP" -l "$CONFIG" >/dev/null 2>&1; then
            sed -i "\|^$BEGIN\$|,\|^$END\$|d" "$CONFIG"
            say "config check failed - Roundcube settings left as they were"
        else
            say "Roundcube: log_logins, login_rate_limit 3/min, plugin enabled"
        fi
    fi
    touch "$RC/logs/reqad-guard.log"
    chown reqad:reqad "$RC/logs/reqad-guard.log"
    chmod 0640 "$RC/logs/reqad-guard.log"
fi

# --- 2. nginx rate limit for login POSTs ----------------------------------------
zone="# Managed by Reqad (scripts/update/setup_webmail_guard.sh): webmail login POSTs
# per client IP; any other request has an empty key and is not limited.
map \"\$request_method:\$arg__task\" \$reqad_webmail_login {
    \"POST:login\" \$binary_remote_addr;
    default      \"\";
}
limit_req_zone \$reqad_webmail_login zone=reqad_webmail_login:1m rate=10r/m;"
changed_files=""
if [ "$MODE" = dry ]; then
    [ "$(cat "$ZONE" 2>/dev/null)" = "$zone" ] || say "would write $ZONE"
else
    if [ "$(cat "$ZONE" 2>/dev/null)" != "$zone" ]; then
        [ -f "$ZONE" ] && cp -a "$ZONE" "$ZONE.wg-bak"
        printf '%s\n' "$zone" > "$ZONE"
        changed_files="$ZONE"
    fi
fi
for v in $(panel_vhosts); do
    grep -q 'limit_req zone=reqad_webmail_login' "$v" && continue
    if [ "$MODE" = dry ]; then
        say "would add the login rate limit to $v"; continue
    fi
    cp -a "$v" "$v.wg-bak"
    # inside the PHP location of /webmail/, where the login POST is handled
    python3 - "$v" "$LIMIT_MARK" <<'PY'
import re, sys
p, mark = sys.argv[1], sys.argv[2]
s = open(p).read()
m = re.search(r'(\n([ \t]*)location ~ \^/webmail/\(\.\+\\\.php\)\(/\.\*\)\?\$ \{\n)', s)
if not m:
    sys.exit(3)
ind = m.group(2) + '    '
ins = ind + mark + '\n' + ind + 'limit_req zone=reqad_webmail_login burst=10 nodelay;\n'
s = s[:m.end()] + ins + s[m.end():]
open(p, 'w').write(s)
PY
    if [ $? -ne 0 ]; then
        mv -f "$v.wg-bak" "$v"
        say "$v: webmail PHP location not found - rate limit not added"
        continue
    fi
    changed_files="$changed_files $v"
done
if [ "$MODE" = apply ] && [ -n "$changed_files" ]; then
    if nginx_test_reload "$changed_files"; then
        for f in $changed_files; do rm -f "$f.wg-bak"; done
        say "nginx: webmail login POSTs limited to 10/min per IP"
    fi
fi

# --- 3. lfd rule + log rotation ----------------------------------------------------
lr="# Managed by Reqad (scripts/update/setup_webmail_guard.sh)
$RC/logs/*.log {
    su reqad reqad
    weekly
    rotate 8
    compress
    delaycompress
    missingok
    notifempty
    copytruncate
}"
if [ ! -f "$CSF_CONF" ] || [ ! -f "$REGEX" ]; then
    say "csf is not installed - failed logins are logged but nobody blocks them"
    [ "$MODE" = apply ] && remove_old_watcher && { [ "$(cat "$LOGROTATE" 2>/dev/null)" = "$lr" ] || printf '%s\n' "$lr" > "$LOGROTATE"; }
    exit 0
fi
slot=$(lfd_slot)
if [ -z "$slot" ]; then
    say "no free CUSTOMn_LOG slot in $CSF_CONF - lfd rule not added"
    exit 0
fi
want_block=$(lfd_block "$slot")
cur_block=$(sed -n "\|^$RX_BEGIN\$|,\|^$RX_END\$|p" "$REGEX")
slot_ok=0; grep -q "^CUSTOM${slot}_LOG = \"$GUARD_LOG\"" "$CSF_CONF" && slot_ok=1
if [ "$MODE" = dry ]; then
    [ "$slot_ok" = 1 ] || say "would set CUSTOM${slot}_LOG = \"$GUARD_LOG\" in $CSF_CONF"
    [ "$cur_block" = "$want_block" ] || say "would add the lfd rule 'reqadwebmail' to $REGEX"
    [ -f "$UNIT" ] && say "would remove the old reqad-webmail-guard.service"
    [ "$(cat "$LOGROTATE" 2>/dev/null)" = "$lr" ] || say "would write $LOGROTATE (weekly, 8 kept)"
    say "dry run - run with --apply"
    exit 0
fi
remove_old_watcher
[ "$(cat "$LOGROTATE" 2>/dev/null)" = "$lr" ] || printf '%s\n' "$lr" > "$LOGROTATE"
if [ "$slot_ok" = 1 ] && [ "$cur_block" = "$want_block" ]; then
    say "lfd rule in place (CUSTOM${slot}_LOG)"
    exit 0
fi
cp -a "$CSF_CONF" "$CSF_CONF.wg-bak"; cp -a "$REGEX" "$REGEX.wg-bak"
sed -i "s|^CUSTOM${slot}_LOG = .*|CUSTOM${slot}_LOG = \"$GUARD_LOG\"|" "$CSF_CONF"
python3 - "$REGEX" "$RX_BEGIN" "$RX_END" "$want_block" <<'PY'
import sys
p, b, e, block = sys.argv[1:5]
s = open(p).read()
if b in s:                                    # replace our old block
    i = s.index(b); j = s.index(e, i) + len(e)
    s = s[:i] + block + s[j:]
else:                                         # before the closing part of custom_line()
    anchor = '# If the matches in this file are not syntactically correct'
    k = s.find(anchor)
    if k < 0:
        k = s.index('# Do not edit beyond this point')
        k = s.rfind('\n#####', 0, k) + 1 or k
    s = s[:k] + block + '\n' + s[k:]
open(p, 'w').write(s)
PY
if lfd_restart_or_revert; then
    say "lfd watches $GUARD_LOG (CUSTOM${slot}_LOG): 10 failures -> 1 h block"
fi
