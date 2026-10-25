<?php
/*
 * WordPress sites for the REST API and the MCP tools (GET /api/v1/wordpress,
 * ...). Curated wp-cli operations only - never a raw wp-cli command line:
 * `wp eval`, `wp db query` or `wp shell` would run any code as the account.
 *
 *   wordpress_sites($account)            the tracked installs (WP Toolkit's list)
 *   wordpress_find($id)                  one install by id, domain or account
 *   wordpress_info($row, $refresh)       versions, plugins, themes and the
 *                                        updates WordPress knows about
 *   wordpress_update($row, $what, $items) update core, plugins or themes, after
 *                                        a database backup to ~/wp-backups/
 *   wordpress_plugin_set($row, $slug, $on)  activate / deactivate a plugin
 *   wordpress_purge_caches($row)         page cache, OPcache, object cache, cache
 *                                        plugins, Elementor CSS (after both above)
 *   wordpress_verify($row)               compare core and plugin files with
 *                                        the wordpress.org checksums
 *   wordpress_scan($account)             find the account's installs (in all its
 *                                        sites' folders) and update the list
 *   wordpress_databases($account)        the databases its sites use (DB_NAME),
 *                                        to warn before one is deleted
 *   wordpress_login($row)                a one-time link that signs in to the
 *                                        site's dashboard
 *   wordpress_install($account, $o)      a new WordPress in a site's folder,
 *                                        with its own database
 *   (copies of a site, staging or clone: wordpress_copy.php; Push to live: wordpress_push.php)
 *
 * Everything runs as the site's account (wordpress_cli(), on the site's PHP
 * version). Only the database backup is root's mysqldump - wp-cli's `db export`
 * needs exec(), which the command-line PHP disables - and its output is still
 * written by the account.
 */

/*
 * wp-cli as the site's account, on the site's own PHP version (plugins may
 * need a newer PHP than the server's default command-line one), the same way
 * the account's shell runs it. Returns array('out', 'rc') like wp_cli_run().
 */
function wordpress_cli(array $row, $args, $timeout = 900, $stdin = null) {
	global $ini;
	$v = wp_site_php_version($ini, $row['domain']);
	$bin = '/opt/remi/php'.str_replace('.', '', (string)$v).'/root/usr/bin/php';
	if (!preg_match('/^\d\.\d+$/', (string)$v) || !is_file($bin))
		$bin = '/usr/bin/php';
	/* a broken or infected site can hang wp-cli (a loop, a plugin waiting on
	   another server): stopped after $timeout s, rc 124 (wp_cli_error says so) */
	/* 512M: unpacking WordPress or a big plugin needs more than the CLI's usual 128M */
	$cmd = 'timeout -k 10 '.(int)$timeout.' sudo -u '.escapeshellarg($row['user']).' '.$bin.' -d memory_limit=512M /usr/local/bin/wp '.$args.' 2>&1';
	/* $stdin: what wp-cli's --prompt reads (passwords never go on a command line, where ps shows them) */
	if ($stdin !== null) {
		$p = proc_open($cmd, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w')), $pipes);
		if (!is_resource($p))
			return array('out' => 'wp-cli could not be started.', 'rc' => 255);
		fwrite($pipes[0], (string)$stdin);
		fclose($pipes[0]);
		$out = (string)stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		return array('out' => rtrim($out), 'rc' => proc_close($p));
	}
	$out = (string)shell_exec($cmd.'; echo "|rc:$?"');
	$rc = 255;
	if (($pos = strrpos($out, '|rc:')) !== false) {
		$rc = (int)substr($out, $pos + 4);
		$out = substr($out, 0, $pos);
	}
	return array('out' => rtrim($out), 'rc' => $rc);
}

function wordpress_docroot(array $row) {
	return wp_site_docroot($row['user'], $row['path'] ?? '', $row['domain']);
}

function wordpress_public(array $row) {
	$path = trim((string)($row['path'] ?? ''), '/');
	return array('id' => (int)$row['id'], 'account' => $row['user'], 'domain' => $row['domain'],
	             'path' => $path, 'url' => 'https://'.$row['domain'].'/'.($path !== '' ? $path.'/' : ''),
	             'version' => $row['wp_version'], 'core_status' => wordpress_core_status((string)$row['wp_version']),
	             'php' => ($php = wp_site_php_version($GLOBALS['ini'], $row['domain'])),
	             'php_ended' => isset(health_php_eol()[$php]) && strtotime(health_php_eol()[$php]) < time(),
	             'title' => $row['title'], 'status' => $row['status'],
	             /* a copy made in Clone or staging: kind (staging|clone), source (the live site's id) */
	             'copy' => wordpress_copy_map()[(int)$row['id']] ?? null);
}

/* latest | outdated | insecure for a WordPress version, or null: from
   wordpress.org's list as last fetched (wp_stable_versions() keeps it), never
   fetched here - lists must stay fast. */
function wordpress_core_status($version) {
	static $map = null;
	if ($map === null) {
		$map = @json_decode((string)@file_get_contents(_PATH.'/wptoolkit/stable-check.json'), true);
		$map = is_array($map) ? $map : array();
	}
	return $version !== '' && isset($map[$version]) ? $map[$version] : null;
}

/* Tracked installs ('' = all accounts). The version is the one the last scan or info call saw. */
function wordpress_sites($account = '') {
	global $db;
	$rows = array();
	$res = $db->query('SELECT * FROM wordpress'.($account !== '' ? ' WHERE user = "'.$db->escapeString($account).'"' : '').' ORDER BY domain');
	while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC)))
		$rows[] = wordpress_public($r);
	return $rows;
}

/* The install for an id, a domain (its install at the top first), a
   domain/path ("example.com/blog") or an account name, or null. */
function wordpress_find($id) {
	global $db;
	$id = strtolower(trim(trim((string)$id), '/'));
	if ($id === '')
		return null;
	$path = '';
	if (strpos($id, '/') !== false)
		list($id, $path) = explode('/', $id, 2);
	if (ctype_digit($id) && $path === '')
		$q = 'id = '.(int)$id;
	elseif (valid_domain($id) && strpos($id, '.') !== false)
		$q = 'domain = "'.$db->escapeString($id).'"'.($path !== '' ? ' AND lower(trim(path, "/")) = "'.$db->escapeString(trim($path, '/')).'"' : '');
	elseif (valid_username($id) && $path === '')
		$q = 'user = "'.$db->escapeString($id).'"';
	else
		return null;
	$res = $db->query('SELECT * FROM wordpress WHERE '.$q.' ORDER BY path <> "", id LIMIT 1');
	$r = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;
	return $r ?: null;
}

/*
 * Versions and updates from one wp-cli start. $refresh asks wordpress.org
 * again first (a few seconds); otherwise the updates are those of WordPress's
 * own last check (twice a day), with its time in updates_checked.
 */
