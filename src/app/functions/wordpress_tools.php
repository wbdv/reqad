<?php
/*
 * WordPress Toolkit > Performance and Security of one install, for both
 * panels and the API (2087's site page, 2083's WordPress Toolkit, GET/POST
 * /v1/wordpress/{site}/tools and /security).
 *
 *   wordpress_tools_status($row)               every switch and measure: on | off | na
 *   wordpress_tool_set($row, $tool, $on)       nginx_cache | wp_cron | indexing | maintenance
 *   wordpress_security_apply($row, $want, $o)  the Security measures to have, all at once
 *   wordpress_cache_rules_set($row, $pages, $cookies)  the site's own page cache rules
 *
 * What lives in the site's web server (the page cache, the nginx rules of
 * the Security measures) is per SITE (vhost): with several WordPress installs
 * on one domain the rules cover all of them (wp_sec_domain_prefix()). The
 * rest (wp-config.php, options, must-use plugins, the cron line) is per
 * install. Everything inside the home folder happens AS the account: wp-cli
 * (wordpress_cli()), must-use plugins written and removed by fm-helper,
 * permissions checked and fixed by its verb wpperms; root only edits the
 * vhost, the security includes in /etc/nginx/reqad-wp-security and the
 * cache folder in /var/cache/nginx.
 */

/* Everything the tools need to know about an install. */
function wordpress_tool_ctx(array $row) {
	global $db;
	$acct = account_get($db, $row['user']);
	$path = trim((string)($row['path'] ?? ''), '/');
	$site = domain_site($row['user'], $row['domain']);
	$main = $acct && strtolower($acct['domain']) === strtolower($row['domain']);
	$vhost = $site ? redirect_vhost($site) : '';
	$conf = $vhost !== '' ? account_vhost_file_read($vhost) : '';
	$base = wp_cache_zone($row['user']);
	/* the cache zone a vhost already has wins; new ones: the account's name for
	   its main site (as before), name + a short hash for its other sites, so two
	   sites of one account never define the same zone */
	$zone = preg_match('/keys_zone=([a-z0-9_]+):/', $conf, $m) ? $m[1] : ($main ? $base : $base.'_'.substr(sha1(strtolower($row['domain'])), 0, 6));
	$doc = wordpress_docroot($row);
	return array('row' => $row, 'user' => $row['user'], 'domain' => strtolower($row['domain']), 'path' => $path, 'id' => (int)$row['id'],
	             'docroot' => $doc, 'rel' => (string)substr($doc, strlen('/home/'.$row['user'].'/')), 'site' => $site, 'vhost' => $vhost,
	             'conf' => $conf, 'main' => $main, 'zone' => $zone,
	             /* the main site's top install keeps the old marker, so lines made by 2087 before stay ours */
	             'cron_marker' => '# reqad-wpcron-'.$base.($main && $path === '' ? '' : '-'.(int)$row['id']));
}

/* The folders of the WordPress installs on a domain ('' = its top). */
function wordpress_domain_paths($domain) {
	global $db;
	$out = array();
	$stmt = $db->prepare('SELECT path FROM wordpress WHERE lower(domain) = :d');
	$stmt->bindValue(':d', strtolower((string)$domain), SQLITE3_TEXT);
	$res = $stmt->execute();
	while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC)))
		$out[] = trim((string)$r['path'], '/');
	return array_values(array_unique($out));
}

