<?php
/* Email > Autoresponders: delete (autoresponder_delete(), app/functions/emails.php). */
$id = (int)($_POST["id"] ?? 0);
$r = $id > 0 ? autoresponder_delete($id) : result_error('Invalid autoresponder ID.');
result_redirect($_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/autoresponders/', $r);
