<?php
$errmsg 	= '';
$successmsg = '';

#echo '<pre>'; print_r($_POST); exit;

$php_ini_allowed = array(
	'allow_url_fopen' => 'boolean',
	'allow_url_include' => 'boolean',
//		'asp_tags',
	'date.timezone' => '',
	'disable_functions' => '',
	'mail.force_extra_parameters' => '',
	'display_errors' => 'boolean',
	'enable_dl' => 'boolean',
	'error_reporting' => '',
	'file_uploads' => 'boolean',
	'log_errors' => 'boolean',
	'max_execution_time' => 'numeric',
	'max_input_time' => 'numeric',
	'max_input_vars' => 'numeric',
	'memory_limit' => 'numeric',
	'max_memory_limit' => 'numeric',
	'output_buffering' => 'numeric',
	'post_max_size' => 'numeric',
	'realpath_cache_size' => 'numeric',
	'realpath_cache_ttl' => 'numeric',
	'session.gc_maxlifetime' => 'numeric',
//	'session.save_path' => '',
	'short_open_tag' => 'boolean',
	'upload_max_filesize' => 'numeric',
	'expose_php' => 'boolean',
	'zlib.output_compression' => 'boolean'
);

$php_versions = array_map('trim', explode(',', $ini['php_versions']));
if(!isset($_POST['phpver']) || !in_array($_POST['phpver'], $php_versions)) {
	$errmsg = 'Wrong PHP version or no version was received';
}
if($errmsg == '') {
	$phpini_path = array();
	$opcache_path = array();
	$apcu_path = array();
	if(isset($_POST["save_to_all"]) && $_POST["save_to_all"]=='on') {
		foreach($php_versions as $phpver) {
			if($phpver == $ini['php']) {
				$phpini_path[] = '/etc/php.ini';
				$opcache_path[] = '/etc/php.d/10-opcache.ini';
				$apcu_path[] = '/etc/php.d/40-apcu.ini';
			} else {
				$phpini_path []= '/etc/opt/remi/php'.str_replace('.', '', $phpver).'/php.ini';
				$opcache_path[] = '/etc/opt/remi/php'.str_replace('.', '', $phpver).'/php.d/10-opcache.ini';
				$apcu_path[] = '/etc/opt/remi/php'.str_replace('.', '', $phpver).'/php.d/40-apcu.ini';
			}
		}
		$phpver = $_POST['phpver'];
	} else {
		$phpver = $_POST['phpver'];
		if($phpver == $ini['php']) {
			$phpini_path[] = '/etc/php.ini';
			$opcache_path[] = '/etc/php.d/10-opcache.ini';
			$apcu_path[] = '/etc/php.d/40-apcu.ini';
		} else {
			$phpini_path[] = '/etc/opt/remi/php'.str_replace('.', '', $phpver).'/php.ini';
			$opcache_path[] = '/etc/opt/remi/php'.str_replace('.', '', $phpver).'/php.d/10-opcache.ini';
			$apcu_path[] = '/etc/opt/remi/php'.str_replace('.', '', $phpver).'/php.d/40-apcu.ini';
		}
	}

	foreach($phpini_path as $phpini_path_cur) {
		if(!is_file($phpini_path_cur)) {
			$errmsg = 'Cannot find php.ini file '.$phpini_path_cur;
			continue;
		}
	}

	if($errmsg == '') {

		foreach($phpini_path as $phpini_path_cur) {
			unset($php_ini);
			$php_ini = array();

			$output = shell_exec("cat $phpini_path_cur | grep -ve '^;' | grep -ve '^\[' | grep -ve '^\$'");
			$output = array_map('trim', explode("\n", trim($output)));
			foreach($output as $line) {
				$parsed_line = array_map('trim', explode("=", trim($line)));
				#echo "<pre>"; print_r($parsed_line);exit;
				$var = $parsed_line[0];
				$val = $parsed_line[1];
				if(array_key_exists($var, $php_ini_allowed)) {
					$php_ini[$var] = $val;
					error_log(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$_SERVER["REMOTE_ADDR"]." ".$_SERVER['USER']." $phpini_path_cur $var -> $val\n", 3, '../log/debug_log');
				}
			}
	#		ksort($php_ini);
	#		echo "<pre>"; print_r($php_ini); #exit;

			foreach($php_ini_allowed as $var => $type) {
				$var2 = str_replace('.', '_', $var);
				if(!isset($php_ini[$var])) {
					// search for commented setting
					$output = trim(shell_exec("grep -e '^;$var' $phpini_path_cur | tail -n 1"));
					if($output=='') {
						// if not found, append settings
						shell_exec("echo '$var =' | sudo tee --append $phpini_path_cur");
					} else {
						// if found, uncomment setting and parse it
						error_log(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$_SERVER["REMOTE_ADDR"]." ".$_SERVER['USER']." sudo sed -i 's/^;$var =/$var =/' $phpini_path_cur\n", 3, '../log/debug_log');
						shell_exec("sudo sed -i 's/^;$var =/$var =/' $phpini_path_cur");
						$parsed_line = array_map('trim', explode("=", trim($output)));
						$val = $parsed_line[1];
						$php_ini[$var] = $val;
						error_log(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$_SERVER["REMOTE_ADDR"]." ".$_SERVER['USER']." $phpini_path_cur $var -> $val\n", 3, '../log/debug_log');
					}
				}
				if($type=='boolean') {
					if(isset($_POST[$var2]) && $_POST[$var2]=='on' && $php_ini[$var] == 'Off') {
	#					$php_ini[$var] == 'On';
						shell_exec("sudo sed -i 's/^$var = Off.*/$var = On/' $phpini_path_cur");
						$successmsg =  "PHP Settings saved.";
					} else if(!isset($_POST[$var2]) && $php_ini[$var] == 'On') {
	#					$php_ini[$var] == 'On';
						shell_exec("sudo sed -i 's/^$var = On.*/$var = Off/' $phpini_path_cur");
						$successmsg =  "PHP Settings saved.";
					}
				} else if($php_ini[$var]!=$_POST[$var2]) {
					// never write a blank numeric value — php reads "var =" as 0
					if($type=='numeric' && (!isset($_POST[$var2]) || trim($_POST[$var2])===''))
						continue;
					//TODO add numbers validation
					error_log(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$_SERVER["REMOTE_ADDR"]." ".$_SERVER['USER']." sudo sed -i 's/^$var = ".$php_ini[$var].".*/$var = ".$_POST[$var2]."/' $phpini_path_cur\n", 3, '../log/debug_log');
					if($php_ini[$var]=='')
						shell_exec("sudo sed -i 's/^$var =.*/$var = ".str_replace(array('&', '/'), array('\&', '\/'), $_POST[$var2])."/' $phpini_path_cur");
					else
						shell_exec("sudo sed -i 's/^$var = ".$php_ini[$var].".*/$var = ".str_replace(array('&', '/'), array('\&', '\/'), $_POST[$var2])."/' $phpini_path_cur");
					$successmsg =  "PHP Settings saved.";
				}
			}
		}
	}
}

