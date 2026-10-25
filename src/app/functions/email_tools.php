<?php
/*
 * The email tools beyond mailboxes, forwarders and autoresponders: a
 * mailbox's own filters, an account's spam settings, the mail DNS records of
 * a domain (MX, SPF, DKIM, DMARC), who handles its mail (this server or
 * another provider: mail_provider_*) and the delivery log.
 *
 * Shared by the API (app/api/routes.php, so also MCP and the account panel on
 * 2083) and the health checks; returns result_ok() / result_error(). The
 * functions take a name only - callers acting for an account ask
 * scope_check() first.
 */

/* The mail domains of a hosting account (/etc/exim/domains), sorted. */
function email_account_domains($account) {
	$out = array();
	foreach (explode("\n", trim((string)shell_exec('sudo -n ls -1 /etc/exim/domains/ 2>/dev/null'))) as $d) {
		$d = strtolower(trim($d));
		if (valid_domain($d) && scope_domain_owner($d) === $account)
			$out[] = $d;
	}
	sort($out);
	return $out;
}

/* ---- Mailbox filters --------------------------------------------------------
   A mailbox's own Sieve script (the "personal" tier, the one webmail's Filters
   page edits too), read and written as a list of rules by ef_read_account() /
   ef_write_account() (modules/functions.php). A script that is not in the
   rule shape (written by hand) is shown, but not changed here.
   Rules are numbered from 1 in the order they run. */

/* The actions a mailbox's own script may use. pipe is for the server-wide and
   domain tiers only (Dovecot refuses it in a personal script). */
function email_filter_actions() {
	return array('fileinto', 'redirect', 'reject', 'discard', 'keep', 'seen', 'flagged', 'addflag', 'setflag', 'removeflag');
}

/* A rule from the API's shape (name, enabled, match, stop, conditions:
   [{field, op, value}], actions: [{type, value}]) -> array(row, error), row as
   sieve_render_rules() takes it. $old: the rule it replaces (its fields are
   kept where $o leaves them out). $admin_tier: pipe allowed. */
function email_filter_rule(array $o, array $old = null, $admin_tier = false) {
	$name = array_key_exists('name', $o) ? trim((string)$o['name']) : ($old ? $old['name'] : '');
	if ($name === '' || strlen($name) > 120)
		return array(null, 'The filter needs a name of 1 to 120 characters.');
	/* the name is written in a `# rule:[...]` comment */
	if (strpbrk($name, "\r\n]") !== false)
		return array(null, 'The name of a filter cannot contain a line break or "]".');

	$conds = array_key_exists('conditions', $o) ? $o['conditions'] : ($old ? json_decode($old['conditions'], true) : array());
	if (!is_array($conds))
		return array(null, 'conditions must be a list.');
	$cl = array();
	foreach (array_values($conds) as $c) {
		if (!is_array($c))
			return array(null, 'Each condition needs a field, an operator and a value.');
		$f = trim((string)($c['field'] ?? ''));
		$op = trim((string)($c['op'] ?? 'contains'));
		$v = (string)($c['value'] ?? '');
		if ($f === '' && $v === '')
			continue;
		if (!ef_valid_field($f))
			return array(null, "\"$f\" is not a header name.");
		if (!ef_valid_op($op))
			return array(null, "Unknown condition \"$op\".");
		if ($f === 'size') {
			if (!ctype_digit(trim($v)))
				return array(null, 'A size condition needs a whole number of bytes.');
			$v = trim($v);
			if ($op !== 'over' && $op !== 'under')
				$op = 'over';
		} elseif ($v === '') {
			return array(null, "The condition on \"$f\" has no value.");
		} elseif ($op === 'over' || $op === 'under') {
			return array(null, 'Larger than / smaller than only works with the size.');
		}
		if (strlen($v) > 1000)
			return array(null, 'A condition can have up to 1000 characters.');
		if (($op === 'regex' || $op === 'not-regex') && @preg_match('/'.str_replace('/', '\/', $v).'/', '') === false)
			return array(null, 'That regular expression is not valid.');
		$cl[] = array('field' => $f, 'op' => $op, 'value' => $v);
	}

	$acts = array_key_exists('actions', $o) ? $o['actions'] : ($old ? json_decode($old['actions'], true) : array());
	if (!is_array($acts))
		return array(null, 'actions must be a list.');
	$allowed = $admin_tier ? array_merge(email_filter_actions(), array('pipe')) : email_filter_actions();
	$al = array();
	foreach (array_values($acts) as $a) {
		if (!is_array($a))
			return array(null, 'Each action needs a type.');
		$t = trim((string)($a['type'] ?? ''));
		$v = trim((string)($a['value'] ?? ''));
		if ($t === 'pipe' && !$admin_tier)
			return array(null, 'Sending mail to a program is set by the server\'s administrator.');
		if (!in_array($t, $allowed, true))
			return array(null, "Unknown action \"$t\".");
		if ($t === 'fileinto') {
			if ($v === '')
				return array(null, 'Choose the folder to move the message to.');
			if (strpbrk($v, "\r\n") !== false || strlen($v) > 255)
				return array(null, 'That folder name is not valid.');
		} elseif ($t === 'redirect') {
			$v = strtolower($v);
			if (!valid_email_address($v))
				return array(null, 'Forwarding needs an address like name@example.com.');
		} elseif ($t === 'reject') {
			if ($v === '')
				return array(null, 'Refusing a message needs the text the sender gets.');
			if (strlen($v) > 500)
				return array(null, 'The refusal text can have up to 500 characters.');
		} elseif ($t === 'pipe') {
			if (!in_array($v, ef_pipe_programs(), true))
				return array(null, "\"$v\" is not an installed program. Programs live in ".EF_PIPE_BIN_DIR.'.');
		} elseif ($t === 'addflag' || $t === 'setflag' || $t === 'removeflag') {
			if ($v === '' || strpbrk($v, "\r\n\"") !== false)
				return array(null, 'That IMAP flag is not valid.');
		} else {
			$v = '';
		}
		$al[] = array('type' => $t, 'value' => $v);
	}
	if (!$al)
		return array(null, 'A filter needs at least one action.');

	$b = function ($k, $def) use ($o) {
		if (!array_key_exists($k, $o)) return $def;
		$v = $o[$k];
		return is_bool($v) ? $v : in_array(strtolower(trim((string)$v)), array('1', 'true', 'yes', 'on'), true);
	};
	$match = array_key_exists('match', $o) ? ((string)$o['match'] === 'any' ? 'any' : 'all') : ($old ? $old['match_type'] : 'all');
	return array(array(
		'name'       => $name,
		'enabled'    => $b('enabled', $old ? (bool)$old['enabled'] : true) ? 1 : 0,
		'priority'   => isset($o['priority']) ? max(0, min(9999, (int)$o['priority'])) : ($old['priority'] ?? 10),
		'match_type' => $match,
		'stop'       => $b('stop', $old ? !empty($old['stop']) : false) ? 1 : 0,
		'conditions' => json_encode($cl),
		'actions'    => json_encode($al),
	), '');
}

/* One rule as the API shows it. */
function email_filter_out(array $r, $n) {
	return array('n' => $n, 'name' => (string)$r['name'], 'enabled' => (bool)$r['enabled'],
		'match' => $r['match_type'] === 'any' ? 'any' : 'all', 'stop' => !empty($r['stop']),
		'conditions' => json_decode($r['conditions'], true) ?: array(), 'actions' => json_decode($r['actions'], true) ?: array());
}

/* A mailbox's filters: editable (false = a hand-written script), rules,
   folders (for "move to folder"; $folders = false skips asking for them). */
function email_filter_list($email, $folders = true) {
	$email = strtolower(trim((string)$email));
	list($user, $domain) = email_split($email);
	if (!mailbox_exists($user, $domain))
		return result_error("$email is not a mailbox on this server.");
	$rules = sieve_parse_script(sieve_user_read($email), false);
	$out = array();
	if (is_array($rules))
		foreach (array_values($rules) as $i => $r)
			$out[] = email_filter_out($r, $i + 1);
	return result_ok('', array('email' => $email, 'editable' => is_array($rules), 'rules' => $out,
		'folders' => $folders ? sieve_user_folders($email) : array()));
}

/* The rules of a mailbox for a change, or a result_error(). */
function email_filter_rules($email) {
	list($user, $domain) = email_split($email);
	if (!mailbox_exists($user, $domain))
		return result_error("$email is not a mailbox on this server.");
	$rules = ef_read_account($email);
	if (!is_array($rules))
		return result_error("The filters of $email were written by hand, so they cannot be changed here. Change them in webmail (Settings > Filters).");
	return array_values($rules);
}

/* Write the rules back; a Sieve error leaves the old script in place. */
function email_filter_write($email, array $rules) {
	$e = ef_write_account($email, array_values($rules));
	return $e === '' ? '' : 'The filters could not be saved: '.trim(preg_replace('/\s+/', ' ', $e));
}

/* Move rule $from to position $to (both 0-based). */
function email_filter_move(array $rules, $from, $to) {
	$to = max(0, min(count($rules) - 1, (int)$to));
	$r = array_splice($rules, $from, 1);
	array_splice($rules, $to, 0, $r);
	return $rules;
}

/* Add a filter to a mailbox ($o: the rule, and position = its number, 1 =
   runs first; left out = last). */
function email_filter_create($email, array $o) {
	$email = strtolower(trim((string)$email));
	$rules = email_filter_rules($email);
	if (isset($rules['ok']))
		return $rules;
	list($row, $e) = email_filter_rule($o);
	if ($e !== '')
		return result_error($e);
	list(, $domain) = email_split($email);
	$owner = email_domain_owner($domain);
	if (($e = limit_allows($owner, 'mailbox_filter', array('email' => $email))) !== '')
		return result_error($e);
	$rules[] = $row;
	$n = count($rules);
	if (isset($o['position']) && (int)$o['position'] >= 1 && (int)$o['position'] < $n) {
		$rules = email_filter_move($rules, $n - 1, (int)$o['position'] - 1);
		$n = (int)$o['position'];
	}
	if (($e = email_filter_write($email, $rules)) !== '')
		return result_error($e);
	route_log("create mailbox filter $email: {$row['name']}");
	plugin_hook('mailbox_filter.created', array('email' => $email, 'name' => $row['name'], 'account' => $owner));
	return result_ok("Filter \"{$row['name']}\" saved for $email.", email_filter_out($row, $n));
}

/* Change filter number $n of a mailbox: the fields given replace the old
   ones; position moves it. expect_name: refuse when rule $n has another name
   (the script was changed elsewhere since it was read). */
function email_filter_edit($email, $n, array $o) {
	$email = strtolower(trim((string)$email));
	$rules = email_filter_rules($email);
	if (isset($rules['ok']))
		return $rules;
	$i = (int)$n - 1;
	if (!isset($rules[$i]))
		return result_error("$email has no filter number $n.");
	if (isset($o['expect_name']) && (string)$o['expect_name'] !== $rules[$i]['name'])
		return result_error('The filters were changed somewhere else in the meantime. Reload the page and try again.');
	list($row, $e) = email_filter_rule($o, $rules[$i]);
	if ($e !== '')
		return result_error($e);
	$rules[$i] = $row;
	if (isset($o['position']) && (int)$o['position'] >= 1) {
		$rules = email_filter_move($rules, $i, (int)$o['position'] - 1);
		$i = max(0, min(count($rules) - 1, (int)$o['position'] - 1));
	}
	if (($e = email_filter_write($email, $rules)) !== '')
		return result_error($e);
	route_log("edit mailbox filter $email: {$row['name']}");
	plugin_hook('mailbox_filter.changed', array('email' => $email, 'name' => $row['name']));
	return result_ok("Filter \"{$row['name']}\" saved for $email.", email_filter_out($row, $i + 1));
}

