<?php

$php_versions = array('7.2', '7.4', '8.2');
$php_version_default = '7.4';

/* DNS API */
$api_user   	= '';
$api_token  	= '';
$api_server 	= '';
$api_trueowner  = '';

$server_ip  	= '';
$server_dns 	= '';

// Backup DB
$backup_user    = '';
$backup_server  = '';
$backup_sshport = '';
$backup_sshkey  = ''; // optional: path to SSH private key (e.g. '/root/.ssh/backup_id_rsa')

/* clean() moved to modules/functions.php */

define('_PATH', '/usr/local/reqad');
$db  = new SQLite3(_PATH.'/db/reqad.db');
$ini = parse_ini_file(_PATH.'/etc/server-software.ini');

#echo '<pre>'; print_r($ini); exit;

$_services = explode(',', $ini["services"]);
$_services = array_map('trim', $_services);
