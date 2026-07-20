<?php
    include('templates/header.php'); 

#    $server_software = parse_ini_file(__DIR__.'/../../etc/server-software.ini');
#    echo '<pre>'; readfile(__DIR__.'/../../etc/server-software.ini'); exit;
#	$server_software = $ini;

	#$_services = ['nginx', 'crond', 'exim', 'mariadb', 'redis', 'lfd', 'php-fpm', 'php72-php-fpm', 'php82-php-fpm', 'sshd', 'monit', 'reqad', 'exim', 'dovecot', 'spamassassin', 'clamav-freshclam', 'clamd@scan'];
#    $_services = explode(',', $server_software["services"]);
#    $_services = array_map('trim', $_services);
    #echo '<pre>'; print_r($_services); exit;
    $services = array();

    #$_timers   = ['certbot-renew'];
    $_timers = explode(',', $ini["timers"]);
    $_timers = array_map('trim', $_timers);
    #echo '<pre>'; print_r($_timers); exit;
    $timers    = array();
    $timers_left = array();

	if($reqs[2]=='restart' && $_SERVER['REQUEST_METHOD']==='POST') {
		$service = $reqs[3];
		if(in_array($service, $_services)) {
			if($service=='reqad' || $service=='php82-php-fpm') {
				$output = shell_exec(__DIR__.'/../../scripts/restart_services.sh '.$service.' 2>/dev/null >/dev/null &');
				$successmsg = "Service $service schedule for restart in 3 seconds.".$output;
			} else {
				$output = shell_exec("sudo systemctl restart $service");
				$output = shell_exec("sudo systemctl status $service | grep 'Active:'");
				$output = trim($output);
				if(substr($output,8,16)=='active (running)')
					$successmsg = "Service $service was restarted.".$output;
				else
					$errmsg = "Service $service cannot be started. <br><br><pre style='color:black'>".shell_exec("sudo systemctl status $service | tail -n 6")."</pre>";
			}
		}
		if(substr($service,-6,6)=='.timer') {
			$timer = substr($service, 0, strlen($service)-6);
			if(in_array($timer, $_timers)) {
				$output = shell_exec("sudo systemctl restart $timer.timer");
				$output = trim(shell_exec("sudo systemctl status $timer.timer | grep 'Loaded:' | awk {'print $4'} | awk -F\; {'print $1'}"));
				if($output == 'enabled')
					$successmsg = "Timer $timer is enabled. <br><br><pre style='color:black'>".shell_exec("sudo systemctl status $timer.timer")."</pre>";
				else
					$errmsg = "Timer $timer cannot be started. <br><br><pre style='color:black'>".shell_exec("sudo systemctl status $timer.timer")."</pre>";
			}
		}
	}

	if($reqs[2]=='enable' && $_SERVER['REQUEST_METHOD']==='POST') {
		$service = $reqs[3];
		if(in_array($service, $_services)) {
			$output = shell_exec("sudo systemctl enable --now $service");
			$output = shell_exec("sudo systemctl status $service | grep 'Active:'");
            $output = trim($output);
            if(substr($output,8,16)=='active (running)')
			    $successmsg = "Service $service was started.".$output;
            else
			    $errmsg = "Service $service cannot be started. <br><br><pre style='color:black'>".shell_exec("sudo systemctl status $service | tail -n 6")."</pre>";
		}
		if(substr($service,-6,6)=='.timer') {
			$timer = substr($service, 0, strlen($service)-6);
			if(in_array($timer, $_timers)) {
				$output = shell_exec("sudo systemctl enable --now $timer.timer");
				$output = trim(shell_exec("sudo systemctl list-unit-files --type=timer --all $timer.timer | grep '$timer.timer' | awk {'print $2'}"));
				if($output == 'enabled') {
					$successmsg = "Timer $timer is enabled";
					$output = trim(shell_exec("sudo systemctl list-units --type=timer --all $timer.timer | grep '$timer.timer' | awk {'print $3'}"));
					if($output=='active') 
						$successmsg .= " and running.<br><br><pre style='color:black'>".shell_exec("sudo systemctl status $timer.timer")."</pre>";
					else
						$successmsg .= " bot not running (inactive).<br><br><pre style='color:black'>".shell_exec("sudo systemctl status $timer.timer")."</pre>";
				} else {
					$errmsg = "Timer $timer cannot be enabled. <br><br><pre style='color:black'>".shell_exec("sudo systemctl status $timer.timer")."</pre>";
				}
			}
		}
	}

	if($reqs[2]=='start' && $_SERVER['REQUEST_METHOD']==='POST') {
		$service = $reqs[3];
		if(in_array($service, $_services)) {
			$output = shell_exec("sudo systemctl start $service");
			$output = shell_exec("sudo systemctl status $service | grep 'Active:'");
            $output = trim($output);
            if(substr($output,8,16)=='active (running)')
			    $successmsg = "Service $service was started.".$output;
            else
			    $errmsg = "Service $service cannot be started. <br><br><pre style='color:black'>".shell_exec("sudo systemctl status $service | tail -n 6")."</pre>";
		}
	}

	if($reqs[2]=='stop' && $_SERVER['REQUEST_METHOD']==='POST') {
		$service = $reqs[3];
		if(in_array($service, $_services)) {
			$output = shell_exec("sudo systemctl stop $service");
			$successmsg = "Service $service was stopped.";
		}
	}

	if($reqs[2]=='status') {
		$service = $reqs[3];
		if(in_array($service, $_services)) {
			$output = shell_exec("sudo systemctl status $service | grep 'Active:'");
            if(substr($output,11,16)=='active (running)')
			    $successmsg = "Service $service is running. <br><br><pre style='color:black'>".shell_exec("sudo systemctl status $service")."</pre>";
            else
			    $errmsg = "Service $service is not running. <br><br><pre style='color:black'>".shell_exec("sudo systemctl status $service")."</pre>";
		}
		if(substr($service,-6,6)=='.timer') {
			$timer = substr($service, 0, strlen($service)-6);
			if(in_array($timer, $_timers)) {
				$output = trim(shell_exec("sudo systemctl list-unit-files --type=timer --all $timer.timer | grep '$timer.timer' | awk {'print $2'}"));
				if($output == 'enabled') {
					$successmsg = "Timer $timer is enabled. <br><br><pre style='color:black'>".shell_exec("sudo systemctl status $timer.timer")."</pre>";
				} else {
					$errmsg = "Timer $timer is disabled. <br><br><pre style='color:black'>".shell_exec("sudo systemctl status $timer.timer")."</pre>";
				}
			}
		}
	}

	$output = `systemctl list-units --type=service --all --plain --no-legend | grep service`;
	$output = trim($output);
