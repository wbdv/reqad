<?php
	#phpinfo(32);

	$items = 10;

	// Mailbox inventory comes from /etc/dovecot/users; the `emails` table is only
	// a cache of the du -skm figure and never an inventory (db/1032.sql). Rows
	// measured within MAILBOX_USAGE_TTL are reused, anything else is measured now
	// and written back, so the cache warms itself without waiting for the cron.
	$emails2     = mailbox_usage_list($db);
	$nb_accounts = count($emails2);

	// Personal Sieve rule count per mailbox, for the Filters column. One helper
	// call for the whole table (see sieve_user_get_all); a mailbox with no script
	// is simply absent. null means the script is there but cannot be shown as a
	// list of rules — the filters page falls back to a raw editor for those.
	$filter_counts = array();
	foreach (sieve_user_get_all() as $mb => $src) {
		$parsed = sieve_parse_script($src, false);
		$filter_counts[$mb] = ($parsed === false) ? null : count($parsed);
	}

	include('templates/header.php');

	/* One check for the whole page: each row's Webmail button is only
	   rendered when the dovecot master user is actually set up. */
	$webmail_autologin = webmail_autologin_enabled();

	/* Hostname a mail client should use, per domain. Looked up once per domain
	   rather than per row: the fallback in mail_client_hostname() shells out. */
	$client_hosts = array();

	/* Domains offering email, plus each account's total disk usage in MB — the
	   Usage column shows every mailbox as a share of its own account's total. */
	$domains     = [];
	$acct_usage  = [];
	$res = $db->query("SELECT domain, disk_usage FROM accounts WHERE has_email=1 ORDER BY domain");
	while ($drow = $res->fetchArray(SQLITE3_ASSOC)) {
		$domains[] = $drow['domain'];
		$acct_usage[strtolower($drow['domain'])] = (float)$drow['disk_usage'];
	}
   	
?>
          <!-- Page title -->
          <div class="page-header d-print-none">
            <div class="row align-items-center">
              <div class="col" style="padding-left:22px;">
                <!-- Page pre-title -->
                <div class="page-pretitle">
                  Email
                </div>
                <h2 class="page-title" style="white-space:nowrap !important;">
                  List Email&nbsp;<span class="d-none d-sm-inline">Accounts</span>
                </h2>
              </div>

			  <? if(count($domains)>0) { ?>
              <div class="col-auto ms-auto d-print-none">
                <div class="btn-list">
                  <a href="#" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal-create-email">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    Create a new email&nbsp;<span class="d-none d-sm-inline">account</span>
                  </a>
                </div>
              </div>
			  <? } ?>
            </div>
          </div>

<?php msg_render(); /* flash message (PRG) — shown once, even with 0 accounts */ ?>