// ---- OPcache settings (separate ini: 10-opcache.ini, key=value, 1/0 booleans) ----
if($errmsg == '') {
	$opcache_allowed = array(
		'opcache.enable' => 'boolean',
		'opcache.enable_cli' => 'boolean',
		'opcache.memory_consumption' => 'numeric',
		'opcache.interned_strings_buffer' => 'numeric',
		'opcache.max_accelerated_files' => 'numeric',
	);
	foreach($opcache_path as $opcache_path_cur) {
		if(!is_file($opcache_path_cur))
			continue;
		$oc = array();
		$output = shell_exec("cat $opcache_path_cur | grep -ve '^;' | grep -ve '^\[' | grep -ve '^\$'");
		$output = array_map('trim', explode("\n", trim($output)));
		foreach($output as $line) {
			$parsed_line = array_map('trim', explode("=", trim($line), 2));
			$var = $parsed_line[0];
			$val = isset($parsed_line[1]) ? $parsed_line[1] : '';
			if(array_key_exists($var, $opcache_allowed))
				$oc[$var] = $val;
		}
		foreach($opcache_allowed as $var => $type) {
			$var2 = str_replace('.', '_', $var);
			if(!isset($oc[$var])) {
				// search for a commented setting to uncomment, else append
				$commented = trim(shell_exec("grep -e '^;$var=' $opcache_path_cur | tail -n 1"));
				if($commented == '') {
					shell_exec("echo '$var=' | sudo tee --append $opcache_path_cur");
					$oc[$var] = '';
				} else {
					shell_exec("sudo sed -i 's/^;$var=/$var=/' $opcache_path_cur");
					$parsed_line = array_map('trim', explode("=", trim($commented), 2));
					$oc[$var] = isset($parsed_line[1]) ? $parsed_line[1] : '';
				}
			}
			if($type=='boolean') {
				$want = (isset($_POST[$var2]) && $_POST[$var2]=='on') ? '1' : '0';
				if($oc[$var] !== $want) {
					shell_exec("sudo sed -i 's/^$var=.*/$var=$want/' $opcache_path_cur");
					$successmsg = "PHP Settings saved.";
				}
			} else if(isset($_POST[$var2]) && $oc[$var] != $_POST[$var2]) {
				// never write a blank numeric value — it would be read as 0
				if($type=='numeric' && trim($_POST[$var2])==='')
					continue;
				shell_exec("sudo sed -i 's/^$var=.*/$var=".str_replace(array('&', '/'), array('\&', '\/'), $_POST[$var2])."/' $opcache_path_cur");
				$successmsg = "PHP Settings saved.";
			}
		}
	}
}

