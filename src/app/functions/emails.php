<?php
/*
 * Mailboxes, forwarders and autoresponders: create / edit / delete / list.
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

/* /etc/exim/userdomains as domain => account (one line per mail domain; an
   account with addon domains can have several). */
function email_userdomains() {
	$map = array();
	foreach (explode("\n", (string)shell_exec('sudo -n cat /etc/exim/userdomains 2>/dev/null')) as $line) {
		if (strpos($line, ':') === false)
			continue;
		list($d, $u) = explode(':', $line, 2);
		if (($d = strtolower(trim($d))) !== '' && !isset($map[$d]))
			$map[$d] = trim($u);
	}
	return $map;
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
 * $o keys: email ("user@domain") or user + domain; password (8+ characters);
 *   size  mailbox size (Reqad Hosting Pro), e.g. "500M", "2G"; '' = the
 *         account's default
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
	$owner = email_domain_owner($domain);
	if (($e = limit_allows($owner, 'mailbox', array('email' => $email))) !== '')
		return result_error($e);
	/* a mailbox size is checked by the plugin that enforces it; without one
	   there are no sizes to ask for */
	$size = trim((string)($o['size'] ?? ''));
	$e = plugin_filter('email.create.check', $size !== '' ? 'Mailbox sizes need the Reqad Hosting Pro add-on.' : '', $o, $owner);
	if (is_string($e) && $e !== '')
		return result_error($e);

	route_log("create email $email");
	$err = mailbox_create($user, $domain, $password);
	if ($err !== '')
		return result_error($err);

	/* plugins apply theirs (Hosting Pro: the mailbox size) */
	$more = plugin_filter('email.create.apply', array('changes' => array(), 'warnings' => array()), $email, $owner, $o);
	$warnings = is_array($more) ? array_values(array_filter((array)($more['warnings'] ?? array()), 'is_string')) : array();

	plugin_hook('mailbox.created', array('email' => $email, 'account' => $owner));
	return result_ok("Email account $email successfully created.", array('email' => $email, 'account' => $owner), $warnings);
}

/*
 * Give an existing mailbox a password hash as it is (a restore from a
 * backup puts back the password a mailbox had). Only crypt()-style or
 * {SCHEME} hashes; the rest of its line in /etc/dovecot/users stays.
 * Returns '' or why not.
 */
function mailbox_set_hash($email, $hash) {
	$email = strtolower(trim((string)$email));
	$hash = (string)$hash;
	if (!preg_match('/^(\$[0-9a-z]{1,3}\$|\{[A-Z0-9.-]{2,30}\})[^:\s!]{8,400}$/', $hash))
		return 'Not a password hash Reqad can use.';
	$line = '';
	foreach (explode("\n", (string)shell_exec('sudo cat /etc/dovecot/users 2>/dev/null')) as $l) {
		$f = explode(':', trim($l), 3);
		if (count($f) === 3 && strtolower($f[0]) === $email)
			$line = $f[0].':'.$hash.':'.$f[2];
	}
	if ($line === '')
		return "Email $email does not exist in /etc/dovecot/users.";
	shell_exec('sudo sed -i '.escapeshellarg('/^'.sed_bre_escape($email).':/d').' /etc/dovecot/users');
	shell_exec('echo '.escapeshellarg($line).' | sudo tee --append /etc/dovecot/users > /dev/null');
	mailbox_list(true);
	return mailbox_hash($email) === $hash ? '' : "Could not write $email to /etc/dovecot/users.";
}

