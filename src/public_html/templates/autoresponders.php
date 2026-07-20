<?php
	include('templates/header.php');

	$today = date('Y-m-d');

	$autoresponders = array();
	$results = $db->query('SELECT * FROM autoresponders ORDER BY domain, user');
	while ($row = $results->fetchArray(SQLITE3_ASSOC)) {
		$autoresponders[] = $row;
	}

	$output = shell_exec('sudo ls -1 /etc/exim/domains/ 2>/dev/null | sort');
	$domains = array_filter(explode("\n", trim($output)));

	// Gather available email addresses: from emails table (stored as full "user@domain")
	$emails_list = array();
	$results2 = $db->query('SELECT email FROM emails ORDER BY email');
	while ($row2 = $results2->fetchArray(SQLITE3_ASSOC)) {
		$emails_list[] = $row2['email'];
	}
?>
          <!-- Page title -->
          <div class="page-header d-print-none">
            <div class="row align-items-center">
              <div class="col" style="padding-left:22px;">
                <div class="page-pretitle">Email</div>
                <h2 class="page-title">Autoresponders</h2>
              </div>
			  <? if(count($domains)>0) { ?>
              <div class="col-auto ms-auto d-print-none">
                <div class="btn-list">
                  <a href="#" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal-create-autoresponder">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    Create&nbsp;<span class="d-none d-sm-inline">a new</span>&nbsp;autoresponder
                  </a>
                </div>
              </div>
			  <? } ?>
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
				 <div class="text-danger"><?=str_replace('Error: ', '', htmlspecialchars($errmsg));?></div>
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
                	<div class="text-success"><?=htmlspecialchars($successmsg);?></div>
              	</div>
            </div>
          </div>
<? } ?>

<?	if(count($autoresponders) == 0) { ?>
		<p style="padding:14px;">There are no autoresponders configured on this server.</p>
<?	} else { ?>
		<div class="col-12">
          <div class="card">
            <div class="table-responsive">
              <table class="table table-vcenter card-table">
                <thead>
                  <tr>
                    <th style="background-color:#DEF;">Email</th>
                    <th style="background-color:#DEF;">Subject</th>
                    <th style="background-color:#DEF;">Active From</th>
                    <th style="background-color:#DEF;">Active To</th>
                    <th style="background-color:#DEF;">Status</th>
                    <th class="w-1" style="background-color:#DEF;"></th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach($autoresponders as $ar) {
					$email = htmlspecialchars($ar['user'] . '@' . $ar['domain']);
					$subject = htmlspecialchars($ar['subject']);
					$date_from = $ar['date_from'];
					$date_to   = $ar['date_to'];

					if($date_from == '' && $date_to == '') {
						$status = '<span class="badge bg-success">Active</span>';
					} elseif($date_from != '' && $date_from > $today) {
						$status = '<span class="badge bg-warning text-dark">Scheduled</span>';
					} elseif($date_to != '' && $date_to < $today) {
						$status = '<span class="badge bg-secondary">Expired</span>';
					} else {
						$status = '<span class="badge bg-success">Active</span>';
					}
                ?>
                  <tr>
                    <td><?=$email;?></td>
                    <td><?=$subject;?></td>
                    <td><?=htmlspecialchars($date_from ?: '—');?></td>
                    <td><?=htmlspecialchars($date_to ?: '—');?></td>
                    <td><?=$status;?></td>
                    <td>
                      <div class="btn-list flex-nowrap">
                        <a href="#" class="btn btn-white btn-md"
                          data-bs-toggle="modal" data-bs-target="#modal-edit-autoresponder"
                          data-id="<?=$ar['id'];?>"
                          data-user="<?=htmlspecialchars($ar['user']);?>"
                          data-domain="<?=htmlspecialchars($ar['domain']);?>"
                          data-subject="<?=htmlspecialchars($ar['subject']);?>"
                          data-message="<?=htmlspecialchars($ar['message']);?>"
                          data-date-from="<?=htmlspecialchars($ar['date_from']);?>"
                          data-date-to="<?=htmlspecialchars($ar['date_to']);?>">Edit</a>
                        <a href="#" class="btn btn-white btn-md"
                          data-bs-toggle="modal" data-bs-target="#modal-delete-autoresponder"
                          data-id="<?=$ar['id'];?>"
                          data-email="<?=$email;?>">Delete</a>
                      </div>
                    </td>
                  </tr>
                <?php } ?>
                </tbody>
              </table>
            </div>
          </div>
		</div>
<?	} ?>

        </div>
      </div>
    </div>
  </div>

