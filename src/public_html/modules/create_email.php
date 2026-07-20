<?php

$user     	 = trim($_POST["user"]);
$domain   	 = trim($_POST["domain"]);
$password 	 = trim($_POST["password"]);
$email 		 = $user.'@'.$domain;

// quota disabled, set 0 (unlimited) so on usage will display entire disk
$disk_quota = 0;
if(isset($_POST["disk_quota"]))
	$disk_quota = (int)($_POST["disk_quota"]);
if($ini["quota"]==0)
	$disk_quota = 0;

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
	} else {
		$emails = explode("\n", trim(shell_exec("sudo cat /etc/exim/domains/".$domain)));
		if(in_array($user, $emails)) {
			$errmsg = "Error: email $user@$domain already exists in /etc/exim/domains/".$domain;
		}
	}
}

if($errmsg == '') {
	$emails = explode("\n", trim(shell_exec('sudo cat /etc/dovecot/users | awk -F\':\' {\'print $1\'}')));
	if(in_array($email, $emails)) {
   		$errmsg = "Error: email $email already exists in /etc/dovecot/users";
	}
}

/*
// TODO save email accounts in sqlite database and check there instead

if($errmsg == '') {
        $results = $db->query('SELECT * FROM accounts WHERE user="'.$user.'"');
        if ($row = $results->fetchArray()) {
            $errmsg =  "Error: Username already exists (UID=".$row["id"]."). Please choose a different one.";
        }
}
*/

if($errmsg == '') {
    // TODO check password strength
    if(strlen($password)<8) {
        $errmsg =  "Error: Password should be at least 8 characters long.";
    }
#    if(strpos($password, ':') !== false || strpos($password, '"') !== false || strpos($password, "'") !== false) {
#        $errmsg =  "Error: Password cannot contains : \" or '";
#    }
}

if($errmsg == '') {
    // All ok, create email account
    error_log(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$_SERVER["REMOTE_ADDR"]." ".$_SERVER['USER']." create email $email\n", 3, '../log/route_log');
	$password = crypt($password, '$6$'.substr(str_shuffle("./ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijkl..mnopqrstuvwxyz012345..6789"), 0, 8));
	$sysuser = trim(shell_exec('sudo grep "'.trim($domain).':" /etc/exim/userdomains | awk -F\':\' {\'print $2\'} '));
	$uid = (int)(trim(shell_exec('sudo id -u '.$sysuser)));
	$gid = (int)(trim(shell_exec('sudo id -g '.$sysuser)));
	shell_exec('echo \''.$email.':'.$password.':'.$uid.':'.$gid.'::/home/'.$sysuser.'/mail/'.$domain.'/'.$user.'::userdb_mail=maildir:~/\' | sudo tee --append /etc/dovecot/users');
	shell_exec('echo "'.$user.'" | sudo tee --append /etc/exim/domains/'.$domain);
	$successmsg =  "Email account $email successfully created.";
}

/* Post/Redirect/Get: carry the message via the queue and 302 to the GET page
   so a browser refresh does not re-submit the form. */
$msg_base = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/email-accounts/';
if($errmsg != '')
	msg_redirect($msg_base, $errmsg, 'error');
else
	msg_redirect($msg_base, $successmsg, 'success');
?>
