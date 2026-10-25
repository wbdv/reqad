#!/bin/bash
# DNS cluster, primary side (Settings > DNS cluster, app/functions/dns_cluster.php):
# the part of this server's /etc/pdns/pdns.conf that sends the zones to the
# secondary servers.
#
#   status                              JSON: version, the values in effect, the block
#   apply --notify=LIST --axfr=LIST     write the block, restart PowerDNS
#         [--drop-kept]                 ...without the values that were there before
#   remove                              take the block out, put the old lines back
#
# LIST = addresses separated by commas: --notify takes IP or IP:port
# (IPv6 as [addr]:port), --axfr IPs or networks.
#
# The block (between the reqad-dns-cluster markers) holds primary=yes (master=yes
# before PowerDNS 4.5), also-notify, allow-axfr-ips and xfr-cycle-interval=15
# (how often PowerDNS looks for changed zones to announce: 60 s by default).
# Lines setting those keys elsewhere in the file are commented out with
# "#reqad-dns-cluster: " in front, and `remove` puts them back. Addresses of
# also-notify / allow-axfr-ips that were there before the first apply are
# kept in the block (a "# kept" line) until --drop-kept.
#
# The old file is backed up in /var/lib/reqad/pdns-backups/; when PowerDNS
# does not come back up with the new one, the old one is put back.

[ "$(id -u)" -eq 0 ] || { echo "This script must be run as root"; exit 1; }

CONF=/etc/pdns/pdns.conf
BACKUPS=/var/lib/reqad/pdns-backups
VERB=${1:-}; shift
NOTIFY=''; AXFR=''; DROP=0
for a in "$@"; do
    case "$a" in
        --notify=*) NOTIFY=${a#--notify=} ;;
        --axfr=*)   AXFR=${a#--axfr=} ;;
        --drop-kept) DROP=1 ;;
        *) echo "unknown option: $a"; exit 2 ;;
    esac
done
case "$VERB" in status|apply|remove) ;; *) echo "usage: $0 status | apply --notify=LIST --axfr=LIST [--drop-kept] | remove"; exit 2 ;; esac
[ -f "$CONF" ] || { echo "{\"error\": \"$CONF not found: is PowerDNS installed?\"}"; exit 1; }

VERSION=$(pdns_server --version 2>&1 | grep -oE 'Authoritative Server [0-9]+\.[0-9]+\.[0-9]+' | grep -oE '[0-9.]+$' | head -1)
ACTIVE=$(systemctl is-active pdns 2>/dev/null)

TMP=$(mktemp)
trap 'rm -f "$TMP"' EXIT

python3 - "$VERB" "$CONF" "$TMP" "$NOTIFY" "$AXFR" "$DROP" "$VERSION" "$ACTIVE" <<'PY'
import ipaddress, json, re, sys

verb, conf, tmp, notify, axfr, drop, version, active = sys.argv[1:9]
BEGIN = '# BEGIN reqad-dns-cluster'
END = '# END reqad-dns-cluster'
MARK = '#reqad-dns-cluster: '
KEYS = ('primary', 'master', 'also-notify', 'allow-axfr-ips', 'xfr-cycle-interval')
lines = open(conf).read().split('\n')

def split(v):
    return [x.strip() for x in v.split(',') if x.strip()]

# the file as it is: block, kept values, values in effect (the last line wins)
inblock, block, kept, outside = False, [], {}, []
for l in lines:
    if l.startswith(BEGIN):
        inblock = True; continue
    if l.startswith(END):
        inblock = False; continue
    if inblock:
        m = re.match(r'^# kept (also-notify|allow-axfr-ips): (.*)$', l)
        if m:
            kept[m.group(1)] = split(m.group(2))
        block.append(l)
    else:
        outside.append(l)

def effective(ls):
    out = {}
    for l in ls:
        m = re.match(r'^\s*([a-z0-9-]+)\s*=\s*(.*?)\s*$', l)
        if m:
            out[m.group(1)] = m.group(2)
    return out

