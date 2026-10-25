<?php
/*
 * Accounts > Deployments form actions. A site there has a code source (a Git
 * deployment, app/functions/deploy.php) and/or a runtime other than PHP (a
 * Node.js / Python app, app/functions/apps.php); one form saves both.
 * Deploy now, rollback, logs, start / stop, the environment and runtimes go
 * over ajax (ajax-deploy-*, ajax-app-* in modules/ajax.php).
 *
 *   deploy-site-save    create or change both parts; a part switched off is
 *                       removed (Git off: stop deploying; runtime PHP: the app
 *                       is removed and the domain gets its PHP block back)
 *   deploy-site-remove  remove both parts; the files stay
 */
function deploy_post($name, $max = 4000) {
	return isset($_POST[$name]) && is_string($_POST[$name]) ? substr($_POST[$name], 0, $max) : '';
}

function deploy_action($action) {
	$base = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/deployments/';
	if (!deploy_ready() || !apps_ready())
		msg_redirect($base, 'The deployment tables are missing. Run the Reqad update (databases 1043 and 1044) first.', 'error');
	$domain = strtolower(trim(deploy_post('domain', 255)));
	$d = deploy_get($domain);
	$a = app_get($domain);
	$done = array();
	$warnings = array();
	$fail = function ($r) use ($base, &$done) {
		msg_redirect($base, ($done ? implode(' ', $done).' But: ' : '').$r['error'], 'error');
	};

	if ($action === 'deploy-site-remove') {
		if (!$d && !$a)
			msg_redirect($base, "$domain is not deployed and runs no app.", 'error');
		if ($a) { $r = app_delete($a['id']); if (!$r['ok']) $fail($r); $done[] = $r['message']; $warnings = array_merge($warnings, $r['warnings']); }
		if ($d) { $r = deploy_delete($d['id']); if (!$r['ok']) $fail($r); $done[] = $r['message']; }
		result_redirect($base, result_ok(implode(' ', $done), array(), $warnings));
	}
	if ($action !== 'deploy-site-save')
		return;

	$git = !empty($_POST['git']);
	$runtime = deploy_post('runtime', 20);            // 'php' or 'node:24' / 'python:3.12'
	if (!$git && $runtime === 'php' && !$d && !$a)
		msg_redirect($base, 'Choose Git as the source, a Node.js / Python runtime, or both.', 'error');

	$new_dep = 0;
	if ($git) {
		$f = array('repo_url' => deploy_post('repo_url', 500), 'branch' => deploy_post('branch', 100),
		           'public_dir' => deploy_post('public_dir', 200), 'build_cmd' => deploy_post('build_cmd'),
		           'shared_paths' => deploy_post('shared_paths'), 'keep_releases' => (int)deploy_post('keep_releases', 3),
		           'health_path' => deploy_post('health_path', 200), 'auto_deploy' => !empty($_POST['auto_deploy']));
		if ($d) {
			/* an address with a token in it is not shown on the page: empty = keep it */
			if ($f['repo_url'] === '') unset($f['repo_url']);
			if (!empty($_POST['new_secret'])) $f['new_secret'] = true;
			$r = deploy_edit($d['id'], $f);
		} else {
			$r = deploy_create(array('domain' => $domain) + $f, auth_user());
			if ($r['ok']) $new_dep = (int)$r['data']['id'];
		}
		if (!$r['ok']) $fail($r);
		$done[] = $r['message'];
		$warnings = array_merge($warnings, $r['warnings']);
	} elseif ($d) {
		$r = deploy_delete($d['id']);
		if (!$r['ok']) $fail($r);
		$done[] = $r['message'];
	}

	if ($runtime !== 'php') {
		$rt = explode(':', $runtime, 2);
		$f = array('runtime' => $rt[0], 'version' => $rt[1] ?? '', 'start_cmd' => deploy_post('start_cmd', 1000),
		           'app_dir' => deploy_post('app_dir', 200), 'serve_static' => !empty($_POST['serve_static']),
		           'memory_mb' => (int)deploy_post('memory_mb', 6));
		if ($a)
			$r = app_edit($a['id'], $f);
		else  /* with Git the code comes with the first deploy, which starts it */
			$r = app_create(array('domain' => $domain, 'start' => !$git) + $f, auth_user());
		if (!$r['ok']) $fail($r);
		$done[] = $r['message'];
		$warnings = array_merge($warnings, $r['warnings']);
	} elseif ($a) {
		$r = app_delete($a['id']);
		if (!$r['ok']) $fail($r);
		$done[] = $r['message'];
	}
	if ($new_dep)
		$done[] = 'Next: connect the repository, then press Deploy.';
	result_redirect($base.($new_dep ? '?connect='.$new_dep : ''), result_ok(implode(' ', $done), array(), $warnings));
}
