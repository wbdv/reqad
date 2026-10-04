<?php
	$s_email     = setting_get('email');
	$s_smtp      = array(
		'from'   => setting_get('smtp_from'),
		'server' => setting_get('smtp_server'),
		'user'   => setting_get('smtp_user'),
		'port'   => setting_get('smtp_port', '465'),
		'haspw'  => setting_get('smtp_password') !== '',
	);
	$s_smtp_ok   = smtp_configured();
	$s_forward   = root_mail_forward_active();
	$s_welcome   = !setting_get('welcome_dismissed');
	$s_telemetry = setting_get('telemetry') !== '0';
	$s_upd       = auto_update_status();
	$s_dnf       = dnf_automatic_status();

	/* systemd prints "Sat 2026-09-12 06:06:17 EEST"; the seconds are noise */
	$s_nosec = function ($t) { return preg_replace('/(\d\d:\d\d):\d\d/', '$1', $t); };

	/* The switch style of the Email account modal ("Email account is [Active]"):
	   label names the thing, the badge carries the state, red when off. The
	   badge and label colour follow the switch live (.state-switch in the JS). */
	$s_state_switch = function ($name, $label, $on, $unavailable = false) {
		if ($unavailable)
			return '<label class="form-check form-switch d-inline-flex align-items-center mb-0">'
				. '<input class="form-check-input" type="checkbox" id="'.$name.'" disabled>'
				. '<span class="form-check-label text-muted" style="cursor:default;">'.h($label).'</span>'
				. '<span class="badge border ms-2 text-muted" style="background:#f4f6fa;">Unavailable</span>'
				. '</label>';
		return '<label class="form-check form-switch d-inline-flex align-items-center mb-0" style="cursor:pointer">'
			. '<input class="form-check-input state-switch" type="checkbox" name="'.$name.'" id="'.$name.'" value="1"'.($on ? ' checked' : '').'>'
			. '<span class="form-check-label'.($on ? '' : ' text-red').'">'.h($label).'</span>'
			. '<span class="badge border ms-2 '.($on ? 'bg-green-lt' : 'bg-red-lt').'">'.($on ? 'Active' : 'Disabled').'</span>'
			. '</label>';
	};

	include('templates/header.php');
?>
        <!-- Page title -->
        <div class="page-header d-print-none">
            <div class="row align-items-center">
              <div class="col" style="padding-left:22px;">
                <h2 class="page-title">
                  Settings
                </h2>
              </div>
            </div>
        </div>

<?php msg_render(); /* flash message (PRG) */ ?>

<style>
	/* one card, one row per setting: label column on the left, stacked on phones */
	#settings .set-row { padding: 1.25rem 0; border-top: 1px solid rgba(98,105,118,.16); }
	#settings .set-row:first-child { border-top: 0; padding-top: 0; }
	#settings .set-label { font-weight: 600; padding-top: 2px; }
	#settings .set-hint { display: block; color: #656d77; font-size: 90%; margin-top: 4px; }
	/* switches: same geometry as the Email account one, hints lined up under the label text */
	#settings .form-switch .form-check-input { margin-right: 10px; margin-bottom: 1px; }
	#settings .form-switch .form-check-label { line-height: 21px; cursor: pointer; }
	#settings .set-hint.under-switch { padding-left: 42px; }
	/* inline-flex sits on the text baseline and leaves descender space below it,
	   which pushed the hint under a bare switch further down than the others */
	#settings label.form-switch { vertical-align: top; }
