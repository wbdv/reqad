#!/bin/bash
# Panel firewall allowlist: only listed addresses may reach the panel port
# (2087) through csf, instead of the global TCP_IN / TCP6_IN opening.
#
#   panel_fw_allow.sh status                  JSON: csf, on, port_open, rules, pending
#   panel_fw_allow.sh on <cidr>[,note]...     2087 out of TCP_IN/TCP6_IN, one rule per entry:
#                                             tcp|in|d=2087|s=<cidr> # reqad-panel-allow <note>
#   panel_fw_allow.sh off                     drop the rules, put 2087 back where it was
#   panel_fw_allow.sh confirm                 keep the rules of the last `on` (cancel the revert)
#   panel_fw_allow.sh revert                  restore the rules from before the last `on`
#
# `on` is a full sync: every tagged line is rewritten from the arguments, lines
# without the tag are never touched (same pattern as sync_mysql_remote_fw.sh).
# The panel always passes the admin's current IP among the entries.
#
# Auto-revert: `on` snapshots csf.conf + csf.allow (only when no revert is
# pending already, so the rollback point is always the last CONFIRMED state)
# and schedules `revert` with systemd-run in REVERT_SECS seconds. The panel
# calls `confirm` when the admin says the panel still opens - a lockout heals
# itself. `off` only opens the port, so it needs no safety net and drops any
# pending revert. From SSH, `reqad auth firewall off` is the way back in.
#
# Which lists held the port before the first `on` is remembered in
# $STATE/ports, so `off` restores exactly that (TCP6_IN often lacks 2087).
#
# Run as root (the panel calls it via sudo).

[ "$(id -u)" -eq 0 ] || { echo "must run as root"; exit 1; }

export PORT=2087
export CSF_CONF=/etc/csf/csf.conf
export CSF_ALLOW=/etc/csf/csf.allow
export STATE=/var/lib/reqad/panel-fw
SNAP=$STATE/rollback
UNIT=reqad-panel-fw-revert
REVERT_SECS=120
SELF=$(readlink -f "$0")

CMD=$1
shift

have_csf() {
    [ -f "$CSF_CONF" ] && [ -f "$CSF_ALLOW" ] && command -v csf >/dev/null 2>&1
}

cancel_timer() {
    systemctl stop "$UNIT.timer" >/dev/null 2>&1
    systemctl reset-failed "$UNIT.timer" "$UNIT.service" >/dev/null 2>&1
}

# A set = csf.conf, csf.allow and $STATE/ports. The csf files are written in
# place (cat >) so they keep owner, mode and inode.
save_set() {    # save_set <dir>
    mkdir -p "$1" && chmod 0700 "$1"
    cat "$CSF_CONF" > "$1/csf.conf" && cat "$CSF_ALLOW" > "$1/csf.allow" || return 1
    rm -f "$1/ports"
    [ -f "$STATE/ports" ] && cp -p "$STATE/ports" "$1/ports"
    return 0
}
restore_set() { # restore_set <dir>
    cat "$1/csf.conf" > "$CSF_CONF"
    cat "$1/csf.allow" > "$CSF_ALLOW"
    if [ -f "$1/ports" ]; then cp -p "$1/ports" "$STATE/ports"; else rm -f "$STATE/ports"; fi
}

