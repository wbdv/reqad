<?php
/* Add a filter to the global, per-domain or per-mailbox tier. */

$errmsg = '';
$successmsg = '';

$sc = ef_scope_from_post($errmsg);
if ($sc === false) {
	$scope = 'global'; $target = '';
} else {
	list($scope, $target) = $sc;
}

$rule = ($errmsg === '') ? ef_rule_from_post($errmsg) : false;

if ($errmsg === '' && $scope !== 'account') {
	$stmt = $db->prepare('SELECT id FROM email_filters WHERE scope=:s AND target=:t AND name=:n');
	$stmt->bindValue(':s', $scope,  SQLITE3_TEXT);
	$stmt->bindValue(':t', $target, SQLITE3_TEXT);
	$stmt->bindValue(':n', $rule['name'], SQLITE3_TEXT);
	if ($stmt->execute()->fetchArray())
		$errmsg = 'Error: a filter named "' . htmlspecialchars($rule['name']) . '" already exists here.';
}

if ($errmsg === '' && $scope !== 'account') {
	$stmt = $db->prepare('INSERT INTO email_filters
	        (scope, target, name, enabled, priority, match_type, conditions, actions, stop)
	        VALUES (:s, :t, :n, :e, :p, :m, :c, :a, :st)');
	$stmt->bindValue(':s',  $scope,               SQLITE3_TEXT);
	$stmt->bindValue(':t',  $target,              SQLITE3_TEXT);
	$stmt->bindValue(':n',  $rule['name'],        SQLITE3_TEXT);
	$stmt->bindValue(':e',  $rule['enabled'],     SQLITE3_INTEGER);
	$stmt->bindValue(':p',  $rule['priority'],    SQLITE3_INTEGER);
	$stmt->bindValue(':m',  $rule['match_type'],  SQLITE3_TEXT);
	$stmt->bindValue(':c',  $rule['conditions'],  SQLITE3_TEXT);
	$stmt->bindValue(':a',  $rule['actions'],     SQLITE3_TEXT);
	$stmt->bindValue(':st', $rule['stop'],        SQLITE3_INTEGER);
	$stmt->execute();
	$new_id = $db->lastInsertRowID();

	$err = sieve_write_system($db, $scope, $target);
	if ($err !== '') {
		/* The script did not compile, so the rule must not stay in the table —
		   the file and the database would disagree about what is running. */
		$db->exec('DELETE FROM email_filters WHERE id = ' . (int)$new_id);
		sieve_write_system($db, $scope, $target);
		$errmsg = 'Error: the filter could not be compiled, so it was not saved. ' . $err;
	} else {
		$successmsg = 'Filter "' . htmlspecialchars($rule['name']) . '" created.';
	}
}

if ($errmsg === '' && $scope === 'account') {
	$rules = ef_read_account($target);
	if ($rules === false) {
		$errmsg = 'Error: this mailbox\'s script cannot be edited as a list of rules. Use the raw editor.';
	} else {
		$rules[] = $rule;
		$err = ef_write_account($target, $rules);
		if ($err !== '')
			$errmsg = 'Error: the filter could not be compiled, so it was not saved. ' . $err;
		else
			$successmsg = 'Filter "' . htmlspecialchars($rule['name']) . '" created.';
	}
}

error_log(date("Y-m-d H:i:s") . " " . $_SERVER["REMOTE_ADDR"] . " " . $_SERVER['USER']
        . " create email filter [$scope/$target] " . ($errmsg === '' ? 'ok' : 'FAILED') . "\n", 3, '../log/route_log');

msg_redirect(ef_redirect_base($scope, $target),
             $errmsg !== '' ? $errmsg : $successmsg,
             $errmsg !== '' ? 'error' : 'success');
