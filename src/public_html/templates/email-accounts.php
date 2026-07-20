<?php
	#phpinfo(32);

	$items = 10;

	$output = shell_exec('sudo cat /etc/dovecot/users | sort | awk -F\':\' {\'print $1 " " $6\'}');
	$emails = array();
	if(trim($output)!='')
   		$emails = explode("\n", trim($output));
	#echo '<pre>'; print_r($emails); exit;
	$nb_accounts = count($emails);

	include('templates/header.php');


#	$results = $db->query('SELECT count(*) as nb FROM emails');
#	$row = $results->fetchArray();
#	$nb_accounts = (int)($row["nb"]);

	$disk_usage = array();
	$results = $db->query('SELECT email, disk_usage FROM emails');
	while ($row = $results->fetchArray()) {
		$email = $row['email'];
		$disk_usage[$email] = $row['disk_usage'];
	}
	#print_r($disk_usage); exit;

	$emails2 = array();
	foreach($emails as $email) {
		$email2 = explode(' ', $email);
		$email = $email2[0];
		$email_path = $email2[1];
		if(isset($disk_usage[$email])) {
			$disk_usage2 = $disk_usage[$email];
		} else {
			$disk_usage2 = trim(shell_exec('sudo du -skm '.$email_path.' | awk {\'print $1\'}'));
		}
		$emails2[] = array('email' => $email, 'path' => $email_path, 'disk_usage' => $disk_usage2);
	}

/* initial data
	$db->query("DELETE FROM emails");
	foreach($emails as $email) {
		$email2 = explode(' ', $email);
		$email = $email2[0];
		$email_path = $email2[1];
		$disk_usage = (int)(trim(shell_exec('sudo du -skm '.$email_path.' | awk {\'print $1\'}')));
		echo("INSERT INTO emails VALUES (null, '$email', $disk_usage, null, 'active', DATE())<br>");
		$db->query("INSERT INTO emails VALUES (null, '$email', $disk_usage, null, 'active', DATE())");
	}
	exit;
*/

	$domains = [];
	$res = $db->query("SELECT domain FROM accounts WHERE has_email=1 ORDER BY domain");
	while ($drow = $res->fetchArray(SQLITE3_ASSOC)) $domains[] = $drow['domain'];
   	
?>
          <!-- Page title -->
          <div class="page-header d-print-none">
            <div class="row align-items-center">
              <div class="col" style="padding-left:22px;">
                <!-- Page pre-title -->
                <div class="page-pretitle">
                  Email
                </div>
                <h2 class="page-title" style="white-space:nowrap !important;">
                  List Email&nbsp;<span class="d-none d-sm-inline">Accounts</span>
                </h2>
              </div>

			  <? if(count($domains)>0) { ?>
              <div class="col-auto ms-auto d-print-none">
                <div class="btn-list">
                  <a href="#" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal-create-email">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    Create a new email&nbsp;<span class="d-none d-sm-inline">account</span>
                  </a>
                </div>
              </div>
			  <? } ?>
            </div>
          </div>

<?php msg_render(); /* flash message (PRG) — shown once, even with 0 accounts */ ?>