/* One wp-cli run that reads what the switches depend on. array(data, '') or array(null, why). */
function wordpress_tools_probe(array $c) {
	$code = <<<'EOT'
if (!function_exists('is_plugin_active')) require_once ABSPATH.'wp-admin/includes/plugin.php';
$weak = 0;
foreach (array('AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT') as $k)
	if (!defined($k) || strlen(constant($k)) < 32 || constant($k) === 'put your unique phrase here') $weak++;
$mu = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR.'/mu-plugins';
/* WPS Hide Login's own address: /<slug>/ with pretty permalinks, /?<slug> without */
$whl = (string)get_option('whl_page');
$ps = (string)get_option('permalink_structure');
echo "\n##REQAD##".json_encode(array(
	'disable_wp_cron' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
	'file_edit_off'   => defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT,
	'concat_off'      => defined('CONCATENATE_SCRIPTS') && !CONCATENATE_SCRIPTS,
	'ping_status'     => (string)get_option('default_ping_status'),
	'pingback_flag'   => (string)get_option('default_pingback_flag'),
	'blog_public'     => (string)get_option('blog_public'),
	'weak_salts'      => $weak,
	'hide_login'      => is_plugin_active('wps-hide-login/wps-hide-login.php'),
	'login_slug'      => $whl,
	'login_url'       => $whl === '' ? '' : home_url('/').($ps !== '' ? $whl.(substr($ps, -1) === '/' ? '/' : '') : '?'.$whl),
	'maintenance'     => is_file($mu.'/reqad-maintenance.php'),
	'hide_version'    => is_file($mu.'/reqad-hide-version.php'),
	'mu_dir'          => $mu,
));
EOT;
	$r = wordpress_cli($c['row'], 'eval '.escapeshellarg($code).' --skip-themes --skip-plugins --path='.escapeshellarg($c['docroot']), 60);
	$pos = strrpos($r['out'], '##REQAD##');
	$d = $pos !== false ? json_decode(substr($r['out'], $pos + 9), true) : null;
	if (!is_array($d))
		return array(null, wp_cli_error(array('out' => $r['out'], 'rc' => $r['rc'] ?: 1)) ?: 'Could not read the WordPress site.');
	return array($d, '');
}

/* The site's must-use plugins folder, relative to the home folder. */
function wordpress_mu_rel(array $c, $probe = null) {
	$home = '/home/'.$c['user'].'/';
	$mu = is_array($probe) ? (string)($probe['mu_dir'] ?? '') : '';
	return $mu !== '' && strpos($mu, $home) === 0 && strpos($mu, '..') === false ? substr($mu, strlen($home)) : $c['rel'].'/wp-content/mu-plugins';
}

/* Put a must-use plugin from scripts/templates/ in place (or take it out), as the account. '' or the error. */
function wordpress_mu_set(array $c, $file, $src, $on, $probe = null) {
	$rel = wordpress_mu_rel($c, $probe).'/'.$file;
	if (!$on) {
		$r = files_delete($c['user'], array($rel));
		return $r['ok'] || ($r['data']['errors'][0]['error'] ?? '') === 'There is no such file or folder.' ? '' : $r['error'];
	}
	$body = @file_get_contents(_PATH.'/scripts/templates/'.$src);
	if ($body === false)
		return 'The template '.$src.' is missing.';
	$m = files_mkdir($c['user'], dirname($rel));
	if (!$m['ok'] && ($m['data']['code'] ?? '') !== 'exists')
		return $m['error'];
	$r = files_save($c['user'], $rel, $body, null, true);
	if (!$r['ok'] && ($r['data']['code'] ?? '') === 'exists')
		$r = files_save($c['user'], $rel, $body, null, false, true);
	return $r['ok'] ? '' : $r['error'];
}

/* wp-config.php's mode and the first world-writable path (fm-helper, as the account). */
function wordpress_perms(array $c, $fix = false) {
	$r = files_run($c['user'], array('verb' => 'wpperms', 'path' => $c['rel'], 'fix' => (bool)$fix), null, null, 120);
	return !empty($r['ok']) ? $r : null;
}

/* The crontab line that runs the site's due WordPress events when WP-Cron is off. */
function wordpress_cron_line(array $c) {
	global $ini;
	$v = wp_site_php_version($ini, $c['domain']);
	return '*/2 * * * * '.cron_php_bin($v).' /usr/local/bin/wp cron event run --due-now --path='.escapeshellarg($c['docroot'])
	     .' >> /home/'.$c['user'].'/logs/cron.log 2>&1 '.$c['cron_marker'];
}

/* Is the install's cron line in this crontab text */
function wordpress_cron_has($text, array $c) {
	return (bool)preg_match('/'.preg_quote($c['cron_marker'], '/').'[ \t]*$/m', (string)$text);
}

/*
 * Every switch of an install: performance (nginx_cache, wp_cron, indexing,
 * maintenance) and security (the keys of wp_security_options()), each on |
 * off | na, plus why one is na, the login address WPS Hide Login uses, the
 * addresses allowed to log in, and the other installs that share the site's
 * web server rules. $probe: a wp_site_probe() / wordpress_tools_probe() result
 * when the caller has one.
 */
