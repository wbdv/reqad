<?php
	$items = 10;

	include('templates/header.php');

    $output = shell_exec('sudo grep -r : /etc/exim/forwards/ | sort');
	$output = str_replace('/etc/exim/forwards/', '', $output);
	$output = str_replace('"', '', $output);
	$output = str_replace("'", '', $output);
    $forwards = array();
    if(trim($output)!='') {
        foreach(explode("\n", trim($output)) as $line) {
            $parts = explode(':', trim($line));
            // skip the catch-all reject (*: :fail: No Such User Here) and any
            // entry without a forward target — these aren't real forwarders and
            // must not be displayed or counted.
            if(!isset($parts[2]) || trim($parts[2])=='') continue;
            $forwards[] = $line;
        }
    }
	#echo '<pre>'; print_r($forwards); exit;

	$nb_accounts = count($forwards);

	$domains = [];
	$res = $db->query("SELECT domain FROM accounts WHERE has_email=1 ORDER BY domain");
	while ($row = $res->fetchArray(SQLITE3_ASSOC)) $domains[] = $row['domain'];
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
					Email Forwarders&nbsp;<span class="d-none d-sm-inline"> / Aliases</span>
                </h2>
              </div>

			  <? if(count($domains)>0) { ?>
              <div class="col-auto ms-auto d-print-none">
                <div class="btn-list">
                  <a href="#" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal-create-forward">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    Create&nbsp;<span class="d-none d-sm-inline"> a new email</span>&nbsp;forwarder
                  </a>
                </div>
              </div>
			  <? } ?>
            </div>
          </div>


