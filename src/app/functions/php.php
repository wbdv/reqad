<?php
/*
 * PHP for an account's sites: which version each site runs and a few of its
 * settings. Used by the API (and so MCP and the account panel on 2083).
 *
 * A site is the account's main domain or one of its addon domains; each has
 * its own php-fpm pool file (account_fpm_pool_target() / addon_pool_path()),
 * aliases and subdomains served by the same vhost share it. The version
 * moves the pool file between the versions' pool folders (account_edit() for
 * the main domain, addon_domain_php_set() for an addon domain).
 *
 * Settings are a short whitelist (php_setting_specs()), each with a fixed
 * list of values, written as php_admin_value / php_admin_flag lines inside a
 * managed block right under the pool's [name] line:
 *     [example.com]
 *     ; BEGIN reqad-php-settings
 *     php_admin_value[memory_limit] = 512M
 *     ; END reqad-php-settings
 * In a pool the FIRST line for a setting wins (tested: a later
 * memory_limit does not override the template's 2048M), so the block goes
 * first. Nothing else of the pool is ever changed from here (open_basedir,
 * disable_functions stay). The block moves with the pool
 * file when the version changes. php-fpm -t checks the result before the
 * service is reloaded (graceful: running requests finish); a failing test
 * puts the old file back.
 *
 * A site on mod_php (apache_modphp) has no pool: its settings are the same
 * kind of block in its Apache vhost, right after the vhost's own php_admin_*
 * lines in every <VirtualHost> that has them (in one Apache context the LAST
 * line for a setting wins, tested), checked with httpd -t and applied with a
 * graceful reload. php_admin_* there too, so a .htaccess php_value cannot
 * undo what the panel set. Moving the site to PHP-FPM (another version)
 * carries the settings over to the new pool (php_version_set()).
 */

/* key => array(label, help, values (value => label), flag: php_admin_flag). '' = the server's default. */
function php_setting_specs() {
	$mb = function (array $list) {
		$out = array();
		foreach ($list as $v)
			$out[$v] = $v >= 1024 && $v % 1024 === 0 ? ($v / 1024).' GB' : $v.' MB';
		return $out;
	};
	$memory = array();
	foreach ($mb(array(128, 256, 512, 1024, 2048)) as $v => $l)
		$memory[$v >= 1024 && $v % 1024 === 0 ? ($v / 1024).'G' : $v.'M'] = $l;
	$upload = array();
	foreach ($mb(array(8, 16, 32, 64, 128, 256, 512)) as $v => $l)
		$upload[$v.'M'] = $l;
	return array(
		'memory_limit'        => array('Memory per request', 'The most memory one page or script may use.', $memory, false),
		'upload_max_filesize' => array('Largest upload', 'The biggest file a form can upload (post_max_size is set to the same).', $upload, false),
		'max_execution_time'  => array('Time limit', 'How long a script may run, in seconds. Requests are always stopped after 300 s.',
		                               array('30' => '30 s', '60' => '60 s', '120' => '120 s', '300' => '300 s'), false),
		'max_input_vars'      => array('Form fields', 'The most fields a form may send (big menus and shop settings need more).',
		                               array('1000' => '1000', '3000' => '3000', '5000' => '5000', '10000' => '10000'), false),
		'display_errors'      => array('Show errors on pages', 'Only while looking for a problem: visitors see the errors too. They are always written to ~/logs.',
		                               array('off' => 'Off', 'on' => 'On'), true),
		'date.timezone'       => array('Time zone', 'The default time zone of date functions.', array(), false),
	);
}

/* PHP's own defaults, for a setting neither php.ini nor the pool sets */
function php_setting_builtin() {
	return array('memory_limit' => '128M', 'upload_max_filesize' => '2M', 'max_execution_time' => '30',
	             'max_input_vars' => '1000', 'display_errors' => 'off', 'date.timezone' => 'UTC');
}

/* An allowed value for $key? date.timezone takes any known zone. */
function php_setting_valid($key, $value) {
	$specs = php_setting_specs();
	if (!isset($specs[$key]))
		return false;
	if ($value === '')
		return true;
	if ($key === 'date.timezone')
		return in_array($value, timezone_identifiers_list(), true);
	return isset($specs[$key][2][$value]);
}

