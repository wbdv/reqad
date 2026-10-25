<?php
/*
 * DNS cluster (Settings > DNS Settings > Cluster = templates/dns-settings-cluster.php,
 * only with dnscluster=1 in server-software.ini): this
 * server's PowerDNS is the primary, one or more plain PowerDNS machines are
 * its secondaries - the pair the name servers in DNS Settings point at.
 *
 * Standard PowerDNS replication, nothing of Reqad runs on a secondary:
 *   - every zone here is kind Master and a member of this server's catalog
 *     zone (RFC 9432, a Producer zone named <hostname>.catalog.invalid);
 *   - pdns.conf sends NOTIFY to the secondaries and lets them transfer the
 *     zones (block written by scripts/dns/pdns_cluster.sh, root, via sudo);
 *   - each secondary consumes the catalog: it makes, copies and removes the
 *     zones by itself (set up by scripts/dns/pdns-secondary-setup.sh, which
 *     the page shows as a command to run there).
 * New zones join at once: add_domain_in_dns() and the DNS API (dns_api_create)
 * put dns_cluster_zone_fields() in the zone; dns_cluster_sync() brings in
 * zones made any other way (the page offers it, the health check reports them).
 *
 * The catalog zone is made with SOA serial 1: PowerDNS numbers a producer
 * catalog's versions by the clock, and a catalog that started at a date-style
 * serial (2026102401, what the API gives a new zone) is never copied again.
 *
 * Setting dns-cluster (JSON): {"secondaries": [{"name", "addr", "added"}]}.
 * addr = IP or IP:port ([v6]:port); no port = 53.
 */

/* dnscluster=1 in etc/server-software.ini: the DNS cluster and the DNS API
   keys exist on this server at all (off by default: most servers use the
   DNS of another one). */
function dns_cluster_available() {
	return feature_enabled($GLOBALS['ini'] ?? array(), 'dnscluster');
}

/* '' when this server can be a primary, else why not. */
function dns_cluster_unavailable() {
	if (!dns_cluster_available())
		return 'The DNS cluster is not available on this server: set dnscluster=1 in /usr/local/reqad/etc/server-software.ini first.';
	if (setting_get('dns-provider') !== 'powerdns')
		return 'The DNS cluster needs PowerDNS as this server\'s DNS provider (Settings > DNS Settings).';
	if (setting_get('powerdns-mode') === 'hidden-master')
		return 'The DNS cluster replaces the hidden master mode: switch DNS Settings to direct mode first.';
	$host = strtolower((string)parse_url((string)setting_get('powerdns-server'), PHP_URL_HOST));
	if (!in_array($host, array('127.0.0.1', 'localhost', '::1', '[::1]'), true))
		return 'The DNS cluster needs PowerDNS on this server (Server address http://127.0.0.1:8081 in DNS Settings).';
	return '';
}

function dns_cluster_settings() {
	$s = json_decode((string)setting_get('dns-cluster', ''), true);
	$s = is_array($s) ? $s : array();
	$s['secondaries'] = isset($s['secondaries']) && is_array($s['secondaries']) ? array_values($s['secondaries']) : array();
	return $s;
}

function dns_cluster_secondaries() {
	return dns_cluster_settings()['secondaries'];
}

/* The cluster is in use: at least one secondary. */
function dns_cluster_on() {
	return dns_cluster_unavailable() === '' && (bool)dns_cluster_secondaries();
}

/* This server's catalog zone (no final dot). */
function dns_cluster_catalog() {
	return strtolower(trim((string)gethostname(), '.')).'.catalog.invalid';
}

/* The cluster's test zone (no final dot): a hidden member of the catalog
   whose TXT record the health check changes, to time how long a change takes
   to reach each secondary (app/functions/dns_cluster_health.php). */
function dns_cluster_probe_zone() {
	return strtolower(trim((string)gethostname(), '.')).'.probe.invalid';
}

/* The catalog and the test zone: the cluster's own, left out of zone lists. */
function dns_cluster_hidden_zone($zone) {
	$z = strtolower(rtrim((string)$zone, '.'));
	return $z === dns_cluster_probe_zone() || $z === dns_cluster_catalog();
}

/* What a new zone needs to be copied to the secondaries (merged into the
   PowerDNS zone it is created with). */
