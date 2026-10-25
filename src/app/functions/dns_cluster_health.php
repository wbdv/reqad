<?php
/*
 * DNS cluster health (Settings > DNS Settings > Cluster, `reqad dns check`,
 * the health check dns_cluster). `reqad dns check` runs every 5 minutes
 * (cron, root) and the page's Check now runs the same: dns_cluster_health_collect().
 *
 * Each run:
 *   - this server: PowerDNS answering, its version and query counters;
 *   - the test zone (dns_cluster_probe_zone(), a hidden catalog member with
 *     SOA-EDIT-API EPOCH): its TXT record is changed, and each secondary is
 *     asked every second until it has the new serial = how long a change
 *     takes to arrive (NOTIFY + transfer);
 *   - each secondary: answers over UDP and TCP (times), every zone's serial
 *     against this server's, its name server name points to it, it refuses
 *     zone transfers to others (this server is one: secondaries allow
 *     transfers only from localhost) and recursion for other domains;
 *   - once an hour, where each domain points at its registry (the NS records
 *     the parent zone hands out, asked of the TLD's servers directly).
 * Kept in db/dns-cluster-health.json (reqad:reqad 0640): a sample per
 * secondary per run for 7 days, the newest details, events (state changes,
 * added / removed) for 7 days, the registry answers.
 */

function dns_cluster_health_file() {
	return _PATH.'/db/dns-cluster-health.json';
}

function dns_cluster_health_load() {
	$h = json_decode((string)@file_get_contents(dns_cluster_health_file()), true);
	$h = is_array($h) ? $h : array();
	foreach (array('samples', 'last', 'events', 'primary', 'ns', 'out') as $k)
		$h[$k] = isset($h[$k]) && is_array($h[$k]) ? $h[$k] : array();
	$h['delegation'] = isset($h['delegation']) && is_array($h['delegation']) ? $h['delegation'] : array('checked' => 0, 'zones' => array());
	$h['checked'] = (int)($h['checked'] ?? 0);
	return $h;
}

function dns_cluster_health_save(array $h) {
	$f = dns_cluster_health_file();
	$tmp = $f.'.'.getmypid().'.tmp';
	if (@file_put_contents($tmp, json_encode($h, JSON_UNESCAPED_SLASHES)) === false)
		return false;
	@chmod($tmp, 0640);
	if (function_exists('posix_geteuid') && posix_geteuid() === 0)
		@chown($tmp, 'reqad') && @chgrp($tmp, 'reqad');
	return @rename($tmp, $f);
}

/* One event (added, removed, ok, behind, down) - also from dns_cluster_add/remove. */
function dns_cluster_health_event($name, $kind, $text) {
	$lock = dns_cluster_health_lock(5);
	$h = dns_cluster_health_load();
	$h['events'][] = array(time(), (string)$name, (string)$kind, (string)$text);
	if ($kind === 'removed')
		unset($h['samples'][$name], $h['last'][$name]);
	dns_cluster_health_save($h);
	if ($lock)
		dns_cluster_health_unlock($lock);
}

/* One run at a time (cron and Check now): a lock handle, or null after $wait s. */
function dns_cluster_health_lock($wait) {
	$f = dns_cluster_health_file().'.lock';
	$new = !file_exists($f);
	$fh = @fopen($f, 'c');
	if (!$fh)
		return null;
	if ($new && function_exists('posix_geteuid') && posix_geteuid() === 0)
		@chown($f, 'reqad') && @chgrp($f, 'reqad');
	$end = microtime(true) + $wait;
	do {
		if (flock($fh, LOCK_EX | LOCK_NB))
			return $fh;
		usleep(250000);
	} while (microtime(true) < $end);
	fclose($fh);
	return null;
}

function dns_cluster_health_unlock($fh) {
	flock($fh, LOCK_UN);
	fclose($fh);
}

/* ---- a small DNS client (UDP and TCP, one question) ----------------------- */