/* The account's sites: array of array(domain, kind main|addon, version
   ('off' = PHP turned off), handler fpm|mod_php|off|none, pool (path or '')). */
function php_sites($account) {
	global $db, $ini;
	$row = account_get($db, $account);
	if (!$row)
		return array();
	$out = array();
	list($v, $h) = account_php($row['domain']);
	$pool = '';
	if ($h === 'fpm') {
		list($p) = account_fpm_pool_target($row['domain'], $v);
		$pool = is_file($p) ? $p : '';
	}
	$out[] = array('domain' => $row['domain'], 'kind' => 'main', 'version' => $v, 'handler' => $h, 'pool' => $pool);
	foreach (addon_domain_list($ini) as $a)
		if ($a['user'] === $account)
			$out[] = array('domain' => $a['domain'], 'kind' => 'addon', 'version' => $a['version'], 'handler' => $a['handler'],
			               'pool' => (string)$a['pool']);
	return $out;
}

/* One of the account's sites, or null. */
function php_site($account, $domain) {
	foreach (php_sites($account) as $s)
		if ($s['domain'] === strtolower(trim((string)$domain)))
			return $s;
	return null;
}

/* The versions installed: array(version, default (new accounts get it), eol (Y-m-d or ''), ended). */
function php_versions() {
	global $ini;
	$eol = health_php_eol();
	$out = array();
	foreach (array_filter(array_map('trim', explode(',', $ini['php_versions'] ?? ''))) as $v)
		$out[] = array('version' => $v, 'default' => $v === $ini['php'], 'eol' => $eol[$v] ?? '',
		               'ended' => isset($eol[$v]) && strtotime($eol[$v]) < time());
	usort($out, function ($a, $b) { return version_compare($b['version'], $a['version']); });
	return $out;
}

/* The versions PHP Settings > Install PHP offers (remi Software Collections).
   5.6 is remi's own php56 on EL8 and the Reqad repo's rebuild of it on EL9;
   5.4 is a Reqad repo build, EL8 only for now. */
function php_installable_versions() {
	$osr = @parse_ini_file('/etc/os-release');
	$el  = (int)($osr['VERSION_ID'] ?? 0);
	return array_merge($el === 8 ? array('5.4') : array(), array('5.6', '7.2', '7.3', '7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5'));
}

/* Extensions the installer adds with a version, as remi package names without
   the phpNN- prefix. One name dnf cannot find fails the whole install. */
function php_install_modules($version) {
	$mods = array(
		'php-xml', 'php-pdo', 'php-mysqlnd', 'php-mysql', 'php-mbstring', 'php-pear',
		'php-gd', 'php-mcrypt', 'php-intl', 'php-process', 'php-soap',
		'php-pecl-redis', 'php-pecl-igbinary', 'php-common', 'php-json', 'php-cli',
		'php-bcmath', 'php-pecl-zip', 'php-opcache', 'php-sodium', 'php-pecl-imagick-im7',
	);
	// sodium is in PHP from 7.2 on (php-json is pecl-jsonc in remi's 5.6;
	// in 5.4 json is in php-common and opcache is pecl-zendopcache, both by Provides)
	if (version_compare($version, '7.2', '<'))
		$mods = array_values(array_diff($mods, array('php-sodium')));
	return $mods;
}

/* php.ini of a version (the server default's is /etc/php.ini) */
function php_ini_file($version) {
	global $ini;
	return $version === $ini['php'] ? '/etc/php.ini' : '/etc/opt/remi/php'.str_replace('.', '', $version).'/php.ini';
}

/* "key = value" lines of an ini-like text: array(key => value). In php.ini
   the later line wins, in a pool the first. */
function php_ini_values($text, $pool) {
	$out = array();
	foreach (explode("\n", (string)$text) as $l) {
		$l = trim($l);
		if ($l === '' || $l[0] === ';' || $l[0] === '[' || $l[0] === '#')
			continue;
		if ($pool) {
			if (!preg_match('/^php_(?:admin_)?(?:value|flag)\[([A-Za-z0-9_.]+)\]\s*=\s*(.*)$/', $l, $m))
				continue;
		} elseif (!preg_match('/^([A-Za-z0-9_.]+)\s*=\s*(.*)$/', $l, $m))
			continue;
		if (!$pool || !isset($out[$m[1]]))
			$out[$m[1]] = trim(trim($m[2]), '"');
	}
	return $out;
}

