<?php
/*
 * Cron jobs of a hosting account, for the API (and so the account panel on
 * 2083). The account's crontab (/var/spool/cron/<user>) is the inventory;
 * scripts/cron/cron_helper.sh reads and writes it as root (only when it has
 * not changed since it was read) and starts a job on request, the jobs
 * themselves always run as the account.
 *
 * A job made here is two lines:
 *   # reqad-cron <id> <name, rawurlencoded>
 *   <minute hour day month weekday> /usr/local/bin/reqad-cron <id> '<command>'
 * and a paused one has "#reqad-off " in front of its second line. The
 * wrapper (bin/reqad-cron) runs the command at lower priority, never two runs
 * at once, and records each run in ~/.reqad/cron/ (read back as the account
 * with fm-helper's cronruns). Other lines (made over SSH or on 2087) are
 * listed too: not tracked, editing one turns it into a job of ours in its
 * place. The WordPress Toolkit's own line (# reqad-wpcron-) is built in:
 * shown, never changed here.
 *
 * Times are the server's local time, as crond uses it (cron_tz()).
 */

const CRON_WRAP = '/usr/local/bin/reqad-cron';
const CRON_MAX = 100;           // jobs of one account (made here or not)
const CRON_CMD_MAX = 1000;

function cron_helper($args, $in = null) {
	$cmd = 'sudo -n '.escapeshellarg(_PATH.'/scripts/cron/cron_helper.sh');
	foreach ($args as $a)
		$cmd .= ' '.escapeshellarg($a);
	$p = proc_open($cmd, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	if (!is_resource($p))
		return array(4, '', 'could not start');
	if ($in !== null)
		fwrite($pipes[0], $in);
	fclose($pipes[0]);
	$out = stream_get_contents($pipes[1]);
	$err = trim((string)stream_get_contents($pipes[2]));
	fclose($pipes[1]);
	fclose($pipes[2]);
	return array(proc_close($p), (string)$out, preg_replace('/^cron_helper: /', '', $err));
}

/* array(hash, text) of the account's crontab, or array(null, error) */
function cron_read($user) {
	list($rc, $out, $err) = cron_helper(array('get', $user));
	if ($rc !== 0 || !preg_match('/^sha1 ([0-9a-f]{40}|none)\n?/', $out, $m))
		return array(null, 'The crontab could not be read'.($err !== '' ? " ($err)." : '.'));
	return array($m[1], substr($out, strlen($m[0])));
}

/* '' or why the crontab was not written */
function cron_write($user, $hash, $text) {
	$text = rtrim($text, "\n");
	list($rc, , $err) = cron_helper(array('put', $user, $hash), $text === '' ? '' : $text."\n");
	if ($rc === 5)
		return 'The cron jobs were changed meanwhile. Reload the page and try again.';
	return $rc === 0 ? '' : 'The crontab could not be written'.($err !== '' ? " ($err)." : '.');
}

/* The server's time zone, the one crond uses */
function cron_tz() {
	static $tz = null;
	if ($tz === null)
		$tz = new DateTimeZone(system_timezone());
	return $tz;
}

/* ---- schedules ---------------------------------------------------------------- */

function cron_macros() {
	return array('@yearly' => '0 0 1 1 *', '@annually' => '0 0 1 1 *', '@monthly' => '0 0 1 * *', '@weekly' => '0 0 * * 0',
	             '@daily' => '0 0 * * *', '@midnight' => '0 0 * * *', '@hourly' => '0 * * * *');
}

/* One field: the sorted values it allows, or false. */
function cron_field($s, $lo, $hi, array $names = array()) {
	$vals = array();
	foreach (explode(',', strtolower($s)) as $part) {
		$step = 1;
		if (strpos($part, '/') !== false) {
			list($part, $st) = explode('/', $part, 2);
			if (!ctype_digit($st) || (int)$st < 1 || (int)$st > $hi)
				return false;
			$step = (int)$st;
		}
		$num = function ($x) use ($names, $lo) {
			if (isset($names[$x]))
				return $names[$x];
			return ctype_digit($x) && strlen($x) <= 2 ? (int)$x : false;
		};
		if ($part === '*') {
			$a = $lo;
			$b = $hi;
		} elseif (strpos($part, '-') !== false) {
			list($x, $y) = explode('-', $part, 2);
			$a = $num($x);
			$b = $num($y);
		} else {
			$a = $num($part);
			$b = $step > 1 ? $hi : $a;
		}
		if ($a === false || $b === false || $a < $lo || $b > $hi || $a > $b)
			return false;
		for ($v = $a; $v <= $b; $v += $step)
			$vals[$v] = true;
	}
	$vals = array_keys($vals);
	sort($vals);
	return $vals;
}

/*
 * A schedule: "m h dom mon dow" or a macro (@daily, ...; not @reboot).
 * Returns array(expr (5 fields, macros written out), sets) or false.
 */
function cron_schedule($expr) {
	$expr = trim(preg_replace('/\s+/', ' ', (string)$expr));
	$macros = cron_macros();
	if (isset($macros[strtolower($expr)]))
		$expr = $macros[strtolower($expr)];
	$f = explode(' ', $expr);
	if (count($f) !== 5)
		return false;
	$mon = array('jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12);
	$dow = array('sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6);
	$sets = array(cron_field($f[0], 0, 59), cron_field($f[1], 0, 23), cron_field($f[2], 1, 31), cron_field($f[3], 1, 12, $mon), cron_field($f[4], 0, 7, $dow));
	foreach ($sets as $s)
		if ($s === false)
			return false;
	/* 7 is Sunday too */
	if (in_array(7, $sets[4], true))
		$sets[4] = array_values(array_unique(array_merge(array(0), array_diff($sets[4], array(7)))));
	sort($sets[4]);
	/* crond: when day and weekday are both limited, either one is enough */
	$sets['or'] = $f[2][0] !== '*' && $f[4][0] !== '*';
	return array($expr, $sets);
}

/* When the schedule next fires after $now (a timestamp), or null (never, e.g. 30 February). */
function cron_next($expr, $now = null) {
	$s = cron_schedule($expr);
	if ($s === false)
		return null;
	list(, $sets) = $s;
	list($mins, $hours, $doms, $mons, $dows) = $sets;
	$tz = cron_tz();
	$t = new DateTime('@'.(($now ?? time()) + 60 - (($now ?? time()) % 60)));
	$t->setTimezone($tz);
	$day = new DateTime($t->format('Y-m-d').' 00:00:00', $tz);
	$first = true;
	for ($i = 0; $i < 366 * 5; $i++, $first = false) {
		if ($i > 0)
			$day->modify('+1 day');
		if (!in_array((int)$day->format('n'), $mons, true))
			continue;
		$dm = in_array((int)$day->format('j'), $doms, true);
		$dw = in_array((int)$day->format('w'), $dows, true);
		if ($sets['or'] ? !($dm || $dw) : !($dm && $dw))
			continue;
		foreach ($hours as $h) {
			if ($first && $h < (int)$t->format('G'))
				continue;
			foreach ($mins as $m) {
				if ($first && $h === (int)$t->format('G') && $m < (int)$t->format('i'))
					continue;
				$c = new DateTime($day->format('Y-m-d').sprintf(' %02d:%02d:00', $h, $m), $tz);
				if ($c->getTimestamp() >= $t->getTimestamp())
					return $c->getTimestamp();
			}
		}
	}
	return null;
}

/* ---- the crontab ---------------------------------------------------------------- */

/* '<command>' as the wrapper's argument in a crontab line: single quotes, and
   % escaped (crond turns a bare % into a new line) */
function cron_quote($cmd) {
	return "'".str_replace(array("'", '%'), array("'\\''", '\\%'), $cmd)."'";
}

function cron_unquote($q) {
	if (!preg_match("/^'((?:[^']|'\\\\'')*)'$/s", $q, $m))
		return null;
	return str_replace(array("'\\''", '\\%'), array("'", '%'), $m[1]);
}

/*
 * The crontab's lines and its jobs. A job: id, name, schedule, command, on,
 * kind (reqad = made here | other = a line made elsewhere | builtin = the
 * WordPress Toolkit's), at (its line), name_at (its name line, or -1).
 */
function cron_parse($text) {
	$lines = $text === '' ? array() : explode("\n", rtrim($text, "\n"));
	$jobs = array();
	$names = array();
	$wrap = preg_quote(CRON_WRAP, '#');
	foreach ($lines as $i => $l) {
		if (preg_match('/^# reqad-cron ([a-z0-9]{8}) ?(\S*)$/', $l, $m)) {
			$names[$m[1]] = array($i, rawurldecode($m[2]));
			continue;
		}
		if (preg_match('#^(\#reqad-off )?(\S+ +\S+ +\S+ +\S+ +\S+) +'.$wrap.' ([a-z0-9]{8}) (\'.*\')$#', $l, $m)) {
			$cmd = cron_unquote($m[4]);
			if ($cmd !== null) {
				$jobs[] = array('id' => $m[3], 'name' => '', 'schedule' => preg_replace('/ +/', ' ', $m[2]), 'command' => $cmd,
				                'on' => $m[1] === '', 'kind' => 'reqad', 'at' => $i, 'name_at' => -1);
				continue;
			}
		}
		$t = trim($l);
		if ($t === '' || $t[0] === '#' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*\s*=/', $t))
			continue;
		if (preg_match('/^(@[a-z]+|\S+\s+\S+\s+\S+\s+\S+\s+\S+)\s+(.+)$/', $t, $m)) {
			$builtin = strpos($m[2], '# reqad-wpcron-') !== false;
			$jobs[] = array('id' => 'x'.substr(sha1($t), 0, 7), 'name' => '', 'schedule' => preg_replace('/\s+/', ' ', $m[1]),
			                'command' => $builtin ? trim(preg_replace('/\s*# reqad-wpcron-.*$/', '', $m[2])) : $m[2],
			                'on' => true, 'kind' => $builtin ? 'builtin' : 'other', 'at' => $i, 'name_at' => -1);
		}
	}
	foreach ($jobs as &$j)
		if (isset($names[$j['id']]))
			list($j['name_at'], $j['name']) = $names[$j['id']];
	unset($j);
	return array($lines, $jobs);
}

/* The two lines of a job of ours */
function cron_job_lines($id, $name, $schedule, $command, $on) {
	return array(rtrim('# reqad-cron '.$id.' '.rawurlencode($name)),
	             ($on ? '' : '#reqad-off ').$schedule.' '.CRON_WRAP.' '.$id.' '.cron_quote($command));
}

/* ---- what a command is ------------------------------------------------------------ */

function cron_php_bin($version) {
	global $ini;
	return $version === ($ini['php'] ?? '') ? '/usr/bin/php' : '/opt/remi/php'.str_replace('.', '', $version).'/root/usr/bin/php';
}

/* array(type: php|url|command, + php/file or url; wp: a WP-CLI command) */
function cron_command_type($cmd) {
	global $ini;
	if (preg_match('#^(/usr/bin/php|/opt/remi/php(\d)(\d+)/root/usr/bin/php) ([A-Za-z0-9._/-]+)$#', $cmd, $m))
		return array('type' => 'php', 'php' => $m[1] === '/usr/bin/php' ? (string)($ini['php'] ?? '') : $m[2].'.'.$m[3], 'file' => $m[4]);
	if (preg_match("#^curl -fsS --max-time 300 '(https?://[^' ]+)'$#", $cmd, $m))
		return array('type' => 'url', 'url' => $m[1]);
	return array('type' => 'command', 'wp' => (bool)preg_match('#^(/usr/local/bin/)?wp\s#', $cmd));
}

/*
 * The command of a job from what was asked: command (as typed), or type php
 * (file relative to the home folder + php version) or type url (opened with
 * curl, a failed HTTP status fails the run). array(command, error).
 */
function cron_command_build($user, array $o) {
	$type = (string)($o['type'] ?? 'command');
	if ($type === 'php') {
		$file = preg_replace('#^(~/|/home/'.preg_quote($user, '#').'/)#', '', trim((string)($o['file'] ?? '')));
		if ($file === '' || !preg_match('#^[A-Za-z0-9._-][A-Za-z0-9._/-]{0,249}$#', $file) || preg_match('#(^|/)\.\.(/|$)#', $file))
			return array('', 'Give the PHP file as a path in your home folder, such as public_html/cron.php (letters, digits, . _ - and /).');
		$v = (string)($o['php'] ?? '');
		if (!in_array($v, array_column(php_versions(), 'version'), true))
			return array('', 'Choose one of the PHP versions of this server.');
		return array(cron_php_bin($v).' '.$file, '');
	}
	if ($type === 'url') {
		$url = trim((string)($o['url'] ?? ''));
		if (!preg_match('#^https?://[A-Za-z0-9.-]+(:[0-9]{1,5})?(/[A-Za-z0-9._~:/?@!&()*+,;=%-]*)?$#', $url) || strlen($url) > 500)
			return array('', 'Give a full address such as https://example.com/cron.php.');
		return array("curl -fsS --max-time 300 '".$url."'", '');
	}
	if ($type !== 'command')
		return array('', 'Unknown kind of job.');
	/* \% is how a crontab line spells %; here the wrapper's quoting does that */
	$cmd = str_replace('\\%', '%', trim((string)($o['command'] ?? '')));
	if ($cmd === '')
		return array('', 'Write the command to run.');
	if (strlen($cmd) > CRON_CMD_MAX)
		return array('', 'The command is too long (at most '.CRON_CMD_MAX.' characters).');
	if (preg_match('/[\x00-\x1f\x7f]/', $cmd) || !preg_match('//u', $cmd))
		return array('', 'The command must be one line of text.');
	return array($cmd, '');
}

/* ---- runs ----------------------------------------------------------------------- */

/* id => newest first list of array(start (s), ms, duration ms|null, rc int|'skip'|null) */
function cron_runs_all($user) {
	$a = files_run($user, array('verb' => 'cronruns'), null, null, 20);
	$by = array();
	if (empty($a['ok']))
		return $by;
	/* a run's start line comes first, its end line (same start) replaces it */
	foreach ($a['runs'] ?? array() as $r) {
		list($id, $ms, $dur, $rc) = $r;
		$by[$id][$ms] = array('start' => intdiv($ms, 1000), 'ms' => $ms, 'duration' => $dur, 'rc' => $rc);
	}
	foreach ($by as $id => $rows) {
		krsort($rows);
		$by[$id] = array_values($rows);
	}
	return $by;
}

/* A run's state: ok | failed | skipped | running | unknown (started long ago, no end) */
function cron_run_state(array $r) {
	if ($r['rc'] === 'skip')
		return 'skipped';
	if ($r['rc'] === null)
		return $r['start'] > time() - 86400 ? 'running' : 'unknown';
	return $r['rc'] === 0 ? 'ok' : 'failed';
}

/* ---- the operations ------------------------------------------------------------------ */

function cron_job_out(array $j, array $runs) {
	$o = array('id' => $j['id'], 'name' => $j['name'], 'schedule' => $j['schedule'], 'command' => $j['command'], 'on' => $j['on'],
	           'kind' => $j['kind'], 'tracked' => $j['kind'] === 'reqad') + cron_command_type($j['command']);
	$s = cron_schedule($j['schedule']);
	$o['valid'] = $s !== false;
	$o['next'] = $j['on'] && $s !== false ? cron_next($j['schedule']) : null;
	$o['last'] = null;
	$o['fails'] = 0;
	if ($j['kind'] === 'reqad' && !empty($runs[$j['id']])) {
		$last = $runs[$j['id']][0];
		$o['last'] = $last + array('state' => cron_run_state($last));
		foreach ($runs[$j['id']] as $r) {
			if ($r['rc'] === 'skip' || $r['rc'] === null)
				continue;
			if ($r['rc'] === 0)
				break;
			$o['fails']++;
		}
	}
	return $o;
}

function cron_list($user) {
	if (($e = files_account_error($user)) !== '')
		return result_error($e);
	list($hash, $text) = cron_read($user);
	if ($hash === null)
		return result_error($text);
	list(, $jobs) = cron_parse($text);
	$runs = $jobs ? cron_runs_all($user) : array();
	$out = array();
	foreach ($jobs as $j)
		$out[] = cron_job_out($j, $runs);
	$now = new DateTime('now', cron_tz());
	return result_ok('', array('jobs' => $out, 'tz' => cron_tz()->getName(), 'tz_abbr' => $now->format('T'), 'now' => time(),
	                           'php' => array_column(php_versions(), 'version'), 'php_default' => cron_account_php($user), 'php_server' => (string)($GLOBALS['ini']['php'] ?? '')));
}

/* The PHP version of the account's main site */
function cron_account_php($user) {
	foreach (php_sites($user) as $s)
		if ($s['kind'] === 'main')
			return $s['version'];
	global $ini;
	return (string)($ini['php'] ?? '');
}

/* name, schedule and command (or type + file/php or url) checked: array(name, schedule, command, error) */
function cron_input($user, array $o, array $old = null) {
	$sched = array_key_exists('schedule', $o) ? (string)$o['schedule'] : ($old['schedule'] ?? '');
	if (strtolower(trim($sched)) === '@reboot')
		return array('', '', '', 'Jobs that run when the server starts are not available.');
	$s = cron_schedule($sched);
	if ($s === false)
		return array('', '', '', 'The schedule is not valid. It has five parts: minute, hour, day of the month, month and day of the week, such as */10 * * * *.');
	if (cron_next($s[0]) === null)
		return array('', '', '', 'That schedule never comes round (such as 30 February).');
	if ($old !== null && !array_key_exists('command', $o) && !isset($o['type']))
		$cmd = $old['command'];
	else {
		list($cmd, $e) = cron_command_build($user, $o);
		if ($e !== '')
			return array('', '', '', $e);
	}
	$name = array_key_exists('name', $o) ? trim((string)$o['name']) : ($old['name'] ?? '');
	if (!preg_match('/^[^\x00-\x1f\x7f]{0,80}$/u', $name))
		return array('', '', '', 'The name must be one line of at most 80 characters.');
	return array($name, $s[0], $cmd, '');
}

function cron_new_id(array $jobs) {
	$have = array_column($jobs, 'id');
	do
		$id = bin2hex(random_bytes(4));
	while (in_array($id, $have, true));
	return $id;
}

function cron_add($user, array $o) {
	if (($e = files_account_error($user)) !== '')
		return result_error($e);
	list($name, $sched, $cmd, $e) = cron_input($user, $o);
	if ($e !== '')
		return result_error($e);
	list($hash, $text) = cron_read($user);
	if ($hash === null)
		return result_error($text);
	list($lines, $jobs) = cron_parse($text);
	if (count($jobs) >= CRON_MAX)
		return result_error('This account has '.CRON_MAX.' cron jobs, the most it can have.');
	foreach ($jobs as $j)
		if ($j['command'] === $cmd && $j['schedule'] === $sched)
			return result_error('There is a job with this command and schedule already.');
	if (($le = limit_allows($user, 'cron', array('line' => $cmd))) !== '')
		return result_error($le);
	$id = cron_new_id($jobs);
	$on = !array_key_exists('on', $o) || !empty($o['on']);
	$lines = array_merge($lines, cron_job_lines($id, $name, $sched, $cmd, $on));
	if (($e = cron_write($user, $hash, implode("\n", $lines))) !== '')
		return result_error($e);
	plugin_hook('cron.created', array('account' => $user, 'id' => $id, 'name' => $name, 'schedule' => $sched, 'command' => $cmd));
	$j = array('id' => $id, 'name' => $name, 'schedule' => $sched, 'command' => $cmd, 'on' => $on, 'kind' => 'reqad');
	return result_ok('Cron job added.', cron_job_out($j, array()));
}

function cron_find(array $jobs, $id) {
	foreach ($jobs as $j)
		if ($j['id'] === $id)
			return $j;
	return null;
}

/* Change a job: any of name, schedule, command (or type ...), on. A line made
   elsewhere becomes a job of ours, in its place. */
function cron_edit($user, $id, array $o) {
	if (($e = files_account_error($user)) !== '')
		return result_error($e);
	list($hash, $text) = cron_read($user);
	if ($hash === null)
		return result_error($text);
	list($lines, $jobs) = cron_parse($text);
	$j = cron_find($jobs, (string)$id);
	if (!$j)
		return result_error('There is no such cron job. Reload the page.', array('code' => 'missing'));
	if ($j['kind'] === 'builtin')
		return result_error('This job belongs to the WordPress Toolkit; change it there.');
	list($name, $sched, $cmd, $e) = cron_input($user, $o, $j);
	if ($e !== '')
		return result_error($e);
	$on = array_key_exists('on', $o) ? (bool)$o['on'] : $j['on'];
	/* a paused job is not counted by a package's limit: turning it on is like adding it */
	if ($on && !$j['on'] && ($le = limit_allows($user, 'cron', array('line' => $cmd))) !== '')
		return result_error($le);
	$nid = $j['kind'] === 'reqad' ? $j['id'] : cron_new_id($jobs);
	list($nl, $jl) = cron_job_lines($nid, $name, $sched, $cmd, $on);
	$lines[$j['at']] = $jl;
	if ($j['name_at'] >= 0)
		$lines[$j['name_at']] = $nl;
	else
		array_splice($lines, $j['at'], 0, array($nl));
	if (($e = cron_write($user, $hash, implode("\n", $lines))) !== '')
		return result_error($e);
	plugin_hook('cron.updated', array('account' => $user, 'id' => $nid, 'name' => $name, 'schedule' => $sched, 'command' => $cmd, 'on' => $on));
	$msg = array_keys($o) === array('on') ? ($on ? 'Cron job turned on.' : 'Cron job paused.') : 'Cron job saved.';
	return result_ok($msg, cron_job_out(array('id' => $nid, 'name' => $name, 'schedule' => $sched, 'command' => $cmd, 'on' => $on, 'kind' => 'reqad'), array()));
}

function cron_delete($user, $id) {
	if (($e = files_account_error($user)) !== '')
		return result_error($e);
	list($hash, $text) = cron_read($user);
	if ($hash === null)
		return result_error($text);
	list($lines, $jobs) = cron_parse($text);
	$j = cron_find($jobs, (string)$id);
	if (!$j)
		return result_error('There is no such cron job. Reload the page.', array('code' => 'missing'));
	if ($j['kind'] === 'builtin')
		return result_error('This job belongs to the WordPress Toolkit; turn it off there.');
	unset($lines[$j['at']]);
	if ($j['name_at'] >= 0)
		unset($lines[$j['name_at']]);
	if (($e = cron_write($user, $hash, implode("\n", $lines))) !== '')
		return result_error($e);
	if ($j['kind'] === 'reqad')
		files_delete($user, array('.reqad/cron/'.$j['id']));
	plugin_hook('cron.deleted', array('account' => $user, 'id' => $j['id'], 'schedule' => $j['schedule'], 'command' => $j['command']));
	return result_ok('Cron job removed.');
}

function cron_run_now($user, $id) {
	if (($e = files_account_error($user)) !== '')
		return result_error($e);
	if (!preg_match('/^[a-z0-9]{8}$/', (string)$id) || $id[0] === 'x')
		return result_error('Only jobs saved here can be run from here. Save it once (Edit, Save) first.');
	list($rc, , $err) = cron_helper(array('run', $user, $id));
	if ($rc === 3)
		return result_error('There is no such cron job. Reload the page.', array('code' => 'missing'));
	if ($rc === 6)
		return result_error('This job is running already.');
	if ($rc !== 0)
		return result_error('The job could not be started'.($err !== '' ? " ($err)." : '.'));
	return result_ok('The job has started. Its result shows here when it ends.', array('id' => $id, 'started' => time()));
}

/* The newest runs of a job (20 at most) */
function cron_runs($user, $id) {
	if (($e = files_account_error($user)) !== '')
		return result_error($e);
	if (!preg_match('/^[a-z0-9]{8}$/', (string)$id))
		return result_error('There is no such cron job.');
	$all = cron_runs_all($user);
	$out = array();
	foreach (array_slice($all[$id] ?? array(), 0, 20) as $r)
		$out[] = $r + array('state' => cron_run_state($r));
	return result_ok('', array('id' => $id, 'runs' => $out));
}

/* What a run printed (first 64 KB), or null when it is not kept */
function cron_run_output($user, $id, $ms) {
	if (($e = files_account_error($user)) !== '')
		return result_error($e);
	if (!preg_match('/^[a-z0-9]{8}$/', (string)$id) || !preg_match('/^[0-9]{10,16}$/', (string)$ms))
		return result_error('There is no such run.');
	$a = files_run($user, array('verb' => 'cronruns', 'id' => (string)$id, 'start' => (string)$ms), null, null, 20);
	if (empty($a['ok']))
		return result_error('The output could not be read.');
	return result_ok('', array('id' => $id, 'ms' => (int)$ms, 'output' => $a['output']));
}
