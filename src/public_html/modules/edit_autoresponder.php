<?php
/* Email > Autoresponders: change (autoresponder_edit(), app/functions/emails.php).
   The address comes from the saved row, never from the form. */
$r = autoresponder_edit(array(
	'id'        => (int)($_POST["id"] ?? 0),
	'subject'   => (string)($_POST["subject"] ?? ''),
	'message'   => (string)($_POST["message"] ?? ''),
	'date_from' => (string)($_POST["date_from"] ?? ''),
	'date_to'   => (string)($_POST["date_to"] ?? ''),
));
result_redirect($_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/autoresponders/', $r);
