<?php
/*
 * MySQL/MariaDB databases: create / delete / change password / list.
 *
 * Shared by the web panel (modules/create_database.php etc.), bin/reqad and the
 * API; returns result_ok() / result_error() (modules/functions.php).
 *
 * Naming: a database and its user are always prefixed with the owning hosting
 * account, "<account>_<name>". Callers pass the short name; the prefix is added
 * here. Names reach MySQL as bare identifiers, where no quoting makes an
 * arbitrary string safe, so they are gated with valid_mysql_identifier().
 *
 * Ownership: the prefix, or -- for a database without one (imported, made in
 * phpMyAdmin) -- an assignment in the db_owners table (db/1035.sql). Either
 * way the database is listed under the account, dumped by its backups and
 * restored with it. database_owner() is the one place that decides.
 */

/* A database grant's Db column (from mysql_rows(), whose batch output
   escapes backslashes) as the database name: grants may spell _ and % as \_
   and \% (GRANT ... ON `bob\_wp`.*, what phpMyAdmin and database_grant() write). */
function database_grant_name($db_column) {
	return strtr(database_rows_field($db_column), array('\\_' => '_', '\\%' => '%'));
}

/* One field of mysql_rows() output as stored: the client's batch mode writes
   \\ \t \n \0 for backslash, tab, newline and NUL. */
function database_rows_field($s) {
	return strtr((string)$s, array('\\\\' => '\\', '\\t' => "\t", '\\n' => "\n", '\\0' => "\0"));
}

/* MySQL's own schemas -- never listed or dropped from here. */
function database_system_schemas() {
	return array('mysql', 'information_schema', 'performance_schema', 'sys');
}

/*
 * List databases with their size (MB) and the users granted on them.
 * $account: only databases prefixed "<account>_" ('' = all).
 * Returns array of array('name', 'account', 'assigned', 'size_mb', 'users' => array('user@host', ...)).
 * 'account' is the owning hosting account (database_owner(), '' = no owner);
 * 'assigned' is true when that comes from db_owners rather than the prefix.
 */
function database_list($account = '') {
	$assigned = database_assignments();
	$accounts = database_account_names();
	/* one mysql run; for one account only its own schemas are measured
	   (information_schema.TABLES is the slow part on a server with many) */
	$where = $account !== '' ? ' WHERE '.database_schemas_where($account, $assigned) : '';
	list($size_rows, $grant_rows) = database_mysql_batch(array(
		'SELECT s.SCHEMA_NAME, IFNULL(ROUND(SUM(t.data_length + t.index_length) / 1048576, 2), 0)'
		.' FROM information_schema.SCHEMATA s LEFT JOIN information_schema.TABLES t ON t.table_schema = s.SCHEMA_NAME'
		.str_replace('SCHEMA_NAME', 's.SCHEMA_NAME', $where).' GROUP BY s.SCHEMA_NAME',
		'SELECT DISTINCT Db, User, Host FROM mysql.db'));
	$sizes = array();
	foreach ($size_rows as $line) {
		list($name, $size) = array_pad(explode("\t", $line), 2, '0');
		$sizes[$name] = (float)$size;
	}

	/* mysql.db stores LIKE-escaped names for grants such as `foo\_bar`.* */
	$users = array();
	foreach ($grant_rows as $line) {
		list($dbn, $u, $h) = array_pad(explode("\t", $line), 3, '');
		if (strpos($u, database_pma_prefix()) === 0)    // phpMyAdmin logins of the account panel
			continue;
		$users[database_grant_name($dbn)][] = $u.'@'.$h;
	}

	$list = array();
	foreach ($sizes as $name => $size) {
		if (in_array($name, database_system_schemas(), true))
			continue;
		$owner = database_owner($name, $assigned, $accounts);
		if ($account !== '' && $owner !== $account)
			continue;
		$list[] = array('name' => $name, 'account' => $owner, 'assigned' => isset($assigned[$name]),
			'size_mb' => $size, 'users' => isset($users[$name]) ? $users[$name] : array());
	}
	return $list;
}

/* SQL condition on SCHEMA_NAME for the schemas that may be $account's: its
   prefix, or assigned to it (database_owner() still decides). */
function database_schemas_where($account, $assigned = null) {
	if ($assigned === null) $assigned = database_assignments();
	$or = array("SCHEMA_NAME LIKE ".mysql_quote(strtr($account, array('_' => '\\_', '%' => '\\%')).'\\_%'));
	$mine = array_keys(array_filter($assigned, function ($a) use ($account) { return $a === $account; }));
	if ($mine)
		$or[] = 'SCHEMA_NAME IN ('.implode(', ', array_map('mysql_quote', $mine)).')';
	return '('.implode(' OR ', $or).')';
}

/* Several SELECTs in one mysql run (each run costs ~15 ms): array of the
   rows of each, as mysql_rows() gives them. A failing statement ends the
   run; it and the ones after it come back empty. */
function database_mysql_batch(array $queries) {
	$mark = 'reqad-next-result';
	$out = array(array());
	foreach (mysql_rows(implode(";\nSELECT '$mark';\n", $queries).';') as $line) {
		if ($line === $mark) {
			$out[] = array();
			continue;
		}
		$out[count($out) - 1][] = $line;
	}
	return array_pad($out, count($queries), array());
}

/* Databases assigned to an account by hand: array(dbname => user). Empty
   before db/1035.sql has run (the query fails, the page still works). */
function database_assignments() {
	global $db;
	$map = array();
	$res = $db instanceof SQLite3 ? @$db->query('SELECT dbname, user FROM db_owners') : false;
	while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC)))
		$map[$row['dbname']] = $row['user'];
	return $map;
}

/* Hosting account usernames, as a set: array(user => true). */
function database_account_names() {
	global $db;
	$set = array();
	$res = $db instanceof SQLite3 ? $db->query('SELECT user FROM accounts') : false;
	while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC)))
		$set[$row['user']] = true;
	return $set;
}

