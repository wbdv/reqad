<?php
    include('templates/header.php');
	$mysql_databases = array();
	$mysql_array = @json_decode(@json_encode(simplexml_load_string(shell_exec("sudo mysql --xml=true -e 'SHOW DATABASES'"))), true);
	foreach($mysql_array["row"] as $mysql_array2) {
		$mysql_databases[] = $mysql_array2["field"];
	}
	#print_r($mysql_databases); #exit;
	$mysql_databases_size = array();
	$mysql_array = @json_decode(@json_encode(simplexml_load_string(shell_exec("sudo mysql --xml=true -e 'SELECT table_schema AS dbname, ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS dbsize FROM information_schema.TABLES GROUP BY table_schema'"))), true);
	#echo '<pre>'; print_r($mysql_array); exit;
	foreach($mysql_array["row"] as $mysql_array2) {
		$dbname = $mysql_array2["field"][0];
		$mysql_databases_size[$dbname] = $mysql_array2["field"][1];
	}
	#echo '<pre>'; print_r($mysql_databases_size); exit;

	$mysql_users = array();
	$mysql_array = @json_decode(@json_encode(simplexml_load_string(shell_exec("sudo mysql --xml=true -e 'SELECT DISTINCT Db,User FROM mysql.db'"))), true);
	#echo '<pre>'; print_r($mysql_array); exit;
    if(!empty($mysql_array["row"])) {
		if(count($mysql_array["row"])==1) {
			$mysql_array2=$mysql_array["row"];
			#echo '<pre>'; print_r($mysql_array2); exit;
			$dbname = $mysql_array2["field"][0];
			$mysql_users[$dbname] = $mysql_array2["field"][1];
		} else {
			foreach($mysql_array["row"] as $mysql_array2) {
				#echo '<pre>'; print_r($mysql_array2); exit;
				$dbname = $mysql_array2["field"][0];
				$mysql_users[$dbname] = $mysql_array2["field"][1];
			}
		}
	}

	$user_databases = array();
	$users = array();
	$q = $db->query('SELECT user FROM accounts');
	while ($row = $q->fetchArray()) {
		$user = $row["user"];
//		if(preg_match('/[a-z]+[a-z0-9]{1,7}/', $user)!==false) {
//			continue;
//		}
		$users[] = $user;
		foreach($mysql_databases as $dbname) {
			if( substr($dbname, 0, strlen($user)+1) == $user.'_' ) {
				$user_databases[$user][] = $dbname;
			}
		}
	}

	#echo '<pre>'; print_r($mysql_databases); print_r($dbs); exit;
	#echo '<pre>'; print_r($user_databases); print_r($dbs); exit;
	#echo '<pre>'; print_r($users); print_r($dbs); exit;

	$hide_databases = array('information_schema', 'mysql', 'performance_schema', 'phpmyadmin', 'roundcube', 'sys');
	foreach($mysql_databases as $dbname) {
		if(!in_array($dbname, $hide_databases)) {
			$db_found = false;
			foreach($user_databases as $tmp_user => $tmp_databases)
				if(in_array($dbname, $tmp_databases))
					$db_found = true;
			if(!$db_found)
				$user_databases['<i>unassigned</i>'][] = $dbname;
		}
	}

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
                  List <span class="d-none d-sm-inline">&nbsp;MySQL / MariaDB&nbsp;</span> Databases
                </h2>
				<div style="min-width:250px">
					Server: 
				<?	
					#echo $ini["mariadb"];
					$dbversion = trim(shell_exec("sudo mysql -NB -e \"SELECT VERSION()\""));
					echo $dbversion
				?>
				
                <?
                    $uptime = (int)(trim(shell_exec("sudo mysql -NB -e \"SHOW STATUS WHERE Variable_name='Uptime'\" | awk {'print $2'}")));
                    if($uptime>0) {
                ?>
                    <span class="badge bg-success sm" style="position:relative;top:-2px;">up and running</span> &nbsp; <span class="d-none d-sm-inline">Uptime: <?=$uptime>86400?round($uptime/86400,1).' days':($uptime>3600?round($uptime/3600,1).' hours':round($uptime/60,1).' minutes');?></span>
				<? } else { ?>
					<span class="badge bg-danger sm" style="position:relative;top:-2px;">not running</span>
				<? } ?>
				</div>
              </div>
              <div class="col-auto ms-auto d-print-none">
                <div class="btn-list">
                  <a href="#" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal-create-db">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    Create <span class="d-none d-sm-inline">&nbsp;a new&nbsp;</span> database
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

