<?php
/* Accounts > Forwarders: delete (forwarder_delete(), app/functions/emails.php). */
$r = forwarder_delete(trim((string)($_POST["forwarder"] ?? '')));
result_redirect($_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/forwarders/', $r);
