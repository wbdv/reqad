<?php
/*
 * DNS API keys (Settings > DNS Settings > API keys = templates/dns-settings-keys.php,
 * db/1055.sql; only with dnscluster=1 in server-software.ini):
 * other servers - usually other Reqad servers whose DNS provider is PowerDNS -
 * keep their zones on this server's PowerDNS. They talk to a PowerDNS-
 * compatible API at https://<host>:2087/pdns (nginx `location ^~ /pdns/`
 * from scripts/update/setup_panel_api.sh -> app/api/pdns.php ->
 * dns_api_serve()), so the other server only needs "Server address" =
 * that URL and "API Key" = its key in its own DNS Settings.
 *
 * A key sees and changes only its own zones (dns_key_zones): the ones it
 * created and the ones the admin gave it. What it may do is a small part of
 * the PowerDNS API: list its zones, create one, read, change records of,
 * export, notify, rectify and remove its own zones. Never: zone settings
 * (PUT), the SOA, the zone's own NS records (the zones always carry this
 * server's name servers, dns_expected_nameservers()), DNSSEC, LUA records,
 * server config, statistics, search, TSIG keys.
 *
 * A key may not create (or be given) a zone that is, holds or is inside the
 * server's hostname, a name of a hosting account on this server, or a zone
 * that is not its own - so a key can never take over names it does not own.
 *
 * Keys are api_tokens rows with kind 'dns' (app/api/api.php: sha256 only,
 * shown once, address allowlist, expiry, permissions read / write (create
 * zones, change records) / delete (remove zones)). They go in the header
 * X-API-Key, as PowerDNS's own key does. A wrong key counts as a failed login
 * (same lockout and firewall bans as the panel). Every call is in api_log.
 *
 * Off until the setting dns-api-enabled is 1, and only with the DNS provider
 * PowerDNS (direct mode).
 */

/* The tables exist (migrations 1042, 1045, 1055). */
function dns_api_ready() {
	global $db;
	static $ok = null;
	if ($ok === null)
		$ok = $db instanceof SQLite3
		   && (bool)@$db->querySingle("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'dns_key_zones'")
		   && function_exists('api_has_kinds') && api_has_kinds();
	return $ok;
}

/* The DNS provider is this server's PowerDNS, so there is something to share. */
/* dnscluster=1 in server-software.ini (the same switch as the DNS cluster)
   and PowerDNS in direct mode. */
function dns_api_available() {
	return dns_cluster_available() && setting_get('dns-provider') === 'powerdns' && setting_get('powerdns-mode') !== 'hidden-master';
}

function dns_api_enabled() {
	return dns_api_available() && dns_api_ready() && setting_get('dns-api-enabled', '0') === '1';
}

function dns_api_set_enabled($on) {
	if ($on && !dns_cluster_available())
		return result_error('DNS API keys are not available on this server: set dnscluster=1 in /usr/local/reqad/etc/server-software.ini first.');
	if ($on && !dns_api_available())
		return result_error('The DNS API needs PowerDNS as this server\'s DNS provider (Settings > DNS Settings).');
	if (!dns_api_ready())
		return result_error('The DNS API tables are missing. Run the Reqad update (database 1055) first.');
	setting_put('dns-api-enabled', $on ? '1' : '0');
	auth_log_event($on ? 'dns_api_on' : 'dns_api_off', '', 0, auth_user());
	return result_ok($on ? 'The DNS API is on.' : 'The DNS API is off. Keys and their zones are kept, but no request is answered.');
}

/* The address other servers put in their DNS Settings as "Server address". */
function dns_api_url() {
	$host = PHP_SAPI !== 'cli' && !empty($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : gethostname().':2087';
	return 'https://'.$host.'/pdns';
}

/* ---------- keys ---------- */

/* "1 zone", "3 zones" */
function dns_key_nzones($n) {
	return (int)$n === 1 ? '1 zone' : (int)$n.' zones';
}

/* A key by id (kind dns), or null. */
function dns_key_get($id) {
	$t = api_token_get((int)$id);
	if (!$t || api_token_kind($t) !== 'dns')
		return null;
	$t['state'] = api_token_state($t);
	return $t;
}

/* The keys, newest first, each with max_zones and zones (names). Revoked
   keys are listed while they still own zones (to move them), or when asked. */
function dns_keys_list($with_revoked = false) {
	global $db;
	if (!dns_api_ready())
		return array();
	$max = array();
	$res = $db->query('SELECT token_id, max_zones FROM dns_keys');
	while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC)))
		$max[(int)$r['token_id']] = (int)$r['max_zones'];
	$zones = array();
	$res = $db->query('SELECT zone, token_id FROM dns_key_zones ORDER BY zone');
	while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC)))
		$zones[(int)$r['token_id']][] = $r['zone'];
	$out = array();
	foreach (api_tokens_list(true, 'dns') as $t) {
		$t['max_zones'] = $max[(int)$t['id']] ?? 0;
		$t['zones'] = $zones[(int)$t['id']] ?? array();
		if ($t['state'] === 'revoked' && !$with_revoked && !$t['zones'])
			continue;
		$out[] = $t;
	}
	return $out;
}

