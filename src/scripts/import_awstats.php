#!/opt/reqad/php-current/usr/bin/php -c/etc/reqad/php-fpm/php.ini
<?php
/**
 * Reqad - import_awstats.php
 *
 * Bring a cPanel server's AWStats history into the visitor stats (Metrics >
 * Visitors and bandwidth, 2087 Bandwidth Usage): rows with src 'awstats' in
 * stats.db. A day Reqad counted itself keeps its own numbers.
 *
 * --dir is laid out per account:
 *   DIR/<user>/awstatsMMYYYY.<name>.txt        cPanel's ~/tmp/awstats/
 *   DIR/<user>/ssl/awstatsMMYYYY.<name>.txt    its ssl/ folder (HTTPS), added to the above
 *   DIR/<user>/domains.map                     optional: "<cPanel name> <domain>" lines;
 *                                              cPanel files an addon domain's stats under
 *                                              its subdomain twin (addon.main.tld)
 * A file is imported only into a site that <user> owns here (an account
 * writes its own ~/tmp/awstats, so it must not reach another one's site).
 *
 * What AWStats has, and where it goes:
 *   per day: pages, hits, bytes, visits -> pages, requests, bytes, visitors
 *   per month only: robots, errors and other not-viewed traffic -> spread
 *     evenly over the month's days (bots, requests, bytes, status codes);
 *     pages per hour -> spread over the days by their pages
 *   month lists: pages, 404s, referrers (+ search engines), bots, files,
 *     countries, browsers, systems (devices from the systems) - countries,
 *     browsers and systems count pages there, scaled here to visits
 *   never: the VISITOR section (addresses), worms, keywords
 * The month lists of a site are imported only for a month Reqad has no own
 * days of (they cannot be split by day). Months older than the stats keep
 * (25) are skipped. Running it again replaces what it imported before.
 *
 * DRY RUN BY DEFAULT. Nothing is written without --apply. Run as root.
 *
 * Usage: import_awstats.php --dir=DIR [--user=USER] [--apply] [--quiet]
 */

if (posix_geteuid() !== 0)
    exit("ERROR: run as root\n");

$opt = getopt('', array('dir:', 'user:', 'apply', 'quiet', 'stats-db:', 'help'));
if (isset($opt['help']) || !isset($opt['dir']))
    exit("usage: import_awstats.php --dir=DIR [--user=USER] [--apply] [--quiet]\n");
if (isset($opt['stats-db']))
    define('STATS_DB', (string)$opt['stats-db']);      // another file, for tests

require_once '/usr/local/reqad/public_html/defines.php';
require_once '/usr/local/reqad/public_html/modules/functions.php';
foreach (glob(_PATH.'/app/functions/*.php') as $app_functions_file)
    require_once $app_functions_file;

$DIR   = rtrim((string)$opt['dir'], '/');
$APPLY = isset($opt['apply']);
$QUIET = isset($opt['quiet']);
$ONLY  = (string)($opt['user'] ?? '');
if (!is_dir($DIR) || is_link($DIR))
    exit("ERROR: $DIR is not a folder\n");

$sdb = stats_db();
if (!$sdb)
    exit("ERROR: the stats database (".STATS_DB.") cannot be opened\n");
$owners = stats_sites();                        // domain => account here
$oldest = date('Y-m', strtotime(date('Y-m-01').' -'.(defined('STATS_KEEP_MONTHS') ? STATS_KEEP_MONTHS : 25).' months'));

