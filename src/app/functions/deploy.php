<?php
/*
 * Git deployments: a domain's site comes from a Git branch instead of
 * uploaded files.
 *
 * Layout (all owned by the account):
 *   ~/apps/<domain>/repo.git          bare mirror of the repository (fetch cache)
 *   ~/apps/<domain>/releases/<time>/  one export of the branch per deploy
 *   ~/apps/<domain>/shared/           what survives deploys (.env, uploads/)
 *                                     - linked into every release
 *   ~/apps/<domain>/current           -> releases/<time>   (switched atomically)
 *   document root (public_html or the addon's root) -> ~/apps/<domain>/current[/<public_dir>]
 *
 * The document root keeps its path, so backups, the WP Toolkit, the file
 * manager and the permission scripts see the site where they always did.
 * nginx passes $realpath_root to PHP (deploy_vhost_realpath()), so a switch
 * never mixes files of two releases; on Apache the web server and the
 * account's PHP-FPM are reloaded after a switch instead.
 *
 * A deploy runs as a transient systemd unit reqad-deploy-<id> (root:
 * `reqad deploy __run <id>`); everything that touches the code - git, the
 * build commands, linking - runs as the account (runuser), with CPU, memory,
 * process and time limits. Requests only queue a run (deploy_runs) and start
 * the unit; a run queued while one is going is picked up when it ends, and
 * only the newest queued one runs (deploy_execute()).
 *
 * Triggers: the panel, `reqad deploy run`, POST /api/v1/deployments/<id>/deploy,
 * and push webhooks (GitHub, GitLab, Gitea/Forgejo, Bitbucket) at
 * https://<hostname>/.reqad-deploy/<id> (scripts/update/setup_deploy_webhook.sh
 * proxies that to the API's /api/v1/hooks/deploy/<id>).
 */

define('DEPLOY_LOG_DIR', '/var/log/reqad/deploy');

/* Git deployments and apps are one feature, off by default: deployments=1 in
   etc/server-software.ini. Off: no page, CLI, API or webhooks (apps already
   running keep running). */
function deploy_enabled() {
	global $ini;
	return feature_enabled($ini, 'deployments');
}

/* Can $account set up its own deployments and apps (the account panel, the
   API with an account token)? '' = yes, else why not. Build and start
   commands run as the account outside any jail, so a jailed or SFTP-only
   account would get out of its limits: only a full shell. The server's admin
   can still set them up for any account. */
function deploy_account_error($account) {
	if (!deploy_enabled())
		return 'Git deployments and apps are turned off on this server.';
	if (!account_shell_full_allowed())
		return 'Deployments and apps run commands (builds, start commands), so they need full shell access, which this server does not offer.';
	if (account_shell_get($account) !== 'full')
		return 'Deployments and apps run commands (builds, start commands), so they need full shell access. Ask your hosting provider for it.';
	return '';
}

/* ---- reading -------------------------------------------------------------- */

function deploy_ready() {
	global $db;
	static $ok = null;
	if ($ok === null)
		$ok = $db instanceof SQLite3
		   && (bool)@$db->querySingle("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'deployments'");
	return $ok;
}

function deploy_row($res) {
	$r = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;
	return $r ?: null;
}

/* One deployment by id, or by domain. */
function deploy_get($which) {
	global $db;
	if (!deploy_ready())
		return null;
	$which = strtolower(trim((string)$which));
	$stmt = ctype_digit($which)
		? $db->prepare('SELECT * FROM deployments WHERE id = :v')
		: $db->prepare('SELECT * FROM deployments WHERE domain = :v');
	$stmt->bindValue(':v', ctype_digit($which) ? (int)$which : $which, ctype_digit($which) ? SQLITE3_INTEGER : SQLITE3_TEXT);
	return deploy_row($stmt->execute());
}

/* All deployments ('' = every account), with the last run joined. */
function deploy_list($account = '') {
	global $db;
	if (!deploy_ready())
		return array();
	$sql = 'SELECT d.*, r.status AS run_status, r.queued AS run_queued, r.started AS run_started, r.finished AS run_finished,
	               r.message AS run_message, r.source AS run_source
	        FROM deployments d LEFT JOIN deploy_runs r ON r.id = d.last_run_id';
	if ($account !== '')
		$sql .= ' WHERE d.account = :a';
	$stmt = $db->prepare($sql.' ORDER BY d.domain');
	if ($account !== '')
		$stmt->bindValue(':a', $account, SQLITE3_TEXT);
	$res = $stmt->execute();
	$rows = array();
	while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC)))
		$rows[] = $r;
	return $rows;
}

/* A deployment as shown to people and programs: no secrets, the repository
   address without a token in it. */
function deploy_public(array $d, $with_secret = false) {
	$out = array(
		'id' => (int)$d['id'], 'account' => $d['account'], 'domain' => $d['domain'],
		'repo_url' => deploy_url_safe($d['repo_url']), 'branch' => $d['branch'], 'public_dir' => $d['public_dir'],
		'build_cmd' => $d['build_cmd'], 'shared_paths' => deploy_lines($d['shared_paths']),
		'keep_releases' => (int)$d['keep_releases'], 'health_path' => $d['health_path'],
		'auto_deploy' => (bool)$d['auto_deploy'], 'current_release' => $d['current_release'],
		'current_commit' => $d['current_commit'], 'last_status' => $d['last_status'],
		'last_run_id' => (int)$d['last_run_id'], 'webhook_url' => deploy_webhook_url($d),
		'docroot' => deploy_docroot($d['domain']), 'created' => (int)$d['created'],
	);
	if ($with_secret)
		$out['webhook_secret'] = $d['webhook_secret'];
	return $out;
}

function deploy_lines($text) {
	return array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string)$text)), 'strlen'));
}

/* "https://user:token@host/x.git" -> "https://***@host/x.git" */
function deploy_url_safe($url) {
	return preg_replace('#^(https?://)[^/@]+@#i', '$1***@', (string)$url);
}

/* The runs of a deployment, newest first. */
function deploy_runs($id, $limit = 20) {
	global $db;
	if (!deploy_ready())
		return array();
	$rows = array();
	$res = $db->query('SELECT * FROM deploy_runs WHERE deployment_id = '.(int)$id.' ORDER BY id DESC LIMIT '.max(1, min(500, (int)$limit)));
	while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC)))
		$rows[] = $r;
	return $rows;
}

function deploy_run_get($run_id) {
	global $db;
	return deploy_row($db->query('SELECT * FROM deploy_runs WHERE id = '.(int)$run_id));
}

function deploy_log_path($run_id) {
	return DEPLOY_LOG_DIR.'/'.(int)$run_id.'.log';
}

/* A run's output from byte $offset: array(text, next offset). */
function deploy_run_log($run_id, $offset = 0) {
	$f = deploy_log_path($run_id);
	clearstatcache(true, $f);     // the CLI follows a growing file
	$size = @filesize($f);
	if ($size === false)
		return array('', 0);
	$offset = max(0, min((int)$offset, $size));
	$text = (string)@file_get_contents($f, false, null, $offset, min(1048576, $size - $offset));
	return array($text, $offset + strlen($text));
}

