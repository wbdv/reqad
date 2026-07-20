<?
$powerdns_server   = $settings["powerdns-server"];
$powerdns_api_key  = $settings["powerdns-api-key"];
$dns_provider_name = 'PowerDNS';

function powerdns_sync_zone($domain, &$error = null) {
    /* Push full desired zone state from local PowerDNS to the cPanel agent.
       Skips SOA/NS (cPanel manages those). Returns true on success. */
    global $settings;
    if (($settings['powerdns-mode'] ?? '') != 'hidden-master') return true;

    $url = get_server_url();
    if (substr($url, 0, 6) == 'Error:') { $error = $url; return false; }

    $zones = get_zones();
    if (!array_key_exists($domain, $zones)) {
        $error = 'zone not found in local PowerDNS';
        return false;
    }
    $json = powerdns_api($url.'/zones/'.$zones[$domain]['zone_id'], true);
    if ($json['success'] != 1) {
        $error = 'could not fetch zone from local PowerDNS';
        return false;
    }

    $records = [];
    foreach ($json['rrsets'] as $rrset) {
        if (in_array($rrset['type'], ['SOA', 'NS'])) continue;
        $name = rtrim($rrset['name'], '.');
        foreach ($rrset['records'] as $r) {
            if (!empty($r['disabled'])) continue;
            $records[] = [
                'name' => $name,
                'type' => $rrset['type'],
                'ttl'  => (int)$rrset['ttl'],
                'data' => $r['content'],
            ];
        }
    }

    return powerdns_agent_call('sync_zone', ['domain' => $domain, 'records' => $records], $error);
}

function powerdns_agent_call($action, $data, &$error = null, &$response = null) {
    global $settings;
    $url   = rtrim($settings['powerdns-agent-url'], '/').'/dns';
    $token = $settings['powerdns-agent-token'];
    $data['action'] = $action;
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer '.$token],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_TIMEOUT        => 60,
    ]);
    $raw  = curl_exec($curl);
    $cerr = curl_error($curl);
    $resp = json_decode($raw, true);
    curl_close($curl);
    log_debug('[powerdns_agent_call] action:'.$action.' url:'.$url.' resp:'.$raw.' curl_err:'.$cerr);
    if ($cerr) { $error = 'curl: '.$cerr; return false; }
    if (empty($resp['ok'])) { $error = $resp['error'] ?? 'agent returned error'; return false; }
    $response = $resp;
    return true;
}

/* ─── Pull / import: cPanel agent → local PowerDNS ────────────────────────────
   Counterpart of powerdns_sync_zone() (which pushes the other way). Used by the
   "Sync to local" button on the DNS page in hidden-master mode. */

function powerdns_list_remote_zones(&$error = null) {
    if (!powerdns_agent_call('list_zones', [], $error, $resp)) return false;
    return isset($resp['zones']) && is_array($resp['zones']) ? $resp['zones'] : [];
}

function powerdns_dump_remote_zone($domain, &$error = null) {
    if (!powerdns_agent_call('dump_zone', ['domain' => $domain], $error, $resp)) return false;
    return isset($resp['records']) && is_array($resp['records']) ? $resp['records'] : [];
}

function powerdns_create_local_zone($domain, $records, &$error = null) {
    /* Create a hidden-master Master zone with our own SOA/NS, then import the
       supplied records. */
    global $settings;
    $url = get_server_url();
    if (substr($url, 0, strlen('Error:')) == 'Error:') { $error = $url; return false; }

    $ns1 = rtrim($settings['powerdns-ns1'], '.').'.';
    $ns2 = rtrim($settings['powerdns-ns2'], '.').'.';
    $data = '{"name": "'.$domain.'.", "kind": "Master", "masters": [], "nameservers": ["'.$ns1.'", "'.$ns2.'"], "rrsets":
        [{"name": "'.$domain.'.", "type": "SOA", "ttl": 14400, "records": [{"content": "'.$ns1.' hostmaster.'.$domain.'. '.date('Ymd').'01 3600 1800 1209600 86400", "disabled": false}]}]}';
    $json = powerdns_api($url.'/zones', true, 'POST', $data);
    if ($json['success'] != 1) { $error = 'zone create failed, code '.$json['reason']; return false; }
    if (!isset($json['id'])) { $error = 'zone created but no id returned'; return false; }
    return powerdns_replace_local_records($domain, $json['id'], $records, $error);
}

