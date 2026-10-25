<?php
/*
 * Health checks: what needs attention on this server and in each account.
 *
 *   health_checks()                    the registry: id => label, scope, run
 *   health_run($db, $only)             run the checks and store what they find.
 *                                      As root: cron every 30 minutes,
 *                                      `reqad health run`, "Check now" (sudo).
 *   health_items($db, $account, $all)  the stored items, most severe first;
 *                                      dismissed ones left out unless $all.
 *                                      $account null = all, '' = server only.
 *   health_dismiss($db, $id, $days)    hide an item for $days (0 = for good)
 *
 * An item (health_item()): id check:account:subject (stable across runs, so a
 * dismissal keeps applying), account ('' = the server), subject (domain,
 * service, ...), severity critical | warning | info, title, detail, fix_url +
 * fix_label (a panel page to fix it), dashboard (false = the dashboard already
 * shows it its own way, e.g. the kernel-reboot alert).
 *
 * Plugins add checks with
 *     'filters' => array('health.checks' => function ($checks) {
 *         $checks['my_check'] = array('label' => '...', 'scope' => 'account',
 *                                     'run' => function () { return array(health_item(...)); });
 *         return $checks; })
 * A check that throws is reported as one info item instead of stopping the run.
 */

function health_severities() {
	return array('critical' => 0, 'warning' => 1, 'info' => 2);
}

function health_item($check, $account, $subject, $severity, $title, $detail = '', $fix_url = '', $fix_label = '', $dashboard = true) {
	return array(
		'id'        => $check.':'.$account.':'.$subject,
		'check'     => $check,
		'account'   => (string)$account,
		'subject'   => (string)$subject,
		'severity'  => isset(health_severities()[$severity]) ? $severity : 'info',
		'title'     => (string)$title,
		'detail'    => (string)$detail,
		'fix_url'   => (string)$fix_url,
		'fix_label' => (string)$fix_label,
		'dashboard' => (bool)$dashboard,
	);
}

function health_checks() {
	$checks = array(
		'ssl'        => array('label' => 'SSL certificates',               'scope' => 'account', 'run' => 'health_check_ssl'),
		'mail_dns'   => array('label' => 'Mail DNS records (MX, SPF, DKIM, DMARC)', 'scope' => 'account', 'run' => 'health_check_mail_dns'),
		'site_mail'  => array('label' => 'Sites that should have email but have none', 'scope' => 'account', 'run' => 'health_check_site_mail'),
		'php_eol'    => array('label' => 'Sites on older PHP versions',     'scope' => 'account', 'run' => 'health_check_php_eol'),
		'disk_quota' => array('label' => 'Account disk quotas',            'scope' => 'account', 'run' => 'health_check_disk_quota'),
		'mysql_remote' => array('label' => 'Remote database access',     'scope' => 'account', 'run' => 'health_check_mysql_remote'),
		'wordpress'  => array('label' => 'WordPress updates',              'scope' => 'account', 'run' => 'health_check_wordpress'),
		'deploy'     => array('label' => 'Git deployments',                'scope' => 'account', 'run' => 'health_check_deploy'),
		'app'        => array('label' => 'Node.js / Python apps',          'scope' => 'account', 'run' => 'health_check_apps_running'),
		'cron'       => array('label' => 'Cron jobs that keep failing',    'scope' => 'account', 'run' => 'health_check_cron'),
		'resources'  => array('label' => 'Sites out of PHP workers, processes stopped by the memory limit', 'scope' => 'account', 'run' => 'health_check_resources'),
		'disk_space' => array('label' => 'Server disk space',              'scope' => 'server',  'run' => 'health_check_disk_space'),
		'services'   => array('label' => 'Services',                       'scope' => 'server',  'run' => 'health_check_services'),
		'backup'     => array('label' => 'Nightly backup',                 'scope' => 'server',  'run' => 'health_check_backup'),
		'rbl'        => array('label' => 'Server IP on mail blocklists',   'scope' => 'server',  'run' => 'health_check_rbl'),
		'mail_queue' => array('label' => 'Mail queue',                     'scope' => 'server',  'run' => 'health_check_mail_queue'),
		'root_mail'  => array('label' => 'Mail for root',                  'scope' => 'server',  'run' => 'health_check_root_mail'),
		'reboot'     => array('label' => 'Reboot after a kernel update',   'scope' => 'server',  'run' => 'health_check_reboot'),
		'stats'      => array('label' => 'Visitor stats counting',         'scope' => 'server',  'run' => 'health_check_stats'),
		'resources_collect' => array('label' => 'Resource usage checks',  'scope' => 'server',  'run' => 'health_check_resources_collect'),
		'symlinks'   => array('label' => 'Symbolic link protection',       'scope' => 'server',  'run' => 'health_check_symlinks'),
		'vhost_split' => array('label' => 'Site https setups certbot put in a file of their own', 'scope' => 'server', 'run' => 'health_check_vhost_split'),
		'shell'      => array('label' => 'Full shell while root access is off', 'scope' => 'server', 'run' => 'health_check_shell'),
		'shell_log'  => array('label' => 'Commands of hosting accounts that look like abuse', 'scope' => 'server', 'run' => 'health_check_shell_log'),
		'dns_cluster' => array('label' => 'DNS cluster: secondaries in step with this server', 'scope' => 'server', 'run' => 'health_check_dns_cluster'),
		'dns_keys'   => array('label' => 'DNS API keys that expire or hold zones while revoked', 'scope' => 'server', 'run' => 'health_check_dns_keys'),
		'apps'       => array('label' => 'Webmail and phpMyAdmin versions','scope' => 'server',  'run' => 'health_check_apps'),
		'reqad'      => array('label' => 'Reqad updates',                  'scope' => 'server',  'run' => 'health_check_reqad_update'),
	);
	$checks = plugin_filter('health.checks', $checks);
	return is_array($checks) ? $checks : array();
}

/* Run every check (or only the ids in $only) and store the result. Returns
   array('items' => n, 'took' => seconds, 'errors' => array). */
function health_run($db, $only = null) {
	$start  = microtime(true);
	$now    = time();
	$errors = array();
	$count  = 0;
	$ran    = array();
	foreach (health_checks() as $id => $c) {
		if ($only !== null && !in_array($id, (array)$only, true))
			continue;
		try {
			$items = is_callable($c['run']) ? (array)call_user_func($c['run']) : array();
		} catch (Throwable $e) {
			$errors[$id] = $e->getMessage();
			$items = array(health_item($id, '', 'error', 'info', 'The check "'.$c['label'].'" could not run',
			                           $e->getMessage()));
		}
		$ran[] = $id;
		foreach ($items as $it) {
			if (!is_array($it) || !isset($it['id'])) continue;
			health_store($db, $it, $now);
			$count++;
		}
		/* whatever this check did not find again is fixed */
		$stmt = $db->prepare('DELETE FROM health_items WHERE check_id = :c AND last_seen < :now');
		$stmt->bindValue(':c', $id, SQLITE3_TEXT);
		$stmt->bindValue(':now', $now, SQLITE3_INTEGER);
		$stmt->execute();
	}
	$db->exec('DELETE FROM health_dismissed WHERE until > 0 AND until < '.$now);
	/* the accounts hear about their new items (the kinds they want) */
	if (function_exists('account_notify_health')) {
		try {
			account_notify_health($db, $now);
		} catch (Throwable $e) {
			$errors['notify'] = $e->getMessage();
		}
	}
	$took = round(microtime(true) - $start, 1);
	if ($only === null) {
		setting_put('health-last-run', (string)$now);
		setting_put('health-last-took', (string)$took);
	}
	return array('items' => $count, 'took' => $took, 'errors' => $errors, 'checks' => $ran);
}