function dns_cluster_zone_fields() {
	return dns_cluster_on() ? array('kind' => 'Master', 'catalog' => dns_cluster_catalog().'.') : array();
}

/* "203.0.113.5", "203.0.113.5:5301", "[2001:db8::5]:53", "2001:db8::5" ->
   array(ip, port), or null. */
function dns_cluster_addr($a) {
	$a = trim((string)$a);
	if (preg_match('/^\[([0-9a-fA-F:.]+)\](?::(\d{1,5}))?$/', $a, $m) || preg_match('/^([0-9.]+)(?::(\d{1,5}))?$/', $a, $m))
		$ip = $m[1];
	elseif (filter_var($a, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6))
		return array(strtolower($a), 53);
	else
		return null;
	$port = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : 53;
	if (!filter_var($ip, FILTER_VALIDATE_IP) || $port < 1 || $port > 65535)
		return null;
	return array(strtolower($ip), $port);
}

/* An address as PowerDNS's also-notify / catalog primaries want it. */
function dns_cluster_addr_text($ip, $port) {
	$v6 = strpos($ip, ':') !== false;
	if ($port === 53)
		return $ip;
	return ($v6 ? "[$ip]" : $ip).':'.$port;
}

/* The addresses of this machine. */
function dns_cluster_own_ips() {
	$out = array();
	foreach (preg_split('/\s+/', trim((string)shell_exec('/usr/sbin/ip -o addr show 2>/dev/null | awk \'{print $4}\''))) as $c)
		if (($ip = strtolower(explode('/', $c)[0])) !== '')
			$out[$ip] = true;
	return $out;
}

/* The primary's pdns.conf as scripts/dns/pdns_cluster.sh status reads it
   (version, values in effect, the block), or array('error' => ...). */
function dns_cluster_conf() {
	static $st = null;
	if ($st === null) {
		$out = (string)shell_exec('sudo -n '._PATH.'/scripts/dns/pdns_cluster.sh status 2>&1');
		$st = json_decode($out, true);
		if (!is_array($st))
			$st = array('error' => trim($out) !== '' ? trim($out) : 'The PowerDNS settings could not be read.');
	}
	return $st;
}

/* Where the secondaries find this server: its PowerDNS address (with the
   port when it is not 53) - the main public address unless PowerDNS listens
   on one address only. */
function dns_cluster_primary_addr() {
	$c = dns_cluster_conf();
	$port = (int)($c['local_port'] ?? 53) ?: 53;
	$ip = '';
	foreach ((array)($c['local_address'] ?? array()) as $a)
		if (filter_var($a, FILTER_VALIDATE_IP) && !in_array($a, array('0.0.0.0', '::'), true)) {
			$ip = $a;
			break;
		}
	if ($ip === '')
		$ip = server_primary_ip();
	return dns_cluster_addr_text($ip, $port);
}

/* The command to run on a secondary (as root). */
function dns_cluster_setup_command() {
	$script = (string)@file_get_contents(_PATH.'/scripts/dns/pdns-secondary-setup.sh');
	return "cat > /root/reqad-dns-secondary.sh <<'REQAD_EOF'\n".rtrim($script)."\nREQAD_EOF\n"
		.'bash /root/reqad-dns-secondary.sh --primary='.dns_cluster_primary_addr().' --catalog='.dns_cluster_catalog()."\n";
}

/* Add a secondary: $name its host name (e.g. the name server it answers
   as), $addr its address (IP, or IP:port). */
