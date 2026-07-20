<?php
	$settings = array("email" => "", "mail-provider" => "", "smtp_from" => "", "smtp_server" => "", "smtp_user" => "", "smtp_password" => "", "smtp_port" => "");
	$results = $db->query('SELECT name,value FROM settings');
	while ($row = $results->fetchArray()) {
		#echo $row["name"].' = '.$row["value"].'<br>';
		$settings_name = $row["name"];
		$settings[$settings_name] = $row["value"];
	}
	#echo "<pre>"; print_r($settings);exit;
	include('templates/header.php');	
?>
        <!-- Page title -->
        <div class="page-header d-print-none">
            <div class="row align-items-center">
              <div class="col" style="padding-left:22px;">
                <!-- Page pre-title -->
<? /*				 	
                <div class="page-pretitle">
                  Most Important
                </div>
	*/ ?>				
                <h2 class="page-title">
                  Settings
                </h2>
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
				 <div class="text-danger"><?=str_replace('Error: ', '', nl2br($errmsg));?></div>
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

		<form method="post" action="/" id="settings" class="needs-validation" novalidate="" autocomplete="off">
		<input type="hidden" name="action" value="settings">

		<div class="card">
		<div class="card-header">
			<h3 class="card-title">Notifications</h3>
		</div>
		<div class="card-body">
			<div class="mb-3 row">
				<label class="col-3 col-form-label required">Email address:</label>
				<div class="col">
					<input type="text" name="email" id="email" value="<?=$settings["email"];?>" class="form-control" placeholder="your-email@example.com"  autocomplete="off" required="" pattern="[A-Za-z0-9\+\-_\.]{1,32}@[a-z0-9\-\.]{2,32}\.[a-z]{2,10}.*" maxlength="256" style="max-width:300px;">
				</div>
			</div>
		</div>
		</div>

		<br />
		<div class="form-selectgroup form-selectgroup-boxes d-flex flex-column">
			<h3 class="card-title" style="padding-left:14px;">Sending method:</h3>
			<div class="row row-deck row-cards">
				<div class="col-md-6 col-lg-4 col-xl-3">
				<label class="form-selectgroup-item flex-fill" style="height:94px;">
						<input type="radio" name="mail-provider" id="smtp" value="smtp" class="form-selectgroup-input" checked="" <?=$settings['mail-provider']=='smtp'?'checked=""':'';?>>
						<div class="form-selectgroup-label d-flex align-items-center p-3">
							<div class="me-3">
								<span class="form-selectgroup-check"></span>
							</div>
							<div style="padding:10px 0;height:50px;">
								<h1>SMTP</h1>
							</div>
						</div>
					</label>
				</div>
				<div class="col-md-6 col-lg-4 col-xl-3">
				<label class="form-selectgroup-item flex-fill" style="height:94px;">
						<input type="radio" name="mail-provider" id="gmail" value="gmail" class="form-selectgroup-input" disabled>
						<div class="form-selectgroup-label d-flex align-items-center p-3">
							<div class="me-3">
								<span class="form-selectgroup-check"></span>
							</div>
							<div style="padding:10px 0;height:50px;">
								<h1 style="color:#AAA">
									<svg xmlns="http://www.w3.org/2000/svg" width="60" height="40" viewBox="0 0 80 80" style="filter: grayscale(1) brightness(2) contrast(0.5);"><path fill="#4285f4" d="M6 66.0162h14v-34l-20-15v43c0 3.315 2.685 6 6 6z"/><path fill="#34a853" d="M68 66.0162h14c3.315 0 6-2.685 6-6v-43l-20 15z"/><path fill="#fbbc04" d="M68 6.0162v26l20-15v-8c0-7.415-8.465-11.65-14.4-7.2z"/><path fill="#ea4335" d="M20 32.0162v-26l24 18 24-18v26l-24 18z"/><path fill="#c5221f" d="M0 9.0162v8l20 15v-26l-5.6-4.2c-5.935-4.45-14.4-.215-14.4 7.2z"/></svg>
									Gmail
								</h1>
							</div>
						</div>
					</label>
				</div>
			</div>

			<div class="card">
				<div class="card-header">
					<div class="card-title">SMTP Settings</div>
				</div>
				<div class="card-body">

			<div class="mb-3 row">
				<label class="col-3 col-form-label required">From address:</label>
				<div class="col"><input type="email" name="smtp_from" id="smtp_from" value="<?=$settings["smtp_from"];?>" class="form-control" placeholder="noreply@example.com" required="" style="max-width:300px;">
				<small class="form-text text-muted" style="display:block;margin-top:8px;">Address shown in the From field of outgoing notifications.</small></div>
			</div>

			<div class="mb-3 row">
				<label class="col-3 col-form-label required">SMTP server:</label>
				<div class="col"><input type="text" name="smtp_server" id="smtp_server" value="<?=$settings["smtp_server"];?>" class="form-control" placeholder="mail.example.com" required="" pattern="[a-z0-9\-\.]{0,128}[a-z]{2,9}" style="max-width:300px;"></div>
			</div>

			<div class="mb-3 row">
				<label class="col-3 col-form-label required">SMTP user:</label>
				<div class="col"><input type="text" name="smtp_user" id="smtp_user" value="<?=$settings["smtp_user"];?>" class="form-control" autocomplete="off" placeholder="user@example.com" required="" pattern="[A-Za-z0-9\-\.@]{0,128}[a-z]{2,9}" style="max-width:300px;"></div>
			</div>

			<div class="mb-3 row">
				<label class="col-3 col-form-label required">SMTP password:</label>
				<div class="col"><input type="text" name="smtp_password" id="smtp_password" value="<?=$settings["smtp_password"];?>" placeholder="your-secret-password" class="form-control" autocomplete="new-password" pattern="^(?=.*[0-9])(?=.*[a-z])(?=.*[A-Z])(?=\S+$).{6,}$" required="" style="-webkit-text-security: disc;max-width:300px;"></div>
			</div>

			<div class="mb-3 row">
				<label class="col-3 col-form-label required">SMTP port:</label>
				<div class="col">
					<label style="cursor:pointer;line-height:20pt;padding:6px;">
						<input type="radio" name="smtp_port" id="smtp_465" value="465" <?=($settings['smtp_port']==''||$settings['smtp_port']=='465')?'checked=""':'';?>>
						465 - TLS/SSL
					</label></br />
					<label style="cursor:pointer;line-height:20pt;padding:6px;">
						<input type="radio" name="smtp_port" id="smtp_587" value="587" <?=$settings['smtp_port']=='587'?'checked=""':'';?>>
						587 - StarTLS
					</label></br />
				</div>
			</div>

			<div class="mb-3 row">
				<label class="col-3 col-form-label required"></label>
				<div class="col">
					<label class="form-check">
						<input class="form-check-input" type="checkbox" name="test_smtp">
						<span class="form-check-label" style="cursor:pointer;">Test connection</span>
					</label><br>
				</div>
			</div>
		</div>
		</div><br>

		<div class="card">
		<div class="card-header">
			<h3 class="card-title">Other settings</h3>
		</div>
		<div class="card-body">
			<div class="mb-3 ps-1 row">
					<label class="form-check mt-2">
						<input class="form-check-input" type="checkbox" name="show_welcome" <?= !$db->querySingle('SELECT value FROM settings WHERE name="welcome_dismissed"') ? 'checked' : '' ?>>
						<span class="form-check-label" style="cursor:pointer;">Show welcome screen on dashboard</span>
					</label>
			</div>
			<div class="mb-0 ps-1 row">
