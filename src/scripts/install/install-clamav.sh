#!/bin/bash
#
# Reqad — install and activate ClamAV mail scanning on a server.
#
#   curl -sL https://repo.reqad.net/install-clamav.sh | bash
#
# Environment overrides:
#   CLAMAV_VERSION=1.5.4        upstream engine version to fetch
#   CLAMAV_SHA256=<hex>         expected checksum (skip check with "none")
#   REQAD_TIER=prod|test        which Reqad repo tier to install our RPMs from
#   WITH_SIGS=yes|no            also install the third-party signature feeds
#   SCAN_MODE=tag|deny|none     wire exim straight away, and how
#
# Installs, in order:
#   1. the upstream ClamAV build from clamav.net (it lives under /usr/local and
#      ships no systemd units -- that is what reqad-clamav supplies)
#   2. reqad-clamav        -- units, config, exim wiring helper, /email/ card
#   3. reqad-clamav-sigs   -- Sanesecurity/Foxhole/URLhaus feeds  (WITH_SIGS)
set -uo pipefail

CLAMAV_VERSION="${CLAMAV_VERSION:-1.5.4}"
CLAMAV_SHA256="${CLAMAV_SHA256:-1f4d381226b3cb5f4b1897253a496cf774a975fa1934e8610d7899f614f144dc}"
REQAD_TIER="${REQAD_TIER:-prod}"
WITH_SIGS="${WITH_SIGS:-yes}"
SCAN_MODE="${SCAN_MODE:-none}"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[0;33m'; NC='\033[0m'
LOG=./install_clamav.log
step() { echo -e "${GREEN}[*]${NC} $*"; }
warn() { echo -e "${YELLOW}[!]${NC} $*"; }
die()  { echo -e "${RED}[x]${NC} $*" >&2; exit 1; }

echo -e "${YELLOW}"
echo "┌──────────────────────────────────────────────────────────────────┐"
printf "│%-66s│\\n" "  Reqad - ClamAV mail scanning installer"
echo "└──────────────────────────────────────────────────────────────────┘"
echo -e "${NC}"

[ "$EUID" -eq 0 ] || die "run as root"
[ "$(uname -m)" = "x86_64" ] || die "the upstream ClamAV build is x86_64 only (got $(uname -m))"

EL=$(rpm -E %{rhel} 2>/dev/null)
case "$EL" in
    8|9) step "Detected EL${EL}" ;;
    *)   die "unsupported release (rpm says rhel=${EL:-unknown}); EL8 or EL9 required" ;;
esac

# Reqad itself must be here: reqad-clamav ships the /email/ card and the exim
# wiring helper, and depends on the panel.
# Check for the panel itself, not just the RPM: a development or source
# install has /usr/local/reqad populated without owning a reqad package.
if ! rpm -q reqad >/dev/null 2>&1 && [ ! -f /usr/local/reqad/public_html/index.php ]; then
    die "Reqad does not appear to be installed here — run the Reqad installer first"
fi

# ── 1. Upstream ClamAV ───────────────────────────────────────────────────────
if [ -x /usr/local/sbin/clamd ]; then
    have=$(rpm -q --qf '%{VERSION}' clamav 2>/dev/null || echo "unknown")
    step "ClamAV already present (${have}) — skipping engine install"