/* The managed block of a pool file: array(before, block lines (key => value), after) */
function php_pool_split($text) {
	$b = "; BEGIN reqad-php-settings\n";
	$e = "; END reqad-php-settings\n";
	$i = strpos($text, $b);
	$j = $i === false ? false : strpos($text, $e, $i);
	if ($i === false || $j === false)
		return array($text, array());
	return array(substr($text, 0, $i).substr($text, $j + strlen($e)), php_ini_values(substr($text, $i, $j - $i), true));
}

/* A site's settings: array(key => array(value (set here, '' = not set), default (what applies without it))). */
function php_settings_get($account, $domain) {
	$s = php_site($account, $domain);
	if (!$s)
		return result_error("$domain is not a site of account $account.");
	if ($s['handler'] === 'mod_php') {
		$text = (string)shell_exec(server_sudo().'cat '.escapeshellarg(php_site_vhost($s)).' 2>/dev/null');
		list($rest, $mine) = php_vhost_split($text);
		$base = array_merge(php_ini_values((string)@file_get_contents(php_ini_file($s['version'])), false), php_vhost_values($rest));
	} elseif ($s['pool'] === '') {
		return result_error("PHP is turned off for $domain.");
	} else {
		$text = (string)shell_exec(server_sudo().'cat '.escapeshellarg($s['pool']).' 2>/dev/null');
		list($rest, $mine) = php_pool_split($text);
		$base = array_merge(php_ini_values((string)@file_get_contents(php_ini_file($s['version'])), false), php_ini_values($rest, true));
	}
	$out = array();
	foreach (php_setting_specs() as $k => $spec) {
		$d = $base[$k] ?? (php_setting_builtin()[$k] ?? '');
		if ($spec[3])
			$d = in_array(strtolower($d), array('1', 'on', 'true', 'yes', 'stdout'), true) ? 'on' : 'off';
		$out[$k] = array('value' => (string)($mine[$k] ?? ''), 'default' => $d);
	}
	return result_ok('', array('domain' => $s['domain'], 'version' => $s['version'], 'handler' => $s['handler'], 'settings' => $out));
}

/*
 * Change some of a site's settings: $set = array(key => value, '' = back to
 * the default). Keys left out stay as they are.
 */
