#!/usr/bin/php82
<?php
/* Dump a domain's DNS zone to a JSON file using the configured DNS provider.
 * Called by scripts/backup.sh. Provider-agnostic: uses export_zone_records()
 * (each api_<provider>.php implements it; returns normalized records).
 *
 * Usage: dump_dns_zone.php --domain=example.com --out=/path/zone.json [--email-only]
 *   --email-only  keep only mail-related records (MX + TXT spf/_domainkey/_dmarc)
 */
if(php_sapi_name() != 'cli') { exit(1); }

$opts   = getopt('', ['domain:', 'out:', 'email-only']);
$domain = isset($opts['domain']) ? trim($opts['domain']) : '';
$out    = isset($opts['out'])    ? trim($opts['out'])    : '';
$emailOnly = isset($opts['email-only']);
if($domain == '' || $out == '') {
	fwrite(STDERR, "Usage: dump_dns_zone.php --domain=D --out=FILE [--email-only]\n");
	exit(1);
}

require_once('/usr/local/reqad/public_html/defines.php');
require_once('/usr/local/reqad/public_html/modules/functions.php');

$settings = array();
$res = $db->query('SELECT name,value FROM settings');
while($row = $res->fetchArray()) $settings[$row['name']] = $row['value'];

$provider     = isset($settings['dns-provider']) ? $settings['dns-provider'] : '';
$providerFile = '/usr/local/reqad/public_html/modules/api_'.preg_replace('/[^a-z]/', '', $provider).'.php';
if($provider == '' || !is_file($providerFile))
	$providerFile = '/usr/local/reqad/public_html/modules/api_none.php';
require_once($providerFile);

if(!function_exists('export_zone_records')) {
	fwrite(STDERR, "provider '$provider' has no export_zone_records()\n");
	exit(2);
}

$records = export_zone_records($domain);
if(!is_array($records)) $records = array();

if($emailOnly) {
	$records = array_values(array_filter($records, function($r) {
		$t = strtoupper($r['type']);
		$n = strtolower($r['name']);
		if($t == 'MX') return true;
		if($t == 'TXT') {
			if($n == '@' && stripos($r['content'], 'v=spf1') !== false) return true;   // SPF
			if(strpos($n, '_domainkey') !== false) return true;                          // DKIM
			if($n == '_dmarc') return true;                                              // DMARC
		}
		return false;
	}));
}

$payload = array(
	'domain'      => $domain,
	'provider'    => $provider,
	'exported_at' => date('c'),
	'records'     => $records,
);
if(file_put_contents($out, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
	fwrite(STDERR, "could not write $out\n");
	exit(3);
}
fwrite(STDOUT, count($records)." DNS records dumped to $out\n");
exit(0);
