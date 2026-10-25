<?php
/*
 * Node.js / Python apps: a domain served by a long-running process of the
 * account instead of PHP.
 *
 *   runtime    Node.js majors in /opt/reqad/node/<major>, Python 3.x from the OS
 *              (scripts/apps/app_runtime.sh install|list)
 *   process    systemd unit reqad-app-<id> (/etc/systemd/system/reqad-app-<id>.service),
 *              User=<account>, its home only (ProtectHome=tmpfs + BindPaths),
 *              memory / CPU / process limits, restarted when it crashes, started
 *              at boot; output in the journal (`reqad app logs`)
 *   port       127.0.0.1:<port> from 30000-39999, in PORT (and HOST); only the
 *              account itself and nginx may connect to it (/etc/reqad/app-ports,
 *              read by the loopback guard of setup_panel_loopback_guard.sh)
 *   web        the domain's nginx `location /` is replaced by a proxy to the port
 *              (websockets included), optionally serving existing files from the
 *              document root first; removing the app writes the PHP block back
 *              (regenerated, with the PHP handler the vhost had)
 *   code       the domain's Git deployment (~/apps/<domain>/current, restarted
 *              after every deploy and rollback), else ~/apps/<domain>/app, or a
 *              folder of the account's home
 *   env        ~/apps/<domain>/app.env (KEY=value lines, the account's file)
 *   start      /etc/reqad-apps/<id>.sh runs the start command (outside /etc/reqad,
 *              which the unit cannot see)
 *   packages   Install packages (app_pkg_*, at the end): .venv + pip / npm as
 *              the account in a background unit, then a restart
 *
 * nginx in front only (Apache in front: not yet).
 */

define('APP_PORT_MIN', 30000);
define('APP_PORT_MAX', 39999);
define('APP_RUNTIME', '/usr/local/reqad/scripts/apps/app_runtime.sh');

function apps_ready() {
	global $db;
	static $ok = null;
	if ($ok === null)
		$ok = $db instanceof SQLite3
		   && (bool)@$db->querySingle("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'apps'");
	return $ok;
}

/* One app by id or domain. */
function app_get($which) {
	global $db;
	if (!apps_ready())
		return null;
	$which = strtolower(trim((string)$which));
	$stmt = ctype_digit($which)
		? $db->prepare('SELECT * FROM apps WHERE id = :v')
		: $db->prepare('SELECT * FROM apps WHERE domain = :v');
	$stmt->bindValue(':v', ctype_digit($which) ? (int)$which : $which, ctype_digit($which) ? SQLITE3_INTEGER : SQLITE3_TEXT);
	$res = $stmt->execute();
	$r = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;
	return $r ?: null;
}

function app_list($account = '') {
	global $db;
	if (!apps_ready())
		return array();
	$stmt = $db->prepare('SELECT * FROM apps'.($account !== '' ? ' WHERE account = :a' : '').' ORDER BY domain');
	if ($account !== '')
		$stmt->bindValue(':a', $account, SQLITE3_TEXT);
	$res = $stmt->execute();
	$rows = array();
	while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC)))
		$rows[] = $r;
	return $rows;
}

function app_unit($a) {
	return 'reqad-app-'.(int)(is_array($a) ? $a['id'] : $a);
}

/* ---- runtimes ------------------------------------------------------------ */

/* Installed runtimes: list of array(kind, version, full, bin). */
function app_runtimes() {
	static $list = null;
	if ($list !== null)
		return $list;
	$list = array();
	foreach (explode("\n", trim((string)shell_exec(deploy_sudo().APP_RUNTIME.' list 2>/dev/null'))) as $l) {
		$f = preg_split('/\s+/', trim($l));
		if (count($f) === 4 && in_array($f[0], array('node', 'python'), true))
			$list[] = array('kind' => $f[0], 'version' => $f[1], 'full' => $f[2], 'bin' => $f[3]);
	}
	return $list;
}

function app_runtime_find($kind, $version) {
	foreach (app_runtimes() as $r)
		if ($r['kind'] === $kind && $r['version'] === (string)$version)
			return $r;
	return null;
}

/* Install / update / remove a runtime (scripts/apps/app_runtime.sh). */
function app_runtime_run($op, $kind, $version) {
	if (!in_array($op, array('install', 'update', 'remove'), true) || !in_array($kind, array('node', 'python'), true)
	    || !preg_match('/^[0-9]{1,2}(\.[0-9]{1,2})?$/', (string)$version))
		return result_error('Unknown runtime or version.');
	if ($op === 'remove')
		foreach (app_list() as $a)
			if ($a['runtime'] === $kind && $a['version'] === (string)$version)
				return result_error("The app of {$a['domain']} uses $kind $version.");
	exec(deploy_sudo().APP_RUNTIME.' '.escapeshellarg($op).' '.escapeshellarg($kind).' '.escapeshellarg($version).' 2>&1', $out, $rc);
	$msg = trim(implode(' ', $out));
	if ($rc !== 0)
		return result_error($msg !== '' ? preg_replace('/^error:\s*/', '', $msg) : "Could not $op $kind $version.");
	$restarted = array();
	if ($op === 'update')
		foreach (app_list() as $a)
			if ($a['runtime'] === $kind && $a['version'] === (string)$version && (int)$a['enabled']) {
				shell_exec(deploy_sudo().'systemctl try-restart '.app_unit($a).' 2>&1');
				$restarted[] = $a['domain'];
			}
	return result_ok($msg.($restarted ? ' Restarted: '.implode(', ', $restarted).'.' : ''), array('restarted' => $restarted));
}

/* ---- paths, ports --------------------------------------------------------- */

/* The folder the app runs in. */
function app_dir(array $a) {
	$home = '/home/'.$a['account'];
	if ($a['app_dir'] !== '')
		return $home.'/'.trim($a['app_dir'], '/');
	if (function_exists('deploy_get') && deploy_get($a['domain']))
		return $home.'/apps/'.$a['domain'].'/current';
	return $home.'/apps/'.$a['domain'].'/app';
}

function app_env_file(array $a) {
	return '/home/'.$a['account'].'/apps/'.$a['domain'].'/app.env';
}

/* A free port: not taken by another app, nothing listening on it. */
function app_port_free() {
	global $db;
	$taken = array();
	$res = $db->query('SELECT port FROM apps');
	while ($res && ($r = $res->fetchArray(SQLITE3_NUM)))
		$taken[(int)$r[0]] = true;
	$listening = array();
	foreach (explode("\n", (string)shell_exec('ss -Htln 2>/dev/null')) as $l)
		if (preg_match('/:(\d+)\s/', $l, $m))
			$listening[(int)$m[1]] = true;
	for ($p = APP_PORT_MIN; $p <= APP_PORT_MAX; $p++)
		if (!isset($taken[$p]) && !isset($listening[$p]))
			return $p;
	return 0;
}

/* /etc/reqad/app-ports ("<port> <uid>") and the loopback guard: each app's
   port is reachable only by its own account (and system users like nginx). */
function app_ports_apply() {
	$lines = array();
	foreach (app_list() as $a)
		if (($pw = posix_getpwnam($a['account'])))
			$lines[] = (int)$a['port'].' '.(int)$pw['uid'];
	$tmp = tempnam(sys_get_temp_dir(), 'rqdp');
	file_put_contents($tmp, ($lines ? implode("\n", $lines)."\n" : ''));
	shell_exec(deploy_sudo().'install -D -m 0644 -o root -g root '.escapeshellarg($tmp).' /etc/reqad/app-ports');
	@unlink($tmp);
	shell_exec(deploy_sudo().'bash -c '.escapeshellarg('[ -x /usr/local/csf/bin/reqad-post.sh ] && /usr/local/csf/bin/reqad-post.sh').' >/dev/null 2>&1');
}

