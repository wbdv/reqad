<?php
/* Start a restore from the backup server, in the background.
 *
 * Mirrors modules/restore_backup.php: validate, do the cheap checks
 * synchronously so an obvious mistake is an instant error rather than a toast
 * forty minutes later, then nohup the script with a messages.db token and
 * redirect with ?restoremsg= so the page can poll for the result.
 */

$errmsg = ''; $successmsg = '';
$date = (string)($_POST['date'] ?? '');
$user = (string)($_POST['user'] ?? '');
$mode = (string)($_POST['mode'] ?? '');
$dbn  = (string)($_POST['db']   ?? '');

$msg_base = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/backup/?tab=remote'
          . (valid_backup_date($date) ? '&date='.rawurlencode($date) : '');

if (!valid_backup_date($date))                       $errmsg = 'Invalid backup date.';
elseif (!valid_username($user))                      $errmsg = 'Invalid account.';
elseif (!in_array($mode, array('account','public_html','database'), true))
                                                     $errmsg = 'Choose what to restore.';
elseif ($mode === 'database' && !valid_mysql_identifier($dbn))
                                                     $errmsg = 'Invalid database name.';
elseif ($mode === 'database' && !in_array(database_owner($dbn), array('', $user), true))
                                                     $errmsg = 'That database belongs to '.database_owner($dbn).', not '.$user.'.';

if ($errmsg === '') {
	/* the same guard the script applies, checked here so the answer is immediate */
	$rc = 0; exec('id -u '.escapeshellarg($user).' >/dev/null 2>&1', $o, $rc);
	$exists = ($rc === 0);
	if ($mode === 'account' && $exists)
		$errmsg = "Account '".$user."' still exists — restoring the whole account only rebuilds a deleted one.";
	elseif ($mode !== 'account' && !$exists)
		$errmsg = "Account '".$user."' does not exist here — restore the whole account first.";
}

if ($errmsg === '') {
	$token = bin2hex(random_bytes(8));
	$cmd = '/usr/local/reqad/scripts/restore_remote.sh'
	     . ' --mode '  . escapeshellarg($mode)
	     . ' --user '  . escapeshellarg($user)
	     . ' --date '  . escapeshellarg($date)
	     . ($mode === 'database' ? ' --db '.escapeshellarg($dbn) : '')
	     . ($mode === 'public_html' && isset($_POST['replace']) ? ' --replace' : '')
	     . ($mode === 'database'    && isset($_POST['drop'])    ? ' --drop'    : '')
	     . ' --token ' . $token
	     . ' >> /usr/local/reqad/log/restore_remote.log 2>&1 &';
	shell_exec('nohup '.$cmd);

	switch ($mode) {
		case 'account':
			$successmsg = "Restoring the whole account '".$user."' from the ".$date." backup. "
			            . "The archive is downloaded first, so this can take a while."; break;
		case 'public_html':
			$successmsg = "Restoring public_html for '".$user."' from the ".$date." backup."; break;
		default:
			$successmsg = "Restoring database '".$dbn."' from the ".$date." backup."; break;
	}
	msg_redirect($msg_base.'&restoremsg='.$token, $successmsg, 'info');
}

msg_redirect($msg_base, $errmsg, 'error');