function dns_wire_types() {
	return array('A' => 1, 'NS' => 2, 'CNAME' => 5, 'SOA' => 6, 'PTR' => 12, 'TXT' => 16, 'AAAA' => 28, 'AXFR' => 252);
}

/* A name at $o in the message $p (compression followed); $o moves past it. */
function dns_wire_name($p, &$o) {
	$labels = array();
	$jumped = false;
	$pos = $o;
	for ($hops = 0; $hops < 64 && $pos < strlen($p); $hops++) {
		$l = ord($p[$pos]);
		if ($l === 0) {
			if (!$jumped)
				$o = $pos + 1;
			return strtolower(implode('.', $labels));
		}
		if (($l & 0xC0) === 0xC0) {
			if ($pos + 1 >= strlen($p))
				break;
			if (!$jumped)
				$o = $pos + 2;
			$jumped = true;
			$pos = (($l & 0x3F) << 8) | ord($p[$pos + 1]);
			continue;
		}
		$labels[] = substr($p, $pos + 1, $l);
		$pos += $l + 1;
	}
	if (!$jumped)
		$o = strlen($p);
	return strtolower(implode('.', $labels));
}

/* The records of one section: array(name, type, ttl, data). */
function dns_wire_records($p, &$o, $n) {
	$out = array();
	for ($i = 0; $i < $n && $o < strlen($p); $i++) {
		$name = dns_wire_name($p, $o);
		if ($o + 10 > strlen($p))
			break;
		$rr = unpack('ntype/nclass/Nttl/nlen', substr($p, $o, 10));
		$o += 10;
		$start = $o;
		$data = null;
		switch ($rr['type']) {
		case 1:  $data = strlen($p) >= $o + 4 ? inet_ntop(substr($p, $o, 4)) : null; break;
		case 28: $data = strlen($p) >= $o + 16 ? inet_ntop(substr($p, $o, 16)) : null; break;
		case 2: case 5: case 12:
			$x = $o; $data = dns_wire_name($p, $x); break;
		case 6:
			$x = $o; $m = dns_wire_name($p, $x); $r = dns_wire_name($p, $x);
			$data = $x + 4 <= strlen($p) ? array($m, $r, unpack('N', substr($p, $x, 4))[1]) : null; break;
		case 16:
			$x = $o; $t = '';
			while ($x < $start + $rr['len']) { $l = ord($p[$x]); $t .= substr($p, $x + 1, $l); $x += $l + 1; }
			$data = $t; break;
		}
		$o = $start + $rr['len'];
		$out[] = array($name, $rr['type'], $rr['ttl'], $data);
	}
	return $out;
}

/*
 * Ask $ip:$port one question. $opt: tcp (bool), rd (recursion desired,
 * default false), timeout (s, default 3). null when nothing came back, else
 * array(rcode, aa, tc, ms, answer, authority) with records as above.
 */
