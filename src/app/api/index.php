<?php
/*
 * REST API entry point. nginx (location ^~ /api/ in the panel vhost, written
 * by scripts/update/setup_panel_api.sh) sends every /api/ request here, past
 * the panel login: the Bearer token is the credential. See app/api/api.php.
 *
 * Loads the panel the way index.php does - defines.php at file scope (it
 * creates $db and $ini as globals), the working directory in public_html for
 * the code that uses relative paths - but no routing, CSRF or login gate.
 */
require '/usr/local/reqad/public_html/defines.php';
chdir(_PATH.'/public_html');
require_once _PATH.'/public_html/modules/functions.php';
foreach (glob(_PATH.'/app/functions/*.php') as $app_functions_file)
	require_once $app_functions_file;
require_once _PATH.'/app/auth/auth.php';
require_once _PATH.'/app/api/api.php';
require_once _PATH.'/app/api/routes.php';
date_default_timezone_set(system_timezone());
/* plugins too: their event hooks, limits and routes apply to the API */
plugins_load();

api_serve();
