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

    public function init()
    {
        $this->add_hook('startup', [$this, 'startup']);
        $this->add_hook('authenticate', [$this, 'authenticate']);
        $this->add_hook('storage_init', [$this, 'storage_init']);
        $this->add_hook('managesieve_connect', [$this, 'managesieve_connect']);
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
            return $args;
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
        }

        return $args;
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

        $stmt = $db->prepare('SELECT email, created FROM webmail_tickets WHERE token = :t');
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
