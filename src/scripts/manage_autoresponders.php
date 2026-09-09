#!/opt/reqad/php-current/usr/bin/php -c/etc/reqad/php-fpm/php.ini
<?php
/**
 * Reqad — manage_autoresponders.php
 * Runs every 5 minutes via cron (as root).
 * Activates or deactivates autoresponder filter files based on date_from / date_to.
 *
 * The shebang pins Reqad's own php.ini: the stock php82 CLI ini disables exec(),
 * which ef_helper() needs to reach the privileged Sieve helper.
 *
 * This is also what migrates a server off the old exim backend — the rendering
 * and the placement of the live script are autoresponder_apply()'s business, and
 * it drops the stale /etc/exim/autoreply file on the way past.
 */

require_once '/usr/local/reqad/public_html/defines.php';
require_once '/usr/local/reqad/public_html/modules/functions.php';

$db_path = '/usr/local/reqad/db/reqad.db';
if (!is_file($db_path)) {
    exit(0);
}

$db    = new SQLite3($db_path);
$today = date('Y-m-d');

$results = $db->query('SELECT * FROM autoresponders');
while ($row = $results->fetchArray(SQLITE3_ASSOC)) {
    $user   = $row['user'];
    $domain = $row['domain'];

    $should_be_active = ($row['date_from'] == '' || $row['date_from'] <= $today)
                     && ($row['date_to']   == '' || $row['date_to']   >= $today);

    $is_active = autoresponder_is_active($user, $domain);

    /* A mailbox left over from the exim backend counts as inactive above, so
       the write below happens on the first run after the switch and migrates it. */
    if ($should_be_active && !$is_active) {
        $err = autoresponder_apply($user, $domain, $row['subject'], $row['message']);
        echo date('Y-m-d H:i:s') . ($err === ''
            ? " activated autoresponder for $user@$domain\n"
            : " FAILED to activate autoresponder for $user@$domain: $err\n");

    } elseif (!$should_be_active && $is_active) {
        $err = autoresponder_remove($user, $domain);
        echo date('Y-m-d H:i:s') . ($err === ''
            ? " deactivated autoresponder for $user@$domain\n"
            : " FAILED to deactivate autoresponder for $user@$domain: $err\n");
    }
}

$db->close();
