<?
$api_token   = $settings["cloudflare-api-token"];
#$zone_id  	 = $settings["cloudflare-zone-id"];
#$account_id  = $settings["cloudflare-account-id"];
$dns_provider_name = 'Cloudflare';

function cloudflare_api($api_query, $parse_to_array = false, $method = 'GET', $data = '') {
    global $api_token, $_debug;
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

    $header[0] = "Authorization: Bearer $api_token";
    $header[1] = "Content-Type: application/json";
    curl_setopt($curl, CURLOPT_HTTPHEADER,$header);
    curl_setopt($curl, CURLOPT_URL, $api_query);

	if($method=='POST' || $method=='PATCH') {
		curl_setopt($curl, CURLOPT_POST, true);
		if($data!='')
			curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
	}

	if($method=='DELETE') {
	    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'DELETE');
	}

	if($method=='PATCH') {
	    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PATCH');
	}

    $result = curl_exec($curl);
    $http_status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

    $json = json_decode($result, $parse_to_array);
    #print_r($json->{'metadata'});
    #if($http_status!=200 && isset($json->{'metadata'}))
    #   $json->{'metadata'}->{'reason'} .= 'HTTP code '.$http_status;
    curl_close($curl);
    return $json;
}

function get_nameservers() {
	#	$json = cloudflare_api('https://api.cloudflare.com/client/v4/zones');
	#	return $json->result->{'0'}->name_servers;
}

function get_zones() {
	$zones = array();
	log_debug("[api_cloudflare] get_zones START");
	$json = cloudflare_api('https://api.cloudflare.com/client/v4/zones', true);
	if($json["success"]==1) {
		foreach($json["result"] as $k => $v) {
			$domain = $v["name"];
			$zones[$domain] = array( 
					"zone_id" => $v["id"], 
					"serial" => $v["id"], 
					"status" => $v["status"], 
					"nameservers" => implode(', ', $v["name_servers"])
				);
		}
	}
	#echo '<pre>'; print_r($json); echo "---------------------\n";	print_r($zones); echo '</pre>'; exit;
	log_debug("[api_cloudflare] get_zones END");
	return $zones;
}

function create_dns_records($zone_id, $domain, $server_ip) {
	log_debug("create_dns_records $zone_id, $domain, $server_ip");
	$json = cloudflare_api('https://api.cloudflare.com/client/v4/zones/'.$zone_id.'/dns_records', true, 'POST', '{ "comment": "", "content": "'.$server_ip.'", "name": "'.$domain.'", "proxied": false, "type": "A" }');
	# echo '<pre>https://api.cloudflare.com/client/v4/zones/'.$zone_id.'/dns_records'."\n"; print_r($json); echo '</pre>'; exit;
	if($json["success"]==1)
		return '';
	else
		return $json["errors"][0]["message"].' (code: '.$json["errors"][0]["code"].')';
}

/* ─── Backup / restore data API (normalized records) ─────────────────────────
   export_zone_records() returns a plain array (unlike get_zone_records() which
   echoes HTML); import_zone_records() re-creates them. Normalized shape:
     { name:'@'|'www'|…, type, ttl, content, priority(int|null), proxied(bool) } */
function export_zone_records($domain) {
	$out = array();
	$zones = get_zones();
	if(!is_array($zones) || !array_key_exists($domain, $zones)) return $out;
	$json = cloudflare_api('https://api.cloudflare.com/client/v4/zones/'.$zones[$domain]["zone_id"].'/dns_records?per_page=1000', true, 'GET');
	if(($json["success"] ?? 0) != 1 || !isset($json["result"])) return $out;
	foreach($json["result"] as $k) {
		$name = $k["name"];
		if($name == $domain)
			$name = '@';
		else
			$name = preg_replace('/\.'.preg_quote($domain, '/').'$/', '', $name);
		$out[] = array(
			'name'     => $name,
			'type'     => $k["type"],
			'ttl'      => (int)$k["ttl"],
			'content'  => $k["content"],
			'priority' => isset($k["priority"]) ? (int)$k["priority"] : null,
			'proxied'  => !empty($k["proxied"]),
		);
	}
	return $out;
}