function powerdns_replace_local_records($domain, $zone_id, $records, &$error = null) {
    /* Full replace of the manageable (non-SOA/NS) records of a local zone with
       the supplied set. Records' 'data' is already PowerDNS content syntax. */
    $url = get_server_url();
    if (substr($url, 0, strlen('Error:')) == 'Error:') { $error = $url; return false; }

    /* Desired state, grouped into rrsets by name+type. */
    $groups = [];
    foreach ($records as $rec) {
        $type = strtoupper(trim($rec['type']));
        if ($type == 'SOA' || $type == 'NS') continue;
        $rname = trim($rec['name']);
        $name  = ($rname == '' || $rname == '@') ? $domain.'.' : rtrim($rname, '.').'.';
        $key   = $name.'|'.$type;
        if (!isset($groups[$key]))
            $groups[$key] = ['name' => $name, 'type' => $type, 'ttl' => (int)($rec['ttl'] ?? 3600), 'records' => []];
        $groups[$key]['records'][] = ['content' => $rec['data'], 'disabled' => false];
    }

    /* Existing non-SOA/NS rrsets absent from the desired set must be deleted. */
    $rrsets = [];
    $json = powerdns_api($url.'/zones/'.$zone_id, true);
    if ($json['success'] == 1 && isset($json['rrsets'])) {
        foreach ($json['rrsets'] as $rr) {
            if (in_array($rr['type'], ['SOA', 'NS'])) continue;
            $key = rtrim($rr['name'], '.').'.|'.$rr['type'];
            if (!isset($groups[$key]))
                $rrsets[] = '{"name": "'.$rr['name'].'", "type": "'.$rr['type'].'", "changetype": "DELETE"}';
        }
    }
    foreach ($groups as $g) {
        $rrsets[] = '{"name": "'.$g['name'].'", "type": "'.$g['type'].'", "ttl": '.$g['ttl'].', "changetype": "REPLACE", "records": '.json_encode($g['records']).'}';
    }
    if (empty($rrsets)) return true;

    $data = '{"rrsets": ['.implode(',', $rrsets).']}';
    $json = powerdns_api($url.'/zones/'.$zone_id, true, 'PATCH', $data);
    if ($json['success'] == 1) return true;
    $error = 'patch failed, code '.$json['reason'];
    return false;
}

function powerdns_sync_to_local(&$summary = null) {
    /* Pull every zone the cPanel agent exposes and create-if-missing /
       update-if-present in local PowerDNS. Fills $summary with one human line
       per zone. Returns false only on a fatal (pre-loop) error. */
    global $settings;
    $summary = [];
    if (($settings['powerdns-mode'] ?? '') != 'hidden-master') {
        $summary[] = 'Error: PowerDNS is not in hidden-master mode.';
        return false;
    }
    if (empty($settings['powerdns-agent-url'])) {
        $summary[] = 'Error: no DNS agent configured.';
        return false;
    }
    $url = get_server_url();
    if (substr($url, 0, strlen('Error:')) == 'Error:') { $summary[] = $url; return false; }

    $remote = powerdns_list_remote_zones($err);
    if ($remote === false) { $summary[] = 'Error: agent list_zones: '.$err; return false; }

    $local = get_zones();
    if (!is_array($local)) $local = [];

    if (count($remote) == 0) {
        $summary[] = 'No zones returned by the cPanel agent.';
        return true;
    }

    foreach ($remote as $domain) {
        $domain = rtrim(strtolower(trim($domain)), '.');
        if ($domain == '') continue;
        $records = powerdns_dump_remote_zone($domain, $err);
        if ($records === false) {
            $summary[] = $domain.': ERROR dump — '.$err;
            continue;
        }
        if (array_key_exists($domain, $local)) {
            $ok = powerdns_replace_local_records($domain, $local[$domain]['zone_id'], $records, $e);
            $summary[] = $domain.': '.($ok ? 'updated ('.count($records).' records)' : 'ERROR update — '.$e);
        } else {
            $ok = powerdns_create_local_zone($domain, $records, $e);
            $summary[] = $domain.': '.($ok ? 'created ('.count($records).' records)' : 'ERROR create — '.$e);
        }
    }
    return true;
}

