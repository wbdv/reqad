<?php
/*
 * REST API: https://<host>:2087/api/v1/ - Bearer tokens, JSON in and out.
 *
 * Available only with api=1 in etc/server-software.ini (off by default: no
 * menu entry, page, CLI or requests), then off until the setting api-enabled
 * is 1 (Settings > API access, `reqad api on`). nginx sends /api/ straight to app/api/index.php, outside the panel
 * login: no basic auth, no session, and the vhost drops the Cookie header, so
 * the token is the only credential and a cross-site request has nothing to
 * ride on. The nginx block: scripts/update/setup_panel_api.sh.
 *
 * Tokens (api_tokens, db/1042.sql): only the sha256 is stored, the token is
 * shown once. A token acts for the whole server, or for one hosting account -
 * then every route asks scope_check() (app/functions/scope.php) and lists
 * show only what the account owns. Permissions: read, write (create and
 * change), delete. Optional expiry and address allowlist.
 *
 * An unknown token counts as a failed login (auth_attempts, outcome
 * bad_token): the same lockout and csf bans as the login form. Revoked and
 * expired tokens are refused without counting, so a forgotten cron job does
 * not get its own server's address banned.
 *
 * Token kinds (db/1045.sql): 'api' tokens work here, 'mcp' tokens only for
 * AI assistants through `reqad mcp` (app/mcp/mcp.php) - so a token copied
 * out of an assistant's settings cannot call the API over the network - and
 * 'dns' keys (db/1055.sql) only for the PowerDNS-compatible DNS API
 * (/pdns/, app/functions/dns_keys.php).
 *
 * Routes: app/api/routes.php (api_routes(), extended by plugins through the
 * 'api.routes' filter). Loaded by index.php and bin/reqad for the token
 * functions; app/api/index.php serves the requests. api_route_refusal() and
 * api_run() are shared with the MCP server.
 */

define('API_TOKEN_PREFIX', 'rqd_');

/* Permissions a token can have; read is always included. */
function api_scopes() {
	return array('read' => 'Read', 'write' => 'Create and change', 'delete' => 'Delete');
}

/* Expiry choices offered in the panel, in days (0 = never). */
function api_expiry_choices() {
	return array(30 => '30 days', 90 => '90 days', 365 => '1 year', 0 => 'Never');
}

/* The tables exist (migration 1042 applied). */
function api_ready() {
	global $db;
	static $ok = null;
	if ($ok === null)
		$ok = $db instanceof SQLite3
		   && (bool)@$db->querySingle("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'api_tokens'");
	return $ok;
}

/* api_tokens has the kind column (migration 1045). */
function api_has_kinds() {
	global $db;
	static $ok = null;
	if ($ok === null) {
		$ok = false;
		$res = api_ready() ? $db->query('PRAGMA table_info(api_tokens)') : false;
		while ($res && ($c = $res->fetchArray(SQLITE3_ASSOC)))
			if ($c['name'] === 'kind')
				$ok = true;
	}
	return $ok;
}

/* The kinds of token: 'api' (REST API), 'mcp' (AI assistants), 'dns' (DNS API keys). */
function api_token_kinds() {
	return array('api', 'mcp', 'dns');
}

/* 'api', 'mcp' or 'dns' (tokens made before migration 1045 are API tokens). */
function api_token_kind(array $t) {
	return isset($t['kind']) && in_array($t['kind'], api_token_kinds(), true) ? $t['kind'] : 'api';
}

/* api=1 in etc/server-software.ini: the API exists on this server at all. */
function api_available() {
	return feature_enabled($GLOBALS['ini'], 'api');
}

function api_enabled() {
	return api_available() && api_ready() && setting_get('api-enabled', '0') === '1';
}

