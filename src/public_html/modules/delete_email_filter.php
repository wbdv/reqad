<?php
/* Remove one filter. */

$errmsg = '';
$successmsg = '';

$sc = ef_scope_from_post($errmsg);
if ($sc === false) {
	$scope = 'global'; $target = '';
} else {
	list($scope, $target) = $sc;
}

$id = isset($_POST['id']) ? trim($_POST['id']) : '';

if ($errmsg === '' && $scope !== 'account') {
	$stmt = $db->prepare('DELETE FROM email_filters WHERE id=:i AND scope=:s AND target=:t');
	$stmt->bindValue(':i', (int)$id, SQLITE3_INTEGER);
	$stmt->bindValue(':s', $scope,   SQLITE3_TEXT);
	$stmt->bindValue(':t', $target,  SQLITE3_TEXT);
	$stmt->execute();

	if ($db->changes() === 0) {
		$errmsg = 'Error: that filter no longer exists.';
	} else {
		$err = sieve_write_system($db, $scope, $target);
		$errmsg = ($err !== '') ? ('Error: filter removed, but the script could not be rebuilt. ' . $err) : '';
		if ($errmsg === '')
			$successmsg = 'Filter deleted.';
	}
}

if ($errmsg === '' && $scope === 'account') {
	$rules = ef_read_account($target);
	$idx   = (int)$id;
	if ($rules === false) {
		$errmsg = 'Error: this mailbox\'s script cannot be edited as a list of rules. Use the raw editor.';
	} else if (!isset($rules[$idx])) {
		$errmsg = 'Error: that filter no longer exists — the script was changed elsewhere. Reload the page.';
	} else {
		array_splice($rules, $idx, 1);
		$err = ef_write_account($target, $rules);
		if ($err !== '')
			$errmsg = 'Error: the script could not be rebuilt. ' . $err;
		else
			$successmsg = 'Filter deleted.';
	}
}

error_log(date("Y-m-d H:i:s") . " " . $_SERVER["REMOTE_ADDR"] . " " . $_SERVER['USER']
        . " delete email filter [$scope/$target] " . ($errmsg === '' ? 'ok' : 'FAILED') . "\n", 3, '../log/route_log');

msg_redirect(ef_redirect_base($scope, $target),
             $errmsg !== '' ? $errmsg : $successmsg,
             $errmsg !== '' ? 'error' : 'success');
