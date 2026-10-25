<?php
/*
 * Scope: what a hosting account owns, and the per-account limits hook.
 *
 * The admin (port 2087, the CLI as root) may touch anything. Everything that
 * acts FOR an account - the API with an account token, the account panel on
 * 2083, account-level MCP, `reqad ... --user=<account>` - must ask here first,
 * because the functions in app/functions/ take a resource name only
 * (database_delete('bob_wp'), email_delete($db, 'x@y'), ...) and do not check
 * whose it is.
 *
 *   scope_check($scope, $type, $id)   '' = allowed, else the reason. $scope is
 *                                     null for the admin, else an account name.
 *   scope_owns($account, $type, $id)  the rule behind it, per resource type.
 *   limit_allows($account, $resource) '' = may create one more, else the
 *                                     reason. Core allows everything; a plugin
 *                                     (hosting packages) supplies real limits
 *                                     through the 'limit.allows' filter.
 *
 * Unknown types are denied: a new resource type is unreachable for accounts
 * until it has a rule here.
 */

/* Resource types scope_owns() knows, with the id it takes. */
function scope_types() {
	return array(
		'account'        => 'account name',
		'domain'         => 'any domain of the account: main, alias, addon or mail domain',
		'alias'          => 'alias domain',
		'addon'          => 'addon domain',
		'db'             => 'database name',
		'dbuser'         => 'MySQL user name',
		'mailbox'        => 'email address',
		'forwarder'      => 'forwarded address',
		'autoresponder'  => 'autoresponder id or email address',
		'email_filter'   => 'email filter id (domain filters only)',
		'cron'           => 'crontab owner (system user)',
		'ssh_key'        => 'authorized_keys owner (system user)',
		'backup_exclude' => 'backup exclude id',
		'health_item'    => 'health item id (check:account:subject)',
		'deployment'     => 'deployment id or its domain',
		'app'            => 'app id or its domain',
	);
}

/* The hosting account that owns $domain, '' = none. Main domain, alias and
   addon domain first (the web side), then the mail-domain map
   (/etc/exim/userdomains) for mail-only domains. */
function scope_domain_owner($domain) {
	global $db, $ini;
	static $cache = array();
	$domain = strtolower(trim((string)$domain));
	if (!valid_domain($domain))
		return '';
	if (isset($cache[$domain]))
		return $cache[$domain];

	$owner = '';
	if ($row = account_get($db, '', $domain))
		$owner = $row['user'];
	if ($owner === '' && $db instanceof SQLite3) {
		$stmt = $db->prepare('SELECT a.user FROM aliases l JOIN accounts a ON a.id = l.account_id WHERE l.alias = :d');
		$stmt->bindValue(':d', $domain, SQLITE3_TEXT);
		$res = $stmt->execute();
		$r = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;
		if ($r) $owner = $r['user'];
	}
	if ($owner === '' && function_exists('addon_domain_get') && ($a = addon_domain_get($ini, $domain)))
		$owner = $a['user'];
	if ($owner === '')
		$owner = email_domain_owner($domain);
	return $cache[$domain] = $owner;
}

