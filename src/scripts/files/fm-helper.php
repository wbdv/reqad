<?php
/*
 * Reqad file manager helper. Runs AS the hosting account (fm-run.sh starts it
 * with the account's uid, gid and groups), never as root: the kernel decides
 * what it may read or change, exactly as for the account's own website code.
 * On top of that it keeps to the account's home folder: paths are always
 * relative to it, ".." is refused, and a symlink is followed only when it
 * leads to somewhere inside it (a link that leads out is shown, and can be
 * deleted or renamed, but never opened). Not open_basedir: it resolves
 * symlinks for every check, so such a link could not even be deleted.
 *
 * Request: the first line of stdin is JSON {"verb": ..., ...}; uploads and
 * saves send their bytes after that line. Answer: one JSON line on stdout
 * ({"ok": true, ...} or {"ok": false, "error": "...", "code": "..."}); a
 * download sends its bytes after that line. argv[1] = the home folder.
 *
 * Paths are relative to the home folder ("public_html/index.php", "" = the
 * home folder itself). Names that are not valid UTF-8 are listed with
 * "bad": true and cannot be changed here (JSON cannot carry them).
 */

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
umask(022);
set_time_limit(0);

const FM_MAX_LIST = 5000;               // entries listed in one folder
const FM_MAX_EDIT = 2 * 1048576;        // largest file opened in the editor
/* top-level entries the account's mail, sites and logs depend on: never
   renamed, moved or deleted from here */
const FM_PROTECTED = array('public_html', 'www', 'mail', 'logs', 'tmp', 'etc', '.ssh', 'apps');

function out(array $a) {
	echo json_encode($a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
}

function fail($msg, $code = 'error', array $extra = array()) {
	out(array('ok' => false, 'error' => $msg, 'code' => $code) + $extra);
	exit(0);
}

/* the uid this process runs as (no posix extension under php -n) */
function my_uid() {
	foreach (file('/proc/self/status') ?: array() as $l)
		if (strpos($l, 'Uid:') === 0)
			return (int)preg_split('/\s+/', trim($l))[1];
	return -1;
}

$home = (string)($argv[1] ?? '');
$uid = my_uid();
if ($uid < 1000 || !preg_match('#^/home/[a-z][a-z0-9]{0,31}$#', $home) || !is_dir($home) || is_link($home) || fileowner($home) !== $uid)
	fail('The file manager cannot start for this account.');
define('HOME', $home);
define('MY_UID', $uid);
proc_nice(10);

$line = fgets(STDIN, 1048576);
$req = is_string($line) ? json_decode($line, true) : null;
if (!is_array($req) || !isset($req['verb']) || !is_string($req['verb']))
	fail('Malformed request.');

/* ---- paths ------------------------------------------------------------------ */

/* "a/b/c" -> array('a','b','c'); false for "..", a NUL or an over-long name */
function segs($rel) {
	if (!is_string($rel) || strpos($rel, "\0") !== false || strlen($rel) > 4096)
		return false;
	$out = array();
	foreach (explode('/', $rel) as $s) {
		if ($s === '' || $s === '.')
			continue;
		if ($s === '..' || strlen($s) > 255)
			return false;
		$out[] = $s;
	}
	return $out;
}

function rel_of(array $segs) {
	return implode('/', $segs);
}

function inside($abs) {
	return $abs === HOME || strpos($abs, HOME.'/') === 0;
}

/* the real path of an existing entry, symlinks followed, or false when it is
   missing or leads out of the home folder */
function real_in($rel) {
	$s = segs($rel);
	if ($s === false)
		return false;
	$r = realpath(HOME.($s ? '/'.rel_of($s) : ''));
	return $r !== false && inside($r) ? $r : false;
}

/* The entry itself (its last part NOT followed: a symlink is the link), in a
   real folder inside the home folder: array(abs, parent, name), or false.
   Never the home folder itself. */
function entry($rel) {
	$s = segs($rel);
	if ($s === false || !$s)
		return false;
	$name = array_pop($s);
	$parent = realpath(HOME.($s ? '/'.rel_of($s) : ''));
	if ($parent === false || !inside($parent) || !is_dir($parent))
		return false;
	return array($parent.'/'.$name, $parent, $name);
}

/* The entry's path relative to the home folder after symlinks in its folders */
function rel_from_abs($abs) {
	return $abs === HOME ? '' : substr($abs, strlen(HOME) + 1);
}

function is_protected($abs) {
	return in_array(rel_from_abs($abs), FM_PROTECTED, true);
}

/* a new name for something in a folder */
function good_name($n) {
	return is_string($n) && $n !== '' && $n !== '.' && $n !== '..' && strpos($n, '/') === false
	    && strpos($n, "\0") === false && strlen($n) <= 255 && preg_match('//u', $n);
}

function exists_l($abs) {
	return file_exists($abs) || is_link($abs);
}

function need_entry($rel, $what = 'That file or folder') {
	$e = entry($rel);
	if ($e === false)
		fail("$what is not in your home folder.", 'path');
	return $e;
}

/* ---- running a program (as this account) ------------------------------------- */

/* array(exit code, output) of a program run without a shell */
function run(array $argv, $cwd = null) {
	$p = proc_open($argv, array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('redirect', 1)), $pipes, $cwd);
	if (!is_resource($p))
		return array(127, 'could not start '.$argv[0]);
	$o = stream_get_contents($pipes[1]);
	fclose($pipes[1]);
	return array(proc_close($p), trim((string)$o));
}

/* ---- listing ----------------------------------------------------------------- */

function mode_str($m) {
	return sprintf('%04o', $m & 07777);
}

function row($dir, $name) {
	$abs = $dir.'/'.$name;
	$l = lstat($abs);
	if ($l === false)
		return null;
	$fmt = $l['mode'] & 0170000;
	$r = array('n' => $name, 's' => (int)$l['size'], 'm' => (int)$l['mtime'], 'p' => mode_str($l['mode']));
	if (!preg_match('//u', $name))
		$r['bad'] = true;
	if ($l['uid'] !== MY_UID)
		$r['o'] = (int)$l['uid'];            // not the account's own: usually read-only
	if ($fmt === 0040000)
		$r['t'] = 'd';
	elseif ($fmt === 0100000)
		$r['t'] = 'f';
	elseif ($fmt === 0120000) {
		$r['t'] = 'l';
		$r['to'] = (string)readlink($abs);
		$real = realpath($abs);
		if ($real === false || !inside($real))
			$r['lt'] = 'x';                  // broken, or leads out of the home folder
		else {
			$r['lt'] = is_dir($real) ? 'd' : 'f';
			if ($r['lt'] === 'f')
				$r['s'] = (int)filesize($real);
		}
	} else
		$r['t'] = 'x';                       // a socket, fifo or device
	return $r;
}

function v_list(array $q) {
	$real = real_in($q['path'] ?? '');
	if ($real === false)
		fail('That folder is not in your home folder.', 'path');
	if (!is_dir($real))
		fail('That is a file, not a folder.', 'notdir');
	$h = opendir($real);
	if (!$h)
		fail('This folder cannot be opened (no permission).', 'perm');
	$rows = array();
	$more = false;
	while (($n = readdir($h)) !== false) {
		if ($n === '.' || $n === '..')
			continue;
		if (count($rows) >= FM_MAX_LIST) {
			$more = true;
			break;
		}
		if ($r = row($real, $n))
			$rows[] = $r;
	}
	closedir($h);
	usort($rows, function ($a, $b) {
		$da = $a['t'] === 'd' || ($a['lt'] ?? '') === 'd';
		$db = $b['t'] === 'd' || ($b['lt'] ?? '') === 'd';
		return $da !== $db ? ($da ? -1 : 1) : strnatcasecmp($a['n'], $b['n']);
	});
	$s = segs($q['path'] ?? '');
	out(array('ok' => true, 'path' => rel_of($s), 'real' => rel_from_abs($real), 'writable' => is_writable($real),
	          'entries' => $rows, 'more' => $more));
}

function v_stat(array $q) {
	$e = entry($q['path'] ?? '');
	if ($e === false || !exists_l($e[0]))
		fail('There is no such file or folder.', 'missing');
	out(array('ok' => true, 'entry' => row($e[1], $e[2])));
}

/* ---- the editor ---------------------------------------------------------------- */

function v_read(array $q) {
	$real = real_in($q['path'] ?? '');
	if ($real === false)
		fail('That file is not in your home folder.', 'path');
	if (!is_file($real))
		fail('That is not a file.', 'notfile');
	$size = filesize($real);
	if ($size > FM_MAX_EDIT)
		fail('This file is too big to edit here ({size}). Download it instead.', 'big', array('args' => array('size' => size_txt($size))));
	$c = file_get_contents($real);
	if ($c === false)
		fail('This file cannot be read (no permission).', 'perm');
	if (strpos($c, "\0") !== false || !preg_match('//u', $c))
		fail('This is not a text file, so it cannot be edited here. Download it instead.', 'binary');
	out(array('ok' => true, 'content' => $c, 'size' => $size, 'mtime' => filemtime($real), 'mode' => mode_str(fileperms($real)),
	          'writable' => is_writable($real)));
}

function size_txt($b) {
	return $b >= 1048576 ? round($b / 1048576, 1).' MB' : max(1, round($b / 1024)).' KB';
}

/* Write $src (a stream: the rest of stdin) to $dest through a temporary file
   in the same folder, then rename: a full disk leaves the old file whole.
   Keeps the old file's permissions (and group, when this account may). */
function write_into($dest, $src, $expect_size = null) {
	$dir = dirname($dest);
	$old = is_file($dest) ? stat($dest) : null;
	$tmp = $dir.'/.'.substr(basename($dest), 0, 200).'.reqad-'.bin2hex(random_bytes(4));
	$fh = fopen($tmp, 'x');
	if (!$fh)
		return is_writable($dir) ? 'The file could not be written.' : 'You cannot write in this folder (no permission).';
	$n = 0;
	$err = '';
	while (!feof($src)) {
		$buf = fread($src, 1048576);
		if ($buf === false)
			break;
		if ($buf === '')
			continue;
		if ($expect_size !== null && $n + strlen($buf) > $expect_size)
			$buf = substr($buf, 0, $expect_size - $n);
		$w = fwrite($fh, $buf);
		if ($w === false || $w < strlen($buf)) {
			$err = 'There is not enough disk space for this file (your package\'s limit, or the server is full).';
			break;
		}
		$n += $w;
		if ($expect_size !== null && $n >= $expect_size)
			break;
	}
	if ($err === '' && !fflush($fh))
		$err = 'There is not enough disk space for this file (your package\'s limit, or the server is full).';
	fclose($fh);
	if ($err === '' && $expect_size !== null && $n !== $expect_size)
		$err = 'The upload was cut off before the end. Try again.';
	if ($err !== '') {
		unlink($tmp);
		return $err;
	}
	if ($old) {
		chmod($tmp, $old['mode'] & 07777);
		if ($old['gid'] !== filegroup($tmp))
			@chgrp($tmp, $old['gid']);
	} else
		chmod($tmp, 0644);
	if (!rename($tmp, $dest)) {
		unlink($tmp);
		return 'The file could not be written.';
	}
	return '';
}

function v_save(array $q) {
	$e = need_entry($q['path'] ?? '', 'That file');
	$dest = $e[0];
	if (is_link($dest)) {
		$real = real_in($q['path']);
		if ($real === false)
			fail('This link leads out of your home folder, so it cannot be saved here.', 'path');
		$dest = $real;
	}
	if (is_dir($dest))
		fail('That is a folder.', 'isdir');
	$exists = file_exists($dest);
	if (!empty($q['create']) && $exists)
		fail('There is already a file or folder named {name} here.', 'exists', array('args' => array('name' => $e[2])));
	if (!$exists && empty($q['create']))
		fail('The file is gone: it was deleted or renamed since you opened it.', 'missing');
	if ($exists && isset($q['mtime']) && empty($q['force']) && (int)$q['mtime'] !== filemtime($dest))
		fail('The file was changed by something else since you opened it.', 'changed', array('mtime' => filemtime($dest)));
	if ($exists && !is_writable($dest))
		fail('You cannot change this file (no permission).', 'perm');
	$err = write_into($dest, STDIN, isset($q['size']) ? (int)$q['size'] : null);
	if ($err !== '')
		fail($err, 'write');
	clearstatcache();
	out(array('ok' => true, 'mtime' => filemtime($dest), 'size' => filesize($dest)));
}

/* ---- uploads and downloads ------------------------------------------------------- */

