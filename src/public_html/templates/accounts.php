<?php
	$items = 10;
	$results = $db->query('SELECT count(*) as nb FROM accounts');
	$row = $results->fetchArray();
	$nb_accounts = (int)($row["nb"]);
	$php_versions = array_map('trim', explode(',', $ini['php_versions']));
	$is_apache = (substr(trim($ini['template'] ?? ''), 0, 7) == 'apache_');
	$php_version_colors = [
		'7.2' => '#4299e1',
		'7.4' => '#2da6b4',
		'8.0' => '#1ab38c',
		'8.1' => '#09bf62',
		'8.2' => '#01c940',
		'8.3' => '#05ce29',
		'8.4' => '#33cf14',
		'8.5' => '#64cf0c',
	];

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
		$zones = get_zones();
		#echo '<pre>'; print_r($zones); exit;
	} else if($settings["dns-provider"]=='powerdns') {
		require_once(__DIR__.'/../modules/api_powerdns.php');
	} else {
		require_once(__DIR__.'/../modules/api_none.php');
	}
	#$errmsg = 'API for '.$settings["dns-provider"].' provider is not implemented!';

	// Aliases per account, for the "Show domain aliases" toggle (www.* first).
	$aliases_by_account = array();
	$ares = $db->query('SELECT account_id, alias, is_wildcard FROM aliases ORDER BY (alias LIKE "www.%") DESC, alias ASC');
	while($ares && $ar = $ares->fetchArray(SQLITE3_ASSOC))
		$aliases_by_account[$ar['account_id']][] = $ar;
	$email_enabled = (isset($ini["email"]) && $ini["email"]==1);

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
                  Accounts
                </div>
                <h2 class="page-title" style="white-space:nowrap !important;">
                  List Accounts
                </h2>
              </div>
              <div class="col-auto ms-auto d-print-none">
                <div class="btn-list">
				<? if(!isset($ini["accounts"]) || (isset($ini["accounts"]) && (int)($ini["accounts"])!=1) || (isset($ini["accounts"]) && (int)($ini["accounts"])==1) && $nb_accounts == 0) { ?>
                  <a href="#" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal-create-account">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    Create <span class="d-none d-sm-inline">&nbsp;a&nbsp;</span> new account
                  </a>
				<? } ?>
                </div>
              </div>
            </div>
          </div>

<?php msg_render(); /* flash message (PRG) — rendered once, shown even with 0 accounts */ ?>
<div id="ssl-toast"></div>
<?php
	// A freshly created account redirects here with ?sslmsg=<token>. The
	// background Let's Encrypt job posts its result to messages.db under that
	// token ~1 min later; the JS at the bottom polls ajax-msg and shows the toast.
	$ssl_msgtoken = (isset($_GET['sslmsg']) && preg_match('/^[0-9a-f]{16}$/', $_GET['sslmsg'])) ? $_GET['sslmsg'] : '';
?>

