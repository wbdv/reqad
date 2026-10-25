<?php
/* Add an addon domain or subdomain to an account: domain_addon_add() in
   app/functions/domains.php does the work (same as the API and 2083). */

$account = trim($_POST["account"] ?? '');
$domain  = trim($_POST["domain"] ?? '');
$folder  = trim($_POST["folder"] ?? '');
$www     = (($_POST["www"] ?? '') === 'on');
$mail    = (($_POST["mail"] ?? '') === 'on');

$r = domain_addon_add($account, $domain, $folder, $www, $mail);

/* Post/Redirect/Get */
result_redirect($_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/addon-domains/', $r);

?>
