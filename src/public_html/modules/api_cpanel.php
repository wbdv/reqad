<?
$api_user   = $settings["cpanel-username"];
$api_token  = $settings["cpanel-api-token"];
$api_server = $settings["cpanel-server"];
$dns_provider_name = 'cPanel';

function cpanel_whm_api($api_query, $parse_to_array = false, $method = 'GET', $data = '') {
    global $api_user, $api_token, $_debug;
    $curl = curl_init();

    /* curl protocol trace — only when debugging (was unconditional → dumped into
       page output / CLI stdout on every call) */
    if(!empty($_debug)) {
        curl_setopt($curl, CURLOPT_VERBOSE, 1);
        curl_setopt($curl, CURLOPT_STDERR, fopen(_PATH.'/log/debug_log', 'a'));
    }

    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST,0);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER,0);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER,1);

    $header[0] = "Authorization: whm $api_user:$api_token";
    curl_setopt($curl, CURLOPT_HTTPHEADER,$header);
    curl_setopt($curl, CURLOPT_URL, $api_query);

	if($method=='POST') {
		curl_setopt($curl, CURLOPT_POST, true);
		if($data!='')
			curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
#			curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query(unserialize($data)));
	}


    $result = curl_exec($curl);
    $http_status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

    $json = json_decode($result, $parse_to_array);

	#log_debug("[cpanel_whm_api] $api_query // ".var_export($json, true));
	log_debug("[cpanel_whm_api] http_status:$http_status // query:$api_query data:$data");
	if(isset($json->{'metadata'})) {
		$metadata = $json->{'metadata'};
		log_debug("[cpanel_whm_api] http_status:$http_status // ".var_export($metadata, true));
	}
    #print_r($json->{'metadata'});
    if($http_status!=200 && isset($json->{'metadata'}))
        $json->{'metadata'}->{'reason'} .= 'HTTP code '.$http_status;
    curl_close($curl);
    return $json;
}

function get_nameservers() {
    global $api_server;
	$api_query = 'https://'.$api_server.':2087/json-api/get_nameserver_config?api.version=1';
    $json = cpanel_whm_api($api_query);
    $result = (int)($json->{'metadata'}->{'result'});
	#echo '<pre>'; print_r($json);exit;
	#print_r($json->data);exit;
    if($result==0) {
        return 'Error: DNS API: '.trim($json->{'metadata'}->{'reason'}).'.';
    } else 
		return implode(', ', $json->data->nameservers);
}

function add_domain_in_dns($domain, $server_ip) {
    global $api_server, $api_user;
	$api_query = 'https://'.$api_server.':2087/json-api/adddns?api.version=1&domain='.urlencode($domain).'&ip='.urlencode($server_ip).'&trueowner='.urlencode($api_user);
    $json = cpanel_whm_api($api_query);
    $result = (int)($json->{'metadata'}->{'result'});
    if($result==0) {
        return 'Error: DNS API: '.trim($json->{'metadata'}->{'reason'}).'.';
    } else 
		return '';
}

function delete_domain_from_dns($domain) {
    global $api_server;
	log_debug("delete_domain_from_dns $domain");
	$api_query = 'https://'.$api_server.':2087/json-api/killdns?api.version=1&domain='.$domain;
    $json = cpanel_whm_api($api_query);
    $result = (int)($json->{'metadata'}->{'result'});
    if($result==0) {
        return 'Error: DNS API: '.trim($json->{'metadata'}->{'reason'}).'.';
    } else 
		return '';
}

/* Alias domains: create the A record (or wildcard A) for an alias.
   Subdomain/wildcard of a managed zone -> add to that zone; separate domain ->
   create a new zone via adddns and add the record. Mirrors the mass_edit_dns_zone
   add/edit pattern used by add_update_dkim/spf here. dname is relative to the zone. */
