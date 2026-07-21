<?php
	// wetty always runs the same entrypoint, so the shell user is chosen here and
	// handed off via ajax-terminal-target before the iframe is loaded. root is
	// only offered when root_access=1 (scripts/terminal_target.sh enforces it too).
	$root_access = isset($ini['root_access']) ? (int)$ini['root_access'] : 1;

	$term_users = $root_access ? array('root' => 'the whole server') : array();
	$q = $db->query('SELECT user, domain FROM accounts ORDER BY user');
	while ($row = $q->fetchArray())
		$term_users[$row["user"]] = $row["domain"];

	// wetty is not an RPM dependency, so it may be missing or stopped. Without
	// this the iframe would just render blank with no explanation.
	$wetty = wetty_status();

	include('templates/header.php');
?>
        <!-- Page title -->
        <div class="page-header d-print-none">
            <div class="row align-items-center">
              <div class="col" style="padding-left:22px;">
                <!-- Page pre-title -->
                <div class="page-pretitle">
                  Terminal
                </div>
                <h2 class="page-title">
                  Bash Shell
                </h2>
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
		<div class="card-body" style="padding-bottom:2px;">

		<? if(!$wetty['ok']) { ?>
		  <div class="alert alert-warning" role="alert" style="background:#FFE;">
			<div class="d-flex">
				<div style="width:55px;">
					<svg xmlns="http://www.w3.org/2000/svg" class="icon mb-2 text-danger icon-md" width="48" height="48" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><path d="M12 9v2m0 4v.01"></path><path d="M5 19h14a2 2 0 0 0 1.84 -2.75l-7.1 -12.25a2 2 0 0 0 -3.5 0l-7.1 12.25a2 2 0 0 0 1.75 2.75"></path></svg>
				</div>
				<div class="flex-fill">
					<h3 class="text-danger" style="margin-top:6px;margin-bottom:4px;"><?=clean($wetty['title']);?></h3>
					<div><?=clean($wetty['message']);?></div>
					<? if($wetty['hint'] != '') { ?>
					<div style="margin-top:10px;">
						Run this on the server:
						<pre style="color:black;margin-top:4px;margin-bottom:0;"><?=clean($wetty['hint']);?></pre>
					</div>
					<? } ?>
					<? if($wetty['detail'] != '') { ?>
					<div style="margin-top:10px;">
						<a href="#" onclick="jQuery('#wetty-detail').toggle();return false;">Show service status</a>
						<pre id="wetty-detail" style="color:black;display:none;margin-top:4px;margin-bottom:0;"><?=clean($wetty['detail']);?></pre>
					</div>
					<? } ?>
				</div>
			</div>
		  </div>
		<? } else if(empty($term_users)) { ?>
		  <div class="alert alert-warning" role="alert" style="background:#FFE;">
			There are no accounts to open a terminal for<?=($root_access ? '' : ', and root access is disabled in server-software.ini');?>.
		  </div>
		<? } else { ?>

		<div class="mb-3 row" id="terminal-picker">
			<div class="col-auto p-2">
				<p>Select user:</p>
			</div>
			<div class="col-auto">
				<select class="form-select" id="terminal-user" style="min-width:260px;">
					<? foreach($term_users as $tu => $tdomain) { ?>
					<option value="<?=clean($tu);?>"><?=clean($tu);?><? if($tdomain != '') { ?> - <?=clean($tdomain);?><? } ?></option>
					<? } ?>
				</select>
			</div>
			<div class="col-auto">
				<button type="button" class="btn btn-primary" id="terminal-open">Open Terminal</button>
			</div>
			<div class="col-auto d-flex align-items-center">
				<span class="text-danger" id="terminal-error"></span>
			</div>
		</div>

		<div class="mb-3 row" id="terminal-console" style="display:none;">
			<div class="col-12 mb-2">
				<div class="alert alert-info mb-0" role="alert" style="background:#EEF6FF;">
					<div class="d-flex align-items-center">
						<div style="width:55px;">
							<svg xmlns="http://www.w3.org/2000/svg" class="icon mb-2 text-primary icon-md" width="48" height="48" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><path d="M8 9l3 3l-3 3"></path><path d="M13 15l3 0"></path><rect x="3" y="4" width="18" height="16" rx="2"></rect></svg>
						</div>
						<div class="flex-fill">
							Shell as <strong id="terminal-current"></strong>
						</div>
						<div>
							<button type="button" class="btn btn-primary" id="terminal-close">Close &amp; switch user</button>
						</div>
					</div>
				</div>
			</div>
			<div class="col-12">
				<iframe src="" id="console" style="min-height:600px;width:100%;border:4px solid #000;background:#000;"></iframe>
			</div>
		</div>

		<? } ?>
		</div>
	<?php
    include('templates/footer.php');
?>
<script>
jQuery(document).ready(function () {
//	'use strict';

	// The target user must be handed off to wetty before the iframe connects —
	// shell.sh consumes it on the next connection and refuses without one.
	jQuery('#terminal-open').on('click', function () {
		var user = jQuery('#terminal-user').val();
		var btn = jQuery(this);

		jQuery('#terminal-error').text('');
		btn.prop('disabled', true);

		jQuery.post('/?ajax=1', { action: 'ajax-terminal-target', user: user }, function (data) {
			btn.prop('disabled', false);
			if (!data || !data.ok) {
				jQuery('#terminal-error').text((data && data.error) ? data.error : 'Could not start the terminal.');
				return;
			}
			jQuery('#terminal-current').text(user);
			jQuery('#terminal-picker').hide();
			jQuery('#terminal-console').show();
			// cache-buster so re-opening always makes a fresh connection
			jQuery('#console').attr('src', 'https://<?=$_SERVER['HTTP_HOST'];?>/wetty/?t=' + Date.now());
		}, 'json').fail(function () {
			btn.prop('disabled', false);
			jQuery('#terminal-error').text('Could not start the terminal.');
		});
	});

	jQuery('#terminal-close').on('click', function (e) {
		e.preventDefault();

		// Send Ctrl+D first so the shell exits cleanly (and `su` unwinds back to
		// wetty's own exit) instead of leaving the process to die with the socket.
		// The iframe is same-origin, and wetty's client exposes window.wetty_term
		// with .input(data, wasUserInput) — see /root/wetty/src/client/wetty/term.ts.
		try {
			var w = document.getElementById('console').contentWindow;
			if (w && w.wetty_term) w.wetty_term.input('\x04', false);
		} catch (err) {
			// cross-origin or not connected yet — just drop the iframe below
		}

		// give the EOF a moment to reach the shell before tearing the socket down
		setTimeout(function () {
			jQuery('#console').attr('src', '');
			jQuery('#terminal-console').hide();
			jQuery('#terminal-picker').show();
		}, 300);
	});

});
</script>
</body>
</html>
