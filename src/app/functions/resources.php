<?php
/*
 * Resource usage: CPU, memory and processes of each hosting account, its
 * sites' PHP workers and its database connections, with the limits it
 * reached (2083 Metrics > Resource usage, 2087 Accounts > Resource Usage,
 * GET /v1/resources, `reqad resources`).
 *
 * Collected by `reqad resources collect` (cron, every minute, root): one pass
 * over /proc for the processes of every account (PSS from smaps_rollup, CPU =
 * utime + stime since the last check, keyed by pid + start time). What an
 * account runs is sorted into kinds:
 *   ses          SSH, cron and the terminal: the user-<uid>.slice. Its CPU,
 *                memory and tasks come from the slice's own cgroup counters
 *                (exact, also for processes that ended between two checks;
 *                the numbers its limits are enforced on).
 *   php:<pool>   a site's PHP-FPM workers ("php-fpm: pool <domain>")
 *   mail         IMAP / POP3 logins (dovecot runs them as the account)
 *   oth          anything else of the account: background jobs (WordPress
 *                updates, deployments, restores), apps
 *   db:<user>    connections open per database user (PROCESSLIST)
 * A site on PHP inside Apache (mod_php) runs as the web server, not the
 * account, and cannot be counted here. A process that starts and ends
 * between two checks is missed (except in the slice).
 *
 * Points (res_points): per account, kind and bucket, the sum of the checks
 * (avg = sum / the bucket's number of checks, kind 'tot') and the highest
 * check, with its time for 'tot'. 5-minute buckets kept 8 days, hourly ones
 * 90 days (the 30 days view adds 4 of them up). User '-' = the whole server.
 *
 * Limits reached (res_events, kept 90 days; the same thing again within 15
 * minutes adds to the last row):
 *   php_full  php-fpm: "[pool <domain>] server reached max_children"
 *   timeout   php-fpm: "... execution timed out", with the script
 *   db_full   a database user at its connection limit at a check
 *   mem_kill  the session memory limit stopped a process (session-limits.log)
 *   tasks     a new process was refused at the session process limit
 *   cpu_held  the slice was held at its CPU limit (throttled time, minutes)
 *
 * Database: its own SQLite file RES_DB (root:reqad 0640, beside the visitor
 * stats) so the writes every minute never lock the panel's reqad.db.
 * "Running now" (resources_processes) is read live when asked, with CPU over
 * the time since the last check. Stop (resources_stop) is sent AS the account
 * (fm-helper verb kill: pid + start time), so it can reach nothing else.
 */

if (!defined('RES_DB')) define('RES_DB', '/var/lib/reqad/stats/usage.db');   // another file only for tests
define('RES_KEEP_5M', 8 * 86400);
define('RES_KEEP_1H', 90 * 86400);
define('RES_HZ', 100);                 // USER_HZ: /proc's clock ticks on Linux

/* The usage database; null when it cannot be opened. Root makes it and its
   tables; everyone else opens it read-only (the panel runs as reqad). */
function resources_db() {
	static $rdb = null, $tried = false;
	if ($tried)
		return $rdb;
	$tried = true;
	$root = function_exists('posix_geteuid') && posix_geteuid() === 0;
	if (!$root) {
		if (!is_readable(RES_DB))
			return null;
		try {
			$rdb = new SQLite3(RES_DB, SQLITE3_OPEN_READONLY);
			$rdb->busyTimeout(5000);
		} catch (Exception $e) { $rdb = null; }
		return $rdb;
	}
	$dir = dirname(RES_DB);
	if (!is_dir($dir)) {
		@mkdir($dir, 0750, true);
		@chgrp($dir, 'reqad');
	}
	$new = !is_file(RES_DB);
	try {
		$rdb = new SQLite3(RES_DB);
	} catch (Exception $e) { return $rdb = null; }
	$rdb->busyTimeout(15000);
	if ($new) {
		@chmod(RES_DB, 0640);
		@chgrp(RES_DB, 'reqad');
	}
	/* no WAL: the panel (reqad) opens the file read-only, and a read-only
	   WAL reader cannot start without a writer's -shm file */
	if ((string)$rdb->querySingle('PRAGMA journal_mode') === 'wal')
		$rdb->exec('PRAGMA journal_mode = DELETE');
	$rdb->exec("CREATE TABLE IF NOT EXISTS res_points (
		user TEXT NOT NULL, res INTEGER NOT NULL, t INTEGER NOT NULL, kind TEXT NOT NULL,
		n INTEGER NOT NULL DEFAULT 0,
		cpu REAL NOT NULL DEFAULT 0, cpu_max REAL NOT NULL DEFAULT 0, at_cpu INTEGER NOT NULL DEFAULT 0,
		mem REAL NOT NULL DEFAULT 0, mem_max REAL NOT NULL DEFAULT 0, at_mem INTEGER NOT NULL DEFAULT 0,
		pr REAL NOT NULL DEFAULT 0, pr_max REAL NOT NULL DEFAULT 0, at_pr INTEGER NOT NULL DEFAULT 0,
		PRIMARY KEY (user, res, t, kind)) WITHOUT ROWID");
	$rdb->exec("CREATE TABLE IF NOT EXISTS res_events (id INTEGER PRIMARY KEY, user TEXT NOT NULL, kind TEXT NOT NULL,
		subject TEXT NOT NULL DEFAULT '', first INTEGER NOT NULL, last INTEGER NOT NULL, n INTEGER NOT NULL DEFAULT 1,
		detail TEXT NOT NULL DEFAULT '')");
	$rdb->exec('CREATE INDEX IF NOT EXISTS res_events_user ON res_events (user, last)');
	$rdb->exec('CREATE TABLE IF NOT EXISTS res_meta (k TEXT PRIMARY KEY, v TEXT)');
	return $rdb;
}

function resources_meta_get($k, $default = '') {
	$rdb = resources_db();
	if (!$rdb) return $default;
	$st = $rdb->prepare('SELECT v FROM res_meta WHERE k = :k');
	$st->bindValue(':k', $k, SQLITE3_TEXT);
	$r = $st->execute();
	$row = $r ? $r->fetchArray(SQLITE3_NUM) : false;
	return $row ? (string)$row[0] : $default;
}