function dns_wire_query($ip, $port, $name, $type, array $opt = array()) {
	$types = dns_wire_types();
	$qtype = is_int($type) ? $type : ($types[strtoupper($type)] ?? 0);
	$tcp = !empty($opt['tcp']);
	$timeout = (float)($opt['timeout'] ?? 3);
	$id = mt_rand(1, 65535);
	$q = pack('nnnnnn', $id, !empty($opt['rd']) ? 0x0100 : 0, 1, 0, 0, 0);
	foreach (explode('.', trim((string)$name, '.')) as $l)
		if ($l !== '')
			$q .= chr(min(63, strlen($l))).substr($l, 0, 63);
	$q .= "\0".pack('nn', $qtype, 1);
	$host = strpos($ip, ':') !== false ? "[$ip]" : $ip;
	$t0 = microtime(true);
	$s = @stream_socket_client(($tcp ? 'tcp' : 'udp')."://$host:$port", $errno, $errstr, $timeout);
	if (!$s)
		return null;
	stream_set_timeout($s, (int)ceil($timeout));
	$p = '';
	if ($tcp) {
		@fwrite($s, pack('n', strlen($q)).$q);
		$len = @fread($s, 2);
		if (strlen((string)$len) === 2) {
			$want = unpack('n', $len)[1];
			while (strlen($p) < $want && microtime(true) - $t0 < $timeout) {
				$chunk = @fread($s, $want - strlen($p));
				if ($chunk === false || $chunk === '')
					break;
				$p .= $chunk;
			}
		}
	} else {
		@fwrite($s, $q);
		$end = $t0 + $timeout;
		while (($wait = $end - microtime(true)) > 0) {
			$r = array($s); $w = $e = null;
			if (!@stream_select($r, $w, $e, (int)$wait, (int)(fmod($wait, 1) * 1e6)))
				break;
			$p = (string)@fread($s, 65535);
			if (strlen($p) >= 12 && unpack('n', substr($p, 0, 2))[1] === $id)
				break;
			$p = '';
		}
	}
	fclose($s);
	$ms = (int)round((microtime(true) - $t0) * 1000);
	if (strlen($p) < 12 || unpack('n', substr($p, 0, 2))[1] !== $id)
		return null;
	$h = unpack('nid/nflags/nqd/nan/nns/nar', substr($p, 0, 12));
	$o = 12;
	for ($i = 0; $i < $h['qd']; $i++) {
		dns_wire_name($p, $o);
		$o += 4;
	}
	$an = dns_wire_records($p, $o, $h['an']);
	$ns = dns_wire_records($p, $o, $h['ns']);
	return array('rcode' => $h['flags'] & 0xF, 'aa' => (bool)($h['flags'] & 0x400), 'tc' => (bool)($h['flags'] & 0x200),
	             'ms' => $ms, 'answer' => $an, 'authority' => $ns);
}

/* The SOA serial $ip has for $zone (authoritative answer), or null. */
function dns_wire_serial($ip, $port, $zone, array $opt = array()) {
	$r = dns_wire_query($ip, $port, $zone, 'SOA', $opt);
	if (!$r || $r['rcode'] !== 0 || !$r['aa'])
		return null;
	foreach ($r['answer'] as $rr)
		if ($rr[1] === 6 && is_array($rr[3]))
			return (int)$rr[3][2];
	return null;
}

/* ---- where a domain points at its registry -------------------------------- */

/* Addresses of the servers of a zone ($name: names of its NS), at most 3. */
function dns_delegation_ips(array $names) {
	$ips = array();
	foreach ($names as $n) {
		foreach ((array)@gethostbynamel($n.'.') as $ip)
			$ips[] = $ip;
		if (count($ips) >= 3)
			break;
	}
	return array_slice(array_values(array_unique($ips)), 0, 3);
}

/*
 * The name servers the parent zone gives for $zone: array(state, names):
 * 'ok' (names), 'none' (the registry does not know it: not registered, or
 * no such top-level domain), 'unknown' (no answer). Asked of the TLD's
 * servers (and of a second level like co.uk when they refer there), no
 * recursion. $cache keeps the servers of each parent.
 */