</style>

		<form method="post" action="/" id="settings" class="needs-validation" novalidate="" autocomplete="off">
		<input type="hidden" name="action" value="settings">

		<div class="card">
		<div class="card-body">

			<!-- ─── dashboard ─────────────────────────────────────────── -->
			<div class="row set-row">
				<div class="col-md-3 set-label">Dashboard</div>
				<div class="col-md-9">
					<label class="form-check form-switch d-inline-flex align-items-center mb-0" style="cursor:pointer">
						<input class="form-check-input" type="checkbox" name="show_welcome" <?=$s_welcome ? 'checked' : '';?>>
						<span class="form-check-label">Show welcome screen on dashboard</span>
					</label>
				</div>
			</div>

			<!-- ─── system mail ───────────────────────────────────────── -->
			<div class="row set-row">
				<div class="col-md-3 set-label">System mail</div>
				<div class="col-md-9">
					<label class="form-check form-switch d-inline-flex align-items-center mb-0" style="cursor:pointer">
						<input class="form-check-input" type="checkbox" name="root_mail_forward" id="root_mail_forward" <?=$s_forward ? 'checked' : '';?>>
						<span class="form-check-label">Forward root system mail to email address below:</span>
					</label>
					<small class="set-hint under-switch mb-2">Cron jobs, monit, csf, logwatch and other system notifications sent to root.</small>

					<div class="input-group" style="max-width:480px;">
						<input type="email" name="email" id="email" value="<?=h($s_email);?>" class="form-control" placeholder="your-email@example.com" autocomplete="off" maxlength="256">
						<button type="button" class="btn" data-bs-toggle="modal" data-bs-target="#modal-mail-method" title="Configure how mail is sent">
							<svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10.325 4.317c.426 -1.756 2.924 -1.756 3.35 0a1.724 1.724 0 0 0 2.573 1.066c1.543 -.94 3.31 .826 2.37 2.37a1.724 1.724 0 0 0 1.065 2.572c1.756 .426 1.756 2.924 0 3.35a1.724 1.724 0 0 0 -1.066 2.573c.94 1.543 -.826 3.31 -2.37 2.37a1.724 1.724 0 0 0 -2.572 1.065c-.426 1.756 -2.924 1.756 -3.35 0a1.724 1.724 0 0 0 -2.573 -1.066c-1.543 .94 -3.31 -.826 -2.37 -2.37a1.724 1.724 0 0 0 -1.065 -2.572c-1.756 -.426 -1.756 -2.924 0 -3.35a1.724 1.724 0 0 0 1.066 -2.573c-.94 -1.543 .826 -3.31 2.37 -2.37c1 .608 2.296 .07 2.572 -1.065z"/><circle cx="12" cy="12" r="3"/></svg>
							Sending method
						</button>
					</div>
					<div class="invalid-feedback" id="email-feedback">Enter a valid email address, or leave it empty.</div>
<?php if ($s_smtp_ok): ?>
					<small class="set-hint">Sent via SMTP <b><?=h($s_smtp['server']);?>:<?=h($s_smtp['port']);?></b> as <?=h($s_smtp['from']);?></small>
<?php else: ?>
					<small class="set-hint"><span class="badge bg-orange">not configured</span> No sending method yet — root mail cannot be forwarded until one is set.</small>
<?php endif; ?>
				</div>
			</div>

			<!-- ─── automatic updates ─────────────────────────────────────
			     Both switches are saved with the main form, and only acted on
			     when they change. The Reqad update time has its own modal. -->
			<div class="row set-row">
				<div class="col-md-3 set-label">Automatic updates</div>
				<div class="col-md-9">
					<!-- Reqad: nightly dnf update 'reqad*' from /etc/crontab -->
					<div class="d-flex align-items-center flex-wrap gap-2">
						<?=$s_state_switch('auto_update', 'Automatic Reqad updates are', $s_upd['on'], !$s_upd['installed']);?>
<?php if ($s_upd['installed']): ?>
						<!-- only while the switch is on; follows it live (JS below) -->
						<span id="auto-update-when" class="<?=$s_upd['on'] ? '' : 'd-none';?>">
							<span class="text-muted ms-2 me-2">every night at <b><?=h(sprintf('%02d:%02d', (int)$s_upd['hour'], (int)$s_upd['min']));?></b></span>
							<button type="button" class="btn btn-sm" data-bs-toggle="modal" data-bs-target="#modal-auto-update">Configure</button>
						</span>
<?php endif; ?>
					</div>
<?php if (!$s_upd['installed']): ?>
					<small class="set-hint under-switch">Reqad is not installed from its RPM package on this server (<code>rpm -q reqad</code>), so there is nothing for dnf to update.</small>
<?php endif; ?>
<?php if ($s_upd['last']): $l = $s_upd['last']; ?>
					<small class="set-hint under-switch">Last run <?=h($l['time']);?> —
<?php	if ($l['running']): ?>
						running, or stopped before finishing
<?php	elseif ($l['rc'] !== 0): ?>
						<span class="text-danger">failed (dnf exit <?=(int)$l['rc'];?>, see <code>log/auto_update.log</code>)</span>
<?php	elseif (count($l['updated'])): ?>
						<span class="text-success">updated <?=h(implode(', ', $l['updated']));?></span>
<?php	else: ?>
						already up to date
<?php	endif; ?>
					</small>
<?php endif; ?>
<?php if ($s_upd['installed']): ?>
					<small class="set-hint under-switch">Reqad and its installed add-ons only; other packages are left alone.</small>
<?php endif; ?>

					<!-- system: dnf-automatic's own systemd timer sets the time -->
					<div class="mt-4">
						<?=$s_state_switch('dnf_automatic', 'Automatic system updates are', $s_dnf['on']);?>
					</div>