/* The hosting account that owns $name, '' = nobody. A db_owners row wins;
   otherwise the "<account>_" prefix, when that is an existing account. Account
   names cannot contain '_' (valid_username), so the first '_' always ends the
   prefix. Pass the two lookups in when calling this in a loop. */
function database_owner($name, $assigned = null, $accounts = null) {
	if ($assigned === null) $assigned = database_assignments();
	if (isset($assigned[$name]))
		return $assigned[$name];
	if ($accounts === null) $accounts = database_account_names();
	$pos = strpos($name, '_');
	$prefix = $pos !== false ? substr($name, 0, $pos) : '';
	return ($prefix !== '' && isset($accounts[$prefix])) ? $prefix : '';
}

/*
 * Give a database that has no prefix owner to a hosting account, so it is
 * listed, backed up and restored with that account. One already assigned by
 * hand can be moved to another account; one owned by its prefix cannot (its
 * name says whose it is, and the backups go by the name).
 */
function database_assign($database, $account) {
	global $db;

	$database = trim((string)$database);
	$account  = trim((string)$account);
	if (!db_is_managed($database))
		return result_error("Database '$database' does not exist or cannot be assigned.");
	if (!valid_username($account) || !account_get($db, $account))
		return result_error("Account '$account' does not exist.");

	$assigned = database_assignments();
	$owner    = database_owner($database, $assigned);
	if ($owner === $account)
		return result_error("Database $database already belongs to $account.");
	if ($owner !== '' && !isset($assigned[$database]))
		return result_error("Database $database belongs to $owner by its name and cannot be reassigned.");

	$stmt = $db->prepare('INSERT OR REPLACE INTO db_owners (dbname, user, created) VALUES (:d, :u, :t)');
	if (!$stmt)
		return result_error("The db_owners table is missing -- the panel database has not been migrated to 1035 yet.");
	$stmt->bindValue(':d', $database, SQLITE3_TEXT);
	$stmt->bindValue(':u', $account, SQLITE3_TEXT);
	$stmt->bindValue(':t', time(), SQLITE3_INTEGER);
	if (!$stmt->execute())
		return result_error("Could not save the assignment: ".$db->lastErrorMsg());

	if ($owner !== '')
		database_pma_revoke_db($database);
	route_log("assign database $database to $account".($owner !== '' ? " (was $owner)" : ''));
	return result_ok("Database $database is now assigned to $account and included in its backups.",
		array('database' => $database, 'account' => $account, 'previous' => $owner));
}

/* Undo database_assign(): the database is left as it is, owned by nobody. */
function database_unassign($database) {
	global $db;

	$database = trim((string)$database);
	$assigned = database_assignments();
	if (!isset($assigned[$database]))
		return result_error("Database '$database' is not assigned to an account.");

	$stmt = $db->prepare('DELETE FROM db_owners WHERE dbname = :d');
	$stmt->bindValue(':d', $database, SQLITE3_TEXT);
	$stmt->execute();

	database_pma_revoke_db($database);
	route_log("unassign database $database from {$assigned[$database]}");
	return result_ok("Database $database is no longer assigned to {$assigned[$database]}.",
		array('database' => $database, 'previous' => $assigned[$database]));
}

/*
 * Create "<account>_<name>" and grant it to "<account>_<dbuser>"@localhost.
 * $o keys: account, name, dbuser, password (8+ characters). dbuser '' = the
 * database alone (no password then); grant = an existing database user (full
 * name) that gets all privileges on it - the caller checks it is the account's.
 */
function database_create(array $o) {
	global $db;

	$account  = trim((string)($o['account'] ?? ''));
	$dbname   = trim((string)($o['name'] ?? ''));
	$dbuser   = trim((string)($o['dbuser'] ?? ''));
	$password = trim((string)($o['password'] ?? ''));
	$grant    = trim((string)($o['grant'] ?? ''));
	$with_user = $dbuser !== '';

	if ($account === '' || $dbname === '' || ($with_user && strlen($password) < 8))
		return result_error("Missing account, database name or password (8+ characters).");
	if (!valid_mysql_identifier($dbname))
		return result_error("Database name may only contain letters, numbers and underscores.");
	if ($with_user && !valid_mysql_identifier($dbuser))
		return result_error("Database user may only contain letters, numbers and underscores.");
	if ($grant !== '' && !valid_mysql_identifier($grant))
		return result_error("Database user may only contain letters, numbers and underscores.");
	if (!valid_username($account))
		return result_error("Invalid account user.");
	/* The web form offers a dropdown of hosting accounts, but the CLI and the API
	   take the account as free text. A database prefixed with something that is
	   not an account (the panel's own "reqad" system user, say, or a typo) would
	   belong to nobody: no owner in the list, never in an account backup, never
	   dropped when an account is deleted. */
	if (!account_get($db, $account))
		return result_error("Account $account does not exist. The account username is the database prefix and must be an existing hosting account.");

	$full_db   = $account.'_'.$dbname;
	$full_user = $with_user ? $account.'_'.$dbuser : '';
	if (strlen($full_db) > 64)
		return result_error("The database name is too long: $full_db has more than 64 characters.");
	if ($with_user && strlen($full_user) > database_user_max())
		return result_error("The user name is too long: $full_user has more than ".database_user_max()." characters.");
	if (($e = limit_allows($account, 'database', array('database' => $full_db, 'user' => $full_user))) !== '')
		return result_error($e);

	if (in_array($full_db, mysql_rows('SHOW DATABASES'), true))
		return result_error("Database $full_db already exists. Please choose a different database name.");
	$users = mysql_rows('SELECT User FROM mysql.user');
	if ($with_user && in_array($full_user, $users, true))
		return result_error("User $full_user already exists. Please choose a different user name.");
	if ($grant !== '' && !in_array($grant, $users, true))
		return result_error("Database user '$grant' not found.");

	route_log("create database $full_db");
	$output = mysql_exec('CREATE DATABASE `'.$full_db.'`');
	if ($with_user) {
		route_log("create user $full_user");
		$output .= mysql_exec('GRANT ALL ON `'.$full_db.'`.* TO `'.$full_user.'`@`localhost` IDENTIFIED BY '.mysql_quote($password));
	}
	/* mysql_exec() folds stderr in, so any output at all means a statement failed. */
	if (trim($output) != '')
		return result_error(trim($output));
	/* the account's cap on simultaneous connections (Advanced Config > Limits) */
	if ($with_user)
		database_conn_apply($full_user, 'localhost', database_conn_limit($account));
	if ($grant !== '') {
		$g = database_grant($grant, $full_db, 'all');
		if (!$g['ok'])
			return result_error("Database $full_db was created, but $grant could not be given access: ".$g['error'], array('database' => $full_db));
	}
	/* phpMyAdmin windows the account has open see it too */
	database_pma_grant_new($account, $full_db);

	plugin_hook('database.created', array('account' => $account, 'database' => $full_db, 'user' => $full_user ?: $grant));
	if ($with_user)
		$msg = "Database $full_db successfully created. User $full_user was assigned to the database.";
	elseif ($grant !== '')
		$msg = "Database $full_db created. $grant can use it.";
	else
		$msg = "Database $full_db created.";
	return result_ok($msg, array('database' => $full_db, 'user' => $full_user ?: $grant));
}

