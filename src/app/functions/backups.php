<?php
/*
 * An account's own backups: what exists, what is in each, download, and
 * putting files, a database or a mailbox back - for the REST API and so the
 * account panel on 2083 (Backups), and the CLI (`reqad backup`).
 *
 * Two places hold an account's backups, both made by the server's admin:
 *   remote  the nightly backup_remote.sh run: <date>/accounts/<user>.tar.gz
 *           (+ <user>.index, + databases/<user>/<db>.sql.gz for the big
 *           ones) on the backup server, read over ssh as root
 *   local   backup_<user>_<YYYY-MM-DD_HHMM>.tar.gz in /usr/local/reqad/backup
 *           (Backup > Local backup on 2087)
 * Both are one tar.gz with a single top folder backup-<user>/: the staged
 * part first (databases/, email/, meta/, excludes.txt, summary.txt), then
 * homedir/ - so what an archive holds besides the files is read from its
 * front without going through the whole of it (backup_scan()).
 *
 * Restores run in the background (backup_restore_start(): table
 * backup_restores db/1053.sql, transient unit reqad-restore-<account>, one at
 * a time per account, `reqad backup __run <job>`):
 *   files     a file or folder of the home folder (not mail/), unpacked AS
 *             the account by fm-helper verb bkrestore: missing (only what is
 *             not there now) | overwrite | replace (not the whole home); what
 *             is replaced or deleted is kept in ~/backup-restores/
 *   database  one of the account's databases: tables (the backup's tables
 *             replace the same ones, the others stay) | replace (emptied
 *             first) | new (into another, new database); a copy of the
 *             database as it was goes to ~/backup-restores/ first
 *   email     one mailbox: missing (messages not there now come back) |
 *             replace; a mailbox deleted since is made again with its old
 *             password
 * Nothing here reads or writes inside a home folder as root: files go
 * through the helper, dumps through `sudo -u <account>`.
 */

const BACKUP_LOCAL_DIR = '/usr/local/reqad/backup';

/* ---- where the backups are ----------------------------------------------------------- */

function backup_valid_id($id) {
	return is_string($id) && preg_match('/^(remote-[0-9]{8}|local-[0-9]{8}-[0-9]{4})$/', $id) === 1;
}

/* small caches beside the panel's logs (read by root and the panel user) */
function backup_cache_get($key, $ttl) {
	$f = _PATH.'/log/backup-cache/'.$key.'.json';
	if (!is_file($f) || ($ttl > 0 && time() - filemtime($f) > $ttl))
		return null;
	$d = json_decode((string)@file_get_contents($f), true);
	return is_array($d) ? $d : null;
}

function backup_cache_put($key, array $data) {
	$dir = _PATH.'/log/backup-cache';
	if (!is_dir($dir) && @mkdir($dir, 0750) && function_exists('posix_geteuid') && posix_geteuid() === 0) {
		/* the panel's user writes here too (the API on 2087) */
		@chown($dir, fileowner(_PATH.'/log'));
		@chgrp($dir, filegroup(_PATH.'/log'));
	}
	$tmp = $dir.'/.'.$key.'.'.getmypid();
	if (@file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false) {
		@chmod($tmp, 0644);
		@rename($tmp, $dir.'/'.$key.'.json');
	}
}

/* The local archives of an account, newest first. */
function backup_local_list($acct) {
	$out = array();
	foreach (glob(BACKUP_LOCAL_DIR.'/backup_'.$acct.'_*.tar.gz') ?: array() as $f) {
		if (!preg_match('/^backup_'.preg_quote($acct, '/').'_([0-9]{4})-([0-9]{2})-([0-9]{2})_([0-9]{2})([0-9]{2})\.tar\.gz$/', basename($f), $m) || is_link($f))
			continue;
		$out[] = array('id' => 'local-'.$m[1].$m[2].$m[3].'-'.$m[4].$m[5], 'source' => 'local', 'date' => $m[1].'-'.$m[2].'-'.$m[3],
		               'time' => $m[4].':'.$m[5], 'ts' => (int)filemtime($f), 'bytes' => (int)filesize($f), 'status' => 'ok', 'file' => $f);
	}
	return $out;
}

/* The nightly backups of an account on the backup server, in ONE ssh call:
   each date that has its archive, with the archive's .index. Cached 2 minutes. */
