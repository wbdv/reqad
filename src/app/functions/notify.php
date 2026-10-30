<?php
/*
 * Messages to hosting accounts: the contact address an account confirmed and
 * the kinds of message it wants (the account panel's Profile page,
 * reqad-account). Everything is sent through send_mail() (Settings >
 * Sending method); without a sending method nothing is sent and the Profile
 * page says so.
 *
 * The address is the account's auth_principals.email (type 'account'),
 * written only once a 6-digit code mailed to it was entered (an
 * auth_challenges row, purpose 'contact', bound to the panel session that
 * asked), so a typo never swallows the messages and the panel cannot be
 * used to mail strangers more than a code.
 *
 * Setting, changing or removing the address asks for the account's current
 * password (a session alone is not enough: a browser left open, a stolen
 * cookie), and the old address is told when it is replaced or removed, so
 * someone who learnt the password cannot quietly redirect the messages.
 * While the server can send mail, the password can only be changed from the
 * account panel once there is a confirmed address (reqad-account's broker).
 *
 * What is sent:
 *   - after each health run, the account's NEW items (first seen in that
 *     run) of the kinds it wants, in one message (account_notify_health)
 *   - a sign-in to the account panel from a browser it has not seen before
 *     (account_notify_login, from reqad-account's broker)
 * The same subject is mailed at most once a week (account_notices, db 1048).
 */

const ACCOUNT_NOTICE_REPEAT = 7 * 86400;

/*
 * The kinds of message, for $user: kind => label, hint, default, checks (the
 * health checks it covers). Kinds that cannot happen for the account (no
 * email, no deployments) are left out, unless $all. Add-ons add theirs
 * through the filter account.notify.kinds and send them with
 * account_notify_send().
 */
function account_notify_kinds($user = '', $all = false) {
	global $db, $ini;
	$acc = $user !== '' ? account_get($db, $user) : null;
	$k = array(
		'security' => array('label' => 'Sign-ins from a new device', 'default' => true, 'checks' => array('mysql_remote'),
		                    'hint' => 'Someone logged in to your panel from a browser it has not seen before, or a database is open to any address on the internet.'),
		'sites'    => array('label' => 'Certificates', 'default' => true, 'checks' => array('ssl'),
		                    'hint' => 'A site\'s certificate is about to expire, has expired or does not cover all its names.'),
		'mail'     => array('label' => 'Email setup', 'default' => true, 'checks' => array('mail_dns', 'site_mail'),
		                    'hint' => 'A mail domain is missing DNS records (MX, SPF, DKIM, DMARC), so mail may not arrive or may land in spam, or a site that should have email has none.'),
		'disk'     => array('label' => 'Disk space', 'default' => true, 'checks' => array('disk_quota'),
		                    'hint' => 'The account is close to its disk space limit.'),
		'updates'  => array('label' => 'Updates', 'default' => true, 'checks' => array('wordpress', 'php_eol'),
		                    'hint' => 'WordPress updates are waiting, or a site runs a PHP version that no longer gets security fixes.'),
		'cron'     => array('label' => 'Cron jobs', 'default' => true, 'checks' => array('cron'),
		                    'hint' => 'A cron job failed 3 times in a row.'),
		'resources' => array('label' => 'Resource limits', 'default' => true, 'checks' => array('resources'),
		                    'hint' => 'A site ran out of PHP workers several times in a day, or the memory limit stopped a process.'),
		'apps'     => array('label' => 'Deployments and apps', 'default' => true, 'checks' => array('deploy', 'app'),
		                    'hint' => 'A deployment failed or an app stopped running.'),
	);
	if (!$all && $acc && !($acc['has_email'] === 'true' || $acc['has_email'] == 1))
		unset($k['mail']);
	if (!feature_enabled($ini, 'wptoolkit'))
		$k['updates']['hint'] = 'A site runs a PHP version that no longer gets security fixes.';
	if (!$all && (!function_exists('deploy_enabled') || !deploy_enabled()))
		unset($k['apps']);
	$more = plugin_filter('account.notify.kinds', $k, (string)$user);
	return is_array($more) ? $more : $k;
}

