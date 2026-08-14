#!/bin/bash
#
# wetty-patch-source.sh — apply Reqad's patches to a fresh wetty checkout,
# before it is built. Called by both scripts/install/install-wetty.sh (build on
# the server) and /root/build_wetty_rpm.sh (build once, ship as reqad-wetty),
# so the two paths cannot drift.
#
# Every patch is guarded, so re-running on an already-patched tree is a no-op.
#
# Usage: wetty-patch-source.sh /path/to/wetty/checkout
#
set -euo pipefail

SRC="${1:-}"
[ -n "$SRC" ] && [ -f "$SRC/package.json" ] || { echo "usage: $0 <wetty-source-dir>" >&2; exit 1; }

GREEN='\033[0;32m'; NC='\033[0m'
info() { echo -e "${GREEN}[INFO]${NC}  $1"; }

# Terminal font. The browser resolves this, not the server, so it has to be a
# stack: Nerd Font first for anyone who has it locally (the Mono variant — its
# icons are single-cell, so columns stay aligned), then plain JetBrains Mono,
# which the panel self-hosts as a webfont below and everyone therefore gets,
# then the platform defaults, then monospace as the floor.
FONT_STACK="'\"JetBrainsMono Nerd Font Mono\", \"JetBrains Mono\", \"Cascadia Mono\", Menlo, Consolas, \"DejaVu Sans Mono\", \"Liberation Mono\", monospace'"
# Strings, not numbers: wetty's own options page offers these as string enums,
# so matching it keeps the select boxes in sync with what is actually applied.
FONT_WEIGHT="'500'"
FONT_WEIGHT_BOLD="'700'"

# ── 1. build.js: drop the pnpm requirement ───────────────────────────────────
# build.js spawns `pnpm tsc -p ...` twice (browser typecheck, server compile) —
# the only spot upstream still assumes pnpm, and it kills the build with
# `spawn pnpm ENOENT`. tsc is a local dev dependency either way, so npx runs it
# from node_modules/.bin with no network and no second package manager.
if grep -q "cmd('pnpm'" "$SRC/build.js"; then
	sed -i "s/cmd('pnpm'/cmd('npx'/g" "$SRC/build.js"
	info "build.js: tsc now invoked via npx instead of pnpm"
fi

# esbuild resolves every url() in the CSS at build time and fails on the
# webfonts added in step 4 — those are absolute paths served by the panel at
# runtime, not files in the wetty tree. Mark them external so the reference is
# emitted verbatim.
if ! grep -q "external:" "$SRC/build.js"; then
	python3 - "$SRC/build.js" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()
old = "        bundle: true,"
new = ("        bundle: true,\n"
       "        // Reqad: /dist/* is served by the panel at runtime, not bundled.\n"
       "        external: ['/dist/*'],")
if old not in src:
    sys.exit("build.js: esbuild config line not found — upstream changed, patch by hand")
open(path, 'w').write(src.replace(old, new, 1))
PY
	info "build.js: /dist/* marked external for esbuild"
fi

# ── 2. Terminal defaults ─────────────────────────────────────────────────────
# The live terminal's defaults. Upstream sets only fontSize here, so xterm.js
# falls back to its own 'courier-new, courier, monospace'. Users who have set
# their own options keep them — this is only the default for localStorage-less
# browsers.
LOAD_TS="$SRC/src/client/wetty/term/load.ts"
if ! grep -q 'fontFamily' "$LOAD_TS"; then
	python3 - "$LOAD_TS" "$FONT_STACK" "$FONT_WEIGHT" "$FONT_WEIGHT_BOLD" <<'PY'
import sys
path, stack, weight, bold = sys.argv[1:5]
src = open(path).read()
old = "  xterm: { fontSize: 14 },"
new = ("  xterm: {\n"
       "    fontSize: 14,\n"
       f"    fontFamily: {stack},\n"
       f"    fontWeight: {weight},\n"
       f"    fontWeightBold: {bold},\n"
       "  },")
if old not in src:
    sys.exit(f"load.ts: expected defaults line not found — upstream changed, patch by hand")
open(path, 'w').write(src.replace(old, new, 1))
PY
	info "load.ts: terminal font defaults set"
fi

# ── 3. Options-page defaults ─────────────────────────────────────────────────
# Same values in the in-iframe options panel, or it reports a font that is not
# the one in use.
DEFAULTS_JS="$SRC/src/assets/xterm_config/xterm_defaults.js"
if grep -q "fontFamily: 'courier-new, courier, monospace'" "$DEFAULTS_JS"; then
	python3 - "$DEFAULTS_JS" "$FONT_STACK" "$FONT_WEIGHT" "$FONT_WEIGHT_BOLD" <<'PY'
import sys
path, stack, weight, bold = sys.argv[1:5]
src = open(path).read()
src = src.replace("fontFamily: 'courier-new, courier, monospace',", f"fontFamily: {stack},", 1)
src = src.replace("fontWeight: 'normal',", f"fontWeight: {weight},", 1)
src = src.replace("fontWeightBold: 'bold',", f"fontWeightBold: {bold},", 1)
open(path, 'w').write(src)
PY
	info "xterm_defaults.js: options-page font defaults set"
fi

# ── 4. Self-hosted webfont ───────────────────────────────────────────────────
# The terminal iframe is same-origin with the panel (https://host:2087/wetty/),
# so it can pull the panel's own copies straight from /dist/fonts. Plain
# JetBrains Mono, not the Nerd Font patch: same letterforms, ~90KB per weight
# instead of several hundred, and the icon glyphs are the only thing missing.
STYLES_SCSS="$SRC/src/assets/scss/styles.scss"
if ! grep -q 'JetBrains Mono' "$STYLES_SCSS"; then
	cat >> "$STYLES_SCSS" <<'CSS'

/* Reqad: self-hosted terminal font, served by the panel from /dist/fonts.
   Weights match the xterm fontWeight/fontWeightBold defaults (500/700).
   font-display: block, not swap: xterm.js measures the glyph cell once at
   startup, and a font swapping in afterwards leaves the grid misaligned until
   the next resize. Same-origin and ~90KB, so the block window is unnoticeable. */
@font-face {
  font-family: 'JetBrains Mono';
  font-style: normal;
  font-weight: 500;
  font-display: block;
  src: url('/dist/fonts/JetBrainsMono-Medium.woff2') format('woff2');
}
@font-face {
  font-family: 'JetBrains Mono';
  font-style: normal;
  font-weight: 700;
  font-display: block;
  src: url('/dist/fonts/JetBrainsMono-Bold.woff2') format('woff2');
}
@font-face {
  font-family: 'JetBrains Mono';
  font-style: italic;
  font-weight: 500;
  font-display: block;
  src: url('/dist/fonts/JetBrainsMono-MediumItalic.woff2') format('woff2');
}
@font-face {
  font-family: 'JetBrains Mono';
  font-style: italic;
  font-weight: 700;
  font-display: block;
  src: url('/dist/fonts/JetBrainsMono-BoldItalic.woff2') format('woff2');
}
CSS
	info "styles.scss: @font-face for the self-hosted JetBrains Mono added"
fi

info "wetty source patched"