function health_store($db, array $it, $now) {
	$stmt = $db->prepare('INSERT INTO health_items (id, check_id, account, subject, severity, title, detail, fix_url, fix_label, dashboard, first_seen, last_seen)
	                      VALUES (:id, :c, :a, :s, :sev, :t, :d, :fu, :fl, :db, :now, :now)
	                      ON CONFLICT(id) DO UPDATE SET severity = :sev, title = :t, detail = :d, fix_url = :fu,
	                          fix_label = :fl, dashboard = :db, last_seen = :now');
	$stmt->bindValue(':id',  $it['id'], SQLITE3_TEXT);
	$stmt->bindValue(':c',   $it['check'], SQLITE3_TEXT);
	$stmt->bindValue(':a',   $it['account'], SQLITE3_TEXT);
	$stmt->bindValue(':s',   $it['subject'], SQLITE3_TEXT);
	$stmt->bindValue(':sev', $it['severity'], SQLITE3_TEXT);
	$stmt->bindValue(':t',   $it['title'], SQLITE3_TEXT);
	$stmt->bindValue(':d',   $it['detail'], SQLITE3_TEXT);
	$stmt->bindValue(':fu',  $it['fix_url'], SQLITE3_TEXT);
	$stmt->bindValue(':fl',  $it['fix_label'], SQLITE3_TEXT);
	$stmt->bindValue(':db',  $it['dashboard'] ? 1 : 0, SQLITE3_INTEGER);
	$stmt->bindValue(':now', $now, SQLITE3_INTEGER);
	$stmt->execute();
}

/* Stored items, most severe first, then server before accounts, then by name.
   Each row gets 'dismissed_until' (null = shown). */
function health_items($db, $account = null, $all = false) {
	$sql = 'SELECT h.*, d.until AS dismissed_until FROM health_items h LEFT JOIN health_dismissed d ON d.id = h.id';
	if ($account !== null)
		$sql .= ' WHERE h.account = :a';
	$stmt = $db->prepare($sql);
	if ($account !== null)
		$stmt->bindValue(':a', (string)$account, SQLITE3_TEXT);
	$res  = $stmt->execute();
	$now  = time();
	$rows = array();
	while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
		$hidden = $r['dismissed_until'] !== null && ((int)$r['dismissed_until'] === 0 || (int)$r['dismissed_until'] > $now);
		if (!$hidden) $r['dismissed_until'] = null;
		if ($hidden && !$all) continue;
		$r['dashboard'] = (bool)$r['dashboard'];
		$rows[] = $r;
	}
	$sev = health_severities();
	usort($rows, function ($a, $b) use ($sev) {
		return array($sev[$a['severity']] ?? 9, $a['account'] !== '', $a['account'], $a['subject'], $a['check_id'])
		   <=> array($sev[$b['severity']] ?? 9, $b['account'] !== '', $b['account'], $b['subject'], $b['check_id']);
	});
	return $rows;
}

function health_item_get($db, $id) {
	$stmt = $db->prepare('SELECT * FROM health_items WHERE id = :id');
	$stmt->bindValue(':id', (string)$id, SQLITE3_TEXT);
	$res = $stmt->execute();
	$r = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;
	return $r ?: null;
}

/* Hide an item from the card and the default lists: $days > 0 for that long,
   0 for good. result_ok/result_error like the other app/functions. */
function health_dismiss($db, $id, $days, $by = '') {
	if (!health_item_get($db, $id))
		return result_error("No health item '$id'.");
	$days  = (int)$days;
	$until = $days > 0 ? time() + $days * 86400 : 0;
	$stmt  = $db->prepare('INSERT OR REPLACE INTO health_dismissed (id, until, by_user, created) VALUES (:id, :u, :by, :now)');
	$stmt->bindValue(':id', (string)$id, SQLITE3_TEXT);
	$stmt->bindValue(':u', $until, SQLITE3_INTEGER);
	$stmt->bindValue(':by', (string)$by, SQLITE3_TEXT);
	$stmt->bindValue(':now', time(), SQLITE3_INTEGER);
	$stmt->execute();
	plugin_hook('health.dismissed', array('id' => $id, 'until' => $until, 'by' => $by));
	return result_ok($days > 0 ? "Hidden for $days days." : 'Hidden until you show it again.', array('id' => $id, 'until' => $until));
}

function health_restore($db, $id) {
	$stmt = $db->prepare('DELETE FROM health_dismissed WHERE id = :id');
	$stmt->bindValue(':id', (string)$id, SQLITE3_TEXT);
	$stmt->execute();
	return result_ok('Shown again.', array('id' => $id));
}

/* What the dashboard card renders: every item (hidden ones flagged), the
   check labels for grouped rows, and when the checks last ran. */
function health_payload($db) {
	$labels = array();
	foreach (health_checks() as $id => $c)
		$labels[$id] = $c['label'];
	$items = array();
	foreach (health_items($db, null, true) as $it)
		$items[] = array('id' => $it['id'], 'check' => $it['check_id'], 'account' => $it['account'],
		                 'severity' => $it['severity'], 'title' => $it['title'], 'detail' => $it['detail'],
		                 'fix_url' => $it['fix_url'], 'fix_label' => $it['fix_label'], 'dashboard' => $it['dashboard'],
		                 'hidden' => $it['dismissed_until'] !== null, 'hidden_until' => $it['dismissed_until'],
		                 'first_seen' => (int)$it['first_seen']);
	$last = (int)setting_get('health-last-run', '0');
	return array('last_run' => $last ?: null, 'last_run_text' => $last ? date('j M H:i', $last) : '',
	             'labels' => $labels, 'items' => $items);
}

/* Counts per severity of the shown items, for badges. */
function health_counts(array $items) {
	$c = array('critical' => 0, 'warning' => 0, 'info' => 0);
	foreach ($items as $it)
		if (isset($c[$it['severity']])) $c[$it['severity']]++;
	return $c;
}

/* ---- helpers ------------------------------------------------------------- */

/* Active accounts: user, domain, has_email, disk_usage, disk_quota, dkim_selector. */
function health_accounts($db) {
	$rows = array();
	$res = $db->query("SELECT user, domain, has_email, disk_usage, disk_quota, dkim_selector FROM accounts WHERE status = 'active' ORDER BY user");
	while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC)))
		$rows[] = $r;
	return $rows;
}

function health_has_email($row) {
	return $row['has_email'] === 'true' || (string)$row['has_email'] === '1';
}

function health_dig($type, $name, $server = '') {
	$cmd = 'dig +short +time=3 +tries=1 '.escapeshellarg($type).' '.escapeshellarg($name);
	if ($server !== '')
		$cmd .= ' '.escapeshellarg('@'.$server);
	$out = trim((string)shell_exec($cmd.' 2>/dev/null'));
	/* dig prints ";; connection timed out" etc. on stdout */
	$lines = array_filter(array_map('trim', explode("\n", $out)), function ($l) { return $l !== '' && $l[0] !== ';'; });
	return array_values($lines);
}

/* A latest-version lookup on the internet, cached for a day in settings
   (health-latest-<key> = "<time>|<version>"). '' when unknown. */
function health_latest($key, callable $fetch) {
	$cached = setting_get('health-latest-'.$key, '');
	list($t, $v) = array_pad(explode('|', $cached, 2), 2, '');
	if ($v !== '' && time() - (int)$t < 86400)
		return $v;
	$new = trim((string)$fetch());
	if (!preg_match('/^[0-9][0-9A-Za-z.\-]{0,30}$/', $new))
		return $v;   /* offline or rate-limited: keep the old answer */
	setting_put('health-latest-'.$key, time().'|'.$new);
	return $new;
}