function dns_delegation($zone, array &$cache) {
	$zone = strtolower(rtrim($zone, '.'));
	$labels = explode('.', $zone);
	if (count($labels) < 2)
		return array('none', array());
	$cut = end($labels);
	if (!isset($cache[$cut])) {
		$names = array();
		foreach ((array)@dns_get_record($cut.'.', DNS_NS) as $r)
			if (!empty($r['target']))
				$names[] = strtolower($r['target']);
		$cache[$cut] = $names ? dns_delegation_ips($names) : array();
	}
	if (!$cache[$cut])
		return array('none', array());
	for ($hop = 0; $hop < 4; $hop++) {
		$r = null;
		foreach ($cache[$cut] as $ip) {
			$r = dns_wire_query($ip, 53, $zone, 'NS', array('timeout' => 2));
			if ($r && $r['tc'])
				$r = dns_wire_query($ip, 53, $zone, 'NS', array('timeout' => 3, 'tcp' => true));
			if ($r)
				break;
		}
		if (!$r)
			return array('unknown', array());
		if ($r['rcode'] === 3)
			return array('none', array());
		$found = $deeper = array();
		$deeper_cut = '';
		foreach (array_merge($r['answer'], $r['authority']) as $rr) {
			if ($rr[1] !== 2 || !is_string($rr[3]))
				continue;
			if ($rr[0] === $zone)
				$found[] = $rr[3];
			elseif (substr($zone, -strlen('.'.$rr[0])) === '.'.$rr[0] && strlen($rr[0]) > strlen($cut)) {
				$deeper[] = $rr[3];
				$deeper_cut = $rr[0];
			}
		}
		if ($found) {
			$found = array_values(array_unique($found));
			sort($found);
			return array('ok', $found);
		}
		if (!$deeper)
			return array('none', array());
		$cut = $deeper_cut;
		if (!isset($cache[$cut]))
			$cache[$cut] = dns_delegation_ips(array_unique($deeper));
		if (!$cache[$cut])
			return array('unknown', array());
	}
	return array('unknown', array());
}

/* ---- the test zone ---------------------------------------------------------- */

/* The test zone exists (Master, in the catalog, SOA-EDIT-API EPOCH): '' or the error. */
function dns_cluster_probe_ensure($url) {
	$z = dns_cluster_probe_zone().'.';
	$cat = dns_cluster_catalog().'.';
	$j = powerdns_api($url.'/zones/'.rawurlencode($z).'?rrsets=false', true);
	if (($j['success'] ?? 0) == 1) {
		if (($j['kind'] ?? '') === 'Master' && ($j['catalog'] ?? $cat) === $cat && ($j['soa_edit_api'] ?? '') === 'EPOCH')
			return '';
		$j = powerdns_api($url.'/zones/'.rawurlencode($z), true, 'PUT', json_encode(array('kind' => 'Master', 'catalog' => $cat, 'soa_edit_api' => 'EPOCH')));
		return ($j['success'] ?? 0) == 1 ? '' : 'The test zone could not be set up: '.($j['error'] ?? $j['reason']);
	}
	$body = array('name' => $z, 'kind' => 'Master', 'catalog' => $cat, 'soa_edit_api' => 'EPOCH', 'rrsets' => array(
		array('name' => $z, 'type' => 'SOA', 'ttl' => 60, 'records' => array(array('content' => 'invalid. invalid. '.time().' 3600 600 2147483646 60', 'disabled' => false))),
		array('name' => $z, 'type' => 'NS', 'ttl' => 60, 'records' => array(array('content' => 'invalid.', 'disabled' => false)))));
	$j = powerdns_api($url.'/zones', true, 'POST', json_encode($body, JSON_UNESCAPED_SLASHES));
	if (($j['success'] ?? 0) != 1)
		return 'The test zone was not created: '.(is_string($j['error'] ?? null) ? $j['error'] : 'code '.$j['reason']);
	route_log("dns cluster: test zone $z created");
	return '';
}

/* Change the test zone: its new serial, or 0. */
function dns_cluster_probe_bump($url) {
	$z = dns_cluster_probe_zone().'.';
	$patch = array('rrsets' => array(array('name' => $z, 'type' => 'TXT', 'ttl' => 60, 'changetype' => 'REPLACE',
		'records' => array(array('content' => '"reqad cluster check '.date('c').'"', 'disabled' => false)))));
	$j = powerdns_api($url.'/zones/'.rawurlencode($z), true, 'PATCH', json_encode($patch, JSON_UNESCAPED_SLASHES));
	if (($j['success'] ?? 0) != 1)
		return 0;
	$j = powerdns_api($url.'/zones/'.rawurlencode($z).'?rrsets=false', true);
	return (int)($j['serial'] ?? 0);
}

/* ---- the run ---------------------------------------------------------------- */

/*
 * Check the cluster and keep the result. $wait: the most seconds to wait for
 * the test change to arrive. $registry: ask the registries now (else once an
 * hour). result data: the view (dns_cluster_health_view()).
 */