/* Drop a database (full name, e.g. "bob_wp"), then each user that had grants on
   it -- but only a user left with no grants on any OTHER database, so a user
   shared across databases keeps working. Remote users (user@<ip>) likewise. */
function database_delete($database) {
	global $db;

	$database = trim((string)$database);
	if ($database === '')
		return result_error("Database is empty (missing).");
	if (!valid_mysql_identifier($database) || in_array($database, database_system_schemas(), true))
		return result_error("Invalid database name.");
	if (!in_array($database, mysql_rows('SHOW DATABASES'), true))
		return result_error("Database $database does not exist.");

	// Which accounts (user@host) had grants on this database, BEFORE dropping it
	$db_accounts = mysql_rows('SELECT DISTINCT User, Host FROM mysql.db WHERE '.mysql_db_match_sql($database));

	route_log("delete database $database");
	$output = mysql_exec('DROP DATABASE `'.$database.'`');
	if (trim($output) != '')
		return result_error(trim($output));

	/* DROP DATABASE keeps the grants: a database made later under the same
	   name must not be open to the account panel's phpMyAdmin logins */
	database_pma_revoke_db($database);

	/* a database created later under the same name must not inherit the owner */
	if ($db instanceof SQLite3 && ($stmt = @$db->prepare('DELETE FROM db_owners WHERE dbname = :d'))) {
		$stmt->bindValue(':d', $database, SQLITE3_TEXT);
		$stmt->execute();
	}

	$dropped = array();
	$remote  = false;
	foreach ($db_accounts as $db_account) {
		list($db_user, $db_host) = array_pad(explode("\t", $db_account), 2, '');
		if ($db_user === '' || !valid_mysql_identifier($db_user)) continue;
		if (mysql_drop_user_if_unused($db_user, $db_host, $database)) {
			$dropped[] = $db_user.'@'.$db_host;
			if ($db_host !== 'localhost') $remote = true;
		}
	}
	if ($dropped) mysql_exec('FLUSH PRIVILEGES');
	if ($remote)  mysql_remote_fw_sync();

	plugin_hook('database.deleted', array('database' => $database, 'users_dropped' => $dropped));
	return result_ok("Database $database successfully removed.", array('database' => $database, 'users_dropped' => $dropped));
}

/*
 * Export a database as SQL and hand it to $write($bytes) piece by piece,
 * gzip-compressed unless $gzip is false. $write returning false stops it (the
 * reader went away). Used by GET /v1/databases/{name}/export and
 * `reqad db export`; callers check ownership first (scope_check 'db').
 *
 * root's mysqldump with the backups' flags: one consistent snapshot of InnoDB
 * tables, routines and triggers, no CREATE DATABASE / USE (so it loads into a
 * database of any name). It is started from an argv, without a shell, so
 * stopping early ends it (a shell pipeline would not stop: PHP ignores SIGPIPE).
 *
 * The first 1 MB of output is held back, so a dump that fails at once sends
 * nothing and the caller can still answer with the error. A failure after
 * that can only cut the output short: a complete dump's SQL ends with
 * "-- Dump completed", and a cut .gz fails `gzip -t`.
 * Returns result_ok('', array(database, bytes, gzip)) or result_error().
 */