function health_http($url) {
	return (string)shell_exec('curl -sfL --max-time 10 -A reqad-health '.escapeshellarg($url).' 2>/dev/null');
}

function health_github_latest($repo) {
	$j = json_decode(health_http('https://api.github.com/repos/'.$repo.'/releases/latest'), true);
	return is_array($j) && isset($j['tag_name']) ? (string)$j['tag_name'] : '';
}

/* ---- the checks ---------------------------------------------------------- */

/* Certificate of every main domain, addon domain and the hostname: missing,
   self-signed, expired, expiring within 14 days (Let's Encrypt renews at 30,
   so 14 means the renewal is failing). */
function health_check_ssl() {
	global $db, $ini;
	$sites = array();
	foreach (health_accounts($db) as $a)
		$sites[$a['domain']] = $a['user'];
	foreach (addon_domain_list($ini) as $d => $ad)
		$sites[$d] = $ad['user'];
	$host = strtolower(trim((string)shell_exec('hostname')));
	if (valid_domain($host) && !isset($sites[$host]))
		$sites[$host] = '';

	$items = array();
	foreach ($sites as $domain => $user) {
		$c = local_ssl_cert_info($domain);
		$what = $user === '' ? 'the server hostname' : $domain;
		if ($c === false) {
			$items[] = health_item('ssl', $user, $domain, 'warning', "No certificate for $what",
			                       "The vhost of $domain points at no readable certificate, so https://$domain/ cannot work.",
			                       '/ssl/', 'SSL');
			continue;
		}
		$days = (int)floor(((int)$c['validTo_time_t'] - time()) / 86400);
		if ((int)$c['validTo_time_t'] < time()) {
			$items[] = health_item('ssl', $user, $domain, 'critical', "Certificate of $what expired",
			                       'It expired on '.date('j M Y', (int)$c['validTo_time_t']).'; browsers refuse the site.',
			                       '/ssl/', 'SSL');
		} elseif (ssl_is_self_signed($c)) {
			$items[] = health_item('ssl', $user, $domain, 'warning', "Self-signed certificate on $what",
			                       "Browsers warn on https://$domain/. A Let's Encrypt certificate needs the domain to point at this server.",
			                       '/ssl/', 'SSL');
		} elseif ($days < 14) {
			$items[] = health_item('ssl', $user, $domain, $days < 3 ? 'critical' : 'warning',
			                       "Certificate of $what expires in $days day".($days == 1 ? '' : 's'),
			                       "Let's Encrypt renews 30 days ahead, so the renewal is probably failing (certbot renew --dry-run shows why).",
			                       '/ssl/', 'SSL');
		}
	}
	return $items;
}

/* The mail domains of accounts with mail on (main and addon sites): MX
   (critical when missing: no mail arrives), SPF (also when it does not let
   this server send), DKIM (when mail is local, the record must match the key
   exim signs with) and DMARC. Asked at the domain's own name server, like the Check email page
   (mail_dns_check_many() in app/functions/email_tools.php). */
function health_check_mail_dns() {
	global $db, $ini;
	if (!feature_enabled($ini, 'email'))
		return array();
	/* every mail domain of an account with mail: its main domain and the
	   addon sites with mail (their own /etc/exim/userdomains lines) */
	$accts = $byuser = array();
	foreach (health_accounts($db) as $a)
		if (health_has_email($a)) {
			$accts[$a['domain']] = $a;
			$byuser[$a['user']] = $a;
		}
	foreach (email_userdomains() as $d => $u)
		if (isset($byuser[$u]) && !isset($accts[$d]) && valid_domain($d))
			$accts[$d] = $byuser[$u];
	$items = array();
	foreach (mail_dns_check_many(array_keys($accts)) as $d => $c) {
		$a = $accts[$d];
		$url = '/check-email-settings/?domain='.rawurlencode($d);
		if (!$c['mx']) {
			$items[] = health_item('mail_dns', $a['user'], $d, 'critical', "No MX record for $d",
			                       "Mail to @$d cannot be delivered. Check and fix adds the record at the DNS provider.",
			                       $url, 'Check & fix');
			continue;
		}
		if ($c['missing'])
			$items[] = health_item('mail_dns', $a['user'], $d, 'warning', 'Mail DNS for '.$d.': '.implode(', ', $c['missing']),
			                       'Mail from @'.$d.' may land in spam or be rejected: Gmail and Yahoo require SPF, DKIM and DMARC.',
			                       $url, 'Check & fix');
	}
	return $items;
}

/* Addon sites of an account with email that should have mail
   (domain_mail_wanted()) but have no /etc/exim/userdomains line: mail to
   them is refused and no mailbox can be made for them. */
function health_check_site_mail() {
	global $db, $ini;
	if (!feature_enabled($ini, 'email'))
		return array();
	$users = array();
	foreach (health_accounts($db) as $a)
		if (health_has_email($a))
			$users[$a['user']] = true;
	$ud = email_userdomains();
	$prefs = domain_mail_prefs();
	$had = domain_mail_had();
	$items = $names = array();
	foreach (addon_domain_list($ini) as $d => $a) {
		$u = $a['user'];
		if (!isset($users[$u]) || isset($ud[$d]))
			continue;
		$names[$u] = $names[$u] ?? domain_account_names($u);
		if (!domain_mail_wanted($d, domain_parent_of($u, $d, $names[$u]), $prefs, $had))
			continue;
		$items[] = health_item('site_mail', $u, $d, 'warning', "Email is not set up for $d",
		                       "The rest of the account has email, but mail to @$d is refused and no mailbox can be made for it. Set its email up again (Fix mail problem), or turn email off for the site if it needs none.",
		                       '/addon-domains/', 'Addon domains');
	}
	return $items;
}

/* Cron jobs made in the account panel (app/functions/cron.php) whose last 3
   or more runs failed: they run unattended, so nobody sees it otherwise.
   Only accounts whose crontab has such jobs are read. */
function health_check_cron() {
	global $db;
	$have = array();
	$out = (string)shell_exec('sudo -n sh -c '.escapeshellarg('grep -l -F -- '.escapeshellarg(CRON_WRAP.' ').' /var/spool/cron/* 2>/dev/null'));
	foreach (explode("\n", trim($out)) as $f)
		if ($f !== '')
			$have[basename($f)] = true;
	$items = array();
	foreach (health_accounts($db) as $a) {
		if (!isset($have[$a['user']]))
			continue;
		list($hash, $text) = cron_read($a['user']);
		if ($hash === null)
			continue;
		list(, $jobs) = cron_parse($text);
		$runs = cron_runs_all($a['user']);
		foreach ($jobs as $j) {
			if ($j['kind'] !== 'reqad' || !$j['on'])
				continue;
			$o = cron_job_out($j, $runs);
			if ($o['fails'] < 3)
				continue;
			$name = $j['name'] !== '' ? $j['name'] : (strlen($j['command']) > 60 ? substr($j['command'], 0, 57).'...' : $j['command']);
			$items[] = health_item('cron', $a['user'], $j['id'], 'warning', 'Cron job "'.$name.'" failed '.$o['fails'].' times in a row',
			                       'Its last run ended with exit code '.$o['last']['rc'].' on '.date('j M H:i', $o['last']['start']).'. Its output shows what went wrong (Cron jobs, Runs and output in the account panel).',
			                       '/cron/', 'Cron jobs');
		}
	}
	return $items;
}

/* Resource usage (app/functions/resources.php): a site that ran out of PHP
   workers 3 or more separate times in the last 24 hours, or a process the
   session memory limit stopped. */