function v_upload(array $q) {
	$dir = real_in($q['dir'] ?? '');
	if ($dir === false || !is_dir($dir))
		fail('That folder is not in your home folder.', 'path');
	$name = $q['name'] ?? '';
	if (!good_name($name))
		fail('This file name cannot be used.', 'name');
	$dest = $dir.'/'.$name;
	if (is_link($dest) || is_dir($dest))
		fail('There is a folder or link named {name} here.', 'isdir', array('args' => array('name' => $name)));
	if (file_exists($dest) && empty($q['overwrite']))
		fail('{name} is already here.', 'exists', array('args' => array('name' => $name)));
	if (!is_writable($dir))
		fail('You cannot write in this folder (no permission).', 'perm');
	$err = write_into($dest, STDIN, (int)($q['size'] ?? 0));
	if ($err !== '')
		fail($err, 'write');
	out(array('ok' => true, 'name' => $name, 'size' => filesize($dest)));
}

/* a file as it is; a folder as a zip made on the way (no size known) */
function v_download(array $q) {
	$e = need_entry($q['path'] ?? '');
	$real = real_in($q['path']);
	if ($real === false)
		fail('That file is not in your home folder.', 'path');
	if (is_dir($real)) {
		out(array('ok' => true, 'name' => $e[2].'.zip', 'size' => null, 'zip' => true));
		flush();
		$p = proc_open(array('/usr/bin/zip', '-q', '-r', '-y', '-', $e[2]), array(0 => array('file', '/dev/null', 'r'), 1 => STDOUT, 2 => array('file', '/dev/null', 'w')), $pipes, $e[1]);
		if (is_resource($p))
			proc_close($p);
		exit(0);
	}
	if (!is_file($real))
		fail('That is not a file.', 'notfile');
	$fh = fopen($real, 'rb');
	if (!$fh)
		fail('This file cannot be read (no permission).', 'perm');
	out(array('ok' => true, 'name' => $e[2], 'size' => filesize($real), 'mtime' => filemtime($real)));
	flush();
	stream_copy_to_stream($fh, STDOUT);
	fclose($fh);
}

/* ---- changes ---------------------------------------------------------------------- */

function v_mkdir(array $q) {
	$e = need_entry($q['path'] ?? '', 'That folder');
	if (!good_name($e[2]))
		fail('This name cannot be used.', 'name');
	if (exists_l($e[0]))
		fail('There is already a file or folder named {name} here.', 'exists', array('args' => array('name' => $e[2])));
	if (!mkdir($e[0], 0755))
		fail(is_writable($e[1]) ? 'The folder could not be made.' : 'You cannot write in this folder (no permission).', 'perm');
	out(array('ok' => true));
}

function v_create(array $q) {
	$e = need_entry($q['path'] ?? '', 'That file');
	if (!good_name($e[2]))
		fail('This name cannot be used.', 'name');
	if (exists_l($e[0]))
		fail('There is already a file or folder named {name} here.', 'exists', array('args' => array('name' => $e[2])));
	$fh = fopen($e[0], 'x');
	if (!$fh)
		fail(is_writable($e[1]) ? 'The file could not be made.' : 'You cannot write in this folder (no permission).', 'perm');
	fclose($fh);
	chmod($e[0], 0644);
	out(array('ok' => true, 'mtime' => filemtime($e[0])));
}

/* the target of a move or copy: a new name in a folder that exists */
function target($rel, $src_name) {
	$s = segs($rel);
	if ($s === false)
		fail('The new place is not in your home folder.', 'path');
	/* to an existing folder: keep the name */
	$dir = real_in($rel);
	if ($dir !== false && is_dir($dir))
		return array($dir.'/'.$src_name, $dir, $src_name);
	$t = entry($rel);
	if ($t === false)
		fail('The folder to move it to does not exist.', 'path');
	if (!good_name($t[2]))
		fail('This name cannot be used.', 'name');
	return $t;
}

function v_rename(array $q) {
	$src = need_entry($q['path'] ?? '');
	if (!exists_l($src[0]))
		fail('There is no such file or folder.', 'missing');
	if (is_protected($src[0]))
		fail('{name} is used by your sites or mail, so it cannot be renamed or moved.', 'protected', array('args' => array('name' => $src[2])));
	$dst = target($q['to'] ?? '', $src[2]);
	if ($dst[0] === $src[0]) {
		out(array('ok' => true));
		return;
	}
	if (exists_l($dst[0]))
		fail('There is already a file or folder named {name} there.', 'exists', array('args' => array('name' => $dst[2])));
	if (is_dir($src[0]) && !is_link($src[0]) && strpos($dst[1].'/', $src[0].'/') === 0)
		fail('A folder cannot be moved into itself.', 'loop');
	if (!rename($src[0], $dst[0])) {
		list($rc, $o) = run(array('/usr/bin/mv', '-n', '-T', '--', $src[0], $dst[0]));
		if ($rc !== 0 || !exists_l($dst[0]))
			fail(is_writable($src[1]) && is_writable($dst[1]) ? 'It could not be moved: {reason}' : 'You cannot change this folder (no permission).', 'perm', array('args' => array('reason' => $o)));
	}
	out(array('ok' => true, 'path' => rel_from_abs($dst[0])));
}

function v_copy(array $q) {
	$src = need_entry($q['path'] ?? '');
	if (!exists_l($src[0]))
		fail('There is no such file or folder.', 'missing');
	$dst = target($q['to'] ?? '', $src[2]);
	if ($dst[0] === $src[0]) {
		$base = $src[2];
		for ($i = 1; $i < 100 && exists_l($dst[0]); $i++) {
			$n = preg_match('/^(.+?)(\.[A-Za-z0-9]{1,8})$/', $base, $m) && !is_dir($src[0]) ? $m[1].' copy'.($i > 1 ? " $i" : '').$m[2] : $base.' copy'.($i > 1 ? " $i" : '');
			$dst = array($dst[1].'/'.$n, $dst[1], $n);
		}
	}
	if (exists_l($dst[0]))
		fail('There is already a file or folder named {name} there.', 'exists', array('args' => array('name' => $dst[2])));
	if (is_dir($src[0]) && !is_link($src[0]) && strpos($dst[1].'/', $src[0].'/') === 0)
		fail('A folder cannot be copied into itself.', 'loop');
	list($rc, $o) = run(array('/usr/bin/cp', '-a', '-T', '--', $src[0], $dst[0]));
	if ($rc !== 0)
		fail(strpos($o, 'Disk quota') !== false || strpos($o, 'No space') !== false
		     ? 'There is not enough disk space for the copy (your package\'s limit, or the server is full).'
		     : 'It could not be copied: {reason}', 'copy', array('args' => array('reason' => $o)));
	out(array('ok' => true, 'path' => rel_from_abs($dst[0])));
}

function v_chmod(array $q) {
	$e = need_entry($q['path'] ?? '');
	if (!exists_l($e[0]))
		fail('There is no such file or folder.', 'missing');
	if (is_link($e[0]))
		fail('Permissions belong to what a link points to: change them there.', 'link');
	$m = (string)($q['mode'] ?? '');
	if (!preg_match('/^0?[0-7]{3}$/', $m))
		fail('Permissions are three digits from 0 to 7, like 644.', 'mode');
	$mode = octdec($m);
	if (is_dir($e[0]) && !($mode & 0100))
		fail('A folder needs "open" (x) for its owner, or you could not get into it any more.', 'mode');
	if (!chmod($e[0], $mode))
		fail('You cannot change the permissions of this one (it is not yours).', 'perm');
	out(array('ok' => true, 'mode' => mode_str($mode)));
}

function v_delete(array $q) {
	$paths = isset($q['paths']) && is_array($q['paths']) ? $q['paths'] : array();
	if (!$paths || count($paths) > 1000)
		fail('Nothing to delete.', 'empty');
	$done = 0;
	$errors = array();
	foreach ($paths as $rel) {
		$e = entry(is_string($rel) ? $rel : '');
		if ($e === false || !exists_l($e[0])) {
			$errors[] = array('path' => (string)$rel, 'error' => 'There is no such file or folder.');
			continue;
		}
		if (is_protected($e[0])) {
			$errors[] = array('path' => (string)$rel, 'error' => '{name} is used by your sites or mail, so it cannot be deleted.', 'args' => array('name' => $e[2]));
			continue;
		}
		if (is_dir($e[0]) && !is_link($e[0])) {
			list($rc, $o) = run(array('/usr/bin/rm', '-rf', '--one-file-system', '--', $e[0]));
			$ok = !exists_l($e[0]);
		} else
			$ok = unlink($e[0]);
		if ($ok)
			$done++;
		else
			$errors[] = array('path' => (string)$rel, 'error' => 'Some of it could not be deleted (no permission).');
	}
	out(array('ok' => $done > 0 || !$errors, 'deleted' => $done, 'errors' => $errors,
	          'error' => $errors && !$done ? $errors[0]['error'] : '', 'args' => $errors && !$done ? ($errors[0]['args'] ?? array()) : array()));
}

/* ---- archives ----------------------------------------------------------------------- */

function v_compress(array $q) {
	$dir = real_in($q['dir'] ?? '');
	if ($dir === false || !is_dir($dir))
		fail('That folder is not in your home folder.', 'path');
	$fmt = ($q['format'] ?? 'zip') === 'tgz' ? 'tgz' : 'zip';
	$base = trim((string)($q['name'] ?? ''));
	$base = preg_replace('/\.(zip|tar\.gz|tgz)$/i', '', $base);
	$arc = $base.($fmt === 'tgz' ? '.tar.gz' : '.zip');
	if (!good_name($arc) || $base === '' || $base[0] === '-')
		fail('This name cannot be used.', 'name');
	if (exists_l($dir.'/'.$arc))
		fail('There is already a file or folder named {name} here.', 'exists', array('args' => array('name' => $arc)));
	$items = array();
	foreach ((array)($q['items'] ?? array()) as $it) {
		if (!good_name($it) || !exists_l($dir.'/'.$it))
			fail('One of the chosen files is not in this folder any more.', 'missing');
		$items[] = './'.$it;
	}
	if (!$items)
		fail('Choose what to put in the archive.', 'empty');
	$argv = $fmt === 'tgz' ? array_merge(array('/usr/bin/tar', '-czf', './'.$arc, '--'), $items)
	                       : array_merge(array('/usr/bin/zip', '-q', '-r', '-y', './'.$arc), $items);
	list($rc, $o) = run($argv, $dir);
	if ($rc !== 0 || !is_file($dir.'/'.$arc)) {
		if (exists_l($dir.'/'.$arc))
			unlink($dir.'/'.$arc);
		fail(strpos($o, 'quota') !== false || strpos($o, 'space') !== false
		     ? 'There is not enough disk space for the archive (your package\'s limit, or the server is full).'
		     : 'The archive could not be made: {reason}', 'archive', array('args' => array('reason' => $o)));
	}
	out(array('ok' => true, 'name' => $arc, 'size' => filesize($dir.'/'.$arc)));
}

function v_extract(array $q) {
	$arc = real_in($q['path'] ?? '');
	if ($arc === false || !is_file($arc))
		fail('That archive is not in your home folder.', 'path');
	$l = strtolower($arc);
	$kind = substr($l, -4) === '.zip' ? 'zip' : ((substr($l, -7) === '.tar.gz' || substr($l, -4) === '.tgz') ? 'tgz'
	      : (substr($l, -4) === '.tar' ? 'tar' : ((substr($l, -8) === '.tar.bz2') ? 'tbz' : ((substr($l, -7) === '.tar.xz') ? 'txz' : ''))));
	if ($kind === '')
		fail('Only .zip, .tar, .tar.gz, .tgz, .tar.bz2 and .tar.xz archives can be unpacked here.', 'kind');
	$dest_rel = (string)($q['dest'] ?? '');
	$dest = real_in($dest_rel);
	if ($dest === false) {
		/* a new folder (one level) */
		$e = entry($dest_rel);
		if ($e === false || !good_name($e[2]))
			fail('The folder to unpack into is not in your home folder.', 'path');
		if (!mkdir($e[0], 0755))
			fail('The folder to unpack into could not be made.', 'perm');
		$dest = $e[0];
	}
	if (!is_dir($dest))
		fail('The place to unpack into is a file, not a folder.', 'notdir');
	$over = !empty($q['overwrite']);
	if ($kind === 'zip')
		$argv = array('/usr/bin/unzip', '-q', $over ? '-o' : '-n', $arc, '-d', $dest);
	else
		$argv = array_merge(array('/usr/bin/tar', '-x', '-f', $arc, '-C', $dest, '--no-same-owner', '--no-same-permissions'),
		                    $over ? array() : array('--skip-old-files'));
	list($rc, $o) = run($argv, $dest);
	/* unzip: 1 = warnings only (some files skipped) */
	if ($rc !== 0 && !($kind === 'zip' && $rc === 1))
		fail(strpos($o, 'quota') !== false || strpos($o, 'space') !== false
		     ? 'There is not enough disk space to unpack it (your package\'s limit, or the server is full).'
		     : 'The archive could not be unpacked: {reason}', 'archive', array('args' => array('reason' => substr($o, 0, 500))));
	out(array('ok' => true, 'dest' => rel_from_abs($dest)));
}

