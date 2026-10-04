<?php
/* Skip-RBL card on the Blocklists (RBL) tab.

   Four operations, all arriving at the same action so the card can stay one
   region of the page:

     providers  which ESPs are exempted
     extra      the operator's own addresses
     rebuild    walk the SPF records again
     exim       add or remove the `!hosts` guard on every dnslists condition

   Anything that changes an input regenerates the file straight away, because
   an input that does not match the file on disk is worse than no input at all
   -- the panel would be describing exemptions exim is not applying. */

$op = isset($_POST['op']) ? clean($_POST['op']) : '';

if ($op === 'providers') {

	/* Both lists are stored, not just the off one. A provider the catalogue
	   gains in a later update is in neither, so it falls back to its own
	   `default` -- which is how a newly shipped spam source stays off instead
	   of being switched on by a preference saved before it existed. */
	$known = array_keys(skiprbl_providers());
	$want  = isset($_POST['provider']) && is_array($_POST['provider']) ? $_POST['provider'] : array();
	$on    = array();
	$off   = array();
	foreach ($known as $k) {
		if (in_array($k, $want, true)) $on[] = $k;
		else                           $off[] = $k;
	}

	setting_put('skiprbl-disabled', implode(',', $off));
	setting_put('skiprbl-enabled',  implode(',', $on));
	$r = skiprbl_rebuild();
	if ($r['error'] === '')
		$r['success'] = count($on).' of '.count($known).' providers exempted. '.$r['success'];

} elseif ($op === 'extra') {

	$raw = isset($_POST['extra']) ? (string)$_POST['extra'] : '';
	if (strlen($raw) > 65536) {
		$r = array('error' => 'That list is too long.', 'success' => '');
	} else {
		$err   = '';
		$clean = skiprbl_clean_extra($raw, $err);
		if ($err !== '') {
			$r = array('error' => $err, 'success' => '');
		} else {
			setting_put('skiprbl-extra', implode("\n", $clean));
			$r = skiprbl_rebuild();
			if ($r['error'] === '')
				$r['success'] = count($clean).' extra address'.(count($clean) === 1 ? '' : 'es').' saved. '.$r['success'];
		}
	}

} elseif ($op === 'rebuild') {

	/* --force is how the operator overrides the shrink guard once they have
	   looked at why the list got smaller. */
	$r = skiprbl_rebuild(isset($_POST['force']) && $_POST['force'] === '1');

} elseif ($op === 'exim') {

	$r = skiprbl_exim_apply(isset($_POST['enable']) && $_POST['enable'] === '1');

} else {
	$r = array('error' => 'Unknown skip-RBL operation.', 'success' => '');
}

error_log(date('Y-m-d H:i:s').' '.$_SERVER['REMOTE_ADDR'].' '.$_SERVER['USER']
        .' save mail skiprbl ['.$op.'] '.($r['error'] === '' ? 'ok' : 'FAILED')."\n", 3, '../log/route_log');

msg_redirect('/email-config/exim/?tab=blocklists',
             $r['error'] !== '' ? $r['error'] : $r['success'],
             $r['error'] !== '' ? 'error' : 'success');