<?php if (!$s_dnf['installed']): ?>
					<small class="set-hint under-switch">dnf-automatic is not installed; turning this on installs it.</small>
<?php elseif ($s_dnf['on']): ?>
					<small class="set-hint under-switch">
						<code><?=h($s_dnf['timer']);?></code>
						<?php if ($s_dnf['next'] !== ''): ?> · next run <?=h($s_nosec($s_dnf['next']));?><?php endif; ?>
						<?php if ($s_dnf['last'] !== ''): ?> · last run <?=h($s_nosec($s_dnf['last']));?><?php endif; ?>
					</small>
<?php	if (!$s_dnf['installs']): ?>
					<small class="set-hint under-switch text-warning">This timer only downloads or reports updates<?=$s_dnf['timer'] === 'dnf-automatic.timer' ? ' (<code>apply_updates = no</code> in <code>/etc/dnf/automatic.conf</code>)' : '';?> — nothing is installed.</small>
<?php	endif; ?>
<?php endif; ?>
					<small class="set-hint under-switch">All installed packages, through dnf-automatic. Its systemd timer sets the time — around 06:00 by default.</small>
				</div>
			</div>

			<!-- ─── WordPress Toolkit ─────────────────────────────────── -->
			<div class="row set-row">
				<div class="col-md-3 set-label">WordPress Toolkit</div>
				<div class="col-md-9">
					<label class="form-label mb-1" for="pagespeed_key">Google PageSpeed Insights API key</label>
					<input type="text" name="pagespeed_key" id="pagespeed_key" value="<?=h(setting_get('pagespeed-api-key'));?>" class="form-control" style="max-width:480px;" placeholder="AIza..." autocomplete="off" spellcheck="false" maxlength="100">
					<small class="set-hint">Performance scores and site screenshots on each WordPress site's page. Free, 25,000 tests a day:
						<a href="https://developers.google.com/speed/docs/insights/v5/get-started#APIKey" target="_blank">get a key</a>
						(Google Cloud &rsaquo; enable the PageSpeed Insights API &rsaquo; create an API key).</small>
				</div>
			</div>

			<!-- ─── telemetry ─────────────────────────────────────────── -->
			<div class="row set-row">
				<div class="col-md-3 set-label">Telemetry</div>
				<div class="col-md-9">
					<label class="form-check form-switch d-inline-flex align-items-center mb-0" style="cursor:pointer">
						<input class="form-check-input" type="checkbox" name="telemetry" <?=$s_telemetry ? 'checked' : '';?>>
						<span class="form-check-label">Send basic telemetry to hub.reqad.net</span>
					</label>
					<small class="set-hint under-switch">Hostname, IP, OS and Reqad version — no personal data. Helps track installations.</small>
				</div>
			</div>

		</div>
		<div class="card-footer">
			<input type="submit" id="submit-btn" class="btn btn-primary" value="Save settings">
		</div>
		</div>
		</form>

<!-- ─── sending method modal ───────────────────────────────────────────────
     Its own form and action, outside the main one (forms cannot nest). -->
