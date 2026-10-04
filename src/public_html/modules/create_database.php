<?php
/* Web wrapper: the work is database_create() in app/functions/databases.php.
   The databases page shows $errmsg / $successmsg itself (no redirect). */
$r = database_create(array(
	'account'  => trim($_POST['user'] ?? ''),
	'name'     => trim($_POST['dbname'] ?? ''),
	'dbuser'   => trim($_POST['dbuser'] ?? ''),
	'password' => trim($_POST['password'] ?? ''),
));
$errmsg     = $r['ok'] ? '' : 'Error: '.$r['error'];
$successmsg = $r['message'];