/* ---- the systemd unit ------------------------------------------------------ */

function app_unit_text(array $a) {
	$rt = app_runtime_find($a['runtime'], $a['version']);
	$bin = $rt ? $rt['bin'] : '/usr/bin';
	$dir = app_dir($a);
	$home = '/home/'.$a['account'];
	$path = ($a['runtime'] === 'python' ? $dir.'/.venv/bin:' : '').$bin.':/usr/local/bin:/usr/bin:/bin';
	$cgv2 = trim((string)@shell_exec('stat -fc %T /sys/fs/cgroup 2>/dev/null')) === 'cgroup2fs';
	$mem = max(64, (int)$a['memory_mb']);
	$env = array('PORT='.(int)$a['port'], 'HOST=127.0.0.1', 'PATH='.$path, 'HOME='.$home,
	             'REQAD_DOMAIN='.$a['domain'], 'REQAD_APP_DIR='.$dir);
	$env[] = $a['runtime'] === 'node' ? 'NODE_ENV=production' : 'PYTHONUNBUFFERED=1';
	if ($a['runtime'] === 'python')
		$env[] = 'VIRTUAL_ENV='.$dir.'/.venv';
	return "# Managed by Reqad (Accounts > Deployments) - regenerated on every change, edits are lost.\n"
		."[Unit]\n"
		.'Description=Reqad app '.$a['domain'].' ('.$a['runtime'].' '.$a['version'].")\n"
		."After=network.target\n"
		."StartLimitIntervalSec=120\nStartLimitBurst=5\n\n"
		."[Service]\n"
		."Type=simple\n"
		.'User='.$a['account']."\nGroup=".$a['account']."\n"
		.'WorkingDirectory='.$dir."\n"
		.'Environment='.implode(' ', array_map(function ($e) { return '"'.str_replace(array('\\', '"', '%'), array('\\\\', '\\"', '%%'), $e).'"'; }, $env))."\n"
		.'EnvironmentFile=-'.app_env_file($a)."\n"
		.'ExecStart=/bin/bash /etc/reqad-apps/'.(int)$a['id'].".sh\n"
		."Restart=on-failure\nRestartSec=3\nTimeoutStopSec=20\n"
		.($cgv2 ? 'MemoryMax='.$mem."M\n" : 'MemoryLimit='.$mem."M\n")
		."TasksMax=256\nCPUQuota=100%\n"
		."NoNewPrivileges=yes\nPrivateTmp=yes\nProtectSystem=full\n"
		."ProtectHome=tmpfs\nBindPaths=".$home."\n"
		."InaccessiblePaths=-/usr/local/reqad -/etc/reqad -/root -/var/lib/reqad\n"
		."ProtectKernelTunables=yes\nProtectKernelModules=yes\nProtectControlGroups=yes\nLockPersonality=yes\n"
		.'SyslogIdentifier=reqad-app-'.$a['domain']."\n\n"
		."[Install]\nWantedBy=multi-user.target\n";
}

/* The start script the unit runs (root-owned; the command is the app's). */
function app_start_script(array $a) {
	return "#!/bin/bash\n# Managed by Reqad (Accounts > Deployments): the start command of ".$a['domain']."\n"
		.($a['start_cmd'] === '' ? "echo 'No start command yet: the first deploy finds it.' >&2\nexit 1\n" : "exec ".$a['start_cmd']."\n");
}

function app_install_unit(array $a) {
	$tmp1 = tempnam(sys_get_temp_dir(), 'rqdu');
	$tmp2 = tempnam(sys_get_temp_dir(), 'rqds');
	file_put_contents($tmp1, app_unit_text($a));
	file_put_contents($tmp2, app_start_script($a));
	$s = deploy_sudo();
	exec($s.'install -D -m 0644 -o root -g root '.escapeshellarg($tmp2).' /etc/reqad-apps/'.(int)$a['id'].'.sh && '
		.$s.'install -m 0644 -o root -g root '.escapeshellarg($tmp1).' /etc/systemd/system/'.app_unit($a).'.service && '
		.$s.'chmod 0755 /etc/reqad-apps && '.$s.'systemctl daemon-reload 2>&1', $out, $rc);
	@unlink($tmp1);
	@unlink($tmp2);
	return $rc === 0 ? '' : 'Could not write the service: '.trim(implode(' ', $out));
}

/* ---- nginx ----------------------------------------------------------------- */

function app_proxy_directives(array $a) {
	return 'proxy_pass http://127.0.0.1:'.(int)$a['port'].';
proxy_http_version 1.1;
proxy_set_header Host $host;
proxy_set_header X-Real-IP $remote_addr;
proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
proxy_set_header X-Forwarded-Proto $scheme;
proxy_set_header Upgrade $http_upgrade;
proxy_set_header Connection $http_connection;
proxy_read_timeout 300s;
proxy_send_timeout 300s;';
}

/* The app's replacement for `location /`, at column 0. */
function app_nginx_block(array $a, $php) {
	$px = conf_indent(app_proxy_directives($a), '    ');
	$head = '# BEGIN reqad-app '.(int)$a['id'].' php='.($php !== '' ? $php : 'none')
		.' - Accounts > Deployments: removing the app puts the PHP block back'."\n";
	if ((int)$a['serve_static'])
		return $head.'location / {
    # files that exist in the document root, the rest goes to the app
    location ~ /\.(?!well-known/) { deny all; }
    try_files $uri @reqad_app;
    expires 1h;
}
location @reqad_app {
'.$px.'
}
# END reqad-app';
	return $head.'location / {
'.$px.'
}
# END reqad-app';
}

/* What goes back in place of an app's `location /`. With Apache behind nginx
   ($php 'backend') it is the proxy to Apache, whatever $php says: PHP on or
   off is decided in the Apache vhost there. */
function app_nginx_php_root($php) {
	if ($php === 'backend' || web_backend() !== 'none')
		return nginx_backend_root();
	return nginx_fpm_root($php);
}

/* The nginx_php-fpm template's `location /` (scripts/templates/nginx_php-fpm.php),
   with $php = the fastcgi upstream, 'off' = the PHP-off block, '' = no PHP. */
function nginx_fpm_root($php) {
	if ($php === 'off')
		$handler = nginx_php_off_location('location ~ \.php$');
	elseif ($php !== '')
		$handler = nginx_php_location(preg_replace('/^php-fpm-/', '', $php));
	else
		$handler = '';
	return 'location / {

    # wordpress rewrite
    location /wp-json {
        rewrite ^/wp-json(.*)$ /?rest_route=$1;
    }

    try_files $uri $uri/ /index.php$is_args$args;

    # Deny all attempts to access hidden files such as .htaccess, .htpasswd, .DS_Store
    location ~ /\. {
        deny all;
    }

    # Deny access to any files with a .php extension in the uploads directory
    # Must stay ABOVE the PHP handler (nested regex locations match in file order).
    location ~* /(?:uploads|files)/.*\.php$ {
        deny all;
    }
'.($handler !== '' ? "\n".conf_indent($handler, '    ')."\n" : '').'
    location ~* \.(js|css|png|jpg|jpeg|gif|ico|woff2)$ {
        expires max;
        log_not_found off;
    }
    location = /favicon.ico {
        log_not_found off;
        access_log off;
    }

    location = /robots.txt {
        allow all;
        log_not_found off;
        access_log off;
    }
}';
}

/* Byte ranges of each server's own `location /` block, back to front:
   array(start of line, end past `}`, indent). */
