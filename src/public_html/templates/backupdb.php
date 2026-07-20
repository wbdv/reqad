<?php
	$ssh_key_opt = (isset($backup_sshkey) && $backup_sshkey !== '') ? "-i $backup_sshkey " : '';
	if(isset($_GET["download"])) {
		$d = $_GET["download"];
		$filepath = explode('/', $d);
		$filename = $filepath[2];
		if(substr($filename, -7)!='.sql.gz') {
			$filename = $filepath[1];
		}
		if(substr($filename, -7)!='.sql.gz') {
			$errmsg = 'Wrong filename.';
		} else {
			$out = shell_exec("sudo ssh -p $backup_sshport {$ssh_key_opt}$backup_user@$backup_server 'ls -al ./".$d."'");
			$out = explode(' ', $out);
			$filesize = (int)($out[4]);
			if($filesize == 0) {
				$errmsg = 'Empty file.';
			} else {
				header("Cache-Control: public, must-revalidate\n");
				header("Pragma: hack\n");
				header("Expires: " . gmdate("D, d M Y H:i:s", mktime(date("H") + 2, date("i"), date("s"), date("m"), date("d"), date("Y"))) . " GMT\n");
				header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
				header("Content-Type: application/gzip\n");
				header("Content-Length: " . $filesize . "\n");
				header("Content-Disposition: attachment; filename=\"" . $filename . "\"\n");
				header("Content-Transfer-Encoding: binary");
				echo shell_exec("sudo ssh -p $backup_sshport {$ssh_key_opt}$backup_user@$backup_server 'cat ./".$d."'");
				exit;
			}
		}
	}
    include('templates/header.php'); 
?>
        <!-- Page title -->
        <div class="page-header d-print-none">
            <div class="row align-items-center">
            	<div class="col" style="padding-left:22px;">
					<!-- Page pre-title -->
					<div class="page-pretitle">
						Backup DB
					</div>
					<h2 class="page-title">
						MySQL Databases Backups 
					</h2>
              	</div>
            </div>
        </div>

<? if(isset($errmsg) && $errmsg != '') { ?>
          <div class="alert alert-warning" role="alert" style="background:#FFE;">
            <div class="d-flex">
				<div style="width:55px;">
                	<svg xmlns="http://www.w3.org/2000/svg" class="icon mb-2 text-danger icon-md" width="48" height="48" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><path d="M12 9v2m0 4v.01"></path><path d="M5 19h14a2 2 0 0 0 1.84 -2.75l-7.1 -12.25a2 2 0 0 0 -3.5 0l-7.1 12.25a2 2 0 0 0 1.75 2.75"></path></svg>
             	</div>
             	<div>
				 <h3 class="text-danger" style="margin-top:6px;margin-bottom:0">Error</h3>
				 <div class="text-danger"><?=str_replace('Error: ', '', clean($errmsg));?></div>
              	</div>
            </div>
          </div>
<? } ?>
<? if(isset($successmsg) && $successmsg != '') { ?>
          <div class="alert alert-success" role="alert" style="background:#EFE;">
            <div class="d-flex">
				<div style="width:55px;">
					<svg xmlns="http://www.w3.org/2000/svg" class="icon mb-2 text-success icon-md icon-tabler-circle-check" width="48" height="48" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="12" cy="12" r="9" /><path d="M9 12l2 2l4 -4" /></svg>
              	</div>
              	<div>
                	<h3 class="text-success" style="margin-top:6px;margin-bottom:0">Success</h3>
                	<div class="text-success"><?=clean($successmsg);?></div>
              	</div>
            </div>
          </div>
<? } ?>

	  <div class="col-12">
            <div class="card">
<? 
	$backup = array();
	$list = shell_exec("sudo ssh -p $backup_sshport {$ssh_key_opt}$backup_user@$backup_server 'find . -type f -name *.sql.gz ! -name mysql.sql.gz ! -name information_schema.sql.gz ! -name performance_schema.sql.gz ! -name phpmyadmin.sql.gz ! -name sys.sql.gz'");
	$line = strtok($list, PHP_EOL);
	while ($line !== false) {
		$l = explode('/', $line);
		$backup[$l[1]][$l[2]][] = $l[3];
    	$line = strtok(PHP_EOL);
	}
	krsort($backup);
#	echo '<pre>';
#	print_r($backup);
	$date = '';
	echo '<table class="tbl1" style="max-width:1050px"><tr><th style="min-width:110px;">Date</th><th>Backup</th></tr>';
	foreach($backup as $d => $b2) {
		if($date!=$d) {
			$date = $d;
			echo '<tr><th><br>'.$date.'</th><th>&nbsp;</th></tr>';
		}
		foreach($b2 as $h => $b3) {
			echo '<tr><td>'.$h.'</td><td>';
			foreach($b3 as $b) {
                if($b=='')
                    echo '<a href="/backupdb/?download='.$d.'/'.$h.'">'.$h.'</a> &nbsp;';
                else
                    echo '<a href="/backupdb/?download='.$d.'/'.$h.'/'.$b.'">'.$b.'</a> &nbsp;';
			}
			echo '</td></tr>';
		}
	}
	echo '</table>';
?>
            </div>
          </div>
        </div>
      </div>
    </div>



<?php
    include('templates/footer.php'); 
?>
</body>
</html>