function api_set_enabled($on) {
	if ($on && !api_available())
		return result_error('The REST API is not available on this server: set api=1 in /usr/local/reqad/etc/server-software.ini first.');
	setting_put('api-enabled', $on ? '1' : '0');
	auth_log_event($on ? 'api_on' : 'api_off', '', 0, auth_user());
	return result_ok($on ? 'The API is on.' : 'The API is off. Tokens are kept, but no request is answered.');
}

/* The base URL clients call. */
function api_url() {
	$host = PHP_SAPI !== 'cli' && !empty($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : gethostname().':2087';
	return 'https://'.$host.'/api/v1/';
}

function api_token_hash($token) {
	return hash('sha256', (string)$token);
}

function api_token_row($res) {
	$r = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;
	return $r ?: null;
}

function api_token_get($id) {
	global $db;
	$stmt = $db->prepare('SELECT * FROM api_tokens WHERE id = :id');
	$stmt->bindValue(':id', (int)$id, SQLITE3_INTEGER);
	return api_token_row($stmt->execute());
}

/* The row for a presented token (any state), or null. */
function api_token_find($token) {
	global $db;
	if (!is_string($token) || strpos($token, API_TOKEN_PREFIX) !== 0 || strlen($token) > 100)
		return null;
	$stmt = $db->prepare('SELECT * FROM api_tokens WHERE token_hash = :h');
	$stmt->bindValue(':h', api_token_hash($token), SQLITE3_TEXT);
	return api_token_row($stmt->execute());
}

/* 'active' | 'expired' | 'revoked' */
function api_token_state(array $t) {
	if ((int)$t['revoked'] > 0)
		return 'revoked';
	if ((int)$t['expires'] > 0 && (int)$t['expires'] < time())
		return 'expired';
	return 'active';
}

function api_token_scopes(array $t) {
	return array_values(array_intersect(array_keys(api_scopes()), explode(',', (string)$t['scopes'])));
}

function api_token_ips(array $t) {
	return array_values(array_filter(explode(',', (string)$t['allow_ips']), 'strlen'));
}

/* Tokens of one kind (api | mcp | dns), newest first; revoked ones only when asked. */
function api_tokens_list($with_revoked = false, $kind = 'api') {
	global $db;
	$rows = array();
	$where = array();
	$kind = in_array($kind, api_token_kinds(), true) ? $kind : 'api';
	if (!$with_revoked)
		$where[] = 'revoked = 0';
	if (api_has_kinds())
		$where[] = "kind = '$kind'";
	elseif ($kind !== 'api')
		return array();
	$res = $db->query('SELECT * FROM api_tokens'.($where ? ' WHERE '.implode(' AND ', $where) : '').' ORDER BY id DESC');
	while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
		unset($r['token_hash']);
		$r['state'] = api_token_state($r);
		$rows[] = $r;
	}
	return $rows;
}

/*
 * A new token. $account '' = the whole server. $scopes: any of read, write,
 * delete (read is added). $days 0 = never expires. $allow_ips: addresses or
 * CIDRs, comma or space separated, '' = from anywhere. $kind 'mcp' = a
 * token for AI assistants (`reqad mcp serve`), 'dns' = a DNS API key
 * (dns_key_create()); both refused by the REST API. The token is in
 * data.token - the only time it is available.
 */
