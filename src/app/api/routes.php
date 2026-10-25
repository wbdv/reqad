<?php
/*
 * The API's endpoints: one table, served by api_serve() (app/api/api.php),
 * listed by GET /api/v1/ and on the Settings > API access page, and offered
 * as tools to AI assistants by the MCP server (app/mcp/mcp.php).
 *
 * Each route: method, path ({name} = a path parameter), perm (read | write |
 * delete - the token must have it), admin (true = whole-server tokens only),
 * feature (an etc/server-software.ini switch that must be 1), summary, body
 * (accepted fields, for the reference) and handler. A handler gets $ctx
 * (token, scope = the token's account or null, params, query, body) and
 * returns a result_ok()/result_error() array (200 / 400) or array(status, result).
 *
 * mcp (optional): the route as an MCP tool -
 *   name         the tool's name (snake_case)
 *   args         name => 'type: description', a trailing ? on the name = optional.
 *                type: string | integer | boolean | enum(a,b,c). An arg named
 *                like a {path parameter} fills it; the rest go in the query
 *                (GET) or the body.
 *   description  instead of the summary
 *   untrusted    the result carries text from outside (logs, mail, commit
 *                messages): the assistant is told not to follow instructions in it
 * A route without mcp is not offered to assistants. Routes with perm delete
 * need a second, confirmed call there (app/mcp/mcp.php).
 *
 * Handlers call the same app/functions as the panel and the CLI. With an
 * account token ($ctx['scope']) they ask scope_check() before touching a
 * resource and filter every list - the functions themselves take a name only.
 *
 * Plugins add routes with the 'api.routes' filter (same shape, mcp included).
 */