function php_settings_set($account, $domain, array $set) {
	global $ini;
	$s = php_site($account, $domain);
	if (!$s)
		return result_error("$domain is not a site of account $account.");
	if ($s['pool'] === '' && $s['handler'] !== 'mod_php')
		return result_error("PHP is turned off for $domain.");
	foreach ($set as $k => $v)
		if (!is_string($k) || !is_scalar($v) || !php_setting_valid($k, (string)$v))
			return result_error("Not a value this panel can set: $k = ".(is_scalar($v) ? (string)$v : '?').'.');
	if ($s['handler'] === 'mod_php')
		return php_vhost_settings_set($account, $s, $set);
	$sudo = server_sudo();
	$orig = (string)shell_exec($sudo.'cat '.escapeshellarg($s['pool']).' 2>/dev/null');
	if (trim($orig) === '')
		return result_error("Could not read the PHP-FPM pool of $domain.");
	list($rest, $mine) = php_pool_split($orig);
	foreach ($set as $k => $v) {
		if ((string)$v === '')
			unset($mine[$k]);
		else
			$mine[$k] = (string)$v;
	}
	$specs = php_setting_specs();
	$lines = '';
	foreach ($specs as $k => $spec) {
		if (!isset($mine[$k]))
			continue;
		$lines .= ($spec[3] ? 'php_admin_flag' : 'php_admin_value').'['.$k.'] = '.$mine[$k]."\n";
		if ($k === 'upload_max_filesize')
			$lines .= 'php_admin_value[post_max_size] = '.$mine[$k]."\n";
		if ($k === 'max_execution_time')
			$lines .= 'php_admin_value[max_input_time] = '.$mine[$k]."\n";
	}
	$new = $rest;
	if ($lines !== '') {
		$block = "; BEGIN reqad-php-settings\n; set in the account panel (PHP settings), first so it wins; changed only from there\n".$lines."; END reqad-php-settings\n";
		if (!preg_match('/^\[[^\]\n]+\][ \t]*\n/m', $rest, $m, PREG_OFFSET_CAPTURE))
			return result_error("The PHP-FPM pool of $domain has no [name] line.");
		$at = $m[0][1] + strlen($m[0][0]);
		$new = substr($rest, 0, $at).$block.substr($rest, $at);
	}
	if ($new === $orig)
		return result_ok("Nothing changed for $domain.", array('domain' => $domain, 'changed' => false));

	$service = $s['kind'] === 'main' ? account_fpm_pool_target($s['domain'], $s['version'])[1] : addon_fpm_service($ini, $s['version']);
	$bin = $s['version'] === $ini['php'] ? '/usr/sbin/php-fpm' : '/opt/remi/php'.str_replace('.', '', $s['version']).'/root/usr/sbin/php-fpm';
	if (($err = php_pool_write($s['pool'], $new)) !== '')
		return result_error($err);
	exec($sudo.escapeshellarg($bin).' -t 2>&1', $o, $rc);
	if ($rc !== 0) {
		php_pool_write($s['pool'], $orig);
		return result_error('PHP-FPM refused the new settings, so nothing changed: '.trim(implode(' ', array_slice($o, -2))));
	}
	exec($sudo.'systemctl reload '.escapeshellarg($service).' 2>&1', $o2, $rc2);
	route_log("php settings $domain: ".json_encode($set));
	plugin_hook('php.settings', array('account' => $account, 'domain' => $s['domain'], 'settings' => $set));
	return result_ok("PHP settings of $domain saved.".($rc2 !== 0 ? ' PHP-FPM could not be reloaded; they apply after its next restart.' : ''),
	                 array('domain' => $s['domain'], 'changed' => true));
}

/* Write a root-owned pool file through a temp file (mode 0644). '' or the error. */
function php_pool_write($path, $content) {
	$tmp = tempnam(sys_get_temp_dir(), 'reqad');
	if ($tmp === false || file_put_contents($tmp, $content) === false)
		return 'Could not write a temporary file.';
	$sudo = server_sudo();
	exec($sudo.'install -m 0644 -o root -g root '.escapeshellarg($tmp).' '.escapeshellarg($path).' 2>&1', $o, $rc);
	@unlink($tmp);
	return $rc === 0 ? '' : 'Could not write '.$path.'.';
}

/* Run $domain (main or addon domain of $account) on PHP $version: "8.3",
   "8.3:fpm" / "8.5:mod_php" (the handler; mod_php only on a mod_php template
   and only the server's default version), or "off" (no PHP: main domain only,
   as Accounts > Manage on 2087). Without a handler a mod_php site stays on
   mod_php for the default version and moves to PHP-FPM for any other; the
   settings set in PHP settings go with it when the handler changes. */