<?	if($nb_accounts == 0 ) { ?>
		<p style="padding:14px;">There are no accouns created on this server.</p>
<? 	} else { ?>

		<div class="col-12">
            <div class="card">
                <div class="table-responsive">
                  <table class="table table-vcenter card-table table-nowrap">
                    <thead>
                      <tr>
                        <th class="w-1" style="background-color:#DEF;">ID</th>
                        <th style="background-color:#DEF;">Domain <svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' width='16' height='16'><path fill='none' stroke='currentColor' stroke-linecap='round' stroke-linejoin='round' stroke-width='1' d='M5 10l3 -3l3 3'/></svg></th>
                        <th style="background-color:#DEF;">User</th>
                        <th class="w-10" style="background-color:#DEF;">Usage</th>
                        <th style="background-color:#DEF;">Status</th>
					<? if( isset($ini["email"]) && $ini["email"]==1 ) { ?>
                        <th style="background-color:#DEF;">Email</th>
					<? } ?>
                        <th style="background-color:#DEF;">PHP Version</th>
					<? if($is_apache): ?>
                        <th style="background-color:#DEF;">Handler</th>
					<? endif; ?>
                        <th style="background-color:#DEF;">Created At</th>
                        <th class="w-5" style="background-color:#DEF;"></th>
                      </tr>
					<? if($nb_accounts > 1 ) { ?>
                      <tr style="background-color:#FFF;">
                        <td style="padding:4px 8px;">&nbsp;</td>
                        <td style="padding:4px 8px;">
                          <div style="position:relative;">
                            <input type="text" id="account-search" class="form-control" placeholder="Filter domains..." style="padding:6px;;padding-right:26px;line-height:8pt;font-size:10pt;border:none;" autocomplete="off">
                            <button id="account-search-clear" type="button" title="Clear" style="display:none;position:absolute;right:7px;top:50%;transform:translateY(-50%);background:none;border:none;padding:0;cursor:pointer;color:#aaa;font-size:15px;line-height:1;">&#x2715;</button>
                          </div>
						</td>
						<td style="padding:4px 8px;" colspan="<?=(isset($ini["email"]) && $ini["email"]==1)?5:4; ?>">&nbsp;</td>
                        <td style="padding:4px 8px;">
                        </td>
                        <td colspan="<?=7 + ($is_apache ? 1 : 0);?>" style="padding:4px 8px;"></td>
                      </tr>
					<? } ?>
                    </thead>
                    <tbody>
                    <?
                    	$i = 0;
						$DISKSPACE = `lsblk -b --output TYPE,SIZE | grep 'disk' | awk {'print \$2'}`;
                      	$DISKSPACE = round((int)($DISKSPACE)/1024/1024);
                      	if($DISKSPACE == 0)
                        	$DISKSPACE = 9999999;

                      	$results = $db->query('SELECT * FROM accounts ORDER BY domain');
                      	while ($row = $results->fetchArray()) {
                        	$i++;
							if($row["disk_quota"] == 0)
								$row["disk_quota"] = (int)($DISKSPACE/20);
						  	$phpversion = $ini['php'];
							foreach ($php_versions as $pv) {
								$pv2=str_replace('.', '', $pv);
								if(is_file('/etc/opt/remi/php'.$pv2.'/php-fpm.d/'.$row['domain'].'.conf'))
									$phpversion = $pv;
							}
							$phphandler = 'mod_php';
							if($is_apache) {
								if($phpversion != $ini['php'])
									$phphandler = 'fpm';
								elseif(is_file('/etc/php-fpm.d/'.$row['domain'].'.conf'))
									$phphandler = 'fpm';
							}
							$domain = $row["domain"];
                    ?>
                      <tr class="account-row" data-domain="<?=$domain;?>" data-idx="<?=$i;?>" style="<?=$i>$items?'display:none':'';?>">
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
                              <div class="font-weight-medium"><a href="https://<?=$domain;?>" target="_blank">
								  <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-link" width="24" height="24" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">   <path stroke="none" d="M0 0h24v24H0z" fill="none"></path>   <path d="M10 14a3.5 3.5 0 0 0 5 0l4 -4a3.5 3.5 0 0 0 -5 -5l-.5 .5"></path>   <path d="M14 10a3.5 3.5 0 0 0 -5 0l-4 4a3.5 3.5 0 0 0 5 5l.5 -.5"></path></svg>							  	
								  <?=$domain;?></a>
								  <? if(array_key_exists($domain, $zones) && $zones[$domain]["status"]!='active') { ?>
									<div class="link-warning" style="display:inline; width:22px;height:22px;overflow:none;padding:0;cursor:pointer" data-bs-toggle="popover" data-bs-title="Nameservers" data-bs-content="Change nameservers to: <?=$zones[$domain]["nameservers"];?>">
										<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 -4 32 32" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round" class="icon alert-icon icon-2">
										<path d="M12 9v4"></path>
										<path d="M10.363 3.591l-8.106 13.534a1.914 1.914 0 0 0 1.636 2.871h16.214a1.914 1.914 0 0 0 1.636 -2.87l-8.106 -13.536a1.914 1.914 0 0 0 -3.274 0z"></path>
										<path d="M12 16h.01"></path>
										</svg>
									</div>
								  <? } ?>
								</div>
								<?php
									$acct_aliases = isset($aliases_by_account[$row['id']]) ? $aliases_by_account[$row['id']] : array();
									$row_has_mail = ($email_enabled && $row['has_email']);
									if(count($acct_aliases) || $row_has_mail):
								?>
								<div class="account-aliases text-muted" style="display:none;margin-top:3px;font-size:.85em;">
									<?php foreach($acct_aliases as $al): ?>
									<div>&#8627; <?=htmlspecialchars($al['alias']);?><?php if($al['is_wildcard']) echo ' <span class="badge bg-purple-lt">wildcard</span>'; ?></div>
									<?php endforeach; ?>
									<?php if($row_has_mail): ?><div>&#8627; mail.<?=$domain;?> <span class="text-muted">(mail)</span></div><?php endif; ?>
								</div>
								<?php endif; ?>
                            </div>
                          </div>
                        </td>
                        <td data-label="User">
                          <div class="d-flex py-1 align-items-center">
                            <div class="flex-fill">
                            <?=$row["user"];?>
                            </div>
                          </div>
                        </td>
                        <td data-label="Usage">
						<? $disk_pct = $DISKSPACE > 0 ? round($row["disk_usage"]*100/$DISKSPACE, 1) : 0; ?>
                          <div class="d-flex">
                            <div><strong><?=$row["disk_usage"]>1024?round($row["disk_usage"]/1024,1).' GB':$row["disk_usage"].' MB';?></strong> <span class="text-muted">(<?=$disk_pct;?>%)</span></div>
                          </div>
                          <div class="progress progress-xs">
                            <div class="progress-bar bg-success" role="progressbar" style="width: <?=min($disk_pct, 100);?>%"></div>
                          </div>
                        </td>
                        <td class="text-muted" data-label="Status">
                        <? if($row["status"] == 'active') { ?>
                          <span class="badge bg-green-lt border">Active</span>
					    <? } else if($row["status"] == 'inactive') { ?>
                          <span class="badge bg-orange">Inactive</span>
                        <? } else if($row["status"] == 'suspended') { ?>
                          <span class="badge bg-danger">Suspended</span>
                        <? } ?>
                        </td>
					<? if( isset($ini["email"]) && $ini["email"]==1 ) { ?>
                        <td class="text-muted data-label="Email">
                          <? if($row["has_email"] == true) { ?>
                          <div class="badge bg-blue-lt border text-blue">Yes</div>
                          <? } else { ?>
							<span class="text-muted" style="color:#aaa !important;"> - </span>
                          <? } ?>
                        </td>
					<? } ?>
                        <td class="text-muted" data-label="PHP Version">
							<? $php_color = $php_version_colors[$phpversion] ?? $php_version_colors[substr($phpversion, 0, 1)] ?? '#aaa'; ?>
							<span class="badge" style="background-color:<?=$php_color;?>">PHP <?=$phpversion;?></span>
                        </td>
					<? if($is_apache): ?>
                        <td class="text-muted" data-label="Handler">
							<span class="text-muted" style="font-size:0.85em;"><?=$phphandler == 'fpm' ? 'php-fpm' : $phphandler;?></span>
                        </td>
					<? endif; ?>
                        <td data-label="Created on" class="text-muted">
                              <div class="font-weight-medium"><?=date("M jS, Y - H:i", strtotime($row["created_at"]));?></></div>
                        </td>
                        <td>
                          <div class="btn-list flex-nowrap">
                            <a href="#" class="btn btn-white btn-md" data-bs-toggle="modal" data-bs-target="#modal-edit-account" data-bs-user="<?=$row["user"];?>" data-bs-domain="<?=$row["domain"];?>" data-bs-phpversion="<?=$phpversion;?>" data-bs-phphandler="<?=$phphandler;?>" data-bs-hasemail="<?=$row["has_email"];?>" <? if(isset($ini["quota"]) && (int)($ini["quota"])>0) { ?>data-bs-disk-quota="<?=$row["disk_quota"];?>"<? } ?>>Manage</a>
                            <? if(feature_enabled($ini, 'filemanager')) { ?>
                            <a href="#" class="btn btn-white btn-md" data-bs-toggle="modal" data-bs-target="#modal-file-manager" data-bs-user="<?=$row["user"];?>" data-bs-domain="<?=$row["domain"];?>">Files</a>
                            <? } ?>
                            <a href="#" class="btn btn-white btn-md" data-bs-toggle="modal" data-bs-target="#modal-delete-account" data-bs-user="<?=$row["user"];?>">Delete</a>
                          </div>
                        </td>
                      </tr>
                      <? } ?>
                    </tbody>
                  </table>
                </div>
		        <div id="account-footer" class="card-footer d-flex align-items-center">
				<? if($nb_accounts > $items): ?>
					<p class="m-0 text-muted"><span class="d-none d-xl-inline">Showing </span>1 to <?=$items;?> of <?=$nb_accounts;?> accounts</p>
					
					<style>
						/* Unchecked = dark grey; checked = primary blue (id beats Tabler's default rules). */
						#toggle-aliases { background-color:#AAA; border-color:#777; }
						#toggle-aliases:checked { background-color:var(--tblr-primary,#206bc4); border-color:var(--tblr-primary,#206bc4); }
					</style>
					<label for="toggle-aliases" class="form-check form-switch m-0" style="font-size:13px;min-height:0;line-height:16px;white-space:nowrap;padding-left:4.7rem;">
						<input class="form-check-input" type="checkbox" id="toggle-aliases" style="width:1.4rem;height:0.8rem;margin-top:2px;margin-left:-1.7rem;margin-right:0.5rem;background-size:0.8rem;;">
						<span class="form-check-label" style="cursor:pointer;color:var(--tblr-primary,#206bc4);font-weight:400;">Domain aliases</span>
					</label>

					<ul class="pagination m-0 ms-auto">
						<li class="page-item disabled"><a class="page-link" href="#" data-start="1"><svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><polyline points="15 6 9 12 15 18"></polyline></svg> prev</a></li>
						<? for($p = 1; $p <= ceil($nb_accounts/$items); $p++): ?>
						<li class="page-item <?=$p===1?'active':'';?>"><a class="page-link" href="#" data-start="<?=($p-1)*$items+1;?>"><?=$p;?></a></li>
						<? endfor; ?>
						<li class="page-item <?=$nb_accounts<=$items?'disabled':'';?>"><a class="page-link" href="#" data-start="<?=$items+1;?>">next <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><polyline points="9 6 15 12 9 18"></polyline></svg></a></li>
					</ul>
				<? else: ?>
					<p class="m-0 text-muted">Total: <?=$nb_accounts;?> account<?=$nb_accounts>1?'s':'';?>.</p>
				<? endif; ?>
				</div>
            </div>
			<?  } ?>

            </div>
          </div>
        </div>
      </div>
    </div>

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
              <input type="text" class="form-control" name="user" id="user" placeholder="user1" aria-describedby="userHelpBlock" required pattern="[a-z]+[a-z0-9]{1,15}" maxlength="16" autocomplete="off">
              <small id="userHelpBlock" class="form-text text-muted" style="display:block;margin-top:8px;">
                Username must be unique, 2-16 characters long, contain letters and numbers, and must not contain spaces.
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
			<br>
			<div class="mb-3 <? if($settings["dns-provider"]=='') echo 'disabled';?>">
				<label class="form-check">
					<input class="form-check-input" type="checkbox" name="adddns" checked="true" <? if($settings["dns-provider"]=='') echo 'disabled';?>>
					  <span class="form-check-label">Add DNS zone record for this domain on remote DNS servers using DNS API (<?=$settings["dns-provider"];?>)</span>
					  <span class="form-check-description">Uncheck if domain already exists in DNS.</span>
                 </label>
          	</div>
			<div class="mb-3">
				<label class="form-check">
					<input class="form-check-input" type="checkbox" name="letsencrypt" checked="true">
					  <span class="form-check-label">Obtain and install a free Let's Encrypt SSL/TLS certificate</span>
					  <span class="form-check-description">Renew automatically every 90 days. <a href="https://letsencrypt.org/repository/" target="_blank">Let's Encrypt Terms and Conditions</a> applies.</span>
                 </label>
          	</div>
				<div class="mb-3">
					<label class="form-check">
						<input class="form-check-input" type="checkbox" name="www_alias" checked="true">
						  <span class="form-check-label">Create the <b>www</b> alias for this domain</span>
						  <span class="form-check-description">Adds <code>www.&lt;domain&gt;</code> as an alias. Uncheck if the domain is already a subdomain.</span>
                 </label>
          	</div>