/*
 * Edit a mailbox. Only the keys present in $o are changed.
 * $o keys:
 *   email     required
 *   password  new password ('' or absent = unchanged)
 *   enabled   bool -- allow / block IMAP, POP3 and SMTP login (delivery continues)
 *   size      mailbox size (Reqad Hosting Pro), e.g. "500M"; 'default' = the
 *             account's default; '' or absent = unchanged
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
	$size = trim((string)($o['size'] ?? ''));
	$e = plugin_filter('email.edit.check', $size !== '' ? 'Mailbox sizes need the Reqad Hosting Pro add-on.' : '', $o, $sysuser);
	if (is_string($e) && $e !== '')
		return result_error($e);

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

	/* the extra fields (userdb_mail=..., and what plugins add, e.g. a mailbox
	   size) are kept as they are */
	$extra = mailbox_extra_fields($email);
	$line = $email.':'.$hash.':'.$uid.':'.$gid.'::/home/'.$sysuser.'/mail/'.$domain.'/'.$user.'::'.($extra !== '' ? $extra : 'userdb_mail=maildir:~/');
	if ($password !== '' || $was_enabled != $enabled) {
		shell_exec('sudo sed -i '.escapeshellarg('/^'.sed_bre_escape($email).':/d').' /etc/dovecot/users');
		shell_exec('echo '.escapeshellarg($line).' | sudo tee --append /etc/dovecot/users > /dev/null');
		mailbox_list(true);
	}

	/* plugins apply theirs last (Hosting Pro: the mailbox size) */
	$more = plugin_filter('email.edit.apply', array('changes' => array(), 'warnings' => array()), $email, $o);
	$more = is_array($more) ? $more : array();
	$changes  = array_values(array_filter((array)($more['changes'] ?? array()), 'is_string'));
	$warnings = array_values(array_filter((array)($more['warnings'] ?? array()), 'is_string'));

	/* Same Active/Disabled wording as the Status column and the modal switch. */
	$state = $enabled ? "Active" : "Disabled";
	if ($password !== '') {
		$message = "Password changed successfully for $email.";
		if ($was_enabled != $enabled)
			$message .= " The account is now $state.";
	} else if ($was_enabled != $enabled || !$changes)
		$message = "Email account $email is now $state.";
	else
		$message = "Email account $email saved.";
	if ($changes)
		$message .= ' '.ucfirst(implode(', ', $changes)).'.';

	return result_ok($message, array('email' => $email, 'enabled' => $enabled, 'password_changed' => $password !== '', 'changes' => $changes), $warnings);
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

	plugin_hook('mailbox.deleted', array('email' => $email));
	return result_ok("Email account $email was deleted.", array('email' => $email));
}

/* ---- Forwarders -------------------------------------------------------------
   /etc/exim/forwards/<domain>: one "local: target, target" line per address,
   read by the forwards router (unseen: a mailbox with the same address still
   gets its copy). A target is an address, a pipe ("|/path", run as the
   account) or something written by hand (":fail:", a file); only addresses
   are managed here, pipes only for the server's admin. Other lines (the
   "*: :fail:" one) are kept as they are. */

function forwarder_file($domain) {
	return '/etc/exim/forwards/'.$domain;
}

/* The lines of a domain's forwards file. */
function forwarder_lines($domain) {
	$raw = (string)shell_exec('sudo cat '.escapeshellarg(forwarder_file($domain)).' 2>/dev/null');
	return $raw === '' ? array() : explode("\n", rtrim($raw, "\n"));
}

function forwarder_write($domain, array $lines) {
	$body = implode("\n", $lines);
	shell_exec('printf %s '.escapeshellarg($body === '' ? '' : $body."\n").' | sudo tee '.escapeshellarg(forwarder_file($domain)).' > /dev/null');
}

/* One line -> array(local part, to (addresses), pipes, other targets), or null. */
function forwarder_parse_line($line) {
	$line = trim($line);
	if ($line === '' || $line[0] === '#' || ($c = strpos($line, ':')) === false)
		return null;
	$local = strtolower(trim(substr($line, 0, $c)));
	if (!valid_email_user($local))
		return null;
	$to = $pipes = $other = array();
	foreach (explode(',', substr($line, $c + 1)) as $t) {
		$t = trim(trim($t), '"\'');
		if ($t === '')
			continue;
		if ($t[0] === '|')
			$pipes[] = substr($t, 1);
		elseif (valid_email_address($t))
			$to[] = strtolower($t);
		else
			$other[] = $t;
	}
	if (!$to && !$pipes && !$other)
		return null;
	return array($local, $to, $pipes, $other);
}