function powerdns_api($api_query, $parse_to_array = false, $method = 'GET', $data = '') {
    global $powerdns_server, $powerdns_api_key, $_debug;
	$headers = array();
    $curl = curl_init();

    /* curl protocol trace — only when debugging (was unconditional, which dumped
       the trace into page output / CLI stdout on every call) */
    if(!empty($_debug)) {
        curl_setopt($curl, CURLOPT_VERBOSE, 1);
        curl_setopt($curl, CURLOPT_STDERR, fopen(_PATH.'/log/debug_log', 'a'));
    }

    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST,0);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER,0);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER,1);

    $header[] = "X-API-Key: $powerdns_api_key";
   	$header[] = "Content-Type: application/json";
	$header[] = 'Accept: application/json';
    curl_setopt($curl, CURLOPT_HTTPHEADER,$header);
    curl_setopt($curl, CURLOPT_URL, $powerdns_server.$api_query);

	if($method=='POST') {
		curl_setopt($curl, CURLOPT_POST, true);
		if($data!='')
			curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
#			curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query(unserialize($data)));
	}

	if($method=='DELETE') {
	    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'DELETE');
	}

	if($method=='PATCH') {
	    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PATCH');
		if($data!='')
			curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
	}

	$result = curl_exec($curl);
    $json = json_decode($result, $parse_to_array);
	#if($method=='POST' && $api_query=='/zones') {
#	if($method=='POST') {
#		echo '<pre>#'.$powerdns_server.$api_query."\n\n"; print_r($result); #exit;
#	}
    $http_status = (int)(curl_getinfo($curl, CURLINFO_HTTP_CODE));
    if($http_status >= 200 && $http_status < 300) {
		$json["success"] = 1;
	} else {
		$json["success"] = 0;
	}
	$json["reason"] = $http_status;

	curl_close($curl);
    return $json;
}

function get_nameservers() {
}

function get_server_url() {
	$json = powerdns_api('/api/v1/servers', true);
	#echo '<pre>#'; print_r($json); exit;
	if($json["success"]==1 && isset($json[0]['url']))
		return $json[0]['url'];
	else
		return 'Error: '.$json["reason"].' '.$json["error"];
}

function get_zones() {
	$url = get_server_url();
	if(substr($url, 0, strlen('Error:')) != 'Error:') {
		$zones = array();
		$json = powerdns_api($url.'/zones', true);
		#echo '<pre>#'; print_r($json); exit;
		if($json["success"]==1) {
			foreach($json as $k => $v) {
				if(!isset($v["name"]))
					continue;
				$domain = substr($v["name"], 0, strlen($v["name"])-1);
				$zones[$domain] = array( 
					"zone_id" => $v["id"],
					"serial" => $v["serial"],
					#"status" => $v["status"], 
					#"nameservers" => implode(', ', $v["name_servers"])
				);
			}
		}
		#echo '<pre>'; print_r($json); echo "---------------------\n";	print_r($zones); echo '</pre>'; exit;
		return $zones;
	} else
		return $url;
}

#echo '<pre>'; var_dump(get_zones()); exit;

function add_domain_in_dns($domain, $server_ip) {
	global $settings;
	$url = get_server_url();
	if(substr($url, 0, strlen('Error:')) != 'Error:') {
		$hidden_master = ($settings['powerdns-mode'] == 'hidden-master');
		if ($hidden_master) {
			$ns1 = rtrim($settings['powerdns-ns1'], '.').'.';
			$ns2 = rtrim($settings['powerdns-ns2'], '.').'.';
			$data = '{"name": "'.$domain.'.", "kind": "Master", "masters": [], "nameservers": ["'.$ns1.'", "'.$ns2.'"], "rrsets":
				[{"name": "'.$domain.'.", "type": "SOA", "ttl": 14400, "records": [{"content": "'.$ns1.' hostmaster.'.$domain.'. '.date('Ymd').'01 3600 1800 1209600 86400", "disabled": false}]},
				{"name": "'.$domain.'.", "type": "A", "ttl": 14400, "records": [{"content": "'.$server_ip.'", "disabled": false}]},
				{"name": "www.'.$domain.'.", "type": "CNAME", "ttl": 14400, "records": [{"content": "'.$domain.'.", "disabled": false}]},
				{"name": "mail.'.$domain.'.", "type": "CNAME", "ttl": 14400, "records": [{"content": "'.$domain.'.", "disabled": false}]},
				{"name": "'.$domain.'.", "type": "MX", "ttl": 14400, "records": [{"content": "0 '.$domain.'.", "disabled": false}]}
				]}';
		} else {
			$data = '{"name": "'.$domain.'.", "kind": "Native", "masters": [], "nameservers": ["ns1.'.$domain.'.", "ns2.'.$domain.'."], "rrsets":
				[{"name": "'.$domain.'.", "type": "SOA", "ttl": 14400, "records": [{"content": "ns1.'.$domain.'. hostmaster.'.$domain.'. '.date('Ymd').'01 3600 1800 1209600 86400", "disabled": false}]},
				{"name": "'.$domain.'.", "type": "A", "ttl": 14400, "records": [{"content": "'.$server_ip.'", "disabled": false}]},
				{"name": "ns1.'.$domain.'.", "type": "A", "ttl": 14400, "records": [{"content": "'.$server_ip.'", "disabled": false}]},
				{"name": "www.'.$domain.'.", "type": "CNAME", "ttl": 14400, "records": [{"content": "'.$domain.'.", "disabled": false}]},
				{"name": "mail.'.$domain.'.", "type": "CNAME", "ttl": 14400, "records": [{"content": "'.$domain.'.", "disabled": false}]},
				{"name": "'.$domain.'.", "type": "MX", "ttl": 14400, "records": [{"content": "0 '.$domain.'.", "disabled": false}]}
				]}';
		}
		$json = powerdns_api($url.'/zones', true, 'POST', $data);
		if ($json["success"] != 1)
			return 'Error: DNS API: zone create failed, code '.$json["reason"];
		if ($hidden_master) {
			if (!powerdns_agent_call('add_zone', ['domain' => $domain, 'ip' => $server_ip], $agent_err))
				return 'Error: DNS agent: '.($agent_err ?? 'no response');
		}
		return '';
	} else
		return $url;
}