<!-- Create Modal -->
<form method="post" action="/" id="create-autoresponder-form">
  <input type="hidden" name="action" value="create-autoresponder">
  <div class="modal modal-blur fade" id="modal-create-autoresponder" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Create a new autoresponder</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Email address</label>
            <select id="ar-email-select" placeholder="Search email address...">
              <?php foreach($emails_list as $email) { ?><option value="<?=htmlspecialchars($email);?>"><?=htmlspecialchars($email);?></option><?php } ?>
            </select>
            <input type="hidden" name="user" id="ar-user">
            <input type="hidden" name="domain" id="ar-domain">
            <div class="text-danger small mt-1" id="ar-user-error" style="display:none;"></div>
            <small class="form-text text-muted">Select the email address that should send the auto-reply.</small>
          </div>
          <div class="mb-3">
            <label class="form-label">Subject</label>
            <input type="text" class="form-control" name="subject" id="ar-subject" placeholder="Out of office" maxlength="255" required autocomplete="off">
            <div class="invalid-feedback">Please enter a subject.</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Message body</label>
            <textarea class="form-control" name="message" id="ar-message" rows="5" required placeholder="Thank you for your email. I am currently out of office and will reply as soon as possible."></textarea>
            <div class="invalid-feedback">Please enter a message.</div>
          </div>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Active from <span class="text-muted">(optional)</span></label>
              <input type="date" class="form-control" name="date_from" id="ar-date-from" value="<?=date('Y-m-d');?>">
              <small class="form-text text-muted">Leave empty to activate immediately.</small>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Active to <span class="text-muted">(optional)</span></label>
              <input type="date" class="form-control" name="date_to" id="ar-date-to">
              <small class="form-text text-muted">Leave empty to never expire.</small>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <a href="#" class="btn btn-link link-secondary" data-bs-dismiss="modal">Cancel</a>
          <button id="ar-submit-btn" class="btn btn-primary" type="submit">
            <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
            Create autoresponder
          </button>
        </div>
      </div>
    </div>
  </div>
</form>

<!-- Edit Modal -->
<form method="post" action="/" id="edit-autoresponder-form">
  <input type="hidden" name="action" value="edit-autoresponder">
  <input type="hidden" name="id" id="ar-edit-id" value="">
  <div class="modal modal-blur fade" id="modal-edit-autoresponder" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Edit autoresponder for <span id="ar-edit-email-title"></span></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Email address</label>
            <input type="text" class="form-control" id="ar-edit-email-display" disabled>
            <input type="hidden" name="user" id="ar-edit-user">
            <input type="hidden" name="domain" id="ar-edit-domain">
          </div>
          <div class="mb-3">
            <label class="form-label">Subject</label>
            <input type="text" class="form-control" name="subject" id="ar-edit-subject" maxlength="255" required autocomplete="off">
            <div class="invalid-feedback">Please enter a subject.</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Message body</label>
            <textarea class="form-control" name="message" id="ar-edit-message" rows="5" required></textarea>
            <div class="invalid-feedback">Please enter a message.</div>
          </div>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Active from <span class="text-muted">(optional)</span></label>
              <input type="date" class="form-control" name="date_from" id="ar-edit-date-from">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Active to <span class="text-muted">(optional)</span></label>
              <input type="date" class="form-control" name="date_to" id="ar-edit-date-to">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <a href="#" class="btn btn-link link-secondary" data-bs-dismiss="modal">Cancel</a>
          <button id="ar-edit-submit-btn" class="btn btn-primary" type="submit">
            <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><path d="M5 12l5 5l10 -10"></path></svg>
            Save changes
          </button>
        </div>
      </div>
    </div>
  </div>
</form>

<!-- Delete Modal -->
<form method="post" action="/" id="delete-autoresponder-form">
  <input type="hidden" name="action" value="delete-autoresponder">
  <input type="hidden" name="id" id="ar-delete-id" value="">
  <div class="modal modal-blur fade" id="modal-delete-autoresponder" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Delete autoresponder</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-status bg-danger"></div>
        <div class="modal-body text-center py-4">
          <svg xmlns="http://www.w3.org/2000/svg" class="icon mb-2 text-danger icon-lg" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="#ff2825" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="12" cy="12" r="9" /><line x1="12" y1="8" x2="12" y2="12" /><line x1="12" y1="16" x2="12.01" y2="16" /></svg>
          <h3>Delete autoresponder for <span id="ar-delete-email-title"></span></h3>
          <div class="text-muted">Do you really want to remove this autoresponder?</div>
        </div>
        <div class="modal-footer">
          <div class="w-100">
            <div class="row">
              <div class="col"><a href="#" class="btn btn-white w-100" data-bs-dismiss="modal">Cancel</a></div>
              <div class="col"><button id="ar-delete-submit-btn" class="btn btn-danger w-100" type="submit">Delete</button></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</form>

