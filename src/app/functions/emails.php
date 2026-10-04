<?php
/*
 * Mailboxes: create / edit / delete / list.
 *
 * Shared by the web panel (modules/create_email.php etc.), bin/reqad and the
 * API; returns result_ok() / result_error() (modules/functions.php).
 *
 * /etc/dovecot/users is the mailbox inventory (delivery and auth read it) and
 * /etc/exim/domains/<domain> lists the local parts exim accepts. The `emails`
 * table is only a disk-usage cache (db/1032.sql) and is not maintained here.
 */

/* Split "user@domain" -> array(user, domain), both trimmed, domain lowercased. */
function email_split($email) {
	$email = trim((string)$email);
	$at    = strrpos($email, '@');
	if ($at === false)
		return array('', '');
	return array(substr($email, 0, $at), strtolower(substr($email, $at + 1)));
}

/* Escape a literal for a sed basic regular expression inside '/.../'. */
function sed_bre_escape($s) {
	return addcslashes((string)$s, '.[]*^$\\/');
}

/* Owning hosting account of a mail domain (from /etc/exim/userdomains), or ''. */
function email_domain_owner($domain) {
	$u = trim((string)shell_exec('sudo grep -E '.escapeshellarg('^'.sed_bre_escape($domain).':')
		.' /etc/exim/userdomains 2>/dev/null | head -n 1 | cut -d: -f2 | tr -d " "'));
	return valid_username($u) ? $u : '';
}

/* Is $domain set up for mail (/etc/exim/domains/<domain> exists)? */
function email_domain_exists($domain) {
	$domains = explode("\n", trim((string)shell_exec('sudo ls -1 /etc/exim/domains/ 2>/dev/null')));
	return in_array($domain, $domains, true);
}

/*
 * List mailboxes from /etc/dovecot/users.
 * $domain: only this domain ('' = all).
 * Returns array of array('email', 'domain', 'account', 'enabled').
 */
function email_list($domain = '') {
	$domain = strtolower(trim((string)$domain));
	$list   = array();
	foreach (explode("\n", (string)shell_exec('sudo cat /etc/dovecot/users 2>/dev/null')) as $line) {
		$f = explode(':', trim($line));
		if (count($f) < 6 || strpos($f[0], '@') === false)
			continue;
		list(, $d) = email_split($f[0]);
		if ($domain !== '' && $d !== $domain)
			continue;
		/* home is /home/<account>/mail/<domain>/<user> */
		$parts = explode('/', $f[5]);
		$list[] = array(
			'email'   => strtolower($f[0]),
			'domain'  => $d,
			'account' => isset($parts[2]) ? $parts[2] : '',
			'enabled' => !mailbox_login_disabled($f[1]),
		);
	}
	usort($list, function($a, $b) { return strcmp($a['email'], $b['email']); });
	return $list;
}

/*
 * Create a mailbox.
 * $o keys: email ("user@domain") or user + domain; password (8+ characters).
 */
function email_create(array $o) {
	if (!empty($o['email']))
		list($user, $domain) = email_split($o['email']);
	else {
		$user   = trim((string)($o['user'] ?? ''));
		$domain = strtolower(trim((string)($o['domain'] ?? '')));
	}
	$password = trim((string)($o['password'] ?? ''));
	$email    = $user.'@'.$domain;

	if (!valid_email_user($user))
		return result_error("Email must be unique, 1-64 characters long, contain letters, numbers, dashes and underscores.");
	if (!valid_domain($domain))
		return result_error("Domain name is wrong, please check what you selected.");
	if (!email_domain_exists($domain))
		return result_error("Domain $domain not found in /etc/exim/domains/ (is email enabled for the account?).");

	/* Stricter than mailbox_create(): a local part already listed for the domain
	   (e.g. by an autoresponder alias) is refused here, as the panel always has. */
	$locals = array_map('trim', explode("\n", trim((string)shell_exec('sudo cat '.escapeshellarg('/etc/exim/domains/'.$domain).' 2>/dev/null'))));
	if (in_array($user, $locals, true))
		return result_error("Email $email already exists in /etc/exim/domains/$domain.");
	if (in_array(strtolower($email), mailbox_list(true), true))
		return result_error("Email $email already exists in /etc/dovecot/users.");
	if (strlen($password) < 8)
		return result_error("Password should be at least 8 characters long.");

	route_log("create email $email");
	$err = mailbox_create($user, $domain, $password);
	if ($err !== '')
		return result_error($err);

	return result_ok("Email account $email successfully created.", array('email' => $email, 'account' => email_domain_owner($domain)));
}

/*
 * Edit a mailbox. Only the keys present in $o are changed.
 * $o keys:
 *   email     required
 *   password  new password ('' or absent = unchanged)
 *   enabled   bool -- allow / block IMAP, POP3 and SMTP login (delivery continues)
 */
