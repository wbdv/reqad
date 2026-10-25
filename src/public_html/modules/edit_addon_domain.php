<?php
/* Change the PHP version / handler of an addon domain: addon_domain_php_set()
   in modules/functions.php does the work (also for the API). */

$domain = trim($_POST["domain"] ?? '');
$raw    = trim($_POST["phpversion"] ?? '');

if (strpos($raw, ':') !== false) {
	list($new_version, $new_handler) = explode(':', $raw, 2);
} else {
	$new_version = $raw;
	$new_handler = 'fpm'; // nginx: always fpm, the select carries no handler
}

$r = addon_domain_php_set($domain, $new_version, $new_handler);

/* Post/Redirect/Get: carry the message via the queue and 302 to the GET page
   so a browser refresh does not re-submit the form. */
$msg_base = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/addon-domains/';
if (!$r['ok'])
	msg_redirect($msg_base, 'Error: '.$r['error'], 'error');
else
	msg_redirect($msg_base, $r['message'], 'success');

?>
