#!/opt/reqad/php-current/usr/bin/php -c/etc/reqad/php-fpm/php.ini
<?php
/**
 * Reqad - import_cpanel_webmail.php
 *
 * Import cPanel mailboxes' webmail data into this server's Roundcube.
 *
 *   cPanel path (in the account's home)              what it holds
 *   etc/<domain>/<box>.rcube.db                      Roundcube's own SQLite: contacts,
 *                                                    groups, collected addresses,
 *                                                    identities, responses, settings
 *   .caldav/<box>@<domain>/<book>/*.vcf              CardDAV address books (phones,
 *                                                    Thunderbird...): vCards
 *
 * --dir is a folder laid out like the cPanel home (only those two paths are
 * read), so a copy of an account's etc/ and .caldav/ works as it is.
 * --sqlite + --email imports one mailbox's .rcube.db.
 *
 * What is taken, for each mailbox: live contacts (deleted ones stay behind),
 * groups and who is in them, collected addresses, canned responses, the
 * identities (a field the identity here already has is kept), and settings
 * that do not name folders (cPanel's folder names, INBOX.Sent..., do not
 * match the mailbox here). A setting already made here is kept. CardDAV
 * contacts go into the same address book; a book other than the default
 * "addressbook" becomes a group. A contact already here (same address, or
 * same name when it has none) is skipped, so running it again adds nothing.
 * The webmail user is made when the mailbox has never logged in here.
 *
 * Calendars (.ics) are not taken: Roundcube here has no calendar.
 *
 * DRY RUN BY DEFAULT. Nothing is written without --apply.
 *
 * Usage:
 *   import_cpanel_webmail.php --dir=DIR [--email=ADDR] [--apply] [--rc=ROUNDCUBE]
 *   import_cpanel_webmail.php --sqlite=FILE --email=ADDR [--apply] [--rc=ROUNDCUBE]
 *
 * It runs as the owner of the Roundcube folder (its logs and temp files stay
 * that user's): started as root, it copies the files it reads into a private
 * temporary folder of that user and goes on as that user.
 */

$opt = getopt('', array('dir:', 'sqlite:', 'email:', 'apply', 'rc:', 'help'));
$usage = "usage: import_cpanel_webmail.php --dir=DIR [--email=ADDR] [--apply] [--rc=ROUNDCUBE]\n"
       . "       import_cpanel_webmail.php --sqlite=FILE --email=ADDR [--apply] [--rc=ROUNDCUBE]\n";
if (isset($opt['help']) || (!isset($opt['dir']) && !isset($opt['sqlite'])) || (isset($opt['sqlite']) && !isset($opt['email'])))
    exit($usage);

$RC    = rtrim((string)($opt['rc'] ?? '/usr/local/reqad/roundcubemail'), '/');
$apply = isset($opt['apply']);
$only  = isset($opt['email']) ? strtolower(trim((string)$opt['email'])) : '';
if ($only !== '' && !filter_var($only, FILTER_VALIDATE_EMAIL))
    wm_die("ERROR: --email is not an email address\n");
if (!is_file($RC.'/config/config.inc.php') || !is_file($RC.'/program/include/clisetup.php'))
    wm_die("Webmail (Roundcube) is not installed in $RC: nothing imported.\n", 0);

