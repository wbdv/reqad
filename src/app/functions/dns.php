<?php
/*
 * The DNS zone editor: the records of an account's zones at the server's DNS
 * provider (Settings > DNS), for the API and so MCP and the account panel on
 * 2083 (Domains > DNS zone). Records go through the providers' whole-zone
 * functions (dns_zone_rrsets / dns_zone_put, comment in functions.php).
 *
 * A zone is the account's when its name is one of the account's domains
 * (main, addon, alias, mail domain: scope_owns 'domain'); nothing makes a
 * zone here. Inside it, names that are the server's own (the host name and
 * under it) or another account's (one of its domains and under it) are
 * left alone. Only A, AAAA, CNAME, MX, TXT, SRV and CAA are changed: NS and
 * SOA stay as the provider has them (the name servers are chosen where the
 * domain is registered), other types are shown, not changed.
 *
 * A record is named by (name, type, value); the values of one name and type
 * are a record set and share one TTL. Functions take the account ('' = a
 * server token: no other-account check) and return result_ok() /
 * result_error(); callers acting for an account ask scope_check() first.
 *
 * The server's admin on 2087 (DNS Zones, templates/dns.php) turns on
 * dns_admin_mode() first: then every name may change (the host name too),
 * NS and PTR records are edited where the provider takes them (PowerDNS),
 * and records of any other type except SOA can be removed. The zone list,
 * zone check, hints and zone files are in dns_tools.php.
 */

/* The 2087 admin is at work (set once per request by the panel's handler). */
function dns_admin_mode($on = null) {
	static $admin = false;
	if ($on !== null)
		$admin = (bool)$on;
	return $admin;
}

function dns_editable_types() {
	$t = array('A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'CAA');
	if (dns_admin_mode() && setting_get('dns-provider') === 'powerdns')
		array_push($t, 'NS', 'PTR');
	return $t;
}

/* The TTLs offered (seconds); Cloudflare also takes 1 = automatic. */
function dns_ttls() {
	return dns_admin_mode() ? array(60, 300, 600, 1800, 3600, 14400, 43200, 86400, 604800) : array(300, 3600, 14400, 86400);
}

/* Is a DNS provider set up (Settings > DNS)? */
function dns_managed() {
	return in_array(setting_get('dns-provider'), array('cloudflare', 'cpanel', 'powerdns'), true);
}

/* Every name an account has on this server => its account: main domains,
   aliases, addon domains, mail domains (once per process). */
function dns_owner_map() {
	global $db, $ini;
	static $map = null;
	if ($map !== null)
		return $map;
	$map = array();
	$res = $db->query('SELECT user, domain FROM accounts');
	while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC)))
		$map[strtolower($r['domain'])] = $r['user'];
	$res = $db->query('SELECT l.alias, a.user FROM aliases l JOIN accounts a ON a.id = l.account_id WHERE l.is_wildcard = 0');
	while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC)))
		$map += array(strtolower($r['alias']) => $r['user']);
	foreach (addon_domain_list($ini) as $d => $a)
		$map += array(strtolower($d) => $a['user']);
	foreach (email_userdomains() as $d => $u)
		$map += array($d => $u);
	return $map;
}

/* Why $fqdn in $zone cannot be changed for $account ('' = it can). */
function dns_name_locked($account, $zone, $fqdn) {
	if ($account === '' && dns_admin_mode())
		return '';
	$host = strtolower(trim((string)gethostname()));
	if ($host !== '' && ($fqdn === $host || substr($fqdn, -strlen($host) - 1) === '.'.$host))
		return 'This is the name of the server itself.';
	if ($account === '')
		return '';
	/* the closest of its names (or the zone's) that an account has */
	$map = dns_owner_map();
	for ($n = $fqdn; $n !== $zone && strpos($n, '.') !== false; $n = substr($n, strpos($n, '.') + 1))
		if (isset($map[$n]))
			return $map[$n] === $account ? '' : 'This name belongs to another account on this server.';
	return '';
}

/* The account's zones at the provider: array(zones, elsewhere = the account's
   names no zone here holds), or a string (why not). */
