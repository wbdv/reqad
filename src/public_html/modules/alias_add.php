<?php
/* Add an alias domain to an account. POST: user, alias.
   Inserts the alias row and rewrites the account's vhost server_name.
   Cert extension (standard SAN / wildcard DNS-01) is handled in a later step. */

$acct_user = preg_replace('/[^a-z0-9]/', '', trim($_POST['user'] ?? ''));
$alias     = strtolower(trim($_POST['alias'] ?? ''));
$add_dns   = isset($_POST['add_dns']) && $_POST['add_dns'] == 'on';

$is_apache = (substr(trim($ini['template'] ?? ''), 0, 7) == 'apache_');
$errmsg = '';

/* DNS provider (for optional record creation). */
$settings = array();
$res = $db->query('SELECT name,value FROM settings');
while($res && $r = $res->fetchArray()) $settings[$r['name']] = $r['value'];
$dns_provider = isset($settings['dns-provider']) ? $settings['dns-provider'] : '';

/* Load the account. */
$acct = false;
if($acct_user !== '') {
	$res = $db->query('SELECT id, user, domain FROM accounts WHERE user="'.$db->escapeString($acct_user).'"');
	$acct = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;
}
if(!$acct)
	$errmsg = 'Error: Account not found.';

if($errmsg === '') {
	$v = alias_validation_error($db, $acct['domain'], $alias);
	if($v !== '')
		$errmsg = 'Error: '.$v;
}

if($errmsg === '') {
	$is_wild = (substr($alias, 0, 2) === '*.') ? 1 : 0;
	$stmt = $db->prepare('INSERT INTO aliases (account_id, alias, is_wildcard, ssl_status, created_at) VALUES (:aid, :alias, :wild, "none", datetime("now"))');
	$stmt->bindValue(':aid',   (int)$acct['id'], SQLITE3_INTEGER);
	$stmt->bindValue(':alias', $alias,           SQLITE3_TEXT);
	$stmt->bindValue(':wild',  $is_wild,         SQLITE3_INTEGER);
	$stmt->execute();

	/* Reflect the new alias in the live vhost server_name and reload. */
	$vh = apply_account_vhost_names($db, $acct['domain'], $is_apache);
	if($vh !== '')
		$errmsg = 'Error: alias saved but web server update failed: '.$vh;

	/* Optionally create the DNS record via the configured provider. */
	if($errmsg === '' && $add_dns && $dns_provider !== '' && $dns_provider !== null) {
		if($dns_provider == 'cloudflare')      include_once(__DIR__.'/api_cloudflare.php');
		else if($dns_provider == 'cpanel')     include_once(__DIR__.'/api_cpanel.php');
		else if($dns_provider == 'powerdns')   include_once(__DIR__.'/api_powerdns.php');
		else                                   include_once(__DIR__.'/api_none.php');

		if(function_exists('add_alias_in_dns')) {
			$server_ip = trim(shell_exec("/usr/sbin/ip address show | grep 'scope global' | grep 'inet ' | head -n 1 | awk {'print \$2'} | awk -F/ {'print \$1'}"));
			$dnserr = add_alias_in_dns($alias, $server_ip);
			if($dnserr !== '' && $dnserr !== null)
				$errmsg = 'Error: alias added, but DNS record failed: '.preg_replace('/^Error:\s*/', '', $dnserr);
		}
	}

	/* If the account already uses Let's Encrypt, extend the cert in the background.
	   A standard alias goes via HTTP-01; a wildcard triggers the DNS-01 re-issue.
	   reissue_letsencrypt_cert() picks the right path based on the alias set. */
	if($errmsg === '') {
		$he = $db->querySingle('SELECT has_email FROM accounts WHERE user="'.$db->escapeString($acct_user).'"');
		$rc = reissue_letsencrypt_cert($db, $acct['domain'], !empty($he));
		if($rc === 'no-dns' && (substr($alias, 0, 2) === '*.'))
			$errmsg = 'Notice: wildcard alias added, but wildcard SSL needs a DNS provider (configure one in DNS Settings).';
	}

	log_debug('[alias_add] '.$alias.' -> '.$acct['domain'].' ('.$acct['user'].') dns='.($add_dns?'1':'0'));
}

$msg_base = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/account/'.$acct_user.'/';
if($errmsg !== '')
	msg_redirect($msg_base, $errmsg, 'error');
else
	msg_redirect($msg_base, 'Alias '.$alias.' added.', 'success');
?>
