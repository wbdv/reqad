<?php

function apache_fpm_pool($domain, $user) {
    return '['.$domain.']
user = '.$user.'
group = apache
listen = /run/php-fpm-'.$user.'.sock
listen.owner = '.$user.'
listen.group = apache
listen.allowed_clients = 127.0.0.1

pm = dynamic
pm.max_children = 200
pm.start_servers = 10
pm.min_spare_servers = 5
pm.max_spare_servers = 50
pm.process_idle_timeout = 1s;
pm.max_requests = 1000

ping.path = /ping
slowlog = /var/log/php-fpm/'.$domain.'-slow.log
chdir = /

php_admin_value[disable_functions] = show_source, system, shell_exec, passthru, exec, popen, proc_open
php_admin_value[open_basedir] = /home/'.$user.'
php_admin_value[error_log] = "/home/'.$user.'/logs/'.$domain.'-error.log"
php_admin_flag[log_errors] = on
php_admin_value[sys_temp_dir] = "/home/'.$user.'/tmp"
php_admin_value[upload_tmp_dir] = "/home/'.$user.'/tmp"
php_admin_value[memory_limit] = 2048M
php_value[session.save_handler] = files
php_value[session.save_path] = "/home/'.$user.'/tmp"
php_value[soap.wsdl_cache_dir]  = /var/lib/php/wsdlcache
';
}

function apache_vhost_add_fpm($domain, $user) {
    $file = '/etc/httpd/conf.d/'.$domain.'.conf';
    $content = shell_exec('sudo cat '.escapeshellarg($file));
    if(!$content) return;
    $block = "\n    <FilesMatch \\.php\$>\n        SetHandler \"proxy:unix:/run/php-fpm-".$user.".sock|fcgi://localhost\"\n    </FilesMatch>";
    $pos = strrpos($content, '</VirtualHost>');
    if($pos === false) return;
    $content = substr($content, 0, $pos) . $block . "\n" . substr($content, $pos);
    $tmp = tempnam('/tmp', 'reqad');
    file_put_contents($tmp, $content);
    shell_exec('sudo cp '.escapeshellarg($tmp).' '.escapeshellarg($file));
    unlink($tmp);
}

function apache_vhost_remove_fpm($domain) {
    $file = '/etc/httpd/conf.d/'.$domain.'.conf';
    $content = shell_exec('sudo cat '.escapeshellarg($file));
    if(!$content) return;
    $content = preg_replace('/\n[ \t]*<FilesMatch[^>]*>.*?<\/FilesMatch>/s', '', $content);
    $tmp = tempnam('/tmp', 'reqad');
    file_put_contents($tmp, $content);
    shell_exec('sudo cp '.escapeshellarg($tmp).' '.escapeshellarg($file));
    unlink($tmp);
}

$domain   = trim($_POST["domain"]);
$user     = trim($_POST["user"]);
$password = trim($_POST["password"]);
$new_phpversion_raw = trim($_POST["phpversion"]);
if(strpos($new_phpversion_raw, ':') !== false) {
    [$new_phpversion, $new_handler] = explode(':', $new_phpversion_raw, 2);
} else {
    $new_phpversion = $new_phpversion_raw;
    $new_handler = 'fpm'; // nginx: always fpm, no handler in select value
}
if(isset($_POST["hasemail"]) && $_POST["hasemail"] == 'on')
	$hasemail = true;
else
	$hasemail = false;

$disk_quota = 1024; // MB
if(isset($_POST["disk_quota"]))
	$disk_quota = (int)($_POST["disk_quota"]);
// quota disabled, set 0 (unlimited) so on usage will display entire disk
if($ini["quota"]==0)
	$disk_quota = 0;

$errmsg 	= '';
$successmsg = '';

#echo '<pre>'; print_r($_POST); var_dump($hasemail); exit;

$settings = array();
$results = $db->query('SELECT name,value FROM settings');
while ($row = $results->fetchArray()) {
	#echo $row["name"].' = '.$row["value"].'<br>';
	$settings_name = $row["name"];
	$settings[$settings_name] = $row["value"];
}
#echo "<pre>"; print_r($settings);exit;

if(preg_match('/[a-z0-9]+[a-z0-9\-\.]*[a-z0-9]+\.[a-z]{2,}/', $domain)) {
    $results = $db->query('SELECT * FROM accounts WHERE domain="'.$domain.'"');
    if ( !$results->fetchArray()) {
        $errmsg = "Error: Domain name does not exists on server.";
    }
} else {
    $errmsg =  "Error: Domain name is wrong, please check what you typed.";
}

