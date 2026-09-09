<?php
/* Save a hand-written Sieve script for one mailbox.
   Only the account tier has a raw editor: the global and domain tiers are
   rendered from the database, so hand edits there would be overwritten. */

$errmsg = '';
$successmsg = '';

$sc = ef_scope_from_post($errmsg);
if ($sc === false) {
	$scope = 'account'; $target = '';
} else {
	list($scope, $target) = $sc;
}

if ($errmsg === '' && $scope !== 'account')
	$errmsg = 'Error: only a mailbox\'s own script can be edited as raw Sieve.';

if ($errmsg === '') {
	$source = isset($_POST['source']) ? (string)$_POST['source'] : '';
	$source = str_replace("\r\n", "\n", $source);

	if (strlen($source) > 262144) {
		$errmsg = 'Error: script is too large (max 256 KB).';
	} else if (trim($source) === '') {
		$err = sieve_user_delete($target);
		$errmsg = ($err !== '') ? ('Error: ' . $err) : '';
		if ($errmsg === '')
			$successmsg = 'Script removed — this mailbox now has no filters.';
	} else {
		/* sieve_user_put activates on success; on a compile error doveadm
		   refuses the upload and the previous script stays active. */
		$err = sieve_user_put($target, $source);
		if ($err !== '')
			$errmsg = 'Error: the script was not saved. ' . $err;
		else
			$successmsg = 'Script saved for ' . htmlspecialchars($target) . '.';
	}
}

error_log(date("Y-m-d H:i:s") . " " . $_SERVER["REMOTE_ADDR"] . " " . $_SERVER['USER']
        . " save raw sieve [$target] " . ($errmsg === '' ? 'ok' : 'FAILED') . "\n", 3, '../log/route_log');

msg_redirect(ef_redirect_base('account', $target),
             $errmsg !== '' ? $errmsg : $successmsg,
             $errmsg !== '' ? 'error' : 'success');
