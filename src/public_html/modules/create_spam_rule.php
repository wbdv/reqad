<?php
/* Add one address to an account's SpamAssassin whitelist or blocklist. */

$errmsg = '';
$successmsg = '';

$account = isset($_POST['account']) ? clean($_POST['account']) : '';
$list    = isset($_POST['list'])    ? trim($_POST['list'])     : '';
$pattern = isset($_POST['pattern']) ? trim($_POST['pattern'])  : '';

$accounts = sa_accounts();
if (!isset($accounts[$account]))
	$errmsg = 'Error: unknown hosting account.';
else if ($list !== 'white' && $list !== 'black')
	$errmsg = 'Error: pick the whitelist or the blocklist.';

$norm = '';
if ($errmsg === '') {
	$norm = sa_normalize_pattern($pattern);
	if ($norm === '')
		$errmsg = 'Error: "' . htmlspecialchars($pattern) . '" is not a sender address. '
		        . 'Use user@example.com, *@example.com, or a bare domain.';
}

if ($errmsg === '') {
	$cur = sa_lists_get($account);
	$key = ($list === 'white') ? 'white' : 'black';

	if (in_array($norm, $cur[$key], true)) {
		$errmsg = 'Error: ' . htmlspecialchars($norm) . ' is already on that list.';
	} else {
		/* The same address on both lists is not an error to SpamAssassin - the
		   two scores simply cancel - but it is never what was meant, so moving it
		   is the only reading that makes sense. */
		$other = ($key === 'white') ? 'black' : 'white';
		$moved = in_array($norm, $cur[$other], true);
		if ($moved)
			$cur[$other] = array_values(array_diff($cur[$other], array($norm)));

		$cur[$key][] = $norm;
		$err = sa_lists_put($account, $cur['white'], $cur['black']);
		if ($err !== '')
			$errmsg = 'Error: the list could not be saved. ' . $err;
		else
			$successmsg = htmlspecialchars($norm) . ' added to the '
			            . ($key === 'white' ? 'whitelist' : 'blocklist')
			            . ($moved ? ' (and removed from the other one).' : '.');
	}
}

error_log(date("Y-m-d H:i:s") . " " . $_SERVER["REMOTE_ADDR"] . " " . $_SERVER['USER']
        . " add spam rule [$account/$list] " . ($errmsg === '' ? 'ok' : 'FAILED') . "\n", 3, '../log/route_log');

msg_redirect(sa_redirect_base($account),
             $errmsg !== '' ? $errmsg : $successmsg,
             $errmsg !== '' ? 'error' : 'success');