function api_routes() {
	static $routes = null;
	if ($routes !== null)
		return $routes;
	$pw = 'password (omit to get a generated one in data.password)';
	$mpw = 'string: Password. Leave it out to have a strong one generated and returned';
	$fbody = 'name, conditions [{field (from, to, subject, to,cc, body, size or a header), op (contains, is, begins, ends, matches, regex, not-..., over, under), value}], actions [{type (fileinto, redirect, reject, discard, keep, seen, flagged), value}], match (all|any), enabled, stop (no later filter runs)';
	$fargs = array('email' => 'string: The mailbox',
		'name' => 'string: A name for the filter',
		'conditions' => 'string: JSON list of conditions, e.g. [{"field":"from","op":"contains","value":"news@"}]. field: from, to, subject, to,cc (any recipient), body, size (bytes, op over/under) or a header name; op: contains, is, begins, ends, matches (* and ?), regex, or not-contains etc. Empty list = every message',
		'actions' => 'string: JSON list of actions, e.g. [{"type":"fileinto","value":"Newsletters"}]. type: fileinto (a folder), redirect (an address), reject (a text for the sender), discard, keep, seen, flagged',
		'match?' => 'enum(all,any): all conditions must match (default) or any one',
		'enabled?' => 'boolean: false = keep the filter but do not run it',
		'stop?' => 'boolean: true = no later filter runs for a message this one matches');
	$routes = array(
		array('method' => 'GET', 'path' => '/v1', 'perm' => 'read', 'handler' => 'api_v1_index',
		      'summary' => 'This server, your token, and the list of endpoints'),

		array('method' => 'GET', 'path' => '/v1/server', 'perm' => 'read', 'admin' => true, 'handler' => 'api_v1_server',
		      'summary' => 'The server: OS, uptime, load, memory, disks, web server, PHP, mail queue, health counts',
		      'mcp' => array('name' => 'server_status',
		                     'description' => 'Overview of the server: OS, Reqad version, uptime, load, CPUs, memory, disk usage, web server, PHP versions, number of accounts, mail queue size and how many health problems there are. A good first call.')),
		array('method' => 'GET', 'path' => '/v1/services', 'perm' => 'read', 'admin' => true, 'handler' => 'api_v1_services',
		      'summary' => 'The services and timers of the Services page with their state',
		      'mcp' => array('name' => 'services_list',
		                     'description' => 'The system services (web server, mail, database, PHP-FPM, firewall...) and timers with their state: active or not, enabled, since when.')),
		array('method' => 'POST', 'path' => '/v1/services/{name}/restart', 'perm' => 'write', 'admin' => true, 'handler' => 'api_v1_service_restart',
		      'summary' => 'Restart a service of the Services page',
		      'mcp' => array('name' => 'service_restart', 'args' => array('name' => 'string: Service name, as services_list shows it'),
		                     'description' => 'Restart one service from services_list. Returns its state afterwards, with its last journal lines when it did not start.')),

		array('method' => 'GET', 'path' => '/v1/accounts', 'perm' => 'read', 'handler' => 'api_v1_accounts',
		      'summary' => 'Hosting accounts',
		      'mcp' => array('name' => 'accounts_list', 'description' => 'The hosting accounts: user, main domain, disk quota and use, status.')),
		array('method' => 'POST', 'path' => '/v1/accounts', 'perm' => 'write', 'admin' => true, 'handler' => 'api_v1_account_create',
		      'summary' => 'Create an account',
		      'body' => "user, domain, $pw, quota (MB), php, shell (full|jailed|none), dns, letsencrypt, email, www (true|false), package (with Hosting Pro)",
		      'mcp' => array('name' => 'account_create', 'args' => array(
		          'user' => 'string: Account (system user) name: lowercase letters and digits, starting with a letter',
		          'domain' => 'string: The main domain, e.g. example.com',
		          'password?' => $mpw,
		          'quota?' => 'integer: Disk quota in MB',
		          'php?' => 'string: PHP version such as "8.3", or "off" for none',
		          'shell?' => 'enum(full,jailed,none): Shell access (default: the server\'s setting)',
		          'dns?' => 'boolean: Create the DNS zone at the DNS provider',
		          'letsencrypt?' => 'boolean: Request a Let\'s Encrypt certificate',
		          'email?' => 'boolean: Enable mail for the domain',
		          'www?' => 'boolean: Add the www. alias',
		          'package?' => 'string: Hosting package (when the server has packages; default: the server\'s default package)'))),
		array('method' => 'GET', 'path' => '/v1/accounts/{user}', 'perm' => 'read', 'handler' => 'api_v1_account_show',
		      'summary' => 'One account: PHP, shell, mailboxes, databases, limits',
		      'mcp' => array('name' => 'account_show', 'args' => array('user' => 'string: Account name'),
		                     'description' => 'One hosting account in detail: domains, PHP version, shell, mailboxes, databases, limits.')),
		array('method' => 'PATCH', 'path' => '/v1/accounts/{user}', 'perm' => 'write', 'handler' => 'api_v1_account_edit',
		      'summary' => 'Change an account',
		      'body' => 'password, php ("8.3", "off"); whole-server tokens also: email (true|false), shell (full|jailed|none)',
		      'mcp' => array('name' => 'account_edit', 'args' => array(
		          'user' => 'string: Account name',
		          'password?' => 'string: New password for the account',
		          'php?' => 'string: PHP version such as "8.3", or "off" to turn PHP off',
		          'email?' => 'boolean: Enable or disable mail for the domain',
		          'shell?' => 'enum(full,jailed,none): Shell access'))),
		array('method' => 'DELETE', 'path' => '/v1/accounts/{user}', 'perm' => 'delete', 'admin' => true, 'handler' => 'api_v1_account_delete',
		      'summary' => 'Delete an account with its files, mail and databases',
		      'mcp' => array('name' => 'account_delete', 'args' => array('user' => 'string: Account name'),
		                     'description' => 'Delete a hosting account with its website files, mail, databases, certificates and DNS zone. Cannot be undone.')),

		array('method' => 'GET', 'path' => '/v1/databases', 'perm' => 'read', 'handler' => 'api_v1_databases',
		      'summary' => 'MySQL databases (?account=<user>)',
		      'mcp' => array('name' => 'databases_list', 'args' => array('account?' => 'string: Only this account\'s databases'),
		                     'description' => 'MySQL/MariaDB databases with their account, size and users.')),
		array('method' => 'POST', 'path' => '/v1/databases', 'perm' => 'write', 'handler' => 'api_v1_database_create',
		      'summary' => 'Create <account>_<name> with user <account>_<dbuser>',
		      'body' => "account, name, dbuser (default: name), $pw; user: false = the database alone; grant = an existing database user (full name) that gets all privileges on it",
		      'mcp' => array('name' => 'database_create', 'args' => array(
		          'account' => 'string: The account it belongs to (its name becomes the prefix)',
		          'name' => 'string: Name without the prefix: "wp" makes <account>_wp',
		          'dbuser?' => 'string: Database user name without the prefix (default: the same as name)',
		          'password?' => $mpw,
		          'user?' => 'boolean: false = make the database without a new user',
		          'grant?' => 'string: An existing database user (full name, e.g. bob_wp) that gets all privileges on the new database'))),
		array('method' => 'GET', 'path' => '/v1/databases/{name}/export', 'perm' => 'read', 'handler' => 'api_v1_database_export',
		      'summary' => 'Download a database as SQL: the file itself, not JSON (curl -OJ). mysqldump, gzip-compressed (.sql.gz); ?gzip=false = plain .sql. A complete dump ends with "-- Dump completed"'),
		array('method' => 'DELETE', 'path' => '/v1/databases/{name}', 'perm' => 'delete', 'handler' => 'api_v1_database_delete',
		      'summary' => 'Drop a database (and its users that have no other database)',
		      'mcp' => array('name' => 'database_delete', 'args' => array('name' => 'string: Full database name, e.g. bob_wp'),
		                     'description' => 'Drop a database and its users that have no other database. Cannot be undone.')),
		array('method' => 'GET', 'path' => '/v1/database-sites', 'perm' => 'read', 'handler' => 'api_v1_database_sites',
		      'summary' => 'Which of an account\'s databases its WordPress sites use: database => sites (?account=<user>)',
		      'mcp' => array('name' => 'database_sites', 'args' => array('account?' => 'string: The account (required with a whole-server token)'),
		                     'description' => 'The databases an account\'s WordPress sites use (DB_NAME in wp-config.php), with the sites using each. Check it before deleting a database.')),
		array('method' => 'POST', 'path' => '/v1/database-users/{user}/password', 'perm' => 'write', 'handler' => 'api_v1_dbuser_password',
		      'summary' => 'Set a database user\'s password', 'body' => $pw,
		      'mcp' => array('name' => 'database_user_password', 'args' => array(
		          'user' => 'string: Full database user name, e.g. bob_wp', 'password?' => $mpw))),
		array('method' => 'GET', 'path' => '/v1/database-users', 'perm' => 'read', 'handler' => 'api_v1_dbusers',
		      'summary' => 'An account\'s database users, the databases each may use and the remote addresses per database (?account=<user>)',
		      'mcp' => array('name' => 'database_users_list', 'args' => array('account?' => 'string: The account (required with a whole-server token)'),
		                     'description' => 'MySQL users of an account, the hosts they log in from and the databases each may use (with the remote addresses allowed per database).')),
		array('method' => 'POST', 'path' => '/v1/database-users', 'perm' => 'write', 'handler' => 'api_v1_dbuser_create',
		      'summary' => 'Create database user <account>_<name> (no database yet)', 'body' => "account, name, $pw",
		      'mcp' => array('name' => 'database_user_create', 'args' => array(
		          'account' => 'string: The account it belongs to (its name becomes the prefix)',
		          'name' => 'string: Name without the prefix: "app" makes <account>_app', 'password?' => $mpw))),
		array('method' => 'PATCH', 'path' => '/v1/database-users/{user}', 'perm' => 'write', 'handler' => 'api_v1_dbuser_access',
		      'summary' => 'Whether a database user may use one database', 'body' => 'database (full name), access (all|none)',
		      'mcp' => array('name' => 'database_user_access', 'args' => array(
		          'user' => 'string: Full database user name, e.g. bob_wp', 'database' => 'string: Full database name, e.g. bob_wp',
		          'access' => 'enum(all,none): all = all privileges on the database; none = no access (also from remote addresses)'))),
		/* remote access opens the MySQL port in the firewall for an address: no MCP tools */
		array('method' => 'GET', 'path' => '/v1/database-remote', 'perm' => 'read', 'handler' => 'api_v1_dbremote_status',
		      'summary' => 'Can other computers connect to MySQL here: port, listening, firewall, open_to_all, host'),
		array('method' => 'POST', 'path' => '/v1/database-remote', 'perm' => 'write', 'handler' => 'api_v1_dbremote_add',
		      'summary' => 'Let a database user connect to one database from an IP address', 'body' => 'user, database (full names), ip'),
		array('method' => 'DELETE', 'path' => '/v1/database-remote', 'perm' => 'delete', 'handler' => 'api_v1_dbremote_remove',
		      'summary' => 'Remove an IP address from a database user\'s remote access to one database', 'body' => 'user, database (full names), ip'),
		array('method' => 'DELETE', 'path' => '/v1/database-users/{user}', 'perm' => 'delete', 'handler' => 'api_v1_dbuser_delete',
		      'summary' => 'Delete a database user (its databases stay)',
		      'mcp' => array('name' => 'database_user_delete', 'args' => array('user' => 'string: Full database user name, e.g. bob_wp'),
		                     'description' => 'Delete a MySQL user on every host it exists for. Its databases and their data stay.')),

		array('method' => 'GET', 'path' => '/v1/mailboxes', 'perm' => 'read', 'handler' => 'api_v1_mailboxes',
		      'summary' => 'Mailboxes (?domain=<domain>)',
		      'mcp' => array('name' => 'mailboxes_list', 'args' => array('domain?' => 'string: Only this domain\'s mailboxes'),
		                     'description' => 'Mailboxes with their size and whether they can log in.')),
		array('method' => 'POST', 'path' => '/v1/mailboxes', 'perm' => 'write', 'handler' => 'api_v1_mailbox_create',
		      'summary' => 'Create a mailbox', 'body' => "email, $pw, size (Hosting Pro)",
		      'mcp' => array('name' => 'mailbox_create', 'args' => array(
		          'email' => 'string: The address, e.g. office@example.com (the domain must have mail on)', 'password?' => $mpw,
		          'size?' => 'string: Mailbox size, e.g. 500M or 2G (needs Reqad Hosting Pro); left out = the account\'s default'))),
		array('method' => 'PATCH', 'path' => '/v1/mailboxes/{email}', 'perm' => 'write', 'handler' => 'api_v1_mailbox_edit',
		      'summary' => 'Change a mailbox', 'body' => 'password, enabled (true|false), size (Hosting Pro: e.g. 500M, or default)',
		      'mcp' => array('name' => 'mailbox_edit', 'args' => array(
		          'email' => 'string: The address',
		          'password?' => 'string: New password',
		          'enabled?' => 'boolean: false = the mailbox cannot log in (mail is still delivered)',
		          'size?' => 'string: Mailbox size, e.g. 500M or 2G, or "default" = the account\'s default (needs Reqad Hosting Pro)'))),
		array('method' => 'DELETE', 'path' => '/v1/mailboxes/{email}', 'perm' => 'delete', 'handler' => 'api_v1_mailbox_delete',
		      'summary' => 'Delete a mailbox and its mail',
		      'mcp' => array('name' => 'mailbox_delete', 'args' => array('email' => 'string: The address'),
		                     'description' => 'Delete a mailbox and all its mail. Cannot be undone.')),

		array('method' => 'GET', 'path' => '/v1/mailboxes/{email}/setup', 'perm' => 'read', 'handler' => 'api_v1_mailbox_setup',
		      'summary' => 'Settings for mail apps: server, ports, user name (?apple=1 adds an Apple configuration profile)',
		      'mcp' => array('name' => 'mailbox_setup', 'args' => array('email' => 'string: The address'),
		                     'description' => 'The settings a phone or desktop mail app needs for a mailbox: incoming (IMAP, POP3) and outgoing (SMTP) server, ports, security and user name.')),

		array('method' => 'GET', 'path' => '/v1/forwarders', 'perm' => 'read', 'handler' => 'api_v1_forwarders',
		      'summary' => 'Forwarders (?domain=<domain>)',
		      'mcp' => array('name' => 'forwarders_list', 'args' => array('domain?' => 'string: Only this domain\'s forwarders'),
		                     'description' => 'Addresses that forward their mail to other addresses (a mailbox with the same address keeps its copy).')),
		array('method' => 'POST', 'path' => '/v1/forwarders', 'perm' => 'write', 'handler' => 'api_v1_forwarder_create',
		      'summary' => 'Create a forwarder', 'body' => 'email, to (addresses: a list or "a@b, c@d"), pipe (a program\'s full path; server tokens only)',
		      'mcp' => array('name' => 'forwarder_create', 'args' => array(
		          'email' => 'string: The address that forwards, e.g. sales@example.com (the domain must have mail on)',
		          'to' => 'string: Where to, one or more addresses separated by commas'))),
		array('method' => 'PATCH', 'path' => '/v1/forwarders/{email}', 'perm' => 'write', 'handler' => 'api_v1_forwarder_edit',
		      'summary' => 'Change a forwarder', 'body' => 'to (the new list), new_email (move it), pipe (server tokens only; "" removes it)',
		      'mcp' => array('name' => 'forwarder_edit', 'args' => array(
		          'email' => 'string: The address that forwards',
		          'to' => 'string: The new list of addresses, separated by commas (replaces the old one)'))),
		array('method' => 'DELETE', 'path' => '/v1/forwarders/{email}', 'perm' => 'delete', 'handler' => 'api_v1_forwarder_delete',
		      'summary' => 'Delete a forwarder',
		      'mcp' => array('name' => 'forwarder_delete', 'args' => array('email' => 'string: The address that forwards'),
		                     'description' => 'Stop forwarding the mail of an address. A mailbox with that address is not touched.')),

		array('method' => 'GET', 'path' => '/v1/autoresponders', 'perm' => 'read', 'handler' => 'api_v1_autoresponders',
		      'summary' => 'Autoresponders (?domain=<domain>); state: on, scheduled or ended',
		      'mcp' => array('name' => 'autoresponders_list', 'args' => array('domain?' => 'string: Only this domain\'s autoresponders'),
		                     'description' => 'Automatic replies (away, out of office) of the mailboxes, with their dates and whether they are on now.')),
		array('method' => 'POST', 'path' => '/v1/autoresponders', 'perm' => 'write', 'handler' => 'api_v1_autoresponder_create',
		      'summary' => 'Create an autoresponder', 'body' => 'email, subject, message (%subject% = the subject answered), date_from, date_to (YYYY-MM-DD, optional)',
		      'mcp' => array('name' => 'autoresponder_create', 'args' => array(
		          'email' => 'string: A mailbox',
		          'subject' => 'string: Subject of the reply; %subject% is replaced by the subject of the message answered',
		          'message' => 'string: Text of the reply',
		          'date_from?' => 'string: First day, YYYY-MM-DD (left out = from today)',
		          'date_to?' => 'string: Last day, YYYY-MM-DD (left out = until it is deleted)'))),
		array('method' => 'PATCH', 'path' => '/v1/autoresponders/{email}', 'perm' => 'write', 'handler' => 'api_v1_autoresponder_edit',
		      'summary' => 'Change an autoresponder', 'body' => 'subject, message, date_from, date_to ("" = no limit)',
		      'mcp' => array('name' => 'autoresponder_edit', 'args' => array(
		          'email' => 'string: The mailbox',
		          'subject?' => 'string: New subject', 'message?' => 'string: New text',
		          'date_from?' => 'string: First day, YYYY-MM-DD, or "" for none', 'date_to?' => 'string: Last day, YYYY-MM-DD, or "" for none'))),
		array('method' => 'DELETE', 'path' => '/v1/autoresponders/{email}', 'perm' => 'delete', 'handler' => 'api_v1_autoresponder_delete',
		      'summary' => 'Delete an autoresponder',
		      'mcp' => array('name' => 'autoresponder_delete', 'args' => array('email' => 'string: The mailbox'))),

		array('method' => 'GET', 'path' => '/v1/mailboxes/{email}/filters', 'perm' => 'read', 'handler' => 'api_v1_mailbox_filters',
		      'summary' => 'A mailbox\'s own filters, numbered in the order they run, and its folders (?folders=0 leaves them out)',
		      'mcp' => array('name' => 'mailbox_filters', 'args' => array('email' => 'string: The mailbox'),
		                     'description' => 'The filters of a mailbox (its own rules, the ones webmail shows too), numbered in the order they run, and its folders. editable=false: the script was written by hand and cannot be changed with these tools.')),
		array('method' => 'POST', 'path' => '/v1/mailboxes/{email}/filters', 'perm' => 'write', 'handler' => 'api_v1_mailbox_filter_create',
		      'summary' => 'Add a filter to a mailbox', 'body' => $fbody,
		      'mcp' => array('name' => 'mailbox_filter_create', 'args' => $fargs + array('position?' => 'integer: Its number, 1 = runs first (left out = last)'))),
		array('method' => 'PATCH', 'path' => '/v1/mailboxes/{email}/filters/{n}', 'perm' => 'write', 'handler' => 'api_v1_mailbox_filter_edit',
		      'summary' => 'Change a filter of a mailbox (the fields sent replace the old ones)', 'body' => $fbody.', expect_name (refuse if filter n has another name)',
		      'mcp' => array('name' => 'mailbox_filter_edit', 'args' => array('email' => 'string: The mailbox', 'n' => 'integer: The filter\'s number from mailbox_filters')
		          + array_combine(array_map(function ($k) { return rtrim($k, '?').'?'; }, array_keys(array_slice($fargs, 1))), array_slice($fargs, 1))
		          + array('position?' => 'integer: Move it to this number, 1 = runs first'))),
		array('method' => 'DELETE', 'path' => '/v1/mailboxes/{email}/filters/{n}', 'perm' => 'delete', 'handler' => 'api_v1_mailbox_filter_delete',
		      'summary' => 'Delete a filter of a mailbox (?expect_name= refuses if filter n has another name)',
		      'mcp' => array('name' => 'mailbox_filter_delete', 'args' => array('email' => 'string: The mailbox', 'n' => 'integer: The filter\'s number from mailbox_filters'))),

		array('method' => 'GET', 'path' => '/v1/domain-filters', 'perm' => 'read', 'handler' => 'api_v1_domain_filters',
		      'summary' => 'The filters for all mailboxes of a domain, in the order they run (?domain=, ?folders=0)',
		      'mcp' => array('name' => 'domain_filters', 'args' => array('domain' => 'string: The mail domain'),
		                     'description' => 'Filters that run for every mailbox of a domain, before each mailbox\'s own filters, in the order they run (with their id). managed=true: set from the spam settings, change it with spam_settings_edit.')),
		array('method' => 'POST', 'path' => '/v1/domain-filters', 'perm' => 'write', 'handler' => 'api_v1_domain_filter_create',
		      'summary' => 'Add a filter for all mailboxes of a domain', 'body' => 'domain, '.$fbody.', position',
		      'mcp' => array('name' => 'domain_filter_create', 'args' => array('domain' => 'string: The mail domain') + array_slice($fargs, 1)
		          + array('position?' => 'integer: Its number, 1 = runs first (left out = last)'))),
		array('method' => 'PATCH', 'path' => '/v1/domain-filters/{id}', 'perm' => 'write', 'handler' => 'api_v1_domain_filter_edit',
		      'summary' => 'Change a domain filter (the fields sent replace the old ones)', 'body' => $fbody.', position, expect_name',
		      'mcp' => array('name' => 'domain_filter_edit', 'args' => array('id' => 'integer: The filter\'s id from domain_filters')
		          + array_combine(array_map(function ($k) { return rtrim($k, '?').'?'; }, array_keys(array_slice($fargs, 1))), array_slice($fargs, 1))
		          + array('position?' => 'integer: Move it to this number, 1 = runs first'))),
		array('method' => 'DELETE', 'path' => '/v1/domain-filters/{id}', 'perm' => 'delete', 'handler' => 'api_v1_domain_filter_delete',
		      'summary' => 'Delete a domain filter (?expect_name=)',
		      'mcp' => array('name' => 'domain_filter_delete', 'args' => array('id' => 'integer: The filter\'s id from domain_filters'))),

		/* PHP of an account's sites: version and a few settings (app/functions/php.php) */
		array('method' => 'GET', 'path' => '/v1/php', 'perm' => 'read', 'handler' => 'api_v1_php',
		      'summary' => 'An account\'s sites with their PHP version, the versions installed and the settings that can be changed (server tokens: ?account=; ?domain= adds that site\'s settings, ?settings=1 the main domain\'s)',
		      'mcp' => array('name' => 'php_sites', 'args' => array('account?' => 'string: The hosting account (server tokens only)', 'domain?' => 'string: Also return this site\'s PHP settings'),
		                     'description' => 'PHP of a hosting account\'s sites (main domain and addon domains): the version each runs, the versions installed (with their end of life), and with domain the site\'s settings (memory_limit, upload_max_filesize, max_execution_time, max_input_vars, display_errors, date.timezone: the value set and the default).')),
		array('method' => 'PATCH', 'path' => '/v1/php/{domain}', 'perm' => 'write', 'handler' => 'api_v1_php_edit',
		      'summary' => 'Change a site\'s PHP', 'body' => 'version ("8.3"; "8.5:mod_php" / "8.3:fpm" on a mod_php server; "off" = no PHP, main domain only), settings ({"memory_limit": "512M", ...}; "" = the default)',
		      'mcp' => array('name' => 'php_site_edit', 'args' => array(
		          'domain' => 'string: The site: the account\'s main domain or an addon domain',
		          'version?' => 'string: PHP version to run, one of those installed (php_sites); on a server with php_mode modphp add :mod_php (default version only) or :fpm; "off" turns PHP off (main domain only, its .php files then answer 403)',
		          'settings?' => 'string: JSON object of settings to change, e.g. {"memory_limit":"512M","upload_max_filesize":"64M"}; "" puts one back to the default. Allowed values: memory_limit 128M/256M/512M/1G/2G, upload_max_filesize 8M-512M (also sets post_max_size), max_execution_time 30/60/120/300, max_input_vars 1000/3000/5000/10000, display_errors on/off, date.timezone a zone name'))),
		/* an account's sites (main + addon domains, subdomains), aliases, certificates (app/functions/domains.php) */
		array('method' => 'GET', 'path' => '/v1/domains', 'perm' => 'read', 'handler' => 'api_v1_domains',
		      'summary' => 'An account\'s sites (main domain, addon domains, subdomains) with their folder and certificate, and the aliases of its main site (server tokens: ?account=; ?check=1 adds where each site points in DNS)',
		      'mcp' => array('name' => 'domains_list', 'args' => array('account?' => 'string: The hosting account (server tokens only)', 'check?' => 'boolean: Also look up where each site points in DNS'),
		                     'description' => 'The websites of a hosting account: its main domain, addon domains and subdomains, each with its folder, whether www. is served and its certificate (issuer, expiry, names), plus the alias domains of the main site and the address the sites should point to.')),
		array('method' => 'POST', 'path' => '/v1/domains', 'perm' => 'write', 'handler' => 'api_v1_domain_add',
		      'summary' => 'Add a site: an addon domain or a subdomain, with its own folder', 'body' => 'domain, folder (relative to the home, default: the domain name; or public_html/<name>), www (default: true, false for a subdomain of the account\'s domains), mail (email for it when the account has email; default: true, false for a subdomain of the account\'s domains), account (server tokens)',
		      'mcp' => array('name' => 'domain_add', 'args' => array(
		          'domain' => 'string: The new site\'s domain, e.g. shop.example.com or example.org',
		          'folder?' => 'string: Its folder, relative to the home folder: a new folder name, or public_html/<name> (default: the domain name)',
		          'www?' => 'boolean: Serve www.<domain> too (default: yes, no for a subdomain of the account\'s own domains)',
		          'mail?' => 'boolean: Email for it when the account has email (default: yes, no for a subdomain of the account\'s own domains)',
		          'account?' => 'string: The hosting account (server tokens only)'),
		          'description' => 'Add a website to a hosting account: an addon domain or a subdomain with its own folder, web server site and PHP (the main site\'s version). Its DNS A record is made when the zone is at the server\'s DNS provider; otherwise the result says where to point it.')),
		array('method' => 'DELETE', 'path' => '/v1/domains/{domain}', 'perm' => 'delete', 'handler' => 'api_v1_domain_delete',
		      'summary' => 'Remove an addon domain or subdomain (files=true also deletes its folder)', 'body' => 'files (true|false)',
		      'mcp' => array('name' => 'domain_delete', 'args' => array('domain' => 'string: The addon domain or subdomain', 'files?' => 'boolean: Also delete its folder and everything in it (default: keep the files)'),
		                     'description' => 'Remove an addon domain or subdomain of a hosting account: its web server site, PHP pool and certificate. Its files stay unless files is true. The main domain cannot be removed.')),
		array('method' => 'POST', 'path' => '/v1/domains/{domain}/mail', 'perm' => 'write', 'handler' => 'api_v1_domain_mail',
		      'summary' => 'Turn on email for an addon domain or subdomain (new addon domains get it when the account has email, subdomains do not); also fixes a site that should have email but has none',
		      'mcp' => array('name' => 'domain_mail_enable', 'args' => array('domain' => 'string: The addon domain or subdomain'),
		                     'description' => 'Turn on email for an addon domain or subdomain of a hosting account, so mailboxes, forwarders and autoresponders can be made for it. Its mail DNS records (MX, SPF, DKIM, DMARC) are added when its zone is at the server\'s DNS provider; otherwise the result says so.')),
		array('method' => 'DELETE', 'path' => '/v1/domains/{domain}/mail', 'perm' => 'delete', 'handler' => 'api_v1_domain_mail_off',
		      'summary' => 'Turn off email for an addon domain or subdomain (refused while it has mailboxes; its forwarders and autoresponders go)',
		      'mcp' => array('name' => 'domain_mail_disable', 'args' => array('domain' => 'string: The addon domain or subdomain'),
		                     'description' => 'Turn off email for an addon domain or subdomain of a hosting account. Refused while it has mailboxes. Its forwarders, autoresponders and filters are deleted. When its zone is at the server\'s DNS provider and its mail came to this server, its MX and DKIM records go and SPF says it sends no mail.')),
		array('method' => 'POST', 'path' => '/v1/aliases', 'perm' => 'write', 'handler' => 'api_v1_alias_add',
		      'summary' => 'Add an alias of the main site (another name showing the same site; *.example.com for every subdomain)', 'body' => 'alias, dns (default true: make its A record when the zone is at the DNS provider), account (server tokens)',
		      'mcp' => array('name' => 'alias_add', 'args' => array('alias' => 'string: The alias domain, e.g. example.net or *.example.com', 'dns?' => 'boolean: Make its DNS A record when the zone is at the DNS provider (default: yes)',
		                                                          'account?' => 'string: The hosting account (server tokens only)'),
		                     'description' => 'Add an alias domain to a hosting account\'s main site: the same site under another name. A Let\'s Encrypt certificate of the site is extended to it.')),
		array('method' => 'DELETE', 'path' => '/v1/aliases/{alias}', 'perm' => 'delete', 'handler' => 'api_v1_alias_delete',
		      'summary' => 'Remove an alias of the main site',
		      'mcp' => array('name' => 'alias_delete', 'args' => array('alias' => 'string: The alias domain'))),
		array('method' => 'GET', 'path' => '/v1/redirects', 'perm' => 'read', 'handler' => 'api_v1_redirects',
		      'summary' => 'The redirects of an account\'s sites (server tokens: ?account=)',
		      'mcp' => array('name' => 'redirects_list', 'args' => array('account?' => 'string: The hosting account (server tokens only)'),
		                     'description' => 'The redirects of a hosting account\'s sites: for each, the site, the address it redirects (/ = the whole site), whether everything under that address goes too, the target and whether it is permanent (301) or temporary (302).')),
		array('method' => 'POST', 'path' => '/v1/redirects', 'perm' => 'write', 'handler' => 'api_v1_redirect_add',
		      'summary' => 'Redirect a site, or an address of it, to another address', 'body' => 'domain (the site), from ("/" = the whole site, or a path), to (http(s) address), code (301|302, default 301), match (exact|prefix, default exact; the whole site is always prefix), keep (prefix: add the rest of the address to the target), www (any|no|only)',
		      'mcp' => array('name' => 'redirect_add', 'args' => array(
		          'domain' => 'string: The site: the main domain or an addon domain',
		          'from?' => 'string: The address to redirect, as a path such as /old-page (default: / = the whole site)',
		          'to' => 'string: Where visitors go: a full address such as https://example.com/new-page',
		          'code?' => 'number: 301 permanent (default) or 302 temporary',
		          'match?' => 'string: exact (only that address, default) or prefix (it and everything under it)',
		          'keep?' => 'boolean: With prefix, add the rest of the address to the target (/old/a goes to <to>/a)',
		          'www?' => 'string: any (with or without www., default), no (only the domain without www.) or only (only www.<domain>, e.g. to send www to the bare domain)'),
		          'description' => 'Make a site of a hosting account send its visitors to another address: the whole site or one address (and optionally everything under it), permanently (301) or temporarily (302).')),
		array('method' => 'DELETE', 'path' => '/v1/redirects/{domain}', 'perm' => 'delete', 'handler' => 'api_v1_redirect_delete',
		      'summary' => 'Remove a redirect of a site', 'body' => 'from (the redirected address, "/" = the whole site), match (exact|prefix), host (the name it is limited to, "" = every name; left out = any)',
		      'mcp' => array('name' => 'redirect_delete', 'args' => array('domain' => 'string: The site', 'from' => 'string: The redirected address ("/" = the whole site)',
		                                                          'match?' => 'string: exact or prefix, when the site has both for that address',
		                                                          'host?' => 'string: The name the redirect is limited to (as redirects_list shows it; empty = every name)'))),
		/* the records of an account's zones at the DNS provider (app/functions/dns.php); NS and SOA are only read */
		array('method' => 'GET', 'path' => '/v1/dns', 'perm' => 'read', 'handler' => 'api_v1_dns',
		      'summary' => 'The zones of an account at the server\'s DNS provider, and its domains whose DNS is elsewhere (server tokens: ?account=)',
		      'mcp' => array('name' => 'dns_zones', 'args' => array('account?' => 'string: The hosting account (server tokens only)'),
		                     'description' => 'Which domains of a hosting account have their DNS zone at this server\'s DNS provider (so their records can be changed with dns_record_add / dns_record_edit / dns_record_delete), and which have their DNS elsewhere.')),
		array('method' => 'GET', 'path' => '/v1/dns/{zone}', 'perm' => 'read', 'handler' => 'api_v1_dns_zone',
		      'summary' => 'The records of a zone: name, type, TTL, value, whether it can be changed here; and its name servers',
		      'mcp' => array('name' => 'dns_records', 'untrusted' => true, 'args' => array('zone' => 'string: The zone, one of dns_zones'),
		                     'description' => 'The DNS records of one zone: for each its name (@ = the domain itself), type, TTL and value (MX "10 mail.example.com", SRV "10 5 5060 sip.example.com", CAA \'0 issue "letsencrypt.org"\'), and whether it can be changed here. Also the zone\'s name servers (never changed here).')),
		array('method' => 'POST', 'path' => '/v1/dns/{zone}/records', 'perm' => 'write', 'handler' => 'api_v1_dns_add',
		      'summary' => 'Add a record to a zone (A, AAAA, CNAME, MX, TXT, SRV, CAA)', 'body' => 'name (@ = the domain, or www, or www.example.com), type, value, ttl (seconds; default: the TTL of its record set, else 14400)',
		      'mcp' => array('name' => 'dns_record_add', 'args' => array(
		          'zone' => 'string: The zone, one of dns_zones',
		          'name' => 'string: @ for the domain itself, or the name in it, such as www or _dmarc',
		          'type' => 'string: A, AAAA, CNAME, MX, TXT, SRV or CAA',
		          'value' => 'string: An address (A, AAAA), a name (CNAME), "priority host" (MX), the text (TXT), "priority weight port host" (SRV) or \'flags tag "value"\' (CAA)',
		          'ttl?' => 'number: Seconds the answer may be kept: 300, 3600, 14400 or 86400 (default: the TTL its name and type have, else 14400)'),
		          'description' => 'Add a DNS record to one of a hosting account\'s zones. Records of the same name and type share their TTL. A CNAME must be alone at its name.')),
		array('method' => 'PATCH', 'path' => '/v1/dns/{zone}/records', 'perm' => 'write', 'handler' => 'api_v1_dns_edit',
		      'summary' => 'Change a record of a zone', 'body' => 'old: {name, type, value} (the record as it is), name, type, value, ttl (the record as it should be)',
		      'mcp' => array('name' => 'dns_record_edit', 'args' => array(
		          'zone' => 'string: The zone, one of dns_zones',
		          'old_name' => 'string: The record\'s name now, as dns_records shows it',
		          'old_type' => 'string: Its type now',
		          'old_value' => 'string: Its value now, as dns_records shows it',
		          'name?' => 'string: Its new name (default: the same)',
		          'type?' => 'string: Its new type (default: the same)',
		          'value' => 'string: Its new value',
		          'ttl?' => 'number: Its TTL in seconds (default: as it is)'),
		          'description' => 'Change one DNS record of a hosting account\'s zone, named by its name, type and value as dns_records shows them.')),
		array('method' => 'DELETE', 'path' => '/v1/dns/{zone}/records', 'perm' => 'delete', 'handler' => 'api_v1_dns_delete',
		      'summary' => 'Remove a record of a zone', 'body' => 'name, type, value (as GET /v1/dns/{zone} shows them)',
		      'mcp' => array('name' => 'dns_record_delete', 'args' => array('zone' => 'string: The zone', 'name' => 'string: The record\'s name, as dns_records shows it',
		                                                              'type' => 'string: Its type', 'value' => 'string: Its value, as dns_records shows it'))),
		/* cron jobs of an account (app/functions/cron.php). No MCP tool adds,
		   changes or runs one: a job is a command run as the account */
		array('method' => 'GET', 'path' => '/v1/cron', 'perm' => 'read', 'handler' => 'api_v1_cron',
		      'summary' => 'The cron jobs of an account: schedule, command, on/paused, last and next run (server tokens: ?account=)',
		      'mcp' => array('name' => 'cron_list', 'untrusted' => true, 'args' => array('account?' => 'string: The hosting account (server tokens only)'),
		                     'description' => 'The cron jobs of a hosting account: name, schedule (server time), command, whether it is on, how its last run ended and when it runs next. Jobs made outside Reqad have no run record.')),
		array('method' => 'POST', 'path' => '/v1/cron', 'perm' => 'write', 'handler' => 'api_v1_cron_add',
		      'summary' => 'Add a cron job', 'body' => 'schedule (5 fields or @daily ...), name, type (command|php|url, default command) with command, or file + php (version), or url; on (default true); account (server tokens)'),
		array('method' => 'PATCH', 'path' => '/v1/cron/{id}', 'perm' => 'write', 'handler' => 'api_v1_cron_edit',
		      'summary' => 'Change a cron job, or pause / turn it on', 'body' => 'any of: name, schedule, type + command / file + php / url, on'),
		array('method' => 'DELETE', 'path' => '/v1/cron/{id}', 'perm' => 'delete', 'handler' => 'api_v1_cron_delete',
		      'summary' => 'Remove a cron job',
		      'mcp' => array('name' => 'cron_delete', 'args' => array('id' => 'string: The job id (cron_list)', 'account?' => 'string: The hosting account (server tokens only)'))),
		array('method' => 'POST', 'path' => '/v1/cron/{id}/run', 'perm' => 'write', 'handler' => 'api_v1_cron_run',
		      'summary' => 'Run a cron job now (in the background, as the account)'),
		array('method' => 'GET', 'path' => '/v1/cron/{id}/runs', 'perm' => 'read', 'handler' => 'api_v1_cron_runs',
		      'summary' => 'The last 20 runs of a cron job: start, duration, exit code',
		      'mcp' => array('name' => 'cron_runs', 'args' => array('id' => 'string: The job id (cron_list)', 'account?' => 'string: The hosting account (server tokens only)'),
		                     'description' => 'The last runs of a cron job of a hosting account: when each started, how long it took and its exit code (ok, failed, skipped because the previous run was still going, running).')),
		array('method' => 'GET', 'path' => '/v1/cron/{id}/runs/{ms}', 'perm' => 'read', 'handler' => 'api_v1_cron_output',
		      'summary' => 'What a run of a cron job printed (first 64 KB)',
		      'mcp' => array('name' => 'cron_run_output', 'untrusted' => true, 'args' => array('id' => 'string: The job id', 'ms' => 'string: The run\'s start in milliseconds (cron_runs)', 'account?' => 'string: The hosting account (server tokens only)'),
		                     'description' => 'The output (stdout and stderr, first 64 KB) of one run of a cron job.')),
		/* SSH access of an account (app/functions/ssh.php): the keys are read and
		   written as the account. No MCP tools: a key is a way to log in. */
		array('method' => 'GET', 'path' => '/v1/ssh', 'perm' => 'read', 'handler' => 'api_v1_ssh',
		      'summary' => 'SSH access of an account: shell (full|jailed|none = SFTP only|other = no login), host, port, whether passwords are taken, and its SSH keys (server tokens: ?account=)'),
		array('method' => 'POST', 'path' => '/v1/ssh/keys', 'perm' => 'write', 'handler' => 'api_v1_ssh_key_add',
		      'summary' => 'Add an SSH key to an account', 'body' => 'key (the public key: an OpenSSH line, or the "BEGIN SSH2 PUBLIC KEY" form), name (optional; else the key\'s own comment); account (server tokens)'),
		array('method' => 'POST', 'path' => '/v1/ssh/keys/fix', 'perm' => 'write', 'handler' => 'api_v1_ssh_keys_fix',
		      'summary' => 'Set ~/.ssh to 0700 and authorized_keys to 0600 (sshd ignores keys others could change)'),
		array('method' => 'DELETE', 'path' => '/v1/ssh/keys/{id}', 'perm' => 'delete', 'handler' => 'api_v1_ssh_key_delete',
		      'summary' => 'Remove an SSH key (its id from GET /v1/ssh)'),
		/* an account's own backups (app/functions/backups.php): list, contents, restores in the background */
		array('method' => 'GET', 'path' => '/v1/backups', 'perm' => 'read', 'feature' => 'backup', 'handler' => 'api_v1_backups',
		      'summary' => 'The backups of an account: nightly (backup server) and local archives, newest first (server tokens: ?account=; ?refresh=1 asks the backup server again)',
		      'mcp' => array('name' => 'backups_list', 'args' => array('account?' => 'string: The hosting account (server tokens only)'),
		                     'description' => 'The backups of a hosting account, newest first: the nightly ones on the backup server and archives made on the server, with their date, size and whether that night\'s backup of the account succeeded.')),
		array('method' => 'GET', 'path' => '/v1/backups/{id}', 'perm' => 'read', 'feature' => 'backup', 'handler' => 'api_v1_backup',
		      'summary' => 'What one backup holds: parts (website, email, databases), databases, mailboxes, the home folder\'s top entries, what it left out'),
		array('method' => 'POST', 'path' => '/v1/backups/{id}/restore', 'perm' => 'write', 'feature' => 'backup', 'handler' => 'api_v1_backup_restore',
		      'summary' => 'Put files, a database or a mailbox back from a backup (in the background; GET /v1/backup-restores/{job})',
		      'body' => 'kind (files|database|email), target (files: a path in the home folder, "" = all of it; database: the backup\'s database; email: the mailbox), mode (files: missing|overwrite|replace; database: tables|replace|new; email: missing|replace), name + grant (database mode new); account (server tokens)'),
		array('method' => 'GET', 'path' => '/v1/backup-restores', 'perm' => 'read', 'feature' => 'backup', 'handler' => 'api_v1_backup_restores',
		      'summary' => 'The latest restores of an account from its backups (server tokens: ?account=)'),
		array('method' => 'GET', 'path' => '/v1/backup-restores/{job}', 'perm' => 'read', 'feature' => 'backup', 'handler' => 'api_v1_backup_restore_get',
		      'summary' => 'One restore: status (queued, running, ok, failed), what it said, where it kept what it replaced'),
		array('method' => 'GET', 'path' => '/v1/ssl', 'perm' => 'read', 'handler' => 'api_v1_ssl',
		      'summary' => 'The certificates of an account\'s sites: issuer, expiry, the names covered and missing (server tokens: ?account=; ?check=1 adds where each name points)',
		      'mcp' => array('name' => 'ssl_status', 'args' => array('account?' => 'string: The hosting account (server tokens only)', 'check?' => 'boolean: Also look up where each name points in DNS'),
		                     'description' => 'The certificates of a hosting account\'s sites: who issued each, when it expires, the names it covers and the names of the site it does not cover yet.')),
		array('method' => 'POST', 'path' => '/v1/ssl/{domain}', 'perm' => 'write', 'handler' => 'api_v1_ssl_issue',
		      'summary' => 'Get a free Let\'s Encrypt certificate for a site (its names that point here); it renews by itself',
		      'mcp' => array('name' => 'ssl_issue', 'args' => array('domain' => 'string: The site: the main domain or an addon domain'),
		                     'description' => 'Request a free Let\'s Encrypt certificate for one site of a hosting account, covering its names that already point to this server (the domain itself must). Takes up to a few minutes; renewals are automatic.')),
		array('method' => 'GET', 'path' => '/v1/spam', 'perm' => 'read', 'handler' => 'api_v1_spam',
		      'summary' => 'An account\'s spam settings: score, allow and block lists, spam to Junk (server tokens: ?account=)',
		      'mcp' => array('name' => 'spam_settings', 'args' => array('account?' => 'string: The hosting account (server tokens only; an account token gets its own)'),
		                     'description' => 'Spam settings of a hosting account: its spam score (the threshold; lower catches more), the senders never / always treated as spam, and whether spam goes to the Junk folder.')),
		array('method' => 'PATCH', 'path' => '/v1/spam', 'perm' => 'write', 'handler' => 'api_v1_spam_edit',
		      'summary' => 'Change an account\'s spam settings', 'body' => 'account (server tokens), score (1-20, or "default"), allow, block (the whole lists: name@example.com, *@example.com or example.com), junk (true|false)',
		      'mcp' => array('name' => 'spam_settings_edit', 'args' => array(
		          'account?' => 'string: The hosting account (server tokens only)',
		          'score?' => 'string: Spam score from 1 to 20 (5 = usual; lower = more mail counts as spam), or "default" for the server\'s',
		          'allow?' => 'string: The whole allow list, separated by commas: senders never treated as spam (name@example.com, *@example.com or example.com)',
		          'block?' => 'string: The whole block list, separated by commas: senders always treated as spam',
		          'junk?' => 'boolean: true = spam goes to the Junk folder of every mailbox instead of the inbox'))),

		array('method' => 'GET', 'path' => '/v1/mail-dns', 'perm' => 'read', 'handler' => 'api_v1_mail_dns',
		      'summary' => 'MX, SPF, DKIM and DMARC of the mail domains, asked at their name servers (?domain=)',
		      'mcp' => array('name' => 'mail_dns_check', 'args' => array('domain?' => 'string: One domain (left out = all your mail domains)'),
		                     'description' => 'Checks the DNS records that decide whether mail arrives and is trusted: MX, SPF, DKIM, DMARC. Says what is missing and what mail_dns_fix would add.')),
		array('method' => 'POST', 'path' => '/v1/mail-dns/{domain}/fix', 'perm' => 'write', 'handler' => 'api_v1_mail_dns_fix',
		      'summary' => 'Add the missing mail records of a domain at the server\'s DNS provider',
		      'mcp' => array('name' => 'mail_dns_fix', 'args' => array('domain' => 'string: The domain'),
		                     'description' => 'Adds the missing MX, SPF, DKIM and DMARC records of a domain at the server\'s DNS provider. Records that are there are left alone.')),

		/* who handles the mail of a site: this server or another provider (app/functions/email_tools.php: mail_provider_*) */
		array('method' => 'GET', 'path' => '/v1/mail-provider', 'perm' => 'read', 'handler' => 'api_v1_mail_provider',
		      'summary' => 'Who handles the mail of each site of an account: this server or another provider (from its MX), its mailboxes here, whether its zone is at the DNS provider (server tokens: ?account=)',
		      'mcp' => array('name' => 'mail_provider_status', 'args' => array('account?' => 'string: The hosting account (server tokens only)'),
		                     'description' => 'For each site of a hosting account: who handles its email (this server, Google Workspace, Microsoft 365, Zoho Mail or another provider, told from its MX records), whether this server is set to leave its mail to that provider, how many mailboxes it has here, and whether its DNS zone is at this server\'s DNS provider.')),
		array('method' => 'POST', 'path' => '/v1/mail-provider/{domain}', 'perm' => 'write', 'handler' => 'api_v1_mail_provider_set',
		      'summary' => 'Hand the mail of a site to another provider, or back to this server: MX, SPF and the provider\'s records (at the DNS provider when the zone is there), and this server\'s own delivery', 'body' => 'provider (local|google|microsoft|zoho|other), region (Zoho: zoho.com, zoho.eu, ...), mx (other: lines "priority host"; Microsoft: its own address), spf (other: the include), verify (a verification code for a TXT record), preview (true: only say what would change)',
		      'mcp' => array('name' => 'mail_provider_set', 'args' => array(
		          'domain' => 'string: The site (main domain, addon domain or subdomain)',
		          'provider' => 'string: local (this server), google, microsoft, zoho or other',
		          'region?' => 'string: Zoho only: zoho.com, zoho.eu, zoho.in, zoho.com.au, zoho.jp, zohocloud.ca, zoho.sa or zoho.uk',
		          'mx?' => 'string: other: the mail servers, "priority host" separated by commas; microsoft: its MX host when it is not <domain-with-dashes>.mail.protection.outlook.com',
		          'spf?' => 'string: other: the SPF include, such as spf.example.net',
		          'verify?' => 'string: A verification code the provider gave, added as a TXT record',
		          'preview?' => 'boolean: true = only show the records that would change'),
		          'description' => 'Hand the email of a site to Google Workspace, Microsoft 365, Zoho Mail or another provider, or back to this server: sets its MX and SPF (this server stays allowed to send for it) and the provider\'s extra records when its zone is at this server\'s DNS provider (otherwise returns the records to set), and makes this server send its mail to the provider. Mailboxes here are kept but get no new mail. Use preview first.')),

		array('method' => 'GET', 'path' => '/v1/mail-provider/{domain}/spf', 'perm' => 'read', 'handler' => 'api_v1_mail_spf',
		      'summary' => 'The SPF record of a site as choices: this server, its MX servers, the services that send for it, other terms, what happens to the rest'),
		array('method' => 'POST', 'path' => '/v1/mail-provider/{domain}/spf', 'perm' => 'write', 'handler' => 'api_v1_mail_spf_set',
		      'summary' => 'Write the SPF record of a site from choices (at the DNS provider when its zone is there, else the record to set is returned)', 'body' => 'server, mx (booleans), senders (ids: google, microsoft, zoho, ses, sendgrid, mailgun, brevo, mailjet, postmark, mandrill, sparkpost, zendesk), custom (terms such as ip4:192.0.2.1, include:spf.example.net), all (-all|~all|?all), preview',
		      'mcp' => array('name' => 'mail_spf_set', 'args' => array(
		          'domain' => 'string: The site',
		          'server?' => 'boolean: This server may send for it (default: yes; its websites send mail from here)',
		          'mx?' => 'boolean: Its MX servers may send for it',
		          'senders?' => 'string: Services that send for it, separated by commas: google, microsoft, zoho, ses, sendgrid, mailgun, brevo, mailjet, postmark, mandrill, sparkpost, zendesk',
		          'custom?' => 'string: Other SPF terms separated by spaces, e.g. ip4:192.0.2.1 include:spf.example.net',
		          'all?' => 'string: What receivers do with mail from anywhere else: ~all (mark it, default), -all (refuse it) or ?all (no opinion)',
		          'preview?' => 'boolean: true = only show the record'),
		          'description' => 'Write the SPF record of a site of a hosting account (one record; other TXT records stay). Refused over 10 DNS lookups. Use preview first.')),
		array('method' => 'GET', 'path' => '/v1/mail-provider/{domain}/dmarc', 'perm' => 'read', 'handler' => 'api_v1_mail_dmarc',
		      'summary' => 'The DMARC record of a site as choices: policy, subdomain policy, share of mail, report addresses, alignment'),
		array('method' => 'POST', 'path' => '/v1/mail-provider/{domain}/dmarc', 'perm' => 'write', 'handler' => 'api_v1_mail_dmarc_set',
		      'summary' => 'Write the DMARC record of a site from choices (other tags kept)', 'body' => 'p (none|quarantine|reject), sp ("" = as p), pct (1-100), rua, ruf (report addresses), adkim, aspf (r|s), preview',
		      'mcp' => array('name' => 'mail_dmarc_set', 'args' => array(
		          'domain' => 'string: The site',
		          'p' => 'string: What receivers do with mail that fails SPF and DKIM: none (only report), quarantine (spam folder) or reject (refuse)',
		          'sp?' => 'string: The same for subdomains (default: as p)',
		          'pct?' => 'number: The share of failing mail the policy applies to, 1-100 (default 100)',
		          'rua?' => 'string: Addresses for the daily reports, separated by commas',
		          'adkim?' => 'string: r (relaxed, default) or s (strict) DKIM alignment',
		          'aspf?' => 'string: r (relaxed, default) or s (strict) SPF alignment',
		          'preview?' => 'boolean: true = only show the record'),
		          'description' => 'Write the DMARC record (_dmarc.<domain>) of a site of a hosting account. Tags it does not set (fo, rf, ri, ...) are kept. Use preview first.')),

		array('method' => 'GET', 'path' => '/v1/mail-log', 'perm' => 'read', 'handler' => 'api_v1_mail_log',
		      'summary' => 'The delivery log: messages sent and received, and what happened to each recipient (?domain=&search=&limit=50)',
		      'mcp' => array('name' => 'mail_log', 'untrusted' => true, 'args' => array(
		          'domain?' => 'string: Only this domain (an account token: left out = all its mail domains)',
		          'search?' => 'string: Only messages whose sender, recipient or subject contains this',
		          'limit?' => 'integer: How many messages, newest first (default 50, at most 200)'),
		          'description' => 'Was a message sent or received? Messages in the mail server\'s log (about the last two weeks) with the sender, subject, spam score and, for each recipient, whether it was delivered, is waiting to be retried, bounced or was dropped; and the senders refused before their message was taken.')),

		array('method' => 'GET', 'path' => '/v1/mail-queue', 'perm' => 'read', 'admin' => true, 'handler' => 'api_v1_mail_queue',
		      'summary' => 'The mail queue: size, oldest messages, top senders and recipient domains (?filter=frozen&search=&limit=50)',
		      'mcp' => array('name' => 'mail_queue', 'untrusted' => true, 'args' => array(
		          'filter?' => 'enum(all,frozen): frozen = only messages exim gave up retrying',
		          'search?' => 'string: Only messages whose sender or recipient contains this',
		          'limit?' => 'integer: How many messages to list, oldest first (default 50, at most 200)'),
		          'description' => 'Messages waiting in the outgoing mail queue: total, frozen, the senders and recipient domains with the most messages, and the oldest messages (id, age, size, sender, recipients, subject).')),
		array('method' => 'POST', 'path' => '/v1/mail-queue/{id}/deliver', 'perm' => 'write', 'admin' => true, 'handler' => 'api_v1_mail_queue_deliver',
		      'summary' => 'Try to deliver a queued message now',
		      'mcp' => array('name' => 'mail_queue_deliver', 'untrusted' => true, 'args' => array('id' => 'string: Message id from mail_queue'),
		                     'description' => 'Try to deliver one queued message now. Returns what exim said.')),
		array('method' => 'DELETE', 'path' => '/v1/mail-queue/{id}', 'perm' => 'delete', 'admin' => true, 'handler' => 'api_v1_mail_queue_remove',
		      'summary' => 'Remove a message from the queue',
		      'mcp' => array('name' => 'mail_queue_remove', 'args' => array('id' => 'string: Message id from mail_queue'),
		                     'description' => 'Remove one message from the mail queue without delivering it. Cannot be undone.')),

		/* files of an account's home folder, done as the account itself (app/functions/files.php) */
		array('method' => 'GET', 'path' => '/v1/files', 'perm' => 'read', 'feature' => 'filemanager', 'handler' => 'api_v1_files',
		      'summary' => 'A folder of an account\'s home folder (?path= relative to it, "" = the home folder; server tokens: ?account=)',
		      'mcp' => array('name' => 'files_list', 'untrusted' => true, 'args' => array('path?' => 'string: The folder, relative to the home folder (left out = the home folder; public_html = the main site)', 'account?' => 'string: The hosting account (server tokens only; an account token works on its own files)'),
		                     'description' => 'Lists a folder in the hosting account\'s home folder: name, type (d folder, f file, l link), size, modification time (unix), permissions. Folders first.')),
		array('method' => 'GET', 'path' => '/v1/files/usage', 'perm' => 'read', 'feature' => 'filemanager', 'handler' => 'api_v1_files_usage',
		      'summary' => 'What takes up space in a folder: its entries biggest first, bytes on disk (?path=, "" = the home folder, which also gives quota_mb)',
		      'mcp' => array('name' => 'files_usage', 'untrusted' => true, 'args' => array('path?' => 'string: The folder, relative to the home folder (left out = the home folder)', 'account?' => 'string: The hosting account (server tokens only; an account token works on its own files)'),
		                     'description' => 'Disk usage of a folder in the hosting account\'s home folder: total and each file or folder in it, biggest first, in bytes on disk. The home folder also gives the disk space limit (quota_mb, 0 = none).')),
		array('method' => 'GET', 'path' => '/v1/files/content', 'perm' => 'read', 'feature' => 'filemanager', 'handler' => 'api_v1_files_read',
		      'summary' => 'A text file\'s content, up to 2 MB (?path=)',
		      'mcp' => array('name' => 'file_read', 'untrusted' => true, 'args' => array('path' => 'string: The file, relative to the home folder', 'account?' => 'string: The hosting account (server tokens only; an account token works on its own files)'),
		                     'description' => 'Reads a text file (at most 2 MB) from the hosting account\'s home folder. Gives its mtime too: pass it to file_write so a change made meanwhile is not overwritten.')),
		array('method' => 'POST', 'path' => '/v1/files/content', 'perm' => 'write', 'feature' => 'filemanager', 'handler' => 'api_v1_files_save',
		      'summary' => 'Save a text file', 'body' => 'path, content, mtime (refuse if the file changed since), create (true = a new file; refuse if it exists), force',
		      'mcp' => array('name' => 'file_write', 'args' => array('path' => 'string: The file, relative to the home folder', 'content' => 'string: The whole new content',
		                         'mtime?' => 'integer: The mtime file_read gave: refuses if the file changed since', 'create?' => 'boolean: true = make a new file (refuses if one exists)', 'account?' => 'string: The hosting account (server tokens only; an account token works on its own files)'),
		                     'description' => 'Writes a whole text file in the hosting account\'s home folder (replaces its content; keeps its permissions).')),
		array('method' => 'POST', 'path' => '/v1/files', 'perm' => 'write', 'feature' => 'filemanager', 'handler' => 'api_v1_files_create',
		      'summary' => 'Make a folder or an empty file', 'body' => 'path, type (folder|file)',
		      'mcp' => array('name' => 'file_create', 'args' => array('path' => 'string: The new folder or file, relative to the home folder', 'type' => 'enum(folder,file): What to make', 'account?' => 'string: The hosting account (server tokens only; an account token works on its own files)'))),
		array('method' => 'PATCH', 'path' => '/v1/files', 'perm' => 'write', 'feature' => 'filemanager', 'handler' => 'api_v1_files_edit',
		      'summary' => 'Rename or move a file or folder, and/or change its permissions', 'body' => 'path, to (the new path, or a folder to move it into), mode (three digits, like 644)',
		      'mcp' => array('name' => 'file_move', 'args' => array('path' => 'string: The file or folder, relative to the home folder', 'to?' => 'string: Its new path, or a folder to move it into',
		                         'mode?' => 'string: New permissions, three digits like 644 (files) or 755 (folders)', 'account?' => 'string: The hosting account (server tokens only; an account token works on its own files)'))),
		array('method' => 'POST', 'path' => '/v1/files/copy', 'perm' => 'write', 'feature' => 'filemanager', 'handler' => 'api_v1_files_copy',
		      'summary' => 'Copy a file or folder', 'body' => 'path, to (the new path, or a folder to copy it into; the same folder = "name copy")',
		      'mcp' => array('name' => 'file_copy', 'args' => array('path' => 'string: The file or folder, relative to the home folder', 'to' => 'string: The copy\'s path, or a folder to copy it into', 'account?' => 'string: The hosting account (server tokens only; an account token works on its own files)'))),
		array('method' => 'DELETE', 'path' => '/v1/files', 'perm' => 'delete', 'feature' => 'filemanager', 'handler' => 'api_v1_files_delete',
		      'summary' => 'Delete files or folders, with all they hold (?path=, or paths in the body)', 'body' => 'paths (a list)',
		      'mcp' => array('name' => 'file_delete', 'args' => array('path' => 'string: The file or folder, relative to the home folder', 'account?' => 'string: The hosting account (server tokens only; an account token works on its own files)'),
		                     'description' => 'Deletes a file, or a folder with everything in it, from the hosting account\'s home folder. Cannot be undone (only last night\'s backup has it).')),
		array('method' => 'POST', 'path' => '/v1/files/compress', 'perm' => 'write', 'feature' => 'filemanager_compression', 'handler' => 'api_v1_files_compress',
		      'summary' => 'Pack files of a folder into a .zip or .tar.gz there', 'body' => 'dir, items (names in dir), name, format (zip|tgz)',
		      'mcp' => array('name' => 'files_compress', 'args' => array('dir' => 'string: The folder, relative to the home folder', 'items' => 'string: Names in that folder, separated by commas',
		                         'name' => 'string: The archive\'s name (.zip or .tar.gz is added)', 'format?' => 'enum(zip,tgz): Default zip', 'account?' => 'string: The hosting account (server tokens only; an account token works on its own files)'))),
		array('method' => 'POST', 'path' => '/v1/files/extract', 'perm' => 'write', 'feature' => 'filemanager', 'handler' => 'api_v1_files_extract',
		      'summary' => 'Unpack a .zip, .tar, .tar.gz, .tar.bz2 or .tar.xz', 'body' => 'path, dest (a folder; made when missing), overwrite (replace files that are there)',
		      'mcp' => array('name' => 'files_extract', 'args' => array('path' => 'string: The archive, relative to the home folder', 'dest' => 'string: The folder to unpack into (made when missing)',
		                         'overwrite?' => 'boolean: true = replace files that are already there (default: keep them)', 'account?' => 'string: The hosting account (server tokens only; an account token works on its own files)'))),

		array('method' => 'GET', 'path' => '/v1/logs', 'perm' => 'read', 'handler' => 'api_v1_logs',
		      'summary' => 'The logs that can be read',
		      'mcp' => array('name' => 'logs_list', 'description' => 'The logs log_tail can read: web, PHP, mail, IMAP, firewall, SSH, cron, system.')),
		array('method' => 'GET', 'path' => '/v1/logs/{name}', 'perm' => 'read', 'handler' => 'api_v1_log',
		      'summary' => 'The last lines of a log (?domain= for site and php, ?lines=100, ?grep=text)',
		      'mcp' => array('name' => 'log_tail', 'untrusted' => true, 'args' => array(
		          'name' => 'string: Log name from logs_list (site, php, web-error, mail, mail-reject, imap, firewall, ssh, cron, system...)',
		          'domain?' => 'string: The domain, for the site and php logs',
		          'lines?' => 'integer: How many lines (default 100, at most 1000)',
		          'grep?' => 'string: Only lines containing this text (case-insensitive), from the last 20000 lines'),
		          'description' => 'The last lines of a server log, optionally only those containing some text. Use it to find out why mail bounces, a site errors or a login fails.')),
		array('method' => 'GET', 'path' => '/v1/logs/site/{domain}', 'perm' => 'read', 'handler' => 'api_v1_site_log',
		      'summary' => 'One day of a site\'s web server log ("main" = the account\'s main domain, with ?account= for a server token): its requests, newest first, 50 at a time, or its errors grouped by kind (?day=YYYY-MM-DD, ?tab=requests|errors, ?filter=all|err|5xx, ?bots=0, ?q=, ?ip=, ?offset=, ?grouped=0, ?after=<cursor> for the lines since an earlier answer)',
		      'mcp' => array('name' => 'site_log', 'untrusted' => true, 'args' => array(
		          'domain' => 'string: The site (main or addon domain)',
		          'day?' => 'string: YYYY-MM-DD, default today; the answer lists the days the server keeps',
		          'tab?' => 'enum(requests,errors): The requests (default), or the web server\'s errors grouped by kind',
		          'filter?' => 'enum(all,err,5xx): Requests only: all (default), errors (4xx and 5xx) or server errors (5xx)',
		          'bots?' => 'boolean: false = leave bots and scanners out',
		          'q?' => 'string: Only requests with this text in the address asked for, the IP, the browser or the referrer',
		          'ip?' => 'string: Only requests from this IP address',
		          'offset?' => 'integer: Skip the newest N matching requests (pages of 50)'),
		          'description' => 'One day of a site\'s web server log: the requests (time, IP and country, method and address, answer code, size, referrer, browser or bot) with counts, or the web server\'s errors for the site grouped by kind (permissions, PHP not answering, timeouts, blocked by a security rule...) with how often and the newest lines. Use it to find why a site answers 404, 403, 500, 502 or 504.')),

		array('method' => 'GET', 'path' => '/v1/stats', 'perm' => 'read', 'handler' => 'api_v1_stats',
		      'summary' => 'Bandwidth and visitors of every account and its sites in a month (?month=YYYY-MM, default this month)',
		      'mcp' => array('name' => 'stats_usage', 'args' => array('month?' => 'string: YYYY-MM, default this month'),
		          'description' => 'Bandwidth, visitors, page views, requests and server errors (5xx) of every hosting account and each of its sites in a month, busiest first, with the hosting package\'s bandwidth limit when there is one.')),
		array('method' => 'GET', 'path' => '/v1/stats/{site}', 'perm' => 'read', 'handler' => 'api_v1_stats_site',
		      'summary' => 'Visitor stats of a site, of all sites of an account (site "all") or of its main domain ("main"; both with ?account= for a server token): ?period=30d|12m|YYYY-MM',
		      'mcp' => array('name' => 'site_stats', 'untrusted' => true, 'args' => array(
		          'site' => 'string: The domain, "all" for every site of the account or "main" for its main domain',
		          'period?' => 'string: 30d (default), 12m or a month YYYY-MM',
		          'account?' => 'string: The account, with site "all" or "main" (server tokens only)'),
		          'description' => 'Visitors, page views, requests, bandwidth and errors of a site per day (or per month for 12m), compared with the period before, with its top pages, referring sites, missing pages (404), bots, countries, browsers and busiest hours. Counted every hour from the web server log.')),
		array('method' => 'PATCH', 'path' => '/v1/stats/settings', 'perm' => 'write', 'admin' => true, 'handler' => 'api_v1_stats_settings',
		      'summary' => 'Turn visitor stats or the monthly country database update on or off', 'body' => 'enabled (bool), geoip_update (bool)'),
		array('method' => 'POST', 'path' => '/v1/stats/geoip', 'perm' => 'write', 'admin' => true, 'handler' => 'api_v1_stats_geoip',
		      'summary' => 'Download this month\'s DB-IP Lite country database now'),

		array('method' => 'GET', 'path' => '/v1/resources', 'perm' => 'read', 'handler' => 'api_v1_resources',
		      'summary' => 'CPU, memory and processes of every account and of the whole server, busiest first (?range=day|week|month)',
		      'mcp' => array('name' => 'resource_usage', 'args' => array('range?' => 'enum(day,week,month): The last 24 hours (default), 7 days or 30 days'),
		          'description' => 'CPU (100% = one core), memory and processes of every hosting account over the last 24 hours, 7 days or 30 days, average and highest check, with each account\'s PHP workers per site at the most and how often it reached a limit; also the whole server. Checked every minute.')),
		array('method' => 'GET', 'path' => '/v1/resources/{account}', 'perm' => 'read', 'handler' => 'api_v1_resources_account',
		      'summary' => 'Resource usage of an account over time (?range=day|week|month; "me" = the token\'s account): CPU, memory and processes of its sessions, each site\'s PHP, mail and background jobs, PHP workers, database connections, the limits it reached, its limits',
		      'mcp' => array('name' => 'account_resources', 'untrusted' => true, 'args' => array(
		          'account' => 'string: The account ("me" with an account token)',
		          'range?' => 'enum(day,week,month): The last 24 hours (default), 7 days or 30 days'),
		          'description' => 'An account\'s CPU (100% = one core), memory and processes over time (5-minute points for a day, hourly for 7 days, 4-hourly for 30 days), split into SSH/cron/terminal sessions, each site\'s PHP, email logins and background jobs; PHP workers per site and connections per database user; the limits it reached (all PHP workers busy, a request stopped after its time limit, database connections at the limit, a process stopped by the memory limit, new processes refused, CPU held at its limit) and its limits. Use it to find why a site is slow or answers 502/504.')),
		array('method' => 'GET', 'path' => '/v1/resources/{account}/processes', 'perm' => 'read', 'handler' => 'api_v1_resources_processes',
		      'summary' => 'What the account runs now, grouped (sessions, each site\'s PHP, email, background jobs): CPU over the last minute, memory, how long'),
		array('method' => 'POST', 'path' => '/v1/resources/{account}/processes/{pid}/stop', 'perm' => 'write', 'handler' => 'api_v1_resources_stop',
		      'summary' => 'Stop a process of the account (TERM, then KILL after 5 seconds)', 'body' => 'start (the process\'s start time, from .../processes)'),

		array('method' => 'GET', 'path' => '/v1/health', 'perm' => 'read', 'handler' => 'api_v1_health',
		      'summary' => 'What needs attention (?account=<user> or - for the server, ?all=1 with hidden ones)',
		      'mcp' => array('name' => 'health_list', 'args' => array(
		          'account?' => 'string: Only this account\'s problems, or "-" for the server\'s own',
		          'all?' => 'boolean: Include the problems someone dismissed'),
		          'description' => 'What needs attention on the server and in the accounts: certificates, mail DNS (SPF, DKIM, DMARC), PHP versions, stopped services, disk space, backups, failed deploys... Each item has an id, severity, title and detail.')),
		array('method' => 'GET', 'path' => '/v1/health/checks', 'perm' => 'read', 'handler' => 'api_v1_health_checks',
		      'summary' => 'The checks that run',
		      'mcp' => array('name' => 'health_checks', 'description' => 'The health checks that run, every 30 minutes.')),
		array('method' => 'POST', 'path' => '/v1/health/run', 'perm' => 'write', 'admin' => true, 'handler' => 'api_v1_health_run',
		      'summary' => 'Run the checks now (takes a few seconds)',
		      'mcp' => array('name' => 'health_run', 'description' => 'Run the health checks now instead of waiting for the next run (takes a few seconds), e.g. after fixing something.')),
		array('method' => 'POST', 'path' => '/v1/health/dismiss', 'perm' => 'write', 'handler' => 'api_v1_health_dismiss',
		      'summary' => 'Hide a problem', 'body' => 'id, days (default 7) or forever (true)',
		      'mcp' => array('name' => 'health_dismiss', 'args' => array(
		          'id' => 'string: Item id from health_list',
		          'days?' => 'integer: Hide it for this many days (default 7)',
		          'forever?' => 'boolean: Hide it for good'))),
		array('method' => 'POST', 'path' => '/v1/health/restore', 'perm' => 'write', 'handler' => 'api_v1_health_restore',
		      'summary' => 'Show a hidden problem again', 'body' => 'id',
		      'mcp' => array('name' => 'health_restore', 'args' => array('id' => 'string: Item id from health_list'))),

		array('method' => 'GET', 'path' => '/v1/wordpress', 'perm' => 'read', 'feature' => 'wptoolkit', 'handler' => 'api_v1_wordpress',
		      'summary' => 'WordPress sites (?account=<user>), with the version the last check saw',
		      'mcp' => array('name' => 'wordpress_list', 'args' => array('account?' => 'string: Only this account\'s sites'),
		                     'description' => 'The WordPress sites on the server (WP Toolkit\'s list): account, domain, URL and the WordPress version the last check saw. wordpress_show reads the live version.')),
		array('method' => 'GET', 'path' => '/v1/wordpress/install', 'perm' => 'read', 'feature' => 'wptoolkit', 'handler' => 'api_v1_wordpress_install_options',
		      'summary' => 'What a new WordPress can be: the account\'s sites (and why one cannot take it), languages, the default administrator email (?account=<user>)'),
		array('method' => 'POST', 'path' => '/v1/wordpress', 'perm' => 'write', 'feature' => 'wptoolkit', 'handler' => 'api_v1_wordpress_install',
		      'summary' => 'Install WordPress in a site or a folder of it, with a new database (the folder must be empty but for the placeholder page)',
		      'body' => 'domain, path (folder, optional), title, admin_user, admin_password (empty = made up, given back once), admin_email, locale, account (with a server token)'),
		array('method' => 'POST', 'path' => '/v1/wordpress/scan', 'perm' => 'write', 'feature' => 'wptoolkit', 'handler' => 'api_v1_wordpress_scan',
		      'summary' => 'Look for an account\'s WordPress sites in all its sites\' folders and update the list', 'body' => 'account (with a server token)',
		      'mcp' => array('name' => 'wordpress_scan', 'args' => array('account' => 'string: The hosting account'),
		                     'description' => 'Look for WordPress installs in all folders of an account\'s sites (main domain, addon domains, subdomains, up to 3 folders down) and bring wordpress_list up to date: new installs are added, removed ones dropped.')),
		array('method' => 'POST', 'path' => '/v1/wordpress/{site}/login', 'perm' => 'write', 'feature' => 'wptoolkit', 'handler' => 'api_v1_wordpress_login',
		      'summary' => 'A one-time link (2 minutes) that signs in to the site\'s dashboard as its first administrator'),
		array('method' => 'GET', 'path' => '/v1/wordpress/{site}', 'perm' => 'read', 'feature' => 'wptoolkit', 'handler' => 'api_v1_wordpress_show',
		      'summary' => 'One site, read live: WordPress version, core / plugin / theme updates, plugins and themes (?refresh=1 asks wordpress.org again)',
		      'mcp' => array('name' => 'wordpress_show', 'untrusted' => true, 'args' => array(
		          'site' => 'string: Site id, domain or account name from wordpress_list',
		          'refresh?' => 'boolean: Ask wordpress.org for updates now instead of using WordPress\'s last check (a few seconds more)'),
		          'description' => 'One WordPress site read live: WordPress version and whether it is the latest, core updates, every plugin and theme with its version, active or not, and its available update.')),
		array('method' => 'GET', 'path' => '/v1/wordpress/{site}/details', 'perm' => 'read', 'feature' => 'wptoolkit', 'handler' => 'api_v1_wordpress_details',
		      'summary' => 'What the panel knows about one site: folder, database (name, size), copies, auto-update settings, the newest job and database backups'),
		array('method' => 'POST', 'path' => '/v1/wordpress/{site}/update', 'perm' => 'write', 'feature' => 'wptoolkit', 'handler' => 'api_v1_wordpress_update',
		      'summary' => 'Update core, plugins or themes, after a database backup to ~/wp-backups/',
		      'body' => 'what (core|plugins|themes), items (plugin / theme names; default: all with an update)',
		      'mcp' => array('name' => 'wordpress_update', 'untrusted' => true, 'args' => array(
		          'site' => 'string: Site id, domain or account name',
		          'what' => 'enum(core,plugins,themes): What to update',
		          'items?' => 'string: Plugin or theme names (folder names from wordpress_show), comma separated. Leave out to update all that have an update'),
		          'description' => 'Update WordPress core, plugins or themes on a site. The database is exported to ~/wp-backups/ of the account first (the 5 newest are kept). Tell the user what will be updated before calling.')),
		array('method' => 'POST', 'path' => '/v1/wordpress/{site}/jobs', 'perm' => 'write', 'feature' => 'wptoolkit', 'handler' => 'api_v1_wordpress_job_start',
		      'summary' => 'Update in the background (one at a time per site): the database is saved first, then core, plugins and themes in that order',
		      'body' => 'steps: [{"what": "core|plugins|themes", "items": [names; empty = all with an update]}, ...]'),
		array('method' => 'GET', 'path' => '/v1/wordpress/{site}/jobs/{job}', 'perm' => 'read', 'feature' => 'wptoolkit', 'handler' => 'api_v1_wordpress_job',
		      'summary' => 'A background update: status (queued, running, ok, warn = updated but the site does not start with its plugins, failed), message, backup file, wp-cli output'),
		array('method' => 'POST', 'path' => '/v1/wordpress/{site}/plugins/{plugin}/{op}', 'perm' => 'write', 'feature' => 'wptoolkit', 'handler' => 'api_v1_wordpress_plugin',
		      'summary' => 'activate or deactivate a plugin',
		      'mcp' => array('name' => 'wordpress_plugin', 'args' => array(
		          'site' => 'string: Site id, domain or account name',
		          'plugin' => 'string: Plugin folder name from wordpress_show, e.g. akismet',
		          'op' => 'enum(activate,deactivate): What to do'),
		          'description' => 'Activate or deactivate one plugin, e.g. to find which plugin breaks a site.')),
		array('method' => 'POST', 'path' => '/v1/wordpress/{site}/purge-cache', 'perm' => 'write', 'feature' => 'wptoolkit', 'handler' => 'api_v1_wordpress_purge',
		      'summary' => 'Empty the site\'s caches: nginx page cache, OPcache, object cache, cache plugins, Elementor CSS',
		      'mcp' => array('name' => 'wordpress_purge_cache', 'args' => array('site' => 'string: Site id, domain or account name'),
		                     'description' => 'Empty every cache of a WordPress site: the nginx page cache, OPcache (graceful PHP-FPM reload), the object cache (Redis / Memcached), cache plugins (WP Rocket, LiteSpeed, W3 Total Cache, WP Super Cache, WP Fastest Cache, Autoptimize, Cache Enabler, WP-Optimize, Breeze, SiteGround Optimizer) and Elementor\'s CSS. wordpress_update and wordpress_plugin already do this; use it when a site still shows old content.')),
		array('method' => 'GET', 'path' => '/v1/wordpress/{site}/verify', 'perm' => 'read', 'feature' => 'wptoolkit', 'handler' => 'api_v1_wordpress_verify',
		      'summary' => 'Compare core and plugin files with the wordpress.org checksums',
		      'mcp' => array('name' => 'wordpress_verify', 'untrusted' => true, 'args' => array('site' => 'string: Site id, domain or account name'),
		                     'description' => 'Check whether WordPress core and plugin files were changed or added, compared with the official wordpress.org checksums - a sign of a hacked site. Takes a few seconds.')),

		array('method' => 'GET', 'path' => '/v1/wordpress/{site}/tools', 'perm' => 'read', 'feature' => 'wptoolkit', 'handler' => 'api_v1_wordpress_tools',
		      'summary' => 'Performance switches (nginx_cache, wp_cron, indexing, maintenance) and Security measures of a site: on, off or na',
		      'mcp' => array('name' => 'wordpress_tools', 'args' => array('site' => 'string: Site id, domain or domain/folder'),
		                     'description' => 'The performance switches (page cache, WP-Cron off, search engine indexing, maintenance mode) and the security measures of a WordPress site, each on, off or na (not possible here, with the reason).')),
		array('method' => 'POST', 'path' => '/v1/wordpress/{site}/tools/{tool}', 'perm' => 'write', 'feature' => 'wptoolkit', 'handler' => 'api_v1_wordpress_tool_set',
		      'summary' => 'Turn a performance switch on or off: nginx_cache, wp_cron, indexing, maintenance', 'body' => 'on (true|false)',
		      'mcp' => array('name' => 'wordpress_tool', 'args' => array('site' => 'string: Site id, domain or domain/folder',
		          'tool' => 'enum(nginx_cache,wp_cron,indexing,maintenance): The switch', 'on' => 'boolean: On or off'),
		          'description' => 'Turn one switch of a WordPress site on or off: nginx_cache (the nginx page cache of the whole site), wp_cron (on = WordPress\'s cron off, the server runs due tasks every 2 minutes), indexing (search engines may index the site), maintenance (visitors see a "back soon" page, logged-in users the site).')),
		array('method' => 'POST', 'path' => '/v1/wordpress/{site}/cache-rules', 'perm' => 'write', 'feature' => 'wptoolkit', 'handler' => 'api_v1_wordpress_cache_rules',
		      'summary' => 'The site\'s own page cache rules (a list given replaces that list, one left out is kept; GET .../tools shows them as cache_rules)', 'body' => 'pages (list of paths never cached: /members/, /shop/*/reviews), cookies (list of cookie names that turn the cache off, name* = prefix)',
		      'mcp' => array('name' => 'wordpress_cache_rules', 'args' => array('site' => 'string: Site id, domain or domain/folder',
		          'pages?' => 'string: Paths that are never cached, separated by spaces (each starts with /, matches everything that starts so, * = any text); empty = none, left out = unchanged',
		          'cookies?' => 'string: Cookie names that turn the cache off for a visitor, separated by spaces (name* = every cookie starting so); empty = none, left out = unchanged'),
		          'description' => 'Set the page cache rules of a WordPress site (the whole site: every install on its domain): pages that are never cached and cookies that turn the cache off (for plugins that show each visitor something different: currency, language, membership). Logged-in users, carts, checkouts and the dashboard are always left out; links carrying only campaign tags (utm_*, gclid, fbclid...) use the cache. wordpress_tools shows the current rules.')),
		array('method' => 'GET', 'path' => '/v1/wordpress/{site}/auto', 'perm' => 'read', 'feature' => 'wptoolkit', 'handler' => 'api_v1_wordpress_auto',
		      'summary' => 'Auto-update settings of a site, the next and last run, what WordPress itself updates, its plugins and themes',
		      'mcp' => array('name' => 'wordpress_auto', 'args' => array('site' => 'string: Site id, domain or domain/folder'),
		                     'description' => 'The auto-update settings of a WordPress site (what the panel updates by itself and when, the wait after a release), its next and last run, and what WordPress itself updates on its own.')),
		array('method' => 'POST', 'path' => '/v1/wordpress/{site}/auto', 'perm' => 'write', 'feature' => 'wptoolkit', 'handler' => 'api_v1_wordpress_auto_set',
		      'summary' => 'Save the auto-update settings of a site',
		      'body' => 'core (off|minor|all), plugins / themes (off|all|some), plugin_list / theme_list (folder names), weekday (-1 every day, 0-6 Sunday-Saturday), hour (0-23, server time), delay (days, 0-30)',
		      'mcp' => array('name' => 'wordpress_auto_set', 'args' => array('site' => 'string: Site id, domain or domain/folder',
		          'core?' => 'enum(off,minor,all): WordPress itself: off, security and fix releases only (minor), or all releases',
		          'plugins?' => 'enum(off,all,some): Plugins', 'plugin_list?' => 'string: With some: plugin folder names, comma separated',
		          'themes?' => 'enum(off,all,some): Themes', 'theme_list?' => 'string: With some: theme folder names, comma separated',
		          'weekday?' => 'integer: -1 every day, 0 Sunday ... 6 Saturday', 'hour?' => 'integer: 0-23, the server\'s time',
		          'delay?' => 'integer: Days a plugin or theme release must be old before it is installed (0-30)'),
		          'description' => 'Choose what the panel updates by itself on a WordPress site and when. Each run saves the database first, leaves out updates that need a newer PHP, and puts plugins and themes back if the home page stops opening.')),
		array('method' => 'GET', 'path' => '/v1/wordpress/{site}/copies', 'perm' => 'read', 'feature' => 'wptoolkit', 'handler' => 'api_v1_wordpress_copies',
		      'summary' => 'Clone or staging: the copies of a site (or what it is a copy of), the name suggested for a new one, the newest copy job'),
		array('method' => 'POST', 'path' => '/v1/wordpress/{site}/copies', 'perm' => 'write', 'feature' => 'wptoolkit', 'handler' => 'api_v1_wordpress_copy',
		      'summary' => 'Copy a site to a new subdomain or domain of its account, in the background (follow it with GET .../jobs/{job})',
		      'body' => 'kind (staging: not indexed, no email, no scheduled tasks | clone: an independent site), domain (new), restrict (true: only allow_ips may open it), allow_ips'),
		array('method' => 'POST', 'path' => '/v1/wordpress/{site}/access', 'perm' => 'write', 'feature' => 'wptoolkit', 'handler' => 'api_v1_wordpress_copy_access',
		      'summary' => 'Who may open a copy: on (true: only allow_ips; others get a 503 page), allow_ips', 'body' => 'on, allow_ips'),
		array('method' => 'GET', 'path' => '/v1/wordpress/{site}/push', 'perm' => 'read', 'feature' => 'wptoolkit', 'handler' => 'api_v1_wordpress_push_preview',
		      'summary' => 'Push to live: what pushing a staging copy would change on its live site (files of WordPress, plugins, themes and translations; plugin and theme switches; chosen groups of settings; new uploads). The live database is never overwritten'),
		array('method' => 'POST', 'path' => '/v1/wordpress/{site}/push', 'perm' => 'write', 'feature' => 'wptoolkit', 'handler' => 'api_v1_wordpress_push',
		      'summary' => 'Push a staging copy\'s code to its live site in the background (a job of the LIVE site: follow it with GET /v1/wordpress/{live}/jobs/{job}). The live database and the replaced files are saved first; when the home page breaks, the files are put back',
		      'body' => 'items (keys from the preview: core, languages, plugin:<name>, theme:<name>, mu:<name>), switches (on:<plugin file>, off:<plugin file>, use:<theme>), settings (groups from the preview: front, permalinks, wc_setup...), uploads (true: add the uploads the live site lacks)'),
		array('method' => 'DELETE', 'path' => '/v1/wordpress/{site}', 'perm' => 'delete', 'feature' => 'wptoolkit', 'handler' => 'api_v1_wordpress_copy_delete',
		      'summary' => 'Delete a copy made in Clone or staging: its site, files and database (other sites are refused)'),
		array('method' => 'POST', 'path' => '/v1/wordpress/{site}/security', 'perm' => 'write', 'feature' => 'wptoolkit', 'handler' => 'api_v1_wordpress_security',
		      'summary' => 'Set the Security measures the site should have (all at once; unticking one-way ones changes nothing)',
		      'body' => 'on: [measure keys], login_slug (with hide_login), allow_ips (with admin_ip_allow), regen_keys (true: new security keys, signs everyone out)'),

		array('method' => 'GET', 'path' => '/v1/deployments', 'perm' => 'read', 'feature' => 'deployments', 'handler' => 'api_v1_deployments',
		      'summary' => 'Git deployments (?account=<user>)',
		      'mcp' => array('name' => 'deployments_list', 'args' => array('account?' => 'string: Only this account\'s deployments'),
		                     'description' => 'Sites deployed from Git: repository, branch, current release and the last deploy.')),
		array('method' => 'GET', 'path' => '/v1/deployments/{id}', 'perm' => 'read', 'feature' => 'deployments', 'handler' => 'api_v1_deployment_show',
		      'summary' => 'One deployment (id or domain) with its releases and latest runs',
		      'mcp' => array('name' => 'deployment_show', 'untrusted' => true, 'args' => array('id' => 'string: Deployment id or its domain'))),
		array('method' => 'POST', 'path' => '/v1/deployments/{id}/deploy', 'perm' => 'write', 'feature' => 'deployments', 'handler' => 'api_v1_deployment_deploy',
		      'summary' => 'Deploy the branch now (for CI); returns the run id',
		      'mcp' => array('name' => 'deployment_deploy', 'args' => array('id' => 'string: Deployment id or its domain'),
		                     'description' => 'Deploy the latest commit of the branch now. Returns the run id; follow it with deploy_run.')),
		array('method' => 'POST', 'path' => '/v1/deployments/{id}/rollback', 'perm' => 'write', 'feature' => 'deployments', 'handler' => 'api_v1_deployment_rollback',
		      'summary' => 'Go back to an earlier release', 'body' => 'release (default: the one before the current)',
		      'mcp' => array('name' => 'deployment_rollback', 'args' => array(
		          'id' => 'string: Deployment id or its domain',
		          'release?' => 'string: Release name from deployment_show (default: the one before the current)'))),
		/* setting deployments up (2087's Deployments page, the account panel): no
		   MCP tools - build commands are commands, and the answers hold secrets.
		   An account token: its own sites only, and a full shell (deploy_account_error()) */
		array('method' => 'POST', 'path' => '/v1/deployments', 'perm' => 'write', 'feature' => 'deployments', 'handler' => 'api_v1_deployment_create',
		      'summary' => 'Deploy a site (main or addon domain) from a Git branch: makes the deploy key and webhook secret; deploy it with /deploy once the repository gives the key access',
		      'body' => 'domain, repo_url (https://... or git@host:path), branch (default main), public_dir, build_cmd (lines), shared_paths (lines or array), keep_releases (2-20, default 5), health_path, auto_deploy (default true)'),
		array('method' => 'PATCH', 'path' => '/v1/deployments/{id}', 'perm' => 'write', 'feature' => 'deployments', 'handler' => 'api_v1_deployment_edit',
		      'summary' => 'Change a deployment (from the next deploy on)', 'body' => 'any of the fields of POST /v1/deployments but domain (repo_url "" = keep it), new_secret (true: a new webhook secret)'),
		array('method' => 'DELETE', 'path' => '/v1/deployments/{id}', 'perm' => 'delete', 'feature' => 'deployments', 'handler' => 'api_v1_deployment_delete',
		      'summary' => 'Stop deploying a site from Git: the site keeps running from its last release, the files stay'),
		array('method' => 'GET', 'path' => '/v1/deployments/{id}/connect', 'perm' => 'read', 'feature' => 'deployments', 'handler' => 'api_v1_deployment_connect',
		      'summary' => 'What the Git host needs: the deploy key (public part), the push webhook address and its secret'),
		array('method' => 'GET', 'path' => '/v1/deploy-runs/{run}', 'perm' => 'read', 'feature' => 'deployments', 'handler' => 'api_v1_deploy_run',
		      'summary' => 'A deploy run: status and output (?offset=<bytes> for the rest)',
		      'mcp' => array('name' => 'deploy_run', 'untrusted' => true, 'args' => array(
		          'run' => 'integer: Run id', 'offset?' => 'integer: Skip this many bytes of output (next_offset of the previous call)'),
		          'description' => 'A deploy run: its status and the build output.')),

		array('method' => 'GET', 'path' => '/v1/app-runtimes', 'perm' => 'read', 'feature' => 'deployments', 'handler' => 'api_v1_app_runtimes',
		      'summary' => 'The Node.js and Python versions installed for apps, and the most memory an app may get (account tokens)'),
		array('method' => 'POST', 'path' => '/v1/apps', 'perm' => 'write', 'feature' => 'deployments', 'handler' => 'api_v1_app_create',
		      'summary' => 'Run a site (main or addon domain) as a Node.js or Python app: its own service as the account, nginx passes the site to it (PHP stops there)',
		      'body' => 'domain, runtime (node|python), version (installed: GET /v1/app-runtimes), start_cmd (must listen on $PORT; may be empty for a Git deployment with no release yet: the first deploy finds it, GET /v1/apps/detect), app_dir (a folder of the home; default: the Git deployment, else apps/<domain>/app), serve_static (files that exist are served by nginx), memory_mb'),
		array('method' => 'PATCH', 'path' => '/v1/apps/{id}', 'perm' => 'write', 'feature' => 'deployments', 'handler' => 'api_v1_app_edit',
		      'summary' => 'Change an app (it restarts)', 'body' => 'any of the fields of POST /v1/apps but domain'),
		array('method' => 'DELETE', 'path' => '/v1/apps/{id}', 'perm' => 'delete', 'feature' => 'deployments', 'handler' => 'api_v1_app_delete',
		      'summary' => 'Stop and remove an app: the site serves its document root with PHP again, the files stay'),
		array('method' => 'GET', 'path' => '/v1/apps/{id}/env', 'perm' => 'read', 'feature' => 'deployments', 'handler' => 'api_v1_app_env',
		      'summary' => 'The app\'s environment file (KEY=value lines)'),
		array('method' => 'PATCH', 'path' => '/v1/apps/{id}/env', 'perm' => 'write', 'feature' => 'deployments', 'handler' => 'api_v1_app_env_save',
		      'summary' => 'Replace the app\'s environment file (it restarts)', 'body' => 'text (KEY=value lines, # comments)'),
		array('method' => 'GET', 'path' => '/v1/apps', 'perm' => 'read', 'feature' => 'deployments', 'handler' => 'api_v1_apps',
		      'summary' => 'Node.js / Python apps with their state (?account=<user>)',
		      'mcp' => array('name' => 'apps_list', 'args' => array('account?' => 'string: Only this account\'s apps'))),
		array('method' => 'GET', 'path' => '/v1/apps/detect', 'perm' => 'read', 'feature' => 'deployments', 'handler' => 'api_v1_app_detect',
		      'summary' => 'How the code of a site starts, read from its files as the account (Django, Flask, FastAPI, Next.js, package.json...): ?domain=, app_dir= (default: the app\'s folder), runtime=node|python (its commands first); found, start_cmd, candidates, runtime that fits, notes'),
		array('method' => 'GET', 'path' => '/v1/apps/{id}', 'perm' => 'read', 'feature' => 'deployments', 'handler' => 'api_v1_app_show',
		      'summary' => 'One app (id or domain): settings and state',
		      'mcp' => array('name' => 'app_show', 'args' => array('id' => 'string: App id or its domain'))),
		array('method' => 'POST', 'path' => '/v1/apps/{id}/install', 'perm' => 'write', 'feature' => 'deployments', 'handler' => 'api_v1_app_install',
		      'summary' => 'Install the app\'s packages as the account, in the background, then restart it (Python: its .venv + requirements.txt / pyproject.toml / setup.py; Node.js: npm ci or npm install, then npm run build); follow it with GET /v1/apps/{id}/install'),
		array('method' => 'GET', 'path' => '/v1/apps/{id}/install', 'perm' => 'read', 'feature' => 'deployments', 'handler' => 'api_v1_app_install_state',
		      'summary' => 'The last package install: status (running|ok|warn|failed), message, output from ?offset= and next_offset'),
		array('method' => 'POST', 'path' => '/v1/apps/{id}/{op}', 'perm' => 'write', 'feature' => 'deployments', 'handler' => 'api_v1_app_control',
		      'summary' => 'start, stop or restart an app',
		      'mcp' => array('name' => 'app_control', 'args' => array(
		          'id' => 'string: App id or its domain', 'op' => 'enum(start,stop,restart): What to do'),
		          'description' => 'Start, stop or restart a Node.js / Python app. A stopped app stays stopped after a reboot.')),
		array('method' => 'GET', 'path' => '/v1/apps/{id}/logs', 'perm' => 'read', 'feature' => 'deployments', 'handler' => 'api_v1_app_logs',
		      'summary' => 'The app\'s latest output (?lines=200)',
		      'mcp' => array('name' => 'app_logs', 'untrusted' => true, 'args' => array(
		          'id' => 'string: App id or its domain', 'lines?' => 'integer: How many lines (default 200)'))),
	);
	$extra = plugin_filter('api.routes', array());
	if (is_array($extra))
		foreach ($extra as $r)
			if (is_array($r) && isset($r['method'], $r['path'], $r['perm'], $r['handler']) && isset(api_scopes()[$r['perm']]))
				$routes[] = $r;
	return $routes;
}

