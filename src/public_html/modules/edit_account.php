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
	'shell'    => trim($_POST['shell'] ?? ''),   // '' = leave as it is
	'contact'  => isset($_POST['contact']) && is_string($_POST['contact']) ? trim($_POST['contact']) : null, // '' = remove
	'package'  => trim($_POST['package'] ?? ''), // hosting package (plugin): name/id, none, '' = leave
	'disk_quota' => isset($_POST['disk_quota']) && is_string($_POST['disk_quota']) ? trim($_POST['disk_quota']) : null, // only while quota is on
	/* fields add-ons put in the dialog (accounts.edit.form) as plugin[<name>],
	   for their account.edit.apply */
	'plugin'   => array_map(function ($v) { return trim((string)$v); },
	                        array_filter((array)($_POST['plugin'] ?? array()), 'is_scalar')),
));

$msg_base = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/accounts/';
result_redirect($msg_base, $r);