<?	if(count($user_databases) == 0 ) { ?>
		<p style="padding:14px;">There are no databases created on this server.</p>
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
                        <th style="background-color:#DEF;">Database Name</th>
                        <th style="background-color:#DEF;">Database User</th>
                        <th style="background-color:#DEF;">System User</th>
                        <th style="background-color:#DEF;">Size</th>
<!--
                        <th style="background-color:#DEF;">Host</th>
                        <th style="background-color:#DEF;">Tables</th>
                        <th style="background-color:#DEF;">Collation</th>
-->
                        <th style="background-color:#DEF;width:20%;max-width:250px;">Operation</th>
                        <th class="w-1" style="background-color:#DEF;"></th>
                      </tr>
                    </thead>
                    <tbody>
                    <?
                    $i = 0;
                    foreach($user_databases as $user => $dbs) {
						foreach($dbs as $dbname) {
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
                              <div class="font-weight-medium"><?=$dbname;?></div>
                            </div>
                          </div>
                        </td>
                        <td data-label="Domain">
                          <div class="d-flex py-1 align-items-center">
                            <div class="flex-fill">
                              <div class="font-weight-medium"><?=isset($mysql_users[$dbname])?$mysql_users[$dbname]:'-';?></div>
                            </div>
                          </div>
                        </td>
                        <td data-label="User">
                          <div class="d-flex py-1 align-items-center">
                            <div class="flex-fill">
                            <?=$user;?>
                            </div>
                          </div>
                        </td>
                        <td data-label="Size">
                          <div class="d-flex py-1 align-items-center">
                            <div class="flex-fill" style="white-space:nowrap">
                            <?=isset($mysql_databases_size[$dbname])?round($mysql_databases_size[$dbname],2):0;?> MB
                            </div>
                          </div>
                        </td>
<!--
                        <td data-label="Host">
                          <div class="d-flex py-1 align-items-center">
                            <div class="flex-fill">
                            localhost
                            </div>
                          </div>
                        </td>
                        <td data-label="Tables">
                          <div class="d-flex py-1 align-items-center">
                            <div class="flex-fill">
                            23
                            </div>
                          </div>
                        </td>
                        <td data-label="Collation">
                          <div class="d-flex py-1 align-items-center">
                            <div class="flex-fill">
                            utf8_general_ci	
                            </div>
                          </div>
                        </td>
-->
                        <td>
                          <div class="btn-list flex-nowrap">
                            <? if(isset($mysql_users[$dbname])) { ?>
                            <a href="#" class="btn btn-white btn-md" data-bs-toggle="modal" data-bs-target="#modal-change-db-password" data-bs-dbuser="<?=$mysql_users[$dbname];?>">Change password</a>
                            <? } ?>
                            <a href="#" class="btn btn-white btn-md" data-bs-toggle="modal" data-bs-target="#modal-delete-database" data-bs-database="<?=$dbname;?>">Delete</a>
                          </div>
                        </td>
                      </tr>
                      <? }} ?>
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
			<?  } ?>
            </div>
          </div>
        </div>
      </div>
    </div>

	<form method="post" action="/" id="create-db" class="needs-validation" novalidate>
    <input type="hidden" name="action" value="create-database">
    <div class="modal modal-blur fade" id="modal-create-db" tabindex="-1" role="dialog" aria-hidden="true">
      <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Create a new MySQL database and user</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <p>To create a new database, first select an account. The account username will be the database prefix. Database user@localhost will grant all permissions.</p>
            <div class="mb-3">
              	<label class="form-label">Username: (prefix)</label>
			  	<select name="user" id="user" class="form-select">
				  <option value=""></option>
					<? foreach ($users as $user) { ?>
					<option value="<?=$user;?>"><?=$user;?></option>
					<? } ?>
                </select>              
				<div class="invalid-feedback" id="invalid-user">
                		Please select an user. Database name and user will have this prefix.
          		</div>
            </div>
            <div class="mb-3">
              	<label class="form-label">MySQL host:</label>
			  	<div class="input-group">
				  	<span class="input-group-text">
						localhost
					</span>
				</div>
			</div>
            <div class="mb-3">
              	<label class="form-label">MySQL database:</label>
			  	<div class="input-group">
                    <span class="input-group-text" id="dbprefix1">
						<i>user_</i>
                    </span>
					<input type="text" class="form-control" name="dbname" id="dbname" placeholder="database" autocomplete="off" aria-describedby="userHelpBlock" required pattern="[a-z]+[a-z0-9]{1,7}" maxlength="8" autocomplete="off">
              		<small id="userHelpBlock" class="form-text text-muted" style="display:block;margin-top:8px;">
                		Database name must be unique, 2-8 characters long, contain letters and numbers, and must not contain spaces.
              		</small>
              		<div class="invalid-feedback" id="invalid-dbname">
                		Please enter a database name.
              		</div>
                </div>
            </div>
            <div class="mb-3">
              	<label class="form-label">MySQL user:</label>
			  	<div class="input-group">
                    <span class="input-group-text" id="dbprefix2">
						<i>user_</i>
                    </span>
					<input type="text" class="form-control" name="dbuser" id="dbuser" placeholder="dbuser" autocomplete="off" aria-describedby="userHelpBlock" required pattern="[a-z]+[a-z0-9]{1,7}" maxlength="8" autocomplete="off">
              		<small id="userHelpBlock" class="form-text text-muted" style="display:block;margin-top:8px;">
                		Database users must be unique, 2-8 characters long, contain letters and numbers, and must not contain spaces.
              		</small>
              		<div class="invalid-feedback" id="invalid-dbuser">
                		Please enter a database user name.
              		</div>
                </div>
            </div>
            <div class="row">
              <div class="col-lg-6">
                <div class="mb-3" id="pwd-container">
                  <label class="form-label">Password:</label>
                  <input type="text" class="form-control" name="password" id="password" autocomplete="off" aria-describedby="passwordHelpBlock" required pattern="[^ ]{8,24}" maxlength="24">
                  <div class="pwstrength_viewport_progress"></div>
                </div>
              </div>
              <div class="col-lg-6">
                <div class="mb-3 top27">
                <input type="button" value="Generate" class="btn btn-white" onClick="$('#password').val(genPass()).pwstrength('forceUpdate');$('#password').removeClass('is-invalid');">
                <input type="button" value="Hide password" class="btn btn-white" onClick="if($(this).val()=='Hide password') { $('#password').attr('type', 'password');$(this).val('Show password'); } else {$('#password').attr('type', 'text');$(this).val('Hide password'); }">
                </div>
              </div>
              <small id="passwordHelpBlock" class="form-text text-muted" style="display:block;margin-top:-8px;">
                Your password must be 8-24 characters long, contain letters and numbers, and must not contain spaces.
              </small>
            </div>
          </div>
          <div class="modal-footer">
            <a href="#" class="btn btn-link link-secondary" data-bs-dismiss="modal">
              Cancel
            </a>
            <button id="submit-btn" class="btn btn-primary" type="submit"><svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg> Create database</button>
          </div>
        </div>
      </div>
    </div>