function add_alias_in_dns($alias, $server_ip) {
	global $api_server;
	$alias   = strtolower(trim($alias));
	$is_wild = (substr($alias, 0, 2) === '*.');
	$bare    = $is_wild ? substr($alias, 2) : $alias;

	$zones = get_zones();
	if(!is_array($zones)) return 'Error: DNS API: cannot list zones.';
	$zone = '';
	foreach($zones as $zname => $z) {
		if($bare === $zname || substr('.'.$bare, -strlen('.'.$zname)) === '.'.$zname) {
			if(strlen($zname) > strlen($zone)) $zone = $zname;
		}
	}
	if($zone === '') {
		$parts   = explode('.', $bare);
		$newzone = (count($parts) >= 2) ? implode('.', array_slice($parts, -2)) : $bare;
		$err = add_domain_in_dns($newzone, $server_ip);
		if($err !== '' && $err !== null) return $err;
		$zone = $newzone;
		if($alias === $zone) return '';   // apex A created by adddns
	}

	/* dname is relative to the zone apex ('*' for wildcard, label(s) otherwise). */
	$dname  = ($alias === $zone) ? '@' : substr($alias, 0, strlen($alias) - strlen($zone) - 1);
	$serial = get_zone_serial($zone);
	if($serial === '') return 'Error: DNS API: cannot read zone '.$zone.'.';

	$line = get_zone_record($zone, $alias.'.', 'A');
	if($line == -1)
		$rec = '{"dname":"'.$dname.'", "ttl":14400, "record_type":"A", "data":["'.$server_ip.'"]}';
	else
		$rec = '{"dname":"'.$dname.'", "ttl":14400, "record_type":"A", "line_index":'.$line.', "data":["'.$server_ip.'"]}';
	$key = ($line == -1) ? 'add' : 'edit';
	$api_query = 'https://'.$api_server.':2087/json-api/mass_edit_dns_zone?api.version=1&zone='.$zone.'&serial='.$serial.'&'.$key.'='.rawurlencode($rec);
	$json = cpanel_whm_api($api_query, true);
	if(isset($json['metadata']['result']) && (int)$json['metadata']['result'] == 0)
		return 'Error: DNS API: '.trim($json['metadata']['reason'] ?? 'alias record failed').'.';
	return '';
}

/* Remove an alias A record (best-effort; never deletes a zone or the apex). */
function delete_alias_from_dns($alias) {
	global $api_server;
	$alias   = strtolower(trim($alias));
	$is_wild = (substr($alias, 0, 2) === '*.');
	$bare    = $is_wild ? substr($alias, 2) : $alias;

	$zones = get_zones();
	if(!is_array($zones)) return '';
	$zone = '';
	foreach($zones as $zname => $z) {
		if($bare === $zname || substr('.'.$bare, -strlen('.'.$zname)) === '.'.$zname) {
			if(strlen($zname) > strlen($zone)) $zone = $zname;
		}
	}
	if($zone === '' || $alias === $zone) return '';
	$serial = get_zone_serial($zone);
	if($serial === '') return '';
	$line = get_zone_record($zone, $alias.'.', 'A');
	if($line == -1) return '';
	$api_query = 'https://'.$api_server.':2087/json-api/mass_edit_dns_zone?api.version=1&zone='.$zone.'&serial='.$serial.'&remove='.$line;
	cpanel_whm_api($api_query, true);
	return '';
}

/* ---- ACME dns-01 TXT helpers (used by certbot_dns_hook.php) --------------
   Always ADD a fresh TXT record so the wildcard and apex challenge values can
   coexist at _acme-challenge.<domain>; cleanup removes the matching value. */
function add_update_txt($name, $value) {
	global $api_server;
	$name = strtolower(rtrim(trim($name), '.'));
	$val  = trim($value, '"');
	$zones = get_zones();
	if(!is_array($zones)) return 'Error: DNS API: cannot list zones.';
	$zone = '';
	foreach($zones as $zname => $z) {
		if($name === $zname || substr('.'.$name, -strlen('.'.$zname)) === '.'.$zname) {
			if(strlen($zname) > strlen($zone)) $zone = $zname;
		}
	}
	if($zone === '') return 'Error: DNS API: no managed zone for '.$name.'.';
	$serial = get_zone_serial($zone);
	if($serial === '') return 'Error: DNS API: cannot read zone '.$zone.'.';
	$dname = ($name === $zone) ? '@' : substr($name, 0, strlen($name) - strlen($zone) - 1);
	$add = '{"dname":"'.$dname.'", "ttl":60, "record_type":"TXT", "data":["'.addslashes($val).'"]}';
	$api_query = 'https://'.$api_server.':2087/json-api/mass_edit_dns_zone?api.version=1&zone='.$zone.'&serial='.$serial.'&add='.rawurlencode($add);
	$json = cpanel_whm_api($api_query, true);
	if(isset($json['metadata']['result']) && (int)$json['metadata']['result'] == 0)
		return 'Error: DNS API: '.trim($json['metadata']['reason'] ?? 'TXT record failed').'.';
	return '';
}

