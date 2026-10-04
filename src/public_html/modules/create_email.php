<?php
/* Web wrapper: the work is email_create() in app/functions/emails.php. */
$r = email_create(array(
	'user'     => trim($_POST['user'] ?? ''),
	'domain'   => trim($_POST['domain'] ?? ''),
	'password' => trim($_POST['password'] ?? ''),
));

$msg_base = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/email-accounts/';
result_redirect($msg_base, $r);
