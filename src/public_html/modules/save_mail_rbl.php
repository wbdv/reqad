<?php
/* Replace one of exim's DNS blocklists (RBLs) from the Blocklists card.

   The list is identified by its position among the uncommented `dnslists =`
   lines, because exim ACL conditions carry no name of their own. Hosts are
   validated one at a time before any of them reach the config, and the write
   itself goes through apply_mail_config() — so a mistake is caught by
   `exim -bV` and reverted rather than left on a live mail server. */

$idx   = isset($_POST['list']) ? (int)$_POST['list'] : -1;
$raw   = isset($_POST['hosts']) ? (string)$_POST['hosts'] : '';

if (strlen($raw) > 8192)
	msg_redirect('/email-config/exim/', 'Error: that blocklist is too long.', 'error');

/* accept one host per line, or a colon/comma separated list pasted in one go */
$hosts = array();
foreach (preg_split('/[\s:,;]+/', $raw) as $h) {
	$h = trim($h);
	if ($h !== '') $hosts[] = $h;
}

$r = exim_dnslists_apply($idx, $hosts);

error_log(date('Y-m-d H:i:s').' '.$_SERVER['REMOTE_ADDR'].' '.$_SERVER['USER']
        .' save mail rbl ['.$idx.'] '.($r['error'] === '' ? 'ok' : 'FAILED')."\n", 3, '../log/route_log');

msg_redirect('/email-config/exim/',
             $r['error'] !== '' ? $r['error'] : $r['success'],
             $r['error'] !== '' ? 'error' : 'success');