# The file work: status prints JSON; on/off write new csf.conf / csf.allow
# into the directory given as the first argument (and $STATE/ports).
fw_py() {
    python3 - "$@" <<'PY'
import ipaddress, json, os, re, sys, time

port = int(os.environ['PORT'])
TAG = '# reqad-panel-allow'
ports_file = os.environ['STATE'] + '/ports'
conf = open(os.environ['CSF_CONF']).read()
allow = open(os.environ['CSF_ALLOW']).read().splitlines(True)
KEYS = ('TCP_IN', 'TCP6_IN')

def get_list(key):
    m = re.search(r'^%s\s*=\s*"([^"]*)"' % key, conf, re.M)
    return m.group(1) if m else None

def set_list(text, key, value):
    return re.sub(r'^(%s\s*=\s*")[^"]*(")' % key, lambda m: m.group(1) + value + m.group(2),
                  text, count=1, flags=re.M)

def parts(spec):
    for p in spec.split(','):
        p = p.strip()
        if not p:
            continue
        a, _, b = p.partition(':')
        try:
            yield p, int(a), int(b) if b else int(a)
        except ValueError:
            yield p, None, None

def covers(spec):
    return spec is not None and any(lo is not None and lo <= port <= hi for _, lo, hi in parts(spec))

def without(spec):      # the list minus the port, splitting a range around it
    out = []
    for p, lo, hi in parts(spec):
        if lo is None or not lo <= port <= hi:
            out.append(p)
            continue
        if lo < port:
            out.append(str(lo) if lo == port - 1 else '%d:%d' % (lo, port - 1))
        if port < hi:
            out.append(str(hi) if hi == port + 1 else '%d:%d' % (port + 1, hi))
    return ','.join(out)

def rules():
    out = []
    for line in allow:
        if TAG not in line:
            continue
        rule, _, note = line.partition(TAG)
        m = re.search(r'\|s=([0-9A-Fa-f:./]+)', rule)
        if m:
            out.append({'cidr': m.group(1), 'note': note.strip()})
    return out

def untagged():
    return [l for l in allow if TAG not in l]

def write(outdir, new_conf, new_allow):
    with open(outdir + '/csf.conf', 'w') as f:
        f.write(new_conf)
    with open(outdir + '/csf.allow', 'w') as f:
        f.write(''.join(new_allow))

cmd = sys.argv[1]

if cmd == 'status':
    pending = -1
    try:
        pending = max(0, int(open(os.environ['STATE'] + '/rollback/deadline').read()) - int(time.time()))
    except (OSError, ValueError):
        pass
    r = rules()
    print(json.dumps({'csf': True, 'on': bool(r), 'port': port,
                      'port_open': any(covers(get_list(k)) for k in KEYS),
                      'rules': r, 'pending': pending}))

elif cmd == 'on':
    outdir, entries = sys.argv[2], sys.argv[3:]
    lines, seen = [], set()
    for e in entries:
        cidr, _, note = e.partition(',')
        try:
            net = ipaddress.ip_network(cidr.strip(), strict=False)
        except ValueError:
            sys.exit('not an IP address or network: ' + cidr)
        if net.prefixlen == 0:
            sys.exit('refused: %s would allow every address' % cidr)
        s = str(net.network_address) if net.prefixlen == net.max_prefixlen else str(net)
        if s in seen:
            continue
        seen.add(s)
        note = re.sub(r'[^A-Za-z0-9 ._:()@/-]', '', note).strip()[:60]
        lines.append('tcp|in|d=%d|s=%s %s%s\n' % (port, s, TAG, ' ' + note if note else ''))
    if not lines:
        sys.exit('no addresses given')
    # First `on`: remember which lists held the port, and their exact text.
    if not os.path.exists(ports_file):
        with open(ports_file, 'w') as f:
            json.dump({k: {'had': covers(get_list(k)), 'orig': get_list(k),
                           'cut': without(get_list(k)) if covers(get_list(k)) else None} for k in KEYS}, f)
    new_conf = conf
    for k in KEYS:
        if covers(get_list(k)):
            new_conf = set_list(new_conf, k, without(get_list(k)))
    new_allow = untagged()
    if new_allow and not new_allow[-1].endswith('\n'):
        new_allow[-1] += '\n'
    write(outdir, new_conf, new_allow + lines)

elif cmd == 'off':
    outdir = sys.argv[2]
    try:
        had = json.load(open(ports_file))
    except (OSError, ValueError):
        had = {'TCP_IN': {'had': True}}
    new_conf = conf
    for k in KEYS:
        cur, h = get_list(k), had.get(k) or {}
        if not h.get('had') or cur is None or covers(cur):
            continue
        if h.get('cut') is not None and cur == h['cut']:    # untouched since: the exact old text
            new_conf = set_list(new_conf, k, h['orig'])
        else:
            new_conf = set_list(new_conf, k, (cur.rstrip(',') + ',' if cur.strip() else '') + str(port))
    write(outdir, new_conf, untagged())
    try:
        os.unlink(ports_file)
    except OSError:
        pass
PY
}