/* ---- helpers ------------------------------------------------------------- */

function api_str(array $a, $key, $max = 255) {
	return isset($a[$key]) && is_scalar($a[$key]) ? substr(trim((string)$a[$key]), 0, $max) : '';
}

/* true / false from JSON booleans, 1/0, "true"/"false", "on"/"off"; null = not given or unreadable. */
function api_bool(array $a, $key) {
	if (!array_key_exists($key, $a))
		return null;
	$v = $a[$key];
	if (is_bool($v)) return $v;
	if (is_int($v)) return $v !== 0;
	$v = strtolower(trim((string)$v));
	if (in_array($v, array('1', 'true', 'yes', 'on'), true)) return true;
	if (in_array($v, array('0', 'false', 'no', 'off', ''), true)) return false;
	return null;
}

/* The body's password, or a generated one: array(password, generated?). */
function api_password(array $body) {
	$pw = isset($body['password']) && is_string($body['password']) ? $body['password'] : '';
	return $pw !== '' ? array($pw, false) : array(mailbox_random_password(20), true);
}

function api_with_password(array $r, $pw, $generated) {
	if ($generated && $r['ok'])
		$r['data']['password'] = $pw;
	return $r;
}

/* 403 when the token's account does not own the resource, else null. */
function api_scope(array $ctx, $type, $id) {
	$e = scope_check($ctx['scope'], $type, $id);
	return $e === '' ? null : api_error(403, $e);
}

