<?php
#phpinfo(32);
	$settings = array();
	$results = $db->query('SELECT name,value FROM settings');
	while ($row = $results->fetchArray()) {
		#echo $row["name"].' = '.$row["value"].'<br>';
		$settings_name = $row["name"];
		$settings[$settings_name] = $row["value"];
	}
	#echo "<pre>"; print_r($settings);exit;
	$wp_versions = array();
	$wp_latest = '';
	$stable_file = _PATH.'/wptoolkit/stable-check.json';
	$cache_life = '3600'; //caching time, in seconds
	if(!is_file($stable_file) || time() - filemtime($stable_file) >= $cache_life) {
		shell_exec('curl -s https://api.wordpress.org/core/stable-check/1.0/ > '.$stable_file.'.tmp');
		$wp_versions = @json_decode(file_get_contents($stable_file.'.tmp'), true);
		if(!empty($wp_versions)) {
			shell_exec('/bin/mv '.$stable_file.'.tmp '.$stable_file);
		} else {
			$errmsg = 'Error: Cannot download stable-check.json from wordpress.org website.';
		}
	}

	if(is_file($stable_file)) {
		$wp_versions = json_decode(file_get_contents(_PATH.'/wptoolkit/stable-check.json'), true);
		#echo "#wp_versions<pre>"; print_r($wp_versions); 
		$wp_latest = array_search('latest', $wp_versions);
		#echo "#wp_latest<pre>"; print_r($wp_latest); 
		#echo 'Last error: ', json_last_error_msg(), PHP_EOL, PHP_EOL;
	}

	include('templates/header.php');	
