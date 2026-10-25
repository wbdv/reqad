<?php
/*
 * Web login for the panel: principals, passwords, sessions, brute-force
 * protection (lockouts + csf bans) and TOTP two-factor authentication.
 *
 * Which mode is active is decided by the panel vhost, not by a setting: when
 * `reqad auth mode web` has switched it, nginx drops basic auth, checks every
 * request with auth_request (app/auth/endpoint/check.php) and passes
 * REQAD_AUTH=web to PHP. A vhost still on basic auth passes nothing, so PHP
 * never enforces a second login there - and can never be the only thing
 * missing, because nginx refuses unauthenticated requests in both modes.
 *
 * Used by index.php, the auth_request endpoint and bin/reqad. Needs only the
 * global $db (defines.php); no $_POST access outside the controller functions
 * the login module calls.
 */

define('AUTH_COOKIE',       '__Host-reqad_sid');     // session token
define('AUTH_LOGIN_COOKIE', '__Host-reqad_lc');      // login-form CSRF nonce
define('AUTH_DEVICE_COOKIE', '__Host-reqad_dev');    // this browser (known device; may also skip 2FA)
define('AUTH_NEXT_COOKIE',  '__Host-reqad_next');    // where to go after login (keeps it out of the URL)
define('AUTH_FLASH_COOKIE', '__Host-reqad_flash');   // one-shot notice for the login page
define('AUTH_FAIL_OUTCOMES', "'bad_password','unknown_user','bad_2fa','bad_code','bad_passkey','bad_token'");

/* Tunables; each can be overridden by a settings row of the same name. */
function auth_cfg($name) {
    static $defaults = array(
        'auth-idle-minutes'      => 30,    // session ends after this long without a request
        'auth-max-hours'         => 12,    // ... and this long after login, regardless
        'auth-pending-minutes'   => 5,     // time to enter the 2FA code after the password
        'auth-lock-failures'     => 5,     // failures from one IP ...
        'auth-lock-minutes'      => 15,    // ... within this window lock that IP out of the form
        'auth-ban-failures'      => 10,    // failures from one IP within an hour -> csf temp ban
        'auth-ban-seconds'       => 3600,
        'auth-permban-after'     => 3,     // temp bans within 7 days before a permanent csf ban
        'auth-distributed-ips'   => 5,     // distinct IPs failing on one username within an hour
        'auth-min-password'      => 10,
        'auth-sudo-minutes'      => 10,    // sensitive changes allowed this long after a password confirmation
        'auth-trust-days'        => 7,     // "don't ask for the 2FA code on this device"
        'auth-device-days'       => 180,   // a browser not used this long is a new device again
        'auth-code-minutes'      => 15,    // emailed confirmation codes are valid this long
    );
    static $cache = array();
    if (!isset($cache[$name])) {
        $v = auth_setting($name, '');
        $cache[$name] = ($v !== '' && ctype_digit($v)) ? (int)$v : $defaults[$name];
    }
    return $cache[$name];
}

/* ---- plumbing -------------------------------------------------------------- */

function auth_db() {
    global $db;
    static $ready = false;
    if (!$ready) {
        $db->busyTimeout(5000);
        $ready = true;
    }
    return $db;
}

/* One prepared statement, bound by name, executed. Returns the result. */
function auth_q($sql, array $bind = array()) {
    $stmt = auth_db()->prepare($sql);
    if (!$stmt)
        return false;
    foreach ($bind as $k => $v)
        $stmt->bindValue(':'.$k, $v, is_int($v) ? SQLITE3_INTEGER : SQLITE3_TEXT);
    return $stmt->execute();
}

function auth_row($sql, array $bind = array()) {
    $r = auth_q($sql, $bind);
    $row = $r ? $r->fetchArray(SQLITE3_ASSOC) : false;
    return $row ?: null;
}

function auth_rows($sql, array $bind = array()) {
    $out = array();
    $r = auth_q($sql, $bind);
    while ($r && ($row = $r->fetchArray(SQLITE3_ASSOC)))
        $out[] = $row;
    return $out;
}

function auth_setting($name, $default = '') {
    $row = auth_row('SELECT value FROM settings WHERE name = :n', array('n' => $name));
    return ($row && $row['value'] !== null) ? (string)$row['value'] : $default;
}

/* The tables exist (migration 1037 applied). */
function auth_ready() {
    static $ok = null;
    if ($ok === null)
        $ok = (bool)auth_db()->querySingle("SELECT 1 FROM sqlite_master WHERE type='table' AND name='auth_sessions'");
    return $ok;
}

function auth_web_enabled() {
    return isset($_SERVER['REQAD_AUTH']) && $_SERVER['REQAD_AUTH'] === 'web';
}

function auth_ip() {
    return isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
}

function auth_ua() {
    return isset($_SERVER['HTTP_USER_AGENT']) ? substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 255) : '';
}

function auth_is_ajax() {
    return (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_POST['action']) && is_string($_POST['action']) && strpos($_POST['action'], 'ajax-') === 0);
}

/*
 * Who is acting, for audit lines. Web login: the session's user. CLI: the
 * real user behind sudo. Basic auth: nginx does not pass the user name, so
 * the old value (the php-fpm worker's own user) is kept. API: "api:<token name>".
 */
function auth_user() {
    if (isset($GLOBALS['api_actor']))
        return $GLOBALS['api_actor'];
    if (PHP_SAPI === 'cli') {
        $u = getenv('SUDO_USER');
        if (!$u && function_exists('posix_getpwuid')) {
            $pw = posix_getpwuid(posix_geteuid());
            $u = $pw ? $pw['name'] : '';
        }
        return 'cli:'.($u ?: '?');
    }
    if (auth_web_enabled() && ($s = auth_session_current()))
        return $s['username'];
    return isset($_SERVER['USER']) ? (string)$_SERVER['USER'] : '-';
}

/* Security event: auth_log table + log/auth_log (one line, lfd/fail2ban friendly). */
function auth_log_event($event, $detail = '', $principal_id = 0, $username = '') {
    $ip = auth_ip();
    if (auth_ready())
        auth_q('INSERT INTO auth_log (ts, principal_id, username, ip, event, detail)
                VALUES (:ts, :p, :u, :ip, :e, :d)',
               array('ts' => time(), 'p' => (int)$principal_id, 'u' => (string)$username,
                     'ip' => $ip, 'e' => $event, 'd' => substr((string)$detail, 0, 500)));
    $clean = function ($s) { return preg_replace('/[^\x21-\x7e]/', '_', (string)$s); };
    $file = _PATH.'/log/auth_log';
    $new  = !file_exists($file);
    @error_log(date('c').' reqad-auth event='.$event.' ip='.($ip !== '' ? $ip : '-')
               .' user='.$clean($username !== '' ? $username : '-')
               .($detail !== '' ? ' '.str_replace(array("\r", "\n"), ' ', $detail) : '')."\n",
               3, $file);
    if ($new) {     // the CLI runs as root; the panel (reqad) must still be able to append
        @chmod($file, 0640);
        if (function_exists('posix_geteuid') && posix_geteuid() === 0)
            @chown($file, 'reqad');
    }
}

/* ---- principals and passwords --------------------------------------------- */

function auth_principal($username, $type = 'admin') {
    return auth_row('SELECT * FROM auth_principals WHERE type = :t AND username = :u',
                    array('t' => $type, 'u' => (string)$username));
}

function auth_principal_by_id($id) {
    return auth_row('SELECT * FROM auth_principals WHERE id = :id', array('id' => (int)$id));
}

function auth_admins() {
    return auth_rows("SELECT * FROM auth_principals WHERE type = 'admin' ORDER BY id");
}

/*
 * First run: create the admin principals from nginx_auth (user:hash lines), so
 * the existing basic-auth password keeps working. The $6$/$5$/$1$ crypt hashes
 * it holds verify through crypt(); the first successful login rehashes them.
 */
function auth_bootstrap() {
    if (!auth_ready())
        return 0;
    if ((int)auth_db()->querySingle("SELECT COUNT(*) FROM auth_principals WHERE type = 'admin'") > 0)
        return 0;
    $file = _PATH.'/nginx_auth';
    $lines = is_readable($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : array();
    $n = 0;
    foreach ($lines as $line) {
        if ($line === '' || $line[0] === '#' || strpos($line, ':') === false)
            continue;
        list($user, $hash) = explode(':', $line, 2);
        if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $user))
            continue;
        auth_q("INSERT OR IGNORE INTO auth_principals (type, username, pw_hash, email, created_at)
                VALUES ('admin', :u, :h, :e, :ts)",
               array('u' => $user, 'h' => trim($hash), 'e' => auth_setting('email', ''), 'ts' => time()));
        $n++;
    }
    if ($n)
        auth_log_event('import', "imported $n admin login(s) from nginx_auth");
    return $n;
}