function app_root_locations($conf) {
	$blocks = nginx_conf_blocks($conf);
	if ($blocks === null)
		return null;
	$out = array();
	foreach ($blocks as $b) {
		if ($b[3] !== 'location /')
			continue;
		foreach ($blocks as $o)          // nested in another location: not the server's own
			if ($o !== $b && preg_match('/^location\b/', $o[3]) && $o[1] < $b[0] && $o[2] > $b[2])
				continue 2;
		$ls = strrpos(substr($conf, 0, $b[0]), "\n");
		$ls = $ls === false ? 0 : $ls + 1;
		$lead = substr($conf, $ls, $b[0] - $ls);
		$out[] = trim($lead) === '' ? array($ls, $b[2] + 1, $lead) : array($b[0], $b[2] + 1, '');
	}
	usort($out, function ($x, $y) { return $y[0] - $x[0]; });
	return $out;
}

/* Replace `location /` with the app proxy. Returns array(error, php) - php is
   the PHP handler that was there, kept to write it back later. */
function app_vhost_on(array $a) {
	$file = web_vhost_path($a['domain'], 'nginx');
	$conf = account_vhost_read($a['domain'], 'nginx');
	if ($conf === '')
		return array("No nginx vhost for {$a['domain']}.", '');
	if (preg_match('/# BEGIN reqad-app \d+ php=(\S+)/', $conf, $m)) {
		$php = $m[1] === 'none' ? '' : $m[1];
		$conf = app_vhost_strip($conf, $php);       // rewrite it (port, static setting)
		if ($conf === null)
			return array('The app block in the vhost is damaged; fix '.$file.' by hand.', '');
	} else {
		$php = strpos($conf, '# BEGIN reqad-php-disabled') !== false ? 'off' : '';
		if ($php === '' && preg_match('/^[ \t]*fastcgi_pass\s+(php-fpm-[A-Za-z0-9._-]+)\s*;/m', $conf, $m))
			$php = $m[1];
		if (web_backend() !== 'none')
			$php = 'backend';
	}
	$orig = account_vhost_read($a['domain'], 'nginx');
	$ranges = app_root_locations($conf);
	if (!$ranges)
		return array("The vhost of {$a['domain']} has no `location /` to hand to the app.", '');
	foreach ($ranges as $r)
		$conf = substr($conf, 0, $r[0]).conf_indent(app_nginx_block($a, $php), $r[2]).substr($conf, $r[1]);
	return array(account_vhost_apply($file, $conf, $orig, 'nginx'), $php);
}

/* The vhost with the app block(s) swapped back for the PHP `location /`. */
function app_vhost_strip($conf, $php) {
	$n = 0;
	$conf = preg_replace_callback('/^([ \t]*)# BEGIN reqad-app \d+[^\n]*\n.*?^[ \t]*# END reqad-app[^\n]*\n?/ms',
		function ($m) use ($php, &$n) { $n++; return conf_indent(app_nginx_php_root($php), $m[1])."\n"; }, $conf);
	return $n ? $conf : null;
}

function app_vhost_off(array $a) {
	$conf = account_vhost_read($a['domain'], 'nginx');
	if (!preg_match('/# BEGIN reqad-app \d+ php=(\S+)/', $conf, $m))
		return '';
	$new = app_vhost_strip($conf, $m[1] === 'none' ? '' : $m[1]);
	if ($new === null)
		return 'Could not find the app block in the vhost of '.$a['domain'].'.';
	return account_vhost_apply(web_vhost_path($a['domain'], 'nginx'), $new, $conf, 'nginx');
}

/* The most memory an account may give one of its apps itself: its session
   memory limit (Phase 2.5, hosting packages) when it has one, else 2 GB. */
function app_memory_max($account) {
	$l = function_exists('account_session_limits') ? account_session_limits($account, 0) : null;
	$m = $l && !empty($l['memory_mb']) ? (int)$l['memory_mb'] : 2048;
	return max(128, min(16384, $m));
}

/* ---- create, change, delete ------------------------------------------------ */

/* $domain: an app that runs a Git deployment with no release yet may have
   no start command: the first deploy finds it (app_detect()). */
function app_clean_fields(array $o, array $old = null, $domain = '') {
	$get = function ($k, $def) use ($o, $old) {
		if (array_key_exists($k, $o)) return $o[$k];
		return $old !== null && array_key_exists($k, $old) ? $old[$k] : $def;
	};
	$f = array();
	$f['runtime'] = (string)$get('runtime', '');
	$f['version'] = trim((string)$get('version', ''));
	if (!in_array($f['runtime'], array('node', 'python'), true))
		return array(null, 'The runtime is node or python.');
	if (!app_runtime_find($f['runtime'], $f['version']))
		return array(null, ucfirst($f['runtime'])." {$f['version']} is not installed on this server (Deployments > Runtimes).");
	$f['start_cmd'] = trim(str_replace(array("\r", "\n"), ' ', (string)$get('start_cmd', '')));
	$f['app_dir'] = trim(trim((string)$get('app_dir', '')), '/');
	if ($f['app_dir'] !== '' && !deploy_valid_relpath($f['app_dir']))
		return array(null, 'The app folder is a path in the account\'s home, like apps/site/app (no .. or leading /).');
	$pending = $f['app_dir'] === '' && $domain !== '' && ($d = deploy_get($domain)) && (string)$d['current_release'] === '';
	if (($f['start_cmd'] === '' && !$pending) || strlen($f['start_cmd']) > 1000)
		return array(null, 'Give the start command, e.g. node server.js or .venv/bin/gunicorn -b 127.0.0.1:$PORT app:app');
	$f['serve_static'] = (int)(bool)$get('serve_static', 0);
	$f['memory_mb'] = (int)$get('memory_mb', 512);
	if ($f['memory_mb'] < 64 || $f['memory_mb'] > 16384)
		return array(null, 'Memory is 64 to 16384 MB.');
	return array($f, '');
}

/*
 * New app for $o['domain']: runtime (node|python), version, start_cmd,
 * app_dir, serve_static, memory_mb. Writes the unit, switches the vhost to the
 * proxy and starts it (unless $o['start'] === false).
 */
