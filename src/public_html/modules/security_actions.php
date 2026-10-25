<?php
/*
 * Settings > Security actions (password, two-factor auth, sessions, blocked
 * IPs). Each modules/security_*.php action file calls security_action().
 *
 * Anything that weakens the login - changing the password, 2FA setup or off,
 * new recovery codes, opening the panel firewall, unblocking an IP - needs
 * "sudo mode": the password (and 2FA code when on) confirmed within the last
 * 10 minutes, at login or in the confirmation dialog (ajax-sudo). A session
 * left open or stolen is not enough. The page asks before submitting; the
 * check here is the one that counts.
 */

/* The principal this page manages: the logged-in admin, or on basic auth the
   first admin (imported from nginx_auth). */
function security_principal() {
	auth_bootstrap();
	if (auth_web_enabled()) {
		$s = auth_session_current();
		return $s ? auth_principal_by_id($s['principal_id']) : null;
	}
	$all = auth_admins();
	return $all ? $all[0] : null;
}

function security_post($name, $max = 256) {
	return isset($_POST[$name]) && is_string($_POST[$name]) ? substr($_POST[$name], 0, $max) : '';
}

/* Stop unless the session is in sudo mode (see the header comment). */
function security_need_sudo($back) {
	if (auth_sudo_left() <= 0)
		msg_redirect($back, 'Please confirm your password first - the '.auth_cfg('auth-sudo-minutes').'-minute confirmation has expired.', 'error');
}

