#!/opt/reqad/php-current/usr/bin/php -c/etc/reqad/php-fpm/php.ini
<?php
/**
 * Reqad - transfer_cpanel.php
 *
 * Runs a transfer job of the transfer tool (Server > Transfer tool): moves
 * whole cPanel accounts from another server to this one, one account after
 * the other. Started by transfer_job_start() in its own systemd unit
 * (reqad-transfer-<id>), as root; the job file says what to take.
 *
 * Each account keeps its user name, so its databases, paths and configuration
 * files stay valid. For each one:
 *   1. the account with its main domain, PHP version, shell and the cPanel
 *      password (the hash, unchanged: the user keeps logging in with it),
 *      and its contact address (the first one, stored as confirmed; only
 *      sign-in messages on, so the move mails nobody);
 *   2. its addon domains and subdomains (each with its PHP version) and
 *      aliases (parked domains);
 *   3. the home folder, copied AS the account (tar over ssh), without what is
 *      cPanel's own (mail, etc, logs, ssl, .cpanel...);
 *   4. databases with their users and passwords (the hashes), owners of
 *      views/triggers/routines pointed at the account's own users;
 *   5. mail: mailboxes with their passwords, the mail itself, forwarders,
 *      filters, autoresponders, spam lists, DKIM keys;
 *   6. cron jobs (cPanel's PHP binaries changed to this server's);
 *   7. certificates of the sites (a paid one keeps working; free ones are
 *      issued again here once DNS points here).
 * DNS is not touched: the sites go live when their DNS is switched.
 *
 * An account that is already here is continued only when a transfer made it
 * (/var/lib/reqad/transfer/accounts/<user>.json), so a stopped or failed
 * transfer can simply be started again; anything else is refused.
 *
 * Usage: transfer_cpanel.php --job=<id>
 */

if (posix_geteuid() !== 0)
    exit("ERROR: run as root\n");

require_once '/usr/local/reqad/public_html/defines.php';
require_once '/usr/local/reqad/public_html/modules/functions.php';
foreach (glob(_PATH.'/app/functions/*.php') as $app_functions_file)
    require_once $app_functions_file;
require_once _PATH.'/app/auth/auth.php';     /* the account's contact address */
plugins_load();

$opt = getopt('', array('job:'));
$JOB = transfer_job_get((string)($opt['job'] ?? ''));
if (!$JOB)
    exit("usage: transfer_cpanel.php --job=<id>\n");

define('TR_PHP', '/opt/reqad/php-current/usr/bin/php -c /etc/reqad/php-fpm/php.ini');
/* a systemd unit has no HOME: mysql, mysqldump and the importers find root's settings there */
putenv('HOME=/root');
define('TR_VAR', '/var/lib/reqad/transfer');

/* the versions chosen for PHP versions this server does not have */
transfer_php_map((array)($JOB['php_map'] ?? array()));

$ssh = transfer_ssh($JOB['server'], (int)$JOB['port'], $JOB['ruser'], $err);
if ($err !== '')
    exit("ERROR: $err\n");
/* the panel's key and known_hosts (reqad's); keep long copies alive */
$SSH = 'runuser -u reqad -- '.preg_replace('/^ssh /', 'ssh -o ServerAliveInterval=30 -o ServerAliveCountMax=10 ', $ssh);

$STATE = transfer_job_state($JOB['id']);
$STATE['status']  = 'running';
$STATE['started'] = $STATE['started'] ?? time();
$STATE['pid']     = getmypid();
foreach ($JOB['accounts'] as $a)
    if (!isset($STATE['accounts'][$a['user']]) || in_array($STATE['accounts'][$a['user']]['status'] ?? '', array('running', 'stopped'), true))
        $STATE['accounts'][$a['user']] = array('status' => 'queued', 'step' => '', 'detail' => '', 'warnings' => 0);
tr_state_save(true);
transfer_job_log($JOB['id'], 'Running: '.count($JOB['accounts']).' account'.(count($JOB['accounts']) === 1 ? '' : 's').' (pid '.getmypid().').');

@mkdir(TR_VAR.'/accounts', 0700, true);
@chmod(TR_VAR, 0700);
/* what a stopped job left (pulled filters, autoresponders, spam lists) */
foreach (glob(TR_VAR.'/*', GLOB_ONLYDIR) ?: array() as $old)
    if (preg_match('#/[a-f0-9]{16}$#', $old) && basename($old) !== $JOB['id'])
        exec('rm -rf '.escapeshellarg($old));

foreach ($JOB['accounts'] as $a) {
    $u = $a['user'];
    if (in_array($STATE['accounts'][$u]['status'] ?? '', array('done', 'warn'), true))
        continue;
    $STATE['accounts'][$u] = array('status' => 'running', 'step' => 'Starting', 'detail' => '', 'warnings' => 0, 'started' => time());
    tr_state_save(true);
    tr_log($u, '=== '.$u.' ('.$a['domain'].') from '.$JOB['server'].' ===');
    $ok = false;
    try {
        $ok = tr_account($a);
    } catch (Throwable $e) {
        tr_log($u, 'ERROR: '.$e->getMessage().' ('.basename($e->getFile()).':'.$e->getLine().')');
    }
    $w = (int)$STATE['accounts'][$u]['warnings'];
    $STATE['accounts'][$u]['status'] = $ok ? ($w ? 'warn' : 'done') : 'failed';
    $STATE['accounts'][$u]['step'] = $ok ? 'Done' : $STATE['accounts'][$u]['step'];
    $STATE['accounts'][$u]['finished'] = time();
    tr_log($u, $ok ? ('Finished'.($w ? " with $w thing".($w === 1 ? '' : 's').' to check (marked with !).' : '.')) : 'Stopped: the account was not finished.');
    transfer_job_log($JOB['id'], "$u ({$a['domain']}): ".($ok ? ($w ? "done, $w thing".($w === 1 ? '' : 's').' to check' : 'done') : 'failed at "'.$STATE['accounts'][$u]['step'].'"'));
    tr_state_save(true);
    exec('rm -rf '.escapeshellarg(TR_VAR.'/'.$JOB['id'].'/'.$u));
}
@rmdir(TR_VAR.'/'.$JOB['id']);
shell_exec(_PATH.'/scripts/update_email_sni >/dev/null 2>&1 &');
$STATE['status'] = 'done';
$STATE['finished'] = time();
tr_state_save(true);
$n = array('done' => 0, 'warn' => 0, 'failed' => 0);
foreach ($STATE['accounts'] as $x)
    if (isset($n[$x['status'] ?? '']))
        $n[$x['status']]++;
transfer_job_log($JOB['id'], "Finished: {$n['done']} done, {$n['warn']} to check, {$n['failed']} failed.");
exit(0);


/* ======================================================================== */

