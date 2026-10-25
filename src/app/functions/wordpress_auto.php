<?php
/*
 * WordPress auto-updates (WP Toolkit > Auto-updates, both panels, the API,
 * `reqad wordpress auto`). Table wordpress_auto (db/1051.sql): no row = off.
 *
 *   wordpress_auto_status($row)          the settings, what WordPress itself
 *                                        does, the plugins and themes to choose from
 *   wordpress_auto_set($row, $o)         save the settings
 *   wordpress_auto_run_due()             start the runs that are due (cron, every
 *                                        15 minutes; at most 2 sites at a time)
 *   wordpress_auto_update($row, $job)    one run, inside the job's unit
 *
 * A run is a wordpress_jobs row (by_user 'auto') in the unit reqad-wp-<site>,
 * like an update from the Updates page: the site is read fresh, updates that
 * need a newer PHP are left out, releases younger than the chosen wait are
 * left for a later run, the database is saved, the plugin and theme folders
 * about to change are copied (as the account) to a hidden folder in
 * ~/wp-backups, the updates run, and the home page is fetched before and
 * after: if it opened before and fails after, those folders are put back.
 * WordPress itself is not put back (that needs a full file backup); the
 * message says so. The account is mailed what was done (kind 'updates').
 * While the panel updates something, a must-use plugin (reqad-auto-updates.php)
 * switches WordPress's own automatic updates of it off, so the two never run
 * at the same time; translations still update by themselves.
 */

function wordpress_auto_ready() {
	global $db;
	return (bool)$db->querySingle("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'wordpress_auto'");
}

/* The settings of an install (defaults when it has none). */
function wordpress_auto_get($site_id) {
	global $db;
	$r = wordpress_auto_ready() ? $db->querySingle('SELECT * FROM wordpress_auto WHERE site_id = '.(int)$site_id, true) : null;
	$a = ($r ?: array()) + array('site_id' => (int)$site_id, 'core' => 'off', 'plugins' => 'off', 'plugin_list' => '[]', 'themes' => 'off',
	                             'theme_list' => '[]', 'weekday' => -1, 'hour' => 3, 'delay' => 0, 'next_run' => 0, 'last_run' => 0,
	                             'last_status' => '', 'last_message' => '', 'fails' => 0, 'updated' => 0);
	foreach (array('plugin_list', 'theme_list') as $k) {
		$l = json_decode((string)$a[$k], true);
		$a[$k] = is_array($l) ? array_values(array_filter($l, 'is_string')) : array();
	}
	foreach (array('weekday', 'hour', 'delay', 'next_run', 'last_run', 'fails', 'updated') as $k)
		$a[$k] = (int)$a[$k];
	return $a;
}

/* Does the panel update anything of this install by itself */
function wordpress_auto_on(array $a) {
	return $a['core'] !== 'off' || $a['plugins'] !== 'off' || $a['themes'] !== 'off';
}

/* The next run after $from: the chosen hour (server time) on the chosen day,
   at a minute that depends on the site so that sites do not all start together. */
function wordpress_auto_next(array $a, $from = null) {
	$from = $from ?? time();
	$tz = new DateTimeZone(system_timezone() ?: 'UTC');
	$d = new DateTime('@'.$from);
	$d->setTimezone($tz);
	$d->setTime((int)$a['hour'], ((int)$a['site_id'] * 7) % 60, 0);
	for ($i = 0; $i < 9; $i++) {
		if ($d->getTimestamp() > $from && ((int)$a['weekday'] < 0 || (int)$d->format('w') === (int)$a['weekday']))
			return $d->getTimestamp();
		$d->modify('+1 day');
		$d->setTime((int)$a['hour'], ((int)$a['site_id'] * 7) % 60, 0);
	}
	return $from + 86400;
}