?>
        <!-- Page title -->
        <div class="page-header d-print-none">
            <div class="row align-items-center">
              <div class="col" style="padding-left:22px;">
                <!-- Page pre-title -->
                <div class="page-pretitle">
                  Wordpress Toolkit
                </div>
                <h2 class="page-title">
                  List of Wordpress Websites
                </h2>
              </div>
              <div class="col-auto ms-auto d-print-none">
                <div class="btn-list">
                  <a href="#" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal-wp-install">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    Install Wordpress
                  </a>
                </div>
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

		<div id="progress" class="alert alert-info" role="alert" style="background:#EEF0FF;display:none;"></div>

		<div class="col-12">
            <div class="card">
			<div class="table-responsive">
				<table class="table table-vcenter card-table table-nowrap">
				<thead>
					<tr>
						<th style="background-color:#DEF;">ID</th>
						<th style="background-color:#DEF;">Domain</th>
						<th style="background-color:#DEF;">Title</th>
						<th style="background-color:#DEF;" colspan="2">WP Version</th>
						<th style="background-color:#DEF;">Comments</th>
						<th style="background-color:#DEF;">Status</th>
						<th style="background-color:#DEF;">Created At</th>
						<th class="w-5" style="background-color:#DEF;"></th>
					</tr>
				</thead>
				<tbody>
					<?
						$i=0;
						$results = $db->query('SELECT * FROM wordpress ORDER BY domain');
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
                        <td data-label="ID">
                          <div class="d-flex">
                            <div class="flex-fill">
								<div class="font-weight-medium"><a href="https://<?=$row["domain"];?>" target="_blank">
								  <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-link" width="24" height="24" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">   <path stroke="none" d="M0 0h24v24H0z" fill="none"></path>   <path d="M10 14a3.5 3.5 0 0 0 5 0l4 -4a3.5 3.5 0 0 0 -5 -5l-.5 .5"></path>   <path d="M14 10a3.5 3.5 0 0 0 -5 0l-4 4a3.5 3.5 0 0 0 5 5l.5 -.5"></path></svg>							  	
								  <?=$row["domain"];?></a></div>
                            </div>
                          </div>
                        </td>
                        <td data-label="ID">
                          <div class="d-flex">
                            <div class="flex-fill">
                              <div class="text-muted"><?=$row['title'];?></div>
                            </div>
                          </div>
                        </td>
                        <td data-label="ID">
                          <div class="d-flex">
                            <div class="flex-fill">
                              	<div class="text-muted">
								<?
									$wp_version = $row['wp_version'];
									echo $wp_version;
								?>
								</div>
                            </div>
                          </div>
                        </td>
                        <td data-label="ID">
                          <div class="d-flex">
                            <div class="flex-fill">
                              	<div class="text-muted">
								<?
									if($wp_versions[$wp_version] == 'latest') { ?>
		                        	<span class="badge bg-success">Latest</span>
								<? } else if($wp_versions[$wp_version] == 'outdated') { ?>
		                        	<span class="badge bg-orange">Outdated</span>
								<? } else if($wp_versions[$wp_version] == 'insecure') { ?>
		                        	<span class="badge bg-red">Insecure</span>
								<? } ?>
								</div>
                            </div>
                          </div>
                        </td>
                        <td data-label="ID">
                          <div class="d-flex">
                            <div class="flex-fill">
                              <div class="text-muted"><?=$row['comments']!=''?$row['comments']:'-';?></div>
                            </div>
                          </div>
                        </td>
                        <td data-label="ID">
                          <div class="d-flex">
                            <div class="flex-fill">
							<? if($row["status"] == 'active') { ?>
	                        	<span class="badge bg-success">Active</span>
					    	<? } else if($row["status"] == 'inactive') { ?>
                          		<span class="badge bg-orange">Inactive</span>
                        	<? } else if($row["status"] == 'suspended') { ?>
                          		<span class="badge bg-danger">Suspended</spa>
						  	<? } else if($row["status"] == 'maintenance') { ?>
                          		<span class="badge bg-danger">Maintenance</spa>
                        	<? } ?>
                            </div>
                          </div>
                        </td>
                        <td data-label="Created on" class="text-muted">
                              <div class="font-weight-medium"><?=date("M jS, Y - H:i", strtotime($row["created_at"]));?></></div>
                        </td>
                        <td>
                          <div class="btn-list flex-nowrap">
                            <form method="post" action="./wp-toolkit/" target="_blank" style="display:inline;margin:0;">
                            <input type="hidden" name="action" value="wp-auto-login">
                            <input type="hidden" name="user" value="<?=htmlspecialchars($row["user"], ENT_QUOTES);?>">
                            <button type="submit" class="btn btn-primary">Login</button></form>
                            <a href="#" class="btn btn-white btn-md" data-bs-toggle="modal" data-bs-target="#modal-wp-scan" data-bs-user="<?=$row["user"];?>">Scan</a>
