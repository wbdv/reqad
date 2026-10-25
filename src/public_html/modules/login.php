<?php
/*
 * /login/ and /login/2fa/ - only reached in web-login mode (auth_gate()).
 *
 * /login/email/ - a new device confirming the login with an emailed code.
 * /login/passkey/ - JSON: options for, then the answer of, a passkey login.
 *
 * Password step: lockout check -> constant-time verify -> either an active
 * session, or (2FA on) a short-lived 'pending' session that only the code
 * step accepts. Without 2FA, a browser the panel has not seen before waits in a
 * 'confirm' session for a code emailed to the admin, when that check is on
 * (Settings > Security). Failures are recorded and escalate to csf in
 * auth_after_failure(). The form is protected against login CSRF by a nonce
 * held in a SameSite=Strict cookie and echoed in the form.
 */

header('X-Frame-Options: DENY');
header("Content-Security-Policy: frame-ancestors 'none'");
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

auth_bootstrap();

$path       = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$login_step = preg_match('#^/login/2fa/?$#', $path) ? '2fa' : (preg_match('#^/login/email/?$#', $path) ? 'email' : 'password');
$qs         = isset($_SERVER['QUERY_STRING']) ? (string)$_SERVER['QUERY_STRING'] : '';

/*
 * Keep the login URL clean: anything arriving in the query string (nginx's
 * redirect adds ?next=<page>, old links may carry ?bye=1 / ?cancel=1) is
 * moved into short-lived cookies and the browser is sent to the bare URL.
 * next= is always the last parameter and may itself contain & and ?, so
 * everything after it is taken. ?bg= (background picker) is kept.
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && preg_match('/(^|&)(next|bye|cancel)=/', $qs)) {
    if (($p = strpos($qs, 'next=')) !== false)
        auth_next_set(rawurldecode(substr($qs, $p + 5)));
    if (isset($_GET['bye']))
        auth_flash_set('bye');
    if (isset($_GET['cancel']))
        auth_session_destroy();
    $keep = isset($_GET['bg']) && preg_match('/^\d$/', $_GET['bg']) ? '?bg='.$_GET['bg'] : '';
    auth_redirect((isset($_GET['cancel']) ? '/login/' : $path).$keep);
}

$login_next  = auth_next_peek();
$login_error = '';
$login_flash = auth_flash_take();
$login_info  = $login_flash === 'bye' ? 'You have been logged out.'
             : ($login_flash === 'revoked' ? 'This session was logged out.' : '');
$login_username = '';
$login_field_errors = array();     // field => message, shown under the field (empty inputs)
$ip = auth_ip();

if (auth_session_current())
    auth_redirect(auth_next_take());


// Login-CSRF nonce.
$login_nonce = auth_cookie_value(AUTH_LOGIN_COOKIE);
if ($login_nonce === '') {
    $login_nonce = bin2hex(random_bytes(32));
    auth_cookie(AUTH_LOGIN_COOKIE, $login_nonce, 0);
}

function login_fail_pause() {
    usleep(random_int(800000, 1500000));    // slows guessing, hides which check failed
}

/* Empty required fields: a typo, not a guess - shown under the field and not
   counted as a failed attempt. The page's own JS catches these before submit;
   this covers browsers without it. */
function login_missing_fields($step) {
    $v = function ($k) { return isset($_POST[$k]) && is_string($_POST[$k]) ? trim($_POST[$k]) : ''; };
    $e = array();
    if ($step === '2fa') {
        if ($v('code') === '') $e['code'] = 'Enter the code from your authenticator app.';
        return $e;
    }
    if ($step === 'email') {
        if ($v('code') === '' && empty($_POST['resend'])) $e['code'] = 'Enter the code from the email.';
        return $e;
    }
    if ($v('username') === '') $e['username'] = 'Enter your user name.';
    if (!isset($_POST['password']) || !is_string($_POST['password']) || $_POST['password'] === '')
        $e['password'] = 'Enter your password.';
    return $e;
}

/*
 * Logged in: remember this browser (a known device from now on, and with
 * $trust it skips the 2FA code), log it, go where the user was heading. A
 * login from a new device that did not confirm by email triggers the "new
 * login" email when that is on - sent after the browser has its redirect.
 */