</form>

<form method="post" action="/" id="change-db-password" class="needs-validation" novalidate>
    <input type="hidden" name="action" value="change-db-password">
    <input type="hidden" name="dbuser" id="chpwd-dbuser" value="">
    <div class="modal modal-blur fade" id="modal-change-db-password" tabindex="-1" role="dialog" aria-hidden="true">
      <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Change database user password</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div class="mb-3">
              <label class="form-label">Database user:</label>
              <div class="input-group">
                <span class="input-group-text" id="chpwd-dbuser-display"></span>
              </div>
            </div>
            <div class="row">
              <div class="col-lg-6">
                <div class="mb-3" id="chpwd-pwd-container">
                  <label class="form-label">New password:</label>
                  <input type="text" class="form-control" name="password" id="chpwd-password" autocomplete="off" aria-describedby="chpwdHelpBlock" required pattern="[^ ]{8,24}" maxlength="24">
                  <div class="pwstrength_viewport_progress"></div>
                </div>
              </div>
              <div class="col-lg-6">
                <div class="mb-3 top27">
                  <input type="button" value="Generate" class="btn btn-white" onClick="$('#chpwd-password').val(genPass()).pwstrength('forceUpdate');$('#chpwd-password').removeClass('is-invalid');">
                  <input type="button" value="Hide password" class="btn btn-white" id="chpwd-toggle-btn" onClick="if($(this).val()=='Hide password') { $('#chpwd-password').attr('type', 'password');$(this).val('Show password'); } else {$('#chpwd-password').attr('type', 'text');$(this).val('Hide password'); }">
                </div>
              </div>
              <small id="chpwdHelpBlock" class="form-text text-muted" style="display:block;margin-top:-8px;">
                Your password must be 8-24 characters long, contain letters and numbers, and must not contain spaces.
              </small>
            </div>
          </div>
          <div class="modal-footer">
            <a href="#" class="btn btn-link link-secondary" data-bs-dismiss="modal">Cancel</a>
            <button id="chpwd-submit-btn" class="btn btn-primary" type="submit">Change password</button>
          </div>
        </div>
      </div>
    </div>
