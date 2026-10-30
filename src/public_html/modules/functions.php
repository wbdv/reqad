<?php
/* CLI scripts (scripts/update_disk_usage, adduserdomain, checkdomain, ...) include
   this file directly without defines.php, so define the app root here when it is
   missing rather than fataling on the top-level define()s further down. */
if (!defined('_PATH'))
	define('_PATH', '/usr/local/reqad');

/* The panel database is written by the panel, the CLI, cron jobs and background
   runs at once. Without a busy timeout a write that meets another one's lock
   fails at once ("database is locked") - an account delete once left its row
   behind that way. defines.php opens it and is not replaced on updates, so the
   timeout is set here, where every entry point passes. */
if (isset($db) && $db instanceof SQLite3)
	$db->busyTimeout(5000);

function log_debug($message) {
	global $_debug;
	if(isset($_debug) && $_debug == true) {
		$remote = isset($_SERVER["REMOTE_ADDR"]) ? $_SERVER["REMOTE_ADDR"] : 'cli';   // CLI has no REMOTE_ADDR
		#die(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$remote." ".$message."\n");
		error_log(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$remote." ".$message."\n", 3, __DIR__.'/../../log/debug_log');
		#echo(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$_SERVER["REMOTE_ADDR"]." ".$message."\n");
	}
}

/*
 * The system timezone, as systemd sees it (/etc/localtime symlink target).
 * PHP falls back to UTC when date.timezone is unset in php.ini, which made the
 * panel render server times in UTC while systemd (shutdown -r 23:00, cron, ...)
 * works in local time. Read the real zone instead of trusting php.ini.
 */
function system_timezone() {
	static $tz = null;
	if($tz !== null) return $tz;

	$link = @readlink('/etc/localtime');                 // ../usr/share/zoneinfo/Europe/Bucharest
	if($link !== false && ($p = strpos($link, 'zoneinfo/')) !== false) {
		$tz = substr($link, $p + strlen('zoneinfo/'));
	} elseif(is_readable('/etc/timezone')) {             // debian-ism, harmless to support
		$tz = trim(file_get_contents('/etc/timezone'));
	}

	if(!$tz || !in_array($tz, timezone_identifiers_list())) {
		$tz = date_default_timezone_get();
	}
	return $tz;
}

/*
 * Sanitize user input. Moved here from defines.php so it lives with the other
 * general helpers. The function_exists() guard avoids a fatal redeclaration on
 * installs whose un-migrated defines.php still defines clean().
 */
if (!function_exists('clean')) {
	function clean($s, $pattern = '/[^a-zA-Z0-9:_\. \'"\+\-\(\)@]*/') {
		return preg_replace($pattern, '', $s);
	}
}

/*
 * HTML-escape for template output.
 *
 * Domain names, account names and database names were echoed raw in several
 * templates. They are admin-supplied, but validation was unanchored until now,
 * so a domain like `evil"><script>...` passed the form and was stored -- making
 * this a real stored-XSS path rather than a theoretical one. ENT_QUOTES matters
 * because several of these land inside data-bs-* attributes.
 */
function h($s) {
	return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/* A small (i) icon next to a problem the panel shows, opening the reqad.com
   docs section that says how to fix it. $page = docs slug, optionally with
   #anchor (e.g. 'email#stopped'). Output is ready to echo. */
function doc_hint($page, $title = 'How to fix this') {
	return '<a href="https://www.reqad.com/docs/'.h($page).'" target="_blank" rel="noopener"'
		.' class="doc-hint text-muted ms-1" title="'.h($title).'" aria-label="'.h($title).'">'
		.'<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;">'
		.'<path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="12" cy="12" r="9"/><line x1="12" y1="8" x2="12.01" y2="8"/><polyline points="11 12 12 12 12 16 13 16"/></svg></a>';
}

/*
 * Input validation helpers.
 *
 * The same two patterns were copy-pasted across create_account, edit_account,
 * create_email, create_database and wp_auto_login -- all of them UNANCHORED, so
 * any string merely *containing* a valid domain/username passed (`evil"><x>.com`
 * validated fine and got stored, which is how unescaped template output turned
 * into stored XSS). Anchored once here, used everywhere.
 */

/* Hosting account / system username: lowercase letter, then alnum, 2-16 chars. */
function valid_username($u) {
	return preg_match('/^[a-z][a-z0-9]{1,15}$/', (string)$u) === 1;
}

/* Domain name. Labels of alnum/dash, dot-separated, alphabetic TLD. */
function valid_domain($d) {
	$d = (string)$d;
	if (strlen($d) > 253)
		return false;
	return preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)*\.[a-z]{2,}$/', $d) === 1;
}

/* Local part of an email address (the bit before the @).
   The dot rules are not pedantry: the local part is concatenated into paths such
   as /etc/exim/autoreply/<domain>/<user>, so a mailbox literally named ".." made
   that resolve to the parent directory. No slash is allowed through, so it could
   never climb more than one level, but "." and ".." are not addresses anyone can
   send mail to either -- and RFC 5321 dot-atom already forbids a leading dot, a
   trailing dot and two dots in a row. Rejecting them here closes both at once. */
function valid_email_user($u) {
	$u = (string)$u;
	if (preg_match('/^[A-Za-z0-9\+\-_\.]{1,64}$/', $u) !== 1)
		return false;
	if ($u[0] === '.' || substr($u, -1) === '.' || strpos($u, '..') !== false)
		return false;
	return true;
}

/* Full email address, for forwarder destinations. */
function valid_email_address($e) {
	$e = (string)$e;
	$at = strrpos($e, '@');
	if ($at === false)
		return false;
	return valid_email_user(substr($e, 0, $at)) && valid_domain(strtolower(substr($e, $at + 1)));
}

/*
 * MySQL helpers.
 *
 * Every caller used to build `sudo mysql -e '<sql>'` by concatenation, which
 * layered two escaping problems on top of each other: shell quoting and SQL
 * quoting. Values that arrive from `SHOW DATABASES` / `mysql.db` rather than
 * from the admin's keyboard (see delete_database.php) were not escaped for
 * either.
 *
 * mysql_exec() pipes the statement in on stdin, so the shell layer disappears
 * completely. Identifiers must still be validated by the caller with
 * valid_mysql_identifier(); mysql_quote() handles string literals.
 */

/* Escape a value for use inside a single-quoted MySQL string literal. */
function mysql_quote($s) {
	return "'".str_replace(
		array("\\",   "'",   "\"",   "\n",  "\r",  "\x00", "\x1a"),
		array("\\\\", "\\'", "\\\"", "\\n", "\\r", "\\0",   "\\Z"),
		(string)$s)."'";
}

/* Run one SQL statement as the MySQL superuser. Returns stdout+stderr. */
function mysql_exec($sql) {
	return shell_with_stdin('sudo mysql 2>&1', $sql);
}

/* Run a statement and return the rows as an array of lines (-N, no headers). */
function mysql_rows($sql) {
	$out = trim(shell_with_stdin('sudo mysql -N 2>/dev/null', $sql));
	if ($out === '')
		return array();
	return array_map('trim', explode("\n", $out));
}

/*
 * Run a command with $input on its stdin and return stdout.
 *
 * Used for the SSL cert/key checks, which used to do
 * `shell_exec("echo '$pem' | openssl ...")`. A PEM blob is multi-line pasted
 * input, so a stray quote in it broke the shell quoting and the check silently
 * reported a mismatch. Piping via proc_open keeps the data out of the command
 * line entirely.
 */
function shell_with_stdin($cmd, $input) {
	$desc = array(0 => array('pipe','r'), 1 => array('pipe','w'), 2 => array('pipe','w'));
	$proc = proc_open($cmd, $desc, $pipes);
	if (!is_resource($proc))
		return '';
	fwrite($pipes[0], $input);
	fclose($pipes[0]);
	$out = stream_get_contents($pipes[1]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	proc_close($proc);
	return $out;
}

/*
 * Set a system account's password without going through the shell.
 *
 * This used to be `shell_exec("echo '$user:$password' | sudo chpasswd")`, so a
 * password containing a single quote broke out of the quoting -- which in
 * practice meant the password was silently set to something other than what was
 * typed, or not set at all. Writing to chpasswd's stdin removes the shell from
 * the path entirely, so any character is safe.
 *
 * Returns true when chpasswd exits 0.
 */
function set_system_password($user, $password) {
	$desc = array(0 => array('pipe','r'), 1 => array('pipe','w'), 2 => array('pipe','w'));
	$proc = proc_open('sudo chpasswd', $desc, $pipes);
	if (!is_resource($proc))
		return false;
	fwrite($pipes[0], $user.':'.$password."\n");
	fclose($pipes[0]);
	$out = stream_get_contents($pipes[1]);
	$err = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$rc = proc_close($proc);
	if ($rc !== 0)
		log_debug('chpasswd failed for '.$user.' (rc='.$rc.'): '.trim($out.' '.$err));
	return $rc === 0;
}

/* Comma-separated list of forward destinations, as the forwarder forms accept
   ("a@x.com, b@y.com"). Every entry must be a valid address; an empty list is
   rejected here and handled by the callers' separate `$forward != ''` check. */
function valid_forward_list($list) {
	$parts = array_filter(array_map('trim', explode(',', (string)$list)), 'strlen');
	if (!count($parts))
		return false;
	foreach ($parts as $p) {
		if (!valid_email_address($p))
			return false;
	}
	return true;
}

/* MySQL identifier (database or user name) -- alnum + underscore only.
   Used to gate values that reach `sudo mysql -e` as bare SQL identifiers,
   where escapeshellarg() protects the shell but NOT the SQL. */
function valid_mysql_identifier($s) {
	return preg_match('/^[A-Za-z0-9_]{1,64}$/', (string)$s) === 1;
}

/* ===========================================================================
   Databases page: system databases, optimize, remote access
   ===========================================================================

   Remote access is per (database, user, IP). MariaDB matches accounts on
   (User, Host), so "wiki_user may connect from 203.0.113.7" is a separate
   account wiki_user@203.0.113.7: it is cloned from wiki_user@localhost (same
   credentials, taken from SHOW CREATE USER) and granted only the one database.
   The firewall side is scripts/sync_mysql_remote_fw.sh, which rebuilds the
   tagged csf.allow rules from the account list after every change.
*/

/* Databases the panel never lists or touches. */
function db_hidden_databases() {
	return array('information_schema', 'mysql', 'performance_schema', 'phpmyadmin', 'roundcube', 'sys');
}

/* A database the page manages: valid name, exists, not a system database. */
function db_is_managed($dbname) {
	return valid_mysql_identifier($dbname)
		&& !in_array($dbname, db_hidden_databases(), true)
		&& in_array($dbname, mysql_rows('SHOW DATABASES'), true);
}

/* Hosts that count as local, i.e. never "remote access". */
function mysql_local_hosts_sql() {
	return "('localhost','127.0.0.1','::1')";
}

/* SQL matching mysql.db.Db for $dbname. A grant made with the `_` wildcard
   escaped (phpMyAdmin does this) is stored as wiki\_db, the panel's as wiki_db. */
function mysql_db_match_sql($dbname) {
	return 'Db IN ('.mysql_quote($dbname).', '.mysql_quote(str_replace('_', '\\_', $dbname)).')';
}

/* Literal IPv4/IPv6 address -> canonical text form, or false. IPv6 goes through
   inet_ntop because MariaDB compares the client address against Host as text. */
function db_remote_normalize_ip($ip) {
	$ip = trim((string)$ip);
	if (filter_var($ip, FILTER_VALIDATE_IP) === false)
		return false;
	$ip = inet_ntop(inet_pton($ip));
	if (in_array($ip, array('127.0.0.1', '::1'), true))
		return false;
	return $ip;
}

/* Users holding a localhost grant on $dbname. */
function db_local_users($dbname) {
	return mysql_rows('SELECT DISTINCT User FROM mysql.db WHERE '.mysql_db_match_sql($dbname).
		" AND Host = 'localhost' AND User <> '' AND User NOT LIKE 'rqpma-%' ORDER BY User");   // not the account panel's phpMyAdmin logins
}

/* Remote hosts $dbuser is granted $dbname from. */
function db_remote_hosts($dbname, $dbuser) {
	return mysql_rows('SELECT DISTINCT Host FROM mysql.db WHERE '.mysql_db_match_sql($dbname).
		' AND User = '.mysql_quote($dbuser).' AND Host NOT IN '.mysql_local_hosts_sql().' ORDER BY Host');
}

/* Grant $dbuser remote access to $dbname from $ip. Arguments must already be
   validated. Returns '' on success or the error text. */
function db_remote_add($dbname, $dbuser, $ip) {
	$acct   = mysql_quote($dbuser).'@'.mysql_quote($ip);
	$exists = mysql_rows('SELECT COUNT(*) FROM mysql.user WHERE User = '.mysql_quote($dbuser).' AND Host = '.mysql_quote($ip));
	if (($exists[0] ?? '0') === '0') {
		// Reuse the local account's authentication clause verbatim, so the
		// remote login takes the same password whatever the auth plugin.
		$create = mysql_rows('SHOW CREATE USER '.mysql_quote($dbuser)."@'localhost'");
		$prefix = 'CREATE USER `'.$dbuser.'`@`localhost`';
		$stmt   = $create[0] ?? '';
		if (strpos($stmt, $prefix) !== 0)
			return 'Cannot read the credentials of '.$dbuser.'@localhost.';
		$out = trim(mysql_exec('CREATE USER '.$acct.substr($stmt, strlen($prefix))));
		if ($out !== '')
			return $out;
	}
	// _ escaped: unescaped it is a wildcard matching other databases' names
	$out = trim(mysql_exec('GRANT ALL PRIVILEGES ON '.database_grant_target($dbname).' TO '.$acct));
	return $out;
}

/* Revoke $dbuser's remote access to $dbname from $ip, dropping the remote
   account once nothing else is granted to it. Returns '' or the error text. */
function db_remote_remove($dbname, $dbuser, $ip) {
	$out = database_revoke_all($dbuser, $ip, $dbname);     // either spelling of the name
	if ($out !== '')
		return $out;
	mysql_drop_user_if_unused($dbuser, $ip);
	return '';
}

/* DROP USER $user@$host when it holds no database or table grant. Grants on
   $ignore_db are not counted (DROP DATABASE leaves its mysql.db rows behind). */
function mysql_drop_user_if_unused($user, $host, $ignore_db = '') {
	$u = mysql_quote($user);
	$h = mysql_quote($host);
	$left = mysql_rows('SELECT (SELECT COUNT(*) FROM mysql.db WHERE User = '.$u.' AND Host = '.$h.
		($ignore_db !== '' ? ' AND NOT '.mysql_db_match_sql($ignore_db) : '').')'.
		' + (SELECT COUNT(*) FROM mysql.tables_priv WHERE User = '.$u.' AND Host = '.$h.
		($ignore_db !== '' ? ' AND NOT '.mysql_db_match_sql($ignore_db) : '').')');
	if (($left[0] ?? '') !== '0')
		return false;
	mysql_exec('DROP USER IF EXISTS '.$u.'@'.$h);
	return true;
}

/* Rebuild the csf MySQL allow rules. Returns nocsf|unchanged|updated|error. */
function mysql_remote_fw_sync() {
	$out = trim((string)shell_exec('sudo '._PATH.'/scripts/sync_mysql_remote_fw.sh 2>/dev/null'));
	return $out !== '' ? $out : 'error';
}

/* Can remote clients reach MariaDB at all? Returns
   port, listening (not bound to loopback / skip_networking),
   firewall ('csf' or ''), port_public (port already open to everyone in csf). */
function mysql_remote_status() {
	$st  = array('port' => 3306, 'listening' => false, 'bind' => '', 'firewall' => '', 'port_public' => false);
	$row = mysql_rows("SELECT @@port, IFNULL(@@bind_address, ''), @@skip_networking");
	if ($row) {
		$f = explode("\t", $row[0]);
		$st['port']      = (int)$f[0];
		$st['bind']      = $f[1] ?? '';
		$st['listening'] = ($f[2] ?? '1') === '0'
			&& !in_array($st['bind'], array('127.0.0.1', 'localhost', '::1'), true);
	}
	if (trim((string)shell_exec('sudo test -f /etc/csf/csf.conf && echo 1')) === '1') {
		$st['firewall'] = 'csf';
		$tcp_in = (string)shell_exec("sudo grep -E '^TCP_IN *=' /etc/csf/csf.conf");
		if (preg_match('/"([^"]*)"/', $tcp_in, $m))
			$st['port_public'] = in_array((string)$st['port'], array_map('trim', explode(',', $m[1])), true);
	}
	return $st;
}

/* OPTIMIZE every table of $dbname (validated by the caller). mysqlcheck does
   the per-table work; InnoDB tables are rebuilt (recreate + analyze). */
function db_optimize($dbname) {
	$out    = (string)shell_exec('sudo mysqlcheck --optimize '.escapeshellarg($dbname).' 2>&1');
	$tables = preg_match_all('/^'.preg_quote($dbname, '/').'\.\S+/m', $out);
	$errors = preg_match_all('/^(error\s*:|mysqlcheck: Got error)/mi', $out);
	return array('output' => $out, 'tables' => $tables, 'errors' => $errors);
}

/* ===========================================================================
   Writing values into php.ini / opcache.ini / apcu.ini with sed
   ===========================================================================

   php_settings.php builds `sudo sed -i 's/.../<value>/' <file>` and used to drop
   $_POST straight into it, escaping only & and / — which are sed's specials, not
   the shell's. A single quote in a value therefore closed the sed script and
   everything after it ran in the local shell as the panel user, which has
   NOPASSWD:ALL. These three functions are the fix, and they are meant to be used
   together: validate the value, escape it for sed, and escapeshellarg() the whole
   sed expression so no value can reach the shell as syntax whatever it contains.
*/

/* Is this an acceptable value for a setting of this type?
   'boolean' never carries a posted value (the caller writes On/Off itself).
   'numeric' covers php.ini sizes (128M, 4096k), -1, and the On/Off some numeric
   settings legitimately hold (output_buffering).
   '' is free text — date.timezone has a slash, error_reporting has & and ~,
   mail.force_extra_parameters has a dash and an @ — so the charset is wide, but
   it still admits no quote, backslash, $, backtick, semicolon or newline. */
function php_ini_valid_value($type, $v) {
	$v = (string)$v;
	if (strlen($v) > 255) return false;
	if ($type === 'numeric') return preg_match('/^(-?[0-9]+[KMGkmg]?|On|Off|on|off)$/', $v) === 1;
	return preg_match('#^[A-Za-z0-9_.,:@/&|~^()+= -]*$#', $v) === 1;
}

/* Escape a string used as sed's LEFT-hand side (a BRE).
   addcslashes rather than preg_replace: the character class needs to contain the
   / delimiter and a backslash, which is a quoting trap not worth stepping into.
   & is deliberately NOT escaped here -- it is only special on the right-hand
   side, and \& in a BRE is not portable. */
function sed_escape_pattern($s) {
	return addcslashes((string)$s, '.[]*^$\\/');
}

/* Escape a string used as sed's RIGHT-hand side: backslash, the / delimiter,
   and & (which means "the whole match" in a replacement). */
function sed_escape_replacement($s) {
	return str_replace(array('\\', '/', '&'), array('\\\\', '\\/', '\\&'), (string)$s);
}

/*
 * Write one key/value into the settings table.
 *
 * Every caller used to build the SQL by concatenation, so any value containing
 * a double quote (an SMTP password, a generated API token) silently produced a
 * broken query and the setting was lost or the row corrupted. Bound parameters
 * fix that and remove the injection surface at the same time.
 */
function setting_put($name, $value) {
	global $db;
	$stmt = $db->prepare('INSERT INTO settings (name, value, updated_at) VALUES (:name, :value, datetime("now"))
	                      ON CONFLICT(name) DO UPDATE SET value = :value, updated_at = datetime("now")');
	if (!$stmt)
		return false;
	$stmt->bindValue(':name',  (string)$name,  SQLITE3_TEXT);
	$stmt->bindValue(':value', (string)$value, SQLITE3_TEXT);
	$ok = $stmt->execute();
	$stmt->close();
	return $ok !== false;
}

/* Read one key from the settings table; $default when the row is missing. */
function setting_get($name, $default = '') {
	global $db;
	$stmt = $db->prepare('SELECT value FROM settings WHERE name = :name');
	if (!$stmt)
		return $default;
	$stmt->bindValue(':name', (string)$name, SQLITE3_TEXT);
	$res = $stmt->execute();
	$row = $res ? $res->fetchArray(SQLITE3_NUM) : false;
	$stmt->close();
	return ($row !== false && $row[0] !== null) ? (string)$row[0] : $default;
}

/* ===========================================================================
   Settings page — outgoing mail + nightly self-update
   =========================================================================== */

/* scripts/forward_root_mail.php silently drops the message when there is no
   SMTP server, so forwarding is only worth enabling once one is set. */
function smtp_configured() {
	return setting_get('smtp_server') !== '' && setting_get('smtp_from') !== '';
}

/*
 * Send one message through the sending method (Settings > Sending method).
 * $html is the message body (wrap it in mail_html_layout() for the Reqad
 * look); $text is the plain-text part. '' when sent, else the error.
 * Used for the SMTP test, the login codes / alerts and the messages to
 * hosting accounts ($from_name: their provider, app/functions/notify.php).
 */
function send_mail($to, $subject, $html, $text = '', $to_name = '', $from_name = 'Reqad') {
	if (!smtp_configured())
		return 'No sending method is set up (Settings > Sending method).';
	if (!filter_var($to, FILTER_VALIDATE_EMAIL))
		return 'Not a valid email address.';
	require_once _PATH.'/public_html/dist/libs/phpmailer/PHPMailer.php';
	require_once _PATH.'/public_html/dist/libs/phpmailer/SMTP.php';
	$port = setting_get('smtp_port', '465') === '587' ? 587 : 465;
	$mail = new PHPMailer();
	$mail->isSMTP();
	$mail->SMTPDebug  = SMTP::DEBUG_OFF;
	$mail->Timeout    = 20;
	$mail->Host       = setting_get('smtp_server');
	$mail->Port       = $port;
	$mail->SMTPSecure = $port === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
	$mail->SMTPAuth   = true;
	$mail->Username   = setting_get('smtp_user');
	$mail->Password   = setting_get('smtp_password');
	$mail->CharSet    = 'UTF-8';
	$mail->setFrom(setting_get('smtp_from'), $from_name !== '' ? $from_name : 'Reqad');
	$mail->addAddress($to, $to_name);
	$mail->Subject = $subject;
	$mail->isHTML(true);
	$mail->Body    = $html;
	$mail->AltBody = $text !== '' ? $text : trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));
	if (!$mail->send())
		return 'SMTP error: '.$mail->ErrorInfo;
	return '';
}

/* The Reqad email look: logo strip, a title, $body_html, footer. $brand
   (messages to a hosting account, app/functions/notify.php): name = the
   hosting provider, shown instead of the Reqad logo; account + to for the
   footer line saying why it came. */
function mail_html_layout($title, $body_html, array $brand = null) {
	$logo = 'data:image/svg+xml;base64,'.base64_encode((string)@file_get_contents(_PATH.'/public_html/images/reqad.svg'));
	$head = $brand ? '<p style="margin:0;font-size:18px;font-weight:bold;color:#232e3c;">'.htmlspecialchars((string)$brand['name']).'</p>'
	               : '<img src="'.$logo.'" alt="Reqad" height="36" border="0" style="display:block;height:36px;max-width:180px;">';
	$foot = $brand && !empty($brand['account'])
		? 'You get this because '.htmlspecialchars((string)$brand['to']).' is the contact address of the hosting account '.htmlspecialchars((string)$brand['account']).'. Choose which messages you get on the Profile page of your hosting panel.'
		: ($brand ? htmlspecialchars((string)$brand['name']) : 'Reqad &mdash; The alternate hosting control panel');
	return '<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background-color:#f4f6f8;font-family:Arial,Helvetica,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f6f8;padding:32px 0;">
    <tr><td align="center">
      <table width="560" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;background-color:#ffffff;overflow:hidden;border:1px solid #e0e4e8;">
        <tr>
          <td style="background-color:#ffffff;padding:20px 32px 16px 32px;border-top: 8px solid #2a6099">
            <br>
            '.$head.'
          </td>
        </tr>
        <tr>
          <td style="background-color:#ffffff;padding:32px 32px 0 32px;border-top:1px solid #e0e4e8;">
            <p style="margin:0;font-size:16pt;color:#000000;">'.htmlspecialchars($title).'</p>
          </td>
        </tr>
        <tr>
          <td style="padding:32px;background-color:#ffffff">'.$body_html.'</td>
        </tr>
        <tr>
          <td style="background-color:#f4f6f8;padding:16px 32px;border-top:1px solid #e0e4e8;">
            <p style="margin:0;font-size:12px;color:#8a929a;text-align:center;">'.$foot.'</p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>';
}

/* Label/value rows for mail_html_layout() bodies (values are escaped). */
function mail_html_facts(array $rows) {
	$out = '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f6f8;border-radius:4px;padding:4px 0;margin:24px 0;">';
	$i = 0;
	foreach ($rows as $label => $value)
		$out .= '<tr'.($i++ % 2 ? ' style="background-color:#eaecef;"' : '').'>'
			.'<td style="padding:10px 16px;font-size:13px;color:#656d77;width:130px;">'.htmlspecialchars($label).'</td>'
			.'<td style="padding:10px 16px;font-size:13px;color:#232e3c;font-weight:bold;">'.htmlspecialchars((string)$value).'</td></tr>';
	return $out.'</table>';
}

/* Read from /etc/aliases rather than a stored flag, so the checkbox reflects
   what exim will actually do. */
function root_mail_forward_active() {
	$aliases = @file_get_contents('/etc/aliases') ?: '';
	return (bool)preg_match('/^root:.*forward_root_mail/mi', $aliases);
}

/* Any root: alias, the forwarder or one set by hand. Without one, exim keeps
   root's mail in /var/log/reqad/root-mail (scripts/root_mail_log.sh). */
function root_mail_has_alias() {
	$aliases = @file_get_contents('/etc/aliases') ?: '';
	return (bool)preg_match('/^root:/mi', $aliases);
}

/* The last $n lines of root's mail kept on the server: root-mail.1 (the older
   10 MB) and root-mail, oldest first. Both are reqad's own 0600 files, so the
   panel reads them directly. The text is mail anyone can send to root: always
   escape it. */
function root_mail_log_tail($n = 100) {
	$lines = array();
	$size  = 0;
	foreach (array('/var/log/reqad/root-mail', '/var/log/reqad/root-mail.1') as $f) {
		$sz = @filesize($f);
		if (!$sz) continue;
		$size += $sz;
		if (count($lines) >= $n) continue;
		$fh = @fopen($f, 'rb');
		if (!$fh) continue;
		$want = 524288;
		fseek($fh, max(0, $sz - $want));
		$chunk = (string)fread($fh, $want);
		fclose($fh);
		$part = explode("\n", rtrim($chunk, "\n"));
		if ($sz > $want) array_shift($part);   /* cut in the middle of a line */
		$lines = array_merge($part, $lines);
	}
	return array('lines' => array_slice($lines, -$n), 'size' => $size);
}

/* The nightly `dnf update 'reqad*'` (scripts/auto_update.sh). On/off is read
   back from /etc/crontab, like the nightly remote backup, so the switch shows
   what cron will actually do. The time is also kept in the settings table
   (auto-update-time, "H:M"), so it survives the entry being switched off. The
   last run comes from the start/end markers the script writes to its log. */
/* Dashboard version label: "1.0.47 (R8, Sep 12, 2026)" from the installed
   package — release number with the dist tag stripped, plus the build date.
   A source checkout has no reqad package, so fall back to version.php's date. */
function reqad_version_label($reqad_version) {
	$out = trim((string)shell_exec("rpm -q --queryformat '%{VERSION}|%{RELEASE}|%{BUILDTIME}' reqad 2>/dev/null"));
	if (preg_match('/^([\d.]+)\|(\d+)[^|]*\|(\d+)$/', $out, $m)) {
		return array($m[1], 'R' . $m[2] . ', ' . date('M j, Y', (int)$m[3]));
	}
	return array($reqad_version[0], date('M j, Y', strtotime($reqad_version[1])));
}

function auto_update_status() {
	/* spread the default minute per host, so installs left on the default do
	   not all hit the repo in the same second */
	$st = array('on' => false, 'hour' => '4', 'min' => (string)(crc32(gethostname()) % 60),
	            'line' => '', 'last' => null, 'installed' => false);
	/* a source checkout (dev boxes) has no reqad package for dnf to update; the
	   add-ons it may carry (reqad-clamav…) do not make it an RPM install */
	$st['installed'] = trim((string)shell_exec('rpm -q --quiet reqad && echo 1')) === '1';
	if (preg_match('/^(\d{1,2}):(\d{1,2})$/', setting_get('auto-update-time'), $m)) {
		$st['hour'] = $m[1];
		$st['min']  = $m[2];
	}

	$line = trim((string)shell_exec("sudo grep -F 'auto_update.sh' /etc/crontab 2>/dev/null | grep -v '^\\s*#' | head -1"));
	if ($line !== '' && preg_match('/^\s*(\d+)\s+(\d+)\s/', $line, $m)) {
		$st['on']   = true;
		$st['line'] = $line;
		$st['min']  = $m[1];
		$st['hour'] = $m[2];
	}

	/* only the tail matters; a run is a few KB */
	$log = _PATH.'/log/auto_update.log';
	$size = @filesize($log);
	if ($size) {
		$fh = @fopen($log, 'r');
		if ($fh) {
			fseek($fh, max(0, $size - 65536));
			$tail = (string)stream_get_contents($fh);
			fclose($fh);
			$start = preg_match_all('/^=== (\S+ \S+) start$/m', $tail, $s) ? end($s[1]) : '';
			if (preg_match_all('/^=== (\S+ \S+) end rc=(\d+) updated=(.*)$/m', $tail, $e)) {
				$i = count($e[1]) - 1;
				$st['last'] = array(
					'time'    => $e[1][$i],
					'rc'      => (int)$e[2][$i],
					'updated' => $e[3][$i] === '' ? array() : explode(',', trim($e[3][$i])),
					'running' => false,
				);
			}
			/* a start with no end after it: running now, or killed mid-run */
			if ($start !== '' && ($st['last'] === null || strcmp($start, $st['last']['time']) > 0))
				$st['last'] = array('time' => $start, 'rc' => 0, 'updated' => array(), 'running' => true);
		}
	}
	return $st;
}

/* Add or remove the auto_update.sh line in /etc/crontab. Same mechanics as
   remote_backup_cron.php — strip our line, then append the new one — so the
   entry also shows on the Cron page. Not /etc/cron.d/reqad: that file is an RPM
   %config, and the very update this runs would fight us for it. Returns '' on
   success, otherwise the error. */
function auto_update_cron_write($enabled, $hour, $min) {
	$script = '/usr/local/reqad/scripts/auto_update.sh';
	/* the script logs to log/auto_update.log itself */
	$line   = (int)$min.' '.(int)$hour.' * * * root '.$script.' > /dev/null 2>&1';

	/* scratch file in /etc, not /tmp — see remote_backup_cron.php */
	$err = '';
	$strip = 'grep -vF '.escapeshellarg($script).' /etc/crontab > /etc/crontab.reqad_new'
	       . ' && mv -f /etc/crontab.reqad_new /etc/crontab';
	exec('sudo -n bash -c '.escapeshellarg($strip), $o, $rc);
	if ($rc !== 0) {
		$err = 'Could not update /etc/crontab.';
	} elseif ($enabled) {
		exec('echo '.escapeshellarg($line).' | sudo -n tee --append /etc/crontab > /dev/null', $o2, $rc2);
		if ($rc2 !== 0) $err = 'Could not write the cron entry.';
	}
	exec('sudo -n chmod 644 /etc/crontab; sudo -n chown root:root /etc/crontab');
	return $err;
}

/* dnf-automatic: system-wide unattended updates, on its own systemd timer (the
   package's OnCalendar sets the time, not us). Four timer variants ship; any of
   them enabled counts as on, and 'installs' says whether that variant actually
   applies updates — dnf-automatic.timer only does when automatic.conf says
   apply_updates = yes. */
function dnf_automatic_status() {
	$st = array('installed' => false, 'on' => false, 'timer' => '', 'installs' => false,
	            'next' => '', 'last' => '');
	$st['installed'] = trim((string)shell_exec('rpm -q --quiet dnf-automatic && echo 1')) === '1';
	if (!$st['installed'])
		return $st;

	$out = (string)shell_exec('systemctl show -p Id -p UnitFileState -p ActiveState'
		.' -p NextElapseUSecRealtime -p LastTriggerUSec'
		.' dnf-automatic-install.timer dnf-automatic.timer dnf-automatic-download.timer dnf-automatic-notifyonly.timer 2>/dev/null');
	foreach (preg_split('/\n\s*\n/', trim($out)) as $block) {
		$u = array();
		foreach (explode("\n", $block) as $kv) {
			$p = strpos($kv, '=');
			if ($p !== false) $u[substr($kv, 0, $p)] = trim(substr($kv, $p + 1));
		}
		if (($u['UnitFileState'] ?? '') !== 'enabled' && ($u['ActiveState'] ?? '') !== 'active')
			continue;
		$st['on']    = true;
		$st['timer'] = $u['Id'] ?? '';
		$st['next']  = $u['NextElapseUSecRealtime'] ?? '';
		$st['last']  = $u['LastTriggerUSec'] ?? '';
		break;
	}

	if ($st['timer'] === 'dnf-automatic-install.timer')
		$st['installs'] = true;
	elseif ($st['timer'] === 'dnf-automatic.timer') {
		$conf = @file_get_contents('/etc/dnf/automatic.conf') ?: '';
		$st['installs'] = (bool)preg_match('/^\s*apply_updates\s*=\s*(yes|true|1|on)\s*$/mi', $conf);
	}
	return $st;
}

/* Turn dnf-automatic on or off. On enables dnf-automatic-install.timer, which
   installs regardless of automatic.conf, installing the package first if it is
   missing. Off disables every variant, so it means off whichever one was used. */
function dnf_automatic_set($enabled) {
	if ($enabled) {
		if (trim((string)shell_exec('rpm -q --quiet dnf-automatic && echo 1')) !== '1') {
			exec('sudo -n dnf -y install dnf-automatic 2>&1', $o, $rc);
			if ($rc !== 0) return 'Could not install dnf-automatic.';
		}
		exec('sudo -n systemctl enable --now dnf-automatic-install.timer 2>&1', $o, $rc);
		return $rc === 0 ? '' : 'Could not enable dnf-automatic-install.timer.';
	}
	exec('sudo -n systemctl disable --now dnf-automatic-install.timer dnf-automatic.timer'
		.' dnf-automatic-download.timer dnf-automatic-notifyonly.timer 2>&1', $o, $rc);
	return $rc === 0 ? '' : 'Could not disable the dnf-automatic timers.';
}

/* The main form on /settings/ ($p = its fields): welcome screen, contact email +
   root mail forwarding, the two automatic-update switches, telemetry. Returns
   array(type, message) for msg_redirect(). Run by modules/settings.php, and by
   the page's modals when the main form had unsaved changes — see
   settings_modal_redirect(). */
function settings_main_save($p) {
	$email   = trim((string)($p['email'] ?? ''));
	$forward = isset($p['root_mail_forward']);

	if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))
		return array('error', 'Settings were not saved: that email address is not valid.');

	setting_put('email', $email);
	setting_put('welcome_dismissed', isset($p['show_welcome']) ? '0' : '1');
	setting_put('telemetry', isset($p['telemetry']) ? '1' : '0');

	/* only when the field was posted: the modals re-post just the main form's
	   changed fields, and an absent key must not wipe the stored one */
	if (isset($p['pagespeed_key'])) {
		$psk = trim((string)$p['pagespeed_key']);
		if ($psk !== '' && !preg_match('/^[A-Za-z0-9_-]{20,100}$/', $psk))
			return array('error', 'Settings were not saved: that PageSpeed Insights API key does not look valid.');
		setting_put('pagespeed-api-key', $psk);
	}

	/* forward_root_mail.php drops the message when there is no address or no SMTP
	   server, so enabling it then would lose root's mail instead of forwarding it */
	$warn = '';
	if ($forward && $email === '')
		$forward = false;
	if ($forward && !smtp_configured()) {
		$forward = false;
		$warn = ' Root mail forwarding was not enabled: configure a sending method first.';
	}

	/* Only touch /etc/aliases when the state changes: the script deletes every
	   root: line, so running "disable" on each save would also wipe a root alias
	   that was set by hand. */
	if ($forward !== root_mail_forward_active())
		shell_exec('sudo -n /usr/local/reqad/scripts/setup_root_mail_alias.sh '.escapeshellarg($forward ? 'enable' : 'disable').' 2>&1');

	/* The update switches, likewise only when they change: an unrelated save must
	   not rewrite /etc/crontab or restart a timer. Both are read back from the
	   system (crontab, systemd), so "changed" is against what is really there. */
	$errs = array();
	$upd = auto_update_status();
	/* not an RPM install: the switch is disabled (so never posted) — leave the
	   crontab alone rather than read that as "off" */
	if ($upd['installed'] && isset($p['auto_update']) !== $upd['on'])
		$errs[] = auto_update_cron_write(isset($p['auto_update']), $upd['hour'], $upd['min']);
	if (isset($p['dnf_automatic']) !== dnf_automatic_status()['on'])
		$errs[] = dnf_automatic_set(isset($p['dnf_automatic']));
	/* http(s)://<site>/webmail: only when the row was on the page (email on)
	   and the switch changed - writing every site's vhost is not free */
	if (isset($p['webmail_link_row']) && isset($p['webmail_link']) !== (setting_get('webmail-link', '1') !== '0')) {
		setting_put('webmail-link', isset($p['webmail_link']) ? '1' : '0');
		if ($w = webmail_links_sync_all())
			$errs[] = 'The /webmail address was not changed on every site: '.implode('; ', $w);
	}

	$errs = array_filter($errs);
	if ($errs)
		return array('error', 'Settings were saved, but: '.implode(' ', $errs));

	if ($warn !== '')
		return array('warning', 'Settings were saved.'.$warn);
	return array('success', 'Settings were saved.');
}

/* The modals on /settings/ (sending method, Reqad update time) are forms of
   their own, so submitting one reloads the page — which used to drop anything
   changed but not yet saved in the main form. The page now posts those changes
   along as main[...], only when there are any. Save them after the modal's own
   settings, which they may depend on (forwarding root mail needs a sending
   method), then redirect with both outcomes in one message. */
function settings_modal_redirect($url, $message, $type) {
	if (isset($_POST['main']) && is_array($_POST['main'])) {
		list($t, $m) = settings_main_save($_POST['main']);
		$message .= ' '.$m;
		$rank = array('success' => 0, 'info' => 0, 'warning' => 1, 'error' => 2);
		if ($rank[$t] > ($rank[$type] ?? 0))
			$type = $t;
	}
	msg_redirect($url, $message, $type);
}

/* ===========================================================================
   Remote backup (scripts/backup_remote.sh) — configuration + ssh plumbing
   ===========================================================================

   Credentials live in the settings table, like the DNS API tokens. They used to
   live only in defines.php ($backup_server/$backup_user/$backup_sshport/
   $backup_sshkey), which the panel has never written and which an RPM update
   never rewrites — so every install that predates this page still has them
   there, and backupdb.php still reads them from there. remote_backup_config()
   therefore reads the settings rows and falls back to those globals per key,
   which is what lets an existing server keep working with no action at all.
*/
function remote_backup_config() {
	global $db, $backup_server, $backup_user, $backup_sshport, $backup_sshkey;

	$cfg = array('host'=>'', 'user'=>'', 'port'=>'', 'key'=>'', 'dest'=>'', 'keep'=>'', 'dbmax'=>'');
	$map = array(
		'host'  => 'backup-remote-host',  'user'  => 'backup-remote-user',
		'port'  => 'backup-remote-port',  'key'   => 'backup-remote-key',
		'dest'  => 'backup-remote-dest',  'keep'  => 'backup-remote-keep',
		'dbmax' => 'backup-remote-dbmax',
	);
	$rows = array();
	$res = $db->query("SELECT name,value FROM settings WHERE name LIKE 'backup-remote-%'");
	if ($res) { while ($r = $res->fetchArray(SQLITE3_ASSOC)) { $rows[$r['name']] = $r['value']; } }
	foreach ($map as $k => $name) {
		if (isset($rows[$name]) && $rows[$name] !== '') $cfg[$k] = (string)$rows[$name];
	}

	/* per-key fallback to defines.php (never the other way round) */
	if ($cfg['host'] === '' && isset($backup_server))  $cfg['host'] = (string)$backup_server;
	if ($cfg['user'] === '' && isset($backup_user))    $cfg['user'] = (string)$backup_user;
	if ($cfg['port'] === '' && isset($backup_sshport)) $cfg['port'] = (string)$backup_sshport;
	if ($cfg['key']  === '' && isset($backup_sshkey))  $cfg['key']  = (string)$backup_sshkey;

	if ($cfg['port']  === '') $cfg['port']  = '22';
	if ($cfg['keep']  === '') $cfg['keep']  = '4';
	/* backup_remote.sh's retention ladder has 9 rungs; older installs saved
	   "newest N" values above that, and the script clamps them the same way */
	if (ctype_digit($cfg['keep']) && (int)$cfg['keep'] > 9) $cfg['keep'] = '9';
	if ($cfg['dbmax'] === '') $cfg['dbmax'] = '1024';
	return $cfg;
}

/* What backup_remote.sh --keep N holds on to, one phrase per rung of its
   KEEP_LADDER (keep the two in step). */
function remote_backup_keep_rungs() {
	return array(
		'the newest backup', 'the 2nd newest', '1st of this month', '15th of this month',
		'1st of last month', 'the 3rd newest', '15th of last month', 'the 4th newest',
		'1st of the month before last',
	);
}

function remote_backup_configured($cfg) {
	return $cfg['host'] !== '' && $cfg['user'] !== '';
}

/* One-time copy of the defines.php values into the settings table, so the form
   on the Backup page has something to edit. Returns true only when it actually
   wrote something. defines.php itself is left byte-identical — it simply stops
   being the source of truth. */
function remote_backup_import_defines() {
	global $db, $backup_server, $backup_user, $backup_sshport, $backup_sshkey;

	$have = 0;
	$res = $db->query("SELECT count(*) AS n FROM settings WHERE name LIKE 'backup-remote-%'");
	if ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) $have = (int)$r['n'];
	if ($have > 0) return false;                       /* already configured here */

	$host = isset($backup_server) ? (string)$backup_server : '';
	$user = isset($backup_user)   ? (string)$backup_user   : '';
	if ($host === '' || $user === '') return false;    /* nothing worth importing */

	setting_put('backup-remote-host', $host);
	setting_put('backup-remote-user', $user);
	setting_put('backup-remote-port', isset($backup_sshport) && $backup_sshport !== '' ? (string)$backup_sshport : '22');
	setting_put('backup-remote-key',  isset($backup_sshkey)  ? (string)$backup_sshkey  : '');
	return true;
}

/*
 * Run one command on the backup server.
 *
 * sudo, because the ssh key is root's (/root/.ssh/...) — same as backupdb.php.
 * EVERY part is escapeshellarg()d, including the remote command: backupdb.php
 * interpolates $_GET straight into its ssh line, and that is exactly the bug not
 * to reproduce here. The remote command itself still runs in a remote shell, so
 * anything embedded in it must have been whitelisted by the caller first.
 */
function remote_backup_ssh_cmdline($cfg, $remote_cmd) {
	$opts = '-p '.escapeshellarg($cfg['port'])
	      . ' -o BatchMode=yes -o StrictHostKeyChecking=accept-new'
	      . ' -o ConnectTimeout=10 -o ServerAliveInterval=15';
	if ($cfg['key'] !== '') $opts .= ' -i '.escapeshellarg($cfg['key']);

	return 'sudo -n ssh '.$opts.' '
	     . escapeshellarg($cfg['user'].'@'.$cfg['host']).' '
	     . escapeshellarg($remote_cmd);
}

function remote_backup_ssh($cfg, $remote_cmd, &$rc = null) {
	$out = array();
	$rc  = 0;
	exec(remote_backup_ssh_cmdline($cfg, $remote_cmd).' 2>&1', $out, $rc);
	/* csf rejects (DROP_OUT=REJECT → "refused") or drops (→ timeout) outbound
	   traffic to ports missing from TCP_OUT — typically a custom ssh port. Open
	   it and try once more; the helper is a no-op when the port is already open. */
	if ($rc === 255 && preg_match('/Connection refused|timed out|No route to host/i', implode("\n", $out))
	    && csf_open_tcp_out($cfg['port']) === 'updated') {
		$out = array();
		exec(remote_backup_ssh_cmdline($cfg, $remote_cmd).' 2>&1', $out, $rc);
	}
	return implode("\n", $out);
}

/* Make sure csf allows outgoing TCP to $port. Returns nocsf|unchanged|updated|error. */
function csf_open_tcp_out($port) {
	if (!ctype_digit((string)$port)) return 'error';
	$out = trim((string)shell_exec('sudo -n '._PATH.'/scripts/csf_open_tcp_out.sh '.escapeshellarg((string)$port).' 2>/dev/null'));
	return $out !== '' ? strtok($out, "\n") : 'error';
}

/*
 * Send one remote file to the browser.
 *
 * passthru(), NOT `echo shell_exec()` the way backupdb.php streams its dumps:
 * shell_exec buffers the entire file in PHP memory first, which for a 48 GB
 * account archive is not a slow download, it is a dead worker. passthru writes
 * through as it reads. The caller must have validated $remote_path already.
 */
function remote_backup_stream($cfg, $remote_path, $bytes, $filename) {
	while (ob_get_level() > 0) ob_end_clean();      /* nothing may buffer this */
	header('Content-Type: application/octet-stream');
	if ($bytes > 0) header('Content-Length: '.(int)$bytes);
	header('Content-Disposition: attachment; filename="'.$filename.'"');
	header('Content-Transfer-Encoding: binary');
	header('Cache-Control: private, must-revalidate');
	passthru(remote_backup_ssh_cmdline($cfg, "cat '".$remote_path."'"));
}

/* Remote path of the dated backup directories ('' dest = the ssh user's home). */
function remote_backup_base($cfg) {
	return $cfg['dest'] !== '' ? rtrim($cfg['dest'], '/').'/' : '';
}

function valid_backup_date($d) {
	return preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', (string)$d) === 1;
}

/*
 * List the dated backups on the backup server.
 *
 * One ssh round trip for the whole table, and it never reads an archive — du on
 * a dated directory walks a few hundred directory entries, not 78 GB of data.
 * Cached, because otherwise every page load (and every browser prefetch) would
 * open an ssh connection to another machine.
 */
function remote_backup_dates($cfg, $force = false) {
	$cache = _PATH.'/log/remote_backup.cache';
	if (!$force && is_file($cache)) {
		$cached = @unserialize((string)file_get_contents($cache));
		$age    = time() - filemtime($cache);
		$ttl    = (is_array($cached) && !empty($cached['ok'])) ? 60 : 15;
		if (is_array($cached) && $age < $ttl) return $cached;
	}

	$base = remote_backup_base($cfg);
	/* Single quotes inside the remote command are fine: escapeshellarg() turns
	   each one into '\'' when it wraps the whole thing. Escaped DOUBLE quotes are
	   not — they do not survive the local shell, ssh and the remote shell. */
	$cmd = 'cd '.($base === '' ? '.' : "'".rtrim($base,'/')."'").' 2>/dev/null || exit 0'."\n"
	     . 'for d in 20[0-9][0-9]-[0-9][0-9]-[0-9][0-9]; do'."\n"
	     . '  [ -d "$d" ] || continue'."\n"
	     . '  kb=$(du -sk "$d" 2>/dev/null | cut -f1)'."\n"
	     . '  n=$(ls -1 "$d"/accounts/*.tar.gz 2>/dev/null | wc -l)'."\n"
	     . '  st=$(sed -n \'s/^failed *: //p\' "$d/MANIFEST.txt" 2>/dev/null | head -1 | tr \' \' \',\')'."\n"
	     . '  echo "DATE $d $kb $n $st"'."\n"
	     . 'done'."\n"
	     . 'echo "FREE $(df -Pk . | awk \'NR==2 {print $4}\')"'."\n";

	$rc  = 0;
	$out = remote_backup_ssh($cfg, $cmd, $rc);
	$dates = array('ok' => ($rc === 0), 'error' => ($rc === 0 ? '' : trim($out)),
	               'rows' => array(), 'free_kb' => 0);
	if ($rc === 0) {
		foreach (explode("\n", $out) as $line) {
			$f = explode(' ', trim($line));
			if (isset($f[0]) && $f[0] === 'FREE' && isset($f[1]) && ctype_digit($f[1])) {
				$dates['free_kb'] = (int)$f[1];
				continue;
			}
			if (count($f) < 4 || $f[0] !== 'DATE' || !valid_backup_date($f[1])) continue;
			$failed = isset($f[4]) ? trim($f[4]) : '';
			$dates['rows'][] = array(
				'date'     => $f[1],
				'kb'       => (int)$f[2],
				'accounts' => (int)$f[3],
				/* MANIFEST.txt is written only when a run finishes, so no manifest
				   at all means the run was interrupted — not that it was clean. */
				'status'   => ($failed === '' ? 'incomplete' : ($failed === 'none' ? 'ok' : 'failed')),
				'failed'   => ($failed === 'none' ? '' : str_replace(',', ' ', $failed)),
			);
		}
		usort($dates['rows'], function ($a, $b) { return strcmp($b['date'], $a['date']); });
	}
	/* Failures are cached as well, or an unreachable backup server would cost a
	   full ConnectTimeout on every single page load now that this is the tab the
	   Backup page opens on. They expire faster than successes (see the read at
	   the top of this function) so a fixed connection shows up quickly. */
	@file_put_contents($cache, serialize($dates));
	@chmod($cache, 0640);
	return $dates;
}

/*
 * Everything the drill-down needs for ONE date, in one ssh call: the per-account
 * archives, the .index files backup_remote.sh writes beside them (so we can say
 * what is restorable without streaming a 48 GB archive back to run `tar tz`),
 * the separately streamed database dumps, and the system bundles.
 */
function remote_backup_date_detail($cfg, $date) {
	$res = array('ok'=>false, 'error'=>'', 'totalkb'=>0, 'accounts'=>array(), 'system'=>array(), 'manifest'=>'');
	if (!valid_backup_date($date)) { $res['error'] = 'invalid date'; return $res; }

	$dir = remote_backup_base($cfg).$date;
	$cmd = 'cd '."'".$dir."'".' 2>/dev/null || { echo ERR nodir; exit 0; }'."\n"
	     . 'echo "TOTALKB $(du -sk . 2>/dev/null | cut -f1)"'."\n"
	     . 'for f in accounts/*.tar.gz; do [ -e "$f" ] || continue'."\n"
	     . '  u=${f##*/}; u=${u%.tar.gz}; echo "ACCOUNT $u $(stat -c \'%s %Y\' "$f")"'."\n"
	     . 'done'."\n"
	     . 'for f in accounts/*.index; do [ -e "$f" ] || continue'."\n"
	     . '  u=${f##*/}; u=${u%.index}; sed "s|^|IDX $u |" "$f"'."\n"
	     . 'done'."\n"
	     . 'for f in databases/*/*.sql.gz; do [ -e "$f" ] || continue'."\n"
	     . '  u=${f%/*}; u=${u##*/}; b=${f##*/}; b=${b%.sql.gz}'."\n"
	     . '  echo "DBGZ $u $b $(stat -c %s "$f")"'."\n"
	     . 'done'."\n"
	     . 'for f in system/*; do [ -e "$f" ] || continue'."\n"
	     . '  echo "SYS ${f##*/} $(stat -c %s "$f")"'."\n"
	     . 'done'."\n"
	     . 'sed "s|^|MAN |" MANIFEST.txt 2>/dev/null'."\n";

	$rc  = 0;
	$out = remote_backup_ssh($cfg, $cmd, $rc);
	if ($rc !== 0) { $res['error'] = trim($out); return $res; }

	$acct = array();
	foreach (explode("\n", $out) as $line) {
		$line = rtrim($line);
		$f = explode(' ', $line);
		switch ($f[0]) {
			case 'ERR':
				$res['error'] = 'no backup for '.$date.' on the backup server';
				return $res;
			case 'TOTALKB':
				$res['totalkb'] = (int)(isset($f[1]) ? $f[1] : 0);
				break;
			case 'ACCOUNT':
				if (count($f) < 4 || !valid_username($f[1])) break;
				if (!isset($acct[$f[1]])) $acct[$f[1]] = array('user'=>$f[1],'bytes'=>0,'mtime'=>0,'home'=>array(),'db'=>array(),'domain'=>'','home_kb'=>0);
				$acct[$f[1]]['bytes'] = (int)$f[2];
				$acct[$f[1]]['mtime'] = (int)$f[3];
				break;
			case 'IDX':
				/* IDX <user> <key> <rest...> — the index format written by backup_remote.sh */
				if (count($f) < 3 || !valid_username($f[1])) break;
				$u = $f[1];
				if (!isset($acct[$u])) $acct[$u] = array('user'=>$u,'bytes'=>0,'mtime'=>0,'home'=>array(),'db'=>array(),'domain'=>'','home_kb'=>0);
				if ($f[2] === 'domain'  && isset($f[3])) $acct[$u]['domain']  = $f[3];
				if ($f[2] === 'home_kb' && isset($f[3])) $acct[$u]['home_kb'] = (int)$f[3];
				if ($f[2] === 'home' && isset($f[4]))    $acct[$u]['home'][$f[3]] = $f[4];
				if ($f[2] === 'db'   && isset($f[5]))    $acct[$u]['db'][$f[3]] = array('kb'=>(int)$f[4], 'src'=>$f[5]);
				/* excl <kind> <path> — what the archive leaves out; the path is the
				   rest of the line, it may hold spaces */
				if ($f[2] === 'excl' && isset($f[4]))    $acct[$u]['excl'][] = array('kind'=>$f[3], 'path'=>implode(' ', array_slice($f, 4)));
				break;
			case 'DBGZ':
				/* ground truth for a backup taken before .index existed */
				if (count($f) < 4 || !valid_username($f[1]) || !valid_mysql_identifier($f[2])) break;
				$u = $f[1];
				if (!isset($acct[$u])) $acct[$u] = array('user'=>$u,'bytes'=>0,'mtime'=>0,'home'=>array(),'db'=>array(),'domain'=>'','home_kb'=>0);
				if (!isset($acct[$u]['db'][$f[2]])) $acct[$u]['db'][$f[2]] = array('kb'=>(int)round($f[3]/1024), 'src'=>'remote');
				break;
			case 'SYS':
				if (count($f) < 3) break;
				$res['system'][] = array('name'=>basename($f[1]), 'bytes'=>(int)$f[2]);
				break;
			case 'MAN':
				$res['manifest'] .= substr($line, 4)."\n";
				break;
		}
	}
	ksort($acct);
	$res['accounts'] = $acct;
	$res['ok'] = true;
	return $res;
}

/* Human-readable size from KB / bytes. */
function human_kb($kb) {
	$u = array('KB','MB','GB','TB'); $i = 0; $v = (float)$kb;
	while ($v >= 1024 && $i < count($u) - 1) { $v /= 1024; $i++; }
	return ($v >= 10 || $i === 0 ? round($v) : round($v, 1)).' '.$u[$i];
}

/*
 * Whole-server restore (scripts/restore_server.sh).
 *
 * Two helpers: may this install run one at all, and what is the one that is
 * running doing right now.
 */

/* array('ok'=>bool, 'reason'=>string) — the same two conditions the script
   enforces, asked here so the button can be hidden with an explanation rather
   than failing forty minutes in. */
function restore_server_allowed($db, $ini) {
	if (!(isset($ini['root_access']) ? (int)$ini['root_access'] : 1))
		return array('ok'=>false, 'reason'=>'Root access is disabled on this install (root_access=0 in server-software.ini).');
	$n = 0;
	$r = $db->query('SELECT count(*) AS n FROM accounts');
	if ($r && ($row = $r->fetchArray(SQLITE3_ASSOC))) $n = (int)$row['n'];
	if ($n > 0)
		return array('ok'=>false, 'n'=>$n, 'reason'=>'This server already has '.$n.' account'.($n===1?'':'s').
			'. A full server restore rebuilds users with their original uid/gid and unpacks /etc over the top, so it only runs on an empty server.');
	return array('ok'=>true, 'n'=>0, 'reason'=>'');
}

/* Parse log/restore_server.status. The file is append-only, so the last record
   for a given key wins and a half-written final line is simply skipped — a
   status file rewritten in place would sometimes be read mid-write. */
function restore_server_status() {
	$out = array('found'=>false, 'running'=>false, 'date'=>'', 'pid'=>0, 'started'=>0,
	             'phases'=>array(), 'accounts'=>array(), 'end'=>null);
	$f = _PATH.'/log/restore_server.status';
	if (!is_readable($f)) return $out;
	$lines = @file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	if (!$lines) return $out;
	foreach ($lines as $line) {
		$p = explode("\t", $line, 2);
		if (count($p) < 2) continue;
		$ts = (int)$p[0];
		$w  = preg_split('/\s+/', trim($p[1]));
		if (!$w || count($w) < 2) continue;
		$out['found'] = true;
		switch ($w[0]) {
			case 'run':
				$out['date'] = $w[1]; $out['pid'] = (int)(isset($w[2]) ? $w[2] : 0);
				$out['started'] = $ts; break;
			case 'phase':
			case 'account':
				$k = ($w[0] === 'phase') ? 'phases' : 'accounts';
				$out[$k][$w[1]] = array(
					'state'  => isset($w[2]) ? $w[2] : '',
					'detail' => implode(' ', array_slice($w, 3)),
					'ts'     => $ts);
				break;
			case 'end':
				$out['end'] = array('state'=>$w[1], 'detail'=>implode(' ', array_slice($w, 2)), 'ts'=>$ts);
				break;
		}
	}
	/* /proc rather than posix_kill: the panel's php.ini does not load the posix
	   extension, and a stale pid must not read as "still running" for ever. */
	$out['running'] = ($out['end'] === null && $out['pid'] > 0 && is_dir('/proc/'.$out['pid']));
	return $out;
}

/*
 * TLS verification for outbound API calls.
 *
 * Every api_*.php connector used to hardcode VERIFYPEER/VERIFYHOST off, so the
 * Cloudflare / WHM / PowerDNS bearer tokens went out over connections that
 * accepted any certificate. Verification is now ON by default.
 *
 * Two connectors talk to hosts that legitimately may present a self-signed or
 * hostname-mismatched cert (WHM on :2087, a PowerDNS agent on a private IP), so
 * those get a per-provider escape hatch in the settings table. Cloudflare is a
 * public host with a valid cert and has no opt-out.
 *
 * Pass $provider ('cpanel'/'powerdns') to honour <provider>-insecure-tls;
 * omit it for providers that must always verify.
 */
function curl_set_tls($curl, $provider = '') {
	global $settings;
	$insecure = $provider !== ''
		&& isset($settings[$provider.'-insecure-tls'])
		&& $settings[$provider.'-insecure-tls'] == '1';

	curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, $insecure ? false : true);
	curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, $insecure ? 0 : 2);
}

/*
 * CSRF protection.
 *
 * Reqad has no sessions, so the token is derived deterministically from the
 * authenticated Nginx user plus a per-install secret stored once in the
 * settings table. Every rendered page can regenerate the same token, and any
 * cross-site request (which cannot read the secret) fails the check.
 *
 * Enforcement is centralised in index.php: every POST must carry a valid token
 * (in the `csrf` field for forms, or the X-CSRF-Token header for AJAX). GET
 * requests stay read-only, so they need no token. See templates/footer.php for
 * the client side that injects the token into forms and AJAX calls.
 */

/* Per-install random secret, lazily created in the settings table. */
function csrf_secret() {
	global $db;
	static $secret = null;
	if ($secret !== null)
		return $secret;

	$row = $db->querySingle('SELECT value FROM settings WHERE name="csrf-secret"', true);
	if (is_array($row) && isset($row['value']) && $row['value'] !== '') {
		$secret = $row['value'];
		return $secret;
	}

	$secret = bin2hex(random_bytes(32));
	$stmt = $db->prepare('INSERT INTO settings (name, value, updated_at)
	                      VALUES ("csrf-secret", :v, datetime("now"))');
	$stmt->bindValue(':v', $secret, SQLITE3_TEXT);
	$stmt->execute();
	return $secret;
}

/* The token for the current authenticated user. In web-login mode it is the
   session's own random token (rotated at every login); on basic auth it is
   derived from the per-install secret as before. */
function csrf_token() {
	if (function_exists('auth_web_enabled') && auth_web_enabled()) {
		$s = auth_session_current();
		return $s ? $s['csrf'] : hash('sha256', 'no-session|'.csrf_secret());
	}
	$user = isset($_SERVER['USER']) ? $_SERVER['USER'] : '';
	return hash('sha256', $user.'|'.csrf_secret());
}

/* Read the token supplied by the client (form field or AJAX header). */
function csrf_request_token() {
	if (isset($_POST['csrf']) && is_string($_POST['csrf']))
		return $_POST['csrf'];
	if (isset($_SERVER['HTTP_X_CSRF_TOKEN']))
		return $_SERVER['HTTP_X_CSRF_TOKEN'];
	return '';
}

/* Abort with 403 unless the request carries a valid token. */
function csrf_check() {
	if (!hash_equals(csrf_token(), csrf_request_token())) {
		http_response_code(403);
		header('Content-Type: text/plain; charset=utf-8');
		echo 'Forbidden: invalid or missing CSRF token.';
		exit;
	}
}

/*
 * Reusable flash message queue — Post/Redirect/Get (PRG) helper.
 *
 * Stored in a SEPARATE SQLite DB (db/messages.db) so it stays decoupled from the
 * main app schema/migrations. No sessions required (Reqad has none).
 *
 * Usage:
 *   - In an action module, after a POST side-effect:
 *         msg_redirect($url, $text, $type);   // 302 -> $url?msgid=<token>, then exit
 *   - In a template, to display any pending message once:
 *         msg_render();                        // reads $_GET['msgid'], shows + marks seen
 *
 * Types: success | error | info | warning
 *
 * The redirect carries an opaque random token in ?msgid=. The message is shown
 * exactly once: on display it is marked seen, so a later refresh (the msgid is
 * still in the URL) renders nothing.
 */

function msg_db() {
    static $mdb = null;
    if ($mdb === null) {
        $mdb = new SQLite3(_PATH.'/db/messages.db');
        $mdb->busyTimeout(3000);
        $mdb->exec('CREATE TABLE IF NOT EXISTS messages (
            token   TEXT PRIMARY KEY,
            type    TEXT NOT NULL DEFAULT "info",
            message TEXT NOT NULL,
            seen    INTEGER NOT NULL DEFAULT 0,
            created INTEGER NOT NULL
        )');
    }
    return $mdb;
}

/* Queue a message; returns its random token (used as ?msgid=). */
function msg_add($message, $type = 'info') {
    $mdb = msg_db();
    $now = time();
    /* prune so the queue never grows unbounded (no cron needed):
       drop anything older than 1h, and seen rows older than 60s */
    $mdb->exec('DELETE FROM messages WHERE created < '.($now - 3600).
               ' OR (seen = 1 AND created < '.($now - 60).')');

    if (!in_array($type, array('success', 'error', 'info', 'warning'), true))
        $type = 'info';
    $token = bin2hex(random_bytes(8));

    $stmt = $mdb->prepare('INSERT INTO messages (token, type, message, seen, created)
                           VALUES (:t, :ty, :m, 0, :c)');
    $stmt->bindValue(':t',  $token,   SQLITE3_TEXT);
    $stmt->bindValue(':ty', $type,    SQLITE3_TEXT);
    $stmt->bindValue(':m',  $message, SQLITE3_TEXT);
    $stmt->bindValue(':c',  $now,     SQLITE3_INTEGER);
    $stmt->execute();
    return $token;
}

/* Queue a message and 302-redirect to $url?msgid=<token>. Stops the request. */
function msg_redirect($url, $message, $type = 'info') {
    $token = msg_add($message, $type);
    $sep = (strpos($url, '?') !== false) ? '&' : '?';
    header('Location: '.$url.$sep.'msgid='.$token);
    exit;
}

/* Return the Tabler alert HTML for a pending (unseen) message token, marking it
   seen so it shows exactly once; '' if the token is invalid/missing/already seen.
   Used both by msg_render() (PRG) and the ajax-msg poll endpoint (async jobs that
   post their result to the queue, e.g. background Let's Encrypt issuance). */
function msg_pull_html($token) {
    /* opaque token guard — also blocks junk/tampered ids before any query */
    if (!preg_match('/^[0-9a-f]{16}$/', $token))
        return '';

    $mdb  = msg_db();
    $stmt = $mdb->prepare('SELECT type, message, seen FROM messages WHERE token = :t');
    $stmt->bindValue(':t', $token, SQLITE3_TEXT);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$row || (int)$row['seen'] === 1)
        return '';

    /* mark seen so a refresh/next poll won't re-show it */
    $up = $mdb->prepare('UPDATE messages SET seen = 1 WHERE token = :t');
    $up->bindValue(':t', $token, SQLITE3_TEXT);
    $up->execute();

    $text = $row['message'];
    if ($row['type'] === 'error')
        $text = preg_replace('/^Error:\s*/', '', $text);

    return msg_alert_html($row['type'], $text);
}

/* Render the pending message (if any), once. Returns true if something shown. */
function msg_render($token = null) {
    if ($token === null)
        $token = isset($_GET['msgid']) ? $_GET['msgid'] : '';
    $html = msg_pull_html($token);
    if ($html === '')
        return false;
    echo $html;
    return true;
}

/* Build the Tabler alert markup, mirroring the existing accounts.php blocks. */
function msg_alert_html($type, $text, $inline = false) {
    $map = array(
        'success' => array('alert-success', '#EFE', 'text-success', 'Success'),
        'error'   => array('alert-warning', '#FFE', 'text-danger',  'Error'),
        'warning' => array('alert-warning', '#FFE', 'text-warning', 'Warning'),
        'info'    => array('alert-info',    '#EEF', 'text-info',    'Info'),
    );
    $c = isset($map[$type]) ? $map[$type] : $map['info'];
    list($alertClass, $bg, $textClass, $heading) = $c;
    $safe = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

    /* $inline: heading and text on one line next to the icon (login page) */
    if ($inline)
        return '
          <div class="alert '.$alertClass.'" role="alert" style="background:'.$bg.';">
            <div class="d-flex align-items-center">
              <div style="width:45px;flex-shrink:0;position:relative;top:3px;">'.msg_icon_svg($type, $textClass).'</div>
              <div class="'.$textClass.'"><strong>'.$heading.':</strong> '.$safe.'</div>
            </div>
          </div>';

    return '
          <div class="alert '.$alertClass.'" role="alert" style="background:'.$bg.';">
            <div class="d-flex">
              <div style="width:55px;">'.msg_icon_svg($type, $textClass).'</div>
              <div>
                <h3 class="'.$textClass.'" style="margin-top:4px;margin-bottom:0">'.$heading.':</h3>
                <div class="'.$textClass.'">'.$safe.'</div>
              </div>
            </div>
          </div>';
}

/* Tabler SVG icon per type (reuses the icons already used in accounts.php). */
function msg_icon_svg($type, $textClass) {
    if ($type === 'success')
        $inner = '<path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="12" cy="12" r="9" /><path d="M9 12l2 2l4 -4" />';
    elseif ($type === 'info')
        $inner = '<path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="12" cy="12" r="9" /><path d="M12 8l.01 0" /><path d="M11 12l1 0l0 4l1 0" />';
    else /* error, warning */
        $inner = '<path stroke="none" d="M0 0h24v24H0z" fill="none"></path><path d="M12 9v2m0 4v.01"></path><path d="M5 19h14a2 2 0 0 0 1.84 -2.75l-7.1 -12.25a2 2 0 0 0 -3.5 0l-7.1 12.25a2 2 0 0 0 1.75 2.75"></path>';

    return '<svg xmlns="http://www.w3.org/2000/svg" class="icon mb-2 '.$textClass.' icon-md" width="48" height="48" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">'.$inner.'</svg>';
}

/*
 * Feature access control based on server-software.ini flags.
 *
 * The nav menu in header.php hides sections for disabled features, but that is
 * only cosmetic — the route/action/ajax handlers still run if the URL is hit
 * directly. These helpers let index.php and ajax.php block access server-side.
 */

// Is an ini feature flag enabled? Section flags default to OFF when absent
// (matching header.php's "isset && ==1" menu checks). root_access defaults to
// ON when absent (matching add_ssh_key.php / delete_ssh_key.php).
// filemanager_compression ("Compress and download" in the file manager, the
// API/MCP compress route) also defaults to ON, and needs filemanager=1 too.
function feature_enabled($ini, $flag) {
	if ($flag === 'root_access')
		return !isset($ini[$flag]) ? true : ((int)$ini[$flag] === 1);
	if ($flag === 'filemanager_compression')
		return feature_enabled($ini, 'filemanager') && (!isset($ini[$flag]) || (int)$ini[$flag] === 1);
	return isset($ini[$flag]) && (int)$ini[$flag] === 1;
}

// ini flag(s) a route requires. A route is allowed only if ALL are enabled.
// terminal only needs terminal=1 — with root_access=0 the page still works, it
// just drops root from the user picker (see templates/terminal.php).
function route_required_features($route) {
	$map = array(
		'email-accounts'       => array('email'),
		'forwarders'           => array('email'),
		'autoresponders'       => array('email'),
		'email-filters'        => array('email'),
		'spam-filters'         => array('email'),
		'check-email-settings' => array('email'),
		'email-stats'          => array('email'),
		'email'                => array('email'),
		'email-config'         => array('email'),
		'wp-toolkit'           => array('wptoolkit'),
		'backup'               => array('backup'),
		'backupdb'             => array('backupdb'),
		'terminal'             => array('terminal'),
		'transfer-tool'        => array('transfer'),
		'addon-domains'        => array('addon_domains'),
		'deployments'          => array('deployments'),
		'apps'                 => array('deployments'),
		'api-access'           => array('api'),
		'ai-access'            => array('mcp'),
		'dns-cluster'          => array('dnscluster'),
		'dns-keys'             => array('dnscluster'),
	);
	if (isset($map[$route]))
		return $map[$route];
	// Plugins declare their own gating feature in the manifest.
	$pl = isset($GLOBALS['plugins'][$route]) ? $GLOBALS['plugins'][$route] : null;
	if ($pl !== null && !empty($pl['feature']))
		return array($pl['feature']);
	return array();
}

// Returns the first disabled feature blocking $route, or null if allowed.
function route_blocked_feature($ini, $route) {
	foreach (route_required_features($route) as $flag)
		if (!feature_enabled($ini, $flag))
			return $flag;
	return null;
}

// ini flag an AJAX action requires, or null if the endpoint is always allowed.
function ajax_required_feature($action) {
	$map = array(
		'ajax-check-email-fixing' => 'email',
		'ajax-check-email'        => 'email',
		'ajax-email'              => 'email',
		'ajax-forward'            => 'email',
		'ajax-autoresponder'      => 'email',
		'ajax-email-filter'       => 'email',
		'ajax-mq-count'           => 'email',
		'ajax-mq-list'            => 'email',
		'ajax-mq-view'            => 'email',
		'ajax-mq-action'          => 'email',
		'ajax-mq-runq'            => 'email',
		'ajax-mq-runq-status'     => 'email',
		'ajax-mq-purge-frozen'    => 'email',
		'ajax-mailconf-validate'  => 'email',
		'ajax-mailconf-save'      => 'email',
		'ajax-mailconf-versions'  => 'email',
		'ajax-mailconf-restore'   => 'email',
		'ajax-webmail-ticket'     => 'email',
		'ajax-mobileconfig'       => 'email',
		'ajax-wp-install'         => 'wptoolkit',
		'ajax-wp-scan'            => 'wptoolkit',
		'ajax-transfer-start'     => 'transfer',
		'ajax-transfer-status'    => 'transfer',
		'ajax-transfer-stop'      => 'transfer',
		'ajax-transfer-accounts'  => 'transfer',
		'ajax-transfer-php'       => 'transfer',
		'ajax-transfer-precheck'  => 'transfer',
		'ajax-fm-list'            => 'filemanager',
		'ajax-fm-read'            => 'filemanager',
		'ajax-fm-save'            => 'filemanager',
		'ajax-fm-mkdir'           => 'filemanager',
		'ajax-fm-newfile'         => 'filemanager',
		'ajax-fm-rename'          => 'filemanager',
		'ajax-fm-chmod'           => 'filemanager',
		'ajax-fm-delete'          => 'filemanager',
		'ajax-fm-upload'          => 'filemanager',
		'ajax-fm-compress'        => 'filemanager_compression',
		'ajax-fm-extract'         => 'filemanager',
		'ajax-fm-download'        => 'filemanager',
		'ajax-restore-server-status' => 'backup',
		'ajax-backup-exclude-preview' => 'backup',
		'ajax-backup-exclude-setting' => 'backup',
		'ajax-backup-exclude-suggest' => 'backup',
		'ajax-terminal-target'    => 'terminal',
		'ajax-deploy-run'         => 'deployments',
		'ajax-deploy-rollback'    => 'deployments',
		'ajax-deploy-log'         => 'deployments',
		'ajax-deploy-releases'    => 'deployments',
		'ajax-deploy-connect'     => 'deployments',
		'ajax-app-control'        => 'deployments',
		'ajax-app-logs'           => 'deployments',
		'ajax-app-env'            => 'deployments',
		'ajax-app-env-save'       => 'deployments',
		'ajax-app-runtime'        => 'deployments',
		'ajax-app-available'      => 'deployments',
	);
	if (isset($map[$action]))
		return $map[$action];
	// Plugins declare per-action gating in the 'ajax_features' manifest key.
	foreach ((isset($GLOBALS['plugins']) ? $GLOBALS['plugins'] : array()) as $pl)
		if (isset($pl['ajax_features'][$action]))
			return $pl['ajax_features'][$action];
	return null;
}

/* ---- Plugin system -------------------------------------------------------- */
/* Add-on modules (e.g. the premium WireGuard package) ship self-contained into
   public_html/plugins/<name>/ and own no core files. Each drops a plugin.php
   that calls plugin_register([...]); core auto-wires the route, sidebar item,
   POST actions and AJAX. See index.php (plugins_load + dispatch fallbacks),
   ajax.php (handler tail) and templates/header.php (nav loop). */

// Register one plugin manifest. Keyed by route so plugin_for_route() is O(1).
// Manifest keys: route, title, icon, feature (ini flag, optional), template,
// actions (POST action names), action_handler, ajax_handler, ajax_features,
// menu_parent ('accounts' / 'settings' = an entry in the Accounts or Settings
// submenu instead of its own sidebar item), cli (commands for bin/reqad, see cli_plugin_command()
// there: array(<command> => array('summary', 'usage' => fn($prog),
// 'spec' => array(<sub> => array('opts' => [...], 'arg' => '')),
// 'run' => fn($prog, $sub, $pos, $opts))) plus a cli.txt line per command,
// "<command>  <summary>", for `reqad --help`), cli_subs (subcommands added to a
// core command, e.g. `reqad account suspend`: array(<command> => array(<sub> =>
// array('opts' => [...], 'arg' => '', 'run' => fn))) plus a cli-subs.txt line
// per sub, "<command> <sub> <arguments><TAB><summary>", for its help).
function plugin_register($m) {
	if (!isset($GLOBALS['plugins']))
		$GLOBALS['plugins'] = array();
	$GLOBALS['plugins'][$m['route']] = $m;
}

// Discover and load every plugin. Called once from index.php at bootstrap,
// before ajax.php is included so ajax handlers are registered in time.
function plugins_load() {
	if (!isset($GLOBALS['plugins']))
		$GLOBALS['plugins'] = array();
	foreach (glob(_PATH.'/public_html/plugins/*/plugin.php') as $p)
		include_once($p);
}

// Manifest for a route, or null.
function plugin_for_route($route) {
	return isset($GLOBALS['plugins'][$route]) ? $GLOBALS['plugins'][$route] : null;
}

// Manifest owning a POST action, or null.
function plugin_for_action($action) {
	foreach ((isset($GLOBALS['plugins']) ? $GLOBALS['plugins'] : array()) as $pl)
		if (in_array($action, isset($pl['actions']) ? $pl['actions'] : array()))
			return $pl;
	return null;
}

/*
 * Event hooks and filters for plugins (premium hosting packages, add-ons).
 *
 * A manifest may declare
 *     'hooks'   => array('account.created' => function (array $data) { ... }),
 *     'filters' => array('limit.allows'    => function ($value, ...$args) { return $value; }),
 *
 * plugin_hook() runs after something happened and cannot stop it; an error in
 * a plugin is logged and never breaks the core operation. plugin_filter()
 * passes a value through every plugin that filters it (limit_allows() uses
 * 'limit.allows'); a plugin that throws keeps the value unchanged.
 *
 * Events fired by core (data keys in brackets):
 *   account.created  [user, domain, uid, package ('' = not asked for)]
 *   account.edited   [user, domain, changed]
 *   account.deleted  [user, domain]
 *   database.created [account, database, user] database.deleted [database, users_dropped]
 *   database_user.created [account, user]     database_user.deleted [user, hosts]
 *   database.access  [user, database, access (all|none)]
 *   database.remote_added / .remote_removed [user, database, ip, account]
 *   php.version      [account, domain, from, to]  php.settings [account, domain, settings (key => value, '' = default)]
 *   mailbox.created  [email, account]          mailbox.deleted [email]
 *   forwarder.created [email, account]         forwarder.deleted [email]
 *   autoresponder.created [email, account]     autoresponder.deleted [email]
 *   mailbox_filter.created [email, name, account]  mailbox_filter.changed / .deleted [email, name]
 *   domain_filter.created [domain, id, name, account]  domain_filter.changed / .deleted [domain, id, name]
 *   spam.changed     [account, changed]        mail_dns.fixed [domain, records]
 *   alias.created    [user, alias, dns (its DNS record was asked for / made)]  alias.deleted [user, alias]
 *   addon.created    [account, domain, docroot]  addon.deleted [account, domain, files]
 *   mail_domain.created [account, domain]  (email turned on for an addon domain)  mail_domain.deleted [account, domain]  (turned off)
 *   redirect.created [account, domain, from, to, code, match, keep, host]  redirect.deleted [account, domain, from, host]
 *     (made from app/functions/domains.php: the API, the account panel)
 *   dns_record.created [account ('' = a server token), zone, name, type, value]
 *   dns_record.changed [account, zone, name, type, value, old: {name, type, value}]  dns_record.deleted [account, zone, name, type, value]
 *     (app/functions/dns.php: the API, the account panel's DNS zone, 2087 DNS Zones)
 *   dns_zone.created [zone, ip, key?]  dns_zone.deleted [zone, account (its owner, '' = none), key]  dns_zone.imported [account, zone, sets]
 *     (app/functions/dns_tools.php: 2087 DNS Zones; filter dns.nameservers = the name servers zones should name;
 *      key = the DNS API key that made / held the zone, app/functions/dns_keys.php)
 *   mail_provider.changed [account, domain, provider (local|google|microsoft|zoho|other), remote (its mail is left to that provider)]
 *     (app/functions/email_tools.php: the API, the account panel's DNS zone)
 *   mail_spf.changed [account, domain, record]  mail_dmarc.changed [account, domain, record]  (the same, SPF / DMARC helpers)
 *   cron.created    [account, id, name, schedule, command]  cron.updated [account, id, name, schedule, command, on]
 *   cron.deleted     [account, id, schedule, command]  (app/functions/cron.php: the API, the account panel)
 *   ssh_key.created [account, id, fingerprint, name]  ssh_key.deleted [account, id, fingerprint, name]
 *     (app/functions/ssh.php: 2087's SSH keys, the API, the account panel)
 *   account.contact_changed [account, email, old]  account.notify_changed [account, kinds]
 *   account.notified [account, kind, to, subject]  (app/functions/notify.php: the account panel's Profile page)
 *   ssl.issued       [account, domain, names]   (a Let's Encrypt certificate, domains.php)
 *   wordpress.scanned [account, added, removed] (installs found / gone, app/functions/wordpress.php)
 *   wordpress.updated [account, domain, what, items]
 *   wordpress.installed [account, domain, path, database]
 *   wordpress.tool    [account, domain, path, tool, on]  (Performance switch, wordpress_tools.php)
 *   wordpress.cache_rules [account, domain, rules]     (Performance > page cache rules, wordpress_tools.php)
 *   wordpress.security [account, domain, path, on]   (Security measures saved)
 *   wordpress.auto    [account, domain, path, core, plugins, themes]  (auto-update settings saved, wordpress_auto.php)
 *   wordpress.copied  [account, domain, kind (staging|clone), source, database]  wordpress.copy_deleted [account, domain, kind, database]
 *     (Clone or staging, wordpress_copy.php)
 *   wordpress.pushed  [account, domain, path, from (the staging copy), items, switches, uploads]  (Push to live, wordpress_push.php)
 *   health.dismissed [id, until, by]
 *   api.token.created [id, name, account, scopes, kind (api | mcp)]   api.token.revoked [id, name]
 *   deployment.created [id, account, domain]   deployment.deleted [id, account, domain]
 *   deployment.finished [id, domain, run, status, source, message]
 *   app.created [id, account, domain, port]   app.deleted [id, account, domain]
 *   app.packages [id, account, domain, status ok|warn|failed, message] (Install packages finished)
 *   service.restarted [service]
 *
 * Filters besides limit.allows: api.routes (extra API routes and MCP tools,
 * app/api/routes.php), health.checks (app/functions/health.php), server.logs
 * (logs the API and MCP may read, app/functions/server.php),
 * account.create.check ('' or why account_create() must refuse its $o, e.g.
 * an unknown package), account.edit.check (the same for account_edit()),
 * account.edit.apply (array(changes, warnings), + user, $o: a plugin's own
 * part of account_edit(), run last), account.info (account_info()'s data,
 * + user), accounts.create.form / accounts.edit.form (HTML added to the
 * Create account / Manage account dialogs; Manage hands fields named
 * plugin[<name>] to account.edit.apply as $o['plugin'][<name>]),
 * accounts.user.badge (HTML after the user name in the accounts list, + user),
 * accounts.columns (extra columns of the accounts list, after User:
 * array(array('title' => ..., 'cell' => fn($row) => HTML), ...); with
 * 'after' => 'domain' after Domain instead),
 * email.create.check / email.edit.check ('' or why email_create() /
 * email_edit() must refuse their $o, + $o, account; e.g. a mailbox size over
 * the package's), email.create.apply / email.edit.apply (array(changes,
 * warnings), + email, ...: a plugin's own part, run after the mailbox line is
 * written), emails.create.form / emails.edit.form (HTML added to the Create /
 * Edit email account dialogs), emails.columns (extra columns of the email
 * accounts list, after Usage; cell gets the mailbox_usage_list() row),
 * emails.usage (the HTML of a mailbox's Usage cell, + that row),
 * api.mailboxes (the rows GET /v1/mailboxes returns, + the token's account
 * or null: a plugin adds its fields, e.g. size_mb),
 * account.active (true, or why the account may not run things now, + user:
 * checked before a deploy run and before an app starts; e.g. suspended),
 * account.notify.kinds (the kinds of message an account can choose, + user:
 * an add-on adds its own and sends them with account_notify_send()),
 * account.notify.brand (array(name, panel) the messages to an account come
 * from, + user: the account panel sets the provider and its address;
 * app/functions/notify.php),
 * ssl.free ('' or why the account may not get free Let's Encrypt
 * certificates, + user, + account_create()'s $o while the account is being
 * made (else null); core asks it through ssl_free_error() in
 * app/functions/domains.php: Hosting Pro's packages can turn them off),
 * ip.list (null, or the server's extra addresses when an add-on gives
 * accounts addresses of their own: address, public, users, mail_domains),
 * account.ip.set (null or a result_ok/result_error(), + user, ipv4, ipv6: an
 * address, or null to leave that kind) and mail.ip.set (the same, + domain,
 * ipv4, ipv6: the address the domain's mail goes out from, '' = none) - the
 * transfer tool's IP restore (transfer_ip_restore()) asks Hosting Pro through
 * these three,
 * stats.needed (false, or true when an add-on needs the sites' logs counted
 * while visitor stats are switched off: Hosting Pro's bandwidth limits;
 * app/functions/stats.php).
 * Plugins may add their own fields to a mailbox's /etc/dovecot/users line;
 * core keeps them when it rewrites the line (mailbox_extra_fields()). For
 * state every site of an account must carry, core writes named marker blocks
 * into the account's vhosts: account_vhost_marker() (app/functions/accounts.php).
 *
 * Events fired by Hosting Pro: account.suspended [user, reason, source]
 * account.unsuspended [user], account.ips [user, ipv4, ipv6], package.*
 * (see plugins/hosting). Core binds an account's vhosts to extra addresses
 * with account_vhost_listen() (app/functions/accounts.php).
 */
/* Core's own listeners, run before the plugins': event => functions. */
function core_hooks() {
	return array(
		/* http(s)://<site>/webmail (webmail_link_sync(), app/functions/emails.php) */
		'account.created' => array('webmail_link_on_event'),
		'account.edited'  => array('webmail_link_on_event'),
		'addon.created'   => array('webmail_link_on_event'),
		'ssl.issued'      => array('webmail_link_on_event'),
		'php.version'     => array('webmail_link_on_event'),
	);
}

function plugin_hook($event, array $data = array()) {
	foreach (core_hooks()[$event] ?? array() as $fn) {
		if (!function_exists($fn))
			continue;
		try {
			$fn($data + array('event' => $event));
		} catch (Throwable $e) {
			log_debug('[core] hook '.$event.' '.$fn.' failed: '.$e->getMessage());
		}
	}
	foreach ((isset($GLOBALS['plugins']) ? $GLOBALS['plugins'] : array()) as $pl) {
		if (empty($pl['hooks'][$event]) || !is_callable($pl['hooks'][$event]))
			continue;
		try {
			call_user_func($pl['hooks'][$event], $data + array('event' => $event));
		} catch (Throwable $e) {
			log_debug('[plugin '.($pl['route'] ?? '?').'] hook '.$event.' failed: '.$e->getMessage());
		}
	}
}

function plugin_filter($name, $value, ...$args) {
	foreach ((isset($GLOBALS['plugins']) ? $GLOBALS['plugins'] : array()) as $pl) {
		if (empty($pl['filters'][$name]) || !is_callable($pl['filters'][$name]))
			continue;
		try {
			$value = call_user_func($pl['filters'][$name], $value, ...$args);
		} catch (Throwable $e) {
			log_debug('[plugin '.($pl['route'] ?? '?').'] filter '.$name.' failed: '.$e->getMessage());
		}
	}
	return $value;
}

// Appliance menu: a plugin can request a minimal sidebar by declaring a
// non-empty 'menu' in its manifest. When any plugin does, header.php hides the
// hosting-panel sections and keeps only Dashboard, add-on plugin items, and
// Reboot (the reqad-wireguard "WireGuard appliance" mode). The 'menu' value is
// reserved for finer per-item control later; presence is the trigger today.
function menu_minimal() {
	foreach ((isset($GLOBALS['plugins']) ? $GLOBALS['plugins'] : array()) as $pl)
		if (!empty($pl['menu']))
			return true;
	return false;
}

// Dashboard "Common Actions" tiles contributed by plugins. A manifest may
// declare 'dashboard' => array( array('label'=>, 'url'=>, 'icon'=>html), ... ).
// Feature-gated like the sidebar items. Returns a flat list of action items.
function plugin_dashboard_actions($ini) {
	$out = array();
	foreach ((isset($GLOBALS['plugins']) ? $GLOBALS['plugins'] : array()) as $pl) {
		if (empty($pl['dashboard'])) continue;
		if (!empty($pl['feature']) && !(isset($ini[$pl['feature']]) && $ini[$pl['feature']] == 1)) continue;
		foreach ($pl['dashboard'] as $a)
			$out[] = $a;
	}
	return $out;
}

/* ---- File Manager --------------------------------------------------------- */
/* The file operations themselves are app/functions/files.php (run as the
   account by scripts/files/fm-run.sh); the 2087 modal's ajax-fm-* handlers
   only find the account. */

/* Confirm an account exists and return its sanitized user, or false. Mirrors the
   guard used by ajax-config-* (SELECT ... WHERE user=...). */
function fm_account_user($db, $raw_user) {
	$u = preg_replace('/[^a-z0-9]/', '', trim((string)$raw_user));
	if ($u === '') return false;
	$res  = $db->query('SELECT user FROM accounts WHERE user="' . $db->escapeString($u) . '"');
	$acct = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;
	return $acct ? $u : false;
}

/* ---- Alias domains -------------------------------------------------------- */

/* Return all alias rows for an account (accounts.id), www.* first then A→Z. */
function get_aliases($db, $account_id) {
	$rows = array();
	$account_id = (int)$account_id;
	$res = $db->query('SELECT id, alias, is_wildcard, ssl_status FROM aliases WHERE account_id='.$account_id.' ORDER BY (alias LIKE "www.%") DESC, alias ASC');
	if($res) while($r = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $r;
	return $rows;
}

/* Validate a proposed alias for an account. Returns '' if OK, else an error
   message. Shared by alias_add.php and the ajax-alias validator so the modal and
   the server agree. $acct_domain is the account's main domain. */
function alias_validation_error($db, $acct_domain, $alias) {
	$alias = strtolower(trim($alias));
	if($alias === '')
		return 'Please enter an alias domain.';

	$is_wild = (substr($alias, 0, 2) === '*.');
	$bare    = $is_wild ? substr($alias, 2) : $alias;

	if(!preg_match('/^([a-z0-9]([a-z0-9\-]*[a-z0-9])?\.)+[a-z]{2,}$/', $bare))
		return 'Please enter a valid domain name.';
	if(strpos($bare, '*') !== false)
		return 'Wildcards are only allowed as a leading "*." label.';
	if($alias === $acct_domain)
		return 'The alias cannot be the main domain.';
	if($alias === 'mail.'.$acct_domain)
		return 'mail.'.$acct_domain.' is managed automatically when email is enabled.';

	$r = $db->query('SELECT user FROM accounts WHERE domain="'.$db->escapeString($alias).'"');
	if($r && $r->fetchArray())
		return 'That domain is already a hosting account on this server.';

	$r = $db->query('SELECT id FROM aliases WHERE alias="'.$db->escapeString($alias).'"');
	if($r && $r->fetchArray())
		return 'That alias already exists.';

	return '';
}

/* Build the server_name token lists for a domain + its alias rows.
   mail.<domain> is added to both :80 and :443 when the account has email, so
   certbot can answer HTTP-01 for it and nginx serves it with the extended cert. */
function account_server_names($domain, $alias_rows, $has_email = false) {
	$names = $domain;
	foreach($alias_rows as $a) $names .= ' '.$a['alias'];
	$mail = $has_email ? ' mail.'.$domain : '';
	return array(
		'http'  => $names.$mail,
		'https' => $names.$mail,
	);
}

/* The names a new account's vhost template answers to (nginx server_name,
   Apache ServerAlias - the same in the :80 and :443 blocks). account_create()
   passes $account_aliases (www.<domain> or none) and $has_email; the old
   adduserdomain path sets neither: www, no mail. */
function template_server_names($domain, $account_aliases = null, $has_email = false) {
	$aliases = is_array($account_aliases) ? $account_aliases : array('www.'.$domain);
	$rows = array_map(function ($a) { return array('alias' => $a); }, $aliases);
	return account_server_names($domain, $rows, $has_email)['http'];
}

/* ---- Web server template --------------------------------------------------- */

/* What the configured template (server-software.ini `template`) means, so code
   asks about capabilities instead of testing the template's name:
     web_front()      nginx | httpd   owns :80/:443 - vhosts, certificates, SNI, server names
     web_backend()    none  | httpd   a second server behind the front, on 127.0.0.1
     php_mode()       fpm   | modphp  modphp = mod_php is the default handler, php-fpm optional per account
     web_php_server() nginx | httpd   the server whose vhost holds the PHP handler
   A template may ship scripts/templates/<template>.ini (front=, backend=, php=);
   without one the name decides: apache_* is httpd + mod_php, anything else
   nginx + php-fpm. scripts/web_caps.sh reads the same files for the shell scripts. */
function web_caps($ini = null) {
	static $cache = array();
	if ($ini === null)
		$ini = $GLOBALS['ini'] ?? array();
	$tpl = basename(trim((string)($ini['template'] ?? '')));
	if (isset($cache[$tpl]))
		return $cache[$tpl];

	$caps = (substr($tpl, 0, 7) == 'apache_')
		? array('front' => 'httpd', 'backend' => 'none', 'php' => 'modphp')
		: array('front' => 'nginx', 'backend' => 'none', 'php' => 'fpm');
	$file = (defined('_PATH') ? _PATH : '/usr/local/reqad').'/scripts/templates/'.$tpl.'.ini';
	/* RAW: the normal scanner turns "none" into '' */
	if ($tpl !== '' && is_readable($file) && is_array($f = @parse_ini_file($file, false, INI_SCANNER_RAW))) {
		$valid = array('front' => array('nginx', 'httpd'), 'backend' => array('none', 'httpd'), 'php' => array('fpm', 'modphp'));
		foreach ($valid as $k => $ok)
			if (isset($f[$k]) && in_array(trim($f[$k]), $ok, true))
				$caps[$k] = trim($f[$k]);
	}
	return $cache[$tpl] = $caps;
}

function web_front($ini = null)   { return web_caps($ini)['front']; }
function web_backend($ini = null) { return web_caps($ini)['backend']; }
function php_mode($ini = null)    { return web_caps($ini)['php']; }

function web_php_server($ini = null) {
	$c = web_caps($ini);
	return $c['backend'] !== 'none' ? $c['backend'] : $c['front'];
}

/* Group of document roots and php-fpm sockets: the front server's user, which
   serves the static files. A backend joins that group (apache is a member of
   nginx on nginx_apache_php-fpm), so nothing on disk changes with it. */
function web_group($ini = null) {
	return web_server(web_front($ini))['user'];
}

/* Where a backend server listens, on loopback only. nginx proxies https
   requests to an https backend vhost, so Apache sees a real TLS connection:
   %{HTTPS} in .htaccess, $_SERVER['HTTPS'] and the port are what the visitor
   used, and "force https" rules do not loop. */
function web_backend_addr($https = true) {
	return $https ? '127.0.0.1:8443' : '127.0.0.1:8080';
}

/* Paths and commands of one web server, 'nginx' or 'httpd'. 'user' is the
   server's own Unix user (pool listen.group, docroot group). */
function web_server($server) {
	if ($server === 'httpd')
		return array('service' => 'httpd', 'bin' => 'httpd', 'test' => 'httpd -t', 'dir' => '/etc/httpd/conf.d',
		             'user' => 'apache', 'label' => 'Apache', 'certbot' => 'apache');
	return array('service' => 'nginx', 'bin' => 'nginx', 'test' => 'nginx -t', 'dir' => '/etc/nginx/conf.d',
	             'user' => 'nginx', 'label' => 'nginx', 'certbot' => 'nginx');
}

/* A domain's vhost file on $server, the front server by default. */
function web_vhost_path($domain, $server = null) {
	return web_server($server ?? web_front())['dir'].'/'.$domain.'.conf';
}

/* Rewrite an account's live vhost server_name/ServerAlias from the alias table,
   validate the server config, and reload. Returns '' on success, or an error
   string (the original file is restored on a failed config test). The names
   live in the front server's vhost. */
function apply_account_vhost_names($db, $domain) {
	$res = $db->query('SELECT id, has_email FROM accounts WHERE domain="'.$db->escapeString($domain).'"');
	$account = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;
	if(!$account) return 'Account not found for '.$domain;

	$aliases = get_aliases($db, $account['id']);
	$names   = account_server_names($domain, $aliases, !empty($account['has_email']));
	$server  = web_front();
	$ws      = web_server($server);
	$file    = web_vhost_path($domain, $server);

	$content = shell_exec('sudo cat '.escapeshellarg($file).' 2>/dev/null');
	if($content === null || trim($content) === '')
		return 'vhost file not found: '.$file;

	if($server === 'nginx') {
		/* Two server blocks: 1st server_name = :80 (http), 2nd = :443 (https). */
		$i = 0;
		$new = preg_replace_callback('/^([ \t]*)server_name[ \t]+[^;]*;/m', function($m) use (&$i, $names) {
			$i++;
			$val = ($i === 1) ? $names['http'] : $names['https'];
			return $m[1].'server_name '.$val.';';
		}, $content);
	} else {
		/* Apache: keep ServerName, rewrite ServerAlias to domain + aliases (+ mail). */
		$new = preg_replace('/^([ \t]*)ServerAlias[ \t]+.*$/m', '${1}ServerAlias    '.trim($names['http']), $content);
	}
	if($new === null || $new === $content)
		return apply_backend_vhost_names($domain, $names['https']);   // nothing to change in the front vhost

	$tmp = tempnam('/tmp', 'reqad');
	file_put_contents($tmp, $new);
	shell_exec('sudo cp '.escapeshellarg($tmp).' '.escapeshellarg($file));
	@unlink($tmp);

	$test = shell_exec('sudo '.$ws['test'].' 2>&1');
	if(stripos($test, 'test is successful') === false && stripos($test, 'syntax ok') === false) {
		/* roll back to the original content */
		$bak = tempnam('/tmp', 'reqad');
		file_put_contents($bak, $content);
		shell_exec('sudo cp '.escapeshellarg($bak).' '.escapeshellarg($file));
		@unlink($bak);
		log_debug('[apply_account_vhost_names] config test failed for '.$domain.': '.trim($test));
		return 'Web server config test failed; change reverted.';
	}
	shell_exec('sudo systemctl reload '.$ws['service'].' >> '.__DIR__.'/../../log/debug_log 2>&1');
	return apply_backend_vhost_names($domain, $names['https']);
}

/* A backend server (Apache behind nginx) picks the site by the Host header
   nginx passes on, so its vhost carries the same names: its ServerAlias line
   is rewritten to $names. '' when there is no backend or nothing to change. */
function apply_backend_vhost_names($domain, $names) {
	if(web_backend() === 'none')
		return '';
	$file = web_vhost_path($domain, web_backend());
	$content = (string)shell_exec('sudo cat '.escapeshellarg($file).' 2>/dev/null');
	if(trim($content) === '')
		return 'Backend vhost not found: '.$file;
	$new = preg_replace('/^([ \t]*)ServerAlias[ \t]+.*$/m', '${1}ServerAlias    '.trim($names), $content);
	if($new === null || $new === $content)
		return '';
	return account_vhost_apply($file, $new, $content, web_backend());
}

/* ---- Let's Encrypt cert extension (aliases) ------------------------------- */

/* Return the SAN (DNS:) names of an account's installed Let's Encrypt cert as a
   lowercased array, array() if the cert has none, or null if the account is not
   using Let's Encrypt (no live lineage). */
function get_cert_san($domain) {
	$f = '/etc/letsencrypt/live/'.$domain.'/cert.pem';
	$ok = trim(shell_exec('sudo test -f '.escapeshellarg($f).' && echo y'));
	if($ok !== 'y') return null;
	$out = shell_exec('sudo openssl x509 -in '.escapeshellarg($f).' -noout -ext subjectAltName 2>/dev/null');
	$names = array();
	if($out && preg_match_all('/DNS:([^,\s]+)/i', $out, $m))
		foreach($m[1] as $n) $names[] = strtolower(trim($n));
	return $names;
}

/* Build the desired cert SAN list for an account: main domain + aliases +
   mail.<domain> when email is enabled. By default wildcards are excluded (they
   need DNS-01); pass $include_wildcards=true for the DNS-01 path. */
function account_cert_names($db, $domain, $has_email, $include_wildcards = false) {
	$names = array($domain);
	$res = $db->query('SELECT id FROM accounts WHERE domain="'.$db->escapeString($domain).'"');
	$acc = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;
	if($acc)
		foreach(get_aliases($db, $acc['id']) as $a)
			if($include_wildcards || (int)$a['is_wildcard'] !== 1)
				$names[] = $a['alias'];
	if($has_email)
		$names[] = 'mail.'.$domain;
	return array_values(array_unique($names));
}

/* Drop names that a wildcard in the same set already covers — Let's Encrypt
   rejects an order that contains both "*.d" and a single-label "x.d". Keeps the
   wildcard itself, the apex (not matched by "*.d"), and deeper names like
   "a.b.d" (a wildcard only matches one label). */
function filter_wildcard_redundant($names) {
	$bases = array();
	foreach($names as $n)
		if(strpos($n, '*.') === 0) $bases[] = substr($n, 2);
	if(!$bases) return array_values(array_unique($names));

	$out = array();
	foreach($names as $n) {
		if(strpos($n, '*.') === 0) { $out[] = $n; continue; }   // keep wildcards
		$redundant = false;
		foreach($bases as $b) {
			$suf = '.'.$b;
			if(substr($n, -strlen($suf)) === $suf) {
				$label = substr($n, 0, strlen($n) - strlen($suf));
				if($label !== '' && strpos($label, '.') === false) { $redundant = true; break; }
			}
		}
		if(!$redundant) $out[] = $n;
	}
	return array_values(array_unique($out));
}

/* Is $name covered by the installed cert's SAN — either literally, or by a
   wildcard entry (*.d covers a single-label x.d)? Used for the per-alias badge. */
function alias_is_covered($name, $cert_san) {
	$name = strtolower($name);
	if(in_array($name, $cert_san, true)) return true;
	if(strpos($name, '*.') === 0) return false;   // a wildcard is only "covered" by an exact match
	foreach($cert_san as $san) {
		if(strpos($san, '*.') === 0) {
			$suf = substr($san, 1);   // ".d" from "*.d"
			if(substr($name, -strlen($suf)) === $suf) {
				$label = substr($name, 0, strlen($name) - strlen($suf));
				if($label !== '' && strpos($label, '.') === false) return true;
			}
		}
	}
	return false;
}

/* True when the account has at least one wildcard (*.domain) alias. */
function account_has_wildcard_alias($db, $domain) {
	$res = $db->query('SELECT a.id FROM aliases a JOIN accounts ac ON ac.id=a.account_id WHERE ac.domain="'.$db->escapeString($domain).'" AND a.is_wildcard=1 LIMIT 1');
	$row = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;
	return $row ? true : false;
}

/* Re-issue / expand an account's Let's Encrypt cert to cover its aliases (and
   mail.<domain> when email is on). Only acts when the account already uses Let's
   Encrypt. If the account has a wildcard alias, the whole cert is re-issued via
   DNS-01 (all SANs, no resolve filter — TXT-based); otherwise the standard
   HTTP-01 flow is used and names are filtered to those that resolve here. Runs
   certbot in the background. Returns: '' launched, 'not-le' (own/self-signed
   cert), 'not-free' (the account may not get free certificates: ssl_free_error()),
   'none' (main domain does not resolve, HTTP-01), 'no-dns' (wildcard but
   no DNS provider configured). */
function reissue_letsencrypt_cert($db, $domain, $has_email, $token = '') {
	$ok = trim(shell_exec('sudo test -d /etc/letsencrypt/live/'.escapeshellarg($domain).' && echo y'));
	if($ok !== 'y') return 'not-le';
	if(function_exists('ssl_free_error') && ($acct = account_get($db, '', $domain)) && ssl_free_error($acct['user']) !== '')
		return 'not-free';

	/* Wildcard present -> DNS-01 re-issue of the full SAN set. */
	if(account_has_wildcard_alias($db, $domain)) {
		$prov = $db->querySingle('SELECT value FROM settings WHERE name="dns-provider"');
		if($prov === '' || $prov === null || $prov === 'none')
			return 'no-dns';
		$names = filter_wildcard_redundant(account_cert_names($db, $domain, $has_email, true));
		$list  = implode(',', $names);
		$tok   = ($token !== '') ? ' '.escapeshellarg($token) : '';
		shell_exec(__DIR__.'/../../scripts/reissue_letsencrypt_cert_dns.sh '.escapeshellarg($list).$tok.' >/dev/null 2>&1 &');
		log_debug('[reissue] DNS-01 '.$domain.' : '.$list);
		return '';
	}

	$ip = trim(shell_exec("/usr/sbin/ip address show | grep 'scope global' | grep 'inet ' | head -n 1 | awk {'print \$2'} | awk -F/ {'print \$1'}"));
	$resolvable = array();
	foreach(account_cert_names($db, $domain, $has_email) as $n) {
		$dig = trim(shell_exec('dig +short a '.escapeshellarg($n).' 2>/dev/null'));
		$lines = array_values(array_filter(array_map('trim', explode("\n", $dig))));
		$last  = end($lines);   // A record is the last line after any CNAME chain
		if($last === $ip) $resolvable[] = $n;
	}
	if(!in_array($domain, $resolvable, true)) return 'none';

	$list = implode(',', $resolvable);
	$tok  = ($token !== '') ? ' '.escapeshellarg($token) : '';
	shell_exec(__DIR__.'/../../scripts/reissue_letsencrypt_cert.sh '.escapeshellarg($list).$tok.' >/dev/null 2>&1 &');
	return '';
}

/* ---- Advanced config editor ---------------------------------------------- */

/* Resolve the editable config target for an account. $which is a whitelisted key
   ('nginx' = web-server vhost, 'fpm' = the php-fpm pool). Returns
   array(path, test, service, label, mode) or null for an unknown key. The php-fpm
   version/path is inferred from which php-fpm.d dir holds the account's pool. */
function account_config_target($ini, $domain, $which) {
	if($which === 'nginx') {
		$server = web_front($ini);
		$ws     = web_server($server);
		return array(
			'path'    => web_vhost_path($domain, $server),
			'test'    => 'sudo '.$ws['test'].' 2>&1',
			'bin'     => $ws['bin'],
			'service' => $ws['service'],
			'label'   => $ws['label'].' vhost',
			'mode'    => 'nginx',
		);
	}

	if($which === 'fpm') {
		$php_versions = array_map('trim', explode(',', $ini['php_versions']));
		$ver = $ini['php'];
		foreach($php_versions as $pv) {
			$s = str_replace('.', '', $pv);
			if(is_file('/etc/opt/remi/php'.$s.'/php-fpm.d/'.$domain.'.conf')) { $ver = $pv; break; }
		}
		$short = str_replace('.', '', $ver);
		if($ver === $ini['php']) {
			$path = '/etc/php-fpm.d/'.$domain.'.conf';
			$bin  = '/usr/sbin/php-fpm';
			$svc  = 'php-fpm.service';
		} else {
			$path = '/etc/opt/remi/php'.$short.'/php-fpm.d/'.$domain.'.conf';
			$bin  = '/opt/remi/php'.$short.'/root/usr/sbin/php-fpm';
			$svc  = 'php'.$short.'-php-fpm.service';
		}
		return array(
			'path'    => $path,
			'test'    => 'sudo '.$bin.' -t 2>&1',
			'bin'     => $bin,
			'service' => $svc,
			'label'   => 'PHP-FPM pool (PHP '.$ver.')',
			'mode'    => 'properties',
		);
	}

	return null;
}

/* Validate proposed config content WITHOUT touching the live file, by running the
   server's own test against a throwaway wrapper that includes the content:
     - nginx:   events{} http{ include <content>; }  ->  nginx -t -c wrapper
     - php-fpm: [global] include=<content>           ->  php-fpm -y wrapper -t
   Returns '' when valid, or a cleaned error message (temp paths/timestamps stripped
   so it reads like the real file). Apache falls back to '' (real save validates). */
function validate_config_content($ini, $domain, $which, $content) {
	$t = account_config_target($ini, $domain, $which);
	if(!$t) return 'Unknown config file.';

	$content = str_replace("\r\n", "\n", $content);
	if(substr($content, -1) !== "\n") $content .= "\n";

	$dir = sys_get_temp_dir().'/reqadcfg_'.bin2hex(random_bytes(6));
	if(!@mkdir($dir, 0700)) return '';   // can't isolate — let the real save validate
	$out = '';

	if($which === 'nginx' && $t['bin'] === 'nginx') {
		file_put_contents($dir.'/vhost.conf', $content);
		/* the real nginx.conf's load_module lines, so directives of dynamic
		   modules (modsecurity, more_set_headers, geoip2) are known here too */
		$mods = '';
		if(preg_match_all('/^[ \t]*load_module[ \t]+[^;\n]+;/m', (string)@file_get_contents('/etc/nginx/nginx.conf'), $m))
			$mods = implode("\n", array_map('trim', $m[0]))."\n";
		file_put_contents($dir.'/nginx.conf', $mods."events {}\nhttp {\n    include ".$dir."/vhost.conf;\n}\n");
		$out = shell_exec('sudo nginx -t -c '.escapeshellarg($dir.'/nginx.conf').' 2>&1');
	} elseif($which === 'fpm') {
		file_put_contents($dir.'/pool.conf', $content);
		file_put_contents($dir.'/fpm.conf', "[global]\ninclude=".$dir."/pool.conf\n");
		$out = shell_exec('sudo '.escapeshellarg($t['bin']).' -y '.escapeshellarg($dir.'/fpm.conf').' -t 2>&1');
	} else {
		shell_exec('rm -rf '.escapeshellarg($dir));
		return '';   // apache / unknown: skip isolated preflight
	}

	shell_exec('rm -rf '.escapeshellarg($dir));

	if(stripos((string)$out, 'test is successful') !== false || stripos((string)$out, 'syntax ok') !== false)
		return '';

	/* clean the message: drop temp paths, php-fpm timestamps; use the real filename */
	$err = (string)$out;
	$err = str_replace($dir.'/', '', $err);
	$err = preg_replace('/^\[\d{2}-\w{3}-\d{4} \d{2}:\d{2}:\d{2}\]\s*/m', '', $err);
	$err = str_replace(array('vhost.conf', 'pool.conf'), basename($t['path']), $err);
	$err = preg_replace('#(nginx\.conf|fpm\.conf)#', basename($t['path']), $err);
	return trim($err) !== '' ? trim($err) : 'Configuration test failed.';
}

/* ---- Advanced Config version history ------------------------------------
   Backups live panel-side (readable without sudo) under
   backup/config/<user>/<basename>.<Ymd-His>. Only the last 5 per file are
   kept. Used by config_save.php, config_restore.php and ajax-config-versions. */

function config_backup_dir($user) {
	return _PATH.'/backup/config/'.preg_replace('/[^a-z0-9]/', '', (string)$user);
}

/* Backup filename prefix for a config file. The nginx vhost and php-fpm pool can
   share a basename (e.g. dt.ro.conf), so $which (nginx|fpm) keeps their histories
   in separate namespaces. */
function config_backup_prefix($which, $path) {
	return $which.'.'.basename($path);
}

/* Save $content as a new backup of $path (of type $which) for $user, prune to 5. */
function save_config_backup($user, $which, $path, $content) {
	$dir = config_backup_dir($user);
	if(!is_dir($dir)) @mkdir($dir, 0700, true);
	$base = config_backup_prefix($which, $path);
	$file = $dir.'/'.$base.'.'.date('Ymd-His');
	file_put_contents($file, $content);
	$all = glob($dir.'/'.$base.'.*');
	if($all && count($all) > 5) {
		sort($all);                                   // oldest first (Ymd-His sorts lexically)
		foreach(array_slice($all, 0, count($all) - 5) as $old) @unlink($old);
	}
	return $file;
}

/* Human-friendly time + size for a backup timestamp string 'Ymd-His'. */
function config_backup_when($ts) {
	$t = @strtotime(preg_replace('/^(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})$/', '$1-$2-$3 $4:$5:$6', $ts));
	if(!$t) return $ts;
	if(date('Y-m-d', $t) === date('Y-m-d'))                     return 'Today, '.date('H:i', $t);
	if(date('Y-m-d', $t) === date('Y-m-d', strtotime('-1 day'))) return 'Yesterday, '.date('H:i', $t);
	return date('M j, H:i', $t);
}
/* "8 hours", "3 days", "12 minutes" -- a service age for a card, where the full
   `Thu 2026-09-03 12:00:57 EEST` costs a whole column and answers a question
   nobody asked. Deliberately one unit: the point is "has it restarted lately". */
function human_duration($seconds) {
	$s = (int)$seconds;
	if ($s < 0) $s = 0;
	$units = array(
		array(31536000, 'year'), array(2592000, 'month'), array(86400, 'day'),
		array(3600, 'hour'), array(60, 'minute'),
	);
	foreach ($units as $u) {
		if ($s >= $u[0]) {
			$n = (int)floor($s / $u[0]);
			return $n.' '.$u[1].($n == 1 ? '' : 's');
		}
	}
	return $s.' second'.($s == 1 ? '' : 's');
}

/* Format a size given in megabytes as MB/GB/TB. */
function human_mb($mb) {
	$mb = (float)$mb;
	if($mb <= 0) return '0 MB';
	if($mb < 1024) return round($mb, ($mb < 10 ? 1 : 0)).' MB';
	if($mb < 1048576) return round($mb / 1024, 1).' GB';
	return round($mb / 1048576, 2).' TB';
}

function config_human_size($bytes) {
	$bytes = (int)$bytes;
	if($bytes < 1024) return $bytes.' B';
	return round($bytes / 1024, 1).' KB';
}

/* List the saved backups for a config file of type $which, newest first. */
function list_config_backups($user, $which, $path) {
	$dir  = config_backup_dir($user);
	$base = config_backup_prefix($which, $path);
	$all  = glob($dir.'/'.$base.'.*');
	if(!$all) return array();
	rsort($all);                                      // newest first
	$out = array();
	foreach($all as $f) {
		$ts = substr($f, strrpos($f, '.') + 1);
		$out[] = array(
			'id'   => basename($f),
			'ts'   => $ts,
			'when' => config_backup_when($ts),
			'size' => config_human_size(@filesize($f)),
		);
	}
	return $out;
}

/* Read one backup's content, guarding the id to this file's type+basename. */
function read_config_backup($user, $which, $path, $version) {
	$base = config_backup_prefix($which, $path);
	$id   = basename((string)$version);              // strip any path components
	if(strpos($id, $base.'.') !== 0) return null;    // must belong to this file+type
	$file = config_backup_dir($user).'/'.$id;
	if(!is_file($file)) return null;
	return (string)file_get_contents($file);
}

/* Validate, back up the current version, write, and reload — the single write
   path shared by config_save.php (editor) and config_restore.php (history).
   Returns array('error'=>..., 'success'=>...). */
function apply_account_config($ini, $user, $domain, $which, $content) {
	$t = account_config_target($ini, $domain, $which);
	if(!$t) return array('error' => 'Unknown config file.', 'success' => '');

	$orig = shell_exec('sudo cat '.escapeshellarg($t['path']).' 2>/dev/null');
	if($orig === null || $orig === '')
		return array('error' => 'Config file not found: '.$t['path'], 'success' => '');

	$content = str_replace("\r\n", "\n", $content);
	if(substr($content, -1) !== "\n") $content .= "\n";

	/* isolated pre-check so we never write invalid content to the live file */
	$verr = validate_config_content($ini, $domain, $which, $content);
	if($verr !== '')
		return array('error' => 'Validation failed, not saved: '.$verr, 'success' => '');

	/* keep the version we're replacing, then write the new one */
	save_config_backup($user, $which, $t['path'], $orig);
	$tmp = tempnam('/tmp', 'reqad');
	file_put_contents($tmp, $content);
	shell_exec('sudo cp '.escapeshellarg($tmp).' '.escapeshellarg($t['path']));
	@unlink($tmp);

	/* defensive re-test against the running server (apache has no isolated check) */
	$out = shell_exec($t['test']);
	if(stripos((string)$out, 'test is successful') === false && stripos((string)$out, 'syntax ok') === false) {
		$bak = tempnam('/tmp', 'reqad');
		file_put_contents($bak, $orig);
		shell_exec('sudo cp '.escapeshellarg($bak).' '.escapeshellarg($t['path']));
		@unlink($bak);
		return array('error' => 'Validation failed on live config, reverted: '.trim(preg_replace('/\s+/', ' ', (string)$out)), 'success' => '');
	}

	shell_exec('sudo systemctl reload '.$t['service'].' >> '._PATH.'/log/debug_log 2>&1');
	log_debug('[config] '.$which.' '.$domain.' saved + reloaded '.$t['service']);
	return array('error' => '', 'success' => $t['label'].' saved and reloaded.');
}

/* The raw vhost and pool editors (and their validate/restore endpoints) are
   root access in disguise: the nginx master opens log paths as root, Apache
   runs piped logs as root, and a pool's "user =" picks who PHP runs as. With
   root_access=0 they are read-only, the Process manager included. */
function account_config_editable($ini) {
	return feature_enabled($ini, 'root_access');
}

/* Process manager of an account's php-fpm pool, written by the server: only the
   pm.* / request_terminate_timeout lines (and the comments Reqad wrote above
   them) are replaced, from validated numbers (root_access=1 only, see
   modules/pool_pm.php). $v: pm (ondemand|dynamic|static),
   max, idle, start, minsp, maxsp, reqs, term. Same rules as the dialog. */
function account_pool_pm_block(array $v) {
	$pm = (string)($v['pm'] ?? '');
	$n  = function ($k) use ($v) { return isset($v[$k]) && preg_match('/^\d+$/', (string)$v[$k]) ? (int)$v[$k] : -1; };
	$max = $n('max'); $reqs = $n('reqs'); $term = $n('term');
	if (!in_array($pm, array('ondemand', 'dynamic', 'static'), true))
		return array('Unknown process manager mode.', null);
	if ($max < 1 || $max > 500)
		return array('Workers at most must be between 1 and 500.', null);
	if ($reqs < 0 || $reqs > 100000 || $term < 0 || $term > 86400)
		return array('Requests and seconds must be 0 or more.', null);
	if ($pm === 'ondemand') {
		$idle = $n('idle');
		if ($idle < 1 || $idle > 3600)
			return array('Stop idle workers after must be 1 to 3600 seconds.', null);
		$block = array("; workers start with the first request and stop after $idle s idle",
		               'pm = ondemand', "pm.max_children = $max", "pm.process_idle_timeout = {$idle}s");
	} else if ($pm === 'dynamic') {
		$st = $n('start'); $mn = $n('minsp'); $mx = $n('maxsp');
		if (!($mn >= 1 && $mn <= $st && $st <= $mx && $mx <= $max))
			return array('Needs: at least <= start with <= at most <= workers at most.', null);
		$block = array("; $mn to $mx idle workers kept ready, up to $max in all",
		               'pm = dynamic', "pm.max_children = $max", "pm.start_servers = $st",
		               "pm.min_spare_servers = $mn", "pm.max_spare_servers = $mx");
	} else {
		$block = array("; a fixed $max workers, always running", 'pm = static', "pm.max_children = $max");
	}
	$block[] = "pm.max_requests = $reqs";
	$block[] = "request_terminate_timeout = {$term}s";
	return array('', $block);
}

function account_pool_pm_apply($ini, $user, $domain, array $v) {
	list($err, $block) = account_pool_pm_block($v);
	if ($err !== '')
		return array('error' => $err, 'success' => '');
	$t = account_config_target($ini, $domain, 'fpm');
	$orig = $t ? (string)shell_exec('sudo cat '.escapeshellarg($t['path']).' 2>/dev/null') : '';
	if ($orig === '')
		return array('error' => 'This account has no php-fpm pool.', 'success' => '');

	$keys = array('pm', 'pm.max_children', 'pm.start_servers', 'pm.min_spare_servers', 'pm.max_spare_servers',
	              'pm.process_idle_timeout', 'pm.max_requests', 'request_terminate_timeout');
	$out = array(); $at = -1;
	foreach (explode("\n", rtrim(str_replace("\r\n", "\n", $orig), "\n")) as $l) {
		$old = (bool)preg_match('/^\s*;\s*(workers start with the first request|site holds no memory|a fixed \d+ workers|\d+ to \d+ idle workers)/', $l);
		foreach ($keys as $k)
			if (preg_match('/^\s*'.preg_quote($k, '/').'\s*=/', $l)) $old = true;
		if ($old) {
			if ($at < 0 && (preg_match('/^\s*pm\s*=/', $l) || preg_match('/^\s*;/', $l))) $at = count($out);
			continue;
		}
		$out[] = $l;
	}
	if ($at < 0) {
		$at = count($out);
		foreach ($out as $i => $l)
			if (preg_match('/^\s*listen/', $l)) $at = $i + 1;
		array_unshift($block, '');
	}
	array_splice($out, $at, 0, $block);
	$r = apply_account_config($ini, $user, $domain, 'fpm', implode("\n", $out)."\n");
	if ($r['error'] === '')
		$r['success'] = 'Process manager: pm = '.$v['pm'].', up to '.(int)$v['max'].' workers. '.$r['success'];
	return $r;
}

/**
 * State of wetty, the browser terminal behind the Terminal page.
 *
 * wetty is a Node app, not an RPM dependency, so it can legitimately be absent
 * or stopped on a working server. The Terminal page has no way to tell — the
 * iframe just renders blank — so check here and report something actionable.
 *
 * Returns:
 *   ok      bool    true only when wetty is running and accepting connections
 *   state   string  ok | no-unit | no-build | stopped | unreachable
 *   title   string  short headline for the alert
 *   message string  what is wrong, in plain language
 *   hint    string  the command that fixes it ('' when there is none)
 *   detail  string  service output, when there is any worth showing
 */
function wetty_status() {
	$installer = _PATH.'/scripts/install/install-wetty.sh';
	$out = array('ok' => false, 'state' => '', 'title' => '', 'message' => '', 'hint' => '', 'detail' => '');

	// 1. Is there a service at all?
	if(!file_exists('/usr/lib/systemd/system/wetty.service') && !file_exists('/etc/systemd/system/wetty.service')) {
		$out['state']   = 'no-unit';
		$out['title']   = 'The terminal is not installed';
		$out['message'] = 'wetty, the browser terminal this page embeds, is not set up on this server.';
		$out['hint']    = $installer;
		return $out;
	}

	// 2. Running? Ask systemd before looking at the checkout: wetty is built and
	//    run out of /root, which the panel user cannot even traverse on a stock
	//    Rocky box (/root is 0550), so any stat of the build from here answers
	//    "missing" on a perfectly healthy install. A running unit is proof
	//    enough that there is something to run.
	$active = trim((string)shell_exec('sudo systemctl is-active wetty 2>&1'));
	if($active !== 'active') {
		// Only now is the checkout worth inspecting — and only through sudo, for
		// the reason above. The build sits next to the unit's WorkingDirectory
		// (ExecStart runs ./build/main.js relative to it).
		$dir = trim((string)shell_exec('sudo systemctl show wetty -p WorkingDirectory --value 2>/dev/null'));
		if($dir == '') $dir = '/root/wetty';
		$built = trim((string)shell_exec('sudo test -f '.escapeshellarg($dir.'/build/main.js').' && echo 1'));

		if($built !== '1') {
			$out['state']   = 'no-build';
			$out['title']   = 'The terminal is not built';
			$out['message'] = 'wetty is present but has not been built, so the service cannot start.';
			$out['hint']    = $installer;
			return $out;
		}

		$out['state']   = 'stopped';
		$out['title']   = 'The terminal service is not running';
		$out['message'] = 'wetty.service reports "'.$active.'".';
		$out['hint']    = 'systemctl start wetty';
		$out['detail']  = trim((string)shell_exec('sudo systemctl status wetty --no-pager -n 8 2>&1'));
		return $out;
	}

	// 3. Active but actually accepting connections? A crash-looping or
	//    misconfigured wetty can report active while nothing is listening.
	// Read the merged ExecStart, not `systemctl cat` — cat prints the base unit
	// and every drop-in, so a drop-in that overrides the port would be missed.
	$port = 3000;
	if(preg_match('/--port\s+(\d+)/', (string)shell_exec('sudo systemctl show wetty -p ExecStart --value 2>/dev/null'), $m))
		$port = (int)$m[1];

	$sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 2);
	if(!$sock) {
		$out['state']   = 'unreachable';
		$out['title']   = 'The terminal is not responding';
		$out['message'] = 'wetty.service is running but nothing is listening on 127.0.0.1:'.$port.'.';
		$out['hint']    = 'systemctl restart wetty';
		$out['detail']  = trim((string)shell_exec('sudo systemctl status wetty --no-pager -n 8 2>&1'));
		return $out;
	}
	fclose($sock);

	$out['ok']    = true;
	$out['state'] = 'ok';
	return $out;
}

/* ==========================================================================
   WP Toolkit — per-site "Manage" options
   --------------------------------------------------------------------------
   Two live, toggleable options, driven from the Manage modal on wp-toolkit:
     1. Nginx FastCGI microcache (+ ngx_cache_purge purge endpoint + the
        matching WordPress purge plugin)
     2. Disable WP-Cron (DISABLE_WP_CRON constant + a real every-2-minutes system cron)

   State is NOT stored in the DB — it is derived from the live system on every
   status read (config markers, wp-config constant, crontab line), so the modal
   always mirrors reality even if the admin changed things by hand.
   ========================================================================== */

/* The server's primary public IPv4 — used to allow the WP purge plugin (which
   calls /purge over the public hostname) through the purge location's ACL. */
function reqad_server_ip() {
	return trim(shell_exec("/usr/sbin/ip address show | grep 'scope global' | grep 'inet ' | head -n 1 | awk {'print \$2'} | awk -F/ {'print \$1'}"));
}

/* Is nginx the front server? The nginx cache only applies then. */
function wp_is_nginx($ini) {
	return web_front($ini) === 'nginx';
}

/* Sanitised zone/prefix for an account — used for the cache zone, cache dir and
   the map variable names. Account users are already [a-z][a-z0-9]{1,11}; this is
   defence in depth so nothing untrusted reaches an nginx identifier. */
function wp_cache_zone($user) {
	return preg_replace('/[^a-z0-9]/', '', strtolower((string)$user));
}

/* Absolute folder of a tracked WordPress install: the site's document root
   ($domain: an addon domain or subdomain of $user has its own; the main
   domain's is ~/public_html) plus the install's subfolder `path`. */
function wp_site_docroot($user, $path = '', $domain = '') {
	global $ini;
	$base = '/home/'.$user.'/public_html';
	if ($domain !== '' && ($a = addon_domain_get($ini, strtolower($domain))) && $a['user'] === $user
	    && strpos((string)$a['docroot'], '/home/'.$user.'/') === 0)
		$base = rtrim($a['docroot'], '/');
	$path = trim((string)$path, '/');
	return $path === '' ? $base : $base.'/'.$path;
}

/* nginx vhost path for a domain (only meaningful when wp_is_nginx()). */
function wp_nginx_conf_path($domain) {
	return '/etc/nginx/conf.d/'.$domain.'.conf';
}

/* --- Nginx page cache (FastCGI, or proxy with Apache behind nginx) -------- */

/* How a vhost's page cache works: 'fcgi' = nginx runs PHP itself and caches
   PHP-FPM's answers (fastcgi_cache); 'proxy' = Apache behind nginx
   (nginx_backend_root(): `include /etc/nginx/reqad-backend.conf;`) and nginx
   caches Apache's answers (proxy_cache). Same zone, maps, rules and purge. */
function wp_nginx_cache_mode($conf) {
	return preg_match('#^[ \t]*include[ \t]+/etc/nginx/reqad-backend\.conf;#m', (string)$conf) ? 'proxy' : 'fcgi';
}

/* A zone's cache folder. Each kind has its own: the two caches store their
   entries differently, so one must never read the other's files. */
function wp_nginx_cache_dir($zone, $mode = 'fcgi') {
	return '/var/cache/nginx/'.($mode === 'proxy' ? 'proxy-' : 'fcgi-').$zone;
}

/* Build the marker-delimited config fragments for a site.
   Returns array('http' => ..., 'purge' => ..., 'fcgi' => ..., 'proxy' => ...):
   http + purge for $mode (wp_nginx_cache_mode()), and both location-level
   blocks ('fcgi' after the PHP handler's fastcgi_pass, 'proxy' after each
   include of reqad-backend.conf). Uses nowdoc so nginx '$' variables survive
   verbatim; only %ZONE% / %IP% are substituted.
   $rules: the site's own additions (wp_cache_rules_get()): 'pages' = paths
   that are never cached, 'cookies' = cookie names that turn the cache off.

   Query strings: a request whose query holds only campaign tags (utm_*, click
   ids: wp_cache_tracking_params()) uses the cache entry of the plain address,
   and PHP gets it WITHOUT the tags when the answer may be cached, so no
   visitor's click id ends up in a page the others get. Any other parameter
   skips the cache, as before. The key is the path alone (%ZONE%_cache_path):
   only cacheable requests ever read or write it. With Apache behind nginx the
   address cannot be changed on the way, so a tagged request reads the cache
   but its answer is never stored (proxy_no_cache ... _nc_track). */
function wp_nginx_cache_fragments($zone, $ip, array $rules = array(), $mode = 'fcgi') {
	$cookies = '';
	foreach (wp_cache_rules_clean($rules)['cookies'] as $c) {
		$name = preg_quote(rtrim($c, '*'));
		$cookies .= '    "~*(^|;)\s*'.$name.(substr($c, -1) === '*' ? '[^=;]*' : '').'=" 1;'."\n";
	}
	$pages = '';
	foreach (wp_cache_rules_clean($rules)['pages'] as $p)
		$pages .= '    "~*^'.str_replace('\*', '.*', preg_quote($p)).'" 1;'."\n";
	$track = implode('|', array_map(function ($t) {
		return substr($t, -1) === '*' ? preg_quote(rtrim($t, '*'), '/').'[a-z0-9_]*' : preg_quote($t, '/');
	}, wp_cache_tracking_params()));

	$http = <<<'EOT'
# BEGIN reqad-nginx-cache
# FastCGI microcache — managed by the Reqad WP Toolkit "Manage" panel.
# Do NOT edit between the BEGIN/END markers; toggling the option rewrites them.
fastcgi_cache_path /var/cache/nginx/fcgi-%ZONE% levels=1:2 keys_zone=%ZONE%:100m
                   inactive=12h max_size=512m;

map $http_cookie $%ZONE%_nc_cookie {
    default                     0;
    ~*wordpress_logged_in       1;
    ~*wp-postpass               1;
    ~*comment_author            1;
    ~*wordpress_no_cache        1;
    ~*woocommerce_items_in_cart 1;
    ~*woocommerce_cart_hash     1;
    ~*wp_woocommerce_session    1;
%COOKIES%}

map $request_uri $%ZONE%_nc_uri {
    default                          0;
    ~*/wp-admin                      1;
    ~*/wp-[a-z_-]+\.php              1;
    ~*/xmlrpc\.php                   1;
    ~*/wp-json                       1;
    ~*/feed                          1;
    ~*/sitemap[^/]*\.xml             1;
    ~*^/purge                        1;
    ~*/(cart|basket|checkout|my-account) 1;
    ~*/wc-api                        1;
    ~*/store-api                     1;
%PAGES%}

map $request_method $%ZONE%_nc_method {
    default 1;
    GET     0;
    HEAD    0;
}

# 1 = the query string holds only campaign tags (utm_*, gclid, fbclid...)
map $query_string $%ZONE%_nc_track {
    default 0;
    "~*^(?:(?:%TRACK%)(?:=[^&]*)?(?:&|$))+$" 1;
}

map "$query_string:$%ZONE%_nc_track" $%ZONE%_nc_query {
    default 1;
    ":0"    0;
    "~:1$"  0;
}

map "$%ZONE%_nc_cookie$%ZONE%_nc_uri$%ZONE%_nc_method$%ZONE%_nc_query" $%ZONE%_skip_cache {
    default 1;
    "0000"  0;
}

# the cache key: the address without its query string
map $request_uri $%ZONE%_cache_path {
    default                  $request_uri;
    "~^(?<%ZONE%_cp>[^?]*)"  $%ZONE%_cp;
}

# what PHP sees: a cacheable request with campaign tags gets the plain address
map "$%ZONE%_skip_cache$%ZONE%_nc_track" $%ZONE%_php_query {
    default $query_string;
    "01"    "";
}
map "$%ZONE%_skip_cache$%ZONE%_nc_track" $%ZONE%_php_uri {
    default $request_uri;
    "01"    $%ZONE%_cache_path;
}
# END reqad-nginx-cache

EOT;

	$purge = <<<'EOT'
	# BEGIN reqad-nginx-cache-purge
	add_header X-FastCGI-Cache $upstream_cache_status always;
	add_header X-Cache-Skip    $%ZONE%_skip_cache      always;

	# Purge endpoint for the WordPress reqad-cache-purger plugin. The plugin calls
	# it over the public hostname, so the request arrives from this server's own
	# public IP — hence the allow below. The key must match fastcgi_cache_key.
	location ~ ^/purge(/.*) {
	    allow 127.0.0.1;
	    allow ::1;
	    allow %IP%;
	    deny  all;

	    cache_purge_response_type json;
	    fastcgi_cache_purge %ZONE% "$scheme$host$1";
	}
	# END reqad-nginx-cache-purge

EOT;

	$fcgi = <<<'EOT'
            # BEGIN reqad-nginx-cache-fcgi
            # The key is the path from $request_uri (not $uri: try_files rewrites
            # $uri to /index.php, which would collapse every page onto one entry).
            # Only requests without a query, or with campaign tags alone, are cached.
            open_file_cache             off;
            fastcgi_cache               %ZONE%;
            fastcgi_cache_key           "$scheme$host$%ZONE%_cache_path";
            fastcgi_cache_methods       GET;
            fastcgi_cache_valid         200 301 302 12h;
            fastcgi_cache_valid         404 1m;
            fastcgi_cache_lock          on;
            fastcgi_cache_revalidate    on;
            fastcgi_cache_use_stale     error timeout updating invalid_header http_500 http_503;
            fastcgi_cache_background_update on;
            fastcgi_ignore_headers      Cache-Control Expires;
            fastcgi_cache_bypass        $%ZONE%_skip_cache;
            fastcgi_no_cache            $%ZONE%_skip_cache;
            # after include fastcgi_params: PHP-FPM keeps the last value
            fastcgi_param               QUERY_STRING $%ZONE%_php_query;
            fastcgi_param               REQUEST_URI  $%ZONE%_php_uri;
            # END reqad-nginx-cache-fcgi

EOT;

	$proxy = <<<'EOT'
            # BEGIN reqad-nginx-cache-proxy
            # Page cache of Apache's answers; the key is the path without its query.
            # Requests with campaign tags alone read it, but only plain addresses
            # are stored (Apache gets the tags, so its answer may carry them).
            # Apache gets no Accept-Encoding: the cached copy is plain and nginx
            # compresses it per visitor, so one copy serves every browser and a
            # purge of the address clears it (Vary is ignored for the same reason).
            proxy_cache               %ZONE%;
            proxy_cache_key           "$scheme$host$%ZONE%_cache_path";
            proxy_cache_methods       GET;
            proxy_cache_valid         200 301 302 12h;
            proxy_cache_valid         404 1m;
            proxy_cache_lock          on;
            proxy_cache_revalidate    on;
            proxy_cache_use_stale     error timeout updating invalid_header http_500 http_503;
            proxy_cache_background_update on;
            proxy_ignore_headers      Cache-Control Expires Vary;
            proxy_cache_bypass        $%ZONE%_skip_cache;
            proxy_no_cache            $%ZONE%_skip_cache $%ZONE%_nc_track;
            proxy_set_header          Accept-Encoding "";
            # END reqad-nginx-cache-proxy

EOT;

	if ($mode === 'proxy') {
		$http = str_replace(array("# FastCGI microcache — managed by the Reqad WP Toolkit \"Manage\" panel.",
		                          "fastcgi_cache_path /var/cache/nginx/fcgi-%ZONE% levels=1:2 keys_zone=%ZONE%:100m\n                  "),
		                    array("# Page cache of Apache's answers (Apache behind nginx) — managed by the Reqad WP Toolkit.",
		                          "proxy_cache_path /var/cache/nginx/proxy-%ZONE% levels=1:2 keys_zone=%ZONE%:100m\n                "), $http);
		/* the header keeps its name: the purge plugin and admins look for it */
		$purge = str_replace(array('fastcgi_cache_purge %ZONE%', 'must match fastcgi_cache_key'),
		                     array('proxy_cache_purge   %ZONE%', 'must match proxy_cache_key'), $purge);
	}

	$sub = function($s) use ($zone, $ip, $cookies, $pages, $track) {
		return str_replace(array('%ZONE%', '%IP%', '%COOKIES%', '%PAGES%', '%TRACK%'), array($zone, $ip, $cookies, $pages, $track), $s);
	};
	return array('http' => $sub($http), 'purge' => $sub($purge), 'fcgi' => $sub($fcgi), 'proxy' => $sub($proxy));
}

/* Put the location-level cache blocks of $mode into a vhost, after taking
   out any there are (of either kind). fcgi: after the first PHP handler's
   `fastcgi_pass php-fpm...;`. proxy: after every `include
   /etc/nginx/reqad-backend.conf;` (`location /` and its PHP location; not the
   server's @reqad_backend, which only gets static files that are not on
   disk). Returns array(conf, blocks placed); 0 = no place for them (PHP off,
   a customised vhost). */
function wp_nginx_cache_place($conf, array $frag, $mode) {
	foreach (array('reqad-nginx-cache-fcgi', 'reqad-nginx-cache-proxy') as $mk)
		$conf = preg_replace('/[ \t]*# BEGIN '.$mk.'\b.*?# END '.$mk.'[^\n]*\n?/s', '', $conf);
	$out = array(); $n = 0; $loc = '';
	foreach (explode("\n", $conf) as $ln) {
		$out[] = $ln;
		if (preg_match('/^[ \t]*location\b[^{]*\{/', $ln))
			$loc = $ln;
		if ($mode === 'proxy') {
			if (preg_match('#^([ \t]*)include[ \t]+/etc/nginx/reqad-backend\.conf;#', $ln, $m) && strpos($loc, '@reqad_backend') === false) {
				$out[] = conf_indent(preg_replace('/^[ \t]+/m', '', trim($frag['proxy'], "\n")), $m[1]);
				$n++;
			}
		} elseif ($n === 0 && strpos($ln, 'fastcgi_pass') !== false && strpos($ln, 'php-fpm') !== false) {
			$out[] = rtrim($frag['fcgi'], "\n");
			$n++;
		}
	}
	return array(implode("\n", $out), $n);
}

/* The query parameters that only tag a visit (ads, newsletters, social
   networks): a query holding nothing else is served from the cache. A
   trailing * = every name starting so. */
function wp_cache_tracking_params() {
	return array('utm_*', 'gclid', 'gbraid', 'wbraid', 'gad_source', 'gad_campaignid', 'dclid', 'fbclid', 'msclkid', 'ttclid', 'twclid',
	             'li_fat_id', 'srsltid', 'igshid', 'yclid', 'mc_cid', 'mc_eid', '_ga', '_gl', '_hsenc', '_hsmi', 'mkt_tok', 'oly_enc_id', 'oly_anon_id',
	             'vero_id', 'rb_clickid', 's_cid', 'epik', 'pk_campaign', 'pk_kwd', 'pk_source', 'pk_medium', 'mtm_campaign', 'mtm_source', 'mtm_medium', 'ref_src');
}

/* A site's own cache rules, checked and normalised: array('pages' => [...],
   'cookies' => [...]). Pages: start with /, no spaces or quotes, * = any text.
   Cookies: letters, digits, . _ - and a trailing * for a prefix. At most 50
   of each; duplicates dropped. Invalid entries are left out here; the setter
   (wordpress_cache_rules_set()) refuses them with a message. */
function wp_cache_rules_clean(array $rules) {
	$out = array('pages' => array(), 'cookies' => array());
	foreach ((array)($rules['pages'] ?? array()) as $p) {
		$p = trim((string)$p);
		if (wp_cache_rule_error('pages', $p) === '' && !in_array(strtolower($p), array_map('strtolower', $out['pages']), true))
			$out['pages'][] = $p;
	}
	foreach ((array)($rules['cookies'] ?? array()) as $c) {
		$c = trim((string)$c);
		if (wp_cache_rule_error('cookies', $c) === '' && !in_array(strtolower($c), array_map('strtolower', $out['cookies']), true))
			$out['cookies'][] = $c;
	}
	$out['pages'] = array_slice($out['pages'], 0, 50);
	$out['cookies'] = array_slice($out['cookies'], 0, 50);
	return $out;
}

/* Why a cache rule is refused ('' = fine). $kind: pages | cookies. */
function wp_cache_rule_error($kind, $v) {
	if ($kind === 'pages') {
		if ($v === '' || $v[0] !== '/')
			return 'A page must start with / (for example /members/).';
		if (strlen($v) > 200 || !preg_match('#^/[A-Za-z0-9/_.~%*+,:@=!()-]*$#', $v))
			return 'A page may hold letters, digits, / and . _ - ~ % * + , : @ = ! ( ) only (no spaces, quotes or ?).';
		return '';
	}
	if ($v === '' || strlen($v) > 100 || !preg_match('/^[A-Za-z0-9._-]+\*?$/', $v))
		return 'A cookie name may hold letters, digits and . _ - only, with an optional * at the end.';
	return '';
}

/* The cache rules a site has (table wordpress_cache_rules, db 1054), per
   domain: the page cache is the site's (vhost), not one install's. */
function wp_cache_rules_get($domain) {
	global $db;
	$out = array('pages' => array(), 'cookies' => array());
	$stmt = @$db->prepare('SELECT kind, value FROM wordpress_cache_rules WHERE domain = :d ORDER BY rowid');
	if (!$stmt)
		return $out;     // before db 1054
	$stmt->bindValue(':d', strtolower((string)$domain), SQLITE3_TEXT);
	$res = $stmt->execute();
	while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC)))
		if (isset($out[$r['kind']]))
			$out[$r['kind']][] = $r['value'];
	return $out;
}

/* Replace a site's cache rules in the table (already checked). */
function wp_cache_rules_put($domain, array $rules) {
	global $db;
	$domain = strtolower((string)$domain);
	$rules = wp_cache_rules_clean($rules);
	$db->exec('BEGIN');
	$del = $db->prepare('DELETE FROM wordpress_cache_rules WHERE domain = :d');
	if (!$del) { $db->exec('ROLLBACK'); return false; }
	$del->bindValue(':d', $domain, SQLITE3_TEXT);
	$del->execute();
	$ins = $db->prepare('INSERT OR IGNORE INTO wordpress_cache_rules (domain, kind, value) VALUES (:d, :k, :v)');
	foreach ($rules as $k => $list)
		foreach ($list as $v) {
			$ins->bindValue(':d', $domain, SQLITE3_TEXT);
			$ins->bindValue(':k', $k, SQLITE3_TEXT);
			$ins->bindValue(':v', $v, SQLITE3_TEXT);
			$ins->execute();
			$ins->reset();
		}
	return $db->exec('COMMIT');
}

/* A removed site leaves no cache rules behind (a later site of that name
   starts without them). */
function wp_cache_rules_forget($domain) {
	global $db;
	if (!($db instanceof SQLite3))
		return;
	$stmt = @$db->prepare('DELETE FROM wordpress_cache_rules WHERE domain = :d');
	if (!$stmt)
		return;
	$stmt->bindValue(':d', strtolower((string)$domain), SQLITE3_TEXT);
	$stmt->execute();
}

/* A vhost's text with its cache blocks written again from the current
   fragments (same zone, the site's rules), or null when it has no cache. The
   purge block keeps the address it allows. The kind follows the vhost
   (wp_nginx_cache_mode()): after a switch between nginx alone and Apache
   behind nginx the location-level blocks of the other kind are replaced, so
   this is also how a switch keeps the cache on (the old kind's folder is then
   left for the caller to empty). */
function wp_nginx_cache_rewrite($conf, $domain) {
	if (!preg_match('/^# BEGIN reqad-nginx-cache[ \t]*$/m', $conf) || !preg_match('/keys_zone=([a-z0-9_]+):/', $conf, $zm))
		return null;
	$mode = wp_nginx_cache_mode($conf);
	$ip = preg_match('/# BEGIN reqad-nginx-cache-purge.*?allow\s+(?!127\.0\.0\.1|::1)([0-9a-fA-F.:]+);/s', $conf, $im) ? $im[1] : reqad_server_ip();
	$frag = wp_nginx_cache_fragments($zm[1], $ip, wp_cache_rules_get($domain), $mode);
	$want  = $mode === 'proxy' ? 'reqad-nginx-cache-proxy' : 'reqad-nginx-cache-fcgi';
	$other = $mode === 'proxy' ? 'reqad-nginx-cache-fcgi' : 'reqad-nginx-cache-proxy';
	if (strpos($conf, '# BEGIN '.$other) !== false || strpos($conf, '# BEGIN '.$want) === false)
		list($conf, ) = wp_nginx_cache_place($conf, $frag, $mode);
	$swap = function ($mk, $text, $c) {
		/* the block keeps the indent of its BEGIN line */
		$text = trim($text, "\n");
		$lead = preg_match('/^[ \t]*/', $text, $l) ? $l[0] : '';
		$text = preg_replace('/^'.preg_quote($lead, '/').'/m', '', $text);    // the fragment's own indent off
		return preg_replace_callback('/^([ \t]*)# BEGIN '.$mk.'[ \t]*\n.*?# END '.$mk.'[^\n]*\n?/ms', function ($m) use ($text) {
			return conf_indent($text, $m[1])."\n";
		}, $c);
	};
	$conf = $swap('reqad-nginx-cache', $frag['http'], $conf);
	$conf = $swap('reqad-nginx-cache-purge', $frag['purge'], $conf);
	$conf = $swap($want, $frag[$mode], $conf);
	return $conf;
}

/* Write a site's cache blocks again (its rules changed, or the blocks are an
   older version). nginx -t before the reload, the old file back on a failure.
   $reload false: the caller reloads once after several sites.
   Returns array(error, changed). */
function wp_nginx_cache_refresh($domain, $reload = true) {
	$path = wp_nginx_conf_path($domain);
	$orig = wp_read_conf($path);
	$new = $orig !== '' ? wp_nginx_cache_rewrite($orig, $domain) : null;
	if ($new === null || $new === $orig)
		return array('', false);
	wp_write_conf($path, $new);
	$test = (string)shell_exec('sudo nginx -t 2>&1');
	if (stripos($test, 'test is successful') === false) {
		wp_write_conf($path, $orig);
		return array('nginx did not accept the new cache rules; nothing was changed. '.trim(preg_replace('/\s+/', ' ', $test)), false);
	}
	if ($reload)
		shell_exec('sudo systemctl reload nginx 2>&1');
	return array('', true);
}

/* Read a vhost via sudo (root-owned files are common in conf.d). */
function wp_read_conf($path) {
	return (string)shell_exec('sudo cat '.escapeshellarg($path).' 2>/dev/null');
}

/* Atomically replace a vhost's content via a temp file + sudo cp. */
function wp_write_conf($path, $content) {
	$tmp = tempnam('/tmp', 'reqadwp');
	file_put_contents($tmp, $content);
	shell_exec('sudo cp '.escapeshellarg($tmp).' '.escapeshellarg($path));
	@unlink($tmp);
}

/* Live state of the nginx cache option for a domain: 'on' | 'off' | 'na'. */
function wp_nginx_cache_state($ini, $domain) {
	if(!wp_is_nginx($ini)) return 'na';
	$path = wp_nginx_conf_path($domain);
	$c = wp_read_conf($path);
	if($c === '') return 'off';
	return (strpos($c, '# BEGIN reqad-nginx-cache') !== false) ? 'on' : 'off';
}

/* Canonical plugin slug/folder for the WordPress purge plugin. */
define('WP_CACHE_PURGER_SLUG', 'reqad-cache-purger');

/* Resolve the download URL of the latest reqad-cache-purger GitHub release.
   Returns '' when the API cannot be reached or has no .zip asset. The release
   asset (unlike a source archive) unpacks to a clean 'reqad-cache-purger/'
   folder, so wp-cli derives the canonical plugin slug from it. */
function wp_cache_purger_release_url() {
	$json = (string)shell_exec('curl -sfL --max-time 15 -H '.escapeshellarg('Accept: application/vnd.github+json')
		.' https://api.github.com/repos/wbdv/'.WP_CACHE_PURGER_SLUG.'/releases/latest 2>/dev/null');
	$d = @json_decode($json, true);
	if(empty($d['assets']) || !is_array($d['assets'])) return '';
	foreach($d['assets'] as $a) {
		$u = $a['browser_download_url'] ?? '';
		if($u !== '' && strtolower(substr($u, -4)) === '.zip') return $u;
	}
	return '';
}

/* Install (if missing) and activate the reqad-cache-purger WordPress plugin.
   It is not on wordpress.org, so wp-cli installs it straight from the latest
   GitHub *release* zip. Returns a log string; safe to call repeatedly. */
function wp_install_cache_purger($user, $docroot) {
	$slug   = WP_CACHE_PURGER_SLUG;
	$asuser = 'sudo -u '.escapeshellarg($user).' ';
	$log    = '';

	$is = trim((string)shell_exec($asuser.'/usr/local/bin/wp plugin is-installed '.$slug
		.' --path='.escapeshellarg($docroot).' 2>/dev/null; echo $?'));

	if(substr($is, -1) !== '0') {
		$url = wp_cache_purger_release_url();
		if($url === '')
			return "Error: could not determine the latest ".$slug." release from GitHub.\n";
		$log .= "Installing ".$slug." from ".$url."\n";
		// --activate installs and enables in one step.
		$log .= (string)shell_exec($asuser.'/usr/local/bin/wp plugin install '.escapeshellarg($url)
			.' --force --activate --path='.escapeshellarg($docroot).' 2>&1');
	} else {
		$log .= $slug." already installed.\n";
		$log .= (string)shell_exec($asuser.'/usr/local/bin/wp plugin activate '.$slug
			.' --path='.escapeshellarg($docroot).' 2>&1');
	}
	return $log;
}

/* Enable the nginx page cache for a site: inject the fragments into the vhost
   (FastCGI cache, or the proxy cache with Apache behind nginx:
   wp_nginx_cache_mode()), create the cache dir, install+activate the purge
   plugin, test & reload. Reverts the vhost on a failed `nginx -t`. Returns
   array(error, success, log). */
function wp_nginx_cache_enable($ini, $user, $domain, $docroot, $zone = null) {
	$log = '';
	if(!wp_is_nginx($ini))
		return array('error' => 'This server does not use nginx, so the page cache is not available.', 'success' => '', 'log' => '');

	$path = wp_nginx_conf_path($domain);
	$orig = wp_read_conf($path);
	if(trim($orig) === '')
		return array('error' => 'nginx vhost not found: '.$path, 'success' => '', 'log' => '');
	if(strpos($orig, '# BEGIN reqad-nginx-cache') !== false)
		return array('error' => '', 'success' => 'Nginx cache is already enabled.', 'log' => '');

	/* the zone: wordpress_tool_ctx() gives one per site (an account's sites must not share one) */
	$zone = $zone ?? wp_cache_zone($user);
	$ip   = reqad_server_ip();
	$mode = wp_nginx_cache_mode($orig);
	$frag = wp_nginx_cache_fragments($zone, $ip, wp_cache_rules_get($domain), $mode);

	// 1) http-context block at the very top of the file (conf.d is http context).
	$new = $frag['http']."\n".$orig;

	// 2) purge block: after the server-level `index ...;` line (443 vhost only).
	$lines = explode("\n", $new);
	$out = array(); $did_purge = false;
	foreach($lines as $ln) {
		$out[] = $ln;
		if(!$did_purge && preg_match('/^\s*index\s+/', $ln)) {
			$out[] = rtrim($frag['purge'], "\n");
			$did_purge = true;
		}
	}
	if(!$did_purge)
		return array('error' => 'Could not locate an `index` directive in the vhost to anchor the purge block.', 'success' => '', 'log' => '');
	$new = implode("\n", $out);

	// 3) location-level cache directives: right after the `fastcgi_pass php-fpm-<user>;`
	//    line, or (Apache behind nginx) after each include of reqad-backend.conf.
	list($new, $placed) = wp_nginx_cache_place($new, $frag, $mode);
	if(!$placed)
		return array('error' => $mode === 'proxy'
			? 'Could not find the line that passes requests to Apache (include /etc/nginx/reqad-backend.conf;) in the vhost to anchor the cache directives.'
			: 'Could not locate the `fastcgi_pass` line in the vhost to anchor the cache directives.', 'success' => '', 'log' => '');

	// Cache dir (nginx creates sublevels itself, but needs the root to exist+own).
	// 0700: other accounts must not be able to read or plant cache entries.
	shell_exec('sudo install -d -o nginx -g nginx -m 0700 '.escapeshellarg(wp_nginx_cache_dir($zone, $mode)).' 2>/dev/null');

	// Snapshot the pre-change vhost into the Advanced Config version history so the
	// admin can roll back the cache injection from that panel's "Version history".
	save_config_backup($user, 'nginx', $path, $orig);

	// Write, test, revert on failure.
	wp_write_conf($path, $new);
	$test = (string)shell_exec('sudo nginx -t 2>&1');
	$log .= $test;
	if(stripos($test, 'test is successful') === false) {
		wp_write_conf($path, $orig);   // revert
		return array('error' => 'nginx config test failed; reverted. '.trim(preg_replace('/\s+/', ' ', $test)), 'success' => '', 'log' => $log);
	}
	shell_exec('sudo systemctl reload nginx 2>&1');

	// Install + activate the WordPress purge plugin (idempotent). Not on
	// wordpress.org, so wp-cli pulls the latest GitHub release zip.
	$log .= "\n".wp_install_cache_purger($user, $docroot);

	log_debug('[wp-manage] nginx-cache enabled for '.$domain.' ('.$user.')');
	return array('error' => '', 'success' => 'Nginx page cache enabled and nginx reloaded.', 'log' => $log);
}

/* Disable the nginx cache: strip the marker blocks, test & reload, empty
   the cache dir and deactivate the purge plugin. Returns array(error, success, log). */
function wp_nginx_cache_disable($ini, $user, $domain, $docroot, $zone = null) {
	$log = '';
	if(!wp_is_nginx($ini))
		return array('error' => 'This server does not use nginx.', 'success' => '', 'log' => '');

	$path = wp_nginx_conf_path($domain);
	$orig = wp_read_conf($path);
	if(trim($orig) === '')
		return array('error' => 'nginx vhost not found: '.$path, 'success' => '', 'log' => '');
	if(strpos($orig, '# BEGIN reqad-nginx-cache') === false)
		return array('error' => '', 'success' => 'Nginx cache is already disabled.', 'log' => '');

	// Remove each marker block (and the blank line that follows it, if any).
	$new = $orig;
	foreach(array('reqad-nginx-cache-purge', 'reqad-nginx-cache-fcgi', 'reqad-nginx-cache-proxy', 'reqad-nginx-cache') as $mk) {
		$new = preg_replace('/[ \t]*# BEGIN '.$mk.'\b.*?# END '.$mk.'[^\n]*\n?\n?/s', '', $new);
	}

	// Snapshot the pre-change (cached) vhost into the Advanced Config version
	// history before stripping the cache, so it can be restored from that panel.
	save_config_backup($user, 'nginx', $path, $orig);

	wp_write_conf($path, $new);
	$test = (string)shell_exec('sudo nginx -t 2>&1');
	$log .= $test;
	if(stripos($test, 'test is successful') === false) {
		wp_write_conf($path, $orig);   // revert
		return array('error' => 'nginx config test failed after removal; reverted. '.trim(preg_replace('/\s+/', ' ', $test)), 'success' => '', 'log' => $log);
	}
	shell_exec('sudo systemctl reload nginx 2>&1');

	// Clear the cache store and deactivate the plugin (leave it installed).
	if(preg_match('/keys_zone=([a-z0-9_]+):/', $orig, $zm))
		$zone = $zm[1];
	$zone = preg_replace('/[^a-z0-9_]/', '', (string)($zone ?? wp_cache_zone($user)));
	if($zone !== '')
		foreach(array('fcgi', 'proxy') as $kind)
			shell_exec('sudo rm -rf '.escapeshellarg(wp_nginx_cache_dir($zone, $kind)).'/* 2>/dev/null');
	// Deactivate the canonical slug, plus any branch-suffixed copy left behind by
	// an install that went through the GitHub archive (reqad-cache-purger-main).
	foreach(array(WP_CACHE_PURGER_SLUG, WP_CACHE_PURGER_SLUG.'-main', WP_CACHE_PURGER_SLUG.'-master') as $pslug) {
		$is = trim((string)shell_exec('sudo -u '.escapeshellarg($user).' /usr/local/bin/wp plugin is-installed '.$pslug.' --path='.escapeshellarg($docroot).' 2>/dev/null; echo $?'));
		if(substr($is, -1) === '0')
			$log .= "\n".(string)shell_exec('sudo -u '.escapeshellarg($user).' /usr/local/bin/wp plugin deactivate '.$pslug.' --path='.escapeshellarg($docroot).' 2>&1');
	}

	log_debug('[wp-manage] nginx-cache disabled for '.$domain.' ('.$user.')');
	return array('error' => '', 'success' => 'Nginx page cache disabled and nginx reloaded.', 'log' => $log);
}

/* Disable WP-Cron, search engine indexing, maintenance mode, the must-use
   plugins and file permissions: app/functions/wordpress_tools.php (as the
   account, per install), for both panels. */

/* Run one wp-cli command as $user. Returns array('out' => string, 'rc' => int).
   stderr is folded into the output and the exit status is kept, because PHP can
   die BEFORE wp-cli runs at all: a bad OPcache ini (interned_strings_buffer not
   fitting in memory_consumption) aborts every PHP process for that version at
   startup. Reading only stdout made that look like an empty, successful run. */
function wp_cli_run($user, $args) {
	$out = (string)shell_exec('sudo -u '.escapeshellarg($user).' /usr/local/bin/wp '.$args.' 2>&1; echo "|rc:$?"');
	$rc  = 255;
	$pos = strrpos($out, '|rc:');
	if($pos !== false) {
		$rc  = (int)substr($out, $pos + 4);
		$out = substr($out, 0, $pos);
	}
	return array('out' => rtrim($out), 'rc' => $rc);
}

/* Turn a failed wp_cli_run() into something the user can act on. Returns '' when
   the command succeeded. */
function wp_cli_error($r) {
	if($r['rc'] === 0)
		return '';
	$out = $r['out'];
	if(stripos($out, 'interned strings buffer') !== false || stripos($out, 'Insufficient shared memory') !== false)
		return 'PHP itself cannot start on this server: OPcache is misconfigured. '
		     . 'opcache.interned_strings_buffer must be well below opcache.memory_consumption. '
		     . 'Fix it under PHP Settings, then restart php-fpm.';
	if($r['rc'] === 124 || $r['rc'] === 137)
		return 'wp-cli did not finish in time and was stopped. The site may be stuck: its code waits for another server or runs in a loop.';
	if(stripos($out, 'establishing a database connection') !== false)
		return 'WordPress cannot connect to its database. Check DB_NAME, DB_USER, DB_PASSWORD and DB_HOST in wp-config.php, and that the database and its user still exist.';
	if(stripos($out, 'does not seem to be a WordPress installation') !== false)
		return 'There is no complete WordPress in this folder (wp-config.php or the WordPress files are missing).';
	/* the PHP error itself, without its stack trace; homes as ~/ */
	if(preg_match('/PHP (Fatal|Parse) error:\s*(?:Uncaught\s+)?(.+)$/mi', $out, $m) || preg_match('/^(Fatal|Parse) error:\s*(?:Uncaught\s+)?(.+)$/mi', $out, $m)) {
		$msg = preg_replace(array('/\s*Stack trace:.*$/s', '/^Error:\s*/'), '', trim($m[2]));
		$msg = preg_replace('~/home/[A-Za-z0-9_.-]+/~', '~/', $msg);
		/* wp-cli runs wp-config.php through eval() */
		$msg = preg_replace('~ in phar://\S+Runner\.php\(\d+\) : eval\(\)\'d code on line (\d+)~', ' in wp-config.php, around line $1', $msg);
		return 'WordPress stops with a PHP error: '.(mb_strlen($msg) > 400 ? mb_substr($msg, 0, 400).'…' : $msg);
	}
	if(stripos($out, 'critical error on this website') !== false)
		return 'WordPress stops with a PHP error (in a plugin, the theme or its own files); wp-cli did not say which.';
	if(trim($out) === '')
		return 'wp-cli failed (exit '.$r['rc'].') with no output.';
	return trim($out);
}

/* Performance-tab status of an account's tracked install (2087's site page,
   one per account): core's wordpress_tools_status(). null when the account
   has no tracked install. $probe: a wp_site_probe() result, if at hand. */
function wp_manage_status($db, $ini, $user, $probe = null) {
	$row = wp_site_row($db, $user);
	if(!$row) return null;
	$st = wordpress_tools_status($row, $probe);
	return array('user' => $row['user'], 'domain' => $row['domain'], 'is_nginx' => wp_is_nginx($ini)) + $st['data']['performance'];
}

/* --- WP Toolkit site page (/wp-toolkit/<user>/) --------------------------- */

/* The tracked install for an account, or false. $user must already be a
   validated account name. */
function wp_site_row($db, $user) {
	$res = $db->query('SELECT * FROM wordpress WHERE user="'.$db->escapeString($user).'"');
	return $res ? $res->fetchArray(SQLITE3_ASSOC) : false;
}

/* URL path prefix of a subdirectory install ('' for the docroot), safe to drop
   into an nginx regex. Anything outside a plain path charset yields null. */
function wp_site_url_prefix($path) {
	$path = trim((string)$path, '/');
	if($path === '') return '';
	if(!preg_match('#^[A-Za-z0-9._/-]+$#', $path) || strpos($path, '..') !== false) return null;
	return '/'.preg_quote($path, '#');
}

/* wordpress.org core stable-check (version => latest|outdated|insecure),
   cached for an hour in wptoolkit/stable-check.json. Returns array($map, $err). */
function wp_stable_versions() {
	$file = _PATH.'/wptoolkit/stable-check.json';
	$err  = '';
	if(!is_file($file) || time() - filemtime($file) >= 3600) {
		shell_exec('curl -s --max-time 10 https://api.wordpress.org/core/stable-check/1.0/ > '.escapeshellarg($file.'.tmp'));
		$v = @json_decode((string)@file_get_contents($file.'.tmp'), true);
		if(!empty($v))
			@rename($file.'.tmp', $file);
		else
			$err = 'Error: Cannot download stable-check.json from wordpress.org website.';
	}
	$map = is_file($file) ? json_decode((string)file_get_contents($file), true) : array();
	return array(is_array($map) ? $map : array(), $err);
}

/* PHP version an account's site runs on — inferred from which php-fpm.d dir
   holds its pool (same rule as the accounts pages); falls back to the default. */
function wp_site_php_version($ini, $domain) {
	if(function_exists('account_php_disabled') && account_php_disabled($domain))
		return 'disabled';
	$ver = $ini['php'] ?? '';
	foreach(array_map('trim', explode(',', $ini['php_versions'] ?? '')) as $pv) {
		if($pv !== '' && is_file('/etc/opt/remi/php'.str_replace('.', '', $pv).'/php-fpm.d/'.$domain.'.conf'))
			$ver = $pv;
	}
	return $ver;
}

/* One wp-cli round trip that reads everything the site page shows: header
   cards plus the WordPress-side state of the Performance/Security options.
   Every wp-cli start costs 1-2s, so this replaces a string of `config get`s.
   Runs as the site's own user (tenant PHP never enters the panel process). */
function wp_site_probe($user, $docroot) {
	$code = <<<'EOT'
$t = wp_get_theme();
if (!function_exists('get_plugins')) require_once ABSPATH.'wp-admin/includes/plugin.php';
$all = get_plugins();
$act = (array)get_option('active_plugins');
$up  = get_site_transient('update_plugins');
$weak = 0;
foreach (array('AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT') as $k)
	if (!defined($k) || strlen(constant($k)) < 32 || constant($k) === 'put your unique phrase here') $weak++;
/* WPS Hide Login's own address: /<slug>/ with pretty permalinks, /?<slug> without */
$whl = (string)get_option('whl_page');
$ps = (string)get_option('permalink_structure');
echo "\n##REQAD##".json_encode(array(
	'wp_version'      => get_bloginfo('version'),
	'title'           => get_bloginfo('name'),
	'tagline'         => get_bloginfo('description'),
	'site_url'        => home_url('/'),
	'theme'           => $t->get('Name'),
	'theme_version'   => $t->get('Version'),
	'plugins_total'   => count($all),
	'plugins_active'  => count(array_intersect($act, array_keys($all))),
	'plugins_updates' => (is_object($up) && !empty($up->response)) ? count($up->response) : 0,
	'disable_wp_cron' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
	'file_edit_off'   => defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT,
	'concat_off'      => defined('CONCATENATE_SCRIPTS') && !CONCATENATE_SCRIPTS,
	'ping_status'     => get_option('default_ping_status'),
	'pingback_flag'   => (string)get_option('default_pingback_flag'),
	'blog_public'     => (string)get_option('blog_public'),
	'weak_salts'      => $weak,
	'hide_login'      => is_plugin_active('wps-hide-login/wps-hide-login.php'),
	'login_slug'      => $whl,
	'login_url'       => $whl === '' ? '' : home_url('/').($ps !== '' ? $whl.(substr($ps, -1) === '/' ? '/' : '') : '?'.$whl),
	'maintenance'     => is_file((defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR.'/mu-plugins').'/reqad-maintenance.php'),
	'hide_version'    => is_file((defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR.'/mu-plugins').'/reqad-hide-version.php'),
	'mu_dir'          => defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR.'/mu-plugins',
));
EOT;
	// --skip-plugins: everything read here is options, constants and plugin
	// headers, none of which needs plugin code — halves the time on busy sites
	$r = wp_cli_run($user, 'eval '.escapeshellarg($code).' --skip-themes --skip-plugins --path='.escapeshellarg($docroot));
	$pos = strrpos($r['out'], '##REQAD##');
	$d = ($pos !== false) ? json_decode(substr($r['out'], $pos + 9), true) : null;
	if(!is_array($d))
		return array('error' => wp_cli_error($r) ?: 'Could not read the WordPress site.');
	$d['error'] = '';
	// last good probe, so the site page can render its switches at once and
	// refresh them afterwards (wp-cli needs ~1s just to start)
	$cache = wp_site_probe_file($user);
	@mkdir(dirname($cache), 0775, true);
	@file_put_contents($cache, json_encode($d));
	return $d;
}

function wp_site_probe_file($user) {
	return _PATH.'/wptoolkit/cache/probe-'.wp_cache_zone($user).'.json';
}

/* Last good wp_site_probe() result, or a placeholder whose error keeps every
   WordPress-dependent option "pending" (rendered as N/A until the refresh). */
function wp_site_probe_cached($user) {
	$d = @json_decode((string)@file_get_contents(wp_site_probe_file($user)), true);
	return is_array($d) ? $d : array('error' => 'pending');
}

/* Empty a site's nginx page cache (FastCGI or proxy) (no-op when there is none). Used when an
   option changes what anonymous visitors must see (maintenance, robots.txt). */
/* $zone: a site's cache zone, or an account name - then every site of the account
   (their zones are <account> and <account>_<hash>, wordpress_tool_ctx()) */
function wp_nginx_cache_purge_all($zone) {
	$zone = preg_replace('/[^a-z0-9_]/', '', strtolower((string)$zone));
	if($zone !== '')
		shell_exec('sudo sh -c '.escapeshellarg('rm -rf /var/cache/nginx/fcgi-'.$zone.'/* /var/cache/nginx/fcgi-'.$zone.'_*/*'
			.' /var/cache/nginx/proxy-'.$zone.'/* /var/cache/nginx/proxy-'.$zone.'_*/*').' 2>/dev/null');
}

/* --- Site screenshot -----------------------------------------------------
   Headless Chromium renders the live home page. It runs as the site's own
   user with the sandbox on — the page is tenant-controlled content and the
   panel user has passwordless sudo — and only the finished PNG crosses back. */

function wp_screenshot_file($user) {
	return _PATH.'/wptoolkit/screenshots/'.wp_cache_zone($user).'.jpg';
}

function wp_chromium_bin() {
	foreach(array('/usr/bin/chromium-browser', '/usr/bin/chromium', '/usr/bin/google-chrome') as $b)
		if(is_file($b)) return $b;
	return '';
}

function wp_screenshot_make($user, $url) {
	$bin = wp_chromium_bin();
	if($bin === '')
		return 'Chromium is not installed on this server (dnf install chromium-headless or chromium).';
	$work = '/home/'.$user.'/tmp/.reqad-shot';
	$asuser = 'sudo -u '.escapeshellarg($user).' ';
	shell_exec('sudo rm -rf '.escapeshellarg($work));
	shell_exec($asuser.'mkdir -p '.escapeshellarg($work));
	shell_exec($asuser.'timeout 45 '.$bin.' --headless --disable-gpu --hide-scrollbars --no-first-run'
		.' --user-data-dir='.escapeshellarg($work.'/profile').' --window-size=1280,800 --virtual-time-budget=4000'
		.' --screenshot='.escapeshellarg($work.'/shot.png').' '.escapeshellarg($url).' >/dev/null 2>&1');
	$png = (string)shell_exec('sudo cat '.escapeshellarg($work.'/shot.png').' 2>/dev/null');
	shell_exec('sudo rm -rf '.escapeshellarg($work));
	if($png === '')
		return 'Chromium could not render '.$url.'.';
	return wp_screenshot_save($user, $png);
}

/* Store image bytes (PNG/JPEG/WebP) as the site's 640px-wide JPEG thumbnail.
   Returns '' or an error. */
function wp_screenshot_save($user, $bytes) {
	$dst = wp_screenshot_file($user);
	@mkdir(dirname($dst), 0775, true);
	$im = function_exists('imagecreatefromstring') ? @imagecreatefromstring($bytes) : false;
	if($im === false)
		return 'Could not decode the screenshot (PHP GD missing?).';
	$w = 640; $hgt = (int)round(imagesy($im) * $w / max(1, imagesx($im)));
	$th = imagecreatetruecolor($w, $hgt);
	imagecopyresampled($th, $im, 0, 0, 0, 0, $w, $hgt, imagesx($im), imagesy($im));
	imagejpeg($th, $dst, 82);
	return '';
}

/* --- Google PageSpeed Insights --------------------------------------------
   Scores (performance, accessibility, best practices, SEO) and the Core Web
   Vitals lab metrics for mobile and desktop, plus the desktop run's final
   screenshot, which becomes the site thumbnail — so servers without Chromium
   still get one. Google fetches the site itself, so it must be public.
   Anonymous use has a quota of 0 requests/day; a free API key (25k/day) is
   kept in settings as `pagespeed-api-key`. To be replaced by Reqad's own API. */

define('WP_PSI_ENDPOINT', 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed');

function wp_pagespeed_key() {
	return trim((string)setting_get('pagespeed-api-key'));
}

function wp_pagespeed_file($user) {
	return _PATH.'/wptoolkit/pagespeed/'.wp_cache_zone($user).'.json';
}

/* Cached result from the last run, or null. */
function wp_pagespeed_cached($user) {
	$d = @json_decode((string)@file_get_contents(wp_pagespeed_file($user)), true);
	return is_array($d) ? $d : null;
}

/* Reduce one runPagespeed response to what the page shows. Returns
   array(error, result, screenshot-bytes). */
function wp_pagespeed_parse($body, $http) {
	$d = @json_decode((string)$body, true);
	if(!is_array($d))
		return array('PageSpeed Insights did not answer (HTTP '.$http.').', null, '');
	if(isset($d['error']))
		return array('PageSpeed Insights: '.($d['error']['message'] ?? 'error '.$http), null, '');
	$lr = $d['lighthouseResult'] ?? array();
	if(!empty($lr['runtimeError']['message']))
		return array('PageSpeed Insights could not load the site: '.$lr['runtimeError']['message'], null, '');

	$scores = array();
	foreach(array('performance', 'accessibility', 'best-practices', 'seo') as $c) {
		$s = $lr['categories'][$c]['score'] ?? null;
		$scores[$c] = ($s === null) ? null : (int)round($s * 100);
	}
	$metrics = array();
	foreach(array('first-contentful-paint' => 'First Contentful Paint', 'largest-contentful-paint' => 'Largest Contentful Paint',
	              'total-blocking-time' => 'Total Blocking Time', 'cumulative-layout-shift' => 'Cumulative Layout Shift',
	              'speed-index' => 'Speed Index') as $k => $label) {
		$a = $lr['audits'][$k] ?? null;
		if($a) $metrics[] = array('label' => $label, 'value' => (string)($a['displayValue'] ?? ''), 'score' => $a['score'] ?? null);
	}

	$shot = '';
	$uri = (string)($lr['audits']['final-screenshot']['details']['data'] ?? '');
	if(($p = strpos($uri, 'base64,')) !== false)
		$shot = (string)base64_decode(substr($uri, $p + 7));

	return array('', array('scores' => $scores, 'metrics' => $metrics), $shot);
}

/* Run mobile + desktop in parallel (each takes 15-40s at Google), cache the
   result, save the desktop screenshot as the thumbnail. Returns array(error, data). */
function wp_pagespeed_run($user, $url) {
	$key = wp_pagespeed_key();
	if($key === '')
		return array('No Google PageSpeed Insights API key is set (Settings).', null);

	$mh = curl_multi_init();
	$ch = array();
	foreach(array('mobile', 'desktop') as $strategy) {
		$q = 'url='.rawurlencode($url).'&strategy='.$strategy.'&key='.rawurlencode($key)
		   . '&category=performance&category=accessibility&category=best-practices&category=seo';
		$c = curl_init(WP_PSI_ENDPOINT.'?'.$q);
		curl_setopt_array($c, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 150, CURLOPT_CONNECTTIMEOUT => 15));
		curl_multi_add_handle($mh, $c);
		$ch[$strategy] = $c;
	}
	do {
		$st = curl_multi_exec($mh, $running);
		if($running) curl_multi_select($mh, 1.0);
	} while($running && $st == CURLM_OK);

	$out = array('url' => $url, 'fetched' => time());
	$errors = array();
	foreach($ch as $strategy => $c) {
		$body = curl_multi_getcontent($c);
		$http = curl_getinfo($c, CURLINFO_HTTP_CODE);
		if($body === '' || $body === null)
			$errors[] = ucfirst($strategy).': '.(curl_error($c) ?: 'no response');
		else {
			list($err, $res, $shot) = wp_pagespeed_parse($body, $http);
			if($err !== '') $errors[] = ucfirst($strategy).': '.$err;
			else {
				$out[$strategy] = $res;
				if($strategy === 'desktop' && $shot !== '')
					wp_screenshot_save($user, $shot);
			}
		}
		curl_multi_remove_handle($mh, $c);
		curl_close($c);
	}
	curl_multi_close($mh);

	if(!isset($out['mobile']) && !isset($out['desktop']))
		return array(implode(' ', array_unique($errors)), null);
	$file = wp_pagespeed_file($user);
	@mkdir(dirname($file), 0775, true);
	file_put_contents($file, json_encode($out));
	return array(implode(' ', $errors), $out);
}

/* --- Background jobs for the site page -----------------------------------
   A screenshot (Chromium) or a PageSpeed test (Google, up to a minute) must
   never hold the browser's request open: slow requests queued behind them and
   the page's switches stopped answering. The AJAX call records the job,
   answers at once, then keeps working after fastcgi_finish_request(); the
   page polls wp_bg_job_get(). State: wptoolkit/jobs/<kind>-<user>.json. */

function wp_bg_job_file($kind, $user) {
	return _PATH.'/wptoolkit/jobs/'.$kind.'-'.wp_cache_zone($user).'.json';
}

function wp_bg_job_get($kind, $user) {
	$j = @json_decode((string)@file_get_contents(wp_bg_job_file($kind, $user)), true);
	if(!is_array($j)) return array('running' => false, 'error' => '', 'done' => 0);
	// a worker killed mid-job must not look busy forever
	if(!empty($j['running']) && time() - (int)($j['started'] ?? 0) > 300) {
		$j['running'] = false;
		$j['error'] = 'The job did not finish.';
	}
	return $j;
}

function wp_bg_job_put($kind, $user, $state) {
	$file = wp_bg_job_file($kind, $user);
	@mkdir(dirname($file), 0775, true);
	file_put_contents($file, json_encode($state), LOCK_EX);
}

/* Start $fn as a background job unless one is already running. Sends the JSON
   answer and closes the request BEFORE running it. */
function wp_bg_job_run($kind, $user, $fn) {
	$cur = wp_bg_job_get($kind, $user);
	header('Content-Type: application/json');
	if(!empty($cur['running'])) {
		echo json_encode(array('ok' => true, 'running' => true));
		return;
	}
	$started = time();
	wp_bg_job_put($kind, $user, array('running' => true, 'started' => $started, 'error' => '', 'done' => 0));
	echo json_encode(array('ok' => true, 'running' => true));
	if(function_exists('fastcgi_finish_request'))
		fastcgi_finish_request();
	ignore_user_abort(true);
	set_time_limit(300);
	$err = '';
	try { $err = (string)$fn(); }
	catch(Throwable $e) { $err = $e->getMessage(); }
	wp_bg_job_put($kind, $user, array('running' => false, 'started' => $started, 'error' => $err, 'done' => time()));
}

/* --- Security hardening ---------------------------------------------------
   Server-side rules live in one include per site,
   /etc/nginx/reqad-wp-security/<domain>.conf, pulled into the vhost's
   `location /` right after try_files. It has to be there and not at server
   level: the vhost nests `location ~ \.php$` inside `location /`, and nginx
   tries a prefix location's nested regexes before any server-level regex, so a
   server-level deny for wp-includes/*.php would never be reached. Inside
   `location /` our blocks come first in file order and win.
   The include file is the source of truth — one BEGIN/END block per option. */

define('WP_SEC_INC_DIR', '/etc/nginx/reqad-wp-security');

/* Option catalogue for the Security tab. type: nginx | wpconfig | option |
   plugin | action (one-way: re-running is the only operation). */
function wp_security_options() {
	return array(
		'xmlrpc' => array('type' => 'nginx', 'rec' => true, 'label' => 'Block access to xmlrpc.php',
			'desc' => 'XML-RPC is a favourite target for password brute-forcing and pingback floods. Leave this off if you use Jetpack or the WordPress mobile app.'),
		'wp_includes_php' => array('type' => 'nginx', 'rec' => true, 'label' => 'Forbid PHP execution in wp-includes',
			'desc' => 'Core PHP files there are only ever included, never requested directly. Blocks planted backdoors and direct probing.'),
		'uploads_php' => array('type' => 'nginx', 'rec' => true, 'label' => 'Forbid PHP execution in wp-content/uploads',
			'desc' => 'The most common place for an uploaded web shell. Uploads are media — nothing there should ever run.'),
		'cache_php' => array('type' => 'nginx', 'rec' => true, 'label' => 'Forbid PHP execution in cache directories',
			'desc' => 'Same idea for wp-content/cache, which caching plugins keep writable.'),
		'wp_config' => array('type' => 'nginx', 'rec' => true, 'label' => 'Block access to wp-config.php',
			'desc' => 'Keeps the database credentials unreachable over HTTP even if PHP is ever misconfigured and serves source.'),
		'sensitive_files' => array('type' => 'nginx', 'rec' => true, 'label' => 'Block access to sensitive files',
			'desc' => 'readme.html, license.txt (they give away the WordPress version), wp-config-sample.php, wp-admin/install.php and setup-config.php (only needed before WordPress is installed), dot-files (.htaccess, .htpasswd, .git, .env) and backup/log/dump files (.sql, .bak, .log, .ini, .sh, *~ …).'),
		'security_headers' => array('type' => 'nginx', 'rec' => true, 'label' => 'Add security headers',
			'desc' => '<code>Strict-Transport-Security</code> (browsers use HTTPS only, for a year), <code>X-Frame-Options: SAMEORIGIN</code> (no framing by other sites, against clickjacking) and <code>X-Content-Type-Options: nosniff</code>. Copies WordPress or a plugin already sends are replaced, so each header appears once.'),
		'admin_ip_allow' => array('type' => 'nginx', 'rec' => false, 'label' => 'Allow login and core updates only from these IPs',
			'desc' => 'Only the addresses below (and this server) can open <code>wp-login.php</code>, <code>wp-admin/upgrade.php</code> and <code>wp-admin/update-core.php</code>; everyone else gets a 404 page. Stops password guessing completely. Visitors can still unlock password-protected posts. Use it only when nobody else needs to log in &mdash; customers, members or authors would be locked out, and so would &ldquo;Lost your password?&rdquo;. If your IP changes, the panel\'s Log in button still gets you in.'),
		'author_scans' => array('type' => 'nginx', 'rec' => true, 'label' => 'Block author scans',
			'desc' => 'Refuses <code>/?author=N</code>, which bots use to harvest login names.'),
		'bad_bots' => array('type' => 'nginx', 'rec' => false, 'label' => 'Block aggressive crawlers',
			'desc' => 'Answers 404 to SEO crawlers and scrapers (AhrefsBot, SemrushBot, MJ12bot, DotBot, Bytespider …) that eat CPU. Leave off if you rely on those SEO tools.'),
		'file_edit' => array('type' => 'wpconfig', 'rec' => true, 'label' => 'Disable file editing in the WordPress dashboard',
			'desc' => 'Sets <code>DISALLOW_FILE_EDIT</code>: a stolen admin login can no longer rewrite theme/plugin PHP from the browser.'),
		'concatenate_scripts' => array('type' => 'wpconfig', 'rec' => true, 'label' => 'Disable script concatenation in wp-admin',
			'desc' => 'Sets <code>CONCATENATE_SCRIPTS</code> to false, closing the load-scripts.php denial-of-service vector.'),
		'pingbacks' => array('type' => 'option', 'rec' => true, 'label' => 'Turn off pingbacks',
			'desc' => 'Pingbacks are mostly spam and are abused for DDoS reflection. Applies to new posts.'),
		'hide_version' => array('type' => 'muplugin', 'rec' => true, 'label' => 'Hide the WordPress version',
			'file' => 'reqad-hide-version.php', 'src' => 'wordpress-hide-version.php',
			'desc' => 'Removes the <code>generator</code> tag from pages and feeds and replaces <code>?ver=&lt;version&gt;</code> on core scripts and styles with a hash, so scanners cannot match the site to known vulnerabilities by version. Pair it with <i>Block access to sensitive files</i> (readme.html).'),
		'hide_login' => array('type' => 'plugin', 'rec' => false, 'label' => 'Hide wp-admin — move the login to a new URL',
			'desc' => 'Installs the <a href="https://wordpress.org/plugins/wps-hide-login/" target="_blank">WPS Hide Login</a> plugin: <code>wp-login.php</code> and <code>/wp-admin</code> return 404 to visitors who are not logged in. The panel\'s Login button keeps working.'),
		'security_keys' => array('type' => 'action', 'rec' => true, 'label' => 'Configure security keys',
			'desc' => 'Makes sure all eight keys/salts in wp-config.php are set and strong. Regenerating signs every user out.'),
		'permissions' => array('type' => 'action', 'rec' => true, 'label' => 'Restrict access to files and directories',
			'desc' => 'wp-config.php becomes readable by the site owner only (600) and world-writable files/directories lose the o+w bit.'),
	);
}

/* nginx fragment for one option. $p is the regex-safe URL prefix. */
function wp_sec_nginx_rules($key, $p, $ctx = array()) {
	// Everything answers 404, not 403: a blocked file must look exactly like a
	// missing one (403 confirms it exists)
	$php = '\.(php\d*|phtml|phar|pht)$';
	switch($key) {
		case 'xmlrpc':
			return "location ~* ^$p/xmlrpc\.php\$ { return 404; access_log off; log_not_found off; }";
		case 'wp_includes_php':
			// wp-tinymce.php and ms-files.php are the two core files meant to be requested
			return "location ~* ^$p/wp-includes/(?!js/tinymce/wp-tinymce\.php\$|ms-files\.php\$).+$php { return 404; }";
		case 'uploads_php':
			return "location ~* ^$p/wp-content/uploads/.*$php { return 404; }";
		case 'cache_php':
			return "location ~* ^$p/wp-content/cache/.*$php { return 404; }";
		case 'wp_config':
			return "location ~* ^$p/wp-config\.php\$ { return 404; }";
		case 'sensitive_files':
			// install.php / setup-config.php only matter before WordPress is
			// installed; Reqad installs through wp-cli, so nothing needs them after
			return "location ~* ^$p/(readme\.html|license\.txt|wp-config-sample\.php|wp-admin/(install|setup-config)\.php)\$ { return 404; }\n"
			     . "location ~ /\.(?!well-known/) { return 404; }\n"
			     . "location ~* (\.(sql|sql\.gz|bak|old|orig|save|swp|swo|log|ini|sh|inc|dist)|~)\$ { return 404; }";
		/* server level (wp_sec_server_body()): an `if` in `location /` does not reach
		   the nested locations, such as a WordPress folder's (reqad-wp-folders) */
		case 'author_scans':
		case 'bad_bots':
		case 'security_headers':
			return "# server-level: ".wp_sec_server_path($ctx['domain'] ?? '');
		case 'admin_ip_allow':
			/* These URIs must still run PHP for allowed clients, and nginx cannot
			   fall through to the vhost's own `location ~ \.php$` once this one
			   matched — so the handler body is copied from the vhost ($ctx['php']).
			   The allow decision is a map (http level, see wp_sec_http_body) so the
			   only `if` here is a bare return. */
			$v = $ctx['var'];
			return "location ~* ^$p/(wp-login\.php|wp-admin/(upgrade|update-core)\.php)\$ {\n"
			     . "    if (\$${v}_deny) { return 404; }\n"
			     . $ctx['php']
			     . "}";
	}
	return '';
}

/* The URL prefix (a regex) of a domain's Security rules: its one install's
   folder, or any folder when the domain has several installs (the rules are
   per vhost, so they must cover all of them). null when a folder cannot go
   into a regex. */
function wp_sec_domain_prefix($domain, $path) {
	global $db;
	$paths = array();
	$stmt = $db->prepare('SELECT path FROM wordpress WHERE lower(domain) = :d');
	$stmt->bindValue(':d', strtolower((string)$domain), SQLITE3_TEXT);
	$res = $stmt->execute();
	while($res && ($r = $res->fetchArray(SQLITE3_ASSOC)))
		$paths[trim((string)$r['path'], '/')] = true;
	if(count($paths) > 1)
		return '(?:/.*)?';
	return wp_site_url_prefix($paths ? array_keys($paths)[0] : $path);
}

function wp_sec_inc_path($domain) {
	return WP_SEC_INC_DIR.'/'.$domain.'.conf';
}

/* server-context companion (security headers) — add_header in `location /`
   would stop every server-level add_header (e.g. the cache's X-FastCGI-Cache)
   from being inherited, so headers go in at server level instead */
function wp_sec_server_path($domain) {
	return WP_SEC_INC_DIR.'/'.$domain.'.server.conf';
}

function wp_sec_server_line($domain) {
	return '	include '.wp_sec_server_path($domain).'; # reqad-wp-security-server';
}

/* The measures that must sit at server level */
function wp_sec_server_keys() {
	return array('security_headers', 'author_scans', 'bad_bots');
}

function wp_sec_server_body($keys = array('security_headers')) {
	$b = "# Managed by the Reqad WP Toolkit (Security tab) — changes here are overwritten.\n";
	if(in_array('author_scans', $keys, true))
		$b .= "if (\$arg_author ~ \"^[0-9]\") { return 404; }\n";
	if(in_array('bad_bots', $keys, true))
		$b .= "if (\$http_user_agent ~* \"(AhrefsBot|AhrefsSiteAudit|SemrushBot|MJ12bot|DotBot|BLEXBot|SEOkicks|dataforseo|rogerbot|serpstatbot|SeekportBot|Timpibot|Bytespider|PetalBot|MegaIndex|Barkrowler)\") { return 404; }\n";
	if(!in_array('security_headers', $keys, true))
		return $b;
	return $b
	     . "# Drop the copies PHP (WordPress, plugins) may send, so each header appears once.\n"
	     . "fastcgi_hide_header Strict-Transport-Security;\n"
	     . "fastcgi_hide_header X-Frame-Options;\n"
	     . "fastcgi_hide_header X-Content-Type-Options;\n"
	     . "proxy_hide_header Strict-Transport-Security;\n"
	     . "proxy_hide_header X-Frame-Options;\n"
	     . "proxy_hide_header X-Content-Type-Options;\n"
	     . "add_header Strict-Transport-Security \"max-age=31536000\" always;\n"
	     . "add_header X-Frame-Options \"SAMEORIGIN\" always;\n"
	     . "add_header X-Content-Type-Options \"nosniff\" always;\n";
}

/* http-context companion (the geo/map of the IP allowlist) */
function wp_sec_http_path($domain) {
	return WP_SEC_INC_DIR.'/'.$domain.'.http.conf';
}

function wp_sec_include_line($domain) {
	return '        include '.wp_sec_inc_path($domain).'; # reqad-wp-security';
}

function wp_sec_http_line($domain) {
	return 'include '.wp_sec_http_path($domain).'; # reqad-wp-security-http';
}

/* nginx variable prefix for a domain (map/geo names are global to nginx) */
function wp_sec_var($domain) {
	return 'reqad_wpip_'.preg_replace('/[^a-z0-9]/', '_', strtolower($domain));
}

/* nginx options currently enforced for a domain (include wired in + block present). */
function wp_sec_nginx_enabled($domain) {
	if(!preg_match('/# reqad-wp-security$/m', wp_read_conf(wp_nginx_conf_path($domain))))
		return array();
	preg_match_all('/^# BEGIN reqad-sec (\w+)/m', wp_read_conf(wp_sec_inc_path($domain)), $m);
	return $m[1];
}

/* This server's own addresses. They are always allowed: WordPress's core
   updater POSTs to wp-admin/upgrade.php over HTTP from the server itself. */
function wp_sec_server_ips() {
	$ips = array('127.0.0.1', '::1');
	$out = (string)shell_exec("/usr/sbin/ip -o addr show scope global 2>/dev/null | awk '{print \$4}' | cut -d/ -f1");
	foreach(preg_split('/\s+/', trim($out)) as $ip)
		if(filter_var($ip, FILTER_VALIDATE_IP)) $ips[] = $ip;
	return array_values(array_unique($ips));
}

/* One IP or CIDR, normalised; '' when invalid. */
function wp_sec_valid_ip($s) {
	$s = trim((string)$s);
	if(filter_var($s, FILTER_VALIDATE_IP)) return $s;
	if(preg_match('#^([0-9a-fA-F:.]+)/(\d{1,3})$#', $s, $m) && ($ip = filter_var($m[1], FILTER_VALIDATE_IP))) {
		$max = strpos($ip, ':') !== false ? 128 : 32;
		if((int)$m[2] >= 1 && (int)$m[2] <= $max) return $ip.'/'.(int)$m[2];
	}
	return '';
}

/* The admin-entered allowlist, read back from the http include. */
function wp_sec_allowed_ips($domain) {
	preg_match_all('/^\s*(\S+)\s+1;\s*# allowed$/m', wp_read_conf(wp_sec_http_path($domain)), $m);
	return $m[1];
}

function wp_sec_http_body($domain, $ips) {
	$v = wp_sec_var($domain);
	$b = "# Managed by the Reqad WP Toolkit (Security tab) — changes here are overwritten.\n"
	   . "# Who may reach wp-login.php, wp-admin/upgrade.php and wp-admin/update-core.php.\n"
	   . "geo \$${v}_ip {\n    default 0;\n";
	foreach(wp_sec_server_ips() as $ip) $b .= "    $ip 1; # server\n";
	foreach($ips as $ip) $b .= "    $ip 1; # allowed\n";
	$b .= "}\n"
	   // password-protected posts POST their password to wp-login.php?action=postpass
	   // — that one stays open to every visitor
	   . "map \"\$${v}_ip:\$arg_action\" \$${v}_deny {\n    default 1;\n    \"~^1:\" 0;\n    \"0:postpass\" 0;\n}\n";
	return $b;
}

/* Body of the vhost's first `location ~ \.php$ { ... }`, minus Reqad-managed
   cache directives (they reference a cache zone that may be switched off
   later) and its try_files (re-added). Returns '' when there is none. */
function wp_sec_php_handler($vhost) {
	if(!preg_match('/^([ \t]*)location[ \t]+~[ \t]+\\\\\.php\$[ \t]*\{/m', $vhost, $m, PREG_OFFSET_CAPTURE))
		return '';
	$i = strpos($vhost, '{', $m[0][1]);
	$depth = 0; $end = -1;
	for($j = $i; $j < strlen($vhost); $j++) {
		if($vhost[$j] === '{') $depth++;
		elseif($vhost[$j] === '}' && --$depth === 0) { $end = $j; break; }
	}
	if($end < 0) return '';
	$body = substr($vhost, $i + 1, $end - $i - 1);
	$body = preg_replace('/[ \t]*# BEGIN reqad-nginx-cache-(fcgi|proxy)\b.*?# END reqad-nginx-cache-\1[^\n]*\n?/s', '', $body);
	$out = "    try_files \$uri =404;\n";
	foreach(explode("\n", $body) as $ln) {
		$t = trim($ln);
		if($t === '' || $t[0] === '#' || strpos($t, 'try_files') === 0) continue;
		if(strpos($t, '{') !== false || strpos($t, '}') !== false) return '';   // nested blocks: too custom to copy
		$out .= "    $t\n";
	}
	/* fastcgi_pass, or the proxy to Apache behind nginx (nginx_backend_root()) */
	return (strpos($out, 'fastcgi_pass') !== false || strpos($out, 'reqad-backend.conf') !== false) ? $out : '';
}

/* Pure text step of wp_sec_nginx_apply(): new vhost + both include bodies
   ('' = file should not exist). Returns array(error, vhost, inc, http). */
function wp_sec_nginx_render($orig_v, $domain, $p, $keys, $ips) {
	$new_v = preg_replace('/^[^\n]*# reqad-wp-security(-http|-server)?[ \t]*\n/m', '', $orig_v);
	if(empty($keys))
		return array('', $new_v, '', '', '');

	$ctx = array('var' => wp_sec_var($domain), 'php' => '', 'domain' => $domain);
	$http = '';
	if(in_array('admin_ip_allow', $keys, true)) {
		if(empty($ips))
			return array('Add at least one allowed IP before restricting the login page.', '', '', '', '');
		$ctx['php'] = wp_sec_php_handler($orig_v);
		if($ctx['php'] === '')
			return array('Could not copy the PHP handler from the vhost (customised `location ~ \.php$`), so the login page cannot be IP-restricted here.', '', '', '', '');
		$http = wp_sec_http_body($domain, $ips);
	}

	$inc = "# Managed by the Reqad WP Toolkit (Security tab) — changes here are overwritten.\n"
	     . "# Included inside the vhost's `location /`, ahead of the PHP handler.\n";
	foreach(array_keys(wp_security_options()) as $k)
		if(in_array($k, $keys, true) && ($rule = wp_sec_nginx_rules($k, $p, $ctx)) !== '')
			$inc .= "# BEGIN reqad-sec $k\n$rule\n# END reqad-sec $k\n";

	$out = array(); $done = false;
	foreach(explode("\n", $new_v) as $ln) {
		$out[] = $ln;
		/* nginx_php-fpm: after try_files; Apache behind nginx: after the proxy include */
		if(!$done && preg_match('#^\s*(try_files\s+\$uri\s+\$uri/\s+/index\.php|include\s+/etc/nginx/reqad-backend\.conf;)#', $ln)) {
			$out[] = wp_sec_include_line($domain);
			$done = true;
		}
	}
	if(!$done)
		return array('Could not find the `try_files ... /index.php` line (or the Apache include) in the vhost to attach the security rules.', '', '', '', '');
	$new_v = implode("\n", $out);

	// security headers, author scans, crawlers: server level, after the HTTPS
	// server's `index` line (the port-80 server only redirects and has none)
	$srv = '';
	if(array_intersect(wp_sec_server_keys(), $keys)) {
		$out = array(); $done = false;
		foreach(explode("\n", $new_v) as $ln) {
			$out[] = $ln;
			if(!$done && preg_match('/^\s*index\s+/', $ln)) {
				$out[] = wp_sec_server_line($domain);
				$done = true;
			}
		}
		if(!$done)
			return array('Could not find the `index` line in the vhost to attach the security headers.', '', '', '', '');
		$new_v = implode("\n", $out);
		$srv = wp_sec_server_body($keys);
	}

	if($http !== '')
		$new_v = wp_sec_http_line($domain)."\n".$new_v;   // conf.d files are http context
	return array('', $new_v, $inc, $http, $srv);
}

/* Rewrite the site's includes to exactly $keys (wiring/unwiring the vhost),
   test, reload — or put all three files back if nginx rejects the result. */
function wp_sec_nginx_apply($user, $domain, $path, $keys, $ips = array()) {
	$p = wp_sec_domain_prefix($domain, $path);
	if($p === null)
		return array('error' => 'Unsupported install path: '.$path, 'log' => '');
	$vhost = wp_nginx_conf_path($domain);
	$inc   = wp_sec_inc_path($domain);
	$http  = wp_sec_http_path($domain);
	$orig_v = wp_read_conf($vhost);
	$orig_i = wp_read_conf($inc);
	$orig_h = wp_read_conf($http);
	$srvf   = wp_sec_server_path($domain);
	$orig_s = wp_read_conf($srvf);
	if(trim($orig_v) === '')
		return array('error' => 'nginx vhost not found: '.$vhost, 'log' => '');

	list($err, $new_v, $new_i, $new_h, $new_s) = wp_sec_nginx_render($orig_v, $domain, $p, $keys, $ips);
	if($err !== '')
		return array('error' => $err, 'log' => '');
	// nothing to change: skip the nginx test and reload
	if($new_v === $orig_v && $new_i === $orig_i && $new_h === $orig_h && $new_s === $orig_s)
		return array('error' => '', 'log' => "nginx security rules already up to date\n", 'changed' => false);

	shell_exec('sudo mkdir -p '.WP_SEC_INC_DIR);
	if($new_i !== '') wp_write_conf($inc, $new_i);
	if($new_h !== '') wp_write_conf($http, $new_h);
	if($new_s !== '') wp_write_conf($srvf, $new_s);
	if($new_v !== $orig_v) {
		save_config_backup($user, 'nginx', $vhost, $orig_v);
		wp_write_conf($vhost, $new_v);
	}
	$test = (string)shell_exec('sudo nginx -t 2>&1');
	if(stripos($test, 'test is successful') === false) {
		if($new_v !== $orig_v) wp_write_conf($vhost, $orig_v);
		wp_sec_restore_inc($inc, $orig_i);
		wp_sec_restore_inc($http, $orig_h);
		wp_sec_restore_inc($srvf, $orig_s);
		return array('error' => 'nginx config test failed; nothing was changed. '.trim(preg_replace('/\s+/', ' ', $test)), 'log' => $test);
	}
	if($new_i === '') shell_exec('sudo rm -f '.escapeshellarg($inc));
	if($new_h === '') shell_exec('sudo rm -f '.escapeshellarg($http));
	if($new_s === '') shell_exec('sudo rm -f '.escapeshellarg($srvf));
	shell_exec('sudo systemctl reload nginx 2>&1');
	return array('error' => '', 'changed' => true, 'log' => "nginx security rules: ".(empty($keys) ? 'none' : implode(', ', $keys))
		.(in_array('admin_ip_allow', $keys, true) ? ' (allowed IPs: '.implode(', ', $ips).')' : '')."\n");
}

function wp_sec_restore_inc($inc, $content) {
	if(trim($content) === '') shell_exec('sudo rm -f '.escapeshellarg($inc));
	else wp_write_conf($inc, $content);
}

/* State of every Security option: 'on' | 'off' | 'na' (core's wordpress_tools_status()). */
function wp_security_status($ini, $row, $probe) {
	$st = wordpress_tools_status($row, $probe);
	return $st['data']['security'];
}

/* Validated custom login slug, or '' when unusable. */
function wp_login_slug_valid($slug) {
	$slug = strtolower(trim((string)$slug));
	if(!preg_match('/^[a-z0-9][a-z0-9-]{3,39}$/', $slug)) return '';
	if(in_array($slug, array('wp-admin', 'wp-login', 'admin', 'login', 'dashboard', 'wp-content', 'wp-includes', 'wp-json', 'feed'), true)) return '';
	return $slug;
}

/* Apply the Security tab (core's wordpress_security_apply()). Returns array(error => [..], log => string). */
function wp_security_apply($ini, $row, $want, $login_slug, $regen_keys, $allow_ips = array()) {
	$r = wordpress_security_apply($row, $want, array('login_slug' => $login_slug, 'regen_keys' => $regen_keys, 'allow_ips' => $allow_ips));
	return array('error' => $r['ok'] ? array() : array($r['error']), 'log' => (string)($r['data']['log'] ?? ''));
}

/* ── Email filters (3-tier Sieve) ─────────────────────────────────────────────
 *
 * Global and per-domain rules are rendered from the email_filters table into
 * /var/lib/reqad/sieve/ and compiled. The per-account tier is NOT in the
 * database: its source of truth is the Sieve script itself, because Roundcube
 * and any IMAP client can rewrite it over ManageSieve and a DB copy would
 * silently clobber them.
 *
 * Every privileged operation goes through scripts/email-filters/ef-helper.sh,
 * which re-validates its arguments. Script source is passed on stdin, never as
 * an argument.
 *
 * IMPORTANT — measured Pigeonhole behaviour, see the plan:
 *   Tiers run global -> domain -> personal, but the chain advances only while
 *   the implicit keep is pending. An admin-tier fileinto/redirect/discard ends
 *   the sequence, so the user's own filters never run. Appending `keep;` keeps
 *   the chain alive (at the cost of a second copy). sieve_render_rules() takes
 *   that decision from the rule's `stop` column via $is_admin_tier.
 */

if (!defined('EF_PIPE_BIN_DIR'))
	define('EF_PIPE_BIN_DIR', '/var/lib/reqad/sieve/bin');
if (!defined('EF_HELPER'))
	define('EF_HELPER', '/usr/local/reqad/scripts/email-filters/ef-helper.sh');

/* Run an ef-helper verb. $stdin is written to a temp file and piped in, so
   script source never has to survive shell quoting. Returns raw stdout+stderr;
   $rc receives the exit status. */
function ef_helper($args, $stdin = null, &$rc = null) {
	$cmd = 'sudo -n ' . EF_HELPER;
	foreach ((array)$args as $a)
		$cmd .= ' ' . escapeshellarg($a);

	$tmp = null;
	if ($stdin !== null) {
		$tmp = tempnam(sys_get_temp_dir(), 'ef_');
		file_put_contents($tmp, $stdin);
		$cmd .= ' < ' . escapeshellarg($tmp);
	}
	$cmd .= ' 2>&1';

	$out = array();
	$rc  = 0;
	exec($cmd, $out, $rc);
	if ($tmp !== null)
		@unlink($tmp);
	return implode("\n", $out);
}

/* Quote a string as a Sieve literal. */
function sieve_quote($s) {
	return '"' . str_replace(array('\\', '"'), array('\\\\', '\\"'), (string)$s) . '"';
}

/* Map a rule condition onto a Sieve test.
   $c = array('field' => ..., 'op' => ..., 'value' => ...) */
function sieve_render_test($c, &$requires) {
	$field = isset($c['field']) ? (string)$c['field'] : '';
	$op    = isset($c['op'])    ? (string)$c['op']    : 'contains';
	$value = isset($c['value']) ? (string)$c['value'] : '';

	$negate = false;
	if (strpos($op, 'not-') === 0) {
		$negate = true;
		$op = substr($op, 4);
	}

	/* begins/ends are implemented with :matches, so a literal * or ? in the
	   user's text would silently become a wildcard. Escape them at the pattern
	   level; sieve_quote() then handles string-literal escaping on top. */
	$lit = str_replace(array('\\', '*', '?'), array('\\\\', '\\*', '\\?'), $value);

	switch ($op) {
		case 'is':       $match = ':is';       break;
		case 'begins':   $match = ':matches';  $value = $lit . '*'; break;
		case 'ends':     $match = ':matches';  $value = '*' . $lit; break;
		case 'matches':  $match = ':matches';  break;   /* wildcards intended */
		case 'regex':    $match = ':regex';    $requires['regex'] = true; break;
		default:         $match = ':contains'; break;
	}

	if ($field === 'body') {
		$requires['body'] = true;
		$test = 'body :text ' . $match . ' ' . sieve_quote($value);
	} else if ($field === 'size') {
		/* value is a byte count; op 'over'/'under' arrive as the raw op */
		$n = (int)$value;
		$test = 'size ' . (($op === 'under') ? ':under ' : ':over ') . $n;
	} else if ($field === 'to,cc') {
		/* cPanel's "Any Recipient". Sieve takes a header list, which is one test
		   rather than an anyof of two, so it round-trips as a single condition. */
		$test = 'header ' . $match . ' ["To", "Cc"] ' . sieve_quote($value);
	} else if ($field === 'to' || $field === 'from') {
		/* envelope is more reliable than the header for these, but the header is
		   what users mean when they read a message, so keep the header test. */
		$test = 'header ' . $match . ' ' . sieve_quote($field) . ' ' . sieve_quote($value);
	} else {
		$test = 'header ' . $match . ' ' . sieve_quote($field) . ' ' . sieve_quote($value);
	}

	return $negate ? 'not ' . $test : $test;
}

/* Map one action onto Sieve. Appends to $lines. */
function sieve_render_action($a, &$lines, &$requires, &$cancels_keep) {
	$type  = isset($a['type'])  ? (string)$a['type']  : '';
	$value = isset($a['value']) ? (string)$a['value'] : '';

	switch ($type) {
		case 'fileinto':
			$requires['fileinto'] = true;
			$requires['mailbox']  = true;
			$lines[] = "\tfileinto :create " . sieve_quote($value) . ';';
			$cancels_keep = true;
			break;
		case 'discard':
			$lines[] = "\tdiscard;";
			$cancels_keep = true;
			break;
		case 'redirect':
			$lines[] = "\tredirect " . sieve_quote($value) . ';';
			$cancels_keep = true;
			break;
		case 'reject':
			$requires['reject'] = true;
			$lines[] = "\treject " . sieve_quote($value) . ';';
			$cancels_keep = true;
			break;
		case 'pipe':
			/* vnd.dovecot.pipe (sieve_extprograms). The argument is a FILENAME in
			   sieve_pipe_bin_dir, never a path — the plugin refuses anything else,
			   which is what keeps this from being "run any command as the mail
			   user". Like a delivery action it cancels the implicit keep. */
			$requires['vnd.dovecot.pipe'] = true;
			$lines[] = "\tpipe " . sieve_quote($value) . ';';
			$cancels_keep = true;
			break;
		case 'keep':
			$lines[] = "\tkeep;";
			break;
		case 'addflag':
			$requires['imap4flags'] = true;
			$lines[] = "\taddflag " . sieve_quote($value) . ';';
			break;
		case 'setflag':
			$requires['imap4flags'] = true;
			$lines[] = "\tsetflag " . sieve_quote($value) . ';';
			break;
		case 'removeflag':
			$requires['imap4flags'] = true;
			$lines[] = "\tremoveflag " . sieve_quote($value) . ';';
			break;
		case 'seen':
			$requires['imap4flags'] = true;
			$lines[] = "\taddflag \"\\\\Seen\";";
			break;
		case 'flagged':
			$requires['imap4flags'] = true;
			$lines[] = "\taddflag \"\\\\Flagged\";";
			break;
	}
}

/* Render a set of rule rows into Sieve source.
 *
 * $rules  rows from email_filters (or the same shape for the account tier):
 *         name, enabled, match_type, conditions (JSON), actions (JSON), stop
 * $is_admin_tier  true for global/domain. On those tiers a rule that cancels
 *         the implicit keep would end the whole chain and silently disable the
 *         user's own filters, so unless the rule is explicitly marked terminal
 *         ($row['stop']) we append `keep;` to keep the chain alive.
 *
 * Rule headers are emitted as `# rule:[name]` so Roundcube's managesieve parser
 * shows them as structured rules rather than falling back to raw source.
 */
function sieve_render_rules($rules, $is_admin_tier = false) {
	$requires = array();
	$body     = array();

	foreach ($rules as $r) {
		$off = (isset($r['enabled']) && !$r['enabled']);
		/* On the admin tiers the row simply is not rendered — the DB is the truth
		   and remembers it. An account rule lives ONLY in the script, so being
		   left out would delete it; Roundcube's convention is to keep the rule
		   and neuter the test with `if false # <tests>`, which is what we emit
		   (and parse) so a rule disabled in either UI survives a save in the
		   other. */
		if ($off && $is_admin_tier)
			continue;
		$disabled_prefix = $off ? 'false # ' : '';
		/* the # comments out the rest of the line, so the brace of a disabled
		   rule goes on the next line (as Roundcube writes it) */
		$open = $off ? "\n{" : ' {';

		$conds = json_decode(isset($r['conditions']) ? $r['conditions'] : '[]', true);
		$acts  = json_decode(isset($r['actions'])    ? $r['actions']    : '[]', true);
		if (!is_array($conds)) $conds = array();
		if (!is_array($acts))  $acts  = array();
		/* A rule with no action is a no-op — EXCEPT on the account tier, where a
		   bare `stop` is itself the action: it ends the user's script early.
		   cPanel writes that as `if <test> then finish endif` and it was being
		   dropped silently, which changes what every rule after it does. On the
		   admin tiers `stop` is a no-op (it cannot cross into the next script —
		   measured), so there a rule with no action really is nothing. */
		if (!$acts && ($is_admin_tier || empty($r['stop'])))
			continue;

		$tests = array();
		foreach ($conds as $c)
			$tests[] = sieve_render_test($c, $requires);

		$lines = array();
		$cancels_keep = false;
		foreach ($acts as $a)
			sieve_render_action($a, $lines, $requires, $cancels_keep);
		if (!$lines && ($is_admin_tier || empty($r['stop'])))
			continue;

		$terminal = !empty($r['stop']);
		/* Two actions must never be followed by `keep;`:
		     discard — in Sieve the keep reinstates delivery, so "discard; keep;"
		               delivers the message and the rule does the exact opposite
		               of what it says;
		     reject  — RFC 5228 makes reject incompatible with keep/fileinto, and
		               Pigeonhole raises a runtime conflict, so the message is not
		               refused at all.
		   Both are genuinely terminal — the message is refused or dropped — so
		   there is no later tier left to keep alive. This surfaced converting
		   cPanel's "Fail with message" rules, which are all rejects. */
		$refuses = false;
		foreach ($acts as $a)
			if (isset($a['type']) && ($a['type'] === 'discard' || $a['type'] === 'reject'))
				$refuses = true;
		if ($is_admin_tier && $cancels_keep && !$terminal && !$refuses)
			$lines[] = "\tkeep;";         /* let the remaining tiers run */
		if (!$is_admin_tier && $terminal)
			$lines[] = "\tstop;";         /* within the user's own script only */

		$body[] = '# rule:[' . str_replace(array("\r", "\n", ']'), '', (string)$r['name']) . ']';

		if (!$tests) {
			$body[] = 'if ' . $disabled_prefix . 'true' . $open;
		} else if (count($tests) === 1) {
			$body[] = 'if ' . $disabled_prefix . $tests[0] . $open;
		} else {
			$join = (isset($r['match_type']) && $r['match_type'] === 'any') ? 'anyof' : 'allof';
			$body[] = 'if ' . $disabled_prefix . $join . ' (' . implode(', ', $tests) . ')' . $open;
		}
		foreach ($lines as $l)
			$body[] = $l;
		$body[] = '}';
	}

	if (!$body)
		return '';

	$head = array('# Generated by Reqad — do not edit by hand, changes are overwritten.');
	if ($requires) {
		$names = array_keys($requires);
		sort($names);
		$head[] = 'require [' . implode(', ', array_map('sieve_quote', $names)) . '];';
	}
	return implode("\n", $head) . "\n" . implode("\n", $body) . "\n";
}

/* Read the rows for one tier and write+compile its script. $scope is
   'global' or 'domain'; $target is '' or the domain name.
   Returns '' on success, or the compiler/helper error text. */
function sieve_write_system($db, $scope, $target = '') {
	$stmt = $db->prepare('SELECT name, enabled, match_type, conditions, actions, stop
	                        FROM email_filters
	                       WHERE scope = :s AND target = :t
	                    ORDER BY priority ASC, id ASC');
	$stmt->bindValue(':s', $scope,  SQLITE3_TEXT);
	$stmt->bindValue(':t', $target, SQLITE3_TEXT);
	$res = $stmt->execute();

	$rules = array();
	while ($row = $res->fetchArray(SQLITE3_ASSOC))
		$rules[] = $row;

	$args = ($scope === 'global') ? array('global') : array('domain', $target);
	$src  = sieve_render_rules($rules, true);

	$rc = 0;
	if ($src === '') {
		/* No enabled rules — remove the script rather than compile an empty one.
		   A missing script is a no-op for Pigeonhole. */
		array_unshift($args, 'system-delete');
		$out = ef_helper($args, null, $rc);
	} else {
		array_unshift($args, 'system-put');
		$out = ef_helper($args, $src, $rc);
	}
	return ($rc === 0) ? '' : $out;
}

/* Account tier — the script itself is the truth. */

function sieve_user_get($email) {
	$rc = 0;
	$out = ef_helper(array('user-get', strtolower($email)), null, $rc);
	return ($rc === 0) ? $out : '';
}

/* The same, read from the mailbox's home without doveadm (ef-helper
   user-read); falls back to sieve_user_get() when the userdb has no home. */
function sieve_user_read($email) {
	$rc = 0;
	$out = ef_helper(array('user-read', strtolower($email)), null, $rc);
	if ($rc === 3)
		return sieve_user_get($email);
	return ($rc === 0) ? $out : '';
}

/* The mailbox's IMAP folders, for the "File into folder" picker. Sorted with
 * INBOX first and the rest alphabetically, which is how a mail client shows
 * them. An empty list (mailbox never delivered to, dovecot down) is not an
 * error — the caller falls back to a free-text folder field.
 */
function sieve_user_folders($email) {
	$rc  = 0;
	$out = ef_helper(array('user-folders', strtolower($email)), null, $rc);
	if ($rc !== 0)
		return array();
	$list = array();
	foreach (explode("\n", (string)$out) as $line) {
		$line = trim($line);
		if ($line !== '')
			$list[$line] = true;
	}
	$list = array_keys($list);
	sort($list, SORT_NATURAL | SORT_FLAG_CASE);
	if (($i = array_search('INBOX', $list, true)) !== false) {
		unset($list[$i]);
		array_unshift($list, 'INBOX');
	}
	return array_values($list);
}

/* Every mailbox's personal script in one privileged call, as email => source.
 *
 * The per-mailbox sieve_user_get() costs two doveadm invocations (~60ms), which
 * is fine for one mailbox and far too slow for the "all mailboxes" view — 17
 * mailboxes took about a second, nearly all of the page load. The helper reads
 * the scripts straight out of the mailbox homes instead, so the whole inventory
 * is one call. Mailboxes with no script are absent from the result.
 *
 * The helper's stream is length-prefixed ("<email> <bytes>\n<script>") because a
 * Sieve script can contain any line at all, so no marker line would be safe.
 */
function sieve_user_get_all() {
	$out = (string)shell_exec('sudo -n ' . EF_HELPER . ' user-get-all 2>/dev/null');
	$res = array();
	$p   = 0;
	$len = strlen($out);
	while ($p < $len) {
		$nl = strpos($out, "\n", $p);
		if ($nl === false)
			break;
		$hdr = explode(' ', substr($out, $p, $nl - $p));
		if (count($hdr) !== 2 || !ctype_digit($hdr[1]))
			break;                       /* stream is not what we expect — stop, do not guess */
		$sz  = (int)$hdr[1];
		/* rtrim to match sieve_user_get(), which drops trailing blank lines. */
		$res[$hdr[0]] = rtrim(substr($out, $nl + 1, $sz), "\n");
		$p = $nl + 1 + $sz;
	}
	return $res;
}

/* Returns '' on success, else the error text. */
function sieve_user_put($email, $source) {
	$rc = 0;
	$out = ef_helper(array('user-put', strtolower($email)), $source, $rc);
	return ($rc === 0) ? '' : $out;
}

function sieve_user_delete($email) {
	$rc = 0;
	$out = ef_helper(array('user-delete', strtolower($email)), null, $rc);
	return ($rc === 0) ? '' : $out;
}

/* ── Autoresponders (Pigeonhole vacation) ─────────────────────────────────────
 *
 * One vacation script per mailbox, rendered into
 * /var/lib/reqad/sieve/autoresponders/<email>.sieve and evaluated as the LAST
 * "before" tier — after global and domain. Order matters: measured with
 * `sieve-test -s`, an admin rule that discards a message (spam) ends the whole
 * sequence, so putting the autoresponder last is what stops it from cheerfully
 * auto-replying to spam. Put it first and the reply goes out before the discard.
 *
 * Why not Exim's own Sieve engine (the old backend): it implements only a
 * subset and has no `variables` extension, so `${subject}` cannot be
 * interpolated — the script dies at load with "Sieve error: unknown capability".
 * Pigeonhole has it, which is what makes the %subject% placeholder possible.
 *
 * The legacy backend wrote /etc/exim/autoreply/<domain>/<local part>, read by
 * the virtual_autoreply router in exim.conf. It is still used on servers that
 * have not been switched over to dovecot LMTP delivery — see
 * autoresponder_backend(). That router needs no change either way: it is
 * require_files-gated on the very file we stop writing, so it simply stops
 * matching once a mailbox has been migrated.
 */

/* 'sieve' once setup_autoresponder_sieve.sh has declared the tier in dovecot,
   otherwise 'exim'. Cached — this is called several times per request. */
function autoresponder_backend() {
	static $backend = null;
	if ($backend !== null)
		return $backend;

	/* The config is 0640 dovecot:dovecot, so the panel cannot stat it directly. */
	$out = array();
	$rc  = 0;
	exec('sudo -n grep -qs "^sieve_script autoresponder" /etc/dovecot/conf.d/90-sieve.conf 2>/dev/null', $out, $rc);
	$declared = ($rc === 0);

	/* The tier being DECLARED is not enough — Pigeonhole only runs if exim hands
	   local mail to dovecot. migrate_dovecot_2.4.sh writes 90-sieve.conf (all
	   four tiers) but does NOT flip the transport; that is setup_dovecot_sieve.sh.
	   A server between those two steps still delivers with exim appendfile, which
	   bypasses sieve entirely, so reporting 'sieve' there would render vacation
	   scripts nothing executes and stop writing the exim autoreply files that DO
	   work — auto-replies would silently stop. Require both. */
	$out = array();
	$rc  = 0;
	exec('sudo -n grep -qs "transport = dovecot_lmtp" /etc/exim/exim.conf 2>/dev/null', $out, $rc);
	$delivers_via_lmtp = ($rc === 0);

	$backend = ($declared && $delivers_via_lmtp) ? 'sieve' : 'exim';
	return $backend;
}

/* Placeholders a user may type into the subject or the body. Kept as one list
   so the UI hint and the renderer cannot drift apart. */
function autoresponder_placeholders() {
	return array(
		'%subject%' => 'the subject line of the message being replied to',
	);
}

/* Render one mailbox's vacation script.
 *
 * Two string forms are in play and they escape differently (RFC 5228 §8.1):
 * a quoted literal processes \ and ", a multi-line text: block processes
 * neither and only needs dot-stuffing. Both interpolate ${...} once the
 * variables extension is required — which is why it is required ONLY when a
 * placeholder is actually present. Without it a literal "${x}" typed by the
 * user stays literal, exactly as it did under the old backend.
 */
function sieve_render_autoresponder($user, $domain, $subject, $message) {
	$message = str_replace("\r\n", "\n", (string)$message);
	$subject = str_replace(array("\r", "\n"), '', (string)$subject);   // header: one line

	$want_vars = (stripos($subject, '%subject%') !== false)
	          || (stripos($message, '%subject%') !== false);

	$subj = str_replace(array('\\', '"'), array('\\\\', '\\"'), $subject);

	$src  = "# Generated by Reqad — do not edit by hand, changes are overwritten.\n";
	$src .= '# Autoresponder for ' . $user . '@' . $domain . "\n";
	$src .= 'require [' . ($want_vars ? '"variables", "vacation"' : '"vacation"') . "];\n";

	if ($want_vars) {
		/* :matches "*" captures the whole header into ${1}. Pigeonhole hands it
		   over already RFC 2047-decoded and re-encodes the outgoing subject, so
		   an accented original survives the round trip. A message with no
		   Subject header simply leaves ${subject} unset, i.e. empty. */
		$src .= "if header :matches \"Subject\" \"*\" {\n\tset \"subject\" \"\${1}\";\n}\n";
		$subj    = str_ireplace('%subject%', '${subject}', $subj);
		$message = str_ireplace('%subject%', '${subject}', $message);
	}

	/* An explicit :handle pins the dedup key. Without it the handle is derived
	   from the reason text, so editing the message would re-notify everyone who
	   had already been answered. */
	$src .= 'vacation :days 1 :handle "reqad-autoresponder" :subject "' . $subj . "\" text:\n";
	foreach (explode("\n", $message) as $line)
		$src .= ((substr($line, 0, 1) === '.') ? '.' . $line : $line) . "\n";
	$src .= ".\n;\n";

	return $src;
}

/* Where the live artefact for one mailbox lives, per backend. */
function autoresponder_sieve_path($user, $domain) {
	return '/var/lib/reqad/sieve/autoresponders/' . strtolower($user . '@' . $domain) . '.sieve';
}

function autoresponder_exim_path($user, $domain) {
	return '/etc/exim/autoreply/' . $domain . '/' . $user;
}

/* Is an autoresponder currently live for this mailbox? */
function autoresponder_is_active($user, $domain) {
	if (autoresponder_backend() === 'sieve')
		return is_file(autoresponder_sieve_path($user, $domain));
	return is_file(autoresponder_exim_path($user, $domain));
}

/* Install or refresh the live autoresponder. Returns '' on success, else the
   error text (a Sieve compile error is reported verbatim). */
function autoresponder_apply($user, $domain, $subject, $message) {
	if (autoresponder_backend() !== 'sieve')
		return autoresponder_exim_apply($user, $domain, $subject, $message);

	$src = sieve_render_autoresponder($user, $domain, $subject, $message);
	$rc  = 0;
	$out = ef_helper(array('system-put', 'autoresponder', strtolower($user . '@' . $domain)), $src, $rc);
	if ($rc !== 0)
		return $out;

	/* Migration: a mailbox created under the old backend still has an exim
	   filter file, and the virtual_autoreply router would answer from there as
	   well. Drop it the first time the mailbox is written as Sieve. */
	autoresponder_exim_remove($user, $domain);
	return '';
}

/* Remove the live autoresponder. Returns '' on success, else the error text. */
function autoresponder_remove($user, $domain) {
	autoresponder_exim_remove($user, $domain);        /* always, for migration */

	if (autoresponder_backend() !== 'sieve')
		return '';

	$rc  = 0;
	$out = ef_helper(array('system-delete', 'autoresponder', strtolower($user . '@' . $domain)), null, $rc);
	return ($rc === 0) ? '' : $out;
}

/* ── legacy exim backend ─────────────────────────────────────────────────────
   Servers still delivering with exim appendfile instead of dovecot LMTP. No
   %subject% support here — Exim's Sieve has no variables extension. */

function autoresponder_exim_apply($user, $domain, $subject, $message) {
	$msg_dir  = '/etc/exim/autoreply/' . $domain;
	$msg_file = autoresponder_exim_path($user, $domain);
	$tmp_file = tempnam(sys_get_temp_dir(), 'ar_');

	/* File named after the local part with no extension so dsearch can detaint
	   it. Dedup is handled by exim via sieve_vacation_directory. */
	$subj = str_replace(array("\r", "\n"), '', (string)$subject);
	$subj = str_replace(array('\\', '"'), array('\\\\', '\\"'), $subj);

	$content  = "# Sieve filter\n";
	$content .= "require [\"vacation\"];\n";
	$content .= 'vacation :days 1 :subject "' . $subj . "\" text:\n";
	foreach (explode("\n", str_replace("\r\n", "\n", (string)$message)) as $line)
		$content .= ((substr($line, 0, 1) === '.') ? '.' . $line : $line) . "\n";
	$content .= ".\n;\ndiscard;\n";

	file_put_contents($tmp_file, $content);
	shell_exec('sudo mkdir -p ' . escapeshellarg($msg_dir));
	shell_exec('sudo chown exim:mail ' . escapeshellarg($msg_dir));
	shell_exec('sudo chmod 750 ' . escapeshellarg($msg_dir));
	shell_exec('sudo mv ' . escapeshellarg($tmp_file) . ' ' . escapeshellarg($msg_file));
	shell_exec('sudo chown exim:mail ' . escapeshellarg($msg_file));
	shell_exec('sudo chmod 640 ' . escapeshellarg($msg_file));
	return '';
}

function autoresponder_exim_remove($user, $domain) {
	$msg_file = autoresponder_exim_path($user, $domain);
	shell_exec('sudo rm -f ' . escapeshellarg($msg_file));
	return '';
}

/* Every real mailbox on the server, as "user@domain", lowercased and sorted.
 *
 * /etc/dovecot/users is the authority. The `emails` table is NOT: nothing
 * maintains it on create (create_email.php writes the exim/dovecot files and
 * never inserts a row), it is only rebuilt as a disk-usage cache when someone
 * happens to open the Email Accounts page, and it accumulates rows for
 * mailboxes that no longer exist. Offering those in a picker lets an admin
 * configure mail features for an address exim will never deliver to. */
function mailbox_list($fresh = false) {
	static $list = null;
	if ($list !== null && !$fresh)
		return $list;

	$out  = shell_exec('sudo cat /etc/dovecot/users 2>/dev/null');
	$list = array();
	foreach (explode("\n", (string)$out) as $line) {
		$addr = strtolower(trim(substr($line, 0, strpos($line . ':', ':'))));
		if ($addr !== '' && strpos($addr, '@') !== false)
			$list[$addr] = true;
	}
	$list = array_keys($list);
	sort($list);
	return $list;
}

/* Does this mailbox actually exist? */
function mailbox_exists($user, $domain) {
	return in_array(strtolower($user . '@' . $domain), mailbox_list(), true);
}

/* ---- Apple Mail configuration profiles (.mobileconfig) -------------------
   Apple reads neither the Mozilla autoconfig XML nor Autodiscover for IMAP
   accounts, so the only deterministic way to hand an iPhone, iPad or Mac a
   working account is a configuration profile. */

/* The hostname to advertise for IMAP/SMTP.

   Deliberately read out of the file scripts/update_autoconfig already wrote
   rather than re-deriving it: that script picks mail.<domain> / <domain> /
   the system hostname by looking at what the domain's certificate actually
   covers, and having two copies of that rule is how they drift apart. If the
   file is not there (email off, or the generator has never run) fall back to
   the system hostname, which always has a certificate. */
function mail_client_hostname($domain) {
	$domain = strtolower(trim((string)$domain));
	$file   = '/var/lib/reqad/autoconfig/'.$domain.'.xml';
	if(valid_domain($domain) && is_file($file)) {
		$xml = (string)@file_get_contents($file);
		if(preg_match('#<hostname>([^<]+)</hostname>#', $xml, $m)) {
			$h = strtolower(trim($m[1]));
			if(valid_domain($h)) return $h;
		}
	}
	$hostname = strtolower(trim((string)shell_exec('hostname')));
	return valid_domain($hostname) ? $hostname : $domain;
}

/* A stable UUID for a given string. Stable matters: reinstalling the profile
   then REPLACES the previous one on the device instead of adding a second
   account alongside it. */
function mobileconfig_uuid($seed) {
	$h = md5('reqad-mobileconfig|'.$seed);
	return strtoupper(substr($h, 0, 8).'-'.substr($h, 8, 4).'-'.substr($h, 12, 4).'-'
		.substr($h, 16, 4).'-'.substr($h, 20, 12));
}

/* Build the profile for one mailbox.

   No password is included, on purpose. The device prompts for it on install,
   so the file carries nothing secret and can be mailed, AirDropped or left in
   Downloads without handing over the mailbox. */
function mobileconfig_build($email) {
	$email = strtolower(trim((string)$email));
	$at    = strrpos($email, '@');
	if($at === false) return '';
	$domain = substr($email, $at + 1);
	if(!valid_domain($domain)) return '';

	$host = mail_client_hostname($domain);
	$e    = function($v) { return htmlspecialchars((string)$v, ENT_XML1, 'UTF-8'); };

	$acc_uuid = mobileconfig_uuid('account|'.$email);
	$top_uuid = mobileconfig_uuid('profile|'.$email);
	$acc_id   = 'com.reqad.mail.'.$domain.'.'.substr($email, 0, $at);
	$top_id   = 'com.reqad.profile.'.$domain.'.'.substr($email, 0, $at);

	$x  = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
	$x .= '<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">'."\n";
	$x .= '<plist version="1.0">'."\n";
	$x .= '<dict>'."\n";
	$x .= '  <key>PayloadContent</key>'."\n";
	$x .= '  <array>'."\n";
	$x .= '    <dict>'."\n";
	$x .= '      <key>PayloadType</key><string>com.apple.mail.managed</string>'."\n";
	$x .= '      <key>PayloadVersion</key><integer>1</integer>'."\n";
	$x .= '      <key>PayloadIdentifier</key><string>'.$e($acc_id).'</string>'."\n";
	$x .= '      <key>PayloadUUID</key><string>'.$acc_uuid.'</string>'."\n";
	$x .= '      <key>PayloadDisplayName</key><string>'.$e($email).'</string>'."\n";
	$x .= '      <key>EmailAccountDescription</key><string>'.$e($email).'</string>'."\n";
	$x .= '      <key>EmailAccountName</key><string>'.$e($email).'</string>'."\n";
	$x .= '      <key>EmailAccountType</key><string>EmailTypeIMAP</string>'."\n";
	$x .= '      <key>EmailAddress</key><string>'.$e($email).'</string>'."\n";
	$x .= '      <key>IncomingMailServerHostName</key><string>'.$e($host).'</string>'."\n";
	$x .= '      <key>IncomingMailServerPortNumber</key><integer>993</integer>'."\n";
	$x .= '      <key>IncomingMailServerUseSSL</key><true/>'."\n";
	$x .= '      <key>IncomingMailServerAuthentication</key><string>EmailAuthPassword</string>'."\n";
	$x .= '      <key>IncomingMailServerUsername</key><string>'.$e($email).'</string>'."\n";
	$x .= '      <key>OutgoingMailServerHostName</key><string>'.$e($host).'</string>'."\n";
	$x .= '      <key>OutgoingMailServerPortNumber</key><integer>465</integer>'."\n";
	$x .= '      <key>OutgoingMailServerUseSSL</key><true/>'."\n";
	$x .= '      <key>OutgoingMailServerAuthentication</key><string>EmailAuthPassword</string>'."\n";
	$x .= '      <key>OutgoingMailServerUsername</key><string>'.$e($email).'</string>'."\n";
	/* one password prompt on install, not two */
	$x .= '      <key>OutgoingPasswordSameAsIncomingPassword</key><true/>'."\n";
	$x .= '    </dict>'."\n";
	$x .= '  </array>'."\n";
	$x .= '  <key>PayloadType</key><string>Configuration</string>'."\n";
	$x .= '  <key>PayloadVersion</key><integer>1</integer>'."\n";
	$x .= '  <key>PayloadIdentifier</key><string>'.$e($top_id).'</string>'."\n";
	$x .= '  <key>PayloadUUID</key><string>'.$top_uuid.'</string>'."\n";
	$x .= '  <key>PayloadDisplayName</key><string>'.$e($email).'</string>'."\n";
	$x .= '  <key>PayloadOrganization</key><string>'.$e($domain).'</string>'."\n";
	$x .= '  <key>PayloadDescription</key><string>Mail settings for '.$e($email)
		.'. You will be asked for the mailbox password when the profile is installed.</string>'."\n";
	$x .= '  <key>PayloadRemovalDisallowed</key><false/>'."\n";
	$x .= '</dict>'."\n";
	$x .= '</plist>'."\n";
	return $x;
}

/* Sign the profile with the domain's own certificate.

   An unsigned profile installs under a red "Unverified" banner, which is
   exactly what makes people abandon the install. Signed with the certificate
   this server already holds for the domain, the device shows a green
   "Verified" badge naming it. Returns '' when the domain has no usable
   Let's Encrypt key, and the caller then serves the profile unsigned rather
   than failing. */
function mobileconfig_sign($plist, $domain) {
	if(!valid_domain($domain)) return '';
	$live = '/etc/letsencrypt/live/'.$domain;

	/* the panel runs as reqad and cannot stat under /etc/letsencrypt/archive,
	   where privkey.pem is root-only -- ask via sudo, not file_exists() */
	foreach(array('cert.pem', 'privkey.pem', 'chain.pem') as $f) {
		if(trim((string)shell_exec('sudo test -f '.escapeshellarg($live.'/'.$f).' && echo ok')) !== 'ok')
			return '';
	}

	$in  = _PATH.'/tmp/mc_'.bin2hex(random_bytes(6));
	$out = $in.'.signed';
	file_put_contents($in, $plist);

	shell_exec('sudo openssl smime -sign'
		.' -signer '.escapeshellarg($live.'/cert.pem')
		.' -inkey '.escapeshellarg($live.'/privkey.pem')
		.' -certfile '.escapeshellarg($live.'/chain.pem')
		.' -nodetach -outform der'
		.' -in '.escapeshellarg($in)
		.' -out '.escapeshellarg($out).' 2>/dev/null');
	shell_exec('sudo chown reqad:reqad '.escapeshellarg($out).' 2>/dev/null');

	$signed = is_file($out) ? (string)file_get_contents($out) : '';
	@unlink($in);
	@unlink($out);
	return $signed;
}

/* Create a mailbox: the exim domain entry, the dovecot passdb line and nothing
 * else -- the Maildir is made by the first delivery, exactly as it is when the
 * panel creates one.
 *
 * $password is the PLAINTEXT password; it is SHA-512 crypted here. Pass '' and
 * a random one is generated, which is what the cPanel import does for an
 * address that exists on the old server only as an autoresponder alias: there
 * is no password to carry over, and a mailbox nobody can log into still
 * receives and still auto-replies.
 *
 * Returns '' on success, else the error text. /etc/exim/domains/<domain> and
 * /etc/dovecot/users are the authority for "does this address exist" (see
 * create_email.php) -- the `emails` table is a disk-usage cache and is not
 * touched.
 */
function mailbox_create($user, $domain, $password = '') {
	$user   = trim((string)$user);
	$domain = strtolower(trim((string)$domain));
	$email  = $user . '@' . $domain;

	if (!valid_email_user($user))
		return "Invalid email user '$user'.";
	if (!valid_domain($domain))
		return "Invalid domain '$domain'.";

	$domains = explode("\n", trim((string)shell_exec('sudo ls -1 /etc/exim/domains/ 2>/dev/null')));
	if (!in_array($domain, $domains, true))
		return "Domain $domain not found in /etc/exim/domains/.";

	if (in_array(strtolower($email), mailbox_list(true), true))
		return "Mailbox $email already exists.";

	$sysuser = trim((string)shell_exec('sudo grep ' . escapeshellarg('^' . $domain . ':')
	         . ' /etc/exim/userdomains 2>/dev/null | cut -d: -f2 | tr -d " "'));
	if (!valid_username($sysuser))
		return "No hosting account owns $domain in /etc/exim/userdomains.";

	$uid = (int)trim((string)shell_exec('sudo id -u ' . escapeshellarg($sysuser) . ' 2>/dev/null'));
	$gid = (int)trim((string)shell_exec('sudo id -g ' . escapeshellarg($sysuser) . ' 2>/dev/null'));
	if ($uid <= 0 || $gid <= 0)
		return "System account $sysuser does not exist.";

	if ($password === '')
		$password = mailbox_random_password();
	$hash = crypt($password, '$6$' . substr(str_shuffle(
		'./ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijkl..mnopqrstuvwxyz012345..6789'), 0, 8));

	/* The local part is appended to the exim domain file only if it is not
	   already listed: a forwarder or an autoresponder alias may have put it
	   there, and a duplicate line makes exim's dsearch lookup ambiguous. */
	$locals = explode("\n", trim((string)shell_exec('sudo cat '
	        . escapeshellarg('/etc/exim/domains/' . $domain) . ' 2>/dev/null')));
	if (!in_array($user, array_map('trim', $locals), true))
		shell_exec('echo ' . escapeshellarg($user) . ' | sudo tee -a '
		         . escapeshellarg('/etc/exim/domains/' . $domain) . ' > /dev/null');

	$line = $email . ':' . $hash . ':' . $uid . ':' . $gid . '::/home/' . $sysuser
	      . '/mail/' . $domain . '/' . $user . '::userdb_mail=maildir:~/';
	shell_exec('echo ' . escapeshellarg($line) . ' | sudo tee -a /etc/dovecot/users > /dev/null');

	mailbox_list(true);   /* the static cache now disagrees with the file */

	return in_array(strtolower($email), mailbox_list(true), true)
	     ? '' : "Could not write $email to /etc/dovecot/users.";
}

/* A password nobody is meant to remember: for mailboxes created by an import,
   where the admin resets it if the address is ever used for login. */
function mailbox_random_password($len = 20) {
	$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
	$out = '';
	for ($i = 0; $i < $len; $i++)
		$out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
	return $out;
}

/* ── Sieve parsing (script -> rule model) ─────────────────────────────────────
 *
 * Deliberately narrow: it understands the shape sieve_render_rules() emits,
 * which is also the shape Roundcube's managesieve plugin writes (`# rule:[name]`
 * headers, one `if` block per rule). Anything else returns false and the caller
 * falls back to a raw source editor — the same behaviour Roundcube itself has.
 * Half-understanding someone's filters and silently rewriting them is worse than
 * admitting we cannot model them.
 */

/* Split a Sieve argument list into tokens: quoted strings become their decoded
   value, everything else (tags, numbers, identifiers) comes back verbatim. */
function sieve_tokenize($s) {
	$out = array();
	$n   = strlen($s);
	$i   = 0;
	while ($i < $n) {
		$c = $s[$i];
		if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r" || $c === ','
		    || $c === '[' || $c === ']') {
			$i++;
			continue;
		}
		if ($c === '"') {
			$val = '';
			$i++;
			while ($i < $n && $s[$i] !== '"') {
				if ($s[$i] === '\\' && $i + 1 < $n) {
					$i++;
					$val .= $s[$i];
				} else {
					$val .= $s[$i];
				}
				$i++;
			}
			$i++;                       /* closing quote */
			$out[] = array('str', $val);
			continue;
		}
		$j = $i;
		while ($j < $n && strpos(" \t\r\n,[]", $s[$j]) === false)
			$j++;
		$out[] = array('raw', substr($s, $i, $j - $i));
		$i = $j;
	}
	return $out;
}

/* One test -> array(field, op, value), or false if not a shape we model. */
function sieve_parse_test($t) {
	$t = trim($t);
	$negate = false;
	if (strpos($t, 'not ') === 0) {
		$negate = true;
		$t = trim(substr($t, 4));
	}

	$tok = sieve_tokenize($t);
	if (!$tok)
		return false;
	if ($tok[0][0] !== 'raw')
		return false;
	$cmd = $tok[0][1];

	$match = null; $strs = array(); $tags = array();
	for ($i = 1; $i < count($tok); $i++) {
		if ($tok[$i][0] === 'str')
			$strs[] = $tok[$i][1];
		else if ($tok[$i][1] !== '' && $tok[$i][1][0] === ':')
			$tags[] = $tok[$i][1];
		else
			$tags[] = $tok[$i][1];      /* numbers for size */
	}
	foreach ($tags as $tg)
		if (in_array($tg, array(':is', ':contains', ':matches', ':regex'), true))
			$match = $tg;

	if ($cmd === 'size') {
		$op = in_array(':under', $tags, true) ? 'under' : 'over';
		$num = '';
		foreach ($tags as $tg)
			if (ctype_digit($tg)) $num = $tg;
		if ($num === '')
			return false;
		return array('field' => 'size', 'op' => ($negate ? 'not-' : '') . $op, 'value' => $num);
	}

	if ($cmd === 'body') {
		if ($match === null || count($strs) !== 1)
			return false;
		$op = substr($match, 1);
		return array('field' => 'body', 'op' => ($negate ? 'not-' : '') . $op, 'value' => $strs[0]);
	}

	if ($cmd === 'header') {
		if ($match === null || count($strs) < 2)
			return false;
		/* Last string is the value; anything before it is a header name. More than
		   one name is only modelled for To+Cc ("Any Recipient") — any other list
		   is left to the raw editor rather than silently flattened. */
		$value = array_pop($strs);
		if (count($strs) === 1) {
			$field = $strs[0];
		} else {
			$names = array_map('strtolower', $strs);
			sort($names);
			if ($names !== array('cc', 'to'))
				return false;
			$field = 'to,cc';
		}
		$op    = substr($match, 1);     /* is | contains | matches | regex */

		/* Recover begins/ends, which are rendered as :matches with a wildcard.
		   Only when the rest of the pattern has no unescaped wildcard left. */
		if ($op === 'matches') {
			$lead  = (strlen($value) > 0 && $value[0] === '*');
			$trail = (strlen($value) > 1 && substr($value, -1) === '*' && substr($value, -2) !== '\\*');
			$core  = $value;
			if ($trail && !$lead) {
				$core = substr($value, 0, -1);
				if (!sieve_has_wildcard($core))
					return array('field' => $field, 'op' => ($negate ? 'not-' : '') . 'begins',
					             'value' => sieve_unescape_pattern($core));
			} else if ($lead && !$trail) {
				$core = substr($value, 1);
				if (!sieve_has_wildcard($core))
					return array('field' => $field, 'op' => ($negate ? 'not-' : '') . 'ends',
					             'value' => sieve_unescape_pattern($core));
			}
		}
		return array('field' => $field, 'op' => ($negate ? 'not-' : '') . $op, 'value' => $value);
	}

	return false;
}

/* Does the pattern contain an UNescaped * or ? */
function sieve_has_wildcard($p) {
	$n = strlen($p);
	for ($i = 0; $i < $n; $i++) {
		if ($p[$i] === '\\') { $i++; continue; }
		if ($p[$i] === '*' || $p[$i] === '?') return true;
	}
	return false;
}

/* Undo the pattern-level escaping sieve_render_test() applies to begins/ends. */
function sieve_unescape_pattern($p) {
	$out = '';
	$n = strlen($p);
	for ($i = 0; $i < $n; $i++) {
		if ($p[$i] === '\\' && $i + 1 < $n) { $i++; $out .= $p[$i]; continue; }
		$out .= $p[$i];
	}
	return $out;
}

/* One action line -> array(type, value), or false. */
function sieve_parse_action($line) {
	$line = trim($line);
	if (substr($line, -1) === ';')
		$line = substr($line, 0, -1);
	$line = trim($line);
	if ($line === '')
		return false;

	$tok = sieve_tokenize($line);
	if (!$tok || $tok[0][0] !== 'raw')
		return false;
	$cmd  = $tok[0][1];
	$strs = array();
	foreach ($tok as $t)
		if ($t[0] === 'str') $strs[] = $t[1];

	switch ($cmd) {
		case 'fileinto': return count($strs) === 1 ? array('type'=>'fileinto','value'=>$strs[0]) : false;
		case 'redirect': return count($strs) === 1 ? array('type'=>'redirect','value'=>$strs[0]) : false;
		case 'reject':   return count($strs) === 1 ? array('type'=>'reject','value'=>$strs[0])   : false;
		case 'pipe':     return count($strs) === 1 ? array('type'=>'pipe','value'=>$strs[0])     : false;
		case 'discard':  return array('type'=>'discard','value'=>'');
		case 'keep':     return array('type'=>'keep','value'=>'');
		case 'stop':     return array('type'=>'stop','value'=>'');
		case 'addflag':
			if (count($strs) !== 1) return false;
			if ($strs[0] === '\\Seen')    return array('type'=>'seen','value'=>'');
			if ($strs[0] === '\\Flagged') return array('type'=>'flagged','value'=>'');
			return array('type'=>'addflag','value'=>$strs[0]);
		/* Roundcube writes setflag/removeflag for its "Set flags" rules. setflag
		   REPLACES every flag, so it must not be folded into addflag — the two
		   are kept as distinct actions and re-render as themselves. Multiple
		   flags arrive as one space-separated string, which is how Sieve carries
		   a flag list, so the value survives round-trip untouched. */
		case 'setflag':
			return count($strs) === 1 ? array('type'=>'setflag','value'=>$strs[0]) : false;
		case 'removeflag':
			return count($strs) === 1 ? array('type'=>'removeflag','value'=>$strs[0]) : false;
	}
	return false;
}

/* Sieve source -> array of rule rows, or false if the script is not in our
   shape. An empty script parses to an empty array (not false). */
function sieve_parse_script($src, $is_admin_tier = false) {
	$src = (string)$src;
	if (trim($src) === '')
		return array();

	$lines = preg_split('/\r\n|\r|\n/', $src);
	$rules = array();
	$i     = 0;
	$n     = count($lines);
	$name  = null;

	while ($i < $n) {
		$line = trim($lines[$i]);

		if ($line === '') { $i++; continue; }

		if (strpos($line, '# rule:[') === 0) {
			$close = strrpos($line, ']');
			if ($close === false) return false;
			$name = substr($line, 8, $close - 8);
			$i++;
			continue;
		}
		if ($line[0] === '#') { $i++; continue; }            /* other comments */
		if (strpos($line, 'require') === 0) {                /* require [...]; */
			while ($i < $n && strpos($lines[$i], ';') === false) $i++;
			$i++;
			continue;
		}

		if (strpos($line, 'if ') !== 0)
			return false;                                    /* not our shape */

		/* Roundcube's managesieve puts the opening brace on its own line, and a
		   long condition may wrap, so gather lines until the brace turns up.
		   Quoted text is skipped: a "{" inside a value is not the block opener. */
		$acc   = $line;
		$brace = sieve_find_brace($acc);
		while ($brace === false) {
			$i++;
			if ($i >= $n) return false;
			$acc  .= ' ' . trim($lines[$i]);
			$brace = sieve_find_brace($acc);
		}
		$cond = trim(substr($acc, 3, $brace - 3));

		/* Roundcube disables a rule by neutering the test: `if false # <tests>`.
		   Keep the rule and mark it disabled rather than failing the parse. */
		$rule_enabled = 1;
		if (preg_match('/^false\s+#\s*/i', $cond, $m)) {
			$rule_enabled = 0;
			$cond = trim(substr($cond, strlen($m[0])));
		}

		$match_type = 'all';
		if (strpos($cond, 'allof') === 0 || strpos($cond, 'anyof') === 0) {
			$match_type = (strpos($cond, 'anyof') === 0) ? 'any' : 'all';
			$open  = strpos($cond, '(');
			$close = strrpos($cond, ')');
			if ($open === false || $close === false) return false;
			$inner = substr($cond, $open + 1, $close - $open - 1);
			$parts = sieve_split_tests($inner);
		} else if ($cond === 'true') {
			$parts = array();
		} else {
			$parts = array($cond);
		}

		$conds = array();
		foreach ($parts as $p) {
			$c = sieve_parse_test($p);
			if ($c === false) return false;
			$conds[] = $c;
		}

		/* body of the if block */
		$i++;
		$acts = array();
		$stop = 0;
		$saw_keep = false;
		$cancels_keep = false;
		$depth = 1;
		while ($i < $n) {
			$l = trim($lines[$i]);
			if ($l === '}') { $depth--; $i++; if ($depth === 0) break; continue; }
			if ($l === '') { $i++; continue; }
			if (substr($l, -1) === '{') return false;        /* nested — not our shape */
			$a = sieve_parse_action($l);
			if ($a === false) return false;
			if ($a['type'] === 'stop')      { $stop = 1; }
			else if ($a['type'] === 'keep') { $saw_keep = true; }
			else {
				$acts[] = $a;
				if (in_array($a['type'], array('fileinto','discard','redirect','reject','pipe'), true))
					$cancels_keep = true;
			}
			$i++;
		}
		if ($depth !== 0) return false;

		/* On the admin tiers terminality is encoded by the ABSENCE of the
		   trailing `keep;` that sieve_render_rules() adds to non-terminal rules
		   (there is no `stop;` to read, because `stop` does not cross script
		   boundaries). Recover it so render->parse->render is exact on every
		   tier, not just the account one. */
		if ($is_admin_tier && $cancels_keep && !$saw_keep)
			$stop = 1;

		$rules[] = array(
			'name'       => ($name === null ? 'rule ' . (count($rules) + 1) : $name),
			'enabled'    => $rule_enabled,
			'match_type' => $match_type,
			'conditions' => json_encode($conds),
			'actions'    => json_encode($acts),
			'stop'       => $stop,
		);
		$name = null;
	}
	return $rules;
}

/* Split "a, b, c" at top level only — commas inside quotes or parens stay put. */
/* Index of the first '{' that is not inside a quoted string, or false. */
function sieve_find_brace($s) {
	$n = strlen($s); $inq = false;
	for ($i = 0; $i < $n; $i++) {
		$c = $s[$i];
		if ($inq) {
			if ($c === '\\' && $i + 1 < $n) { $i++; continue; }
			if ($c === '"') $inq = false;
			continue;
		}
		if ($c === '"') { $inq = true; continue; }
		if ($c === '{') return $i;
	}
	return false;
}

function sieve_split_tests($s) {
	$out = array(); $buf = ''; $depth = 0; $inq = false;
	$n = strlen($s);
	for ($i = 0; $i < $n; $i++) {
		$c = $s[$i];
		if ($inq) {
			$buf .= $c;
			if ($c === '\\' && $i + 1 < $n) { $i++; $buf .= $s[$i]; continue; }
			if ($c === '"') $inq = false;
			continue;
		}
		if ($c === '"') { $inq = true; $buf .= $c; continue; }
		if ($c === '(' || $c === '[') { $depth++; $buf .= $c; continue; }
		if ($c === ')' || $c === ']') { $depth--; $buf .= $c; continue; }
		if ($c === ',' && $depth === 0) { $out[] = trim($buf); $buf = ''; continue; }
		$buf .= $c;
	}
	if (trim($buf) !== '') $out[] = trim($buf);
	return $out;
}

/* ── Email filter form handling ───────────────────────────────────────────────
 * Shared by create_email_filter.php / edit_email_filter.php.
 */

/* Header names Sieve can test, plus our two pseudo-fields. */
/* Programs a "pipe to a program" action may name.
 *
 * Pigeonhole's extprograms plugin only accepts a bare filename here and resolves
 * it inside sieve_pipe_bin_dir, so a filter can never name an arbitrary path.
 * The directory is deliberately not writable from the panel: dropping a program
 * in is a root action, and the UI only lets a rule choose among what is there.
 */
function ef_pipe_programs() {
	$dir = EF_PIPE_BIN_DIR;
	$out = array();
	if (!is_dir($dir))
		return $out;
	foreach ((array)scandir($dir) as $f) {
		if ($f === '.' || $f === '..')
			continue;
		if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $f))
			continue;
		if (is_file($dir . '/' . $f) && is_executable($dir . '/' . $f))
			$out[] = $f;
	}
	sort($out);
	return $out;
}

/* ─────────────────────────────────────────────────────────────────────────────
 * cPanel / DirectAdmin filter import — Exim filter language → Reqad rule model
 *
 * cPanel writes every tier of its filtering in Exim filter syntax:
 *   global   /etc/cpanel_exim_system_filter
 *   domain   /etc/vfilters/<domain>
 *   account  /home/<acct>/etc/<domain>/<localpart>/filter
 * so one converter serves all three. DirectAdmin's /etc/system_filter.exim and
 * /etc/virtual/<domain>/filter are the same language.
 *
 * The UI-generated shape is regular and parses cleanly. Hand-written filters are
 * not, and half-translating one would silently change what a user's mail does —
 * so anything unrecognised is returned in 'skipped' WITH ITS RAW TEXT for the
 * admin to read, never guessed at.
 *
 * Returns array('rules' => array(<rule rows>), 'skipped' => array(
 *     array('raw' => <the block>, 'why' => <one line>), ...)).
 */
function exim_filter_parse($src, $ctx = array()) {
	$lines = preg_split('/\r\n|\r|\n/', (string)$src);
	$rules = array();
	$skip  = array();
	$n     = count($lines);
	$i     = 0;
	$name  = null;
	$seq   = 0;

	while ($i < $n) {
		$l = trim($lines[$i]);

		if ($l === '') { $i++; continue; }
		if ($l[0] === '#') {
			/* cPanel labels a rule with a comment above it. Keep the last one as
			   a candidate name; "# Exim filter" and banner rules are not names. */
			$c = trim(ltrim($l, '#'));
			if ($c !== '' && stripos($c, 'exim filter') === false && strspn($c, '#=- ') !== strlen($c))
				$name = $c;
			$i++;
			continue;
		}

		/* cPanel opens every file with `headers charset "UTF-8"`. It is a
		   decoding directive, not a rule, and Sieve does the equivalent itself. */
		if (preg_match('/^headers\s+charset\b/i', $l)) { $i++; continue; }

		/* An unconditional top-level `fail` / `save "/dev/null"` is not a filter
		   at all: it is how cPanel marks a mailbox whose incoming mail has been
		   suspended. Say so, rather than reporting it as an unreadable rule. */
		if (preg_match('/^fail\b/i', $l) || preg_match('/^save\s+"?\/dev\/null"?/i', $l)) {
			$skip[] = array('raw' => $l, 'why' => 'this is "suspend incoming mail" flag for the '
			              . 'mailbox, not a filter — every message is refused unconditionally. Reqad has no '
			              . 'equivalent, so nothing is imported; delete or disable the mailbox if you want '
			              . 'that to continue');
			$i++;
			continue;
		}

		/* Only `if ... then ... endif` blocks are modelled. */
		if (!preg_match('/^if\b/', $l)) {
			$skip[] = array('raw' => $l, 'why' => 'not an "if ... then ... endif" block');
			$i++;
			continue;
		}

		/* Collect the whole block, counting nested if/endif so an inner `endif`
		   cannot close the outer one. cPanel writes both shapes:
		     if <cond> then <action> endif                    (all on one line)
		     if <cond> then / <action> / endif                (across lines)
		   and nests them: `... then if error_message then save ... else ... endif`.
		   The old scanner only ever looked for a line ENDING in "then", so a
		   one-line block sent it hunting for a "then" through the rest of the file
		   and it swallowed every later rule. That is why a cPanel /etc/vfilters
		   file imported nothing at all. */
		$start  = $i;
		$depth  = 0;
		$closed = false;
		for ($j = $i; $j < $n; $j++) {
			$depth += exim_block_delta($lines[$j]);
			if ($depth <= 0) { $closed = true; $i = $j; break; }
		}
		if (!$closed) $i = $n - 1;
		$raw = implode("\n", array_slice($lines, $start, $i - $start + 1));
		$i++;

		$rule_name = ($name !== null && $name !== '') ? $name : ('imported rule ' . (++$seq));
		$name = null;

		if (!$closed) { $skip[] = array('raw' => $raw, 'why' => 'no "endif" found'); continue; }

		/* Split the block at its first top-level `then`: everything before is the
		   condition, everything after (less the closing `endif`) is the actions. */
		$split = exim_split_then($raw);
		if ($split === false) { $skip[] = array('raw' => $raw, 'why' => 'no "then" found'); continue; }
		list($cond, $body) = $split;

		/* cPanel's "Fail with message" action. Its UI writes every reject as
		     if error_message then save "/dev/null" 660 else fail "TEXT" endif
		   because in Exim, failing a bounce would bounce the bounce. Sieve needs
		   no such guard — RFC 5429 forbids sending a rejection to a null return
		   path, and Pigeonhole discards instead — so the whole nested block is
		   exactly one `reject "TEXT"`.
		   This is not an exotic shape: it was 12 of the 13 blocks the first real
		   /etc/vfilters file could not convert, i.e. nearly every rule in it. */
		$fail_text = null;
		if (preg_match('/^if\s+error_message\s+then\s+save\s+"?\/dev\/null"?'
		             . '(?:\s+0?[0-7]{3,4})?\s*;?\s+else\s+fail\s+(.*?)\s*;?\s*endif$/is',
		               trim($body), $fm)) {
			$fail_text = exim_filter_unquote(trim($fm[1]));
		}

		if ($fail_text === null && preg_match('/(^|\s)(else|elif)(\s|$)/i', $body)) {
			$skip[] = array('raw' => $raw, 'why' => 'has else/elif, which Reqad cannot model as one rule');
			continue;
		}
		if ($fail_text === null && preg_match('/^if\b/', trim($body))) {
			$skip[] = array('raw' => $raw, 'why' => 'has a nested "if", which Reqad cannot model as one rule');
			continue;
		}

		$acts_raw = array();
		if ($fail_text !== null) {
			$acts_raw[] = 'fail "' . str_replace(array('\\', '"'), array('\\\\', '\\"'), $fail_text) . '"';
		} else {
			foreach (preg_split('/\r\n|\r|\n/', $body) as $bl) {
				$bl = trim($bl);
				if ($bl === '' || $bl[0] === '#') continue;
				$acts_raw[] = $bl;
			}
		}

		$why   = '';
		$parsed = exim_filter_conditions($cond, $why, $ctx);
		if ($parsed === false) { $skip[] = array('raw' => $raw, 'why' => $why); continue; }
		list($conds, $match_type) = $parsed;

		$acts = array();
		$stop = 0;
		foreach ($acts_raw as $a) {
			$one = exim_filter_action($a, $why, $ctx);
			if ($one === false) { $acts = false; break; }
			if ($one === null)   continue;                 /* no-op (e.g. "seen") */
			if ($one['type'] === 'stop') { $stop = 1; continue; }
			$acts[] = $one;
		}
		if ($acts === false)  { $skip[] = array('raw' => $raw, 'why' => $why); continue; }
		if (!$acts && !$stop) { $skip[] = array('raw' => $raw, 'why' => 'no action Reqad can model'); continue; }

		$rules[] = array(
			'name'       => substr($rule_name, 0, 120),
			'enabled'    => 1,
			'priority'   => 10,
			'match_type' => $match_type,
			'conditions' => json_encode($conds),
			'actions'    => json_encode($acts),
			'stop'       => $stop,
		);
	}

	return array('rules' => $rules, 'skipped' => $skip);
}

/* Blank out the parts of Exim filter text that keywords must not be matched in:
   double-quoted strings, and comments running to end of line. LENGTH-PRESERVING
   — every removed character becomes a space — so an offset found in the result
   points at the same character in the original. */
function exim_strip_noise($l) {
	$n = strlen($l);
	$out = str_repeat(' ', $n);
	$i = 0;
	while ($i < $n) {
		$c = $l[$i];
		if ($c === "\n") { $out[$i] = "\n"; $i++; continue; }
		if ($c === '#') {                            /* comment to end of line */
			while ($i < $n && $l[$i] !== "\n") $i++;
			continue;
		}
		if ($c === '"') {                            /* skip the whole string */
			$i++;
			while ($i < $n && $l[$i] !== '"') {
				if ($l[$i] === '\\') $i++;
				$i++;
			}
			$i++;
			continue;
		}
		$out[$i] = $c;
		$i++;
	}
	return $out;
}

/* Net if/endif nesting change contributed by one line. `elif` continues a block
   rather than opening one, so it must not count. */
function exim_block_delta($l) {
	$l = exim_strip_noise($l);
	$d = 0;
	if (preg_match_all('/(?<![A-Za-z0-9_$])(if|elif|endif)(?![A-Za-z0-9_])/i', $l, $m)) {
		foreach ($m[1] as $w) {
			$w = strtolower($w);
			if ($w === 'if')    $d++;
			if ($w === 'endif') $d--;
		}
	}
	return $d;
}

/* Split a whole `if ... then ... endif` block into (condition, body) at its
   FIRST `then` that is not inside a string or a comment, dropping the closing
   `endif`. Returns false if there is no `then` at all. */
function exim_split_then($raw) {
	$clean = exim_strip_noise($raw);

	if (!preg_match('/(?<![A-Za-z0-9_])then(?![A-Za-z0-9_])/i', $clean, $m, PREG_OFFSET_CAPTURE))
		return false;
	$at = $m[0][1];

	$cond = substr($raw, 0, $at);
	$body = substr($raw, $at + 4);

	$cond = trim(preg_replace('/^\s*if\b/i', '', $cond));

	/* Drop the block's own closing endif — the LAST one outside strings. */
	$bclean = exim_strip_noise($body);
	if (preg_match_all('/(?<![A-Za-z0-9_])endif(?![A-Za-z0-9_])/i', $bclean, $mm, PREG_OFFSET_CAPTURE)) {
		$last = end($mm[0]);
		$body = substr($body, 0, $last[1]) . substr($body, $last[1] + 5);
	}
	return array($cond, trim($body));
}

/* Split an Exim filter condition on top-level and/or. Returns
   array(conditions, 'all'|'any') or false with $why set. */
function exim_filter_conditions($cond, &$why, $ctx = array()) {
	$cond = trim($cond);
	if ($cond === '') { $why = 'empty condition'; return false; }

	/* Strip one wrapping paren pair: cPanel brackets multi-test conditions. */
	while (strlen($cond) > 1 && $cond[0] === '(' && substr($cond, -1) === ')'
	       && exim_paren_balanced(substr($cond, 1, -1)))
		$cond = trim(substr($cond, 1, -1));

	$parts = array();
	$ops   = array();
	$buf   = '';
	$depth = 0;
	$inq   = false;
	$len   = strlen($cond);
	for ($i = 0; $i < $len; $i++) {
		$c = $cond[$i];
		if ($inq) {
			$buf .= $c;
			if ($c === '\\' && $i + 1 < $len) { $i++; $buf .= $cond[$i]; continue; }
			if ($c === '"') $inq = false;
			continue;
		}
		if ($c === '"') { $inq = true; $buf .= $c; continue; }
		if ($c === '(') { $depth++; $buf .= $c; continue; }
		if ($c === ')') { $depth--; $buf .= $c; continue; }
		/* Any whitespace, newlines included: the block scanner now hands the
		   condition over exactly as written, and cPanel puts each `or` at the
		   start of its own line. Matching only space/tab silently glued the
		   whole multi-line condition into one malformed test. */
		if ($depth === 0 && ctype_space($c)) {
			if (preg_match('/^(and|or)\s/i', substr($cond, $i + 1), $m)) {
				$parts[] = trim($buf);
				$ops[]   = strtolower($m[1]);
				$buf     = '';
				$i      += strlen($m[1]);
				continue;
			}
		}
		$buf .= $c;
	}
	$parts[] = trim($buf);

	$uniq = array_unique($ops);
	if (count($uniq) > 1) {
		$why = 'mixes "and" with "or", which Reqad models as one match type only';
		return false;
	}
	$match_type = (count($uniq) && reset($uniq) === 'or') ? 'any' : 'all';

	$out = array();
	foreach ($parts as $p) {
		$c = exim_filter_test($p, $why, $ctx);
		if ($c === false) return false;
		$out[] = $c;
	}
	return array($out, $match_type);
}

function exim_paren_balanced($s) {
	$d = 0; $inq = false; $n = strlen($s);
	for ($i = 0; $i < $n; $i++) {
		$c = $s[$i];
		if ($inq) { if ($c === '\\') { $i++; continue; } if ($c === '"') $inq = false; continue; }
		if ($c === '"') { $inq = true; continue; }
		if ($c === '(') $d++;
		if ($c === ')') { $d--; if ($d < 0) return false; }
	}
	return $d === 0;
}

/* One Exim filter test → one Reqad condition, or false with $why set. */
function exim_filter_test($t, &$why, $ctx = array()) {
	$t   = trim($t);
	$neg = false;
	if (preg_match('/^not\s+/i', $t)) { $neg = true; $t = trim(substr($t, 4)); }

	/* cPanel's "Any Recipient": foranyaddress $h_to:,$h_cc: ($thisaddress contains "x") */
	if (preg_match('/^foranyaddress\s+(\$\S+)\s*\((.*)\)\s*$/is', $t, $m)) {
		$inner = trim($m[2]);
		$inner = preg_replace('/^\$thisaddress\s*/i', '', $inner);
		$c = exim_filter_op($inner, $why, $ctx);
		if ($c === false) return false;
		$hdrs = strtolower(str_replace(array('$h_', '$header_', ':', ' '), '', $m[1]));
		$set  = array_filter(explode(',', $hdrs));
		sort($set);
		if ($set !== array('cc', 'to')) {
			$why = 'foranyaddress over headers Reqad does not model: ' . $m[1];
			return false;
		}
		$c['field'] = 'to,cc';
		if ($neg) $c['op'] = 'not-' . $c['op'];
		return $c;
	}

	/* Exim filter condition KEYWORDS (no leading $). These are facts about the
	   delivery attempt, not about the message, and Sieve has no equivalent for
	   any of them — so say which one it was instead of "cannot read the test".
	   `first_delivery`/`error_message` matter because cPanel opens every file
	   with `if not first_delivery and error_message then finish endif`. */
	if (preg_match('/^(first_delivery|manually_thawed|delivered|error_message|personal)\b/i', $t, $m)) {
		$kw   = strtolower($m[1]);
		$note = array(
			'first_delivery'  => 'whether this is the first delivery attempt',
			'manually_thawed' => 'whether the message was thawed by hand',
			'delivered'       => 'whether any delivery has happened yet',
			'error_message'   => 'whether the message is a bounce',
			'personal'        => 'Exim\'s "looks like personal mail" heuristic',
		);
		$why = 'tests ' . $kw . ' (' . $note[$kw] . '), which Sieve cannot express';
		if ($kw === 'first_delivery' || $kw === 'error_message')
			$why .= ' — this is boilerplate that guards against filtering bounces,'
			      . ' and dropping it is normally the right call';
		return false;
	}

	/* $header_x: / $h_X: / $message_body / $reply_address: */
	if (!preg_match('/^(\$[A-Za-z_][A-Za-z0-9_-]*:?)\s*(.*)$/s', $t, $m)) {
		$why = 'cannot read the test: ' . $t;
		return false;
	}
	$raw_var = rtrim($m[1], ':');
	$var     = strtolower($raw_var);
	$rest    = trim($m[2]);

	/* Header names are case-insensitive in Sieve, but keep the spelling from the
	   source so a rule reads as "X-Spam-Flag", not "x-spam-flag". */
	$field = null;
	if ($var === '$message_body')        $field = 'body';
	else if ($var === '$reply_address')  $field = 'reply-to';
	else if (strpos($var, '$header_') === 0) $field = substr($raw_var, 8);
	else if (strpos($var, '$h_') === 0)      $field = substr($raw_var, 3);
	if ($field === null || $field === '') {
		$why = 'test on ' . $m[1] . ', which has no Sieve equivalent';
		return false;
	}
	/* Keep the header spelling users know; Sieve header names are case-insensitive. */
	$known = array('from'=>'from','to'=>'to','cc'=>'cc','subject'=>'subject',
	               'reply-to'=>'reply-to','list-id'=>'list-id','body'=>'body');
	$lf    = strtolower($field);
	$field = isset($known[$lf]) ? $known[$lf] : $field;

	$c = exim_filter_op($rest, $why, $ctx);
	if ($c === false) return false;
	$c['field'] = $field;
	if ($neg) $c['op'] = 'not-' . $c['op'];
	return $c;
}

/* `contains "x"` / `does not contain "x"` / `is "x"` … → op + value. */
function exim_filter_op($s, &$why, $ctx = array()) {
	$s = trim($s);
	$map = array(
		'does not contain'    => 'not-contains',
		'does not begin with' => 'not-begins',
		'does not end with'   => 'not-ends',
		'does not match'      => 'not-matches',
		'is not'              => 'not-is',
		'contains'            => 'contains',
		'begins with'         => 'begins',
		'begins'              => 'begins',
		'ends with'           => 'ends',
		'ends'                => 'ends',
		'matches'             => 'matches',
		'is'                  => 'is',
	);
	foreach ($map as $word => $op) {
		if (stripos($s, $word) === 0) {
			$v = trim(substr($s, strlen($word)));
			if ($v === '') { $why = 'operator "' . $word . '" with no value'; return false; }
			if ($v[0] === '"' && substr($v, -1) === '"')
				$v = str_replace(array('\\"', '\\\\'), array('"', '\\'), substr($v, 1, -1));
			$v = exim_expand_vars($v, $ctx, $why);
			if ($v === false) return false;
			return array('field' => '', 'op' => $op, 'value' => $v);
		}
	}
	$why = 'unknown operator in: ' . $s;
	return false;
}

/* One Exim filter action → one Reqad action, null for a no-op, or false. */
function exim_filter_action($a, &$why, $ctx = array()) {
	$a = trim(rtrim($a, ';'));
	$low = strtolower($a);

	if ($low === 'finish' || $low === 'stop')     return array('type' => 'stop', 'value' => '');
	if ($low === 'seen' || $low === 'unseen')     return null;   /* delivery bookkeeping, not a filter action */

	if (preg_match('/^save\s+(.*)$/i', $a, $m)) {
		/* Lenient: only the trailing .Folder component of the path is used, so an
		   unexpanded $home earlier in it changes nothing. */
		/* `save <path> [<mode>]` — the optional trailing octal is a file mode,
		   which Sieve has no equivalent for and which is not part of the path. */
		$sv = preg_replace('/\s+0?[0-7]{3,4}\s*$/', '', trim($m[1]));
		$p  = exim_expand_vars(exim_filter_unquote($sv), $ctx, $why, false);
		if ($p === '/dev/null')                    return array('type' => 'discard', 'value' => '');
		$folder = exim_maildir_to_folder($p);
		if ($folder === false) { $why = 'save to a path Reqad cannot map to a folder: ' . $p; return false; }
		if ($folder === '')                        return array('type' => 'keep', 'value' => '');
		return array('type' => 'fileinto', 'value' => $folder);
	}
	if (preg_match('/^deliver\s+(.*)$/i', $a, $m)) {
		$to = exim_expand_vars(exim_filter_unquote($m[1]), $ctx, $why);
		if ($to === false) return false;
		$to = exim_redirect_address($to, $why);
		if ($to === false) return false;
		$fold = ef_detail_folder($to, $ctx);
		if ($fold !== null)
			return $fold === '' ? array('type' => 'keep',     'value' => '')
			                    : array('type' => 'fileinto', 'value' => $fold);
		return array('type' => 'redirect', 'value' => $to);
	}
	if (preg_match('/^fail\s+(.*)$/i', $a, $m)) {
		$txt = exim_expand_vars(exim_filter_unquote($m[1]), $ctx, $why);
		if ($txt === false) return false;
		return array('type' => 'reject', 'value' => $txt);
	}
	if (preg_match('/^pipe\s+/i', $a)) {
		/* Deliberate: an exim pipe names an arbitrary command line, which our
		   pipe action cannot express (it takes a program installed in
		   EF_PIPE_BIN_DIR). Translating it would run something different. */
		$why = 'pipes to a command; install an equivalent program in ' . EF_PIPE_BIN_DIR
		     . ' and add the action by hand: ' . $a;
		return false;
	}
	$why = 'unknown action: ' . $a;
	return false;
}

/* Expand the Exim variables a cPanel filter uses, against the address the tier
   is being imported for.

   cPanel writes plus-address redirects as `deliver "\"$local_part+logs\"@$domain"`,
   and Exim expands $local_part/$domain at delivery time. Sieve has no variable
   expansion at all, so leaving them in produces a rule that redirects to the
   literal string "$local_part+logs@$domain" — mail to nowhere. They have to be
   resolved HERE, while we still know whose filter file this is.

   $ctx comes from the import scope: an account import knows both parts, a domain
   import knows only 'domain', a global import knows neither. In $strict mode an
   unresolvable variable fails the rule (it is skipped with its raw text) rather
   than being emitted half-translated. Lenient mode is for maildir save paths,
   where only the trailing .Folder component is used and an unexpanded $home in
   the middle is harmless. */
function exim_expand_vars($s, $ctx, &$why, $strict = true) {
	$s = (string)$s;
	if (strpos($s, '$') === false) return $s;

	$map = array();
	if (!empty($ctx['localpart'])) $map['local_part'] = $ctx['localpart'];
	if (!empty($ctx['domain']))    $map['domain']     = $ctx['domain'];
	if (!empty($ctx['home']))      $map['home']       = $ctx['home'];
	/* A per-mailbox filter runs for the bare address, so the affixes are empty.
	   Only claim that when we actually know the local part. */
	if (isset($map['local_part'])) {
		$map['local_part_prefix'] = '';
		$map['local_part_suffix'] = '';
	}

	$unknown = null;
	$out = preg_replace_callback('/\$\{?([A-Za-z_][A-Za-z0-9_]*)\}?/',
		function ($m) use ($map, &$unknown) {
			if (array_key_exists($m[1], $map)) return $map[$m[1]];
			if ($unknown === null) $unknown = $m[0];
			return $m[0];
		}, $s);

	if ($unknown !== null && $strict) {
		$why = 'uses the Exim variable ' . $unknown . ', which has no value at this '
		     . 'scope, and Sieve cannot expand variables — import this rule at the '
		     . 'mailbox level, or write the address out in full';
		return false;
	}
	return $out;
}

/* A cPanel filter that "delivers" to the mailbox's OWN address with a +detail
   is not a redirect at all — it is subaddressing, and cPanel files the message
   into the folder named by the detail. Written as a Sieve redirect it would
   loop the message back through delivery instead.

   Returns the folder name, '' when the address is the mailbox itself with no
   detail (a plain keep), or null when this is a genuine redirect somewhere else.
   Only the account tier can decide this: it is the only scope that knows which
   mailbox the filter belongs to.

   The detail's '.' becomes '/': cPanel's Maildir++ separator is '.', ours is
   '/', so "+Lists.News" is the folder Lists/News.

   Rendering it as an explicit `fileinto` is also strictly better than relying on
   the address: it does not depend on this server having detail-to-folder
   delivery turned on. */
function ef_detail_folder($addr, $ctx) {
	if (empty($ctx['localpart']) || empty($ctx['domain'])) return null;

	$lp  = strtolower($ctx['localpart']);
	$dom = strtolower($ctx['domain']);
	$at  = strrpos($addr, '@');
	if ($at === false) return null;
	if (substr($addr, $at + 1) !== $dom) return null;

	$alp = substr($addr, 0, $at);
	if ($alp === $lp) return '';                       /* itself, no detail */
	if (strpos($alp, $lp . '+') !== 0) return null;    /* a different mailbox */

	$detail = substr($alp, strlen($lp) + 1);
	if ($detail === '') return '';
	return str_replace('.', '/', $detail);
}

/* cPanel quotes the local part of a plus-address: "dt+logs"@reqad.net. That is
   legal RFC 5321 but pointless when the local part needs no quoting, and every
   client shows it verbatim — so unwrap it when it is safe to. Also the last gate
   that what we are about to write as a Sieve `redirect` really is an address. */
function exim_redirect_address($s, &$why) {
	$s = trim($s);
	if (preg_match('/^"([^"\\\\]*)"@(.+)$/', $s, $m)
	    && preg_match('/^[A-Za-z0-9!#$%&*+\\/=?^_`{|}~.\'-]+$/', $m[1]))
		$s = $m[1] . '@' . $m[2];

	if (!preg_match('/^[^@\s]+@[A-Za-z0-9][A-Za-z0-9.-]*\.[A-Za-z]{2,}$/', $s)) {
		$why = 'redirects to something that is not a plain email address: ' . $s;
		return false;
	}
	return strtolower($s);
}

function exim_filter_unquote($s) {
	$s = trim($s);
	if (strlen($s) > 1 && $s[0] === '"' && substr($s, -1) === '"')
		return str_replace(array('\\"', '\\\\'), array('"', '\\'), substr($s, 1, -1));
	return $s;
}

/* cPanel saves into a maildir path; Sieve files into a folder NAME.
   .../mail/<domain>/<user>/.Parent.Child → "Parent/Child";  the maildir root
   itself → '' (INBOX, i.e. plain keep).  Returns false if it is not a maildir. */
function exim_maildir_to_folder($path) {
	$p = rtrim(trim($path), '/');
	if ($p === '') return false;
	$base = basename($p);
	if (strpos($p, '/mail/') === false && strpos($p, '$home') === false)
		return false;
	if ($base === '' || $base[0] !== '.')
		return '';                                   /* the maildir root = INBOX */
	$folder = ltrim($base, '.');
	if ($folder === '' || $folder === 'INBOX') return '';
	return str_replace('.', '/', $folder);           /* maildir++ nesting */
}

function ef_valid_field($f) {
	if ($f === 'body' || $f === 'size' || $f === 'to,cc')
		return true;
	return preg_match('/^[A-Za-z0-9][A-Za-z0-9\-]{0,63}$/', (string)$f) === 1;
}

function ef_valid_op($o) {
	return in_array((string)$o, array(
		'contains', 'is', 'begins', 'ends', 'matches', 'regex',
		'not-contains', 'not-is', 'not-begins', 'not-ends', 'not-matches', 'not-regex',
		'over', 'under'), true);
}

function ef_valid_action_type($t) {
	return in_array((string)$t, array(
		'fileinto', 'redirect', 'reject', 'discard', 'keep', 'seen', 'flagged', 'addflag',
		'setflag', 'removeflag', 'pipe'), true);
}

/* Build one rule row from $_POST. Returns the row, or false with $errmsg set.
   The checks are email_filter_rule()'s (app/functions/email_tools.php), shared
   with the API. */
function ef_rule_from_post(&$errmsg) {
	$rows = function ($a, $b) {
		$x = isset($_POST[$a]) ? (array)$_POST[$a] : array();
		$y = isset($_POST[$b]) ? (array)$_POST[$b] : array();
		return array($x, $y);
	};
	list($fields, $ops) = $rows('cond_field', 'cond_op');
	$vals = isset($_POST['cond_value']) ? (array)$_POST['cond_value'] : array();
	$conds = array();
	for ($i = 0; $i < count($fields); $i++)
		$conds[] = array('field' => trim((string)$fields[$i]), 'op' => isset($ops[$i]) ? trim((string)$ops[$i]) : '',
		                 'value' => isset($vals[$i]) ? (string)$vals[$i] : '');
	list($types, $avals) = $rows('act_type', 'act_value');
	$acts = array();
	for ($i = 0; $i < count($types); $i++)
		$acts[] = array('type' => trim((string)$types[$i]), 'value' => isset($avals[$i]) ? (string)$avals[$i] : '');
	list($rule, $e) = email_filter_rule(array(
		'name'       => isset($_POST['name']) ? (string)$_POST['name'] : '',
		'enabled'    => !(isset($_POST['enabled']) && $_POST['enabled'] === '0'),
		'priority'   => isset($_POST['priority']) ? (int)$_POST['priority'] : 10,
		'match'      => isset($_POST['match_type']) && $_POST['match_type'] === 'any' ? 'any' : 'all',
		'stop'       => !empty($_POST['stop']),
		'conditions' => $conds,
		'actions'    => $acts,
	), null, !(isset($_POST['scope']) && $_POST['scope'] === 'account'));
	if ($e !== '') {
		$errmsg = 'Error: '.htmlspecialchars($e);
		return false;
	}
	return $rule;
}

/* Resolve and validate scope/target from $_POST for the filter modules.
   Returns array(scope, target) or false with $errmsg set. */
function ef_scope_from_post(&$errmsg) {
	$scope  = isset($_POST['scope'])  ? trim($_POST['scope'])  : '';
	$target = isset($_POST['target']) ? trim($_POST['target']) : '';

	if ($scope === 'global')
		return array('global', '');

	if ($scope === 'domain') {
		$domains = array_filter(explode("\n", trim((string)shell_exec('sudo ls -1 /etc/exim/domains/ 2>/dev/null'))));
		if (!in_array($target, $domains, true)) {
			$errmsg = 'Error: unknown domain.';
			return false;
		}
		return array('domain', $target);
	}

	if ($scope === 'account') {
		$target = strtolower($target);
		if (!in_array($target, mailbox_list(), true)) {
			$errmsg = 'Error: unknown mailbox.';
			return false;
		}
		return array('account', $target);
	}

	$errmsg = 'Error: unknown filter scope.';
	return false;
}

/* Write the account tier from a rule list. Returns '' or an error. */
/* Remove every filter artefact belonging to a domain. Called when a hosting
   account (and with it its domain) is deleted: the rules live in the panel db
   and the compiled script under /var/lib/reqad/sieve, neither of which is inside
   the account's home, so deleting the account used to leave both behind. An
   orphaned domain script is not merely untidy — recreate the domain later and
   its old rules are silently live again. Returns a short log line per action. */
function ef_purge_domain($db, $domain) {
	$done = array();
	if (!valid_domain($domain)) return $done;

	$st = $db->prepare('DELETE FROM email_filters WHERE scope = :s AND target = :t');
	$st->bindValue(':s', 'domain', SQLITE3_TEXT);
	$st->bindValue(':t', $domain,  SQLITE3_TEXT);
	$st->execute();
	if ($db->changes() > 0)
		$done[] = 'removed ' . $db->changes() . ' domain filter rule(s)';

	ef_helper(array('system-delete', 'domain', $domain), null, $rc);
	if ($rc === 0) $done[] = 'removed the domain filter script';

	/* Autoresponders are per mailbox, so they are named individually. */
	$res = $db->query('SELECT user FROM autoresponders WHERE domain = ' . "'" . SQLite3::escapeString($domain) . "'");
	$n = 0;
	while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
		ef_helper(array('system-delete', 'autoresponder', $row['user'] . '@' . $domain), null, $rc);
		$n++;
	}
	$st = $db->prepare('DELETE FROM autoresponders WHERE domain = :d');
	$st->bindValue(':d', $domain, SQLITE3_TEXT);
	$st->execute();
	if ($n) $done[] = "removed $n autoresponder(s)";

	return $done;
}

/* The mailbox equivalent. The personal Sieve script needs no handling: it lives
   inside the maildir, which the delete removes wholesale. Only the autoresponder
   script sits outside, under /var/lib/reqad/sieve/autoresponders. */
function ef_purge_mailbox($db, $email) {
	$done = array();
	if (!valid_email_address($email)) return $done;

	$at     = strrpos($email, '@');
	$user   = substr($email, 0, $at);
	$domain = substr($email, $at + 1);

	ef_helper(array('system-delete', 'autoresponder', $email), null, $rc);

	$st = $db->prepare('DELETE FROM autoresponders WHERE user = :u AND domain = :d');
	$st->bindValue(':u', $user,   SQLITE3_TEXT);
	$st->bindValue(':d', $domain, SQLITE3_TEXT);
	$st->execute();
	if ($db->changes() > 0) $done[] = 'removed the autoresponder';

	return $done;
}

function ef_write_account($target, $rules) {
	$src = sieve_render_rules($rules, false);
	if (trim($src) === '')
		return sieve_user_delete($target);
	return sieve_user_put($target, $src);
}

/* Read the account tier as rules. Returns false if it is not parseable — the
   caller must refuse to modify it structurally rather than clobber it. */
function ef_read_account($target) {
	return sieve_parse_script(sieve_user_get($target), false);
}

/* Where the filter modules redirect back to. */
function ef_redirect_base($scope, $target) {
	return $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST']
	     . '/email-filters/?scope=' . urlencode($scope)
	     . ($target !== '' ? '&target=' . urlencode($target) : '');
}

/* ── SpamAssassin allow / block lists (per hosting account) ───────────────────
 *
 * Exim's spam_check transport scans with
 *     spamc -u "${lookup{$domain_data}lsearch* {/etc/exim/userdomains}{$value}}"
 * so SpamAssassin is always asked for the HOSTING ACCOUNT's preferences, never
 * for an individual mailbox's - the account is the finest grain this server can
 * actually filter at, and every mailbox under it shares one list.
 *
 * spamd runs without --virtual-config-dir, so it setuids to that account and
 * reads ~/.spamassassin/user_prefs. THAT FILE IS THE TRUTH: there is no table
 * mirroring it. It is also exactly where cPanel keeps the same two lists, which
 * is what makes the transfer-tool import a copy rather than a conversion.
 *
 * The file is shared with whatever else has written prefs into it (required_score,
 * a hand-added rule, cPanel leftovers). Anything that is not one of the four
 * list directives is carried through a save untouched, in its original order.
 */

if (!defined('SA_HELPER'))
	define('SA_HELPER', '/usr/local/reqad/scripts/spam-filters/sa-helper.sh');

/* Header lines Reqad writes above each managed list. Recognised on read and
   dropped, so a save does not stack a new copy on top of the last one. */
if (!defined('SA_MARK_WHITE'))
	define('SA_MARK_WHITE', '# Reqad whitelist - mail from these senders is never spam');
if (!defined('SA_MARK_BLACK'))
	define('SA_MARK_BLACK', '# Reqad blocklist - mail from these senders is always spam');

/* Is this line one of Reqad's own list headers? Matched by shape rather than by
   the exact current wording, so a file written by an older build (which said
   "allow list" / "block list") is recognised too - otherwise its header would be
   carried through as an ordinary comment and a second one stacked above it on
   every save. */
function sa_is_marker($line) {
	return preg_match('/^#\s*Reqad\s+(white|black|allow|block)\s?list\b/i', trim($line)) === 1;
}

/* Run an sa-helper verb. Same shape as ef_helper(): $stdin goes through a temp
   file so file content never has to survive shell quoting. */
function sa_helper($args, $stdin = null, &$rc = null) {
	$cmd = 'sudo -n ' . SA_HELPER;
	foreach ((array)$args as $a)
		$cmd .= ' ' . escapeshellarg($a);

	$tmp = null;
	if ($stdin !== null) {
		$tmp = tempnam(sys_get_temp_dir(), 'sa_');
		file_put_contents($tmp, $stdin);
		$cmd .= ' < ' . escapeshellarg($tmp);
	}
	$cmd .= ' 2>&1';

	$out = array();
	$rc  = 0;
	exec($cmd, $out, $rc);
	if ($tmp !== null)
		@unlink($tmp);
	return implode("\n", $out);
}

/* The hosting accounts SpamAssassin will actually be asked about, as
   account => array of domains. /etc/exim/userdomains is the same lookup the
   spam_check transport does, so this is the real set - an account with no mail
   domain has nowhere for a list to take effect and is deliberately absent. */
function sa_accounts() {
	static $map = null;
	if ($map !== null)
		return $map;

	$out = shell_exec('sudo cat /etc/exim/userdomains 2>/dev/null');
	$map = array();
	foreach (explode("\n", (string)$out) as $line) {
		$line = trim($line);
		if ($line === '' || $line[0] === '#' || strpos($line, ':') === false)
			continue;
		list($domain, $acct) = explode(':', $line, 2);
		$domain = strtolower(trim($domain));
		$acct   = trim($acct);
		if ($domain === '' || !sa_valid_account($acct))
			continue;
		$map[$acct][] = $domain;
	}
	foreach ($map as $a => $d) {
		$d = array_values(array_unique($d));
		sort($d);
		$map[$a] = $d;
	}
	ksort($map);
	return $map;
}

/* System account name, matching valid_account() in sa-helper.sh. */
function sa_valid_account($a) {
	return preg_match('/^[a-z0-9_][a-z0-9_-]{0,31}$/', (string)$a) === 1;
}

/* Normalise one list entry, or return '' if it is not usable.
   A bare domain becomes *@domain: SpamAssassin matches these against the
   envelope/From address, so "example.com" on its own would never fire. */
function sa_normalize_pattern($p) {
	$p = strtolower(trim((string)$p));
	$p = trim($p, ',;');
	if ($p === '' || strlen($p) > 255)
		return '';
	if (strpos($p, '@') === false)
		$p = '*@' . $p;
	$at = strrpos($p, '@');
	$lp = substr($p, 0, $at);
	$dm = substr($p, $at + 1);
	/* The wildcards SpamAssassin understands here are * and ?, and nothing else
	   in an address needs quoting - so anything outside this set is a typo or an
	   attempt to smuggle a second directive onto the line. */
	if (!preg_match('/^[a-z0-9._+*?-]{1,64}$/', $lp))
		return '';
	if (!preg_match('/^[a-z0-9.*?-]{1,190}$/', $dm))
		return '';
	if ($dm !== '*' && strpos($dm, '.') === false)
		return '';
	return $lp . '@' . $dm;
}

/* Split a user_prefs file into the two lists Reqad manages and everything else.
   Returns array('white' => [...], 'black' => [...], 'other' => [lines]).

   Both the historic directive names and the 3.4.5+ ones are read; only the
   historic pair is written back, because those are understood by every version
   this panel can be installed against. */
function sa_parse_prefs($src) {
	$white = $black = $other = array();

	foreach (explode("\n", str_replace("\r\n", "\n", (string)$src)) as $raw) {
		$line = rtrim($raw);
		$t    = trim($line);

		if (sa_is_marker($t))
			continue;               /* our own header, re-emitted on save */

		/* SpamAssassin strips an unescaped # to end of line before parsing, so
		   a directive is read the same way here. */
		$code = preg_replace('/(?<!\\\\)#.*$/', '', $t);
		$code = trim($code);

		if ($code !== '' && preg_match('/^(whitelist_from|welcomelist_from|blacklist_from|blocklist_from)\s+(.+)$/i', $code, $m)) {
			$d      = strtolower($m[1]);
			$bucket = ($d === 'whitelist_from' || $d === 'welcomelist_from') ? 'w' : 'b';
			foreach (preg_split('/\s+/', trim($m[2])) as $p) {
				$n = sa_normalize_pattern($p);
				if ($n === '')
					continue;       /* unusable entry: dropped, not smuggled through */
				if ($bucket === 'w') $white[] = $n; else $black[] = $n;
			}
			continue;
		}

		$other[] = $line;
	}

	/* Trailing blank lines in the passthrough would grow by one on every save. */
	while ($other && trim(end($other)) === '')
		array_pop($other);

	return array(
		'white' => array_values(array_unique($white)),
		'black' => array_values(array_unique($black)),
		'other' => $other,
	);
}

/* Rebuild a user_prefs file: everything Reqad does not manage first, in its
   original order, then the two managed lists. */
function sa_render_prefs($other, $white, $black) {
	$out = array();
	foreach ((array)$other as $l)
		$out[] = rtrim($l);
	while ($out && trim(end($out)) === '')
		array_pop($out);

	if ($white) {
		if ($out) $out[] = '';
		$out[] = SA_MARK_WHITE;
		foreach ($white as $p)
			$out[] = 'whitelist_from ' . $p;
	}
	if ($black) {
		if ($out) $out[] = '';
		$out[] = SA_MARK_BLACK;
		foreach ($black as $p)
			$out[] = 'blacklist_from ' . $p;
	}
	if (!$out)
		return '';
	return implode("\n", $out) . "\n";
}

/* One account's prefs, parsed. */
function sa_lists_get($account) {
	return sa_parse_prefs(sa_helper(array('get', $account), null, $rc));
}

/* Every account's prefs in one sudo call, as account => parsed lists. Reads the
   length-prefixed stream sa-helper's get-all emits (a prefs file may contain any
   line at all, so a delimiter line would not be safe). */
function sa_lists_get_all() {
	$buf = sa_helper(array('get-all'), null, $rc);
	$out = array();
	$pos = 0;
	$len = strlen($buf);
	while ($pos < $len) {
		$nl = strpos($buf, "\n", $pos);
		if ($nl === false)
			break;
		$hdr = substr($buf, $pos, $nl - $pos);
		$pos = $nl + 1;
		if (!preg_match('/^(\S+) (\d+)$/', $hdr, $m))
			break;                       /* not our framing - stop rather than guess */
		$body = substr($buf, $pos, (int)$m[2]);
		$pos += (int)$m[2];
		$out[$m[1]] = sa_parse_prefs($body);
	}
	return $out;
}

/* Replace both managed lists for one account, preserving every other pref.
   Returns '' on success or an error message. Re-reads first: cPanel imports,
   sa-learn and hand edits all write to the same file. */
function sa_lists_put($account, $white, $black) {
	if (!sa_valid_account($account))
		return 'invalid account name';

	$cur = sa_lists_get($account);
	$src = sa_render_prefs($cur['other'], array_values(array_unique($white)),
	                                      array_values(array_unique($black)));

	if (trim($src) === '') {
		$out = sa_helper(array('delete', $account), null, $rc);
		return ($rc === 0) ? '' : trim($out);
	}
	$out = sa_helper(array('put', $account), $src, $rc);
	return ($rc === 0) ? '' : trim($out);
}

/* Where the spam-filter modules redirect back to. */
function sa_redirect_base($account) {
	return $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST']
	     . '/spam-filters/' . ($account !== '' ? '?account=' . urlencode($account) : '');
}


/* ── Mailbox disk-usage cache ─────────────────────────────────────────────────
 *
 * `du -skm` on a maildir is the only way to get this number and it is far too
 * slow to run for every mailbox on every page render, so the `emails` table
 * caches it. That table is a CACHE and nothing more (db/1032.sql): the mailbox
 * inventory is /etc/dovecot/users via mailbox_list(), so a row for a mailbox
 * that no longer exists is inert — lookups are driven by the dovecot list and
 * nothing ever reads it. A missing row means "not measured yet", not an error.
 *
 * Write-through: whoever measures a mailbox stores the result, so the cache
 * warms itself on the first page view and update_disk_usage (cron, every 2h)
 * is an optimisation rather than a requirement. There are deliberately no
 * insert/delete hooks on mailbox create/delete — that would be a second copy of
 * state dovecot already owns authoritatively, and every missed call site would
 * put the two back out of step. */

if (!defined('MAILBOX_USAGE_TTL'))
	define('MAILBOX_USAGE_TTL', 6 * 3600);      /* re-measure after 6 hours */

/* email => disk_usage (MB) for every row measured within the TTL. One query,
   because the callers render whole pages of mailboxes. */
function mailbox_usage_cached($db) {
	$fresh = array();
	$res = $db->query('SELECT email, disk_usage FROM emails
	                    WHERE updated_at IS NOT NULL
	                      AND updated_at > datetime("now", "-' . (int)MAILBOX_USAGE_TTL . ' seconds")');
	if ($res)
		while ($row = $res->fetchArray(SQLITE3_ASSOC))
			$fresh[strtolower($row['email'])] = (int)$row['disk_usage'];
	return $fresh;
}

/* Measure one maildir and store the result. $path comes from /etc/dovecot/users
   (field 6), never from user input. */
function mailbox_usage_measure($db, $email, $path) {
	$mb = (int)trim((string)shell_exec('sudo du -skm ' . escapeshellarg($path) . ' 2>/dev/null | awk \'{print $1}\''));

	$stmt = $db->prepare('INSERT INTO emails (email, disk_usage, updated_at)
	                      VALUES (:e, :u, datetime("now"))
	     ON CONFLICT(email) DO UPDATE SET disk_usage = :u, updated_at = datetime("now")');
	if ($stmt) {
		$stmt->bindValue(':e', strtolower($email), SQLITE3_TEXT);
		$stmt->bindValue(':u', $mb, SQLITE3_INTEGER);
		$stmt->execute();
	}
	return $mb;
}

/* Every mailbox with its size, as array(email, path, disk_usage). Cached rows
   are used as-is; anything stale or unmeasured is measured now and written back. */
/* A mailbox is disabled by prefixing its hash in /etc/dovecot/users with '!',
   the shadow(5) convention: the field stops being a valid crypt hash, so the
   CRYPT scheme can never match it. That kills IMAP/POP3 logins and SMTP AUTH
   alike (exim authenticates through the dovecot auth socket), while delivery to
   the mailbox and the password itself are untouched — re-enabling just strips
   the '!' back off. */
function mailbox_login_disabled($hash) {
	return substr((string)$hash, 0, 1) === '!';
}

/* Current password field for $email from /etc/dovecot/users, '' if not found. */
function mailbox_hash($email) {
	$line = trim(shell_exec('sudo grep -m1 -F -- '.escapeshellarg(strtolower($email).':').' /etc/dovecot/users 2>/dev/null'));
	if ($line === '')
		return '';
	$f = explode(':', $line);
	return isset($f[1]) ? $f[1] : '';
}

/* The extra fields of $email's line in /etc/dovecot/users (field 8 onward:
   "userdb_mail=maildir:~/" and whatever else is there, e.g. a mailbox size
   the Hosting Pro add-on wrote), '' if not found. A rewrite of the line must
   keep them. */
function mailbox_extra_fields($email) {
	foreach (explode("\n", (string)shell_exec('sudo cat /etc/dovecot/users 2>/dev/null')) as $line) {
		$f = explode(':', trim($line), 8);
		if (count($f) === 8 && strtolower($f[0]) === strtolower($email))
			return trim($f[7]);
	}
	return '';
}

function mailbox_usage_list($db) {
	$out    = shell_exec('sudo cat /etc/dovecot/users 2>/dev/null');
	$fresh  = mailbox_usage_cached($db);
	$rows   = array();

	foreach (explode("\n", (string)$out) as $line) {
		$line = trim($line);
		if ($line === '')
			continue;
		$f = explode(':', $line);
		/* passwd-style: user:hash:uid:gid:gecos:home:... — home is field 6. */
		if (count($f) < 6 || strpos($f[0], '@') === false)
			continue;
		$email = strtolower($f[0]);
		$path  = $f[5];

		$rows[] = array(
			'email'      => $email,
			'path'       => $path,
			'enabled'    => !mailbox_login_disabled($f[1]),
			'disk_usage' => isset($fresh[$email])
			                ? $fresh[$email]
			                : mailbox_usage_measure($db, $email, $path),
		);
	}
	usort($rows, function($a, $b) { return strcmp($a['email'], $b['email']); });
	return $rows;
}

/* Re-measure every mailbox unconditionally, ignoring the TTL, and drop cache
   rows for mailboxes that no longer exist. Used by scripts/update_disk_usage. */
function mailbox_usage_refresh_all($db) {
	$out  = shell_exec('sudo cat /etc/dovecot/users 2>/dev/null');
	$seen = array();
	$n    = 0;

	foreach (explode("\n", (string)$out) as $line) {
		$line = trim($line);
		if ($line === '')
			continue;
		$f = explode(':', $line);
		if (count($f) < 6 || strpos($f[0], '@') === false)
			continue;
		$email  = strtolower($f[0]);
		$seen[] = "'" . SQLite3::escapeString($email) . "'";
		mailbox_usage_measure($db, $email, $f[5]);
		$n++;
	}

	/* Not required for correctness — a row nothing looks up is harmless — but it
	   keeps the cache from growing forever as mailboxes come and go. */
	if ($seen)
		$db->query('DELETE FROM emails WHERE email NOT IN (' . implode(',', $seen) . ')');

	return $n;
}

/* Return the certificate file configured in the domain's live vhost, or ''
   when the domain has no vhost or no ssl_certificate/SSLCertificateFile line.
   Checked in order: panel vhost (hostname), nginx, apache. */
/* Call after every certbot run that installs a certificate. certbot's Apache
   plugin puts the https vhost of a site that had none into a new file
   <site>-le-ssl.conf; Reqad reads and edits only <site>.conf, so the script
   moves the https vhost in there (no-op on nginx, or when there is none). */
function le_ssl_vhost_merge($domain) {
	if (!valid_domain($domain) || web_front() !== 'httpd')
		return;
	shell_exec('sudo -n '._PATH.'/scripts/update/merge_le_ssl_vhosts.sh --quiet --domain='.escapeshellarg($domain)
	          .' >> '._PATH.'/log/debug_log 2>&1');
}

function local_ssl_cert_file($domain) {
	if(!valid_domain($domain)) return '';

	$files = array(
		'/etc/reqad/conf.d/'.$domain.'.conf',
		'/etc/nginx/conf.d/'.$domain.'.conf',
		'/etc/httpd/conf.d/'.$domain.'.conf',
		/* certbot's own https copy of an Apache vhost that had no :443 block
		   (scripts/update/merge_le_ssl_vhosts.sh moves it into the site's file) */
		'/etc/httpd/conf.d/'.$domain.'-le-ssl.conf',
	);
	foreach($files as $file) {
		/* the trailing [[:space:]] keeps ssl_certificate_key out of the match */
		$cert = trim(shell_exec("sudo grep -m1 -hE '^[[:space:]]*(ssl_certificate|SSLCertificateFile)[[:space:]]' "
			.escapeshellarg($file)." 2>/dev/null | awk '{print \$2}' | sed 's/;//'"));
		if($cert != '' && trim(shell_exec('sudo test -f '.escapeshellarg($cert).' && echo ok')) == 'ok')
			return $cert;
	}
	return '';
}

/* Parse the certificate this server actually serves for $domain, read from
   disk instead of over the network — a domain whose DNS still points at the
   old server would otherwise report that server's certificate.
   Returns the openssl_x509_parse() array, or false when there is none. */
function local_ssl_cert_info($domain) {
	$file = local_ssl_cert_file($domain);
	if($file == '') return false;

	$pem = shell_exec('sudo cat '.escapeshellarg($file).' 2>/dev/null');
	if($pem === null || strpos($pem, '-----BEGIN CERTIFICATE-----') === false) return false;

	$certinfo = @openssl_x509_parse($pem);
	if(!is_array($certinfo) || !isset($certinfo['validTo_time_t'])) return false;

	$certinfo['_file'] = $file;
	return $certinfo;
}

/* Every domain on this server a certificate can be installed for.
 *
 * Three sources, and the accounts table is only one of them: an ADDON domain
 * has no row there at all (accounts has unique indexes on both `user` and
 * `domain`, so the vhost file is its inventory — see addon_domain_list), and
 * the panel's own hostname has no row either. Both serve their own vhost with
 * their own certificate, so both belong on the SSL page; listing only the
 * accounts left addon domains with no way to get a certificate through the UI.
 *
 * Sorted, de-duplicated, and the single place the SSL page, the add/replace
 * picker and the handlers behind them agree on — they used to each carry their
 * own accounts query, which is how they drifted apart. */
function ssl_domain_list($db, $ini) {
	$domains = array();

	$res = $db->query('SELECT domain FROM accounts');
	while($res && $row = $res->fetchArray(SQLITE3_ASSOC))
		$domains[strtolower($row['domain'])] = true;

	foreach(addon_domain_list($ini) as $domain => $a)
		$domains[strtolower($domain)] = true;

	$hostname = strtolower(trim((string)shell_exec('hostname')));
	if($hostname != '')
		$domains[$hostname] = true;

	$domains = array_keys($domains);
	sort($domains);
	return $domains;
}

/* Is $domain one of them? The gate every SSL action uses before it touches a
   vhost or asks certbot for a certificate. */
function ssl_domain_exists($db, $ini, $domain) {
	if(!valid_domain($domain)) return false;
	return in_array(strtolower($domain), ssl_domain_list($db, $ini), true);
}

/* Self-signed = issued by itself (the reqad placeholder certificates carry O=Org). */
function ssl_is_self_signed($certinfo) {
	if(isset($certinfo['issuer']['O']) && $certinfo['issuer']['O'] == 'Org') return true;
	return isset($certinfo['issuer'], $certinfo['subject']) && $certinfo['issuer'] == $certinfo['subject'];
}

/* ==========================================================================
   Mail stack — overview, queue manager and exim/dovecot configuration.
   Backs templates/email.php, templates/email-config.php and the ajax-mq-* /
   ajax-mailconf-* endpoints. Every privileged queue operation goes through
   scripts/mail/mq-helper.sh; config writes reuse the Advanced Config version
   store (save_config_backup / list_config_backups / read_config_backup).
   ========================================================================== */

if (!defined('MQ_HELPER'))
	define('MQ_HELPER', _PATH.'/scripts/mail/mq-helper.sh');

/* Above this many queued messages we refuse to list: `exim -bp` walks the whole
   spool, so a runaway queue would hang the page for minutes. The user filters
   instead. Tune here if a busy server needs a different ceiling. */
if (!defined('MQ_MAX_LIST'))
	define('MQ_MAX_LIST', 5000);

/* Body lines returned by `view`. Must match BODY_MAX_LINES in mq-helper.sh --
   the helper does the cutting; this is only how the panel knows it happened. */
if (!defined('MQ_VIEW_BODY_LINES'))
	define('MQ_VIEW_BODY_LINES', 300);

/* Rows per page in the queue table. */
if (!defined('MQ_PAGE_ITEMS'))
	define('MQ_PAGE_ITEMS', 50);

/* Run an mq-helper verb. Same shape as ef_helper(): args are escapeshellarg'd
   individually, stdout+stderr come back as a string, $rc gets the exit status. */
function mq_helper($args, &$rc = null) {
	$cmd = 'sudo -n '.MQ_HELPER;
	foreach ((array)$args as $a)
		$cmd .= ' '.escapeshellarg($a);
	$cmd .= ' 2>&1';

	$out = array();
	$rc  = 0;
	exec($cmd, $out, $rc);
	return implode("\n", $out);
}

/* Number of messages in the queue. Cheap (exim -bpc just counts spool files),
   so this is what gates every listing. */
function mq_count() {
	$out = mq_helper(array('count'), $rc);
	if ($rc !== 0) return -1;
	return (int)trim($out);
}

/* Where the parsed queue is cached. One file per filter+search combination so a
   search never collides with the unfiltered listing. */
function mq_cache_file($filter = 'all', $pattern = '') {
	return _PATH.'/log/mailqueue.'.substr(sha1($filter.'|'.$pattern), 0, 12).'.cache';
}

/* Drop every cached queue listing. Called by all mutating endpoints so the
   refresh after a delete/deliver shows real state. */
function mq_cache_clear() {
	foreach ((array)glob(_PATH.'/log/mailqueue.*.cache') as $f)
		@unlink($f);
}

/* The whole queue (optionally narrowed), as
   array('id','age','size','sender','frozen','recipients' => array()).
   Cached for 30s: paging then costs no exim calls at all. */
function mq_list($filter = 'all', $pattern = '') {
	if ($filter !== 'frozen') $filter = 'all';
	$pattern = trim((string)$pattern);

	$cache = mq_cache_file($filter, $pattern);
	if (is_file($cache) && (time() - filemtime($cache)) < 30) {
		$rows = json_decode((string)file_get_contents($cache), true);
		if (is_array($rows)) return $rows;
	}

	$args = array('list', $filter);
	if ($pattern !== '') $args[] = $pattern;
	$out = mq_helper($args, $rc);
	if ($rc !== 0) return array();

	$rows = array();
	foreach (explode("\n", $out) as $line) {
		if (trim($line) === '') continue;
		$f = explode("\t", $line);
		if (count($f) < 6) continue;
		$rows[] = array(
			'id'         => $f[0],
			'age'        => $f[1],
			'size'       => $f[2],
			'sender'     => ($f[3] === '<>' ? '' : $f[3]),
			'frozen'     => ($f[4] === '1'),
			'recipients' => ($f[5] === '' ? array() : explode(',', $f[5])),
		);
	}

	@file_put_contents($cache, json_encode($rows));
	return $rows;
}

/* Subjects for a set of queued messages, as id => subject.

   Deliberately NOT part of mq_list(): reading a subject means opening that
   message's spool header, so doing it for a whole 5000-message queue would cost
   5000 file reads to render 50 rows. The caller slices the page first and asks
   only for what it is about to show.

   Subjects arrive RFC 2047 encoded (=?UTF-8?B?...?=) whenever they are not
   plain ASCII, so they are decoded here rather than shown raw. */
function mq_subjects($ids) {
	$ids = array_values(array_filter((array)$ids, 'mq_valid_id'));
	if (!$ids) return array();

	$out = mq_helper(array_merge(array('subjects'), $ids), $rc);
	if ($rc !== 0) return array();

	$map = array();
	foreach (explode("\n", $out) as $line) {
		if (trim($line) === '') continue;
		$f = explode("\t", $line, 2);
		if (count($f) < 2) continue;
		$subj = trim($f[1]);
		if ($subj !== '') {
			$dec = @iconv_mime_decode($subj, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
			if (is_string($dec) && $dec !== '') $subj = $dec;
		}
		$map[$f[0]] = $subj;
	}
	return $map;
}

/* Exim message id, as accepted by mq-helper.sh. Checked panel-side too so a bad
   id fails with a clean JSON error instead of a non-zero helper exit. */
function mq_valid_id($id) {
	return (bool)preg_match('/^[A-Za-z0-9]{6}-[A-Za-z0-9]{6,20}-[A-Za-z0-9]{2,6}$/', (string)$id);
}

/* Version + running state of one mail daemon, for the overview cards.
   Cached 60s in log/ — the page would otherwise spend four shell round-trips. */
function mail_stack_info($which) {
	if (!in_array($which, array('exim', 'dovecot', 'spamassassin', 'clamav'), true)) $which = 'exim';
	$cache = _PATH.'/log/mailstack.'.$which.'.cache';
	if (is_file($cache) && (time() - filemtime($cache)) < 60) {
		$d = json_decode((string)file_get_contents($cache), true);
		if (is_array($d)) return $d;
	}

	if ($which === 'exim') {
		/* exim -bV prints a "cwd=... args:" log line first; the version is the
		   first line that actually starts with "Exim version". */
		$raw = (string)shell_exec('sudo exim -bV 2>&1');
		$ver = '';
		foreach (explode("\n", $raw) as $l)
			if (preg_match('/^Exim version (\S+)/', trim($l), $m)) { $ver = $m[1]; break; }
		$ports   = trim((string)shell_exec("sudo exim -bP daemon_smtp_ports 2>/dev/null | sed 's/^[^=]*= *//'"));
		$ports   = implode(', ', array_filter(array_map('trim', explode(':', $ports)), 'strlen'));
		/* Which transport the virtual_user router hands local mail to: the
		   dovecot LMTP socket, or the legacy exim-side virtual_users_trans
		   appender kept for rollback. Read from the running config rather than
		   exim.conf so an override or an unreloaded edit can't lie about it. */
		$delivery = trim((string)shell_exec("sudo exim -bP router virtual_user 2>/dev/null | sed -n 's/^transport = *//p' | head -1"));
		$service = 'exim';
		$label   = 'Exim';
	} elseif ($which === 'spamassassin') {
		/* `spamassassin -V` prints "SpamAssassin version 3.4.6" then a perl line */
		$raw = (string)shell_exec('sudo spamassassin -V 2>/dev/null');
		$ver = preg_match('/version (\S+)/i', $raw, $m) ? $m[1] : '';
		/* spamd's listen port is whatever -p says in SPAMDOPTIONS, else 783 */
		$opts  = (string)shell_exec("sudo sed -n 's/^SPAMDOPTIONS=//p' /etc/sysconfig/spamassassin 2>/dev/null");
		$ports = preg_match('/(?:-p|--port)[= ]+(\d{1,5})/', $opts, $m) ? $m[1] : '783';
		$service  = 'spamassassin';
		$label    = 'SpamAssassin';
		$delivery = '';
	} elseif ($which === 'clamav') {
		/* `clamd --version` prints "ClamAV 1.5.4/28112/Thu Sep  3 09:22:22 2026"
		   -- engine version, then the signature database version, then its build
		   date. Only the engine version belongs in the Version row; the database
		   freshness is reported separately by clamav_summary(). */
		$raw = (string)shell_exec('sudo /usr/local/sbin/clamd --version 2>/dev/null');
		$ver = preg_match('#ClamAV ([0-9][^/\s]*)#', $raw, $m) ? $m[1] : '';
		/* clamd here is the upstream /usr/local build driven by reqad-clamav, so
		   it is a unix socket rather than a TCP port. */
		$ports    = '/run/clamd/clamd.sock';
		$service  = 'clamd';
		$label    = 'ClamAV';
		$delivery = '';
	} else {
		$ver     = trim((string)shell_exec('sudo dovecot --version 2>/dev/null'));
		$ver     = trim(preg_replace('/\s*\(.*$/', '', $ver));      // drop the build hash
		/* doveconf -n prints one "protocols = sieve imap lmtp pop3" line */
		$protos  = trim((string)shell_exec("sudo doveconf -n 2>/dev/null | sed -n 's/^protocols *= *//p' | head -1"));
		$ports   = implode(', ', array_filter(array_map('trim', preg_split('/\s+/', $protos)), 'strlen'));
		if ($ports === '') $ports = 'imap, pop3';
		$service  = 'dovecot';
		$label    = 'Dovecot';
		$delivery = '';
	}

	$active = (trim((string)shell_exec('sudo systemctl is-active '.$service.' 2>/dev/null')) === 'active');
	$since  = trim((string)shell_exec('sudo systemctl show '.$service.' -p ActiveEnterTimestamp --value 2>/dev/null'));
	/* The absolute timestamp is too long for a card column, and what the admin
	   actually reads off it is "has it restarted recently". Keep the parsed
	   epoch so the page can render an age -- computed at render time, not here,
	   or the 60-second cache would freeze it. */
	$since_ts = ($since !== '') ? (int)strtotime($since) : 0;

	$info = array(
		'which'   => $which,
		'label'   => $label,
		'version' => ($ver !== '' ? $ver : 'unknown'),
		'active'  => $active,
		'since'   => $since,
		'since_ts'=> ($since_ts > 0 ? $since_ts : 0),
		'ports'   => $ports,
		'service' => $service,
		'delivery'=> $delivery,
	);
	@file_put_contents($cache, json_encode($info));
	return $info;
}

/* SpamAssassin facts for the overview card: is it there at all, what threshold
   is in force, is Bayes on, and how stale the rules are.

   The threshold has three possible sources and they are not interchangeable:
   `required_score` in local.cf, the deprecated `required_hits` spelling the
   stock Rocky file still ships, or nothing at all -- in which case
   SpamAssassin's own 5.0 applies and the card says so. */
function spamassassin_summary() {
	$out = array(
		'installed'      => false,
		'required_score' => '5.0',
		'score_in_file'  => false,
		'use_bayes'      => true,
		'rules_age'      => 'unknown',
		'rules_ts'       => 0,
	);
	if (!is_executable('/usr/bin/spamassassin')) return $out;
	$out['installed'] = true;

	$content = (string)mail_config_read('spamassassin');
	$score = mail_setting_read($content, 'required_score', 'spamassassin');
	if ($score === null) $score = mail_setting_read($content, 'required_hits', 'spamassassin');
	if ($score !== null && trim($score) !== '') {
		$out['required_score'] = trim($score);
		$out['score_in_file']  = true;
	}
	$bayes = mail_setting_read($content, 'use_bayes', 'spamassassin');
	if ($bayes !== null) $out['use_bayes'] = (trim($bayes) !== '0');

	/* sa-update rewrites this directory; its mtime is the last successful run.
	   Rules that stopped updating are a silent failure -- spam just gets past. */
	$mt = (int)trim((string)shell_exec("sudo find /var/lib/spamassassin -maxdepth 2 -name updates_spamassassin_org -printf '%T@\n' 2>/dev/null | cut -d. -f1 | sort -n | tail -1"));
	$out['rules_ts'] = $mt;
	if ($mt > 0) $out['rules_age'] = human_duration(time() - $mt).' ago';
	else         $out['rules_age'] = 'never';

	return $out;
}

/* ClamAV state for the /email/ overview card.

   Three things an admin needs to know here, and every one of them fails
   silently:
     - is the engine present at all (reqad-clamav configures the upstream
       /usr/local build from clamav.net, not EPEL's /usr one)
     - how stale the signatures are -- a dead freshclam is invisible, because
       clamd carries on scanning quite happily against a months-old database
     - whether exim is actually wired to it. clamd can be running perfectly and
       scanning nothing at all, which looks identical on the Services page. */
function clamav_summary() {
	$out = array(
		'installed'   => false,
		'db_age'      => 'unknown',
		'db_ts'       => 0,
		'signatures'  => 0,
		'third_party' => 0,
		'feeds'       => array(),
		'exim_mode'   => 'disabled',
	);
	if (!is_executable('/usr/local/sbin/clamd')) return $out;
	$out['installed'] = true;

	/* freshclam rewrites daily.c?d on every successful update, so its mtime is
	   the last one. main/bytecode change rarely; daily is the freshness signal. */
	$mt = (int)trim((string)shell_exec("sudo find /usr/local/share/clamav -maxdepth 1 -name 'daily.c?d' -printf '%T@\n' 2>/dev/null | cut -d. -f1 | sort -n | tail -1"));
	if ($mt > 0) {
		$out['db_ts']  = $mt;
		$out['db_age'] = human_duration(time() - $mt).' ago';
	} else {
		$out['db_age'] = 'never';
	}

	/* clamd logs "Loaded N signatures" at startup and "Database correctly
	   reloaded (N signatures)" on every reload; the last of either is current. */
	$n = trim((string)shell_exec("sudo grep -oE '[0-9]+ signatures' /var/log/clamav/clamd.log 2>/dev/null | tail -1 | tr -dc '0-9'"));
	if ($n !== '') $out['signatures'] = (int)$n;

	/* Anything that is not main/daily/bytecode was put there by the third-party
	   feed fetcher. clamd reports only a grand total and never says WHICH extra
	   databases it holds, so list the files and count the signatures in each --
	   that is the only per-feed breakdown available.

	   Read directly rather than shelling out: the databases are world-readable
	   and streaming them avoids pulling ~5MB (phish.ndb) into memory. Cached,
	   because this would otherwise run on every page load. */
	$fcache = _PATH.'/log/clamav.feeds.cache';
	$feeds = null;
	if (is_file($fcache) && (time() - filemtime($fcache)) < 60) {
		$d = json_decode((string)file_get_contents($fcache), true);
		if (is_array($d)) $feeds = $d;
	}
	if ($feeds === null) {
		$feeds = array();
		$paths = glob('/usr/local/share/clamav/*.{ndb,hdb,cdb,ign2,ftm,ldb,hsb}', GLOB_BRACE);
		if (is_array($paths)) {
			sort($paths);
			foreach ($paths as $path) {
				$n = 0;
				if (($fh = @fopen($path, 'r')) !== false) {
					while (($line = fgets($fh)) !== false)
						if ($line !== '' && $line[0] !== '#' && trim($line) !== '') $n++;
					fclose($fh);
				}
				$feeds[] = array('name' => basename($path), 'sigs' => $n);
			}
		}
		@file_put_contents($fcache, json_encode($feeds));
	}
	$out['feeds']       = $feeds;
	$out['third_party'] = count($feeds);

	/* disabled | tag | deny, read back out of exim.conf rather than from a
	   stored flag, so a hand-edited config cannot leave this card lying. */
	$wire = '/usr/libexec/reqad/clamav-exim-wire.sh';
	if (is_executable($wire)) {
		$m = trim((string)shell_exec('sudo '.$wire.' status 2>/dev/null'));
		if (in_array($m, array('disabled', 'tag', 'deny'), true)) $out['exim_mode'] = $m;
	}

	return $out;
}

/* Is dovecot's full-text search usable?

   Three separate things, and only all three together mean working search:
     - the core fts plugin ships with dovecot itself (lib*_fts_plugin.so)
     - a BACKEND is a separate package (flatcurve, solr, xapian...) and is what
       actually stores the index; the core plugin alone indexes nothing
     - fts has to be listed in mail_plugins

   Reported as one of: enabled | no-backend | not-enabled | missing. */
function dovecot_fts_info() {
	$dirs = array('/usr/lib64/dovecot', '/usr/lib/dovecot');
	$core = false;
	$backends = array();
	foreach ($dirs as $d) {
		if (!is_dir($d)) continue;
		foreach ((array)glob($d.'/lib*_fts_plugin.so') as $f) $core = true;
		foreach ((array)glob($d.'/lib*_fts_*_plugin.so') as $f) {
			if (preg_match('/lib\d*_fts_(.+)_plugin\.so$/', basename($f), $m))
				$backends[] = $m[1];
		}
	}
	$backends = array_values(array_unique($backends));

	/* dovecot 2.4 writes mail_plugins as a block, not a space-separated list */
	$block   = (string)shell_exec("sudo doveconf -n 2>/dev/null | sed -n '/mail_plugins {/,/}/p'");
	$enabled = (bool)preg_match('/^\s*fts\s*=\s*yes/mi', $block);

	if (!$core)
		return array('state' => 'missing', 'backends' => array(),
		             'label' => 'Not installed', 'badge' => 'bg-red-lt');
	if ($enabled)
		return array('state' => 'enabled', 'backends' => $backends,
		             'label' => 'Enabled'.($backends ? ' ('.implode(', ', $backends).')' : ''),
		             'badge' => 'bg-green-lt');
	if (!$backends)
		return array('state' => 'no-backend', 'backends' => array(),
		             'label' => 'No backend', 'badge' => 'bg-yellow-lt');
	return array('state' => 'not-enabled', 'backends' => $backends,
	             'label' => 'Not enabled ('.implode(', ', $backends).')',
	             'badge' => 'bg-yellow-lt');
}

/* Roundcube version and the plugins it actually loads.

   The plugin list is read from config.inc.php rather than from the plugins/
   directory: that directory carries everything Roundcube ships, most of it
   inactive, so listing it would claim a dozen plugins are running that are not.
   Commented-out entries are skipped for the same reason. */
function roundcube_info() {
	$dir = defined('WEBMAIL_RC_DIR') ? WEBMAIL_RC_DIR : '/usr/local/reqad/roundcubemail';
	$out = array('installed' => false, 'version' => '', 'plugins' => array(), 'path' => $dir);

	$iniset = $dir.'/program/include/iniset.php';
	$cfg    = $dir.'/config/config.inc.php';
	if (!is_file($iniset) || !is_file($cfg)) return $out;
	$out['installed'] = true;

	if (preg_match("/RCMAIL_VERSION'\s*,\s*'([^']+)'/", (string)@file_get_contents($iniset), $m))
		$out['version'] = $m[1];

	$conf = (string)@file_get_contents($cfg);
	/* single-quoted: in a double-quoted PHP string "$config" interpolates and
	   the pattern silently loses the variable name it is looking for */
	if (preg_match('/\$config\[[\x27"]plugins[\x27"]\]\s*=\s*(\[|array\s*\()(.*?)(\]|\))\s*;/s', $conf, $m)) {
		$body = $m[2];
		$body = preg_replace('#/\*.*?\*/#s', '', $body);          // block comments
		$body = preg_replace('#(^|\s)(//|\#)[^\n]*#m', '', $body); // line comments
		if (preg_match_all('/[\x27"]([A-Za-z0-9_\-]+)[\x27"]/', $body, $mm)) {
			foreach ($mm[1] as $name)
				$out['plugins'][] = array(
					'name'    => $name,
					'present' => is_dir($dir.'/plugins/'.$name),
				);
		}
	}
	return $out;
}

function mail_stack_cache_clear() {
	foreach (array('exim', 'dovecot', 'spamassassin', 'clamav') as $w)
		@unlink(_PATH.'/log/mailstack.'.$w.'.cache');
}

/* How many real forwarders exist, for the overview card. Same parse as
   templates/forwarders.php: the per-domain files also carry the catch-all
   reject line (`*: :fail: No Such User Here`), which is not a forwarder and
   must not be counted. */
function forwarder_count() {
	$out = shell_exec('sudo grep -r : /etc/exim/forwards/ 2>/dev/null');
	$n = 0;
	foreach (explode("\n", (string)$out) as $line) {
		if (trim($line) === '') continue;
		$parts = explode(':', str_replace('/etc/exim/forwards/', '', trim($line)));
		if (!isset($parts[2]) || trim($parts[2]) === '') continue;
		$n++;
	}
	return $n;
}

/* ---- Mail config: targets, validation, write ----------------------------- */

/* The editable config file for a mail daemon. $which is a whitelisted key, never
   a caller-supplied path — same rule as account_config_target(). Dovecot edits
   local.conf only: dovecot.conf and conf.d/ are package-owned, local.conf is
   ours and already carries the reqad overrides. */
function mail_config_target($which) {
	/* syntax: how a setting line is written. exim and dovecot use `key = value`;
	   SpamAssassin's .cf files are `key value`, with no equals sign at all.
	   reload:  spamassassin.service has no ExecReload, so `systemctl reload`
	            fails on it -- spamd has to be restarted to re-read local.cf. */
	if ($which === 'exim')
		return array(
			'path'    => '/etc/exim/exim.conf',
			'service' => 'exim',
			'label'   => 'Exim configuration',
			'mode'    => 'exim',
			'syntax'  => 'equals',
			'reload'  => 'reload',
			'checker' => 'exim -bV',
		);
	if ($which === 'dovecot')
		return array(
			'path'    => '/etc/dovecot/local.conf',
			'service' => 'dovecot',
			'label'   => 'Dovecot configuration',
			'mode'    => 'dovecot',
			'syntax'  => 'equals',
			'reload'  => 'reload',
			'checker' => 'doveconf',
		);
	if ($which === 'spamassassin')
		return array(
			'path'    => '/etc/mail/spamassassin/local.cf',
			'service' => 'spamassassin',
			'label'   => 'SpamAssassin configuration',
			'mode'    => 'spamassassin',
			'syntax'  => 'space',
			'reload'  => 'restart',
			'checker' => 'spamassassin --lint',
		);
	return null;
}

/* Separator between a setting name and its value in this config's syntax. */
function mail_config_sep($which) {
	$t = mail_config_target($which);
	return ($t && isset($t['syntax']) && $t['syntax'] === 'space') ? ' ' : ' = ';
}

/* Regex fragment matching the separator after a key at column zero. */
function mail_config_sep_re($which) {
	$t = mail_config_target($which);
	return ($t && isset($t['syntax']) && $t['syntax'] === 'space') ? '[ \t]+' : '[ \t]*=[ \t]*';
}

/* Dry-run proposed content against the daemon's own parser without touching the
   live file. Returns '' when valid, else a cleaned error message.

   exim:    needs the file root-owned and non-writable or it refuses to read it,
            so the scratch copy is built under /var/lib/reqad with sudo.
   dovecot: local.conf alone is not a complete config (the parser demands
            dovecot_config_version first), so it is wrapped in a stand-in
            dovecot.conf that pulls in the real conf.d/ and then our candidate. */
function validate_mail_config($which, $content) {
	$t = mail_config_target($which);
	if (!$t) return 'Unknown config file.';

	$content = str_replace("\r\n", "\n", $content);
	if (substr($content, -1) !== "\n") $content .= "\n";

	$dir = '/var/lib/reqad/mailcfg.'.bin2hex(random_bytes(6));
	$tmp = tempnam(sys_get_temp_dir(), 'mailcfg_');
	file_put_contents($tmp, $content);

	shell_exec('sudo mkdir -p '.escapeshellarg($dir).' && sudo chown root:root '.escapeshellarg($dir).' && sudo chmod 755 '.escapeshellarg($dir));

	if ($which === 'exim') {
		$cand = $dir.'/exim.conf';
		shell_exec('sudo cp '.escapeshellarg($tmp).' '.escapeshellarg($cand).
		           ' && sudo chown root:root '.escapeshellarg($cand).' && sudo chmod 644 '.escapeshellarg($cand));
		$out = (string)shell_exec('sudo exim -bV -C '.escapeshellarg($cand).' 2>&1; echo "RC=$?"');
	} elseif ($which === 'spamassassin') {
		/* --lint takes a config DIRECTORY, not a file, and local.cf on its own
		   is not a config: the .pre files decide which plugins load and the
		   other .cf files carry the rules a bad local.cf could clash with. So
		   the whole site config dir is mirrored and only local.cf swapped. */
		$cand = $dir.'/local.cf';
		shell_exec('sudo cp /etc/mail/spamassassin/*.cf /etc/mail/spamassassin/*.pre '.escapeshellarg($dir).'/ 2>/dev/null');
		shell_exec('sudo cp '.escapeshellarg($tmp).' '.escapeshellarg($cand).
		           ' && sudo chown -R root:root '.escapeshellarg($dir).' && sudo chmod 644 '.escapeshellarg($dir).'/*');
		$out = (string)shell_exec('sudo spamassassin --lint --siteconfigpath='.escapeshellarg($dir).' 2>&1; echo "RC=$?"');
	} else {
		$cand = $dir.'/local.conf';
		$wrap = $dir.'/dovecot.conf';
		shell_exec('sudo cp '.escapeshellarg($tmp).' '.escapeshellarg($cand));
		$wrapper = "dovecot_config_version = 2.4.1\ndovecot_storage_version = 2.4.1\nprotocols = \n".
		           "!include_try /etc/dovecot/conf.d/*.conf\n!include_try ".$cand."\n";
		$wtmp = tempnam(sys_get_temp_dir(), 'mailcfg_');
		file_put_contents($wtmp, $wrapper);
		shell_exec('sudo cp '.escapeshellarg($wtmp).' '.escapeshellarg($wrap).
		           ' && sudo chown -R root:root '.escapeshellarg($dir).' && sudo chmod 644 '.escapeshellarg($cand).' '.escapeshellarg($wrap));
		@unlink($wtmp);
		$out = (string)shell_exec('sudo doveconf -c '.escapeshellarg($wrap).' -n 2>&1; echo "RC=$?"');
	}

	@unlink($tmp);
	shell_exec('sudo rm -rf '.escapeshellarg($dir));

	$rc = 0;
	if (preg_match('/RC=(\d+)\s*$/', $out, $m)) {
		$rc  = (int)$m[1];
		$out = preg_replace('/RC=\d+\s*$/', '', $out);
	}
	if ($rc === 0) return '';

	return mail_config_clean_error($out, $dir, $t['path'], $which);
}

/* Turn daemon output into something that reads like it came from the real file.
   Both tools bury the useful line in a wall of build information: exim prints
   its full version banner (library versions, built-in routers, transports...)
   after the error, and doveconf dumps the whole parsed config. Keep only the
   part that says what is wrong, and point it at the real path. */
function mail_config_clean_error($out, $dir, $realpath, $which = 'exim') {
	$lines = array();
	foreach (explode("\n", (string)$out) as $l) {
		$l = rtrim($l);
		if (trim($l) === '') continue;

		if ($which === 'exim') {
			/* the banner starts at "Exim version ..." and everything after it
			   is build detail, not diagnostics */
			if (preg_match('/^Exim version /', $l)) break;
			if (strpos($l, 'args: exim') !== false) continue;              // exim's invocation log
			$l = preg_replace('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\s+/', '', $l);
		} elseif ($which === 'spamassassin') {
			/* every --lint line is prefixed with a timestamp and pid, and the
			   final "N issues detected, please rerun with debug enabled" adds
			   nothing once the offending lines are already listed */
			$l = preg_replace('/^\w{3}\s+\d+\s+[\d:.]+\s+\[\d+\]\s+/', '', $l);
			if (preg_match('/^\w+: lint: \d+ issues detected/', $l)) continue;
			$l = preg_replace('/^(warn|error|info):\s*/', '', $l);
		} else {
			/* doveconf prints the parsed config on stdout and the problem on
			   stderr; only the latter is worth showing */
			if (!preg_match('/(Fatal|Error|Warning|Invalid|Unknown)/i', $l)) continue;
			$l = preg_replace('/^doveconf:\s*/', '', $l);
		}

		$l = str_replace(array($dir.'/exim.conf', $dir.'/local.conf', $dir.'/local.cf'), $realpath, $l);
		$l = str_replace($dir.'/', '', $l);
		$lines[] = $l;
	}
	$err = trim(implode("\n", $lines));
	return $err !== '' ? $err : 'Configuration test failed.';
}

/* Read the live config file. */
function mail_config_read($which) {
	$t = mail_config_target($which);
	if (!$t) return null;
	$out = shell_exec('sudo cat '.escapeshellarg($t['path']).' 2>/dev/null');
	return ($out === null) ? null : (string)$out;
}

/* Validate, back up the version being replaced, write, re-test, reload — and
   revert if the live daemon disagrees with the preflight. The single write path
   for both the settings form and the raw editor.
   Returns array('error' => ..., 'success' => ...). */
function apply_mail_config($which, $content, $user = 'mail') {
	$t = mail_config_target($which);
	if (!$t) return array('error' => 'Unknown config file.', 'success' => '');

	$orig = mail_config_read($which);
	if ($orig === null || $orig === '')
		return array('error' => 'Config file not found: '.$t['path'], 'success' => '');

	$content = str_replace("\r\n", "\n", $content);
	if (substr($content, -1) !== "\n") $content .= "\n";

	if ($content === $orig)
		return array('error' => '', 'success' => 'No changes to save.');

	/* isolated pre-check, so invalid content never reaches the live file */
	$verr = validate_mail_config($which, $content);
	if ($verr !== '')
		return array('error' => 'Validation failed, not saved:'."\n".$verr, 'success' => '');

	save_config_backup($user, $which, $t['path'], $orig);

	$tmp = tempnam(sys_get_temp_dir(), 'mailcfg_');
	file_put_contents($tmp, $content);
	shell_exec('sudo cp '.escapeshellarg($tmp).' '.escapeshellarg($t['path']));
	@unlink($tmp);

	/* re-test what is actually on disk now; revert on any disagreement */
	$live = validate_mail_config($which, $content);
	if ($live !== '') {
		$bak = tempnam(sys_get_temp_dir(), 'mailcfg_');
		file_put_contents($bak, $orig);
		shell_exec('sudo cp '.escapeshellarg($bak).' '.escapeshellarg($t['path']));
		@unlink($bak);
		return array('error' => 'Validation failed on the live config, reverted:'."\n".$live, 'success' => '');
	}

	/* A reload that the unit cannot do is worse than a restart: systemd answers
	   "Job type reload is not applicable", the exit status is thrown away, and
	   the panel cheerfully reports a saved setting that the running daemon has
	   never read. Stock exim.service has no ExecReload at all (Reqad adds one
	   in scripts/update/setup_exim_reload.sh), so check the status and fall
	   back to a restart rather than lie about it. */
	$verb = (isset($t['reload']) && $t['reload'] === 'restart') ? 'restart' : 'reload';
	$rc   = 0;
	exec('sudo systemctl '.$verb.' '.escapeshellarg($t['service']).' >> '._PATH.'/log/debug_log 2>&1', $ignored, $rc);
	if ($rc !== 0 && $verb === 'reload') {
		$verb = 'restart';
		exec('sudo systemctl restart '.escapeshellarg($t['service']).' >> '._PATH.'/log/debug_log 2>&1', $ignored, $rc);
	}
	mail_stack_cache_clear();
	log_debug('[mailconfig] '.$which.' saved + '.$verb.' '.$t['service'].' rc='.$rc);
	if ($rc !== 0)
		return array('error' => $t['label'].' was saved, but '.$t['service'].' could not be '
		                        .($verb === 'restart' ? 'restarted' : 'reloaded')
		                        .' — it is still running the previous configuration.', 'success' => '');
	return array('error' => '', 'success' => $t['label'].' saved and '.$t['service'].' '.($verb === 'restart' ? 'restarted' : 'reloaded').'.');
}

/* ---- Mail config: the curated settings form ------------------------------
   Only the keys listed here are editable through the form, and the form posts
   the key NAME, never a path or a raw line — same whitelist rule as
   modules/config_save.php. Anything not in this list is reachable only through
   the raw editor, which is validated identically. */

/* Exim time values are compound: "2m45s", "1h30m", "1w2d" are all legal, and
   `exim -bP` NORMALISES what is in the file (165s is reported back as 2m45s).
   A single-unit pattern therefore rejects the very value the field displays. */
if (!defined('EXIM_TIME_RE'))
	define('EXIM_TIME_RE', '#^(?:\d{1,6}[smhdw])*\d{1,6}[smhdw]?$#');

/* Group -> key -> definition.
     type    text | number | select | toggle | textarea
     virtual the value is derived rather than stored under its own name
     help    shown under the field
     unit    suffix rendered inside the input group */
/* ---- Dovecot mail compression -------------------------------------------
   The mail_compress plugin and the imap/pop3 vsz_limit do NOT live in
   local.conf, so they cannot go through mail_setting_write like the rest of the
   Dovecot page. They live in /etc/dovecot/compress.conf, which is owned start to
   finish by scripts/update/setup_dovecot_compress.sh — the panel asks that
   script for state and tells it what to do, rather than learning the file
   format in a second place and letting the two drift.

   Everything here shells out as root (the panel's user has NOPASSWD sudo), and
   every value handed over is constrained to a fixed choice list before it goes
   near a command line. */

if (!defined('COMPRESS_HELPER'))
	define('COMPRESS_HELPER', _PATH.'/scripts/update/setup_dovecot_compress.sh');

/* One `--status` call answers all three questions the page asks. Cached for the
   request so rendering a form with two compression fields does not fork twice. */
function mail_compress_status($refresh = false) {
	static $cache = null;
	if ($cache !== null && !$refresh) return $cache;
	$out = array(); $rc = 0;
	exec('sudo -n /usr/bin/bash '.escapeshellarg(COMPRESS_HELPER).' --status 2>&1', $out, $rc);
	$st = array('enabled' => false, 'vsz' => '', 'compressed' => 0, 'ok' => ($rc === 0));
	foreach ($out as $line) {
		$line = trim($line);
		if ($line === 'enabled')  $st['enabled'] = true;
		if (strpos($line, 'vsz=') === 0) {
			$v = substr($line, 4);
			$st['vsz'] = ($v === 'default') ? '' : $v;
		}
		if (strpos($line, 'compressed_mailboxes=') === 0)
			$st['compressed'] = (int)substr($line, 21);
	}
	$cache = $st;
	return $st;
}

function mail_compress_enabled() {
	$st = mail_compress_status();
	return $st['enabled'];
}

/* Enable, or re-run with a new vsz_limit. The script is idempotent and rolls
   itself back if doveconf or dovecot reject the result, so this cannot leave
   the mail server down. */
function mail_compress_enable($vsz = '') {
	$args = '';
	if ($vsz !== '') {
		/* belt and braces: the script validates this too, but a value that
		   reaches a command line should never have been free text here */
		if (!preg_match('/^\d{1,6}[KMG]?$/', $vsz))
			return array('error' => 'Invalid memory limit.', 'success' => '');
		$args = ' --vsz '.escapeshellarg($vsz);
	}
	$out = array(); $rc = 0;
	exec('sudo -n /usr/bin/bash '.escapeshellarg(COMPRESS_HELPER).$args.' 2>&1', $out, $rc);
	mail_compress_status(true);
	if ($rc !== 0)
		return array('error' => 'Could not enable mail compression: '.h(implode(' ', array_slice($out, -3))), 'success' => '');
	return array('error' => '', 'success' => 'Mail compression enabled.');
}

/* Refused by the script while any mailbox still holds gzipped messages — those
   would be served to clients as raw gzip the moment the plugin goes away. The
   error text names the command that undoes it, so pass it through rather than
   replacing it with something vaguer. */
function mail_compress_disable() {
	$out = array(); $rc = 0;
	exec('sudo -n /usr/bin/bash '.escapeshellarg(COMPRESS_HELPER).' --disable 2>&1', $out, $rc);
	mail_compress_status(true);
	if ($rc !== 0) {
		$st  = mail_compress_status();
		$msg = 'Mail compression cannot be turned off yet: '.(int)$st['compressed'].
		       ' mailbox(es) still hold compressed messages, and dovecot would serve '.
		       'those to clients as unreadable gzip data. Decompress them first, on the server:'.
		       '<br><code>'.h(_PATH.'/scripts/compress_old_mail --decompress --all').'</code>';
		return array('error' => $msg, 'success' => '');
	}
	return array('error' => '', 'success' => 'Mail compression disabled.');
}

/* ---- Dovecot imap/pop3 memory limit --------------------------------------
   service imap { vsz_limit } is not a flat key=value line, so it cannot go
   through mail_setting_write with the rest of the Dovecot page. It used to be
   written by the compression helper into compress.conf, which meant the panel
   field did nothing whenever compression was off -- the save was validated and
   then dropped, and dovecot went on killing imap children at the 256M default.
   It now has its own file and its own script, on the same "ask the script that
   owns the file" footing as compression. */

if (!defined('VSZ_HELPER'))
	define('VSZ_HELPER', _PATH.'/scripts/update/setup_dovecot_vsz.sh');

/* Read from `doveconf -n`, so this reports what dovecot is ENFORCING rather
   than what some file says. '' means no explicit limit: dovecot's own default
   is in force. Cached for the request. */
function mail_vsz_status($refresh = false) {
	static $cache = null;
	if ($cache !== null && !$refresh) return $cache;
	$out = array(); $rc = 0;
	exec('sudo -n /usr/bin/bash '.escapeshellarg(VSZ_HELPER).' --status 2>&1', $out, $rc);
	/* 'uniform' is whether imap, pop3 and indexer-worker all carry the same
	   limit. They can differ on a server configured by hand, and the value
	   reported above is imap's -- so without this flag a save that picks the
	   value imap already has would be skipped as "no change" and leave
	   indexer-worker sitting at the 256M default. Assume uniform when the
	   script is too old to say, which is the pre-existing behaviour. */
	$st = array('vsz' => '', 'uniform' => true, 'ok' => ($rc === 0));
	foreach ($out as $line) {
		$line = trim($line);
		if (strpos($line, 'vsz=') === 0) {
			$v = substr($line, 4);
			$st['vsz'] = ($v === 'default') ? '' : $v;
		}
		if (strpos($line, 'uniform=') === 0)
			$st['uniform'] = (substr($line, 8) !== 'no');
	}
	$cache = $st;
	return $st;
}

/* The script validates this too, but a value that reaches a command line should
   never have been free text here. It restarts dovecot -- a service{} setting is
   read by the master at startup, and a reload would leave the old limit in force
   while the file claimed otherwise. */
function mail_vsz_set($vsz) {
	if (!preg_match('/^\d{1,6}[KMG]?$/', $vsz))
		return array('error' => 'Invalid memory limit.', 'success' => '');
	$out = array(); $rc = 0;
	exec('sudo -n /usr/bin/bash '.escapeshellarg(VSZ_HELPER).' --vsz '.escapeshellarg($vsz).' 2>&1', $out, $rc);
	mail_vsz_status(true);
	if ($rc !== 0)
		return array('error' => 'Could not set the memory limit: '.h(implode(' ', array_slice($out, -3))), 'success' => '');
	return array('error' => '', 'success' => 'IMAP/POP3 memory limit set to '.h($vsz).' and dovecot restarted.');
}

function mail_setting_defs($which) {
	if ($which === 'exim') return array(
		'identity' => array('title' => 'Identity &amp; logging', 'keys' => array(
			'primary_hostname' => array('label' => 'Primary hostname', 'type' => 'text', 'pattern' => '#^[A-Za-z0-9\.\-]{0,253}$#',
				'help' => 'The name exim calls itself in HELO and Received headers. Leave empty to use the system hostname.'),
			'smtp_banner' => array('label' => 'SMTP banner', 'type' => 'text', 'pattern' => '#^[^\r\n]{0,255}$#',
				'help' => 'Greeting sent on connect. Exim expansions such as <code>${primary_hostname}</code> work here.'),
			'message_body_visible' => array('label' => 'Body bytes visible to filters', 'type' => 'text', 'pattern' => '#^\d{1,9}[KMG]?$#i'),
			'log_selector' => array('label' => 'Log selector', 'type' => 'textarea', 'rows' => 3, 'pattern' => '#^[A-Za-z0-9 _\+\-]*$#', 'maxlen' => 1024, 'oneline' => true,
				'help' => 'Space-separated <code>+item</code> / <code>-item</code> flags controlling what lands in the exim log.'),
			'disable_ipv6' => array('label' => 'Disable IPv6', 'type' => 'toggle',
				'help' => 'Turn on only when the server has no working IPv6 — otherwise exim wastes a connection attempt per delivery.'),
		)),
		'tls' => array('title' => 'TLS &amp; ports', 'keys' => array(
			'tls_min_version' => array(
				'label' => 'Minimum TLS version', 'type' => 'select', 'virtual' => true,
				'choices' => array('TLSv1.0' => 'TLS 1.0 (insecure)', 'TLSv1.1' => 'TLS 1.1 (insecure)', 'TLSv1.2' => 'TLS 1.2 (recommended)', 'TLSv1.3' => 'TLS 1.3 only'),
				'help'  => 'Written as <code>openssl_options</code>. TLS 1.3 only will refuse mail from older servers — most of the internet still needs 1.2.'),
			/* tls_advertise_hosts is deliberately NOT offered here. Exim's own
			   default is `*`, so the only thing a change can do is narrow who
			   is offered STARTTLS -- and clearing it turns STARTTLS off on 25
			   and 587 outright, which means submission passwords in clear.
			   The one historical reason to narrow it (ancient MTAs that broke
			   on STARTTLS) is long gone. Still editable in the Advanced tab
			   for anyone who genuinely needs it. */
			'tls_require_ciphers' => array(
				'label' => 'Cipher list', 'type' => 'text', 'pattern' => '#^[A-Za-z0-9 :\.\!\+\-_@]{0,255}$#',
				'help'  => 'OpenSSL cipher string. Leave as shipped unless you know you need to change it.'),
			'daemon_smtp_ports' => array(
				'label' => 'Listening ports', 'type' => 'text', 'pattern' => '#^[0-9 :]{1,64}$#',
				'help'  => 'Colon-separated. 25 = server-to-server, 587 = submission, 465 = submission over implicit TLS.'),
			'tls_on_connect_ports' => array(
				'label' => 'Implicit-TLS ports', 'type' => 'text', 'pattern' => '#^[0-9 :]{0,64}$#',
				'help'  => 'Ports where TLS starts immediately, without STARTTLS. Normally just 465.'),
		)),
		'limits' => array('title' => 'Message &amp; connection limits', 'keys' => array(
			'message_size_limit' => array('label' => 'Maximum message size', 'type' => 'text', 'pattern' => '#^\d{1,9}[KMG]?$#i',
				'help' => 'A number, optionally suffixed K, M or G. <code>0</code> means no limit.'),
			'recipients_max' => array('label' => 'Maximum recipients per message', 'type' => 'number', 'min' => 0, 'max' => 100000),
			'smtp_accept_max' => array('label' => 'Maximum simultaneous connections', 'type' => 'number', 'min' => 0, 'max' => 10000),
			'smtp_accept_queue_per_connection' => array('label' => 'Messages per connection before queueing', 'type' => 'number', 'min' => 0, 'max' => 100000),
			'remote_max_parallel' => array('label' => 'Parallel remote deliveries', 'type' => 'number', 'min' => 1, 'max' => 1000),
			'smtp_receive_timeout' => array('label' => 'Receive timeout', 'type' => 'text', 'pattern' => EXIM_TIME_RE,
				'help' => 'Time exim waits for the next SMTP command, e.g. <code>165s</code>, <code>5m</code> or <code>2m45s</code>.'),
		)),
		/* Outbound sending limits. Every key here is an exim MACRO, not an
		   option: the rules that read them live in acl_check_rcpt and in
		   acl_reqad_fail / acl_reqad_notsmtp, installed once by
		   scripts/update/setup_mail_limits.sh. That is what lets the whole
		   feature be tuned through the ordinary settings path -- a macro is a
		   `NAME = value` line at column zero like any other. `macro => true`
		   marks them so a cleared field restores the default rather than
		   deleting a line the ACL depends on. */
		'sendlimits' => array('title' => 'Outbound sending limits', 'keys' => array(
			'REQAD_LIMIT_MODE' => array(
				'label' => 'When a domain is over its limit', 'type' => 'select', 'macro' => true, 'default' => 'freeze',
				'choices' => array(
					'freeze' => 'Log and freeze — accept, hold in the mail queue',
					'defer'  => 'Temporary reject (451) — sender retries later',
					'deny'   => 'Permanent reject (550) — sender gets a bounce'),
				'help'  => '<strong>Freeze</strong> accepts the message and holds it, undelivered, on the <a href="/email/#queue">Mail Queue</a> page — nothing bounces and nothing is lost, and you release or delete it after a look. Note the sender is told the mail went out, and held mail is eventually discarded by <em>Discard frozen messages after</em> on the Settings tab. <strong>451</strong> leaves the mail with the sending client, which retries later; <strong>550</strong> bounces it. Every mode logs, so <code>grep "REQAD LIMIT" /var/log/exim/main.log</code> always shows what happened.'),
			'REQAD_MAX_HOURLY' => array(
				'label' => 'Maximum hourly email by domain relayed', 'type' => 'number', 'macro' => true,
				'min' => 0, 'max' => 1000000, 'default' => '200',
				'help'  => 'Recipients per hour per sending domain, counted from the mailbox that authenticated — not from the envelope sender, which is forgeable. <code>0</code> means no limit.'),
			'REQAD_MAX_FAILURES' => array(
				'label' => 'Maximum failed messages per hour', 'type' => 'number', 'macro' => true,
				'min' => 0, 'max' => 1000000, 'default' => '25',
				'help'  => 'Permanent delivery failures per hour per sending domain — a bounce, counted once, at the moment it is generated. Deferrals and the retries that follow them are <strong>not</strong> counted: mail to one over-quota mailbox is retried for days. <code>0</code> means no limit.'),
			'REQAD_LOCALMAIL' => array(
				'label' => 'Mail sent without authenticating', 'type' => 'select', 'macro' => true, 'default' => 'warn',
				'choices' => array(
					'accept' => 'Allow — no restriction',
					'warn'   => 'Log only — record it, deliver anyway',
					'deny'   => 'Block — require authenticated SMTP'),
				'help'  => 'Covers PHP\'s <code>mail()</code>, cron jobs and anything pointed at <code>localhost:25</code> without a login — the traffic a hacked plugin uses, and the traffic no per-domain limit can see, because there is no domain to attribute it to. Blocking it forces applications onto authenticated SMTP on port 587, where they are counted. Root and system mail are always allowed. <strong>Leave this on log only until the log tells you which sites still use <code>mail()</code></strong> — blocking it breaks contact forms that have never been configured with SMTP credentials.'),
			'reqad_nolimit' => array(
				'label' => 'Exempt sending domains', 'type' => 'textarea', 'rows' => 2,
				'line_key' => 'domainlist reqad_nolimit', 'macro' => true, 'default' => '',
				'pattern' => '#^[A-Za-z0-9\.\-\*\?\+\@ :_/]{0,1024}$#', 'maxlen' => 1024, 'oneline' => true,
				'help'  => 'Colon-separated list of domains that no limit applies to, e.g. <code>example.com : mail.example.net</code>. Leave empty for none.'),
		)),
		/* A macro read by the forwards router's condition, installed by
		   scripts/update/setup_forward_spam_gate.sh. A toggle rather than a
		   select: the script always writes the line, and a missing line means
		   the router sees the bare macro name, never "yes" -- i.e. off, which
		   is what the toggle then shows. */
		'forwarding' => array('title' => 'Forwarding', 'keys' => array(
			'REQAD_NO_FORWARD_SPAM' => array(
				'label' => 'Don\'t forward mails marked as spam', 'type' => 'toggle', 'macro' => true,
				'help'  => 'Mail that SpamAssassin tags as spam (<code>X-Spam-Flag: YES</code>) is not passed on by email forwarders. It is still delivered to the address\'s own mailbox if it has one; for a forward-only address it is discarded without a bounce. Forwarding spam to Gmail or Outlook gets this server\'s IP marked as a spam source, which hurts delivery of all mail sent from it. Nothing is scanned twice — the forwarder reads the verdict given when the message arrived.'),
		)),
		'queue' => array('title' => 'Queue behaviour', 'keys' => array(
			'queue_run_max' => array('label' => 'Parallel queue runners', 'type' => 'number', 'min' => 0, 'max' => 1000),
			'queue_only_load' => array('label' => 'Queue instead of delivering above load', 'type' => 'text', 'pattern' => '#^[0-9\.]{0,10}$#',
				'help' => 'System load average above which incoming mail is queued rather than delivered immediately. Empty = never.'),
			'timeout_frozen_after' => array('label' => 'Discard frozen messages after', 'type' => 'text', 'pattern' => EXIM_TIME_RE,
				'help' => 'e.g. <code>1w</code>. <code>0s</code> keeps frozen messages for ever.'),
			'auto_thaw' => array('label' => 'Retry frozen messages after', 'type' => 'text', 'pattern' => EXIM_TIME_RE,
				'help' => '<code>0s</code> means frozen messages are never retried automatically.'),
			'ignore_bounce_errors_after' => array('label' => 'Give up on undeliverable bounces after', 'type' => 'text', 'pattern' => EXIM_TIME_RE),
		)),
	);

	if ($which === 'dovecot') return array(
		'tls' => array('title' => 'TLS', 'keys' => array(
			'ssl' => array('label' => 'SSL/TLS', 'type' => 'select',
				'choices' => array('required' => 'Required (recommended)', 'yes' => 'Offered but optional', 'no' => 'Disabled'),
				'help' => '<strong>Required</strong> refuses plaintext logins on non-TLS connections.'),
			'ssl_min_protocol' => array('label' => 'Minimum TLS version', 'type' => 'select',
				'choices' => array('TLSv1' => 'TLS 1.0 (insecure)', 'TLSv1.1' => 'TLS 1.1 (insecure)', 'TLSv1.2' => 'TLS 1.2 (recommended)', 'TLSv1.3' => 'TLS 1.3 only'),
				'help' => 'TLS 1.3 only will lock out older mail clients.'),
			'ssl_server_prefer_ciphers' => array('label' => 'Cipher preference', 'type' => 'select',
				'choices' => array('client' => 'Client chooses', 'server' => 'Server chooses')),
			'ssl_cipher_list' => array('label' => 'Cipher list', 'type' => 'text', 'pattern' => '#^[A-Za-z0-9 :\.\!\+\-_@]{0,255}$#',
				'help' => 'Leave empty to use the OpenSSL default.'),
		)),
		'limits' => array('title' => 'Client limits', 'keys' => array(
			'mail_max_userip_connections' => array('label' => 'Connections per user per IP', 'type' => 'number', 'min' => 0, 'max' => 1000,
				'help' => 'Dovecot ships 10. Raise it if users with several devices see "Maximum number of connections" errors.'),
			'imap_idle_notify_interval' => array('label' => 'IMAP IDLE notify interval', 'type' => 'text', 'pattern' => '#^\d{1,6}\s*(secs?|mins?|hours?|s|m|h)?$#',
				'help' => 'How often dovecot pings an idle client, e.g. <code>2 mins</code>.'),
			'imap_max_line_length' => array('label' => 'IMAP max line length', 'type' => 'text', 'pattern' => '#^\d{1,12}\s*[kKmM]?$#'),
			'mail_vsz_limit' => array('label' => 'Memory limit per mail process', 'type' => 'select', 'special' => 'vsz',
				'choices' => array('256M' => '256 MB (dovecot default)', '512M' => '512 MB', '1024M' => '1 GB', '2048M' => '2 GB', '4096M' => '4 GB'),
				'help' => 'Address space a single <code>imap</code>, <code>pop3</code> or <code>indexer-worker</code> process may use. A process that hits the ceiling is <strong>killed mid-session</strong> — the user sees the connection drop while opening a large message or running a search, and the mail log shows <code>child NNN returned error 83 (Out of memory (service imap { vsz_limit=256 MB }))</code>. Raise it if you see that. The full-text indexer is often the process that dies first, and it surfaces on the imap side as <code>Mailbox INBOX: indexer failed to index mailbox</code>, so all three are set together — raising one alone just moves the error somewhere else. This is address space, not RAM: a higher limit does not reserve memory, it stops dovecot refusing the mapping. Large mailboxes, full-text searches and reading compressed mail all push against it. Saving this <strong>restarts dovecot</strong>, because the limit is read by the master process at startup.'),
		)),
		'storage' => array('title' => 'Mail storage', 'keys' => array(
			'mail_compression' => array('label' => 'Compress stored mail', 'type' => 'toggle', 'special' => 'compress',
				'help' => 'Lets dovecot read gzipped messages, so old mail can be stored compressed — typically a 60-70% saving on a mail-heavy account. This switch only makes compressed mail <em>readable</em>; nothing is compressed until a mailbox is listed in <code>etc/mail-archive.conf</code>, which the nightly job reads. Turning it back off is refused while any mailbox still holds compressed mail, because those messages would reach clients as unreadable gzip data.'),
		)),
		'webmail' => array('title' => 'Webmail', 'keys' => array(
			'webmail_autologin' => array('label' => 'Enable webmail auto-login', 'type' => 'toggle', 'special' => 'webmail',
				'help' => 'Adds a <strong>Webmail</strong> button to every row on the Email Accounts page that opens Roundcube already logged in as that mailbox. Works by giving dovecot a <em>master user</em> — a generated password that can authenticate as any mailbox on this server. The password is stored by the panel and never sent to the browser; the button hands out single-use links that expire in 30 seconds.'),
		)),
		'debug' => array('title' => 'Auth troubleshooting', 'warn' => 'These write a great deal to the mail log and are meant to be switched on briefly while diagnosing a login problem. Turn them all off again when you are done.', 'keys' => array(
			'auth_verbose' => array('label' => 'Log failed authentication attempts', 'type' => 'toggle',
				'help' => 'Logs the username and reason for every failed login. The usual first step.'),
			'auth_debug' => array('label' => 'Verbose authentication debug', 'type' => 'toggle',
				'help' => 'Full detail of the auth process, including the passdb lookups.'),
			'auth_debug_passwords' => array('label' => 'Include passwords in the debug log', 'type' => 'toggle',
				'danger' => true,
				'help' => '<strong>Writes submitted passwords and password hashes to the log in clear.</strong> Only ever enable this briefly on a server you control, and rotate the log afterwards.'),
			'mail_debug' => array('label' => 'Mailbox access debug', 'type' => 'toggle',
				'help' => 'Logs how dovecot opens and indexes mailboxes. Useful for "folder not found" problems.'),
		)),
	);

	/* SpamAssassin. `default` is declared here because SpamAssassin has no way
	   to print the value it is running with (see mail_setting_defaults), so the
	   placeholder shown for an unset field comes from this table. Booleans are
	   selects rather than switches: SpamAssassin spells them 1/0, and a switch
	   cannot express the third state, "not in the file, use the default". */
	if ($which === 'spamassassin') return array(
		'scoring' => array('title' => 'Scoring', 'keys' => array(
			'required_score' => array(
				'label' => 'Spam threshold', 'type' => 'text', 'alias' => 'required_hits',
				'pattern' => '#^-?\d{1,3}(\.\d{1,3})?$#', 'default' => '5.0',
				'help' => 'Score at or above which a message is called spam. Lower catches more spam and more legitimate mail; 5 is the SpamAssassin default and what most hosts run.'),
			'rewrite_subject' => array(
				'label' => 'Subject tag', 'type' => 'text', 'line_key' => 'rewrite_header Subject',
				'pattern' => '#^[^\r\n]{1,100}$#',
				'help' => 'Prefixed to the subject of anything scoring above the threshold, e.g. <code>[SPAM]</code>. Written as <code>rewrite_header Subject</code>. Clear it to leave subjects untouched.'),
			'report_safe' => array(
				'label' => 'How spam is delivered', 'type' => 'select', 'default' => '1',
				'choices' => array(
					'0' => 'Unchanged, with X-Spam-* headers only',
					'1' => 'Wrapped in a report, original attached as a message',
					'2' => 'Wrapped in a report, original attached as plain text'),
				'help' => 'Reqad delivers spam to the Junk folder rather than rejecting it, so <strong>0</strong> is usually what you want — the message stays readable and the score is in the headers.'),
		)),
		'networks' => array('title' => 'Networks', 'keys' => array(
			'trusted_networks' => array(
				'label' => 'Trusted networks', 'type' => 'textarea', 'rows' => 3,
				'pattern' => '#^[0-9a-fA-F:\.\*/! \t\n-]*$#', 'maxlen' => 16384, 'oneline' => true,
				'help' => 'Mail relayed through these hosts is not blamed for the Received headers above them, so RBL checks stop firing on your own relays. Space-separated; <code>10.0.0.0/8</code>, <code>192.168.1.1</code> and <code>!1.2.3.4</code> (exclude) are all accepted. Leave empty and SpamAssassin guesses from the Received chain. Do not list email providers here (Gmail, Outlook, ESPs) — that disables SpamAssassin\'s RBL tests on them and awards ALL_TRUSTED; they are scored by the REQAD_ESP_* rules from Trusted senders (skip RBL) instead.'),
			'internal_networks' => array(
				'label' => 'Internal networks', 'type' => 'textarea', 'rows' => 3,
				'pattern' => '#^[0-9a-fA-F:\.\*/! \t\n-]*$#', 'maxlen' => 16384, 'oneline' => true,
				'help' => 'The machines that hand mail <em>to</em> this server — always a subset of trusted networks. Everything outside is treated as the public internet, which is what makes the first external hop identifiable. Empty means the same as trusted networks.'),
		)),
		'checks' => array('title' => 'Checks', 'keys' => array(
			'use_bayes' => array(
				'label' => 'Bayesian filter', 'type' => 'select', 'default' => '1',
				'choices' => array('1' => 'Enabled', '0' => 'Disabled'),
				'help' => 'Per-account statistical filter. It scores nothing until it has learned 200 spam and 200 ham messages for that account.'),
			'bayes_auto_learn' => array(
				'label' => 'Train Bayes automatically', 'type' => 'select', 'default' => '1',
				'choices' => array('1' => 'Enabled', '0' => 'Disabled'),
				'help' => 'Feeds obvious spam and obvious ham back into the filter using the rest of the ruleset as the teacher.'),
			'skip_rbl_checks' => array(
				'label' => 'DNS blocklist rules', 'type' => 'select', 'default' => '0',
				'choices' => array('0' => 'Enabled', '1' => 'Disabled'),
				'help' => 'SpamAssassin\'s own RBL rules, which only add score — separate from the blocklists on the Exim page, which refuse the message outright.'),
			'skip_uribl_checks' => array(
				'label' => 'URI blocklist rules', 'type' => 'select', 'default' => '0',
				'choices' => array('0' => 'Enabled', '1' => 'Disabled'),
				'help' => 'Looks up the domains of links inside the message body.'),
		)),
	);

	return array();
}

/* Flatten the groups to key => definition. */
function mail_setting_keys($which) {
	$out = array();
	foreach (mail_setting_defs($which) as $g)
		foreach ($g['keys'] as $k => $d) $out[$k] = $d;
	return $out;
}

/* The values the daemon is ACTUALLY running with, whether or not they appear in
   the config file. Most of these settings are absent from the file and running
   on a built-in default, so a blank input is not "no value" -- it is "whatever
   the daemon defaults to". Shown as the field's placeholder.

   One batched call per page: `exim -bP a b c` and `doveconf a b c` both accept
   a list, so this costs one process, not one per setting. */
function mail_setting_defaults($which, $keys) {
	$keys = array_values(array_filter((array)$keys, function ($k) {
		return preg_match('/^[a-z0-9_]{1,64}$/', $k);
	}));
	if (!$keys) return array();

	/* SpamAssassin has no "print the effective value of X" mode -- there is no
	   equivalent of `exim -bP` -- so its built-in defaults are declared in the
	   setting definitions instead and read straight out of them. */
	if ($which === 'spamassassin') {
		$map = array();
		foreach (mail_setting_keys($which) as $k => $d)
			if (in_array($k, $keys, true) && isset($d['default']) && $d['default'] !== '')
				$map[$k] = (string)$d['default'];
		return $map;
	}

	$bin = ($which === 'exim') ? 'sudo exim -bP' : 'sudo doveconf';
	$cmd = $bin;
	foreach ($keys as $k) $cmd .= ' '.escapeshellarg($k);
	$out = (string)shell_exec($cmd.' 2>/dev/null');

	$map = array();
	foreach (explode("\n", $out) as $line) {
		if (strpos($line, '=') === false) continue;
		list($k, $v) = explode('=', $line, 2);
		$k = trim($k);
		/* exim prints a bare option name for a false boolean and "no_<name>"
		   for some; neither is a value we want to offer as a placeholder */
		if (isset($map[$k]) || !in_array($k, $keys, true)) continue;
		$map[$k] = trim($v);
	}
	return $map;
}

/* Read one setting's current value out of the config text.

   Main-section exim options and dovecot's local.conf settings both sit at column
   zero; exim's driver options (inside routers/transports) are always indented,
   which is what keeps a `message_size_limit` in a transport from shadowing the
   global one. Hence the deliberate `^` with no leading-whitespace class. */
function mail_setting_read($content, $key, $which = 'exim') {
	if (preg_match('/^'.mail_setting_key_re($key).mail_config_sep_re($which).'(.*)$/m', (string)$content, $m))
		return rtrim($m[1]);
	return null;
}

/* Regex for a setting name at column zero.

   Some settings are named by two words rather than one -- SpamAssassin's
   `rewrite_header Subject` is a `rewrite_header` directive whose first argument
   selects the header -- so any space inside the name matches run-of-whitespace,
   the way the parser itself reads it. */
function mail_setting_key_re($key) {
	return str_replace(' ', '[ \t]+', preg_quote($key, '/'));
}

/* The name this setting is written under in the config file. Normally the form
   field name; `line_key` overrides it where the two cannot be the same (a form
   field name has to be a bare identifier). */
function mail_setting_line_key($key, $def) {
	return (!empty($def['line_key'])) ? $def['line_key'] : $key;
}

/* Exim expresses "minimum TLS version" as a set of openssl_options exclusions
   rather than a version number, so the form's tls_min_version is derived. */
function exim_tls_min_version($content) {
	$o = (string)mail_setting_read($content, 'openssl_options');
	if (strpos($o, '+no_tlsv1_2') !== false) return 'TLSv1.3';
	if (strpos($o, '+no_tlsv1_1') !== false) return 'TLSv1.2';
	if (strpos($o, '+no_tlsv1')   !== false) return 'TLSv1.1';
	return 'TLSv1.0';
}
function exim_tls_min_options($version) {
	$opts = array('+no_sslv2', '+no_sslv3');
	if ($version === 'TLSv1.1' || $version === 'TLSv1.2' || $version === 'TLSv1.3') $opts[] = '+no_tlsv1';
	if ($version === 'TLSv1.2' || $version === 'TLSv1.3')                           $opts[] = '+no_tlsv1_1';
	if ($version === 'TLSv1.3')                                                     $opts[] = '+no_tlsv1_2';
	return implode(' ', $opts);
}

/* Current value of every whitelisted setting, for rendering the form. */
function mail_settings_current($which) {
	$content = (string)mail_config_read($which);
	$out = array();
	foreach (mail_setting_keys($which) as $k => $d) {
		if ($which === 'exim' && $k === 'tls_min_version') { $out[$k] = exim_tls_min_version($content); continue; }
		if (!empty($d['special']) && $d['special'] === 'webmail') { $out[$k] = webmail_autologin_enabled() ? 'yes' : 'no'; continue; }
		/* compress.conf, not local.conf — ask the script that owns it */
		if (!empty($d['special']) && $d['special'] === 'compress') { $out[$k] = mail_compress_enabled() ? 'yes' : 'no'; continue; }
		/* limits.conf, via doveconf -- what dovecot is actually enforcing */
		if (!empty($d['special']) && $d['special'] === 'vsz')      { $st = mail_vsz_status(); $out[$k] = $st['vsz']; continue; }
		$v = mail_setting_read($content, mail_setting_line_key($k, $d), $which);
		/* A key the config file still spells by its deprecated name (SpamAssassin's
		   `required_hits` for `required_score`) is the value in force -- show it,
		   rather than an empty box that invites writing a second, conflicting line. */
		if ($v === null && !empty($d['alias']))
			$v = mail_setting_read($content, $d['alias'], $which);
		if (!empty($d['type']) && $d['type'] === 'toggle') {
			/* exim booleans may be bare (`disable_ipv6`), dovecot's are yes/no */
			if ($v === null)
				$v = preg_match('/^'.mail_setting_key_re(mail_setting_line_key($k, $d)).'\s*$/m', $content) ? 'yes' : 'no';
			else
				$v = in_array(strtolower(trim($v)), array('yes', 'true', '1'), true) ? 'yes' : 'no';
		}
		$out[$k] = ($v === null) ? '' : trim($v);
	}
	return $out;
}

/* Rewrite `key = value` in $content, in place where the key already exists at
   column zero, otherwise inside a clearly marked managed block.

   Placement of the managed block matters for exim: main-section options are only
   legal before the first `begin <section>` line, so appending at EOF would drop
   them inside `begin authenticators` and break the config. Dovecot's local.conf
   has no such sections, so the block goes at the end. */
function mail_setting_write($content, $key, $value, $which) {
	$content = str_replace("\r\n", "\n", (string)$content);
	$line    = $key.mail_config_sep($which).$value;
	$lines   = explode("\n", $content);

	/* Line-based on purpose: values carry exim expansions like
	   ${primary_hostname}, and a preg_replace replacement would read those
	   dollar signs as backreferences and eat them. */
	foreach ($lines as $i => $l) {
		if (mail_setting_line_matches($l, $key, $which)) {
			$lines[$i] = $line;                       // also normalises bare exim booleans
			return implode("\n", $lines);
		}
	}

	$marker = '# --- managed by Reqad ---';
	foreach ($lines as $i => $l) {
		if (trim($l) === $marker) {
			array_splice($lines, $i + 1, 0, array($line));
			return implode("\n", $lines);
		}
	}

	$block = array('', $marker, $line, '');
	if ($which === 'exim') {
		foreach ($lines as $i => $l) {
			if (preg_match('/^begin\s+\w+/', $l)) {
				array_splice($lines, $i, 0, $block);
				return implode("\n", $lines);
			}
		}
	}
	return rtrim(implode("\n", $lines), "\n")."\n".implode("\n", $block);
}

/* Delete a setting's line entirely.

   Used when the user clears a field. Writing `auto_thaw =` instead would be a
   configuration ERROR for exim ("option ... unknown" / missing value), not a
   reset -- clearing a field has to mean "take the line out and let the daemon
   default apply", which is what the placeholder promises. */
function mail_setting_remove($content, $key, $which = 'exim') {
	$content = str_replace("\r\n", "\n", (string)$content);
	$lines   = explode("\n", $content);
	foreach ($lines as $i => $l) {
		if (mail_setting_line_matches($l, $key, $which)) {
			array_splice($lines, $i, 1);
			return implode("\n", $lines);
		}
	}
	return $content;
}

/* Does this line set $key, in $which's syntax? A bare `key` with no value is a
   true boolean for exim, and for SpamAssassin the `key` / `key value` split is
   whitespace, so both are matched with the separator made optional. */
function mail_setting_line_matches($line, $key, $which) {
	return (bool)preg_match('/^'.mail_setting_key_re($key).'([ \t]|=|$)/', $line);
}

/* Validate one posted value against its definition. Returns '' or an error. */
function mail_setting_validate($key, $def, $value) {
	$label = isset($def['label']) ? $def['label'] : $key;
	$type  = isset($def['type']) ? $def['type'] : 'text';

	/* '' means "use the built-in default" for a select too -- the form offers it
	   as an explicit option when the key is absent from the config file, and
	   mail_settings_apply() turns it into a line removal, same as a cleared
	   text field. Without this, merely opening the page and saving would write
	   the first choice into the file as though the admin had picked it. */
	if ($type === 'select')
		return ($value === '' || isset($def['choices'][$value])) ? '' : $label.': not one of the offered values.';

	if ($type === 'toggle')
		return in_array($value, array('yes', 'no'), true) ? '' : $label.': must be yes or no.';

	if ($type === 'number') {
		if ($value === '') return '';
		if (!preg_match('/^\d{1,10}$/', $value)) return $label.': must be a whole number.';
		if (isset($def['min']) && (int)$value < $def['min']) return $label.': must be at least '.$def['min'].'.';
		if (isset($def['max']) && (int)$value > $def['max']) return $label.': must be at most '.$def['max'].'.';
		return '';
	}

	/* An empty free-text field means "unset this and use the daemon default",
	   which mail_settings_apply() turns into a line removal. Running it past the
	   pattern would reject it -- most patterns require at least one character. */
	if ($value === '') return '';

	if (strpos($value, "\n") !== false && $type !== 'textarea')
		return $label.': must be a single line.';
	/* Checked before the pattern. A bounded pattern such as {0,2048} fails on a
	   value that is merely too long, and the caller then reports it as a bad
	   character -- which sent an admin hunting for a stray character in a
	   perfectly good 2.5KB list of networks. Length is its own error. */
	if (isset($def['maxlen']) && strlen($value) > $def['maxlen'])
		return $label.': too long -- '.strlen($value).' characters, the limit is '.$def['maxlen'].'.';
	if (isset($def['pattern']) && !preg_match($def['pattern'], $value))
		return $label.': contains characters that are not allowed here.';
	return '';
}

/* Apply a set of posted settings: validate each, rewrite the file in memory,
   then hand the whole thing to apply_mail_config() so it goes through the same
   backup / preflight / revert path as the raw editor.
   $posted is key => value; unknown keys are ignored, not an error. */
function mail_settings_apply($which, $posted) {
	$defs = mail_setting_keys($which);
	if (!$defs) return array('error' => 'Unknown config file.', 'success' => '');

	$content = mail_config_read($which);
	if ($content === null || $content === '')
		return array('error' => 'Could not read the configuration file.', 'success' => '');

	/* PASS 1 -- validate everything before touching anything.

	   This has to be a separate pass. Some keys have side effects that reach
	   outside the config text (the webmail toggle generates a credential and
	   rewrites local.conf), so validating and applying in one loop meant a form
	   that failed on a LATER field had already run those side effects: a save
	   rejected with "contains characters that are not allowed here" could
	   silently have disabled webmail auto-login on its way to the error. */
	$errors = array();
	$values = array();
	foreach ($defs as $key => $def) {
		if (!array_key_exists($key, $posted)) continue;
		$value = trim(str_replace("\r\n", "\n", (string)$posted[$key]));
		/* These are textareas for room to paste, but they are written back as a
		   single directive line (mail_setting_write builds "key sep value"), so
		   a pasted list one-per-line would emit a broken config whose second
		   line is a bare token. Fold any run of whitespace into one space. */
		if (!empty($def['oneline']))
			$value = trim(preg_replace('/\s+/', ' ', $value));
		$err = mail_setting_validate($key, $def, $value);
		if ($err !== '') { $errors[] = $err; continue; }
		$values[$key] = $value;
	}
	if ($errors)
		return array('error' => implode('<br>', $errors), 'success' => '');

	/* PASS 2 -- apply. Nothing below can fail validation any more. */
	$changed = 0;
	$special_msgs = array();
	$want_compress = null;      // null = the form did not carry this field
	$want_vsz      = null;
	foreach ($defs as $key => $def) {
		if (!array_key_exists($key, $values)) continue;
		$value = $values[$key];

		/* A macro is not an option with a daemon default behind it: the ACL
		   text further down the file reads it BY NAME, so deleting the line --
		   what a cleared field means everywhere else -- leaves exim expanding a
		   token that no longer exists. Put the declared default back instead. */
		if (!empty($def['macro']) && $value === '')
			$value = isset($def['default']) ? (string)$def['default'] : '0';

		/* Also not local.conf: each of these is written in full by the script
		   that owns its file. Collect them and reconcile once after the loop,
		   so a save that changes both does not take dovecot through two
		   restarts. */
		if (!empty($def['special']) && $def['special'] === 'compress') { $want_compress = ($value === 'yes'); continue; }
		if (!empty($def['special']) && $def['special'] === 'vsz')      { $want_vsz = $value; continue; }

		/* not a local.conf key: it owns a generated credential and a passdb
		   block, so it has its own enable/disable path */
		if (!empty($def['special']) && $def['special'] === 'webmail') {
			$want = ($value === 'yes');
			if ($want === webmail_autologin_enabled()) continue;
			$r = $want ? webmail_autologin_enable() : webmail_autologin_disable();
			if ($r['error'] !== '') return $r;
			$special_msgs[] = $r['success'];
			continue;
		}

		if ($which === 'exim' && $key === 'tls_min_version') {
			if ($value === exim_tls_min_version($content)) continue;
			$content = mail_setting_write($content, 'openssl_options', exim_tls_min_options($value), $which);
			$changed++;
			continue;
		}

		$lkey    = mail_setting_line_key($key, $def);
		$current = mail_setting_read($content, $lkey, $which);
		$alias   = !empty($def['alias']) ? $def['alias'] : '';
		if ($current === null && $alias !== '')
			$current = mail_setting_read($content, $alias, $which);
		if (!empty($def['type']) && $def['type'] === 'toggle') {
			$cur = ($current === null)
				? (preg_match('/^'.mail_setting_key_re($lkey).'\s*$/m', $content) ? 'yes' : 'no')
				: (in_array(strtolower(trim($current)), array('yes', 'true', '1'), true) ? 'yes' : 'no');
			if ($cur === $value) continue;
		} elseif ($current !== null && trim($current) === $value) {
			continue;
		} elseif ($value === '' && empty($def['macro'])) {
			/* cleared: drop the line so the daemon default applies again. If it
			   was not in the file to begin with there is nothing to do.
			   Macros are exempt -- an empty one is written as `NAME =`, which
			   is a legal empty value; deleting it is not (see above). */
			if ($current === null) continue;
			$content = mail_setting_remove($content, $lkey, $which);
			if ($alias !== '') $content = mail_setting_remove($content, $alias, $which);
			$changed++;
			continue;
		}

		/* Writing the canonical name while the deprecated one is still in the
		   file leaves two lines setting the same thing, and which one wins is
		   file order. Drop the alias as part of the write. */
		if ($alias !== '') $content = mail_setting_remove($content, $alias, $which);
		$content = mail_setting_write($content, $lkey, $value, $which);
		$changed++;
	}

	/* The memory limit is INDEPENDENT of compression. It used to be handled as
	   part of it, written into compress.conf by the compression helper -- which
	   meant that on a server with compression off this field was read, validated
	   and then silently discarded, because the whole reconciliation went down
	   the "compression stays off" branch and no file was ever written. That is
	   the bug this split fixes: a limit raised on the panel now lands whether or
	   not anything is compressed. '' from the select still means "leave it
	   alone" (dovecot's default is in force and the admin did not pick). */
	/* One exception to doing it here: when compression is being switched ON in
	   the same save, hand the limit to the compression helper instead. It
	   forwards the value to the very same script with --no-restart and picks the
	   restart up itself, so a save that changes both settings still costs one
	   dovecot restart rather than two -- restarting twice drops every IMAP
	   connection twice for a single click. */
	$vsz_via_compress = ($want_vsz !== null && $want_vsz !== ''
	                     && $want_compress === true && !mail_compress_enabled());

	if ($want_vsz !== null && $want_vsz !== '' && !$vsz_via_compress) {
		$st = mail_vsz_status();
		/* !uniform means the three services disagree, so "the value is already
		   what you picked" is only true of imap -- write anyway and bring the
		   rest into line. */
		if ($want_vsz !== $st['vsz'] || !$st['uniform']) {
			$r = mail_vsz_set($want_vsz);
			if ($r['error'] !== '') return $r;
			$special_msgs[] = $r['success'];
		}
	}

	/* Compression owns compress.conf only. Enabling it still makes sure the
	   limit above is not left at a value decompression makes inadequate -- the
	   helper asks setup_dovecot_vsz.sh for that, rather than writing a second
	   copy of the setting into its own file. */
	if ($want_compress !== null) {
		$on = mail_compress_enabled();
		if ($want_compress !== $on) {
			$r = $want_compress ? mail_compress_enable() : mail_compress_disable();
			if ($r['error'] !== '') return $r;
			$special_msgs[] = $r['success'];
		}
	}

	if (!$changed)
		return array('error' => '', 'success' => $special_msgs
			? implode(' ', $special_msgs)
			: 'No changes to save.');

	$r = apply_mail_config($which, $content);
	if ($r['error'] === '' && $special_msgs)
		$r['success'] = implode(' ', $special_msgs).' '.$r['success'];
	return $r;
}

/* ---- Exim outbound sending limits ---------------------------------------

   The counters are exim's own ratelimit hints DB, written by the ACL rules
   scripts/update/setup_mail_limits.sh installs. The panel keeps no count of
   its own -- it reads and clears exim's, through the helper, because the DB is
   exim:exim and its format is exim's business.

   What `rate` means: ratelimit keeps an exponentially smoothed average over
   the period, not a tally. Two messages back to back read as 1.92, not 2, and
   the number decays as the hour passes. It is the same figure the ACL compares
   against the limit, so it is what is actually being enforced -- but do not
   present it as "messages sent". */

define('LIMITS_HELPER', _PATH.'/scripts/mail/limits-helper.sh');

function exim_limits_helper($args) {
	if (!is_file(LIMITS_HELPER)) return null;
	$out = shell_exec('sudo -n '.escapeshellarg(LIMITS_HELPER).' '.$args.' 2>/dev/null');
	return ($out === null) ? null : (string)$out;
}

/* Is the managed block in exim.conf at all? The settings form is meaningless
   without it -- the macros would be edited and nothing would read them. */
function exim_limits_installed() {
	static $known = null;
	if ($known === null)
		$known = (trim((string)exim_limits_helper('status')) === 'installed');
	return $known;
}

/* domain => array('hourly' => rate|null, 'failures' => rate|null, 'updated' => text),
   sorted with the busiest first. */
function exim_limits_counters() {
	$out  = (string)exim_limits_helper('list');
	$rows = array();
	foreach (explode("\n", $out) as $line) {
		if (trim($line) === '') continue;
		$f = explode("\t", $line);
		if (count($f) < 4) continue;
		list($dom, $kind, $rate, $when) = $f;
		if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9\.\-]{0,252}$/', $dom)) continue;
		if ($kind !== 'hourly' && $kind !== 'failures') continue;
		if (!isset($rows[$dom]))
			$rows[$dom] = array('hourly' => null, 'failures' => null, 'updated' => '');
		$rows[$dom][$kind] = (float)$rate;
		/* the later of the two timestamps is when this domain was last seen */
		if (strtotime($when) > strtotime($rows[$dom]['updated'])) $rows[$dom]['updated'] = $when;
	}
	uasort($rows, function ($a, $b) {
		$x = max((float)$a['hourly'], (float)$a['failures']);
		$y = max((float)$b['hourly'], (float)$b['failures']);
		if ($x == $y) return 0;
		return ($x < $y) ? 1 : -1;
	});
	return $rows;
}

/* Clear one counter, or every counter when $domain is ''. $kind is the panel's
   word, never a database key -- the helper builds the key itself. */
function exim_limits_reset($kind, $domain) {
	if ($domain === '' && $kind === '') {
		exim_limits_helper('reset-all');
		return array('error' => '', 'success' => 'All sending counters cleared.');
	}
	if ($kind !== 'hourly' && $kind !== 'failures')
		return array('error' => 'Error: unknown counter.', 'success' => '');
	if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9\.\-]{0,252}$/', (string)$domain))
		return array('error' => 'Error: not a valid domain.', 'success' => '');

	$out = shell_exec('sudo -n '.escapeshellarg(LIMITS_HELPER).' reset '
	                  .escapeshellarg($kind).' '.escapeshellarg($domain).' 2>&1');
	$out = trim((string)$out);
	if ($out !== '')
		return array('error' => 'Error: '.h($out), 'success' => '');
	return array('error' => '',
	             'success' => ($kind === 'hourly' ? 'Hourly' : 'Failure').' counter cleared for '.h($domain).'.');
}

/* ---- Exim DNS blocklists (RBLs) -----------------------------------------
   The two uncommented `dnslists =` lines in exim.conf are, in order, the one in
   the authenticated-sender ACL and the one in the RCPT ACL. They are edited by
   position rather than by name because exim ACL conditions have no identifiers. */

function exim_dnslists_read() {
	$content = (string)mail_config_read('exim');
	$out = array();
	foreach (explode("\n", $content) as $n => $line) {
		if (!preg_match('/^(\s*)dnslists(\s*)=(\s*)(.*)$/', $line, $m)) continue;
		if (preg_match('/^\s*#/', $line)) continue;                   // commented-out example
		$hosts = array();
		foreach (explode(':', $m[4]) as $h) {
			$h = trim($h);
			if ($h !== '') $hosts[] = $h;
		}
		$out[] = array('line' => $n, 'indent' => $m[1], 'hosts' => $hosts);
	}
	return $out;
}

/* Blocklists that stopped answering years ago. A dead RBL does not fail open
   quietly — every lookup waits for a DNS timeout, on every message. */
function exim_dnslist_dead() {
	return array('relays.ordb.org', 'list.dsbl.org', 'sbl-xbl.spamhaus.org', 'dnsbl.njabl.org', 'bl.csma.biz');
}

function exim_dnslist_valid_host($h) {
	return (bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9\.\-_]{0,253}$/', (string)$h);
}

/* Replace list number $idx (0-based, in the order exim_dnslists_read returns)
   with $hosts, then save through the normal validate/backup/revert path. */
function exim_dnslists_apply($idx, $hosts) {
	$lists = exim_dnslists_read();
	$idx   = (int)$idx;
	if (!isset($lists[$idx]))
		return array('error' => 'That blocklist no longer exists in the configuration.', 'success' => '');

	$clean = array();
	foreach ((array)$hosts as $h) {
		$h = trim((string)$h);
		if ($h === '') continue;
		if (!exim_dnslist_valid_host($h))
			return array('error' => 'Not a valid blocklist hostname: '.h($h), 'success' => '');
		if (!in_array($h, $clean, true)) $clean[] = $h;
	}

	$content = str_replace("\r\n", "\n", (string)mail_config_read('exim'));
	$lines   = explode("\n", $content);
	$target  = $lists[$idx]['line'];
	if (!isset($lines[$target]))
		return array('error' => 'Could not locate the blocklist line.', 'success' => '');

	if (!$clean) {
		/* an empty list is not valid exim syntax — comment the condition out
		   instead, which is how you disable an ACL condition by hand too */
		$lines[$target] = $lists[$idx]['indent'].'# dnslists =   (disabled by Reqad)';
	} else {
		$lines[$target] = $lists[$idx]['indent'].'dnslists = '.implode(' : ', $clean);
	}

	return apply_mail_config('exim', implode("\n", $lines));
}


/* ---- Skip-RBL: senders exempt from the blocklist checks -------------------
   A DNS blocklist listing is evidence, not proof, and the large ESPs get
   listed routinely -- one compromised customer is enough to put a shared
   outbound range on a list for a day. Rejecting on that means losing real
   mail from Gmail or Microsoft 365, which is a worse failure than accepting
   some spam, so their ranges are exempted from the lookup.

   /etc/exim/skiprblhosts is generated by scripts/mail/build-skiprbl.sh from
   the ESP catalogue in etc/skiprbl-providers.ini plus the addresses kept here
   in the settings table. The file is output, never input: nothing reads a
   value back out of it, so a hand edit is simply lost on the next run. */

if (!defined('SKIPRBL_FILE'))    define('SKIPRBL_FILE',    '/etc/exim/skiprblhosts');
if (!defined('SKIPRBL_INI'))     define('SKIPRBL_INI',     _PATH.'/etc/skiprbl-providers.ini');
if (!defined('SKIPRBL_BUILDER')) define('SKIPRBL_BUILDER', _PATH.'/scripts/mail/build-skiprbl.sh');

/* The ESP catalogue, in file order. */
function skiprbl_providers() {
	if (!is_readable(SKIPRBL_INI)) return array();
	$ini = @parse_ini_file(SKIPRBL_INI, true, INI_SCANNER_RAW);
	if (!is_array($ini)) return array();
	$out = array();
	foreach ($ini as $key => $sec) {
		if (!is_array($sec)) continue;
		/* INI_SCANNER_RAW is mandatory here: PHP's default ini scanner treats
		   `off` as a reserved word and hands back "", which would read as "on"
		   and silently exempt a provider shipped switched off. RAW keeps any
		   quotes the value was written with, so strip those. */
		$dflt = isset($sec['default']) ? strtolower(trim($sec['default'], " \t\"'")) : '';
		$out[$key] = array(
			'key'     => $key,
			'label'   => isset($sec['label']) && $sec['label'] !== '' ? trim($sec['label']) : $key,
			'spf'     => isset($sec['spf']) ? trim($sec['spf']) : '',
			/* `default = off` ships a provider switched off -- a known spam
			   source nobody wants exempted, catalogued so it can be seen and
			   turned on deliberately rather than left out and forgotten. */
			'default' => in_array($dflt, array('off', 'no', '0', 'false', 'disabled'), true) ? 'off' : 'on',
		);
	}
	return $out;
}

function skiprbl_disabled() {
	$raw = setting_get('skiprbl-disabled', '');
	$out = array();
	foreach (preg_split('/[\s,]+/', $raw) as $k) {
		$k = trim($k);
		if ($k !== '') $out[] = $k;
	}
	return $out;
}

function skiprbl_enabled_keys() {
	$raw = setting_get('skiprbl-enabled', '');
	$out = array();
	foreach (preg_split('/[\s,]+/', $raw) as $k) {
		$k = trim($k);
		if ($k !== '') $out[] = $k;
	}
	return $out;
}

/* Three-way, matching provider_is_on() in build-skiprbl.sh: an explicit choice
   wins, otherwise the catalogue's default decides. Storing both lists rather
   than just the off one is what stops a preference saved today from switching
   on a provider a later update adds to the catalogue. */
function skiprbl_is_on($key, $prov, $off = null, $on = null) {
	if ($off === null) $off = skiprbl_disabled();
	if ($on  === null) $on  = skiprbl_enabled_keys();
	if (in_array($key, $off, true)) return false;
	if (in_array($key, $on,  true)) return true;
	return !(isset($prov['default']) && $prov['default'] === 'off');
}

function skiprbl_extra() {
	return (string)setting_get('skiprbl-extra', '');
}

/* One address per line, with an optional "# note". Exim ignores everything
   from a # to end of line in a list file, so the note rides along into the
   live list -- which is the whole point: the flat file this replaced had no
   room to say why any address was in it. */
function skiprbl_valid_entry($addr) {
	$addr = trim((string)$addr);
	if ($addr === '') return false;
	if (strpos($addr, ':') !== false) {                       // IPv6
		if (!preg_match('#^([0-9A-Fa-f:]+)(?:/(\d{1,3}))?$#', $addr, $m)) return false;
		if (isset($m[2]) && $m[2] !== '' && (int)$m[2] > 128)  return false;
		return (bool)@inet_pton($m[1]);
	}
	if (!preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})(?:/(\d{1,2}))?$#', $addr, $m)) return false;
	if (isset($m[2]) && $m[2] !== '' && (int)$m[2] > 32) return false;
	foreach (explode('.', $m[1]) as $o)
		if ((int)$o > 255) return false;
	return true;
}

/* Split the textarea into validated "addr" / "addr  # note" lines, or report
   the first line that is not an address. */
function skiprbl_clean_extra($raw, &$error) {
	$error = '';
	$out   = array();
	$seen  = array();
	foreach (preg_split('/\r\n|\r|\n/', (string)$raw) as $line) {
		$addr = $line;
		$note = '';
		$hash = strpos($line, '#');
		if ($hash !== false) {
			$addr = substr($line, 0, $hash);
			$note = trim(substr($line, $hash + 1));
		}
		$addr = trim($addr);
		if ($addr === '') continue;
		if (!skiprbl_valid_entry($addr)) {
			$error = 'Not a valid IP address or CIDR range: '.$addr;
			return array();
		}
		if (isset($seen[$addr])) continue;
		$seen[$addr] = true;
		/* a note must not carry a newline back into a one-item-per-line file */
		$note = trim(preg_replace('/\s+/', ' ', $note));
		$out[] = ($note !== '') ? $addr.'  # '.$note : $addr;
	}
	return $out;
}

/* Parse the generated file back into groups, so the panel reports what exim is
   actually serving rather than what we think we generated. */
function skiprbl_groups() {
	if (!is_readable(SKIPRBL_FILE)) return array();
	$out   = array();
	$label = '';
	foreach (file(SKIPRBL_FILE, FILE_IGNORE_NEW_LINES) as $line) {
		if (preg_match('/^#\s*===\s*(.+?)\s*===/', $line, $m)) {
			$label = $m[1];
			if (!isset($out[$label])) $out[$label] = array();
			continue;
		}
		if ($label === '') continue;
		if (preg_match('/^\s*(?:#|$)/', $line)) continue;
		$out[$label][] = trim($line);
	}
	return $out;
}

/* Header facts: when it was built and how much of it is provider data. */
function skiprbl_meta() {
	$meta = array('generated' => '', 'esp' => 0, 'providers' => '', 'exists' => false, 'total' => 0);
	if (!is_readable(SKIPRBL_FILE)) return $meta;
	$meta['exists'] = true;
	foreach (file(SKIPRBL_FILE, FILE_IGNORE_NEW_LINES) as $line) {
		if (preg_match('/^#\s*Generated\s+(.+)$/', $line, $m))    $meta['generated'] = trim($m[1]);
		elseif (preg_match('/^#\s*esp-entries:\s*(\d+)/', $line, $m)) $meta['esp'] = (int)$m[1];
		elseif (preg_match('/^#\s*providers:\s*(.+)$/', $line, $m))   $meta['providers'] = trim($m[1]);
		elseif (!preg_match('/^\s*(?:#|$)/', $line))                  $meta['total']++;
	}
	return $meta;
}

/* Regenerate. Slow -- it walks a few dozen SPF records over DNS -- so callers
   run it from a POST, never from a page render. */
function skiprbl_rebuild($force = false) {
	if (!is_file(SKIPRBL_BUILDER))
		return array('error' => 'Builder script not installed: '.SKIPRBL_BUILDER, 'success' => '');

	$cmd = 'sudo -n '.escapeshellarg(SKIPRBL_BUILDER).($force ? ' --force' : '').' 2>&1';
	$out = array(); $rc = 0;
	exec($cmd, $out, $rc);
	$txt = trim(implode("\n", $out));

	if ($rc === 2)
		return array('error' => "The rebuilt list was rejected as implausible, so the live one was kept:\n".$txt, 'success' => '');
	if ($rc !== 0)
		return array('error' => "Rebuild failed:\n".($txt !== '' ? $txt : 'exit status '.$rc), 'success' => '');

	$meta = skiprbl_meta();
	return array('error' => '', 'success' => 'Skip-RBL list rebuilt: '.$meta['total'].' addresses ('.$meta['providers'].' providers).');
}

/* --- the exim side -------------------------------------------------------
   The exemption is a plain `!hosts =` condition placed immediately BEFORE the
   `dnslists =` it guards. Order is load-bearing: exim evaluates ACL conditions
   as written, so in front of the lookup a known sender costs one local file
   match, while behind it the DNS round trips happen first and are then thrown
   away. */

function skiprbl_exim_marker() {
	return '# exempt known senders before the lookup (Reqad: skip-RBL)';
}

/* Returns total uncommented dnslists conditions and how many are guarded. */
function skiprbl_exim_status($content = null) {
	if ($content === null) $content = (string)mail_config_read('exim');
	$lines   = explode("\n", str_replace("\r\n", "\n", $content));
	$total   = 0;
	$guarded = 0;
	$prev_guard = false;
	foreach ($lines as $l) {
		if (preg_match('/^\s*!\s*hosts\s*=\s*'.preg_quote(SKIPRBL_FILE, '/').'\s*$/', $l)) {
			$prev_guard = true;
			continue;
		}
		if (preg_match('/^\s*#/', $l)) continue;              // comments do not break the pairing
		if (preg_match('/^\s*dnslists\s*=/', $l)) {
			$total++;
			if ($prev_guard) $guarded++;
		}
		$prev_guard = false;
	}
	return array('total' => $total, 'guarded' => $guarded);
}

/* Add the guard to every dnslists condition, or take every guard out again.
   Goes through apply_mail_config(), so the result is checked with `exim -bV`
   and reverted if exim dislikes it. */
function skiprbl_exim_apply($enable) {
	$content = str_replace("\r\n", "\n", (string)mail_config_read('exim'));
	if ($content === '')
		return array('error' => 'Could not read the exim configuration.', 'success' => '');

	$marker    = skiprbl_exim_marker();
	$guard_re  = '/^\s*!\s*hosts\s*=\s*'.preg_quote(SKIPRBL_FILE, '/').'\s*$/';
	$stripped  = array();

	/* always start from a clean slate, so re-running never stacks guards */
	foreach (explode("\n", $content) as $l) {
		if (preg_match($guard_re, $l))   continue;
		if (trim($l) === $marker)        continue;
		$stripped[] = $l;
	}

	$lines = $stripped;
	if ($enable) {
		if (!is_readable(SKIPRBL_FILE))
			return array('error' => 'The list is not built yet: '.SKIPRBL_FILE.' does not exist. Rebuild it first.', 'success' => '');
		$lines = array();
		foreach ($stripped as $l) {
			if (preg_match('/^(\s*)dnslists\s*=/', $l, $m) && !preg_match('/^\s*#/', $l)) {
				$lines[] = $m[1].$marker;
				$lines[] = $m[1].'!hosts = '.SKIPRBL_FILE;
			}
			$lines[] = $l;
		}
	}

	$new = implode("\n", $lines);
	if ($new === $content)
		return array('error' => '', 'success' => $enable
			? 'Every blocklist check already consults the skip-RBL list.'
			: 'No blocklist check was consulting the skip-RBL list.');

	$r = apply_mail_config('exim', $new);
	if ($r['error'] === '') {
		$st = skiprbl_exim_status($new);
		$r['success'] = $enable
			? 'Skip-RBL list applied to '.$st['total'].' blocklist check'.($st['total'] === 1 ? '' : 's').'.'
			: 'Skip-RBL exemption removed from every blocklist check.';
	}
	return $r;
}

/* ---- Webmail auto-login (dovecot master user + Roundcube) ----------------
   Opens Roundcube already logged in as any mailbox, without knowing the user's
   password. Dovecot gets a master user, which may authenticate as any mailbox;
   Roundcube binds with SASL PLAIN proxy auth (authzid = the mailbox, authcid =
   the master user), so the session username stays the plain address.

   The master password is a skeleton key for every mailbox on the server. It is
   generated here, never chosen; only its hash reaches /etc/dovecot/master-users;
   and it never reaches the browser — the panel hands out single-use tickets
   instead (webmail_ticket_create / webmail_ticket_consume below). */

if (!defined('WEBMAIL_HELPER'))
	define('WEBMAIL_HELPER', _PATH.'/scripts/mail/webmail-helper.sh');
if (!defined('WEBMAIL_MASTER_USER'))
	define('WEBMAIL_MASTER_USER', 'reqad-master');
/* A ticket is redeemed by the immediately following page load, so this only has
   to cover a redirect. Short is the point. */
if (!defined('WEBMAIL_TICKET_TTL'))
	define('WEBMAIL_TICKET_TTL', 30);

function webmail_helper($args, &$rc = null) {
	$cmd = 'sudo -n '.WEBMAIL_HELPER;
	foreach ((array)$args as $a)
		$cmd .= ' '.escapeshellarg($a);
	$cmd .= ' 2>&1';
	$out = array(); $rc = 0;
	exec($cmd, $out, $rc);
	return trim(implode("\n", $out));
}

function webmail_setting($name) {
	global $db;
	$stmt = $db->prepare('SELECT value FROM settings WHERE name = :n');
	$stmt->bindValue(':n', $name, SQLITE3_TEXT);
	$res = $stmt->execute();
	$row = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;
	return (is_array($row) && isset($row['value'])) ? (string)$row['value'] : '';
}

/* On when the master-user file exists AND we still hold the password that goes
   with it — one without the other cannot log anybody in. */
function webmail_autologin_enabled() {
	if (webmail_setting('webmail-master-pass') === '') return false;
	return (webmail_helper(array('status'), $rc) === 'enabled' && $rc === 0);
}

/* The dovecot passdb block that makes the master user real. Named block with an
   explicit driver: dovecot 2.4 rejects `passdb passwd-file <name> {`, and an
   unnamed second passwd-file block would collide with the mailbox one. */
function webmail_passdb_block() {
	return
		"\n# --- webmail auto-login (managed by Reqad) ---\n".
		"passdb reqad_master {\n".
		"  driver = passwd-file\n".
		"  passwd_file_path = /etc/dovecot/master-users\n".
		"  default_password_scheme = SHA512-CRYPT\n".
		"  master = yes\n".
		"  result_success = continue-ok\n".
		"}\n";
}

/* Add or remove the block from local.conf content. */
function webmail_passdb_strip($content) {
	return preg_replace(
		'/\n?# --- webmail auto-login \(managed by Reqad\) ---\npassdb reqad_master \{.*?\n\}\n/s',
		"\n", (string)$content);
}

/* Where Roundcube actually lives. NOT public_html/roundcubemail -- that is a
   stale unserved copy on some installs; the served tree is the one nginx aliases
   /webmail/ to. */
if (!defined('WEBMAIL_RC_DIR'))
	define('WEBMAIL_RC_DIR', '/usr/local/reqad/roundcubemail');

/* Is the Roundcube half of auto-login in place?

   Two separate things have to be true -- the plugin file has to exist AND be
   listed in $config['plugins'] -- and a half-installed state looks exactly like
   a working one from the panel: the button appears, the ticket is minted, and
   the user just lands on the login form with no explanation. So report the
   state precisely rather than as a boolean.

   Returns array(state, ok, message) where state is:
     ok | no-roundcube | missing | not-enabled */
function webmail_plugin_status() {
	$cfg = WEBMAIL_RC_DIR.'/config/config.inc.php';
	if (!is_dir(WEBMAIL_RC_DIR) || !is_file($cfg))
		return array('state' => 'no-roundcube', 'ok' => false,
		             'message' => 'Roundcube is not installed on this server, so the Webmail buttons will have nothing to open.');

	if (!is_file(WEBMAIL_RC_DIR.'/plugins/reqad_autologin/reqad_autologin.php'))
		return array('state' => 'missing', 'ok' => false,
		             'message' => 'The Roundcube auto-login plugin is not installed.');

	$conf = (string)@file_get_contents($cfg);
	if (strpos($conf, 'reqad_autologin') === false)
		return array('state' => 'not-enabled', 'ok' => false,
		             'message' => 'The Roundcube auto-login plugin is installed but not enabled in config.inc.php.');

	return array('state' => 'ok', 'ok' => true, 'message' => '');
}

/* Place the plugin and register it in Roundcube's config.

   Roundcube is excluded from the RPM, so the plugin cannot ship in its plugins
   directory -- normally scripts/update/install-webmail-autologin.sh places it
   from the packaged copy during post-install. That misses a server where
   Roundcube was installed AFTER the panel, so enabling the feature runs the
   same script again; it is idempotent. */
function webmail_plugin_install(&$out = null) {
	$out = webmail_helper_run('sudo -n '.escapeshellarg(_PATH.'/scripts/update/install-webmail-autologin.sh'), $rc);
	$st  = webmail_plugin_status();
	return $st['ok'];
}

/* exec() wrapper shared by the webmail helpers. */
function webmail_helper_run($cmd, &$rc = null) {
	$out = array(); $rc = 0;
	exec($cmd.' 2>&1', $out, $rc);
	return trim(implode("\n", $out));
}

function webmail_autologin_enable() {
	$pass = bin2hex(random_bytes(24));
	$hash = crypt($pass, '$6$'.substr(bin2hex(random_bytes(8)), 0, 16).'$');
	if (!is_string($hash) || substr($hash, 0, 3) !== '$6$')
		return array('error' => 'Could not hash the master password on this system.', 'success' => '');

	$out = webmail_helper(array('enable', $hash), $rc);
	if ($rc !== 0)
		return array('error' => 'Could not write the master user file: '.$out, 'success' => '');

	/* the passdb block goes through the normal config path, so a bad edit is
	   caught by doveconf and reverted instead of taking dovecot down */
	$content = webmail_passdb_strip(mail_config_read('dovecot')).webmail_passdb_block();
	$r = apply_mail_config('dovecot', $content);
	if ($r['error'] !== '') {
		webmail_helper(array('disable'));
		return $r;
	}

	setting_put('webmail-master-pass', $pass);

	/* Dovecot is now ready; make sure Roundcube is too, or the button will mint
	   tickets that land on the login form with nothing to explain why. */
	$msg = 'Webmail auto-login enabled.';
	$st  = webmail_plugin_status();
	if (!$st['ok'] && $st['state'] !== 'no-roundcube') {
		webmail_plugin_install($out);
		$st = webmail_plugin_status();
	}
	if (!$st['ok'])
		$msg .= ' Note: '.$st['message'];

	return array('error' => '', 'success' => $msg);
}

function webmail_autologin_disable() {
	$content = webmail_passdb_strip(mail_config_read('dovecot'));
	$r = apply_mail_config('dovecot', $content);
	/* remove the credential even if dovecot complained — leaving a live master
	   password behind is worse than leaving a stale config block */
	webmail_helper(array('disable'));
	setting_put('webmail-master-pass', '');
	webmail_tickets_purge(0);
	if ($r['error'] !== '') return $r;
	return array('error' => '', 'success' => 'Webmail auto-login disabled.');
}

/* Drop tickets older than $ttl seconds (0 = all of them). */
function webmail_tickets_purge($ttl = WEBMAIL_TICKET_TTL) {
	global $db;
	$db->exec('DELETE FROM webmail_tickets WHERE created < '.(time() - (int)$ttl));
}

/* Mint a single-use ticket for $email. Returns the token, or '' if the address
   is not a real mailbox.

   The ticket carries the panel session that asked for it (db/1046.sql), and the
   Roundcube plugin keeps the mailbox logged in only while that session lives:
   logging out of the panel, or the session being revoked or timing out, logs
   the webmail out too. Basic-auth mode has no panel sessions, so it stays ''.
   $session_id: the session to bind it to instead of this request's own (the
   account panel on 2083 passes its account session). */
function webmail_ticket_create($email, $session_id = null) {
	global $db;
	$email = strtolower(trim((string)$email));
	if (!valid_email_address($email)) return '';
	if (!in_array($email, mailbox_list(), true)) return '';

	webmail_tickets_purge(300);        // opportunistic prune, no cron needed

	$token = bin2hex(random_bytes(16));
	if ($session_id !== null) {
		$sid = (string)$session_id;
		if (!preg_match('/^[0-9a-f]{64}$/', $sid)) return '';
	} else {
		$sid = (function_exists('auth_web_enabled') && auth_web_enabled() && ($s = auth_session_current())) ? (string)$s['id'] : '';
		if (function_exists('auth_web_enabled') && auth_web_enabled() && $sid === '') return '';
	}

	$stmt = $db->prepare('INSERT INTO webmail_tickets (token, email, created, session_id) VALUES (:t, :e, :c, :s)');
	if (!$stmt) return '';             // migration 1046 not applied yet
	$stmt->bindValue(':t', $token, SQLITE3_TEXT);
	$stmt->bindValue(':e', $email, SQLITE3_TEXT);
	$stmt->bindValue(':c', time(), SQLITE3_INTEGER);
	$stmt->bindValue(':s', $sid, SQLITE3_TEXT);
	return $stmt->execute() ? $token : '';
}

/* Redeem a ticket: returns the address, or '' if unknown or expired. The row is
   deleted whether or not it was still valid, so a token is good exactly once. */
function webmail_ticket_consume($token) {
	global $db;
	$token = (string)$token;
	if (!preg_match('/^[0-9a-f]{32}$/', $token)) return '';

	$stmt = $db->prepare('SELECT email, created FROM webmail_tickets WHERE token = :t');
	$stmt->bindValue(':t', $token, SQLITE3_TEXT);
	$res = $stmt->execute();
	$row = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;

	$del = $db->prepare('DELETE FROM webmail_tickets WHERE token = :t');
	$del->bindValue(':t', $token, SQLITE3_TEXT);
	$del->execute();

	if (!is_array($row)) return '';
	if ((time() - (int)$row['created']) > WEBMAIL_TICKET_TTL) return '';
	return (string)$row['email'];
}

/* ---- Addon domains -------------------------------------------------------- */

/* Additional domains added to an existing account with scripts/adddomain. They
   have no row in the accounts table (it has unique indexes on both `user` and
   `domain`), so the vhost file IS the inventory: every file the tool writes
   starts with the marker comment matched by addon_domain_marker(). Reading is
   enough — /etc/{nginx,httpd}/conf.d is world-readable — but every write goes
   through sudo, like the rest of the panel. */

function addon_vhost_dir($ini) {
	return web_server(web_front($ini))['dir'];
}

function addon_vhost_path($ini, $domain) {
	return addon_vhost_dir($ini).'/'.$domain.'.conf';
}

/* The account username stamped in the vhost header, or '' when the file is not
   an addon-domain vhost (a main account vhost, or anything hand-written). The
   marker survives certbot rewriting the file — it stays on line 1. */
function addon_domain_marker($content) {
	if (preg_match('/^#\s*Additional domain of account\s+([a-z][a-z0-9]{1,15})\b.*adddomain/mu', (string)$content, $m))
		return $m[1];
	return '';
}

/* php-fpm pool path for $domain under a given PHP version (default version lives
   in /etc/php-fpm.d, the remi SCLs under /etc/opt/remi/phpNN). */
function addon_pool_path($ini, $domain, $version) {
	if ($version === $ini['php'])
		return '/etc/php-fpm.d/'.$domain.'.conf';
	return '/etc/opt/remi/php'.str_replace('.', '', $version).'/php-fpm.d/'.$domain.'.conf';
}

function addon_fpm_service($ini, $version) {
	if ($version === $ini['php'])
		return 'php-fpm.service';
	return 'php'.str_replace('.', '', $version).'-php-fpm.service';
}

/* Socket the addon domain's pool listens on. Per-domain, because the account's
   main domain already owns /run/php-fpm-<user>.sock. */
function addon_socket($user, $domain) {
	return '/run/php-fpm-'.$user.'-'.$domain.'.sock';
}

/* Which PHP version/handler serves $domain: array(version, handler, pool).
   version and handler are 'off' when PHP is turned off (the PHP-off block of
   account_php_disable() in the vhost with the PHP handler, the backend's if
   any), handler is 'fpm' when a pool file exists, 'mod_php' on apache when
   none does (apache falls back to the module, which is always the default
   PHP), and 'none' on nginx with no pool — a broken state PHP requests would
   502 on. */
function addon_domain_php($ini, $domain) {
	$vhost = web_vhost_path($domain, web_php_server($ini));
	if (strpos((string)@file_get_contents($vhost), '# BEGIN reqad-php-disabled') !== false)
		return array('version' => 'off', 'handler' => 'off', 'pool' => null);
	$php_versions = array_map('trim', explode(',', $ini['php_versions'] ?? ''));
	foreach ($php_versions as $pv) {
		$pool = addon_pool_path($ini, $domain, $pv);
		if (is_file($pool))
			return array('version' => $pv, 'handler' => 'fpm', 'pool' => $pool);
	}
	return array(
		'version' => $ini['php'],
		'handler' => php_mode($ini) === 'modphp' ? 'mod_php' : 'none',
		'pool'    => null,
	);
}

/* Every addon domain on this server, sorted by domain. */
function addon_domain_list($ini) {
	$list = array();
	foreach ((array)glob(addon_vhost_dir($ini).'/*.conf') as $file) {
		$content = @file_get_contents($file);
		if ($content === false) continue;
		$user = addon_domain_marker($content);
		if ($user === '') continue;

		$domain = basename($file, '.conf');
		$docroot = '';
		if (preg_match('/^\s*DocumentRoot\s+"?([^"\s]+)"?/mi', $content, $d))
			$docroot = $d[1];
		elseif (preg_match('/^\s*root\s+([^;\s]+)\s*;/mi', $content, $d))
			$docroot = $d[1];

		$created = '';
		if (preg_match('/adddomain on\s+([0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2})/', $content, $c))
			$created = $c[1];
		else
			$created = date('Y-m-d H:i:s', @filemtime($file));

		$php = addon_domain_php($ini, $domain);
		$list[$domain] = array(
			'domain'  => $domain,
			'user'    => $user,
			'docroot' => $docroot,
			'version' => $php['version'],
			'handler' => $php['handler'],
			'pool'    => $php['pool'],
			'vhost'   => $file,
			'created' => $created,
			'ssl'     => addon_domain_ssl_kind($domain, $content),
		);
	}
	ksort($list);
	return $list;
}

/* What certificate an addon domain's vhost serves: 'letsencrypt', 'self-signed',
   'custom' (any other: one installed by hand or brought by a transfer) or ''.
   Read from the certificate line of the vhost, not from which files exist:
   every new site gets a self-signed <domain>.crt that stays after a real
   certificate (<domain>_newcert.pem) is put in its place. */
function addon_domain_ssl_kind($domain, $content) {
	if (!preg_match('/^[ \t]*(?:ssl_certificate|SSLCertificateFile)[ \t]+"?([^";\s]+)/m', (string)$content, $m))
		return is_file('/etc/letsencrypt/live/'.$domain.'/cert.pem') ? 'letsencrypt'
		       : (is_file('/etc/ssl/certs/'.$domain.'.crt') ? 'self-signed' : '');
	if (strpos($m[1], '/etc/letsencrypt/') === 0)
		return 'letsencrypt';
	$pem = @file_get_contents($m[1]);   // /etc/ssl/certs is world-readable; the keys are not read
	$c = $pem !== false && strpos($pem, '-----BEGIN CERTIFICATE-----') !== false ? @openssl_x509_parse($pem) : false;
	return is_array($c) && ssl_is_self_signed($c) ? 'self-signed' : 'custom';
}

/* One addon domain by name, or null when $domain is not an addon domain. Every
   action module calls this first: it is what stops a crafted POST from pointing
   an edit or a delete at a main account vhost. */
function addon_domain_get($ini, $domain) {
	if (!valid_domain($domain)) return null;
	$file = addon_vhost_path($ini, $domain);
	if (!is_file($file)) return null;
	$content = @file_get_contents($file);
	if ($content === false || addon_domain_marker($content) === '') return null;
	$all = addon_domain_list($ini);
	return isset($all[$domain]) ? $all[$domain] : null;
}

/* php-fpm pool file contents for an addon domain (same shape as the pool the
   account templates write, with the per-domain socket). */
function addon_fpm_pool_content($ini, $domain, $user) {
	$group  = web_group($ini);
	$socket = addon_socket($user, $domain);
	return '['.$domain.']
user = '.$user.'
group = '.$user.'
listen = '.$socket.'
listen.owner = '.$user.'
listen.group = '.$group.'
listen.allowed_clients = 127.0.0.1

; workers start with the first request and stop after 10 s idle, so a quiet
; site holds no memory; 20 at once per site, a stuck script is killed at 300 s
pm = ondemand
pm.max_children = 20
pm.process_idle_timeout = 10s
pm.max_requests = 500
request_terminate_timeout = 300s

ping.path = /ping
slowlog = /var/log/php-fpm/'.$domain.'-slow.log
chdir = /

php_admin_value[disable_functions] = show_source, system, shell_exec, passthru, exec, popen, proc_open
php_admin_value[open_basedir] = /home/'.$user.'
php_admin_value[error_log] = "/home/'.$user.'/logs/'.$domain.'-error.log"
php_admin_flag[log_errors] = on
php_admin_value[sys_temp_dir] = "/home/'.$user.'/tmp"
php_admin_value[upload_tmp_dir] = "/home/'.$user.'/tmp"
php_admin_value[memory_limit] = 2048M
php_value[session.save_handler] = files
php_value[session.save_path] = "/home/'.$user.'/tmp"
php_value[soap.wsdl_cache_dir]  = /var/lib/php/wsdlcache
';
}

/* Change the PHP version / handler of an addon domain (created with
   scripts/adddomain): the same mechanics as account_switch_php() in
   app/functions/accounts.php - move/create/delete the pool file, restart the
   affected php-fpm services - except the pool is named after the addon domain
   and listens on its own per-domain socket. $handler 'fpm', or 'mod_php' on
   Apache (default PHP only); $new_version 'off' = No PHP (account_php_disable()
   with the addon's socket: pool removed, .php answers 403), and a version
   brings it back. Used by modules/edit_addon_domain.php (2087) and
   php_version_set() (the API, the account panel). result_ok / result_error. */
function addon_domain_php_set($domain, $new_version, $new_handler = 'fpm') {
	global $ini;
	$modphp       = (php_mode($ini) === 'modphp');
	$php_versions = array_map('trim', explode(',', $ini['php_versions']));

	/* addon_domain_get() returns null for anything that is not an addon vhost, so a
	   crafted request cannot aim this at a main account domain. */
	$addon = addon_domain_get($ini, $domain);
	if ($addon === null)
		return result_error("'".$domain."' is not an addon domain on this server.");
	if ($new_version === 'off') {
		if ($addon['version'] === 'off')
			return result_ok("PHP is already off for ".$domain.".", array('domain' => $domain, 'changed' => false));
		route_log("edit addon domain $domain: PHP {$addon['version']}/{$addon['handler']} -> off");
		if (($err = account_php_disable($domain, $addon['user'], true)) !== '')
			return result_error("PHP was not turned off for ".$domain.": ".$err);
		return result_ok("PHP is off for ".$domain.": its .php files are not run any more (they answer 403).",
		                 array('domain' => $domain, 'changed' => true));
	}
	if (!in_array($new_version, $php_versions, true))
		return result_error("PHP ".$new_version." is not installed on this server.");
	if (!in_array($new_handler, array('fpm', 'mod_php'), true))
		return result_error("Unknown PHP handler.");
	if (!$modphp && $new_handler != 'fpm')
		return result_error("nginx cannot run mod_php.");
	/* mod_php is the apache module - it only ever runs the server's default PHP. */
	if ($new_handler == 'mod_php' && $new_version != $ini['php'])
		return result_error("mod_php is only available for PHP ".$ini['php'].". Use php-fpm for PHP ".$new_version.".");

	$user            = $addon['user'];
	$old_version     = $addon['version'];
	$old_handler     = $addon['handler'];
	$handler_changed = ($new_handler != $old_handler);
	$label = "PHP ".$new_version.($modphp ? " (".($new_handler == 'fpm' ? 'php-fpm' : 'mod_php').")" : "");
	if ($new_version == $old_version && !$handler_changed)
		return result_ok("Addon domain ".$domain." already runs ".$label.".", array('domain' => $domain, 'changed' => false));
	if ($old_version === 'off') {
		route_log("edit addon domain $domain: PHP off -> $new_version/$new_handler");
		if (($err = account_php_enable($domain, $user, $new_version, $new_handler, true)) !== '')
			return result_error("PHP was not turned on for ".$domain.": ".$err);
		/* mod_php writes the account's tmp/ and logs/ as apache */
		if ($new_handler == 'mod_php')
			shell_exec('sudo '._PATH.'/scripts/update/harden_account_perms.sh --apply --quiet --user='.escapeshellarg($user).' >/dev/null 2>&1');
		return result_ok("Addon domain ".$domain." now runs ".$label.".", array('domain' => $domain, 'changed' => true));
	}

	route_log("edit addon domain $domain: PHP $old_version/$old_handler -> $new_version/$new_handler");
	$old_pool = ($old_handler == 'fpm') ? $addon['pool'] : null;
	$new_pool = ($new_handler == 'fpm') ? addon_pool_path($ini, $domain, $new_version) : null;
	$restart  = '';

	/* apache: the vhost decides who runs PHP - with the SetHandler block it
	   goes to the pool socket, without it mod_php handles the request. */
	if ($modphp && $handler_changed) {
		addon_vhost_set_fpm($ini, $domain, $user, ($new_handler == 'fpm'));
		/* mod_php writes the account's tmp/ and logs/ as apache */
		shell_exec('sudo '._PATH.'/scripts/update/harden_account_perms.sh --apply --quiet --user='.escapeshellarg($user).' >/dev/null 2>&1');
	}

	if ($old_pool === null && $new_pool !== null) {
		addon_write_root_file($new_pool, addon_fpm_pool_content($ini, $domain, $user));
	} elseif ($old_pool !== null && $new_pool === null) {
		shell_exec('sudo rm -f '.escapeshellarg($old_pool));
	} elseif ($old_pool !== null && $new_pool !== null && $old_pool !== $new_pool) {
		/* different PHP version: the pool just moves to the other version's
		   php-fpm.d - the socket path (and so the vhost) is unchanged */
		shell_exec('sudo mv '.escapeshellarg($old_pool).' '.escapeshellarg(dirname($new_pool).'/'));
	}

	if ($old_pool !== null)
		$restart .= addon_fpm_service($ini, $old_version).' ';
	if ($new_pool !== null && $new_pool !== $old_pool)
		$restart .= addon_fpm_service($ini, $new_version).' ';
	/* apache needs a restart whenever the vhost changed; nginx keeps the same
	   upstream socket, so it only needs one when nothing else did. */
	if ($modphp && $handler_changed)
		$restart .= 'httpd.service';
	$restart = trim($restart);
	if ($restart != '')
		shell_exec('sudo '._PATH.'/scripts/restart_services.sh '.$restart.' 2>/dev/null >/dev/null &');
	return result_ok("Addon domain ".$domain." now runs ".$label.".", array('domain' => $domain, 'changed' => true));
}

/* Write $content over a root-owned file through sudo (tempfile + sudo cp). */
function addon_write_root_file($path, $content) {
	$tmp = tempnam('/tmp', 'reqad');
	file_put_contents($tmp, $content);
	shell_exec('sudo cp '.escapeshellarg($tmp).' '.escapeshellarg($path));
	shell_exec('sudo chmod 0644 '.escapeshellarg($path));
	unlink($tmp);
}

/* apache only: add/remove the <FilesMatch \.php$> SetHandler block that sends
   PHP to the domain's own fpm socket. Removing it drops the vhost back to
   mod_php. Mirrors apache_vhost_add_fpm()/apache_vhost_remove_fpm() in
   app/functions/accounts.php, but with the per-domain socket. */
function addon_vhost_set_fpm($ini, $domain, $user, $enable) {
	$file    = addon_vhost_path($ini, $domain);
	$content = @file_get_contents($file);
	if ($content === false || $content === '') return false;

	$content = preg_replace('/\n[ \t]*<FilesMatch[^>]*>.*?<\/FilesMatch>/s', '', $content);
	if ($enable) {
		$block = "\n    <FilesMatch \\.php\$>\n        SetHandler \"proxy:unix:".addon_socket($user, $domain)."|fcgi://localhost\"\n    </FilesMatch>";
		/* one block per <VirtualHost>, so :80 and :443 both get it */
		$content = preg_replace('/\n<\/VirtualHost>/', $block."\n</VirtualHost>", $content);
	}
	addon_write_root_file($file, $content);
	return true;
}

/* --- SMTP delivery failures ----------------------------------------------
   These used to be INSERTed into an `errors` table in the panel database.
   That was the wrong store: the rows are a rolling tail of whatever exim's
   main.log still holds, they are never queried by anything but one page, and
   they are re-derived from scratch on every run -- so the database carried
   write traffic every minute, grew without bound and had to be vacuumed, all
   to hold data that is already a log. They live in a log file now.

   Format is one failure per line, `date|recipient|reason`, oldest first, so
   the file appends cleanly and the tail is the newest. The reason is kept
   verbatim (a log has room for it); only the page truncates. */

if (!defined('SMTP_ERRORS_LOG'))   define('SMTP_ERRORS_LOG',   _PATH.'/log/smtp_errors.log');
if (!defined('SMTP_ERRORS_MAX'))   define('SMTP_ERRORS_MAX',   5000);   // lines kept after a trim

/* Parse one exim main.log permanent-failure line ("**") into date/email/reason,
   or null when it is not one. Exim writes the recipient differently depending
   on the transport, hence the three shapes:

     ... ** user@example.com R=dnslookup T=remote_smtp: SMTP error ...
     ... ** |/path/to/script <root@host>: retry timeout exceeded      (pipe)
     ... ** old@example.com <new@example.com> R=...: ...              (alias) */
function smtp_error_parse($line) {
	if (!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) \S+ \*\* (.*)$/', $line, $m))
		return null;

	$when = $m[1];
	$rest = $m[2];

	/* The routed target, then any parents exim expanded through, then the
	   address it settled on -- the last of those is the one worth reporting,
	   and for a pipe or file delivery it is the only address on the line. */
	if (!preg_match('/^(\S+)((?:\s+\([^)]*\))*)(?:\s+<([^>]*)>)?(.*)$/', $rest, $m2))
		return null;

	$email = (isset($m2[3]) && $m2[3] !== '') ? $m2[3] : ltrim(rtrim($m2[1], ':'), '|');
	$rest  = isset($m2[4]) ? $m2[4] : '';

	/* Drop exim's routing breadcrumbs (R=router T=transport H=host ...); what
	   is left after them is the reason the delivery failed. */
	$rest = preg_replace('/^\s*(?:[A-Z]+=\S+\s*)*/', '', $rest);
	$rest = trim(preg_replace('/\s+/', ' ', ltrim($rest, ": \t")));

	if ($email === '' || $rest === '') return null;

	/* `|` is the field separator, so it must not survive in the first two
	   fields -- the reason is last and may keep its own. */
	return array('date'   => $when,
	             'email'  => str_replace('|', ' ', $email),
	             'errmsg' => $rest);
}

/* The last $n lines of a file, read from the end so a trimmed-but-still-large
   log does not have to be pulled into memory whole. */
function smtp_errors_tail($file, $n) {
	$fh = @fopen($file, 'rb');
	if (!$fh) return array();

	$buf = '';
	fseek($fh, 0, SEEK_END);
	$pos = ftell($fh);
	while ($pos > 0 && substr_count($buf, "\n") <= $n) {
		$read = min(8192, $pos);
		$pos -= $read;
		fseek($fh, $pos);
		$buf = fread($fh, $read).$buf;
	}
	fclose($fh);

	$buf = trim($buf, "\n");
	if ($buf === '') return array();
	return array_slice(explode("\n", $buf), -$n);
}

/* Failures for the SMTP statistics page: one line per recipient, newest first.
   The page is a "who is bouncing" summary rather than a delivery trace, which
   is what the old `GROUP BY email` in the SQL was reaching for. */
function smtp_errors_read($limit = 500) {
	$latest = array();
	foreach (smtp_errors_tail(SMTP_ERRORS_LOG, SMTP_ERRORS_MAX) as $line) {
		$f = explode('|', $line, 3);
		if (count($f) < 3) continue;
		/* the file is oldest-first, so a later line legitimately overwrites */
		$latest[$f[1]] = array('date' => $f[0], 'email' => $f[1], 'errmsg' => $f[2]);
	}

	$rows = array_values($latest);
	usort($rows, function($a, $b) { return strcmp($b['date'], $a['date']); });
	return array_slice($rows, 0, $limit);
}

/* Append failures that are not logged yet.

   exim's main.log is rescanned in full every minute, so almost everything the
   parser hands us is already on disk. Dedup uses the newest timestamp in the
   log as a high-water mark: anything older was seen on an earlier run, and
   anything at that exact second is compared line for line, because several
   deliveries can fail within the same second. Returns the number appended. */
function smtp_errors_append($entries) {
	$high = '';
	$seen = array();
	foreach (smtp_errors_tail(SMTP_ERRORS_LOG, 500) as $line) {
		$ts = substr($line, 0, 19);
		if ($ts > $high) { $high = $ts; $seen = array(); }
		if ($ts === $high) $seen[$line] = true;
	}

	$new = array();
	foreach ($entries as $e) {
		if ($high !== '' && $e['date'] < $high) continue;
		$line = $e['date'].'|'.$e['email'].'|'.$e['errmsg'];
		if (isset($seen[$line])) continue;
		$seen[$line] = true;                      // also collapses dups within this batch
		$new[] = $line;
	}
	if (!$new) return 0;

	if (@file_put_contents(SMTP_ERRORS_LOG, implode("\n", $new)."\n", FILE_APPEND | LOCK_EX) === false)
		return 0;
	@chmod(SMTP_ERRORS_LOG, 0644);                // cron writes as root, the panel reads as reqad
	return count($new);
}

/* Keep the log bounded. Same shape as the trim in scripts/auto_update.sh:
   rewrite via a temp file so a reader never sees a half-written log. */
function smtp_errors_trim($max = SMTP_ERRORS_MAX) {
	if (!is_file(SMTP_ERRORS_LOG)) return false;

	$lines = @file(SMTP_ERRORS_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	if ($lines === false || count($lines) <= $max) return false;

	$tmp = SMTP_ERRORS_LOG.'.tmp';
	if (@file_put_contents($tmp, implode("\n", array_slice($lines, -$max))."\n") === false)
		return false;
	@chmod($tmp, 0644);
	return @rename($tmp, SMTP_ERRORS_LOG);
}

/* ---- Shared by app/functions/*.php (web modules, bin/reqad, API) ----------
   The account/database/email operations live in app/functions/ and are called
   from more than one front-end, so nothing in them may assume a web request. */

/* Append one line to log/route_log. Web requests log the client address and the
   nginx basic-auth user; the CLI logs "cli" and the invoking system user, so
   route_log still says who did what. Absolute path: the CLI has no fixed CWD. */
function route_log($message) {
	if (isset($_SERVER['REMOTE_ADDR'])) {
		$remote = $_SERVER['REMOTE_ADDR'];
		$who    = function_exists('auth_user') ? auth_user() : (isset($_SERVER['USER']) ? $_SERVER['USER'] : '-');
	} else {
		$remote = 'cli';
		$pw     = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid()) : false;
		$who    = $pw ? $pw['name'] : (string)getenv('USER');
	}
	error_log(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$remote." ".$who." ".$message."\n", 3, _PATH.'/log/route_log');
}

/* Load the configured DNS provider (api_<provider>.php), once per process.

   The provider files read $settings at their top level and their functions pull
   the results back in with `global`. Included from inside a function, those
   top-level assignments would land in the function's local scope and every
   API call would run with empty credentials -- so the names are bound to the
   globals here first, which makes the include write to the real globals. */
function dns_provider_load($db) {
	global $settings, $api_token, $api_user, $api_server, $powerdns_server, $powerdns_api_key, $dns_provider_name, $_debug;

	if (!is_array($settings))
		$settings = array();
	$res = $db->query('SELECT name, value FROM settings');
	while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC)))
		$settings[$row['name']] = $row['value'];

	$prov = isset($settings['dns-provider']) ? $settings['dns-provider'] : '';
	if (!in_array($prov, array('cloudflare', 'cpanel', 'powerdns'), true))
		$prov = 'none';
	include_once(__DIR__.'/api_'.$prov.'.php');
	return $prov;
}

/* Record sets, provider-neutral. Every api_<provider>.php has
     dns_rrset_get($name, $type)          the values at $name (full name, no
                                          trailing dot): array of strings, TXT
                                          values unquoted and joined; null when
                                          no managed zone holds $name
     dns_rrset_set($name, $type, $values) make the values exactly these (an
                                          empty array removes the set); '' or
                                          'Error: ...'
   for A, AAAA and TXT. Values already there are left as they are (TTL,
   Cloudflare's proxy), so a set call only touches what changes. Other types
   (MX) only for removing the whole set: dns_rrset_set($name, 'MX', array())
   (their values are not in the same form at every provider). */

/* The longest zone in $zones (name => ...) that holds $name, '' when none. */
function dns_zone_for($name, $zones) {
	$name = strtolower(rtrim(trim((string)$name), '.'));
	$zone = '';
	if (is_array($zones))
		foreach ($zones as $zname => $z)
			if (($name === $zname || substr('.'.$name, -strlen('.'.$zname)) === '.'.$zname) && strlen($zname) > strlen($zone))
				$zone = (string)$zname;
	return $zone;
}

/* A TXT record's text from its zone-file form ("abc" "def" -> abcdef). */
function dns_txt_decode($content) {
	$content = trim((string)$content);
	if (preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $content, $m) && $m[1])
		return implode('', array_map(function ($s) { return str_replace(array('\\"', '\\\\'), array('"', '\\'), $s); }, $m[1]));
	return $content;
}

/* Text -> 255-byte chunks, unquoted. */
function dns_txt_chunks($value) {
	$value = (string)$value;
	return $value === '' ? array('') : str_split($value, 255);
}

/* Text -> zone-file form: quoted, escaped, in 255-byte chunks. */
function dns_txt_encode($value) {
	return implode(' ', array_map(function ($c) { return '"'.str_replace(array('\\', '"'), array('\\\\', '\\"'), $c).'"'; }, dns_txt_chunks($value)));
}

/* Whole zones, provider-neutral (the DNS zone editor: app/functions/dns.php).
   Every api_<provider>.php has
     dns_zone_rrsets($zone)   every record set of the zone: list of {name
                              (full, no trailing dot), type, ttl, values,
                              proxied (Cloudflare true/false, else null)};
                              null when no managed zone is $zone
     dns_zone_put($zone, $name, $type, $ttl, $values)
                              make the set at $name exactly these values with
                              this TTL (an empty array removes it); '' or
                              'Error: ...'
   for A, AAAA, CNAME, MX, TXT, SRV and CAA (SOA and NS are only read). A
   value has one form at every provider:
     A, AAAA  the address          MX   "10 mail.example.com"
     CNAME    the host name        SRV  "10 5 5060 sip.example.com"
     TXT      the text, unquoted   CAA  '0 issue "letsencrypt.org"'
   host names lowercase, without the trailing dot ("." = none, RFC 7505). */

/* "Mail.Example.com." -> "mail.example.com"; "." stays "." */
function dns_host_canon($host) {
	$host = strtolower(trim((string)$host));
	return $host === '.' ? '.' : rtrim($host, '.');
}

/* A value's fields: MX [priority, host], SRV [priority, weight, port, target],
   CAA [flags, tag, value], others [value]; null when it does not parse. */
function dns_value_parts($type, $value) {
	$value = trim((string)$value);
	if ($type === 'MX')
		return preg_match('/^(\d{1,5})\s+(\S+)$/', $value, $m) ? array((int)$m[1], dns_host_canon($m[2])) : null;
	if ($type === 'SRV')
		return preg_match('/^(\d{1,5})\s+(\d{1,5})\s+(\d{1,5})\s+(\S+)$/', $value, $m) ? array((int)$m[1], (int)$m[2], (int)$m[3], dns_host_canon($m[4])) : null;
	if ($type === 'CAA')
		return preg_match('/^(\d{1,3})\s+([A-Za-z0-9]+)\s+(.*)$/s', $value, $m) ? array((int)$m[1], strtolower($m[2]), dns_txt_decode($m[3])) : null;
	return array($value);
}

/* Zone-file content (PowerDNS's form, also Cloudflare's for most types) -> the value. */
function dns_value_from_zone($type, $content) {
	$content = trim((string)$content);
	switch ($type) {
	case 'TXT':   return dns_txt_decode($content);
	case 'CNAME': case 'NS': case 'PTR': return dns_host_canon($content);
	case 'AAAA':  $b = @inet_pton($content); return $b !== false ? inet_ntop($b) : strtolower($content);
	case 'MX': case 'SRV': case 'CAA':
		$p = dns_value_parts($type, $content);
		return $p === null ? $content : dns_value_join($type, $p);
	}
	return $content;
}

/* The fields -> the value (dns_value_parts() undone). */
function dns_value_join($type, array $p) {
	if ($type === 'CAA')
		return (int)$p[0].' '.$p[1].' "'.str_replace(array('\\', '"'), array('\\\\', '\\"'), (string)$p[2]).'"';
	return implode(' ', $p);
}

/* The value -> zone-file content (host names absolute, with the dot). */
function dns_value_to_zone($type, $value) {
	$dot = function ($h) { return $h === '.' ? '.' : $h.'.'; };
	$p = dns_value_parts($type, $value);
	switch ($type) {
	case 'TXT':   return dns_txt_encode($value);
	case 'CNAME': case 'NS': case 'PTR': return $dot(dns_host_canon($value));
	case 'MX':    return $p ? $p[0].' '.$dot($p[1]) : $value;
	case 'SRV':   return $p ? $p[0].' '.$p[1].' '.$p[2].' '.$dot($p[3]) : $value;
	case 'CAA':   return $p ? dns_value_join('CAA', $p) : $value;
	}
	return $value;
}

/* Uniform return value of the app/functions/ operations.
   ok       -- the operation happened (possibly with warnings)
   message  -- one-line human summary
   warnings -- non-fatal problems after the change was made (e.g. a Let's
               Encrypt certificate that could not be requested yet)
   error    -- why nothing was changed (ok = false)
   data     -- machine-readable details for the CLI --json / API */
function result_ok($message, $data = array(), $warnings = array()) {
	return array('ok' => true, 'message' => $message, 'warnings' => array_values(array_unique(array_filter($warnings, 'strlen'))), 'error' => '', 'data' => $data);
}

function result_error($error, $data = array()) {
	return array('ok' => false, 'message' => '', 'warnings' => array(), 'error' => $error, 'data' => $data);
}

/* Web wrapper helper: show a result on the next page (Post/Redirect/Get). A
   change that went through but left warnings is shown as a warning, with the
   success line first so the admin knows it was not rolled back. */
function result_redirect($url, $r) {
	if (!$r['ok'])
		msg_redirect($url, $r['error'], 'error');
	if ($r['warnings'])
		msg_redirect($url, $r['message'].' '.implode(' ', $r['warnings']), 'warning');
	msg_redirect($url, $r['message'], 'success');
}


/* ===================== Transfer tool: the cPanel server =====================
   The transfer tool reads the old cPanel server over SSH (whmapi1 as root).
   Only server, port and SSH user come from the form and they are checked here;
   every command sent to the remote shell is fixed text, and anything read back
   that is used again (account names, document roots) is checked before use. */

/* The ssh command for the form's values, or '' with $error set. */
function transfer_ssh($server, $port, $ruser, &$error) {
	$error = '';
	if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.\-]{0,253}$/', $server))
		$error = 'Invalid server address.';
	elseif (!preg_match('/^[a-z_][a-z0-9_\-]{0,31}$/', $ruser))
		$error = 'Invalid SSH username.';
	elseif ((int)$port < 1 || (int)$port > 65535)
		$error = 'Invalid SSH port.';
	if ($error !== '')
		return '';
	return 'ssh -o ConnectTimeout=10 -o BatchMode=yes -o StrictHostKeyChecking=accept-new -p '.(int)$port.' '
	     .escapeshellarg($ruser.'@'.$server);
}

/* The reqad user's SSH public key, which transfer_ssh() logs in with (ssh
   finds ~/.ssh/id_rsa or id_ed25519 by itself). Made the first time it is
   asked for - a new server has none. '' when it cannot be made. */
function transfer_ssh_pubkey() {
	$dir = _PATH.'/.ssh';
	foreach (array('id_rsa', 'id_ed25519') as $k) {
		if (!is_file("$dir/$k"))
			continue;
		if (!is_file("$dir/$k.pub"))
			exec('ssh-keygen -y -f '.escapeshellarg("$dir/$k").' > '.escapeshellarg("$dir/$k.pub").' 2>/dev/null');
		$pub = trim((string)@file_get_contents("$dir/$k.pub"));
		if ($pub !== '')
			return $pub;
	}
	if (!is_dir($dir) && !@mkdir($dir, 0700))
		return '';
	exec('ssh-keygen -q -t ed25519 -N "" -C '.escapeshellarg('reqad@'.gethostname())
		.' -f '.escapeshellarg("$dir/id_ed25519").' < /dev/null 2>&1', $out, $rc);
	if ($rc !== 0)
		return '';
	route_log('transfer: made an SSH key for the reqad user ('.$dir.'/id_ed25519)');
	return trim((string)@file_get_contents("$dir/id_ed25519.pub"));
}

/* Several whmapi1 calls over ONE ssh connection (one login, not one per call).
   Returns the 'data' of each call in order, null for a call that failed. */
function transfer_whmapi($ssh, array $calls) {
	$sep = '@@REQAD-NEXT@@';
	$parts = array();
	foreach ($calls as $c)
		$parts[] = 'whmapi1 --output=json '.$c.' 2>/dev/null';
	$raw = (string)shell_exec($ssh.' '.escapeshellarg(implode("; echo '".$sep."'; ", $parts)).' 2>/dev/null');
	$out = array();
	foreach (explode($sep, $raw) as $i => $chunk) {
		if ($i >= count($calls))
			break;
		$j = json_decode(trim($chunk), true);
		$out[] = (is_array($j) && !empty($j['metadata']['result'])) ? ($j['data'] ?? array()) : null;
	}
	while (count($out) < count($calls))
		$out[] = null;
	return $out;
}

/* "ea-php82" / "alt-php74" / "php82" -> "8.2"; '' when it is not a version */
function transfer_php_version($pkg) {
	if (preg_match('/(?:^|-)php(\d)(\d{1,2})$/', strtolower(trim((string)$pkg)), $m))
		return $m[1].'.'.$m[2];
	return '';
}

/* What this server has of a PHP version: 'installed' | 'installable' | 'missing',
   plus 'ended' (past its end of life) for an installed one. */
function transfer_php_local($version) {
	static $have = null, $can = null;
	if ($have === null) {
		$have = array();
		foreach (php_versions() as $v)
			$have[$v['version']] = $v['ended'];
		$can = php_installable_versions();
	}
	if (isset($have[$version]))
		return array('status' => 'installed', 'ended' => (bool)$have[$version]);
	return array('status' => in_array($version, $can, true) ? 'installable' : 'missing', 'ended' => false);
}

/* ---- Transfer tool: the pre-transfer check ------------------------------------
   One check of the cPanel server (MySQL, PHP extensions, mail settings) and one
   per account (domains and DNS, cPanel features missing here, mail settings,
   PHP settings, databases, cron jobs, size, certificates). Items:
   array(area, level ok|info|warn|block, title, detail, list[], code (list is commands)). */

/* $cmd on the cPanel server with $stdin on its stdin (never in the command);
   its output. The local ssh is stopped after $timeout seconds. */
function transfer_remote($ssh, $cmd, $stdin = '', $timeout = 120) {
	$tmp = tempnam(sys_get_temp_dir(), 'rqtf');
	file_put_contents($tmp, $stdin);
	$out = '';
	$p = proc_open('timeout '.(int)$timeout.' '.$ssh.' '.escapeshellarg($cmd),
	               array(0 => array('file', $tmp, 'r'), 1 => array('pipe', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes);
	if (is_resource($p)) {
		$out = (string)stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		proc_close($p);
	}
	@unlink($tmp);
	return $out;
}

/* Output of the remote scripts below, in sections: a line "@@RQ name arg"
   starts one. array of array(name, arg, lines) */
function transfer_sections($out) {
	$sec = array();
	$cur = -1;
	foreach (explode("\n", $out) as $l) {
		if (strncmp($l, '@@RQ ', 5) === 0) {
			$p = explode(' ', substr($l, 5), 2);
			$sec[] = array($p[0], trim($p[1] ?? ''), array());
			$cur = count($sec) - 1;
		} elseif ($cur >= 0)
			$sec[$cur][2][] = $l;
	}
	return $sec;
}

function transfer_item($area, $level, $title, $detail = '', array $list = array(), $code = false) {
	return array('area' => $area, 'level' => $level, 'title' => $title, 'detail' => $detail, 'list' => array_values($list), 'code' => $code);
}

function transfer_bytes($n) {
	$n = (float)$n;
	foreach (array('B', 'KB', 'MB', 'GB', 'TB') as $i => $u)
		if ($n < 1024 || $u === 'TB')
			return ($i ? number_format($n, $n < 10 ? 1 : 0) : (int)$n).' '.$u;
		else
			$n /= 1024;
}

/* The registered domain of a name (example.co.uk for www.shop.example.co.uk),
   '' when the name is itself a public suffix. Public Suffix List rules. */
function transfer_registrable($d) {
	static $rules = null;
	if ($rules === null) {
		$rules = array();
		foreach ((array)@file('/usr/share/publicsuffix/public_suffix_list.dat', FILE_IGNORE_NEW_LINES) as $l) {
			$l = strtolower(trim(explode(' ', trim((string)$l))[0]));
			if ($l !== '' && strpos($l, '//') !== 0)
				$rules[$l] = true;
		}
	}
	$labels = explode('.', strtolower(trim($d, '.')));
	$n = count($labels);
	$suffix = 1;
	for ($i = 0; $i < $n; $i++) {
		$cand = implode('.', array_slice($labels, $i));
		if (isset($rules['!'.$cand])) { $suffix = $n - $i - 1; break; }
		if (isset($rules[$cand]) || ($i + 1 < $n && isset($rules['*.'.implode('.', array_slice($labels, $i + 1))]))) {
			$suffix = $n - $i;
			break;
		}
	}
	return $n > $suffix ? implode('.', array_slice($labels, $n - $suffix - 1)) : '';
}

/* The registry's RDAP server for a top-level domain ('' = it has none). IANA's
   list, kept a week. */
function transfer_rdap_base($tld) {
	$f = _PATH.'/transfers/rdap-dns.json';
	$map = (is_file($f) && filemtime($f) > time() - 7 * 86400) ? json_decode((string)@file_get_contents($f), true) : null;
	if (!is_array($map)) {
		$j = json_decode((string)@file_get_contents('https://data.iana.org/rdap/dns.json', false,
		                 stream_context_create(array('http' => array('timeout' => 8)))), true);
		$map = array();
		foreach ((array)($j['services'] ?? array()) as $s)
			foreach ((array)($s[0] ?? array()) as $t)
				$map[strtolower($t)] = rtrim((string)($s[1][0] ?? ''), '/').'/';
		if ($map) {
			@mkdir(dirname($f), 0750, true);
			@file_put_contents($f, json_encode($map));
		}
	}
	return $map[strtolower($tld)] ?? '';
}

/* What the registry says of a registered domain: array(state found|none|unknown,
   statuses, expires (unix time or 0), source rdap|whois). RDAP when the TLD has
   it, else whois (.ro has no RDAP, and RoTLD shows no expiry date). */
function transfer_registry($domain) {
	$tld = substr(strrchr($domain, '.'), 1);
	$base = transfer_rdap_base($tld);
	if ($base !== '/' && $base !== '') {
		$ctx = stream_context_create(array('http' => array('timeout' => 8, 'ignore_errors' => true,
		                             'header' => "Accept: application/rdap+json\r\n")));
		$body = @file_get_contents($base.'domain/'.rawurlencode($domain), false, $ctx);
		$code = 0;
		foreach ((array)($http_response_header ?? array()) as $h)
			if (preg_match('#^HTTP/\S+ (\d{3})#', $h, $m))
				$code = (int)$m[1];
		if ($code === 404)
			return array('none', array(), 0, 'rdap');
		$j = $code === 200 ? json_decode((string)$body, true) : null;
		if (is_array($j)) {
			$exp = 0;
			foreach ((array)($j['events'] ?? array()) as $e)
				if (($e['eventAction'] ?? '') === 'expiration')
					$exp = (int)strtotime((string)$e['eventDate']);
			return array('found', array_values(array_filter((array)($j['status'] ?? array()), 'is_string')), $exp, 'rdap');
		}
	}
	$p = proc_open(array('timeout', '15', 'whois', '--', $domain),
	               array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes);
	if (!is_resource($p))
		return array('unknown', array(), 0, 'whois');
	$w = (string)stream_get_contents($pipes[1]);
	fclose($pipes[1]);
	proc_close($p);
	if (trim($w) === '')
		return array('unknown', array(), 0, 'whois');
	if (preg_match('/no (match|entries|data) (found|for)|^\s*not found|status:\s*(free|available)|is available for registration/im', $w))
		return array('none', array(), 0, 'whois');
	$exp = 0;
	if (preg_match('/^\s*(?:registry expiry date|registrar registration expiration date|expiry date|expiration date|expires on|expire date|paid-till|expires|expire|renewal date)\s*:\s*(\S+)/im', $w, $m))
		$exp = (int)strtotime(str_replace('.', '-', $m[1]));
	preg_match_all('/^\s*(?:domain )?status\s*:\s*(.+)$/im', $w, $mm);
	$st = array();
	foreach ($mm[1] as $s)
		$st[] = trim(preg_replace('#\s+https?://\S+#', '', $s));
	return array('found', array_values(array_unique($st)), $exp, 'whois');
}

/* Is $ip in one of Cloudflare's published ranges (IPv4 and IPv6)? */
function transfer_is_cloudflare($ip) {
	$nets = array('173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
	              '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
	              '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22', '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32',
	              '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32');
	$bin = @inet_pton($ip);
	if ($bin === false)
		return false;
	foreach ($nets as $n) {
		list($net, $len) = explode('/', $n);
		$nb = inet_pton($net);
		if (strlen($nb) !== strlen($bin))
			continue;
		$bytes = intdiv((int)$len, 8);
		$bits = (int)$len % 8;
		if (substr($bin, 0, $bytes) !== substr($nb, 0, $bytes))
			continue;
		if ($bits === 0 || ((ord($bin[$bytes]) ^ ord($nb[$bytes])) & (0xFF << (8 - $bits)) & 0xFF) === 0)
			return true;
	}
	return false;
}

/* The versions chosen on the Transfer tool's PHP step for versions this server
   does not have ("8.1" => "8.2"): set from the request (pre-check) or the job
   (the runner), read by transfer_php_nearest(). $set: replaces the map, only
   entries of a missing version to an installed one are kept. */
function transfer_php_map(?array $set = null) {
	static $map = array();
	if ($set !== null) {
		$have = array_column(php_versions(), 'version');
		$map = array();
		foreach ($set as $from => $to)
			if (preg_match('/^\d\.\d{1,2}$/', (string)$from) && !in_array((string)$from, $have, true) && in_array((string)$to, $have, true))
				$map[(string)$from] = (string)$to;
	}
	return $map;
}

/* The version here for one that is not installed: the one chosen for it
   (transfer_php_map()), else the nearest newer one, else the newest. */
function transfer_php_nearest($version) {
	$map = transfer_php_map();
	if (isset($map[$version]))
		return $map[$version];
	$have = array_column(php_versions(), 'version');
	usort($have, 'version_compare');
	foreach ($have as $v)
		if (version_compare($v, $version, '>='))
			return $v;
	return $have ? end($have) : '';
}

/*
 * A cPanel crontab made into jobs for this server. cPanel's PHP binaries
 * (/opt/cpanel/ea-phpXY/..., /usr/local/bin/ea-phpXY, CloudLinux /opt/alt/phpXY,
 * and /usr/local/bin/php or /usr/bin/php, which on cPanel pick the account's
 * version) become this server's binary of the same version; /home/<remote>/
 * becomes /home/<local>/. A job that needs a cPanel program, or a program this
 * server does not have, comes in paused. @reboot jobs are not taken.
 * $php: the version /usr/local/bin/php meant for the account (its main site's).
 * array(jobs: array(schedule, command, original, on, notes[]), skipped: array(line, why), notes[])
 */
function transfer_cron_convert($text, $ruser, $luser, $php) {
	$jobs = $skipped = $notes = array();
	$have = array_column(php_versions(), 'version');
	$bin_for = function ($v, &$n) use ($have) {
		if (!in_array($v, $have, true)) {
			$near = transfer_php_nearest($v);
			$n[] = 'PHP '.$v.' is not installed here, so it runs with PHP '.$near;
			$v = $near;
		}
		return cron_php_bin($v);
	};
	foreach (explode("\n", str_replace("\r", '', (string)$text)) as $l) {
		$t = trim($l);
		if ($t === '' || $t[0] === '#')
			continue;
		if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $t, $m)) {
			if ($m[1] === 'MAILTO' && trim($m[2], " \"'") !== '')
				$notes[] = 'MAILTO='.trim($m[2], " \"'").' is not carried over: here each run\'s output is kept with the job (Cron jobs page).';
			elseif ($m[1] !== 'SHELL' && $m[1] !== 'MAILTO')
				$notes[] = 'The line "'.$t.'" (a setting for all jobs) is not carried over.';
			continue;
		}
		if (!preg_match('/^(@[a-z]+|\S+\s+\S+\s+\S+\s+\S+\s+\S+)\s+(.+)$/', $t, $m)) {
			$skipped[] = array($t, 'not a cron line');
			continue;
		}
		$sched = preg_replace('/\s+/', ' ', $m[1]);
		$orig = $m[2];
		if (strtolower($sched) === '@reboot') {
			$skipped[] = array($t, 'jobs that run when the server starts are not available here');
			continue;
		}
		if (cron_schedule($sched) === false) {
			$skipped[] = array($t, 'the schedule is not valid');
			continue;
		}
		$n = array();
		$on = true;
		$cmd = $orig;
		if ($ruser !== $luser) {
			$cmd = preg_replace('#/home\d*/'.preg_quote($ruser, '#').'(?=/|\s|$|[\'";|&)])#', '/home/'.$luser, $cmd);
			$cmd = preg_replace('#~'.preg_quote($ruser, '#').'(?=/|\s|$)#', '~'.$luser, $cmd);
		} else
			$cmd = preg_replace('#/home\d+/'.preg_quote($ruser, '#').'(?=/|\s|$)#', '/home/'.$luser, $cmd);
		// cPanel's PHP binaries, by version
		$cmd = preg_replace_callback('#(?:/opt/cpanel/ea-php(\d)(\d+)/root/usr/bin/php(-cgi)?|/usr/local/bin/ea-php(\d)(\d+)|/opt/alt/php(\d)(\d+)/usr/bin/php(-cgi)?)(?=\s|$|[;|&)])#',
			function ($mm) use ($bin_for, &$n) {
				$v = $mm[1] !== '' ? $mm[1].'.'.$mm[2] : (($mm[4] ?? '') !== '' ? $mm[4].'.'.$mm[5] : $mm[6].'.'.$mm[7]);
				if (!empty($mm[3]) || !empty($mm[8]))
					$n[] = 'php-cgi became the command-line php';
				return "\x01".$v."\x01";   // made a binary below, after the second pass
			}, $cmd);
		// the version-choosing wrappers, and a bare "php" at the start of a command
		$cmd = preg_replace_callback('#(^|[;&|(]\s*|\bnice\s+(?:-n\s*\d+\s+)?)(/usr/local/bin/php|/usr/bin/php|php)(-cgi)?(?=\s|$)#',
			function ($mm) use ($bin_for, $php, &$n) {
				if (!empty($mm[3]))
					$n[] = 'php-cgi became the command-line php';
				return $mm[1].$bin_for($php, $n);
			}, $cmd);
		$cmd = preg_replace_callback('/\x01([0-9.]+)\x01/', function ($mm) use ($bin_for, &$n) { return $bin_for($mm[1], $n); }, $cmd);
		if (preg_match('#/usr/local/cpanel/|(^|[\s;&|])/scripts/#', $cmd)) {
			$on = false;
			$n[] = 'it runs a cPanel program, which does not exist here: imported paused';
		} else {
			// every program a part of the command starts with must be on this server
			foreach (preg_split('/\s*(?:&&|\|\||;|\|)\s*/', $cmd) as $part) {
				$w = preg_split('/\s+/', trim($part));
				$i = 0;
				while (isset($w[$i]) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', $w[$i]))
					$i++;
				if (in_array($w[$i] ?? '', array('nice', 'ionice', 'timeout', 'flock', 'nohup'), true))
					continue;
				$prog = $w[$i] ?? '';
				if ($prog === '' || !preg_match('#^[A-Za-z0-9_./-]+$#', $prog) || in_array($prog, array('cd', 'echo', 'test', '[', 'exit', 'export', 'source', '.'), true))
					continue;
				if ($prog[0] === '/') {
					if (preg_match('#^/home/#', $prog))
						continue;
					$ok = is_executable($prog);
				} else
					$ok = is_executable('/usr/bin/'.$prog) || is_executable('/bin/'.$prog) || is_executable('/usr/local/bin/'.$prog);
				if (!$ok) {
					$on = false;
					$n[] = $prog.' is not on this server: imported paused';
				}
			}
		}
		if (strlen($cmd) > CRON_CMD_MAX) {
			$skipped[] = array($t, 'the command is too long');
			continue;
		}
		$jobs[] = array('schedule' => $sched, 'command' => $cmd, 'original' => $orig, 'on' => $on, 'notes' => array_values(array_unique($n)));
	}
	return array('jobs' => $jobs, 'skipped' => $skipped, 'notes' => array_values(array_unique($notes)));
}

/* PHP extension names made comparable ("Zend OPcache" = "opcache", ionCube's
   long name = "ioncube") */
function transfer_php_ext_name($m) {
	$m = strtolower(trim($m));
	if (strpos($m, 'ioncube') !== false) return 'ioncube';
	if (strpos($m, 'sourceguardian') !== false) return 'sourceguardian';
	if (strpos($m, 'opcache') !== false) return 'opcache';
	if (strpos($m, 'zend guard') !== false) return 'zend guard loader';
	return $m;
}

/* "php -m": the extension names */
function transfer_php_exts(array $lines) {
	$out = array();
	foreach ($lines as $l) {
		$l = trim($l);
		if ($l !== '' && $l[0] !== '[')
			$out[transfer_php_ext_name($l)] = true;
	}
	return $out;
}

/* The check of the cPanel server itself. $pkgs: the PHP packages its sites
   use (ea-php82, alt-php74). array(items, ctx) - ctx goes back with each
   account's check: the server's addresses and name servers, free space here,
   and whether encoded files need looking for. */
function transfer_precheck_server($ssh, array $pkgs) {
	$script = <<<'SH'
echo "@@RQ mysql"; mysql -N -B -e 'SELECT VERSION()' 2>/dev/null
echo "@@RQ hostname"; hostname
echo "@@RQ ns"; grep -E '^NS[0-9]* ' /etc/wwwacct.conf 2>/dev/null | awk '{print $2}'
echo "@@RQ ips"; hostname -I 2>/dev/null | tr ' ' '\n'; awk -F: '{print $1}' /etc/ips 2>/dev/null
echo "@@RQ cpconf"; grep -E '^(maxemailsperhour|emailsperdaynotify|email_outbound_spam_detect_enable|email_outbound_spam_detect_action|email_outbound_spam_detect_threshold|nobodyspam|smtpmailgidonly)=' /var/cpanel/cpanel.config 2>/dev/null
echo "@@RQ localopts"; grep -E '^(smarthost_routelist|per_domain_mailips|acl_[a-z0-9_]*rbl[a-z0-9_]*|acl_ratelimit[a-z0-9_]*|acl_deny_spam_score_over_int|acl_default_spam_scan[a-z0-9_]*|require_secure_auth|max_spam_scan_size|senderverify|callouts|acl_requirehelo[a-z0-9_]*)=' /etc/exim.conf.localopts 2>/dev/null
echo "@@RQ mailipsall"; awk -F': *' '$1=="*"{print FILENAME": "$2}' /etc/mailips /etc/mailhelo 2>/dev/null
while read -r p; do
  case "$p" in
    ea-php[0-9]*) b=/opt/cpanel/$p/root/usr/bin/php ;;
    alt-php[0-9]*) b=/opt/alt/php${p#alt-php}/usr/bin/php ;;
    *) continue ;;
  esac
  echo "@@RQ phpm $p"; [ -x "$b" ] && "$b" -m 2>/dev/null
done
SH;
	$pk = array();
	foreach ($pkgs as $p)
		if (preg_match('/^(ea|alt)-php\d{2,3}$/', (string)$p))
			$pk[$p] = true;
	$out = transfer_remote($ssh, $script, implode("\n", array_keys($pk))."\n", 90);
	if (trim($out) === '')
		return array(array(transfer_item('Server', 'block', 'The cPanel server did not answer the check.')), array());

	$sec = array('mysql' => array(), 'ns' => array(), 'ips' => array(), 'cpconf' => array(), 'localopts' => array(), 'mailipsall' => array(), 'hostname' => array());
	$phpm = array();
	foreach (transfer_sections($out) as $s) {
		if ($s[0] === 'phpm')
			$phpm[$s[1]] = transfer_php_exts($s[2]);
		else
			$sec[$s[0]] = array_values(array_filter(array_map('trim', $s[2]), 'strlen'));
	}
	$items = array();

	// MySQL / MariaDB
	$rv = $sec['mysql'][0] ?? '';
	$lv = trim((string)(mysql_rows('SELECT VERSION()')[0] ?? ''));
	$remote_mysql8 = $rv !== '' && stripos($rv, 'mariadb') === false && version_compare($rv, '8.0', '>=');
	$items[] = transfer_item('Databases', $remote_mysql8 ? 'warn' : 'ok', 'Database server: '.($rv ?: 'unknown').' there, '.($lv ?: 'unknown').' here',
		$remote_mysql8 ? 'MySQL 8 databases can use collations (utf8mb4_0900_*) and password types (caching_sha2_password) that MariaDB does not have. Each account\'s check below looks at its own databases.' : '');

	// PHP extensions per version
	$scan = array();
	foreach ($phpm as $pkg => $exts) {
		$v = transfer_php_version($pkg);
		if (!$exts) {
			$items[] = transfer_item('PHP', 'info', 'PHP '.$v.' ('.$pkg.'): could not list its extensions on the cPanel server.');
			continue;
		}
		/* a version missing here: compared with the one chosen to replace it */
		$lv = $v;
		$label = 'PHP '.$v;
		if (!in_array($v, array_column(php_versions(), 'version'), true)) {
			$lv = transfer_php_map()[$v] ?? '';
			if ($lv === '') {
				$items[] = transfer_item('PHP', 'warn', 'PHP '.$v.' is not installed here, so its extensions cannot be compared yet.',
					'Install PHP '.$v.' in PHP Settings, or choose a version to use instead on the PHP versions step, then run the check again.');
				continue;
			}
			$label = 'PHP '.$v.' (PHP '.$lv.' here)';
		}
		// without the panel's own PHP_INI_SCAN_DIR / PHPRC, which name the panel
		// PHP's ini folder: the version must read its own php.d
		$local = transfer_php_exts(explode("\n", (string)shell_exec('env -u PHP_INI_SCAN_DIR -u PHPRC '.escapeshellarg(cron_php_bin($lv)).' -m 2>/dev/null')));
		$missing = array_keys(array_diff_key($exts, $local));
		sort($missing);
		foreach (array('ioncube', 'sourceguardian') as $enc)
			if (in_array($enc, $missing, true))
				$scan[$v][] = $enc;
		if ($missing)
			$items[] = transfer_item('PHP', 'warn', $label.': '.count($missing).' extension'.(count($missing) === 1 ? '' : 's').' of the cPanel server '.(count($missing) === 1 ? 'is' : 'are').' not loaded here',
				'Extensions a site uses must be installed here (PHP Settings > PHP Modules) or the site breaks. Not every site needs all of them.'
				.(isset($scan[$v]) ? ' Each account\'s check looks for encoded files ('.implode(', ', $scan[$v]).').' : ''), $missing);
		else
			$items[] = transfer_item('PHP', 'ok', $label.': every extension of the cPanel server is loaded here too.');
	}

	// mail settings of the whole server that change how mail goes out or comes in
	$kv = array();
	foreach (array_merge($sec['cpconf'], $sec['localopts']) as $l)
		if (strpos($l, '=') !== false) {
			list($k, $val) = explode('=', $l, 2);
			$kv[$k] = trim($val);
		}
	if (($kv['smarthost_routelist'] ?? '') !== '')
		$items[] = transfer_item('Mail', 'warn', 'The cPanel server sends its mail through a smarthost',
			'Outgoing mail is relayed through '.$kv['smarthost_routelist'].'. If the sites need that (a provider that blocks port 25, or a sending service), set the same up here.');
	$mx = $kv['maxemailsperhour'] ?? '';
	$items[] = transfer_item('Mail', 'info', 'Sending limit for every domain: '.($mx === '' || $mx === '0' ? 'none' : $mx.' messages an hour'),
		'Accounts and domains can have their own limit (shown in each account\'s check). Limits here come with the hosting packages.');
	$rbls = array();
	foreach ($kv as $k => $val)
		if (preg_match('/^acl_(.+_rbl)$/', $k, $m) && $val === '1')
			$rbls[] = $m[1];
	if ($rbls)
		$items[] = transfer_item('Mail', 'info', 'Blocklists (RBLs) the cPanel server checks incoming mail against', 'Compare with this server\'s mail settings.', $rbls);
	if (($kv['email_outbound_spam_detect_enable'] ?? '') === '1')
		$items[] = transfer_item('Mail', 'info', 'The cPanel server watches for accounts sending spam ('.($kv['email_outbound_spam_detect_action'] ?? 'noaction').' above '.($kv['email_outbound_spam_detect_threshold'] ?? '?').' messages)');
	if (($kv['per_domain_mailips'] ?? '') === '1' || $sec['mailipsall'])
		$items[] = transfer_item('Mail', 'info', 'The cPanel server chooses the address mail is sent from', 'Per domain (/etc/mailips, /etc/mailhelo). Domains with their own address are shown in their account\'s check; Hosting Pro does the same with Settings > IP addresses > Outgoing mail.', $sec['mailipsall']);

	// space here
	$free = (float)@disk_free_space('/home');
	$items[] = transfer_item('Size', 'info', 'Free space here: '.transfer_bytes($free).' in /home');

	$ips = array();
	foreach ($sec['ips'] as $ip)
		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))
			$ips[] = strtolower($ip);
	$ns = array();
	foreach ($sec['ns'] as $n)
		if (valid_domain(strtolower(rtrim($n, '.'))))
			$ns[] = strtolower(rtrim($n, '.'));
	return array($items, array('ips' => array_values(array_unique($ips)), 'ns' => $ns, 'free' => $free, 'scan' => $scan ? 1 : 0,
	                           'maxemail' => preg_match('/^\d+$/', $mx) ? $mx : '',
	                           'scan_for' => $scan, 'hostname' => $sec['hostname'][0] ?? ''));
}

/* Everything the account check reads on the cPanel server, in one ssh call.
   $1 = the account (checked before), $2 = 1 to look for encoded PHP files. */
function transfer_account_script() {
	return <<<'SH'
u="$1"; scan="$2"
h=$(getent passwd "$u" | cut -d: -f6)
if [ -z "$h" ] || [ ! -d "$h" ]; then echo "@@RQ nouser"; exit 0; fi
doms=$(awk -F': ' -v u="$u" '$2==u{print $1}' /etc/userdomains 2>/dev/null)
echo "@@RQ cron"; crontab -l -u "$u" 2>/dev/null
echo "@@RQ caldav"
if [ -d "$h/.caldav" ]; then
  find "$h/.caldav" -type f \( -name '*.ics' -o -name '*.vcf' \) 2>/dev/null | sed "s#^$h/.caldav/##" | awk -F/ '{n=$NF; sub(/.*\./,"",n); c[$1" "n]++} END{for(k in c) print k" "c[k]}'
fi
echo "@@RQ webdisk"; [ -f "$h/etc/webdav/passwd" ] && cut -d: -f1 "$h/etc/webdav/passwd"
echo "@@RQ htpasswds"; [ -d "$h/.htpasswds" ] && find "$h/.htpasswds" -type f -name passwd -size +0 2>/dev/null | sed "s#^$h/.htpasswds/##; s#/passwd\$##" | head -50
echo "@@RQ boxtrapper"; find "$h/etc" -maxdepth 3 -name '.boxtrapperenable' 2>/dev/null | sed "s#^$h/etc/##; s#\.boxtrapperenable\$##; s#/\$##" | awk -F/ -v u="$u" '{ if ($0 == "") print u " (main account)"; else if (NF == 2) print $2 "@" $1; else print $0 }' | head -50
echo "@@RQ apps"; [ -s "/var/cpanel/userdata/$u/applications.json" ] && cat "/var/cpanel/userdata/$u/applications.json"; echo
echo "@@RQ clsel"; for d in nodevenv virtualenv rubyvenv .cl.selector; do [ -d "$h/$d" ] && echo "$d"; done
echo "@@RQ repos"; [ -d "$h/repositories" ] && find "$h/repositories" -mindepth 1 -maxdepth 1 -type d 2>/dev/null | sed "s#^$h/##" | head -50
echo "@@RQ ftp"; for f in "/etc/proftpd/$u" "/etc/pure-ftpd/$u"; do [ -f "$f" ] && cut -d: -f1 "$f"; done | sort -u
echo "@@RQ fwddomain"; for d in $doms; do [ -s "/etc/vdomainaliases/$d" ] && echo "$d $(grep -v '^#' "/etc/vdomainaliases/$d" | head -1)"; done
echo "@@RQ maildomains"; for d in $doms; do r=local; grep -qxF "$d" /etc/remotedomains 2>/dev/null && r=remote; grep -qxF "$d" /etc/secondarymx 2>/dev/null && r=secondary; echo "$d $r"; done
echo "@@RQ mailips"; for d in $doms; do awk -F': *' -v d="$d" '$1==d{print FILENAME" "d" "$2}' /etc/mailips /etc/mailhelo 2>/dev/null; done
echo "@@RQ outgoing"; grep -qxF "$u" /etc/outgoing_mail_suspended_users 2>/dev/null && echo suspended; grep -qxF "$u" /etc/outgoing_mail_hold_users 2>/dev/null && echo hold
echo "@@RQ limits"; grep -E '^MAX_(EMAIL_PER_HOUR|DEFER_FAIL_PERCENTAGE)' "/var/cpanel/users/$u" 2>/dev/null
echo "@@RQ quotas"; for f in "$h"/etc/*/quota; do [ -s "$f" ] && awk -v d="$(basename "$(dirname "$f")")" -F: 'NF>=2{print d" "$1" "$2}' "$f"; done
echo "@@RQ mailboxes"; for f in "$h"/etc/*/passwd; do [ -s "$f" ] && awk -v d="$(basename "$(dirname "$f")")" -F: 'NF>1{print d" "$1}' "$f"; done
echo "@@RQ spam"; [ -e "$h/.spamassassinenable" ] && echo enabled; [ -e "$h/.spamassassinboxenable" ] && echo spambox; grep -iE '^[[:space:]]*required_score' "$h/.spamassassin/user_prefs" 2>/dev/null | head -1
echo "@@RQ dbs"; whmapi1 --output=json list_mysql_databases_and_users user="$u" 2>/dev/null; echo
echo "@@RQ disk"; whmapi1 --output=json get_disk_usage 2>/dev/null; echo
echo "@@RQ maildu"; [ -d "$h/mail" ] && timeout 90 nice du -sb "$h/mail" 2>/dev/null | cut -f1
for f in /var/cpanel/userdata/"$u"/*; do
  n=${f##*/}
  case "$n" in main|cache|*.cache|*.json|*.yaml|*_SSL|*.db|*.stor) continue ;; esac
  dr=$(awk '/^documentroot:/{print $2; exit}' "$f")
  echo "@@RQ site $n $dr"
  if [ -n "$dr" ]; then
    [ -f "$dr/.user.ini" ] && grep -vE '^[[:space:]]*(;|#|\[|$)' "$dr/.user.ini" | head -40 | sed 's/^/userini /'
    [ -f "$dr/php.ini" ] && grep -vE '^[[:space:]]*(;|#|\[|$)' "$dr/php.ini" | head -40 | sed 's/^/phpini /'
    [ -f "$dr/.htaccess" ] && awk '{ l = tolower($0) }
      l ~ /^[[:space:]]*<ifmodule/ { d++; p[d] = (l ~ /mod_php|php[0-9]*_module|lsapi|suphp/) }
      l ~ /^[[:space:]]*<\/ifmodule/ { if (d > 0) d-- }
      l ~ /^[[:space:]]*php_(value|flag|admin_value|admin_flag)[[:space:]]/ { g = 0; for (i = 1; i <= d; i++) if (p[i]) g = 1; print (g ? "htaccessg " : "htaccess ") $0 }' "$dr/.htaccess" | head -40
  fi
  [ -f "/var/cpanel/userdata/$u/$n.php-fpm.yaml" ] && grep -E '^[[:space:]]*php_(admin_)?(value|flag)_' "/var/cpanel/userdata/$u/$n.php-fpm.yaml" | head -40 | sed 's/^/fpm /'
  c="/var/cpanel/ssl/apache_tls/$n/certificates"
  [ -f "$c" ] && awk '/BEGIN CERTIFICATE/{p=1} p{print "cert "$0} /END CERTIFICATE/{exit}' "$c"
  if [ "$scan" = 1 ] && [ -n "$dr" ] && [ -d "$dr" ]; then
    timeout 60 nice grep -rlsE --include='*.php' 'ionCube Loader|sg_load\(' "$dr" 2>/dev/null | head -3 | sed 's/^/encoded /'
  fi
done
SH;
}

/* "key = value" / php_value key value / php_admin_value_key: value - array(key, value) or null */
function transfer_ini_line($kind, $l) {
	$l = trim($l);
	if ($kind === 'htaccess') {
		if (preg_match('/^php_(?:admin_)?(?:value|flag)\s+(\S+)\s+(.*)$/i', $l, $m))
			return array(strtolower($m[1]), trim($m[2], " \"'"));
	} elseif ($kind === 'fpm') {
		if (preg_match('/^php_(?:admin_)?(?:value|flag)_([A-Za-z0-9_.]+)\s*:\s*(.*)$/', $l, $m)) {
			$v = preg_match("/value:\s*['\"]?([^'\",}]*)/", $m[2], $vm) ? $vm[1] : $m[2];
			return array(strtolower($m[1]), trim($v, " \"'"));
		}
	} elseif (preg_match('/^([A-Za-z0-9_.]+)\s*=\s*(.*)$/', $l, $m))
		return array(strtolower($m[1]), trim($m[2], " \"'"));
	return null;
}

/*
 * The check of one account. $sites: from the PHP step (domain, type, version,
 * package, docroot); $ctx: from transfer_precheck_server() plus the account's
 * IP. Assumes the account keeps its user name here (the transfer can change it).
 */
function transfer_precheck_account($ssh, $user, array $sites, array $ctx) {
	global $db;
	$items = array();
	$rips = (array)($ctx['ips'] ?? array());
	$rns = (array)($ctx['ns'] ?? array());
	$scan = !empty($ctx['scan']) ? '1' : '0';
	$out = transfer_remote($ssh, 'bash -s -- '.escapeshellarg($user).' '.$scan, transfer_account_script(), 300);
	$sec = array();
	$site_sec = array();
	foreach (transfer_sections($out) as $s) {
		if ($s[0] === 'site') {
			$p = explode(' ', $s[1], 2);
			$site_sec[strtolower($p[0])] = array('docroot' => $p[1] ?? '', 'lines' => $s[2]);
		} else
			$sec[$s[0]] = array_values(array_filter($s[2], function ($l) { return trim($l) !== ''; }));
	}
	if (isset($sec['nouser']) || trim($out) === '')
		return array(transfer_item('Account', 'block', trim($out) === '' ? 'The cPanel server did not answer the check.' : 'There is no such account on the cPanel server.'));

	/* ---- name here ---- */
	if (!valid_username($user))
		$items[] = transfer_item('Account', 'warn', 'The user name '.$user.' is not allowed here',
			'Here a user name is a letter followed by 1 to 15 letters or digits. Give the account another name when it is created; paths in its files and cron jobs then change with it.');
	elseif (account_get($db, $user))
		$items[] = transfer_item('Account', 'warn', 'An account named '.$user.' exists here already', 'Transfer into it, or give the new one another name.');
	else
		$items[] = transfer_item('Account', 'ok', 'The user name '.$user.' is free here');

	/* ---- domains and DNS ---- */
	$here_ips = domain_server_ips('');
	$names = array();
	$mail_names = array();
	foreach ($sites as $s) {
		$names[] = $s['domain'];
		if ($s['type'] !== 'sub') {
			$names[] = 'www.'.$s['domain'];
			$mail_names[] = $s['domain'];
		}
	}
	$where = function (array $ips) use ($rips, $here_ips) {
		if (!$ips) return 'none';
		if (array_intersect($ips, $rips)) return 'cpanel';
		if (array_intersect($ips, $here_ips)) return 'here';
		foreach ($ips as $ip)
			if (transfer_is_cloudflare($ip)) return 'cloudflare';
		return 'other';
	};
	$cache = array();
	$regs = array();
	foreach ($sites as $s)
		if (($r = transfer_registrable($s['domain'])) !== '')
			$regs[$r][] = $s['domain'];
	$local_ns = dns_expected_nameservers();
	foreach ($regs as $reg => $under) {
		list($dstate, $nsnames) = dns_delegation($reg, $cache);
		list($rstate, $statuses, $exp, $src) = transfer_registry($reg);
		$bad = array_values(array_filter($statuses, function ($s) { return preg_match('/hold|expired|redemption|pending ?delete|inactive|suspend/i', $s); }));
		$sub = count($under) > 1 || $under[0] !== $reg ? ' (for '.implode(', ', $under).')' : '';
		if ($dstate === 'none' && $rstate !== 'found')
			$items[] = transfer_item('Domains', 'block', $reg.' is not registered'.$sub, 'The registry does not know it: it has expired or was never registered. There is nothing to transfer for it unless it is renewed.');
		elseif ($bad)
			$items[] = transfer_item('Domains', 'block', $reg.': '.implode(', ', $bad).$sub, 'The registry has the domain on hold or expired, so it does not work now. Renew it, or leave it out.');
		elseif ($exp && $exp < time())
			$items[] = transfer_item('Domains', 'block', $reg.' expired on '.date('Y-m-d', $exp).$sub);
		elseif ($exp && $exp < time() + 30 * 86400)
			$items[] = transfer_item('Domains', 'warn', $reg.' expires on '.date('Y-m-d', $exp).$sub, 'Make sure it is renewed.');
		elseif ($rstate === 'unknown' && $dstate !== 'ok')
			$items[] = transfer_item('Domains', 'warn', $reg.': the registry did not answer'.$sub);
		else
			$items[] = transfer_item('Domains', 'ok', $reg.' is registered'.($exp ? ', until '.date('Y-m-d', $exp) : ' (the registry shows no expiry date)').$sub,
				$statuses ? 'Status: '.implode(', ', $statuses) : '');
		if ($dstate !== 'ok')
			continue;
		// whose name servers
		$ns_ips = array();
		foreach ($nsnames as $n)
			foreach ((array)@gethostbynamel($n.'.') as $ip)
				$ns_ips[] = $ip;
		if (array_intersect($nsnames, $local_ns))
			$items[] = transfer_item('DNS', 'ok', $reg.': name servers are this server\'s', '', $nsnames);
		elseif (array_intersect($nsnames, $rns) || array_intersect($ns_ips, $rips))
			$items[] = transfer_item('DNS', 'warn', $reg.': name servers are the cPanel server\'s',
				'The zone has to be made here (or at another DNS provider) before the switch, then the name servers changed at the registrar.', $nsnames);
		elseif (preg_grep('/\.ns\.cloudflare\.com$/', $nsnames))
			$items[] = transfer_item('DNS', 'info', $reg.': DNS is at Cloudflare', 'At the switch, change the records there.', $nsnames);
		else
			$items[] = transfer_item('DNS', 'info', $reg.': DNS is at another provider', 'At the switch, change the records there.', $nsnames);
		// TTL, from the zone's own server
		$ttl = null;
		foreach (dns_delegation_ips($nsnames) as $ip) {
			$r = dns_wire_query($ip, 53, $reg, 'A', array('timeout' => 2));
			if ($r && $r['rcode'] === 0) {
				foreach ($r['answer'] as $rr)
					if ($rr[1] === 1 || $rr[1] === 5)
						$ttl = $rr[2];
				break;
			}
		}
		if ($ttl !== null)
			$items[] = transfer_item('DNS', $ttl > 3600 ? 'info' : 'ok', $reg.': time to live of its address is '.$ttl.' s',
				$ttl > 600 ? 'Lower it to 300 s a day before the switch, so the change is seen within minutes.' : '');
	}
	// where each site points now
	$pts = domain_points($names);
	$list = array();
	$worst = 'ok';
	$labels = array('cpanel' => 'the cPanel server', 'here' => 'this server', 'cloudflare' => 'Cloudflare (proxied)', 'other' => 'another server', 'none' => 'no address');
	foreach ($names as $n) {
		$ips = $pts[$n]['ips'] ?? array();
		$w = $where($ips);
		if (strpos($n, 'www.') === 0 && $w === 'none')
			continue;
		$list[] = $n.' → '.($ips ? implode(', ', $ips).' ' : '').'('.$labels[$w].')';
		if ($w === 'other' || ($w === 'none' && strpos($n, 'www.') !== 0))
			$worst = 'warn';
	}
	$items[] = transfer_item('DNS', $worst, 'Where the sites point now',
		$worst === 'warn' ? 'A site that points to another server, or nowhere, may already have moved or be unused: ask before transferring it.' : '', $list);
	// mail: MX and SPF
	$mlist = array();
	$mworst = 'ok';
	foreach ($mail_names as $d) {
		$mx = array();
		foreach ((array)@dns_get_record($d, DNS_MX) as $r)
			$mx[] = array('host' => strtolower($r['target'] ?? ''), 'pri' => (int)($r['pri'] ?? 0));
		usort($mx, function ($a, $b) { return $a['pri'] - $b['pri']; });
		$spf = '';
		foreach ((array)@dns_get_record($d, DNS_TXT) as $r)
			if (stripos($t = (string)($r['txt'] ?? ''), 'v=spf1') === 0)
				$spf = $t;
		if (!$mx) {
			$mlist[] = $d.': no MX record (gets no mail)';
			continue;
		}
		$top = (array)@gethostbynamel($mx[0]['host'].'.');
		$prov = array_intersect($top, $rips) ? 'local' : mail_provider_detect($mx, false);
		$pname = $prov === 'local' ? 'the cPanel server' : (mail_providers()[$prov]['name'] ?? $prov);
		$line = $d.': mail goes to '.$pname.' ('.$mx[0]['host'].')';
		if ($prov !== 'local') {
			$line .= ' - set it as the site\'s mail provider here; mailboxes are not needed';
			if ($mworst === 'ok') $mworst = 'info';
		}
		if ($spf === '')
			$line .= '; no SPF record';
		else
			foreach ($rips as $ip)
				if (stripos($spf, 'ip4:'.$ip) !== false || stripos($spf, 'ip6:'.$ip) !== false) {
					$line .= '; SPF names the cPanel server\'s address '.$ip.': add this server\'s at the switch';
					$mworst = 'warn';
					break;
				}
		$mlist[] = $line;
	}
	if ($mlist)
		$items[] = transfer_item('DNS', $mworst, 'Where mail goes now', '', $mlist);

	/* ---- cPanel features missing here ---- */
	$caldav = array();
	foreach ($sec['caldav'] ?? array() as $l) {
		$p = preg_split('/\s+/', trim($l));
		if (count($p) === 3)
			$caldav[] = $p[0].': '.$p[2].' '.($p[1] === 'ics' ? 'calendar entries' : 'contacts');
	}
	if ($caldav)
		$items[] = transfer_item('Features', 'warn', 'Calendars and contacts are used', 'This server has no calendar or contacts service. Ask the users to export them (from webmail or their phone) before the switch; they are not transferred.', $caldav);
	if (!empty($sec['webdisk']))
		$items[] = transfer_item('Features', 'warn', 'Web Disk accounts: '.count($sec['webdisk']), 'Web Disk is not available here. FTP accounts or the file manager can replace it.', $sec['webdisk']);
	if (!empty($sec['boxtrapper']))
		$items[] = transfer_item('Features', 'warn', 'BoxTrapper is switched on', 'BoxTrapper (challenge mails to unknown senders) is not available here; spam filtering is.', $sec['boxtrapper']);
	if (!empty($sec['fwddomain']))
		$items[] = transfer_item('Features', 'warn', 'Domain forwarders', 'A whole domain\'s mail forwarded to another domain is not available here: make forwarders for the addresses that are used.', $sec['fwddomain']);
	if (!empty($sec['htpasswds']))
		$items[] = transfer_item('Features', 'info', 'Password-protected folders: '.count($sec['htpasswds']),
			'With Apache they keep working, but their passwords are in ~/.htpasswds, outside the document root, so that folder has to be copied too (and the paths in .htaccess changed if the user name changes).', $sec['htpasswds']);
	$apps = json_decode(implode("\n", $sec['apps'] ?? array()), true);
	if (is_array($apps) && $apps) {
		$al = array();
		foreach ($apps as $name => $a)
			$al[] = (is_string($name) ? $name : ($a['name'] ?? '?')).(isset($a['path']) ? ' ('.$a['path'].')' : '').(isset($a['domain']) ? ' on '.$a['domain'] : '');
		$items[] = transfer_item('Features', 'warn', 'Applications (Application Manager): '.count($apps),
			'Ruby applications are not available here. Node.js and Python ones can run as apps here (Deployments, switched off by default) and are set up by hand.', $al);
	}
	if (!empty($sec['clsel']))
		$items[] = transfer_item('Features', 'warn', 'CloudLinux app selector folders', 'The account has Node.js / Python / Ruby environments of CloudLinux\'s selector. Node.js and Python apps are set up by hand here; Ruby is not available.', $sec['clsel']);
	if (!empty($sec['repos']))
		$items[] = transfer_item('Features', 'info', 'Git repositories: '.count($sec['repos']), 'They are files in the home folder. Here Git deployments can deploy a site from a repository.', $sec['repos']);
	$ftp = array_values(array_filter($sec['ftp'] ?? array(), function ($f) use ($user) { return $f !== $user && $f !== 'ftp' && $f !== $user.'_logs' && strpos($f, '#') !== 0; }));
	if ($ftp)
		$items[] = transfer_item('Features', is_file(_PATH.'/public_html/plugins/pureftpd/plugin.php') ? 'info' : 'warn', 'FTP accounts: '.count($ftp),
			is_file(_PATH.'/public_html/plugins/pureftpd/plugin.php') ? 'Make them again under Files > FTP accounts (passwords are not carried over).' : 'FTP accounts need the Pure-FTPd add-on here.', $ftp);
	if (!$caldav && empty($sec['webdisk']) && empty($sec['boxtrapper']) && empty($sec['fwddomain']) && !(is_array($apps) && $apps) && empty($sec['clsel']))
		$items[] = transfer_item('Features', 'ok', 'No cPanel feature this server lacks is in use', 'Looked for: calendars and contacts, Web Disk, BoxTrapper, domain forwarders, Application Manager and CloudLinux apps.');

	/* ---- mail settings ---- */
	foreach ($sec['maildomains'] ?? array() as $l) {
		$p = explode(' ', trim($l));
		if (count($p) === 2 && $p[1] === 'remote')
			$items[] = transfer_item('Mail', 'info', $p[0].': cPanel sends its mail to another server (Remote Mail Exchanger)', 'Set the site\'s mail provider here the same way.');
		elseif (count($p) === 2 && $p[1] === 'secondary')
			$items[] = transfer_item('Mail', 'warn', $p[0].': the cPanel server is a backup mail server for it', 'This is not available here.');
	}
	$mi = array();
	$site_names = array_column($sites, 'domain');
	foreach ($sec['mailips'] ?? array() as $l) {
		$p = preg_split('/\s+/', trim($l));
		if (count($p) === 3 && in_array($p[1], $site_names, true))
			$mi[$p[1]][strpos($p[0], 'mailhelo') !== false ? 'helo' : 'ip'] = $p[2];
	}
	if ($mi) {
		$ml = array();
		foreach ($mi as $d => $v)
			$ml[] = $d.': sends from '.($v['ip'] ?? 'the main address').(isset($v['helo']) ? ', HELO '.$v['helo'] : '');
		$items[] = transfer_item('Mail', 'info', 'Address and HELO name mail is sent with', 'Hosting Pro does this with Settings > IP addresses > Outgoing mail; the new server\'s address needs its own reverse DNS (PTR).', $ml);
	}
	foreach ($sec['outgoing'] ?? array() as $o)
		$items[] = transfer_item('Mail', 'warn', $o === 'hold' ? 'Outgoing mail of this account is held on the cPanel server' : 'Outgoing mail of this account is suspended on the cPanel server',
			'Probably because it sent spam. Find out why before it sends mail from this server.');
	$lim = array();
	$srv_max = (string)($ctx['maxemail'] ?? '');
	foreach ($sec['limits'] ?? array() as $l)
		if (preg_match('/^MAX_EMAIL_PER_HOUR(?:-([^=]+))?=(.*)$/', trim($l), $m) && $m[2] !== '' && $m[2] !== $srv_max
		    && !(in_array($m[2], array('unlimited', '0'), true) && in_array($srv_max, array('', '0', 'unlimited'), true)))
			$lim[] = ($m[1] !== '' ? $m[1] : 'the account').': '.(in_array($m[2], array('unlimited', '0'), true) ? 'no limit' : $m[2].' messages an hour');
	if ($lim)
		$items[] = transfer_item('Mail', 'info', 'Sending limits different from the server\'s'.($srv_max !== '' ? ' ('.$srv_max.' an hour)' : ''),
			'They come with the hosting package here.', $lim);
	$q = array();
	foreach ($sec['quotas'] ?? array() as $l) {
		$p = explode(' ', trim($l));
		if (count($p) === 3 && (float)$p[2] > 0)
			$q[] = $p[1].'@'.$p[0].': '.transfer_bytes((float)$p[2]);
	}
	$q = array_values(array_unique($q));
	$nbox = count(array_unique($sec['mailboxes'] ?? array()));
	if ($nbox)
		$items[] = transfer_item('Mail', 'info', 'Mailboxes: '.$nbox.($q ? ', '.count($q).' with a size limit' : ''),
			$q ? 'Mailbox size limits come with Hosting Pro here.' : '', $q);
	$spam = $sec['spam'] ?? array();
	if ($spam)
		$items[] = transfer_item('Mail', 'info', 'Spam filtering on the cPanel server', 'The allow/block lists are taken by the transfer ("Transfer spam filters").',
			array_map(function ($s) { return $s === 'enabled' ? 'SpamAssassin is on' : ($s === 'spambox' ? 'spam goes to a Spam folder' : trim($s)); }, $spam));

	/* ---- PHP settings and encoded files ---- */
	$mode = php_mode();
	$apache = web_php_server() === 'httpd';
	$specs = php_setting_specs();
	$scan_for = (array)($ctx['scan_for'] ?? array());
	$site_certs = array();
	/* what cPanel writes into every customised PHP-FPM pool */
	$fpm_defaults = array('allow_url_fopen' => 'on', 'log_errors' => 'on', 'short_open_tag' => 'on',
	                      'disable_functions' => 'show_source,system,shell_exec,passthru,exec,popen,proc_open');
	foreach ($sites as $s) {
		$ss = $site_sec[$s['domain']] ?? null;
		if (!$ss)
			continue;
		$by = array('userini' => array(), 'phpini' => array(), 'htaccess' => array(), 'htaccessg' => array(), 'fpm' => array());
		$cert = '';
		$enc = array();
		foreach ($ss['lines'] as $l) {
			$p = explode(' ', $l, 2);
			if ($p[0] === 'cert')
				$cert .= ($p[1] ?? '')."\n";
			elseif ($p[0] === 'encoded')
				$enc[] = substr($p[1] ?? '', strlen($ss['docroot']) + 1);
			elseif (isset($by[$p[0]]) && ($kv = transfer_ini_line($p[0] === 'htaccessg' ? 'htaccess' : $p[0], $p[1] ?? ''))) {
				// cPanel's pool files list doc_root / error_log (empty or its own paths) for every site
				if ($p[0] === 'fpm' && ($kv[1] === '' || in_array($kv[0], array('doc_root', 'error_log'), true)
				    || (isset($fpm_defaults[$kv[0]]) && strtolower(str_replace(' ', '', $kv[1])) === $fpm_defaults[$kv[0]])))
					continue;
				$by[$p[0]][$kv[0].'='.$kv[1]] = $kv;   // keyed: a line repeated in the file counts once
			}
		}
		/* $mark: say which ones PHP Settings cannot set (when they have to be set there) */
		$fmt = function (array $kvs, $mark = true) use ($specs) {
			return array_map(function ($kv) use ($specs, $mark) { return $kv[0].' = '.$kv[1].($mark && !isset($specs[$kv[0]]) ? '  (not one of PHP Settings\' choices)' : ''); }, $kvs);
		};
		/* cPanel writes its own folders into these files (session.save_path =
		   /var/cpanel/php/sessions/ea-php82): here they do not exist, and PHP-FPM
		   or mod_php would still use them - sessions (logins) fail */
		$cp = array();
		// lines in an <IfModule> of mod_php / LSAPI only count where mod_php runs them
		foreach (array('userini' => '.user.ini', 'htaccess' => '.htaccess') + ($mode === 'fpm' ? array() : array('htaccessg' => '.htaccess')) as $k => $f)
			foreach ($by[$k] as $kv)
				if (preg_match('#/(var|opt|usr/local)/cpanel/|/opt/alt/#', $kv[1]))
					$cp[] = $f.': '.$kv[0].' = '.$kv[1];
		$cp = array_values(array_unique($cp));
		if ($cp)
			$items[] = transfer_item('PHP', 'warn', $s['domain'].': settings that point at cPanel folders',
				'These folders do not exist here, so for example PHP sessions (logins) fail. Remove the lines after the transfer.', $cp);
		if ($by['htaccess']) {
			if ($mode === 'fpm' && $apache)
				$items[] = transfer_item('PHP', 'block', $s['domain'].': php_value lines in .htaccess',
					'With Apache and PHP-FPM these lines make every page fail (error 500). Take them out of .htaccess after the transfer and set the values in the site\'s PHP settings.', $fmt($by['htaccess']));
			elseif ($mode === 'fpm')
				$items[] = transfer_item('PHP', 'warn', $s['domain'].': php_value lines in .htaccess', 'nginx does not read .htaccess: set these in the site\'s PHP settings.', $fmt($by['htaccess']));
			else
				$items[] = transfer_item('PHP', 'ok', $s['domain'].': php_value lines in .htaccess keep working with mod_php', '', $fmt($by['htaccess'], false));
		}
		if ($by['htaccessg'])
			$items[] = $mode === 'fpm'
				? transfer_item('PHP', 'info', $s['domain'].': php_value lines in .htaccess, inside <IfModule> blocks for mod_php / LSAPI',
					'With PHP-FPM those blocks are skipped, so they do no harm, but their values do not apply either: set the ones that matter in the site\'s PHP settings.', $fmt($by['htaccessg']))
				: transfer_item('PHP', 'ok', $s['domain'].': php_value lines in .htaccess (inside <IfModule> blocks) work with mod_php', '', $fmt($by['htaccessg'], false));
		if ($by['userini'])
			$items[] = $mode === 'fpm'
				? transfer_item('PHP', 'ok', $s['domain'].': .user.ini comes with the files and PHP-FPM reads it', '', $fmt($by['userini'], false))
				: transfer_item('PHP', 'warn', $s['domain'].': .user.ini is not read by mod_php', 'Set these in the site\'s PHP settings.', $fmt($by['userini']));
		if ($by['phpini'])
			$items[] = transfer_item('PHP', 'warn', $s['domain'].': php.ini in the document root is not read here', 'Only cPanel\'s CGI / suPHP handlers read it. Set what is needed in the site\'s PHP settings.', $fmt($by['phpini']));
		if ($by['fpm'])
			$items[] = transfer_item('PHP', 'warn', $s['domain'].': PHP-FPM settings made in cPanel', 'They are in cPanel\'s own configuration and are not carried over: set them in the site\'s PHP settings.', $fmt($by['fpm']));
		if ($enc) {
			$need = $scan_for[$s['version']] ?? array();
			$items[] = transfer_item('PHP', $need ? 'block' : 'info', $s['domain'].': encoded PHP files (ionCube / SourceGuardian)',
				$need ? 'Install the '.implode(' / ', $need).' loader for PHP '.$s['version'].' here first, or these files do not run.' : 'Their loader is installed here.', $enc);
		}
		$site_certs[$s['domain']] = $cert;
	}

	/* ---- databases ---- */
	$dbj = json_decode(implode("\n", $sec['dbs'] ?? array()), true);
	$dbs = array();
	$dbusers = array();
	foreach ((array)($dbj['data']['mysql_databases'] ?? array()) as $name => $us) {
		if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name))
			continue;
		$dbs[] = $name;
		foreach ((array)$us as $u2)
			if (is_string($u2) && preg_match('/^[A-Za-z0-9_]{1,80}$/', $u2))
				$dbusers[$u2] = true;
	}
	$db_bytes = 0;
	if ($dbs) {
		$in = "'".implode("','", $dbs)."'";
		$uin = $dbusers ? "'".implode("','", array_keys($dbusers))."'" : "''";
		$sql = "SELECT 'size', TABLE_SCHEMA, COALESCE(SUM(DATA_LENGTH+INDEX_LENGTH),0), COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA IN ($in) GROUP BY TABLE_SCHEMA;\n"
		     . "SELECT 'coll', TABLE_SCHEMA, TABLE_COLLATION, COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA IN ($in) AND TABLE_COLLATION IS NOT NULL GROUP BY TABLE_SCHEMA, TABLE_COLLATION;\n"
		     . "SELECT 'coll', TABLE_SCHEMA, COLLATION_NAME, COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA IN ($in) AND COLLATION_NAME IS NOT NULL GROUP BY TABLE_SCHEMA, COLLATION_NAME;\n"
		     . "SELECT 'coll', SCHEMA_NAME, DEFAULT_COLLATION_NAME, 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME IN ($in);\n"
		     . "SELECT 'engine', TABLE_SCHEMA, ENGINE, COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA IN ($in) AND ENGINE IS NOT NULL GROUP BY TABLE_SCHEMA, ENGINE;\n"
		     . "SELECT 'definer', TABLE_SCHEMA, DEFINER, COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA IN ($in) GROUP BY TABLE_SCHEMA, DEFINER;\n"
		     . "SELECT 'definer', TRIGGER_SCHEMA, DEFINER, COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA IN ($in) GROUP BY TRIGGER_SCHEMA, DEFINER;\n"
		     . "SELECT 'definer', ROUTINE_SCHEMA, DEFINER, COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA IN ($in) GROUP BY ROUTINE_SCHEMA, DEFINER;\n"
		     . "SELECT 'plugin', User, plugin, 1 FROM mysql.user WHERE User IN ($uin);\n";
		$rows = array();
		foreach (explode("\n", transfer_remote($ssh, 'mysql -N -B 2>/dev/null', $sql, 120)) as $l) {
			$p = explode("\t", $l);
			if (count($p) === 4)
				$rows[$p[0]][] = $p;
		}
		$have_coll = array_flip(array_map(function ($l) { return explode("\t", $l)[0]; }, mysql_rows('SHOW COLLATION')));
		$have_eng = array();
		foreach (mysql_rows('SHOW ENGINES') as $l) {
			$p = explode("\t", $l);
			if (in_array(strtoupper($p[1] ?? ''), array('YES', 'DEFAULT'), true))
				$have_eng[strtolower($p[0])] = true;
		}
		$sizes = array();
		foreach ($rows['size'] ?? array() as $r) {
			$sizes[$r[1]] = (float)$r[2];
			$db_bytes += (float)$r[2];
		}
		$bad_coll = $bad_eng = $definers = array();
		foreach ($rows['coll'] ?? array() as $r) {
			$alt = strpos($r[2], 'utf8mb3_') === 0 ? 'utf8_'.substr($r[2], 8) : (strpos($r[2], 'utf8_') === 0 ? 'utf8mb3_'.substr($r[2], 5) : '');
			if (!isset($have_coll[$r[2]]) && ($alt === '' || !isset($have_coll[$alt])))
				$bad_coll[$r[2]][$r[1]] = true;
		}
		foreach ($rows['engine'] ?? array() as $r)
			if (!isset($have_eng[strtolower($r[2])]))
				$bad_eng[] = $r[1].': '.$r[2].' ('.$r[3].' tables)';
		foreach ($rows['definer'] ?? array() as $r)
			$definers[] = $r[1].': '.$r[3].' made by '.$r[2];
		$dl = array();
		foreach ($dbs as $d)
			$dl[] = $d.': '.transfer_bytes($sizes[$d] ?? 0);
		$items[] = transfer_item('Databases', 'info', 'Databases: '.count($dbs).', '.transfer_bytes($db_bytes), '', $dl);
		if ($bad_coll) {
			$bl = array();
			foreach ($bad_coll as $c => $in_dbs)
				$bl[] = $c.' (in '.implode(', ', array_keys($in_dbs)).')';
			$items[] = transfer_item('Databases', 'block', 'Collations this server\'s MariaDB does not have',
				'The import stops at the first table that uses one. The dump has to be changed on the way (utf8mb4_0900_* to utf8mb4_unicode_520_ci, for example).', $bl);
		}
		if ($bad_eng)
			$items[] = transfer_item('Databases', 'block', 'Table types this server does not have', '', $bad_eng);
		if ($definers)
			$items[] = transfer_item('Databases', 'info', 'Views, triggers and routines with an owner',
				'They keep their owner\'s name; if the database user is named differently here they stop working until the owner is changed.', $definers);
		$sha2 = array();
		foreach ($rows['plugin'] ?? array() as $r)
			if (!in_array($r[2], array('mysql_native_password', ''), true))
				$sha2[] = $r[1].' ('.$r[2].')';
		if ($sha2)
			$items[] = transfer_item('Databases', 'warn', 'Database users whose password cannot be moved',
				'Their passwords are stored in a form MariaDB cannot read. Give them a new password here and put it in the site\'s configuration (wp-config.php and the like).', array_values(array_unique($sha2)));
		$long = array();
		foreach ($dbs as $d)
			if (strlen($d) > 64)
				$long[] = $d;
		foreach (array_keys($dbusers) as $u2)
			if (strlen($u2) > database_user_max())
				$long[] = $u2.' (users: at most '.database_user_max().' characters here)';
		if ($long)
			$items[] = transfer_item('Databases', 'warn', 'Names too long for this server', '', $long);
	} else
		$items[] = transfer_item('Databases', 'ok', 'No databases');

	/* ---- cron jobs ---- */
	$main_php = '';
	foreach ($sites as $s)
		if ($s['type'] === 'main')
			$main_php = $s['version'];
	$cron = transfer_cron_convert(implode("\n", $sec['cron'] ?? array()), $user, $user, $main_php ?: (string)($GLOBALS['ini']['php'] ?? ''));
	if ($cron['jobs'] || $cron['skipped']) {
		$cl = array();
		$paused = $noted = 0;
		foreach ($cron['jobs'] as $j) {
			$paused += $j['on'] ? 0 : 1;
			$noted += $j['notes'] ? 1 : 0;
			$cl[] = $j['schedule'].' '.$j['command'].($j['command'] !== $j['original'] ? "\n    was: ".$j['original'] : '')
			      .($j['notes'] ? "\n    ! ".implode("\n    ! ", $j['notes']) : '');
		}
		foreach ($cron['skipped'] as $sk)
			$cl[] = $sk[0]."\n    ! not taken: ".$sk[1];
		$items[] = transfer_item('Cron jobs', ($paused || $cron['skipped']) ? 'warn' : ($noted || $cron['notes'] ? 'info' : 'ok'),
			'Cron jobs: '.count($cron['jobs']).($paused ? ', '.$paused.' will come in paused' : '').($cron['skipped'] ? ', '.count($cron['skipped']).' not taken' : ''),
			trim('The transfer changes them like this (cPanel\'s PHP to this server\'s, same version). '.implode(' ', $cron['notes'])), $cl, true);
	} else
		$items[] = transfer_item('Cron jobs', 'ok', 'No cron jobs');

	/* ---- size ---- */
	$disk = json_decode(implode("\n", $sec['disk'] ?? array()), true);
	$used = $files = null;
	foreach ((array)($disk['data']['accounts'] ?? array()) as $a)
		if (($a['user'] ?? '') === $user) {
			$used = isset($a['blocks_used']) ? (float)$a['blocks_used'] * 1024 : null;
			$files = isset($a['inodes_used']) ? (int)$a['inodes_used'] : null;
		}
	$mail_bytes = (float)($sec['maildu'][0] ?? 0);
	$sl = array();
	if ($used !== null) $sl[] = 'home folder: '.transfer_bytes($used).($files !== null ? ', '.number_format($files).' files' : '');
	if ($mail_bytes) $sl[] = 'of it mail: '.transfer_bytes($mail_bytes);
	$sl[] = 'databases: '.transfer_bytes($db_bytes);
	$total = (float)$used + $db_bytes;
	$free = (float)($ctx['free'] ?? 0);
	$items[] = transfer_item('Size', $free && $total > $free * 0.8 ? 'block' : 'info',
		'Size: '.transfer_bytes($total).($free && $total > $free * 0.8 ? ' - more than this server can take' : ''), '', $sl);

	/* ---- certificates ---- */
	$cl = array();
	$paid = 0;
	foreach ($site_certs as $d => $pem) {
		if (trim($pem) === '')
			continue;
		$x = @openssl_x509_parse($pem);
		if (!$x)
			continue;
		$iss = $x['issuer']['O'] ?? ($x['issuer']['CN'] ?? '?');
		$isscn = $x['issuer']['CN'] ?? '';
		$to = (int)($x['validTo_time_t'] ?? 0);
		$days = (int)round(($to - (int)($x['validFrom_time_t'] ?? 0)) / 86400);
		$self = ($x['issuer'] ?? null) == ($x['subject'] ?? null);
		$free_ca = preg_match("/let's encrypt|cpanel|zerossl|google trust/i", $iss.' '.$isscn) || $days <= 100;
		if ($self)
			$kind = 'self-signed';
		elseif ($to < time())
			$kind = 'expired';
		elseif ($free_ca)
			$kind = 'free (AutoSSL / Let\'s Encrypt)';
		else {
			$kind = 'PAID';
			$paid++;
		}
		$cl[] = $d.': '.$kind.', '.$iss.', until '.date('Y-m-d', $to);
	}
	if ($cl)
		$items[] = transfer_item('Certificates', $paid ? 'warn' : 'ok', $paid ? $paid.' paid certificate'.($paid === 1 ? '' : 's') : 'Certificates',
			$paid ? 'A paid certificate is copied with the site (the transfer does this); renew it here before it ends. Free ones are issued again here.' : 'Free certificates are issued again here after the switch.', $cl);
	return $items;
}

/* ---- Transfer tool: the sites of cPanel accounts --------------------------------
   Every site (main, addon, subdomain, alias) of the accounts $users with its
   document root and PHP version: cPanel's MultiPHP choice, or the AddHandler
   line of its .htaccess, which wins for the non-FPM handlers. Used by the PHP
   step of the page and by the transfer itself.
   array(error, accounts: [{user, domain, sites[]}], versions, system_default, local_versions) */
function transfer_sites($ssh, array $users) {
	$want = array_flip($users);
	list($vh_data, $dom_data, $def_data) = transfer_whmapi($ssh, [
		'php_get_vhost_versions',
		'get_domain_info',
		'php_get_system_default_version',
	]);
	if($vh_data === null || $dom_data === null)
		return ['error' => 'Could not read the PHP versions from the cPanel server (whmapi1 php_get_vhost_versions / get_domain_info).'];
	$sys_default = (string)($def_data['version'] ?? '');

	$vhosts = [];
	foreach((array)($vh_data['versions'] ?? []) as $v)
		if(isset($want[$v['account'] ?? ''])) $vhosts[strtolower($v['vhost'])] = $v;

	$doms = []; $twins = []; $main_of = [];
	foreach((array)($dom_data['domains'] ?? []) as $d) {
		if(!isset($want[$d['user'] ?? ''])) continue;
		$doms[] = $d;
		// every addon domain has a subdomain twin on cPanel (addon.main.tld), not a site of its own
		if(($d['domain_type'] ?? '') === 'addon' && !empty($d['parent_domain'])) $twins[strtolower($d['parent_domain'])] = true;
		if(($d['domain_type'] ?? '') === 'main') $main_of[$d['user']] = strtolower($d['domain']);
	}

	$sites = [];
	foreach($doms as $d) {
		$name = strtolower((string)$d['domain']);
		$type = (string)($d['domain_type'] ?? '');
		if($type === 'sub' && isset($twins[$name])) continue;
		$vh_name = ($type === 'parked') ? strtolower($d['parent_domain'] ?? ($main_of[$d['user']] ?? '')) : $name;
		$vh = $vhosts[$vh_name] ?? null;
		$pkg = (string)($vh['version'] ?? '');
		if($pkg === '' || $pkg === 'inherit') $pkg = $sys_default;
		$src = (array)($vh['phpversion_source'] ?? []);
		if(!empty($src['system_default']))      $source = 'server default';
		elseif(!empty($src['domain']) && strtolower($src['domain']) !== $vh_name) $source = 'from '.strtolower($src['domain']);
		elseif(!empty($src['domain']))         $source = 'set for the site';
		else                                   $source = '';
		if($type === 'parked') $source = 'alias of '.$vh_name;
		$docroot = (string)($d['docroot'] ?? ($vh['documentroot'] ?? ''));
		$sites[] = [
			'user'     => (string)$d['user'],
			'domain'   => $name,
			'type'     => $type,
			'docroot'  => $docroot,
			'package'  => $pkg,
			'version'  => transfer_php_version($pkg),
			'source'   => $source,
			'fpm'      => !empty($vh['php_fpm']),
			'htaccess' => '',
			// the cPanel name of its vhost: an addon domain's is its twin subdomain
			'twin'     => ($type === 'addon') ? strtolower((string)($d['parent_domain'] ?? '')) : '',
			// its addresses on cPanel (the IP map)
			'ip4'      => (string)($d['ipv4'] ?? ''),
			'ip6'      => (string)($d['ipv6'] ?? ''),
			'ip6_own'  => !empty($d['ipv6_is_dedicated']),
		];
	}

	/* cPanel sets a site's PHP for the CGI / suPHP / LSAPI handlers with an
	   AddHandler line in the document root's .htaccess, and a hand-edited one
	   wins over what cPanel shows. Read that line too (as root on the cPanel
	   server; the folders go in on stdin, never into the command). */
	$roots = [];
	foreach($sites as $s)
		if(preg_match('#^/[A-Za-z0-9._@+\-/]+$#', $s['docroot']) && strpos($s['docroot'], '/..') === false)
			$roots[$s['docroot']] = true;
	$found = [];
	if($roots) {
		$tmp = tempnam(sys_get_temp_dir(), 'rqtf');
		file_put_contents($tmp, implode("\n", array_keys($roots))."\n");
		$script = 'while IFS= read -r d; do '
		        . 'h=$(grep -v "^[[:space:]]*#" "$d/.htaccess" 2>/dev/null | grep -ioE "x-httpd-[a-z0-9-]*php[0-9]+" | tail -n1); '
		        . 'echo "$d|$h"; done';
		$p = proc_open($ssh.' '.escapeshellarg($script), [0 => ['file', $tmp, 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
		if(is_resource($p)) {
			foreach(explode("\n", (string)stream_get_contents($pipes[1])) as $line) {
				$i = strrpos($line, '|');
				if($i !== false) $found[substr($line, 0, $i)] = transfer_php_version(substr($line, $i + 1));
			}
			fclose($pipes[1]);
			proc_close($p);
		}
		@unlink($tmp);
	}

	$versions = [];
	foreach($sites as &$s) {
		$h = $found[$s['docroot']] ?? '';
		if($h !== '' && $h !== $s['version']) {
			$s['htaccess'] = $h;
			// PHP-FPM is chosen in the vhost, so there the .htaccess line does nothing
			if(!$s['fpm']) $s['version'] = $h;
		}
		$v = $s['version'];
		if($v === '') continue;
		if(!isset($versions[$v])) {
			$versions[$v] = transfer_php_local($v) + ['sites' => 0];
			// what the transfer uses unless another one is chosen
			if($versions[$v]['status'] !== 'installed') $versions[$v]['instead'] = transfer_php_nearest($v);
		}
		$versions[$v]['sites']++;
		$s['local'] = $versions[$v]['status'];
	}
	unset($s);
	uksort($versions, function($a, $b) { return version_compare($b, $a); });

	$accounts = [];
	foreach(array_keys($want) as $u) $accounts[$u] = ['user' => $u, 'domain' => $main_of[$u] ?? '', 'sites' => []];
	$order = ['main' => 0, 'addon' => 1, 'sub' => 2, 'parked' => 3];
	usort($sites, function($a, $b) use ($order) {
		return [$order[$a['type']] ?? 9, $a['domain']] <=> [$order[$b['type']] ?? 9, $b['domain']];
	});
	foreach($sites as $s) $accounts[$s['user']]['sites'][] = $s;

	return [
		'error'          => '',
		'accounts'       => array_values($accounts),
		'versions'       => $versions,
		'system_default' => transfer_php_version($sys_default),
		'local_versions' => array_column(php_versions(), 'version'),
	];
}

/* ---- Certificates brought from another server ---------------------------------
   Serve $domain with the certificate $cert (PEM: the certificate, then its
   chain) and its private key $key, the way the SSL page's "own certificate"
   does: /etc/ssl/certs/<domain>_newcert.pem + _privkey.pem, named in the
   front server's vhost. Checked first (a certificate, not expired, the key
   fits it); the vhost is put back when the web server refuses the change.
   $reload false: the caller reloads the web server once for several sites.
   Returns '' or why not. */
function ssl_custom_install($domain, $cert, $key, $reload = true) {
	global $ini;
	$domain = strtolower(trim((string)$domain));
	if (!valid_domain($domain))
		return 'Not a domain name.';
	$x = @openssl_x509_read((string)$cert);
	$k = @openssl_pkey_get_private((string)$key);
	if ($x === false || $k === false)
		return 'Not a certificate and private key in PEM form.';
	$info = openssl_x509_parse($x);
	if (!is_array($info) || (int)($info['validTo_time_t'] ?? 0) < time())
		return 'The certificate has expired.';
	if (!openssl_x509_check_private_key($x, $k))
		return 'The private key does not belong to the certificate.';

	$server = web_front($ini);
	$ws = web_server($server);
	$vhost = web_vhost_path($domain, $server);
	$conf = (string)shell_exec('sudo -n cat '.escapeshellarg($vhost).' 2>/dev/null');
	if ($conf === '')
		return "$domain has no vhost here ($vhost).";
	$crt_file = '/etc/ssl/certs/'.$domain.'_newcert.pem';
	$key_file = '/etc/ssl/certs/'.$domain.'_privkey.pem';
	if ($server === 'httpd') {
		$new = preg_replace('/^([ \t]*SSLCertificateFile[ \t]+)\S+[ \t]*$/m', '${1}'.$crt_file, $conf);
		$new = preg_replace('/^([ \t]*SSLCertificateKeyFile[ \t]+)\S+[ \t]*$/m', '${1}'.$key_file, $new);
		$new = preg_replace('/^[ \t]*SSLCertificateChainFile[ \t][^\n]*\n/m', '', $new);   // the chain is in the certificate file
	} else {
		$new = preg_replace('/^([ \t]*ssl_certificate[ \t]+)[^;\n]+;/m', '${1}'.$crt_file.';', $conf);
		$new = preg_replace('/^([ \t]*ssl_certificate_key[ \t]+)[^;\n]+;/m', '${1}'.$key_file.';', $new);
	}
	if ($new === $conf && strpos($conf, $crt_file) === false)
		return "The vhost of $domain names no certificate to replace.";

	$pem = trim((string)$cert)."\n";
	shell_with_stdin('sudo -n tee '.escapeshellarg($crt_file).' > /dev/null', $pem);
	shell_with_stdin('sudo -n sh -c '.escapeshellarg('umask 077; cat > '.escapeshellarg($key_file)), trim((string)$key)."\n");
	shell_exec('sudo -n chmod 600 '.escapeshellarg($key_file).'; sudo -n chmod 644 '.escapeshellarg($crt_file));
	if ($new !== $conf) {
		shell_with_stdin('sudo -n tee '.escapeshellarg($vhost).' > /dev/null', $new);
		exec('sudo -n '.$ws['test'].' 2>&1', $out, $rc);
		if ($rc !== 0) {
			shell_with_stdin('sudo -n tee '.escapeshellarg($vhost).' > /dev/null', $conf);
			return $ws['label'].' refused the change: '.trim(implode(' ', array_slice($out, -2)));
		}
	}
	if ($reload)
		shell_exec('sudo -n systemctl reload '.escapeshellarg($ws['service']).' 2>&1');
	route_log("install certificate for $domain (".($info['issuer']['O'] ?? ($info['issuer']['CN'] ?? '?')).')');
	return '';
}

/* ---- Transfer tool: transfer jobs ---------------------------------------------
   The page starts a job for the accounts it has checked; the job runs as root
   in its own systemd unit (reqad-transfer-<id>, scripts/transfer_cpanel.php),
   one account after the other, so closing the page does not stop it. In
   _PATH/transfers: job-<id>.json (what to do, written by the panel),
   job-<id>.state.json (how far it is, written by the job) and
   job-<id>.<account>.log (what it did). No passwords or keys in any of them. */

function transfer_job_dir() {
	return _PATH.'/transfers';
}

function transfer_job_file($id, $kind = '') {
	return transfer_job_dir().'/job-'.$id.($kind === '' ? '.json' : '.'.$kind);
}

function transfer_job_valid_id($id) {
	return preg_match('/^[a-f0-9]{16}$/', (string)$id) === 1;
}

/* What the transfer can bring, all on unless the page says otherwise */
function transfer_job_parts() {
	return array('files' => 'Files', 'databases' => 'Databases', 'mail' => 'Email (mailboxes, mail, forwarders, filters, autoresponders, spam lists, DKIM)',
	             'cron' => 'Cron jobs', 'ssl' => 'Certificates');
}

function transfer_job_get($id) {
	if (!transfer_job_valid_id($id))
		return null;
	$j = json_decode((string)@file_get_contents(transfer_job_file($id)), true);
	return is_array($j) ? $j : null;
}

function transfer_job_state($id) {
	$s = json_decode((string)@file_get_contents(transfer_job_file($id, 'state.json')), true);
	return is_array($s) ? $s : array();
}

function transfer_job_active($id) {
	return transfer_job_valid_id($id)
	    && trim((string)shell_exec('systemctl is-active '.escapeshellarg('reqad-transfer-'.$id).' 2>/dev/null')) === 'active';
}

/* the newest job, or null */
function transfer_job_latest() {
	$files = glob(transfer_job_dir().'/job-*.json') ?: array();
	$files = array_filter($files, function ($f) { return preg_match('#/job-[a-f0-9]{16}\.json$#', $f); });
	usort($files, function ($a, $b) { return filemtime($b) - filemtime($a); });
	return $files ? transfer_job_get(substr(basename($files[0]), 4, 16)) : null;
}

/*
 * Start a transfer. $conn: server, port, ruser. $accounts: rows from the
 * account list (user, domain, diskused); each keeps its user name here.
 * $parts: transfer_job_parts() keys to take. data: id.
 */
function transfer_job_start(array $conn, array $accounts, array $parts, array $php_map = array()) {
	$ssh = transfer_ssh((string)($conn['server'] ?? ''), (int)($conn['port'] ?? 22), (string)($conn['ruser'] ?? ''), $err);
	if ($err !== '')
		return result_error($err);
	$latest = transfer_job_latest();
	if ($latest && transfer_job_active($latest['id']))
		return result_error('A transfer is already running. Wait for it to end, or stop it.');
	$list = array();
	foreach ($accounts as $a) {
		$u = (string)($a['user'] ?? '');
		if (!valid_username($u))
			return result_error("$u cannot be an account name here (2-16 lowercase letters and digits, starting with a letter).");
		$list[$u] = array('user' => $u, 'domain' => strtolower((string)($a['domain'] ?? '')),
		                  'diskused' => preg_match('/^[0-9.]+[KMGT]?$/i', (string)($a['diskused'] ?? '')) ? (string)$a['diskused'] : '');
	}
	if (!$list)
		return result_error('Choose at least one account.');
	$take = array();
	foreach (transfer_job_parts() as $k => $label)
		$take[$k] = in_array($k, $parts, true);
	if (!array_filter($take))
		return result_error('Choose at least one thing to transfer.');

	$id = bin2hex(random_bytes(8));
	$dir = transfer_job_dir();
	if (!is_dir($dir))
		@mkdir($dir, 0750, true);
	$job = array('id' => $id, 'server' => (string)$conn['server'], 'port' => (int)$conn['port'], 'ruser' => (string)$conn['ruser'],
	             'accounts' => array_values($list), 'parts' => $take, 'php_map' => transfer_php_map($php_map), 'created' => time(), 'by' => function_exists('auth_user') ? (string)auth_user() : '');
	if (@file_put_contents(transfer_job_file($id), json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false)
		return result_error("Could not write the job file in $dir.");
	@chmod(transfer_job_file($id), 0640);
	$cmd = server_sudo().'systemd-run --quiet --collect --unit='.escapeshellarg('reqad-transfer-'.$id)
	     .' --description='.escapeshellarg('Reqad transfer of '.count($list).' cPanel account(s) from '.$job['server'])
	     .' -p Nice=5 -p IOSchedulingClass=best-effort -p IOSchedulingPriority=6'
	     .' -- /opt/reqad/php-current/usr/bin/php -c /etc/reqad/php-fpm/php.ini '._PATH.'/scripts/transfer_cpanel.php --job='.$id.' 2>&1';
	transfer_job_log($id, 'Transfer from '.$job['server'].' asked by '.transfer_actor().': '.implode(', ', array_keys($list))
	                    .'. Parts: '.implode(', ', array_keys(array_filter($take))).($job['php_map'] ? '. PHP versions replaced: '
	                    .implode(', ', array_map(function ($f, $t) { return "$f -> $t"; }, array_keys($job['php_map']), $job['php_map'])) : '').'.');
	exec($cmd, $out, $rc);
	if ($rc !== 0) {
		transfer_job_log($id, 'Could not start: '.trim(implode(' ', $out)));
		return result_error('Could not start the transfer: '.trim(implode(' ', $out)));
	}
	route_log('transfer from '.$job['server'].': '.implode(', ', array_keys($list)));
	return result_ok('Transfer started.', array('id' => $id));
}

/* The job, its progress and what each account's log got since $offsets
   (account => bytes already shown). */
function transfer_job_status($id, array $offsets = array()) {
	$job = transfer_job_get($id);
	if (!$job)
		return result_error('No such transfer.');
	$state = transfer_job_state($id);
	$active = transfer_job_active($id);
	$accounts = array();
	foreach ($job['accounts'] as $a) {
		$u = $a['user'];
		$st = (array)($state['accounts'][$u] ?? array());
		$log = '';
		$off = max(0, (int)($offsets[$u] ?? 0));
		$f = transfer_job_file($id, $u.'.log');
		$size = is_file($f) ? (int)filesize($f) : 0;
		if ($size > $off)
			$log = (string)@file_get_contents($f, false, null, $off, min(262144, $size - $off));
		$status = (string)($st['status'] ?? 'queued');
		if (!$active && in_array($status, array('queued', 'running'), true))
			$status = 'stopped';
		$accounts[] = array('user' => $u, 'domain' => $a['domain'], 'status' => $status, 'step' => (string)($st['step'] ?? ''),
		                    'detail' => (string)($st['detail'] ?? ''), 'warnings' => (int)($st['warnings'] ?? 0),
		                    'started' => (int)($st['started'] ?? 0), 'finished' => (int)($st['finished'] ?? 0),
		                    'log' => $log, 'offset' => $off + strlen($log));
	}
	$status = (string)($state['status'] ?? 'queued');
	if (!$active && in_array($status, array('queued', 'running'), true))
		$status = 'stopped';
	return result_ok('', array('id' => $id, 'server' => $job['server'], 'parts' => $job['parts'], 'created' => $job['created'],
	                           'by' => (string)($job['by'] ?? ''), 'ips' => is_file(transfer_job_file($id, 'ips')),
	                           'joblog' => (string)@file_get_contents(transfer_job_file($id, 'log'), false, null, 0, 65536),
	                           'status' => $status, 'active' => $active, 'accounts' => $accounts));
}

function transfer_job_stop($id) {
	if (!transfer_job_get($id))
		return result_error('No such transfer.');
	if (!transfer_job_active($id))
		return result_ok('The transfer is not running.');
	shell_exec(server_sudo().'systemctl stop '.escapeshellarg('reqad-transfer-'.$id).' 2>&1');
	route_log("transfer $id stopped");
	transfer_job_log($id, 'Stopped by '.transfer_actor().'.');
	return result_ok('Transfer stopped. What was done so far stays; start the account again to finish it.');
}

/* ============== Transfer tool: history, logs and the IP address map ==============
   Each transfer keeps its files in transfers/ for good, like a transfer session
   on cPanel: job-<id>.json (what was asked), .state.json (progress), .log (the
   transfer's own log: started, each account's end, stopped, finished),
   .<user>.log (each account's log) and .ips (the IP map: the address each site
   and its mail had on the cPanel server). The IP map is plain text, one line per
   site, so it can be kept, edited and read back to give the accounts their
   addresses here once the addresses have moved to this server
   (transfer_ip_restore(); restores are logged in transfers/ip-restores.log). */

/* Append lines to a transfer log, each with the date and time. A file made by
   root (the runner, the CLI) is given to reqad, so the panel can add to it. */
function transfer_log_append($file, $msg) {
	$new = !is_file($file);
	$lines = '';
	foreach (explode("\n", rtrim((string)$msg, "\n")) as $l)
		$lines .= date('Y-m-d H:i:s').' '.$l."\n";
	if (@file_put_contents($file, $lines, FILE_APPEND) === false)
		return false;
	if ($new) {
		@chmod($file, 0640);
		if (function_exists('posix_geteuid') && posix_geteuid() === 0)
			@chown($file, 'reqad');
		@chgrp($file, 'reqad');
	}
	return true;
}

function transfer_job_log($id, $msg) {
	return transfer_job_valid_id($id) && transfer_log_append(transfer_job_file($id, 'log'), $msg);
}

/* Who did it: the panel's admin, or the CLI's user */
function transfer_actor() {
	$by = function_exists('auth_user') ? (string)auth_user() : '';
	return $by !== '' ? $by : (PHP_SAPI === 'cli' ? 'cli' : 'admin');
}

/* Every transfer, newest first: id, server, created, finished, by, status,
   active, accounts (count), done / warn / failed, warnings, ips (has a map). */
function transfer_job_list() {
	$out = array();
	foreach (glob(transfer_job_dir().'/job-*.json') ?: array() as $f) {
		if (!preg_match('#/job-([a-f0-9]{16})\.json$#', $f, $m))
			continue;
		$job = transfer_job_get($m[1]);
		if (!$job)
			continue;
		$st = transfer_job_state($m[1]);
		$status = (string)($st['status'] ?? 'queued');
		/* only a job that says it runs asks systemd */
		$active = in_array($status, array('queued', 'running'), true) && transfer_job_active($m[1]);
		if (!$active && in_array($status, array('queued', 'running'), true))
			$status = 'stopped';
		$n = array('done' => 0, 'warn' => 0, 'failed' => 0, 'warnings' => 0);
		foreach ((array)($st['accounts'] ?? array()) as $a) {
			$s = (string)($a['status'] ?? '');
			if (isset($n[$s]))
				$n[$s]++;
			$n['warnings'] += (int)($a['warnings'] ?? 0);
		}
		$out[] = array('id' => $m[1], 'server' => (string)$job['server'], 'created' => (int)$job['created'],
		               'finished' => (int)($st['finished'] ?? 0), 'by' => (string)($job['by'] ?? ''), 'status' => $status, 'active' => $active,
		               'accounts' => count($job['accounts']), 'users' => array_column($job['accounts'], 'user'),
		               'done' => $n['done'], 'warn' => $n['warn'], 'failed' => $n['failed'], 'warnings' => $n['warnings'],
		               'ips' => is_file(transfer_job_file($m[1], 'ips')));
	}
	usort($out, function ($a, $b) { return $b['created'] - $a['created']; });
	return $out;
}

/* A transfer's log as one text: its own log, then each account's ($user: only
   that account's). data: name (a file name to save it as), text. */
function transfer_job_log_text($id, $user = '') {
	$job = transfer_job_get($id);
	if (!$job)
		return result_error('No such transfer.');
	$users = array_column($job['accounts'], 'user');
	if ($user !== '' && !in_array($user, $users, true))
		return result_error("The transfer $id did not have the account $user.");
	$text = '';
	if ($user === '') {
		$text = "Transfer $id from {$job['server']}, started ".date('Y-m-d H:i', (int)$job['created'])
		      .($job['by'] !== '' ? " by {$job['by']}" : '')."\nAccounts: ".implode(', ', $users)."\n\n";
		$own = (string)@file_get_contents(transfer_job_file($id, 'log'));
		if ($own !== '')
			$text .= $own."\n";
	}
	foreach ($user === '' ? $users : array($user) as $u) {
		$l = (string)@file_get_contents(transfer_job_file($id, $u.'.log'));
		$text .= ($user === '' ? "==================== $u ====================\n" : '').($l !== '' ? $l : "(nothing logged)\n").($user === '' ? "\n" : '');
	}
	return result_ok('', array('name' => 'transfer-'.date('Ymd-Hi', (int)$job['created']).($user !== '' ? '-'.$user : '').'.log', 'text' => $text));
}

/* --- the IP map --- */

function transfer_ip_map_columns() {
	return array('domain', 'account', 'kind', 'ipv4', 'ipv4_use', 'ipv6', 'ipv6_use', 'mail_from');
}

/* The map as text: rows of transfer_ip_map_columns() keys ('' = '-'). */
function transfer_ip_map_text(array $rows, $title) {
	$w = array(30, 16, 6, 15, 9, 39, 9, 0);
	$line = function (array $v) use ($w) {
		$s = '';
		foreach (array_values($v) as $i => $x)
			$s .= $w[$i] ? str_pad($x, $w[$i]).' ' : $x;
		return rtrim($s)."\n";
	};
	$t = "# Reqad transfer IP map: $title\n"
	   . "# The address each site had on the cPanel server (ipv4_use / ipv6_use: dedicated = the\n"
	   . "# account's own, shared = shared by several accounts) and the address its mail went out\n"
	   . "# from. Once the addresses have been moved to this server and added in Settings > IP\n"
	   . "# addresses, restore them: Transfer tool > History > IP map, or\n"
	   . "#   reqad transfer ip-restore <this file> [--apply]\n"
	   . "# Lines can be removed or changed; '-' = none.\n"
	   . '# '.$line(transfer_ip_map_columns());
	foreach ($rows as $r) {
		$v = array();
		foreach (transfer_ip_map_columns() as $k)
			$v[] = (string)($r[$k] ?? '') !== '' ? (string)$r[$k] : '-';
		$t .= $line($v);
	}
	return $t;
}

/* Rows and errors of a map file's text. Lines that are not right are left out
   (errors: "line N: why"). */
function transfer_ip_map_parse($text) {
	$rows = array(); $errors = array();
	$cols = transfer_ip_map_columns();
	foreach (preg_split('/\r?\n/', (string)$text) as $i => $l) {
		$l = trim($l);
		if ($l === '' || $l[0] === '#')
			continue;
		if (count($rows) >= 20000) {
			$errors[] = 'more than 20000 lines: the rest is left out';
			break;
		}
		$p = preg_split('/\s+/', $l);
		$n = $i + 1;
		if (count($p) !== count($cols)) {
			$errors[] = "line $n: ".count($cols).' columns expected ('.implode(' ', $cols).')';
			continue;
		}
		$r = array_combine($cols, array_map(function ($x) { return $x === '-' ? '' : $x; }, $p));
		$r['domain'] = strtolower(rtrim($r['domain'], '.'));
		if ($r['kind'] === 'parked')
			$r['kind'] = 'alias';
		$why = '';
		if (!valid_domain($r['domain']))                                          $why = 'not a domain name';
		elseif (!valid_username($r['account']))                                   $why = 'not an account name';
		elseif (!in_array($r['kind'], array('main', 'addon', 'sub', 'alias'), true)) $why = 'kind: main, addon, sub or alias';
		elseif ($r['ipv4'] !== '' && !filter_var($r['ipv4'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) $why = 'ipv4: not an IPv4 address';
		elseif ($r['ipv6'] !== '' && !filter_var($r['ipv6'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) $why = 'ipv6: not an IPv6 address';
		elseif (!in_array($r['ipv4_use'], array('', 'dedicated', 'shared'), true) || !in_array($r['ipv6_use'], array('', 'dedicated', 'shared'), true)) $why = 'ipv4_use / ipv6_use: dedicated or shared';
		elseif ($r['mail_from'] !== '' && !filter_var($r['mail_from'], FILTER_VALIDATE_IP)) $why = 'mail_from: not an IP address';
		if ($why !== '') {
			$errors[] = "line $n: $why";
			continue;
		}
		foreach (array('ipv4', 'ipv6', 'mail_from') as $k)
			if ($r[$k] !== '')
				$r[$k] = (string)inet_ntop(inet_pton($r[$k]));
		if ($r['ipv4'] !== '' && $r['ipv4_use'] === '') $r['ipv4_use'] = 'dedicated';
		if ($r['ipv6'] !== '' && $r['ipv6_use'] === '') $r['ipv6_use'] = 'dedicated';
		$rows[] = $r;
	}
	return array($rows, $errors);
}

/* Save one account's rows in a transfer's map (the runner, as root; a
   resumed account replaces its lines). */
function transfer_ip_map_save($id, $user, array $rows) {
	$job = transfer_job_get($id);
	if (!$job)
		return false;
	$f = transfer_job_file($id, 'ips');
	list($old) = transfer_ip_map_parse((string)@file_get_contents($f));
	$keep = array_values(array_filter($old, function ($r) use ($user) { return $r['account'] !== $user; }));
	$text = transfer_ip_map_text(array_merge($keep, $rows), 'transfer '.$id.' from '.$job['server'].', '.date('Y-m-d H:i', (int)$job['created']));
	if (@file_put_contents($f.'.tmp', $text) === false)
		return false;
	@chmod($f.'.tmp', 0640);
	@chgrp($f.'.tmp', 'reqad');
	return @rename($f.'.tmp', $f);
}

/* data: name, text of a transfer's map */
function transfer_ip_map_get($id) {
	$job = transfer_job_get($id);
	if (!$job)
		return result_error('No such transfer.');
	$text = (string)@file_get_contents(transfer_job_file($id, 'ips'));
	if ($text === '')
		return result_error('This transfer has no IP map (transfers made before the map existed have none; transfer the account again to make one).');
	return result_ok('', array('name' => 'transfer-'.date('Ymd-Hi', (int)$job['created']).'-ips.txt', 'text' => $text));
}

/* The addresses this server has (bound to its interfaces). */
function transfer_ip_local() {
	$out = array();
	foreach (explode("\n", (string)shell_exec('ip -o addr show 2>/dev/null')) as $l)
		if (preg_match('/\sinet6?\s+([0-9a-fA-F:.]+)\//', $l, $m) && ($b = @inet_pton($m[1])) !== false)
			$out[] = (string)inet_ntop($b);
	return array_values(array_unique($out));
}

/*
 * What restoring a map would do. Per account (the main domain's line gives its
 * addresses): an address that was its own on cPanel is given to it again when
 * the address is in Settings > IP addresses (Hosting Pro) and free; a shared one
 * is never given to one account - every site here answers on every IPv4 address
 * of the server, so once it is on this server the sites answer on it as they
 * did; mail of a domain that went out from another address than the account's
 * gets an outgoing mail rule for that address, when it is here.
 * data: hosting (Hosting Pro gives addresses), accounts [account, domain,
 * domains, here, items [what, address, state, text, do]], counts by state.
 * States: done (nothing to do), ready (the restore does it), missing (the
 * address is not here yet), taken (another account has it), warn, skip.
 */
function transfer_ip_plan(array $rows) {
	global $db;
	$local = transfer_ip_local();
	$list = plugin_filter('ip.list', null);
	$hp = is_array($list);
	$extra = array();
	foreach ($hp ? $list : array() as $ip) {
		$extra[(string)$ip['address']] = $ip;
		if ((string)($ip['public'] ?? '') !== '')
			$extra[(string)$ip['public']] = $ip;
	}
	$by = array();
	foreach ($rows as $r)
		$by[$r['account']][] = $r;
	ksort($by);
	$accounts = array();
	$count = array('done' => 0, 'ready' => 0, 'missing' => 0, 'taken' => 0, 'warn' => 0, 'skip' => 0);
	$add_text = $hp ? 'Once it has been moved to this server, add it in Settings > IP addresses and restore again.'
	                : 'Giving an account an address of its own needs Reqad Hosting Pro.';
	foreach ($by as $user => $rs) {
		$main = $rs[0];
		foreach ($rs as $r)
			if ($r['kind'] === 'main')
				$main = $r;
		$acc = array('account' => $user, 'domain' => $main['domain'], 'domains' => array_values(array_unique(array_column($rs, 'domain'))),
		             'here' => (bool)account_get($db, $user), 'items' => array());
		$item = function ($what, $addr, $state, $text, array $do = array()) use (&$acc, &$count) {
			$acc['items'][] = array('what' => $what, 'address' => $addr, 'state' => $state, 'text' => $text, 'do' => $do);
			$count[$state]++;
		};
		if (!$acc['here']) {
			$item('Account', '', 'skip', "$user is not an account on this server.");
			$accounts[] = $acc;
			continue;
		}
		$own = array(4 => '', 6 => '');   // the account's own address after the restore
		foreach (array(4, 6) as $k) {
			$a = $main['ipv'.$k];
			if ($a === '')
				continue;
			$what = 'IPv'.$k;
			$x = $extra[$a] ?? null;
			$users = $x ? array_values((array)$x['users']) : array();
			if ($x && in_array($user, $users, true)) {
				$own[$k] = $a;
				$item($what, $a, 'done', "$user has it.");
				continue;
			}
			if ($main['ipv'.$k.'_use'] === 'shared') {
				if ($users)
					$item($what, $a, 'warn', 'Shared by several accounts on cPanel. Here it is the address of '.implode(', ', $users).', so only their sites answer on it.');
				elseif ($k === 6)
					$item($what, $a, 'skip', 'Shared on cPanel. Sites here answer on IPv6 only on an address of their own.');
				elseif ($x || in_array($a, $local, true))
					$item($what, $a, 'done', 'Shared by several accounts on cPanel. It is on this server, and every site answers on every address of this server.');
				else
					$item($what, $a, 'missing', 'Shared by several accounts on cPanel; it is not on this server. Once it is (Settings > IP addresses), the sites answer on it.');
				continue;
			}
			if ($users)
				$item($what, $a, 'taken', 'Its own on cPanel, but here it is the address of '.implode(', ', $users).'.');
			elseif ($x) {
				$own[$k] = $a;
				$item($what, $a, 'ready', "Its own on cPanel: the restore gives it to $user (its sites answer on it, and its mail goes out from it once the reverse DNS of the address matches).",
				      array('account' => $user, 'kind' => $k, 'address' => (string)$x['address']));
			} elseif (in_array($a, $local, true))
				$item($what, $a, $k === 4 ? 'done' : 'warn', $k === 4
				      ? 'An address of this server outside Settings > IP addresses (its main one): every site answers on it.'
				      : 'An address of this server outside Settings > IP addresses: '.($hp ? 'add it there to give it to the account.' : $add_text));
			else
				$item($what, $a, 'missing', 'Its own on cPanel; not on this server yet. '.$add_text);
		}
		/* mail: grouped by the address it went out from */
		$mail = array();
		foreach ($rs as $r)
			if ($r['mail_from'] !== '' && $r['kind'] !== 'sub')
				$mail[$r['mail_from']][] = $r['domain'];
		foreach ($mail as $m => $doms) {
			$k = strpos($m, ':') !== false ? 6 : 4;
			if ($own[$k] === $m)
				continue;   // it goes with the account's address
			$x = $extra[$m] ?? null;
			$what = 'Mail of '.implode(', ', $doms);
			if ($m === $main['ipv'.$k] && $main['ipv'.$k.'_use'] === 'dedicated') {
				/* the account's own address, which it does not get back: not
				   another account's address for its mail either */
				if ($x)
					$item($what, $m, 'warn', 'Went out from the account\'s own address, which it does not have here; it keeps going out as now.');
				continue;
			}
			if (!$x) {
				if (!in_array($m, $local, true))
					$item($what, $m, 'missing', "Went out from $m on cPanel, which is not on this server. ".($hp ? 'Once it is, add it in Settings > IP addresses and restore again; until then SPF records that name it must also allow this server.' : 'SPF records that name it must also allow this server.'));
				continue;   // this server's main address: as it is now
			}
			$todo = array_values(array_diff($doms, (array)($x['mail_domains'] ?? array())));
			if (!$todo)
				$item($what, $m, 'done', "Goes out from $m.");
			else
				$item('Mail of '.implode(', ', $todo), $m, 'ready', "Went out from $m on cPanel: the restore adds outgoing mail rules for it (Settings > IP addresses > Outgoing mail).",
				      array('domains' => $todo, 'address' => (string)$x['address'], 'kind' => $k));
		}
		$accounts[] = $acc;
	}
	return result_ok('', array('hosting' => $hp, 'accounts' => $accounts, 'counts' => $count));
}

/*
 * Restore a map: do what transfer_ip_plan() marks ready. $source: what the map
 * is (a transfer id or a file name), for the log. data: results [what, ok,
 * text], plan (after).
 */
function transfer_ip_restore(array $rows, $source) {
	$plan = transfer_ip_plan($rows)['data'];
	if (!$plan['hosting'])
		return result_error('Giving accounts their own addresses needs Reqad Hosting Pro (Settings > IP addresses).');
	$results = array(); $warn = array();
	$note = function ($what, $r) use (&$results, &$warn) {
		$ok = is_array($r) && !empty($r['ok']);
		$text = is_array($r) ? ($ok ? (string)$r['message'] : (string)$r['error']) : 'Hosting Pro did not answer.';
		$results[] = array('what' => $what, 'ok' => $ok, 'text' => $text);
		if ($ok)
			foreach ((array)$r['warnings'] as $w)
				$warn[] = "$what: $w";
	};
	foreach ($plan['accounts'] as $acc) {
		$set = array(4 => null, 6 => null);
		foreach ($acc['items'] as $it)
			if ($it['state'] === 'ready' && isset($it['do']['account']))
				$set[(int)$it['do']['kind']] = $it['do']['address'];
		if ($set[4] !== null || $set[6] !== null)
			$note($acc['account'], plugin_filter('account.ip.set', null, $acc['account'], $set[4], $set[6]));
		foreach ($acc['items'] as $it)
			if ($it['state'] === 'ready' && isset($it['do']['domains']))
				foreach ($it['do']['domains'] as $d)
					$note("mail of $d", plugin_filter('mail.ip.set', null, $d, $it['do']['kind'] === 4 ? $it['do']['address'] : '', $it['do']['kind'] === 6 ? $it['do']['address'] : ''));
	}
	$src = preg_replace('/[^\w .:@+-]/', '_', mb_substr((string)$source, 0, 120));
	$log = array("Restore from $src by ".transfer_actor().': '.($results ? count($results).' change'.(count($results) === 1 ? '' : 's') : 'nothing to do'));
	foreach ($results as $x)
		$log[] = '  '.($x['ok'] ? 'ok  ' : 'FAIL').' '.$x['what'].': '.$x['text'];
	foreach ($warn as $w)
		$log[] = '  !    '.$w;
	transfer_log_append(transfer_job_dir().'/ip-restores.log', implode("\n", $log));
	if (preg_match('/^transfer ([a-f0-9]{16})$/', $src, $m))
		transfer_job_log($m[1], 'IP addresses restored by '.transfer_actor().': '.(count(array_filter(array_column($results, 'ok')))).' of '.count($results).' changes made (details in ip-restores.log).');
	route_log("transfer IP restore from $src: ".count($results).' changes');
	$failed = count(array_filter($results, function ($x) { return !$x['ok']; }));
	$msg = !$results ? 'Nothing to restore now.'
	     : (count($results) - $failed).' of '.count($results).' change'.(count($results) === 1 ? '' : 's').' made.';
	$data = array('results' => $results, 'plan' => transfer_ip_plan($rows)['data']);
	if ($results && $failed === count($results))
		return result_error($msg.' '.implode(' ', array_column($results, 'text')), $data);
	return result_ok($msg, $data, $warn);
}

/* The latest restores, as text (newest last) */
function transfer_ip_restore_log($bytes = 65536) {
	$f = transfer_job_dir().'/ip-restores.log';
	$size = is_file($f) ? (int)filesize($f) : 0;
	if (!$size)
		return '';
	$t = (string)@file_get_contents($f, false, null, max(0, $size - $bytes));
	return $size > $bytes ? substr($t, strpos($t, "\n") + 1) : $t;
}