function resources_meta_put($k, $v) {
	$st = resources_db()->prepare('INSERT OR REPLACE INTO res_meta (k, v) VALUES (:k, :v)');
	$st->bindValue(':k', $k, SQLITE3_TEXT);
	$st->bindValue(':v', (string)$v, SQLITE3_TEXT);
	$st->execute();
}

/* uid => account of every hosting account */
function resources_accounts() {
	global $db;
	$out = array();
	$r = $db->query('SELECT user FROM accounts ORDER BY user');
	while ($r && ($row = $r->fetchArray(SQLITE3_ASSOC))) {
		$pw = function_exists('posix_getpwnam') ? posix_getpwnam($row['user']) : false;
		if ($pw && (int)$pw['uid'] >= 1000)
			$out[(int)$pw['uid']] = $row['user'];
	}
	return $out;
}

function resources_cgroup2() {
	static $v2 = null;
	if ($v2 === null)
		$v2 = is_file('/sys/fs/cgroup/cgroup.controllers');
	return $v2;
}

function resources_cores() {
	static $n = null;
	if ($n === null) {
		$n = 0;
		foreach (@file('/proc/cpuinfo') ?: array() as $l)
			if (strncmp($l, 'processor', 9) === 0) $n++;
		$n = max(1, $n);
	}
	return $n;
}

/* MB of RAM, and MB in use (total - available) */
function resources_ram() {
	$m = array();
	foreach (@file('/proc/meminfo') ?: array() as $l)
		if (preg_match('/^(MemTotal|MemAvailable):\s+(\d+)/', $l, $x))
			$m[$x[1]] = (int)$x[2];
	$tot = (int)round(($m['MemTotal'] ?? 0) / 1024);
	return array($tot, max(0, $tot - (int)round(($m['MemAvailable'] ?? 0) / 1024)));
}

/* The counters of an account's user slice (SSH, cron, terminal): cpu ns used,
   ns held at the CPU limit, memory bytes (anon + shmem, what the limit
   keeps), tasks. null while the account has no session. */
function resources_slice($uid) {
	$uid = (int)$uid;
	if (resources_cgroup2()) {
		$b = "/sys/fs/cgroup/user.slice/user-$uid.slice";
		if (!is_dir($b)) return null;
		$c = array('cpu' => 0, 'thr' => 0, 'mem' => 0, 'tasks' => (int)@file_get_contents("$b/pids.current"));
		foreach (@file("$b/cpu.stat") ?: array() as $l) {
			$f = explode(' ', trim($l));
			if ($f[0] === 'usage_usec') $c['cpu'] = (int)$f[1] * 1000;
			if ($f[0] === 'throttled_usec') $c['thr'] = (int)$f[1] * 1000;
		}
		foreach (@file("$b/memory.stat") ?: array() as $l) {
			$f = explode(' ', trim($l));
			if ($f[0] === 'anon' || $f[0] === 'shmem') $c['mem'] += (int)$f[1];
		}
		return $c;
	}
	$m = "/sys/fs/cgroup/memory/user.slice/user-$uid.slice";
	if (!is_dir($m)) return null;
	$cpu = "/sys/fs/cgroup/cpu,cpuacct/user.slice/user-$uid.slice";
	$c = array('cpu' => (int)@file_get_contents("$cpu/cpuacct.usage"), 'thr' => 0, 'mem' => 0,
	           'tasks' => (int)@file_get_contents("/sys/fs/cgroup/pids/user.slice/user-$uid.slice/pids.current"));
	foreach (@file("$cpu/cpu.stat") ?: array() as $l) {
		$f = explode(' ', trim($l));
		if ($f[0] === 'throttled_time') $c['thr'] = (int)$f[1];
	}
	foreach (@file("$m/memory.stat") ?: array() as $l) {
		$f = explode(' ', trim($l));
		if ($f[0] === 'total_rss' || $f[0] === 'total_shmem') $c['mem'] += (int)$f[1];
	}
	return $c;
}

/*
 * The processes of these accounts (uid => user): pid, uid, start (clock ticks
 * after boot), ticks (utime + stime), mem (PSS MB), threads, cmd, kind, app
 * (id of a reqad-app unit). Also counts every process of the server ($all).
 */
function resources_scan(array $uids, &$all = 0) {
	$out = array();
	$all = 0;
	$dh = @opendir('/proc');
	if (!$dh) return $out;
	while (($e = readdir($dh)) !== false) {
		if (!ctype_digit($e))
			continue;
		$all++;
		$uid = @fileowner("/proc/$e");
		if ($uid === false || !isset($uids[$uid]))
			continue;
		$st = @file_get_contents("/proc/$e/stat");
		if (!$st || ($p = strrpos($st, ')')) === false)
			continue;
		$f = explode(' ', substr($st, $p + 2));   // f[0] = state (field 3)
		if (($f[0] ?? '') === 'Z')
			continue;
		$comm = substr($st, strpos($st, '(') + 1, $p - strpos($st, '(') - 1);
		$cmd = trim(str_replace("\0", ' ', (string)@file_get_contents("/proc/$e/cmdline", false, null, 0, 4096)));
		if ($cmd === '')
			$cmd = '['.$comm.']';
		$cg = (string)@file_get_contents("/proc/$e/cgroup");
		$pss = 0;
		foreach (@file("/proc/$e/smaps_rollup") ?: array() as $l)
			if (strncmp($l, 'Pss:', 4) === 0) { $pss = (int)preg_replace('/\D/', '', $l); break; }
		$kind = 'oth';
		$app = 0;
		if (strpos($cg, "/user.slice/user-$uid.slice") !== false)
			$kind = 'ses';
		elseif (preg_match('/^php-fpm: pool (\S+)/', $cmd, $m))
			$kind = 'php:'.strtolower($m[1]);
		elseif (preg_match('#^dovecot/(imap|pop3|imap-hibernate|lmtp|managesieve)#', $cmd) || in_array($comm, array('imap', 'pop3'), true))
			$kind = 'mail';
		if (preg_match('#/reqad-app-(\d+)\.service#', $cg, $m))
			$app = (int)$m[1];
		$out[] = array('pid' => (int)$e, 'uid' => $uid, 'start' => (int)($f[19] ?? 0), 'ticks' => (int)($f[11] ?? 0) + (int)($f[12] ?? 0),
		               'threads' => (int)($f[17] ?? 1), 'mem' => round($pss / 1024, 1), 'cmd' => $cmd, 'kind' => $kind, 'app' => $app);
	}
	closedir($dh);
	return $out;
}