/* ---- root: go on as the Roundcube folder's owner ---- */
if (posix_geteuid() === 0) {
    $pw = posix_getpwuid((int)fileowner($RC.'/config/config.inc.php'));
    if (!$pw || (int)$pw['uid'] === 0)
        wm_die("ERROR: $RC belongs to root; run this as the user the webmail runs as\n");
    $src = isset($opt['sqlite']) ? (string)$opt['sqlite'] : rtrim((string)$opt['dir'], '/');
    if (!file_exists($src))
        wm_die("ERROR: $src not found\n");
    /* the owner may not reach them where they are: a private copy, read by
       root and written by the owner (tar to tar) */
    $tmp = rtrim((string)shell_exec('runuser -u '.escapeshellarg($pw['name']).' -- mktemp -d 2>/dev/null'));
    if (!preg_match('#^/[A-Za-z0-9/._-]+$#', $tmp) || !is_dir($tmp) || is_link($tmp))
        wm_die("ERROR: could not make a temporary folder\n");
    $list = array();
    if (isset($opt['sqlite'])) {
        if (is_link($src) || !is_file($src))
            wm_die("ERROR: $src is not a file\n");
        $base = dirname($src);
        $list[] = basename($src);
    } else {
        $base = $src;
        foreach (glob($src.'/etc/*/*.rcube.db') ?: array() as $f)
            if (!is_link(dirname($f)) && !is_link($f) && is_file($f))
                $list[] = substr($f, strlen($src) + 1);
        foreach (glob($src.'/.caldav/*/*', GLOB_ONLYDIR) ?: array() as $b)
            if (!is_link($b) && !is_link(dirname($b)))
                foreach (array_merge(glob($b.'/*.vcf') ?: array(), array($b.'/.metadata')) as $f)
                    if (!is_link($f) && is_file($f))
                        $list[] = substr($f, strlen($src) + 1);
    }
    if ($list) {
        $p = proc_open('tar -C '.escapeshellarg($base).' --null --no-recursion -T - -cf - | runuser -u '.escapeshellarg($pw['name'])
                       .' -- tar -xf - -C '.escapeshellarg($tmp).' --no-same-owner', array(0 => array('pipe', 'r')), $pipes);
        if (!is_resource($p))
            wm_die("ERROR: could not copy the files\n");
        fwrite($pipes[0], implode("\0", $list)."\0");
        fclose($pipes[0]);
        if (proc_close($p) !== 0)
            echo "Some files could not be copied.\n";
    }
    $src = isset($opt['sqlite']) ? $tmp.'/'.basename($src) : $tmp;
    $args = array('--rc='.$RC, isset($opt['sqlite']) ? '--sqlite='.$src : '--dir='.$src);
    if ($only !== '')
        $args[] = '--email='.$only;
    if ($apply)
        $args[] = '--apply';
    $php = PHP_BINARY.(php_ini_loaded_file() ? ' -c '.escapeshellarg(php_ini_loaded_file()) : '');
    passthru('runuser -u '.escapeshellarg($pw['name']).' -- '.$php.' '.escapeshellarg(__FILE__).' '.implode(' ', array_map('escapeshellarg', $args)), $rc);
    exec('rm -rf '.escapeshellarg($tmp));
    exit($rc);
}

/* ---- what to import: email => array(sqlite file or '', CardDAV book folders) ---- */
$todo = array();
if (isset($opt['sqlite'])) {
    if (!is_readable((string)$opt['sqlite']))
        wm_die("ERROR: cannot read {$opt['sqlite']}\n");
    $todo[$only] = array('db' => (string)$opt['sqlite'], 'books' => array());
} else {
    $dir = rtrim((string)$opt['dir'], '/');
    if (!is_dir($dir))
        wm_die("ERROR: $dir is not a folder\n");
    foreach (glob($dir.'/etc/*/*.rcube.db') ?: array() as $f) {
        $e = strtolower(basename($f, '.rcube.db').'@'.basename(dirname($f)));
        if (filter_var($e, FILTER_VALIDATE_EMAIL) && !is_link($f))
            $todo[$e] = array('db' => $f, 'books' => array());
    }
    foreach (glob($dir.'/.caldav/*/*', GLOB_ONLYDIR) ?: array() as $b) {
        $e = strtolower(basename(dirname($b)));
        if (filter_var($e, FILTER_VALIDATE_EMAIL) && !is_link($b) && glob($b.'/*.vcf')) {
            $todo[$e] = $todo[$e] ?? array('db' => '', 'books' => array());
            $todo[$e]['books'][] = $b;
        }
    }
    if ($only !== '')
        $todo = array_intersect_key($todo, array($only => 1));
}
if (!$todo) {
    echo "No webmail data or address books to import.\n";
    exit(0);
}
ksort($todo);

/* ---- Roundcube ---- */
define('INSTALL_PATH', $RC.'/');
require INSTALL_PATH.'program/include/clisetup.php';
$rcmail = rcmail::get_instance();
$db = $rcmail->get_dbh();
$db->db_connect('w');
if (!$db->is_connected())
    wm_die("ERROR: cannot reach the webmail database\n");

