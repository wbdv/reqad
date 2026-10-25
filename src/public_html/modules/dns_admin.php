<?php
/*
 * DNS Zones on 2087 (templates/dns.php + dist/js/dns-zone.js): one AJAX
 * action, ajax-dns, with op = what to do. JSON out, the result arrays of
 * app/functions/ (ok, message, warnings, error, data). The admin may change
 * every record but SOA (dns_admin_mode, app/functions/dns.php); the zone
 * list, check, hints and zone files are app/functions/dns_tools.php, the
 * mail provider / SPF / DMARC helpers of a zone that belongs to an account
 * are the account panel's own (app/functions/email_tools.php), run for that
 * account. Included by modules/ajax.php (POST only, CSRF checked).
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
dns_admin_mode(true);

$dns_out = function (array $r) {
	echo json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
	exit;
};
$dns_rec = function ($a) {
	$a = is_array($a) ? $a : array();
	return array('name' => api_str($a, 'name', 253), 'type' => api_str($a, 'type', 10),
	             'value' => api_str($a, 'value', 4100), 'ttl' => (int)api_str($a, 'ttl', 7));
};
$P = $_POST;
$op = api_str($P, 'op', 20);
$zone = dns_zone_name(api_str($P, 'zone', 253));

if (!dns_managed() && $op !== 'zones')
	$dns_out(result_error('No DNS provider is set up on this server. Choose one under Settings > DNS Settings.'));
if (!in_array($op, array('zones', 'summary', 'create', 'import-new-preview', 'cluster-check', 'provider-test'), true) && !valid_domain($zone))
	$dns_out(result_error('Say which zone.'));
dns_provider_load($db);

/* the account a mail helper of this zone runs for, and its domain */
$dns_mail_site = function ($domain) use ($zone, $dns_out) {
	$d = strtolower(trim((string)$domain));
	$acct = scope_domain_owner($d);
	if ($acct === '' || dns_zone_for($d, array($zone => 1)) !== $zone
	    || (!in_array($d, array_column(domain_sites($acct, false), 'domain'), true) && (email_userdomains()[$d] ?? '') !== $acct))
		$dns_out(result_error("$d is not a site of a hosting account in this zone."));
	return array($acct, $d);
};