<? /*                            <a href="#" class="btn btn-white disabled" data-bs-toggle="modal" data-bs-target="#modal-wp-clone" data-bs-user="<?=$row["user"];?>">Clone</a> */ ?>
                          </div>
                        </td>
					</tr>
					<? } ?>
				</tbody>
           		</table>
            </div>
            </div>
			<div class="card-footer d-flex align-items-center">
				<b>Total:</b> &nbsp;<?=$i;?> Wordpress websites.
			</div>
        </div>




		<form method="post" action="/" id="wp-install" class="needs-validation" novalidate="" style="padding-left:14px;margin-left:-14px;">
		<input type="hidden" name="action" value="wp-install">
		<div class="modal modal-blur fade" id="modal-wp-install" tabindex="-1" role="dialog" aria-hidden="true">
      	<div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Install Wordpress</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
		  	<p>You can install Wordpress in just few seconds. Database is created automatically.</p>

			<div class="mb-3">
				<div class="form-label">Select doimain:</div>
				<select class="form-select" name="domain" id="domain" required>
				<?
					$results = $db->query('SELECT * FROM accounts ORDER BY domain');
					while ($row = $results->fetchArray()) {
				?>						
					<option value="<?=$row["domain"];?>"><?=$row["domain"];?></option>
				<? 	} ?>
				</select>
				<div id="invalid-domain" class="invalid-feedback">
					Please select another domain.
				</div>
				<span class="form-check-description">Note: Please select a domain that has an empty public_html directory.</span>
				<br/>
				<div class="row">
					<div class="col-lg-12">
						<label class="form-label">Site title:</label>
						<input type="text" name="title" value="Default Wordpress Site" class="form-control" placeholder="Default Wordpress Site" required><br/>
						<div id="invalid-title" class="invalid-feedback">
							Please choose a site title.
						</div>
					</div>
				</div>
				<div class="row">
					<div class="col-lg-6">
						<label class="form-label">User:</label>
						<input type="text" name="user" id="user" value="" class="form-control" placeholder="admin" required  pattern="[a-z][a-z0-9]{1,11}" maxlength="12" autocomplete="off">
						<div class="invalid-feedback">
							Please choose a username.
						</div>
						<br />
					</div>
				</div>
				<div class="row">
					<div class="col-lg-6">
						<label class="form-label">Password:</label>
						<input type="text" class="form-control" name="password" id="password" autocomplete="off" aria-describedby="passwordHelpBlock" required pattern="[^ ]{8,24}" maxlength="24">
						<div class="pwstrength_viewport_progress"></div>
						<div class="invalid-feedback">
							<br>
							Please enter a password.
						</div>
					</div>
					<div class="col-lg-6">
						<div class="mb-3 top27">
							<input type="button" value="Generate" class="btn btn-white" onClick="$('#password').val(genPass()).pwstrength('forceUpdate');">
							<input type="button" value="Hide password" class="btn btn-white" onClick="if($(this).val()=='Hide password') { $('#password').attr('type', 'password');$(this).val('Show password'); } else {$('#password').attr('type', 'text');$(this).val('Hide password'); }">
						</div>
					</div>
					<small id="passwordHelpBlock" class="form-text text-muted" style="display:block;">
							Your password must be 8-24 characters long, contain letters and numbers, and must not contain spaces.
					</small>
				</div>
				<br/>
				<div class="row">
					<div class="col-lg-12">
						<label class="form-label">Email:</label>
        	        	<input type="text" class="form-control" name="email" id="email" placeholder="user@example.com" autocomplete="off" required pattern="[A-Za-z0-9\+\-_\.]{1,32}@[a-z0-9\-\.]{2,32}\.[a-z]{2,10}.*" maxlength="256">
					</div>
				</div>
			</div>
		</div>
		<div class="modal-footer">
            <a href="#" class="btn btn-link link-secondary" data-bs-dismiss="modal">
              Cancel
            </a>
			<input type="submit" id="submit-btn" class="btn btn-primary" value="Install Wordpress" />
          </div>
        </div>
      	</div>
    	</div>
		</form>

<? /*		<form method="post" action="/" id="wp-scan" class="needs-validation" novalidate="" style="padding-left:14px;margin-left:-14px;"> */ ?>
		<div class="modal modal-blur fade" id="modal-wp-scan" tabindex="-1" role="dialog" aria-hidden="true">
      	<div class="modal-dialog modal-lg" role="document" style="min-width:90%">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Wordfence Scan</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
		  	<p>Below is the scan done with <a href="https://github.com/wordfence/wordfence-cli" target="_blank">wordfence-cli</a>, output from console:</p>
			<div id="console" style="background:#222;color:#ccc;padding:10px 20px;height:500px;width:auto;font-family: 'Roboto Mono', monospace, monospace;overflow:auto;white-space: pre;"></div>
		  </div>
		  <div class="modal-footer">
			<input type="submit" id="submit-btn2" class="btn btn-primary" data-bs-dismiss="modal" value="Close" />
          </div>
	  	</div>
    	</div>
<? /*		</form> */ ?>

	<?php
    include('templates/footer.php'); 