#echo '<pre>'; print_r(add_domain_in_dns('test-domain5.dom', '85.9.27.182')); exit;

function delete_domain_from_dns($domain) {
	global $settings;
	$url = get_server_url();
	if(substr($url, 0, strlen('Error:')) != 'Error:') {
		$zones = get_zones();
		if(array_key_exists($domain, $zones)) {
			if ($settings['powerdns-mode'] == 'hidden-master') {
				if (!powerdns_agent_call('delete_zone', ['domain' => $domain], $agent_err))
					return 'Error: DNS agent: '.($agent_err ?? 'no response');
			}
			$json = powerdns_api($url.'/zones/'.$zones[$domain]["zone_id"], true, 'DELETE');
			if($json["success"]==1)
				return '';
			else
				return 'Error: DNS API: domain '.$domain.' error code '.$json["reason"];
		} else {
			return 'Error: DNS API: domain '.$domain.' does not exists on DNS server';
		}
	} else
		return $url;
}

#echo '<pre>#'.delete_domain_from_dns('test-domain3.dom'); exit;

/* Alias domains ------------------------------------------------------------
   Create the DNS record for an alias domain pointing to $server_ip.
   - If the alias falls under an existing managed zone (a subdomain or wildcard of
     the account's domain), add an A record to that zone.
   - Otherwise create a new zone at the registrable domain (last two labels) and
     add the record there.
   Returns '' on success or an 'Error: ...' string. */
function add_alias_in_dns($alias, $server_ip) {
	$url = get_server_url();
	if(substr($url, 0, strlen('Error:')) == 'Error:') return $url;

	$alias   = strtolower(trim($alias));
	$is_wild = (substr($alias, 0, 2) === '*.');
	$bare    = $is_wild ? substr($alias, 2) : $alias;

	$zones = get_zones();
	if(!is_array($zones)) return 'Error: DNS API: cannot list zones.';

	/* longest managed zone that is a suffix of the bare alias */
	$zone = '';
	foreach($zones as $zname => $z) {
		if($bare === $zname || substr('.'.$bare, -strlen('.'.$zname)) === '.'.$zname) {
			if(strlen($zname) > strlen($zone)) $zone = $zname;
		}
	}

	if($zone === '') {
		/* no managed parent — create a new zone at the registrable domain */
		$parts   = explode('.', $bare);
		$newzone = (count($parts) >= 2) ? implode('.', array_slice($parts, -2)) : $bare;
		$err = add_domain_in_dns($newzone, $server_ip);
		if($err !== '') return $err;
		$zone  = $newzone;
		$zones = get_zones();   // refresh to pick up the new zone_id
		if(!is_array($zones) || !array_key_exists($zone, $zones))
			return 'Error: DNS API: new zone '.$zone.' not found after create.';
		/* add_domain_in_dns already created the apex A + www; if the alias is the
		   apex itself we are done. */
		if($alias === $zone) {
			powerdns_sync_zone($zone, $agent_err);
			return '';
		}
	}

	/* upsert an A record for the full alias name (wildcard "*." names allowed) */
	$records = array(array("content" => $server_ip, "disabled" => false));
	$data = '{"rrsets": [{"name": "'.$alias.'.", "type": "A", "ttl": 14400, "changetype": "REPLACE", "records": '.json_encode($records).'}]}';
	$json = powerdns_api($url.'/zones/'.$zones[$zone]["zone_id"], true, 'PATCH', $data);
	if($json["success"] != 1)
		return 'Error: DNS API: alias '.$alias.' error code '.$json["reason"];
	if(!powerdns_sync_zone($zone, $agent_err))
		return 'Error: DNS agent (alias): '.($agent_err ?? 'no response');
	return '';
}