/* What WordPress itself updates on its own, read from the site (its options and constants). */
function wordpress_auto_own(array $row) {
	$code = <<<'EOT'
$off = defined('AUTOMATIC_UPDATER_DISABLED') && AUTOMATIC_UPDATER_DISABLED;
if (defined('WP_AUTO_UPDATE_CORE'))
	$core = WP_AUTO_UPDATE_CORE === true ? 'all' : (WP_AUTO_UPDATE_CORE === false ? 'off' : 'minor');
else
	$core = get_site_option('auto_update_core_major') === 'enabled' ? 'all' : 'minor';
$mu = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR.'/mu-plugins';
echo "\n##REQAD##".json_encode(array('core' => $off ? 'off' : $core, 'disabled' => $off,
	'plugins' => array_values((array)get_site_option('auto_update_plugins', array())),
	'themes' => array_values((array)get_site_option('auto_update_themes', array())),
	'ours' => is_file($mu.'/reqad-auto-updates.php'), 'mu_dir' => $mu));
EOT;
	$r = wordpress_cli($row, 'eval '.escapeshellarg($code).' --skip-themes --skip-plugins --path='.escapeshellarg(wordpress_docroot($row)), 60);
	$pos = strrpos($r['out'], '##REQAD##');
	$d = $pos !== false ? json_decode(substr($r['out'], $pos + 9), true) : null;
	return is_array($d) ? $d : null;
}

/*
 * Everything the Auto-updates page shows: settings (core, plugins + list,
 * themes + list, weekday, hour, delay), the next and the last run, what
 * WordPress itself does (own), the plugins and themes (slug, name, active)
 * and the server's time zone.
 */
function wordpress_auto_status(array $row) {
	$a = wordpress_auto_get($row['id']);
	$info = wordpress_info($row);
	if (!$info['ok'])
		return result_error($info['error'], array('settings' => $a));
	$own = wordpress_auto_own($row);
	$pick = function ($list) {
		$out = array();
		foreach ($list as $p)
			$out[] = array('slug' => $p['slug'], 'name' => $p['name'] !== '' ? $p['name'] : $p['slug'], 'active' => (bool)$p['active']);
		return $out;
	};
	return result_ok('', array('site' => wordpress_public($row), 'settings' => array_diff_key($a, array('updated' => 1)),
		'on' => wordpress_auto_on($a), 'own' => $own, 'plugins' => $pick($info['data']['plugins']), 'themes' => $pick($info['data']['themes']),
		'timezone' => system_timezone() ?: 'UTC', 'php' => $info['data']['php']));
}

/*
 * Save an install's auto-update settings. $o: core (off|minor|all), plugins
 * and themes (off|all|some), plugin_list / theme_list (folder names, with
 * some), weekday (-1 every day, 0-6 Sunday-Saturday), hour (0-23), delay
 * (days, 0-30). Missing keys keep their value.
 */
