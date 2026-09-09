<?php
$user     = trim($_POST["user"]);
$dbname   = trim($_POST["dbname"]);
$dbuser   = trim($_POST["dbuser"]);
$password = trim($_POST["password"]);

$errmsg 	= '';
$successmsg = '';

if($user=='' || $dbname=='' || strlen($password)<8) {
	$errmsg = "Wrong POST data (db/user/password).";
}

# escape special shell characters
# $password = str_replace('!', '\!', $password);

#echo '<pre>'; print_r($_POST); exit;

/* These two become bare SQL identifiers in CREATE DATABASE / GRANT below, where
   no amount of quoting makes an arbitrary string safe -- so restrict them to
   the characters MySQL identifiers actually need. This gate used to be
   valid_domain($dbname), which made no sense for a database name and, being
   unanchored, also skipped the duplicate check for every ordinary name. */
if($errmsg == '' && !valid_mysql_identifier($dbname)) {
	$errmsg = "Error: Database name may only contain letters, numbers and underscores.";
}
if($errmsg == '' && !valid_mysql_identifier($dbuser)) {
	$errmsg = "Error: Database user may only contain letters, numbers and underscores.";
}
if($errmsg == '' && !valid_username($user)) {
	$errmsg = "Error: Invalid account user.";
}

if($errmsg == '') {
	$mysql_databases = mysql_rows('SHOW DATABASES');
	if(in_array($user.'_'.$dbname, $mysql_databases)) {
		$errmsg = "Database ".$user."_".$dbname." already exists. Please choose a different database name.";
	}
}

if($errmsg == '') {
	$mysql_users = mysql_rows('SELECT User FROM mysql.user');
	if(in_array($user.'_'.$dbuser, $mysql_users)) {
		$errmsg = "User ".$user."_".$dbuser." already exists. Please choose a different user name.";
	}
}

if($errmsg == '') {
    // All ok, create database.
    error_log(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$_SERVER["REMOTE_ADDR"]." ".$_SERVER['USER']." create database ".$user."_".$dbname."\n", 3, '../log/route_log');
    $output = mysql_exec('CREATE DATABASE `'.$user.'_'.$dbname.'`');
    error_log(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$_SERVER["REMOTE_ADDR"]." ".$_SERVER['USER']." create user ".$user."_".$dbuser."\n", 3, '../log/route_log');
    $output = mysql_exec('GRANT ALL ON `'.$user.'_'.$dbname.'`.* TO `'.$user.'_'.$dbuser.'`@`localhost` IDENTIFIED BY '.mysql_quote($password));
    /* mysql_exec() folds stderr in, so any output at all means the statement
       failed -- this used to be captured and silently thrown away. */
    if(trim($output) != '')
        $errmsg = "Error: ".$output;
}

if($errmsg == '') {
	$successmsg =  "Database ".$user."_".$dbname." successfully created. User ".$user."_".$dbuser." was asigned to the database.";
}
?>