function wordpress_info(array $row, $refresh = false) {
	global $db;
	$code = <<<'EOT'
if (!function_exists('get_plugins')) require_once ABSPATH.'wp-admin/includes/plugin.php';
require_once ABSPATH.'wp-admin/includes/update.php';
if (REQAD_WP_REFRESH) {
	foreach (array('update_core', 'update_plugins', 'update_themes') as $t) delete_site_transient($t);
	wp_version_check(array(), true); wp_update_plugins(); wp_update_themes();
}
$core = get_site_transient('update_core');
$core_up = array();
if (is_object($core) && !empty($core->updates))
	foreach ($core->updates as $u)
		if (isset($u->response) && $u->response === 'upgrade' && version_compare($u->current, get_bloginfo('version'), '>'))
			$core_up[] = array('version' => $u->current, 'php' => isset($u->php_version) ? (string)$u->php_version : '');
$up = get_site_transient('update_plugins');
$act = (array)get_option('active_plugins');
$plugins = array();
foreach (get_plugins() as $file => $p) {
	$plugins[] = array('slug' => dirname($file) === '.' ? basename($file, '.php') : dirname($file), 'file' => $file, 'name' => $p['Name'],
		'version' => $p['Version'], 'active' => in_array($file, $act, true),
		'update' => (is_object($up) && isset($up->response[$file]->new_version)) ? $up->response[$file]->new_version : null,
		'requires_php' => isset($up->response[$file]->requires_php) ? (string)$up->response[$file]->requires_php : '',
		'requires_wp' => isset($up->response[$file]->requires) ? (string)$up->response[$file]->requires : '');
}
$ut = get_site_transient('update_themes');
$themes = array();
foreach (wp_get_themes() as $slug => $t)
	$themes[] = array('slug' => $slug, 'dir' => $t->get_stylesheet_directory(), 'name' => $t->get('Name'), 'version' => $t->get('Version'), 'active' => $slug === get_stylesheet(),
		'update' => (is_object($ut) && isset($ut->response[$slug]['new_version'])) ? $ut->response[$slug]['new_version'] : null,
		'requires_php' => isset($ut->response[$slug]['requires_php']) ? (string)$ut->response[$slug]['requires_php'] : '',
		'requires_wp' => isset($ut->response[$slug]['requires']) ? (string)$ut->response[$slug]['requires'] : '');
echo "\n##REQAD##".json_encode(array(
	'version' => get_bloginfo('version'), 'core_offers' => $core_up, 'php_full' => PHP_VERSION,
	'updates_checked' => is_object($core) && isset($core->last_checked) ? (int)$core->last_checked : null,
	'title' => get_bloginfo('name'), 'site_url' => home_url('/'), 'multisite' => is_multisite(),
	'db_version' => (int)get_option('db_version'), 'plugin_dir' => WP_PLUGIN_DIR,
	'plugins' => $plugins, 'themes' => $themes,
));
EOT;
	$code = str_replace('REQAD_WP_REFRESH', $refresh ? 'true' : 'false', $code);
	$r = wordpress_cli($row, 'eval '.escapeshellarg($code).' --skip-themes --skip-plugins --path='.escapeshellarg(wordpress_docroot($row)), 120);
	$pos = strrpos($r['out'], '##REQAD##');
	$d = $pos !== false ? json_decode(substr($r['out'], $pos + 9), true) : null;
	if (!is_array($d))
		return result_error('WordPress did not start, even with its plugins and theme off. '
			.(wp_cli_error(array('out' => $r['out'], 'rc' => $r['rc'] ?: 1)) ?: 'wp-cli gave no answer.'), wordpress_public($row) + array('broken' => true));
	$title = html_entity_decode((string)$d['title'], ENT_QUOTES, 'UTF-8');
	if (($d['version'] !== '' && $d['version'] !== $row['wp_version']) || $title !== $row['title']) {
		$stmt = $db->prepare('UPDATE wordpress SET wp_version = :v, title = :t WHERE id = :id');
		$stmt->bindValue(':v', $d['version'] !== '' ? substr($d['version'], 0, 8) : $row['wp_version'], SQLITE3_TEXT);
		$stmt->bindValue(':t', mb_substr($title, 0, 255), SQLITE3_TEXT);
		$stmt->bindValue(':id', (int)$row['id'], SQLITE3_INTEGER);
		$stmt->execute();
		$row['wp_version'] = $d['version'] !== '' ? $d['version'] : $row['wp_version'];
		$row['title'] = $title;
	}
	/* the PHP wp-cli ran on is the site's (wordpress_cli()): what updates must suit */
	$php = (string)$d['php_full'];
	$d['php'] = preg_match('/^\d+\.\d+/', $php, $m) ? $m[0] : wp_site_php_version($GLOBALS['ini'], $row['domain']);
	$needs = function ($req_php, $req_wp) use ($php, $d) {
		if ($req_php !== '' && $php !== '' && version_compare($php, $req_php, '<'))
			return array('what' => 'php', 'version' => $req_php);
		if ($req_wp !== '' && version_compare($d['version'], $req_wp, '<'))
			return array('what' => 'wordpress', 'version' => $req_wp);
		return null;
	};
	foreach (array('plugins', 'themes') as $k)
		foreach ($d[$k] as $i => $p)
			$d[$k][$i]['needs'] = $p['update'] !== null ? $needs($p['requires_php'], $p['requires_wp']) : null;
	/* core: the newest offer this PHP can run (WordPress's own update refuses
	   the others); core_blocked = a newer one that needs a newer PHP */
	usort($d['core_offers'], function ($a, $b) { return version_compare($b['version'], $a['version']); });
	$d['core_updates'] = array();
	$d['core_blocked'] = null;
	foreach ($d['core_offers'] as $o) {
		if ($needs($o['php'], '') === null)
			$d['core_updates'][] = $o['version'];
		elseif (!$d['core_updates'] && !$d['core_blocked'])
			$d['core_blocked'] = array('version' => $o['version'], 'php' => $o['php']);
	}
	$d['core_updates'] = array_values(array_unique($d['core_updates']));
	list($stable, ) = wp_stable_versions();
	$d['core_status'] = $stable[$d['version']] ?? null;      // latest | outdated | insecure
	$d['plugins_with_updates'] = count(array_filter($d['plugins'], function ($p) { return $p['update'] !== null && !$p['needs']; }));
	$d['themes_with_updates']  = count(array_filter($d['themes'],  function ($t) { return $t['update'] !== null && !$t['needs']; }));
	return result_ok('', array('php' => $d['php']) + wordpress_public($row) + $d);
}

/* A plugin or theme slug as wp-cli takes it. */
function wordpress_valid_slug($s) {
	return is_string($s) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $s);
}

/*
 * Save the database to ~/wp-backups/ before a change, keeping the 5 newest
 * of this site: the file, or '' + $err. mysqldump runs as root (the site's
 * own PHP may not start programs), but the file is written by the account,
 * so root never writes into a folder the account controls. The database
 * named in wp-config.php must be one of the account's own (database_owner()):
 * wp-config.php is the account's file, and root would otherwise dump any
 * database it names into the account's home.
 */
function wordpress_db_backup(array $row, &$err) {
	$name = wordpress_cli($row, 'config get DB_NAME --path='.escapeshellarg(wordpress_docroot($row)), 60);
	$dbname = trim((string)substr($name['out'], (int)strrpos("\n".$name['out'], "\n")));
	if ($name['rc'] !== 0 || !preg_match('/^[A-Za-z0-9_$-]{1,64}$/', $dbname)) {
		$err = 'Could not read DB_NAME from wp-config.php: '.wp_cli_error($name);
		return '';
	}
	if (database_owner($dbname) !== $row['user']) {
		$err = "The site's database $dbname is not one of {$row['user']}'s databases.";
		return '';
	}
	$u = escapeshellarg($row['user']);
	$dir = '/home/'.$row['user'].'/wp-backups';
	$base = wordpress_backup_base($row);
	$file = $dir.'/'.$base.'-'.date('Ymd-His').'.sql.gz';
	$write = 'umask 077; mkdir -p '.escapeshellarg($dir).' && cat > '.escapeshellarg($file);
	$cmd = 'set -o pipefail; '.server_sudo().'mysqldump --single-transaction --quick --routines --triggers --default-character-set=utf8mb4 '
	      .escapeshellarg($dbname).' | gzip -6 | sudo -u '.$u.' sh -c '.escapeshellarg($write);
	exec('bash -c '.escapeshellarg($cmd).' 2>&1', $o, $rc);
	if ($rc !== 0) {
		shell_exec('sudo -u '.$u.' rm -f '.escapeshellarg($file).' 2>/dev/null');
		$err = 'mysqldump of '.$dbname.' failed: '.trim(implode(' ', $o));
		return '';
	}
	$keep = 'cd '.escapeshellarg($dir).' && ls -1t | grep -E '.escapeshellarg('^'.preg_quote($base, '/').'-[0-9]{8}-[0-9]{6}\.sql\.gz$')
	       .' | tail -n +6 | xargs -r rm -f --';
	shell_exec('sudo -u '.$u.' sh -c '.escapeshellarg($keep).' 2>&1');
	return $file;
}

/* The start of a site's backup file names: domain, then its folder. */
function wordpress_backup_base(array $row) {
	$path = trim((string)($row['path'] ?? ''), '/');
	return preg_replace('/[^a-z0-9.]+/', '-', strtolower($row['domain'].($path !== '' ? '-'.$path : '')));
}

/*
 * Update core, plugins or themes ($what). $items: plugin / theme slugs, empty
 * = every one with an update. See wordpress_update_steps().
 */
function wordpress_update(array $row, $what, array $items = array()) {
	return wordpress_update_steps($row, array(array($what, $items)));
}

/* The steps of an update, checked and in the order they run (core first,
   then plugins, then themes), or null + $err. */
function wordpress_steps_clean(array $steps, &$err) {
	$err = '';
	$by = array();
	foreach ($steps as $st) {
		$what = is_array($st) ? (string)($st[0] ?? ($st['what'] ?? '')) : '';
		$items = is_array($st) ? ($st[1] ?? ($st['items'] ?? array())) : array();
		if (!in_array($what, array('core', 'plugins', 'themes'), true)) {
			$err = 'Update core, plugins or themes.';
			return null;
		}
		$items = array_values(array_unique(array_map('strval', is_array($items) ? $items : preg_split('/[\s,]+/', (string)$items, -1, PREG_SPLIT_NO_EMPTY))));
		foreach ($items as $it)
			if (!wordpress_valid_slug($it)) {
				$err = "'$it' is not a plugin or theme name (the folder name, e.g. akismet).";
				return null;
			}
		/* core: no names, or the one version to update to (auto-updates: the newest of the branch) */
		if ($what === 'core' && $items && (count($items) > 1 || !preg_match('/^\d+\.\d+(\.\d+)?$/', $items[0]))) {
			$err = 'Core updates take no plugin or theme names.';
			return null;
		}
		if (count($items) > 200) {
			$err = 'At most 200 plugins or themes at a time.';
			return null;
		}
		/* "all" wins over a list */
		$by[$what] = isset($by[$what]) && (!$by[$what] || !$items) ? array() : array_values(array_unique(array_merge($by[$what] ?? array(), $items)));
	}
	if (!$by) {
		$err = 'Nothing to update.';
		return null;
	}
	$out = array();
	foreach (array('core', 'plugins', 'themes') as $w)
		if (isset($by[$w]))
			$out[] = array($w, $by[$w]);
	return $out;
}

/*
 * Run update steps (array(array(what, items), ...), see wordpress_steps_clean)
 * on a site. The site is read first: updates that need a newer PHP (or
 * WordPress) than the site has are left out and said so, and nothing runs when
 * WordPress does not start at all. Then the database is exported (once), core
 * updates also run the database upgrade, a failed step does not stop the next
 * one. A step whose wp-cli dies while loading WordPress (a broken or infected
 * plugin or theme) runs again with plugins and themes off. Then the caches are
 * emptied and the versions read again. Returns the wp-cli output, the backup
 * file and the new versions.
 */
