<?php
/* Save an account's session limits (Advanced Config > Session limits).
   POST: user, mode (default|custom|none), cpu, memory (MB), processes.
   scripts/session_limits.sh validates the numbers again. */

$acct_user = preg_replace('/[^a-z0-9]/', '', trim($_POST['user'] ?? ''));
$mode      = in_array($_POST['mode'] ?? '', array('default', 'custom', 'none'), true) ? $_POST['mode'] : '';
$msg_base  = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/account/'.$acct_user.'/?tab=config&cfg=limits';

if ($acct_user === '' || !account_get($db, $acct_user))
	msg_redirect($_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/accounts/', 'Error: Account not found.', 'error');
if ($mode === '')
	msg_redirect($msg_base, 'Error: choose the server defaults, custom limits or no limit.', 'error');

result_redirect($msg_base, account_session_limits_set($acct_user, $mode,
	(int)($_POST['cpu'] ?? 0), (int)($_POST['memory'] ?? 0), (int)($_POST['processes'] ?? 0)));
