<?php
    include('templates/header.php');

	$crons = array();

	// When root_access is disabled, root's crontab (/etc/crontab and root's
	// user crontab) must be hidden and off-limits — /etc/crontab runs commands
	// as root, so exposing it is a privilege escalation. Defaults ON when absent
	// (matching add_ssh_key.php / delete_ssh_key.php).
	$root_access = isset($ini['root_access']) ? (int)$ini['root_access'] : 1;

	$cron_users = $root_access ? array('root') : array();
	$q = $db->query('SELECT user FROM accounts ORDER BY user');
	while ($ur = $q->fetchArray()) { $cron_users[] = $ur["user"]; }

	$i=0;

	// /etc/crontab is root-owned system config — only show/manage it when root
	// access is granted.
	if ($root_access) {
    $cron_list = `sudo cat /etc/crontab | egrep -v '^#' | egrep -v '^$' | egrep -v '^SHELL=' | egrep -v '^PATH=' | egrep -v '^MAILTO='`;
    $cron_list = explode("\n", trim($cron_list));
	# echo '<pre>'; print_r($cron_list); exit;

	foreach($cron_list as $j => $cron0) {
		$i++;
		unset($cron1);
		$cron0 = trim($cron0);
		preg_match('/^(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(.*$)/', $cron0, $cron1);
		#echo '<pre>'; print_r($cron0); print_r($cron);
		if (count($cron1)>=6) {
			$crons[$i]["user"] 		= $cron1[6];
			$crons[$i]["date"] 		= $cron1[1].' '.$cron1[2].' '.$cron1[3].' '.$cron1[4].' '.$cron1[5];
			$crons[$i]["cmd"] 		= $cron1[7];
			$crons[$i]["global"] 	= true;
		} else {
			preg_match('/^(\S+)\s+(\S+)\s+(\S+)(.*$)/', $cron0, $cron1);
			#echo '<pre>'; print_r($cron0); print_r($cron1); echo '</pre>';
			if (count($cron1)>=4) {
				$crons[$i]["user"]      = $cron1[2];
				$crons[$i]["date"]      = $cron1[1];
				$crons[$i]["cmd"]       = $cron1[3];
				$crons[$i]["global"]    = true;
			}
		}
	}
	}
	#echo '<pre>'; print_r($crons); exit;

	$cron_list = `sudo /usr/local/reqad/scripts/list_user_cron.sh`;
	#echo '<pre>'; print_r($cron_list);
	if(trim($cron_list)!='') {
		$cron_list = explode("\n", trim($cron_list));

		foreach($cron_list as $j => $cron0) {
            unset($cron1);
            $cron0 = trim($cron0);
            // Skip root's user crontab when root access is disabled.
            if (!$root_access && preg_match('/^root\s/', $cron0)) { continue; }
            $i++;
            preg_match('/^(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(.*$)/', $cron0, $cron1);
            #echo '<pre>'; print_r($cron0); print_r($cron1); echo '</pre>';
            if (count($cron1)>=6) {
                $crons[$i]["user"]      = $cron1[1];
                $crons[$i]["date"]      = $cron1[2].' '.$cron1[3].' '.$cron1[4].' '.$cron1[5].' '.$cron1[6];
                $crons[$i]["cmd"]       = $cron1[7];
                $crons[$i]["global"]    = false;
            } else {
                preg_match('/^(\S+)\s+(\S+)\s+(\S+)(.*$)/', $cron0, $cron1);
                #echo '<pre>'; print_r($cron0); print_r($cron1); echo '</pre>';
                if (count($cron1)>=4) {
                    $crons[$i]["user"]      = $cron1[1];
                    $crons[$i]["date"]      = $cron1[2];
                    $crons[$i]["cmd"]       = $cron1[3];
                    $crons[$i]["global"]    = false;
                }
            }
        }

	}

	#rsort($crons);
	#echo '<pre>'; print_r($crons); exit;

