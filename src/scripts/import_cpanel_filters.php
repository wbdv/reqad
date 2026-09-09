#!/opt/reqad/php-current/usr/bin/php -c/etc/reqad/php-fpm/php.ini
<?php
/**
 * Reqad — import_cpanel_filters.php
 *
 * Convert a cPanel (or DirectAdmin) Exim filter file into Reqad filters.
 * The transfer tool pulls the files; this turns them into rules.
 *
 *   cPanel path                                   scope
 *   /etc/cpanel_exim_system_filter                global
 *   /etc/vfilters/<domain>                        domain <domain>
 *   /home/<acct>/etc/<domain>/<lp>/filter         account <lp>@<domain>
 *
 * DirectAdmin: /etc/system_filter.exim → global, /etc/virtual/<d>/filter →
 * domain. DA's per-account filters are already Sieve and need no converter —
 * they ride along in the mail tar, exactly like ours do.
 *
 * DRY RUN BY DEFAULT. Nothing is written without --apply, because a bad import
 * silently changes where someone's mail goes.
 *
 * Usage:
 *   import_cpanel_filters.php --scope=global               --file=F [--apply]
 *   import_cpanel_filters.php --scope=domain  --target=D   --file=F [--apply]
 *   import_cpanel_filters.php --scope=account --target=U@D --file=F [--apply]
 *
 * --replace (account scope only) discards the mailbox's existing rules instead
 * of appending to them. Use it to RE-import a mailbox: a plain re-run would
 * leave two copies of every rule.
 *
 * The shebang pins Reqad's own php.ini: the stock CLI ini disables exec(),
 * which ef_helper() needs to reach the privileged Sieve helper.
 */

require_once '/usr/local/reqad/public_html/defines.php';
require_once '/usr/local/reqad/public_html/modules/functions.php';

$opt    = getopt('', array('scope:', 'target::', 'file:', 'apply', 'replace', 'help'));
$usage  = "usage: import_cpanel_filters.php --scope=global|domain|account "
        . "[--target=<domain|user@domain>] --file=<exim filter file> [--apply] [--replace]\n";

if (isset($opt['help']) || !isset($opt['scope']) || !isset($opt['file']))
    exit($usage);

$scope  = (string)$opt['scope'];
$target = isset($opt['target']) ? strtolower(trim((string)$opt['target'])) : '';
$file   = (string)$opt['file'];
$apply  = isset($opt['apply']);
$replace = isset($opt['replace']);

if (!in_array($scope, array('global', 'domain', 'account'), true))
    exit($usage);
if ($scope === 'global')  $target = '';
if ($scope === 'domain'  && !valid_domain($target))
    exit("ERROR: --target must be a domain for --scope=domain\n");
if ($scope === 'account' && !valid_email_address($target))
    exit("ERROR: --target must be user@domain for --scope=account\n");
if (!is_readable($file))
    exit("ERROR: cannot read $file\n");

/* What the Exim variables in this file expand to. cPanel writes plus-address
   redirects as `deliver "\"$local_part+tag\"@$domain"`, and Sieve has no
   variable expansion — so they are resolved here, against the address whose
   filter file this is. An account import knows both halves; a domain import
   knows only the domain; a global import knows neither, and any rule that needs
   one is skipped with its raw text rather than imported broken. */
$ctx = array();
if ($scope === 'account') {
    $ctx['localpart'] = substr($target, 0, strrpos($target, '@'));
    $ctx['domain']    = substr($target, strrpos($target, '@') + 1);
} else if ($scope === 'domain') {
    $ctx['domain']    = $target;
}

$res = exim_filter_parse(file_get_contents($file), $ctx);

printf("%s → %s%s\n", $file, $scope, $target !== '' ? " $target" : '');
printf("  %d rule(s) converted, %d skipped\n\n", count($res['rules']), count($res['skipped']));

foreach ($res['rules'] as $r) {
    printf("  RULE  %s  (match %s%s)\n", $r['name'], $r['match_type'], $r['stop'] ? ', stops' : '');
    foreach (json_decode($r['conditions'], true) as $c)
        printf("          if   %s %s %s\n", $c['field'], str_replace('not-', 'not ', $c['op']), $c['value']);
    foreach (json_decode($r['actions'], true) as $a)
        printf("          then %s%s\n", $a['type'], $a['value'] !== '' ? ' "' . $a['value'] . '"' : '');
}

