<?php
/*
 * SSH access of a hosting account: how it may log in (its shell, the
 * server's SSH port, whether passwords are taken) and its SSH keys, for
 * 2087's SSH keys page, the API and the account panel on 2083.
 *
 * The keys are the account's ~/.ssh/authorized_keys, read and written AS the
 * account (fm-helper verbs sshkeys / sshkeysput, app/functions/files.php):
 * never by root, since a symlink there would lead root's write anywhere.
 * The file is the inventory: lines this panel did not write (options such as
 * from= or command=, comments) are kept as they are. A key is named by its
 * comment and found again by its id, the first 16 hex digits of the SHA-256
 * of the key itself (so the id survives a new comment).
 */

const SSH_KEYS_MAX = 100;

/* Types a new key may have (ssh-dss is off in OpenSSH since 7.0). */
function ssh_key_types() {
	return array('ssh-ed25519', 'ssh-rsa', 'ecdsa-sha2-nistp256', 'ecdsa-sha2-nistp384', 'ecdsa-sha2-nistp521',
	             'sk-ssh-ed25519@openssh.com', 'sk-ecdsa-sha2-nistp256@openssh.com');
}

/* The strings of an SSH wire-format blob (uint32 length + bytes each), or false. */
function ssh_blob_strings($blob, $max = 8) {
	$out = array();
	for ($off = 0, $len = strlen($blob); $off < $len && count($out) < $max; ) {
		if ($off + 4 > $len)
			return false;
		$n = unpack('N', substr($blob, $off, 4))[1];
		if ($n > $len - $off - 4)
			return false;
		$out[] = substr($blob, $off + 4, $n);
		$off += 4 + $n;
	}
	return $out;
}

/* What a key is: array(type, bits, fingerprint, id) for a type + base64 blob, or null. */
function ssh_key_info($type, $b64) {
	$blob = base64_decode($b64, true);
	if ($blob === false || strlen($blob) < 16)
		return null;
	$s = ssh_blob_strings($blob);
	if (!$s || $s[0] !== $type)
		return null;
	$bits = 0;
	if ($type === 'ssh-rsa' && isset($s[2]))
		$bits = strlen(ltrim($s[2], "\0")) * 8;
	elseif ($type === 'ssh-dss' && isset($s[1]))
		$bits = strlen(ltrim($s[1], "\0")) * 8;
	elseif (strpos($type, 'ed25519') !== false)
		$bits = 256;
	elseif (preg_match('/nistp(256|384|521)/', $type, $m))
		$bits = (int)$m[1];
	$h = hash('sha256', $blob, true);
	return array('type' => $type, 'bits' => $bits, 'fingerprint' => 'SHA256:'.rtrim(base64_encode($h), '='),
	             'id' => substr(bin2hex($h), 0, 16));
}

/* One line of authorized_keys: array(options, type, b64, comment) or null
   (a blank line, a comment, or something sshd would not read either). */
function ssh_key_line($line) {
	$line = trim($line);
	if ($line === '' || $line[0] === '#')
		return null;
	$opts = '';
	if (!preg_match('/^(ssh-|ecdsa-|sk-)/', $line)) {
		/* options first: up to the first space outside double quotes */
		$q = false;
		for ($i = 0, $n = strlen($line); $i < $n; $i++) {
			$c = $line[$i];
			if ($c === '\\' && $q) { $i++; continue; }
			if ($c === '"') $q = !$q;
			elseif (!$q && ($c === ' ' || $c === "\t")) break;
		}
		$opts = substr($line, 0, $i);
		$line = ltrim(substr($line, $i));
	}
	$p = preg_split('/\s+/', $line, 3);
	if (count($p) < 2 || !preg_match('/^[a-z0-9@.-]+$/', $p[0]) || !preg_match('#^[A-Za-z0-9+/]+=*$#', $p[1]))
		return null;
	return array($opts, $p[0], $p[1], trim($p[2] ?? ''));
}

/* A pasted public key (OpenSSH's one line, or the "---- BEGIN SSH2 PUBLIC
   KEY ----" form PuTTYgen shows) -> array(type, b64, comment), or an error string. */
