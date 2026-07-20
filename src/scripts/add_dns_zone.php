<?php

include_once(__DIR__.'/../public_html/defines.php');

if(php_sapi_name() == "cli" && isset($argv[1]) && $argv[1]!='') {
	$domain = $argv[1];
}

$server_ip = trim(shell_exec("/usr/sbin/ip address show | grep 'scope global' | grep 'inet ' | head -n 1 | awk {'print \$2'} | awk -F/ {'print \$1'}"));
$settings = array();
$results = $db->query('SELECT name,value FROM settings');
while ($row = $results->fetchArray()) {
    #echo $row["name"].' = '.$row["value"].'<br>';
    $settings_name = $row["name"];
    $settings[$settings_name] = $row["value"];
}

#print_r($settings); exit;

if($settings["dns-provider"]=='cpanel') {

$api_user   = $settings["cpanel-username"];
$api_token  = $settings["cpanel-api-token"];
$api_server = $settings["cpanel-server"];

#$api_query = 'https://'.$api_server.':2087/json-api/adddns?api.version=1&domain='.$domain.'&ip='.$server_ip.'&trueowner='.$api_trueowner;
$api_query = 'https://'.$api_server.':2087/json-api/adddns?api.version=1&domain='.$domain.'&ip='.$server_ip;

function cpanel_whm_api($api_query) {
	global $api_user, $api_token;
    $curl = curl_init();

    curl_setopt($curl, CURLOPT_VERBOSE, 1);
    curl_setopt($curl, CURLOPT_STDERR, fopen('php://stdout', 'w'));

    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST,0);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER,0);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER,1);

    $header[0] = "Authorization: whm $api_user:$api_token";
    curl_setopt($curl, CURLOPT_HTTPHEADER,$header);
    curl_setopt($curl, CURLOPT_URL, $api_query);

    $result = curl_exec($curl);
    $http_status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

	$json = json_decode($result);
	#print_r($json->{'metadata'});
	if($http_status!=200 && isset($json->{'metadata'}))
		$json->{'metadata'}->{'reason'} .= 'HTTP code '.$http_status;
	curl_close($curl);
	return $json;
}

if(!isset($domain) || $domain=='') {
	$errmsg = 'cPanel API Add DNS: Domain not defined.';	
    if(php_sapi_name() == "cli") {
        echo $errmsg."\n";
        exit;
    }
} else {
	error_log("add_dns_zone.php $domain $server_ip ".__FILE__."\n",  3, __DIR__.'/../log/debug_log');

	$json = cpanel_whm_api($api_query);
	$result = (int)($json->{'metadata'}->{'result'});

	if($result==0) {
		$errmsg = 'Error: DNS API: '.trim($json->{'metadata'}->{'reason'}).'.';
	}

	error_log("add_dns_zone.php Result: ".trim($json->{'metadata'}->{'reason'})." ".__FILE__."\n",  3, __DIR__.'/../log/debug_log');

	if(php_sapi_name() == "cli") {
		if($result==1) {
			echo 'Domain '.$domain.' successfully added in DNS.'."\n";
		} else {
			echo $errmsg."\n";
		}
		exit;
	}
}

} else {
	$errmsg = 'Adding DNS zone on '.$settings["dns-provider"].' provider is not implemented!';
	if(php_sapi_name() == "cli") 
		echo $errmsg."\n";
}
?>