/* the host a login stores in users.mail_host (as rcmail::login() works it out) */
$h = $rcmail->config->get('imap_host', 'localhost:143');
if (is_array($h)) {
    $k = key($h);
    $h = is_numeric($k) ? $h[$k] : $k;
}
list($HOST) = rcube_utils::parse_host_uri(rcube_utils::parse_host((string)$h), 143, 993);
$HOST = rcube_utils::idn_to_ascii($HOST);
$LC = $rcmail->config->get('login_lc', 2);

$total_fail = 0;
foreach ($todo as $email => $src) {
    try {
        $r = wm_import($email, $src);
        echo $email.': '.$r."\n";
    } catch (Throwable $e) {
        $total_fail++;
        echo "$email: NOT imported - ".$e->getMessage()."\n";
        if (!empty($GLOBALS['wm_tx'])) {
            $db->rollbackTransaction();
            $GLOBALS['wm_tx'] = false;
        }
    }
}
if (!$apply)
    echo "(dry run: nothing written; add --apply)\n";
exit($total_fail ? 1 : 0);


/* ======================================================================== */

function wm_die($msg, $rc = 1) {
    echo $msg;
    exit($rc);
}

function wm_q($sql, array $args = array()) {
    global $db;
    $r = $db->query($sql, $args);
    if ($db->is_error($r))
        throw new Exception('database error: '.$db->is_error($r));
    return $r;
}

function wm_rows($sql, array $args = array()) {
    global $db;
    $r = wm_q($sql, $args);
    $out = array();
    while ($row = $db->fetch_assoc($r))
        $out[] = $row;
    return $out;
}

function wm_t($name) {
    global $db;
    return $db->table_name($name, true);
}

/* only scalars and arrays of them (an unserialized object never goes on) */
function wm_plain($v) {
    if (is_array($v)) {
        $o = array();
        foreach ($v as $k => $x)
            if (($y = wm_plain($x)) !== null)
                $o[$k] = $y;
        return $o;
    }
    return is_scalar($v) ? $v : null;
}

/* settings that name folders, the old server's own, or secrets: not taken */
function wm_pref_skip($key) {
    return (bool)preg_match('/_mbox$|^(client_hash|namespace_fixed|default_folders|search_mods|message_threading|collapsed_folders|compose_responses|list_cols|list_flags|archive_type)$|pass|secret|token|2fa|twofactor|key$/i', (string)$key);
}

function wm_key($emails, $name) {
    $e = array();
    foreach (preg_split('/[,;\s]+/', strtolower((string)$emails), -1, PREG_SPLIT_NO_EMPTY) as $x)
        $e[] = $x;
    if ($e)
        return $e;
    $n = trim(mb_strtolower((string)$name));
    return $n !== '' ? array('name:'.$n) : array();
}

/* the cPanel SQLite, read-only; tables (never views) by name */
function wm_sqlite($file) {
    $sq = new PDO('sqlite:'.$file, null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY));
    try { $sq->exec('PRAGMA trusted_schema = OFF'); } catch (Throwable $e) {}
    $tables = array();
    foreach ($sq->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN) as $t)
        $tables[$t] = true;
    return array($sq, $tables);
}