function dns_cluster_add($name, $addr, $by = '') {
	if (($why = dns_cluster_unavailable()) !== '')
		return result_error($why);
	$name = strtolower(rtrim(trim((string)$name), '.'));
	if (!valid_domain($name) || strpos($name, '.') === false)
		return result_error('Give the secondary a host name, such as ns2.example.com.');
	$a = dns_cluster_addr($addr);
	if (!$a)
		return result_error("'$addr' is not an IP address (e.g. 203.0.113.5, or 203.0.113.5:5301 for another port).");
	if (isset(dns_cluster_own_ips()[$a[0]]))
		return result_error("{$a[0]} is an address of this server: a secondary is another machine.");
	$s = dns_cluster_settings();
	foreach ($s['secondaries'] as $x) {
		if ($x['name'] === $name)
			return result_error("There already is a secondary $name.");
		$b = dns_cluster_addr($x['addr']);
		if ($b && $b[0] === $a[0] && $b[1] === $a[1])
			return result_error("{$x['name']} already has the address ".dns_cluster_addr_text($a[0], $a[1]).'.');
	}
	if (count($s['secondaries']) >= 10)
		return result_error('At most 10 secondaries.');
	$s['secondaries'][] = array('name' => $name, 'addr' => dns_cluster_addr_text($a[0], $a[1]), 'added' => time());
	setting_put('dns-cluster', json_encode($s, JSON_UNESCAPED_SLASHES));
	auth_log_event('dns_cluster_add', "$name ".dns_cluster_addr_text($a[0], $a[1]), 0, (string)$by);
	$r = dns_cluster_apply();
	if (!$r['ok']) {
		/* not half added: the list goes back to what it was */
		array_pop($s['secondaries']);
		setting_put('dns-cluster', json_encode($s, JSON_UNESCAPED_SLASHES));
		return $r;
	}
	route_log("dns cluster: secondary $name added");
	dns_cluster_health_event($name, 'added', "$name added");
	plugin_hook('dns_cluster.changed', array('secondaries' => $s['secondaries']));
	return result_ok("$name added. Now run the setup command on it (Setup on its row).", array('name' => $name), $r['warnings']);
}

function dns_cluster_remove($name, $by = '') {
	$s = dns_cluster_settings();
	$left = array_values(array_filter($s['secondaries'], function ($x) use ($name) { return $x['name'] !== $name; }));
	if (count($left) === count($s['secondaries']))
		return result_error("There is no secondary $name.");
	$s['secondaries'] = $left;
	setting_put('dns-cluster', json_encode($s, JSON_UNESCAPED_SLASHES));
	auth_log_event('dns_cluster_remove', (string)$name, 0, (string)$by);
	$r = $left ? dns_cluster_apply() : dns_cluster_helper('remove');
	route_log("dns cluster: secondary $name removed");
	dns_cluster_health_event($name, 'removed', "$name removed");
	plugin_hook('dns_cluster.changed', array('secondaries' => $left));
	if (!$r['ok'])
		return result_error("$name was taken off the list, but PowerDNS was not updated: ".$r['error']);
	return result_ok("$name removed: this server no longer sends it the zones. Its own copy stays until you switch it off"
		.($left ? '.' : '; the zones here stay as they are.'));
}

/* Run scripts/dns/pdns_cluster.sh: result. */
function dns_cluster_helper($verb, array $args = array()) {
	$cmd = 'sudo -n '._PATH.'/scripts/dns/pdns_cluster.sh '.escapeshellarg($verb);
	foreach ($args as $a)
		$cmd .= ' '.escapeshellarg($a);
	exec($cmd.' 2>&1', $out, $rc);
	$text = trim(implode("\n", $out));
	return $rc === 0 ? result_ok($text) : result_error($text !== '' ? $text : "pdns_cluster.sh $verb failed ($rc).");
}

/* pdns.conf, the catalog zone and every zone in it, as the secondaries list
   says. $drop_kept: stop sending to the addresses that were in pdns.conf
   before the cluster. */
function dns_cluster_apply($drop_kept = false) {
	global $db;
	if (($why = dns_cluster_unavailable()) !== '')
		return result_error($why);
	$sec = dns_cluster_secondaries();
	if (!$sec)
		return dns_cluster_helper('remove');
	$conf = dns_cluster_conf();
	if (isset($conf['error']))
		return result_error($conf['error']);
	if (version_compare((string)($conf['version'] ?? '0'), '4.7', '<'))
		return result_error('PowerDNS '.($conf['version'] ?? '?').' is too old for the cluster: catalog zones need 4.7 or newer.');
	$notify = $axfr = array();
	foreach ($sec as $x) {
		$a = dns_cluster_addr($x['addr']);
		if (!$a)
			continue;
		$notify[] = dns_cluster_addr_text($a[0], $a[1]);
		$axfr[] = $a[0];
	}
	$args = array('--notify='.implode(',', array_unique($notify)), '--axfr='.implode(',', array_unique($axfr)));
	if ($drop_kept)
		$args[] = '--drop-kept';
	$h = dns_cluster_helper('apply', $args);
	if (!$h['ok'])
		return $h;
	$c = dns_cluster_catalog_ensure();
	if ($c !== '')
		return result_error($c);
	$s = dns_cluster_sync();
	return result_ok('The DNS cluster is set up.', $s['data'], $s['ok'] ? $s['warnings'] : array($s['error']));
}