# Install the files fw_py wrote to $1 when they differ; 0 = changed.
install_new() {
    local changed=1
    cmp -s "$1/csf.conf" "$CSF_CONF" || { cat "$1/csf.conf" > "$CSF_CONF"; changed=0; }
    cmp -s "$1/csf.allow" "$CSF_ALLOW" || { cat "$1/csf.allow" > "$CSF_ALLOW"; changed=0; }
    return $changed
}

if [ "$CMD" = status ] && ! have_csf; then
    echo '{"csf":false}'
    exit 0
fi
case "$CMD" in
    on|off|confirm|revert) have_csf || { echo "nocsf"; exit 0; } ;;
    status) ;;
    *) echo "usage: $0 status | on <cidr>[,note]... | off | confirm | revert"; exit 2 ;;
esac

mkdir -p "$STATE" && chmod 0700 "$STATE"
TMP=$(mktemp -d) || exit 1
trap 'rm -rf "$TMP"' EXIT

case "$CMD" in
    status)
        fw_py status
        ;;

    on)
        save_set "$TMP/pre" || { echo "error: cannot read the csf files"; exit 1; }
        fw_py on "$TMP" "$@" || exit 2
        install_new "$TMP" || { echo "unchanged"; exit 0; }
        if ! csf -r >/dev/null 2>&1; then
            restore_set "$TMP/pre"
            csf -r >/dev/null 2>&1
            echo "error: csf -r failed, the previous rules were restored"
            exit 1
        fi
        # Keep the oldest snapshot while a revert is pending: that is the
        # last state the admin confirmed.
        [ -d "$SNAP" ] || { cp -a "$TMP/pre" "$SNAP" && chmod 0700 "$SNAP"; }
        cancel_timer
        if systemd-run --quiet --unit="$UNIT" --on-active="${REVERT_SECS}s" \
                --timer-property=AccuracySec=1s "$SELF" revert >/dev/null 2>&1; then
            echo $(( $(date +%s) + REVERT_SECS )) > "$SNAP/deadline"
            echo "pending"
        else
            rm -rf "$SNAP"     # no safety net possible: keep the rules, say so
            echo "applied (the automatic revert could not be scheduled)"
        fi
        logger -t reqad "panel firewall allowlist applied for port $PORT: $*"
        ;;

    off)
        cancel_timer
        rm -rf "$SNAP"
        fw_py off "$TMP" || exit 2
        install_new "$TMP" || { echo "unchanged"; exit 0; }
        csf -r >/dev/null 2>&1 || { echo "error: csf -r failed"; exit 1; }
        logger -t reqad "panel firewall allowlist turned off, port $PORT open again"
        echo "updated"
        ;;

    confirm)
        cancel_timer
        rm -rf "$SNAP"
        echo "confirmed"
        ;;

    revert)
        cancel_timer
        [ -f "$SNAP/csf.conf" ] && [ -f "$SNAP/csf.allow" ] || { rm -rf "$SNAP"; echo "nothing to revert"; exit 0; }
        restore_set "$SNAP"
        rm -rf "$SNAP"
        csf -r >/dev/null 2>&1 || { echo "error: csf -r failed"; exit 1; }
        logger -t reqad "panel firewall allowlist change was not confirmed - reverted"
        echo "reverted"
        ;;
esac
exit 0