/* Seconds since boot */
function resources_uptime() {
	return (float)explode(' ', (string)@file_get_contents('/proc/uptime'))[0];
}

/* busy and total jiffies of the whole server */
function resources_cpu_jiffies() {
	$l = explode("\n", (string)@file_get_contents('/proc/stat'), 2)[0];
	$f = array_map('intval', preg_split('/\s+/', trim(substr($l, 3))));
	$tot = array_sum(array_slice($f, 0, 8));           // without guest (already in user)
	$idle = ($f[3] ?? 0) + ($f[4] ?? 0);
	return array($tot - $idle, $tot);
}

/* Database user => account. Names with the "<account>_" prefix, others from
   database_account_users() (grants), read again once an hour. */
function resources_db_users(array $accounts, array $conn) {
	$map = json_decode(resources_meta_get('dbmap', ''), true);
	if (!is_array($map) || (int)($map['t'] ?? 0) < time() - 3600) {
		/* database_account_users()'s rule, for every account in one pass */
		$assigned = database_assignments();
		$names = database_account_names();
		$grants = array();
		foreach (mysql_rows('SELECT DISTINCT User, Db FROM mysql.db') as $line) {
			list($dbu, $d) = array_pad(explode("\t", $line), 2, '');
			$grants[$dbu][database_owner(database_grant_name($d), $assigned, $names)] = true;
		}
		$m = array();
		foreach ($grants as $dbu => $owners)
			if (count($owners) === 1 && in_array(key($owners), $accounts, true))
				$m[$dbu] = key($owners);
		$map = array('t' => time(), 'm' => $m);
		resources_meta_put('dbmap', json_encode($map));
	}
	$out = array();
	foreach (array_keys($conn) as $dbu) {
		if (strpos($dbu, 'rqpma-') === 0)
			continue;
		if (isset($map['m'][$dbu])) { $out[$dbu] = $map['m'][$dbu]; continue; }
		foreach ($accounts as $u)
			if (strpos($dbu, $u.'_') === 0) { $out[$dbu] = $u; break; }
	}
	return $out;
}

/* Add a limit reached; the same one within 15 minutes adds to the last row. */
function resources_event($rdb, $user, $kind, $subject, $t, $n = 1, array $detail = array()) {
	$st = $rdb->prepare('SELECT id, detail FROM res_events WHERE user = :u AND kind = :k AND subject = :s AND last >= :t ORDER BY last DESC LIMIT 1');
	$st->bindValue(':u', $user, SQLITE3_TEXT);
	$st->bindValue(':k', $kind, SQLITE3_TEXT);
	$st->bindValue(':s', (string)$subject, SQLITE3_TEXT);
	$st->bindValue(':t', (int)$t - 900, SQLITE3_INTEGER);
	$r = $st->execute();
	$row = $r ? $r->fetchArray(SQLITE3_ASSOC) : false;
	if ($row) {
		$old = json_decode($row['detail'], true) ?: array();
		$st = $rdb->prepare('UPDATE res_events SET last = MAX(last, :t), n = n + :n, detail = :d WHERE id = :id');
		$st->bindValue(':id', (int)$row['id'], SQLITE3_INTEGER);
		$detail = $detail + $old;
		if (isset($old['max'], $detail['max'])) $detail['max'] = max($old['max'], $detail['max']);
	} else {
		$st = $rdb->prepare('INSERT INTO res_events (user, kind, subject, first, last, n, detail) VALUES (:u, :k, :s, :t, :t, :n, :d)');
		$st->bindValue(':u', $user, SQLITE3_TEXT);
		$st->bindValue(':k', $kind, SQLITE3_TEXT);
		$st->bindValue(':s', (string)$subject, SQLITE3_TEXT);
	}
	$st->bindValue(':t', (int)$t, SQLITE3_INTEGER);
	$st->bindValue(':n', max(1, (int)$n), SQLITE3_INTEGER);
	$st->bindValue(':d', $detail ? json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : '', SQLITE3_TEXT);
	$st->execute();
}

/* The logs that tell about limits: PHP-FPM's error logs and the session hit log. */
function resources_log_files() {
	$f = array_merge(glob('/var/log/php-fpm/*error.log') ?: array(), glob('/var/opt/remi/php*/log/php-fpm/error.log') ?: array());
	$f[] = '/var/log/reqad/session-limits.log';
	return array_values(array_unique(array_filter($f, 'is_file')));
}

/* What a log got since the last check, line by line (at most 4 MB a run).
   A log seen for the first time starts at its end; after a rotation the rest
   of the old file (<log>-<date> with the old inode) is read first. */
function resources_log_lines($path, array &$state, callable $each) {
	clearstatcache(true, $path);
	$st = @stat($path);
	if (!$st) return;
	$old = $state[$path] ?? null;
	$state[$path] = array((int)$st['ino'], (int)$st['size']);
	if (!$old)
		return;
	$read = function ($file, $from) use ($each) {
		$fh = @fopen($file, 'r');
		if (!$fh) return 0;
		fseek($fh, $from);
		$got = 0;
		while ($got < 4194304 && ($l = fgets($fh, 65536)) !== false) {
			$got += strlen($l);
			if (substr($l, -1) !== "\n") { $got -= strlen($l); break; }   // half a line: next time
			$each(rtrim($l, "\n"));
		}
		$pos = $from + $got;
		fclose($fh);
		return $pos;
	};
	$from = 0;
	if ((int)$old[0] === (int)$st['ino']) {
		$from = (int)$old[1] <= $st['size'] ? (int)$old[1] : 0;
	} else {
		foreach (glob($path.'-*') ?: array() as $rf)
			if (substr($rf, -3) !== '.gz' && ($rs = @stat($rf)) && (int)$rs['ino'] === (int)$old[0]) {
				$read($rf, (int)$old[1]);
				break;
			}
	}
	$state[$path] = array((int)$st['ino'], $read($path, $from));
}

