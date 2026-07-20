<?php
	$settings = array();
	$results = $db->query('SELECT name,value FROM settings');
	while ($row = $results->fetchArray()) {
    	#echo $row["name"].' = '.$row["value"].'<br>';
	    $settings_name = $row["name"];
    	$settings[$settings_name] = $row["value"];
	}
	#print_r($settings); exit;

	$zones = array();
	if($settings["dns-provider"]=='cpanel') {
		require_once(__DIR__.'/../modules/api_cpanel.php');
	} else if($settings["dns-provider"]=='cloudflare') {
		require_once(__DIR__.'/../modules/api_cloudflare.php');
		#$zones = get_zones();
		#$errmsg = 'DNS zone management is not implemented for current DNS provider (Cloudflare).';
	} else if($settings["dns-provider"]=='powerdns') {
		require_once(__DIR__.'/../modules/api_powerdns.php');
		#$errmsg = 'DNS zone management is not implemented for current DNS provider (PowerDNS).';
	} else {
		require_once(__DIR__.'/../modules/api_none.php');
		$errmsg = 'Current DNS provider: none. Please select the DNS provider from <a href="/dns-settings/" class="text-danger" style="text-decoration:underline">DNS Settings</a>.';
	}

	if(!isset($errmsg))
		$errmsg = '';

    include('templates/header.php'); 
?>
        <!-- Page title -->
        <div class="page-header d-print-none">
            <div class="row align-items-center">
            	<div class="col" style="padding-left:22px;">
					<!-- Page pre-title -->
					<div class="page-pretitle">
						DNS
					</div>
					<h2 class="page-title">
						List Zones on <?=$dns_provider_name;?>
					</h2>
              	</div>
              	<div class="col-auto ms-auto d-print-none">
	                <div class="btn-list">
<? if($settings["dns-provider"]=='powerdns' && ($settings["powerdns-mode"] ?? '')=='hidden-master' && !empty($settings["powerdns-agent-url"])) { ?>
                  		<a href="#" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal-sync-local" title="Pull all zones from the cPanel DNS agent into local PowerDNS">
                    		<svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><path d="M4 12v-3a3 3 0 0 1 3 -3h13m-3 -3l3 3l-3 3"></path><path d="M20 12v3a3 3 0 0 1 -3 3h-13m3 3l-3 -3l3 -3"></path></svg>
	                    	Sync to local
                  		</a>
<? } ?>
                  		<a href="#" class="btn btn-primary disabled" data-bs-toggle="modal" data-bs-target="#modal-create-account">
                    		<svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
	                    	Add a new DNS Zone
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
				 <div class="text-danger"><?=str_replace('Error: ', '', $errmsg);?></div>
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
                <div class="table-responsive">
                  <table class="table table-vcenter table-mobile-md card-table">
                    <thead>
                      <tr>
                        <th style="background-color:#DEF;">ID</th>
                        <th style="background-color:#DEF;">Domain</th>
                        <th style="background-color:#DEF;">Serial</th>
<? if($settings["dns-provider"]=='cloudflare') { ?>
                        <th style="background-color:#DEF;">Nameservers</th>
<? } ?>
                        <th class="w-1" style="background-color:#DEF;"></th>
                      </tr>
                    </thead>
                    <tbody>
                    <?
						$i=0;
						$zones = array(); 
						$zones = get_zones();
						#echo '<pre>'; print_r($zones); exit;

						foreach($zones as $domain => $z) {
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
                              <div class="font-weight-medium"><?=$domain;?></div>
                            </div>
                          </div>
                        </td>

                        <td data-label="Domain">
                          <div class="d-flex py-1 align-items-center">
                            <div class="flex-fill">
							  <div class="font-weight-medium" id="serial_<?=str_replace('.', '_', $domain);?>" style="min-width:200px;">
								<? if(isset($z['serial']) && $z['serial']!='') { 
										echo $z['serial'];
									} else { ?>
								<div style="width:200px;height:10px;" class="loading"></div>
								<? } ?>
							  </div>
                            </div>
                          </div>
                        </td>
<? if($settings["dns-provider"]=='cloudflare') { ?>
                        <td data-label="Nameservers">
                          <div class="d-flex py-1 align-items-center">
                            <div class="flex-fill">
							  <div class="font-weight-medium" style="min-width:200px;">
								<? 
									if(isset($z['nameservers']) && $z['nameservers']!='') { 
										echo $z['nameservers'];
									} else { 
										echo '-';
									}
								?>
							  </div>
                            </div>
                          </div>
                        </td>
<? } ?>						
                        <td>
                          <div class="btn-list flex-nowrap">
<? /*							
                            <a href="#" class="btn btn-white btn-md" data-bs-toggle="modal" data-bs-target="#modal-edit-account" data-bs-user="<?=$z["zone_"];?>" data-bs-domain="<?=$row["domain"];?>" <? if(isset($ini["quota"]) && (int)($ini["quota"])>0) { ?>data-bs-disk-quota="<?=$row["disk_quota"];?>"<? } ?>>Edit</a>
                            <a href="#" class="btn btn-white btn-md" data-bs-toggle="modal" data-bs-target="#modal-delete-account" data-bs-user="<?=$row["user"];?>">Delete</a>
	*/ ?>
                            <a href="#" class="btn btn-white btn-md" data-bs-toggle="modal" data-bs-target="#modal-view-zone" data-bs-zone="<?=$domain;?>">View zone</a>
                            <a href="#" class="btn btn-white disabled" data-bs-toggle="modal" data-bs-target="#modal-delete-zone" data-bs-user="<?=$domain;?>">Delete</a>
                          </div>
                        </td>
                      </tr>
                      <? } ?>
                    </tbody>
                  </table>
                </div>