function health_check_resources() {
	if (!function_exists('resources_attention'))
		return array();
	$items = array();
	foreach (resources_attention() as $r) {
		$when = date('H:i', (int)$r['last']);
		if ($r['kind'] === 'php_full')
			$items[] = health_item('resources', $r['user'], 'php:'.$r['subject'], 'warning', $r['subject'].' ran out of PHP workers '.$r['times'].' times in 24 hours',
				'All its PHP workers were busy, so visitors waited and some got an error page (502 or 504); the last time at '.$when.'. The site\'s logs show what was asked then: a slow page or plugin, or many requests at once (often bots). More workers can be set in PHP settings.',
				'/resources/', 'Resource usage');
		else
			$items[] = health_item('resources', $r['user'], 'mem_kill', 'warning', 'A process was stopped by the memory limit',
				'SSH, cron and the terminal share a memory limit; at '.$when.' the system stopped the process that held the most of it'.((int)$r['times'] > 1 ? ' ('.$r['times'].' times in 24 hours)' : '').'. A script that needs more memory should work in smaller parts, or the limit can be raised.',
				'/resources/', 'Resource usage');
	}
	return $items;
}

/* End of security support per PHP branch (php.net/supported-versions). */
function health_php_eol() {
	return array('5.4' => '2015-09-03', '5.6' => '2018-12-31', '7.0' => '2019-01-10', '7.1' => '2019-12-01', '7.2' => '2020-11-30',
	             '7.3' => '2021-12-06', '7.4' => '2022-11-28', '8.0' => '2023-11-26', '8.1' => '2025-12-31',
	             '8.2' => '2026-12-31', '8.3' => '2027-12-31', '8.4' => '2028-12-31', '8.5' => '2029-12-31');
}

/* Installed PHP versions newer than $v, oldest first. */
function health_php_newer($v) {
	global $ini;
	$n = array_filter(array_map('trim', explode(',', $ini['php_versions'] ?? '')), function ($s) use ($v) {
		return $s !== '' && version_compare($s, $v, '>');
	});
	usort($n, 'version_compare');
	return array_values($n);
}

/* Sites on an older PHP branch (php.net's end of security support has
   passed), and the server default new accounts get. Info only: the PHP
   builds Reqad installs carry security fixes for these branches too
   (5.4/5.6/7.4 included), so they are not unsafe - only older. */
function health_check_php_eol() {
	global $db, $ini;
	$eol   = health_php_eol();
	$old   = function ($v) use ($eol) {
		return isset($eol[$v]) && strtotime($eol[$v]) < time();
	};
	$newer = function ($v) {
		$n = health_php_newer($v);
		return $n ? ' Newer versions installed here: PHP '.implode(', ', $n).'.' : '';
	};
	$tip   = 'Consider updating your PHP to a newer version for better performance and security.';
	$items = array();
	$sites = array();
	foreach (health_accounts($db) as $a) {
		$v = account_php($a['domain'])[0];
		if ($v !== 'off') $sites[$a['domain']] = array($a['user'], $v);
	}
	foreach (addon_domain_list($ini) as $d => $ad)
		if (($ad['handler'] ?? '') !== 'none') $sites[$d] = array($ad['user'], $ad['version']);
	foreach ($sites as $d => $s) {
		if (!$old($s[1])) continue;
		$items[] = health_item('php_eol', $s[0], $d, 'info', "$d runs PHP {$s[1]}",
		                       $tip.$newer($s[1]), '/accounts/', 'Accounts');
	}
	$def = trim((string)($ini['php'] ?? ''));
	if ($def !== '' && $old($def))
		$items[] = health_item('php_eol', '', 'default', 'info', "New accounts start on PHP $def",
		                       'Consider a newer default PHP version for better performance and security.'.$newer($def),
		                       '/php-settings/', 'PHP settings');
	return $items;
}

/* Accounts with a quota: 90% used = warning, full = critical. */
function health_check_disk_quota() {
	global $db;
	$items = array();
	foreach (health_accounts($db) as $a) {
		$q = (int)$a['disk_quota']; $u = (int)$a['disk_usage'];
		if ($q <= 0 || $u < $q * 0.9) continue;
		$items[] = health_item('disk_quota', $a['user'], $a['domain'], $u >= $q ? 'critical' : 'warning',
		                       $a['user'].' uses '.round($u * 100 / $q).'% of its disk quota',
		                       number_format($u).' MB of '.number_format($q).' MB.'.($u >= $q ? ' Uploads and new mail fail when the quota is enforced.' : ''),
		                       '/accounts/', 'Accounts');
	}
	return $items;
}

/* Remote database logins (user@<ip>) that cannot connect: MySQL listens on
   this server only (bind-address / skip_networking), or csf does not let
   the address through to the MySQL port. One item per account, by the owner
   of the databases the login may use. Reading csf.allow needs root (cron,
   "Check now", the account panel's broker); without it only the first part
   is checked. */
function health_check_mysql_remote() {
	$items = array();
	$far = array();      // account => array(ip => true)
	$assigned = database_assignments();
	$accounts = database_account_names();
	foreach (mysql_rows('SELECT DISTINCT User, Host, Db FROM mysql.db WHERE Host NOT IN '.mysql_local_hosts_sql()) as $line) {
		list($u, $h, $d) = array_pad(explode("\t", $line), 3, '');
		$owner = database_owner(database_grant_name($d), $assigned, $accounts);
		if ($owner !== '' && $h !== '' && db_remote_normalize_ip($h) !== false)
			$far[$owner][$h] = true;
	}
	if (!$far)
		return $items;
	$st = mysql_remote_status();
	$port = (int)$st['port'];
	/* addresses csf lets through to the port: tcp|in|d=<port>|s=<ip>, or a bare address (all ports) */
	$open = null;
	if (!$st['listening'] || $st['firewall'] !== 'csf' || $st['port_public'])
		$open = true;
	elseif (is_readable('/etc/csf/csf.allow')) {
		$open = array();
		foreach (file('/etc/csf/csf.allow', FILE_IGNORE_NEW_LINES) ?: array() as $l) {
			$l = trim(preg_replace('/#.*/', '', $l));
			if (preg_match('/^tcp\|in\|d=(\d+)\|s=(\S+)$/i', $l, $m) && (int)$m[1] === $port)
				$open[(string)db_remote_normalize_ip($m[2])] = true;
			elseif (filter_var($l, FILTER_VALIDATE_IP))
				$open[(string)db_remote_normalize_ip($l)] = true;
		}
	}
	foreach ($far as $acct => $ips) {
		$ips = array_keys($ips);
		sort($ips);
		if (!$st['listening']) {
			$items[] = health_item('mysql_remote', $acct, 'mysql', 'warning',
				'Remote database access for '.$acct.' cannot connect',
				'MySQL accepts connections only from this server, so '.implode(', ', $ips).' cannot reach it.'
				.' The server has to allow outside connections to MySQL (bind-address in the MariaDB configuration, then a restart).',
				'/databases/', 'Databases');
			continue;
		}
		if (!is_array($open))
			continue;
		$closed = array_values(array_filter($ips, function ($ip) use ($open) { return !isset($open[$ip]); }));
		if ($closed)
			$items[] = health_item('mysql_remote', $acct, 'mysql', 'warning',
				'The firewall blocks remote database access for '.$acct,
				'Port '.$port.' is closed in csf for '.implode(', ', $closed).'. Remove the address in Remote access and add it again to write its firewall rule.',
				'/databases/', 'Databases');
	}
	return $items;
}

/* A deployment whose last deploy failed: the site still runs its previous
   release (or the files it had), so the branch is not live. */