function wordpress_update_steps(array $row, array $steps) {
	$steps = wordpress_steps_clean($steps, $err);
	if ($steps === null)
		return result_error($err);
	$path = ' --path='.escapeshellarg(wordpress_docroot($row));
	$site = $row['domain'].(trim((string)$row['path'], '/') !== '' ? '/'.trim($row['path'], '/') : '');
	/* fresh from wordpress.org: the stored check may be gone (a plugin install clears it) */
	$before = wordpress_info($row, true);
	if (!$before['ok'])
		return result_error('Nothing was updated on '.$site.'. '.$before['error'], $before['data']);
	$b = $before['data'];
	$skipped = array();
	$run = array();
	foreach ($steps as $st) {
		list($what, $items) = $st;
		if ($what === 'core') {
			if ($b['core_blocked'] && !$b['core_updates'])
				$skipped[] = 'WordPress '.$b['core_blocked']['version'].' needs PHP '.$b['core_blocked']['php'].' (the site runs PHP '.$b['php'].')';
			elseif ($items && !in_array($items[0], $b['core_updates'], true))
				$skipped[] = 'WordPress '.$items[0].' (it is not offered for this site now)';
			else
				$run[] = array('core', array(), $items ? $items[0] : ($b['core_updates'] ? $b['core_updates'][0] : ''));
			continue;
		}
		$known = array();
		foreach ($b[$what] as $p)
			$known[$p['slug']] = $p;
		$keep = array();
		foreach ($items as $it) {
			$n = $known[$it]['needs'] ?? null;
			if ($n)
				$skipped[] = ($known[$it]['name'] ?: $it).' '.$known[$it]['update'].' needs '.($n['what'] === 'php' ? 'PHP '.$n['version'].' (the site runs PHP '.$b['php'].')' : 'WordPress '.$n['version'].' (update WordPress first)');
			else
				$keep[] = $it;
		}
		/* "all": wp-cli itself leaves out what this PHP cannot run */
		if ($keep || !$items)
			$run[] = array($what, $keep, '');
	}
	$skip_msg = $skipped ? ' Left out: '.implode('; ', $skipped).'.' : '';
	if (!$run)
		return result_error('Nothing was updated on '.$site.'.'.$skip_msg, array('site' => wordpress_public($row), 'skipped' => $skipped));
	$backup = wordpress_db_backup($row, $err);
	if ($backup === '')
		return result_error('Nothing was updated: the database backup failed first. '.$err, wordpress_public($row));
	$out = $failed = $done = array();
	$safe = '';
	/* one wp-cli run; again with plugins and themes off when WordPress dies while loading */
	$cli = function ($cmd) use ($row, $path, &$out, &$safe) {
		$r = wordpress_cli($row, $cmd.($safe !== '' ? ' --skip-plugins --skip-themes' : '').$path);
		$out[] = '$ wp '.$cmd.($safe !== '' ? ' --skip-plugins --skip-themes' : '')."\n".$r['out'];
		if ($r['rc'] !== 0 && $safe === '' && wordpress_load_failed($r)) {
			$safe = wp_cli_error($r);
			$out[] = '# WordPress did not start with its plugins and theme on. Again with them off:';
			$r = wordpress_cli($row, $cmd.' --skip-plugins --skip-themes'.$path);
			$out[] = '$ wp '.$cmd.' --skip-plugins --skip-themes'."\n".$r['out'];
		}
		return $r;
	};
	foreach ($run as $st) {
		list($what, $items, $target) = $st;
		if ($what === 'core') {
			/* wp-cli takes the newest offer: name the version when this PHP needs an older one */
			$first = $b['core_offers'] ? $b['core_offers'][0]['version'] : '';
			$r = $cli('core update'.($target !== '' && $target !== $first ? ' --version='.escapeshellarg($target) : ''));
			if ($r['rc'] === 0)
				$cli('core update-db');
		} else {
			$kind = $what === 'plugins' ? 'plugin' : 'theme';
			$r = $cli($kind.' update '.($items ? implode(' ', array_map('escapeshellarg', $items)) : '--all'));
		}
		log_debug('[wp] '.auth_user().' updated '.$what.($items ? ' '.implode(',', $items) : '').' on '.$site.' (rc '.$r['rc'].')');
		if ($r['rc'] !== 0)
			$failed[] = ($what === 'core' ? 'WordPress' : ucfirst($what)).': '.(wp_cli_error($r) ?: 'the update failed.');
		else {
			$done[] = $what === 'core' ? 'WordPress' : ($items ? count($items).' '.(count($items) === 1 ? substr($what, 0, -1) : $what) : 'all '.$what);
			plugin_hook('wordpress.updated', array('account' => $row['user'], 'domain' => $row['domain'], 'what' => $what, 'items' => $items));
		}
	}
	/* also after a failure: some files may have changed */
	list($purged, $warn) = wordpress_purge_caches($row, $safe === '');
	$info = wordpress_info($row);
	$data = array('site' => wordpress_public($row), 'backup' => $backup, 'caches_emptied' => $purged,
	              'output' => trim(implode("\n\n", $out)), 'skipped' => $skipped, 'safe_mode' => $safe !== '');
	if ($info['ok']) {
		$data['site']['version'] = $info['data']['version'];
		$data += array('version' => $info['data']['version'], 'core_updates' => $info['data']['core_updates'],
		               'plugins_with_updates' => $info['data']['plugins_with_updates'], 'themes_with_updates' => $info['data']['themes_with_updates']);
	}
	$saved = ' The database was saved first to ~/'.substr($backup, strlen('/home/'.$row['user'].'/')).'.';
	$safe_msg = $safe !== '' ? ' WordPress does not start with its plugins and theme on, so the updates ran with them off. Look at the site: '.$safe : '';
	if ($failed)
		return result_error(($done ? 'Updated on '.$site.': '.implode(', ', $done).'. ' : '').implode(' ', $failed).$skip_msg.$saved.$safe_msg, $data);
	return result_ok('Updated on '.$site.': '.implode(', ', $done).'.'.$skip_msg.$saved
		.($purged ? ' Emptied: '.implode(', ', $purged).'.' : '').$safe_msg, $data, $warn);
}

/* Did wp-cli die while loading WordPress (a PHP error in a plugin, the theme
   or the site's code) rather than fail the command itself? Not for database
   errors: switching plugins off does not help there. */
function wordpress_load_failed(array $r) {
	return $r['rc'] !== 0 && $r['rc'] !== 124 && $r['rc'] !== 137
		&& stripos($r['out'], 'establishing a database connection') === false
		&& preg_match('/PHP (Fatal|Parse) error|critical error on this website|Allowed memory size of/i', $r['out']);
}

/* ---- updates in the background (the account panel: they can take minutes) ---- */

function wordpress_unit($site_id) {
	return 'reqad-wp-'.(int)$site_id;
}

function wordpress_unit_active($site_id) {
	exec(server_sudo().'systemctl is-active --quiet '.escapeshellarg(wordpress_unit($site_id)).' 2>/dev/null', $o, $rc);
	return $rc === 0;
}

function wordpress_jobs_ready() {
	global $db;
	return (bool)$db->querySingle("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'wordpress_jobs'");
}

/* Has wordpress_jobs its kind column (db 1052): update | copy | push (Push to live, wordpress_push.php) */
function wordpress_jobs_kinds() {
	global $db;
	static $has = null;
	if ($has === null)
		$has = wordpress_jobs_ready() && (bool)$db->querySingle("SELECT 1 FROM pragma_table_info('wordpress_jobs') WHERE name = 'kind'");
	return $has;
}

function wordpress_job_get($id) {
	global $db;
	if (!wordpress_jobs_ready())
		return null;
	$r = $db->querySingle('SELECT * FROM wordpress_jobs WHERE id = '.(int)$id, true);
	return $r ?: null;
}

/* What a page shows of a job (no output unless $output). */
function wordpress_job_public(array $j, $output = false) {
	$st = json_decode($j['steps'], true);
	$a = array('id' => (int)$j['id'], 'site' => (int)$j['site_id'], 'kind' => (string)($j['kind'] ?? 'update'), 'steps' => is_array($st) ? $st : array(), 'status' => $j['status'],
	           'message' => $j['message'], 'backup' => $j['backup'] !== '' ? '~/'.substr($j['backup'], strlen('/home/'.$j['account'].'/')) : '',
	           'queued' => (int)$j['queued'], 'started' => (int)$j['started'], 'finished' => (int)$j['finished'], 'by' => $j['by_user']);
	if ($output)
		$a['output'] = $j['output'];
	return $a;
}

/* The site's newest job of a kind (update | copy, '' = any): running or
   queued, or one that ended in the last day; null when there is none. A job
   whose unit is gone is marked failed. */
function wordpress_job_latest($site_id, $kind = 'update') {
	global $db;
	if (!wordpress_jobs_ready())
		return null;
	$k = $kind !== '' && wordpress_jobs_kinds() ? " AND kind = '".SQLite3::escapeString($kind)."'" : '';
	$j = $db->querySingle('SELECT * FROM wordpress_jobs WHERE site_id = '.(int)$site_id.$k.' ORDER BY id DESC LIMIT 1', true);
	if (!$j)
		return null;
	if (in_array($j['status'], array('queued', 'running'), true) && $j['queued'] < time() - 15 && !wordpress_unit_active($site_id)) {
		$db->exec("UPDATE wordpress_jobs SET status = 'failed', finished = ".time().", message = '"
		         .(array('copy' => 'The copy was interrupted (time limit or restart).', 'push' => 'The push was interrupted (time limit or restart).')[$j['kind'] ?? 'update'] ?? 'The update was interrupted (time limit or restart).')."'"
		         .' WHERE id = '.(int)$j['id']);
		$j = $db->querySingle('SELECT * FROM wordpress_jobs WHERE id = '.(int)$j['id'], true);
	}
	if (!in_array($j['status'], array('queued', 'running'), true) && (int)$j['finished'] < time() - 86400)
		return null;
	return $j;
}