if verb == 'status':
    eff = effective(lines)
    print(json.dumps({
        'version': version, 'active': active == 'active', 'managed': bool(block) or any(l.startswith(BEGIN) for l in lines),
        'primary': eff.get('primary', eff.get('master', 'no')) == 'yes',
        'also_notify': split(eff.get('also-notify', '')), 'allow_axfr_ips': split(eff.get('allow-axfr-ips', '127.0.0.0/8,::1')),
        'xfr_cycle_interval': int(eff.get('xfr-cycle-interval', '60') or 60),
        'local_address': split(eff.get('local-address', '0.0.0.0,::')), 'local_port': int(eff.get('local-port', '53') or 53),
        'kept': kept}))
    sys.exit(0)

def check_notify(v):
    m = re.match(r'^\[([0-9a-fA-F:.]+)\](?::(\d+))?$', v) or re.match(r'^([0-9.]+)(?::(\d+))?$', v) or re.match(r'^([0-9a-fA-F:]+)()$', v)
    if not m:
        raise ValueError(v)
    ipaddress.ip_address(m.group(1))
    if m.group(2) and not 0 < int(m.group(2)) < 65536:
        raise ValueError(v)

def check_net(v):
    ipaddress.ip_network(v, strict=False)

try:
    for v in split(notify): check_notify(v)
    for v in split(axfr): check_net(v)
except ValueError as e:
    print('not an address: %s' % e); sys.exit(2)

# lines of the managed keys outside the block: commented out (and their
# values remembered as kept, the first time); `remove` puts them back
first = not any(l.startswith(BEGIN) for l in lines)
new = []
for l in outside:
    if verb == 'remove':
        new.append(l[len(MARK):] if l.startswith(MARK) else l)
        continue
    m = re.match(r'^\s*([a-z0-9-]+)\s*=\s*(.*?)\s*$', l)
    if m and m.group(1) in KEYS:
        if first and m.group(1) in ('also-notify', 'allow-axfr-ips'):
            kept[m.group(1)] = split(m.group(2))
        new.append(MARK + l)
    else:
        new.append(l)

if verb == 'apply':
    if drop == '1':
        kept = {}
    def merged(key, vals, base):
        out = []
        for v in base + kept.get(key, []) + vals:
            if v not in out:
                out.append(v)
        return ','.join(out)
    major = tuple(int(x) for x in (version or '4.8.0').split('.')[:2])
    blk = [BEGIN + ' - managed by Reqad (Settings > DNS cluster); change it there']
    for k in ('also-notify', 'allow-axfr-ips'):
        if kept.get(k):
            blk.append('# kept %s: %s' % (k, ','.join(kept[k])))
    blk.append('primary=yes' if major >= (4, 5) else 'master=yes')
    an = merged('also-notify', split(notify), [])
    if an:
        blk.append('also-notify=' + an)
    blk.append('allow-axfr-ips=' + merged('allow-axfr-ips', split(axfr), ['127.0.0.0/8', '::1']))
    blk.append('xfr-cycle-interval=15')
    blk.append(END)
    while new and new[-1] == '':
        new.pop()
    new += [''] + blk + ['']

if verb == 'remove':
    while new and new[-1] == '':
        new.pop()
    new.append('')
open(tmp, 'w').write('\n'.join(new))
PY
rc=$?
[ "$VERB" = status ] && exit $rc
[ $rc -eq 0 ] || exit $rc

if cmp -s "$TMP" "$CONF"; then
    echo "pdns_cluster: $CONF is up to date"
    exit 0
fi

mkdir -p "$BACKUPS" && chmod 700 "$BACKUPS"
BK="$BACKUPS/pdns.conf.$(date +%Y%m%d%H%M%S)"
cp -a "$CONF" "$BK"
ls -1t "$BACKUPS"/pdns.conf.* 2>/dev/null | tail -n +21 | xargs -r rm -f     # the 20 newest
cat "$TMP" > "$CONF"                                                        # keeps owner and mode

systemctl restart pdns
for i in $(seq 1 15); do
    pdns_control rping >/dev/null 2>&1 && { echo "pdns_cluster: $VERB done, PowerDNS restarted (backup $BK)"; exit 0; }
    sleep 1
done
echo "pdns_cluster: PowerDNS did not start with the new $CONF - the old one is back"
journalctl -u pdns -n 5 --no-pager 2>/dev/null
cat "$BK" > "$CONF"
systemctl restart pdns
exit 1
