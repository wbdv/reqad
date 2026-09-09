<?php
/* Replace both of an account's lists from the bulk editor: one address per
   line. Everything else in user_prefs is preserved by sa_lists_put(). */

$errmsg = '';
$successmsg = '';

$account = isset($_POST['account']) ? clean($_POST['account']) : '';

$accounts = sa_accounts();
if (!isset($accounts[$account]))
	$errmsg = 'Error: unknown hosting account.';

$raw_white = isset($_POST['white']) ? (string)$_POST['white'] : '';
$raw_black = isset($_POST['black']) ? (string)$_POST['black'] : '';

if ($errmsg === '' && strlen($raw_white) + strlen($raw_black) > 262144)
	$errmsg = 'Error: those lists are too large (max 256 KB).';

if ($errmsg === '') {
	$bad   = array();
	$parse = function($raw) use (&$bad) {
		$out = array();
		/* Commas and semicolons as well as newlines: a list pasted out of a mail
		   client or a spreadsheet arrives on one line just as often as on many. */
		foreach (preg_split('/[\s,;]+/', (string)$raw) as $line) {
			if (trim($line) === '')
				continue;
			$n = sa_normalize_pattern($line);
			if ($n === '')
				$bad[] = trim($line);
			else
				$out[] = $n;
		}
		return array_values(array_unique($out));
	};

	$white = $parse($raw_white);
	$black = $parse($raw_black);

	if ($bad) {
		/* Refuse the whole save rather than silently drop entries: a list that is
		   quietly one address short is worse than one that was not saved. */
		$show = array_slice($bad, 0, 5);
		$errmsg = 'Error: nothing was saved - these are not sender addresses: '
		        . htmlspecialchars(implode(', ', $show))
		        . (count($bad) > 5 ? ' (and ' . (count($bad) - 5) . ' more)' : '') . '.';
	} else {
		/* An address on both lists cancels itself out in SpamAssassin, so the
		   blocklist wins: it is the safer reading of a contradiction. */
		$dupes = array_intersect($white, $black);
		if ($dupes)
			$white = array_values(array_diff($white, $dupes));

		$err = sa_lists_put($account, $white, $black);
		if ($err !== '') {
			$errmsg = 'Error: the lists could not be saved. ' . $err;
		} else {
			$successmsg = 'Saved ' . count($white) . ' whitelisted and ' . count($black)
			            . ' blocklisted sender' . ((count($white) + count($black)) === 1 ? '' : 's') . '.'
			            . ($dupes ? ' ' . count($dupes) . ' address(es) were on both lists and were'
			                      . ' kept on the blocklist only.' : '');
		}
	}
}

error_log(date("Y-m-d H:i:s") . " " . $_SERVER["REMOTE_ADDR"] . " " . $_SERVER['USER']
        . " save spam rules [$account] " . ($errmsg === '' ? 'ok' : 'FAILED') . "\n", 3, '../log/route_log');

msg_redirect(sa_redirect_base($account),
             $errmsg !== '' ? $errmsg : $successmsg,
             $errmsg !== '' ? 'error' : 'success');