function wordpress_tools_status(array $row, $probe = null) {
	global $ini;
	$c = wordpress_tool_ctx($row);
	$err = '';
	if (!is_array($probe) || ($probe['error'] ?? '') !== '')
		list($probe, $err) = wordpress_tools_probe($c);
	$wp = is_array($probe);
	$nginx = wp_is_nginx($ini);
	$why = array();

	$perf = array();
	if (!$nginx) {
		$perf['nginx_cache'] = 'na';
		$why['nginx_cache'] = 'The page cache needs nginx as the web server.';
	} elseif (web_backend($ini) !== 'none') {
		$perf['nginx_cache'] = 'na';
		$why['nginx_cache'] = 'The page cache is not available while Apache runs behind nginx.';
	} else
		$perf['nginx_cache'] = strpos($c['conf'], '# BEGIN reqad-nginx-cache') !== false ? 'on' : 'off';
	$cron_on = false;
	if ($wp && !empty($probe['disable_wp_cron'])) {
		list($h, $text) = cron_read($c['user']);
		$cron_on = $h !== null && wordpress_cron_has($text, $c);
	}
	$perf['wp_cron'] = $wp ? (!empty($probe['disable_wp_cron']) && $cron_on ? 'on' : 'off') : 'na';
	$perf['indexing'] = $wp ? ((string)$probe['blog_public'] === '0' ? 'off' : 'on') : 'na';
	$perf['maintenance'] = $wp ? (!empty($probe['maintenance']) ? 'on' : 'off') : 'na';

	$sec = array();
	$rules = $nginx ? wp_sec_nginx_enabled($c['domain']) : null;
	foreach (wp_security_options() as $k => $o) {
		if ($o['type'] === 'nginx')
			$sec[$k] = $rules === null ? 'na' : (in_array($k, $rules, true) ? 'on' : 'off');
		else
			$sec[$k] = 'na';
	}
	if (!$nginx)
		foreach (wp_security_options() as $k => $o)
			if ($o['type'] === 'nginx')
				$why[$k] = 'Web server rules need nginx.';
	if ($wp) {
		$sec['file_edit']           = !empty($probe['file_edit_off']) ? 'on' : 'off';
		$sec['concatenate_scripts'] = !empty($probe['concat_off']) ? 'on' : 'off';
		/* WordPress allows pings only when the value is exactly 'open' */
		$sec['pingbacks']           = ($probe['ping_status'] !== 'open' && (string)$probe['pingback_flag'] !== '1') ? 'on' : 'off';
		$sec['hide_login']          = !empty($probe['hide_login']) ? 'on' : 'off';
		$sec['hide_version']        = !empty($probe['hide_version']) ? 'on' : 'off';
		$sec['security_keys']       = (int)$probe['weak_salts'] === 0 ? 'on' : 'off';
	}
	if (php_mode($ini) === 'modphp') {
		$sec['permissions'] = 'na';
		$why['permissions'] = 'With mod_php the web server itself reads wp-config.php.';
	} else {
		$p = wordpress_perms($c);
		if (!$p || $p['config'] === '') {
			$sec['permissions'] = 'na';
			$why['permissions'] = $p ? 'wp-config.php is not in the site\'s folder.' : 'The file permissions could not be read.';
		} else
			$sec['permissions'] = in_array($p['config_mode'], array('0400', '0600'), true) && $p['world_writable'] === '' ? 'on' : 'off';
	}
	$others = array_values(array_diff(wordpress_domain_paths($c['domain']), array($c['path'])));
	return result_ok('', array('site' => wordpress_public($row), 'performance' => $perf, 'security' => $sec, 'why' => $why,
		'login_slug' => $wp ? (string)$probe['login_slug'] : '', 'login_url' => $wp ? (string)($probe['login_url'] ?? '') : '',
		'allow_ips' => wp_sec_allowed_ips($c['domain']),
		'cache_rules' => wp_cache_rules_get($c['domain']),
		'others' => $others, 'nginx' => $nginx, 'wp_error' => $err));
}