function security_action($action) {
	global $security_new_codes;
	$base = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/security/';
	$p = security_principal();
	if (!$p)
		msg_redirect($base, 'No panel login exists yet - run "reqad auth passwd" on the server.', 'error');
	$current = auth_web_enabled() ? auth_session_current() : null;

	switch ($action) {
	case 'security-password':
		security_need_sudo($base);
		$new = security_post('new_password');
		if ($new !== security_post('new_password2'))
			msg_redirect($base, 'The new passwords do not match.', 'error');
		if (($e = auth_password_policy($new, $p['username'])) !== '')
			msg_redirect($base, $e, 'error');
		auth_password_set($p['id'], $new);
		auth_sessions_revoke_all($p['id'], $current ? $current['id'] : '');
		auth_log_event('password_changed', 'other sessions logged out', (int)$p['id'], $p['username']);
		msg_redirect($base, 'Password changed. Any other sessions were logged out.', 'success');

	case 'security-2fa-start':
		if (!empty($_POST['cancel'])) {
			auth_q("UPDATE auth_principals SET totp_pending = '' WHERE id = :id", array('id' => (int)$p['id']));
			msg_redirect($base, 'Two-factor setup cancelled.', 'info');
		}
		if ((int)$p['totp_enabled'] === 1)
			msg_redirect($base, 'Two-factor authentication is already on.', 'info');
		security_need_sudo($base);
		auth_q('UPDATE auth_principals SET totp_pending = :s WHERE id = :id',
		       array('s' => auth_totp_new_secret(), 'id' => (int)$p['id']));
		msg_redirect($base, 'Scan the QR code with your authenticator app, then enter the code it shows.', 'info');

	case 'security-2fa-enable':
		if ($p['totp_pending'] === '')
			msg_redirect($base, 'Start the two-factor setup first.', 'error');
		$step = auth_totp_match($p['totp_pending'], security_post('code', 20));
		if (!$step)
			msg_redirect($base, 'That code does not match. Check that the time on your phone is correct and try again.', 'error');
		auth_q("UPDATE auth_principals SET totp_secret = totp_pending, totp_pending = '', totp_enabled = 1,
		        totp_last_step = :st WHERE id = :id", array('st' => $step, 'id' => (int)$p['id']));
		$security_new_codes = auth_recovery_generate($p['id']);
		auth_sessions_revoke_all($p['id'], $current ? $current['id'] : '');
		auth_log_event('2fa_enabled', '', (int)$p['id'], $p['username']);
		return;     // render the page with the recovery codes (shown once)

	case 'security-2fa-codes':
		if ((int)$p['totp_enabled'] !== 1)
			msg_redirect($base, 'Two-factor authentication is off.', 'error');
		security_need_sudo($base);
		$security_new_codes = auth_recovery_generate($p['id']);
		auth_log_event('2fa_codes_regenerated', '', (int)$p['id'], $p['username']);
		return;

	case 'security-2fa-disable':
		if ((int)$p['totp_enabled'] !== 1)
			msg_redirect($base, 'Two-factor authentication is already off.', 'info');
		security_need_sudo($base);
		auth_totp_disable($p['id']);
		auth_log_event('2fa_disabled', '', (int)$p['id'], $p['username']);
		msg_redirect($base, 'Two-factor authentication is now off.', 'warning');

	case 'security-session-revoke':
		$id = security_post('session', 64);
		if (!preg_match('/^[0-9a-f]{64}$/', $id))
			msg_redirect($base, 'Unknown session.', 'error');
		$row = auth_row('SELECT principal_id FROM auth_sessions WHERE id = :id', array('id' => $id));
		if (!$row || (int)$row['principal_id'] !== (int)$p['id'])
			msg_redirect($base, 'Unknown session.', 'error');
		auth_session_revoke($id);
		auth_log_event('session_revoked', '', (int)$p['id'], $p['username']);
		if ($current && $current['id'] === $id) {
			auth_flash_set('revoked');
			auth_redirect('/login/');
		}
		msg_redirect($base, 'Session logged out.', 'success');

	case 'security-sessions-revoke-all':
		auth_sessions_revoke_all($p['id'], $current ? $current['id'] : '');
		auth_log_event('sessions_revoked', 'all other sessions', (int)$p['id'], $p['username']);
		msg_redirect($base, 'All other sessions were logged out.', 'success');

	case 'security-unban':
		$ip = security_post('ip', 64);
		if (!filter_var($ip, FILTER_VALIDATE_IP))
			msg_redirect($base, 'Not an IP address.', 'error');
		security_need_sudo($base);
		auth_unban($ip);
		msg_redirect($base, "$ip was unblocked.", 'success');

	case 'security-fw':
		security_fw_action($p, $base);

	case 'security-shell-default':
		$sh = security_post('shell', 8);
		if (!isset(account_shell_modes()[$sh]))
			msg_redirect($base, 'Unknown shell access.', 'error');
		setting_put('account-default-shell', $sh);
		auth_log_event('shell_default', $sh, (int)$p['id'], $p['username']);
		msg_redirect($base, 'New accounts get shell access: '.account_shell_modes()[$sh].'.', 'success');

	case 'security-shell-log':
		$on = security_post('op', 8);
		$days = (int)security_post('days', 4);
		if (!in_array($days, array(7, 30, 90, 180), true))
			msg_redirect($base, 'Keep logged commands 7, 30, 90 or 180 days.', 'error');
		/* turning it off hides what accounts do: confirm the password first */
		if ($on === 'off')
			security_need_sudo($base.'#shell-log');
		$args = ($on === 'on' ? 'on --days='.$days : ($on === 'off' ? 'off' : 'days '.$days));
		$out = (string)shell_exec('sudo -n '._PATH.'/bin/reqad shelllog '.$args.' --json 2>&1');
		$r = json_decode($out, true);
		if (!is_array($r) || !isset($r['ok']))
			$r = result_error(trim($out) !== '' ? trim($out) : 'Could not run reqad shelllog.');
		if ($r['ok'] && $on !== '')
			auth_log_event('shell_log', $on, (int)$p['id'], $p['username']);
		result_redirect($base.'#shell-log', $r);

	case 'security-device-forget':
		$id   = security_post('device', 12);
		$next = auth_email_code_on() ? 'it has to confirm its next login with an emailed code' : 'it counts as a new device at its next login';
		if ($id === 'all') {
			auth_devices_forget_all($p['id'], auth_device_current_id());
			auth_log_event('devices_forgotten', 'all other devices', (int)$p['id'], $p['username']);
			msg_redirect($base, 'Every other device was forgotten: '.str_replace('it has', 'each has', str_replace('it counts', 'each counts', $next)).'.', 'success');
		}
		if (!ctype_digit($id))
			msg_redirect($base, 'Unknown device.', 'error');
		auth_device_forget((int)$id, $p['id']);
		auth_log_event('device_forgotten', '', (int)$p['id'], $p['username']);
		msg_redirect($base, 'Device forgotten: '.$next.'.', 'success');

	case 'security-email':
		security_email_action($p, $base, $current);

	case 'security-passkey':
		$op = security_post('op', 8);
		if ($op === 'on') {         // a new way in: needs sudo mode
			security_need_sudo($base);
			auth_setting_put('auth-passkeys', '1');
			auth_log_event('passkeys_on', '', (int)$p['id'], $p['username']);
			msg_redirect($base, 'Passkeys are on. Add one below - your browser or password manager will ask where to keep it.', 'success');
		}
		if ($op === 'off') {
			auth_setting_put('auth-passkeys', '0');
			auth_log_event('passkeys_off', '', (int)$p['id'], $p['username']);
			msg_redirect($base, 'Passkeys are off: none of them can log in now. They stay listed and work again if you turn this back on.', 'warning');
		}
		$id = security_post('passkey', 12);
		$row = ctype_digit($id) ? auth_row('SELECT * FROM auth_passkeys WHERE id = :id AND principal_id = :p',
		                                   array('id' => (int)$id, 'p' => (int)$p['id'])) : null;
		if (!$row)
			msg_redirect($base, 'Unknown passkey.', 'error');
		auth_passkey_remove($row['id'], $p['id']);
		auth_log_event('passkey_removed', $row['name'], (int)$p['id'], $p['username']);
		msg_redirect($base, 'Passkey "'.$row['name'].'" removed. Also delete it from the device or password manager that holds it.', 'success');
	}
}

/*
 * New-device checks (op = code-on | alert-on | alert-off | address | test |
 * verify | resend | cancel).
 *
 * The emailed code needs no switch: it is asked for whenever 2FA is off and
 * the panel can send email (auth_email_code_state). Only the command line
 * turns it off (break-glass); code-on undoes that. Login alerts are a switch.
 *
 * A new address is used once it has proved it receives mail from this
 * server: changing it (with a sending method set up), or turning on alerts,
 * first emails a code to it (challenge 'verify', bound to this session) and
 * the change is made when that code is entered in the dialog the page then
 * shows - so a typo cannot lock the admin out. 'test' does the same for the
 * current address. Turning alerts off and changing the address need
 * sudo mode.
 */
function security_email_action(array $p, $back, $current) {
	if (!$current)
		msg_redirect($back, 'This needs the web login (reqad auth mode web).', 'error');
	$op  = security_post('op', 16);
	$sid = $current['id'];

	/* start a verification of $email, then do $then ('code' | 'alert' | 'address') */
	$verify = function ($email, $then) use ($p, $back, $sid) {
		if (!smtp_configured())
			msg_redirect($back, 'Set up the sending method first (Settings > Sending method), so the panel can send email.', 'error');
		if (!filter_var($email, FILTER_VALIDATE_EMAIL))
			msg_redirect($back, 'Enter a valid email address first.', 'error');
		auth_challenge_drop($sid, 'verify');
		$err = auth_challenge_send($sid, 'verify', $email, json_encode(array('email' => $email, 'then' => $then)));
		if ($err !== '')
			msg_redirect($back, $err, 'error');
		auth_log_event('email_verify_sent', $email, (int)$p['id'], $p['username']);
		header('Location: '.$back.'#new-devices');     // the page opens the code dialog
		exit;
	};
	$verified = auth_setting('auth-email-verified', '');

	switch ($op) {
	case 'code-on':     // undo `reqad auth email-code off`
		auth_q("DELETE FROM settings WHERE name = 'auth-email-code'");
		auth_log_event('email_code_on', '', (int)$p['id'], $p['username']);
		auth_device_seen($p);
		msg_redirect($back, 'The email code is back on for new devices. This browser is already known.', 'success');

	case 'alert-on':
		if ($p['email'] === '' || $p['email'] !== $verified)
			$verify($p['email'], 'alert');
		security_email_apply($p, 'alert');
		msg_redirect($back, security_email_done('alert'), 'success');

	case 'alert-off':
		security_need_sudo($back);
		auth_setting_put('auth-login-alert', '0');
		auth_log_event('login_alert_off', '', (int)$p['id'], $p['username']);
		msg_redirect($back, 'Login alerts are off.', 'warning');

	case 'test':
		$verify($p['email'], 'address');

	case 'address':
		$email = strtolower(trim(security_post('email', 254)));
		if (!filter_var($email, FILTER_VALIDATE_EMAIL))
			msg_redirect($back, 'That is not a valid email address.', 'error');
		security_need_sudo($back);
		if ($email === $p['email'])
			msg_redirect($back, 'That is already the address.', 'info');
		if (smtp_configured() && $email !== $verified)
			$verify($email, 'address');         // codes keep going to the old address until then
		auth_q('UPDATE auth_principals SET email = :e WHERE id = :id', array('e' => $email, 'id' => (int)$p['id']));
		auth_log_event('email_changed', $email, (int)$p['id'], $p['username']);
		msg_redirect($back, 'Email address saved.', 'success');

	case 'verify':
		$r = auth_challenge_check($sid, 'verify', security_post('code', 20));
		if ($r === 'wrong')
			msg_redirect($back, 'That code is not right. Use the code from the latest email.', 'error');
		if (!is_array($r))
			msg_redirect($back, $r === 'locked' ? 'Too many wrong codes. Start again.' : 'That code has expired. Start again.', 'error');
		$t = json_decode($r['target'], true);
		if (!is_array($t) || !isset($t['email'], $t['then']))
			msg_redirect($back, 'Start again.', 'error');
		auth_q('UPDATE auth_principals SET email = :e WHERE id = :id', array('e' => $t['email'], 'id' => (int)$p['id']));
		auth_setting_put('auth-email-verified', $t['email']);
		auth_log_event('email_verified', $t['email'], (int)$p['id'], $p['username']);
		$p['email'] = $t['email'];
		security_email_apply($p, $t['then']);
		msg_redirect($back, 'Email address confirmed. '.security_email_done($t['then']), 'success');

	case 'resend':
		$c = auth_challenge($sid, 'verify');
		$t = $c ? json_decode($c['target'], true) : null;
		if (!$c || !is_array($t))
			msg_redirect($back, 'Start again.', 'error');
		if (($err = auth_challenge_send($sid, 'verify', $t['email'])) !== '')
			msg_redirect($back, $err, 'error');
		msg_redirect($back, 'A new code was sent to '.$t['email'].'.', 'info');

	case 'cancel':
		auth_challenge_drop($sid, 'verify');
		header('Location: '.$back);
		exit;

	}
	msg_redirect($back, 'Unknown request.', 'error');
}

/* Turn on what a verified address was for; this browser counts as known. */
function security_email_apply(array $p, $then) {
	if ($then === 'alert') {
		auth_setting_put('auth-login-alert', '1');
		auth_log_event('login_alert_on', 'to '.$p['email'], (int)$p['id'], $p['username']);
	}
	auth_device_seen($p);
}

function security_email_done($then) {
	if ($then === 'alert')
		return 'You will get an email after each login from a new device.';
	return 'Login codes and alerts go to this address.';
}

/*
 * Panel firewall allowlist (op = add | remove | on | off | keep | undo).
 * Adding an address or turning the allowlist off needs sudo mode; removing
 * and turning on only tighten it. Whenever the rules are
 * applied the admin's current address is kept on the list, and the change
 * reverts itself unless "keep" follows within two minutes.
 */
function security_fw_action(array $p, $back) {
	$ip   = auth_ip();
	$list = auth_fw_list();
	$st   = auth_fw_status();
	$on   = !empty($st['on']);
	$op   = security_post('op', 16);

	switch ($op) {
	case 'add':
		$cidr = auth_fw_normalize(security_post('cidr', 64));
		if ($cidr === '')
			msg_redirect($back, 'Enter an IP address or a network like 203.0.113.0/24.', 'error');
		security_need_sudo($back);
		if (in_array($cidr, array_column($list, 'cidr'), true))
			msg_redirect($back, "$cidr is already on the list.", 'info');
		$list[] = array('cidr' => $cidr, 'note' => auth_fw_note(security_post('note', 120)));
		break;

	case 'remove':
		$cidr = security_post('cidr', 64);
		$rest = array_values(array_filter($list, function ($e) use ($cidr) { return $e['cidr'] !== $cidr; }));
		if (count($rest) === count($list))
			msg_redirect($back, 'That address is not on the list.', 'error');
		if ($on && !auth_fw_allowed($ip, $rest))
			msg_redirect($back, "Not removed: it would block your own address ($ip) from the panel.", 'error');
		$list = $rest;
		break;

	case 'on':
		break;

	case 'off':
		security_need_sudo($back);
		if (($e = auth_fw_off()) !== '')
			msg_redirect($back, $e, 'error');
		msg_redirect($back, 'The firewall allowlist is off - port 2087 is open to every address again.', 'warning');

	case 'keep':
	case 'undo':
		auth_fw_settle($op === 'keep');
		msg_redirect($back, $op === 'keep' ? 'Firewall rules kept.' : 'The firewall change was undone.', 'success');

	default:
		msg_redirect($back, 'Unknown request.', 'error');
	}

	/* add / remove / on: save, then apply when the allowlist is (being turned) on */
	if (($on || $op === 'on') && !auth_fw_allowed($ip, $list))
		$list[] = array('cidr' => auth_fw_normalize($ip), 'note' => 'added automatically (your address)');
	auth_fw_list_save($list);
	if (!$on && $op !== 'on')
		msg_redirect($back, $op === 'add' ? 'Added. The firewall changes when you turn the allowlist on.' : 'Removed.', 'success');
	if (($e = auth_fw_apply($list)) !== '')
		msg_redirect($back, $e, 'error');
	// No flash message: the page opens the "Check that you can still reach the
	// panel" dialog by itself while the change is pending.
	header('Location: '.$back);
	exit;
}

/*
 * Firewall allowlist state for the page. Brings the saved list in line with
 * csf when no revert is pending (after an unconfirmed change was reverted,
 * or a hand edit of csf.allow), and notes a revert the admin did not ask for.
 */
function security_fw_state() {
	$st = auth_fw_status();
	if (empty($st['csf']))
		return $st;
	$st['reverted'] = false;
	if ($st['pending'] < 0 && auth_setting('panel-fw-pending', '') !== '') {
		auth_q("DELETE FROM settings WHERE name = 'panel-fw-pending'");
		auth_log_event('fw_allow_reverted', 'not confirmed in time - reverted automatically');
		$st['reverted'] = true;
	}
	if ($st['on'] && $st['pending'] < 0) {
		$saved = array_column(auth_fw_list(), 'cidr');
		$live  = array_column($st['rules'], 'cidr');
		sort($saved);
		sort($live);
		if ($saved !== $live)
			auth_fw_list_save($st['rules']);
	}
	return $st;
}
