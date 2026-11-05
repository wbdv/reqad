<?php
/* Redirect a whole addon domain to another address (usually the account's
   main domain), or stop redirecting it. The rule is a site-wide redirect of
   redirect_add() / redirect_delete() in app/functions/domains.php (the same
   ones the API and the account panel use): every other redirect of the whole
   site (such as www to the bare domain) is replaced by it, and put back when
   the new one cannot be added. Redirects of single addresses stay. */

$domain = strtolower(trim($_POST["domain"] ?? ''));
$op     = $_POST["op"] ?? 'set';
$addon  = addon_domain_get($ini, $domain);

if ($addon === null)
	$r = result_error("'$domain' is not an addon domain on this server.");
elseif (!($site = domain_site($addon['user'], $domain)))
	$r = result_error("$domain is not a site of {$addon['user']}.");
else {
	$user = $addon['user'];
	$orig = redirect_rules_parse(account_vhost_file_read(redirect_vhost($site)));
	$whole = array_values(array_filter($orig, function ($x) { return $x['from'] === '/'; }));
	if ($op === 'remove') {
		if (!$whole)
			$r = result_error("$domain is not redirected.");
		else
			foreach ($whole as $w) {
				$r = redirect_delete($user, $domain, '/', 'prefix', $w['host']);
				if (!$r['ok'])
					break;
			}
		if ($r['ok'])
			$r = result_ok("$domain is no longer redirected.");
	}
	else {
		$r = result_ok('');
		foreach ($whole as $w) {
			$r = redirect_delete($user, $domain, '/', 'prefix', $w['host']);
			if (!$r['ok'])
				break;
		}
		if ($r['ok']) {
			$r = redirect_add($user, array('domain' => $domain, 'from' => '/', 'to' => trim($_POST['to'] ?? ''),
			                               'code' => (int)($_POST['code'] ?? 301), 'match' => 'prefix',
			                               'keep' => !empty($_POST['keep']), 'www' => 'any'));
			/* the old redirects back as they were */
			if (!$r['ok'] && $whole)
				redirect_save($site, $orig);
		}
	}
}

/* Post/Redirect/Get: carry the message via the queue and 302 to the GET page
   so a browser refresh does not re-submit the form. */
$msg_base = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/addon-domains/';
if (!$r['ok'])
	msg_redirect($msg_base, 'Error: '.$r['error'], 'error');
else
	msg_redirect($msg_base, $r['message'], 'success');

?>
