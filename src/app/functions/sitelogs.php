<?php
/*
 * A site's web server log, one day at a time (2083 Metrics > Logs,
 * GET /v1/logs/site/{domain}, `reqad logs site`): its requests (the access
 * lines, "access_log") and the web server's errors for it (the error lines in
 * the same file, "error_log"; nginx only: Apache writes them to its one shared
 * log).
 *
 * The files: the site's log (server_logs()['site']) and logrotate's daily
 * copies <log>-YYYYMMDD (.gz from the day after). A copy holds the lines up to
 * the rotation on its date, so a day's lines are in the first copy dated that
 * day or later and the one after it (or the live file). Root reads them (they
 * are nginx:adm 0640, outside any home); nothing is copied or kept, and every
 * read goes through the chosen day once: filters, search and paging run here,
 * so a busy day never travels to the browser.
 *
 * Errors are grouped by kind: sitelog_kinds() knows the common ones (a 2083
 * page says what they mean); others are grouped by their message with the
 * quoted values and numbers taken out.
 */

define('SITELOG_PAGE', 50);
define('SITELOG_MAX_ROWS', 10000);   // how far back "Show older" goes on one day
define('SITELOG_LINE_MAX', 2000);

/* The rotated copies of a site's log: Ymd => path, oldest first. */
function sitelog_rotated($path) {
	$out = array();
	foreach (glob($path.'-*') ?: array() as $f)
		if (preg_match('/-(\d{8})(\.gz)?$/', $f, $m) && is_file($f))
			$out[$m[1]] = $f;          // a day's .gz wins over a leftover plain copy
	ksort($out);
	return $out;
}

/* The days that can be read, newest first (Y-m-d). */
function sitelog_days($domain) {
	$path = stats_log_path($domain);
	$rot = sitelog_rotated($path);
	$today = date('Y-m-d');
	if ($rot) {
		$first = date('Y-m-d', strtotime(substr(array_key_first($rot), 0, 4).'-'.substr(array_key_first($rot), 4, 2).'-'.substr(array_key_first($rot), 6, 2).' -1 day'));
	} else {
		/* no copy yet: the live file's first line says since when */
		$first = $today;
		if (($fh = @fopen($path, 'r'))) {
			for ($i = 0; $i < 50 && ($l = fgets($fh, 8192)) !== false; $i++)
				if (($d = sitelog_line_day($l)) !== '') { $first = $d; break; }
			fclose($fh);
		}
	}
	$days = array();
	for ($t = strtotime($today); count($days) < 62 && date('Y-m-d', $t) >= $first; $t = strtotime('-1 day', $t))
		$days[] = date('Y-m-d', $t);
	return $days ?: array($today);
}

/* The day of a log line (access or error), '' for neither. */
function sitelog_line_day($l) {
	static $mon = array('Jan' => '01', 'Feb' => '02', 'Mar' => '03', 'Apr' => '04', 'May' => '05', 'Jun' => '06',
	                    'Jul' => '07', 'Aug' => '08', 'Sep' => '09', 'Oct' => '10', 'Nov' => '11', 'Dec' => '12');
	if (preg_match('#^\S+ \S+ \S+ \[(\d{2})/([A-Z][a-z]{2})/(\d{4}):#', $l, $m) && isset($mon[$m[2]]))
		return $m[3].'-'.$mon[$m[2]].'-'.$m[1];
	if (preg_match('#^(\d{4})/(\d{2})/(\d{2}) \d{2}:#', $l, $m))
		return $m[1].'-'.$m[2].'-'.$m[3];
	return '';
}

/* The files that hold $day's lines, oldest first: array(path, gz). */
function sitelog_day_files($domain, $day) {
	$path = stats_log_path($domain);
	$rot = sitelog_rotated($path);
	$want = str_replace('-', '', $day);
	$keys = array_keys($rot);
	$files = array();
	foreach ($keys as $i => $k) {
		if ($k < $want)
			continue;
		$files[] = $rot[$k];
		$files[] = isset($keys[$i + 1]) ? $rot[$keys[$i + 1]] : $path;
		break;
	}
	if (!$files)
		$files[] = $path;
	$out = array();
	foreach (array_unique($files) as $f)
		if (is_file($f))
			$out[] = array($f, substr($f, -3) === '.gz');
	return $out;
}

