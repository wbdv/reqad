<?php
	/* Settings > Security: panel login, two-factor auth, sessions, blocked IPs,
	   security log. Actions: modules/security_actions.php. */
	include_once 'modules/security_actions.php';
	$sec_p      = auth_ready() ? security_principal() : null;
	$sec_web    = auth_web_enabled();
	$sec_cur    = $sec_web ? auth_session_current() : null;
	$sec_codes  = isset($security_new_codes) && is_array($security_new_codes) ? $security_new_codes : null;
	$sec_fw     = auth_ready() ? security_fw_state() : array('csf' => false);
	$sec_time   = function ($ts) { return $ts ? date('Y-m-d H:i', (int)$ts) : '-'; };
	$sec_ago    = function ($ts) {
		$d = time() - (int)$ts;
		if ($d < 90)    return 'just now';
		if ($d < 3600)  return floor($d / 60).' min ago';
		if ($d < 86400) return floor($d / 3600).' h ago';
		return floor($d / 86400).' days ago';
	};
	include('templates/header.php');
?>
        <div class="page-header d-print-none">
            <div class="row align-items-center">
              <div class="col" style="padding-left:22px;">
                <div class="page-pretitle">Settings</div>
                <h2 class="page-title">Security</h2>
              </div>
            </div>
        </div>

<?php msg_render(); ?>

<style>
	#security .set-row { padding: 1.25rem 0; border-top: 1px solid rgba(98,105,118,.16); }
	#security .set-row:first-child { border-top: 0; padding-top: 0; }
	#security .set-label { font-weight: 600; padding-top: 2px; }
	#security .set-hint { display: block; color: #656d77; font-size: 90%; margin-top: 4px; font-weight: 400; }
	.recovery-codes { font-family: monospace; font-size: 1.05rem; columns: 2; max-width: 22rem; }
	#security .table td, #security .table th { vertical-align: middle; }
	#totp-qr img, #totp-qr canvas { border: 8px solid #fff; }
</style>

<div id="security">

<?php if (!auth_ready()): ?>
	<div class="alert alert-warning">The login tables are missing. Run the Reqad update (database 1037) first.</div>
<?php else: ?>

<?php if ($sec_codes): ?>
	<div class="modal modal-blur fade" id="modal-recovery-codes" tabindex="-1" data-bs-backdrop="static" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered" role="document">
			<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Your recovery codes</h5>
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
				</div>
				<div class="modal-body">
					<p>Each code logs you in once if you lose your phone. <strong>They are shown only now</strong> - store them in your password manager or print them.</p>
					<div class="recovery-codes mb-3" id="recovery-codes"><?php foreach ($sec_codes as $c) echo '<div>'.h($c).'</div>'; ?></div>
					<div class="d-flex flex-wrap gap-2 align-items-center">
					<button type="button" class="btn" id="copy-codes"><svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M7 7m0 2.667a2.667 2.667 0 0 1 2.667 -2.667h8.666a2.667 2.667 0 0 1 2.667 2.667v8.666a2.667 2.667 0 0 1 -2.667 2.667h-8.666a2.667 2.667 0 0 1 -2.667 -2.667z" /><path d="M4.012 16.737a2.005 2.005 0 0 1 -1.012 -1.737v-10c0 -1.1 .9 -2 2 -2h10c.75 0 1.158 .385 1.5 1" /></svg> <span>Copy</span></button>
					<button type="button" class="btn" id="download-codes"
						data-file="reqad-recovery-codes-<?=h(preg_replace('/[^A-Za-z0-9.-]/', '', gethostname()))?>.txt"
						data-head="Reqad recovery codes - user <?=h($sec_p ? $sec_p['username'] : '')?> on <?=h(gethostname())?>, <?=date('Y-m-d H:i')?>"><svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2" /><path d="M7 11l5 5l5 -5" /><path d="M12 4l0 12" /></svg> Download</button>
					<button type="button" class="btn btn-primary ms-auto" data-bs-dismiss="modal">Close</button>
					</div>
				</div>
			</div>
		</div>
	</div>
<?php endif; ?>

	<div class="card mb-3">
	<div class="card-body">

		<!-- ─── login mode ─────────────────────────────────────── -->
		<div class="row set-row">
			<div class="col-md-3 set-label">Panel login</div>
			<div class="col-md-9">
<?php if ($sec_web): ?>
				<span class="badge bg-green-lt">Web login</span>
				<span class="set-hint">Sessions end after <?=auth_cfg('auth-idle-minutes')?> minutes without activity and <?=auth_cfg('auth-max-hours')?> hours after login.
					<?=auth_cfg('auth-lock-failures')?> failed attempts lock an address out of the form for <?=auth_cfg('auth-lock-minutes')?> minutes;
					<?=auth_cfg('auth-ban-failures')?> within an hour block it in the firewall (csf), and repeat offenders are blocked permanently.
					Sensitive changes on this page ask for your password<?=($sec_p && (int)$sec_p['totp_enabled'] === 1) ? ' and 2FA code' : ''?> again once your last confirmation is more than <?=auth_cfg('auth-sudo-minutes')?> minutes old.</span>
<?php else: ?>
				<span class="badge bg-yellow-lt">Browser password prompt (nginx basic auth)</span>
				<span class="set-hint">Switch to the web login page - with two-factor authentication and brute-force protection - by running
					<code>reqad auth mode web</code> on the server as root. The password below is used by both.</span>
