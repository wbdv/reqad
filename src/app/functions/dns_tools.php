<?php
/*
 * DNS tools for the server's admin: 2087 DNS Zones (templates/dns.php +
 * dist/js/dns-zone.js, handler modules/dns_admin.php). The zone list, new
 * and removed zones, the zone check (common mistakes, each with a fix where
 * the fix is clear), hints while a record is typed, and zone files in
 * (import) and out (export).
 *
 * Records change only through dns.php (dns_record_add / edit / delete,
 * dns_zone_put), so its rules hold here too; the 2087 handler turns on
 * dns_admin_mode() first. A check item is {id, level (error | warn | info),
 * code, name, text, refs [[fqdn, type, value]], fix null | {label, ops}};
 * an op is {op: add | edit | delete, rec | old + new: {name (full), type,
 * value, ttl}}, applied in order by dns_zone_fix() after the check runs
 * again (so a fix is only made while its problem is still there).
 */

/* Every zone at the provider: {zone, account (its owner, '' = none), serial, kind, status}. */
function dns_zone_list() {
	global $db, $dns_provider_name;
	if (!dns_managed())
		return result_error('No DNS provider is set up on this server. Choose one under Settings > DNS Settings.');
	dns_provider_load($db);
	$all = get_zones();
	if (!is_array($all))
		return result_error('The DNS provider is not answering: '.preg_replace('/^Error:\s*/', '', (string)$all));
	$own = dns_owner_map();
	$keys = dns_key_zone_map();
	$out = array();
	foreach ($all as $z => $info) {
		$z = strtolower((string)$z);
		if (in_array($info['kind'] ?? '', array('Producer', 'Consumer'), true))
			continue;     /* the DNS cluster's catalog, not a zone of names */
		$out[] = array('zone' => $z, 'account' => (string)($own[$z] ?? ''), 'key' => (string)($keys[$z]['name'] ?? ''), 'serial' => (string)($info['serial'] ?? ''),
		               'kind' => (string)($info['kind'] ?? ''), 'status' => (string)($info['status'] ?? ''));
	}
	usort($out, function ($a, $b) { return strcmp($a['zone'], $b['zone']); });
	return result_ok('', array('provider' => (string)$dns_provider_name, 'zones' => $out));
}

/* The DNS zones this server's sites need (`reqad dns zones`), for moving its
   DNS to another server: what to import there and list under "Zones already
   here" for this server's DNS API key. Every name of dns_owner_map() (main,
   addon and alias domains, mail domains) goes under the zone of the DNS
   provider that holds it, else under the shortest of those names it is
   inside (shop.example.com -> example.com), else its own name. $all adds the
   provider's zones no site uses. Returns {provider, url (PowerDNS server),
   error (the provider did not answer), zones: [{zone, accounts, names,
   there: the zone is at the DNS provider now}] sorted by zone}. */
function dns_zones_needed($all = false) {
	global $db, $settings;
	$prov = dns_provider_load($db);
	$have = function_exists('get_zones') ? get_zones() : array();
	$err = is_array($have) ? '' : preg_replace('/^Error:\s*/', '', (string)$have);
	$have = is_array($have) ? array_map('strtolower', array_map('strval', array_keys($have))) : array();
	$have = array_values(array_filter($have, function ($z) {
		return !function_exists('dns_cluster_hidden_zone') || !dns_cluster_hidden_zone($z);
	}));
	$in = function ($n, $z) { return $n === $z || substr($n, -strlen($z) - 1) === '.'.$z; };
	$names = array();
	foreach (dns_owner_map() as $n => $acct) {
		$n = strtolower(rtrim(trim((string)$n), '.'));
		if ($n !== '' && strpos($n, '.') !== false && strpos($n, '*') === false)
			$names[$n] = (string)$acct;
	}
	$out = array();
	foreach ($names as $n => $acct) {
		$zone = '';
		foreach ($have as $z)
			if ($in($n, $z) && strlen($z) > strlen($zone))
				$zone = $z;
		if ($zone === '') {
			$zone = $n;
			foreach ($names as $m => $_)
				if ($m !== $n && $in($n, $m) && strlen($m) < strlen($zone))
					$zone = $m;
		}
		if (!isset($out[$zone]))
			$out[$zone] = array('zone' => $zone, 'accounts' => array(), 'names' => array(), 'there' => in_array($zone, $have, true));
		$out[$zone]['names'][] = $n;
		if ($acct !== '' && !in_array($acct, $out[$zone]['accounts'], true))
			$out[$zone]['accounts'][] = $acct;
	}
	if ($all)
		foreach ($have as $z)
			$out += array($z => array('zone' => $z, 'accounts' => array(), 'names' => array(), 'there' => true));
	ksort($out);
	foreach ($out as &$r)
		sort($r['names']);
	unset($r);
	return array('provider' => $prov, 'url' => $prov === 'powerdns' ? (string)($settings['powerdns-server'] ?? '') : '',
	             'error' => $err, 'zones' => array_values($out));
}

/* The zone's owner account ('' = none). */
function dns_zone_owner($zone) {
	return (string)(dns_owner_map()[strtolower($zone)] ?? '');
}

