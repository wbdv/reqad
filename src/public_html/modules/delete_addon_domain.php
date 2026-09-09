<?php
/* Remove an addon domain: vhost + php-fpm pool, optionally the document root.
   The work is done by scripts/deldomain, which re-checks the adddomain marker
   before it removes anything. */

$domain      = trim($_POST["domain"] ?? '');
$del_docroot = (isset($_POST["delete_docroot"]) && $_POST["delete_docroot"] == 'on');

$errmsg     = '';
$successmsg = '';

$addon = addon_domain_get($ini, $domain);
if ($addon === null)
	$errmsg = "Error: '".$domain."' is not an addon domain on this server.";

if ($errmsg == '') {
	error_log(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$_SERVER["REMOTE_ADDR"]." ".$_SERVER['USER'].
	          " delete addon domain $domain".($del_docroot ? ' (with document root)' : '')."\n", 3, '../log/route_log');

	$cmd = 'sudo '.__DIR__.'/../../scripts/deldomain --domain='.escapeshellarg($domain);
	if ($del_docroot)
		$cmd .= ' --delete-docroot';
	$output = shell_exec($cmd.' 2>&1');

	if (is_file(addon_vhost_path($ini, $domain)))
		$errmsg = "Error: Addon domain ".$domain." could not be removed: ".trim((string)$output);
	else
		$successmsg = "Addon domain ".$domain." was removed".($del_docroot ? ", together with its document root." : ". Its files in ".$addon['docroot']." were kept.");
}

/* Post/Redirect/Get: carry the message via the queue and 302 to the GET page
   so a browser refresh does not re-submit the form. */
$msg_base = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/addon-domains/';
if ($errmsg != '')
	msg_redirect($msg_base, $errmsg, 'error');
else
	msg_redirect($msg_base, $successmsg, 'success');

?>
