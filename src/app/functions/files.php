<?php
/*
 * The file manager: a hosting account's files, for the 2087 file manager,
 * the API (and so MCP and the account panel on 2083).
 *
 * Everything happens in scripts/files/fm-helper.php, started by fm-run.sh
 * with the ACCOUNT's uid, gid and groups - never as root. So the kernel's own
 * permissions decide what can be read or changed, exactly as for the
 * account's website code and SSH login; the helper also keeps to the home
 * folder (paths relative to it, no "..", symlinks followed only to places
 * inside it) and refuses to rename or delete what the sites and mail depend
 * on (public_html, mail, logs, ...).
 *
 * Paths are relative to the account's home folder ("" = the home folder).
 * The functions take an account name only - callers acting for an account
 * (API account tokens, the account panel) pass that account, never one from
 * the request. Results are result_ok() / result_error(); an error with a
 * {placeholder} also carries error_key + error_args for translations.
 */

/* largest file opened in the editor (fm-helper.php has the same) */
const FILES_MAX_EDIT = 2097152;

/* '' when $user is an account of this server, else why not */
function files_account_error($user) {
	global $db;
	if (!is_string($user) || !valid_username($user))
		return 'Not a valid account name.';
	if (!account_get($db, $user))
		return "There is no account $user.";
	return '';
}

/* The helper's answer as a result: ok -> result_ok($message, data). */
function files_result(array $a, $message = '', array $margs = array()) {
	if (!empty($a['ok']))
		return result_ok(strtr($message, files_args($margs)), array_diff_key($a, array('ok' => 1, 'error' => 1, 'args' => 1, 'code' => 1)));
	$args = isset($a['args']) && is_array($a['args']) ? $a['args'] : array();
	$key = (string)($a['error'] ?? 'The file manager did not answer.');
	$r = result_error(strtr($key, files_args($args)), array('code' => (string)($a['code'] ?? 'error')) + array_diff_key($a, array('ok' => 1, 'error' => 1, 'code' => 1, 'args' => 1)));
	if ($args) {
		$r['error_key'] = $key;
		$r['error_args'] = $args;
	}
	return $r;
}

function files_args(array $a) {
	$out = array();
	foreach ($a as $k => $v)
		$out['{'.$k.'}'] = (string)$v;
	return $out;
}

/*
 * One request to the helper, as $user. $in: more bytes for its stdin (a
 * string, or a function given the pipe to write them into); $out: a function
 * given the answer and the stdout pipe when the bytes follow the answer (a
 * download). Returns the helper's answer (array with ok).
 */
