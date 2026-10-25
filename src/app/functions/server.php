<?php
/*
 * The server as a whole: status, services, the mail queue and logs. Used by
 * the REST API (GET /api/v1/server, /services, /mail-queue, /logs) and
 * through it by the MCP tools (`reqad mcp`, app/mcp/mcp.php).
 *
 *   server_status()                       OS, uptime, load, memory, disks, web
 *                                         server, PHP, accounts, mail queue, health
 *   server_services()                     the services and timers of
 *                                         server-software.ini with their state
 *   server_service_restart($name)         restart one of those services
 *   server_mail_queue($filter, $search, $limit)   queue size, oldest messages,
 *                                         top senders and recipient domains
 *   server_mail_queue_action($verb, $id)  deliver | remove one message
 *   server_logs()                         the logs that can be read
 *   server_log_tail($name, $domain, $lines, $grep)  their last lines
 *
 * Commands run with sudo -n: the panel (user reqad) has it, root does not need it.
 */

/* Root runs commands as itself; the panel goes through sudo. */
function server_sudo() {
	return function_exists('posix_geteuid') && posix_geteuid() === 0 ? '' : 'sudo -n ';
}

function server_status() {
	global $db, $ini;
	$osr = @parse_ini_file('/etc/os-release');
	$up  = (float)explode(' ', (string)@file_get_contents('/proc/uptime'))[0];
	$la  = explode(' ', trim((string)@file_get_contents('/proc/loadavg')));
	$mem = array();
	foreach (@file('/proc/meminfo', FILE_IGNORE_NEW_LINES) ?: array() as $l)
		if (preg_match('/^(\w+):\s+(\d+) kB/', $l, $m))
			$mem[$m[1]] = (int)round($m[2] / 1024);
	$cpus = preg_match_all('/^processor\s*:/m', (string)@file_get_contents('/proc/cpuinfo'));

	$disks = array();
	$df = (string)shell_exec('df -P -k -x tmpfs -x devtmpfs -x overlay -x squashfs -x efivarfs 2>/dev/null');
	foreach (array_slice(explode("\n", trim($df)), 1) as $l) {
		$f = preg_split('/\s+/', trim($l));
		if (count($f) < 6 || strpos($f[0], '/') !== 0)
			continue;
		$disks[] = array('mount' => $f[5], 'size_gb' => round($f[1] / 1048576, 1), 'used_gb' => round($f[2] / 1048576, 1),
		                 'free_gb' => round($f[3] / 1048576, 1), 'used_percent' => (int)rtrim($f[4], '%'));
	}

	include _PATH.'/public_html/modules/version.php';
	$php = array_values(array_filter(array_map('trim', explode(',', $ini['php_versions'] ?? '')), 'strlen'));
	$out = array(
		'hostname'       => gethostname(),
		'reqad'          => isset($reqad_version) ? $reqad_version[0] : '',
		'os'             => $osr && !empty($osr['PRETTY_NAME']) ? $osr['PRETTY_NAME'] : '',
		'kernel'         => php_uname('r'),
		'uptime_seconds' => (int)$up,
		'load'           => array((float)($la[0] ?? 0), (float)($la[1] ?? 0), (float)($la[2] ?? 0)),
		'cpus'           => $cpus,
		'memory_mb'      => array('total' => $mem['MemTotal'] ?? 0, 'available' => $mem['MemAvailable'] ?? 0,
		                          'swap_total' => $mem['SwapTotal'] ?? 0, 'swap_free' => $mem['SwapFree'] ?? 0),
		'disks'          => $disks,
		'web'            => array('template' => $ini['template'] ?? '', 'front' => web_front($ini) === 'httpd' ? 'apache' : 'nginx'),
		'php'            => array('default' => trim($ini['php'] ?? ''), 'installed' => $php),
		'accounts'       => count(account_list($db)),
		'features'       => array(),
	);
	foreach (array('email', 'backup', 'wptoolkit', 'deployments') as $f)
		$out['features'][$f] = feature_enabled($ini, $f);
	if (feature_enabled($ini, 'email'))
		$out['mail_queue'] = mq_count();
	if (function_exists('health_items')) {
		$out['health'] = health_counts(health_items($db));
		$out['health']['last_run'] = (int)setting_get('health-last-run', '0') ?: null;
	}
	/* the dashboard's cached checks, when it ran lately (both are slow to compute) */
	$rb = @json_decode((string)@file_get_contents(_PATH.'/log/dashboard_reboot.cache'), true);
	if (is_array($rb) && isset($rb['reboot_req']) && filemtime(_PATH.'/log/dashboard_reboot.cache') > time() - 86400)
		$out['reboot_required'] = (int)$rb['reboot_req'] === 1;
	return $out;
}