function tr_state_save($now = false) {
    global $JOB, $STATE;
    static $last = 0;
    if (!$now && microtime(true) - $last < 2)
        return;
    $last = microtime(true);
    $f = transfer_job_file($JOB['id'], 'state.json');
    @file_put_contents($f.'.tmp', json_encode($STATE, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    @chmod($f.'.tmp', 0640);
    @chgrp($f.'.tmp', 'reqad');
    @rename($f.'.tmp', $f);
}

function tr_log($u, $msg) {
    global $JOB;
    $f = transfer_job_file($JOB['id'], $u.'.log');
    $new = !is_file($f);
    $lines = '';
    foreach (explode("\n", rtrim((string)$msg, "\n")) as $l)
        $lines .= date('H:i:s').' '.$l."\n";
    @file_put_contents($f, $lines, FILE_APPEND);
    if ($new) {
        @chmod($f, 0640);
        @chgrp($f, 'reqad');
    }
}

function tr_warn($u, $msg) {
    global $STATE;
    $STATE['accounts'][$u]['warnings'] = (int)($STATE['accounts'][$u]['warnings'] ?? 0) + 1;
    tr_log($u, '  ! '.$msg);
    tr_state_save();
}

function tr_step($u, $step, $detail = '') {
    global $STATE;
    $STATE['accounts'][$u]['step'] = $step;
    $STATE['accounts'][$u]['detail'] = $detail;
    tr_log($u, '-- '.$step.($detail !== '' ? ': '.$detail : ''));
    tr_state_save(true);
}

function tr_detail($u, $detail) {
    global $STATE;
    $STATE['accounts'][$u]['detail'] = $detail;
    tr_state_save();
}

/* warnings of a result_ok(), each as a ! line; "does not point here yet"
   is what every site of a transfer says until its DNS is switched */
function tr_warnings($u, array $r, $prefix = '') {
    foreach ((array)($r['warnings'] ?? array()) as $w)
        if (trim((string)$w) !== '' && !preg_match('/ points to .*not to this server| has no address in DNS yet|is not on this server: add its mail records/', $w))
            tr_warn($u, $prefix.$w);
}

/* a script on the cPanel server with $args as its $1, $2... (each checked by
   the caller, and quoted here); its output */
function tr_remote($script, array $args = array(), $timeout = 300) {
    global $SSH;
    $cmd = ($args ? 'set -- '.implode(' ', array_map('escapeshellarg', $args))."\n" : '').$script;
    return transfer_remote($SSH, $cmd, '', $timeout);
}

/*
 * Stream the output of $remote (a command on the cPanel server) into the stdin
 * of $local (a command here). $filter: called with each line (a dump), returns
 * what goes on. Progress goes to the account's state every few seconds.
 * array(ok, bytes, error text of either side)
 */
function tr_pipe($u, $remote, $local, $label, $total = 0, $filter = null) {
    global $SSH;
    $rerr = tempnam(sys_get_temp_dir(), 'rqtr');
    $lerr = tempnam(sys_get_temp_dir(), 'rqtl');
    $rp = proc_open($SSH.' '.escapeshellarg($remote), array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('file', $rerr, 'w')), $rpipes);
    $lp = proc_open($local, array(0 => array('pipe', 'r'), 1 => array('file', $lerr, 'a'), 2 => array('file', $lerr, 'a')), $lpipes);
    if (!is_resource($rp) || !is_resource($lp)) {
        @unlink($rerr);
        @unlink($lerr);
        return array(false, 0, 'could not start the copy');
    }
    $bytes = 0;
    $broken = false;
    $t0 = microtime(true);
    while (!feof($rpipes[1])) {
        $chunk = $filter ? fgets($rpipes[1]) : fread($rpipes[1], 1048576);
        if ($chunk === false || $chunk === '')
            continue;
        $bytes += strlen($chunk);
        if ($filter)
            $chunk = $filter($chunk);
        for ($off = 0, $n = strlen($chunk); $off < $n; $off += $w) {
            $w = @fwrite($lpipes[0], substr($chunk, $off, 1048576));
            if ($w === false || $w === 0) {
                $broken = true;
                break 2;
            }
        }
        tr_detail($u, $label.': '.transfer_bytes($bytes).($total > 0 ? ' of about '.transfer_bytes($total) : '')
                      .', '.transfer_bytes($bytes / max(1, microtime(true) - $t0)).'/s');
    }
    if ($broken)
        proc_terminate($rp);
    fclose($rpipes[1]);
    fclose($lpipes[0]);
    $rrc = proc_close($rp);
    $lrc = proc_close($lp);
    $e = trim(implode("\n", array_slice(array_filter(array_map('trim', explode("\n", (string)@file_get_contents($rerr)."\n".(string)@file_get_contents($lerr)))), -12)));
    @unlink($rerr);
    @unlink($lerr);
    return array(!$broken && $rrc === 0 && $lrc === 0, $bytes, $e);
}

/* the account's file in /var/lib/reqad/transfer/accounts: made by a transfer */
function tr_marker($u, array $set = null) {
    $f = TR_VAR.'/accounts/'.$u.'.json';
    if ($set === null) {
        $m = json_decode((string)@file_get_contents($f), true);
        return is_array($m) ? $m : null;
    }
    file_put_contents($f, json_encode($set, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    chmod($f, 0600);
    return $set;
}

/* run one of the import_cpanel_*.php scripts; its output into the log */
function tr_import($u, $script, array $args) {
    $cmd = TR_PHP.' '._PATH.'/scripts/'.$script.' '.implode(' ', array_map('escapeshellarg', $args)).' 2>&1';
    exec($cmd, $out, $rc);
    foreach ($out as $l)
        if (trim($l) !== '')
            tr_log($u, '   '.rtrim($l));
    if ($rc !== 0)
        tr_warn($u, $script.' ended with an error (see above).');
}

/* The account's lines of the IP map: each site's address on cPanel (its own
   or shared) and the address its mail went out from (/etc/mailips: the
   domain's line, else its "*" line, else the server's main address). Behind
   NAT (/var/cpanel/cpnat) the public address, the one DNS points at. */
function tr_ip_map($u, array $sites, array $f) {
    global $JOB;
    $ip = $f['ip'];
    $pub = function ($a) use ($ip) { return $ip['nat'][$a] ?? $a; };
    $rows = array();
    foreach ($sites as $s) {
        $b4 = @inet_pton((string)($s['ip4'] ?? ''));
        $a4 = $b4 !== false && strlen($b4) === 4 ? (string)inet_ntop($b4) : $ip['acct'];
        $b6 = @inet_pton((string)($s['ip6'] ?? ''));
        $a6 = $b6 !== false && strlen($b6) === 16 ? (string)inet_ntop($b6) : '';
        $m = $ip['mail'][$s['domain']] ?? ($ip['mailall'] !== '' ? $ip['mailall'] : $ip['main']);
        $rows[] = array('domain' => $s['domain'], 'account' => $u, 'kind' => $s['type'] === 'parked' ? 'alias' : $s['type'],
                        'ipv4' => $a4 !== '' ? $pub($a4) : '', 'ipv4_use' => $a4 === '' ? '' : (in_array($a4, $ip['own'], true) && $a4 !== $ip['main'] ? 'dedicated' : 'shared'),
                        'ipv6' => $a6, 'ipv6_use' => $a6 === '' ? '' : (!empty($s['ip6_own']) ? 'dedicated' : 'shared'),
                        'mail_from' => $m !== '' ? $pub($m) : '');
    }
    if (!transfer_ip_map_save($JOB['id'], $u, $rows)) {
        tr_warn($u, 'The IP map could not be saved in '.transfer_job_file($JOB['id'], 'ips').'.');
        return;
    }
    $main = $rows[0];
    foreach ($rows as $r)
        if ($r['kind'] === 'main')
            $main = $r;
    $mail = array();
    foreach ($rows as $r)
        if ($r['mail_from'] !== '' && $r['kind'] !== 'sub')
            $mail[$r['mail_from']][] = $r['domain'];
    tr_log($u, 'IP addresses on cPanel (kept in the IP map): '.($main['ipv4'] !== '' ? $main['ipv4'].' ('.($main['ipv4_use'] === 'dedicated' ? 'its own' : 'shared').')' : 'no IPv4')
               .($main['ipv6'] !== '' ? ', '.$main['ipv6'].' ('.($main['ipv6_use'] === 'dedicated' ? 'its own' : 'shared').')' : '')
               .($mail ? '; mail from '.implode('; ', array_map(function ($a, $d) { return $a.' ('.implode(', ', $d).')'; }, array_keys($mail), $mail)) : '').'.');
}

/* a local version for a cPanel one: the same, else the one chosen for it on
   the PHP step, else the nearest newer */
function tr_local_php($u, $version, $what) {
    if ($version === '')
        return '';
    if (transfer_php_local($version)['status'] === 'installed')
        return $version;
    $near = transfer_php_nearest($version);
    if (isset(transfer_php_map()[$version]))
        tr_log($u, "$what: PHP $version is not installed here, PHP $near is used instead (chosen for the transfer).");
    else
        tr_warn($u, "$what: PHP $version is not installed here, PHP ".($near ?: 'default').' is used instead. Install PHP '.$version.' in PHP Settings and switch the site back if it needs it.');
    return $near;
}

/* what the cPanel server has of the account (passwords and keys included,
   kept in memory only) */
function tr_facts($u, array $sites) {
    $args = array($u);
    foreach ($sites as $s)
        if ($s['type'] !== 'parked')
            $args[] = $s['domain'].':'.(string)($s['twin'] ?? '');
    $script = <<<'SH'
u="$1"; shift
h=$(getent passwd "$u" | cut -d: -f6)
if [ -z "$h" ] || [ ! -d "$h" ]; then echo "@@RQ nouser"; exit 0; fi
b64() { [ -f "$1" ] && [ ! -L "$1" ] && base64 -w0 < "$1" 2>/dev/null; echo; }
echo "@@RQ home"; echo "$h"
echo "@@RQ shell"; getent passwd "$u" | cut -d: -f7
echo "@@RQ hash"; getent shadow "$u" | cut -d: -f2
echo "@@RQ suspended"; [ -e "/var/cpanel/suspended/$u" ] && echo yes
c=$(grep -m1 '^CONTACTEMAIL=' "/var/cpanel/users/$u" 2>/dev/null | cut -d= -f2-)
[ -z "$c" ] && [ -f "$h/.contactemail" ] && [ ! -L "$h/.contactemail" ] && c=$(head -c 1000 "$h/.contactemail")
echo "@@RQ contact"; printf '%s' "$c" | tr '\n' ' '; echo
echo "@@RQ ipmain"; head -c 100 /var/cpanel/mainip 2>/dev/null; echo
echo "@@RQ ipacct"; grep -m1 '^IP=' "/var/cpanel/users/$u" 2>/dev/null | cut -d= -f2
echo "@@RQ ipown"; awk -F': *' '/^[0-9a-fA-F.:]+:/{print $1}' /etc/domainips 2>/dev/null
echo "@@RQ ipnat"; grep -v '^#' /var/cpanel/cpnat 2>/dev/null
echo "@@RQ mailipall"; awk -F': *' '$1=="*"{print $2; exit}' /etc/mailips 2>/dev/null
for d in $(awk -F': ' -v u="$u" '$2==u{print $1}' /etc/userdomains 2>/dev/null); do
  case "$d" in ''|*[!a-z0-9.-]*) continue ;; esac
  r=local; grep -qxF "$d" /etc/remotedomains 2>/dev/null && r=remote; grep -qxF "$d" /etc/secondarymx 2>/dev/null && r=secondary
  echo "@@RQ dom $d $r"
  mi=$(awk -F': *' -v d="$d" '$1==d{print $2; exit}' /etc/mailips 2>/dev/null); [ -n "$mi" ] && echo "@@RQ mailip $d $mi"
  [ -s "$h/etc/$d/shadow" ] && { echo "@@RQ shadow $d"; b64 "$h/etc/$d/shadow"; }
  [ -s "/etc/valiases/$d" ] && { echo "@@RQ valiases $d"; b64 "/etc/valiases/$d"; }
  [ -s "/var/cpanel/domain_keys/private/$d" ] && { echo "@@RQ dkim $d"; b64 "/var/cpanel/domain_keys/private/$d"; }
  [ -s "/etc/vfilters/$d" ] && { echo "@@RQ vfilter $d"; b64 "/etc/vfilters/$d"; }
  for f in "$h/etc/$d"/*/filter; do [ -s "$f" ] || continue; b=${f%/filter}; echo "@@RQ filter $d ${b##*/}"; b64 "$f"; done
  if [ -d "$h/mail/$d" ] && [ ! -L "$h/mail/$d" ]; then
    echo "@@RQ maildu $d"; timeout 120 du -sb "$h/mail/$d" 2>/dev/null | cut -f1
    for m in "$h/mail/$d"/*/; do [ -d "${m}storage" ] && { b=${m%/}; echo "@@RQ mdbox $d ${b##*/}"; }; done
  fi
done
for s in "$@"; do
  d=${s%%:*}; t=${s#*:}
  for n in "$d" "$t"; do
    [ -n "$n" ] && [ -s "/var/cpanel/ssl/apache_tls/$n/combined" ] && { echo "@@RQ ssl $d"; b64 "/var/cpanel/ssl/apache_tls/$n/combined"; break; }
  done
done
echo "@@RQ cron"; crontab -l -u "$u" 2>/dev/null | base64 -w0; echo
[ -s "$h/.spamassassin/user_prefs" ] && { echo "@@RQ prefs"; b64 "$h/.spamassassin/user_prefs"; }
if [ -d "$h/.autorespond" ] && [ ! -L "$h/.autorespond" ]; then
  for f in "$h/.autorespond"/*; do [ -f "$f" ] && [ ! -L "$f" ] || continue; echo "@@RQ ar ${f##*/}"; b64 "$f"; done
fi
echo "@@RQ dbs"; whmapi1 --output=json list_mysql_databases_and_users user="$u" 2>/dev/null | base64 -w0; echo
SH;
    $f = array('home' => '', 'shell' => '', 'hash' => '', 'suspended' => false, 'contact' => '', 'doms' => array(), 'cron' => '', 'prefs' => '',
               'ar' => array(), 'dbs' => array(), 'ssl' => array(),
               'ip' => array('main' => '', 'acct' => '', 'own' => array(), 'nat' => array(), 'mail' => array(), 'mailall' => ''));
    $ipn = function ($x) { $b = @inet_pton(trim((string)$x)); return $b === false ? '' : (string)inet_ntop($b); };
    $out = tr_remote($script, $args, 600);
    if (trim($out) === '')
        throw new Exception('The cPanel server did not answer.');
    $dom = function ($d) use (&$f) {
        if (!isset($f['doms'][$d]))
            $f['doms'][$d] = array('route' => 'local', 'shadow' => '', 'valiases' => '', 'dkim' => '', 'vfilter' => '', 'filters' => array(),
                                   'maildu' => 0, 'mdbox' => array());
        return $d;
    };
    foreach (transfer_sections($out) as $s) {
        list($name, $arg, $lines) = $s;
        $first = trim((string)($lines[0] ?? ''));
        $b64 = $first === '' ? '' : (string)base64_decode($first, true);
        $p = explode(' ', $arg);
        switch ($name) {
            case 'nouser':    throw new Exception("The cPanel server has no user $arg.");
            case 'home':      $f['home'] = $first; break;
            case 'shell':     $f['shell'] = $first; break;
            case 'hash':      $f['hash'] = $first; break;
            case 'suspended': $f['suspended'] = $first === 'yes'; break;
            case 'contact':   /* cPanel may hold several: only the first */
                foreach (preg_split('/[\s,;]+/', strtolower($first), -1, PREG_SPLIT_NO_EMPTY) as $c)
                    if (filter_var($c, FILTER_VALIDATE_EMAIL) && strlen($c) <= 254) {
                        $f['contact'] = $c;
                        break;
                    }
                break;
            case 'dom':       if (valid_domain($p[0])) $f['doms'][$dom($p[0])]['route'] = $p[1] ?? 'local'; break;
            case 'shadow': case 'valiases': case 'dkim': case 'vfilter':
                if (valid_domain($p[0])) $f['doms'][$dom($p[0])][$name] = $b64;
                break;
            case 'filter':
                if (valid_domain($p[0]) && valid_email_user($p[1] ?? '')) $f['doms'][$dom($p[0])]['filters'][$p[1]] = $b64;
                break;
            case 'maildu':    if (valid_domain($p[0])) $f['doms'][$dom($p[0])]['maildu'] = (float)$first; break;
            case 'mdbox':     if (valid_domain($p[0]) && valid_email_user($p[1] ?? '')) $f['doms'][$dom($p[0])]['mdbox'][] = $p[1]; break;
            case 'ssl':       if (valid_domain($p[0])) $f['ssl'][$p[0]] = $b64; break;
            case 'cron':      $f['cron'] = $b64; break;
            case 'prefs':     $f['prefs'] = $b64; break;
            case 'ar':        if (preg_match('/^[A-Za-z0-9@._+=-]{1,200}$/', $arg) && $arg[0] !== '.') $f['ar'][$arg] = $b64; break;
            case 'dbs':       $f['dbs'] = (array)(json_decode($b64, true)['data'] ?? array()); break;
            case 'ipmain':    $f['ip']['main'] = $ipn($first); break;
            case 'ipacct':    $f['ip']['acct'] = $ipn($first); break;
            case 'mailipall': $f['ip']['mailall'] = $ipn($first); break;
            case 'ipown':     foreach ($lines as $l) if (($x = $ipn($l)) !== '') $f['ip']['own'][] = $x; break;
            case 'ipnat':     /* cPanel behind NAT: "<local> <public>" */
                foreach ($lines as $l) { $q = preg_split('/\s+/', trim($l)); if (count($q) >= 2 && $ipn($q[0]) !== '' && $ipn($q[1]) !== '') $f['ip']['nat'][$ipn($q[0])] = $ipn($q[1]); }
                break;
            case 'mailip':    if (valid_domain($p[0]) && ($x = $ipn($p[1] ?? '')) !== '') $f['ip']['mail'][$p[0]] = $x; break;
        }
    }
    if ($f['home'] === '' || $f['home'][0] !== '/')
        throw new Exception('Could not read the home folder of the cPanel account.');
    return $f;
}