function dns_account_zones($account) {
	global $db;
	if (!dns_managed())
		return 'No DNS provider is set up on this server, so DNS records are changed where each domain\'s DNS is hosted.';
	dns_provider_load($db);
	$all = get_zones();
	if (!is_array($all))
		return 'The DNS provider is not answering: '.preg_replace('/^Error:\s*/', '', (string)$all);
	$names = domain_account_names($account);
	foreach (email_userdomains() as $d => $u)
		if ($u === $account)
			$names[] = $d;
	$zones = $elsewhere = array();
	foreach (array_unique($names) as $n)
		if (isset($all[$n]))
			$zones[] = $n;
	foreach (array_unique($names) as $n)
		if (dns_zone_for($n, array_flip($zones)) === '' && substr($n, 0, 4) !== 'www.')
			$elsewhere[] = $n;
	$main = strtolower((string)(account_get($db, $account)['domain'] ?? ''));
	usort($zones, function ($a, $b) use ($main) { return ($b === $main) - ($a === $main) ?: strcmp($a, $b); });
	sort($elsewhere);
	return array('zones' => $zones, 'elsewhere' => $elsewhere);
}

/* The zone's record sets, or a string (why not). $zone must be the account's
   (scope_check) and at the provider. */
function dns_zone_sets($zone) {
	global $db;
	if (!dns_managed())
		return 'No DNS provider is set up on this server.';
	dns_provider_load($db);
	$sets = dns_zone_rrsets($zone);
	return is_array($sets) ? $sets : "$zone is not a zone at this server's DNS provider.";
}