/* Remove the A record for an alias from its managed zone. Best-effort: never
   deletes a zone or the zone apex; returns '' even when there is nothing to do. */
function delete_alias_from_dns($alias) {
	$url = get_server_url();
	if(substr($url, 0, strlen('Error:')) == 'Error:') return '';

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
	if($zone === '' || $alias === $zone) return '';   // nothing to remove / never touch apex

	$data = '{"rrsets": [{"name": "'.$alias.'.", "type": "A", "changetype": "DELETE"}]}';
	powerdns_api($url.'/zones/'.$zones[$zone]["zone_id"], true, 'PATCH', $data);
	powerdns_sync_zone($zone, $agent_err);
	return '';
}

/* ---- ACME dns-01 TXT helpers (used by certbot_dns_hook.php) --------------
   Manage the _acme-challenge TXT record for a name. add_update_txt APPENDS a
   value (preserving any already present) so the wildcard and the apex challenge
   — which share _acme-challenge.<domain> with different values — can coexist.
   $name is the full record name; $value is the raw (unquoted) token. */
function add_update_txt($name, $value) {
	$url = get_server_url();
	if(substr($url, 0, strlen('Error:')) == 'Error:') return $url;

	$name = strtolower(rtrim(trim($name), '.'));
	$zones = get_zones();
	if(!is_array($zones)) return 'Error: DNS API: cannot list zones.';

	/* longest managed zone that is a suffix of the record name */
	$zone = '';
	foreach($zones as $zname => $z) {
		if($name === $zname || substr('.'.$name, -strlen('.'.$zname)) === '.'.$zname) {
			if(strlen($zname) > strlen($zone)) $zone = $zname;
		}
	}
	if($zone === '') return 'Error: DNS API: no managed zone for '.$name.'.';

	/* keep existing TXT values at this name, then add ours (deduped) */
	$records  = array();
	$existing = get_zone_record($zone, $name, 'TXT');
	if(is_array($existing))
		foreach($existing as $rec)
			if(isset($rec['content']) && $rec['content'] !== '')
				$records[$rec['content']] = array('content' => $rec['content'], 'disabled' => false);
	$quoted = '"'.trim($value, '"').'"';
	$records[$quoted] = array('content' => $quoted, 'disabled' => false);
	$records = array_values($records);

	$data = '{"rrsets": [{"name": "'.$name.'.", "type": "TXT", "ttl": 60, "changetype": "REPLACE", "records": '.json_encode($records).'}]}';
	$json = powerdns_api($url.'/zones/'.$zones[$zone]["zone_id"], true, 'PATCH', $data);
	if($json["success"] != 1)
		return 'Error: DNS API: TXT '.$name.' error code '.$json["reason"];
	if(!powerdns_sync_zone($zone, $agent_err))
		return 'Error: DNS agent (TXT): '.($agent_err ?? 'no response');
	return '';
}

/* Remove a single TXT value from a name (or the whole record when $value==''). */
function delete_txt($name, $value = '') {
	$url = get_server_url();
	if(substr($url, 0, strlen('Error:')) == 'Error:') return '';

	$name = strtolower(rtrim(trim($name), '.'));
	$zones = get_zones();
	if(!is_array($zones)) return '';

	$zone = '';
	foreach($zones as $zname => $z) {
		if($name === $zname || substr('.'.$name, -strlen('.'.$zname)) === '.'.$zname) {
			if(strlen($zname) > strlen($zone)) $zone = $zname;
		}
	}
	if($zone === '') return '';

	$data = '{"rrsets": [{"name": "'.$name.'.", "type": "TXT", "changetype": "DELETE"}]}';
	if($value !== '') {
		/* keep every other value; only drop ours */
		$quoted   = '"'.trim($value, '"').'"';
		$records  = array();
		$existing = get_zone_record($zone, $name, 'TXT');
		if(is_array($existing))
			foreach($existing as $rec)
				if(isset($rec['content']) && $rec['content'] !== $quoted && $rec['content'] !== '')
					$records[] = array('content' => $rec['content'], 'disabled' => false);
		if(count($records))
			$data = '{"rrsets": [{"name": "'.$name.'.", "type": "TXT", "ttl": 60, "changetype": "REPLACE", "records": '.json_encode($records).'}]}';
	}
	powerdns_api($url.'/zones/'.$zones[$zone]["zone_id"], true, 'PATCH', $data);
	powerdns_sync_zone($zone, $agent_err);
	return '';
}

