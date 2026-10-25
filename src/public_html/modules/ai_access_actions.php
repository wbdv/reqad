<?php
/*
 * Settings > AI access actions: turn MCP on or off, make and revoke tokens
 * for AI assistants (app/mcp/mcp.php). Each modules/ai_access_*.php action
 * file calls ai_access_action().
 *
 * Turning it on and making a token give an assistant control of the server,
 * so they need sudo mode (security_need_sudo()), like the API page. Turning
 * it off and revoking only take access away.
 */
include_once __DIR__.'/security_actions.php';

function ai_access_action($action) {
	global $ai_new_token;
	$base = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/ai-access/';
	if (!mcp_ready())
		msg_redirect($base, 'The AI access tables are missing. Run the Reqad update (database 1045) first.', 'error');

	switch ($action) {
	case 'ai-access-toggle':
		$on = security_post('op', 8) === 'on';
		if ($on)
			security_need_sudo($base);
		result_redirect($base, mcp_set_enabled($on));

	case 'ai-access-token-create':
		security_need_sudo($base);
		$scopes = array('read');
		foreach (array('write', 'delete') as $s)
			if (!empty($_POST['perm_'.$s]))
				$scopes[] = $s;
		$days = security_post('days', 3);
		if (!array_key_exists((int)$days, mcp_expiry_choices()))
			msg_redirect($base, 'Choose when the token expires.', 'error');
		$r = mcp_token_create(security_post('name', 60), security_post('account', 32), $scopes, (int)$days,
		                      security_post('allow_ips', 500), auth_user());
		if (!$r['ok'])
			msg_redirect($base, $r['error'], 'error');
		$ai_new_token = $r['data'] + array('client' => mcp_client_config($r['data']['token_file']));
		return;     // render the page with the assistant's settings

	case 'ai-access-token-revoke':
		result_redirect($base, mcp_token_revoke((string)(int)security_post('token', 12), auth_user()));
	}
}