function api_created(array $r) {
	return array($r['ok'] ? 201 : 400, $r);
}

/* ---- handlers ------------------------------------------------------------ */

function api_v1_index(array $ctx) {
	include _PATH.'/public_html/modules/version.php';
	$t = $ctx['token'];
	$eps = array();
	foreach (api_routes() as $r)
		if (empty($r['feature']) || feature_enabled($GLOBALS['ini'], $r['feature']))
		$eps[] = array('method' => $r['method'], 'path' => '/api'.$r['path'], 'permission' => $r['perm'],
		               'server_tokens_only' => !empty($r['admin']), 'summary' => $r['summary'] ?? '',
		               'body' => $r['body'] ?? '');
	return result_ok('', array(
		'server'  => array('hostname' => gethostname(), 'reqad' => isset($reqad_version) ? $reqad_version[0] : ''),
		'token'   => array('name' => $t['name'], 'account' => $t['account'] !== '' ? $t['account'] : null,
		                   'permissions' => api_token_scopes($t), 'expires' => (int)$t['expires'] ?: null),
		'endpoints' => $eps,
	));
}

/* accounts */

function api_v1_accounts(array $ctx) {
	global $db;
	$rows = account_list($db);
	if ($ctx['scope'] !== null)
		$rows = array_values(array_filter($rows, function ($r) use ($ctx) { return $r['user'] === $ctx['scope']; }));
	return result_ok('', $rows);
}

