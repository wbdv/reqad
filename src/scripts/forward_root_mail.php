#!/usr/bin/php82
<?php
// Rate limit: max 30 forwards per hour to prevent abuse
$rate_file = '/usr/local/reqad/etc/reqad_rootmail_rate';
$max_per_hour = 30;
$rate = @json_decode(@file_get_contents($rate_file), true);
$now  = time();
if (!is_array($rate) || ($now - ($rate['reset'] ?? 0)) > 3600) {
	$rate = ['count' => 0, 'reset' => $now];
}
if ($rate['count'] >= $max_per_hour) {
	exit(0); // silently drop — don't bounce to avoid loops
}
$rate['count']++;
file_put_contents($rate_file, json_encode($rate));

// Read raw email from stdin
$raw = stream_get_contents(STDIN);
if (empty(trim($raw))) exit(0);

// Split headers / body at first blank line
$sep = strpos($raw, "\r\n\r\n");
if ($sep !== false) {
	$headers_raw = substr($raw, 0, $sep);
	$body        = substr($raw, $sep + 4);
} else {
	$sep = strpos($raw, "\n\n");
	$headers_raw = $sep !== false ? substr($raw, 0, $sep) : $raw;
	$body        = $sep !== false ? substr($raw, $sep + 2) : '';
}

// Extract and decode Subject
$subject = 'System mail to root';
if (preg_match('/^Subject:\s*(.+(?:\r?\n[ \t].+)*)/mi', $headers_raw, $m)) {
	$raw_subject = preg_replace('/\r?\n[ \t]+/', ' ', trim($m[1]));
	$subject = function_exists('mb_decode_mimeheader')
		? mb_decode_mimeheader($raw_subject)
		: $raw_subject;
}

// Load Reqad settings from SQLite DB
$db_path = '/usr/local/reqad/db/reqad.db';
if (!file_exists($db_path)) exit(75); // EX_TEMPFAIL — exim will retry

$db = new SQLite3($db_path, SQLITE3_OPEN_READONLY);
$get = function(string $name) use ($db): string {
	return (string)($db->querySingle(
		"SELECT value FROM settings WHERE name='" . SQLite3::escapeString($name) . "'"
	) ?? '');
};

$smtp_server = $get('smtp_server');
$smtp_user   = $get('smtp_user');
$smtp_pass   = $get('smtp_password');
$smtp_port   = $get('smtp_port') ?: '465';
$smtp_from   = $get('smtp_from');
$to_email    = $get('email');

if (empty($smtp_server) || empty($to_email)) exit(0);

// Send via PHPMailer
require '/usr/local/reqad/public_html/dist/libs/phpmailer/PHPMailer.php';
require '/usr/local/reqad/public_html/dist/libs/phpmailer/SMTP.php';

$mail = new PHPMailer();
$mail->isSMTP();
$mail->SMTPDebug  = 0;
$mail->Host       = $smtp_server;
$mail->Port       = (int)$smtp_port;
$mail->SMTPSecure = ($smtp_port === '587')
	? PHPMailer::ENCRYPTION_STARTTLS
	: PHPMailer::ENCRYPTION_SMTPS;
$mail->SMTPAuth = true;
$mail->Username = $smtp_user;
$mail->Password = $smtp_pass;
$mail->CharSet  = 'UTF-8';
$mail->setFrom($smtp_from, 'Reqad');
$mail->addAddress($to_email);
$_host = gethostname();
$mail->Subject = (strpos($subject, '[' . $_host . ']') === 0) ? $subject : '[' . $_host . '] ' . $subject;

// Send as HTML if body contains markup, otherwise plain text
if (preg_match('/<html|<body|<div|<p\b/i', $body)) {
	$mail->isHTML(true);
	$mail->Body    = $body;
	$mail->AltBody = strip_tags($body);
} else {
	$mail->isHTML(false);
	$mail->Body = $body;
}

$mail->send();
exit(0);