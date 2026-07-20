<?php
$domain   	 	= trim($_POST["domain"]);
$title     	 	= trim($_POST["title"]);
$admin_user   	= trim($_POST["user"]);
$admin_password = trim($_POST["password"]);
$admin_email 	= trim($_POST["email"]);
$cache_life 	= 3600; //caching time, in seconds

#$errmsg = "$domain $title $user";

/*
 curl -s https://api.wordpress.org/core/stable-check/1.0/ > /usr/local/reqad/wptoolkit/stable-check.json
 curl -s --output /usr/local/reqad/wptoolkit/wordpress-6.7.2.tar.gz https://downloads.wordpress.org/release/wordpress-6.7.2.tar.gz
 */

/*
TODO download and global cache Wordpress instead of download for each user
$wp_versions = array();
$stable_file = _PATH.'/wptoolkit/stable-check.json';
if(!is_file($stable_file) || time() - filemtime($stable_file) >= $cache_life) {
	shell_exec('curl -s https://api.wordpress.org/core/stable-check/1.0/ > '.$stable_file.'.tmp');
	$wp_versions = @json_decode(file_get_contents($stable_file.'.tmp'), true);
	if(!empty($wp_versions)) {
		shell_exec('/bin/mv '.$stable_file.'.tmp '.$stable_file);
	}
}

$wp_versions = json_decode(file_get_contents($stable_file), true);
$wp_latest = array_search('latest', $wp_versions);
if(!is_file(_PATH.'/wptoolkit/wordpress-'.$wp_latest.'.tar.gz')) {
	shell_exec('curl -s --output '._PATH.'/wptoolkit/wordpress-'.$wp_latest.'.tar.gz https://downloads.wordpress.org/release/wordpress-'.$wp_latest.'.tar.gz');
}
*/

$results = $db->query('SELECT user,domain FROM accounts WHERE domain="'.$domain.'"');
if ($row = $results->fetchArray()) {
	$user = $row["user"];
}