function health_check_deploy() {
	$items = array();
	if (!deploy_enabled())
		return $items;
	foreach (deploy_list() as $d) {
		if (($d['run_status'] ?? '') !== 'failed')
			continue;
		$items[] = health_item('deploy', $d['account'], $d['domain'], 'warning',
		                       'The last deploy of '.$d['domain'].' failed',
		                       ucfirst((string)$d['run_message']).'. '.($d['current_release'] !== ''
		                           ? 'The site still runs the release of '.date('j M H:i', strtotime($d['current_release'])).'.'
		                           : 'The site was never deployed.'),
		                       '/deployments/', 'Deployments');
	}
	return $items;
}

/* An app that should run but does not (crashed, failed to start, not listening). */
function health_check_apps_running() {
	$items = array();
	if (!deploy_enabled())
		return $items;
	foreach (app_list() as $a) {
		if (!(int)$a['enabled'])
			continue;
		$st = app_status($a);
		if ($st['state'] === 'active' && $st['listening'])
			continue;
		sleep(5);                       // a restart in progress is not a problem
		$st = app_status($a);
		if ($st['state'] === 'active' && $st['listening'])
			continue;
		$items[] = health_item('app', $a['account'], $a['domain'], 'critical',
		                       $st['state'] === 'active' ? 'The app of '.$a['domain'].' does not answer on its port'
		                                                 : 'The app of '.$a['domain'].' is not running',
		                       $st['state'] === 'active' ? 'It runs, but nothing listens on 127.0.0.1:'.$a['port'].' - the start command must use $PORT. The site answers 502.'
		                                                 : 'Its state is '.$st['state'].($st['restarts'] ? ' after '.$st['restarts'].' restarts' : '').'; the site answers 502. Its log says why.',
		                       '/deployments/', 'Deployments');
	}
	return $items;
}

/* WordPress core behind the latest release (wordpress.org, checked once a day),
   and plugin updates the site reported the last time it was opened in WP Toolkit. */
function health_check_wordpress() {
	global $db;
	$latest = health_latest('wordpress', function () {
		$j = json_decode(health_http('https://api.wordpress.org/core/version-check/1.7/'), true);
		return $j['offers'][0]['current'] ?? '';
	});
	$items = array();
	$res = $db->query("SELECT user, domain, path, wp_version FROM wordpress WHERE status = 'active' ORDER BY user");
	$users = array();
	while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
		$site = $r['domain'].($r['path'] !== '' ? '/'.trim($r['path'], '/') : '');
		$users[$r['user']] = true;
		if ($latest !== '' && $r['wp_version'] !== '' && version_compare($r['wp_version'], $latest, '<'))
			$items[] = health_item('wordpress', $r['user'], $site, 'warning', "WordPress {$r['wp_version']} on $site (latest $latest)",
			                       'Older releases miss security fixes. Update it from WP Toolkit (wp-cli core update).',
			                       '/wp-toolkit/'.rawurlencode($r['user']).'/', 'WP Toolkit');
	}
	foreach (array_keys($users) as $u) {
		$file = wp_site_probe_file($u);
		$p = is_file($file) ? json_decode((string)@file_get_contents($file), true) : null;
		$n = is_array($p) ? (int)($p['plugins_updates'] ?? 0) : 0;
		if ($n > 0)
			$items[] = health_item('wordpress', $u, 'plugins', 'info', "$n plugin update".($n == 1 ? '' : 's').' for '.$u."'s WordPress",
			                       'As of the site check on '.date('j M Y H:i', filemtime($file)).'.',
			                       '/wp-toolkit/'.rawurlencode($u).'/', 'WP Toolkit');
	}
	/* auto-updates (wordpress_auto.php) whose last 3 runs failed */
	if (function_exists('wordpress_auto_ready') && wordpress_auto_ready()) {
		$res = $db->query('SELECT a.site_id, a.fails, a.last_message, w.user, w.domain, w.path FROM wordpress_auto a JOIN wordpress w ON w.id = a.site_id WHERE a.fails >= 3');
		while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
			$site = $r['domain'].($r['path'] !== '' ? '/'.trim($r['path'], '/') : '');
			$items[] = health_item('wordpress', $r['user'], 'auto:'.$site, 'warning', "Auto-updates of $site failed {$r['fails']} times in a row",
			                       mb_substr((string)$r['last_message'], 0, 300), '/wp-toolkit/'.rawurlencode($r['user']).'/', 'WP Toolkit');
		}
	}
	return $items;
}

/* / and /home (and any other mount they sit on): 90% = warning, 95% = critical. */
function health_check_disk_space() {
	$items = array();
	$seen  = array();
	foreach (array('/', '/home', '/var') as $path) {
		$st = @stat($path);
		if (!$st || isset($seen[$st['dev']])) continue;
		$seen[$st['dev']] = true;
		$total = @disk_total_space($path); $free = @disk_free_space($path);
		if (!$total) continue;
		$pct = round(($total - $free) * 100 / $total);
		if ($pct < 90) continue;
		$items[] = health_item('disk_space', '', $path, $pct >= 95 ? 'critical' : 'warning', "Disk $path is $pct% full",
		                       round($free / 1073741824, 1).' GB free. When it fills up, mail, databases and backups stop.');
	}
	return $items;
}

/* The units in server-software.ini services= that are installed but not running. */
function health_check_services() {
	global $ini;
	$units = array_filter(array_map('trim', explode(',', $ini['services'] ?? '')));
	$units = array_values(array_filter($units, function ($u) { return preg_match('/^[A-Za-z0-9@._-]+$/', $u); }));
	if (!$units) return array();
	$out = (string)shell_exec('systemctl show -p Id,LoadState,ActiveState,SubState '.implode(' ', array_map(function ($u) {
		return escapeshellarg(strpos($u, '.') === false ? $u.'.service' : $u); }, $units)).' 2>/dev/null');
	$items = array();
	foreach (preg_split('/\n\n+/', trim($out)) as $block) {
		$p = array();
		foreach (explode("\n", $block) as $l)
			if (strpos($l, '=') !== false) { list($k, $v) = explode('=', $l, 2); $p[$k] = $v; }
		if (($p['LoadState'] ?? '') !== 'loaded' || ($p['ActiveState'] ?? '') === 'active') continue;
		$name = preg_replace('/\.service$/', '', $p['Id'] ?? '?');
		$items[] = health_item('services', '', $name, 'critical',
		                       "Service $name ".(($p['ActiveState'] ?? '') === 'failed' ? 'has failed' : 'is not running'),
		                       'State: '.($p['ActiveState'] ?? '?').'/'.($p['SubState'] ?? '?').'. The journal says why: journalctl -u '.$name,
		                       '/services/', 'Services');
	}
	return $items;
}

/* The nightly remote backup (Backup > Remote): not set up = info; the last run
   had failures = warning; no successful run for 2 days = critical. */
function health_check_backup() {
	$cron = (string)@file_get_contents('/etc/crontab');
	if (!preg_match('/^[^#\n]*backup_remote\.sh/m', $cron))
		return array(health_item('backup', '', 'remote', 'info', 'No nightly backup is set up',
		                         'Nothing copies the accounts to another server. Backup > Remote sets one up.', '/backup/', 'Backup'));
	$log = _PATH.'/log/backup_remote.log';
	$last_ok = 0; $last = '';
	foreach (array_reverse(explode("\n", (string)shell_exec('grep -a " DONE" '.escapeshellarg($log).' 2>/dev/null | tail -n 30'))) as $l) {
		if (!preg_match('/^\[(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)\] DONE( with \d+ failure)?/', $l, $m)) continue;
		if ($last === '') $last = $l;
		if (empty($m[2])) { $last_ok = strtotime($m[1]); break; }
	}
	$items = array();
	if ($last !== '' && strpos($last, 'DONE with') !== false)
		$items[] = health_item('backup', '', 'failures', 'warning', 'The last nightly backup had failures',
		                       trim(preg_replace('/^\[[^\]]+\]\s*/', '', $last)).' - see log/backup_remote.log.', '/backup/', 'Backup');
	if ($last_ok < time() - 2 * 86400)
		$items[] = health_item('backup', '', 'age', 'critical',
		                       $last_ok ? 'No successful backup since '.date('j M Y', $last_ok) : 'No nightly backup has finished yet',
		                       'The nightly remote backup is scheduled but has not completed. See log/backup_remote.log.', '/backup/', 'Backup');
	return $items;
}