function app_create(array $o, $by = '') {
	global $db;
	if (!apps_ready())
		return result_error('The apps table is missing. Run the Reqad update (database 1044) first.');
	if (web_front() !== 'nginx')
		return result_error('Apps need nginx in front (this server runs Apache).');
	$domain = strtolower(trim((string)($o['domain'] ?? '')));
	list($account) = deploy_domain($domain);
	if ($account === '')
		return result_error("$domain is not the main or an addon domain of a hosting account.");
	if (app_get($domain))
		return result_error("$domain already runs an app.");
	if (($e = limit_allows($account, 'app', array('domain' => $domain))) !== '')
		return result_error($e);
	list($f, $err) = app_clean_fields($o, null, $domain);
	if ($err !== '')
		return result_error($err);
	$port = app_port_free();
	if (!$port)
		return result_error('No free port left for apps ('.APP_PORT_MIN.'-'.APP_PORT_MAX.').');

	$stmt = $db->prepare('INSERT INTO apps (account, domain, runtime, version, app_dir, start_cmd, port, serve_static, memory_mb, created, created_by)
	                      VALUES (:a, :d, :rt, :v, :dir, :cmd, :port, :st, :mem, :now, :by)');
	foreach (array(':a' => $account, ':d' => $domain, ':rt' => $f['runtime'], ':v' => $f['version'], ':dir' => $f['app_dir'],
	               ':cmd' => $f['start_cmd'], ':by' => (string)$by) as $k => $v)
		$stmt->bindValue($k, $v, SQLITE3_TEXT);
	foreach (array(':port' => $port, ':st' => $f['serve_static'], ':mem' => $f['memory_mb'], ':now' => time()) as $k => $v)
		$stmt->bindValue($k, $v, SQLITE3_INTEGER);
	if (!$stmt->execute())
		return result_error('Could not save the app: '.$db->lastErrorMsg());
	$a = app_get($db->lastInsertRowID());

	/* the default folder and the env file, as the account */
	$dir = app_dir($a);
	shell_exec(deploy_sudo().'runuser -u '.escapeshellarg($account).' -- bash -c '.escapeshellarg(
		'umask 077; mkdir -p "$(dirname "$2")" && touch "$2" && chmod 0600 "$2"; '
		.'case "$1" in */current) ;; *) umask 022; mkdir -p "$1" ;; esac').' x '.escapeshellarg($dir).' '.escapeshellarg(app_env_file($a)).' 2>&1');

	$warnings = array();
	if (($e = app_install_unit($a)) !== '') {
		app_forget($a);
		return result_error($e);
	}
	list($e, $php) = app_vhost_on($a);
	if ($e !== '') {
		shell_exec(deploy_sudo().'rm -f /etc/systemd/system/'.app_unit($a).'.service /etc/reqad-apps/'.(int)$a['id'].'.sh; '.deploy_sudo().'systemctl daemon-reload');
		app_forget($a);
		return result_error($e);
	}
	$db->exec("UPDATE apps SET php_upstream = '".SQLite3::escapeString($php)."' WHERE id = ".(int)$a['id']);
	app_ports_apply();
	/* enabled = starts at boot; not started now when the code is not there yet */
	$started = (!isset($o['start']) || $o['start'] !== false) && $f['start_cmd'] !== '';
	shell_exec(deploy_sudo().'systemctl enable '.($started ? '--now ' : '').app_unit($a).' 2>&1');
	error_log(date('Y-m-d H:i:s')." app: created #{$a['id']} $domain ($account) {$f['runtime']} {$f['version']} port $port by $by\n", 3, _PATH.'/log/debug_log');
	plugin_hook('app.created', array('id' => (int)$a['id'], 'account' => $account, 'domain' => $domain, 'port' => $port));
	return result_ok("App for $domain created on port $port".($started ? ' and started' : '').'. Its files go in '.$dir.'.'
		.($f['start_cmd'] === '' ? ' The first deploy finds its start command.' : ''),
		app_public(app_get($a['id'])), $warnings);
}

function app_edit($which, array $o) {
	global $db;
	$a = app_get($which);
	if (!$a)
		return result_error("No app '$which'.");
	list($f, $err) = app_clean_fields($o, $a, $a['domain']);
	if ($err !== '')
		return result_error($err);
	$stmt = $db->prepare('UPDATE apps SET runtime = :rt, version = :v, app_dir = :dir, start_cmd = :cmd, serve_static = :st, memory_mb = :mem WHERE id = :id');
	foreach (array(':rt' => $f['runtime'], ':v' => $f['version'], ':dir' => $f['app_dir'], ':cmd' => $f['start_cmd']) as $k => $v)
		$stmt->bindValue($k, $v, SQLITE3_TEXT);
	foreach (array(':st' => $f['serve_static'], ':mem' => $f['memory_mb'], ':id' => (int)$a['id']) as $k => $v)
		$stmt->bindValue($k, $v, SQLITE3_INTEGER);
	$stmt->execute();
	$a = app_get($a['id']);
	if (($e = app_install_unit($a)) !== '')
		return result_error($e);
	list($e) = app_vhost_on($a);
	if ($e !== '')
		return result_error($e);
	$restart = (int)$a['enabled'] && $a['start_cmd'] !== '';
	if ($restart)
		shell_exec(deploy_sudo().'systemctl restart '.app_unit($a).' 2>&1');
	return result_ok("App for {$a['domain']} saved".($restart ? ' and restarted' : '').'.', app_public($a));
}

/* Stop and remove the app; the domain gets its PHP `location /` back. The
   files (and app.env) stay. */
function app_delete($which) {
	$a = app_get($which);
	if (!$a)
		return result_error("No app '$which'.");
	shell_exec(deploy_sudo().'systemctl disable --now '.app_unit($a).' 2>&1');
	$warnings = array();
	if (($e = app_vhost_off($a)) !== '')
		$warnings[] = $e;
	shell_exec(deploy_sudo().'rm -f /etc/systemd/system/'.app_unit($a).'.service /etc/reqad-apps/'.(int)$a['id'].'.sh; '.deploy_sudo().'systemctl daemon-reload');
	app_forget($a);
	app_ports_apply();
	plugin_hook('app.deleted', array('id' => (int)$a['id'], 'account' => $a['account'], 'domain' => $a['domain']));
	return result_ok("App for {$a['domain']} removed; the domain serves its document root with PHP again. The files stay in ".app_dir($a).'.',
		array('id' => (int)$a['id']), $warnings);
}

function app_forget(array $a) {
	global $db;
	$db->exec('DELETE FROM apps WHERE id = '.(int)$a['id']);
}

/* start | stop | restart. Stop also keeps it from starting at boot. */
function app_control($which, $op) {
	global $db;
	$a = app_get($which);
	if (!$a)
		return result_error("No app '$which'.");
	if (!in_array($op, array('start', 'stop', 'restart'), true))
		return result_error("Unknown action '$op'.");
	if ($op !== 'stop' && ($why = plugin_filter('account.active', true, $a['account'])) !== true)
		return result_error(is_string($why) && $why !== '' ? $why : "Account {$a['account']} is not active.");
	if ($op !== 'stop' && $a['start_cmd'] === '')
		return result_error("The app of {$a['domain']} has no start command yet: deploy it (the deploy finds it), or give it one in its settings.");
	$cmd = $op === 'stop' ? 'disable --now' : ($op === 'start' ? 'enable --now' : 'restart');
	if ($op !== 'stop')
		shell_exec(deploy_sudo().'systemctl reset-failed '.app_unit($a).' 2>/dev/null');
	exec(deploy_sudo().'systemctl '.$cmd.' '.app_unit($a).' 2>&1', $out, $rc);
	$db->exec('UPDATE apps SET enabled = '.($op === 'stop' ? 0 : 1).' WHERE id = '.(int)$a['id']);
	if ($rc !== 0)
		return result_error("Could not $op the app of {$a['domain']}: ".trim(implode(' ', $out)));
	if ($op !== 'stop') {
		usleep(1500000);
		$st = app_status($a);
		if ($st['state'] !== 'active')
			return result_error("The app of {$a['domain']} did not stay up ({$st['state']}). See its log.", $st);
		for ($i = 0; $i < 10 && !$st['listening']; $i++) {
			usleep(500000);
			$st = app_status($a);
		}
		if (!$st['listening'])
			return result_ok("App of {$a['domain']}: started, but nothing answers on 127.0.0.1:{$a['port']} yet - the start command must listen on \$PORT.", $st);
	}
	return result_ok("App of {$a['domain']}: ".($op === 'stop' ? 'stopped' : ($op === 'start' ? 'started' : 'restarted')).'.', app_status($a));
}

/* Restart after a deploy or rollback switched the code (deploy.php). */
function app_after_deploy($domain) {
	$a = app_get($domain);
	if (!$a || !(int)$a['enabled'])
		return '';
	/* a crash loop of the previous code may have hit the start limit */
	exec('systemctl reset-failed '.app_unit($a).' 2>/dev/null; systemctl enable '.app_unit($a).' 2>/dev/null; systemctl restart '.app_unit($a).' 2>&1', $out, $rc);
	/* up to 30 s for it to listen (an app may build caches at start) */
	for ($i = 0; $i < 60; $i++) {
		usleep(500000);
		$st = app_status($a);
		if ($st['state'] === 'failed' || ($st['state'] === 'inactive' && $i > 4))
			return 'the app did not start after the switch ('.$st['state'].')';
		if ($st['state'] === 'active' && app_port_answers((int)$a['port'], 1))
			return '';
	}
	return 'the app did not listen on port '.$a['port'].' within 30 seconds after the switch';
}

/* State of the unit: state (active|inactive|failed|activating...), since,
   pid, memory_mb, restarts, and whether the port answers. */
function app_status(array $a) {
	$out = (string)shell_exec(deploy_sudo().'systemctl show '.app_unit($a)
		.' -p ActiveState -p SubState -p MainPID -p MemoryCurrent -p NRestarts -p ActiveEnterTimestamp -p UnitFileState 2>/dev/null');
	$p = array();
	foreach (explode("\n", $out) as $l)
		if (strpos($l, '=') !== false) { list($k, $v) = explode('=', $l, 2); $p[$k] = $v; }
	$mem = isset($p['MemoryCurrent']) && ctype_digit($p['MemoryCurrent']) ? round($p['MemoryCurrent'] / 1048576) : null;
	$state = $p['ActiveState'] ?? 'unknown';
	$listening = false;
	if ($state === 'active')
		$listening = app_port_answers((int)$a['port']);
	return array('state' => $state, 'sub' => $p['SubState'] ?? '', 'pid' => (int)($p['MainPID'] ?? 0),
		'memory_mb' => $mem, 'restarts' => (int)($p['NRestarts'] ?? 0), 'listening' => $listening,
		'since' => !empty($p['ActiveEnterTimestamp']) ? (int)strtotime($p['ActiveEnterTimestamp']) : 0,
		'boot' => ($p['UnitFileState'] ?? '') === 'enabled');
}

/* Something accepts connections on 127.0.0.1:$port ($tries attempts, 1 s
   each, half a second apart - a single miss is not trusted). */
function app_port_answers($port, $tries = 3) {
	for ($i = 0; $i < $tries; $i++) {
		$fp = @fsockopen('127.0.0.1', (int)$port, $en, $es, 1);
		if ($fp) { fclose($fp); return true; }
		if ($i + 1 < $tries) usleep(500000);
	}
	return false;
}

/* The last $n lines the app wrote (journal). */
function app_logs(array $a, $n = 200) {
	return (string)shell_exec(deploy_sudo().'journalctl -u '.app_unit($a).' -n '.max(10, min(5000, (int)$n)).' --no-pager -o short-iso 2>&1');
}

function app_public(array $a) {
	return array('id' => (int)$a['id'], 'account' => $a['account'], 'domain' => $a['domain'], 'runtime' => $a['runtime'],
		'version' => $a['version'], 'app_dir' => $a['app_dir'], 'dir' => app_dir($a), 'start_cmd' => $a['start_cmd'],
		'port' => (int)$a['port'], 'serve_static' => (bool)$a['serve_static'], 'memory_mb' => (int)$a['memory_mb'],
		'enabled' => (bool)$a['enabled'], 'env_file' => app_env_file($a), 'created' => (int)$a['created'],
		'install' => app_pkg_state($a));
}

/* The env file's text (the panel edits it). */
function app_env_read(array $a) {
	/* as the account: never read inside a home as root */
	return (string)shell_exec(deploy_sudo().'runuser -u '.escapeshellarg($a['account']).' -- head -c 65536 '.escapeshellarg(app_env_file($a)).' 2>/dev/null');
}

/* Replace the env file (KEY=value lines; comments allowed) as the account and restart. */
function app_env_write(array $a, $text) {
	$text = str_replace("\r", '', (string)$text);
	if (strlen($text) > 32768)
		return result_error('The environment is limited to 32 KB.');
	foreach (explode("\n", $text) as $i => $l) {
		$l = trim($l);
		if ($l === '' || $l[0] === '#') continue;
		if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', $l))
			return result_error('Line '.($i + 1).' is not KEY=value.');
	}
	$tmp = tempnam(sys_get_temp_dir(), 'rqde');
	file_put_contents($tmp, rtrim($text)."\n");
	chmod($tmp, 0644);
	exec(deploy_sudo().'runuser -u '.escapeshellarg($a['account']).' -- bash -c '.escapeshellarg('umask 077; cat > "$1"').' x '
		.escapeshellarg(app_env_file($a)).' < '.escapeshellarg($tmp).' 2>&1', $out, $rc);
	@unlink($tmp);
	if ($rc !== 0)
		return result_error('Could not write '.app_env_file($a).': '.trim(implode(' ', $out)));
	if ((int)$a['enabled'])
		shell_exec(deploy_sudo().'systemctl restart '.app_unit($a).' 2>&1');
	return result_ok('Environment of '.$a['domain'].' saved'.((int)$a['enabled'] ? ' and the app restarted' : '').'.');
}

/* ---- Install packages ------------------------------------------------------ */
/*
 * The account's packages in the app's folder, without a terminal: Python
 * gets its own .venv (made again when the app's Python version changed) and
 * requirements.txt, else pyproject.toml's [project] dependencies, else
 * `pip install .`; plus the server the start command names (gunicorn,
 * uvicorn[standard]...) when the requirements leave it out. Node.js: npm ci
 * (a lock file) or npm install, then `npm run build` when package.json has
 * one. Runs in the background (unit reqad-appinst-<id>, `reqad app __install`)
 * as the account, like a deploy's build; then the app restarts. One state
 * file and one log per app (the last install), in DEPLOY_LOG_DIR.
 */

define('APP_PKG_TIMEOUT', 900);

function app_pkg_unit(array $a) {
	return 'reqad-appinst-'.(int)$a['id'];
}

function app_pkg_log(array $a) {
	return DEPLOY_LOG_DIR.'/app-'.(int)$a['id'].'-install.log';
}

function app_pkg_state_file(array $a) {
	return DEPLOY_LOG_DIR.'/app-'.(int)$a['id'].'-install.json';
}

function app_pkg_active(array $a) {
	return trim((string)shell_exec(deploy_sudo().'systemctl is-active '.app_pkg_unit($a).' 2>/dev/null')) === 'active';
}

/* The last install: status (running|ok|warn|failed), started, finished, by,
   message - or null when there was none. */
function app_pkg_state(array $a) {
	$s = @json_decode((string)@file_get_contents(app_pkg_state_file($a)), true);
	if (!is_array($s) || !isset($s['status']))
		return null;
	/* running, but its unit is gone: killed (time limit, reboot) */
	if ($s['status'] === 'running' && time() - (int)($s['started'] ?? 0) > 10 && !app_pkg_active($a))
		$s = array('status' => 'failed', 'message' => 'interrupted (time limit or restart)') + $s;
	return $s;
}

/* root-owned, readable by the panel (group reqad), like a deploy's log */
function app_pkg_state_write(array $a, array $s) {
	$tmp = tempnam(sys_get_temp_dir(), 'rqdi');
	file_put_contents($tmp, json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
	shell_exec(deploy_sudo().'install -D -m 0640 -o root -g reqad '.escapeshellarg($tmp).' '.escapeshellarg(app_pkg_state_file($a)).' 2>&1');
	@unlink($tmp);
}

/* The last install's output from byte $offset: array(text, next offset). */
function app_pkg_output(array $a, $offset = 0) {
	$f = app_pkg_log($a);
	clearstatcache(true, $f);
	$size = @filesize($f);
	if ($size === false)
		return array('', 0);
	$offset = max(0, min((int)$offset, $size));
	$text = (string)@file_get_contents($f, false, null, $offset, min(1048576, $size - $offset));
	return array($text, $offset + strlen($text));
}

/* What an install does, for $kind node|python; "$1" = the app's version,
   "$2" = its start command. Also used for a Git deploy's release. */
function app_pkg_script($kind) {
	$common = <<<'SH'
step() { echo "+ $*"; "$@"; }

SH;
	if ($kind === 'node')
		return $common.<<<'SH'
if [ ! -f package.json ]; then echo "There is no package.json in this folder: no packages to install."; exit 0; fi
if [ -f package-lock.json ] || [ -f npm-shrinkwrap.json ]; then step npm ci --no-audit --no-fund
else step npm install --no-audit --no-fund; fi
if node -e 'process.exit((require("./package.json").scripts || {}).build ? 0 : 1)' 2>/dev/null; then step npm run build; fi
echo "Packages installed."
SH;
	return $common.<<<'SH'
V="$1"; CMD=" $2 "
export PIP_DISABLE_PIP_VERSION_CHECK=1 PIP_NO_INPUT=1 PIP_PROGRESS_BAR=off
if [ -e .venv ] && [ ! -x .venv/bin/python ]; then echo ".venv is not a working virtual environment: making it again."; rm -rf .venv; fi
if [ -x .venv/bin/python ]; then
  have=$(.venv/bin/python -c 'import sys; print("%d.%d" % sys.version_info[:2])' 2>/dev/null) || have=unknown
  if [ "$have" != "$V" ]; then echo ".venv was made with Python $have and the app runs $V: making it again."; rm -rf .venv; fi
fi
[ -x .venv/bin/python ] || step python -m venv .venv
if [ -f requirements.txt ]; then
  step .venv/bin/python -m pip install -r requirements.txt
elif [ -f pyproject.toml ]; then
  rc=0
  .venv/bin/python - > .venv/reqad-requirements.txt <<'PY' || rc=$?
import sys
try:
    import tomllib
except ImportError:
    sys.exit(3)
with open("pyproject.toml", "rb") as f:
    deps = tomllib.load(f).get("project", {}).get("dependencies")
if deps is None:
    sys.exit(4)
print("\n".join(deps))
PY
  case $rc in
    0) echo "Dependencies from pyproject.toml:"; sed 's/^/  /' .venv/reqad-requirements.txt
       [ -s .venv/reqad-requirements.txt ] && step .venv/bin/python -m pip install -r .venv/reqad-requirements.txt ;;
    3|4) step .venv/bin/python -m pip install . ;;
    *) echo "Could not read pyproject.toml."; exit 1 ;;
  esac