function email_filter_delete($email, $n, $expect_name = null) {
	$email = strtolower(trim((string)$email));
	$rules = email_filter_rules($email);
	if (isset($rules['ok']))
		return $rules;
	$i = (int)$n - 1;
	if (!isset($rules[$i]))
		return result_error("$email has no filter number $n.");
	if ($expect_name !== null && (string)$expect_name !== $rules[$i]['name'])
		return result_error('The filters were changed somewhere else in the meantime. Reload the page and try again.');
	$name = $rules[$i]['name'];
	array_splice($rules, $i, 1);
	if (($e = email_filter_write($email, $rules)) !== '')
		return result_error($e);
	route_log("delete mailbox filter $email: $name");
	plugin_hook('mailbox_filter.deleted', array('email' => $email, 'name' => $name));
	return result_ok("Filter \"$name\" deleted.", array('email' => $email, 'name' => $name));
}

/* ---- Domain filters ---------------------------------------------------------
   Rules for every mailbox of a domain: the "domain" tier of the email_filters
   table (the same rows 2087's Email Filters page edits), rendered to one
   Sieve script by sieve_write_system(). They run before each mailbox's own
   filters and its autoresponder. A rule that moves, forwards or deletes ends
   the chain only with stop; without it core adds `keep`, so a copy stays in
   the inbox and the mailbox's own filters still run (delete and refuse always
   end it). The "Move spam to Junk" rule (Spam protection) is shown but changed
   only from there. Rules are addressed by id; position = their order. */

/* A domain's filters in the order they run. folders: those of its first
   mailbox (for "move to folder"; $folders = false skips asking). */
function email_domain_filter_list($domain, $folders = true) {
	global $db;
	$domain = strtolower(trim((string)$domain));
	if (!valid_domain($domain) || !email_domain_exists($domain))
		return result_error("$domain does not have mail on this server.");
	$out = array();
	foreach (email_domain_filter_rows($domain) as $i => $r)
		$out[] = email_filter_out($r, $i + 1) + array('id' => (int)$r['id'], 'managed' => $r['name'] === SPAM_JUNK_RULE);
	$f = array();
	if ($folders)
		foreach (mailbox_list() as $m)
			if (substr($m, -strlen($domain) - 1) === '@'.$domain) {
				$f = sieve_user_folders($m);
				break;
			}
	return result_ok('', array('domain' => $domain, 'editable' => true, 'rules' => $out, 'folders' => $f));
}

function email_domain_filter_rows($domain) {
	global $db;
	$stmt = $db->prepare("SELECT * FROM email_filters WHERE scope = 'domain' AND target = :t ORDER BY priority ASC, id ASC");
	$stmt->bindValue(':t', $domain, SQLITE3_TEXT);
	$res = $stmt->execute();
	$rows = array();
	while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC)))
		$rows[] = $r;
	return $rows;
}

/* One domain filter by id, or null. */
function email_domain_filter_get($id) {
	global $db;
	$r = $db->querySingle("SELECT * FROM email_filters WHERE scope = 'domain' AND id = ".(int)$id, true);
	return $r ?: null;
}

/* Give the rows of $domain priorities 10, 20, ... in the order of $ids (the
   spam rule stays first), then rebuild the script. '' or the error. */
function email_domain_filter_order($domain, array $ids) {
	global $db;
	$rows = array();
	foreach (email_domain_filter_rows($domain) as $r)
		$rows[(int)$r['id']] = $r;
	$ids = array_values(array_unique(array_merge(array_intersect(array_map('intval', $ids), array_keys($rows)), array_keys($rows))));
	usort($ids, function ($a, $b) use ($rows, $ids) {
		$ma = $rows[$a]['name'] === SPAM_JUNK_RULE; $mb = $rows[$b]['name'] === SPAM_JUNK_RULE;
		return $ma !== $mb ? ($ma ? -1 : 1) : array_search($a, $ids) - array_search($b, $ids);
	});
	$stmt = $db->prepare('UPDATE email_filters SET priority = :p WHERE id = :i');
	foreach ($ids as $k => $id) {
		$stmt->bindValue(':p', ($k + 1) * 10, SQLITE3_INTEGER);
		$stmt->bindValue(':i', $id, SQLITE3_INTEGER);
		$stmt->execute();
		$stmt->reset();
	}
	return trim((string)sieve_write_system($db, 'domain', $domain));
}

/* Store a row (insert when $old is null) and rebuild the script; on a Sieve
   error the previous rows are put back. '' or the error. */