/* Turn one Performance switch of an install on or off. */
function wordpress_tool_set(array $row, $tool, $on) {
	global $ini;
	$c = wordpress_tool_ctx($row);
	$on = (bool)$on;
	$p = ' --path='.escapeshellarg($c['docroot']);
	$site = $c['domain'].($c['path'] !== '' ? '/'.$c['path'] : '');
	switch ($tool) {
		case 'nginx_cache':
			$r = $on ? wp_nginx_cache_enable($ini, $c['user'], $c['domain'], $c['docroot'], $c['zone'])
			         : wp_nginx_cache_disable($ini, $c['user'], $c['domain'], $c['docroot'], $c['zone']);
			break;
		case 'wp_cron':
			$r = wordpress_wpcron_set($c, $on);
			break;
		case 'indexing':
			$x = wordpress_cli($row, 'option update blog_public '.($on ? '1' : '0').$p, 60);
			$e = wp_cli_error($x);
			/* robots.txt is made by WordPress and may be in the page cache */
			wp_nginx_cache_purge_all($c['zone']);
			$r = array('error' => $e !== '' ? 'Could not change search engine indexing: '.$e : '', 'log' => $x['out'],
			           'success' => $on ? 'Search engines may index '.$site.' again.' : 'Search engines are asked not to index '.$site.'.');
			break;
		case 'maintenance':
			list($probe, ) = wordpress_tools_probe($c);
			$e = wordpress_mu_set($c, 'reqad-maintenance.php', 'wordpress-maintenance.php', $on, $probe);
			wp_nginx_cache_purge_all($c['zone']);    // cached pages would go around it
			$r = array('error' => $e !== '' ? 'Maintenance mode: '.$e : '', 'log' => '',
			           'success' => $on ? 'Maintenance mode is on: visitors see a "back soon" page, you still see the site when logged in.' : 'Maintenance mode is off.');
			break;
		default:
			return result_error('There is no such switch.');
	}
	if ($r['error'] !== '')
		return result_error($r['error'], array('log' => $r['log'] ?? ''));
	log_debug('[wp] '.auth_user().' '.$tool.' '.($on ? 'on' : 'off').' on '.$site);
	plugin_hook('wordpress.tool', array('account' => $c['user'], 'domain' => $c['domain'], 'path' => $c['path'], 'tool' => $tool, 'on' => $on));
	return result_ok($r['success'], array('log' => $r['log'] ?? ''));
}

/* The site's own page cache rules: pages that are never cached and cookies
   that turn the cache off (wp_cache_rules_clean() for the forms). Saved per
   domain (the cache is the site's, shared by its installs) and written into
   the vhost at once when the cache is on; kept while it is off. */
function wordpress_cache_rules_set(array $row, $pages, $cookies) {
	global $ini;
	$c = wordpress_tool_ctx($row);
	$want = array('pages' => array(), 'cookies' => array());
	foreach (array('pages' => $pages, 'cookies' => $cookies) as $k => $list) {
		if (is_string($list))
			$list = preg_split('/[\s,]+/', $list, -1, PREG_SPLIT_NO_EMPTY);
		foreach ((array)$list as $v) {
			$v = trim((string)$v);
			if ($v === '')
				continue;
			if (($e = wp_cache_rule_error($k, $v)) !== '')
				return result_error($v.': '.$e);
			$want[$k][] = $v;
		}
		if (count(array_unique(array_map('strtolower', $want[$k]))) > 50)
			return result_error($k === 'pages' ? 'At most 50 pages.' : 'At most 50 cookies.');
	}
	$old = wp_cache_rules_get($c['domain']);
	if (!wp_cache_rules_put($c['domain'], $want))
		return result_error('The rules could not be saved: the table for them is missing (apply db/1054.sql; the Reqad update does it).');
	$on = wp_is_nginx($ini) && strpos($c['conf'], '# BEGIN reqad-nginx-cache') !== false;
	if ($on) {
		list($err, ) = wp_nginx_cache_refresh($c['domain']);
		if ($err !== '') {
			wp_cache_rules_put($c['domain'], $old);
			return result_error($err);
		}
		/* pages cached before a rule was added would still be served */
		wp_nginx_cache_purge_all($c['zone']);
	}
	$now = wp_cache_rules_get($c['domain']);
	log_debug('[wp] '.auth_user().' cache rules of '.$c['domain'].': '.count($now['pages']).' pages, '.count($now['cookies']).' cookies');
	plugin_hook('wordpress.cache_rules', array('account' => $c['user'], 'domain' => $c['domain'], 'rules' => $now));
	return result_ok($on ? 'Saved; the page cache follows them from now on.' : 'Saved. They apply while the page cache is on.', $now);
}

/* "Disable WP-Cron": the constant in wp-config.php and the install's line in
   the account's crontab (cron_read/cron_write), always together. */
