<?php
    include('templates/header.php'); 

    $results = $db->query('SELECT count(*) as nb FROM accounts');
    $row = $results->fetchArray();
    $nb_accounts = (int)($row["nb"]);

	#$certbot_certs = `sudo certbot certificates 2>&1 | grep 'Certificate Name:' | awk {'print $3'}`;
	#$certbot_certs = explode("\n", trim($certbot_certs));
    #echo '<pre>'; print_r($certbot_certs); exit;
?>
        <!-- Page title -->
        <div class="page-header d-print-none">
            <div class="row align-items-center">
            	<div class="col" style="padding-left:22px;">
					<!-- Page pre-title -->
					<div class="page-pretitle">
						SSL / TLS
					</div>
					<h2 class="page-title" style="white-space:nowrap">
						List SSL <span class="d-none d-sm-inline">&nbsp;/ TLS</span> Certificates
					</h2>
					<div style="width:450px;float:left;">
					Cerbot auto-renew:
					<?
						$output = trim(shell_exec("sudo systemctl list-unit-files --type=timer --all certbot-renew.timer | grep 'certbot-renew.timer' | awk {'print $2'}"));
						if( $output=='enabled' ) {
					?>					
					<span class="badge bg-success sm" style="position:relative;top:-2px;">enabled</span> &nbsp;
					<?  } else { ?>
					<span class="badge bg-danger sm" style="position:relative;top:-2px;">disabled</span>
					<?  
						} 
					
						if(is_file('/etc/letsencrypt/renewal-hooks/post/reqad.sh')) { 
							echo '<span class="d-none d-sm-inline">Reqad SSL renewal-hook present.</span>'; 
						} else {
							shell_exec("echo -e '#!/bin/bash\nsystemctl restart reqad\n/usr/local/reqad/scripts/update_email_sni\nchmod -R a+rx /etc/letsencrypt/' | sudo tee /etc/letsencrypt/renewal-hooks/post/reqad.sh");
							shell_exec("sudo chmod +x /etc/letsencrypt/renewal-hooks/post/reqad.sh");
							if(is_file('/etc/letsencrypt/renewal-hooks/post/reqad.sh')) { 
								echo 'Reqad SSL renewal-hook added.'; 
							} else {
								echo 'Reqad SSL renewal-hook cannot be created!'; 
							}
						}
					?>
					</div>
              	</div>
              	<div class="col-auto ms-auto d-print-none">
	                <div class="btn-list">
                  		<a href="#" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal-add-ssl">
                    		<svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
	                    	Add&nbsp;<span class="d-none d-sm-inline">/ Replace</span>&nbsp;SSL Cert<span class="d-none d-sm-inline">ificate</span>
                  		</a>
                	</div>
	            </div>
            </div>
        </div>

<?	/* if($nb_accounts == 0 && $certbot_certs == '') { ?>
		<p style="padding:14px;">There are no SSL/TLS certificates on this server.</p>
<? 	} else { */ ?>


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
     $results = $db->query('SELECT domain FROM accounts');
	 $sslcert = array();
     while ($row = $results->fetchArray()) {
		$sslcert[] = $row['domain'];
	}
	
	$hostname = trim(`hostname`);
	if(!in_array($hostname, $sslcert))
		$sslcert[] = $hostname;

/*		
	foreach($certbot_certs as $certbot_domain) {
		// only check domains that are not in accounts list
		#if(!array_key_exists($certbot_domain, $sslcert)) {
		if(!in_array($certbot_domain, $sslcert)) {
			$sslcert[] = $certbot_domain;
		}
	}
*/		

	sort($sslcert);
	#ksort($sslcert);
	#echo '<pre>'; print_r($sslcert); exit;