#	echo '<pre>'; print_r($output);
	foreach(explode("\n", $output) as $ol) {
		#echo $ol."<br>";
		$ol = preg_split("/[\s,]+/", $ol);
		#if(!isset($ol[1])) { print_r($ol); exit; }
		$s = str_replace('.service', '', $ol[0]);
		#echo('#'.$s.'#<br>');
		if($s!='' && in_array($s, $_services))
			$services[$s] = $ol[2].' '.$ol[3];
#		foreach($ol as $ov) {
#			echo $ov.'<br>';
#		}
	}

    $output = `systemctl list-unit-files --type=service --all --plain --no-legend | awk {'print $1 "\t" $2'} | grep disabled`;
	#echo '<pre>'; print_r($output);
	foreach(explode("\n", $output) as $ol) {
		#echo $ol."<br>";
		$ol = preg_split("/[\s,]+/", $ol);
		$s = str_replace('.service', '', $ol[0]);
		#echo('#'.$s.'#');
		if($s!='' && in_array($s, $_services))
			$services[$s] = $ol[1];
#		foreach($ol as $ov) {
#			echo $ov.'<br>';
#		}
	}

    ksort($services);
	#echo '<pre>'; print_r($_services); exit;
	#echo '<pre>'; print_r($services); exit;

    $output = `systemctl list-units --type=timer --all | grep "\.timer"`;
    $output.= `systemctl list-unit-files --type=timer --all | awk {'print $1 "\t" $2'} | grep disabled`;
    #echo '<pre>'; print_r($output); exit;
    foreach(explode("\n", $output) as $ol) {
        #echo $ol."<br>";
        $ol = preg_split("/[\s,]+/", trim($ol));
        #echo $ol[0]."<br>";
        $s = str_replace('.timer', '', $ol[0]);
        #echo('#'.$s.'#');
        if($s!='' && in_array($s, $_timers))
            $timers[$s] = trim($ol[1].' '.$ol[2].' '.$ol[3]);
#       foreach($ol as $ov) {
#           echo $ov.'<br>';
#       }
		$output = `systemctl list-timers | grep '$s.timer' | head -2 | tail -1 | cut -c 31-44`;
		$timers_left[$s] = trim($output);
    }

    ksort($timers);
	#echo '<pre>'; print_r($timers); exit;

