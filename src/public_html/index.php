<?php
    #phpinfo(); exit;
  	if(in_array('shell_exec', explode(',', ini_get('disable_functions'))) || !function_exists('shell_exec')) {
		echo "<pre style=\"border:1px solid #FCC;background:#FEE;color:#D55;padding:20px;max-width:300px;text-align:center;margin:20% auto;\"><b>Error:</b> Reqad requires shell_exec function.</pre>";
		exit;
	}

    include('defines.php');
    include('modules/functions.php');

    // Discover add-on modules (public_html/plugins/*/plugin.php). Must run before
    // ajax.php so plugin ajax handlers are registered when its dispatch tail runs.
    plugins_load();

    // CSRF: every state change goes over POST, so a single guard here protects
    // both form submits and AJAX (which posts to ajax.php, included next). GET
    // stays read-only and needs no token. See templates/footer.php for the
    // client that supplies the token.
    if($_SERVER['REQUEST_METHOD'] === 'POST')
        csrf_check();

    // Compute the token now, while $db is guaranteed to be the SQLite handle.
    // Some page templates reuse the global $db as a loop variable (e.g. the
    // databases page), so csrf_secret() must not first run during footer render
    // or it would fatal on a clobbered $db and blank out window.CSRF site-wide.
    $csrf_token = csrf_token();

    include('modules/ajax.php');

    $req = $_SERVER['REQUEST_URI']; 
    if($req == '/index.php') {
        header("Location: ".$_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST']);
        exit;
    }
    #echo '<pre>'; var_dump($req);
    // Split on the PATH only — REQUEST_URI includes the query string, which would
    // otherwise leak into the last path segment (e.g. /account/john?tab=config
    // would make $reqs[2] = 'john?tab=config' -> wrong account).
    $reqs = explode('/', parse_url($req, PHP_URL_PATH));
    #echo '<pre>'; var_dump($reqs);
    $route = $reqs[1];

	$allowed_actions = array(
		'accounts'			=> array('create-account', 'edit-account', 'delete-account'),
		'account'			=> array('alias-add', 'alias-delete', 'reissue-ssl', 'config-save', 'config-restore'),
		'databases' 		=> array('create-database', 'delete-database', 'change-db-password'),
		'email-accounts' 	=> array('create-email', 'edit-email', 'delete-email'),
		'forwarders'		=> array('create-forwarder', 'edit-forwarder', 'delete-forwarder'),
		'autoresponders'	=> array('create-autoresponder', 'edit-autoresponder', 'delete-autoresponder'),
		'ssl'				=> array('update-ssl'),
		'settings'			=> array('settings'),
		'dns-settings'		=> array('dns-settings'),
		'php-settings'		=> array('php-settings'),
		'wp-toolkit'		=> array('wp-install', 'wp-clone', 'wp-scan', 'wp-auto-login'),
		'reboot'			=> array('reboot-server'),
		'backup'			=> array('generate-backup', 'delete-backup', 'download-backup', 'restore-backup'),
		'transfer-tool'		=> array('ajax-transfer-run'),
		'ssh-keys'			=> array('add-ssh-key', 'delete-ssh-key'),
		'cron'				=> array('create-cron', 'edit-cron', 'delete-cron'),
	);

   	$myaction = '';
   	// Actions mutate state, so they are accepted over POST only (and are CSRF
   	// checked above). GET can no longer trigger an action.
   	if(isset($_POST["action"]))
		$myaction 				= $_POST["action"];
	
	$myaction_is_allowed 	= false;

	#echo '<pre>#'.$route.'# -> '.$myaction.' -> '.($myaction_is_allowed===true?'true':'false'); exit;

	if($myaction!='') {
		foreach($allowed_actions as $rt => $actions) {
			if(in_array($myaction, $actions)) {
				$route = $rt;
				$myaction_is_allowed = true;
				break;
			}
		}

		// Fall back to plugin-owned actions (e.g. wg-add-peer).
		if(!$myaction_is_allowed && ($pl = plugin_for_action($myaction))) {
			$route = $pl['route'];
			$myaction_is_allowed = true;
		}

		#echo '<pre>#'.$route.'# -> '.$myaction.' -> '.($myaction_is_allowed===true?'true':'false'); exit;

		// Block disabled-feature actions before they execute any side effect
		if($myaction_is_allowed) {
			$blocked_feature = route_blocked_feature($ini, $route);
			if($blocked_feature !== null) {
				http_response_code(403);
				include('templates/feature-disabled.php');
				exit;
			}
		}

		if($myaction_is_allowed) {
			// A plugin route runs its own action handler; core routes use
			// modules/<action>.php (dashes -> underscores).
			if(($pl = plugin_for_route($route)) && !empty($pl['action_handler']))
				include_once($pl['action_handler']);
			elseif(is_file('modules/'.str_replace('-', '_', $myaction).'.php'))
				include_once('modules/'.str_replace('-', '_', $myaction).'.php');
		}
    }

    $user = clean($_SERVER['USER']);
    
    error_log(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$_SERVER["REMOTE_ADDR"]." ".$_SERVER['USER']." ".$route."\n", 3, '../log/route_log');

    // Block direct access to disabled-feature sections
    $blocked_feature = route_blocked_feature($ini, $route);
    if($blocked_feature !== null) {
        http_response_code(403);
        include('templates/feature-disabled.php');
        exit;
    }

    switch ($route) {
        case 'accounts':
            $page = isset($_GET["page"])?(int)($_GET["page"]):0;
            include('templates/accounts.php');
            break;
        case 'account':
            // Per-account management page: /account/<user>
            $acct_user = isset($reqs[2]) ? preg_replace('/[^a-z0-9]/', '', $reqs[2]) : '';
            include('templates/account.php');
            break;
        case 'ssh-keys':
            include('templates/ssh-keys.php');
            break;
        case 'databases':
            include('templates/databases.php');
            break;
        case 'mysqltuner':
            include('templates/mysqltuner.php');
            break;
        case 'ssl':
            include('templates/ssl.php');
            break;
        case 'dns':
            include('templates/dns.php');
            break;
        case 'cron':
            include('templates/cron.php');
            break;
		case 'services':
            include('templates/services.php');
            break;
		case 'apache':
            include('templates/apache.php');
            break;
		case 'backup':
            include('templates/backup.php');
            break;
		case 'transfer-tool':
            include('templates/transfer-tool.php');
            break;
		case 'backupdb':
            include('templates/backupdb.php');
            break;
        case 'email-accounts':
            #$page = isset($_GET["page"])?(int)($_GET["page"]):0;
            include('templates/email-accounts.php');
            break;
		case 'forwarders':
			include('templates/forwarders.php');
			break;
		case 'autoresponders':
			include('templates/autoresponders.php');
			break;
        case 'check-email-settings':
            include('templates/check-email-settings.php');
            break;
        case 'email-stats':
            include('templates/email-stats.php');
            break;
        case 'settings':
            include('templates/settings.php');
            break;
        case 'dns-settings':
            include('templates/dns-settings.php');
            break;
        case 'php-settings':
            include('templates/php-settings.php');
            break;
        case 'wp-toolkit':
            include('templates/wp-toolkit.php');
            break;
        case 'terminal':
            include('templates/terminal.php');
            break;
		case 'reboot':
            include('templates/reboot.php');
            break;
		case 'cancel-reboot':
			include('templates/dashboard.php');
            break;
		case 'ping':
            echo "OK";
            break;
		default:
			// Plugin-owned route (feature gating already enforced above).
			if(($pl = plugin_for_route($route))) {
				include($pl['template']);
				break;
			}
			if($route != '') {
				header("Location: ".$_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST']);
				exit;
			}
			$route = 'dashboard';
			include('templates/dashboard.php');
	}
