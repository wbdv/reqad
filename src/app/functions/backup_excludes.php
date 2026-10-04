<?php
/*
 * Backup exclusions: what the account backups leave out (db/1036.sql).
 *
 * Shared by the web panel (modules/backup_exclude.php, the Exclusions tab of
 * /backup/), bin/reqad and the API; returns result_ok() / result_error().
 *
 * The rules are only stored here. scripts/backup_excludes.sh is the single
 * place that validates a pattern (`check`) and turns it into tar/find
 * patterns, so this file asks it rather than keeping a second copy of the
 * rules that could drift from what the backup really does.
 */

function backup_exclude_helper() {
	return _PATH.'/scripts/backup_excludes.sh';
}

/* scope => label */
function backup_exclude_scopes() {
	return array('both' => 'Nightly + manual', 'nightly' => 'Nightly only', 'manual' => 'Manual only');
}

/* The stored form of a path pattern, or false with the reason in $error. */
function backup_exclude_check($pattern, &$error = '') {
	$out = array(); $rc = 1;
	exec(escapeshellarg(backup_exclude_helper()).' check '.escapeshellarg((string)$pattern).' 2>&1', $out, $rc);
	$line = trim(implode(' ', $out));
	if ($rc !== 0) {
		$error = $line !== '' ? $line : 'invalid pattern';
		return false;
	}
	return $line;
}

/* Rules for one account (or '*' for the server-wide ones), oldest first. */
function backup_exclude_rules($user) {
	global $db;
	$rows = array();
	$stmt = @$db->prepare('SELECT id, user, kind, pattern, scope, note, created FROM backup_excludes WHERE user = :u ORDER BY kind DESC, id');
	if (!$stmt)
		return $rows;      /* table missing: db/1036.sql has not run yet */
	$stmt->bindValue(':u', (string)$user, SQLITE3_TEXT);
	$res = $stmt->execute();
	while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC)))
		$rows[] = $row;
	return $rows;
}

function backup_exclude_add($user, $kind, $pattern, $scope = 'both', $note = '') {
	global $db;
	$user  = trim((string)$user);
	$kind  = (string)$kind;
	$scope = (string)$scope;
	/* free text, but shown in the panel and dumped into account backups */
	$note  = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string)$note)), 0, 200);

	if ($user !== '*' && (!valid_username($user) || !account_get($db, $user)))
		return result_error("Account '$user' does not exist.");
	if (!isset(backup_exclude_scopes()[$scope]))
		return result_error('Choose which backups the rule applies to.');

	if ($kind === 'path') {
		$err = '';
		$stored = backup_exclude_check($pattern, $err);
		if ($stored === false)
			return result_error("'".trim((string)$pattern)."' cannot be used: $err.");
	} elseif ($kind === 'db') {
		if ($user === '*')
			return result_error('Databases can only be excluded per account.');
		$stored = trim((string)$pattern);
		if (!valid_mysql_identifier($stored) || database_owner($stored) !== $user)
			return result_error("Database '$stored' does not belong to $user.");
	} else {
		return result_error('Unknown rule type.');
	}

	$stmt = @$db->prepare('INSERT INTO backup_excludes (user, kind, pattern, scope, note, created)
	                       VALUES (:u, :k, :p, :s, :n, :t) ON CONFLICT(user, kind, pattern) DO NOTHING');
	if (!$stmt)
		return result_error('The backup_excludes table is missing -- the panel database has not been migrated to 1036 yet.');
	$stmt->bindValue(':u', $user,   SQLITE3_TEXT);
	$stmt->bindValue(':k', $kind,   SQLITE3_TEXT);
	$stmt->bindValue(':p', $stored, SQLITE3_TEXT);
	$stmt->bindValue(':s', $scope,  SQLITE3_TEXT);
	$stmt->bindValue(':n', $note,   SQLITE3_TEXT);
	$stmt->bindValue(':t', time(),  SQLITE3_INTEGER);
	if (!$stmt->execute())
		return result_error('Could not save the rule: '.$db->lastErrorMsg());
	$who = $user === '*' ? 'every account' : $user;
	if ($db->changes() === 0)
		return result_error(($kind === 'db' ? "Database $stored" : "'$stored'")." is already excluded for $who.");

	route_log("backup exclude add $kind '$stored' ($scope) for $who");
	return result_ok(($kind === 'db' ? "Database $stored" : "'$stored'")." is now left out of "
		.strtolower(backup_exclude_scopes()[$scope])." backups of $who.",
		array('id' => $db->lastInsertRowID(), 'pattern' => $stored));
}

function backup_exclude_delete($id) {
	global $db;
	$stmt = @$db->prepare('SELECT user, kind, pattern FROM backup_excludes WHERE id = :i');
	if (!$stmt)
		return result_error('The backup_excludes table is missing.');
	$stmt->bindValue(':i', (int)$id, SQLITE3_INTEGER);
	$res = $stmt->execute();
	$row = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;
	if (!$row)
		return result_error('That rule does not exist.');
	$stmt = $db->prepare('DELETE FROM backup_excludes WHERE id = :i');
	$stmt->bindValue(':i', (int)$id, SQLITE3_INTEGER);
	$stmt->execute();
	route_log("backup exclude delete {$row['kind']} '{$row['pattern']}' for {$row['user']}");
	return result_ok(($row['kind'] === 'db' ? "Database {$row['pattern']}" : "'{$row['pattern']}'")
		.' is included in backups again.', $row);
}

/* Every rule of an account -- called when the account is deleted, so a new
   account created later under the same name does not inherit them. */
