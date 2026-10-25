<?php
/* Set the process manager of an account's php-fpm pool (Advanced Config >
   php-fpm pool > Process manager). POST: user, pm, max, idle, start, minsp,
   maxsp, reqs, term. Only the pm lines are rewritten, server-side, from
   validated numbers (root_access=1 only, like the raw editor). The
   result goes through apply_account_config(): php-fpm -t, version history,
   revert on failure. */

$acct_user = preg_replace('/[^a-z0-9]/', '', trim($_POST['user'] ?? ''));
$msg_base  = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/account/'.$acct_user.'/?tab=config&cfg=fpm';

$acct = $acct_user !== '' ? account_get($db, $acct_user) : false;
if(!$acct)
	msg_redirect($_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/accounts/', 'Error: Account not found.', 'error');

/* with root_access=0 the pool is read-only, process manager included */
if(!account_config_editable($ini))
	msg_redirect($msg_base, 'Error: changing the php-fpm pool needs root access (root_access=1 in server-software.ini).', 'error');

$v = array();
foreach(array('pm', 'max', 'idle', 'start', 'minsp', 'maxsp', 'reqs', 'term') as $k)
	$v[$k] = trim((string)($_POST[$k] ?? ''));

$r = account_pool_pm_apply($ini, $acct_user, $acct['domain'], $v);
if($r['error'] !== '')
	msg_redirect($msg_base, 'Error: '.$r['error'], 'error');
route_log("pool pm $acct_user: ".$v['pm'].' max '.$v['max']);
msg_redirect($msg_base, $r['success'], 'success');
