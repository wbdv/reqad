#!/usr/bin/php82
<?php
/* Re-apply a DNS zone dumped by dump_dns_zone.php, using the configured provider.
 * Called by scripts/restore.sh. Provider-agnostic: uses import_zone_records().
 *
 * Usage: restore_dns_zone.php --domain=example.com --file=/path/zone.json
 */
if(php_sapi_name() != 'cli') { exit(1); }

$opts   = getopt('', ['domain:', 'file:']);
$domain = isset($opts['domain']) ? trim($opts['domain']) : '';
$file   = isset($opts['file'])   ? trim($opts['file'])   : '';
if($domain == '' || $file == '' || !is_file($file)) {
	fwrite(STDERR, "Usage: restore_dns_zone.php --domain=D --file=FILE\n");
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

$data    = json_decode(file_get_contents($file), true);
$records = (is_array($data) && isset($data['records']) && is_array($data['records'])) ? $data['records'] : array();
if(empty($records)) {
	fwrite(STDOUT, "no DNS records in $file, nothing to restore\n");
	exit(0);
}

if(!function_exists('import_zone_records')) {
	fwrite(STDERR, "provider '$provider' has no import_zone_records()\n");
	exit(2);
}

$error = '';
if(import_zone_records($domain, $records, $error)) {
	fwrite(STDOUT, "restored ".count($records)." DNS records for $domain via $provider\n");
	exit(0);
}
fwrite(STDERR, "DNS restore failed: ".$error."\n");
exit(3);