/* Remove the TXT value matching $value (or the first TXT when $value==''). */
function delete_txt($name, $value = '') {
	global $api_server;
	$name = strtolower(rtrim(trim($name), '.'));
	$val  = trim($value, '"');
	$zones = get_zones();
	if(!is_array($zones)) return '';
	$zone = '';
	foreach($zones as $zname => $z) {
		if($name === $zname || substr('.'.$name, -strlen('.'.$zname)) === '.'.$zname) {
			if(strlen($zname) > strlen($zone)) $zone = $zname;
		}
	}
	if($zone === '') return '';
	$serial = get_zone_serial($zone);
	if($serial === '') return '';
	$line = get_zone_record($zone, $name.'.', 'TXT', $val);
	if($line == -1) return '';
	$api_query = 'https://'.$api_server.':2087/json-api/mass_edit_dns_zone?api.version=1&zone='.$zone.'&serial='.$serial.'&remove='.$line;
	cpanel_whm_api($api_query, true);
	return '';
}

function get_zone_serial($domain) {
    global $api_server;
	$api_query = 'https://'.$api_server.':2087/json-api/dumpzone?api.version=1&zone='.$domain;
	$json = cpanel_whm_api($api_query, true);
	$serial='';
	if(is_array($json['data']['zone'][0]['record'])) {
		foreach($json['data']['zone'][0]['record'] as $i => $k) {
			if($k['type']=='SOA')
				$serial = $k['serial'];
		}
	}
	log_debug("[cpanel_whm_api] get_zone_serial:$serial");
	return $serial;
}

function get_zone_record($domain, $name, $type, $start = '') {
    global $api_server;
	$api_query = 'https://'.$api_server.':2087/json-api/dumpzone?api.version=1&zone='.$domain;
	$json = cpanel_whm_api($api_query, true);
	#echo "<pre>"; print_r($json);echo "</pre>";exit;
	if($type == 'MX')
		log_debug("get_zone_record name: [MX] // ".var_export($json, true));
	$line = -1;
	if(is_array($json['data']['zone'][0]['record']))
	foreach($json['data']['zone'][0]['record'] as $i => $k) {
		if($k['type']==$type && $k['class']=='IN' && $k['name']==$name) {
			if($start == '' ) {
				if($type == 'MX')
					$line = (int)($k['Line']);
				else
					$line = (int)($i);
			} else if($type == 'TXT' && substr($k['txtdata'],0, strlen($start))==$start) {
				$line = (int)($i);
				#log_debug("get_zone_record name: $name domain: $domain type: $type $start - found on line $line");
			}
			log_debug("get_zone_record name: $name domain: $domain type: $type $start - found on line $line // Line: ".$k['Line']);
		}
	}
	if($line == -1)
		log_debug("get_zone_record name: $name domain: $domain type: $type $start - not found");
	
	if($type=='MX')
		log_debug("get_zone_record name: $line");
	return $line;
}

function add_update_dkim($domain) {
    global $api_server;
	log_debug("add_update_dkim $domain");
	$serial = get_zone_serial($domain);
	$output = trim(shell_exec("sudo cat /etc/exim/keys/".$domain.".public.key | sed 's/-----BEGIN PUBLIC KEY-----//' | sed 's/-----END PUBLIC KEY-----//' | tr -d '\n' && echo"));
	if($output!='' && $serial!='')	{
		$dkim = 'v=DKIM1; k=rsa; p='.$output.';';
		log_debug("serial: $serial dkim: $dkim");
		$dkim1 = substr($dkim,0,255);
		$dkim2 = substr($dkim,255,255);
		$line = get_zone_record($domain, 'default._domainkey.'.$domain.'.', 'TXT');
		#$line = get_zone_record($domain, 'default._domainkey', 'TXT');
		#echo "<pre>"; print_r($dkim1); echo "\n\n"; print_r($dkim2); echo $line; echo "</pre>";
		if($line == -1) {
			// add record
			$add = '{"dname":"default._domainkey", "ttl":14400, "record_type":"TXT", "data":["'.$dkim1.'", "'.$dkim2.'"]}';
			$api_query = 'https://'.$api_server.':2087/json-api/mass_edit_dns_zone?api.version=1&zone='.$domain.'&serial='.$serial.'&add='.rawurlencode($add);
		} else {
			// edit record
			$edit = '{"dname":"default._domainkey", "ttl":14400, "record_type":"TXT", "line_index":'.$line.', "data":["'.$dkim1.'", "'.$dkim2.'"]}';
			$api_query = 'https://'.$api_server.':2087/json-api/mass_edit_dns_zone?api.version=1&zone='.$domain.'&serial='.$serial.'&edit='.rawurlencode($edit);
		}
		$json = cpanel_whm_api($api_query, true);
		#echo "<pre>"; print_r($json);echo "</pre>";
	}
}

