<?php
/* Email > Autoresponders: create. autoresponder_create()
   (app/functions/emails.php) checks, saves and turns it on when its dates
   say so; this only maps the form. */
$r = autoresponder_create(array(
	'email'     => trim((string)($_POST["user"] ?? '')).'@'.trim((string)($_POST["domain"] ?? '')),
	'subject'   => (string)($_POST["subject"] ?? ''),
	'message'   => (string)($_POST["message"] ?? ''),
	'date_from' => (string)($_POST["date_from"] ?? ''),
	'date_to'   => (string)($_POST["date_to"] ?? ''),
));
result_redirect($_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/autoresponders/', $r);