/* The account's home-relative app directory for a domain. */
function deploy_app_dir($account, $domain) {
	return '/home/'.$account.'/apps/'.$domain;
}

/* Releases on disk, newest first: list of array(release, commit, message).
   (A list, not keyed by name: PHP would turn the numeric names into ints.) */
function deploy_releases(array $d) {
	global $db;
	$dir = deploy_app_dir($d['account'], $d['domain']).'/releases';
	/* listed as the account: never read inside a home as root */
	$names = array_filter(explode("\n", trim((string)shell_exec(deploy_sudo().'runuser -u '.escapeshellarg($d['account']).' -- ls -1 '.escapeshellarg($dir).' 2>/dev/null'))),
	                      function ($n) { return preg_match('/^[0-9]{14}$/', $n); });
	rsort($names);
	$commits = array();
	$res = $db->query('SELECT release_name, commit_sha, commit_msg FROM deploy_runs WHERE deployment_id = '.(int)$d['id']
		." AND status = 'ok' AND release_name <> ''");
	while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC)))
		$commits[$r['release_name']] = array('commit' => $r['commit_sha'], 'message' => $r['commit_msg']);
	$out = array();
	foreach ($names as $n)
		$out[] = array('release' => (string)$n) + ($commits[$n] ?? array('commit' => '', 'message' => ''));
	return $out;
}

/* The webhook address for pushes (see deploy_webhook_ready()). */
function deploy_webhook_url(array $d) {
	return 'https://'.gethostname().'/.reqad-deploy/'.(int)$d['id'];
}

/* Push webhooks reach the server (nginx in front with the hostname vhost
   including scripts/update/setup_deploy_webhook.sh's location). */
function deploy_webhook_ready() {
	return web_front() === 'nginx' && is_file('/etc/nginx/reqad-deploy-hook.conf');
}

/* The SSH key the server reads the repository with. */
function deploy_key_path(array $d) {
	return '/home/'.$d['account'].'/.ssh/reqad_deploy_'.(int)$d['id'];
}

/* Read as the account (a symlink there must not make root show another file). */
function deploy_public_key(array $d) {
	$k = trim((string)shell_exec(deploy_sudo().'runuser -u '.escapeshellarg($d['account']).' -- head -c 2000 '
		.escapeshellarg(deploy_key_path($d).'.pub').' 2>/dev/null'));
	return preg_match('/^(ssh-|ecdsa-)[^\n]+$/', $k) ? $k : '';
}

/* 'sudo ' for the panel (reqad), '' when already root (CLI, the runner). */
function deploy_sudo() {
	return (function_exists('posix_geteuid') && posix_geteuid() === 0) ? '' : 'sudo ';
}

/* ---- domains and document roots ------------------------------------------ */

/* Who owns $domain and where its document root is: array(account, docroot),
   or array('', '') when it is not a main or addon domain. */
function deploy_domain($domain) {
	global $db, $ini;
	$domain = strtolower(trim((string)$domain));
	if (!valid_domain($domain))
		return array('', '');
	if ($row = account_get($db, '', $domain))
		return array($row['user'], '/home/'.$row['user'].'/public_html');
	if ($a = addon_domain_get($ini, $domain))
		return array($a['user'], rtrim($a['docroot'], '/'));
	return array('', '');
}

function deploy_docroot($domain) {
	return deploy_domain($domain)[1];
}

/* Every web root of an account: domain => docroot (main + addons). */
function deploy_account_roots($account) {
	global $db, $ini;
	$out = array();
	if ($row = account_get($db, $account))
		$out[$row['domain']] = '/home/'.$account.'/public_html';
	foreach (addon_domain_list($ini) as $a)
		if ($a['user'] === $account && $a['docroot'] !== '')
			$out[$a['domain']] = rtrim($a['docroot'], '/');
	return $out;
}

/* A deployed document root is replaced by a symlink on the first deploy, so
   no other site of the account may live inside it, and it may not live
   inside another deployed one. '' = fine, else why not. */
function deploy_docroot_conflict($account, $domain, $docroot) {
	$deployed = array_column(deploy_list($account), 'domain');
	foreach (deploy_account_roots($account) as $dom => $root) {
		if ($dom === $domain)
			continue;
		if (strpos($root.'/', $docroot.'/') === 0)
			return "The document root of $dom ($root) is inside $docroot, which a deployment replaces. Move $dom's document root first.";
		if (in_array($dom, $deployed, true) && strpos($docroot.'/', $root.'/') === 0)
			return "$docroot is inside the document root of $dom, which is deployed from Git.";
	}
	return '';
}

/* ---- validation ---------------------------------------------------------- */

function deploy_valid_repo($url) {
	$url = trim((string)$url);
	if (strlen($url) > 500 || preg_match('/[\s\'"`$\\\\;|&<>]/', $url))
		return false;
	/* https://host/path, ssh://[user@]host[:port]/path, user@host:path */
	return (bool)(preg_match('#^https?://[A-Za-z0-9._~%!*()+,=:@-]*[A-Za-z0-9.-]+(:[0-9]+)?/[A-Za-z0-9._~/%+-]+$#', $url)
	           || preg_match('#^ssh://([A-Za-z0-9._-]+@)?[A-Za-z0-9.-]+(:[0-9]+)?/[A-Za-z0-9._~/%+-]+$#', $url)
	           || preg_match('#^[A-Za-z0-9._-]+@[A-Za-z0-9.-]+:[A-Za-z0-9._~/%+-]+$#', $url));
}

function deploy_valid_branch($b) {
	return (bool)preg_match('#^(?!-)(?!.*\.\.)(?!.*//)[A-Za-z0-9._/-]{1,100}(?<![./])$#', (string)$b)
	    && substr((string)$b, -5) !== '.lock';
}

/* A path inside the release: no leading /, no .. segments. Directories may end in /. */
function deploy_valid_relpath($p) {
	$p = (string)$p;
	if ($p === '' || strlen($p) > 200 || !preg_match('#^[A-Za-z0-9._@+-][A-Za-z0-9._@+/-]*$#', $p))
		return false;
	foreach (explode('/', rtrim($p, '/')) as $seg)
		if ($seg === '' || $seg === '.' || $seg === '..')
			return false;
	return true;
}