$sum = array('sites' => 0, 'months' => 0, 'days' => 0, 'bytes' => 0, 'skipped' => array());
foreach (glob($DIR.'/*', GLOB_ONLYDIR) ?: array() as $udir) {
    $user = basename($udir);
    if (is_link($udir) || !preg_match('/^[a-z0-9][a-z0-9._-]{0,31}$/', $user) || ($ONLY !== '' && $user !== $ONLY))
        continue;
    $map = aw_map($udir.'/domains.map');
    /* name|month => [files] */
    $groups = array();
    foreach (array($udir, $udir.'/ssl') as $d) {
        if (!is_dir($d) || is_link($d))
            continue;
        foreach (glob($d.'/awstats*.txt') ?: array() as $file) {
            if (is_link($file) || !is_file($file)
                || !preg_match('/^awstats(0[1-9]|1[0-2])(\d{4})\.([a-z0-9.-]+)\.txt$/', basename($file), $m))
                continue;
            $groups[strtolower($m[3]).'|'.$m[2].'-'.$m[1]][] = $file;
        }
    }
    ksort($groups);
    foreach ($groups as $key => $files) {
        list($name, $month) = explode('|', $key);
        $site = $map[$name] ?? $name;
        $site = preg_replace('/^www\./', '', $site);
        $here = $owners[$site] ?? '';
        if ($here === '') {
            $sum['skipped'][$site] = "$user/$site: no such site here";
            continue;
        }
        if ($here !== $user) {
            $sum['skipped'][$site] = "$user/$site: the site belongs to $here here, not $user";
            continue;
        }
        if ($month < $oldest)
            continue;
        $a = aw_new();
        foreach ($files as $file)
            aw_read($file, $month, $a);
        if (!$a['days'])
            continue;
        $own = (int)$sdb->querySingle("SELECT COUNT(*) FROM stats_days WHERE site = '".$sdb->escapeString($site)."' AND user = '"
                                     .$sdb->escapeString($user)."' AND src = 'log' AND day LIKE '$month-%'");
        $rows = aw_day_rows($a);
        $lists = $own ? array() : aw_lists($a, $site);
        $t = array('p' => 0, 'v' => 0, 'r' => 0, 'b' => 0);
        foreach ($rows as $r) {
            $t['p'] += $r['pages']; $t['v'] += $r['visitors']; $t['r'] += $r['requests']; $t['b'] += $r['bytes'];
        }
        if (!$QUIET)
            printf("%s %-40s %s: %2d days, %s visits, %s pages, %s requests, %s%s\n", $APPLY ? 'imported' : 'would import',
                   $site, $month, count($rows), number_format($t['v']), number_format($t['p']), number_format($t['r']),
                   aw_bytes($t['b']), $own ? " (lists not taken: $own own day".($own === 1 ? '' : 's').' this month)' : '');
        if ($APPLY)
            aw_write($sdb, $site, $user, $month, $rows, $own ? null : $lists);
        $sum['months']++;
        $sum['days'] += count($rows);
        $sum['bytes'] += $t['b'];
        $sum['sites_seen'][$site] = 1;
    }
}
$sum['sites'] = count($sum['sites_seen'] ?? array());
foreach ($sum['skipped'] as $s)
    echo "skipped  $s\n";
printf("%s: %d site%s, %d month%s, %d days, %s.%s\n", $APPLY ? 'Imported' : 'Dry run', $sum['sites'], $sum['sites'] === 1 ? '' : 's',
       $sum['months'], $sum['months'] === 1 ? '' : 's', $sum['days'], aw_bytes($sum['bytes']), $APPLY ? '' : ' Nothing written; add --apply.');
if ($APPLY && $sum['months'])
    route_log('stats: AWStats imported from '.$DIR.' ('.$sum['sites'].' sites, '.$sum['months'].' months)');
exit(0);

/* ---------------------------------------------------------------------------- */

/* "<cPanel name> <domain>" lines */
function aw_map($file) {
    $out = array();
    if (!is_file($file) || is_link($file))
        return $out;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
        $p = preg_split('/\s+/', strtolower(trim($l)));
        if (count($p) === 2 && valid_domain($p[0]) && valid_domain($p[1]))
            $out[$p[0]] = $p[1];
    }
    return $out;
}

function aw_new() {
    return array('days' => array(), 'time' => array_fill(0, 24, 0), 'nv_h' => 0, 'nv_b' => 0, 'bot_h' => 0, 'bot_b' => 0,
                 'err' => array(3 => 0, 4 => 0, 5 => 0), 'top' => array(), 'pp' => array());
}

/* a count from the file: digits only (the account can write the file), capped */
function aw_n($s) {
    return ctype_digit((string)$s) && strlen($s) <= 15 ? (int)$s : 0;
}

/* a list item from the file: printable, no query string, at most 200 bytes */
function aw_item($s, $path = false) {
    $s = preg_replace('/[\x00-\x20\x7f]/', '', (string)$s);
    if ($path) {
        if (($q = strpos($s, '?')) !== false) $s = substr($s, 0, $q);
        if ($s === '' || $s[0] !== '/') return '';
    }
    return substr($s, 0, 200);
}