function api_token_create($name, $account, array $scopes, $days, $allow_ips = '', $by = '', $kind = 'api') {
	global $db;
	if (!api_ready())
		return result_error('The API tables are missing. Run the Reqad update (database 1042) first.');
	$kind = in_array($kind, api_token_kinds(), true) ? $kind : 'api';
	if ($kind !== 'api' && !api_has_kinds())
		return result_error('The token tables are out of date. Run the Reqad update (database 1045) first.');
	$name = trim((string)$name);
	if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._@-]{0,59}$/', $name))
		return result_error('Give the token a name: up to 60 letters, digits, spaces and . _ @ -');
	$account = trim((string)$account);
	if ($account !== '' && (!valid_username($account) || !account_get($db, $account)))
		return result_error("There is no hosting account '$account'.");
	$bad = array_diff($scopes, array_keys(api_scopes()));
	if ($bad)
		return result_error('Unknown permission: '.implode(', ', $bad).' (read, write or delete).');
	$scopes = array_values(array_intersect(array_keys(api_scopes()), array_merge(array('read'), $scopes)));
	$days = (int)$days;
	if ($kind === 'mcp' && ($days < 1 || $days > 90))
		return result_error('A token for AI assistants expires after 1 to 90 days.');
	if ($days < 0 || $days > 3650)
		return result_error('The expiry is a number of days from 1 to 3650, or 0 for never.');
	$ips = array();
	foreach (preg_split('/[\s,]+/', trim((string)$allow_ips), -1, PREG_SPLIT_NO_EMPTY) as $a) {
		$n = auth_fw_normalize($a);
		if ($n === '')
			return result_error("'$a' is not an IP address or network (e.g. 203.0.113.7 or 203.0.113.0/24).");
		$ips[$n] = true;
	}
	$dup = $db->prepare('SELECT 1 FROM api_tokens WHERE name = :n AND revoked = 0');
	$dup->bindValue(':n', $name, SQLITE3_TEXT);
	if (api_token_row($dup->execute()))
		return result_error("A token named '$name' already exists.");

	$token = API_TOKEN_PREFIX.bin2hex(random_bytes(20));
	$now = time();
	$stmt = $db->prepare('INSERT INTO api_tokens (name, token_hash, prefix, account, scopes, allow_ips, created, created_by, expires'
	                     .(api_has_kinds() ? ', kind' : '').')
	                      VALUES (:n, :h, :p, :a, :s, :ip, :now, :by, :exp'.(api_has_kinds() ? ', :kind' : '').')');
	if (api_has_kinds())
		$stmt->bindValue(':kind', $kind, SQLITE3_TEXT);
	$stmt->bindValue(':n', $name, SQLITE3_TEXT);
	$stmt->bindValue(':h', api_token_hash($token), SQLITE3_TEXT);
	$stmt->bindValue(':p', substr($token, 0, 12), SQLITE3_TEXT);
	$stmt->bindValue(':a', $account, SQLITE3_TEXT);
	$stmt->bindValue(':s', implode(',', $scopes), SQLITE3_TEXT);
	$stmt->bindValue(':ip', implode(',', array_keys($ips)), SQLITE3_TEXT);
	$stmt->bindValue(':now', $now, SQLITE3_INTEGER);
	$stmt->bindValue(':by', (string)$by, SQLITE3_TEXT);
	$stmt->bindValue(':exp', $days > 0 ? $now + $days * 86400 : 0, SQLITE3_INTEGER);
	if (!$stmt->execute())
		return result_error('Could not save the token: '.$db->lastErrorMsg());
	$id = $db->lastInsertRowID();
	auth_log_event($kind === 'api' ? 'api_token_created' : $kind.'_token_created', "#$id $name (".($account !== '' ? "account $account" : 'whole server')
		.', '.implode('+', $scopes).($ips ? ', from '.implode(' ', array_keys($ips)) : '').')', 0, (string)$by);
	plugin_hook('api.token.created', array('id' => $id, 'name' => $name, 'account' => $account, 'scopes' => $scopes, 'kind' => $kind));
	return result_ok("Token '$name' created. Copy it now - it is not shown again.",
		array('id' => $id, 'name' => $name, 'token' => $token, 'account' => $account, 'scopes' => $scopes,
		      'allow_ips' => array_keys($ips), 'expires' => $days > 0 ? $now + $days * 86400 : 0, 'kind' => $kind));
}

