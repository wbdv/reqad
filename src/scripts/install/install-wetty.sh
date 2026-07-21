#!/bin/bash
#
# install-wetty.sh — install and build wetty, the browser terminal behind
# Reqad's Terminal page (etc/server-software.ini → [reqad] terminal=1).
#
# wetty is a Node app, not an RPM, so it cannot be a package dependency. This
# script provisions it. Safe to re-run: it updates an existing checkout rather
# than starting over.
#
# Usage: sudo /usr/local/reqad/scripts/install/install-wetty.sh
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
PKG_UNIT=/usr/lib/systemd/system/wetty.service

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

# ── 3. pnpm ──────────────────────────────────────────────────────────────────
# Only needed to install deps and build. The service runs node directly.
# corepack ships with Node and honours wetty's packageManager pin; fall back to
# a global npm install. Deliberately not the get.pnpm.io installer — it puts
# pnpm (and sometimes its own node) in ~/.local/share/pnpm and prepends that to
# PATH, which is how a stray node ends up shadowing /usr/bin/node.
if ! command -v pnpm >/dev/null 2>&1; then
	info "Installing pnpm..."
	corepack enable pnpm 2>/dev/null || npm install -g pnpm
fi
command -v pnpm >/dev/null 2>&1 || error "pnpm install failed"
info "pnpm $(pnpm --version 2>/dev/null || echo '?')"

# ── 4. Source checkout ───────────────────────────────────────────────────────
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

# ── 5. Install and build ─────────────────────────────────────────────────────
# node_modules is wiped rather than reused: a native addon built against a
# different Node major survives a plain install and fails at runtime with
# ERR_DLOPEN_FAILED / NODE_MODULE_VERSION mismatch.
info "Installing dependencies (this compiles native modules, ~1-2 min)..."
rm -rf "$WETTY_DIR/node_modules"
( cd "$WETTY_DIR" && PATH=/usr/bin:$PATH pnpm install )

info "Building wetty..."
( cd "$WETTY_DIR" && PATH=/usr/bin:$PATH pnpm run build )

[ -f "$WETTY_DIR/build/main.js" ] || error "Build produced no $WETTY_DIR/build/main.js"

# ── 6. Service ───────────────────────────────────────────────────────────────
[ -f "$PKG_UNIT" ] || error "$PKG_UNIT missing — reinstall the reqad package"

# A hand-made unit in /etc overrides the packaged one; retire it.
bash /usr/local/reqad/scripts/update/update_wetty_shell.sh || true

systemctl daemon-reload
systemctl enable --now wetty

sleep 2
if systemctl is-active --quiet wetty; then
	info "wetty is running on 127.0.0.1:3000"
else
	warn "wetty did not start — check: journalctl -u wetty -n 50"
fi

echo ""
info "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
info "  wetty installed"
info ""
info "  Still required:"
info "   1. nginx must proxy /wetty/ to 127.0.0.1:3000 (websocket upgrade)"
info "      — the panel loads it in an iframe at https://<host>/wetty/"
info "   2. Enable the Terminal page: [reqad] terminal=1 in"
info "      /usr/local/reqad/etc/server-software.ini"
info "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