/* Never half-translate: what could not be converted is shown verbatim so the
   admin can recreate it by hand, and it is NOT counted as imported. */
if ($res['skipped']) {
    print "\n  SKIPPED — these were left out and need doing by hand:\n";
    foreach ($res['skipped'] as $s) {
        printf("    · %s\n", $s['why']);
        foreach (explode("\n", $s['raw']) as $line)
            printf("        %s\n", $line);
    }
}

if (!$res['rules'])
    exit("\nNothing to import.\n");

/* Show exactly what would run, dry run or not. */
$sieve_preview = rtrim(sieve_render_rules($res['rules'], $scope !== 'account'));
print "\n  Sieve that this produces:\n";
foreach (explode("\n", $sieve_preview) as $line)
    print "    $line\n";

/* Sanity gate: every converted rule must actually appear in the script. The
   renderer drops rules it considers no-ops, and a rule that is counted as
   imported but silently absent is the worst possible outcome — it looks like it
   worked. (This caught cPanel's `if <test> then finish endif`, a bare stop.) */
$rendered = substr_count($sieve_preview, '# rule:[');
if ($rendered < count($res['rules']))
    printf("\n  WARNING: %d rule(s) converted but only %d reached the script — the rest\n"
         . "           render to nothing. Check the Sieve above before applying.\n",
           count($res['rules']), $rendered);

if (!$apply)
    exit("\nDry run — nothing written. Re-run with --apply to import.\n");

if ($scope === 'account') {
    /* File-truth tier: append to whatever the mailbox already has rather than
       replacing it, and re-read first — Roundcube may have written since.
       --replace drops what is there instead, which is what a RE-import needs:
       appending would give the mailbox two copies of every rule. */
    $existing = sieve_parse_script(sieve_user_get($target), false);
    if ($existing === false)
        exit("\nERROR: this mailbox already has a Sieve script Reqad cannot parse.\n"
           . "Import refused — merging into it by hand is safer than guessing.\n");
    if ($replace) {
        printf("\n--replace: discarding the %d rule(s) already in this mailbox's script.\n",
               count($existing));
        $existing = array();
    }
    $err = ef_write_account($target, array_merge($existing, $res['rules']));
    if ($err !== '')
        exit("\nERROR: the imported rules did not compile, nothing was written.\n$err\n");
    printf("\nImported %d rule(s) into %s (%d were already there).\n",
           count($res['rules']), $target, count($existing));
    exit(0);
}

/* Admin tiers are DB-truth. INSERT OR IGNORE on (scope,target,name), so a
   re-run does not duplicate and an existing rule of the same name wins. */
$db = new SQLite3('/usr/local/reqad/db/reqad.db');
$added = $skipped_dupe = 0;
foreach ($res['rules'] as $r) {
    $st = $db->prepare('INSERT OR IGNORE INTO email_filters
                        (scope, target, name, enabled, priority, match_type, conditions, actions, stop)
                        VALUES (:s, :t, :n, 1, :p, :m, :c, :a, :st)');
    $st->bindValue(':s',  $scope,           SQLITE3_TEXT);
    $st->bindValue(':t',  $target,          SQLITE3_TEXT);
    $st->bindValue(':n',  $r['name'],       SQLITE3_TEXT);
    $st->bindValue(':p',  $r['priority'],   SQLITE3_INTEGER);
    $st->bindValue(':m',  $r['match_type'], SQLITE3_TEXT);
    $st->bindValue(':c',  $r['conditions'], SQLITE3_TEXT);
    $st->bindValue(':a',  $r['actions'],    SQLITE3_TEXT);
    $st->bindValue(':st', $r['stop'],       SQLITE3_INTEGER);
    $st->execute();
    if ($db->changes() > 0) $added++; else $skipped_dupe++;
}

$err = sieve_write_system($db, $scope, $target);
if ($err !== '')
    exit("\nERROR: the rules were stored but the script did not compile: $err\n"
       . "Fix or delete them in the panel (Email Filters).\n");

printf("\nImported %d rule(s)%s. The %s filter script has been rebuilt.\n",
       $added,
       $skipped_dupe ? ", $skipped_dupe skipped as a name that already exists" : '',
       $scope);