/* A domain as typed -> lowercase ASCII without the final dot. */
function dns_zone_name($zone) {
	$zone = strtolower(rtrim(trim((string)$zone), '.'));
	if (preg_match('/[^\x20-\x7e]/', $zone) && function_exists('idn_to_ascii')) {
		$a = idn_to_ascii($zone, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
		if (is_string($a))
			$zone = $a;
	}
	return $zone;
}

/* A new zone with the provider's starting records (address $ip: the site,
   www, mail, MX). */
function dns_zone_create($zone, $ip = '') {
	global $db;
	$zone = dns_zone_name($zone);
	if (!valid_domain($zone) || strpos($zone, '.') === false)
		return result_error('Enter a domain name, such as example.com.');
	if (!dns_managed())
		return result_error('No DNS provider is set up on this server.');
	dns_provider_load($db);
	$all = get_zones();
	if (!is_array($all))
		return result_error('The DNS provider is not answering: '.preg_replace('/^Error:\s*/', '', (string)$all));
	if (isset($all[$zone]))
		return result_error("There already is a zone $zone.");
	$ip = trim((string)$ip);
	if ($ip === '')
		$ip = server_primary_ip();
	if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
		return result_error('The address of the site is an IPv4 address, such as 192.0.2.10.');
	$warn = array();
	if (($parent = dns_zone_for($zone, $all)) !== '')
		$warn[] = "$zone is inside the zone $parent: its names are now answered from the new zone, so records of them left in $parent no longer count.";
	$e = (string)add_domain_in_dns($zone, $ip);
	if ($e !== '')
		return result_error('The DNS provider did not create the zone: '.preg_replace('/^Error:\s*(DNS API:\s*)?/', '', $e));
	dns_key_zone_forget($zone);     /* a DNS API key held a zone of this name before */
	route_log("dns zone $zone created (site address $ip)");
	plugin_hook('dns_zone.created', array('zone' => $zone, 'ip' => $ip));
	return result_ok("Zone $zone created.", array('zone' => $zone), $warn);
}

/* Remove a zone and every record in it. */
function dns_zone_delete($zone) {
	global $db;
	$zone = dns_zone_name($zone);
	if (!dns_managed())
		return result_error('No DNS provider is set up on this server.');
	dns_provider_load($db);
	$all = get_zones();
	if (!is_array($all))
		return result_error('The DNS provider is not answering: '.preg_replace('/^Error:\s*/', '', (string)$all));
	if (!isset($all[$zone]))
		return result_error("There is no zone $zone.");
	$owner = dns_zone_owner($zone);
	$key = dns_key_zone_map()[$zone]['name'] ?? '';
	$e = (string)delete_domain_from_dns($zone);
	if ($e !== '')
		return result_error('The DNS provider did not remove the zone: '.preg_replace('/^Error:\s*(DNS API:\s*)?/', '', $e));
	dns_key_zone_forget($zone);
	route_log("dns zone $zone removed".($owner !== '' ? " (domain of $owner)" : '').($key !== '' ? " (zone of DNS API key $key)" : ''));
	plugin_hook('dns_zone.deleted', array('zone' => $zone, 'account' => $owner, 'key' => $key));
	return result_ok("Zone $zone removed.", array('zone' => $zone));
}

/* ---------- the zone check ---------- */

/* Does $name answer on the internet (A, AAAA or CNAME)? null = not asked
   (a check asks at most 12 names, so a page never waits long). */
function dns_tools_resolves($name) {
	static $seen = array(), $asked = 0;
	if (isset($seen[$name]))
		return $seen[$name];
	if (++$asked > 12 || !preg_match('/^[a-z0-9_.-]+\.[a-z0-9-]+$/', $name))
		return null;
	$r = @dns_get_record($name.'.', DNS_A);
	if (!$r)
		$r = @dns_get_record($name.'.', DNS_CNAME);
	if (!$r)
		$r = @dns_get_record($name.'.', DNS_AAAA);
	return $seen[$name] = !empty($r);
}

/* The IPv4 addresses $name has on the internet (at most 4). */
function dns_tools_addresses($name) {
	$out = array();
	foreach ((array)@dns_get_record(rtrim($name, '.').'.', DNS_A) as $r)
		if (!empty($r['ip']) && count($out) < 4)
			$out[] = $r['ip'];
	return array_values(array_unique($out));
}

/* The name meant when the final dot was left out while typing $host: the
   zone twice ("www.example.com.example.com"), or with $net, another domain
   in front of the zone ("ghs.googlehosted.com.example.com") when that name
   answers on the internet and the long one has no records here. '' = fine. */
function dns_missing_dot_fix($zone, $host, array $at = array(), $net = false) {
	$sfx = '.'.$zone;
	if (strlen($host) <= strlen($sfx) || substr($host, -strlen($sfx)) !== $sfx)
		return '';
	$pre = substr($host, 0, -strlen($sfx));
	if ($pre === $zone || substr($pre, -strlen($sfx)) === $sfx)
		return $pre;
	if (!$net || isset($at[$host]) || substr_count($pre, '.') < 1 || $pre[0] === '_' || !preg_match('/\.[a-z]{2,24}$/', $pre))
		return '';
	return dns_tools_resolves($pre) ? $pre : '';
}

/* The host name inside a value: [index of it in dns_value_parts, host]; null when the type has none. */
function dns_value_host($type, $value) {
	if (in_array($type, array('CNAME', 'NS', 'PTR'), true))
		return array(0, dns_host_canon($value));
	if ($type === 'MX' || $type === 'SRV') {
		$p = dns_value_parts($type, $value);
		return $p === null ? null : array($type === 'MX' ? 1 : 3, $p[$type === 'MX' ? 1 : 3]);
	}
	return null;
}

/* $value with its host name replaced. */
function dns_value_with_host($type, $value, $host) {
	if (in_array($type, array('CNAME', 'NS', 'PTR'), true))
		return $host;
	$p = dns_value_parts($type, $value);
	$p[$type === 'MX' ? 1 : 3] = $host;
	return dns_value_join($type, $p);
}

/* SPF terms of a record (after v=spf1). */
function dns_spf_terms($v) {
	return preg_split('/\s+/', trim(preg_replace('/^v=spf1\b/i', '', trim($v))), -1, PREG_SPLIT_NO_EMPTY);
}

/* Several SPF records -> one (their terms in order, the first one's all);
   '' when one redirects (not merged by hand-off). */
function dns_spf_merge(array $spfs) {
	$terms = array();
	$all = '';
	foreach ($spfs as $s)
		foreach (dns_spf_terms($s) as $t) {
			if (stripos($t, 'redirect=') === 0)
				return '';
			if (preg_match('/^[-~?+]?all$/i', $t)) {
				if ($all === '')
					$all = strtolower($t);
				continue;
			}
			$terms[strtolower($t)] = $t;
		}
	return trim('v=spf1 '.implode(' ', $terms).' '.($all !== '' ? $all : '~all'));
}

/* DNS lookups an SPF record makes itself (includes not followed). */
function dns_spf_lookup_count($v) {
	$n = 0;
	foreach (dns_spf_terms($v) as $t)
		if (preg_match('/^[-~?+]?(include:|a\b|mx\b|ptr\b|exists:|redirect=)/i', $t))
			$n++;
	return $n;
}

/*
 * The problems of a zone: list of check items (comment at the top). $sets =
 * dns_zone_sets($zone). $account: the zone's owner ('' = none), for the
 * address a fix gives a site. $net: also ask the internet (the name servers
 * the domain is registered with, names meant without the final dot) - off
 * for the zone list, which checks many zones at once.
 */
function dns_zone_check($zone, array $sets, $account = '', $net = true) {
	$items = array();
	$add = function ($level, $code, $name, $text, array $refs = array(), $fix = null) use (&$items) {
		$items[] = array('id' => substr(sha1($code.'|'.$name.'|'.json_encode($refs)), 0, 12), 'level' => $level, 'code' => $code,
		                 'name' => $name, 'text' => $text, 'refs' => $refs, 'fix' => $fix);
	};
	$rec = function ($name, $type, $value, $ttl) { return array('name' => $name, 'type' => $type, 'value' => $value, 'ttl' => (int)$ttl); };
	$at = $ttl = array();
	foreach ($sets as $s) {
		$at[$s['name']][$s['type']] = $s['values'];
		$ttl[$s['name'].'|'.$s['type']] = (int)$s['ttl'];
	}
	$inzone = function ($h) use ($zone) { return $h === $zone || substr($h, -strlen($zone) - 1) === '.'.$zone; };
	/* a name has records itself, or a wildcard of its parent covers it */
	$exists = function ($h) use (&$at, $zone) {
		if (isset($at[$h]))
			return true;
		for ($p = $h; strpos($p, '.') !== false && $p !== $zone; ) {
			$p = substr($p, strpos($p, '.') + 1);
			if (isset($at['*.'.$p]))
				return true;
		}
		return false;
	};
	$site_ip = $account !== '' ? domain_site_ip($account) : server_primary_ip();
	$show = function ($n) use ($zone) { return $n === $zone ? $zone : $n; };

	foreach ($sets as $s) {
		$n = $s['name'];
		$t = $s['type'];
		$tl = (int)$s['ttl'];
		/* the name typed with the domain but without the final dot */
		if (($short = dns_missing_dot_fix($zone, $n)) !== '') {
			$ops = array();
			foreach ($s['values'] as $v)
				$ops[] = array('op' => 'edit', 'old' => $rec($n, $t, $v, $tl), 'new' => $rec($short, $t, $v, $tl));
			$add('error', 'name_twice', $n, "The name $n ends with $zone twice: it was typed with the domain but without the final dot. It is meant to be $short.",
			     array_map(function ($v) use ($n, $t) { return array($n, $t, $v); }, $s['values']), array('label' => "Rename to $short", 'ops' => $ops));
		}
		foreach ($s['values'] as $v) {
			$ref = array(array($n, $t, $v));
			$hp = dns_value_host($t, $v);
			if ($hp !== null && $hp[1] !== '.' && $hp[1] !== '') {
				$h = $hp[1];
				$what = array('CNAME' => 'The alias', 'MX' => 'The mail server', 'SRV' => 'The server', 'NS' => 'The name server', 'PTR' => 'The host name')[$t] ?? 'The name';
				if (filter_var($h, FILTER_VALIDATE_IP)) {
					if ($t === 'CNAME' && filter_var($h, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && count($at[$n]) === 1)
						$add('error', 'host_ip', $n, "$n is a CNAME to the address $h, but a CNAME points to a name. An address goes in an A record.", $ref,
						     array('label' => "Make it an A record ($h)", 'ops' => array(array('op' => 'delete', 'rec' => $rec($n, $t, $v, $tl)), array('op' => 'add', 'rec' => $rec($n, 'A', $h, $tl)))));
					else {
						$fix = null;
						if ($t === 'MX') {
							$named = '';
							foreach ($at as $nm => $ty)
								if (in_array($h, $ty['A'] ?? array(), true) && !isset($ty['CNAME'])) { $named = $nm; break; }
							if ($named !== '')
								$fix = array('label' => "Use the name $named", 'ops' => array(array('op' => 'edit', 'old' => $rec($n, $t, $v, $tl), 'new' => $rec($n, $t, dns_value_with_host($t, $v, $named), $tl))));
							elseif (!isset($at['mail.'.$zone]) && filter_var($h, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
								$fix = array('label' => "Name it mail.$zone", 'ops' => array(array('op' => 'add', 'rec' => $rec('mail.'.$zone, 'A', $h, $tl)),
								             array('op' => 'edit', 'old' => $rec($n, $t, $v, $tl), 'new' => $rec($n, $t, dns_value_with_host($t, $v, 'mail.'.$zone), $tl))));
						}
						$add('error', 'host_ip', $n, "$what of {$show($n)} is the address $h, but it must be a name (with an A record of its own).", $ref, $fix);
					}
					continue;
				}
				if (strpos($h, '.') === false) {
					$add('error', 'host_label', $n, "$what of {$show($n)} is \"$h\", a name without a domain. It is probably meant to be $h.$zone.", $ref,
					     array('label' => "Use $h.$zone", 'ops' => array(array('op' => 'edit', 'old' => $rec($n, $t, $v, $tl), 'new' => $rec($n, $t, dns_value_with_host($t, $v, $h.'.'.$zone), $tl)))));
					continue;
				}
				if (($meant = dns_missing_dot_fix($zone, $h, $at, $net)) !== '') {
					$twice = substr($h, -2 * strlen($zone) - 1) === $zone.'.'.$zone;
					$add($twice ? 'error' : 'warn', 'host_dot', $n, "$what of {$show($n)} is $h: the final dot was left out when it was typed, so the domain was added to it. It is meant to be $meant.", $ref,
					     array('label' => "Use $meant", 'ops' => array(array('op' => 'edit', 'old' => $rec($n, $t, $v, $tl), 'new' => $rec($n, $t, dns_value_with_host($t, $v, $meant), $tl)))));
					continue;
				}
				if ($inzone($h) && $t !== 'PTR') {
					$cn = $at[$h]['CNAME'][0] ?? '';
					if ($cn !== '' && $t !== 'CNAME')
						$add('warn', 'host_cname', $n, "$what of {$show($n)} is $h, which is an alias (CNAME) of $cn. Mail and name servers must not be aliases: some servers refuse them.", $ref,
						     array('label' => "Use $cn", 'ops' => array(array('op' => 'edit', 'old' => $rec($n, $t, $v, $tl), 'new' => $rec($n, $t, dns_value_with_host($t, $v, $cn), $tl)))));
					elseif (!$exists($h)) {
						$fix = in_array($t, array('MX', 'NS'), true) && $site_ip !== ''
							? array('label' => "Add an A record for $h ($site_ip)", 'ops' => array(array('op' => 'add', 'rec' => $rec($h, 'A', $site_ip, $tl)))) : null;
						$add('warn', 'host_missing', $n, "$what of {$show($n)} is $h, which has no records in this zone: it leads nowhere.", $ref, $fix);
					}
				}
			}
			if ($t === 'A' && !filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) && strpos($n, 'localhost.') !== 0)
				$add('warn', 'a_private', $n, "{$show($n)} points to $v, a private or reserved address that nobody on the internet can reach.", $ref);
			if ($t === 'TXT') {
				$lv = strtolower(ltrim($v));
				if (strlen($v) > 1 && $v[0] === '"' && substr($v, -1) === '"')
					$add('warn', 'txt_quotes', $n, "The text of {$show($n)} has quotes inside it (it was pasted with them), so services read them as part of it.", $ref,
					     array('label' => 'Take the quotes off', 'ops' => array(array('op' => 'edit', 'old' => $rec($n, $t, $v, $tl), 'new' => $rec($n, $t, dns_txt_decode($v), $tl)))));
				if (strpos($lv, 'v=spf1') === 0) {
					if (preg_match('/(^|\s)\+?all$/i', trim($v)) && !preg_match('/[-~?]all$/i', trim($v)))
						$add('warn', 'spf_all', $n, "The SPF record of {$show($n)} ends with +all: every server on the internet may send mail as it.", $ref,
						     array('label' => 'Use ~all', 'ops' => array(array('op' => 'edit', 'old' => $rec($n, $t, $v, $tl), 'new' => $rec($n, $t, preg_replace('/\+?all$/i', '~all', trim($v)), $tl)))));
					elseif (!preg_match('/(^|\s)([-~?+]?all|redirect=\S+)$/i', trim($v)))
						$add('info', 'spf_noall', $n, "The SPF record of {$show($n)} does not end with an all term, so mail from other servers gets no verdict.", $ref,
						     array('label' => 'Add ~all', 'ops' => array(array('op' => 'edit', 'old' => $rec($n, $t, $v, $tl), 'new' => $rec($n, $t, trim($v).' ~all', $tl)))));
					if (($lk = dns_spf_lookup_count($v)) > 10)
						$add('warn', 'spf_lookups', $n, "The SPF record of {$show($n)} makes $lk DNS lookups; receivers allow 10, so it fails for them.", $ref);
				}
				/* <domain>._report._dmarc.<zone> lets another domain send its reports here (RFC 7489 7.1) */
				if (strpos($lv, 'v=dmarc1') === 0 && strpos($n, '_dmarc.') !== 0 && strpos($n, '._report._dmarc.') === false) {
					$to = '_dmarc.'.$n;
					$fix = empty(array_filter($at[$to]['TXT'] ?? array(), function ($x) { return stripos(ltrim($x), 'v=dmarc1') === 0; }))
						? array('label' => "Move it to $to", 'ops' => array(array('op' => 'edit', 'old' => $rec($n, $t, $v, $tl), 'new' => $rec($to, $t, $v, $tl)))) : null;
					$add('warn', 'dmarc_name', $n, "A DMARC record is at {$show($n)}, but receivers read it only at $to.", $ref, $fix);
				}
				if (strpos($lv, 'v=dkim1') === 0 && strpos($n, '._domainkey.') === false)
					$add('warn', 'dkim_name', $n, "A DKIM key is at {$show($n)}, but receivers look for it at <selector>._domainkey.<domain>, such as default._domainkey.$zone.", $ref);
			}
		}
		if ($t === 'CNAME') {
			if ($n === $zone) {
				$ips = $net ? dns_tools_addresses($s['values'][0]) : array();
				$ops = array(array('op' => 'delete', 'rec' => $rec($n, 'CNAME', $s['values'][0], $tl)));
				foreach ($ips as $ip)
					$ops[] = array('op' => 'add', 'rec' => $rec($n, 'A', $ip, $tl));
				$add('error', 'cname_apex', $n, "The domain itself is an alias (CNAME) of {$s['values'][0]}. That is not allowed at the top of a zone: its mail and name servers then stop working.",
				     array(array($n, 'CNAME', $s['values'][0])), $ips ? array('label' => 'Make it A record'.(count($ips) > 1 ? 's' : '').' ('.implode(', ', $ips).')', 'ops' => $ops) : null);
			} elseif (count($at[$n]) > 1) {
				$others = array_diff(array_keys($at[$n]), array('CNAME'));
				$add('error', 'cname_mix', $n, "$n is an alias (CNAME) and also has ".implode(', ', $others)." records. A CNAME must stand alone: resolvers see either one or the other. Remove the CNAME or the other records.",
				     array(array($n, 'CNAME', $s['values'][0])));
			}
			if (count($s['values']) > 1) {
				$ops = array();
				foreach (array_slice($s['values'], 1) as $v)
					$ops[] = array('op' => 'delete', 'rec' => $rec($n, 'CNAME', $v, $tl));
				$add('error', 'cname_many', $n, "$n is an alias (CNAME) of ".count($s['values'])." names; a name can be an alias of only one.",
				     array_map(function ($v) use ($n) { return array($n, 'CNAME', $v); }, $s['values']), array('label' => 'Keep only '.$s['values'][0], 'ops' => $ops));
			}
		}
		if ($t === 'TXT') {
			$spfs = array_values(array_filter($s['values'], function ($x) { return stripos(ltrim($x), 'v=spf1') === 0; }));
			if (count($spfs) > 1) {
				$m = dns_spf_merge($spfs);
				$ops = array();
				if ($m !== '') {
					$ops[] = array('op' => 'edit', 'old' => $rec($n, 'TXT', $spfs[0], $tl), 'new' => $rec($n, 'TXT', $m, $tl));
					foreach (array_slice($spfs, 1) as $v)
						$ops[] = array('op' => 'delete', 'rec' => $rec($n, 'TXT', $v, $tl));
				}
				$add('error', 'spf_many', $n, "{$show($n)} has ".count($spfs)." SPF records. Receivers then fail SPF for all mail: everything must be in one record.",
				     array_map(function ($v) use ($n) { return array($n, 'TXT', $v); }, $spfs), $m !== '' ? array('label' => 'Merge them: '.$m, 'ops' => $ops) : null);
			}
			$dm = array_values(array_filter($s['values'], function ($x) { return stripos(ltrim($x), 'v=dmarc1') === 0; }));
			if (count($dm) > 1 && strpos($n, '_dmarc.') === 0)
				$add('error', 'dmarc_many', $n, "$n has ".count($dm).' DMARC records; receivers then ignore DMARC for the domain. Keep one.',
				     array_map(function ($v) use ($n) { return array($n, 'TXT', $v); }, $dm));
		}
		if ($t === 'SPF') {
			$txt = $at[$n]['TXT'] ?? array();
			$ops = array();
			foreach ($s['values'] as $v) {
				$v2 = dns_txt_decode($v);
				$ops[] = array('op' => 'delete', 'rec' => $rec($n, 'SPF', $v, $tl));
				if (!in_array($v2, $txt, true))
					$ops[] = array('op' => 'add', 'rec' => $rec($n, 'TXT', $v2, $tl));
			}
			$add('info', 'spf_type', $n, "{$show($n)} has a record of the old type SPF. Receivers read only the TXT record now.",
			     array_map(function ($v) use ($n) { return array($n, 'SPF', $v); }, $s['values']), array('label' => 'Keep it as a TXT record only', 'ops' => $ops));
		}
	}

	/* the top of the zone */
	$ns = $at[$zone]['NS'] ?? array();
	if (!$ns)
		$add('error', 'ns_none', $zone, "The zone has no name server (NS) records.");
	if (empty($at[$zone]['A']) && empty($at[$zone]['AAAA']) && empty($at[$zone]['CNAME']) && empty($at[$zone]['ALIAS']))
		$add('info', 'apex_noaddr', $zone, "$zone has no address (A record): visitors who leave out www get no website.", array(),
		     $site_ip !== '' && $account !== '' ? array('label' => "Add an A record ($site_ip)", 'ops' => array(array('op' => 'add', 'rec' => $rec($zone, 'A', $site_ip, 14400)))) : null);
	$caa = $at[$zone]['CAA'] ?? array();
	if ($caa) {
		$issue = array_filter($caa, function ($c) { return preg_match('/^\d+\s+issue(wild)?\s/i', $c); });
		$le = array_filter($issue, function ($c) { return stripos($c, 'letsencrypt.org') !== false; });
		if ($issue && !$le)
			$add('warn', 'caa_le', $zone, "The CAA records of $zone do not allow Let's Encrypt, so this server cannot get or renew certificates for it.",
			     array_map(function ($c) use ($zone) { return array($zone, 'CAA', $c); }, array_values($issue)),
			     array('label' => 'Allow Let\'s Encrypt', 'ops' => array(array('op' => 'add', 'rec' => $rec($zone, 'CAA', '0 issue "letsencrypt.org"', $ttl[$zone.'|CAA'] ?? 14400)))));
	}
	/* the name servers the zone names, against those it should have */
	$want = dns_expected_nameservers();
	if ($want && $ns) {
		$miss = array_values(array_diff($want, $ns));
		$extra = array_values(array_diff($ns, $want));
		if ($miss) {
			$tl = $ttl[$zone.'|NS'] ?? 86400;
			$ops = array();
			foreach ($miss as $m)
				$ops[] = array('op' => 'add', 'rec' => $rec($zone, 'NS', $m, $tl));
			foreach ($extra as $x)
				$ops[] = array('op' => 'delete', 'rec' => $rec($zone, 'NS', $x, $tl));
			/* the SOA names the first of them as the primary server */
			$soa = preg_split('/\s+/', trim((string)($at[$zone]['SOA'][0] ?? '')));
			if (count($soa) >= 7 && dns_host_canon($soa[0]) !== $want[0]) {
				$soa[0] = $want[0].'.';
				$ops[] = array('op' => 'soa', 'rec' => $rec($zone, 'SOA', implode(' ', $soa), $ttl[$zone.'|SOA'] ?? 14400));
			}
			$add('warn', 'ns_expected', $zone, 'The zone names the name servers '.implode(', ', $ns).', but this server\'s are '.implode(', ', $want).'.',
			     array_map(function ($x) use ($zone) { return array($zone, 'NS', $x); }, $extra),
			     in_array('NS', dns_editable_types(), true) ? array('label' => 'Use '.implode(', ', $want), 'ops' => $ops) : null);
		}
	}
	/* where the domain's registration sends the internet */
	if ($net && $ns) {
		$live = array();
		foreach ((array)@dns_get_record($zone.'.', DNS_NS) as $r)
			if (!empty($r['target']))
				$live[] = strtolower(rtrim($r['target'], '.'));
		$live = array_values(array_unique($live));
		if (!$live)
			$add('info', 'ns_live', $zone, "$zone does not answer on the internet yet (no name servers found for it): it may not be registered, or its registration points nowhere.");
		elseif (!array_intersect($live, $ns))
			$add('warn', 'ns_live', $zone, "The internet asks the name servers ".implode(', ', $live)." for $zone, not the ones of this zone (".implode(', ', $ns)."): changes here are not seen until the domain's name servers are changed where it is registered.");
	}
	$rank = array('error' => 0, 'warn' => 1, 'info' => 2);
	usort($items, function ($a, $b) use ($rank) { return $rank[$a['level']] - $rank[$b['level']] ?: strcmp($a['name'], $b['name']); });
	return $items;
}

/* The name servers this server's zones should name ([] = no rule known). */
function dns_expected_nameservers() {
	$ns = array();
	if (setting_get('dns-provider') === 'powerdns')
		foreach (array('powerdns-ns1', 'powerdns-ns2') as $k)
			if (($v = dns_host_canon(setting_get($k))) !== '')
				$ns[] = $v;
	return (array)plugin_filter('dns.nameservers', array_values(array_unique($ns)));
}

/* Record counts and problems of several zones (the zone list): zone =>
   {records, errors, warnings} or {error}. No questions to the internet. */
function dns_zone_summary(array $zones) {
	$out = array();
	foreach (array_slice(array_values(array_unique($zones)), 0, 100) as $z) {
		$z = dns_zone_name($z);
		$sets = dns_zone_sets($z);
		if (!is_array($sets)) {
			$out[$z] = array('error' => $sets);
			continue;
		}
		$n = 0;
		foreach ($sets as $s)
			if ($s['type'] !== 'SOA')
				$n += count($s['values']);
		$c = array('error' => 0, 'warn' => 0, 'info' => 0);
		foreach (dns_zone_check($z, $sets, dns_zone_owner($z), false) as $it)
			$c[$it['level']]++;
		$out[$z] = array('records' => $n, 'errors' => $c['error'], 'warnings' => $c['warn']);
	}
	return $out;
}

/* Make the fix of check item $id (the check runs again first). */
/* The SOA of $zone as fields: primary, contact (an address), serial,
   refresh, retry, expire, minimum, ttl - or null. */
function dns_zone_soa($zone) {
	$sets = dns_zone_sets($zone);
	if (!is_array($sets))
		return null;
	foreach ($sets as $s)
		if ($s['type'] === 'SOA' && $s['name'] === $zone && isset($s['values'][0])) {
			$f = preg_split('/\s+/', trim((string)$s['values'][0]));
			if (count($f) < 7)
				return null;
			/* the mailbox: its first unescaped dot is the @ */
			$rname = rtrim($f[1], '.');
			$contact = preg_match('/^((?:[^.\\\\]|\\\\.)+)\.(.+)$/', $rname, $m) ? str_replace('\\.', '.', $m[1]).'@'.$m[2] : $rname;
			return array('primary' => rtrim($f[0], '.'), 'contact' => $contact, 'serial' => (int)$f[2], 'refresh' => (int)$f[3],
			             'retry' => (int)$f[4], 'expire' => (int)$f[5], 'minimum' => (int)$f[6], 'ttl' => (int)$s['ttl']);
		}
	return null;
}

/*
 * Change the SOA of $zone (2087, PowerDNS): any of primary, contact,
 * refresh, retry, expire, minimum, and serial - a higher number, or '' for
 * one higher than now ("Raise serial": the secondaries fetch the zone
 * again). The serial never goes down: secondaries would keep the old copy.
 */
function dns_zone_soa_set($zone, array $f) {
	if (!dns_admin_mode() || setting_get('dns-provider') !== 'powerdns')
		return result_error('The SOA is changed here only with PowerDNS.');
	$zone = dns_zone_name($zone);
	$cur = dns_zone_soa($zone);
	if (!$cur)
		return result_error("The SOA of $zone could not be read.");
	$primary = dns_host_canon((string)($f['primary'] ?? $cur['primary']));
	if (!valid_domain($primary) || strpos($primary, '.') === false)
		return result_error('The primary server is a host name, such as ns1.example.com.');
	$contact = strtolower(trim((string)($f['contact'] ?? $cur['contact'])));
	if (!preg_match('/^([^@\s]+)@([a-z0-9.-]+)$/', $contact, $m) || !valid_domain($m[2]) || !preg_match('/^[a-z0-9._+-]+$/', $m[1]))
		return result_error('The contact is an e-mail address, such as hostmaster@'.$zone.'.');
	$rname = str_replace('.', '\\.', $m[1]).'.'.$m[2];
	$num = array();
	$lim = array('refresh' => array(300, 2419200, 'Refresh'), 'retry' => array(60, 2419200, 'Retry'),
	             'expire' => array(3600, 31536000, 'Expire'), 'minimum' => array(0, 86400, 'Negative TTL'));
	foreach ($lim as $k => $l) {
		$v = isset($f[$k]) && $f[$k] !== '' ? $f[$k] : $cur[$k];
		if (!is_numeric($v) || (int)$v != $v || $v < $l[0] || $v > $l[1])
			return result_error("{$l[2]} is a number of seconds from {$l[0]} to {$l[1]}.");
		$num[$k] = (int)$v;
	}
	if ($num['retry'] > $num['refresh'])
		return result_error('Retry is shorter than Refresh: it is how soon a secondary tries again after a failed refresh.');
	if ($num['expire'] <= $num['refresh'] + $num['retry'])
		return result_error('Expire is longer than Refresh and Retry together: it is how long a secondary keeps answering without reaching the primary.');
	$serial = trim((string)($f['serial'] ?? ''));
	if ($serial === '')
		$serial = $cur['serial'] + 1;
	elseif (!ctype_digit($serial) || (int)$serial <= $cur['serial'] || (int)$serial > 4294967295)
		return result_error("The serial is a number higher than now ({$cur['serial']}) and at most 4294967295: secondaries keep their copy while it is lower.");
	/* PowerDNS raises the serial itself after every change through its API
	   when the zone has SOA-EDIT-API (new zones: DEFAULT) - one less is
	   written then, so it ends on the number asked for */
	$url = get_server_url();
	$info = substr($url, 0, 6) !== 'Error:' ? powerdns_api($url.'/zones/'.rawurlencode($zone.'.').'?rrsets=false', true) : array();
	if ((string)($info['soa_edit_api'] ?? '') !== '')
		$serial = (int)$serial - 1;
	$content = "$primary. $rname. ".(int)$serial." {$num['refresh']} {$num['retry']} {$num['expire']} {$num['minimum']}";
	$e = (string)dns_zone_put($zone, $zone, 'SOA', $cur['ttl'] ?: 14400, array($content));
	if ($e !== '')
		return result_error(preg_replace('/^Error:\s*/', '', $e));
	$now = dns_zone_soa($zone);
	route_log("dns zone $zone: SOA saved (serial ".($now['serial'] ?? $serial).')');
	return result_ok("SOA of $zone saved. Serial: ".($now['serial'] ?? $serial).'.', array('soa' => $now));
}

function dns_zone_fix($account, $zone, $id) {
	$sets = dns_zone_sets($zone);
	if (!is_array($sets))
		return result_error($sets);
	$item = null;
	foreach (dns_zone_check($zone, $sets, $account !== '' ? $account : dns_zone_owner($zone)) as $it)
		if ($it['id'] === $id)
			$item = $it;
	if (!$item)
		return result_error('This problem is not there any more (the zone changed meanwhile). The list is read again.');
	if (empty($item['fix']['ops']))
		return result_error('There is no fix for this one to make by itself.');
	$done = array();
	foreach ($item['fix']['ops'] as $op) {
		if ($op['op'] === 'soa') {
			$e = (string)dns_zone_put($zone, $zone, 'SOA', (int)$op['rec']['ttl'], array($op['rec']['value']));
			$r = $e === '' ? result_ok('SOA of '.$zone.' saved.') : dns_put_error($e);
			if ($e === '')
				route_log("dns $zone: SOA primary server set to ".strtok($op['rec']['value'], ' '));
		} elseif ($op['op'] === 'add')
			$r = dns_record_add($account, $zone, $op['rec']);
		elseif ($op['op'] === 'edit')
			$r = dns_record_edit($account, $zone, $op['old'], $op['new']);
		else
			$r = dns_record_delete($account, $zone, $op['rec']);
		if (!$r['ok'])
			return result_error(($done ? 'Part of the fix was made ('.implode(' ', $done).'), then: ' : '').$r['error']);
		$done[] = $r['message'];
	}
	return result_ok('Fixed: '.lcfirst($item['fix']['label']).'.', array('done' => $done));
}

/* ---------- hints while a record is typed ---------- */

/* What a record as typed probably means: list of {level, text, fix
   ({type, name, value, ttl}: the form's new values) or null, label}. */
function dns_record_hints($zone, array $sets, array $r, array $old = null) {
	$type = strtoupper(trim((string)($r['type'] ?? '')));
	$name = trim((string)($r['name'] ?? ''));
	$value = trim((string)($r['value'] ?? ''));
	$out = array();
	$hint = function ($level, $text, $fix = null, $label = '') use (&$out) { $out[] = array('level' => $level, 'text' => $text, 'fix' => $fix, 'label' => $label); };
	$at = array();
	foreach ($sets as $s)
		$at[$s['name']][$s['type']] = $s['values'];

	/* a whole zone-file line in one field */
	foreach (array($value, $name) as $raw)
		if (preg_match('/^\S+\s+(\d+[smhdw]?\s+)?(IN\s+)?(A|AAAA|CNAME|MX|TXT|SRV|CAA|NS|PTR)\s+\S/i', $raw)) {
			$p = dns_zonefile_parse($zone, $raw);
			if (count($p['records']) === 1 && $p['records'][0]['status'] !== 'skip') {
				$x = $p['records'][0];
				$nm = $x['name'] === $zone ? '@' : substr($x['name'], 0, -strlen($zone) - 1);
				$hint('info', 'This looks like a whole line of a zone file.', array('type' => $x['type'], 'name' => $nm, 'value' => $x['value'], 'ttl' => $x['ttl']), 'Put it into the fields');
				return $out;
			}
		}

	list($fq) = dns_record_name($zone, $name);
	if ($fq !== '' && ($short = dns_missing_dot_fix($zone, $fq)) !== '')
		$hint('error', "The name ends with $zone twice. Type only the part before the domain, such as www, or the full name.",
		      array('name' => $short === $zone ? '@' : substr($short, 0, -strlen($zone) - 1)), 'Use '.$short);
	$apex = $fq === $zone;

	if ($type === 'A' || $type === 'AAAA') {
		$v = preg_replace('#^https?://([^/]+).*$#i', '$1', $value);
		if ($v !== $value && filter_var($v, FILTER_VALIDATE_IP))
			$hint('error', 'Only the address goes here, not a web address.', array('value' => $v), 'Use '.$v);
		elseif ($type === 'A' && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6))
			$hint('error', 'This is an IPv6 address: it goes in an AAAA record.', array('type' => 'AAAA'), 'Make it AAAA');
		elseif ($type === 'AAAA' && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
			$hint('error', 'This is an IPv4 address: it goes in an A record.', array('type' => 'A'), 'Make it A');
		elseif ($value !== '' && !filter_var($value, FILTER_VALIDATE_IP) && ($h = dns_host_ok($value)) !== '') {
			if (!$apex)
				$hint('error', "An A record holds an address. To make this name show what $h shows, use a CNAME.", array('type' => 'CNAME', 'value' => $h), 'Make it a CNAME');
			elseif ($ips = dns_tools_addresses($h))
				$hint('error', "An A record holds an address. $h has the address ".implode(', ', $ips).'.', array('value' => $ips[0]), 'Use '.$ips[0]);
		} elseif ($type === 'A' && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && !filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))
			$hint('warn', 'This is a private or reserved address: nobody on the internet can reach it.');
	}

	$hp = in_array($type, array('CNAME', 'NS', 'PTR', 'MX', 'SRV'), true) ? dns_value_host($type, $value) : null;
	if ($hp !== null && $hp[1] !== '' && $hp[1] !== '.') {
		$h = $hp[1];
		$use = function ($host) use ($type, $value) { return array('value' => dns_value_with_host($type, $value, $host)); };
		$h2 = preg_replace('#^[a-z]+://([^/:]+).*$#i', '$1', $h);
		if ($h2 !== $h)
			$hint('error', 'Only the name goes here, without http:// or a path.', $use(strtolower($h2)), 'Use '.strtolower($h2));
		elseif (filter_var($h, FILTER_VALIDATE_IP)) {
			if ($type === 'CNAME' && !$apex)
				$hint('error', 'A CNAME points to a name. An address goes in an A record.', array('type' => filter_var($h, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'AAAA' : 'A', 'value' => $h), 'Make it an A record');
			else {
				$named = '';
				foreach ($at as $nm => $ty)
					if (in_array($h, array_merge($ty['A'] ?? array(), $ty['AAAA'] ?? array()), true) && !isset($ty['CNAME'])) { $named = $nm; break; }
				$hint('error', 'This must be a name, not an address.'.($named === '' ? " Add an A record (such as mail.$zone) with $h and use that name here." : " $named has this address."),
				      $named !== '' ? $use($named) : null, $named !== '' ? 'Use '.$named : '');
			}
		} elseif ($h === '@')
			$hint('info', "@ stands for the domain itself: $zone.", $use($zone), 'Use '.$zone);
		elseif (strpos($h, '.') === false && preg_match('/^[a-z0-9_-]+$/', $h))
			$hint('error', "\"$h\" has no domain. Did you mean $h.$zone? (Or give the full name of another domain.)", $use($h.'.'.$zone), 'Use '.$h.'.'.$zone);
		elseif (($meant = dns_missing_dot_fix($zone, $h, $at, true)) !== '')
			$hint('error', "$h has the domain twice or added by mistake. It is meant to be $meant.", $use($meant), 'Use '.$meant);
		elseif ($h === $zone || substr($h, -strlen($zone) - 1) === '.'.$zone) {
			$cn = $at[$h]['CNAME'][0] ?? '';
			if ($cn !== '' && $type !== 'CNAME')
				$hint('warn', "$h is an alias (CNAME) of $cn. Mail and name servers must not be aliases: some servers refuse them.", $use($cn), 'Use '.$cn);
			elseif (!isset($at[$h]) && $h !== $fq && $type !== 'PTR')
				$hint('info', "$h has no records in this zone yet: add an A record for it too.");
		}
		if ($type === 'CNAME' && $apex && strpos($h, '.') !== false && !filter_var($h, FILTER_VALIDATE_IP) && ($ips = dns_tools_addresses($h)))
			$hint('error', "The domain itself cannot be an alias. $h has the address ".implode(', ', $ips).': give the domain an A record with it.', array('type' => 'A', 'value' => $ips[0]), 'Make it an A record');
		if ($type === 'NS' && $apex)
			$hint('info', 'Which name servers the internet asks is set where the domain is registered: change them there too.');
	}

	if ($type === 'TXT' && $value !== '') {
		$v = strlen($value) > 1 && $value[0] === '"' && substr($value, -1) === '"' ? dns_txt_decode($value) : $value;
		$lv = strtolower($v);
		if (strpos($lv, 'v=spf1') === 0 && $fq !== '') {
			/* the record being changed does not count */
			$was = $old && dns_record_name($zone, $old['name'] ?? '')[0] === $fq ? strtolower(trim((string)($old['value'] ?? ''))) : '';
			$others = array_filter($at[$fq]['TXT'] ?? array(), function ($x) use ($was) {
				return stripos(ltrim($x), 'v=spf1') === 0 && strtolower($x) !== $was; });
			if ($others)
				$hint('warn', "$fq already has an SPF record: two SPF records make both fail. Change that one instead, or put everything into one, such as: "
				      .dns_spf_merge(array_merge(array_values($others), array($v))));
			if (preg_match('/(^|\s)\+?all$/i', trim($v)) && !preg_match('/[-~?]all$/i', trim($v)))
				$hint('warn', '+all lets every server on the internet send mail as this domain.', array('value' => preg_replace('/\+?all$/i', '~all', trim($v))), 'Use ~all');
		}
		if (strpos($lv, 'v=dmarc1') === 0 && $fq !== '' && strpos($fq, '_dmarc.') !== 0 && strpos($fq, '._report._dmarc.') === false)
			$hint('error', "Receivers read DMARC only at _dmarc.$fq.", array('name' => $apex ? '_dmarc' : '_dmarc.'.substr($fq, 0, -strlen($zone) - 1)), 'Use _dmarc'.($apex ? '' : '.'.substr($fq, 0, -strlen($zone) - 1)));
		if (strpos($lv, 'v=dkim1') === 0 && $fq !== '' && strpos($fq, '._domainkey.') === false)
			$hint('warn', 'A DKIM key goes at <selector>._domainkey: the selector is the name the sender gave, such as default or google.');
		if (strlen($v) > 255)
			$hint('info', 'It is longer than 255 characters, so it is stored as '.ceil(strlen($v) / 255).' strings, as DNS needs. Receivers join them again.');
	}
	if ($type === 'SRV' && $fq !== '' && !preg_match('/^_[a-z0-9-]+\._(tcp|udp|tls|sctp)\./', $fq.'.') && preg_match('/^[a-z0-9-]+$/', $name))
		$hint('error', 'The name of an SRV record starts with the service and the protocol.', array('name' => '_'.$name.'._tcp'.''), 'Use _'.$name.'._tcp');
	return $out;
}

/* What a record as typed would be saved as: {name, type, ttl, value, line
   (the zone-file line), hints}, or an error with the hints in data. */
function dns_record_preview($account, $zone, array $r, array $old = null) {
	$sets = dns_zone_sets($zone);
	if (!is_array($sets))
		return result_error($sets);
	$hints = dns_record_hints($zone, $sets, $r, $old);
	$o = null;
	if ($old) {
		list($ofq, $otype, $ov, $e) = dns_record_find($account, $zone, $sets, $old);
		if ($e !== '')
			return result_error($e, array('hints' => $hints));
		$o = array('fqdn' => $ofq, 'type' => $otype);
	}
	list($fq, $type, $ttl, $v, $e) = dns_record_input($account, $zone, $sets, $r, $o);
	if ($e !== '')
		return result_error($e, array('hints' => $hints));
	$same = $old && $ofq === $fq && $otype === $type && $ov === $v;
	if (!$same && in_array($v, dns_set_of($sets, $fq, $type)[0], true))
		return result_error("There already is this $type record at $fq.", array('hints' => $hints));
	return result_ok('', array('name' => $fq, 'type' => $type, 'ttl' => $ttl, 'value' => $v,
	                           'line' => $fq.'. '.$ttl.' IN '.$type.' '.dns_value_to_zone($type, $v), 'hints' => $hints));
}

/* ---------- zone files ---------- */

/* "1h30m" -> 5400; plain seconds stay; null = not a TTL. */
function dns_zonefile_ttl($s) {
	if (ctype_digit($s))
		return (int)$s;
	if (!preg_match('/^(\d+[smhdw])+$/i', $s))
		return null;
	$n = 0;
	preg_match_all('/(\d+)([smhdw])/i', $s, $m, PREG_SET_ORDER);
	foreach ($m as $x)
		$n += (int)$x[1] * array('s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400, 'w' => 604800)[strtolower($x[2])];
	return $n;
}

/* A zone-file line -> its fields (quoted strings kept whole). */
function dns_zonefile_tokens($line) {
	preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|\S+/', $line, $m);
	return $m[0];
}

/*
 * A zone file (BIND form, as other panels export it) -> {records [{name
 * (full), type, ttl, value (dns.php's form), status: new | same | skip |
 * bad, note}], origin}. Names and host names without a final dot are under
 * $ORIGIN (the zone), as in any zone file; SOA and the zone's own NS records
 * are skipped (this server keeps its own).
 */
function dns_zonefile_parse($zone, $text, array $sets = null) {
	$origin = $zone;
	$def_ttl = 14400;
	$recs = array();
	$text = str_replace("\r", '', (string)$text);
	/* comments off (not inside quotes), then lines joined while a ( is open */
	$lines = array();
	$buf = '';
	$depth = 0;
	foreach (explode("\n", $text) as $raw) {
		$l = '';
		$q = false;
		for ($i = 0, $n = strlen($raw); $i < $n; $i++) {
			$c = $raw[$i];
			if ($c === '\\' && $q && $i + 1 < $n) { $l .= $c.$raw[++$i]; continue; }
			if ($c === '"') $q = !$q;
			if ($c === ';' && !$q) break;
			if (!$q && $c === '(') { $depth++; $c = ' '; }
			if (!$q && $c === ')') { $depth = max(0, $depth - 1); $c = ' '; }
			$l .= $c;
		}
		$buf = $buf === '' ? $l : $buf.' '.trim($l);
		if ($depth === 0) {
			if (trim($buf) !== '')
				$lines[] = $buf;
			$buf = '';
		}
	}
	if (trim($buf) !== '')
		$lines[] = $buf;

	$abs = function ($h) use (&$origin) {
		$h = strtolower($h);
		if ($h === '@')
			return $origin;
		if (substr($h, -1) === '.')
			return $h === '.' ? '.' : substr($h, 0, -1);
		return $h.'.'.$origin;
	};
	$last = $zone;
	$types = array('A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'CAA', 'NS', 'PTR', 'SOA', 'SPF', 'DS', 'DNSKEY', 'TLSA', 'SSHFP', 'NAPTR', 'HINFO', 'LOC', 'RP', 'ALIAS', 'HTTPS', 'SVCB', 'DNAME');
	foreach ($lines as $line) {
		if (preg_match('/^\$ORIGIN\s+(\S+)/i', $line, $m)) { $origin = $abs($m[1]); continue; }
		if (preg_match('/^\$TTL\s+(\S+)/i', $line, $m)) { $def_ttl = dns_zonefile_ttl($m[1]) ?? $def_ttl; continue; }
		if ($line[0] === '$')
			continue;
		$tok = dns_zonefile_tokens($line);
		if (!$tok)
			continue;
		$owner = ctype_space($line[0]) ? $last : $abs(array_shift($tok));
		$last = $owner;
		$ttl = null;
		$type = '';
		while ($tok) {
			$x = $tok[0];
			if ($ttl === null && ($t = dns_zonefile_ttl($x)) !== null) { $ttl = $t; array_shift($tok); continue; }
			if (in_array(strtoupper($x), array('IN', 'CH', 'HS'), true)) { array_shift($tok); continue; }
			break;
		}
		$type = strtoupper((string)array_shift($tok));
		$rdata = $tok;
		$r = array('name' => $owner, 'type' => $type, 'ttl' => $ttl ?? $def_ttl, 'value' => implode(' ', $rdata), 'status' => 'new', 'note' => '');
		if ($type === '' || !in_array($type, $types, true) && !preg_match('/^TYPE\d+$/', $type)) {
			$r['status'] = 'bad'; $r['note'] = 'This line could not be read.';
			$r['value'] = trim($line);
		} elseif ($owner !== $zone && substr($owner, -strlen($zone) - 1) !== '.'.$zone) {
			$r['status'] = 'skip'; $r['note'] = "Not a name of $zone.";
		} elseif ($type === 'SOA') {
			$r['status'] = 'skip'; $r['note'] = 'The zone keeps the SOA record it has here.';
		} elseif ($type === 'NS' && $owner === $zone) {
			$r['status'] = 'skip'; $r['note'] = 'The zone keeps its own name servers.';
			$r['value'] = $abs($rdata[0] ?? '');
		} else {
			switch ($type) {
			case 'CNAME': case 'NS': case 'PTR': case 'ALIAS': case 'DNAME':
				$r['value'] = $abs($rdata[0] ?? ''); break;
			case 'MX':
				$r['value'] = ($rdata[0] ?? '').' '.$abs($rdata[1] ?? ''); break;
			case 'SRV':
				$r['value'] = implode(' ', array_slice($rdata, 0, 3)).' '.$abs($rdata[3] ?? ''); break;
			case 'TXT': case 'SPF':
				$r['value'] = strpos($r['value'], '"') !== false ? dns_txt_decode($r['value']) : $r['value']; break;
			case 'CAA':
				$r['value'] = ($rdata[0] ?? '').' '.strtolower($rdata[1] ?? '').' '.(isset($rdata[2]) ? '"'.str_replace('"', '', implode(' ', array_slice($rdata, 2))).'"' : ''); break;
			}
			if ($type === 'SPF') {
				$r['type'] = 'TXT'; $r['note'] = 'The old type SPF is saved as a TXT record.';
			}
			/* a host name written without its final dot got the zone twice */
			if (($hp = dns_value_host($r['type'], $r['value'])) !== null && ($meant = dns_missing_dot_fix($zone, $hp[1])) !== '') {
				$r['value'] = dns_value_with_host($r['type'], $r['value'], $meant);
				$r['note'] = "The final dot was missing after {$hp[1]}: saved as $meant.";
			}
			if (!in_array($r['type'], dns_editable_types(), true)) {
				$r['status'] = 'skip'; $r['note'] = "Records of type {$r['type']} are not imported here.";
			} else {
				list($v, $e) = dns_record_value($r['name'], $zone, $r['type'], $r['value']);
				if ($e !== '') { $r['status'] = 'bad'; $r['note'] = $e; }
				else {
					$r['value'] = $v;
					if ($sets !== null && in_array($v, dns_set_of($sets, $r['name'], $r['type'])[0], true))
						$r['status'] = 'same';
				}
			}
		}
		$recs[] = $r;
		if (count($recs) >= 5000)
			break;
	}
	return array('records' => $recs, 'origin' => $origin);
}

/* Read a zone file and say what importing it would do: the parsed records,
   and per record set (name + type) whether it replaces what is there. */
function dns_zonefile_preview($account, $zone, $text) {
	$sets = dns_zone_sets($zone);
	if (!is_array($sets))
		return result_error($sets);
	if (strlen((string)$text) > 1048576)
		return result_error('The zone file is larger than 1 MB.');
	$p = dns_zonefile_parse($zone, $text, $sets);
	foreach ($p['records'] as &$r)
		if ($r['status'] === 'new' && ($why = dns_name_locked($account, $zone, $r['name'])) !== '') {
			$r['status'] = 'skip';
			$r['note'] = $why;
		}
	unset($r);
	$now = array();
	foreach ($sets as $s)
		if ($s['type'] !== 'SOA' && !($s['type'] === 'NS' && $s['name'] === $zone))
			$now[$s['name'].'|'.$s['type']] = $s['values'];
	return result_ok('', array('records' => $p['records'], 'current' => $now));
}

/*
 * Import records: $recs [{name, type, ttl, value}] (from the preview), per
 * record set. $replace: a set the file has is made exactly the file's values
 * (what the zone had there goes), else the values are added to it. A set
 * shares one TTL: the first record's. Returns counts and the errors of the
 * sets that were not saved.
 */
function dns_records_import($account, $zone, array $recs, $replace = true) {
	$sets = dns_zone_sets($zone);
	if (!is_array($sets))
		return result_error($sets);
	if (count($recs) > 5000)
		return result_error('At most 5000 records at a time.');
	$groups = array();
	$errors = array();
	foreach ($recs as $r) {
		if (!is_array($r))
			continue;
		list($fq, $type, $ttl, $v, $e) = dns_record_input($account, $zone, array(), $r);
		if ($e !== '') {
			$errors[] = trim(($r['name'] ?? '').' '.($r['type'] ?? '')).': '.$e;
			continue;
		}
		$k = $fq.'|'.$type;
		if (!isset($groups[$k]))
			$groups[$k] = array('name' => $fq, 'type' => $type, 'ttl' => $ttl, 'values' => array());
		if (!in_array($v, $groups[$k]['values'], true))
			$groups[$k]['values'][] = $v;
	}
	$added = $sets_done = 0;
	foreach ($groups as $g) {
		list($have, $httl) = dns_set_of($sets, $g['name'], $g['type']);
		$values = $replace ? $g['values'] : array_values(array_unique(array_merge($have, $g['values'])));
		if ($values === $have)
			continue;
		/* a CNAME stands alone */
		$clash = '';
		foreach ($sets as $s)
			if ($s['name'] === $g['name'] && $s['type'] !== $g['type'] && ($s['type'] === 'CNAME' || $g['type'] === 'CNAME'))
				$clash = $s['type'];
		if ($clash !== '') {
			$errors[] = "{$g['name']} {$g['type']}: the name already has a".($clash === 'CNAME' ? ' CNAME' : 'n '.$clash.' record').', and a CNAME must stand alone.';
			continue;
		}
		if ($g['type'] === 'CNAME' && count($values) > 1) {
			$errors[] = "{$g['name']} CNAME: a name can be an alias of only one name (the file gives ".count($values).').';
			continue;
		}
		$ttl = $httl && !$replace ? $httl : $g['ttl'];
		if (($e = (string)dns_zone_put($zone, $g['name'], $g['type'], $ttl, $values)) !== '') {
			$errors[] = "{$g['name']} {$g['type']}: ".preg_replace('/^Error:\s*(DNS API:\s*)?/', '', $e);
			continue;
		}
		$added += count(array_diff($values, $have));
		$sets_done++;
		/* the zone as it is now, for the next sets' checks */
		$found = false;
		foreach ($sets as &$s)
			if ($s['name'] === $g['name'] && $s['type'] === $g['type']) { $s['values'] = $values; $s['ttl'] = $ttl; $found = true; }
		unset($s);
		if (!$found)
			$sets[] = array('name' => $g['name'], 'type' => $g['type'], 'ttl' => $ttl, 'values' => $values, 'proxied' => null);
	}
	route_log("dns $zone: zone file imported, $sets_done record sets saved, ".count($errors).' refused'.($account !== '' ? " for $account" : ''));
	if ($sets_done)
		plugin_hook('dns_zone.imported', array('account' => $account, 'zone' => $zone, 'sets' => $sets_done));
	$msg = $sets_done ? "$sets_done record set".($sets_done > 1 ? 's' : '')." saved ($added new record".($added === 1 ? '' : 's').')' : 'Nothing was changed';
	if (!$sets_done && $errors)
		return result_error($msg.': '.implode(' ', array_slice($errors, 0, 5)), array('errors' => $errors));
	return result_ok($msg.'.', array('sets' => $sets_done, 'added' => $added, 'errors' => $errors), array_slice($errors, 0, 20));
}

/* The zone as a zone file. */
function dns_zonefile_export($zone) {
	$sets = dns_zone_sets($zone);
	if (!is_array($sets))
		return result_error($sets);
	$order = array_flip(array('SOA', 'NS', 'A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'CAA'));
	usort($sets, function ($a, $b) use ($order, $zone) {
		$ka = $a['name'] === $zone ? '' : implode('.', array_reverse(explode('.', $a['name'])));
		$kb = $b['name'] === $zone ? '' : implode('.', array_reverse(explode('.', $b['name'])));
		if (($a['type'] === 'SOA') !== ($b['type'] === 'SOA'))
			return $a['type'] === 'SOA' ? -1 : 1;
		return strcmp($ka, $kb) ?: (($order[$a['type']] ?? 99) - ($order[$b['type']] ?? 99));
	});
	$w = 0;
	foreach ($sets as $s)
		$w = max($w, strlen($s['name']) + 1);
	$out = array('; '.$zone.' - exported '.date('Y-m-d H:i').' from '.gethostname(), '$ORIGIN '.$zone.'.', '');
	foreach ($sets as $s)
		foreach ($s['values'] as $v) {
			$content = $s['type'] === 'SOA' ? $v : dns_value_to_zone($s['type'], $v);
			if (in_array($s['type'], array('NS', 'PTR', 'CNAME'), true) && substr($content, -1) !== '.')
				$content .= '.';
			$out[] = str_pad($s['name'].'.', $w).' '.str_pad((string)$s['ttl'], 6).' IN '.str_pad($s['type'], 5).' '.$content;
		}
	return result_ok('', array('zone' => $zone, 'text' => implode("\n", $out)."\n"));
}

/* The zone a zone file is for: its $ORIGIN, else the owner of its SOA
   record; '' = not found. */
function dns_zonefile_origin($text) {
	$text = str_replace("\r", '', (string)$text);
	if (preg_match('/^\$ORIGIN\s+(\S+)/mi', $text, $m) && ($z = dns_zone_name($m[1])) !== '' && valid_domain($z))
		return $z;
	if (preg_match('/^(\S+)\.\s+(?:\d+[smhdw]?\s+)?(?:IN\s+)?SOA\s/mi', $text, $m) && ($z = dns_zone_name($m[1])) !== '' && valid_domain($z))
		return $z;
	return '';
}

/* A zone file read for a NEW zone: {zone (as given, or found in the file),
   records} as dns_zonefile_parse() gives them. */
function dns_zonefile_preview_new($zone, $text) {
	global $db;
	if (strlen((string)$text) > 1048576)
		return result_error('The zone file is larger than 1 MB.');
	$zone = dns_zone_name($zone);
	if ($zone === '')
		$zone = dns_zonefile_origin($text);
	if ($zone === '')
		return result_error('The domain was not found in the file (it has no $ORIGIN and no SOA record): type it in.');
	if (!valid_domain($zone) || strpos($zone, '.') === false)
		return result_error('Enter a domain name, such as example.com.');
	dns_provider_load($db);
	$all = get_zones();
	if (!is_array($all))
		return result_error('The DNS provider is not answering: '.preg_replace('/^Error:\s*/', '', (string)$all));
	if (isset($all[$zone]))
		return result_error("There already is a zone $zone. Open it and use Import there to add the file's records to it.", array('exists' => $zone));
	$p = dns_zonefile_parse($zone, $text);
	$n = count(array_filter($p['records'], function ($r) { return $r['status'] === 'new'; }));
	if (!$n)
		return result_error("No records of $zone were found in the file.");
	return result_ok('', array('zone' => $zone, 'records' => $p['records']));
}

/*
 * A new zone made from a zone file: the zone is created, then holds only the
 * file's records ($recs from the preview) - the provider's starting records
 * go, except SOA, the zone's NS and the addresses of its own name servers.
 */
function dns_zone_import_new($zone, array $recs) {
	$zone = dns_zone_name($zone);
	$ip = '';
	foreach ($recs as $r)
		if (is_array($r) && strtoupper((string)($r['type'] ?? '')) === 'A' && dns_zone_name($r['name'] ?? '') === $zone
		    && filter_var($r['value'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) { $ip = $r['value']; break; }
	$c = dns_zone_create($zone, $ip);
	if (!$c['ok'])
		return $c;
	$sets = dns_zone_sets($zone);
	if (!is_array($sets))
		return result_error("Zone $zone created, but it could not be read back to import the records: $sets");
	$file = array();
	foreach ($recs as $r)
		if (is_array($r)) {
			list($fq) = dns_record_name($zone, $r['name'] ?? '');
			$file[$fq.'|'.strtoupper((string)($r['type'] ?? ''))] = true;
		}
	$ns = dns_set_of($sets, $zone, 'NS')[0];
	foreach ($sets as $s) {
		if ($s['type'] === 'SOA' || ($s['type'] === 'NS' && $s['name'] === $zone) || isset($file[$s['name'].'|'.$s['type']]))
			continue;
		if (in_array($s['type'], array('A', 'AAAA'), true) && in_array($s['name'], $ns, true))
			continue;
		dns_zone_put($zone, $s['name'], $s['type'], (int)$s['ttl'], array());
	}
	$r = dns_records_import('', $zone, $recs, true);
	if (!$r['ok'])
		return result_ok("Zone $zone created, but its records were not imported: ".$r['error'], array('zone' => $zone), $r['warnings']);
	$n = count($recs) - count($r['data']['errors'] ?? array());
	return result_ok("Zone $zone created with $n record".($n === 1 ? '' : 's').' from the zone file.', array('zone' => $zone) + $r['data'], array_merge($c['warnings'], $r['warnings']));
}