function php_version_set($account, $domain, $version) {
	global $db, $ini;
	$s = php_site($account, $domain);
	if (!$s)
		return result_error("$domain is not a site of account $account.");
	$version = trim((string)$version);
	$handler = '';
	if (strpos($version, ':') !== false)
		list($version, $handler) = explode(':', $version, 2);
	$modphp = php_mode() === 'modphp';

	if ($version === 'off') {
		if ($s['version'] === 'off')
			return result_ok("PHP is already off for $domain.", array('domain' => $s['domain'], 'version' => 'off', 'changed' => false));
		if ($s['kind'] !== 'main')
			return result_error("PHP can only be turned off for the account's main domain.");
		$r = account_edit($db, array('user' => $account, 'php' => 'off'));
		if (empty($r['ok']))
			return $r;
		plugin_hook('php.version', array('account' => $account, 'domain' => $s['domain'], 'from' => $s['version'], 'to' => 'off'));
		return result_ok("PHP is off for $domain: its .php files are not run any more (they answer 403).",
		                 array('domain' => $s['domain'], 'version' => 'off', 'changed' => true));
	}

	if (!in_array($version, array_column(php_versions(), 'version'), true))
		return result_error("PHP $version is not installed on this server.");
	if ($handler === '')
		$handler = $modphp && $version === $ini['php'] && in_array($s['handler'], array('mod_php', 'off'), true) ? 'mod_php' : 'fpm';
	if (!in_array($handler, $modphp ? array('fpm', 'mod_php') : array('fpm'), true))
		return result_error($handler === 'mod_php' ? "This server does not run PHP as an Apache module." : "Unknown PHP handler '$handler'.");
	if ($handler === 'mod_php' && $version !== $ini['php'])
		return result_error("mod_php only runs PHP ".$ini['php'].". Choose PHP-FPM for PHP $version.");
	$label = 'PHP '.$version.($modphp ? ($handler === 'mod_php' ? ' (mod_php)' : ' (PHP-FPM)') : '');
	if ($s['version'] === $version && $s['handler'] === $handler)
		return result_ok("$domain already runs $label.", array('domain' => $s['domain'], 'version' => $version, 'changed' => false));

	/* the settings set here move with the site when the handler changes
	   (pool <-> vhost block); a site coming back from "off" starts fresh */
	$carry = array();
	if ($s['version'] !== 'off' && $s['handler'] !== $handler) {
		$g = php_settings_get($account, $s['domain']);
		foreach ((array)($g['data']['settings'] ?? array()) as $k => $st)
			if ($st['value'] !== '')
				$carry[$k] = $st['value'];
	}
	$r = $s['kind'] === 'main'
		? account_edit($db, array('user' => $account, 'php' => $version.':'.$handler))
		: addon_domain_php_set($s['domain'], $version, $handler);
	if (empty($r['ok']))
		return $r;
	$note = '';
	if ($s['handler'] === 'mod_php' && $handler !== 'mod_php')
		php_vhost_block_remove(php_site_vhost($s));
	if ($carry && empty(php_settings_set($account, $s['domain'], $carry)['ok']))
		$note = ' Its PHP settings could not be carried over: set them again in PHP settings.';
	plugin_hook('php.version', array('account' => $account, 'domain' => $s['domain'], 'from' => $s['version'], 'to' => $version));
	return result_ok("$domain now runs $label. It takes a few seconds to apply.".$note,
	                 array('domain' => $s['domain'], 'version' => $version, 'handler' => $handler, 'changed' => true));
}

/* ---- mod_php: the settings block in the site's Apache vhost --------------- */

/* The vhost file of a site (main or addon domain). */
function php_site_vhost(array $s) {
	global $ini;
	if ($s['kind'] === 'main')
		return account_vhost_path($s['domain']);
	$a = addon_domain_get($ini, $s['domain']);
	return $a ? (string)$a['vhost'] : '';
}

/* A vhost without its settings blocks, and the settings of the first one:
   array(rest, key => value). */
function php_vhost_split($text) {
	$mine = array();
	$re = '/^[ \t]*# BEGIN reqad-php-settings[^\n]*\n.*?^[ \t]*# END reqad-php-settings[^\n]*\n/ms';
	if (preg_match($re, $text, $m))
		$mine = php_vhost_values($m[0]);
	return array(preg_replace($re, '', $text), $mine);
}

/* php_value / php_admin_value / *_flag lines of Apache config: key => value (the later line wins). */
function php_vhost_values($text) {
	$out = array();
	foreach (explode("\n", (string)$text) as $l)
		if (preg_match('/^\s*php_(?:admin_)?(?:value|flag)\s+([A-Za-z0-9_.]+)\s+(.*?)\s*$/', $l, $m))
			$out[$m[1]] = trim($m[2], '"');
	return $out;
}

/* $rest with $lines (already indented, '' = none) as a block after the last
   php_* line of every <VirtualHost> that has one; null when none has. */
function php_vhost_insert($rest, $lines) {
	if ($lines === '')
		return $rest;
	$parts = preg_split('/(<\/VirtualHost>)/i', $rest, -1, PREG_SPLIT_DELIM_CAPTURE);
	$done = 0;
	foreach ($parts as $i => $part) {
		if (stripos($part, '<VirtualHost') === false)
			continue;
		if (!preg_match_all('/^([ \t]*)php_(?:admin_)?(?:value|flag)\s[^\n]*\n/m', $part, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER))
			continue;
		$last = end($m);
		$at = $last[0][1] + strlen($last[0][0]);
		$ind = $last[1][0];
		$block = $ind."# BEGIN reqad-php-settings (account panel > PHP settings, last so it wins; changed only from there)\n"
		       .preg_replace('/^/m', $ind, rtrim($lines, "\n"))."\n".$ind."# END reqad-php-settings\n";
		$parts[$i] = substr($part, 0, $at).$block.substr($part, $at);
		$done++;
	}
	return $done ? implode('', $parts) : null;
}