function import_zone_records($domain, $records, &$error = null) {
	$zones = get_zones();
	if(!is_array($zones) || !array_key_exists($domain, $zones)) {
		$error = "zone $domain not found on Cloudflare";
		return false;
	}
	$zone_id = $zones[$domain]["zone_id"];
	$base    = 'https://api.cloudflare.com/client/v4/zones/'.$zone_id.'/dns_records';

	/* Replace semantics: delete existing manageable (non-SOA/NS) records first, so a
	   restore is idempotent and doesn't collide with defaults. */
	$existing = cloudflare_api($base.'?per_page=1000', true, 'GET');
	if(($existing["success"] ?? 0) == 1 && isset($existing["result"])) {
		foreach($existing["result"] as $r) {
			if(in_array($r["type"], array('SOA', 'NS'))) continue;
			cloudflare_api($base.'/'.$r["id"], true, 'DELETE');
		}
	}

	foreach($records as $r) {
		$t = strtoupper(trim($r['type']));
		if($t == 'SOA' || $t == 'NS') continue;   // Cloudflare manages these
		$name = ($r['name'] === '@' || $r['name'] === '') ? $domain : $r['name'].'.'.$domain;
		$rec  = array(
			'type'    => $t,
			'name'    => $name,
			'content' => $r['content'],
			'ttl'     => (int)($r['ttl'] ?? 1),
			'proxied' => !empty($r['proxied']),
		);
		if(isset($r['priority']) && $r['priority'] !== null && ($t == 'MX' || $t == 'SRV'))
			$rec['priority'] = (int)$r['priority'];
		$json = cloudflare_api($base, true, 'POST', json_encode($rec));
		if(($json["success"] ?? 0) != 1)
			$error = 'Cloudflare: '.($json["errors"][0]["message"] ?? 'add record failed').' ('.$name.' '.$t.')';
	}
	/* return true even if some records errored; $error carries the last problem */
	return true;
}

function get_zone_record($domain, $name, $type, $start='') {
	$zones = get_zones();
	#echo '<pre>'; print_r($zones); echo '</pre>';
	$line = '';
	log_debug("get_zone_record name: $name domain: $domain type: $type $start");
	if(array_key_exists($domain, $zones)) {
		#echo('https://api.cloudflare.com/client/v4/zones/'.$zones[$domain]["zone_id"].'/dns_records<br>');
		$json = cloudflare_api('https://api.cloudflare.com/client/v4/zones/'.$zones[$domain]["zone_id"].'/dns_records', true, 'GET');
		#echo '<pre>'; print_r($json); echo '</pre>'; #exit;
		if($json["success"]==0) {
			#return 'Error: DNS API: '.$json["errors"][0]["message"].' (code: '.$json["errors"][0]["code"].')';
			return '';
		} else  {
			foreach($json['result'] as $i => $k) {
				if($line!='') 
					continue;
				#echo $i.' '.$k["id"].' '.$k["name"].' '.$k["type"].$type.' '.$k["content"]."<br>";
				#echo "#".substr($k['content'],0, strlen($start)).'# - #'.$start.'# ';
				#echo ((substr($k['content'],0, strlen($start))==$start)?' - OK':'')."<br>";
				if($k['type']==$type && $k['name']==$name) {
					log_debug("get_zone_record name: $name domain: $domain type: $type $start");
					if($start=='') {
						$line = $k['id'];
					} else if($type == 'TXT' && substr($k['content'],0, strlen($start))==$start) {
						#echo 'FOUND!';
						$line = $k['id'];
					}
					log_debug("get_zone_record name: $name domain: $domain type: $type $start - found on line $line");
				}
			}
		}
	}
	if($line == '')
		log_debug("get_zone_record name: $name domain: $domain type: $type $start - not found");
	return $line;
}