/* Calls $each($line) for every line of $day, oldest first. */
function sitelog_each($domain, $day, callable $each) {
	$acc = '['.substr($day, 8, 2).'/'.date('M', strtotime($day)).'/'.substr($day, 0, 4).':';
	$err = str_replace('-', '/', $day).' ';
	foreach (sitelog_day_files($domain, $day) as $f) {
		list($path, $gz) = $f;
		$fh = $gz ? @gzopen($path, 'r') : @fopen($path, 'r');
		if (!$fh)
			continue;
		while (($l = $gz ? gzgets($fh, 65536) : fgets($fh, 65536)) !== false) {
			if (strpos($l, $acc) === false && strncmp($l, $err, 11) !== 0)
				continue;
			if ($each(rtrim($l, "\r\n")) === false) {
				$gz ? gzclose($fh) : fclose($fh);
				return;
			}
		}
		$gz ? gzclose($fh) : fclose($fh);
	}
}

/* An access line => its fields, or null. Lines that are not "METHOD /path"
   (TLS or SSH on port 80, junk) keep the request text as their path. */
function sitelog_parse_access($l) {
	if (!preg_match('#^(\S+) \S+ \S+ \[\d{2}/[A-Z][a-z]{2}/\d{4}:(\d{2}:\d{2}:\d{2}) [^\]]*\] "((?:[^"\\\\]|\\\\.)*)" (\d{3}) (\d+|-)(?: "((?:[^"\\\\]|\\\\.)*)" "((?:[^"\\\\]|\\\\.)*)")?#', $l, $m))
		return null;
	$req = $m[3];
	$method = ''; $path = $req; $query = '';
	if (preg_match('#^([A-Z]{3,10}) (\S+)(?: HTTP/[\d.]+)?$#', $req, $r)) {
		$method = $r[1];
		$path = $r[2];
		if (($q = strpos($path, '?')) !== false) { $query = substr($path, $q); $path = substr($path, 0, $q); }
	}
	return array('t' => $m[2], 'ip' => $m[1], 'm' => $method, 'p' => $path, 'q' => $query, 's' => (int)$m[4],
	             'b' => $m[5] === '-' ? 0 : (int)$m[5], 'ref' => ($m[6] ?? '-') === '-' ? '' : $m[6], 'ua' => ($m[7] ?? '-') === '-' ? '' : $m[7]);
}

/* An nginx error line => its fields, or null. */
function sitelog_parse_error($l) {
	if (!preg_match('#^\d{4}/\d{2}/\d{2} (\d{2}:\d{2}:\d{2}) \[([a-z]+)\] \d+\#\d+: (?:\*\d+ )?(.*)$#', $l, $m))
		return null;
	$rest = $m[3];
	$msg = $rest;
	if (($p = strpos($rest, ', client: ')) !== false)
		$msg = substr($rest, 0, $p);
	$client = preg_match('/, client: ([^,\s]+)/', $rest, $c) ? $c[1] : '';
	$req = preg_match('/, request: "((?:[^"\\\\]|\\\\.)*)"/', $rest, $r) ? $r[1] : '';
	return array('t' => $m[1], 'lv' => $m[2], 'msg' => $msg, 'client' => $client, 'req' => $req);
}

/*
 * The kinds of error a page can explain: id => array(pattern, level, what
 * the list under it shows: 'path' (the pattern's first group) or 'req' (the
 * address asked for)). The first match wins.
 */
function sitelog_kinds() {
	return array(
		'forbidden_rule' => array('/^access forbidden by rule/', 'info', 'req'),
		'perm'           => array('/^open(?:at)?\(\) "([^"]+)" failed \(13: Permission denied\)/', 'warn', 'path'),
		'symlink'        => array('/^open(?:at)?\(\) "([^"]+)" failed \(40: Too many levels of symbolic links\)/', 'info', 'path'),
		'not_found'      => array('/^open(?:at)?\(\) "([^"]+)" failed \(2: No such file or directory\)/', 'info', 'path'),
		'dir_index'      => array('/^directory index of "([^"]+)" is forbidden/', 'info', 'path'),
		'php_busy'       => array('/^connect\(\) to unix:\S+ failed \(11: Resource temporarily unavailable\)/', 'warn', 'req'),
		'down'           => array('/^connect\(\)(?: to \S+)? failed \((2|111): [^)]*\) while connecting to upstream/', 'crit', 'req'),
		'timeout'        => array('/^(upstream timed out \(110|connect\(\)(?: to \S+)? failed \(110: )/', 'warn', 'req'),
		'reset'          => array('/^(recv\(\) failed \(104: Connection reset by peer\)|upstream prematurely closed connection) while reading response header from upstream/', 'crit', 'req'),
		'too_large'      => array('/^client intended to send too large body/', 'warn', 'req'),
		'script_unknown' => array('/^FastCGI sent in stderr: "Primary script unknown"/', 'info', 'req'),
		'php_stderr'     => array('/^FastCGI sent in stderr: /', 'warn', 'req'),
		'limit'          => array('/^limiting (requests|connections)/', 'info', 'req'),
		'loop'           => array('/^rewrite or internal redirection cycle/', 'warn', 'req'),
	);
}