function forwarder_line($local, array $to, array $pipes, array $other) {
	$t = $to;
	foreach ($pipes as $p)
		$t[] = '|'.$p;
	return $local.': '.implode(', ', array_merge($t, $other));
}

/* Forwarders, sorted by address. $domain: only this one ('' = all).
   Rows: email, domain, to (addresses), pipes, other (hand-written targets). */
function forwarder_list($domain = '') {
	$domain = strtolower(trim((string)$domain));
	if ($domain !== '')
		$domains = valid_domain($domain) ? array($domain) : array();
	else
		$domains = array_filter(explode("\n", trim((string)shell_exec('sudo ls -1 /etc/exim/forwards/ 2>/dev/null'))), 'valid_domain');
	$out = array();
	foreach ($domains as $d)
		foreach (forwarder_lines($d) as $line)
			if ($f = forwarder_parse_line($line))
				$out[$f[0].'@'.$d] = array('email' => $f[0].'@'.$d, 'domain' => $d, 'to' => $f[1], 'pipes' => $f[2], 'other' => $f[3]);
	ksort($out);
	return array_values($out);
}

function forwarder_get($email) {
	list($user, $domain) = email_split(strtolower((string)$email));
	if (!valid_domain($domain))
		return null;
	foreach (forwarder_list($domain) as $f)
		if ($f['email'] === $user.'@'.$domain)
			return $f;
	return null;
}

/* "a@b, c@d" or array -> array(addresses, error). */
function forwarder_targets($to) {
	$list = is_array($to) ? $to : explode(',', str_replace(array("\n", ';', ' '), ',', (string)$to));
	$out = array();
	foreach ($list as $t) {
		$t = strtolower(trim((string)$t));
		if ($t === '')
			continue;
		if (!valid_email_address($t))
			return array(array(), "$t is not an email address.");
		$out[$t] = $t;
	}
	if (count($out) > 20)
		return array(array(), 'A forwarder can send to up to 20 addresses.');
	return array(array_values($out), '');
}

/* A pipe is a program that already exists, given by its full path. */
function forwarder_pipe_check($pipe) {
	if (!preg_match('#^/[A-Za-z0-9._/+-]{1,250}$#', $pipe) || strpos($pipe, '/../') !== false)
		return 'A pipe must be the full path of a program, like /home/user/bin/script.';
	exec('sudo -n test -x '.escapeshellarg($pipe), $o, $rc);
	return $rc === 0 ? '' : "$pipe is not a program that can be run.";
}

/*
 * Create a forwarder.
 * $o keys: email ("user@domain"); to (addresses, array or "a@b, c@d");
 *   pipe (a program's full path, '' = none; the server's admin only - callers
 *   for an account must not pass it).
 */
function forwarder_create(array $o) {
	list($user, $domain) = email_split(strtolower((string)($o['email'] ?? '')));
	$email = $user.'@'.$domain;
	if (!valid_email_user($user) || !valid_domain($domain))
		return result_error('The address must look like name@example.com.');
	if (!email_domain_exists($domain))
		return result_error("Mail is not turned on for $domain.");
	list($to, $e) = forwarder_targets($o['to'] ?? '');
	if ($e !== '')
		return result_error($e);
	$pipe = trim((string)($o['pipe'] ?? ''));
	if ($pipe !== '' && ($e = forwarder_pipe_check($pipe)) !== '')
		return result_error($e);
	if (!$to && $pipe === '')
		return result_error('Give at least one address to forward to.');
	if (forwarder_get($email))
		return result_error("$email already forwards. Change that forwarder instead.");
	$owner = email_domain_owner($domain);
	if (($e = limit_allows($owner, 'forwarder', array('email' => $email))) !== '')
		return result_error($e);

	route_log("create forwarder $email -> ".implode(', ', $to).($pipe !== '' ? " |$pipe" : ''));
	$lines = forwarder_lines($domain);
	$lines[] = forwarder_line($user, $to, $pipe !== '' ? array($pipe) : array(), array());
	forwarder_write($domain, $lines);
	plugin_hook('forwarder.created', array('email' => $email, 'account' => $owner));
	return result_ok("$email now forwards to ".implode(', ', $pipe !== '' ? array_merge($to, array("|$pipe")) : $to).'.',
		array('email' => $email, 'to' => $to));
}

