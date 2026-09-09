<?php

$dbuser   = trim($_POST["dbuser"]   ?? '');
$password = trim($_POST["password"] ?? '');

$errmsg     = '';
$successmsg = '';

if ($dbuser == '') {
    $errmsg = "Error: No database user specified.";
}

if ($errmsg == '' && strlen($password) < 8) {
    $errmsg = "Error: Password must be at least 8 characters long.";
}

if ($errmsg == '' && strlen($password) > 24) {
    $errmsg = "Error: Password must be at most 24 characters long.";
}

if ($errmsg == '' && strpos($password, ' ') !== false) {
    $errmsg = "Error: Password must not contain spaces.";
}

if ($errmsg == '') {
    // Verify user exists
    $mysql_users = mysql_rows('SELECT User FROM mysql.user');
    if (!in_array($dbuser, $mysql_users)) {
        $errmsg = "Error: Database user '$dbuser' not found.";
    }
}

if ($errmsg == '') {
    $output = mysql_exec('ALTER USER '.mysql_quote($dbuser).'@'.mysql_quote('localhost').
                         ' IDENTIFIED BY '.mysql_quote($password));
    if (trim($output) != '') {
        $errmsg = "Error: " . trim($output);
    } else {
        mysql_exec('FLUSH PRIVILEGES');
        $successmsg = "Password for user '$dbuser' successfully changed.";
        error_log(date("Y-m-d H:i:s") . substr((string)microtime(), 1, 8) . " " . $_SERVER["REMOTE_ADDR"] . " " . $_SERVER['USER'] . " change db password for $dbuser\n", 3, '../log/route_log');
    }
}
?>
