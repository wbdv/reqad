<?php

/**
 * Reqad webmail auto-login.
 *
 * Lets the Reqad panel open a mailbox in Roundcube without knowing the user's
 * password, by authenticating as that mailbox with dovecot's master user.
 *
 * The master password never reaches the browser. The panel writes a single-use
 * ticket to its own SQLite database and sends the browser only an opaque token;
 * this plugin exchanges the token for an address, deletes the row on read, and
 * refuses anything older than 30 seconds.
 *
 * The token is accepted from POST ONLY, deliberately. As a query string it would
 * be written to the nginx access log, the browser history, and any Referer the
 * webmail page later sends to a third party -- single use and a 30 second life
 * make that survivable, but a POST body lands in none of them. Reading $_GET
 * here would quietly reopen all three.
 *
 * Roundcube here is served by the same php-fpm pool as the panel (both run as
 * the `reqad` user), so the database is simply readable — there is no shared
 * secret file and no extra service.
 *
 * The IMAP bind uses SASL PLAIN proxy authorization: authzid is the mailbox,
 * authcid is the master user. That is why the session username stays the plain
 * address, rather than the `user*master` form a separator-based login produces.
 *
 * The webmail session lives only as long as the panel session that opened it.
 * The ticket names that panel session (auth_sessions.id, db/1046.sql); it is
 * kept in the Roundcube session and checked on every request, and once the
 * panel session is gone -- logout, revoked, expired, idle -- the mailbox is
 * logged out too. Basic-auth panels have no sessions and leave it empty.
 *
 * NOTE: this plugin deliberately declares no $task. It must be initialized on
 * every request of an auto-logged-in session, not just on 'login' — storage_init
 * has to re-apply the proxy credentials each time Roundcube reconnects to IMAP,
 * and a task-restricted plugin is never loaded once the task becomes 'mail'.
 *
 * @license GNU GPLv3+
 */
class reqad_autologin extends rcube_plugin
{
    private $db_path = '/usr/local/reqad/db/reqad.db';

    /** @var string|null address to log in as, set once a ticket is redeemed */
    private $target = null;

    /** @var string panel session (auth_sessions.id) the ticket was minted by */
    private $panel_sid = '';

    /** Roundcube's background polls: they must not count as panel activity. */
    private $background_actions = ['refresh', 'keep-alive'];

    public function init()
    {
        $this->add_hook('startup', [$this, 'startup']);
        $this->add_hook('authenticate', [$this, 'authenticate']);
        $this->add_hook('storage_init', [$this, 'storage_init']);
        $this->add_hook('managesieve_connect', [$this, 'managesieve_connect']);
        $this->add_hook('smtp_connect', [$this, 'smtp_connect']);
        $this->add_hook('login_after', [$this, 'login_after']);
    }

