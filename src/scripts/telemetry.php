#!/usr/bin/php82
<?php
require_once __DIR__ . '/../public_html/defines.php';
require_once __DIR__ . '/../public_html/modules/version.php';

try {
	// check opt-out
	$telemetry = $db->querySingle('SELECT value FROM settings WHERE name="telemetry"');
	if ($telemetry === '0') exit(0);

	// generate install_id if not set
	$install_id = $db->querySingle('SELECT value FROM settings WHERE name="install_id"');
	if (!$install_id) {
		$install_id = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
			mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
			mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
		$db->exec('INSERT INTO settings VALUES ("install_id", "' . $db->escapeString($install_id) . '", datetime("now"))');
	}

	// gather data
	$hostname = gethostname();
	$ip       = trim(shell_exec("hostname -I 2>/dev/null | awk '{print $1}'"));
	$os       = trim(shell_exec("grep PRETTY_NAME /etc/os-release 2>/dev/null | cut -d'=' -f2 | tr -d '\"'"));
	$version  = $reqad_version[0];
	$ver_date = $reqad_version[1];
	$template = $ini['template'] ?? '';

	// email / dovecot: "no" (not installed), "yes" (2.4+), "yes [2.3]" (older)
	$email = 'no';
	$dovecot_bin = trim(shell_exec('command -v dovecot 2>/dev/null'));
	if ($dovecot_bin) {
		$dovecot_ver = trim(shell_exec(escapeshellarg($dovecot_bin) . ' --version 2>/dev/null'));
		// e.g. "2.4.1-4 (7d8c0e5759)" -> "2.4.1-4"
		$dovecot_ver = strtok($dovecot_ver, ' ');
		if (version_compare($dovecot_ver, '2.4', '>=')) {
			$email = 'yes';
		} else {
			// keep only major.minor for the tag, e.g. "2.3"
			$mm = preg_match('/^(\d+\.\d+)/', $dovecot_ver, $m) ? $m[1] : $dovecot_ver;
			$email = 'yes [' . $mm . ']';
		}
	}

	$payload = json_encode([
		'install_id'   => $install_id,
		'hostname'     => $hostname,
		'ip'           => $ip,
		'os'           => $os,
		'version'      => $version,
		'version_date' => $ver_date,
		'template'     => $template,
		'email'        => $email,
	]);

	// POST to hub — silent fail
	$ch = curl_init('https://hub.reqad.net/api/telemetry');
	curl_setopt_array($ch, [
		CURLOPT_POST           => true,
		CURLOPT_POSTFIELDS     => $payload,
		CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT        => 10,
		CURLOPT_CONNECTTIMEOUT => 5,
	]);
	curl_exec($ch);
	curl_close($ch);

} catch (Exception $e) {
	// silent fail
}