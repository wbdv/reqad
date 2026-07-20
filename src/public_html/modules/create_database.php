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

if(preg_match('/[a-z0-9]+[a-z0-9\-\.]+[a-z0-9]+\.[a-z]{2,}/', $dbname)) {
	$mysql_databases = array();
	$mysql_array = @json_decode(@json_encode(simplexml_load_string(shell_exec("sudo mysql --xml=true -e 'SHOW DATABASES'"))), true);
	foreach($mysql_array["row"] as $mysql_array2) {
		$mysql_databases[] = $mysql_array2["field"];
	}
	#echo "Databases: ".var_export($mysql_databases, true);
	if(in_array($dbname, $mysql_databases)) {
		$errmsg = "Database ".$dbname." already exists. Please choose a different database name.";
	}
}

if($errmsg == '') {
	$mysql_users = array();
	$mysql_array = @json_decode(@json_encode(simplexml_load_string(shell_exec("sudo mysql --xml=true -e 'SELECT User FROM mysql.user'"))), true);
	foreach($mysql_array["row"] as $mysql_array2) {
		$mysql_users[] = $mysql_array2["field"];
	}
	#echo "Users: ".var_export($mysql_users, true);
	if(in_array($dbuser, $mysql_users)) {
		$errmsg = "User ".$dbuser." already exists. Please choose a different user name.";
	}
}

if($errmsg == '') {
    // All ok, create database.
    error_log(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$_SERVER["REMOTE_ADDR"]." ".$_SERVER['USER']." create database ".$user."_".$dbname."\n", 3, '../log/route_log');
    $output = shell_exec("sudo mysql -e 'CREATE DATABASE ".$user."_".$dbname."' 2>&1");
    error_log(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$_SERVER["REMOTE_ADDR"]." ".$_SERVER['USER']." create user ".$user."_".$dbuser."\n", 3, '../log/route_log');
    $output = shell_exec("sudo mysql -e 'GRANT ALL ON ".$user."_".$dbname.".* TO ".$user."_".$dbuser."@localhost IDENTIFIED BY \"".addslashes($password)."\"'  2>&1");
	#die("sudo mysql -e 'GRANT ALL ON ".$user."_".$dbname.".* TO ".$user."_".$dbuser."@localhost IDENTIFIED BY \"".addslashes($password)."\"'  2>&1");
}

if($errmsg == '') {
	$successmsg =  "Database ".$user."_".$dbname." successfully created. User ".$user."_".$dbuser." was asigned to the database.";
}
?>
