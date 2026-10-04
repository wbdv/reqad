<?php
/* Sending method modal on /settings/ — the relay outgoing panel mail goes
 * through (today: scripts/forward_root_mail.php). SMTP is the only provider;
 * Gmail is shown disabled in the modal until it exists.
 *
 * "Save and test" sends a test message to the contact email from the main
 * form, copied into test_to on submit so an unsaved address works too.
 *
 * Unsaved changes in the main form come along as main[...] and are saved after
 * the sending method by settings_modal_redirect() — after, so that turning on
 * root mail forwarding in the same go finds a sending method configured.
 */

$msg_base = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/settings/';

$provider = (string)($_POST['mail-provider'] ?? 'smtp');
$from     = trim((string)($_POST['smtp_from'] ?? ''));
$server   = strtolower(trim((string)($_POST['smtp_server'] ?? '')));
$user     = trim((string)($_POST['smtp_user'] ?? ''));
$pass     = (string)($_POST['smtp_password'] ?? '');
$port     = (string)($_POST['smtp_port'] ?? '465');

/* the password is never sent back to the browser; blank keeps the saved one */
if ($pass === '')
	$pass = setting_get('smtp_password');

$errmsg = '';
if (!in_array($provider, array('smtp'), true))
	$errmsg = 'Unknown mail provider.';
elseif (!filter_var($from, FILTER_VALIDATE_EMAIL))
	$errmsg = 'The From address is not valid.';
elseif (!preg_match('/^[a-z0-9]([a-z0-9\-\.]{0,251}[a-z0-9])?$/', $server))
	$errmsg = 'The SMTP server must be a hostname or an IP address.';
elseif ($user === '' || strlen($user) > 256)
	$errmsg = 'Enter the SMTP user.';
elseif ($pass === '')
	$errmsg = 'Enter the SMTP password.';
elseif (!in_array($port, array('465', '587'), true))
	$errmsg = 'Unknown SMTP port.';

if ($errmsg !== '')
	settings_modal_redirect($msg_base, $errmsg, 'error');

setting_put('mail-provider', $provider);
setting_put('smtp_from',     $from);
setting_put('smtp_server',   $server);
setting_put('smtp_user',     $user);
setting_put('smtp_password', $pass);
setting_put('smtp_port',     $port);

if (!isset($_POST['test']))
	settings_modal_redirect($msg_base, 'Sending method was saved.', 'success');

$to = trim((string)($_POST['test_to'] ?? ''));
if (!filter_var($to, FILTER_VALIDATE_EMAIL))
	settings_modal_redirect($msg_base, 'Sending method was saved. To send a test, fill in the email address first.', 'warning');

require './dist/libs/phpmailer/PHPMailer.php';
require './dist/libs/phpmailer/SMTP.php';

$mail = new PHPMailer();
$mail->isSMTP();
$mail->SMTPDebug = SMTP::DEBUG_OFF;
$mail->Host = $server;
$mail->Port = (int)$port;
if ($port == '465')
	$mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
$mail->SMTPAuth = true;
$mail->Username = $user;
$mail->Password = $pass;
$mail->setFrom($from, 'Reqad');
$mail->addAddress($to, 'Reqad Test Mail');
$mail->CharSet = 'UTF-8';
$mail->Subject = 'Reqad — SMTP test';

$_sent_at     = date('Y-m-d H:i:s T');
$_hostname    = gethostname();
$_smtp_server = htmlspecialchars($server);
$_smtp_port   = htmlspecialchars($port);
$_from        = htmlspecialchars($from);
$_logo_src    = 'data:image/svg+xml;base64,'.base64_encode(file_get_contents(__DIR__.'/../images/reqad.svg'));
$mail->isHTML(true);
$mail->Body = '<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background-color:#f4f6f8;font-family:Arial,Helvetica,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f6f8;padding:32px 0;">
    <tr><td align="center">
      <table width="560" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;background-color:#ffffff;overflow:hidden;border:1px solid #e0e4e8;">
        <!-- Logo strip -->
        <tr>
          <td style="background-color:#ffffff;padding:20px 32px 16px 32px;border-top: 8px solid #2a6099">
		  	<br>
            <img src="' . $_logo_src . '" alt="Reqad" height="36" border="0" style="display:block;height:36px;max-width:180px;">
          </td>
        </tr>
        <!-- Header banner -->
        <tr>
          <td style="background-color:#ffffff;padding:32px 32px 0 32px;border-top:1px solid #e0e4e8;">
            <p style="margin:0;font-size:16pt;color:#000000;">SMTP Configuration Test</p>
          </td>
        </tr>
        <!-- Body -->
        <tr>
          <td style="padding:32px;background-color:#ffffff">
            <p style="margin:0 0 16px;font-size:15px;color:#232e3c;">Your SMTP settings are working correctly. This message confirms that outgoing email delivery is properly configured.</p>
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f6f8;border-radius:4px;padding:4px 0;margin:24px 0;">
              <tr>
                <td style="padding:10px 16px;font-size:13px;color:#656d77;width:130px;">Sent at</td>
                <td style="padding:10px 16px;font-size:13px;color:#232e3c;font-weight:bold;">' . $_sent_at . '</td>
              </tr>
              <tr style="background-color:#eaecef;">
                <td style="padding:10px 16px;font-size:13px;color:#656d77;">From</td>
                <td style="padding:10px 16px;font-size:13px;color:#232e3c;font-weight:bold;">' . $_from . '</td>
              </tr>
              <tr>
                <td style="padding:10px 16px;font-size:13px;color:#656d77;">SMTP server</td>
                <td style="padding:10px 16px;font-size:13px;color:#232e3c;font-weight:bold;">' . $_smtp_server . ':' . $_smtp_port . '</td>
              </tr>
              <tr style="background-color:#eaecef;">
                <td style="padding:10px 16px;font-size:13px;color:#656d77;">Hostname</td>
                <td style="padding:10px 16px;font-size:13px;color:#232e3c;font-weight:bold;">' . htmlspecialchars($_hostname) . '</td>
              </tr>
            </table>
            <p style="margin:0;font-size:13px;color:#656d77;">If you did not request this test, you can safely ignore this message.</p>
          </td>
        </tr>
        <!-- Footer -->
        <tr>
          <td style="background-color:#f4f6f8;padding:16px 32px;border-top:1px solid #e0e4e8;">
            <p style="margin:0;font-size:12px;color:#adb5bd;text-align:center;">Reqad &mdash; The alternate hosting control panel</p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>';
$mail->AltBody = "Reqad SMTP Configuration Test\n"
    . "==============================\n\n"
    . "Your SMTP settings are working correctly.\n\n"
    . "Sent at:     $_sent_at\n"
    . "From:        $from\n"
    . "SMTP server: $server:$port\n"
    . "Hostname:    $_hostname\n\n"
    . "If you did not request this test, you can safely ignore this message.\n\n"
    . "-- Reqad, the alternate hosting control panel";

if (!$mail->send())
	settings_modal_redirect($msg_base, 'Sending method was saved, but the test failed. SMTP Error: '.$mail->ErrorInfo, 'error');
settings_modal_redirect($msg_base, 'Sending method was saved. A test message was sent to '.$to.'.', 'success');
