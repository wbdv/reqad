<?php
/* Remove one address from an account's whitelist or blocklist. */

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

if ($errmsg === '') {
	/* Re-read rather than trust the page: an import or a hand edit may have
	   rewritten this file since it was rendered. */
	$cur = sa_lists_get($account);
	$key = ($list === 'white') ? 'white' : 'black';
	$hit = sa_normalize_pattern($pattern);

	if ($hit === '' || !in_array($hit, $cur[$key], true)) {
		$errmsg = 'Error: that entry is no longer on the list.';
	} else {
		$cur[$key] = array_values(array_diff($cur[$key], array($hit)));
		$err = sa_lists_put($account, $cur['white'], $cur['black']);
		if ($err !== '')
			$errmsg = 'Error: the list could not be saved. ' . $err;
		else
			$successmsg = htmlspecialchars($hit) . ' removed.';
	}
}

error_log(date("Y-m-d H:i:s") . " " . $_SERVER["REMOTE_ADDR"] . " " . $_SERVER['USER']
        . " delete spam rule [$account/$list] " . ($errmsg === '' ? 'ok' : 'FAILED') . "\n", 3, '../log/route_log');

msg_redirect(sa_redirect_base($account),
             $errmsg !== '' ? $errmsg : $successmsg,
             $errmsg !== '' ? 'error' : 'success');
