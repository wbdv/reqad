#!/opt/reqad/php-current/usr/bin/php -c/etc/reqad/php-fpm/php.ini
<?php
/*
 * exim_error_parser.php -- collect SMTP delivery failures into a log file
 *
 * Run from cron every minute. Reads the permanent-failure ("**") lines out of
 * exim's main.log and appends the ones not recorded yet to log/smtp_errors.log.
 *
 * These used to be INSERTed into an `errors` table in the panel database; see
 * the comment above smtp_error_parse() in modules/functions.php for why that
 * moved here. The parsing, dedup and trimming all live in functions.php so the
 * page that renders the log reads it back through the same code.
 *
 * Reqad -- https://www.reqad.com/
 */

require_once __DIR__.'/../public_html/modules/functions.php';

/* Runs every minute over a log that can be tens of megabytes after a spam
   burst. Skip rather than queue up overlapping scans that would each redo the
   same work and then race on the append. */
$lock = @fopen(__DIR__.'/../log/.smtp_errors.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB))
	exit(0);

$raw = shell_exec(escapeshellarg(__DIR__.'/exim_error_parser.sh').' 2>/dev/null');

$entries = array();
foreach (explode("\n", (string)$raw) as $line) {
	$e = smtp_error_parse(rtrim($line, "\r"));
	if ($e !== null) $entries[] = $e;
}

$added = smtp_errors_append($entries);
smtp_errors_trim();

/* cron redirects to /dev/null; this is for running it by hand. */
echo "exim_error_parser: ".count($entries)." failures in main.log, ".$added." new\n";

flock($lock, LOCK_UN);
fclose($lock);
