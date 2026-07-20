<?php
    include('templates/header.php');
	#echo '<pre>'; print_r($user_databases); print_r($dbs); exit;
?>
          <!-- Page title -->
          <div class="page-header d-print-none">
            <div class="row align-items-center">
              <div class="col" style="padding-left:22px;">
                <!-- Page pre-title -->
                <div class="page-pretitle">
                  Databases
                </div>
                <h2 class="page-title" style="white-spaces:nowrap;">
                  MySQLTuner
                </h2>
              </div>
              <div class="col-auto ms-auto d-print-none">
                <div class="btn-list">
                  <a href="#" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal-report">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    Generate <span class="d-none d-sm-inline">&nbsp;a new&nbsp;</span> report
                  </a>
                </div>
            </div>
			<? /*
			<div class="col-auto ms-auto d-print-none">
                <div class="btn-list">
                  <a href="#" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal-create-dbuser">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    Create a new user
                  </a>
                </div>
              </div>
			*/ ?>
            </div>
          </div>

<?
    $uptime = (int)(trim(shell_exec("sudo mysql -NB -e \"SHOW STATUS WHERE Variable_name='Uptime'\" | awk {'print $2'}")));
    if($uptime==0) {
?>
	<p style="padding:14px;">MySQL Server is not running.</p>
<? 	} else { ?>


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
<?
		if(is_file(_PATH.'/reports/mysqltuner.html')) {
?>
		<div class="alert alert-success" role="alert" style="background:#EFE;">
			<div class="text">
				<b>Note:</b> MySQLTuner report was generated on: <?=date("F d, Y \a\\t H:i", filemtime(_PATH.'/reports/mysqltuner.html'));?>.
			</div>
		</div><br/>
		<div class="col-12">
            <div class="card" id="mysqltuner"><div class="term-container"><?
			echo trim(@file_get_contents(_PATH.'/reports/mysqltuner-style.html'));
			echo trim(@file_get_contents(_PATH.'/reports/mysqltuner.html'));
			?></div></div>
<? 		
		} else {
?>			
			<div class="alert alert-info" role="alert" style="background:#EEF0FF;">
				<div class="text"><b>Note:</b> MySQLTuner report file does not exists, please <a href="#" data-bs-toggle="modal" data-bs-target="#modal-report">geneate a new report</a> first.</div>
			</div>
<?			
		}
	} 
?>
          </div>
        </div>
      </div>
    </div>

	<form method="post" action="/" id="report" class="needs-validation" novalidate>
    <input type="hidden" name="action" value="mysqltuner-report">
    <div class="modal modal-blur fade" id="modal-report" tabindex="-1" role="dialog" aria-hidden="true">
      <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Generate a new MySQLTuner report</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <p>Please wait until report is generated.</p>
			<div class="progress">
  				<div class="progress-bar progress-bar-indeterminate"></div>
			</div>
          </div>
          <div class="modal-footer">
            <a href="#" class="btn btn-link link-primary" data-bs-dismiss="modal">
              Close
            </a>
          </div>
        </div>
      </div>
    </div>
</form>

<?php
    include('templates/footer.php'); 
?>
<script>
jQuery(document).ready(function () {
	'use strict';
	var report_running = false;
    $('#report').on('show.bs.modal', function (event) {
        //console.log(event);
        // Button that triggered the modal
        var button = event.relatedTarget;
        // $('#report .modal-title').html('');
		if(!report_running) {
			report_running = true;
			jQuery.ajax({
				method: "POST",
				url: "./ajax-mysqltuner/",
				data: { action: 'ajax-mysqltuner' }
			}).done(function( msg ) {
				console.log(msg);
				if(msg == 'OK') {
					window.location.reload();
				} else {
				}
			});
		}
    });
});
</script>
</body>
</html>