/* The server's IPv4 on the big mail blocklists. A 127.255.255.x answer means
   the list refuses this resolver (Spamhaus and public DNS) - not checked then. */
function health_check_rbl() {
	global $ini;
	if (!feature_enabled($ini, 'email'))
		return array();
	$ip = server_primary_ip();
	if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))
		return array();
	$rev = implode('.', array_reverse(explode('.', $ip)));
	$items = array();
	foreach (array('zen.spamhaus.org' => 'https://check.spamhaus.org/', 'bl.spamcop.net' => 'https://www.spamcop.net/bl.shtml',
	               'b.barracudacentral.org' => 'https://www.barracudacentral.org/lookups') as $zone => $site) {
		$a = health_dig('A', $rev.'.'.$zone);
		$a = array_filter($a, function ($r) { return strpos($r, '127.') === 0 && strpos($r, '127.255.255.') !== 0; });
		if ($a)
			$items[] = health_item('rbl', '', $zone, 'critical', "Server IP $ip is listed on $zone",
			                       "Mail from this server may be rejected. The reason and the delisting form: $site");
	}
	return $items;
}

function health_check_mail_queue() {
	global $ini;
	if (!feature_enabled($ini, 'email'))
		return array();
	$n = mq_count();
	if ($n < 200)
		return array();
	return array(health_item('mail_queue', '', 'queue', $n >= 1000 ? 'critical' : 'warning', number_format($n).' messages wait in the mail queue',
	                         'A full queue usually means a remote server refuses our mail, or an account sends spam.', '/email/', 'Mail queue'));
}

/* Root's mail without a root: alias (forwarding off in Settings): kept in
   /var/log/reqad/root-mail by the root_mail_log router (setup_root_mail_log.sh).
   Without that router or a writable file it has nowhere to go and piles up in
   the queue. Runs as root, so exim.conf is readable. */
function health_check_root_mail() {
	if (!is_file('/etc/exim/exim.conf') || root_mail_has_alias())
		return array();
	$log  = '/var/log/reqad/root-mail';
	$conf = (string)@file_get_contents('/etc/exim/exim.conf');
	if (!preg_match('/^root_mail_log:\s*$/m', $conf) || !is_file($log) || (fileowner($log) !== (posix_getpwnam('reqad')['uid'] ?? -1)))
		return array(health_item('root_mail', '', 'log', 'warning', 'Mail for root has nowhere to go',
		                         'Root mail is not forwarded and the root mail log is not set up, so cron, firewall and monitoring mail waits in the queue until it expires. Run scripts/update/setup_root_mail_log.sh, or turn on forwarding in Settings.',
		                         '/settings/', 'Settings'));
	return array(health_item('root_mail', '', 'forward', 'info', 'Mail for root is not forwarded',
	                         'Cron, firewall and monitoring mail sent to root stays on this server in /var/log/reqad/root-mail (at most 20 MB, the oldest is dropped). Set a sending method and turn on forwarding to get it by email; Settings > System mail shows the latest.',
	                         '/settings/', 'Settings'));
}

/* The dashboard has its own reboot alert with the reboot form. */
function health_check_reboot() {
	if (!is_executable('/usr/bin/needs-restarting'))
		return array();
	exec('/usr/bin/needs-restarting -r >/dev/null 2>&1', $o, $rc);
	if ($rc !== 1)
		return array();
	return array(health_item('reboot', '', 'kernel', 'warning', 'Reboot needed to finish an update',
	                         'A kernel or core library was updated; the running system still uses the old one.', '/', 'Dashboard', false));
}

/* DNS cluster (app/functions/dns_cluster.php): a secondary that stopped
   answering or copying, zones left out of the catalog, pdns.conf without the
   cluster's block. Uses what `reqad dns check` (every 5 minutes,
   dns_cluster_health.php) kept: a secondary counts as down after 10 minutes,
   as behind after 15 (a zone changed seconds ago is behind for a moment).
   Without fresh results it checks itself, looking at problems again after 30 s. */
function health_check_dns_cluster() {
	if (!function_exists('dns_cluster_on') || !dns_cluster_on())
		return array();
	$out = array();
	$link = '/dns-settings/cluster/';
	$c = dns_cluster_conf();
	if (empty($c['managed']) && !isset($c['error']))
		$out[] = health_item('dns_cluster', '', 'conf', 'critical', 'PowerDNS no longer sends the zones to the secondaries',
			'The DNS cluster block is missing from /etc/pdns/pdns.conf (edited by hand, or replaced by an update). Settings > DNS Settings > Cluster writes it again.', $link, 'DNS cluster');
	$h = dns_cluster_health_load();
	$now = time();
	if ($h['checked'] && $now - $h['checked'] <= 900) {
		foreach (dns_cluster_secondaries() as $x) {
			$l = $h['last'][$x['name']] ?? null;
			if (!$l)
				continue;
			$for = $now - (int)$l['since'];
			if ($l['state'] === 'd' && $for >= 540)
				$out[] = health_item('dns_cluster', '', 'down-'.$x['name'], 'critical', "The secondary DNS server {$x['name']} does not answer",
					"No reply from {$x['addr']} on port 53 for ".dns_cluster_health_ago($for).': when this server is down too, the domains whose name servers point there do not resolve.', $link, 'DNS cluster');
			elseif ($l['state'] === 'b' && $for >= 840)
				$out[] = health_item('dns_cluster', '', 'behind-'.$x['name'], 'warning', "The secondary {$x['name']} is missing changes",
					'For '.dns_cluster_health_ago($for).': '.dns_cluster_health_list(array_merge((array)$l['behind'], (array)$l['missing']), !empty($l['late']))
					.'. Check that it can reach this server on port 53 (TCP).', $link, 'DNS cluster');
		}
		$left = $h['out'];
	} else {
		if ($h['checked'])
			$out[] = health_item('dns_cluster', '', 'check', 'warning', 'The DNS cluster has not been checked since '.date('Y-m-d H:i', $h['checked']),
				'reqad dns check runs every 5 minutes from /etc/cron.d/reqad; the line is missing or the check fails. Run it by hand to see why: /usr/local/reqad/bin/reqad dns check', $link, 'DNS cluster');
		$check = dns_cluster_check();
		$bad = array_filter($check, function ($s) { return !$s['answering'] || $s['behind'] || $s['missing']; });
		if ($bad) {
			sleep(30);
			$check = dns_cluster_check();
		}
		foreach ($check as $s) {
			if (!$s['answering'])
				$out[] = health_item('dns_cluster', '', 'down-'.$s['name'], 'critical', "The secondary DNS server {$s['name']} does not answer",
					"No reply from {$s['addr']} on port 53: when this server is down too, the domains whose name servers point there do not resolve.", $link, 'DNS cluster');
			elseif ($s['behind'] || $s['missing'])
				$out[] = health_item('dns_cluster', '', 'behind-'.$s['name'], 'warning', "The secondary {$s['name']} is missing changes",
					dns_cluster_health_list(array_merge(array_keys($s['behind']), $s['missing']), false)
					.'. Check that it can reach this server on port 53 (TCP).', $link, 'DNS cluster');
		}
		$left = dns_cluster_zones_out();
	}
	if ($left)
		$out[] = health_item('dns_cluster', '', 'out', 'warning', (count($left) === 1 ? 'A zone is' : count($left).' zones are').' not copied to the secondaries',
			'Not in the cluster: '.implode(', ', array_slice($left, 0, 5)).(count($left) > 5 ? ' ...' : '').'. Settings > DNS Settings > Cluster adds them.', $link, 'DNS cluster');
	return $out;
}

