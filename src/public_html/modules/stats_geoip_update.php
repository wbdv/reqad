<?php
// Accounts & Domains > Bandwidth Usage: download this month's DB-IP Lite country database now (as root: it goes to /var/lib/reqad/geoip)
$out = (string)shell_exec('sudo -n /usr/local/reqad/bin/reqad stats geoip --update --json 2>/dev/null');
$r = json_decode($out, true);
result_redirect('/bandwidth/', is_array($r) && isset($r['ok']) ? $r : result_error('The country database could not be updated: '.trim($out)));