function main_domain($domain) {
	log_debug("main_domain $domain");
	$zones = get_zones();
	# echo '<pre>'; print_r($zones); echo '</pre>';
	$sd = explode('.', $domain);
	$sc = count($sd);
	if($sc==1)
		return '';
	// a domain can only be a subdomain of a parent zone that has FEWER labels,
	// so require strictly more labels than the candidate parent (else an apex
	// like reqad.eu / reqad.co.uk matches itself and is misread as a subdomain)
	else if($sc>2 && array_key_exists($sd[$sc-2].'.'.$sd[$sc-1], $zones)) {
		log_debug("main_domain? subdomain of ".$sd[$sc-2].'.'.$sd[$sc-1]);
		return $sd[$sc-2].'.'.$sd[$sc-1];
	} else if($sc>3 && array_key_exists($sd[$sc-3].'.'.$sd[$sc-2].'.'.$sd[$sc-1], $zones)) {
		log_debug("main_domain? subdomain of ".$sd[$sc-3].'.'.$sd[$sc-2].'.'.$sd[$sc-1]);
		return $sd[$sc-3].'.'.$sd[$sc-2].'.'.$sd[$sc-1];
	}
	return '';
}

/* Alias domains: create the A record (or wildcard A) for an alias.
   Subdomain/wildcard of a managed zone -> add to that zone; separate domain ->
   create a new zone (registrable domain = last two labels) and add the record.
   Mirrors the create/patch pattern used by add_update_spf/dkim here. */
function add_alias_in_dns($alias, $server_ip) {
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
		$zone  = $newzone;
		$zones = get_zones();
		if(!is_array($zones) || !array_key_exists($zone, $zones))
			return 'Error: DNS API: new zone '.$zone.' not found after create.';
		if($alias === $zone) return '';   // apex A already created
	}

	$payload = '{ "comment": "", "content": "'.$server_ip.'", "name": "'.$alias.'", "proxied": false, "type": "A", "ttl": 3600 }';
	$rid = get_zone_record($zone, $alias, 'A');
	if($rid !== '')
		$json = cloudflare_api('https://api.cloudflare.com/client/v4/zones/'.$zones[$zone]["zone_id"].'/dns_records/'.$rid, true, 'PATCH', $payload);
	else
		$json = cloudflare_api('https://api.cloudflare.com/client/v4/zones/'.$zones[$zone]["zone_id"].'/dns_records', true, 'POST', $payload);
	if(isset($json["success"]) && $json["success"] == 0)
		return 'Error: DNS API: '.($json["errors"][0]["message"] ?? 'alias record failed').'.';
	return '';
}

/* Remove an alias A record (best-effort; never deletes a zone or the apex). */
function delete_alias_from_dns($alias) {
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
	$rid = get_zone_record($zone, $alias, 'A');
	if($rid !== '')
		cloudflare_api('https://api.cloudflare.com/client/v4/zones/'.$zones[$zone]["zone_id"].'/dns_records/'.$rid, true, 'DELETE');
	return '';
}

/* ---- ACME dns-01 TXT helpers (used by certbot_dns_hook.php) --------------
   Cloudflare stores each TXT value as its own record, so appending is just a
   POST; the wildcard and apex challenge values coexist naturally. */
function add_update_txt($name, $value) {
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
	$payload = '{ "comment": "ACME", "content": "'.addslashes($val).'", "name": "'.$name.'", "proxied": false, "type": "TXT", "ttl": 60 }';
	$json = cloudflare_api('https://api.cloudflare.com/client/v4/zones/'.$zones[$zone]["zone_id"].'/dns_records', true, 'POST', $payload);
	if(isset($json["success"]) && $json["success"] == 0)
		return 'Error: DNS API: '.($json["errors"][0]["message"] ?? 'TXT record failed').'.';
	return '';
}