/* DNS API keys (app/functions/dns_keys.php): another server keeps its zones
   here with a key; when the key expires that server can no longer change
   them, and nothing on it says why. Zones of a revoked key wait for a new key. */
function health_check_dns_keys() {
	if (!function_exists('dns_api_enabled') || !function_exists('api_tokens_list') || !dns_api_enabled())
		return array();
	$out = array();
	$own = dns_owner_map();
	foreach (dns_keys_list() as $k) {
		foreach ($k['zones'] as $z)
			if (isset($own[$z]))
				$out[] = health_item('dns_keys', '', 'shared-'.$z, 'warning', "$z is both a domain of {$own[$z]} and a zone of the DNS API key {$k['name']}",
					"The account here and the key's server both change its records. Take the zone away from the key (or move the site) so only one of them does.", '/dns-settings/keys/#key-'.$k['id'], 'DNS API keys');
		$n = count($k['zones']);
		$zones = $n === 1 ? 'its zone' : "its $n zones";
		$left = (int)$k['expires'] ? (int)ceil(((int)$k['expires'] - time()) / 86400) : 0;
		if ($k['state'] === 'active' && (int)$k['expires'] && $left <= 14)
			$out[] = health_item('dns_keys', '', 'expires-'.$k['id'], 'warning', "DNS API key {$k['name']} expires in $left day".($left === 1 ? '' : 's'),
				"Its server can then no longer change $zones here. Make a new key, put it in that server's DNS Settings and move the zones to it.", '/dns-settings/keys/', 'DNS API keys');
		elseif ($k['state'] === 'expired' && $n)
			$out[] = health_item('dns_keys', '', 'expired-'.$k['id'], 'warning', "DNS API key {$k['name']} expired",
				"Its server can no longer change $zones here since ".date('Y-m-d', (int)$k['expires']).". Make a new key, put it in that server's DNS Settings and move the zones to it.", '/dns-settings/keys/', 'DNS API keys');
		elseif ($k['state'] === 'revoked' && $n)
			$out[] = health_item('dns_keys', '', 'revoked-'.$k['id'], 'info', ($n === 1 ? 'A zone belongs' : "$n zones belong")." to the revoked DNS API key {$k['name']}",
				'No server can change '.($n === 1 ? 'it' : 'them').' through the API. Move them to another key, or remove them under DNS Zones if they are no longer used.', '/dns-settings/keys/', 'DNS API keys', false);
	}
	return $out;
}

/* Resource usage (app/functions/resources.php): the check every minute
   stopped, or never ran because cron has no line for it. */
function health_check_resources_collect() {
	if (!function_exists('resources_meta_get'))
		return array();
	$last = (int)resources_meta_get('collected', '0');
	$cron = (string)@file_get_contents('/etc/cron.d/reqad');
	if ($last && $last < time() - 900)
		return array(health_item('resources_collect', '', 'collect', 'warning', 'Resource usage is not being checked',
			'The last check of the accounts\' CPU, memory and processes was on '.date('Y-m-d H:i', $last).'. Run reqad resources collect to see why; cron runs it every minute.', '/resources/', 'Resource Usage', true));
	if (!$last && strpos($cron, 'resources collect') === false)
		return array(health_item('resources_collect', '', 'cron', 'warning', 'Resource usage is never checked',
			'/etc/cron.d/reqad has no line for reqad resources collect (a changed copy was kept at an update; compare it with /etc/cron.d/reqad.rpmnew).', '/resources/', 'Resource Usage', true));
	return array();
}

/* Visitor stats (app/functions/stats.php): the hourly count stopped, or never
   started (an /etc/cron.d/reqad kept from before, the update left a .rpmnew),
   and a country database that no longer gets its monthly update. */
function health_check_stats() {
	if (!function_exists('stats_counting') || !stats_counting())
		return array();
	$items = array();
	$last = (int)stats_meta_get('collected', '0');
	$cron = (string)@file_get_contents('/etc/cron.d/reqad');
	if ($last && $last < time() - 3 * 3600)
		$items[] = health_item('stats', '', 'collect', 'warning', 'Visitor stats are not being counted',
			'The last count of the sites\' logs was on '.date('Y-m-d H:i', $last).'. Run reqad stats collect to see why; cron runs it every hour.', '/bandwidth/', 'Bandwidth Usage', true);
	elseif (!$last && strpos($cron, 'stats collect') === false)
		$items[] = health_item('stats', '', 'cron', 'warning', 'Visitor stats are never counted',
			'/etc/cron.d/reqad has no line for reqad stats collect (a changed copy was kept at an update; compare it with /etc/cron.d/reqad.rpmnew).', '/bandwidth/', 'Bandwidth Usage', true);
	$g = stats_geo_file();
	if (setting_get('stats-geoip-update', '1') !== '0' && (!$g || $g[1] !== 'DB-IP Lite' || $g[2] < date('Y-m-d', strtotime('-70 days'))))
		$items[] = health_item('stats', '', 'geoip', 'info', 'The country database is out of date',
			($g ? $g[1].' from '.$g[2] : 'There is none').': the monthly DB-IP update has not worked. Try reqad stats geoip --update.', '/bandwidth/', 'Bandwidth Usage', true);
	return $items;
}

/* Symbolic links to other accounts' files (scripts/update/setup_symlink_protection.sh):
   nginx without its protection, and .htaccess files Apache refuses since it is on
   (they ask for FollowSymLinks; the site answers 500). */
function health_check_symlinks() {
	$items = array();
	$fix = _PATH.'/scripts/update/setup_symlink_protection.sh';
	if (web_front() === 'nginx' && !is_file('/etc/nginx/conf.d/00-reqad-symlinks.conf'))
		$items[] = health_item('symlinks', '', 'nginx', 'warning', 'Symbolic link protection is off',
			'nginx follows any symbolic link in a site, so an account could link another account\'s files into its own site and download them. Turn it on with '.$fix.' (it runs on every Reqad update).',
			'', '', true);
	if (php_mode() === 'fpm' && web_php_server() === 'httpd') {
		$log = (string)shell_exec('sudo -n tail -n 20000 /var/log/httpd/error_log 2>/dev/null');
		$since = time() - 86400;
		$files = array();
		foreach (explode("\n", $log) as $line) {
			if (stripos($line, 'FollowSymLinks not allowed here') === false
			    || !preg_match('#(/home/[^:\s]+/\.htaccess):#', $line, $m))
				continue;
			if (preg_match('/^\[([A-Z][a-z]{2} [A-Z][a-z]{2} \d+ [\d:.]+ \d{4})\]/', $line, $t)
			    && ($ts = strtotime(preg_replace('/\.\d+/', '', $t[1]))) && $ts < $since)
				continue;
			$files[$m[1]] = true;
		}
		if ($files)
			$items[] = health_item('symlinks', '', 'htaccess', 'warning',
				count($files).' .htaccess file'.(count($files) == 1 ? '' : 's').' refused by Apache',
				'They ask for FollowSymLinks, which sites may no longer turn on, so these folders answer 500: '.implode(', ', array_keys($files))
				.'. '.$fix.' --htaccess changes it to SymLinksIfOwnerMatch, which rewrite rules accept the same way.', '', '', true);
	}
	return $items;
}