/* The confirmed contact address of $user ('' = none) */
function account_contact($user) {
	$p = auth_principal((string)$user, 'account');
	return $p ? (string)$p['email'] : '';
}

/* Can this server send mail at all */
function account_notify_can_send() {
	return function_exists('smtp_configured') && smtp_configured();
}

/* Who the messages come from, for $user: name (the hosting provider), panel
   (the account panel's address). reqad-account fills it in. */
function account_notify_brand($user = '') {
	$b = plugin_filter('account.notify.brand', array('name' => strtolower(php_uname('n')), 'panel' => ''), (string)$user);
	return is_array($b) ? $b + array('name' => '', 'panel' => '') : array('name' => strtolower(php_uname('n')), 'panel' => '');
}

/* kind => array(label, hint, default, enabled) */
function account_notify_prefs($user) {
	global $db;
	$set = array();
	$stmt = $db->prepare('SELECT kind, enabled FROM account_notify WHERE account = :a');
	$stmt->bindValue(':a', (string)$user, SQLITE3_TEXT);
	$r = $stmt->execute();
	while ($r && ($row = $r->fetchArray(SQLITE3_ASSOC)))
		$set[$row['kind']] = (int)$row['enabled'] === 1;
	$out = array();
	foreach (account_notify_kinds($user) as $kind => $k)
		$out[$kind] = array('label' => $k['label'], 'hint' => $k['hint'], 'default' => (bool)$k['default'],
		                    'enabled' => $set[$kind] ?? (bool)$k['default']);
	return $out;
}

function account_notify_wants($user, $kind) {
	$p = account_notify_prefs($user);
	return !empty($p[$kind]['enabled']);
}

/* Save the kinds $user wants: kind => bool (kinds not given stay as they are;
   a kind hidden for the account now, like Email setup without email, is
   kept for when it shows). */
function account_notify_set($user, array $kinds) {
	global $db;
	$known = account_notify_kinds($user, true);
	$changed = array();
	foreach ($kinds as $kind => $on) {
		if (!isset($known[$kind]))
			continue;
		$on = (bool)$on;
		if ($on === (bool)$known[$kind]['default'])
			$stmt = $db->prepare('DELETE FROM account_notify WHERE account = :a AND kind = :k');
		else {
			$stmt = $db->prepare('INSERT OR REPLACE INTO account_notify (account, kind, enabled) VALUES (:a, :k, :e)');
			$stmt->bindValue(':e', $on ? 1 : 0, SQLITE3_INTEGER);
		}
		$stmt->bindValue(':a', (string)$user, SQLITE3_TEXT);
		$stmt->bindValue(':k', (string)$kind, SQLITE3_TEXT);
		$stmt->execute();
		$changed[$kind] = $on;
	}
	if ($changed)
		plugin_hook('account.notify_changed', array('account' => $user, 'kinds' => $changed));
	return result_ok('Saved.', array('kinds' => account_notify_prefs($user)));
}

/* ---- the contact address ------------------------------------------------------------ */

/* The address waiting for its code in $session_id, or '' */
function account_contact_pending($session_id) {
	$c = auth_challenge($session_id, 'contact');
	$t = $c ? json_decode((string)$c['target'], true) : null;
	return is_array($t) && isset($t['email']) ? (string)$t['email'] : '';
}

/* Codes mailed for $user in the last hour (start and resend): at most 5, so
   the Profile page cannot be used to mail strangers again and again. */
function account_contact_codes_recent($user) {
	$r = auth_row("SELECT COUNT(*) AS n FROM auth_log WHERE username = :u AND event IN ('contact_code_sent', 'contact_code_resent') AND ts > :t",
	              array('u' => (string)$user, 't' => time() - 3600));
	return $r ? (int)$r['n'] : 0;
}

/*
 * '' when $password is the account's password (its system password, the one
 * the account panel signs in with: account_password_verify()), else why not.
 * A wrong one counts as a failed login attempt (the same lock-out as the
 * login page) and costs a second.
 */
