<?php
	// todo move to modules/download_backup.php
	if(isset($_GET["download"])) {
		$d = $_GET["download"];
		if(preg_match('/backup_[a-z0-9]+_[a-z0-9\-\_]+\.tar\.gz/', $d) && is_file('/usr/local/reqad/backup/'.$d)) {
			$filesize = filesize('/usr/local/reqad/backup/'.$d);
			header("Cache-Control: public, must-revalidate\n");
			header("Pragma: hack\n");
			header("Expires: " . gmdate("D, d M Y H:i:s", mktime(date("H") + 2, date("i"), date("s"), date("m"), date("d"), date("Y"))) . " GMT\n");
			header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
			header("Content-Type: application/gzip\n");
			header("Content-Length: " . $filesize . "\n");
			header("Content-Disposition: attachment; filename=\"" . $d . "\"\n");
			header("Content-Transfer-Encoding: binary");
			echo readfile('/usr/local/reqad/backup/'.$d);
			exit;
		} else {
			/* invalid/missing file in download link — flash via PRG (no output yet) */
			msg_redirect($_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/backup/', "Invalid backup filename.", 'error');
		}
	}

	include('templates/header.php'); 
?>
        <!-- Page title -->
        <div class="page-header d-print-none">
            <div class="row align-items-center">
            	<div class="col" style="padding-left:22px;">
					<!-- Page pre-title -->
					<div class="page-pretitle">
						Backup
					</div>
					<h2 class="page-title">
						Full Backup
					</h2>
              	</div>			
              	<div class="col-auto ms-auto d-print-none">
                	<div class="btn-list">
                  		<a href="#" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal-generate-backup">
                    		<svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    		Generate backup
                  		</a>
                  		<a href="#" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#modal-restore-backup">
                    		<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-restore"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3.06 13a9 9 0 1 0 .49 -4.087" /><path d="M3 4.001v5h5" /><path d="M12 12m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" /></svg>
                    		Restore account
                  		</a>
                </div>
              </div>
            </div>
        </div>

<?php msg_render(); /* flash message (PRG) — rendered once, shown even with 0 backups */ ?>
<div id="restore-toast"></div>
<?php
	/* A backgrounded restore redirects here with ?restoremsg=<token>; restore.sh
	   posts its result to messages.db under that token — polled below. */
	$restore_msgtoken = (isset($_GET['restoremsg']) && preg_match('/^[0-9a-f]{16}$/', $_GET['restoremsg'])) ? $_GET['restoremsg'] : '';
?>
<?
     $results = $db->query('SELECT user,domain FROM accounts');
	 $domains = array();
     while ($row = $results->fetchArray()) {
		$row_user = $row['user'];
		$domains[$row_user] = $row['domain'];
	}
	asort($domains);

	$backup = array();
	$list = shell_exec("cd ~/backup && ls -lh *.gz | awk {'print $3 \"|\" $5 \"|\" $9'}");
	$line = strtok($list, PHP_EOL);
	while ($line !== false) {
		$l = explode('|', $line);
		#print_r($l);
		$backup[] = $l;
    	$line = strtok(PHP_EOL);
	}

	if(count($backup)==0) {
?>
		<div class="alert alert-info" role="alert" style="background:#EEF0FF;">
			<div class="text">No backup exists, you need to <a href="#" data-bs-toggle="modal" data-bs-target="#modal-generate-backup">geneate a new backup</a> first.</div>
		</div>
<?
	} else {
?>
		<div class="col-12">
            <div class="card">
                <div class="table-responsive">
                  <table class="table table-vcenter card-table table-nowrap">
                    <thead>
                      <tr>
                        <th class="w-8" style="background-color:#DEF;">Backup</th>
						<th class="w-1" style="background-color:#DEF;">Size</th>
						<th class="w-2" style="background-color:#DEF;">Status</th>
                        <th class="w-5" style="background-color:#DEF;"></th>
                      </tr>
                    </thead>
                    <tbody>
					<?						
						foreach($backup as $b) {
							if($b[0]=='root') {
					?>
					<tr>
						<td><?=$b[2];?></td>
						<td><?=$b[1];?></td>
						<td><span class="badge bg-orange">backup in progress</span></td>
						<td>
                          <div class="btn-list flex-nowrap">
                            <a href="/backup/?download=<?=rawurlencode($b[2]);?>" class="btn btn-white btn-md disabled">
								<svg  xmlns="http://www.w3.org/2000/svg"  width="24"  height="24"  viewBox="0 0 24 24"  fill="none"  stroke="currentColor"  stroke-width="2"  stroke-linecap="round"  stroke-linejoin="round"  class="icon icon-tabler icons-tabler-outline icon-tabler-download"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2" /><path d="M7 11l5 5l5 -5" /><path d="M12 4l0 12" /></svg>
								Download</a>
                            <a href="#" class="btn btn-white btn-md" data-bs-toggle="modal" data-bs-target="#modal-delete-backup" data-bs-file="<?=$b[2];?>">Delete</a>
                          </div>
						</td>
					</tr>
					<?
							} else {
					?>
					<tr>
						<td><a href="/backup//?download=<?=rawurlencode($b[2]);?>"><?=htmlspecialchars($b[2]);?></a></td>
						<td><?=$b[1];?></td>
						<td><span class="badge bg-success">completed</span></td>
						<td>
                          <div class="btn-list flex-nowrap">
                            <a href="/backup/?download=<?=rawurlencode($b[2]);?>" class="btn btn-white btn-md">
								<svg  xmlns="http://www.w3.org/2000/svg"  width="24"  height="24"  viewBox="0 0 24 24"  fill="none"  stroke="currentColor"  stroke-width="2"  stroke-linecap="round"  stroke-linejoin="round"  class="icon icon-tabler icons-tabler-outline icon-tabler-download"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2" /><path d="M7 11l5 5l5 -5" /><path d="M12 4l0 12" /></svg>
								Download</a>
                            <a href="#" class="btn btn-white btn-md" data-bs-toggle="modal" data-bs-target="#modal-delete-backup" data-bs-file="<?=$b[2];?>">Delete</a>
                          </div>
						</td>
					</tr>
					<?
							}
						}
					?>
					</tbody>
				  </table>
				</div>
            </div>
          </div>
<?	}  ?>		  
        </div>
      </div>
    </div>

	<form method="post" action="/" id="generate-backup" class="needs-validation" novalidate>
    <input type="hidden" name="action" value="generate-backup">
    <div class="modal modal-blur fade" id="modal-generate-backup" tabindex="-1" role="dialog" aria-hidden="true">
      <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Generate a new backup</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body" id="modal-body-generate-backup">
			<? /* <div class="alert alert-info" role="alert" style="background:#EEF0FF;"> 
				<div class="text"><b>Note:</b> Please ensure that you have enough free disk space before generating a new backup.</div>
			</div>*/ ?>
			<p>Select the domain name you want to backup:</p>

<? /*		TODO search
            <div class="mb-3">
            	<label class="form-label">Domain name and user</label>
				<select class="form-select tomselected ts-hidden-accessible" id="user" name="user" value="" tabindex="-1">
					<option value=""></option>
					<? foreach ($domains as $user => $domain) { ?>
					<option value="<?=$user;?>"><?=$domain .' - '.$user;?></option>
					<? } ?>
              	</select>
				<div class="ts-wrapper form-select single">
					<div class="ts-control">
						<input tabindex="0" role="combobox" aria-haspopup="listbox" aria-expanded="false" aria-controls="user-ts-dropdown" id="user-ts-control" aria-activedescendant="user-opt-1">
					</div>
				</div>
				<div class="invalid-feedback" id="invalid-domain">
                		Please select a domain name.
          		</div>
            </div>
*/ ?>			
            <div class="mb-3">
            	<label class="form-label">Domain name and user</label>
				<select class="form-select" id="user" name="user" value="" tabindex="-1" required>
					<option value="" style="color:#eee">Please select a domain name / user to backup</option>
					<? foreach ($domains as $user => $domain) { ?>
					<option value="<?=$user;?>">
						<?=$domain;?> -	<?=$user;?>
					</option>
					<? } ?>
              	</select>
				<div class="invalid-feedback" id="invalid-domain">
                		Please select a domain name.
          		</div>
            </div>
			<div class="mb-3">
				<label class="form-check">
					<input class="form-check-input backup-part" type="checkbox" name="website" checked="true">
					  <span class="form-check-label">Website</span>
					  <span class="form-check-description">home directory files, web/PHP config, SSL certificates, cron jobs, DNS zone</span>
                 </label>
          	</div>
			<div class="mb-3">
				<label class="form-check">
					<input class="form-check-input backup-part" type="checkbox" name="email" checked="true">
					  <span class="form-check-label">Email</span>
					  <span class="form-check-description">mailboxes (mail folder), exim/dovecot settings, DKIM keys, email DNS records</span>
                 </label>
          	</div>
			<div class="mb-3">
				<label class="form-check">
					<input class="form-check-input backup-part" type="checkbox" name="database" checked="true">
					  <span class="form-check-label">Databases</span>
					  <span class="form-check-description">MariaDB dumps, CREATE DATABASE and user grants</span>
                 </label>
          	</div>
          </div>
          <div class="modal-footer">
            <a href="#" class="btn btn-link link-secondary" data-bs-dismiss="modal">
              Cancel
            </a>
            <button id="submit-btn" class="btn btn-primary" type="submit"><svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg> Generate backup</button>
          </div>
        </div>
      </div>
    </div>
	</form>

	<form method="post" action="/backup/" id="delete-backup" class="needs-validation" novalidate>
    <input type="hidden" name="action" value="delete-backup">
    <input type="hidden" name="filename" id="filename" value="">
    <div class="modal modal-blur fade" id="modal-delete-backup" tabindex="-1" role="dialog" aria-hidden="true">
      <div class="modal-dialog modal-sm modal-dialog-centered" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Delete backup <span id="file-title"></span></h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          <div class="modal-status bg-danger"></div>
          <div class="modal-body text-center py-4">
			<svg xmlns="http://www.w3.org/2000/svg" class="icon mb-2 text-danger icon-lg" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="#ff2825" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="12" cy="12" r="9" /><line x1="12" y1="8" x2="12" y2="12" /><line x1="12" y1="16" x2="12.01" y2="16" /></svg>
            <h3>Delete backup <span id="file-title2"></span></h3>
            <div class="text-muted">Do you really want to remove this backup file?</div>
          </div>
          <div class="modal-footer">
            <div class="w-100">
              <div class="row">
                <div class="col"><a href="#" class="btn btn-white w-100" data-bs-dismiss="modal">
                    Cancel
                </a></div>
                <div class="col"><button id="submit-btn3" class="btn btn-primary" type="submit">
					Delete backup file
				</button></div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
	</form>

	<form method="post" action="/backup/" id="restore-backup" class="needs-validation" novalidate>
    <input type="hidden" name="action" value="restore-backup">
    <div class="modal modal-blur fade" id="modal-restore-backup" tabindex="-1" role="dialog" aria-hidden="true">
      <div class="modal-dialog modal-md modal-dialog-centered" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Restore an account</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-status bg-primary"></div>
          <div class="modal-body py-4" id="modal-body-restore-backup">
			<svg xmlns="http://www.w3.org/2000/svg" class="icon mb-2 text-primary icon-lg d-block mx-auto" width="48" height="48" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3.06 13a9 9 0 1 0 .49 -4.087" /><path d="M3 4.001v5h5" /><path d="M12 12m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" /></svg>
            <p class="text-center">Select the backup file to restore the account from:</p>
            <div class="mb-3">
				<select class="form-select" name="filename" id="restore-filename" required>
					<option value="">Select a backup file ...</option>
<?
					foreach($backup as $b) {
						if($b[0]=='root') continue;   // skip backups still in progress
?>
					<option value="<?=htmlspecialchars($b[2]);?>"><?=htmlspecialchars($b[2]);?> (<?=htmlspecialchars($b[1]);?>)</option>
<?					} ?>
				</select>
				<div class="invalid-feedback" id="invalid-restore-file">Please select a backup file.</div>
            </div>
            <div class="alert alert-info mt-3" style="background:#FFE;">
				<p>Backup archive <code>backup_&lt;user&gt;_&lt;date&gt;.tar.gz</code> should be moved into 
				<code>/usr/local/reqad/backup/</code> and it will appear here on dropdown.</p>

				<p>Restore will recreate the account (user, files, databases, mail, config, SSL, cron, DNS) from the backup.
				but only if the account does not exists (it was previously deleted). if the account still exists, the restore
				will stop and report an error without changing anything.</p>

				<p>Restoring may take a while for large accounts.</p>
			</div>
          </div>
          <div class="modal-footer">
            <div class="w-100">
              <div class="row">
                <div class="col"><a href="#" class="btn btn-white w-100" data-bs-dismiss="modal">Cancel</a></div>
                <div class="col"><button id="submit-btn-restore" class="btn btn-primary w-100" type="submit">Restore account</button></div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
	</form>
<?php
    include('templates/footer.php');
?>
<script>
jQuery(document).ready(function () {
	'use strict';

	$("#generate-backup").submit(function(event) {
		event.preventDefault();
		if ($('#user').val()=='') {
			event.stopPropagation();
			$('#user').addClass('is-invalid');
			$('#user').removeClass('was-validated');
			$("#generate-backup").removeClass('was-validated');
			$('#user').focus();
		} else {
			$('#user').removeClass('is-invalid');
			$('#user').addClass('was-validated');
			$('#submit-btn').html('Generating backup ...');
			$('#submit-btn').prop('disabled', true);
			$("#generate-backup").unbind('submit').submit();
			$('#modal-body-generate-backup').html('<div class="progress"><div class="progress-bar progress-bar-indeterminate"></div></div>');
		}
	});


	$('#delete-backup').on('show.bs.modal', function (event) {
		//console.log(event);
		// Button that triggered the modal
		var button = event.relatedTarget;
		// Extract info from data-bs-* attributes
		var file = button.getAttribute('data-bs-file');
		$('#filename').val(file);
		$('#file-title2').html(file);
  	});

	$("#delete-backup").submit(function(event) {
	//	event.preventDefault();
	//	event.stopPropagation();
		$('#submit-btn3').prop('disabled', true);
		$("#delete-backup").unbind('submit').submit();
  	});

	$("#restore-backup").submit(function(event) {
		if ($('#restore-filename').val() == '') {
			event.preventDefault();
			event.stopPropagation();
			$('#restore-filename').addClass('is-invalid');
			return;
		}
		event.preventDefault();
		$('#restore-filename').removeClass('is-invalid');
		$('#submit-btn-restore').prop('disabled', true).html('Starting restore …');
		// Submit via the NATIVE form.submit(): it serializes the form from the
		// current DOM synchronously, so the <select name="filename"> (which lives
		// inside #modal-body-restore-backup) is included. Do NOT replace that
		// modal body here — doing so removes the select before it is serialized,
		// which is why the restore posted no filename and the page showed an empty
		// "Info" flash instead of running the restore.
		this.submit();
	});

	// --- Async toast when the backgrounded restore finishes -------------------
	// The page arrives with ?restoremsg=<token>; restore.sh posts its result to
	// messages.db under that token. Poll ajax-msg until it returns the alert HTML.
	var restoreMsgToken = '<?= $restore_msgtoken ?>';
	if (restoreMsgToken !== '') {
		var rAttempts = 0, rMax = 120;        // ~10 min ceiling (120 * 5s)
		function restoreMsgPoll() {
			rAttempts++;
			$.post('/?ajax=1', { action: 'ajax-msg', token: restoreMsgToken }, null, 'json')
				.done(function (r) {
					if (r && r.html) {
						$('#restore-toast').html(r.html);
						if (window.history && history.replaceState)
							history.replaceState(null, '', window.location.pathname);
						return;
					}
					if (rAttempts < rMax) setTimeout(restoreMsgPoll, 5000);
				})
				.fail(function () {
					if (rAttempts < rMax) setTimeout(restoreMsgPoll, 5000);
				});
		}
		setTimeout(restoreMsgPoll, 5000);
	}
});
</script>
</body>
</html>
