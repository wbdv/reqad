<?php
/* Login page (rendered by modules/login.php). Standalone: no panel chrome. */
$login_host = php_uname('n');
?><!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"/>
    <meta name="robots" content="noindex, nofollow"/>
    <title>Log in - Reqad - <?=h($login_host)?></title>
    <base href="<?=h($_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'])?>/"/>
    <link href="./dist/css/tabler.min.css" rel="stylesheet"/>
    <link href="./dist/fonts/fonts.css" rel="stylesheet"/>
    <link rel="icon" href="./favicon.ico"/>
    <style>
      body { background: #f1f4f8; }
      /* centred both ways, whatever the window height */
      .login-wrap { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 1.5rem 1rem; }
      .login-box { width: 100%; max-width: 32rem; }
      .login-brand { display: flex; justify-content: left; margin-bottom: 2.5rem; }
      .card { border-top: 6px solid #2a6099; border-radius: 2px;
              box-shadow: 0 18px 40px -12px rgba(42, 96, 153, .28), 0 4px 12px rgba(16, 24, 40, .06); }
      .code-input { font-size: 1.5rem; letter-spacing: .4em; text-align: center; font-variant-numeric: tabular-nums; }
	  .forgot { text-shadow: 1px; }
    </style>
  </head>
  <body>
<?php include __DIR__.'/login-bg.php'; ?>
    <div class="login-wrap">
      <div class="login-box">
        <div class="card card-md">
          <div class="card-body">
        <div class="login-brand"><img src="./images/reqad.svg" height="40" alt="Reqad"></div>
<?php if ($login_step === '2fa'): ?>
            <h2 class="h2 text-center mb-2">Two-factor authentication</h2>
            <p class="text-muted text-center mb-4">Enter the 6-digit code from your authenticator app, or one of your recovery codes.</p>
<?php elseif ($login_step === 'email'): ?>
            <h2 class="h2 text-center mb-2">Check your email</h2>
            <p class="text-muted text-center mb-4">This is a new device, so we sent a 6-digit code to <strong><?=h($login_email_to)?></strong>.
              Enter it to finish logging in. It expires in <?=auth_cfg('auth-code-minutes')?> minutes.</p>
<?php else: ?>
            <h2 class="h2 mb-4">Log in to the control panel</h2>
<?php endif; ?>

<?php /* same alert as the panel's flash messages (msg_alert_html escapes the text) */ ?>
<?php if ($login_error !== ''): ?>
            <?=msg_alert_html('error', $login_error, true)?>
<?php elseif ($login_info !== ''): ?>
            <?=msg_alert_html('success', $login_info, true)?>
<?php endif; ?>

<?php if ($login_step === '2fa'): ?>
            <form method="post" action="/login/2fa/" autocomplete="off" novalidate>
              <input type="hidden" name="lc" value="<?=h($login_nonce)?>">
              <div class="mb-3">
                <input type="text" name="code" class="form-control code-input<?=isset($login_field_errors['code']) ? ' is-invalid' : ''?>" inputmode="numeric"
                       autocomplete="one-time-code" maxlength="12" placeholder="000000" autofocus required
                       data-empty="Enter the code from your authenticator app.">
                <div class="invalid-feedback"><?=h(isset($login_field_errors['code']) ? $login_field_errors['code'] : '')?></div>
              </div>
              <label class="form-check">
                <input type="checkbox" class="form-check-input" name="remember" value="1"<?=!empty($_POST['remember']) ? ' checked' : ''?>>
                <span class="form-check-label">Don't ask for a code on this device for <?=auth_cfg('auth-trust-days')?> days</span>
              </label>
              <div class="form-footer">
                <button type="submit" class="btn btn-primary w-100">Verify</button>
              </div>
            </form>
<?php elseif ($login_step === 'email'): ?>
            <form method="post" action="/login/email/" autocomplete="off" novalidate>
              <input type="hidden" name="lc" value="<?=h($login_nonce)?>">
              <div class="mb-3">
                <input type="text" name="code" class="form-control code-input<?=isset($login_field_errors['code']) ? ' is-invalid' : ''?>" inputmode="numeric"
                       autocomplete="one-time-code" maxlength="7" placeholder="000000" autofocus required
                       data-empty="Enter the code from the email.">
                <div class="invalid-feedback"><?=h(isset($login_field_errors['code']) ? $login_field_errors['code'] : '')?></div>
              </div>
              <div class="form-footer">
                <button type="submit" class="btn btn-primary w-100">Verify</button>
              </div>
            </form>
            <form method="post" action="/login/email/" class="text-center mt-3">
              <input type="hidden" name="lc" value="<?=h($login_nonce)?>">
              <span class="text-muted">No email? Check the spam folder, or</span>
              <button type="submit" name="resend" value="1" class="btn btn-link p-0 align-baseline">send a new code</button>
            </form>
<?php else: ?>
            <form method="post" action="/login/" autocomplete="on" novalidate>
              <input type="hidden" name="lc" value="<?=h($login_nonce)?>">
              <div class="mb-3">
                <label class="form-label" for="login-username">User name:</label>
                <input type="text" id="login-username" name="username" class="form-control<?=isset($login_field_errors['username']) ? ' is-invalid' : ''?>" value="<?=h($login_username)?>"
                       autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="64"
                       <?=$login_username === '' ? 'autofocus' : ''?> required data-empty="Enter your user name.">
                <div class="invalid-feedback"><?=h(isset($login_field_errors['username']) ? $login_field_errors['username'] : '')?></div>
              </div>
              <div class="mb-2">
                <label class="form-label" for="login-password">Password:</label>
                <input type="password" id="login-password" name="password" class="form-control<?=isset($login_field_errors['password']) ? ' is-invalid' : ''?>"
                       autocomplete="current-password" maxlength="256" <?=$login_username !== '' ? 'autofocus' : ''?> required
                       data-empty="Enter your password.">
                <div class="invalid-feedback"><?=h(isset($login_field_errors['password']) ? $login_field_errors['password'] : '')?></div>
              </div>
              <div class="form-footer">
                <button type="submit" class="btn btn-primary w-100">Log in</button>
              </div>
            </form>
<?php if (auth_rp_id() !== '' && auth_passkeys_on() && auth_passkeys_exist()): ?>
            <div id="passkey-login" class="d-none">
              <div class="hr-text my-3">or</div>
              <button type="button" class="btn w-100" id="passkey-btn">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon me-2" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M16.555 3.843l3.602 3.602a2.877 2.877 0 0 1 0 4.069l-2.643 2.643a2.877 2.877 0 0 1 -4.069 0l-.301 -.301l-6.558 6.558a2 2 0 0 1 -1.239 .578l-.175 .008h-1.172a1 1 0 0 1 -.993 -.883l-.007 -.117v-1.172a2 2 0 0 1 .467 -1.284l.119 -.13l.414 -.414h2v-2h2v-2l2.144 -2.144l-.301 -.301a2.877 2.877 0 0 1 0 -4.069l2.643 -2.643a2.877 2.877 0 0 1 4.069 0z"/><path d="M15 9h.01"/></svg>
                Log in with a passkey
              </button>
            </div>
<?php endif; ?>
<?php endif; ?>
          </div>
        </div>
        <div class="text-center text-muted small mt-3 forgot">Forgot the password? On the server, run <code>reqad auth passwd</code> as root.</div>
      </div>
    </div>
    <script src="./dist/js/passkey.js"></script>
    <script>
    /* Empty fields: mark them right away under the field instead of posting
       (an empty submit is not a login attempt). */
    document.querySelectorAll('form').forEach(function (form) {
      form.addEventListener('submit', function (e) {
        var first = null;
        form.querySelectorAll('input[data-empty]').forEach(function (inp) {
          var fb = inp.parentNode.querySelector('.invalid-feedback');
          if (inp.value.trim() === '') {
            inp.classList.add('is-invalid');
            if (fb) fb.textContent = inp.getAttribute('data-empty');
            if (!first) first = inp;
          }
        });
        if (first) { e.preventDefault(); first.focus(); }
      });
      form.querySelectorAll('input[data-empty]').forEach(function (inp) {
        inp.addEventListener('input', function () { inp.classList.remove('is-invalid'); });
      });
    });
    /* 2FA / email code: submit as soon as six digits are in (typed or pasted).
       Recovery codes contain letters, so they still need Verify / Enter. */
    /* Passkey: options -> the browser's passkey prompt -> verify -> go. */
    var pkBox = document.getElementById('passkey-login');
    if (pkBox && window.ReqadPasskey && ReqadPasskey.supported()) {
      pkBox.classList.remove('d-none');
      var pkBtn = document.getElementById('passkey-btn'), lc = document.querySelector('input[name="lc"]').value;
      var pkPost = function (data) {
        data.lc = lc;
        return fetch('/login/passkey/', { method: 'POST', credentials: 'same-origin', body: new URLSearchParams(data) })
          .then(function (r) { return r.json(); });
      };
      var pkError = function (msg) {
        var box = document.getElementById('passkey-error');
        if (!box) {
          box = document.createElement('div');
          box.id = 'passkey-error';
          box.className = 'text-danger small mt-2 text-center';
          pkBox.appendChild(box);
        }
        box.textContent = msg;
      };
      pkBtn.addEventListener('click', function () {
        pkBtn.disabled = true;
        pkError('');
        pkPost({ op: 'options' })
          .then(function (o) { if (o.error) throw new Error(o.error); return ReqadPasskey.get(o); })
          .then(function (a) { a.op = 'verify'; return pkPost(a); })
          .then(function (r) { if (r.error) throw new Error(r.error); location.href = r.next || '/'; })
          .catch(function (e) { pkError(e.name && e.name !== 'Error' ? ReqadPasskey.message(e) : e.message); pkBtn.disabled = false; });
      });
    }

    var code = document.querySelector('input[name="code"]'), sent = false;
    if (code) code.addEventListener('input', function () {
      if (!sent && /^\d{6}$/.test(code.value.replace(/\s+/g, ''))) {
        sent = true;
        code.form.submit();
      }
    });
    </script>
  </body>
</html>
