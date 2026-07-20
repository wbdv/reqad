#!/usr/bin/php82
<?php
/**
 * Reqad — manage_autoresponders.php
 * Runs every 5 minutes via cron (as root).
 * Activates or deactivates autoresponder filter files based on date_from / date_to.
 */

$db_path = '/usr/local/reqad/db/reqad.db';
if (!is_file($db_path)) {
    exit(0);
}

$db    = new SQLite3($db_path);
$today = date('Y-m-d');

$results = $db->query('SELECT * FROM autoresponders');
while ($row = $results->fetchArray(SQLITE3_ASSOC)) {
    $user      = $row['user'];
    $domain    = $row['domain'];
    $subject   = $row['subject'];
    $message   = $row['message'];
    $date_from = $row['date_from'];
    $date_to   = $row['date_to'];

    $msg_file = '/etc/exim/autoreply/' . $domain . '/' . $user;

    $should_be_active = ($date_from == '' || $date_from <= $today)
                     && ($date_to   == '' || $date_to   >= $today);

    $is_active = is_file($msg_file);

    if ($should_be_active && !$is_active) {
        $msg_dir  = '/etc/exim/autoreply/' . $domain;
        $tmp_file = tempnam(sys_get_temp_dir(), 'ar_');

        // Sieve vacation filter — dedup handled automatically by Exim via sieve_vacation_directory
        // File named after local part (no extension) so dsearch can detaint it
        $subj_escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $subject);
        $content  = "# Sieve filter\n";
        $content .= "require [\"vacation\"];\n";
        $content .= "vacation :days 1 :subject \"" . $subj_escaped . "\" text:\n";
        foreach (explode("\n", $message) as $line) {
            $content .= ($line === '.' ? '..' : $line) . "\n";
        }
        $content .= ".\n;\ndiscard;\n";

        file_put_contents($tmp_file, $content);
        shell_exec('sudo mkdir -p ' . escapeshellarg($msg_dir));
        shell_exec('sudo chown exim:mail ' . escapeshellarg($msg_dir));
        shell_exec('sudo chmod 750 ' . escapeshellarg($msg_dir));
        shell_exec('sudo mv ' . escapeshellarg($tmp_file) . ' ' . escapeshellarg($msg_file));
        shell_exec('sudo chown exim:mail ' . escapeshellarg($msg_file));
        shell_exec('sudo chmod 640 ' . escapeshellarg($msg_file));
        echo date('Y-m-d H:i:s') . " activated autoresponder for $user@$domain\n";

    } elseif (!$should_be_active && $is_active) {
        shell_exec('sudo rm -f ' . escapeshellarg($msg_file));
        echo date('Y-m-d H:i:s') . " deactivated autoresponder for $user@$domain\n";
    }
}

$db->close();
