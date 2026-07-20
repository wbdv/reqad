<?php
	$settings = array();
	$results = $db->query('SELECT name,value FROM settings');
	while ($row = $results->fetchArray()) {
    	#echo $row["name"].' = '.$row["value"].'<br>';
	    $settings_name = $row["name"];
    	$settings[$settings_name] = $row["value"];
	}
	#echo '<pre>'; print_r($settings); exit;
	#echo '<pre>'; print_r($ini); exit;

	$ssh_keys = array();

	$authorized = shell_exec('sudo grep AuthorizedKeysFile /etc/ssh/sshd_config | awk {\'print $2\'}');
	$pw_auth_raw = trim(shell_exec('sudo grep -i "^PasswordAuthentication" /etc/ssh/sshd_config | awk \'{print $2}\''));
	$pw_auth_enabled = ($pw_auth_raw === '' || strtolower($pw_auth_raw) === 'yes');
	$root_access = isset($ini['root_access']) ? (int)$ini['root_access'] : 1;

	$users = $root_access ? array('root') : array();
	$q = $db->query('SELECT user FROM accounts');
	while ($row = $q->fetchArray()) {
		$users[] = $row["user"];
	}

	foreach($users as $user) {
		$keys_raw = trim(shell_exec('sudo cat ~'.$user.'/'.trim($authorized).' 2>/dev/null'));
		if($keys_raw != '') {
			$parsed = array();
			foreach(explode("\n", $keys_raw) as $line) {
				$line = trim($line);
				if($line == '' || $line[0] == '#') continue;
				$parsed[] = $line;
			}
			if(!empty($parsed))
				$ssh_keys[$user] = $parsed;
		}
	}


	if(!isset($errmsg))
		$errmsg = '';

    include('templates/header.php'); 
?>
        <!-- Page title -->
        <div class="page-header d-print-none">
            <div class="row align-items-center">
            	<div class="col" style="padding-left:22px;">
					<!-- Page pre-title -->
					<div class="page-pretitle">
						ACCOUNTS
					</div>
					<h2 class="page-title">
						SSH Authorized Keys
					</h2>
					<div class="page-subtitle" style="margin-top:4px;">
						Password authentication is
						<? if($pw_auth_enabled) { ?>
						<span class="badge bg-warning text-white">enabled</span>
						<? } else { ?>
						<span class="badge bg-success text-white">disabled</span>
						<? } ?>
					</div>
              	</div>
              	<div class="col-auto ms-auto d-print-none">
	                <div class="btn-list">
                  		<a href="#" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal-add-ssh-key">
                    		<svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
	                    	Add a new SSH Key
                  		</a>
                	</div>
	            </div>
            </div>
        </div>

<? if(isset($errmsg) && $errmsg != '') { ?>
          <div class="alert alert-warning" role="alert" style="background:#FFE;">
            <div class="d-flex">
				<div style="width:55px;">
                	<svg xmlns="http://www.w3.org/2000/svg" class="icon mb-2 text-danger icon-md" width="48" height="48" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><path d="M12 9v2m0 4v.01"></path><path d="M5 19h14a2 2 0 0 0 1.84 -2.75l-7.1 -12.25a2 2 0 0 0 -3.5 0l-7.1 12.25a2 2 0 0 0 1.75 2.75"></path></svg>
             	</div>
             	<div>
				 <h3 class="text-danger" style="margin-top:6px;margin-bottom:0">Error</h3>
				 <div class="text-danger"><?=str_replace('Error: ', '', $errmsg);?></div>
              	</div>
            </div>
          </div>
<? } ?>
<? if(isset($successmsg) && $successmsg != '') { ?>
          <div class="alert alert-success" role="alert" style="background:#EFE;">
            <div class="d-flex">
				<div style="width:55px;">
					<svg xmlns="http://www.w3.org/2000/svg" class="icon mb-2 text-success icon-md icon-tabler-circle-check" width="48" height="48" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="12" cy="12" r="9" /><path d="M9 12l2 2l4 -4" /></svg>
              	</div>
              	<div>
                	<h3 class="text-success" style="margin-top:6px;margin-bottom:0">Success</h3>
                	<div class="text-success"><?=clean($successmsg);?></div>
              	</div>
            </div>
          </div>
<? } ?>

		<div class="col-12">
            <div class="card">
                <div class="table-responsive">
                  <table class="table table-vcenter table-mobile-md card-table">
                    <thead>
                      <tr>
                        <th style="background-color:#DEF;">ID</th>
                        <th style="background-color:#DEF;">User</th>
                        <th style="background-color:#DEF;">Keys</th>
                      </tr>
                    </thead>
                    <tbody>
                    <?
						$i=0;
						foreach($ssh_keys as $user => $keys) {
							$i++;
                    ?>
                      <tr>
                        <td data-label="ID" class="align-text-top">
                          <div class="d-flex">
                            <div class="flex-fill">
                              <div class="text-muted"><?=$i;?>.</div>
                            </div>
                          </div>
                        </td>
                        <td data-label="User" class="align-text-top">
                          <div class="d-flex py-1 align-items-center">
                            <div class="flex-fill">
                              <div class="font-weight-medium"><?=$user;?></div>
                            </div>
                          </div>
                        </td>
                        <td data-label="Keys" class="align-text-top">
                          <div class="flex-fill">
                            <? foreach($keys as $key_line) {
                                $parts = preg_split('/\s+/', $key_line, 3);
                                $type    = $parts[0] ?? '';
                                $keydata = $parts[1] ?? '';
                                $comment = $parts[2] ?? '';
                                $trunc   = strlen($keydata) > 30 ? substr($keydata,0,20).'...'.substr($keydata,-10) : $keydata;
                            ?>
                            <div style="font-family:monospace, monospace;font-size:11pt;white-space:nowrap;margin-bottom:3px;">
                              <?=htmlspecialchars($type.' '.$trunc.($comment ? ' '.$comment : ''));?>
                              <? if($user !== 'root' || $root_access) { ?>
                              <a href="#" class="badge bg-grey text-white ms-2"
                                data-bs-toggle="modal" data-bs-target="#modal-delete-ssh-key"
                                data-bs-sshuser="<?=htmlspecialchars($user, ENT_QUOTES);?>"
                                data-bs-pubkey="<?=htmlspecialchars($key_line, ENT_QUOTES);?>"
                                data-bs-display="<?=htmlspecialchars($type.' '.$trunc.($comment ? ' '.$comment : ''), ENT_QUOTES);?>"
                                style="cursor:pointer;border-radius:10px;text-decoration:none;font-size:12pt;padding:0">×</a>
                              <? } ?>
                            </div>
                            <? } ?>
                          </div>
                        </td>
                      </tr>
                      <? } ?>
                    </tbody>
                  </table>
                </div>