function api_v1_account_show(array $ctx) {
	global $db;
	$user = $ctx['params']['user'];
	if ($e = api_scope($ctx, 'account', $user)) return $e;
	$data = account_info($db, $user);
	return $data ? result_ok('', $data) : api_error(404, "Account $user not found.");
}

function api_v1_account_create(array $ctx) {
	global $db;
	$b = $ctx['body'];
	list($pw, $gen) = api_password($b);
	$shell = api_str($b, 'shell');
	if ($shell !== '' && !in_array($shell, array('full', 'jailed', 'none'), true))
		return api_error(400, 'shell must be full, jailed or none.');
	$r = account_create($db, array(
		'user' => api_str($b, 'user'), 'domain' => api_str($b, 'domain'), 'password' => $pw,
		'disk_quota' => (int)api_str($b, 'quota'),
		'dns' => (bool)api_bool($b, 'dns'), 'letsencrypt' => (bool)api_bool($b, 'letsencrypt'),
		'email' => (bool)api_bool($b, 'email'), 'www' => (bool)api_bool($b, 'www'),
		'shell' => $shell, 'php' => api_str($b, 'php'), 'package' => api_str($b, 'package'),
	));
	return api_created(api_with_password($r, $pw, $gen));
}

function api_v1_account_edit(array $ctx) {
	global $db;
	$user = $ctx['params']['user'];
	if ($e = api_scope($ctx, 'account', $user)) return $e;
	if (!account_get($db, $user))
		return api_error(404, "Account $user not found.");
	$b = $ctx['body'];
	$o = array('user' => $user);
	if (isset($b['password'])) {
		if (!is_string($b['password']) || $b['password'] === '')
			return api_error(400, 'password must be a non-empty string.');
		$o['password'] = $b['password'];
	}
	if (isset($b['php']))
		$o['php'] = api_str($b, 'php', 20);
	/* mail on/off and the shell type are the server admin's: an account token
	   could otherwise give itself the full shell its package does not have */
	foreach (array('email', 'shell') as $k)
		if (array_key_exists($k, $b) && $ctx['scope'] !== null)
			return api_error(403, "Only a token for the whole server can change $k.");
	if (array_key_exists('email', $b)) {
		$v = api_bool($b, 'email');
		if ($v === null) return api_error(400, 'email must be true or false.');
		$o['email'] = $v;
	}
	if (array_key_exists('shell', $b)) {
		$o['shell'] = api_str($b, 'shell');
		if (!in_array($o['shell'], array('full', 'jailed', 'none'), true))
			return api_error(400, 'shell must be full, jailed or none.');
	}
	if (count($o) === 1)
		return api_error(400, 'Nothing to change: send password, php, email or shell.');
	return account_edit($db, $o);
}

function api_v1_account_delete(array $ctx) {
	global $db;
	$user = $ctx['params']['user'];
	if (!account_get($db, $user))
		return api_error(404, "Account $user not found.");
	return account_delete($db, $user);
}

/* databases */

function api_v1_databases(array $ctx) {
	$acct = $ctx['scope'] !== null ? $ctx['scope'] : api_str($ctx['query'], 'account');
	return result_ok('', database_list($acct));
}

function api_v1_database_create(array $ctx) {
	$b = $ctx['body'];
	$acct = api_str($b, 'account');
	if ($acct === '' && $ctx['scope'] !== null)
		$acct = $ctx['scope'];
	if ($e = api_scope($ctx, 'account', $acct)) return $e;
	$name = api_str($b, 'name', 64);
	$grant = api_str($b, 'grant', 64);
	if ($grant !== '' && ($e = api_scope($ctx, 'dbuser', $grant))) return $e;
	if ($grant !== '' && !in_array($grant, array_column(database_account_users($acct), 'user'), true))
		return api_error(400, "Database user $grant does not belong to account $acct.");
	if (api_bool($b, 'user') === false)
		return api_created(database_create(array('account' => $acct, 'name' => $name, 'dbuser' => '', 'grant' => $grant)));
	list($pw, $gen) = api_password($b);
	$dbuser = api_str($b, 'dbuser', 64);
	$r = database_create(array('account' => $acct, 'name' => $name, 'dbuser' => $dbuser !== '' ? $dbuser : $name, 'password' => $pw, 'grant' => $grant));
	return api_created(api_with_password($r, $pw, $gen));
}

function api_v1_database_delete(array $ctx) {
	$name = $ctx['params']['name'];
	/* scope first: an account token learns nothing about other accounts' names */
	if ($e = api_scope($ctx, 'db', $name)) return $e;
	if (!in_array($name, array_column(database_list(), 'name'), true))
		return api_error(404, "Database $name does not exist.");
	return database_delete($name);
}

/* The dump is streamed by api_serve() ('stream'); other callers of the route
   (the account panel's broker) get only the JSON part. */
function api_v1_database_export(array $ctx) {
	$name = $ctx['params']['name'];
	/* scope first: an account token learns nothing about other accounts' names */
	if ($e = api_scope($ctx, 'db', $name)) return $e;
	if (!in_array($name, array_column(database_list($ctx['scope'] !== null ? $ctx['scope'] : ''), 'name'), true))
		return api_error(404, "Database $name does not exist.");
	$gzip = api_bool($ctx['query'], 'gzip') !== false;
	$file = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name).'-'.date('Ymd-His').($gzip ? '.sql.gz' : '.sql');
	return array(200, result_ok('', array('database' => $name, 'file' => $file, 'gzip' => $gzip)),
		'stream' => array('file' => $file, 'type' => $gzip ? 'application/gzip' : 'application/sql',
			'run' => function (callable $write) use ($name, $gzip) { return database_export($name, $write, $gzip); }));
}

/* database => the WordPress sites whose wp-config.php names it */
function api_v1_database_sites(array $ctx) {
	global $db;
	$acct = $ctx['scope'] !== null ? $ctx['scope'] : api_str($ctx['query'], 'account');
	if ($acct === '')
		return api_error(400, 'account is required.');
	if (!account_get($db, $acct))
		return api_error(404, "Account $acct not found.");
	return wordpress_databases($acct);
}

function api_v1_dbusers(array $ctx) {
	global $db;
	$acct = $ctx['scope'] !== null ? $ctx['scope'] : api_str($ctx['query'], 'account');
	if ($acct === '')
		return api_error(400, 'account is required.');
	if (!account_get($db, $acct))
		return api_error(404, "Account $acct not found.");
	return result_ok('', database_users($acct));
}

function api_v1_dbuser_create(array $ctx) {
	$b = $ctx['body'];
	$acct = api_str($b, 'account');
	if ($acct === '' && $ctx['scope'] !== null)
		$acct = $ctx['scope'];
	if ($e = api_scope($ctx, 'account', $acct)) return $e;
	list($pw, $gen) = api_password($b);
	return api_created(api_with_password(database_user_create($acct, api_str($b, 'name', 64), $pw), $pw, $gen));
}

/* the user's account: by its prefix or its grants (scope_owns 'dbuser') */
function api_dbuser_account($user) {
	$p = strpos($user, '_');
	$acct = $p ? substr($user, 0, $p) : '';
	if ($acct !== '' && in_array($user, array_column(database_account_users($acct), 'user'), true))
		return $acct;
	foreach (array_keys(database_account_names()) as $a)
		if (in_array($user, array_column(database_account_users($a), 'user'), true))
			return $a;
	return '';
}

/* a database user and a database of one account (scope checks first), null = fine */
function api_dbuser_and_db(array $ctx, $user, $database) {
	if ($e = api_scope($ctx, 'dbuser', $user)) return $e;
	if ($e = api_scope($ctx, 'db', $database)) return $e;
	/* both of one account, also with a whole-server token */
	$acct = $ctx['scope'] !== null ? $ctx['scope'] : api_dbuser_account($user);
	if ($acct === '')
		return api_error(404, "Database user $user does not exist or belongs to no account.");
	if (database_owner($database) !== $acct)
		return api_error(400, "Database $database does not belong to account $acct, like $user.");
	return null;
}

function api_v1_dbuser_access(array $ctx) {
	$user = $ctx['params']['user'];
	$database = api_str($ctx['body'], 'database', 64);
	if ($e = api_dbuser_and_db($ctx, $user, $database)) return $e;
	return database_grant($user, $database, strtolower(api_str($ctx['body'], 'access', 10)));
}

function api_v1_dbremote_status(array $ctx) {
	return result_ok('', database_remote_status());
}

function api_v1_dbremote_add(array $ctx) {
	$b = $ctx['body'];
	$user = api_str($b, 'user', 64);
	$database = api_str($b, 'database', 64);
	if ($e = api_dbuser_and_db($ctx, $user, $database)) return $e;
	return api_created(database_remote_add($user, $database, api_str($b, 'ip', 64)));
}

function api_v1_dbremote_remove(array $ctx) {
	$b = $ctx['body'];
	$user = api_str($b, 'user', 64);
	$database = api_str($b, 'database', 64);
	if ($e = api_dbuser_and_db($ctx, $user, $database)) return $e;
	return database_remote_remove($user, $database, api_str($b, 'ip', 64));
}

function api_v1_dbuser_delete(array $ctx) {
	$user = $ctx['params']['user'];
	if ($e = api_scope($ctx, 'dbuser', $user)) return $e;
	return database_user_delete($user);
}

function api_v1_dbuser_password(array $ctx) {
	$user = $ctx['params']['user'];
	if ($e = api_scope($ctx, 'dbuser', $user)) return $e;
	list($pw, $gen) = api_password($ctx['body']);
	return api_with_password(database_password($user, $pw), $pw, $gen);
}

/* mailboxes */

function api_email_off() {
	global $ini;
	return feature_enabled($ini, 'email') ? null : api_error(409, 'Email is turned off on this server.');
}

function api_v1_mailboxes(array $ctx) {
	global $db;
	if ($e = api_email_off()) return $e;
	$rows = email_list(api_str($ctx['query'], 'domain'));
	if ($ctx['scope'] !== null)
		$rows = array_values(array_filter($rows, function ($r) use ($ctx) { return scope_owns($ctx['scope'], 'mailbox', $r['email']); }));
	/* used_mb: the last measurement (the emails table, refreshed by
	   update_disk_usage), null = never measured; plugins add theirs (Hosting
	   Pro: size_mb, the mailbox's size) with the 'api.mailboxes' filter */
	$used = array();
	$res = $db instanceof SQLite3 ? @$db->query('SELECT email, disk_usage, updated_at FROM emails') : false;
	while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC)))
		$used[strtolower($r['email'])] = $r['updated_at'] !== null ? (float)$r['disk_usage'] : null;
	$measure = 10;    // never measured (a new mailbox): measure a few now, the cache keeps them
	foreach ($rows as &$r) {
		if (!array_key_exists($r['email'], $used) && $measure-- > 0 && $r['account'] !== '' && $db instanceof SQLite3) {
			list($u, $d) = email_split($r['email']);
			$used[$r['email']] = (float)mailbox_usage_measure($db, $r['email'], '/home/'.$r['account'].'/mail/'.$d.'/'.$u);
		}
		$r['used_mb'] = $used[$r['email']] ?? null;
	}
	unset($r);
	$more = plugin_filter('api.mailboxes', $rows, $ctx['scope']);
	return result_ok('', is_array($more) ? array_values($more) : $rows);
}

function api_v1_mailbox_create(array $ctx) {
	if ($e = api_email_off()) return $e;
	$email = strtolower(api_str($ctx['body'], 'email'));
	$parts = email_split($email);
	if ($parts[0] === '')
		return api_error(400, 'email must be an address like name@example.com.');
	if ($e = api_scope($ctx, 'domain', $parts[1])) return $e;
	list($pw, $gen) = api_password($ctx['body']);
	$o = array('email' => $email, 'password' => $pw);
	if (isset($ctx['body']['size']) && is_scalar($ctx['body']['size']))
		$o['size'] = (string)$ctx['body']['size'];
	return api_created(api_with_password(email_create($o), $pw, $gen));
}

function api_mailbox_find(array $ctx) {
	$email = strtolower($ctx['params']['email']);
	if ($e = api_scope($ctx, 'mailbox', $email))
		return array(null, $e);
	if (!in_array($email, mailbox_list(true), true))
		return array(null, api_error(404, "Mailbox $email does not exist."));
	return array($email, null);
}

function api_v1_mailbox_edit(array $ctx) {
	if ($e = api_email_off()) return $e;
	list($email, $e) = api_mailbox_find($ctx);
	if ($e) return $e;
	$b = $ctx['body'];
	$o = array('email' => $email);
	if (isset($b['password'])) {
		if (!is_string($b['password']) || $b['password'] === '')
			return api_error(400, 'password must be a non-empty string.');
		$o['password'] = $b['password'];
	}
	if (array_key_exists('enabled', $b)) {
		$v = api_bool($b, 'enabled');
		if ($v === null) return api_error(400, 'enabled must be true or false.');
		$o['enabled'] = $v;
	}
	if (isset($b['size'])) {
		if (!is_scalar($b['size']) || trim((string)$b['size']) === '')
			return api_error(400, 'size must be a size like 500M or 2G, or "default".');
		$o['size'] = (string)$b['size'];
	}
	if (count($o) === 1)
		return api_error(400, 'Nothing to change: send password, enabled or size.');
	return email_edit($o);
}

function api_v1_mailbox_delete(array $ctx) {
	global $db;
	if ($e = api_email_off()) return $e;
	list($email, $e) = api_mailbox_find($ctx);
	if ($e) return $e;
	return email_delete($db, $email);
}

function api_v1_mailbox_setup(array $ctx) {
	if ($e = api_email_off()) return $e;
	list($email, $e) = api_mailbox_find($ctx);
	if ($e) return $e;
	list(, $domain) = email_split($email);
	$host = mail_client_hostname($domain);
	$out = array('email' => $email, 'username' => $email, 'host' => $host,
		'incoming' => array(array('protocol' => 'IMAP', 'host' => $host, 'port' => 993, 'security' => 'SSL/TLS'),
		                    array('protocol' => 'POP3', 'host' => $host, 'port' => 995, 'security' => 'SSL/TLS')),
		'outgoing' => array(array('protocol' => 'SMTP', 'host' => $host, 'port' => 465, 'security' => 'SSL/TLS'),
		                    array('protocol' => 'SMTP', 'host' => $host, 'port' => 587, 'security' => 'STARTTLS')),
		'autoconfig' => is_file('/var/lib/reqad/autoconfig/'.$domain.'.xml'));
	if (api_bool($ctx['query'], 'apple'))
		$out['mobileconfig'] = mobileconfig_build($email);
	return result_ok('', $out);
}

/* forwarders */

/* Pipes run programs: only the server's own tokens may set them. */
function api_forwarder_pipe(array $ctx) {
	if (!array_key_exists('pipe', $ctx['body']))
		return array(null, null);
	if ($ctx['scope'] !== null)
		return array(null, api_error(403, 'Forwarding to a program is set by the server\'s administrator.'));
	if (!is_string($ctx['body']['pipe']))
		return array(null, api_error(400, 'pipe must be a string.'));
	return array($ctx['body']['pipe'], null);
}

function api_forwarder_to(array $b) {
	$to = $b['to'] ?? '';
	if (is_array($to))
		return array_values(array_filter($to, 'is_string'));
	return is_scalar($to) ? (string)$to : '';
}

/* account tokens see the pipes of their forwarders as a count only */
function api_forwarder_out(array $ctx, array $f) {
	if ($ctx['scope'] !== null) {
		$f['pipes'] = count($f['pipes']);
		$f['other'] = count($f['other']);
	}
	return $f;
}

function api_v1_forwarders(array $ctx) {
	if ($e = api_email_off()) return $e;
	$rows = forwarder_list(api_str($ctx['query'], 'domain'));
	if ($ctx['scope'] !== null)
		$rows = array_values(array_filter($rows, function ($r) use ($ctx) { return scope_owns($ctx['scope'], 'forwarder', $r['email']); }));
	return result_ok('', array_map(function ($f) use ($ctx) { return api_forwarder_out($ctx, $f); }, $rows));
}

function api_v1_forwarder_create(array $ctx) {
	if ($e = api_email_off()) return $e;
	$email = strtolower(api_str($ctx['body'], 'email'));
	$parts = email_split($email);
	if ($parts[0] === '')
		return api_error(400, 'email must be an address like name@example.com.');
	if ($e = api_scope($ctx, 'domain', $parts[1])) return $e;
	list($pipe, $e) = api_forwarder_pipe($ctx);
	if ($e) return $e;
	return api_created(forwarder_create(array('email' => $email, 'to' => api_forwarder_to($ctx['body']), 'pipe' => (string)$pipe)));
}

function api_forwarder_find(array $ctx) {
	$email = strtolower($ctx['params']['email']);
	if ($e = api_scope($ctx, 'forwarder', $email))
		return array(null, $e);
	if (!forwarder_get($email))
		return array(null, api_error(404, "There is no forwarder for $email."));
	return array($email, null);
}

function api_v1_forwarder_edit(array $ctx) {
	if ($e = api_email_off()) return $e;
	list($email, $e) = api_forwarder_find($ctx);
	if ($e) return $e;
	$b = $ctx['body'];
	if (!array_key_exists('to', $b) && !array_key_exists('pipe', $b) && !isset($b['new_email']))
		return api_error(400, 'Nothing to change: send to, new_email or pipe.');
	list($pipe, $e) = api_forwarder_pipe($ctx);
	if ($e) return $e;
	$new = strtolower(api_str($b, 'new_email'));
	if ($new !== '' && $new !== $email) {
		$np = email_split($new);
		if ($np[0] === '')
			return api_error(400, 'new_email must be an address like name@example.com.');
		if ($e = api_scope($ctx, 'domain', $np[1])) return $e;
	}
	$to = array_key_exists('to', $b) ? api_forwarder_to($b) : forwarder_get($email)['to'];
	return forwarder_edit(array('email' => $email, 'to' => $to, 'pipe' => $pipe, 'new_email' => $new));
}

function api_v1_forwarder_delete(array $ctx) {
	if ($e = api_email_off()) return $e;
	list($email, $e) = api_forwarder_find($ctx);
	if ($e) return $e;
	return forwarder_delete($email);
}

/* autoresponders */