else
    URL="https://www.clamav.net/downloads/production/clamav-${CLAMAV_VERSION}.linux.x86_64.rpm"
    TMP=$(mktemp -d); trap 'rm -rf "$TMP"' EXIT
    step "Downloading ClamAV ${CLAMAV_VERSION} (~45MB) from clamav.net"
    curl -fsSL --max-time 300 -o "$TMP/clamav.rpm" "$URL" \
        || die "download failed: $URL"

    # The clamav.net RPM is UNSIGNED, so a checksum is the only integrity check
    # available. Pinned for the default version; pass CLAMAV_SHA256 when you
    # bump CLAMAV_VERSION, or "none" to skip deliberately.
    if [ "$CLAMAV_SHA256" = "none" ]; then
        warn "checksum verification skipped (CLAMAV_SHA256=none)"
    else
        got=$(sha256sum "$TMP/clamav.rpm" | awk '{print $1}')
        if [ "$got" != "$CLAMAV_SHA256" ]; then
            die "checksum mismatch for clamav-${CLAMAV_VERSION}
   expected $CLAMAV_SHA256
   got      $got
 If you changed CLAMAV_VERSION, pass the matching CLAMAV_SHA256."
        fi
        step "Checksum verified"
    fi

    step "Installing the ClamAV engine"
    # --nogpgcheck: upstream ships it unsigned; the sha256 above is the check.
    dnf install -y --nogpgcheck "$TMP/clamav.rpm" >>"$LOG" 2>&1 \
        || die "clamav install failed — see $LOG"
fi

# ── 2. reqad-clamav ──────────────────────────────────────────────────────────
DNFTIER=()
[ "$REQAD_TIER" = "test" ] && DNFTIER=(--enablerepo=reqad-test)
step "Installing reqad-clamav (units, config, exim helper) from ${REQAD_TIER}"
dnf install -y "${DNFTIER[@]}" reqad-clamav >>"$LOG" 2>&1 \
    || die "reqad-clamav install failed — see $LOG"

# reqad-clamav %pre creates this account. If it is still missing, every later
# step fails in a confusing way -- rpm silently falls back to root:root, the
# /run/clamd tmpfiles entry is rejected, and freshclam aborts with "Failed to
# get information about user clamav". Stop here instead, with the real reason.
getent passwd clamav >/dev/null 2>&1 \
    || die "the 'clamav' user was not created — you are probably on an older
   reqad-clamav than 1.0.0-4. Upgrade it, or create the account by hand:
     groupadd -r clamav
     useradd -r -g clamav -d /usr/local/share/clamav -s /sbin/nologin clamav"

# ── 3. First signature download ──────────────────────────────────────────────
# clamd.service has ConditionPathExistsGlob=.../daily.c?d, so it will not start
# until freshclam has fetched a database. Do that now, in the foreground: it is
# ~300MB and the box is useless for scanning until it finishes.
if ! ls /usr/local/share/clamav/daily.c?d >/dev/null 2>&1; then
    # The freshclam DAEMON holds an exclusive lock on its log file, so a
    # foreground run alongside it dies with "Failed to lock the log file:
    # Resource temporarily unavailable" and fetches nothing. That only bites on
    # a re-run (first install has no daemon yet), which is exactly the recovery
    # case. Stop it for the duration; it is re-enabled below either way.
    if systemctl is-active --quiet freshclam 2>/dev/null; then
        step "Pausing the freshclam daemon for the initial fetch"
        systemctl stop freshclam >>"$LOG" 2>&1 || :
    fi
    step "Fetching the initial signature database (~300MB, this takes a few minutes)"
    /usr/local/bin/freshclam --config-file=/usr/local/etc/freshclam.conf >>"$LOG" 2>&1 \
        || warn "freshclam reported an error — see $LOG"
fi

systemctl enable --now freshclam.service >>"$LOG" 2>&1 || :
systemctl enable --now clamd.service     >>"$LOG" 2>&1 || :

step "Waiting for clamd to load its databases"
for _ in $(seq 1 90); do
    [ -S /run/clamd/clamd.sock ] && break
    sleep 2
done
if [ -S /run/clamd/clamd.sock ]; then
    step "clamd is listening on /run/clamd/clamd.sock"
else
    warn "clamd has not opened its socket yet — check: journalctl -u clamd"
fi