?>
<script>
jQuery(document).ready(function () {
	'use strict';
	$('.alert-warning').delay(5000).fadeOut(2000);
	$('.alert-success').delay(5000).fadeOut(2000);
	$('#wp-install').on('submit', function (event) {
		event.preventDefault();
		event.stopPropagation();
		var button = event.relatedTarget;
		jQuery.ajax({
			method: "POST",
			url: "./ajax-wp-install/",
			data: { action: 'ajax-wp-install', domain: $('#domain').val() }
			}).done(function( msg ) {
			if(msg != 'OK') {
				$('#invalid-domain').html(msg);
				$('#domain').addClass('is-invalid');
				$('#domain').removeClass('was-validated');
				$("#create-account").removeClass('was-validated');
		//		console.log(msg);
			} else {
				$('#invalid-domain').html('');
				$('#domain').removeClass('is-invalid');
				$('#domain').addClass('was-validated');
		//		console.log('domain ok');
				
				$("#wp-install").addClass('was-validated');
				if($('#wp-install').is(':valid')) {
					$('#submit-btn').prop('value', 'Installing Wordpress ...');
					$('#submit-btn').prop('disabled', true);
					$('#wp-install').unbind('submit').submit();
				}
			}
		});
	});

	var ws;
	var wsopen = false;
	var scanning = false;

	function showstate(state) {
		switch(state) {
			case 0:
				return 'CONNECTING'; break;
			case 1:
				return 'OPEN'; break;
			case 2:
				return 'CLOSING'; break;
			case 3:
				return 'CLOSED'; break;
			default:
				return 'UNKNOWN '+state;
		}
	}

	function escapeHtml(unsafe) {		
  		return unsafe
    	.replaceAll("&", "&amp;")
	    .replaceAll("<", "&lt;")
    	.replaceAll(">", "&gt;")
	    .replaceAll('"', "&quot;")
    	.replaceAll("'", "&#039;");
	};

	// show progress. if process is running on server, open websocket
	jQuery.ajax({
		method: "POST",
		url: "./ajax-wp-scan/",
		data: { action: 'ajax-wp-scan', user: '' }
	}).done(function( msg ) {
		console.log('RESPONSE ajax-wp-scan '+msg);
		console.log(msg.trim().substring(0, 27));
		if(msg.trim().substring(0, 27) == '*** resuming connection ***') {
			scanning = true;
			var user = msg.trim().substring(28);
			console.log('show progress // user: '+user);
			//$('#progress').show().html('Scanning in progress, <a href="javascript:" onClick="$(\'#modal-wp-scan\').modal(\'toggle\');">click here to view details</a>.');
			//$('.btn-white').addClass("disabled");
			//$('.btn-white').attr("disabled", "disabled");

			ws = new WebSocket('wss://<?=$_SERVER['SERVER_NAME'];?>:2087/websocket/');
			ws.onerror = function() {
				console.log('Error opening websocket');
				wsopen = false;
			}

			ws.onopen = function() {
				wsopen = true;
				//$('#console').append('<br/>'+msg);
				$('#console').append('<font color=green>CONNECTED TO SERVER, WAIT FOR OUTPUT</font>');
				console.log('OPEN WEBSOCKET // wsopen: ' + wsopen + ' state: ' + showstate(ws.readyState));
				//console.log(ws.readyState);
				$('#progress').show().html('Scanning user <b>'+user+'</b> in progress, <a href="javascript:" onClick="$(\'#modal-wp-scan\').modal(\'toggle\');">click here to view details</a>.');
				$('.btn-white').addClass("disabled");
				$('.btn-white').attr("disabled", "disabled");
			}

			ws.onmessage = function(event) {
				$('#console').append('<br/>'+escapeHtml(event.data));
				$('#console').scrollTop($('#console').prop("scrollHeight"));
			};

			ws.onclose = function() {
				if(wsopen) {
					$('#console').append('<br/><font color=green>DISCONNECTED</font><br />');
					wsopen = false;
				}
				$('#console').scrollTop($('#console').prop("scrollHeight"));
				console.log('CLOSING WEBSOCKET // scanning: ' + scanning + ' wsopen: ' + wsopen + ' state: ' + showstate(ws.readyState));
				$('#progress').show().html('Scanning user <b>'+user+'</b> completed. <a href="javascript:" onClick="$(\'#modal-wp-scan\').modal(\'toggle\');">Click here to view details</a>.');
				$('.btn-white').removeClass("disabled");
				$('.btn-white').removeAttr("disabled");
				//setInterval(function() {$('#modal-wp-scan').modal("hide") }.bind(this), 5000);
				//console.log(ws.readyState);
			};
		}
	});


	$('#modal-wp-scan').on('show.bs.modal', function (event) {
//		event.preventDefault();
//		event.stopPropagation();
		console.log('show // scanning: '+scanning+' wsopen: '+wsopen);
		var button = event.relatedTarget;
		var user = '';
		if(button) {
			user = button.getAttribute('data-bs-user');
		}
		$('#console').scrollTop($('#console').prop("scrollHeight"));

		if(scanning && !wsopen && button) {
			scanning = false;
		}

		if(!scanning) {
			scanning = true;
			$('#console').html('');
			console.log('POST ajax-wp-scan '+user);
			jQuery.ajax({
				method: "POST",
				url: "./ajax-wp-scan/",
				data: { action: 'ajax-wp-scan', user: user }
			}).done(function( msg ) {
				console.log('RESPONSE ajax-wp-scan '+msg);
				//$('#console').append(msg);

				if(!wsopen) {
					ws = new WebSocket('wss://<?=$_SERVER['SERVER_NAME'];?>:2087/websocket/');
					ws.onerror = function() {
						console.log('Error opening websocket');
						wsopen = false;
					}

					ws.onopen = function() {
						wsopen = true;
						//scanning = true;
						//$('#console').append('<br/>'+msg);
						$('#console').append('<font color=green>CONNECTED TO SERVER, WAITING FOR OUTPUT</font>');
						console.log('OPEN WEBSOCKET // wsopen: ' + wsopen + ' state: ' + showstate(ws.readyState));
						//console.log(ws.readyState);
						$('#progress').show().html('Scanning user <b>'+user+'</b> in progress, <a href="javascript:" onClick="$(\'#modal-wp-scan\').modal(\'toggle\');">click here to view details</a>.');
						$('.btn-white').addClass("disabled");
						$('.btn-white').attr("disabled", "disabled");
					}

					ws.onmessage = function(event) {
						$('#console').append('<br/>'+escapeHtml(event.data));
						$('#console').scrollTop($('#console').prop("scrollHeight"));
					};

					ws.onclose = function() {
						if(wsopen) {
							$('#console').append('<br/><font color=green>DISCONNECTED</font><br />');
							wsopen = false;
						}
						$('#console').scrollTop($('#console').prop("scrollHeight"));
						console.log('CLOSING WEBSOCKET // scanning: ' + scanning + ' wsopen: ' + wsopen + ' state: ' + showstate(ws.readyState));
						$('#progress').show().html('Scanning user <b>'+user+'</b> completed. <a href="javascript:" onClick="$(\'#modal-wp-scan\').modal(\'toggle\');">Click here to view details</a>.');
						$('.btn-white').removeClass("disabled");
						$('.btn-white').removeAttr("disabled");
						//setInterval(function() {$('#modal-wp-scan').modal("hide") }.bind(this), 5000);
						//console.log(ws.readyState);
						//scanning = false;
					};
				}
			}).fail(function() {
				//scanning = false;
    			console.log("ajax error");
  			});
		}
	});

//	$('.scan').on('click', function (event) {
//		console.log('scan button clicked');
//		scanning = false;
//		//$('#console').html('');
//	});

<? /*	
	$('#modal-wp-scan').on('hide.bs.modal', function (event) {
		console.log('hide // scanning: '+scanning+' wsopen: '+wsopen);
		if(scanning) {
			event.preventDefault();
			event.stopPropagation();
			$('#console').append('<br /><span style="color:#fff">*** closing connection ***</span>');
			$('#console').scrollTop($('#console').prop("scrollHeight"));
//			if(ws.readyState===WebSocket.CONNECTNG || ws.readyState===WebSocket.OPEN) {
//				ws.close();
				//setInterval(function() {
				//	scanning = false;
				//	console.log('hide timeout');
				//	$('#modal-wp-scan').hide(); 
				//	$('.modal-backdrop').hide(); 
				//}.bind(this), 3000);
				setTimeout( function () {
//					scanning = false;
//					console.log('hide timeout');
//					$('.modal').modal('hide');
					$(this).modal('hide');
					$('.modal-backdrop').hide();
				}, 3000);
//			}
//			wsopen = false;
			scanning = false;
		}
	});
*/ ?>	

<? /*	
//		$('#console').append('<br>Processing file: /home/wptest/public_html/wp-content/themes/twentytwentyfour/templates/inde.html');
//		$('#console').append('<br>Processing file: /home/wptest/public_html/wp-content/themes/twentytwentyfour/templates.html');
		if(wsopen == false) {
			ws = new WebSocket('wss://<?=$_SERVER['SERVER_NAME'];?>:2087/websocket/');
			wsopen = true
		}
	
		ws.onopen = function() {
			$('#console').html('<span style="color:#fff"># wordfence malware-scan /home/wp/public_html/</span>');
			$('#console').append('<br/>CONNECT');
			ws.send("/usr/local/bin/wordfence malware-scan --no-color --no-banner --verbose /home/wp/public_html/ 2>&1 | websocat -s 2122");
			//ws.send("/usr/bin/sudo /usr/local/bin/wordfence malware-scan --no-color --no-banner --verbose /home/wptest/public_html/ 2>&1");
			// ws.send("/usr/bin/sudo /usr/local/bin/wordfence vuln-scan --no-color --no-banner -v --output-format csv /home/wp/public_html/ 2>&1");
			// shell_exec("/usr/local/reqad/scripts/wordfence_vuln.sh > /usr/local/reqad/wordfence.log 2>&1");
			console.log(ws.readyState);
			//wp.send("/usr/local/reqad/scripts/wordfence_vuln.sh > /usr/local/reqad/wordfence.log 2>&1");
			//ws.send("/usr/local/reqad/scripts/wordfence_vuln.sh");
			//ws.send("tail -f /usr/local/reqad/log/wordfence.log");
			//ws.send("/usr/bin/sudo find /home/wp/ 2>&1");
			//ws.send("/usr/bin/sudo journalctl -f");
			//ws.send("/usr/bin/sudo /usr/local/bin/wordfence vuln-scan --no-color -v --output-format csv /home/wp/public_html/ 2>&1");
		};
		ws.onclose = function() {
			$('#console').append('</br>CONNETION LOST / DISCONNECTED');
			$('#console').scrollTop($('#console').prop("scrollHeight"));
			//setInterval(function() {$('#modal-wp-scan').modal("hide") }.bind(this), 5000);
			console.log(ws.readyState);
		};
		ws.onmessage = function(event) {
			$('#console').append('<br/>'+event.data);
			$('#console').scrollTop($('#console').prop("scrollHeight"));
		};
	});

	$('#wp-scan').on('submit', function (event) {
		console.log('submit');
		event.preventDefault();
		event.stopPropagation();
//		ws.send("^C ^C ^C ^C close");
		$('#console').append('<br /><span style="color:#fff">*** closing connection ***</span>');
		ws.close();
		$('#submit-btn2').prop('value', 'Stopping ...');
		$('#submit-btn2').prop('disabled', true);
		$('#submit-btn2').prop('value', 'Stop and close');
		$('#submit-btn2').prop('disabled', false);
		setInterval(function() {
			$('#modal-wp-scan').modal("hide"); 
		}.bind(this), 2000);
	});

	$('#wp-scan').on('hide.bs.modal', function (event) {
		console.log('hide');
		//event.preventDefault();
		//event.stopPropagation();
		$('#console').append('<br /><span style="color:#fff">*** closing connection ***</span>');
		if(ws.readyState===WebSocket.CONNECTNG || ws.readyState===WebSocket.OPEN) {
			ws.close();
		}
		//$('#submit-btn2').prop('value', 'Stopping ...');
		//$('#submit-btn2').prop('disabled', true);
		//setInterval(function() {
		//	$('#modal-wp-scan').hide(); 
		//	$('#wp-scan').hide(); 
		//}.bind(this), 2000);
	});
*/ ?>
});
</script>
</>
</html>