function get_zone_record($domain, $name, $type, $start='') {
	$url = get_server_url();
	if(substr($url, 0, strlen('Error:')) != 'Error:') {
		$zones = get_zones();
		#echo '<pre>'; print_r($zones); echo '</pre>';
		if($name=='')
			$name = $domain;
		log_debug("get_zone_record name: $name domain: $domain type: $type $start");
		if(array_key_exists($domain, $zones)) {
			#echo '#exists#'.$domain;
			$json = powerdns_api($url.'/zones/'.$zones[$domain]["zone_id"], true);
			#echo '<pre>'; print_r($json); echo '</pre>'; exit;
			if($json["success"]==1) {
				foreach($json["rrsets"] as $i => $k) {
					#echo $i.' '.$k["id"].' '.$k["name"].'='.$name.' '.$k["type"].' '.$k["records"][0]["content"]."<br>";
					#echo "#".substr($k['content'],0, strlen($start)).'# - #'.$start.'# ';
					#echo ((substr($k['content'],0, strlen($start))==$start)?' - OK':'')."<br>";
					if($k['type']==$type && $k['name']==$name.'.') {
						#echo '%%'.$k['records']; exit;
						return $k['records'];
					}
				}
			} else
				return 'Error: DNS API: domain '.$domain.' error code '.$json["reason"];
		} else {
			return 'Error: DNS API: domain '.$domain.' does not exists on DNS server';
		}
	} else
		return $url;
}

#echo '<pre>#get_zone_record#'; print_r(get_zone_record('test-domain4.dom', '', 'A')); exit;

function get_zone_records($domain) {
	$url = get_server_url();
	if(substr($url, 0, strlen('Error:')) != 'Error:') {
		$zones = get_zones();
		#echo '<pre>'; print_r($zones); echo '</pre>';
		if(array_key_exists($domain, $zones)) {
			#echo '#exists#'.$domain;
			$json = powerdns_api($url.'/zones/'.$zones[$domain]["zone_id"], true);
			#echo '<pre>'; print_r($json); echo '</pre>'; exit;
			if($json["success"]==1) {
				#return $json["rrsets"];
				#echo '<h1>Zone records for '.$domain.'</h1>';
				echo '<table class="table table-vcenter table-mobile-md card-table" border="1" cellspacing="0" cellpadding="4" style="border:1px solid #CCC">';
				echo '<tr><th>Name</th><th>Type</th><th>TTL</th><th>Value</th></tr>';
				foreach($json["rrsets"] as $i => $k) {
					#print_r($k); exit;
					if($k["name"]==$domain.'.')
						$k["name"] = '@';
					$k["name"] = str_replace('.'.$domain.'.', '', $k["name"]);
					echo '<tr><td>'.$k["name"].'</td><td>'.$k["type"].'</td><td>'.$k["ttl"].'</td><td>';
					foreach($k["records"] as $rk => $rv) {
						if($k["type"]=='TXT')
							echo '<span style="font-family:monospace;" title="'.htmlspecialchars($rv["content"]).'">';
						if(strlen($rv["content"])>80)
							echo substr($rv["content"],0,80).' ...';
						else
							echo $rv["content"]."<br>";
						if($k["type"]=='TXT')
							echo '</span>';
					}
					echo "</td></tr>";
				}
				echo '</table>';
			} else
				return 'Error: DNS API: domain '.$domain.' error code '.$json["reason"];
		} else {
			return 'Error: DNS API: domain '.$domain.' does not exists on DNS server';
		}
	} else
		return $url;
}
#echo '<pre>#get_zone_records#'."\n\n"; print_r(get_zone_records('test-domain7.dom')); exit;

/* ─── Backup / restore data API (normalized records) ─────────────────────────
   export_zone_records() returns a plain array (unlike get_zone_records() which
   echoes an HTML table); import_zone_records() re-creates them. Normalized shape:
     { name: '@'|'www'|'_dmarc'|…, type, ttl, content, priority(null), proxied(false) }
   For PowerDNS the content is already native syntax (e.g. MX "0 domain."). */
function export_zone_records($domain) {
	$out = array();
	$url = get_server_url();
	if(substr($url, 0, strlen('Error:')) == 'Error:') return $out;
	$zones = get_zones();
	if(!is_array($zones) || !array_key_exists($domain, $zones)) return $out;
	$json = powerdns_api($url.'/zones/'.$zones[$domain]["zone_id"], true);
	if(($json["success"] ?? 0) != 1 || !isset($json["rrsets"])) return $out;
	foreach($json["rrsets"] as $k) {
		$name = rtrim($k["name"], '.');
		if($name == $domain)
			$name = '@';
		else
			$name = preg_replace('/\.'.preg_quote($domain, '/').'$/', '', $name);
		foreach($k["records"] as $rv) {
			if(!empty($rv["disabled"])) continue;
			$out[] = array(
				'name'     => $name,
				'type'     => $k["type"],
				'ttl'      => (int)$k["ttl"],
				'content'  => $rv["content"],
				'priority' => null,
				'proxied'  => false,
			);
		}
	}
	return $out;
}