/* "/home/<user>/public_html/x" => "~/public_html/x". */
function sitelog_short_path($p, $user) {
	$home = '/home/'.$user.'/';
	return $user !== '' && strncmp($p, $home, strlen($home)) === 0 ? '~/'.substr($p, strlen($home)) : $p;
}

/* What a request is: array(bot name or '', scanner, browser, system). */
function sitelog_who($ip, $ua, $path, $status, array $own) {
	if (isset($own[$ip]))
		return array('This server', false, '', '');
	$u = stats_ua($ua);
	if ($u[0] !== '')
		return array($u[0], false, '', '');
	$scan = stats_probe_path($path) || ($status >= 400 && $status < 500 && stats_scanner_path($path));
	return array('', $scan, $u[1], $u[2]);
}

/*
 * One day of a site's log. $o:
 *   day      Y-m-d (default today)
 *   tab      requests (default) | errors
 *   filter   all | err (4xx + 5xx) | 5xx            (requests)
 *   bots     false = leave bots and scanners out    (requests)
 *   q        only requests with this text in the address, IP, browser or referrer
 *   ip       only this address
 *   offset   skip the newest N matching requests ("Show older")
 *   grouped  true (default) = errors by kind; false = the newest 500 lines
 *   after    "<inode>:<offset>" from an earlier answer (today only): only the
 *            lines written since, for Live; the answer then has no days/summary
 *            of the whole day but 'add' (what the new lines add to it)
 * Returns result_ok with: site, day, days, today, errors_available, summary
 * {requests, errors, e5, bytes, error_lines, kinds, serious}, counts {all,
 * err, e5} and rows [] (requests) or groups [] / lines [] (errors), more
 * (older matching requests), cursor (today: for 'after').
 */