/* mailboxes of a domain from cPanel's ~/etc/<domain>/shadow: box => hash */
function tr_boxes($shadow) {
    $boxes = array();
    foreach (explode("\n", (string)$shadow) as $l) {
        $p = explode(':', trim($l));
        if (count($p) >= 2 && valid_email_user(strtolower($p[0])))
            $boxes[strtolower($p[0])] = $p[1];
    }
    return $boxes;
}

/* the cPanel account's mail domains: those with mailboxes, forwarders or autoresponders */
function tr_mail_domains(array $f) {
    $out = array();
    foreach ($f['doms'] as $d => $x) {
        $ar = false;
        foreach (array_keys($f['ar']) as $n)
            if (substr($n, -strlen($d) - 1) === '@'.$d || strpos($n, '@'.$d.'.') !== false)
                $ar = true;
        if (tr_boxes($x['shadow']) || tr_forwards($x['valiases'], $d, '')[0] || $ar)
            $out[$d] = true;
    }
    return $out;
}

/*
 * cPanel's /etc/valiases/<domain> as forwarders: array(forwarders: local =>
 * array(to[], pipe), notes[]). cPanel leaves same-domain destinations
 * unqualified ("other"), so those get @domain. Its autoresponder pipe is
 * dropped (the autoresponder import makes it again), and so is the default
 * address (*): a catch-all is not taken.
 */
