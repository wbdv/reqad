<?
function log_debug($message) {
	global $_debug;
	if(isset($_debug) && $_debug == true) {
		$remote = isset($_SERVER["REMOTE_ADDR"]) ? $_SERVER["REMOTE_ADDR"] : 'cli';   // CLI has no REMOTE_ADDR
		#die(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$remote." ".$message."\n");
		error_log(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$remote." ".$message."\n", 3, __DIR__.'/../../log/debug_log');
		#echo(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$_SERVER["REMOTE_ADDR"]." ".$message."\n");
	}
}

/*
 * The system timezone, as systemd sees it (/etc/localtime symlink target).
 * PHP falls back to UTC when date.timezone is unset in php.ini, which made the
 * panel render server times in UTC while systemd (shutdown -r 23:00, cron, ...)
 * works in local time. Read the real zone instead of trusting php.ini.
 */
function system_timezone() {
	static $tz = null;
	if($tz !== null) return $tz;

	$link = @readlink('/etc/localtime');                 // ../usr/share/zoneinfo/Europe/Bucharest
	if($link !== false && ($p = strpos($link, 'zoneinfo/')) !== false) {
		$tz = substr($link, $p + strlen('zoneinfo/'));
	} elseif(is_readable('/etc/timezone')) {             // debian-ism, harmless to support
		$tz = trim(file_get_contents('/etc/timezone'));
	}

	if(!$tz || !in_array($tz, timezone_identifiers_list())) {
		$tz = date_default_timezone_get();
	}
	return $tz;
}

/*
 * Sanitize user input. Moved here from defines.php so it lives with the other
 * general helpers. The function_exists() guard avoids a fatal redeclaration on
 * installs whose un-migrated defines.php still defines clean().
 */
if (!function_exists('clean')) {
	function clean($s, $pattern = '/[^a-zA-Z0-9:_\. \'"\+\-\(\)@]*/') {
		return preg_replace($pattern, '', $s);
	}
}

/*
 * CSRF protection.
 *
 * Reqad has no sessions, so the token is derived deterministically from the
 * authenticated Nginx user plus a per-install secret stored once in the
 * settings table. Every rendered page can regenerate the same token, and any
 * cross-site request (which cannot read the secret) fails the check.
 *
 * Enforcement is centralised in index.php: every POST must carry a valid token
 * (in the `csrf` field for forms, or the X-CSRF-Token header for AJAX). GET
 * requests stay read-only, so they need no token. See templates/footer.php for
 * the client side that injects the token into forms and AJAX calls.
 */

