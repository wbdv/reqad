#!/opt/reqad/php-current/usr/bin/php -c/etc/reqad/php-fpm/php.ini
<?php
/**
 * Reqad - import_cpanel_cron.php
 *
 * Import a cPanel account's crontab (crontab -l -u <acct> on the cPanel server)
 * as cron jobs of a local account.
 *
 * The lines are changed for this server by transfer_cron_convert() (the same
 * function the transfer tool's pre-transfer check shows its preview with):
 *   - cPanel's PHP binaries (/opt/cpanel/ea-phpXY/root/usr/bin/php,
 *     /usr/local/bin/ea-phpXY, CloudLinux /opt/alt/phpXY) become this server's
 *     binary of the same version (the nearest newer one when it is missing);
 *     /usr/local/bin/php and /usr/bin/php, which on cPanel pick the account's
 *     version, become this server's binary of --php;
 *   - /home/<remote user>/ becomes /home/<local user>/;
 *   - a job that runs a cPanel program, or a program this server does not have,
 *     comes in paused; @reboot jobs and MAILTO / SHELL lines are not taken.
 * Each job is added with cron_add(), so it runs through Reqad's wrapper as the
 * account (jail, no overlapping runs, output kept) and shows on the Cron jobs
 * page. Jobs the account already has (same schedule and command) are skipped,
 * so running it twice adds nothing.
 *
 * DRY RUN BY DEFAULT: without --apply it only prints what it would add.
 *
 * Usage:
 *   import_cpanel_cron.php --user=<local account> --remote-user=<cPanel account>
 *                          --file=<crontab> [--php=X.Y] [--apply]
 */

if (posix_geteuid() !== 0)
    exit("ERROR: run as root\n");

/* Load the panel the way bin/reqad does: at file scope, so $db and $ini are globals. */
require_once '/usr/local/reqad/public_html/defines.php';
require_once '/usr/local/reqad/public_html/modules/functions.php';
foreach (glob(_PATH.'/app/functions/*.php') as $app_functions_file)
    require_once $app_functions_file;
plugins_load();   // add-on limits (Hosting Pro's cron limit) apply here too

$opt   = getopt('', array('user:', 'remote-user:', 'file:', 'php:', 'apply', 'help'));
$usage = "usage: import_cpanel_cron.php --user=<local account> --remote-user=<cPanel account> "
       . "--file=<crontab> [--php=X.Y] [--apply]\n";
if (isset($opt['help']) || !isset($opt['user']) || !isset($opt['remote-user']) || !isset($opt['file']))
    exit($usage);

$user  = strtolower(trim((string)$opt['user']));
$ruser = strtolower(trim((string)$opt['remote-user']));
$file  = (string)$opt['file'];
$apply = isset($opt['apply']);
$php   = (string)($opt['php'] ?? '');

if (!valid_username($user) || !account_get($db, $user))
    exit("ERROR: --user: no hosting account '$user' here\n");
if (!preg_match('/^[a-z0-9][a-z0-9_.\-]{0,31}$/', $ruser))
    exit("ERROR: --remote-user is not an account name\n");
if (!is_readable($file))
    exit("ERROR: cannot read $file\n");
if ($php !== '' && !preg_match('/^\d\.\d{1,2}$/', $php))
    exit("ERROR: --php must be a version such as 8.2\n");
if ($php === '')
    $php = cron_account_php($user);

$r = transfer_cron_convert((string)file_get_contents($file), $ruser, $user, $php);
if (!$r['jobs'] && !$r['skipped']) {
    echo "[cron] The cPanel account has no cron jobs.\n";
    exit(0);
}

$added = $failed = $already = 0;
foreach ($r['jobs'] as $j) {
    echo ($j['on'] ? '  ' : '  [paused] ').$j['schedule'].' '.$j['command']."\n";
    if ($j['command'] !== $j['original'])
        echo "      was: ".$j['original']."\n";
    foreach ($j['notes'] as $n)
        echo "      ! $n\n";
    if (!$apply)
        continue;
    $res = cron_add($user, array('type' => 'command', 'command' => $j['command'], 'schedule' => $j['schedule'],
                                 'name' => 'From cPanel', 'on' => $j['on']));
    if (!empty($res['ok'])) {
        $added++;
    } elseif (strpos((string)($res['error'] ?? ''), 'already') !== false) {
        $already++;
        echo "      (already there)\n";
    } else {
        $failed++;
        echo "      ! not added: ".($res['error'] ?? 'unknown error')."\n";
    }
}
foreach ($r['skipped'] as $s)
    echo "  [not taken] ".$s[0]."\n      ! ".$s[1]."\n";
foreach ($r['notes'] as $n)
    echo "  ! $n\n";

if ($apply)
    echo "[cron] $added job(s) added to $user".($already ? ", $already already there" : '').($failed ? ", $failed not added (see above)" : '').".\n";
else
    echo "[cron] Dry run: nothing was added. Run again with --apply.\n";
exit($failed ? 1 : 0);