/*
 * Queue an update of a site and start its runner: `reqad wordpress __run
 * <job>` in the transient unit reqad-wp-<site id> (root; wp-cli runs as the
 * account inside it; at most 30 minutes). One job at a time per site, of any
 * kind: $kind copy (wordpress_copy_start(), at most 2 hours) and push
 * (wordpress_push_start(), at most 1 hour) have their options as $steps.
 */
function wordpress_job_start(array $row, $steps, $by = '', $kind = 'update') {
	global $db;
	if (!wordpress_jobs_ready())
		return result_error('The table for WordPress updates is missing: apply db/1049.sql (the Reqad update does it).');
	if ($kind !== 'update' && !wordpress_jobs_kinds())
		return result_error('The table for WordPress jobs is too old: apply db/1052.sql (the Reqad update does it).');
	/* 'auto': an auto-update run, which decides what to update when it starts (wordpress_auto_update()) */
	if ($kind === 'update' && $steps !== 'auto') {
		$steps = wordpress_steps_clean((array)$steps, $err);
		if ($steps === null)
			return result_error($err);
	}
	$site = $row['domain'].(trim((string)$row['path'], '/') !== '' ? '/'.trim($row['path'], '/') : '');
	$busy = function ($cur) use ($site) {
		$k = $cur['kind'] ?? 'update';
		return $k === 'copy' ? "$site is being copied right now." : ($k === 'push' ? "$site is being updated from its staging copy right now." : "An update of $site is already running.");
	};
	$cur = wordpress_job_latest($row['id'], '');
	if ($cur && in_array($cur['status'], array('queued', 'running'), true))
		return result_error($busy($cur), array('job' => wordpress_job_public($cur)));
	$stmt = $db->prepare('INSERT INTO wordpress_jobs (site_id, account, steps, by_user, queued'.(wordpress_jobs_kinds() ? ', kind' : '').')'
	                    .' VALUES (:s, :a, :st, :b, :q'.(wordpress_jobs_kinds() ? ', :k' : '').')');
	$stmt->bindValue(':s', (int)$row['id'], SQLITE3_INTEGER);
	$stmt->bindValue(':a', $row['user'], SQLITE3_TEXT);
	$stmt->bindValue(':st', json_encode($steps), SQLITE3_TEXT);
	$stmt->bindValue(':b', (string)$by, SQLITE3_TEXT);
	$stmt->bindValue(':q', time(), SQLITE3_INTEGER);
	if (wordpress_jobs_kinds())
		$stmt->bindValue(':k', $kind, SQLITE3_TEXT);
	$stmt->execute();
	$id = (int)$db->lastInsertRowID();
	$cmd = server_sudo().'systemd-run --quiet --collect --unit='.escapeshellarg(wordpress_unit($row['id']))
		.' --description='.escapeshellarg('Reqad WordPress '.(in_array($kind, array('copy', 'push'), true) ? $kind : 'update').' of '.$site)
		.' -p RuntimeMaxSec='.(array('copy' => 7200, 'push' => 3600)[$kind] ?? 1800).' -p Nice=10 -p IOSchedulingClass=best-effort -p IOSchedulingPriority=7'
		.' -- '._PATH.'/bin/reqad wordpress __run '.$id.' 2>&1';
	exec($cmd, $out, $rc);
	if ($rc !== 0) {
		$msg = trim(implode(' ', $out));
		$db->exec("UPDATE wordpress_jobs SET status = 'failed', finished = ".time().", message = '"
		         .SQLite3::escapeString('Could not start: '.substr($msg, 0, 200))."' WHERE id = ".$id);
		return result_error(stripos($msg, 'already') !== false ? $busy(array('kind' => $kind)) : 'Could not start: '.$msg);
	}
	return result_ok($kind === 'copy' ? "The copy of $site started." : ($kind === 'push' ? "The push to $site started." : "The update of $site started."), array('job' => wordpress_job_public(wordpress_job_get($id))));
}

/* The runner (root, inside the unit): run one queued job. */
function wordpress_job_execute($id) {
	global $db;
	$db->busyTimeout(10000);
	$j = wordpress_job_get($id);
	if (!$j || $j['status'] !== 'queued')
		return;
	$set = function (array $f) use ($db, $id) {
		$sets = array();
		foreach ($f as $k => $v)
			$sets[] = $k.' = '.(is_int($v) ? $v : "'".SQLite3::escapeString((string)$v)."'");
		$db->exec('UPDATE wordpress_jobs SET '.implode(', ', $sets).' WHERE id = '.(int)$id);
	};
	$row = wordpress_find($j['site_id']);
	if (!$row || $row['user'] !== $j['account']) {
		$set(array('status' => 'failed', 'finished' => time(), 'message' => 'The site is no longer in the list.'));
		return;
	}
	$set(array('status' => 'running', 'started' => time()));
	$auto = json_decode($j['steps'], true) === 'auto';
	$kind = $j['kind'] ?? 'update';
	try {
		$r = $kind === 'copy' ? wordpress_copy_run($row, (array)json_decode($j['steps'], true))
		   : ($kind === 'push' ? wordpress_push_run($row, (array)json_decode($j['steps'], true))
		   : ($auto ? wordpress_auto_update($row, (int)$id) : wordpress_update_steps($row, (array)json_decode($j['steps'], true))));
	} catch (Throwable $e) {
		$r = result_error((array('copy' => 'The copy stopped: ', 'push' => 'The push stopped: ')[$kind] ?? 'The update stopped: ').$e->getMessage());
	}
	$msg = $r['ok'] ? $r['message'].($r['warnings'] ? ' '.implode(' ', $r['warnings']) : '') : $r['error'];
	/* warn: updated, but WordPress dies loading its plugins or theme (wordpress_update_steps' safe mode) */
	$set(array('status' => $r['ok'] ? (empty($r['data']['safe_mode']) && empty($r['data']['rolled_back']) ? 'ok' : 'warn') : 'failed', 'finished' => time(), 'message' => substr($msg, 0, 2000),
	           'backup' => (string)($r['data']['backup'] ?? ''), 'output' => substr((string)($r['data']['output'] ?? ''), 0, 65536)));
	if ($auto) {
		wordpress_auto_done($row, $r);
		/* a run that found nothing to do leaves no trace on the Updates page */
		if (!empty($r['data']['nothing']))
			$db->exec('DELETE FROM wordpress_jobs WHERE id = '.(int)$id);
	}
	/* the 2087 site page's probe would still count the old plugin updates */
	@unlink(wp_site_probe_file($row['user']));
	health_run($db, array('wordpress'));
}

/*
 * Empty every cache that could still serve the site as it was, after an
 * update or a plugin change: the nginx page cache (WP Toolkit's cache switch),
 * OPcache (graceful reload of the site's PHP-FPM; otherwise it may run old
 * code for up to opcache.revalidate_freq seconds), the WordPress object cache
 * (Redis / Memcached drop-ins), cache plugins and Elementor's generated CSS.
 * Fixed code of our own in `wp eval`, with the plugins loaded ($wp false:
 * only nginx and OPcache, for a site that dies loading them). Returns
 * array(what was emptied, warnings).
 */
function wordpress_purge_caches(array $row, $wp = true) {
	global $ini;
	$done = $warn = array();
	if (wp_nginx_cache_state($ini, $row['domain']) === 'on') {
		wp_nginx_cache_purge_all($row['user']);
		$done[] = 'nginx page cache';
	}
	$v = wp_site_php_version($ini, $row['domain']);
	if (preg_match('/^\d\.\d+$/', (string)$v)) {
		$svc = addon_fpm_service($ini, $v);
		exec(server_sudo().'systemctl reload '.escapeshellarg($svc).' 2>&1', $o, $rc);
		if ($rc === 0)
			$done[] = "OPcache (PHP $v)";
		else
			$warn[] = "Could not reload $svc to reset OPcache: ".trim(implode(' ', $o));
	}
	/* plugins' own caches need WordPress with its plugins: not when it dies loading them */
	if (!$wp)
		return array($done, $warn);
	$code = <<<'EOT'
$done = array();
$try = function ($label, $fn) use (&$done) { try { if ($fn() !== false) $done[] = $label; } catch (Throwable $e) {} };
if (function_exists('wp_cache_flush') && function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) $try('object cache', function () { return wp_cache_flush(); });
if (function_exists('rocket_clean_domain')) $try('WP Rocket', function () { rocket_clean_domain(); if (function_exists('rocket_clean_minify')) rocket_clean_minify(); });
if (defined('LSCWP_V')) $try('LiteSpeed Cache', function () { do_action('litespeed_purge_all'); });
if (function_exists('w3tc_flush_all')) $try('W3 Total Cache', function () { w3tc_flush_all(); });
if (function_exists('wp_cache_clear_cache')) $try('WP Super Cache', function () { wp_cache_clear_cache(); });
if (isset($GLOBALS['wp_fastest_cache']) && method_exists($GLOBALS['wp_fastest_cache'], 'deleteCache')) $try('WP Fastest Cache', function () { $GLOBALS['wp_fastest_cache']->deleteCache(true); });
if (class_exists('autoptimizeCache') && method_exists('autoptimizeCache', 'clearall')) $try('Autoptimize', function () { autoptimizeCache::clearall(); });
if (class_exists('Cache_Enabler') && method_exists('Cache_Enabler', 'clear_complete_cache')) $try('Cache Enabler', function () { Cache_Enabler::clear_complete_cache(); });
if (function_exists('WP_Optimize') && method_exists(WP_Optimize(), 'get_page_cache')) $try('WP-Optimize', function () { WP_Optimize()->get_page_cache()->purge(); });
if (has_action('breeze_clear_all_cache')) $try('Breeze', function () { do_action('breeze_clear_all_cache'); });
if (function_exists('sg_cachepress_purge_everything')) $try('SiteGround Optimizer', function () { sg_cachepress_purge_everything(); });
if (class_exists('\Elementor\Plugin') && isset(\Elementor\Plugin::$instance->files_manager)) $try('Elementor CSS', function () { \Elementor\Plugin::$instance->files_manager->clear_cache(); });
echo "\n##REQAD##".json_encode($done);
EOT;
	$r = wordpress_cli($row, 'eval '.escapeshellarg($code).' --path='.escapeshellarg(wordpress_docroot($row)), 120);
	$pos = strrpos($r['out'], '##REQAD##');
	$list = $pos !== false ? json_decode(substr($r['out'], $pos + 9), true) : null;
	if (is_array($list))
		$done = array_merge($done, $list);
	else
		$warn[] = 'Could not empty the WordPress caches (the site may not load): '.(wp_cli_error($r) ?: 'no answer');
	return array($done, $warn);
}