    /**
     * Redeem the ticket and turn the request into a login action.
     *
     * An existing session is destroyed first, for two reasons. The admin may be
     * opening a DIFFERENT mailbox than the one already open, and without this
     * they would simply be shown the session they already had. And Roundcube
     * runs request_security_check() on every POST once a user is logged in
     * (index.php), which demands a _token this request cannot have -- so a
     * second auto-login into a live session died with "REQUEST CHECK FAILED"
     * instead of switching account.
     *
     * kill_session() calls $user->reset(), which empties $RCMAIL->user->ID and
     * routes the request into the login branch, ahead of that CSRF check.
     */
    public function startup($args)
    {
        // POST only -- see the note at the top of this file.
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['_reqad_login'])) {
            return $this->check_panel_session($args);
        }

        $target = $this->consume_ticket((string) $_POST['_reqad_login']);
        if ($target === '' || $this->master_password() === '') {
            // expired, already used, forged, or the feature was turned off:
            // fall through to the normal login form rather than saying which
            return $args;
        }

        if (!empty($_SESSION['user_id'])) {
            rcmail::get_instance()->kill_session();
        }

        $this->target   = $target;
        $args['task']   = 'login';
        $args['action'] = 'login';

        return $args;
    }

    /**
     * Supply the credentials for the login Roundcube is now performing.
     *
     * The password handed over is the master password. It is what Roundcube
     * encrypts into the session and what storage_init() below hands back as the
     * authcid secret; the user's own password is never involved.
     */
    public function authenticate($args)
    {
        if ($this->target === null) {
            return $args;
        }

        $args['user']        = $this->target;
        $args['pass']        = $this->master_password();
        $args['cookiecheck'] = false;
        $args['valid']       = true;

        return $args;
    }

    /**
     * Mark the session, once Roundcube has regenerated it.
     *
     * This cannot be done in startup() or authenticate(): rcmail::login()
     * regenerates the session on success, so anything written to $_SESSION
     * before that point is discarded. login_after runs after the new session
     * exists, which is what makes the flag survive to the next request.
     */
    public function login_after($args)
    {
        if ($this->target !== null) {
            $_SESSION['reqad_autologin'] = true;
            $_SESSION['reqad_panel_sid'] = $this->panel_sid;
        }

        return $args;
    }

    /**
     * End an auto-logged-in session whose panel session has ended.
     *
     * Runs from startup(), before index.php looks at the session: once it is
     * killed here, Roundcube shows its login page with "session invalid or
     * expired" (or, for an AJAX call, sends the browser there).
     *
     * A session with no reqad_panel_sid at all predates the binding and cannot
     * be checked, so it is ended as well -- opening Webmail again from the
     * panel starts a bound one.
     */
    private function check_panel_session($args)
    {
        if (empty($_SESSION['reqad_autologin']) || empty($_SESSION['user_id'])) {
            return $args;
        }

        if (!isset($_SESSION['reqad_panel_sid'])) {
            $alive = false;
        } elseif ($_SESSION['reqad_panel_sid'] === '') {
            $alive = true;                      // basic-auth panel: nothing to follow
        } else {
            $touch = !in_array((string) $args['action'], $this->background_actions, true);
            $alive = $this->panel_session_alive((string) $_SESSION['reqad_panel_sid'], $touch);
        }

        if (!$alive) {
            rcmail::get_instance()->kill_session();
        }

        return $args;
    }

    /**
     * Is the panel session still logged in, by the panel's own rules
     * (app/auth/auth.php auth_session_lookup)? Active state, principal active,
     * not past its hard expiry, not idle longer than auth-idle-minutes.
     *
     * Using the webmail is using the panel, so a real webmail request counts as
     * activity for the idle timeout ($touch, at most once a minute like the
     * panel itself). Roundcube's background polls do not, or a webmail tab left
     * open would keep the panel logged in for the full auth-max-hours.
     *
     * A database error counts as "not alive": failing closed only costs a
     * click on Webmail in the panel.
     */
    private function panel_session_alive($sid, $touch)
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $sid)) {
            return false;
        }

        $db = $this->open_db();
        if (!$db) {
            return false;
        }

        $stmt = $db->prepare("SELECT s.last_seen, s.expires_at
                              FROM auth_sessions s JOIN auth_principals p ON p.id = s.principal_id
                              WHERE s.id = :id AND s.state = 'active' AND p.status = 'active'");
        if (!$stmt) {
            return false;
        }
        $stmt->bindValue(':id', $sid, SQLITE3_TEXT);
        $res = $stmt->execute();
        $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;
        if (!is_array($row)) {
            return false;
        }

        $now  = time();
        $idle = 60 * $this->setting_int('auth-idle-minutes', 30);
        if ($now >= (int) $row['expires_at'] || $now - (int) $row['last_seen'] > $idle) {
            return false;
        }

        if ($touch && $now - (int) $row['last_seen'] >= 60) {
            $up = $db->prepare('UPDATE auth_sessions SET last_seen = :ts WHERE id = :id');
            if ($up) {
                $up->bindValue(':ts', $now, SQLITE3_INTEGER);
                $up->bindValue(':id', $sid, SQLITE3_TEXT);
                $up->execute();
            }
        }

        return true;
    }

    /** A numeric panel setting, with the panel's default (auth_cfg()). */
    private function setting_int($name, $default)
    {
        $db = $this->open_db();
        if (!$db) {
            return $default;
        }
        $stmt = $db->prepare('SELECT value FROM settings WHERE name = :n');
        if (!$stmt) {
            return $default;
        }
        $stmt->bindValue(':n', $name, SQLITE3_TEXT);
        $res = $stmt->execute();
        $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;
        $v   = is_array($row) && isset($row['value']) ? (string) $row['value'] : '';

        return ($v !== '' && ctype_digit($v)) ? (int) $v : $default;
    }

    /**
     * Bind as the master user on behalf of the mailbox.
     *
     * SASL PLAIN sends `authzid \0 authcid \0 password`, so dovecot sees the
     * mailbox as the identity being assumed and the master user as the one
     * proving itself. auth_type must be forced: the default 'check' can settle
     * on a mechanism with no proxy-authorization form.
     *
     * $this->target covers the initial login (the session flag does not exist
     * yet at that point); the session flag covers every request after it.
     */
    public function storage_init($args)
    {
        if ($this->target === null && empty($_SESSION['reqad_autologin'])) {
            return $args;
        }

        $pass = $this->master_password();
        if ($pass === '') {
            return $args;
        }

        $args['auth_type'] = 'PLAIN';
        $args['auth_cid']  = 'reqad-master';
        $args['auth_pw']   = $pass;

        return $args;
    }

    /**
     * Bind as the master user for the Sieve (filters) connection too.
     *
     * The managesieve plugin connects with $_SESSION['username'] and the
     * decrypted session password. In an auto-logged-in session that password is
     * the MASTER password, and dovecot rejects it as the mailbox's own password
     * -- so Settings > Filters died with "Authentication failed" while IMAP
     * worked fine. Same PLAIN proxy-authorization bind as storage_init(): the
     * mailbox is the authzid, the master user the authcid.
     *
     * Only the extra auth_* fields are set; 'user' stays the mailbox so the
     * script still belongs to the right account.
     */
    public function managesieve_connect($args)
    {
        if ($this->target === null && empty($_SESSION['reqad_autologin'])) {
            return $args;
        }

        $pass = $this->master_password();
        if ($pass === '') {
            return $args;
        }

        $args['auth_type'] = 'PLAIN';
        $args['auth_cid']  = 'reqad-master';
        $args['auth_pw']   = $pass;

        return $args;
    }

    /**
     * Bind as the master user for sending too.
     *
     * With smtp_user '%u' / smtp_pass '%p' Roundcube signs in to Exim as the
     * mailbox with the session password -- in an auto-logged-in session the
     * MASTER password, which dovecot rejects as the mailbox's own (535). Same
     * PLAIN proxy bind as storage_init(): rcube_smtp turns smtp_auth_cid into
     * the authcid and makes the mailbox the authzid, and Exim's dovecot
     * authenticator then sets $authenticated_id to the mailbox, so the sending
     * limits still count it against the mailbox's domain.
     *
     * An empty smtp_user means no SMTP AUTH at all; binding the master user
     * then would sign in as reqad-master itself and fail, so it is left alone.
     */
    public function smtp_connect($args)
    {
        if (empty($_SESSION['reqad_autologin']) || (string) $args['smtp_user'] === '') {
            return $args;
        }

        $pass = $this->master_password();
        if ($pass === '') {
            return $args;
        }

        $args['smtp_auth_type'] = 'PLAIN';
        $args['smtp_auth_cid']  = 'reqad-master';
        $args['smtp_auth_pw']   = $pass;

        return $args;
    }

    /**
     * Look a token up, delete it, and return the address it stood for.
     * Returns '' for anything unknown, malformed or expired.
     */
    private function consume_ticket($token)
    {
        if (!preg_match('/^[0-9a-f]{32}$/', $token)) {
            return '';
        }

        $db = $this->open_db();
        if (!$db) {
            return '';
        }

        $stmt = $db->prepare('SELECT email, created, session_id FROM webmail_tickets WHERE token = :t');
        if (!$stmt) {
            return '';
        }
        $stmt->bindValue(':t', $token, SQLITE3_TEXT);
        $res = $stmt->execute();
        $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;

        // delete unconditionally: a token is good exactly once, valid or not
        $del = $db->prepare('DELETE FROM webmail_tickets WHERE token = :t');
        if ($del) {
            $del->bindValue(':t', $token, SQLITE3_TEXT);
            $del->execute();
        }

        if (!is_array($row) || empty($row['email'])) {
            return '';
        }
        if ((time() - (int) $row['created']) > 30) {
            return '';
        }

        $this->panel_sid = (string) $row['session_id'];

        return (string) $row['email'];
    }

    private function master_password()
    {
        static $pass = null;
        if ($pass !== null) {
            return $pass;
        }

        $pass = '';
        $db   = $this->open_db();
        if (!$db) {
            return $pass;
        }

        $stmt = $db->prepare('SELECT value FROM settings WHERE name = :n');
        if (!$stmt) {
            return $pass;
        }
        $stmt->bindValue(':n', 'webmail-master-pass', SQLITE3_TEXT);
        $res = $stmt->execute();
        $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;

        if (is_array($row) && isset($row['value'])) {
            $pass = (string) $row['value'];
        }

        return $pass;
    }

    private function open_db()
    {
        static $db = false;
        if ($db !== false) {
            return $db;
        }

        $db = null;
        if (!class_exists('SQLite3') || !is_readable($this->db_path)) {
            return $db;
        }
        try {
            $db = new SQLite3($this->db_path, SQLITE3_OPEN_READWRITE);
            $db->busyTimeout(2000);
        } catch (Exception $e) {
            $db = null;
        }

        return $db;
    }
}