/* Limits reached in the logs since the last check */
function resources_scan_logs($rdb, array &$state, array $uids, array $sites) {
	$n = 0;
	foreach (resources_log_files() as $path) {
		$session = basename($path) === 'session-limits.log';
		resources_log_lines($path, $state, function ($l) use ($rdb, $uids, $sites, $session, &$n) {
			if ($session) {
				$t = strtotime(substr($l, 0, strpos($l, ' ') ?: 0)) ?: time();
				if (strpos($l, 'Memory cgroup out of memory: Killed process') !== false && preg_match('/UID:(\d+)/', $l, $u) && isset($uids[(int)$u[1]])) {
					$d = array();
					if (preg_match('/Killed process (\d+) \(([^)]*)\)/', $l, $m)) { $d['pid'] = (int)$m[1]; $d['proc'] = $m[2]; }
					if (preg_match('/anon-rss:(\d+)kB/', $l, $m)) $d['mb'] = (int)round($m[1] / 1024);
					resources_event($rdb, $uids[(int)$u[1]], 'mem_kill', '', $t, 1, $d);
					$n++;
				} elseif (preg_match('#fork rejected by pids controller in /user\.slice/user-(\d+)\.slice#', $l, $u) && isset($uids[(int)$u[1]])) {
					resources_event($rdb, $uids[(int)$u[1]], 'tasks', '', $t, 1);
					$n++;
				}
				return;
			}
			if (!preg_match('/^\[([^\]]+)\] WARNING: \[pool ([^\]]+)\] (.*)$/', $l, $m))
				return;
			$pool = strtolower($m[2]);
			$user = $sites[$pool] ?? '';
			if ($user === '')
				return;
			$t = strtotime($m[1]) ?: time();
			if (preg_match('/^server reached (?:pm\.)?max_children setting \((\d+)\)/', $m[3], $x)) {
				resources_event($rdb, $user, 'php_full', $pool, $t, 1, array('max' => (int)$x[1]));
				$n++;
			} elseif (preg_match('/^child (\d+), script \'([^\']*)\' \(request: "([^"]*)"\) execution timed out \(([\d.]+) sec\)/', $m[3], $x)) {
				$req = $x[3];
				if (($q = strpos($req, '?')) !== false) $req = substr($req, 0, $q).'?…';
				resources_event($rdb, $user, 'timeout', $pool, $t, 1, array('script' => sitelog_short_path($x[2], $user),
					'request' => mb_substr($req, 0, 200), 'secs' => (int)round((float)$x[4])));
				$n++;
			}
		});
	}
	return $n;
}

/* Add one check to the 5-minute and the hourly buckets. $v: kind =>
   array(cpu %, mem MB, processes). */
