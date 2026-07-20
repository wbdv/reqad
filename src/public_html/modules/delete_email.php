<?php
$email    = trim($_POST["email"]);
$domain   = substr($email, strpos($email, '@')+1, 99);
$user 	  = substr($email, 0, strpos($email, '@'));
$sysuser  = trim(shell_exec('sudo grep -E "^'.$domain.':" /etc/exim/userdomains | awk -F\':\' {\'print $2\'} '));

error_log(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$_SERVER["REMOTE_ADDR"]." ".$_SERVER['USER']." delete email $email\n", 3, '../log/route_log');

/*
$disk_quota = 1024; // MB
if(isset($_POST["disk_quota"]))
	$disk_quota = (int)($_POST["disk_quota"]);
// quota disabled, set 0 (unlimited) so on usage will display entire disk
if($ini["quota"]==0)
	$disk_quota = 0;
*/

$errmsg 	= '';
$successmsg = '';

#echo '<pre>'; print_r($_POST); exit;

if(!preg_match('/[A-Za-z0-9\+\-_]{1,16}/', $user)) {
	$errmsg = "Error: Email must be unique, 1-64 characters long, contain letters, numbers, dashes and underscores.";
} else if(!preg_match('/[a-z0-9]+[a-z0-9\-\.]*[a-z0-9]+\.[a-z]{2,}/', $domain)) {
	$errmsg = "Error: Domain name is wrong, please check what you selected.";
} else {
	$domains = explode("\n", trim(shell_exec("sudo ls -1 /etc/exim/domains/")));
	if(!in_array($domain, $domains)) {
		$errmsg = "Error: domain $domain not found in /etc/exim/domains/";
	}
}

if($errmsg == '') {
	$emails = explode("\n", trim(shell_exec('sudo cat /etc/dovecot/users | awk -F\':\' {\'print $1\'}')));
	if(!in_array($email, $emails)) {
		$errmsg = "Error: email $email does not exists in /etc/dovecot/users";
	}
}

if($errmsg == '') {
	if($sysuser == '') {
		$errmsg = "Error: domain $domain does not exists in /etc/exim/userdomains";
	}
}

if($errmsg == '') {
    // All ok, chnage password
    error_log(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$_SERVER["REMOTE_ADDR"]." ".$_SERVER['USER']." delete email $email\n", 3, '../log/route_log');

	shell_exec('sudo sed -i \'/^'.$email.':/d\' /etc/dovecot/users');
	shell_exec('sudo sed -i \'/^'.$user.'$/d\' /etc/exim/domains/'.$domain);
	shell_exec('sudo rm -rf /home/'.$sysuser.'/mail/'.$domain.'/'.$user);
	$successmsg = "Email account $email was deleted.";
}

/* Post/Redirect/Get: carry the message via the queue and 302 to the GET page
   so a browser refresh does not re-submit the form. */
$msg_base = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/email-accounts/';
if($errmsg != '')
	msg_redirect($msg_base, $errmsg, 'error');
else
	msg_redirect($msg_base, $successmsg, 'success');
?>