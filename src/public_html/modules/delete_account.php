<?php
/* Web wrapper: the work is account_delete() in app/functions/accounts.php. */
$r = account_delete($db, trim($_POST['user'] ?? ''));

$msg_base = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/accounts/';
result_redirect($msg_base, $r);