function wm_import($email, array $src) {
    global $rcmail, $db, $apply, $HOST, $LC;

    $username = $LC === 2 || $LC === true ? mb_strtolower($email) : $email;
    $username = rcube_utils::idn_to_ascii($username);

    /* the cPanel side */
    $old = null;
    $sq = null;
    $tables = array();
    if ($src['db'] !== '') {
        list($sq, $tables) = wm_sqlite($src['db']);
        if (empty($tables['users']))
            throw new Exception(basename($src['db']).' is not a Roundcube database');
        $users = $sq->query('SELECT * FROM users')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($users as $x)
            if (strtolower((string)$x['username']) === $email)
                $old = $x;
        if (!$old && count($users) === 1)
            $old = $users[0];
        if (!$old)
            throw new Exception(basename($src['db']).' has no user '.$email);
    }
    $ou = $old ? (int)$old['user_id'] : 0;
    $sel = function ($table, $where = 'user_id = ?') use ($sq, $tables, $ou) {
        if (!$sq || empty($tables[$table]))
            return array();
        $s = $sq->prepare("SELECT * FROM \"$table\"".($where !== '' ? " WHERE $where" : ''));
        $s->execute($where !== '' ? array($ou) : array());
        return $s->fetchAll(PDO::FETCH_ASSOC);
    };

    /* the user here, made when it never logged in */
    $user = rcube_user::query($username, $HOST);
    $made = false;
    if ($apply) {
        if (!$db->startTransaction())
            throw new Exception('the webmail database refused a transaction');
        $GLOBALS['wm_tx'] = true;
    }
    if (!$user && $apply) {
        $user = rcube_user::create($username, $HOST);
        if (!$user)
            throw new Exception('the webmail user could not be made');
        $made = true;
    }
    $uid = $user ? (int)$user->ID : 0;
    $n = array('contacts' => 0, 'skipped' => 0, 'groups' => 0, 'collected' => 0, 'responses' => 0, 'identities' => 0, 'settings' => 0, 'carddav' => 0);

    /* contacts already here: addresses (or names) */
    $have = array();
    if ($uid)
        foreach (wm_rows('SELECT `name`, `email` FROM '.wm_t('contacts').' WHERE `user_id` = ? AND `del` <> 1', array($uid)) as $c)
            foreach (wm_key($c['email'], $c['name']) as $k)
                $have[$k] = true;
    $is_new = function ($emails, $name) use (&$have) {
        $keys = wm_key($emails, $name);
        foreach ($keys as $k)
            if (isset($have[$k]))
                return false;
        foreach ($keys as $k)
            $have[$k] = true;
        return true;
    };

    /* contacts from Roundcube's database, as they were (search words included) */
    $cmap = array();
    $fake = -1;
    foreach ($sel('contacts', 'user_id = ? AND del = 0') as $c) {
        if (!$is_new($c['email'] ?? '', $c['name'] ?? '')) {
            $n['skipped']++;
            continue;
        }
        if ($apply) {
            $changed = preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', (string)($c['changed'] ?? '')) && $c['changed'] > '1971' ? $c['changed'] : date('Y-m-d H:i:s');
            wm_q('INSERT INTO '.wm_t('contacts').' (`user_id`, `changed`, `del`, `name`, `email`, `firstname`, `surname`, `vcard`, `words`) VALUES (?, ?, 0, ?, ?, ?, ?, ?, ?)',
                array($uid, $changed, (string)($c['name'] ?? ''), (string)($c['email'] ?? ''), (string)($c['firstname'] ?? ''), (string)($c['surname'] ?? ''),
                      (string)($c['vcard'] ?? ''), (string)($c['words'] ?? '')));
            $cmap[$c['contact_id']] = (int)$db->insert_id('contacts');
        } else
            $cmap[$c['contact_id']] = $fake--;
        $n['contacts']++;
    }

    /* groups and their members */
    $book = new rcube_contacts($db, $uid);
    $groups = array();
    if ($uid)
        foreach ($book->list_groups() as $g)
            $groups[mb_strtolower($g['name'])] = $g['ID'];
    $group_id = function ($name) use (&$groups, $book, $apply, &$n, &$fake) {
        $k = mb_strtolower(trim((string)$name));
        if ($k === '')
            return null;
        if (!isset($groups[$k])) {
            if ($apply) {
                $g = $book->create_group(trim((string)$name));
                if (!$g)
                    throw new Exception('the group '.$name.' could not be made');
                $groups[$k] = $g['id'];
            } else
                $groups[$k] = $fake--;
            $n['groups']++;
        }
        return $groups[$k];
    };
    $gmap = array();
    foreach ($sel('contactgroups', 'user_id = ? AND del = 0') as $g)
        if (($id = $group_id($g['name'])) !== null)
            $gmap[$g['contactgroup_id']] = $id;
    $members = array();
    foreach ($sel('contactgroupmembers', '') as $m)
        if (isset($gmap[$m['contactgroup_id']], $cmap[$m['contact_id']]))
            $members[$gmap[$m['contactgroup_id']]][] = $cmap[$m['contact_id']];
    if ($apply)
        foreach ($members as $gid => $ids)
            $book->add_to_group($gid, $ids);

    /* collected addresses (Roundcube 1.5 and later on both sides) */
    if (!empty($tables['collected_addresses']) && version_compare(RCMAIL_VERSION, '1.5', '>=')) {
        $got = array();
        if ($uid)
            foreach (wm_rows('SELECT `type`, `email` FROM '.wm_t('collected_addresses').' WHERE `user_id` = ?', array($uid)) as $a)
                $got[$a['type'].' '.strtolower($a['email'])] = true;
        foreach ($sel('collected_addresses') as $a) {
            $k = (int)$a['type'].' '.strtolower((string)$a['email']);
            if ((string)$a['email'] === '' || isset($got[$k]))
                continue;
            $got[$k] = true;
            if ($apply)
                wm_q('INSERT INTO '.wm_t('collected_addresses').' (`changed`, `name`, `email`, `user_id`, `type`) VALUES (?, ?, ?, ?, ?)',
                    array((string)$a['changed'], (string)$a['name'], (string)$a['email'], $uid, (int)$a['type']));
            $n['collected']++;
        }
    }

    /* canned responses: the table (1.5+), or the old setting */
    $prefs = $old ? @unserialize((string)$old['preferences'], array('allowed_classes' => false)) : array();
    $prefs = is_array($prefs) ? wm_plain($prefs) : array();
    $resp = array();
    foreach ($sel('responses', 'user_id = ? AND del = 0') as $x)
        $resp[] = array('name' => (string)$x['name'], 'data' => (string)$x['data'], 'is_html' => (int)$x['is_html']);
    if (empty($tables['responses']))
        foreach ((array)($prefs['compose_responses'] ?? array()) as $x)
            if (is_array($x) && isset($x['name'], $x['text']))
                $resp[] = array('name' => (string)$x['name'], 'data' => (string)$x['text'], 'is_html' => (int)(($x['format'] ?? '') === 'html'));
    if ($resp) {
        $got = array();
        if ($uid)
            foreach (wm_rows('SELECT `name` FROM '.wm_t('responses').' WHERE `user_id` = ? AND `del` <> 1', array($uid)) as $x)
                $got[mb_strtolower($x['name'])] = true;
        foreach ($resp as $x) {
            if (trim($x['name']) === '' || isset($got[mb_strtolower($x['name'])]))
                continue;
            $got[mb_strtolower($x['name'])] = true;
            if ($apply)
                wm_q('INSERT INTO '.wm_t('responses').' (`user_id`, `changed`, `del`, `name`, `data`, `is_html`) VALUES (?, '.$db->now().', 0, ?, ?, ?)',
                    array($uid, $x['name'], $x['data'], $x['is_html']));
            $n['responses']++;
        }
    }

    /* identities: an empty field here gets the old value; a missing one is added */
    $fields = array('name', 'organization', 'reply-to', 'bcc', 'signature');
    $mine = array();
    if ($user)
        foreach ($user->list_identities() as $i)
            $mine[strtolower($i['email'])] = $i;
    foreach ($sel('identities', 'user_id = ? AND del = 0') as $i) {
        $e = strtolower(trim((string)$i['email']));
        if ($e === '')
            continue;
        if (isset($mine[$e])) {
            $set = array();
            foreach ($fields as $f)
                if (trim((string)($mine[$e][$f] ?? '')) === '' && trim((string)($i[$f] ?? '')) !== '')
                    $set[$f] = (string)$i[$f];
            if (isset($set['signature']))
                $set['html_signature'] = (int)($i['html_signature'] ?? 0);
            if ($set) {
                if ($apply && !$user->update_identity($mine[$e]['identity_id'], $set))
                    throw new Exception('the identity '.$e.' could not be changed');
                $n['identities']++;
            }
        } else {
            $rec = array('email' => (string)$i['email'], 'standard' => 0, 'html_signature' => (int)($i['html_signature'] ?? 0));
            foreach ($fields as $f)
                $rec[$f] = (string)($i[$f] ?? '');
            if ($apply && !$user->insert_identity($rec))
                throw new Exception('the identity '.$e.' could not be added');
            $mine[$e] = $rec;
            $n['identities']++;
        }
    }

    /* settings: those that do not name folders, and only where none is set here */
    if ($prefs) {
        $stored = $user ? @unserialize((string)($user->data['preferences'] ?? ''), array('allowed_classes' => false)) : array();
        $stored = is_array($stored) ? $stored : array();
        foreach ($prefs as $k => $v) {
            if (wm_pref_skip($k) || array_key_exists($k, $stored))
                continue;
            if ($k === 'skin' && (!is_string($v) || !preg_match('/^[a-z0-9_-]+$/i', $v) || !is_dir(INSTALL_PATH.'skins/'.$v)))
                continue;
            if ($k === 'language')
                continue;
            $stored[$k] = $v;
            $n['settings']++;
        }
        if ($apply && $n['settings'])
            wm_q('UPDATE '.wm_t('users').' SET `preferences` = ? WHERE `user_id` = ?', array(serialize($stored), $uid));
    }
    /* the language: for a user made now, or one that has none yet */
    $lang = (string)($old['language'] ?? '');
    if ($apply && preg_match('/^[a-z]{2,3}(_[A-Z]{2})?$/', $lang))
        wm_q('UPDATE '.wm_t('users').' SET `language` = ? WHERE `user_id` = ?'.($made ? '' : ' AND (`language` IS NULL OR `language` = \'\')'), array($lang, $uid));

    /* CardDAV address books */
    foreach ($src['books'] as $dir) {
        $gname = '';
        if (basename($dir) !== 'addressbook') {
            $meta = json_decode((string)@file_get_contents($dir.'/.metadata'), true);
            $gname = trim((string)(is_array($meta) ? ($meta['displayname'] ?? $meta['{DAV:}displayname'] ?? '') : ''));
            if ($gname === '')
                $gname = basename($dir);
            $gname = mb_substr($gname, 0, 128);
        }
        $ids = array();
        $added = 0;
        foreach (glob($dir.'/*.vcf') ?: array() as $f) {
            if (is_link($f) || filesize($f) > 5 * 1048576)
                continue;
            $vo = new rcube_vcard();
            $vo->extend_fieldmap($book->vcard_map);
            foreach ((array)$vo->import((string)file_get_contents($f)) as $vcard) {
                $rec = $vcard->get_assoc();
                if (empty($rec['name'])) {
                    $rec['name'] = rcube_addressbook::compose_display_name($rec, true);
                    if ($rec['name'] == ($rec['email'][0] ?? null))
                        $rec['name'] = '';
                }
                $emails = array();
                foreach ($rec as $k => $v)
                    if (strpos($k, 'email') === 0)
                        foreach ((array)$v as $x)
                            $emails[] = rcube_utils::idn_to_utf8((string)$x);
                if (!$emails && trim((string)$rec['name']) === '')
                    continue;
                if (!$book->validate($rec, true) || !$is_new(implode(',', $emails), $rec['name'])) {
                    $n['skipped']++;
                    continue;
                }
                if ($apply) {
                    $rec['vcard'] = $vcard->export();
                    if (!($id = $book->insert($rec)))
                        throw new Exception('a CardDAV contact could not be added');
                    $ids[] = $id;
                }
                $added++;
                $n['carddav']++;
            }
        }
        /* a book other than the default one: its contacts in a group of its name */
        if ($gname !== '' && $added && ($gid = $group_id($gname)) !== null && $ids)
            $book->add_to_group($gid, $ids);
    }

    if ($apply) {
        if ($db->is_error())
            throw new Exception('database error: '.$db->is_error());
        if (!$db->endTransaction())
            throw new Exception('the changes could not be saved');
        $GLOBALS['wm_tx'] = false;
    }

    $parts = array();
    $say = array('contacts' => array('contact', 'contacts'), 'carddav' => array('contact from CardDAV', 'contacts from CardDAV'),
                 'groups' => array('group', 'groups'), 'collected' => array('collected address', 'collected addresses'),
                 'responses' => array('response', 'responses'), 'identities' => array('identity', 'identities'),
                 'settings' => array('setting', 'settings'));
    foreach ($say as $k => $w)
        if ($n[$k])
            $parts[] = $n[$k].' '.$w[$n[$k] === 1 ? 0 : 1];
    $out = $parts ? implode(', ', $parts) : 'nothing new';
    if ($n['skipped'])
        $out .= ' ('.$n['skipped'].' contact'.($n['skipped'] === 1 ? '' : 's').' already here)';
    if ($made)
        $out .= '; webmail user made';
    elseif (!$user)
        $out .= '; the webmail user will be made';
    return $out;
}