function auth_hash_verify($hash, $password) {
    if ($hash === '')
        return false;
    if (strpos($hash, '$2y$') === 0 || strpos($hash, '$argon2') === 0)
        return password_verify($password, $hash);
    if (preg_match('/^\$(1|5|6)\$/', $hash)) {
        $c = crypt($password, $hash);
        return is_string($c) && strlen($c) > 13 && hash_equals($hash, $c);
    }
    return false;
}

/* Spend the same time on unknown users as on a real verify. */
function auth_dummy_verify($password) {
    static $dummy = null;
    if ($dummy === null)
        $dummy = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT, array('cost' => 12));
    password_verify($password, $dummy);
}

function auth_password_verify(array $p, $password) {
    if (!auth_hash_verify($p['pw_hash'], $password))
        return false;
    if (strpos($p['pw_hash'], '$2y$') !== 0 || password_needs_rehash($p['pw_hash'], PASSWORD_BCRYPT, array('cost' => 12)))
        auth_q('UPDATE auth_principals SET pw_hash = :h WHERE id = :id',
               array('h' => password_hash($password, PASSWORD_BCRYPT, array('cost' => 12)), 'id' => (int)$p['id']));
    return true;
}

/* '' when acceptable, otherwise why not. */
function auth_password_policy($password, $username = '') {
    $min = auth_cfg('auth-min-password');
    if (strlen($password) < $min)
        return "The password must be at least $min characters long.";
    if (strlen($password) > 256)
        return 'The password is too long.';
    if ($username !== '' && stripos($password, $username) !== false)
        return 'The password must not contain the user name.';
    if (count(array_unique(str_split($password))) < 5)
        return 'The password is too simple.';
    return '';
}

/*
 * Set a principal's password. Admin passwords are also written to nginx_auth
 * (as SHA-512 crypt), so switching the vhost back to basic auth keeps the
 * same password.
 */
function auth_password_set($principal_id, $password) {
    $p = auth_principal_by_id($principal_id);
    if (!$p)
        return false;
    auth_q('UPDATE auth_principals SET pw_hash = :h, pw_changed_at = :ts WHERE id = :id',
           array('h' => password_hash($password, PASSWORD_BCRYPT, array('cost' => 12)),
                 'ts' => time(), 'id' => (int)$principal_id));
    if ($p['type'] === 'admin')
        auth_nginx_auth_sync($p['username'], $password);
    // whoever knew the old password: their browsers are new devices again
    auth_devices_forget_all($principal_id, auth_device_current_id());
    auth_devices_untrust_all($principal_id);
    return true;
}

function auth_nginx_auth_sync($username, $password) {
    $file = _PATH.'/nginx_auth';
    $salt = substr(strtr(base64_encode(random_bytes(12)), '+', '.'), 0, 16);
    $line = $username.':'.crypt($password, '$6$'.$salt.'$');
    $out = array();
    $found = false;
    foreach (is_readable($file) ? file($file, FILE_IGNORE_NEW_LINES) : array() as $l) {
        if (strpos($l, $username.':') === 0) {
            $out[] = $line;
            $found = true;
        } elseif ($l !== '') {
            $out[] = $l;
        }
    }
    if (!$found)
        $out[] = $line;
    // Rewrite in place: keeps the file's owner and its 0600 mode.
    return @file_put_contents($file, implode("\n", $out)."\n", LOCK_EX) !== false;
}

/* ---- sessions -------------------------------------------------------------- */

function auth_cookie($name, $value, $expires) {
    setcookie($name, $value, array(
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ));
}

function auth_cookie_value($name) {
    return (isset($_COOKIE[$name]) && is_string($_COOKIE[$name]) && preg_match('/^[0-9a-f]{64}$/', $_COOKIE[$name]))
        ? $_COOKIE[$name] : '';
}

/* New session for $p in $state; sets the cookie. Returns the row. */
function auth_session_create(array $p, $state, $sudo = false) {
    $token = bin2hex(random_bytes(32));
    $now = time();
    $expires = $state === 'pending'
        ? $now + 60 * auth_cfg('auth-pending-minutes')
        : $now + 3600 * auth_cfg('auth-max-hours');
    $id = hash('sha256', $token);
    auth_q('INSERT INTO auth_sessions (id, principal_id, state, csrf, ip, ua, created_at, last_seen, expires_at, mfa)
            VALUES (:id, :p, :st, :csrf, :ip, :ua, :ts, :ts, :exp, 0)',
           array('id' => $id, 'p' => (int)$p['id'], 'st' => $state, 'csrf' => bin2hex(random_bytes(32)),
                 'ip' => auth_ip(), 'ua' => auth_ua(), 'ts' => $now, 'exp' => $expires));
    if ($sudo && $state === 'active')       // the password was just typed: counts as a confirmation
        auth_q('UPDATE auth_sessions SET sudo_until = :t WHERE id = :id',
               array('t' => $now + 60 * auth_cfg('auth-sudo-minutes'), 'id' => $id));
    auth_cookie(AUTH_COOKIE, $token, 0);    // browser-session cookie; the server enforces expiry
    $_COOKIE[AUTH_COOKIE] = $token;
    return auth_row('SELECT * FROM auth_sessions WHERE id = :id', array('id' => $id));
}

/* The session row (joined with its principal) for the cookie, in $state, or
   null. Only sessions of $type count: an account's session (type 'account',
   the account panel on 2083, which passes its own $token) never opens 2087,
   even when its token is sent in this panel's cookie. */
