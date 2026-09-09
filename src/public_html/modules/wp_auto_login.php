<?php
$user   		= trim(isset($_POST["user"]) ? $_POST["user"] : (isset($_GET["user"]) ? $_GET["user"] : ''));

if(!valid_username($user)) {
	echo('Wrong username');
	exit;
}

$docroot 	 = wp_site_docroot($user);
$mu_plugins  = $docroot.'/wp-content/mu-plugins/';

$q_user    = escapeshellarg($user);
$q_docroot = escapeshellarg($docroot);
$q_mu      = escapeshellarg($mu_plugins);

echo shell_exec("sudo mkdir -p $q_mu && sudo /bin/cp /usr/local/reqad/scripts/templates/wordpress-autologin.php $q_mu/autologin.php && sudo chown -R $user:$user $q_mu");
sleep(1);
if(is_file($mu_plugins.'/autologin.php')) {
	$ttl = 120; // seconds until link expires
	$expires = time() + $ttl;

	/*
	 * Read the site's HMAC key and URL WITHOUT pulling tenant PHP into this
	 * process. This used to `require_once` the account's own wp-load.php, which
	 * meant any tenant who could write to their own docroot got arbitrary code
	 * executed inside the panel -- and the panel has passwordless sudo. wp-cli
	 * run under `sudo -u <user>` keeps that code on the tenant's side of the
	 * privilege boundary, which is the same pattern wp_install.php already uses.
	 */
	$auth_key = trim(shell_exec("sudo -u $q_user /usr/local/bin/wp config get SECURE_AUTH_KEY --path=$q_docroot 2>/dev/null"));
	$site_url = trim(shell_exec("sudo -u $q_user /usr/local/bin/wp option get siteurl --path=$q_docroot 2>/dev/null"));

	if($auth_key == '' || $site_url == '') {
		$errmsg = "Error: Autologin failed because the Wordpress configuration could not be read.";
	} else {
		$sig = hash_hmac('sha256', (string)$expires, $auth_key);

		header('Location: '.rtrim($site_url, '/') . '/?otl_expires=' . $expires . '&otl_sig=' . urlencode($sig));
		shell_exec("/usr/local/reqad/scripts/delete_autologin.sh $q_user 2>/dev/null >/dev/null &");
		exit;
	}
} else {
	$errmsg = "Error: Autologin failed because Wordpress instance is not accessible.";
}
