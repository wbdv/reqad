<?php
/* Clear an outbound sending counter from the Sending limits tab.

   The counters live in exim's ratelimit hints DB, not in the panel, so this
   only asks the helper to delete a record — there is no state here to keep in
   step. Used when a domain has tripped a limit for a reason the admin has
   dealt with and should not have to wait an hour to send again.

   $kind is the panel's own word for the counter ('hourly' / 'failures'); the
   helper builds the database key from it, so nothing the browser sends is ever
   used as a key. */

$kind   = isset($_POST['kind'])   ? clean($_POST['kind'])   : '';
$domain = isset($_POST['domain']) ? clean($_POST['domain']) : '';

/* the All button posts neither, and means every counter */
$r = ($kind === '' && $domain === '')
	? exim_limits_reset('', '')
	: exim_limits_reset($kind, $domain);

error_log(date('Y-m-d H:i:s').' '.$_SERVER['REMOTE_ADDR'].' '.$_SERVER['USER']
        .' reset mail limit ['.($domain !== '' ? $domain.'/'.$kind : 'all').'] '
        .($r['error'] === '' ? 'ok' : 'FAILED')."\n", 3, '../log/route_log');

msg_redirect('/email-config/exim/?tab=limits',
             $r['error'] !== '' ? $r['error'] : $r['success'],
             $r['error'] !== '' ? 'error' : 'success');