/* The services of server-software.ini (services=, timers=), as listed on the Services page. */
function server_service_names($kind = 'service') {
	global $ini;
	$key = $kind === 'timer' ? 'timers' : 'services';
	return array_values(array_filter(array_map('trim', explode(',', $ini[$key] ?? '')), function ($n) {
		return preg_match('/^[A-Za-z0-9@._-]{1,64}$/', $n);
	}));
}

/* State of each installed service and timer: name, kind, active, state, enabled, since. */
function server_services() {
	$units = array();
	foreach (server_service_names('service') as $n) $units[$n.'.service'] = array($n, 'service');
	foreach (server_service_names('timer') as $n)   $units[$n.'.timer'] = array($n, 'timer');
	if (!$units)
		return array();
	$cmd = server_sudo().'systemctl show -p Id,LoadState,ActiveState,SubState,UnitFileState,ActiveEnterTimestamp';
	foreach (array_keys($units) as $u)
		$cmd .= ' '.escapeshellarg($u);
	$rows = array();
	foreach (preg_split('/\n\s*\n/', trim((string)shell_exec($cmd.' 2>/dev/null'))) as $block) {
		$p = array();
		foreach (explode("\n", $block) as $l)
			if (($i = strpos($l, '=')) !== false)
				$p[substr($l, 0, $i)] = substr($l, $i + 1);
		if (empty($p['Id']) || !isset($units[$p['Id']]) || ($p['LoadState'] ?? '') !== 'loaded')
			continue;
		list($name, $kind) = $units[$p['Id']];
		$since = !empty($p['ActiveEnterTimestamp']) ? strtotime($p['ActiveEnterTimestamp']) : 0;
		$rows[] = array('name' => $name, 'kind' => $kind, 'active' => ($p['ActiveState'] ?? '') === 'active',
		                'state' => ($p['ActiveState'] ?? '').'/'.($p['SubState'] ?? ''),
		                'enabled' => ($p['UnitFileState'] ?? ''), 'since' => $since ?: null);
	}
	return $rows;
}

/*
 * Restart a service from server-software.ini's list. The panel's own
 * services (reqad, reqad-php-fpm) restart 3 seconds later from a transient
 * unit, so the request that asked can still be answered.
 */
function server_service_restart($name) {
	$name = trim((string)$name);
	if (!in_array($name, server_service_names('service'), true))
		return result_error("'$name' is not one of the services on the Services page (services= in etc/server-software.ini).");
	$known = array_column(server_services(), 'name');
	if (!in_array($name, $known, true))
		return result_error("The service $name is not installed.");
	if (in_array($name, array('reqad', 'reqad-php-fpm'), true)) {
		shell_exec(server_sudo().'systemd-run --quiet --on-active=3 --unit='.escapeshellarg('reqad-restart-'.$name.'-'.time())
			.' systemctl restart '.escapeshellarg($name).' 2>&1');
		plugin_hook('service.restarted', array('service' => $name));
		return result_ok("$name restarts in 3 seconds.", array('service' => $name, 'delayed' => true));
	}
	exec(server_sudo().'systemctl restart '.escapeshellarg($name).' 2>&1', $o, $rc);
	$state = trim((string)shell_exec(server_sudo().'systemctl is-active '.escapeshellarg($name).' 2>/dev/null'));
	if ($rc !== 0 || $state !== 'active') {
		$tail = (string)shell_exec(server_sudo().'journalctl -u '.escapeshellarg($name).' -n 15 --no-pager -o cat 2>/dev/null');
		return result_error("$name did not start again (state: $state).", array('service' => $name, 'state' => $state, 'journal' => trim($tail)));
	}
	plugin_hook('service.restarted', array('service' => $name));
	return result_ok("$name restarted.", array('service' => $name, 'state' => $state));
}

