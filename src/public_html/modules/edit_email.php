<?php
/* Web wrapper: the work is email_edit() in app/functions/emails.php. The form
   always carries the enable/disable switch; an empty password means "leave the
   password alone" and only apply the switch. */
$r = email_edit(array(
	'email'    => trim($_POST['email'] ?? ''),
	'password' => trim($_POST['password'] ?? ''),
	'enabled'  => isset($_POST['login_enabled']) && $_POST['login_enabled'] == '1',
));

$msg_base = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/email-accounts/';
result_redirect($msg_base, $r);
