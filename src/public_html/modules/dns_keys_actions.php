<?php
/*
 * Settings > DNS Settings > API keys actions (templates/dns-settings-keys.php): turn the DNS API
 * on or off, make and revoke keys, give zones to a key, move or take them
 * away (app/functions/dns_keys.php). Each modules/dns_key*.php action file
 * calls dns_keys_action().
 *
 * Turning the API on, making a key and giving a key zones give another
 * server access, so they need sudo mode (security_need_sudo(), as on the
 * API access page). Turning it off, revoking and taking zones away only take
 * access away.
 */
include_once __DIR__.'/security_actions.php';

function dns_keys_action($action) {
	global $dns_new_key;
	$base = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/dns-settings/keys/';
	if (!dns_api_ready())
		msg_redirect($base, 'The DNS API tables are missing. Run the Reqad update (database 1055) first.', 'error');
	$key = (int)security_post('key', 12);

	switch ($action) {
	case 'dns-keys-toggle':
		$on = security_post('op', 8) === 'on';
		if ($on)
			security_need_sudo($base);
		result_redirect($base, dns_api_set_enabled($on));

	case 'dns-key-create':
		security_need_sudo($base);
		$perms = array();
		foreach (array('write', 'delete') as $p)
			if (!empty($_POST['perm_'.$p]))
				$perms[] = $p;
		$days = security_post('days', 5);
		if (!array_key_exists((int)$days, api_expiry_choices()))
			msg_redirect($base, 'Choose when the key expires.', 'error');
		$r = dns_key_create(security_post('name', 60), security_post('allow_ips', 500), $perms, (int)security_post('max_zones', 7),
		                    (int)$days, security_post('zones', 200000), auth_user());
		if (!$r['ok'])
			msg_redirect($base, $r['error'], 'error');
		$dns_new_key = $r['data'] + array('warnings' => $r['warnings']);
		return;     // render the page with the key (shown once, never stored)

	case 'dns-key-revoke':
		result_redirect($base, dns_key_revoke($key, auth_user()));

	case 'dns-key-zones-add':
		security_need_sudo($base);
		result_redirect($base.'#key-'.$key, dns_key_assign($key, security_post('zones', 200000), auth_user()));

	case 'dns-key-zone-release':
		result_redirect($base.'#key-'.$key, dns_key_release($key, security_post('zone', 253), auth_user()));

	case 'dns-key-zones-move':
		security_need_sudo($base);
		$to = (int)security_post('to', 12);
		result_redirect($base.'#key-'.$to, dns_key_move_all($key, $to, auth_user()));

	case 'dns-key-limit':
		result_redirect($base.'#key-'.$key, dns_key_limit_set($key, (int)security_post('max_zones', 7), auth_user()));
	}
}
