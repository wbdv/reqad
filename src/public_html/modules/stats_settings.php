<?php
// Accounts & Domains > Bandwidth Usage: the visitor stats switches (app/functions/stats.php)
$on  = isset($_POST['enabled']) ? $_POST['enabled'] === '1' : null;
$geo = isset($_POST['geoip_update']) ? $_POST['geoip_update'] === '1' : null;
$r = stats_settings_set($on, $geo);
if ($r['ok'])
	$r['message'] = $on !== null ? ('Visitor stats are '.($on ? 'on.' : 'off.')) : ('Monthly country database updates are '.($geo ? 'on.' : 'off.'));
result_redirect('/bandwidth/', $r);