<? /*				
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

            </div>
          </div>
        </div>
      </div>
    </div>

<? /*	
	<form method="post" action="/" id="create-account" class="needs-validation" novalidate>
    <input type="hidden" name="action" value="create-account">
    <div class="modal modal-blur fade" id="modal-create-account" tabindex="-1" role="dialog" aria-hidden="true">
      <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Create a new account</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <p>To create a new account, you need a domain name that will point to this server. Ensure that the domain name is registered and has the correct nameservers.</p>
            <div class="mb-3">
              <label class="form-label">Domain Name</label>
              <input type="text" class="form-control" name="domain" id="domain" placeholder="example.com" required pattern="[a-z0-9]+[a-z0-9\-\.]*[a-z0-9]+\.[a-z]{2,}" maxlength="32" autocomplete="off">
              <div class="invalid-feedback" id="invalid-domain">
                Please enter a domain name.
              </div>
            </div>
            <div class="mb-3">
              <label class="form-label">Username</label>
              <input type="text" class="form-control" name="user" id="user" placeholder="user1" aria-describedby="userHelpBlock" required pattern="[a-z]+[a-z0-9]{1,7}" maxlength="8" autocomplete="off">
              <small id="userHelpBlock" class="form-text text-muted" style="display:block;margin-top:8px;">
                Username must be unique, 2-8 characters long, contain letters and numbers, and must not contain spaces.
              </small>
              <div class="invalid-feedback" id="invalid-user">
                Please enter a username.
              </div>
            </div>
			<? if(isset($ini["quota"]) && (int)($ini["quota"])>0) { ?>
            <div class="mb-3">
              <label class="form-label">Disk Space Quota</label>
              <input name="disk_quota" type="range" class="form-range mb-2" value="1024" min="0" max="10240" step="256" oninput="if(this.value==0) { this.nextElementSibling.value = 'disabled'; } else { this.nextElementSibling.value = this.value + ' MB'; }" style="width:80%;align:left;margin-right:20px;" /><output>1024 MB</output></input>
              <small id="userHelpBlock" class="form-text text-muted" style="display:block;margin-top:8px;">
                Disk space quota is optional, you can set it to zero to disable account level quota.
              </small>
            </div>
			<? } ?>
            <div class="row">
              <div class="col-lg-6">
                <div class="mb-3" id="pwd-container">
                  <label class="form-label">Password</label>
                  <input type="text" class="form-control" name="password" id="password" autocomplete="off" aria-describedby="passwordHelpBlock" required pattern="[^ ]{8,24}" maxlength="24">
                  <div class="pwstrength_viewport_progress"></div>
                </div>
              </div>
              <div class="col-lg-6">
                <div class="mb-3 top27">
                <input type="button" value="Generate" class="btn btn-white" onClick="$('#password').val(genPass()).pwstrength('forceUpdate');">
                <input type="button" value="Hide password" class="btn btn-white" onClick="if($(this).val()=='Hide password') { $('#password').attr('type', 'password');$(this).val('Show password'); } else {$('#password').attr('type', 'text');$(this).val('Hide password'); }">
                </div>
              </div>
              <small id="passwordHelpBlock" class="form-text text-muted" style="display:block;margin-top:-8px;">
                Your password must be 8-24 characters long, contain letters and numbers, and must not contain spaces.
              </small>
              <div class="invalid-feedback">
                Please enter a password.
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <a href="#" class="btn btn-link link-secondary" data-bs-dismiss="modal">
              Cancel
            </a>
            <button id="submit-btn" class="btn btn-primary" type="submit"><svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg> Create account</button>
          </div>
        </div>
      </div>
    </div>
</form>

<form method="post" action="/" id="edit-account" class="needs-validation" novalidate>
    <input type="hidden" name="action" value="edit-account">
    <input type="hidden" name="user" id="user-edit" value="edit-account">
    <div class="modal modal-blur fade" id="modal-edit-account" tabindex="-1" role="dialog" aria-hidden="true">
      <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Edit account <span id="user-title"></span></h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <p>You can edit the account, but you can't change the username.</p>
            <div class="mb-3">
              <label class="form-label">Domain Name</label>
              <input type="text" class="form-control" name="domain" id="domain-edit" placeholder="example.com" required pattern="[a-z0-9]+[a-z0-9\-\.]*[a-z0-9]+\.[a-z]{2,}" maxlength="32" autocomplete="off">
              <div class="invalid-feedback" id="invalid-domain">
                Please enter a domain name.
              </div>
            </div>
			<? if(isset($ini["quota"]) && (int)($ini["quota"])>0) { ?>
            <div class="mb-3">
              <label class="form-label">Disk Space Quota</label>
              <input name="disk_quota" id="diskquota-edit" type="range" class="form-range mb-2" value="1024" min="0" max="<?=$ini["quota"];?>" step="256" oninput="if(this.value==0) { this.nextElementSibling.value = 'disabled'; } else { this.nextElementSibling.value = this.value + ' MB'; }" style="width:80%;align:left;margin-right:20px;" /><output><?=$ini["quota"];?> MB</output></input>
              <small id="userHelpBlock" class="form-text text-muted" style="display:block;margin-top:8px;">
                Disk space quota is optional, you can set it to zero to disable account level quota.
              </small>
            </div>
			<? } ?>
            <div class="row">
              <div class="col-lg-6">
                <div class="mb-3" id="pwd-container2">
                  <label class="form-label">Change Password</label>
                  <input type="text" class="form-control" name="password" id="password2" autocomplete="off" aria-describedby="passwordHelpBlock" required pattern="[^ ]{8,24}" maxlength="24">
                  <div class="pwstrength_viewport_progress"></div>
                </div>
              </div>
              <div class="col-lg-6">
                <div class="mb-3 top27">
                <input type="button" value="Generate" class="btn btn-white" onClick="$('#password2').val(genPass()).pwstrength('forceUpdate');">
                <input type="button" value="Hide password" class="btn btn-white" onClick="if($(this).val()=='Hide password') { $('#password2').attr('type', 'password');$(this).val('Show password'); } else {$('#password2').attr('type', 'text');$(this).val('Hide password'); }">
                </div>
              </div>
              <small id="passwordHelpBlock" class="form-text text-muted" style="display:block;margin-top:-8px;">
                Enter a new password only if you want to change the existing one.
                Your password must be 8-24 characters long, contain letters and numbers, and must not contain spaces.
              </small>
              <div class="invalid-feedback">
                Please enter a password.
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <a href="#" class="btn btn-link link-secondary" data-bs-dismiss="modal">
              Cancel
            </a>
            <button id="submit-btn" class="btn btn-primary" type="submit">Save changes</button>
          </div>
        </div>
      </div>
    </div>
</form>

<form method="post" action="/" id="delete-account" class="needs-validation" novalidate>
    <input type="hidden" name="action" value="delete-account">
    <input type="hidden" name="user" id="user-delete" value="">
    <div class="modal modal-blur fade" id="modal-delete-account" tabindex="-1" role="dialog" aria-hidden="true">
      <div class="modal-dialog modal-sm modal-dialog-centered" role="document">
        <div class="modal-content">
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          <div class="modal-status bg-danger"></div>
          <div class="modal-body text-center py-4">
			<svg xmlns="http://www.w3.org/2000/svg" class="icon mb-2 text-danger icon-lg" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="#ff2825" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="12" cy="12" r="9" /><line x1="12" y1="8" x2="12" y2="12" /><line x1="12" y1="16" x2="12.01" y2="16" /></svg>
            <h3>Delete account <span id="user-title2"></span></h3>
            <div class="text-muted">Do you really want to remove this account?</div>
          </div>
          <div class="modal-footer">
            <div class="w-100">
              <div class="row">
                <div class="col"><a href="#" class="btn btn-white w-100" data-bs-dismiss="modal">
                    Cancel
                  </a></div>
                <div class="col"><button id="submit-btn" class="btn btn-primary" type="submit">
					Delete account
						  </button></div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
</form>	

*/ ?>

