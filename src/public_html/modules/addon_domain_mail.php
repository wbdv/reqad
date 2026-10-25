<?php
/* Turn email on or off for an addon domain: domain_mail_enable() /
   domain_mail_disable() in app/functions/domains.php do the work (also for
   the API, the account panel and `reqad email site`). A Let's Encrypt
   certificate is issued again to add or drop mail.<domain>. */

$domain = strtolower(trim($_POST["domain"] ?? ''));
$state  = $_POST["state"] ?? '';
$addon  = addon_domain_get($ini, $domain);

if ($addon === null)
	$r = result_error("'$domain' is not an addon domain on this server.");
elseif ($state !== 'on' && $state !== 'off')
	$r = result_error('Choose on or off.');
else {
	$r = $state === 'on' ? domain_mail_enable($addon['user'], $domain) : domain_mail_disable($addon['user'], $domain);
	if ($r['ok'] && !empty($r['data']['reissue'])) {
		$c = ssl_site_issue($addon['user'], $domain);
		$r['warnings'][] = $c['ok'] ? $c['message'] : 'The certificate was not issued again: '.$c['error'];
	}
}

/* Post/Redirect/Get: carry the message via the queue and 302 to the GET page
   so a browser refresh does not re-submit the form. */
$msg_base = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/addon-domains/';
if (!$r['ok'])
	msg_redirect($msg_base, 'Error: '.$r['error'], 'error');
else
	msg_redirect($msg_base, trim($r['message'].' '.implode(' ', array_filter($r['warnings'], 'strlen'))), $r['warnings'] ? 'warning' : 'success');

?>
