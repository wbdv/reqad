<?php
$domain   	 = trim($_POST["domain"]);
$user     	 = trim($_POST["user"]);
$password 	 = trim($_POST["password"]);
$adddns   	 = isset($_POST["adddns"])?($_POST["adddns"]=='on'?true:false):false;
$letsencrypt = isset($_POST["letsencrypt"])?($_POST["letsencrypt"]=='on'?true:false):false;
$has_email 	 = isset($_POST["email"])?($_POST["email"]=='on'?true:false):false;
$create_www  = isset($_POST["www_alias"])?($_POST["www_alias"]=='on'?true:false):false;

$server_ip = trim(shell_exec("/usr/sbin/ip address show | grep 'scope global' | grep 'inet ' | head -n 1 | awk {'print \$2'} | awk -F/ {'print \$1'}"));
$settings = array();
$results = $db->query('SELECT name,value FROM settings');
while ($row = $results->fetchArray()) {
    #echo $row["name"].' = '.$row["value"].'<br>';
    $settings_name = $row["name"];
    $settings[$settings_name] = $row["value"];
}
#print_r($settings); exit;

// quota disabled, set 0 (unlimited) so on usage will display entire disk
$disk_quota = 0;
if(isset($_POST["disk_quota"]))
	$disk_quota = (int)($_POST["disk_quota"]);
if($ini["quota"]==0)
	$disk_quota = 0;

$errmsg 	= '';
$successmsg = '';
#echo '<pre>'; print_r($_POST); exit;

if(preg_match('/[a-z0-9]+[a-z0-9\-\.]*[a-z0-9]+\.[a-z]{2,}/', $domain)) {
    $results = $db->query('SELECT * FROM accounts WHERE domain="'.$domain.'"');
    if ($row = $results->fetchArray()) {
        $errmsg = "Error: Domain name already exists on this server, assigned to user ".$row["user"].".";
    }
} else {
    $errmsg =  "Error: Domain name is wrong, please check what you typed.";
}

if($errmsg == '') {
    if(in_array($user, array('root', 'reqad', 'test', 'bin', 'daemon', 'adm', 'lp', 'sync', 'shutdown', 'halt', 'mail', 'operator', 'games', 'ftp', 'nobody', 'systemd-network', 'dbus', 'polkitd', 'sshd', 'postfix', 'chrony', 'reqad', 'apache', 'cjdns', 'vnstat', 'postgres', 'redis', 'awx', 'nginx', 'tss'))) {
        $errmsg =  "Error: Username already exists. Please choose a distinct one.";
    } else if(preg_match('/[a-z]+[a-z0-9]{1,7}/', $user)) {
        $results = $db->query('SELECT * FROM accounts WHERE user="'.$user.'"');
        if ($row = $results->fetchArray()) {
            $errmsg =  "Error: Username already exists (UID=".$row["id"]."). Please choose a different one.";
        } else {
            $USER_EXISTS = `(id $user) > /dev/null 2>&1; echo $?`;
            if((int)($USER_EXISTS) == 0) {
                $errmsg =  "Error: Username already exists on server. Please choose a different one.";
            }
        }
    } else {
		$errmsg =  "Error: Username should contain only lowercase letters and numbers.";
	}
}

if($errmsg == '') {
    // TODO check password strength
    if(strlen($password)<8) {
        $errmsg =  "Error: Password should be at least 8 characters long.";
    }
    if(strpos($password, ':') !== false || strpos($password, '"') !== false || strpos($password, "'") !== false) {
        $errmsg =  "Error: Password cannot contains : \" or '";
    }
}

if($settings["dns-provider"]=='cloudflare')
	include_once(__DIR__.'/api_cloudflare.php');
else if($settings["dns-provider"]=='cpanel')
	include_once(__DIR__.'/api_cpanel.php');
else if($settings["dns-provider"]=='powerdns')
	include_once(__DIR__.'/api_powerdns.php');
