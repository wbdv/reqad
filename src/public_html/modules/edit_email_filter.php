<?php
/* Update an existing filter. */

$errmsg = '';
$successmsg = '';

$sc = ef_scope_from_post($errmsg);
if ($sc === false) {
	$scope = 'global'; $target = '';
} else {
	list($scope, $target) = $sc;
}

$id   = isset($_POST['id']) ? trim($_POST['id']) : '';
$rule = ($errmsg === '') ? ef_rule_from_post($errmsg) : false;

if ($errmsg === '' && $scope !== 'account') {
	$id = (int)$id;

	$stmt = $db->prepare('SELECT * FROM email_filters WHERE id=:i AND scope=:s AND target=:t');
	$stmt->bindValue(':i', $id,     SQLITE3_INTEGER);
	$stmt->bindValue(':s', $scope,  SQLITE3_TEXT);
	$stmt->bindValue(':t', $target, SQLITE3_TEXT);
	$before = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

	if (!$before) {
		$errmsg = 'Error: that filter no longer exists.';
	} else {
		$stmt = $db->prepare('UPDATE email_filters
		           SET name=:n, enabled=:e, priority=:p, match_type=:m,
		               conditions=:c, actions=:a, stop=:st
		         WHERE id=:i');
		$stmt->bindValue(':n',  $rule['name'],       SQLITE3_TEXT);
		$stmt->bindValue(':e',  $rule['enabled'],    SQLITE3_INTEGER);
		$stmt->bindValue(':p',  $rule['priority'],   SQLITE3_INTEGER);
		$stmt->bindValue(':m',  $rule['match_type'], SQLITE3_TEXT);
		$stmt->bindValue(':c',  $rule['conditions'], SQLITE3_TEXT);
		$stmt->bindValue(':a',  $rule['actions'],    SQLITE3_TEXT);
		$stmt->bindValue(':st', $rule['stop'],       SQLITE3_INTEGER);
		$stmt->bindValue(':i',  $id,                 SQLITE3_INTEGER);
		$stmt->execute();

		$err = sieve_write_system($db, $scope, $target);
		if ($err !== '') {
			/* Put the previous version back so the table matches the file. */
			$rb = $db->prepare('UPDATE email_filters
			         SET name=:n, enabled=:e, priority=:p, match_type=:m,
			             conditions=:c, actions=:a, stop=:st
			       WHERE id=:i');
			$rb->bindValue(':n',  $before['name'],       SQLITE3_TEXT);
			$rb->bindValue(':e',  $before['enabled'],    SQLITE3_INTEGER);
			$rb->bindValue(':p',  $before['priority'],   SQLITE3_INTEGER);
			$rb->bindValue(':m',  $before['match_type'], SQLITE3_TEXT);
			$rb->bindValue(':c',  $before['conditions'], SQLITE3_TEXT);
			$rb->bindValue(':a',  $before['actions'],    SQLITE3_TEXT);
			$rb->bindValue(':st', $before['stop'],       SQLITE3_INTEGER);
			$rb->bindValue(':i',  $id,                   SQLITE3_INTEGER);
			$rb->execute();
			sieve_write_system($db, $scope, $target);
			$errmsg = 'Error: the change could not be compiled, so it was not saved. ' . $err;
		} else {
			$successmsg = 'Filter "' . htmlspecialchars($rule['name']) . '" updated.';
		}
	}
}

if ($errmsg === '' && $scope === 'account') {
	/* Account rules are addressed by position in the script. Re-read first:
	   Roundcube or an IMAP client may have rewritten it since the page loaded. */
	$rules = ef_read_account($target);
	$idx   = (int)$id;
	if ($rules === false) {
		$errmsg = 'Error: this mailbox\'s script cannot be edited as a list of rules. Use the raw editor.';
	} else if (!isset($rules[$idx])) {
		$errmsg = 'Error: that filter no longer exists — the script was changed elsewhere. Reload the page.';
	} else {
		/* The "stop processing later rules" control is hidden on this tier, so the
		   POST cannot carry it. Keep whatever the rule already had — a `stop;`
		   written in Roundcube must survive an edit made here. */
		$rule['stop'] = isset($rules[$idx]['stop']) ? $rules[$idx]['stop'] : 0;
		$rules[$idx] = $rule;
		$err = ef_write_account($target, $rules);
		if ($err !== '')
			$errmsg = 'Error: the change could not be compiled, so it was not saved. ' . $err;
		else
			$successmsg = 'Filter "' . htmlspecialchars($rule['name']) . '" updated.';
	}
}

error_log(date("Y-m-d H:i:s") . " " . $_SERVER["REMOTE_ADDR"] . " " . $_SERVER['USER']
        . " edit email filter [$scope/$target] " . ($errmsg === '' ? 'ok' : 'FAILED') . "\n", 3, '../log/route_log');

msg_redirect(ef_redirect_base($scope, $target),
             $errmsg !== '' ? $errmsg : $successmsg,
             $errmsg !== '' ? 'error' : 'success');
