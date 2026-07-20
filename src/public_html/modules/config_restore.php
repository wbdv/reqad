<?php
/* Restore a previous version of an account's config from the version history.
   POST: user, file ('nginx'|'fpm'), version (backup id — a bare basename).
   The backup content is validated and promoted through the same write path as a
   normal save, so restoring also snapshots the current version first. */

$acct_user = preg_replace('/[^a-z0-9]/', '', trim($_POST['user'] ?? ''));
$which     = (($_POST['file'] ?? '') === 'fpm') ? 'fpm' : 'nginx';   // enum whitelist
$version   = (string)($_POST['version'] ?? '');

$errmsg = '';
$successmsg = '';

$acct = false;
if($acct_user !== '') {
	$res = $db->query('SELECT domain FROM accounts WHERE user="'.$db->escapeString($acct_user).'"');
	$acct = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;
}
if(!$acct)
	$errmsg = 'Error: Account not found.';

$target = null;
if($errmsg === '') {
	$target = account_config_target($ini, $acct['domain'], $which);
	if(!$target) $errmsg = 'Error: Unknown config file.';
}

$content = null;
if($errmsg === '') {
	$content = read_config_backup($acct_user, $which, $target['path'], $version);
	if($content === null)
		$errmsg = 'Error: that saved version could not be found.';
}

if($errmsg === '') {
	$r = apply_account_config($ini, $acct_user, $acct['domain'], $which, $content);
	if($r['error'] !== '') $errmsg = 'Error: '.$r['error'];
	else                   $successmsg = 'Restored: '.$r['success'];
}

$msg_base = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/account/'.$acct_user.'/?tab=config';
if($errmsg !== '')
	msg_redirect($msg_base, $errmsg, 'error');
else
	msg_redirect($msg_base, $successmsg, 'success');
?>