/* Activate or deactivate one plugin. */
function wordpress_plugin_set(array $row, $slug, $on) {
	if (!wordpress_valid_slug($slug))
		return result_error("'$slug' is not a plugin name (the folder name, e.g. akismet).");
	$r = wordpress_cli($row, 'plugin '.($on ? 'activate' : 'deactivate').' '.escapeshellarg($slug).' --path='.escapeshellarg(wordpress_docroot($row)));
	if ($r['rc'] !== 0)
		return result_error(wp_cli_error($r));
	log_debug('[wp-api] '.auth_user().' '.($on ? 'activated' : 'deactivated').' plugin '.$slug.' on '.$row['domain']);
	list($purged, $warn) = wordpress_purge_caches($row);
	return result_ok("Plugin $slug ".($on ? 'activated' : 'deactivated').' on '.$row['domain'].'.'
		.($purged ? ' Emptied: '.implode(', ', $purged).'.' : ''), array('caches_emptied' => $purged, 'output' => $r['out']), $warn);
}

/* Core and plugin files compared with wordpress.org's checksums: what was changed or added. */
function wordpress_verify(array $row) {
	$path = ' --path='.escapeshellarg(wordpress_docroot($row));
	$core = wordpress_cli($row, 'core verify-checksums'.$path);
	$plug = wordpress_cli($row, 'plugin verify-checksums --all --format=json'.$path);
	$bad = array();
	$pos = strpos($plug['out'], '[');
	if ($pos !== false && is_array($j = json_decode(substr($plug['out'], $pos), true)))
		$bad = $j;
	return result_ok('', array(
		'core_ok'     => $core['rc'] === 0,
		'core'        => trim($core['out']),
		'plugins_ok'  => $plug['rc'] === 0,
		'plugin_problems' => $bad,
		'note'        => 'Plugins that are not on wordpress.org (premium, custom) cannot be checked and show as errors.',
	));
}

/*
 * Look for the account's WordPress installs in the folders of all its sites
 * (the main domain's ~/public_html, every addon domain and subdomain), up to
 * 3 folders down, and bring its rows of the list up to date: new installs
 * are added, versions refreshed, rows whose install is gone removed. The
 * looking is done AS the account (fm-helper verb wpfind: it only reads
 * wp-includes/version.php, never runs it); only a new install's title comes
 * from wp-cli, also as the account. An install inside another site's folder
 * (an addon site under ~/public_html) belongs to the site whose folder is
 * closest.
 */
function wordpress_scan($account) {
	global $db, $ini;
	$acct = valid_username((string)$account) ? account_get($db, $account) : null;
	if (!$acct)
		return result_error("No hosting account '$account'.");
	$user = $acct['user'];
	$home = '/home/'.$user;
	$norm = function ($p) { return implode('/', array_filter(explode('/', $p), 'strlen')); };
	$roots = array('public_html' => strtolower($acct['domain']));
	foreach (addon_domain_list($ini) as $d => $a)
		if ($a['user'] === $user && strpos((string)$a['docroot'], $home.'/') === 0)
			$roots[$norm(substr($a['docroot'], strlen($home) + 1))] = strtolower($d);
	$r = files_run($user, array('verb' => 'wpfind', 'roots' => array_keys($roots)), null, null, 120);
	if (empty($r['ok']) || !isset($r['found']) || !is_array($r['found']))
		return result_error('Could not look for WordPress sites: '.($r['error'] ?? 'no answer.'));

	$best = array();
	foreach ($r['found'] as $f) {
		if (!is_array($f) || !isset($roots[(string)($f['root'] ?? '')]))
			continue;
		$k = (string)$f['real'];
		if (!isset($best[$k]) || strlen((string)$f['path']) < strlen((string)$best[$k]['path']))
			$best[$k] = $f;
	}
	$have = array();
	$stmt = $db->prepare('SELECT * FROM wordpress WHERE user = :u');
	$stmt->bindValue(':u', $user, SQLITE3_TEXT);
	$res = $stmt->execute();
	while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC)))
		$have[strtolower($row['domain']).'|'.trim((string)$row['path'], '/')] = $row;

	$added = $removed = $seen = array();
	foreach ($best as $f) {
		$domain = $roots[$f['root']];
		$path = $norm((string)$f['path']);
		$key = $domain.'|'.$path;
		$seen[$key] = true;
		$version = substr((string)$f['version'], 0, 8);
		if (isset($have[$key])) {
			if ($version !== '' && $version !== $have[$key]['wp_version']) {
				$u = $db->prepare('UPDATE wordpress SET wp_version = :v WHERE id = :id');
				$u->bindValue(':v', $version, SQLITE3_TEXT);
				$u->bindValue(':id', (int)$have[$key]['id'], SQLITE3_INTEGER);
				$u->execute();
			}
			continue;
		}
		$row = array('id' => 0, 'user' => $user, 'domain' => $domain, 'path' => $path);
		$t = wordpress_cli($row, 'option get blogname --skip-plugins --skip-themes --path='.escapeshellarg(wordpress_docroot($row)), 60);
		$title = $t['rc'] === 0 ? html_entity_decode(trim($t['out']), ENT_QUOTES, 'UTF-8') : '';
		$ins = $db->prepare('INSERT INTO wordpress (user, domain, title, wp_version, path, comments, status, created_at)
		                     VALUES (:u, :d, :t, :v, :p, "", :s, datetime("now"))');
		$ins->bindValue(':u', $user, SQLITE3_TEXT);
		$ins->bindValue(':d', $domain, SQLITE3_TEXT);
		$ins->bindValue(':t', mb_substr($title !== '' ? $title : $domain, 0, 255), SQLITE3_TEXT);
		$ins->bindValue(':v', $version, SQLITE3_TEXT);
		$ins->bindValue(':p', $path, SQLITE3_TEXT);
		$ins->bindValue(':s', (string)($acct['status'] ?? 'active'), SQLITE3_TEXT);
		$ins->execute();
		$added[] = $domain.($path !== '' ? '/'.$path : '');
	}
	/* only a complete look may say an install is gone */
	if (!empty($r['complete']))
		foreach ($have as $key => $row)
			if (!isset($seen[$key])) {
				$db->exec('DELETE FROM wordpress WHERE id = '.(int)$row['id']);
				$removed[] = $row['domain'].(trim((string)$row['path'], '/') !== '' ? '/'.trim($row['path'], '/') : '');
			}
	if ($added || $removed)
		plugin_hook('wordpress.scanned', array('account' => $user, 'added' => $added, 'removed' => $removed));
	/* installs in a folder need their nginx location (also ones made before it existed) */
	$warn = empty($r['complete']) ? array('There are a great many folders; only part of them was looked through.') : array();
	foreach (array_unique(array_values($roots)) as $d)
		if (($e = wordpress_vhost_folders($user, $d)) !== '')
			$warn[] = $d.': '.$e;
	$sites = wordpress_sites($user);
	$msg = count($sites) === 0 ? 'No WordPress site was found.'
	     : (count($sites) === 1 ? '1 WordPress site found.' : count($sites).' WordPress sites found.');
	return result_ok($msg, array('sites' => $sites, 'added' => $added, 'removed' => $removed), $warn);
}

/*
 * The databases the account's WordPress sites use: database name => the
 * sites (wordpress_public()) whose wp-config.php names it. Read AS the
 * account (fm-helper verb wpdbname: the file is read, never run); a site
 * whose config names no database plainly is left out. For a warning before
 * a database is deleted, so it is never more than that: no refusal on it.
 */
function wordpress_databases($account) {
	$by_path = array();
	foreach (wordpress_sites($account) as $s) {
		$doc = wp_site_docroot($s['account'], $s['path'], $s['domain']);
		$home = '/home/'.$s['account'].'/';
		if (strpos($doc, $home) === 0)
			$by_path[substr($doc, strlen($home))][] = $s;
	}
	if (!$by_path)
		return result_ok('', array());
	$r = files_run($account, array('verb' => 'wpdbname', 'paths' => array_map('strval', array_keys($by_path))), null, null, 30);
	if (empty($r['ok']) || !isset($r['dbs']) || !is_array($r['dbs']))
		return result_error('Could not read the WordPress sites\' settings: '.($r['error'] ?? 'no answer.'));
	$out = array();
	foreach ($r['dbs'] as $path => $name)
		if (is_string($name) && isset($by_path[$path]))
			foreach ($by_path[$path] as $s)
				$out[$name][] = $s;
	ksort($out);
	return result_ok('', $out);
}

/*
 * What the panel knows about one install, for its page in Sites (fast: no
 * wp-cli; GET /v1/wordpress/{site} reads plugins and themes): folder,
 * database {name, size_mb, own} (DB_NAME of its wp-config.php, read as the
 * account), added, copy (what it is a copy of), copies (its own), auto
 * (auto-update settings), job (the newest background job), backups (the
 * newest database backups in ~/wp-backups/: name, size, time).
 */