<?php endif; ?>
			</div>
		</div>

		<!-- ─── password ───────────────────────────────────────── -->
		<div class="row set-row">
			<div class="col-md-3 set-label">Password
				<span class="set-hint">User <strong><?=h($sec_p ? $sec_p['username'] : '-')?></strong><?php if ($sec_p && $sec_p['pw_changed_at']): ?>, changed <?=h($sec_ago($sec_p['pw_changed_at']))?><?php endif; ?></span>
			</div>
			<div class="col-md-9">
				<button type="button" class="btn" id="show-password-form">Change password</button>
				<form method="post" action="/" autocomplete="off" style="max-width:28rem" class="d-none" id="password-form" data-sudo>
					<input type="hidden" name="action" value="security-password">
					<input type="text" name="username" value="<?=h($sec_p ? $sec_p['username'] : '')?>" autocomplete="username" hidden>
					<div class="mb-2"><input type="password" name="new_password" class="form-control" placeholder="New password (at least <?=auth_cfg('auth-min-password')?> characters)" autocomplete="new-password" required></div>
					<div class="mb-2"><input type="password" name="new_password2" class="form-control" placeholder="Repeat the new password" autocomplete="new-password" required></div>
					<button type="submit" class="btn btn-primary">Change password</button>
					<button type="button" class="btn btn-link link-secondary" id="hide-password-form">Cancel</button>
					<span class="set-hint">Other sessions are logged out.</span>
				</form>
			</div>
		</div>

		<!-- ─── two-factor ─────────────────────────────────────── -->
		<div class="row set-row" id="twofactor">
			<div class="col-md-3 set-label">Two-factor authentication</div>
			<div class="col-md-9">
<?php
	$tfa_on      = $sec_p && (int)$sec_p['totp_enabled'] === 1;
	$tfa_pending = !$tfa_on && $sec_p && $sec_p['totp_pending'] !== '';
	/* What flipping the switch does: on -> off turns 2FA off, off -> on starts
	   the setup (both need sudo mode), and during setup it cancels it. */
	$tfa_action  = $tfa_on ? 'security-2fa-disable' : 'security-2fa-start';
?>
				<form method="post" action="/" id="tfa-toggle"<?=$tfa_pending ? '' : ' data-sudo'?>>
					<input type="hidden" name="action" value="<?=$tfa_action?>">
<?php if ($tfa_pending): ?>
					<input type="hidden" name="cancel" value="1">
<?php endif; ?>
					<label class="form-check form-switch d-inline-flex align-items-center mb-0" style="cursor:pointer">
						<input class="form-check-input state-toggle" type="checkbox" style="margin-right:10px"<?=($tfa_on || $tfa_pending) ? ' checked' : ''?><?=$sec_p ? '' : ' disabled'?>>
						<span class="form-check-label<?=($tfa_on || $tfa_pending) ? '' : ' text-red'?>">Two-factor</span>
<?php if ($tfa_pending): ?>
						<span class="badge border ms-2 bg-yellow-lt">Setting up</span>
<?php else: ?>
						<span class="badge border ms-2 <?=$tfa_on ? 'bg-green-lt' : 'bg-red-lt'?>"><?=$tfa_on ? 'Active' : 'Disabled'?></span>
<?php endif; ?>
					</label>
				</form>
				<span class="set-hint mb-3">A 6-digit code from an authenticator app (Google Authenticator, Aegis, 1Password, Bitwarden...) after the password.</span>
<?php if ($tfa_on): ?>
				<div class="d-flex align-items-center gap-3">
					<form method="post" action="/" data-sudo>
						<button type="submit" name="action" value="security-2fa-codes" class="btn">New recovery codes</button>
					</form>
					<span class="text-muted"><?=auth_recovery_remaining($sec_p['id'])?> unused recovery codes left.</span>
				</div>
<?php elseif ($sec_p && $sec_p['totp_pending'] !== ''): ?>
				<?php $sec_uri = auth_totp_uri($sec_p['totp_pending'], $sec_p['username']); ?>
				<div class="d-flex flex-wrap gap-4 align-items-start">
					<div id="totp-qr" data-uri="<?=h($sec_uri)?>" style="background:#fff"></div>
					<div style="max-width:24rem">
						<p class="mb-2">1. Scan the code with your authenticator app. Can't scan? Enter this key:</p>
						<p><code style="word-break:break-all"><?=h(trim(chunk_split($sec_p['totp_pending'], 4, ' ')))?></code></p>
						<p class="mb-2">2. Enter the 6-digit code the app shows:</p>
						<form method="post" action="/" autocomplete="off" class="d-flex gap-2">
							<input type="hidden" name="action" value="security-2fa-enable">
							<input type="text" name="code" class="form-control" style="max-width:9rem" inputmode="numeric" autocomplete="one-time-code" maxlength="7" placeholder="000000" required autofocus>
							<button type="submit" class="btn">Turn on</button>
						</form>

					</div>
				</div>
<?php else: ?>
<?php if (!$sec_web): ?>
				<span class="set-hint">It is asked for only with the web login (<code>reqad auth mode web</code>).</span>
<?php endif; ?>
<?php endif; ?>
			</div>
		</div>

		<!-- ─── passkeys ───────────────────────────────────────── -->
		<div class="row set-row" id="passkeys">
			<div class="col-md-3 set-label">Passkeys
				<span class="set-hint">Log in with your fingerprint, face or device PIN - no password, no code.</span>
			</div>
			<div class="col-md-9">
<?php
	$pk_list = ($sec_web && $sec_p) ? auth_passkeys_list($sec_p['id']) : array();
	$pk_on   = auth_passkeys_on();
	$pk_ok   = $sec_web && $sec_p && auth_rp_id() !== '';
	/* same switch + badge as the others; turning it on needs sudo mode */
?>
				<form method="post" action="/" id="pk-toggle" class="mb-1"<?=$pk_on ? '' : ' data-sudo'?>>
					<input type="hidden" name="action" value="security-passkey">
					<input type="hidden" name="op" value="<?=$pk_on ? 'off' : 'on'?>">
					<label class="form-check form-switch d-inline-flex align-items-center mb-0" style="cursor:pointer">
						<input class="form-check-input state-toggle" type="checkbox" style="margin-right:10px"<?=$pk_on ? ' checked' : ''?><?=$pk_ok ? '' : ' disabled'?>>
						<span class="form-check-label<?=$pk_on ? '' : ' text-red'?>">Passkeys</span>
						<span class="badge border ms-2 <?=$pk_on ? 'bg-green-lt' : 'bg-red-lt'?>"><?=$pk_on ? 'Active' : 'Disabled'?></span>
					</label>
				</form>
<?php if (!$sec_web): ?>
				<span class="set-hint">Available with the web login (<code>reqad auth mode web</code>).</span>
