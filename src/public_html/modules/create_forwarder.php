<?php
$user     	= trim($_POST["user"]);
$domain   	= trim($_POST["domain"]);
$forward 	= str_replace(',', ', ', str_replace(' ', '', trim($_POST["forward"])));
$pipe 	 	= trim($_POST["pipe"]);
$email 		= $user.'@'.$domain;

$errmsg 	= '';
$successmsg = '';

#echo '<pre>'; print_r($_POST); exit;

if(!preg_match('/[A-Za-z0-9\+\-_\.]{1,32}/', $user)) {
	$errmsg = "Error: New user conains unallowed characters.";
} else if(!preg_match('/[a-z0-9\-\.]{2,32}\.[a-z]{2,10}/', $domain)) {
	$errmsg = "Error: New domain conains unallowed characters.";
} else if(is_file("/etc/exim/forwards/".$domain)) {
	$existing_forwards = explode("\n", shell_exec("sudo awk -F: {'print $1'} /etc/exim/forwards/".$domain));
	if(in_array($user, $existing_forwards))
		$errmsg = "Error: User $user already has an forwarder, please edit existing one instead of adding a new one.";
}
if($errmsg == '') {
	if(!preg_match('/[A-Za-z0-9\+\-_\.]{1,32}@[a-z0-9\-\.]{2,32}\.[a-z]{2,10}.*/', $forward) && $forward!='') {
		$errmsg = "Error: Forwarder should contain at least one email address.";
	}
}

if($errmsg == '') {
	if($pipe!='' && !is_executable($pipe)) {
		$errmsg = "Error: Pipe should point to an executable file.";
	}
}

/*
// TODO save forwarder in sqlite database and check there instead

if($errmsg == '') {
        $results = $db->query('SELECT * FROM accounts WHERE user="'.$user.'"');
        if ($row = $results->fetchArray()) {
            $errmsg =  "Error: Username already exists (UID=".$row["id"]."). Please choose a different one.";
        }
}
*/

if($errmsg == '') {
    // All ok, create forwarder
	if($pipe!='') {
		if($forward!='')
			$forward.=', ';
		$forward.='|'.$pipe;
	}
    error_log(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$_SERVER["REMOTE_ADDR"]." ".$_SERVER['USER']." create forwarder $email -> $forward\n", 3, '../log/route_log');
	shell_exec('echo "'.$user.": ".$forward.'" | sudo tee --append /etc/exim/forwards/'.$domain);
	$successmsg =  "Forwarder $email to $forward successfully created.";
}

/* Post/Redirect/Get: carry the message via the queue and 302 to the GET page
   so a browser refresh does not re-submit the form. */
$msg_base = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/forwarders/';
if($errmsg != '')
	msg_redirect($msg_base, $errmsg, 'error');
else
	msg_redirect($msg_base, $successmsg, 'success');
?>