?>

		<div class="col-12">
            <div class="card">
		        <!-- div class="card-header">
	                <h3 class="card-title text-nowrap">List Accounts</h3>
                    <div class="ms-auto text-muted">
                      Search:
                      <div class="ms-2 d-inline-block">
                        <input type="text" class="form-control form-control-sm" aria-label="Search email" size="10">
                      </div>
                    </div>
                </div -->
                <div class="table-responsive">
                  <table class="table table-vcenter card-table">
                    <thead>
                      <tr>
                        <th style="background-color:#DEF;">ID</th>
                        <th style="background-color:#DEF;">Domain</th>
                        <th style="background-color:#DEF;">Common Name</th>
                        <th style="background-color:#DEF;">Certificate</th>
                        <th style="background-color:#DEF;">CA</th>
                        <th style="background-color:#DEF;">Expiration Date</th>
                        <th class="w-1" style="background-color:#DEF;"></th>
                      </tr>
                    </thead>
                    <tbody>
                    <?
						$i=0;
						foreach($sslcert as $cn) {
							$i++;
							$cn2 = str_replace('.', '_', $cn);
                    ?>
                      <tr id="tr_<?=$cn2;?>">
                        <td data-label="ID" style="min-width:30px;">
                          <div class="d-flex">
                            <div class="flex-fill">
                              <div class="text-muted"><?=$i;?>.</div>
                            </div>
                          </div>
                        </td>
                        <td data-label="domain" style="min-width:180px;">
                          <div class="d-flex py-1 align-items-center">
                            <div class="flex-fill">
                              <div class="font-weight-medium" title=""><?=$cn;?></div>
                            </div>
                          </div>
                        </td>
                        <td data-label="cn">
                          <div class="d-flex py-1 align-items-center">
                            <div class="flex-fill">
                              <div class="font-weight-medium" id="domains_<?=$cn2;?>" style="min-width:300px;"><div style="width:300px;height:10px;" class="loading"></div></div>
                            </div>
                          </div>
                        </td>
                        <td data-label="cert">
                          <div class="d-flex py-1 align-items-center">
                            <div class="flex-fill">
                              <div class="font-weight-medium" id="cert_<?=$cn2;?>" style="min-width:100px;"><div style="width:100px;height:10px;" class="loading"></div></div>
                            </div>
                          </div>
                        </td>
                        <td data-label="ca">
                          <div class="d-flex py-1 align-items-center">
                            <div class="flex-fill">
                              <div class="font-weight-medium" id="ca_<?=$cn2;?>" style="min-width:200px;"><div style="width:200px;height:10px;" class="loading"></div></div>
                            </div>
                          </div>
                        </td>
                        <td data-label="expiration">
                          <div class="d-flex py-1 align-items-center">
                            <div class="flex-fill">
                              <div class="font-weight-medium" id="expire_<?=$cn2;?>" style="min-width:180px;"><div style="width:180px;height:10px;" class="loading"></div></div>
                            </div>
                          </div>
                        </td>
                        <td>
                          <div class="btn-list flex-nowrap" id="excl_<?=$cn2;?>" style="min-width:100px;">
							<div style="width:50px;height:10px;background:#EEE;"></div>
                          </div>
                        </td>
                      </tr>
                      <? } /* ?>
                      <tr>
                        <td data-label="ID">
                          <div class="d-flex">
                            <div class="flex-fill">
                              <div class="text-muted">2.</div>
                            </div>
                          </div>
                        </td>
                        <td data-label="Domain">
                          <div class="d-flex py-1 align-items-center">
                            <div class="flex-fill">
                              <div class="font-weight-medium">example2.com</div>
                            </div>
                          </div>
                        </td>
                        <td data-label="User">
                          <div class="d-flex py-1 align-items-center">
                            <div class="flex-fill">
                              user2
                            </div>
                          </div>
                        </td>
                        <td data-label="Usage">
                          <div class="d-flex">
                            <div><strong>7.99 GB </strong>of 8 GB</div>
                          </div>
                          <div class="progress progress-xs">
                            <div class="progress-bar bg-danger" role="progressbar" style="width: 99%"></div>
                          </div>
                        </td>
                        <td class="text-muted" data-label="Status">
                          <span class="badge bg-success">Active</span>
                        </td>
                        <td>
                          <div class="btn-list flex-nowrap">
                            <a href="#" class="btn btn-white">
                              Edit
                            </a>
                          </div>
                        </td>
                      </tr><? */ ?>
                    </tbody>
                  </table>
                </div>
                <? /* pagination
				        <div class="card-footer d-flex align-items-center">
                  <p class="m-0 text-muted"><span class="d-none d-xl-inline">Showing </span><span>1</span> to <span>2</span> of <span>2</span></p>
                  <!--
                  <ul class="pagination m-0 ms-auto">
                    <li class="page-item disabled">
                      <a class="page-link" href="#" tabindex="-1" aria-disabled="true">
                        <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><polyline points="15 6 9 12 15 18"></polyline></svg>
                        prev
                      </a>
                    </li>
                    <li class="page-item active"><a class="page-link" href="#">1</a></li>
                    <li class="page-item"><a class="page-link" href="#">2</a></li>
                    <li class="page-item"><a class="page-link" href="#">3</a></li>
                    <li class="page-item">
                      <a class="page-link" href="/email-accounts/?page=2">
                        next <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><polyline points="9 6 15 12 9 18"></polyline></svg>
                      </a>
                    </li>
                  </ul>
                  -->
                </div>
                */ ?>
              </div>
            </div>
			<?  #} ?>

            </div>
          </div>
        </div>
      </div>
    </div>

	<form method="post" action="/" id="update-ssl" class="needs-validation" novalidate>
    <input type="hidden" name="action" value="update-ssl">
    <div class="modal modal-blur fade" id="modal-add-ssl" tabindex="-1" role="dialog" aria-hidden="true">
      <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Add or replace SSL/TLS certificate</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <p>Select the domain name to check current status and then you can add or replace SSL/TLS certificate.</p>
            <div class="mb-3">
            	<label class="form-label">Domain Name</label>
			  	<select name="domain" id="domain" class="form-select">
				  <option value=""></option>
					<? foreach ($sslcert as $domain) { ?>
					<option value="<?=$domain;?>"><?=$domain;?></option>
					<? } ?>
              	</select>              
				<div class="invalid-feedback" id="invalid-domain">
                		Please select a domain name.
          		</div>
            </div>
            <div class="mb-3" id="existing">
            	<label class="form-label">Existing certificate:</label>
				<div id="certificate"></div>
            </div>
            <div class="mb-3" id="replace">
            	<label class="form-label">Replace with certificate:</label>
				<div id="choosecert">
					<div class="form-selectgroup form-selectgroup-boxes d-flex flex-column">
					<label class="form-selectgroup-item flex-fill">
					<input type="radio" name="ssltype" id="ssltype1" value="letsencrypt" class="form-selectgroup-input">
					<div class="form-selectgroup-label d-flex align-items-center p-3">
						<div class="me-3">
							<span class="form-selectgroup-check"></span>
						</div>
						<div>
							Let's Encrypt
						</div>
					</div>
					</label>
					<label class="form-selectgroup-item flex-fill">
					<input type="radio" name="ssltype" id="ssltype2" value="own" class="form-selectgroup-input">
					<div class="form-selectgroup-label d-flex align-items-center p-3">
						<div class="me-3">
							<span class="form-selectgroup-check"></span>
						</div>
						<div>
							Use your own SSL/TLS certificate and private key
						</div>
					</div>
					</label>
					</div>
				</div>
				<div id="uploadcert">
					<br>Certificate:<br>
					<textarea id="newcert" name="newcert" style="font-family:monospace;width:100%;padding:10px;vertical-align:bottom;min-height:120px;" placeholder="-----BEGIN CERTIFICATE-----"></textarea>
					<br><br>
					<input type="button" id="loadkey" class="btn btn-sm btn-primary" style="float:right;margin-bottom:4px;" value="Load existing key" />
					Private key: 
					<br>
					<textarea id="privkey" name="privkey" style="font-family:monospace;width:100%;padding:10px;vertical-align:bottom;min-height:120px;" placeholder="-----BEGIN PRIVATE KEY-----"></textarea>
				</div>
            </div>
			<div id="certerror" class="alert alert-warning" role="alert" style="background:#FEE;">
				<div id="certerrormsg" class="text-danger"></div>
            </div>
          </div>
          <div class="modal-footer">
            <a href="#" class="btn btn-link link-secondary" data-bs-dismiss="modal">
              Cancel
            </a>
            <button id="submit-btn" class="btn btn-primary" type="submit"><svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg> Update SSL</button>
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
	$('#existing').hide();
	$('#replace').hide();
	$("#uploadcert").hide();
	$("#choosecert").hide();
	$("#certerror").hide();