<?php elseif (auth_rp_id() === ''): ?>
				<span class="set-hint">Open the panel by its hostname (not its IP address) to use passkeys - they are bound to the hostname.</span>
<?php elseif (!$pk_on): ?>
				<span class="set-hint">Turn on to log in with a passkey instead of the password<?=($sec_p && (int)$sec_p['totp_enabled'] === 1) ? ' and 2FA code' : ''?>.<?php if ($pk_list): ?>
					<?=count($pk_list)?> saved passkey<?=count($pk_list) === 1 ? ' is' : 's are'?> kept but cannot log in while this is off.<?php endif; ?></span>
<?php else: ?>
				<span class="set-hint mb-3">The login page offers "Log in with a passkey", and the confirmation dialog accepts one instead of the password.</span>
<?php if ($pk_list): ?>
				<div class="table-responsive mb-3" style="max-width:44rem">
					<table class="table table-sm table-vcenter mb-0">
						<thead><tr><th>Name</th><th>Added</th><th>Last used</th><th></th></tr></thead>
						<tbody>
<?php foreach ($pk_list as $k): ?>
						<tr>
							<td><?=h($k['name'])?></td>
							<td><?=h($sec_time($k['created_at']))?></td>
							<td><?=$k['last_used'] ? h($sec_ago($k['last_used'])).' <span class="text-muted">from '.h($k['last_ip']).'</span>' : '<span class="text-muted">never</span>'?></td>
							<td class="text-end">
								<form method="post" action="/" class="d-inline">
									<input type="hidden" name="action" value="security-passkey">
									<button type="submit" name="passkey" value="<?=(int)$k['id']?>" class="btn btn-sm" onclick="return confirm('Remove this passkey? It will no longer log in.')">Remove</button>
								</form>
							</td>
						</tr>
<?php endforeach; ?>
						</tbody>
					</table>
				</div>
<?php endif; ?>
				<form method="post" action="/" id="passkey-add" class="d-flex gap-2" style="max-width:32rem" autocomplete="off" data-sudo>
					<input type="text" name="name" class="form-control" maxlength="60" placeholder="Name, e.g. Laptop or Phone" autocomplete="off">
					<button type="submit" class="btn text-nowrap">Add a passkey</button>
				</form>
				<div id="passkey-add-error" class="text-danger small mt-2"></div>
				<span class="set-hint">Your browser or password manager (Windows Hello, Touch ID, Android, iCloud Keychain, 1Password, Bitwarden, a security key...) keeps the key; the panel stores only its public half.
					A passkey works on <strong><?=h(auth_rp_id())?></strong> only.</span>
<?php endif; ?>
			</div>
		</div>

		<!-- ─── new devices ────────────────────────────────────── -->
<?php
	$nd_state  = $sec_p ? auth_email_code_state($sec_p) : 'no-email';
	$nd_alert  = auth_login_alert_on();
	$nd_email  = $sec_p ? $sec_p['email'] : '';
	$nd_smtp   = smtp_configured();
	$nd_verify = $sec_cur ? auth_challenge($sec_cur['id'], 'verify') : null;
	$nd_vt     = $nd_verify ? json_decode($nd_verify['target'], true) : null;
	/* a switch (login alerts): same markup as the others; sudo when turning it off */
	$nd_switch = function ($id, $on, $label, $op_on, $op_off, $badge = null) use ($sec_web, $sec_p) { ?>
				<form method="post" action="/" id="<?=$id?>" class="mb-1"<?=$on ? ' data-sudo' : ''?>>
					<input type="hidden" name="action" value="security-email">
					<input type="hidden" name="op" value="<?=$on ? $op_off : $op_on?>">
					<label class="form-check form-switch d-inline-flex align-items-center mb-0" style="cursor:pointer">
						<input class="form-check-input state-toggle" type="checkbox" style="margin-right:10px"<?=$on ? ' checked' : ''?><?=($sec_web && $sec_p) ? '' : ' disabled'?>>
						<span class="form-check-label<?=$on ? '' : ' text-red'?>"><?=$label?></span>
<?php if ($badge): ?>
						<span class="badge border ms-2 <?=$badge[1]?>"><?=$badge[0]?></span>
<?php else: ?>
						<span class="badge border ms-2 <?=$on ? 'bg-green-lt' : 'bg-red-lt'?>"><?=$on ? 'Active' : 'Disabled'?></span>
<?php endif; ?>
					</label>
				</form>
<?php }; ?>
		<div class="row set-row" id="new-devices">
			<div class="col-md-3 set-label">New devices
				<span class="set-hint">A browser that has not logged in here before.</span>
			</div>
			<div class="col-md-9">
<?php if (!$sec_web): ?>
				<span class="set-hint mt-0">Available with the web login (<code>reqad auth mode web</code>).</span>
<?php else: ?>
<?php
	/* The switch is the setting; the badge says whether the code is asked for now
	   (it also follows 2FA and the sending method). badge, class, hint */
	$nd_code = auth_email_code_on();
	if (!$nd_code)
		$nd_state = 'off';
	$nd_status = array(
		'active'   => array('Active', 'bg-green-lt', 'Two-factor authentication is off, so after the right password a new device must also enter a 6-digit code sent to your email. Stops someone who has your password but not your mailbox.'),
		'2fa'      => array('Not needed', 'bg-secondary-lt', 'Two-factor authentication is on - the authenticator code already stops someone who only has your password. The email code comes back on by itself if you turn two-factor off.'),
		'no-smtp'  => array('Waiting for email', 'bg-yellow-lt', 'Two-factor authentication is off, but the panel cannot send email yet, so new devices log in with the password only. Set up the sending method under <a href="/settings/">Settings</a> and the code is asked for from then on.'),
		'no-email' => array('Waiting for email', 'bg-yellow-lt', 'Two-factor authentication is off, but there is no email address to send the code to. Enter it below.'),
		'off'      => array('Off', 'bg-red-lt', 'Turned off. While two-factor authentication is off, a new device logs in with the password only. Turn on two-factor authentication, or this switch, to keep a second step.'),
	);
	list($nd_b, $nd_bc, $nd_hint) = $nd_status[$nd_state];