<?php
	$_aliases = @file_get_contents('/etc/aliases') ?: '';
	$_root_forward_active = (bool)preg_match('/^root:.*forward_root_mail/mi', $_aliases);
?>
				<label class="form-check mt-2">
					<input class="form-check-input" type="checkbox" name="root_mail_forward" <?= $_root_forward_active ? 'checked' : '' ?>>
					<span class="form-check-label" style="cursor:pointer;">Forward root system mail to email address above</span>
				</label>
				<small class="text-muted ps-4">Cron jobs, monit, csf, logwatch and other system notifications sent to root will be forwarded via SMTP.</small>
			</div>
			<div class="mb-0 ps-1 row">
				<label class="form-check mt-2">
					<input class="form-check-input" type="checkbox" name="telemetry" <?= ($db->querySingle('SELECT value FROM settings WHERE name="telemetry"') !== '0') ? 'checked' : '' ?>>
					<span class="form-check-label" style="cursor:pointer;">Send basic telemetry to hub.reqad.net</span>
				</label>
				<small class="text-muted ps-4">Hostname, IP, OS and Reqad version — no personal data. Helps track installations.</small>
			</div>
		</div>
		</div>

		<input type="submit" id="submit-btn" class="btn btn-primary mt-3" value="Save settings" style="max-width:130px;">
		</form>

	<?php
    include('templates/footer.php');
?>
<script>
jQuery(document).ready(function () {
	'use strict';
	$('.alert').delay(5000).fadeOut(2000);

//	$('input[type=radio][name=mail-provider]').change(function() {
//		if($('#smtp').prop('checked')) {
//			$('#smtp').show();
//	});
	$('#settings').on('submit', function (event) {
		event.preventDefault();
		if ($('#settings')[0].checkValidity() === false) {
			event.stopPropagation();
			if($('#email').val()=='' || !$('#email').is(':valid')) {
				$('#email').addClass('is-invalid');
				$('#email').removeClass('was-validated');
				$('#email').focus();
			} else {
				$('#email').addClass('was-validated');
				$('#email').removeClass('is-invalid');
				if($('#smtp_from').val().trim()=='' || !$('#smtp_from').is(':valid')) {
					$('#smtp_from').addClass('is-invalid');
					$('#smtp_from').removeClass('was-validated');
					$('#smtp_from').focus();
				} else {
				$('#smtp_from').addClass('was-validated');
				$('#smtp_from').removeClass('is-invalid');
				if($('#smtp_server').val()=='' || !$('#smtp_server').is(':valid')) {
					$('#smtp_server').addClass('is-invalid');
					$('#smtp_server').removeClass('was-validated');
					$('#smtp_server').focus();
				} else {
					$('#smtp_server').addClass('was-validated');
					$('#smtp_server').removeClass('is-invalid');
					if($('#smtp_user').val()=='' || !$('#smtp_user').is(':valid')) {
						$('#smtp_user').addClass('is-invalid');
						$('#smtp_user').removeClass('was-validated');
						$('#smtp_user').focus();
					} else {
						$('#smtp_user').addClass('was-validated');
						$('#smtp_user').removeClass('is-invalid');
						if($('#smtp_password').val()=='' || !$('#smtp_password').is(':valid')) {
							$('#smtp_password').addClass('is-invalid');
							$('#smtp_password').removeClass('was-validated');
							$('#smtp_password').focus();
						} else {
							$('#smtp_password').addClass('was-validated');
							$('#smtp_password').removeClass('is-invalid');
						}
					}
				}
			}
			}
		} else {
			$('#settings').addClass('was-validated');
			$('#settings').removeClass('is-invalid');
//			document.getElementById("smtp_password").type = 'text'; // disable prompt to save password
//			var button = event.relatedTarget;
			$('#submit-btn').prop('value', 'Saving...');
			$('#submit-btn').prop('disabled', true);
			$('#settings').unbind('submit').submit();
		}
	});
});
</script>
</body>
</html>