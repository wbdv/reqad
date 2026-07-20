<?
	$errmsg 	= '';
	$successmsg = '';

	if(!isset($_POST['filename']) || trim($_POST['filename']) === '') {
		/* No file selected / field never posted — show a real error instead of
		   falling through to the empty "info" redirect at the bottom. */
		$errmsg = "Please select a backup file to restore.";
	} else {
		$d = trim($_POST["filename"]);
		if(preg_match('/^backup_([a-z0-9]+)_[a-z0-9\-\_]+\.tar\.gz$/', $d, $m) && is_file('/usr/local/reqad/backup/'.$d)) {
			$user = $m[1];
			/* The restore is backgrounded, so restore.sh's own "refuse to overwrite a
			   live account" guard would only surface later via the async toast. Do a
			   synchronous pre-check here so the user gets immediate feedback. */
			if(trim(shell_exec('id -u '.escapeshellarg($user).' 2>/dev/null')) !== '') {
				$errmsg = "Cannot restore because system user '$user' already exists.";
			} else {
				$archive = '/usr/local/reqad/backup/'.$d;
				/* Background the restore (it can take a while for large accounts) and let
				   it post its result to db/messages.db under this token; the Backup page
				   polls ajax-msg and shows a toast when it finishes — same pattern as the
				   Let's Encrypt issuance flow. */
				$token = bin2hex(random_bytes(8));
				shell_exec('/usr/local/reqad/scripts/restore.sh '.escapeshellarg($archive).' '.$token.' >> /usr/local/reqad/log/restore.log 2>&1 &');
				$restoremsg = $token;
				$successmsg = "Restore started for $d. You'll be notified when it finishes.";
			}
		} else {
			$errmsg = "Invalid backup filename.";
		}
	}

	/* Post/Redirect/Get: carry the immediate flash via the queue, and (on success)
	   the poll token in ?restoremsg= so the page can show the async result. */
	$msg_base = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/backup/';
	if($errmsg != '') {
		msg_redirect($msg_base, $errmsg, 'error');
	} else {
		msg_redirect($msg_base.'?restoremsg='.$restoremsg, $successmsg, 'info');
	}
?>