function ssh_key_from_input($text) {
	$text = trim(str_replace("\r", '', (string)$text));
	if ($text === '')
		return 'Paste the public key.';
	if (preg_match('/-----BEGIN [A-Z ]*PRIVATE KEY-----|PuTTY-User-Key-File/', $text))
		return 'That is a private key: keep it on your computer and never paste it anywhere. Paste the public key (the .pub file) instead.';
	if (strpos($text, '---- BEGIN SSH2 PUBLIC KEY ----') !== false) {
		$comment = '';
		$b64 = '';
		$in = false;
		$cont = false;
		foreach (explode("\n", $text) as $l) {
			$l = trim($l);
			if ($l === '---- BEGIN SSH2 PUBLIC KEY ----') { $in = true; continue; }
			if ($l === '---- END SSH2 PUBLIC KEY ----') break;
			if (!$in) continue;
			if ($cont) { $cont = substr($l, -1) === '\\'; continue; }
			if (preg_match('/^([A-Za-z-]+):\s*(.*)$/', $l, $m)) {
				if (strtolower($m[1]) === 'comment')
					$comment = trim($m[2], " \"");
				$cont = substr($l, -1) === '\\';
				continue;
			}
			$b64 .= $l;
		}
		$blob = base64_decode($b64, true);
		$s = $blob !== false ? ssh_blob_strings($blob, 1) : false;
		if (!$s)
			return 'This does not look like a public key.';
		$type = $s[0];
	} else {
		if (strpos($text, "\n") !== false)
			return 'Paste one key at a time: it is one line.';
		$l = ssh_key_line($text);
		if (!$l)
			return 'This does not look like a public key. It starts with ssh-ed25519, ssh-rsa or ecdsa-sha2-...';
		if ($l[0] !== '')
			return 'Paste the key alone, without options in front of it.';
		list(, $type, $b64, $comment) = $l;
	}
	if (!in_array($type, ssh_key_types(), true))
		return $type === 'ssh-dss' ? 'DSA keys are no longer accepted by SSH. Make an Ed25519 key instead.'
		                           : 'This kind of key is not accepted here. Use an Ed25519, RSA or ECDSA key.';
	$i = ssh_key_info($type, $b64);
	if (!$i)
		return 'This key is damaged: part of it is missing. Copy the whole line again.';
	if ($type === 'ssh-rsa' && $i['bits'] < 2048)
		return 'This RSA key is too short (under 2048 bits). Make a new one, preferably Ed25519.';
	return array($type, $b64, $comment);
}

/* A key's name as it goes in the file: one line of printable text. */
function ssh_key_name_clean($name) {
	$name = trim(preg_replace('/[\x00-\x1f\x7f]+/', ' ', (string)$name));
	return function_exists('mb_substr') ? mb_substr($name, 0, 100) : substr($name, 0, 100);
}

/* The file as the helper read it: array(answer, error result or null). */
function ssh_keys_read($user) {
	$a = files_run($user, array('verb' => 'sshkeys'), null, null, 30);
	if (empty($a['ok']))
		return array($a, files_result($a));
	return array($a, null);
}

function ssh_keys_write($user, $text, $sha1) {
	$a = files_run($user, array('verb' => 'sshkeysput', 'text' => $text, 'sha1' => (string)$sha1), null, null, 30);
	return empty($a['ok']) ? files_result($a) : null;
}

/* The keys of $user: keys (id, type, bits, fingerprint, name, options, key =
   the base64 part), and
   how many other lines the file has (comments and lines sshd ignores). */
function ssh_keys_list($user) {
	list($a, $e) = ssh_keys_read($user);
	if ($e)
		return $e;
	$keys = array();
	$other = 0;
	foreach (explode("\n", (string)$a['text']) as $line) {
		if (trim($line) === '')
			continue;
		$l = ssh_key_line($line);
		$i = $l ? ssh_key_info($l[1], $l[2]) : null;
		if (!$i) {
			$other++;
			continue;
		}
		$keys[] = $i + array('name' => $l[3], 'options' => $l[0], 'key' => $l[2]);
	}
	/* sshd ignores the file when it or ~/.ssh can be written by others */
	$bad = !empty($a['exists']) && ((octdec((string)($a['mode'] ?? '600')) & 022) !== 0 || (octdec((string)($a['dir_mode'] ?? '700')) & 022) !== 0);
	return result_ok('', array('keys' => $keys, 'other' => $other, 'perms_bad' => $bad));
}