function tr_forwards($text, $domain, $ruser) {
    $fw = array();
    $notes = array();
    foreach (explode("\n", (string)$text) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || ($i = strpos($line, ':')) === false)
            continue;
        $k = strtolower(trim(substr($line, 0, $i)));
        $v = trim(substr($line, $i + 1));
        if (substr($k, -strlen($domain) - 1) === '@'.$domain)
            $k = substr($k, 0, -strlen($domain) - 1);
        if ($k === '*') {
            if (!preg_match('/^(:fail:|:blackhole:|"?:fail:|'.preg_quote($ruser, '/').'$)/', $v))
                $notes[] = "The default address of $domain went to \"$v\"; a catch-all is not taken.";
            continue;
        }
        if (!valid_email_user($k))
            continue;
        if (preg_match('/^"(.*)"$/', $v, $m))
            $v = trim($m[1]);
        if ($v !== '' && $v[0] === ':') {
            $notes[] = "$k@$domain answered \"".substr($v, 0, 60)."\" (".(strpos($v, ':blackhole:') === 0 ? 'discarded mail' : 'refused mail').'); not taken.';
            continue;
        }
        $to = array();
        $pipe = '';
        foreach (explode(',', $v) as $t) {
            $t = trim(trim($t), '"\'');
            if ($t === '')
                continue;
            if ($t[0] === '|') {
                if (strpos($t, 'autorespond') !== false)
                    continue;
                if ($pipe === '')
                    $pipe = trim(substr($t, 1));
                else
                    $notes[] = "$k@$domain: a second program ($t) is not taken.";
                continue;
            }
            if ($t === $ruser) {
                $notes[] = "$k@$domain also went to the cPanel account's own mailbox, which does not exist here.";
                continue;
            }
            if (strpos($t, '@') === false)
                $t .= '@'.$domain;
            if (valid_email_address(strtolower($t)))
                $to[] = strtolower($t);
            else
                $notes[] = "$k@$domain: \"$t\" is not an address; left out.";
        }
        if ($to || $pipe !== '')
            $fw[$k] = array('to' => array_values(array_unique($to)), 'pipe' => $pipe);
    }
    return array($fw, $notes);
}