function resources_points_add($rdb, $user, $now, array $v) {
	static $st = null;
	if ($st === null)
		$st = $rdb->prepare('INSERT INTO res_points (user, res, t, kind, n, cpu, cpu_max, at_cpu, mem, mem_max, at_mem, pr, pr_max, at_pr)
			VALUES (:u, :r, :t, :k, 1, :c, :c, :now, :m, :m, :now, :p, :p, :now)
			ON CONFLICT (user, res, t, kind) DO UPDATE SET n = n + 1,
			cpu = cpu + excluded.cpu, cpu_max = MAX(cpu_max, excluded.cpu), at_cpu = CASE WHEN excluded.cpu > cpu_max THEN excluded.at_cpu ELSE at_cpu END,
			mem = mem + excluded.mem, mem_max = MAX(mem_max, excluded.mem), at_mem = CASE WHEN excluded.mem > mem_max THEN excluded.at_mem ELSE at_mem END,
			pr = pr + excluded.pr, pr_max = MAX(pr_max, excluded.pr), at_pr = CASE WHEN excluded.pr > pr_max THEN excluded.at_pr ELSE at_pr END');
	foreach (array(300, 3600) as $res)
		foreach ($v as $kind => $x) {
			$st->reset();
			$st->bindValue(':u', $user, SQLITE3_TEXT);
			$st->bindValue(':r', $res, SQLITE3_INTEGER);
			$st->bindValue(':t', intdiv($now, $res) * $res, SQLITE3_INTEGER);
			$st->bindValue(':k', $kind, SQLITE3_TEXT);
			$st->bindValue(':c', round($x[0], 2), SQLITE3_FLOAT);
			$st->bindValue(':m', round($x[1], 1), SQLITE3_FLOAT);
			$st->bindValue(':p', round($x[2], 1), SQLITE3_FLOAT);
			$st->bindValue(':now', $now, SQLITE3_INTEGER);
			$st->execute();
		}
}

/*
 * One check of every account (cron, every minute). Returns accounts,
 * processes, events and seconds.
 */
function resources_collect() {
	$t0 = microtime(true);
	$lock = @fopen('/run/reqad-resources.lock', 'c');
	if (!$lock || !flock($lock, LOCK_EX | LOCK_NB))
		return result_error('Another check is still running.', array('busy' => true));
	$rdb = resources_db();
	if (!$rdb)
		return result_error('Could not open '.RES_DB.'.');
	$now = time();
	$state = json_decode(resources_meta_get('state', ''), true);
	if (!is_array($state)) $state = array();
	$prev_t = (int)($state['t'] ?? 0);
	$dt = $now - $prev_t;
	$cpu_ok = $prev_t > 0 && $dt >= 20 && $dt <= 900;   // CPU needs a check not too long ago
	$dt = max(1, $dt);
	$prev = $state['p'] ?? array();
	$cg_prev = $state['cg'] ?? array();

	$uids = resources_accounts();
	$all = 0;
	$procs = resources_scan($uids, $all);
	$v = array();             // user => kind => [cpu, mem, pr]
	$p_state = array();
	foreach ($procs as $p) {
		$key = $p['pid'].':'.$p['start'];
		$p_state[$key] = $p['ticks'];
		$cpu = 0.0;
		if ($cpu_ok) {
			$was = $prev[$key] ?? 0;   // new since the last check: all of its time is new
			$cpu = max(0, $p['ticks'] - $was) / RES_HZ / $dt * 100;
		}
		$u = $uids[$p['uid']];
		$x = &$v[$u][$p['kind']];
		if ($x === null) $x = array(0.0, 0.0, 0);
		$x[0] += $cpu; $x[1] += $p['mem']; $x[2]++;
		unset($x);
	}
	/* the slice's own counters for SSH, cron and the terminal */
	$cg_state = array();
	$held = array();
	foreach ($uids as $uid => $u) {
		$c = resources_slice($uid);
		if (!$c) {
			unset($v[$u]['ses']);
			continue;
		}
		$cg_state[$uid] = array($c['cpu'], $c['thr']);
		$was = $cg_prev[$uid] ?? null;
		$cpu = 0.0;
		if ($cpu_ok) {
			$d = $was && $c['cpu'] >= $was[0] ? $c['cpu'] - $was[0] : ($was ? $c['cpu'] : 0);
			$cpu = min(resources_cores() * 100, $d / 1e9 / $dt * 100);
			if ($was && $c['thr'] > $was[1])
				$held[$u] = true;
		}
		if ($c['tasks'] > 0 || $c['mem'] > 0)
			$v[$u]['ses'] = array($cpu, $c['mem'] / 1048576, $c['tasks']);
	}
	/* database connections */
	$conn = function_exists('database_conn_now') ? database_conn_now() : array();
	$dbu = $conn ? resources_db_users(array_values($uids), $conn) : array();
	$db_full = array();
	foreach ($dbu as $name => $u) {
		$v[$u]['db:'.$name] = array(0, 0, $conn[$name]);
		$lim = database_conn_limit($u);
		if ($lim > 0 && $conn[$name] >= $lim)
			$db_full[] = array($u, $name, $lim);
	}

	$rdb->exec('BEGIN IMMEDIATE');
	$last = array();
	foreach ($uids as $u) {
		$k = $v[$u] ?? array();
		$tot = array(0.0, 0.0, 0);
		foreach ($k as $kind => $x)
			if (strncmp($kind, 'db:', 3) !== 0) { $tot[0] += $x[0]; $tot[1] += $x[1]; $tot[2] += $x[2]; }
		$k = array_filter($k, function ($x) { return $x[0] >= 0.05 || $x[1] >= 0.5 || $x[2] > 0; });
		$k['tot'] = $tot;
		resources_points_add($rdb, $u, $now, $k);
		$last[$u] = array_map(function ($x) { return array(round($x[0], 2), round($x[1], 1), $x[2]); }, $k);
	}
	/* the whole server */
	list($busy, $jt) = resources_cpu_jiffies();
	list($ram, $used) = resources_ram();
	$srv = $state['srv'] ?? null;
	$scpu = $cpu_ok && $srv && $jt > $srv[1] ? ($busy - $srv[0]) / ($jt - $srv[1]) * resources_cores() * 100 : 0;
	resources_points_add($rdb, '-', $now, array('tot' => array(max(0, $scpu), $used, $all)));
	$last['-'] = array('tot' => array(round(max(0, $scpu), 2), $used, $all));

	$ev = 0;
	foreach ($held as $u => $x) { resources_event($rdb, $u, 'cpu_held', '', $now, 1); $ev++; }
	foreach ($db_full as $x) { resources_event($rdb, $x[0], 'db_full', $x[1], $now, 1, array('limit' => $x[2])); $ev++; }
	$logs = $state['logs'] ?? array();
	$ev += resources_scan_logs($rdb, $logs, $uids, stats_sites());

	resources_meta_put('state', json_encode(array('t' => $now, 'up' => resources_uptime(), 'p' => $p_state, 'cg' => $cg_state,
		'srv' => array($busy, $jt), 'logs' => $logs), JSON_UNESCAPED_SLASHES));
	resources_meta_put('last', json_encode(array('t' => $now, 'cpu_ok' => $cpu_ok, 'v' => $last), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
	resources_meta_put('collected', (string)$now);
	resources_meta_put('cores', (string)resources_cores());
	resources_meta_put('ram', (string)$ram);
	$rdb->exec('COMMIT');

	if ((int)resources_meta_get('maintained', '0') < $now - 3600) {
		$rdb->exec('DELETE FROM res_points WHERE res = 300 AND t < '.($now - RES_KEEP_5M - 3600));
		$rdb->exec('DELETE FROM res_points WHERE res = 3600 AND t < '.($now - RES_KEEP_1H - 3600));
		$rdb->exec('DELETE FROM res_events WHERE last < '.($now - RES_KEEP_1H));
		/* accounts deleted since */
		$in = implode(',', array_map(function ($u) use ($rdb) { return "'".$rdb->escapeString($u)."'"; }, array_merge(array('-'), array_values($uids))));
		$rdb->exec("DELETE FROM res_points WHERE user NOT IN ($in)");
		$rdb->exec("DELETE FROM res_events WHERE user NOT IN ($in)");
		resources_meta_put('maintained', (string)$now);
	}
	flock($lock, LOCK_UN);
	$secs = round(microtime(true) - $t0, 3);
	return result_ok('Checked '.count($uids).' accounts, '.count($procs).' processes'.($ev ? ", $ev limit(s) reached" : '')." ($secs s).",
		array('accounts' => count($uids), 'processes' => count($procs), 'events' => $ev, 'seconds' => $secs));
}

/* ---- reading -------------------------------------------------------------- */

/* range => array(seconds per point, points, stored resolution) */
function resources_ranges() {
	return array('day' => array(300, 288, 300), 'week' => array(3600, 168, 3600), 'month' => array(14400, 180, 3600));
}

/* A pool file's PHP limits: workers (pm.max_children) and the time limit
   (request_terminate_timeout, seconds; 0 = none). */
function resources_pool_limits($file) {
	$o = array('max_children' => null, 'timeout' => null);
	foreach (@file($file) ?: array() as $l) {
		$l = trim($l);
		if ($l === '' || $l[0] === ';') continue;
		if ($o['max_children'] === null && preg_match('/^pm\.max_children\s*=\s*(\d+)/', $l, $m)) $o['max_children'] = (int)$m[1];
		if ($o['timeout'] === null && preg_match('/^request_terminate_timeout\s*=\s*(\d+)\s*(s|m|h)?/', $l, $m))
			$o['timeout'] = (int)$m[1] * (($m[2] ?? '') === 'm' ? 60 : (($m[2] ?? '') === 'h' ? 3600 : 1));
	}
	return $o;
}

/* The limits of an account: its sessions (the slice drop-in), each site's PHP,
   its database users, the hosting package. */
function resources_limits($user) {
	$out = array('cores' => resources_cores(), 'ram_mb' => resources_ram()[0], 'session' => null, 'sites' => array(),
	             'db_per_user' => database_conn_limit($user), 'package' => '');
	$pw = posix_getpwnam($user);
	if ($pw) {
		$f = '/etc/systemd/system/user-'.(int)$pw['uid'].'.slice.d/50-reqad-limits.conf';
		$c = is_file($f) ? (string)@file_get_contents($f) : '';
		$mode = preg_match('/mode: *([a-z]+)/', $c, $m) ? $m[1] : ($c === '' ? 'missing' : 'custom');
		$s = array('mode' => $mode, 'cpu' => null, 'memory_mb' => null, 'tasks' => null);
		if ($mode !== 'none' && $mode !== 'missing') {
			if (preg_match('/^CPUQuota=(\d+)%/m', $c, $m)) $s['cpu'] = (int)$m[1];
			if (preg_match('/^MemoryMax=(\d+)M/m', $c, $m) || preg_match('/^MemoryLimit=(\d+)M/m', $c, $m)) $s['memory_mb'] = (int)$m[1];
			if (preg_match('/^TasksMax=(\d+)$/m', $c, $m)) $s['tasks'] = (int)$m[1];
		}
		$out['session'] = $s;
	}
	foreach (php_sites($user) as $s) {
		$x = array('domain' => $s['domain'], 'handler' => $s['handler'], 'version' => $s['version'], 'max_children' => null, 'timeout' => null);
		if ($s['handler'] === 'fpm' && $s['pool'] !== '')
			$x = resources_pool_limits($s['pool']) + $x;
		$out['sites'][] = $x;
	}
	if (function_exists('hosting_enabled') && hosting_enabled() && function_exists('hosting_account_package') && ($pkg = hosting_account_package($user)))
		$out['package'] = (string)$pkg['name'];
	return $out;
}

function resources_kind_name($kind) {
	if ($kind === 'ses') return 'SSH, cron and terminal';
	if ($kind === 'mail') return 'Email';
	if ($kind === 'oth') return 'Background jobs';
	if ($kind === 'tot') return 'All';
	return substr($kind, strpos($kind, ':') + 1);
}

/* Rows of res_points for users in [from, to), at resolution $res */
function resources_rows(array $users, $res, $from, $to) {
	$rdb = resources_db();
	if (!$rdb || !$users) return array();
	$in = implode(',', array_map(function ($u) use ($rdb) { return "'".$rdb->escapeString($u)."'"; }, $users));
	$r = $rdb->query("SELECT * FROM res_points WHERE user IN ($in) AND res = ".(int)$res.' AND t >= '.(int)$from.' AND t < '.(int)$to.' ORDER BY t');
	$out = array();
	while ($r && ($row = $r->fetchArray(SQLITE3_ASSOC))) $out[] = $row;
	return $out;
}

function resources_events($user, $from, $limit = 200) {
	$rdb = resources_db();
	if (!$rdb) return array();
	$st = $rdb->prepare('SELECT kind, subject, first, last, n, detail FROM res_events WHERE user = :u AND last >= :f ORDER BY last DESC LIMIT '.(int)$limit);
	$st->bindValue(':u', $user, SQLITE3_TEXT);
	$st->bindValue(':f', (int)$from, SQLITE3_INTEGER);
	$r = $st->execute();
	$out = array();
	while ($r && ($row = $r->fetchArray(SQLITE3_ASSOC))) {
		$row['detail'] = $row['detail'] !== '' ? (json_decode($row['detail'], true) ?: (object)array()) : (object)array();
		foreach (array('first', 'last', 'n') as $k) $row[$k] = (int)$row[$k];
		$out[] = $row;
	}
	return $out;
}

/*
 * An account's usage over a range (day | week | month): one point per 5
 * minutes / hour / 4 hours. buckets: t (start), n (checks), k (kind =>
 * [cpu avg %, mem avg MB, processes avg, processes highest]), top (highest
 * check of all kinds together: [cpu, at, mem, at, processes, at]). Kinds
 * php:<site> are the sites' PHP (processes = workers), db:<user> the
 * connections of a database user.
 */
function resources_report($user, $range = 'day') {
	global $db;
	if (!valid_username($user) || !account_get($db, $user))
		return result_error("Account $user does not exist.");
	$ranges = resources_ranges();
	if (!isset($ranges[$range]))
		return result_error('range: day, week or month.');
	list($step, $count, $res) = $ranges[$range];
	$now = time();
	$end = intdiv($now, $step) * $step + $step;
	$from = $end - $count * $step;
	$B = array();
	$kinds = array();
	foreach (resources_rows(array($user), $res, $from, $end) as $row) {
		$i = intdiv((int)$row['t'] - $from, $step);
		if (!isset($B[$i])) $B[$i] = array('t' => $from + $i * $step, 'n' => 0, 'k' => array(), 'top' => array(0, 0, 0, 0, 0, 0));
		$b = &$B[$i];
		$k = $row['kind'];
		if ($k === 'tot') {
			$b['n'] += (int)$row['n'];
			foreach (array(array('cpu', 0), array('mem', 2), array('pr', 4)) as $m)
				if ((float)$row[$m[0].'_max'] > $b['top'][$m[1]]) { $b['top'][$m[1]] = (float)$row[$m[0].'_max']; $b['top'][$m[1] + 1] = (int)$row['at_'.$m[0]]; }
		} else {
			$kinds[$k] = true;
			$x = $b['k'][$k] ?? array(0, 0, 0, 0);
			$b['k'][$k] = array($x[0] + (float)$row['cpu'], $x[1] + (float)$row['mem'], $x[2] + (float)$row['pr'], max($x[3], (float)$row['pr_max']));
		}
		unset($b);
	}
	foreach ($B as &$b) {
		$n = max(1, $b['n']);
		foreach ($b['k'] as &$x)
			$x = array(round($x[0] / $n, 2), round($x[1] / $n, 1), round($x[2] / $n, 2), $x[3]);
		unset($x);
		$b['top'][0] = round($b['top'][0], 1);
	}
	unset($b);
	ksort($B);
	$limits = resources_limits($user);
	/* the series: sessions first (so its limit line compares), then each site, mail, the rest */
	$series = array('ses');
	foreach ($limits['sites'] as $s) $series[] = 'php:'.$s['domain'];
	foreach (array_keys($kinds) as $k) if (strncmp($k, 'php:', 4) === 0 && !in_array($k, $series, true)) $series[] = $k;
	$series[] = 'mail';
	$series[] = 'oth';
	$dbs = array();
	foreach (array_keys($kinds) as $k) if (strncmp($k, 'db:', 3) === 0) $dbs[] = $k;
	sort($dbs);
	$last = json_decode(resources_meta_get('last', ''), true);
	$nowv = is_array($last) && (int)$last['t'] >= $now - 180 ? ($last['v'][$user] ?? array('tot' => array(0, 0, 0))) : null;
	$collected = (int)resources_meta_get('collected', '0');
	return result_ok('', array(
		'account' => $user, 'range' => $range, 'step' => $step, 'from' => $from, 'to' => $end, 'now' => $now,
		'collected' => $collected ?: null, 'series' => array_map(function ($k) { return array('k' => $k, 'name' => resources_kind_name($k)); }, $series),
		'db_users' => array_map(function ($k) { return array('k' => $k, 'name' => substr($k, 3)); }, $dbs),
		'buckets' => array_values($B), 'current' => $nowv, 'current_cpu' => is_array($last) ? !empty($last['cpu_ok']) : false,
		'events' => resources_events($user, $from), 'limits' => $limits,
	));
}

/*
 * What the account runs now, grouped by kind: CPU over the time since the
 * last check (100% = one core), memory (PSS MB), how long it runs. "start"
 * goes back with a Stop, so a pid that was used again is never hit.
 */
function resources_processes($user) {
	global $db;
	if (!valid_username($user) || !account_get($db, $user))
		return result_error("Account $user does not exist.");
	$pw = posix_getpwnam($user);
	if (!$pw)
		return result_error("No system user $user.");
	$uid = (int)$pw['uid'];
	$state = json_decode(resources_meta_get('state', ''), true);
	$prev = is_array($state) ? ($state['p'] ?? array()) : array();
	$pt = is_array($state) ? (int)($state['t'] ?? 0) : 0;
	$now = microtime(true);
	$up = resources_uptime();
	$all = 0;
	$procs = resources_scan(array($uid => $user), $all);
	$groups = array();
	$sites = array_column(php_sites($user), 'domain');
	foreach (array_merge(array('ses'), array_map(function ($d) { return 'php:'.$d; }, $sites), array('mail', 'oth')) as $k)
		$groups[$k] = array('k' => $k, 'name' => resources_kind_name($k), 'cpu' => 0, 'mem' => 0, 'procs' => array());
	foreach ($procs as $p) {
		$age = max(1, $up - $p['start'] / RES_HZ);
		$key = $p['pid'].':'.$p['start'];
		if (isset($prev[$key]) && $pt > 0 && $now - $pt >= 5)
			$cpu = max(0, $p['ticks'] - $prev[$key]) / RES_HZ / ($now - $pt) * 100;
		else
			$cpu = $p['ticks'] / RES_HZ / $age * 100;          // started since: its whole life
		$k = $p['kind'];
		if (!isset($groups[$k]))
			$groups[$k] = array('k' => $k, 'name' => resources_kind_name($k), 'cpu' => 0, 'mem' => 0, 'procs' => array());
		$groups[$k]['cpu'] += $cpu;
		$groups[$k]['mem'] += $p['mem'];
		$groups[$k]['procs'][] = array('pid' => $p['pid'], 'start' => (string)$p['start'], 'cmd' => mb_substr($p['cmd'], 0, 300),
			'cpu' => round($cpu, 1), 'mem' => $p['mem'], 'age' => (int)round($age), 'threads' => $p['threads'], 'app' => $p['app'] ?: null);
	}
	foreach ($groups as &$g) {
		usort($g['procs'], function ($a, $b) { return $b['cpu'] <=> $a['cpu'] ?: $b['mem'] <=> $a['mem']; });
		$g['cpu'] = round($g['cpu'], 1);
		$g['mem'] = round($g['mem'], 1);
	}
	unset($g);
	$limits = resources_limits($user);
	return result_ok('', array('account' => $user, 'at' => (int)$now, 'since' => $pt ?: null, 'cores' => resources_cores(),
		'groups' => array_values($groups), 'sites' => $limits['sites']));
}

/* Stop a process of the account: TERM, then KILL after 5 seconds. Sent as the
   account (fm-helper verb kill), matched by pid + start time. */
function resources_stop($user, $pid, $start) {
	global $db;
	if (!valid_username($user) || !account_get($db, $user))
		return result_error("Account $user does not exist.");
	$pid = (int)$pid;
	$start = (string)$start;
	if ($pid < 2 || !ctype_digit($start))
		return result_error('Give the process number and its start time.');
	$pw = posix_getpwnam($user);
	if (!$pw || @fileowner("/proc/$pid") !== (int)$pw['uid'])
		return result_error("Process $pid is not running (or not this account's).", array('code' => 'gone'));
	$cmd = trim(str_replace("\0", ' ', (string)@file_get_contents("/proc/$pid/cmdline", false, null, 0, 200)));
	$r = files_run($user, array('verb' => 'kill', 'pid' => $pid, 'start' => $start), null, null, 30);
	if (empty($r['ok']))
		return result_error((string)($r['error'] ?? "Process $pid could not be stopped."), array('code' => $r['code'] ?? 'error'));
	route_log("resources: $user stopped process $pid".($cmd !== '' ? " ($cmd)" : '').', '.($r['how'] ?? ''));
	$how = (string)($r['how'] ?? '');
	return result_ok($how === 'gone' ? "Process $pid had already ended." : ($how === 'kill' ? "Process $pid was ended (it did not stop by itself within 5 seconds)." : "Process $pid stopped."),
		array('pid' => $pid, 'how' => $how));
}

/*
 * 2087 Resource Usage: every account over a range, busiest first: CPU and
 * memory (average and highest check), processes, PHP workers per site at the
 * most, limits reached by kind; and the whole server.
 */
function resources_usage($range = 'day') {
	global $db;
	$ranges = resources_ranges();
	if (!isset($ranges[$range])) $range = 'day';
	list($step, $count, $res) = $ranges[$range];
	$now = time();
	$end = intdiv($now, $step) * $step + $step;
	$from = $end - $count * $step;
	$acc = array();
	$r = $db->query('SELECT user, domain FROM accounts ORDER BY user');
	while ($r && ($row = $r->fetchArray(SQLITE3_ASSOC)))
		$acc[$row['user']] = array('user' => $row['user'], 'domain' => $row['domain'], 'package' => '', 'n' => 0,
			'cpu' => 0, 'cpu_max' => 0, 'at_cpu' => null, 'mem' => 0, 'mem_max' => 0, 'at_mem' => null, 'pr_max' => 0,
			'kinds' => array(), 'workers' => array(), 'events' => array());
	$srv = array('n' => 0, 'cpu' => 0, 'cpu_max' => 0, 'at_cpu' => null, 'mem' => 0, 'mem_max' => 0, 'at_mem' => null, 'pr_max' => 0);
	$rdb = resources_db();
	if ($rdb) {
		$q = $rdb->query('SELECT user, kind, SUM(n) n, SUM(cpu) cpu, MAX(cpu_max) cpu_max, SUM(mem) mem, MAX(mem_max) mem_max, SUM(pr) pr, MAX(pr_max) pr_max
			FROM res_points WHERE res = '.(int)$res.' AND t >= '.(int)$from.' GROUP BY user, kind');
		$sums = array();
		while ($q && ($row = $q->fetchArray(SQLITE3_ASSOC))) $sums[$row['user']][$row['kind']] = $row;
		/* when the highest check was */
		$q = $rdb->query('SELECT user, cpu_max, at_cpu, mem_max, at_mem FROM res_points WHERE kind = \'tot\' AND res = '.(int)$res.' AND t >= '.(int)$from);
		$at = array();
		while ($q && ($row = $q->fetchArray(SQLITE3_ASSOC))) {
			$a = &$at[$row['user']];
			if (!$a || $row['cpu_max'] > $a[0]) { $a[0] = (float)$row['cpu_max']; $a[1] = (int)$row['at_cpu']; }
			if (!isset($a[2]) || $row['mem_max'] > $a[2]) { $a[2] = (float)$row['mem_max']; $a[3] = (int)$row['at_mem']; }
			unset($a);
		}
		foreach ($sums as $u => $kinds) {
			$tot = $kinds['tot'] ?? null;
			if (!$tot) continue;
			$n = max(1, (int)$tot['n']);
			$x = array('n' => (int)$tot['n'], 'cpu' => round($tot['cpu'] / $n, 2), 'cpu_max' => round((float)$tot['cpu_max'], 1), 'at_cpu' => $at[$u][1] ?? null,
			           'mem' => round($tot['mem'] / $n, 1), 'mem_max' => round((float)$tot['mem_max'], 1), 'at_mem' => $at[$u][3] ?? null, 'pr_max' => (int)$tot['pr_max']);
			if ($u === '-') { $srv = $x; continue; }
			if (!isset($acc[$u])) continue;
			$acc[$u] = $x + $acc[$u];
			foreach ($kinds as $k => $row) {
				if ($k === 'tot') continue;
				if (strncmp($k, 'db:', 3) === 0) { $acc[$u]['db'][substr($k, 3)] = (int)$row['pr_max']; continue; }
				$acc[$u]['kinds'][$k] = array('name' => resources_kind_name($k), 'cpu' => round($row['cpu'] / $n, 2), 'mem' => round($row['mem'] / $n, 1),
				                              'cpu_max' => round((float)$row['cpu_max'], 1), 'mem_max' => round((float)$row['mem_max'], 1), 'pr_max' => (int)$row['pr_max']);
				if (strncmp($k, 'php:', 4) === 0) $acc[$u]['workers'][substr($k, 4)] = (int)$row['pr_max'];
			}
		}
		$q = $rdb->query('SELECT user, kind, COUNT(*) c, SUM(n) n FROM res_events WHERE last >= '.(int)$from.' GROUP BY user, kind');
		while ($q && ($row = $q->fetchArray(SQLITE3_ASSOC)))
			if (isset($acc[$row['user']])) $acc[$row['user']]['events'][$row['kind']] = array('times' => (int)$row['c'], 'n' => (int)$row['n']);
	}
	$hp = function_exists('hosting_enabled') && hosting_enabled() && function_exists('hosting_account_package');
	$accounts = 0;
	foreach ($acc as $u => &$a) {
		if ($hp && ($pkg = hosting_account_package($u))) $a['package'] = (string)$pkg['name'];
		$accounts += $a['cpu'];
	}
	unset($a);
	$list = array_values($acc);
	usort($list, function ($x, $y) { return $y['cpu'] <=> $x['cpu'] ?: $y['mem'] <=> $x['mem'] ?: strcmp($x['user'], $y['user']); });
	$collected = (int)resources_meta_get('collected', '0');
	list($ram) = resources_ram();
	return result_ok('', array('range' => $range, 'from' => $from, 'to' => $end, 'collected' => $collected ?: null,
		'cores' => resources_cores(), 'ram_mb' => $ram, 'server' => $srv, 'accounts' => $list,
		'accounts_cpu' => round($accounts, 2)));
}

/* Health: a site that ran out of PHP workers on 3 or more separate times in
   the last 24 hours, or a process the session memory limit stopped. */
function resources_attention() {
	$rdb = resources_db();
	if (!$rdb) return array();
	$out = array();
	$r = $rdb->query("SELECT user, kind, subject, COUNT(*) times, SUM(n) n, MAX(last) last FROM res_events
		WHERE last >= ".(time() - 86400)." AND kind IN ('php_full', 'mem_kill') GROUP BY user, kind, subject");
	while ($r && ($row = $r->fetchArray(SQLITE3_ASSOC)))
		if ($row['kind'] === 'mem_kill' || (int)$row['times'] >= 3)
			$out[] = $row;
	return $out;
}
