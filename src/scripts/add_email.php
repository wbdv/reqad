<?php

include_once(__DIR__.'/../public_html/defines.php');

#$api_query = 'https://'.$api_server.':2087/json-api/adddns?api.version=1&domain='.$domain.'&ip='.$server_ip;

#$domain = 'test.ro';
#$user 	= 'test1';

error_log("add_email.php $domain $user ".__FILE__."\n",  3, __DIR__.'/../log/debug_log');

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
	shell_exec('touch /etc/exim/domains/'.$domain);
	// TODO generate keys & DNS 
}

echo "\n";
exit;

?>
