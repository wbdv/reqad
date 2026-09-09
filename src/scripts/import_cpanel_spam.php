#!/opt/reqad/php-current/usr/bin/php -c/etc/reqad/php-fpm/php.ini
<?php
/**
 * Reqad - import_cpanel_spam.php
 *
 * Import a cPanel account's SpamAssassin whitelist / blocklist.
 *
 *   cPanel path                              what it holds
 *   /home/<acct>/.spamassassin/user_prefs    whitelist_from / blacklist_from
 *
 * cPanel keeps these in exactly the file SpamAssassin reads, and so does Reqad,
 * so this is a merge rather than a conversion: the remote file is parsed, its
 * two lists are added to whatever the local account already has, and every other
 * preference in the LOCAL file (a score threshold, a hand-added rule) is left
 * alone. Preferences other than the two lists are NOT taken from the remote
 * file - they are the old server's tuning and often reference its rule set.
 *
 * DRY RUN BY DEFAULT. Nothing is written without --apply, because a bad import
 * silently changes which mail is treated as spam.
 *
 * Usage:
 *   import_cpanel_spam.php --user=<local account> --file=F [--apply] [--replace]
 *
 * --replace discards the local account's current lists instead of merging into
 * them. Use it to RE-import an account cleanly.
 *
 * The shebang pins Reqad's own php.ini: the stock CLI ini disables exec(),
 * which sa_helper() needs to reach the privileged helper.
 */

require_once '/usr/local/reqad/public_html/defines.php';
require_once '/usr/local/reqad/public_html/modules/functions.php';

$opt   = getopt('', array('user:', 'file:', 'apply', 'replace', 'help'));
$usage = "usage: import_cpanel_spam.php --user=<local account> "
       . "--file=<cPanel user_prefs> [--apply] [--replace]\n";

if (isset($opt['help']) || !isset($opt['user']) || !isset($opt['file']))
    exit($usage);

$user    = strtolower(trim((string)$opt['user']));
$file    = (string)$opt['file'];
$apply   = isset($opt['apply']);
$replace = isset($opt['replace']);

if (!sa_valid_account($user))
    exit("ERROR: --user must be a system account name\n");
if (!is_readable($file))
    exit("ERROR: cannot read $file\n");

$accounts = sa_accounts();
if (!isset($accounts[$user]))
    exit("ERROR: $user owns no mail domain in /etc/exim/userdomains, so SpamAssassin\n"
       . "       would never be asked for its preferences. Enable email for it first.\n");

/* The remote file is read with the same parser the panel uses, so an entry that
   would not be usable here is dropped in exactly the same way - and reported,
   never silently. */
$remote_src = file_get_contents($file);
$remote     = sa_parse_prefs($remote_src);

/* What the parser refused, so it can be re-added by hand rather than lost. */
$skipped = array();
foreach (explode("\n", str_replace("\r\n", "\n", $remote_src)) as $line) {
    $code = trim(preg_replace('/(?<!\\\\)#.*$/', '', trim($line)));
    if ($code === '' || !preg_match('/^(whitelist_from|welcomelist_from|blacklist_from|blocklist_from)\s+(.+)$/i', $code, $m))
        continue;
    foreach (preg_split('/\s+/', trim($m[2])) as $p)
        if (sa_normalize_pattern($p) === '')
            $skipped[] = $m[1] . ' ' . $p;
}

printf("%s → account %s (%s)\n", $file, $user, implode(', ', $accounts[$user]));
printf("  %d whitelisted, %d blocklisted sender(s) found\n\n", count($remote['white']), count($remote['black']));

foreach ($remote['white'] as $p) printf("  WHITELIST  %s\n", $p);
foreach ($remote['black'] as $p) printf("  BLOCKLIST  %s\n", $p);

if ($skipped) {
    print "\n  SKIPPED - not usable as a sender address, add these by hand if you want them:\n";
    foreach ($skipped as $s)
        printf("    · %s\n", $s);
}

/* The other preferences in the remote file are shown but never taken: scores and
   custom rules belong to the server they were tuned on. */
$carried = array();
foreach ($remote['other'] as $l)
    if (trim($l) !== '' && trim($l)[0] !== '#')
        $carried[] = trim($l);
if ($carried) {
    print "\n  NOT IMPORTED - other preferences in the remote file, shown for reference:\n";
    foreach ($carried as $l)
        printf("    · %s\n", $l);
}

if (!$remote['white'] && !$remote['black'])
    exit("\nNothing to import.\n");

$local = sa_lists_get($user);
if ($replace) {
    printf("\n--replace: discarding the %d whitelisted and %d blocklisted sender(s) already on this account.\n",
           count($local['white']), count($local['black']));
    $local['white'] = $local['black'] = array();
}

$white = array_values(array_unique(array_merge($local['white'], $remote['white'])));
$black = array_values(array_unique(array_merge($local['black'], $remote['black'])));

/* An address on both lists cancels itself out in SpamAssassin. Block wins, the
   same way the panel's bulk editor resolves it. */
$dupes = array_intersect($white, $black);
if ($dupes)
    $white = array_values(array_diff($white, $dupes));

printf("\n  Result: %d whitelisted, %d blocklisted (%d and %d already there).\n",
       count($white), count($black),
       count($local['white']), count($local['black']));
if ($dupes)
    printf("  %d address(es) ended up on both lists and were kept on the blocklist only.\n",
           count($dupes));

if (!$apply)
    exit("\nDry run - nothing written. Re-run with --apply to import.\n");

$err = sa_lists_put($user, $white, $black);
if ($err !== '')
    exit("\nERROR: nothing was written. $err\n");

printf("\nImported into %s: %d whitelisted and %d blocklisted sender(s) are now in place.\n",
       $user, count($white), count($black));