$output = trim(shell_exec('sudo ls -1 /home/'.$user.'/public_html/'));
if($output=='index.php' || $output=='') {
	#echo('sudo /usr/local/bin/wp core download --path=/home/'.$user.'/public_html/ --allow-root'); exit;
	@shell_exec('sudo -u '.$user.' /bin/mv /home/'.$user.'/public_html/index.php /home/'.$user.'/public_html/_old_index.php');
	$output = shell_exec('sudo -u '.$user.' /usr/local/bin/wp core download --path=/home/'.$user.'/public_html/ 2>&1');
	if(substr($output, 0, 5)=='Error') {
		$errmsg = $output;
	} else {
		$db_name = $db_user = $user.'_wp';
		$mysql_array = @json_decode(@json_encode(simplexml_load_string(shell_exec("sudo mysql --xml=true -e 'SHOW DATABASES'"))), true);
		foreach($mysql_array["row"] as $mysql_array2) {
			$mysql_databases[] = $mysql_array2["field"];
		}
		$i=0;
		while(in_array($db_name, $mysql_databases)) {
			$i++;
			$db_name = $db_user = $user.'_wp'.$i;
		}

		$mysql_users = array();
		$mysql_array = @json_decode(@json_encode(simplexml_load_string(shell_exec("sudo mysql --xml=true -e 'SELECT DISTINCT User FROM mysql.db'"))), true);
		foreach($mysql_array["row"] as $mysql_array2) {
			if(isset($mysql_array2["field"]))
				$mysql_users[] = $mysql_array2["field"];
		}
		while(in_array($db_user, $mysql_users)) {
			$i++;
			$db_user = $user.'_wp'.$i;
		}
		$db_pass = trim(shell_exec("head -n 10 /dev/urandom | tr -cd '[:alnum:]!@#%^&*()+-0123456789' | paste -sd - | sed 's/[\t, ]//g' | cut -b -16"));
		$output = shell_exec("sudo mysql -e 'CREATE DATABASE ".$db_name."' 2>&1");
		$output = shell_exec("sudo mysql -e 'GRANT ALL ON ".$db_name.".* TO ".$db_user."@localhost IDENTIFIED BY \"".addslashes($db_pass)."\"'  2>&1");
	
		$output = shell_exec('sudo -u '.$user.' /usr/local/bin/wp config create --dbname='.escapeshellarg($db_name).' --dbuser='.escapeshellarg($db_user).' --dbpass='.escapeshellarg($db_pass).' --path=/home/'.$user.'/public_html/ 2>&1');
		if(substr($output, 0, 5)=='Error') {
			$errmsg = $output;
		} else {
			#shell_exec('sudo -u '.$user.' /usr/local/bin/wp core install --url="https://'.$domain.'/" --title="'.escapeshellarg($title).'" --admin_user="'.escapeshellarg($admin_user).'" --admin_password="'.escapeshellarg($admin_password).'" --admin_email="'.escapeshellarg($admin_email).'" --path=/home/'.$user.'/public_html/');
			#die('sudo -u '.$user.' /usr/local/bin/wp core install --url=https://'.$domain.'/ --title='.escapeshellarg($title).' --admin_user='.escapeshellarg($admin_user).' --admin_password='.escapeshellarg($admin_password).' --admin_email='.escapeshellarg($admin_email).' --path=/home/'.$user.'/public_html/');
			$output = shell_exec('sudo -u '.$user.' /usr/local/bin/wp core install --url=https://'.$domain.'/ --title='.escapeshellarg($title).' --admin_user='.escapeshellarg($admin_user).' --admin_password='.escapeshellarg($admin_password).' --admin_email='.escapeshellarg($admin_email).' --path=/home/'.$user.'/public_html/ 2>&1');
			error_log("wp install ".__FILE__."\n".$output."\n",  3, __DIR__.'/../../log/debug_log');

			if(substr($output, 0, 5)=='Error') {
				$errmsg = $output;
			} else {
				shell_exec('sudo -u '.$user.' /usr/local/bin/wp plugin update --all --path=/home/'.$user.'/public_html/ >> '.__DIR__.'/../../log/debug_log'); // update akismet
				shell_exec('sudo -u '.$user.' /usr/local/bin/wp theme update --all --path=/home/'.$user.'/public_html/  >> '.__DIR__.'/../../log/debug_log'); // update theme
				shell_exec('sudo -u '.$user.' /usr/local/bin/wp option update auto_update_core_major enabled --path=/home/'.$user.'/public_html/ >> '.__DIR__.'/../../log/debug_log');
				shell_exec('sudo -u '.$user.' /usr/local/bin/wp option update auto_update_core_minor enabled --path=/home/'.$user.'/public_html/ >> '.__DIR__.'/../../log/debug_log');
				shell_exec('sudo -u '.$user.' /usr/local/bin/wp plugin auto-updates enable --all --path=/home/'.$user.'/public_html/ >> '.__DIR__.'/../../log/debug_log');
				shell_exec('sudo -u '.$user.' /usr/local/bin/wp theme  auto-updates enable --all --path=/home/'.$user.'/public_html/ >> '.__DIR__.'/../../log/debug_log');

				$output = trim(shell_exec('/usr/local/bin/wp core version --allow-root --path=/home/'.$user.'/public_html/ | head -n 1'));
				if($output!='Error: This does not seem to be a WordPress installation.') {
					$wp_version = $output;
					// list explicit columns — the table gained a `path` column in a later
					// migration, so a positional INSERT now fails ("9 columns but 8 values")
					// and the install never showed up in the list. path='' = docroot root.
					$db->query('INSERT INTO wordpress (user, domain, title, wp_version, comments, status, created_at, path) VALUES ("'.$user.'", "'.$domain.'", "'.addslashes($title).'", "'.$wp_version.'", "", "active", datetime("now"), "")');
				} else {
					$errmsg = 'Error: Cannot determine Wordpress version after install finished.';
				}
			}
		}
	}
} else {
	$errmsg = "Error: Domain $domain contains files (other than index.php).";
}	

if($errmsg == '') {
	$successmsg =  "Wordpress successfully installed!";
}
?>