function email_domain_filter_store($domain, array $row, array $old = null) {
	global $db;
	$before = email_domain_filter_rows($domain);
	if ($old) {
		$stmt = $db->prepare('UPDATE email_filters SET name = :n, enabled = :e, match_type = :m, conditions = :c, actions = :a, stop = :st WHERE id = :i');
		$stmt->bindValue(':i', (int)$old['id'], SQLITE3_INTEGER);
	} else {
		$prio = 10;
		foreach ($before as $r)
			$prio = max($prio, (int)$r['priority'] + 10);
		$stmt = $db->prepare("INSERT INTO email_filters (scope, target, name, enabled, priority, match_type, conditions, actions, stop)
		                      VALUES ('domain', :t, :n, :e, :p, :m, :c, :a, :st)");
		$stmt->bindValue(':t', $domain, SQLITE3_TEXT);
		$stmt->bindValue(':p', $prio, SQLITE3_INTEGER);
	}
	foreach (array(':n' => $row['name'], ':m' => $row['match_type'], ':c' => $row['conditions'], ':a' => $row['actions']) as $k => $v)
		$stmt->bindValue($k, $v, SQLITE3_TEXT);
	$stmt->bindValue(':e', (int)$row['enabled'], SQLITE3_INTEGER);
	$stmt->bindValue(':st', (int)$row['stop'], SQLITE3_INTEGER);
	if (!@$stmt->execute())
		return 'The filter could not be saved.';
	$id = $old ? (int)$old['id'] : (int)$db->lastInsertRowID();
	$e = trim((string)sieve_write_system($db, 'domain', $domain));
	if ($e === '')
		return array('id' => $id);
	/* put the table back as it was, so it and the live script agree */
	$st = $db->prepare("DELETE FROM email_filters WHERE scope = 'domain' AND target = :t");
	$st->bindValue(':t', $domain, SQLITE3_TEXT);
	$st->execute();
	foreach ($before as $r) {
		$ins = $db->prepare('INSERT INTO email_filters (id, scope, target, name, enabled, priority, match_type, conditions, actions, stop, created_at)
		                     VALUES (:id, :s, :t, :n, :e, :p, :m, :c, :a, :st, :ca)');
		foreach (array(':id' => 'id', ':e' => 'enabled', ':p' => 'priority', ':st' => 'stop') as $k => $c)
			$ins->bindValue($k, (int)$r[$c], SQLITE3_INTEGER);
		foreach (array(':s' => 'scope', ':t' => 'target', ':n' => 'name', ':m' => 'match_type', ':c' => 'conditions', ':a' => 'actions', ':ca' => 'created_at') as $k => $c)
			$ins->bindValue($k, (string)$r[$c], SQLITE3_TEXT);
		$ins->execute();
	}
	sieve_write_system($db, 'domain', $domain);
	return 'The filter could not be saved: '.preg_replace('/\s+/', ' ', $e);
}

/* Is $name free on $domain (apart from rule $id)? */
function email_domain_filter_name_free($domain, $name, $id = 0) {
	foreach (email_domain_filter_rows($domain) as $r)
		if (strcasecmp($r['name'], $name) === 0 && (int)$r['id'] !== (int)$id)
			return false;
	return true;
}

/* Add a filter for every mailbox of $domain ($o: the rule as for
   email_filter_create(), position). $allow_pipe: the server admin only. */
function email_domain_filter_create($domain, array $o, $allow_pipe = false) {
	$domain = strtolower(trim((string)$domain));
	if (!valid_domain($domain) || !email_domain_exists($domain))
		return result_error("$domain does not have mail on this server.");
	list($row, $e) = email_filter_rule($o, null, $allow_pipe);
	if ($e !== '')
		return result_error($e);
	if ($row['name'] === SPAM_JUNK_RULE || !email_domain_filter_name_free($domain, $row['name']))
		return result_error("$domain already has a filter named \"{$row['name']}\". Choose another name.");
	$owner = email_domain_owner($domain);
	if (($e = limit_allows($owner, 'domain_filter', array('domain' => $domain))) !== '')
		return result_error($e);
	$r = email_domain_filter_store($domain, $row);
	if (is_string($r))
		return result_error($r);
	if (isset($o['position']) && (int)$o['position'] >= 1) {
		$ids = array_map(function ($x) { return (int)$x['id']; }, email_domain_filter_rows($domain));
		$ids = array_values(array_diff($ids, array($r['id'])));
		array_splice($ids, (int)$o['position'] - 1, 0, array($r['id']));
		email_domain_filter_order($domain, $ids);
	}
	route_log("create domain filter $domain: {$row['name']}");
	plugin_hook('domain_filter.created', array('domain' => $domain, 'id' => $r['id'], 'name' => $row['name'], 'account' => $owner));
	return result_ok("Filter \"{$row['name']}\" saved for all mailboxes of $domain.", array('id' => $r['id'], 'domain' => $domain));
}

/* Change domain filter $id: the fields given replace the old ones; position
   moves it (1 = runs first). The spam rule is changed from Spam protection. */
function email_domain_filter_edit($id, array $o, $allow_pipe = false) {
	$old = email_domain_filter_get($id);
	if (!$old)
		return result_error('That filter no longer exists. Reload the page.');
	$domain = $old['target'];
	if ($old['name'] === SPAM_JUNK_RULE)
		return result_error('This filter is set from Spam protection; change it there.');
	if (isset($o['expect_name']) && (string)$o['expect_name'] !== $old['name'])
		return result_error('The filters were changed somewhere else in the meantime. Reload the page and try again.');
	$move = isset($o['position']) ? (int)$o['position'] : 0;
	unset($o['position']);
	if (array_intersect(array_keys($o), array('name', 'conditions', 'actions', 'match', 'enabled', 'stop'))) {
		list($row, $e) = email_filter_rule($o, $old, $allow_pipe);
		if ($e !== '')
			return result_error($e);
		if ($row['name'] === SPAM_JUNK_RULE || !email_domain_filter_name_free($domain, $row['name'], $old['id']))
			return result_error("$domain already has a filter named \"{$row['name']}\". Choose another name.");
		$r = email_domain_filter_store($domain, $row, $old);
		if (is_string($r))
			return result_error($r);
	}
	if ($move >= 1) {
		$ids = array_map(function ($x) { return (int)$x['id']; }, email_domain_filter_rows($domain));
		$ids = array_values(array_diff($ids, array((int)$old['id'])));
		array_splice($ids, $move - 1, 0, array((int)$old['id']));
		if (($e = email_domain_filter_order($domain, $ids)) !== '')
			return result_error('The order could not be saved: '.$e);
	}
	$new = email_domain_filter_get($old['id']);
	route_log("edit domain filter $domain: {$new['name']}");
	plugin_hook('domain_filter.changed', array('domain' => $domain, 'id' => (int)$old['id'], 'name' => $new['name']));
	return result_ok("Filter \"{$new['name']}\" saved for all mailboxes of $domain.", array('id' => (int)$old['id'], 'domain' => $domain));
}

function email_domain_filter_delete($id, $expect_name = null) {
	global $db;
	$old = email_domain_filter_get($id);
	if (!$old)
		return result_error('That filter no longer exists. Reload the page.');
	if ($old['name'] === SPAM_JUNK_RULE)
		return result_error('This filter is set from Spam protection; turn it off there.');
	if ($expect_name !== null && (string)$expect_name !== $old['name'])
		return result_error('The filters were changed somewhere else in the meantime. Reload the page and try again.');
	$stmt = $db->prepare('DELETE FROM email_filters WHERE id = :i');
	$stmt->bindValue(':i', (int)$old['id'], SQLITE3_INTEGER);
	$stmt->execute();
	$e = trim((string)sieve_write_system($db, 'domain', $old['target']));
	route_log("delete domain filter {$old['target']}: {$old['name']}");
	plugin_hook('domain_filter.deleted', array('domain' => $old['target'], 'id' => (int)$old['id'], 'name' => $old['name']));
	return result_ok("Filter \"{$old['name']}\" deleted.", array('id' => (int)$old['id'], 'domain' => $old['target']),
		$e !== '' ? array("The domain's script could not be rebuilt: $e") : array());
}

/* ---- Spam settings of an account --------------------------------------------
   SpamAssassin scans inbound mail as the hosting account (exim acl_check_data),
   so the account is the finest grain: ~/.spamassassin/user_prefs holds its
   threshold (required_score) and its allow / block lists (sa_* in
   modules/functions.php). Spam is only tagged (X-Spam-Flag: YES); "move spam
   to Junk" is a rule on the domain tier of the email filters (email_filters
   table, one per mail domain of the account), named SPAM_JUNK_RULE. */

if (!defined('SPAM_JUNK_RULE'))
	define('SPAM_JUNK_RULE', 'Move spam to Junk');

/* The folder clients use for junk (the mailbox flagged \Junk in dovecot's
   config; setup_special_use.sh makes it "spam"). */
function spam_junk_folder() {
	$conf = (string)shell_exec('sudo -n doveconf -n 2>/dev/null');
	if (preg_match('/mailbox\s+"?([^\s"{]+)"?\s*\{[^}]*special_use\s*=\s*"?\\\\+Junk/', $conf, $m))
		return $m[1];
	return 'spam';
}

/* Does $domain have the "move spam to Junk" rule (an enabled one)? */
function spam_junk_get($domain) {
	global $db;
	$stmt = $db->prepare('SELECT enabled FROM email_filters WHERE scope = \'domain\' AND target = :t AND name = :n');
	$stmt->bindValue(':t', $domain, SQLITE3_TEXT);
	$stmt->bindValue(':n', SPAM_JUNK_RULE, SQLITE3_TEXT);
	$res = $stmt->execute();
	$r = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;
	return $r && $r['enabled'];
}

/* Add or remove the rule on one domain and rebuild its script. '' or the error. */
function spam_junk_set($domain, $on) {
	global $db;
	$del = $db->prepare('DELETE FROM email_filters WHERE scope = \'domain\' AND target = :t AND name = :n');
	$del->bindValue(':t', $domain, SQLITE3_TEXT);
	$del->bindValue(':n', SPAM_JUNK_RULE, SQLITE3_TEXT);
	$del->execute();
	if ($on) {
		/* first, and terminal (stop): the message goes to Junk only, and the
		   rules after it (the mailbox's own, the autoresponder) do not run */
		$stmt = $db->prepare('INSERT INTO email_filters (scope, target, name, enabled, priority, match_type, conditions, actions, stop)
		                      VALUES (\'domain\', :t, :n, 1, 0, \'all\', :c, :a, 1)');
		$stmt->bindValue(':t', $domain, SQLITE3_TEXT);
		$stmt->bindValue(':n', SPAM_JUNK_RULE, SQLITE3_TEXT);
		$stmt->bindValue(':c', json_encode(array(array('field' => 'X-Spam-Flag', 'op' => 'is', 'value' => 'YES'))), SQLITE3_TEXT);
		$stmt->bindValue(':a', json_encode(array(array('type' => 'fileinto', 'value' => spam_junk_folder()))), SQLITE3_TEXT);
		$stmt->execute();
	}
	$e = sieve_write_system($db, 'domain', $domain);
	if ($e !== '' && $on) {
		$del->execute();
		sieve_write_system($db, 'domain', $domain);
	}
	return trim((string)$e);
}

/* The server's threshold (local.cf required_score, or the old required_hits;
   SpamAssassin's own 5 when neither is set). */
function spam_default_score() {
	$c = (string)mail_config_read('spamassassin');
	$v = mail_setting_read($c, 'required_score', 'spamassassin');
	if ($v === null)
		$v = mail_setting_read($c, 'required_hits', 'spamassassin');
	return is_numeric(trim((string)$v)) ? (float)trim($v) : 5.0;
}

/* Does spamd answer where exim asks it (spamd_address, 127.0.0.1 783)? */
function spam_scanner_up() {
	$s = @fsockopen('127.0.0.1', 783, $errno, $errstr, 0.3);
	if (!$s)
		return false;
	fclose($s);
	return true;
}

/* An account's spam settings: score (its own threshold, null = the
   server's), default_score, allow, block, junk (spam goes to the Junk folder
   on all its mail domains), junk_folder, domains, running. */
function spam_settings_get($account) {
	$accts = sa_accounts();
	if (!isset($accts[$account]))
		return result_error("The account $account has no mail domain, so there is nothing to scan.");
	$p = sa_lists_get($account);
	$score = null;
	foreach ($p['other'] as $l)
		if (preg_match('/^\s*(required_score|required_hits)\s+(-?[0-9.]+)/i', $l, $m))
			$score = (float)$m[2];
	$junk = array();
	foreach ($accts[$account] as $d)
		$junk[$d] = spam_junk_get($d);
	return result_ok('', array('account' => $account, 'domains' => $accts[$account],
		'running' => spam_scanner_up(),
		'score' => $score, 'default_score' => spam_default_score(),
		'allow' => $p['white'], 'block' => $p['black'],
		'junk' => $junk && !in_array(false, $junk, true), 'junk_domains' => $junk));
}

/*
 * Change an account's spam settings. $o (any of):
 *   score  the threshold, 1-20 (lower = more mail counts as spam); null or
 *          'default' = the server's
 *   allow  the senders never treated as spam (the whole list)
 *   block  the senders always treated as spam (the whole list)
 *   junk   true = spam goes to the Junk folder of every mailbox
 * Senders: name@example.com, *@example.com or example.com.
 */
function spam_settings_set($account, array $o) {
	$accts = sa_accounts();
	if (!isset($accts[$account]))
		return result_error("The account $account has no mail domain, so there is nothing to scan.");
	$changed = array();
	if (array_key_exists('score', $o) || array_key_exists('allow', $o) || array_key_exists('block', $o)) {
		$cur = sa_lists_get($account);
		$lists = array('white' => $cur['white'], 'black' => $cur['black']);
		foreach (array('allow' => 'white', 'block' => 'black') as $k => $l) {
			if (!array_key_exists($k, $o))
				continue;
			$in = is_array($o[$k]) ? $o[$k] : preg_split('/[\s,;]+/', (string)$o[$k]);
			$new = array();
			foreach ($in as $p) {
				if (!is_scalar($p) || trim((string)$p) === '')
					continue;
				$n = sa_normalize_pattern($p);
				if ($n === '')
					return result_error('"'.trim((string)$p).'" is not a sender. Use name@example.com, *@example.com or example.com.');
				$new[] = $n;
			}
			if (count($new) > 500)
				return result_error('A list can have up to 500 senders.');
			$lists[$l] = array_values(array_unique($new));
			$changed[] = $k === 'allow' ? 'allow list' : 'block list';
		}
		/* the same sender on both: the list being saved wins */
		if (array_key_exists('allow', $o) && !array_key_exists('block', $o))
			$lists['black'] = array_values(array_diff($lists['black'], $lists['white']));
		elseif (array_key_exists('block', $o) && !array_key_exists('allow', $o))
			$lists['white'] = array_values(array_diff($lists['white'], $lists['black']));
		elseif ($both = array_intersect($lists['white'], $lists['black']))
			return result_error(reset($both).' is on both lists.');
		$other = $cur['other'];
		if (array_key_exists('score', $o)) {
			$s = $o['score'];
			if ($s !== null && $s !== '' && strtolower((string)$s) !== 'default') {
				if (!is_numeric($s) || (float)$s < 1 || (float)$s > 20)
					return result_error('The spam score must be a number from 1 to 20.');
				$s = round((float)$s, 1);
			} else {
				$s = null;
			}
			$other = array_values(array_filter($other, function ($l) { return !preg_match('/^\s*(required_score|required_hits)\s/i', $l); }));
			if ($s !== null)
				array_unshift($other, 'required_score '.number_format($s, 1, '.', ''));
			$changed[] = 'spam score';
		}
		$src = sa_render_prefs($other, $lists['white'], $lists['black']);
		$out = trim($src) === '' ? sa_helper(array('delete', $account), null, $rc) : sa_helper(array('put', $account), $src, $rc);
		if ($rc !== 0)
			return result_error('The spam settings could not be saved: '.trim($out));
	}
	if (array_key_exists('junk', $o)) {
		$on = is_bool($o['junk']) ? $o['junk'] : in_array(strtolower(trim((string)$o['junk'])), array('1', 'true', 'yes', 'on'), true);
		foreach ($accts[$account] as $d)
			if (spam_junk_get($d) !== $on && ($e = spam_junk_set($d, $on)) !== '')
				return result_error("Spam could not be ".($on ? 'sent to' : 'kept out of')." the Junk folder for $d: $e");
		$changed[] = $on ? 'spam to Junk on' : 'spam to Junk off';
	}
	if (!$changed)
		return result_error('Nothing to change: send score, allow, block or junk.');
	route_log("spam settings $account: ".implode(', ', $changed));
	plugin_hook('spam.changed', array('account' => $account, 'changed' => $changed));
	$r = spam_settings_get($account);
	return result_ok('Spam settings saved.', $r['data']);
}

/* ---- Mail DNS: MX, SPF, DKIM, DMARC -----------------------------------------
   Asked at the domain's own name server (a fix shows at once, no resolver
   cache in the way), in one dig per domain, the domains in parallel. */

/* Run dig for each job (key => arguments, already escaped) at the same time. */
function mail_dns_dig(array $jobs) {
	$procs = array();
	foreach ($jobs as $k => $args) {
		$p = proc_open('dig +time=3 +tries=1 '.$args, array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes);
		if (is_resource($p))
			$procs[$k] = array($p, $pipes[1]);
	}
	$out = array();
	foreach ($procs as $k => $pp) {
		$out[$k] = (string)stream_get_contents($pp[1]);
		fclose($pp[1]);
		proc_close($pp[0]);
	}
	return $out;
}

/* dig +short output -> its lines (no ";; timed out" comments). */
function mail_dns_short($out) {
	return array_values(array_filter(array_map('trim', explode("\n", (string)$out)), function ($l) { return $l !== '' && $l[0] !== ';'; }));
}

/* The DKIM selector of a domain (accounts.dkim_selector for main domains). */
function mail_dns_selector($domain) {
	global $db;
	$stmt = $db->prepare('SELECT dkim_selector FROM accounts WHERE domain = :d');
	$stmt->bindValue(':d', $domain, SQLITE3_TEXT);
	$res = $stmt->execute();
	$r = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;
	return $r && preg_match('/^[a-z0-9][a-z0-9._-]{0,62}$/i', (string)$r['dkim_selector']) ? (string)$r['dkim_selector'] : 'default';
}

/* This server's public DKIM key for $domain (base64, one line), '' = none. */
function mail_dns_local_key($domain) {
	$key = (string)shell_exec('sudo -n cat '.escapeshellarg('/etc/exim/keys/'.$domain.'.public.key').' 2>/dev/null');
	return preg_replace('/-----[A-Z ]+-----|\s/', '', $key);
}

/* The records this server would add (what "fix" writes). */
function mail_dns_expected($domain, $selector, $key) {
	return array(
		'mx'    => array('name' => $domain, 'type' => 'MX', 'value' => '0 '.$domain),
		'spf'   => array('name' => $domain, 'type' => 'TXT', 'value' => 'v=spf1 +a +mx ~all'),
		'dkim'  => array('name' => $selector.'._domainkey.'.$domain, 'type' => 'TXT', 'value' => $key !== '' ? 'v=DKIM1; k=rsa; p='.$key.';' : ''),
		'dmarc' => array('name' => '_dmarc.'.$domain, 'type' => 'TXT', 'value' => 'v=DMARC1;p=none;sp=none;adkim=r;aspf=r;pct=100;fo=0;rf=afrf;ri=86400'),
	);
}

/*
 * Check the mail records of several domains. Returns domain => array(
 *   ns, mx (list of {priority, host}), mx_local (the best MX is this server),
 *   spf (the record, '' = none; spf_count = how many), dkim ('' = none),
 *   dkim_selector, dkim_match (true/false; null = not compared: mail is
 *   handled elsewhere or this server has no key), dmarc, dmarc_policy,
 *   checks (mx|spf|dkim|dmarc => {state: ok|warn|bad|info, text}),
 *   missing (the problems, as the health check words them),
 *   fixable (what mail_dns_fix() would add), expected (the records to add)).
 */
function mail_dns_check_many(array $domains) {
	$domains = array_values(array_unique(array_filter(array_map('strtolower', $domains), 'valid_domain')));
	if (!$domains)
		return array();
	$ns = array();
	foreach (mail_dns_dig(array_combine($domains, array_map(function ($d) { return '+short NS '.escapeshellarg($d); }, $domains))) as $d => $o) {
		$l = mail_dns_short($o);
		$ns[$d] = $l ? rtrim($l[0], '.') : '';
	}
	$sel = array();
	$jobs = array();
	foreach ($domains as $d) {
		$sel[$d] = mail_dns_selector($d);
		$jobs[$d] = '+noall +answer'.($ns[$d] !== '' ? ' '.escapeshellarg('@'.$ns[$d]) : '')
			.' '.escapeshellarg($d).' MX '.escapeshellarg($d).' TXT '.escapeshellarg($d).' A '
			.escapeshellarg($sel[$d].'._domainkey.'.$d).' TXT '.escapeshellarg('_dmarc.'.$d).' TXT';
	}
	$ans = mail_dns_dig($jobs);
	$rec = array();
	$mxhosts = array();
	foreach ($domains as $d) {
		$rec[$d] = array('mx' => array(), 'txt' => array(), 'dkim' => array(), 'dmarc' => array(), 'a' => array());
		foreach (explode("\n", (string)($ans[$d] ?? '')) as $line) {
			if (!preg_match('/^(\S+)\s+\d+\s+IN\s+(MX|TXT|CNAME|A)\s+(.*)$/i', trim($line), $m))
				continue;
			$name = strtolower(rtrim($m[1], '.'));
			$type = strtoupper($m[2]);
			$val = trim($m[3]);
			if ($type === 'TXT' && preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $val, $mm))
				$val = stripcslashes(implode('', $mm[1]));
			if ($name === $d && $type === 'MX') {
				$p = preg_split('/\s+/', $val);
				if (count($p) >= 2)
					$rec[$d]['mx'][] = array('priority' => (int)$p[0], 'host' => strtolower(rtrim($p[1], '.')));
			} elseif ($name === $d && $type === 'TXT') {
				$rec[$d]['txt'][] = $val;
			} elseif ($name === $d && $type === 'A') {
				$rec[$d]['a'][] = $val;
			} elseif ($name === $sel[$d].'._domainkey.'.$d) {
				$rec[$d]['dkim'][] = $type === 'CNAME' ? 'CNAME '.rtrim($val, '.') : $val;
			} elseif ($name === '_dmarc.'.$d && $type === 'TXT') {
				$rec[$d]['dmarc'][] = $val;
			}
		}
		usort($rec[$d]['mx'], function ($a, $b) { return $a['priority'] - $b['priority']; });
		foreach ($rec[$d]['mx'] as $mx)
			if ($mx['priority'] === $rec[$d]['mx'][0]['priority'])
				$mxhosts[$mx['host']] = true;
	}
	$ips = array();
	if ($mxhosts) {
		$hosts = array_keys($mxhosts);
		foreach (mail_dns_dig(array_combine($hosts, array_map(function ($h) { return '+short A '.escapeshellarg($h); }, $hosts))) as $h => $o)
			$ips[$h] = mail_dns_short($o);
	}
	$local_ips = array();
	foreach (explode("\n", (string)shell_exec('/usr/sbin/ip -4 -o address show scope global 2>/dev/null')) as $l)
		if (preg_match('/inet ([0-9.]+)\//', $l, $m))
			$local_ips[] = $m[1];

	$out = array();
	foreach ($domains as $d) {
		$r = $rec[$d];
		$local = false;
		foreach ($r['mx'] as $mx)
			if ($mx['priority'] === $r['mx'][0]['priority'] && array_intersect($ips[$mx['host']] ?? array(), $local_ips))
				$local = true;
		$spf = array_values(array_filter($r['txt'], function ($t) { return stripos(ltrim($t), 'v=spf1') === 0; }));
		$mxips = array();   // addresses of its best MX hosts (the SPF "mx" mechanism)
		foreach ($r['mx'] as $mx)
			if ($mx['priority'] === $r['mx'][0]['priority'])
				$mxips = array_merge($mxips, $ips[$mx['host']] ?? array());
		$dkim = implode('', $r['dkim']);
		$dmarc = '';
		foreach ($r['dmarc'] as $t)
			if (stripos(ltrim($t), 'v=DMARC1') === 0)
				$dmarc = $t;
		$key = mail_dns_local_key($d);
		$match = null;
		if ($dkim !== '' && $local && $key !== '' && strpos($dkim, 'CNAME ') !== 0) {
			$p = preg_match('/(?:^|;)\s*p=([^;]*)/', str_replace(array('"', ' '), '', $dkim), $m) ? $m[1] : '';
			$match = $p === $key;
		}
		$checks = $missing = $fix = array();
		if (!$r['mx']) {
			$checks['mx'] = array('state' => 'bad', 'text' => "There is no MX record: mail to @$d cannot be delivered.");
			$fix[] = 'mx';
		} elseif ($local) {
			$checks['mx'] = array('state' => 'ok', 'text' => "Mail to @$d comes to this server.");
		} else {
			$checks['mx'] = array('state' => 'info', 'text' => "Mail to @$d goes to another server ({$r['mx'][0]['host']}), so the mailboxes here do not get it.");
		}
		if (!$spf) {
			$checks['spf'] = array('state' => 'warn', 'text' => 'No SPF record: receivers cannot tell which servers may send mail for this domain.');
			$missing[] = 'SPF missing';
			$fix[] = 'spf';
		} elseif (count($spf) > 1) {
			$checks['spf'] = array('state' => 'bad', 'text' => 'There are '.count($spf).' SPF records; receivers ignore all of them. Keep one.');
			$missing[] = 'more than one SPF record';
		} elseif (mail_dns_spf_allows($spf[0], $local_ips, $r['a'], $mxips) === false) {
			$checks['spf'] = array('state' => 'bad', 'text' => 'The SPF record does not list this server, so the mail it sends for this domain fails the check. Add +a +mx (or ip4:'.($local_ips[0] ?? '').') to it.');
			$missing[] = 'SPF does not allow this server';
		} else {
			$checks['spf'] = array('state' => 'ok', 'text' => 'The servers that may send for this domain are listed.');
		}
		if ($dkim === '') {
			$checks['dkim'] = array('state' => 'warn', 'text' => 'No DKIM record: mail from this domain is not signed in a way receivers can check.');
			$missing[] = 'DKIM missing';
			if ($key !== '')
				$fix[] = 'dkim';
		} elseif ($match === false) {
			$checks['dkim'] = array('state' => 'bad', 'text' => 'The DKIM record does not match the key this server signs with, so signed mail fails the check.');
			$missing[] = "DKIM does not match this server's key";
			$fix[] = 'dkim';
		} else {
			$checks['dkim'] = array('state' => 'ok', 'text' => 'Mail is signed, and receivers can check the signature.');
		}
		$policy = preg_match('/(?:^|;)\s*p\s*=\s*(\w+)/i', $dmarc, $m) ? strtolower($m[1]) : '';
		if ($dmarc === '') {
			$checks['dmarc'] = array('state' => 'warn', 'text' => 'No DMARC record: Gmail and Yahoo ask for one from domains that send a lot of mail.');
			$missing[] = 'DMARC missing';
			$fix[] = 'dmarc';
		} else {
			$checks['dmarc'] = array('state' => 'ok', 'text' => $policy === 'none' ? 'Present, in monitoring mode (p=none): failing mail is still delivered.'
				: "Present, failing mail is ".($policy === 'reject' ? 'refused' : 'sent to spam')." (p=$policy).");
		}
		$out[$d] = array('domain' => $d, 'ns' => $ns[$d], 'mx' => $r['mx'], 'mx_local' => $local,
			'spf' => $spf[0] ?? '', 'spf_count' => count($spf), 'dkim' => $dkim, 'dkim_selector' => $sel[$d], 'dkim_match' => $match,
			'dmarc' => $dmarc, 'dmarc_policy' => $policy, 'checks' => $checks, 'missing' => $missing, 'fixable' => $fix,
			'expected' => mail_dns_expected($d, $sel[$d], $key));
	}
	return $out;
}

/* Does an SPF record let this server send? true, false, or null when it
   cannot be told without following include:/redirect= (taken as yes).
   $a: the domain's A addresses, $mx: its MX hosts' addresses. */
function mail_dns_spf_allows($spf, array $local_ips, array $a, array $mx) {
	$maybe = false;
	foreach (preg_split('/\s+/', strtolower(trim($spf))) as $t) {
		if ($t === '' || $t === 'v=spf1')
			continue;
		$q = in_array($t[0], array('+', '-', '~', '?'), true) ? $t[0] : '+';
		$m = ltrim($t, '+-~?');
		if ($q === '-' || $q === '~') {
			if ($m === 'all')
				break;
			continue;
		}
		if ($m === 'all')
			return true;
		if ($m === 'a' && array_intersect($a, $local_ips))
			return true;
		if ($m === 'mx' && array_intersect($mx, $local_ips))
			return true;
		if (preg_match('#^ip4:([0-9.]+)(?:/([0-9]{1,2}))?$#', $m, $x)) {
			$bits = isset($x[2]) ? (int)$x[2] : 32;
			$mask = $bits === 0 ? 0 : (~0 << (32 - $bits)) & 0xFFFFFFFF;
			foreach ($local_ips as $ip)
				if ((ip2long($ip) & $mask) === (ip2long($x[1]) & $mask))
					return true;
		}
		if (strpos($m, 'include:') === 0 || strpos($m, 'redirect=') === 0 || strpos($m, 'exists:') === 0
		    || strpos($m, 'a:') === 0 || strpos($m, 'mx:') === 0 || strpos($m, 'a/') === 0 || strpos($m, 'mx/') === 0)
			$maybe = true;
	}
	return $maybe ? null : false;
}

function mail_dns_check($domain) {
	$r = mail_dns_check_many(array($domain));
	return $r[strtolower($domain)] ?? null;
}

/* Add the missing mail records of $domain at the server's DNS provider: MX
   (when there is none), SPF, DKIM (also when it does not match this server's
   key while mail comes here), DMARC. Records that are there are left alone. */
function mail_dns_fix($domain) {
	global $db;
	$domain = strtolower(trim((string)$domain));
	if (!valid_domain($domain) || !email_domain_exists($domain))
		return result_error("$domain does not have mail on this server.");
	$c = mail_dns_check($domain);
	if (!$c['fixable'])
		return result_ok("Nothing to add for $domain.", array('check' => $c));
	if (dns_provider_load($db) === 'none')
		return result_error("This server does not manage DNS, so the records cannot be added from here. Add them where the DNS of $domain is hosted.",
			array('check' => $c));
	$fn = array('mx' => 'add_update_local_mx', 'spf' => 'add_update_spf', 'dkim' => 'add_update_dkim', 'dmarc' => 'add_update_dmarc');
	$done = $errs = array();
	foreach ($c['fixable'] as $k) {
		$e = trim((string)call_user_func($fn[$k], $domain));
		if (stripos($e, 'error') === 0)
			$errs[] = strtoupper($k).': '.trim(preg_replace('/^error:\s*/i', '', $e));
		else
			$done[] = strtoupper($k);
	}
	route_log("mail dns fix $domain: ".implode(', ', $done).($errs ? ' / failed: '.implode('; ', $errs) : ''));
	if ($done)
		plugin_hook('mail_dns.fixed', array('domain' => $domain, 'records' => $done));
	$after = mail_dns_check($domain);
	if (!$done)
		return result_error('The records could not be added: '.implode('; ', $errs), array('check' => $after));
	return result_ok('Added for '.$domain.': '.implode(', ', $done).'.', array('check' => $after, 'added' => $done),
		$errs ? array('Not added: '.implode('; ', $errs)) : array());
}

/* ---- Mail provider: this server or another one --------------------------------
   A domain whose mail another provider handles (Google Workspace, Microsoft
   365, Zoho Mail, any other) is a line of /etc/exim/remotedomains: exim then
   sends the mail this server writes to it (a site's contact form, a mailbox
   here writing to a colleague) to its MX instead of the mailboxes here
   (scripts/update/setup_exim_remote_domains.sh). Its mailboxes, forwarders
   and DKIM key stay: the mailboxes can still be read, and the mail the server
   sends for the domain is still signed. The DNS part (MX, SPF, the
   provider's extras, a verification code) is written when the zone is one
   of the account's zones at the DNS provider, else returned as the records
   to set where the DNS is. SPF always keeps this server (the site's own
   mail). Callers acting for an account ask scope_check($account, 'domain'). */

/* The providers offered. mx: "priority host" ({dash} = the domain with - for
   dots, {zoho} = the Zoho region's domain); spf: the include; match: their MX
   hosts; cname: extra records name => target. */
function mail_providers() {
	return array(
		'local'     => array('name' => 'This server'),
		'google'    => array('name' => 'Google Workspace', 'mx' => array('1 smtp.google.com'), 'spf' => 'include:_spf.google.com',
		                     'match' => '/(^|\.)(google|googlemail)\.com$/', 'verify' => 'google-site-verification=...'),
		'microsoft' => array('name' => 'Microsoft 365', 'mx' => array('0 {dash}.mail.protection.outlook.com'), 'spf' => 'include:spf.protection.outlook.com',
		                     'match' => '/(\.outlook\.com|\.mx\.microsoft)$/', 'cname' => array('autodiscover' => 'autodiscover.outlook.com'), 'verify' => 'MS=ms...'),
		'zoho'      => array('name' => 'Zoho Mail', 'mx' => array('10 mx.{zoho}', '20 mx2.{zoho}', '50 mx3.{zoho}'), 'spf' => 'include:{zoho}',
		                     'match' => '/(^|\.)(zoho|zohocloud)\.[a-z.]+$/', 'verify' => 'zoho-verification=...',
		                     'regions' => array('zoho.com' => 'United States (zoho.com)', 'zoho.eu' => 'Europe (zoho.eu)', 'zoho.in' => 'India (zoho.in)',
		                                        'zoho.com.au' => 'Australia (zoho.com.au)', 'zoho.jp' => 'Japan (zoho.jp)', 'zohocloud.ca' => 'Canada (zohocloud.ca)',
		                                        'zoho.sa' => 'Saudi Arabia (zoho.sa)', 'zoho.uk' => 'United Kingdom (zoho.uk)')),
		'other'     => array('name' => 'Another provider'),
	);
}

/* The domains in /etc/exim/remotedomains. */
function mail_remote_list() {
	$out = array();
	foreach (explode("\n", (string)shell_exec('sudo -n cat /etc/exim/remotedomains 2>/dev/null')) as $l)
		if (($l = strtolower(trim($l))) !== '' && $l[0] !== '#')
			$out[$l] = true;
	return array_keys($out);
}

/* Put $domain on the list (its mail is handled elsewhere) or take it off. */
function mail_remote_set($domain, $remote) {
	$domain = strtolower(trim((string)$domain));
	if (!valid_domain($domain))
		return;
	$on = in_array($domain, mail_remote_list(), true);
	if ($remote && !$on)
		shell_exec('echo '.escapeshellarg($domain).' | sudo -n tee --append /etc/exim/remotedomains > /dev/null');
	elseif (!$remote && $on)
		shell_exec('sudo -n sed -i '.escapeshellarg('/^'.sed_bre_escape($domain).'$/d').' /etc/exim/remotedomains');
}

/* Which provider MX hosts belong to: a mail_providers() key ('local' when
   the best MX is this server, '' when there is no MX). */
function mail_provider_detect(array $mx, $mx_local) {
	if (!$mx)
		return '';
	if ($mx_local)
		return 'local';
	foreach (mail_providers() as $k => $p)
		if (isset($p['match']) && preg_match($p['match'], (string)$mx[0]['host']))
			return $k;
	return 'other';
}

/* The account's zone that holds $domain at the DNS provider ('' = none). */
function mail_provider_zone($account, $domain) {
	if (!function_exists('dns_account_zones') || !dns_managed())
		return '';
	$z = dns_account_zones($account);
	return is_array($z) ? dns_zone_for($domain, array_flip($z['zones'])) : '';
}

/* How the mail of the account's sites is handled: list of {domain, mail_here
   (mail is on for it on this server), remote (on the list), provider,
   provider_name, mx [{priority, host}], mx_local, mailboxes (how many here),
   zone (its zone at the DNS provider, '' = elsewhere)}. Asks the domains'
   name servers (one dig round), so pages read it after they are shown. */
function mail_provider_status($account) {
	$sites = array_column(domain_sites($account, false), 'domain');
	$ud = email_userdomains();
	foreach ($ud as $d => $u)
		if ($u === $account && !in_array($d, $sites, true))
			$sites[] = $d;
	$zones = array();
	if (function_exists('dns_account_zones') && dns_managed() && is_array($z = dns_account_zones($account)))
		$zones = array_flip($z['zones']);
	/* a zone at the DNS provider is read there (what was just set, not what
	   resolvers still keep); the others at their name servers */
	$sets = $checks = array();
	$ask = array();
	foreach ($sites as $d)
		if (($zn = $zones ? dns_zone_for($d, $zones) : '') !== '') {
			if (!isset($sets[$zn]))
				$sets[$zn] = dns_zone_sets($zn);
			if (is_array($sets[$zn])) {
				$checks[$d] = mail_provider_zone_mx($sets[$zn], $d);
				continue;
			}
		} else
			$ask[] = $d;
	$checks += mail_dns_check_many($ask);
	$remote = mail_remote_list();
	$boxes = array();
	foreach (mailbox_list() as $m) {
		$d = substr(strrchr($m, '@'), 1);
		$boxes[$d] = ($boxes[$d] ?? 0) + 1;
	}
	$names = array();
	foreach (mail_providers() as $k => $p)
		$names[$k] = $p['name'];
	$out = array();
	foreach ($sites as $d) {
		$c = $checks[$d] ?? array('mx' => array(), 'mx_local' => false);
		$here = ($ud[$d] ?? '') === $account;
		$prov = mail_provider_detect($c['mx'], $c['mx_local']);
		$out[] = array('domain' => $d, 'mail_here' => $here, 'remote' => in_array($d, $remote, true), 'provider' => $prov,
		               'provider_name' => $names[$prov] ?? '', 'mx' => $c['mx'], 'mx_local' => (bool)$c['mx_local'],
		               'mailboxes' => (int)($boxes[$d] ?? 0), 'zone' => $zones ? dns_zone_for($d, $zones) : '');
	}
	return $out;
}

/* The MX of $domain from its zone's record sets: {mx: [{priority, host}],
   mx_local (the best one is this server: the domain, its mail. name, the
   host name, or a name of the zone whose A is one of this server's)}. */
function mail_provider_zone_mx(array $sets, $domain) {
	list($vals) = dns_set_of($sets, $domain, 'MX');
	$mx = array();
	foreach ($vals as $v)
		if ($p = dns_value_parts('MX', $v))
			$mx[] = array('priority' => (int)$p[0], 'host' => $p[1]);
	usort($mx, function ($a, $b) { return $a['priority'] - $b['priority']; });
	$ips = domain_server_ips();
	$local = false;
	foreach ($mx as $m) {
		if ($m['priority'] !== $mx[0]['priority'])
			break;
		$h = $m['host'];
		for ($i = 0; $i < 5 && ($c = dns_set_of($sets, $h, 'CNAME')[0]); $i++)
			$h = $c[0];
		if (in_array($h, array($domain, 'mail.'.$domain, strtolower((string)gethostname())), true) || array_intersect(dns_set_of($sets, $h, 'A')[0], $ips))
			$local = true;
	}
	return array('mx' => $mx, 'mx_local' => $local);
}

/* The SPF record for a provider: the old one's own terms kept, other
   providers' includes and (for another provider) "mx" taken out, this
   server kept (+a, or its address), the provider's include added. */
function mail_provider_spf($old, $include, $remote, $ip) {
	$known = array();
	foreach (mail_providers() as $p) {
		if (isset($p['spf']) && strpos($p['spf'], '{zoho}') === false)
			$known[] = strtolower($p['spf']);
		foreach (array_keys($p['regions'] ?? array()) as $r)
			$known[] = 'include:'.$r;
	}
	$terms = array();
	$all = '~all';
	$server = false;
	foreach (preg_split('/\s+/', strtolower(trim($old !== '' ? $old : 'v=spf1 +a +mx ~all'))) as $t) {
		if ($t === '' || $t === 'v=spf1')
			continue;
		$m = ltrim($t, '+');
		if (preg_match('/^[-~?]?all$/', $m)) {
			$all = $t[0] === '+' ? '~all' : $t;
			continue;
		}
		if (in_array($m, $known, true) || ($include !== '' && $m === strtolower($include)) || ($remote && $m === 'mx'))
			continue;
		if ($m === 'a' || $m === 'ip4:'.$ip)
			$server = true;
		$terms[] = $t;
	}
	if (!$remote && !in_array('mx', array_map(function ($t) { return ltrim($t, '+'); }, $terms), true))
		array_unshift($terms, '+mx');
	if (!$server)
		array_unshift($terms, $ip !== '' ? 'ip4:'.$ip : '+a');
	if ($remote && $include !== '')
		$terms[] = $include;
	return 'v=spf1 '.implode(' ', $terms).' '.$all;
}

/* What switching $domain to $o['provider'] means: array(error, plan) with
   plan = {provider, name, remote, records: [{name, type, values, was}]}. $o:
   provider, region (Zoho), mx (list of "priority host": another provider,
   or a Microsoft 365 address of its own), spf (another provider's include),
   verify (a verification code for its TXT record). $sets: the zone's record
   sets when it is at the DNS provider (null = ask its name servers). */
function mail_provider_plan($account, $domain, array $o, array $sets = null) {
	$all = mail_providers();
	$id = (string)($o['provider'] ?? '');
	if (!isset($all[$id]))
		return array('Choose a mail provider: '.implode(', ', array_keys($all)).'.', null);
	$p = $all[$id];
	$remote = $id !== 'local';
	$zoho = '';
	if ($id === 'zoho') {
		$zoho = (string)($o['region'] ?? 'zoho.com');
		if (!isset($p['regions'][$zoho]))
			return array('Choose the Zoho region your account is in.', null);
	}
	$fill = function ($s) use ($domain, $zoho) { return str_replace(array('{dash}', '{zoho}'), array(str_replace('.', '-', $domain), $zoho), $s); };

	/* MX */
	$mx = array();
	$given = array_values(array_filter(array_map('trim', is_array($o['mx'] ?? null) ? $o['mx'] : preg_split('/[\r\n,]+/', (string)($o['mx'] ?? ''))), 'strlen'));
	if ($id === 'local')
		$mx = array('0 '.$domain);
	elseif ($id === 'other' || ($id === 'microsoft' && $given))
		$mx = $given;
	else
		$mx = array_map($fill, $p['mx']);
	if (!$mx)
		return array('Enter the mail servers (MX) your provider gave you, such as 10 mx1.example.net.', null);
	$vals = array();
	foreach ($mx as $line) {
		if (preg_match('/^[a-z0-9.-]+\.?$/i', $line))
			$line = '10 '.$line;
		list($v, $e) = dns_record_value($domain, $domain, 'MX', $line);
		if ($e !== '')
			return array($e.' ('.$line.')', null);
		$vals[] = $v;
	}
	$mx = array_values(array_unique($vals));
	if ($remote) {
		$here = array_map('strtolower', array_merge(array($domain, 'mail.'.$domain, (string)gethostname())));
		foreach ($mx as $v)
			if (in_array(dns_value_parts('MX', $v)[1], $here, true))
				return array('These mail servers are this server. Choose "This server" instead.', null);
	}

	/* SPF include */
	$inc = isset($p['spf']) ? $fill($p['spf']) : '';
	if ($id === 'other' && ($s = strtolower(trim((string)($o['spf'] ?? '')))) !== '') {
		$s = preg_replace('/^include:/', '', $s);
		if (dns_host_ok($s) === '')
			return array('The SPF include is a name, such as spf.example.net.', null);
		$inc = 'include:'.dns_host_ok($s);
	}

	/* what is there now */
	$txt = $mxnow = array();
	if ($sets !== null) {
		list($mxnow) = dns_set_of($sets, $domain, 'MX');
		list($txt) = dns_set_of($sets, $domain, 'TXT');
	} else {
		$c = mail_dns_check($domain);
		foreach ((array)($c['mx'] ?? array()) as $m)
			$mxnow[] = $m['priority'].' '.$m['host'];
		if (($c['spf'] ?? '') !== '')
			$txt[] = $c['spf'];
	}
	$spfs = array_values(array_filter($txt, function ($t) { return stripos(ltrim($t), 'v=spf1') === 0; }));
	$spf = mail_provider_spf($spfs[0] ?? '', $inc, $remote, domain_site_ip($account));
	$newtxt = array_values(array_filter($txt, function ($t) { return stripos(ltrim($t), 'v=spf1') !== 0; }));
	$newtxt[] = $spf;
	if (($code = trim((string)($o['verify'] ?? ''))) !== '') {
		list($code, $e) = dns_record_value($domain, $domain, 'TXT', $code);
		if ($e !== '')
			return array('The verification code: '.$e, null);
		if (!in_array($code, $newtxt, true))
			$newtxt[] = $code;
	}
	$records = array(array('name' => $domain, 'type' => 'MX', 'values' => $mx, 'was' => $mxnow),
	                 array('name' => $domain, 'type' => 'TXT', 'values' => $newtxt, 'was' => $txt, 'spf' => $spf));

	/* the provider's extra names, and the ones of a provider left */
	foreach ($all as $k => $q)
		foreach ((array)($q['cname'] ?? array()) as $n => $target) {
			$fq = $n.'.'.$domain;
			$now = $sets !== null ? dns_set_of($sets, $fq, 'CNAME')[0] : array();
			if ($k === $id && $now !== array($target)) {
				$other = $sets !== null && array_filter($sets, function ($s) use ($fq) { return $s['name'] === $fq && $s['type'] !== 'CNAME'; });
				if (!$other)
					$records[] = array('name' => $fq, 'type' => 'CNAME', 'values' => array($target), 'was' => $now);
			} elseif ($k !== $id && $now === array($target)) {
				$records[] = array('name' => $fq, 'type' => 'CNAME', 'values' => array(), 'was' => $now);
			}
		}
	return array('', array('provider' => $id, 'name' => $p['name'], 'remote' => $remote, 'records' => $records));
}

/* Switch the mail of $domain (a site or mail domain of $account) to a
   provider. $preview: only say what would change. */
function mail_provider_set($account, $domain, array $o, $preview = false) {
	global $db;
	$domain = strtolower(trim((string)$domain));
	$mine = array_column(domain_sites($account, false), 'domain');
	if (!valid_domain($domain) || (!in_array($domain, $mine, true) && (email_userdomains()[$domain] ?? '') !== $account))
		return result_error("$domain is not a site of this account.");
	$zone = mail_provider_zone($account, $domain);
	$sets = null;
	if ($zone !== '') {
		$sets = dns_zone_sets($zone);
		if (!is_array($sets))
			return result_error($sets);
	}
	list($e, $plan) = mail_provider_plan($account, $domain, $o, $sets);
	if ($e !== '')
		return result_error($e);
	$here = (email_userdomains()[$domain] ?? '') === $account && email_domain_exists($domain);
	$n = 0;
	foreach (mailbox_list() as $m)
		if (substr($m, -strlen($domain) - 1) === '@'.$domain)
			$n++;
	$data = array('domain' => $domain, 'zone' => $zone, 'mail_here' => $here, 'mailboxes' => $n) + $plan;
	/* only what changes */
	$data['records'] = array_values(array_filter($plan['records'], function ($r) {
		$a = $r['values']; $b = $r['was']; sort($a); sort($b); return $a !== $b; }));
	if ($preview)
		return result_ok('', $data);

	$warn = array();
	if ($zone !== '') {
		foreach ($data['records'] as $r) {
			$ttl = dns_set_of($sets, $r['name'], $r['type'])[1] ?: 3600;
			if (($e = (string)dns_zone_put($zone, $r['name'], $r['type'], $ttl, $r['values'])) !== '')
				return result_error('The DNS provider did not take the '.$r['type'].' record of '.$r['name'].': '.preg_replace('/^Error:\s*(DNS API:\s*)?/', '', $e)
				                    .($r !== $data['records'][0] ? ' The records before it were changed.' : ''), $data);
		}
	}
	/* DNS elsewhere: this server leaves the mail to the provider only once the
	   MX points there, else mail from outside would still come here and wait */
	$switch = $here;
	if ($here && $zone === '' && $plan['remote'] && !empty(mail_dns_check($domain)['mx_local'])) {
		$switch = false;
		$warn[] = "The MX of $domain still points to this server, so its mail keeps coming here for now.";
	}
	if ($switch)
		mail_remote_set($domain, $plan['remote']);
	$data['switched'] = $switch;
	route_log("mail provider $domain: ".$plan['provider'].($zone !== '' ? ' (dns '.$zone.')' : ' (dns elsewhere)').($here ? ($switch ? '' : ', mail here not switched yet') : ', no mail here').' for '.$account);
	plugin_hook('mail_provider.changed', array('account' => $account, 'domain' => $domain, 'provider' => $plan['provider'], 'remote' => $plan['remote'] && $switch));
	if ($plan['remote'] && $n && ($switch || !$here))
		$warn[] = ($n === 1 ? "The mailbox of $domain stays here and can still be read" : "The $n mailboxes of $domain stay here and can still be read").', but new mail goes to '.$plan['name'].'.';
	$done = $plan['remote'] ? "Mail for $domain now goes to ".$plan['name'].'.' : "Mail for $domain comes to this server again.";
	if ($here && !$switch)
		$msg = "Set the records shown where the DNS of $domain is hosted, then choose ".$plan['name'].' here again.';
	elseif ($zone === '' && $data['records'])
		$msg = ($here ? $done.' ' : '')."Set the records shown where the DNS of $domain is hosted.";
	else
		$msg = $done;
	return result_ok($msg, $data, $warn);
}

/* ---- SPF and DMARC helpers ---------------------------------------------------
   The SPF record of a domain as choices (this server, its MX servers, the
   services that send for it, other terms, what happens to the rest) and its
   DMARC record as a policy, both written back as one record. Like the mail
   provider: written when the zone is the account's at the DNS provider, else
   the record is returned to set by hand. Terms and tags the helper does not
   know are kept as they are. */

/* Services that send mail for a domain: id => name, include (default), match
   (any of their includes). */
function mail_spf_senders() {
	return array(
		'google'    => array('name' => 'Google Workspace', 'include' => 'include:_spf.google.com', 'match' => '/^include:_spf\.google\.com$/'),
		'microsoft' => array('name' => 'Microsoft 365', 'include' => 'include:spf.protection.outlook.com', 'match' => '/^include:spf\.protection\.outlook\.com$/'),
		'zoho'      => array('name' => 'Zoho Mail', 'include' => 'include:zoho.com', 'match' => '/^include:(zoho\.[a-z.]+|zohocloud\.ca)$/'),
		'ses'       => array('name' => 'Amazon SES', 'include' => 'include:amazonses.com', 'match' => '/^include:amazonses\.com$/'),
		'sendgrid'  => array('name' => 'SendGrid', 'include' => 'include:sendgrid.net', 'match' => '/^include:sendgrid\.net$/'),
		'mailgun'   => array('name' => 'Mailgun', 'include' => 'include:mailgun.org', 'match' => '/^include:mailgun\.org$/'),
		'brevo'     => array('name' => 'Brevo', 'include' => 'include:spf.brevo.com', 'match' => '/^include:spf\.(brevo|sendinblue)\.com$/'),
		'mailjet'   => array('name' => 'Mailjet', 'include' => 'include:spf.mailjet.com', 'match' => '/^include:spf\.mailjet\.com$/'),
		'postmark'  => array('name' => 'Postmark', 'include' => 'include:spf.mtasv.net', 'match' => '/^include:spf\.mtasv\.net$/'),
		'mandrill'  => array('name' => 'Mandrill (Mailchimp)', 'include' => 'include:spf.mandrillapp.com', 'match' => '/^include:spf\.mandrillapp\.com$/'),
		'sparkpost' => array('name' => 'SparkPost', 'include' => 'include:sparkpostmail.com', 'match' => '/^include:sparkpostmail\.com$/'),
		'zendesk'   => array('name' => 'Zendesk', 'include' => 'include:mail.zendesk.com', 'match' => '/^include:mail\.zendesk\.com$/'),
	);
}

/* The TXT values at $name now: from the zone's sets, or its name servers. */
function mail_txt_now($name, array $sets = null) {
	if ($sets !== null)
		return dns_set_of($sets, $name, 'TXT')[0];
	$ns = mail_dns_short(mail_dns_dig(array('ns' => '+short NS '.escapeshellarg(preg_replace('/^_dmarc\./', '', $name))))['ns'] ?? '');
	$out = mail_dns_dig(array('t' => '+short'.($ns ? ' '.escapeshellarg('@'.rtrim($ns[0], '.')) : '').' TXT '.escapeshellarg($name)))['t'] ?? '';
	$vals = array();
	foreach (mail_dns_short($out) as $l)
		$vals[] = dns_txt_decode($l);
	return $vals;
}

/* The zone's sets for $domain when it is the account's at the DNS provider:
   array(zone, sets) - zone '' and sets null otherwise; a string on an error. */
function mail_zone_sets($account, $domain) {
	$zone = mail_provider_zone($account, $domain);
	if ($zone === '')
		return array('', null);
	$sets = dns_zone_sets($zone);
	return is_array($sets) ? array($zone, $sets) : $sets;
}

/* An SPF record as choices: {record, server (+a or this server's address),
   mx, senders (ids), custom (other terms), all (-all|~all|?all), lookups}. */
function mail_spf_parse($spf, $ip) {
	$o = array('record' => $spf, 'server' => false, 'server_term' => '', 'mx' => false, 'senders' => array(), 'sender_terms' => array(),
	           'custom' => array(), 'all' => '~all', 'lookups' => 0);
	$senders = mail_spf_senders();
	foreach (preg_split('/\s+/', trim((string)$spf)) as $t) {
		$l = strtolower($t);
		if ($l === '' || $l === 'v=spf1')
			continue;
		$m = ltrim($l, '+');
		if (preg_match('/^[-~?+]?all$/', $l)) {
			$o['all'] = $l[0] === '+' || $l === 'all' ? '~all' : $l;
			continue;
		}
		if ($m === 'a' || ($ip !== '' && $m === 'ip4:'.$ip)) {
			$o['server'] = true;
			$o['server_term'] = $o['server_term'] ?: $t;
			continue;
		}
		if ($m === 'mx') {
			$o['mx'] = true;
			continue;
		}
		foreach ($senders as $k => $s)
			if (!in_array($l[0], array('-', '~', '?'), true) && preg_match($s['match'], $m)) {
				$o['senders'][] = $k;
				$o['sender_terms'][$k] = $m;
				continue 2;
			}
		$o['custom'][] = $t;
	}
	$o['lookups'] = mail_spf_lookups($o);
	return $o;
}

/* DNS lookups the record costs at its top level (receivers stop at 10). */
function mail_spf_lookups(array $o) {
	$n = ($o['server'] && strpos(strtolower($o['server_term'] ?: 'a'), 'ip4:') === false ? 1 : 0) + ($o['mx'] ? 1 : 0) + count($o['senders']);
	foreach ($o['custom'] as $t)
		if (preg_match('/^[-~?+]?(include:|a\b|mx\b|ptr\b|exists:|redirect=)/i', $t))
			$n++;
	return $n;
}

/* The SPF record of $domain as choices, and what the helper offers. */
function mail_spf_get($account, $domain) {
	$z = mail_zone_sets($account, $domain);
	if (is_string($z))
		return result_error($z);
	list($zone, $sets) = $z;
	$spfs = array_values(array_filter(mail_txt_now($domain, $sets), function ($t) { return stripos(ltrim($t), 'v=spf1') === 0; }));
	$o = mail_spf_parse($spfs[0] ?? '', domain_site_ip($account));
	$names = array();
	foreach (mail_spf_senders() as $k => $s)
		$names[$k] = $s['name'];
	return result_ok('', array('domain' => $domain, 'zone' => $zone, 'count' => count($spfs), 'ip' => domain_site_ip($account),
	                           'senders_offered' => $names) + $o);
}

/* Write the SPF record of $domain from choices: server, mx (booleans),
   senders (ids), custom (terms: ip4:, ip6:, include:, a:, mx:, exists:,
   redirect=, with an optional qualifier), all (-all|~all|?all). */
function mail_spf_set($account, $domain, array $c, $preview = false) {
	$cur = mail_spf_get($account, $domain);
	if (!$cur['ok'])
		return $cur;
	$d = $cur['data'];
	$ip = $d['ip'];
	$terms = array();
	if (!empty($c['server']))
		$terms[] = $d['server_term'] !== '' ? $d['server_term'] : '+a';
	if (!empty($c['mx']))
		$terms[] = '+mx';
	$senders = mail_spf_senders();
	foreach ((array)($c['senders'] ?? array()) as $k)
		if (isset($senders[$k]))
			$terms[] = $d['sender_terms'][$k] ?? $senders[$k]['include'];
	$custom = array();
	foreach ((array)($c['custom'] ?? array()) as $t) {
		$t = trim((string)$t);
		if ($t === '')
			continue;
		if (preg_match('/^[a-z0-9_.-]+\.[a-z]{2,}$/i', $t))
			$t = 'include:'.$t;
		if (!preg_match('#^[-~?+]?(ip4:[0-9]{1,3}(\.[0-9]{1,3}){3}(/[0-9]{1,2})?|ip6:[0-9a-f:]+(/[0-9]{1,3})?|(include|exists):[a-z0-9_.%{}-]+|(a|mx)(:[a-z0-9_.-]+)?(/[0-9]{1,3})?|redirect=[a-z0-9_.-]+|ptr(:[a-z0-9_.-]+)?|exp=[a-z0-9_.-]+)$#i', $t))
			return result_error("\"$t\" is not an SPF term: use ip4:192.0.2.1, ip6:2001:db8::1, include:spf.example.net, a:host.example.com or mx:example.com.");
		if (preg_match('#^[-~?+]?ip4:(.+?)(/\d+)?$#i', $t, $m) && !filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
			return result_error("$t is not a valid IPv4 address.");
		if (preg_match('#^[-~?+]?ip6:(.+?)(/\d+)?$#i', $t, $m) && !filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6))
			return result_error("$t is not a valid IPv6 address.");
		if (!in_array($t, $custom, true) && !in_array(strtolower($t), array_map('strtolower', $terms), true))
			$custom[] = $t;
	}
	$terms = array_merge($terms, $custom);
	$all = in_array($c['all'] ?? '', array('-all', '~all', '?all'), true) ? $c['all'] : '~all';
	$redirect = (bool)preg_grep('/^redirect=/i', $custom);
	$record = 'v=spf1'.($terms ? ' '.implode(' ', $terms) : '').($redirect ? '' : ' '.$all);
	if (strlen($record) > 450)
		return result_error('The record is too long; use fewer terms or an include.');
	$o = mail_spf_parse($record, $ip);
	$warn = array();
	if ($o['lookups'] > 10)
		return result_error('This record needs '.$o['lookups'].' DNS lookups; receivers stop at 10 and treat it as broken. Remove some includes.');
	if (!$o['server'] && empty(array_filter($custom, function ($t) use ($ip) { return stripos($t, 'ip4:'.$ip) !== false; })))
		$warn[] = 'This server is not in the record, so mail its websites send for '.$domain.' (contact forms, shop orders) fails the check.';
	if (!$terms)
		$warn[] = 'No server may send mail for '.$domain.': use this only for a domain that sends no mail.';
	return mail_txt_write($account, $domain, $domain, 'spf', $record, $cur['data']['zone'], $preview, $warn, array('lookups' => $o['lookups']));
}

/* Replace the SPF or DMARC record at $name (other TXT values there stay),
   or say what to set when the zone is not here. */
function mail_txt_write($account, $domain, $name, $kind, $record, $zone, $preview, array $warn, array $extra = array()) {
	$sets = null;
	if ($zone !== '' && !is_array($sets = dns_zone_sets($zone)))
		return result_error($sets);
	$now = mail_txt_now($name, $sets);
	$mark = $kind === 'spf' ? 'v=spf1' : 'v=dmarc1';
	$old = array_values(array_filter($now, function ($t) use ($mark) { return stripos(ltrim($t), $mark) === 0; }));
	$values = array_values(array_filter($now, function ($t) use ($mark) { return stripos(ltrim($t), $mark) !== 0; }));
	$values[] = $record;
	$data = array('domain' => $domain, 'zone' => $zone, 'name' => $name, 'record' => $record, 'was' => $old,
	              'changed' => $old !== array($record)) + $extra;
	if ($preview || !$data['changed'])
		return result_ok($data['changed'] ? '' : 'Nothing to change.', $data, $warn);
	if ($zone === '')
		return result_ok("Set this record where the DNS of $domain is hosted".(count($old) > 1 ? ', in place of the '.count($old).' there now.' : ($old ? ', in place of the one there now.' : '.')), $data, $warn);
	$ttl = dns_set_of($sets, $name, 'TXT')[1] ?: 3600;
	if (($e = (string)dns_zone_put($zone, $name, 'TXT', $ttl, $values)) !== '')
		return result_error('The DNS provider did not take the change: '.preg_replace('/^Error:\s*(DNS API:\s*)?/', '', $e), $data);
	route_log("mail $kind $domain: ".mb_substr($record, 0, 200)." for $account");
	plugin_hook('mail_'.$kind.'.changed', array('account' => $account, 'domain' => $domain, 'record' => $record));
	return result_ok(($kind === 'spf' ? 'SPF' : 'DMARC')." record of $domain saved.", $data, $warn);
}

/* A DMARC record as choices: {record, p, sp, pct, rua, ruf, adkim, aspf, other (tags kept)}. */
function mail_dmarc_parse($rec) {
	$o = array('record' => $rec, 'p' => 'none', 'sp' => '', 'pct' => 100, 'rua' => array(), 'ruf' => array(), 'adkim' => 'r', 'aspf' => 'r', 'other' => array());
	foreach (explode(';', (string)$rec) as $part) {
		if (strpos($part, '=') === false)
			continue;
		list($k, $v) = array_map('trim', explode('=', $part, 2));
		$k = strtolower($k);
		$lv = strtolower($v);
		switch ($k) {
		case 'v': break;
		case 'p': case 'sp': if (in_array($lv, array('none', 'quarantine', 'reject'), true)) $o[$k] = $lv; break;
		case 'pct': $o['pct'] = max(0, min(100, (int)$v)); break;
		case 'adkim': case 'aspf': $o[$k] = $lv === 's' ? 's' : 'r'; break;
		case 'rua': case 'ruf':
			foreach (explode(',', $v) as $u)
				if (($u = trim($u)) !== '')
					$o[$k][] = preg_replace('/^mailto:/i', '', $u);
			break;
		default: if ($k !== '') $o['other'][$k] = $v;
		}
	}
	return $o;
}

function mail_dmarc_get($account, $domain) {
	$z = mail_zone_sets($account, $domain);
	if (is_string($z))
		return result_error($z);
	list($zone, $sets) = $z;
	$recs = array_values(array_filter(mail_txt_now('_dmarc.'.$domain, $sets), function ($t) { return stripos(ltrim($t), 'v=dmarc1') === 0; }));
	return result_ok('', array('domain' => $domain, 'zone' => $zone, 'count' => count($recs), 'exists' => (bool)$recs) + mail_dmarc_parse($recs[0] ?? ''));
}

/* Write the DMARC record of $domain: p, sp ('' = as p), pct, rua / ruf
   (addresses), adkim / aspf (r|s); its other tags are kept. */
function mail_dmarc_set($account, $domain, array $c, $preview = false) {
	$cur = mail_dmarc_get($account, $domain);
	if (!$cur['ok'])
		return $cur;
	$d = $cur['data'];
	$pol = array('none', 'quarantine', 'reject');
	$p = in_array($c['p'] ?? '', $pol, true) ? $c['p'] : 'none';
	$sp = in_array($c['sp'] ?? '', $pol, true) ? $c['sp'] : '';
	$pct = isset($c['pct']) && $c['pct'] !== '' ? (int)$c['pct'] : 100;
	if ($pct < 1 || $pct > 100)
		return result_error('The share of mail the policy applies to is 1 to 100 percent.');
	$tags = array('v=DMARC1', 'p='.$p);
	if ($sp !== '' && $sp !== $p)
		$tags[] = 'sp='.$sp;
	if ($pct !== 100)
		$tags[] = 'pct='.$pct;
	$warn = array();
	foreach (array('rua', 'ruf') as $k) {
		$list = array();
		foreach ((array)($c[$k] ?? array()) as $a) {
			$a = strtolower(trim(preg_replace('/^mailto:/i', '', (string)$a)));
			if ($a === '')
				continue;
			if (!filter_var($a, FILTER_VALIDATE_EMAIL))
				return result_error("$a is not an email address.");
			$list[] = 'mailto:'.$a;
			$ad = substr(strrchr($a, '@'), 1);
			if ($ad !== $domain && substr($ad, -strlen($domain) - 1) !== '.'.$domain && strpos($domain, '.'.$ad) === false)
				$warn[] = "Reports to $a are sent only when $ad allows it, with the TXT record $domain._report._dmarc.$ad = v=DMARC1 (report services such as dmarcian or Postmark add it themselves).";
		}
		if ($list)
			$tags[] = $k.'='.implode(',', array_unique($list));
	}
	if (($c['adkim'] ?? 'r') === 's')
		$tags[] = 'adkim=s';
	if (($c['aspf'] ?? 'r') === 's')
		$tags[] = 'aspf=s';
	foreach ($d['other'] as $k => $v)      // fo, rf, ri, ... as they were
		$tags[] = $k.'='.$v;
	$record = implode('; ', $tags);
	if (!preg_grep('/^rua=/', $tags))
		$warn[] = 'Without a report address you do not see which mail fails the checks. A good start: p=none with reports for a few weeks, then quarantine or reject.';
	if ($p === 'reject')
		$warn[] = 'With p=reject, mail from '.$domain.' that fails SPF and DKIM is refused: make sure every service that sends for it is in the SPF record or signs with DKIM.';
	return mail_txt_write($account, $domain, '_dmarc.'.$domain, 'dmarc', $record, $d['zone'], $preview, $warn);
}

/* ---- Delivery log ------------------------------------------------------------
   The exim main log (the current file and the one before it), as messages:
   who sent it, to whom, and what happened to each recipient. With $domains
   (an account's mail domains) only the messages that involve them are read,
   and of a message coming from outside only the recipients at those domains
   are shown. */

/* The log files read: the one before the current (when not compressed) and
   the current one. */
function mail_log_files() {
	static $files = null;
	if ($files === null) {
		$prev = trim((string)shell_exec("sudo -n find /var/log/exim -maxdepth 1 -name 'main.log-*' ! -name '*.gz' -printf '%T@ %p\\n' 2>/dev/null | sort -n | tail -n 1 | cut -d' ' -f2-"));
		$files = array_merge($prev !== '' ? array($prev) : array(), array('/var/log/exim/main.log'));
	}
	return $files;
}

/* The log lines that contain any of $needles (grep -F; $ci: any case). */
function mail_log_grep(array $needles, $ci = true) {
	$e = '';
	foreach ($needles as $n)
		$e .= ' -e '.escapeshellarg($n);
	return (string)shell_exec('sudo -n cat '.implode(' ', array_map('escapeshellarg', mail_log_files())).' 2>/dev/null | tail -n 300000 | grep -a -F'.($ci ? ' -i' : '').$e.' 2>/dev/null');
}

/*
 * Messages in the delivery log, newest first.
 *   $domains  only messages to or from these domains (null = all: server only)
 *   $search   only messages whose sender, recipients or subject contain it
 *   $limit    at most this many messages (1-200)
 * Returns array(messages => [{id, time, from, subject, size, spam (score or
 * null), spam_flag, direction (in|out|local), recipients: [{to, via (where a
 * forwarder sent it), status (delivered|deferred|bounced|discarded (no mailbox
 * or forwarder has the address)|refused|waiting),
 * time, detail}]}], refused => [{time, from, to, host, reason}] (refused
 * before the message was taken), since (the oldest line read)).
 */
function mail_log_search($domains, $search = '', $limit = 50) {
	$limit = max(1, min(200, (int)$limit ?: 50));
	$search = substr(trim((string)$search), 0, 200);
	$set = null;
	if ($domains !== null) {
		$set = array_flip(array_map('strtolower', (array)$domains));
		if (!$set)
			return array('messages' => array(), 'refused' => array(), 'since' => '');
	}
	$ours = function ($addr) use ($set) {
		if ($set === null) return true;
		$at = strrpos((string)$addr, '@');
		return $at !== false && isset($set[strtolower(substr($addr, $at + 1))]);
	};
	$idre = '[0-9A-Za-z]{6}-[0-9A-Za-z]{6,11}-[0-9A-Za-z]{2,4}';
	$host = strtolower(php_uname('n'));
	$text = mail_log_grep($set === null ? array($search !== '' ? $search : ' ') : array_map(function ($d) { return '@'.$d; }, array_keys($set)));
	$ids = $refused = array();
	foreach (explode("\n", $text) as $line) {
		if (!preg_match('/^(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)\S* (?:\[\d+\] )?(.*)$/', $line, $m))
			continue;
		if ($search !== '' && stripos($line, $search) === false)
			continue;
		if (preg_match('/^('.$idre.') /', $m[2], $mm)) {
			unset($ids[$mm[1]]);
			$ids[$mm[1]] = true;     // keep the order of the last mention
		} elseif (preg_match('/^H=(\S+)(?: \(([^)]*)\))? \[([0-9a-fA-F.:]+)\]\S*.*? F=<([^>]*)> (?:temporarily )?rejected RCPT <([^>]+)>: ?(.*)$/', $m[2], $r)
		          && ($ours($r[5]) || $ours($r[4]))) {
			$refused[] = array('time' => $m[1], 'from' => $r[4], 'to' => $r[5], 'host' => $r[3], 'reason' => substr($r[6], 0, 300));
		}
	}
	$refused = array_reverse(array_slice($refused, -$limit));
	$ids = array_slice(array_keys($ids), -$limit);
	$msgs = array();
	if ($ids) {
		foreach (explode("\n", mail_log_grep($ids, false)) as $line) {
			if (!preg_match('/^(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)\S* (?:\[\d+\] )?('.$idre.') (.*)$/', $line, $m) || !in_array($m[2], $ids, true))
				continue;
			list(, $time, $id, $rest) = $m;
			if (!isset($msgs[$id]))
				$msgs[$id] = array('id' => $id, 'time' => $time, 'from' => '', 'subject' => '', 'size' => 0, 'spam' => null, 'spam_flag' => false,
				                   'for' => array(), 'recipients' => array(), 'done' => false, 'rejected' => '');
			$g = &$msgs[$id];
			if (preg_match('/^<= (\S+) (.*)$/', $rest, $r)) {
				$g['time'] = $time;
				$g['from'] = $r[1] === '<>' ? '' : $r[1];
				$tail = $r[2];
				if (preg_match('/ T="((?:[^"\\\\]|\\\\.)*)"/', $tail, $t)) {
					$g['subject'] = stripcslashes($t[1]);
					$tail = str_replace($t[0], '', $tail);
				}
				if (preg_match('/ S=(\d+)/', $tail, $t))
					$g['size'] = (int)$t[1];
				if (preg_match('/ for (.+)$/', $tail, $t))
					$g['for'] = preg_split('/\s+/', trim($t[1]));
			} elseif (preg_match('/^(=>|->|==|\*\*) (\S+)(?: \([^)]*\))?(?: <([^>]+)>)?(.*)$/', $rest, $r)) {
				$to = $r[3] !== '' ? $r[3] : $r[2];
				$via = $r[3] !== '' && strpos($r[2], '@') !== false && strcasecmp($r[2], $r[3]) !== 0 ? $r[2] : '';
				if ($set !== null && strcasecmp(substr(strrchr($via, '@'), 1), $host) === 0)
					$via = '';     // the server's own aliases (postmaster, root) are not the account's
				$st = array('=>' => 'delivered', '->' => 'delivered', '==' => 'deferred', '**' => 'bounced')[$r[1]];
				$detail = '';
				if ($st === 'delivered' && preg_match('/ T=null_transport\b/', $r[4])) {
					$st = 'discarded';     // the last router: no mailbox or forwarder has the address
				} elseif ($st === 'delivered' && preg_match('/ T=\S*(?:local|lmtp|dovecot)/i', $r[4])) {
					$detail = 'mailbox';    // put in a mailbox on this server
				} elseif ($st === 'delivered') {
					if (preg_match('/ H=(\S+)/', $r[4], $t)) $detail = $t[1];
					if (preg_match('/ C="((?:[^"\\\\]|\\\\.)*)"/', $r[4], $t)) $detail .= ($detail !== '' ? ': ' : '').stripcslashes($t[1]);
				} elseif (($p = strpos($r[4], ': ')) !== false) {
					$detail = trim(substr($r[4], $p + 2));
				} elseif (preg_match('/defer \((-?\d+)\)/', $r[4], $t)) {
					$detail = 'deferred ('.$t[1].')';
				}
				$key = strtolower($to.'|'.$via);
				$g['recipients'][$key] = array('to' => $to, 'via' => $via, 'status' => $st, 'time' => $time, 'detail' => substr($detail, 0, 400));
			} elseif ($rest === 'Completed') {
				$g['done'] = true;
			} elseif (preg_match('/SpamAssassin as \S+ detected message as (NOT )?spam \((-?[0-9.]+)\)/', $rest, $r)) {
				$g['spam'] = (float)$r[2];
				$g['spam_flag'] = $r[1] === '';
			} elseif (preg_match('/rejected after DATA: ?(.*)$/', $rest, $r)) {
				$g['rejected'] = substr($r[1], 0, 300);
				if (!$g['from'] && preg_match('/ F=<([^>]*)>/', $rest, $t))
					$g['from'] = $t[1];
			}
			unset($g);
		}
	}
	$out = array();
	foreach ($ids as $id) {
		if (!isset($msgs[$id]))
			continue;
		$g = $msgs[$id];
		$mine = $g['from'] !== '' && $ours($g['from']);
		$rc = array();
		foreach ($g['recipients'] as $x)
			if ($mine || $ours($x['to']) || ($x['via'] !== '' && $ours($x['via'])))
				$rc[] = $x;
		$seen = array_map(function ($x) { return strtolower($x['to']); }, $rc);
		foreach ($g['for'] as $f)
			if (($mine || $ours($f)) && !in_array(strtolower($f), $seen, true))
				$rc[] = array('to' => $f, 'via' => '', 'status' => $g['rejected'] !== '' ? 'refused' : 'waiting', 'time' => $g['time'],
				              'detail' => $g['rejected']);
		if (!$rc && !$mine)
			continue;     // the domain was only in the subject: a stranger's message
		/* in = from outside to these domains, out = from them to outside,
		   local = from them to them ('' when no domains were given) */
		$dir = '';
		if ($set !== null) {
			$dir = 'in';
			if ($mine) {
				$dir = 'local';
				foreach ($rc as $x)
					if (!$ours($x['to']))
						$dir = 'out';
			}
		}
		$out[] = array('id' => $id, 'time' => $g['time'], 'from' => $g['from'], 'subject' => $g['subject'], 'size' => $g['size'],
			'spam' => $g['spam'], 'spam_flag' => $g['spam_flag'], 'direction' => $dir, 'recipients' => $rc);
	}
	usort($out, function ($a, $b) { return strcmp($b['time'], $a['time']); });
	$first = mail_log_files()[0];
	$since = preg_match('/^(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)/', (string)shell_exec('sudo -n head -n 1 '.escapeshellarg($first).' 2>/dev/null'), $m) ? $m[1] : '';
	return array('messages' => $out, 'refused' => $refused, 'since' => $since);
}
