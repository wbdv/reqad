<?php
/* certbot --manual DNS-01 hook for Reqad. Invoked by reissue_letsencrypt_cert_dns.sh:
     php certbot_dns_hook.php auth      -> create _acme-challenge TXT, wait for propagation
     php certbot_dns_hook.php cleanup   -> remove the TXT value
   certbot passes CERTBOT_DOMAIN (base domain, no leading "*.") and CERTBOT_VALIDATION
   in the environment. The wildcard and the apex share _acme-challenge.<domain> with
   different values, so add_update_txt() appends rather than replaces. */

include_once(__DIR__.'/../public_html/defines.php');
include_once(__DIR__.'/../public_html/modules/functions.php');

$mode = isset($argv[1]) ? $argv[1] : '';
$cdom = getenv('CERTBOT_DOMAIN');
$cval = getenv('CERTBOT_VALIDATION');
if($cdom === false || $cval === false || ($mode !== 'auth' && $mode !== 'cleanup')) {
	fwrite(STDERR, "certbot_dns_hook: usage 'auth|cleanup' with CERTBOT_DOMAIN/CERTBOT_VALIDATION set\n");
	exit(1);
}
$record = '_acme-challenge.'.strtolower(rtrim($cdom, '.'));

/* load the active DNS provider (same selection as ajax.php / alias_add.php) */
$settings = array();
$res = $db->query('SELECT name,value FROM settings');
while($res && $r = $res->fetchArray()) $settings[$r['name']] = $r['value'];
$provider = isset($settings['dns-provider']) ? $settings['dns-provider'] : '';
if($provider === 'cloudflare')   include_once(__DIR__.'/../public_html/modules/api_cloudflare.php');
elseif($provider === 'cpanel')   include_once(__DIR__.'/../public_html/modules/api_cpanel.php');
elseif($provider === 'powerdns') include_once(__DIR__.'/../public_html/modules/api_powerdns.php');
else                             include_once(__DIR__.'/../public_html/modules/api_none.php');

if(!function_exists('add_update_txt')) {
	fwrite(STDERR, "certbot_dns_hook: provider '".$provider."' has no add_update_txt()\n");
	exit(1);
}

if($mode === 'cleanup') {
	delete_txt($record, $cval);
	exit(0);
}

/* auth: publish the challenge, then wait until every authoritative NS answers it */
$err = add_update_txt($record, $cval);
if($err !== '') {
	fwrite(STDERR, "certbot_dns_hook: ".$err."\n");
	exit(1);
}

$needle = trim($cval, '"');
$zone   = implode('.', array_slice(explode('.', rtrim($cdom, '.')), -2));   // registrable domain
$ns_out = shell_exec('dig +short NS '.escapeshellarg($zone).' 2>/dev/null');
$nslist = array_values(array_filter(array_map('trim', explode("\n", (string)$ns_out))));

$deadline = time() + 180;
do {
	$seen = true;
	if(count($nslist)) {
		foreach($nslist as $ns) {
			$txt = (string)shell_exec('dig +short TXT '.escapeshellarg($record).' @'.escapeshellarg(rtrim($ns, '.')).' 2>/dev/null');
			if(strpos($txt, $needle) === false) { $seen = false; break; }
		}
	} else {
		$txt = (string)shell_exec('dig +short TXT '.escapeshellarg($record).' 2>/dev/null');
		$seen = (strpos($txt, $needle) !== false);
	}
	if($seen) break;
	sleep(5);
} while(time() < $deadline);

/* small extra settle so the ACME server's own resolver sees it too */
sleep(8);
exit(0);
