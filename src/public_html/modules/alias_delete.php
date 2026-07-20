<?php
/* Delete an alias domain from an account. POST: user, alias_id.
   Removes the alias row and rewrites the account's vhost server_name.
   (mail.<domain> is not an alias row, so it is never deletable here.) */

$acct_user = preg_replace('/[^a-z0-9]/', '', trim($_POST['user'] ?? ''));
$alias_id  = (int)($_POST['alias_id'] ?? 0);

$is_apache = (substr(trim($ini['template'] ?? ''), 0, 7) == 'apache_');
$errmsg = '';

/* DNS provider (for best-effort record removal). */
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

$alias = '';
if($errmsg === '') {
	/* The alias must belong to this account. */
	$res = $db->query('SELECT alias FROM aliases WHERE id='.$alias_id.' AND account_id='.(int)$acct['id']);
	$row = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;
	if(!$row)
		$errmsg = 'Error: Alias not found for this account.';
	else
		$alias = $row['alias'];
}

if($errmsg === '') {
	$db->query('DELETE FROM aliases WHERE id='.$alias_id.' AND account_id='.(int)$acct['id']);

	$vh = apply_account_vhost_names($db, $acct['domain'], $is_apache);
	if($vh !== '')
		$errmsg = 'Error: alias removed but web server update failed: '.$vh;

	/* Best-effort DNS record removal (never fails the delete, never removes a zone). */
	if($dns_provider !== '' && $dns_provider !== null) {
		if($dns_provider == 'cloudflare')      include_once(__DIR__.'/api_cloudflare.php');
		else if($dns_provider == 'cpanel')     include_once(__DIR__.'/api_cpanel.php');
		else if($dns_provider == 'powerdns')   include_once(__DIR__.'/api_powerdns.php');
		else                                   include_once(__DIR__.'/api_none.php');
		if(function_exists('delete_alias_from_dns'))
			delete_alias_from_dns($alias);
	}

	log_debug('[alias_delete] '.$alias.' from '.$acct['domain'].' ('.$acct['user'].')');
}

$msg_base = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/account/'.$acct_user.'/';
if($errmsg !== '')
	msg_redirect($msg_base, $errmsg, 'error');
else
	msg_redirect($msg_base, 'Alias '.$alias.' removed.', 'success');
?>
