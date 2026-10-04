<?php
/* The main form on /settings/: welcome screen, contact email + root mail
 * forwarding, the two automatic-update switches, telemetry. The sending method
 * and the Reqad update time are saved from their own modals (settings_smtp.php,
 * auto_update_cron.php), so this form no longer needs SMTP filled in to save.
 *
 * The save itself is settings_main_save() in functions.php: the modals run it
 * too, when they are submitted with unsaved changes in this form.
 */

$msg_base = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/settings/';

list($type, $message) = settings_main_save($_POST);
msg_redirect($msg_base, $message, $type);