function auth_session_lookup($state, $type = 'admin', $token = null) {
    $token = $token === null ? auth_cookie_value(AUTH_COOKIE) : (preg_match('/^[0-9a-f]{64}$/', (string)$token) ? (string)$token : '');
    if ($token === '' || !auth_ready())
        return null;
    $s = auth_row('SELECT s.*, p.username, p.type, p.status AS principal_status, p.totp_enabled
                   FROM auth_sessions s JOIN auth_principals p ON p.id = s.principal_id
                   WHERE s.id = :id', array('id' => hash('sha256', $token)));
    if (!$s || $s['state'] !== $state || $s['principal_status'] !== 'active' || $s['type'] !== $type)
        return null;
    $now = time();
    $idle = 60 * auth_cfg('auth-idle-minutes');
    if ($now >= (int)$s['expires_at'] || ($state === 'active' && $now - (int)$s['last_seen'] > $idle)) {
        auth_q('DELETE FROM auth_sessions WHERE id = :id', array('id' => $s['id']));
        return null;
    }
    return $s;
}

/* The logged-in session, or null. Touches last_seen at most once a minute. */
function auth_session_current() {
    static $cache = false;
    if ($cache !== false)
        return $cache;
    $s = auth_session_lookup('active');
    if ($s && time() - (int)$s['last_seen'] >= 60) {
        auth_q('UPDATE auth_sessions SET last_seen = :ts, ip = :ip WHERE id = :id',
               array('ts' => time(), 'ip' => auth_ip(), 'id' => $s['id']));
    }
    return $cache = $s;
}

/* A session waiting for its second factor, or null. */
function auth_session_pending() {
    return auth_session_lookup('pending');
}

/* A session waiting for the code emailed to confirm a new device, or null. */
function auth_session_confirming() {
    return auth_session_lookup('confirm');
}

/* pending/confirm -> active, under a NEW token (no fixation across the 2FA or
   email-code step). $sudo: counts as a fresh confirmation (default: $mfa). */
function auth_session_activate(array $s, $mfa, $sudo = null) {
    $token = bin2hex(random_bytes(32));
    $now = time();
    auth_q("UPDATE auth_sessions SET id = :new, state = 'active', mfa = :mfa, created_at = :ts,
            last_seen = :ts, expires_at = :exp, csrf = :csrf WHERE id = :old",
           array('new' => hash('sha256', $token), 'mfa' => $mfa ? 1 : 0, 'ts' => $now,
                 'exp' => $now + 3600 * auth_cfg('auth-max-hours'),
                 'csrf' => bin2hex(random_bytes(32)), 'old' => $s['id']));
    if ($sudo === null ? $mfa : $sudo)      // password (+ code) just entered: a fresh confirmation
        auth_q('UPDATE auth_sessions SET sudo_until = :t WHERE id = :id',
               array('t' => $now + 60 * auth_cfg('auth-sudo-minutes'), 'id' => hash('sha256', $token)));
    auth_cookie(AUTH_COOKIE, $token, 0);
    $_COOKIE[AUTH_COOKIE] = $token;
}

function auth_session_destroy() {
    $token = auth_cookie_value(AUTH_COOKIE);
    if ($token !== '' && auth_ready())
        auth_q('DELETE FROM auth_sessions WHERE id = :id', array('id' => hash('sha256', $token)));
    auth_cookie(AUTH_COOKIE, '', time() - 3600);
    unset($_COOKIE[AUTH_COOKIE]);
}

function auth_sessions_list($principal_id = 0) {
    auth_sessions_prune();
    if ($principal_id)
        return auth_rows("SELECT s.*, p.username FROM auth_sessions s JOIN auth_principals p ON p.id = s.principal_id
                          WHERE s.state = 'active' AND s.principal_id = :p ORDER BY s.last_seen DESC",
                         array('p' => (int)$principal_id));
    return auth_rows("SELECT s.*, p.username FROM auth_sessions s JOIN auth_principals p ON p.id = s.principal_id
                      WHERE s.state = 'active' ORDER BY s.last_seen DESC");
}

/* Revoke by the session's id hash (as listed), never by token. */
function auth_session_revoke($id) {
    auth_q('DELETE FROM auth_sessions WHERE id = :id', array('id' => (string)$id));
}

function auth_sessions_revoke_all($principal_id, $except_id = '') {
    auth_q('DELETE FROM auth_sessions WHERE principal_id = :p AND id != :keep',
           array('p' => (int)$principal_id, 'keep' => (string)$except_id));
}

function auth_sessions_prune() {
    $now = time();
    auth_q("DELETE FROM auth_sessions WHERE expires_at <= :now
            OR (state = 'active' AND last_seen < :idle)",
           array('now' => $now, 'idle' => $now - 60 * auth_cfg('auth-idle-minutes')));
}

/* Short, human description of a user agent for the sessions table. */
function auth_ua_label($ua) {
    $browser = 'Browser';
    foreach (array('Edg/' => 'Edge', 'OPR/' => 'Opera', 'Firefox/' => 'Firefox', 'Chrome/' => 'Chrome',
                   'Safari/' => 'Safari', 'curl/' => 'curl') as $needle => $name)
        if (strpos($ua, $needle) !== false) { $browser = $name; break; }
    $os = '';
    foreach (array('Windows' => 'Windows', 'Android' => 'Android', 'iPhone' => 'iPhone', 'iPad' => 'iPad',
                   'Mac OS X' => 'macOS', 'Linux' => 'Linux') as $needle => $name)
        if (strpos($ua, $needle) !== false) { $os = $name; break; }
    return $browser.($os !== '' ? ' on '.$os : '');
}

/* ---- sudo mode (re-confirmation for sensitive changes) ---------------------- */

/*
 * Seconds the current session may still make sensitive changes without
 * confirming the password again. Basic-auth mode has no session and sends the
 * password with every request anyway, so it always counts as confirmed.
 */
function auth_sudo_left() {
    if (!auth_web_enabled())
        return 86400;
    $s = auth_session_current();
    if (!$s || !isset($s['sudo_until']))
        return 0;
    return max(0, (int)$s['sudo_until'] - time());
}

function auth_sudo_grant() {
    $s = auth_session_current();
    if (!$s)
        return 0;
    $until = time() + 60 * auth_cfg('auth-sudo-minutes');
    auth_q('UPDATE auth_sessions SET sudo_until = :t WHERE id = :id', array('t' => $until, 'id' => $s['id']));
    return $until - time();
}

/*
 * Check $p's password (+ 2FA code or recovery code when 2FA is on). '' when
 * correct, else the message to show. Failures count like failed logins, so
 * guessing through this dialog runs into the same lockout and csf bans.
 */
function auth_reauth(array $p, $password, $code) {
    $ip = auth_ip();
    if (($left = auth_ip_locked($ip)) > 0)
        return 'Too many failed attempts. Try again in '.max(1, (int)ceil($left / 60)).' minutes.';
    if (!auth_password_verify($p, (string)$password)) {
        auth_attempt_record($ip, $p['username'], 'bad_password');
        auth_log_event('reauth_fail', 'wrong password in the confirmation dialog', (int)$p['id'], $p['username']);
        auth_after_failure($ip, $p['username']);
        usleep(800000);
        return 'The password is not correct.';
    }
    if ((int)$p['totp_enabled'] === 1) {
        $code = trim((string)$code);
        $ok = preg_match('/^\d{3}\s?\d{3}$/', $code) ? auth_totp_verify($p, $code) : auth_recovery_use($p['id'], $code);
        if (!$ok) {
            auth_attempt_record($ip, $p['username'], 'bad_2fa');
            auth_log_event('reauth_fail', 'wrong 2FA code in the confirmation dialog', (int)$p['id'], $p['username']);
            auth_after_failure($ip, $p['username']);
            usleep(800000);
            return 'The two-factor code is not valid.';
        }
    }
    return '';
}

/* ---- devices ---------------------------------------------------------------
 * Every browser that completes a login gets the __Host-reqad_dev cookie and an
 * auth_devices row (sha256 of the token): a "known device". One that passed
 * 2FA with "don't ask for a code on this device" also has trusted_until in the
 * future and skips the code until then. A browser without a row - never used,
 * cookie cleared, forgotten on the Security page, unused for auth-device-days -
 * is a new device: it can be made to confirm the login by email.
 */

/* This browser's row for $p (trusted or not), or null. */
function auth_device_known(array $p) {
    $token = auth_cookie_value(AUTH_DEVICE_COOKIE);
    if ($token === '')
        return null;
    return auth_row('SELECT * FROM auth_devices WHERE token_hash = :h AND principal_id = :p AND last_seen > :old',
                    array('h' => hash('sha256', $token), 'p' => (int)$p['id'],
                          'old' => time() - 86400 * auth_cfg('auth-device-days')));
}

/* Record a completed login from this browser; $trust: skip 2FA here for
   auth-trust-days. Returns the device id. */
function auth_device_seen(array $p, $trust = false) {
    $now = time();
    $d = auth_device_known($p);
    if ($d) {
        $token = auth_cookie_value(AUTH_DEVICE_COOKIE);
        auth_q('UPDATE auth_devices SET last_seen = :ts, last_ip = :ip, label = :l'
               .($trust ? ', trusted_until = :u' : '').' WHERE id = :id',
               array('ts' => $now, 'ip' => auth_ip(), 'l' => auth_ua_label(auth_ua()), 'id' => (int)$d['id'])
               + ($trust ? array('u' => $now + 86400 * auth_cfg('auth-trust-days')) : array()));
        $id = (int)$d['id'];
    } else {
        $token = bin2hex(random_bytes(32));
        auth_q('INSERT INTO auth_devices (principal_id, token_hash, label, first_seen, last_seen, last_ip, trusted_until)
                VALUES (:p, :h, :l, :ts, :ts, :ip, :u)',
               array('p' => (int)$p['id'], 'h' => hash('sha256', $token), 'l' => auth_ua_label(auth_ua()),
                     'ts' => $now, 'ip' => auth_ip(), 'u' => $trust ? $now + 86400 * auth_cfg('auth-trust-days') : 0));
        $id = (int)auth_db()->lastInsertRowID();
    }
    auth_cookie(AUTH_DEVICE_COOKIE, $token, $now + 86400 * auth_cfg('auth-device-days'));
    $_COOKIE[AUTH_DEVICE_COOKIE] = $token;
    return $id;
}

/* The trusted-device row for this browser and $p (2FA skipped), or null. */
function auth_device_trusted(array $p) {
    $d = auth_device_known($p);
    return ($d && (int)$d['trusted_until'] > time()) ? $d : null;
}

/* This browser's device id (0 when it has no device cookie). */
function auth_device_current_id() {
    $token = auth_cookie_value(AUTH_DEVICE_COOKIE);
    if ($token === '')
        return 0;
    return (int)auth_db()->querySingle('SELECT id FROM auth_devices WHERE token_hash = '.auth_sql_str(hash('sha256', $token)));
}

function auth_devices_list($principal_id) {
    auth_q('DELETE FROM auth_devices WHERE last_seen <= :old', array('old' => time() - 86400 * auth_cfg('auth-device-days')));
    return auth_rows('SELECT * FROM auth_devices WHERE principal_id = :p ORDER BY last_seen DESC', array('p' => (int)$principal_id));
}

/* Forgotten: the browser is a new device at its next login. */
function auth_device_forget($id, $principal_id) {
    auth_q('DELETE FROM auth_devices WHERE id = :id AND principal_id = :p', array('id' => (int)$id, 'p' => (int)$principal_id));
}

function auth_devices_forget_all($principal_id, $except_id = 0) {
    auth_q('DELETE FROM auth_devices WHERE principal_id = :p AND id != :keep',
           array('p' => (int)$principal_id, 'keep' => (int)$except_id));
}

/* Every device asks for the 2FA code again but stays known (2FA changes). */
function auth_devices_untrust_all($principal_id) {
    auth_q('UPDATE auth_devices SET trusted_until = 0 WHERE principal_id = :p', array('p' => (int)$principal_id));
}

/* ---- new devices: emailed confirmation codes, login alerts ----------------- */

/* The emailed code for new devices is on unless switched off from the
   command line (`reqad auth email-code off`, the break-glass). */
function auth_email_code_on() {
    return auth_setting('auth-email-code', '1') !== '0';
}

/*
 * Is the emailed code in force for $p, and if not, why not:
 * 'active' | '2fa' (not needed: the authenticator code is the second step) |
 * 'off' (switched off on the server) | 'no-smtp' (no sending method - the
 * code could not be sent, so it is not asked for) | 'no-email'.
 */
function auth_email_code_state(array $p) {
    if ((int)$p['totp_enabled'] === 1)
        return '2fa';
    if (!auth_email_code_on())
        return 'off';
    if (auth_setting('smtp_server', '') === '' || auth_setting('smtp_from', '') === '')
        return 'no-smtp';
    if ($p['email'] === '')
        return 'no-email';
    return 'active';
}

/* Email the admin after each login from a new device. */
function auth_login_alert_on() {
    return auth_setting('auth-login-alert', '0') === '1';
}

/*
 * Does a login of $p from this browser and address need the emailed code?
 * It is the second step for admins without 2FA - on by default, as soon as
 * the panel can send email. With an authenticator app it would be a third
 * step that adds nothing, so it is not asked for then.
 */
function auth_needs_email_code(array $p) {
    return auth_email_code_state($p) === 'active' && !auth_device_known($p);
}

/* "jane@example.com" -> "j•@example.com": enough to recognise, not to harvest. */
function auth_mask_email($email) {
    $at = strrpos($email, '@');
    if ($at === false)
        return '';
    $local = substr($email, 0, $at);
    return substr($local, 0, 1).str_repeat('•', max(1, min(6, strlen($local) - 1))).substr($email, $at);
}

/* The open challenge of a session ($purpose 'login' | 'verify'), or null. */
function auth_challenge($session_id, $purpose) {
    $c = auth_row('SELECT * FROM auth_challenges WHERE session_id = :s AND purpose = :p',
                  array('s' => (string)$session_id, 'p' => $purpose));
    if ($c && (int)$c['expires_at'] <= time()) {
        auth_q('DELETE FROM auth_challenges WHERE id = :id', array('id' => (int)$c['id']));
        return null;
    }
    return $c;
}

function auth_challenge_drop($session_id, $purpose) {
    auth_q('DELETE FROM auth_challenges WHERE session_id = :s AND purpose = :p', array('s' => (string)$session_id, 'p' => $purpose));
}

/*
 * Email a new 6-digit code for ($session_id, $purpose) to $email. A resend
 * replaces the code. At most 5 sends per challenge, 30 seconds apart. '' when
 * sent, else the message to show.
 */
function auth_challenge_send($session_id, $purpose, $email, $target = '', $remember = 0) {
    $now = time();
    $c = auth_challenge($session_id, $purpose);
    if ($c && (int)$c['sent_at'] > $now - 30)
        return 'A code was sent less than a minute ago. Check your inbox (and the spam folder) first.';
    if ($c && (int)$c['sends'] >= 5)
        return 'Too many codes sent. Start over.';
    if (!function_exists('send_mail'))
        return 'Email cannot be sent from here.';
    auth_q('DELETE FROM auth_challenges WHERE expires_at <= :now', array('now' => $now));
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    if (($err = auth_mail_code($email, $code, $purpose)) !== '') {
        auth_log_event('email_code_failed', 'to '.$email.': '.$err);
        return 'The code could not be sent: '.$err;
    }
    $hash = hash('sha256', $session_id.'|'.$code);
    $exp  = $now + 60 * auth_cfg('auth-code-minutes');
    if ($c)
        auth_q('UPDATE auth_challenges SET code_hash = :h, sends = sends + 1, sent_at = :ts, expires_at = :exp, attempts = 0
                WHERE id = :id', array('h' => $hash, 'ts' => $now, 'exp' => $exp, 'id' => (int)$c['id']));
    else
        auth_q('INSERT INTO auth_challenges (session_id, purpose, code_hash, target, remember, sent_at, expires_at)
                VALUES (:s, :p, :h, :t, :r, :ts, :exp)',
               array('s' => (string)$session_id, 'p' => $purpose, 'h' => $hash, 't' => (string)$target,
                     'r' => $remember ? 1 : 0, 'ts' => $now, 'exp' => $exp));
    return '';
}

/*
 * Check a code. Returns the challenge row when it matches (and removes it),
 * 'wrong' (attempts left), 'locked' (5 wrong: the challenge is gone) or
 * 'expired' (no open challenge).
 */
function auth_challenge_check($session_id, $purpose, $code) {
    $c = auth_challenge($session_id, $purpose);
    if (!$c)
        return 'expired';
    $code = preg_replace('/\s+/', '', (string)$code);
    if (preg_match('/^\d{6}$/', $code) && hash_equals($c['code_hash'], hash('sha256', $session_id.'|'.$code))) {
        auth_q('DELETE FROM auth_challenges WHERE id = :id', array('id' => (int)$c['id']));
        return $c;
    }
    if ((int)$c['attempts'] + 1 >= 5) {
        auth_q('DELETE FROM auth_challenges WHERE id = :id', array('id' => (int)$c['id']));
        return 'locked';
    }
    auth_q('UPDATE auth_challenges SET attempts = attempts + 1 WHERE id = :id', array('id' => (int)$c['id']));
    return 'wrong';
}

/* Where, when and what from: the facts shown in login emails. */
function auth_mail_facts() {
    return array('Server' => php_uname('n'), 'IP address' => auth_ip(), 'Browser' => auth_ua_label(auth_ua()),
                 'Time' => date('Y-m-d H:i:s T'));
}

function auth_mail_code($email, $code, $purpose) {
    /* a hosting account confirming its contact address (app/functions/notify.php) */
    if ($purpose === 'contact' && function_exists('account_contact_mail_code'))
        return account_contact_mail_code($email, $code);
    $host = php_uname('n');
    $big  = '<p style="margin:24px 0;font-size:32px;letter-spacing:6px;font-weight:bold;color:#2a6099;font-family:Arial,Helvetica,sans-serif;">'.$code.'</p>';
    $min  = auth_cfg('auth-code-minutes');
    if ($purpose === 'verify') {
        $html = mail_html_layout('Confirm your email address',
            '<p style="margin:0;font-size:15px;color:#232e3c;">Enter this code on the Security page of the Reqad panel on <b>'.htmlspecialchars($host).'</b> to send login codes to this address:</p>'
            .$big.'<p style="margin:0;font-size:13px;color:#656d77;">It expires in '.$min.' minutes. If you did not ask for it, ignore this message.</p>');
        $text = "Confirm your email address\n\nEnter this code on the Security page of the Reqad panel on $host:\n\n    $code\n\nIt expires in $min minutes.";
        return send_mail($email, "Reqad: confirm your email address ($host)", $html, $text);
    }
    $html = mail_html_layout('Confirm your login',
        '<p style="margin:0;font-size:15px;color:#232e3c;">Someone entered your password on a device the panel has not seen before. To finish logging in, enter this code:</p>'
        .$big.mail_html_facts(auth_mail_facts())
        .'<p style="margin:0;font-size:13px;color:#656d77;">It expires in '.$min.' minutes. <b>If this was not you</b>, someone knows your password: change it under Settings &gt; Security, or run <code>reqad auth passwd</code> on the server.</p>');
    $text = "Confirm your login\n\nSomeone entered your password on a new device. To finish logging in, enter this code:\n\n    $code\n\n";
    foreach (auth_mail_facts() as $k => $v)
        $text .= str_pad($k.':', 13).$v."\n";
    $text .= "\nIt expires in $min minutes. If this was not you, change your password (reqad auth passwd on the server).";
    return send_mail($email, "Reqad login code for $host", $html, $text);
}

/* "New login" email to $p after a login from a new device. */
function auth_mail_login_alert(array $p, $how) {
    if ($p['email'] === '' || !function_exists('send_mail'))
        return;
    $host  = php_uname('n');
    $facts = auth_mail_facts() + array('User' => $p['username'], 'Signed in with' => $how);
    $html = mail_html_layout('New login to your panel',
        '<p style="margin:0;font-size:15px;color:#232e3c;">Your Reqad panel was logged in to from a new device.</p>'
        .mail_html_facts($facts)
        .'<p style="margin:0;font-size:13px;color:#656d77;">If this was you, there is nothing to do. <b>If it was not</b>, log out its session and change your password under Settings &gt; Security, or run <code>reqad auth passwd</code> and <code>reqad auth logout</code> on the server.</p>');
    $text = "New login to your panel\n\n";
    foreach ($facts as $k => $v)
        $text .= str_pad($k.':', 16).$v."\n";
    $text .= "\nIf this was not you, change your password (reqad auth passwd) and end its session (reqad auth logout).";
    if (($err = send_mail($p['email'], "New login to Reqad on $host", $html, $text)) !== '')
        auth_log_event('login_alert_failed', $err, (int)$p['id'], $p['username']);
}

/* ---- passkeys (WebAuthn) ----------------------------------------------------
 * A passkey logs in on its own, without password or code: the device signs a
 * one-time challenge with a private key it never gives out, after the user
 * unlocks it (fingerprint, face, PIN - user verification is required). It is
 * bound to the panel's hostname (the RP ID), so a look-alike site cannot use
 * it, and it does not work when the panel is opened by IP address. Challenges
 * are single use and live in auth_challenges ('passkey-login' keyed by the
 * login-form nonce, 'passkey-add' / 'passkey-sudo' by the session).
 * The verification itself is lbuchs/WebAuthn (app/auth/webauthn, MIT).
 */

/* The hostname passkeys are bound to; '' when the panel is reached by IP. */
function auth_rp_id() {
    $host = isset($_SERVER['HTTP_HOST']) ? strtolower((string)$_SERVER['HTTP_HOST']) : '';
    $host = preg_replace('/:\d+$/', '', $host);
    if ($host === '' || strpos($host, '.') === false || !preg_match('/^[a-z0-9.-]+$/', $host) || filter_var($host, FILTER_VALIDATE_IP))
        return '';
    return $host;
}

function auth_webauthn() {
    require_once __DIR__.'/webauthn/WebAuthn.php';
    return new \lbuchs\WebAuthn\WebAuthn('Reqad', auth_rp_id(), null, true);
}

function auth_b64u_encode($bin) {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function auth_b64u_decode($s) {
    $s = strtr((string)$s, '-_', '+/');
    $bin = base64_decode($s.str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    return $bin === false ? '' : $bin;
}

function auth_passkeys_list($principal_id) {
    return auth_rows('SELECT * FROM auth_passkeys WHERE principal_id = :p ORDER BY created_at', array('p' => (int)$principal_id));
}

/*
 * Passkey logins allowed (Settings > Security switch, off by default). Servers
 * that already had passkeys before the switch existed keep them working until
 * the switch is used.
 */
function auth_passkeys_on() {
    $v = auth_setting('auth-passkeys', '');
    if ($v !== '')
        return $v === '1';
    return auth_passkeys_exist();
}

/* Any admin passkey on this server (the login page offers the button only
   then). Accounts' passkeys (the account panel on 2083) do not count. */
function auth_passkeys_exist() {
    return auth_ready() && (bool)auth_db()->querySingle("SELECT 1 FROM sqlite_master WHERE type='table' AND name='auth_passkeys'")
        && (bool)auth_db()->querySingle("SELECT 1 FROM auth_passkeys k JOIN auth_principals p ON p.id = k.principal_id
                                         WHERE p.type = 'admin' LIMIT 1");
}

function auth_passkey_remove($id, $principal_id) {
    auth_q('DELETE FROM auth_passkeys WHERE id = :id AND principal_id = :p', array('id' => (int)$id, 'p' => (int)$principal_id));
}

function auth_passkey_challenge_put($key, $purpose, $bin) {
    $now = time();
    auth_q('DELETE FROM auth_challenges WHERE (session_id = :s AND purpose = :p) OR expires_at <= :now',
           array('s' => (string)$key, 'p' => $purpose, 'now' => $now));
    auth_q('INSERT INTO auth_challenges (session_id, purpose, code_hash, sent_at, expires_at) VALUES (:s, :p, :h, :ts, :exp)',
           array('s' => (string)$key, 'p' => $purpose, 'h' => bin2hex($bin), 'ts' => $now, 'exp' => $now + 300));
}

/* The challenge, removed so it can be answered once only; '' when none. */
function auth_passkey_challenge_take($key, $purpose) {
    $c = auth_challenge($key, $purpose);
    if (!$c)
        return '';
    auth_challenge_drop($key, $purpose);
    return (string)hex2bin($c['code_hash']);
}

/* The browser says where the ceremony ran; it must be exactly this panel. */
function auth_passkey_origin_ok($client_data_json) {
    $cd = json_decode($client_data_json, true);
    $origin = 'https://'.strtolower((string)$_SERVER['HTTP_HOST']);
    return is_array($cd) && isset($cd['origin']) && $cd['origin'] === $origin;
}

/* Options for navigator.credentials.create() for $p; challenge kept under $key. */
function auth_passkey_create_args(array $p, $key) {
    if ($p['webauthn_user'] === '') {
        $p['webauthn_user'] = bin2hex(random_bytes(16));
        auth_q('UPDATE auth_principals SET webauthn_user = :h WHERE id = :id', array('h' => $p['webauthn_user'], 'id' => (int)$p['id']));
    }
    $wa = auth_webauthn();
    $have = array();
    foreach (auth_passkeys_list($p['id']) as $k)
        $have[] = auth_b64u_decode($k['credential_id']);
    // discoverable (no user name to type), user verification required
    $args = $wa->getCreateArgs(hex2bin($p['webauthn_user']), $p['username'], $p['username'].' - Reqad '.php_uname('n'),
                               120, 'required', true, null, $have);
    $args->publicKey->attestation = 'none';     // which brand of device it is does not matter here
    unset($args->publicKey->extensions);
    auth_passkey_challenge_put($key, 'passkey-add', $wa->getChallenge()->getBinaryString());
    return $args;
}

/* Store a new passkey from the browser's answer. '' when added, else why not. */
function auth_passkey_register(array $p, $key, $client_data_json, $attestation_object, $name) {
    $challenge = auth_passkey_challenge_take($key, 'passkey-add');
    if ($challenge === '')
        return 'The request expired. Please try again.';
    if (!auth_passkey_origin_ok($client_data_json))
        return 'The passkey was made for another address.';
    try {
        $d = auth_webauthn()->processCreate($client_data_json, $attestation_object, $challenge, true, true, false, false);
    } catch (\Throwable $e) {
        return 'The passkey was not accepted ('.$e->getMessage().').';
    }
    $name = trim(preg_replace('/[^\p{L}\p{N} ._()\'@+-]/u', '', (string)$name));
    if ($name === '')
        $name = auth_ua_label(auth_ua());
    auth_q('INSERT INTO auth_passkeys (principal_id, credential_id, public_key, sign_count, name, aaguid, created_at)
            VALUES (:p, :c, :k, :n, :name, :a, :ts)',
           array('p' => (int)$p['id'], 'c' => auth_b64u_encode($d->credentialId), 'k' => (string)$d->credentialPublicKey,
                 'n' => (int)$d->signatureCounter, 'name' => mb_substr($name, 0, 60), 'a' => bin2hex((string)$d->AAGUID),
                 'ts' => time()));
    auth_log_event('passkey_added', $name, (int)$p['id'], $p['username']);
    return '';
}

/* Options for navigator.credentials.get(): any passkey of this server (login),
   or only $p's (confirming a change). Challenge kept under $key. */
function auth_passkey_get_args($key, $purpose, $p = null) {
    $wa = auth_webauthn();
    $ids = array();
    if ($p)
        foreach (auth_passkeys_list($p['id']) as $k)
            $ids[] = auth_b64u_decode($k['credential_id']);
    $args = $wa->getGetArgs($ids, 120, true, true, true, true, true, true);
    auth_passkey_challenge_put($key, $purpose, $wa->getChallenge()->getBinaryString());
    return $args;
}

/*
 * Check the browser's answer. Returns the passkey row (with username) when the
 * signature is right - of $principal_id's passkeys only, when given - else the
 * message to show.
 */
function auth_passkey_verify($key, $purpose, $credential_id, $client_data_json, $authenticator_data, $signature, $principal_id = 0) {
    $challenge = auth_passkey_challenge_take($key, $purpose);
    if ($challenge === '')
        return 'The request expired. Please try again.';
    $k = auth_row('SELECT k.*, p.username, p.status FROM auth_passkeys k JOIN auth_principals p ON p.id = k.principal_id
                   WHERE k.credential_id = :c', array('c' => (string)$credential_id));
    if (!$k || ($principal_id && (int)$k['principal_id'] !== (int)$principal_id))
        return 'This passkey is not registered on this panel (it may have been removed).';
    if (!auth_passkey_origin_ok($client_data_json))
        return 'The passkey answered for another address.';
    $wa = auth_webauthn();
    try {
        $wa->processGet($client_data_json, $authenticator_data, $signature, $k['public_key'], $challenge, (int)$k['sign_count'], true, true);
    } catch (\Throwable $e) {
        return 'The passkey could not be verified ('.$e->getMessage().').';
    }
    $n = $wa->getSignatureCounter();
    auth_q('UPDATE auth_passkeys SET sign_count = :n, last_used = :ts, last_ip = :ip WHERE id = :id',
           array('n' => $n === null ? (int)$k['sign_count'] : (int)$n, 'ts' => time(), 'ip' => auth_ip(), 'id' => (int)$k['id']));
    return $k;
}

/* ---- brute-force protection ------------------------------------------------- */

function auth_attempt_record($ip, $username, $outcome) {
    auth_q('INSERT INTO auth_attempts (ts, ip, username, outcome) VALUES (:ts, :ip, :u, :o)',
           array('ts' => time(), 'ip' => $ip, 'u' => substr((string)$username, 0, 64), 'o' => $outcome));
    if (mt_rand(1, 50) === 1)
        auth_q('DELETE FROM auth_attempts WHERE ts < :old', array('old' => time() - 30 * 86400));
}

function auth_failures_since($ip, $since) {
    return (int)auth_db()->querySingle('SELECT COUNT(*) FROM auth_attempts WHERE ip = '
        .auth_sql_str($ip).' AND ts >= '.(int)$since.' AND outcome IN ('.AUTH_FAIL_OUTCOMES.')');
}

function auth_sql_str($s) {
    return "'".SQLite3::escapeString((string)$s)."'";
}

/* Seconds this IP is still locked out of the login form; 0 when it may try. */
function auth_ip_locked($ip) {
    $window = 60 * auth_cfg('auth-lock-minutes');
    $since = time() - $window;
    if (auth_failures_since($ip, $since) < auth_cfg('auth-lock-failures'))
        return 0;
    $last = (int)auth_db()->querySingle('SELECT MAX(ts) FROM auth_attempts WHERE ip = '.auth_sql_str($ip)
        .' AND outcome IN ('.AUTH_FAIL_OUTCOMES.')');
    return max(0, $last + $window - time());
}

/* The server's own addresses: never banned (the panel would ban itself). */
function auth_server_ips() {
    static $ips = null;
    if ($ips === null) {
        $ips = array('127.0.0.1', '::1');
        foreach (preg_split('/\s+/', trim((string)@shell_exec('hostname -I 2>/dev/null'))) as $a)
            if ($a !== '')
                $ips[] = $a;
    }
    return $ips;
}

/* Not to be handed to csf: the server itself, an allowlisted address, or
   someone logged in right now. */
function auth_ip_exempt($ip) {
    if (in_array($ip, auth_server_ips(), true) || auth_fw_allowed($ip))
        return true;
    return (bool)auth_db()->querySingle("SELECT 1 FROM auth_sessions WHERE state = 'active' AND ip = ".auth_sql_str($ip));
}

/*
 * After a failed attempt: escalate to csf when one IP keeps failing, and note
 * a distributed attack on one username. Runs after the failure is recorded.
 */
function auth_after_failure($ip, $username) {
    if (auth_failures_since($ip, time() - 3600) >= auth_cfg('auth-ban-failures') && !auth_ip_exempt($ip)) {
        $ban = auth_row('SELECT * FROM auth_bans WHERE ip = :ip', array('ip' => $ip));
        $recent = ($ban && (int)$ban['updated_at'] > time() - 7 * 86400) ? (int)$ban['count'] : 0;
        $active = $ban && ((int)$ban['level'] === 2 || (int)$ban['until'] > time());
        if (!$active) {
            if ($recent + 1 >= auth_cfg('auth-permban-after'))
                auth_ban($ip, 2, 0, 'repeated panel login failures', $recent + 1);
            else
                auth_ban($ip, 1, auth_cfg('auth-ban-seconds'), 'panel login failures', $recent + 1);
        }
    }
    if ($username !== '') {
        $since = time() - 3600;
        $ips = (int)auth_db()->querySingle('SELECT COUNT(DISTINCT ip) FROM auth_attempts WHERE username = '
            .auth_sql_str($username).' AND ts >= '.$since.' AND outcome IN ('.AUTH_FAIL_OUTCOMES.')');
        if ($ips >= auth_cfg('auth-distributed-ips')) {
            $logged = (int)auth_db()->querySingle("SELECT COUNT(*) FROM auth_log WHERE event = 'distributed_attack' AND username = "
                .auth_sql_str($username).' AND ts >= '.$since);
            if (!$logged)
                auth_log_event('distributed_attack', "$ips IPs failed to log in as this user within an hour", 0, $username);
        }
    }
}

/* Hand an IP to csf (scripts/auth_ban.sh validates and refuses exempt ones). */
function auth_ban($ip, $level, $seconds, $reason, $count = 1) {
    if (!filter_var($ip, FILTER_VALIDATE_IP))
        return false;
    $cmd = $level === 2
        ? 'sudo '._PATH.'/scripts/auth_ban.sh perm '.escapeshellarg($ip).' '.escapeshellarg('Reqad: '.$reason)
        : 'sudo '._PATH.'/scripts/auth_ban.sh temp '.escapeshellarg($ip).' '.(int)$seconds.' '.escapeshellarg('Reqad: '.$reason);
    exec($cmd.' 2>&1', $lines, $rc);
    $out = trim(implode(' ', $lines));
    if ($rc !== 0) {    // refused (allowlisted, the server itself) or csf failed
        auth_log_event('ban_refused', $reason.' - '.$out);
        return false;
    }
    $now = time();
    auth_q('INSERT INTO auth_bans (ip, level, until, count, reason, created_at, updated_at)
            VALUES (:ip, :lv, :until, :c, :r, :ts, :ts)
            ON CONFLICT(ip) DO UPDATE SET level = :lv, until = :until, count = :c, reason = :r, updated_at = :ts',
           array('ip' => $ip, 'lv' => (int)$level, 'until' => $level === 2 ? 0 : $now + (int)$seconds,
                 'c' => (int)$count, 'r' => $reason, 'ts' => $now));
    auth_log_event($level === 2 ? 'ban_permanent' : 'ban_temporary',
                   $reason.($level === 2 ? '' : ' ('.(int)$seconds.'s)').($out !== '' ? ' - '.$out : ''));
    return true;
}

function auth_unban($ip) {
    if (!filter_var($ip, FILTER_VALIDATE_IP))
        return false;
    $out = trim((string)shell_exec('sudo '._PATH.'/scripts/auth_ban.sh remove '.escapeshellarg($ip).' 2>&1'));
    auth_q('DELETE FROM auth_bans WHERE ip = :ip', array('ip' => $ip));
    auth_q('DELETE FROM auth_attempts WHERE ip = :ip', array('ip' => $ip));
    auth_log_event('unban', $ip.($out !== '' ? ' - '.$out : ''));
    return true;
}

/* Bans still in force, plus IPs currently locked out of the form. */
function auth_blocked_list() {
    $out = array();
    foreach (auth_rows('SELECT * FROM auth_bans WHERE level = 2 OR until > :now ORDER BY updated_at DESC',
                       array('now' => time())) as $b)
        $out[$b['ip']] = array('ip' => $b['ip'], 'kind' => (int)$b['level'] === 2 ? 'csf permanent' : 'csf temporary',
                               'until' => (int)$b['until'], 'reason' => $b['reason']);
    $since = time() - 60 * auth_cfg('auth-lock-minutes');
    foreach (auth_rows('SELECT ip, COUNT(*) AS n FROM auth_attempts WHERE ts >= :s AND outcome IN ('.AUTH_FAIL_OUTCOMES.')
                        GROUP BY ip HAVING n >= :min', array('s' => $since, 'min' => auth_cfg('auth-lock-failures'))) as $r)
        if (!isset($out[$r['ip']]) && ($left = auth_ip_locked($r['ip'])) > 0)
            $out[$r['ip']] = array('ip' => $r['ip'], 'kind' => 'login form', 'until' => time() + $left,
                                   'reason' => $r['n'].' failed attempts');
    foreach (auth_webmail_blocks() as $b)
        if (!isset($out[$b['ip']]))
            $out[$b['ip']] = $b;
    return array_values($out);
}

/* Webmail login blocks made by lfd (rule "reqadwebmail" in regex.custom.pm,
   scripts/update/setup_webmail_guard.sh): temporary ones from `csf -t`,
   permanent ones (lfd's LF_PERMBLOCK) from csf.deny. auth_unban() lifts both. */
function auth_webmail_blocks() {
    $out = array();
    foreach (preg_split('/\n/', (string)@shell_exec('sudo csf -t 2>/dev/null')) as $l) {
        if (strpos($l, '(reqadwebmail)') === false || !preg_match('/^DENY\s+(\S+)\s+\S+\s+\S+\s+(.*?)\s+lfd/', $l, $m)
            || !filter_var($m[1], FILTER_VALIDATE_IP))
            continue;
        $left = 0;
        foreach (array('d' => 86400, 'h' => 3600, 'm' => 60, 's' => 1) as $u => $mul)
            if (preg_match('/(\d+)'.$u.'\b/', $m[2], $t))
                $left += (int)$t[1] * $mul;
        $out[] = array('ip' => $m[1], 'kind' => 'csf temporary', 'until' => time() + $left,
                       'reason' => preg_match('/: (\d+ in the last \d+ secs)/', $l, $r) ? 'webmail login failures ('.$r[1].')' : 'webmail login failures');
    }
    foreach (preg_split('/\n/', (string)@shell_exec("sudo grep -F '(reqadwebmail)' /etc/csf/csf.deny 2>/dev/null")) as $l)
        if (preg_match('/^(\S+)\s*#/', $l, $m) && filter_var($m[1], FILTER_VALIDATE_IP))
            $out[] = array('ip' => $m[1], 'kind' => 'csf permanent', 'until' => 0, 'reason' => 'repeated webmail login failures');
    return $out;
}

/* ---- panel firewall allowlist (csf) -----------------------------------------
 * When on, port 2087 is out of csf's TCP_IN/TCP6_IN and only the listed
 * addresses reach it (scripts/panel_fw_allow.sh). The saved list - table
 * auth_ip_allow, scope 'firewall' - can be edited while it is off;
 * while it is on, csf.allow is what is enforced and the page brings the
 * saved list in line with it. Every change that tightens the rules reverts
 * itself after two minutes unless it is confirmed.
 */

function auth_setting_put($name, $value) {
    auth_q('INSERT INTO settings (name, value, updated_at) VALUES (:n, :v, datetime("now"))
            ON CONFLICT(name) DO UPDATE SET value = :v, updated_at = datetime("now")',
           array('n' => $name, 'v' => (string)$value));
}

function auth_fw_run($args) {
    exec('sudo '._PATH.'/scripts/panel_fw_allow.sh '.$args.' 2>&1', $lines, $rc);
    return array($rc, trim(implode(' ', $lines)));
}

/* What csf enforces: csf, on, port, port_open, rules [{cidr, note}], pending
   (seconds until the automatic revert, -1 when none). */
function auth_fw_status() {
    list($rc, $out) = auth_fw_run('status');
    $st = json_decode($out, true);
    if ($rc !== 0 || !is_array($st))
        return array('csf' => false, 'error' => $out);
    return $st;
}

function auth_fw_list() {
    return auth_rows("SELECT cidr, label AS note FROM auth_ip_allow WHERE scope = 'firewall' ORDER BY id");
}

/* Replace the saved list; rows that stay keep their created_at. */
function auth_fw_list_save(array $list) {
    $keep = array();
    foreach ($list as $e)
        $keep[] = auth_sql_str($e['cidr']);
    auth_db()->exec("DELETE FROM auth_ip_allow WHERE scope = 'firewall'"
        .($keep ? ' AND cidr NOT IN ('.implode(',', $keep).')' : ''));
    foreach ($list as $e)
        auth_q("INSERT INTO auth_ip_allow (cidr, label, scope, created_at) VALUES (:c, :l, 'firewall', :ts)
                ON CONFLICT(cidr, scope) DO UPDATE SET label = :l",
               array('c' => $e['cidr'], 'l' => auth_fw_note($e['note']), 'ts' => time()));
}

function auth_fw_note($note) {
    return substr(trim(preg_replace('#[^A-Za-z0-9 ._:()@/-]#', '', (string)$note)), 0, 60);
}

/* "203.0.113.7", "203.0.113.0/24", "2001:db8::/48" -> canonical form (network
   address, no /32 or /128); '' when invalid or when it would match everything. */
function auth_fw_normalize($s) {
    $s = trim((string)$s);
    $prefix = null;
    if (strpos($s, '/') !== false) {
        list($s, $prefix) = explode('/', $s, 2);
        if (!ctype_digit($prefix))
            return '';
        $prefix = (int)$prefix;
    }
    $bin = @inet_pton($s);
    if ($bin === false || !filter_var($s, FILTER_VALIDATE_IP))
        return '';
    $bits = strlen($bin) * 8;
    if ($prefix === null)
        $prefix = $bits;
    if ($prefix < 1 || $prefix > $bits)
        return '';
    $net = auth_fw_mask($bin, $prefix);
    return inet_ntop($net).($prefix === $bits ? '' : '/'.$prefix);
}

function auth_fw_mask($bin, $prefix) {
    $out = '';
    for ($i = 0; $i < strlen($bin); $i++) {
        $keep = max(0, min(8, $prefix - 8 * $i));
        $out .= chr(ord($bin[$i]) & ((0xFF << (8 - $keep)) & 0xFF));
    }
    return $out;
}

function auth_ip_in_cidr($ip, $cidr) {
    $bits = null;
    if (strpos($cidr, '/') !== false)
        list($cidr, $bits) = explode('/', $cidr, 2);
    $a = @inet_pton($ip);
    $n = @inet_pton($cidr);
    if ($a === false || $n === false || strlen($a) !== strlen($n))
        return false;
    $bits = $bits === null ? strlen($a) * 8 : (int)$bits;
    return auth_fw_mask($a, $bits) === auth_fw_mask($n, $bits);
}

/* On the saved allowlist (on or off): never handed to csf for login failures. */
function auth_fw_allowed($ip, $list = null) {
    foreach ($list === null ? auth_fw_list() : $list as $e)
        if (auth_ip_in_cidr($ip, $e['cidr']))
            return true;
    return false;
}

/* Allow only $list on the panel port. '' when done, else the error. */
function auth_fw_apply(array $list) {
    if (!$list)
        return 'The allowlist is empty.';
    $args = array();
    foreach ($list as $e)
        $args[] = escapeshellarg($e['cidr'].','.auth_fw_note($e['note']));
    list($rc, $out) = auth_fw_run('on '.implode(' ', $args));
    if ($rc !== 0 || strpos($out, 'error') === 0)
        return 'The firewall was not changed: '.$out;
    if ($out === 'nocsf')
        return 'csf is not installed, so there is no firewall to change.';
    if ($out === 'pending')
        auth_setting_put('panel-fw-pending', (string)time());
    auth_log_event('fw_allow_on', implode(', ', array_column($list, 'cidr')).' - '.$out);
    return '';
}

function auth_fw_off() {
    list($rc, $out) = auth_fw_run('off');
    auth_q("DELETE FROM settings WHERE name = 'panel-fw-pending'");
    if ($rc !== 0 || strpos($out, 'error') === 0)
        return 'The firewall was not changed: '.$out;
    auth_log_event('fw_allow_off', 'port 2087 open to every address again - '.$out);
    return '';
}

/* Keep (confirm) or undo (revert) the pending change. */
function auth_fw_settle($keep) {
    list($rc, $out) = auth_fw_run($keep ? 'confirm' : 'revert');
    auth_q("DELETE FROM settings WHERE name = 'panel-fw-pending'");
    auth_log_event($keep ? 'fw_allow_confirmed' : 'fw_allow_reverted', $out);
    return $rc === 0;
}

/* ---- TOTP (RFC 6238) and recovery codes ------------------------------------ */

function auth_base32_encode($bin) {
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bin) as $c)
        $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $chunk)
        $out .= $alpha[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}

function auth_base32_decode($b32) {
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32));
    $bits = '';
    foreach (str_split($b32) as $c)
        $bits .= str_pad(decbin(strpos($alpha, $c)), 5, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 8) as $byte)
        if (strlen($byte) === 8)
            $out .= chr(bindec($byte));
    return $out;
}

function auth_totp_new_secret() {
    return auth_base32_encode(random_bytes(20));
}

function auth_totp_code($secret_b32, $step) {
    $key = auth_base32_decode($secret_b32);
    $msg = pack('N*', 0, $step);        // 64-bit big-endian counter (high word 0)
    $h = hash_hmac('sha1', $msg, $key, true);
    $o = ord($h[19]) & 0x0f;
    $n = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string)($n % 1000000), 6, '0', STR_PAD_LEFT);
}

/* The step a code matches within +-1 step (30s each), or 0. */
function auth_totp_match($secret_b32, $code, $after_step = 0) {
    $code = preg_replace('/\s+/', '', (string)$code);
    if (!preg_match('/^\d{6}$/', $code) || $secret_b32 === '')
        return 0;
    $now = intdiv(time(), 30);
    for ($s = $now - 1; $s <= $now + 1; $s++)
        if ($s > $after_step && hash_equals(auth_totp_code($secret_b32, $s), $code))
            return $s;
    return 0;
}

/* Verify a login/confirmation code for $p; a code is accepted once only. */
function auth_totp_verify(array $p, $code) {
    $step = auth_totp_match($p['totp_secret'], $code, (int)$p['totp_last_step']);
    if (!$step)
        return false;
    auth_q('UPDATE auth_principals SET totp_last_step = :s WHERE id = :id', array('s' => $step, 'id' => (int)$p['id']));
    return true;
}

/*
 * otpauth:// URI for the QR code. Authenticator apps show "issuer: account",
 * so the account part is the server's hostname - "Reqad: v182.example.com" -
 * which tells several Reqad servers apart. The user name is added only when
 * the server has more than one admin.
 */
function auth_totp_uri($secret, $username) {
    $account = php_uname('n');
    if (count(auth_admins()) > 1)
        $account .= ' ('.$username.')';
    $label = rawurlencode('Reqad').':'.rawurlencode($account);
    return 'otpauth://totp/'.$label.'?secret='.$secret.'&issuer='.rawurlencode('Reqad').'&algorithm=SHA1&digits=6&period=30';
}

/* Replace $p's recovery codes; returns the new plain codes (shown once). */
function auth_recovery_generate($principal_id) {
    auth_q('DELETE FROM auth_recovery_codes WHERE principal_id = :p', array('p' => (int)$principal_id));
    $alpha = 'abcdefghjkmnpqrstuvwxyz23456789';
    $codes = array();
    for ($i = 0; $i < 10; $i++) {
        $c = '';
        for ($j = 0; $j < 10; $j++)
            $c .= $alpha[random_int(0, strlen($alpha) - 1)];
        $c = substr($c, 0, 5).'-'.substr($c, 5);
        $codes[] = $c;
        auth_q('INSERT INTO auth_recovery_codes (principal_id, code_hash) VALUES (:p, :h)',
               array('p' => (int)$principal_id, 'h' => hash('sha256', $c)));
    }
    return $codes;
}

function auth_recovery_use($principal_id, $code) {
    $code = strtolower(preg_replace('/[^A-Za-z0-9]/', '', (string)$code));
    if (strlen($code) !== 10)
        return false;
    $code = substr($code, 0, 5).'-'.substr($code, 5);
    $row = auth_row('SELECT id FROM auth_recovery_codes WHERE principal_id = :p AND code_hash = :h AND used_at = 0',
                    array('p' => (int)$principal_id, 'h' => hash('sha256', $code)));
    if (!$row)
        return false;
    auth_q('UPDATE auth_recovery_codes SET used_at = :ts WHERE id = :id', array('ts' => time(), 'id' => (int)$row['id']));
    return true;
}

function auth_recovery_remaining($principal_id) {
    return (int)auth_db()->querySingle('SELECT COUNT(*) FROM auth_recovery_codes WHERE used_at = 0 AND principal_id = '.(int)$principal_id);
}

function auth_totp_disable($principal_id) {
    auth_q("UPDATE auth_principals SET totp_enabled = 0, totp_secret = '', totp_pending = '', totp_last_step = 0 WHERE id = :id",
           array('id' => (int)$principal_id));
    auth_q('DELETE FROM auth_recovery_codes WHERE principal_id = :p', array('p' => (int)$principal_id));
    auth_devices_untrust_all($principal_id);
}

/* ---- request gate (index.php) ----------------------------------------------- */

/* A same-site path to return to after login; '/' when $next is anything else. */
function auth_safe_next($next) {
    $next = (string)$next;
    if ($next === '' || $next[0] !== '/' || strpos($next, '//') === 0 || strpos($next, '\\') !== false
        || preg_match('/[\x00-\x20\x7f]/', $next) || preg_match('#^/(login|logout)(/|$|\?)#', $next))
        return '/';
    return $next;
}

/*
 * Login-page state that used to ride in the query string (?next=, ?bye=1)
 * lives in short-lived cookies instead, so the login URL stays /login/ and
 * nothing ends up in history, bookmarks or access logs.
 */
function auth_next_set($path) {
    $path = auth_safe_next($path);
    if ($path === '/')
        auth_cookie(AUTH_NEXT_COOKIE, '', time() - 3600);
    else
        auth_cookie(AUTH_NEXT_COOKIE, $path, time() + 900);
}

function auth_next_peek() {
    return auth_safe_next(isset($_COOKIE[AUTH_NEXT_COOKIE]) && is_string($_COOKIE[AUTH_NEXT_COOKIE]) ? $_COOKIE[AUTH_NEXT_COOKIE] : '/');
}

function auth_next_take() {
    $next = auth_next_peek();
    if (isset($_COOKIE[AUTH_NEXT_COOKIE]))
        auth_cookie(AUTH_NEXT_COOKIE, '', time() - 3600);
    return $next;
}

/* One notice for the next login page view: 'bye' (logged out) or 'revoked'. */
function auth_flash_set($key) {
    auth_cookie(AUTH_FLASH_COOKIE, $key, time() + 60);
}

function auth_flash_take() {
    $v = isset($_COOKIE[AUTH_FLASH_COOKIE]) && is_string($_COOKIE[AUTH_FLASH_COOKIE]) ? $_COOKIE[AUTH_FLASH_COOKIE] : '';
    if ($v !== '')
        auth_cookie(AUTH_FLASH_COOKIE, '', time() - 3600);
    return in_array($v, array('bye', 'revoked'), true) ? $v : '';
}

function auth_redirect($path) {
    header('Location: '.$path, true, 302);
    header('Cache-Control: no-store');
    exit;
}

/*
 * Runs at the top of index.php. Basic-auth mode: nothing to do (nginx has
 * already authenticated the request). Web mode: /login is handled here, POST
 * /logout ends the session, and everything else needs an active session.
 */
function auth_gate() {
    if (!auth_web_enabled())
        return;
    $path = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

    if (preg_match('#^/login(/|$)#', $path)) {
        include _PATH.'/public_html/modules/login.php';
        exit;
    }

    $s = auth_session_current();
    if (!$s) {
        if (auth_is_ajax()) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(array('error' => 'Your session has ended. Please log in again.', 'login' => true));
            exit;
        }
        auth_next_set($_SERVER['REQUEST_URI']);
        auth_redirect('/login/');
    }

    if (preg_match('#^/logout/?$#', $path)) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST')
            auth_redirect('/');
        csrf_check();
        auth_log_event('logout', '', (int)$s['principal_id'], $s['username']);
        auth_session_destroy();
        auth_flash_set('bye');
        auth_redirect('/login/');
    }
}
