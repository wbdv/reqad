<?php
/* Web wrapper: the work is email_create() in app/functions/emails.php. */
$r = email_create(array(
	'user'     => trim($_POST['user'] ?? ''),
	'domain'   => trim($_POST['domain'] ?? ''),
	'password' => trim($_POST['password'] ?? ''),
	'size'     => trim($_POST['size'] ?? ''),      // mailbox size (Hosting Pro's field)
));

$msg_base = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/email-accounts/';
result_redirect($msg_base, $r);