/* ---- disk usage -------------------------------------------------------------- */

const FM_MAX_USAGE = 300;               // biggest entries of a folder sent back

/* What takes up space in a folder: du's allocated sizes (bytes) of each entry,
   on this filesystem only (-x), links not followed. Folders it cannot read
   are counted in "unreadable" (their size is then too low). */
function v_usage(array $q) {
	$real = real_in($q['path'] ?? '');
	if ($real === false)
		fail('That folder is not in your home folder.', 'path');
	if (!is_dir($real))
		fail('That is a file, not a folder.', 'notdir');
	$err = tmpfile();       // a file, not a pipe: many "cannot read" lines must not block du
	$p = proc_open(array('/usr/bin/du', '-x', '-a', '-d', '1', '-B1', '-0', '--', $real),
	               array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => $err), $pipes, $real);
	if (!is_resource($p))
		fail('The sizes could not be measured.');
	$o = stream_get_contents($pipes[1]);
	fclose($pipes[1]);
	proc_close($p);
	rewind($err);
	$unreadable = 0;
	while (($l = fgets($err)) !== false)
		if (strpos($l, 'cannot') !== false || strpos($l, 'denied') !== false)
			$unreadable++;
	fclose($err);
	$total = 0;
	$rows = array();
	foreach (explode("\0", (string)$o) as $line) {
		$t = strpos($line, "\t");
		if ($t === false)
			continue;
		$size = (int)substr($line, 0, $t);
		$abs = substr($line, $t + 1);
		if ($abs === $real) {
			$total = $size;
			continue;
		}
		if (dirname($abs) !== $real)
			continue;
		$name = basename($abs);
		$type = is_link($abs) ? 'l' : (is_dir($abs) ? 'd' : 'f');
		$r = array('n' => $name, 't' => $type, 'size' => $size);
		if (!preg_match('//u', $name))
			$r['bad'] = true;
		$rows[] = $r;
	}
	usort($rows, function ($a, $b) { return $b['size'] <=> $a['size'] ?: strnatcasecmp($a['n'], $b['n']); });
	$rest = 0;
	$more = max(0, count($rows) - FM_MAX_USAGE);
	foreach (array_slice($rows, FM_MAX_USAGE) as $r)
		$rest += $r['size'];
	$s = segs($q['path'] ?? '');
	out(array('ok' => true, 'path' => rel_of($s), 'total' => $total, 'entries' => array_slice($rows, 0, FM_MAX_USAGE),
	          'more' => $more, 'rest' => $rest, 'unreadable' => $unreadable));
}

/* ---- logs ---------------------------------------------------------------------------- */

/* The end of a log in the home folder (app/functions/server.php:
   server_log_tail(), e.g. ~/logs/<domain>-error.log): "text" = the last
   lines (1-1000, oldest first); with grep, only the lines holding it (any
   case) among the last 20000. Read from the end, at most 16 MB back.
   "missing": the log is not there (yet). */
function v_tail(array $q) {
	$s = segs($q['path'] ?? '');
	if ($s === false || !$s)
		fail('That file is not in your home folder.', 'path');
	if (!exists_l(HOME.'/'.rel_of($s))) {
		out(array('ok' => true, 'missing' => true, 'text' => ''));
		return;
	}
	$real = real_in(rel_of($s));
	if ($real === false)
		fail('That file is not in your home folder.', 'path');
	if (!is_file($real))
		fail('That is not a file.', 'notfile');
	$lines = max(1, min(1000, (int)($q['lines'] ?? 100)));
	$grep = substr((string)($q['grep'] ?? ''), 0, 200);
	$want = $grep === '' ? $lines : 20000;
	$fh = @fopen($real, 'rb');
	if (!$fh)
		fail('This file cannot be read (no permission).', 'perm');
	$size = (int)filesize($real);
	$pos = $size;
	$buf = '';
	$nl = 0;
	while ($pos > 0 && $nl <= $want && $size - $pos < 16 * 1048576) {
		$n = min(65536, $pos);
		$pos -= $n;
		fseek($fh, $pos);
		$chunk = (string)fread($fh, $n);
		$nl += substr_count($chunk, "\n");
		$buf = $chunk.$buf;
	}
	fclose($fh);
	$rows = $buf === '' ? array() : explode("\n", rtrim($buf, "\n"));
	/* the first line read is cut when the file goes on before it */
	if ($pos > 0 && $rows)
		array_shift($rows);
	$rows = array_slice($rows, -$want);
	if ($grep !== '')
		$rows = array_slice(array_values(array_filter($rows, function ($r) use ($grep) { return stripos($r, $grep) !== false; })), -$lines);
	out(array('ok' => true, 'missing' => false, 'size' => $size, 'text' => implode("\n", $rows)));
}

/* ---- cron job runs ------------------------------------------------------------------ */

/* What /usr/local/bin/reqad-cron recorded (app/functions/cron.php): with no
   id, the runs of every job ("runs": [id, start ms, duration ms or null,
   exit code / "skip" / null], newest last, from the last 512 KB of
   ~/.reqad/cron/runs); with id and start, that run's output (first 64 KB). */
function v_cronruns(array $q) {
	$id = (string)($q['id'] ?? '');
	if ($id !== '') {
		if (!preg_match('/^[a-z0-9]{8}$/', $id) || !preg_match('/^[0-9]{10,16}$/', (string)($q['start'] ?? '')))
			fail('Malformed request.');
		$real = real_in('.reqad/cron/'.$id.'/'.$q['start'].'.out');
		if ($real === false || !is_file($real))
			out(array('ok' => true, 'output' => null));
		else
			out(array('ok' => true, 'output' => (string)file_get_contents($real, false, null, 0, 65536)));
		return;
	}
	$real = real_in('.reqad/cron/runs');
	$runs = array();
	if ($real !== false && is_file($real)) {
		$size = filesize($real);
		$c = (string)file_get_contents($real, false, null, max(0, $size - 524288));
		if ($size > 524288)
			$c = substr($c, strpos($c, "\n") + 1);
		foreach (explode("\n", $c) as $l)
			if (preg_match('/^([a-z0-9]{8}) ([0-9]{10,16}) (-|[0-9]+) (-|skip|[0-9]{1,3})$/', $l, $m))
				$runs[] = array($m[1], (int)$m[2], $m[3] === '-' ? null : (int)$m[3], $m[4] === '-' ? null : ($m[4] === 'skip' ? 'skip' : (int)$m[4]));
	}
	out(array('ok' => true, 'runs' => $runs));
}

/* ---- WordPress (app/functions/wordpress.php) ------------------------------------------ */

/* The WordPress installs in some site folders ("roots": paths as above), up
   to 3 folders down; symlinked folders below a root are not followed, nor
   WordPress's own and other folders that never hold a site. "found": root,
   real (the install's real path), path (below the root, '' = the root
   itself), version (read from wp-includes/version.php, never run). */
function v_wpfind(array $q) {
	$skip = array('wp-admin', 'wp-includes', 'wp-content', 'node_modules', 'vendor', 'cgi-bin', 'logs', 'tmp', 'cache');
	$found = array();
	$seen = 0;
	foreach (array_slice((array)($q['roots'] ?? array()), 0, 200) as $root) {
		if (!is_string($root) || ($real = real_in($root)) === false || !is_dir($real))
			continue;
		$queue = array(array($real, '', 0));
		while ($queue && $seen < 5000 && count($found) < 200) {
			list($dir, $sub, $depth) = array_shift($queue);
			$seen++;
			if (is_file($dir.'/wp-load.php') && is_file($dir.'/wp-includes/version.php')) {
				$head = (string)@file_get_contents($dir.'/wp-includes/version.php', false, null, 0, 8192);
				$found[] = array('root' => rel_of(segs($root)), 'real' => $dir, 'path' => $sub,
				                 'version' => preg_match('/\$wp_version\s*=\s*[\'"]([0-9][0-9A-Za-z.+-]{0,30})[\'"]/', $head, $m) ? $m[1] : '');
			}
			if ($depth >= 3)
				continue;
			$names = @scandir($dir);
			foreach ($names ?: array() as $n) {
				if ($n[0] === '.' || in_array($n, $skip, true) || !preg_match('//u', $n))
					continue;
				$abs = $dir.'/'.$n;
				if (!is_link($abs) && is_dir($abs))
					$queue[] = array($abs, ltrim($sub.'/'.$n, '/'), $depth + 1);
			}
		}
	}
	out(array('ok' => true, 'found' => $found, 'complete' => $seen < 5000));
}

/* The database each install in "paths" (its folders) names in wp-config.php
   (in the folder, or the one above as WordPress itself looks): "dbs" =
   path => DB_NAME, or null when there is no config or it does not name one
   plainly (getenv(), a constant from elsewhere). The file is read, never run:
   the first define('DB_NAME', '...') at the start of a line outside a
   comment counts, as in PHP the first define wins. */
function v_wpdbname(array $q) {
	$dbs = array();
	foreach (array_slice((array)($q['paths'] ?? array()), 0, 200) as $p) {
		if (!is_string($p))
			continue;
		$dbs[$p] = null;
		$dir = real_in($p);
		if ($dir === false || !is_dir($dir) || !is_file($dir.'/wp-load.php'))
			continue;
		$cfg = $dir.'/wp-config.php';
		if (!is_file($cfg) && $dir !== HOME && inside(dirname($dir)) && is_file(dirname($dir).'/wp-config.php') && !is_file(dirname($dir).'/wp-settings.php'))
			$cfg = dirname($dir).'/wp-config.php';
		$real = is_file($cfg) ? real_in(rel_from_abs($cfg)) : false;
		if ($real === false || !is_file($real))
			continue;
		$c = preg_replace('#/\*.*?\*/#s', '', (string)@file_get_contents($real, false, null, 0, 262144));
		if (preg_match('/^[ \t]*define\s*\(\s*([\'"])DB_NAME\1\s*,\s*([\'"])([A-Za-z0-9_$-]{1,64})\2\s*\)/m', (string)$c, $m))
			$dbs[$p] = $m[3];
	}
	out(array('ok' => true, 'dbs' => $dbs));
}

/* A one-time sign-in file for the install at "path" (its folder): bytes on
   stdin ("size"), written as wp-content/mu-plugins/<name> (name
   reqad-login-<hex>.php). Earlier ones older than 10 minutes go first (each
   also removes itself once used or expired). Answer: file (its real path). */
function v_wplogin(array $q) {
	$wp = real_in($q['path'] ?? '');
	$name = (string)($q['name'] ?? '');
	if ($wp === false || !is_dir($wp) || !is_file($wp.'/wp-load.php'))
		fail('There is no WordPress site in that folder.', 'path');
	if (!preg_match('/^reqad-login-[a-f0-9]{12}\.php$/', $name))
		fail('Malformed request.');
	$wc = real_in(rel_from_abs($wp).'/wp-content');
	if ($wc === false || !is_dir($wc))
		fail('This WordPress site has no wp-content folder.', 'path');
	$mu = $wc.'/mu-plugins';
	if (is_link($mu)) {
		$r = realpath($mu);
		if ($r === false || !inside($r) || !is_dir($r))
			fail('wp-content/mu-plugins leads out of your home folder.', 'path');
		$mu = $r;
	} elseif (!is_dir($mu) && !@mkdir($mu, 0755))
		fail('You cannot write in this site\'s wp-content folder (no permission).', 'perm');
	foreach (glob($mu.'/reqad-login-*.php') ?: array() as $old)
		if (!is_link($old) && @filemtime($old) < time() - 600)
			@unlink($old);
	$err = write_into($mu.'/'.$name, STDIN, isset($q['size']) ? (int)$q['size'] : null);
	if ($err !== '')
		fail($err, 'write');
	out(array('ok' => true, 'file' => $mu.'/'.$name));
}