<? if( isset($ini["email"]) && $ini["email"]==1 ) { ?>
			<div class="mb-3">
				<label class="form-check">
					<input class="form-check-input" type="checkbox" name="email">
					  <span class="form-check-label">Configure email accounts on this domain</span>
					  <span class="form-check-description">This option will create DNS records and mail settings on local server..</span>
                 </label>
          	</div>
<? } ?>
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
    <input type="hidden" name="user" id="user-edit" value="">
    <input type="hidden" name="domain" id="domain-edit" value="">
    <div class="modal modal-blur fade" id="modal-edit-account" tabindex="-1" role="dialog" aria-hidden="true">
      <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Manage account <span id="user-title"></span></h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <p>You can edit the account, but you can't change the domain or username.</p>
            <div class="mb-3">
              <label class="form-label">Domain name:</label>
              <span id="domain-title"></span>
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
                  <label class="form-label">New password:</label>
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


			<div class="row">
              <div class="col-lg-6">
                <div class="mb-3">
					<br />
          			<label class="form-label">PHP version:</label>
		            <select name="phpversion" id="phpversion" class="form-select">
					<? if($is_apache): ?>
						<option value="<?=$ini['php'];?>:mod_php">PHP <?=$ini['php'];?> (mod_php)</option>
						<option value="<?=$ini['php'];?>:fpm">PHP <?=$ini['php'];?> (php-fpm)</option>
						<? foreach ($php_versions as $pv): if($pv == $ini['php']) continue; ?>
						<option value="<?=$pv;?>:fpm">PHP <?=$pv;?> (php-fpm)</option>
						<? endforeach; ?>
					<? else: ?>
						<? foreach ($php_versions as $pv): ?>
						<option value="<?=$pv;?>">PHP <?=$pv;?></option>
						<? endforeach; ?>
					<? endif; ?>
        		    </select>
		            <div class="invalid-feedback" id="invalid-user">
        		        Please select a php version to use on this account.
            		</div>
           		</div>
			  </div>

			  <? if( isset($ini["email"]) && $ini["email"]==1 ) { ?>
				<div class="col-lg-6">
			  	<div class="mb-3">
					<br />
          		  	<label class="form-label">Email:</label>
				   	<label class="form-check form-switch" style="padding-top:7px">
    					<input class="form-check-input" name="hasemail" id="hasemail" type="checkbox">
    					<span class="form-check-label">Enable email on this domain</span>
					</label>
				</div>
			  	</div>
			  <? } ?>
		    </div>

          </div>
          <div class="modal-footer">
            <!-- Advanced settings (Alias Domains / config editor) — premium-only;
                 a version gate will be added here later. Link target is set by the
                 show.bs.modal handler from the clicked row's user. -->
            <a href="#" id="advanced-settings-link" class="btn btn-white me-auto">
              <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><path d="M10.325 4.317c.426 -1.756 2.924 -1.756 3.35 0a1.724 1.724 0 0 0 2.573 1.066c1.543 -.94 3.31 .826 2.37 2.37a1.724 1.724 0 0 0 1.065 2.572c1.756 .426 1.756 2.924 0 3.35a1.724 1.724 0 0 0 -1.066 2.573c.94 1.543 -.826 3.31 -2.37 2.37a1.724 1.724 0 0 0 -2.572 1.065c-.426 1.756 -2.924 1.756 -3.35 0a1.724 1.724 0 0 0 -2.573 -1.066c-1.543 .94 -3.31 -.826 -2.37 -2.37a1.724 1.724 0 0 0 -1.065 -2.572c-1.756 -.426 -1.756 -2.924 0 -3.35a1.724 1.724 0 0 0 1.066 -2.573c-.94 -1.543 .826 -3.31 2.37 -2.37c1 .608 2.296 .07 2.572 -1.065z"></path><path d="M9 12a3 3 0 1 0 6 0a3 3 0 0 0 -6 0"></path></svg>
              Advanced settings
            </a>
            <a href="#" class="btn btn-link link-secondary" data-bs-dismiss="modal">
              Cancel
            </a>
            <button id="submit-btn2" class="btn btn-primary" type="submit">Save changes</button>
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
          <div class="modal-header">
            <h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Delete account <span id="user-title"></span></h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
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
                <div class="col"><button id="submit-btn3" class="btn btn-primary" type="submit">
					Delete account
				</button></div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