function wordpress_wpcron_set(array $c, $on) {
	global $ini;
	$p = ' --path='.escapeshellarg($c['docroot']);
	$log = '';
	if ($on && in_array(wp_site_php_version($ini, $c['domain']), array('', 'disabled'), true))
		return array('error' => 'PHP is turned off for this site.', 'log' => '', 'success' => '');
	$cron = function ($add) use ($c) {
		list($hash, $text) = cron_read($c['user']);
		if ($hash === null)
			return $text;
		$lines = preg_split('/\n/', rtrim((string)$text, "\n"));
		$keep = array();
		foreach ($lines as $l)
			if ($l !== '' || $keep)
				if (!preg_match('/'.preg_quote($c['cron_marker'], '/').'[ \t]*$/', $l))
					$keep[] = $l;
		if ($add)
			$keep[] = wordpress_cron_line($c);
		return cron_write($c['user'], $hash, implode("\n", $keep));
	};
	if ($on) {
		if (($e = $cron(true)) !== '')
			return array('error' => 'The cron job could not be added: '.$e, 'log' => '', 'success' => '');
		$x = wordpress_cli($c['row'], 'config set DISABLE_WP_CRON true --raw --type=constant'.$p, 60);
		$log .= $x['out']."\n";
		if (($e = wp_cli_error($x)) !== '') {
			$cron(false);
			return array('error' => 'Could not change wp-config.php: '.$e, 'log' => $log, 'success' => '');
		}
		return array('error' => '', 'log' => $log, 'success' => 'WP-Cron is off: the server runs the site\'s due tasks every 2 minutes instead.');
	}
	$h = wordpress_cli($c['row'], 'config has DISABLE_WP_CRON'.$p, 60);
	if ($h['rc'] === 0) {
		$x = wordpress_cli($c['row'], 'config delete DISABLE_WP_CRON'.$p, 60);
		$log .= $x['out']."\n";
		/* the cron line stays while the constant does: without both, nothing runs the tasks */
		if (($e = wp_cli_error($x)) !== '')
			return array('error' => 'Could not change wp-config.php: '.$e, 'log' => $log, 'success' => '');
	}
	if (($e = $cron(false)) !== '')
		return array('error' => 'WP-Cron is back on, but the server\'s cron job could not be removed: '.$e, 'log' => $log, 'success' => '');
	return array('error' => '', 'log' => $log, 'success' => 'WP-Cron is back on: WordPress runs its tasks on page visits.');
}

/*
 * The Security measures an install should have: $want = keys of
 * wp_security_options(); what differs from now is changed, the nginx rules
 * in one test and reload. $o: login_slug (Hide wp-admin), allow_ips (array
 * or text: who may log in, with admin_ip_allow), regen_keys (new keys even
 * when they are strong: signs everybody out). Security keys and permissions
 * are one-way: unticking them changes nothing.
 */