<form action="#" id="view-zone" class="needs-validation" novalidate>
    <div class="modal modal-blur fade" id="modal-view-zone" tabindex="-1" role="dialog" aria-hidden="true">
      <div class="modal-dialog modal-sm modal-dialog-centered" role="document" style="min-width:1200px">
        <div class="modal-content">
		  <div class="modal-header">
            <h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">View zone <span id="zone-title"></span> on <?=$dns_provider_name;?></h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body" id="view-zone-body">
          </div>
          <div class="modal-footer">
            <div class="w-100">
              <div class="row">
			  	<div class="col"><a href="#" class="btn btn-whit" data-bs-dismiss="modal">Close</a></div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
</form>	

<? if($settings["dns-provider"]=='powerdns' && ($settings["powerdns-mode"] ?? '')=='hidden-master' && !empty($settings["powerdns-agent-url"])) { ?>
<div class="modal modal-blur fade" id="modal-sync-local" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document" style="min-width:700px">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Sync zones to local PowerDNS</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p id="sync-local-intro">This pulls every DNS zone from the cPanel agent and imports it into local PowerDNS:
        zones that are missing locally are created, zones that already exist are updated to match.
        SOA and NS records are kept local. This does not change anything on the cPanel server.</p>
        <pre id="sync-local-result" style="display:none;max-height:340px;overflow:auto;background:#F6F8FB;border:1px solid #DEF;border-radius:6px;padding:12px;margin:0;white-space:pre-wrap;"></pre>
      </div>
      <div class="modal-footer">
        <a href="#" class="btn btn-link link-secondary" data-bs-dismiss="modal" id="sync-local-close">Cancel</a>
        <button class="btn btn-primary" id="sync-local-start" type="button">
          <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><path d="M4 12v-3a3 3 0 0 1 3 -3h13m-3 -3l3 3l-3 3"></path><path d="M20 12v3a3 3 0 0 1 -3 3h-13m3 3l-3 -3l3 -3"></path></svg>
          Start sync
        </button>
      </div>
    </div>
  </div>
</div>
<? } ?>