function wordpress_details(array $row) {
	global $db;
	$user = $row['user'];
	$home = '/home/'.$user.'/';
	$doc = rtrim(wordpress_docroot($row), '/');
	$rel = strpos($doc, $home) === 0 ? substr($doc, strlen($home)) : '';
	$out = array('site' => wordpress_public($row), 'folder' => $rel !== '' ? '~/'.$rel : $doc, 'added' => (string)($row['created_at'] ?? ''),
	             'database' => null, 'copy' => null, 'copies' => array(), 'auto' => null, 'job' => null, 'backups' => array());
	if ($rel !== '') {
		$r = files_run($user, array('verb' => 'wpdbname', 'paths' => array($rel)), null, null, 30);
		$name = !empty($r['ok']) && is_string($r['dbs'][$rel] ?? null) ? $r['dbs'][$rel] : '';
		if ($name !== '') {
			$own = database_owner($name) === $user;
			$size = null;
			if ($own && preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
				list($rows) = database_mysql_batch(array('SELECT IFNULL(ROUND(SUM(data_length + index_length) / 1048576, 2), 0)'
				                                         .' FROM information_schema.TABLES WHERE table_schema = \''.$name.'\''));
				$size = isset($rows[0]) ? (float)$rows[0] : null;
			}
			$out['database'] = array('name' => $name, 'size_mb' => $size, 'own' => $own);
		}
	}
	$c = function_exists('wordpress_copy_get') ? wordpress_copy_get($row['id']) : null;
	if ($c) {
		$src = wordpress_find($c['source_id']);
		$out['copy'] = array('kind' => $c['kind'], 'created' => (int)$c['created'], 'pushed' => (int)$c['pushed'], 'source_label' => $c['source_label'],
		                     'source' => $src && $src['user'] === $user ? wordpress_public($src) : null);
	}
	if (function_exists('wordpress_copy_get') && @$db->querySingle("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'wordpress_copies'")) {
		$q = $db->query('SELECT * FROM wordpress_copies WHERE source_id = '.(int)$row['id'].' ORDER BY created');
		while ($q && ($x = $q->fetchArray(SQLITE3_ASSOC)))
			if ($x['account'] === $user && ($cr = wordpress_find($x['site_id'])))
				$out['copies'][] = array('id' => (int)$x['site_id'], 'domain' => $cr['domain'], 'url' => wordpress_public($cr)['url'], 'kind' => $x['kind'],
				                         'created' => (int)$x['created'], 'pushed' => (int)$x['pushed']);
	}
	if (function_exists('wordpress_auto_get')) {
		$a = wordpress_auto_get($row['id']);
		$out['auto'] = array('on' => wordpress_auto_on($a), 'core' => $a['core'], 'plugins' => $a['plugins'], 'themes' => $a['themes'],
		                     'next' => (int)$a['next_run'], 'last' => (int)$a['last_run'], 'last_status' => $a['last_status']);
	}
	$j = wordpress_job_latest($row['id'], '');
	if ($j)
		$out['job'] = wordpress_job_public($j);
	$l = files_list($user, 'wp-backups');
	if ($l['ok']) {
		$re = '/^'.preg_quote(wordpress_backup_base($row), '/').'-\d{8}-\d{6}\.sql\.gz$/';
		foreach ($l['data']['entries'] as $e)
			if (($e['t'] ?? '') === 'f' && preg_match($re, $e['n']))
				$out['backups'][] = array('name' => $e['n'], 'size' => (int)$e['s'], 'time' => (int)$e['m']);
		usort($out['backups'], function ($a, $b) { return $b['time'] - $a['time']; });
		$out['backups'] = array_slice($out['backups'], 0, 3);
	}
	return result_ok('', $out);
}

/*
 * A link that signs in to the site's dashboard once, as its first
 * administrator (a super admin on a multisite), within 2 minutes. The site
 * gets a small must-use plugin made AS the account (fm-helper verb wplogin)
 * that holds only the SHA-256 of the link's secret; it removes itself when
 * the link is used, on the site's first visit after it expired, and in any
 * case after 5 minutes (a timer that runs rm as the account). The link goes
 * to the site's own address (siteurl), so the sign-in cookie is for the
 * host the dashboard is on.
 */
function wordpress_login(array $row) {
	$doc = wordpress_docroot($row);
	$home = '/home/'.$row['user'];
	if (strpos($doc, $home.'/') !== 0)
		return result_error('This WordPress site is not in the account\'s home folder.');
	$u = wordpress_cli($row, 'option get siteurl --skip-plugins --skip-themes --path='.escapeshellarg($doc), 60);
	$lines = preg_split('/\R/', trim($u['out']));
	$site = trim((string)end($lines));
	if ($u['rc'] !== 0 || !preg_match('~^https?://[A-Za-z0-9.-]+(:[0-9]{1,5})?(/[^\s?#]*)?$~', $site))
		return result_error(wp_cli_error($u) ?: 'Could not read the site\'s address from WordPress.');
	$token = bin2hex(random_bytes(24));
	$hash = hash('sha256', $token);
	$name = 'reqad-login-'.substr($hash, 0, 12).'.php';
	$code = str_replace(array('REQAD_EXPIRES', 'REQAD_HASH'), array((string)(time() + 120), $hash), wordpress_login_code());
	$r = files_run($row['user'], array('verb' => 'wplogin', 'path' => substr($doc, strlen($home) + 1), 'name' => $name, 'size' => strlen($code)), $code, null, 30);
	if (empty($r['ok']))
		return result_error('Could not prepare the sign-in: '.($r['error'] ?? 'no answer.'));
	$pw = posix_getpwnam($row['user']);
	if ($pw && preg_match('#^'.preg_quote($home, '#').'/[^\0\n]+/'.preg_quote($name, '#').'$#', (string)$r['file']))
		exec(server_sudo().'systemd-run --quiet --collect --on-active=300 --uid='.(int)$pw['uid'].' --gid='.(int)$pw['gid']
		     .' /usr/bin/rm -f -- '.escapeshellarg($r['file']).' >/dev/null 2>&1');
	log_debug('[wp] '.auth_user().' made a one-time sign-in for '.$row['domain'].(trim((string)$row['path'], '/') !== '' ? '/'.trim($row['path'], '/') : ''));
	return result_ok('', array('url' => rtrim($site, '/').'/?reqad_login='.$token, 'expires' => time() + 120));
}

/* The must-use plugin of wordpress_login(). Runs inside the account's site,
   on any PHP from 5.6 on (no ?? or immediately called closures). */
function wordpress_login_code() {
	return <<<'EOT'
<?php
/*
 * One-time sign-in from the hosting panel (WordPress Toolkit > Sites > Log in).
 * The panel made this file for one click. It removes itself once used or
 * expired; deleting it by hand is always safe.
 */
if (!defined('ABSPATH'))
	exit;
call_user_func(function () {
	$expires = REQAD_EXPIRES;
	$hash = 'REQAD_HASH';
	$t = isset($_GET['reqad_login']) && is_string($_GET['reqad_login']) ? $_GET['reqad_login'] : '';
	$mine = $t !== '' && hash_equals($hash, hash('sha256', $t));
	if (time() > $expires) {
		@unlink(__FILE__);
		if ($mine)
			wp_die('This sign-in link has expired. Open the site again from the hosting panel.', 'Sign-in link expired', array('response' => 403));
		return;
	}
	if (!$mine)
		return;
	add_action('init', function () use ($t, $hash) {
		$want = wp_parse_url(site_url('/'));
		$host = strtolower(isset($want['host']) ? $want['host'] : '').(isset($want['port']) ? ':'.$want['port'] : '');
		$here = strtolower(isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '');
		if ($host !== $here || (isset($want['scheme']) && $want['scheme'] === 'https' && !is_ssl())) {
			wp_redirect(add_query_arg('reqad_login', $t, site_url('/')));
			exit;
		}
		nocache_headers();
		$used = 'reqad_login_'.substr($hash, 0, 20);
		@unlink(__FILE__);
		if (get_transient($used))
			wp_die('This sign-in link was already used. Open the site again from the hosting panel.', 'Sign-in link used', array('response' => 403));
		set_transient($used, 1, 900);
		$user = null;
		if (is_multisite())
			foreach (get_super_admins() as $login)
				if (($user = get_user_by('login', $login)))
					break;
		if (!$user) {
			$a = get_users(array('role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC'));
			$user = $a ? $a[0] : null;
		}
		if (!$user)
			wp_die('This site has no administrator to sign in as.', 'No administrator', array('response' => 403));
		wp_set_current_user($user->ID);
		wp_set_auth_cookie($user->ID, false);
		do_action('wp_login', $user->user_login, $user);
		wp_safe_redirect(admin_url());
		exit;
	}, 1);
});
EOT;
}

/* ---- a new WordPress ------------------------------------------------------- */

/* WordPress's languages, code => name (English first): wordpress.org's list,
   kept a week in wptoolkit/translations.json (English alone until it can be had). */
function wordpress_locales() {
	$f = _PATH.'/wptoolkit/translations.json';
	$age = @filemtime($f);
	if ($age === false || $age < time() - 7 * 86400) {
		$d = json_decode((string)shell_exec('curl -fsS --max-time 8 https://api.wordpress.org/translations/core/1.0/ 2>/dev/null'), true);
		$list = array();
		foreach ((is_array($d) && isset($d['translations']) && is_array($d['translations'])) ? $d['translations'] : array() as $t)
			if (preg_match('/^[a-z]{2,3}(_[A-Z]{2})?(_[a-z0-9]+)?$/', (string)($t['language'] ?? '')))
				$list[$t['language']] = (string)($t['native_name'] ?? ($t['english_name'] ?? $t['language']));
		if ($list) {
			@file_put_contents($f.'.tmp', json_encode($list, JSON_UNESCAPED_UNICODE));
			@rename($f.'.tmp', $f);
			if (function_exists('posix_geteuid') && posix_geteuid() === 0)
				@chown($f, 'reqad');
		} elseif ($age !== false)
			@touch($f, time() - 6 * 86400);         // try again in a day
	}
	$list = @json_decode((string)@file_get_contents($f), true);
	$list = is_array($list) ? $list : array();
	asort($list, SORT_NATURAL | SORT_FLAG_CASE);
	return array('en_US' => 'English (United States)') + $list;
}

