<?php
/*
 * WordPress Toolkit > Clone or staging: a copy of an install as a new site
 * of the same account (a subdomain such as staging.example.com, or another
 * domain), for the API and the account panel (GET/POST
 * /v1/wordpress/{site}/copies, POST .../access, DELETE /v1/wordpress/{site}).
 *
 *   wordpress_copy_status($row)          a site's copies, or what it is a copy of
 *   wordpress_copy_start($row, $o, $by)  check, then copy in the background
 *                                        (a wordpress_jobs row of kind copy)
 *   wordpress_copy_run($row, $o)         the copy itself (inside the job's unit)
 *   wordpress_copy_access_set($row, $on, $ips)  only these addresses may open a copy
 *   wordpress_copy_delete($row)          remove a copy: its site, files and database
 *   (Push to live, a staging copy's code back to its live site: wordpress_push.php)
 *
 * kind staging: a private copy to try changes on. Search engines are told
 * not to index it, it sends no email and WordPress does not update itself
 * there (the must-use plugin reqad-staging.php), WordPress knows it is a
 * staging site (WP_ENVIRONMENT_TYPE, which plugins such as WooCommerce and
 * Jetpack read) and runs no scheduled tasks (DISABLE_WP_CRON: no renewals
 * charged, no newsletters sent from a copy). kind clone: an independent
 * second site, which keeps the live site's settings.
 *
 * The new site is made by domain_addon_add() (folder ~/<domain>, made as the
 * account; no www., no email), on the live site's PHP version and settings.
 * The files are copied AS the account into a hidden stage of it (fm-helper
 * verb wpclone: rsync without other sites' folders and installs, caches,
 * backups and the object cache drop-in, which would share the live site's
 * cache), whose wp-config.php gets the copy's own database before anything
 * can run it; then they are moved in place (wpplace). The database is a new
 * one ("<account>_wp<N>"), filled by root's mysqldump | mysql (the live
 * DB_NAME must be one of the account's own: database_owner(), as for the
 * update backups), and its addresses are changed with wp search-replace as
 * the account. Anything that fails takes the new site and database away
 * again. Table wordpress_copies (db 1052) links a copy to its live site.
 *
 * Who may open a copy: a geo/map pair at the top of its vhost and an `if`
 * at the top of each server ("# BEGIN reqad-staging-access[-http]"); others
 * get the 503 page. The block is the inventory (the addresses). Let's
 * Encrypt's checks and the error pages stay open, and this server's own
 * addresses are always allowed (the toolkit's checks, WordPress's loopback).
 */

function wordpress_copies_ready() {
	global $db;
	return (bool)$db->querySingle("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'wordpress_copies'");
}

/* The copies row of a site (it is a copy made here), or null. */
function wordpress_copy_get($site_id) {
	global $db;
	if (!wordpress_copies_ready())
		return null;
	$r = $db->querySingle('SELECT * FROM wordpress_copies WHERE site_id = '.(int)$site_id, true);
	return $r ?: null;
}

/* site id => array(kind, source) of every copy (wordpress_public() marks them; $fresh after a change). */
function wordpress_copy_map($fresh = false) {
	global $db;
	static $map = null;
	if ($map !== null && !$fresh)
		return $map;
	$map = array();
	if (!($db instanceof SQLite3) || !wordpress_copies_ready())
		return $map;
	$res = $db->query('SELECT site_id, source_id, kind FROM wordpress_copies');
	while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC)))
		$map[(int)$r['site_id']] = array('kind' => $r['kind'], 'source' => (int)$r['source_id']);
	return $map;
}

function wordpress_site_label(array $row) {
	$path = trim((string)($row['path'] ?? ''), '/');
	return $row['domain'].($path !== '' ? '/'.$path : '');
}

/* A free name for a new copy: staging.<domain> (staging-<folder>.<domain>
   for an install in a folder), then staging2... */
function wordpress_copy_suggest(array $row) {
	global $db;
	$acct = account_get($db, $row['user']);
	$base = strtolower($row['domain']);
	$path = trim((string)$row['path'], '/');
	$first = 'staging'.($path !== '' ? '-'.preg_replace('/[^a-z0-9]+/', '-', strtolower(basename($path))) : '');
	for ($i = 1; $i < 20; $i++) {
		$name = $first.($i > 1 ? $i : '').'.'.$base;
		if (domain_claim_error($row['user'], $name) !== '')
			continue;
		/* its folder ~/<name> must be free too (asked as the account) */
		$st = files_run($row['user'], array('verb' => 'stat', 'path' => $name));
		if (empty($st['ok']) && ($st['code'] ?? '') === 'missing')
			return $name;
	}
	return $first.'.'.($acct ? strtolower($acct['domain']) : $base);
}

