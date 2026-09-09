<?php

$cron_line = trim($_POST["cron_line"] ?? '');
$cron_type = trim($_POST["cron_type"] ?? '');
$cron_user = trim($_POST["cron_user"] ?? '');

$errmsg     = '';
$successmsg = '';

if ($cron_line == '') {
    $errmsg = "Error: No cron entry specified.";
}

if ($errmsg == '' && $cron_type != 'global' && $cron_type != 'user') {
    $errmsg = "Error: Unknown cron type.";
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

// Validate user — root or an app-managed account
if ($errmsg == '' && $cron_type == 'user') {
    $valid_users = array('root');
    $q = $db->query('SELECT user FROM accounts');
    while ($ur = $q->fetchArray()) { $valid_users[] = $ur["user"]; }
    if (!in_array($cron_user, $valid_users, true)) {
        $errmsg = "Error: Invalid user.";
    }
}

$cron_file = $cron_type === 'global' ? '/etc/crontab' : '/var/spool/cron/' . $cron_user;

if ($errmsg == '') {
    // Same helper as edit_cron.php — whitespace-normalised match, owner/mode
    // preserved. Called with no replacement line, it deletes the entry.
    $cmd = 'sudo ' . _PATH . '/scripts/cron_edit.sh ' . escapeshellarg($cron_file) . ' '
         . escapeshellarg($cron_line) . ' 2>&1';
    $out = trim((string)shell_exec($cmd . '; echo "rc=$?"'));
    $rc  = 1;
    if (preg_match('/rc=(\d+)$/', $out, $m)) {
        $rc  = (int)$m[1];
        $out = trim(preg_replace('/rc=\d+$/', '', $out));
    }

    if ($rc !== 0) {
        $errmsg = $out != '' ? $out : "Error: Cron entry could not be removed.";
    } else {
        $successmsg = $cron_type === 'global'
            ? "Cron job successfully deleted."
            : "Cron job for user $cron_user successfully deleted.";
        error_log(date("Y-m-d H:i:s") . substr((string)microtime(), 1, 8) . " " . $_SERVER["REMOTE_ADDR"] . " " . $_SERVER['USER'] . " delete cron ($cron_type, user=$cron_user): $cron_line\n", 3, '../log/route_log');
    }
}
?>