function sitelog_read($domain, array $o = array()) {
	$domain = strtolower((string)$domain);
	$user = stats_sites()[$domain] ?? '';
	if ($user === '')
		return result_error("No site $domain on this server.");
	$days = sitelog_days($domain);
	$day = (string)($o['day'] ?? '');
	if ($day === '' || $day === 'today')
		$day = date('Y-m-d');
	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || !in_array($day, $days, true))
		return result_error("No log of $domain for $day: the server keeps the days ".end($days).' to '.$days[0].'.');
	$tab = ($o['tab'] ?? 'requests') === 'errors' ? 'errors' : 'requests';
	$filter = in_array($o['filter'] ?? 'all', array('all', 'err', '5xx'), true) ? ($o['filter'] ?? 'all') : 'all';
	$bots = !array_key_exists('bots', $o) || $o['bots'] !== false;
	$q = strtolower(substr(trim((string)($o['q'] ?? '')), 0, 200));
	$ip = substr(trim((string)($o['ip'] ?? '')), 0, 64);
	$offset = max(0, min(SITELOG_MAX_ROWS - SITELOG_PAGE, (int)($o['offset'] ?? 0)));
	$grouped = !array_key_exists('grouped', $o) || $o['grouped'] !== false;
	$today = $day === date('Y-m-d');
	$path = stats_log_path($domain);
	$nginx = web_front() === 'nginx';
	$own = array_flip(wp_sec_server_ips());
	$kinds = sitelog_kinds();

	$sum = array('requests' => 0, 'errors' => 0, 'e5' => 0, 'bytes' => 0, 'error_lines' => 0, 'kinds' => 0, 'serious' => 0);
	$counts = array('all' => 0, 'err' => 0, 'e5' => 0);
	$keep = $offset + SITELOG_PAGE;
	$ring = array(); $matched = 0;
	$groups = array(); $elines = array();

	$take = function ($l) use (&$sum, &$counts, &$ring, &$matched, &$groups, &$elines, $tab, $filter, $bots, $q, $ip, $keep, $own, $kinds, $user, $grouped) {
		if ($l === '')
			return;
		if ($l[0] >= '0' && $l[0] <= '9' && preg_match('#^\d{4}/#', $l)) {
			if (!($e = sitelog_parse_error($l)))
				return;
			$sum['error_lines']++;
			$raw = strlen($l) > SITELOG_LINE_MAX ? substr($l, 0, SITELOG_LINE_MAX).' [...]' : $l;
			if ($tab === 'errors' && !$grouped) {
				$elines[] = $raw;
				if (count($elines) > 1000) $elines = array_slice($elines, -500);
			}
			/* grouped always: the tab's line says how many kinds and how serious */
			$kind = ''; $m = null;
			foreach ($kinds as $id => $k)
				if (preg_match($k[0], $e['msg'], $m)) { $kind = $id; break; }
			$key = $kind !== '' ? $kind : substr(preg_replace(array('/"[^"]*"/', '/\d+/'), array('"…"', 'N'), $e['msg']), 0, 300);
			if (!isset($groups[$key]))
				$groups[$key] = array('kind' => $kind, 'level' => $kind !== '' ? $kinds[$kind][1] : ($e['lv'] === 'error' || $e['lv'] === 'warn' ? 'warn' : ($e['lv'] === 'notice' || $e['lv'] === 'info' ? 'info' : 'crit')),
				                      'message' => substr($e['msg'], 0, 500), 'count' => 0, 'first' => $e['t'], 'last' => $e['t'], 'items' => array(), 'lines' => array());
			$g = &$groups[$key];
			$g['count']++;
			$g['last'] = $e['t'];
			$item = $kind !== '' && $kinds[$kind][2] === 'path' && isset($m[1]) ? sitelog_short_path($m[1], $user)
			      : (preg_match('#^[A-Z]+ (\S+)#', $e['req'], $r) ? preg_replace('/\?.*/', '', $r[1]) : '');
			if ($item !== '' && (isset($g['items'][$item]) || count($g['items']) < 500))
				$g['items'][$item] = ($g['items'][$item] ?? 0) + 1;
			$g['lines'][] = $raw;
			if (count($g['lines']) > 5) array_shift($g['lines']);
			unset($g);
			return;
		}
		if (!($a = sitelog_parse_access($l)))
			return;
		$sum['requests']++;
		$sum['bytes'] += $a['b'];
		if ($a['s'] >= 400) $sum['errors']++;
		if ($a['s'] >= 500) $sum['e5']++;
		if ($tab !== 'requests')
			return;
		if ($ip !== '' && $a['ip'] !== $ip)
			return;
		if ($q !== '' && strpos(strtolower($a['p'].$a['q'].' '.$a['ip'].' '.$a['ua'].' '.$a['ref']), $q) === false)
			return;
		$who = null;
		if (!$bots) {
			$who = sitelog_who($a['ip'], $a['ua'], $a['p'], $a['s'], $own);
			if ($who[0] !== '' || $who[1])
				return;
		}
		$counts['all']++;
		if ($a['s'] >= 400) $counts['err']++;
		if ($a['s'] >= 500) $counts['e5']++;
		if (($filter === 'err' && $a['s'] < 400) || ($filter === '5xx' && $a['s'] < 500))
			return;
		$matched++;
		$a['raw'] = strlen($l) > SITELOG_LINE_MAX ? substr($l, 0, SITELOG_LINE_MAX).' [...]' : $l;
		$a['who'] = $who;
		$ring[] = $a;
		if (count($ring) > $keep * 2)
			$ring = array_slice($ring, -$keep);
	};

	$after = (string)($o['after'] ?? '');
	if ($after !== '') {
		/* Live: only what the live file got since $after */
		if (!$today)
			return result_error('Live is for today only.');
		if (!preg_match('/^(\d+):(\d+)$/', $after, $c))
			return result_error('after: <inode>:<offset> from an earlier answer.');
		clearstatcache(true, $path);
		$st = @stat($path);
		if (!$st || (int)$st['ino'] !== (int)$c[1] || $st['size'] < (int)$c[2])
			return result_ok('', array('site' => $domain, 'day' => $day, 'reset' => true));
		$keep = SITELOG_MAX_ROWS;
		$fh = @fopen($path, 'r');
		$pos = (int)$c[2];
		if ($fh) {
			fseek($fh, $pos);
			while (($l = fgets($fh, 65536)) !== false && $matched < 500) {
				if (substr($l, -1) !== "\n")
					break;                  // a line still being written: next time
				$pos += strlen($l);
				$take(rtrim($l, "\r\n"));
			}
			fclose($fh);
		}
		$rows = array_map(function ($a) use ($own) { return sitelog_row($a, $own); }, array_reverse($ring));
		return result_ok('', array('site' => $domain, 'day' => $day, 'add' => $sum, 'counts' => $counts, 'rows' => $rows,
		                           'cursor' => $st['ino'].':'.$pos));
	}

	$cursor = null;
	if ($today) {
		clearstatcache(true, $path);
		if (($st = @stat($path)))
			$cursor = $st['ino'].':'.$st['size'];
	}
	sitelog_each($domain, $day, $take);
	if ($cursor !== null) {
		/* lines written while the day was read are left to the next Live call */
		list($ino, $size) = explode(':', $cursor);
		clearstatcache(true, $path);
		$st = @stat($path);
		if ($st && (int)$st['ino'] === (int)$ino && $st['size'] > (int)$size)
			$cursor = $ino.':'.(int)$size;
	}

	$out = array('site' => $domain, 'day' => $day, 'days' => $days, 'today' => $today, 'errors_available' => $nginx, 'tz' => date_default_timezone_get(),
	             'summary' => $sum, 'tab' => $tab, 'cursor' => $cursor);
	if ($tab === 'requests') {
		$ring = array_slice($ring, -$keep);
		$page = array_reverse(array_slice($ring, 0, max(0, count($ring) - $offset)));
		$page = array_slice($page, 0, SITELOG_PAGE);
		$out['counts'] = $counts;
		$out['rows'] = array_map(function ($a) use ($own) { return sitelog_row($a, $own); }, $page);
		$out['offset'] = $offset;
		$out['more'] = max(0, $matched - $offset - count($page));
	} else {
		$order = array('crit' => 0, 'warn' => 1, 'info' => 2);
		$list = array_values($groups);
		usort($list, function ($x, $y) use ($order) { return $order[$x['level']] <=> $order[$y['level']] ?: $y['count'] <=> $x['count']; });
		foreach ($list as &$g) {
			arsort($g['items']);
			$g['distinct'] = count($g['items']);
			$g['items'] = array_map(function ($k, $n) { return array((string)$k, $n); }, array_keys(array_slice($g['items'], 0, 6, true)), array_slice($g['items'], 0, 6, true));
			$g['lines'] = array_reverse($g['lines']);
		}
		unset($g);
		$out['grouped'] = $grouped;
		if ($grouped)
			$out['groups'] = $list;
		else
			$out['lines'] = array_reverse(array_slice($elines, -500));
	}
	$out['summary']['kinds'] = count($groups);
	$out['summary']['serious'] = array_sum(array_map(function ($g) { return $g['level'] === 'crit' ? $g['count'] : 0; }, $groups));
	return result_ok('', $out);
}

