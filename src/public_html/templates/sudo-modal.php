<?php
/*
 * Sudo-mode confirmation dialog. Include it on a page whose sensitive forms
 * carry `data-sudo`: when the session's confirmation (password at login, or
 * this dialog) is older than auth-sudo-minutes, submitting such a form opens
 * the dialog first, confirms password + 2FA code over ajax-sudo, then sends
 * the form exactly as the user submitted it (same button). The server checks
 * again (security_need_sudo()), so this is the convenience, not the guard.
 */
$sudo_p = null;
if (auth_web_enabled() && ($sudo_s = auth_session_current()))
	$sudo_p = auth_principal_by_id($sudo_s['principal_id']);
$sudo_mfa = $sudo_p && (int)$sudo_p['totp_enabled'] === 1;
/* a passkey confirms on its own, instead of password + code */
$sudo_pk  = $sudo_p && auth_rp_id() !== '' && auth_passkeys_on() && auth_passkeys_list($sudo_p['id']);
?>
<div class="modal modal-blur fade" id="modal-sudo" tabindex="-1" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered modal-sm" role="document" style="max-width:26rem">
		<div class="modal-content">
			<form id="sudo-form" autocomplete="off" novalidate>
				<div class="modal-header">
					<h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Confirm it's you</h5>
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
				</div>
				<div class="modal-body">
					<p class="text-muted mb-3">This change needs your password<?=$sudo_mfa ? ' and a two-factor code' : ''?>.
						You won't be asked again for <?=auth_cfg('auth-sudo-minutes')?> minutes.</p>
					<div id="sudo-error"></div>
					<input type="text" name="username" value="<?=h($sudo_p ? $sudo_p['username'] : '')?>" autocomplete="username" hidden>
					<div class="mb-3">
						<label class="form-label" for="sudo-password">Password</label>
						<input type="password" id="sudo-password" class="form-control" autocomplete="current-password" maxlength="256">
						<div class="invalid-feedback">Enter your password.</div>
					</div>
<?php if ($sudo_mfa): ?>
					<div class="mb-1">
						<label class="form-label" for="sudo-code">Two-factor code</label>
						<input type="text" id="sudo-code" class="form-control" inputmode="numeric" autocomplete="one-time-code" maxlength="12" placeholder="6-digit code or a recovery code">
						<div class="invalid-feedback">Enter the code from your authenticator app.</div>
					</div>
<?php endif; ?>
<?php if ($sudo_pk): ?>
					<div id="sudo-passkey-wrap">
						<div class="hr-text my-3">or</div>
						<button type="button" class="btn w-100" id="sudo-passkey">Use a passkey instead</button>
					</div>
<?php endif; ?>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn" data-bs-dismiss="modal">Cancel</button>
					<button type="submit" class="btn btn-primary" id="sudo-submit">Confirm</button>
				</div>
			</form>
		</div>
	</div>