<?	if($nb_accounts == 0 ) { ?>
		<p style="padding:14px;">There are no email accouns created on this server.</p>
<? 	} else { ?>



		<div class="col-12">
            <div class="card">
		        <!-- div class="card-header">
	                <h3 class="card-title text-nowrap">List Accounts</h3>
                    <div class="ms-auto text-muted">
                      Search:
                      <div class="ms-2 d-inline-block">
                        <input type="text" class="form-control form-control-sm" aria-label="Search email" size="10">
                      </div>
                    </div>
                </div -->
                <div class="table-responsive">
                  <table class="table table-vcenter card-table table-responsive"">
                    <thead>
                      <tr>
                        <th class="w-1" style="background-color:#DEF;">ID</th>
                        <th style="background-color:#DEF;">EMAIL <svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' width='16' height='16'><path fill='none' stroke='currentColor' stroke-linecap='round' stroke-linejoin='round' stroke-width='1' d='M5 10l3 -3l3 3'/></svg></th>
                        <th class="w-10" style="background-color:#DEF;">Usage</th>
                        <th style="background-color:#DEF;">Status</th>
                        <th class="w-5" style="background-color:#DEF;"></th>
                      </tr>
                      <tr>
                        <td style="padding:4px 8px;"></td>
                        <td style="padding:4px 8px;">
                          <div style="position:relative;max-width:350px;">
                            <input type="text" id="email-search" class="form-control" placeholder="Filter emails..." style="padding:6px;;padding-right:26px;line-height:8pt;font-size:10pt;border:none;" autocomplete="off">
                            <button id="email-search-clear" type="button" title="Clear" style="display:none;position:absolute;right:7px;top:50%;transform:translateY(-50%);background:none;border:none;padding:0;cursor:pointer;color:#aaa;font-size:15px;line-height:1;">&#x2715;</button>
                          </div>
                        </td>
                        <td colspan="3" style="padding:4px 8px;"></td>
                      </tr>
                    </thead>
                    <tbody>
                   <?
                      	$i = 0;
						foreach($emails2 as $email) {
							$i++;
                    ?>
                      <tr class="email-row" data-email="<?=$email['email'];?>" data-idx="<?=$i;?>" style="<?=$i>$items?'display:none':'';?>">
                        <td data-label="ID">
                          <div class="d-flex">
                            <div class="flex-fill">
                              <div class="text-muted"><?=$i;?>.</div>
                            </div>
                          </div>
                        </td>
                        <td data-label="Domain">
                          <div class="d-flex py-1 align-items-center">
                            <div class="flex-fill">
                              <div class="font-weight-medium">
								  <?=$email['email'];?></div>
                            </div>
                          </div>
                        </td>
                        <td data-label="Usage">
                          <div class="d-flex">
                            <div class="flex-fill">
								<?=(int)($email['disk_usage'])>0?(int)($email['disk_usage']).' MB':'-';?>
                            </div>
                          </div>
                        </td>
                        <td class="text-muted" data-label="Status">
                          <? #if($row["status"] == 'active') { ?>
                          <span class="badge bg-success">Active</span>
                          <? /* } else if($row["status"] == 'suspended') { ?>
                          <span class="badge bg-danger">Suspended</span>
                          <? } */ ?>
                        </td>
                        <td>
                          <div class="btn-list flex-nowrap">
                            <a href="#" class="btn btn-white btn-md" data-bs-toggle="modal" data-bs-target="#modal-edit-email" data-bs-email="<?=$email['email'];?>" <? if(isset($ini["quota"]) && (int)($ini["quota"])>0) { ?>data-bs-disk-quota="<?=$row["disk_quota"];?>"<? } ?>>Change Password</a>
                            <a href="#" class="btn btn-white btn-md" data-bs-toggle="modal" data-bs-target="#modal-delete-email" data-bs-email="<?=$email['email'];?>">Delete</a>
                          </div>
                        </td>
                      </tr>
                      <? } ?>
                    </tbody>
                  </table>
                </div>
		        <div id="email-footer" class="card-footer d-flex align-items-center">
				<? if($nb_accounts > $items): ?>
					<p class="m-0 text-muted"><span class="d-none d-xl-inline">Showing </span>1 to <?=$items;?> of <?=$nb_accounts;?> email accounts</p>
					<ul class="pagination m-0 ms-auto">
						<li class="page-item disabled"><a class="page-link" href="#" data-start="1"><svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><polyline points="15 6 9 12 15 18"></polyline></svg> prev</a></li>
						<? for($p = 1; $p <= ceil($nb_accounts/$items); $p++): ?>
						<li class="page-item <?=$p===1?'active':'';?>"><a class="page-link" href="#" data-start="<?=($p-1)*$items+1;?>"><?=$p;?></a></li>
						<? endfor; ?>
						<li class="page-item"><a class="page-link" href="#" data-start="<?=$items+1;?>">next <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><polyline points="9 6 15 12 9 18"></polyline></svg></a></li>
					</ul>
				<? else: ?>
					<p class="m-0 text-muted">Total: <?=$nb_accounts;?> email account<?=$nb_accounts>1?'s':'';?>.</p>
				<? endif; ?>
				</div>
              </div>
            </div>
			<?  } ?>

            </div>
          </div>
        </div>
      </div>
    </div>

	<form method="post" action="/" id="create-email" class="needs-validation" novalidate>
    <input type="hidden" name="action" value="create-email">
    <div class="modal modal-blur fade" id="modal-create-email" tabindex="-1" role="dialog" aria-hidden="true">
      <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Create a new email account</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
			<p>To create a new email account, type the user and then select the domain name from the list.</p>
            <div class="mb-3">
				<div class="row">
                    <div class="col-5">
						<input type="text" class="form-control" name="user" id="user" placeholder="user1" aria-describedby="userHelpBlock" required pattern="[A-Za-z0-9_\-\+\.]+" maxlength="64" autocomplete="off">
						<div class="invalid-feedback" id="invalid-email">
							Please type email address.
						</div>
					</div>
                    <div class="col-1">
						<div class="input-group">
							<span class="input-group-text">
								@
							</span>
						</div>
					</div>
                    <div class="col-6">
						<select class="form-select" name="domain" id="domain">
						<? foreach($domains as $domain) { ?>
							<option><?=$domain;?></option>
						<?	} ?>
						</select>
					</div>
				</div>
				<small id="userHelpBlock" class="form-text text-muted" style="display:block;margin-top:8px;">
					Email must be unique, 1-64 characters long, contain letters, numbers, dashes and underscores.
				</small>
            </div>

			<? if(isset($ini["quota"]) && (int)($ini["quota"])>0) { ?>
            <div class="mb-3">
              <label class="form-label">Disk Space Quota</label>
              <input name="disk_quota" type="range" class="form-range mb-2" value="1024" min="0" max="10240" step="256" oninput="if(this.value==0) { this.nextElementSibling.value = 'disabled'; } else { this.nextElementSibling.value = this.value + ' MB'; }" style="width:80%;align:left;margin-right:20px;" /><output>1024 MB</output></input>
              <small id="userHelpBlock" class="form-text text-muted" style="display:block;margin-top:8px;">
                Disk space quota is optional, you can set it to zero to disable account level quota.
              </small>
            </div>
			<? } ?>
            <div class="row">
              <div class="col-lg-6">
                <div class="mb-3" id="pwd-container">
                  <label class="form-label">Password</label>
                  <input type="text" class="form-control" name="password" id="password" autocomplete="off" aria-describedby="passwordHelpBlock" required pattern="[^ ]{8,24}" maxlength="24">
                  <div class="pwstrength_viewport_progress"></div>
                </div>
              </div>
              <div class="col-lg-6">
                <div class="mb-3 top27">
                <input type="button" value="Generate" class="btn btn-white" onClick="$('#password').val(genPass()).pwstrength('forceUpdate');">
                <input type="button" value="Hide password" class="btn btn-white" onClick="if($(this).val()=='Hide password') { $('#password').attr('type', 'password');$(this).val('Show password'); } else {$('#password').attr('type', 'text');$(this).val('Hide password'); }">
                </div>
              </div>
              <small id="passwordHelpBlock" class="form-text text-muted" style="display:block;margin-top:-8px;">
                Your password must be 8-24 characters long, contain letters and numbers, and must not contain spaces.
              </small>
              <div class="invalid-feedback">
                Please enter a password.
              </div>
            </div>
			<br>
          </div>
          <div class="modal-footer">
            <a href="#" class="btn btn-link link-secondary" data-bs-dismiss="modal">
              Cancel
            </a>
            <button id="submit-btn" class="btn btn-primary" type="submit"><svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg> Create email account</button>
          </div>
        </div>
      </div>
    </div>