/* certificate blocks of cPanel's apache_tls/<vhost>/combined: array(key, leaf + chain) */
function tr_cert_split($pem) {
    $key = preg_match('/-----BEGIN [A-Z ]*PRIVATE KEY-----.+?-----END [A-Z ]*PRIVATE KEY-----/s', (string)$pem, $m) ? $m[0] : '';
    preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', (string)$pem, $c);
    return array($key, implode("\n", $c[0]));
}


/* ======================================================================== */

function tr_account(array $a) {
    global $db, $ini, $JOB, $SSH;
    $u = $a['user'];
    $parts = $JOB['parts'];

    /* ---- what is there ---- */
    tr_step($u, 'Reading the cPanel account');
    $r = transfer_sites($SSH, array($u));
    if ($r['error'] !== '')
        throw new Exception($r['error']);
    $sites = $r['accounts'][0]['sites'] ?? array();
    $main = $r['accounts'][0]['domain'] ?? '';
    if ($main === '' || !$sites)
        throw new Exception("The cPanel server shows no sites for $u.");
    $f = tr_facts($u, $sites);
    $home = rtrim($f['home'], '/');
    $main_site = null;
    foreach ($sites as $s)
        if ($s['type'] === 'main')
            $main_site = $s;
    $maildoms = tr_mail_domains($f);
    tr_log($u, 'Sites: '.implode(', ', array_map(function ($s) { return $s['domain'].' ('.$s['type'].')'; }, $sites)));
    tr_ip_map($u, $sites, $f);
    if ($f['suspended'])
        tr_warn($u, 'The account is suspended on the cPanel server; it is active here.');

    /* room for it */
    $size = 0;
    if (preg_match('/^([0-9.]+)([KMGT]?)$/i', (string)$a['diskused'], $m))
        $size = (float)$m[1] * array('' => 1048576, 'K' => 1024, 'M' => 1048576, 'G' => 1073741824, 'T' => 1099511627776)[strtoupper($m[2])];
    $free = (float)@disk_free_space('/home');
    if ($size > 0 && $free > 0 && $size * 1.1 > $free)
        throw new Exception('Not enough space in /home: the account uses about '.transfer_bytes($size).', '.transfer_bytes($free).' is free.');

    /* ---- 1. the account ---- */
    tr_step($u, 'Account');
    $marker = tr_marker($u);
    $acct = account_get($db, $u);
    if ($acct) {
        /* the same account: its uid, and made when the record was (a uid can be given again) */
        if (!$marker || (int)($marker['uid'] ?? -1) !== (int)$acct['id']
            || abs(strtotime($acct['created_at'].' UTC') - (int)$marker['created']) > 900)
            throw new Exception("An account $u already exists here and was not made by a transfer; it is left alone.");
        if (strtolower($acct['domain']) !== $main)
            throw new Exception("The account $u here has the domain {$acct['domain']}, not $main.");
        tr_log($u, "Already here (made by a transfer on ".date('Y-m-d H:i', (int)$marker['created'])."): continuing it.");
    } else {
        if (($o = account_get($db, '', $main)))
            throw new Exception("$main is already the domain of the account {$o['user']} here.");
        if (($o = addon_domain_get($ini, $main)))
            throw new Exception("$main is already a site of the account {$o['user']} here.");
        $php = tr_local_php($u, (string)($main_site['version'] ?? ''), $main);
        $sh = basename($f['shell']);
        $shell = $sh === 'jailshell' ? 'jailed' : (in_array($sh, array('noshell', 'nologin', 'false'), true) ? 'none' : 'full');
        if ($shell === 'full' && !account_shell_full_allowed())
            $shell = 'jailed';
        $r = account_create($db, array('user' => $u, 'domain' => $main, 'password' => bin2hex(random_bytes(12)),
                                       'email' => !empty($parts['mail']) && $maildoms, 'mail_dns' => false, 'www' => true, 'dns' => false, 'letsencrypt' => false,
                                       'php' => $php, 'shell' => $shell));
        if (empty($r['ok']))
            throw new Exception('The account was not made: '.$r['error']);
        $marker = tr_marker($u, array('job' => $JOB['id'], 'server' => $JOB['server'], 'remote_user' => $u, 'created' => time(),
                                      'uid' => (int)$r['data']['uid'], 'dbs' => array(), 'dbusers' => array()));
        tr_log($u, "Account $u made with $main (PHP ".($php ?: 'default').", shell $shell).");
        tr_warnings($u, $r);
        $acct = account_get($db, $u);
    }
    /* the cPanel password, as it is (its hash) */
    if (preg_match('/^!*\$[0-9a-z]{1,3}\$[^\s:]{8,}$/', $f['hash'])) {
        $p = proc_open('chpasswd -e', array(0 => array('pipe', 'r'), 1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pp);
        fwrite($pp[0], $u.':'.$f['hash']."\n");
        fclose($pp[0]);
        if (proc_close($p) === 0)
            tr_log($u, 'Password: the same as on cPanel.');
        else
            tr_warn($u, 'The cPanel password could not be set; the account has a random one (set one in Accounts > Manage).');
    } else
        tr_warn($u, 'The cPanel password is in a form this server cannot use; the account has a random one (set one in Accounts > Manage).');
    /* its contact address, as confirmed, and only sign-in messages on: the move mails nobody (once, not again on a resume) */
    if (empty($marker['contact'])) {
        $r = account_contact_import($u, $f['contact']);
        if (empty($r['ok']))
            tr_warn($u, 'Contact address: '.$r['error']);
        else {
            if (!empty($r['data']['kept']))
                tr_log($u, 'Contact address: '.$r['data']['email'].' (already set here, kept).');
            elseif ($f['contact'] !== '')
                tr_log($u, 'Contact address: '.$f['contact'].' (confirmed, no message sent).');
            else
                tr_log($u, 'Contact address: none on cPanel.');
            tr_log($u, 'Messages to the account: only sign-ins from a new device are on.');
            $marker['contact'] = $f['contact'] !== '' ? $f['contact'] : '-';
            tr_marker($u, $marker);
        }
    }

    /* ---- 2. sites ---- */
    tr_step($u, 'Sites');
    $folders = array('public_html');
    $here_sites = array($main => true);
    foreach ($sites as $s) {
        $d = $s['domain'];
        if ($s['type'] === 'main' || $s['type'] === 'parked')
            continue;
        if (substr($d, 0, 2) === '*.') {
            tr_warn($u, "$d (all subdomains) is not taken: here that is an alias of the main site (Domains > Aliases).");
            continue;
        }
        $docroot = rtrim((string)$s['docroot'], '/');
        if (strpos($docroot, $home.'/') !== 0) {
            tr_warn($u, "$d: its folder $docroot is outside the home folder; the site is not made.");
            continue;
        }
        $folder = substr($docroot, strlen($home) + 1);
        if (($o = addon_domain_get($ini, $d))) {
            if ($o['user'] !== $u) {
                tr_warn($u, "$d is already a site of the account {$o['user']} here; left out.");
                continue;
            }
            tr_log($u, "$d: already here.");
        } else {
            $r = domain_addon_add($u, $d, $folder, null, isset($maildoms[$d]) && !empty($parts['mail']));
            if (empty($r['ok'])) {
                tr_warn($u, "$d was not made: ".$r['error'].' (its files still come with the home folder).');
                continue;
            }
            tr_log($u, "$d made (~/$folder).");
            tr_warnings($u, $r, "$d: ");
        }
        $folders[] = $folder;
        $here_sites[$d] = true;
    }
    foreach ($sites as $s) {
        if (!isset($here_sites[$s['domain']]) || (string)$s['version'] === '')
            continue;
        $want = tr_local_php($u, $s['version'], $s['domain']);
        $cur = php_site($u, $s['domain']);
        if ($want !== '' && $cur && $cur['version'] !== $want) {
            $r = php_version_set($u, $s['domain'], $want);
            if (empty($r['ok']))
                tr_warn($u, $s['domain'].': PHP '.$want.' was not set: '.$r['error']);
            else
                tr_log($u, $s['domain'].': PHP '.$want.'.');
        }
    }
    $have_alias = array_column(domain_aliases($u), 'alias');
    foreach ($sites as $s) {
        if ($s['type'] !== 'parked' || in_array($s['domain'], $have_alias, true))
            continue;
        $r = domain_alias_add($u, $s['domain'], false);
        if (empty($r['ok']))
            tr_warn($u, "Alias {$s['domain']} was not added: ".$r['error']);
        else
            tr_log($u, "Alias {$s['domain']} added.");
    }

    /* ---- 3. files ---- */
    $mail_bytes = 0;
    foreach ($f['doms'] as $x)
        $mail_bytes += $x['maildu'];
    if (!empty($parts['files'])) {
        tr_step($u, 'Files');
        /* the welcome page a new site gets, so the site's own index.html is not hidden behind it */
        $tpl = (string)@file_get_contents(_PATH.'/scripts/templates/default-template.html');
        foreach (array_unique($folders) as $fo) {
            $rd = files_read($u, $fo.'/index.php');
            if ($tpl !== '' && !empty($rd['ok']) && (string)($rd['data']['content'] ?? '') === $tpl)
                files_delete($u, array($fo.'/index.php'));
        }
        $script = <<<'SH'
cd "$1" || exit 3
set --
for n in .[!.]* ..?* *; do
  [ -e "$n" ] || [ -L "$n" ] || continue
  case "$n" in
    mail|etc|tmp|logs|access-logs|ssl|.cpanel|.trash|.caldav|.cphorde|.spamassassin|.autorespond|.cagefs|.cl.selector|.cpaddons|.sqmaildata|.contactemail|.lastlogin|.bash_profile|.bashrc|.bash_logout|.MirrorSearch|.cpobjcache) continue ;;
    www) [ -L www ] && continue ;;
  esac
  set -- "$@" "$n"
done
[ $# -gt 0 ] || exit 0
exec nice -n 10 ionice -c2 -n7 tar --numeric-owner -cf - -- "$@"
SH;
        $remote = 'set -- '.escapeshellarg($home)."\n".$script;
        $local = 'runuser -u '.escapeshellarg($u).' -- tar -xpf - -C '.escapeshellarg('/home/'.$u).' --no-overwrite-dir --no-same-owner';
        list($ok, $bytes, $e) = tr_pipe($u, $remote, $local, 'Copying the home folder', max(0, $size - $mail_bytes));
        tr_log($u, 'Copied '.transfer_bytes($bytes).'.');
        if (!$ok)
            tr_warn($u, 'The copy of the home folder reported problems'.($e !== '' ? ":\n".$e : '.'));
    }

    /* ---- 4. databases ---- */
    if (!empty($parts['databases']))
        tr_databases($u, $f, $marker);

    /* ---- 5. mail ---- */
    if (!empty($parts['mail']) && $maildoms)
        tr_mail($u, $f, $maildoms, $home);

    /* ---- 6. cron jobs ---- */
    if (!empty($parts['cron']) && trim($f['cron']) !== '') {
        tr_step($u, 'Cron jobs');
        $cr = transfer_cron_convert($f['cron'], $u, $u, cron_account_php($u));
        foreach ($cr['jobs'] as $j) {
            $res = cron_add($u, array('type' => 'command', 'command' => $j['command'], 'schedule' => $j['schedule'], 'name' => 'From cPanel', 'on' => $j['on']));
            if (!empty($res['ok']))
                tr_log($u, ($j['on'] ? '   ' : '   [paused] ').$j['schedule'].' '.$j['command']);
            elseif (strpos((string)($res['error'] ?? ''), 'already') !== false)
                tr_log($u, '   (already there) '.$j['schedule'].' '.$j['command']);
            else
                tr_warn($u, 'Not added: '.$j['schedule'].' '.$j['original'].' - '.($res['error'] ?? 'unknown error'));
            foreach ($j['notes'] as $n)
                tr_warn($u, $n);
        }
        foreach ($cr['skipped'] as $sk)
            tr_warn($u, 'Not taken: '.$sk[0].' - '.$sk[1]);
        foreach ($cr['notes'] as $n)
            tr_log($u, '   '.$n);
    }

    /* ---- 7. certificates ---- */
    if (!empty($parts['ssl']) && $f['ssl']) {
        tr_step($u, 'Certificates');
        $reload = false;
        foreach ($f['ssl'] as $d => $pem) {
            if (!isset($here_sites[$d]))
                continue;
            list($key, $chain) = tr_cert_split($pem);
            $x = $chain !== '' ? @openssl_x509_parse($chain) : false;
            if (!$key || !is_array($x)) {
                tr_log($u, "$d: no usable certificate on cPanel.");
                continue;
            }
            $issuer = (string)($x['issuer']['O'] ?? ($x['issuer']['CN'] ?? ''));
            if (($x['issuer'] ?? null) == ($x['subject'] ?? null)) {
                tr_log($u, "$d: cPanel had a self-signed certificate; not taken.");
                continue;
            }
            if ((int)$x['validTo_time_t'] < time()) {
                tr_log($u, "$d: the certificate on cPanel has expired; not taken.");
                continue;
            }
            $e = ssl_custom_install($d, $chain, $key, false);
            if ($e === '') {
                $reload = true;
                tr_log($u, "$d: certificate from $issuer, valid until ".date('Y-m-d', (int)$x['validTo_time_t']).'.');
            } else
                tr_warn($u, "$d: the certificate was not installed: $e");
        }
        if ($reload) {
            $ws = web_server(web_front($ini));
            shell_exec('systemctl reload '.escapeshellarg($ws['service']).' 2>&1');
        }
    }

    /* ---- after ---- */
    tr_step($u, 'Finishing');
    if (function_exists('wordpress_scan')) {
        $w = wordpress_scan($u);
        $n = is_array($w) ? count((array)($w['data']['sites'] ?? ($w['data'] ?? array()))) : 0;
        if (!empty($w['ok']))
            tr_log($u, 'WordPress sites found: '.$n.'.');
    }
    return true;
}