<form method="post" action="/" id="mail-method-form" autocomplete="off">
<input type="hidden" name="action" value="settings-smtp">
<input type="hidden" name="test_to" id="test_to" value="">
<div class="modal modal-blur fade" id="modal-mail-method" tabindex="-1" role="dialog" aria-hidden="true">
	<div class="modal-dialog modal-lg modal-dialog-centered" role="document">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title">Sending method</h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body">
				<div class="form-selectgroup form-selectgroup-boxes d-flex mb-3">
					<label class="form-selectgroup-item flex-fill">
						<input type="radio" name="mail-provider" value="smtp" class="form-selectgroup-input" checked>
						<div class="form-selectgroup-label d-flex align-items-center p-3">
							<div class="me-3"><span class="form-selectgroup-check"></span></div>
							<div style="font-size:16pt;font-weight:600;">SMTP</div>
						</div>
					</label>
					<label class="form-selectgroup-item flex-fill ms-2">
						<input type="radio" name="mail-provider" value="gmail" class="form-selectgroup-input" disabled>
						<div class="form-selectgroup-label d-flex align-items-center p-3">
							<div class="me-3"><span class="form-selectgroup-check"></span></div>
							<div style="font-size:16pt;font-weight:600;color:#AAA;">
								<svg xmlns="http://www.w3.org/2000/svg" width="36" height="24" viewBox="0 0 80 80" style="filter: grayscale(1) brightness(2) contrast(0.5);vertical-align:-3px;"><path fill="#4285f4" d="M6 66.0162h14v-34l-20-15v43c0 3.315 2.685 6 6 6z"/><path fill="#34a853" d="M68 66.0162h14c3.315 0 6-2.685 6-6v-43l-20 15z"/><path fill="#fbbc04" d="M68 6.0162v26l20-15v-8c0-7.415-8.465-11.65-14.4-7.2z"/><path fill="#ea4335" d="M20 32.0162v-26l24 18 24-18v26l-24 18z"/><path fill="#c5221f" d="M0 9.0162v8l20 15v-26l-5.6-4.2c-5.935-4.45-14.4-.215-14.4 7.2z"/></svg>
								Gmail <span class="badge bg-secondary-lt ms-1" style="font-size:9pt;vertical-align:3px;">coming soon</span>
							</div>
						</div>
					</label>
				</div>

				<div class="row g-2 mb-2">
					<div class="col-md-6">
						<label class="form-label required">From address</label>
						<input type="email" name="smtp_from" value="<?=h($s_smtp['from']);?>" class="form-control" placeholder="noreply@example.com" required>
						<small class="form-hint">Shown in the From field of outgoing notifications.</small>
					</div>
					<div class="col-md-6">
						<label class="form-label required">SMTP server</label>
						<input type="text" name="smtp_server" value="<?=h($s_smtp['server']);?>" class="form-control" placeholder="mail.example.com" required pattern="[A-Za-z0-9\-\.]{1,253}">
					</div>
				</div>
				<div class="row g-2 mb-2">
					<div class="col-md-6">
						<label class="form-label required">SMTP user</label>
						<input type="text" name="smtp_user" value="<?=h($s_smtp['user']);?>" class="form-control" placeholder="user@example.com" autocomplete="off" required maxlength="256">
					</div>
					<div class="col-md-6">
						<label class="form-label <?=$s_smtp['haspw'] ? '' : 'required';?>">SMTP password</label>
						<input type="password" name="smtp_password" value="" class="form-control" autocomplete="new-password"
							placeholder="<?=$s_smtp['haspw'] ? 'unchanged' : 'your-secret-password';?>" <?=$s_smtp['haspw'] ? '' : 'required';?>>
<?php if ($s_smtp['haspw']): ?>
						<small class="form-hint">Leave empty to keep the saved password.</small>
<?php endif; ?>
					</div>
				</div>
				<div class="mb-1">
					<label class="form-label required">SMTP port</label>
					<label class="form-check form-check-inline">
						<input class="form-check-input" type="radio" name="smtp_port" value="465" <?=$s_smtp['port'] !== '587' ? 'checked' : '';?>>
						<span class="form-check-label">465 - TLS/SSL</span>
					</label>
					<label class="form-check form-check-inline">
						<input class="form-check-input" type="radio" name="smtp_port" value="587" <?=$s_smtp['port'] === '587' ? 'checked' : '';?>>
						<span class="form-check-label">587 - STARTTLS</span>
					</label>
				</div>
				<small class="form-hint" id="test-hint"></small>
			</div>
			<div class="modal-footer">
				<a href="#" class="btn btn-link link-secondary" data-bs-dismiss="modal">Cancel</a>
				<button type="submit" name="test" value="1" id="smtp-test-btn" class="btn btn-outline-secondary ms-auto">Save and test</button>
				<button type="submit" id="smtp-save-btn" class="btn btn-primary">Save</button>
			</div>
		</div>
	</div>
</div>
</form>

<!-- ─── Reqad update time modal (on/off is the switch on the page) ─────── -->
<form method="post" action="/" id="auto-update-form">
<input type="hidden" name="action" value="auto-update-cron">
<div class="modal modal-blur fade" id="modal-auto-update" tabindex="-1" role="dialog" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered" role="document">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title">Reqad update time</h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body">
				<div class="row g-2">
					<div class="col-4">
						<label class="form-label">Hour</label>
						<input type="number" name="hour" value="<?=(int)$s_upd['hour'];?>" min="0" max="23" class="form-control" required>
					</div>
					<div class="col-4">
						<label class="form-label">Minute</label>
						<input type="number" name="min" value="<?=(int)$s_upd['min'];?>" min="0" max="59" class="form-control" required>
					</div>
				</div>
				<small class="form-hint mt-2 d-block">
					Runs <code>dnf --refresh -y update 'reqad*'</code> as root: the panel and every installed
					Reqad add-on, plus any newer dependencies they need. Output goes to
					<code>log/auto_update.log</code>.
				</small>
