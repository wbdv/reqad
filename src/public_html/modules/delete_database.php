<?php
$database     = trim($_POST["database"]);
$errmsg 	  = '';
$successmsg   = '';

if($database == '')
	$errmsg =  "Error: Database is empty (missing).";

/* $database arrives from the page's own listing rather than being typed, but it
   still ends up as a bare SQL identifier in DROP DATABASE, so validate it. */
if($errmsg == '' && !valid_mysql_identifier($database))
	$errmsg =  "Error: Invalid database name.";

if($errmsg == '') {
    // Capture which users had grants on this database BEFORE we drop it.
    $db_users = mysql_rows('SELECT DISTINCT User FROM mysql.db WHERE Db = '.mysql_quote($database));

    // All ok, delete database
    error_log(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$_SERVER["REMOTE_ADDR"]." ".$_SERVER['USER']." delete database $database\n", 3, '../log/route_log');
    $output = mysql_exec('DROP DATABASE `'.$database.'`');
    if(trim($output) != '') {
        $errmsg =  "Error: ".$output;
    }
    // Drop each grantee ONLY if it has no grants left on any OTHER database.
    // A user shared across databases must survive so the others keep working.
    if ($errmsg == '' && $db_users) {
        $dropped = false;
        foreach ($db_users as $db_user) {
            /* This name comes straight out of mysql.db and was previously
               interpolated unescaped into three more statements. */
            if ($db_user === '' || !valid_mysql_identifier($db_user)) continue;
            $other = trim(shell_with_stdin('sudo mysql -N 2>/dev/null',
                'SELECT COUNT(*) FROM mysql.db WHERE User = '.mysql_quote($db_user).
                ' AND Db <> '.mysql_quote($database)));
            if ($other === '0') {
                mysql_exec('DROP USER IF EXISTS `'.$db_user.'`@`localhost`');
                $dropped = true;
            }
        }
        if ($dropped) mysql_exec('FLUSH PRIVILEGES');
    }
}

if($errmsg == '') {
	$successmsg =  "Database $database successfully removed.";
}

?>
