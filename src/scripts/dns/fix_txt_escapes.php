<?php
/*
 * Fix TXT records saved with zone-file escapes left in them.
 *
 * Before 1.0.51, importing a zone file that writes ';' as '\;' inside TXT
 * strings (cPanel does: "v=DKIM1\; k=rsa\; p=...") kept the backslash, so
 * PowerDNS served "v=DMARC1\; p=none..." - DMARC and DKIM records receivers
 * cannot read. This rewrites every such TXT/SPF record in the local PowerDNS
 * (through its API, so serials go up and secondaries are notified).
 *
 *   php fix_txt_escapes.php                 list what would change (default)
 *   php fix_txt_escapes.php --apply         change it (a backup of every
 *                                           changed set goes to
 *                                           /root/reqad-txt-fix-<time>.json)
 *   php fix_txt_escapes.php --zone=a.com    only this zone
 *
 * Standalone: reads only powerdns-server / powerdns-api-key from Reqad's
 * settings table, so it also runs on servers with an older Reqad.
 */

if (PHP_SAPI !== 'cli')
	exit(1);
$apply = in_array('--apply', $argv, true);
$only = '';
foreach ($argv as $a)
	if (strpos($a, '--zone=') === 0)
		$only = strtolower(rtrim(substr($a, 7), '.')).'.';

$db = new SQLite3('/usr/local/reqad/db/reqad.db', SQLITE3_OPEN_READONLY);
$set = array();
$r = $db->query("SELECT name, value FROM settings WHERE name IN ('powerdns-server', 'powerdns-api-key')");
while ($row = $r->fetchArray(SQLITE3_ASSOC))
	$set[$row['name']] = (string)$row['value'];
$db->close();
if (empty($set['powerdns-server']) || empty($set['powerdns-api-key']))
	die("No PowerDNS server / API key in Reqad's settings.\n");
$base = rtrim($set['powerdns-server'], '/');
if (strpos($base, '/api/v1') === false)
	$base .= '/api/v1/servers/localhost';
$key = $set['powerdns-api-key'];

function pdns($method, $path, $body = null) {
	global $base, $key;
	$c = curl_init($base.$path);
	curl_setopt_array($c, array(CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
	                            CURLOPT_HTTPHEADER => array('X-API-Key: '.$key, 'Content-Type: application/json')));
	if ($body !== null)
		curl_setopt($c, CURLOPT_POSTFIELDS, json_encode($body));
	$out = curl_exec($c);
	$code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
	$err = curl_error($c);
	curl_close($c);
	if ($code < 200 || $code > 299)
		return array(null, "HTTP $code ".($err !== '' ? $err : trim((string)$out)));
	return array($out === '' ? array() : json_decode($out, true), '');
}

/* zone-file escapes inside one string: \DDD = that byte, \X = X */
function unescape($s) {
	return preg_replace_callback('/\\\\(\d{3}|.)/s', function ($x) {
		return strlen($x[1]) === 3 ? chr(min(255, (int)$x[1])) : $x[1];
	}, $s);
}

/* the record's content with the leftover escapes taken out of each string, or null if it has none */
function fixed($content) {
	if (!preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/s', $content, $m) || !$m[1])
		return null;
	$out = array();
	$changed = false;
	foreach ($m[1] as $raw) {
		$text = unescape($raw);                                   // what is served now
		if (preg_match('/\\\\(?:[;@ "\\\\]|\d{3})/', $text)) {    // a backslash left from the zone file
			$text = unescape($text);
			$changed = true;
		}
		$out[] = '"'.str_replace(array('\\', '"'), array('\\\\', '\\"'), $text).'"';
	}
	return $changed ? implode(' ', $out) : null;
}

list($zones, $e) = pdns('GET', '/zones');
if ($e !== '')
	die("PowerDNS: $e\n");
$backup = '/root/reqad-txt-fix-'.date('Ymd-His').'.json';
$n_sets = $n_recs = $n_zones = $failed = 0;
foreach ($zones as $z) {
	if ($only !== '' && strtolower($z['name']) !== $only)
		continue;
	list($zone, $e) = pdns('GET', '/zones/'.rawurlencode($z['id']).'?rrsets=true');
	if ($e !== '') {
		echo "{$z['name']}: could not read it ($e)\n";
		$failed++;
		continue;
	}
	$patch = array();
	foreach ($zone['rrsets'] as $rr) {
		if ($rr['type'] !== 'TXT' && $rr['type'] !== 'SPF')
			continue;
		$recs = array();
		$hit = 0;
		foreach ($rr['records'] as $rec) {
			$f = fixed($rec['content']);
			if ($f !== null) {
				$hit++;
				echo "{$rr['name']} {$rr['type']}\n   was: ".substr($rec['content'], 0, 90)."\n   now: ".substr($f, 0, 90)."\n";
			}
			$recs[] = array('content' => $f !== null ? $f : $rec['content'], 'disabled' => !empty($rec['disabled']));
		}
		if ($hit) {
			$patch[] = array('name' => $rr['name'], 'type' => $rr['type'], 'ttl' => $rr['ttl'], 'changetype' => 'REPLACE', 'records' => $recs, 'old' => $rr['records']);
			$n_recs += $hit;
		}
	}
	if (!$patch)
		continue;
	$n_zones++;
	$n_sets += count($patch);
	if (!$apply)
		continue;
	file_put_contents($backup, json_encode(array('zone' => $z['name'], 'rrsets' => array_map(function ($p) {
		return array('name' => $p['name'], 'type' => $p['type'], 'ttl' => $p['ttl'], 'records' => $p['old']);
	}, $patch)))."\n", FILE_APPEND);
	chmod($backup, 0600);
	list(, $e) = pdns('PATCH', '/zones/'.rawurlencode($z['id']), array('rrsets' => array_map(function ($p) { unset($p['old']); return $p; }, $patch)));
	if ($e !== '') {
		echo "{$z['name']}: NOT changed ($e)\n";
		$failed++;
	} else
		echo "{$z['name']}: fixed\n";
}

echo "\n$n_recs record(s) in $n_sets set(s) of $n_zones zone(s) ".($apply ? 'fixed' : 'to fix (nothing changed; run again with --apply)')
     .($failed ? ", $failed zone(s) failed" : '').".\n";
if ($apply && $n_zones)
	echo "Backup of the old records: $backup\n";
exit($failed ? 1 : 0);
