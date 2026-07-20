<?php

$orig_line = trim($_POST["orig_line"] ?? '');
$cron_type = trim($_POST["cron_type"] ?? '');
$cron_user = trim($_POST["cron_user"] ?? '');
$cron_min  = trim($_POST["cron_min"]  ?? '');
$cron_hour = trim($_POST["cron_hour"] ?? '');
$cron_dom  = trim($_POST["cron_dom"]  ?? '');
$cron_mon  = trim($_POST["cron_mon"]  ?? '');
$cron_dow  = trim($_POST["cron_dow"]  ?? '');
$cron_cmd  = trim($_POST["cron_cmd"]  ?? '');

$cron_cmd = str_replace(["\r", "\n"], '', $cron_cmd);

$errmsg     = '';
$successmsg = '';

if ($orig_line == '') {
    $errmsg = "Error: Original cron entry not specified.";
}

// Validate schedule fields
$sched_pattern = '/^[\d\*\/\-\,]+$/';
if ($errmsg == '' && (
    !preg_match($sched_pattern, $cron_min) || !preg_match($sched_pattern, $cron_hour) ||
    !preg_match($sched_pattern, $cron_dom) || !preg_match($sched_pattern, $cron_mon)  ||
    !preg_match($sched_pattern, $cron_dow))) {
    $errmsg = "Error: Invalid cron schedule fields.";
}

if ($errmsg == '' && $cron_cmd == '') {
    $errmsg = "Error: Command cannot be empty.";
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

// Validate user
if ($errmsg == '') {
    $valid_users = array('root');
    $q = $db->query('SELECT user FROM accounts');
    while ($ur = $q->fetchArray()) { $valid_users[] = $ur["user"]; }
    if (!in_array($cron_user, $valid_users, true)) {
        $errmsg = "Error: Invalid user.";
    }
}

$schedule = "$cron_min $cron_hour $cron_dom $cron_mon $cron_dow";
$new_line  = $cron_type === 'global' ? "$schedule $cron_user $cron_cmd" : "$schedule $cron_cmd";
$cron_file = $cron_type === 'global' ? '/etc/crontab' : '/var/spool/cron/' . $cron_user;

if ($errmsg == '') {
    $existing = (int)trim(shell_exec("sudo grep -cF " . escapeshellarg($orig_line) . " " . escapeshellarg($cron_file) . " 2>/dev/null"));
    if ($existing === 0) {
        $errmsg = "Error: Original cron entry not found in $cron_file.";
    }
}

if ($errmsg == '') {
    // Replace old line with new line
    $tmpfile = '/tmp/crontab.reqad_tmp';
    shell_exec('sudo grep -vF ' . escapeshellarg($orig_line) . ' ' . escapeshellarg($cron_file) . ' | sudo tee ' . $tmpfile . ' > /dev/null');
    shell_exec('echo ' . escapeshellarg($new_line) . ' | sudo tee --append ' . $tmpfile . ' > /dev/null');
    shell_exec('sudo mv ' . $tmpfile . ' ' . escapeshellarg($cron_file));
    if ($cron_type === 'global') {
        shell_exec('sudo chmod 644 ' . escapeshellarg($cron_file));
        shell_exec('sudo chown root:root ' . escapeshellarg($cron_file));
    }
    $successmsg = "Cron job successfully updated.";
    error_log(date("Y-m-d H:i:s") . substr((string)microtime(), 1, 8) . " " . $_SERVER["REMOTE_ADDR"] . " " . $_SERVER['USER'] . " edit cron ($cron_type, user=$cron_user): $orig_line -> $new_line\n", 3, '../log/route_log');
}
?>