# ── 4. Third-party feeds ─────────────────────────────────────────────────────
case "$WITH_SIGS" in
 yes|true|1)
    step "Installing reqad-clamav-sigs (Sanesecurity, Foxhole, URLhaus)"
    dnf install -y "${DNFTIER[@]}" reqad-clamav-sigs >>"$LOG" 2>&1 \
        || warn "reqad-clamav-sigs install failed — see $LOG"
    step "Fetching third-party signatures (the fetcher pauses a few minutes on purpose)"
    /usr/libexec/reqad/clamav-sigs-update.sh >>"$LOG" 2>&1 \
        || warn "first signature fetch reported an error — see $LOG"
    ;;
 *) step "Skipping third-party feeds (WITH_SIGS=$WITH_SIGS)" ;;
esac

# ── 5. Optional exim wiring ──────────────────────────────────────────────────
case "$SCAN_MODE" in
 tag|deny)
    step "Wiring ClamAV into exim (${SCAN_MODE} mode)"
    /usr/libexec/reqad/clamav-exim-wire.sh enable "$SCAN_MODE" >>"$LOG" 2>&1 \
        || warn "exim wiring failed — see $LOG"
    ;;
 *) : ;;
esac

# ── Summary ──────────────────────────────────────────────────────────────────
sigs=$(grep -oE '[0-9]+ signatures' /var/log/clamav/clamd.log 2>/dev/null | tail -1)
mode=$(/usr/libexec/reqad/clamav-exim-wire.sh status 2>/dev/null || echo unknown)
clamd_state=$(systemctl is-active clamd 2>/dev/null)
fresh_state=$(systemctl is-active freshclam 2>/dev/null)

# Fixed inner width so every row closes with its own border. Colour codes sit
# OUTSIDE the padded field, or printf counts the escape bytes as width and the
# right-hand border drifts.
BW=66
line() { printf "${GREEN}│${NC}%-*s${GREEN}│${NC}\n" "$BW" "  $1"; }
rule() { printf "${GREEN}%s${NC}\n" "$1"; }

echo
rule "┌──────────────────────────────────────────────────────────────────┐"
line "Done"
line ""
line "engine      : $(rpm -q --qf '%{VERSION}' clamav 2>/dev/null)"
line "clamd       : ${clamd_state}"
line "freshclam   : ${fresh_state}"
line "signatures  : ${sigs:-not loaded yet}"
line "mail scan   : ${mode}"
rule "└──────────────────────────────────────────────────────────────────┘"
echo

if [ "$clamd_state" != "active" ]; then
    warn "clamd is not running yet."
    if ! ls /usr/local/share/clamav/daily.c?d >/dev/null 2>&1; then
        echo "   No signature database was downloaded, and clamd.service will not"
        echo "   start without one (ConditionPathExistsGlob), so it is not an error"
        echo "   you will find in clamd's own log. Check freshclam first:"
        echo "       journalctl -u freshclam -n 40"
        echo "       tail -40 $LOG"
    else
        echo "   Signatures are present, so this is clamd itself. Loading ~300MB can"
        echo "   take a couple of minutes; if it persists:"
        echo "       journalctl -u clamd -n 40"
    fi
    echo
fi

if [ "$mode" = "disabled" ]; then
    echo "Mail is NOT being scanned yet. Turn it on from the panel:"
    echo "    Email  ->  ClamAV card  ->  Enable scanning"
    echo "or:  /usr/libexec/reqad/clamav-exim-wire.sh enable tag"
    echo
    echo "Start in 'tag' mode: it adds X-Virus-* headers and logs every message"
    echo "but rejects nothing, so you can measure false positives before"
    echo "switching to 'deny'."
    echo
fi

echo "To force a signature update by hand, give it its own log file -- the"
echo "running daemon holds an exclusive lock on the default one:"
echo "    freshclam --log=/tmp/freshclam.log"
echo
echo "The signature update timer is left DISABLED on purpose. Enable it once"
echo "you are happy with the false-positive rate:"
echo "    systemctl enable --now clamav-unofficial-sigs.timer"