/*
 * A new key. $perms: any of write (create zones, change records), delete
 * (remove zones); read is always in. $max_zones 0 = no limit. $days 0 =
 * never expires. $allow_ips: addresses or networks, '' = from anywhere.
 * $zones: zones already on this server to give it (text or array). The key
 * is in data.key - the only time it is available.
 */
function dns_key_create($name, $allow_ips, array $perms, $max_zones, $days, $zones = '', $by = '') {
	global $db;
	if (!dns_api_ready())
		return result_error('The DNS API tables are missing. Run the Reqad update (database 1055) first.');
	$max_zones = (int)$max_zones;
	if ($max_zones < 0 || $max_zones > 100000)
		return result_error('The most zones a key may hold is a number from 1 to 100000, or 0 for no limit.');
	$perms = array_values(array_intersect(array('write', 'delete'), $perms));
	$r = api_token_create($name, '', $perms, $days, $allow_ips, $by, 'dns');
	if (!$r['ok'])
		return $r;
	$id = (int)$r['data']['id'];
	$stmt = $db->prepare('INSERT OR REPLACE INTO dns_keys (token_id, max_zones) VALUES (:id, :m)');
	$stmt->bindValue(':id', $id, SQLITE3_INTEGER);
	$stmt->bindValue(':m', $max_zones, SQLITE3_INTEGER);
	$stmt->execute();
	$data = array('id' => $id, 'name' => $r['data']['name'], 'key' => $r['data']['token'], 'url' => dns_api_url(),
	              'perms' => $r['data']['scopes'], 'allow_ips' => $r['data']['allow_ips'], 'max_zones' => $max_zones,
	              'zones' => array());
	$warn = array();
	if (trim(is_array($zones) ? implode(' ', $zones) : (string)$zones) !== '') {
		$a = dns_key_assign($id, $zones, $by);
		if (!$a['ok'])
			$warn[] = $a['error'];
		else {
			$warn = array_merge($warn, $a['warnings']);
			$data['zones'] = $a['data']['zones'];
		}
	}
	return result_ok("Key '{$data['name']}' created. Copy it now - it is not shown again.", $data, $warn);
}

function dns_key_revoke($id, $by = '') {
	if (!dns_key_get($id))
		return result_error('There is no such DNS API key.');
	$r = api_token_revoke((string)(int)$id, $by);
	if (!$r['ok'])
		return $r;
	$r['message'] = preg_replace('/^Token /', 'Key ', $r['message']);
	if (dns_key_zones($id))
		$r['message'] .= ' Its zones stay on this server: move them to another key, or remove them under DNS Zones.';
	return $r;
}

function dns_key_limit_set($id, $max_zones, $by = '') {
	global $db;
	$t = dns_key_get($id);
	if (!$t)
		return result_error('There is no such DNS API key.');
	$max_zones = (int)$max_zones;
	if ($max_zones < 0 || $max_zones > 100000)
		return result_error('The most zones a key may hold is a number from 1 to 100000, or 0 for no limit.');
	$stmt = $db->prepare('INSERT OR REPLACE INTO dns_keys (token_id, max_zones) VALUES (:id, :m)');
	$stmt->bindValue(':id', (int)$t['id'], SQLITE3_INTEGER);
	$stmt->bindValue(':m', $max_zones, SQLITE3_INTEGER);
	$stmt->execute();
	auth_log_event('dns_key_limit', "#{$t['id']} {$t['name']}: ".($max_zones ?: 'no limit'), 0, (string)$by);
	$n = count(dns_key_zones($id));
	return result_ok($max_zones ? "Key '{$t['name']}' may hold at most ".dns_key_nzones($max_zones).'.' : "Key '{$t['name']}' may hold any number of zones.",
		array(), $max_zones && $n > $max_zones ? array('It already holds '.dns_key_nzones($n).': it keeps them, but cannot make more.') : array());
}

