<?php
/* Save the backup server credentials (and optionally test them).
   POST-only, dispatched from index.php's $allowed_actions. */

$errmsg = ''; $successmsg = '';
$msg_base = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/backup/?tab=remote';

$host  = trim((string)($_POST['host']  ?? ''));
$user  = trim((string)($_POST['user']  ?? ''));
$port  = trim((string)($_POST['port']  ?? ''));
$key   = trim((string)($_POST['key']   ?? ''));
$dest  = trim((string)($_POST['dest']  ?? ''));
$dbmax = trim((string)($_POST['dbmax'] ?? ''));

/* Everything here ends up inside an ssh command line, so it is whitelisted
   rather than escaped-and-hoped: a hostname, a unix user, a port, and two
   paths. None of these charsets contain a quote, $ or backtick. */
if (!preg_match('/^[A-Za-z0-9._:-]{1,255}$/', $host))       $errmsg = 'That server name is not valid.';
elseif (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $user))     $errmsg = 'That ssh user name is not valid.';
elseif (!ctype_digit($port) || (int)$port < 1 || (int)$port > 65535) $errmsg = 'That port is not valid.';
elseif ($key !== '' && !preg_match('#^/[A-Za-z0-9._/-]{1,255}$#', $key)) $errmsg = 'The key must be an absolute path.';
elseif ($dest !== '' && !preg_match('#^[A-Za-z0-9._/-]{1,255}$#', $dest)) $errmsg = 'That remote directory is not valid.';
elseif ($dbmax !== '' && !ctype_digit($dbmax))              $errmsg = 'The database size limit must be a number of MB.';
elseif ($key !== '') {
	/* the panel connects as root, so root is who has to be able to read it */
	$rc = 0; exec('sudo -n test -r '.escapeshellarg($key), $o, $rc);
	if ($rc !== 0) $errmsg = 'The key file '.$key.' does not exist or root cannot read it.';
}

if ($errmsg === '') {
	setting_put('backup-remote-host',  $host);
	setting_put('backup-remote-user',  $user);
	setting_put('backup-remote-port',  $port);
	setting_put('backup-remote-key',   $key);
	setting_put('backup-remote-dest',  $dest);
	if ($dbmax !== '') setting_put('backup-remote-dbmax', $dbmax);
	@unlink(_PATH.'/log/remote_backup.cache');      /* the listing may now differ */
	$successmsg = 'Backup server settings saved.';

	/* a custom ssh port is usually missing from csf's TCP_OUT */
	$fw_note = csf_open_tcp_out($port) === 'updated' ? ' Outbound port '.$port.' opened in the csf firewall.' : '';

	if (isset($_POST['test'])) {
		$cfg = remote_backup_config();
		$rc  = 0;
		$out = remote_backup_ssh($cfg, 'echo reqad-ok; pwd; df -Pk . | tail -1', $rc);
		if ($rc === 0 && strpos($out, 'reqad-ok') !== false) {
			$lines = explode("\n", trim($out));
			$successmsg = 'Connected to '.$user.'@'.$host.' — backups go to '.
			              ($dest !== '' ? $dest : trim((string)($lines[1] ?? '')));
		} else {
			$errmsg = 'Settings saved, but the connection failed: '.trim($out);
		}
	}
}

if ($errmsg === '') $successmsg .= $fw_note;
if ($errmsg !== '') msg_redirect($msg_base, $errmsg, 'error');
msg_redirect($msg_base, $successmsg, 'success');