<?php msg_render(); /* flash message (PRG) — shown once, even with 0 forwarders */ ?>
<?	if($nb_accounts == 0 ) { ?>
		<p style="padding:14px;">There are no email forwarders created on this server.</p>
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
                  <table class="table table-vcenter card-table">
                    <thead>
                      <tr>
                        <th style="background-color:#DEF;">ID</th>
                        <th style="background-color:#DEF;">Email</th>
                        <th style="background-color:#DEF;">Forward to</th>
                        <th class="w-1" style="background-color:#DEF;"></th>
                      </tr>
                      <tr>
                        <td style="padding:4px 8px;"></td>
                        <td style="padding:4px 8px;">
                          <div style="position:relative;max-width:350px;">
                            <input type="text" id="forward-search" class="form-control" placeholder="Filter forwarders..." style="padding:6px;padding-right:26px;line-height:8pt;font-size:10pt;border:none;" autocomplete="off">
                            <button id="forward-search-clear" type="button" title="Clear" style="display:none;position:absolute;right:7px;top:50%;transform:translateY(-50%);background:none;border:none;padding:0;cursor:pointer;color:#aaa;font-size:15px;line-height:1;">&#x2715;</button>
                          </div>
                        </td>
                        <td colspan="2" style="padding:4px 8px;"></td>
                      </tr>
                    </thead>
                    <tbody>
                   <?
                      	$i = 0;
 						foreach($forwards as $email) {
                            $email = explode(':', trim($email));
                            // :fail:
                            if(trim($email[2])=='') continue;
                            $i++;
                    ?>
                      <tr class="forward-row" data-forward="<?=$email[1].'@'.$email[0];?>" data-idx="<?=$i;?>" style="<?=$i>$items?'display:none':'';?>">
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
								  <?=$email[1].'@'.$email[0];?></div>
                            </div>
                          </div>
                        </td>
                        <td data-label="Usage">
                          <div class="d-flex">
                            <div class="flex-fill">
							<?=str_replace(',', ', ', str_replace(' ', '', trim($email[2])));?></div>
                            </div>
                          </div>
                        </td>
                        <td>
                          <div class="btn-list flex-nowrap">
                            <a href="#" class="btn btn-white btn-md" data-bs-toggle="modal" data-bs-target="#modal-edit-forwarder" data-bs-forwarder="<?=$email[1].'@'.$email[0];?>" data-bs-forwardto="<?=str_replace(',', ', ', str_replace(' ', '', trim($email[2])));?>">Edit</a>
                            <a href="#" class="btn btn-white btn-md" data-bs-toggle="modal" data-bs-target="#modal-delete-forwarder" data-bs-forwarder="<?=$email[1].'@'.$email[0];?>">Delete</a>
                          </div>
                        </td>
                      </tr>
                      <? } ?>
                    </tbody>
                  </table>
                </div>
                <div id="forward-footer" class="card-footer d-flex align-items-center">
				<? if($nb_accounts > $items): ?>
					<p class="m-0 text-muted"><span class="d-none d-xl-inline">Showing </span>1 to <?=$items;?> of <?=$nb_accounts;?> forwarders</p>
					<ul class="pagination m-0 ms-auto">
						<li class="page-item disabled"><a class="page-link" href="#" data-start="1"><svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><polyline points="15 6 9 12 15 18"></polyline></svg> prev</a></li>
						<? for($p = 1; $p <= ceil($nb_accounts/$items); $p++): ?>
						<li class="page-item <?=$p===1?'active':'';?>"><a class="page-link" href="#" data-start="<?=($p-1)*$items+1;?>"><?=$p;?></a></li>
						<? endfor; ?>
						<li class="page-item"><a class="page-link" href="#" data-start="<?=$items+1;?>">next <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><polyline points="9 6 15 12 9 18"></polyline></svg></a></li>
					</ul>
				<? else: ?>
					<p class="m-0 text-muted">Total: <?=$nb_accounts;?> forwarder<?=$nb_accounts>1?'s':'';?>.</p>
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

	<form method="post" action="/" id="create-forwarder" class="needs-validation" novalidate>
    <input type="hidden" name="action" value="create-forwarder">
    <div class="modal modal-blur fade" id="modal-create-forward" tabindex="-1" role="dialog" aria-hidden="true">
      <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Create a new email forwarder</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
			<p>To create a new email forwarder, type the user part and then select the domain name from the list.</p>
            <div class="mb-3">
				<div class="row">
                    <div class="col-5">
						<input type="text" class="form-control" name="user" id="user" placeholder="user1" aria-describedby="userHelpBlock" required pattern="[A-Za-z0-9_\-\+\.]+" maxlength="64" autocomplete="off">
						<div class="invalid-feedback" id="invalid-forward">
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
						<? 
							foreach($domains as $domain) {
						?>
							<option><?=$domain;?></option>
						<?	} ?>
						</select>
					</div>
				</div>
				<small id="userHelpBlock" class="form-text text-muted" style="display:block;margin-top:8px;">
					User must be 1-64 characters long, contain letters, numbers, dashes and underscores. If an email account with the same name exists on server, a copy of email will be saved local, aother one forwarded.
				</small>
            </div>

            <div class="row">
              <div class="col-lg-12">
                <div class="mb-3" id="pwd-container">
                  <label class="form-label">Forwarded to:</label>
                  <input type="text" class="form-control" name="forward" id="forward" placeholder="otheremail@another.domain, [...]" autocomplete="off" aria-describedby="forwardHelpBlock" required pattern="[A-Za-z0-9\+\-_\.]{1,32}@[a-z0-9\-\.]{2,32}\.[a-z]{2,10}.*" maxlength="256">
				  <div class="invalid-feedback" id="invalid-forward2"></div>
				  <small id="userHelpBlock" class="form-text text-muted" style="display:block;margin-top:8px;">
					Multiple email addresses can be separated by comma.
				  </small>
                </div>
              </div>
            </div>
			<div class="row">
              <div class="col-lg-12">
                <div class="mb-3" id="pwd-container">
                  <label class="form-label">Pipe to program:</label>
                  <input type="text" class="form-control" name="pipe" id="pipe" placeholder="/full/path/to/script" autocomplete="off" aria-describedby="forwardHelpBlock" required pattern="/.*" maxlength="256">
				  <div class="invalid-feedback" id="invalid-forward3"></div>
				  <small id="userHelpBlock" class="form-text text-muted" style="display:block;margin-top:8px;">
					Optional. The script need to be executable and will receive the emails as input (stdin).
				  </small>
                </div>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <a href="#" class="btn btn-link link-secondary" data-bs-dismiss="modal">
              Cancel
            </a>
            <button id="submit-btn" class="btn btn-primary" type="submit"><svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg> Create email account forwarder</button>
          </div>
        </div>
      </div>
    </div>
</form>

<form method="post" action="/" id="edit-forwarder" class="needs-validation" novalidate>
    <input type="hidden" name="action" value="edit-forwarder">
    <input type="hidden" name="old_forward" id="forwarder-edit" value="">
    <div class="modal modal-blur fade" id="modal-edit-forwarder" tabindex="-1" role="dialog" aria-hidden="true">
      <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Edit email forwarder <span id="forwarder-title"></span></h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
		  <p>You can change user, domain or email addresses where the mail is forwarded.</p>
            <div class="mb-3">
				<div class="row">
                    <div class="col-5">
						<input type="text" class="form-control" name="user" id="user2" placeholder="user1" aria-describedby="userHelpBlock" required pattern="[A-Za-z0-9_\-\+\.]+" maxlength="64" autocomplete="off">
						<div class="invalid-feedback" id="invalid-forward4">
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
						<select class="form-select" name="domain" id="domain2">
						<? 
							foreach($domains as $domain) {
						?>
							<option><?=$domain;?></option>
						<?	} ?>
						</select>
					</div>
				</div>
				<small id="userHelpBlock" class="form-text text-muted" style="display:block;margin-top:8px;">
					User must be 1-64 characters long, contain letters, numbers, dashes and underscores. If an email account with the same name exists on server, a copy of email will be saved local, aother one forwarded.
				</small>
            </div>

            <div class="row">
              <div class="col-lg-12">
                <div class="mb-3" id="pwd-container">
                  <label class="form-label">Forwarded to:</label>
                  <input type="text" class="form-control" name="forward" id="forward2" placeholder="otheremail@another.domain, [...]" autocomplete="off" aria-describedby="forwardHelpBlock" required pattern="[A-Za-z0-9\+\-_\.]{1,32}@[a-z0-9\-\.]{2,32}\.[a-z]{2,10}.*" maxlength="256">
				  <div class="invalid-feedback" id="invalid-forward5">
				  	Please add forwarders.
				  </div>
				  <small id="userHelpBlock" class="form-text text-muted" style="display:block;margin-top:8px;">
					Multiple email addresses can be separated by comma.
				  </small>
                </div>
              </div>
            </div>

			<div class="row">
              <div class="col-lg-12">
                <div class="mb-3" id="pwd-container">
                  <label class="form-label">Pipe to program:</label>
                  <input type="text" class="form-control" name="pipe" id="pipe2" placeholder="/full/path/to/script" autocomplete="off" aria-describedby="forwardHelpBlock" required pattern="/.*" maxlength="256">
				  <div class="invalid-feedback" id="invalid-forward3"></div>
				  <small id="userHelpBlock" class="form-text text-muted" style="display:block;margin-top:8px;">
					Optional. The script need to be executable and will receive the emails as input (stdin).
				  </small>
                </div>
              </div>
            </div>

			<br>
          </div>
          <div class="modal-footer">
            <a href="#" class="btn btn-link link-secondary" data-bs-dismiss="modal">
              Cancel
            </a>
            <button id="submit-btn2" class="btn btn-primary" type="submit"><svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg> Save changes</button>
          </div>
        </div>
      </div>
    </div>
</form>

<form method="post" action="/" id="delete-forwarder" class="needs-validation" novalidate>
    <input type="hidden" name="action" value="delete-forwarder">
    <input type="hidden" name="forwarder" id="forwarder-delete" value="">
    <div class="modal modal-blur fade" id="modal-delete-forwarder" tabindex="-1" role="dialog" aria-hidden="true">
      <div class="modal-dialog modal-sm modal-dialog-centered" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Delete email forwarder</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          <div class="modal-status bg-danger"></div>
          <div class="modal-body text-center py-4">
			<svg xmlns="http://www.w3.org/2000/svg" class="icon mb-2 text-danger icon-lg" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="#ff2825" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="12" cy="12" r="9" /><line x1="12" y1="8" x2="12" y2="12" /><line x1="12" y1="16" x2="12.01" y2="16" /></svg>
            <h3>Delete forwarder <span id="forwarder-title3"></span></h3>
            <div class="text-muted">Do you really want to remove this forwarder?</div>
          </div>
          <div class="modal-footer">
            <div class="w-100">
              <div class="row">
                <div class="col"><a href="#" class="btn btn-white w-100" data-bs-dismiss="modal">
                    Cancel
                </a></div>
                <div class="col"><button id="submit-btn3" class="btn btn-primary" type="submit">
					Delete forwarder
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

	// Forwarder search / pagination
	var forwardStart = 1;
	var forwardItems = <?=$items;?>;
	var forwardTotal = <?=$nb_accounts;?>;
	var svgPrev = '<svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><polyline points="15 6 9 12 15 18"></polyline></svg>';
	var svgNext = '<svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><polyline points="9 6 15 12 9 18"></polyline></svg>';

	function renderForwardFooter(total, start) {
		var html = '';
		if (total > forwardItems) {
			var pages = Math.ceil(total / forwardItems);
			var curPage = Math.floor((start - 1) / forwardItems) + 1;
			html += '<p class="m-0 text-muted"><span class="d-none d-xl-inline">Showing </span>' + start + ' to ' + Math.min(start + forwardItems - 1, total) + ' of ' + total + ' forwarders</p>';
			html += '<ul class="pagination m-0 ms-auto">';
			html += '<li class="page-item' + (start === 1 ? ' disabled' : '') + '"><a class="page-link" href="#" data-start="' + Math.max(1, start - forwardItems) + '">' + svgPrev + ' prev</a></li>';
			for (var p = 1; p <= pages; p++) {
				html += '<li class="page-item' + (p === curPage ? ' active' : '') + '"><a class="page-link" href="#" data-start="' + ((p - 1) * forwardItems + 1) + '">' + p + '</a></li>';
			}
			html += '<li class="page-item' + ((start + forwardItems) > total ? ' disabled' : '') + '"><a class="page-link" href="#" data-start="' + (start + forwardItems) + '">next ' + svgNext + '</a></li>';
			html += '</ul>';
		} else {
			html = '<p class="m-0 text-muted">Total: ' + total + ' forwarder' + (total !== 1 ? 's' : '') + '.</p>';
		}
		$('#forward-footer').html(html);
	}

	function showForwardPage(start) {
		forwardStart = start;
		$('.forward-row').each(function() {
			var idx = parseInt($(this).data('idx'));
			$(this).toggle(idx >= forwardStart && idx < forwardStart + forwardItems);
		});
		renderForwardFooter(forwardTotal, forwardStart);
	}

	$('#forward-footer').on('click', '.page-link', function(e) {
		e.preventDefault();
		if ($(this).closest('.page-item').hasClass('disabled')) return;
		showForwardPage(parseInt($(this).data('start')));
	});

	$('#forward-search').on('input', function() {
		$('#forward-search-clear').toggle($(this).val() !== '');
	});

	$('#forward-search').on('keydown', function(e) {
		if (e.key !== 'Enter') return;
		var q = $(this).val().toLowerCase().trim();
		if (q === '') { clearForwardSearch(); return; }
		var shown = 0;
		$('.forward-row').each(function() {
			var matches = $(this).data('forward').toLowerCase().indexOf(q) !== -1;
			$(this).toggle(matches);
			if (matches) shown++;
		});
		$('#forward-footer').html('<p class="m-0 text-muted">' + shown + ' result' + (shown !== 1 ? 's' : '') + ' for &ldquo;' + $('<span>').text(q).html() + '&rdquo;</p>');
	});

	$('#forward-search-clear').on('click', clearForwardSearch);

	function clearForwardSearch() {
		$('#forward-search').val('');
		$('#forward-search-clear').hide();
		showForwardPage(forwardStart);
	}

	$("#modal-create-forward").on('shown.bs.modal', function() {
		$('#user').focus();
	});
	$("#create-forwarder").submit(function(event) {
		event.preventDefault();
		//if ($('#create-forwarder')[0].checkValidity() === false) {
		if (!$('#user').is(':valid') || (!$('#forward').is(':valid') && !$('#pipe').is(':valid'))) {
			event.stopPropagation();
			if(!$('#user').is(':valid')) {
				$('#user').focus();
			} else if(!$('#forward').is(':valid') && !$('#pipe').is(':valid')) {
				$('#forward').focus();
			} else {
				console.log('not valid');
				$('#forward').is(':valid');
				$('#pipe').is(':valid');
			}
		} else if($('#user').is(':valid')) {
			jQuery.ajax({
				method: "POST",
				url: "./ajax-forward/",
				data: { action: 'ajax-forward', user: $('#user').val(), domain: $('#domain').val(), forward: $('#forward').val(), pipe: $('#pipe').val() }
			}).done(function( msg ) {
				if(msg != '') {
					console.log(msg.substring(0,16));
					if(msg.substring(0,16)=='Error: Forwarder') {
						$('#invalid-forward2').html(msg);
						$('#forward').addClass('is-invalid');
						$('#forward').removeClass('was-validated');
					} else if(msg.substring(0,11)=='Error: Pipe') {
						$('#invalid-forward3').html(msg);
						$('#pipe').addClass('is-invalid');
						$('#pipe').removeClass('was-validated');
					} else {
						$('#invalid-forward').html(msg);
						$('#user').addClass('is-invalid');
						$('#user').removeClass('was-validated');
					}
					$("#create-forwarder").removeClass('was-validated');
				} else {
					$('#invalid-forward').html('');
					$('#invalid-forward2').html('');
					$('#invalid-forward3').html('');
					$('#user').removeClass('is-invalid');
					$('#user').addClass('was-validated');
					$('#forward').removeClass('is-invalid');
					$('#forward').addClass('was-validated');
					$('#pipe').removeClass('is-invalid');
					$('#pipe').addClass('was-validated');
					$("#create-forwarder").removeClass('is-invalid');
					//$("#create-forwarder").addClass('was-validated');
					$("#create-forwarder").unbind('submit').submit();
				}
			});
		}
  	});

	$('#edit-forwarder').on('show.bs.modal', function (event) {
		// Button that triggered the modal
		var button = event.relatedTarget;
		// Extract info from data-bs-* attributes
		var forwarder = button.getAttribute('data-bs-forwarder');
		var forwardto = button.getAttribute('data-bs-forwardto');
		//console.log(forwarder);
		//console.log(forwardto);
		
		$('#forwarder-edit').val(forwarder);
		$('#forwarder-title').html(forwarder);
		const userdomain = forwarder.split('@');
		// console.log(userdomain[0]);
		// console.log(userdomain[1]);
		$('#user2').val(userdomain[0]);
		$('#domain2').val(userdomain[1]).change();
		var forwards = forwardto.split(", ");
		forwardto='';
		forwards.forEach(function(item) {
			if(item.substring(0,1)=='|')
				$('#pipe2').val(item.substring(1));
			else if(forwardto!='')
				forwardto=forwardto+", "+item;
			else
				forwardto=item;
		});
		$('#forward2').val(forwardto);
  	});

	$("#edit-forwarder").submit(function(event) {
		event.preventDefault();
		if ($('#edit-forwarder')[0].checkValidity() === false) {
			if(!$('#user2').is(':valid')) {
				$('#invalid-forward4').html('User is not corrent.');
				$('#user2').addClass('is-invalid');
				$('#user2').removeClass('was-validated');
				$("#edit-forwarder").removeClass('was-validated');
			} else if(!$('#forward2').is(':valid')) {
				$('#invalid-forward5').html('Forward address is not corrent.');
				$('#forward2').addClass('is-invalid');
				$('#forward2').removeClass('was-validated');
				$("#edit-forwarder").removeClass('was-validated');
			}
			event.stopPropagation();
		} else {
			$("#edit-forwarder").addClass('was-validated');
			$('#submit-btn2').prop('disabled', true);
//			console.log('submit');
			$("#edit-forwarder").unbind('submit').submit();
		}
  	});

	$('#delete-forwarder').on('show.bs.modal', function (event) {
		console.log(event);
		// Button that triggered the modal
		var button = event.relatedTarget;
		// Extract info from data-bs-* attributes
		var forwarder = button.getAttribute('data-bs-forwarder');
		console.log(forwarder);
		$('#forwarder-delete').val(forwarder);
		$('#forwarder-title3').html(forwarder);
  	});

	$("#delete-forward").submit(function(event) {
	//	event.preventDefault();
	//	event.stopPropagation();
		$('#submit-btn3').prop('disabled', true);
		$("#delete-forwarder").unbind('submit').submit();
  	});

});
</script>
</body>
</html>