function backup_exclude_forget($user) {
	global $db;
	$stmt = @$db->prepare('DELETE FROM backup_excludes WHERE user = :u');
	if ($stmt && $user !== '*') {
		$stmt->bindValue(':u', (string)$user, SQLITE3_TEXT);
		$stmt->execute();
	}
}

/* ~/cpbackup-exclude.conf of an account, if it has one (array of lines), or
   false. Read AS the account user: the file is the user's, and as root a
   symlink in its place would read any file on the server. */
function backup_exclude_cpanel_file($user) {
	if (!valid_username($user))
		return false;
	$path = '/home/'.$user.'/cpbackup-exclude.conf';
	$rc = 1; $o = array();
	exec('sudo -u '.escapeshellarg($user).' test -f '.escapeshellarg($path).' 2>/dev/null', $o, $rc);
	if ($rc !== 0)
		return false;
	$txt = (string)shell_exec('sudo -u '.escapeshellarg($user).' head -c 65536 '.escapeshellarg($path).' 2>/dev/null');
	return preg_split('/\r?\n/', $txt);
}

/* One rule per usable line of ~/cpbackup-exclude.conf. Its lines are relative
   to the home directory (or absolute under it), which is what an anchored
   pattern here means, so they are taken as they are. */
function backup_exclude_import_cpanel($user) {
	$lines = backup_exclude_cpanel_file($user);
	if ($lines === false)
		return result_error("$user has no cpbackup-exclude.conf in its home directory.");
	$home = '/home/'.$user;
	$added = array(); $skipped = array();
	foreach ($lines as $line) {
		$line = trim($line);
		if ($line === '' || $line[0] === '#')
			continue;
		if ($line === $home || strpos($line, $home.'/') === 0)
			$line = substr($line, strlen($home));         /* keeps the leading / = anchored */
		elseif (strpos($line, './') === 0)
			$line = '/'.substr($line, 2);
		elseif ($line[0] === '/') {                       /* outside the home directory */
			$skipped[] = $line;
			continue;
		}
		$r = backup_exclude_add($user, 'path', $line, 'both', 'imported from cpbackup-exclude.conf');
		if ($r['ok'])
			$added[] = $r['data']['pattern'];
		elseif (strpos($r['error'], 'already excluded') === false)
			$skipped[] = $line;
	}
	if (!$added && !$skipped)
		return result_ok('Nothing new to import: every line of cpbackup-exclude.conf is already a rule.');
	$warn = $skipped ? array('Not imported (outside the home directory or not a valid pattern): '.implode(', ', $skipped)) : array();
	return result_ok('Imported '.count($added).' rule'.(count($added) == 1 ? '' : 's').' from cpbackup-exclude.conf'
		.($added ? ': '.implode(', ', $added) : '').'.', array('added' => $added, 'skipped' => $skipped), $warn);
}

/* Filesystems mounted inside the home directory:
   array of array('path', 'fstype', 'source'). Read from the mount table only. */
function backup_exclude_mounts($user) {
	if (!valid_username($user))
		return array();
	$list = array();
	foreach (explode("\n", (string)shell_exec('sudo '.escapeshellarg(backup_exclude_helper()).' mounts '.escapeshellarg($user).' 2>/dev/null')) as $line) {
		$f = explode("\t", $line);
		if (count($f) >= 3 && $f[0] !== '')
			$list[] = array('path' => $f[0], 'fstype' => $f[1], 'source' => $f[2]);
	}
	return $list;
}

/* What every rule of $user (and the server-wide ones) matches right now.
   One metadata walk of the home directory -- it can take a while on a big one. */
function backup_exclude_preview($user) {
	$res = array('home_kb' => 0, 'rules' => array(), 'mounts' => array(), 'markers' => array(), 'cachedirs' => array());
	if (!valid_username($user))
		return $res;
	$out = (string)shell_exec('sudo '.escapeshellarg(backup_exclude_helper()).' preview '.escapeshellarg($user).' 2>/dev/null');
	foreach (explode("\n", $out) as $line) {
		$f = explode("\t", $line);
		switch ($f[0]) {
			case 'home':
				$res['home_kb'] = (int)($f[1] ?? 0);
				break;
			case 'rule':
				if (count($f) >= 4)
					$res['rules'][(int)$f[1]] = array('kb' => (int)$f[2], 'count' => (int)$f[3],
						'samples' => isset($f[4]) && $f[4] !== '' ? explode('|', $f[4]) : array());
				break;
			case 'mount':
				if (count($f) >= 4)
					$res['mounts'][] = array('path' => $f[1], 'fstype' => $f[2], 'source' => $f[3]);
				break;
			case 'marker':
			case 'cachedir':
				if (count($f) >= 3)
					$res[$f[0].'s'][] = array('path' => $f[1], 'kb' => (int)$f[2]);
				break;
		}
	}
	return $res;
}

/* Things in the home directory worth excluding that no rule covers yet:
   array of array('pattern', 'kb', 'count', 'label'), biggest first. */
function backup_exclude_suggest($user) {
	$list = array();
	if (!valid_username($user))
		return $list;
	$out = (string)shell_exec('sudo '.escapeshellarg(backup_exclude_helper()).' suggest '.escapeshellarg($user).' 2>/dev/null');
	foreach (explode("\n", $out) as $line) {
		$f = explode("\t", $line);
		if (count($f) >= 4 && $f[0] !== '')
			$list[] = array('pattern' => $f[0], 'kb' => (int)$f[1], 'count' => (int)$f[2], 'label' => $f[3]);
	}
	return $list;
}