/* Remove a TXT value (or every TXT at the name when $value==''). */
function delete_txt($name, $value = '') {
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
	$q = 'https://api.cloudflare.com/client/v4/zones/'.$zones[$zone]["zone_id"].'/dns_records?type=TXT&name='.rawurlencode($name);
	$json = cloudflare_api($q, true, 'GET');
	if(isset($json["result"]) && is_array($json["result"]))
		foreach($json["result"] as $rec)
			if($value === '' || (isset($rec["content"]) && trim($rec["content"], '"') === $val))
				cloudflare_api('https://api.cloudflare.com/client/v4/zones/'.$zones[$zone]["zone_id"].'/dns_records/'.$rec["id"], true, 'DELETE');
	return '';
}

function add_domain_in_dns($domain, $server_ip) {
	log_debug("add_domain_in_dns $domain");
	$zones = get_zones();
	# echo '<pre>'; print_r($zones); echo '</pre>'; 
	if(!array_key_exists($domain, $zones)) {
		$main_domain = main_domain($domain);
		if($main_domain=='') {
			$json = cloudflare_api('https://api.cloudflare.com/client/v4/zones', true, 'POST', '{ "name": "'.$domain.'" }');
			if($json["success"]==1) {
				$zone_id = $json["result"]["id"];
				$error = create_dns_records($zone_id, $domain, $server_ip);
				if($error=='') {
					#return "Domain added in Cloudflare DNS. Please set nameservers to ". implode(', ', $json["result"]["name_servers"]);
					return '';
				} else {
					#return "Error: Domain added in Cloudflare DNS. Please set nameservers to ". implode(', ', $json["result"]["name_servers"]). "\n". $error;
					return '';
				}
			} else {		
				return 'Error: DNS API: '.$json["errors"][0]["message"].' (code: '.$json["errors"][0]["code"].')';
			}
		} else {
			//create_dns_records($zones[$main_domain]["zone_id"], $domain, $server_ip);
			$zone_id=$zones[$main_domain]["zone_id"];
			log_debug("add_subdomain_in_dns $domain -> $main_domain");
			$json = cloudflare_api('https://api.cloudflare.com/client/v4/zones/'.$zone_id.'/dns_records', true, 'POST', '{ "comment": "", "content": "'.$server_ip.'", "name": "'.$domain.'", "proxied": false, "type": "A" }');
			if($json["success"]==1) {
				return '';
			} else {		
				return 'Error: DNS API: '.$json["errors"][0]["message"].' (code: '.$json["errors"][0]["code"].')';
			}
		}
	#	echo '<pre>'; print_r($json); echo '</pre>'; exit;
	#	echo 'Error: DNS API: '.$json["errors"][0]["message"].' (code: '.$json["errors"][0]["message"].')';
	#	exit;
	} else {
		// return 'Error: DNS API: Domain already exists in Clodflare DNS.';
		$zone_id = $zones[$domain]["zone_id"];
		$error = create_dns_records($zone_id, $domain, $server_ip);
		if($error=='') {
			#return "IP addedd sucessfully. Domain already exists in Cloudflare DNS. Please set nameservers to ". $zones[$domain]["nameservers"];
			return '';
		} else
			#return "Error: Domain already exists in Cloudflare DNS. Please set nameservers to ". $zones[$domain]["nameservers"] . "\n". $error;
			return '';
	}
}

function delete_domain_from_dns($domain) {
	log_debug("delete_domain_from_dns $domain");
	$zones = get_zones();
	#	echo '<pre>'; print_r($zones); echo '</pre>'; 
	if(array_key_exists($domain, $zones)) {
		$json = cloudflare_api('https://api.cloudflare.com/client/v4/zones/'.$zones[$domain]["zone_id"], true, 'DELETE');
		if($json["success"]==0) {
			return 'Error: DNS API: '.$json["errors"][0]["message"].' (code: '.$json["errors"][0]["code"].')';
		} else 
			return '';
	} else {
		$main_domain = main_domain($domain);
		if($main_domain=='')
			return 'Error: DNS API: Domain does not exists on Cloudflare.';
		else {
			log_debug("delete_domain_from_dns zone: $main_domain");
			$line = get_zone_record($main_domain, $domain, 'A');
			log_debug('DELETE https://api.cloudflare.com/client/v4/zones/'.$zones[$main_domain]["zone_id"].'/dns_records/'.$line);
			$json = cloudflare_api('https://api.cloudflare.com/client/v4/zones/'.$zones[$main_domain]["zone_id"].'/dns_records/'.$line, true, 'DELETE');	
			return '';
		}
	}
}