/* Normalise and check the editable fields; returns array(clean, error). */
function deploy_clean_fields(array $o, array $old = null) {
	$f = array();
	$get = function ($k, $def) use ($o, $old) {
		if (array_key_exists($k, $o)) return $o[$k];
		return $old !== null && array_key_exists($k, $old) ? $old[$k] : $def;
	};
	$f['repo_url'] = trim((string)$get('repo_url', ''));
	if (!deploy_valid_repo($f['repo_url']))
		return array(null, 'The repository address must look like https://github.com/you/site.git or git@github.com:you/site.git.');
	$f['branch'] = trim((string)$get('branch', 'main'));
	if (!deploy_valid_branch($f['branch']))
		return array(null, "'{$f['branch']}' is not a valid branch name.");
	$f['public_dir'] = trim(trim((string)$get('public_dir', '')), '/');
	if ($f['public_dir'] !== '' && !deploy_valid_relpath($f['public_dir']))
		return array(null, 'The public folder is a path inside the repository, like public or dist (no .. or leading /).');
	$f['build_cmd'] = str_replace("\r", '', trim((string)$get('build_cmd', '')));
	if (strlen($f['build_cmd']) > 4000)
		return array(null, 'The build commands are limited to 4000 characters.');
	$shared = deploy_lines(is_array($sp = $get('shared_paths', '')) ? implode("\n", $sp) : $sp);
	foreach ($shared as $p)
		if (!deploy_valid_relpath($p))
			return array(null, "Shared path '$p' must be a path inside the release, like .env or storage/ (no .. or leading /).");
	if (count($shared) > 30)
		return array(null, 'At most 30 shared paths.');
	$f['shared_paths'] = implode("\n", $shared);
	$f['keep_releases'] = (int)$get('keep_releases', 5);
	if ($f['keep_releases'] < 2 || $f['keep_releases'] > 20)
		return array(null, 'Keep between 2 and 20 releases (one is the current one, the others are for rolling back).');
	$f['health_path'] = trim((string)$get('health_path', ''));
	if ($f['health_path'] !== '' && !preg_match('#^/[A-Za-z0-9._~/?&=%+-]{0,199}$#', $f['health_path']))
		return array(null, 'The health check is a path on the site, like / or /health.');
	$f['auto_deploy'] = (int)(bool)$get('auto_deploy', 1);
	return array($f, '');
}

/* ---- create, change, delete ---------------------------------------------- */

/*
 * New deployment for $o['domain'] (a main or addon domain). Fields:
 * repo_url, branch, public_dir, build_cmd, shared_paths (lines or array),
 * keep_releases, health_path, auto_deploy. Makes the deploy key and the
 * webhook secret; the first deploy is started separately (deploy_trigger()).
 */
function deploy_create(array $o, $by = '') {
	global $db;
	if (!deploy_ready())
		return result_error('The deployment tables are missing. Run the Reqad update (database 1043) first.');
	$domain = strtolower(trim((string)($o['domain'] ?? '')));
	list($account, $docroot) = deploy_domain($domain);
	if ($account === '')
		return result_error("$domain is not the main or an addon domain of a hosting account.");
	if (isset($o['account']) && $o['account'] !== '' && $o['account'] !== $account)
		return result_error("$domain belongs to account $account, not {$o['account']}.");
	if (deploy_get($domain))
		return result_error("$domain is already deployed from Git.");
	if (($e = deploy_docroot_conflict($account, $domain, $docroot)) !== '')
		return result_error($e);
	if (($e = limit_allows($account, 'deployment', array('domain' => $domain))) !== '')
		return result_error($e);
	list($f, $err) = deploy_clean_fields($o);
	if ($err !== '')
		return result_error($err);

	$stmt = $db->prepare('INSERT INTO deployments (account, domain, repo_url, branch, public_dir, build_cmd, shared_paths,
	                          keep_releases, health_path, auto_deploy, webhook_secret, created, created_by)
	                      VALUES (:a, :d, :r, :b, :p, :c, :s, :k, :h, :auto, :sec, :now, :by)');
	foreach (array(':a' => $account, ':d' => $domain, ':r' => $f['repo_url'], ':b' => $f['branch'], ':p' => $f['public_dir'],
	               ':c' => $f['build_cmd'], ':s' => $f['shared_paths'], ':h' => $f['health_path'],
	               ':sec' => bin2hex(random_bytes(24)), ':by' => (string)$by) as $k => $v)
		$stmt->bindValue($k, $v, SQLITE3_TEXT);
	$stmt->bindValue(':k', $f['keep_releases'], SQLITE3_INTEGER);
	$stmt->bindValue(':auto', $f['auto_deploy'], SQLITE3_INTEGER);
	$stmt->bindValue(':now', time(), SQLITE3_INTEGER);
	if (!$stmt->execute())
		return result_error('Could not save the deployment: '.$db->lastErrorMsg());
	$d = deploy_get($db->lastInsertRowID());

	$warnings = array();
	if (($e = deploy_make_key($d)) !== '')
		$warnings[] = $e;
	if (($e = deploy_vhost_realpath($domain)) !== '')
		$warnings[] = $e;
	error_log(date('Y-m-d H:i:s')." deploy: created #{$d['id']} $domain ($account) from ".deploy_url_safe($f['repo_url'])." {$f['branch']} by $by\n", 3, _PATH.'/log/debug_log');
	plugin_hook('deployment.created', array('id' => (int)$d['id'], 'account' => $account, 'domain' => $domain));
	return result_ok("Deployment for $domain created. Give the repository access to the deploy key if it is private, then deploy.",
		deploy_public($d, true) + array('public_key' => deploy_public_key($d)), $warnings);
}

function deploy_edit($which, array $o) {
	global $db;
	$d = deploy_get($which);
	if (!$d)
		return result_error("No deployment '$which'.");
	list($f, $err) = deploy_clean_fields($o, $d);
	if ($err !== '')
		return result_error($err);
	$sets = array();
	foreach ($f as $k => $v)
		$sets[] = "$k = :$k";
	$stmt = $db->prepare('UPDATE deployments SET '.implode(', ', $sets).' WHERE id = :id');
	foreach ($f as $k => $v)
		$stmt->bindValue(":$k", $v, is_int($v) ? SQLITE3_INTEGER : SQLITE3_TEXT);
	$stmt->bindValue(':id', (int)$d['id'], SQLITE3_INTEGER);
	$stmt->execute();
	if (!empty($o['new_secret']))
		$db->exec("UPDATE deployments SET webhook_secret = '".bin2hex(random_bytes(24))."' WHERE id = ".(int)$d['id']);
	$changed = array_keys(array_diff_assoc(array_map('strval', $f), array_map('strval', array_intersect_key($d, $f))));
	$msg = "Deployment for {$d['domain']} saved.";
	if (array_intersect($changed, array('public_dir', 'build_cmd', 'shared_paths', 'repo_url', 'branch')))
		$msg .= ' The change applies from the next deploy.';
	return result_ok($msg, deploy_public(deploy_get($d['id'])) + array('changed' => $changed));
}

/*
 * Stop deploying $which. The site keeps running from its current release:
 * the document root stays a symlink into ~/apps/<domain>, which is left as it
 * is (files, shared data, releases). The deploy key is removed.
 */