function import_zone_records($domain, $records, &$error = null) {
	global $settings;
	/* normalized -> powerdns record shape ({name,type,ttl,data}); replace/create
	   skip SOA/NS themselves, but drop them here too to be explicit. */
	$recs = array();
	foreach($records as $r) {
		$t = strtoupper(trim($r['type']));
		if($t == 'SOA' || $t == 'NS') continue;
		$nm = (isset($r['name']) ? trim($r['name']) : '');
		$recs[] = array(
			'name' => ($nm == '' || $nm == '@') ? '@' : $nm.'.'.$domain,
			'type' => $t,
			'ttl'  => (int)($r['ttl'] ?? 14400),
			'data' => $r['content'],
		);
	}
	$zones = get_zones();
	if(is_string($zones)) { $error = $zones; return false; }
	if(array_key_exists($domain, $zones)) {
		if(!powerdns_replace_local_records($domain, $zones[$domain]['zone_id'], $recs, $error)) return false;
	} else {
		if(!powerdns_create_local_zone($domain, $recs, $error)) return false;
	}
	/* push to the hidden-master agent if configured (best effort) */
	if(($settings['powerdns-mode'] ?? '') == 'hidden-master')
		powerdns_sync_zone($domain, $error);
	return true;
}

function add_update_dkim($domain) {
	global $settings;
	$output = trim(shell_exec("sudo cat /etc/exim/keys/".$domain.".public.key | sed 's/-----BEGIN PUBLIC KEY-----//' | sed 's/-----END PUBLIC KEY-----//' | tr -d '\n' && echo"));
	if($output!='')	{
		$dkim = 'v=DKIM1; k=rsa; p='.str_replace(' ', '', $output).';';
		$url = get_server_url();
		if(substr($url, 0, strlen('Error:')) != 'Error:') {
			$zones = get_zones();
			#echo '<pre>'; print_r($zones); echo '</pre>'; 
			if(array_key_exists($domain, $zones)) {
				#echo '<pre>$$$'; print_r(get_zone_record($domain, 'default._domainkey.'.$domain, 'TXT')); exit;
				/* # always overwrite, there will be only one TXT entry for default._domainkey */
				$records[] = array( "content" => '"'.$dkim.'"', "disabled" => false);
				#echo '<pre>'; print_r($records); exit;
				#echo json_encode($records); exit;
				$data = '{"rrsets": [{"name": "default._domainkey.'.$domain.'.", "type": "TXT", "ttl": 3600, "changetype": "REPLACE", "records": '.json_encode($records).'}]}';
				#echo $domain.' '.$dkim.' '.$data."\n";
				$json = powerdns_api($url.'/zones/'.$zones[$domain]["zone_id"], true, 'PATCH', $data);
				if($json["success"]==1) {
					if (!powerdns_sync_zone($domain, $agent_err))
						return 'Error: DNS agent (DKIM): '.($agent_err ?? 'no response');
					return '';
				} else
					return 'Error: DNS API: domain '.$domain.' error code '.$json["reason"];
			} else {
				return 'Error: DNS API: domain '.$domain.' does not exists on DNS server';
			}
		} else
			return $url;
	} else
		return 'Error: Missing exim DKIM keys.';
}

#echo '<pre>#add_update_dkim#'."\n"; print_r(add_update_dkim('test-domain4.dom')); echo "\n"; print_r(get_zone_record('test-domain4.dom', 'default._domainkey.test-domain4.dom', 'TXT')); exit;

