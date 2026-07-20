#!/usr/bin/env python3
"""
Reqad DNS Agent
Runs on the cPanel server. Accepts HTTPS requests from Reqad VPS servers,
authenticates them by IP + Bearer token, and manages DNS zones via whmapi1.
"""

import http.server
import ssl
import json
import base64
import sqlite3
import subprocess
import logging
import re
import sys
from pathlib import Path
from urllib.parse import quote

BASE_DIR = Path(__file__).parent
CONFIG_FILE = BASE_DIR / 'agent.conf'
DB_FILE = BASE_DIR / 'agent.db'
LOG_FILE = BASE_DIR / 'agent.log'
WHMAPI = '/usr/local/cpanel/bin/whmapi1'

DOMAIN_RE = re.compile(r'^[a-z0-9][a-z0-9\-]+\.[a-z0-9.\-]+$')
IP_RE = re.compile(r'^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$')

# ── Config ─────────────────────────────────────────────────────────────────────

def load_config():
    if not CONFIG_FILE.exists():
        print(f"ERROR: config file not found: {CONFIG_FILE}", file=sys.stderr)
        sys.exit(1)
    with open(CONFIG_FILE) as f:
        return json.load(f)

config = load_config()
CERT_FILE = config['cert_file']
KEY_FILE  = config['key_file']
PORT      = int(config.get('port', 2089))
HOST      = config.get('host', '0.0.0.0')

# ── Logging ────────────────────────────────────────────────────────────────────

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s %(levelname)s %(message)s',
    handlers=[
        logging.FileHandler(LOG_FILE),
        logging.StreamHandler(sys.stdout),
    ]
)
log = logging.getLogger('reqad-agent')

# ── Database ───────────────────────────────────────────────────────────────────

def db():
    conn = sqlite3.connect(str(DB_FILE))
    conn.row_factory = sqlite3.Row
    return conn

def init_db():
    with db() as conn:
        conn.executescript("""
            CREATE TABLE IF NOT EXISTS servers (
                id    INTEGER PRIMARY KEY,
                name  TEXT    NOT NULL,
                ip    TEXT    NOT NULL UNIQUE,
                token TEXT    NOT NULL UNIQUE
            );
            CREATE TABLE IF NOT EXISTS domains (
                domain    TEXT    NOT NULL,
                server_id INTEGER NOT NULL,
                UNIQUE(domain),
                FOREIGN KEY (server_id) REFERENCES servers(id)
            );
        """)

def auth(token, client_ip):
    """Return server row if token is valid and IP matches, else None."""
    with db() as conn:
        row = conn.execute('SELECT * FROM servers WHERE token=?', (token,)).fetchone()
    if not row:
        return None
    if row['ip'] != client_ip:
        log.warning(f"IP mismatch: token belongs to {row['ip']}, request from {client_ip}")
        return None
    return row

# ── Actions ────────────────────────────────────────────────────────────────────

def whmapi1(*args):
    """Run whmapi1 as root via sudo. Returns (success, stdout).

    WHM API 1 command-line parameters must be URI-encoded: whmapi1 URL-decodes
    each value, so a literal '+' in a value (base64 DKIM keys, SPF '+a +mx')
    would otherwise be turned into a space (and '%' mis-decoded). Encode the
    value half of every 'key=value' arg, leaving the bare API function name and
    any '--flag' untouched."""
    enc = []
    for a in args:
        if a.startswith('--') or '=' not in a:
            enc.append(a)
        else:
            k, v = a.split('=', 1)
            enc.append(f'{k}=' + quote(v, safe=''))
    cmd = ['/usr/bin/sudo', '-n', WHMAPI] + enc
    log.info(f"whmapi1 calling: {' '.join(args)}")
    sys.stdout.flush()
    result = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                            universal_newlines=True, timeout=120)
    stdout = result.stdout.strip()
    stderr = result.stderr.strip()
    log.info(f"whmapi1 returned: rc={result.returncode} stdout_len={len(stdout)}")
    if stderr:
        log.warning(f"whmapi1 stderr: {stderr[:500]}")
    log.debug(f"whmapi1 stdout: {stdout}")
    sys.stdout.flush()
    success = bool(re.search(r'^\s*result:\s*1\s*$', stdout, re.MULTILINE))
    return success, stdout