function dns_key_max_zones($id) {
	global $db;
	return (int)$db->querySingle('SELECT max_zones FROM dns_keys WHERE token_id = '.(int)$id);
}

/* ---------- the zones of each key ---------- */

/* The zones of key $id. */
function dns_key_zones($id) {
	global $db;
	$out = array();
	if (!dns_api_ready())
		return $out;
	$res = $db->query('SELECT zone FROM dns_key_zones WHERE token_id = '.(int)$id.' ORDER BY zone');
	while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC)))
		$out[] = $r['zone'];
	return $out;
}

/* zone => array(id, name) of the key that owns it (any state). */
function dns_key_zone_map() {
	global $db;
	$out = array();
	if (!($db instanceof SQLite3) || !@$db->querySingle("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'dns_key_zones'"))
		return $out;
	$res = $db->query('SELECT z.zone, z.token_id, t.name FROM dns_key_zones z LEFT JOIN api_tokens t ON t.id = z.token_id');
	while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC)))
		$out[$r['zone']] = array('id' => (int)$r['token_id'], 'name' => (string)$r['name']);
	return $out;
}

/* Why $domain cannot become a site here ('' = it can): its DNS zone, or a
   zone it is in or holds, belongs to a DNS API key - another server keeps
   that zone here and would go on changing it. The mirror of
   dns_key_conflict()'s rule that a key never gets an account's domain.
   Account create and domain_claim_error() (addon domains, aliases) ask it. */
function dns_key_domain_error($domain, $admin = true) {
	if (!dns_cluster_available())
		return '';
	$d = strtolower(rtrim(trim((string)$domain), '.'));
	if ($d === '')
		return '';
	foreach (dns_key_zone_map() as $z => $k) {
		$z = (string)$z;
		if ($z === $d)
			$what = "the DNS zone $d";
		elseif (substr($d, -strlen($z) - 1) === '.'.$z)
			$what = "the DNS zone $z, which holds $d,";
		elseif (substr($z, -strlen($d) - 1) === '.'.$d)
			$what = "the DNS zone $z, under $d,";
		else
			continue;
		if (!$admin)     /* an account: no key names, the admin fixes it */
			return "$d cannot be a site here: another server keeps $what on this server's DNS. Ask the server's administrator.";
		$key = $k['name'] !== '' ? '"'.$k['name'].'"' : '#'.$k['id'];
		return "$d cannot be a site here: $what belongs to the DNS API key $key (another server keeps it here)."
		      .' Take the zone away from that key first (Settings > DNS Settings > API keys > Zones), or remove it.';
	}
	return '';
}

/* The id of the key that owns $zone, 0 = none. */
function dns_key_zone_owner($zone) {
	global $db;
	if (!dns_api_ready())
		return 0;
	$stmt = $db->prepare('SELECT token_id FROM dns_key_zones WHERE zone = :z');
	$stmt->bindValue(':z', strtolower((string)$zone), SQLITE3_TEXT);
	$r = $stmt->execute();
	$r = $r ? $r->fetchArray(SQLITE3_NUM) : false;
	return $r ? (int)$r[0] : 0;
}

/* The zone is gone (or made again here): no key owns it any more. */
function dns_key_zone_forget($zone) {
	global $db;
	if (!($db instanceof SQLite3) || !@$db->querySingle("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'dns_key_zones'"))
		return;
	$stmt = $db->prepare('DELETE FROM dns_key_zones WHERE zone = :z');
	$stmt->bindValue(':z', strtolower((string)$zone), SQLITE3_TEXT);
	$stmt->execute();
}

function dns_key_zone_put($zone, $id, $by) {
	global $db;
	$stmt = $db->prepare('INSERT OR REPLACE INTO dns_key_zones (zone, token_id, added, added_by) VALUES (:z, :id, :now, :by)');
	$stmt->bindValue(':z', strtolower((string)$zone), SQLITE3_TEXT);
	$stmt->bindValue(':id', (int)$id, SQLITE3_INTEGER);
	$stmt->bindValue(':now', time(), SQLITE3_INTEGER);
	$stmt->bindValue(':by', (string)$by, SQLITE3_TEXT);
	$stmt->execute();
}

/* Why key $id may not hold $zone ('' = it may): the zone is, holds or is
   inside this server's hostname, a name of a hosting account here, or a zone
   (in $all, the zones at PowerDNS) that is not the key's. $mine: zones to
   count as the key's (those being added with it). */