function wordpress_auto_set(array $row, array $o) {
	global $db;
	if (!wordpress_auto_ready())
		return result_error('The table for auto-updates is missing: apply db/1051.sql (the Reqad update does it).');
	$a = wordpress_auto_get($row['id']);
	foreach (array('core' => array('off', 'minor', 'all'), 'plugins' => array('off', 'all', 'some'), 'themes' => array('off', 'all', 'some')) as $k => $ok)
		if (isset($o[$k])) {
			if (!in_array((string)$o[$k], $ok, true))
				return result_error("$k: one of ".implode(', ', $ok).'.');
			$a[$k] = (string)$o[$k];
		}
	foreach (array('plugin_list', 'theme_list') as $k)
		if (isset($o[$k])) {
			$l = is_array($o[$k]) ? $o[$k] : preg_split('/[\s,]+/', (string)$o[$k], -1, PREG_SPLIT_NO_EMPTY);
			$l = array_values(array_unique(array_map('strval', $l)));
			foreach ($l as $it)
				if (!wordpress_valid_slug($it))
					return result_error("'$it' is not a plugin or theme name (the folder name, e.g. akismet).");
			if (count($l) > 200)
				return result_error('At most 200 plugins or themes.');
			$a[$k] = $l;
		}
	foreach (array('weekday' => array(-1, 6), 'hour' => array(0, 23), 'delay' => array(0, 30)) as $k => $range)
		if (isset($o[$k])) {
			if (!is_numeric($o[$k]) || (int)$o[$k] < $range[0] || (int)$o[$k] > $range[1])
				return result_error("$k: a number from {$range[0]} to {$range[1]}.");
			$a[$k] = (int)$o[$k];
		}
	if ($a['plugins'] === 'some' && !$a['plugin_list'])
		return result_error('Choose the plugins to update, or All.');
	if ($a['themes'] === 'some' && !$a['theme_list'])
		return result_error('Choose the themes to update, or All.');
	$a['next_run'] = wordpress_auto_on($a) ? wordpress_auto_next($a) : 0;
	$stmt = $db->prepare('INSERT OR REPLACE INTO wordpress_auto (site_id, core, plugins, plugin_list, themes, theme_list, weekday, hour, delay,
	                        next_run, last_run, last_status, last_message, fails, updated)
	                      VALUES (:id, :c, :p, :pl, :t, :tl, :w, :h, :d, :n, :lr, :ls, :lm, :f, :u)');
	foreach (array(':id' => (int)$row['id'], ':c' => $a['core'], ':p' => $a['plugins'], ':pl' => json_encode($a['plugin_list']), ':t' => $a['themes'],
	               ':tl' => json_encode($a['theme_list']), ':w' => $a['weekday'], ':h' => $a['hour'], ':d' => $a['delay'], ':n' => $a['next_run'],
	               ':lr' => $a['last_run'], ':ls' => $a['last_status'], ':lm' => $a['last_message'], ':f' => $a['fails'], ':u' => time()) as $k => $v)
		$stmt->bindValue($k, $v, is_int($v) ? SQLITE3_INTEGER : SQLITE3_TEXT);
	$stmt->execute();
	/* WordPress's own automatic updates off for what the panel now updates */
	$warn = array();
	if (($e = wordpress_auto_own_set($row, $a)) !== '')
		$warn[] = 'WordPress\'s own automatic updates could not be switched off: '.$e;
	$site = $row['domain'].(trim((string)$row['path'], '/') !== '' ? '/'.trim($row['path'], '/') : '');
	log_debug('[wp] '.auth_user().' auto-updates of '.$site.': core '.$a['core'].', plugins '.$a['plugins'].', themes '.$a['themes']);
	plugin_hook('wordpress.auto', array('account' => $row['user'], 'domain' => $row['domain'], 'path' => trim((string)$row['path'], '/'),
	                                    'core' => $a['core'], 'plugins' => $a['plugins'], 'themes' => $a['themes']));
	$msg = wordpress_auto_on($a) ? 'Auto-updates of '.$site.' are saved. The next run: '.wordpress_auto_when($a['next_run']).'.'
	                            : 'Auto-updates of '.$site.' are off.';
	return result_ok($msg, array('settings' => wordpress_auto_get($row['id'])), $warn);
}

/* A run time as the server shows it */
function wordpress_auto_when($ts) {
	$d = new DateTime('@'.(int)$ts);
	$d->setTimezone(new DateTimeZone(system_timezone() ?: 'UTC'));
	return $d->format('D j M Y, H:i').' ('.(system_timezone() ?: 'UTC').')';
}

/* The must-use plugin that switches WordPress's own updates off for what the panel updates; gone when it updates nothing. */
function wordpress_auto_own_set(array $row, array $a) {
	$c = wordpress_tool_ctx($row);
	$rel = wordpress_mu_rel($c).'/reqad-auto-updates.php';
	if (!wordpress_auto_on($a)) {
		$r = files_delete($c['user'], array($rel));
		return $r['ok'] || ($r['data']['errors'][0]['error'] ?? '') === 'There is no such file or folder.' ? '' : $r['error'];
	}
	$body = "<?php\n/*\nPlugin Name: Auto-updates by the hosting panel (Reqad)\n"
	      ."Description: The hosting panel updates this site at the time chosen there, after saving the database and with a check that the site still opens, so WordPress's own automatic updates of the same things are off. Translations still update by themselves. Change it in the panel: WordPress Toolkit > Auto-updates.\n*/\n";
	if ($a['core'] !== 'off')
		$body .= "add_filter('auto_update_core', '__return_false');\n";
	if ($a['plugins'] !== 'off')
		$body .= "add_filter('auto_update_plugin', '__return_false');\nadd_filter('plugins_auto_update_enabled', '__return_false');\n";
	if ($a['themes'] !== 'off')
		$body .= "add_filter('auto_update_theme', '__return_false');\nadd_filter('themes_auto_update_enabled', '__return_false');\n";
	$m = files_mkdir($c['user'], dirname($rel));
	if (!$m['ok'] && ($m['data']['code'] ?? '') !== 'exists')
		return $m['error'];
	$r = files_save($c['user'], $rel, $body, null, true);
	if (!$r['ok'] && ($r['data']['code'] ?? '') === 'exists')
		$r = files_save($c['user'], $rel, $body, null, false, true);
	return $r['ok'] ? '' : $r['error'];
}

