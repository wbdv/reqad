<?php
/* Accounts > Forwarders: create. forwarder_create() (app/functions/emails.php)
   checks and writes; this only maps the form. */
$forward = trim((string)($_POST["forward"] ?? ''));
$r = forwarder_create(array(
	'email' => trim((string)($_POST["user"] ?? '')).'@'.trim((string)($_POST["domain"] ?? '')),
	'to'    => $forward,
	'pipe'  => trim((string)($_POST["pipe"] ?? '')),
));
result_redirect($_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/forwarders/', $r);