function deploy_delete($which) {
	global $db;
	$d = deploy_get($which);
	if (!$d)
		return result_error("No deployment '$which'.");
	if (deploy_unit_active((int)$d['id']))
		return result_error("A deploy of {$d['domain']} is running. Wait for it to finish.");
	$key = deploy_key_path($d);
	shell_exec(deploy_sudo().'rm -f '.escapeshellarg($key).' '.escapeshellarg($key.'.pub'));
	deploy_forget((int)$d['id']);
	plugin_hook('deployment.deleted', array('id' => (int)$d['id'], 'account' => $d['account'], 'domain' => $d['domain']));
	return result_ok("Deployment for {$d['domain']} removed. The site keeps running from its last release in "
		.deploy_app_dir($d['account'], $d['domain']).'.', array('id' => (int)$d['id']));
}

/* Drop a deployment's rows and run logs (deploy_delete(), account_delete()). */
function deploy_forget($id) {
	global $db;
	$logs = array();
	$res = $db->query('SELECT id FROM deploy_runs WHERE deployment_id = '.(int)$id);
	while ($res && ($r = $res->fetchArray(SQLITE3_NUM)))
		$logs[] = escapeshellarg(deploy_log_path($r[0]));
	if ($logs)
		shell_exec(deploy_sudo().'rm -f '.implode(' ', $logs));
	$db->exec('DELETE FROM deploy_runs WHERE deployment_id = '.(int)$id);
	$db->exec('DELETE FROM deployments WHERE id = '.(int)$id);
}

/* The account's deploy key (ed25519, no passphrase), made as the account. */
function deploy_make_key(array $d) {
	$key = deploy_key_path($d);
	$u = escapeshellarg($d['account']);
	$cmd = deploy_sudo().'runuser -u '.$u.' -- bash -c '.escapeshellarg(
		'umask 077; mkdir -p "$HOME/.ssh" && chmod 0700 "$HOME/.ssh" && '
		.'[ -f '.escapeshellarg($key).' ] || ssh-keygen -q -t ed25519 -N "" -C '.escapeshellarg('reqad-deploy '.$d['domain'])
		.' -f '.escapeshellarg($key)).' 2>&1';
	exec($cmd, $out, $rc);
	return $rc === 0 ? '' : 'Could not make the deploy key: '.trim(implode(' ', $out));
}

/*
 * nginx: hand PHP the resolved path ($realpath_root) instead of the symlink,
 * so the PHP realpath cache and opcache never mix two releases after a switch.
 * Edits only the fastcgi_param lines of the domain's own vhost; no-op on
 * Apache and when already done. '' = fine, else a warning.
 */
function deploy_vhost_realpath($domain) {
	if (web_php_server() !== 'nginx')
		return '';
	$file = web_vhost_path($domain, 'nginx');
	$conf = (string)shell_exec(deploy_sudo().'cat '.escapeshellarg($file).' 2>/dev/null');
	if ($conf === '' || strpos($conf, '$document_root$fastcgi_script_name') === false)
		return '';
	$new = preg_replace('/^(\s*)fastcgi_param(\s+)SCRIPT_FILENAME(\s+)\$document_root\$fastcgi_script_name;/m',
	                    '$1fastcgi_param$2SCRIPT_FILENAME$3$realpath_root$fastcgi_script_name;'."\n".'$1fastcgi_param$2DOCUMENT_ROOT$3$realpath_root;', $conf);
	if ($new === $conf)
		return '';
	$tmp = tempnam(sys_get_temp_dir(), 'rqdv');
	file_put_contents($tmp, $new);
	shell_exec(deploy_sudo().'cp '.escapeshellarg($file).' '.escapeshellarg($file.'.pre-deploy').' && '
		.deploy_sudo().'bash -c '.escapeshellarg('cat '.escapeshellarg($tmp).' > '.escapeshellarg($file)));
	@unlink($tmp);
	exec(deploy_sudo().'nginx -t 2>&1', $out, $rc);
	if ($rc !== 0) {
		shell_exec(deploy_sudo().'bash -c '.escapeshellarg('cat '.escapeshellarg($file.'.pre-deploy').' > '.escapeshellarg($file)));
		return 'Could not switch the vhost of '.$domain.' to $realpath_root (nginx -t failed); PHP may see the old release for a moment after a deploy.';
	}
	shell_exec(deploy_sudo().'rm -f '.escapeshellarg($file.'.pre-deploy').'; '.deploy_sudo().'systemctl reload nginx');
	return '';
}

/* ---- starting runs ------------------------------------------------------- */

function deploy_unit($id) {
	return 'reqad-deploy-'.(int)$id;
}

function deploy_unit_active($id) {
	return trim((string)shell_exec(deploy_sudo().'systemctl is-active '.deploy_unit($id).' 2>/dev/null')) === 'active';
}

/*
 * Queue a deploy ($source manual | webhook | cli | api) or a rollback
 * ($source 'rollback', $target = release name, '' = the previous one) and
 * start the runner unless one is going already. Returns the run id in data.
 */
function deploy_trigger($which, $source, $by = '', $target = '') {
	global $db;
	$d = deploy_get($which);
	if (!$d)
		return result_error("No deployment '$which'.");
	/* an add-on may hold an account's runs back (Hosting Pro: suspended) */
	if (($why = plugin_filter('account.active', true, $d['account'])) !== true)
		return result_error(is_string($why) && $why !== '' ? $why : "Account {$d['account']} is not active.");
	if ($source === 'rollback') {
		$rels = array_column(deploy_releases($d), 'release');
		if ($target === '') {
			$i = array_search($d['current_release'], $rels, true);
			$target = $i !== false && isset($rels[$i + 1]) ? $rels[$i + 1] : '';
			if ($target === '')
				return result_error("There is no earlier release of {$d['domain']} to roll back to.");
		}
		if (!in_array($target, $rels, true))
			return result_error("Release $target of {$d['domain']} is not on disk (kept: ".($rels ? implode(', ', $rels) : 'none').').');
		if ($target === $d['current_release'])
			return result_error("Release $target is already the current one.");
	}
	/* a run marked running whose unit is gone was killed (time limit, reboot) */
	if (!deploy_unit_active((int)$d['id']))
		$db->exec("UPDATE deploy_runs SET status = 'failed', finished = ".time().", message = 'interrupted (time limit or restart)'"
			." WHERE deployment_id = ".(int)$d['id']." AND status = 'running'");

	$stmt = $db->prepare('INSERT INTO deploy_runs (deployment_id, source, by_user, target, queued) VALUES (:d, :s, :b, :t, :now)');
	$stmt->bindValue(':d', (int)$d['id'], SQLITE3_INTEGER);
	$stmt->bindValue(':s', (string)$source, SQLITE3_TEXT);
	$stmt->bindValue(':b', (string)$by, SQLITE3_TEXT);
	$stmt->bindValue(':t', (string)$target, SQLITE3_TEXT);
	$stmt->bindValue(':now', time(), SQLITE3_INTEGER);
	$stmt->execute();
	$run = $db->lastInsertRowID();
	$db->exec('UPDATE deployments SET last_run_id = '.(int)$run.' WHERE id = '.(int)$d['id']);

	if (!deploy_unit_active((int)$d['id'])) {
		$cmd = deploy_sudo().'systemd-run --quiet --collect --unit='.deploy_unit($d['id'])
			.' --description='.escapeshellarg('Reqad deploy of '.$d['domain'])
			.' -p MemoryLimit=2G -p TasksMax=512 -p CPUQuota=100% -p RuntimeMaxSec=1800 -p Nice=10'
			.' -- '._PATH.'/bin/reqad deploy __run '.(int)$d['id'].' 2>&1';
		exec($cmd, $out, $rc);
		if ($rc !== 0 && !deploy_unit_active((int)$d['id'])) {
			$db->exec("UPDATE deploy_runs SET status = 'failed', finished = ".time().", message = 'could not start: "
				.SQLite3::escapeString(substr(trim(implode(' ', $out)), 0, 200))."' WHERE id = ".(int)$run);
			return result_error('Could not start the deploy: '.trim(implode(' ', $out)));
		}
	}
	$what = $source === 'rollback' ? "Rollback of {$d['domain']} to $target" : "Deploy of {$d['domain']}";
	return result_ok("$what started.", array('run' => (int)$run, 'deployment' => (int)$d['id']));
}