?>
<?php $nd_switch('nd-code', $nd_code, 'Email code', 'code-on', 'code-off', array($nd_b, $nd_bc)); ?>
				<span class="set-hint mb-3"><?=$nd_hint?></span>
<?php $nd_switch('nd-alert', $nd_alert, 'Login alerts', 'alert-on', 'alert-off'); ?>
				<span class="set-hint mb-3">An email after each login from a new device, with its IP address and browser.</span>

				<div class="mb-3" style="max-width:32rem">
					<label class="form-label mb-1" for="nd-email">Send to</label>
					<form method="post" action="/" class="d-flex gap-2" autocomplete="off" data-sudo>
						<input type="hidden" name="action" value="security-email">
						<input type="hidden" name="op" value="address">
						<input type="email" name="email" id="nd-email" class="form-control" value="<?=h($nd_email)?>" placeholder="you@example.com" autocomplete="off" required maxlength="254">
						<button type="submit" class="btn">Save</button>
					</form>
<?php if ($nd_email !== '' && $nd_email === auth_setting('auth-email-verified', '')): ?>
					<span class="set-hint"><span class="text-green">Confirmed</span> - a code sent there was entered.</span>
<?php elseif ($nd_email !== '' && $nd_smtp): ?>
					<span class="set-hint d-flex align-items-center gap-2"><span>Not confirmed yet. Make sure the codes arrive:</span>
						<button type="submit" form="nd-test" class="btn btn-sm">Send a test code</button></span>
					<form method="post" action="/" id="nd-test" class="d-none">
						<input type="hidden" name="action" value="security-email">
						<input type="hidden" name="op" value="test">
					</form>
<?php endif; ?>
					<span class="set-hint">A new address gets a code first, and is used once you enter it.</span>
<?php if (!$nd_smtp): ?>
					<span class="set-hint text-red">Email needs a sending method: set one up under <a href="/settings/">Settings</a> first.</span>
<?php endif; ?>
				</div>
<?php endif; ?>
			</div>
		</div>
<?php if ($nd_verify && is_array($nd_vt)): ?>
	<div class="modal modal-blur fade" id="modal-email-verify" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
		<div class="modal-dialog modal-dialog-centered" role="document" style="max-width:28rem">
			<div class="modal-content">
				<form method="post" action="/" autocomplete="off">
					<input type="hidden" name="action" value="security-email">
					<div class="modal-header">
						<h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Check your email</h5>
					</div>
					<div class="modal-body">
						<p>We sent a 6-digit code to <strong><?=h($nd_vt['email'])?></strong>. Enter it to confirm the address<?=$nd_vt['then'] === 'code' ? ' and turn on email codes' : ($nd_vt['then'] === 'alert' ? ' and turn on login alerts' : '')?>.</p>
						<input type="text" name="code" id="nd-verify-code" class="form-control" style="font-size:1.4rem;letter-spacing:.4em;text-align:center;max-width:12rem" inputmode="numeric" autocomplete="one-time-code" maxlength="7" placeholder="000000">
						<p class="text-muted mt-3 mb-0">No email? Check the spam folder, or
							<button type="submit" name="op" value="resend" class="btn btn-link p-0 align-baseline">send a new code</button>.</p>
					</div>
					<div class="modal-footer">
						<button type="submit" name="op" value="cancel" class="btn">Cancel</button>
						<button type="submit" name="op" value="verify" class="btn btn-primary ms-auto" id="nd-verify-btn">Confirm</button>
					</div>
				</form>
			</div>
		</div>
	</div>
<?php endif; ?>

<?php if (!empty($sec_fw['csf'])):
	$fw_on   = !empty($sec_fw['on']);
	$fw_list = auth_fw_list();
	$fw_ip   = auth_ip(); ?>
		<!-- ─── panel IP restriction (csf) ─────────────────────── -->
		<div class="row set-row" id="firewall">
			<div class="col-md-3 set-label">IP restriction
				<span class="set-hint">Only the addresses you list can reach the panel, enforced by the firewall.</span>
			</div>
			<div class="col-md-9">
				<form method="post" action="/" class="mb-1" id="fw-toggle"<?=$fw_on ? ' data-sudo' : ''?>>
					<input type="hidden" name="action" value="security-fw">
					<input type="hidden" name="op" value="<?=$fw_on ? 'off' : 'on'?>">
					<?php /* same switch + badge as the others; turning it off needs sudo mode */ ?>
					<label class="form-check form-switch d-inline-flex align-items-center mb-0" style="cursor:pointer">
						<input class="form-check-input state-toggle" type="checkbox" id="fw-switch" style="margin-right:10px"<?=$fw_on ? ' checked' : ''?><?=$sec_fw['pending'] >= 0 ? ' disabled' : ''?>>
						<span class="form-check-label<?=$fw_on ? '' : ' text-red'?>">Restriction</span>
						<span class="badge border ms-2 <?=$fw_on ? 'bg-green-lt' : 'bg-red-lt'?>"><?=$fw_on ? 'Active' : 'Disabled'?></span>
					</label>
				</form>
<?php if ($sec_fw['reverted']): ?>
				<div class="alert alert-warning mt-2">The last firewall change was not confirmed in time and was undone.</div>
