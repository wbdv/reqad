<?php
$errmsg 	= '';
$successmsg = '';

#echo '<pre>'; print_r($_POST); exit;

if(!in_array($_POST["dns-provider"], array('cloudflare', 'cpanel', 'powerdns', ''))) {
	$errmsg = 'Unknown DNS provider.';
}

if($errmsg == '') {
	$results = $db->query('SELECT * FROM settings WHERE name="dns-provider"');
	if ($results->fetchArray()) {
		$db->query('UPDATE settings SET value="'.$_POST["dns-provider"].'", updated_at=datetime("now") WHERE name="dns-provider"');
	} else {
		$db->query('INSERT INTO settings VALUES ("dns-provider", "'.$_POST["dns-provider"].'", datetime("now"))');
	}
}

if($_POST["dns-provider"]=='cloudflare') {
#	$db->query('DELETE FROM settings WHERE name = "cloudflare-api-token" OR  name = "cloudflare-zone-id" OR  name = "cloudflare-account-id"');
	$db->query('DELETE FROM settings WHERE name = "cloudflare-api-token"');
	$db->query('INSERT INTO settings VALUES ("cloudflare-api-token", "'.$_POST["cloudflare-api-token"].'", datetime())');
#	$db->query('INSERT INTO settings VALUES ("cloudflare-zone-id", "'.$_POST["cloudflare-zone-id"].'", datetime())');
#	$db->query('INSERT INTO settings VALUES ("cloudflare-account-id", "'.$_POST["cloudflare-account-id"].'", datetime())');

	if(isset($_POST['cloudflare-test']) && $_POST['cloudflare-test']==1) {
		$cf_json = trim(shell_exec('curl -s https://api.cloudflare.com/client/v4/zones --header "Authorization: Bearer '.$_POST["cloudflare-api-token"].'" --header "Content-Type: application/json"'));
#		echo '<pre>'; print_r($cf_json); exit;
		$cf = @json_decode($cf_json, true);
#		echo '<pre>'; print_r($cf); exit;
		if(json_last_error() === JSON_ERROR_NONE) {
			if($cf['success'] == 1)
				$successmsg = 'DNS Settings saved. Clouldflare connection verified.';
			else
				$errmsg = 'Cloudflare error: '.$cf['errors']["0"]["message"].' ('.$cf['errors']["0"]["code"].')';
		} else {
			$errmsg = 'Cloudflare API answer - JSON parse error: '.json_last_error_msg();
		}
	}
}


if($_POST["dns-provider"]=='cpanel') {
	$db->query('DELETE FROM settings WHERE name = "cpanel-api-token" OR  name = "cpanel-server" OR  name = "cpanel-username"');
	$db->query('INSERT INTO settings VALUES ("cpanel-api-token", "'.$_POST["cpanel-api-token"].'", datetime())');
	$db->query('INSERT INTO settings VALUES ("cpanel-server", "'.$_POST["cpanel-server"].'", datetime())');
	$db->query('INSERT INTO settings VALUES ("cpanel-username", "'.$_POST["cpanel-username"].'", datetime())');
	if(isset($_POST['cpanel-test']) && $_POST['cpanel-test']==1) {
		#$cp_json = trim(shell_exec('curl -s https://'.$_POST["cpanel-server"].':2087/json-api/listzones?api.version=1 --header "Authorization: whm '.$_POST["cpanel-username"].':'.$_POST["cpanel-api-token"].'"'));
		$http_code = trim(shell_exec('curl -s -o /dev/null -w "%{http_code}" https://'.$_POST["cpanel-server"].':2087/json-api/listzones?api.version=1 --header "Authorization: whm '.$_POST["cpanel-username"].':'.$_POST["cpanel-api-token"].'"'));
		if($http_code == '200' || $http_code == '403') {
			// 200 = root access, 403 = reseller (authenticated OK, endpoint root-only — expected)
			$successmsg = 'DNS Settings saved. cPanel connection verified.';
		} elseif($http_code == '401') {
			$errmsg = 'cPanel error: invalid credentials (HTTP 401) — check username and API token.';
		} elseif($http_code == '000') {
			$errmsg = 'cPanel error: server unreachable — check server address and port 2087.';
		} else {
			$errmsg = 'cPanel error: unexpected HTTP '.$http_code;
		}
		#} else {
		#	$errmsg = 'cPanel API answer - JSON parse error: '.json_last_error_msg();
		#}
	}
}

if($_POST["dns-provider"]=='powerdns') {
	$db->query('DELETE FROM settings WHERE name = "powerdns-api-key" OR name = "powerdns-server" OR name = "powerdns-mode" OR name = "powerdns-ns1" OR name = "powerdns-ns2" OR name = "powerdns-agent-url" OR name = "powerdns-agent-token"');
	$db->query('INSERT INTO settings VALUES ("powerdns-server", "'.$_POST["powerdns-server"].'", datetime())');
	$db->query('INSERT INTO settings VALUES ("powerdns-api-key", "'.$_POST["powerdns-api-key"].'", datetime())');
	$db->query('INSERT INTO settings VALUES ("powerdns-mode", "'.$_POST["powerdns-mode"].'", datetime())');
	if($_POST["powerdns-mode"]=='hidden-master') {
		$db->query('INSERT INTO settings VALUES ("powerdns-ns1", "'.$_POST["powerdns-ns1"].'", datetime())');
		$db->query('INSERT INTO settings VALUES ("powerdns-ns2", "'.$_POST["powerdns-ns2"].'", datetime())');
		$db->query('INSERT INTO settings VALUES ("powerdns-agent-url", "'.$_POST["powerdns-agent-url"].'", datetime())');
		$db->query('INSERT INTO settings VALUES ("powerdns-agent-token", "'.$_POST["powerdns-agent-token"].'", datetime())');
	}
	if(isset($_POST['powerdns-test']) && $_POST['powerdns-test']==1) {
		#$pdns_json = trim(shell_exec('curl -s '.$_POST["powerdns-server"].'/api/v1/servers/localhost --header "X-API-Key: '.$_POST["powerdns-api-key"].'"'));
		$pdns_json = trim(shell_exec('curl -s '.$_POST["powerdns-server"].'/api/v1/servers/localhost --header "X-API-Key: '.$_POST["powerdns-api-key"].'"'));
		#echo '<pre>'; print_r($pdns_json); exit;
		$pdns = @json_decode($pdns_json, true);
		if(json_last_error() === JSON_ERROR_NONE && $pdns_json!='Unauthorized') {
			if($pdns['url'] == '/api/v1/servers/localhost')
				$successmsg = 'DNS Settings saved. PowerDNS connection verified.';
			else
				$errmsg = 'PowerDNS error: '.$pdns_json;
		} else {
			if($pdns_json=='Unauthorized')
				$errmsg = 'PowerDNS API answer: '.$pdns_json;
			else
				$errmsg = 'PowerDNS API answer - JSON parse error: '.json_last_error_msg();
		}
	}
}


if($errmsg == '' && $successmsg == '') {
    // All ok
    #error_log(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$_SERVER["REMOTE_ADDR"]." ".$_SERVER['USER']." create forwarder $email -> $forward\n", 3, '../log/route_log');
	#shell_exec('echo "'.$user.": ".$forward.'" | sudo tee --append /etc/exim/forwards/'.$domain);
	$successmsg =  "DNS Settings saved.";
}
?>
