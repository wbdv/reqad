<?php
    include('templates/header.php'); 
	#$webserver = `sudo netstat -nap | grep 443 | grep LISTEN | awk {'print $7'} | awk -F/ {'print $2'} | awk -F: {'print $1'}`;
    $port = '443';
    $webserver = shell_exec("sudo netstat -nap | grep ':443' | grep LISTEN | awk {'print $7'} | awk -F/ {'print $2'} | sed 's/://'");
	$webserver = trim($webserver);
	if($webserver!='httpd') {
		if($webserver=='nginx')
			$errmsg = 'Current web server is nginx.';

        $webserver = shell_exec("sudo netstat -nap | grep ':8080' | grep LISTEN | awk {'print $7'} | awk -F/ {'print $2'} | sed 's/://'");
        $webserver = trim($webserver);
        if($webserver!='httpd') {
            $errmsg = 'Apache is not running. '.$errmsg;
        } else {
            $port = '8080';
            $errmsg = 'Apache is running on '.$port.'. '.$errmsg;
        }
	}

?>
        <!-- Page title -->
        <div class="page-header d-print-none">
            <div class="row align-items-center">
            	<div class="col" style="padding-left:22px;">
					<!-- Page pre-title -->
					<div class="page-pretitle">
						web services
					</div>
					<h2 class="page-title">
						Apache Status 
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
            <div class="card" id="apachestat" style="padding:15px;white-space:nowrap;overflow:auto">
            <style>
            #apachestat tr:nth-child(even) {background: #EEE}
            </style>
<?
    if($port == '8080') {
        echo @file_get_contents('http://localhost:8080/server-status');
    } else {
        $output = @file_get_contents('http://localhost/server-status');
        if($output=='') {
            $HOSTNAME=trim(`hostname`);
            $output = @file_get_contents('http://'.$HOSTNAME.'/server-status');
        }
        echo $output;
    }
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
