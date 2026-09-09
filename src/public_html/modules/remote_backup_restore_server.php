<?php
/* Start a WHOLE-SERVER restore from the backup server, in the background.
 *
 * Mirrors remote_backup_restore.php: validate, apply the same guards the script
 * applies so a refusal is instant rather than a toast forty minutes later, then
 * nohup restore_server.sh with a messages.db token.
 *
 * The two guards are the point of this module. A full restore recreates every
 * account with its original uid/gid and unpacks the backup's /etc over this
 * box, so it may only run on a server that has no accounts, and only where the
 * install allows root-level operations at all.
 */

$errmsg = ''; $date = (string)($_POST['date'] ?? '');
$msg_base = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/backup/?tab=remote'
          . (valid_backup_date($date) ? '&date='.rawurlencode($date) : '');

/* none | safe | everything — anything else is not passed through to the shell */
$sysmode = (string)($_POST['system_files'] ?? 'safe');
if (!in_array($sysmode, array('none','safe','everything'), true)) $sysmode = 'safe';
$forcepkg = isset($_POST['force_packages']);

$allowed = restore_server_allowed($db, $ini);

if (!valid_backup_date($date))              $errmsg = 'Invalid backup date.';
elseif (!$allowed['ok'])                    $errmsg = $allowed['reason'];
elseif (($_POST['confirm'] ?? '') !== 'RESTORE')
                                            $errmsg = 'Type RESTORE to confirm the full server restore.';
else {
	$cfg = remote_backup_config();
	if (!remote_backup_configured($cfg)) $errmsg = 'No backup server is configured.';
}
if ($errmsg === '') {
	$st = restore_server_status();
	if ($st['running']) $errmsg = 'A full server restore is already running.';
}

if ($errmsg === '') {
	$token = bin2hex(random_bytes(8));
	$cmd = '/usr/local/reqad/scripts/restore_server.sh'
	     . ' --date '         . escapeshellarg($date)
	     . ' --system-files ' . escapeshellarg($sysmode)
	     . ($forcepkg ? ' --force-packages' : '')
	     . ' --token '        . $token
	     . ' >> /usr/local/reqad/log/restore_server.log 2>&1 &';
	shell_exec('nohup '.$cmd);
	msg_redirect($msg_base.'&restoremsg='.$token,
		'Full server restore from '.$date.' started. Every account is fetched and rebuilt one at a time, '
		.'so this runs for a long while — the progress panel updates on its own.', 'info');
}

msg_redirect($msg_base, $errmsg, 'error');