// ---- APCu settings (separate ini: 40-apcu.ini, key=value, 1/0 booleans) ----
// Only processed when the form actually rendered the APCu controls (apcu_present),
// so saving from a version without APCu can't blank other versions' apcu.ini.
if($errmsg == '' && isset($_POST['apcu_present'])) {
	$apcu_allowed = array(
		'apc.enabled'    => 'boolean',
		'apc.enable_cli' => 'boolean',
		'apc.shm_size'   => 'numeric',
		'apc.serializer' => '',
	);
	foreach($apcu_path as $apcu_path_cur) {
		if(!is_file($apcu_path_cur))
			continue;
		$ac = array();
		$output = shell_exec("cat $apcu_path_cur | grep -ve '^;' | grep -ve '^\[' | grep -ve '^\$'");
		$output = array_map('trim', explode("\n", trim($output)));
		foreach($output as $line) {
			$parsed_line = array_map('trim', explode("=", trim($line), 2));
			$var = $parsed_line[0];
			$val = isset($parsed_line[1]) ? trim($parsed_line[1], "'\"") : '';
			if(array_key_exists($var, $apcu_allowed))
				$ac[$var] = $val;
		}
		foreach($apcu_allowed as $var => $type) {
			$var2 = str_replace('.', '_', $var);
			if(!isset($ac[$var])) {
				$commented = trim(shell_exec("grep -e '^;$var=' $apcu_path_cur | tail -n 1"));
				if($commented == '') {
					shell_exec("echo '$var=' | sudo tee --append $apcu_path_cur");
					$ac[$var] = '';
				} else {
					shell_exec("sudo sed -i 's/^;$var=/$var=/' $apcu_path_cur");
					$parsed_line = array_map('trim', explode("=", trim($commented), 2));
					$ac[$var] = isset($parsed_line[1]) ? trim($parsed_line[1], "'\"") : '';
				}
			}
			if($type=='boolean') {
				$want = (isset($_POST[$var2]) && $_POST[$var2]=='on') ? '1' : '0';
				if($ac[$var] !== $want) {
					shell_exec("sudo sed -i 's/^$var=.*/$var=$want/' $apcu_path_cur");
					$successmsg = "PHP Settings saved.";
				}
			} else if(isset($_POST[$var2])) {
				// strip quotes so single-quoted defaults (e.g. apc.serializer='php') don't break sed
				$newval = str_replace(array("'", '"'), '', $_POST[$var2]);
				// never write a blank numeric value — it would be read as 0
				if($type=='numeric' && trim($newval)==='')
					continue;
				if($ac[$var] != $newval) {
					shell_exec("sudo sed -i 's/^$var=.*/$var=".str_replace(array('&', '/'), array('\&', '\/'), $newval)."/' $apcu_path_cur");
					$successmsg = "PHP Settings saved.";
				}
			}
		}
	}
}

if($errmsg == '' && $successmsg == '') {
    // All ok
    #error_log(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$_SERVER["REMOTE_ADDR"]." ".$_SERVER['USER']." create forwarder $email -> $forward\n", 3, '../log/route_log');
	$successmsg =  "No change was made in php.ini";
}
?>
