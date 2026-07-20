<?php
	$domains = array();

	$output = shell_exec('sudo ls -1 /etc/exim/domains/ | sort');
	if(trim($output)!='')
		$domains = array_map('trim', explode("\n", trim($output)));

	#echo '<pre>'; print_r($domains); exit;

	$nb_accounts = count($domains);

	$output = shell_exec("/usr/sbin/ip address show | grep 'scope global' | grep 'inet ' | awk {'print \$2'} | awk -F/ {'print \$1'}");
	$local_ips = array_map('trim', explode("\n", trim($output)));

	include('templates/header.php');
 	
?>
          <!-- Page title -->
          <div class="page-header d-print-none">
            <div class="row align-items-center">
              <div class="col" style="padding-left:22px;">
                <!-- Page pre-title -->
                <div class="page-pretitle">
                  Email
                </div>
                <h2 class="page-title">
                  SMTP Statistics 
                </h2>
              </div>
            </div>
          </div>

<?	if($nb_accounts == 0 ) { ?>
		<p style="padding:14px;">There are no domain with active mail setup on this server.</p>
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
<? #if(isset($_GET['successmsg']) && $_GET['successmsg'] != '') { ?>
<? if(isset($successmsg) && $successmsg != '') { ?>
          <div class="alert alert-success" role="alert" style="background:#EFE;">
            <div class="d-flex">
				<div style="width:55px;">
					<svg xmlns="http://www.w3.org/2000/svg" class="icon mb-2 text-success icon-md icon-tabler-circle-check" width="48" height="48" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="12" cy="12" r="9" /><path d="M9 12l2 2l4 -4" /></svg>
              	</div>
              	<div>
                	<h3 class="text-success" style="margin-top:6px;margin-bottom:0">Success</h3>
                	<div class="text-success"><?=htmlspecialchars($successmsg);?></div>
              	</div>
            </div>
          </div>
<? } ?>

       <div class="row row-deck row-cards">
            <div class="col-sm-6 col-lg-3">
              <div class="card">
                <div class="card-body">
                  <div class="d-flex align-items-center">
                    <div class="subheader">Delivered mails</div>
                  </div>
                  <div class="h1 mb-3"><?=shell_exec("sudo grep '<= c' /var/log/exim/main.log | wc -l");?></div>
                </div>
              </div>
            </div>

            <div class="col-sm-6 col-lg-3">
              <div class="card">
                <div class="card-body">
                  <div class="d-flex align-items-center">
                    <div class="subheader">Errors</div>
                  </div>
                  <div class="h1 mb-3"><?=shell_exec("sudo grep '\*' /var/log/exim/main.log | awk {'print $5'} | sort | uniq | wc -l");?></div>
                </div>
              </div>
            </div>

            <div class="col-sm-6 col-lg-3">
              <div class="card">
                <div class="card-body">
                  <div class="d-flex align-items-center">
                    <div class="subheader">Emails in queue</div>
                  </div>
                  <div class="h1 mb-3"><?=shell_exec("sudo exim -bpc");?></div>
                </div>
              </div>
            </div>
		</div>
		<br />

          <div class="page-header d-print-none">
            <div class="row align-items-center">
              <div class="col" style="padding-left:22px;">
                <h2 class="page-title">
					List of Errors
                </h2>
              </div>
            </div>
          </div>

		<div class="col-12">
            <div class="card">
                <div class="table-responsive">
                  <table class="table table-vcenter card-table table-responsive"">
                    <thead>
                      <tr>
                        <th class="w-1" style="background-color:#DEF;">ID</th>
                        <th class="w-10" style="background-color:#DEF;">DATE <svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' width='16' height='16'><path fill='none' stroke='currentColor' stroke-linecap='round' stroke-linejoin='round' stroke-width='1' d='M5 10l3 -3l3 3'/></svg></th>
                        <th style="background-color:#DEF;">EMAIL</th>
                        <th style="background-color:#DEF;">MESSAGE</th>
                      </tr>
                    </thead>
                    <tbody>
                   <?
						$i=0;
    					$results = $db->query('SELECT * FROM errors GROUP BY email ORDER BY date desc');
				    	while ($row = $results->fetchArray()) {
							$i++;
                    ?>
                      <tr>
                        <td data-label="ID">
                          <div class="d-flex">
                            <div class="flex-fill">
                              <div class="text-muted"><?=$i;?>.</div>
                            </div>
                          </div>
                        </td>
                        <td data-label="Domain">
                          <div class="d-flex py-1 align-items-center">
                            <div class="flex-fill">
                              <div class="font-weight-medium" style="text-wrap: nowrap;"><?=$row["date"];?></div>
                            </div>
                          </div>
                        </td>
                        <td data-label="MX">
                          <div class="d-flex">
                            <div class="flex-fill">
                              <div class="font-weight-medium"><?=$row["email"];?></div>
                            </div>
                          </div>
                        </td>
                        <td data-label="SPF">
                          <div class="d-flex">
                            <div class="flex-fill">
                            	<div class="font-weight-medium" style="font-family:monospace;white-space:nowrap;"><?=nl2br(wordwrap(str_replace('\n', " ", substr($row["errmsg"],0,152)), 100, "\n", true));?></div>
                            </div>
                          </div>
                        </td>
                      </tr>
                      <? } ?>
                    </tbody>
                  </table>
                </div>
            </div>
			<?  } ?>
            </div>
          </div>
        </div>
      </div>
    </div>

	<form method="get" action="#" id="check-email-details" class="needs-validation" novalidate>
    <div class="modal modal-blur fade" id="modal-info" tabindex="-1" role="dialog" aria-hidden="true">
      <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;"><span id="domain-title">&nbsp;</span></h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body" id="email-info">
			<p><b>MX settings:</b></p><pre>&nbsp;</pre><p><b>SPF settings:</b></p><pre>&nbsp;</pre><p><b>DKIM settings:</b></p><pre>&nbsp;</pre><p><b>DMARC settings:</b></p><pre>&nbsp;</pre>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-primary" data-bs-dismiss="modal" aria-label="Close">Close</button>
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
	$("#modal-info").on('shown.bs.modal', function(event) {
		var button = event.relatedTarget;
		var domain = button.getAttribute('data-bs-domain');
		$('#domain-title').html('Details about email settings for <b>'+domain+'</b>');
		jQuery.ajax({
			method: "POST",
			url: "./ajax-check-email/",
			data: { action: 'ajax-check-email', domain: domain }
		}).done(function( msg ) {
			if(msg != '') {
				$('#email-info').html(msg);
			}
		});		
	});

	$("#modal-info").on('hide.bs.modal', function(event) {
		$('#domain-title').html('&nbsp;');
		$('#email-info').html('<p><b>MX settings:</b></p><pre>&nbsp;</pre><p><b>SPF settings:</b></p><pre>&nbsp;</pre><p><b>DKIM settings:</b></p><pre>&nbsp;</pre><p><b>DMARC settings:</b></p><pre>&nbsp;</pre>');
	});
});
</script>
</body>
</html>