function api_v1_autoresponders(array $ctx) {
	if ($e = api_email_off()) return $e;
	$rows = autoresponder_list(api_str($ctx['query'], 'domain'));
	if ($ctx['scope'] !== null)
		$rows = array_values(array_filter($rows, function ($r) use ($ctx) { return scope_owns($ctx['scope'], 'autoresponder', $r['email']); }));
	return result_ok('', $rows);
}

function api_autoresponder_fields(array $b) {
	$o = array();
	foreach (array('subject', 'message', 'date_from', 'date_to') as $k)
		if (array_key_exists($k, $b) && is_scalar($b[$k]))
			$o[$k] = (string)$b[$k];
	return $o;
}

function api_v1_autoresponder_create(array $ctx) {
	if ($e = api_email_off()) return $e;
	$email = strtolower(api_str($ctx['body'], 'email'));
	if (email_split($email)[0] === '')
		return api_error(400, 'email must be an address like name@example.com.');
	if ($e = api_scope($ctx, 'mailbox', $email)) return $e;
	return api_created(autoresponder_create(array('email' => $email) + api_autoresponder_fields($ctx['body'])));
}

function api_autoresponder_find(array $ctx) {
	$email = strtolower($ctx['params']['email']);
	if ($e = api_scope($ctx, 'autoresponder', $email))
		return array(null, $e);
	if (!autoresponder_get($email))
		return array(null, api_error(404, "$email has no autoresponder."));
	return array($email, null);
}

function api_v1_autoresponder_edit(array $ctx) {
	if ($e = api_email_off()) return $e;
	list($email, $e) = api_autoresponder_find($ctx);
	if ($e) return $e;
	$o = api_autoresponder_fields($ctx['body']);
	if (!$o)
		return api_error(400, 'Nothing to change: send subject, message, date_from or date_to.');
	return autoresponder_edit(array('email' => $email) + $o);
}

function api_v1_autoresponder_delete(array $ctx) {
	if ($e = api_email_off()) return $e;
	list($email, $e) = api_autoresponder_find($ctx);
	if ($e) return $e;
	return autoresponder_delete($email);
}

/* mailbox filters */

/* conditions / actions: a list, or the same as a JSON string (MCP) */
function api_filter_body(array $b) {
	foreach (array('conditions', 'actions') as $k)
		if (isset($b[$k]) && is_string($b[$k])) {
			$v = json_decode($b[$k], true);
			$b[$k] = is_array($v) ? $v : 'invalid';
		}
	foreach (array('enabled', 'stop') as $k)
		if (array_key_exists($k, $b) && api_bool($b, $k) !== null)
			$b[$k] = api_bool($b, $k);
	return $b;
}

function api_v1_mailbox_filters(array $ctx) {
	if ($e = api_email_off()) return $e;
	list($email, $e) = api_mailbox_find($ctx);
	if ($e) return $e;
	return email_filter_list($email, api_bool($ctx['query'], 'folders') !== false);
}

function api_v1_mailbox_filter_create(array $ctx) {
	if ($e = api_email_off()) return $e;
	list($email, $e) = api_mailbox_find($ctx);
	if ($e) return $e;
	$b = api_filter_body($ctx['body']);
	foreach (array('conditions', 'actions') as $k)
		if (isset($b[$k]) && !is_array($b[$k]))
			return api_error(400, "$k must be a list (or a JSON list).");
	return api_created(email_filter_create($email, $b));
}

function api_v1_mailbox_filter_edit(array $ctx) {
	if ($e = api_email_off()) return $e;
	list($email, $e) = api_mailbox_find($ctx);
	if ($e) return $e;
	$b = api_filter_body($ctx['body']);
	foreach (array('conditions', 'actions') as $k)
		if (isset($b[$k]) && !is_array($b[$k]))
			return api_error(400, "$k must be a list (or a JSON list).");
	if (!array_intersect(array_keys($b), array('name', 'conditions', 'actions', 'match', 'enabled', 'stop', 'position')))
		return api_error(400, 'Nothing to change: send name, conditions, actions, match, enabled, stop or position.');
	if (!ctype_digit((string)$ctx['params']['n']))
		return api_error(404, 'A filter is named by its number.');
	return email_filter_edit($email, (int)$ctx['params']['n'], $b);
}

function api_v1_mailbox_filter_delete(array $ctx) {
	if ($e = api_email_off()) return $e;
	list($email, $e) = api_mailbox_find($ctx);
	if ($e) return $e;
	if (!ctype_digit((string)$ctx['params']['n']))
		return api_error(404, 'A filter is named by its number.');
	$x = $ctx['query']['expect_name'] ?? ($ctx['body']['expect_name'] ?? null);
	return email_filter_delete($email, (int)$ctx['params']['n'], is_scalar($x) ? (string)$x : null);
}

/* domain filters */

function api_v1_domain_filters(array $ctx) {
	if ($e = api_email_off()) return $e;
	$d = strtolower(api_str($ctx['query'], 'domain'));
	if ($d === '')
		return api_error(400, 'Say which domain: ?domain=<domain>.');
	if ($e = api_scope($ctx, 'domain', $d)) return $e;
	return email_domain_filter_list($d, api_bool($ctx['query'], 'folders') !== false);
}

function api_domain_filter_body(array $ctx) {
	$b = api_filter_body($ctx['body']);
	foreach (array('conditions', 'actions') as $k)
		if (isset($b[$k]) && !is_array($b[$k]))
			return array(null, api_error(400, "$k must be a list (or a JSON list)."));
	return array($b, null);
}

function api_v1_domain_filter_create(array $ctx) {
	if ($e = api_email_off()) return $e;
	$d = strtolower(api_str($ctx['body'], 'domain'));
	if ($d === '')
		return api_error(400, 'Say which domain: domain=<domain>.');
	if ($e = api_scope($ctx, 'domain', $d)) return $e;
	list($b, $e) = api_domain_filter_body($ctx);
	if ($e) return $e;
	/* programs: the server's tokens only */
	return api_created(email_domain_filter_create($d, $b, $ctx['scope'] === null));
}

function api_domain_filter_find(array $ctx) {
	$id = (string)$ctx['params']['id'];
	if (!ctype_digit($id))
		return array(null, api_error(404, 'A domain filter is named by its id.'));
	if ($e = api_scope($ctx, 'email_filter', $id))
		return array(null, $e);
	if (!email_domain_filter_get($id))
		return array(null, api_error(404, "There is no domain filter $id."));
	return array((int)$id, null);
}

function api_v1_domain_filter_edit(array $ctx) {
	if ($e = api_email_off()) return $e;
	list($id, $e) = api_domain_filter_find($ctx);
	if ($e) return $e;
	list($b, $e) = api_domain_filter_body($ctx);
	if ($e) return $e;
	if (!array_intersect(array_keys($b), array('name', 'conditions', 'actions', 'match', 'enabled', 'stop', 'position')))
		return api_error(400, 'Nothing to change: send name, conditions, actions, match, enabled, stop or position.');
	return email_domain_filter_edit($id, $b, $ctx['scope'] === null);
}

function api_v1_domain_filter_delete(array $ctx) {
	if ($e = api_email_off()) return $e;
	list($id, $e) = api_domain_filter_find($ctx);
	if ($e) return $e;
	$x = $ctx['query']['expect_name'] ?? ($ctx['body']['expect_name'] ?? null);
	return email_domain_filter_delete($id, is_scalar($x) ? (string)$x : null);
}

/* spam settings */

/* The account the call is about: the token's own, or ?account= / account for server tokens. */
function api_spam_account(array $ctx, array $a) {
	if ($ctx['scope'] !== null)
		return array($ctx['scope'], null);
	$acct = api_str($a, 'account', 32);
	if ($acct === '')
		return array(null, api_error(400, 'Say which account: account=<user>.'));
	return array($acct, null);
}

function api_v1_php(array $ctx) {
	global $db;
	list($acct, $e) = api_spam_account($ctx, $ctx['query']);
	if ($e) return $e;
	if (!account_get($db, $acct))
		return api_error(404, "Account $acct not found.");
	$out = array('sites' => array_map(function ($s) { unset($s['pool']); return $s; }, php_sites($acct)), 'versions' => php_versions(),
	             'php_mode' => php_mode(), 'specs' => array());
	foreach (php_setting_specs() as $k => $sp)
		$out['specs'][$k] = array('label' => $sp[0], 'help' => $sp[1], 'values' => $sp[2] ?: (object)array());
	$domain = strtolower(api_str($ctx['query'], 'domain', 253));
	/* ?settings=1 without a domain: the main domain's */
	if ($domain === '' && api_bool($ctx['query'], 'settings') && $out['sites'])
		$domain = $out['sites'][0]['domain'];
	if ($domain !== '') {
		$r = php_settings_get($acct, $domain);
		if (!$r['ok'])
			return api_error(400, $r['error']);
		$out['site'] = $r['data'];
	}
	return result_ok('', $out);
}

function api_v1_php_edit(array $ctx) {
	$domain = strtolower($ctx['params']['domain']);
	if ($e = api_scope($ctx, 'domain', $domain)) return $e;
	$acct = $ctx['scope'] !== null ? $ctx['scope'] : scope_domain_owner($domain);
	if ($acct === '' || !php_site($acct, $domain))
		return api_error(404, "$domain is not the main or an addon domain of an account.");
	$b = $ctx['body'];
	$set = $b['settings'] ?? null;
	if (is_string($set) && $set !== '')
		$set = json_decode($set, true);
	if ($set !== null && !is_array($set))
		return api_error(400, 'settings must be an object: {"memory_limit": "512M", ...}.');
	$version = api_str($b, 'version', 16);
	if ($version === '' && !$set)
		return api_error(400, 'Say what to change: version and/or settings.');
	$msgs = array();
	if ($version !== '') {
		$r = php_version_set($acct, $domain, $version);
		if (!$r['ok'])
			return $r;
		$msgs[] = $r['message'];
	}
	if ($set) {
		$r = php_settings_set($acct, $domain, $set);
		if (!$r['ok'])
			return $r;
		$msgs[] = $r['message'];
	}
	return result_ok(implode(' ', $msgs), array('domain' => $domain));
}

/* sites, aliases, certificates (app/functions/domains.php) */

function api_v1_domains(array $ctx) {
	global $db;
	list($acct, $e) = api_spam_account($ctx, $ctx['query']);
	if ($e) return $e;
	if (!account_get($db, $acct))
		return api_error(404, "Account $acct not found.");
	$sites = domain_sites($acct);
	$c = $sites[0]['cert'] ?? null;
	$have = $c && !$c['self_signed'] && !$c['expired'] ? $c['names'] : array();
	$aliases = domain_aliases($acct);
	foreach ($aliases as $i => $a)
		$aliases[$i]['covered'] = domain_cert_covers($have, $a['alias']);
	if (api_bool($ctx['query'], 'check')) {
		$names = array_column($sites, 'domain');
		foreach ($aliases as $a)
			if (!$a['wildcard'])
				$names[] = $a['alias'];
		$p = domain_points($names, $acct);
		$none = array('ips' => array(), 'here' => false);
		foreach ($sites as $i => $s)
			$sites[$i]['points'] = $p[$s['domain']] ?? $none;
		foreach ($aliases as $i => $a)
			if (!$a['wildcard'])
				$aliases[$i]['points'] = $p[$a['alias']] ?? $none;
	}
	return result_ok('', array('sites' => $sites, 'aliases' => $aliases, 'ip' => domain_site_ip($acct),
	                           'dns_provider' => dns_provider_load($db) !== 'none', 'mail' => domain_mail_available($acct) === ''));
}

function api_v1_domain_add(array $ctx) {
	list($acct, $e) = api_spam_account($ctx, $ctx['body']);
	if ($e) return $e;
	if ($e = api_scope($ctx, 'account', $acct)) return $e;
	$b = $ctx['body'];
	return api_created(domain_addon_add($acct, api_str($b, 'domain', 253), api_str($b, 'folder', 300), api_bool($b, 'www'), api_bool($b, 'mail')));
}

function api_v1_domain_delete(array $ctx) {
	$domain = strtolower($ctx['params']['domain']);
	if ($e = api_scope($ctx, 'addon', $domain)) return $e;
	$a = addon_domain_get($GLOBALS['ini'], $domain);
	if (!$a)
		return api_error(404, "$domain is not an addon domain.");
	return domain_addon_delete($a['user'], $domain, (bool)(api_bool($ctx['body'], 'files') ?? api_bool($ctx['query'], 'files')));
}

function api_v1_domain_mail(array $ctx) {
	$domain = strtolower($ctx['params']['domain']);
	if ($e = api_scope($ctx, 'addon', $domain)) return $e;
	$a = addon_domain_get($GLOBALS['ini'], $domain);
	if (!$a)
		return api_error(404, "$domain is not an addon domain.");
	return domain_mail_enable($a['user'], $domain);
}

function api_v1_domain_mail_off(array $ctx) {
	$domain = strtolower($ctx['params']['domain']);
	if ($e = api_scope($ctx, 'addon', $domain)) return $e;
	$a = addon_domain_get($GLOBALS['ini'], $domain);
	if (!$a)
		return api_error(404, "$domain is not an addon domain.");
	return domain_mail_disable($a['user'], $domain);
}

function api_v1_alias_add(array $ctx) {
	list($acct, $e) = api_spam_account($ctx, $ctx['body']);
	if ($e) return $e;
	if ($e = api_scope($ctx, 'account', $acct)) return $e;
	$dns = api_bool($ctx['body'], 'dns');
	return api_created(domain_alias_add($acct, api_str($ctx['body'], 'alias', 253), $dns === null ? true : $dns));
}

function api_v1_alias_delete(array $ctx) {
	$alias = strtolower($ctx['params']['alias']);
	if ($e = api_scope($ctx, 'alias', $alias)) return $e;
	$owner = scope_domain_owner(substr($alias, 0, 2) === '*.' ? substr($alias, 2) : $alias);
	if ($ctx['scope'] !== null)
		$owner = $ctx['scope'];
	return domain_alias_delete($owner, $alias);
}

function api_v1_ssl(array $ctx) {
	global $db;
	list($acct, $e) = api_spam_account($ctx, $ctx['query']);
	if ($e) return $e;
	if (!account_get($db, $acct))
		return api_error(404, "Account $acct not found.");
	return result_ok('', array('sites' => ssl_sites($acct, (bool)api_bool($ctx['query'], 'check')), 'ip' => domain_site_ip($acct)));
}

function api_v1_redirects(array $ctx) {
	global $db;
	list($acct, $e) = api_spam_account($ctx, $ctx['query']);
	if ($e) return $e;
	if (!account_get($db, $acct))
		return api_error(404, "Account $acct not found.");
	return result_ok('', redirect_list($acct));
}

/* the site's account, after the scope check (a server token may name any site) */
function api_redirect_site(array $ctx, $domain) {
	$domain = strtolower(trim((string)$domain));
	if (!valid_domain($domain))
		return array('', api_error(400, 'domain is required.'));
	if ($e = api_scope($ctx, 'domain', $domain)) return array('', $e);
	$acct = $ctx['scope'] !== null ? $ctx['scope'] : scope_domain_owner($domain);
	if ($acct === '' || !domain_site($acct, $domain))
		return array('', api_error(404, "$domain is not the main or an addon domain of an account."));
	return array($acct, null);
}

function api_v1_redirect_add(array $ctx) {
	$b = $ctx['body'];
	list($acct, $e) = api_redirect_site($ctx, api_str($b, 'domain', 253));
	if ($e) return $e;
	return api_created(redirect_add($acct, array('domain' => api_str($b, 'domain', 253), 'from' => api_str($b, 'from', 200), 'to' => api_str($b, 'to', 500),
	                                             'code' => (int)(api_str($b, 'code', 3) ?: 301), 'match' => api_str($b, 'match', 10), 'keep' => (bool)api_bool($b, 'keep'),
	                                             'www' => api_str($b, 'www', 5) ?: 'any')));
}

function api_v1_redirect_delete(array $ctx) {
	$domain = strtolower($ctx['params']['domain']);
	list($acct, $e) = api_redirect_site($ctx, $domain);
	if ($e) return $e;
	$from = api_str($ctx['body'], 'from', 200);
	if ($from === '')
		$from = api_str($ctx['query'], 'from', 200);
	$match = api_str($ctx['body'], 'match', 10) ?: api_str($ctx['query'], 'match', 10);
	$host = array_key_exists('host', $ctx['body']) ? api_str($ctx['body'], 'host', 253) : (array_key_exists('host', $ctx['query']) ? api_str($ctx['query'], 'host', 253) : null);
	return redirect_delete($acct, $domain, $from === '' ? '/' : $from, in_array($match, array('exact', 'prefix'), true) ? $match : '', $host);
}

/* DNS zones (app/functions/dns.php) */

function api_v1_dns(array $ctx) {
	global $db;
	list($acct, $e) = api_spam_account($ctx, $ctx['query']);
	if ($e) return $e;
	if (!account_get($db, $acct))
		return api_error(404, "Account $acct not found.");
	$z = dns_account_zones($acct);
	if (!is_array($z))
		return result_ok('', array('managed' => false, 'why' => $z, 'zones' => array(), 'elsewhere' => array()));
	return result_ok('', array('managed' => true, 'why' => '') + $z);
}

/* The zone after the scope check, and the account whose names in it may change ('' = a server token). */
function api_dns_zone(array $ctx) {
	$zone = strtolower(trim((string)$ctx['params']['zone']));
	if (!valid_domain($zone))
		return array('', '', api_error(400, 'zone is required.'));
	if ($e = api_scope($ctx, 'domain', $zone)) return array('', '', $e);
	if (!dns_managed())
		return array('', '', api_error(404, 'No DNS provider is set up on this server.'));
	return array($zone, $ctx['scope'] !== null ? $ctx['scope'] : '', null);
}

function api_dns_record(array $a, $prefix = '') {
	return array('name' => api_str($a, $prefix.'name', 253), 'type' => api_str($a, $prefix.'type', 10),
	             'value' => api_str($a, $prefix.'value', 4100), 'ttl' => (int)api_str($a, $prefix.'ttl', 7));
}

function api_v1_dns_zone(array $ctx) {
	list($zone, $acct, $e) = api_dns_zone($ctx);
	if ($e) return $e;
	$r = dns_zone_view($acct, $zone);
	return $r['ok'] ? $r : api_error(404, $r['error']);
}

function api_v1_dns_add(array $ctx) {
	list($zone, $acct, $e) = api_dns_zone($ctx);
	if ($e) return $e;
	return api_created(dns_record_add($acct, $zone, api_dns_record($ctx['body'])));
}

function api_v1_dns_edit(array $ctx) {
	list($zone, $acct, $e) = api_dns_zone($ctx);
	if ($e) return $e;
	$b = $ctx['body'];
	/* old: {name, type, value}, or old_name / old_type / old_value (MCP) */
	$old = isset($b['old']) && is_array($b['old']) ? api_dns_record($b['old']) : api_dns_record($b, 'old_');
	$new = api_dns_record($b);
	if (!array_key_exists('name', $b)) $new['name'] = $old['name'];
	if ($new['type'] === '') $new['type'] = $old['type'];
	return dns_record_edit($acct, $zone, $old, $new);
}

function api_v1_dns_delete(array $ctx) {
	list($zone, $acct, $e) = api_dns_zone($ctx);
	if ($e) return $e;
	return dns_record_delete($acct, $zone, api_dns_record($ctx['body'] + $ctx['query']));
}

/* the account of a cron request, after the scope check */
function api_cron_account(array $ctx, array $a) {
	global $db;
	list($acct, $e) = api_spam_account($ctx, $a);
	if ($e) return array('', $e);
	if ($e = api_scope($ctx, 'cron', $acct)) return array('', $e);
	if (!account_get($db, $acct))
		return array('', api_error(404, "Account $acct not found."));
	return array($acct, null);
}

/* the job fields of a request body */
function api_cron_fields(array $b) {
	$o = array();
	foreach (array('name' => 80, 'schedule' => 100, 'type' => 10, 'command' => 1000, 'file' => 250, 'php' => 5, 'url' => 500) as $k => $max)
		if (array_key_exists($k, $b))
			$o[$k] = api_str($b, $k, $max);
	if (array_key_exists('on', $b))
		$o['on'] = (bool)api_bool($b, 'on');
	return $o;
}

function api_backup_account(array $ctx, array $a) {
	global $db;
	list($acct, $e) = api_spam_account($ctx, $a);
	if ($e) return array('', $e);
	if ($e = api_scope($ctx, 'account', $acct)) return array('', $e);
	if (!account_get($db, $acct))
		return array('', api_error(404, "Account $acct not found."));
	return array($acct, null);
}

function api_v1_backups(array $ctx) {
	list($acct, $e) = api_backup_account($ctx, $ctx['query']);
	if ($e) return $e;
	return backup_list($acct, api_bool($ctx['query'], 'refresh') === true);
}

function api_v1_backup(array $ctx) {
	list($acct, $e) = api_backup_account($ctx, $ctx['query']);
	if ($e) return $e;
	$r = backup_detail($acct, $ctx['params']['id']);
	return $r['ok'] ? $r : api_error(404, $r['error']);
}

function api_v1_backup_restore(array $ctx) {
	list($acct, $e) = api_backup_account($ctx, $ctx['body'] + $ctx['query']);
	if ($e) return $e;
	$b = $ctx['body'];
	$o = array('kind' => api_str($b, 'kind', 20), 'target' => api_str($b, 'target', 1000), 'mode' => api_str($b, 'mode', 20),
	           'name' => api_str($b, 'name', 64), 'grant' => api_str($b, 'grant', 80));
	$by = $ctx['token']['name'] === 'account-panel' ? '' : 'api:'.$ctx['token']['name'];
	return api_created(backup_restore_start($acct, $ctx['params']['id'], $o, $by));
}

function api_v1_backup_restores(array $ctx) {
	list($acct, $e) = api_backup_account($ctx, $ctx['query']);
	if ($e) return $e;
	return result_ok('', array('restores' => backup_restore_list($acct)));
}

function api_v1_backup_restore_get(array $ctx) {
	list($acct, $e) = api_backup_account($ctx, $ctx['query']);
	if ($e) return $e;
	$j = ctype_digit((string)$ctx['params']['job']) ? backup_restore_get((int)$ctx['params']['job']) : null;
	if (!$j || $j['account'] !== $acct)
		return api_error(404, 'There is no such restore of account '.$acct.'.');
	return result_ok('', array('restore' => backup_restore_public($j, $ctx['scope'] === null)));
}

function api_v1_cron(array $ctx) {
	list($acct, $e) = api_cron_account($ctx, $ctx['query']);
	if ($e) return $e;
	return cron_list($acct);
}

function api_v1_cron_add(array $ctx) {
	list($acct, $e) = api_cron_account($ctx, $ctx['body']);
	if ($e) return $e;
	return api_created(cron_add($acct, api_cron_fields($ctx['body'])));
}

function api_v1_cron_edit(array $ctx) {
	list($acct, $e) = api_cron_account($ctx, $ctx['body'] + $ctx['query']);
	if ($e) return $e;
	return cron_edit($acct, $ctx['params']['id'], api_cron_fields($ctx['body']));
}

function api_v1_cron_delete(array $ctx) {
	list($acct, $e) = api_cron_account($ctx, $ctx['body'] + $ctx['query']);
	if ($e) return $e;
	return cron_delete($acct, $ctx['params']['id']);
}

function api_v1_cron_run(array $ctx) {
	list($acct, $e) = api_cron_account($ctx, $ctx['body'] + $ctx['query']);
	if ($e) return $e;
	return cron_run_now($acct, $ctx['params']['id']);
}

/* ---- SSH access ---------------------------------------------------------- */

