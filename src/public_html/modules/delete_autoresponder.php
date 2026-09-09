<?php
$id = (int)($_POST["id"] ?? 0);

$errmsg     = '';
$successmsg = '';

if ($id <= 0) {
    $errmsg = "Error: Invalid autoresponder ID.";
}

if ($errmsg == '') {
    $stmt = $db->prepare('SELECT * FROM autoresponders WHERE id=:id');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$row) {
        $errmsg = "Error: Autoresponder not found.";
    }
}

if ($errmsg == '') {
    $user   = $row['user'];
    $domain = $row['domain'];

    $stmt = $db->prepare('DELETE FROM autoresponders WHERE id=:id');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->execute();

    /* The row is gone either way; a helper failure here only leaves a stale
       script behind, so report it but do not pretend the delete failed. */
    $errmsg = autoresponder_remove($user, $domain);

    if ($errmsg == '') {
        $successmsg = "Autoresponder for $user@$domain deleted.";
        error_log(date("Y-m-d H:i:s") . " " . $_SERVER["REMOTE_ADDR"] . " delete autoresponder $user@$domain\n", 3, '../log/route_log');
    }
}

$msg_base = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . '/autoresponders/';
if ($errmsg != '')
    msg_redirect($msg_base, $errmsg, 'error');
else
    msg_redirect($msg_base, $successmsg, 'success');