/* Write a root-owned config file in place (cp keeps its mode and owner). '' or the error. */
function php_conf_write($path, $content) {
	$tmp = tempnam(sys_get_temp_dir(), 'reqad');
	if ($tmp === false || file_put_contents($tmp, $content) === false)
		return 'Could not write a temporary file.';
	exec(server_sudo().'cp '.escapeshellarg($tmp).' '.escapeshellarg($path).' 2>&1', $o, $rc);
	@unlink($tmp);
	return $rc === 0 ? '' : 'Could not write '.$path.'.';
}

/* Write a changed vhost, test the web server's config, reload gracefully; the
   old file goes back when the test fails. '' or the error. */
function php_vhost_apply($path, $orig, $new) {
	if (($err = php_conf_write($path, $new)) !== '')
		return $err;
	$w = web_server('httpd');
	exec(server_sudo().$w['test'].' 2>&1', $o, $rc);
	if ($rc !== 0) {
		php_conf_write($path, $orig);
		return 'Apache refused the new settings, so nothing changed: '.trim(implode(' ', array_slice($o, -2)));
	}
	exec(server_sudo().'systemctl reload '.escapeshellarg($w['service']).' 2>&1', $o2, $rc2);
	return $rc2 === 0 ? '' : 'reload';
}

/* php_settings_set() for a site on mod_php ($s from php_site(), $set checked). */
function php_vhost_settings_set($account, array $s, array $set) {
	$domain = $s['domain'];
	$path = php_site_vhost($s);
	$orig = $path !== '' ? (string)shell_exec(server_sudo().'cat '.escapeshellarg($path).' 2>/dev/null') : '';
	if (trim($orig) === '')
		return result_error("Could not read the Apache configuration of $domain.");
	list($rest, $mine) = php_vhost_split($orig);
	foreach ($set as $k => $v) {
		if ((string)$v === '')
			unset($mine[$k]);
		else
			$mine[$k] = (string)$v;
	}
	$lines = '';
	foreach (php_setting_specs() as $k => $spec) {
		if (!isset($mine[$k]))
			continue;
		$lines .= ($spec[3] ? 'php_admin_flag ' : 'php_admin_value ').$k.' '.$mine[$k]."\n";
		if ($k === 'upload_max_filesize')
			$lines .= 'php_admin_value post_max_size '.$mine[$k]."\n";
		if ($k === 'max_execution_time')
			$lines .= 'php_admin_value max_input_time '.$mine[$k]."\n";
	}
	$new = php_vhost_insert($rest, $lines);
	if ($new === null)
		return result_error("The Apache configuration of $domain has no PHP lines to put the settings after. Ask the server administrator.");
	if ($new === $orig)
		return result_ok("Nothing changed for $domain.", array('domain' => $domain, 'changed' => false));
	$err = php_vhost_apply($path, $orig, $new);
	if ($err !== '' && $err !== 'reload')
		return result_error($err);
	route_log("php settings $domain (mod_php): ".json_encode($set));
	plugin_hook('php.settings', array('account' => $account, 'domain' => $domain, 'settings' => $set));
	return result_ok("PHP settings of $domain saved.".($err === 'reload' ? ' Apache could not be reloaded; they apply after its next restart.' : ''),
	                 array('domain' => $domain, 'changed' => true));
}

/* Take the settings block out of a vhost (the site left mod_php). */
function php_vhost_block_remove($path) {
	if ($path === '')
		return;
	$orig = (string)shell_exec(server_sudo().'cat '.escapeshellarg($path).' 2>/dev/null');
	list($rest) = php_vhost_split($orig);
	if ($orig !== '' && $rest !== $orig)
		php_vhost_apply($path, $orig, $rest);
}