function dns_key_conflict($zone, $id, array $all, array $mine = array()) {
	$near = function ($a, $b) {
		return $a === $b || substr($a, -strlen($b) - 1) === '.'.$b || substr($b, -strlen($a) - 1) === '.'.$a;
	};
	$how = function ($a, $b) {
		return $a === $b ? 'is' : (substr($a, -strlen($b) - 1) === '.'.$b ? 'is inside' : 'holds');
	};
	$host = strtolower(trim((string)gethostname()));
	if ($host !== '' && $near($zone, $host))
		return "$zone {$how($zone, $host)} this server's own name ($host).";
	foreach (dns_owner_map() as $name => $acct)
		if ($near($zone, (string)$name))
			return "$zone {$how($zone, (string)$name)} $name, a domain of the hosting account $acct on this server.";
	$keys = dns_key_zone_map();
	foreach ($all as $z => $_) {
		$z = strtolower((string)$z);
		if ($z === $zone || !$near($zone, $z) || isset($mine[$z]) || ($keys[$z]['id'] ?? 0) === (int)$id)
			continue;
		return "$zone {$how($zone, $z)} the zone $z, which is not this key's.";
	}
	return '';
}

/* Give key $id zones that are already on this server (text: names separated
   by spaces, commas or lines; or an array). A zone of another key moves. */
function dns_key_assign($id, $zones, $by = '') {
	global $db;
	$t = dns_key_get($id);
	if (!$t)
		return result_error('There is no such DNS API key.');
	if ($t['state'] === 'revoked')
		return result_error("Key '{$t['name']}' is revoked: give the zones to an active key.");
	$names = is_array($zones) ? $zones : preg_split('/[\s,;]+/', (string)$zones, -1, PREG_SPLIT_NO_EMPTY);
	$names = array_values(array_unique(array_map('dns_zone_name', $names)));
	if (!$names)
		return result_error('Enter the zones to add, one per line.');
	if (count($names) > 5000)
		return result_error('At most 5000 zones at a time.');
	dns_provider_load($db);
	$all = get_zones();
	if (!is_array($all))
		return result_error('PowerDNS is not answering: '.preg_replace('/^Error:\s*/', '', (string)$all));
	$all = array_change_key_case($all, CASE_LOWER);
	$keys = dns_key_zone_map();
	$warn = array();
	$want = array();
	foreach ($names as $z) {
		if (!valid_domain($z))
			$warn[] = "'$z' is not a domain name.";
		elseif (!isset($all[$z]))
			$warn[] = "There is no zone $z on this server.";
		elseif (($keys[$z]['id'] ?? 0) !== (int)$id)
			$want[$z] = true;
	}
	$ok = array();
	$moved = 0;
	foreach (array_keys($want) as $z) {
		$c = dns_key_conflict($z, $id, $all, $want);
		if ($c !== '') {
			$warn[] = $c;
			continue;
		}
		$ok[] = $z;
	}
	$max = dns_key_max_zones($id);
	$have = count(dns_key_zones($id));
	if ($max && $have + count($ok) > $max)
		return result_error("Key '{$t['name']}' may hold at most ".dns_key_nzones($max)." and already holds $have: raise its limit first.", array(), $warn);
	foreach ($ok as $z) {
		if (isset($keys[$z]))
			$moved++;
		dns_key_zone_put($z, $id, $by);
	}
	if ($ok) {
		auth_log_event('dns_key_zones', "#{$t['id']} {$t['name']} given ".implode(' ', array_slice($ok, 0, 20)).(count($ok) > 20 ? ' and '.(count($ok) - 20).' more' : ''), 0, (string)$by);
		route_log("dns key {$t['name']}: ".count($ok).' zones given');
	}
	$n = count($ok);
	$msg = $n ? "Key '{$t['name']}' now holds ".($n === 1 ? "the zone {$ok[0]}" : "$n more zones").($moved ? ' ('.($moved === 1 && $n === 1 ? 'moved from another key' : "$moved moved from other keys").')' : '').'.'
	          : "No zone was added to key '{$t['name']}'.";
	return $n ? result_ok($msg, array('zones' => $ok), $warn) : result_error($msg.($warn ? ' '.implode(' ', $warn) : ''));
}