</div>
<?php if ($sudo_pk): /* the dialog needs it on every page, not only on Security */ ?>
<script src="./dist/js/passkey.js"></script>
<?php endif; ?>
<script>
(function () {
	var modal = document.getElementById('modal-sudo');
	document.body.appendChild(modal);       // outside the footer's unclosed div
	var deadline = Date.now() + <?=(int)auth_sudo_left()?> * 1000;
	var pending = null, busy = false;
	var form = document.getElementById('sudo-form'), pw = document.getElementById('sudo-password'),
	    code = document.getElementById('sudo-code'), err = document.getElementById('sudo-error'),
	    btn = document.getElementById('sudo-submit');

	function resend() {
		var f = pending.form, b = pending.submitter;
		pending = null;
		if (f.requestSubmit) { f.requestSubmit(b || undefined); return; }
		if (b && b.name) {                          // old browsers: keep the clicked button's value
			var h = document.createElement('input');
			h.type = 'hidden'; h.name = b.name; h.value = b.value;
			f.appendChild(h);
		}
		f.submit();
	}

	// Capture phase: runs before any other submit handler of the form.
	document.addEventListener('submit', function (e) {
		var f = e.target;
		if (!f.hasAttribute || !f.hasAttribute('data-sudo') || Date.now() < deadline - 5000)
			return;
		e.preventDefault();
		e.stopImmediatePropagation();
		pending = { form: f, submitter: e.submitter || null };
		err.innerHTML = '';
		form.reset();
		pw.classList.remove('is-invalid');
		if (code) code.classList.remove('is-invalid');
		bootstrap.Modal.getOrCreateInstance(modal).show();
	}, true);

	modal.addEventListener('shown.bs.modal', function () { pw.focus(); });
	modal.addEventListener('hidden.bs.modal', function () { pending = null; });

	function confirm() {
		if (busy || !pending) return;
		var bad = null;
		if (pw.value === '') { pw.classList.add('is-invalid'); bad = bad || pw; }
		if (code && code.value.trim() === '') { code.classList.add('is-invalid'); bad = bad || code; }
		if (bad) { bad.focus(); return; }
		busy = true;
		btn.disabled = true;
		jQuery.post('/', { action: 'ajax-sudo', password: pw.value, code: code ? code.value : '' }, null, 'json')
			.done(function (r) {
				if (r && r.ok) {
					confirmed(r.left);
				} else {
					err.innerHTML = '<div class="alert alert-danger py-2">' + jQuery('<div>').text((r && r.error) || 'Could not confirm.').html() + '</div>';
					if (code) code.value = '';
					(r && /two-factor/i.test(r.error) && code ? code : pw).focus();
				}
			})
			.fail(function () { err.innerHTML = '<div class="alert alert-danger py-2">Could not reach the panel. Try again.</div>'; })
			.always(function () { busy = false; btn.disabled = false; });
	}
	function confirmed(left) {
		deadline = Date.now() + left * 1000;
		var p = pending;
		bootstrap.Modal.getOrCreateInstance(modal).hide();
		pending = p;
		resend();
	}

	// passkey instead of password + code: options -> device prompt -> verify
	var pkBtn = document.getElementById('sudo-passkey');
	if (pkBtn && !(window.ReqadPasskey && ReqadPasskey.supported()))
		document.getElementById('sudo-passkey-wrap').classList.add('d-none');    /* the "or" line too */
	else if (pkBtn) pkBtn.addEventListener('click', function () {
		if (busy || !pending) return;
		busy = true;
		pkBtn.disabled = true;
		err.innerHTML = '';
		jQuery.post('/', { action: 'ajax-sudo-passkey-options' }, null, 'json')
			.then(function (o) { if (o.error) throw new Error(o.error); return ReqadPasskey.get(o); })
			.then(function (a) { a.action = 'ajax-sudo-passkey'; return jQuery.post('/', a, null, 'json'); })
			.then(function (r) {
				busy = false; pkBtn.disabled = false;
				if (!r || !r.ok) throw new Error((r && r.error) || 'Could not confirm.');
				confirmed(r.left);
			})
			.catch(function (e) {
				busy = false; pkBtn.disabled = false;
				var msg = e && e.name && e.name !== 'Error' ? ReqadPasskey.message(e) : ((e && e.message) || 'Could not confirm.');
				err.innerHTML = '<div class="alert alert-danger py-2">' + jQuery('<div>').text(msg).html() + '</div>';
			});
	});

	form.addEventListener('submit', function (e) { e.preventDefault(); confirm(); });
	pw.addEventListener('input', function () { pw.classList.remove('is-invalid'); });
	if (code) code.addEventListener('input', function () {
		code.classList.remove('is-invalid');
		// six digits in and the password filled: go, like the login page
		if (pw.value !== '' && /^\d{6}$/.test(code.value.replace(/\s+/g, ''))) confirm();
	});
})();
</script>
