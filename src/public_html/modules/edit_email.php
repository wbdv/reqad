<?php
$email    = trim($_POST["email"]);
$password = trim($_POST["password"]);
$domain   = substr($email, strpos($email, '@')+1, 99);
$user 	  = substr($email, 0, strpos($email, '@'));
$sysuser  = trim(shell_exec('sudo grep -E "^'.$domain.':" /etc/exim/userdomains | awk -F\':\' {\'print $2\'} '));

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

if(!valid_email_user($user)) {
	$errmsg = "Error: Email must be unique, 1-64 characters long, contain letters, numbers, dashes and underscores.";
} else if(!valid_domain($domain)) {
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
	} else {
		$uid = (int)(trim(shell_exec('sudo id -u '.$sysuser)));
		$gid = (int)(trim(shell_exec('sudo id -g '.$sysuser)));
		if($uid==0 || $gid=='')
			$errmsg = "Error: user $sysuser does not exists on system (no ID / GID found).";
	}
}

/* The password is optional here: the same form also carries the enable/disable
   toggle, so an empty field means "leave the password alone" and only apply the
   toggle to the hash already on file. */
if($errmsg == '' && $password != '') {
    // TODO check password strength
    if(strlen($password)<8) {
        $errmsg =  "Error: Password should be at least 8 characters long.";
    }
}

$enabled = isset($_POST["login_enabled"]) && $_POST["login_enabled"] == '1';
$oldhash = mailbox_hash($email);

if($errmsg == '' && $password == '' && ltrim($oldhash, '!') == '') {
	$errmsg = "Error: no password on file for $email, please set one.";
}

if($errmsg == '') {
    // All ok, chnage password
    error_log(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$_SERVER["REMOTE_ADDR"]." ".$_SERVER['USER']." edit email $email\n", 3, '../log/route_log');

	if($password != '')
		$hash = crypt($password, '$6$'.substr(str_shuffle("./ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijkl..mnopqrstuvwxyz012345..6789"), 0, 8));
	else
		$hash = ltrim($oldhash, '!');   // keep the stored password, toggle only

	/* Disabled accounts keep their hash behind a '!' — see mailbox_login_disabled(). */
	if(!$enabled)
		$hash = '!'.$hash;

  	shell_exec('sudo sed -i \'/^'.$email.':/d\' /etc/dovecot/users');
    shell_exec('echo \''.$email.':'.$hash.':'.$uid.':'.$gid.'::/home/'.$sysuser.'/mail/'.$domain.'/'.$user.'::userdb_mail=maildir:~/\' | sudo tee --append /etc/dovecot/users');

	/* Same Active/Disabled wording as the Status column and the modal switch. */
	$state       = $enabled ? "Active" : "Disabled";
	$was_enabled = !mailbox_login_disabled($oldhash);
	if($password != '')
		$successmsg = "Password changed successfully for $email.";
	else
		$successmsg = "Email account $email is now $state.";
	if($password != '' && $was_enabled != $enabled)
		$successmsg .= " The account is now $state.";
}

/* Post/Redirect/Get: carry the message via the queue and 302 to the GET page
   so a browser refresh does not re-submit the form. */
$msg_base = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/email-accounts/';
if($errmsg != '')
	msg_redirect($msg_base, $errmsg, 'error');
else
	msg_redirect($msg_base, $successmsg, 'success');
?>