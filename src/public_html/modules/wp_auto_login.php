<?php
$user   		= trim(isset($_POST["user"]) ? $_POST["user"] : (isset($_GET["user"]) ? $_GET["user"] : ''));

if(!preg_match('/[a-z]+[a-z0-9]{1,7}/', $user)) {
	echo('Wrong username');
	exit;
}

#$results = $db->query('SELECT domain FROM wordpress WHERE user="'.$user.'"');
#if ($row = $results->fetchArray()) {
#	$site_url = 'https://'.$row["domain"];
#}

$shared_key = 'otl_' . hash('sha256', bin2hex(random_bytes(32)));

$mu_plugins  =  '/home/'. $user. '/public_html/wp-content/mu-plugins/';
#echo shell_exec("sudo mkdir -p $mu_plugins && sudo /bin/cp /usr/local/reqad/scripts/templates/wordpress-autologin.php $mu_plugins/autologin.php && echo '$shared_key=\'".$shared_key."\';' >> $mu_plugins/autologin.php && sudo chown -R $user:$user $mu_plugins");
echo shell_exec("sudo mkdir -p $mu_plugins && sudo /bin/cp /usr/local/reqad/scripts/templates/wordpress-autologin.php $mu_plugins/autologin.php && sudo chown -R $user:$user $mu_plugins");
sleep(1);
if(is_file($mu_plugins.'/autologin.php')) {
	$ttl = 120; // seconds until link expires
	$expires = time() + $ttl;
#	$sig = hash_hmac('sha256', (string)$expires, $shared_key);

	define('WP_CACHE', false);
    define('WP_USE_THEMES', false);
    define('FS_METHOD', 'direct');
    define('AUTOMATIC_UPDATER_DISABLED', true);
    define('WP_AUTO_UPDATE_CORE', false);
    define('SHORTINIT', false);

    require_once '/home/'.$user.'/public_html/wp-includes/plugin.php';
	add_filter('enable_loading_object_cache_dropin', '__return_false', PHP_INT_MAX);
    require_once '/home/'.$user.'/public_html/wp-load.php';
    #require_once ABSPATH . WPINC . '/general-template.php';

    $sig = hash_hmac('sha256', (string)$expires, SECURE_AUTH_KEY);
	$site_url = site_url('/');

	header('Location: '.$site_url . '/?otl_expires=' . $expires . '&otl_sig=' . urlencode($sig));
	shell_exec("/usr/local/reqad/scripts/delete_autologin.sh $user 2>/dev/null >/dev/null &");
	exit;
} else {
	$errmsg = "Error: Autologin failed because Wordpress instance is not accessible.";
}