def whmapi1_reason(out):
    m = re.search(r'reason:\s*(.+)', out)
    return m.group(1).strip() if m else out[:200]

def owns_domain(server, domain):
    """True if server is allowed to manage this domain."""
    with db() as conn:
        row = conn.execute('SELECT server_id FROM domains WHERE domain=?', (domain,)).fetchone()
    return row is not None and row['server_id'] == server['id']

def action_add_zone(server, domain, ip):
    with db() as conn:
        existing = conn.execute('SELECT server_id FROM domains WHERE domain=?', (domain,)).fetchone()
    if existing and existing['server_id'] != server['id']:
        return False, "domain already managed by another server"

    ok, out = whmapi1('adddns', f'domain={domain}', f'ip={ip}')
    if not ok:
        return False, f"whmapi1: {whmapi1_reason(out)}"

    with db() as conn:
        conn.execute('INSERT OR REPLACE INTO domains (domain, server_id) VALUES (?,?)',
                     (domain, server['id']))
    log.info(f"zone added: {domain} for server {server['name']}")
    return True, None

def action_delete_zone(server, domain):
    if not owns_domain(server, domain):
        return False, "domain not managed by this server"

    ok, out = whmapi1('killdns', f'domain={domain}')
    if not ok:
        return False, f"whmapi1: {whmapi1_reason(out)}"

    with db() as conn:
        conn.execute('DELETE FROM domains WHERE domain=?', (domain,))
    log.info(f"zone deleted: {domain} for server {server['name']}")
    return True, None

def parse_zone(domain):
    """Return (serial, payload[]) for a zone using whmapi1 parse_dns_zone.

    parse_dns_zone is the read API paired with mass_edit_dns_zone: each payload
    entry's `line_index` is exactly what mass_edit's `remove=`/`line_index`
    expect -- the physical zone-file line. SOA is multi-line and $TTL/comment
    lines also consume indices, so line_index is sparse (e.g. SOA at 3, next
    record at 10). dumpzone's `Line` and array position are DIFFERENT numberings
    and must never be fed to mass_edit. Serial comes from the SOA's data_b64
    (mname, rname, SERIAL, refresh, retry, expire, minimum)."""
    cmd = ['/usr/bin/sudo', '-n', WHMAPI, 'parse_dns_zone', '--output=json', f'zone={domain}']
    log.info(f"whmapi1 calling: parse_dns_zone --output=json zone={domain}")
    sys.stdout.flush()
    result = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                            universal_newlines=True, timeout=120)
    stdout = result.stdout.strip()
    log.info(f"whmapi1 returned: rc={result.returncode} stdout_len={len(stdout)}")
    if result.returncode != 0:
        log.error(f"parse_dns_zone stderr: {result.stderr.strip()[:500]}")
        return None, None
    try:
        data = json.loads(stdout)
    except json.JSONDecodeError as e:
        log.error(f"parse_dns_zone JSON parse error: {e}")
        return None, None
    if data.get('metadata', {}).get('result') != 1:
        log.error(f"parse_dns_zone API error: {data.get('metadata', {}).get('reason')}")
        return None, None
    payload = data.get('data', {}).get('payload', [])
    if not isinstance(payload, list):
        return None, None
    serial = None
    for e in payload:
        if e.get('type') == 'record' and e.get('record_type') == 'SOA':
            data_b64 = e.get('data_b64', [])
            if len(data_b64) >= 3:
                try:
                    serial = base64.b64decode(data_b64[2]).decode().strip()
                except Exception:
                    serial = None
            break
    return serial, payload

def managed_line_indices(payload):
    """line_index of every record we manage: type=='record' and record_type not
    in SKIP_TYPES. Comments/control entries (type != 'record') are skipped, as
    are the SOA/NS records cPanel owns."""
    out = []
    for e in payload:
        if e.get('type') != 'record':
            continue
        if e.get('record_type') in SKIP_TYPES:
            continue
        li = e.get('line_index')
        if li is not None:
            out.append(int(li))
    return out