/* Per-install random secret, lazily created in the settings table. */
function csrf_secret() {
	global $db;
	static $secret = null;
	if ($secret !== null)
		return $secret;

	$row = $db->querySingle('SELECT value FROM settings WHERE name="csrf-secret"', true);
	if (is_array($row) && isset($row['value']) && $row['value'] !== '') {
		$secret = $row['value'];
		return $secret;
	}

	$secret = bin2hex(random_bytes(32));
	$stmt = $db->prepare('INSERT INTO settings (name, value, updated_at)
	                      VALUES ("csrf-secret", :v, datetime("now"))');
	$stmt->bindValue(':v', $secret, SQLITE3_TEXT);
	$stmt->execute();
	return $secret;
}

/* The token for the current authenticated user. */
function csrf_token() {
	$user = isset($_SERVER['USER']) ? $_SERVER['USER'] : '';
	return hash('sha256', $user.'|'.csrf_secret());
}

/* Read the token supplied by the client (form field or AJAX header). */
function csrf_request_token() {
	if (isset($_POST['csrf']) && is_string($_POST['csrf']))
		return $_POST['csrf'];
	if (isset($_SERVER['HTTP_X_CSRF_TOKEN']))
		return $_SERVER['HTTP_X_CSRF_TOKEN'];
	return '';
}

/* Abort with 403 unless the request carries a valid token. */
function csrf_check() {
	if (!hash_equals(csrf_token(), csrf_request_token())) {
		http_response_code(403);
		header('Content-Type: text/plain; charset=utf-8');
		echo 'Forbidden: invalid or missing CSRF token.';
		exit;
	}
}

/*
 * Reusable flash message queue — Post/Redirect/Get (PRG) helper.
 *
 * Stored in a SEPARATE SQLite DB (db/messages.db) so it stays decoupled from the
 * main app schema/migrations. No sessions required (Reqad has none).
 *
 * Usage:
 *   - In an action module, after a POST side-effect:
 *         msg_redirect($url, $text, $type);   // 302 -> $url?msgid=<token>, then exit
 *   - In a template, to display any pending message once:
 *         msg_render();                        // reads $_GET['msgid'], shows + marks seen
 *
 * Types: success | error | info | warning
 *
 * The redirect carries an opaque random token in ?msgid=. The message is shown
 * exactly once: on display it is marked seen, so a later refresh (the msgid is
 * still in the URL) renders nothing.
 */

function msg_db() {
    static $mdb = null;
    if ($mdb === null) {
        $mdb = new SQLite3(_PATH.'/db/messages.db');
        $mdb->busyTimeout(3000);
        $mdb->exec('CREATE TABLE IF NOT EXISTS messages (
            token   TEXT PRIMARY KEY,
            type    TEXT NOT NULL DEFAULT "info",
            message TEXT NOT NULL,
            seen    INTEGER NOT NULL DEFAULT 0,
            created INTEGER NOT NULL
        )');
    }
    return $mdb;
}

/* Queue a message; returns its random token (used as ?msgid=). */
function msg_add($message, $type = 'info') {
    $mdb = msg_db();
    $now = time();
    /* prune so the queue never grows unbounded (no cron needed):
       drop anything older than 1h, and seen rows older than 60s */
    $mdb->exec('DELETE FROM messages WHERE created < '.($now - 3600).
               ' OR (seen = 1 AND created < '.($now - 60).')');

    if (!in_array($type, array('success', 'error', 'info', 'warning'), true))
        $type = 'info';
    $token = bin2hex(random_bytes(8));

    $stmt = $mdb->prepare('INSERT INTO messages (token, type, message, seen, created)
                           VALUES (:t, :ty, :m, 0, :c)');
    $stmt->bindValue(':t',  $token,   SQLITE3_TEXT);
    $stmt->bindValue(':ty', $type,    SQLITE3_TEXT);
    $stmt->bindValue(':m',  $message, SQLITE3_TEXT);
    $stmt->bindValue(':c',  $now,     SQLITE3_INTEGER);
    $stmt->execute();
    return $token;
}

/* Queue a message and 302-redirect to $url?msgid=<token>. Stops the request. */
function msg_redirect($url, $message, $type = 'info') {
    $token = msg_add($message, $type);
    $sep = (strpos($url, '?') !== false) ? '&' : '?';
    header('Location: '.$url.$sep.'msgid='.$token);
    exit;
}

/* Return the Tabler alert HTML for a pending (unseen) message token, marking it
   seen so it shows exactly once; '' if the token is invalid/missing/already seen.
   Used both by msg_render() (PRG) and the ajax-msg poll endpoint (async jobs that
   post their result to the queue, e.g. background Let's Encrypt issuance). */
function msg_pull_html($token) {
    /* opaque token guard — also blocks junk/tampered ids before any query */
    if (!preg_match('/^[0-9a-f]{16}$/', $token))
        return '';

    $mdb  = msg_db();
    $stmt = $mdb->prepare('SELECT type, message, seen FROM messages WHERE token = :t');
    $stmt->bindValue(':t', $token, SQLITE3_TEXT);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$row || (int)$row['seen'] === 1)
        return '';

    /* mark seen so a refresh/next poll won't re-show it */
    $up = $mdb->prepare('UPDATE messages SET seen = 1 WHERE token = :t');
    $up->bindValue(':t', $token, SQLITE3_TEXT);
    $up->execute();

    $text = $row['message'];
    if ($row['type'] === 'error')
        $text = preg_replace('/^Error:\s*/', '', $text);

    return msg_alert_html($row['type'], $text);
}

/* Render the pending message (if any), once. Returns true if something shown. */
function msg_render($token = null) {
    if ($token === null)
        $token = isset($_GET['msgid']) ? $_GET['msgid'] : '';
    $html = msg_pull_html($token);
    if ($html === '')
        return false;
    echo $html;
    return true;
}

/* Build the Tabler alert markup, mirroring the existing accounts.php blocks. */
function msg_alert_html($type, $text) {
    $map = array(
        'success' => array('alert-success', '#EFE', 'text-success', 'Success'),
        'error'   => array('alert-warning', '#FFE', 'text-danger',  'Error'),
        'warning' => array('alert-warning', '#FFE', 'text-warning', 'Warning'),
        'info'    => array('alert-info',    '#EEF', 'text-info',    'Info'),
    );
    $c = isset($map[$type]) ? $map[$type] : $map['info'];
    list($alertClass, $bg, $textClass, $heading) = $c;
    $safe = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

    return '
          <div class="alert '.$alertClass.'" role="alert" style="background:'.$bg.';">
            <div class="d-flex">
              <div style="width:55px;">'.msg_icon_svg($type, $textClass).'</div>
              <div>
                <h3 class="'.$textClass.'" style="margin-top:4px;margin-bottom:0">'.$heading.':</h3>
                <div class="'.$textClass.'">'.$safe.'</div>
              </div>
            </div>
          </div>';
}

/* Tabler SVG icon per type (reuses the icons already used in accounts.php). */
function msg_icon_svg($type, $textClass) {
    if ($type === 'success')
        $inner = '<path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="12" cy="12" r="9" /><path d="M9 12l2 2l4 -4" />';
    elseif ($type === 'info')
        $inner = '<path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="12" cy="12" r="9" /><path d="M12 8l.01 0" /><path d="M11 12l1 0l0 4l1 0" />';
    else /* error, warning */
        $inner = '<path stroke="none" d="M0 0h24v24H0z" fill="none"></path><path d="M12 9v2m0 4v.01"></path><path d="M5 19h14a2 2 0 0 0 1.84 -2.75l-7.1 -12.25a2 2 0 0 0 -3.5 0l-7.1 12.25a2 2 0 0 0 1.75 2.75"></path>';

    return '<svg xmlns="http://www.w3.org/2000/svg" class="icon mb-2 '.$textClass.' icon-md" width="48" height="48" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">'.$inner.'</svg>';
}

/*
 * Feature access control based on server-software.ini flags.
 *
 * The nav menu in header.php hides sections for disabled features, but that is
 * only cosmetic — the route/action/ajax handlers still run if the URL is hit
 * directly. These helpers let index.php and ajax.php block access server-side.
 */

// Is an ini feature flag enabled? Section flags default to OFF when absent
// (matching header.php's "isset && ==1" menu checks). root_access defaults to
// ON when absent (matching add_ssh_key.php / delete_ssh_key.php).
function feature_enabled($ini, $flag) {
	if ($flag === 'root_access')
		return !isset($ini[$flag]) ? true : ((int)$ini[$flag] === 1);
	return isset($ini[$flag]) && (int)$ini[$flag] === 1;
}

// ini flag(s) a route requires. A route is allowed only if ALL are enabled.
// terminal only needs terminal=1 — with root_access=0 the page still works, it
// just drops root from the user picker (see templates/terminal.php).
function route_required_features($route) {
	$map = array(
		'email-accounts'       => array('email'),
		'forwarders'           => array('email'),
		'autoresponders'       => array('email'),
		'check-email-settings' => array('email'),
		'email-stats'          => array('email'),
		'wp-toolkit'           => array('wptoolkit'),
		'backup'               => array('backup'),
		'backupdb'             => array('backupdb'),
		'terminal'             => array('terminal'),
		'transfer-tool'        => array('transfer'),
	);
	if (isset($map[$route]))
		return $map[$route];
	// Plugins declare their own gating feature in the manifest.
	$pl = isset($GLOBALS['plugins'][$route]) ? $GLOBALS['plugins'][$route] : null;
	if ($pl !== null && !empty($pl['feature']))
		return array($pl['feature']);
	return array();
}

// Returns the first disabled feature blocking $route, or null if allowed.
function route_blocked_feature($ini, $route) {
	foreach (route_required_features($route) as $flag)
		if (!feature_enabled($ini, $flag))
			return $flag;
	return null;
}

// ini flag an AJAX action requires, or null if the endpoint is always allowed.
function ajax_required_feature($action) {
	$map = array(
		'ajax-check-email-fixing' => 'email',
		'ajax-check-email'        => 'email',
		'ajax-email'              => 'email',
		'ajax-forward'            => 'email',
		'ajax-autoresponder'      => 'email',
		'ajax-wp-install'         => 'wptoolkit',
		'ajax-wp-scan'            => 'wptoolkit',
		'ajax-transfer-run'       => 'transfer',
		'ajax-transfer-check'     => 'transfer',
		'ajax-fm-list'            => 'filemanager',
		'ajax-fm-read'            => 'filemanager',
		'ajax-fm-save'            => 'filemanager',
		'ajax-fm-mkdir'           => 'filemanager',
		'ajax-fm-newfile'         => 'filemanager',
		'ajax-fm-rename'          => 'filemanager',
		'ajax-fm-chmod'           => 'filemanager',
		'ajax-fm-delete'          => 'filemanager',
		'ajax-fm-upload'          => 'filemanager',
		'ajax-fm-compress'        => 'filemanager',
		'ajax-fm-extract'         => 'filemanager',
		'ajax-fm-download'        => 'filemanager',
		'ajax-terminal-target'    => 'terminal',
	);
	if (isset($map[$action]))
		return $map[$action];
	// Plugins declare per-action gating in the 'ajax_features' manifest key.
	foreach ((isset($GLOBALS['plugins']) ? $GLOBALS['plugins'] : array()) as $pl)
		if (isset($pl['ajax_features'][$action]))
			return $pl['ajax_features'][$action];
	return null;
}

/* ---- Plugin system -------------------------------------------------------- */
/* Add-on modules (e.g. the premium WireGuard package) ship self-contained into
   public_html/plugins/<name>/ and own no core files. Each drops a plugin.php
   that calls plugin_register([...]); core auto-wires the route, sidebar item,
   POST actions and AJAX. See index.php (plugins_load + dispatch fallbacks),
   ajax.php (handler tail) and templates/header.php (nav loop). */

// Register one plugin manifest. Keyed by route so plugin_for_route() is O(1).
// Manifest keys: route, title, icon, feature (ini flag, optional), template,
// actions (POST action names), action_handler, ajax_handler, ajax_features.
function plugin_register($m) {
	if (!isset($GLOBALS['plugins']))
		$GLOBALS['plugins'] = array();
	$GLOBALS['plugins'][$m['route']] = $m;
}

// Discover and load every plugin. Called once from index.php at bootstrap,
// before ajax.php is included so ajax handlers are registered in time.
function plugins_load() {
	if (!isset($GLOBALS['plugins']))
		$GLOBALS['plugins'] = array();
	foreach (glob(_PATH.'/public_html/plugins/*/plugin.php') as $p)
		include_once($p);
}

// Manifest for a route, or null.
function plugin_for_route($route) {
	return isset($GLOBALS['plugins'][$route]) ? $GLOBALS['plugins'][$route] : null;
}

// Manifest owning a POST action, or null.
function plugin_for_action($action) {
	foreach ((isset($GLOBALS['plugins']) ? $GLOBALS['plugins'] : array()) as $pl)
		if (in_array($action, isset($pl['actions']) ? $pl['actions'] : array()))
			return $pl;
	return null;
}

// Appliance menu: a plugin can request a minimal sidebar by declaring a
// non-empty 'menu' in its manifest. When any plugin does, header.php hides the
// hosting-panel sections and keeps only Dashboard, add-on plugin items, and
// Reboot (the reqad-wireguard "WireGuard appliance" mode). The 'menu' value is
// reserved for finer per-item control later; presence is the trigger today.
function menu_minimal() {
	foreach ((isset($GLOBALS['plugins']) ? $GLOBALS['plugins'] : array()) as $pl)
		if (!empty($pl['menu']))
			return true;
	return false;
}

// Dashboard "Common Actions" tiles contributed by plugins. A manifest may
// declare 'dashboard' => array( array('label'=>, 'url'=>, 'icon'=>html), ... ).
// Feature-gated like the sidebar items. Returns a flat list of action items.
function plugin_dashboard_actions($ini) {
	$out = array();
	foreach ((isset($GLOBALS['plugins']) ? $GLOBALS['plugins'] : array()) as $pl) {
		if (empty($pl['dashboard'])) continue;
		if (!empty($pl['feature']) && !(isset($ini[$pl['feature']]) && $ini[$pl['feature']] == 1)) continue;
		foreach ($pl['dashboard'] as $a)
			$out[] = $a;
	}
	return $out;
}

/* ---- File Manager --------------------------------------------------------- */
/* Account-level file manager helpers. Every filesystem op runs as the account
   user (sudo -u <user>), all client paths are relative to the account home and
   jailed inside it. See templates/file-manager-modal.php + ajax-fm-* handlers. */

// Absolute home directory for an account user (convention: /home/<user>).
// $user must already be sanitized to [a-z0-9]; we sanitize again defensively.
function fm_home($user) {
	return '/home/' . preg_replace('/[^a-z0-9]/', '', (string)$user);
}

/* Resolve a client-supplied relative path to an absolute path guaranteed to sit
   inside the account home, or false on any escape/error. Uses `realpath -m` so it
   also works for not-yet-existing targets (mkdir / newfile / rename destination). */
function fm_resolve($user, $rel) {
	$home = fm_home($user);
	$rel  = (string)$rel;
	if (strpos($rel, "\0") !== false) return false;            // null-byte guard
	$rel = ltrim($rel, '/');                                    // always relative to home
	$abs = shell_exec('sudo -u ' . escapeshellarg($user) .
	       ' realpath -m ' . escapeshellarg($home . '/' . $rel) . ' 2>/dev/null');
	$abs = trim((string)$abs);
	if ($abs === '') return false;
	if ($abs !== $home && strpos($abs, $home . '/') !== 0) return false;   // jail
	return $abs;
}

/* List one directory as the account user. Returns an array of rows
   [{name,type:'dir'|'file',size,mtime,perms}] or false on error. One parseable
   `find` call: type / size / mtime / octal-perms / symbolic-perms / name. */
function fm_list($user, $absdir) {
	// %y type, %s size, %T.. mtime, %M symbolic-perms, %Y deref-type (follows
	// symlinks: d/f/... or N for broken), %f name
	$cmd = 'sudo -u ' . escapeshellarg($user) . ' find ' . escapeshellarg($absdir) .
	       ' -maxdepth 1 -mindepth 1 -printf ' .
	       escapeshellarg('%y\t%s\t%TY-%Tm-%Td %TH:%TM\t%M\t%Y\t%f\n') . ' 2>/dev/null';
	$out = shell_exec($cmd);
	if ($out === null) return array();
	$rows = array();
	foreach (explode("\n", rtrim($out, "\n")) as $line) {
		if ($line === '') continue;
		$p = explode("\t", $line, 6);
		if (count($p) < 6) continue;
		$is_link = ($p[0] === 'l');
		$deref   = $p[4];   // %Y: type the symlink points at (d/f/…), N/L/? if unresolved
		// effective type drives UI behaviour: a symlink to a dir navigates like a dir
		$type = $is_link ? (($deref === 'd') ? 'dir' : 'file')
		                 : (($p[0] === 'd') ? 'dir' : 'file');
		$row = array(
			'name'  => $p[5],
			'type'  => $type,
			'size'  => (int)$p[1],
			'mtime' => $p[2],
			'perms' => $p[3],   // symbolic (e.g. -rw-r--r-- or lrwxrwxrwx), matches the UI
		);
		if ($is_link) {
			$row['link'] = true;
			if ($deref === 'N' || $deref === 'L' || $deref === '?') $row['broken'] = true;
		}
		$rows[] = $row;
	}
	// folders first, then case-insensitive by name (mirrors the JS sample sort)
	usort($rows, function($a, $b) {
		if ($a['type'] !== $b['type']) return $a['type'] === 'dir' ? -1 : 1;
		return strcasecmp($a['name'], $b['name']);
	});
	return $rows;
}

// True if $abspath is a small-enough, text (non-binary) file editable in the UI.
function fm_is_text($user, $abspath, $max = 102400) {
	$stat = trim((string)shell_exec('sudo -u ' . escapeshellarg($user) .
	        ' stat -c %F/%s ' . escapeshellarg($abspath) . ' 2>/dev/null'));
	if ($stat === '' || strpos($stat, 'regular') !== 0) return false;
	$size = substr($stat, strrpos($stat, '/') + 1);
	if ((int)$size >= $max) return false;
	// `file` reports an empty file as "binary" (inode/x-empty), so short-circuit
	if ((int)$size === 0) return true;
	// `file --mime-encoding` reports "binary" for non-text content
	$enc = trim((string)shell_exec('sudo -u ' . escapeshellarg($user) .
	       ' file --mime-encoding -b ' . escapeshellarg($abspath) . ' 2>/dev/null'));
	return $enc !== '' && strpos($enc, 'binary') === false;
}

/* Confirm an account exists and return its sanitized user, or false. Mirrors the
   guard used by ajax-config-* (SELECT ... WHERE user=...). */
function fm_account_user($db, $raw_user) {
	$u = preg_replace('/[^a-z0-9]/', '', trim((string)$raw_user));
	if ($u === '') return false;
	$res  = $db->query('SELECT user FROM accounts WHERE user="' . $db->escapeString($u) . '"');
	$acct = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;
	return $acct ? $u : false;
}

/* ---- Alias domains -------------------------------------------------------- */

/* Return all alias rows for an account (accounts.id), www.* first then A→Z. */
function get_aliases($db, $account_id) {
	$rows = array();
	$account_id = (int)$account_id;
	$res = $db->query('SELECT id, alias, is_wildcard, ssl_status FROM aliases WHERE account_id='.$account_id.' ORDER BY (alias LIKE "www.%") DESC, alias ASC');
	if($res) while($r = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $r;
	return $rows;
}

/* Validate a proposed alias for an account. Returns '' if OK, else an error
   message. Shared by alias_add.php and the ajax-alias validator so the modal and
   the server agree. $acct_domain is the account's main domain. */
function alias_validation_error($db, $acct_domain, $alias) {
	$alias = strtolower(trim($alias));
	if($alias === '')
		return 'Please enter an alias domain.';

	$is_wild = (substr($alias, 0, 2) === '*.');
	$bare    = $is_wild ? substr($alias, 2) : $alias;

	if(!preg_match('/^([a-z0-9]([a-z0-9\-]*[a-z0-9])?\.)+[a-z]{2,}$/', $bare))
		return 'Please enter a valid domain name.';
	if(strpos($bare, '*') !== false)
		return 'Wildcards are only allowed as a leading "*." label.';
	if($alias === $acct_domain)
		return 'The alias cannot be the main domain.';
	if($alias === 'mail.'.$acct_domain)
		return 'mail.'.$acct_domain.' is managed automatically when email is enabled.';

	$r = $db->query('SELECT user FROM accounts WHERE domain="'.$db->escapeString($alias).'"');
	if($r && $r->fetchArray())
		return 'That domain is already a hosting account on this server.';

	$r = $db->query('SELECT id FROM aliases WHERE alias="'.$db->escapeString($alias).'"');
	if($r && $r->fetchArray())
		return 'That alias already exists.';

	return '';
}

/* Build the server_name token lists for a domain + its alias rows.
   mail.<domain> is added to both :80 and :443 when the account has email, so
   certbot can answer HTTP-01 for it and nginx serves it with the extended cert. */
function account_server_names($domain, $alias_rows, $has_email = false) {
	$names = $domain;
	foreach($alias_rows as $a) $names .= ' '.$a['alias'];
	$mail = $has_email ? ' mail.'.$domain : '';
	return array(
		'http'  => $names.$mail,
		'https' => $names.$mail,
	);
}

/* Rewrite an account's live vhost server_name/ServerAlias from the alias table,
   validate the server config, and reload. Returns '' on success, or an error
   string (the original file is restored on a failed config test). */
function apply_account_vhost_names($db, $domain, $is_apache) {
	$res = $db->query('SELECT id, has_email FROM accounts WHERE domain="'.$db->escapeString($domain).'"');
	$account = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;
	if(!$account) return 'Account not found for '.$domain;

	$aliases = get_aliases($db, $account['id']);
	$names   = account_server_names($domain, $aliases, !empty($account['has_email']));
	$file    = $is_apache ? '/etc/httpd/conf.d/'.$domain.'.conf' : '/etc/nginx/conf.d/'.$domain.'.conf';

	$content = shell_exec('sudo cat '.escapeshellarg($file).' 2>/dev/null');
	if($content === null || trim($content) === '')
		return 'vhost file not found: '.$file;

	if(!$is_apache) {
		/* Two server blocks: 1st server_name = :80 (http), 2nd = :443 (https). */
		$i = 0;
		$new = preg_replace_callback('/^([ \t]*)server_name[ \t]+[^;]*;/m', function($m) use (&$i, $names) {
			$i++;
			$val = ($i === 1) ? $names['http'] : $names['https'];
			return $m[1].'server_name '.$val.';';
		}, $content);
	} else {
		/* Apache: keep ServerName, rewrite ServerAlias to domain + aliases (+ mail). */
		$new = preg_replace('/^([ \t]*)ServerAlias[ \t]+.*$/m', '${1}ServerAlias    '.trim($names['http']), $content);
	}
	if($new === null || $new === $content)
		return '';   // nothing to change (or regex no-op) — leave the file alone

	$tmp = tempnam('/tmp', 'reqad');
	file_put_contents($tmp, $new);
	shell_exec('sudo cp '.escapeshellarg($tmp).' '.escapeshellarg($file));
	@unlink($tmp);

	$test = $is_apache ? shell_exec('sudo httpd -t 2>&1') : shell_exec('sudo nginx -t 2>&1');
	if(stripos($test, 'test is successful') === false && stripos($test, 'syntax ok') === false) {
		/* roll back to the original content */
		$bak = tempnam('/tmp', 'reqad');
		file_put_contents($bak, $content);
		shell_exec('sudo cp '.escapeshellarg($bak).' '.escapeshellarg($file));
		@unlink($bak);
		log_debug('[apply_account_vhost_names] config test failed for '.$domain.': '.trim($test));
		return 'Web server config test failed; change reverted.';
	}
	shell_exec('sudo systemctl reload '.($is_apache ? 'httpd' : 'nginx').' >> '.__DIR__.'/../../log/debug_log 2>&1');
	return '';
}

/* ---- Let's Encrypt cert extension (aliases) ------------------------------- */

/* Return the SAN (DNS:) names of an account's installed Let's Encrypt cert as a
   lowercased array, array() if the cert has none, or null if the account is not
   using Let's Encrypt (no live lineage). */
function get_cert_san($domain) {
	$f = '/etc/letsencrypt/live/'.$domain.'/cert.pem';
	$ok = trim(shell_exec('sudo test -f '.escapeshellarg($f).' && echo y'));
	if($ok !== 'y') return null;
	$out = shell_exec('sudo openssl x509 -in '.escapeshellarg($f).' -noout -ext subjectAltName 2>/dev/null');
	$names = array();
	if($out && preg_match_all('/DNS:([^,\s]+)/i', $out, $m))
		foreach($m[1] as $n) $names[] = strtolower(trim($n));
	return $names;
}

/* Build the desired cert SAN list for an account: main domain + aliases +
   mail.<domain> when email is enabled. By default wildcards are excluded (they
   need DNS-01); pass $include_wildcards=true for the DNS-01 path. */
function account_cert_names($db, $domain, $has_email, $include_wildcards = false) {
	$names = array($domain);
	$res = $db->query('SELECT id FROM accounts WHERE domain="'.$db->escapeString($domain).'"');
	$acc = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;
	if($acc)
		foreach(get_aliases($db, $acc['id']) as $a)
			if($include_wildcards || (int)$a['is_wildcard'] !== 1)
				$names[] = $a['alias'];
	if($has_email)
		$names[] = 'mail.'.$domain;
	return array_values(array_unique($names));
}

/* Drop names that a wildcard in the same set already covers — Let's Encrypt
   rejects an order that contains both "*.d" and a single-label "x.d". Keeps the
   wildcard itself, the apex (not matched by "*.d"), and deeper names like
   "a.b.d" (a wildcard only matches one label). */
function filter_wildcard_redundant($names) {
	$bases = array();
	foreach($names as $n)
		if(strpos($n, '*.') === 0) $bases[] = substr($n, 2);
	if(!$bases) return array_values(array_unique($names));

	$out = array();
	foreach($names as $n) {
		if(strpos($n, '*.') === 0) { $out[] = $n; continue; }   // keep wildcards
		$redundant = false;
		foreach($bases as $b) {
			$suf = '.'.$b;
			if(substr($n, -strlen($suf)) === $suf) {
				$label = substr($n, 0, strlen($n) - strlen($suf));
				if($label !== '' && strpos($label, '.') === false) { $redundant = true; break; }
			}
		}
		if(!$redundant) $out[] = $n;
	}
	return array_values(array_unique($out));
}

/* Is $name covered by the installed cert's SAN — either literally, or by a
   wildcard entry (*.d covers a single-label x.d)? Used for the per-alias badge. */
function alias_is_covered($name, $cert_san) {
	$name = strtolower($name);
	if(in_array($name, $cert_san, true)) return true;
	if(strpos($name, '*.') === 0) return false;   // a wildcard is only "covered" by an exact match
	foreach($cert_san as $san) {
		if(strpos($san, '*.') === 0) {
			$suf = substr($san, 1);   // ".d" from "*.d"
			if(substr($name, -strlen($suf)) === $suf) {
				$label = substr($name, 0, strlen($name) - strlen($suf));
				if($label !== '' && strpos($label, '.') === false) return true;
			}
		}
	}
	return false;
}

/* True when the account has at least one wildcard (*.domain) alias. */
function account_has_wildcard_alias($db, $domain) {
	$res = $db->query('SELECT a.id FROM aliases a JOIN accounts ac ON ac.id=a.account_id WHERE ac.domain="'.$db->escapeString($domain).'" AND a.is_wildcard=1 LIMIT 1');
	$row = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;
	return $row ? true : false;
}

/* Re-issue / expand an account's Let's Encrypt cert to cover its aliases (and
   mail.<domain> when email is on). Only acts when the account already uses Let's
   Encrypt. If the account has a wildcard alias, the whole cert is re-issued via
   DNS-01 (all SANs, no resolve filter — TXT-based); otherwise the standard
   HTTP-01 flow is used and names are filtered to those that resolve here. Runs
   certbot in the background. Returns: '' launched, 'not-le' (own/self-signed
   cert), 'none' (main domain does not resolve, HTTP-01), 'no-dns' (wildcard but
   no DNS provider configured). */
function reissue_letsencrypt_cert($db, $domain, $has_email, $token = '') {
	$ok = trim(shell_exec('sudo test -d /etc/letsencrypt/live/'.escapeshellarg($domain).' && echo y'));
	if($ok !== 'y') return 'not-le';

	/* Wildcard present -> DNS-01 re-issue of the full SAN set. */
	if(account_has_wildcard_alias($db, $domain)) {
		$prov = $db->querySingle('SELECT value FROM settings WHERE name="dns-provider"');
		if($prov === '' || $prov === null || $prov === 'none')
			return 'no-dns';
		$names = filter_wildcard_redundant(account_cert_names($db, $domain, $has_email, true));
		$list  = implode(',', $names);
		$tok   = ($token !== '') ? ' '.escapeshellarg($token) : '';
		shell_exec(__DIR__.'/../../scripts/reissue_letsencrypt_cert_dns.sh '.escapeshellarg($list).$tok.' >/dev/null 2>&1 &');
		log_debug('[reissue] DNS-01 '.$domain.' : '.$list);
		return '';
	}

	$ip = trim(shell_exec("/usr/sbin/ip address show | grep 'scope global' | grep 'inet ' | head -n 1 | awk {'print \$2'} | awk -F/ {'print \$1'}"));
	$resolvable = array();
	foreach(account_cert_names($db, $domain, $has_email) as $n) {
		$dig = trim(shell_exec('dig +short a '.escapeshellarg($n).' 2>/dev/null'));
		$lines = array_values(array_filter(array_map('trim', explode("\n", $dig))));
		$last  = end($lines);   // A record is the last line after any CNAME chain
		if($last === $ip) $resolvable[] = $n;
	}
	if(!in_array($domain, $resolvable, true)) return 'none';

	$list = implode(',', $resolvable);
	$tok  = ($token !== '') ? ' '.escapeshellarg($token) : '';
	shell_exec(__DIR__.'/../../scripts/reissue_letsencrypt_cert.sh '.escapeshellarg($list).$tok.' >/dev/null 2>&1 &');
	return '';
}

/* ---- Advanced config editor ---------------------------------------------- */

/* Resolve the editable config target for an account. $which is a whitelisted key
   ('nginx' = web-server vhost, 'fpm' = the php-fpm pool). Returns
   array(path, test, service, label, mode) or null for an unknown key. The php-fpm
   version/path is inferred from which php-fpm.d dir holds the account's pool. */
function account_config_target($ini, $domain, $which) {
	$is_apache = (substr(trim($ini['template'] ?? ''), 0, 7) == 'apache_');

	if($which === 'nginx') {
		return array(
			'path'    => $is_apache ? '/etc/httpd/conf.d/'.$domain.'.conf' : '/etc/nginx/conf.d/'.$domain.'.conf',
			'test'    => $is_apache ? 'sudo httpd -t 2>&1' : 'sudo nginx -t 2>&1',
			'bin'     => $is_apache ? 'httpd' : 'nginx',
			'service' => $is_apache ? 'httpd' : 'nginx',
			'label'   => $is_apache ? 'Apache vhost' : 'nginx vhost',
			'mode'    => 'nginx',
		);
	}

	if($which === 'fpm') {
		$php_versions = array_map('trim', explode(',', $ini['php_versions']));
		$ver = $ini['php'];
		foreach($php_versions as $pv) {
			$s = str_replace('.', '', $pv);
			if(is_file('/etc/opt/remi/php'.$s.'/php-fpm.d/'.$domain.'.conf')) { $ver = $pv; break; }
		}
		$short = str_replace('.', '', $ver);
		if($ver === $ini['php']) {
			$path = '/etc/php-fpm.d/'.$domain.'.conf';
			$bin  = '/usr/sbin/php-fpm';
			$svc  = 'php-fpm.service';
		} else {
			$path = '/etc/opt/remi/php'.$short.'/php-fpm.d/'.$domain.'.conf';
			$bin  = '/opt/remi/php'.$short.'/root/usr/sbin/php-fpm';
			$svc  = 'php'.$short.'-php-fpm.service';
		}
		return array(
			'path'    => $path,
			'test'    => 'sudo '.$bin.' -t 2>&1',
			'bin'     => $bin,
			'service' => $svc,
			'label'   => 'PHP-FPM pool (PHP '.$ver.')',
			'mode'    => 'properties',
		);
	}

	return null;
}

/* Validate proposed config content WITHOUT touching the live file, by running the
   server's own test against a throwaway wrapper that includes the content:
     - nginx:   events{} http{ include <content>; }  ->  nginx -t -c wrapper
     - php-fpm: [global] include=<content>           ->  php-fpm -y wrapper -t
   Returns '' when valid, or a cleaned error message (temp paths/timestamps stripped
   so it reads like the real file). Apache falls back to '' (real save validates). */
function validate_config_content($ini, $domain, $which, $content) {
	$t = account_config_target($ini, $domain, $which);
	if(!$t) return 'Unknown config file.';

	$content = str_replace("\r\n", "\n", $content);
	if(substr($content, -1) !== "\n") $content .= "\n";

	$dir = sys_get_temp_dir().'/reqadcfg_'.bin2hex(random_bytes(6));
	if(!@mkdir($dir, 0700)) return '';   // can't isolate — let the real save validate
	$out = '';

	if($which === 'nginx' && $t['bin'] === 'nginx') {
		file_put_contents($dir.'/vhost.conf', $content);
		file_put_contents($dir.'/nginx.conf', "events {}\nhttp {\n    include ".$dir."/vhost.conf;\n}\n");
		$out = shell_exec('sudo nginx -t -c '.escapeshellarg($dir.'/nginx.conf').' 2>&1');
	} elseif($which === 'fpm') {
		file_put_contents($dir.'/pool.conf', $content);
		file_put_contents($dir.'/fpm.conf', "[global]\ninclude=".$dir."/pool.conf\n");
		$out = shell_exec('sudo '.escapeshellarg($t['bin']).' -y '.escapeshellarg($dir.'/fpm.conf').' -t 2>&1');
	} else {
		shell_exec('rm -rf '.escapeshellarg($dir));
		return '';   // apache / unknown: skip isolated preflight
	}

	shell_exec('rm -rf '.escapeshellarg($dir));

	if(stripos((string)$out, 'test is successful') !== false || stripos((string)$out, 'syntax ok') !== false)
		return '';

	/* clean the message: drop temp paths, php-fpm timestamps; use the real filename */
	$err = (string)$out;
	$err = str_replace($dir.'/', '', $err);
	$err = preg_replace('/^\[\d{2}-\w{3}-\d{4} \d{2}:\d{2}:\d{2}\]\s*/m', '', $err);
	$err = str_replace(array('vhost.conf', 'pool.conf'), basename($t['path']), $err);
	$err = preg_replace('#(nginx\.conf|fpm\.conf)#', basename($t['path']), $err);
	return trim($err) !== '' ? trim($err) : 'Configuration test failed.';
}

/* ---- Advanced Config version history ------------------------------------
   Backups live panel-side (readable without sudo) under
   backup/config/<user>/<basename>.<Ymd-His>. Only the last 5 per file are
   kept. Used by config_save.php, config_restore.php and ajax-config-versions. */

function config_backup_dir($user) {
	return _PATH.'/backup/config/'.preg_replace('/[^a-z0-9]/', '', (string)$user);
}

/* Backup filename prefix for a config file. The nginx vhost and php-fpm pool can
   share a basename (e.g. dt.ro.conf), so $which (nginx|fpm) keeps their histories
   in separate namespaces. */
function config_backup_prefix($which, $path) {
	return $which.'.'.basename($path);
}

/* Save $content as a new backup of $path (of type $which) for $user, prune to 5. */
function save_config_backup($user, $which, $path, $content) {
	$dir = config_backup_dir($user);
	if(!is_dir($dir)) @mkdir($dir, 0700, true);
	$base = config_backup_prefix($which, $path);
	$file = $dir.'/'.$base.'.'.date('Ymd-His');
	file_put_contents($file, $content);
	$all = glob($dir.'/'.$base.'.*');
	if($all && count($all) > 5) {
		sort($all);                                   // oldest first (Ymd-His sorts lexically)
		foreach(array_slice($all, 0, count($all) - 5) as $old) @unlink($old);
	}
	return $file;
}

/* Human-friendly time + size for a backup timestamp string 'Ymd-His'. */
function config_backup_when($ts) {
	$t = @strtotime(preg_replace('/^(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})$/', '$1-$2-$3 $4:$5:$6', $ts));
	if(!$t) return $ts;
	if(date('Y-m-d', $t) === date('Y-m-d'))                     return 'Today, '.date('H:i', $t);
	if(date('Y-m-d', $t) === date('Y-m-d', strtotime('-1 day'))) return 'Yesterday, '.date('H:i', $t);
	return date('M j, H:i', $t);
}
function config_human_size($bytes) {
	$bytes = (int)$bytes;
	if($bytes < 1024) return $bytes.' B';
	return round($bytes / 1024, 1).' KB';
}

/* List the saved backups for a config file of type $which, newest first. */
function list_config_backups($user, $which, $path) {
	$dir  = config_backup_dir($user);
	$base = config_backup_prefix($which, $path);
	$all  = glob($dir.'/'.$base.'.*');
	if(!$all) return array();
	rsort($all);                                      // newest first
	$out = array();
	foreach($all as $f) {
		$ts = substr($f, strrpos($f, '.') + 1);
		$out[] = array(
			'id'   => basename($f),
			'ts'   => $ts,
			'when' => config_backup_when($ts),
			'size' => config_human_size(@filesize($f)),
		);
	}
	return $out;
}

/* Read one backup's content, guarding the id to this file's type+basename. */
function read_config_backup($user, $which, $path, $version) {
	$base = config_backup_prefix($which, $path);
	$id   = basename((string)$version);              // strip any path components
	if(strpos($id, $base.'.') !== 0) return null;    // must belong to this file+type
	$file = config_backup_dir($user).'/'.$id;
	if(!is_file($file)) return null;
	return (string)file_get_contents($file);
}

/* Validate, back up the current version, write, and reload — the single write
   path shared by config_save.php (editor) and config_restore.php (history).
   Returns array('error'=>..., 'success'=>...). */
function apply_account_config($ini, $user, $domain, $which, $content) {
	$t = account_config_target($ini, $domain, $which);
	if(!$t) return array('error' => 'Unknown config file.', 'success' => '');

	$orig = shell_exec('sudo cat '.escapeshellarg($t['path']).' 2>/dev/null');
	if($orig === null || $orig === '')
		return array('error' => 'Config file not found: '.$t['path'], 'success' => '');

	$content = str_replace("\r\n", "\n", $content);
	if(substr($content, -1) !== "\n") $content .= "\n";

	/* isolated pre-check so we never write invalid content to the live file */
	$verr = validate_config_content($ini, $domain, $which, $content);
	if($verr !== '')
		return array('error' => 'Validation failed, not saved: '.$verr, 'success' => '');

	/* keep the version we're replacing, then write the new one */
	save_config_backup($user, $which, $t['path'], $orig);
	$tmp = tempnam('/tmp', 'reqad');
	file_put_contents($tmp, $content);
	shell_exec('sudo cp '.escapeshellarg($tmp).' '.escapeshellarg($t['path']));
	@unlink($tmp);

	/* defensive re-test against the running server (apache has no isolated check) */
	$out = shell_exec($t['test']);
	if(stripos((string)$out, 'test is successful') === false && stripos((string)$out, 'syntax ok') === false) {
		$bak = tempnam('/tmp', 'reqad');
		file_put_contents($bak, $orig);
		shell_exec('sudo cp '.escapeshellarg($bak).' '.escapeshellarg($t['path']));
		@unlink($bak);
		return array('error' => 'Validation failed on live config, reverted: '.trim(preg_replace('/\s+/', ' ', (string)$out)), 'success' => '');
	}

	shell_exec('sudo systemctl reload '.$t['service'].' >> '._PATH.'/log/debug_log 2>&1');
	log_debug('[config] '.$which.' '.$domain.' saved + reloaded '.$t['service']);
	return array('error' => '', 'success' => $t['label'].' saved and reloaded.');
}

/**
 * State of wetty, the browser terminal behind the Terminal page.
 *
 * wetty is a Node app, not an RPM dependency, so it can legitimately be absent
 * or stopped on a working server. The Terminal page has no way to tell — the
 * iframe just renders blank — so check here and report something actionable.
 *
 * Returns:
 *   ok      bool    true only when wetty is running and accepting connections
 *   state   string  ok | no-unit | no-build | stopped | unreachable
 *   title   string  short headline for the alert
 *   message string  what is wrong, in plain language
 *   hint    string  the command that fixes it ('' when there is none)
 *   detail  string  service output, when there is any worth showing
 */
function wetty_status() {
	$installer = _PATH.'/scripts/install/install-wetty.sh';
	$out = array('ok' => false, 'state' => '', 'title' => '', 'message' => '', 'hint' => '', 'detail' => '');

	// 1. Is there a service at all?
	if(!file_exists('/usr/lib/systemd/system/wetty.service') && !file_exists('/etc/systemd/system/wetty.service')) {
		$out['state']   = 'no-unit';
		$out['title']   = 'The terminal is not installed';
		$out['message'] = 'wetty, the browser terminal this page embeds, is not set up on this server.';
		$out['hint']    = $installer;
		return $out;
	}

	// 2. Running? Ask systemd before looking at the checkout: wetty is built and
	//    run out of /root, which the panel user cannot even traverse on a stock
	//    Rocky box (/root is 0550), so any stat of the build from here answers
	//    "missing" on a perfectly healthy install. A running unit is proof
	//    enough that there is something to run.
	$active = trim((string)shell_exec('sudo systemctl is-active wetty 2>&1'));
	if($active !== 'active') {
		// Only now is the checkout worth inspecting — and only through sudo, for
		// the reason above. The build sits next to the unit's WorkingDirectory
		// (ExecStart runs ./build/main.js relative to it).
		$dir = trim((string)shell_exec('sudo systemctl show wetty -p WorkingDirectory --value 2>/dev/null'));
		if($dir == '') $dir = '/root/wetty';
		$built = trim((string)shell_exec('sudo test -f '.escapeshellarg($dir.'/build/main.js').' && echo 1'));

		if($built !== '1') {
			$out['state']   = 'no-build';
			$out['title']   = 'The terminal is not built';
			$out['message'] = 'wetty is present but has not been built, so the service cannot start.';
			$out['hint']    = $installer;
			return $out;
		}

		$out['state']   = 'stopped';
		$out['title']   = 'The terminal service is not running';
		$out['message'] = 'wetty.service reports "'.$active.'".';
		$out['hint']    = 'systemctl start wetty';
		$out['detail']  = trim((string)shell_exec('sudo systemctl status wetty --no-pager -n 8 2>&1'));
		return $out;
	}

	// 3. Active but actually accepting connections? A crash-looping or
	//    misconfigured wetty can report active while nothing is listening.
	// Read the merged ExecStart, not `systemctl cat` — cat prints the base unit
	// and every drop-in, so a drop-in that overrides the port would be missed.
	$port = 3000;
	if(preg_match('/--port\s+(\d+)/', (string)shell_exec('sudo systemctl show wetty -p ExecStart --value 2>/dev/null'), $m))
		$port = (int)$m[1];

	$sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 2);
	if(!$sock) {
		$out['state']   = 'unreachable';
		$out['title']   = 'The terminal is not responding';
		$out['message'] = 'wetty.service is running but nothing is listening on 127.0.0.1:'.$port.'.';
		$out['hint']    = 'systemctl restart wetty';
		$out['detail']  = trim((string)shell_exec('sudo systemctl status wetty --no-pager -n 8 2>&1'));
		return $out;
	}
	fclose($sock);

	$out['ok']    = true;
	$out['state'] = 'ok';
	return $out;
}

/* ==========================================================================
   WP Toolkit — per-site "Manage" options
   --------------------------------------------------------------------------
   Two live, toggleable options, driven from the Manage modal on wp-toolkit:
     1. Nginx FastCGI microcache (+ ngx_cache_purge purge endpoint + the
        matching WordPress purge plugin)
     2. Disable WP-Cron (DISABLE_WP_CRON constant + a real every-2-minutes system cron)

   State is NOT stored in the DB — it is derived from the live system on every
   status read (config markers, wp-config constant, crontab line), so the modal
   always mirrors reality even if the admin changed things by hand.
   ========================================================================== */

/* The server's primary public IPv4 — used to allow the WP purge plugin (which
   calls /purge over the public hostname) through the purge location's ACL. */
function reqad_server_ip() {
	return trim(shell_exec("/usr/sbin/ip address show | grep 'scope global' | grep 'inet ' | head -n 1 | awk {'print \$2'} | awk -F/ {'print \$1'}"));
}

/* Is the panel serving sites with nginx (vs apache)? Nginx cache only applies then. */
function wp_is_nginx($ini) {
	return substr(trim($ini['template'] ?? ''), 0, 7) != 'apache_';
}

/* Sanitised zone/prefix for an account — used for the cache zone, cache dir and
   the map variable names. Account users are already [a-z][a-z0-9]{1,11}; this is
   defence in depth so nothing untrusted reaches an nginx identifier. */
function wp_cache_zone($user) {
	return preg_replace('/[^a-z0-9]/', '', strtolower((string)$user));
}

/* Absolute docroot for a tracked WordPress install (honours the subdir `path`). */
function wp_site_docroot($user, $path = '') {
	$base = '/home/'.$user.'/public_html';
	$path = trim((string)$path, '/');
	return $path === '' ? $base : $base.'/'.$path;
}

/* nginx vhost path for a domain (only meaningful when wp_is_nginx()). */
function wp_nginx_conf_path($domain) {
	return '/etc/nginx/conf.d/'.$domain.'.conf';
}

/* --- Nginx FastCGI cache ------------------------------------------------- */

/* Build the three marker-delimited config fragments for a site.
   Returns array('http' => ..., 'purge' => ..., 'fcgi' => ...). Uses nowdoc so
   nginx '$' variables survive verbatim; only %ZONE% / %IP% are substituted. */
function wp_nginx_cache_fragments($zone, $ip) {
	$http = <<<'EOT'
# BEGIN reqad-nginx-cache
# FastCGI microcache — managed by the Reqad WP Toolkit "Manage" panel.
# Do NOT edit between the BEGIN/END markers; toggling the option rewrites them.
fastcgi_cache_path /var/cache/nginx/fcgi-%ZONE% levels=1:2 keys_zone=%ZONE%:100m
                   inactive=12h max_size=512m;

map $http_cookie $%ZONE%_nc_cookie {
    default                     0;
    ~*wordpress_logged_in       1;
    ~*wp-postpass               1;
    ~*comment_author            1;
    ~*wordpress_no_cache        1;
    ~*woocommerce_items_in_cart 1;
    ~*woocommerce_cart_hash     1;
    ~*wp_woocommerce_session    1;
}

map $request_uri $%ZONE%_nc_uri {
    default                          0;
    ~*^/wp-admin                     1;
    ~*^/wp-login\.php                1;
    ~*^/wp-cron\.php                 1;
    ~*^/xmlrpc\.php                  1;
    ~*^/wp-json                      1;
    ~*^/feed                         1;
    ~*^/sitemap.*\.xml               1;
    ~*^/purge                        1;
    ~*^/(cart|checkout|my-account)   1;
    ~*^/wc-api                       1;
    ~*^/store-api                    1;
}

map $request_method $%ZONE%_nc_method {
    default 1;
    GET     0;
    HEAD    0;
}

map $query_string $%ZONE%_nc_query {
    default 1;
    ""      0;
}

map "$%ZONE%_nc_cookie$%ZONE%_nc_uri$%ZONE%_nc_method$%ZONE%_nc_query" $%ZONE%_skip_cache {
    default 1;
    "0000"  0;
}
# END reqad-nginx-cache

EOT;

	$purge = <<<'EOT'
	# BEGIN reqad-nginx-cache-purge
	add_header X-FastCGI-Cache $upstream_cache_status always;
	add_header X-Cache-Skip    $%ZONE%_skip_cache      always;

	# Purge endpoint for the WordPress reqad-cache-purger plugin. The plugin calls
	# it over the public hostname, so the request arrives from this server's own
	# public IP — hence the allow below. The key must match fastcgi_cache_key.
	location ~ ^/purge(/.*) {
	    allow 127.0.0.1;
	    allow ::1;
	    allow %IP%;
	    deny  all;

	    cache_purge_response_type json;
	    fastcgi_cache_purge %ZONE% "$scheme$host$1";
	}
	# END reqad-nginx-cache-purge

EOT;

	$fcgi = <<<'EOT'
            # BEGIN reqad-nginx-cache-fcgi
            # $request_uri (not $uri): try_files rewrites $uri to /index.php, which
            # would collapse every page onto one cache entry. Query strings are never
            # cached (see the %ZONE%_nc_query map) so the key stays a clean path.
            open_file_cache             off;
            fastcgi_cache               %ZONE%;
            fastcgi_cache_key           "$scheme$host$request_uri";
            fastcgi_cache_methods       GET;
            fastcgi_cache_valid         200 301 302 12h;
            fastcgi_cache_valid         404 1m;
            fastcgi_cache_lock          on;
            fastcgi_cache_revalidate    on;
            fastcgi_cache_use_stale     error timeout updating invalid_header http_500 http_503;
            fastcgi_cache_background_update on;
            fastcgi_ignore_headers      Cache-Control Expires;
            fastcgi_cache_bypass        $%ZONE%_skip_cache;
            fastcgi_no_cache            $%ZONE%_skip_cache;
            # END reqad-nginx-cache-fcgi

EOT;

	$sub = function($s) use ($zone, $ip) {
		return str_replace(array('%ZONE%', '%IP%'), array($zone, $ip), $s);
	};
	return array('http' => $sub($http), 'purge' => $sub($purge), 'fcgi' => $sub($fcgi));
}

/* Read a vhost via sudo (root-owned files are common in conf.d). */
function wp_read_conf($path) {
	return (string)shell_exec('sudo cat '.escapeshellarg($path).' 2>/dev/null');
}

/* Atomically replace a vhost's content via a temp file + sudo cp. */
function wp_write_conf($path, $content) {
	$tmp = tempnam('/tmp', 'reqadwp');
	file_put_contents($tmp, $content);
	shell_exec('sudo cp '.escapeshellarg($tmp).' '.escapeshellarg($path));
	@unlink($tmp);
}

/* Live state of the nginx cache option for a domain: 'on' | 'off' | 'na'. */
function wp_nginx_cache_state($ini, $domain) {
	if(!wp_is_nginx($ini)) return 'na';
	$path = wp_nginx_conf_path($domain);
	$c = wp_read_conf($path);
	if($c === '') return 'off';
	return (strpos($c, '# BEGIN reqad-nginx-cache') !== false) ? 'on' : 'off';
}

/* Canonical plugin slug/folder for the WordPress purge plugin. */
define('WP_CACHE_PURGER_SLUG', 'reqad-cache-purger');

/* Resolve the download URL of the latest reqad-cache-purger GitHub release.
   Returns '' when the API cannot be reached or has no .zip asset. The release
   asset (unlike a source archive) unpacks to a clean 'reqad-cache-purger/'
   folder, so wp-cli derives the canonical plugin slug from it. */
function wp_cache_purger_release_url() {
	$json = (string)shell_exec('curl -sfL --max-time 15 -H '.escapeshellarg('Accept: application/vnd.github+json')
		.' https://api.github.com/repos/wbdv/'.WP_CACHE_PURGER_SLUG.'/releases/latest 2>/dev/null');
	$d = @json_decode($json, true);
	if(empty($d['assets']) || !is_array($d['assets'])) return '';
	foreach($d['assets'] as $a) {
		$u = $a['browser_download_url'] ?? '';
		if($u !== '' && strtolower(substr($u, -4)) === '.zip') return $u;
	}
	return '';
}

/* Install (if missing) and activate the reqad-cache-purger WordPress plugin.
   It is not on wordpress.org, so wp-cli installs it straight from the latest
   GitHub *release* zip. Returns a log string; safe to call repeatedly. */
function wp_install_cache_purger($user, $docroot) {
	$slug   = WP_CACHE_PURGER_SLUG;
	$asuser = 'sudo -u '.escapeshellarg($user).' ';
	$log    = '';

	$is = trim((string)shell_exec($asuser.'/usr/local/bin/wp plugin is-installed '.$slug
		.' --path='.escapeshellarg($docroot).' 2>/dev/null; echo $?'));

	if(substr($is, -1) !== '0') {
		$url = wp_cache_purger_release_url();
		if($url === '')
			return "Error: could not determine the latest ".$slug." release from GitHub.\n";
		$log .= "Installing ".$slug." from ".$url."\n";
		// --activate installs and enables in one step.
		$log .= (string)shell_exec($asuser.'/usr/local/bin/wp plugin install '.escapeshellarg($url)
			.' --force --activate --path='.escapeshellarg($docroot).' 2>&1');
	} else {
		$log .= $slug." already installed.\n";
		$log .= (string)shell_exec($asuser.'/usr/local/bin/wp plugin activate '.$slug
			.' --path='.escapeshellarg($docroot).' 2>&1');
	}
	return $log;
}

/* Enable the nginx FastCGI cache for a site: inject the three fragments into the
   vhost, create the cache dir, install+activate the purge plugin, test & reload.
   Reverts the vhost on a failed `nginx -t`. Returns array(error, success, log). */
function wp_nginx_cache_enable($ini, $user, $domain, $docroot) {
	$log = '';
	if(!wp_is_nginx($ini))
		return array('error' => 'This server does not use nginx, so the FastCGI cache is not available.', 'success' => '', 'log' => '');

	$path = wp_nginx_conf_path($domain);
	$orig = wp_read_conf($path);
	if(trim($orig) === '')
		return array('error' => 'nginx vhost not found: '.$path, 'success' => '', 'log' => '');
	if(strpos($orig, '# BEGIN reqad-nginx-cache') !== false)
		return array('error' => '', 'success' => 'Nginx cache is already enabled.', 'log' => '');

	$zone = wp_cache_zone($user);
	$ip   = reqad_server_ip();
	$frag = wp_nginx_cache_fragments($zone, $ip);

	// 1) http-context block at the very top of the file (conf.d is http context).
	$new = $frag['http']."\n".$orig;

	// 2) purge block: after the server-level `index ...;` line (443 vhost only).
	$lines = explode("\n", $new);
	$out = array(); $did_purge = false;
	foreach($lines as $ln) {
		$out[] = $ln;
		if(!$did_purge && preg_match('/^\s*index\s+/', $ln)) {
			$out[] = rtrim($frag['purge'], "\n");
			$did_purge = true;
		}
	}
	if(!$did_purge)
		return array('error' => 'Could not locate an `index` directive in the vhost to anchor the purge block.', 'success' => '', 'log' => '');
	$new = implode("\n", $out);

	// 3) fcgi cache directives: right after the `fastcgi_pass php-fpm-<user>;` line.
	$lines = explode("\n", $new);
	$out = array(); $did_fcgi = false;
	foreach($lines as $ln) {
		$out[] = $ln;
		if(!$did_fcgi && strpos($ln, 'fastcgi_pass') !== false && strpos($ln, 'php-fpm') !== false) {
			$out[] = rtrim($frag['fcgi'], "\n");
			$did_fcgi = true;
		}
	}
	if(!$did_fcgi)
		return array('error' => 'Could not locate the `fastcgi_pass` line in the vhost to anchor the cache directives.', 'success' => '', 'log' => '');
	$new = implode("\n", $out);

	// Cache dir (nginx creates sublevels itself, but needs the root to exist+own).
	shell_exec('sudo mkdir -p /var/cache/nginx/fcgi-'.escapeshellarg($zone));
	shell_exec('sudo chown nginx:nginx /var/cache/nginx/fcgi-'.$zone.' 2>/dev/null');

	// Snapshot the pre-change vhost into the Advanced Config version history so the
	// admin can roll back the cache injection from that panel's "Version history".
	save_config_backup($user, 'nginx', $path, $orig);

	// Write, test, revert on failure.
	wp_write_conf($path, $new);
	$test = (string)shell_exec('sudo nginx -t 2>&1');
	$log .= $test;
	if(stripos($test, 'test is successful') === false) {
		wp_write_conf($path, $orig);   // revert
		return array('error' => 'nginx config test failed; reverted. '.trim(preg_replace('/\s+/', ' ', $test)), 'success' => '', 'log' => $log);
	}
	shell_exec('sudo systemctl reload nginx 2>&1');

	// Install + activate the WordPress purge plugin (idempotent). Not on
	// wordpress.org, so wp-cli pulls the latest GitHub release zip.
	$log .= "\n".wp_install_cache_purger($user, $docroot);

	log_debug('[wp-manage] nginx-cache enabled for '.$domain.' ('.$user.')');
	return array('error' => '', 'success' => 'Nginx FastCGI cache enabled and nginx reloaded.', 'log' => $log);
}

/* Disable the nginx cache: strip the three marker blocks, test & reload, empty
   the cache dir and deactivate the purge plugin. Returns array(error, success, log). */
function wp_nginx_cache_disable($ini, $user, $domain, $docroot) {
	$log = '';
	if(!wp_is_nginx($ini))
		return array('error' => 'This server does not use nginx.', 'success' => '', 'log' => '');

	$path = wp_nginx_conf_path($domain);
	$orig = wp_read_conf($path);
	if(trim($orig) === '')
		return array('error' => 'nginx vhost not found: '.$path, 'success' => '', 'log' => '');
	if(strpos($orig, '# BEGIN reqad-nginx-cache') === false)
		return array('error' => '', 'success' => 'Nginx cache is already disabled.', 'log' => '');

	// Remove each marker block (and the blank line that follows it, if any).
	$new = $orig;
	foreach(array('reqad-nginx-cache-purge', 'reqad-nginx-cache-fcgi', 'reqad-nginx-cache') as $mk) {
		$new = preg_replace('/[ \t]*# BEGIN '.$mk.'\b.*?# END '.$mk.'[^\n]*\n?\n?/s', '', $new);
	}

	// Snapshot the pre-change (cached) vhost into the Advanced Config version
	// history before stripping the cache, so it can be restored from that panel.
	save_config_backup($user, 'nginx', $path, $orig);

	wp_write_conf($path, $new);
	$test = (string)shell_exec('sudo nginx -t 2>&1');
	$log .= $test;
	if(stripos($test, 'test is successful') === false) {
		wp_write_conf($path, $orig);   // revert
		return array('error' => 'nginx config test failed after removal; reverted. '.trim(preg_replace('/\s+/', ' ', $test)), 'success' => '', 'log' => $log);
	}
	shell_exec('sudo systemctl reload nginx 2>&1');

	// Clear the cache store and deactivate the plugin (leave it installed).
	$zone = wp_cache_zone($user);
	if($zone !== '')
		shell_exec('sudo rm -rf /var/cache/nginx/fcgi-'.escapeshellarg($zone).'/* 2>/dev/null');
	// Deactivate the canonical slug, plus any branch-suffixed copy left behind by
	// an install that went through the GitHub archive (reqad-cache-purger-main).
	foreach(array(WP_CACHE_PURGER_SLUG, WP_CACHE_PURGER_SLUG.'-main', WP_CACHE_PURGER_SLUG.'-master') as $pslug) {
		$is = trim((string)shell_exec('sudo -u '.escapeshellarg($user).' /usr/local/bin/wp plugin is-installed '.$pslug.' --path='.escapeshellarg($docroot).' 2>/dev/null; echo $?'));
		if(substr($is, -1) === '0')
			$log .= "\n".(string)shell_exec('sudo -u '.escapeshellarg($user).' /usr/local/bin/wp plugin deactivate '.$pslug.' --path='.escapeshellarg($docroot).' 2>&1');
	}

	log_debug('[wp-manage] nginx-cache disabled for '.$domain.' ('.$user.')');
	return array('error' => '', 'success' => 'Nginx FastCGI cache disabled and nginx reloaded.', 'log' => $log);
}

/* --- Disable WP-Cron ----------------------------------------------------- */

/* Marker appended to the crontab line so we can find/remove exactly our entry. */
function wp_cron_marker($user) {
	return '# reqad-wpcron-'.wp_cache_zone($user);
}

/* Live state of the "Disable WP-Cron" option: 'on' | 'off'. Considered ON only
   when BOTH the DISABLE_WP_CRON constant is true AND our system cron is present. */
function wp_wpcron_state($user, $docroot) {
	$has = trim((string)shell_exec('sudo -u '.escapeshellarg($user).' /usr/local/bin/wp config get DISABLE_WP_CRON --path='.escapeshellarg($docroot).' 2>/dev/null'));
	$const_on = in_array(strtolower($has), array('1', 'true'), true);
	$cron_on  = ((int)trim((string)shell_exec('sudo grep -cF '.escapeshellarg(wp_cron_marker($user)).' /var/spool/cron/'.escapeshellarg($user).' 2>/dev/null'))) > 0;
	return ($const_on && $cron_on) ? 'on' : 'off';
}

/* Enable "Disable WP-Cron": set the constant and install an every-2-minutes system
   cron that runs due events via wp-cli. Both steps are idempotent. */
function wp_wpcron_enable($user, $docroot) {
	$log = '';
	// 1) Constant. `wp config set` updates in place if it already exists.
	$has = trim((string)shell_exec('sudo -u '.escapeshellarg($user).' /usr/local/bin/wp config has DISABLE_WP_CRON --path='.escapeshellarg($docroot).' 2>/dev/null; echo $?'));
	if(substr($has, -1) === '0')
		$log .= "DISABLE_WP_CRON already present in wp-config.php.\n";
	$set = (string)shell_exec('sudo -u '.escapeshellarg($user).' /usr/local/bin/wp config set DISABLE_WP_CRON true --raw --type=constant --path='.escapeshellarg($docroot).' 2>&1');
	$log .= $set."\n";
	if(stripos($set, 'Error') === 0 || stripos($set, 'Error:') !== false)
		return array('error' => 'Could not update wp-config.php: '.trim($set), 'success' => '', 'log' => $log);

	// 2) System cron (per-user spool). Skip if our marked line already exists.
	$marker = wp_cron_marker($user);
	$exists = (int)trim((string)shell_exec('sudo grep -cF '.escapeshellarg($marker).' /var/spool/cron/'.escapeshellarg($user).' 2>/dev/null'));
	if($exists === 0) {
		$line = '*/2 * * * * /usr/local/bin/wp cron event run --due-now --path='.$docroot.' >/dev/null 2>&1 '.$marker;
		shell_exec('echo '.escapeshellarg($line).' | sudo tee --append /var/spool/cron/'.escapeshellarg($user).' > /dev/null');
		shell_exec('sudo chown '.escapeshellarg($user).': /var/spool/cron/'.escapeshellarg($user).' 2>/dev/null');
		shell_exec('sudo chmod 600 /var/spool/cron/'.escapeshellarg($user).' 2>/dev/null');
		$log .= "Installed */2 wp-cron system cron.\n";
	} else {
		$log .= "System cron already present.\n";
	}

	log_debug('[wp-manage] wp-cron disabled (system cron installed) for '.$user);
	return array('error' => '', 'success' => 'WP-Cron disabled — a system cron now runs due events every 2 minutes.', 'log' => $log);
}

/* Disable the option again: remove the constant and our system cron line. */
function wp_wpcron_disable($user, $docroot) {
	$log = '';
	$has = trim((string)shell_exec('sudo -u '.escapeshellarg($user).' /usr/local/bin/wp config has DISABLE_WP_CRON --path='.escapeshellarg($docroot).' 2>/dev/null; echo $?'));
	if(substr($has, -1) === '0') {
		$del = (string)shell_exec('sudo -u '.escapeshellarg($user).' /usr/local/bin/wp config delete DISABLE_WP_CRON --path='.escapeshellarg($docroot).' 2>&1');
		$log .= $del."\n";
	}
	// Remove our marked crontab line. Match the bare marker slug (no '#', no
	// slashes) so '/'-delimited sed is safe.
	$slug = 'reqad-wpcron-'.wp_cache_zone($user);
	shell_exec('sudo sed -i '.escapeshellarg('/'.$slug.'/d').' /var/spool/cron/'.escapeshellarg($user).' 2>/dev/null');
	$log .= "Removed system cron.\n";

	log_debug('[wp-manage] wp-cron re-enabled (system cron removed) for '.$user);
	return array('error' => '', 'success' => 'WP-Cron re-enabled — the system cron was removed.', 'log' => $log);
}

/* Combined status for the Manage modal. Looks the account up in `wordpress` by
   user, so the caller only supplies a (validated) username. Returns null if the
   user is not a tracked WordPress install. */
function wp_manage_status($db, $ini, $user) {
	$res = $db->query('SELECT user, domain, path FROM wordpress WHERE user="'.$db->escapeString($user).'"');
	$row = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;
	if(!$row) return null;
	$docroot = wp_site_docroot($row['user'], $row['path'] ?? '');
	return array(
		'user'        => $row['user'],
		'domain'      => $row['domain'],
		'is_nginx'    => wp_is_nginx($ini),
		'nginx_cache' => wp_nginx_cache_state($ini, $row['domain']),
		'wp_cron'     => wp_wpcron_state($row['user'], $docroot),
	);
}
?>
