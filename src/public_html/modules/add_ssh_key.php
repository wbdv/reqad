<?php

$ssh_user   = trim($_POST["sshuser"] ?? '');
$ssh_pubkey = trim($_POST["pubkey"] ?? '');

$errmsg     = '';
$successmsg = '';

// Auto-convert SSH2/RFC 4716 format to OpenSSH format
if (strpos($ssh_pubkey, '---- BEGIN SSH2 PUBLIC KEY ----') !== false) {
    $tmpfile = tempnam(sys_get_temp_dir(), 'reqad_sshkey_');
    file_put_contents($tmpfile, $ssh_pubkey . "\n");
    $converted = trim(shell_exec('ssh-keygen -i -m RFC4716 -f ' . escapeshellarg($tmpfile) . ' 2>/dev/null'));
    unlink($tmpfile);
    if ($converted != '') {
        $ssh_pubkey = $converted;
    } else {
        $errmsg = "Error: Could not convert SSH2 key to OpenSSH format. Please check the key and try again.";
    }
}

// Validate key format — must start with a recognised key type followed by a space and base64 data
if ($errmsg == '') {
    $valid_key_types = array(
        'ssh-rsa', 'ssh-ed25519', 'ssh-dss',
        'ecdsa-sha2-nistp256', 'ecdsa-sha2-nistp384', 'ecdsa-sha2-nistp521',
        'sk-ssh-ed25519@openssh.com', 'sk-ecdsa-sha2-nistp256@openssh.com',
    );
    $key_type_ok = false;
    foreach ($valid_key_types as $kt) {
        if (strpos($ssh_pubkey, $kt . ' ') === 0) {
            $key_type_ok = true;
            break;
        }
    }
    if (!$key_type_ok) {
        $errmsg = "Error: Invalid SSH public key format. Key must start with a recognised type (ssh-rsa, ssh-ed25519, ecdsa-sha2-nistp256, …).";
    }
}

// Validate that the selected user is allowed
if ($errmsg == '') {
    $root_access = isset($ini['root_access']) ? (int)$ini['root_access'] : 1;
    if ($ssh_user === 'root' && !$root_access) {
        $errmsg = "Error: Root SSH key management is disabled on this server.";
    }
}

// Validate that the selected user is root or an app-managed account
if ($errmsg == '') {
    $valid_users = array('root');
    $q = $db->query('SELECT user FROM accounts');
    while ($row = $q->fetchArray()) {
        $valid_users[] = $row["user"];
    }
    if (!in_array($ssh_user, $valid_users, true)) {
        $errmsg = "Error: Invalid user selected.";
    }
}

if ($errmsg == '') {
    // Get AuthorizedKeysFile path from sshd_config (e.g. ".ssh/authorized_keys")
    $authorized = trim(shell_exec('sudo grep -i AuthorizedKeysFile /etc/ssh/sshd_config | grep -v \'^\s*#\' | awk \'{print $2}\' | head -n 1'));
    if ($authorized == '' || strpos($authorized, '%') !== false) {
        // Fall back to the OpenSSH default if the directive is absent or uses %h/%u tokens
        $authorized = '.ssh/authorized_keys';
    }

    // Build the path using tilde expansion (same approach as the template listing)
    $keyfile = '~' . $ssh_user . '/' . $authorized;

    // Check whether this exact key is already present
    $count = (int) trim(shell_exec('sudo grep -cF ' . escapeshellarg($ssh_pubkey) . ' ' . $keyfile . ' 2>/dev/null'));
    if ($count > 0) {
        $errmsg = "Error: This SSH key already exists for user $ssh_user.";
    }
}

if ($errmsg == '') {
    // Ensure the .ssh directory exists with correct ownership and permissions
    $sshdir = '~' . $ssh_user . '/.ssh';
    shell_exec('sudo mkdir -p ' . $sshdir);
    shell_exec('sudo chown ' . escapeshellarg($ssh_user) . ':' . escapeshellarg($ssh_user) . ' ' . $sshdir);
    shell_exec('sudo chmod 700 ' . $sshdir);

    // Append the key
    shell_exec('echo ' . escapeshellarg($ssh_pubkey) . ' | sudo tee --append ' . $keyfile . ' > /dev/null');
    shell_exec('sudo chown ' . escapeshellarg($ssh_user) . ':' . escapeshellarg($ssh_user) . ' ' . $keyfile);
    shell_exec('sudo chmod 600 ' . $keyfile);

    // Verify the authorised_keys file now lists keys correctly
    $verify = trim(shell_exec('sudo ssh-keygen -l -f ' . $keyfile . ' 2>&1'));
    if ($verify == '' || stripos($verify, 'no keys') !== false || stripos($verify, 'invalid') !== false) {
        $errmsg = "Error: Key may not have been added correctly. ssh-keygen output: " . htmlspecialchars($verify);
    } else {
        $successmsg = "SSH key successfully added for user $ssh_user.";
        error_log(date("Y-m-d H:i:s") . substr((string) microtime(), 1, 8) . " " . $_SERVER["REMOTE_ADDR"] . " " . $_SERVER['USER'] . " add ssh key for $ssh_user\n", 3, '../log/route_log');
    }
}
?>