function database_export($database, callable $write, $gzip = true) {
	$database = trim((string)$database);
	if ($database === '' || $database[0] === '-' || in_array(strtolower($database), database_system_schemas(), true))
		return result_error('Invalid database name.');
	if (!in_array($database, array_map('database_rows_field', mysql_rows('SHOW DATABASES')), true))
		return result_error("Database $database does not exist.");
	@set_time_limit(0);

	$argv = array('/usr/bin/mysqldump', '--single-transaction', '--quick', '--routines', '--triggers',
	              '--default-character-set=utf8mb4', '--', $database);
	if (server_sudo() !== '')
		$argv = array_merge(array('sudo', '-n', '--'), $argv);
	$errf = tmpfile();
	$p = proc_open($argv, array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => $errf), $pipes);
	if (!is_resource($p))
		return result_error('mysqldump could not be started.');
	$z = $gzip ? deflate_init(ZLIB_ENCODING_GZIP, array('level' => 6)) : null;

	$held = '';
	$started = false;
	$bytes = 0;
	$emit = function ($data) use (&$held, &$started, &$bytes, $write) {
		if ($data === '')
			return true;
		if (!$started) {
			$held .= $data;
			if (strlen($held) < 1048576)
				return true;
			list($data, $held, $started) = array($held, '', true);
		}
		$bytes += strlen($data);
		return $write($data) !== false;
	};

	$tail = '';
	$stopped = false;
	while (!feof($pipes[1])) {
		$chunk = fread($pipes[1], 65536);
		if ($chunk === false || $chunk === '')
			continue;
		$tail = substr($tail.$chunk, -100);
		if (!$emit($z ? deflate_add($z, $chunk, ZLIB_NO_FLUSH) : $chunk)) {
			$stopped = true;
			break;
		}
	}
	fclose($pipes[1]);
	if ($stopped)
		proc_terminate($p);
	$rc = proc_close($p);
	fseek($errf, 0);
	$err = trim((string)stream_get_contents($errf));
	fclose($errf);

	if ($stopped)
		return result_error("The export of $database was stopped before it ended.");
	if ($rc !== 0 || strpos($tail, '-- Dump completed') === false)
		return result_error("mysqldump of $database failed".($err !== '' ? ': '.substr($err, 0, 500) : " (exit code $rc)."));
	if (!$emit($z ? deflate_add($z, '', ZLIB_FINISH) : ''))
		return result_error("The export of $database was stopped before it ended.");
	if ($held !== '') {
		$bytes += strlen($held);
		if ($write($held) === false)
			return result_error("The export of $database was stopped before it ended.");
	}
	route_log("export database $database ($bytes bytes)");
	return result_ok('', array('database' => $database, 'bytes' => $bytes, 'gzip' => (bool)$gzip));
}

/* Set a database user's password (full name, e.g. "bob_wpuser") on every host
   it exists for: localhost plus any remote-access accounts (user@<ip>). */
function database_password($dbuser, $password) {
	$dbuser   = trim((string)$dbuser);
	$password = trim((string)$password);

	if ($dbuser === '')
		return result_error("No database user specified.");
	if (strlen($password) < 8)
		return result_error("Password must be at least 8 characters long.");
	if (strlen($password) > 24)
		return result_error("Password must be at most 24 characters long.");
	if (strpos($password, ' ') !== false)
		return result_error("Password must not contain spaces.");
	if (!in_array($dbuser, mysql_rows('SELECT User FROM mysql.user'), true))
		return result_error("Database user '$dbuser' not found.");

	$output = '';
	$hosts  = mysql_rows('SELECT Host FROM mysql.user WHERE User = '.mysql_quote($dbuser));
	foreach ($hosts as $dbhost)
		$output .= mysql_exec('ALTER USER '.mysql_quote($dbuser).'@'.mysql_quote($dbhost).' IDENTIFIED BY '.mysql_quote($password));
	if (trim($output) != '')
		return result_error(trim($output));

	mysql_exec('FLUSH PRIVILEGES');
	route_log("change db password for $dbuser");
	return result_ok("Password for user '$dbuser' successfully changed.", array('user' => $dbuser, 'hosts' => $hosts));
}

/* ---- connection limits ---------------------------------------------------
   MAX_USER_CONNECTIONS on each MySQL user of a hosting account, so one busy or
   attacked site cannot take all of max_connections (151 by default). MariaDB
   counts per user, not per account: every user of the account gets the
   account's number. Stored per account in settings (db-conn-limit-<account>,
   absent = the default), applied when a database user is created and by
   database_conn_sync() (post-install, `reqad account limits --sync-db`). */

function database_conn_default() {
	return 30;
}

/* The account's limit per database user; 0 = no limit. */
function database_conn_limit($account) {
	$v = (string)setting_get('db-conn-limit-'.$account);
	return ctype_digit($v) ? (int)$v : database_conn_default();
}

/* true = the server default applies (nothing stored for the account) */
function database_conn_is_default($account) {
	return !ctype_digit((string)setting_get('db-conn-limit-'.$account));
}

/* MySQL users of an account: array(array(user, host, limit)). A user is the
   account's when its name has the "<account>_" prefix, or when every database
   it is granted on belongs to the account (imported / assigned ones). */
function database_account_users($account) {
	if (!valid_username($account))
		return array();
	$assigned = database_assignments();
	$accounts = database_account_names();
	$grants = array();
	foreach (mysql_rows('SELECT DISTINCT User, Host, Db FROM mysql.db') as $line) {
		list($u, $h, $d) = array_pad(explode("\t", $line), 3, '');
		$grants[$u.'@'.$h][] = database_owner(database_grant_name($d), $assigned, $accounts);
	}
	$out = array();
	foreach (mysql_rows('SELECT User, Host, max_user_connections FROM mysql.user') as $line) {
		list($u, $h, $max) = array_pad(explode("\t", $line), 3, '0');
		if ($u === '' || !valid_mysql_identifier($u))
			continue;
		$mine = strpos($u, $account.'_') === 0;
		if (!$mine && isset($grants[$u.'@'.$h]))
			$mine = count(array_unique($grants[$u.'@'.$h])) === 1 && $grants[$u.'@'.$h][0] === $account;
		if ($mine)
			$out[] = array('user' => $u, 'host' => $h, 'limit' => (int)$max);
	}
	return $out;
}

/* Set MAX_USER_CONNECTIONS on one user@host; '' on success. Takes effect for
   new connections, open ones stay. */
function database_conn_apply($user, $host, $limit) {
	if (!valid_mysql_identifier($user) || !preg_match('/^[A-Za-z0-9.%:_-]{1,255}$/', $host))
		return "invalid user $user@$host";
	return trim(mysql_exec('ALTER USER '.mysql_quote($user).'@'.mysql_quote($host)
		.' WITH MAX_USER_CONNECTIONS '.max(0, (int)$limit)));
}