function aw_add(array &$a, $kind, $item, $n, $bytes = 0) {
    if ($item === '' || ($n <= 0 && $bytes <= 0))
        return;
    if (!isset($a['top'][$kind][$item]))
        $a['top'][$kind][$item] = array(0, 0);
    $a['top'][$kind][$item][0] += $n;
    $a['top'][$kind][$item][1] += $bytes;
}

/* Add one AWStats data file of $month (YYYY-MM) to $a. Read line by line:
   the VISITOR section (addresses) is passed over, never kept. */
function aw_read($file, $month, array &$a) {
    $fh = @fopen($file, 'r');
    if (!$fh)
        return;
    $ym = str_replace('-', '', $month);
    $sec = '';
    while (($l = fgets($fh, 8192)) !== false) {
        if ($l === '' || $l[0] === '#')
            continue;
        $l = rtrim($l, "\r\n");
        if (strncmp($l, 'BEGIN_', 6) === 0) { $sec = strtok(substr($l, 6), ' '); continue; }
        if (strncmp($l, 'END_', 4) === 0) { $sec = ''; continue; }
        if ($sec === '' || $sec === 'VISITOR')
            continue;
        $p = explode(' ', $l);
        switch ($sec) {
        case 'DAY':         // date pages hits bandwidth visits
            if (!preg_match('/^'.$ym.'(\d\d)$/', $p[0], $m) || !checkdate((int)substr($ym, 4), (int)$m[1], (int)substr($ym, 0, 4)))
                break;
            $d = $month.'-'.$m[1];
            if (!isset($a['days'][$d])) $a['days'][$d] = array(0, 0, 0, 0);
            foreach (array(1, 2, 3, 4) as $i)
                $a['days'][$d][$i - 1] += aw_n($p[$i] ?? '');
            break;
        case 'TIME':        // hour pages hits bandwidth notviewed-pages notviewed-hits notviewed-bandwidth
            $h = aw_n($p[0]);
            if ($h > 23 || !ctype_digit($p[0])) break;
            $a['time'][$h] += aw_n($p[1] ?? '');
            $a['nv_h'] += aw_n($p[5] ?? '');
            $a['nv_b'] += aw_n($p[6] ?? '');
            break;
        case 'ROBOT':       // id hits bandwidth lastvisit robots.txt
            $h = aw_n($p[1] ?? ''); $b = aw_n($p[2] ?? '');
            $a['bot_h'] += $h; $a['bot_b'] += $b;
            aw_add($a, 'bot', aw_bot($p[0]), $h, $b);
            break;
        case 'ERRORS':      // code hits bandwidth
            $c = intdiv(aw_n($p[0]), 100);
            if ($c >= 3 && $c <= 5 && strlen($p[0]) === 3) $a['err'][$c] += aw_n($p[1] ?? '');
            break;
        case 'SIDER':       // url pages bandwidth entry exit
            aw_add($a, 'page', aw_item($p[0], true), aw_n($p[1] ?? ''));
            aw_add($a, 'file', aw_item($p[0], true), aw_n($p[1] ?? ''), aw_n($p[2] ?? ''));
            break;
        case 'DOWNLOADS':   // url hits 206-hits bandwidth
            aw_add($a, 'file', aw_item($p[0], true), aw_n($p[1] ?? '') + aw_n($p[2] ?? ''), aw_n($p[3] ?? ''));
            break;
        case 'SIDER_404':   // url hits referrer
            aw_add($a, '404', aw_item($p[0], true), aw_n($p[1] ?? ''));
            break;
        case 'PAGEREFS':    // url pages hits
            $host = strtolower((string)parse_url(aw_item($p[0]), PHP_URL_HOST));
            aw_add($a, 'ref', preg_replace('/^www\./', '', $host), aw_n($p[1] ?? ''));
            break;
        case 'SEREFERRALS': // engine-id pages hits
            aw_add($a, 'ref', aw_engine($p[0]), aw_n($p[1] ?? ''));
            break;
        case 'DOMAIN':      // tld pages hits bandwidth
            $cc = strtoupper($p[0]) === 'UK' ? 'GB' : strtoupper($p[0]);
            if (preg_match('/^[A-Z]{2}$/', $cc) && !in_array($cc, array('EU', 'SU', 'IP', 'A1', 'A2', 'AP', 'O1'), true))
                $a['pp']['country'][$cc] = ($a['pp']['country'][$cc] ?? 0) + aw_n($p[1] ?? '');
            break;
        case 'BROWSER':     // id hits pages
            $b = aw_browser($p[0]);
            $a['pp']['browser'][$b] = ($a['pp']['browser'][$b] ?? 0) + aw_n($p[2] ?? '');
            break;
        case 'OS':          // id hits pages
            list($o, $dev) = aw_os($p[0]);
            $a['pp']['os'][$o] = ($a['pp']['os'][$o] ?? 0) + aw_n($p[2] ?? '');
            $a['pp']['device'][$dev] = ($a['pp']['device'][$dev] ?? 0) + aw_n($p[2] ?? '');
            break;
        }
    }
    fclose($fh);
}