</form>

<form method="post" action="/" id="edit-email" class="needs-validation" novalidate>
    <input type="hidden" name="action" value="edit-email">
    <input type="hidden" name="email" id="email-edit" value="">
    <div class="modal modal-blur fade" id="modal-edit-email" tabindex="-1" role="dialog" aria-hidden="true">
      <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Edit email account <span id="email-title"></span></h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <p>You can change the password for this email account.</p>
            <div class="mb-3">
              <label class="form-label">Email address:</label>
              <span id="email-title2" class="input-group-text"></span>
            </div>
			<? if(isset($ini["quota"]) && (int)($ini["quota"])>0) { /* TODO */ ?>
            <div class="mb-3">
              <label class="form-label">Disk Space Quota</label>
              <input name="disk_quota" id="diskquota-edit" type="range" class="form-range mb-2" value="1024" min="0" max="<?=$ini["quota"];?>" step="256" oninput="if(this.value==0) { this.nextElementSibling.value = 'disabled'; } else { this.nextElementSibling.value = this.value + ' MB'; }" style="width:80%;align:left;margin-right:20px;" /><output><?=$ini["quota"];?> MB</output></input>
              <small id="userHelpBlock" class="form-text text-muted" style="display:block;margin-top:8px;">
                Disk space quota is optional, you can set it to zero to disable account level quota.
              </small>
            </div>
			<? } ?>
            <div class="row">
              <div class="col-lg-6">
                <div class="mb-3" id="pwd-container2">
                  <label class="form-label">New password:</label>
                  <input type="text" class="form-control" name="password" id="password2" autocomplete="off" aria-describedby="passwordHelpBlock" required pattern="[^ ]{8,24}" maxlength="24">
                  <div class="pwstrength_viewport_progress"></div>
                </div>
              </div>
              <div class="col-lg-6">
                <div class="mb-3 top27">
                <input type="button" value="Generate" class="btn btn-white" onClick="$('#password2').val(genPass()).pwstrength('forceUpdate');">
                <input type="button" value="Hide password" class="btn btn-white" onClick="if($(this).val()=='Hide password') { $('#password2').attr('type', 'password');$(this).val('Show password'); } else {$('#password2').attr('type', 'text');$(this).val('Hide password'); }">
                </div>
              </div>
              <small id="passwordHelpBlock" class="form-text text-muted" style="display:block;margin-top:-8px;">
                Enter a new password only if you want to change the existing one.
                Your password must be 8-24 characters long, contain letters, numbers and special characters but not spaces.
              </small>
              <div class="invalid-feedback">
                Please enter a password.
              </div>
              </div>
          </div>
          <div class="modal-footer">
            <a href="#" class="btn btn-link link-secondary" data-bs-dismiss="modal">
              Cancel
            </a>
            <button id="submit-btn2" class="btn btn-primary" type="submit">Save changes</button>
          </div>
        </div>
      </div>
    </div>