/* A request as the page shows it. */
function sitelog_row(array $a, array $own) {
	$who = $a['who'] ?? sitelog_who($a['ip'], $a['ua'], $a['p'], $a['s'], $own);
	return array('t' => $a['t'], 'ip' => $a['ip'], 'cc' => stats_geo_country($a['ip']), 'm' => $a['m'],
	             'p' => strlen($a['p']) > 500 ? substr($a['p'], 0, 500).'…' : $a['p'], 'q' => strlen($a['q']) > 300 ? substr($a['q'], 0, 300).'…' : $a['q'],
	             's' => $a['s'], 'b' => $a['b'], 'ref' => substr($a['ref'], 0, 500), 'ua' => substr($a['ua'], 0, 500),
	             'bot' => $who[0], 'scan' => $who[1], 'br' => $who[2], 'os' => $who[3], 'raw' => $a['raw']);
}

/* The sites whose logs an account may read: main domain first. */
function sitelog_sites($user) {
	return stats_account_sites($user);
}

/* Writes $day's lines of the site's log, gzip-compressed, through $out($bytes)
   (the 2083 Download: a day's log as <site>-<day>.log.gz). Returns
   result_ok(name) or an error before anything was written. */
function sitelog_download($domain, $day, callable $out) {
	$domain = strtolower((string)$domain);
	if ((stats_sites()[$domain] ?? '') === '')
		return result_error("No site $domain on this server.");
	if ($day === '' || $day === 'today')
		$day = date('Y-m-d');
	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || !in_array($day, sitelog_days($domain), true))
		return result_error("No log of $domain for $day.");
	$z = deflate_init(ZLIB_ENCODING_GZIP, array('level' => 6));
	$buf = '';
	$stop = false;
	sitelog_each($domain, $day, function ($l) use ($z, &$buf, $out, &$stop) {
		$buf .= $l."\n";
		if (strlen($buf) >= 262144) {
			if ($out(deflate_add($z, $buf, ZLIB_NO_FLUSH)) === false) { $stop = true; return false; }
			$buf = '';
		}
	});
	if (!$stop)
		$out(deflate_add($z, $buf, ZLIB_FINISH));
	return result_ok('', array('name' => $domain.'-'.$day.'.log.gz'));
}
