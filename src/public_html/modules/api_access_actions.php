<?php
/*
 * Settings > API access actions: turn the REST API on or off, make and revoke
 * tokens (app/api/api.php). Each modules/api_access_*.php action file calls
 * api_access_action().
 *
 * Turning the API on and making a token give remote access to the server, so
 * they need sudo mode (security_need_sudo(), same as the Security page).
 * Turning it off and revoking only take access away.
 */
include_once __DIR__.'/security_actions.php';

function api_access_action($action) {
	global $db, $api_new_token;
	$base = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/api-access/';
	if (!api_ready())
		msg_redirect($base, 'The API tables are missing. Run the Reqad update (database 1042) first.', 'error');

	switch ($action) {
	case 'api-access-toggle':
		$on = security_post('op', 8) === 'on';
		if ($on)
			security_need_sudo($base);
		result_redirect($base, api_set_enabled($on));

	case 'api-access-token-create':
		security_need_sudo($base);
		$scopes = array('read');
		foreach (array('write', 'delete') as $s)
			if (!empty($_POST['perm_'.$s]))
				$scopes[] = $s;
		$days = security_post('days', 5);
		if (!array_key_exists((int)$days, api_expiry_choices()))
			msg_redirect($base, 'Choose when the token expires.', 'error');
		$r = api_token_create(security_post('name', 60), security_post('account', 32), $scopes, (int)$days,
		                      security_post('allow_ips', 500), auth_user());
		if (!$r['ok'])
			msg_redirect($base, $r['error'], 'error');
		$api_new_token = $r['data'];
		return;     // render the page with the token (shown once, never stored)

	case 'api-access-token-revoke':
		result_redirect($base, api_token_revoke((string)(int)security_post('token', 12), auth_user()));
	}
}