function scope_owns($account, $type, $id) {
	global $db, $ini;
	$account = (string)$account;
	$id = trim((string)$id);
	if (!valid_username($account) || $id === '' || !account_get($db, $account))
		return false;

	switch ($type) {
	case 'account':
		return $id === $account;

	case 'domain':
		return scope_domain_owner($id) === $account;

	case 'alias':
		if (!($db instanceof SQLite3)) return false;
		$stmt = $db->prepare('SELECT 1 FROM aliases l JOIN accounts a ON a.id = l.account_id WHERE l.alias = :d AND a.user = :u');
		$stmt->bindValue(':d', strtolower($id), SQLITE3_TEXT);
		$stmt->bindValue(':u', $account, SQLITE3_TEXT);
		$res = $stmt->execute();
		return (bool)($res && $res->fetchArray());

	case 'addon':
		$a = addon_domain_get($ini, strtolower($id));
		return $a && $a['user'] === $account;

	case 'db':
		return database_owner($id) === $account;

	case 'dbuser':
		return in_array($id, array_column(database_account_users($account), 'user'), true);

	case 'mailbox':
	case 'forwarder':
		$parts = email_split($id);
		return $parts[0] !== '' && scope_domain_owner($parts[1]) === $account;

	case 'autoresponder':
		if (ctype_digit($id)) {
			$r = $db->querySingle('SELECT user, domain FROM autoresponders WHERE id = '.(int)$id, true);
			return $r && scope_domain_owner($r['domain']) === $account;
		}
		$parts = email_split($id);
		return $parts[0] !== '' && scope_domain_owner($parts[1]) === $account;

	case 'email_filter':
		/* global filters belong to the server, domain filters to the domain's owner */
		if (!ctype_digit($id)) return false;
		$r = $db->querySingle('SELECT scope, target FROM email_filters WHERE id = '.(int)$id, true);
		return $r && $r['scope'] === 'domain' && scope_domain_owner($r['target']) === $account;

	case 'cron':
	case 'ssh_key':
		return $id === $account;

	case 'backup_exclude':
		if (!ctype_digit($id)) return false;
		/* '*' rules apply to every account: the admin's */
		return $db->querySingle('SELECT user FROM backup_excludes WHERE id = '.(int)$id) === $account;

	case 'health_item':
		/* server items (account '') are the admin's */
		$r = health_item_get($db, $id);
		return $r && $r['account'] === $account;

	case 'deployment':
		$r = deploy_get($id);
		return $r && $r['account'] === $account;

	case 'app':
		$r = app_get($id);
		return $r && $r['account'] === $account;
	}
	return false;
}

/* '' when $scope may act on the resource, else why not. $scope null (or '')
   = the admin, who may act on anything. */
function scope_check($scope, $type, $id) {
	if ($scope === null || $scope === '')
		return '';
	if (!isset(scope_types()[$type]))
		return "Unknown resource type '$type'.";
	if (scope_owns($scope, $type, $id))
		return '';
	if ($type === 'account')
		return "Account $scope can only manage itself, not '$id'.";
	$what = array('db' => 'Database', 'dbuser' => 'Database user', 'mailbox' => 'Mailbox', 'forwarder' => 'Forwarder',
	              'domain' => 'Domain', 'alias' => 'Alias domain', 'addon' => 'Addon domain', 'account' => 'Account',
	              'autoresponder' => 'Autoresponder', 'email_filter' => 'Email filter', 'cron' => 'Cron job',
	              'ssh_key' => 'SSH key', 'backup_exclude' => 'Backup exclude', 'health_item' => 'Health item',
	              'deployment' => 'Deployment', 'app' => 'App');
	return ($what[$type] ?? 'Resource')." '$id' does not belong to account $scope.";
}

/* Same check, as a result_error() for the app/functions callers; null when allowed. */
function scope_result($scope, $type, $id) {
	$e = scope_check($scope, $type, $id);
	return $e === '' ? null : result_error($e, array('scope' => $scope, 'type' => $type, 'id' => $id));
}

/*
 * May $account create one more $resource? '' = yes, else the reason.
 * Resources: account (with $account = '' - a new account), database, dbuser,
 * mailbox, forwarder, autoresponder, alias, addon, cron, ssh_key, deployment, app.
 * $context carries what is being created (name, domain, ...); for addon the
 * domain lets a package count a subdomain of the account's own domains apart.
 *
 * Core has no limits. A plugin adds them with
 *     'filters' => array('limit.allows' => function ($ok, $account, $resource, $context) { ... })
 * returning true, or a string with the reason when the limit is reached.
 */
function limit_allows($account, $resource, array $context = array()) {
	$v = plugin_filter('limit.allows', true, (string)$account, (string)$resource, $context);
	if ($v === true || $v === null)
		return '';
	if (is_string($v) && $v !== '')
		return $v;
	return $account !== ''
		? "Account $account has reached its limit for: $resource."
		: "The limit for new $resource"."s on this server has been reached.";
}