/* The catalog zone exists (kind Producer, serial 1): '' or the error. */
function dns_cluster_catalog_ensure() {
	global $db;
	dns_provider_load($db);
	$cat = dns_cluster_catalog().'.';
	$url = get_server_url();
	if (substr($url, 0, 6) === 'Error:')
		return 'PowerDNS is not answering: '.substr($url, 7);
	$j = powerdns_api($url.'/zones/'.rawurlencode($cat).'?rrsets=false', true);
	if (($j['success'] ?? 0) == 1) {
		if (($j['kind'] ?? '') === 'Producer')
			return '';
		$j = powerdns_api($url.'/zones/'.rawurlencode($cat), true, 'PUT', json_encode(array('kind' => 'Producer')));
		return ($j['success'] ?? 0) == 1 ? '' : 'The catalog zone could not be made a catalog: '.($j['error'] ?? $j['reason']);
	}
	$body = array('name' => $cat, 'kind' => 'Producer', 'soa_edit_api' => '', 'rrsets' => array(
		array('name' => $cat, 'type' => 'SOA', 'ttl' => 3600, 'records' => array(array('content' => 'invalid. invalid. 1 3600 600 2147483646 0', 'disabled' => false))),
		array('name' => $cat, 'type' => 'NS', 'ttl' => 3600, 'records' => array(array('content' => 'invalid.', 'disabled' => false)))));
	$j = powerdns_api($url.'/zones', true, 'POST', json_encode($body, JSON_UNESCAPED_SLASHES));
	if (($j['success'] ?? 0) != 1)
		return 'The catalog zone was not created: '.(is_string($j['error'] ?? null) ? $j['error'] : 'code '.$j['reason']);
	route_log("dns cluster: catalog zone $cat created");
	return '';
}

/* Where this server's PowerDNS answers DNS questions: array(ip, port). */
function dns_cluster_local_dns() {
	$c = dns_cluster_conf();
	$port = (int)($c['local_port'] ?? 53) ?: 53;
	foreach ((array)($c['local_address'] ?? array()) as $a)
		if (filter_var($a, FILTER_VALIDATE_IP) && !in_array($a, array('0.0.0.0', '::'), true))
			return array($a, $port);
	return array('127.0.0.1', $port);
}

/* Which zones of the PowerDNS zone list $list are members of the catalog:
   name (with the final dot) => true. PowerDNS 4.8 leaves "catalog" empty in
   the zone list, so the members are read from the catalog itself (a zone
   transfer from this server to itself), else asked zone by zone. */
function dns_cluster_members(array $list, $url) {
	$cat = dns_cluster_catalog().'.';
	$in = $unsure = array();
	foreach ($list as $z) {
		if (!is_array($z) || !isset($z['name']) || ($z['kind'] ?? '') !== 'Master')
			continue;
		if (($z['catalog'] ?? '') === $cat)
			$in[strtolower($z['name'])] = true;
		elseif (($z['catalog'] ?? '') === '')
			$unsure[] = $z;
	}
	if (!$unsure)
		return $in;
	list($ip, $port) = dns_cluster_local_dns();
	exec('dig +noall +answer +tcp +time=5 +tries=1 -p '.(int)$port.' @'.escapeshellarg($ip).' '.escapeshellarg($cat).' AXFR 2>/dev/null', $lines, $rc);
	$members = array();
	foreach ($rc === 0 ? $lines : array() as $l) {
		$f = preg_split('/\s+/', trim($l));
		if (count($f) >= 5 && strtoupper($f[3]) === 'PTR' && substr(strtolower($f[0]), -strlen('.zones.'.$cat)) === '.zones.'.$cat)
			$members[strtolower($f[4])] = true;
	}
	if ($members) {
		foreach ($unsure as $z)
			if (isset($members[strtolower($z['name'])]))
				$in[strtolower($z['name'])] = true;
		return $in;
	}
	foreach ($unsure as $z) {
		$j = powerdns_api($url.'/zones/'.rawurlencode($z['id']).'?rrsets=false', true);
		if (($j['catalog'] ?? '') === $cat)
			$in[strtolower($z['name'])] = true;
	}
	return $in;
}