/* $limit: number, 0 = no limit, null = back to the default. */
function database_conn_limit_set($account, $limit) {
	global $db;
	if (!valid_username($account) || !account_get($db, $account))
		return result_error("Account $account does not exist.");
	if ($limit !== null && ((int)$limit < 0 || (int)$limit > 10000))
		return result_error("Database connections must be 0 (no limit) to 10000.");
	if ($limit === null)
		$db->exec("DELETE FROM settings WHERE name = 'db-conn-limit-".$db->escapeString($account)."'");
	else
		setting_put('db-conn-limit-'.$account, (string)(int)$limit);
	$r = database_conn_sync($account);
	if (!$r['ok'])
		return $r;
	$n = database_conn_limit($account);
	route_log("db connections $account: ".($limit === null ? 'default' : (int)$limit));
	return result_ok("$account: ".($n ? "at most $n connections per database user" : 'no database connection limit')
		.($limit === null ? ' (default)' : '').'; '.count($r['data']['users']).' user(s) updated.', $r['data']);
}

/* Apply the stored limits to the account's database users ('' = every account). */
function database_conn_sync($account = '') {
	$accounts = $account !== '' ? array($account) : array_keys(database_account_names());
	$done = array(); $errors = array();
	foreach ($accounts as $a) {
		$limit = database_conn_limit($a);
		foreach (database_account_users($a) as $u) {
			if ($u['limit'] === $limit)
				continue;
			$e = database_conn_apply($u['user'], $u['host'], $limit);
			if ($e === '') $done[] = $u['user'].'@'.$u['host'];
			else           $errors[] = $u['user'].'@'.$u['host'].': '.$e;
		}
	}
	if ($errors)
		return result_error(implode('; ', $errors), array('users' => $done));
	return result_ok(count($done).' database user(s) updated.', array('users' => $done));
}

/* Connections open right now per MySQL user: array(user => count). */
function database_conn_now() {
	$out = array();
	foreach (mysql_rows('SELECT USER, COUNT(*) FROM information_schema.PROCESSLIST GROUP BY USER') as $line) {
		list($u, $n) = array_pad(explode("\t", $line), 2, '0');
		$out[$u] = (int)$n;
	}
	return $out;
}

/* ---- database users of an account -----------------------------------------
   A user either may use a database, with all privileges (what database_create()
   gives and what a site like WordPress needs for its updates), or may not.
   Grants made some other way (phpMyAdmin, the 2087 page) also count as "may
   use"; taking the access away removes them. A user may also log in from other
   addresses (remote access, below), each with its own databases. */

/* longest MySQL user name (MySQL 5.7+; MariaDB allows 80, older MySQL 16) */
function database_user_max() {
	return 32;
}

/*
 * The account's database users: array of array('user', 'hosts' => array(...),
 * 'remote' => number of hosts other than localhost, 'databases' =>
 * array(array('name', 'remote' => the other hosts it may use it from))).
 * Only the account's own databases are listed under a user.
 */
function database_users($account) {
	if (!valid_username($account))
		return array();
	$assigned = database_assignments();
	$accounts = database_account_names();
	list($user_rows, $grant_rows) = database_mysql_batch(array(
		"SELECT User, Host FROM mysql.user ORDER BY Host = 'localhost' DESC, Host",
		"SELECT DISTINCT User, Host, Db FROM mysql.db ORDER BY Db"));
	/* whose is each grant's database; a user is the account's by its prefix,
	   or when every database it may use is the account's (database_account_users) */
	$grants = array();
	foreach ($grant_rows as $line) {
		$f = explode("\t", $line);
		$d = database_grant_name($f[2] ?? '');
		$grants[$f[0].'@'.($f[1] ?? '')][] = array($d, database_owner($d, $assigned, $accounts));
	}
	$local = array('localhost', '127.0.0.1', '::1');
	$out = array();
	foreach ($user_rows as $line) {
		list($u, $h) = array_pad(explode("\t", $line), 2, '');
		if ($u === '' || !valid_mysql_identifier($u))
			continue;
		$g = $grants[$u.'@'.$h] ?? array();
		$owners = array_unique(array_column($g, 1));
		if (strpos($u, $account.'_') !== 0 && !($owners && count($owners) === 1 && $owners[0] === $account))
			continue;
		if (!isset($out[$u]))
			$out[$u] = array('user' => $u, 'hosts' => array(), 'remote' => 0, 'databases' => array());
		$out[$u]['hosts'][] = $h;
		$far = !in_array($h, $local, true);
		if ($far)
			$out[$u]['remote']++;
		foreach ($g as $row) {
			list($d, $owner) = $row;
			if ($owner !== $account)
				continue;
			if (!isset($out[$u]['databases'][$d]))
				$out[$u]['databases'][$d] = array('name' => $d, 'remote' => array());
			if ($far && !in_array($h, $out[$u]['databases'][$d]['remote'], true))
				$out[$u]['databases'][$d]['remote'][] = $h;
		}
	}
	ksort($out);
	foreach ($out as &$o) {
		ksort($o['databases']);
		$o['databases'] = array_values($o['databases']);
	}
	unset($o);
	return array_values($out);
}

/* Create "<account>_<name>"@localhost, with no database yet. */
function database_user_create($account, $name, $password) {
	global $db;
	$account  = trim((string)$account);
	$name     = trim((string)$name);
	$password = (string)$password;
	if (!valid_username($account) || !account_get($db, $account))
		return result_error("Account $account does not exist.");
	if (!valid_mysql_identifier($name))
		return result_error("Database user may only contain letters, numbers and underscores.");
	$full = $account.'_'.$name;
	if (strlen($full) > database_user_max())
		return result_error("The user name is too long: $full has more than ".database_user_max()." characters.");
	if (strlen($password) < 8 || strlen($password) > 24 || strpos($password, ' ') !== false)
		return result_error("The password must have 8 to 24 characters, without spaces.");
	if (($e = limit_allows($account, 'dbuser', array('user' => $full))) !== '')
		return result_error($e);
	if (in_array($full, mysql_rows('SELECT User FROM mysql.user'), true))
		return result_error("User $full already exists. Please choose a different user name.");

	$out = mysql_exec('CREATE USER '.mysql_quote($full).'@'.mysql_quote('localhost').' IDENTIFIED BY '.mysql_quote($password));
	if (trim($out) !== '')
		return result_error(trim($out));
	database_conn_apply($full, 'localhost', database_conn_limit($account));
	route_log("create db user $full");
	plugin_hook('database_user.created', array('account' => $account, 'user' => $full));
	return result_ok("Database user $full created.", array('user' => $full));
}