function login_finish(array $p, $how, $trust = false, $alert_ok = true, $json = false) {
    $new = !auth_device_known($p);
    $dev = auth_device_seen($p, $trust);
    auth_q('UPDATE auth_sessions SET device_id = :d WHERE id = :id',
           array('d' => $dev, 'id' => hash('sha256', auth_cookie_value(AUTH_COOKIE))));
    if ($trust)
        $how .= ', device remembered for '.auth_cfg('auth-trust-days').' days';
    auth_attempt_record(auth_ip(), $p['username'], 'ok');
    auth_log_event('login_ok', $how.($new ? ' (new device)' : ''), (int)$p['id'], $p['username']);
    $next  = auth_next_take();
    $alert = $new && $alert_ok && auth_login_alert_on();
    if ($json) {                // passkey login: the page's script goes there
        echo json_encode(array('ok' => true, 'next' => $next));
        if (!$alert)
            exit;
    } else {
        if (!$alert)
            auth_redirect($next);
        header('Location: '.$next, true, 302);
        header('Cache-Control: no-store');
    }
    if (function_exists('fastcgi_finish_request'))
        fastcgi_finish_request();
    auth_mail_login_alert($p, $how);
    exit;
}

/*
 * Password right, no 2FA, and a new device: hold the session in 'confirm'
 * until the code emailed to the admin comes back. When the email cannot be
 * sent at all (SMTP down or misconfigured) the login goes through on the
 * password, as it would without a sending method - a broken mail relay must
 * not lock the admin out. That is logged, and only a send failure does it:
 * a wrong code never gets past.
 */
function login_start_confirm(array $p, array $sess) {
    auth_q("UPDATE auth_sessions SET state = 'confirm', mfa = 0, expires_at = :exp WHERE id = :id",
           array('exp' => time() + 60 * auth_cfg('auth-code-minutes'), 'id' => $sess['id']));
    $err = auth_challenge_send($sess['id'], 'login', $p['email']);
    if ($err !== '') {
        auth_log_event('email_code_skipped', 'new device let in on the password: '.$err, (int)$p['id'], $p['username']);
        auth_challenge_drop($sess['id'], 'login');
        auth_session_activate($sess, false, true);
        login_finish($p, 'password (email code could not be sent)');
    }
    auth_log_event('login_new_device', 'code emailed to '.$p['email'], (int)$p['id'], $p['username']);
    auth_redirect('/login/email/');
}

function login_lock_message($seconds) {
    $m = max(1, (int)ceil($seconds / 60));
    return "Too many failed attempts from your address. Try again in $m minute".($m == 1 ? '' : 's').'.';
}

/*
 * Passkey login (POST, JSON). op=options hands out a challenge tied to this
 * browser's login nonce; op=verify checks the signed answer. A passkey is a
 * complete login by itself - something you have, unlocked by something you
 * know or are - so it needs neither the 2FA code nor the emailed code, and it
 * counts as a fresh confirmation for sensitive changes.
 */