def parse_txt_content(content):
    """PowerDNS TXT 'content' is one or more double-quoted character-strings
    (each <=255 bytes) separated by spaces, e.g. '"abc" "def"'. Concatenate them
    into the real TXT value, unescaping \\" and \\\\. Falls back to the raw string
    when there are no quotes."""
    content = content.strip()
    segments = re.findall(r'"((?:[^"\\]|\\.)*)"', content)
    if segments:
        return ''.join(s.replace('\\"', '"').replace('\\\\', '\\') for s in segments)
    return content

def split_txt(value, chunk=255):
    """Split a TXT value into <=255-byte character-strings, returned as a list of
    UNquoted chunks. cPanel's mass_edit_dns_zone adds the zone-file quoting
    itself; passing quoted text would double-quote it (e.g. "\\"v=spf1...\\"")."""
    return [value[i:i+chunk] for i in range(0, len(value), chunk)] or ['']

SKIP_TYPES = {'SOA', 'NS'}  # cPanel manages these

def format_data(rtype, content):
    """Translate a PowerDNS record 'content' string to cPanel mass_edit data array."""
    content = content.strip()
    if rtype == 'TXT':
        # PowerDNS stores TXT as quoted character-string(s); send cPanel the
        # concatenated value re-split into separate UNquoted <=255-byte chunks.
        return split_txt(parse_txt_content(content))
    if rtype == 'MX':
        # "0 mail.example.com." -> ["0", "mail.example.com."]
        parts = content.split(None, 1)
        if len(parts) == 2:
            return [parts[0], parts[1].rstrip('.') + '.']
        return [content]
    if rtype in ('A', 'AAAA'):
        return [content]
    if rtype == 'CNAME':
        return [content.rstrip('.') + '.']
    return [content]

def fqdn_name(dname_raw, domain):
    """Normalise a parse_dns_zone record name to a fully-qualified name WITHOUT a
    trailing dot. cPanel's dname_raw may be the bare origin ('@'), a name
    relative to the zone ('www'), or already absolute ('www.example.com.')."""
    name = (dname_raw or '').strip()
    if name in ('', '@'):
        return domain
    name = name.rstrip('.')
    if name == domain or name.endswith('.' + domain):
        return name
    return name + '.' + domain

def cpanel_to_pdns_content(rtype, data):
    """Translate a cPanel record's decoded data fields (the data_b64 list, already
    base64-decoded) into a single PowerDNS 'content' string. Inverse of
    format_data(); keeps TXT/MX/SRV/CNAME round-tripping with the push path."""
    rtype = rtype.upper()
    if rtype in ('A', 'AAAA'):
        return data[0] if data else ''
    if rtype in ('CNAME', 'PTR', 'DNAME'):
        return (data[0].rstrip('.') + '.') if data else ''
    if rtype == 'MX':
        if len(data) >= 2:
            return data[0] + ' ' + data[1].rstrip('.') + '.'
        return ' '.join(data)
    if rtype == 'SRV':
        if len(data) >= 4:
            return '{} {} {} {}'.format(data[0], data[1], data[2], data[3].rstrip('.') + '.')
        return ' '.join(data)
    if rtype == 'TXT':
        # Each field is one zone-file character-string (<=255 bytes); re-quote
        # and escape each so PowerDNS stores the same value cPanel holds.
        return ' '.join('"' + d.replace('\\', '\\\\').replace('"', '\\"') + '"' for d in data)
    if rtype == 'CAA':
        if len(data) >= 3:
            return '{} {} "{}"'.format(data[0], data[1], data[2].strip('"'))
        return ' '.join(data)
    return ' '.join(data)