/* Revoke by id, or by name / prefix (CLI). */
function api_token_revoke($which, $by = '') {
	global $db;
	if (!api_ready())
		return result_error('The API tables are missing. Run the Reqad update (database 1042) first.');
	$which = trim((string)$which);
	$t = ctype_digit($which) ? api_token_get($which) : null;
	if (!$t && $which !== '') {
		$stmt = $db->prepare('SELECT * FROM api_tokens WHERE revoked = 0 AND (name = :w OR prefix = :w)');
		$stmt->bindValue(':w', $which, SQLITE3_TEXT);
		$t = api_token_row($stmt->execute());
	}
	if (!$t)
		return result_error("No token '$which'.");
	if ((int)$t['revoked'] > 0)
		return result_ok("Token '{$t['name']}' was already revoked.", array('id' => (int)$t['id']));
	$db->exec('UPDATE api_tokens SET revoked = '.time().' WHERE id = '.(int)$t['id']);
	if (api_token_kind($t) === 'mcp' && function_exists('mcp_token_file_remove'))
		mcp_token_file_remove($t['id']);
	auth_log_event('api_token_revoked', "#{$t['id']} {$t['name']}", 0, (string)$by);
	plugin_hook('api.token.revoked', array('id' => (int)$t['id'], 'name' => $t['name']));
	return result_ok("Token '{$t['name']}' revoked. Requests with it are refused from now on.", array('id' => (int)$t['id']));
}

/* Latest calls of one token kind (api | mcp | dns), newest first, with the token's name. */
function api_log_rows($limit = 50, $token_id = 0, $kind = 'api') {
	global $db;
	$kind = in_array($kind, api_token_kinds(), true) ? $kind : 'api';
	if (!api_ready() || ($kind !== 'api' && !api_has_kinds()))
		return array();
	$rows = array();
	$where = array();
	if ($token_id)
		$where[] = 'l.token_id = '.(int)$token_id;
	if (api_has_kinds())
		$where[] = $kind !== 'api' ? "t.kind = '$kind'" : "(t.kind IS NULL OR t.kind = 'api')";
	$res = $db->query('SELECT l.*, t.name AS token_name, t.account FROM api_log l LEFT JOIN api_tokens t ON t.id = l.token_id'
		.($where ? ' WHERE '.implode(' AND ', $where) : '').' ORDER BY l.id DESC LIMIT '.max(1, min(1000, (int)$limit)));
	while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC)))
		$rows[] = $r;
	return $rows;
}

/* ---- serving requests (app/api/index.php) -------------------------------- */

function api_respond($status, array $result, array $headers = array()) {
	http_response_code($status);
	foreach ($headers as $k => $v)
		header("$k: $v");
	echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
}

/* A handler's error with its HTTP status. */
function api_error($status, $message, $data = array()) {
	return array($status, result_error($message, $data));
}

function api_log_call(array $t, $method, $path, $status, $t0) {
	global $db;
	$stmt = $db->prepare('INSERT INTO api_log (ts, token_id, ip, method, path, status, ms) VALUES (:ts, :t, :ip, :m, :p, :s, :ms)');
	$stmt->bindValue(':ts', time(), SQLITE3_INTEGER);
	$stmt->bindValue(':t', (int)$t['id'], SQLITE3_INTEGER);
	$stmt->bindValue(':ip', auth_ip(), SQLITE3_TEXT);
	$stmt->bindValue(':m', $method, SQLITE3_TEXT);
	$stmt->bindValue(':p', substr($path, 0, 200), SQLITE3_TEXT);
	$stmt->bindValue(':s', (int)$status, SQLITE3_INTEGER);
	$stmt->bindValue(':ms', (int)round((microtime(true) - $t0) * 1000), SQLITE3_INTEGER);
	$stmt->execute();
	if (mt_rand(1, 100) === 1)
		$db->exec('DELETE FROM api_log WHERE ts < '.(time() - 30 * 86400));
}