function add_update_local_mx($domain) {
	log_debug("add_update_local_mx $domain");
	$zones = get_zones();
	#	echo '<pre>'; print_r($zones); echo '</pre>'; 
	if(array_key_exists($domain, $zones)) {
		$line = get_zone_record($domain, $domain, 'MX');
		#echo '%'.$line."<br>";exit;
		while($line!='') {
			log_debug("add_update_local_mx MX found on line: $line");
			log_debug('DELETE https://api.cloudflare.com/client/v4/zones/'.$zones[$domain]["zone_id"].'/dns_records/'.$line);
			$json = cloudflare_api('https://api.cloudflare.com/client/v4/zones/'.$zones[$domain]["zone_id"].'/dns_records/'.$line, true, 'DELETE');	
			$line = get_zone_record($domain, $domain, 'MX');
		}
		$add = '{ "comment": "Mail server", "content": "'.$domain.'", "name": "'.$domain.'", "priority": 0, "proxied": false, "type": "MX" }';
		$json = cloudflare_api('https://api.cloudflare.com/client/v4/zones/'.$zones[$domain]["zone_id"].'/dns_records', true, 'POST', $add);
		log_debug('https://api.cloudflare.com/client/v4/zones/'.$zones[$domain]["zone_id"].'/dns_records true, POST '.$add);
		if($json["success"]==0) {
			return 'Error: DNS API: '.$json["errors"][0]["message"].' (code: '.$json["errors"][0]["code"].')';
		} else 
			return '';
	} else 
		return 'Error: DNS API: Domain does not exists on Cloudflare.';
}

#echo '#'.add_update_local_mx('buzzword.ro'); exit;

function add_update_dkim($domain) {
	log_debug("add_update_dkim $domain");
	$output = trim(shell_exec("sudo cat /etc/exim/keys/".$domain.".public.key | sed 's/-----BEGIN PUBLIC KEY-----//' | sed 's/-----END PUBLIC KEY-----//' | tr -d '\n' && echo"));
	log_debug("add_update_dkim $output");
	if($output!='')	{
		$zones = get_zones();
		# echo '<pre>'; print_r($zones); echo '</pre>'; 
		if(array_key_exists($domain, $zones)) {
			$dkim = 'v=DKIM1; k=rsa; p='.$output.';';
			log_debug("dkim: $dkim");
			#$dkim1 = substr($dkim,0,255);
			#$dkim2 = substr($dkim,255,255);
			$line = get_zone_record($domain, 'default._domainkey.'.$domain, 'TXT');
			#$line = get_zone_record($domain, 'default._domainkey', 'TXT');
			#echo "<pre>"; print_r($dkim1); echo "\n"; print_r($dkim2); echo "\n\nline: #".$line.'#'; echo "</pre>"; exit;
			if($line == '') {
				// add record
				$add = '{ "comment": "DKIM", "content": "\"'.$dkim.'\"", "name": "default._domainkey.'.$domain.'", "proxied": false, "type": "TXT" }';
				$json = cloudflare_api('https://api.cloudflare.com/client/v4/zones/'.$zones[$domain]["zone_id"].'/dns_records', true, 'POST', $add);
			} else {
				// edit record
				$edit = '{ "comment": "DKIM", "content": "\"'.$dkim.'\"", "name": "default._domainkey.'.$domain.'", "proxied": false, "type": "TXT" }';
				$json = cloudflare_api('https://api.cloudflare.com/client/v4/zones/'.$zones[$domain]["zone_id"].'/dns_records/'.$line, true, 'PATCH', $edit);
			}
			#echo "<pre>"; print_r($json);echo "</pre>";
		} else 
			return 'Error: DNS API: Domain does not exists on Cloudflare.';
	} else 
		return 'Error: Missing exim DKIM keys.';
}

