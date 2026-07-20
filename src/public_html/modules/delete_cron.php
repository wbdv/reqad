<?php

$cron_line = trim($_POST["cron_line"] ?? '');
$cron_type = trim($_POST["cron_type"] ?? '');
$cron_user = trim($_POST["cron_user"] ?? '');

$errmsg     = '';
$successmsg = '';

if ($cron_line == '') {
    $errmsg = "Error: No cron entry specified.";
}

// Block root cron management when root_access is disabled. Global crons live in
// /etc/crontab (run as root); root's user crontab is equally off-limits.
// Defaults ON when absent (matching add_ssh_key.php / delete_ssh_key.php).
if ($errmsg == '') {
    $root_access = isset($ini['root_access']) ? (int)$ini['root_access'] : 1;
    if (!$root_access && ($cron_type === 'global' || $cron_user === 'root')) {
        $errmsg = "Error: Root cron management is disabled on this server.";
    }
}

if ($errmsg == '' && $cron_type == 'global') {
    $cron_file = '/etc/crontab';

    $existing = (int)trim(shell_exec("sudo grep -cF " . escapeshellarg($cron_line) . " $cron_file 2>/dev/null"));
    if ($existing === 0) {
        $errmsg = "Error: Cron entry not found in $cron_file.";
    }

    if ($errmsg == '') {
        $tmpfile = '/tmp/crontab.reqad_tmp';
        shell_exec('sudo grep -vF ' . escapeshellarg($cron_line) . " $cron_file | sudo tee $tmpfile > /dev/null");
        shell_exec("sudo mv $tmpfile $cron_file");
        shell_exec("sudo chmod 644 $cron_file");
        shell_exec("sudo chown root:root $cron_file");

        $still_there = (int)trim(shell_exec("sudo grep -cF " . escapeshellarg($cron_line) . " $cron_file 2>/dev/null"));
        if ($still_there > 0) {
            $errmsg = "Error: Cron entry could not be removed.";
        } else {
            $successmsg = "Cron job successfully deleted.";
            error_log(date("Y-m-d H:i:s") . substr((string)microtime(), 1, 8) . " " . $_SERVER["REMOTE_ADDR"] . " " . $_SERVER['USER'] . " delete cron (global): $cron_line\n", 3, '../log/route_log');
        }
    }

} else if ($errmsg == '' && $cron_type == 'user') {
    // Validate user is root or an app-managed account
    $valid_users = array('root');
    $q = $db->query('SELECT user FROM accounts');
    while ($ur = $q->fetchArray()) { $valid_users[] = $ur["user"]; }
    if (!in_array($cron_user, $valid_users, true)) {
        $errmsg = "Error: Invalid user.";
    }

    if ($errmsg == '') {
        $cron_file = '/var/spool/cron/' . $cron_user;

        $existing = (int)trim(shell_exec("sudo grep -cF " . escapeshellarg($cron_line) . " " . escapeshellarg($cron_file) . " 2>/dev/null"));
        if ($existing === 0) {
            $errmsg = "Error: Cron entry not found in $cron_file.";
        }
    }

    if ($errmsg == '') {
        $tmpfile = '/tmp/crontab.reqad_tmp';
        shell_exec('sudo grep -vF ' . escapeshellarg($cron_line) . ' ' . escapeshellarg($cron_file) . " | sudo tee $tmpfile > /dev/null");
        shell_exec('sudo mv ' . $tmpfile . ' ' . escapeshellarg($cron_file));

        $still_there = (int)trim(shell_exec("sudo grep -cF " . escapeshellarg($cron_line) . " " . escapeshellarg($cron_file) . " 2>/dev/null"));
        if ($still_there > 0) {
            $errmsg = "Error: Cron entry could not be removed.";
        } else {
            $successmsg = "Cron job for user $cron_user successfully deleted.";
            error_log(date("Y-m-d H:i:s") . substr((string)microtime(), 1, 8) . " " . $_SERVER["REMOTE_ADDR"] . " " . $_SERVER['USER'] . " delete cron (user=$cron_user): $cron_line\n", 3, '../log/route_log');
        }
    }

} else if ($errmsg == '') {
    $errmsg = "Error: Unknown cron type.";
}
?>