function wordpress_security_apply(array $row, array $want, array $o = array()) {
	global $ini;
	$c = wordpress_tool_ctx($row);
	$opts = wp_security_options();
	$want = array_values(array_intersect(array_keys($opts), $want));
	$on = function ($k) use ($want) { return in_array($k, $want, true); };
	$p = ' --path='.escapeshellarg($c['docroot']);
	$errors = array();
	$log = '';
	$wp = function ($args, $what) use ($c, $p, &$errors, &$log) {
		$r = wordpress_cli($c['row'], $args.$p, 120);
		$log .= '$ wp '.$args."\n".$r['out']."\n";
		$e = wp_cli_error($r);
		if ($e !== '')
			$errors[] = $what.': '.$e;
		return $e === '';
	};
	if ($on('admin_ip_allow') && $on('hide_login'))
		/* WPS Hide Login answers the login on index.php at the new address, which an nginx rule on /wp-login.php never sees */
		return result_error('Choose either "Move the login page" or "Allow login only from these addresses": with a moved login page the address rule would not apply.');
	list($probe, $perr) = wordpress_tools_probe($c);
	$st = wordpress_tools_status($row, $probe ? $probe + array('error' => '') : null);
	$cur = $st['data']['security'];

	/* 1. the web server rules (for the whole site) */
	if (wp_is_nginx($ini)) {
		$ips = array();
		$raw = isset($o['allow_ips']) ? (is_array($o['allow_ips']) ? $o['allow_ips'] : preg_split('/[\s,]+/', (string)$o['allow_ips'], -1, PREG_SPLIT_NO_EMPTY)) : wp_sec_allowed_ips($c['domain']);
		foreach ($raw as $ip) {
			if (trim((string)$ip) === '')
				continue;
			if (($v = wp_sec_valid_ip($ip)) === '')
				return result_error('Not an IP address or range: '.$ip);
			$ips[] = $v;
		}
		if (count($ips) > 50)
			return result_error('At most 50 addresses may log in.');
		$nk = array();
		foreach ($opts as $k => $x)
			if ($x['type'] === 'nginx' && $on($k))
				$nk[] = $k;
		$r = wp_sec_nginx_apply($c['user'], $c['domain'], $c['path'], $nk, array_values(array_unique($ips)));
		$log .= $r['log'];
		if ($r['error'] !== '')
			$errors[] = $r['error'];
	}

	/* 2. inside WordPress */
	if (!$probe)
		$errors[] = 'WordPress settings were not changed: '.$perr;
	else {
		foreach (array('file_edit' => array('DISALLOW_FILE_EDIT', 'true', 'File editing in the dashboard'),
		               'concatenate_scripts' => array('CONCATENATE_SCRIPTS', 'false', 'Script concatenation')) as $k => $d) {
			if ($on($k) && $cur[$k] !== 'on')
				$wp('config set '.$d[0].' '.$d[1].' --raw --type=constant', $d[2]);
			if (!$on($k) && $cur[$k] === 'on')
				$wp('config delete '.$d[0], $d[2]);
		}
		if ($on('pingbacks') !== ($cur['pingbacks'] === 'on')) {
			/* update_option() in eval, not `wp option update`: wp-cli sanitizes the stored
			   value first, turns '' into 'closed', calls it unchanged and writes nothing */
			$ping = $on('pingbacks') ? 'closed' : 'open';
			$flag = $on('pingbacks') ? '0' : '1';
			$wp('eval '.escapeshellarg('update_option("default_ping_status", "'.$ping.'"); update_option("default_pingback_flag", "'.$flag.'");'), 'Pingbacks');
		}
		if ($on('hide_login')) {
			$slug = wp_login_slug_valid($o['login_slug'] ?? '');
			if ($slug === '')
				$errors[] = 'Move the login page: the new address needs 4 to 40 of a-z, 0-9 and dashes, and cannot be a WordPress path.';
			elseif ($cur['hide_login'] !== 'on') {
				if ($wp('plugin install wps-hide-login --activate', 'Move the login page'))
					$wp('option update whl_page '.escapeshellarg($slug), 'Move the login page');
			} elseif ($slug !== (string)$probe['login_slug'])
				$wp('option update whl_page '.escapeshellarg($slug), 'Move the login page');
		} elseif ($cur['hide_login'] === 'on')
			$wp('plugin uninstall wps-hide-login --deactivate', 'Move the login page');
		foreach ($opts as $k => $x) {
			if ($x['type'] !== 'muplugin' || $on($k) === ($cur[$k] === 'on'))
				continue;
			$e = wordpress_mu_set($c, $x['file'], $x['src'], $on($k), $probe);
			$log .= ($on($k) ? 'Added' : 'Removed').' the must-use plugin '.$x['file']."\n";
			if ($e !== '')
				$errors[] = $x['label'].': '.$e;
		}
		if (($on('security_keys') && $cur['security_keys'] !== 'on') || !empty($o['regen_keys']))
			$wp('config shuffle-salts', 'Security keys');
	}

	/* 3. permissions (one-way) */
	if ($on('permissions') && $cur['permissions'] === 'off') {
		$r = wordpress_perms($c, true);
		$log .= "Permissions: wp-config.php 0600, write access for others taken off.\n";
		if (!$r || !in_array($r['config_mode'], array('0400', '0600'), true) || $r['world_writable'] !== '')
			$errors[] = 'Permissions: some files could not be changed'.($r && $r['world_writable'] !== '' ? ' (~/'.$r['world_writable'].' belongs to another user?)' : '').'.';
	}
	wp_nginx_cache_purge_all($c['zone']);
	$site = $c['domain'].($c['path'] !== '' ? '/'.$c['path'] : '');
	log_debug('[wp] '.auth_user().' security on '.$site.': '.implode(',', $want).($errors ? ' ('.count($errors).' errors)' : ''));
	plugin_hook('wordpress.security', array('account' => $c['user'], 'domain' => $c['domain'], 'path' => $c['path'], 'on' => $want));
	$after = wordpress_tools_status($row);
	$data = array('log' => trim($log), 'status' => $after['ok'] ? $after['data'] : null);
	if ($errors)
		return result_error(implode(' ', $errors), $data);
	return result_ok('The security settings of '.$site.' are saved.', $data);
}
