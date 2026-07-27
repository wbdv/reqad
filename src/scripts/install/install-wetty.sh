#!/bin/bash
#
# install-wetty.sh — install and build wetty, the browser terminal behind
# Reqad's Terminal page (etc/server-software.ini → [reqad] terminal=1).
#
# wetty is a Node app, not an RPM, so it cannot be a package dependency. This
# script provisions it. Safe to re-run: it updates an existing checkout rather
# than starting over.
#
# Usage (as root): /usr/local/reqad/scripts/install/install-wetty.sh
#
set -euo pipefail

WETTY_DIR=/root/wetty
WETTY_REPO=https://github.com/butlerx/wetty.git
# Pinned: wetty 2.7.0. main has moved to 3.x, which changes the client
# internals that templates/terminal.php reaches into (window.wetty_term) to
# send Ctrl+D on close. Do not float this without testing that path.
WETTY_COMMIT=0ec642a27302bb4c53244715e089e12a7fefe199
NODE_STREAM=20
NODE_BIN=/usr/bin/node
NPM_BIN=/usr/bin/npm
PKG_UNIT=/usr/lib/systemd/system/wetty.service
WETTY_PORT=3000
# The panel has its own nginx instance (reqad.service), separate from any
# nginx serving customer sites.
PANEL_CONF_DIR=/etc/reqad/conf.d
PANEL_NGINX_CONF=/etc/reqad/nginx.conf
INI=/usr/local/reqad/etc/server-software.ini

# wetty ships a pnpm lockfile but no package-lock.json, so npm re-resolves the
# tree and picks newer majors than the lockfile pinned. Two of those break the
# build, so pin them back to exactly what pnpm resolved:
#   esbuild-sass-plugin ^3.3.1 -> npm takes 3.7.0, which demands esbuild
#   >=0.27.3 (project has 0.21.5) and requires sass-embedded at build time.
SASS_PLUGIN_PIN=esbuild-sass-plugin@3.3.1
SASS_EMBEDDED_PIN=sass-embedded@1.77.5

GREEN='\033[0;32m'; YELLOW='\033[1;33m'; RED='\033[0;31m'; NC='\033[0m'
info()  { echo -e "${GREEN}[INFO]${NC}  $1"; }
warn()  { echo -e "${YELLOW}[WARN]${NC}  $1"; }
error() { echo -e "${RED}[ERROR]${NC} $1"; exit 1; }

[ "$(id -u)" -eq 0 ] || error "Must run as root"

# ── 1. Node 20 ───────────────────────────────────────────────────────────────
# wetty 2.7.0 depends on gc-stats 1.4.1, a 2018 native addon that does not
# build against Node 22's V8 API. The module stream and the installed package
# can disagree (enabling a stream does not downgrade what is already there),
# so assert the actual binary rather than trusting dnf's view.
node_major() {
	[ -x "$NODE_BIN" ] || return 1
	"$NODE_BIN" -v 2>/dev/null | sed -E 's/^v([0-9]+).*/\1/'
}

if [ "$(node_major || echo none)" != "$NODE_STREAM" ]; then
	info "Installing Node ${NODE_STREAM} (found: $("$NODE_BIN" -v 2>/dev/null || echo 'no node'))"
	dnf remove -y nodejs nodejs-full-i18n npm >/dev/null 2>&1 || true
	dnf module reset -y nodejs >/dev/null
	dnf module enable -y "nodejs:${NODE_STREAM}" >/dev/null
	dnf install -y nodejs npm
fi

ACTUAL=$(node_major || echo none)
[ "$ACTUAL" = "$NODE_STREAM" ] || \
	error "Node ${NODE_STREAM} required, $NODE_BIN reports v${ACTUAL}. Remove any node earlier in PATH (e.g. /usr/local/bin/node) and re-run."
info "Node $("$NODE_BIN" -v) at $NODE_BIN"

# ── 2. Build toolchain (node-pty and gc-stats are native) ────────────────────
info "Installing build dependencies..."
dnf install -y git gcc-c++ make python3

# npm comes with the nodejs package — no extra package manager to install.
# (Earlier revisions used pnpm here; provisioning it was the least reliable
# step in the whole script, and it is only ever needed to build.)
[ -x "$NPM_BIN" ] || error "$NPM_BIN not found — install the nodejs/npm packages"
info "npm $("$NPM_BIN" --version 2>/dev/null || echo '?')"

# ── 3. Source checkout ───────────────────────────────────────────────────────
if [ -d "$WETTY_DIR/.git" ]; then
	info "Updating existing checkout in $WETTY_DIR"
	git -C "$WETTY_DIR" fetch --quiet origin
else
	[ -e "$WETTY_DIR" ] && error "$WETTY_DIR exists but is not a git checkout — move it aside and re-run"
	info "Cloning wetty into $WETTY_DIR"
	git clone --quiet "$WETTY_REPO" "$WETTY_DIR"
fi

git -C "$WETTY_DIR" checkout --quiet "$WETTY_COMMIT"
info "Checked out $(git -C "$WETTY_DIR" describe --tags --always)"

# ── 4. Install and build ─────────────────────────────────────────────────────
# node_modules is wiped rather than reused: a native addon built against a
# different Node major survives a plain install and fails at runtime with
# ERR_DLOPEN_FAILED / NODE_MODULE_VERSION mismatch.
#
# --legacy-peer-deps is required: wetty 2.7.0's dev tree has peer conflicts that
# npm (unlike pnpm) refuses to resolve on its own.
info "Installing dependencies (this compiles native modules, ~1-2 min)..."
rm -rf "$WETTY_DIR/node_modules"
( cd "$WETTY_DIR" && PATH=/usr/bin:$PATH "$NPM_BIN" install --legacy-peer-deps --no-audit --no-fund )

# Pin back the two packages npm floats past the lockfile (see top of file).
# --no-save so package.json stays untouched and the checkout remains clean.
info "Pinning build deps to the versions wetty was locked against..."
( cd "$WETTY_DIR" && PATH=/usr/bin:$PATH "$NPM_BIN" install --no-save --legacy-peer-deps \
	--no-audit --no-fund "$SASS_PLUGIN_PIN" "$SASS_EMBEDDED_PIN" )

info "Building wetty..."
( cd "$WETTY_DIR" && PATH=/usr/bin:$PATH "$NPM_BIN" run build )

[ -f "$WETTY_DIR/build/main.js" ] || error "Build produced no $WETTY_DIR/build/main.js"

# ── 5. Service ───────────────────────────────────────────────────────────────
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

# ── 6. Panel vhost: proxy /wetty to the service ──────────────────────────────
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

# ── 7. Enable the Terminal page ──────────────────────────────────────────────
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
info "  wetty installed and wired up"
info ""
info "  Terminal page:  https://$(hostname -f):2087/terminal/"
info "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