function add_update_spf($domain, $spf = 'v=spf1 +a +mx ~all') {
    global $api_server;
	log_debug("add_update_spf $domain");
	$serial = get_zone_serial($domain);
	$line = get_zone_record($domain, $domain.'.', 'TXT', 'v=spf1');
	if($line == -1) {
		// add record
		$add = '{"dname":"'.$domain.'.", "ttl":14400, "record_type":"TXT", "data":["'.addslashes($spf).'"]}';
		log_debug("add_update_spf add $add");
		$api_query = 'https://'.$api_server.':2087/json-api/mass_edit_dns_zone?api.version=1&zone='.$domain.'&serial='.$serial.'&add='.rawurlencode($add);
	} else {
		// edit record
		$edit = '{"dname":"'.$domain.'.", "ttl":14400, "record_type":"TXT", "line_index":'.$line.', "data":["'.addslashes($spf).'"]}';
		log_debug("add_update_spf update $edit");
		$api_query = 'https://'.$api_server.':2087/json-api/mass_edit_dns_zone?api.version=1&zone='.$domain.'&serial='.$serial.'&edit='.rawurlencode($edit);
	}
	$json = cpanel_whm_api($api_query, true);
	#echo "<pre>"; print_r($json);echo "</pre>";
}

function add_update_dmarc($domain, $dmarc = 'v=DMARC1;p=none;sp=none;adkim=r;aspf=r;pct=100;fo=0;rf=afrf;ri=86400') {
    global $api_server;
	log_debug("add_update_dmarc $domain");
	$serial = get_zone_serial($domain);
	$line = get_zone_record($domain, '_dmarc.'.$domain.'.', 'TXT', 'v=DMARC1');
	#$line = get_zone_record($domain, '_dmarc', 'TXT', 'v=DMARC1');
	if($line == -1) {
		// add record
		#$add = '{"dname":"_dmarc.'.$domain.'.", "ttl":14400, "record_type":"TXT", "data":["'.addslashes($dmarc).'"]}';
		$add = '{"dname":"_dmarc", "ttl":14400, "record_type":"TXT", "data":["'.addslashes($dmarc).'"]}';
		log_debug("add_update_dmarc add $add");
		$api_query = 'https://'.$api_server.':2087/json-api/mass_edit_dns_zone?api.version=1&zone='.$domain.'&serial='.$serial.'&add='.rawurlencode($add);
	} else {
		// edit record
		#$edit = '{"dname":"'.$domain.'.", "ttl":14400, "record_type":"TXT", "line_index":'.$line.', "data":["'.addslashes($dmarc).'"]}';
		$edit = '{"dname":"_dmarc", "ttl":14400, "record_type":"TXT", "line_index":'.$line.', "data":["'.addslashes($dmarc).'"]}';
		log_debug("add_update_dmarc update $edit");
		$api_query = 'https://'.$api_server.':2087/json-api/mass_edit_dns_zone?api.version=1&zone='.$domain.'&serial='.$serial.'&edit='.rawurlencode($edit);
	}
	$json = cpanel_whm_api($api_query, true);
	#echo "<pre>"; print_r($json);echo "</pre>";
}