</form>

<form method="post" action="/" id="delete-email" class="needs-validation" novalidate>
    <input type="hidden" name="action" value="delete-email">
    <input type="hidden" name="email" id="email-delete" value="">
    <div class="modal modal-blur fade" id="modal-delete-email" tabindex="-1" role="dialog" aria-hidden="true">
      <div class="modal-dialog modal-sm modal-dialog-centered" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Delete email account</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          <div class="modal-status bg-danger"></div>
          <div class="modal-body text-center py-4">
			<svg xmlns="http://www.w3.org/2000/svg" class="icon mb-2 text-danger icon-lg" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="#ff2825" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="12" cy="12" r="9" /><line x1="12" y1="8" x2="12" y2="12" /><line x1="12" y1="16" x2="12.01" y2="16" /></svg>
            <h3>Delete email account <span id="email-title3"></span></h3>
            <div class="text-muted">Do you really want to remove this email account?</div>
          </div>
          <div class="modal-footer">
            <div class="w-100">
              <div class="row">
                <div class="col"><a href="#" class="btn btn-white w-100" data-bs-dismiss="modal">
                    Cancel
                </a></div>
                <div class="col"><button id="submit-btn3" class="btn btn-primary" type="submit">
					Delete account
				</button></div>
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

	// Email search / pagination
	var emailStart = 1;
	var emailItems = <?=$items;?>;
	var emailTotal = <?=$nb_accounts;?>;
	var svgPrev = '<svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><polyline points="15 6 9 12 15 18"></polyline></svg>';
	var svgNext = '<svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><polyline points="9 6 15 12 9 18"></polyline></svg>';

	function renderEmailFooter(total, start) {
		var html = '';
		if (total > emailItems) {
			var pages = Math.ceil(total / emailItems);
			var curPage = Math.floor((start - 1) / emailItems) + 1;
			html += '<p class="m-0 text-muted"><span class="d-none d-xl-inline">Showing </span>' + start + ' to ' + Math.min(start + emailItems - 1, total) + ' of ' + total + ' email accounts</p>';
			html += '<ul class="pagination m-0 ms-auto">';
			html += '<li class="page-item' + (start === 1 ? ' disabled' : '') + '"><a class="page-link" href="#" data-start="' + Math.max(1, start - emailItems) + '">' + svgPrev + ' prev</a></li>';
			for (var p = 1; p <= pages; p++) {
				html += '<li class="page-item' + (p === curPage ? ' active' : '') + '"><a class="page-link" href="#" data-start="' + ((p - 1) * emailItems + 1) + '">' + p + '</a></li>';
			}
			html += '<li class="page-item' + ((start + emailItems) > total ? ' disabled' : '') + '"><a class="page-link" href="#" data-start="' + (start + emailItems) + '">next ' + svgNext + '</a></li>';
			html += '</ul>';
		} else {
			html = '<p class="m-0 text-muted">Total: ' + total + ' email account' + (total !== 1 ? 's' : '') + '.</p>';
		}
		$('#email-footer').html(html);
	}

	function showEmailPage(start) {
		emailStart = start;
		$('.email-row').each(function() {
			var idx = parseInt($(this).data('idx'));
			$(this).toggle(idx >= emailStart && idx < emailStart + emailItems);
		});
		renderEmailFooter(emailTotal, emailStart);
	}

	$('#email-footer').on('click', '.page-link', function(e) {
		e.preventDefault();
		if ($(this).closest('.page-item').hasClass('disabled')) return;
		showEmailPage(parseInt($(this).data('start')));
	});

	$('#email-search').on('input', function() {
		$('#email-search-clear').toggle($(this).val() !== '');
	});

	$('#email-search').on('keydown', function(e) {
		if (e.key !== 'Enter') return;
		var q = $(this).val().toLowerCase().trim();
		if (q === '') { clearEmailSearch(); return; }
		var shown = 0;
		$('.email-row').each(function() {
			var matches = $(this).data('email').toLowerCase().indexOf(q) !== -1;
			$(this).toggle(matches);
			if (matches) shown++;
		});
		$('#email-footer').html('<p class="m-0 text-muted">' + shown + ' result' + (shown !== 1 ? 's' : '') + ' for &ldquo;' + $('<span>').text(q).html() + '&rdquo;</p>');
	});

	$('#email-search-clear').on('click', clearEmailSearch);

	function clearEmailSearch() {
		$('#email-search').val('');
		$('#email-search-clear').hide();
		showEmailPage(emailStart);
	}
	$("#modal-create-email").on('shown.bs.modal', function() {
		$('#user').focus();
	});
	$("#create-email").submit(function(event) {
		event.preventDefault();
		if ($('#create-email')[0].checkValidity() === false) {
			event.stopPropagation();
			if(!$('#user').is(':valid')) {
				$('#user').focus();
			} else if(!$('#password').is(':valid')) {
				$('#password').focus();
			}
		} else if($('#user').is(':valid')) {
			jQuery.ajax({
				method: "POST",
				url: "./ajax-email/",
				data: { action: 'ajax-email', user: $('#user').val(), domain: $('#domain').val() }
			}).done(function( msg ) {
				if(msg != '') {
					$('#invalid-email').html(msg);
					$('#user').addClass('is-invalid');
					$('#user').removeClass('was-validated');
					$("#create-email").removeClass('was-validated');
				} else {
					$('#invalid-email').html('');
					$('#user').removeClass('is-invalid');
					$('#user').addClass('was-validated');
					$("#create-email").addClass('was-validated');
					$('#submit-btn').prop('disabled', true);
					$("#create-email").unbind('submit').submit();
				}
			});
		}
  	});

	$('#edit-email').on('show.bs.modal', function (event) {
		// Button that triggered the modal
		var button = event.relatedTarget;
		// Extract info from data-bs-* attributes
		var email = button.getAttribute('data-bs-email');
		$('#email-edit').val(email);
		$('#email-title').html(email);
		$('#email-title2').html(email);
		$('#password2').val('');
	<? if(isset($ini["quota"]) && (int)($ini["quota"])>0) { ?>
		var disk_quota = button.getAttribute('data-bs-disk-quota')
		$('#diskquota-edit').val(disk_quota);
		$('#diskquota-edit').next().html(disk_quota + ' MB');
	<? } ?>
  	});

	$("#edit-email").submit(function(event) {
		console.log('submit');
		event.preventDefault();
		if ($('#edit-email')[0].checkValidity() === false) {
			event.stopPropagation();
			if(!$('#password2').is(':valid')) {
				$('#password2').focus();
			}
		} else if($('#password2').is(':valid')) {
			$('#submit-btn2').prop('disabled', true);
			$("#edit-email").unbind('submit').submit();
		}
  	});

	$('#delete-email').on('show.bs.modal', function (event) {
		//console.log(event);
		// Button that triggered the modal
		var button = event.relatedTarget;
		// Extract info from data-bs-* attributes
		var email = button.getAttribute('data-bs-email');
		console.log(email);
		$('#email-delete').val(email);
		$('#email-title3').html(email);
  	});

	$("#delete-email").submit(function(event) {
	//	event.preventDefault();
	//	event.stopPropagation();
		$('#submit-btn3').prop('disabled', true);
		$("#delete-email").unbind('submit').submit();
  	});

});
</script>
</body>
</html>