/* Which token is calling, or the response that refuses the request. */
function api_authenticate() {
	$h = isset($_SERVER['HTTP_AUTHORIZATION']) ? (string)$_SERVER['HTTP_AUTHORIZATION'] : '';
	if (!preg_match('/^Bearer\s+(\S+)\s*$/i', $h, $m)) {
		$ip = auth_ip();
		if (($left = auth_ip_locked($ip)) > 0)
			return api_error(429, 'Too many failed attempts from this address. Try again in '.ceil($left / 60).' minutes.')
				+ array(2 => array('Retry-After' => (string)$left));
		return api_error(401, 'Send the token in the header "Authorization: Bearer <token>".')
			+ array(2 => array('WWW-Authenticate' => 'Bearer realm="reqad"'));
	}
	return api_token_check($m[1], 'api');
}

/* The row of token $token if it may be used now, from this address, for
   $kind ('api' REST API, 'dns' DNS API) - else the response that refuses the
   request: array(status, result[, headers[, token row]]). An unknown token
   counts as a failed login. */
function api_token_check($token, $kind) {
	global $db;
	$ip = auth_ip();
	if (($left = auth_ip_locked($ip)) > 0)
		return api_error(429, 'Too many failed attempts from this address. Try again in '.ceil($left / 60).' minutes.')
			+ array(2 => array('Retry-After' => (string)$left));
	$t = api_token_find($token);
	if (!$t) {
		auth_attempt_record($ip, '', 'bad_token');
		auth_log_event($kind === 'dns' ? 'dns_bad_key' : 'api_bad_token', substr((string)$token, 0, 12).'...');
		auth_after_failure($ip, '');
		return api_error(401, $kind === 'dns' ? 'Invalid key.' : 'Invalid token.') + array(2 => array('WWW-Authenticate' => 'Bearer realm="reqad", error="invalid_token"'));
	}
	$state = api_token_state($t);
	if ($state !== 'active')
		return array(401, result_error($state === 'revoked' ? 'This token was revoked.'
			: 'This token expired on '.date('Y-m-d', (int)$t['expires']).'.'), array('WWW-Authenticate' => 'Bearer realm="reqad", error="invalid_token"'), $t);
	$ips = api_token_ips($t);
	if ($ips) {
		$ok = false;
		foreach ($ips as $c)
			if (auth_ip_in_cidr($ip, $c)) { $ok = true; break; }
		if (!$ok)
			return array(403, result_error("This token may not be used from $ip."), array(), $t);
	}
	if (api_token_kind($t) !== $kind) {
		$what = array('api' => 'the REST API (/api/)', 'mcp' => 'AI assistants (reqad mcp serve over SSH)', 'dns' => 'the DNS API (/pdns/)');
		return array(403, result_error('This token is for '.$what[api_token_kind($t)].', not for '.$what[$kind].'.'), array(), $t);
	}
	if ($t['account'] !== '' && !account_get($db, $t['account']))
		return array(403, result_error("The account {$t['account']} this token belongs to no longer exists."), array(), $t);

	if ($t['last_ip'] !== $ip || (int)$t['last_used'] < time() - 60) {
		if ($t['last_ip'] !== '' && $t['last_ip'] !== $ip)
			auth_log_event('api_new_ip', "token #{$t['id']} {$t['name']} used from $ip (before: {$t['last_ip']})");
		$stmt = $db->prepare('UPDATE api_tokens SET last_used = :now, last_ip = :ip WHERE id = :id');
		$stmt->bindValue(':now', time(), SQLITE3_INTEGER);
		$stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
		$stmt->bindValue(':id', (int)$t['id'], SQLITE3_INTEGER);
		$stmt->execute();
	}
	return $t;
}

/* The request body as an array: JSON (also when sent without a JSON
   Content-Type, as `curl -d '{...}'` does), or form fields. null = not valid JSON. */
function api_body() {
	$type = isset($_SERVER['CONTENT_TYPE']) ? strtolower((string)$_SERVER['CONTENT_TYPE']) : '';
	if (strpos($type, 'multipart/form-data') === 0)
		return $_POST;
	$raw = trim((string)file_get_contents('php://input'));
	if ($raw === '')
		return array();
	if ($raw[0] === '{' || strpos($type, 'json') !== false) {
		$v = json_decode($raw, true);
		return is_array($v) ? $v : null;
	}
	/* PHP fills $_POST for POST only; PATCH and DELETE forms are parsed here */
	parse_str($raw, $form);
	return $form;
}

