<?php
$errmsg 	= '';
$successmsg = '';

#echo '<pre>'; print_r($_POST); exit;

if(!in_array($_POST["mail-provider"], array('smtp', ''))) {
	$errmsg = 'Unknown mail provider.';
}

function update_settings($name, $value) {
	global $db;
	$results = $db->query('SELECT * FROM settings WHERE name="'.$name.'"');
	if ($results->fetchArray()) {
		$db->query('UPDATE settings SET value="'.$value.'", updated_at=datetime("now") WHERE name="'.$name.'"');
	} else {
		$db->query('INSERT INTO settings VALUES ("'.$name.'", "'.$value.'", datetime("now"))');
	}
}

if($errmsg == '') {
	update_settings('email', 			$_POST['email']);
	update_settings('mail-provider', 	$_POST['mail-provider']);
	update_settings('smtp_from', 		$_POST['smtp_from']);
	update_settings('smtp_server', 		$_POST['smtp_server']);
	update_settings('smtp_user', 		$_POST['smtp_user']);
	update_settings('smtp_password', 	$_POST['smtp_password']);
	update_settings('smtp_port', 		$_POST['smtp_port']);
	update_settings('welcome_dismissed', isset($_POST['show_welcome']) ? '0' : '1');
	update_settings('telemetry', isset($_POST['telemetry']) ? '1' : '0');

	$_forward_action = isset($_POST['root_mail_forward']) ? 'enable' : 'disable';
	shell_exec('sudo -n /usr/local/reqad/scripts/setup_root_mail_alias.sh ' . escapeshellarg($_forward_action) . ' 2>&1');

	$successmsg = 'Settings were saved.';

	if(isset($_POST['test_smtp']) && $_POST['test_smtp']=='on') {

/**
 * This example shows making an SMTP connection with authentication.
 */

//Import the PHPMailer class into the global namespace
#use PHPMailer\PHPMailer\PHPMailer;
#use PHPMailer\PHPMailer\SMTP;

//SMTP needs accurate times, and the PHP time zone MUST be set
//This should be done in your php.ini, but this is how to do it if you don't have access to that
#date_default_timezone_set('Etc/UTC');

#require '../vendor/autoload.php';
require './dist/libs/phpmailer/PHPMailer.php';
require './dist/libs/phpmailer/SMTP.php';

//Create a new PHPMailer instance
#$mail = new SMTP();
#$mail->connect($_POST['smtp_server'], 25);
#$mail->hello(gethostname());
#$e = $mail->getServerExtList();
#echo '<pre>'; var_dump($e); exit;

$mail = new PHPMailer();
//Tell PHPMailer to use SMTP
$mail->isSMTP();
//Enable SMTP debugging
//SMTP::DEBUG_OFF = off (for production use)
//SMTP::DEBUG_CLIENT = client messages
//SMTP::DEBUG_SERVER = client and server messages
#$mail->SMTPDebug = SMTP::DEBUG_SERVER;
$mail->SMTPDebug = SMTP::DEBUG_OFF;
//Set the hostname of the mail server
$mail->Host = $_POST['smtp_server'];

//Set the SMTP port number - likely to be 25, 465 or 587
$mail->Port = $_POST['smtp_port'];

if($_POST['smtp_port']=='465')
	$mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;

//Whether to use SMTP authentication
$mail->SMTPAuth = true;
//Username to use for SMTP authentication
$mail->Username = $_POST['smtp_user'];
//Password to use for SMTP authentication
$mail->Password = $_POST['smtp_password'];
//Set who the message is to be sent from
$mail->setFrom($_POST['smtp_from'], 'Reqad');
//Set an alternative reply-to address
//$mail->addReplyTo('replyto@example.com', 'First Last');
//Set who the message is to be sent to
$mail->addAddress($_POST['email'], 'Reqad Test Mail');
//Set the subject line
$mail->CharSet = 'UTF-8';
$mail->Subject = 'Reqad — SMTP test';
$_sent_at = date('Y-m-d H:i:s T');
$_hostname = gethostname();
$_smtp_server = htmlspecialchars($_POST['smtp_server']);
$_smtp_port   = htmlspecialchars($_POST['smtp_port']);
$_from        = htmlspecialchars($_POST['smtp_from']);
$_logo_b64 = base64_encode(file_get_contents(__DIR__ . '/../images/reqad.svg'));
$_logo_src = 'data:image/svg+xml;base64,' . $_logo_b64;
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
    . "From:        {$_POST['smtp_from']}\n"
    . "SMTP server: {$_POST['smtp_server']}:{$_POST['smtp_port']}\n"
    . "Hostname:    $_hostname\n\n"
    . "If you did not request this test, you can safely ignore this message.\n\n"
    . "-- Reqad, the alternate hosting control panel";

//SMTP XCLIENT attributes can be passed with setSMTPXclientAttribute method
//$mail->setSMTPXclientAttribute('LOGIN', 'yourname@example.com');
//$mail->setSMTPXclientAttribute('ADDR', '10.10.10.10');
//$mail->setSMTPXclientAttribute('HELO', 'test.example.com');

//send the message, check for errors
if (!$mail->send()) {
//    echo 'Mailer Error: ' . $mail->ErrorInfo;
	$errmsg = 'SMTP Error: ' . $mail->ErrorInfo;
#	exit;
} else {
//    echo 'Message sent!';
	$successmsg .= ' Test message was sent.';
}		

	}
}