function files_run($user, array $req, $in = null, $out = null, $secs = 290) {
	if (($e = files_account_error($user)) !== '')
		return array('ok' => false, 'error' => $e, 'code' => 'account');
	$cmd = 'sudo -n '.escapeshellarg(_PATH.'/scripts/files/fm-run.sh').' '.escapeshellarg($user).' '.(int)$secs;
	$p = proc_open($cmd, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	if (!is_resource($p))
		return array('ok' => false, 'error' => 'The file manager could not start.', 'code' => 'start');
	stream_set_blocking($pipes[2], false);
	@fwrite($pipes[0], json_encode($req, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
	if (is_string($in) && $in !== '') {
		for ($off = 0, $len = strlen($in); $off < $len; ) {
			$n = @fwrite($pipes[0], substr($in, $off, 1048576));
			if (!$n)
				break;
			$off += $n;
		}
	} elseif (is_callable($in))
		$in($pipes[0]);
	@fclose($pipes[0]);
	$line = stream_get_line($pipes[1], 64 * 1048576, "\n");
	$a = is_string($line) ? json_decode($line, true) : null;
	if (is_array($a) && !empty($a['ok']) && is_callable($out))
		$out($a, $pipes[1]);
	else
		stream_get_contents($pipes[1]);
	fclose($pipes[1]);
	$err = trim((string)stream_get_contents($pipes[2]));
	fclose($pipes[2]);
	$rc = proc_close($p);
	if (!is_array($a)) {
		$why = $rc === 2 && strpos($err, 'fm-run: ') === 0 ? substr($err, 8) : ($rc === 124 ? 'it took too long' : 'no answer');
		return array('ok' => false, 'error' => 'The file manager failed ({reason}).', 'args' => array('reason' => $why), 'code' => 'helper');
	}
	return $a;
}

/* "a/b" -> "b" */
function files_base($path) {
	$p = rtrim((string)$path, '/');
	$i = strrpos($p, '/');
	return $i === false ? $p : substr($p, $i + 1);
}

/* One folder: path, real (after symlinks), writable, more (cut at 5000),
   entries [{n name, t d|f|l|x, s size, m mtime, p mode "0644", o uid when not
   the account's own, to + lt (d|f|x = leads nowhere or out) for links,
   bad = a name that is not UTF-8}]. */
function files_list($user, $path = '') {
	return files_result(files_run($user, array('verb' => 'list', 'path' => (string)$path)));
}

/* What takes up space in a folder: path, total, entries [{n, t d|f|l, size,
   bad}] biggest first (bytes allocated on disk, links not followed), more +
   rest (the entries left out and their bytes), unreadable (folders that could
   not be read: the sizes are then too low). For the home folder also
   quota_mb (0 = no limit), and the fresh total becomes the account's
   disk_usage (what update_disk_usage measures with du -sm). */
function files_usage($user, $path = '') {
	global $db;
	$r = files_result(files_run($user, array('verb' => 'usage', 'path' => (string)$path)));
	if ($r['ok'] && ($r['data']['path'] ?? null) === '') {
		$a = account_get($db, $user);
		$r['data']['quota_mb'] = (int)($a['disk_quota'] ?? 0);
		$mb = (int)ceil((int)$r['data']['total'] / 1048576);
		if ($db instanceof SQLite3 && ($st = @$db->prepare('UPDATE accounts SET disk_usage = :m WHERE user = :u'))) {
			$st->bindValue(':m', $mb, SQLITE3_INTEGER);
			$st->bindValue(':u', $user, SQLITE3_TEXT);
			$st->execute();
		}
	}
	return $r;
}

/* A text file for the editor: content, size, mtime, mode, writable. */
function files_read($user, $path) {
	return files_result(files_run($user, array('verb' => 'read', 'path' => (string)$path)));
}

/* Save a text file. $mtime: the time it had when it was opened - another
   change since then is refused (code "changed") unless $force. */
function files_save($user, $path, $content, $mtime = null, $create = false, $force = false) {
	$content = (string)$content;
	if (strlen($content) > FILES_MAX_EDIT)
		return result_error('This is too big to save from the editor (at most 2 MB).');
	$req = array('verb' => 'save', 'path' => (string)$path, 'size' => strlen($content), 'create' => (bool)$create, 'force' => (bool)$force);
	if ($mtime !== null)
		$req['mtime'] = (int)$mtime;
	return files_result(files_run($user, $req, $content), 'Saved {name}.', array('name' => files_base($path)));
}

function files_mkdir($user, $path) {
	return files_result(files_run($user, array('verb' => 'mkdir', 'path' => (string)$path)), 'Folder {name} made.', array('name' => files_base($path)));
}

/* An empty file. */
function files_create($user, $path) {
	return files_result(files_run($user, array('verb' => 'create', 'path' => (string)$path)), 'File {name} made.', array('name' => files_base($path)));
}

/* Rename or move: $to is the new path, or a folder to move it into. */
function files_rename($user, $path, $to) {
	$a = files_run($user, array('verb' => 'rename', 'path' => (string)$path, 'to' => (string)$to));
	$new = (string)($a['path'] ?? '');
	$from_dir = dirname('/'.trim((string)$path, '/'));
	$same = $new !== '' && dirname('/'.$new) === $from_dir;
	return files_result($a, $same ? 'Renamed {name} to {new}.' : 'Moved {name} to {dir}.',
	                    array('name' => files_base($path), 'new' => files_base($new), 'dir' => dirname('/'.$new) === '/' ? 'the home folder' : ltrim(dirname('/'.$new), '/')));
}

/* Copy (folders with all they hold); to the same folder = "name copy". */
function files_copy($user, $path, $to) {
	return files_result(files_run($user, array('verb' => 'copy', 'path' => (string)$path, 'to' => (string)$to)), 'Copied {name}.', array('name' => files_base($path)));
}

/* $mode: three octal digits ("644"). */
function files_chmod($user, $path, $mode) {
	return files_result(files_run($user, array('verb' => 'chmod', 'path' => (string)$path, 'mode' => (string)$mode)), 'Permissions of {name} set to {mode}.',
	                    array('name' => files_base($path), 'mode' => ltrim((string)$mode, '0') ?: '0'));
}

/* Delete files and folders (folders with all they hold). data: deleted,
   errors [{path, error}] - some may go while others are refused. */
function files_delete($user, array $paths) {
	$paths = array_values(array_filter($paths, 'is_string'));
	if (!$paths)
		return result_error('Nothing to delete.');
	$a = files_run($user, array('verb' => 'delete', 'paths' => $paths));
	foreach ((array)($a['errors'] ?? array()) as $i => $e)
		if (isset($e['args']) && is_array($e['args']))
			$a['errors'][$i]['error'] = strtr($e['error'], files_args($e['args']));
	$n = (int)($a['deleted'] ?? 0);
	$msg = count($paths) === 1 ? 'Deleted {name}.' : (empty($a['errors']) ? 'Deleted {n} items.' : 'Deleted {n} of {total}.');
	return files_result($a, $msg, array('name' => files_base($paths[0]), 'n' => $n, 'total' => count($paths)));
}

/* Pack $items (names in $dir) into $name.zip or $name.tar.gz in $dir. */
function files_compress($user, $dir, array $items, $name, $format = 'zip') {
	return files_result(files_run($user, array('verb' => 'compress', 'dir' => (string)$dir, 'items' => array_values($items), 'name' => (string)$name,
	                                           'format' => $format === 'tgz' ? 'tgz' : 'zip')), 'Made {name}.', array('name' => preg_replace('/\.(zip|tar\.gz|tgz)$/i', '', (string)$name).($format === 'tgz' ? '.tar.gz' : '.zip')));
}

/* Unpack an archive into $dest (a folder; made when missing, one level).
   Files already there are kept unless $overwrite. */
function files_extract($user, $path, $dest, $overwrite = false) {
	return files_result(files_run($user, array('verb' => 'extract', 'path' => (string)$path, 'dest' => (string)$dest, 'overwrite' => (bool)$overwrite)),
	                    'Unpacked {name}.', array('name' => files_base($path)));
}

/* Upload: $size bytes, written into the helper's stdin by $writer($pipe).
   An existing file is replaced only with $overwrite (else code "exists"). */
function files_upload($user, $dir, $name, $size, callable $writer, $overwrite = false) {
	$size = (int)$size;
	if ($size < 0)
		return result_error('Not a valid upload.');
	return files_result(files_run($user, array('verb' => 'upload', 'dir' => (string)$dir, 'name' => (string)$name, 'size' => $size, 'overwrite' => (bool)$overwrite),
	                              $writer, null, 3600), 'Uploaded {name}.', array('name' => (string)$name));
}

/* Download: $sink($meta, $pipe) is given {name, size (null for a folder,
   sent as a zip made on the way)} and the stream of its bytes. */
function files_download($user, $path, callable $sink) {
	return files_result(files_run($user, array('verb' => 'download', 'path' => (string)$path), null, $sink, 3600));
}

/* "-rw-r--r--" from "0644" and the entry type (the 2087 file manager shows it) */
function files_mode_symbolic($mode, $type = 'f') {
	$m = octdec((string)$mode);
	$s = $type === 'd' ? 'd' : ($type === 'l' ? 'l' : '-');
	foreach (array(6, 3, 0) as $sh)
		$s .= (($m >> $sh) & 4 ? 'r' : '-').(($m >> $sh) & 2 ? 'w' : '-').(($m >> $sh) & 1 ? 'x' : '-');
	return $s;
}