elif [ -f setup.py ]; then
  step .venv/bin/python -m pip install .
else
  echo "There is no requirements.txt, pyproject.toml or setup.py in this folder: no packages to install."
fi
for s in gunicorn uvicorn hypercorn waitress daphne; do
  case "$CMD" in *"$s"*) ;; *) continue ;; esac
  b=$s; [ "$s" = waitress ] && b=waitress-serve
  [ -x ".venv/bin/$b" ] && continue
  p=$s; [ "$s" = uvicorn ] && p='uvicorn[standard]'
  echo "The start command uses $s, which the requirements do not install: adding it."
  step .venv/bin/python -m pip install "$p"
done
echo "Packages installed."
SH;
}

/* Start an install of $which's packages in the background. */
function app_pkg_start($which, $by = '') {
	$a = app_get($which);
	if (!$a)
		return result_error("No app '$which'.");
	if (($why = plugin_filter('account.active', true, $a['account'])) !== true)
		return result_error(is_string($why) && $why !== '' ? $why : "Account {$a['account']} is not active.");
	if (app_pkg_active($a))
		return result_error("The packages of {$a['domain']} are being installed already.");
	if (function_exists('deploy_get') && ($d = deploy_get($a['domain'])) && deploy_unit_active((int)$d['id']))
		return result_error("A deploy of {$a['domain']} is running; install the packages after it.");
	if (!app_runtime_find($a['runtime'], $a['version']))
		return result_error(ucfirst($a['runtime'])." {$a['version']} is not installed on this server any more.");
	$dir = app_dir($a);
	/* as the account: never look inside a home as root */
	exec(deploy_sudo().'runuser -u '.escapeshellarg($a['account']).' -- test -d '.escapeshellarg($dir).' 2>/dev/null', $o, $rc);
	if ($rc !== 0)
		return result_error(substr($dir, -8) === '/current'
			? "The code of {$a['domain']} is not there yet: deploy it from Git first."
			: 'The app\'s folder '.preg_replace('#^/home/[^/]+/#', '~/', $dir).' does not exist yet: upload the code first.');

	app_pkg_state_write($a, array('status' => 'running', 'started' => time(), 'finished' => 0, 'by' => (string)$by, 'message' => ''));
	shell_exec(deploy_sudo().'install -D -m 0640 -o root -g reqad /dev/null '.escapeshellarg(app_pkg_log($a)).' 2>&1');
	$cmd = deploy_sudo().'systemd-run --quiet --collect --unit='.app_pkg_unit($a)
		.' --description='.escapeshellarg('Reqad: packages of '.$a['domain'])
		.' -p MemoryLimit=2G -p TasksMax=512 -p CPUQuota=100% -p RuntimeMaxSec='.(APP_PKG_TIMEOUT + 120).' -p Nice=10'
		.' -- '._PATH.'/bin/reqad app __install '.(int)$a['id'].' 2>&1';
	exec($cmd, $out, $rc);
	if ($rc !== 0 && !app_pkg_active($a)) {
		$msg = 'could not start: '.substr(trim(implode(' ', $out)), 0, 200);
		app_pkg_state_write($a, array('status' => 'failed', 'started' => time(), 'finished' => time(), 'by' => (string)$by, 'message' => $msg));
		return result_error('Could not start installing the packages: '.trim(implode(' ', $out)));
	}
	return result_ok("Installing the packages of {$a['domain']}.", array('id' => (int)$a['id']));
}