/* AWStats robot ids are patterns (Googlebot\-Image/): Reqad's bot names, else the catch-all */
function aw_bot($id) {
    $id = stripslashes($id);
    foreach (stats_bot_names() as $name => $p)
        if (preg_match('/'.$p.'/i', $id))
            return $name;
    return 'Other bots and scanners';
}

/* search engine ids (www_google_com, google_catchall, baidu) as host names */
function aw_engine($id) {
    $h = strtolower(str_replace('_', '.', preg_replace('/_catchall$/', '', aw_item($id))));
    $h = preg_replace('/^www\./', '', $h);
    if ($h !== '' && strpos($h, '.') === false)
        $h .= '.com';
    return preg_match('/^[a-z0-9.-]+$/', $h) ? $h : '';
}

function aw_browser($id) {
    $id = strtolower($id);
    foreach (array('edge' => 'Edge', 'opera' => 'Opera', 'samsung' => 'Samsung Internet', 'yandex' => 'Yandex Browser',
                   'firefox' => 'Firefox', 'chrome' => 'Chrome', 'safari' => 'Safari') as $k => $name)
        if (strpos($id, $k) === 0)
            return $name;
    return 'Other';
}

/* array(system, device) of an AWStats OS id */
function aw_os($id) {
    $id = strtolower($id);
    if ($id === 'ios_ipad')               return array('iOS', 'Tablet');
    if (strpos($id, 'ios') === 0)         return array('iOS', 'Phone');
    if (strpos($id, 'android') === 0)     return array('Android', 'Phone');
    if (strpos($id, 'win') === 0)         return array('Windows', $id === 'wince' || $id === 'winphone' ? 'Phone' : 'Computer');
    if (strpos($id, 'cros') === 0 || strpos($id, 'chromeos') === 0) return array('ChromeOS', 'Computer');
    if (strpos($id, 'mac') === 0)         return array('macOS', 'Computer');
    if (strpos($id, 'linux') === 0 || strpos($id, 'unix') === 0 || preg_match('/bsd$/', $id)) return array('Linux', 'Computer');
    return array('Other', 'Computer');
}

/* $total spread over $n days: day $i's share (the remainder goes to the first days) */
function aw_share($total, $n, $i) {
    return $n ? intdiv($total, $n) + ($i < $total % $n ? 1 : 0) : 0;
}

/* stats_days rows (without site/user) of the month */
function aw_day_rows(array $a) {
    ksort($a['days']);
    $n = count($a['days']);
    $mp = 0;
    foreach ($a['days'] as $D) $mp += $D[0];
    $rows = array();
    $i = 0;
    foreach ($a['days'] as $day => $D) {
        list($p, $h, $b, $v) = $D;
        $req = $h + aw_share($a['nv_h'], $n, $i);
        $s = array();
        foreach (array(3, 4, 5) as $c)
            $s[$c] = aw_share($a['err'][$c], $n, $i);
        $hours = array();
        foreach ($a['time'] as $hp)
            $hours[] = $mp ? (int)round($hp * $p / $mp) : 0;
        $rows[] = array('day' => $day, 'visitors' => $v, 'pages' => $p, 'requests' => $req, 'bytes' => $b + aw_share($a['nv_b'], $n, $i),
                        'bot_requests' => aw_share($a['bot_h'], $n, $i), 'bot_bytes' => aw_share($a['bot_b'], $n, $i),
                        's2' => max(0, $req - $s[3] - $s[4] - $s[5]), 's3' => $s[3], 's4' => $s[4], 's5' => $s[5], 'hours' => json_encode($hours));
        $i++;
    }
    return $rows;
}