def action_sync_zone(server, domain, records):
    """Push the full desired record state to cPanel by full replace, in ONE
    atomic mass_edit_dns_zone call: remove every managed (non-SOA/NS) record and
    add the full desired set together.

    mass_edit_dns_zone indexes records by parse_dns_zone's `line_index` (the
    physical zone-file line) and processes all removes before all adds. We read
    the zone once, then issue every `remove=` ordered highest line_index first
    (deleting a line only renumbers lines BELOW it, so the lower indices in the
    same batch stay valid) followed by every `add=`. One call => atomic (a
    failure leaves the zone untouched) and a short log. No `edit`, no per-record
    comparison.

    records is a list of {name, type, ttl, data}. SOA/NS are skipped (cPanel
    manages those)."""
    if not owns_domain(server, domain):
        return False, "domain not managed by this server"

    # Records to add: the full desired set (minus SOA/NS which cPanel owns).
    add_entries = []
    for rec in records:
        rtype = rec['type']
        if rtype in SKIP_TYPES:
            continue
        add_entries.append({
            'dname': rec['name'].rstrip('.') + '.',
            'ttl': int(rec.get('ttl', 3600)),
            'record_type': rtype,
            'data': format_data(rtype, rec['data']),
        })

    serial, payload = parse_zone(domain)
    if not serial:
        return False, "could not read zone"
    try:
        (BASE_DIR / 'last-parse.json').write_text(json.dumps(payload, indent=2))
    except Exception:
        pass

    remove_lines = sorted(managed_line_indices(payload), reverse=True)
    log.info(f"sync_zone {domain}: remove {len(remove_lines)} record(s) at line_index "
             f"{remove_lines}, add {len(add_entries)} record(s)")

    if not remove_lines and not add_entries:
        log.info(f"zone synced: {domain} (no records)")
        return True, None

    # One atomic call: all removes (highest line_index first) then all adds.
    args = ['mass_edit_dns_zone', f'zone={domain}', f'serial={serial}']
    for line in remove_lines:
        args.append(f'remove={line}')
    for entry in add_entries:
        args.append('add=' + json.dumps(entry))

    ok, out = whmapi1(*args)
    if not ok:
        return False, f"whmapi1 (sync): {whmapi1_reason(out)}"

    log.info(f"zone synced: {domain} (-{len(remove_lines)} +{len(add_entries)})")
    return True, None

def action_list_zones(server):
    """List the zones this server is allowed to manage — i.e. its own entries in
    the agent allowlist (the `domains` table). Read-only: no whmapi1 call, and
    the agent never exposes (or touches) zones belonging to another server.
    Returns (True, {'zones': [...]})."""
    with db() as conn:
        rows = conn.execute('SELECT domain FROM domains WHERE server_id=? ORDER BY domain',
                            (server['id'],)).fetchall()
    zones = [r['domain'] for r in rows]
    log.info(f"list_zones: {len(zones)} zone(s) for server {server['name']}")
    return True, {'zones': zones}

def action_dump_zone(server, domain):
    """Return the manageable records of a zone (everything except SOA/NS, which
    local PowerDNS regenerates) as a list of {name, type, ttl, data}, where data
    is already a PowerDNS 'content' string. Only zones already in this server's
    allowlist may be dumped. Returns (True, {'records': [...], 'serial': str})."""
    if not owns_domain(server, domain):
        return False, "domain not managed by this server"

    serial, payload = parse_zone(domain)
    if payload is None:
        return False, "could not read zone"

    records = []
    for e in payload:
        if e.get('type') != 'record':
            continue
        rtype = e.get('record_type', '')
        if rtype in SKIP_TYPES:
            continue
        decoded = []
        for b in e.get('data_b64', []):
            try:
                decoded.append(base64.b64decode(b).decode('utf-8', 'replace'))
            except Exception:
                decoded.append('')
        records.append({
            'name': fqdn_name(e.get('dname_raw', ''), domain),
            'type': rtype,
            'ttl':  int(e.get('ttl', 3600) or 3600),
            'data': cpanel_to_pdns_content(rtype, decoded),
        })
    log.info(f"dump_zone {domain}: {len(records)} record(s)")
    return True, {'records': records, 'serial': serial}

# ── HTTP handler ───────────────────────────────────────────────────────────────