<?php endif; ?>
<?php if ($sec_fw['pending'] >= 0): ?>
				<div class="modal modal-blur fade" id="modal-fw-confirm" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-hidden="true">
					<div class="modal-dialog modal-dialog-centered" role="document">
						<div class="modal-content">
							<div class="modal-header">
								<h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Check that you can still reach the panel</h5>
							</div>
							<div class="modal-body">
								<?php $fw_test_url = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/'; ?>
								<p>The new firewall rules are active. This page keeps working either way, because your browser is still using
									its existing connection. A private (incognito) window starts a fresh connection, exactly like a new visit:</p>
								<div class="d-flex align-items-center gap-2 mb-1">
									<a href="<?=h($fw_test_url)?>" id="fw-test-link" class="fw-bold" target="_blank" rel="noopener"><?=h($fw_test_url)?></a>
									<button type="button" class="btn btn-sm" id="fw-test-copy">Copy link</button>
								</div>
								<p class="text-muted small" id="fw-test-hint">Right-click the link and choose <em>Open link in incognito / private window</em>, or copy it and paste it
									into one (Ctrl+Shift+N in Chrome and Edge, Ctrl+Shift+P in Firefox, Cmd+Shift+N in Safari).
									If the login page opens there, keep the rules.</p>
								<p class="mb-0">Not confirmed, they are undone automatically in
									<strong id="fw-countdown" data-left="<?=(int)$sec_fw['pending']?>"><?=(int)$sec_fw['pending']?> s</strong>.</p>
							</div>
							<div class="modal-footer">
								<form method="post" action="/" class="d-flex gap-2">
									<input type="hidden" name="action" value="security-fw">
									<button type="submit" name="op" value="undo" class="btn">Discard</button>
									<button type="submit" name="op" value="keep" class="btn btn-primary">Keep the rules</button>
								</form>
							</div>
						</div>
					</div>
				</div>
<?php endif; ?>
<?php if (!$fw_on): ?>
				<span class="set-hint">Port <?=(int)$sec_fw['port']?> is open to every address. Turn on to let only chosen addresses reach the panel - your current address (<?=h($fw_ip)?>) is added
					automatically, and you get two minutes to check that you can still log in before the change is kept.</span>
<?php else: ?>
				<span class="set-hint mb-3">Only the addresses below can reach port <?=(int)$sec_fw['port']?>. Add any other server that calls the panel, too.
					Locked out after your address changed? Run <code>reqad auth firewall off</code> on the server as root.</span>
<?php if (!empty($sec_fw['port_open'])): ?>
				<div class="alert alert-warning">Port <?=(int)$sec_fw['port']?> is still listed in csf's <code>TCP_IN</code> or <code>TCP6_IN</code>, so every address can reach it anyway. Turn the restriction off and on again to fix it.</div>
<?php endif; ?>
<?php if ($fw_list): ?>
				<div class="table-responsive mb-3" style="max-width:44rem">
					<table class="table table-sm table-vcenter mb-0">
						<thead><tr><th>Address</th><th>Note</th><th></th></tr></thead>
						<tbody>
<?php foreach ($fw_list as $e): ?>
						<tr>
							<td><?=h($e['cidr'])?><?php if (auth_ip_in_cidr($fw_ip, $e['cidr'])): ?> <span class="badge bg-blue-lt ms-1">Your address</span><?php endif; ?></td>
							<td class="text-muted"><?=h($e['note'])?></td>
							<td class="text-end">
								<form method="post" action="/" class="d-inline">
									<input type="hidden" name="action" value="security-fw">
									<input type="hidden" name="cidr" value="<?=h($e['cidr'])?>">
									<button type="submit" name="op" value="remove" class="btn btn-sm">Remove</button>
								</form>
							</td>
						</tr>
<?php endforeach; ?>
						</tbody>
					</table>
				</div>
<?php endif; ?>
				<form method="post" action="/" autocomplete="off" class="d-flex gap-2" style="max-width:44rem" data-sudo>
					<input type="hidden" name="action" value="security-fw">
					<input type="hidden" name="op" value="add">
					<input type="text" name="cidr" class="form-control" placeholder="IP or network (203.0.113.0/24)" autocomplete="off" required>
					<input type="text" name="note" class="form-control" placeholder="Note (optional)" maxlength="60" autocomplete="off">
					<button type="submit" class="btn text-nowrap">Add address</button>
				</form>
<?php endif; ?>
			</div>
		</div>
<?php endif; /* csf */ ?>

		<!-- ─── shell access for new hosting accounts ──────────── -->
		<div class="row set-row">
			<div class="col-md-3 set-label">Shell for new accounts
				<span class="set-hint">SSH, cron and web terminal of accounts created from now on</span>
			</div>
			<div class="col-md-9">
				<form method="post" action="/" class="d-flex flex-wrap align-items-center gap-2">
					<input type="hidden" name="action" value="security-shell-default">
					<select name="shell" class="form-select" style="max-width:18rem">
<?php foreach (account_shell_modes() as $sh_k => $sh_l): ?>
						<option value="<?=h($sh_k)?>" <?=account_shell_default() === $sh_k ? 'selected' : ''?>><?=h($sh_k === 'full' ? 'Full - normal shell' : ($sh_k === 'jailed' ? 'Jailed - own home only' : 'None - SFTP only'))?></option>
<?php endforeach; ?>
					</select>
					<button type="submit" class="btn">Save</button>
					<span class="set-hint">Existing accounts keep theirs; change one in Accounts &gt; Manage.<?=account_shell_full_allowed() ? '' : ' Full shell access is off on this server (root_access=0 in server-software.ini).'?></span>
				</form>
			</div>
		</div>

		<!-- ─── command logging (app/functions/shelllog.php) ──────── -->
<?php $sl = shelllog_status(); ?>
		<div class="row set-row" id="shell-log">
			<div class="col-md-3 set-label">Log commands run by hosting accounts
				<span class="set-hint">Every program they start over SSH, in the Terminal, from cron and from their sites</span>
			</div>
			<div class="col-md-9">
				<form method="post" action="/" class="mb-1"<?=$sl['enabled'] ? ' data-sudo' : ''?>>
					<input type="hidden" name="action" value="security-shell-log">
					<input type="hidden" name="op" value="<?=$sl['enabled'] ? 'off' : 'on'?>">
					<input type="hidden" name="days" value="<?=(int)$sl['days']?>">
					<label class="form-check form-switch d-inline-flex align-items-center mb-0" style="cursor:pointer">
						<input class="form-check-input state-toggle" type="checkbox" style="margin-right:10px"<?=$sl['enabled'] ? ' checked' : ''?>>
						<span class="form-check-label<?=$sl['enabled'] ? '' : ' text-red'?>">Command logging</span>
						<span class="badge border ms-2 <?=$sl['enabled'] ? 'bg-green-lt' : 'bg-red-lt'?>"><?=$sl['enabled'] ? 'Active' : 'Disabled'?></span>
					</label>
				</form>
				<form method="post" action="/" class="d-flex flex-wrap align-items-center gap-2 mt-2">
					<input type="hidden" name="action" value="security-shell-log">
					<span class="text-muted">Keep logged commands</span>
					<select name="days" class="form-select form-select-sm" style="width:auto">