function tr_databases($u, array $f, array &$marker) {
    $dbmap = array();
    foreach ((array)($f['dbs']['mysql_databases'] ?? array()) as $name => $users)
        if (is_string($name))
            $dbmap[$name] = array_values(array_filter((array)$users, 'is_string'));
    if (!$dbmap)
        return;
    tr_step($u, 'Databases', count($dbmap).' to copy');

    $users = array();
    foreach ($dbmap as $name => $us)
        foreach ($us as $x)
            $users[$x] = true;
    foreach (array_keys($users) as $x)
        if (!valid_mysql_identifier($x) || in_array(strtolower($x), array('root', 'mysql', 'reqad', 'mariadb.sys'), true)) {
            tr_warn($u, "Database user $x is not taken (not a name this server can give an account).");
            unset($users[$x]);
        }

    /* passwords (hashes) and the other addresses of the users */
    $hashes = $hosts = array();
    if ($users) {
        $in = "'".implode("','", array_keys($users))."'";
        $rows = tr_remote('mysql -N -B -e '.escapeshellarg("SELECT User, Host FROM mysql.user WHERE User IN ($in)").' 2>/dev/null');
        $sql = '';
        foreach (explode("\n", $rows) as $l) {
            $p = explode("\t", trim($l));
            if (count($p) !== 2 || !isset($users[$p[0]]))
                continue;
            $hosts[$p[0]][] = $p[1];
            if ($p[1] === 'localhost')
                $sql .= "SELECT '@@U ".$p[0]."'; SHOW CREATE USER '".$p[0]."'@'localhost';\n";
        }
        $cur = '';
        foreach (explode("\n", $sql !== '' ? tr_remote('mysql -N -B --force 2>/dev/null <<\'REQADSQL\''."\n".$sql."REQADSQL\n") : '') as $l) {
            if (strpos($l, '@@U ') === 0)
                $cur = trim(substr($l, 4));
            elseif ($cur !== '' && preg_match("/'(\*[0-9A-Fa-f]{40})'/", $l, $m))
                $hashes[$cur] = strtoupper($m[1]);
        }
    }

    /* users first: views and triggers name them as their owners */
    $made_users = array();
    $local_users = array();
    foreach (mysql_rows("SELECT User FROM mysql.user WHERE Host = 'localhost'") as $x)
        $local_users[$x] = true;
    foreach (array_keys($users) as $x) {
        $ours = in_array($x, (array)$marker['dbusers'], true);
        if (isset($local_users[$x]) && !$ours) {
            tr_warn($u, "Database user $x already exists here (not made by this transfer); left alone.");
            continue;
        }
        if (strlen($x) > 80) {
            tr_warn($u, "Database user $x: the name is too long for MariaDB; not taken.");
            continue;
        }
        if (!isset($local_users[$x]) && ($e = limit_allows($u, 'dbuser', array('user' => $x))) !== '') {
            tr_warn($u, "Database user $x: $e");
            continue;
        }
        if (isset($hashes[$x]))
            $auth = 'IDENTIFIED BY PASSWORD '.mysql_quote($hashes[$x]);
        else {
            $pw = mailbox_random_password(20);
            $auth = 'IDENTIFIED BY '.mysql_quote($pw);
            tr_warn($u, "Database user $x: its password could not be copied; it has the new password $pw - put it in the site's configuration.");
        }
        $out = mysql_exec((isset($local_users[$x]) ? 'ALTER' : 'CREATE').' USER '.mysql_quote($x).'@'.mysql_quote('localhost').' '.$auth);
        if (trim($out) !== '') {
            tr_warn($u, "Database user $x was not made: ".trim($out));
            continue;
        }
        database_conn_apply($x, 'localhost', database_conn_limit($u));
        $made_users[$x] = true;
        if (!$ours) {
            $marker['dbusers'][] = $x;
            tr_marker($u, $marker);
        }
        if (strlen($x) > database_user_max())
            tr_log($u, "   $x: longer than ".database_user_max().' characters (works, but the panel cannot make such names).');
        foreach (array_diff($hosts[$x] ?? array(), array('localhost')) as $h)
            tr_warn($u, "Database user $x could also log in from $h on cPanel; add that address in Databases > Remote access if it is still needed.");
    }

    $local_dbs = array_flip(mysql_rows('SHOW DATABASES'));
    foreach ($dbmap as $name => $us) {
        if (!valid_mysql_identifier($name) || in_array($name, database_system_schemas(), true)) {
            tr_warn($u, "Database $name is not taken (not a name this server can use).");
            continue;
        }
        $ours = in_array($name, (array)$marker['dbs'], true);
        if (isset($local_dbs[$name])) {
            if (!$ours) {
                tr_warn($u, "Database $name already exists here (not made by this transfer); left alone.");
                continue;
            }
            mysql_exec('DROP DATABASE `'.$name.'`');
        }
        if (strpos($name, $u.'_') === 0 && strlen($name) > strlen($u) + 1) {
            $r = database_create(array('account' => $u, 'name' => substr($name, strlen($u) + 1)));
            if (empty($r['ok'])) {
                tr_warn($u, "Database $name was not made: ".$r['error']);
                continue;
            }
        } else {
            if (($e = limit_allows($u, 'database', array('database' => $name))) !== '') {
                tr_warn($u, "Database $name: $e");
                continue;
            }
            $out = mysql_exec('CREATE DATABASE `'.$name.'`');
            $r = trim($out) === '' ? database_assign($name, $u) : result_error(trim($out));
            if (empty($r['ok'])) {
                tr_warn($u, "Database $name was not made: ".$r['error']);
                continue;
            }
        }
        if (!$ours) {
            $marker['dbs'][] = $name;
            tr_marker($u, $marker);
        }

        /* owners of views, triggers and routines: the account's own users;
           any other (root, a user not taken) becomes the database's first user */
        $owner = '';
        foreach ($us as $x)
            if (isset($made_users[$x])) {
                $owner = $x;
                break;
            }
        $fallback = $owner !== '' ? $owner : $u;
        $filter = function ($l) use ($made_users, $fallback) {
            if (strncmp($l, 'INSERT INTO', 11) === 0)
                return $l;
            if (strncmp($l, 'SET @@GLOBAL.GTID_PURGED', 24) === 0 || strncmp($l, 'SET @@SESSION.SQL_LOG_BIN', 25) === 0)
                return '';
            if (strpos($l, '_0900_') !== false)
                $l = preg_replace('/\butf8mb4_0900_[a-z_]+/', 'utf8mb4_unicode_520_ci', $l);
            if (strpos($l, 'DEFINER=') !== false)
                $l = preg_replace_callback('/DEFINER=`([^`]*)`@`([^`]*)`/', function ($m) use ($made_users, $fallback) {
                    return 'DEFINER=`'.(isset($made_users[$m[1]]) ? $m[1] : $fallback).'`@`localhost`';
                }, $l);
            return $l;
        };
        $remote = 'nice -n 10 mysqldump --single-transaction --quick --routines --triggers --events --hex-blob --no-tablespaces'
                . ' --default-character-set=utf8mb4 --max-allowed-packet=1G '.escapeshellarg($name);
        list($ok, $bytes, $e) = tr_pipe($u, $remote, 'mysql --default-character-set=utf8mb4 --max-allowed-packet=1G '.escapeshellarg($name), "Database $name", 0, $filter);
        if ($ok)
            tr_log($u, "Database $name: ".transfer_bytes($bytes).' copied.');
        else
            tr_warn($u, "Database $name: the copy reported problems".($e !== '' ? ":\n".$e : '.'));
        foreach ($us as $x)
            if (isset($made_users[$x])) {
                $g = database_grant($x, $name, 'all');
                if (empty($g['ok']))
                    tr_warn($u, "$x could not be given $name: ".$g['error']);
            }
        tr_log($u, "   users: ".($us ? implode(', ', $us) : 'none'));
    }
}

