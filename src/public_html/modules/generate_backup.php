<?
	$user   	= isset($_POST['user'])?trim($_POST["user"]):'';
	$website 	= (isset($_POST['website'])  && $_POST['website']=='on') ?'-w':'';
	$email 		= (isset($_POST['email'])    && $_POST['email']=='on')   ?'-m':'';
	$database 	= (isset($_POST['database']) && $_POST['database']=='on')?'-d':'';

	$errmsg 	= '';
	$successmsg = '';

	if( $user == '') {
		$errmsg = 'No user was specified.';
	} else if( $website == '' && $email == '' && $database == '') {
		$errmsg = 'Select at least one thing to back up (website, email or databases).';
	} else {
		shell_exec("/usr/local/reqad/scripts/backup.sh $user $website $email $database >> /usr/local/reqad/log/backup.log 2>&1 &");
		sleep(5);
		$successmsg = "Backup on progress for user $user.";
	}

	/* Post/Redirect/Get: carry the message via the queue and 302 to the GET page
	   so a browser refresh does not re-submit the form. */
	$msg_base = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/backup/';
	if($errmsg != '')
		msg_redirect($msg_base, $errmsg, 'error');
	else
		msg_redirect($msg_base, $successmsg, 'success');
?>