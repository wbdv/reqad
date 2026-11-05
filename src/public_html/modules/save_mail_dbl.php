<?php
/* Turn the sender domain check (Spamhaus Domain Block List) on or off, from
   Email > Exim > Blocklists. scripts/update/setup_exim_dbl.sh does the work:
   saves the setting exim-dbl (updates keep the choice), checks the new
   exim.conf with `exim -bV -C` and reloads exim. */

$on = (isset($_POST['dbl']) && $_POST['dbl'] === 'on');
$r  = exim_dbl_apply($on);

error_log(date('Y-m-d H:i:s').' '.$_SERVER['REMOTE_ADDR'].' '.auth_user()
        .' sender domain check '.($on ? 'on' : 'off').' '.($r['error'] === '' ? 'ok' : 'FAILED')."\n", 3, '../log/route_log');

msg_redirect('/email-config/exim/?tab=blocklists',
             $r['error'] !== '' ? $r['error'] : $r['success'],
             $r['error'] !== '' ? 'error' : 'success');
