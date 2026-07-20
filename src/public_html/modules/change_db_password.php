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
    $mysql_users = array();
    $mysql_array = @json_decode(@json_encode(simplexml_load_string(shell_exec("sudo mysql --xml=true -e 'SELECT User FROM mysql.user'"))), true);
    if (!empty($mysql_array["row"])) {
        foreach ($mysql_array["row"] as $row) {
            $mysql_users[] = $row["field"];
        }
    }
    if (!in_array($dbuser, $mysql_users)) {
        $errmsg = "Error: Database user '$dbuser' not found.";
    }
}

if ($errmsg == '') {
    $escaped_user = addslashes($dbuser);
    $escaped_pass = addslashes($password);
    $output = shell_exec("sudo mysql -e \"ALTER USER '${escaped_user}'@'localhost' IDENTIFIED BY '${escaped_pass}'\" 2>&1");
    if (trim($output) != '') {
        $errmsg = "Error: " . trim($output);
    } else {
        shell_exec("sudo mysql -e \"FLUSH PRIVILEGES\" 2>&1");
        $successmsg = "Password for user '$dbuser' successfully changed.";
        error_log(date("Y-m-d H:i:s") . substr((string)microtime(), 1, 8) . " " . $_SERVER["REMOTE_ADDR"] . " " . $_SERVER['USER'] . " change db password for $dbuser\n", 3, '../log/route_log');
    }
}
?>