/* ---- a new WordPress (core's wordpress_install()) ------------------------------------ */

/* the page a new site starts with (scripts/templates/default-template.html) */
function is_placeholder($abs) {
	return is_file($abs) && !is_link($abs) && filesize($abs) < 65536
	    && strpos((string)file_get_contents($abs), 'placeholder - powered by Reqad') !== false;
}

/*
 * Get a folder ready for a new WordPress: made when missing (each part as
 * this account), and refused when it holds anything but the placeholder page,
 * .well-known, an empty cgi-bin and the folders in "keep" (other sites'
 * folders inside it). Then an empty folder "stage" (.reqad-wp-<hex>) is made
 * in it, where WordPress is put together before it is moved in place.
 */
function v_wpprep(array $q) {
	$s = segs($q['path'] ?? '');
	$stage = (string)($q['stage'] ?? '');
	if ($s === false || !$s || !preg_match('/^\.reqad-wp-[a-f0-9]{12}$/', $stage))
		fail('Malformed request.');
	$keep = array_flip(array_filter((array)($q['keep'] ?? array()), 'is_string'));
	$dir = HOME;
	$made = '';                              // the first folder made here (taken back when the install fails)
	foreach ($s as $part) {
		$next = $dir.'/'.$part;
		if (is_link($next)) {
			$r = realpath($next);
			if ($r === false || !inside($r) || !is_dir($r))
				fail('{name} leads out of your home folder.', 'path', array('args' => array('name' => $part)));
			$next = $r;
		} elseif (!file_exists($next)) {
			if (!good_name($part) || !@mkdir($next, 0755))
				fail('The folder {name} could not be made (no permission).', 'perm', array('args' => array('name' => $part)));
			if ($made === '')
				$made = rel_from_abs($next);
		} elseif (!is_dir($next))
			fail('{name} is a file, not a folder.', 'path', array('args' => array('name' => $part)));
		$dir = $next;
	}
	$busy = $placeholders = array();
	foreach (scandir($dir) ?: array() as $n) {
		if ($n === '.' || $n === '..' || isset($keep[$n]) || $n === '.well-known' || preg_match('/^\.reqad-wp-[a-f0-9]{12}$/', $n))
			continue;
		if ($n === 'cgi-bin' && is_dir($dir.'/'.$n) && count(scandir($dir.'/'.$n) ?: array()) <= 2)
			continue;
		if (in_array($n, array('index.php', 'index.html', 'index.htm'), true) && is_placeholder($dir.'/'.$n)) {
			$placeholders[] = $n;
			continue;
		}
		$busy[] = $n;
	}
	if ($busy) {
		sort($busy);
		fail('The folder is not empty: {names}.', 'notempty', array('args' => array('names' => implode(', ', array_slice($busy, 0, 8)).(count($busy) > 8 ? ', …' : '')), 'names' => array_slice($busy, 0, 50)));
	}
	/* a stage left by an install that was cut off */
	foreach (glob($dir.'/.reqad-wp-*') ?: array() as $old)
		if (is_dir($old) && !is_link($old) && filemtime($old) < time() - 3600)
			run(array('/usr/bin/rm', '-rf', '--one-file-system', '--', $old));
	if (!@mkdir($dir.'/'.$stage, 0755))
		fail('You cannot write in this folder (no permission).', 'perm');
	out(array('ok' => true, 'dir' => rel_from_abs($dir), 'stage' => rel_from_abs($dir).'/'.$stage, 'made' => $made, 'placeholders' => $placeholders));
}

/* Move what is in "stage" up into its folder (the placeholder page goes;
   nothing else is overwritten), then remove the empty stage. */
function v_wpplace(array $q) {
	$st = real_in($q['stage'] ?? '');
	if ($st === false || !is_dir($st) || is_link($st) || !preg_match('/^\.reqad-wp-[a-f0-9]{12}$/', basename($st)))
		fail('Malformed request.');
	$dir = dirname($st);
	$names = array_values(array_diff(scandir($st) ?: array(), array('.', '..')));
	foreach ($names as $n)
		if (exists_l($dir.'/'.$n) && !(in_array($n, array('index.php', 'index.html', 'index.htm'), true) && is_placeholder($dir.'/'.$n)))
			fail('{name} is already in the folder.', 'exists', array('args' => array('name' => $n)));
	foreach (array('index.php', 'index.html', 'index.htm') as $n)
		if (is_placeholder($dir.'/'.$n))
			@unlink($dir.'/'.$n);
	foreach ($names as $n)
		if (!@rename($st.'/'.$n, $dir.'/'.$n))
			fail('{name} could not be moved in place.', 'perm', array('args' => array('name' => $n)));
	@rmdir($st);
	out(array('ok' => true, 'moved' => count($names)));
}

/*
 * Copy a WordPress site (core's wordpress_copy_run(): Clone or staging). The
 * folder "to" (a new site's) may hold only what a new site starts with (see
 * wpprep); the copy goes into a hidden stage there (.reqad-wp-<hex>, which
 * nginx does not serve), so nothing of it runs before its wp-config.php
 * points at its own database. Then wpplace moves it in place.
 *   from    the site's folder; to: the new site's folder; stage: the stage's name
 *   skip    paths in "from" left out (other sites' folders and installs in it,
 *           caches, backups, the object cache drop-in)
 *   paths   old => new absolute folder: changed in the config files at the
 *           top of the copy and of its wp-content (wp-config.php, .user.ini,
 *           wordfence-waf.php, advanced-cache.php ...), which hold it
 *   db      DB_NAME, DB_USER, DB_PASSWORD for the copy's wp-config.php
 * rsync -a as this account: links stay links. A wp-config.php kept one folder
 * up (as WordPress allows) is copied into the copy. Answer: stage, warning
 * (some files could not be read: their owner is another user).
 */
function v_wpclone(array $q) {
	$from = real_in($q['from'] ?? '');
	$to = real_in($q['to'] ?? '');
	$stage = (string)($q['stage'] ?? '');
	if ($from === false || !is_dir($from) || !is_file($from.'/wp-load.php'))
		fail('There is no WordPress site in that folder.', 'path');
	if ($to === false || !is_dir($to) || !preg_match('/^\.reqad-wp-[a-f0-9]{12}$/', $stage))
		fail('Malformed request.');
	if (strpos($to.'/', $from.'/') === 0 || strpos($from.'/', $to.'/') === 0)
		fail('A site cannot be copied into its own folder.', 'loop');
	$db = isset($q['db']) && is_array($q['db']) ? $q['db'] : array();
	foreach (array('DB_NAME', 'DB_USER', 'DB_PASSWORD') as $k)
		if (!isset($db[$k]) || !is_string($db[$k]) || $db[$k] === '' || preg_match('/[\x00-\x1f]/', $db[$k]))
			fail('Malformed request.');
	$busy = array();
	foreach (scandir($to) ?: array() as $n) {
		if ($n === '.' || $n === '..' || $n === '.well-known' || preg_match('/^\.reqad-wp-[a-f0-9]{12}$/', $n))
			continue;
		if ($n === 'cgi-bin' && is_dir($to.'/'.$n) && count(scandir($to.'/'.$n) ?: array()) <= 2)
			continue;
		if (in_array($n, array('index.php', 'index.html', 'index.htm'), true) && is_placeholder($to.'/'.$n))
			continue;
		$busy[] = $n;
	}
	if ($busy)
		fail('The folder is not empty: {names}.', 'notempty', array('args' => array('names' => implode(', ', array_slice($busy, 0, 8)))));
	$st = $to.'/'.$stage;
	if (!@mkdir($st, 0755))
		fail('You cannot write in this folder (no permission).', 'perm');

	$argv = array('/usr/bin/rsync', '-a', '--no-specials', '--no-devices', '--exclude=/.reqad-wp-*', '--exclude=/wp-content/mu-plugins/reqad-login-*.php');
	foreach (array_slice((array)($q['skip'] ?? array()), 0, 500) as $s) {
		$sg = is_string($s) ? segs($s) : false;
		if ($sg)
			$argv[] = '--exclude=/'.addcslashes(rel_of($sg), '*?[\\');
	}
	$argv[] = $from.'/';
	$argv[] = $st.'/';
	list($rc, $o) = run($argv);
	$warn = '';
	if ($rc !== 0) {
		$space = strpos($o, 'Disk quota') !== false || strpos($o, 'No space') !== false;
		/* 23/24: some files could not be read (another user's) or vanished meanwhile */
		if ($space || !in_array($rc, array(23, 24), true)) {
			run(array('/usr/bin/rm', '-rf', '--one-file-system', '--', $st));
			fail($space ? 'There is not enough disk space for the copy (your package\'s limit, or the server is full).'
			            : 'The files could not be copied: {reason}', 'copy', array('args' => array('reason' => substr($o, -400))));
		}
		$warn = substr(implode(' ', array_slice(preg_split('/\n/', $o), 0, 3)), 0, 400);
	}
	if (!is_file($st.'/wp-config.php') && is_file(dirname($from).'/wp-config.php') && !is_file(dirname($from).'/wp-settings.php') && inside(dirname($from)))
		@copy(dirname($from).'/wp-config.php', $st.'/wp-config.php');
	$cfg = $st.'/wp-config.php';
	if (!is_file($cfg) || is_link($cfg)) {
		run(array('/usr/bin/rm', '-rf', '--one-file-system', '--', $st));
		fail('The site has no wp-config.php.', 'config');
	}
	/* the copy's own database, before anything can run it */
	$text = (string)file_get_contents($cfg);
	foreach ($db as $k => $v) {
		$re = '/(define\s*\(\s*([\'"])'.$k.'\2\s*,\s*)([\'"])((?:\\\\.|(?!\3).)*)\3/s';
		if (preg_match_all($re, $text) !== 1) {
			run(array('/usr/bin/rm', '-rf', '--one-file-system', '--', $st));
			fail('wp-config.php does not set {name} in the usual way (define(\'{name}\', \'...\')), so the copy cannot get its own database.', 'config', array('args' => array('name' => $k)));
		}
		$text = preg_replace_callback($re, function ($m) use ($v) { return $m[1]."'".addcslashes($v, "\\'")."'"; }, $text);
	}
	if (file_put_contents($cfg, $text) === false) {
		run(array('/usr/bin/rm', '-rf', '--one-file-system', '--', $st));
		fail('wp-config.php of the copy could not be written.', 'config');
	}
	/* absolute paths in the config files (a firewall plugin's .user.ini, a page cache's advanced-cache.php) */
	$paths = array();
	foreach ((array)($q['paths'] ?? array()) as $old => $new)
		if (is_string($old) && is_string($new) && strpos($old, HOME.'/') === 0 && strpos($new, HOME.'/') === 0)
			$paths[$old] = $new;
	foreach (array($st, $st.'/wp-content') as $dir)
		foreach (scandir($dir) ?: array() as $n) {
			$f = $dir.'/'.$n;
			if (!preg_match('/(\.php|\.ini|^\.htaccess|^\.user\.ini)$/', $n) || is_link($f) || !is_file($f) || filesize($f) > 1048576 || fileowner($f) !== MY_UID)
				continue;
			$c = (string)file_get_contents($f);
			$d = strtr($c, $paths);
			if ($d !== $c)
				file_put_contents($f, $d);
		}
	out(array('ok' => true, 'stage' => rel_from_abs($st), 'warning' => $warn));
}

/*
 * Push to live (core's wordpress_push_*(): Clone or staging). The code of a
 * staging copy ("from") goes back to its live site ("to"), never its
 * database, its wp-config.php, .htaccess, robots.txt, the uploads (unless
 * asked: then only files the live site does not have) or the panel's own
 * must-use plugins (reqad-*). What can go, by item:
 *   core          wp-admin/, wp-includes/ and WordPress's files at the top
 *   plugin:<name> a folder or file in wp-content/plugins/
 *   theme:<name>  a folder in wp-content/themes/
 *   mu:<name>     a must-use plugin (wp-content/mu-plugins/)
 *   languages     wp-content/languages/ (added and changed only: the live
 *                 site's translations of its own plugins stay)
 * Files are compared by their content (rsync -c), so the same version
 * unpacked at another time is no change.
 */
function push_dirs(array $q) {
	$from = real_in($q['from'] ?? '');
	$to = real_in($q['to'] ?? '');
	foreach (array($from, $to) as $d)
		if ($d === false || !is_dir($d) || !is_file($d.'/wp-load.php') || !is_dir($d.'/wp-content'))
			fail('There is no WordPress site in that folder.', 'path');
	if ($from === $to || strpos($to.'/', $from.'/') === 0 || strpos($from.'/', $to.'/') === 0)
		fail('A site cannot be pushed into its own folder.', 'loop');
	return array($from, $to);
}

