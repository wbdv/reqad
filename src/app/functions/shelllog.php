<?php
/*
 * Shell activity: the programs hosting accounts start (Settings > Security >
 * "Log commands run by hosting accounts", Accounts & Domains > Shell Activity,
 * `reqad shelllog`). Off by default; the menu entry shows only while it is on.
 *
 * Recorded by the kernel (auditd), so an account can neither avoid nor wipe it,
 * jailed or not (bubblewrap runs on the same kernel; the record has the real
 * uid). The rule (SHL_RULES, loaded by augenrules) logs every execve of a uid
 * in 1000-60000 under the key SHL_KEY. It filters on the uid, not the login
 * uid: `su` sets none, so the 2083 terminal would slip through an auid rule.
 * Builtins of the shell (cd, echo > file) start no program and are not seen.
 *
 * `reqad shelllog collect` (cron, every minute, root) reads the audit log from
 * where it stopped (inode + offset, rotated copies followed like the stats)
 * into its own SQLite file SHL_DB (root:reqad 0640; the panel opens it
 * read-only). Per command it keeps:
 *   src   ssh        the account's own SSH session (sshd's USER_START record
 *                    gives the address; scp/rsync/git too)
 *         terminal   no login uid + a terminal: the 2083 Terminal (su on a pty)
 *         cron       the account's cron jobs (crond / atd sessions)
 *         web        no login uid, no terminal: a site's PHP, an app, a job the
 *                    panel started as the account
 *         admin      an admin's session switched to the account (su, runuser,
 *                    `reqad` commands, root's cron); via = that user
 *         account    another hosting account's session switched to this one
 *         login      a session of the account Reqad cannot place
 *   the same command (account, src, address, folder, line) again within the
 *   same hour adds to n of its row instead of a new one (cron jobs, sendmail
 *   from a site), so the file stays small.
 * Left out: the start-up noise of a login (systemd --user, /etc/profile.d's
 * exact command lines), the jail wrapper's own setup, the panel's file helper,
 * and execs that failed with "not found" (PATH searches).
 *
 * Red flags (shelllog_flag_rules(), never on admin rows): downloaded or
 * base64 code run by a shell (also as two processes of one pipeline: curl and
 * sh started together by the same parent), reverse shells, miners, programs in
 * temporary folders, scanners, sudo/su attempts, other people's files,
 * clearing the history. Each newest flagged row is a server health item
 * (shell_log::<user>-<id>, never shown to the account), so a dismissal lasts
 * until something new is flagged.
 */

if (!defined('SHL_DB')) define('SHL_DB', '/var/lib/reqad/stats/shell.db');   // another file only for tests
define('SHL_KEY', 'reqad-cmd');
define('SHL_RULES', '/etc/audit/rules.d/reqad-commands.rules');
define('SHL_AUDIT_LOG', '/var/log/audit/audit.log');
define('SHL_UNSET', 4294967295);
define('SHL_MAX_ROWS_RUN', 5000);   // new rows per account and run; more = one summary row

function shelllog_enabled() {
	return setting_get('shell-log-enabled', '0') === '1';
}

function shelllog_days() {
	$d = (int)setting_get('shell-log-days', '30');
	return in_array($d, array(7, 30, 90, 180), true) ? $d : 30;
}

function shelllog_sources() {
	return array(
		'ssh'      => array('SSH', 'The account\'s own SSH session (also scp, rsync, git over SSH)'),
		'terminal' => array('Terminal', 'The Terminal of the account panel'),
		'cron'     => array('Cron', 'The account\'s cron jobs'),
		'web'      => array('Website / jobs', 'A site\'s PHP, an app, or a job the panel ran as the account'),
		'account'  => array('Other account', 'Another hosting account switched to this one (su with its password)'),
		'login'    => array('Login', 'A session of the account that is not SSH or cron'),
		'admin'    => array('Admin', 'root or an admin ran it as the account (su, runuser, reqad commands, root\'s cron)'),
	);
}

/* The database; null when it cannot be opened. Root makes it and its tables;
   everyone else opens it read-only (the panel runs as reqad). */
function shelllog_db() {
	static $sdb = null, $tried = false;
	if ($tried)
		return $sdb;
	$tried = true;
	$root = function_exists('posix_geteuid') && posix_geteuid() === 0;
	if (!$root) {
		if (!is_readable(SHL_DB))
			return null;
		try {
			$sdb = new SQLite3(SHL_DB, SQLITE3_OPEN_READONLY);
			$sdb->busyTimeout(5000);
		} catch (Exception $e) { $sdb = null; }
		return $sdb;
	}
	$dir = dirname(SHL_DB);
	if (!is_dir($dir)) {
		@mkdir($dir, 0750, true);
		@chgrp($dir, 'reqad');
	}
	$new = !is_file(SHL_DB);
	try {
		$sdb = new SQLite3(SHL_DB);
	} catch (Exception $e) { return $sdb = null; }
	$sdb->busyTimeout(15000);
	if ($new) {
		@chmod(SHL_DB, 0640);
		@chgrp(SHL_DB, 'reqad');
	}
	$sdb->exec("CREATE TABLE IF NOT EXISTS shell_cmds (
		id INTEGER PRIMARY KEY, user TEXT NOT NULL, src TEXT NOT NULL, via TEXT NOT NULL DEFAULT '',
		addr TEXT NOT NULL DEFAULT '', first INTEGER NOT NULL, last INTEGER NOT NULL, n INTEGER NOT NULL DEFAULT 1,
		hour INTEGER NOT NULL, k TEXT NOT NULL, cwd TEXT NOT NULL DEFAULT '', exe TEXT NOT NULL DEFAULT '',
		cmd TEXT NOT NULL, err INTEGER NOT NULL DEFAULT 0, flag TEXT NOT NULL DEFAULT '',
		UNIQUE (k, hour))");
	$sdb->exec("CREATE INDEX IF NOT EXISTS shell_cmds_user ON shell_cmds (user, first)");
	$sdb->exec("CREATE INDEX IF NOT EXISTS shell_cmds_last ON shell_cmds (last)");
	$sdb->exec("CREATE INDEX IF NOT EXISTS shell_cmds_flag ON shell_cmds (user, id) WHERE flag != ''");
	$sdb->exec("CREATE TABLE IF NOT EXISTS shell_sessions (ses INTEGER NOT NULL, t INTEGER NOT NULL, user TEXT NOT NULL,
		kind TEXT NOT NULL, addr TEXT NOT NULL DEFAULT '', PRIMARY KEY (ses, t)) WITHOUT ROWID");
	$sdb->exec("CREATE TABLE IF NOT EXISTS shell_meta (k TEXT PRIMARY KEY, v TEXT)");
	return $sdb;
}

