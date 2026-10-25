<?php
/* Save an account's database connection limit (Advanced Config > Limits >
   Databases). POST: user, connections (0 = no limit), default (1 = back to the
   server default). database_conn_limit_set() applies it to every MySQL user of
   the account. */

$acct_user = preg_replace('/[^a-z0-9]/', '', trim($_POST['user'] ?? ''));
$msg_base  = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/account/'.$acct_user.'/?tab=config&cfg=limits';

if ($acct_user === '' || !account_get($db, $acct_user))
	msg_redirect($_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/accounts/', 'Error: Account not found.', 'error');

$conn = trim((string)($_POST['connections'] ?? ''));
if (empty($_POST['default']) && !ctype_digit($conn))
	msg_redirect($msg_base, 'Error: connections must be a number, 0 for no limit.', 'error');

result_redirect($msg_base, database_conn_limit_set($acct_user, !empty($_POST['default']) ? null : (int)$conn));