switch ($op) {
case 'zones':
	$dns_out(dns_zone_list());

case 'cluster-check':
	/* Settings > DNS Settings > Cluster, Check now: the check cron runs every 5 minutes (it keeps the result) */
	if (($why = dns_cluster_unavailable()) !== '')
		$dns_out(result_error($why));
	@set_time_limit(150);
	$dns_out(dns_cluster_health_collect(30, false));

case 'provider-test':
	/* Settings > DNS Settings > Provider: the saved PowerDNS answers (its version, how many zones) */
	if (setting_get('dns-provider') !== 'powerdns')
		$dns_out(result_error('PowerDNS is not the DNS provider.'));
	$url = get_server_url();
	if (substr($url, 0, 6) === 'Error:')
		$dns_out(result_error(trim(substr($url, 7)) ?: 'No answer.'));
	$info = powerdns_api($url, true);
	$zl = get_zones();
	$dns_out(result_ok('', array('version' => (string)($info['version'] ?? ''), 'zones' => is_array($zl) ? count(array_filter($zl, function ($z) {
		return !in_array($z['kind'] ?? '', array('Producer', 'Consumer'), true); })) : 0)));

case 'summary':
	$list = isset($P['zones']) && is_array($P['zones']) ? array_filter($P['zones'], 'is_string') : array();
	$dns_out(result_ok('', dns_zone_summary($list)));

case 'zone':
	$r = dns_zone_view('', $zone);
	if (!$r['ok'])
		$dns_out($r);
	$owner = dns_zone_owner($zone);
	$sets = dns_zone_sets($zone);
	$all = get_zones();
	$r['data'] += array('soa_fields' => setting_get('dns-provider') === 'powerdns' ? dns_zone_soa($zone) : null, 'account' => $owner, 'key' => (string)(dns_key_zone_map()[$zone]['name'] ?? ''), 'kind' => (string)($all[$zone]['kind'] ?? ''),
	                    'expected_ns' => dns_expected_nameservers(),
	                    'check' => is_array($sets) ? dns_zone_check($zone, $sets, $owner) : array(),
	                    'presets' => array(
	                        /* the record dialog's ready-made SPF / DKIM / DMARC */
	                        'senders' => array_map(function ($x) { return array('name' => $x['name'], 'include' => $x['include']); }, mail_spf_senders()),
	                        'ip' => $owner !== '' ? domain_site_ip($owner) : server_primary_ip(),
	                        'selector' => mail_dns_selector($zone),
	                        'key' => mail_dns_local_key($zone)));
	$dns_out($r);

case 'check':
	$sets = dns_zone_sets($zone);
	$dns_out(is_array($sets) ? result_ok('', array('check' => dns_zone_check($zone, $sets, dns_zone_owner($zone)))) : result_error($sets));

case 'preview':
	$old = isset($P['old']) && is_array($P['old']) ? $dns_rec($P['old']) : null;
	$dns_out(dns_record_preview('', $zone, $dns_rec($P['rec'] ?? array()), $old));

case 'add':
	$dns_out(dns_record_add('', $zone, $dns_rec($P['rec'] ?? array())));

case 'edit':
	$dns_out(dns_record_edit('', $zone, $dns_rec($P['old'] ?? array()), $dns_rec($P['rec'] ?? array())));

case 'delete':
	$dns_out(dns_record_delete('', $zone, $dns_rec($P['rec'] ?? array())));

case 'delete-many':
	$recs = isset($P['recs']) && is_array($P['recs']) ? array_map($dns_rec, $P['recs']) : array();
	$dns_out(dns_records_delete('', $zone, $recs));

case 'soa':
	/* the SOA card: Raise serial (no fields) or Edit */
	$b = isset($P['soa']) && is_array($P['soa']) ? $P['soa'] : array();
	$f = array();
	foreach (array('primary', 'contact', 'serial', 'refresh', 'retry', 'expire', 'minimum') as $k)
		if (isset($b[$k]) && is_scalar($b[$k]))
			$f[$k] = api_str($b, $k, 253);
	$dns_out(dns_zone_soa_set($zone, $f));

case 'fix':
	$dns_out(dns_zone_fix('', $zone, api_str($P, 'id', 12)));

case 'create':
	$dns_out(dns_zone_create($zone, api_str($P, 'ip', 45)));

case 'import-new-preview':
	$dns_out(dns_zonefile_preview_new($zone, (string)($P['text'] ?? '')));

case 'import-new':
	$recs = isset($P['recs']) && is_array($P['recs']) ? array_map($dns_rec, $P['recs']) : array();
	if (!$recs)
		$dns_out(result_error('Choose the records to import.'));
	$dns_out(dns_zone_import_new($zone, $recs));

case 'remove':
	if (api_str($P, 'confirm', 253) !== $zone)
		$dns_out(result_error('Type the name of the zone to remove it.'));
	$dns_out(dns_zone_delete($zone));

case 'import-preview':
	$dns_out(dns_zonefile_preview('', $zone, (string)($P['text'] ?? '')));

case 'import':
	$recs = isset($P['recs']) && is_array($P['recs']) ? array_map($dns_rec, $P['recs']) : array();
	$dns_out(dns_records_import('', $zone, $recs, !empty($P['replace']) && $P['replace'] !== 'false'));

case 'export':
	$dns_out(dns_zonefile_export($zone));

/* the account panel's mail helpers, for the account the zone belongs to */
case 'mail':
	$owner = dns_zone_owner($zone);
	if ($owner === '')
		$dns_out(result_ok('', array('providers' => array(), 'sites' => array())));
	$sites = array_values(array_filter(mail_provider_status($owner), function ($s) use ($zone) { return $s['zone'] === $zone; }));
	$dns_out(result_ok('', array('account' => $owner, 'sites' => $sites,
		'providers' => array_map(function ($p) { return array('name' => $p['name'], 'regions' => $p['regions'] ?? null, 'verify' => $p['verify'] ?? ''); }, mail_providers()))));

case 'mail-set':
	list($acct, $d) = $dns_mail_site(api_str($P, 'domain', 253));
	$b = isset($P['b']) && is_array($P['b']) ? $P['b'] : array();
	$mx = isset($b['mx']) && is_array($b['mx']) ? array_map('strval', array_filter($b['mx'], 'is_scalar')) : api_str($b, 'mx', 2000);
	$dns_out(mail_provider_set($acct, $d, array('provider' => api_str($b, 'provider', 20), 'region' => api_str($b, 'region', 30), 'mx' => $mx,
	                                           'spf' => api_str($b, 'spf', 253), 'verify' => api_str($b, 'verify', 1000)), (bool)api_bool($b, 'preview')));

case 'spf':
	list($acct, $d) = $dns_mail_site(api_str($P, 'domain', 253));
	$dns_out(mail_spf_get($acct, $d));

case 'spf-set':
	list($acct, $d) = $dns_mail_site(api_str($P, 'domain', 253));
	$b = isset($P['b']) && is_array($P['b']) ? $P['b'] : array();
	$dns_out(mail_spf_set($acct, $d, array('server' => api_bool($b, 'server') ?? true, 'mx' => (bool)api_bool($b, 'mx'),
	                                      'senders' => api_words($b, 'senders'), 'custom' => api_words($b, 'custom'), 'all' => api_str($b, 'all', 5)),
	                      (bool)api_bool($b, 'preview')));

case 'dmarc':
	list($acct, $d) = $dns_mail_site(api_str($P, 'domain', 253));
	$dns_out(mail_dmarc_get($acct, $d));

case 'dmarc-set':
	list($acct, $d) = $dns_mail_site(api_str($P, 'domain', 253));
	$b = isset($P['b']) && is_array($P['b']) ? $P['b'] : array();
	$dns_out(mail_dmarc_set($acct, $d, array('p' => api_str($b, 'p', 12), 'sp' => api_str($b, 'sp', 12), 'pct' => api_str($b, 'pct', 3),
	                                        'rua' => api_words($b, 'rua'), 'ruf' => api_words($b, 'ruf'), 'adkim' => api_str($b, 'adkim', 1), 'aspf' => api_str($b, 'aspf', 1)),
	                        (bool)api_bool($b, 'preview')));
}
$dns_out(result_error('Unknown request.'));
