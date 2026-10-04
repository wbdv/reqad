<?php
/* Web wrapper: the work is account_edit() in app/functions/accounts.php.
   The form always carries the PHP select and the email checkbox, so both are
   passed explicitly (unchecked = email off). */
$r = account_edit($db, array(
	'user'     => trim($_POST['user'] ?? ''),
	'domain'   => trim($_POST['domain'] ?? ''),
	'password' => trim($_POST['password'] ?? ''),
	'php'      => trim($_POST['phpversion'] ?? ''),
	'email'    => isset($_POST['hasemail']) && $_POST['hasemail'] == 'on',
));

$msg_base = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/accounts/';
result_redirect($msg_base, $r);