function add_update_local_mx($domain) {
    global $api_server;
	log_debug("add_update_local_mx $domain");
	$serial = get_zone_serial($domain);
	#$line = get_zone_record($domain, '_dmarc.'.$domain.'.', 'TXT', 'v=DMARC1');
	$line = get_zone_record($domain, $domain.'.', 'MX');
	log_debug("[cpanel-api] get_zone_record $domain // $line");
	$i=3; // avoid loop
	while($line>0 && $i>0) {
		$line--; // strange, cpanel can't decidehow to count, from 1 or from 0.
		log_debug("[cpanel-api] [$i] add_update_local_mx MX found on line: $line");
		#$serial = get_zone_serial($domain);
		$serial = get_zone_serial($domain);
		$api_query = 'https://'.$api_server.':2087/json-api/mass_edit_dns_zone?api.version=1&zone='.$domain.'&serial='.$serial.'&remove='.$line;
		#$api_query = 'https://'.$api_server.':2087/json-api/mass_edit_dns_zone';
		$json = cpanel_whm_api($api_query, true);
		#$json = cpanel_whm_api($api_query, true, 'POST', '{"api.version":"1", "serial": "'.$serial.'", "domain": "'.$domain.'", "remove":"'.$line.'"}');
		log_debug("[add_update_local_mx] remove line $line \n\t $api_query // ".var_export($json, true));
		$line = get_zone_record($domain, $domain.'.', 'MX');
		$i--;
	}

	$serial = get_zone_serial($domain);
	$add = '{"dname":"'.$domain.'.", "ttl":14400, "record_type":"MX", "data":["0", "'.$domain.'"]}';
	log_debug("add_update_local_mx add $add");
	$api_query = 'https://'.$api_server.':2087/json-api/mass_edit_dns_zone?api.version=1&zone='.$domain.'&serial='.$serial.'&add='.rawurlencode($add);
	$json = cpanel_whm_api($api_query, true);
	#echo "<pre>"; print_r($json);echo "</pre>"; exit;
}


/* debug
$domain = '';
echo '<h1>Testing add_update_local_mx for '.$domain.'</h1><pre>';
#$serial = get_zone_serial($domain);
#echo 'Serial: '.$serial;
add_update_local_mx($domain);
exit;
*/

function get_zones() {
    global $api_server;
	log_debug("[cpanel_api] get_zones");
	$zones = array();
	$api_query = 'https://'.$api_server.':2087/json-api/listzones';
	$json = cpanel_whm_api($api_query, true);
	#echo "<pre>"; print_r($json);echo "</pre>"; exit;

	if(isset($json['zone']))
		foreach($json['zone'] as $k => $zone) {
			$domain = $zone['domain'];
			if(!in_array($domain, $zones)) {
				//$serial = get_zone_serial($domain);
				$serial = '';
				$zones[$domain]['serial'] = $serial;
			}
		}

	return $zones;
}

function get_zone_records($domain) {
    global $api_server;
	$api_query = 'https://'.$api_server.':2087/json-api/dumpzone?api.version=1&zone='.$domain;
	$json = cpanel_whm_api($api_query, true);
	#echo "<pre>"; print_r($json['data']['zone'][0]['record']);echo "</pre>";exit;
	#echo '<table class="table table-vcenter">';
	echo '<table class="table table-vcenter table-mobile-md card-table" border="1" cellspacing="0" cellpadding="4" style="border:1px solid #CCC">';
	echo '<tr><th>Name</th><th>Type</th><th>TTL</th><th>Value</th></tr>';
	foreach($json['data']['zone'][0]['record'] as $k => $v) {
		if(!isset($v['name']) || $v['type']=='SOA')
			continue;
		$name = str_replace('.'.$domain.'.', '', $v['name']);
		if($name == $domain.'.')
			$name = '@';
		echo '<tr><td>'.$name.'</td><td>'.$v['type'].'</td><td>'.$v['ttl'].'</td><td>';
		switch($v['type']) {
			case 'A':		echo $v['address']; break;
			case 'NS':		echo $v['nsdname']; break;
			case 'MX':		echo $v['preference'].' '.$v['exchange']; break;
			case 'CNAME':	echo $v['cname']; break;
			case 'TXT':
				echo '<span style="font-family:monospace;" title="'.htmlspecialchars($v['txtdata']).'">'.substr($v['txtdata'], 0, 80).'</span>';
				if(strlen($v['txtdata'])>80)
					echo ' ...';
				break;
		}
		echo '</td></tr>';
	}
	echo '</table>';
}

/* ─── Backup / restore data API (normalized records) ─────────────────────────
   export_zone_records() returns a plain array (unlike get_zone_records() which
   echoes HTML); import_zone_records() re-creates them via mass_edit_dns_zone.
   NOTE: not runtime-tested on this server (no cPanel provider configured here) —
   implemented to the dumpzone/mass_edit shapes already used in this file. */
