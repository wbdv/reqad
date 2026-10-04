<?php
/* Web wrapper: the work is account_create() in app/functions/accounts.php. */
$on = function($k) { return isset($_POST[$k]) && $_POST[$k] == 'on'; };

/* Pre-allocate a message-queue token so the backgrounded Let's Encrypt job
   (launched inside the template) can post its result to messages.db ~1 min
   later; the accounts list polls this token and shows a toast when done. */
$sslmsgtoken = $on('letsencrypt') ? bin2hex(random_bytes(8)) : '';

$r = account_create($db, array(
	'user'          => trim($_POST['user'] ?? ''),
	'domain'        => trim($_POST['domain'] ?? ''),
	'password'      => trim($_POST['password'] ?? ''),
	'disk_quota'    => (int)($_POST['disk_quota'] ?? 0),
	'dns'           => $on('adddns'),
	'letsencrypt'   => $on('letsencrypt'),
	'email'         => $on('email'),
	'www'           => $on('www_alias'),
	'ssl_msg_token' => $sslmsgtoken,
));

/* Post/Redirect/Get. When the certificate job was actually launched, pass its
   queue token so the accounts list can poll messages.db and show a toast when
   the background job posts its result. */
$msg_base = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/accounts/';
if ($r['ok'] && !empty($r['data']['letsencrypt_started']))
	$msg_base .= '?sslmsg='.$sslmsgtoken;
result_redirect($msg_base, $r);
