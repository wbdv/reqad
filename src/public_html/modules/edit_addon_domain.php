<?php
/* Change the PHP version / handler of an addon domain (created with
   scripts/adddomain). Same mechanics as the account PHP switch in
   edit_account.php — move/create/delete the pool file, restart the affected
   php-fpm services — except the pool is named after the addon domain and
   listens on its own per-domain socket. */

$domain = trim($_POST["domain"] ?? '');
$raw    = trim($_POST["phpversion"] ?? '');

if (strpos($raw, ':') !== false) {
	list($new_version, $new_handler) = explode(':', $raw, 2);
} else {
	$new_version = $raw;
	$new_handler = 'fpm'; // nginx: always fpm, the select carries no handler
}

$errmsg     = '';
$successmsg = '';

$is_apache    = addon_is_apache($ini);
$php_versions = array_map('trim', explode(',', $ini['php_versions']));

/* addon_domain_get() returns null for anything that is not an addon vhost, so a
   crafted POST cannot aim this at a main account domain. */
$addon = addon_domain_get($ini, $domain);
if ($addon === null)
	$errmsg = "Error: '".$domain."' is not an addon domain on this server.";

if ($errmsg == '' && !in_array($new_version, $php_versions, true))
	$errmsg = "Error: PHP ".$new_version." is not installed on this server.";

if ($errmsg == '' && !in_array($new_handler, array('fpm', 'mod_php'), true))
	$errmsg = "Error: Unknown PHP handler.";

if ($errmsg == '' && !$is_apache && $new_handler != 'fpm')
	$errmsg = "Error: nginx cannot run mod_php.";

/* mod_php is the apache module — it only ever runs the server's default PHP. */
if ($errmsg == '' && $new_handler == 'mod_php' && $new_version != $ini['php'])
	$errmsg = "Error: mod_php is only available for PHP ".$ini['php'].". Use php-fpm for PHP ".$new_version.".";

if ($errmsg == '') {
	$user            = $addon['user'];
	$old_version     = $addon['version'];
	$old_handler     = $addon['handler'];
	$version_changed = ($new_version != $old_version);
	$handler_changed = ($new_handler != $old_handler);

	if (!$version_changed && !$handler_changed) {
		$successmsg = "Addon domain ".$domain." already runs PHP ".$new_version.
		              ($is_apache ? " (".($new_handler == 'fpm' ? 'php-fpm' : 'mod_php').")" : "").".";
	} else {
		error_log(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$_SERVER["REMOTE_ADDR"]." ".$_SERVER['USER'].
		          " edit addon domain $domain: PHP $old_version/$old_handler -> $new_version/$new_handler\n", 3, '../log/route_log');

		$old_pool = ($old_handler == 'fpm') ? $addon['pool'] : null;
		$new_pool = ($new_handler == 'fpm') ? addon_pool_path($ini, $domain, $new_version) : null;
		$restart  = '';

		/* apache: the vhost decides who runs PHP — with the SetHandler block it
		   goes to the pool socket, without it mod_php handles the request. */
		if ($is_apache && $handler_changed)
			addon_vhost_set_fpm($ini, $domain, $user, ($new_handler == 'fpm'));

		if ($old_pool === null && $new_pool !== null) {
			addon_write_root_file($new_pool, addon_fpm_pool_content($ini, $domain, $user));
		} elseif ($old_pool !== null && $new_pool === null) {
			shell_exec('sudo rm -f '.escapeshellarg($old_pool));
		} elseif ($old_pool !== null && $new_pool !== null && $old_pool !== $new_pool) {
			/* different PHP version: the pool just moves to the other version's
			   php-fpm.d — the socket path (and so the vhost) is unchanged */
			shell_exec('sudo mv '.escapeshellarg($old_pool).' '.escapeshellarg(dirname($new_pool).'/'));
		}

		if ($old_pool !== null)
			$restart .= addon_fpm_service($ini, $old_version).' ';
		if ($new_pool !== null && $new_pool !== $old_pool)
			$restart .= addon_fpm_service($ini, $new_version).' ';
		/* apache needs a restart whenever the vhost changed; nginx keeps the same
		   upstream socket, so it only needs one when nothing else did. */
		if ($is_apache && $handler_changed)
			$restart .= 'httpd.service';

		$restart = trim($restart);
		if ($restart != '')
			shell_exec(__DIR__.'/../../scripts/restart_services.sh '.$restart.' 2>/dev/null >/dev/null &');

		$successmsg = "Addon domain ".$domain." now runs PHP ".$new_version.
		              ($is_apache ? " (".($new_handler == 'fpm' ? 'php-fpm' : 'mod_php').")" : "").".";
	}
}

/* Post/Redirect/Get: carry the message via the queue and 302 to the GET page
   so a browser refresh does not re-submit the form. */
$msg_base = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/addon-domains/';
if ($errmsg != '')
	msg_redirect($msg_base, $errmsg, 'error');
else
	msg_redirect($msg_base, $successmsg, 'success');

?>