<?php foreach (array(7, 30, 90, 180) as $d): ?>
						<option value="<?=$d?>" <?=$sl['days'] === $d ? 'selected' : ''?>><?=$d?> days</option>
<?php endforeach; ?>
					</select>
					<button type="submit" class="btn btn-sm">Save</button>
				</form>
<?php if ($sl['enabled'] && $sl['rule_error'] !== ''): ?>
				<div class="alert alert-warning mt-2 mb-0">Commands are not being logged: <?=h($sl['rule_error'])?></div>
<?php endif; ?>
				<span class="set-hint">The kernel's audit system records them (auditd), so an account cannot avoid or remove it, also in the jailed shell.
					Commands built into the shell (<code>cd</code>, <code>echo &gt; file</code>) start no program and are not seen.
					Commands that look like abuse (downloaded code run straight away, reverse shells, miners, programs in temporary folders, attempts at
					sudo or at other accounts' files) are flagged and show under Needs attention on the dashboard; the accounts never see this log.
					<?=$sl['enabled'] ? 'Turning it off removes the audit rule; what was logged stays until it is older than the days kept.' : 'Off by default; on, it adds Accounts &amp; Domains &gt; Shell Activity.'?>
					Also <code>reqad shelllog</code>.</span>
<?php if ($sl['enabled']): ?>
				<a href="/shell-activity/" class="d-inline-block mt-2">&gt; See shell activity</a>
<?php endif; ?>
			</div>
		</div>

		<!-- ─── server SSH: password login + root's keys (managed on SSH Keys) ── -->
<?php
	$ssh_pw_on   = !preg_match('/^passwordauthentication\s+no\b/mi', (string)shell_exec('sudo sshd -T 2>/dev/null | grep -i "^passwordauthentication"'));
	$ssh_root_n  = (int)trim((string)shell_exec("sudo grep -cE '^(ssh-|ecdsa-|sk-)' /root/.ssh/authorized_keys 2>/dev/null"));
?>
		<div class="row set-row" id="ssh">
			<div class="col-md-3 set-label">Server SSH login
				<span class="set-hint">Logging in to the server itself, as root or as a hosting account</span>
			</div>
			<div class="col-md-9 d-flex flex-wrap align-items-center gap-2">
				<span class="badge border <?=$ssh_pw_on ? 'bg-yellow-lt' : 'bg-green-lt'?>"><?=$ssh_pw_on ? 'Password login on' : 'Keys only'?></span>
				<span class="badge border bg-azure-lt"><?=$ssh_root_n?> key<?=$ssh_root_n === 1 ? '' : 's'?> for root</span>
				<a href="/ssh-keys/" class="btn ms-md-2">SSH keys and password login</a>
				<span class="set-hint w-100 mt-1">Add and remove the keys of root and of each account, and turn password login off once your keys work.
<?php if (feature_enabled($ini, 'mcp')): ?>
					AI assistants log in with a key for root (<a href="/ai-access/">AI access</a>).
<?php endif; ?>
				</span>
			</div>
		</div>

	</div>
	</div>

<?php if ($sec_web && $sec_p): $sec_sessions = auth_sessions_list($sec_p['id']); ?>
	<div class="card mb-3">
		<div class="card-header">
			<h3 class="card-title">Active sessions</h3>
<?php if (count($sec_sessions) > 1): ?>
			<form method="post" action="/" class="ms-auto">
				<input type="hidden" name="action" value="security-sessions-revoke-all">
				<button type="submit" class="btn btn-sm">Log out all other sessions</button>
			</form>
<?php endif; ?>
		</div>
		<div class="table-responsive">
			<table class="table card-table table-vcenter">
				<thead><tr><th>Browser</th><th>IP address</th><th>Logged in</th><th>Last activity</th><th></th></tr></thead>
				<tbody>
<?php foreach ($sec_sessions as $s): $is_cur = $sec_cur && $sec_cur['id'] === $s['id']; ?>
				<tr>
					<td><?=h(auth_ua_label($s['ua']))?><?php if ((int)$s['mfa'] === 1): ?> <span class="badge bg-green-lt ms-1" title="Logged in with two-factor authentication">2FA</span><?php elseif ((int)$s['mfa'] === 2): ?> <span class="badge bg-azure-lt ms-1" title="Two-factor code skipped: remembered device">Trusted device</span><?php elseif ((int)$s['mfa'] === 3): ?> <span class="badge bg-green-lt ms-1" title="Logged in with a passkey">Passkey</span><?php endif; ?></td>
					<td><?=h($s['ip'])?></td>
					<td><?=h($sec_time($s['created_at']))?></td>
					<td><?=$is_cur ? '<span class="badge bg-blue-lt">This session</span>' : h($sec_ago($s['last_seen']))?></td>
					<td class="text-end">
						<form method="post" action="/" class="d-inline">
							<input type="hidden" name="action" value="security-session-revoke">
							<input type="hidden" name="session" value="<?=h($s['id'])?>">
							<button type="submit" class="btn btn-sm"><?=$is_cur ? 'Log out' : 'Revoke'?></button>
						</form>
					</td>
				</tr>
<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
<?php endif; ?>

<?php if ($sec_web && $sec_p): $sec_devices = auth_devices_list($sec_p['id']); $sec_dev_cur = auth_device_current_id(); ?>
	<div class="card mb-3">
		<div class="card-header">
			<h3 class="card-title">Known devices</h3>
			<span class="text-muted ms-2">browsers that logged in during the last <?=auth_cfg('auth-device-days')?> days</span>
<?php if (count(array_filter($sec_devices, function ($d) use ($sec_dev_cur) { return (int)$d['id'] !== $sec_dev_cur; }))): ?>
			<form method="post" action="/" class="ms-auto">
				<input type="hidden" name="action" value="security-device-forget">
				<button type="submit" name="device" value="all" class="btn btn-sm">Forget all others</button>
			</form>
<?php endif; ?>
		</div>
<?php if (!$sec_devices): ?>
		<div class="card-body text-muted">No device yet. Each browser is added when it logs in.</div>
<?php else: ?>
		<div class="table-responsive">
			<table class="table card-table table-vcenter">
				<thead><tr><th>Browser</th><th>Last IP address</th><th>First login</th><th>Last used</th><th>Two-factor code</th><th></th></tr></thead>
				<tbody>
<?php foreach ($sec_devices as $d): ?>
				<tr>
					<td><?=h($d['label'])?><?php if ((int)$d['id'] === $sec_dev_cur): ?> <span class="badge bg-blue-lt ms-1">This device</span><?php endif; ?></td>
					<td><?=h($d['last_ip'])?></td>
					<td><?=h($sec_time($d['first_seen']))?></td>
					<td><?=h($sec_ago($d['last_seen']))?></td>
					<td><?=(int)$d['trusted_until'] > time() ? 'skipped until '.h($sec_time($d['trusted_until'])) : '<span class="text-muted">asked</span>'?></td>
					<td class="text-end">
						<form method="post" action="/" class="d-inline">
							<input type="hidden" name="action" value="security-device-forget">
							<button type="submit" name="device" value="<?=(int)$d['id']?>" class="btn btn-sm">Forget</button>
						</form>
					</td>
				</tr>
<?php endforeach; ?>
				</tbody>
			</table>
		</div>
<?php endif; ?>
	</div>
<?php endif; ?>

<?php $sec_blocked = auth_blocked_list(); ?>
	<div class="card mb-3">
		<div class="card-header"><h3 class="card-title">Blocked addresses</h3></div>
<?php if (!$sec_blocked): ?>
		<div class="card-body text-muted">No address is blocked for failed panel logins.</div>
<?php else: ?>
		<div class="table-responsive">
			<table class="table card-table table-vcenter">
				<thead><tr><th>IP address</th><th>Blocked in</th><th>Until</th><th>Reason</th><th></th></tr></thead>
				<tbody>
<?php foreach ($sec_blocked as $b): ?>
				<tr>
					<td><?=h($b['ip'])?></td>
					<td><?=h($b['kind'])?></td>
					<td><?=$b['until'] ? h($sec_time($b['until'])) : 'permanent'?></td>
					<td><?=h($b['reason'])?></td>
					<td class="text-end">
						<form method="post" action="/" class="d-inline" data-sudo>
							<input type="hidden" name="action" value="security-unban">
							<input type="hidden" name="ip" value="<?=h($b['ip'])?>">
							<button type="submit" class="btn btn-sm btn-ghost-primary">Unblock</button>
						</form>
					</td>
				</tr>
<?php endforeach; ?>
				</tbody>
			</table>
		</div>
<?php endif; ?>
	</div>

<?php
	/* newest 20; "Show more" adds 100 per click (?log=<n>) */
	$sec_log_n     = isset($_GET['log']) && ctype_digit((string)$_GET['log']) ? min(max((int)$_GET['log'], 20), 5000) : 20;
	$sec_log_total = (int)auth_rows('SELECT COUNT(*) AS n FROM auth_log')[0]['n'];
	$sec_log       = auth_rows('SELECT * FROM auth_log ORDER BY id DESC LIMIT '.$sec_log_n);
?>
	<div class="card mb-3" id="security-log">
		<div class="card-header">
			<h3 class="card-title">Security log</h3>
<?php if ($sec_log_total > 0): ?>
			<span class="text-muted ms-2"><?=count($sec_log) < $sec_log_total ? 'latest '.count($sec_log).' of '.$sec_log_total : 'all '.$sec_log_total.' entries'?></span>
<?php endif; ?>
		</div>
<?php if (!$sec_log): ?>
		<div class="card-body text-muted">Nothing logged yet.</div>
<?php else: ?>
		<div class="table-responsive">
			<table class="table card-table table-vcenter table-sm">
				<thead><tr><th>Time</th><th>Event</th><th>User</th><th>IP address</th><th>Details</th></tr></thead>
				<tbody>
<?php foreach ($sec_log as $l): ?>
				<tr>
					<td class="text-nowrap"><?=h(date('Y-m-d H:i:s', (int)$l['ts']))?></td>
					<td><?=h(str_replace('_', ' ', $l['event']))?></td>
					<td><?=h($l['username'])?></td>
					<td><?=h($l['ip'])?></td>
					<td class="text-muted"><?=h($l['detail'])?></td>
				</tr>
<?php endforeach; ?>
				</tbody>
			</table>
		</div>
<?php if ($sec_log_total > count($sec_log)): ?>
		<div class="card-footer text-center">
			<a href="/security/?log=<?=$sec_log_n + 100?>#security-log">Show more</a>
			<span class="text-muted ms-2">(<?=$sec_log_total - count($sec_log)?> older)</span>
		</div>
<?php endif; ?>
<?php endif; ?>
	</div>

<?php endif; /* auth_ready */ ?>
</div>

<script src="./dist/libs/qrcodejs/qrcode.min.js"></script>
<script src="./dist/js/passkey.js"></script>
<script>
(function () {
	var qr = document.getElementById('totp-qr');
	if (qr && window.QRCode)
		new QRCode(qr, { text: qr.getAttribute('data-uri'), width: 192, height: 192, correctLevel: QRCode.CorrectLevel.M });

	var codes = document.getElementById('modal-recovery-codes');
	if (codes) {
		document.body.appendChild(codes);
		document.addEventListener('DOMContentLoaded', function () {     // bootstrap loads in the footer
			bootstrap.Modal.getOrCreateInstance(codes).show();
		});
		var text = document.getElementById('recovery-codes').innerText.trim() + '\n';
		var copy = document.getElementById('copy-codes');
		copy.addEventListener('click', function () {
			navigator.clipboard.writeText(text).then(function () { copy.querySelector('span').textContent = 'Copied'; });
		});
		var dl = document.getElementById('download-codes');
		dl.addEventListener('click', function () {
			var blob = new Blob([dl.getAttribute('data-head') + '\n\n' + text], { type: 'text/plain' });
			var a = document.createElement('a');
			a.href = URL.createObjectURL(blob);
			a.download = dl.getAttribute('data-file');
			document.body.appendChild(a);
			a.click();
			setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
		});
	}

	var pwShow = document.getElementById('show-password-form'), pwForm = document.getElementById('password-form');
	if (pwShow) {
		pwShow.addEventListener('click', function () {
			pwShow.classList.add('d-none');
			pwForm.classList.remove('d-none');
			pwForm.querySelector('input[type=password]').focus();
		});
		document.getElementById('hide-password-form').addEventListener('click', function () {
			pwForm.reset();
			pwForm.classList.add('d-none');
			pwShow.classList.remove('d-none');
		});
	}

/* Switches (two-factor, IP restriction): flipping one submits its form at
	   once. Some directions need sudo mode, so the confirmation dialog may come
	   first; if that is cancelled the switch goes back to where it was. */
	document.querySelectorAll('.state-toggle').forEach(function (sw) {
		var form = sw.form, orig = sw.checked, sent = false;
		var show = function (on) {
			var b = form.querySelector('.badge'), l = form.querySelector('.form-check-label');
			b.textContent = on ? 'Active' : 'Disabled';
			b.classList.remove('bg-yellow-lt');
			b.classList.toggle('bg-green-lt', on); b.classList.toggle('bg-red-lt', !on);
			l.classList.toggle('text-red', !on);
		};
		form.addEventListener('submit', function () { sent = true; });   // not reached while the dialog holds it
		sw.addEventListener('change', function () {
			show(sw.checked);
			if (form.requestSubmit) form.requestSubmit(); else form.submit();
		});
		// the dialog is included after this script; Bootstrap's modal events bubble
		document.addEventListener('hidden.bs.modal', function (e) {
			if (e.target.id === 'modal-sudo' && !sent && sw.checked !== orig) { sw.checked = orig; location.reload(); }
		});
	});

/* Add a passkey. The form carries data-sudo, so the confirmation dialog runs
	   first when needed (it resubmits the form); this handler runs after it. */
	var pkForm = document.getElementById('passkey-add');
	if (pkForm) {
		var pkErr = document.getElementById('passkey-add-error');
		if (!ReqadPasskey.supported()) {
			pkForm.querySelector('button').disabled = true;
			pkErr.textContent = 'This browser does not support passkeys.';
		}
		pkForm.addEventListener('submit', function (e) {
			e.preventDefault();
			var btn = pkForm.querySelector('button'), name = pkForm.querySelector('input[name="name"]').value;
			btn.disabled = true;
			pkErr.textContent = '';
			jQuery.post('/', { action: 'ajax-passkey-add-options' }, null, 'json')
				.then(function (o) { if (o.error) throw new Error(o.error); return ReqadPasskey.create(o); })
				.then(function (a) { a.action = 'ajax-passkey-add'; a.name = name; return jQuery.post('/', a, null, 'json'); })
				.then(function (r) { if (r.error) throw new Error(r.error); location.href = '/security/?msgid=' + r.msg; })
				.catch(function (err) {
					pkErr.textContent = err && err.name && err.name !== 'Error' ? ReqadPasskey.message(err) : ((err && err.message) || 'The passkey was not added.');
					btn.disabled = false;
				});
		});
	}

	var ndModal = document.getElementById('modal-email-verify');
	if (ndModal) {
		var ndCode = document.getElementById('nd-verify-code');
		document.body.appendChild(ndModal);          // outside the footer's unclosed div
		ndModal.addEventListener('shown.bs.modal', function () { ndCode.focus(); });
		document.addEventListener('DOMContentLoaded', function () { bootstrap.Modal.getOrCreateInstance(ndModal).show(); });
		// Enter in the code field = Confirm (not the first submit button, "send a new code")
		ndCode.addEventListener('keydown', function (e) {
			if (e.key === 'Enter') { e.preventDefault(); document.getElementById('nd-verify-btn').click(); }
		});
		ndCode.addEventListener('input', function () {
			if (/^\d{6}$/.test(ndCode.value.replace(/\s+/g, ''))) document.getElementById('nd-verify-btn').click();
		});
	}

	var fwModal = document.getElementById('modal-fw-confirm');
	if (fwModal) {
		// A plain click would open the test over this browser's existing
		// connection and prove nothing - copy the link instead and say so.
		var fwLink = document.getElementById('fw-test-link'), fwHint = document.getElementById('fw-test-hint');
		var fwCopy = function (e) {
			if (e) e.preventDefault();
			navigator.clipboard.writeText(fwLink.href).then(function () {
				document.getElementById('fw-test-copy').textContent = 'Copied';
				fwHint.innerHTML = '<strong>Link copied.</strong> Paste it into a private / incognito window (Ctrl+Shift+N in Chrome and Edge, Ctrl+Shift+P in Firefox, Cmd+Shift+N in Safari). If the login page opens there, keep the rules.';
			});
		};
		fwLink.addEventListener('click', fwCopy);
		document.getElementById('fw-test-copy').addEventListener('click', fwCopy);
		document.body.appendChild(fwModal);          // outside the footer's unclosed div
		document.addEventListener('DOMContentLoaded', function () { bootstrap.Modal.getOrCreateInstance(fwModal).show(); });
	}

	var cd = document.getElementById('fw-countdown');
	if (cd) {
		var left = parseInt(cd.getAttribute('data-left'), 10) || 0;
		var tick = setInterval(function () {
			left = Math.max(0, left - 1);
			cd.textContent = left + ' s';
			if (left === 0) { clearInterval(tick); setTimeout(function () { location.href = '/security/'; }, 3000); }
		}, 1000);
	}
})();
</script>

<?php
    include('templates/sudo-modal.php');
    include('templates/footer.php');
?>
