<?php
/*
 * SECURITY (fixed 2026-09-08): this page used to interpolate $_GET["download"]
 * straight into a shell command --
 *
 *     shell_exec("sudo ssh ... 'cat ./".$d."'")
 *
 * -- while only checking that ONE EXPLODED SEGMENT of it ended in .sql.gz. $d
 * itself was never validated, so a single quote in the query string closed the
 * remote command and everything after it ran in the local shell as the panel
 * user, which has NOPASSWD:ALL. That was remote code execution as root behind
 * nothing but the Nginx basic auth.
 *
 * The path is now whitelisted to the exact shape backupdb.sh writes
 * (<date>/<db>.sql.gz), out of a charset with no
 * quote, $, backtick, space or backslash in it, and every command is assembled
 * by remote_backup_ssh_cmdline(), which escapeshellarg()s each part.
 *
 * The download also used `echo shell_exec(...)`, which reads the whole dump into
 * PHP memory before sending a byte; it now streams with passthru().
 *
 * Connection settings are the ones on the Backup page's Remote tab
 * (remote_backup_config(): settings table, defines.php as fallback), and the
 * dumps live in <dest>/databases/<date>/<db>.sql.gz — written by
 * scripts/backupdb.sh. <dest> empty = the ssh user's home.
 */
	$bdb_cfg   = remote_backup_config();
	$bdb_ready = remote_backup_configured($bdb_cfg);
	$bdb_root  = remote_backup_base($bdb_cfg).'databases';

	if(isset($_GET["download"])) {
		$d = (string)$_GET["download"];
		/* <date>/<db>.sql.gz and nothing else: no quote, $, backtick, space,
		   backslash or ".." can get into the remote command */
		$valid = (bool)preg_match('#^[0-9]{4}-[0-9]{2}-[0-9]{2}/[A-Za-z0-9_-]{1,64}\.sql\.gz$#', $d);
		if(!$valid) {
			$errmsg = 'Wrong filename.';
		} elseif(!$bdb_ready) {
			$errmsg = 'No backup server is configured.';
		} else {
			$filename = basename($d);
			$remote   = $bdb_root.'/'.$d;
			$rc = 0;
			$filesize = trim(remote_backup_ssh($bdb_cfg, "stat -c %s '".$remote."'", $rc));
			if($rc !== 0 || !ctype_digit($filesize) || (int)$filesize === 0) {
				$errmsg = 'Empty file.';
			} else {
				/* passthru, not echo shell_exec: a dump must not be buffered in
				   PHP memory in its entirety before the first byte is sent */
				remote_backup_stream($bdb_cfg, $remote, (int)$filesize, $filename);
				exit;
			}
		}
	}
	/* The listing runs BEFORE the header is included: $errmsg is rendered by the
	   alert block a few lines below the header, so an error raised after that
	   point would never be shown. */
	$bdb_list = '';
	if(!$bdb_ready) {
		$errmsg = 'No backup server is configured — set it up on the Backup page, Remote tab.';
	} else {
		/* relative paths (<date>/<db>.sql.gz) so the parsing below does not
		   depend on where <dest> is; a missing directory is just "no backups" */
		$rc = 0;
		$bdb_list = remote_backup_ssh($bdb_cfg,
			"cd '".$bdb_root."' 2>/dev/null || exit 0; "
			."find . -mindepth 2 -maxdepth 2 -type f -name '*.sql.gz' -printf '%P %s\n'", $rc);
		if($rc !== 0) { $errmsg = 'Cannot reach the backup server: '.trim($bdb_list); $bdb_list = ''; }
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
	/* "<date>/<db>.sql.gz <bytes>" per line */
	$backup = array();
	foreach(explode("\n", $bdb_list) as $line) {
		if(!preg_match('#^([0-9]{4}-[0-9]{2}-[0-9]{2})/([A-Za-z0-9_-]{1,64}\.sql\.gz) ([0-9]+)$#', trim($line), $m)) continue;
		$backup[$m[1]][$m[2]] = (int)$m[3];
	}
	krsort($backup);
	echo '<table class="tbl1" style="max-width:1050px"><tr><th style="min-width:110px;">Date</th><th>Databases</th></tr>';
	if(!$backup && $bdb_ready && !isset($errmsg))
		echo '<tr><td colspan="2">No database backups in '.h($bdb_cfg['user'].'@'.$bdb_cfg['host'].':'.$bdb_root).' yet.</td></tr>';
	foreach($backup as $d => $files) {
		ksort($files);
		echo '<tr><td>'.h($d).'</td><td>';
		foreach($files as $b => $bytes) {
			/* these names come off the backup server, so they are escaped
			   both as URL and as HTML rather than pasted in raw */
			echo '<a href="/backupdb/?download='.rawurlencode($d).'/'.rawurlencode($b).'" title="'.h(round($bytes/1048576, 1).' MB').'">'.h(substr($b, 0, -7)).'</a> &nbsp;';
		}
		echo '</td></tr>';
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
