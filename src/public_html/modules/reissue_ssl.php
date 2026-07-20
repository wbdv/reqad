<?php
/* Re-issue an account's Let's Encrypt certificate to cover its current standard
   aliases (and mail.<domain> when email is on). POST: user. Runs in the background. */

$acct_user = preg_replace('/[^a-z0-9]/', '', trim($_POST['user'] ?? ''));
$errmsg = '';

$acct = false;
if($acct_user !== '') {
	$res = $db->query('SELECT domain, has_email FROM accounts WHERE user="'.$db->escapeString($acct_user).'"');
	$acct = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;
}
if(!$acct)
	$errmsg = 'Error: Account not found.';

$successmsg = '';
if($errmsg === '') {
	$r = reissue_letsencrypt_cert($db, $acct['domain'], !empty($acct['has_email']));
	if($r === 'not-le')
		$errmsg = "Error: This account is not using a Let's Encrypt certificate.";
	else if($r === 'none')
		$errmsg = 'Error: '.$acct['domain'].' does not resolve to this server yet, so a certificate cannot be issued.';
	else if($r === 'no-dns')
		$errmsg = 'Error: this account has a wildcard alias, which needs DNS-01. Configure a DNS provider in DNS Settings first.';
	else if(account_has_wildcard_alias($db, $acct['domain']))
		$successmsg = 'Wildcard SSL re-issue started for '.$acct['domain'].' (DNS-01). This can take a couple of minutes.';
	else
		$successmsg = 'SSL certificate re-issue started for '.$acct['domain'].'. It updates within about a minute.';
	log_debug('[reissue_ssl] '.$acct['domain'].' -> '.$r);
}

$msg_base = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/account/'.$acct_user.'/';
if($errmsg !== '')
	msg_redirect($msg_base, $errmsg, 'error');
else
	msg_redirect($msg_base, $successmsg, 'success');
?>
