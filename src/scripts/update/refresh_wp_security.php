<?php
/* Re-render the WP Toolkit Security tab's nginx rules for every site that has
 * some applied, so rule changes shipped in an update reach existing sites
 * (e.g. blocked paths answering 404 instead of 403) without anyone pressing
 * Apply. Keeps each site's current selection and allowed IPs; a site whose
 * rules are already current is left untouched (no nginx test or reload).
 *
 * Run as root from post_reqad_install.sh:
 *   /opt/reqad/php-current/usr/bin/php -c /etc/reqad/php-fpm/php.ini refresh_wp_security.php
 */

if (php_sapi_name() !== 'cli' || posix_getuid() !== 0) {
	echo "Run as root from the command line.\n";
	exit(1);
}

chdir('/usr/local/reqad/public_html');
include 'defines.php';
include 'modules/functions.php';

if (!wp_is_nginx($ini) || !is_dir(WP_SEC_INC_DIR)) {
	echo "  wp security: nothing to refresh\n";
	exit(0);
}

$done = 0; $fail = 0;
$res = $db->query('SELECT user, domain, path FROM wordpress ORDER BY domain');
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
	$keys = wp_sec_nginx_enabled($row['domain']);
	if (empty($keys))
		continue;
	$r = wp_sec_nginx_apply($row['user'], $row['domain'], $row['path'] ?? '', $keys, wp_sec_allowed_ips($row['domain']));
	if ($r['error'] !== '') {
		echo "  wp security: ".$row['domain'].": ".$r['error']."\n";
		$fail++;
	} elseif (!empty($r['changed'])) {
		echo "  wp security: ".$row['domain']." rules refreshed\n";
		$done++;
	}
}
echo "  wp security: $done site(s) refreshed".($fail ? ", $fail failed" : '')."\n";