/* rsync's filter rules for the items ($keys; null = every item, to compare) */
function push_filters($keys) {
	$f = array('--exclude=/wp-config.php', '--exclude=/.reqad-wp-*', '--exclude=/.maintenance', '--exclude=/wp-content/mu-plugins/reqad-*',
	           '--filter=P /wp-content/languages/**');
	$esc = function ($n) { return addcslashes($n, '*?[\\'); };
	$core = array('--include=/wp-admin/***', '--include=/wp-includes/***', '--include=/index.php', '--include=/xmlrpc.php',
	              '--include=/wp-*.php', '--include=/license.txt', '--include=/readme.html');
	$dirs = array('plugin' => 'plugins', 'theme' => 'themes', 'mu' => 'mu-plugins');
	if ($keys === null) {
		$f = array_merge($f, $core, array('--include=/wp-content/'));
		foreach (array('plugins', 'themes', 'mu-plugins', 'languages') as $d)
			$f[] = '--include=/wp-content/'.$d.'/***';
	} else
		foreach ($keys as $k) {
			if ($k === 'core')
				$f = array_merge($f, $core);
			elseif ($k === 'languages')
				array_push($f, '--include=/wp-content/', '--include=/wp-content/languages/***');
			elseif (preg_match('/^(plugin|theme|mu):(.+)$/', (string)$k, $m) && good_name($m[2]) && $m[2][0] !== '.'
			        && !($m[1] === 'mu' && strpos($m[2], 'reqad-') === 0)) {
				$d = '/wp-content/'.$dirs[$m[1]].'/';
				array_push($f, '--include=/wp-content/', '--include='.$d, '--include='.$d.$esc($m[2]), '--include='.$d.$esc($m[2]).'/***');
			} else
				fail('Malformed request.');
		}
	$f[] = '--exclude=*';
	return $f;
}

/* rsync's options for a push: files, links and times, but no owner, group or
   permissions - new files get the copy's permissions (less the umask) and
   existing ones keep theirs, so the live site's folder keeps its group (the
   web server's) and mode; -a would give it the source folder's */
function push_rsync() {
	return array('-rlt', '-O', '--out-format=%i %l %n');
}

/* the item a path of the tree belongs to ('' = none: the folders above them) */
function push_item($p) {
	if (preg_match('#^wp-content/(plugins|themes|mu-plugins)/([^/]+)#', $p, $m))
		return array('plugins' => 'plugin', 'themes' => 'theme', 'mu-plugins' => 'mu')[$m[1]].':'.$m[2];
	if (strpos($p, 'wp-content/languages/') === 0)
		return 'languages';
	if (strpos($p, 'wp-content') === 0)
		return '';
	return 'core';
}

/* rsync's "%i %l %n" lines: array(change, size, path) each (names as rsync prints them: \#ooo for odd bytes) */
function push_lines($out) {
	$rows = array();
	foreach (explode("\n", $out) as $l)
		if (preg_match('/^(\S[^ ]{0,10}) +(\d+) (.+)$/', $l, $m))
			$rows[] = array($m[1], (int)$m[2], preg_replace_callback('/\\\\#([0-7]{3})/', function ($x) { return chr(octdec($x[1])); }, $m[3]));
	return $rows;
}

/* The uploads a push may add: files the live site does not have, without caches and backups. */
function push_upload_args() {
	$a = array('--ignore-existing');
	foreach (array('cache', 'wc-logs', 'elementor/css', 'et-cache', 'wpo-cache', 'wpo', 'ai1wm-backups', 'updraft', 'wpvividbackups',
	               'backwpup-*', 'backup*', 'wp-staging*', 'sucuri', 'wflogs', 'jetpack-waf', 'wp-migrate-db') as $d)
		$a[] = '--exclude=/wp-content/uploads/'.$d;
	array_push($a, '--include=/wp-content/', '--include=/wp-content/uploads/***', '--exclude=*');
	return $a;
}

/*
 * What a push would change: items {key: {added, changed, deleted (files),
 * state (added: only on the copy | removed: only on the live site |
 * changed), sample (a few paths)}}, uploads {files, bytes} (new ones).
 */
function v_wppushdiff(array $q) {
	list($from, $to) = push_dirs($q);
	$base = array_merge(array('/usr/bin/rsync', '-n'), push_rsync());
	list($rc, $o) = run(array_merge($base, array('-c', '--delete'), push_filters(null), array($from.'/', $to.'/')));
	if ($rc !== 0 && $rc !== 24)
		fail('The sites could not be compared: {reason}', 'copy', array('args' => array('reason' => substr($o, -400))));
	$items = array();
	foreach (push_lines($o) as $r) {
		list($i, , $p) = $r;
		$key = push_item($p);
		if ($key === '' || $i[0] === '.')
			continue;
		if (!isset($items[$key]))
			$items[$key] = array('added' => 0, 'changed' => 0, 'deleted' => 0, 'state' => 'changed', 'sample' => array());
		$x = &$items[$key];
		$top = $key !== 'core' && $key !== 'languages' && preg_match('#^wp-content/[^/]+/[^/]+/?$#', $p);
		if (strpos($i, '*deleting') === 0) {
			if ($top)
				$x['state'] = 'removed';
			if (substr($p, -1) !== '/')
				$x['deleted']++;
		} elseif (substr($i, 2, 9) === '+++++++++') {
			if ($top)
				$x['state'] = 'added';
			if ($i[1] !== 'd')
				$x['added']++;
		} elseif ($i[1] !== 'd')
			$x['changed']++;
		else
			continue;
		if (count($x['sample']) < 8 && substr($p, -1) !== '/')
			$x['sample'][] = $p;
		unset($x);
	}
	$up = array('files' => 0, 'bytes' => 0);
	if (!empty($q['uploads']) && is_dir($from.'/wp-content/uploads')) {
		list($rc, $o) = run(array_merge($base, push_upload_args(), array($from.'/', $to.'/')));
		foreach ($rc === 0 || $rc === 24 ? push_lines($o) : array() as $r)
			if ($r[0][1] === 'f' && substr($r[0], 2, 9) === '+++++++++') {
				$up['files']++;
				$up['bytes'] += $r[1];
			}
	}
	ksort($items);
	out(array('ok' => true, 'items' => (object)$items, 'uploads' => $up));
}

/* the folder kept of a push: wp-backups/<site>-push-<date> */
function push_backup($rel, $make) {
	$s = segs((string)$rel);
	if ($s === false || count($s) !== 2 || $s[0] !== 'wp-backups' || !preg_match('/^[a-z0-9.-]{1,200}-push-\d{8}-\d{6}$/', $s[1]))
		fail('Malformed request.');
	$dir = HOME.'/wp-backups';
	if ($make && !is_dir($dir) && !is_link($dir))
		@mkdir($dir, 0700);
	if (is_link($dir) || !is_dir($dir))
		fail('The folder wp-backups could not be made.', 'perm');
	$bk = $dir.'/'.$s[1];
	if ($make) {
		if (exists_l($bk) || !@mkdir($bk, 0700) || !@mkdir($bk.'/files', 0700))
			fail('The folder for the replaced files could not be made.', 'perm');
	} elseif (is_link($bk) || !is_dir($bk) || !is_file($bk.'/push.json'))
		fail('The files of that push are no longer kept.', 'missing');
	return $bk;
}

/* WordPress's "Briefly unavailable for scheduled maintenance" while files change: true when it was turned on here */
function push_maintenance($to, $on) {
	$f = $to.'/.maintenance';
	if ($on)
		return !exists_l($f) && @file_put_contents($f, '<?php $upgrading = '.time().'; ?>') !== false;
	if (is_file($f) && !is_link($f))
		@unlink($f);
	return false;
}

/* Take a push back: what it made goes, the files it replaced or deleted come back. array(ok, why not) */
function push_restore($to, $bk) {
	$j = json_decode((string)@file_get_contents($bk.'/push.json'), true);
	if (!is_array($j))
		return array(false, 'its list of new files is missing');
	$made = array_values(array_filter((array)($j['created'] ?? array()), 'is_string'));
	usort($made, function ($a, $b) { return strlen($b) - strlen($a); });
	foreach ($made as $p) {
		$s = segs($p);
		if (!$s)
			continue;
		$abs = $to.'/'.rel_of($s);
		if (is_dir($abs) && !is_link($abs))
			run(array('/usr/bin/rm', '-rf', '--one-file-system', '--', $abs));
		elseif (exists_l($abs))
			@unlink($abs);
	}
	list($rc, $o) = run(array_merge(array('/usr/bin/rsync'), push_rsync(), array($bk.'/files/', $to.'/')));
	return $rc === 0 ? array(true, '') : array(false, substr($o, -300));
}

/*
 * Push the items ("items": keys as above; "uploads": add the live site's
 * missing uploads too). Each file it replaces or deletes is moved into
 * backup/files (rsync --backup-dir), and what it made is listed in
 * backup/push.json, so wppushundo can take it all back. Answer: created,
 * replaced, deleted (counts of files and folders), uploads (files added).
 */
