<?php
/* Web wrapper: database_assign() / database_unassign() in app/functions/databases.php.
   An empty account means "unassigned". */
$account = trim($_POST['account'] ?? '');
$r = $account === ''
	? database_unassign(trim($_POST['database'] ?? ''))
	: database_assign(trim($_POST['database'] ?? ''), $account);
$errmsg     = $r['ok'] ? '' : 'Error: '.$r['error'];
$successmsg = $r['message'];