function backup_remote_list($acct, $force = false, &$error = '') {
	$error = '';
	$cfg = remote_backup_config();
	if (!remote_backup_configured($cfg))
		return array();
	$key = 'remote-'.$acct;
	if (!$force && ($c = backup_cache_get($key, 120)) !== null) {
		$error = (string)($c['error'] ?? '');
		return (array)($c['rows'] ?? array());
	}
	$base = remote_backup_base($cfg);
	$cmd = 'cd '.($base === '' ? '.' : "'".rtrim($base, '/')."'").' 2>/dev/null || exit 0'."\n"
	     . 'for d in 20[0-9][0-9]-[0-9][0-9]-[0-9][0-9]; do'."\n"
	     . '  f="$d/accounts/'.$acct.'.tar.gz"; [ -f "$f" ] || continue'."\n"
	     . '  st=$(sed -n \'s/^failed *: //p\' "$d/MANIFEST.txt" 2>/dev/null | head -1)'."\n"
	     . '  echo "B $d $(stat -c \'%s %Y\' "$f") ${st:-incomplete}"'."\n"
	     . '  [ -f "$d/accounts/'.$acct.'.index" ] && sed "s|^|I $d |" "$d/accounts/'.$acct.'.index"'."\n"
	     . '  for g in "$d/databases/'.$acct.'"/*.sql.gz; do [ -e "$g" ] || continue; b=${g##*/}; echo "G $d ${b%.sql.gz} $(stat -c %s "$g")"; done'."\n"
	     . 'done'."\n";
	$rc = 0;
	$out = remote_backup_ssh($cfg, $cmd, $rc);
	$rows = array();
	if ($rc !== 0)
		$error = 'The backup server did not answer: '.substr(trim($out), -200);
	else
		foreach (explode("\n", $out) as $line) {
			$f = explode(' ', rtrim($line));
			if (count($f) < 3 || !valid_backup_date($f[1]))
				continue;
			$d = $f[1];
			if ($f[0] === 'B' && count($f) >= 4) {
				$failed = trim(implode(' ', array_slice($f, 4)));
				$rows[$d] = array('id' => 'remote-'.str_replace('-', '', $d), 'source' => 'remote', 'date' => $d, 'time' => '',
				                  'ts' => (int)$f[3], 'bytes' => (int)$f[2],
				                  /* this account's part failed in that run (or the run never finished) */
				                  'status' => $failed === 'incomplete' ? 'incomplete' : (preg_match('/(^|[ ,])'.preg_quote($acct, '/').'(:|[ ,]|$)/', $failed) ? 'failed' : 'ok'),
				                  'homes' => array(), 'databases' => array(), 'excluded' => array());
			} elseif ($f[0] === 'I' && isset($rows[$d], $f[2])) {
				if ($f[2] === 'home' && isset($f[4]))
					$rows[$d]['homes'][$f[3]] = $f[4];
				elseif ($f[2] === 'db' && isset($f[5]) && valid_mysql_identifier($f[3]))
					$rows[$d]['databases'][$f[3]] = array('kb' => (int)$f[4], 'src' => $f[5] === 'remote' ? 'remote' : 'archive');
				elseif ($f[2] === 'excl' && isset($f[4]))
					$rows[$d]['excluded'][] = array('kind' => $f[3], 'path' => implode(' ', array_slice($f, 4)));
			} elseif ($f[0] === 'G' && isset($rows[$d], $f[3]) && valid_mysql_identifier($f[2]) && !isset($rows[$d]['databases'][$f[2]]))
				$rows[$d]['databases'][$f[2]] = array('kb' => (int)round((int)$f[3] / 1024), 'src' => 'remote');
		}
	$rows = array_values($rows);
	backup_cache_put($key, array('rows' => $rows, 'error' => $error));
	return $rows;
}

/* Every backup of an account, newest first: id, source (remote|local), date,
   time ('' for the nightly ones), ts, bytes, status (ok|failed|incomplete). */
function backup_list($acct, $force = false) {
	global $db;
	if (!valid_username($acct) || !account_get($db, $acct))
		return result_error("There is no account $acct.");
	$err = '';
	$rows = array_merge(backup_remote_list($acct, $force, $err), backup_local_list($acct));
	usort($rows, function ($a, $b) { return $b['ts'] <=> $a['ts']; });
	$cfg = remote_backup_config();
	return result_ok('', array('backups' => array_map('backup_public', $rows), 'remote' => remote_backup_configured($cfg), 'remote_error' => $err));
}

function backup_public(array $r) {
	return array_intersect_key($r, array_flip(array('id', 'source', 'date', 'time', 'ts', 'bytes', 'status')));
}

/* One backup of the account (with the file / index details), or null. */
function backup_get($acct, $id) {
	if (!backup_valid_id($id))
		return null;
	$rows = strpos($id, 'remote-') === 0 ? backup_remote_list($acct) : backup_local_list($acct);
	foreach ($rows as $r)
		if ($r['id'] === $id)
			return $r;
	/* made since the cache was filled */
	if (strpos($id, 'remote-') === 0)
		foreach (backup_remote_list($acct, true) as $r)
			if ($r['id'] === $id)
				return $r;
	return null;
}

/* A shell command that writes the backup's archive (gzip'd tar) to stdout. */
function backup_source_cmd(array $r, $acct) {
	if ($r['source'] === 'local')
		return 'cat '.escapeshellarg($r['file']);
	$cfg = remote_backup_config();
	return remote_backup_ssh_cmdline($cfg, "cat '".remote_backup_base($cfg).$r['date'].'/accounts/'.$acct.".tar.gz'");
}