function v_wppush(array $q) {
	list($from, $to) = push_dirs($q);
	$keys = array_values(array_unique(array_filter((array)($q['items'] ?? array()), 'is_string')));
	if (!$keys && empty($q['uploads']))
		fail('Nothing to push.', 'empty');
	if (count($keys) > 500)
		fail('Malformed request.');
	$filters = $keys ? push_filters($keys) : array();
	$bk = push_backup($q['backup'] ?? '', true);
	$maint = push_maintenance($to, true);
	$made = array();
	$replaced = $added = $deleted = 0;
	$base = array_merge(array('/usr/bin/rsync'), push_rsync());
	$runs = array();
	if ($keys)
		$runs[] = array_merge($base, array('-c', '--delete', '--backup', '--backup-dir='.$bk.'/files'), $filters);
	if (!empty($q['uploads']) && is_dir($from.'/wp-content/uploads'))
		$runs[] = array_merge($base, push_upload_args());
	$err = '';
	foreach ($runs as $n => $argv) {
		list($rc, $o) = run(array_merge($argv, array($from.'/', $to.'/')));
		foreach (push_lines($o) as $r)
			if (substr($r[0], 2, 9) === '+++++++++') {
				$made[] = rtrim($r[2], '/');
				if ($n > 0 || !$keys)
					$added++;
			} elseif (strpos($r[0], '*deleting') === 0) {
				if (substr($r[2], -1) !== '/')
					$deleted++;
			} elseif ($r[0][0] !== '.' && $r[0][1] !== 'd')
				$replaced++;
		if ($rc !== 0 && $rc !== 24) {
			$err = strpos($o, 'Disk quota') !== false || strpos($o, 'No space') !== false
			     ? 'There is not enough disk space (your package\'s limit, or the server is full).' : substr($o, -400);
			break;
		}
	}
	file_put_contents($bk.'/push.json', json_encode(array('time' => time(), 'from' => rel_from_abs($from), 'to' => rel_from_abs($to),
		'items' => $keys, 'created' => $made), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
	if ($err !== '') {
		list($ok, $why) = push_restore($to, $bk);
		if ($maint)
			push_maintenance($to, false);
		fail($ok ? 'The files could not be copied, so the live site\'s files were put back as they were: {reason}'
		         : 'The files could not be copied, and putting the old ones back failed too ({why}). They are in {folder}: {reason}', 'copy',
		     array('args' => array('reason' => $err, 'why' => $why, 'folder' => rel_from_abs($bk))));
	}
	if ($maint)
		push_maintenance($to, false);
	out(array('ok' => true, 'created' => count($made) - $added, 'replaced' => $replaced, 'deleted' => $deleted, 'uploads' => $added));
}

/* Take back a push made by wppush ("to": the live site's folder, "backup": the folder it kept). */
function v_wppushundo(array $q) {
	$to = real_in($q['to'] ?? '');
	if ($to === false || !is_dir($to) || !is_file($to.'/wp-load.php'))
		fail('There is no WordPress site in that folder.', 'path');
	$bk = push_backup($q['backup'] ?? '', false);
	$maint = push_maintenance($to, true);
	list($ok, $why) = push_restore($to, $bk);
	if ($maint)
		push_maintenance($to, false);
	if (!$ok)
		fail('The old files could not all be put back ({why}). They are in {folder}.', 'copy', array('args' => array('why' => $why, 'folder' => rel_from_abs($bk))));
	out(array('ok' => true));
}

/*
 * A WordPress site's file permissions (WP Toolkit > Security): wp-config.php's
 * mode (in the site's folder, or the one above it as WordPress allows) and
 * whether anything under the folder is writable by every user. fix: true
 * makes wp-config.php 0600 and takes o+w off everything (links left alone).
 */
function v_wpperms(array $q) {
	$dir = real_in($q['path'] ?? '');
	if ($dir === false || !is_dir($dir) || !is_file($dir.'/wp-settings.php'))
		fail('There is no WordPress site in that folder.', 'path');
	$cfg = $dir.'/wp-config.php';
	if (!is_file($cfg) && is_file(dirname($dir).'/wp-config.php') && !is_file(dirname($dir).'/wp-settings.php') && inside(dirname($dir)))
		$cfg = dirname($dir).'/wp-config.php';
	if (is_link($cfg) || !is_file($cfg))
		$cfg = '';
	$fixed = 0;
	if (!empty($q['fix'])) {
		if ($cfg !== '' && fileowner($cfg) === MY_UID && @chmod($cfg, 0600))
			$fixed++;
		list($rc, $o) = run(array('/usr/bin/find', $dir, '-xdev', '-perm', '-0002', '!', '-type', 'l', '-exec', '/usr/bin/chmod', 'o-w', '{}', '+'));
	}
	clearstatcache();
	list($rc, $first) = run(array('/usr/bin/find', $dir, '-xdev', '-perm', '-0002', '!', '-type', 'l', '-print', '-quit'));
	out(array('ok' => true, 'config' => $cfg !== '' ? rel_from_abs($cfg) : '', 'config_mode' => $cfg !== '' ? mode_str(fileperms($cfg)) : '',
	          'world_writable' => $first !== '' ? rel_from_abs($first) : ''));
}

/* ---- restoring from a backup ---------------------------------------------------------
 *
 * bkrestore: put files or one mailbox back from a backup archive. stdin, after
 * the request line: the archive itself (gzip'd tar, as backup.sh and
 * backup_remote.sh make it), which root reads and streams in - this process
 * unpacks only what was asked for, as the account, into a hidden stage folder
 * in the home (~/.reqad/restore-<job>), then copies it in place:
 *
 *   q.root    the archive's top folder: backup-<account>
 *   q.kind    files | mail
 *   q.path    files: what to restore, relative to the home ('' = all of it,
 *             without mail/); mail: mail/<domain>/<mailbox>
 *   q.mode    files: missing (only what is not there now) | overwrite (the
 *             backup's version wins, others stay) | replace (exactly as in
 *             the backup: what was added since is deleted; not for the whole
 *             home folder); mail: missing (messages that are not there now) |
 *             replace
 *   q.keep    where replaced and deleted files go, relative to the home
 *             (overwrite / replace), e.g. backup-restores/files-20261018-1200
 *   q.job     the restore's number (names the stage folder)
 *
 * Kept safe in replace mode: filesystems mounted below the place, folders
 * holding .nobackup or CACHEDIR.TAG and the backup's own exclusion rules
 * (excludes.txt in the archive): they were never in the backup, so they are
 * not "added since".
 */
function bk_stage($job) {
	if (!is_int($job) && !ctype_digit((string)$job))
		fail('Malformed request.');
	foreach (array(HOME.'/.reqad', HOME.'/.reqad/restore-'.(int)$job) as $d) {
		if (is_link($d))
			fail('{name} is a link: it is not followed.', 'path', array('args' => array('name' => rel_from_abs($d))));
		if (!is_dir($d) && !mkdir($d, 0700))
			fail('A folder for the restore could not be made in your home folder (no permission, or the disk is full).', 'perm');
	}
	return HOME.'/.reqad/restore-'.(int)$job;
}

function bk_rmtree($dir) {
	if ($dir !== '' && strpos($dir, HOME.'/.reqad/restore-') === 0 && is_dir($dir) && !is_link($dir))
		run(array('/usr/bin/rm', '-rf', '--one-file-system', '--', $dir));
}

/* make the folders of $rel (relative to the home) that are missing, never through a link */
function bk_mkdirs(array $segs) {
	$cur = HOME;
	foreach ($segs as $s) {
		$cur .= '/'.$s;
		if (is_link($cur))
			fail('{name} is a link: nothing is restored through it.', 'path', array('args' => array('name' => rel_from_abs($cur))));
		if (!is_dir($cur)) {
			if (file_exists($cur))
				fail('{name} is a file now, not a folder.', 'path', array('args' => array('name' => rel_from_abs($cur))));
			if (!mkdir($cur, 0755))
				fail('The folder {name} could not be made (no permission).', 'perm', array('args' => array('name' => rel_from_abs($cur))));
		}
	}
	return $cur;
}

/* rsync protect rules for a replace into $dst (absolute): what the backup never had */
function bk_protect($dst, $stage, $root, array $segs) {
	$rules = array();
	$rel_to = function ($rel) use ($segs) {      // a path relative to the home -> relative to $dst, or null
		$p = implode('/', $segs);
		if ($p === '')
			return $rel;
		return strpos($rel.'/', $p.'/') === 0 ? substr($rel, strlen($p) + 1) : null;
	};
	/* filesystems mounted below it (findmnt is not needed: the kernel's list) */
	foreach (@file('/proc/self/mountinfo') ?: array() as $l) {
		$f = explode(' ', $l);
		$mp = isset($f[4]) ? preg_replace_callback('/\\\\([0-7]{3})/', function ($m) { return chr(octdec($m[1])); }, $f[4]) : '';
		if ($mp !== '' && strpos($mp, $dst.'/') === 0)
			$rules[] = '/'.substr($mp, strlen($dst) + 1).'/***';
	}
	/* folders the markers kept out */
	list($rc, $o) = run(array('/usr/bin/find', $dst, '-xdev', '(', '-name', '.nobackup', '-o', '-name', 'CACHEDIR.TAG', ')', '-type', 'f', '-printf', '%h\n'));
	foreach (explode("\n", $o) as $d)
		if ($d === $dst)
			$rules[] = '/***';
		elseif ($d !== '' && strpos($d, $dst.'/') === 0)
			$rules[] = '/'.substr($d, strlen($dst) + 1).'/***';
	/* the backup's own record of what it left out */
	foreach (@file($stage.'/'.$root.'/excludes.txt') ?: array() as $l) {
		if ($l === '' || $l[0] === '#')
			continue;
		$f = explode("\t", rtrim($l, "\n"));
		if (count($f) < 2 || $f[1] === '' || strpos($f[1], "\0") !== false)
			continue;
		$w = $f[1];
		if ($f[0] === 'rule') {
			/* as backup_excludes.sh stores them: "/x" and "a/b" from the home, "name" and "** /a/b" anywhere */
			$w = str_replace('**/', '', $w);
			$anch = $f[1][0] === '/' || (strpos($f[1], '/') !== false && strpos($f[1], '**/') !== 0);
			$w = ltrim($w, '/');
			if ($anch) {
				$r = $rel_to($w);
				if ($r !== null && $r !== '')
					$rules[] = '/'.str_replace('*', '**', $r).'/***';
			} else
				$rules[] = (strpos($w, '/') !== false ? '**/' : '').str_replace('*', '**', $w).'/***';
		} elseif (in_array($f[0], array('mount', 'marker', 'cachedir'), true)) {
			$r = $rel_to(ltrim($w, '/'));
			if ($r !== null)
				$rules[] = $r === '' ? '/***' : '/'.$r.'/***';
		}
	}
	$out = array();
	foreach (array_unique($rules) as $r)
		if (strpos($r, "\n") === false)
			$out[] = '--filter=P '.$r;
	return $out;
}

/* mail: the messages of the backup's folders that are not in the mailbox now
   (a message keeps the first part of its file name for life; the flags after
   ":" change), and the folders that are gone; never the indexes */
function bk_mail_merge($src, $dst) {
	$added = $folders = 0;
	$dirs = array('');
	foreach (scandir($src) ?: array() as $n)
		if ($n !== '.' && $n !== '..' && $n[0] === '.' && is_dir($src.'/'.$n) && !is_link($src.'/'.$n) && is_dir($src.'/'.$n.'/cur'))
			$dirs[] = $n;
	foreach ($dirs as $f) {
		$from = $src.($f !== '' ? '/'.$f : '');
		$to = $dst.($f !== '' ? '/'.$f : '');
		if (is_link($to))
			continue;
		if (!is_dir($to)) {
			if (!mkdir($to, 0700))
				fail('The folder {name} could not be made (no permission, or the disk is full).', 'perm', array('args' => array('name' => $f)));
			$folders++;
			if (is_file($from.'/maildirfolder'))
				@touch($to.'/maildirfolder');
		}
		foreach (array('cur', 'new', 'tmp') as $sub)
			if (!is_dir($to.'/'.$sub))
				@mkdir($to.'/'.$sub, 0700);
		$have = array();
		foreach (array('cur', 'new') as $sub)
			foreach (scandir($to.'/'.$sub) ?: array() as $n)
				if ($n[0] !== '.')
					$have[strtok($n, ':')] = true;
		foreach (array('cur', 'new') as $sub)
			foreach (scandir($from.'/'.$sub) ?: array() as $n) {
				if ($n[0] === '.' || isset($have[strtok($n, ':')]) || !is_file($from.'/'.$sub.'/'.$n) || is_link($from.'/'.$sub.'/'.$n))
					continue;
				if (!@rename($from.'/'.$sub.'/'.$n, $to.'/'.$sub.'/'.$n) && !@copy($from.'/'.$sub.'/'.$n, $to.'/'.$sub.'/'.$n))
					fail('Messages could not be put back (no permission, or the disk is full).', 'copy');
				@chmod($to.'/'.$sub.'/'.$n, 0600);
				$have[strtok($n, ':')] = true;
				$added++;
			}
	}
	/* folders the mail apps show: the backup's subscriptions added to today's */
	if (is_file($src.'/subscriptions') && !is_link($dst.'/subscriptions')) {
		$now = is_file($dst.'/subscriptions') ? file($dst.'/subscriptions', FILE_IGNORE_NEW_LINES) : array();
		$all = array_values(array_unique(array_merge($now ?: array(), file($src.'/subscriptions', FILE_IGNORE_NEW_LINES) ?: array())));
		if (count($all) !== count($now ?: array()))
			@file_put_contents($dst.'/subscriptions', implode("\n", array_filter($all, 'strlen'))."\n");
	}
	return array($added, $folders);
}

function v_bkrestore(array $q) {
	$root = (string)($q['root'] ?? '');
	$kind = (string)($q['kind'] ?? '');
	$mode = (string)($q['mode'] ?? '');
	if (!preg_match('/^backup-[a-z][a-z0-9]{0,31}$/', $root) || !in_array($kind, array('files', 'mail'), true)
	    || !in_array($mode, $kind === 'mail' ? array('missing', 'replace') : array('missing', 'overwrite', 'replace'), true))
		fail('Malformed request.');
	$segs = segs((string)($q['path'] ?? ''));
	if ($segs === false)
		fail('That is not a place in your home folder.', 'path');
	$rel = rel_of($segs);
	if ($kind === 'files' && $segs && $segs[0] === 'mail')
		fail('Mail is restored per mailbox (Backups, Restore email).', 'path');
	if ($kind === 'mail' && (count($segs) !== 3 || $segs[0] !== 'mail'))
		fail('Malformed request.');
	if ($mode === 'replace' && $kind === 'files' && !$segs)
		fail('Your whole home folder cannot be replaced from a backup: choose a folder in it, or put back what is missing.', 'path');
	$keep = '';
	if ($mode !== 'missing') {
		$k = segs((string)($q['keep'] ?? ''));
		if ($k === false || count($k) !== 2 || $k[0] !== 'backup-restores' || !preg_match('/^[a-z0-9@._-]{1,200}$/', $k[1]))
			fail('Malformed request.');
		$keep = rel_of($k);
	}

	$stage = bk_stage($q['job'] ?? '');
	$member = $root.'/homedir'.($rel !== '' ? '/'.$rel : '');
	$argv = array('/usr/bin/tar', '-x', '-z', '-f', '-', '-C', $stage, '-p', '--no-same-owner', '--warning=no-timestamp');
	if ($rel === '')
		foreach (array('mail', '.reqad', 'backup-restores') as $x)
			$argv[] = '--exclude='.$root.'/homedir/'.$x;
	$argv[] = $member;
	if ($mode === 'replace' && $kind === 'files')
		$argv[] = $root.'/excludes.txt';
	$p = proc_open($argv, array(0 => array('pipe', 'r'), 1 => array('file', '/dev/null', 'w'), 2 => array('pipe', 'w')), $pipes, $stage);
	if (!is_resource($p)) {
		bk_rmtree($stage);
		fail('The backup could not be unpacked (tar did not start).', 'tar');
	}
	stream_set_blocking($pipes[2], false);
	$err = '';
	while (!feof(STDIN)) {
		$buf = fread(STDIN, 1048576);
		if ($buf === false || $buf === '')
			break;
		if (@fwrite($pipes[0], $buf) === false)
			break;               // tar stopped: its message says why
		$err .= (string)stream_get_contents($pipes[2]);
	}
	fclose($pipes[0]);
	stream_set_blocking($pipes[2], true);
	$err .= (string)stream_get_contents($pipes[2]);
	fclose($pipes[2]);
	$rc = proc_close($p);
	$src = $stage.'/'.$member;
	$found = file_exists($src) || is_link($src);
	/* tar says 2 when one of the names was not in the archive: excludes.txt is often missing */
	if (!$found) {
		bk_rmtree($stage);
		if (strpos($err, 'Not found in archive') !== false || $rc === 0 || $rc === 2)
			fail($kind === 'mail' ? 'This mailbox has no messages in that backup.' : 'There is nothing at {path} in that backup.', 'missing', array('args' => array('path' => '~/'.$rel)));
		fail(strpos($err, 'quota') !== false || strpos($err, 'No space') !== false
		     ? 'There is not enough disk space to unpack it (your package\'s limit, or the server is full).'
		     : 'The backup could not be unpacked: {reason}', 'tar', array('args' => array('reason' => substr(trim($err), -400))));
	}
	if ($rc !== 0 && strpos($err, 'Not found in archive') === false && (strpos($err, 'quota') !== false || strpos($err, 'No space') !== false || strpos($err, 'Unexpected EOF') !== false)) {
		bk_rmtree($stage);
		fail(strpos($err, 'Unexpected EOF') !== false ? 'The backup archive ended too early (it may be damaged): nothing was changed.'
		     : 'There is not enough disk space to unpack it (your package\'s limit, or the server is full).', 'tar');
	}

	$isdir = is_dir($src) && !is_link($src);
	$dst_segs = $isdir ? $segs : array_slice($segs, 0, -1);
	$dst = bk_mkdirs($dst_segs);
	if ($kind === 'mail' && $mode === 'missing') {
		list($added, $folders) = bk_mail_merge($src, $dst);
		bk_rmtree($stage);
		out(array('ok' => true, 'added' => $added, 'folders' => $folders, 'replaced' => 0, 'deleted' => 0, 'kept' => ''));
		return;
	}
	$rs = array('/usr/bin/rsync', '-rlpt', '--out-format=%i %l %n');
	if ($mode === 'missing')
		$rs[] = '--ignore-existing';
	else {
		$rs[] = '--backup';
		$rs[] = '--backup-dir='.HOME.'/'.$keep.($kind === 'files' && $isdir && $rel !== '' ? '/'.$rel : ($kind === 'files' && !$isdir && count($segs) > 1 ? '/'.rel_of(array_slice($segs, 0, -1)) : ''));
	}
	if ($mode === 'replace') {
		$rs[] = '--delete';
		if ($kind === 'files')
			$rs = array_merge($rs, bk_protect($dst, $stage, $root, $segs));
		else
			/* the mailbox's own indexes stay (left out both ways); dovecot rebuilds them after */
			foreach (array('dovecot.*', 'dovecot-*') as $x)
				$rs[] = '--exclude='.$x;
	}
	$rs[] = $isdir ? $src.'/' : $src;
	$rs[] = $dst.'/';
	list($rc, $o) = run($rs);
	bk_rmtree($stage);
	$added = $replaced = $deleted = 0;
	foreach (push_lines($o) as $r) {
		/* mail: only the messages count */
		if ($kind === 'mail' && !preg_match('#(^|/)(cur|new)/[^/]+$#', $r[2]))
			continue;
		if (strpos($r[0], '*deleting') === 0) {
			if (substr($r[2], -1) !== '/')
				$deleted++;
		} elseif (substr($r[0], 1, 1) === 'f') {
			if (substr($r[0], 2, 9) === '+++++++++')
				$added++;
			else
				$replaced++;
		}
	}
	if ($rc !== 0 && $rc !== 24)
		fail(strpos($o, 'quota') !== false || strpos($o, 'No space') !== false
		     ? 'There is not enough disk space to put it all back (your package\'s limit, or the server is full). {n} files were put back.'
		     : 'Not everything could be put back: {reason}', 'copy', array('args' => array('reason' => substr($o, -400), 'n' => $added + $replaced)));
	out(array('ok' => true, 'added' => $added, 'replaced' => $replaced, 'deleted' => $deleted, 'folders' => 0,
	          'kept' => $mode !== 'missing' && ($replaced || $deleted) ? $keep : ''));
}

/* ---- SSH keys (app/functions/ssh.php) ------------------------------------------------- */

/* ~/.ssh/authorized_keys as text, with its sha1 (the "put" below takes it
   back, so a change made in between is not lost). Never a symlink: .ssh and
   the file must be the real thing (sshd itself refuses a group/world-writable
   one, and a link could lead anywhere this account may write). */
function ssh_paths() {
	$dir = HOME.'/.ssh';
	if (is_link($dir) || (file_exists($dir) && !is_dir($dir)))
		fail('Your ~/.ssh is not a folder: rename it with the file manager first.', 'ssh');
	$file = $dir.'/authorized_keys';
	if (is_link($file) || (file_exists($file) && !is_file($file)))
		fail('Your ~/.ssh/authorized_keys is not a plain file: rename it with the file manager first.', 'ssh');
	return array($dir, $file);
}

function v_sshkeys(array $q) {
	list(, $file) = ssh_paths();
	if (!file_exists($file)) {
		out(array('ok' => true, 'text' => '', 'sha1' => '', 'exists' => false));
		return;
	}
	if (filesize($file) > 262144)
		fail('Your ~/.ssh/authorized_keys is too big to be read here (over 256 KB).', 'ssh');
	$t = (string)file_get_contents($file);
	out(array('ok' => true, 'text' => $t, 'sha1' => sha1($t), 'exists' => true,
	          'mode' => mode_str(fileperms($file)), 'dir_mode' => mode_str(fileperms(dirname($file)))));
}

/* Write the whole file: {text, sha1 (of what was read; '' = there was none)}.
   .ssh is made 0700, the file is written beside it and renamed into place,
   0600 - the permissions sshd wants. */
function v_sshkeysput(array $q) {
	list($dir, $file) = ssh_paths();
	$text = (string)($q['text'] ?? '');
	if (strlen($text) > 262144 || strpos($text, "\0") !== false)
		fail('Malformed request.');
	$now = file_exists($file) ? sha1((string)file_get_contents($file)) : '';
	if ($now !== (string)($q['sha1'] ?? ''))
		fail('Your keys were changed somewhere else in the meantime. Reload the page and try again.', 'changed');
	if (!is_dir($dir) && !mkdir($dir, 0700))
		fail('The folder ~/.ssh could not be made.', 'ssh');
	chmod($dir, 0700);
	$tmp = $dir.'/.authorized_keys.reqad-'.bin2hex(random_bytes(4));
	$fh = fopen($tmp, 'x');
	if (!$fh)
		fail('~/.ssh/authorized_keys could not be written.', 'ssh');
	chmod($tmp, 0600);
	$ok = fwrite($fh, $text) === strlen($text) && fflush($fh);
	fclose($fh);
	if (!$ok || !rename($tmp, $file)) {
		@unlink($tmp);
		fail('~/.ssh/authorized_keys could not be written (is the disk full?).', 'ssh');
	}
	out(array('ok' => true, 'sha1' => sha1($text)));
}

/* ---- what a folder of code is (Deploy & apps, app_detect() in apps.php) --------------- */

/* A small text file below $dir, or null: missing, not a plain file, leading
   out of the home folder, over $max bytes or binary. */
function af_read($dir, $name, $max = 262144) {
	$r = realpath($dir.'/'.$name);
	if ($r === false || !inside($r) || !is_file($r) || filesize($r) > $max)
		return null;
	$t = @file_get_contents($r);
	return is_string($t) && strpos($t, "\0") === false ? $t : null;
}

/* "3.12" from a version file's line, or '' */
function af_minor($s) {
	return preg_match('/(\d+\.\d+)/', (string)$s, $m) ? $m[1] : '';
}

/* The Python files that may hold the app object: the folder's own *.py, the
   packages next to them (and below src/): file => array(module, chdir) */
function af_py_files($real, array $names) {
	$rank = array('wsgi.py' => 0, 'asgi.py' => 1, 'app.py' => 2, 'main.py' => 3, 'application.py' => 4, 'server.py' => 5, 'run.py' => 6, 'api.py' => 7);
	$skip = array('node_modules', 'venv', 'env', 'tests', 'test', 'migrations', 'static', 'templates', 'docs', 'build', 'dist', 'site-packages');
	$out = array();
	$add = function ($base, $chdir, $pkg) use (&$out, $real, $rank) {
		foreach (@scandir($real.($base !== '' ? '/'.$base : '')) ?: array() as $n)
			if (preg_match('/^[A-Za-z_]\w*\.py$/', $n) && ($pkg === '' || isset($rank[$n]) || $n === '__init__.py')) {
				$m = $n === '__init__.py' ? rtrim($pkg, '.') : $pkg.substr($n, 0, -3);
				if ($m !== '')
					$out[ltrim($base.'/'.$n, '/')] = array($m, $chdir, $n === '__init__.py' ? 9 : ($rank[$n] ?? 8) + ($pkg !== '' ? 10 : 0));
			}
	};
	$add('', '', '');
	foreach ($names as $n) {
		if (count($out) > 60)
			break;
		if (!preg_match('/^[A-Za-z_]\w*$/', $n) || in_array($n, $skip, true) || is_link("$real/$n") || !is_dir("$real/$n"))
			continue;
		if ($n === 'src') {
			$add('src', 'src', '');
			foreach (@scandir("$real/src") ?: array() as $s)
				if (preg_match('/^[A-Za-z_]\w*$/', $s) && is_file("$real/src/$s/__init__.py") && !is_link("$real/src/$s"))
					$add("src/$s", 'src', "$s.");
		} elseif (is_file("$real/$n/__init__.py") || in_array($n, array('app', 'api', 'backend', 'server'), true))
			$add($n, '', "$n.");
	}
	uasort($out, function ($a, $b) { return $a[2] - $b[2]; });
	return array_slice($out, 0, 60, true);
}

/* The facts start commands are made of, read (never run) from the folder
   "path": which files are there, the Python packages and Node.js packages
   that matter, Django's settings, Flask / FastAPI / ... app objects, the
   versions the code asks for, the Procfile's web line. */
function v_appfacts(array $q) {
	$real = real_in($q['path'] ?? '');
	if ($real === false || !is_dir($real)) {
		out(array('ok' => true, 'exists' => false));
		return;
	}
	$names = array_values(array_diff(@scandir($real) ?: array(), array('.', '..', '.reqad-release', '.git')));
	$f = array('ok' => true, 'exists' => true, 'empty' => !$names, 'files' => array_values(array_intersect($names, array(
		'package.json', 'package-lock.json', 'yarn.lock', 'pnpm-lock.yaml', 'requirements.txt', 'pyproject.toml', 'Pipfile', 'setup.py', 'setup.cfg',
		'manage.py', 'Procfile', 'index.html', 'index.php', 'composer.json', '.python-version', 'runtime.txt', '.nvmrc', '.node-version', '.venv', 'node_modules'))));

	/* ---- Python ---- */
	$deps = '';
	foreach (array('requirements.txt', 'requirements/base.txt', 'requirements/prod.txt', 'requirements/production.txt', 'pyproject.toml', 'Pipfile', 'setup.py', 'setup.cfg') as $n)
		$deps .= "\n".strtolower((string)af_read($real, $n));
	$f['py_deps'] = array();
	foreach (array('flask', 'django', 'fastapi', 'starlette', 'litestar', 'quart', 'bottle', 'gunicorn', 'uvicorn', 'hypercorn', 'waitress', 'daphne', 'channels', 'whitenoise') as $p)
		if (preg_match('/(?<![\w.-])'.$p.'(?![\w-])/', $deps))
			$f['py_deps'][] = $p;
	$f['py_version'] = array();
	if (($v = af_minor(af_read($real, '.python-version', 4096))) !== '')
		$f['py_version'] = array('exact' => $v, 'from' => '.python-version');
	elseif (preg_match('/python-(\d+\.\d+)/', (string)af_read($real, 'runtime.txt', 4096), $m))
		$f['py_version'] = array('exact' => $m[1], 'from' => 'runtime.txt');
	elseif (preg_match('/python_version\s*=\s*["\'](\d+\.\d+)/', (string)af_read($real, 'Pipfile'), $m))
		$f['py_version'] = array('exact' => $m[1], 'from' => 'Pipfile');
	elseif (preg_match('/^\s*(?:requires-python|python)\s*=\s*["\']([^"\']+)["\']/m', (string)af_read($real, 'pyproject.toml'), $m)
	        && preg_match('/(?:>=|~=|\^|==)\s*(\d+\.\d+)/', $m[1], $v))
		$f['py_version'] = array(strpos($m[1], '==') !== false && strpos($m[1], '*') === false ? 'exact' : 'min' => $v[1], 'from' => 'pyproject.toml');

	/* Django: manage.py here or one folder down names the settings */
	foreach (array_merge(array(''), array_slice(array_filter($names, function ($n) use ($real) {
		return preg_match('/^[A-Za-z_][\w-]*$/', $n) && !is_link("$real/$n") && is_dir("$real/$n"); }), 0, 40)) as $sub) {
		$base = $real.($sub !== '' ? "/$sub" : '');
		$mp = af_read($base, 'manage.py', 65536);
		if ($mp === null)
			continue;
		$dj = array('dir' => $sub, 'settings' => '', 'wsgi' => '', 'asgi' => '');
		if (preg_match('/DJANGO_SETTINGS_MODULE[\'"]\s*,\s*[\'"]([A-Za-z_][\w.]*)[\'"]/', $mp, $m))
			$dj['settings'] = $m[1];
		$pkgs = array();
		if ($dj['settings'] !== '')
			$pkgs[] = preg_replace('/\.settings(\..*)?$/', '', $dj['settings']);
		foreach (@scandir($base) ?: array() as $n)
			if (preg_match('/^[A-Za-z_]\w*$/', $n) && is_file("$base/$n/wsgi.py"))
				$pkgs[] = $n;
		foreach (array_unique($pkgs) as $p) {
			$path = str_replace('.', '/', $p);
			if ($dj['wsgi'] === '' && strpos((string)af_read($base, "$path/wsgi.py", 65536), 'get_wsgi_application') !== false)
				$dj['wsgi'] = "$p.wsgi";
			if ($dj['asgi'] === '' && strpos((string)af_read($base, "$path/asgi.py", 65536), 'get_asgi_application') !== false)
				$dj['asgi'] = "$p.asgi";
		}
		$f['django'] = $dj;
		break;
	}

	/* app objects: "app = Flask(__name__)" at the start of a line of a file
	   that imports the framework; a create_app() factory when there is none */
	$fws = array('flask' => 'Flask', 'fastapi' => 'FastAPI', 'starlette' => 'Starlette', 'litestar' => 'Litestar', 'quart' => 'Quart', 'bottle' => 'Bottle');
	$f['py_apps'] = array();
	foreach (af_py_files($real, $names) as $file => list($module, $chdir)) {
		$t = af_read($real, $file);
		if ($t === null)
			continue;
		$hit = false;
		foreach ($fws as $fw => $cls) {
			if (!preg_match('/^\s*(?:from\s+'.$fw.'(?:\.[\w.]+)?\s+import|import\s+'.$fw.')\b/m', $t))
				continue;
			if (preg_match('/^([A-Za-z_]\w*)\s*(?::[^=\n]+)?=\s*(?:[\w.]+\.)?'.$cls.'\s*\(/m', $t, $m))
				$f['py_apps'][] = array('fw' => $fw, 'module' => $module, 'var' => $m[1], 'factory' => false, 'file' => $file, 'chdir' => $chdir);
			elseif (preg_match('/^def\s+(create_app|make_app|get_app)\s*\(/m', $t, $m))
				$f['py_apps'][] = array('fw' => $fw, 'module' => $module, 'var' => $m[1], 'factory' => true, 'file' => $file, 'chdir' => $chdir);
			else
				continue;
			$hit = true;
			break;
		}
		/* a wsgi.py / asgi.py that builds the app object from elsewhere */
		if (!$hit && preg_match('/^(wsgi|asgi)\.py$/', basename($file), $w) && strpos($t, 'get_wsgi_application') === false
		    && strpos($t, 'get_asgi_application') === false && preg_match('/^(application|app)\s*=/m', $t, $m))
			$f['py_apps'][] = array('fw' => $w[1], 'module' => $module, 'var' => $m[1], 'factory' => false, 'file' => $file, 'chdir' => $chdir);
		if (count($f['py_apps']) >= 8)
			break;
	}
	$f['py_files'] = count(preg_grep('/^[A-Za-z_]\w*\.py$/', $names));
	$pf = (string)af_read($real, 'Procfile', 16384);
	$f['procfile'] = preg_match('/^web:\s*(.+?)\s*$/m', $pf, $m) ? substr($m[1], 0, 1000) : '';

	/* ---- Node.js ---- */
	$pj = af_read($real, 'package.json', 1048576);
	$j = $pj !== null ? json_decode($pj, true) : null;
	if (is_array($j)) {
		$scripts = isset($j['scripts']) && is_array($j['scripts']) ? array_filter($j['scripts'], 'is_string') : array();
		$all = array_merge(isset($j['devDependencies']) && is_array($j['devDependencies']) ? $j['devDependencies'] : array(),
		                   isset($j['dependencies']) && is_array($j['dependencies']) ? $j['dependencies'] : array());
		$n = array('start' => substr((string)($scripts['start'] ?? ''), 0, 500), 'scripts' => array_slice(array_keys($scripts), 0, 50),
		           'main' => is_string($j['main'] ?? null) ? substr($j['main'], 0, 200) : '', 'type' => is_string($j['type'] ?? null) ? $j['type'] : '',
		           'deps' => array_values(array_intersect(array('next', 'nuxt', '@sveltejs/kit', '@sveltejs/adapter-node', 'astro', '@astrojs/node',
		               '@remix-run/serve', '@remix-run/node', '@nestjs/core', 'express', 'fastify', 'koa', '@hapi/hapi', 'hono', '@hono/node-server',
		               'vite', 'react-scripts', '@angular/core', 'typescript', 'ts-node', 'tsx'), array_keys($all))),
		           'version' => array());
		$eng = is_array($j['engines'] ?? null) && is_string($j['engines']['node'] ?? null) ? $j['engines']['node'] : '';
		if (($v = (string)af_read($real, '.nvmrc', 1024)) !== '' && preg_match('/^\s*v?(\d+)/', $v, $m))
			$n['version'] = array('exact' => $m[1], 'from' => '.nvmrc');
		elseif (($v = (string)af_read($real, '.node-version', 1024)) !== '' && preg_match('/^\s*v?(\d+)/', $v, $m))
			$n['version'] = array('exact' => $m[1], 'from' => '.node-version');
		elseif (preg_match('/^\s*>=?\s*v?(\d+)/', $eng, $m))
			$n['version'] = array('min' => $m[1], 'from' => 'package.json');
		elseif (preg_match('/^\s*[~^]?v?(\d+)(\.[\dx*]+)*\s*$/', $eng, $m))
			$n['version'] = array('exact' => $m[1], 'from' => 'package.json');
		/* the file it runs: from the start script, "main", or a usual name */
		$entry = '';
		if (preg_match('/(?:^|&&|;)\s*node\s+(?:--?[\w-]+(?:=\S+)?\s+)*([\w.\/-]+\.[cm]?js)\b/', $n['start'], $m))
			$entry = $m[1];
		elseif ($n['main'] !== '' && af_read($real, $n['main'], 1048576) !== null)
			$entry = $n['main'];
		else
			foreach (array('server.js', 'app.js', 'index.js', 'main.js', 'server.mjs', 'index.mjs', 'app.mjs', 'src/server.js', 'src/index.js', 'src/app.js') as $c)
				if (af_read($real, $c, 1048576) !== null) { $entry = $c; break; }
		$n['entry'] = $entry;
		$n['entry_exists'] = $entry !== '' && af_read($real, $entry, 1048576) !== null;
		$n['fixed_port'] = 0;
		$t = $n['entry_exists'] ? (string)af_read($real, $entry, 1048576) : '';
		if (preg_match('/\.listen\s*\(\s*(\d{2,5})\b/', $t, $m) && !preg_match('/process\.env(\.PORT\b|\[\s*[\'"]PORT)/', $t))
			$n['fixed_port'] = (int)$m[1];
		$f['node'] = $n;
	}
	out($f);
}

/* ---- processes (app/functions/resources.php) ------------------------------------------- */

/* The start time (clock ticks after boot) and real uid of a process; null when it is gone */
function proc_ident($pid) {
	$st = @file_get_contents("/proc/$pid/stat");
	if (!$st || ($p = strrpos($st, ')')) === false)
		return null;
	$f = explode(' ', substr($st, $p + 2));
	if (($f[0] ?? '') === 'Z')
		return null;
	$uid = -1;
	foreach (@file("/proc/$pid/status") ?: array() as $l)
		if (strpos($l, 'Uid:') === 0) { $uid = (int)preg_split('/\s+/', trim($l))[1]; break; }
	return array((string)($f[19] ?? ''), $uid);
}

/* Stop one of this account's processes: TERM, then KILL when it is still
   there after 5 seconds. "start" must match, so a pid used again by another
   process is never hit; the kernel refuses anyone else's process anyway. */
function v_kill(array $q) {
	$pid = (int)($q['pid'] ?? 0);
	$start = (string)($q['start'] ?? '');
	if ($pid < 2 || $pid === getmypid() || !preg_match('/^[0-9]{1,20}$/', $start))
		fail('Malformed request.');
	$id = proc_ident($pid);
	if (!$id || $id[0] !== $start)
		out(array('ok' => true, 'how' => 'gone'));
	elseif ($id[1] !== MY_UID)
		fail('That process is not yours.', 'perm');
	else {
		list($rc, $o) = run(array('/bin/kill', '-s', 'TERM', '--', (string)$pid));
		if ($rc !== 0) {
			$id = proc_ident($pid);
			if (!$id || $id[0] !== $start)
				out(array('ok' => true, 'how' => 'gone'));
			else
				fail('The process could not be stopped: '.$o, 'perm');
			return;
		}
		for ($i = 0; $i < 50; $i++) {
			usleep(100000);
			$id = proc_ident($pid);
			if (!$id || $id[0] !== $start) { out(array('ok' => true, 'how' => 'term')); return; }
		}
		run(array('/bin/kill', '-s', 'KILL', '--', (string)$pid));
		usleep(300000);
		out(array('ok' => true, 'how' => 'kill'));
	}
}

/* ---- the request -------------------------------------------------------------------- */

$verbs = array('list' => 'v_list', 'stat' => 'v_stat', 'read' => 'v_read', 'save' => 'v_save', 'upload' => 'v_upload',
               'download' => 'v_download', 'mkdir' => 'v_mkdir', 'create' => 'v_create', 'rename' => 'v_rename',
               'copy' => 'v_copy', 'chmod' => 'v_chmod', 'delete' => 'v_delete', 'compress' => 'v_compress', 'extract' => 'v_extract',
               'usage' => 'v_usage', 'tail' => 'v_tail', 'cronruns' => 'v_cronruns', 'wpfind' => 'v_wpfind', 'wpdbname' => 'v_wpdbname', 'wplogin' => 'v_wplogin',
               'wpprep' => 'v_wpprep', 'wpplace' => 'v_wpplace', 'wpperms' => 'v_wpperms', 'wpclone' => 'v_wpclone',
               'wppushdiff' => 'v_wppushdiff', 'wppush' => 'v_wppush', 'wppushundo' => 'v_wppushundo',
               'bkrestore' => 'v_bkrestore', 'sshkeys' => 'v_sshkeys', 'sshkeysput' => 'v_sshkeysput', 'appfacts' => 'v_appfacts',
               'kill' => 'v_kill');
if (!isset($verbs[$req['verb']]))
	fail('Unknown request.');
call_user_func($verbs[$req['verb']], $req);