<?	if($nb_accounts == 0 ) { ?>
		<p style="padding:14px;">There are no email accouns created on this server.</p>
<? 	} else { ?>



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
                        <th style="background-color:#DEF;">EMAIL <svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' width='16' height='16'><path fill='none' stroke='currentColor' stroke-linecap='round' stroke-linejoin='round' stroke-width='1' d='M5 10l3 -3l3 3'/></svg></th>
                        <th class="w-10" style="background-color:#DEF;">Usage</th>
                        <th style="background-color:#DEF;">Status</th>
                        <th style="background-color:#DEF;">Filters</th>
                        <th class="w-5" style="background-color:#DEF;"></th>
                      </tr>
                      <tr>
                        <td style="padding:4px 8px;"></td>
                        <td style="padding:4px 8px;">
                          <div style="position:relative;max-width:350px;">
                            <input type="text" id="email-search" class="form-control" placeholder="Filter emails..." style="padding:6px;;padding-right:26px;line-height:8pt;font-size:10pt;border:none;" autocomplete="off">
                            <button id="email-search-clear" type="button" title="Clear" style="display:none;position:absolute;right:7px;top:50%;transform:translateY(-50%);background:none;border:none;padding:0;cursor:pointer;color:#aaa;font-size:15px;line-height:1;">&#x2715;</button>
                          </div>
                        </td>
                        <td colspan="4" style="padding:4px 8px;"></td>
                      </tr>
                    </thead>
                    <tbody>
                   <?
                      	$i = 0;
						foreach($emails2 as $email) {
							$i++;
                    ?>
                      <tr class="email-row" data-email="<?=$email['email'];?>" data-idx="<?=$i;?>" style="<?=$i>$items?'display:none;':'';?><?=$email['enabled']===false?'background-color:#FF000015;':'';?>">
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
                              <div class="font-weight-medium">
								  <?=$email['email'];?></div>
                            </div>
                          </div>
                        </td>
                        <td data-label="Usage">
						<?	$mb_used   = (float)$email['disk_usage'];
							$dom       = strtolower(substr(strrchr($email['email'], '@'), 1));
							$acct_tot  = isset($acct_usage[$dom]) ? $acct_usage[$dom] : 0;
							$mail_pct  = $acct_tot > 0 ? round($mb_used * 100 / $acct_tot, 1) : 0; ?>
                          <div class="d-flex">
                            <div><strong><?=$mb_used>0?human_mb($mb_used):'-';?></strong>
							<? if($acct_tot > 0) { ?><span class="text-muted">(<?=$mail_pct;?>%)</span><? } ?></div>
                          </div>
                          <div class="progress progress-xs">
                            <div class="progress-bar bg-success" role="progressbar" style="width: <?=min($mail_pct, 100);?>%"></div>
                          </div>
                        </td>
                        <td class="text-muted" data-label="Status">
                        <? if($email['enabled']) { ?>
                          <span class="badge bg-green-lt border">Active</span>
                        <? } else { ?>
                          <span class="badge bg-red-lt border" title="Login is disabled: no IMAP/POP3 or SMTP AUTH. Mail is still delivered to this mailbox.">Disabled</span>
                        <? } ?>
                        </td>
                        <td data-label="Filters">
                          <?	$fc = array_key_exists($email['email'], $filter_counts)
							      ? $filter_counts[$email['email']] : 0; ?>
                          <a href="/email-filters/?scope=account&amp;target=<?=urlencode($email['email']);?>"
                             class="btn btn-white btn-md" title="<?=$fc === null
                                ? 'This mailbox has a Sieve script Reqad cannot show as a list of rules'
                                : 'Edit this mailbox\'s own filters';?>">Filters
                            <? if($fc === null) { ?>
                              <span class="badge bg-yellow text-yellow-fg ms-2">!</span>
                            <? } else if($fc > 0) { ?>
                              <span class="badge bg-blue text-blue-fg ms-2"><?=$fc;?></span>
                            <? } else { ?>
                              <span class="badge bg-default text-default-fg ms-2">0</span>
                            <? } ?>
                          </a>
                        </td>
                        <td>
                          <div class="btn-list flex-nowrap">
                            <? if($webmail_autologin) { ?>
                            <a href="#" class="btn btn-white btn-md wm-login" data-email="<?=$email['email'];?>" title="Open this mailbox in webmail" style="color:#206bc4;border:1px solid #206ac44e">
<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#206ac4" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-mail-spark">
	<path stroke="none" d="M0 0h24v24H0z" fill="none" />
	<path d="M19 22.5a4.75 4.75 0 0 1 3.5 -3.5a4.75 4.75 0 0 1 -3.5 -3.5a4.75 4.75 0 0 1 -3.5 3.5a4.75 4.75 0 0 1 3.5 3.5" />
	<path d="M11.5 19h-6.5a2 2 0 0 1 -2 -2v-10a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v5" />
	<path d="M3 7l9 6l9 -6" />
</svg>
<?php /* roundcube logo
                              <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20"
                               viewBox="9.14 141.8 573.65 573.65" aria-hidden="true" focusable="false">
                                <polygon fill="#37BEFF" fill-rule="evenodd" clip-rule="evenodd" points="582.79,549.77 295.96,384.1 295.96,207.27 582.79,372.95"/>
                                <polygon fill="#404F54" fill-rule="evenodd" clip-rule="evenodd" points="9.14,549.77 295.96,384.1 295.96,207.27 9.14,372.95"/>
                                <path fill="#CCCCCC" fill-rule="evenodd" clip-rule="evenodd" d="M295.96,141.8c109.56,0,198.41,88.85,198.41,198.41c0,109.56-88.85,198.41-198.41,198.41 c-109.56,0-198.41-88.85-198.41-198.41C97.55,230.65,186.4,141.8,295.96,141.8"/>
                                <path fill="#E5E5E5" fill-rule="evenodd" clip-rule="evenodd" d="M295.96,141.8c109.6,0,198.48,88.85,198.48,198.41c0,109.56-88.88,198.41-198.48,198.41 c-62.91-42.34-88.94-127.64-88.94-198.3S233.05,184.22,295.96,141.8"/>
                                <polygon fill="#37BEFF" fill-rule="evenodd" clip-rule="evenodd" points="582.79,372.95 295.96,538.62 295.96,715.45 582.79,549.77"/>
                                <polygon fill="#404F54" fill-rule="evenodd" clip-rule="evenodd" points="9.14,372.95 295.96,538.62 295.96,715.45 9.14,549.77"/>
                              </svg>
*/ ?> 
							  Webmail
                            </a>
                            <? } ?>
                            <?	$dom_cs = strtolower(substr(strrchr($email['email'], '@'), 1));
								if(!isset($client_hosts[$dom_cs])) $client_hosts[$dom_cs] = mail_client_hostname($dom_cs); ?>
                            <a href="#" class="btn btn-white btn-md" data-bs-toggle="modal" data-bs-target="#modal-client-settings"
                               data-bs-email="<?=htmlspecialchars($email['email']);?>" data-bs-host="<?=htmlspecialchars($client_hosts[$dom_cs]);?>"
                               title="Server settings for setting up this mailbox in a mail client (Outlook, Thunderbird, Apple Mail, phones)">
<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-device-mobile">
	<path stroke="none" d="M0 0h24v24H0z" fill="none" />
	<path d="M6 5a2 2 0 0 1 2 -2h8a2 2 0 0 1 2 2v14a2 2 0 0 1 -2 2h-8a2 2 0 0 1 -2 -2v-14z" />
	<path d="M11 4h2" />
	<path d="M12 17v.01" />
</svg>
							  Client Setup
                            </a>
                            <a href="#" class="btn btn-white btn-md" data-bs-toggle="modal" data-bs-target="#modal-edit-email" data-bs-email="<?=$email['email'];?>" data-bs-enabled="<?=$email['enabled']?1:0;?>">Change Password</a>
                            <a href="#" class="btn btn-white btn-md" data-bs-toggle="modal" data-bs-target="#modal-delete-email" data-bs-email="<?=$email['email'];?>">Delete</a>
                          </div>
                        </td>
                      </tr>
                      <? } ?>
                    </tbody>
                  </table>
                </div>
		        <div id="email-footer" class="card-footer d-flex align-items-center">
				<? if($nb_accounts > $items): ?>
					<p class="m-0 text-muted"><span class="d-none d-xl-inline">Showing </span>1 to <?=$items;?> of <?=$nb_accounts;?> email accounts</p>
					<ul class="pagination m-0 ms-auto">
						<li class="page-item disabled"><a class="page-link" href="#" data-start="1"><svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><polyline points="15 6 9 12 15 18"></polyline></svg> prev</a></li>
						<? for($p = 1; $p <= ceil($nb_accounts/$items); $p++): ?>
						<li class="page-item <?=$p===1?'active':'';?>"><a class="page-link" href="#" data-start="<?=($p-1)*$items+1;?>"><?=$p;?></a></li>
						<? endfor; ?>
						<li class="page-item"><a class="page-link" href="#" data-start="<?=$items+1;?>">next <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><polyline points="9 6 15 12 9 18"></polyline></svg></a></li>
					</ul>
				<? else: ?>
					<p class="m-0 text-muted">Total: <?=$nb_accounts;?> email account<?=$nb_accounts>1?'s':'';?>.</p>
				<? endif; ?>
				</div>
              </div>
            </div>
			<?  } ?>

            </div>
          </div>
        </div>
      </div>
    </div>

	<form method="post" action="/" id="create-email" class="needs-validation" novalidate>
    <input type="hidden" name="action" value="create-email">
    <div class="modal modal-blur fade" id="modal-create-email" tabindex="-1" role="dialog" aria-hidden="true">
      <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Create a new email account</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
			<p>To create a new email account, type the user and then select the domain name from the list.</p>
            <div class="mb-3">
				<div class="row">
                    <div class="col-5">
						<input type="text" class="form-control" name="user" id="user" placeholder="user1" aria-describedby="userHelpBlock" required pattern="[A-Za-z0-9_\-\+\.]+" maxlength="64" autocomplete="off">
						<div class="invalid-feedback" id="invalid-email">
							Please type email address.
						</div>
					</div>
                    <div class="col-1">
						<div class="input-group">
							<span class="input-group-text">
								@
							</span>
						</div>
					</div>
                    <div class="col-6">
						<select class="form-select" name="domain" id="domain">
						<? foreach($domains as $domain) { ?>
							<option><?=$domain;?></option>
						<?	} ?>
						</select>
					</div>
				</div>
				<small id="userHelpBlock" class="form-text text-muted" style="display:block;margin-top:8px;">
					Email must be unique, 1-64 characters long, contain letters, numbers, dashes and underscores.
				</small>
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
          </div>
          <div class="modal-footer">
            <a href="#" class="btn btn-link link-secondary" data-bs-dismiss="modal">
              Cancel
            </a>
            <button id="submit-btn" class="btn btn-primary" type="submit"><svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg> Create email account</button>
          </div>
        </div>
      </div>
    </div>