/* Take $zone away from key $id (the zone stays on this server). */
function dns_key_release($id, $zone, $by = '') {
	global $db;
	$t = dns_key_get($id);
	if (!$t)
		return result_error('There is no such DNS API key.');
	$zone = dns_zone_name($zone);
	if (dns_key_zone_owner($zone) !== (int)$id)
		return result_error("Key '{$t['name']}' does not hold the zone $zone.");
	dns_key_zone_forget($zone);
	auth_log_event('dns_key_zones', "#{$t['id']} {$t['name']} no longer holds $zone", 0, (string)$by);
	return result_ok("Key '{$t['name']}' no longer holds $zone. The zone stays on this server.");
}

/* Every zone of key $from goes to key $to (e.g. a new key replacing an old one). */
function dns_key_move_all($from, $to, $by = '') {
	global $db;
	$a = dns_key_get($from);
	$b = dns_key_get($to);
	if (!$a || !$b || (int)$from === (int)$to)
		return result_error('Choose another key to move the zones to.');
	if ($b['state'] !== 'active')
		return result_error("Key '{$b['name']}' is {$b['state']}: move the zones to an active key.");
	$zones = dns_key_zones($from);
	if (!$zones)
		return result_error("Key '{$a['name']}' holds no zones.");
	$max = dns_key_max_zones($to);
	$have = count(dns_key_zones($to));
	if ($max && $have + count($zones) > $max)
		return result_error("Key '{$b['name']}' may hold at most ".dns_key_nzones($max)." and already holds $have: raise its limit first.");
	$db->exec('UPDATE dns_key_zones SET token_id = '.(int)$to.' WHERE token_id = '.(int)$from);
	auth_log_event('dns_key_zones', dns_key_nzones(count($zones))." moved from #{$a['id']} {$a['name']} to #{$b['id']} {$b['name']}", 0, (string)$by);
	return result_ok(ucfirst(dns_key_nzones(count($zones)))." moved from key '{$a['name']}' to key '{$b['name']}'.");
}

/* ---------- the PowerDNS-compatible API (app/api/pdns.php) ---------- */

/* Record types a key may not set: DNSSEC records are PowerDNS's own, LUA
   records run code on this server. */
function dns_api_denied_types() {
	return array('SOA' => 1, 'DNSKEY' => 1, 'RRSIG' => 1, 'NSEC' => 1, 'NSEC3' => 1, 'NSEC3PARAM' => 1, 'LUA' => 1);
}

/* A PowerDNS zone id ("example.com.", "=2F" for "/") -> the zone name. */
function dns_api_zone_from_id($id) {
	$id = preg_replace_callback('/=([0-9A-Fa-f]{2})/', function ($m) { return chr(hexdec($m[1])); }, (string)$id);
	return dns_zone_name($id);
}

/* A request to this server's PowerDNS: array(status, body, content type). */
function dns_api_upstream($method, $path, $body = null) {
	global $db, $powerdns_server, $powerdns_api_key;
	dns_provider_load($db);
	$c = curl_init(rtrim((string)$powerdns_server, '/').$path);
	$h = array('X-API-Key: '.$powerdns_api_key, 'Accept: application/json');
	if ($body !== null)
		$h[] = 'Content-Type: application/json';
	curl_setopt_array($c, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h,
	                            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 120));
	if ($body !== null)
		curl_setopt($c, CURLOPT_POSTFIELDS, $body);
	curl_set_tls($c, 'powerdns');
	$raw = curl_exec($c);
	$code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
	$type = (string)curl_getinfo($c, CURLINFO_CONTENT_TYPE);
	curl_close($c);
	if ($raw === false || $code === 0)
		return array(502, array('error' => 'The DNS server behind this API is not answering.'), 'application/json');
	return array($code, (string)$raw, $type !== '' ? $type : 'application/json');
}

/* What the PowerDNS API says about the server (the version is PowerDNS's). */
function dns_api_server_info() {
	static $v = null;
	if ($v === null) {
		$r = dns_api_upstream('GET', '/api/v1/servers/localhost');
		$j = $r[0] === 200 ? json_decode($r[1], true) : null;
		$v = is_array($j) && isset($j['version']) ? (string)$j['version'] : '';
	}
	return array('type' => 'Server', 'id' => 'localhost', 'daemon_type' => 'authoritative', 'version' => $v,
	             'url' => '/api/v1/servers/localhost', 'zones_url' => '/api/v1/servers/localhost/zones{/zone}');
}

/* The request body as JSON, or null. */
function dns_api_body() {
	$raw = (string)file_get_contents('php://input');
	$v = json_decode($raw, true);
	return is_array($v) ? $v : null;
}