/* Drop a database user on every host it exists for; its databases stay. */
function database_user_delete($user) {
	$user = trim((string)$user);
	if (!valid_mysql_identifier($user))
		return result_error("Invalid database user.");
	$hosts = mysql_rows('SELECT Host FROM mysql.user WHERE User = '.mysql_quote($user));
	if (!$hosts)
		return result_error("Database user '$user' not found.");
	$out = '';
	$remote = false;
	foreach ($hosts as $h) {
		$out .= mysql_exec('DROP USER '.mysql_quote($user).'@'.mysql_quote($h));
		if (!in_array($h, array('localhost', '127.0.0.1', '::1'), true))
			$remote = true;
	}
	if (trim($out) !== '')
		return result_error(trim($out));
	mysql_exec('FLUSH PRIVILEGES');
	if ($remote)
		mysql_remote_fw_sync();
	route_log("delete db user $user");
	plugin_hook('database_user.deleted', array('user' => $user, 'hosts' => $hosts));
	return result_ok("Database user $user deleted.", array('user' => $user, 'hosts' => $hosts));
}

/* `name`.* for a grant: _ and % are wildcards in a database grant */
function database_grant_target($database) {
	return '`'.strtr($database, array('_' => '\\_', '%' => '\\%')).'`.*';
}

/* Remove what $user@$host may do in $database (both spellings of the name). */
function database_revoke_all($user, $host, $database) {
	$out = '';
	foreach (mysql_rows('SELECT Db FROM mysql.db WHERE User = '.mysql_quote($user).' AND Host = '.mysql_quote($host)
	                    .' AND '.mysql_db_match_sql($database)) as $stored)
		$out .= mysql_exec('REVOKE ALL PRIVILEGES ON `'.str_replace('`', '', database_rows_field($stored)).'`.* FROM '.mysql_quote($user).'@'.mysql_quote($host));
	return trim($out);
}

/*
 * Whether $user (full name) may use $database: $access 'all' grants all
 * privileges from localhost (and from the remote addresses that already have
 * this database), 'none' takes it away from every host - a remote login left
 * with no database is dropped. The caller checks both belong to one account.
 */
function database_grant($user, $database, $access) {
	$user = trim((string)$user);
	$database = trim((string)$database);
	if (!valid_mysql_identifier($user))
		return result_error("Invalid database user.");
	if (!valid_mysql_identifier($database) || in_array($database, database_system_schemas(), true))
		return result_error("Invalid database name.");
	if ($access !== 'none' && $access !== 'all')
		return result_error("The access must be all or none.");
	$hosts = mysql_rows('SELECT Host FROM mysql.user WHERE User = '.mysql_quote($user));
	if (!$hosts)
		return result_error("Database user '$user' not found.");
	if ($access === 'all' && !in_array($database, mysql_rows('SHOW DATABASES'), true))
		return result_error("Database $database does not exist.");

	$local = array('localhost', '127.0.0.1', '::1');
	$out = '';
	$remote = false;
	if ($access === 'all') {
		$to = array_merge(in_array('localhost', $hosts, true) ? array('localhost') : array(), db_remote_hosts($database, $user));
		if (!$to)
			return result_error("Database user '$user' has no localhost login.");
		foreach ($to as $h)
			$out .= mysql_exec('GRANT ALL PRIVILEGES ON '.database_grant_target($database).' TO '.mysql_quote($user).'@'.mysql_quote($h));
	} else {
		foreach ($hosts as $h) {
			$out .= database_revoke_all($user, $h, $database);
			if (!in_array($h, $local, true) && mysql_drop_user_if_unused($user, $h))
				$remote = true;
		}
	}
	if (trim($out) !== '')
		return result_error(trim($out));
	mysql_exec('FLUSH PRIVILEGES');
	if ($remote)
		mysql_remote_fw_sync();
	route_log("db access $user on $database: $access");
	plugin_hook('database.access', array('user' => $user, 'database' => $database, 'access' => $access));
	return result_ok($access === 'all' ? "$user can now use $database." : "$user can no longer use $database.",
	                 array('user' => $user, 'database' => $database, 'access' => $access));
}

/* ---- remote access ---------------------------------------------------------
   A database user can also log in from another computer: user@<ip>, with the
   same password (db_remote_add() copies the login's authentication clause),
   allowed only the databases chosen for that address. With csf, each address
   is let through on the MySQL port only (scripts/sync_mysql_remote_fw.sh,
   rebuilt from mysql.user after every change). The helpers db_remote_* in
   modules/functions.php are the 2087 page's; these add the checks an
   account needs (limits, connection cap, a cap on addresses). */

/* remote addresses per account (csf.allow gets one line for each) */
function database_remote_max() {
	return 20;
}

/* Can other computers reach MySQL here: port, listening, firewall ('csf' or ''),
   open_to_all (the port is open to everyone in csf), host (to connect to). */
function database_remote_status() {
	$st = mysql_remote_status();
	return array('port' => $st['port'], 'listening' => $st['listening'], 'firewall' => $st['firewall'],
	             'open_to_all' => $st['port_public'], 'host' => (string)gethostname());
}

