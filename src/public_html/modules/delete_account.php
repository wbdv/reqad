<?php
$user     = trim($_POST["user"]);
$domain   = '';

$errmsg 	= '';
$successmsg = '';

$TEMPLATE = shell_exec("grep -e '^template=' ../etc/server-software.ini | awk -F= {'print \$2'}");

$settings = array();
$results = $db->query('SELECT name,value FROM settings');
while ($row = $results->fetchArray()) {
    #echo $row["name"].' = '.$row["value"].'<br>';
    $settings_name = $row["name"];
    $settings[$settings_name] = $row["value"];
}
#print_r($settings); exit;
if($settings["dns-provider"]=='cloudflare')
	include_once(__DIR__.'/api_cloudflare.php');
else if($settings["dns-provider"]=='cpanel')
	include_once(__DIR__.'/api_cpanel.php');
else if($settings["dns-provider"]=='powerdns')
	include_once(__DIR__.'/api_powerdns.php');
else
	require_once(__DIR__.'/../modules/api_none.php');
#	$errmsg = 'DNS API '.$settings["dns-provider"].' provider is not (yet) implemented!';

if($user == '')
	$errmsg =  "Error: Username is empty (missing).";

if($errmsg == '') {
    if(in_array($user, array('root', 'reqad', 'test', 'bin', 'daemon', 'adm', 'lp', 'sync', 'shutdown', 'halt', 'mail', 'operator', 'games', 'ftp', 'nobody', 'systemd-network', 'dbus', 'polkitd', 'sshd', 'postfix', 'chrony', 'reqad', 'apache', 'cjdns', 'vnstat', 'postgres', 'redis', 'awx', 'nginx', 'tss'))) {
        $errmsg =  "Error: You cannot delete a system user.";
    } else if(preg_match('/[a-z]+[a-z0-9]{1,7}/', $user)) {
        $results = $db->query('SELECT * FROM accounts WHERE user="'.$user.'"');
        if ($row = $results->fetchArray()) {
			$domain = $row['domain'];
            // user exists, is ok to be deletes
        } else {
			$errmsg =  "Error: Username does not exists in database.";
		}
	} else {
		$errmsg =  "Error: Username should contain only lowercase letters and numbers.";
   }
}

if($errmsg == '') {
    // All ok, delete account
    error_log(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$_SERVER["REMOTE_ADDR"]." ".$_SERVER['USER']." delete account $user\n", 3, '../log/route_log');
	$db->query('DELETE FROM accounts WHERE user="'.$user.'"');
	$db->query('DELETE FROM wordpress WHERE user="'.$user.'"');
	$db->query('DELETE FROM emails WHERE email LIKE "%@'.$domain.'"');
#	$output  = shell_exec('sudo sed -i "s/user  '.$user.';/user  nginx;/" /etc/nginx/nginx.conf');
	if(substr($TEMPLATE,0,7)=='apache_')
    	$output = shell_exec("sudo rm -f /etc/httpd/conf.d/".$domain.".conf 2>&1");
	else
	    $output = shell_exec("sudo rm -f /etc/nginx/conf.d/".$domain.".conf 2>&1");

	error_log('#'.substr($TEMPLATE,0,7).'# '.$output."\n", 3, '../log/debug_log');

	shell_exec("sudo sed -i '/^".$domain.":".$user."$/d' /etc/exim/userdomains");
	shell_exec("sudo rm -f /etc/exim/domains/".$domain);
	shell_exec("sudo rm -f /etc/exim/forwards/".$domain);
	shell_exec("sudo rm -f /etc/exim/keys/".$domain.".private.key");
	shell_exec("sudo rm -f /etc/exim/keys/".$domain.".public.key");
	shell_exec("sudo sed -i '/@".$domain.":/d' /etc/dovecot/users");
	shell_exec("sudo rm -rf /etc/exim/autoreply/".$domain);

	$php_versions = array_map('trim', explode(',', $ini['php_versions']));
	$restart_services = substr($TEMPLATE, 0, 7) == 'apache_' ? 'httpd.service ' : '';
	foreach($php_versions as $phpv) {
		$short_phpversion = str_replace('.', '', $phpv);
		if($phpv == $ini['php'] && is_file('/etc/php-fpm.d/'.$domain.'.conf')) {
			$restart_services .= 'php-fpm.service ';
			$output .= shell_exec("sudo rm -f /etc/php-fpm.d/".$domain.".conf 2>&1");
		} else if(is_file('/etc/opt/remi/php'.$short_phpversion.'/php-fpm.d/'.$domain.'.conf')) {
			$restart_services .= 'php'.$short_phpversion.'-php-fpm.service ';
			$output .= shell_exec("sudo rm -f /etc/opt/remi/php".$short_phpversion."/php-fpm.d/".$domain.".conf 2>&1");
		}
	}

    $output .= shell_exec("sudo rm -f /etc/ssl/certs/".$domain.".key 2>&1");
    $output .= shell_exec("sudo rm -f /etc/ssl/certs/".$domain.".crt 2>&1");
    $output .= shell_exec("sudo rm -rf /home/$user 2>&1");
    $output .= shell_exec("echo Y | sudo certbot --non-interactive delete --cert-name $domain >> ../log/debug_log 2>&1");
	$errmsg = delete_domain_from_dns($domain);
    shell_exec('sudo '.__DIR__.'/../../scripts/restart_services.sh '.trim($restart_services).' 2>/dev/null >/dev/null &');
    shell_exec('sudo '.__DIR__.'/../../scripts/delete_user.sh '.$user.' 2>/dev/null >/dev/null &');
	$output = trim($output);
    if($output != '' && $errmsg=='') {
        $errmsg =  "Error: ".$output;
    }
	// Drop all databases and MySQL users prefixed with this account username.
	// The '_' is escaped as '\_' because in a LIKE pattern a bare '_' matches
	// ANY single character -- 'foo_%' would also match another account's
	// 'food_blog'. '\' is MySQL's default LIKE escape character.
	$mysql_dbs = shell_exec("sudo mysql -N -e \"SHOW DATABASES LIKE '" . $user . "\\_%'\" 2>&1");
	if ($mysql_dbs) {
		foreach (explode("\n", trim($mysql_dbs)) as $mysql_db) {
			if ($mysql_db !== '') shell_exec("sudo mysql -e 'DROP DATABASE IF EXISTS `" . $mysql_db . "`;' 2>&1");
		}
	}
	$mysql_users = shell_exec("sudo mysql -N -e \"SELECT User FROM mysql.user WHERE User LIKE '" . $user . "\\_%'\" 2>&1");
	if ($mysql_users) {
		foreach (explode("\n", trim($mysql_users)) as $mysql_user) {
			if ($mysql_user !== '') shell_exec("sudo mysql -e 'DROP USER IF EXISTS `" . $mysql_user . "`@`localhost`;' 2>&1");
		}
	}
	shell_exec("sudo mysql -e 'FLUSH PRIVILEGES;' 2>&1");
#	else
#	    $errmsg = 'API for '.$settings["dns-provider"].' provider is not implemented!';

}

if($errmsg == '') {
	$successmsg =  "Account $user successfully removed.";
}

/* Post/Redirect/Get: carry the message via the queue and 302 to the GET page
   so a browser refresh does not re-submit the form. */
$msg_base = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/accounts/';
if($errmsg != '')
	msg_redirect($msg_base, $errmsg, 'error');
else
	msg_redirect($msg_base, $successmsg, 'success');

?>