<?php
    include('templates/footer.php');
?>
<script>
jQuery(document).ready(function () {
	'use strict';

	$('#sync-local-start').on('click', function () {
		var btn = $(this);
		btn.prop('disabled', true).html('Syncing&hellip;');
		$('#sync-local-intro').hide();
		$('#sync-local-result').show().text('Contacting cPanel agent, please wait…');
		jQuery.ajax({
			method: "POST",
			url: "./ajax-dns-sync-local/",
			data: { action: 'ajax-dns-sync-local' }
		}).done(function (msg) {
			$('#sync-local-result').text(msg && msg.length ? msg : 'Done.');
			btn.hide();
			$('#sync-local-close').text('Close').removeClass('btn-link link-secondary').addClass('btn-primary').attr('data-bs-dismiss', '').on('click', function (e) {
				e.preventDefault();
				location.reload();
			});
		}).fail(function (xhr) {
			$('#sync-local-result').text('Request failed (' + xhr.status + ').');
			btn.prop('disabled', false).text('Retry');
		});
	});
<?	
	$i=0;
	foreach($zones as $domain => $z) {
		if(!isset($z['serial']) || ($z['serial']=='')) {
?>
	jQuery.ajax({
		method: "POST",
		url: "./ajax-dns-serial/",
		data: { action: 'ajax-dns-serial', domain: '<?=$domain;?>' }
	}).done(function( msg ) {
		//console.log(msg);
		$('#serial_<?=str_replace('.','_',$domain);?>').html(msg);
  	});
<? 	}} ?>	

	var run_once = false;
	$('#view-zone').on('show.bs.modal', function (event) {
		if(run_once == false) {
			run_once = true;
			//console.log(run_once);
			//console.log(event);
			// Button that triggered the modal
			var button = event.relatedTarget;
			// Extract info from data-bs-* attributes
			var domain = button.getAttribute('data-bs-zone');
			$('#zone-title').html(domain);
			$('#view-zone-body').html('<div class="loading" style="padding:10px;">Loading...</div>');
			jQuery.ajax({
				method: "POST",
				url: "./ajax-dns-zone/",
				data: { action: 'ajax-dns-zone', domain: domain }
			}).done(function( msg ) {
				$('#view-zone-body').html(msg);
				run_once = false;
			});
		}
  	});
	//$(':checkbox').on('change', function (event) {
	//$('.proxied').change(function() {
	//	console.log('changed!');
	//});

	$('#proxied_0').change(function() {
		console.log('changed2!');
	});

	
});
function changeProxy(id) {
	obj = $('#'+id);
	$('#label_'+id).html('sending...');
//	console.log(id);
//	console.log(obj.data("domain"));
//	console.log(obj.data("name"));
//	console.log(obj.data("type"));
//	console.log(obj.is(":checked"));

	jQuery.ajax({
		method: "POST",
		url: "./ajax-dns-proxy/",
		data: { action: 'ajax-dns-proxy', domain: obj.data("domain"), name: obj.data("name"), type: obj.data("type"), proxied: obj.is(":checked")}
	}).done(function( msg ) {
		$('#label_'+id).html(msg);
		obj.blur()
	});
}
</script>
</body>
</html>