/* ---- the runs ---- */

/* Start the runs that are due (cron). Returns lines for the log. */
function wordpress_auto_run_due() {
	global $db;
	if (!wordpress_auto_ready())
		return array('the table wordpress_auto is missing (db/1051.sql)');
	$out = array();
	$res = $db->query('SELECT site_id FROM wordpress_auto WHERE next_run > 0 AND next_run <= '.time().' ORDER BY next_run');
	$due = array();
	while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC)))
		$due[] = (int)$r['site_id'];
	foreach ($due as $id) {
		$a = wordpress_auto_get($id);
		$row = wordpress_find($id);
		if (!$row) {
			$db->exec('DELETE FROM wordpress_auto WHERE site_id = '.$id);
			continue;
		}
		/* at most 2 sites at a time on the server; the others wait for the next round */
		exec(server_sudo()."systemctl list-units 'reqad-wp-*' --state=active --no-legend --plain 2>/dev/null", $units);
		if (count(array_filter($units, 'trim')) >= 2) {
			$out[] = 'two updates are running; the rest waits';
			break;
		}
		$units = array();
		if (!wordpress_auto_on($a) || (string)($row['status'] ?? 'active') !== 'active') {
			$db->exec('UPDATE wordpress_auto SET next_run = '.($a['next_run'] = wordpress_auto_on($a) ? wordpress_auto_next($a) : 0).' WHERE site_id = '.$id);
			continue;
		}
		if (function_exists('plugin_filter') && !plugin_filter('account.active', true, $row['user']))
			continue;
		$r = wordpress_job_start($row, 'auto', 'auto');
		$site = $row['domain'].(trim((string)$row['path'], '/') !== '' ? '/'.trim($row['path'], '/') : '');
		if ($r['ok']) {
			$db->exec('UPDATE wordpress_auto SET next_run = '.wordpress_auto_next($a).' WHERE site_id = '.$id);
			$out[] = "$site: started";
		} else
			/* an update from the page is running: try again in a quarter of an hour */
			$out[] = "$site: ".$r['error'];
	}
	return $out;
}

/* Is the site's home page fine: array(ok, http code, what is wrong). Fetched
   through this server (not DNS), past the page cache (a query string). */
function wordpress_site_check(array $row) {
	$url = wordpress_public($row)['url'];
	$h = parse_url($url, PHP_URL_HOST);
	$port = parse_url($url, PHP_URL_SCHEME) === 'http' ? 80 : 443;
	$tmp = tempnam(sys_get_temp_dir(), 'reqadchk');
	$code = (int)trim((string)shell_exec('curl -sk -o '.escapeshellarg($tmp).' -w "%{http_code}" --max-time 40 --resolve '.escapeshellarg($h.':'.$port.':127.0.0.1')
		.' '.escapeshellarg($url.'?reqad-check='.time()).' 2>/dev/null'));
	$body = (string)@file_get_contents($tmp, false, null, 0, 300000);
	@unlink($tmp);
	if ($code === 0)
		return array(false, 0, 'it does not answer');
	if (stripos($body, 'There has been a critical error on this website') !== false || preg_match('/<b>(Fatal|Parse) error<\/b>/i', $body))
		return array(false, $code, 'it shows a PHP error');
	if ($code >= 500 && $code !== 503)
		return array(false, $code, 'it answers with an error ('.$code.')');
	return array(true, $code, '');
}