if (preg_match('#^/login/passkey/?$#', $path)) {
    header('Content-Type: application/json');
    $jfail = function ($msg) { echo json_encode(array('error' => $msg)); exit; };
    $posted_nonce = isset($_POST['lc']) && is_string($_POST['lc']) ? $_POST['lc'] : '';
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $posted_nonce === '' || !hash_equals($login_nonce, $posted_nonce))
        $jfail('The login form expired. Please reload the page.');
    if (!auth_ready() || auth_rp_id() === '')
        $jfail('Passkeys need the panel to be opened by its hostname.');
    if (!auth_passkeys_on())
        $jfail('Passkey login is turned off.');
    if (($left = auth_ip_locked($ip)) > 0)
        $jfail(login_lock_message($left));
    $key = 'lc:'.hash('sha256', $login_nonce);
    $op  = isset($_POST['op']) ? (string)$_POST['op'] : '';
    if ($op === 'options') {
        echo json_encode(auth_passkey_get_args($key, 'passkey-login'));
        exit;
    }
    $pv = function ($k) { return isset($_POST[$k]) && is_string($_POST[$k]) ? auth_b64u_decode(substr($_POST[$k], 0, 8192)) : ''; };
    $cid = isset($_POST['id']) && is_string($_POST['id']) ? substr($_POST['id'], 0, 1400) : '';
    $k = auth_passkey_verify($key, 'passkey-login', $cid, $pv('clientDataJSON'), $pv('authenticatorData'), $pv('signature'));
    $p = is_array($k) ? auth_principal_by_id($k['principal_id']) : null;
    if (!$p || $p['status'] !== 'active' || $p['type'] !== 'admin') {     // an account's passkey is for 2083 only
        $why = is_string($k) ? $k : 'This login is disabled.';
        auth_attempt_record($ip, is_array($k) ? $k['username'] : '', 'bad_passkey');
        auth_log_event('login_fail', 'bad_passkey: '.$why, $p ? (int)$p['id'] : 0, is_array($k) ? $k['username'] : '');
        auth_after_failure($ip, is_array($k) ? $k['username'] : '');
        login_fail_pause();
        $jfail($why);
    }
    $sess = auth_session_create($p, 'active', true);
    auth_q('UPDATE auth_sessions SET mfa = 3 WHERE id = :id', array('id' => $sess['id']));
    login_finish($p, 'passkey "'.$k['name'].'"', false, true, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_nonce = isset($_POST['lc']) && is_string($_POST['lc']) ? $_POST['lc'] : '';
    if (!auth_ready()) {
        $login_error = 'The login tables are missing - run the Reqad update (database 1037).';
    } elseif ($posted_nonce === '' || !hash_equals($login_nonce, $posted_nonce)) {
        $login_error = 'The login form expired. Please try again.';
    } elseif (($left = auth_ip_locked($ip)) > 0) {
        auth_attempt_record($ip, isset($_POST['username']) ? (string)$_POST['username'] : '', 'locked');
        $login_error = login_lock_message($left);
    } elseif (($login_field_errors = login_missing_fields($login_step))) {
        $login_username = isset($_POST['username']) && is_string($_POST['username']) ? substr(trim($_POST['username']), 0, 64) : '';
        if (($login_step === '2fa' && !auth_session_pending()) || ($login_step === 'email' && !auth_session_confirming())) {
            $login_step = 'password';
            $login_field_errors = array();
            $login_error = 'Your login took too long. Please enter your password again.';
        }
    } elseif ($login_step === 'password') {
        $login_username = isset($_POST['username']) && is_string($_POST['username']) ? substr(trim($_POST['username']), 0, 64) : '';
        $password       = isset($_POST['password']) && is_string($_POST['password']) ? substr($_POST['password'], 0, 256) : '';
        $p = $login_username !== '' ? auth_principal($login_username) : null;

        if (!$p || $p['status'] !== 'active') {
            auth_dummy_verify($password);
            $ok = false;
            $outcome = 'unknown_user';
        } else {
            $ok = auth_password_verify($p, $password);
            $outcome = $ok ? 'ok' : 'bad_password';
        }

        if (!$ok) {
            auth_attempt_record($ip, $login_username, $outcome);
            auth_log_event('login_fail', $outcome, $p ? (int)$p['id'] : 0, $login_username);
            auth_after_failure($ip, $login_username);
            login_fail_pause();
            $left = auth_ip_locked($ip);
            $login_error = $left > 0 ? login_lock_message($left) : 'Invalid user name or password.';
        } elseif ((int)$p['totp_enabled'] === 1 && ($dev = auth_device_trusted($p))) {
            // 2FA passed on this browser within the trust period: no code. Not a
            // fresh 2FA confirmation, so sensitive changes still ask for it.
            $sess = auth_session_create($p, 'active', false);
            auth_q('UPDATE auth_sessions SET mfa = 2 WHERE id = :id', array('id' => $sess['id']));
            login_finish($p, 'password + trusted device (until '.date('Y-m-d H:i', (int)$dev['trusted_until']).')');
        } elseif ((int)$p['totp_enabled'] === 1) {
            auth_session_create($p, 'pending');
            auth_log_event('login_password_ok', 'waiting for 2FA code', (int)$p['id'], $p['username']);
            auth_redirect('/login/2fa/');
        } elseif (auth_needs_email_code($p)) {
            $sess = auth_session_create($p, 'pending');
            login_start_confirm($p, $sess);
        } else {
            auth_session_create($p, 'active', true);
            login_finish($p, 'password');
        }
    } elseif ($login_step === 'email') {
        $s = auth_session_confirming();
        $p = $s ? auth_principal_by_id($s['principal_id']) : null;
        if (!$s || !$p) {
            $login_step  = 'password';
            $login_error = 'Your login took too long. Please enter your password again.';
        } elseif (!empty($_POST['resend'])) {
            if (($err = auth_challenge_send($s['id'], 'login', $p['email'])) !== '') {
                $login_error = $err;
            } else {
                auth_log_event('login_new_device', 'new code emailed to '.$p['email'], (int)$p['id'], $p['username']);
                $login_info = 'A new code is on its way. Only the latest code works.';
            }
        } else {
            $r = auth_challenge_check($s['id'], 'login', isset($_POST['code']) && is_string($_POST['code']) ? $_POST['code'] : '');
            if (is_array($r)) {
                auth_session_activate($s, false, true);      // password just typed: sudo as usual
                // the code arrived, so the address works: same as "Send a test code"
                if ($p['email'] !== '' && auth_setting('auth-email-verified', '') !== $p['email']) {
                    auth_setting_put('auth-email-verified', $p['email']);
                    auth_log_event('email_verified', $p['email'].' (login code)', (int)$p['id'], $p['username']);
                }
                login_finish($p, 'password + email code', false, false);
            }
            if ($r !== 'expired') {
                auth_attempt_record($ip, $s['username'], 'bad_code');
                auth_log_event('login_fail', 'bad_code', (int)$s['principal_id'], $s['username']);
                auth_after_failure($ip, $s['username']);
                login_fail_pause();
            }
            $left = auth_ip_locked($ip);
            if ($left > 0 || $r === 'locked') {
                auth_session_destroy();
                $login_step  = 'password';
                $login_error = $left > 0 ? login_lock_message($left) : 'Too many wrong codes. Please log in again.';
            } elseif ($r === 'expired') {
                $login_error = 'That code has expired. Send a new one below.';
            } else {
                $login_error = 'That code is not right. Use the code from the latest email.';
            }
        }
    } else {
        // 2FA step
        $s = auth_session_pending();
        if (!$s) {
            $login_step  = 'password';
            $login_error = 'Your login took too long. Please enter your password again.';
        } else {
            $p    = auth_principal_by_id($s['principal_id']);
            $code = isset($_POST['code']) && is_string($_POST['code']) ? trim($_POST['code']) : '';
            $how  = '';
            if ($p && preg_match('/^\d{3}\s?\d{3}$/', $code) && auth_totp_verify($p, $code))
                $how = 'authenticator code';
            elseif ($p && strlen(preg_replace('/[^A-Za-z0-9]/', '', $code)) === 10 && auth_recovery_use($p['id'], $code))
                $how = 'recovery code ('.auth_recovery_remaining($p['id']).' left)';

            if ($how === '') {
                auth_attempt_record($ip, $s['username'], 'bad_2fa');
                auth_log_event('login_fail', 'bad_2fa', (int)$s['principal_id'], $s['username']);
                auth_after_failure($ip, $s['username']);
                login_fail_pause();
                $left = auth_ip_locked($ip);
                if ($left > 0) {
                    auth_session_destroy();
                    $login_step  = 'password';
                    $login_error = login_lock_message($left);
                } else {
                    $login_error = 'That code is not valid. Check your authenticator app and try again.';
                }
            } else {
                auth_session_activate($s, true);
                login_finish($p, 'password + '.$how, !empty($_POST['remember']));
            }
        }
    }
} elseif ($login_step === '2fa' && !auth_session_pending()) {
    auth_redirect('/login/');
} elseif ($login_step === 'email' && !auth_session_confirming()) {
    auth_redirect('/login/');
}

/* the email step: where the code went, and whether one is out */
if ($login_step === 'email' && ($cs = auth_session_confirming())) {
    $cp = auth_principal_by_id($cs['principal_id']);
    $login_email_to   = $cp ? auth_mask_email($cp['email']) : '';
    $login_email_sent = (bool)auth_challenge($cs['id'], 'login');
    if (!$login_email_sent && $login_error === '')
        $login_error = 'The code could not be sent. Try again with "Send a new code". If email does not work, run reqad auth email-code off on the server as root.';
}

include _PATH.'/public_html/templates/login.php';