function shelllog_meta_get($k, $default = '') {
	$sdb = shelllog_db();
	if (!$sdb) return $default;
	$st = $sdb->prepare('SELECT v FROM shell_meta WHERE k = :k');
	$st->bindValue(':k', $k, SQLITE3_TEXT);
	$r = $st->execute();
	$row = $r ? $r->fetchArray(SQLITE3_NUM) : false;
	return $row ? (string)$row[0] : $default;
}

function shelllog_meta_put($k, $v) {
	$st = shelllog_db()->prepare('INSERT OR REPLACE INTO shell_meta (k, v) VALUES (:k, :v)');
	$st->bindValue(':k', $k, SQLITE3_TEXT);
	$st->bindValue(':v', (string)$v, SQLITE3_TEXT);
	$st->execute();
}

/* ---- the audit rule ------------------------------------------------------ */

function shelllog_rule_text() {
	return "## Reqad: Settings > Security > Log commands run by hosting accounts\n"
	      ."## (written and removed by `reqad shelllog on|off`; the panel reads the log)\n"
	      ."-a always,exit -F arch=b64 -S execve,execveat -F euid>=1000 -F euid<=60000 -k ".SHL_KEY."\n"
	      ."-a always,exit -F arch=b32 -S execve,execveat -F euid>=1000 -F euid<=60000 -k ".SHL_KEY."\n";
}

/* Root only: is the rule in the running kernel? */
function shelllog_rule_loaded() {
	$out = (string)shell_exec('/usr/sbin/auditctl -l 2>/dev/null');
	return strpos($out, 'key='.SHL_KEY) !== false;
}

/* Root only: why the rule cannot be loaded, '' when it is. */
function shelllog_rule_load() {
	if (!is_executable('/usr/sbin/auditctl') || !is_executable('/usr/sbin/augenrules'))
		return 'auditd is not installed (dnf install audit).';
	exec('systemctl is-active --quiet auditd', $o, $rc);
	if ($rc !== 0) {
		exec('systemctl enable --now auditd 2>&1', $o2, $rc2);
		exec('systemctl is-active --quiet auditd', $o3, $rc3);
		if ($rc3 !== 0)
			return 'auditd does not run and could not be started: '.trim(implode(' ', $o2));
	}
	if (@file_get_contents(SHL_RULES) !== shelllog_rule_text()) {
		if (@file_put_contents(SHL_RULES, shelllog_rule_text()) === false)
			return 'Could not write '.SHL_RULES.'.';
		@chmod(SHL_RULES, 0600);
	}
	if (shelllog_rule_loaded())
		return '';
	exec('/usr/sbin/augenrules --load 2>&1', $out, $rc);
	if (shelllog_rule_loaded())
		return '';
	/* -e 2 somewhere in the rules: they cannot change until the next boot */
	if (preg_match('/^enabled 2$/m', (string)shell_exec('/usr/sbin/auditctl -s 2>/dev/null')))
		return 'The audit rules are locked (-e 2) until the next reboot; the rule is in '.SHL_RULES.' and applies after it.';
	/* augenrules may refuse the whole set because of another file: load ours alone */
	foreach (array('b64', 'b32') as $arch)
		exec('/usr/sbin/auditctl -a always,exit -F arch='.$arch.' -S execve,execveat -F euid\>=1000 -F euid\<=60000 -k '.SHL_KEY.' 2>&1', $o4);
	if (shelllog_rule_loaded())
		return '';
	return 'The audit rule could not be loaded: '.trim(implode(' ', array_merge($out, $o4 ?? array())));
}

function shelllog_rule_unload() {
	@unlink(SHL_RULES);
	exec('/usr/sbin/auditctl -D -k '.SHL_KEY.' >/dev/null 2>&1');
}

/* Root only (the panel calls `sudo reqad shelllog on|off`): turn logging on or
   off and/or set how many days are kept. What was logged stays when it is
   turned off (until it is older than the days kept). */
function shelllog_set($on = null, $days = null) {
	if (!function_exists('posix_geteuid') || posix_geteuid() !== 0)
		return result_error('Only root can change command logging.');
	if ($days !== null) {
		$days = (int)$days;
		if (!in_array($days, array(7, 30, 90, 180), true))
			return result_error('Days kept: 7, 30, 90 or 180.');
		setting_put('shell-log-days', (string)$days);
	}
	if ($on === true) {
		if (!shelllog_db())
			return result_error('Could not open '.SHL_DB.'.');
		$err = shelllog_rule_load();
		if ($err !== '' && !is_file(SHL_RULES))
			return result_error($err);
		if (!shelllog_enabled()) {
			/* start at the end of today's log: nothing from before was logged for us */
			clearstatcache();
			$st = @stat(SHL_AUDIT_LOG);
			shelllog_meta_put('log', $st ? $st['ino'].':'.$st['size'] : '');
			shelllog_meta_put('since', (string)time());
		}
		setting_put('shell-log-enabled', '1');
		shelllog_meta_put('rule-error', $err);
		route_log('shell activity logging on');
		return $err === '' ? result_ok('Commands run by hosting accounts are logged from now on (kept '.shelllog_days().' days).', shelllog_status())
		                   : result_ok('Command logging is on, but commands are not logged yet:', shelllog_status(), array($err));
	}
	if ($on === false) {
		shelllog_rule_unload();
		setting_put('shell-log-enabled', '0');
		if (shelllog_db())
			shelllog_meta_put('rule-error', '');
		route_log('shell activity logging off');
		return result_ok('Command logging is off. What was logged stays until it is older than '.shelllog_days().' days.', shelllog_status());
	}
	return result_ok('Logged commands are kept '.shelllog_days().' days.', shelllog_status());
}