if($errmsg == '') {
    /* if(in_array($user, array('root', 'reqad', 'test', 'bin', 'daemon', 'adm', 'lp', 'sync', 'shutdown', 'halt', 'mail', 'operator', 'games', 'ftp', 'nobody', 'systemd-network', 'dbus', 'polkitd', 'sshd', 'postfix', 'chrony', 'reqad', 'apache', 'cjdns', 'vnstat', 'postgres', 'redis', 'awx', 'nginx', 'tss'))) {
        $errmsg =  "Error: Username already exists. Please choose a distinct one.";
    } else */
	if(preg_match('/[a-z]+[a-z0-9]{1,7}/', $user)) {
        $results = $db->query('SELECT * FROM accounts WHERE user="'.$user.'"');
        if ($row = $results->fetchArray()) {
        } else {
            $errmsg =  "Error: User does not exists on server.";
        }
    } else {
		$errmsg =  "Error: Username should contain only lowercase letters and numbers.";
	}
}

if($errmsg == '') {
    // TODO check password strength
    if(strlen($password)<8 && strlen($password)>0) {
        $errmsg =  "Error: Password should be at least 8 characters long.";
		if(strpos($password, ':') !== false || strpos($password, '"') !== false || strpos($password, "'") !== false) {
	        $errmsg =  "Error: Password cannot contains : \" or '";
		}
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

if($errmsg == '') {
    // All ok, edit account
    error_log(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$_SERVER["REMOTE_ADDR"]." ".$_SERVER['USER']." edit account $user\n", 3, '../log/route_log');

    // set password
    #$password = str_replace('"', '\"', $password);
    #$password = str_replace("'", "\'", $password);
    if(strlen($password)>0) {
    	$output = shell_exec("echo '$user:$password' | sudo chpasswd");
	}

	// change php version/handler, if needed
	$is_apache = (isset($ini['template']) && substr(trim($ini['template']), 0, 7) == 'apache_');
    $existing_phpversion = $ini['php'];
	$existing_handler = $is_apache ? 'mod_php' : 'fpm';
	$php_versions = array_map('trim', explode(',', $ini['php_versions']));
	foreach($php_versions as $phpv) {
		if($phpv == $ini['php']) continue;
		if(is_file('/etc/opt/remi/php'.str_replace('.', '', $phpv).'/php-fpm.d/'.$domain.'.conf')) {
    		$existing_phpversion = $phpv;
			$existing_handler = 'fpm';
			break;
		}
	}
	// Check if default PHP version is running as fpm (apache only)
	if($is_apache && $existing_phpversion == $ini['php'] && is_file('/etc/php-fpm.d/'.$domain.'.conf'))
		$existing_handler = 'fpm';

	$version_changed = ($new_phpversion != $existing_phpversion);
	$handler_changed = ($new_handler != $existing_handler);

	if($version_changed || $handler_changed) {
		$restart_services = '';

		if($is_apache) {
			$new_short = str_replace('.', '', $new_phpversion);
			$old_short = str_replace('.', '', $existing_phpversion);
			$existing_is_default = ($existing_phpversion == $ini['php']);
			$new_is_default      = ($new_phpversion == $ini['php']);

			// Determine pool file paths (null = mod_php, no pool)
			if($existing_is_default && $existing_handler == 'fpm')
				$old_pool = '/etc/php-fpm.d/'.$domain.'.conf';
			elseif(!$existing_is_default)
				$old_pool = '/etc/opt/remi/php'.$old_short.'/php-fpm.d/'.$domain.'.conf';
			else
				$old_pool = null;

			if($new_is_default && $new_handler == 'fpm')
				$new_pool = '/etc/php-fpm.d/'.$domain.'.conf';
			elseif(!$new_is_default)
				$new_pool = '/etc/opt/remi/php'.$new_short.'/php-fpm.d/'.$domain.'.conf';
			else
				$new_pool = null;

			// Update SetHandler in vhost when switching between mod_php and fpm
			if($existing_handler == 'mod_php' && $new_handler == 'fpm')
				apache_vhost_add_fpm($domain, $user);
			elseif($existing_handler == 'fpm' && $new_handler == 'mod_php')
				apache_vhost_remove_fpm($domain);

			// Create, move, or delete pool file
			if($old_pool === null && $new_pool !== null) {
				// mod_php → fpm: create pool
				$pool_content = apache_fpm_pool($domain, $user);
				$tmp = tempnam('/tmp', 'reqad');
				file_put_contents($tmp, $pool_content);
				shell_exec('sudo cp '.escapeshellarg($tmp).' '.escapeshellarg($new_pool));
				unlink($tmp);
			} elseif($old_pool !== null && $new_pool === null) {
				// fpm → mod_php: delete pool
				shell_exec('sudo rm -f '.escapeshellarg($old_pool));
			} elseif($old_pool !== null && $new_pool !== null && $old_pool !== $new_pool) {
				// fpm → different version fpm: move pool (socket path unchanged)
				shell_exec('sudo mv '.escapeshellarg($old_pool).' '.escapeshellarg(dirname($new_pool).'/'));
			}

			// Restart affected fpm services
			if($old_pool !== null) {
				$restart_services .= ($existing_is_default ? 'php-fpm.service' : 'php'.$old_short.'-php-fpm.service').' ';
			}
			if($new_pool !== null && $new_pool !== $old_pool) {
				$restart_services .= ($new_is_default ? 'php-fpm.service' : 'php'.$new_short.'-php-fpm.service').' ';
			}
			// httpd restart whenever vhost or pool changed
			$restart_services .= 'httpd.service';

		} else {
			// nginx: move pool file between version directories (always fpm)
			if($existing_phpversion == $ini['php']) {
				$move_from = '/etc/php-fpm.d/'.$domain.'.conf';
				$restart_services = 'php-fpm.service ';
			} else {
				$short_phpversion = str_replace('.', '', $existing_phpversion);
				$move_from = '/etc/opt/remi/php'.$short_phpversion.'/php-fpm.d/'.$domain.'.conf';
				$restart_services = 'php'.$short_phpversion.'-php-fpm.service ';
			}
			if($new_phpversion == $ini['php']) {
				$move_to = '/etc/php-fpm.d/';
				$restart_services .= 'php-fpm.service';
			} else {
				$short_phpversion = str_replace('.', '', $new_phpversion);
				$move_to = '/etc/opt/remi/php'.$short_phpversion.'/php-fpm.d/';
				$restart_services .= 'php'.$short_phpversion.'-php-fpm.service';
			}
			shell_exec('sudo mv '.escapeshellarg($move_from).' '.escapeshellarg($move_to));
		}

		shell_exec(__DIR__.'/../../scripts/restart_services.sh '.trim($restart_services).' 2>/dev/null >/dev/null &');

		// Update ~/.bashrc CLI PHP path only when version changes
		if($version_changed) {
			$bashrc = '/home/'.$user.'/.bashrc';
			$new_short_php = str_replace('.', '', $new_phpversion);
			shell_exec('sudo sed -i \'/^export PATH="\/opt\/remi\/php/d\' '.escapeshellarg($bashrc).' 2>/dev/null');
			if($new_phpversion != $ini['php']) {
				$path_export = 'export PATH="/opt/remi/php'.$new_short_php.'/root/usr/bin/:$PATH"';
				shell_exec('echo '.escapeshellarg($path_export).' | sudo tee -a '.escapeshellarg($bashrc).' > /dev/null');
			}
		}
	}

	// enable or disable email
	if(isset($ini["email"]) && $ini["email"]==1) {

		// no email for subdomains
		if($settings["dns-provider"]=='cloudflare' && $hasemail==true) {
			if(main_domain($domain)!='') {
				$hasemail=false;
				$errmsg='Error: Cannot enable email for a subdomain on Cloudflare.';
			}
		}

		if( $row["has_email"]==false && $hasemail==true ) {
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
			#print_r($domains); exit;
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
			$output = shell_exec("dig +short MX $domain $nameserver");
			if(trim($output)=='')
				add_update_local_mx($domain);
			// TODO if remote MX, change to local
			add_update_dkim($domain);
			$output = trim(shell_exec("dig +short TXT $domain $nameserver | grep 'v=spf1'"));
			if($output=='')
				add_update_spf($domain);
			$output = trim(shell_exec("dig +short TXT _dmarc.$domain $nameserver"));
			if($output=='')
				add_update_dmarc($domain);	
			
			$results = $db->query('UPDATE accounts SET has_email=1 WHERE user="'.$user.'"');
			// Update Dovecot and exim SNI config
			shell_exec('sudo /usr/local/reqad/scripts/update_email_sni >> /usr/local/reqad/log/debug_log 2>&1 &');
			log_debug("[edit_account.php] enable mail for $domain $user");
		} else if( $row["has_email"]==true && $hasemail==false ) {
			// disable email
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
			if($domains[$user]==$domain) {
				shell_exec('sudo sed -i \'/^'.$domain.':'.$user.'$/d\' /etc/exim/userdomains');
			} else {
				$errmsg = 'This user / domain combination does not exists in etc &gt; exim &gt; userdomains';
			}

			$results = $db->query('UPDATE accounts SET has_email=0 WHERE user="'.$user.'"');
			// Update Dovecot and exim SNI config
			shell_exec('sudo /usr/local/reqad/scripts/update_email_sni >> /usr/local/reqad/log/debug_log 2>&1 &');
			log_debug("[edit_account.php] disable mail for $domain $user");
		}

		/* mail.<domain> vhost server_name + cert coverage depends on email
		   state; when it changed, rewrite the vhost and (if Let's Encrypt)
		   re-issue the certificate to add/remove mail.<domain>. */
		$email_changed = ( ($row["has_email"]==false && $hasemail==true) || ($row["has_email"]==true && $hasemail==false) );
		if($email_changed) {
			apply_account_vhost_names($db, $domain, $is_apache);
			reissue_letsencrypt_cert($db, $domain, $hasemail);
		}
	}
}

if($errmsg == '') {
	$successmsg =  "Account $user successfully updated.";
}

/* Post/Redirect/Get: carry the message via the queue and 302 to the GET page
   so a browser refresh does not re-submit the form. */
$msg_base = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/accounts/';
if($errmsg != '')
	msg_redirect($msg_base, $errmsg, 'error');
else
	msg_redirect($msg_base, $successmsg, 'success');

?>
