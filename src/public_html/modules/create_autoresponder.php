<?php
$user      = trim($_POST["user"]);
$domain    = trim($_POST["domain"]);
$subject   = trim($_POST["subject"]);
$message   = trim($_POST["message"]);
$date_from = trim($_POST["date_from"] ?? '');
$date_to   = trim($_POST["date_to"] ?? '');

$errmsg     = '';
$successmsg = '';

// Validate user
if (!preg_match('/^[A-Za-z0-9_\-\+\.]{1,64}$/', $user)) {
    $errmsg = "Error: Invalid email user.";
}
// Validate domain
if ($errmsg == '' && !preg_match('/^[a-z0-9][a-z0-9\-\.]*[a-z0-9]\.[a-z]{2,}$/', $domain)) {
    $errmsg = "Error: Invalid domain name.";
}
// The picker only offers real mailboxes, but the POST is trivially forged and
// an autoresponder for a non-existent address silently never fires.
if ($errmsg == '' && !mailbox_exists($user, $domain)) {
    $errmsg = "Error: $user@$domain is not a mailbox on this server.";
}
// Validate subject
if ($errmsg == '' && $subject == '') {
    $errmsg = "Error: Subject cannot be empty.";
}
// Validate message
if ($errmsg == '' && $message == '') {
    $errmsg = "Error: Message cannot be empty.";
}
// Validate dates
if ($errmsg == '' && $date_from != '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
    $errmsg = "Error: Invalid date_from format.";
}
if ($errmsg == '' && $date_to != '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
    $errmsg = "Error: Invalid date_to format.";
}
if ($errmsg == '' && $date_from != '' && $date_to != '' && $date_to < $date_from) {
    $errmsg = "Error: Active to date must be after active from date.";
}

// Check duplicate
if ($errmsg == '') {
    $stmt = $db->prepare('SELECT id FROM autoresponders WHERE user=:u AND domain=:d');
    $stmt->bindValue(':u', $user, SQLITE3_TEXT);
    $stmt->bindValue(':d', $domain, SQLITE3_TEXT);
    if ($stmt->execute()->fetchArray()) {
        $errmsg = "Error: An autoresponder for $user@$domain already exists.";
    }
}

if ($errmsg == '') {
    $stmt = $db->prepare('INSERT INTO autoresponders (user, domain, subject, message, date_from, date_to) VALUES (:u, :d, :s, :m, :df, :dt)');
    $stmt->bindValue(':u',  $user,      SQLITE3_TEXT);
    $stmt->bindValue(':d',  $domain,    SQLITE3_TEXT);
    $stmt->bindValue(':s',  $subject,   SQLITE3_TEXT);
    $stmt->bindValue(':m',  $message,   SQLITE3_TEXT);
    $stmt->bindValue(':df', $date_from, SQLITE3_TEXT);
    $stmt->bindValue(':dt', $date_to,   SQLITE3_TEXT);
    $stmt->execute();

    // Activate immediately if date_from is today or empty
    $today = date('Y-m-d');
    $should_activate = ($date_from == '' || $date_from <= $today)
                    && ($date_to   == '' || $date_to   >= $today);

    if ($should_activate)
        $errmsg = autoresponder_apply($user, $domain, $subject, $message);

    if ($errmsg == '') {
        $successmsg = "Autoresponder for $user@$domain successfully created.";
        error_log(date("Y-m-d H:i:s") . " " . $_SERVER["REMOTE_ADDR"] . " create autoresponder $user@$domain\n", 3, '../log/route_log');
    }
}

/* Post/Redirect/Get: carry the message via the queue and 302 to the GET page
   so a browser refresh does not re-submit the form. */
$msg_base = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . '/autoresponders/';
if ($errmsg != '')
    msg_redirect($msg_base, $errmsg, 'error');
else
    msg_redirect($msg_base, $successmsg, 'success');