function shelllog_status() {
	$sdb = shelllog_db();
	$rows = 0;
	if ($sdb)
		$rows = (int)$sdb->querySingle('SELECT COALESCE(SUM(n), 0) FROM shell_cmds');
	$root = function_exists('posix_geteuid') && posix_geteuid() === 0;
	return array(
		'enabled'    => shelllog_enabled(),
		'days'       => shelllog_days(),
		'collected'  => (int)shelllog_meta_get('collected', '0'),
		'since'      => (int)shelllog_meta_get('since', '0'),
		'commands'   => $rows,
		'rule_error' => shelllog_meta_get('rule-error', ''),
		'rule_loaded'=> $root ? shelllog_rule_loaded() : null,
	);
}

/* ---- reading the audit log ----------------------------------------------- */

/* The files to read since the last run: [path, offset, live] in order. */
function shelllog_log_files($state) {
	clearstatcache();
	$cur = @stat(SHL_AUDIT_LOG);
	if (!$cur)
		return array();
	list($ino, $off) = array_pad(explode(':', (string)$state), 2, 0);
	$ino = (int)$ino; $off = (int)$off;
	if ($ino === (int)$cur['ino'])
		return array(array(SHL_AUDIT_LOG, $off > $cur['size'] ? 0 : $off, true));
	$files = array();
	if ($ino)
		foreach (glob(SHL_AUDIT_LOG.'.*') as $f) {
			$s = @stat($f);
			if ($s && (int)$s['ino'] === $ino) {
				$files[] = array($f, $off > $s['size'] ? 0 : $off, false);
				/* rotated more than once since: the copies in between too, oldest first */
				$n = (int)substr($f, strlen(SHL_AUDIT_LOG) + 1);
				for ($i = $n - 1; $i >= 1; $i--)
					if (is_file(SHL_AUDIT_LOG.'.'.$i))
						$files[] = array(SHL_AUDIT_LOG.'.'.$i, 0, false);
				break;
			}
		}
	$files[] = array(SHL_AUDIT_LOG, 0, true);
	return $files;
}

/* key=value pairs of an audit record (values quoted, hex or plain) */
function shelllog_fields($s) {
	$f = array();
	if (preg_match_all('/(?:^|\s)([\w\[\]-]+)=("[^"]*"|\S+)/', $s, $m, PREG_SET_ORDER))
		foreach ($m as $x)
			$f[$x[1]] = $x[2];
	return $f;
}

/* an audit value: "quoted" as is, unquoted = hex encoded (spaces, quotes, non-ASCII) */
function shelllog_val($v) {
	if ($v === null || $v === '(null)')
		return '';
	if (strlen($v) >= 2 && $v[0] === '"' && substr($v, -1) === '"')
		return substr($v, 1, -1);
	if ($v !== '' && strlen($v) % 2 === 0 && ctype_xdigit($v))
		return (string)hex2bin($v);
	return $v;
}

/* The argument list of an EXECVE record (long arguments come in a<N>[i] parts) */
function shelllog_execve_args($s, array &$args) {
	if (!preg_match_all('/(?:^|\s)a(\d+)(?:\[(\d+)\])?=("[^"]*"|[0-9A-Fa-f]+|\(null\))/', $s, $m, PREG_SET_ORDER))
		return;
	foreach ($m as $x) {
		$i = (int)$x[1];
		$v = shelllog_val($x[3]);
		if ($x[2] !== '')
			$args[$i] = ($args[$i] ?? '').$v;
		else
			$args[$i] = $v;
	}
}

/* One line for the list: arguments joined, quoted when they need it, control
   characters escaped, each argument and the whole kept to a sane length. */
function shelllog_cmdline(array $args) {
	ksort($args);
	$parts = array();
	$len = 0;
	foreach ($args as $a) {
		$a = (string)$a;
		if (strlen($a) > 600)
			$a = substr($a, 0, 600).'…';
		$a = preg_replace_callback('/[\x00-\x1f\x7f]/', function ($c) {
			return $c[0] === "\n" ? '\n' : ($c[0] === "\t" ? '\t' : sprintf('\x%02x', ord($c[0])));
		}, $a);
		if ($a === '' || preg_match('/[\s\'"$`\\\\|&;<>(){}*?!#~]/', $a))
			$a = "'".str_replace("'", "'\\''", $a)."'";
		$parts[] = $a;
		$len += strlen($a) + 1;
		if ($len > 3000) { $parts[] = '…'; break; }
	}
	return implode(' ', $parts);
}

/* Exact command lines a login runs before the prompt (/etc/profile.d, bash
   completion, environment modules): not the account's doing. */
