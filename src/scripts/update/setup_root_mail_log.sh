#!/bin/bash
# Keep root's mail in a size-capped file while it is not forwarded.
#
# Mail to root (cron output, lfd and monit alerts, sudo warnings) goes through
# /etc/aliases. With root mail forwarding on (Settings > System mail) the alias
# pipes it to scripts/forward_root_mail.php. With it off - the default on a new
# server - there is no root: line, the localuser router accepts root and hands
# it to local_delivery, which runs as the recipient. Root is on never_users, so
# every message defers:
#
#   User 0 set for local_delivery transport is on the never_users list
#
# and sits in the queue for days until it times out. A fresh cloud image with
# sudo set to ask sssd put hundreds of such messages a day in the queue.
#
# This adds a router just before localuser that accepts root at any local
# domain and pipes the message, as reqad, to scripts/root_mail_log.sh, which
# appends it to /var/log/reqad/root-mail (10 MB, plus one older copy). The
# router comes after system_aliases and userforward, so a root: alias (the
# panel's forwarder or one set by hand) and /root/.forward still win: the new
# router only sees root when nothing else took it.
#
# Also creates the two log files reqad:reqad 0600 (the logger cannot create
# them: /var/log/reqad is root's) and sets the logger's ownership.
#
# Idempotent, splices on content rather than line numbers, and validates the
# result with `exim -bV -C` on a root-owned scratch copy before installing it.
# Leaves an exim.conf without a localuser router alone.
#
# Usage:  bash /usr/local/reqad/scripts/update/setup_root_mail_log.sh [--dry-run]

set -u
export LC_ALL=C

if [ "$(id -u)" -ne 0 ]; then
    echo "This script must be run as root"
    exit 1
fi

DRY=0
[ "${1:-}" = "--dry-run" ] && DRY=1

CONF=/etc/exim/exim.conf
LOGDIR=/var/log/reqad
LOG=$LOGDIR/root-mail
LOGGER=/usr/local/reqad/scripts/root_mail_log.sh
STAMP=$(date +%Y%m%d%H%M%S)

# ── the log files and the logger ───────────────────────────────────────────
if [ $DRY = 0 ]; then
    id reqad >/dev/null 2>&1 || { echo "root mail log: no reqad user - nothing to do"; exit 0; }
    [ -d "$LOGDIR" ] || { mkdir -p "$LOGDIR" && chmod 0755 "$LOGDIR"; }
    for f in "$LOG" "$LOG.1"; do
        [ -f "$f" ] || : > "$f"
        chown reqad:reqad "$f"
        chmod 0600 "$f"
    done
    if [ -f "$LOGGER" ]; then
        # root-owned: exim runs it, and the panel (also reqad) must not be able
        # to rewrite it. Group reqad so the pipe (user reqad) may run it.
        chown root:reqad "$LOGGER"
        chmod 0750 "$LOGGER"
    fi
fi

# ── exim.conf ──────────────────────────────────────────────────────────────
[ -f "$CONF" ] || { echo "root mail log: no $CONF - nothing to do"; exit 0; }
grep -qE '^localuser:[[:space:]]*$' "$CONF" || {
    echo "root mail log: no localuser router in $CONF - leaving it alone"; exit 0; }

HAVE_R=0; HAVE_T=0
grep -qE '^root_mail_log:[[:space:]]*$' "$CONF" && HAVE_R=1
grep -qE '^root_mail_log_pipe:[[:space:]]*$' "$CONF" && HAVE_T=1
if [ $HAVE_R = 1 ] && [ $HAVE_T = 1 ]; then
    echo "root mail log: already set up in $CONF"
    exit 0
fi

CAND=$(mktemp) || exit 1
RBLOCK=$(mktemp) || exit 1
TBLOCK=$(mktemp) || exit 1
trap 'rm -f "$CAND" "$RBLOCK" "$TBLOCK"' EXIT