/* The oldest PHP the current WordPress runs on (wordpress.org's offer says
   php_version; 7.4 since WordPress 6.6 - raise it with WordPress). */
function wordpress_min_php() {
	return '7.4';
}

/*
 * The account's sites a WordPress can go into: domain, folder (~/...),
 * docroot, php, https (the site has a certificate: the new site's address
 * starts with https://), trusted (a certificate browsers accept covers the
 * domain), installs (folders of the site that
 * have a WordPress already, '' = the site itself) and blocked ('' or why not).
 */
function wordpress_install_targets($account) {
	global $db, $ini;
	$have = array();
	foreach (wordpress_sites($account) as $w)
		$have[strtolower($w['domain'])][] = $w['path'];
	$out = array();
	foreach (domain_sites($account) as $s) {
		$php = wp_site_php_version($ini, $s['domain']);
		/* a site with any certificate sends http:// on to https:// (port 80 only redirects), so its
		   WordPress must have https:// - also while the certificate is self-signed */
		$c = $s['cert'];
		$https = (bool)$c;
		$trusted = $c && !$c['self_signed'] && !$c['expired'] && domain_cert_covers($c['names'], $s['domain']);
		$blocked = '';
		if ($php === 'disabled' || $php === '')
			$blocked = 'PHP is turned off for this site.';
		elseif (version_compare($php, wordpress_min_php(), '<'))
			$blocked = 'WordPress needs PHP '.wordpress_min_php().' or newer, and this site runs PHP '.$php.'. Choose a newer PHP version for it first.';
		elseif (is_link($s['docroot']))
			$blocked = 'This site is deployed from Git: its files come from the deployment.';
		elseif (strpos(account_vhost_file_read(redirect_vhost($s)), '# BEGIN reqad-app') !== false)
			$blocked = 'This site runs a Node.js or Python app.';
		elseif (strpos($s['docroot'], '/home/'.$account.'/') !== 0)
			$blocked = 'This site\'s folder is not in your home folder.';
		$out[] = array('domain' => $s['domain'], 'folder' => $s['folder'], 'docroot' => $s['docroot'], 'php' => $php,
		               'https' => $https, 'trusted' => $trusted, 'installs' => $have[$s['domain']] ?? array(), 'blocked' => $blocked);
	}
	return $out;
}

/* The checks of a new WordPress's settings: array(clean settings, '') or array(null, why not). */
function wordpress_install_check($account, array $o, array $targets) {
	$domain = strtolower(trim((string)($o['domain'] ?? '')));
	$t = null;
	foreach ($targets as $x)
		if ($x['domain'] === $domain)
			$t = $x;
	if (!$t)
		return array(null, 'That site is not one of yours.');
	if ($t['blocked'] !== '')
		return array(null, $t['blocked']);
	$path = trim(preg_replace('#/+#', '/', str_replace('\\', '/', trim((string)($o['path'] ?? '')))), '/');
	if ($path !== '' && (!preg_match('#^[A-Za-z0-9][A-Za-z0-9._-]{0,63}(/[A-Za-z0-9][A-Za-z0-9._-]{0,63}){0,2}$#', $path)
	                     || preg_match('#(^|/)(wp-admin|wp-includes|wp-content|cgi-bin|\.well-known)(/|$)#i', $path)))
		return array(null, 'The folder may have letters, digits, dots, dashes and underscores, at most 3 levels (for example blog or shop/en).');
	foreach ($t['installs'] as $p)
		if (strcasecmp($p, $path) === 0)
			return array(null, 'There is a WordPress at '.$domain.($path !== '' ? '/'.$path : '').' already.');
	$title = trim(preg_replace('/\s+/u', ' ', (string)($o['title'] ?? '')));
	if ($title === '')
		$title = $domain;
	if (mb_strlen($title) > 200)
		return array(null, 'The site title is too long (200 characters at most).');
	$admin = trim((string)($o['admin_user'] ?? ''));
	/* WordPress's own rule (sanitize_user, strict): up to 60 of these, no minimum */
	if (!preg_match('/^[A-Za-z0-9._@-]{1,60}$/', $admin))
		return array(null, 'The administrator\'s user name may have up to 60 letters, digits or . _ @ -');
	if (in_array(strtolower($admin), array('admin', 'administrator', 'root', 'wordpress', 'wp', 'user', 'test'), true))
		return array(null, '"'.$admin.'" is the first name password guessers try. Choose another user name.');
	$pw = (string)($o['admin_password'] ?? '');
	$generated = $pw === '';
	if ($generated)
		$pw = mailbox_random_password(20);
	elseif (($e = auth_password_policy($pw, $admin)) !== '')
		return array(null, $e);
	$email = trim((string)($o['admin_email'] ?? ''));
	if ($email === '')
		$email = account_contact($account);
	if (!filter_var($email, FILTER_VALIDATE_EMAIL))
		return array(null, 'Give the administrator\'s email address: WordPress sends password resets there.');
	$locale = (string)($o['locale'] ?? 'en_US');
	if ($locale === '' || !isset(wordpress_locales()[$locale]))
		$locale = 'en_US';
	return array(array('site' => $t, 'domain' => $domain, 'path' => $path, 'title' => $title, 'admin_user' => $admin,
	                   'admin_password' => $pw, 'generated' => $generated, 'admin_email' => $email, 'locale' => $locale), '');
}

/*
 * Apache: WordPress's own rewrite block in the install's .htaccess ($rel_doc:
 * its folder from the home, $path: from the site's root), written as the
 * account. wp-cli cannot write it (`rewrite flush --hard` sees no mod_rewrite
 * on the command line), and without it pretty permalinks 404 - and so do the
 * addresses plugins such as WPS Hide Login make. An existing block is kept.
 * '' or why not.
 */
function wordpress_htaccess($user, $rel_doc, $path) {
	$base = '/'.($path !== '' ? trim($path, '/').'/' : '');
	$block = "# BEGIN WordPress\n<IfModule mod_rewrite.c>\nRewriteEngine On\n"
		."RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]\nRewriteBase ".$base."\n"
		."RewriteRule ^index\\.php$ - [L]\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\n"
		."RewriteRule . ".$base."index.php [L]\n</IfModule>\n# END WordPress\n";
	$f = $rel_doc.'/.htaccess';
	$r = files_read($user, $f);
	if ($r['ok']) {
		$c = (string)$r['data']['content'];
		if (strpos($c, '# BEGIN WordPress') !== false)
			return '';
		$w = files_save($user, $f, rtrim($c, "\n").($c !== '' ? "\n\n" : '').$block, $r['data']['mtime']);
	} else
		$w = files_save($user, $f, $block, null, true);
	return $w['ok'] ? '' : '.htaccess could not be written: '.$w['error'];
}

/*
 * A new WordPress for $account. $o: domain (one of the account's sites), path
 * ('' = the site itself, or a folder of it such as blog), title, admin_user,
 * admin_password ('' = made up and given back once), admin_email ('' = the
 * account's contact address), locale (en_US, de_DE, ...).
 *
 * Everything in the home folder is done AS the account: the folder is checked
 * (empty but for the placeholder page and other sites' folders) and a hidden
 * stage folder made in it (fm-helper wpprep), wp-cli downloads WordPress and
 * writes wp-config.php there, then it is moved in place (wpplace). The
 * database "<account>_wp[N]" and its user are new; the passwords reach
 * wp-cli on stdin. Anything that fails takes back what was made. WordPress in
 * a folder gets its own nginx location (wordpress_vhost_folders()); on Apache
 * the install gets WordPress's rewrite block in .htaccess (wordpress_htaccess()).
 */