function shelllog_noise($cmd) {
	static $re = null;
	if ($re === null)
		$re = '#^(?:'.implode('|', array(
			'/usr/bin/id -(?:un|gn|u)',
			'/usr/bin/hostname',
			"/bin/sh /usr/libexec/grepconf\\.sh -c",
			"grep -qsi '\\^COLOR\\.\\*none' /etc/GREP_COLORS",
			"/usr/bin/grep -qi '\\^COLOR\\.\\*none' [^ ]*",            // colorls.sh, 256-colour terminals
			'/usr/bin/tty -s',
			'/usr/bin/tput colors',
			'/usr/bin/dircolors --sh /etc/DIR_COLORS[\\w.]*',
			"tr '\\\\n' ' '",
			"cat /dev/null '?/etc/debuginfod/[^ ]*'?",
			"/usr/bin/sed -r -e 's/\\^\\[\\[:blank:\\]\\]\\*.*",
			'/usr/bin/tclsh /usr/share/Modules/libexec/modulecmd\\.tcl bash autoinit',
			'manpath',
			'/bin/ps -p \\d+ -ocomm=',
			'/bin/basename bash',
			'readlink /proc/\\d+/exe',
			'basename /usr/bin/bash',
			'/usr/bin/systemctl --user (?:set|unset|import)-environment .*',
			'/usr/bin/locale',
			'/usr/bin/uname -[sr]',
			'/usr/bin/consoletype stdout',
		)).')$#';
	return (bool)preg_match($re, $cmd);
}

/* The jail wrapper's own set-up (bin/reqad-jailshell), only right after it started */
function shelllog_jail_setup($cmd, $user) {
	$u = preg_quote($user, '#');
	return (bool)preg_match('#^(?:id -(?:u|un|g)|getent passwd '.$u.'|cut -d: -f6|hostname -s|dirname .*|awk -F: -v me='.$u.' .*|bwrap .*)$#', $cmd);
}

/* ---- red flags ----------------------------------------------------------- */

/* id => [label, regex on the command line (case-insensitive)] */
function shelllog_flag_rules() {
	return array(
		'download_run' => array('Downloaded code run straight away',
			'#\b(?:curl|wget|fetch)\b[^|;&]*\|\s*(?:sudo\s+)?(?:/\S*/)?(?:ba|da|z|k)?sh\b|\b(?:curl|wget)\b[^|;&]*\|\s*(?:/\S*/)?(?:python[\d.]*|perl|php[\d.]*|ruby|node)\b|\b(?:ba)?sh\s+<\(\s*(?:curl|wget)#i'),
		'hidden_code'  => array('Hidden (base64) code run',
			'#base64\s+(?:-d|--decode|-D)\b[^|]*\|\s*(?:/\S*/)?(?:ba|da|z)?sh\b|\beval\s*\(?\s*["\']?\$\(\s*echo\s+\S+\s*\|\s*base64|\beval\s*\(\s*(?:base64_decode|gzinflate|str_rot13)\s*\(|exec\s*\(\s*(?:base64\.)?b64decode|\bpython[\d.]*\s+-c\s+.*\bb64decode#i'),
		'reverse_shell'=> array('Reverse shell (gives someone outside a shell here)',
			'#/dev/(?:tcp|udp)/|\b(?:nc|ncat|netcat)(?:\.\w+)?\s+(?:.*\s)?-[a-z]*[ec]\s|\bsocat\b.*\b(?:exec|system):|\bbash\s+-i\s*[>&]|\bpty\.spawn\b|socket\.socket\(.*\.connect\(|\bfsockopen\s*\(|\bmkfifo\b.*\|\s*(?:nc|ncat|netcat)\b|\bperl\b.*\buse\s+Socket\b|\bTCPSocket\.(?:new|open)\b#i'),
		'miner'        => array('Crypto-miner',
			'#\b(?:xmrig|xmr-stak|minerd|cpuminer|ccminer|ethminer|nbminer|t-rex|lolminer|srbminer|teamredminer|nanominer|gminer|phoenixminer)\b|stratum\d?\+(?:tcp|ssl|tls)://|--donate-level\b|\b(?:c3pool|supportxmr|moneroocean|hashvault|nanopool|minexmr|2miners|f2pool|herominers|unmineable)\b#i'),
		'tmp_program'  => array('Program run from a temporary folder', ''),
		'attack_tool'  => array('Scanning or attack tool', ''),
		'become_root'  => array('Tried to become root or another user',
			'#^(?:\S*/)?(?:sudo|su|pkexec|doas)(?:\s|$)|\bfind\s+/\s.*-perm\s+(?:-|/)?(?:4000|u=s|2000|g=s)|\b(?:linpeas|linenum|lse|les|linux-exploit-suggester\S*|pspy\d*|unix-privesc-check|traitor|beroot|suid3num)(?:\.sh|\.py)?\b#i'),
		'others_files' => array('Looked at files outside its home', ''),
		'history'      => array('Cleared its shell history',
			'#\b(?:rm|shred|truncate|unlink|ln|cp|mv)\b.*\.(?:bash_|zsh_|mysql_|python_|psql_)?history\b|\bHISTFILE=/dev/null#i'),
	);
}

function shelllog_flag_label($id) {
	$r = shelllog_flag_rules();
	return isset($r[$id]) ? $r[$id][0] : $id;
}

/* The first red flag of a command ('' = none). $home: the account's home. */
function shelllog_flag($cmd, $exe, $argv0, $user, $home) {
	$rules = shelllog_flag_rules();
	foreach (array('download_run', 'hidden_code', 'reverse_shell', 'miner') as $id)
		if (preg_match($rules[$id][1], $cmd))
			return $id;
	foreach (array($exe, $argv0) as $p)
		if ($p !== '' && preg_match('#^(?:/tmp/|/var/tmp/|/dev/shm/|/run/user/\d+/|'.preg_quote(rtrim($home, '/'), '#').'/tmp/)#', $p))
			return 'tmp_program';
	$base = strtolower(basename($argv0 !== '' ? $argv0 : $exe));
	if (in_array($base, array('nmap', 'masscan', 'zmap', 'hydra', 'medusa', 'ncrack', 'sqlmap', 'nikto', 'wpscan', 'gobuster',
	                          'dirb', 'dirbuster', 'ffuf', 'feroxbuster', 'nuclei', 'hping3', 'slowloris', 'zgrab', 'zgrab2',
	                          'patator', 'crowbar', 'john', 'hashcat', 'xhydra', 'thc-hydra', 'mhddos', 'goldeneye'), true))
		return 'attack_tool';
	if (preg_match($rules['become_root'][1], $cmd))
		return 'become_root';
	if (preg_match('#(?:^|[\s=\'":])(?:/etc/(?:g?shadow|sudoers)\b|/root(?:/|\b)|/usr/local/reqad(?:-account)?/|/etc/reqad/|/var/lib/reqad/|/proc/\d+/environ\b)#', $cmd))
		return 'others_files';
	if (preg_match_all('#(?:^|[\s=\'":])/home/([a-z0-9_][a-z0-9_.-]*)#', $cmd, $m))
		foreach ($m[1] as $other)
			if ($other !== $user && '/home/'.$other !== rtrim($home, '/'))
				return 'others_files';
	if (preg_match($rules['history'][1], $cmd))
		return 'history';
	return '';
}