function email_edit(array $o) {
	list($user, $domain) = email_split($o['email'] ?? '');
	$email    = $user.'@'.$domain;
	$password = trim((string)($o['password'] ?? ''));

	if (!valid_email_user($user))
		return result_error("Email must be unique, 1-64 characters long, contain letters, numbers, dashes and underscores.");
	if (!valid_domain($domain))
		return result_error("Domain name is wrong, please check what you selected.");
	if (!email_domain_exists($domain))
		return result_error("Domain $domain not found in /etc/exim/domains/.");
	if (!in_array(strtolower($email), mailbox_list(true), true))
		return result_error("Email $email does not exist in /etc/dovecot/users.");

	$sysuser = email_domain_owner($domain);
	if ($sysuser === '')
		return result_error("Domain $domain does not exist in /etc/exim/userdomains.");
	$uid = (int)trim((string)shell_exec('sudo id -u '.escapeshellarg($sysuser).' 2>/dev/null'));
	$gid = (int)trim((string)shell_exec('sudo id -g '.escapeshellarg($sysuser).' 2>/dev/null'));
	if ($uid <= 0 || $gid <= 0)
		return result_error("User $sysuser does not exist on system (no UID / GID found).");

	if ($password !== '' && strlen($password) < 8)
		return result_error("Password should be at least 8 characters long.");

	$oldhash     = mailbox_hash($email);
	$was_enabled = !mailbox_login_disabled($oldhash);
	$enabled     = array_key_exists('enabled', $o) && $o['enabled'] !== null ? (bool)$o['enabled'] : $was_enabled;

	if ($password === '' && ltrim($oldhash, '!') === '')
		return result_error("No password on file for $email, please set one.");

	route_log("edit email $email");

	if ($password !== '')
		$hash = crypt($password, '$6$'.substr(str_shuffle("./ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijkl..mnopqrstuvwxyz012345..6789"), 0, 8));
	else
		$hash = ltrim($oldhash, '!');   // keep the stored password, toggle only
	/* Disabled accounts keep their hash behind a '!' -- see mailbox_login_disabled(). */
	if (!$enabled)
		$hash = '!'.$hash;

	$line = $email.':'.$hash.':'.$uid.':'.$gid.'::/home/'.$sysuser.'/mail/'.$domain.'/'.$user.'::userdb_mail=maildir:~/';
	shell_exec('sudo sed -i '.escapeshellarg('/^'.sed_bre_escape($email).':/d').' /etc/dovecot/users');
	shell_exec('echo '.escapeshellarg($line).' | sudo tee --append /etc/dovecot/users > /dev/null');
	mailbox_list(true);

	/* Same Active/Disabled wording as the Status column and the modal switch. */
	$state = $enabled ? "Active" : "Disabled";
	if ($password !== '') {
		$message = "Password changed successfully for $email.";
		if ($was_enabled != $enabled)
			$message .= " The account is now $state.";
	} else
		$message = "Email account $email is now $state.";

	return result_ok($message, array('email' => $email, 'enabled' => $enabled, 'password_changed' => $password !== ''));
}

/* Delete a mailbox: dovecot passdb line, exim local part, the Maildir and the
   mailbox's filters / autoresponder. */
function email_delete($db, $email) {
	list($user, $domain) = email_split($email);
	$email = $user.'@'.$domain;

	if (!valid_email_user($user))
		return result_error("Email must be unique, 1-64 characters long, contain letters, numbers, dashes and underscores.");
	if (!valid_domain($domain))
		return result_error("Domain name is wrong, please check what you selected.");
	if (!email_domain_exists($domain))
		return result_error("Domain $domain not found in /etc/exim/domains/.");
	if (!in_array(strtolower($email), mailbox_list(true), true))
		return result_error("Email $email does not exist in /etc/dovecot/users.");
	$sysuser = email_domain_owner($domain);
	if ($sysuser === '')
		return result_error("Domain $domain does not exist in /etc/exim/userdomains.");

	route_log("delete email $email");
	shell_exec('sudo sed -i '.escapeshellarg('/^'.sed_bre_escape($email).':/d').' /etc/dovecot/users');
	shell_exec('sudo sed -i '.escapeshellarg('/^'.sed_bre_escape($user).'$/d').' '.escapeshellarg('/etc/exim/domains/'.$domain));
	shell_exec('sudo rm -rf '.escapeshellarg('/home/'.$sysuser.'/mail/'.$domain.'/'.$user));
	/* The personal Sieve script goes with the maildir above; the autoresponder
	   script and row do not, so they are removed explicitly. */
	foreach (ef_purge_mailbox($db, $email) as $line)
		route_log("delete email $email: $line");
	mailbox_list(true);

	return result_ok("Email account $email was deleted.", array('email' => $email));
}
