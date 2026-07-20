<?php

$cron_min  = trim($_POST["cron_min"]  ?? '');
$cron_hour = trim($_POST["cron_hour"] ?? '');
$cron_dom  = trim($_POST["cron_dom"]  ?? '');
$cron_mon  = trim($_POST["cron_mon"]  ?? '');
$cron_dow  = trim($_POST["cron_dow"]  ?? '');
$cron_user = trim($_POST["cron_user"] ?? '');
$cron_cmd  = trim($_POST["cron_cmd"]  ?? '');

// Strip newlines from command
$cron_cmd = str_replace(["\r", "\n"], '', $cron_cmd);

$errmsg     = '';
$successmsg = '';

// Validate schedule fields — allow digits, *, /, -, ,
$sched_pattern = '/^[\d\*\/\-\,]+$/';
if (!preg_match($sched_pattern, $cron_min) || !preg_match($sched_pattern, $cron_hour) ||
    !preg_match($sched_pattern, $cron_dom) || !preg_match($sched_pattern, $cron_mon)  ||
    !preg_match($sched_pattern, $cron_dow)) {
    $errmsg = "Error: Invalid cron schedule fields. Use digits, *, /, -, or ,";
}

// Block root cron management when root_access is disabled — /etc/crontab runs
// commands as root, so this would be a privilege escalation. Defaults ON when
// absent (matching add_ssh_key.php / delete_ssh_key.php).
if ($errmsg == '') {
    $root_access = isset($ini['root_access']) ? (int)$ini['root_access'] : 1;
    if ($cron_user === 'root' && !$root_access) {
        $errmsg = "Error: Root cron management is disabled on this server.";
    }
}

// Validate user
if ($errmsg == '') {
    $valid_users = array('root');
    $q = $db->query('SELECT user FROM accounts');
    while ($ur = $q->fetchArray()) {
        $valid_users[] = $ur["user"];
    }
    if (!in_array($cron_user, $valid_users, true)) {
        $errmsg = "Error: Invalid user selected.";
    }
}

if ($errmsg == '' && $cron_cmd == '') {
    $errmsg = "Error: Command cannot be empty.";
}

$schedule = "$cron_min $cron_hour $cron_dom $cron_mon $cron_dow";

if ($cron_user === 'root') {
    // root → /etc/crontab (includes user field in line)
    $line      = "$schedule root $cron_cmd";
    $cron_file = '/etc/crontab';

    if ($errmsg == '') {
        $existing = (int)trim(shell_exec("sudo grep -cF " . escapeshellarg($line) . " $cron_file 2>/dev/null"));
        if ($existing > 0) {
            $errmsg = "Error: An identical cron entry already exists.";
        }
    }

    if ($errmsg == '') {
        shell_exec('echo ' . escapeshellarg($line) . ' | sudo tee --append ' . $cron_file . ' > /dev/null');
        $successmsg = "Cron job successfully added to /etc/crontab.";
        error_log(date("Y-m-d H:i:s") . substr((string)microtime(), 1, 8) . " " . $_SERVER["REMOTE_ADDR"] . " " . $_SERVER['USER'] . " create cron (global): $line\n", 3, '../log/route_log');
    }

} else {
    // regular user → /var/spool/cron/<user> (no user field in line)
    $line      = "$schedule $cron_cmd";
    $cron_file = '/var/spool/cron/' . $cron_user;

    if ($errmsg == '') {
        $existing = (int)trim(shell_exec("sudo grep -cF " . escapeshellarg($line) . " " . escapeshellarg($cron_file) . " 2>/dev/null"));
        if ($existing > 0) {
            $errmsg = "Error: An identical cron entry already exists.";
        }
    }

    if ($errmsg == '') {
        shell_exec('echo ' . escapeshellarg($line) . ' | sudo tee --append ' . escapeshellarg($cron_file) . ' > /dev/null');
        $successmsg = "Cron job successfully added for user $cron_user.";
        error_log(date("Y-m-d H:i:s") . substr((string)microtime(), 1, 8) . " " . $_SERVER["REMOTE_ADDR"] . " " . $_SERVER['USER'] . " create cron (user=$cron_user): $line\n", 3, '../log/route_log');
    }
}
?>