<?php if ($s_upd['on']): ?>
				<div class="text-muted mt-3" style="font-size:85%;">
					Current entry in <code>/etc/crontab</code>:<br><code><?=h($s_upd['line']);?></code>
				</div>
<?php endif; ?>
			</div>
			<div class="modal-footer">
				<a href="#" class="btn btn-link link-secondary" data-bs-dismiss="modal">Cancel</a>
				<button type="submit" class="btn btn-primary ms-auto">Save time</button>
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
	$('.alert').delay(5000).fadeOut(2000);

	/* The email drives the forwarding checkbox, not the other way round: no
	   address means nothing to forward to (unchecked + disabled), and typing
	   one into an empty field turns forwarding on. With an address filled in
	   the checkbox is the user's to clear. */
	var $email = $('#email'), $fwd = $('#root_mail_forward');
	var wasEmpty = $.trim($email.val()) === '';
	function syncForward() {
		var empty = $.trim($email.val()) === '';
		if (empty) $fwd.prop('checked', false);
		else if (wasEmpty) $fwd.prop('checked', true);
		$fwd.prop('disabled', empty);
		wasEmpty = empty;
	}
	$email.on('input', syncForward);
	syncForward();

	/* The modals are forms of their own, so submitting one reloads the page and
	   anything changed but not saved in the main form would be lost. Carry
	   those changes along as main[...]; the modal's handler saves them after
	   its own settings (settings_modal_redirect()). The snapshot is taken after
	   syncForward() so its initial adjustment does not count as a change. */
	var $main = $('#settings'), mainSaved = $main.serialize();
	$('#mail-method-form, #auto-update-form').on('submit', function (e) {
		var $f = $(this);
		$f.find('.main-carry').remove();
		if ($main.serialize() === mainSaved) return;
		/* an invalid address would be refused server-side and the page reload
		   would lose it — keep the modal's changes unsent and point at it */
		if (!$email[0].checkValidity()) {
			e.preventDefault();
			$f.find('[data-bs-dismiss="modal"]').first().click();
			$email.addClass('is-invalid').focus();
			$('#email-feedback').show();
			return;
		}
		$f.append($('<input>', { type: 'hidden', name: 'main[_]', value: '1', 'class': 'main-carry' }));
		$.each($main.serializeArray(), function (i, fld) {
			if (fld.name === 'action' || fld.name === 'csrf') return;
			$f.append($('<input>', { type: 'hidden', name: 'main[' + fld.name + ']', value: fld.value, 'class': 'main-carry' }));
		});
	});

	/* Active/Disabled badge + red label follow the switch, as in the Email
	   account modal */
	$('.state-switch').on('change', function () {
		var on = $(this).is(':checked');
		$(this).siblings('.badge').text(on ? 'Active' : 'Disabled')
			.toggleClass('bg-green-lt', on).toggleClass('bg-red-lt', !on);
		$(this).siblings('.form-check-label').toggleClass('text-red', !on);
	});
	$('#auto_update').on('change', function () {
		$('#auto-update-when').toggleClass('d-none', !$(this).is(':checked'));
	});

	$('#settings').on('submit', function (event) {
		if (!$email[0].checkValidity()) {
			event.preventDefault();
			$email.addClass('is-invalid').focus();
			$('#email-feedback').show();
			return;
		}
		$('#submit-btn').prop('value', 'Saving...').prop('disabled', true);
	});
	$email.on('input', function () { $email.removeClass('is-invalid'); $('#email-feedback').hide(); });

	/* the test goes to the address in the main form, saved or not — with no
	   valid address there is nowhere to send it, so the button is disabled */
	$('#modal-mail-method').on('show.bs.modal', function () {
		var to = $.trim($email.val());
		var ok = to !== '' && $email[0].checkValidity();
		$('#smtp-test-btn').prop('disabled', !ok);
		$('#test-hint').text(ok ? '"Save and test" sends a test message to ' + to + '.'
		                   : to ? 'The System mail address on the page is not valid — fix it to use "Save and test".'
		                        : 'Fill in the System mail address on the page to use "Save and test".');
	});
	/* Enter in a field would submit through the first submit button, which is
	   "Save and test" — send it to plain Save instead (and not to a dead
	   disabled button) */
	$('#mail-method-form').on('keydown', 'input', function (e) {
		if (e.key !== 'Enter') return;
		e.preventDefault();
		$('#smtp-save-btn').click();
	});
	$('#mail-method-form').on('submit', function () {
		$('#test_to').val($.trim($email.val()));
	});
});
</script>
</body>
</html>