function account_password_check($user, $password) {
	$p = auth_principal((string)$user, 'account');
	if (!$p)
		return 'This account cannot sign in to the panel.';
	$ip = auth_ip();
	if (auth_ip_locked($ip) > 0)
		return 'Too many failed attempts from your address. Try again later.';
	if ((string)$password === '')
		return 'Enter your current password.';
	if (account_password_verify((string)$user, (string)$password) !== 'ok') {
		auth_attempt_record($ip, (string)$user, 'bad_password');
		auth_log_event('password_check_failed', 'account panel', (int)$p['id'], (string)$user);
		usleep(random_int(800000, 1500000));
		return 'Your current password is not right.';
	}
	return '';
}

/* Mail a code to $email; the address is saved when the code comes back. */
function account_contact_start($user, $session_id, $email, $password = null) {
	$email = strtolower(trim((string)$email));
	if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254)
		return result_error('That is not a valid email address.');
	if (!account_notify_can_send())
		return result_error('This server cannot send email yet. Ask your hosting provider.');
	if ($email === account_contact($user))
		return result_error('That is already your contact address.');
	if (($e = account_password_check($user, (string)$password)) !== '')
		return result_error($e, array('field' => 'password'));
	if (account_contact_codes_recent($user) >= 5)
		return result_error('Too many codes were sent in the last hour. Try again later.');
	auth_challenge_drop($session_id, 'contact');
	$err = auth_challenge_send($session_id, 'contact', $email, json_encode(array('email' => $email, 'account' => (string)$user)));
	if ($err !== '')
		return result_error($err);
	$p = auth_principal($user, 'account');
	auth_log_event('contact_code_sent', $email, $p ? (int)$p['id'] : 0, $user);
	return result_ok('We sent a code to {email}. Enter it here to confirm the address.', array('pending' => $email));
}

function account_contact_resend($user, $session_id) {
	$email = account_contact_pending($session_id);
	if ($email === '')
		return result_error('Start again: enter the address.');
	if (account_contact_codes_recent($user) >= 5)
		return result_error('Too many codes were sent in the last hour. Try again later.');
	if (($err = auth_challenge_send($session_id, 'contact', $email)) !== '')
		return result_error($err);
	$p = auth_principal($user, 'account');
	auth_log_event('contact_code_resent', $email, $p ? (int)$p['id'] : 0, $user);
	return result_ok('We sent a new code to {email}.', array('pending' => $email));
}

function account_contact_cancel($session_id) {
	auth_challenge_drop($session_id, 'contact');
	return result_ok('');
}

function account_contact_confirm($user, $session_id, $code) {
	$r = auth_challenge_check($session_id, 'contact', (string)$code);
	if ($r === 'wrong')
		return result_error('That code is not right. Use the code from the latest message.');
	if (!is_array($r))
		return result_error($r === 'locked' ? 'Too many wrong codes. Enter the address again for a new code.' : 'That code has expired. Enter the address again for a new code.');
	$t = json_decode((string)$r['target'], true);
	if (!is_array($t) || ($t['account'] ?? '') !== (string)$user || !isset($t['email']))
		return result_error('Start again: enter the address.');
	$p = auth_principal($user, 'account');
	if (!$p)
		return result_error('This account cannot sign in to the panel.');
	$old = (string)$p['email'];
	auth_q('UPDATE auth_principals SET email = :e WHERE id = :id', array('e' => $t['email'], 'id' => (int)$p['id']));
	auth_log_event('contact_changed', $t['email'], (int)$p['id'], $user);
	plugin_hook('account.contact_changed', array('account' => $user, 'email' => $t['email'], 'old' => $old));
	if ($old !== '' && $old !== $t['email'])
		account_contact_tell_old($user, $old, $t['email']);
	return result_ok('{email} is now your contact address.', array('email' => $t['email']));
}

function account_contact_remove($user, $password = null) {
	$p = auth_principal($user, 'account');
	if (!$p || $p['email'] === '')
		return result_ok('');
	if (($e = account_password_check($user, (string)$password)) !== '')
		return result_error($e, array('field' => 'password'));
	auth_q("UPDATE auth_principals SET email = '' WHERE id = :id", array('id' => (int)$p['id']));
	auth_log_event('contact_changed', '(removed)', (int)$p['id'], $user);
	plugin_hook('account.contact_changed', array('account' => $user, 'email' => '', 'old' => $p['email']));
	account_contact_tell_old($user, (string)$p['email'], '');
	return result_ok('The contact address was removed. No more messages are sent.');
}

