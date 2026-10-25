<?php
/*
 * WP Toolkit "Login": a one-time sign-in to the site's dashboard
 * (wordpress_login() in app/functions/wordpress.php: a must-use plugin made
 * as the account, never by root inside its home). POST id (the install) or
 * user (its first install). Opens in a new tab.
 */
$id   = trim((string)($_POST["id"] ?? $_GET["id"] ?? ''));
$user = trim((string)($_POST["user"] ?? $_GET["user"] ?? ''));

$row = null;
if ($id !== '' && ctype_digit($id))
	$row = wordpress_find($id);
elseif (valid_username($user))
	$row = wordpress_find($user);
if (!$row || ($user !== '' && $row['user'] !== $user)) {
	echo 'Unknown WordPress site.';
	exit;
}

$r = wordpress_login($row);
if ($r['ok']) {
	error_log(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$_SERVER["REMOTE_ADDR"]." ".auth_user()." wp-auto-login ".$row['domain']."\n", 3, __DIR__.'/../../log/route_log');
	header('Location: '.$r['data']['url']);
	exit;
}
$errmsg = 'Error: '.$r['error'];
