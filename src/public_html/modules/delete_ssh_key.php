<?php

$ssh_user   = trim($_POST["sshuser"] ?? '');
$ssh_pubkey = trim($_POST["pubkey"] ?? '');

$errmsg     = '';
$successmsg = '';

// Block root key deletion if root_access is disabled
$root_access = isset($ini['root_access']) ? (int)$ini['root_access'] : 1;
if ($ssh_user === 'root' && !$root_access) {
    $errmsg = "Error: Root SSH key management is disabled on this server.";
}

// Validate user is root or an app-managed account
$valid_users = array('root');
$q = $db->query('SELECT user FROM accounts');
while ($row = $q->fetchArray()) {
    $valid_users[] = $row["user"];
}
if ($errmsg == '' && !in_array($ssh_user, $valid_users, true)) {
    $errmsg = "Error: Invalid user selected.";
}

if ($errmsg == '') {
    $authorized = trim(shell_exec('sudo grep -i AuthorizedKeysFile /etc/ssh/sshd_config | grep -v \'^\s*#\' | awk \'{print $2}\' | head -n 1'));
    if ($authorized == '' || strpos($authorized, '%') !== false) {
        $authorized = '.ssh/authorized_keys';
    }

    $keyfile = '~' . $ssh_user . '/' . $authorized;

    // Check the key actually exists before trying to remove it
    $count = (int) trim(shell_exec('sudo grep -cF ' . escapeshellarg($ssh_pubkey) . ' ' . $keyfile . ' 2>/dev/null'));
    if ($count === 0) {
        $errmsg = "Error: Key not found in authorized_keys for user $ssh_user.";
    }
}

if ($errmsg == '') {
    $tmpfile = $keyfile . '.reqad_tmp';

    // Write all lines except the matching key into a temp file, then replace
    shell_exec('sudo grep -vF ' . escapeshellarg($ssh_pubkey) . ' ' . $keyfile . ' | sudo tee ' . $tmpfile . ' > /dev/null');
    shell_exec('sudo mv ' . $tmpfile . ' ' . $keyfile);
    shell_exec('sudo chmod 600 ' . $keyfile);
    shell_exec('sudo chown ' . escapeshellarg($ssh_user) . ':' . escapeshellarg($ssh_user) . ' ' . $keyfile);

    // Verify the key is gone
    $still_there = (int) trim(shell_exec('sudo grep -cF ' . escapeshellarg($ssh_pubkey) . ' ' . $keyfile . ' 2>/dev/null'));
    if ($still_there > 0) {
        $errmsg = "Error: Key could not be removed.";
    } else {
        $successmsg = "SSH key successfully removed for user $ssh_user.";
        error_log(date("Y-m-d H:i:s") . substr((string) microtime(), 1, 8) . " " . $_SERVER["REMOTE_ADDR"] . " " . $_SERVER['USER'] . " delete ssh key for $ssh_user\n", 3, '../log/route_log');
    }
}
?>