class Handler(http.server.BaseHTTPRequestHandler):

    def log_message(self, fmt, *args):
        log.info(f"{self.client_address[0]} {fmt % args}")

    def send_json(self, code, data):
        body = json.dumps(data).encode()
        self.send_response(code)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', len(body))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        if self.path == '/':
            self.send_json(200, {'ok': True, 'service': 'reqad-dns-agent', 'version': '1.0'})
        else:
            self.send_json(404, {'ok': False, 'error': 'not found'})

    def do_POST(self):
        try:
            self._handle_post()
        except Exception as e:
            import traceback
            tb = traceback.format_exc()
            log.error(f"unhandled exception in do_POST:\n{tb}")
            try:
                self.send_json(500, {'ok': False, 'error': f"agent exception: {type(e).__name__}: {e}"})
            except Exception:
                pass

    def _handle_post(self):
        if self.path != '/dns':
            self.send_json(404, {'ok': False, 'error': 'not found'})
            return

        # Parse body
        length = int(self.headers.get('Content-Length', 0))
        try:
            body = json.loads(self.rfile.read(length))
        except (json.JSONDecodeError, ValueError):
            self.send_json(400, {'ok': False, 'error': 'invalid JSON'})
            return

        # Authenticate
        auth_header = self.headers.get('Authorization', '')
        if not auth_header.startswith('Bearer '):
            self.send_json(401, {'ok': False, 'error': 'missing token'})
            return
        token = auth_header[7:]
        client_ip = self.client_address[0]
        server = auth(token, client_ip)
        if not server:
            log.warning(f"auth failed from {client_ip}")
            self.send_json(403, {'ok': False, 'error': 'forbidden'})
            return

        action = body.get('action', '')
        log.info(f"action={action} server={server['name']} ip={client_ip}")

        # list_zones takes no domain; handle it before domain validation.
        if action == 'list_zones':
            ok, result = action_list_zones(server)
            if ok:
                self.send_json(200, dict({'ok': True}, **result))
            else:
                log.error(f"list_zones failed: {result}")
                self.send_json(500, {'ok': False, 'error': result})
            return

        domain = str(body.get('domain', '')).lower().strip().rstrip('.')
        if not DOMAIN_RE.match(domain):
            self.send_json(400, {'ok': False, 'error': 'invalid domain'})
            return

        if action == 'dump_zone':
            ok, result = action_dump_zone(server, domain)
            if ok:
                self.send_json(200, dict({'ok': True}, **result))
            else:
                log.error(f"dump_zone failed: {result}")
                self.send_json(500, {'ok': False, 'error': result})
            return

        if action == 'add_zone':
            ip = str(body.get('ip', '')).strip()
            if not IP_RE.match(ip):
                self.send_json(400, {'ok': False, 'error': 'invalid ip'})
                return
            ok, err = action_add_zone(server, domain, ip)

        elif action == 'delete_zone':
            ok, err = action_delete_zone(server, domain)

        elif action == 'sync_zone':
            records = body.get('records', [])
            if not isinstance(records, list):
                self.send_json(400, {'ok': False, 'error': 'records must be a list'})
                return
            ok, err = action_sync_zone(server, domain, records)

        else:
            self.send_json(400, {'ok': False, 'error': f"unknown action: {action}"})
            return

        if ok:
            self.send_json(200, {'ok': True})
        else:
            log.error(f"{action} failed: {err}")
            self.send_json(500, {'ok': False, 'error': err})

# ── Main ───────────────────────────────────────────────────────────────────────

def main():
    init_db()
    ssl_ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    try:
        ssl_ctx.load_cert_chain(certfile=CERT_FILE, keyfile=KEY_FILE)
    except Exception as e:
        log.error(f"Failed to load TLS cert: {e}")
        sys.exit(1)

    server = http.server.HTTPServer((HOST, PORT), Handler)
    server.socket = ssl_ctx.wrap_socket(server.socket, server_side=True)
    log.info(f"Reqad DNS Agent listening on {HOST}:{PORT}")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        log.info("Agent stopped")

if __name__ == '__main__':
    main()
