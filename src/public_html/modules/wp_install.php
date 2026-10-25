<?php
/* WP Toolkit > Install Wordpress (the 2087 modal): core's wordpress_install(),
   the same as the account panel's Install WordPress - everything in the home
   folder as the account, a database of its own. Here: the site itself (its
   main domain's ~/public_html), English. */
$domain         = strtolower(trim((string)($_POST['domain'] ?? '')));
$title          = trim((string)($_POST['title'] ?? ''));
$admin_user     = trim((string)($_POST['user'] ?? ''));
$admin_password = (string)($_POST['password'] ?? '');
$admin_email    = trim((string)($_POST['email'] ?? ''));

$user = '';
$stmt = $db->prepare('SELECT user FROM accounts WHERE domain = :d');
$stmt->bindValue(':d', $domain, SQLITE3_TEXT);
$res = $stmt->execute();
if ($res && ($row = $res->fetchArray(SQLITE3_ASSOC)))
	$user = $row['user'];

if ($user === '') {
	$errmsg = "Error: there is no account with the domain $domain.";
} else {
	$r = wordpress_install($user, array('domain' => $domain, 'path' => '', 'title' => $title, 'admin_user' => $admin_user,
	                                    'admin_password' => $admin_password, 'admin_email' => $admin_email, 'locale' => 'en_US'));
	if ($r['ok'])
		$successmsg = $r['message'].($r['warnings'] ? ' '.implode(' ', $r['warnings']) : '');
	else
		$errmsg = 'Error: '.$r['error'];
}
