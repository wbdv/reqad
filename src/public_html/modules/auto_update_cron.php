<?php
/* Update time modal on /settings/ — when the nightly Reqad self-update
 * (scripts/auto_update.sh) runs. On/off is the switch on the page itself and is
 * saved with the main form (settings.php); this only stores the time, and
 * rewrites the /etc/crontab line when the update is currently on.
 *
 * Unsaved changes in the main form come along as main[...] and are saved after
 * this by settings_modal_redirect().
 */

$msg_base = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/settings/';

$st = auto_update_status();
if (!$st['installed'])
	settings_modal_redirect($msg_base, 'Reqad is not installed from its RPM package on this server, so it cannot update itself.', 'error');

$hour = trim((string)($_POST['hour'] ?? ''));
$min  = trim((string)($_POST['min']  ?? ''));

/* once a night, so plain numbers only — no ranges or steps */
if (!ctype_digit($hour) || (int)$hour > 23 || !ctype_digit($min) || (int)$min > 59)
	settings_modal_redirect($msg_base, 'Enter an hour between 0 and 23 and a minute between 0 and 59.', 'error');

setting_put('auto-update-time', (int)$hour.':'.(int)$min);
$at = sprintf('%02d:%02d', (int)$hour, (int)$min);

/* on/off once this submit is done: an unsaved flip of the switch comes along
   with the main form, and settings_main_save() applies it — turning on writes
   the entry with the time just stored, turning off strips it */
$on = (isset($_POST['main']) && is_array($_POST['main'])) ? isset($_POST['main']['auto_update']) : $st['on'];

if (!$on)
	settings_modal_redirect($msg_base, 'Update time set to '.$at.'. It applies once automatic Reqad updates are turned on.', 'success');
if (!$st['on'])
	settings_modal_redirect($msg_base, 'Reqad now updates every night at '.$at.'.', 'success');

$err = auto_update_cron_write(true, $hour, $min);
if ($err !== '')
	settings_modal_redirect($msg_base, $err, 'error');
settings_modal_redirect($msg_base, 'Reqad now updates every night at '.$at.'.', 'success');