function add_update_spf($domain, $spf = '"v=spf1 +a +mx ~all"') {
	log_debug("add_update_spf $domain");
	$zones = get_zones();
	# echo '<pre>'; print_r($zones); echo '</pre>'; 
	if(array_key_exists($domain, $zones)) {
		$line = get_zone_record($domain, $domain, 'TXT', '"v=spf1');
		if($line == '')
			$line = get_zone_record($domain, $domain, 'TXT', 'v=spf1');
		if($line == '') {
			// add record
			$add = '{ "comment": "SPF", "content": "'.addslashes($spf).'", "name": "'.$domain.'", "proxied": false, "type": "TXT" }';
			$json = cloudflare_api('https://api.cloudflare.com/client/v4/zones/'.$zones[$domain]["zone_id"].'/dns_records', true, 'POST', $add);
		} else {
			// edit record
			$edit = '{ "comment": "SPF", "content": "'.addslashes($spf).'", "name": "'.$domain.'", "proxied": false, "type": "TXT" }';
			$json = cloudflare_api('https://api.cloudflare.com/client/v4/zones/'.$zones[$domain]["zone_id"].'/dns_records/'.$line, true, 'PATCH', $edit);
		}
		#echo "<pre>"; print_r($json);echo "</pre>";
	} else 
		return 'Error: DNS API: Domain does not exists on Cloudflare.';
}

function add_update_dmarc($domain, $dmarc = '"v=DMARC1;p=none;sp=none;adkim=r;aspf=r;pct=100;fo=0;rf=afrf;ri=86400"') {
	log_debug("add_update_dmarc $domain");
	$zones = get_zones();
	# echo '<pre>'; print_r($zones); echo '</pre>'; 
	if(array_key_exists($domain, $zones)) {
		$line = get_zone_record($domain, '_dmarc.'.$domain, 'TXT', '"v=DMARC');
		if($line == '')
			$line = get_zone_record($domain, '_dmarc.'.$domain, 'TXT', 'v=DMARC');
		log_debug("add_update_dmarc line #".$line);
		#echo '<pre>#'; echo $line; echo '#</pre>'; 
		if($line == '') {
			// add record
			log_debug("add_update_dmarc ADD $dmarc");
			$add = '{ "comment": "DMARC", "content": "'.addslashes($dmarc).'", "name": "_dmarc.'.$domain.'", "proxied": false, "type": "TXT" }';
			$json = cloudflare_api('https://api.cloudflare.com/client/v4/zones/'.$zones[$domain]["zone_id"].'/dns_records', true, 'POST', $add);
		} else {
			// edit record
			log_debug("add_update_dmarc EDIT $dmarc");
			$edit = '{ "comment": "DMARC", "content": "'.addslashes($dmarc).'", "name": "_dmarc.'.$domain.'", "proxied": false, "type": "TXT" }';
			$json = cloudflare_api('https://api.cloudflare.com/client/v4/zones/'.$zones[$domain]["zone_id"].'/dns_records/'.$line, true, 'PATCH', $edit);
		}
		#echo "<pre>"; print_r($json);echo "</pre>";
	} else 
		return 'Error: DNS API: Domain does not exists on Cloudflare.';
}

#echo '<pre>'.add_domain_in_dns('buzzword4.ro', '85.9.27.182'); exit;

