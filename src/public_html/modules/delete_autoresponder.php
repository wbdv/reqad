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

    $msg_file = '/etc/exim/autoreply/' . $domain . '/' . $user;
    shell_exec('sudo rm -f ' . escapeshellarg($msg_file));

    $successmsg = "Autoresponder for $user@$domain deleted.";
    error_log(date("Y-m-d H:i:s") . " " . $_SERVER["REMOTE_ADDR"] . " delete autoresponder $user@$domain\n", 3, '../log/route_log');
}