/* certbot's Apache plugin put a site's https vhost into <site>-le-ssl.conf
   (the vhost had no :443 block). The panel reads and changes only <site>.conf,
   so suspension, redirects, PHP settings and removing the site miss https.
   The panel merges them after its own certbot runs and on every update; what
   is left came from certbot run by hand, or could not be merged. Server items:
   the fix is root's. */
function health_check_vhost_split() {
	$items = array();
	$fix = _PATH.'/scripts/update/merge_le_ssl_vhosts.sh';
	foreach (glob('/etc/httpd/conf.d/*-le-ssl.conf') ?: array() as $le) {
		$site = substr(basename($le), 0, -strlen('-le-ssl.conf'));
		if (!is_file('/etc/httpd/conf.d/'.$site.'.conf'))
			$items[] = health_item('vhost_split', '', $site, 'warning', "Leftover https setup of $site",
				"$le has no $site.conf next to it, so it is not a site of the panel any more, but Apache still serves it over https. Remove it if $site is gone, then reload Apache.",
				'', '', true);
		else
			$items[] = health_item('vhost_split', '', $site, 'warning', "The https setup of $site is in a file of its own",
				"certbot put it in $le. The panel changes only $site.conf, so suspension, redirects and PHP settings miss https there. Run $fix as root to move it into $site.conf.",
				'', '', true);
	}
	return $items;
}

/* root_access=0 allows no full shell, but an account still has one (the ini
   was changed after the last update, or the shell was set outside Reqad).
   Server items (one per account) so the account panel does not show them. */
function health_check_shell() {
	global $db;
	if (account_shell_full_allowed())
		return array();
	$items = array();
	foreach (health_accounts($db) as $a)
		if (account_shell_get($a['user']) === 'full')
			$items[] = health_item('shell', '', $a['user'], 'warning', $a['user'].' still has full shell access',
				'Full shell access is off on this server (root_access=0 in server-software.ini). Choose Jailed or None in Accounts > Manage, or run '
				._PATH.'/scripts/account_shell.sh restrict (it runs on every Reqad update) to move every such account to the jailed shell.',
				'/accounts/', 'Accounts');
	return $items;
}

/* Shell activity (app/functions/shelllog.php): an account ran something with
   a red flag in the last 7 days (one server item per account, its id names
   the newest flagged row: a dismissal lasts until something new is flagged;
   the account panel never shows server items), or the logging itself stopped. */
function health_check_shell_log() {
	if (!function_exists('shelllog_enabled') || !shelllog_enabled())
		return array();
	$items = array();
	$st = shelllog_status();
	$cron = (string)@file_get_contents('/etc/cron.d/reqad');
	if ($st['rule_error'] !== '')
		$items[] = health_item('shell_log', '', 'rule', 'warning', 'Commands of hosting accounts are not being logged',
			$st['rule_error'], '/security/#shell-log', 'Settings > Security', true);
	if ($st['collected'] && $st['collected'] < time() - 900)
		$items[] = health_item('shell_log', '', 'collect', 'warning', 'The commands accounts run are not being read',
			'The audit log was last read on '.date('Y-m-d H:i', $st['collected']).'. Run reqad shelllog collect to see why; cron runs it every minute.', '/shell-activity/', 'Shell Activity', true);
	elseif (!$st['collected'] && strpos($cron, 'shelllog collect') === false)
		$items[] = health_item('shell_log', '', 'cron', 'warning', 'The commands accounts run are never read',
			'/etc/cron.d/reqad has no line for reqad shelllog collect (a changed copy was kept at an update; compare it with /etc/cron.d/reqad.rpmnew).', '/shell-activity/', 'Shell Activity', true);
	$sdb = shelllog_db();
	if (!$sdb)
		return $items;
	$q = $sdb->query("SELECT user, COUNT(*) AS n, MAX(id) AS id FROM shell_cmds WHERE flag != '' AND src != 'admin' AND last >= ".(time() - 7 * 86400)." GROUP BY user");
	$get = $sdb->prepare('SELECT first, cmd, flag FROM shell_cmds WHERE id = :id');
	while ($q && ($x = $q->fetchArray(SQLITE3_ASSOC))) {
		$get->bindValue(':id', (int)$x['id'], SQLITE3_INTEGER);
		$r = $get->execute()->fetchArray(SQLITE3_ASSOC);
		$get->reset();
		if (!$r) continue;
		$items[] = health_item('shell_log', '', $x['user'].'-'.(int)$x['id'],
			in_array($r['flag'], array('reverse_shell', 'miner'), true) ? 'critical' : 'warning',
			$x['user'].' ran something that looks like abuse',
			(int)$x['n'].' command'.((int)$x['n'] === 1 ? '' : 's').' with a red flag in the last 7 days. The newest, '.date('M j H:i', (int)$r['first']).': '
			.shelllog_flag_label($r['flag']).' - '.mb_strimwidth((string)$r['cmd'], 0, 160, '…'),
			'/shell-activity/'.rawurlencode($x['user']).'/?flagged=1', 'Shell Activity', true);
	}
	return $items;
}

/* Roundcube (reachable by every mailbox owner) and phpMyAdmin behind their latest release. */
function health_check_apps() {
	global $ini;
	$items = array();
	$rc_file = _PATH.'/roundcubemail/program/include/iniset.php';
	if (feature_enabled($ini, 'email') && is_file($rc_file)
	    && preg_match("/define\('RCMAIL_VERSION',\s*'([^']+)'/", (string)file_get_contents($rc_file), $m)) {
		$latest = health_latest('roundcube', function () { return health_github_latest('roundcube/roundcubemail'); });
		if ($latest !== '' && version_compare($m[1], $latest, '<'))
			$items[] = health_item('apps', '', 'roundcube', 'warning', "Webmail (Roundcube {$m[1]}) is out of date (latest $latest)",
			                       'Every mailbox owner can reach webmail, so its fixes matter. Update: bash '._PATH.'/scripts/update/update_roundcube.sh');
	}
	$pma = _PATH.'/public_html/phpmyadmin/package.json';
	$j = is_file($pma) ? json_decode((string)file_get_contents($pma), true) : null;
	if (is_array($j) && !empty($j['version'])) {
		$latest = health_latest('phpmyadmin', function () {
			return str_replace('_', '.', preg_replace('/^RELEASE_/', '', health_github_latest('phpmyadmin/phpmyadmin')));
		});
		if ($latest !== '' && version_compare($j['version'], $latest, '<'))
			$items[] = health_item('apps', '', 'phpmyadmin', 'warning', "phpMyAdmin {$j['version']} is out of date (latest $latest)",
			                       'Update: bash '._PATH.'/scripts/update/update_phpmyadmin.sh');
	}
	return $items;
}

/* From the dnf cache (refreshed every 6 hours by cron.d/reqad); the dashboard
   has its own "Update available" alert with the update button. */
function health_check_reqad_update() {
	/* timeout: dnf waits for its lock while an update runs */
	exec('timeout 60 dnf -q --cacheonly check-update reqad 2>/dev/null', $out, $rc);
	if ($rc !== 100)
		return array();
	$ver = '';
	foreach ($out as $l)
		if (preg_match('/^reqad\.\S+\s+(\S+)/', $l, $m)) $ver = $m[1];
	return array(health_item('reqad', '', 'update', 'info', 'Reqad update available'.($ver !== '' ? " ($ver)" : ''),
	                         'Update from the dashboard or with: dnf update reqad', '/', 'Dashboard', false));
}