<?	
	$i=0;
	foreach($sslcert as $cn) { 
		$i++;
		$cn2 = str_replace('.', '_', $cn);
		#if($i>1) continue;
?>
	//console.log('<?=$cn;?>');
	jQuery.ajax({
		method: "POST",
		url: "./ajax-ssl/",
		data: { action: 'ajax-ssl', info: 1, domain: '<?=$cn;?>' }
	}).done(function( msg ) {
		const certinfo = msg.split('|');
		$('#domains_<?=$cn2;?>').html(certinfo[0]);
		$('#cert_<?=$cn2;?>').html(certinfo[1]);
		$('#ca_<?=$cn2;?>').html(certinfo[2]);
		$('#expire_<?=$cn2;?>').html(certinfo[3]);
		if(certinfo[4]<=0) {
			$('#tr_<?=$cn2;?>').css('background-color', '#FCC');
			$('#excl_<?=$cn2;?>').html('<svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-alert-triangle" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"> <path stroke="none" d="M0 0h24v24H0z" fill="none"></path> <path d="M10.24 3.957l-8.422 14.06a1.989 1.989 0 0 0 1.7 2.983h16.845a1.989 1.989 0 0 0 1.7 -2.983l-8.423 -14.06a1.989 1.989 0 0 0 -3.4 0z"></path> <path d="M12 9v4"></path> <path d="M12 17h.01"></path> </svg>');
		} else
			$('#excl_<?=$cn2;?>').html('');
		//console.log( msg );
	});
<?  } ?>

	$("#ssltype2").on("focus", function(value) {
		$("#uploadcert").show();
	});
	$("#ssltype1").on("focus", function(value) {
		$("#uploadcert").hide();
		$("#certerror").hide();
	});
	$("#domain").on("change", function(value) {
		var domain = $(this).val();
		if(domain!='') {
			jQuery.ajax({
				method: "POST",
				url: "./ajax-ssl/",
				data: { action: 'ajax-ssl', domain: domain }
			}).done(function( msg ) {
				if(msg.substr(0, 6) != 'Error:') {
					$("#certerror").hide();
					$('#certificate').html(msg);
					$('#existing').show();
					if(msg.substr(0,18)=='<!--self-signed-->') {
						$("#ssltype1").prop("checked", true);
						$("#ssltype1").prop("disabled", false);
						$("#ssltype1").focus();
						$("#uploadcert").hide();
						$("#choosecert").show();
					} else {
						$("#ssltype1").prop("disabled", true);
						$("#ssltype2").prop("checked", true);
						$("#ssltype2").focus();
						$("#uploadcert").show();
						$("#choosecert").hide();
					}
					$('#replace').show();
				} else {
					$('#existing').hide();
					$('#replace').hide();
					$("#uploadcert").hide();
					$("#choosecert").hide();
					$("#certerror").show();
					$('#certerrormsg').html(msg);
				}
			});
		}
	});
	$("#newcert").on("change", function(value) {
		$("#certerror").hide();
		console.log('check certificate');
		var newcert = $(this).val().trim();
		var domain = $('#domain').val();
		if(newcert.substr(0,27) != '-----BEGIN CERTIFICATE-----') {
			$("#certerror").show();
			$('#certerrormsg').html('Certificate should start with -----BEGIN CERTIFICATE-----');
		} else {
			jQuery.ajax({
				method: "POST",
				url: "./ajax-ssl/",
				data: { action: 'ajax-ssl', domain: domain, newcert: newcert }
			}).done(function( msg ) {
				if(msg.substr(0, 6) != 'Error:') {
					console.log('++ certificate is ok');
					$('#newcert').css('border', '1px solid #2fb344');
					$('#newcert').css('background-color', '#efe');
					$('#newcert').css('background-image', 'url(/images/check.svg)');
					$('#newcert').css('background-position', "99% 8%");
					$('#newcert').css('background-repeat', "no-repeat");
					$('#newcert').scrollTop(0);
					$('#newcert').prop('readonly', true);
					$('#newcert').css('overflow', 'hidden');
					//$('#newcert').removeClass('is-invalid');
					//$('#newcert').addClass('was-validated');
				} else {
					console.log('-- certificate error');
					$('#newcert').addClass('is-invalid');
					$('#newcert').removeClass('was-validated');
					$("#certerror").show();
					$('#certerrormsg').html(msg);
				}
			});
		}
	});
	$("#update-ssl").submit(function(event) {
    	event.preventDefault();
   		event.stopPropagation();
		$("#certerror").hide();
		console.log('submit update certificate');
		var domain = $('#domain').val();
		var newcert = $('#newcert').val().trim();
		var privkey = $('#privkey').val().trim();
		if($("#ssltype1").prop("checked")==true) {
			console.log('upgrade self to letsencrypt');
			$('#update-ssl').removeClass('is-invalid');
			$('#update-ssl').addClass('was-validated');

			$('#submit-btn').prop('disabled', true);
			$("#update-ssl").unbind('submit').submit();
		} else if(newcert.substr(0,27) != '-----BEGIN CERTIFICATE-----') {
			$("#certerror").show();
			$('#certerrormsg').html('Certificate should start with -----BEGIN CERTIFICATE-----');
		} else if(privkey.substr(0,27) != '-----BEGIN PRIVATE KEY-----' && privkey.substr(0,31) !='-----BEGIN RSA PRIVATE KEY-----') {
			$("#certerror").show();
			$('#certerrormsg').html('Private key should start with -----BEGIN PRIVATE KEY-----');
		} else {
			jQuery.ajax({
				method: "POST",
				url: "./ajax-ssl/",
				data: { action: 'ajax-ssl', domain: domain, newcert: newcert, privkey: privkey }
			}).done(function( msg ) {
				if(msg.substr(0, 6) != 'Error:') {
					console.log('sent!');
					$('#privkey').css('border', '1px solid #2fb344');
					$('#privkey').css('background-color', '#efe');
					$('#privkey').css('background-image', 'url(/images/check.svg)');
					$('#privkey').css('background-position', "99% 8%");
					$('#privkey').css('background-repeat', "no-repeat");
					$('#privkey').scrollTop(0);
					$('#privkey').prop('readonly', true);
					$('#privkey').css('overflow', 'hidden');

					$('#update-ssl').removeClass('is-invalid');
					$('#update-ssl').addClass('was-validated');

					$('#submit-btn').prop('disabled', true);
					$("#update-ssl").unbind('submit').submit();
				} else {
					$("#certerror").show();
					$('#certerrormsg').html(msg);
				}
			});
		}
    });
	$('#loadkey').click(function(event) {
			var domain = $('#domain').val();
			console.log('Load existing private key for '+domain);
			jQuery.ajax({
				method: "POST",
				url: "./ajax-ssl/",
				data: { action: 'ajax-ssl-getkey', domain: domain }
			}).done(function( msg ) {
				if(msg.substr(0, 6) != 'Error:') {
					$('#privkey').html(msg);
				} else {
					$("#certerror").show();
					$('#certerrormsg').html(msg);
				}
			});
    });	
});
</script>
</body>
</html>
