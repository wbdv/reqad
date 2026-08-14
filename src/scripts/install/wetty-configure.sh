#!/bin/bash
#
# wetty-configure.sh — wire an already-present wetty into Reqad: start the
# service, proxy /wetty through the panel's nginx, and enable the Terminal page.
#
# Split out of install-wetty.sh so the reqad-wetty RPM (which ships a prebuilt
# wetty and never compiles anything) performs the exact same wiring from %post.
# Everything here is idempotent — both callers may run it repeatedly.
#
# Usage (as root): /usr/local/reqad/scripts/install/wetty-configure.sh
#
set -euo pipefail

WETTY_PORT=3000
PKG_UNIT=/usr/lib/systemd/system/wetty.service
# The panel has its own nginx instance (reqad.service), separate from any
# nginx serving customer sites.
PANEL_CONF_DIR=/etc/reqad/conf.d
PANEL_NGINX_CONF=/etc/reqad/nginx.conf
INI=/usr/local/reqad/etc/server-software.ini

GREEN='\033[0;32m'; YELLOW='\033[1;33m'; RED='\033[0;31m'; NC='\033[0m'
info()  { echo -e "${GREEN}[INFO]${NC}  $1"; }
warn()  { echo -e "${YELLOW}[WARN]${NC}  $1"; }
error() { echo -e "${RED}[ERROR]${NC} $1"; exit 1; }

[ "$(id -u)" -eq 0 ] || error "Must run as root"

# ── 1. Service ───────────────────────────────────────────────────────────────
[ -f "$PKG_UNIT" ] || error "$PKG_UNIT missing — reinstall the reqad package"

# A hand-made unit in /etc overrides the packaged one; retire it.
bash /usr/local/reqad/scripts/update/update_wetty_shell.sh || true

systemctl daemon-reload
systemctl enable --now wetty

sleep 2
if systemctl is-active --quiet wetty; then
	info "wetty is running on 127.0.0.1:${WETTY_PORT}"
else
	warn "wetty did not start — check: journalctl -u wetty -n 50"
fi

# ── 2. Panel vhost: proxy /wetty to the service ──────────────────────────────
# The panel loads wetty in an iframe at https://<host>:2087/wetty/, so the
# panel's own nginx (reqad.service, /etc/reqad/nginx.conf) has to proxy it with
# a websocket upgrade. Current packages ship the block already; older vhosts
# predate it, so add it rather than telling the admin to.
# Returns: 0 added, 1 already there, 2 could not place it.
add_wetty_proxy() {
	local conf="$1"

	grep -qE '^[[:space:]]*location[[:space:]]+\^~[[:space:]]*/wetty' "$conf" && return 1

	local bkp="${conf}.bkp-$(date +%F-%H%M%S)"
	cp -a "$conf" "$bkp"
	BACKUPS+=("$bkp")

	# Insert ahead of the "location ~ /\.ht" deny block, the last location in
	# the packaged vhost. Anchoring there keeps the block inside the server {}.
	awk '
		/location[[:space:]]*~[[:space:]]*\/\\\.ht/ && !done {
			print "\t\tlocation ^~ /wetty {"
			print "\t\t\tproxy_pass http://127.0.0.1:'"$WETTY_PORT"';"
			print "\t\t\tproxy_http_version 1.1;"
			print "\t\t\tproxy_set_header Upgrade $http_upgrade;"
			print "\t\t\tproxy_set_header Connection \"upgrade\";"
			print "\t\t\tproxy_read_timeout 43200000;"
			print "\t\t\tproxy_set_header X-Real-IP $remote_addr;"
			print "\t\t\tproxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;"
			print "\t\t\tproxy_set_header Host $http_host;"
			print "\t\t\tproxy_set_header X-NginX-Proxy true;"
			print "\t\t}"
			print ""
			done = 1
		}
		{ print }
	' "$bkp" > "$conf"

	if ! grep -qE '^[[:space:]]*location[[:space:]]+\^~[[:space:]]*/wetty' "$conf"; then
		cp -a "$bkp" "$conf"
		return 2
	fi
	return 0
}

CHANGED_CONF=0
BACKUPS=()
shopt -s nullglob
for conf in "$PANEL_CONF_DIR"/*.conf; do
	set +e; add_wetty_proxy "$conf"; rc=$?; set -e
	case "$rc" in
		0) info "Added /wetty proxy to $conf (backup alongside it)"; CHANGED_CONF=1 ;;
		1) info "$conf already proxies /wetty" ;;
		2) warn "Could not place the /wetty block in $conf — add it by hand" ;;
	esac
done
shopt -u nullglob

if [ "$CHANGED_CONF" -eq 1 ]; then
	if nginx -t -c "$PANEL_NGINX_CONF" >/dev/null 2>&1; then
		systemctl reload reqad
		info "Panel nginx config valid — reqad reloaded"
	else
		warn "nginx rejected the panel config after the edit — restoring backups"
		for bkp in "${BACKUPS[@]}"; do
			cp -a "$bkp" "${bkp%%.bkp-*}"
		done
		nginx -t -c "$PANEL_NGINX_CONF" || true
		error "Panel vhost left unchanged. Add the /wetty proxy block by hand."
	fi
fi

# ── 3. Enable the Terminal page ──────────────────────────────────────────────
# terminal=0/absent hides the page entirely, so installing wetty without this
# leaves nothing to show for it.
if [ -f "$INI" ]; then
	if grep -qE '^[[:space:]]*terminal[[:space:]]*=' "$INI"; then
		if grep -qE '^[[:space:]]*terminal[[:space:]]*=[[:space:]]*1[[:space:]]*$' "$INI"; then
			info "Terminal page already enabled in $INI"
		else
			sed -i -E 's/^[[:space:]]*terminal[[:space:]]*=.*/terminal=1/' "$INI"
			info "Enabled the Terminal page (terminal=1) in $INI"
		fi
	else
		# No key at all — add it under [reqad], the section the panel reads.
		sed -i '0,/^\[reqad\]/s//[reqad]\nterminal=1/' "$INI"
		grep -qE '^terminal=1' "$INI" \
			&& info "Enabled the Terminal page (terminal=1) in $INI" \
			|| warn "Could not set terminal=1 in $INI — add it under [reqad] by hand"
	fi
else
	warn "$INI not found — enable the Terminal page manually ([reqad] terminal=1)"
fi

echo ""
info "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
info "  wetty wired up"
info ""
info "  Terminal page:  https://$(hostname -f):2087/terminal/"
info "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
