<?php
/* Web wrapper: the work is database_password() in app/functions/databases.php. */
$r = database_password(trim($_POST['dbuser'] ?? ''), trim($_POST['password'] ?? ''));
$errmsg     = $r['ok'] ? '' : 'Error: '.$r['error'];
$successmsg = $r['message'];