/*
 * Change a forwarder.
 * $o keys: email (the existing one); to (the new list of addresses);
 *   pipe (null or absent = keep the pipes it has, '' = remove them, a path =
 *   that one; the server's admin only); new_email (move it to another address).
 * Hand-written targets are kept.
 */
function forwarder_edit(array $o) {
	$old = forwarder_get($o['email'] ?? '');
	if (!$old)
		return result_error('There is no forwarder for '.strtolower((string)($o['email'] ?? '')).'.');
	list($to, $e) = forwarder_targets($o['to'] ?? '');
	if ($e !== '')
		return result_error($e);
	$pipes = $old['pipes'];
	if (array_key_exists('pipe', $o) && $o['pipe'] !== null) {
		$pipe = trim((string)$o['pipe']);
		if ($pipe !== '' && ($e = forwarder_pipe_check($pipe)) !== '')
			return result_error($e);
		$pipes = $pipe !== '' ? array($pipe) : array();
	}
	if (!$to && !$pipes && !$old['other'])
		return result_error('Give at least one address to forward to, or delete the forwarder.');

	$new = strtolower(trim((string)($o['new_email'] ?? '')));
	if ($new === '' || $new === $old['email'])
		$new = $old['email'];
	list($nuser, $ndomain) = email_split($new);
	if ($new !== $old['email']) {
		if (!valid_email_user($nuser) || !valid_domain($ndomain))
			return result_error('The address must look like name@example.com.');
		if (!email_domain_exists($ndomain))
			return result_error("Mail is not turned on for $ndomain.");
		if (forwarder_get($new))
			return result_error("$new already forwards. Change that forwarder instead.");
	}

	route_log("edit forwarder {$old['email']}".($new !== $old['email'] ? " => $new" : '').' -> '.implode(', ', $to));
	list($ouser, $odomain) = email_split($old['email']);
	$lines = array();
	foreach (forwarder_lines($odomain) as $line) {
		$f = forwarder_parse_line($line);
		if ($f && $f[0] === $ouser)
			continue;
		$lines[] = $line;
	}
	$line = forwarder_line($nuser, $to, $pipes, $old['other']);
	if ($ndomain === $odomain) {
		$lines[] = $line;
		forwarder_write($odomain, $lines);
	} else {
		forwarder_write($odomain, $lines);
		$nl = forwarder_lines($ndomain);
		$nl[] = $line;
		forwarder_write($ndomain, $nl);
	}
	return result_ok($new !== $old['email'] ? "The forwarder of {$old['email']} is now $new." : "Forwarder $new saved.",
		array('email' => $new, 'to' => $to));
}

function forwarder_delete($email) {
	$old = forwarder_get($email);
	if (!$old)
		return result_error('There is no forwarder for '.strtolower((string)$email).'.');
	list($user, $domain) = email_split($old['email']);
	route_log("delete forwarder {$old['email']}");
	$lines = array();
	foreach (forwarder_lines($domain) as $line) {
		$f = forwarder_parse_line($line);
		if ($f && $f[0] === $user)
			continue;
		$lines[] = $line;
	}
	forwarder_write($domain, $lines);
	plugin_hook('forwarder.deleted', array('email' => $old['email']));
	return result_ok("Forwarder {$old['email']} was deleted.", array('email' => $old['email']));
}

/* ---- Autoresponders ---------------------------------------------------------
   The autoresponders table holds them (one per mailbox); the live part is a
   Sieve vacation script (or, on old servers, an exim autoreply file) that
   autoresponder_apply() writes while today is inside date_from..date_to.
   scripts/manage_autoresponders.php turns them on and off as the dates come. */