function tr_mail($u, array $f, array $maildoms, $home) {
    global $JOB;
    tr_step($u, 'Email', count($maildoms).' mail domain'.(count($maildoms) === 1 ? '' : 's'));
    $stage = TR_VAR.'/'.$JOB['id'].'/'.$u;
    if (!is_dir($stage))
        mkdir($stage, 0700, true);
    $tar_doms = array();
    $skip = array();
    $made = 0;
    foreach (array_keys($maildoms) as $d) {
        $x = $f['doms'][$d];
        if (!email_domain_exists($d)) {
            tr_warn($u, "Mail is not on for $d here, so its mail is not taken.");
            unset($maildoms[$d]);
            continue;
        }
        /* mailboxes, with the cPanel passwords */
        foreach (tr_boxes($x['shadow']) as $box => $hash) {
            $email = $box.'@'.$d;
            if (in_array($box, $x['mdbox'], true)) {
                tr_warn($u, "$email: stored in cPanel's mdbox format, which is not copied; the mailbox is made empty.");
                $skip[] = $d.'/'.$box;
            }
            if (!in_array($email, mailbox_list(true), true)) {
                $r = email_create(array('email' => $email, 'password' => mailbox_random_password()));
                if (empty($r['ok'])) {
                    tr_warn($u, "$email was not made: ".$r['error']);
                    $skip[] = $d.'/'.$box;
                    continue;
                }
                tr_warnings($u, $r, "$email: ");
                $made++;
            }
            $e = mailbox_set_hash($email, $hash);
            if ($e !== '')
                tr_warn($u, "$email: the cPanel password was not set ($e); it has a random one.");
        }
        if (tr_boxes($x['shadow']))
            $tar_doms[] = $d;
    }
    tr_log($u, 'Mailboxes: '.$made.' made.');

    /* the mail itself, as the account (dovecot builds its indexes again) */
    if ($tar_doms) {
        $mb = 0;
        foreach ($tar_doms as $d)
            $mb += $f['doms'][$d]['maildu'];
        $st = files_run($u, array('verb' => 'stat', 'path' => 'mail'));
        if (empty($st['ok']))
            files_mkdir($u, 'mail');
        $ex = '';
        foreach ($skip as $s)
            $ex .= ' --exclude='.escapeshellarg($s);
        $remote = 'cd '.escapeshellarg($home.'/mail').' && exec nice -n 10 ionice -c2 -n7 tar --numeric-owner -cf -'
                . " --exclude='dovecot.index*' --exclude='dovecot.list.index*' --exclude=maildirsize --exclude='*.lock'".$ex
                . ' -- '.implode(' ', array_map('escapeshellarg', $tar_doms));
        $local = 'runuser -u '.escapeshellarg($u).' -- tar -xpf - -C '.escapeshellarg('/home/'.$u.'/mail').' --no-overwrite-dir --no-same-owner';
        list($ok, $bytes, $e) = tr_pipe($u, $remote, $local, 'Copying mail', $mb);
        tr_log($u, 'Mail: '.transfer_bytes($bytes).' copied.');
        if (!$ok)
            tr_warn($u, 'The copy of the mail reported problems'.($e !== '' ? ":\n".$e : '.'));
    }

    foreach (array_keys($maildoms) as $d) {
        $x = $f['doms'][$d];
        /* forwarders */
        list($fws, $notes) = tr_forwards($x['valiases'], $d, $u);
        foreach ($fws as $local => $fw) {
            $email = $local.'@'.$d;
            if (forwarder_get($email)) {
                tr_log($u, "   $email: forwarder already here.");
                continue;
            }
            $r = forwarder_create(array('email' => $email, 'to' => $fw['to'], 'pipe' => $fw['pipe']));
            if (empty($r['ok']) && $fw['pipe'] !== '' && $fw['to']) {
                tr_warn($u, "$email: the program |{$fw['pipe']} is not taken (".$r['error'].').');
                $r = forwarder_create(array('email' => $email, 'to' => $fw['to']));
            }
            if (empty($r['ok']))
                tr_warn($u, "Forwarder $email was not made: ".$r['error']);
            else
                tr_log($u, "   $email -> ".implode(', ', array_merge($fw['to'], $fw['pipe'] !== '' ? array('|'.$fw['pipe']) : array())));
        }
        foreach ($notes as $n)
            tr_warn($u, $n);

        /* DKIM key: the DNS record on the domain already has its public half */
        if ($x['dkim'] !== '' && ($k = @openssl_pkey_get_private($x['dkim']))) {
            $pub = (string)(openssl_pkey_get_details($k)['key'] ?? '');
            $base = '/etc/exim/keys/'.$d;
            if ($pub !== '' && @file_put_contents($base.'.private.key', $x['dkim']) !== false) {
                file_put_contents($base.'.public.key', $pub);
                foreach (array('.private.key' => 0640, '.public.key' => 0644) as $ext => $mode) {
                    @chown($base.$ext, 'exim');
                    @chgrp($base.$ext, 'mail');
                    @chmod($base.$ext, $mode);
                }
                tr_log($u, "   $d: DKIM key from cPanel.");
            }
        }

        /* filters */
        if ($x['vfilter'] !== '') {
            file_put_contents($stage.'/vfilter-'.$d, $x['vfilter']);
            tr_import($u, 'import_cpanel_filters.php', array('--scope=domain', '--target='.$d, '--file='.$stage.'/vfilter-'.$d, '--apply'));
        }
        foreach ($x['filters'] as $box => $text) {
            if (!in_array($box.'@'.$d, mailbox_list(true), true))
                continue;
            file_put_contents($stage.'/filter-'.$box.'@'.$d, $text);
            /* --replace: a transfer started again must not add the rules twice */
            tr_import($u, 'import_cpanel_filters.php', array('--scope=account', '--target='.$box.'@'.$d, '--file='.$stage.'/filter-'.$box.'@'.$d, '--apply', '--replace'));
        }

        /* the domain's mail is handled elsewhere (Google, Microsoft...) */
        if ($x['route'] === 'remote') {
            mail_remote_set($d, true);
            tr_log($u, "   $d: its mail is handled by another server (as on cPanel); mail from the sites goes there.");
        } elseif ($x['route'] === 'secondary')
            tr_warn($u, "$d was a backup mail server (secondary MX) on cPanel; here it receives the mail itself.");
    }

    /* autoresponders */
    if ($f['ar']) {
        $dir = $stage.'/.autorespond';
        if (!is_dir($dir))
            mkdir($dir, 0700);
        foreach ($f['ar'] as $n => $body)
            file_put_contents($dir.'/'.$n, $body);
        foreach (array_keys($maildoms) as $d)
            tr_import($u, 'import_cpanel_autoresponders.php', array('--domain='.$d, '--dir='.$dir, '--apply'));
    }

    /* SpamAssassin allow / block lists */
    if ($f['prefs'] !== '') {
        file_put_contents($stage.'/user_prefs', $f['prefs']);
        tr_import($u, 'import_cpanel_spam.php', array('--user='.$u, '--file='.$stage.'/user_prefs', '--apply'));
    }
}