/* Every zone (but secondary and catalog zones) kind Master and in the
   catalog. data: changed, total, failed. */
function dns_cluster_sync() {
	global $db;
	if (!dns_cluster_on())
		return result_error('No secondary is set up.');
	dns_provider_load($db);
	$url = get_server_url();
	if (substr($url, 0, 6) === 'Error:')
		return result_error('PowerDNS is not answering: '.substr($url, 7));
	$cat = dns_cluster_catalog().'.';
	$list = powerdns_api($url.'/zones', true);
	$in = dns_cluster_members($list, $url);
	$n = $total = 0;
	$failed = array();
	foreach ($list as $z) {
		if (!is_array($z) || !isset($z['name']) || !in_array($z['kind'] ?? '', array('Native', 'Master'), true))
			continue;
		if (dns_cluster_hidden_zone($z['name']))
			continue;
		$total++;
		if (isset($in[strtolower($z['name'])]))
			continue;
		$j = powerdns_api($url.'/zones/'.rawurlencode($z['id']), true, 'PUT', json_encode(array('kind' => 'Master', 'catalog' => $cat)));
		if (($j['success'] ?? 0) == 1)
			$n++;
		else
			$failed[] = rtrim($z['name'], '.').': '.(is_string($j['error'] ?? null) ? $j['error'] : 'code '.$j['reason']);
	}
	if ($n)
		route_log("dns cluster: $n zones added to the catalog");
	return result_ok($n ? ($n === 1 ? '1 zone joined' : "$n zones joined").' the cluster.' : 'Every zone is in the cluster.', array('changed' => $n, 'total' => $total),
		$failed ? array('Not added: '.implode('; ', array_slice($failed, 0, 10))) : array());
}

/* Zones of this server that are not in the cluster yet (names). */
function dns_cluster_zones_out() {
	global $db;
	if (!dns_cluster_on())
		return array();
	dns_provider_load($db);
	$url = get_server_url();
	if (substr($url, 0, 6) === 'Error:')
		return array();
	$list = powerdns_api($url.'/zones', true);
	$in = dns_cluster_members($list, $url);
	$out = array();
	foreach ($list as $z)
		if (is_array($z) && isset($z['name']) && in_array($z['kind'] ?? '', array('Native', 'Master'), true)
		    && !isset($in[strtolower($z['name'])]) && !dns_cluster_hidden_zone($z['name']))
			$out[] = rtrim($z['name'], '.');
	return $out;
}

/*
 * The SOA serials of $zones at a DNS server, asked over UDP all at once:
 * zone => serial (int), or 'refused' / 'missing' / 'not authoritative' /
 * 'no answer'. $timeout seconds for all of them together.
 */
function dns_soa_serials($ip, $port, array $zones, $timeout = 3.0) {
	$out = array_fill_keys($zones, 'no answer');
	if (!$zones)
		return $out;
	$host = strpos($ip, ':') !== false ? "[$ip]" : $ip;
	$s = @stream_socket_client("udp://$host:$port", $errno, $errstr, 2);
	if (!$s)
		return $out;
	stream_set_blocking($s, false);
	$ids = array();
	$id = mt_rand(1, 30000);
	foreach ($zones as $z) {
		$id = ($id % 65535) + 1;
		$ids[$id] = $z;
		$q = pack('nnnnnn', $id, 0, 1, 0, 0, 0);
		foreach (explode('.', rtrim($z, '.')) as $l)
			$q .= chr(strlen($l)).$l;
		$q .= "\0".pack('nn', 6, 1);
		@fwrite($s, $q);
	}
	$end = microtime(true) + $timeout;
	$left = count($ids);
	$skip = function ($p, $o) {
		while ($o < strlen($p)) {
			$l = ord($p[$o]);
			if ($l === 0)
				return $o + 1;
			if (($l & 0xC0) === 0xC0)
				return $o + 2;
			$o += $l + 1;
		}
		return strlen($p);
	};
	while ($left > 0 && ($wait = $end - microtime(true)) > 0) {
		$r = array($s);
		$w = $e = null;
		if (!@stream_select($r, $w, $e, (int)$wait, (int)(fmod($wait, 1) * 1e6)))
			break;
		while (($p = @fread($s, 4096)) !== false && $p !== '') {
			if (strlen($p) < 12)
				continue;
			$h = unpack('nid/nflags/nqd/nan', substr($p, 0, 8));
			if (!isset($ids[$h['id']]) || $out[$ids[$h['id']]] !== 'no answer')
				continue;
			$z = $ids[$h['id']];
			$left--;
			$rcode = $h['flags'] & 0xF;
			if ($rcode === 5) { $out[$z] = 'refused'; continue; }
			if ($rcode === 9) { $out[$z] = 'not authoritative'; continue; }
			if (!($h['flags'] & 0x400)) { $out[$z] = 'not authoritative'; continue; }
			$o = 12;
			for ($i = 0; $i < unpack('n', substr($p, 4, 2))[1]; $i++)
				$o = $skip($p, $o) + 4;
			$serial = 'missing';
			for ($i = 0; $i < $h['an'] && $o + 10 <= strlen($p); $i++) {
				$o = $skip($p, $o);
				$rr = unpack('ntype/nclass/Nttl/nlen', substr($p, $o, 10));
				$o += 10;
				if ($rr['type'] === 6) {
					$x = $skip($p, $skip($p, $o));
					if ($x + 4 <= strlen($p))
						$serial = unpack('N', substr($p, $x, 4))[1];
					break;
				}
				$o += $rr['len'];
			}
			$out[$z] = $serial;
		}
	}
	fclose($s);
	return $out;
}