/*
 * What the copy needs to know of the live site, read by one wp-cli start
 * (plugins and themes off): array(data, '') or array(null, why not).
 */
function wordpress_copy_probe(array $row) {
	$code = <<<'EOT'
global $table_prefix;
echo "\n##REQAD##".json_encode(array(
	'home' => (string)get_option('home'), 'siteurl' => (string)get_option('siteurl'), 'multisite' => is_multisite(),
	'db_name' => DB_NAME, 'db_host' => DB_HOST, 'prefix' => (string)$table_prefix, 'title' => (string)get_option('blogname'),
	'wp_home' => defined('WP_HOME'), 'wp_siteurl' => defined('WP_SITEURL'),
	'object_cache' => file_exists(WP_CONTENT_DIR.'/object-cache.php'), 'version' => (string)$GLOBALS['wp_version'],
));
EOT;
	$r = wordpress_cli($row, 'eval '.escapeshellarg($code).' --skip-plugins --skip-themes --path='.escapeshellarg(wordpress_docroot($row)), 90);
	$pos = strrpos($r['out'], '##REQAD##');
	$d = $pos !== false ? json_decode(substr($r['out'], $pos + 9), true) : null;
	if (!is_array($d))
		return array(null, (wp_cli_error(array('out' => $r['out'], 'rc' => $r['rc'] ?: 1)) ?: 'WordPress does not start').'.');
	if (!empty($d['multisite']))
		return array(null, 'This is a WordPress network (multisite): the panel cannot copy those yet.');
	if (!preg_match('/^[A-Za-z0-9_$-]{1,64}$/', (string)$d['db_name']) || database_owner($d['db_name']) !== $row['user'])
		return array(null, 'The site\'s database '.$d['db_name'].' is not one of '.$row['user'].'\'s databases.');
	if (!preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$|^localhost:\//', (string)$d['db_host']))
		return array(null, 'The site\'s database is on another server ('.$d['db_host'].').');
	if (!preg_match('~^https?://[A-Za-z0-9.-]+(:\d+)?(/[^\s?#]*)?$~', (string)$d['home']))
		return array(null, 'The site\'s address in WordPress ('.$d['home'].') is not one the panel can change.');
	return array($d, '');
}

/*
 * The checks of a new copy: array(clean options, '') or array(null, why not).
 * $o: kind (staging|clone), domain (a new subdomain or domain of the account),
 * restrict (only allow_ips may open it), allow_ips (array or text).
 */
function wordpress_copy_check(array $row, array $o) {
	global $ini;
	$user = $row['user'];
	if (!wordpress_copies_ready() || !wordpress_jobs_kinds())
		return array(null, 'The tables for copies are missing: apply db/1052.sql (the Reqad update does it).');
	$kind = (string)($o['kind'] ?? 'staging');
	if (!in_array($kind, array('staging', 'clone'), true))
		return array(null, 'Make a staging copy or a clone.');
	$c = wordpress_copy_get($row['id']);
	if ($c && $c['kind'] === 'staging' && $kind === 'staging')
		return array(null, 'This is a staging copy already: make the staging copy from the live site.');
	$doc = wordpress_docroot($row);
	if (strpos($doc, '/home/'.$user.'/') !== 0)
		return array(null, 'This WordPress site is not in the account\'s home folder.');
	if (is_link(rtrim($doc, '/')))
		return array(null, 'This site is deployed from Git: its files come from the deployment.');
	if (in_array(wp_site_php_version($ini, $row['domain']), array('', 'disabled', 'off'), true))
		return array(null, 'PHP is turned off for this site.');
	$domain = strtolower(rtrim(trim((string)($o['domain'] ?? '')), '.'));
	if (($e = domain_claim_error($user, $domain)) !== '')
		return array(null, $e);
	if (($e = domain_folder_error($domain)) !== '')
		return array(null, $e);
	$st = files_run($user, array('verb' => 'stat', 'path' => $domain));
	if (!empty($st['ok']) || ($st['code'] ?? '') !== 'missing')
		return array(null, "There is a folder ~/$domain already. Choose another name, or move that folder away first.");
	if (($e = limit_allows($user, 'addon', array('domain' => $domain))) !== '')
		return array(null, $e);
	if (($e = limit_allows($user, 'database', array('database' => $user.'_wp', 'user' => $user.'_wp'))) !== '')
		return array(null, $e);
	$restrict = !empty($o['restrict']);
	$ips = array();
	if ($restrict) {
		list($ips, $e) = wordpress_copy_ips($o['allow_ips'] ?? array());
		if ($e !== '')
			return array(null, $e);
		if (web_front($ini) !== 'nginx')
			return array(null, 'Limiting who can open a site works only with nginx in front.');
	}
	return array(array('kind' => $kind, 'domain' => $domain, 'restrict' => $restrict, 'allow_ips' => $ips), '');
}

/* Clean addresses (array or text): array(list, '') or array(null, why not). */
function wordpress_copy_ips($in) {
	$list = is_array($in) ? $in : preg_split('/[\s,;]+/', (string)$in, -1, PREG_SPLIT_NO_EMPTY);
	$ips = array();
	foreach ($list as $x) {
		$v = wp_sec_valid_ip((string)$x);
		if ($v === '')
			return array(null, '"'.substr((string)$x, 0, 60).'" is not an IP address or a range such as 203.0.113.0/24.');
		$ips[] = $v;
	}
	$ips = array_values(array_unique($ips));
	if (!$ips)
		return array(null, 'Add at least one address that may open the site (yours is a good start).');
	if (count($ips) > 50)
		return array(null, 'At most 50 addresses.');
	return array($ips, '');
}

/* Check, then make the copy in the background (wordpress_copy_run() in the site's job unit). */
function wordpress_copy_start(array $row, array $o, $by = '') {
	list($c, $err) = wordpress_copy_check($row, $o);
	if (!$c)
		return result_error($err);
	list($p, $err) = wordpress_copy_probe($row);
	if (!$p)
		return result_error('The site cannot be copied: '.$err);
	$r = wordpress_job_start($row, $c, $by, 'copy');
	if ($r['ok'])
		$r['message'] = 'Copying '.wordpress_site_label($row).' to '.$c['domain'].'. It takes a minute or more, depending on the site\'s size.';
	return $r;
}

/* The folders under $doc that the copy leaves out: other sites' folders and installs in it, caches, backups. */
function wordpress_copy_skip(array $row, $doc) {
	$skip = array('wp-content/cache', 'wp-content/upgrade', 'wp-content/upgrade-temp-backup', 'wp-content/et-cache', 'wp-content/litespeed',
	              'wp-content/updraft', 'wp-content/ai1wm-backups', 'wp-content/backups-dup-lite', 'wp-content/backups-dup-pro',
	              'wp-content/wpvividbackups', 'wp-content/debug.log', 'wp-content/object-cache.php',
	              'wp-content/mu-plugins/reqad-auto-updates.php', 'wp-content/mu-plugins/reqad-staging.php', 'error_log');
	$under = function ($d) use ($doc) {
		$d = rtrim($d, '/');
		return strpos($d.'/', $doc.'/') === 0 && $d !== $doc ? substr($d, strlen($doc) + 1) : '';
	};
	foreach (domain_sites($row['user'], false) as $s)
		if (($r = $under($s['docroot'])) !== '')
			$skip[] = $r;
	foreach (wordpress_sites($row['user']) as $w)
		if ((int)$w['id'] !== (int)$row['id'] && ($r = $under(wp_site_docroot($row['user'], $w['path'], $w['domain']))) !== '')
			$skip[] = $r;
	return array_values(array_unique($skip));
}

/*
 * Make the copy (the job's unit, root; see the top of this file). $o: the
 * clean options of wordpress_copy_check(). data: site (the copy), output
 * (what was done, step by step).
 */
function wordpress_copy_run(array $row, array $o) {
	global $db, $ini;
	$user = $row['user'];
	$home = '/home/'.$user;
	$label = wordpress_site_label($row);
	$log = $warn = array();
	list($c, $err) = wordpress_copy_check($row, $o);
	if (!$c)
		return result_error('Nothing was copied: '.$err);
	$domain = $c['domain'];
	$staging = $c['kind'] === 'staging';
	list($p, $err) = wordpress_copy_probe($row);
	if (!$p)
		return result_error('Nothing was copied: '.$err);
	$doc = rtrim(wordpress_docroot($row), '/');
	$made = false;
	$database = '';
	$out = function () use (&$log) { return array('output' => implode("\n", $log)); };
	$fail = function ($msg) use (&$made, &$database, &$log, $user, $domain, $out) {
		$log[] = '# failed: '.$msg;
		if ($database !== '')
			database_delete($database);
		if ($made)
			domain_addon_delete($user, $domain, true);
		return result_error($msg.' Nothing was kept of the copy.', $out());
	};

	/* the new site, on the live site's PHP */
	$a = domain_addon_add($user, $domain, $domain, false, false);
	if (!$a['ok'])
		return result_error('The new site could not be made: '.$a['error'], $out());
	$made = true;
	$log[] = '# site '.$domain.' made ('.$a['message'].')';
	foreach ($a['warnings'] as $w)
		if (stripos($w, 'points') === false && stripos($w, 'no address') === false)
			$warn[] = $w;
	$new_doc = rtrim((string)$a['data']['site']['docroot'], '/');
	if (strpos($new_doc, $home.'/') !== 0)
		return $fail('The new site\'s folder is not in the home folder.');
	$v = wp_site_php_version($ini, $row['domain']);
	if ($v !== wp_site_php_version($ini, $domain)) {
		$r = php_version_set($user, $domain, $v);
		$log[] = '# PHP '.$v.': '.($r['ok'] ? 'set' : $r['error']);
		if (!$r['ok'])
			$warn[] = 'The copy could not get PHP '.$v.' like the live site: '.$r['error'];
	}
	$ps = php_settings_get($user, $row['domain']);
	$set = array();
	foreach ($ps['ok'] ? $ps['data']['settings'] : array() as $k => $x)
		if ($x['value'] !== '')
			$set[$k] = $x['value'];
	if ($set) {
		$r = php_settings_set($user, $domain, $set);
		$log[] = '# PHP settings '.json_encode($set).': '.($r['ok'] ? 'copied' : $r['error']);
		if (!$r['ok'])
			$warn[] = 'The PHP settings of the live site were not copied: '.$r['error'];
	}
	/* closed from the start, when asked: the copy should never be open to everybody */
	if ($c['restrict'] && ($e = wordpress_copy_access_apply($user, $domain, $c['allow_ips'])) !== '')
		return $fail('Access could not be limited to your addresses: '.$e);

	/* its own database and user: <account>_wp, _wp2, ... */
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
	if (!$d['ok'])
		return $fail('The database could not be made: '.$d['error']);
	$database = $d['data']['database'];
	$log[] = '# database '.$database.' made';

	/* the files, into a hidden stage of the new folder */
	$stage = '.reqad-wp-'.bin2hex(random_bytes(6));
	$f = files_run($user, array('verb' => 'wpclone', 'from' => substr($doc, strlen($home) + 1), 'to' => substr($new_doc, strlen($home) + 1),
	                            'stage' => $stage, 'skip' => wordpress_copy_skip($row, $doc), 'paths' => array($doc.'/' => $new_doc.'/'),
	                            'db' => array('DB_NAME' => $database, 'DB_USER' => $d['data']['user'], 'DB_PASSWORD' => $dbpass)), null, null, 3600);
	if (empty($f['ok']))
		return $fail('The files could not be copied: '.files_result($f)['error']);
	$stage_rel = (string)$f['stage'];
	$log[] = '# files copied'.($f['warning'] !== '' ? ' (some could not be read: '.$f['warning'].')' : '');
	if ($f['warning'] !== '')
		$warn[] = 'Some files could not be read, so the copy does not have them (they belong to another user): '.$f['warning'];

	/* the database: root's dump of the live one into the new one, without DEFINERs (they name the live site's user) */
	$cmd = 'set -o pipefail; '.server_sudo().'mysqldump --single-transaction --quick --routines --triggers --no-tablespaces --default-character-set=utf8mb4 '
	      .escapeshellarg($p['db_name']).' | sed -e '.escapeshellarg('s/DEFINER=`[^`]*`@`[^`]*`//g').' | '.server_sudo().'mysql --default-character-set=utf8mb4 '.escapeshellarg($database);
	exec('bash -c '.escapeshellarg($cmd).' 2>&1', $o2, $rc);
	if ($rc !== 0)
		return $fail('The database could not be copied: '.substr(trim(implode(' ', $o2)), 0, 400));
	$log[] = '# database '.$p['db_name'].' copied into '.$database;

	/* its addresses: wp-cli as the account, on the stage (plugins and themes off) */
	$crow = array('id' => 0, 'user' => $user, 'domain' => $domain, 'path' => '');
	$pth = ' --path='.escapeshellarg($home.'/'.$stage_rel);
	$cert = domain_cert($domain);
	$scheme = $cert ? 'https' : 'http';
	$h = parse_url($p['home']);
	$old = strtolower($h['host']).(isset($h['port']) ? ':'.$h['port'] : '').rtrim((string)($h['path'] ?? ''), '/');
	$new_url = $scheme.'://'.$domain;
	$pairs = array();
	if (($h['scheme'] ?? '') !== $scheme)
		$pairs[] = array(($h['scheme'] ?? 'https').'://'.$old, $new_url);
	$pairs[] = array('//'.$old, '//'.$domain);
	$pairs[] = array(str_replace('/', '\\/', '//'.$old), str_replace('/', '\\/', '//'.$domain));
	$pairs[] = array($doc.'/', $new_doc.'/');
	foreach ($pairs as $pr) {
		$r = wordpress_cli($crow, 'search-replace '.escapeshellarg($pr[0]).' '.escapeshellarg($pr[1])
			.' --all-tables-with-prefix --skip-columns=guid --skip-plugins --skip-themes --report-changed-only'.$pth, 1800);
		$log[] = '$ wp search-replace '.$pr[0].' '.$pr[1]."\n".$r['out'];
		if ($r['rc'] !== 0)
			return $fail('The addresses in the copy\'s database could not be changed: '.wp_cli_error($r));
	}
	/* addresses fixed in wp-config.php */
	$cfg = array();
	if (!empty($p['wp_home']))
		$cfg[] = array('WP_HOME', $new_url, '');
	if (!empty($p['wp_siteurl'])) {
		$s = parse_url($p['siteurl']);
		$cfg[] = array('WP_SITEURL', $new_url.substr(rtrim((string)($s['path'] ?? ''), '/'), strlen(rtrim((string)($h['path'] ?? ''), '/'))), '');
	}
	if ($staging) {
		$cfg[] = array('WP_ENVIRONMENT_TYPE', 'staging', '');
		$cfg[] = array('DISABLE_WP_CRON', 'true', ' --raw');
	}
	foreach ($cfg as $x) {
		$r = wordpress_cli($crow, 'config set '.$x[0].' '.escapeshellarg($x[1]).' --type=constant'.$x[2].$pth, 60);
		$log[] = '$ wp config set '.$x[0].' '.$x[1]."\n".$r['out'];
		if ($r['rc'] !== 0)
			return $fail('wp-config.php of the copy could not be changed: '.wp_cli_error($r));
	}
	if ($staging) {
		foreach (array(array('blog_public', '0'), array('reqad_staging_of', $p['home'])) as $x) {
			$r = wordpress_cli($crow, 'option update '.$x[0].' '.escapeshellarg($x[1]).' --skip-plugins --skip-themes'.$pth, 60);
			$log[] = '$ wp option update '.$x[0].' '.$x[1]."\n".$r['out'];
		}
		/* how its settings start (the live site's): Push to live compares with it */
		if (wordpress_push_snapshot($crow, $home.'/'.$stage_rel))
			$log[] = '# settings recorded as they are now (reqad_staging_base), for Push to live';
		$body = @file_get_contents(_PATH.'/scripts/templates/wordpress-staging.php');
		$mu = $stage_rel.'/wp-content/mu-plugins';
		$m = files_mkdir($user, $mu);
		$s = $body !== false && ($m['ok'] || ($m['data']['code'] ?? '') === 'exists') ? files_save($user, $mu.'/reqad-staging.php', $body, null, true) : array('ok' => false, 'error' => 'the template is missing');
		if (!$s['ok'])
			return $fail('The copy could not be marked as a staging site: '.$s['error']);
		/* the web server serves robots.txt from the folder (WordPress's own is not reached) */
		$rb = "# A staging copy (WordPress Toolkit > Clone or staging): not for search engines.\nUser-agent: *\nDisallow: /\n";
		$r = files_save($user, $stage_rel.'/robots.txt', $rb, null, true);
		if (!$r['ok'] && ($r['data']['code'] ?? '') === 'exists')
			files_save($user, $stage_rel.'/robots.txt', $rb, null, false, true);
		$log[] = '# must-use plugin reqad-staging.php: no email, not indexed, no automatic updates';
	}
	$place = files_run($user, array('verb' => 'wpplace', 'stage' => $stage_rel), null, null, 120);
	if (empty($place['ok']))
		return $fail('The copy could not be moved in place: '.files_result($place)['error']);

	/* in the list, linked to its site */
	$ins = $db->prepare('INSERT INTO wordpress (user, domain, title, wp_version, path, comments, status, created_at)
	                     VALUES (:u, :d, :t, :v, "", "", :s, datetime("now"))');
	$ins->bindValue(':u', $user, SQLITE3_TEXT);
	$ins->bindValue(':d', $domain, SQLITE3_TEXT);
	$ins->bindValue(':t', mb_substr($p['title'] !== '' ? $p['title'] : $domain, 0, 255), SQLITE3_TEXT);
	$ins->bindValue(':v', substr($p['version'], 0, 8), SQLITE3_TEXT);
	$ins->bindValue(':s', (string)($row['status'] ?? 'active'), SQLITE3_TEXT);
	$ins->execute();
	$new_id = (int)$db->lastInsertRowID();
	$database_made = $database;
	$database = '';
	$made = false;                                   // from here on, it stays
	$cp = $db->prepare('INSERT OR REPLACE INTO wordpress_copies (site_id, source_id, account, kind, domain, source_label, db_name, created)
	                    VALUES (:i, :s, :a, :k, :d, :l, :db, :c)');
	foreach (array(':i' => $new_id, ':s' => (int)$row['id'], ':a' => $user, ':k' => $c['kind'], ':d' => $domain, ':l' => $label,
	               ':db' => $database_made, ':c' => time()) as $k => $x)
		$cp->bindValue($k, $x, is_int($x) ? SQLITE3_INTEGER : SQLITE3_TEXT);
	$cp->execute();
	wordpress_copy_map(true);
	$new = wordpress_find($new_id);

	/* the live site's web server rules; a clone also its page cache and cron line */
	$keys = web_php_server($ini) === 'nginx' ? wp_sec_nginx_enabled($row['domain']) : array();
	if ($keys) {
		$r = wp_sec_nginx_apply($user, $domain, '', $keys, wp_sec_allowed_ips($row['domain']));
		$log[] = '# Security rules '.implode(', ', $keys).': '.($r['error'] === '' ? 'copied' : $r['error']);
		if ($r['error'] !== '')
			$warn[] = 'The live site\'s Security rules were not copied: '.$r['error'];
	}
	/* the site's own page cache rules too (they apply once its cache is on) */
	$rules = wp_cache_rules_get($row['domain']);
	if ($rules['pages'] || $rules['cookies'])
		wp_cache_rules_put($domain, $rules);
	if (!$staging && $new) {
		$lc = wordpress_tool_ctx($row);
		list($hash, $tab) = cron_read($user);
		if ($hash !== null && wordpress_cron_has($tab, $lc)) {
			$r = wordpress_wpcron_set(wordpress_tool_ctx($new), true);
			if ($r['error'] !== '')
				$warn[] = 'WordPress\'s scheduled tasks of the clone need a look (Performance): '.$r['error'];
		}
		if (wp_nginx_cache_state($ini, $row['domain']) === 'on') {
			$r = wordpress_tool_set($new, 'nginx_cache', true);
			if (!$r['ok'])
				$warn[] = 'The page cache was not turned on for the clone: '.$r['error'];
		}
	}
	if (!empty($p['object_cache']))
		$warn[] = 'The live site uses an object cache (Redis or Memcached). The copy runs without it, so it never shares the live site\'s cache.';

	/* a certificate browsers trust, when the name points here already */
	$pt = domain_points(array($domain), $user);
	if (!empty($pt[$domain]['here'])) {
		$r = ssl_site_issue($user, $domain);
		$log[] = '# certificate: '.($r['ok'] ? $r['message'] : $r['error']);
		if (!$r['ok'])
			$warn[] = $r['error'].' Get it later in Domains > SSL certificates.';
	} else
		$warn[] = domain_points_note($user, $domain, $pt[$domain] ?? array()).' Then get its free certificate in Domains > SSL certificates.';
	/* certbot rewrites the vhost: the access block must still be there */
	if ($c['restrict'] && ($e = wordpress_copy_access_apply($user, $domain, $c['allow_ips'])) !== '')
		$warn[] = 'Access could not be limited to your addresses: '.$e;

	if ($new) {
		wordpress_purge_caches($new, false);
		list($ok, $code, $why) = wordpress_site_check($new);
		if (!$ok)
			$warn[] = 'The copy does not open properly yet: '.$why.'.';
	}
	log_debug('[wp] copied '.$label.' to '.$domain.' ('.$c['kind'].', '.$database_made.')');
	plugin_hook('wordpress.copied', array('account' => $user, 'domain' => $domain, 'kind' => $c['kind'], 'source' => $label, 'database' => $database_made));
	$msg = $staging ? $domain.' is ready: a staging copy of '.$label.'.' : $domain.' is ready: a clone of '.$label.'.';
	return result_ok($msg, array('site' => $new ? wordpress_public($new) : null) + $out(), $warn);
}

/* ---- who may open a copy --------------------------------------------------- */

function wordpress_copy_access_var($domain) {
	return 'reqad_stg_'.preg_replace('/[^a-z0-9]/', '_', strtolower($domain));
}

/* The addresses allowed to open the site (from its vhost), or null when everybody may. */
function wordpress_copy_access_ips($conf) {
	if (!preg_match('/\n?[ \t]*# BEGIN reqad-staging-access-http\n(.*?)# END reqad-staging-access-http/s', $conf, $m))
		return null;
	preg_match_all('/^\s*(\S+)\s+1;\s*# allowed$/m', $m[1], $x);
	return $x[1];
}

/* The vhost text with access limited to $ips (null: open to everybody). */
function wordpress_copy_access_edit($conf, $domain, $ips) {
	$conf = preg_replace('/(^|\n)[ \t]*# BEGIN reqad-staging-access-http\n.*?# END reqad-staging-access-http[^\n]*\n?/s', '$1', $conf);
	$v = wordpress_copy_access_var($domain);
	$conf = vhost_marker_edit($conf, 'staging-access', $ips === null ? null : 'if ($'.$v.'_deny) { return 503; }', 'nginx');
	if ($conf === null || $ips === null)
		return $conf;
	$b = "# BEGIN reqad-staging-access-http\n# Who may open this site (WordPress Toolkit > Clone or staging); others get the 503 page.\n"
	   . 'geo $'.$v."_ip {\n    default 0;\n";
	foreach (wp_sec_server_ips() as $ip)
		$b .= "    $ip 1; # server\n";
	foreach ($ips as $ip)
		$b .= "    $ip 1; # allowed\n";
	$b .= "}\nmap \"\$".$v."_ip:\$uri\" \$".$v."_deny {\n    default 1;\n    \"~^1:\" 0;\n"
	    . "    \"~^0:/\\.well-known/acme-challenge/\" 0;\n    \"~^0:/reqad-error/\" 0;\n}\n# END reqad-staging-access-http\n";
	return $b.$conf;
}

/* Limit the site $domain of $user to $ips (null: open it). '' or the error. */
function wordpress_copy_access_apply($user, $domain, $ips) {
	global $ini;
	if (web_front($ini) !== 'nginx')
		return 'Limiting who can open a site works only with nginx in front.';
	$site = domain_site($user, $domain);
	if (!$site)
		return "$domain is not a site of $user.";
	$file = redirect_vhost($site);
	$orig = account_vhost_file_read($file);
	if (trim($orig) === '')
		return 'The site\'s web server configuration is missing.';
	$new = wordpress_copy_access_edit($orig, $domain, $ips);
	if ($new === null)
		return 'The site\'s web server configuration has no server block to add the rule to.';
	return $new === $orig ? '' : account_vhost_files_apply(array($file => array($orig, $new)));
}

/* Who may open a copy: on (only the listed addresses), allow_ips. */
function wordpress_copy_access_set(array $row, $on, $ips) {
	if (!wordpress_copy_get($row['id']))
		return result_error('Only copies made in Clone or staging can be limited here.');
	if ($on) {
		list($ips, $e) = wordpress_copy_ips($ips);
		if ($e !== '')
			return result_error($e);
	} else
		$ips = null;
	if (($e = wordpress_copy_access_apply($row['user'], $row['domain'], $ips)) !== '')
		return result_error($e);
	log_debug('[wp] '.auth_user().' '.($on ? 'limited '.$row['domain'].' to '.implode(', ', $ips) : 'opened '.$row['domain'].' to everybody'));
	return result_ok($on ? 'Only the addresses you listed can open '.$row['domain'].' now.' : $row['domain'].' is open to everybody now.',
	                 array('on' => (bool)$on, 'allow_ips' => $ips ?: array()));
}

/* ---- what a page shows ---------------------------------------------------- */

/*
 * A site's copies (copies: [{site, kind, created, restricted}]) and the name
 * suggested for a new one; or, for a copy, what it is a copy of (copy: kind,
 * created, pushed (0 = never), source (the live site, null when it is gone),
 * source_label, restricted, allow_ips, push_job: the newest Push to live from
 * it, a job of the live site). job: the newest copy job of the site.
 */
function wordpress_copy_status(array $row) {
	global $db, $ini;
	if (!wordpress_copies_ready())
		return result_error('The tables for copies are missing: apply db/1052.sql (the Reqad update does it).');
	/* copies whose site is gone from the list */
	$db->exec('DELETE FROM wordpress_copies WHERE site_id NOT IN (SELECT id FROM wordpress)');
	$conf = function ($domain) use ($row) {
		$s = domain_site($row['user'], $domain);
		return $s ? account_vhost_file_read(redirect_vhost($s)) : '';
	};
	$data = array('site' => wordpress_public($row), 'copy' => null, 'copies' => array(), 'suggest' => '',
	              'restrict_available' => web_front($ini) === 'nginx', 'job' => null);
	$c = wordpress_copy_get($row['id']);
	if ($c) {
		$src = wordpress_find($c['source_id']);
		$ips = wordpress_copy_access_ips($conf($row['domain']));
		$data['copy'] = array('kind' => $c['kind'], 'created' => (int)$c['created'], 'pushed' => (int)$c['pushed'], 'source' => $src && $src['user'] === $row['user'] ? wordpress_public($src) : null,
		                      'source_label' => $c['source_label'], 'restricted' => $ips !== null, 'allow_ips' => $ips ?: array(), 'push_job' => null);
		/* Push to live runs as a job of the live site */
		$pj = $c['kind'] === 'staging' && $data['copy']['source'] ? wordpress_job_latest($src['id'], 'push') : null;
		if ($pj && (int)(json_decode($pj['steps'], true)['copy'] ?? 0) === (int)$row['id'])
			$data['copy']['push_job'] = wordpress_job_public($pj, true);
	}
	if (!$c || $c['kind'] === 'clone') {
		$res = $db->query('SELECT * FROM wordpress_copies WHERE source_id = '.(int)$row['id'].' ORDER BY created');
		while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
			$w = wordpress_find($r['site_id']);
			if ($w && $w['user'] === $row['user'])
				$data['copies'][] = array('site' => wordpress_public($w), 'kind' => $r['kind'], 'created' => (int)$r['created'], 'pushed' => (int)$r['pushed'],
				                          'restricted' => wordpress_copy_access_ips($conf($w['domain'])) !== null);
		}
		$data['suggest'] = wordpress_copy_suggest($row);
	}
	$j = wordpress_job_latest($row['id'], 'copy');
	$data['job'] = $j ? wordpress_job_public($j, true) : null;
	return result_ok('', $data);
}

/* ---- removing a copy ------------------------------------------------------ */

/*
 * Remove a copy made here: its site (vhost, certificate, DNS record), its
 * files and its database. The database goes only when it is still the
 * account's own and not the one the live site uses.
 */
function wordpress_copy_delete(array $row) {
	global $db, $ini;
	$c = wordpress_copy_get($row['id']);
	if (!$c || $c['account'] !== $row['user'] || strtolower($c['domain']) !== strtolower($row['domain']) || trim((string)$row['path'], '/') !== '')
		return result_error('Only copies made in Clone or staging can be deleted here.');
	if (wordpress_unit_active($row['id']))
		return result_error($row['domain'].' is being updated right now. Delete it once that is done.');
	$user = $row['user'];
	$domain = strtolower($row['domain']);
	$warn = array();
	$keep = '';
	$src = wordpress_find($c['source_id']);
	$pj = $src ? wordpress_job_latest($src['id'], 'push') : null;
	if ($pj && in_array($pj['status'], array('queued', 'running'), true) && (int)(json_decode($pj['steps'], true)['copy'] ?? 0) === (int)$row['id'])
		return result_error($row['domain'].' is being pushed to its live site right now. Delete it once that is done.');
	if ($src && $src['user'] === $user) {
		$n = wordpress_cli($src, 'config get DB_NAME --path='.escapeshellarg(wordpress_docroot($src)), 60);
		$keep = $n['rc'] === 0 ? trim((string)substr($n['out'], (int)strrpos("\n".$n['out'], "\n"))) : '';
	}
	$ctx = wordpress_tool_ctx($row);
	list($hash, $tab) = cron_read($user);
	if ($hash !== null && wordpress_cron_has($tab, $ctx))
		wordpress_wpcron_set($ctx, false);
	if (web_php_server($ini) === 'nginx' && wp_sec_nginx_enabled($domain))
		wp_sec_nginx_apply($user, $domain, '', array());
	if (wp_nginx_cache_state($ini, $domain) === 'on')
		wordpress_tool_set($row, 'nginx_cache', false);
	$r = domain_addon_delete($user, $domain, true);
	if (!$r['ok'])
		return result_error($domain.' could not be deleted: '.$r['error']);
	$warn = array_merge($warn, $r['warnings']);
	$dbn = (string)$c['db_name'];
	if ($dbn !== '' && $dbn !== $keep && database_owner($dbn) === $user) {
		$d = database_delete($dbn);
		if (!$d['ok'])
			$warn[] = 'Its database '.$dbn.' was not deleted: '.$d['error'];
	} elseif ($dbn !== '')
		$warn[] = 'Its database '.$dbn.' was kept: '.($dbn === $keep ? 'the live site uses it.' : 'it is not one of your databases.');
	$db->exec('DELETE FROM wordpress WHERE id = '.(int)$row['id']);
	$db->exec('DELETE FROM wordpress_copies WHERE site_id = '.(int)$row['id']);
	if (function_exists('wordpress_auto_ready') && wordpress_auto_ready())
		$db->exec('DELETE FROM wordpress_auto WHERE site_id = '.(int)$row['id']);
	wordpress_copy_map(true);
	log_debug('[wp] '.auth_user().' deleted the copy '.$domain.' of '.$c['source_label']);
	plugin_hook('wordpress.copy_deleted', array('account' => $user, 'domain' => $domain, 'kind' => $c['kind'], 'database' => $dbn));
	return result_ok($domain.' is deleted, with its files and database.', array('domain' => $domain), $warn);
}
