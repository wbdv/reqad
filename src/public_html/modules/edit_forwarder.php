<?php
/* Accounts > Forwarders: change (the address may change too). forwarder_edit()
   (app/functions/emails.php) checks and writes; this only maps the form. The
   form always sends the pipe field, so '' removes a pipe. */
$r = forwarder_edit(array(
	'email'     => trim((string)($_POST["old_forward"] ?? '')),
	'new_email' => trim((string)($_POST["user"] ?? '')).'@'.trim((string)($_POST["domain"] ?? '')),
	'to'        => trim((string)($_POST["forward"] ?? '')),
	'pipe'      => trim((string)($_POST["pipe"] ?? '')),
));
result_redirect($_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/forwarders/', $r);