cat > "$RBLOCK" <<'ROUTER'
# Reqad: mail for root that no alias, .forward or mailbox took (root mail
# forwarding is off in Settings > System mail). localuser would deliver it as
# root, which never_users refuses, so it would sit in the queue until it timed
# out. scripts/root_mail_log.sh keeps it in /var/log/reqad/root-mail instead,
# capped at 10 MB plus one older copy. Added by
# scripts/update/setup_root_mail_log.sh.
root_mail_log:
  driver = accept
  domains = +local_domains
  local_parts = root
  transport = root_mail_log_pipe
  no_more

ROUTER

cat > "$TBLOCK" <<'TRANSPORT'
# Reqad: appends root's mail to /var/log/reqad/root-mail (router
# root_mail_log). Runs as reqad, the owner of that file; the script always
# exits 0, and ignore_status keeps a message that could not be written from
# going back to the queue or bouncing to root (who would get it here again).
root_mail_log_pipe:
  driver = pipe
  command = /usr/local/reqad/scripts/root_mail_log.sh
  user = reqad
  group = reqad
  message_prefix =
  ignore_status
  timeout = 2m

TRANSPORT

# Router just above localuser (in the routers section), transport at the top
# of the transports section.
awk -v rfile="$RBLOCK" -v tfile="$TBLOCK" -v have_r="$HAVE_R" -v have_t="$HAVE_T" '
function slurp(f,   l, s) { s = ""; while ((getline l < f) > 0) s = s l "\n"; close(f); return s }
BEGIN { rb = slurp(rfile); tb = slurp(tfile); radded = (have_r == "1"); tadded = (have_t == "1"); sect = "" }
/^begin[[:space:]]+/ { sect = $2; print; if (sect == "transports" && !tadded) { printf "\n%s", tb; tadded = 1 }; next }
sect == "routers" && /^localuser:[[:space:]]*$/ && !radded { printf "%s", rb; radded = 1 }
{ print }
END { if (!radded || !tadded) exit 1 }
' "$CONF" > "$CAND" || { echo "root mail log: ERROR - could not place the router/transport in $CONF" >&2; exit 1; }

# The router must come before localuser, in the routers section.
awk '/^begin[[:space:]]+/ { s = $2 }
     s == "routers" && /^root_mail_log:[[:space:]]*$/ { r = NR }
     s == "routers" && /^localuser:[[:space:]]*$/      { l = NR }
     s == "transports" && /^root_mail_log_pipe:[[:space:]]*$/ { t = 1 }
     END { exit (r && l && r < l && t) ? 0 : 1 }' "$CAND" || {
    echo "root mail log: ERROR - the candidate config does not route root as expected" >&2; exit 1; }

# exim only reads a -C config it trusts: root-owned, not group/world writable.
VDIR=/var/lib/reqad/eximcfg.$$
mkdir -p "$VDIR" && cp "$CAND" "$VDIR/exim.conf" \
  && chown -R root:root "$VDIR" && chmod 755 "$VDIR" && chmod 644 "$VDIR/exim.conf"
VOUT=$(exim -bV -C "$VDIR/exim.conf" 2>&1); VRC=$?
ROUTE=""
[ $VRC -eq 0 ] && ROUTE=$(exim -C "$VDIR/exim.conf" -bt "root@$(hostname -f 2>/dev/null || hostname)" 2>&1)
rm -rf "$VDIR"

if [ $VRC -ne 0 ]; then
    echo "root mail log: ERROR - exim rejected the new config, nothing changed:" >&2
    echo "$VOUT" >&2
    exit 1
fi

if [ $DRY = 1 ]; then
    echo "root mail log: would add the root_mail_log router/transport to $CONF. Routing test:"
    echo "$ROUTE" | sed 's/^/    /'
    exit 0
fi

cp -a "$CONF" "$CONF.bak-rootmaillog-$STAMP"
cat "$CAND" > "$CONF"
echo "root mail log: root's mail now goes to $LOG when it is not forwarded (backup: $CONF.bak-rootmaillog-$STAMP)"

systemctl reload exim 2>/dev/null || systemctl restart exim 2>/dev/null \
    || { echo "root mail log: WARNING - could not reload exim, do it by hand." >&2; exit 1; }

# Root's mail already waiting in the queue (deferred or frozen) is delivered
# into the log now instead of at the next retry. Only messages for root.
exim -qRff 'root@' >/dev/null 2>&1 &
exit 0