</form>	

<?php
    include('templates/footer.php');
    if(feature_enabled($ini, 'filemanager')) include('templates/file-manager-modal.php');
?>
<script>
jQuery(document).ready(function () {
	'use strict';

	// --- "Show domain aliases" toggle (persisted in localStorage) --------------
	var showAliases = localStorage.getItem('reqad_show_aliases') === '1';
	$('#toggle-aliases').prop('checked', showAliases);
	$('.account-aliases').toggle(showAliases);
	$('#toggle-aliases').on('change', function () {
		var on = $(this).is(':checked');
		$('.account-aliases').toggle(on);
		localStorage.setItem('reqad_show_aliases', on ? '1' : '0');
	});

	// --- Async toast when the background Let's Encrypt job finishes ------------
	// The account redirected here with ?sslmsg=<token>; the cert script posts its
	// result to messages.db under that token. Poll ajax-msg until it returns the
	// alert HTML (cert installed, or failed → check SSL page), then show it once.
	var sslMsgToken = '<?= $ssl_msgtoken ?>';
	if (sslMsgToken !== '') {
		var sslMsgAttempts = 0;
		var sslMsgMax = 48;               // ~4 min ceiling (48 * 5s)
		function sslMsgPoll() {
			sslMsgAttempts++;
			$.post('/?ajax=1', { action: 'ajax-msg', token: sslMsgToken }, null, 'json')
				.done(function (r) {
					if (r && r.html) {
						$('#ssl-toast').html(r.html);
						// drop ?sslmsg so a refresh doesn't re-poll
						if (window.history && history.replaceState)
							history.replaceState(null, '', window.location.pathname);
						return;
					}
					if (sslMsgAttempts < sslMsgMax) setTimeout(sslMsgPoll, 5000);
				})
				.fail(function () {
					if (sslMsgAttempts < sslMsgMax) setTimeout(sslMsgPoll, 5000);
				});
		}
		setTimeout(sslMsgPoll, 5000);
	}

	// Account search / pagination
	var accountStart = 1;
	var accountItems = <?=$items;?>;
	var accountTotal = <?=$nb_accounts;?>;
	var svgPrev = '<svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><polyline points="15 6 9 12 15 18"></polyline></svg>';
	var svgNext = '<svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><polyline points="9 6 15 12 9 18"></polyline></svg>';

	function renderAccountFooter(total, start) {
		var html = '';
		if (total > accountItems) {
			var pages = Math.ceil(total / accountItems);
			var curPage = Math.floor((start - 1) / accountItems) + 1;
			html += '<p class="m-0 text-muted"><span class="d-none d-xl-inline">Showing </span>' + start + ' to ' + Math.min(start + accountItems - 1, total) + ' of ' + total + ' accounts</p>';
			html += '<ul class="pagination m-0 ms-auto">';
			html += '<li class="page-item' + (start === 1 ? ' disabled' : '') + '"><a class="page-link" href="#" data-start="' + Math.max(1, start - accountItems) + '">' + svgPrev + ' prev</a></li>';
			for (var p = 1; p <= pages; p++) {
				html += '<li class="page-item' + (p === curPage ? ' active' : '') + '"><a class="page-link" href="#" data-start="' + ((p - 1) * accountItems + 1) + '">' + p + '</a></li>';
			}
			html += '<li class="page-item' + ((start + accountItems) > total ? ' disabled' : '') + '"><a class="page-link" href="#" data-start="' + (start + accountItems) + '">next ' + svgNext + '</a></li>';
			html += '</ul>';
		} else {
			html = '<p class="m-0 text-muted">Total: ' + total + ' account' + (total !== 1 ? 's' : '') + '.</p>';
		}
		$('#account-footer').html(html);
	}

	function showAccountPage(start) {
		accountStart = start;
		$('.account-row').each(function() {
			var idx = parseInt($(this).data('idx'));
			$(this).toggle(idx >= accountStart && idx < accountStart + accountItems);
		});
		renderAccountFooter(accountTotal, accountStart);
	}

	$('#account-footer').on('click', '.page-link', function(e) {
		e.preventDefault();
		if ($(this).closest('.page-item').hasClass('disabled')) return;
		showAccountPage(parseInt($(this).data('start')));
	});

	$('#account-search').on('input', function() {
		$('#account-search-clear').toggle($(this).val() !== '');
	});

	$('#account-search').on('keydown', function(e) {
		if (e.key !== 'Enter') return;
		var q = $(this).val().toLowerCase().trim();
		if (q === '') { clearAccountSearch(); return; }
		var shown = 0;
		$('.account-row').each(function() {
			var matches = $(this).data('domain').toLowerCase().indexOf(q) !== -1;
			$(this).toggle(matches);
			if (matches) shown++;
		});
		$('#account-footer').html('<p class="m-0 text-muted">' + shown + ' result' + (shown !== 1 ? 's' : '') + ' for &ldquo;' + $('<span>').text(q).html() + '&rdquo;</p>');
	});

	$('#account-search-clear').on('click', clearAccountSearch);

	function clearAccountSearch() {
		$('#account-search').val('');
		$('#account-search-clear').hide();
		showAccountPage(accountStart);
	}

	$('[data-bs-toggle="popover"]').popover({
		html: true
	}).on('shown.bs.popover', function(e){
		// get the dom element that the popover points to
		var el = $(e.target);
		// get the popover dom obj
		var po = $('#' + el.attr('aria-describedby'));
		// get the title
		var poh = po.find('.popover-header');
		poh.append('<sup class="popover-close ms-3" style="cursor:pointer;">&times;</sup>');

		// get the close button that we just added
		var cb = poh.find('.popover-close');

		// get the bootstrap popover obj
		var bpo = bootstrap.Popover.getInstance(e.target);

		cb.on('click', function(e) {
		bpo.hide();
		});
	});

	$("#modal-create-account").on('shown.bs.modal', function() {
		$('#domain').focus();
	});
	$("#create-account").submit(function(event) {
		event.preventDefault();
		if ($('#create-account')[0].checkValidity() === false) {
			event.stopPropagation();
			if(!$('#domain').is(':valid')) {
				$('#domain').focus();
			}
		} else {
		if($('#domain').is(':valid')) {
			jQuery.ajax({
			method: "POST",
			url: "./ajax-domain/",
			data: { action: 'ajax-domain', domain: $('#domain').val() }
			}).done(function( msg ) {
			if(msg != '') {
				$('#invalid-domain').html(msg);
				$('#domain').addClass('is-invalid');
				$('#domain').removeClass('was-validated');
				$("#create-account").removeClass('was-validated');
			} else {
				$('#invalid-domain').html('');
				$('#domain').removeClass('is-invalid');
				$('#domain').addClass('was-validated');
				//console.log('domain ok');
				if ($('#user').is(':valid')) {
				jQuery.ajax({
					method: "POST",
					url: "./ajax-user/",
					data: { action: 'ajax-user', user: $('#user').val() }
				}).done(function( msg ) {
					if(msg != '') {
						$('#invalid-user').html(msg);
						$('#user').addClass('is-invalid');
						$('#user').removeClass('was-validated');
						$("#create-account").removeClass('was-validated');
					} else {
						$('#invalid-user').html('');
						$('#user').removeClass('is-invalid');
						$('#user').addClass('was-validated');
						// console.log('user ok');
						$("#create-account").addClass('was-validated');
						if($('#create-account').is(':valid')) {
							$('#submit-btn').html('Creating account ...');
							$('#submit-btn').prop('disabled', true);
							$("#create-account").unbind('submit').submit();
						}
					}
				});
				}
			}
			});
		}
		}
  	});

	$('#edit-account').on('show.bs.modal', function (event) {
		// Button that triggered the modal
		var button = event.relatedTarget;
		// Extract info from data-bs-* attributes
		var user = button.getAttribute('data-bs-user');
		var domain = button.getAttribute('data-bs-domain');
		var phpversion = button.getAttribute('data-bs-phpversion');
		var phphandler = button.getAttribute('data-bs-phphandler');
		if(button.getAttribute('data-bs-hasemail')==true)
			var hasemail = true;
		else
			var hasemail = false;
		$('#user-edit').val(user);
		$('#user-title').html(user);
		$('#domain-edit').val(domain);
		$('#domain-title').html(domain);
		$('#password2').val('');
		// Advanced settings (premium) — point the link at this account's page
		$('#advanced-settings-link').attr('href', '/account/' + user + '/');
	<? if(isset($ini["quota"]) && (int)($ini["quota"])>0) { ?>
		var disk_quota = button.getAttribute('data-bs-disk-quota')
		$('#diskquota-edit').val(disk_quota);
		$('#diskquota-edit').next().html(disk_quota + ' MB');
	<? } ?>
	<?php if($is_apache): ?>
		$('#phpversion').val(phpversion + ':' + phphandler);
	<?php else: ?>
		$('#phpversion').val(phpversion);
	<?php endif; ?>
		$('#hasemail').prop( "checked", hasemail);
  	});

	$("#edit-account").submit(function(event) {
		$('#submit-btn2').prop('disabled', true);
		$("#edit-account").unbind('submit').submit();
  	});

	$('#delete-account').on('show.bs.modal', function (event) {
		//console.log(event);
		// Button that triggered the modal
		var button = event.relatedTarget;
		// Extract info from data-bs-* attributes
		var user = button.getAttribute('data-bs-user');
		$('#user-delete').val(user);
		$('#user-title2').html(user);
  	});

	$("#delete-account").submit(function(event) {
	//	event.preventDefault();
	//	event.stopPropagation();
		$('#submit-btn3').prop('disabled', true);
		$("#delete-account").unbind('submit').submit();
  	});
});
</script>
</body>
</html>