</form>

<form method="post" action="/" id="delete-database" class="needs-validation" novalidate>
    <input type="hidden" name="action" value="delete-database">
    <input type="hidden" name="database" id="database-delete" value="">
    <div class="modal modal-blur fade" id="modal-delete-database" tabindex="-1" role="dialog" aria-hidden="true">
      <div class="modal-dialog modal-sm modal-dialog-centered" role="document">
        <div class="modal-content">
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          <div class="modal-status bg-danger"></div>
          <div class="modal-body text-center py-4">
            <svg xmlns="http://www.w3.org/2000/svg" class="icon mb-2 text-danger icon-lg" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="#ff2825" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="12" cy="12" r="9" /><line x1="12" y1="8" x2="12" y2="12" /><line x1="12" y1="16" x2="12.01" y2="16" /></svg>
            <h3>Delete database <span id="database-title2"></span></h3>
            <div class="text-muted">Do you really want to delete this database?</div>
          </div>
          <div class="modal-footer">
            <div class="w-100">
              <div class="row">
                <div class="col"><a href="#" class="btn btn-white w-100" data-bs-dismiss="modal">
                    Cancel
                </a></div>
                <div class="col"><button id="submit-btn2" class="btn btn-primary" type="submit">
                    Delete database
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
	$("#user").on("change", function(value){
		var user = $(this).val();
		if(user=='') {
			user = 'user';
			$("#dbprefix1").html('<i>user_</i>');
			$("#dbprefix2").html('<i>user_</i>');
		} else {
			$("#dbprefix1").text(user+'_');
			$("#dbprefix2").text(user+'_');
		}
   		
	});
	$("#create-db").submit(function(event) {
		event.preventDefault();
		if ($('#user').val()=='') {
			$('#invalid-user').html("Please selet a user.");
			$('#user').addClass('is-invalid');
			$('#user').removeClass('was-validated');
			$("#create-db").removeClass('was-validated');
		    event.stopPropagation();
		} else {
			$('#user').removeClass('is-invalid');
			$('#user').addClass('was-validated');
			if(!$('#dbname').is(':valid')) {
				$('#invalid-dbname').html("Please type a name for your database. Prefix will be "+$('#user').val()+"_");
				$('#dbname').addClass('is-invalid');
				$('#dbname').removeClass('was-validated');
				$("#create-db").removeClass('was-validated');
		    	event.stopPropagation();
			} else {
				jQuery.ajax({
					method: "POST",
					url: "./ajax-database/",
					data: { action: 'ajax-database', dbname: $('#user').val()+"_"+$('#dbname').val() }
				}).done(function( msg ) {
					if(msg != '') {
						$('#invalid-dbname').html(msg);
						$('#dbname').addClass('is-invalid');
						$('#dbname').removeClass('was-validated');
						$("#create-db").removeClass('was-validated');
						event.stopPropagation();
					} else {
						$('#dbname').removeClass('is-invalid');
						$('#dbname').addClass('was-validated');
						if(!$('#dbuser').is(':valid')) {
							$('#invalid-dbuser').html("Please type a user for your database. Prefix will be "+$('#user').val()+"_");
							$('#dbuser').addClass('is-invalid');
							$('#dbuser').removeClass('was-validated');
							$("#create-db").removeClass('was-validated');
							event.stopPropagation();
						} else {
							jQuery.ajax({
								method: "POST",
								url: "./ajax-database/",
								data: { action: 'ajax-database', dbname: $('#user').val()+"_"+$('#dbname').val(), dbuser: $('#user').val()+"_"+$('#dbuser').val() }
							}).done(function( msg ) {
								if(msg != '') {
									$('#invalid-dbuser').html(msg);
									$('#dbuser').addClass('is-invalid');
									$('#dbuser').removeClass('was-validated');
									$("#create-db").removeClass('was-validated');
									alert(msg);
									event.stopPropagation();
								} else {
									$('#dbuser').removeClass('is-invalid');
									$('#dbuser').addClass('was-validated');
									if ($('#password').val()=='') {
										$('#password').addClass('is-invalid');
										$('#password').removeClass('was-validated');
										$("#create-db").removeClass('was-validated');
										event.stopPropagation();
									} else {
										$('#password').removeClass('is-invalid');
										$('#password').addClass('was-validated');

										if ($('#create-db')[0].checkValidity() === false) {
											event.stopPropagation();
										} else {
											// console.log('all ok');
											$("#create-db").addClass('was-validated');
											$('#submit-btn').prop('disabled', true);
											$("#create-db").unbind('submit').submit();
										}
									}
								}
							});
						}
					}
				});
			}
		}

<? /*
		if ($('#create-db')[0].checkValidity() === false) {
			event.stopPropagation();
		} else {
		if($('#dbname').is(':valid')) {
			jQuery.ajax({
			method: "POST",
			url: "./ajax-database/",
			data: { action: 'ajax-database', dbname: $('#dbname').val() }
			}).done(function( msg ) {
			if(msg != '') {
				$('#invalid-dbname').html(msg);
				$('#dbname').addClass('is-invalid');
				$('#dbname').removeClass('was-validated');
				$("#create-db").removeClass('was-validated');
			} else {
				$('#invalid-dbname').html('');
				$('#dbname').removeClass('is-invalid');
				$('#dbname').addClass('was-validated');
				//console.log('domain ok');
				if ($('#dbname').is(':valid')) {
				jQuery.ajax({
					method: "POST",
					url: "./ajax-database/",
					data: { action: 'ajax-database', dbname: $('#dbname').val() }
				}).done(function( msg ) {
					if(msg != '') {
						$('#invalid-dbname').html(msg);
						$('#dbname').addClass('is-invalid');
						$('#dbname').removeClass('was-validated');
						$("#create-db").removeClass('was-validated');
					} else {
						$('#invalid-dbname').html('');
						$('#dbname').removeClass('is-invalid');
						$('#dbname').addClass('was-validated');
						// console.log('user ok');
						$("#create-db").addClass('was-validated');
						$('#submit-btn').prop('disabled', true);
						$("#create-db").unbind('submit').submit();
					}
				});
				}
			}
			});
		}
		}
*/ ?>
  	});