function autoresponder_row_out(array $r) {
	$today = date('Y-m-d');
	if ($r['date_from'] !== '' && $r['date_from'] > $today)
		$state = 'scheduled';
	elseif ($r['date_to'] !== '' && $r['date_to'] < $today)
		$state = 'ended';
	else
		$state = 'on';
	return array('id' => (int)$r['id'], 'email' => strtolower($r['user'].'@'.$r['domain']), 'domain' => $r['domain'],
		'subject' => (string)$r['subject'], 'message' => (string)$r['message'],
		'date_from' => (string)$r['date_from'], 'date_to' => (string)$r['date_to'], 'state' => $state);
}

/* Autoresponders, by address. $domain: only this one ('' = all). state: on |
   scheduled (starts later) | ended (date_to has passed). */
function autoresponder_list($domain = '') {
	global $db;
	$domain = strtolower(trim((string)$domain));
	$stmt = $db->prepare('SELECT * FROM autoresponders'.($domain !== '' ? ' WHERE domain = :d' : '').' ORDER BY domain, user');
	if ($domain !== '')
		$stmt->bindValue(':d', $domain, SQLITE3_TEXT);
	$res = $stmt->execute();
	$out = array();
	while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC)))
		$out[] = autoresponder_row_out($r);
	return $out;
}

/* One autoresponder by address or by id, or null. */
function autoresponder_get($key) {
	global $db;
	if (is_int($key) || ctype_digit((string)$key)) {
		$stmt = $db->prepare('SELECT * FROM autoresponders WHERE id = :id');
		$stmt->bindValue(':id', (int)$key, SQLITE3_INTEGER);
	} else {
		list($user, $domain) = email_split(strtolower((string)$key));
		$stmt = $db->prepare('SELECT * FROM autoresponders WHERE lower(user) = :u AND domain = :d');
		$stmt->bindValue(':u', $user, SQLITE3_TEXT);
		$stmt->bindValue(':d', $domain, SQLITE3_TEXT);
	}
	$res = $stmt->execute();
	$r = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;
	return $r ? autoresponder_row_out($r) : null;
}

/* subject, message, date_from, date_to from $o -> array(values, error). */
function autoresponder_fields(array $o, array $old = null) {
	$v = array();
	foreach (array('subject', 'message', 'date_from', 'date_to') as $k)
		$v[$k] = array_key_exists($k, $o) && $o[$k] !== null ? trim(str_replace("\r\n", "\n", (string)$o[$k])) : ($old ? $old[$k] : '');
	$v['subject'] = str_replace(array("\r", "\n"), ' ', $v['subject']);
	if ($v['subject'] === '')
		return array($v, 'The subject cannot be empty.');
	if (strlen($v['subject']) > 250)
		return array($v, 'The subject can have up to 250 characters.');
	if ($v['message'] === '')
		return array($v, 'The message cannot be empty.');
	if (strlen($v['message']) > 20000)
		return array($v, 'The message can have up to 20000 characters.');
	foreach (array('date_from', 'date_to') as $k)
		if ($v[$k] !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v[$k]) || !strtotime($v[$k])))
			return array($v, 'Dates must look like 2026-12-31.');
	if ($v['date_from'] !== '' && $v['date_to'] !== '' && $v['date_to'] < $v['date_from'])
		return array($v, 'The last day must not be before the first day.');
	return array($v, '');
}

/* Write or remove the live part to match the dates. Returns '' or the error. */
function autoresponder_sync(array $a) {
	list($user, $domain) = email_split($a['email']);
	$today = date('Y-m-d');
	$on = ($a['date_from'] === '' || $a['date_from'] <= $today) && ($a['date_to'] === '' || $a['date_to'] >= $today);
	return $on ? autoresponder_apply($user, $domain, $a['subject'], $a['message']) : autoresponder_remove($user, $domain);
}