/* the checks both add and remove need: array($ip, $owner) or a result_error() */
function database_remote_args($user, $database, $ip) {
	if (!valid_mysql_identifier($user) || !valid_mysql_identifier($database) || in_array($database, database_system_schemas(), true))
		return result_error("Invalid database or user.");
	$ip = db_remote_normalize_ip($ip);
	if ($ip === false)
		return result_error("Enter a single IPv4 or IPv6 address, e.g. 203.0.113.10.");
	if (!in_array($database, mysql_rows('SHOW DATABASES'), true))
		return result_error("Database $database does not exist.");
	if (!in_array($user, db_local_users($database), true))
		return result_error("$user cannot use $database. Give it access first.");
	return array($ip, database_owner($database));
}

/* The Needs attention item for remote access follows a change at once when
   running as root (the account panel's broker, the CLI); otherwise the next
   health run (every 30 minutes) updates it. */
function database_remote_health() {
	global $db;
	if (function_exists('posix_geteuid') && posix_geteuid() === 0 && $db instanceof SQLite3 && function_exists('health_run'))
		health_run($db, array('mysql_remote'));
}

/* Let $user (full name) use $database from $ip too. */
function database_remote_add($user, $database, $ip) {
	$user = trim((string)$user);
	$database = trim((string)$database);
	$a = database_remote_args($user, $database, $ip);
	if (isset($a['ok']))
		return $a;
	list($ip, $owner) = $a;
	if (in_array($ip, db_remote_hosts($database, $user), true))
		return result_error("$ip can already connect as $user to $database.");
	if ($owner !== '') {
		$far = array();
		foreach (database_account_users($owner) as $r)
			if (!in_array($r['host'], array('localhost', '127.0.0.1', '::1'), true))
				$far[$r['user'].'@'.$r['host']] = true;
		if (!isset($far[$user.'@'.$ip]) && count($far) >= database_remote_max())
			return result_error("Account $owner already has ".database_remote_max()." remote addresses. Remove one first.");
		if (($e = limit_allows($owner, 'dbremote', array('user' => $user, 'database' => $database, 'ip' => $ip))) !== '')
			return result_error($e);
	}
	$err = db_remote_add($database, $user, $ip);
	if ($err !== '')
		return result_error($err);
	if ($owner !== '')
		database_conn_apply($user, $ip, database_conn_limit($owner));
	mysql_exec('FLUSH PRIVILEGES');
	$fw = mysql_remote_fw_sync();
	route_log("db remote $user@$ip on $database: add (firewall $fw)");
	database_remote_health();
	plugin_hook('database.remote_added', array('user' => $user, 'database' => $database, 'ip' => $ip, 'account' => $owner));
	return result_ok("$user can now connect to $database from $ip.".($fw === 'error' ? ' The firewall could not be updated; the server administrator has to let the address through.' : ''),
	                 array('user' => $user, 'database' => $database, 'ip' => $ip, 'hosts' => db_remote_hosts($database, $user), 'firewall' => $fw));
}

/* $user no longer uses $database from $ip; the login goes once it has no database. */
function database_remote_remove($user, $database, $ip) {
	$user = trim((string)$user);
	$database = trim((string)$database);
	$a = database_remote_args($user, $database, $ip);
	if (isset($a['ok']))
		return $a;
	list($ip, $owner) = $a;
	if (!in_array($ip, db_remote_hosts($database, $user), true))
		return result_error("$ip cannot connect as $user to $database.");
	$err = db_remote_remove($database, $user, $ip);
	if ($err !== '')
		return result_error($err);
	mysql_exec('FLUSH PRIVILEGES');
	$fw = mysql_remote_fw_sync();
	route_log("db remote $user@$ip on $database: remove (firewall $fw)");
	database_remote_health();
	plugin_hook('database.remote_removed', array('user' => $user, 'database' => $database, 'ip' => $ip, 'account' => $owner));
	return result_ok("$ip can no longer connect as $user to $database.",
	                 array('user' => $user, 'database' => $database, 'ip' => $ip, 'hosts' => db_remote_hosts($database, $user), 'firewall' => $fw));
}

/* ---- phpMyAdmin for an account (the account panel on 2083) -----------------
   Each time the panel opens phpMyAdmin it makes a MySQL login of its own,
   rqpma-<hex>@localhost with a random password, that has all privileges on the
   account's databases and nothing else (no global privilege, no other
   account's database). It belongs to the panel session that opened it (table
   sql_logins, db/1047.sql) and is dropped when that session ends: logout
   (database_pma_close), or a session found expired by database_pma_purge(),
   which runs on every opening and every few minutes in the broker.

   The panel gets a single-use ticket (60 s). phpMyAdmin on 2083 /sql/ (signon
   auth, reqad-account/pma/signon.php) redeems it once: the password moves into
   phpMyAdmin's own session and is wiped from the table, and on every request
   it checks that the panel session still lives. Account names cannot contain
   '-', so these logins never count as an account's database users. */

function database_pma_prefix() {
	return 'rqpma-';
}

/* open phpMyAdmin windows per panel session (the oldest login goes first) */
function database_pma_max() {
	return 5;
}

function database_pma_ready() {
	global $db;
	return $db instanceof SQLite3 && @$db->querySingle("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'sql_logins'");
}

function database_pma_drop($user) {
	global $db;
	if (strpos((string)$user, database_pma_prefix()) !== 0 || !preg_match('/^[a-z0-9-]{1,32}$/', (string)$user))
		return;
	mysql_exec('DROP USER IF EXISTS '.mysql_quote($user).'@'.mysql_quote('localhost'));
	if (database_pma_ready()) {
		$st = $db->prepare('DELETE FROM sql_logins WHERE user = :u');
		$st->bindValue(':u', $user, SQLITE3_TEXT);
		$st->execute();
	}
}

/*
 * A new phpMyAdmin login for $account, bound to the panel session $session_id
 * (auth_sessions.id). $database: open that one first ('' = the list).
 * data: token (single use, 60 s), database.
 */
