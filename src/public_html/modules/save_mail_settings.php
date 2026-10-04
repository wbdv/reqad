<?php
/* Save the curated exim/dovecot settings form.

   Only whitelisted keys are accepted, and the form posts the key NAME — never a
   path or a raw config line. mail_settings_apply() validates each value against
   its definition, rewrites the file in memory and then hands the whole thing to
   apply_mail_config(), so a settings change goes through exactly the same
   backup / preflight / write / re-test / revert path as the raw editor. */

$which = isset($_POST['which']) ? (string)$_POST['which'] : '';
if (!mail_config_target($which))
	msg_redirect('/email/', 'Error: unknown configuration file.', 'error');

$posted = array();
foreach (mail_setting_keys($which) as $key => $def) {
	if (!empty($def['type']) && $def['type'] === 'toggle') {
		/* an unchecked checkbox posts nothing, which is what "no" looks like —
		   but only for the group that was actually submitted */
		if (!isset($_POST['_group_'.$key])) continue;
		$posted[$key] = isset($_POST[$key]) ? 'yes' : 'no';
		continue;
	}
	if (isset($_POST[$key])) $posted[$key] = $_POST[$key];
}

$r = mail_settings_apply($which, $posted);

error_log(date('Y-m-d H:i:s').' '.$_SERVER['REMOTE_ADDR'].' '.$_SERVER['USER']
        .' save mail settings ['.$which.'] '.($r['error'] === '' ? 'ok' : 'FAILED')."\n", 3, '../log/route_log');

/* Back to the tab the form was on. Whitelisted, because it is pasted into a
   Location header. */
$tab = isset($_POST['tab']) ? clean($_POST['tab']) : '';
if (!in_array($tab, array('limits'), true)) $tab = '';

msg_redirect('/email-config/'.$which.'/'.($tab !== '' ? '?tab='.$tab : ''),
             $r['error'] !== '' ? $r['error'] : $r['success'],
             $r['error'] !== '' ? 'error' : 'success');