<?php include('templates/footer.php'); ?>
<link rel="stylesheet" href="./dist/libs/tom-select/dist/css/tom-select.bootstrap5.min.css">
<style>
.ts-wrapper .ts-control { background-color: #fff; border: 1px solid #c8d3e1; border-radius: 4px; padding: 6px 12px; min-height: 38px; flex-wrap: nowrap; }
.ts-wrapper .ts-control input { min-width: 0 !important; }
.ts-wrapper .ts-control input { min-width: 0 !important; }
.ts-wrapper.single.has-items .ts-control input { width: 0 !important; opacity: 0; }
.ts-wrapper.focus .ts-control { border-color: #6ea8fe; box-shadow: 0 0 0 0.2rem rgba(38,143,255,.25); }
.ts-dropdown { background-color: #fff; }
.ts-dropdown .option { padding: 6px 12px; }
.ts-dropdown .option.active { background-color: #206bc4; color: #fff; }
</style>
<script src="./dist/libs/tom-select/dist/js/tom-select.complete.min.js"></script>
<script>
jQuery(document).ready(function () {
  'use strict';

  // Tom Select for email field in create modal
  var arTomSelect = new TomSelect('#ar-email-select', {
    placeholder: 'Search email address...',
    allowEmptyOption: true,
    onChange: function(value) {
      if (value) {
        var parts = value.split('@');
        $('#ar-user').val(parts[0]);
        $('#ar-domain').val(parts[1]);
        $(this.control).removeClass('is-invalid');
        $('#ar-user-error').hide();
      } else {
        $('#ar-user').val('');
        $('#ar-domain').val('');
      }
    }
  });

  // Create form submission
  $("#create-autoresponder-form").submit(function(event) {
    event.preventDefault();
    var user    = $('#ar-user').val().trim();
    var domain  = $('#ar-domain').val().trim();
    var subject = $('#ar-subject').val().trim();
    var message = $('#ar-message').val().trim();
    var valid = true;
    if (!user || !domain) {
      $(arTomSelect.control).addClass('is-invalid');
      $('#ar-user-error').text('Please select an email address.').show();
      valid = false;
    }
    if (!subject) { $('#ar-subject').addClass('is-invalid'); valid = false; }
    if (!message) { $('#ar-message').addClass('is-invalid'); valid = false; }
    if (!valid) return;
    $.post('/?ajax=1', {
      action: 'ajax-autoresponder',
      user:   user,
      domain: domain
    }).done(function(msg) {
      if (msg !== '') {
        $(arTomSelect.control).addClass('is-invalid');
        $('#ar-user-error').text(msg).show();
      } else {
        $('#ar-submit-btn').prop('disabled', true);
        $("#create-autoresponder-form").unbind('submit').submit();
      }
    });
  });
  $('#modal-create-autoresponder').on('shown.bs.modal', function() {
    arTomSelect.clear();
    $(arTomSelect.control).removeClass('is-invalid');
    $('#ar-user').val(''); $('#ar-domain').val('');
    $('#ar-user-error').hide();
    $('#ar-subject').removeClass('is-invalid');
    $('#ar-message').removeClass('is-invalid');
  });
  $('#modal-create-autoresponder').on('hidden.bs.modal', function() {
    arTomSelect.clear();
    $('#ar-user').val(''); $('#ar-domain').val('');
  });

  // Edit modal — populate fields from data attributes
  $('#modal-edit-autoresponder').on('show.bs.modal', function(event) {
    var btn = event.relatedTarget;
    var id      = btn.getAttribute('data-id');
    var user    = btn.getAttribute('data-user');
    var domain  = btn.getAttribute('data-domain');
    var subject = btn.getAttribute('data-subject');
    var message = btn.getAttribute('data-message');
    var dfrom   = btn.getAttribute('data-date-from');
    var dto     = btn.getAttribute('data-date-to');
    $('#ar-edit-id').val(id);
    $('#ar-edit-user').val(user);
    $('#ar-edit-domain').val(domain);
    $('#ar-edit-email-display').val(user + '@' + domain);
    $('#ar-edit-email-title').text(user + '@' + domain);
    $('#ar-edit-subject').val(subject);
    $('#ar-edit-message').val(message);
    $('#ar-edit-date-from').val(dfrom);
    $('#ar-edit-date-to').val(dto);
  });
  $("#edit-autoresponder-form").submit(function(event) {
    event.preventDefault();
    var subject = $('#ar-edit-subject').val().trim();
    var message = $('#ar-edit-message').val().trim();
    if (!subject || !message) {
      if (!subject) $('#ar-edit-subject').addClass('is-invalid');
      if (!message) $('#ar-edit-message').addClass('is-invalid');
      return;
    }
    $('#ar-edit-submit-btn').prop('disabled', true);
    $("#edit-autoresponder-form").unbind('submit').submit();
  });

  // Delete modal — populate id and email
  $('#modal-delete-autoresponder').on('show.bs.modal', function(event) {
    var btn = event.relatedTarget;
    $('#ar-delete-id').val(btn.getAttribute('data-id'));
    $('#ar-delete-email-title').text(btn.getAttribute('data-email'));
  });
  $("#delete-autoresponder-form").submit(function(event) {
    $('#ar-delete-submit-btn').prop('disabled', true);
  });
});
</script>
</body>
</html>