function api_ssh_account(array $ctx, array $a) {
	global $db;
	list($acct, $e) = api_spam_account($ctx, $a);
	if ($e) return array('', $e);
	if ($e = api_scope($ctx, 'ssh_key', $acct)) return array('', $e);
	if (!account_get($db, $acct))
		return array('', api_error(404, "Account $acct not found."));
	return array($acct, null);
}

function api_v1_ssh(array $ctx) {
	list($acct, $e) = api_ssh_account($ctx, $ctx['query']);
	if ($e) return $e;
	$r = ssh_keys_list($acct);
	if (!$r['ok'])
		return $r;
	return result_ok('', ssh_access_info($acct) + $r['data']);
}

function api_v1_ssh_key_add(array $ctx) {
	list($acct, $e) = api_ssh_account($ctx, $ctx['body']);
	if ($e) return $e;
	return api_created(ssh_key_add($acct, api_str($ctx['body'], 'key', 16384), api_str($ctx['body'], 'name', 200)));
}

function api_v1_ssh_keys_fix(array $ctx) {
	list($acct, $e) = api_ssh_account($ctx, $ctx['body']);
	if ($e) return $e;
	return ssh_keys_fix($acct);
}

function api_v1_ssh_key_delete(array $ctx) {
	list($acct, $e) = api_ssh_account($ctx, $ctx['body'] + $ctx['query']);
	if ($e) return $e;
	return ssh_key_delete($acct, $ctx['params']['id']);
}

function api_v1_cron_runs(array $ctx) {
	list($acct, $e) = api_cron_account($ctx, $ctx['query']);
	if ($e) return $e;
	return cron_runs($acct, $ctx['params']['id']);
}

function api_v1_cron_output(array $ctx) {
	list($acct, $e) = api_cron_account($ctx, $ctx['query']);
	if ($e) return $e;
	return cron_run_output($acct, $ctx['params']['id'], $ctx['params']['ms']);
}

function api_v1_ssl_issue(array $ctx) {
	$domain = strtolower($ctx['params']['domain']);
	if ($e = api_scope($ctx, 'domain', $domain)) return $e;
	$acct = $ctx['scope'] !== null ? $ctx['scope'] : scope_domain_owner($domain);
	if ($acct === '' || !domain_site($acct, $domain))
		return api_error(404, "$domain is not the main or an addon domain of an account.");
	return ssl_site_issue($acct, $domain);
}

function api_v1_spam(array $ctx) {
	if ($e = api_email_off()) return $e;
	list($acct, $e) = api_spam_account($ctx, $ctx['query']);
	if ($e) return $e;
	return spam_settings_get($acct);
}

function api_v1_spam_edit(array $ctx) {
	if ($e = api_email_off()) return $e;
	list($acct, $e) = api_spam_account($ctx, $ctx['body']);
	if ($e) return $e;
	$o = array();
	foreach (array('score', 'allow', 'block') as $k)
		if (array_key_exists($k, $ctx['body']))
			$o[$k] = $ctx['body'][$k];
	if (array_key_exists('junk', $ctx['body'])) {
		if (($v = api_bool($ctx['body'], 'junk')) === null)
			return api_error(400, 'junk must be true or false.');
		$o['junk'] = $v;
	}
	return spam_settings_set($acct, $o);
}

/* mail DNS */

/* ?domain= (scope-checked), else an account's mail domains */
function api_mail_domains(array $ctx) {
	$d = strtolower(api_str($ctx['query'], 'domain'));
	if ($d !== '') {
		if ($e = api_scope($ctx, 'domain', $d))
			return array(null, $e);
		if (!email_domain_exists($d))
			return array(null, api_error(404, "$d does not have mail on this server."));
		return array(array($d), null);
	}
	if ($ctx['scope'] === null)
		return array(null, null);
	return array(email_account_domains($ctx['scope']), null);
}

function api_v1_mail_dns(array $ctx) {
	if ($e = api_email_off()) return $e;
	list($domains, $e) = api_mail_domains($ctx);
	if ($e) return $e;
	if ($domains === null)
		return api_error(400, 'Say which domain: ?domain=<domain>.');
	return result_ok('', array_values(mail_dns_check_many($domains)));
}

function api_v1_mail_dns_fix(array $ctx) {
	if ($e = api_email_off()) return $e;
	$d = strtolower($ctx['params']['domain']);
	if ($e = api_scope($ctx, 'domain', $d)) return $e;
	return mail_dns_fix($d);
}

/* mail provider */

function api_v1_mail_provider(array $ctx) {
	global $db;
	list($acct, $e) = api_spam_account($ctx, $ctx['query']);
	if ($e) return $e;
	if (!account_get($db, $acct))
		return api_error(404, "Account $acct not found.");
	return result_ok('', array('providers' => array_map(function ($p) { return array('name' => $p['name'], 'regions' => $p['regions'] ?? null, 'verify' => $p['verify'] ?? ''); }, mail_providers()),
	                           'sites' => mail_provider_status($acct)));
}

function api_v1_mail_provider_set(array $ctx) {
	$d = strtolower($ctx['params']['domain']);
	if ($e = api_scope($ctx, 'domain', $d)) return $e;
	$acct = $ctx['scope'] !== null ? $ctx['scope'] : scope_domain_owner($d);
	if ($acct === '')
		return api_error(404, "$d is not a domain of an account.");
	$b = $ctx['body'];
	$mx = isset($b['mx']) && is_array($b['mx']) ? array_map('strval', array_filter($b['mx'], 'is_scalar')) : api_str($b, 'mx', 2000);
	return mail_provider_set($acct, $d, array('provider' => api_str($b, 'provider', 20), 'region' => api_str($b, 'region', 30), 'mx' => $mx,
	                                          'spf' => api_str($b, 'spf', 253), 'verify' => api_str($b, 'verify', 1000)), (bool)api_bool($b, 'preview'));
}

/* the site's account for the mail record helpers, after the scope check */
function api_mail_site(array $ctx) {
	$d = strtolower($ctx['params']['domain']);
	if ($e = api_scope($ctx, 'domain', $d)) return array('', '', $e);
	$acct = $ctx['scope'] !== null ? $ctx['scope'] : scope_domain_owner($d);
	if ($acct === '' || (!in_array($d, array_column(domain_sites($acct, false), 'domain'), true) && (email_userdomains()[$d] ?? '') !== $acct))
		return array('', '', api_error(404, "$d is not a site of an account."));
	return array($acct, $d, null);
}

/* a list given as an array or as text (commas, spaces or lines) */
function api_words(array $a, $key) {
	if (!isset($a[$key]))
		return array();
	$v = is_array($a[$key]) ? array_filter($a[$key], 'is_scalar') : preg_split('/[\s,;]+/', (string)$a[$key]);
	return array_values(array_filter(array_map('trim', array_map('strval', $v)), 'strlen'));
}

function api_v1_mail_spf(array $ctx) {
	list($acct, $d, $e) = api_mail_site($ctx);
	return $e ?: mail_spf_get($acct, $d);
}

function api_v1_mail_spf_set(array $ctx) {
	list($acct, $d, $e) = api_mail_site($ctx);
	if ($e) return $e;
	$b = $ctx['body'];
	return mail_spf_set($acct, $d, array('server' => api_bool($b, 'server') ?? true, 'mx' => (bool)api_bool($b, 'mx'),
	                                    'senders' => api_words($b, 'senders'), 'custom' => api_words($b, 'custom'), 'all' => api_str($b, 'all', 5)),
	                    (bool)api_bool($b, 'preview'));
}

function api_v1_mail_dmarc(array $ctx) {
	list($acct, $d, $e) = api_mail_site($ctx);
	return $e ?: mail_dmarc_get($acct, $d);
}

function api_v1_mail_dmarc_set(array $ctx) {
	list($acct, $d, $e) = api_mail_site($ctx);
	if ($e) return $e;
	$b = $ctx['body'];
	return mail_dmarc_set($acct, $d, array('p' => api_str($b, 'p', 12), 'sp' => api_str($b, 'sp', 12), 'pct' => api_str($b, 'pct', 3),
	                                      'rua' => api_words($b, 'rua'), 'ruf' => api_words($b, 'ruf'), 'adkim' => api_str($b, 'adkim', 1), 'aspf' => api_str($b, 'aspf', 1)),
	                      (bool)api_bool($b, 'preview'));
}

/* delivery log */

function api_v1_mail_log(array $ctx) {
	if ($e = api_email_off()) return $e;
	list($domains, $e) = api_mail_domains($ctx);
	if ($e) return $e;
	$limit = isset($ctx['query']['limit']) ? (int)$ctx['query']['limit'] : 50;
	return result_ok('', mail_log_search($domains, api_str($ctx['query'], 'search', 200), $limit));
}

/* files */

/* The account whose files: the token's own, or account= for server tokens. */
function api_files_account(array $ctx, array $a) {
	list($acct, $e) = api_spam_account($ctx, $a);
	if ($e)
		return array(null, $e);
	if (($why = files_account_error($acct)) !== '')
		return array(null, api_error(404, $why));
	return array($acct, null);
}

/* a path as given (not trimmed: a name may end in a space) */
function api_path(array $a, $key) {
	return isset($a[$key]) && is_scalar($a[$key]) ? substr((string)$a[$key], 0, 4096) : '';
}

function api_files_status(array $r) {
	$code = $r['data']['code'] ?? '';
	$st = array('path' => 404, 'missing' => 404, 'perm' => 403, 'protected' => 403, 'exists' => 409, 'changed' => 409, 'big' => 413, 'account' => 404);
	return $r['ok'] ? $r : array($st[$code] ?? 400, $r);
}

function api_v1_files(array $ctx) {
	list($u, $e) = api_files_account($ctx, $ctx['query']);
	if ($e) return $e;
	return api_files_status(files_list($u, api_path($ctx['query'], 'path')));
}

function api_v1_files_usage(array $ctx) {
	list($u, $e) = api_files_account($ctx, $ctx['query']);
	if ($e) return $e;
	return api_files_status(files_usage($u, api_path($ctx['query'], 'path')));
}

function api_v1_files_read(array $ctx) {
	list($u, $e) = api_files_account($ctx, $ctx['query']);
	if ($e) return $e;
	return api_files_status(files_read($u, api_path($ctx['query'], 'path')));
}

function api_v1_files_save(array $ctx) {
	$b = $ctx['body'];
	list($u, $e) = api_files_account($ctx, $b);
	if ($e) return $e;
	if (!isset($b['content']) || !is_string($b['content']))
		return api_error(400, 'Send the file\'s content: content.');
	$mtime = isset($b['mtime']) && is_numeric($b['mtime']) ? (int)$b['mtime'] : null;
	return api_files_status(files_save($u, api_path($b, 'path'), $b['content'], $mtime, (bool)api_bool($b, 'create'), (bool)api_bool($b, 'force')));
}

function api_v1_files_create(array $ctx) {
	$b = $ctx['body'];
	list($u, $e) = api_files_account($ctx, $b);
	if ($e) return $e;
	$type = api_str($b, 'type', 10);
	if (!in_array($type, array('folder', 'file'), true))
		return api_error(400, 'type must be folder or file.');
	$r = $type === 'folder' ? files_mkdir($u, api_path($b, 'path')) : files_create($u, api_path($b, 'path'));
	return $r['ok'] ? api_created($r) : api_files_status($r);
}

function api_v1_files_edit(array $ctx) {
	$b = $ctx['body'];
	list($u, $e) = api_files_account($ctx, $b);
	if ($e) return $e;
	$path = api_path($b, 'path');
	$move = array_key_exists('to', $b) && is_scalar($b['to']);       // "" = the home folder
	$to = api_path($b, 'to');
	$mode = api_str($b, 'mode', 5);
	if (!$move && $mode === '')
		return api_error(400, 'Say what to change: to (a new path, "" = the home folder) and/or mode.');
	$r = null;
	if ($mode !== '') {
		$r = files_chmod($u, $path, $mode);
		if (!$r['ok'] || !$move)
			return api_files_status($r);
	}
	$m = files_rename($u, $path, $to);
	if ($m['ok'] && $r)
		$m['message'] = $r['message'].' '.$m['message'];
	return api_files_status($m);
}

function api_v1_files_copy(array $ctx) {
	$b = $ctx['body'];
	list($u, $e) = api_files_account($ctx, $b);
	if ($e) return $e;
	return api_files_status(files_copy($u, api_path($b, 'path'), api_path($b, 'to')));
}

/* a list from JSON, or one string with commas (MCP sends strings) */
function api_list(array $a, $key) {
	if (!isset($a[$key]))
		return array();
	if (is_array($a[$key]))
		return array_values(array_filter($a[$key], 'is_string'));
	return is_string($a[$key]) ? array_values(array_filter(array_map('trim', explode(',', $a[$key])), 'strlen')) : array();
}

function api_v1_files_delete(array $ctx) {
	$a = $ctx['body'] + $ctx['query'];
	list($u, $e) = api_files_account($ctx, $a);
	if ($e) return $e;
	$paths = isset($ctx['body']['paths']) && is_array($ctx['body']['paths']) ? array_values(array_filter($ctx['body']['paths'], 'is_string')) : array();
	if (!$paths && api_path($a, 'path') !== '')
		$paths = array(api_path($a, 'path'));
	if (!$paths)
		return api_error(400, 'Say what to delete: ?path= or paths.');
	return api_files_status(files_delete($u, $paths));
}

function api_v1_files_compress(array $ctx) {
	$b = $ctx['body'];
	list($u, $e) = api_files_account($ctx, $b);
	if ($e) return $e;
	return api_files_status(files_compress($u, api_path($b, 'dir'), api_list($b, 'items'), api_str($b, 'name', 200), api_str($b, 'format', 5)));
}

function api_v1_files_extract(array $ctx) {
	$b = $ctx['body'];
	list($u, $e) = api_files_account($ctx, $b);
	if ($e) return $e;
	return api_files_status(files_extract($u, api_path($b, 'path'), api_path($b, 'dest'), (bool)api_bool($b, 'overwrite')));
}

/* health */

function api_v1_health(array $ctx) {
	global $db;
	$acct = isset($ctx['query']['account']) ? api_str($ctx['query'], 'account') : null;
	if ($acct === '-') $acct = '';
	if ($ctx['scope'] !== null) {
		if ($acct !== null && $acct !== $ctx['scope'])
			return api_error(403, "This token sees only the problems of account {$ctx['scope']}.");
		$acct = $ctx['scope'];
	}
	$items = health_items($db, $acct, (bool)api_bool($ctx['query'], 'all'));
	$last = (int)setting_get('health-last-run', '0');
	return result_ok('', array('last_run' => $last ?: null, 'counts' => health_counts($items), 'items' => $items));
}

function api_v1_health_checks(array $ctx) {
	$rows = array();
	foreach (health_checks() as $id => $c)
		$rows[] = array('id' => $id, 'label' => $c['label'], 'scope' => $c['scope']);
	return result_ok('', $rows);
}

function api_v1_health_run(array $ctx) {
	global $db;
	/* the checks read files only root may read: run them as the panel's
	   "Check now" does */
	shell_exec('sudo '._PATH.'/bin/reqad health run --quiet > /dev/null 2>&1');
	$c = health_counts(health_items($db));
	return result_ok("Checked: {$c['critical']} critical, {$c['warning']} warning, {$c['info']} info.",
		array('last_run' => (int)setting_get('health-last-run', '0') ?: null, 'counts' => $c));
}

function api_v1_health_dismiss(array $ctx) {
	global $db;
	$b = $ctx['body'];
	$id = api_str($b, 'id', 300);
	if ($e = api_scope($ctx, 'health_item', $id)) return $e;
	if (!health_item_get($db, $id))
		return api_error(404, "No health item '$id'.");
	$forever = (bool)api_bool($b, 'forever');
	$days = isset($b['days']) ? (int)$b['days'] : 7;
	if (!$forever && ($days < 1 || $days > 365))
		return api_error(400, 'days must be 1 to 365 (or send forever: true).');
	return health_dismiss($db, $id, $forever ? 0 : $days, auth_user());
}

function api_v1_health_restore(array $ctx) {
	global $db;
	$id = api_str($ctx['body'], 'id', 300);
	if ($e = api_scope($ctx, 'health_item', $id)) return $e;
	if (!health_item_get($db, $id))
		return api_error(404, "No health item '$id'.");
	return health_restore($db, $id);
}

/* deployments */

function api_deploy_find(array $ctx) {
	$id = $ctx['params']['id'];
	if ($e = api_scope($ctx, 'deployment', $id)) return array(null, $e);
	$d = deploy_get($id);
	return $d ? array($d, null) : array(null, api_error(404, "No deployment '$id'."));
}

function api_v1_deployments(array $ctx) {
	$acct = $ctx['scope'] !== null ? $ctx['scope'] : api_str($ctx['query'], 'account');
	$out = array();
	foreach (deploy_list($acct) as $d)
		$out[] = deploy_public($d) + array('last_run' => array('status' => $d['run_status'], 'source' => $d['run_source'],
			'queued' => (int)$d['run_queued'] ?: null, 'finished' => (int)$d['run_finished'] ?: null, 'message' => $d['run_message']));
	return result_ok('', $out);
}

function api_v1_deployment_show(array $ctx) {
	list($d, $e) = api_deploy_find($ctx);
	if ($e) return $e;
	$rel = array();
	foreach (deploy_releases($d) as $c)
		$rel[] = $c + array('current' => $c['release'] === $d['current_release']);
	return result_ok('', deploy_public($d) + array('public_key' => deploy_public_key($d), 'releases' => $rel,
		'runs' => array_map('api_deploy_run_public', deploy_runs($d['id'], 10))));
}

function api_deploy_run_public(array $r) {
	return array('id' => (int)$r['id'], 'source' => $r['source'], 'by' => $r['by_user'], 'status' => $r['status'],
		'commit' => $r['commit_sha'], 'commit_message' => $r['commit_msg'], 'release' => $r['release_name'],
		'message' => $r['message'], 'queued' => (int)$r['queued'], 'started' => (int)$r['started'] ?: null,
		'finished' => (int)$r['finished'] ?: null);
}

function api_v1_deployment_deploy(array $ctx) {
	list($d, $e) = api_deploy_find($ctx);
	if ($e) return $e;
	$r = deploy_trigger($d['id'], 'api', auth_user());
	return array($r['ok'] ? 202 : 400, $r);
}

function api_v1_deployment_rollback(array $ctx) {
	list($d, $e) = api_deploy_find($ctx);
	if ($e) return $e;
	$r = deploy_trigger($d['id'], 'rollback', auth_user(), api_str($ctx['body'], 'release', 20));
	return array($r['ok'] ? 202 : 400, $r);
}

function api_v1_deploy_run(array $ctx) {
	$run = deploy_run_get((int)$ctx['params']['run']);
	if ($run && ($e = api_scope($ctx, 'deployment', (string)$run['deployment_id']))) return $e;
	if (!$run) return api_error(404, 'No such deploy run.');
	list($text, $next) = deploy_run_log($run['id'], (int)api_str($ctx['query'], 'offset'));
	return result_ok('', api_deploy_run_public($run) + array('output' => $text, 'next_offset' => $next));
}

/* a site an account token may set a deployment or an app up for: its own,
   with a full shell. array(domain, error) */
function api_deploy_site(array $ctx, $domain) {
	$domain = strtolower(trim((string)$domain));
	if ($ctx['scope'] !== null) {
		if ($e = api_scope($ctx, 'domain', $domain)) return array('', $e);
		if (($why = deploy_account_error($ctx['scope'])) !== '') return array('', api_error(403, $why));
	}
	return array($domain, null);
}

function api_deploy_fields(array $b) {
	$f = array();
	foreach (array('repo_url' => 500, 'branch' => 100, 'public_dir' => 200, 'health_path' => 200) as $k => $max)
		if (array_key_exists($k, $b))
			$f[$k] = api_str($b, $k, $max);
	foreach (array('build_cmd', 'shared_paths') as $k)
		if (array_key_exists($k, $b))
			$f[$k] = is_array($b[$k]) ? implode("\n", array_map('strval', array_filter($b[$k], 'is_scalar'))) : (is_scalar($b[$k]) ? substr((string)$b[$k], 0, 4000) : '');
	if (array_key_exists('keep_releases', $b))
		$f['keep_releases'] = (int)$b['keep_releases'];
	if (array_key_exists('auto_deploy', $b))
		$f['auto_deploy'] = (bool)api_bool($b, 'auto_deploy');
	return $f;
}

function api_v1_deployment_create(array $ctx) {
	list($domain, $e) = api_deploy_site($ctx, api_str($ctx['body'], 'domain', 255));
	if ($e) return $e;
	$f = api_deploy_fields($ctx['body']);
	if ($ctx['scope'] !== null)
		$f['account'] = $ctx['scope'];
	return api_created(deploy_create(array('domain' => $domain) + $f, auth_user()));
}

function api_v1_deployment_edit(array $ctx) {
	list($d, $e) = api_deploy_find($ctx);
	if ($e) return $e;
	if ($ctx['scope'] !== null && ($why = deploy_account_error($ctx['scope'])) !== '')
		return api_error(403, $why);
	$f = api_deploy_fields($ctx['body']);
	if (isset($f['repo_url']) && $f['repo_url'] === '')
		unset($f['repo_url']);           // an address with a token in it is never shown: empty = keep it
	if (api_bool($ctx['body'], 'new_secret'))
		$f['new_secret'] = true;
	return deploy_edit($d['id'], $f);
}

function api_v1_deployment_delete(array $ctx) {
	list($d, $e) = api_deploy_find($ctx);
	if ($e) return $e;
	return deploy_delete($d['id']);
}

function api_v1_deployment_connect(array $ctx) {
	list($d, $e) = api_deploy_find($ctx);
	if ($e) return $e;
	return result_ok('', array('domain' => $d['domain'], 'public_key' => deploy_public_key($d), 'webhook_url' => deploy_webhook_url($d),
		'webhook_secret' => $d['webhook_secret'], 'webhook_ready' => deploy_webhook_ready(),
		'repo_url' => deploy_url_safe($d['repo_url']), 'ssh' => !preg_match('#^https?://#i', $d['repo_url'])));
}

/* apps */

function api_app_fields(array $b) {
	$f = array();
	foreach (array('runtime' => 10, 'version' => 10, 'start_cmd' => 1000, 'app_dir' => 200) as $k => $max)
		if (array_key_exists($k, $b))
			$f[$k] = api_str($b, $k, $max);
	if (array_key_exists('serve_static', $b))
		$f['serve_static'] = (bool)api_bool($b, 'serve_static');
	if (array_key_exists('memory_mb', $b))
		$f['memory_mb'] = (int)$b['memory_mb'];
	return $f;
}

/* an account token gives an app at most app_memory_max() */
function api_app_memory(array $ctx, array $f) {
	if ($ctx['scope'] === null || !isset($f['memory_mb']))
		return null;
	$max = app_memory_max($ctx['scope']);
	return $f['memory_mb'] > $max ? api_error(400, "An app of this account can have at most $max MB of memory.") : null;
}

function api_v1_app_runtimes(array $ctx) {
	$out = array('runtimes' => array(), 'nginx' => web_front() === 'nginx');
	foreach (app_runtimes() as $r)
		$out['runtimes'][] = array('kind' => $r['kind'], 'version' => $r['version'], 'full' => $r['full']);
	if ($ctx['scope'] !== null)
		$out['memory_max'] = app_memory_max($ctx['scope']);
	return result_ok('', $out);
}