/*
 * The mail queue: how many messages, how many frozen, the oldest $limit
 * (filter all | frozen, search = exim -bp pattern) with their subjects, and
 * the senders and recipient domains with the most messages.
 */
function server_mail_queue($filter = 'all', $search = '', $limit = 50) {
	$total = mq_count();
	if ($total < 0)
		return result_error('Could not read the mail queue.');
	$out = array('total' => $total, 'shown' => 0, 'messages' => array());
	if ($total === 0)
		return result_ok('', $out);
	if ($total > MQ_MAX_LIST && trim((string)$search) === '') {
		$out['note'] = 'More than '.MQ_MAX_LIST.' messages: give a search to list some of them.';
		return result_ok('', $out);
	}
	$rows = mq_list($filter, $search);
	$out['matching'] = count($rows);
	$out['frozen'] = count(array_filter($rows, function ($r) { return $r['frozen']; }));
	$senders = $domains = array();
	foreach ($rows as $r) {
		$s = $r['sender'] !== '' ? $r['sender'] : '<> (bounce)';
		$senders[$s] = ($senders[$s] ?? 0) + 1;
		foreach ($r['recipients'] as $rc) {
			$d = strtolower(substr(strrchr($rc, '@') ?: '', 1));
			if ($d !== '')
				$domains[$d] = ($domains[$d] ?? 0) + 1;
		}
	}
	arsort($senders);
	arsort($domains);
	$out['top_senders'] = array_slice($senders, 0, 10, true);
	$out['top_recipient_domains'] = array_slice($domains, 0, 10, true);
	$page = array_slice($rows, 0, max(1, min(200, (int)$limit)));
	$subj = mq_subjects(array_column($page, 'id'));
	foreach ($page as &$r)
		$r['subject'] = $subj[$r['id']] ?? '';
	unset($r);
	$out['messages'] = $page;
	$out['shown'] = count($page);
	return result_ok('', $out);
}

/* Try to deliver one queued message now, or remove it. */
function server_mail_queue_action($verb, $id) {
	if (!in_array($verb, array('deliver', 'remove'), true))
		return result_error('Unknown action.');
	if (!mq_valid_id($id))
		return result_error("'$id' is not a message id (like 1sXyZa-000Abc-Qr).");
	$log = trim(mq_helper(array($verb, $id), $rc));
	mq_cache_clear();
	if ($rc !== 0)
		return result_error($log !== '' ? $log : "Could not $verb message $id.", array('id' => $id));
	return result_ok($verb === 'remove' ? "Message $id removed from the queue." : "Delivery of $id attempted.",
		array('id' => $id, 'log' => $log));
}

/*
 * The logs that can be read: name => label, path ({domain} and {user} are
 * filled in), and whether the domain's account may read it with its own
 * token ('account' => true; the others are the admin's).
 */