/* ---- the runner (root, inside the unit) ----------------------------------- */

/* Do the build commands install an app's packages themselves (deployments
   made before the runner did it: pip / venv, npm / yarn / pnpm, uv, poetry)? */
function deploy_build_installs($build) {
	return (bool)preg_match('/\b(pip3?\s+install|python[0-9.]*\s+-m\s+(pip|venv)|virtualenv|uv\s+(sync|pip)|poetry\s+install|pipenv\s+(install|sync)'
		.'|npm\s+(ci|install|i)\b|pnpm\s+(install|i)\b|yarn(\s+install)?\s*$)/m', (string)$build);
}

/* Run every queued run of deployment $id, newest only (older ones are skipped). */
function deploy_execute($id) {
	global $db;
	$db->busyTimeout(10000);
	@mkdir(DEPLOY_LOG_DIR, 0750, true);
	@chgrp(DEPLOY_LOG_DIR, 'reqad');
	while (($d = deploy_get($id)) && ($run = deploy_row($db->query("SELECT * FROM deploy_runs WHERE deployment_id = ".(int)$id
	                                     ." AND status = 'queued' ORDER BY id DESC LIMIT 1")))) {
		$db->exec("UPDATE deploy_runs SET status = 'skipped', finished = ".time().", message = 'replaced by run #".(int)$run['id']."'"
			." WHERE deployment_id = ".(int)$id." AND status = 'queued' AND id < ".(int)$run['id']);
		$db->exec("UPDATE deploy_runs SET status = 'running', started = ".time().' WHERE id = '.(int)$run['id']);
		$db->exec("UPDATE deployments SET last_status = 'running', last_run_id = ".(int)$run['id'].' WHERE id = '.(int)$id);
		$log = deploy_log_path($run['id']);
		@file_put_contents($log, '');
		@chmod($log, 0640);
		@chgrp($log, 'reqad');
		$ctx = array('d' => $d, 'run' => $run, 'log' => $log, 'user' => $d['account'],
		             'app' => deploy_app_dir($d['account'], $d['domain']));
		try {
			$r = $run['source'] === 'rollback' ? deploy_do_rollback($ctx) : deploy_do_release($ctx);
		} catch (Throwable $e) {
			$r = array('ok' => false, 'message' => 'internal error: '.$e->getMessage());
		}
		$status = $r['ok'] ? 'ok' : 'failed';
		deploy_say($ctx, ($r['ok'] ? 'Done: ' : 'Failed: ').$r['message']);
		$stmt = $db->prepare('UPDATE deploy_runs SET status = :s, finished = :now, message = :m, commit_sha = :c, commit_msg = :cm,
		                      release_name = :r WHERE id = :id');
		$stmt->bindValue(':s', $status, SQLITE3_TEXT);
		$stmt->bindValue(':now', time(), SQLITE3_INTEGER);
		$stmt->bindValue(':m', substr($r['message'], 0, 500), SQLITE3_TEXT);
		$stmt->bindValue(':c', $r['commit'] ?? '', SQLITE3_TEXT);
		$stmt->bindValue(':cm', substr($r['commit_msg'] ?? '', 0, 200), SQLITE3_TEXT);
		$stmt->bindValue(':r', $r['release'] ?? '', SQLITE3_TEXT);
		$stmt->bindValue(':id', (int)$run['id'], SQLITE3_INTEGER);
		$stmt->execute();
		$db->exec("UPDATE deployments SET last_status = '$status' WHERE id = ".(int)$id);
		plugin_hook('deployment.finished', array('id' => (int)$id, 'domain' => $d['domain'], 'run' => (int)$run['id'],
		                                        'status' => $status, 'source' => $run['source'], 'message' => $r['message']));
	}
}

function deploy_say(array $ctx, $line) {
	@file_put_contents($ctx['log'], '['.date('H:i:s').'] '.$line."\n", FILE_APPEND);
}

/* Run $script (bash) as the account in $cwd with a clean environment; output
   goes to the log. Returns the exit code. $args become "$1", "$2"... */
function deploy_as_user(array $ctx, $script, array $args = array(), $cwd = null, $timeout = 1500) {
	$u = $ctx['user'];
	$home = '/home/'.$u;
	$path = '/usr/local/bin:/usr/bin:/bin';
	if (!empty($ctx['php_bin']))
		$path = $ctx['php_bin'].':'.$path;
	if (!empty($ctx['runtime_bin']))          // the domain's app: its Node.js / Python first
		$path = $ctx['runtime_bin'].':'.$path;
	$env = array('HOME='.$home, 'USER='.$u, 'LOGNAME='.$u, 'PATH='.$path, 'LANG=C.UTF-8', 'TERM=dumb',
	             'GIT_TERMINAL_PROMPT=0', 'COMPOSER_HOME='.$home.'/.cache/composer', 'COMPOSER_NO_INTERACTION=1',
	             'npm_config_cache='.$home.'/.npm', 'CI=1');
	if (!empty($ctx['git_ssh']))
		$env[] = 'GIT_SSH_COMMAND='.$ctx['git_ssh'];
	if (!empty($ctx['extra_env']))
		foreach ($ctx['extra_env'] as $k => $v)
			$env[] = $k.'='.$v;
	$cmd = 'runuser -u '.escapeshellarg($u).' -- env -i '.implode(' ', array_map('escapeshellarg', $env))
		.' timeout --kill-after=30 '.(int)$timeout.' bash -e -o pipefail -c '.escapeshellarg(($cwd !== null ? 'cd '.escapeshellarg($cwd).' && ' : '').$script)
		.' reqad-deploy '.implode(' ', array_map('escapeshellarg', $args))
		.' >> '.escapeshellarg($ctx['log']).' 2>&1 < /dev/null';
	exec($cmd, $o, $rc);
	return $rc;
}

/* Output of a command run as the account (short ones: git rev-parse, ...). */
function deploy_capture(array $ctx, $script, array $args = array()) {
	$u = $ctx['user'];
	$cmd = 'runuser -u '.escapeshellarg($u).' -- env -i HOME=/home/'.$u.' PATH=/usr/bin:/bin LANG=C.UTF-8 bash -c '
		.escapeshellarg($script).' reqad-deploy '.implode(' ', array_map('escapeshellarg', $args)).' 2>/dev/null';
	return trim((string)shell_exec($cmd));
}

function deploy_do_release(array $ctx) {
	global $ini;
	$d = $ctx['d'];
	$app = $ctx['app'];
	$u = $ctx['user'];
	$web = web_group();
	$rel = date('YmdHis');
	while (is_dir("$app/releases/$rel"))
		$rel = date('YmdHis', strtotime($rel) + 1);
	$release = "$app/releases/$rel";
	$res = array('ok' => false, 'release' => '', 'commit' => '', 'commit_msg' => '');

	deploy_say($ctx, "Deploying {$d['domain']} from ".deploy_url_safe($d['repo_url'])." ({$d['branch']}), run #{$ctx['run']['id']} ({$ctx['run']['source']}"
		.($ctx['run']['by_user'] !== '' ? ', '.$ctx['run']['by_user'] : '').')');
	if (!posix_getpwnam($u))
		return array('ok' => false, 'message' => "the system user $u does not exist");
	if (($e = deploy_docroot_conflict($u, $d['domain'], deploy_docroot($d['domain']))) !== '')
		return array('ok' => false, 'message' => $e);

	/* layout: ~/apps 0711; ~/apps/<domain> <user>:<web> 0750 - the web server
	   enters it, other accounts do not; everything below is the account's */
	if (deploy_as_user($ctx, 'umask 022; mkdir -p "$1/releases" "$1/shared" && chmod 0711 "$HOME/apps" && chmod 0750 "$1"', array($app)) !== 0)
		return array('ok' => false, 'message' => "could not create $app");
	/* the web server's group, by root: only a real folder of the account,
	   and chgrp -h never follows a link swapped in at the last moment */
	clearstatcache();
	$pw = posix_getpwnam($u);
	if (is_link($app) || !is_dir($app) || !$pw || fileowner($app) !== (int)$pw['uid'])
		return array('ok' => false, 'message' => "$app is not a folder of $u");
	exec('chgrp -h '.escapeshellarg($web).' '.escapeshellarg($app).' 2>&1', $o, $rc);
	if ($rc !== 0)
		return array('ok' => false, 'message' => "could not give the web server access to $app");

	$key = deploy_key_path($d);
	if (is_file($key))
		$ctx['git_ssh'] = 'ssh -i '.$key.' -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=accept-new';
	else
		$ctx['git_ssh'] = 'ssh -o BatchMode=yes -o StrictHostKeyChecking=accept-new';

	/* fetch into the bare mirror */
	deploy_say($ctx, 'Fetching...');
	$rc = deploy_as_user($ctx,
		'if [ -d "$1/repo.git" ]; then git -C "$1/repo.git" remote set-url origin "$2" && git -C "$1/repo.git" remote update --prune; '
		.'else git clone --mirror --quiet "$2" "$1/repo.git"; fi', array($app, $d['repo_url']), null, 600);
	if ($rc !== 0)
		return array('ok' => false, 'message' => 'git could not fetch the repository - check the address and that the deploy key has access');
	$sha = deploy_capture($ctx, 'git -C "$1/repo.git" rev-parse --verify --quiet "refs/heads/$2^{commit}"', array($app, $d['branch']));
	if (!preg_match('/^[0-9a-f]{40,64}$/', $sha))
		return array('ok' => false, 'message' => "the repository has no branch {$d['branch']}");
	$res['commit'] = $sha;
	$res['commit_msg'] = deploy_capture($ctx, 'git -C "$1/repo.git" log -1 --format=%s "$2"', array($app, $sha));
	deploy_say($ctx, 'Commit '.substr($sha, 0, 10).' '.$res['commit_msg']);

	/* export (no .git in the release) */
	if (deploy_as_user($ctx, 'umask 022; mkdir "$2" && git -C "$1/repo.git" archive --format=tar "$3" | tar -x -C "$2" '
		.'&& printf "%s\n%s\n" "$3" "$(date -u +%FT%TZ)" > "$2/.reqad-release"', array($app, $release, $sha)) !== 0) {
		deploy_cleanup($ctx, $release);
		return array('ok' => false, 'message' => 'could not export the commit') + $res;
	}

	/* shared paths: seeded from the first release, then linked into each one */
	foreach (deploy_lines($d['shared_paths']) as $p) {
		$isdir = substr($p, -1) === '/';
		$p = rtrim($p, '/');
		$rc = deploy_as_user($ctx,
			'R="$1/$3"; S="$2/$3"; '
			.'if [ ! -e "$S" ] && [ ! -L "$S" ]; then mkdir -p "$(dirname "$S")"; '
			.'  if [ -e "$R" ] || [ -L "$R" ]; then mv "$R" "$S"; echo "shared: $3 (taken from this release)"; '
			.'  elif [ "$4" = dir ]; then mkdir -p "$S"; echo "shared: $3/ (new, empty)"; '
			.'  else ( umask 077; : > "$S" ); echo "shared: $3 (new, empty file - fill it in $2/$3)"; fi; fi; '
			.'rm -rf "$R"; mkdir -p "$(dirname "$R")"; ln -s "$S" "$R"',
			array($release, "$app/shared", $p, $isdir ? 'dir' : 'file'));
		if ($rc !== 0) {
			deploy_cleanup($ctx, $release);
			return array('ok' => false, 'message' => "could not link the shared path $p") + $res;
		}
	}

	/* the domain's app: its runtime comes first in PATH for the steps below */
	$ap = function_exists('app_get') ? app_get($d['domain']) : null;
	$rt = $ap ? app_runtime_find($ap['runtime'], $ap['version']) : null;

	/* an app set up before its code was here has no start command yet:
	   found in this release, saved when the release goes live */
	$found_cmd = '';
	if ($ap && (string)$ap['app_dir'] === '' && trim((string)$ap['start_cmd']) === '') {
		$det = app_detect($u, substr($release, strlen('/home/'.$u.'/')), $ap['runtime']);
		if (!$det['found'] || $det['kind'] !== $ap['runtime']) {
			deploy_say($ctx, $det['message']);
			deploy_cleanup($ctx, $release);
			return array('ok' => false, 'message' => ($det['found'] ? lcfirst(rtrim($det['message'], '.')).': change its runtime, or give it a start command'
				: 'no start command found in the code: give the app one in its settings').'; the site was not changed') + $res;
		}
		$found_cmd = $ap['start_cmd'] = $det['start_cmd'];
		deploy_say($ctx, 'Start command: '.$found_cmd.' ('.$det['label'].', '.$det['why'].')');
		foreach ($det['notes'] as $note)
			deploy_say($ctx, 'Note: '.$note);
	}

	/* the app's packages, in this release (Install packages' script): a .venv /
	   node_modules per release, so a rollback gets its own back. Not when the
	   app runs from another folder, or the build commands install them */
	if ($ap && $rt && (string)$ap['app_dir'] === '') {
		if (deploy_build_installs($d['build_cmd']))
			deploy_say($ctx, 'Packages: the build commands install them.');
		else {
			deploy_say($ctx, 'Installing the packages ('.($ap['runtime'] === 'node' ? 'Node.js' : 'Python').' '.$ap['version'].', as '.$u.')...');
			$ctx['runtime_bin'] = $rt['bin'];
			$ctx['extra_env'] = array('REQAD_RELEASE' => $release, 'REQAD_SHARED' => "$app/shared", 'REQAD_DOMAIN' => $d['domain']);
			$rc = deploy_as_user($ctx, app_pkg_script($ap['runtime']), array($ap['version'], $ap['start_cmd']), $release, APP_PKG_TIMEOUT);
			unset($ctx['runtime_bin'], $ctx['extra_env']);
			if ($rc !== 0) {
				deploy_cleanup($ctx, $release);
				return array('ok' => false, 'message' => 'installing the packages failed (exit code '.$rc.(in_array($rc, array(124, 137), true) ? ', time limit' : '').'); the site was not changed') + $res;
			}
		}
	}

	/* build */
	if (trim($d['build_cmd']) !== '') {
		list($ver) = deploy_php($d['domain']);
		if ($ver !== 'off' && $ver !== ($ini['php'] ?? '') && is_dir('/opt/remi/php'.str_replace('.', '', $ver).'/root/usr/bin'))
			$ctx['php_bin'] = '/opt/remi/php'.str_replace('.', '', $ver).'/root/usr/bin';
		$ctx['extra_env'] = array('REQAD_RELEASE' => $release, 'REQAD_SHARED' => "$app/shared", 'REQAD_DOMAIN' => $d['domain']);
		if ($ap && $rt) {
			$ctx['runtime_bin'] = $rt['bin'];
			$ctx['extra_env'][$ap['runtime'] === 'node' ? 'NODE_ENV' : 'REQAD_PYTHON'] = $ap['runtime'] === 'node' ? 'production' : $rt['bin'].'/python';
		}
		deploy_say($ctx, 'Building (as '.$u.(isset($ctx['php_bin']) ? ', PHP '.$ver : '').($ap && $rt ? ', '.$ap['runtime'].' '.$ap['version'] : '').')...');
		$rc = deploy_as_user($ctx, "set -x\n".$d['build_cmd'], array(), $release, 1500);
		if ($rc !== 0) {
			deploy_cleanup($ctx, $release);
			return array('ok' => false, 'message' => 'the build failed (exit code '.$rc.($rc === 124 ? ', time limit' : '').'); the site was not changed') + $res;
		}
		unset($ctx['php_bin'], $ctx['extra_env'], $ctx['runtime_bin']);
	}
	if ($d['public_dir'] !== '' && deploy_as_user($ctx, 'test -d "$1"', array($release.'/'.$d['public_dir'])) !== 0) {
		deploy_cleanup($ctx, $release);
		return array('ok' => false, 'message' => "the release has no folder {$d['public_dir']} (the public folder)") + $res;
	}

	if ($found_cmd !== '' && ($e = app_start_cmd_set($ap['id'], $found_cmd)) !== '') {
		deploy_cleanup($ctx, $release);
		return array('ok' => false, 'message' => $e) + $res;
	}

	/* switch */
	$prev = $d['current_release'];
	if (($e = deploy_switch($ctx, $rel)) !== '') {
		deploy_cleanup($ctx, $release);
		return array('ok' => false, 'message' => $e) + $res;
	}
	deploy_set_current($d, $rel, $sha);
	deploy_say($ctx, "Live: release $rel");

	/* the domain's app runs the new code from now; then the health check.
	   Either failing goes back to the previous release */
	$fail = '';
	if (function_exists('app_get') && app_get($d['domain'])) {
		deploy_say($ctx, 'Restarting the app...');
		$fail = app_after_deploy($d['domain']);
	}
	if ($fail === '' && $d['health_path'] !== '' && ($code = deploy_health($d)) !== '')
		$fail = "the health check https://{$d['domain']}{$d['health_path']} failed ($code)";
	if ($fail !== '') {
		deploy_say($ctx, ucfirst($fail));
		if ($prev !== '' && is_dir("$app/releases/$prev")) {
			deploy_switch($ctx, $prev);
			$pc = deploy_capture($ctx, 'head -1 "$1/.reqad-release"', array("$app/releases/$prev"));
			deploy_set_current($d, $prev, $pc);
			if (function_exists('app_get') && app_get($d['domain']))
				app_after_deploy($d['domain']);
			deploy_say($ctx, "Rolled back to $prev");
			deploy_cleanup($ctx, $release);     // never offered as a rollback target
			return array('ok' => false, 'message' => "$fail; rolled back to release $prev") + $res;
		}
		return array('ok' => false, 'message' => "$fail; no earlier release to go back to") + array('release' => $rel) + $res;
	}

	deploy_prune($ctx, $rel);
	$res['ok'] = true;
	$res['release'] = $rel;
	$res['message'] = 'release '.$rel.' ('.substr($sha, 0, 10).') is live';
	return $res;
}

function deploy_do_rollback(array $ctx) {
	$d = $ctx['d'];
	$rel = $ctx['run']['target'];
	$dir = $ctx['app'].'/releases/'.$rel;
	deploy_say($ctx, "Rolling {$d['domain']} back to release $rel, run #{$ctx['run']['id']}");
	if (!preg_match('/^[0-9]{14}$/', $rel) || !is_dir($dir))
		return array('ok' => false, 'message' => "release $rel is not on disk");
	if (($e = deploy_switch($ctx, $rel)) !== '')
		return array('ok' => false, 'message' => $e);
	$sha = deploy_capture($ctx, 'head -1 "$1/.reqad-release"', array($dir));
	deploy_set_current($d, $rel, $sha);
	if (function_exists('app_get') && app_get($d['domain']) && ($e = app_after_deploy($d['domain'])) !== '')
		deploy_say($ctx, 'Warning: '.$e);
	return array('ok' => true, 'message' => "release $rel is live again", 'release' => $rel, 'commit' => $sha,
	             'commit_msg' => deploy_capture($ctx, 'git -C "$1/repo.git" log -1 --format=%s "$2" 2>/dev/null', array($ctx['app'], $sha)));
}

/*
 * Point current at releases/$rel (atomically: a new symlink renamed over the
 * old one) and make sure the document root is a symlink to current[/public].
 * The first time, files already in the document root are moved aside to
 * <docroot>.before-deploy-<time>. '' = done, else why not.
 */
function deploy_switch(array $ctx, $rel) {
	global $ini;
	$d = $ctx['d'];
	$app = $ctx['app'];
	$docroot = deploy_docroot($d['domain']);
	if ($docroot === '' || strpos($docroot, '/home/'.$ctx['user'].'/') !== 0)
		return "no document root found for {$d['domain']}";
	$target = $app.'/current'.($d['public_dir'] !== '' ? '/'.$d['public_dir'] : '');

	if (deploy_as_user($ctx, 'ln -sfn "releases/$2" "$1/current.new" && mv -T "$1/current.new" "$1/current"', array($app, $rel)) !== 0)
		return 'could not switch the current release';

	clearstatcache();
	if (is_link($docroot)) {
		if (readlink($docroot) !== $target
		    && deploy_as_user($ctx, 'ln -sfn "$2" "$1.reqad-new" && mv -T "$1.reqad-new" "$1"', array($docroot, $target)) !== 0)
			return "could not point $docroot at the release";
	} else {
		if (file_exists($docroot)) {
			$aside = $docroot.'.before-deploy-'.date('YmdHis');
			if (deploy_as_user($ctx, 'mv -T "$1" "$2"', array($docroot, $aside)) !== 0)
				return "could not move the old files in $docroot aside";
			deploy_say($ctx, "The files that were in $docroot are now in $aside");
		}
		if (deploy_as_user($ctx, 'ln -s "$2" "$1"', array($docroot, $target)) !== 0)
			return "could not link $docroot to the release";
	}

	/* Apache resolves the symlinked document root itself: reload it and the
	   account's PHP-FPM so no worker keeps paths of the old release */
	if (web_php_server() === 'httpd') {
		exec('systemctl reload httpd 2>&1');
		list($ver, $handler) = deploy_php($d['domain']);
		if ($handler === 'fpm')
			exec('systemctl reload '.escapeshellarg(addon_fpm_service($ini, $ver)).' 2>&1');
	}
	return '';
}

function deploy_set_current(array $d, $rel, $sha) {
	global $db;
	$stmt = $db->prepare('UPDATE deployments SET current_release = :r, current_commit = :c WHERE id = :id');
	$stmt->bindValue(':r', $rel, SQLITE3_TEXT);
	$stmt->bindValue(':c', (string)$sha, SQLITE3_TEXT);
	$stmt->bindValue(':id', (int)$d['id'], SQLITE3_INTEGER);
	$stmt->execute();
}

/* '' when https://<domain><health_path> answers 2xx/3xx (3 tries), else what it answered. */
function deploy_health(array $d) {
	$code = '';
	for ($i = 0; $i < 3; $i++) {
		if ($i) sleep(3);
		$code = trim((string)shell_exec('curl -sk -o /dev/null -m 20 -w %{http_code} --resolve '
			.escapeshellarg($d['domain'].':443:127.0.0.1').' '.escapeshellarg('https://'.$d['domain'].$d['health_path'])));
		if (preg_match('/^[23][0-9][0-9]$/', $code))
			return '';
	}
	return $code === '' || $code === '000' ? 'no answer' : 'HTTP '.$code;
}

function deploy_cleanup(array $ctx, $release) {
	deploy_as_user($ctx, 'rm -rf "$1"', array($release));
}

/* Keep the newest keep_releases releases (and always the live one). */
function deploy_prune(array $ctx, $live) {
	$keep = max(2, (int)$ctx['d']['keep_releases']);
	$all = array_column(deploy_releases($ctx['d']), 'release');
	$n = 0;
	foreach ($all as $r) {
		if ($r === $live || ++$n < $keep)
			continue;
		deploy_as_user($ctx, 'rm -rf "$1"', array($ctx['app'].'/releases/'.$r));
		deploy_say($ctx, "Removed old release $r");
	}
}

/* PHP version and handler of a main or addon domain: array(version, fpm|mod_php|off|none). */
function deploy_php($domain) {
	global $db, $ini;
	if (account_get($db, '', $domain))
		return account_php($domain);
	$a = addon_domain_php($ini, $domain);
	return array($a['version'], $a['handler']);
}

/* ---- webhooks ------------------------------------------------------------ */

/*
 * A push webhook for deployment $id with the raw body and the request
 * headers (lower-case names). Verifies the signature of the forge it comes
 * from, ignores pushes to other branches, queues a deploy. array(status, result).
 */
function deploy_webhook($id, $body, array $h) {
	if (!deploy_enabled())
		return array(404, result_error('Deployments are turned off on this server.'));
	$d = ctype_digit((string)$id) ? deploy_get($id) : null;
	$secret = $d ? $d['webhook_secret'] : 'x';
	$forge = '';
	$ok = false;
	if (isset($h['x-hub-signature-256'])) {             // GitHub, Bitbucket, Gitea/Forgejo too
		$forge = isset($h['x-github-event']) ? 'github' : (isset($h['x-event-key']) ? 'bitbucket' : 'gitea');
		$ok = hash_equals('sha256='.hash_hmac('sha256', $body, $secret), trim($h['x-hub-signature-256']));
	} elseif (isset($h['x-gitea-signature']) || isset($h['x-gogs-signature'])) {
		$forge = 'gitea';
		$ok = hash_equals(hash_hmac('sha256', $body, $secret), trim($h['x-gitea-signature'] ?? $h['x-gogs-signature']));
	} elseif (isset($h['x-gitlab-token'])) {
		$forge = 'gitlab';
		$ok = hash_equals($secret, (string)$h['x-gitlab-token']);
	}
	if (!$d || !$ok)
		return array(401, result_error($forge === '' ? 'Unsigned request: set the webhook secret in your Git host.' : 'Bad signature.'));
	$event = strtolower((string)($h['x-github-event'] ?? $h['x-gitea-event'] ?? $h['x-gogs-event'] ?? $h['x-gitlab-event'] ?? $h['x-event-key'] ?? ''));
	if ($event === 'ping')
		return array(200, result_ok('pong'));
	$p = json_decode($body, true);
	if (!is_array($p))
		return array(400, result_error('The body is not JSON (set the content type to application/json).'));
	/* the pushed branch: refs/heads/<b> (GitHub, GitLab, Gitea); Bitbucket: push.changes[].new.name */
	$branches = array();
	if (isset($p['ref']) && is_string($p['ref']) && strpos($p['ref'], 'refs/heads/') === 0)
		$branches[] = substr($p['ref'], 11);
	foreach ((array)($p['push']['changes'] ?? array()) as $c)
		if (($c['new']['type'] ?? '') === 'branch' && isset($c['new']['name']))
			$branches[] = $c['new']['name'];
	if (!in_array($d['branch'], $branches, true))
		return array(200, result_ok('Ignored: not a push to '.$d['branch'].'.'));
	if (!(int)$d['auto_deploy'])
		return array(200, result_ok('Ignored: deploying on push is turned off for '.$d['domain'].'.'));
	$r = deploy_trigger($d['id'], 'webhook', $forge);
	return array($r['ok'] ? 202 : 500, $r);
}
