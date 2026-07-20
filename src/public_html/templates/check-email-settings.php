<?php
	$domains = array();
	$domains1 = array();
	$domains2 = array();

	$output = shell_exec('sudo cat /etc/exim/userdomains | sort | awk -F\':\' {\'print $1 " " $6\'}');
	if(trim($output)!='')
   		$domains1 = array_map('trim', explode("\n", trim($output)));
	

	$output = shell_exec('sudo ls -1 /etc/exim/domains/ | sort');
	if(trim($output)!='')
		$domains2 = array_map('trim', explode("\n", trim($output)));

	$results = $db->query('SELECT * FROM accounts WHERE has_email=true ORDER BY domain');
	while ($row = $results->fetchArray()) {
		$user 	= $row['user'];
		$domain = $row['domain'];
		$domains[] = $domain;
		if(!in_array($domain, $domains1)) {
			shell_exec('echo \''.$domain.':'.$user.'\' | sudo tee --append /etc/exim/userdomains');
			shell_exec('sudo chown exim:exim /etc/exim/userdomains');
			error_log("$domain $user added to /etc/exim/userdomains ".__FILE__."\n",  3, __DIR__.'/../../log/debug_log');
		}
		if(!in_array($domain, $domains2)) {
			shell_exec('sudo touch /etc/exim/domains/'.$domain);
			shell_exec('sudo chown exim:exim /etc/exim/domains/'.$domain);
			error_log("create file /etc/exim/domains/".$domain." ".__FILE__."\n",  3, __DIR__.'/../../log/debug_log');
		}
	}
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
                  Check Email Settings
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
                  <table class="table table-vcenter card-table table-responsive"">
                    <thead>
                      <tr>
                        <th class="w-1" style="background-color:#DEF;">ID</th>
                        <th class="w-10" style="background-color:#DEF;">DOMAIN <svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' width='16' height='16'><path fill='none' stroke='currentColor' stroke-linecap='round' stroke-linejoin='round' stroke-width='1' d='M5 10l3 -3l3 3'/></svg></th>
                        <th style="background-color:#DEF;">NS</th>
                        <th style="background-color:#DEF;">SYNC</th>
                        <th style="background-color:#DEF;">MX</th>
                        <th style="background-color:#DEF;">SPF</th>
                        <th style="background-color:#DEF;">DKIM</th>
                        <th style="background-color:#DEF;">DMARC</th>
                        <th class="w-5" style="background-color:#DEF;"></th>
                      </tr>
                    </thead>
                    <tbody>
                   <?
                      	$i = 0;
						foreach($domains as $domain) {
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
                              <div class="font-weight-medium" style="text-wrap: nowrap;"><?=$domain;?></div>
                            </div>
                          </div>
                        </td>
                        <td data-label="NS">
                          <div class="d-flex py-1 align-items-center">
                            <div class="flex-fill">
                              <div class="font-weight-medium" style="text-wrap: nowrap;">
								<?
									$ns_raw = trim(shell_exec("dig +short NS $domain +time=3 +tries=1 2>/dev/null"));
									// NS values come from an untrusted authoritative server and are used
									// unquoted in the `dig @<ns>` calls below, so drop anything that is not
									// a well-formed hostname (no shell metacharacters can survive this).
									$ns_all = array_values(array_filter(array_map(function($l){
										$h = strtolower(rtrim(trim($l), '.'));
										return preg_match('/^[a-z0-9]([a-z0-9\-\.]*[a-z0-9])?$/', $h) ? $h : '';
									}, explode("\n", $ns_raw))));
									$nameserver = '';
									if (!empty($ns_all)) {
										$nameserver = '@' . $ns_all[0];
										echo htmlspecialchars($ns_all[0]);
									}
									// Query SOA from each NS to check sync
									$soa_serials = [];
									$soa_missing = [];
									foreach ($ns_all as $_ns) {
										$_soa = trim(shell_exec("dig @$_ns +short SOA $domain +time=3 +tries=1 2>/dev/null"));
										if ($_soa !== '') {
											$_parts = preg_split('/\s+/', $_soa);
											if (isset($_parts[2])) $soa_serials[$_ns] = $_parts[2];
											else $soa_missing[] = $_ns;
										} else {
											$soa_missing[] = $_ns;
										}
									}
								?>
							  </div>
                            </div>
                          </div>
                        </td>
                        <td data-label="SYNC">
                          <div class="d-flex py-1 align-items-center">
                            <div class="flex-fill">
                              <div class="font-weight-medium">
								<?
									if (empty($ns_all)) {
										echo '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#888" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 12l14 0" /></svg>';
									} elseif (count($ns_all) == 1) {
										echo '<span class="badge bg-orange" title="Only one nameserver: ' . htmlspecialchars($ns_all[0]) . '">only one NS</span>';
									} elseif (count($ns_all) < 1) {
										echo '<span class="badge bg-red" title="No nameservers found">no NS</span>';
									} elseif (!empty($soa_missing)) {
										$_detail = 'No SOA response from: ' . implode(', ', $soa_missing);
										if (!empty($soa_serials)) $_detail .= ' | OK: ' . implode(', ', array_map(fn($ns, $s) => "$ns ($s)", array_keys($soa_serials), array_values($soa_serials)));
										echo '<span class="badge bg-red" title="' . htmlspecialchars($_detail) . '">zone missing</span>';
									} else {
										$_unique = array_unique(array_values($soa_serials));
										if (count($_unique) === 1) {
											echo '<span title="SOA serial: ' . htmlspecialchars($_unique[0]) . '"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#6A6" stroke-width="1" stroke-linecap="round" stroke-linejoin="round" class="icon"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 12l5 5l10 -10"/></svg></span>';
										} else {
											$_detail = implode(' | ', array_map(fn($ns, $s) => "$ns: $s", array_keys($soa_serials), array_values($soa_serials)));
											echo '<span class="badge bg-red" title="' . htmlspecialchars($_detail) . '">out of sync</span>';
										}
									}
								?>
							  </div>
                            </div>
                          </div>
                        </td>
                        <td data-label="MX">
                          <div class="d-flex">
                            <div class="flex-fill">
                              <div class="font-weight-medium">
								<?							
									$mx_status = '';
									$output = shell_exec("dig +short MX $domain $nameserver");
									if(trim($output)=='') {
										$mx_status = 'no mx';
										echo '<span class="badge bg-red">no MX record</span>';
									} else {
										$mxs = array_map('trim', explode("\n", trim($output)));
										$mx2 = array();
										foreach($mxs as $mx) {
											list($priority,$mx) = explode(' ', $mx);
											if(substr($mx,-1,1)=='.')
												$mx = substr($mx,0,strlen($mx)-1);
											$mx2[] = array("priority" => $priority, "mx" => $mx);
										}
										sort($mx2);
										#echo '<pre>'; var_dump($mx2); exit;
										$priority=null;
										foreach($mx2 as $mx) {
											if(is_null($priority))
												$priority = $mx["priority"];
											if($mx["priority"]==$priority) {
												// check all mx records with lowest priority
												$ip = trim(shell_exec("dig +short A ".$mx["mx"]."  $nameserver"));
												if(in_array($ip, $local_ips)) {
													$mx_status = 'local email';
													echo '<span class="badge bg-lime">local mail</span> ';
												} else {
													if($mx_status=='')
														echo '<span class="badge bg-info">remote mail</span> ';
													$mx_status = 'remote email';
												}
											}
										}
									}
								?></div>
                            </div>
                          </div>
                        </td>
                        <td data-label="SPF">
                          <div class="d-flex">
                            <div class="flex-fill">
                            	<div class="font-weight-medium">
								<?	
									if($mx_status!='no mx') {
										$spf = trim(shell_exec("dig +short TXT $domain  $nameserver | grep 'v=spf1'"));
										if($spf!='') {
											echo '<span><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#6A6" stroke-width="1" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-check"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><path d="M5 12l5 5l10 -10"></path></svg></span>';
										} else {
											echo '<span class="badge bg-orange">no SPF</span>';
										}
									} else echo '<svg  xmlns="http://www.w3.org/2000/svg"  width="24"  height="24"  viewBox="0 0 24 24"  fill="none"  stroke="#888"  stroke-width="2"  stroke-linecap="round"  stroke-linejoin="round"  class="icon icon-tabler icons-tabler-outline icon-tabler-minus"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 12l14 0" /></svg>';
								?>
								</div>
                            </div>
                          </div>
                        </td>
                        <td data-label="DKIM">
                          <div class="d-flex">
                            <div class="flex-fill">
                              	<div class="font-weight-medium">
								<?
									if($mx_status!='no mx') {
										$dkim_selector = $row['dkim_selector'] ?? 'default';
									$dkim = trim(shell_exec("dig +short TXT {$dkim_selector}._domainkey.$domain $nameserver"));
										if($dkim!='') {
											if($mx_status == 'remote email') {
												echo '<span class="badge bg-success">DKIM exists in DNS</span>';
											} else {
											$output = trim(shell_exec("sudo cat /etc/exim/keys/".$domain.".public.key | sed 's/-----BEGIN PUBLIC KEY-----//' | sed 's/-----END PUBLIC KEY-----//' | tr -d '\n' && echo"));
											if($output!='')	{
												#echo $output."<br>\n";
												if(preg_match('/p=(.*);/', $dkim, $matches)) {
													$dkim_plain = str_replace('"', '', str_replace(' ', '', $matches[1]));
													if($dkim_plain == $output) {
														echo '<span><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#6A6" stroke-width="1" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-check"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><path d="M5 12l5 5l10 -10"></path></svg></span>';
													} else {
														#echo '<pre style="widthg:1000px;overflow:auto;white-space:nowrap;font-family:monospace, monospace;">';
														#echo $dkim_plain."<br>"; // file
														#echo $output."</pre><br>"; // dns
														echo '<span class="badge bg-danger">wrong DKIM</span>';
													}
												} else 
													echo '<span class="badge bg-info">cannot parse DKIM</span>';
											} else 
												echo '<span class="badge bg-danger">missing local DKIM key</span>';
											}
										} else {
											echo '<span class="badge bg-orange">no DKIM</span>';
										}
									} else echo '<svg  xmlns="http://www.w3.org/2000/svg"  width="24"  height="24"  viewBox="0 0 24 24"  fill="none"  stroke="#888"  stroke-width="2"  stroke-linecap="round"  stroke-linejoin="round"  class="icon icon-tabler icons-tabler-outline icon-tabler-minus"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 12l14 0" /></svg>';
								?>
								</div>
                            </div>
                          </div>
                        </td>
                        <td data-label="DMARC">
                          <div class="d-flex">
                            <div class="flex-fill">
                            	<div class="font-weight-medium">
								<?
									if($mx_status!='no mx') {
										$dkim = trim(shell_exec("dig +short TXT _dmarc.$domain $nameserver"));
										log_debug("[check-email-settings.php] dig +short TXT _dmarc.$domain $nameserver // $dkim");
										if($dkim!='') {
											echo '<span><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#6A6" stroke-width="1" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-check"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><path d="M5 12l5 5l10 -10"></path></svg></span>';
										} else {
											echo '<span class="badge bg-orange">no DMARC</span>';
										}
									} else echo '<svg  xmlns="http://www.w3.org/2000/svg"  width="24"  height="24"  viewBox="0 0 24 24"  fill="none"  stroke="#888"  stroke-width="2"  stroke-linecap="round"  stroke-linejoin="round"  class="icon icon-tabler icons-tabler-outline icon-tabler-minus"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 12l14 0" /></svg>';
								?>
								</div>
                            </div>
                          </div>
                        </td>
                        <td>
                          <div class="btn-list flex-nowrap">
						  <? #if($mx_status!='no mx') { ?>
                            <a href="#" class="btn btn-white btn-md" data-bs-toggle="modal" data-bs-target="#modal-info" data-bs-domain="<?=$domain;?>">Details</a>
						  <? #} ?>
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
	<input type="hidden" id="domain-id" value="" />
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
            <button type="button" class="btn orange" id="fixsettiings" aria-label="Fix Email Settings">Fix Email Settings</button>
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
		$('#domain-id').val(domain);
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

	$("#fixsettiings").on('click', function(event) {
		var button = event.relatedTarget;
		var domain = $('#domain-id').val();
		$("#fixsettiings").prop('disabled', true);
		$("#fixsettiings").html('Fixing ...');
		jQuery.ajax({
			method: "POST",
			url: "./ajax-check-email/",
			data: { action: 'ajax-check-email-fixing', domain: domain }
		}).done(function( msg ) {
			$("#fixsettiings").html('Done!');
			if(msg != '') {
				$('#email-info').html(msg);
			}
		});		
	});

	$("#modal-info").on('hide.bs.modal', function(event) {
		$("#fixsettiings").prop('enabled', true);
		$("#fixsettiings").html('Fix Email Settings');
		$('#domain-title').html('&nbsp;');
		$('#email-info').html('<p><b>MX settings:</b></p><pre>&nbsp;</pre><p><b>SPF settings:</b></p><pre>&nbsp;</pre><p><b>DKIM settings:</b></p><pre>&nbsp;</pre><p><b>DMARC settings:</b></p><pre>&nbsp;</pre>');
	});
});
</script>
</body>
</html>