/* The route for $method $path: array(route, params), or an error response. */
function api_match($method, $path) {
	$allowed = array();
	foreach (api_routes() as $r) {
		$re = '#^'.preg_replace('#\\\\\{([a-z_]+)\\\\\}#', '(?P<$1>[^/]+)', preg_quote($r['path'], '#')).'$#';
		if (!preg_match($re, $path, $m))
			continue;
		if ($r['method'] !== $method) {
			$allowed[] = $r['method'];
			continue;
		}
		$params = array();
		foreach ($m as $k => $v)
			if (is_string($k))
				$params[$k] = rawurldecode($v);
		return array($r, $params);
	}
	if ($allowed)
		return api_error(405, "Use ".implode(' or ', array_unique($allowed))." for $path.") + array(2 => array('Allow' => implode(', ', array_unique($allowed))));
	return api_error(404, "No API endpoint $method $path. GET /api/v1/ lists them.");
}

function api_serve() {
	$t0 = microtime(true);
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-store');
	header('X-Content-Type-Options: nosniff');
	header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

	$method = strtoupper((string)$_SERVER['REQUEST_METHOD']);
	$path   = (string)parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH);
	$path   = '/'.trim(substr($path, strlen('/api')), '/');

	/* Git push webhooks: signed by the Git host with the deployment's secret,
	   no token - and answered whether the API is on or not (Accounts >
	   Deployments, app/functions/deploy.php) */
	if (preg_match('#^/v1/hooks/deploy/([0-9]{1,9})$#', $path, $hm)) {
		if ($method !== 'POST') {
			api_respond(405, result_error('Git hosts POST here.'), array('Allow' => 'POST'));
			return;
		}
		$hdr = array();
		foreach ($_SERVER as $k => $v)
			if (strpos($k, 'HTTP_') === 0)
				$hdr[strtolower(str_replace('_', '-', substr($k, 5)))] = (string)$v;
		$out = deploy_webhook($hm[1], (string)file_get_contents('php://input'), $hdr);
		$from = isset($hdr['x-forwarded-for']) && in_array(auth_ip(), array('127.0.0.1', '::1'), true) ? $hdr['x-forwarded-for'] : auth_ip();
		error_log(date('Y-m-d H:i:s').' deploy webhook #'.(int)$hm[1].' from '.preg_replace('/[^0-9a-fA-F.:, ]/', '', $from)
			.': '.$out[0].' '.($out[1]['ok'] ? $out[1]['message'] : $out[1]['error'])."\n", 3, _PATH.'/log/debug_log');
		api_respond($out[0], $out[1]);
		return;
	}

	if (!api_available()) {
		api_respond(403, result_error('The API is not available on this server (api=0 in etc/server-software.ini).'));
		return;
	}
	if (!api_enabled()) {
		api_respond(403, result_error('The API is turned off on this server. Turn it on in the panel under Settings > API access.'));
		return;
	}

	$t = api_authenticate();
	if (!isset($t['token_hash'])) {
		/* refused: array(status, result[, headers[, token row]]) */
		if (isset($t[3]))
			api_log_call($t[3], $method, $path, $t[0], $t0);
		api_respond($t[0], $t[1], $t[2] ?? array());
		return;
	}
	$GLOBALS['api_actor'] = 'api:'.$t['name'];
	$scope = $t['account'] !== '' ? $t['account'] : null;
	$GLOBALS['api_account'] = (string)$t['account'];     /* '' = a server token (messages for the admin) */

	$m = api_match($method, $path);
	if (!isset($m[0]['handler'])) {
		api_log_call($t, $method, $path, $m[0], $t0);
		api_respond($m[0], $m[1], $m[2] ?? array());
		return;
	}
	list($route, $params) = $m;
	$out = api_route_refusal($route, $t);
	if (!$out) {
		$body = in_array($method, array('POST', 'PUT', 'PATCH', 'DELETE'), true) ? api_body() : array();
		if ($body === null)
			$out = api_error(400, 'The request body is not valid JSON.');
	}
	if (!$out) {
		$out = api_run($route, array('token' => $t, 'scope' => $scope, 'params' => $params, 'query' => $_GET, 'body' => $body,
		                             'method' => $method, 'path' => $path));
	}
	if (isset($out['ok']))
		$out = array($out['ok'] ? 200 : 400, $out);
	if (isset($out['stream'])) {
		api_log_call($t, $method, $path, api_send_stream($out['stream'], $method.' '.$path), $t0);
		return;
	}
	api_log_call($t, $method, $path, $out[0], $t0);
	api_respond($out[0], $out[1]);
}