?>
        <!-- Page title -->
        <div class="page-header d-print-none">
            <div class="row align-items-center">
            	<div class="col" style="padding-left:22px;">
					<!-- Page pre-title -->
					<div class="page-pretitle">
						CRON
					</div>
					<h2 class="page-title" style="white-space:nowrap">
						List Cron Jobs
					</h2>
					<div style="min-width:250px">
						Cron service: 
					<?
						$crond_status = trim(shell_exec("systemctl is-active crond 2>/dev/null"));
						if($crond_status == 'active') {
					?>
						<span class="badge bg-success sm" style="position:relative;top:-2px;">running</span>
					<? } else { ?>
						<span class="badge bg-danger sm" style="position:relative;top:-2px;"><?=htmlspecialchars($crond_status)?></span>
					<? } ?>
					</div>
              	</div>
              	<div class="col-auto ms-auto d-print-none">
	                <div class="btn-list">
                  		<a href="#" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal-create-cron">
                    		<svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
	                    	Add&nbsp;<span class="d-none d-sm-inline">a new</span>&nbsp;Cron Job
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
<?	if(count($crons) == 0) { ?>
		<p style="padding:14px;">There are no crons on this server.</p>
<? 	} ?>
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
                        <th style="background-color:#DEF;">Date</th>
                        <th style="background-color:#DEF;">User</th>
                        <th style="background-color:#DEF;">Command</th>
                        <th class="w-1" style="background-color:#DEF;"></th>
                      </tr>
                    </thead>
                    <tbody>
                    <?
						foreach($crons as $i => $cron) {
                    ?>
                      <tr>
                        <td data-label="ID">
                          <div class="d-flex">
                            <div class="flex-fill">
                              <div class="text-muted" style="text-align:right;"><?=$i;?>.</div>
                            </div>
                          </div>
                        </td>
                        <td data-label="Date">
                          <div class="d-flex py-1 align-items-center">
                            <div class="flex-fill">
                              <div class="font-weight-medium" style="font-family:monospace;white-space:nowrap;"><?=$cron["date"];?></div>
                            </div>
                          </div>
                        </td>
                        <td data-label="User">
                          <div class="d-flex py-1 align-items-center">
                            <div class="flex-fill">
                              <div class="font-weight-medium"><span class="badge <?=$cron["user"]=='root'?' bg-lime':' bg-cyan';?>"><?=$cron["user"];?></span></div>
                            </div>
                          </div>
                        </td>
                        <td data-label="Cmd">
                          <div class="d-flex py-1 align-items-center">
                            <div class="flex-fill">
                              <div class="font-weight-medium" style="font-family:monospace;white-space:nowrap;"><?=$cron["cmd"];?></div>
                            </div>
                          </div>
                        </td>
                        <td>
                          <?
                            $sched_parts = explode(' ', $cron["date"]);
                            $orig_line = $cron["global"] ? $cron["date"].' '.$cron["user"].' '.$cron["cmd"] : $cron["date"].' '.$cron["cmd"];
                            $cron_type = $cron["global"] ? 'global' : 'user';
                          ?>
                          <div class="btn-list flex-nowrap">
                            <a href="#" class="btn btn-white btn-md" data-bs-toggle="modal" data-bs-target="#modal-edit-cron"
                               data-bs-orig-line="<?=htmlspecialchars($orig_line, ENT_QUOTES);?>"
                               data-bs-cron-type="<?=$cron_type;?>"
                               data-bs-cron-user="<?=htmlspecialchars($cron["user"], ENT_QUOTES);?>"
                               data-bs-cron-min="<?=htmlspecialchars($sched_parts[0] ?? '*', ENT_QUOTES);?>"
                               data-bs-cron-hour="<?=htmlspecialchars($sched_parts[1] ?? '*', ENT_QUOTES);?>"
                               data-bs-cron-dom="<?=htmlspecialchars($sched_parts[2] ?? '*', ENT_QUOTES);?>"
                               data-bs-cron-mon="<?=htmlspecialchars($sched_parts[3] ?? '*', ENT_QUOTES);?>"
                               data-bs-cron-dow="<?=htmlspecialchars($sched_parts[4] ?? '*', ENT_QUOTES);?>"
                               data-bs-cron-cmd="<?=htmlspecialchars($cron["cmd"], ENT_QUOTES);?>">Edit</a>
                            <a href="#" class="btn btn-white btn-md" data-bs-toggle="modal" data-bs-target="#modal-delete-cron"
                               data-bs-cron-line="<?=htmlspecialchars($orig_line, ENT_QUOTES);?>"
                               data-bs-cron-type="<?=$cron_type;?>"
                               data-bs-cron-user="<?=htmlspecialchars($cron["user"], ENT_QUOTES);?>"
                               data-bs-cron-display="<?=htmlspecialchars($cron["cmd"], ENT_QUOTES);?>">Delete</a>
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
                            <a href="#" class="btn btn-white btn-md">
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

            </div>
          </div>
        </div>
      </div>
    </div>

	<form method="post" action="/" id="create-cron" class="needs-validation" novalidate>
    <input type="hidden" name="action" value="create-cron">
    <div class="modal modal-blur fade" id="modal-create-cron" tabindex="-1" role="dialog" aria-hidden="true">
      <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Add a new Cron Job</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div class="mb-3">
              <label class="form-label">User</label>
              <select class="form-select" name="cron_user" required>
                <option value="" disabled selected>— select a user —</option>
                <? foreach($cron_users as $cu) { ?>
                <option value="<?=htmlspecialchars($cu);?>"><?=htmlspecialchars($cu);?></option>
                <? } ?>
              </select>
              <div class="invalid-feedback">Please select a user.</div>
            </div>
            <div class="mb-3">
              <label class="form-label">Schedule</label>
              <table style="width:100%;font-family:monospace;">
                <thead>
                  <tr>
                    <th class="text-muted" style="font-weight:normal;font-size:11pt;padding-bottom:4px;">Minute</th>
                    <th class="text-muted" style="font-weight:normal;font-size:11pt;padding-bottom:4px;">Hour</th>
                    <th class="text-muted" style="font-weight:normal;font-size:11pt;padding-bottom:4px;">Day of Month</th>
                    <th class="text-muted" style="font-weight:normal;font-size:11pt;padding-bottom:4px;">Month</th>
                    <th class="text-muted" style="font-weight:normal;font-size:11pt;padding-bottom:4px;">Day of Week</th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td style="padding-right:6px;"><input type="text" class="form-control" name="cron_min"  placeholder="*" value="*" required pattern="[\d\*\/\-\,]+" maxlength="20"></td>
                    <td style="padding-right:6px;"><input type="text" class="form-control" name="cron_hour" placeholder="*" value="*" required pattern="[\d\*\/\-\,]+" maxlength="20"></td>
                    <td style="padding-right:6px;"><input type="text" class="form-control" name="cron_dom"  placeholder="*" value="*" required pattern="[\d\*\/\-\,]+" maxlength="20"></td>
                    <td style="padding-right:6px;"><input type="text" class="form-control" name="cron_mon"  placeholder="*" value="*" required pattern="[\d\*\/\-\,]+" maxlength="20"></td>
                    <td><input type="text" class="form-control" name="cron_dow"  placeholder="*" value="*" required pattern="[\d\*\/\-\,]+" maxlength="20"></td>
                  </tr>
                </tbody>
              </table>
              <small class="form-text text-muted">Use * for any, */n for every n, ranges (1-5), or lists (1,3,5).</small>
              <div style="margin-top:8px;">
                <small class="text-muted">Examples: </small>
                <a href="#" class="cron-example badge bg-azure-lt" data-min="*"    data-hour="*"  data-dom="*" data-mon="*" data-dow="*">every minute</a>
                <a href="#" class="cron-example badge bg-azure-lt" data-min="*/5"  data-hour="*"  data-dom="*" data-mon="*" data-dow="*">every 5 min</a>
                <a href="#" class="cron-example badge bg-azure-lt" data-min="0"    data-hour="*"  data-dom="*" data-mon="*" data-dow="*">every hour</a>
                <a href="#" class="cron-example badge bg-azure-lt" data-min="0"    data-hour="0"  data-dom="*" data-mon="*" data-dow="*">every day at midnight</a>
                <a href="#" class="cron-example badge bg-azure-lt" data-min="0"    data-hour="12" data-dom="*" data-mon="*" data-dow="*">every day at noon</a>
                <a href="#" class="cron-example badge bg-azure-lt" data-min="0"    data-hour="0"  data-dom="*" data-mon="*" data-dow="1">every Monday</a>
                <a href="#" class="cron-example badge bg-azure-lt" data-min="0"    data-hour="0"  data-dom="1" data-mon="*" data-dow="*">every 1st of month</a>
                <a href="#" class="cron-example badge bg-azure-lt" data-min="0"    data-hour="0"  data-dom="1" data-mon="1" data-dow="*">every year (Jan 1st)</a>
              </div>
            </div>
            <div class="mb-3">
              <label class="form-label">Command</label>
              <input type="text" class="form-control" name="cron_cmd" placeholder="/usr/local/reqad/scripts/example.sh" required maxlength="512" autocomplete="off" style="font-family:monospace;">
              <div class="invalid-feedback">Please enter a command.</div>
              <div style="margin-top:8px;font-size:11pt;line-height:2;">
                <div><span class="text-muted">Absolute path:</span> <code>/usr/bin/php /home/user/script.php</code></div>
                <div><span class="text-muted">Redirect output</span> <span class="text-muted">(suppress email):</span> <code>/usr/local/bin/script.sh &gt;&gt; /var/log/mycron.log 2&gt;&amp;1</code></div>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <a href="#" class="btn btn-link link-secondary" data-bs-dismiss="modal">Cancel</a>
            <button class="btn btn-primary" type="submit">
              <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
              Add Cron Job
            </button>
          </div>
        </div>
      </div>
    </div>
</form>

<form method="post" action="/" id="edit-cron" class="needs-validation" novalidate>
    <input type="hidden" name="action" value="edit-cron">
    <input type="hidden" name="orig_line" id="edit-orig-line" value="">
    <input type="hidden" name="cron_type" id="edit-cron-type" value="">
    <input type="hidden" name="cron_user" id="edit-cron-user" value="">
    <div class="modal modal-blur fade" id="modal-edit-cron" tabindex="-1" role="dialog" aria-hidden="true">
      <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Edit Cron Job</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div class="mb-3">
              <label class="form-label">User</label>
              <input type="text" class="form-control" id="edit-cron-user-display" readonly style="font-family:monospace;background:#f4f6fa;">
            </div>
            <div class="mb-3">
              <label class="form-label">Schedule</label>
              <table style="width:100%;font-family:monospace;">
                <thead>
                  <tr>
                    <th class="text-muted" style="font-weight:normal;font-size:11pt;padding-bottom:4px;">Minute</th>
                    <th class="text-muted" style="font-weight:normal;font-size:11pt;padding-bottom:4px;">Hour</th>
                    <th class="text-muted" style="font-weight:normal;font-size:11pt;padding-bottom:4px;">Day of Month</th>
                    <th class="text-muted" style="font-weight:normal;font-size:11pt;padding-bottom:4px;">Month</th>
                    <th class="text-muted" style="font-weight:normal;font-size:11pt;padding-bottom:4px;">Day of Week</th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td style="padding-right:6px;"><input type="text" class="form-control" name="cron_min"  required pattern="[\d\*\/\-\,]+" maxlength="20"></td>
                    <td style="padding-right:6px;"><input type="text" class="form-control" name="cron_hour" required pattern="[\d\*\/\-\,]+" maxlength="20"></td>
                    <td style="padding-right:6px;"><input type="text" class="form-control" name="cron_dom"  required pattern="[\d\*\/\-\,]+" maxlength="20"></td>
                    <td style="padding-right:6px;"><input type="text" class="form-control" name="cron_mon"  required pattern="[\d\*\/\-\,]+" maxlength="20"></td>
                    <td><input type="text" class="form-control" name="cron_dow"  required pattern="[\d\*\/\-\,]+" maxlength="20"></td>
                  </tr>
                </tbody>
              </table>
              <small class="form-text text-muted">Use * for any, */n for every n, ranges (1-5), or lists (1,3,5).</small>
            </div>
            <div class="mb-3">
              <label class="form-label">Command</label>
              <input type="text" class="form-control" name="cron_cmd" required maxlength="512" autocomplete="off" style="font-family:monospace;">
              <div class="invalid-feedback">Please enter a command.</div>
            </div>
          </div>
          <div class="modal-footer">
            <a href="#" class="btn btn-link link-secondary" data-bs-dismiss="modal">Cancel</a>
            <button id="edit-cron-submit-btn" class="btn btn-primary" type="submit">Save changes</button>
          </div>
        </div>
      </div>
    </div>
</form>

<form method="post" action="/" id="delete-cron">
    <input type="hidden" name="action" value="delete-cron">
    <input type="hidden" name="cron_line" id="delete-cron-line" value="">
    <input type="hidden" name="cron_type" id="delete-cron-type" value="">
    <input type="hidden" name="cron_user" id="delete-cron-user" value="">
    <div class="modal modal-blur fade" id="modal-delete-cron" tabindex="-1" role="dialog" aria-hidden="true">
      <div class="modal-dialog modal-sm modal-dialog-centered" role="document">
        <div class="modal-content">
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          <div class="modal-status bg-danger"></div>
          <div class="modal-body text-center py-4">
            <svg xmlns="http://www.w3.org/2000/svg" class="icon mb-2 text-danger icon-lg" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="#ff2825" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="12" cy="12" r="9" /><line x1="12" y1="8" x2="12" y2="12" /><line x1="12" y1="16" x2="12.01" y2="16" /></svg>
            <h3>Delete cron job?</h3>
            <div class="text-muted" style="font-family:monospace;font-size:11pt;word-break:break-all;" id="delete-cron-display"></div>
          </div>
          <div class="modal-footer">
            <div class="w-100">
              <div class="row">
                <div class="col"><a href="#" class="btn btn-white w-100" data-bs-dismiss="modal">Cancel</a></div>
                <div class="col"><button class="btn btn-danger w-100" type="submit">Delete</button></div>
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
    $('#modal-delete-cron').on('show.bs.modal', function(event) {
        var btn = event.relatedTarget;
        $('#delete-cron-line').val(btn.getAttribute('data-bs-cron-line'));
        $('#delete-cron-type').val(btn.getAttribute('data-bs-cron-type'));
        $('#delete-cron-user').val(btn.getAttribute('data-bs-cron-user'));
        $('#delete-cron-display').text(btn.getAttribute('data-bs-cron-display'));
    });

    // Populate edit modal
    $('#modal-edit-cron').on('show.bs.modal', function (event) {
        var btn = event.relatedTarget;
        $('#edit-orig-line').val(btn.getAttribute('data-bs-orig-line'));
        $('#edit-cron-type').val(btn.getAttribute('data-bs-cron-type'));
        $('#edit-cron-user').val(btn.getAttribute('data-bs-cron-user'));
        $('#edit-cron-user-display').val(btn.getAttribute('data-bs-cron-user'));
        $('#edit-cron [name="cron_min"]').val(btn.getAttribute('data-bs-cron-min'));
        $('#edit-cron [name="cron_hour"]').val(btn.getAttribute('data-bs-cron-hour'));
        $('#edit-cron [name="cron_dom"]').val(btn.getAttribute('data-bs-cron-dom'));
        $('#edit-cron [name="cron_mon"]').val(btn.getAttribute('data-bs-cron-mon'));
        $('#edit-cron [name="cron_dow"]').val(btn.getAttribute('data-bs-cron-dow'));
        $('#edit-cron [name="cron_cmd"]').val(btn.getAttribute('data-bs-cron-cmd'));
        $('#edit-cron').removeClass('was-validated');
        $('#edit-cron input').removeClass('is-invalid');
    });

    // Reset edit form when modal closes
    $('#modal-edit-cron').on('hidden.bs.modal', function () {
        $('#edit-cron')[0].reset();
        $('#edit-cron').removeClass('was-validated');
        $('#edit-cron input').removeClass('is-invalid');
    });

    // Validate edit form on submit
    $('#edit-cron').submit(function (event) {
        event.preventDefault();
        var form = this;

        if (!form.checkValidity()) {
            event.stopPropagation();
            $(form).addClass('was-validated');
            return;
        }

        var schedFields = [
            { name: 'cron_min',  min: 0,  max: 59 },
            { name: 'cron_hour', min: 0,  max: 23 },
            { name: 'cron_dom',  min: 1,  max: 31 },
            { name: 'cron_mon',  min: 1,  max: 12 },
            { name: 'cron_dow',  min: 0,  max: 7  }
        ];
        var schedValid = true;
        var schedPattern = /^[\d\*\/\-\,]+$/;
        schedFields.forEach(function (field) {
            var input = $('#edit-cron [name="' + field.name + '"]');
            var val = input.val().trim();
            var ok = true;
            if (!schedPattern.test(val)) {
                ok = false;
            } else if (/^\d+$/.test(val)) {
                var n = parseInt(val, 10);
                if (n < field.min || n > field.max) ok = false;
            }
            if (!ok) { input.addClass('is-invalid'); schedValid = false; }
            else { input.removeClass('is-invalid'); }
        });
        if (!schedValid) return;

        if ($('#edit-cron [name="cron_cmd"]').val().trim() === '') {
            $('#edit-cron [name="cron_cmd"]').addClass('is-invalid');
            return;
        }

        $('#edit-cron-submit-btn').prop('disabled', true).text('Saving…');
        $(form).unbind('submit').submit();
    });

    // Cron examples — click to populate schedule fields
    $(document).on('click', '.cron-example', function (e) {
        e.preventDefault();
        $('[name="cron_min"]').val($(this).data('min'));
        $('[name="cron_hour"]').val($(this).data('hour'));
        $('[name="cron_dom"]').val($(this).data('dom'));
        $('[name="cron_mon"]').val($(this).data('mon'));
        $('[name="cron_dow"]').val($(this).data('dow'));
        $('[name="cron_min"], [name="cron_hour"], [name="cron_dom"], [name="cron_mon"], [name="cron_dow"]').removeClass('is-invalid');
    });

    // Reset create form when modal closes
    $('#modal-create-cron').on('hidden.bs.modal', function () {
        $('#create-cron')[0].reset();
        $('#create-cron').removeClass('was-validated');
    });

    // Validate create form on submit
    $('#create-cron').submit(function (event) {
        event.preventDefault();
        var form = this;

        if (!form.checkValidity()) {
            event.stopPropagation();
            $(form).addClass('was-validated');
            return;
        }

        // Validate each schedule field against cron syntax
        var schedFields = [
            { name: 'cron_min',  min: 0,  max: 59 },
            { name: 'cron_hour', min: 0,  max: 23 },
            { name: 'cron_dom',  min: 1,  max: 31 },
            { name: 'cron_mon',  min: 1,  max: 12 },
            { name: 'cron_dow',  min: 0,  max: 7  }
        ];
        var schedValid = true;
        var schedPattern = /^[\d\*\/\-\,]+$/;
        schedFields.forEach(function (field) {
            var input = $('[name="' + field.name + '"]');
            var val = input.val().trim();
            var ok = true;
            if (!schedPattern.test(val)) {
                ok = false;
            } else if (/^\d+$/.test(val)) {
                // single plain number — check range
                var n = parseInt(val, 10);
                if (n < field.min || n > field.max) ok = false;
            }
            if (!ok) {
                input.addClass('is-invalid');
                schedValid = false;
            } else {
                input.removeClass('is-invalid');
            }
        });
        if (!schedValid) return;

        if ($('[name="cron_user"]').val() === '') {
            $('[name="cron_user"]').addClass('is-invalid');
            return;
        }
        $('[name="cron_user"]').removeClass('is-invalid');

        if ($('[name="cron_cmd"]').val().trim() === '') {
            $('[name="cron_cmd"]').addClass('is-invalid');
            return;
        }
        $('[name="cron_cmd"]').removeClass('is-invalid');

        $(form).unbind('submit').submit();
    });
});
</script>
</body>
</html>