function export_zone_records($domain) {
	global $api_server;
	$out  = array();
	$json = cpanel_whm_api('https://'.$api_server.':2087/json-api/dumpzone?api.version=1&zone='.$domain, true);
	if(!isset($json['data']['zone'][0]['record']) || !is_array($json['data']['zone'][0]['record']))
		return $out;
	foreach($json['data']['zone'][0]['record'] as $v) {
		if(!isset($v['type']) || !isset($v['name'])) continue;
		$type = $v['type'];
		if($type == 'SOA' || $type == 'NS') continue;   // cPanel manages these
		$name = rtrim($v['name'], '.');
		if($name == $domain)
			$name = '@';
		else
			$name = preg_replace('/\.'.preg_quote($domain, '/').'$/', '', $name);
		$priority = null; $content = '';
		switch($type) {
			case 'A': case 'AAAA': $content = $v['address']  ?? ''; break;
			case 'CNAME':          $content = $v['cname']    ?? ''; break;
			case 'PTR':            $content = $v['ptrdname'] ?? ''; break;
			case 'MX':             $content = $v['exchange'] ?? ''; $priority = isset($v['preference']) ? (int)$v['preference'] : 0; break;
			case 'TXT':            $content = $v['txtdata']  ?? ''; break;
			case 'SRV':            $content = ($v['weight'] ?? 0).' '.($v['port'] ?? 0).' '.($v['target'] ?? ''); $priority = isset($v['priority']) ? (int)$v['priority'] : 0; break;
			case 'CAA':            $content = ($v['flag'] ?? 0).' '.($v['tag'] ?? '').' '.($v['value'] ?? ''); break;
			default:               $content = $v['record'] ?? ''; break;
		}
		$out[] = array('name' => $name, 'type' => $type, 'ttl' => (int)($v['ttl'] ?? 14400), 'content' => $content, 'priority' => $priority, 'proxied' => false);
	}
	return $out;
}

function import_zone_records($domain, $records, &$error = null) {
	global $api_server, $api_user;
	/* ensure the zone exists (create it if the account/zone was deleted) */
	$zones = get_zones();
	if(!array_key_exists($domain, $zones)) {
		$server_ip = trim(shell_exec("/usr/sbin/ip address show | grep 'scope global' | grep 'inet ' | head -n 1 | awk {'print \$2'} | awk -F/ {'print \$1'}"));
		$err = add_domain_in_dns($domain, $server_ip);
		if($err != '') { $error = $err; return false; }
	}
	/* Add-only import (cPanel's mass_edit line-index replace is fragile — see
	   [[reference_cpanel_mass_edit_line_index]]); assumes a fresh zone (DR). */
	foreach($records as $r) {
		$type = strtoupper(trim($r['type']));
		if($type == 'SOA' || $type == 'NS') continue;
		$dname = ($r['name'] === '@' || $r['name'] === '') ? $domain.'.' : $r['name'].'.'.$domain.'.';
		switch($type) {
			case 'CNAME': case 'PTR': $data = array(rtrim($r['content'], '.').'.'); break;
			case 'MX':                $data = array((int)($r['priority'] ?? 0), rtrim($r['content'], '.').'.'); break;
			case 'SRV':               $p = explode(' ', $r['content'], 3); $data = array((int)($r['priority'] ?? 0), (int)($p[0] ?? 0), (int)($p[1] ?? 0), rtrim($p[2] ?? '', '.').'.'); break;
			case 'CAA':               $data = explode(' ', $r['content'], 3); break;
			default:                  $data = array($r['content']); break;   // A/AAAA/TXT/…
		}
		$serial    = get_zone_serial($domain);
		$add       = json_encode(array('dname' => $dname, 'ttl' => (int)($r['ttl'] ?? 14400), 'record_type' => $type, 'data' => $data));
		$api_query = 'https://'.$api_server.':2087/json-api/mass_edit_dns_zone?api.version=1&zone='.urlencode($domain).'&serial='.$serial.'&add='.rawurlencode($add);
		$json      = cpanel_whm_api($api_query, true);
		$result    = isset($json['metadata']['result']) ? (int)$json['metadata']['result'] : 0;
		if($result == 0)
			$error = 'cPanel: '.trim($json['metadata']['reason'] ?? 'add record failed').' ('.$dname.' '.$type.')';
	}
	return true;
}