else
	require_once(__DIR__.'/../modules/api_none.php');
#	$errmsg = 'DNS API '.$settings["dns-provider"].' provider is not (yet) implemented!';

if($errmsg == '' && $adddns) {
	if($errmsg == '') {
		$server_ip = trim(shell_exec("/usr/sbin/ip address show | grep 'scope global' | grep 'inet ' | head -n 1 | awk {'print \$2'} | awk -F/ {'print \$1'}"));
		$errmsg = add_domain_in_dns($domain, $server_ip);
	}
}

if($errmsg == '') {
    // All ok, create account. FInally!
    error_log(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$_SERVER["REMOTE_ADDR"]." ".$_SERVER['USER']." create account $user\n", 3, '../log/route_log');
    #$output = shell_exec("/usr/local/reqad/scripts/create_user.sh $user");
    $output = shell_exec("sudo useradd $user 2>&1");
	$uid = `id -u $user`;
	$uid = (int)($uid);
	if($uid>=1000) {
		// set password
		#$password = str_replace('"', '\"', $password);
		#$password = str_replace("'", "\'", $password);
		shell_exec("echo '$user:$password' | sudo chpasswd");
		#    if(!is_null($output)) {
		#        $errmsg =  "Error: ".$output;
		#   } else {

		if($settings["dns-provider"]=='cloudflare') {
			if(main_domain($domain)!='')
				$has_email = false;
		}

    	error_log(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$_SERVER["REMOTE_ADDR"]." ".$_SERVER['USER'].'INSERT INTO accounts VALUES ('.$uid.', "'.$user.'", "'.$domain.'", 0, '.$disk_quota.', '.($has_email?'true':'false').', active, datetime("now"))'."\n", 3, '../log/route_log');
        $db->query('INSERT INTO accounts VALUES ('.$uid.', "'.$user.'", "'.$domain.'", 0, '.$disk_quota.', '.($has_email?'true':'false').', "active", datetime("now"), \'default\')');

		/* Seed the www alias when requested, and pass the alias list to the vhost
		   template so the initial server_name matches (empty = no www). */
		$account_aliases = array();
		if($create_www) {
			$account_aliases[] = 'www.'.$domain;
			$db->query('INSERT INTO aliases (account_id, alias, is_wildcard, ssl_status, created_at) VALUES ('.$uid.', "www.'.$db->escapeString($domain).'", 0, "none", datetime("now"))');
		}

		/* Pre-allocate a message-queue token so the backgrounded Let's Encrypt job
		   (launched inside the template) can post its result to messages.db ~1 min
		   later; the accounts list polls this token and shows a toast when done. */
		$sslmsgtoken = $letsencrypt ? bin2hex(random_bytes(8)) : '';
		require_once(__DIR__.'/../../scripts/templates/'.$ini["template"].'.php');
		#$errmsg = shell_exec("sudo /usr/bin/php ".__DIR__."/../../scripts/templates/".$ini["template"].".php 2>&1");

		if($has_email === true) {
			#require_once(__DIR__.'/../../scripts/add_email.php');
			// enable email
			$output  = shell_exec("sudo cat /etc/exim/userdomains");
			$output  = explode("\n", $output);
			$domains = array();
			foreach($output as $output_line) {
				if($output_line!='') {
					$output2 = explode(':', $output_line);
					#print_r($output2);
					$user2 = trim($output2[1]);
					if($user!='') {
						$domains[$user2] = trim($output2[0]);
					}
				}
			}
			#print_r($domains);
			if( !in_array($domain, $domains) && !array_key_exists($user, $domains) ) {
				shell_exec('echo \''.$domain.':'.$user.'\' | sudo tee --append /etc/exim/userdomains');
				shell_exec('sudo chown exim:exim /etc/exim/userdomains');
				shell_exec('sudo chmod a+r,u+w /etc/exim/userdomains');
				shell_exec('sudo touch /etc/exim/domains/'.$domain);
				shell_exec('sudo chown exim:exim /etc/exim/domains/'.$domain);
			}
			// Ensure catch-all :fail: entry exists in forwards file
			shell_exec('sudo grep -q \'^\\*:\' /etc/exim/forwards/'.$domain.' 2>/dev/null || echo \'*: :fail: No Such User Here\' | sudo tee --append /etc/exim/forwards/'.$domain.' > /dev/null');
			shell_exec('sudo chown exim:exim /etc/exim/forwards/'.$domain.' 2>/dev/null');
			if( !is_file('/etc/exim/keys/'.$domain.'.private.key') || !is_file('/etc/exim/keys/'.$domain.'.public.key') ) {
				shell_exec('sudo rm -f /etc/exim/keys/'.$domain.'.private.key /etc/exim/keys/'.$domain.'.public.key');
				shell_exec('sudo openssl genrsa -out /etc/exim/keys/'.$domain.'.private.key 2048');
				shell_exec('sudo openssl rsa -in /etc/exim/keys/'.$domain.'.private.key -out /etc/exim/keys/'.$domain.'.public.key -pubout -outform PEM');
				shell_exec('sudo chown exim:mail /etc/exim/keys/'.$domain.'.private.key /etc/exim/keys/'.$domain.'.public.key');
				shell_exec('sudo chmod g+r /etc/exim/keys/'.$domain.'.private.key /etc/exim/keys/'.$domain.'.public.key');
				if( is_file('/etc/exim/keys/'.$domain.'.private.key') && is_file('/etc/exim/keys/'.$domain.'.public.key') ) {
					$output = shell_exec("sudo cat /etc/exim/keys/".$domain.".public.key | sed 's/-----BEGIN PUBLIC KEY-----/v=DKIM1;t=s;p=/' | sed 's/-----END PUBLIC KEY-----/;/' | tr -d '\n' && echo");				
					#echo '<pre>'.wordwrap($output, 256, "\n", true);					
					#exit;				
				} else {
					$errmsg = 'Cannot generate the pair of keys for DKIM.';
				}
			}


			$output = trim(shell_exec("dig +short NS $domain | head -n 1 | sed 's/\.$//'"));
			if($output=='') 
				$nameserver = '';
			else
				$nameserver = '@'.$output;
#			$output = shell_exec("dig +short MX $domain $nameserver");
#			if(trim($output)=='')
			add_update_local_mx($domain);
			add_update_dkim($domain);
			$output = trim(shell_exec("dig +short TXT $domain $nameserver | grep 'v=spf1'"));
			if($output=='')
				add_update_spf($domain);
			$output = trim(shell_exec("dig +short TXT _dmarc.$domain $nameserver"));
			if($output=='')
				add_update_dmarc($domain);		
		} 
    } else {
		$errmsg = "User $user does not exists (cannot be created).";
	}
}

if($errmsg == '') {
	$successmsg =  "Account $user successfully created.";
	if($letsencrypt)
		$successmsg .= " Please wait one minute until Let's Encrypt Certificate will be installed.";
}

/* Post/Redirect/Get: carry the message via the queue and 302 to the GET page
   so a browser refresh does not re-submit the form. */
$msg_base = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/accounts/';
/* If a Let's Encrypt cert job was actually launched (nginx_php-fpm.php only
   backgrounds certbot when the domain resolves to this server, otherwise it
   sets $errmsg), pass the queue token so the accounts list can poll messages.db
   and show a toast when the background job posts its result. */
if($letsencrypt && $errmsg == '' && $sslmsgtoken != '')
	$msg_base .= '?sslmsg='.$sslmsgtoken;
if($errmsg != '')
	msg_redirect($msg_base, $errmsg, 'error');
else
	msg_redirect($msg_base, $successmsg, 'success');

?>
