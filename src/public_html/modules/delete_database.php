<?php
$database     = trim($_POST["database"]);
$errmsg 	  = '';
$successmsg   = '';

if($database == '')
	$errmsg =  "Error: Database is empty (missing).";

if($errmsg == '') {
    // Capture which users had grants on this database BEFORE we drop it.
    $db_users = shell_exec("sudo mysql -N -e \"SELECT DISTINCT User FROM mysql.db WHERE Db = '".$database."'\" 2>&1");

    // All ok, delete database
    error_log(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$_SERVER["REMOTE_ADDR"]." ".$_SERVER['USER']." delete database $database\n", 3, '../log/route_log');
    $output = shell_exec("sudo mysql -e 'DROP DATABASE `".$database."`' 2>&1");
    if($output != '') {
        $errmsg =  "Error: ".$output;
    }
    // Drop each grantee ONLY if it has no grants left on any OTHER database.
    // A user shared across databases must survive so the others keep working.
    if ($errmsg == '' && $db_users) {
        $dropped = false;
        foreach (explode("\n", trim($db_users)) as $db_user) {
            if ($db_user === '') continue;
            $other = trim(shell_exec("sudo mysql -N -e \"SELECT COUNT(*) FROM mysql.db WHERE User = '".$db_user."' AND Db <> '".$database."'\" 2>&1"));
            if ($other === '0') {
                shell_exec("sudo mysql -e 'DROP USER IF EXISTS `".$db_user."`@`localhost`;' 2>&1");
                $dropped = true;
            }
        }
        if ($dropped) shell_exec("sudo mysql -e 'FLUSH PRIVILEGES;' 2>&1");
    }
}

if($errmsg == '') {
	$successmsg =  "Database $database successfully removed.";
}

?>
