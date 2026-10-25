<?php
/*
 * DNS API entry point: a PowerDNS-compatible API for other servers' zones,
 * with DNS API keys (app/functions/dns_keys.php). nginx (location ^~ /pdns/
 * in the panel vhost, written by scripts/update/setup_panel_api.sh) sends
 * every /pdns/ request here, past the panel login: the key is the credential.
 *
 * Loads the panel the way app/api/index.php does, without routing, CSRF or
 * the login gate.
 */
require '/usr/local/reqad/public_html/defines.php';
chdir(_PATH.'/public_html');
require_once _PATH.'/public_html/modules/functions.php';
foreach (glob(_PATH.'/app/functions/*.php') as $app_functions_file)
	require_once $app_functions_file;
require_once _PATH.'/app/auth/auth.php';
require_once _PATH.'/app/api/api.php';
date_default_timezone_set(system_timezone());
/* plugins too: their event hooks apply to zones made and removed here */
plugins_load();

dns_api_serve();
