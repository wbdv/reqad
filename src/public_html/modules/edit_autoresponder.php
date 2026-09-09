<?php
$id        = (int)($_POST["id"] ?? 0);
$user      = trim($_POST["user"]);
$domain    = trim($_POST["domain"]);
$subject   = trim($_POST["subject"]);
$message   = trim($_POST["message"]);
$date_from = trim($_POST["date_from"] ?? '');
$date_to   = trim($_POST["date_to"] ?? '');

$errmsg     = '';
$successmsg = '';

if ($id <= 0) {
    $errmsg = "Error: Invalid autoresponder ID.";
}
if ($errmsg == '' && $subject == '') {
    $errmsg = "Error: Subject cannot be empty.";
}
if ($errmsg == '' && $message == '') {
    $errmsg = "Error: Message cannot be empty.";
}
if ($errmsg == '' && $date_from != '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
    $errmsg = "Error: Invalid date_from format.";
}
if ($errmsg == '' && $date_to != '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
    $errmsg = "Error: Invalid date_to format.";
}
if ($errmsg == '' && $date_from != '' && $date_to != '' && $date_to < $date_from) {
    $errmsg = "Error: Active to date must be after active from date.";
}

// Load existing row to get user/domain
if ($errmsg == '') {
    $stmt = $db->prepare('SELECT * FROM autoresponders WHERE id=:id');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$row) {
        $errmsg = "Error: Autoresponder not found.";
    } else {
        // user/domain come from DB (not from POST) to prevent tampering
        $user   = $row['user'];
        $domain = $row['domain'];
    }
}

if ($errmsg == '') {
    $stmt = $db->prepare('UPDATE autoresponders SET subject=:s, message=:m, date_from=:df, date_to=:dt WHERE id=:id');
    $stmt->bindValue(':s',   $subject,   SQLITE3_TEXT);
    $stmt->bindValue(':m',   $message,   SQLITE3_TEXT);
    $stmt->bindValue(':df',  $date_from, SQLITE3_TEXT);
    $stmt->bindValue(':dt',  $date_to,   SQLITE3_TEXT);
    $stmt->bindValue(':id',  $id,        SQLITE3_INTEGER);
    $stmt->execute();

    $today = date('Y-m-d');
    $should_activate = ($date_from == '' || $date_from <= $today)
                    && ($date_to   == '' || $date_to   >= $today);

    if ($should_activate)
        $errmsg = autoresponder_apply($user, $domain, $subject, $message);
    else
        $errmsg = autoresponder_remove($user, $domain);

    if ($errmsg == '') {
        $successmsg = "Autoresponder for $user@$domain successfully updated.";
        error_log(date("Y-m-d H:i:s") . " " . $_SERVER["REMOTE_ADDR"] . " edit autoresponder $user@$domain\n", 3, '../log/route_log');
    }
}

$msg_base = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . '/autoresponders/';
if ($errmsg != '')
    msg_redirect($msg_base, $errmsg, 'error');
else
    msg_redirect($msg_base, $successmsg, 'success');