/* The release date of a plugin's or theme's newest version on wordpress.org (0 = unknown, e.g. not from wordpress.org). */
function wordpress_release_time($kind, $slug, $version) {
	$url = $kind === 'plugins'
		? 'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]='.rawurlencode($slug).'&request[fields][versions]=0&request[fields][sections]=0'
		: 'https://api.wordpress.org/themes/info/1.2/?action=theme_information&request[slug]='.rawurlencode($slug).'&request[fields][sections]=0';
	$j = json_decode((string)shell_exec('curl -g -fsS --max-time 8 '.escapeshellarg($url).' 2>/dev/null'), true);   // -g: the [] in the address are not ranges
	if (!is_array($j) || (string)($j['version'] ?? '') !== (string)$version)
		return 0;
	$t = strtotime(str_replace(array('am GMT', 'pm GMT'), array('am UTC', 'pm UTC'), (string)($j['last_updated'] ?? '')));
	return $t ?: 0;
}

/*
 * One auto-update run of an install (inside its job's unit; $job = its id).
 * Result data: nothing (true when there was nothing to do), rolled_back,
 * backup, output - like wordpress_update_steps().
 */
function wordpress_auto_update(array $row, $job = 0) {
	$a = wordpress_auto_get($row['id']);
	$site = $row['domain'].(trim((string)$row['path'], '/') !== '' ? '/'.trim($row['path'], '/') : '');
	if (!wordpress_auto_on($a))
		return result_ok('Auto-updates are off for '.$site.'.', array('nothing' => true));
	$info = wordpress_info($row, true);
	if (!$info['ok'])
		return result_error('Nothing was updated on '.$site.'. '.$info['error']);
	$b = $info['data'];

	/* what to update */
	$steps = $waiting = array();
	if ($a['core'] !== 'off' && $b['core_updates']) {
		$branch = implode('.', array_slice(explode('.', $b['version']), 0, 2));
		$target = '';
		foreach ($b['core_updates'] as $v)
			if ($a['core'] === 'all' || implode('.', array_slice(explode('.', $v), 0, 2)) === $branch) {
				$target = $v;
				break;
			}
		if ($target !== '')
			$steps[] = array('core', array($target));
	}
	$copies = array();
	foreach (array('plugins', 'themes') as $kind) {
		if ($a[$kind] === 'off')
			continue;
		$list = $a[$kind === 'plugins' ? 'plugin_list' : 'theme_list'];
		$items = array();
		foreach ($b[$kind] as $p) {
			if ($p['update'] === null || !empty($p['needs']) || ($a[$kind] === 'some' && !in_array($p['slug'], $list, true)))
				continue;
			if ($a['delay'] > 0 && ($t = wordpress_release_time($kind, $p['slug'], $p['update'])) > time() - $a['delay'] * 86400) {
				$waiting[] = ($p['name'] ?: $p['slug']).' '.$p['update'];
				continue;
			}
			$items[] = $p['slug'];
			$copies[] = array($kind, $p);
		}
		if ($items)
			$steps[] = array($kind, $items);
	}
	$wait_msg = $waiting ? ' Waiting '.$a['delay'].' '.($a['delay'] === 1 ? 'day' : 'days').' after their release: '.implode(', ', $waiting).'.' : '';
	if (!$steps)
		/* nothing: the job row goes again (the settings keep the message) */
		return result_ok('Nothing to update on '.$site.'.'.$wait_msg, array('nothing' => true, 'waiting' => $waiting));

	/* the folders about to change, copied as the account, to put back if the site breaks */
	$home = '/home/'.$row['user'].'/';
	$rb = 'wp-backups/.rollback-'.(int)$row['id'].'-'.date('Ymd-His');
	$saved = array();
	foreach (array('wp-backups', $rb, $rb.'/plugins', $rb.'/themes') as $d) {
		$m = files_mkdir($row['user'], $d);
		if (!$m['ok'] && ($m['data']['code'] ?? '') !== 'exists')
			return result_error('Nothing was updated on '.$site.': the copy to put back could not be made ('.$m['error'].').');
	}
	foreach ($copies as $cp) {
		list($kind, $p) = $cp;
		$abs = $kind === 'plugins' ? rtrim((string)$b['plugin_dir'], '/').'/'.(dirname($p['file']) === '.' ? $p['file'] : dirname($p['file'])) : (string)$p['dir'];
		if (strpos($abs, $home) !== 0 || strpos($abs, '..') !== false)
			continue;
		$rel = substr($abs, strlen($home));
		$r = files_copy($row['user'], $rel, $rb.'/'.$kind);
		if (!$r['ok'])
			return result_error('Nothing was updated on '.$site.': '.$p['slug'].' could not be copied first ('.$r['error'].').');
		$saved[] = array('from' => $rb.'/'.$kind.'/'.basename($rel), 'to' => $rel, 'name' => ($p['name'] ?: $p['slug']));
	}

	list($was_ok, $was_code, ) = wordpress_site_check($row);
	$r = wordpress_update_steps($row, $steps);
	$data = $r['data'] + array('output' => '');
	$msg = $r['ok'] ? $r['message'] : $r['error'];
	$rolled = false;
	if ($was_ok) {
		list($ok, $code, $why) = wordpress_site_check($row);
		if (!$ok) {
			/* put the plugins and themes back */
			$back = $fail = array();
			foreach ($saved as $s) {
				files_delete($row['user'], array($s['to']));
				$mv = files_rename($row['user'], $s['from'], dirname($s['to']));
				if ($mv['ok']) $back[] = $s['name']; else $fail[] = $s['name'];
			}
			wordpress_purge_caches($row);
			list($ok2, , $why2) = wordpress_site_check($row);
			$rolled = true;
			$msg = 'After the update the site no longer opened ('.$why.')'.($back ? ', so these were put back as they were: '.implode(', ', $back) : '').'.'
			     .($fail ? ' Could not be put back: '.implode(', ', $fail).'.' : '')
			     .($ok2 ? ' The site opens again.' : ' The site still does not open ('.$why2.'): look at it now.'
			              .(in_array('core', array_column($steps, 0), true) ? ' WordPress itself was updated too and is not put back; the database was saved first to ~/'.substr((string)($data['backup'] ?? ''), strlen($home)).'.' : ''))
			     .' '.$msg;
			$data['output'] .= "\n\n# The home page failed after the update ($why): put back ".implode(', ', $back);
		}
	} else
		$msg .= ' (The home page did not open before the update either, so it was not checked afterwards.)';
	files_delete($row['user'], array($rb));
	$data['rolled_back'] = $rolled;
	$msg .= $wait_msg;
	return $r['ok'] && !$rolled ? result_ok($msg, $data, $r['warnings'] ?? array()) : ($rolled ? result_ok($msg, $data) : result_error($msg, $data));
}

