<?php
$errmsg 	= '';
$successmsg = '';
$fw_note    = '';

#echo '<pre>'; print_r($_POST); exit;

if(!in_array($_POST["dns-provider"], array('cloudflare', 'cpanel', 'powerdns', ''))) {
	$errmsg = 'Unknown DNS provider.';
}

if($errmsg == '') {
	setting_put('dns-provider', $_POST["dns-provider"]);
}

if($_POST["dns-provider"]=='cloudflare') {
#	$db->query('DELETE FROM settings WHERE name = "cloudflare-api-token" OR  name = "cloudflare-zone-id" OR  name = "cloudflare-account-id"');
	setting_put('cloudflare-api-token', $_POST["cloudflare-api-token"]);
#	$db->query('INSERT INTO settings VALUES ("cloudflare-zone-id", "'.$_POST["cloudflare-zone-id"].'", datetime())');
#	$db->query('INSERT INTO settings VALUES ("cloudflare-account-id", "'.$_POST["cloudflare-account-id"].'", datetime())');

	if(isset($_POST['cloudflare-test']) && $_POST['cloudflare-test']==1) {
		$cf_json = trim(shell_exec('curl -s https://api.cloudflare.com/client/v4/zones --header '.escapeshellarg('Authorization: Bearer '.$_POST["cloudflare-api-token"]).' --header "Content-Type: application/json"'));
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
	setting_put('cpanel-api-token',    $_POST["cpanel-api-token"]);
	setting_put('cpanel-server',       $_POST["cpanel-server"]);
	setting_put('cpanel-username',     $_POST["cpanel-username"]);
	setting_put('cpanel-insecure-tls', isset($_POST['cpanel-insecure-tls']) ? '1' : '0');
	if(isset($_POST['cpanel-test']) && $_POST['cpanel-test']==1) {
		#$cp_json = trim(shell_exec('curl -s https://'.$_POST["cpanel-server"].':2087/json-api/listzones?api.version=1 --header "Authorization: whm '.$_POST["cpanel-username"].':'.$_POST["cpanel-api-token"].'"'));
		$cp_insecure = isset($_POST['cpanel-insecure-tls']) ? '-k ' : '';
		$http_code = trim(shell_exec('curl -s '.$cp_insecure.'-o /dev/null -w "%{http_code}" '.escapeshellarg('https://'.$_POST["cpanel-server"].':2087/json-api/listzones?api.version=1').' --header '.escapeshellarg('Authorization: whm '.$_POST["cpanel-username"].':'.$_POST["cpanel-api-token"])));
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
	/* Hidden-master keys are still cleared when leaving that mode, so a later
	   switch back does not silently reuse a stale agent URL/token. */
	$db->query('DELETE FROM settings WHERE name = "powerdns-ns1" OR name = "powerdns-ns2" OR name = "powerdns-agent-url" OR name = "powerdns-agent-token"');
	setting_put('powerdns-server',       $_POST["powerdns-server"]);
	setting_put('powerdns-api-key',      $_POST["powerdns-api-key"]);
	setting_put('powerdns-mode',         $_POST["powerdns-mode"]);
	setting_put('powerdns-insecure-tls', isset($_POST['powerdns-insecure-tls']) ? '1' : '0');
	/* the zones' name servers, in either mode (new zones name them; DNS Zones checks them) */
	foreach(array('powerdns-ns1', 'powerdns-ns2') as $nsk) {
		$nsv = strtolower(rtrim(trim((string)($_POST[$nsk] ?? '')), '.'));
		if($nsv !== '' && !valid_domain($nsv))
			$errmsg = 'The name server '.$nsv.' is not a host name, such as ns1.example.com.';
		else
			setting_put($nsk, $nsv);
	}
	/* A DNS server on another port (another Reqad server's DNS API is
	   https://<host>:2087/pdns): csf drops outgoing traffic to ports missing
	   from TCP_OUT, which looks like a DNS server that does not answer, so
	   the port is opened here (no-op when it already is, or without csf). */
	$pdns_u = parse_url(trim((string)$_POST["powerdns-server"]));
	$pdns_port = isset($pdns_u['port']) ? (int)$pdns_u['port'] : 0;
	if($pdns_port > 0 && !in_array($pdns_port, array(80, 443), true)
	   && !in_array(strtolower(trim((string)($pdns_u['host'] ?? ''), '[]')), array('', 'localhost', '127.0.0.1', '::1'), true)) {
		$fw = csf_open_tcp_out($pdns_port);
		if($fw === 'updated')
			$fw_note = ' Outgoing port '.$pdns_port.' opened in the csf firewall.';
		elseif($fw === 'error')
			$fw_note = ' Outgoing port '.$pdns_port.' could not be opened in the csf firewall (TCP_OUT in /etc/csf/csf.conf).';
		if($fw_note !== '')
			route_log('dns settings: csf TCP_OUT '.$pdns_port.' '.$fw);
	}
	if($_POST["powerdns-mode"]=='hidden-master') {
		setting_put('powerdns-agent-url',   $_POST["powerdns-agent-url"]);
		setting_put('powerdns-agent-token', $_POST["powerdns-agent-token"]);
	}
	if(isset($_POST['powerdns-test']) && $_POST['powerdns-test']==1) {
		#$pdns_json = trim(shell_exec('curl -s '.$_POST["powerdns-server"].'/api/v1/servers/localhost --header "X-API-Key: '.$_POST["powerdns-api-key"].'"'));
		$pdns_insecure = isset($_POST['powerdns-insecure-tls']) ? '-k ' : '';
		$pdns_json = trim(shell_exec('curl -s '.$pdns_insecure.escapeshellarg(rtrim($_POST["powerdns-server"], '/').'/api/v1/servers/localhost').' --header '.escapeshellarg('X-API-Key: '.$_POST["powerdns-api-key"])));
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
    #error_log(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$_SERVER["REMOTE_ADDR"]." ".auth_user()." create forwarder $email -> $forward\n", 3, '../log/route_log');
	#shell_exec('echo "'.$user.": ".$forward.'" | sudo tee --append /etc/exim/forwards/'.$domain);
	$successmsg =  "DNS Settings saved.";
}
if($fw_note !== '') {
	if($errmsg != '')
		$errmsg .= $fw_note;
	else
		$successmsg .= $fw_note;
}
?>