function ssh_key_add($user, $text, $name = '') {
	$k = ssh_key_from_input($text);
	if (is_string($k))
		return result_error($k);
	if (($lim = limit_allows($user, 'ssh_key')) !== '')
		return result_error($lim);
	list($type, $b64, $comment) = $k;
	$name = ssh_key_name_clean($name !== '' ? $name : $comment);
	$info = ssh_key_info($type, $b64);
	list($a, $e) = ssh_keys_read($user);
	if ($e)
		return $e;
	$text = (string)$a['text'];
	$n = 0;
	foreach (explode("\n", $text) as $line)
		if (($l = ssh_key_line($line)) && ($i = ssh_key_info($l[1], $l[2]))) {
			if ($i['id'] === $info['id'])
				return result_error('This key is already on the account'.($l[3] !== '' ? ', as "'.$l[3].'"' : '').'.');
			$n++;
		}
	if ($n >= SSH_KEYS_MAX)
		return result_error('An account can have at most '.SSH_KEYS_MAX.' SSH keys.');
	if ($text !== '' && substr($text, -1) !== "\n")
		$text .= "\n";
	$text .= $type.' '.$b64.($name !== '' ? ' '.$name : '')."\n";
	if ($e = ssh_keys_write($user, $text, $a['sha1']))
		return $e;
	route_log("ssh key added for $user: ".$info['fingerprint'].($name !== '' ? " ($name)" : ''));
	plugin_hook('ssh_key.created', array('account' => $user, 'id' => $info['id'], 'fingerprint' => $info['fingerprint'], 'name' => $name));
	return result_ok('Key added. It works for SSH and SFTP logins right away.', $info + array('name' => $name));
}

/* Remove the key with id $id (every line holding it). */
function ssh_key_delete($user, $id) {
	if (!is_string($id) || !preg_match('/^[0-9a-f]{16}$/', $id))
		return result_error('No such key.');
	list($a, $e) = ssh_keys_read($user);
	if ($e)
		return $e;
	$keep = array();
	$gone = null;
	foreach (explode("\n", (string)$a['text']) as $line) {
		if (($l = ssh_key_line($line)) && ($i = ssh_key_info($l[1], $l[2])) && $i['id'] === $id) {
			$gone = $i + array('name' => $l[3]);
			continue;
		}
		$keep[] = $line;
	}
	if (!$gone)
		return result_error('That key is not on the account any more.');
	$text = rtrim(implode("\n", $keep), "\n");
	if ($e = ssh_keys_write($user, $text !== '' ? $text."\n" : '', $a['sha1']))
		return $e;
	route_log("ssh key removed for $user: ".$gone['fingerprint'].($gone['name'] !== '' ? ' ('.$gone['name'].')' : ''));
	plugin_hook('ssh_key.deleted', array('account' => $user, 'id' => $id, 'fingerprint' => $gone['fingerprint'], 'name' => $gone['name']));
	return result_ok('Key removed: it no longer logs in.', $gone);
}

/* Write the file again as it is: ~/.ssh 0700, the file 0600 (sshd ignores
   keys that others could change). */
function ssh_keys_fix($user) {
	list($a, $e) = ssh_keys_read($user);
	if ($e)
		return $e;
	if (empty($a['exists']))
		return result_ok('There are no keys yet.');
	if ($e = ssh_keys_write($user, (string)$a['text'], $a['sha1']))
		return $e;
	route_log("ssh keys of $user: permissions fixed");
	return result_ok('Fixed: only you can change your keys now, so SSH uses them again.');
}

/* The server's SSH: ports and whether passwords are taken (sshd -T: Include
   and defaults resolved). Cached for the request. */
function ssh_server() {
	static $c = null;
	if ($c !== null)
		return $c;
	$t = (string)shell_exec('sudo -n sshd -T 2>/dev/null');
	$ports = preg_match_all('/^port\s+(\d+)$/mi', $t, $m) ? array_values(array_unique(array_map('intval', $m[1]))) : array(22);
	$pw = !preg_match('/^passwordauthentication\s+no$/mi', $t);
	return $c = array('ports' => $ports, 'password' => $pw, 'known' => $t !== '');
}

/* What the account panel's SSH access page shows besides the keys. */
function ssh_access_info($user) {
	$srv = ssh_server();
	return array(
		'shell'    => account_shell_get($user),     // full | jailed | none (SFTP only) | other (no login)
		'host'     => strtolower(trim((string)php_uname('n'))),
		'port'     => $srv['ports'][0] ?? 22,
		'password' => $srv['password'],
		'user'     => $user,
	);
}
