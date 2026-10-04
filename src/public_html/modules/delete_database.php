<?php
/* Web wrapper: the work is database_delete() in app/functions/databases.php. */
$r = database_delete(trim($_POST['database'] ?? ''));
$errmsg     = $r['ok'] ? '' : 'Error: '.$r['error'];
$successmsg = $r['message'];