/*
 * An account brought over from another server (the transfer tool): $email,
 * its contact address there, is stored as confirmed - no code, no message -
 * and every kind of message is turned off except sign-ins from a new device
 * ('security'), so the move itself mails nobody; the account turns the rest
 * on in its Profile. An address the account already has here is kept.
 * $email '' only sets the kinds.
 */
function account_contact_import($user, $email) {
	if (!auth_ready())
		return result_error('The login tables are missing (db 1037).');
	$email = strtolower(trim((string)$email));
	if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254))
		return result_error('That is not a valid email address.');
	$kinds = array();
	foreach (array_keys(account_notify_kinds($user, true)) as $kind)
		$kinds[$kind] = $kind === 'security';
	account_notify_set($user, $kinds);
	if ($email === '')
		return result_ok('', array('email' => account_contact($user), 'kept' => false));
	auth_q("INSERT OR IGNORE INTO auth_principals (type, username, pw_hash, email, created_at)
	        VALUES ('account', :u, '', '', :ts)", array('u' => (string)$user, 'ts' => time()));
	$p = auth_principal($user, 'account');
	if (!$p)
		return result_error('The account\'s panel login could not be made.');
	if ((string)$p['email'] !== '')
		return result_ok('', array('email' => (string)$p['email'], 'kept' => true));
	auth_q('UPDATE auth_principals SET email = :e WHERE id = :id', array('e' => $email, 'id' => (int)$p['id']));
	auth_log_event('contact_changed', $email.' (imported)', (int)$p['id'], $user);
	plugin_hook('account.contact_changed', array('account' => $user, 'email' => $email, 'old' => ''));
	return result_ok('', array('email' => $email, 'kept' => false));
}

/* Tell the address that was replaced ($new) or removed ($new = '') - always,
   whatever kinds of message it had chosen. The new one is shown masked. */
