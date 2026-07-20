<?
	$reboot_time = isset($_POST["time"])?$_POST["time"]:'now';
	if(!in_array($reboot_time, array('now', '22:00', '23:00', '00:00', '01:00', '02:00'))) {
		$reboot_time = 'now';
	}

	if($reboot_time == 'now') {
    	shell_exec(__DIR__.'/../../scripts/reboot_server.sh 2>/dev/null >/dev/null &');
	} else {
		shell_exec("sudo shutdown -r $reboot_time");
		header("Location: ".$_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST']);
		exit;
	}
?><!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"/>
    <meta http-equiv="X-UA-Compatible" content="ie=edge"/>
    <title><?=substr($_SERVER['HTTP_HOST'],0,strpos($_SERVER['HTTP_HOST'], '.'));?> - Rebooting Server</title>
    <!-- CSS files -->
    <link href="./dist/css/tabler.min.css" rel="stylesheet"/>
    <link href="./dist/css/tabler-flags.min.css" rel="stylesheet"/>
    <link href="./dist/css/tabler-payments.min.css" rel="stylesheet"/>
    <link href="./dist/css/tabler-vendors.min.css" rel="stylesheet"/>
  </head>
  <body class="antialiased border-top-wide border-primary d-flex flex-column" style="background:#EEE;">
    <div class="flex-fill d-flex align-items-center justify-content-center">
      <div class="container-small py-6">
        <div class="empty" style="background:#FFF;border:1px solid #CCC;">
          <h5 class="empty-title" style="font-size:16pt;margin:40px 0 15px 0;">Rebooting Server</h5>
          <p class="empty-subtitle text-muted">
            Usually it takes 20-30 seconds to reboot. Please wait for reboot to complete then it goes back to first page.
          </p>
          <div class="empty-action">
            <a href="./." class="btn btn-primary">
              <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><line x1="5" y1="12" x2="19" y2="12" /><line x1="5" y1="12" x2="11" y2="18" /><line x1="5" y1="12" x2="11" y2="6" /></svg>
              Return home
            </a>
          </div>
        </div>
		  <div id="console" style="background:#222;color:#ccc;padding:10px 20px;width:auto;font-family: monospace, monospace;"></div>
      </div>
    </div>
    <!-- Libs JS -->
    <script src="./dist/libs/bootstrap/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Tabler Core -->
    <script src="./dist/js/tabler.min.js"></script>
	<script src="./dist/libs/jquery/dist/jquery-3.6.0.min.js"></script>
	<script>
const sleep = (delay) => new Promise((resolve) => setTimeout(resolve, delay))
const wait_to_reboot = async () => {
	console.log('rebooting, please wait 15 seconds for the for the first check.');
	$('#console').append('rebooting, please wait 15 seconds for the for the first check.');
	await sleep(10000);
}
const check_back_online = async () => {
	await sleep(5000);
	console.log('checking ... ');
	$('#console').append('<br>checking ... ');
	jQuery.ajax({
			method: "GET",
			url: "./ping",
			data: { },
			success: function (response) {
        		console.log('back online!');
        		$('#console').append(' back online!');
				window.location="/";
	    	},
    		error: function (xhr, ajaxOptions, thrownError) {
        		$('#console').append(' still offline.');
				check_back_online();
    	  	},
			timeout: 3000
	});
}

jQuery(document).ready(function () {
	'use strict';
	wait_to_reboot();
	check_back_online();
});
	</script>
  </body>
</html>