function add_update_spf($domain, $spf = 'v=spf1 +a +mx ~all') {
	global $settings;
	$url = get_server_url();
	if(substr($url, 0, strlen('Error:')) != 'Error:') {
		$zones = get_zones();
		#echo '<pre>'; print_r($zones); echo '</pre>'; 
		if(array_key_exists($domain, $zones)) {
			#echo '<pre>$$$'; print_r(get_zone_record($domain, $domain, 'TXT')); exit;
			$records = get_zone_record($domain, $domain, 'TXT');
			$spf_found = false;
			foreach($records as $i => $k) {
				#echo '<br>'.$i.'. '.$k["content"].' '.$k["disabled"]."\n";
				if($k["content"]=='' || $k["content"]=='""')
					unset($records[$i]);
				if(substr($k["content"],0,strlen('"v=spf1'))=='"v=spf1') {
					if($spf_found) {
						// remove duplicate spf
						unset($records[$i]);
					} else {
						$spf_found = true;
						$records[$i]["content"]  = '"'.$spf.'"';
						$records[$i]["disabled"] = false;
					}
				}
			}
			if(!$spf_found) {
				$records[] = array( "content" => '"'.$spf.'"', "disabled" => false);
			}
			#echo '<pre>'; print_r($records); exit;
			#echo json_encode($records); exit;
			$data = '{"rrsets": [{"name": "'.$domain.'.", "type": "TXT", "ttl": 3600, "changetype": "REPLACE", "records": '.json_encode($records).'}]}';
			#echo $domain.' '.$spf.' '.$data."\n";
			$json = powerdns_api($url.'/zones/'.$zones[$domain]["zone_id"], true, 'PATCH', $data);
			if($json["success"]==1) {
				if (!powerdns_sync_zone($domain, $agent_err))
					return 'Error: DNS agent (SPF): '.($agent_err ?? 'no response');
				return '';
			} else
				return 'Error: DNS API: domain '.$domain.' error code '.$json["reason"];
		} else {
			return 'Error: DNS API: domain '.$domain.' does not exists on DNS server';
		}
	} else
		return $url;
}

#echo '<pre>#add_update_spf#'."\n"; print_r(add_update_spf('test-domain4.dom')); echo "\n"; print_r(get_zone_record('test-domain4.dom', 'test-domain4.dom', 'TXT')); exit;

function add_update_dmarc($domain, $dmarc = 'v=DMARC1;p=none;sp=none;adkim=r;aspf=r;pct=100;fo=0;rf=afrf;ri=86400') {
	global $settings;
	$url = get_server_url();
	if(substr($url, 0, strlen('Error:')) != 'Error:') {
		$zones = get_zones();
		#echo '<pre>'; print_r($zones); echo '</pre>'; 
		if(array_key_exists($domain, $zones)) {
			#echo '<pre>$$$'; print_r(get_zone_record($domain, '_dmarc.'.$domain, 'TXT')); exit;
			/* # always overwrite, there will be only one TXT entry for _dmarc */
			$records[] = array( "content" => '"'.$dmarc.'"', "disabled" => false);
			#echo '<pre>'; print_r($records); exit;
			#echo json_encode($records); exit;
			$data = '{"rrsets": [{"name": "_dmarc.'.$domain.'.", "type": "TXT", "ttl": 3600, "changetype": "REPLACE", "records": '.json_encode($records).'}]}';
			#echo $domain.' '.$dkim.' '.$data."\n";
			$json = powerdns_api($url.'/zones/'.$zones[$domain]["zone_id"], true, 'PATCH', $data);
			if($json["success"]==1) {
				if (!powerdns_sync_zone($domain, $agent_err))
					return 'Error: DNS agent (DMARC): '.($agent_err ?? 'no response');
				return '';
			} else
				return 'Error: DNS API: domain '.$domain.' error code '.$json["reason"];
		} else {
			return 'Error: DNS API: domain '.$domain.' does not exists on DNS server';
		}
	} else
		return $url;
}

#echo '<pre>#add_update_dmarc#'."\n"; print_r(add_update_dmarc('test-domain4.dom')); echo "\n"; print_r(get_zone_record('test-domain4.dom', '_dmarc.test-domain4.dom', 'TXT')); exit;

function add_update_local_mx($domain) {
	global $settings;
	$url = get_server_url();
	if(substr($url, 0, strlen('Error:')) != 'Error:') {
		$zones = get_zones();
		#echo '<pre>'; print_r($zones); echo '</pre>'; 
		if(array_key_exists($domain, $zones)) {
			$data = '{"rrsets": [{"name": "'.$domain.'.", "type": "MX", "ttl": 3600, "changetype": "REPLACE", "records": [{"content": "0 '.$domain.'.", "disabled": false}]}]}';
			$json = powerdns_api($url.'/zones/'.$zones[$domain]["zone_id"], true, 'PATCH', $data);
			if($json["success"]==1) {
				if (!powerdns_sync_zone($domain, $agent_err))
					return 'Error: DNS agent (MX): '.($agent_err ?? 'no response');
				return '';
			} else
				return 'Error: DNS API: domain '.$domain.' error code '.$json["reason"];
		} else {
			return 'Error: DNS API: domain '.$domain.' does not exists on DNS server';
		}
	} else
		return $url;
}

#echo '<pre>#add_update_local_mx#'."\n"; print_r(add_update_local_mx('test-domain4.dom')); echo "\n"; print_r(get_zone_record('test-domain4.dom', '', 'MX')); exit;