<? /*				
                <div class="card-footer d-flex align-items-center">
                  <p class="m-0 text-muted"><span class="d-none d-xl-inline">Showing </span><span>1</span> to <span>2</span> of <span>2</span></p>
                  <!--
                  <ul class="pagination m-0 ms-auto">
                    <li class="page-item disabled">
                      <a class="page-link" href="#" tabindex="-1" aria-disabled="true">
                        <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><polyline points="15 6 9 12 15 18"></polyline></svg>
                        prev
                      </a>
                    </li>
                    <li class="page-item active"><a class="page-link" href="#">1</a></li>
                    <li class="page-item"><a class="page-link" href="#">2</a></li>
                    <li class="page-item"><a class="page-link" href="#">3</a></li>
                    <li class="page-item">
                      <a class="page-link" href="/email-accounts/?page=2">
                        next <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><polyline points="9 6 15 12 9 18"></polyline></svg>
                      </a>
                    </li>
                  </ul>
                  -->
                </div>
*/ ?>
              </div>
            </div>

            </div>
          </div>
        </div>
      </div>
    </div>

<form method="post" action="/" id="add-ssh-key" class="needs-validation" novalidate>
    <input type="hidden" name="action" value="add-ssh-key">
    <div class="modal modal-blur fade" id="modal-add-ssh-key" tabindex="-1" role="dialog" aria-hidden="true">
      <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Add a new SSH Key</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div class="mb-3">
              <label class="form-label">User</label>
              <select name="sshuser" id="sshuser" class="form-select" required>
                <option value="" disabled selected>— select a user —</option>
                <? foreach($users as $u) { ?>
                <option value="<?=htmlspecialchars($u);?>"><?=htmlspecialchars($u);?></option>
                <? } ?>
              </select>
              <div class="invalid-feedback">Please select a user.</div>
            </div>
            <div class="mb-3">
              <label class="form-label">Public Key</label>
              <textarea name="pubkey" id="pubkey" class="form-control" rows="4" required
                placeholder="ssh-ed25519 AAAA… user@host" style="font-family:monospace;font-size:0.85em;"></textarea>
              <small class="form-text text-muted" style="display:block;margin-top:8px;">
                Paste the full public key (e.g. <code>~/.ssh/id_ed25519.pub</code>). SSH2 format is accepted and will be converted automatically.
              </small>
              <div class="invalid-feedback" id="invalid-pubkey">Please paste a public key.</div>
            </div>
          </div>
          <div class="modal-footer">
            <a href="#" class="btn btn-link link-secondary" data-bs-dismiss="modal">Cancel</a>
            <button id="ssh-submit-btn" class="btn btn-primary" type="submit">
              <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
              Add SSH Key
            </button>
          </div>
        </div>
      </div>
    </div>
</form>

