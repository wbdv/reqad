<?php
/* Save an account's web-server or php-fpm config from the Advanced Config editor.
   POST: user, file ('nginx'|'fpm'), content.
   The file path is resolved server-side from the account + a whitelisted key —
   never from a client path. apply_account_config() validates before writing,
   backs up the previous version (last 5 kept), and reverts on failure. */

$acct_user = preg_replace('/[^a-z0-9]/', '', trim($_POST['user'] ?? ''));
$which     = (($_POST['file'] ?? '') === 'fpm') ? 'fpm' : 'nginx';   // enum whitelist
$content   = (string)($_POST['content'] ?? '');

$errmsg = '';
$successmsg = '';

$acct = false;
if($acct_user !== '') {
	$res = $db->query('SELECT domain FROM accounts WHERE user="'.$db->escapeString($acct_user).'"');
	$acct = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;
}
if(!$acct)
	$errmsg = 'Error: Account not found.';

if($errmsg === '') {
	$r = apply_account_config($ini, $acct_user, $acct['domain'], $which, $content);
	if($r['error'] !== '') $errmsg = 'Error: '.$r['error'];
	else                   $successmsg = $r['success'];
}

$msg_base = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/account/'.$acct_user.'/?tab=config';
if($errmsg !== '')
	msg_redirect($msg_base, $errmsg, 'error');
else
	msg_redirect($msg_base, $successmsg, 'success');
?>
