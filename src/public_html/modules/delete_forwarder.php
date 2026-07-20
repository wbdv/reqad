<?php
$forwarder	 = trim($_POST["forwarder"]);

$errmsg 	= '';
$successmsg = '';

#echo '<pre>'; print_r($_POST); exit;
list($user, $domain) = explode('@', $forwarder);

if(!preg_match('/[a-z0-9]+[a-z0-9\-\.]*[a-z0-9]+\.[a-z]{2,}/', $domain)) {
	$errmsg = "Error: Domain name is wrong, please check what you selected.";
} else if(!is_file("/etc/exim/forwards/".$domain)) {
	$errmsg = "Error: Missing /etc/exim/forwards/$domain file";
} else if(!preg_match('/[A-Za-z0-9\+\-_\.]{1,32}/', $user)) {
	$errmsg = "Error: User part of forwarder is wrong";
}

if($errmsg == '') {
	$users = explode("\n", trim(shell_exec('sudo cat /etc/exim/forwards/'.$domain.' | awk -F\':\' {\'print $1\'}')));
	if(!in_array($user, $users)) {
		$errmsg = "Error: forwarder $forwarder does not exists in /etc/exim/forwards/$domain";
	}
}

if($errmsg == '') {
    // All ok, chnage password
    error_log(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$_SERVER["REMOTE_ADDR"]." ".$_SERVER['USER']." delete forwarder $forwarder\n", 3, '../log/route_log');
	shell_exec('sudo sed -i \'/^'.$user.':/d\' /etc/exim/forwards/'.$domain);
	$successmsg = "Forwarder $forwarder was deleted.";
}

/* Post/Redirect/Get: carry the message via the queue and 302 to the GET page
   so a browser refresh does not re-submit the form. */
$msg_base = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/forwarders/';
if($errmsg != '')
	msg_redirect($msg_base, $errmsg, 'error');
else
	msg_redirect($msg_base, $successmsg, 'success');
?>