/* ---- collect ------------------------------------------------------------- */

/* Root, cron every minute: read the audit log since the last run. */
function shelllog_collect() {
	global $db;
	if (!function_exists('posix_geteuid') || posix_geteuid() !== 0)
		return result_error('reqad shelllog collect must run as root.');
	$sdb = shelllog_db();
	if (!$sdb)
		return result_error('Could not open '.SHL_DB.'.');
	$now = time();
	/* old rows go also while it is off */
	if ((int)shelllog_meta_get('pruned', '0') < $now - 3600) {
		$keep = $now - shelllog_days() * 86400;
		$sdb->exec('DELETE FROM shell_cmds WHERE last < '.$keep);
		$sdb->exec('DELETE FROM shell_sessions WHERE t < '.($keep - 86400));
		shelllog_meta_put('pruned', (string)$now);
	}
	if (!shelllog_enabled())
		return result_ok('Command logging is off.', array('commands' => 0));

	/* the rule is gone (auditd restarted with other rules, a restore): put it back,
	   at most every 10 minutes */
	if (!shelllog_rule_loaded()) {
		if ((int)shelllog_meta_get('rule-tried', '0') < $now - 600) {
			shelllog_meta_put('rule-tried', (string)$now);
			shelllog_meta_put('rule-error', shelllog_rule_load());
		}
	} elseif (shelllog_meta_get('rule-error', '') !== '')
		shelllog_meta_put('rule-error', '');

	/* uid => account, home */
	$accounts = array();
	$r = $db->query('SELECT user FROM accounts');
	while ($r && ($row = $r->fetchArray(SQLITE3_ASSOC))) {
		$pw = posix_getpwnam($row['user']);
		if ($pw && (int)$pw['uid'] >= 1000)
			$accounts[(int)$pw['uid']] = array('user' => $row['user'], 'home' => (string)$pw['dir']);
	}

	$events = array();     // complete exec events, in order
	$sessions = array();   // ses => [[t, user, kind, addr]...] seen in this read
	$state = shelllog_meta_get('log', '');
	$newstate = $state;
	/* events after the resume point that the last run already took */
	$done = array_flip(array_filter(explode(',', shelllog_meta_get('done', ''))));
	$newdone = array();
	foreach (shelllog_log_files($state) as $lf) {
		list($path, $off, $live) = $lf;
		$fh = @fopen($path, 'rb');
		if (!$fh)
			continue;
		fseek($fh, $off);
		$pending = array();   // serial => event being read
		$pos = $off;
		$stopat = null;
		while (($line = fgets($fh, 1 << 20)) !== false) {
			$start = $pos;
			$pos += strlen($line);
			if (substr($line, -1) !== "\n") {   // a line auditd is still writing
				$stopat = $start;
				break;
			}
			if (strncmp($line, 'type=', 5) !== 0)
				continue;
			$sp = strpos($line, ' ');
			$type = substr($line, 5, $sp - 5);
			if (!in_array($type, array('SYSCALL', 'EXECVE', 'CWD', 'PATH', 'PROCTITLE', 'USER_START'), true))
				continue;
			if (!preg_match('/^msg=audit\((\d+)\.\d+:(\d+)\): /', substr($line, $sp + 1), $mm))
				continue;
			$t = (int)$mm[1]; $serial = $mm[2];
			$body = substr($line, $sp + 1 + strlen($mm[0]));
			$gs = strpos($body, "\x1d");      // ENRICHED log format: the translated part after GS
			if ($gs !== false)
				$body = substr($body, 0, $gs);
			$body = rtrim($body, "\r\n");
			if ($type === 'USER_START') {
				$f = shelllog_fields($body);
				$ses = (int)($f['ses'] ?? 0);
				if (!preg_match('/acct="([^"]*)"/', $body, $am) || !preg_match('/exe="([^"]*)"/', $body, $em))
					continue;
				$kind = basename($em[1]);
				$addr = preg_match('/\baddr=([0-9a-fA-F.:]+)/', $body, $xm) ? $xm[1] : '';
				if ($ses && $ses !== SHL_UNSET)
					$sessions[$ses][] = array($t, $am[1], $kind, $addr);
				continue;
			}
			if ($type === 'SYSCALL') {
				if (strpos($body, 'key="'.SHL_KEY.'"') === false || isset($done[$serial]))
					continue;
				$f = shelllog_fields($body);
				$pending[$serial] = array('t' => $t, 'start' => $start, 'sys' => $f, 'args' => array(), 'cwd' => '', 'path0' => '');
				continue;
			}
			if (!isset($pending[$serial]))
				continue;
			$ev = &$pending[$serial];
			if ($type === 'EXECVE')
				shelllog_execve_args($body, $ev['args']);
			elseif ($type === 'CWD')
				$ev['cwd'] = shelllog_val(shelllog_fields($body)['cwd'] ?? '');
			elseif ($type === 'PATH' && $ev['path0'] === '' && preg_match('/^item=0 name=("[^"]*"|[0-9A-Fa-f]+)/', $body, $pm))
				$ev['path0'] = shelllog_val($pm[1]);
			unset($ev);
			if ($type === 'PROCTITLE') {
				$events[] = $pending[$serial] + array('serial' => $serial);
				unset($pending[$serial]);
			}
		}
		fclose($fh);
		/* events still without their last record: read them again next time,
		   unless they are old (a record that never came) */
		if ($live) {
			$resume = $stopat !== null ? $stopat : $pos;
			foreach ($pending as $serial => $ev) {
				if ($ev['t'] >= $now - 5)
					$resume = min($resume, $ev['start']);
				else
					$events[] = $ev + array('serial' => $serial);
			}
			/* what this run took after that point is skipped next time */
			foreach ($events as $ev)
				if (isset($ev['serial']) && $ev['start'] >= $resume && $ev['start'] >= $off)
					$newdone[] = $ev['serial'];
			clearstatcache();
			$st = @stat($path);
			if ($st)
				$newstate = $st['ino'].':'.$resume;
		} else {
			foreach ($pending as $serial => $ev)
				$events[] = $ev + array('serial' => $serial);
		}
	}

	/* sessions first: where each exec came from */
	$ins = $sdb->prepare('INSERT OR REPLACE INTO shell_sessions (ses, t, user, kind, addr) VALUES (:s, :t, :u, :k, :a)');
	foreach ($sessions as $ses => $list)
		foreach ($list as $x) {
			$ins->bindValue(':s', $ses, SQLITE3_INTEGER);
			$ins->bindValue(':t', $x[0], SQLITE3_INTEGER);
			$ins->bindValue(':u', $x[1], SQLITE3_TEXT);
			$ins->bindValue(':k', $x[2], SQLITE3_TEXT);
			$ins->bindValue(':a', $x[3], SQLITE3_TEXT);
			$ins->execute();
		}
	$ses_q = $sdb->prepare('SELECT kind, addr FROM shell_sessions WHERE ses = :s AND t <= :t ORDER BY t DESC LIMIT 1');
	$ses_cache = array();
	$session_of = function ($ses, $t) use ($ses_q, &$ses_cache) {
		$k = $ses.':'.intdiv($t, 60);
		if (!array_key_exists($k, $ses_cache)) {
			$ses_q->bindValue(':s', $ses, SQLITE3_INTEGER);
			$ses_q->bindValue(':t', $t + 1, SQLITE3_INTEGER);
			$r = $ses_q->execute();
			$ses_cache[$k] = $r ? ($r->fetchArray(SQLITE3_ASSOC) ?: null) : null;
		}
		return $ses_cache[$k];
	};

	/* first pass: who, from where, what - and what to leave out */
	$rows = array();
	$jail_start = array();   // euid:ses => time the jail wrapper started
	$names = array();
	foreach ($events as $ev) {
		$s = $ev['sys'];
		$euid = (int)($s['euid'] ?? -1);
		if (!isset($accounts[$euid]))
			continue;
		$acct = $accounts[$euid];
		$err = ($s['success'] ?? 'yes') === 'yes' ? 0 : (int)($s['exit'] ?? 0);
		if ($err === -2 || $err === -20)    // ENOENT / ENOTDIR: a PATH search
			continue;
		$args = $ev['args'];
		if (!$args && $ev['path0'] !== '')
			$args = array($ev['path0']);
		if (!$args)
			continue;
		$exe = shelllog_val($s['exe'] ?? '');
		if ($err)
			$exe = $ev['path0'];
		ksort($args);
		$argv0 = (string)reset($args);
		$cmd = shelllog_cmdline($args);
		$ses = (int)($s['ses'] ?? 0);
		$t = $ev['t'];
		/* the user's systemd manager and its generators */
		if (strncmp($exe, '/usr/lib/systemd/', 17) === 0 || strncmp($exe, '/usr/libexec/podman/', 20) === 0)
			continue;
		if (shelllog_noise($cmd))
			continue;
		/* the jail wrapper (the command it runs comes again as bash -c inside) */
		if (isset($args[1]) && in_array($args[1], array('/usr/local/bin/reqad-jailshell', '/usr/local/bin/reqad-sftponly'), true)
		    && basename($exe) === 'bash') {
			$jail_start[$euid.':'.$ses] = $t;
			continue;
		}
		if (isset($jail_start[$euid.':'.$ses]) && $t - $jail_start[$euid.':'.$ses] <= 3 && shelllog_jail_setup($cmd, $acct['user']))
			continue;

		$auid = (int)($s['auid'] ?? SHL_UNSET);
		$tty = (string)($s['tty'] ?? '(none)');
		$src = 'login'; $via = ''; $addr = '';
		if ($auid === $euid) {
			$sx = $ses && $ses !== SHL_UNSET ? $session_of($ses, $t) : null;
			$kind = $sx ? $sx['kind'] : '';
			if ($kind === 'sshd') { $src = 'ssh'; $addr = (string)$sx['addr']; }
			elseif ($kind === 'crond' || $kind === 'atd') $src = 'cron';
			elseif (strncmp($tty, 'pts', 3) === 0) $src = 'ssh';
		} elseif ($auid === SHL_UNSET) {
			$src = strncmp($tty, 'pts', 3) === 0 ? 'terminal' : 'web';
		} else {
			if (!isset($names[$auid])) {
				$pw = posix_getpwuid($auid);
				$names[$auid] = $pw ? $pw['name'] : (string)$auid;
			}
			$via = $names[$auid];
			$src = isset($accounts[$auid]) ? 'account' : 'admin';
		}
		/* the panel's own file helper, run as the account for an admin or the account panel */
		if ($src !== 'ssh' && $src !== 'terminal' && $src !== 'cron' && strpos($cmd, '/usr/local/reqad/scripts/files/fm-helper.php') !== false)
			continue;

		$rows[] = array('user' => $acct['user'], 'home' => $acct['home'], 'src' => $src, 'via' => $via, 'addr' => $addr,
		                't' => $t, 'cwd' => $ev['cwd'], 'exe' => $exe, 'argv0' => $argv0, 'cmd' => $cmd, 'err' => $err,
		                'ppid' => (int)($s['ppid'] ?? 0), 'pid' => (int)($s['pid'] ?? 0), 'euid' => $euid,
		                'flag' => $src === 'admin' ? '' : shelllog_flag($cmd, $exe, $argv0, $acct['user'], $acct['home']));
	}

	/* second pass: `curl ... | sh` typed at a prompt is two processes of one
	   pipeline - started together by the same shell */
	$by_parent = array();
	foreach ($rows as $i => $r)
		$by_parent[$r['euid'].':'.$r['ppid']][] = $i;
	foreach ($by_parent as $list) {
		if (count($list) < 2)
			continue;
		foreach ($list as $i) {
			$a = $rows[$i];
			if ($a['src'] === 'admin') continue;
			$base = basename($a['argv0']);
			$fetch = in_array($base, array('curl', 'wget'), true) ? 'download_run'
			       : ((($base === 'base64' && preg_match('/\s-(?:d|-decode|D)\b/', $a['cmd'])) || ($base === 'xxd' && strpos($a['cmd'], ' -r') !== false)) ? 'hidden_code' : '');
			if ($fetch === '')
				continue;
			foreach ($list as $j) {
				$b = $rows[$j];
				if ($j === $i || abs($b['t'] - $a['t']) > 2) continue;
				/* a shell or interpreter reading its program from the pipe: no script named */
				if (preg_match('#^(?:\S*/)?(?:ba|da|z|k)?sh(?:\s+-[a-z]+)*\s*$|^(?:\S*/)?(?:python[\d.]*|perl|php[\d.]*|ruby|node)(?:\s+-)?\s*$#', $b['cmd'])) {
					if ($rows[$i]['flag'] === '') $rows[$i]['flag'] = $fetch;
					if ($rows[$j]['flag'] === '') $rows[$j]['flag'] = $fetch;
				}
			}
		}
	}

	/* write: the same command again within the hour adds to its row */
	$up = $sdb->prepare('INSERT INTO shell_cmds (user, src, via, addr, first, last, n, hour, k, cwd, exe, cmd, err, flag)
		VALUES (:user, :src, :via, :addr, :t, :t, :n, :hour, :k, :cwd, :exe, :cmd, :err, :flag)
		ON CONFLICT (k, hour) DO UPDATE SET n = n + excluded.n, last = MAX(last, excluded.last), first = MIN(first, excluded.first),
			flag = CASE WHEN flag = \'\' THEN excluded.flag ELSE flag END');
	$sdb->exec('BEGIN');
	$count = 0; $flagged = 0;
	$per_user = array(); $over = array();
	foreach ($rows as $r) {
		$per_user[$r['user']] = ($per_user[$r['user']] ?? 0) + 1;
		if ($per_user[$r['user']] > SHL_MAX_ROWS_RUN && $r['flag'] === '') {
			$over[$r['user']] = array(($over[$r['user']][0] ?? 0) + 1, $r['t'], $r['src']);
			continue;
		}
		$key = substr(sha1($r['user']."\0".$r['src']."\0".$r['via']."\0".$r['addr']."\0".$r['cwd']."\0".$r['cmd']."\0".$r['err']), 0, 20);
		$up->bindValue(':user', $r['user'], SQLITE3_TEXT);
		$up->bindValue(':src', $r['src'], SQLITE3_TEXT);
		$up->bindValue(':via', $r['via'], SQLITE3_TEXT);
		$up->bindValue(':addr', $r['addr'], SQLITE3_TEXT);
		$up->bindValue(':t', $r['t'], SQLITE3_INTEGER);
		$up->bindValue(':n', 1, SQLITE3_INTEGER);
		$up->bindValue(':hour', intdiv($r['t'], 3600), SQLITE3_INTEGER);
		$up->bindValue(':k', $key, SQLITE3_TEXT);
		$up->bindValue(':cwd', $r['cwd'], SQLITE3_TEXT);
		$up->bindValue(':exe', $r['exe'], SQLITE3_TEXT);
		$up->bindValue(':cmd', $r['cmd'], SQLITE3_TEXT);
		$up->bindValue(':err', $r['err'], SQLITE3_INTEGER);
		$up->bindValue(':flag', $r['flag'], SQLITE3_TEXT);
		$up->execute();
		$up->reset();
		$count++;
		if ($r['flag'] !== '') $flagged++;
	}
	foreach ($over as $user => $x) {
		$key = substr(sha1($user."\0over"), 0, 20);
		foreach (array(':user' => $user, ':src' => $x[2], ':via' => '', ':addr' => '', ':cwd' => '', ':exe' => '', ':flag' => '',
		               ':cmd' => '(more commands in this minute than are stored one by one)', ':k' => $key) as $p => $v)
			$up->bindValue($p, $v, SQLITE3_TEXT);
		$up->bindValue(':t', $x[1], SQLITE3_INTEGER);
		$up->bindValue(':n', $x[0], SQLITE3_INTEGER);
		$up->bindValue(':hour', intdiv($x[1], 3600), SQLITE3_INTEGER);
		$up->bindValue(':err', 0, SQLITE3_INTEGER);
		$up->execute();
		$up->reset();
	}
	$sdb->exec('COMMIT');
	shelllog_meta_put('log', $newstate);
	shelllog_meta_put('done', implode(',', array_slice($newdone, -2000)));
	shelllog_meta_put('collected', (string)$now);
	return result_ok("$count command(s) logged".($flagged ? ", $flagged flagged" : '').'.', array('commands' => $count, 'flagged' => $flagged));
}