function account_contact_tell_old($user, $old, $new) {
	if (!account_notify_can_send())
		return;
	$b = account_notify_brand($user);
	$f = auth_mail_facts();
	$what = $new !== '' ? 'was changed to '.auth_mask_email($new) : 'was removed';
	$title = 'Your contact address '.($new !== '' ? 'was changed' : 'was removed');
	$detail = 'The contact address of the hosting account '.$user.' '.$what.', from '.$f['Browser'].' at '.($f['IP address'] ?: 'an unknown address').', '.$f['Time']
	        .'. Messages about the account no longer come here. If this was not you, someone knows your password: ask your hosting provider to set a new one right away.';
	$html = mail_html_layout($title, '<p style="margin:0;font-size:15px;color:#232e3c;">'.htmlspecialchars($detail).'</p>'
		.($b['panel'] !== '' ? '<p style="margin:24px 0 0;"><a href="'.htmlspecialchars($b['panel']).'" style="display:inline-block;background:#2a6099;color:#ffffff;text-decoration:none;padding:10px 18px;border-radius:4px;font-size:14px;">Open your hosting panel</a></p>' : ''),
		array('name' => $b['name']));
	if (($err = send_mail($old, $title, $html, $title."

".$detail.($b['panel'] !== '' ? "

Your hosting panel: ".$b['panel'] : ''), '', $b['name'])) !== '')
		error_log("reqad notify: could not tell $old ($user): $err");
}

/* The code message (auth_mail_code() hands purpose 'contact' here) */
function account_contact_mail_code($email, $code) {
	$b = account_notify_brand();
	$min = auth_cfg('auth-code-minutes');
	$big = '<p style="margin:24px 0;font-size:32px;letter-spacing:6px;font-weight:bold;color:#2a6099;font-family:Arial,Helvetica,sans-serif;">'.$code.'</p>';
	$html = mail_html_layout('Confirm your contact address',
		'<p style="margin:0;font-size:15px;color:#232e3c;">Enter this code on the Profile page of your hosting panel to receive its messages at this address:</p>'
		.$big.'<p style="margin:0;font-size:13px;color:#656d77;">It expires in '.$min.' minutes. If you did not ask for it, ignore this message: nothing is sent to this address without the code.</p>',
		$b);
	$text = "Confirm your contact address\n\nEnter this code on the Profile page of your hosting panel to receive its messages at this address:\n\n    $code\n\nIt expires in $min minutes. If you did not ask for it, ignore this message.";
	return send_mail($email, 'Confirm your contact address ('.$b['name'].')', $html, $text, '', $b['name']);
}

/* ---- sending ------------------------------------------------------------------------ */

/* Mailed $key to $user within the last week */
function account_notice_recent($user, $key) {
	global $db;
	$stmt = $db->prepare('SELECT sent_at FROM account_notices WHERE account = :a AND key = :k');
	$stmt->bindValue(':a', (string)$user, SQLITE3_TEXT);
	$stmt->bindValue(':k', (string)$key, SQLITE3_TEXT);
	$r = $stmt->execute();
	$row = $r ? $r->fetchArray(SQLITE3_ASSOC) : null;
	return $row && (int)$row['sent_at'] > time() - ACCOUNT_NOTICE_REPEAT;
}

function account_notice_sent($user, array $keys) {
	global $db;
	foreach ($keys as $key) {
		$stmt = $db->prepare('INSERT OR REPLACE INTO account_notices (account, key, sent_at) VALUES (:a, :k, :t)');
		$stmt->bindValue(':a', (string)$user, SQLITE3_TEXT);
		$stmt->bindValue(':k', (string)$key, SQLITE3_TEXT);
		$stmt->bindValue(':t', time(), SQLITE3_INTEGER);
		$stmt->execute();
	}
	$db->exec('DELETE FROM account_notices WHERE sent_at < '.(time() - 4 * ACCOUNT_NOTICE_REPEAT));
}

/*
 * Mail $user a message of $kind ($rows: a list of array(title, detail)).
 * $keys: what it is about, so the same thing is not mailed again within a
 * week (rows whose key was mailed lately are dropped; $keys[i] belongs to
 * $rows[i]). Returns '' when sent, 'skipped' when nothing went out (no
 * address, kind off, nothing new, no sending method), else the error.
 */
function account_notify_send($user, $kind, $subject, array $rows, array $keys = array(), $intro = '') {
	$to = account_contact($user);
	if ($to === '' || !account_notify_can_send() || !account_notify_wants($user, $kind))
		return 'skipped';
	foreach ($keys as $i => $key)
		if ($key !== '' && account_notice_recent($user, $key))
			unset($rows[$i], $keys[$i]);
	if (!$rows)
		return 'skipped';
	$b = account_notify_brand($user);
	$body = $intro !== '' ? '<p style="margin:0 0 18px;font-size:15px;color:#232e3c;">'.htmlspecialchars($intro).'</p>' : '';
	$text = $intro !== '' ? $intro."\n\n" : '';
	/* one row with the subject as its title: the title is the message's heading already */
	$one = count($rows) === 1 && reset($rows)[0] === $subject;
	foreach ($rows as $r) {
		$body .= ($one ? '' : '<p style="margin:0 0 4px;font-size:15px;font-weight:bold;color:#232e3c;">'.htmlspecialchars($r[0]).'</p>')
		       .($r[1] !== '' ? '<p style="margin:0 0 18px;font-size:14px;color:#454f5b;">'.htmlspecialchars($r[1]).'</p>' : '');
		$text .= '- '.$r[0]."\n".($r[1] !== '' ? '  '.$r[1]."\n" : '')."\n";
	}
	if ($b['panel'] !== '') {
		$body .= '<p style="margin:24px 0 0;"><a href="'.htmlspecialchars($b['panel']).'" style="display:inline-block;background:#2a6099;color:#ffffff;text-decoration:none;padding:10px 18px;border-radius:4px;font-size:14px;">Open your hosting panel</a></p>';
		$text .= 'Your hosting panel: '.$b['panel']."\n";
	}
	$text .= "\nYou get this because ".$to." is the contact address of the hosting account ".$user.". Choose which messages you get on the Profile page of your hosting panel.";
	$err = send_mail($to, $subject, mail_html_layout($subject, $body, $b + array('account' => $user, 'to' => $to)), $text, '', $b['name']);
	if ($err !== '') {
		error_log("reqad notify: could not mail $to ($user): $err");
		return $err;
	}
	account_notice_sent($user, array_filter($keys, 'strlen'));
	plugin_hook('account.notified', array('account' => $user, 'kind' => $kind, 'to' => $to, 'subject' => $subject));
	return '';
}

/*
 * After a health run: mail each account the items first seen in that run
 * ($now = the run's time), one message per kind. Dismissed items are not
 * mailed. Returns the number of messages sent.
 */
function account_notify_health($db, $now) {
	if (!account_notify_can_send())
		return 0;
	$stmt = $db->prepare("SELECT h.id, h.check_id, h.account, h.title, h.detail FROM health_items h
	                      LEFT JOIN health_dismissed d ON d.id = h.id
	                      WHERE h.account != '' AND h.first_seen = :now AND d.id IS NULL ORDER BY h.account, h.severity");
	$stmt->bindValue(':now', (int)$now, SQLITE3_INTEGER);
	$r = $stmt->execute();
	$by = array();
	while ($r && ($row = $r->fetchArray(SQLITE3_ASSOC)))
		$by[$row['account']][] = $row;
	$sent = 0;
	foreach ($by as $user => $items) {
		if (account_contact($user) === '')
			continue;
		$kinds = account_notify_kinds($user);
		foreach ($kinds as $kind => $k) {
			$rows = $keys = array();
			foreach ($items as $it)
				if (in_array($it['check_id'], $k['checks'] ?? array(), true)) {
					$rows[] = array($it['title'], $it['detail']);
					$keys[] = $it['id'];
				}
			if (!$rows)
				continue;
			$subject = count($rows) === 1 ? $rows[0][0] : $k['label'].': '.count($rows).' things need attention';
			if (account_notify_send($user, $kind, $subject, $rows, $keys, 'Something in your hosting account needs attention:') === '')
				$sent++;
		}
	}
	return $sent;
}

/* A sign-in to the account panel from a browser it had not seen ($how: password, authenticator code, ...) */
function account_notify_login($user, $how) {
	$facts = auth_mail_facts();
	$rows = array(array('New sign-in to your hosting panel',
		'From '.$facts['Browser'].' at '.($facts['IP address'] ?: 'an unknown address').', '.$facts['Time'].' ('.$how.'). If this was you, there is nothing to do. If it was not, someone knows your password: change it and log out the other sessions in your hosting panel.'));
	return account_notify_send($user, 'security', 'New sign-in to your hosting panel', $rows);
}

/* The password of $user was changed from the account panel (it is the
   account's system password: SSH, SFTP and FTP use it too) */
function account_notify_password($user) {
	$facts = auth_mail_facts();
	$rows = array(array('Your hosting account password was changed',
		'From '.$facts['Browser'].' at '.($facts['IP address'] ?: 'an unknown address').', '.$facts['Time'].'. It is the password of your hosting panel and of SSH, SFTP and FTP. Other devices signed in to the panel with the old password were logged out. If this was not you, ask your hosting provider to set a new password for you right away.'));
	return account_notify_send($user, 'security', 'Your hosting account password was changed', $rows);
}

/* The sign-in of $user was changed from the account panel's Password and 2FA
   page ($what: 2fa_on, 2fa_off, codes, passkey_added, passkey_removed;
   $detail: the passkey's name) */
function account_notify_signin($user, $what, $detail = '') {
	$titles = array(
		'2fa_on'          => 'Two-factor authentication was turned on',
		'2fa_off'         => 'Two-factor authentication was turned off',
		'codes'           => 'New recovery codes were made',
		'passkey_added'   => 'A passkey was added',
		'passkey_removed' => 'A passkey was removed',
	);
	if (!isset($titles[$what]))
		return false;
	$facts = auth_mail_facts();
	$text = 'For your hosting panel'.($detail !== '' ? ' ("'.$detail.'")' : '').', from '.$facts['Browser'].' at '
	      .($facts['IP address'] ?: 'an unknown address').', '.$facts['Time'].'. If this was you, there is nothing to do. '
	      .'If it was not, someone is signed in to your panel: change your password there right away, or ask your hosting provider for help.';
	return account_notify_send($user, 'security', $titles[$what], array(array($titles[$what], $text)));
}
