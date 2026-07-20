<?php
$user     	 = trim($_POST["user"]);
$domain   	 = trim($_POST["domain"]);
$old_forward = trim($_POST["old_forward"]);
$forward 	 = str_replace(',', ', ', str_replace(' ', '', trim($_POST["forward"])));
$pipe 		 = trim($_POST["pipe"]);
$email 		 = $user.'@'.$domain;
list($old_user, $old_domain) = explode('@', $old_forward);

$errmsg 	= '';
$successmsg = '';

#echo '<pre>'; print_r($_POST); exit;

#$m = preg_match('/^[A-Za-z0-9\+\-_\.]{1,32}$/', $user, $matches);
#echo '<pre>'; var_dump($matches); if ($m==false) echo 'false'; exit;

if(!preg_match('/^[A-Za-z0-9\+\-_\.]{1,32}$/', $user)) {
	$errmsg = "Error: New user conains unallowed characters.";
} else if(!preg_match('/^[a-z0-9\-\.]{2,32}\.[a-z]{2,10}$/', $domain)) {
	$errmsg = "Error: New domain conains unallowed characters.";
} else if(!preg_match('/^[A-Za-z0-9\+\-_\.]{1,32}$/', $old_user)) {
	$errmsg = "Error: Old (existing) user conains unallowed characters.";
} else if(!preg_match('/[a-z0-9\-\.]{2,32}\.[a-z]{2,10}/', $old_domain)) {
	$errmsg = "Error: Old (existing) domain conains unallowed characters.";
} else if(!is_file("/etc/exim/forwards/".$old_domain)) {
	$existing_forwards = explode("\n", shell_exec("sudo awk -F: {'print $1'} /etc/exim/forwards/".$old_domain));
	if(in_array($old_user, $existing_forwards))
		$errmsg = "Error: Old forward $old_forward does not exists.";
}
if($errmsg == '' && $old_forward!=$email) {
	$existing_forwards = explode("\n", shell_exec("sudo awk -F: {'print $1'} /etc/exim/forwards/".$domain));
	if(in_array($user, $existing_forwards))
		$errmsg = "Error: User $email already has an forwarder, please edit existing one instead of adding a new one.";
}
if($errmsg == '') {
	if(!preg_match('/[A-Za-z0-9\+\-_\.]{1,32}@[a-z0-9\-\.]{2,32}\.[a-z]{2,10}/', $forward) && $forward!='') {
		$errmsg = "Error: Forwarder should contain at least one email address.";
	}
}

if($errmsg == '') {
	if($pipe!='' && !is_executable($pipe)) {
		$errmsg = "Error: Pipe should point to an executable file.";
	}
}

if($errmsg == '') {
    // All ok, create forwarder
	if($pipe!='') {
		if($forward!='')
			$forward.=', ';
		$forward.='|'.$pipe;
	}
    error_log(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$_SERVER["REMOTE_ADDR"]." ".$_SERVER['USER']." edit forwarder $old_forwarder ==> $email -> $forward\n", 3, '../log/route_log');
	shell_exec('sudo sed -i \'/^'.$old_user.':/d\' /etc/exim/forwards/'.$old_domain);
	shell_exec('echo "'.$user.": ".$forward.'" | sudo tee --append /etc/exim/forwards/'.$domain);
	if($old_forward == $email)
		$successmsg =  "Forwarder $email to $forward successfully updated.";
	else
		$successmsg =  "Forwarder $email to $forward successfully created. Old forwarder $old_forward was deleted.";
}

/* Post/Redirect/Get: carry the message via the queue and 302 to the GET page
   so a browser refresh does not re-submit the form. */
$msg_base = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/forwarders/';
if($errmsg != '')
	msg_redirect($msg_base, $errmsg, 'error');
else
	msg_redirect($msg_base, $successmsg, 'success');
?>