/* The installer itself (root, inside unit reqad-appinst-<id>). */
function app_pkg_execute($id) {
	$a = app_get((string)(int)$id);
	if (!$a)
		return;
	$s = app_pkg_state($a) ?: array('started' => time(), 'by' => '');
	$s['status'] = 'running';
	$ctx = array('user' => $a['account'], 'log' => app_pkg_log($a));
	$finish = function ($status, $msg) use ($a, $s, $ctx) {
		deploy_say($ctx, ($status === 'failed' ? 'Failed: ' : 'Done: ').$msg);
		app_pkg_state_write($a, array('status' => $status, 'finished' => time(), 'message' => $msg) + $s);
		plugin_hook('app.packages', array('id' => (int)$a['id'], 'account' => $a['account'], 'domain' => $a['domain'],
		                                  'status' => $status, 'message' => $msg));
	};
	$rt = app_runtime_find($a['runtime'], $a['version']);
	if (!$rt)
		return $finish('failed', ucfirst($a['runtime'])." {$a['version']} is not installed on this server any more");
	$ctx['runtime_bin'] = $rt['bin'];
	$ctx['extra_env'] = array('REQAD_DOMAIN' => $a['domain'], 'PIP_CACHE_DIR' => '/home/'.$a['account'].'/.cache/pip');
	$dir = app_dir($a);
	deploy_say($ctx, 'Installing the packages of '.$a['domain'].' ('.($a['runtime'] === 'node' ? 'Node.js' : 'Python').' '.$a['version']
		.', as '.$a['account'].') in '.preg_replace('#^/home/[^/]+/#', '~/', $dir));
	$rc = deploy_as_user($ctx, app_pkg_script($a['runtime']), array($a['version'], $a['start_cmd']), $dir, APP_PKG_TIMEOUT);
	if ($rc === 124 || $rc === 137)
		return $finish('failed', 'it took longer than '.(APP_PKG_TIMEOUT / 60).' minutes');
	if ($rc !== 0)
		return $finish('failed', 'a step failed (exit code '.$rc.'): see the lines above');
	if (!(int)$a['enabled'])
		return $finish('ok', 'packages installed; the app is stopped, start it when you want');
	deploy_say($ctx, 'Restarting the app...');
	if (($e = app_after_deploy($a['domain'])) !== '')
		return $finish('warn', 'packages installed, but '.$e.' - see the app\'s output');
	$finish('ok', 'packages installed and the app restarted');
}

