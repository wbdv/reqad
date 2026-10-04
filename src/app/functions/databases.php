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
	$sizes = array();
	foreach (mysql_rows('SELECT s.SCHEMA_NAME, IFNULL(ROUND(SUM(t.data_length + t.index_length) / 1048576, 2), 0)'
			.' FROM information_schema.SCHEMATA s LEFT JOIN information_schema.TABLES t ON t.table_schema = s.SCHEMA_NAME'
			.' GROUP BY s.SCHEMA_NAME') as $line) {
		list($name, $size) = array_pad(explode("\t", $line), 2, '0');
		$sizes[$name] = (float)$size;
	}

	/* mysql.db stores LIKE-escaped names for grants such as `foo\_bar`.* */
	$users = array();
	foreach (mysql_rows('SELECT DISTINCT Db, User, Host FROM mysql.db') as $line) {
		list($dbn, $u, $h) = array_pad(explode("\t", $line), 3, '');
		$users[str_replace('\\_', '_', $dbn)][] = $u.'@'.$h;
	}

	$assigned = database_assignments();
	$accounts = database_account_names();
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

	route_log("unassign database $database from {$assigned[$database]}");
	return result_ok("Database $database is no longer assigned to {$assigned[$database]}.",
		array('database' => $database, 'previous' => $assigned[$database]));
}

/*
 * Create "<account>_<name>" and grant it to "<account>_<dbuser>"@localhost.
 * $o keys: account, name, dbuser, password (8+ characters).
 */
function database_create(array $o) {
	global $db;

	$account  = trim((string)($o['account'] ?? ''));
	$dbname   = trim((string)($o['name'] ?? ''));
	$dbuser   = trim((string)($o['dbuser'] ?? ''));
	$password = trim((string)($o['password'] ?? ''));

	if ($account === '' || $dbname === '' || strlen($password) < 8)
		return result_error("Missing account, database name or password (8+ characters).");
	if (!valid_mysql_identifier($dbname))
		return result_error("Database name may only contain letters, numbers and underscores.");
	if (!valid_mysql_identifier($dbuser))
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
	$full_user = $account.'_'.$dbuser;

	if (in_array($full_db, mysql_rows('SHOW DATABASES'), true))
		return result_error("Database $full_db already exists. Please choose a different database name.");
	if (in_array($full_user, mysql_rows('SELECT User FROM mysql.user'), true))
		return result_error("User $full_user already exists. Please choose a different user name.");

	route_log("create database $full_db");
	$output = mysql_exec('CREATE DATABASE `'.$full_db.'`');
	route_log("create user $full_user");
	$output .= mysql_exec('GRANT ALL ON `'.$full_db.'`.* TO `'.$full_user.'`@`localhost` IDENTIFIED BY '.mysql_quote($password));
	/* mysql_exec() folds stderr in, so any output at all means a statement failed. */
	if (trim($output) != '')
		return result_error(trim($output));

	return result_ok("Database $full_db successfully created. User $full_user was assigned to the database.",
		array('database' => $full_db, 'user' => $full_user));
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

	return result_ok("Database $database successfully removed.", array('database' => $database, 'users_dropped' => $dropped));
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
