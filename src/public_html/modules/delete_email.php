<?php
/* Web wrapper: the work is email_delete() in app/functions/emails.php. */
$r = email_delete($db, trim($_POST['email'] ?? ''));

$msg_base = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/email-accounts/';
result_redirect($msg_base, $r);