/* The archive as a readable stream, for a reader that stops early: array(stream,
   source process or null). Without a shell: PHP ignores SIGPIPE and its
   children inherit that, so a pipeline would read a whole multi-GB archive
   after the reader is done - this way the caller stops it (backup_close()). */
function backup_open(array $r, $acct) {
	if ($r['source'] === 'local')
		return array(@fopen($r['file'], 'rb'), null);
	$cfg = remote_backup_config();
	$argv = function_exists('posix_geteuid') && posix_geteuid() === 0 ? array() : array('sudo', '-n');
	$argv = array_merge($argv, array('ssh', '-p', $cfg['port'], '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=accept-new',
	                                 '-o', 'ConnectTimeout=10', '-o', 'ServerAliveInterval=15'));
	if ($cfg['key'] !== '')
		$argv = array_merge($argv, array('-i', $cfg['key']));
	$argv[] = $cfg['user'].'@'.$cfg['host'];
	$argv[] = "cat '".remote_backup_base($cfg).$r['date'].'/accounts/'.$acct.".tar.gz'";
	$p = proc_open($argv, array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes);
	return is_resource($p) ? array($pipes[1], $p) : array(false, null);
}

/* Stop a reader (proc_open'ed tar) and the source it reads from. */
function backup_close($proc, array $pipes, array $src) {
	foreach ($pipes as $h)
		if (is_resource($h))
			@fclose($h);
	if (is_resource($proc)) {
		@proc_terminate($proc);
		@proc_close($proc);
	}
	if (is_resource($src[0]))
		@fclose($src[0]);
	if (is_resource($src[1])) {
		@proc_terminate($src[1]);
		@proc_close($src[1]);
	}
}

/* The command for one database dump (plain SQL on stdout), or '' when the backup has none of it. */
function backup_db_cmd(array $r, $acct, $dbname, array $scan) {
	if (!valid_mysql_identifier($dbname) || !isset($scan['databases'][$dbname]))
		return '';
	if ($scan['databases'][$dbname]['src'] === 'remote') {
		$cfg = remote_backup_config();
		return remote_backup_ssh_cmdline($cfg, "cat '".remote_backup_base($cfg).$r['date'].'/databases/'.$acct.'/'.$dbname.".sql.gz'").' | gzip -dc';
	}
	return backup_source_cmd($r, $acct).' | tar -xzOf - --occurrence=1 '.escapeshellarg('backup-'.$acct.'/databases/'.$dbname.'.sql');
}

/*
 * What a backup holds besides the files: its databases, its mailboxes and
 * which parts it has (website, email, databases). Read from the archive's
 * front (the staged part comes before homedir/): one listing that stops at
 * the first homedir/ entry, then the few small files it names. Mailbox
 * password hashes are read and dropped at once, never kept. Cached per
 * backup for a day (an archive does not change).
 */