/*
 * How each secondary is doing: per secondary {name, addr, answering, total,
 * in_step, behind: [zone => [primary serial, secondary serial]], missing:
 * [zones]}. The primary's serials come from its own answers (the same
 * question), so both sides are compared the same way.
 */
function dns_cluster_check() {
	global $db;
	$sec = dns_cluster_secondaries();
	if (!$sec)
		return array();
	dns_provider_load($db);
	$url = get_server_url();
	$zones = array();
	if (substr($url, 0, 6) !== 'Error:')
		foreach (powerdns_api($url.'/zones', true) as $z)
			if (is_array($z) && isset($z['name']) && in_array($z['kind'] ?? '', array('Native', 'Master'), true) && !dns_cluster_hidden_zone($z['name']))
				$zones[] = rtrim($z['name'], '.');
	list($pip, $pport) = dns_cluster_local_dns();
	$mine = dns_soa_serials($pip, $pport, $zones);
	$out = array();
	foreach ($sec as $x) {
		$a = dns_cluster_addr($x['addr']);
		$theirs = $a ? dns_soa_serials($a[0], $a[1], $zones) : array();
		$r = array('name' => $x['name'], 'addr' => $x['addr'], 'answering' => false, 'total' => count($zones), 'in_step' => 0,
		           'behind' => array(), 'missing' => array(), 'checked' => time());
		foreach ($zones as $z) {
			$t = $theirs[$z] ?? 'no answer';
			if ($t !== 'no answer')
				$r['answering'] = true;
			if (is_int($t) && is_int($mine[$z]) && $t === $mine[$z])
				$r['in_step']++;
			elseif (is_int($t) && is_int($mine[$z]))
				$r['behind'][$z] = array($mine[$z], $t);
			elseif (!is_int($t))
				$r['missing'][] = $z;
		}
		$out[] = $r;
	}
	return $out;
}

/* Where each name server name points (DNS Settings' names): name =>
   array(addresses, 'this server' | secondary name | ''). */
function dns_cluster_ns_targets() {
	$own = dns_cluster_own_ips();
	$sec = array();
	foreach (dns_cluster_secondaries() as $x)
		if ($a = dns_cluster_addr($x['addr']))
			$sec[$a[0]] = $x['name'];
	$out = array();
	foreach (dns_expected_nameservers() as $ns) {
		$ips = array();
		foreach (array(DNS_A, DNS_AAAA) as $t)
			foreach ((array)@dns_get_record($ns.'.', $t) as $r)
				$ips[] = strtolower($r['ip'] ?? $r['ipv6'] ?? '');
		$ips = array_values(array_filter(array_unique($ips)));
		$who = '';
		foreach ($ips as $ip)
			if (isset($own[$ip]) || $ip === server_primary_ip())
				$who = 'this server';
			elseif (isset($sec[$ip]))
				$who = $sec[$ip];
		$out[$ns] = array($ips, $who);
	}
	return $out;
}