/* ---- what the code is: its start command (Detect) -------------------------------- */

/* Procfile's "web:" line made to fit here: the .venv's server, on 127.0.0.1
   and $PORT. array(kind, command); kind '' = not a command we know. */
function app_detect_procfile($line) {
	$line = trim(str_replace('0.0.0.0', '127.0.0.1', $line));
	$w = strtok($line, " \t");
	if (preg_match('/^(gunicorn|uvicorn|hypercorn|waitress-serve|daphne)$/', $w)) {
		$cmd = '.venv/bin/'.$line;
		if ($w === 'gunicorn' && !preg_match('/\s(-b|--bind)[\s=]/', $cmd))
			$cmd = preg_replace('#^\.venv/bin/gunicorn#', '.venv/bin/gunicorn -b 127.0.0.1:$PORT', $cmd);
		if ($w === 'uvicorn' && strpos($cmd, '--port') === false)
			$cmd .= ' --host 127.0.0.1 --port $PORT';
		return array('python', $cmd);
	}
	if (preg_match('/^python3?$/', $w))
		return array('python', '.venv/bin/python'.substr($line, strlen($w)));
	if (in_array($w, array('node', 'npm', 'npx', 'yarn', 'pnpm'), true))
		return array('node', $line);
	return array('', $line);
}

/* An installed runtime of $kind for what the code asks ($hint: exact or min,
   from): array(array(kind, version) or null, note). */
function app_detect_runtime($kind, array $hint) {
	$have = array();
	foreach (app_runtimes() as $r)
		if ($r['kind'] === $kind)
			$have[] = $r['version'];
	usort($have, 'version_compare');
	$name = $kind === 'node' ? 'Node.js' : 'Python';
	if (!$have)
		return array(null, "No $name is installed for apps on this server yet: the server's admin installs it first.");
	$newest = end($have);
	if (isset($hint['exact'])) {
		if (in_array($hint['exact'], $have, true))
			return array(array('kind' => $kind, 'version' => $hint['exact']), '');
		return array(array('kind' => $kind, 'version' => $newest),
			"The code asks for $name {$hint['exact']} ({$hint['from']}), which is not installed here: $newest is chosen.");
	}
	if (isset($hint['min']) && version_compare($newest, $hint['min'], '<'))
		return array(array('kind' => $kind, 'version' => $newest),
			"The code needs $name {$hint['min']} or newer ({$hint['from']}), and the newest installed here is $newest.");
	return array(array('kind' => $kind, 'version' => $newest), '');
}

/*
 * How the code in $rel (a folder of $account's home) starts, looked at AS
 * the account (fm-helper verb appfacts: files are read, nothing runs).
 * $prefer: node|python, the app's own runtime, whose commands then come
 * first. Returns: found, exists, empty, kind (node|python|''), start_cmd,
 * label, why, candidates [{kind, label, cmd, why}] (best first), runtime
 * ({kind, version} installed here that fits, or null), notes [sentences],
 * message.
 */