function database_pma_open($account, $session_id, $database = '') {
	global $db;
	if (!valid_username($account) || !account_get($db, $account))
		return result_error("Account $account does not exist.");
	if (!preg_match('/^[0-9a-f]{64}$/', (string)$session_id))
		return result_error('Not a valid session.');
	if (!database_pma_ready())
		return result_error('phpMyAdmin from the account panel needs the Reqad database migration 1047.');
	$database = (string)$database;
	if ($database !== '' && (!valid_mysql_identifier($database) || database_owner($database) !== $account))
		return result_error("Database $database does not exist.");
	database_pma_purge();

	$st = $db->prepare('SELECT user FROM sql_logins WHERE session_id = :s ORDER BY created DESC');
	$st->bindValue(':s', $session_id, SQLITE3_TEXT);
	$res = $st->execute();
	$old = array();
	while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC)))
		$old[] = $r['user'];
	foreach (array_slice($old, database_pma_max() - 1) as $u)
		database_pma_drop($u);

	$user = database_pma_prefix().bin2hex(random_bytes(5));
	$pw = bin2hex(random_bytes(16));
	$who = mysql_quote($user).'@'.mysql_quote('localhost');
	$limit = database_conn_limit($account);
	$sql = 'CREATE USER '.$who.' IDENTIFIED BY '.mysql_quote($pw).($limit ? ' WITH MAX_USER_CONNECTIONS '.(int)$limit : '').";\n";
	foreach (database_list($account) as $d)
		$sql .= 'GRANT ALL PRIVILEGES ON '.database_grant_target($d['name']).' TO '.$who.";\n";
	$out = trim(mysql_exec($sql));
	if ($out !== '') {
		mysql_exec('DROP USER IF EXISTS '.$who);
		return result_error($out);
	}
	$token = bin2hex(random_bytes(16));
	$st = $db->prepare('INSERT INTO sql_logins (user, account, session_id, created, ticket, secret, ticket_at)
	                    VALUES (:u, :a, :s, :c, :t, :p, :c)');
	foreach (array(':u' => $user, ':a' => $account, ':s' => $session_id, ':t' => hash('sha256', $token), ':p' => $pw) as $k => $v)
		$st->bindValue($k, $v, SQLITE3_TEXT);
	$st->bindValue(':c', time(), SQLITE3_INTEGER);
	if (!$st->execute()) {
		mysql_exec('DROP USER IF EXISTS '.$who);
		return result_error('Could not save the phpMyAdmin login.');
	}
	route_log("phpmyadmin login $user for $account");
	return result_ok('', array('token' => $token, 'database' => $database));
}

/* The panel session ended (logout): its phpMyAdmin logins go too. */
function database_pma_close($session_id) {
	global $db;
	if (!database_pma_ready())
		return;
	$st = $db->prepare('SELECT user FROM sql_logins WHERE session_id = :s');
	$st->bindValue(':s', (string)$session_id, SQLITE3_TEXT);
	$res = $st->execute();
	$users = array();
	while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC)))
		$users[] = $r['user'];
	foreach ($users as $u)
		database_pma_drop($u);
}

/* Drop the logins whose panel session has ended (logged out, expired, idle,
   its account turned off), MySQL logins with the prefix and no row, and wipe
   tickets nobody redeemed. Returns how many logins were dropped. */
function database_pma_purge() {
	global $db;
	if (!database_pma_ready())
		return 0;
	$now = time();
	$idle = 60 * (function_exists('auth_cfg') ? auth_cfg('auth-idle-minutes') : 30);
	$db->exec('UPDATE sql_logins SET ticket = \'\', secret = \'\' WHERE secret != \'\' AND ticket_at < '.($now - 60));
	$res = $db->query("SELECT l.user, s.state, s.expires_at, s.last_seen, p.status FROM sql_logins l
	                   LEFT JOIN auth_sessions s ON s.id = l.session_id LEFT JOIN auth_principals p ON p.id = s.principal_id");
	$keep = $dead = array();
	while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
		$alive = $r['state'] === 'active' && $r['status'] === 'active' && $now < (int)$r['expires_at'] && $now - (int)$r['last_seen'] <= $idle;
		if ($alive)
			$keep[$r['user']] = true;
		else
			$dead[] = $r['user'];
	}
	foreach (mysql_rows("SELECT User FROM mysql.user WHERE User LIKE '".database_pma_prefix()."%'") as $u)
		if (!isset($keep[$u]) && !in_array($u, $dead, true))
			$dead[] = $u;
	foreach ($dead as $u)
		database_pma_drop($u);
	return count($dead);
}

/* A database the account just made: its open phpMyAdmin logins may use it. */
function database_pma_grant_new($account, $database) {
	global $db;
	if (!database_pma_ready() || !valid_mysql_identifier($database))
		return;
	$st = $db->prepare('SELECT user FROM sql_logins WHERE account = :a');
	$st->bindValue(':a', (string)$account, SQLITE3_TEXT);
	$res = $st->execute();
	$sql = '';
	while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC)))
		$sql .= 'GRANT ALL PRIVILEGES ON '.database_grant_target($database).' TO '.mysql_quote($r['user']).'@'.mysql_quote('localhost').";\n";
	if ($sql !== '')
		mysql_exec($sql);
}

/* A database that is dropped or changes owner: no phpMyAdmin login keeps it. */
function database_pma_revoke_db($database) {
	$sql = '';
	foreach (mysql_rows("SELECT User, Db FROM mysql.db WHERE User LIKE '".database_pma_prefix()."%' AND ".mysql_db_match_sql($database)) as $line) {
		list($u, $stored) = array_pad(explode("\t", $line), 2, '');
		$sql .= 'REVOKE ALL PRIVILEGES ON `'.str_replace('`', '', database_rows_field($stored)).'`.* FROM '.mysql_quote($u).'@'.mysql_quote('localhost').";\n";
	}
	if ($sql !== '')
		mysql_exec($sql);
}