function api_v1_app_create(array $ctx) {
	list($domain, $e) = api_deploy_site($ctx, api_str($ctx['body'], 'domain', 255));
	if ($e) return $e;
	$f = api_app_fields($ctx['body']);
	if ($e = api_app_memory($ctx, $f)) return $e;
	/* deployed from Git but not yet: the first deploy starts it */
	$d = deploy_get($domain);
	if ($d && (string)$d['current_release'] === '' && (string)($f['app_dir'] ?? '') === '')
		$f['start'] = false;
	return api_created(app_create(array('domain' => $domain) + $f, auth_user()));
}

function api_v1_app_detect(array $ctx) {
	list($domain, $e) = api_deploy_site($ctx, api_str($ctx['query'], 'domain', 255));
	if ($e) return $e;
	$prefer = api_str($ctx['query'], 'runtime', 10);
	return app_detect_site($domain, array_key_exists('app_dir', $ctx['query']) ? api_str($ctx['query'], 'app_dir', 200) : null,
		in_array($prefer, array('node', 'python'), true) ? $prefer : '');
}

function api_v1_app_edit(array $ctx) {
	list($a, $e) = api_app_find($ctx);
	if ($e) return $e;
	if ($ctx['scope'] !== null && ($why = deploy_account_error($ctx['scope'])) !== '')
		return api_error(403, $why);
	$f = api_app_fields($ctx['body']);
	if ($e = api_app_memory($ctx, $f)) return $e;
	return app_edit($a['id'], $f);
}

function api_v1_app_delete(array $ctx) {
	list($a, $e) = api_app_find($ctx);
	if ($e) return $e;
	return app_delete($a['id']);
}

function api_v1_app_env(array $ctx) {
	list($a, $e) = api_app_find($ctx);
	if ($e) return $e;
	return result_ok('', array('text' => app_env_read($a), 'file' => app_env_file($a)));
}

function api_v1_app_env_save(array $ctx) {
	list($a, $e) = api_app_find($ctx);
	if ($e) return $e;
	return app_env_write($a, isset($ctx['body']['text']) && is_string($ctx['body']['text']) ? $ctx['body']['text'] : '');
}

function api_app_find(array $ctx) {
	$id = $ctx['params']['id'];
	if ($e = api_scope($ctx, 'app', $id)) return array(null, $e);
	$a = app_get($id);
	return $a ? array($a, null) : array(null, api_error(404, "No app '$id'."));
}

function api_v1_apps(array $ctx) {
	$acct = $ctx['scope'] !== null ? $ctx['scope'] : api_str($ctx['query'], 'account');
	$out = array();
	foreach (app_list($acct) as $a)
		$out[] = app_public($a) + array('status' => app_status($a));
	return result_ok('', $out);
}

function api_v1_app_show(array $ctx) {
	list($a, $e) = api_app_find($ctx);
	if ($e) return $e;
	return result_ok('', app_public($a) + array('status' => app_status($a)));
}

function api_v1_app_control(array $ctx) {
	if (!in_array($ctx['params']['op'], array('start', 'stop', 'restart'), true))
		return api_error(404, 'Use /start, /stop or /restart.');
	list($a, $e) = api_app_find($ctx);
	if ($e) return $e;
	return app_control($a['id'], $ctx['params']['op']);
}

/* it runs the account's install scripts (setup.py, npm's) outside any jail */
function api_v1_app_install(array $ctx) {
	list($a, $e) = api_app_find($ctx);
	if ($e) return $e;
	if ($ctx['scope'] !== null && ($why = deploy_account_error($ctx['scope'])) !== '')
		return api_error(403, $why);
	return app_pkg_start($a['id'], auth_user());
}

function api_v1_app_install_state(array $ctx) {
	list($a, $e) = api_app_find($ctx);
	if ($e) return $e;
	list($text, $next) = app_pkg_output($a, (int)api_str($ctx['query'], 'offset'));
	return result_ok('', array('install' => app_pkg_state($a), 'output' => $text, 'next_offset' => $next));
}

function api_v1_app_logs(array $ctx) {
	list($a, $e) = api_app_find($ctx);
	if ($e) return $e;
	return result_ok('', array('output' => app_logs($a, (int)api_str($ctx['query'], 'lines') ?: 200)));
}

/* server */

function api_v1_server(array $ctx) {
	return result_ok('', server_status());
}

function api_v1_services(array $ctx) {
	return result_ok('', server_services());
}

function api_v1_service_restart(array $ctx) {
	return server_service_restart($ctx['params']['name']);
}

function api_v1_mail_queue(array $ctx) {
	if ($e = api_email_off()) return $e;
	$q = $ctx['query'];
	return server_mail_queue(api_str($q, 'filter') === 'frozen' ? 'frozen' : 'all', api_str($q, 'search', 200),
		(int)api_str($q, 'limit') ?: 50);
}

function api_v1_mail_queue_deliver(array $ctx) {
	if ($e = api_email_off()) return $e;
	return server_mail_queue_action('deliver', $ctx['params']['id']);
}

function api_v1_mail_queue_remove(array $ctx) {
	if ($e = api_email_off()) return $e;
	return server_mail_queue_action('remove', $ctx['params']['id']);
}

/* logs: an account token reads only the site and PHP logs of its own domains */

function api_v1_logs(array $ctx) {
	$rows = array();
	foreach (server_logs() as $name => $l)
		if ($ctx['scope'] === null || !empty($l['account']))
			$rows[] = array('name' => $name, 'label' => $l['label'], 'needs_domain' => !empty($l['domain']),
			                'path' => $l['path']);
	return result_ok('', $rows);
}

function api_v1_log(array $ctx) {
	$name = $ctx['params']['name'];
	$logs = server_logs();
	$domain = strtolower(api_str($ctx['query'], 'domain'));
	if ($ctx['scope'] !== null) {
		if (isset($logs[$name]) && empty($logs[$name]['account']))
			return api_error(403, "Only a token for the whole server can read the $name log.");
		if ($e = api_scope($ctx, 'domain', $domain)) return $e;
	}
	if (!isset($logs[$name]))
		return api_error(404, "No log '$name'. GET /api/v1/logs lists them.");
	return server_log_tail($name, $domain, (int)api_str($ctx['query'], 'lines') ?: 100, api_str($ctx['query'], 'grep', 200));
}

/* A site's web server log, one day (app/functions/sitelogs.php) */
function api_v1_site_log(array $ctx) {
	$domain = strtolower($ctx['params']['domain']);
	if ($domain === 'main') {
		$acct = $ctx['scope'] !== null ? $ctx['scope'] : api_str($ctx['query'], 'account');
		$row = valid_username($acct) ? account_get($GLOBALS['db'], $acct) : null;
		if (!$row)
			return api_error(400, 'Site "main" needs ?account=<user>.');
		$domain = strtolower($row['domain']);
	}
	if ($ctx['scope'] !== null && ($e = api_scope($ctx, 'domain', $domain))) return $e;
	$q = $ctx['query'];
	$o = array('day' => api_str($q, 'day', 10), 'tab' => api_str($q, 'tab', 10), 'filter' => api_str($q, 'filter', 5), 'q' => api_str($q, 'q', 200),
	           'ip' => api_str($q, 'ip', 64), 'offset' => (int)api_str($q, 'offset', 6), 'after' => api_str($q, 'after', 40));
	if (api_bool($q, 'bots') === false) $o['bots'] = false;
	if (api_bool($q, 'grouped') === false) $o['grouped'] = false;
	$r = sitelog_read($domain, $o);
	if ($r['ok'] && !isset($r['data']['add']))
		$r['data']['sites'] = sitelog_sites(stats_sites()[$domain] ?? '');
	return $r;
}

/* Visitor stats (app/functions/stats.php) */

function api_v1_stats(array $ctx) {
	$month = api_str($ctx['query'], 'month', 7);
	if ($month !== '' && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month))
		return api_error(400, 'month: YYYY-MM.');
	$r = stats_usage($month);
	if ($ctx['scope'] !== null && $r['ok'])
		$r['data']['accounts'] = array_values(array_filter($r['data']['accounts'], function ($a) use ($ctx) { return $a['user'] === $ctx['scope']; }));
	if ($ctx['scope'] !== null && $r['ok'])
		unset($r['data']['totals'], $r['data']['geo']['file']);
	return $r;
}

function api_v1_stats_site(array $ctx) {
	$site = strtolower($ctx['params']['site']);
	$period = api_str($ctx['query'], 'period', 7) ?: '30d';
	if ($site === 'main') {
		/* the account's main domain */
		$acct = $ctx['scope'] !== null ? $ctx['scope'] : api_str($ctx['query'], 'account');
		$row = valid_username($acct) ? account_get($GLOBALS['db'], $acct) : null;
		if (!$row)
			return api_error(400, 'Site "main" needs ?account=<user>.');
		$site = strtolower($row['domain']);
	}
	if ($site === 'all') {
		$acct = $ctx['scope'] !== null ? $ctx['scope'] : api_str($ctx['query'], 'account');
		if (!valid_username($acct) || !account_get($GLOBALS['db'], $acct))
			return api_error(400, 'Site "all" needs ?account=<user>.');
	} else {
		if ($ctx['scope'] !== null && ($e = api_scope($ctx, 'domain', $site))) return $e;
		$acct = stats_sites()[$site] ?? '';
		if ($acct === '')
			return api_error(404, "No site $site on this server.");
	}
	return stats_report($acct, $site, $period);
}

function api_v1_stats_settings(array $ctx) {
	$on = api_bool($ctx['body'], 'enabled');
	$geo = api_bool($ctx['body'], 'geoip_update');
	if ($on === null && $geo === null)
		return api_error(400, 'Give enabled and/or geoip_update (true or false).');
	return stats_settings_set($on, $geo);
}

function api_v1_stats_geoip(array $ctx) {
	return stats_geo_update(true);
}

/* Resource usage (app/functions/resources.php) */

function api_v1_resources_range(array $ctx) {
	$range = api_str($ctx['query'], 'range', 5) ?: 'day';
	return in_array($range, array('day', 'week', 'month'), true) ? $range : null;
}

/* the {account} of a resources route, "me" = the token's own; null + error */
function api_v1_resources_who(array $ctx) {
	$a = strtolower((string)$ctx['params']['account']);
	if ($a === 'me') {
		if ($ctx['scope'] === null)
			return array(null, api_error(400, '"me" needs an account token; give the account name.'));
		$a = $ctx['scope'];
	}
	if ($ctx['scope'] !== null && ($e = api_scope($ctx, 'account', $a)))
		return array(null, $e);
	if (!valid_username($a) || !account_get($GLOBALS['db'], $a))
		return array(null, api_error(404, "No account $a."));
	return array($a, null);
}

function api_v1_resources(array $ctx) {
	$range = api_v1_resources_range($ctx);
	if ($range === null)
		return api_error(400, 'range: day, week or month.');
	$r = resources_usage($range);
	if ($ctx['scope'] !== null && $r['ok']) {
		$r['data']['accounts'] = array_values(array_filter($r['data']['accounts'], function ($a) use ($ctx) { return $a['user'] === $ctx['scope']; }));
		unset($r['data']['server'], $r['data']['accounts_cpu']);
	}
	return $r;
}

function api_v1_resources_account(array $ctx) {
	$range = api_v1_resources_range($ctx);
	if ($range === null)
		return api_error(400, 'range: day, week or month.');
	list($a, $e) = api_v1_resources_who($ctx);
	return $e ?: resources_report($a, $range);
}

function api_v1_resources_processes(array $ctx) {
	list($a, $e) = api_v1_resources_who($ctx);
	return $e ?: resources_processes($a);
}

function api_v1_resources_stop(array $ctx) {
	list($a, $e) = api_v1_resources_who($ctx);
	if ($e) return $e;
	if (!ctype_digit((string)$ctx['params']['pid']))
		return api_error(400, 'pid: a number.');
	return resources_stop($a, (int)$ctx['params']['pid'], api_str($ctx['body'], 'start', 20));
}

/* WordPress (app/functions/wordpress.php) */

function api_wp_find(array $ctx) {
	$id = $ctx['params']['site'];
	$row = wordpress_find($id);
	if ($ctx['scope'] !== null && (!$row || scope_check($ctx['scope'], 'account', $row['user']) !== ''))
		return array(null, api_error(403, "Account {$ctx['scope']} has no WordPress site '$id'."));
	return $row ? array($row, null) : array(null, api_error(404, "No WordPress site '$id'. GET /api/v1/wordpress lists them."));
}

function api_v1_wordpress(array $ctx) {
	$acct = $ctx['scope'] !== null ? $ctx['scope'] : api_str($ctx['query'], 'account');
	return result_ok('', wordpress_sites($acct));
}

function api_v1_wordpress_scan(array $ctx) {
	$acct = $ctx['scope'] !== null ? $ctx['scope'] : api_str($ctx['body'], 'account');
	if ($acct === '')
		return api_error(400, 'Name the account: {"account": "<user>"}.');
	if ($ctx['scope'] !== null && ($e = scope_check($ctx['scope'], 'account', $acct)) !== '')
		return api_error(403, $e);
	return wordpress_scan($acct);
}

/* The account a WordPress install request is for: the token's, or the one named (server tokens). */
function api_wp_account(array $ctx, $from) {
	$acct = $ctx['scope'] !== null ? $ctx['scope'] : api_str($from, 'account');
	if ($acct === '')
		return array('', api_error(400, 'Name the account: "account": "<user>".'));
	if ($ctx['scope'] !== null && ($e = scope_check($ctx['scope'], 'account', $acct)) !== '')
		return array('', api_error(403, $e));
	if (!account_get($GLOBALS['db'], $acct))
		return array('', api_error(404, "No hosting account '$acct'."));
	return array($acct, null);
}

function api_v1_wordpress_install_options(array $ctx) {
	list($acct, $e) = api_wp_account($ctx, $ctx['query']);
	if ($e) return $e;
	$sites = array();
	foreach (wordpress_install_targets($acct) as $t)
		$sites[] = array_diff_key($t, array('docroot' => 1));
	return result_ok('', array('sites' => $sites, 'locales' => wordpress_locales(), 'email' => account_contact($acct),
	                           'password_min' => (int)auth_cfg('auth-min-password')));
}

function api_v1_wordpress_install(array $ctx) {
	list($acct, $e) = api_wp_account($ctx, $ctx['body']);
	if ($e) return $e;
	$b = $ctx['body'];
	$o = array();
	foreach (array('domain', 'path', 'title', 'admin_user', 'admin_email', 'locale') as $k)
		$o[$k] = api_str($b, $k, $k === 'title' ? 400 : 255);
	$o['admin_password'] = isset($b['admin_password']) && is_string($b['admin_password']) ? substr($b['admin_password'], 0, 256) : '';
	return wordpress_install($acct, $o);
}

function api_v1_wordpress_tools(array $ctx) {
	list($row, $e) = api_wp_find($ctx);
	if ($e) return $e;
	return wordpress_tools_status($row);
}

function api_v1_wordpress_tool_set(array $ctx) {
	list($row, $e) = api_wp_find($ctx);
	if ($e) return $e;
	if (!isset($ctx['body']['on']))
		return api_error(400, 'Say "on": true or false.');
	return wordpress_tool_set($row, (string)$ctx['params']['tool'], (bool)api_bool($ctx['body'], 'on'));
}

function api_v1_wordpress_cache_rules(array $ctx) {
	list($row, $e) = api_wp_find($ctx);
	if ($e) return $e;
	$b = $ctx['body'];
	$now = wp_cache_rules_get($row['domain']);
	$list = function ($k) use ($b, $now) {
		if (!array_key_exists($k, $b)) return $now[$k];     // not given = kept
		if ($b[$k] === null) return array();
		return is_array($b[$k]) ? array_slice(array_map('strval', array_filter($b[$k], 'is_scalar')), 0, 60) : api_str($b, $k, 12000);
	};
	return wordpress_cache_rules_set($row, $list('pages'), $list('cookies'));
}

function api_v1_wordpress_security(array $ctx) {
	list($row, $e) = api_wp_find($ctx);
	if ($e) return $e;
	$b = $ctx['body'];
	$on = isset($b['on']) && is_array($b['on']) ? array_values(array_filter($b['on'], 'is_string')) : array();
	$o = array('login_slug' => api_str($b, 'login_slug', 60), 'regen_keys' => (bool)api_bool($b, 'regen_keys'));
	if (isset($b['allow_ips']))
		$o['allow_ips'] = is_array($b['allow_ips']) ? array_slice(array_map('strval', $b['allow_ips']), 0, 60) : api_str($b, 'allow_ips', 4000);
	return wordpress_security_apply($row, $on, $o);
}

function api_v1_wordpress_auto(array $ctx) {
	list($row, $e) = api_wp_find($ctx);
	if ($e) return $e;
	return wordpress_auto_status($row);
}

function api_v1_wordpress_auto_set(array $ctx) {
	list($row, $e) = api_wp_find($ctx);
	if ($e) return $e;
	$b = $ctx['body'];
	$o = array();
	foreach (array('core', 'plugins', 'themes') as $k)
		if (isset($b[$k])) $o[$k] = api_str($b, $k, 10);
	foreach (array('plugin_list', 'theme_list') as $k)
		if (isset($b[$k])) $o[$k] = is_array($b[$k]) ? array_slice(array_map('strval', $b[$k]), 0, 250) : api_str($b, $k, 8000);
	foreach (array('weekday', 'hour', 'delay') as $k)
		if (isset($b[$k]) && is_scalar($b[$k])) $o[$k] = $b[$k];
	return wordpress_auto_set($row, $o);
}

function api_v1_wordpress_copies(array $ctx) {
	list($row, $e) = api_wp_find($ctx);
	if ($e) return $e;
	return wordpress_copy_status($row);
}

function api_v1_wordpress_copy(array $ctx) {
	list($row, $e) = api_wp_find($ctx);
	if ($e) return $e;
	$b = $ctx['body'];
	$o = array('kind' => api_str($b, 'kind', 10), 'domain' => api_str($b, 'domain', 255), 'restrict' => (bool)api_bool($b, 'restrict'));
	if (isset($b['allow_ips']))
		$o['allow_ips'] = is_array($b['allow_ips']) ? array_slice(array_map('strval', $b['allow_ips']), 0, 60) : api_str($b, 'allow_ips', 4000);
	$by = (string)($ctx['token']['account'] ?? '') !== '' ? $ctx['token']['account'] : 'api:'.(string)($ctx['token']['name'] ?? '');
	return wordpress_copy_start($row, $o, $by);
}

function api_v1_wordpress_copy_access(array $ctx) {
	list($row, $e) = api_wp_find($ctx);
	if ($e) return $e;
	$b = $ctx['body'];
	$ips = isset($b['allow_ips']) ? (is_array($b['allow_ips']) ? array_slice(array_map('strval', $b['allow_ips']), 0, 60) : api_str($b, 'allow_ips', 4000)) : array();
	return wordpress_copy_access_set($row, (bool)api_bool($b, 'on'), $ips);
}

function api_v1_wordpress_details(array $ctx) {
	list($row, $e) = api_wp_find($ctx);
	if ($e) return $e;
	return wordpress_details($row);
}

function api_v1_wordpress_push_preview(array $ctx) {
	list($row, $e) = api_wp_find($ctx);
	if ($e) return $e;
	return wordpress_push_preview($row);
}

function api_v1_wordpress_push(array $ctx) {
	list($row, $e) = api_wp_find($ctx);
	if ($e) return $e;
	$b = $ctx['body'];
	$list = function ($k) use ($b) { return isset($b[$k]) && is_array($b[$k]) ? array_slice(array_map('strval', $b[$k]), 0, 500) : array(); };
	$by = (string)($ctx['token']['account'] ?? '') !== '' ? $ctx['token']['account'] : 'api:'.(string)($ctx['token']['name'] ?? '');
	return wordpress_push_start($row, array('items' => $list('items'), 'switches' => $list('switches'), 'settings' => $list('settings'),
	                                         'uploads' => (bool)api_bool($b, 'uploads')), $by);
}

function api_v1_wordpress_copy_delete(array $ctx) {
	list($row, $e) = api_wp_find($ctx);
	if ($e) return $e;
	return wordpress_copy_delete($row);
}

function api_v1_wordpress_login(array $ctx) {
	list($row, $e) = api_wp_find($ctx);
	if ($e) return $e;
	return wordpress_login($row);
}

function api_v1_wordpress_show(array $ctx) {
	list($row, $e) = api_wp_find($ctx);
	if ($e) return $e;
	$r = wordpress_info($row, (bool)api_bool($ctx['query'], 'refresh'));
	$j = wordpress_job_latest($row['id']);
	$r['data']['job'] = $j ? wordpress_job_public($j, true) : null;
	return $r;
}

function api_v1_wordpress_job_start(array $ctx) {
	list($row, $e) = api_wp_find($ctx);
	if ($e) return $e;
	$b = $ctx['body'];
	$steps = array();
	foreach ((isset($b['steps']) && is_array($b['steps']) ? $b['steps'] : array()) as $st)
		if (is_array($st))
			$steps[] = array((string)($st['what'] ?? ''), $st['items'] ?? array());
	if (!$steps && isset($b['what']))
		$steps[] = array(api_str($b, 'what'), $b['items'] ?? array());
	$by = (string)($ctx['token']['account'] ?? '') !== '' ? $ctx['token']['account'] : 'api:'.(string)($ctx['token']['name'] ?? '');
	return wordpress_job_start($row, $steps, $by);
}

function api_v1_wordpress_job(array $ctx) {
	list($row, $e) = api_wp_find($ctx);
	if ($e) return $e;
	$j = ctype_digit((string)$ctx['params']['job']) ? wordpress_job_get($ctx['params']['job']) : null;
	if (!$j || (int)$j['site_id'] !== (int)$row['id'])
		return api_error(404, "No update '{$ctx['params']['job']}' of this site.");
	if (in_array($j['status'], array('queued', 'running'), true)) {
		wordpress_job_latest($row['id'], '');   // marks it failed when its unit is gone
		$j = wordpress_job_get($j['id']);
	}
	return result_ok('', wordpress_job_public($j, true));
}

function api_v1_wordpress_update(array $ctx) {
	list($row, $e) = api_wp_find($ctx);
	if ($e) return $e;
	$b = $ctx['body'];
	$items = isset($b['items']) ? (is_array($b['items']) ? $b['items'] : preg_split('/[\s,]+/', (string)$b['items'], -1, PREG_SPLIT_NO_EMPTY)) : array();
	return wordpress_update($row, api_str($b, 'what'), array_values(array_map('strval', $items)));
}

function api_v1_wordpress_plugin(array $ctx) {
	if (!in_array($ctx['params']['op'], array('activate', 'deactivate'), true))
		return api_error(404, 'Use /activate or /deactivate.');
	list($row, $e) = api_wp_find($ctx);
	if ($e) return $e;
	return wordpress_plugin_set($row, $ctx['params']['plugin'], $ctx['params']['op'] === 'activate');
}

function api_v1_wordpress_purge(array $ctx) {
	list($row, $e) = api_wp_find($ctx);
	if ($e) return $e;
	list($done, $warn) = wordpress_purge_caches($row);
	return result_ok($done ? 'Emptied on '.$row['domain'].': '.implode(', ', $done).'.' : 'No cache to empty on '.$row['domain'].'.',
		array('caches_emptied' => $done), $warn);
}

function api_v1_wordpress_verify(array $ctx) {
	list($row, $e) = api_wp_find($ctx);
	if ($e) return $e;
	return wordpress_verify($row);
}