function server_logs() {
	$apache = web_front() === 'httpd';
	$logs = array(
		'site'        => array('label' => 'Web requests and errors of a domain', 'domain' => true, 'account' => true,
		                       'path' => $apache ? '/var/log/httpd/{domain}_log' : '/var/log/nginx/{domain}_log'),
		'php'         => array('label' => 'PHP errors of a domain', 'domain' => true, 'account' => true,
		                       'path' => '/home/{user}/logs/{domain}-error.log'),
		'web-error'   => array('label' => 'Web server errors', 'path' => $apache ? '/var/log/httpd/error_log' : '/var/log/nginx/error.log'),
		'mail'        => array('label' => 'Mail delivery (exim main log)', 'path' => '/var/log/exim/main.log'),
		'mail-reject' => array('label' => 'Rejected mail (exim)', 'path' => '/var/log/exim/reject.log'),
		'mail-panic'  => array('label' => 'Mail server errors (exim panic log)', 'path' => '/var/log/exim/panic.log'),
		'imap'        => array('label' => 'IMAP / POP3 logins (dovecot)', 'path' => '/var/log/maillog'),
		'firewall'    => array('label' => 'Firewall blocks (lfd)', 'path' => '/var/log/lfd.log'),
		'ssh'         => array('label' => 'SSH and sudo (secure)', 'path' => '/var/log/secure'),
		'cron'        => array('label' => 'Cron jobs', 'path' => '/var/log/cron'),
		'system'      => array('label' => 'System messages', 'path' => '/var/log/messages'),
		'panel'       => array('label' => 'Reqad panel', 'path' => _PATH.'/log/debug_log'),
	);
	return plugin_filter('server.logs', $logs);
}

/*
 * The last $lines lines of a log (at most 1000). $grep: only lines containing
 * it (case-insensitive), searched in the last 20000 lines. Returns the text,
 * oldest line first.
 */
function server_log_tail($name, $domain = '', $lines = 100, $grep = '') {
	$logs = server_logs();
	if (!isset($logs[$name]))
		return result_error("No log '$name'. Logs: ".implode(', ', array_keys($logs)).'.');
	$l = $logs[$name];
	$path = $l['path'];
	if (!empty($l['domain'])) {
		$domain = strtolower(trim((string)$domain));
		if (!valid_domain($domain))
			return result_error("The $name log needs a domain.");
		$user = scope_domain_owner($domain);
		if ($user === '')
			return result_error("No account has the domain $domain.");
		$path = str_replace(array('{domain}', '{user}'), array($domain, $user), $path);
	}
	$lines = max(1, min(1000, (int)$lines ?: 100));
	$grep = substr(trim((string)$grep), 0, 200);
	if (!empty($l['domain']) && strpos($path, '/home/'.$user.'/') === 0) {
		/* in the account's home: read AS the account (fm-helper verb tail),
		   never as root - the account could swap the log for a symlink */
		$r = files_result(files_run($user, array('verb' => 'tail', 'path' => substr($path, strlen('/home/'.$user.'/')),
		                                         'lines' => $lines, 'grep' => $grep), null, null, 30));
		if (!$r['ok'])
			return $r;
		if (!empty($r['data']['missing']))
			return result_ok("The log $path does not exist (yet).", array('log' => $name, 'path' => $path, 'lines' => 0, 'text' => ''));
		$text = (string)$r['data']['text'];
	} else {
		$s = server_sudo();
		if (trim((string)shell_exec($s.'test -f '.escapeshellarg($path).' && echo y')) !== 'y')
			return result_ok("The log $path does not exist (yet).", array('log' => $name, 'path' => $path, 'lines' => 0, 'text' => ''));
		$cmd = $grep === ''
			? $s.'tail -n '.$lines.' '.escapeshellarg($path)
			: $s.'tail -n 20000 '.escapeshellarg($path).' | grep -F -i -a -- '.escapeshellarg($grep).' | tail -n '.$lines;
		$text = (string)shell_exec($cmd.' 2>/dev/null');
	}
	/* very long lines (minified payloads, base64) would flood the reader */
	$text = implode("\n", array_map(function ($x) { return strlen($x) > 2000 ? substr($x, 0, 2000).' [...]' : $x; },
		explode("\n", rtrim($text, "\n"))));
	return result_ok('', array('log' => $name, 'path' => $path, 'lines' => $text === '' ? 0 : substr_count($text, "\n") + 1,
	                           'text' => $text));
}
