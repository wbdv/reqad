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

$_sent_at  = date('Y-m-d H:i:s T');
$_hostname = gethostname();
$html = mail_html_layout('SMTP Configuration Test',
	'<p style="margin:0 0 16px;font-size:15px;color:#232e3c;">Your SMTP settings are working correctly. This message confirms that outgoing email delivery is properly configured.</p>'
	.mail_html_facts(array('Sent at' => $_sent_at, 'From' => $from, 'SMTP server' => $server.':'.$port, 'Hostname' => $_hostname))
	.'<p style="margin:0;font-size:13px;color:#656d77;">If you did not request this test, you can safely ignore this message.</p>');
$text = "Reqad SMTP Configuration Test\n"
    . "==============================\n\n"
    . "Your SMTP settings are working correctly.\n\n"
    . "Sent at:     $_sent_at\n"
    . "From:        $from\n"
    . "SMTP server: $server:$port\n"
    . "Hostname:    $_hostname\n\n"
    . "If you did not request this test, you can safely ignore this message.\n\n"
    . "-- Reqad, the alternate hosting control panel";

if (($err = send_mail($to, 'Reqad — SMTP test', $html, $text, 'Reqad Test Mail')) !== '')
	settings_modal_redirect($msg_base, 'Sending method was saved, but the test failed. '.$err, 'error');
settings_modal_redirect($msg_base, 'Sending method was saved. A test message was sent to '.$to.'.', 'success');