/* $_GET reduced to the parameters PowerDNS knows for this call. */
function dns_api_query(array $keep) {
	$q = array();
	foreach ($keep as $k)
		if (isset($_GET[$k]) && is_string($_GET[$k]))
			$q[$k] = substr($_GET[$k], 0, 255);
	return $q ? '?'.http_build_query($q) : '';
}

/*
 * The record sets of a request, checked: every name inside $zone, no denied
 * type, the zone's own SOA and NS left to this server. For a new zone
 * ($patch false) an SOA or apex NS set is just dropped (the zone gets this
 * server's); in a change it is refused, unless it sets the NS records to
 * what they already are. array(sets, error).
 */
function dns_api_rrsets($zone, $sets, $patch) {
	if (!is_array($sets) || ($sets && array_keys($sets) !== range(0, count($sets) - 1)))
		return array(null, 'rrsets must be a list.');
	if (count($sets) > 5000)
		return array(null, 'At most 5000 record sets in one request.');
	$ns = array_map(function ($n) { return strtolower($n).'.'; }, dns_expected_nameservers());
	sort($ns);
	$denied = dns_api_denied_types();
	$out = array();
	foreach ($sets as $s) {
		if (!is_array($s))
			return array(null, 'Each rrset is an object with name, type, ttl, changetype and records.');
		$name = strtolower(trim((string)($s['name'] ?? '')));
		$n = rtrim($name, '.');
		if ($n === '' || ($n !== $zone && substr($n, -strlen($zone) - 1) !== '.'.$zone))
			return array(null, "'".($s['name'] ?? '')."' is not a name in the zone $zone.");
		$type = strtoupper(trim((string)($s['type'] ?? '')));
		if (!preg_match('/^[A-Z][A-Z0-9-]{0,15}$/', $type))
			return array(null, "'".($s['type'] ?? '')."' is not a record type.");
		$ct = strtoupper(trim((string)($s['changetype'] ?? '')));
		if ($patch && !in_array($ct, array('REPLACE', 'DELETE', 'EXTEND', 'PRUNE'), true))
			return array(null, "changetype of $name $type must be REPLACE or DELETE.");
		$recs = array();
		if (isset($s['records'])) {
			if (!is_array($s['records']) || count($s['records']) > 2000)
				return array(null, "records of $name $type must be a list (at most 2000).");
			foreach ($s['records'] as $r) {
				if (!is_array($r) || !isset($r['content']) || !is_scalar($r['content']) || strlen((string)$r['content']) > 65535)
					return array(null, "Each record of $name $type needs a content.");
				$recs[] = array('content' => (string)$r['content'], 'disabled' => !empty($r['disabled']));
			}
		}
		if ($n === $zone && ($type === 'SOA' || $type === 'NS')) {
			if (!$patch)
				continue;
			if ($type === 'NS' && $ct === 'REPLACE') {
				$vals = array_map(function ($r) { return strtolower(rtrim($r['content'], '.')).'.'; }, $recs);
				sort($vals);
				if ($vals === $ns)
					continue;
			}
			return array(null, $type === 'SOA' ? "The SOA record of $zone is kept by this DNS server and can't be changed with a key."
				: "The name servers of $zone are this DNS server's (".($ns ? implode(', ', array_map(function ($x) { return rtrim($x, '.'); }, $ns)) : 'none set yet')
				  ."); they can't be changed with a key.");
		}
		if (isset($denied[$type]))
			return array(null, "$type records can't be set with a DNS API key.");
		$o = array('name' => $n.'.', 'type' => $type);
		if ($patch)
			$o['changetype'] = $ct;
		if (isset($s['ttl']))
			$o['ttl'] = max(1, min(2147483647, (int)$s['ttl']));
		if (isset($s['records']))
			$o['records'] = $recs;
		if (isset($s['comments']) && is_array($s['comments'])) {
			$o['comments'] = array();
			foreach (array_slice($s['comments'], 0, 50) as $cm)
				if (is_array($cm) && isset($cm['content']) && is_scalar($cm['content']))
					$o['comments'][] = array('content' => substr((string)$cm['content'], 0, 1000), 'account' => substr((string)($cm['account'] ?? ''), 0, 100),
					                         'modified_at' => (int)($cm['modified_at'] ?? time()));
		}
		$out[] = $o;
	}
	return array($out, '');
}

/* PowerDNS-style error: array(status, {"error": ...}). */
function dns_api_err($status, $message) {
	return array($status, array('error' => $message), 'application/json');
}