function wordpress_install($account, array $o) {
	global $db, $ini;
	$acct = valid_username((string)$account) ? account_get($db, $account) : null;
	if (!$acct)
		return result_error("No hosting account '$account'.");
	$user = $acct['user'];
	$home = '/home/'.$user;
	$targets = wordpress_install_targets($user);
	list($c, $err) = wordpress_install_check($user, $o, $targets);
	if (!$c)
		return result_error($err);
	$domain = $c['domain'];
	$path = $c['path'];
	$row = array('id' => 0, 'user' => $user, 'domain' => $domain, 'path' => $path);
	$doc = rtrim(wp_site_docroot($user, $path, $domain), '/');
	if (strpos($doc, $home.'/') !== 0)
		return result_error('This site\'s folder is not in your home folder.');
	$site = $domain.($path !== '' ? '/'.$path : '');

	/* other sites' folders inside the new one stay (a subdomain in ~/public_html/blog) */
	$keep = array();
	foreach ($targets as $x) {
		$dirs = array(rtrim($x['docroot'], '/'));
		foreach ($x['installs'] as $p)
			if ($p !== '')
				$dirs[] = rtrim($x['docroot'], '/').'/'.$p;
		foreach ($dirs as $d)
			if (dirname($d) === $doc)
				$keep[] = basename($d);
	}
	$prep = files_run($user, array('verb' => 'wpprep', 'path' => substr($doc, strlen($home) + 1),
	                               'stage' => '.reqad-wp-'.bin2hex(random_bytes(6)), 'keep' => array_values(array_unique($keep))), null, null, 60);
	if (empty($prep['ok'])) {
		$r = files_result($prep);
		return result_error(($prep['code'] ?? '') === 'notempty'
			? $r['error'].' WordPress goes into an empty folder: move those files away first, or choose another folder.' : $r['error']);
	}
	$stage_rel = (string)$prep['stage'];
	$p = ' --path='.escapeshellarg($home.'/'.$stage_rel);
	$database = '';
	$made = (string)($prep['made'] ?? '');
	$undo = function () use ($user, $stage_rel, $made, &$database) {
		/* the stage, or the folders made for it (empty but for the stage) */
		files_run($user, array('verb' => 'delete', 'paths' => array($made !== '' ? $made : $stage_rel)), null, null, 120);
		if ($database !== '')
			database_delete($database);
	};

	$r = wordpress_cli($row, 'core download --locale='.escapeshellarg($c['locale']).$p, 150);
	if ($r['rc'] !== 0) {
		$undo();
		return result_error('WordPress could not be downloaded: '.wp_cli_error($r));
	}
	/* a database and user of its own: <account>_wp, _wp2, ... */
	$dbs = mysql_rows('SHOW DATABASES');
	$users = mysql_rows('SELECT User FROM mysql.user');
	$name = '';
	for ($i = 1; $i <= 99 && $name === ''; $i++) {
		$n = 'wp'.($i > 1 ? $i : '');
		if (!in_array($user.'_'.$n, $dbs, true) && !in_array($user.'_'.$n, $users, true))
			$name = $n;
	}
	$dbpass = mailbox_random_password(24);
	$d = $name !== '' ? database_create(array('account' => $user, 'name' => $name, 'dbuser' => $name, 'password' => $dbpass))
	                  : result_error('There is no free database name left.');
	if (!$d['ok']) {
		$undo();
		return result_error('The database could not be made: '.$d['error']);
	}
	$database = $d['data']['database'];
	$r = wordpress_cli($row, 'config create --dbname='.escapeshellarg($database).' --dbuser='.escapeshellarg($d['data']['user'])
		.' --dbhost=localhost --locale='.escapeshellarg($c['locale']).' --skip-check --prompt=dbpass'.$p, 60, $dbpass."\n");
	if ($r['rc'] !== 0) {
		$undo();
		return result_error('wp-config.php could not be written: '.wp_cli_error($r));
	}
	$url = ($c['site']['https'] ? 'https' : 'http').'://'.$domain.($path !== '' ? '/'.$path : '');
	$r = wordpress_cli($row, 'core install --url='.escapeshellarg($url).' --title='.escapeshellarg($c['title'])
		.' --admin_user='.escapeshellarg($c['admin_user']).' --admin_email='.escapeshellarg($c['admin_email'])
		.' --skip-email --prompt=admin_password'.$p, 90, $c['admin_password']."\n");
	if ($r['rc'] !== 0) {
		$undo();
		return result_error('WordPress could not be set up: '.wp_cli_error($r));
	}
	$place = files_run($user, array('verb' => 'wpplace', 'stage' => $stage_rel), null, null, 60);
	if (empty($place['ok'])) {
		$undo();
		return result_error('WordPress could not be moved in place: '.files_result($place)['error']);
	}
	$database_made = $database;
	$database = '';                                  // from here on, it stays
	$rel_doc = substr($doc, strlen($home) + 1);
	files_chmod($user, $rel_doc.'/wp-config.php', '0640');

	$v = wordpress_cli($row, 'core version --path='.escapeshellarg($doc), 60);
	$version = $v['rc'] === 0 ? substr(trim($v['out']), 0, 8) : '';
	$ins = $db->prepare('INSERT INTO wordpress (user, domain, title, wp_version, path, comments, status, created_at)
	                     VALUES (:u, :d, :t, :v, :p, "", :s, datetime("now"))');
	$ins->bindValue(':u', $user, SQLITE3_TEXT);
	$ins->bindValue(':d', $domain, SQLITE3_TEXT);
	$ins->bindValue(':t', mb_substr($c['title'], 0, 255), SQLITE3_TEXT);
	$ins->bindValue(':v', $version, SQLITE3_TEXT);
	$ins->bindValue(':p', $path, SQLITE3_TEXT);
	$ins->bindValue(':s', (string)($acct['status'] ?? 'active'), SQLITE3_TEXT);
	$ins->execute();
	$new = wordpress_find($db->lastInsertRowID());

	$warn = array();
	/* addresses like /hello-world/: nginx sends a folder's to its own index.php */
	if (web_php_server($ini) === 'nginx') {
		if ($path !== '' && ($e = wordpress_vhost_folders($user, $domain)) !== '')
			$warn[] = 'The web server could not be set up for WordPress in a folder, so its pages keep addresses like ?p=1: '.$e;
		else {
			/* not `wp rewrite structure`: it starts another wp-cli (proc_open, off in the command-line PHP) */
			$rw = wordpress_cli($row, 'option update permalink_structure '.escapeshellarg('/%postname%/').' --path='.escapeshellarg($doc), 60);
			if ($rw['rc'] === 0)
				$rw = wordpress_cli($row, 'rewrite flush --path='.escapeshellarg($doc), 60);
			if ($rw['rc'] !== 0)
				$warn[] = 'Page addresses stay like ?p=1: '.wp_cli_error($rw);
		}
	} elseif (($e = wordpress_htaccess($user, $rel_doc, $path)) !== '')
		$warn[] = 'Page addresses stay like ?p=1: '.$e;
	else {
		$rw = wordpress_cli($row, 'option update permalink_structure '.escapeshellarg('/%postname%/').' --path='.escapeshellarg($doc), 60);
		if ($rw['rc'] !== 0)
			$warn[] = 'Page addresses stay like ?p=1: '.wp_cli_error($rw);
	}
	/* OPcache would still serve the placeholder's index.php for a moment */
	if ($new)
		wordpress_purge_caches($new, false);
	log_debug('[wp] '.auth_user().' installed WordPress '.$version.' at '.$site.' ('.$database_made.')');
	plugin_hook('wordpress.installed', array('account' => $user, 'domain' => $domain, 'path' => $path, 'database' => $database_made));
	$data = array('site' => $new ? wordpress_public($new) : null, 'url' => $url.'/', 'admin_url' => $url.'/wp-admin/',
	              'admin_user' => $c['admin_user'], 'admin_email' => $c['admin_email'], 'database' => $database_made,
	              'database_user' => $d['data']['user'], 'folder' => '~/'.$rel_doc, 'version' => $version);
	if ($c['generated'])
		$data['admin_password'] = $c['admin_password'];
	return result_ok('WordPress '.$version.' is installed at '.$site.'.', $data, $warn);
}

/*
 * WordPress in a folder of a site (example.com/blog) on nginx: its own
 * addresses (/blog/hello-world/) must go to its own index.php, not the
 * site's. A "# BEGIN reqad-wp-folders" block at the top of each `location /`
 * of the site's vhost holds one location per folder install in the list;
 * rewritten from the list (install, scan), taken out when there is none.
 * The site's Security rules are rendered again too, so they cover every
 * install. Returns '' or the error (the vhost is put back when nginx -t fails).
 */
function wordpress_vhost_folders($user, $domain) {
	global $db, $ini;
	if (web_php_server($ini) !== 'nginx')
		return '';
	$site = domain_site($user, $domain);
	if (!$site)
		return '';
	$file = redirect_vhost($site);
	$orig = account_vhost_file_read($file);
	if (trim($orig) === '')
		return '';
	$paths = array();
	$stmt = $db->prepare('SELECT path FROM wordpress WHERE user = :u AND lower(domain) = :d');
	$stmt->bindValue(':u', $user, SQLITE3_TEXT);
	$stmt->bindValue(':d', strtolower($domain), SQLITE3_TEXT);
	$res = $stmt->execute();
	while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
		$pth = trim((string)$r['path'], '/');
		if ($pth !== '' && preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]*$#', $pth) && strpos($pth, '..') === false)
			$paths[] = $pth;
	}
	$new = wordpress_vhost_folders_edit($orig, array_values(array_unique($paths)));
	$e = $new === $orig ? '' : account_vhost_files_apply(array($file => array($orig, $new)));
	/* the Security rules of the site must cover a new (or no longer an old) install */
	$keys = $e === '' ? wp_sec_nginx_enabled($domain) : array();
	if ($keys) {
		$r = wp_sec_nginx_apply($user, $domain, '', $keys, wp_sec_allowed_ips($domain));
		$e = $r['error'];
	}
	return $e;
}

/* A vhost's text with its folder-install block set to $paths (none: taken out). */
function wordpress_vhost_folders_edit($conf, array $paths) {
	$conf = preg_replace('/\n[ \t]*# BEGIN reqad-wp-folders\n.*?\n[ \t]*# END reqad-wp-folders(?=\n|$)/s', '', $conf);
	/* only a WordPress-style `location /` (try_files to /index.php), not an app's or a proxy's */
	if (!$paths || strpos($conf, 'try_files $uri $uri/ /index.php') === false)
		return $conf;
	rsort($paths);
	$block = "# BEGIN reqad-wp-folders\n# WordPress in a folder: its addresses go to its own index.php (WP Toolkit)\n";
	foreach ($paths as $pth)
		$block .= 'location /'.$pth.'/ { try_files $uri $uri/ /'.$pth.'/index.php$is_args$args; }'."\n";
	$block .= '# END reqad-wp-folders';
	return preg_replace_callback('/^([ \t]*)location \/ \{[ \t]*$/m', function ($m) use ($block) {
		return $m[0]."\n".conf_indent($block, $m[1].'    ');
	}, $conf);
}