<? /*
	$('#edit-account').on('show.bs.modal', function (event) {
		// Button that triggered the modal
		var button = event.relatedTarget
		// Extract info from data-bs-* attributes
		var user = button.getAttribute('data-bs-user')
		var domain = button.getAttribute('data-bs-domain')
		$('#user-edit').val(user);
		$('#user-title').html(user);
		$('#domain-edit').val(domain);
	<? if(isset($ini["quota"]) && (int)($ini["quota"])>0) { ?>
		var disk_quota = button.getAttribute('data-bs-disk-quota')
		$('#diskquota-edit').val(disk_quota);
		$('#diskquota-edit').next().html(disk_quota + ' MB');
	<? } ?>
  	});
	
	$('#delete-account').on('show.bs.modal', function (event) {
		// Button that triggered the modal
		var button = event.relatedTarget
		// Extract info from data-bs-* attributes
		var user = button.getAttribute('data-bs-user')
		$('#user-delete').val(user);
		$('#user-title2').html(user);
  	});
	*/ ?>

    var chpwdOptions = {};
    chpwdOptions.ui = {
        container: "#chpwd-pwd-container",
        viewports: { progress: ".pwstrength_viewport_progress" },
        showVerdicts: false,
        showProgressBar: true,
        progressBarEmptyPercentage: 10,
        progressBarMinPercentage: 15
    };
    $('#chpwd-password').pwstrength(chpwdOptions);

    $('#modal-change-db-password').on('show.bs.modal', function (event) {
        var button = event.relatedTarget;
        var dbuser = button.getAttribute('data-bs-dbuser');
        $('#chpwd-dbuser').val(dbuser);
        $('#chpwd-dbuser-display').text(dbuser);
        $('#chpwd-password').val('').removeClass('is-invalid was-validated').attr('type', 'text');
        $('#chpwd-toggle-btn').val('Hide password');
    });

    $('#change-db-password').submit(function(event) {
        event.preventDefault();
        var pwd = $('#chpwd-password').val();
        if (!$('#chpwd-password')[0].checkValidity() || pwd === '') {
            $('#chpwd-password').addClass('is-invalid');
            event.stopPropagation();
            return;
        }
        $('#chpwd-password').removeClass('is-invalid');
        $('#chpwd-submit-btn').prop('disabled', true);
        $('#change-db-password').unbind('submit').submit();
    });

    $('#delete-database').on('show.bs.modal', function (event) {
        //console.log(event);
        // Button that triggered the modal
        var button = event.relatedTarget;
        // Extract info from data-bs-* attributes
        var database = button.getAttribute('data-bs-database');
        $('#database-delete').val(database);
        $('#database-title2').html(database);
    });

    $("#delete-database").submit(function(event) {
    //  event.preventDefault();
    //  event.stopPropagation();
        $('#submit-btn2').prop('disabled', true);
        $("#delete-database").unbind('submit').submit();
    });

});
</script>
</body>
</html>