/*
 * Create an autoresponder.
 * $o keys: email (a mailbox), subject, message (%subject% = the subject of the
 *   message answered), date_from / date_to (YYYY-MM-DD, '' = no limit).
 */
function autoresponder_create(array $o) {
	global $db;
	list($user, $domain) = email_split(strtolower((string)($o['email'] ?? '')));
	$email = $user.'@'.$domain;
	if (!valid_email_user($user) || !valid_domain($domain))
		return result_error('The address must look like name@example.com.');
	if (!mailbox_exists($user, $domain))
		return result_error("$email is not a mailbox on this server.");
	if (autoresponder_get($email))
		return result_error("$email already has an autoresponder. Change it instead.");
	list($v, $e) = autoresponder_fields($o);
	if ($e !== '')
		return result_error($e);
	$owner = email_domain_owner($domain);
	if (($e = limit_allows($owner, 'autoresponder', array('email' => $email))) !== '')
		return result_error($e);

	$stmt = $db->prepare('INSERT INTO autoresponders (user, domain, subject, message, date_from, date_to) VALUES (:u, :d, :s, :m, :df, :dt)');
	foreach (array(':u' => $user, ':d' => $domain, ':s' => $v['subject'], ':m' => $v['message'], ':df' => $v['date_from'], ':dt' => $v['date_to']) as $k => $x)
		$stmt->bindValue($k, $x, SQLITE3_TEXT);
	if (!$stmt->execute())
		return result_error('The autoresponder could not be saved.');
	route_log("create autoresponder $email");
	$a = autoresponder_get($email);
	if (($e = autoresponder_sync($a)) !== '')
		return result_error("The autoresponder of $email was saved but could not be turned on: $e", $a);
	plugin_hook('autoresponder.created', array('email' => $email, 'account' => $owner));
	return result_ok("Autoresponder for $email saved.", $a);
}

/* Change an autoresponder ($o: email or id, and the fields to change). */
function autoresponder_edit(array $o) {
	global $db;
	$old = autoresponder_get(isset($o['id']) && $o['id'] !== '' ? (int)$o['id'] : (string)($o['email'] ?? ''));
	if (!$old)
		return result_error('There is no such autoresponder.');
	list($v, $e) = autoresponder_fields($o, $old);
	if ($e !== '')
		return result_error($e);
	$stmt = $db->prepare('UPDATE autoresponders SET subject = :s, message = :m, date_from = :df, date_to = :dt WHERE id = :id');
	foreach (array(':s' => $v['subject'], ':m' => $v['message'], ':df' => $v['date_from'], ':dt' => $v['date_to']) as $k => $x)
		$stmt->bindValue($k, $x, SQLITE3_TEXT);
	$stmt->bindValue(':id', $old['id'], SQLITE3_INTEGER);
	if (!$stmt->execute())
		return result_error('The autoresponder could not be saved.');
	route_log("edit autoresponder {$old['email']}");
	$a = autoresponder_get($old['id']);
	if (($e = autoresponder_sync($a)) !== '')
		return result_error("The autoresponder of {$old['email']} was saved but could not be applied: $e", $a);
	return result_ok("Autoresponder for {$old['email']} saved.", $a);
}

/* Delete an autoresponder (by address or id). */
function autoresponder_delete($key) {
	global $db;
	$old = autoresponder_get($key);
	if (!$old)
		return result_error('There is no such autoresponder.');
	$stmt = $db->prepare('DELETE FROM autoresponders WHERE id = :id');
	$stmt->bindValue(':id', $old['id'], SQLITE3_INTEGER);
	$stmt->execute();
	route_log("delete autoresponder {$old['email']}");
	list($user, $domain) = email_split($old['email']);
	/* the row is gone either way; a helper failure only leaves a stale script */
	$e = autoresponder_remove($user, $domain);
	plugin_hook('autoresponder.deleted', array('email' => $old['email']));
	return result_ok("Autoresponder for {$old['email']} deleted.", array('email' => $old['email']), $e !== '' ? array("Its script could not be removed: $e") : array());
}