/* kind => item => [n, bytes] for the month; per-visitor lists scaled from pages to visits */
function aw_lists(array $a, $site) {
    $top = $a['top'];
    $self = preg_replace('/^www\./', '', $site);
    foreach (array_keys($top['ref'] ?? array()) as $host)
        if ($host === $self || substr($host, -strlen('.'.$self)) === '.'.$self)
            unset($top['ref'][$host]);
    $pages = $visits = 0;
    foreach ($a['days'] as $D) { $pages += $D[0]; $visits += $D[3]; }
    $k = $pages ? $visits / $pages : 0;
    foreach (array('country', 'browser', 'os', 'device') as $kind)
        foreach ($a['pp'][$kind] ?? array() as $item => $p)
            if (($n = (int)round($p * $k)) > 0)
                $top[$kind][$item] = array($n, 0);
    return $top;
}

/* Replace what an earlier import wrote for the site's month. $lists null:
   Reqad counted days of that month itself, its lists stay as they are. */
function aw_write($sdb, $site, $user, $month, array $rows, $lists) {
    $sdb->exec('BEGIN IMMEDIATE');
    $del = $sdb->prepare("DELETE FROM stats_days WHERE site = :s AND src = 'awstats' AND day LIKE :m");
    $del->bindValue(':s', $site, SQLITE3_TEXT);
    $del->bindValue(':m', $month.'-%', SQLITE3_TEXT);
    $del->execute();
    $ins = $sdb->prepare("INSERT INTO stats_days (site, user, day, src, visitors, pages, requests, bytes, bot_requests, bot_bytes, s2, s3, s4, s5, hours)
        VALUES (:site, :user, :day, 'awstats', :visitors, :pages, :requests, :bytes, :bot_requests, :bot_bytes, :s2, :s3, :s4, :s5, :hours)");
    foreach ($rows as $r) {
        $ins->bindValue(':site', $site, SQLITE3_TEXT);
        $ins->bindValue(':user', $user, SQLITE3_TEXT);
        foreach ($r as $k => $v)
            $ins->bindValue(':'.$k, $v, is_int($v) ? SQLITE3_INTEGER : SQLITE3_TEXT);
        $ins->execute();
        $ins->reset();
    }
    if ($lists !== null) {
        $st = $sdb->prepare('DELETE FROM stats_top WHERE site = :s AND user = :u AND period = :p');
        $st->bindValue(':s', $site, SQLITE3_TEXT);
        $st->bindValue(':u', $user, SQLITE3_TEXT);
        $st->bindValue(':p', $month, SQLITE3_TEXT);
        $st->execute();
        $top = $sdb->prepare('INSERT INTO stats_top (site, user, period, kind, item, n, bytes) VALUES (:s, :u, :p, :k, :i, :n, :b)');
        foreach ($lists as $kind => $items) {
            /* the month keeps its top 200 of each list, as Reqad's own months do */
            uasort($items, $kind === 'file' ? function ($x, $y) { return $y[1] <=> $x[1]; } : function ($x, $y) { return $y[0] <=> $x[0]; });
            foreach (array_slice($items, 0, 200, true) as $item => $v) {
                foreach (array(':s' => $site, ':u' => $user, ':p' => $month, ':k' => $kind, ':i' => (string)$item) as $k => $x)
                    $top->bindValue($k, $x, SQLITE3_TEXT);
                $top->bindValue(':n', $v[0], SQLITE3_INTEGER);
                $top->bindValue(':b', $v[1], SQLITE3_INTEGER);
                $top->execute();
                $top->reset();
            }
        }
    }
    $sdb->exec('COMMIT');
}

function aw_bytes($b) {
    foreach (array('GB' => 1073741824, 'MB' => 1048576, 'KB' => 1024) as $u => $n)
        if ($b >= $n) return round($b / $n, 1).' '.$u;
    return $b.' B';
}