function dns_cluster_health_collect($wait = 45, $registry = false) {
	global $db;
	if (!dns_cluster_on())
		return result_ok('No DNS cluster to check.', array());
	$lock = dns_cluster_health_lock(90);
	if (!$lock)
		return result_error('Another check is still running.');
	$now = time();
	$h = dns_cluster_health_load();
	dns_provider_load($db);
	$url = get_server_url();
	$api = substr($url, 0, 6) !== 'Error:';
	$conf = dns_cluster_conf();

	/* this server */
	$prim = array('running' => $api && !empty($conf['active']), 'version' => (string)($conf['version'] ?? ''), 'uptime' => 0, 'error' => $api ? '' : substr($url, 7));
	$q = $sf = null;
	if ($api) {
		foreach ((array)powerdns_api($url.'/statistics', true) as $st)
			if (is_array($st) && isset($st['name'], $st['value']) && !is_array($st['value'])) {
				if ($st['name'] === 'uptime')
					$prim['uptime'] = (int)$st['value'];
				elseif (in_array($st['name'], array('udp-queries', 'tcp-queries'), true))
					$q = (int)$q + (int)$st['value'];
				elseif ($st['name'] === 'servfail-packets')
					$sf = (int)$st['value'];
			}
		if ($q !== null)
			$h['primary'][] = array($now, $q, (int)$sf, $prim['uptime']);
	}
	$h['primary_now'] = $prim;

	/* the test change */
	$probe_err = $api ? dns_cluster_probe_ensure($url) : 'PowerDNS is not answering.';
	$probe = $probe_err === '' ? dns_cluster_probe_bump($url) : 0;
	$t_bump = microtime(true);
	$pz = dns_cluster_probe_zone();

	$sec = dns_cluster_secondaries();
	$zones = array();
	if ($api)
		foreach (powerdns_api($url.'/zones', true) as $z)
			if (is_array($z) && isset($z['name']) && in_array($z['kind'] ?? '', array('Native', 'Master'), true) && !dns_cluster_hidden_zone($z['name']))
				$zones[] = rtrim($z['name'], '.');
	sort($zones);

	/* each secondary: the quick checks */
	$own = dns_cluster_own_ips();
	$det = array();
	foreach ($sec as $x) {
		$a = dns_cluster_addr($x['addr']);
		$d = array('udp_ms' => null, 'tcp_ms' => null, 'axfr' => 'unknown', 'open' => 'unknown', 'ns' => 'none', 'delay' => null, 'probe' => 'none');
		if ($a) {
			$u = dns_wire_query($a[0], $a[1], dns_cluster_catalog(), 'SOA', array('timeout' => 3));
			$d['udp_ms'] = $u ? $u['ms'] : null;
			$t = dns_wire_query($a[0], $a[1], dns_cluster_catalog(), 'SOA', array('timeout' => 3, 'tcp' => true));
			$d['tcp_ms'] = $t ? $t['ms'] : null;
			if ($zones) {
				$x2 = dns_wire_query($a[0], $a[1], $zones[0], 'AXFR', array('timeout' => 5, 'tcp' => true));
				$got = false;
				foreach ($x2 ? $x2['answer'] : array() as $rr)
					if ($rr[1] === 6)
						$got = true;
				$d['axfr'] = $got && $x2['rcode'] === 0 ? 'allowed' : ($x2 || $t ? 'refused' : 'unknown');
			}
			$o = dns_wire_query($a[0], $a[1], 'example.com', 'A', array('rd' => true, 'timeout' => 3));
			$d['open'] = !$o ? 'unknown' : ($o['rcode'] === 0 && $o['answer'] ? 'yes' : 'no');
			$ips = array();
			foreach (array(DNS_A, DNS_AAAA) as $ty)
				foreach ((array)@dns_get_record($x['name'].'.', $ty) as $r)
					$ips[] = strtolower($r['ip'] ?? $r['ipv6'] ?? '');
			$d['ns'] = !array_filter($ips) ? 'none' : (in_array($a[0], $ips, true) ? 'ok' : 'other');
			$d['ns_ips'] = array_values(array_filter(array_unique($ips)));
		}
		$det[$x['name']] = $d;
	}

	/* the test change arriving */
	if ($probe) {
		$pending = array();
		foreach ($sec as $x)
			if (($a = dns_cluster_addr($x['addr'])) && $det[$x['name']]['udp_ms'] !== null)
				$pending[$x['name']] = $a;
		$end = $t_bump + max(5, (int)$wait);
		while ($pending && microtime(true) < $end) {
			foreach ($pending as $n => $a) {
				$s = dns_wire_serial($a[0], $a[1], $pz, array('timeout' => 1));
				if ($s !== null)
					$det[$n]['probe'] = 'old';
				if ($s !== null && $s >= $probe) {
					$det[$n]['delay'] = max(0, (int)round(microtime(true) - $t_bump));
					$det[$n]['probe'] = 'ok';
					unset($pending[$n]);
				}
			}
			if ($pending)
				usleep(1000000);
		}
	}

	/* every zone's serial, after the wait (a change made just before has arrived by now) */
	$check = array();
	foreach (dns_cluster_check() as $c)
		$check[$c['name']] = $c;

	$keep = $now - 7 * 86400;
	foreach ($sec as $x) {
		$n = $x['name'];
		$c = $check[$n] ?? array('answering' => false, 'in_step' => 0, 'total' => count($zones), 'behind' => array(), 'missing' => array());
		$d = $det[$n];
		$answering = $c['answering'] || $d['udp_ms'] !== null;
		/* a test change that never came: behind (the first one may still be on its way while the catalog brings the zone) */
		$late = $probe && $d['probe'] === 'old';
		$state = !$answering ? 'd' : ($c['behind'] || $c['missing'] || $late ? 'b' : 'o');
		$prev = $h['last'][$n] ?? null;
		$prev_state = $prev['state'] ?? '';
		$since = $state === 'o' ? 0 : ($prev_state === $state && !empty($prev['since']) ? (int)$prev['since'] : $now);
		if ($prev_state !== '' && $prev_state !== $state) {
			if ($state === 'o') {
				$was = !empty($prev['since']) ? (int)$prev['since'] : $now;
				$h['events'][] = array($now, $n, 'ok', "$n in step again (".($prev_state === 'd' ? 'not answering' : 'behind').' for '.dns_cluster_health_ago($now - $was).')');
			} elseif ($state === 'b')
				$h['events'][] = array($now, $n, 'behind', "$n behind: ".dns_cluster_health_list(array_merge(array_keys($c['behind']), $c['missing']), $late));
			else
				$h['events'][] = array($now, $n, 'down', "$n not answering on port 53");
		}
		$h['samples'][$n][] = array($now, $state, (int)$c['in_step'], (int)$c['total'], $d['udp_ms'], $d['tcp_ms'], $d['delay']);
		$h['samples'][$n] = array_values(array_filter($h['samples'][$n], function ($s) use ($keep) { return $s[0] >= $keep; }));
		$h['last'][$n] = $d + array('state' => $state, 'since' => $since, 'checked' => $now, 'addr' => $x['addr'],
			'in_step' => (int)$c['in_step'], 'total' => (int)$c['total'],
			'behind' => array_slice(array_keys($c['behind']), 0, 20), 'missing' => array_slice($c['missing'], 0, 20), 'late' => $late);
	}
	/* secondaries no longer on the list */
	foreach (array_keys($h['last']) as $n)
		if (!in_array($n, array_column($sec, 'name'), true))
			unset($h['last'][$n], $h['samples'][$n]);

	$h['primary'] = array_values(array_filter($h['primary'], function ($s) use ($keep) { return $s[0] >= $keep; }));
	$h['events'] = array_slice(array_values(array_filter($h['events'], function ($e) use ($keep) { return $e[0] >= $keep; })), -300);
	$h['probe_error'] = $probe_err;
	$h['ns'] = array();
	foreach (dns_cluster_ns_targets() as $nsn => $t)
		$h['ns'][] = array('name' => $nsn, 'ips' => $t[0], 'who' => $t[1]);
	$h['out'] = $api ? dns_cluster_zones_out() : array();

	/* the registries, once an hour */
	if ($registry || $now - (int)($h['delegation']['checked'] ?? 0) >= 3600) {
		$cache = $dz = array();
		foreach (array_slice($zones, 0, 500) as $z)
			$dz[$z] = dns_delegation($z, $cache);
		$h['delegation'] = array('checked' => $now, 'zones' => $dz);
	}

	$h['checked'] = $now;
	dns_cluster_health_save($h);
	dns_cluster_health_unlock($lock);
	return result_ok('Checked.', dns_cluster_health_view($h));
}