<form method="post" action="/" id="delete-ssh-key">
    <input type="hidden" name="action" value="delete-ssh-key">
    <input type="hidden" name="sshuser" id="delete-sshuser" value="">
    <input type="hidden" name="pubkey" id="delete-pubkey" value="">
    <div class="modal modal-blur fade" id="modal-delete-ssh-key" tabindex="-1" role="dialog" aria-hidden="true">
      <div class="modal-dialog modal-sm modal-dialog-centered" role="document">
        <div class="modal-content">
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          <div class="modal-status bg-danger"></div>
          <div class="modal-body text-center py-4">
            <svg xmlns="http://www.w3.org/2000/svg" class="icon mb-2 text-danger icon-lg" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="#ff2825" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="12" cy="12" r="9" /><line x1="12" y1="8" x2="12" y2="12" /><line x1="12" y1="16" x2="12.01" y2="16" /></svg>
            <h3>Delete SSH Key</h3>
            <div class="text-muted">Remove key <strong><span id="delete-key-display"></span></strong> for user <strong><span id="delete-key-user"></span></strong>?</div>
          </div>
          <div class="modal-footer">
            <div class="w-100">
              <div class="row">
                <div class="col"><a href="#" class="btn btn-white w-100" data-bs-dismiss="modal">Cancel</a></div>
                <div class="col"><button id="delete-ssh-submit-btn" class="btn btn-danger w-100" type="submit">Delete</button></div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
</form>

<form action="#" id="view-zone" class="needs-validation" novalidate>
    <div class="modal modal-blur fade" id="modal-view-zone" tabindex="-1" role="dialog" aria-hidden="true">
      <div class="modal-dialog modal-sm modal-dialog-centered" role="document" style="min-width:1200px">
        <div class="modal-content">
		  <div class="modal-header">
            <h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">View zone <span id="zone-title"></span> on <?=$dns_provider_name;?></h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body" id="view-zone-body">
          </div>
          <div class="modal-footer">
            <div class="w-100">
              <div class="row">
			  	<div class="col"><a href="#" class="btn btn-whit" data-bs-dismiss="modal">Close</a></div>
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

	// Focus the user select when the modal opens
	$('#modal-add-ssh-key').on('shown.bs.modal', function () {
		$('#sshuser').focus();
	});

	// Reset form when modal closes
	$('#modal-add-ssh-key').on('hidden.bs.modal', function () {
		$('#add-ssh-key')[0].reset();
		$('#add-ssh-key').removeClass('was-validated');
		$('#invalid-pubkey').html('Please paste a public key.');
		$('#pubkey').removeClass('is-invalid is-valid');
		$('#ssh-submit-btn').prop('disabled', false).html(
			'<svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg> Add SSH Key'
		);
	});

	$('#modal-delete-ssh-key').on('show.bs.modal', function (event) {
		var btn = event.relatedTarget;
		$('#delete-sshuser').val(btn.getAttribute('data-bs-sshuser'));
		$('#delete-pubkey').val(btn.getAttribute('data-bs-pubkey'));
		$('#delete-key-user').text(btn.getAttribute('data-bs-sshuser'));
		$('#delete-key-display').text(btn.getAttribute('data-bs-display'));
		$('#delete-ssh-submit-btn').prop('disabled', false).text('Delete');
	});

	$('#delete-ssh-key').submit(function () {
		$('#delete-ssh-submit-btn').prop('disabled', true).text('Deleting…');
		$(this).unbind('submit').submit();
	});

	$('#add-ssh-key').submit(function (event) {
		event.preventDefault();
		var form = this;

		// Basic HTML5 validation
		if (!form.checkValidity()) {
			event.stopPropagation();
			$(form).addClass('was-validated');
			return;
		}

		// Check key type client-side
		var pubkey = $('#pubkey').val().trim();
		var validTypes = ['ssh-rsa ', 'ssh-ed25519 ', 'ssh-dss ', 'ecdsa-sha2-nistp256 ', 'ecdsa-sha2-nistp384 ', 'ecdsa-sha2-nistp521 '];
		var isSsh2   = pubkey.indexOf('---- BEGIN SSH2 PUBLIC KEY ----') !== -1;
		var keyOk    = isSsh2 || validTypes.some(function (t) { return pubkey.indexOf(t) === 0; });
		if (!keyOk) {
			$('#invalid-pubkey').html('Key must start with a recognised type (ssh-ed25519, ssh-rsa, ecdsa-sha2-nistp256, …) or be in SSH2 format.');
			$(form).removeClass('was-validated');
			$('#pubkey').addClass('is-invalid');
			return;
		}
		$('#pubkey').removeClass('is-invalid');

		$('#ssh-submit-btn').prop('disabled', true).html('Adding key…');
		$(form).unbind('submit').submit();
	});
});
</script>
</body>
</html>