/* ---- reading ------------------------------------------------------------- */

/* Every account with its counts: 24 hours, 7 days, flagged in the kept days,
   the newest command. Admin rows are not counted. */
function shelllog_accounts() {
	global $db;
	$sdb = shelllog_db();
	$now = time();
	$out = array();
	$r = $db->query('SELECT user, domain FROM accounts ORDER BY user');
	while ($r && ($row = $r->fetchArray(SQLITE3_ASSOC)))
		$out[$row['user']] = array('user' => $row['user'], 'domain' => (string)$row['domain'], 'shell' => account_shell_get($row['user']),
		                           'day' => 0, 'week' => 0, 'flagged' => 0, 'flag_new' => 0, 'last' => 0, 'last_cmd' => '', 'last_src' => '');
	if (!$sdb)
		return array_values($out);
	$q = $sdb->query("SELECT user,
		SUM(CASE WHEN last >= ".($now - 86400)." THEN n ELSE 0 END) AS day,
		SUM(CASE WHEN last >= ".($now - 7 * 86400)." THEN n ELSE 0 END) AS week,
		SUM(CASE WHEN flag != '' THEN 1 ELSE 0 END) AS flagged,
		SUM(CASE WHEN flag != '' AND last >= ".($now - 86400)." THEN 1 ELSE 0 END) AS flag_new
		FROM shell_cmds WHERE src != 'admin' GROUP BY user");
	while ($q && ($x = $q->fetchArray(SQLITE3_ASSOC)))
		if (isset($out[$x['user']]))
			foreach (array('day', 'week', 'flagged', 'flag_new') as $k)
				$out[$x['user']][$k] = (int)$x[$k];
	$st = $sdb->prepare("SELECT last, cmd, src FROM shell_cmds WHERE user = :u AND src != 'admin' ORDER BY last DESC LIMIT 1");
	foreach ($out as $u => &$a) {
		if (!$a['week'] && !$a['flagged']) continue;
		$st->bindValue(':u', $u, SQLITE3_TEXT);
		$x = $st->execute()->fetchArray(SQLITE3_ASSOC);
		$st->reset();
		if ($x) { $a['last'] = (int)$x['last']; $a['last_cmd'] = (string)$x['cmd']; $a['last_src'] = (string)$x['src']; }
	}
	unset($a);
	$out = array_values($out);
	usort($out, function ($a, $b) {
		return array($b['flag_new'], $b['flagged'], $b['last'], $a['user']) <=> array($a['flag_new'], $a['flagged'], $a['last'], $b['user']);
	});
	return $out;
}

/* One account's commands, newest first.
   $f: day (YYYY-MM-DD), src, flagged (bool), q (text), admin (bool: include admin rows), page */
function shelllog_list($user, array $f = array(), $per_page = 200) {
	$sdb = shelllog_db();
	$empty = array('rows' => array(), 'more' => false, 'page' => 0, 'total' => 0);
	if (!$sdb || !valid_username($user))
		return $empty;
	$where = array('user = :u'); $bind = array(':u' => $user);
	if (!empty($f['day']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['day'])) {
		$d0 = strtotime($f['day'].' 00:00:00');
		$where[] = 'last >= :d0 AND first < :d1';
		$bind[':d0'] = $d0; $bind[':d1'] = $d0 + 86400;
	}
	if (!empty($f['src']) && isset(shelllog_sources()[$f['src']])) {
		$where[] = 'src = :src'; $bind[':src'] = $f['src'];
	} elseif (empty($f['admin']))
		$where[] = "src != 'admin'";
	if (!empty($f['flagged']))
		$where[] = "flag != ''";
	if (isset($f['q']) && trim((string)$f['q']) !== '') {
		$where[] = "(cmd LIKE :q ESCAPE '\\' OR cwd LIKE :q ESCAPE '\\' OR addr LIKE :q ESCAPE '\\')";
		$bind[':q'] = '%'.addcslashes(trim((string)$f['q']), '%_\\').'%';
	}
	$page = max(0, (int)($f['page'] ?? 0));
	$sql = 'FROM shell_cmds WHERE '.implode(' AND ', $where);
	$st = $sdb->prepare('SELECT id, src, via, addr, first, last, n, cwd, exe, cmd, err, flag '.$sql.' ORDER BY first DESC, id DESC LIMIT '.($per_page + 1).' OFFSET '.($page * $per_page));
	$ct = $sdb->prepare('SELECT COALESCE(SUM(n), 0) '.$sql);
	foreach ($bind as $k => $v) {
		$st->bindValue($k, $v, is_int($v) ? SQLITE3_INTEGER : SQLITE3_TEXT);
		$ct->bindValue($k, $v, is_int($v) ? SQLITE3_INTEGER : SQLITE3_TEXT);
	}
	$rows = array();
	$r = $st->execute();
	while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC)))
		$rows[] = $x;
	$more = count($rows) > $per_page;
	if ($more) array_pop($rows);
	$total = (int)$ct->execute()->fetchArray(SQLITE3_NUM)[0];
	return array('rows' => $rows, 'more' => $more, 'page' => $page, 'total' => $total);
}

/* The account's sessions with an address (SSH logins), newest first */
function shelllog_logins($user, $limit = 8) {
	$sdb = shelllog_db();
	if (!$sdb) return array();
	$st = $sdb->prepare("SELECT addr, COUNT(*) AS n, MAX(t) AS t FROM shell_sessions WHERE user = :u AND kind = 'sshd' AND addr != '' GROUP BY addr ORDER BY t DESC LIMIT ".(int)$limit);
	$st->bindValue(':u', $user, SQLITE3_TEXT);
	$out = array();
	$r = $st->execute();
	while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC)))
		$out[] = $x;
	return $out;
}

/* What an exec error means, for the list */
function shelllog_err_label($err) {
	$e = array(-1 => 'not allowed', -13 => 'permission denied', -8 => 'not a program', -7 => 'too many arguments', -12 => 'out of memory', -26 => 'file busy');
	return $e[(int)$err] ?? 'failed ('.(int)$err.')';
}