function get_zone_records($domain) {
	$zones = get_zones();
	#echo '<pre>'; print_r($zones); echo '</pre>'; exit;
	$line = '';
	log_debug("[api_cloudflare] get_zone_records domain: $domain");
	if(array_key_exists($domain, $zones)) {
		#echo('https://api.cloudflare.com/client/v4/zones/'.$zones[$domain]["zone_id"].'/dns_records<br>');
		$json = cloudflare_api('https://api.cloudflare.com/client/v4/zones/'.$zones[$domain]["zone_id"].'/dns_records', true, 'GET');
		#echo '<pre>'; print_r($json); echo '</pre>'; #exit;

		if($json["success"]==0) {
			#return 'Error: DNS API: '.$json["errors"][0]["message"].' (code: '.$json["errors"][0]["code"].')';
			return '';
		} else  {
			echo '<table class="table table-vcenter table-mobile-md card-table" border="1" cellspacing="0" cellpadding="4" style="border:1px solid #CCC">';
			echo '<tr><th>Name</th><th>Type</th><th>TTL</th><th>Proxied</th><th>Value</th></tr>';
			foreach($json["result"] as $i => $k) {
				#print_r($k); exit;
				if($k["name"]==$domain)
					$k["name"] = '@';
					$k["name"] = str_replace('.'.$domain, '', $k["name"]);
					echo '<tr><td>'.$k["name"].'</td><td>'.$k["type"].'</td><td>'.$k["ttl"].'</td>';
					echo '<td>'.($k["proxied"]==1?
						'<!-- div class="badge bg-orange-lt border text-orange">Yes</div -->
						 <label class="form-check form-switch" style="padding-top:7px;cursor:pointer;width:110px;">
    					 	<input class="form-check-input" id="proxied_'.$i.'" type="checkbox" checked="true" onChange="changeProxy(this.id)" data-domain="'.htmlspecialchars($domain).'" data-name="'.htmlspecialchars($k["name"]).'" data-type="'.htmlspecialchars($k["type"]).'">
    						<span class="form-check-label" id="label_proxied_'.$i.'">Enabled</span>
						</label>':
						($k["proxiable"]==1?
							'<!-- div class="badge bg-blue-lt border text-blue">No</div -->
							<label class="form-check form-switch" style="padding-top:7px;cursor:pointer;width:110px;">
    					 		<input class="form-check-input" id="proxied_'.$i.'" type="checkbox" onChange="changeProxy(this.id)" data-domain="'.htmlspecialchars($domain).'" data-name="'.htmlspecialchars($k["name"]).'" data-type="'.htmlspecialchars($k["type"]).'">
    							<span class="form-check-label" id="label_proxied_'.$i.'">Disabled</span>
							</label>':
							'<div class="badge bg-grey-lt text-grey">N/A</div>')).
						'</td><td>';
					if($k["type"]=='TXT')
						echo '<span style="font-family:monospace;" title="'.htmlspecialchars($k["content"]).'">';
					if(strlen($k["content"])>80)
						echo substr($k["content"],0,80).' ...';
					else {
						if(isset($k["priority"]))
							echo $k["priority"]." ";
						echo $k["content"];
					}
					if($k["type"]=='TXT')
						echo '</span>';
				echo "</td></tr>";
			}
			echo '</table>';
		}
	} else
		return 'Error: DNS API: Domain does not exists on Cloudflare.';
}

function change_proxied($domain, $name, $type, $proxied) {
	$zones = get_zones();
	if(array_key_exists($domain, $zones)) {
		if($name == '@')
			$name = $domain;
		else
			$name = $name.'.'.$domain;
		$line = get_zone_record($domain, $name, $type);
		#return 'Debug: '.$line; exit;
		if($line != '') {
			$edit = '{ "name": "'.$name.'", "proxied": '.$proxied.', "type": "'.$type.'" }';
			$json = cloudflare_api('https://api.cloudflare.com/client/v4/zones/'.$zones[$domain]["zone_id"].'/dns_records/'.$line, true, 'PATCH', $edit);
			if($json["success"]==1)
				return $json["result"]["proxied"]==1?'Enabled':'Disabled';
			else
				return 'Error: '.var_export($json["errors"], 1);
		} else
			return 'Error: Line not found.';
	} else
		return 'Error: domain does not exists.';
}

#get_zone_record('buzzword.ro', 'test', 'A'); exit;

#echo '<pre>#get_zone_records#'."\n\n"; print_r(get_zone_records('test-domain7.dom')); exit;