</form>

<form method="post" action="/" id="edit-email" class="needs-validation" novalidate>
    <input type="hidden" name="action" value="edit-email">
    <input type="hidden" name="email" id="email-edit" value="">
    <div class="modal modal-blur fade" id="modal-edit-email" tabindex="-1" role="dialog" aria-hidden="true">
      <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Edit email account <span id="email-title"></span></h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <p>You can change the password for this email account, or disable it.</p>
            <div class="mb-3">
              <label class="form-label">Email address:</label>
              <span id="email-title2" class="input-group-text"></span>
            </div>
			<? if(isset($ini["quota"]) && (int)($ini["quota"])>0) { /* TODO */ ?>
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
                  <input type="text" class="form-control" name="password" id="password2" autocomplete="off" aria-describedby="passwordHelpBlock" pattern="[^ ]{8,24}" maxlength="24">
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
                Your password must be 8-24 characters long, contain letters, numbers and special characters but not spaces.
              </small>
              <div class="invalid-feedback">
                Please enter a password.
              </div>
              </div>

            <div class="mb-1" style="margin-top:20px;">
              <label class="form-check form-switch d-inline-flex align-items-center mb-0" style="cursor:pointer">
                <input class="form-check-input" type="checkbox" name="login_enabled" id="login-enabled" value="1" checked style="margin-right:10px;margin-bottom:1px;">
                <span id="login-enabled-label" class="form-check-label" style="cursor: pointer;line-height:21px;">Email account is</span>
                <span id="login-enabled-state" class="badge bg-green-lt border ms-2">Active</span>
              </label>
              <small class="form-text text-muted" style="display:block;">
                Set to Disabled to disable the password: the account can no longer be used to
                read mail from a mail client or webmail, and cannot send mail through the server.
                Incoming mail is still delivered to the mailbox, and the password is kept -
                turning it back on restores access.
              </small>
            </div>
          </div>
          <div class="modal-footer">
            <a href="#" class="btn btn-link link-secondary" data-bs-dismiss="modal">
              Cancel
            </a>
            <button id="submit-btn2" class="btn btn-primary" type="submit">Save changes</button>
          </div>
        </div>
      </div>
    </div>
</form>

<style>
  /* one click selects the whole value, ready to copy */
  #modal-client-settings .cs-copy { font-family: var(--tblr-font-monospace, monospace); user-select: all; }
  #modal-client-settings .cs-table td { padding-top: 3px; padding-bottom: 3px; }
</style>
<div class="modal modal-blur fade" id="modal-client-settings" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Mail client settings</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted">Use these settings to add <strong class="cs-email text-body"></strong> to Outlook, Thunderbird, Apple Mail or a phone.
          The password is the mailbox password.</p>

        <h4 class="mb-2"><b>Incoming mail server</b></h4>
        <table class="sysinfo-table table table-sm w-100">
          <tr><td class="text-muted w-10">Protocol</td><td>IMAP</td></tr>
          <tr><td class="text-muted">Server</td><td class="cs-host cs-copy"></td></tr>
          <tr><td class="text-muted">Port</td><td>993</td></tr>
          <tr><td class="text-muted">Connection security</td><td>SSL/TLS</td></tr>
          <tr><td class="text-muted">Username</td><td class="cs-email cs-copy"></td></tr>
        </table>

        <h4 class="mb-2"><b>Outgoing mail server</b></h4>
        <table class="sysinfo-table table table-sm w-100">
          <tr><td class="text-muted w-10">Protocol</td><td>SMTP</td></tr>
          <tr><td class="text-muted">Server</td><td class="cs-host cs-copy"></td></tr>
          <tr><td class="text-muted">Port</td><td>465</td></tr>
          <tr><td class="text-muted">Connection security</td><td>SSL/TLS</td></tr>
          <tr><td class="text-muted">Username</td><td class="cs-email cs-copy"></td></tr>
          <tr><td class="text-muted">Authentication</td><td>Normal password</td></tr>
        </table>
      </div>
      <div class="modal-footer">
        <a href="#" class="btn btn-link link-secondary me-auto" data-bs-dismiss="modal">Close</a>
        <a href="#" id="cs-mobileconfig" class="btn btn-white"
           title="Apple Mail setup profile for iPhone, iPad and Mac. It carries no password - the device asks for it on install.">
          <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2" /><path d="M7 11l5 5l5 -5" /><path d="M12 4l0 12" /></svg>
          Download Apple profile
        </a>
      </div>
    </div>
  </div>
</div>

<form method="post" action="/" id="delete-email" class="needs-validation" novalidate>
    <input type="hidden" name="action" value="delete-email">
    <input type="hidden" name="email" id="email-delete" value="">
    <div class="modal modal-blur fade" id="modal-delete-email" tabindex="-1" role="dialog" aria-hidden="true">
      <div class="modal-dialog modal-sm modal-dialog-centered" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Delete email account</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          <div class="modal-status bg-danger"></div>
          <div class="modal-body text-center py-4">
			<svg xmlns="http://www.w3.org/2000/svg" class="icon mb-2 text-danger icon-lg" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="#ff2825" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="12" cy="12" r="9" /><line x1="12" y1="8" x2="12" y2="12" /><line x1="12" y1="16" x2="12.01" y2="16" /></svg>
            <h3>Delete email account <span id="email-title3"></span></h3>
            <div class="text-muted">Do you really want to remove this email account?</div>
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
?>
<script>
jQuery(document).ready(function () {
	'use strict';

	// Email search / pagination
	var emailStart = 1;
	var emailItems = <?=$items;?>;
	var emailTotal = <?=$nb_accounts;?>;
	var svgPrev = '<svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><polyline points="15 6 9 12 15 18"></polyline></svg>';
	var svgNext = '<svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><polyline points="9 6 15 12 9 18"></polyline></svg>';

	function renderEmailFooter(total, start) {
		var html = '';
		if (total > emailItems) {
			var pages = Math.ceil(total / emailItems);
			var curPage = Math.floor((start - 1) / emailItems) + 1;
			html += '<p class="m-0 text-muted"><span class="d-none d-xl-inline">Showing </span>' + start + ' to ' + Math.min(start + emailItems - 1, total) + ' of ' + total + ' email accounts</p>';
			html += '<ul class="pagination m-0 ms-auto">';
			html += '<li class="page-item' + (start === 1 ? ' disabled' : '') + '"><a class="page-link" href="#" data-start="' + Math.max(1, start - emailItems) + '">' + svgPrev + ' prev</a></li>';
			for (var p = 1; p <= pages; p++) {
				html += '<li class="page-item' + (p === curPage ? ' active' : '') + '"><a class="page-link" href="#" data-start="' + ((p - 1) * emailItems + 1) + '">' + p + '</a></li>';
			}
			html += '<li class="page-item' + ((start + emailItems) > total ? ' disabled' : '') + '"><a class="page-link" href="#" data-start="' + (start + emailItems) + '">next ' + svgNext + '</a></li>';
			html += '</ul>';
		} else {
			html = '<p class="m-0 text-muted">Total: ' + total + ' email account' + (total !== 1 ? 's' : '') + '.</p>';
		}
		$('#email-footer').html(html);
	}

	function showEmailPage(start) {
		emailStart = start;
		$('.email-row').each(function() {
			var idx = parseInt($(this).data('idx'));
			$(this).toggle(idx >= emailStart && idx < emailStart + emailItems);
		});
		renderEmailFooter(emailTotal, emailStart);
	}

	$('#email-footer').on('click', '.page-link', function(e) {
		e.preventDefault();
		if ($(this).closest('.page-item').hasClass('disabled')) return;
		showEmailPage(parseInt($(this).data('start')));
	});

	$('#email-search').on('input', function() {
		$('#email-search-clear').toggle($(this).val() !== '');
	});

	$('#email-search').on('keydown', function(e) {
		if (e.key !== 'Enter') return;
		var q = $(this).val().toLowerCase().trim();
		if (q === '') { clearEmailSearch(); return; }
		var shown = 0;
		$('.email-row').each(function() {
			var matches = $(this).data('email').toLowerCase().indexOf(q) !== -1;
			$(this).toggle(matches);
			if (matches) shown++;
		});
		$('#email-footer').html('<p class="m-0 text-muted">' + shown + ' result' + (shown !== 1 ? 's' : '') + ' for &ldquo;' + $('<span>').text(q).html() + '&rdquo;</p>');
	});

	$('#email-search-clear').on('click', clearEmailSearch);

	function clearEmailSearch() {
		$('#email-search').val('');
		$('#email-search-clear').hide();
		showEmailPage(emailStart);
	}
	$("#modal-create-email").on('shown.bs.modal', function() {
		$('#user').focus();
	});
	$("#create-email").submit(function(event) {
		event.preventDefault();
		if ($('#create-email')[0].checkValidity() === false) {
			event.stopPropagation();
			if(!$('#user').is(':valid')) {
				$('#user').focus();
			} else if(!$('#password').is(':valid')) {
				$('#password').focus();
			}
		} else if($('#user').is(':valid')) {
			jQuery.ajax({
				method: "POST",
				url: "./ajax-email/",
				data: { action: 'ajax-email', user: $('#user').val(), domain: $('#domain').val() }
			}).done(function( msg ) {
				if(msg != '') {
					$('#invalid-email').html(msg);
					$('#user').addClass('is-invalid');
					$('#user').removeClass('was-validated');
					$("#create-email").removeClass('was-validated');
				} else {
					$('#invalid-email').html('');
					$('#user').removeClass('is-invalid');
					$('#user').addClass('was-validated');
					$("#create-email").addClass('was-validated');
					$('#submit-btn').prop('disabled', true);
					$("#create-email").unbind('submit').submit();
				}
			});
		}
  	});

	$('#edit-email').on('show.bs.modal', function (event) {
		// Button that triggered the modal
		var button = event.relatedTarget;
		// Extract info from data-bs-* attributes
		var email = button.getAttribute('data-bs-email');
		$('#email-edit').val(email);
		$('#email-title').html(email);
		$('#email-title2').html(email);
		$('#password2').val('');
		// The toggle reflects what is on file; the password field stays empty and
		// optional, so the form can be submitted to flip the toggle alone.
		$('#login-enabled').prop('checked', button.getAttribute('data-bs-enabled') !== '0')
		                   .trigger('change');
	// No per-mailbox quota is stored anywhere (the disk_quota block in
	// edit_email.php is commented out and the column went with db/1032.sql), so
	// the slider keeps its markup default instead of loading a value that does
	// not exist. It previously read $row["disk_quota"], which was undefined here.
  	});

	/* The switch is labelled with the object ("Email account"); the word next to
	   it carries the state, in the same Active/Disabled wording as the Status
	   column, so the label reads correctly in both positions. */
	$('#login-enabled').on('change', function() {
		var on = $(this).is(':checked');
		$('#login-enabled-state')
			.text(on ? 'Active' : 'Disabled')
			.attr('class', 'badge ms-2 ' + (on ? 'bg-green-lt border' : 'bg-red-lt border'));
		$('#login-enabled-label').attr('class', (on ? '' : 'text-red'));
	});

	$("#edit-email").submit(function(event) {
		console.log('submit');
		event.preventDefault();
		if ($('#edit-email')[0].checkValidity() === false) {
			event.stopPropagation();
			if(!$('#password2').is(':valid')) {
				$('#password2').focus();
			}
		} else if($('#password2').is(':valid')) {
			$('#submit-btn2').prop('disabled', true);
			$("#edit-email").unbind('submit').submit();
		}
  	});

	$('#modal-client-settings').on('show.bs.modal', function (event) {
		var button = event.relatedTarget;
		var email  = button.getAttribute('data-bs-email');
		$(this).find('.cs-email').text(email);
		$(this).find('.cs-host').text(button.getAttribute('data-bs-host'));
		$('#cs-mobileconfig').attr('href', '/?action=ajax-mobileconfig&email=' + encodeURIComponent(email));
	});

	$('#delete-email').on('show.bs.modal', function (event) {
		//console.log(event);
		// Button that triggered the modal
		var button = event.relatedTarget;
		// Extract info from data-bs-* attributes
		var email = button.getAttribute('data-bs-email');
		console.log(email);
		$('#email-delete').val(email);
		$('#email-title3').html(email);
  	});

	$("#delete-email").submit(function(event) {
	//	event.preventDefault();
	//	event.stopPropagation();
		$('#submit-btn3').prop('disabled', true);
		$("#delete-email").unbind('submit').submit();
  	});

	/* Webmail auto-login. The ticket is minted on click and redeemed by the very
	   next request, so it is fetched here rather than baked into the page: a
	   token printed at render time would already have expired by the time anyone
	   clicked it, and would sit in the HTML for every mailbox at once.

	   The token is handed over as a form POST, never in the URL -- a query
	   string ends up in the nginx access log, the browser history, and any
	   Referer the webmail page later sends.

	   The target tab is opened SYNCHRONOUSLY inside the click handler and only
	   then submitted into once the AJAX returns: a window opened from an async
	   callback has lost the user gesture and is blocked as a popup. */
	var wmSeq = 0;

	$('#email-tbody, table').on('click', '.wm-login', function (e) {
		e.preventDefault();
		var $b = $(this);
		if ($b.data('busy')) return;
		$b.data('busy', true);

		var name = 'reqad_webmail_' + (++wmSeq);
		var win  = window.open('', name);        // still inside the gesture

		$.post('/?ajax=1', { action: 'ajax-webmail-ticket', email: $b.data('email') }, null, 'json')
			.done(function (res) {
				$b.data('busy', false);
				if (!res || res.error || !res.token) {
					if (win) win.close();
					alert(res && res.error ? res.error : 'Could not start the webmail session.');
					return;
				}
				$('<form>')
					.attr({ method: 'post', action: res.url, target: name })
					.append($('<input>').attr({ type: 'hidden', name: '_reqad_login', value: res.token }))
					.appendTo(document.body)
					.submit()
					.remove();
			})
			.fail(function () {
				$b.data('busy', false);
				if (win) win.close();
				alert('Could not start the webmail session. Please try again.');
			});
	});

});
</script>
</body>
</html>