function app_detect($account, $rel, $prefer = '') {
	$rel = trim((string)$rel, '/');
	$out = array('found' => false, 'exists' => false, 'empty' => false, 'kind' => '', 'start_cmd' => '', 'label' => '', 'why' => '',
	             'candidates' => array(), 'runtime' => null, 'notes' => array(), 'message' => '');
	$f = files_run($account, array('verb' => 'appfacts', 'path' => $rel), null, null, 30);
	if (empty($f['ok'])) {
		$out['message'] = 'Could not look at the code: '.strtr((string)($f['error'] ?? 'no answer'), files_args($f['args'] ?? array()));
		return $out;
	}
	$out['exists'] = !empty($f['exists']);
	$out['empty'] = !empty($f['empty']);
	if (!$out['exists'] || $out['empty']) {
		$out['message'] = '~/'.$rel.($out['exists'] ? ' is empty' : ' does not exist yet').': upload the code there first.';
		return $out;
	}
	$files = (array)($f['files'] ?? array());
	$deps = (array)($f['py_deps'] ?? array());
	$c = array();
	$score = array('python' => 0, 'node' => 0);
	$add = function ($kind, $label, $cmd, $why) use (&$c) {
		foreach ($c as $x)
			if ($x['cmd'] === $cmd)
				return;
		$c[] = array('kind' => $kind, 'label' => $label, 'cmd' => $cmd, 'why' => $why);
	};
	$gunicorn = function ($target, $chdir) {
		return '.venv/bin/gunicorn -w 2 -b 127.0.0.1:$PORT '.($chdir !== '' ? '--chdir '.$chdir.' ' : '').$target;
	};
	$uvicorn = function ($target, $chdir, $factory) {
		return '.venv/bin/uvicorn '.$target.' --host 127.0.0.1 --port $PORT'.($factory ? ' --factory' : '').($chdir !== '' ? ' --app-dir '.$chdir : '');
	};

	/* Python */
	$dj = isset($f['django']) && is_array($f['django']) ? $f['django'] : null;
	if ($dj && $dj['wsgi'] !== '') {
		$where = ($dj['dir'] !== '' ? $dj['dir'].'/' : '').str_replace('.', '/', $dj['wsgi']).'.py';
		$add('python', 'Django', $gunicorn($dj['wsgi'], $dj['dir']), "Django project, $where");
		if ($dj['asgi'] !== '' && array_intersect($deps, array('channels', 'daphne', 'uvicorn'))) {
			if (in_array('daphne', $deps, true) && $dj['dir'] === '')
				$add('python', 'Django (ASGI)', '.venv/bin/daphne -b 127.0.0.1 -p $PORT '.$dj['asgi'].':application', 'Django with daphne');
			else
				$add('python', 'Django (ASGI)', $uvicorn($dj['asgi'].':application', $dj['dir'], false), 'Django with an ASGI server');
		}
		$score['python'] += 3;
	}
	$names = array('flask' => 'Flask', 'fastapi' => 'FastAPI', 'starlette' => 'Starlette', 'litestar' => 'Litestar', 'quart' => 'Quart',
	               'bottle' => 'Bottle', 'wsgi' => 'WSGI app', 'asgi' => 'ASGI app');
	foreach ((array)($f['py_apps'] ?? array()) as $p) {
		if (!isset($names[$p['fw']]) || !preg_match('/^[A-Za-z_][\w.]*$/', $p['module'].$p['var']) || ($dj && $dj['wsgi'] !== '' && in_array($p['fw'], array('wsgi', 'asgi'), true)))
			continue;
		$asgi = in_array($p['fw'], array('fastapi', 'starlette', 'litestar', 'quart', 'asgi'), true);
		$chdir = preg_match('#^[\w.-]+$#', $p['chdir']) ? $p['chdir'] : '';
		$why = $p['factory'] ? "the {$p['var']}() factory in {$p['file']}" : $names[$p['fw']]." app \"{$p['var']}\" in {$p['file']}";
		if ($asgi)
			$add('python', $names[$p['fw']], $uvicorn($p['module'].':'.$p['var'], $chdir, $p['factory']), $why);
		else
			$add('python', $names[$p['fw']], $gunicorn($p['factory'] ? '"'.$p['module'].':'.$p['var'].'()"' : $p['module'].':'.$p['var'], $chdir), $why);
		$score['python'] = max($score['python'], 3);
	}
	if (array_intersect($files, array('requirements.txt', 'pyproject.toml', 'Pipfile', 'setup.py')))
		$score['python'] += 1;
	/* Node.js */
	$n = isset($f['node']) && is_array($f['node']) ? $f['node'] : null;
	if ($n) {
		$nd = (array)$n['deps'];
		$has = function ($p) use ($nd) { return in_array($p, $nd, true); };
		$fw = true;
		if ($has('next'))
			$add('node', 'Next.js', 'node_modules/.bin/next start -H 127.0.0.1 -p $PORT', 'next in package.json');
		elseif ($has('nuxt'))
			$add('node', 'Nuxt', 'node .output/server/index.mjs', 'nuxt in package.json (its build makes .output)');
		elseif ($has('@sveltejs/kit') && $has('@sveltejs/adapter-node'))
			$add('node', 'SvelteKit', 'node build', '@sveltejs/adapter-node in package.json (its build makes build/)');
		elseif ($has('astro') && $has('@astrojs/node'))
			$add('node', 'Astro', 'node ./dist/server/entry.mjs', '@astrojs/node in package.json');
		elseif ($has('@nestjs/core'))
			$add('node', 'NestJS', in_array('start:prod', (array)$n['scripts'], true) ? 'npm run start:prod' : 'node dist/main.js', '@nestjs/core in package.json');
		else
			$fw = false;
		if ($n['start'] !== '')
			$add('node', 'npm start', 'npm start', 'the start script of package.json: '.$n['start']);
		if ($n['entry'] !== '' && preg_match('#^[\w./-]+$#', $n['entry']) && ($n['entry_exists'] || in_array('build', (array)$n['scripts'], true)))
			$add('node', 'node '.$n['entry'], 'node '.$n['entry'], $n['entry_exists'] ? $n['entry'].' in the folder' : $n['entry'].', made by the build script');
		$score['node'] = ($fw ? 3 : 0) + ($n['start'] !== '' || $n['entry_exists'] ? 2 : 0) + 1;
		if (!$fw && $n['start'] === '' && !$n['entry_exists']) {
			if ($has('@sveltejs/kit'))
				$out['notes'][] = 'SvelteKit runs as an app only with @sveltejs/adapter-node; without it, it builds a static site.';
			elseif (array_intersect($nd, array('astro', 'vite', 'react-scripts', '@angular/core')))
				$out['notes'][] = 'This looks like a front end that builds to static files: a Git deployment with the Static site (npm build) preset serves it without an app.';
		}
		if (!empty($n['fixed_port']))
			$out['notes'][] = $n['entry'].' listens on port '.(int)$n['fixed_port'].': make it use process.env.PORT, the port the server gives the app.';
	}

	/* the author's own line, after what the code itself shows */
	if ((string)($f['procfile'] ?? '') !== '') {
		list($pk, $pc) = app_detect_procfile($f['procfile']);
		if ($pk !== '') {
			$add($pk, 'Procfile', $pc, 'the web line of the Procfile');
			$score[$pk] += 2;
		}
	}

	/* the best kind: the preferred one when it has a command */
	$kinds = array_unique(array_column($c, 'kind'));
	$best = $score['python'] >= $score['node'] && in_array('python', $kinds, true) ? 'python' : (in_array('node', $kinds, true) ? 'node' : (in_array('python', $kinds, true) ? 'python' : ''));
	$kind = $prefer !== '' && in_array($prefer, $kinds, true) ? $prefer : $best;
	usort($c, function ($a, $b) use ($kind) { return ($a['kind'] !== $kind) - ($b['kind'] !== $kind); });
	$out['candidates'] = $c;
	$out['kind'] = $kind;
	if ($kind !== '') {
		$out['found'] = true;
		$out['start_cmd'] = $c[0]['cmd'];
		$out['label'] = $c[0]['label'];
		$out['why'] = $c[0]['why'];
		list($out['runtime'], $note) = app_detect_runtime($kind, (array)($kind === 'python' ? ($f['py_version'] ?? array()) : ($n['version'] ?? array())));
		if ($note !== '')
			$out['notes'][] = $note;
		$out['message'] = 'Found: '.$out['label'].' ('.$out['why'].').';
		if ($prefer !== '' && $kind !== $prefer)
			$out['message'] = 'The code looks like a '.($kind === 'node' ? 'Node.js' : 'Python').' app ('.$out['why'].'), not '.($prefer === 'node' ? 'Node.js' : 'Python').'.';
	} else {
		if (in_array('index.html', $files, true) && !$n)
			$out['notes'][] = 'This folder holds a static site (index.html): it needs no app.';
		elseif (array_intersect($files, array('index.php', 'composer.json')))
			$out['notes'][] = 'This looks like a PHP site: it needs no app.';
		$out['message'] = 'No start command found in ~/'.$rel.($out['notes'] ? '.'
			: ': no Flask, Django or FastAPI app, no package.json with a start script or a server file. Write the command yourself.');
	}
	if ($kind === 'python') {
		if (!array_intersect($files, array('requirements.txt', 'pyproject.toml', 'Pipfile', 'setup.py')))
			$out['notes'][] = 'There is no requirements.txt or pyproject.toml: only the server (gunicorn, uvicorn) gets installed. List the packages the code imports in requirements.txt.';
		elseif (in_array('Pipfile', $files, true) && !array_intersect($files, array('requirements.txt', 'pyproject.toml', 'setup.py')))
			$out['notes'][] = 'Only a Pipfile lists the packages, and Install packages reads requirements.txt or pyproject.toml: export it with pipenv requirements > requirements.txt.';
		if ($out['label'] === 'Django' || $out['label'] === 'Django (ASGI)')
			$out['notes'][] = 'Django: put the site\'s domain in ALLOWED_HOSTS.'.(in_array('whitenoise', $deps, true) ? '' : ' For its CSS and images, add whitenoise to the requirements (or collectstatic and Serve existing files first).');
	}
	return $out;
}

/* app_detect() for a site: the folder its app runs (or would run) in.
   $app_dir null = the app's own setting (none yet: automatic). */
function app_detect_site($domain, $app_dir = null, $prefer = '') {
	$domain = strtolower(trim((string)$domain));
	list($account) = deploy_domain($domain);
	if ($account === '')
		return result_error("$domain is not the main or an addon domain of a hosting account.");
	$a = app_get($domain);
	$app_dir = trim(trim((string)($app_dir === null ? ($a ? $a['app_dir'] : '') : $app_dir)), '/');
	if ($app_dir !== '' && !deploy_valid_relpath($app_dir))
		return result_error('The app folder is a path in the account\'s home, like apps/site/app (no .. or leading /).');
	if ($prefer === '' && $a)
		$prefer = $a['runtime'];
	if ($app_dir === '' && ($d = deploy_get($domain))) {
		if ((string)$d['current_release'] === '')
			return result_ok('The code comes with the first deploy. Leave the start command empty and the deploy finds it.',
				array('found' => false, 'pending' => true, 'dir' => '~/apps/'.$domain.'/current', 'candidates' => array(), 'notes' => array()));
		$rel = 'apps/'.$domain.'/current';
	} else
		$rel = $app_dir !== '' ? $app_dir : 'apps/'.$domain.'/app';
	$r = app_detect($account, $rel, $prefer);
	return result_ok($r['message'], $r + array('pending' => false, 'dir' => '~/'.$rel));
}

/* A start command found by a deploy: saved, and the unit's script rewritten. */
function app_start_cmd_set($which, $cmd) {
	global $db;
	$a = app_get($which);
	if (!$a)
		return "No app '$which'.";
	$stmt = $db->prepare('UPDATE apps SET start_cmd = :cmd WHERE id = :id');
	$stmt->bindValue(':cmd', (string)$cmd, SQLITE3_TEXT);
	$stmt->bindValue(':id', (int)$a['id'], SQLITE3_INTEGER);
	$stmt->execute();
	return app_install_unit(app_get($a['id']));
}
