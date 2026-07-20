#!/usr/bin/php82
<?php

if(!is_file(__DIR__.'/../db/reqad.db')) {
    echo "Missing SQLite Database '".__DIR__."'/../db/reqad.db'\n";
    exit;
}

$db = new SQLite3(__DIR__.'/../db/reqad.db');
$s = array();

$results = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='errors';");
$row = $results->fetchArray();
print_r($row);



$output = shell_exec(__DIR__.'/exim_error_parser.sh');
$output = explode("\n", trim($output));
function sqlitequote($s) {
	return trim(str_replace(array("'", '<', '>'), array("''", '', ''), $s));
}

foreach($output as $k => $o) {
	$s[$k] = array_map('trim', explode('|', $o));
	if($s[$k][3]!='')
		$s[$k][2] = $s[$k][3];
	unset($s[$k][3]);
	$results = $db->query("SELECT count(*) AS nb FROM errors WHERE date='".sqlitequote($s[$k][0])."' AND email='".sqlitequote($s[$k][1])."'");
	if($results !== false) {
		$row = $results->fetchArray();
		$nb  = (int)($row["nb"]);
		if($nb==0 && $s[$k][0]!='' && $s[$k][1]!='' && $s[$k][2]!='') {
			echo("INSERT INTO errors VALUES (null, '".sqlitequote($s[$k][0])."', '".sqlitequote($s[$k][1])."', '".str_replace(array('550 5.1.1', '550-5.1.1', '552 1', '554 30', '550-','550 '), array('', '', '', '', '', ''), sqlitequote($s[$k][2]))."')\n");
			$db->query("INSERT INTO errors VALUES (null, '".sqlitequote($s[$k][0])."', '".sqlitequote($s[$k][1])."', '".str_replace(array('550 5.1.1', '550-5.1.1', '552 1', '554 30', '550-','550 '), array('', '', '', '', '', ''), sqlitequote($s[$k][2]))."')");
		}
	} else {
		echo("CREATE TABLE `errors` (`id` integer not null primary key autoincrement, `date` datetime not null, `email` varchar(100) not null, `errmsg` varchar(255) not null, unique (`id`))");
		$db->exec("CREATE TABLE `errors` (`id` integer not null primary key autoincrement, `date` datetime not null, `email` varchar(100) not null, `errmsg` varchar(255) not null, unique (`id`))");
	}
}

#print_r($s);

#$results = $db->query('SELECT count(*) as nb FROM errors');
#$row = $results->fetchArray();
#$nb_accounts = (int)($row["nb"]);