function backup_scan($acct, array $r) {
	$key = 'scan-'.$acct.'-'.$r['id'].'-'.$r['bytes'];
	if (($c = backup_cache_get($key, 86400)) !== null)
		return $c;
	$root = 'backup-'.$acct;
	$scan = array('databases' => array(), 'mailboxes' => array(), 'bundles' => array(), 'home' => false, 'ok' => false);
	$names = array();
	$src = backup_open($r, $acct);
	if (!$src[0])
		return $scan;
	$p = proc_open(array('tar', '-tzf', '-'), array(0 => $src[0], 1 => array('pipe', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes);
	if (!is_resource($p)) {
		backup_close(null, array(), $src);
		return $scan;
	}
	while (($l = fgets($pipes[1], 8192)) !== false) {
		$l = rtrim($l, "\n");
		if (strpos($l, $root.'/homedir') === 0) {
			$scan['home'] = true;
			break;                  // the rest is the home folder: not needed
		}
		if (preg_match('#^'.preg_quote($root, '#').'/databases/([A-Za-z0-9_$]{1,64})\.sql$#', $l, $m) && $m[1][0] !== '_')
			$scan['databases'][$m[1]] = array('kb' => 0, 'src' => 'archive');
		elseif (preg_match('#^'.preg_quote($root, '#').'/email/(domains/[a-z0-9.-]+/)?dovecot-users$#', $l) || $l === $root.'/summary.txt')
			$names[] = $l;
	}
	backup_close($p, $pipes, $src);
	/* the remote index knows the big dumps kept beside the archive */
	foreach ((array)($r['databases'] ?? array()) as $n => $d)
		$scan['databases'][$n] = $d;
	if ($names) {
		$cmd = backup_source_cmd($r, $acct).' | tar -xzOf - --occurrence=1 '.implode(' ', array_map('escapeshellarg', $names)).' 2>/dev/null';
		$p = proc_open($cmd, array(1 => array('pipe', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes);
		if (is_resource($p)) {
			while (($l = fgets($pipes[1], 65536)) !== false) {
				if (preg_match('/^([a-z0-9._+-]{1,64}@[a-z0-9.-]{1,253}):/i', $l, $m))
					$scan['mailboxes'][strtolower($m[1])] = true;
				elseif (preg_match('/^Bundles:\s*(.*)$/', $l, $m))
					$scan['bundles'] = array_values(array_filter(array_map('trim', explode(',', $m[1]))));
				$l = null;
			}
			fclose($pipes[1]);
			proc_close($p);
		}
	}
	$scan['mailboxes'] = array_keys($scan['mailboxes']);
	sort($scan['mailboxes']);
	ksort($scan['databases']);
	$scan['ok'] = $scan['home'] || $scan['databases'] || $names;
	if ($scan['ok'])
		backup_cache_put($key, $scan);
	return $scan;
}

/* One backup in full: the list entry + what it holds (databases [{name, kb}],
   mailboxes, parts website|email|databases, homes: the home folder's top
   entries when the backup lists them, excluded: what it left out). */
function backup_detail($acct, $id) {
	$r = backup_get($acct, $id);
	if (!$r)
		return result_error("There is no backup $id of account $acct.");
	$scan = backup_scan($acct, $r);
	if (!$scan['ok'])
		return result_error('This backup could not be read.', array('backup' => backup_public($r)));
	$parts = $scan['bundles'];
	if (!$parts) {
		/* the nightly backups always hold all three */
		$parts = $r['source'] === 'remote' ? array('website', 'email', 'databases') : array_values(array_filter(array(
			$scan['home'] ? 'website' : '', $scan['mailboxes'] ? 'email' : '', $scan['databases'] ? 'databases' : '')));
	}
	$dbs = array();
	foreach ($scan['databases'] as $n => $d)
		$dbs[] = array('name' => $n, 'kb' => (int)$d['kb']);
	$homes = array();
	foreach ((array)($r['homes'] ?? array()) as $n => $t)
		$homes[] = array('name' => $n, 'type' => $t);
	return result_ok('', array('backup' => backup_public($r), 'parts' => $parts, 'databases' => $dbs, 'mailboxes' => $scan['mailboxes'],
	                           'homes' => $homes, 'excluded' => (array)($r['excluded'] ?? array())));
}

/*
 * A download of a backup: what = archive (the whole .tar.gz) | database (one
 * dump, as .sql.gz). Returns result_ok with data cmd (a shell command writing
 * the bytes to stdout), name and size (null when not known before).
 */
function backup_download($acct, $id, $what, $dbname = '') {
	$r = backup_get($acct, $id);
	if (!$r)
		return result_error("There is no backup $id of account $acct.");
	$stamp = str_replace('-', '', $r['date']).($r['time'] !== '' ? '-'.str_replace(':', '', $r['time']) : '');
	if ($what === 'archive')
		return result_ok('', array('cmd' => backup_source_cmd($r, $acct), 'name' => 'backup-'.$acct.'-'.$stamp.'.tar.gz', 'size' => (int)$r['bytes']));
	if ($what !== 'database')
		return result_error('Choose what to download.');
	$scan = backup_scan($acct, $r);
	$cmd = backup_db_cmd($r, $acct, $dbname, $scan);
	if ($cmd === '')
		return result_error("Database $dbname is not in this backup.");
	if ($scan['databases'][$dbname]['src'] === 'remote') {
		$cfg = remote_backup_config();
		$cmd = remote_backup_ssh_cmdline($cfg, "cat '".remote_backup_base($cfg).$r['date'].'/databases/'.$acct.'/'.$dbname.".sql.gz'");
	} else
		$cmd .= ' | gzip -6';
	return result_ok('', array('cmd' => $cmd, 'name' => $dbname.'-'.$stamp.'.sql.gz', 'size' => null));
}

/* ---- restoring ----------------------------------------------------------------------- */

function backup_restores_ready() {
	global $db;
	static $ok = null;
	if ($ok === null)
		$ok = $db instanceof SQLite3 && (bool)@$db->querySingle("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'backup_restores'");
	return $ok;
}

function backup_restore_modes($kind) {
	return array('files' => array('missing', 'overwrite', 'replace'), 'database' => array('tables', 'replace', 'new'),
	             'email' => array('missing', 'replace'))[$kind] ?? array();
}

function backup_restore_get($id) {
	global $db;
	if (!backup_restores_ready())
		return null;
	$r = $db->querySingle('SELECT * FROM backup_restores WHERE id = '.(int)$id, true);
	return $r ?: null;
}

function backup_restore_public(array $j, $output = false) {
	$o = array('id' => (int)$j['id'], 'backup' => $j['backup'], 'kind' => $j['kind'], 'target' => $j['target'], 'mode' => $j['mode'],
	           'status' => $j['status'], 'message' => $j['message'], 'kept' => $j['kept'], 'by' => $j['by_user'],
	           'queued' => (int)$j['queued'], 'started' => (int)$j['started'], 'finished' => (int)$j['finished']);
	if ($output)
		$o['output'] = $j['output'];
	return $o;
}

/* The latest restores of an account, newest first. */
function backup_restore_list($acct, $limit = 10) {
	global $db;
	$out = array();
	if (!backup_restores_ready())
		return $out;
	$st = $db->prepare('SELECT * FROM backup_restores WHERE account = :a ORDER BY id DESC LIMIT '.max(1, min(100, (int)$limit)));
	$st->bindValue(':a', $acct, SQLITE3_TEXT);
	$res = $st->execute();
	while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC)))
		$out[] = backup_restore_public($r);
	return $out;
}

/*
 * Queue a restore and start its runner. $o: kind (files|database|email),
 * target (files: a path relative to the home, '' = all of it; database: the
 * backup's database name; email: the mailbox), mode (backup_restore_modes()),
 * name (database mode new: the new database's name without the account's
 * prefix), grant (database mode new: a database user to give it to).
 */
function backup_restore_start($acct, $id, array $o, $by = '') {
	global $db, $ini;
	if (!backup_restores_ready())
		return result_error('The table for restores is missing: apply db/1053.sql (the Reqad update does it).');
	$kind = (string)($o['kind'] ?? '');
	$mode = (string)($o['mode'] ?? '');
	$target = trim((string)($o['target'] ?? ''));
	if (!in_array($mode, backup_restore_modes($kind), true))
		return result_error('Choose what to restore and how.');
	$r = backup_get($acct, $id);
	if (!$r)
		return result_error("There is no backup $id of account $acct.");
	$extra = array();
	if ($kind === 'files') {
		$target = trim(str_replace('\\', '/', $target), '/');
		if (strlen($target) > 1000 || preg_match('#(^|/)\.\.?(/|$)|//|[\x00-\x1f]#', $target))
			return result_error('That is not a place in your home folder.');
		$top = strtok($target, '/');
		if ($top === 'mail')
			return result_error('Mail is restored per mailbox: use Restore email.');
		if ($target === '' && $mode === 'replace')
			return result_error('Your whole home folder cannot be replaced from a backup: choose a folder in it, or put back what is missing.');
	} elseif ($kind === 'database') {
		if (!valid_mysql_identifier($target))
			return result_error('Choose a database of the backup.');
		$scan = backup_scan($acct, $r);
		if (!isset($scan['databases'][$target]))
			return result_error("Database $target is not in this backup.");
		$have = array_column(database_list($acct), 'name');
		if ($mode === 'new') {
			$name = trim((string)($o['name'] ?? ''));
			if (strpos($name, $acct.'_') === 0)
				$name = substr($name, strlen($acct) + 1);
			if (!valid_mysql_identifier($name))
				return result_error('Give the new database a name: letters, digits and underscores.');
			if (in_array($acct.'_'.$name, array_column(database_list(), 'name'), true))
				return result_error("There is already a database {$acct}_$name.");
			$extra['name'] = $name;
			$grant = trim((string)($o['grant'] ?? ''));
			if ($grant !== '') {
				if (($e = scope_check($acct, 'dbuser', $grant)) !== '')
					return result_error($e);
				$extra['grant'] = $grant;
			}
		} elseif (!in_array($target, $have, true) || database_owner($target) !== $acct)
			return result_error("Database $target is not one of your databases now. Restore it into a new database instead (it can have the same name).");
	} elseif ($kind === 'email') {
		$target = strtolower($target);
		if (!preg_match('/^[a-z0-9._+-]{1,64}@[a-z0-9.-]{1,253}$/', $target))
			return result_error('Choose a mailbox of the backup.');
		$scan = backup_scan($acct, $r);
		if (!in_array($target, $scan['mailboxes'], true))
			return result_error("Mailbox $target is not in this backup.");
		$dom = substr(strrchr($target, '@'), 1);
		if (email_domain_owner($dom) !== $acct || !email_domain_exists($dom))
			return result_error("$dom has no email on your account now. Turn email on for it first (Domains), then restore the mailbox.");
	} else
		return result_error('Choose what to restore.');

	$st = $db->prepare("SELECT * FROM backup_restores WHERE account = :a AND status IN ('queued', 'running') ORDER BY id DESC LIMIT 1");
	$st->bindValue(':a', $acct, SQLITE3_TEXT);
	$res = $st->execute();
	if ($res && ($cur = $res->fetchArray(SQLITE3_ASSOC)))
		return result_error('Another restore of your account is running. Wait until it has finished.', array('restore' => backup_restore_public($cur)));

	$st = $db->prepare('INSERT INTO backup_restores (account, backup, kind, target, mode, options, by_user, queued) VALUES (:a, :b, :k, :t, :m, :o, :by, :q)');
	$st->bindValue(':a', $acct, SQLITE3_TEXT);
	$st->bindValue(':b', $r['id'], SQLITE3_TEXT);
	$st->bindValue(':k', $kind, SQLITE3_TEXT);
	$st->bindValue(':t', $target, SQLITE3_TEXT);
	$st->bindValue(':m', $mode, SQLITE3_TEXT);
	$st->bindValue(':o', json_encode($extra), SQLITE3_TEXT);
	$st->bindValue(':by', (string)$by, SQLITE3_TEXT);
	$st->bindValue(':q', time(), SQLITE3_INTEGER);
	$st->execute();
	$jid = (int)$db->lastInsertRowID();
	$what = $kind === 'files' ? '~/'.$target : $target;
	$cmd = server_sudo().'systemd-run --quiet --collect --unit='.escapeshellarg('reqad-restore-'.$acct)
		.' --description='.escapeshellarg('Reqad restore of '.$what.' ('.$acct.')')
		.' -p RuntimeMaxSec=21600 -p Nice=10 -p IOSchedulingClass=best-effort -p IOSchedulingPriority=7'
		.' -- '._PATH.'/bin/reqad backup __run '.$jid.' 2>&1';
	exec($cmd, $out, $rc);
	if ($rc !== 0) {
		$msg = trim(implode(' ', $out));
		$db->exec("UPDATE backup_restores SET status = 'failed', finished = ".time().", message = '"
		         .SQLite3::escapeString('Could not start: '.substr($msg, 0, 200))."' WHERE id = ".$jid);
		return result_error(stripos($msg, 'already') !== false ? 'Another restore of your account is running. Wait until it has finished.' : 'Could not start: '.$msg);
	}
	route_log("restore $kind $what from $id for $acct");
	return result_ok("The restore of $what from the backup of ".$r['date'].' started.', array('restore' => backup_restore_public(backup_restore_get($jid))));
}

/* A name for files kept by a restore: ~/backup-restores/<what>-<date-time> */
function backup_keep_name($what) {
	return 'backup-restores/'.preg_replace('/[^a-z0-9@._-]+/', '-', strtolower($what)).'-'.date('Ymd-His');
}

/* The runner (root, inside the unit): run one queued restore. */
function backup_restore_execute($id) {
	global $db;
	$db->busyTimeout(10000);
	$j = backup_restore_get($id);
	if (!$j || $j['status'] !== 'queued')
		return;
	$set = function (array $f) use ($db, $id) {
		$sets = array();
		foreach ($f as $k => $v)
			$sets[] = $k.' = '.(is_int($v) ? $v : "'".SQLite3::escapeString((string)$v)."'");
		$db->exec('UPDATE backup_restores SET '.implode(', ', $sets).' WHERE id = '.(int)$id);
	};
	$set(array('status' => 'running', 'started' => time()));
	$acct = $j['account'];
	$r = backup_get($acct, $j['backup']);
	if (!$r) {
		$set(array('status' => 'failed', 'finished' => time(), 'message' => 'The backup is no longer there.'));
		return;
	}
	$opts = json_decode((string)$j['options'], true) ?: array();
	$fn = 'backup_restore_'.$j['kind'];
	try {
		list($ok, $msg, $kept, $log) = $fn($acct, $r, $j, $opts);
	} catch (Throwable $e) {
		list($ok, $msg, $kept, $log) = array(false, 'The restore stopped with an error: '.$e->getMessage(), '', '');
	}
	$set(array('status' => $ok ? 'ok' : 'failed', 'finished' => time(), 'message' => substr($msg, 0, 1000), 'kept' => $kept, 'output' => substr($log, 0, 65536)));
	plugin_hook('backup.restored', array('account' => $acct, 'backup' => $r['id'], 'kind' => $j['kind'], 'target' => $j['target'], 'ok' => $ok));
}

/* The archive's bytes into the helper's stdin (the helper unpacks only what it needs). */
function backup_feed(array $r, $acct) {
	return function ($pipe) use ($r, $acct) {
		$src = popen(backup_source_cmd($r, $acct).' 2>/dev/null', 'r');
		if (!$src)
			return;
		while (!feof($src) && ($buf = fread($src, 1048576)) !== false && $buf !== '')
			if (@fwrite($pipe, $buf) === false)
				break;          // the helper is done with it (or failed): its answer says
		pclose($src);
	};
}

function backup_restore_files($acct, array $r, array $j, array $opts) {
	$path = $j['target'];
	$keep = $j['mode'] === 'missing' ? '' : backup_keep_name('files');
	$a = files_run($acct, array('verb' => 'bkrestore', 'root' => 'backup-'.$acct, 'kind' => 'files', 'path' => $path,
	                            'mode' => $j['mode'], 'keep' => $keep, 'job' => (int)$j['id']), backup_feed($r, $acct), null, 21600);
	$res = files_result($a);
	if (!$res['ok'])
		return array(false, $res['error'], '', '');
	$d = $res['data'];
	$what = $path === '' ? 'your home folder' : '~/'.$path;
	$files = function ($n) { return $n.' '.($n === 1 ? 'file' : 'files'); };
	$parts = array();
	if ($d['added'])
		$parts[] = $files($d['added']).' put back';
	if ($d['replaced'])
		$parts[] = $files($d['replaced']).' changed back to the backup\'s version';
	if ($d['deleted'])
		$parts[] = $files($d['deleted']).' added since the backup deleted';
	$msg = !$parts ? ($j['mode'] === 'missing' ? "Nothing was missing in $what: everything the backup has is there (files you have now were left as they are; to get the backup's versions back, choose \"Merge, the backup wins\")." : "$what is already as in the backup.")
	     : ucfirst($what).': '.implode(', ', $parts).'.';
	if ($d['kept'] !== '')
		$msg .= ' What was replaced or deleted is in ~/'.$d['kept'].'.';
	return array(true, $msg, (string)$d['kept'], '');
}

function backup_restore_database($acct, array $r, array $j, array $opts) {
	$src_db = $j['target'];
	$scan = backup_scan($acct, $r);
	$cmd = backup_db_cmd($r, $acct, $src_db, $scan);
	if ($cmd === '')
		return array(false, "Database $src_db is not in this backup.", '', '');
	$dest = $j['mode'] === 'new' ? $acct.'_'.$opts['name'] : $src_db;
	if ($j['mode'] !== 'new' && (database_owner($dest) !== $acct || !in_array($dest, array_column(database_list($acct), 'name'), true)))
		return array(false, "Database $dest is not one of your databases now.", '', '');
	$log = array();
	/* 1. the dump, out of the backup into a file of root's first: nothing is
	      changed before we know the backup has a whole dump */
	$tmpdir = '/var/lib/reqad/restore-tmp';
	if (!is_dir($tmpdir))
		@mkdir($tmpdir, 0700, true);
	$tmp = $tmpdir.'/db-'.(int)$j['id'].'.sql.gz';
	/* no pipefail: the archive's reader stops at the dump, so cat / ssh before it see a closed pipe;
	   whether the dump is whole is what counts */
	exec('bash -c '.escapeshellarg('umask 077; '.$cmd.' | gzip -1 > '.escapeshellarg($tmp)).' 2>&1', $o, $rc);
	$tail = trim((string)shell_exec('gzip -dc '.escapeshellarg($tmp).' 2>/dev/null | tail -c 300'));
	if (strpos($tail, 'Dump completed') === false) {
		@unlink($tmp);
		return array(false, "The dump of $src_db could not be read from the backup, or it is not complete: nothing was changed.", '', implode("\n", $o));
	}
	$kept = '';
	if ($j['mode'] === 'new') {
		$c = database_create(array('account' => $acct, 'name' => $opts['name']));
		if (!$c['ok']) {
			@unlink($tmp);
			return array(false, 'The new database could not be made: '.$c['error'], '', '');
		}
		if (!empty($opts['grant']) && function_exists('database_grant'))
			database_grant($opts['grant'], $dest, 'all');
	} else {
		/* 2. a copy of the database as it is now, in the account's home, as the account */
		$file = backup_keep_name($dest).'.sql.gz';
		$write = 'umask 077; mkdir -p "$HOME/backup-restores" && cat > "$HOME/'.$file.'"';
		$dump = 'set -o pipefail; '.server_sudo().'mysqldump --single-transaction --quick --routines --triggers --default-character-set=utf8mb4 '
		      .escapeshellarg($dest).' | gzip -6 | sudo -u '.escapeshellarg($acct).' -H sh -c '.escapeshellarg($write);
		exec('bash -c '.escapeshellarg($dump).' 2>&1', $o2, $rc2);
		if ($rc2 !== 0) {
			@unlink($tmp);
			return array(false, "A copy of $dest as it is now could not be saved first (".trim(implode(' ', $o2))."): nothing was changed.", '', '');
		}
		$kept = $file;
		$log[] = "saved ~/$file";
		/* 3. replace: emptied first (the database itself, its charset and the users' access stay) */
		if ($j['mode'] === 'replace') {
			$q = "SELECT CONCAT('DROP ', IF(table_type = 'VIEW', 'VIEW', 'TABLE'), ' IF EXISTS `', REPLACE(table_name, '`', '``'), '`;') FROM information_schema.tables WHERE table_schema = '".str_replace("'", "''", $dest)."'";
			$drops = (string)shell_exec(server_sudo().'mysql -N -B -e '.escapeshellarg($q).' 2>/dev/null');
			$sql = "SET FOREIGN_KEY_CHECKS=0;\n".$drops."\nSET FOREIGN_KEY_CHECKS=1;\n";
			$p = proc_open(server_sudo().'mysql '.escapeshellarg($dest).' 2>&1', array(0 => array('pipe', 'r'), 1 => array('pipe', 'w')), $pp);
			fwrite($pp[0], $sql);
			fclose($pp[0]);
			$e = stream_get_contents($pp[1]);
			fclose($pp[1]);
			if (proc_close($p) !== 0) {
				@unlink($tmp);
				return array(false, "$dest could not be emptied first: ".trim($e).' Nothing was loaded; the copy of it is in ~/'.$file.'.', $kept, '');
			}
		}
	}
	/* 4. load it: no DEFINERs (they name users of when it was made), no USE or CREATE DATABASE */
	$load = 'set -o pipefail; gzip -dc '.escapeshellarg($tmp).' | sed -e '.escapeshellarg('s/DEFINER=`[^`]*`@`[^`]*`//g').' -e '.escapeshellarg('/^USE `/d').' -e '.escapeshellarg('/^CREATE DATABASE /d')
	      .' | '.server_sudo().'mysql --default-character-set=utf8mb4 '.escapeshellarg($dest);
	exec('bash -c '.escapeshellarg($load).' 2>&1', $o3, $rc3);
	@unlink($tmp);
	if ($rc3 !== 0)
		return array(false, "Loading the backup into $dest failed: ".substr(trim(implode(' ', $o3)), 0, 400).($kept !== '' ? " The copy of it from before is in ~/$kept." : ''), $kept, implode("\n", $o3));
	$when = $r['date'].($r['time'] !== '' ? ' '.$r['time'] : '');
	$msg = $j['mode'] === 'new' ? "The database $src_db from the backup of $when is now in $dest."
	     : ($j['mode'] === 'replace' ? "$dest is now as in the backup of $when." : "The tables of $dest in the backup of $when were put back; tables made since were kept.");
	if ($kept !== '')
		$msg .= " A copy of it from before the restore is in ~/$kept.";
	return array(true, $msg, $kept, implode("\n", $log));
}

function backup_restore_email($acct, array $r, array $j, array $opts) {
	$email = $j['target'];
	list($box, $dom) = explode('@', $email, 2);
	$note = '';
	if (!in_array($email, mailbox_list(true), true)) {
		/* gone since: made again, with its password from the backup */
		$hash = backup_mail_hash($r, $acct, $email);
		$c = email_create(array('email' => $email, 'password' => mailbox_random_password(24)));
		if (!$c['ok'])
			return array(false, "The mailbox $email could not be made again: ".$c['error'], '', '');
		$note = $hash !== '' && mailbox_set_hash($email, $hash) === ''
		      ? " The mailbox was made again, with its password from the backup."
		      : " The mailbox was made again with a new password: set one in Email accounts.";
	}
	$keep = $j['mode'] === 'replace' ? backup_keep_name('mail-'.$email) : '';
	$a = files_run($acct, array('verb' => 'bkrestore', 'root' => 'backup-'.$acct, 'kind' => 'mail', 'path' => 'mail/'.$dom.'/'.$box,
	                            'mode' => $j['mode'], 'keep' => $keep, 'job' => (int)$j['id']), backup_feed($r, $acct), null, 21600);
	$res = files_result($a);
	if (!$res['ok'])
		return array(false, $res['error'].$note, '', '');
	/* dovecot finds the messages put back and rebuilds its indexes */
	exec(server_sudo().'doveadm force-resync -u '.escapeshellarg($email).' '.escapeshellarg('*').' 2>&1', $o, $rc);
	$d = $res['data'];
	$n = (int)$d['added'] + (int)$d['replaced'];
	$msg = $j['mode'] === 'replace'
	     ? "$email is now as in the backup of ".$r['date'].($d['deleted'] ? ' ('.$d['deleted'].' '.($d['deleted'] === 1 ? 'message' : 'messages').' that came in or moved since taken out).' : '.')
	     : ($n ? $n.' '.($n === 1 ? 'message' : 'messages').' put back in '.$email.($d['folders'] ? ', with '.$d['folders'].' '.($d['folders'] === 1 ? 'folder that had' : 'folders that had').' been deleted' : '').'.'
	           : ($note !== '' ? "$email had no messages in that backup." : "Nothing was missing in $email: it has every message the backup has."));
	if ($d['kept'] !== '')
		$msg .= ' What was replaced or taken out is in ~/'.$d['kept'].'.';
	return array(true, $msg.$note, (string)$d['kept'], $rc !== 0 ? implode("\n", $o) : '');
}

/* The password hash a mailbox had in the backup ('' when not found). Kept in memory only. */
function backup_mail_hash(array $r, $acct, $email) {
	$dom = substr(strrchr($email, '@'), 1);
	$acc = account_get($GLOBALS['db'], $acct);
	$member = 'backup-'.$acct.'/email/'.($acc && strtolower($acc['domain']) === $dom ? '' : 'domains/'.$dom.'/').'dovecot-users';
	$p = proc_open(backup_source_cmd($r, $acct).' | tar -xzOf - --occurrence=1 '.escapeshellarg($member).' 2>/dev/null',
	               array(1 => array('pipe', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes);
	$hash = '';
	if (is_resource($p)) {
		while (($l = fgets($pipes[1], 65536)) !== false) {
			$f = explode(':', rtrim($l, "\n"));
			if (count($f) >= 2 && strtolower($f[0]) === $email)
				$hash = ltrim($f[1], '!');
		}
		fclose($pipes[1]);
		proc_close($p);
	}
	return $hash;
}