/*
 * A route that answers with a file instead of JSON (a database export): its
 * handler returns array(200, result, 'stream' => array(file, type, run)), and
 * run($write) writes the file through $write and returns a result. Headers
 * go out with the first bytes, so an error before any (run held them back)
 * is still a JSON answer; one after that can only cut the file short, and
 * goes to log/debug_log. The status for the API log is returned.
 */
function api_send_stream(array $s, $what) {
	@set_time_limit(0);
	ignore_user_abort(true);      /* run() stops itself when the client goes away */
	while (ob_get_level())
		ob_end_clean();
	$sent = false;
	$write = function ($data) use ($s, &$sent) {
		if (connection_aborted())
			return false;
		if (!$sent) {
			header('Content-Type: '.$s['type']);
			header('Content-Disposition: attachment; filename="'.$s['file'].'"');
			header('X-Accel-Buffering: no');    /* nginx passes it on as it comes */
			$sent = true;
		}
		echo $data;
		flush();
		return !connection_aborted();
	};
	$r = call_user_func($s['run'], $write);
	if ($r['ok']) {
		if (!$sent)
			$write('');
		return 200;
	}
	if ($sent && connection_aborted())
		return 499;                   /* the client stopped reading */
	if ($sent) {
		error_log(date('Y-m-d H:i:s')." api $what: cut short: ".$r['error']."\n", 3, _PATH.'/log/debug_log');
		return 500;
	}
	api_respond(500, $r);
	return 500;
}

/* Why token $t may not use $route (an error response), or null. */
function api_route_refusal(array $route, array $t) {
	$scope = $t['account'] !== '' ? $t['account'] : null;
	if (!empty($route['feature']) && !feature_enabled($GLOBALS['ini'], $route['feature']))
		return api_error(404, "This part of the API is turned off on this server ({$route['feature']}=0 in etc/server-software.ini).");
	if (!in_array($route['perm'], api_token_scopes($t), true))
		return api_error(403, "This token does not have the '{$route['perm']}' permission.");
	if ($scope !== null && !empty($route['admin']))
		return api_error(403, "Only a token for the whole server can do this, not one for account $scope.");
	return null;
}

/* Call the route's handler: array(status, result). A handler returns a result
   array (200, or 400 when not ok) or array(status, result). */
function api_run(array $route, array $ctx) {
	try {
		$out = call_user_func($route['handler'], $ctx);
	} catch (Throwable $e) {
		error_log(date('Y-m-d H:i:s').' api '.($ctx['method'] ?? '').' '.($ctx['path'] ?? '').': '.$e->getMessage()
			.' at '.$e->getFile().':'.$e->getLine()."\n", 3, _PATH.'/log/debug_log');
		$out = api_error(500, 'Internal error. The details are in the panel\'s log/debug_log.');
	}
	if (isset($out['ok']))
		$out = array($out['ok'] ? 200 : 400, $out);
	return $out;
}
