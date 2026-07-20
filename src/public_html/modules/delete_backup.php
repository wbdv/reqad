<?
	$errmsg 	= '';
	$successmsg = '';

	if(isset($_POST['filename'])) {
		$d = $_POST["filename"];
		if(preg_match('/backup_[a-z0-9]+_[a-z0-9\-\_]+\.tar\.gz/', $d) && is_file('/usr/local/reqad/backup/'.$d)) {
			@unlink('/usr/local/reqad/backup/'.$d);
			#die('rm -f /usr/local/reqad/backup/'.$d);
			if(is_file('/usr/local/reqad/backup/'.$d))
				shell_exec('rm -f /usr/local/reqad/backup/'.$d);
			$successmsg = "Backup file $d was deleted.";
		} else {
			$errmsg = "Invalid backup filename.";
		}
	}

	/* Post/Redirect/Get: carry the message via the queue and 302 to the GET page
	   so a browser refresh does not re-submit the delete. */
	$msg_base = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/backup/';
	if($errmsg != '')
		msg_redirect($msg_base, $errmsg, 'error');
	else
		msg_redirect($msg_base, $successmsg, 'success');
?>