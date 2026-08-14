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
# step in the whole script, and it is only ever needed to build. Step 4 patches
# out the one place upstream still hardcodes it.)
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

# Reqad's source patches (drop the pnpm requirement from build.js, terminal font
# defaults, self-hosted webfont). Shared with /root/build_wetty_rpm.sh so the
# from-source and packaged builds stay identical. Re-runnable.
bash /usr/local/reqad/scripts/install/wetty-patch-source.sh "$WETTY_DIR"

info "Building wetty..."
( cd "$WETTY_DIR" && PATH=/usr/bin:$PATH "$NPM_BIN" run build )

[ -f "$WETTY_DIR/build/main.js" ] || error "Build produced no $WETTY_DIR/build/main.js"

# ── 5. Wire it up ────────────────────────────────────────────────────────────
# Service, panel nginx proxy and the terminal=1 flag all live in the shared
# configure script — the reqad-wetty RPM runs the same one from %post.
exec /usr/local/reqad/scripts/install/wetty-configure.sh