function dns_api_may(array $t, $perm) {
	return in_array($perm, api_token_scopes($t), true);
}

/* Serve one request (app/api/pdns.php). */
function dns_api_serve() {
	$t0 = microtime(true);
	header('Cache-Control: no-store');
	header('X-Content-Type-Options: nosniff');
	header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
	$method = strtoupper((string)$_SERVER['REQUEST_METHOD']);
	$path = (string)parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH);
	$path = '/'.trim(substr($path, strlen('/pdns')), '/');

	$t = null;
	$hdr = array();
	if (!dns_api_available() || !dns_api_ready())
		$out = dns_api_err(404, 'There is no DNS API on this server.');
	elseif (!dns_api_enabled())
		$out = dns_api_err(403, 'The DNS API is turned off on this server (its panel: Settings > DNS API keys).');
	else {
		$key = isset($_SERVER['HTTP_X_API_KEY']) ? trim((string)$_SERVER['HTTP_X_API_KEY']) : '';
		if ($key === '' && preg_match('/^Bearer\s+(\S+)\s*$/i', (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''), $m))
			$key = $m[1];
		$r = $key === '' ? array(401, result_error('Send the key in the header X-API-Key.')) : api_token_check($key, 'dns');
		if (isset($r['token_hash'])) {
			$t = $r;
			$GLOBALS['api_actor'] = 'dns:'.$t['name'];
			$out = dns_api_route($t, $method, $path);
		} else {
			$t = $r[3] ?? null;
			$hdr = $r[2] ?? array();
			$out = dns_api_err($r[0], $r[1]['error']);
		}
	}
	if ($t)
		api_log_call($t, $method, '/pdns'.$path, $out[0], $t0);
	http_response_code($out[0]);
	foreach ($hdr as $k => $v)
		header("$k: $v");
	if ($out[0] === 204)
		return;
	header('Content-Type: '.($out[2] ?? 'application/json'));
	echo is_string($out[1]) ? $out[1] : json_encode($out[1], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/* The answer to one request of key $t: array(status, body, content type). */
function dns_api_route(array $t, $method, $path) {
	global $db;
	$base = '/api/v1/servers/localhost';
	if ($path === '/api' || $path === '/api/v1') {
		return $method === 'GET' ? array(200, array(array('url' => '/api/v1', 'version' => 1)), 'application/json')
			: dns_api_err(405, 'Method not allowed');
	}
	if ($path === '/api/v1/servers' || $path === $base) {
		if ($method !== 'GET')
			return dns_api_err(405, 'Method not allowed');
		return array(200, $path === $base ? dns_api_server_info() : array(dns_api_server_info()), 'application/json');
	}

	if ($path === $base.'/zones') {
		if ($method === 'GET') {
			$r = dns_api_upstream('GET', $base.'/zones'.dns_api_query(array('zone', 'dnssec')));
			if ($r[0] !== 200)
				return $r;
			$list = json_decode($r[1], true);
			if (!is_array($list))
				return dns_api_err(502, 'The DNS server behind this API gave an answer it could not read.');
			$mine = array_flip(dns_key_zones($t['id']));
			return array(200, array_values(array_filter($list, function ($z) use ($mine) {
				return is_array($z) && isset($mine[dns_zone_name($z['name'] ?? '')]);
			})), 'application/json');
		}
		if ($method === 'POST')
			return dns_api_create($t);
		return dns_api_err(405, 'Method not allowed');
	}

	if (preg_match('#^'.preg_quote($base, '#').'/zones/([^/]+)(?:/(notify|rectify|export))?$#', $path, $m)) {
		$zone = dns_api_zone_from_id(rawurldecode($m[1]));
		/* someone else's zone and no zone look the same */
		if (!valid_domain($zone) || dns_key_zone_owner($zone) !== (int)$t['id'])
			return dns_api_err(404, "Could not find domain '".rawurldecode($m[1])."'");
		$up = $base.'/zones/'.rawurlencode($zone.'.');
		$sub = $m[2] ?? '';
		if ($sub === 'export')
			return $method === 'GET' ? dns_api_upstream('GET', $up.'/export') : dns_api_err(405, 'Method not allowed');
		if ($sub !== '') {
			if ($method !== 'PUT')
				return dns_api_err(405, 'Method not allowed');
			return dns_api_may($t, 'write') ? dns_api_upstream('PUT', $up.'/'.$sub) : dns_api_err(403, 'This key may only read.');
		}
		switch ($method) {
		case 'GET':
			return dns_api_upstream('GET', $up.dns_api_query(array('rrsets', 'rrset_name', 'rrset_type', 'include_disabled')));
		case 'PATCH':
			if (!dns_api_may($t, 'write'))
				return dns_api_err(403, 'This key may only read.');
			$b = dns_api_body();
			if ($b === null || !isset($b['rrsets']))
				return dns_api_err(400, 'Send the changes as JSON: {"rrsets": [...]}.');
			list($sets, $err) = dns_api_rrsets($zone, $b['rrsets'], true);
			if ($err !== '')
				return dns_api_err(422, $err);
			if (!$sets)
				return array(204, '', '');
			return dns_api_upstream('PATCH', $up, json_encode(array('rrsets' => $sets), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		case 'DELETE':
			if (!dns_api_may($t, 'delete'))
				return dns_api_err(403, 'This key may not remove zones.');
			$r = dns_api_upstream('DELETE', $up);
			if ($r[0] >= 200 && $r[0] < 300) {
				dns_key_zone_forget($zone);
				route_log("dns zone $zone removed by DNS API key {$t['name']}");
				plugin_hook('dns_zone.deleted', array('zone' => $zone, 'account' => '', 'key' => $t['name']));
			}
			return $r;
		case 'PUT':
			return dns_api_err(403, 'A zone\'s settings (kind, primaries, DNSSEC) are kept by this DNS server; a key changes only its records.');
		}
		return dns_api_err(405, 'Method not allowed');
	}

	if (strpos($path, $base.'/') === 0 || strpos($path, '/api/') === 0)
		return dns_api_err(403, 'A DNS API key reaches only its own zones and their records.');
	return dns_api_err(404, 'Not found');
}

/* POST .../zones: a new zone for key $t, with this server's name servers. */
function dns_api_create(array $t) {
	global $db;
	if (!dns_api_may($t, 'write'))
		return dns_api_err(403, 'This key may not create zones.');
	$b = dns_api_body();
	if ($b === null)
		return dns_api_err(400, 'Send the zone as JSON: {"name": "example.com.", "kind": "Native", ...}.');
	$zone = dns_zone_name($b['name'] ?? '');
	if (!valid_domain($zone) || strpos($zone, '.') === false)
		return dns_api_err(422, "'".($b['name'] ?? '')."' is not a domain name.");
	dns_provider_load($db);
	$all = get_zones();
	if (!is_array($all))
		return dns_api_err(502, 'The DNS server behind this API is not answering.');
	if (isset(array_change_key_case($all, CASE_LOWER)[$zone]))
		return dns_api_err(409, "Conflict: there already is a zone $zone on this DNS server.");
	$c = dns_key_conflict($zone, $t['id'], $all);
	if ($c !== '')
		return dns_api_err(403, $c);
	$max = dns_key_max_zones($t['id']);
	if ($max && count(dns_key_zones($t['id'])) >= $max)
		return dns_api_err(403, 'This key may hold at most '.dns_key_nzones($max).'.');
	$ns = dns_expected_nameservers();
	if (!$ns)
		return dns_api_err(503, 'This DNS server has no name servers set yet (its panel: Settings > DNS Settings).');
	list($sets, $err) = dns_api_rrsets($zone, $b['rrsets'] ?? array(), false);
	if ($err !== '')
		return dns_api_err(422, $err);
	$sets[] = array('name' => $zone.'.', 'type' => 'SOA', 'ttl' => 14400,
	                'records' => array(array('content' => strtolower($ns[0]).'. hostmaster.'.$zone.'. '.date('Ymd').'01 3600 1800 1209600 86400', 'disabled' => false)));
	$body = array_merge(array('name' => $zone.'.', 'kind' => 'Native', 'masters' => array(),
	              'nameservers' => array_map(function ($n) { return strtolower($n).'.'; }, $ns), 'rrsets' => $sets),
	              dns_cluster_zone_fields());     /* with secondaries: kind Master, in the catalog */
	$r = dns_api_upstream('POST', '/api/v1/servers/localhost/zones'.dns_api_query(array('rrsets')),
	                      json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
	if ($r[0] >= 200 && $r[0] < 300) {
		dns_key_zone_put($zone, $t['id'], 'key:'.$t['name']);
		route_log("dns zone $zone created by DNS API key {$t['name']}");
		plugin_hook('dns_zone.created', array('zone' => $zone, 'ip' => '', 'key' => $t['name']));
	}
	return $r;
}
