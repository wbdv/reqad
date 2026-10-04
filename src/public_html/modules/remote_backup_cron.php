<?php
/* Turn the nightly remote backup on or off.
 *
 * Writes /etc/crontab with the same mechanics as create_cron.php and
 * delete_cron.php (sudo grep -vF | sudo tee, never a rewrite), so the entry is
 * also visible and removable on the Cron page rather than living in a second,
 * hidden place. /etc/cron.d/reqad is deliberately NOT touched: that file is an
 * RPM %config and an update would fight us for it.
 */

$errmsg = ''; $successmsg = '';
$msg_base = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/backup/?tab=remote';

$enabled = isset($_POST['enabled']);
$hour = trim((string)($_POST['hour'] ?? '2'));
$min  = trim((string)($_POST['min']  ?? '15'));
$keep = trim((string)($_POST['keep'] ?? '4'));
/* "Notify on errors" is on by default. Without a contact email the switch is
   disabled, so never posted — that is not the admin switching it off, and
   saving '0' then would keep it off once an address is set */
$contact = setting_get('email');
$notify  = isset($_POST['notify']) && $contact !== '';

/* same schedule charset create_cron.php accepts */
if (!preg_match('/^[\d\*\/\-\,]{1,32}$/', $hour) || !preg_match('/^[\d\*\/\-\,]{1,32}$/', $min))
	$errmsg = 'That schedule is not valid.';
elseif (!ctype_digit($keep) || (int)$keep < 1 || (int)$keep > 9)
	$errmsg = 'Keep must be a number from 1 to 9.';

if ($errmsg === '') {
	setting_put('backup-remote-keep', $keep);
	/* not in the cron line: backup_remote.sh reads this setting and the contact
	   email at run time, so schedules saved before the switch existed notify too */
	if ($contact !== '')
		setting_put('backup-remote-notify', $notify ? '1' : '0');

	$script = '/usr/local/reqad/scripts/backup_remote.sh';
	$line   = $min.' '.$hour.' * * * root '.$script
	        . ((int)$keep > 0 ? ' --keep '.(int)$keep : '')
	        . ' >> /usr/local/reqad/log/backup_remote.log 2>&1';

	/* drop any existing entry first, so a schedule change replaces rather than
	   accumulates; grep -vF because the line contains no regex metacharacters
	   worth honouring and we want a literal match */
	/* The scratch file goes in /etc, not /tmp: delete_cron.php uses a fixed
	   /tmp path, and any local user can pre-create that name as a symlink and
	   have us copy it over /etc/crontab. /etc is root-only, and mv is atomic.
	   grep -v exits 1 only when it selects nothing, which cannot happen here —
	   and the && then stops us installing an empty crontab anyway. */
	$rc = 0;
	$strip = 'grep -vF '.escapeshellarg($script).' /etc/crontab > /etc/crontab.reqad_new'
	       . ' && mv -f /etc/crontab.reqad_new /etc/crontab';
	exec('sudo -n bash -c '.escapeshellarg($strip), $o, $rc);
	if ($rc !== 0) {
		$errmsg = 'Could not update /etc/crontab.';
	} elseif ($enabled) {
		exec('echo '.escapeshellarg($line).' | sudo -n tee --append /etc/crontab > /dev/null', $o2, $rc2);
		if ($rc2 !== 0) $errmsg = 'Could not write the cron entry.';
		else $successmsg = 'Nightly remote backup enabled at '.sprintf('%02d:%02d', (int)$hour, (int)$min).'.'
		                 . ($notify ? ' Errors will be emailed to '.$contact.'.' : '');
	} else {
		$successmsg = 'Nightly remote backup disabled.';
	}
	exec('sudo -n chmod 644 /etc/crontab; sudo -n chown root:root /etc/crontab');
}

if ($errmsg !== '') msg_redirect($msg_base, $errmsg, 'error');
msg_redirect($msg_base, $successmsg, 'success');