/* After a run (wordpress_job_execute()): the settings' last run, the failure count, and the account's message. */
function wordpress_auto_done(array $row, array $r) {
	global $db;
	if (!wordpress_auto_ready())
		return;
	$a = wordpress_auto_get($row['id']);
	$status = !$r['ok'] ? 'failed' : (!empty($r['data']['rolled_back']) ? 'rolled_back' : (!empty($r['data']['nothing']) ? 'nothing' : 'ok'));
	$msg = $r['ok'] ? $r['message'] : $r['error'];
	$fails = $status === 'failed' ? $a['fails'] + 1 : 0;
	$stmt = $db->prepare('UPDATE wordpress_auto SET last_run = :t, last_status = :s, last_message = :m, fails = :f WHERE site_id = :id');
	$stmt->bindValue(':t', time(), SQLITE3_INTEGER);
	$stmt->bindValue(':s', $status, SQLITE3_TEXT);
	$stmt->bindValue(':m', mb_substr($msg, 0, 2000), SQLITE3_TEXT);
	$stmt->bindValue(':f', $fails, SQLITE3_INTEGER);
	$stmt->bindValue(':id', (int)$row['id'], SQLITE3_INTEGER);
	$stmt->execute();
	if ($status === 'nothing' || !function_exists('account_notify_send'))
		return;
	$site = $row['domain'].(trim((string)$row['path'], '/') !== '' ? '/'.trim($row['path'], '/') : '');
	$subject = $status === 'ok' ? "$site was updated" : ($status === 'rolled_back' ? "$site: an update was taken back" : "$site: the automatic update failed");
	account_notify_send($row['user'], 'updates', $subject, array(array($subject, $msg)), array(),
		'The automatic update of your WordPress site ran.');
}