function dns_cluster_health_ago($s) {
	$s = max(0, (int)$s);
	if ($s < 90)
		return $s.' s';
	if ($s < 5400)
		return round($s / 60).' min';
	if ($s < 172800)
		return round($s / 3600).' h';
	return round($s / 86400).' days';
}

function dns_cluster_health_list(array $zones, $late) {
	$t = $zones ? implode(', ', array_slice($zones, 0, 3)).(count($zones) > 3 ? ' and '.(count($zones) - 3).' more' : '') : '';
	if ($late)
		$t .= ($t !== '' ? '; ' : '').'the test change did not arrive';
	return $t;
}

/* ---- what the page and the health check show -------------------------------- */

/*
 * The kept results for the page: checked, stale (no run for 20 min), overall
 * ('ok' | 'bad' | 'unknown'), problem (text), primary, secondaries (each with
 * its newest details, a 24-hour bar of 48 half hours, in-step share over 7
 * days, slowest test change), ns, out, delegation summary, events (newest first).
 */
function dns_cluster_health_view(array $h = null) {
	$h = $h ?? dns_cluster_health_load();
	$now = time();
	$sec = dns_cluster_secondaries();
	$v = array('checked' => $h['checked'], 'stale' => !$h['checked'] || $now - $h['checked'] > 1200, 'secondaries' => array(),
	           'ns' => $h['ns'], 'out' => $h['out'], 'probe_error' => (string)($h['probe_error'] ?? ''));

	/* this server */
	$p = $h['primary_now'] ?? array();
	$v['primary'] = array('running' => !empty($p['running']), 'version' => (string)($p['version'] ?? ''), 'uptime' => (int)($p['uptime'] ?? 0),
	                      'error' => (string)($p['error'] ?? ''), 'queries_hour' => null, 'servfail_hour' => null);
	$ps = $h['primary'];
	if (count($ps) >= 2) {
		$new = end($ps);
		$old = null;
		foreach ($ps as $s)
			if ($s[0] <= $new[0] - 3300) $old = $s;
		if ($old && $new[1] >= $old[1] && $new[3] >= $old[3]) {
			$f = 3600 / max(1, $new[0] - $old[0]);
			$v['primary']['queries_hour'] = (int)round(($new[1] - $old[1]) * $f);
			$v['primary']['servfail_hour'] = (int)round(($new[2] - $old[2]) * $f);
		}
	}

	$bad = array();
	foreach ($sec as $x) {
		$n = $x['name'];
		$l = $h['last'][$n] ?? null;
		$ss = $h['samples'][$n] ?? array();
		$bars = array_fill(0, 48, '');
		$start = $now - 86400;
		$rank = array('' => 0, 'o' => 1, 'b' => 2, 'd' => 3);
		$ok = $all = 0;
		$slow = null;
		foreach ($ss as $s) {
			$all++;
			if ($s[1] === 'o')
				$ok++;
			if ($s[6] !== null && ($slow === null || $s[6] > $slow))
				$slow = (int)$s[6];
			if ($s[0] >= $start) {
				$i = min(47, (int)floor(($s[0] - $start) / 1800));
				if ($rank[$s[1]] > $rank[$bars[$i]])
					$bars[$i] = $s[1];
			}
		}
		$state = $l['state'] ?? '';
		$v['secondaries'][] = array('name' => $n, 'addr' => $x['addr'], 'added' => (int)($x['added'] ?? 0), 'last' => $l, 'state' => $state,
		                            'bars' => $bars, 'pct' => $all ? round($ok * 100 / $all, 1) : null, 'slowest' => $slow);
		if ($state === 'd')
			$bad[] = "$n is not answering";
		elseif ($state === 'b')
			$bad[] = "$n is behind";
		elseif ($state === '')
			$bad[] = "$n is not checked yet";
	}
	if (!$v['primary']['running'] && $h['checked'])
		array_unshift($bad, 'PowerDNS on this server is not answering');
	if ($h['checked'] && $v['stale'])
		$bad[] = 'the check has not run since '.date('Y-m-d H:i', $h['checked']);
	$v['overall'] = !$h['checked'] ? 'unknown' : ($bad ? 'bad' : 'ok');
	$v['problem'] = $bad ? implode('; ', $bad).'.' : '';
	$v['answering'] = count(array_filter($v['secondaries'], function ($s) { return in_array($s['state'], array('o', 'b'), true); }));
	$v['in_step_all'] = $v['secondaries'] && !array_filter($v['secondaries'], function ($s) { return $s['state'] !== 'o'; });
	$v['zones'] = $v['secondaries'] ? (int)($v['secondaries'][0]['last']['total'] ?? 0) : 0;
	$v['in_step_min'] = $v['secondaries'] ? min(array_map(function ($s) { return (int)($s['last']['in_step'] ?? 0); }, $v['secondaries'])) : 0;
	$delays = array_filter(array_map(function ($s) { return $s['last']['delay'] ?? null; }, $v['secondaries']), function ($d) { return $d !== null; });
	$v['delay'] = $delays ? max($delays) : null;

	/* problems in the last 7 days */
	$last_bad = 0;
	foreach ($h['events'] as $e)
		if (in_array($e[2], array('behind', 'down'), true))
			$last_bad = max($last_bad, $e[0]);
	$v['last_problem'] = $last_bad;

	/* the registries */
	$exp = dns_expected_nameservers();
	$dl = array('checked' => (int)($h['delegation']['checked'] ?? 0), 'total' => 0, 'ours' => 0, 'rows' => array());
	foreach ((array)($h['delegation']['zones'] ?? array()) as $z => $r) {
		$dl['total']++;
		list($st, $names) = $r;
		$names = array_map(function ($n) { return rtrim(strtolower($n), '.'); }, (array)$names);
		$have = array_intersect($exp, $names);
		if ($st === 'ok' && $exp && count($have) === count($exp) && count($names) === count($exp)) {
			$dl['ours']++;
			continue;
		}
		if ($st === 'ok' && $have)
			$kind = count($have) < count($exp) ? 'partial' : 'extra';
		else
			$kind = $st === 'ok' ? 'other' : $st;
		$dl['rows'][] = array('zone' => $z, 'kind' => $kind, 'names' => $names, 'missing' => array_values(array_diff($exp, $names)));
	}
	$v['delegation'] = $dl;
	$v['expected'] = $exp;
	$v['events'] = array_reverse($h['events']);
	return $v;
}

/* The Cluster tab's dot: 'ok', 'bad', or '' (no cluster / not checked yet). */
function dns_cluster_health_dot() {
	if (!dns_cluster_on())
		return '';
	$h = dns_cluster_health_load();
	if (!$h['checked'])
		return '';
	if (time() - $h['checked'] > 1200)
		return 'bad';
	foreach (dns_cluster_secondaries() as $x)
		if (($h['last'][$x['name']]['state'] ?? '') !== 'o')
			return 'bad';
	return !empty($h['primary_now']['running']) ? 'ok' : 'bad';
}