/* A name as typed ("@", "www", "www.example.com.") -> array(full name, error). */
function dns_record_name($zone, $name) {
	$name = strtolower(rtrim(trim((string)$name), '.'));
	if (preg_match('/[^\x20-\x7e]/', $name) && function_exists('idn_to_ascii')) {
		$a = idn_to_ascii($name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
		if (is_string($a))
			$name = $a;
	}
	if ($name === '' || $name === '@')
		$fq = $zone;
	elseif ($name === $zone || substr($name, -strlen($zone) - 1) === '.'.$zone)
		$fq = $name;
	else
		$fq = $name.'.'.$zone;
	if (strlen($fq) > 253)
		return array('', 'The name is too long.');
	$labels = explode('.', substr($fq, 0, -strlen($zone) - 1) ?: '');
	foreach ($fq === $zone ? array() : $labels as $i => $l)
		if (!($l === '*' && $i === 0) && !preg_match('/^[a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9_])?$/', $l))
			return array('', 'A name may use letters, digits, - and _ (and * as its first part), with dots between the parts.');
	return array($fq, '');
}

/* A host name in a value: lowercase, no trailing dot; '' when it is not one. */
function dns_host_ok($h, $none = false) {
	$h = dns_host_canon($h);
	if ($h === '.')
		return $none ? '.' : '';
	if (strlen($h) > 253 || strpos($h, '.') === false || filter_var($h, FILTER_VALIDATE_IP))
		return '';
	foreach (explode('.', $h) as $l)
		if (!preg_match('/^[a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9_])?$/', $l))
			return '';
	return $h;
}

/* A value as typed -> array(value in the common form, error). */
function dns_record_value($fqdn, $zone, $type, $value) {
	$value = trim(str_replace(array("\r", "\n", "\t"), ' ', (string)$value));
	$port = function ($n) { return ctype_digit((string)$n) && (int)$n <= 65535; };
	switch ($type) {
	case 'A':
		return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? array($value, '') : array('', 'Enter an IPv4 address, such as 192.0.2.10.');
	case 'AAAA':
		return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? array(inet_ntop(inet_pton($value)), '') : array('', 'Enter an IPv6 address, such as 2001:db8::10.');
	case 'CNAME':
		if ($fqdn === $zone)
			return array('', 'The domain itself cannot be a CNAME: give it an A record instead.');
		$h = dns_host_ok($value);
		if ($h === '')
			return array('', 'Enter the full name it is an alias of, such as example.com.');
		return $h === $fqdn ? array('', 'A name cannot be an alias of itself.') : array($h, '');
	case 'MX':
		$p = dns_value_parts('MX', $value);
		if ($p === null || !$port($p[0]))
			return array('', 'Enter a priority (0-65535) and a mail server name.');
		$h = dns_host_ok($p[1], true);
		return $h === '' ? array('', 'The mail server must be a name, such as mail.example.com, not an address.') : array($p[0].' '.$h, '');
	case 'TXT':
		if (strlen($value) > 1 && $value[0] === '"' && substr($value, -1) === '"')
			$value = dns_txt_decode($value);
		if ($value === '' || strlen($value) > 2048 || preg_match('/[\x00-\x1f\x7f]/', $value) || !mb_check_encoding($value, 'UTF-8'))
			return array('', 'Enter the text, up to 2048 characters on one line.');
		return array($value, '');
	case 'SRV':
		if (!preg_match('/^_[a-z0-9-]+\._(tcp|udp|tls|sctp)\./', $fqdn.'.'))
			return array('', 'The name of an SRV record starts with the service and the protocol, such as _sip._tcp.');
		$p = dns_value_parts('SRV', $value);
		if ($p === null || !$port($p[0]) || !$port($p[1]) || !$port($p[2]))
			return array('', 'Enter the priority, weight and port (0-65535) and the server name.');
		$h = dns_host_ok($p[3], true);
		return $h === '' ? array('', 'The server must be a name, such as sip.example.com, not an address.') : array(implode(' ', array($p[0], $p[1], $p[2], $h)), '');
	case 'NS':
	case 'PTR':
		$h = dns_host_ok($value);
		if ($h === '')
			return array('', $type === 'NS' ? 'Enter the name of a name server, such as ns1.example.com.' : 'Enter the host name the address belongs to, such as mail.example.com.');
		return array($h, '');
	case 'CAA':
		$p = dns_value_parts('CAA', $value);
		if ($p === null || !in_array($p[0], array(0, 128), true) || !in_array($p[1], array('issue', 'issuewild', 'iodef'), true))
			return array('', 'Enter the flag (0), the tag (issue, issuewild or iodef) and the value, such as 0 issue "letsencrypt.org".');
		$p[2] = trim($p[2]);
		if ($p[1] === 'iodef' ? !preg_match('#^(mailto:\S+@\S+|https?://\S+)$#', $p[2]) : !preg_match('/^[a-z0-9.;= -]{1,255}$/i', $p[2]))
			return array('', $p[1] === 'iodef' ? 'The iodef value is where reports go: mailto:security@example.com or an https:// address.' : 'The value is the certificate authority\'s domain, such as letsencrypt.org (or ; for none).');
		return array(dns_value_join('CAA', $p), '');
	}
	return array('', 'Choose a record type: '.implode(', ', dns_editable_types()).'.');
}

/* What a record is for, as a short tag the panel shows ('' = nothing known). */
function dns_record_tag($fqdn, $zone, $type, $value, array $here) {
	$v = strtolower($value);
	if ($type === 'TXT') {
		if (strpos($v, 'v=spf1') === 0)
			return 'spf';
		if (strpos($fqdn, '_dmarc.') === 0 && strpos($v, 'v=dmarc1') === 0)
			return 'dmarc';
		if (strpos($fqdn, '._domainkey.') !== false)
			return 'dkim';
		if (preg_match('/^(google-site-verification=|ms=|zoho-verification=|facebook-domain-verification=|apple-domain-verification=|[a-z0-9-]+-site-verification=|[a-z0-9-]+-verification=)/', $v))
			return 'verify';
		return '';
	}
	if (($type === 'A' || $type === 'AAAA') && in_array($v, $here, true))
		return 'here';
	if ($type === 'MX' || $type === 'CNAME') {
		$h = $type === 'MX' ? (string)(dns_value_parts('MX', $v)[1] ?? '') : $v;
		if ($h === strtolower((string)gethostname()))
			return 'here';
	}
	return '';
}

/* The zone's records for the editor: array(zone, provider, nameservers,
   records: [{name ('@' = the domain), fqdn, type, ttl, value, proxied, edit,
   locked (why not), tag}], types, ttls, auto_ttl). */
function dns_zone_view($account, $zone) {
	global $dns_provider_name;
	$sets = dns_zone_sets($zone);
	if (!is_array($sets))
		return result_error($sets);
	$here = $account !== '' ? domain_server_ips($account) : domain_server_ips();
	$types = dns_editable_types();
	$admin = $account === '' && dns_admin_mode();
	$host = strtolower(trim((string)gethostname()));
	$order = array_flip(array_merge(array('NS'), $types));
	$ns = $out = array();
	$soa = '';
	foreach ($sets as $s) {
		if ($s['type'] === 'SOA') {
			$soa = (string)($s['values'][0] ?? '');
			continue;
		}
		if ($s['type'] === 'NS' && $s['name'] === $zone) {
			$ns = array_values(array_unique(array_merge($ns, $s['values'])));
			if (!$admin)
				continue;
		}
		$why = '';
		if ($s['type'] === 'NS' && !in_array('NS', $types, true))
			$why = 'Name server records are not changed here.';
		elseif (!in_array($s['type'], $types, true))
			$why = 'Records of this type are not changed here.';
		else
			$why = dns_name_locked($account, $zone, $s['name']);
		/* the admin may still remove a record of a type not edited here */
		$del = $why === '' || ($admin && $s['type'] !== 'SOA');
		$note = $admin && $host !== '' && ($s['name'] === $host || substr($s['name'], -strlen($host) - 1) === '.'.$host)
			? 'The server\'s own name: the panel, mail and the certificates use it.' : '';
		foreach ($s['values'] as $v)
			$out[] = array('name' => $s['name'] === $zone ? '@' : substr($s['name'], 0, -strlen($zone) - 1), 'fqdn' => $s['name'],
			               'type' => $s['type'], 'ttl' => (int)$s['ttl'], 'value' => (string)$v, 'proxied' => $s['proxied'],
			               'edit' => $why === '', 'del' => $del, 'locked' => $why, 'note' => $note,
			               'tag' => dns_record_tag($s['name'], $zone, $s['type'], (string)$v, $here));
	}
	usort($out, function ($a, $b) use ($order, $zone) {
		$ka = $a['fqdn'] === $zone ? '' : implode('.', array_reverse(explode('.', $a['name'])));
		$kb = $b['fqdn'] === $zone ? '' : implode('.', array_reverse(explode('.', $b['name'])));
		return strcmp($ka, $kb) ?: (($order[$a['type']] ?? 99) - ($order[$b['type']] ?? 99)) ?: strcmp($a['value'], $b['value']);
	});
	$auto = setting_get('dns-provider') === 'cloudflare';
	$data = array('zone' => $zone, 'provider' => (string)$dns_provider_name, 'nameservers' => $ns, 'records' => $out,
	              'types' => $types, 'ttls' => dns_ttls(), 'auto_ttl' => $auto);
	if ($admin)
		$data['soa'] = $soa;
	return result_ok('', $data);
}

/* A record as asked for -> array(fqdn, type, ttl, value, error), checked
   against the zone ($sets) as it is now. $old: the record it replaces. */
function dns_record_input($account, $zone, array $sets, array $r, array $old = null) {
	$type = strtoupper(trim((string)($r['type'] ?? '')));
	if (!in_array($type, dns_editable_types(), true))
		return array('', '', 0, '', 'Choose a record type: '.implode(', ', dns_editable_types()).'.');
	list($fq, $e) = dns_record_name($zone, $r['name'] ?? '');
	if ($e !== '')
		return array('', '', 0, '', $e);
	if (($why = dns_name_locked($account, $zone, $fq)) !== '')
		return array('', '', 0, '', "$fq cannot be changed here. $why");
	list($v, $e) = dns_record_value($fq, $zone, $type, $r['value'] ?? '');
	if ($e !== '')
		return array('', '', 0, '', $e);
	/* no TTL given: the one its record set has, else 4 hours */
	$ttl = (int)($r['ttl'] ?? 0);
	if ($ttl === 0)
		$ttl = dns_set_of($sets, $fq, $type)[1] ?: 14400;
	if (!($ttl === 1 && setting_get('dns-provider') === 'cloudflare') && ($ttl < 60 || $ttl > 604800))
		return array('', '', 0, '', 'The TTL is between 60 seconds and a week.');
	/* a CNAME stands alone at its name */
	foreach ($sets as $s) {
		if ($s['name'] !== $fq || ($old && $old['fqdn'] === $fq && $old['type'] === $s['type'] && count($s['values']) === 1))
			continue;
		if ($type === 'CNAME' && $s['type'] !== 'CNAME')
			return array('', '', 0, '', "$fq already has other records, so it cannot be a CNAME (an alias of another name).");
		if ($type === 'CNAME' && $s['type'] === 'CNAME' && !($old && $old['fqdn'] === $fq && $old['type'] === 'CNAME'))
			return array('', '', 0, '', "$fq already is a CNAME: a name can have only one.");
		if ($type !== 'CNAME' && $s['type'] === 'CNAME')
			return array('', '', 0, '', "$fq is a CNAME (an alias of ".$s['values'][0]."), so it cannot have other records.");
	}
	return array($fq, $type, $ttl, $v, '');
}

/* The values now at (fqdn, type), and its TTL. */
function dns_set_of(array $sets, $fqdn, $type) {
	foreach ($sets as $s)
		if ($s['name'] === $fqdn && $s['type'] === $type)
			return array($s['values'], (int)$s['ttl']);
	return array(array(), 0);
}

/* The record (name, type, value) as it is in the zone now: array(fqdn, type, value, error). */
function dns_record_find($account, $zone, array $sets, array $r) {
	$type = strtoupper(trim((string)($r['type'] ?? '')));
	list($fq, $e) = dns_record_name($zone, $r['name'] ?? '');
	/* the admin removes records of any type but SOA */
	$known = in_array($type, dns_editable_types(), true) || ($account === '' && dns_admin_mode() && preg_match('/^[A-Z0-9]{1,10}$/', $type) && $type !== 'SOA');
	if ($e !== '' || !$known)
		return array('', '', '', 'Say which record: its name, type and value.');
	if (($why = dns_name_locked($account, $zone, $fq)) !== '')
		return array('', '', '', "$fq cannot be changed here. $why");
	list($values) = dns_set_of($sets, $fq, $type);
	$want = trim((string)($r['value'] ?? ''));
	foreach ($values as $v)
		if ($v === $want || strtolower($v) === strtolower($want))
			return array($fq, $type, $v, '');
	return array('', '', '', "There is no such $type record at $fq (it may have been changed meanwhile: reload the page).");
}

function dns_put_error($e) {
	return result_error('The DNS provider did not take the change: '.preg_replace('/^Error:\s*(DNS API:\s*)?/', '', (string)$e));
}

/* Add a record: $r = name, type, ttl, value. */
function dns_record_add($account, $zone, array $r) {
	$sets = dns_zone_sets($zone);
	if (!is_array($sets))
		return result_error($sets);
	list($fq, $type, $ttl, $v, $e) = dns_record_input($account, $zone, $sets, $r);
	if ($e !== '')
		return result_error($e);
	list($values) = dns_set_of($sets, $fq, $type);
	if (in_array($v, $values, true))
		return result_error("There already is this $type record at $fq.");
	if ($account !== '' && ($e = limit_allows($account, 'dns_record', array('zone' => $zone, 'name' => $fq, 'type' => $type))) !== '')
		return result_error($e);
	$values[] = $v;
	if (($e = (string)dns_zone_put($zone, $fq, $type, $ttl, $values)) !== '')
		return dns_put_error($e);
	route_log("dns $zone: $type $fq ".mb_substr($v, 0, 120).' added'.($account !== '' ? " for $account" : ''));
	plugin_hook('dns_record.created', array('account' => $account, 'zone' => $zone, 'name' => $fq, 'type' => $type, 'value' => $v));
	return result_ok("$type record of $fq added.", array('name' => $fq, 'type' => $type, 'ttl' => $ttl, 'value' => $v));
}

/* Change a record: $old = name, type, value of the one there; $r = the new one. */
function dns_record_edit($account, $zone, array $old, array $r) {
	$sets = dns_zone_sets($zone);
	if (!is_array($sets))
		return result_error($sets);
	list($ofq, $otype, $ov, $e) = dns_record_find($account, $zone, $sets, $old);
	if ($e !== '')
		return result_error($e);
	list($fq, $type, $ttl, $v, $e) = dns_record_input($account, $zone, $sets, $r, array('fqdn' => $ofq, 'type' => $otype));
	if ($e !== '')
		return result_error($e);
	list($ovalues) = dns_set_of($sets, $ofq, $otype);
	if ($otype === 'NS' && $ofq === $zone && count($ovalues) <= 1 && !($fq === $ofq && $type === $otype))
		return result_error('A zone needs at least one name server record: add the new one first, then remove this one.');
	if ($fq === $ofq && $type === $otype) {
		$values = array();
		foreach ($ovalues as $x)
			if ($x !== $ov)
				$values[] = $x;
		if ($v !== $ov && in_array($v, $values, true))
			return result_error("There already is this $type record at $fq.");
		/* in the same place, so the record keeps its position */
		$values = array_map(function ($x) use ($ov, $v) { return $x === $ov ? $v : $x; }, $ovalues);
		if (($e = (string)dns_zone_put($zone, $fq, $type, $ttl, $values)) !== '')
			return dns_put_error($e);
	} else {
		/* the new one first: a failure then leaves the old one as it was */
		list($values) = dns_set_of($sets, $fq, $type);
		if (in_array($v, $values, true))
			return result_error("There already is this $type record at $fq.");
		$values[] = $v;
		if (($e = (string)dns_zone_put($zone, $fq, $type, $ttl, $values)) !== '')
			return dns_put_error($e);
		list($ovalues, $ottl) = dns_set_of($sets, $ofq, $otype);
		if (($e = (string)dns_zone_put($zone, $ofq, $otype, $ottl ?: $ttl, array_values(array_diff($ovalues, array($ov))))) !== '')
			return result_ok("$type record of $fq saved.", array('name' => $fq, 'type' => $type, 'ttl' => $ttl, 'value' => $v),
			                 array("The old $otype record of $ofq is still there: ".preg_replace('/^Error:\s*(DNS API:\s*)?/', '', $e)));
	}
	route_log("dns $zone: $otype $ofq ".mb_substr($ov, 0, 120)." -> $type $fq ".mb_substr($v, 0, 120).($account !== '' ? " for $account" : ''));
	plugin_hook('dns_record.changed', array('account' => $account, 'zone' => $zone, 'name' => $fq, 'type' => $type, 'value' => $v,
	                                        'old' => array('name' => $ofq, 'type' => $otype, 'value' => $ov)));
	return result_ok("$type record of $fq saved.", array('name' => $fq, 'type' => $type, 'ttl' => $ttl, 'value' => $v));
}

/* Remove a record: $r = name, type, value. */
function dns_record_delete($account, $zone, array $r) {
	$sets = dns_zone_sets($zone);
	if (!is_array($sets))
		return result_error($sets);
	list($fq, $type, $v, $e) = dns_record_find($account, $zone, $sets, $r);
	if ($e !== '')
		return result_error($e);
	list($values, $ttl) = dns_set_of($sets, $fq, $type);
	if ($type === 'NS' && $fq === $zone && count($values) <= 1)
		return result_error('A zone needs at least one name server record: add the new one first, then remove this one.');
	if (($e = (string)dns_zone_put($zone, $fq, $type, $ttl ?: 14400, array_values(array_diff($values, array($v))))) !== '')
		return dns_put_error($e);
	route_log("dns $zone: $type $fq ".mb_substr($v, 0, 120).' removed'.($account !== '' ? " for $account" : ''));
	plugin_hook('dns_record.deleted', array('account' => $account, 'zone' => $zone, 'name' => $fq, 'type' => $type, 'value' => $v));
	return result_ok("$type record of $fq removed.", array('name' => $fq, 'type' => $type, 'value' => $v));
}

/* Remove several records at once: $recs = [{name, type, value}]. One change
   per record set; refused as a whole when a record is not there any more or
   the zone would lose its last name server record. */
function dns_records_delete($account, $zone, array $recs) {
	$sets = dns_zone_sets($zone);
	if (!is_array($sets))
		return result_error($sets);
	if (!$recs)
		return result_error('Choose the records to remove.');
	if (count($recs) > 1000)
		return result_error('At most 1000 records at a time.');
	$groups = array();
	foreach ($recs as $r) {
		list($fq, $type, $v, $e) = dns_record_find($account, $zone, $sets, is_array($r) ? $r : array());
		if ($e !== '')
			return result_error($e);
		$groups[$fq.'|'.$type]['name'] = $fq;
		$groups[$fq.'|'.$type]['type'] = $type;
		$groups[$fq.'|'.$type]['gone'][] = $v;
	}
	foreach ($groups as $g)
		if ($g['type'] === 'NS' && $g['name'] === $zone && !array_diff(dns_set_of($sets, $zone, 'NS')[0], $g['gone']))
			return result_error('A zone needs at least one name server record: leave one of them.');
	$n = 0;
	$errors = array();
	foreach ($groups as $g) {
		list($values, $ttl) = dns_set_of($sets, $g['name'], $g['type']);
		if (($e = (string)dns_zone_put($zone, $g['name'], $g['type'], $ttl ?: 14400, array_values(array_diff($values, $g['gone'])))) !== '') {
			$errors[] = "{$g['type']} {$g['name']}: ".preg_replace('/^Error:\s*(DNS API:\s*)?/', '', $e);
			continue;
		}
		foreach ($g['gone'] as $v) {
			$n++;
			route_log("dns $zone: {$g['type']} {$g['name']} ".mb_substr($v, 0, 120).' removed'.($account !== '' ? " for $account" : ''));
			plugin_hook('dns_record.deleted', array('account' => $account, 'zone' => $zone, 'name' => $g['name'], 'type' => $g['type'], 'value' => $v));
		}
	}
	if (!$n)
		return result_error('The DNS provider did not take the change: '.implode(' ', $errors));
	return result_ok($n === 1 ? '1 record removed.' : "$n records removed.", array('removed' => $n), $errors);
}
