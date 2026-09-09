<?php
/* CLI scripts (scripts/update_disk_usage, adduserdomain, checkdomain, ...) include
   this file directly without defines.php, so define the app root here when it is
   missing rather than fataling on the top-level define()s further down. */
if (!defined('_PATH'))
	define('_PATH', '/usr/local/reqad');

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

/* Local part of an email address (the bit before the @). */
function valid_email_user($u) {
	return preg_match('/^[A-Za-z0-9\+\-_\.]{1,64}$/', (string)$u) === 1;
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
	if ($cfg['keep']  === '') $cfg['keep']  = '6';
	if ($cfg['dbmax'] === '') $cfg['dbmax'] = '1024';
	return $cfg;
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
	return implode("\n", $out);
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

/* The token for the current authenticated user. */
function csrf_token() {
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
function msg_alert_html($type, $text) {
    $map = array(
        'success' => array('alert-success', '#EFE', 'text-success', 'Success'),
        'error'   => array('alert-warning', '#FFE', 'text-danger',  'Error'),
        'warning' => array('alert-warning', '#FFE', 'text-warning', 'Warning'),
        'info'    => array('alert-info',    '#EEF', 'text-info',    'Info'),
    );
    $c = isset($map[$type]) ? $map[$type] : $map['info'];
    list($alertClass, $bg, $textClass, $heading) = $c;
    $safe = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

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
function feature_enabled($ini, $flag) {
	if ($flag === 'root_access')
		return !isset($ini[$flag]) ? true : ((int)$ini[$flag] === 1);
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
		'ajax-mq-purge-frozen'    => 'email',
		'ajax-mailconf-validate'  => 'email',
		'ajax-mailconf-save'      => 'email',
		'ajax-mailconf-versions'  => 'email',
		'ajax-mailconf-restore'   => 'email',
		'ajax-webmail-ticket'     => 'email',
		'ajax-mobileconfig'       => 'email',
		'ajax-wp-install'         => 'wptoolkit',
		'ajax-wp-scan'            => 'wptoolkit',
		'ajax-transfer-run'       => 'transfer',
		'ajax-transfer-check'     => 'transfer',
		'ajax-fm-list'            => 'filemanager',
		'ajax-fm-read'            => 'filemanager',
		'ajax-fm-save'            => 'filemanager',
		'ajax-fm-mkdir'           => 'filemanager',
		'ajax-fm-newfile'         => 'filemanager',
		'ajax-fm-rename'          => 'filemanager',
		'ajax-fm-chmod'           => 'filemanager',
		'ajax-fm-delete'          => 'filemanager',
		'ajax-fm-upload'          => 'filemanager',
		'ajax-fm-compress'        => 'filemanager',
		'ajax-fm-extract'         => 'filemanager',
		'ajax-fm-download'        => 'filemanager',
		'ajax-restore-server-status' => 'backup',
		'ajax-terminal-target'    => 'terminal',
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
// actions (POST action names), action_handler, ajax_handler, ajax_features.
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
/* Account-level file manager helpers. Every filesystem op runs as the account
   user (sudo -u <user>), all client paths are relative to the account home and
   jailed inside it. See templates/file-manager-modal.php + ajax-fm-* handlers. */

// Absolute home directory for an account user (convention: /home/<user>).
// $user must already be sanitized to [a-z0-9]; we sanitize again defensively.
function fm_home($user) {
	return '/home/' . preg_replace('/[^a-z0-9]/', '', (string)$user);
}

/* Resolve a client-supplied relative path to an absolute path guaranteed to sit
   inside the account home, or false on any escape/error. Uses `realpath -m` so it
   also works for not-yet-existing targets (mkdir / newfile / rename destination). */
function fm_resolve($user, $rel) {
	$home = fm_home($user);
	$rel  = (string)$rel;
	if (strpos($rel, "\0") !== false) return false;            // null-byte guard
	$rel = ltrim($rel, '/');                                    // always relative to home
	$abs = shell_exec('sudo -u ' . escapeshellarg($user) .
	       ' realpath -m ' . escapeshellarg($home . '/' . $rel) . ' 2>/dev/null');
	$abs = trim((string)$abs);
	if ($abs === '') return false;
	if ($abs !== $home && strpos($abs, $home . '/') !== 0) return false;   // jail
	return $abs;
}

/* List one directory as the account user. Returns an array of rows
   [{name,type:'dir'|'file',size,mtime,perms}] or false on error. One parseable
   `find` call: type / size / mtime / octal-perms / symbolic-perms / name. */
function fm_list($user, $absdir) {
	// %y type, %s size, %T.. mtime, %M symbolic-perms, %Y deref-type (follows
	// symlinks: d/f/... or N for broken), %f name
	$cmd = 'sudo -u ' . escapeshellarg($user) . ' find ' . escapeshellarg($absdir) .
	       ' -maxdepth 1 -mindepth 1 -printf ' .
	       escapeshellarg('%y\t%s\t%TY-%Tm-%Td %TH:%TM\t%M\t%Y\t%f\n') . ' 2>/dev/null';
	$out = shell_exec($cmd);
	if ($out === null) return array();
	$rows = array();
	foreach (explode("\n", rtrim($out, "\n")) as $line) {
		if ($line === '') continue;
		$p = explode("\t", $line, 6);
		if (count($p) < 6) continue;
		$is_link = ($p[0] === 'l');
		$deref   = $p[4];   // %Y: type the symlink points at (d/f/…), N/L/? if unresolved
		// effective type drives UI behaviour: a symlink to a dir navigates like a dir
		$type = $is_link ? (($deref === 'd') ? 'dir' : 'file')
		                 : (($p[0] === 'd') ? 'dir' : 'file');
		$row = array(
			'name'  => $p[5],
			'type'  => $type,
			'size'  => (int)$p[1],
			'mtime' => $p[2],
			'perms' => $p[3],   // symbolic (e.g. -rw-r--r-- or lrwxrwxrwx), matches the UI
		);
		if ($is_link) {
			$row['link'] = true;
			if ($deref === 'N' || $deref === 'L' || $deref === '?') $row['broken'] = true;
		}
		$rows[] = $row;
	}
	// folders first, then case-insensitive by name (mirrors the JS sample sort)
	usort($rows, function($a, $b) {
		if ($a['type'] !== $b['type']) return $a['type'] === 'dir' ? -1 : 1;
		return strcasecmp($a['name'], $b['name']);
	});
	return $rows;
}

// True if $abspath is a small-enough, text (non-binary) file editable in the UI.
function fm_is_text($user, $abspath, $max = 102400) {
	$stat = trim((string)shell_exec('sudo -u ' . escapeshellarg($user) .
	        ' stat -c %F/%s ' . escapeshellarg($abspath) . ' 2>/dev/null'));
	if ($stat === '' || strpos($stat, 'regular') !== 0) return false;
	$size = substr($stat, strrpos($stat, '/') + 1);
	if ((int)$size >= $max) return false;
	// `file` reports an empty file as "binary" (inode/x-empty), so short-circuit
	if ((int)$size === 0) return true;
	// `file --mime-encoding` reports "binary" for non-text content
	$enc = trim((string)shell_exec('sudo -u ' . escapeshellarg($user) .
	       ' file --mime-encoding -b ' . escapeshellarg($abspath) . ' 2>/dev/null'));
	return $enc !== '' && strpos($enc, 'binary') === false;
}

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

/* Rewrite an account's live vhost server_name/ServerAlias from the alias table,
   validate the server config, and reload. Returns '' on success, or an error
   string (the original file is restored on a failed config test). */
function apply_account_vhost_names($db, $domain, $is_apache) {
	$res = $db->query('SELECT id, has_email FROM accounts WHERE domain="'.$db->escapeString($domain).'"');
	$account = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;
	if(!$account) return 'Account not found for '.$domain;

	$aliases = get_aliases($db, $account['id']);
	$names   = account_server_names($domain, $aliases, !empty($account['has_email']));
	$file    = $is_apache ? '/etc/httpd/conf.d/'.$domain.'.conf' : '/etc/nginx/conf.d/'.$domain.'.conf';

	$content = shell_exec('sudo cat '.escapeshellarg($file).' 2>/dev/null');
	if($content === null || trim($content) === '')
		return 'vhost file not found: '.$file;

	if(!$is_apache) {
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
		return '';   // nothing to change (or regex no-op) — leave the file alone

	$tmp = tempnam('/tmp', 'reqad');
	file_put_contents($tmp, $new);
	shell_exec('sudo cp '.escapeshellarg($tmp).' '.escapeshellarg($file));
	@unlink($tmp);

	$test = $is_apache ? shell_exec('sudo httpd -t 2>&1') : shell_exec('sudo nginx -t 2>&1');
	if(stripos($test, 'test is successful') === false && stripos($test, 'syntax ok') === false) {
		/* roll back to the original content */
		$bak = tempnam('/tmp', 'reqad');
		file_put_contents($bak, $content);
		shell_exec('sudo cp '.escapeshellarg($bak).' '.escapeshellarg($file));
		@unlink($bak);
		log_debug('[apply_account_vhost_names] config test failed for '.$domain.': '.trim($test));
		return 'Web server config test failed; change reverted.';
	}
	shell_exec('sudo systemctl reload '.($is_apache ? 'httpd' : 'nginx').' >> '.__DIR__.'/../../log/debug_log 2>&1');
	return '';
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
   cert), 'none' (main domain does not resolve, HTTP-01), 'no-dns' (wildcard but
   no DNS provider configured). */
function reissue_letsencrypt_cert($db, $domain, $has_email, $token = '') {
	$ok = trim(shell_exec('sudo test -d /etc/letsencrypt/live/'.escapeshellarg($domain).' && echo y'));
	if($ok !== 'y') return 'not-le';

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
	$is_apache = (substr(trim($ini['template'] ?? ''), 0, 7) == 'apache_');

	if($which === 'nginx') {
		return array(
			'path'    => $is_apache ? '/etc/httpd/conf.d/'.$domain.'.conf' : '/etc/nginx/conf.d/'.$domain.'.conf',
			'test'    => $is_apache ? 'sudo httpd -t 2>&1' : 'sudo nginx -t 2>&1',
			'bin'     => $is_apache ? 'httpd' : 'nginx',
			'service' => $is_apache ? 'httpd' : 'nginx',
			'label'   => $is_apache ? 'Apache vhost' : 'nginx vhost',
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
		file_put_contents($dir.'/nginx.conf', "events {}\nhttp {\n    include ".$dir."/vhost.conf;\n}\n");
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

/* Is the panel serving sites with nginx (vs apache)? Nginx cache only applies then. */
function wp_is_nginx($ini) {
	return substr(trim($ini['template'] ?? ''), 0, 7) != 'apache_';
}

/* Sanitised zone/prefix for an account — used for the cache zone, cache dir and
   the map variable names. Account users are already [a-z][a-z0-9]{1,11}; this is
   defence in depth so nothing untrusted reaches an nginx identifier. */
function wp_cache_zone($user) {
	return preg_replace('/[^a-z0-9]/', '', strtolower((string)$user));
}

/* Absolute docroot for a tracked WordPress install (honours the subdir `path`). */
function wp_site_docroot($user, $path = '') {
	$base = '/home/'.$user.'/public_html';
	$path = trim((string)$path, '/');
	return $path === '' ? $base : $base.'/'.$path;
}

/* nginx vhost path for a domain (only meaningful when wp_is_nginx()). */
function wp_nginx_conf_path($domain) {
	return '/etc/nginx/conf.d/'.$domain.'.conf';
}

/* --- Nginx FastCGI cache ------------------------------------------------- */

/* Build the three marker-delimited config fragments for a site.
   Returns array('http' => ..., 'purge' => ..., 'fcgi' => ...). Uses nowdoc so
   nginx '$' variables survive verbatim; only %ZONE% / %IP% are substituted. */
function wp_nginx_cache_fragments($zone, $ip) {
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
}

map $request_uri $%ZONE%_nc_uri {
    default                          0;
    ~*^/wp-admin                     1;
    ~*^/wp-login\.php                1;
    ~*^/wp-cron\.php                 1;
    ~*^/xmlrpc\.php                  1;
    ~*^/wp-json                      1;
    ~*^/feed                         1;
    ~*^/sitemap.*\.xml               1;
    ~*^/purge                        1;
    ~*^/(cart|checkout|my-account)   1;
    ~*^/wc-api                       1;
    ~*^/store-api                    1;
}

map $request_method $%ZONE%_nc_method {
    default 1;
    GET     0;
    HEAD    0;
}

map $query_string $%ZONE%_nc_query {
    default 1;
    ""      0;
}

map "$%ZONE%_nc_cookie$%ZONE%_nc_uri$%ZONE%_nc_method$%ZONE%_nc_query" $%ZONE%_skip_cache {
    default 1;
    "0000"  0;
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
            # $request_uri (not $uri): try_files rewrites $uri to /index.php, which
            # would collapse every page onto one cache entry. Query strings are never
            # cached (see the %ZONE%_nc_query map) so the key stays a clean path.
            open_file_cache             off;
            fastcgi_cache               %ZONE%;
            fastcgi_cache_key           "$scheme$host$request_uri";
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
            # END reqad-nginx-cache-fcgi

EOT;

	$sub = function($s) use ($zone, $ip) {
		return str_replace(array('%ZONE%', '%IP%'), array($zone, $ip), $s);
	};
	return array('http' => $sub($http), 'purge' => $sub($purge), 'fcgi' => $sub($fcgi));
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

/* Enable the nginx FastCGI cache for a site: inject the three fragments into the
   vhost, create the cache dir, install+activate the purge plugin, test & reload.
   Reverts the vhost on a failed `nginx -t`. Returns array(error, success, log). */
function wp_nginx_cache_enable($ini, $user, $domain, $docroot) {
	$log = '';
	if(!wp_is_nginx($ini))
		return array('error' => 'This server does not use nginx, so the FastCGI cache is not available.', 'success' => '', 'log' => '');

	$path = wp_nginx_conf_path($domain);
	$orig = wp_read_conf($path);
	if(trim($orig) === '')
		return array('error' => 'nginx vhost not found: '.$path, 'success' => '', 'log' => '');
	if(strpos($orig, '# BEGIN reqad-nginx-cache') !== false)
		return array('error' => '', 'success' => 'Nginx cache is already enabled.', 'log' => '');

	$zone = wp_cache_zone($user);
	$ip   = reqad_server_ip();
	$frag = wp_nginx_cache_fragments($zone, $ip);

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

	// 3) fcgi cache directives: right after the `fastcgi_pass php-fpm-<user>;` line.
	$lines = explode("\n", $new);
	$out = array(); $did_fcgi = false;
	foreach($lines as $ln) {
		$out[] = $ln;
		if(!$did_fcgi && strpos($ln, 'fastcgi_pass') !== false && strpos($ln, 'php-fpm') !== false) {
			$out[] = rtrim($frag['fcgi'], "\n");
			$did_fcgi = true;
		}
	}
	if(!$did_fcgi)
		return array('error' => 'Could not locate the `fastcgi_pass` line in the vhost to anchor the cache directives.', 'success' => '', 'log' => '');
	$new = implode("\n", $out);

	// Cache dir (nginx creates sublevels itself, but needs the root to exist+own).
	shell_exec('sudo mkdir -p /var/cache/nginx/fcgi-'.escapeshellarg($zone));
	shell_exec('sudo chown nginx:nginx /var/cache/nginx/fcgi-'.$zone.' 2>/dev/null');

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
	return array('error' => '', 'success' => 'Nginx FastCGI cache enabled and nginx reloaded.', 'log' => $log);
}

/* Disable the nginx cache: strip the three marker blocks, test & reload, empty
   the cache dir and deactivate the purge plugin. Returns array(error, success, log). */
function wp_nginx_cache_disable($ini, $user, $domain, $docroot) {
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
	foreach(array('reqad-nginx-cache-purge', 'reqad-nginx-cache-fcgi', 'reqad-nginx-cache') as $mk) {
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
	$zone = wp_cache_zone($user);
	if($zone !== '')
		shell_exec('sudo rm -rf /var/cache/nginx/fcgi-'.escapeshellarg($zone).'/* 2>/dev/null');
	// Deactivate the canonical slug, plus any branch-suffixed copy left behind by
	// an install that went through the GitHub archive (reqad-cache-purger-main).
	foreach(array(WP_CACHE_PURGER_SLUG, WP_CACHE_PURGER_SLUG.'-main', WP_CACHE_PURGER_SLUG.'-master') as $pslug) {
		$is = trim((string)shell_exec('sudo -u '.escapeshellarg($user).' /usr/local/bin/wp plugin is-installed '.$pslug.' --path='.escapeshellarg($docroot).' 2>/dev/null; echo $?'));
		if(substr($is, -1) === '0')
			$log .= "\n".(string)shell_exec('sudo -u '.escapeshellarg($user).' /usr/local/bin/wp plugin deactivate '.$pslug.' --path='.escapeshellarg($docroot).' 2>&1');
	}

	log_debug('[wp-manage] nginx-cache disabled for '.$domain.' ('.$user.')');
	return array('error' => '', 'success' => 'Nginx FastCGI cache disabled and nginx reloaded.', 'log' => $log);
}

/* --- Disable WP-Cron ----------------------------------------------------- */

/* Marker appended to the crontab line so we can find/remove exactly our entry. */
function wp_cron_marker($user) {
	return '# reqad-wpcron-'.wp_cache_zone($user);
}

/* Live state of the "Disable WP-Cron" option: 'on' | 'off'. Considered ON only
   when BOTH the DISABLE_WP_CRON constant is true AND our system cron is present. */
function wp_wpcron_state($user, $docroot) {
	$has = trim((string)shell_exec('sudo -u '.escapeshellarg($user).' /usr/local/bin/wp config get DISABLE_WP_CRON --path='.escapeshellarg($docroot).' 2>/dev/null'));
	$const_on = in_array(strtolower($has), array('1', 'true'), true);
	$cron_on  = ((int)trim((string)shell_exec('sudo grep -cF '.escapeshellarg(wp_cron_marker($user)).' /var/spool/cron/'.escapeshellarg($user).' 2>/dev/null'))) > 0;
	return ($const_on && $cron_on) ? 'on' : 'off';
}

/* Enable "Disable WP-Cron": set the constant and install an every-2-minutes system
   cron that runs due events via wp-cli. Both steps are idempotent. */
function wp_wpcron_enable($user, $docroot) {
	$log = '';
	// 1) Constant. `wp config set` updates in place if it already exists.
	$has = trim((string)shell_exec('sudo -u '.escapeshellarg($user).' /usr/local/bin/wp config has DISABLE_WP_CRON --path='.escapeshellarg($docroot).' 2>/dev/null; echo $?'));
	if(substr($has, -1) === '0')
		$log .= "DISABLE_WP_CRON already present in wp-config.php.\n";
	$set = (string)shell_exec('sudo -u '.escapeshellarg($user).' /usr/local/bin/wp config set DISABLE_WP_CRON true --raw --type=constant --path='.escapeshellarg($docroot).' 2>&1');
	$log .= $set."\n";
	if(stripos($set, 'Error') === 0 || stripos($set, 'Error:') !== false)
		return array('error' => 'Could not update wp-config.php: '.trim($set), 'success' => '', 'log' => $log);

	// 2) System cron (per-user spool). Skip if our marked line already exists.
	$marker = wp_cron_marker($user);
	$exists = (int)trim((string)shell_exec('sudo grep -cF '.escapeshellarg($marker).' /var/spool/cron/'.escapeshellarg($user).' 2>/dev/null'));
	if($exists === 0) {
		$line = '*/2 * * * * /usr/local/bin/wp cron event run --due-now --path='.$docroot.' >/dev/null 2>&1 '.$marker;
		shell_exec('echo '.escapeshellarg($line).' | sudo tee --append /var/spool/cron/'.escapeshellarg($user).' > /dev/null');
		shell_exec('sudo chown '.escapeshellarg($user).': /var/spool/cron/'.escapeshellarg($user).' 2>/dev/null');
		shell_exec('sudo chmod 600 /var/spool/cron/'.escapeshellarg($user).' 2>/dev/null');
		$log .= "Installed */2 wp-cron system cron.\n";
	} else {
		$log .= "System cron already present.\n";
	}

	log_debug('[wp-manage] wp-cron disabled (system cron installed) for '.$user);
	return array('error' => '', 'success' => 'WP-Cron disabled — a system cron now runs due events every 2 minutes.', 'log' => $log);
}

/* Disable the option again: remove the constant and our system cron line. */
function wp_wpcron_disable($user, $docroot) {
	$log = '';
	$has = trim((string)shell_exec('sudo -u '.escapeshellarg($user).' /usr/local/bin/wp config has DISABLE_WP_CRON --path='.escapeshellarg($docroot).' 2>/dev/null; echo $?'));
	if(substr($has, -1) === '0') {
		$del = (string)shell_exec('sudo -u '.escapeshellarg($user).' /usr/local/bin/wp config delete DISABLE_WP_CRON --path='.escapeshellarg($docroot).' 2>&1');
		$log .= $del."\n";
	}
	// Remove our marked crontab line. Match the bare marker slug (no '#', no
	// slashes) so '/'-delimited sed is safe.
	$slug = 'reqad-wpcron-'.wp_cache_zone($user);
	shell_exec('sudo sed -i '.escapeshellarg('/'.$slug.'/d').' /var/spool/cron/'.escapeshellarg($user).' 2>/dev/null');
	$log .= "Removed system cron.\n";

	log_debug('[wp-manage] wp-cron re-enabled (system cron removed) for '.$user);
	return array('error' => '', 'success' => 'WP-Cron re-enabled — the system cron was removed.', 'log' => $log);
}

/* Combined status for the Manage modal. Looks the account up in `wordpress` by
   user, so the caller only supplies a (validated) username. Returns null if the
   user is not a tracked WordPress install. */
function wp_manage_status($db, $ini, $user) {
	$res = $db->query('SELECT user, domain, path FROM wordpress WHERE user="'.$db->escapeString($user).'"');
	$row = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;
	if(!$row) return null;
	$docroot = wp_site_docroot($row['user'], $row['path'] ?? '');
	return array(
		'user'        => $row['user'],
		'domain'      => $row['domain'],
		'is_nginx'    => wp_is_nginx($ini),
		'nginx_cache' => wp_nginx_cache_state($ini, $row['domain']),
		'wp_cron'     => wp_wpcron_state($row['user'], $docroot),
	);
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
			$body[] = 'if ' . $disabled_prefix . 'true {';
		} else if (count($tests) === 1) {
			$body[] = 'if ' . $disabled_prefix . $tests[0] . ' {';
		} else {
			$join = (isset($r['match_type']) && $r['match_type'] === 'any') ? 'anyof' : 'allof';
			$body[] = 'if ' . $disabled_prefix . $join . ' (' . implode(', ', $tests) . ') {';
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
			$skip[] = array('raw' => $l, 'why' => 'this is cPanel\'s "suspend incoming mail" flag for the '
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
			$why .= ' — this is cPanel boilerplate that guards against filtering bounces,'
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

/* Build one rule row from $_POST. Returns the row, or false with $errmsg set. */
function ef_rule_from_post(&$errmsg) {
	$name = trim(isset($_POST['name']) ? $_POST['name'] : '');
	if ($name === '' || strlen($name) > 120) {
		$errmsg = 'Error: filter name must be 1-120 characters.';
		return false;
	}
	/* The name is emitted inside a `# rule:[...]` header comment, so a newline
	   or a ] would corrupt the script and break Roundcube's parser. */
	if (strpbrk($name, "\r\n]") !== false) {
		$errmsg = 'Error: filter name cannot contain a newline or a "]" character.';
		return false;
	}

	$fields = isset($_POST['cond_field']) ? (array)$_POST['cond_field'] : array();
	$ops    = isset($_POST['cond_op'])    ? (array)$_POST['cond_op']    : array();
	$vals   = isset($_POST['cond_value']) ? (array)$_POST['cond_value'] : array();

	$conds = array();
	for ($i = 0; $i < count($fields); $i++) {
		$f = trim($fields[$i]);
		$o = isset($ops[$i])  ? trim($ops[$i])  : '';
		$v = isset($vals[$i]) ? (string)$vals[$i] : '';
		if ($f === '' && $v === '')
			continue;                       /* blank row the user left behind */
		if (!ef_valid_field($f)) {
			$errmsg = 'Error: "' . htmlspecialchars($f) . '" is not a valid header name.';
			return false;
		}
		if (!ef_valid_op($o)) {
			$errmsg = 'Error: unknown condition operator.';
			return false;
		}
		if ($f === 'size') {
			if (!ctype_digit(trim($v))) {
				$errmsg = 'Error: a size condition needs a whole number of bytes.';
				return false;
			}
			$v = trim($v);
			if ($o !== 'over' && $o !== 'under')
				$o = 'over';
		} else if ($v === '') {
			$errmsg = 'Error: condition on "' . htmlspecialchars($f) . '" has no value.';
			return false;
		}
		if (strlen($v) > 1000) {
			$errmsg = 'Error: condition value is too long (max 1000 characters).';
			return false;
		}
		if ($o === 'regex' || $o === 'not-regex') {
			/* Reject a pattern Sieve would refuse at compile time anyway, but do
			   it here so the user gets a field-level message instead of a
			   compiler dump. @ is not a delimiter that can appear unescaped. */
			if (@preg_match('/' . str_replace('/', '\/', $v) . '/', '') === false) {
				$errmsg = 'Error: that regular expression is not valid.';
				return false;
			}
		}
		$conds[] = array('field' => $f, 'op' => $o, 'value' => $v);
	}

	$atypes = isset($_POST['act_type'])  ? (array)$_POST['act_type']  : array();
	$avals  = isset($_POST['act_value']) ? (array)$_POST['act_value'] : array();

	$acts = array();
	for ($i = 0; $i < count($atypes); $i++) {
		$t = trim($atypes[$i]);
		$v = isset($avals[$i]) ? trim((string)$avals[$i]) : '';
		if (!ef_valid_action_type($t)) {
			$errmsg = 'Error: unknown action.';
			return false;
		}
		if ($t === 'fileinto') {
			if ($v === '') { $errmsg = 'Error: choose a folder to file into.'; return false; }
			if (strpbrk($v, "\r\n") !== false || strlen($v) > 255) {
				$errmsg = 'Error: folder name is not valid.'; return false;
			}
		} else if ($t === 'redirect') {
			if (!valid_email_address(strtolower($v))) {
				$errmsg = 'Error: redirect needs a valid email address.'; return false;
			}
			$v = strtolower($v);
		} else if ($t === 'reject') {
			if ($v === '') { $errmsg = 'Error: a reject action needs a message.'; return false; }
			if (strlen($v) > 500) { $errmsg = 'Error: reject message is too long.'; return false; }
		} else if ($t === 'pipe') {
			/* Admin tiers only. Dovecot enables vnd.dovecot.pipe through
			   sieve_global_extensions, which refuses it in a mailbox's own script;
			   catching it here gives a sentence instead of a compiler dump. */
			if (isset($_POST['scope']) && $_POST['scope'] === 'account') {
				$errmsg = 'Error: piping to a program is only available on the server-wide and '
				        . 'per-domain filters, not in a mailbox\'s own script.';
				return false;
			}
			/* Only a program that is actually installed may be named. The check is
			   against the directory listing, not the string, so no path, no `..`
			   and no missing program can reach the generated script. */
			if (!in_array($v, ef_pipe_programs(), true)) {
				$errmsg = 'Error: "' . htmlspecialchars($v) . '" is not an installed program. '
				        . 'Programs live in ' . EF_PIPE_BIN_DIR . ' and are added by the server administrator.';
				return false;
			}
		} else if ($t === 'addflag' || $t === 'setflag' || $t === 'removeflag') {
			if ($v === '') { $errmsg = 'Error: an IMAP flag is required.'; return false; }
			if (strpbrk($v, "\r\n\"") !== false) { $errmsg = 'Error: flag name is not valid.'; return false; }
		} else {
			$v = '';
		}
		$acts[] = array('type' => $t, 'value' => $v);
	}
	if (!$acts) {
		$errmsg = 'Error: a filter needs at least one action.';
		return false;
	}

	return array(
		'name'       => $name,
		'enabled'    => (isset($_POST['enabled']) && $_POST['enabled'] === '0') ? 0 : 1,
		'priority'   => isset($_POST['priority']) ? max(0, min(9999, (int)$_POST['priority'])) : 10,
		'match_type' => (isset($_POST['match_type']) && $_POST['match_type'] === 'any') ? 'any' : 'all',
		'stop'       => !empty($_POST['stop']) ? 1 : 0,
		'conditions' => json_encode($conds),
		'actions'    => json_encode($acts),
	);
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
function local_ssl_cert_file($domain) {
	if(!valid_domain($domain)) return '';

	$files = array(
		'/etc/reqad/conf.d/'.$domain.'.conf',
		'/etc/nginx/conf.d/'.$domain.'.conf',
		'/etc/httpd/conf.d/'.$domain.'.conf',
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
		             'label' => 'No backend installed', 'badge' => 'bg-yellow-lt');
	return array('state' => 'not-enabled', 'backends' => $backends,
	             'label' => 'Installed, not enabled ('.implode(', ', $backends).')',
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

	$verb = (isset($t['reload']) && $t['reload'] === 'restart') ? 'restart' : 'reload';
	shell_exec('sudo systemctl '.$verb.' '.$t['service'].' >> '._PATH.'/log/debug_log 2>&1');
	mail_stack_cache_clear();
	log_debug('[mailconfig] '.$which.' saved + '.$verb.'ed '.$t['service']);
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

function mail_setting_defs($which) {
	if ($which === 'exim') return array(
		'tls' => array('title' => 'TLS &amp; ports', 'keys' => array(
			'tls_min_version' => array(
				'label' => 'Minimum TLS version', 'type' => 'select', 'virtual' => true,
				'choices' => array('TLSv1.0' => 'TLS 1.0 (insecure)', 'TLSv1.1' => 'TLS 1.1 (insecure)', 'TLSv1.2' => 'TLS 1.2 (recommended)', 'TLSv1.3' => 'TLS 1.3 only'),
				'help'  => 'Written as <code>openssl_options</code>. TLS 1.3 only will refuse mail from older servers — most of the internet still needs 1.2.'),
			'tls_advertise_hosts' => array(
				'label' => 'Advertise TLS to', 'type' => 'text', 'pattern' => '#^[A-Za-z0-9 :\.\*\!\+\@\-_/]{1,255}$#',
				'help'  => 'Hosts offered STARTTLS. <code>*</code> means everyone; empty disables TLS entirely.'),
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
		'limits' => array('title' => 'Limits &amp; throttling', 'keys' => array(
			'message_size_limit' => array('label' => 'Maximum message size', 'type' => 'text', 'pattern' => '#^\d{1,9}[KMG]?$#i',
				'help' => 'A number, optionally suffixed K, M or G. <code>0</code> means no limit.'),
			'recipients_max' => array('label' => 'Maximum recipients per message', 'type' => 'number', 'min' => 0, 'max' => 100000),
			'smtp_accept_max' => array('label' => 'Maximum simultaneous connections', 'type' => 'number', 'min' => 0, 'max' => 10000),
			'smtp_accept_queue_per_connection' => array('label' => 'Messages per connection before queueing', 'type' => 'number', 'min' => 0, 'max' => 100000),
			'remote_max_parallel' => array('label' => 'Parallel remote deliveries', 'type' => 'number', 'min' => 1, 'max' => 1000),
			'smtp_receive_timeout' => array('label' => 'Receive timeout', 'type' => 'text', 'pattern' => EXIM_TIME_RE,
				'help' => 'Time exim waits for the next SMTP command, e.g. <code>165s</code>, <code>5m</code> or <code>2m45s</code>.'),
		)),
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
		)),
		'storage' => array('title' => 'Mail storage', 'keys' => array(
			'mail_compression' => array('label' => 'Compress stored mail', 'type' => 'toggle', 'special' => 'compress',
				'help' => 'Lets dovecot read gzipped messages, so old mail can be stored compressed — typically a 60-70% saving on a mail-heavy account. This switch only makes compressed mail <em>readable</em>; nothing is compressed until a mailbox is listed in <code>etc/mail-archive.conf</code>, which the nightly job reads. Turning it back off is refused while any mailbox still holds compressed mail, because those messages would reach clients as unreadable gzip data.'),
			'mail_compress_vsz' => array('label' => 'Memory limit per IMAP/POP3 process', 'type' => 'select', 'special' => 'compress_vsz',
				'choices' => array('256M' => '256 MB (dovecot default)', '512M' => '512 MB', '1024M' => '1 GB (recommended with compression)', '2048M' => '2 GB'),
				'help' => 'Address space a single imap or pop3 process may use. Decompressing a message costs memory, and a process that hits the ceiling is killed mid-session — which the user sees as the connection dropping while opening a large old message. Only applies while compression is on; it is written to the same file.'),
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
				'help' => 'Mail relayed through these hosts is not blamed for the Received headers above them, so RBL checks stop firing on your own relays. Space-separated; <code>10.0.0.0/8</code>, <code>192.168.1.1</code> and <code>!1.2.3.4</code> (exclude) are all accepted. Leave empty and SpamAssassin guesses from the Received chain.'),
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
		if (!empty($d['special']) && $d['special'] === 'compress')     { $out[$k] = mail_compress_enabled() ? 'yes' : 'no'; continue; }
		if (!empty($d['special']) && $d['special'] === 'compress_vsz') { $st = mail_compress_status(); $out[$k] = $st['vsz']; continue; }
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

		/* Also not local.conf: both of these live in compress.conf, which one
		   script call writes in full. Collect them and reconcile once after the
		   loop — handling them inline would enable compression for the toggle
		   and then immediately re-run the script for the memory limit, taking
		   dovecot through two restarts for one save. */
		if (!empty($def['special']) && $def['special'] === 'compress')     { $want_compress = ($value === 'yes'); continue; }
		if (!empty($def['special']) && $def['special'] === 'compress_vsz') { $want_vsz = $value; continue; }

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
		} elseif ($value === '') {
			/* cleared: drop the line so the daemon default applies again. If it
			   was not in the file to begin with there is nothing to do. */
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

	/* One reconciliation for both compression fields. The memory limit is only
	   meaningful while compression is on (it is written to compress.conf, which
	   does not exist otherwise), so turning compression off wins over any vsz
	   choice submitted alongside it. */
	if ($want_compress !== null || $want_vsz !== null) {
		$st  = mail_compress_status();
		$on  = $st['enabled'];
		$want = ($want_compress === null) ? $on : $want_compress;

		if (!$want) {
			if ($on) {
				$r = mail_compress_disable();
				if ($r['error'] !== '') return $r;
				$special_msgs[] = $r['success'];
			}
		} else {
			/* '' from the select means "leave it alone": keep what is in force,
			   or the recommended value when switching compression on for the
			   first time. Never silently drop back to the 256M default, which
			   is the value compression makes inadequate. */
			$vsz = ($want_vsz !== null && $want_vsz !== '')
				? $want_vsz
				: ($st['vsz'] !== '' ? $st['vsz'] : '1024M');
			if (!$on || $vsz !== $st['vsz']) {
				$r = mail_compress_enable($vsz);
				if ($r['error'] !== '') return $r;
				$special_msgs[] = $on
					? 'Memory limit set to '.h($vsz).'.'
					: $r['success'];
			}
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
   is not a real mailbox. */
function webmail_ticket_create($email) {
	global $db;
	$email = strtolower(trim((string)$email));
	if (!valid_email_address($email)) return '';
	if (!in_array($email, mailbox_list(), true)) return '';

	webmail_tickets_purge(300);        // opportunistic prune, no cron needed

	$token = bin2hex(random_bytes(16));
	$stmt = $db->prepare('INSERT INTO webmail_tickets (token, email, created) VALUES (:t, :e, :c)');
	$stmt->bindValue(':t', $token, SQLITE3_TEXT);
	$stmt->bindValue(':e', $email, SQLITE3_TEXT);
	$stmt->bindValue(':c', time(), SQLITE3_INTEGER);
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

function addon_is_apache($ini) {
	return (substr(trim($ini['template'] ?? ''), 0, 7) == 'apache_');
}

function addon_vhost_dir($ini) {
	return addon_is_apache($ini) ? '/etc/httpd/conf.d' : '/etc/nginx/conf.d';
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
   handler is 'fpm' when a pool file exists, 'mod_php' on apache when none does
   (apache falls back to the module, which is always the default PHP), and
   'none' on nginx with no pool — a broken state PHP requests would 502 on. */
function addon_domain_php($ini, $domain) {
	$php_versions = array_map('trim', explode(',', $ini['php_versions'] ?? ''));
	foreach ($php_versions as $pv) {
		$pool = addon_pool_path($ini, $domain, $pv);
		if (is_file($pool))
			return array('version' => $pv, 'handler' => 'fpm', 'pool' => $pool);
	}
	return array(
		'version' => $ini['php'],
		'handler' => addon_is_apache($ini) ? 'mod_php' : 'none',
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
			'ssl'     => is_file('/etc/letsencrypt/live/'.$domain.'/cert.pem') ? 'letsencrypt'
			             : (is_file('/etc/ssl/certs/'.$domain.'.crt') ? 'self-signed' : ''),
		);
	}
	ksort($list);
	return $list;
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
	$group  = addon_is_apache($ini) ? 'apache' : 'nginx';
	$socket = addon_socket($user, $domain);
	return '['.$domain.']
user = '.$user.'
group = '.$group.'
listen = '.$socket.'
listen.owner = '.$user.'
listen.group = '.$group.'
listen.allowed_clients = 127.0.0.1

pm = dynamic
pm.max_children = 200
pm.start_servers = 10
pm.min_spare_servers = 5
pm.max_spare_servers = 50
pm.process_idle_timeout = 1s;
pm.max_requests = 1000

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
   edit_account.php, but with the per-domain socket. */
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