#	$results = $db->query('SELECT count(*) as nb FROM accounts');
#	$row = $results->fetchArray();
#	$nb_accounts = (int)($row["nb"]);
?>
          <!-- Page title -->
          <div class="page-header d-print-none">
            <div class="row align-items-center">
              <div class="col" style="padding-left:22px;">
                <!-- Page pre-title -->
                <div class="page-pretitle">
                  SERVER
                </div>
                <h2 class="page-title">
                  Services Status
                </h2>
              </div>
            </div>
          </div>

<?	if(count($services) == 0 && count($timers)==0) { ?>
		<p style="padding:14px;">There are no services defined on this server.</p>
<? 	} else { ?>


<? if(isset($errmsg) && $errmsg != '') { ?>
          <div class="alert alert-warning" role="alert" style="background:#FFE;">
            <div class="d-flex">
				<div style="width:55px;">
                	<svg xmlns="http://www.w3.org/2000/svg" class="icon mb-2 text-danger icon-md" width="48" height="48" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><path d="M12 9v2m0 4v.01"></path><path d="M5 19h14a2 2 0 0 0 1.84 -2.75l-7.1 -12.25a2 2 0 0 0 -3.5 0l-7.1 12.25a2 2 0 0 0 1.75 2.75"></path></svg>
             	</div>
             	<div>
				 <h3 class="text-danger" style="margin-top:6px;margin-bottom:0">Error</h3>
				 <div class="text-danger"><?=str_replace('Error: ', '', strip_tags($errmsg, "<pre><br><b>"));?></div>
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
                	<div class="text-success"><?=strip_tags($successmsg, "<pre><br><b>");?></div>
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
                        <th style="background-color:#DEF;">Service</th>
                        <th style="background-color:#DEF;">Status</th>
                        <th style="background-color:#DEF;">Operation</th>
                        <th class="w-1" style="background-color:#DEF;"></th>
                      </tr>
                    </thead>
                    <tbody>
                    <?
                      foreach ($services as $service => $status) {
                    ?>
                      <tr>
                        <td data-label="ID">
                          <div class="d-flex">
                            <div class="flex-fill">
                              <div class="text-muted" style="white-space:nowrap;"><?=$service;?></div>
                            </div>
                          </div>
                        </td>
                        <td data-label="Domain">
                          <div class="d-flex py-1 align-items-center">
                            <div class="flex-fill">
                              <div class="font-weight-medium">
<?	
	if($service=='certbot-renew') {
		echo '<span class="badge bg-primary sm">on demand</span>';
	} else if($status=='active running') {
		echo '<span class="badge bg-success sm">running</span>';
	} else if($status=='disabled') {
		echo '<span class="badge bg-yellow sm">disabled</span>';
	} else {
		echo '<span class="badge bg-danger sm">not running</span>';
	}
?>
							  </div>
                            </div>
                          </div>
                        </td>
                        <td>
                          <div class="btn-list flex-nowrap">
							<? if($status == 'active running') { ?>
                            <form method="post" action="services/stop/<?=$service;?>" style="display:inline;margin:0;">
                            <button type="submit" class="btn btn-white btn-md">
								<svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-player-pause" width="16" height="16" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><rect x="6" y="5" width="4" height="14" rx="1"></rect><rect x="14" y="5" width="4" height="14" rx="1"></rect></svg>
								Stop</button></form>
                            <form method="post" action="services/restart/<?=$service;?>" style="display:inline;margin:0;">
                            <button type="submit" class="btn btn-white btn-md">
								<svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-refresh" width="8" height="8" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4"></path><path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4"></path></svg>
								Restart</button></form>
                            <a href="services/status/<?=$service;?>" class="btn btn-white btn-md">
								<svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-run" width="24" height="24" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">  <path stroke="none" d="M0 0h24v24H0z" fill="none"></path>   <circle cx="13" cy="4" r="1"></circle>   <path d="M4 17l5 1l.75 -1.5"></path>   <path d="M15 21l0 -4l-4 -3l1 -6"></path>   <path d="M7 12l0 -3l5 -1l3 3l3 1"></path></svg>
								Status</a>
							<? } else if($status=='disabled') { ?>
                            <form method="post" action="services/enable/<?=$service;?>" style="display:inline;margin:0;">
                            <button type="submit" class="btn btn-white btn-md">
								<span class="icon-item-icon"><svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-player-play" width="16" height="16" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"> <path stroke="none" d="M0 0h24v24H0z" fill="none"></path> <path d="M7 4v16l13 -8z"></path></svg></span>
								Enable</button></form>
                            <a href="services/status/<?=$service;?>" class="btn btn-white btn-md">
								<svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-run" width="24" height="24" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">  <path stroke="none" d="M0 0h24v24H0z" fill="none"></path>   <circle cx="13" cy="4" r="1"></circle>   <path d="M4 17l5 1l.75 -1.5"></path>   <path d="M15 21l0 -4l-4 -3l1 -6"></path>   <path d="M7 12l0 -3l5 -1l3 3l3 1"></path></svg>
								Status</a>
							<? } else { ?>
                            <form method="post" action="services/start/<?=$service;?>" style="display:inline;margin:0;">
                            <button type="submit" class="btn btn-white btn-md">
								<span class="icon-item-icon"><svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-player-play" width="16" height="16" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"> <path stroke="none" d="M0 0h24v24H0z" fill="none"></path> <path d="M7 4v16l13 -8z"></path></svg></span>
								Start</button></form>
                            <form method="post" action="services/restart/<?=$service;?>" style="display:inline;margin:0;">
                            <button type="submit" class="btn btn-white btn-md">
								<svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-refresh" width="8" height="8" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4"></path><path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4"></path></svg>
								Restart</button></form>
                            <a href="services/status/<?=$service;?>" class="btn btn-white btn-md">
								<svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-run" width="24" height="24" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">  <path stroke="none" d="M0 0h24v24H0z" fill="none"></path>   <circle cx="13" cy="4" r="1"></circle>   <path d="M4 17l5 1l.75 -1.5"></path>   <path d="M15 21l0 -4l-4 -3l1 -6"></path>   <path d="M7 12l0 -3l5 -1l3 3l3 1"></path></svg>
								Status</a>
							<? } ?>
                          </div>
                        </td>
                      </tr>
                      <? } ?>
                    </tbody>
                    <thead>
                      <tr>
                        <th style="background-color:#DEF;">Timer</th>
                        <th style="background-color:#DEF;">Status</th>
                        <th style="background-color:#DEF;"></th>
                        <th class="w-1" style="background-color:#DEF;"></th>
                      </tr>
                    </thead>
                    <tbody>
                    <?
                      foreach ($timers as $timer => $status) {
                    ?>
                      <tr>
                        <td data-label="ID">
                          <div class="d-flex">
                            <div class="flex-fill">
                              <div class="text-muted"><?=$timer;?></div>
                            </div>
                          </div>
                        </td>
                        <td data-label="Domain">
                          <div class="d-flex py-1 align-items-center">
                            <div class="flex-fill">
                              	<div class="font-weight-medium">
									<?=$status=='loaded active waiting'?'
										<span class="badge bg-success sm">active</span> <span class="d-none d-md-inline"><span class="badge bg-grey sm" style="margin-top:0px;">'.substr($timers_left[$timer],0,strpos($timers_left[$timer], 'left')+4) .'</span>'
										:($status=='disabled'?'<span class="badge bg-yellow sm">disabled</span>':'<span class="badge bg-danger sm">not running</span>');?>
								</div>
                            </div>
                          </div>
                        </td>
                        <td>
							<? if($status=='disabled') { ?>
                            <form method="post" action="services/enable/<?=$timer;?>.timer" style="display:inline;margin:0;">
                            <button type="submit" class="btn btn-white btn-md">
								<span class="icon-item-icon"><svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-player-play" width="16" height="16" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"> <path stroke="none" d="M0 0h24v24H0z" fill="none"></path> <path d="M7 4v16l13 -8z"></path></svg></span>
								Enable</button></form>
							<? } else { ?>
                            <form method="post" action="services/restart/<?=$timer;?>.timer" style="display:inline;margin:0;">
                            <button type="submit" class="btn btn-white btn-md">
								<svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-refresh" width="8" height="8" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4"></path><path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4"></path></svg>
								Restart</button></form>
							<? } ?>
							<a href="services/status/<?=$timer;?>.timer" class="btn btn-white btn-md">
								<svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-run" width="24" height="24" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">  <path stroke="none" d="M0 0h24v24H0z" fill="none"></path>   <circle cx="13" cy="4" r="1"></circle>   <path d="M4 17l5 1l.75 -1.5"></path>   <path d="M15 21l0 -4l-4 -3l1 -6"></path>   <path d="M7 12l0 -3l5 -1l3 3l3 1"></path></svg>
								Status</a>
                        </td>
                      </tr>
                      <? } ?>
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

<? /*
<div id="lipsum" style="padding:15px;">
<p>
Lorem ipsum dolor sit amet, consectetur adipiscing elit. Morbi sagittis leo nec ex aliquet posuere. Vestibulum leo lacus, gravida eget risus vel, malesuada euismod urna. Nunc velit justo, scelerisque sit amet consectetur non, faucibus quis purus. Cras consequat lobortis tortor sit amet lacinia. Etiam tristique luctus ipsum. Aliquam eu mattis enim. Nullam vel placerat magna, et commodo eros. Sed ex purus, interdum quis ex non, elementum pharetra ligula. Fusce lacinia justo sed risus rutrum, eget laoreet ligula suscipit. Donec porttitor, nibh ut lacinia aliquet, ante ex efficitur justo, vitae dictum felis dui sed enim. Vivamus tempus lacus quis eros viverra, sit amet fringilla magna condimentum.
</p>
<p>
Integer tincidunt ultrices mauris a finibus. Pellentesque ullamcorper, diam ut condimentum commodo, metus felis lacinia lorem, quis pellentesque est ipsum ut nisl. Fusce massa nisl, malesuada sit amet blandit nec, rhoncus et mi. Duis feugiat sed elit tristique mollis. Mauris consequat malesuada dolor quis euismod. Proin leo justo, vestibulum in scelerisque quis, vehicula ac ante. Donec nec libero risus. Vivamus ornare consequat justo, eu viverra elit sagittis a. In molestie est in nisi auctor, vitae finibus elit faucibus. Ut feugiat nulla nec volutpat euismod. Vivamus ut dignissim turpis. Nulla non egestas quam.
</p>
<p>
Cras mattis risus at nulla laoreet, sit amet consequat libero venenatis. Nam sit amet odio aliquam, ultrices sem sed, tempor velit. Curabitur dictum faucibus risus nec ornare. Pellentesque nec hendrerit arcu, vitae sagittis purus. Phasellus nec aliquam ligula. Cras sodales, massa eu convallis accumsan, ligula est accumsan lacus, nec elementum tortor sapien quis erat. Aliquam faucibus risus ligula, ut tincidunt leo aliquet eget. Praesent non vulputate purus. Curabitur egestas dolor id ligula egestas elementum. In id fermentum nunc. Suspendisse at elementum metus. Phasellus eu risus rutrum, fermentum felis ac, ullamcorper metus. Integer vel neque quis velit varius sagittis eget non nulla. Ut pellentesque justo eget sodales pellentesque.
</p>
<p>
Aliquam dapibus, elit vel cursus hendrerit, justo purus vehicula sapien, at suscipit justo nunc vitae est. Quisque posuere rhoncus faucibus. Duis dictum quis neque sed malesuada. Fusce velit nunc, commodo ac volutpat nec, fringilla sit amet odio. Etiam placerat orci nec dolor laoreet hendrerit. Praesent consectetur ante in purus sagittis, in consectetur augue efficitur. Nullam id ullamcorper nibh, eget sodales nisl. Ut dictum metus eget tempor ultricies. Nam pretium mauris non vulputate bibendum. Nullam in leo eu diam feugiat mollis. Vestibulum molestie tincidunt ipsum, et lacinia massa iaculis nec. Ut dignissim auctor nisl, a vestibulum quam tincidunt quis. Etiam nec sapien ac ligula elementum posuere in eu libero. Aenean et diam ac ex lacinia fermentum ac eget velit. Mauris tristique hendrerit metus, sed volutpat dui euismod eget.
</p>
<p>
Duis eget urna lacinia, posuere ligula at, lacinia massa. Fusce tristique eleifend enim sed imperdiet. Donec consequat a ligula nec finibus. Mauris ullamcorper massa eu eleifend vulputate. Donec facilisis, sapien non convallis pellentesque, neque mauris sagittis lectus, eget venenatis nisi orci vel urna. Suspendisse tristique leo libero, eu suscipit justo venenatis nec. Donec dictum cursus nibh, eu mattis orci interdum eget. Interdum et malesuada fames ac ante ipsum primis in faucibus. Duis nibh nulla, tristique molestie tempor nec, tempor sit amet ante. Interdum et malesuada fames ac ante ipsum primis in faucibus.
</p></div>
*/ ?>

            </div>
          </div>
        </div>
      </div>
    </div>
<?php
    include('templates/footer.php'); 
?>
	</body>
</html>
