<?php
	$php_ini_allowed = array(
		'allow_url_fopen' => 'boolean',
		'allow_url_include' => 'boolean',
//		'asp_tags',
		'date.timezone' => '',
		'disable_functions' => '',
		'display_errors' => 'boolean',
		'enable_dl' => 'boolean',
		'error_reporting' => '',
		'file_uploads' => 'boolean',
		'log_errors' => 'boolean',
		'max_execution_time' => 'numeric',
		'max_input_time' => 'numeric',
		'max_input_vars' => 'numeric',
		'memory_limit' => 'numeric',
		'max_memory_limit' => 'numeric',
		'output_buffering' => 'numeric',
		'post_max_size' => 'numeric',
		'realpath_cache_size' => 'numeric',
		'realpath_cache_ttl' => 'numeric',
		'session.gc_maxlifetime' => 'numeric',
//		'session.save_path' => '',
		'short_open_tag' => 'boolean',
		'upload_max_filesize' => 'numeric',
		'expose_php' => 'boolean',
		'zlib.output_compression' => 'boolean',
		'mail.force_extra_parameters' => 'numeric'
	);
	#echo "<pre>"; print_r($php_ini_allowed);exit;

	// Default value for each numeric field. Used to prefill the input when the
	// setting is missing/empty in php.ini, so a blank field is never submitted
	// (a blank numeric value gets written as 0). mail.force_extra_parameters is
	// intentionally absent — empty is a valid value for it.
	$php_numeric_defaults = array(
		'max_execution_time'     => '30',
		'max_input_time'         => '60',
		'max_input_vars'         => '1000',
		'memory_limit'           => '128M',
		'max_memory_limit'       => '256M',
		'output_buffering'       => 'Off',
		'post_max_size'          => '8M',
		'realpath_cache_size'    => '4096k',
		'realpath_cache_ttl'     => '120',
		'session.gc_maxlifetime' => '1440',
		'upload_max_filesize'    => '2M',
	);

	$php_versions = array_map('trim', explode(',', $ini['php_versions']));
	if(isset($_GET['phpver']))
		$phpver = $_GET['phpver'];

	if(!isset($phpver) || !in_array($phpver, $php_versions))
		$phpver = $ini['php'];

	// max_memory_limit is available since php 8.5
	if((int)(str_replace('.', '', $phpver)) < 85)
		unset($php_ini_allowed['max_memory_limit']);

	if($phpver == $ini['php'])
		$phpini_path = '/etc/php.ini';
	else
		$phpini_path = '/etc/opt/remi/php'.str_replace('.', '', $phpver).'/php.ini';

	$php_ini = array();

	$output = shell_exec("cat $phpini_path | grep -ve '^;' | grep -ve '^\[' | grep -ve '^\$'");
	$output = array_map('trim', explode("\n", trim($output)));
	foreach($output as $line) {
		$parsed_line = array_map('trim', explode("=", trim($line)));
		#echo "<pre>"; print_r($parsed_line);exit;
		$var = $parsed_line[0];
		$val = $parsed_line[1];
		if(array_key_exists($var, $php_ini_allowed)) {
			$php_ini[$var] = $val;
		}
	}
	ksort($php_ini);
	#echo "<pre>"; print_r($php_ini);exit;

	// OPcache settings live in a separate ini (key=value, 1/0 booleans)
	$opcache_allowed = array(
		'opcache.enable' => 'boolean',
		'opcache.enable_cli' => 'boolean',
		'opcache.memory_consumption' => 'numeric',
		'opcache.interned_strings_buffer' => 'numeric',
		'opcache.max_accelerated_files' => 'numeric',
	);
	if($phpver == $ini['php'])
		$opcache_path = '/etc/php.d/10-opcache.ini';
	else
		$opcache_path = '/etc/opt/remi/php'.str_replace('.', '', $phpver).'/php.d/10-opcache.ini';

	$opcache = array();
	if(is_file($opcache_path)) {
		$output = shell_exec("cat $opcache_path | grep -ve '^;' | grep -ve '^\[' | grep -ve '^\$'");
		$output = array_map('trim', explode("\n", trim($output)));
		foreach($output as $line) {
			$parsed_line = array_map('trim', explode("=", trim($line), 2));
			$var = $parsed_line[0];
			$val = isset($parsed_line[1]) ? $parsed_line[1] : '';
			if(array_key_exists($var, $opcache_allowed))
				$opcache[$var] = $val;
		}
		// fall back to the commented default so the field isn't misleadingly empty
		foreach($opcache_allowed as $var => $type) {
			if(!isset($opcache[$var])) {
				$c = trim(shell_exec("grep -e '^;$var=' $opcache_path | tail -n 1"));
				if($c !== '') {
					$pl = array_map('trim', explode('=', $c, 2));
					$opcache[$var] = isset($pl[1]) ? $pl[1] : '';
				}
			}
		}
	}

	// APCu settings (separate ini, key=value, 1/0 booleans; often not installed)
	$apcu_allowed = array(
		'apc.enabled'    => 'boolean',
		'apc.enable_cli' => 'boolean',
		'apc.shm_size'   => 'numeric',
		'apc.serializer' => '',
	);
	if($phpver == $ini['php'])
		$apcu_path = '/etc/php.d/40-apcu.ini';
	else
		$apcu_path = '/etc/opt/remi/php'.str_replace('.', '', $phpver).'/php.d/40-apcu.ini';

	$apcu = array();
	$apcu_installed = is_file($apcu_path);
	if($apcu_installed) {
		$output = shell_exec("cat $apcu_path | grep -ve '^;' | grep -ve '^\[' | grep -ve '^\$'");
		$output = array_map('trim', explode("\n", trim($output)));
		foreach($output as $line) {
			$parsed_line = array_map('trim', explode("=", trim($line), 2));
			$var = $parsed_line[0];
			$val = isset($parsed_line[1]) ? trim($parsed_line[1], "'\"") : '';
			if(array_key_exists($var, $apcu_allowed))
				$apcu[$var] = $val;
		}
		// fall back to the commented default so the field isn't misleadingly empty
		foreach($apcu_allowed as $var => $type) {
			if(!isset($apcu[$var])) {
				$c = trim(shell_exec("grep -e '^;$var=' $apcu_path | tail -n 1"));
				if($c !== '') {
					$pl = array_map('trim', explode('=', $c, 2));
					$apcu[$var] = isset($pl[1]) ? trim($pl[1], "'\"") : '';
				}
			}
		}
	}

	$disable_functions_recommended ='show_source, system, shell_exec, passthru, exec, popen, proc_open, proc_close, pcntl_exec, dl, mb_send_mail, imap_open, highlight_file, create_function';

	$tab = (isset($_GET['tab']) && $_GET['tab'] === 'modules') ? 'modules' : 'settings';

	$known_php_versions = ['7.2', '7.3', '7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5'];
	$missing_php_versions = array_values(array_diff($known_php_versions, $php_versions));

	include('templates/header.php');
?>
        <!-- Page title -->
        <div class="page-header d-print-none">
            <div class="row align-items-center">
              <div class="col" style="padding-left:22px;">
                <!-- Page pre-title -->
                <div class="page-pretitle">
                  Settings
                </div>
                <h2 class="page-title">
                  PHP Settings
                </h2>
              </div>
			  <? if (!empty($missing_php_versions)): ?>
              <div class="col-auto ms-auto d-print-none">
                <div class="btn-list">
                  <a href="#" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal-install-php">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Install PHP
                  </a>
                </div>
              </div>
			  <? endif; ?>
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
		<div id="page-alert"></div>

		<form method="get" action="/php-settings/" id="phpver-form" class="card needs-validation" novalidate="">
		<input type="hidden" name="tab" value="<?=$tab; ?>">
		<div class="card-body" style="padding-bottom:2px;background:rgb(71 93 180 / 10%);"><div class="mb-3 row">
			<label class="col-3 col-form-label">Select PHP Version</label>
			<div class="col" style="max-width:200px;">
				<select name="phpver" class="form-select" style="max-width180px;">
				<?
					$php_versions = array_map('trim', explode(',', $ini['php_versions']));
					foreach($php_versions as $phpv) {
				?>
					<option value="<?=$phpv;?>" <?=($phpv==$phpver?'selected=""':'');?>><?=$phpv; if($phpv == $ini['php']) echo ' (default)';?></option>
				<?	} ?>					
				</select>
			</div>
			<div class="col">
				<input type="submit" id="submit-btn1" class="btn" value="Change" style="max-width:100px;">
			</div>
		</div></div></form>

		<style>
		#php-main-card { border: none; }
		#php-main-card > .card-header { padding-bottom: 0; background: #f6f8fb; }
		#php-tabs { border-bottom: 0; gap: 30px; }
		#php-tabs .nav-link {
			display: block;
			border: 1px solid transparent;
/*			border-radius: 4px 4px 0 0; */
			padding: 10px 20px !important;
			color: #6c757d;
			font-weight: 500;
			line-height:20pt !important;
			margin-bottom: -1px;
			margin-left: -16px !important;
		}
		#php-tabs .nav-link:hover { border-color: #dee2e6 #dee2e6 transparent; background: #475db41a; color: #354052; }
		#php-tabs .nav-link.active { background: #fff; border-color: #dee2e6 #dee2e6 #fff; color: #354052; font-weight: bold; }
		#tab-settings { border: 1px solid; border-color: transparent #dee2e6 #dee2e6 #dee2e6; padding-left: 6px;  }
		#tab-modules { border: 1px solid; border-color: transparent #dee2e6 #dee2e6 #dee2e6; padding-left: 0px;  }
		#tab-modules > .card-body { padding: 10px; }
		#tab-modules .table-hover tbody tr:hover td { background-color: #475db41a; }
		</style>
		<div class="card mt-3" id="php-main-card">
		<div class="card-header">
			<ul class="nav nav-tabs card-header-tabs" id="php-tabs">
				<li class="nav-item">
					<a class="nav-link <?=$tab==='settings'?'active':''?>" href="/php-settings/?phpver=<?=$phpver?>">PHP Settings</a>
				</li>
				<li class="nav-item">
					<a class="nav-link <?=$tab==='modules'?'active':''?>" href="/php-settings/?phpver=<?=$phpver?>&tab=modules">PHP Modules</a>
				</li>
			</ul>
		</div>
		<div class="tab-content">

		<div class="tab-pane <?=$tab==='settings'?'active show':''?>" id="tab-settings">
		<form method="post" action="/" id="php-settings" class="needs-validation" novalidate="">
		<input type="hidden" name="action" value="php-settings">
		<input type="hidden" name="phpver" value="<?=$phpver; ?>">
		<div class="card-body">
		<div class="mb-3 row">
			<label class="col-3 col-form-label required">path to php.ini</label>
			<div class="col" style="border:1px solid #DDD;background:#F9F9F9;padding:5px 10px;line-height:24px;color:#888;"><?=$phpini_path;?></div>
		</div>

		<?
			foreach($php_ini_allowed as $var => $type) {
				if(!isset($php_ini[$var]))
					$php_ini[$var] = '';
		?>
			<div class="mb-3 row">
				<label class="col-3 col-form-label required"><?=$var;?></label>
				<div class="col">
					<? if($type=='boolean') { ?>
					<label class="form-check form-check-single form-switch" style="max-width:40px;padding:2px;">
						<input class="form-check-input" name="<?=$var;?>" type="checkbox" <?=$php_ini[$var]=='On'?'checked=""':'';?>>
					</label>
					<? } else if($type=='numeric') {
						$numval = ($php_ini[$var]==='' && isset($php_numeric_defaults[$var])) ? $php_numeric_defaults[$var] : $php_ini[$var];
					?>
						<input type="text" name="<?=$var;?>" value="<?=$numval;?>" class="form-control" style="font-family:monospace;max-width:100px;" placeholder="<?=isset($php_numeric_defaults[$var])?$php_numeric_defaults[$var]:'';?>">
					<? } else { ?>
						<input type="text" name="<?=$var;?>" value="<?=$php_ini[$var];?>" class="form-control" style="font-family:monospace;" placeholder="">
					<? } ?>
					<? if($var=='disable_functions') { ?>
						<div class="p-3 mt-1" style="background:#FFE">
							Recommended: 
							<code><?=$disable_functions_recommended;?></code><br>
							<button type="button" class="btn btn-sm btn-primary use-recommended mt-1" style="float:right" data-target="disable_functions" data-value="<?=htmlspecialchars($disable_functions_recommended, ENT_QUOTES);?>">use recommended list</button><br>
						</div>
					<? } else if($var=='output_buffering') { ?>
						<small class="form-hint"><code>0</code> = Off, <code>1</code> = On, or a byte size (e.g. <code>4096</code> = buffer up to 4&nbsp;KB). Default: <code>Off</code>.</small>
					<? } /* else if($var=='mail.force_extra_parameters') { ?>
						<small class="form-hint">Recommended: <code>1</code> <button type="button" class="btn btn-sm btn-outline-primary use-recommended ms-1" data-target="mail.force_extra_parameters" data-value="1">use recommended</button></small>
					<? } */ else if(!isset($php_ini[$var])) { ?>
						<small class="form-hint"><b>Note:</b> not defined in php.ini</small>
					<? } ?>
				</div>
			</div>
		<? } ?>

			<hr style="margin:6px -16px 10px -22px;">
			<div class="mb-3 row">
				<div class="col">
					<h3 style="margin:10px 0 0 0;font-weight:bold">OPcache</h3>
					<div class="text-muted" style="font-size:.85rem;">File: <code><?=$opcache_path;?></code><?=is_file($opcache_path)?'':' <span class="text-danger">(not found)</span>';?></div>
				</div>
			</div>
		<?
			foreach($opcache_allowed as $var => $type) {
				if(!isset($opcache[$var]))
					$opcache[$var] = '';
		?>
			<div class="mb-3 row">
				<label class="col-3 col-form-label required"><?=$var;?></label>
				<div class="col">
					<? if($type=='boolean') { ?>
					<label class="form-check form-check-single form-switch" style="max-width:40px;padding:2px;">
						<input class="form-check-input" name="<?=$var;?>" type="checkbox" <?=$opcache[$var]=='1'?'checked=""':'';?>>
					</label>
					<? } else { ?>
						<input type="text" name="<?=$var;?>" value="<?=$opcache[$var];?>" class="form-control" style="font-family:monospace;max-width:100px;" placeholder="">
					<? } ?>
				</div>
			</div>
		<? } ?>

			<hr style="margin:6px -16px 10px -22px;">
				<div class="mb-3 row">
					<div class="col">
						<h3 style="margin:10px 0 0 0;font-weight:bold">APCu</h3>
						<div class="text-muted" style="font-size:.85rem;">File: <code><?=$apcu_path;?></code><?=$apcu_installed?'':' <span class="text-danger">(not installed for this PHP version)</span>';?></div>
					</div>
				</div>
			<? if($apcu_installed) { ?>
				<input type="hidden" name="apcu_present" value="1">
			<?
				foreach($apcu_allowed as $var => $type) {
					if(!isset($apcu[$var]))
						$apcu[$var] = '';
			?>
				<div class="mb-3 row">
					<label class="col-3 col-form-label required"><?=$var;?></label>
					<div class="col">
						<? if($type=='boolean') { ?>
						<label class="form-check form-check-single form-switch" style="max-width:40px;padding:2px;">
							<input class="form-check-input" name="<?=$var;?>" type="checkbox" <?=$apcu[$var]=='1'?'checked=""':'';?>>
						</label>
						<? } else if($type=='numeric') { ?>
							<input type="text" name="<?=$var;?>" value="<?=$apcu[$var];?>" class="form-control" style="font-family:monospace;max-width:100px;" placeholder="">
						<? } else { ?>
							<input type="text" name="<?=$var;?>" value="<?=$apcu[$var];?>" class="form-control" style="font-family:monospace;max-width:200px;" placeholder="php">
							<? if($var=='apc.serializer') { ?><small class="form-hint">e.g. <code>php</code> or <code>igbinary</code></small><? } ?>
						<? } ?>
					</div>
				</div>
			<? } ?>
			<? } else { ?>
				<div class="mb-3 row">
					<div class="col text-muted" style="padding-top:4px;">
						APCu is not installed for this PHP version. Install <code>php-pecl-apcu</code> from the <a href="/php-settings/?phpver=<?=$phpver?>&tab=modules">PHP Modules</a> tab.
					</div>
				</div>
			<? } ?>

			<hr style="margin:6px -16px 30px -22px;">
			<div class="mb-3 row">
				<label class="col-3 col-form-label required"></label>
				<div class="col">
					<label class="form-check">
						<input class="form-check-input" type="checkbox" name="save_to_all">
						<span class="form-check-label" style="cursor:pointer;">Save these settings to <b>all PHP versions</b></span>
					</label><br>
					<input type="submit" id="submit-btn" class="btn btn-primary" value="Save" style="max-width:100px;">
				</div>
			</div>
		</div>
		</form>
		</div><!-- #tab-settings -->

		<div class="tab-pane <?=$tab==='modules'?'active show':''?>" id="tab-modules">
		<div class="card-body">
			<div id="modules-loading" class="py-5 text-center">
				<div class="spinner-border text-blue" role="status"></div>
				<div class="mt-2 text-muted">Loading module list...</div>
			</div>
			<div id="modules-content" style="display:none;">
				<table class="table table-vcenter table-hover">
					<thead>
						<tr>
							<th style="width:36px;"></th>
							<th style="width:30%;">Module</th>
							<th>Description</th>
						</tr>
					</thead>
					<tbody id="modules-list"></tbody>
				</table>
				<div class="mt-2">
					<button class="btn btn-primary" id="apply-modules-btn" type="button">Apply Changes</button>
					<span class="text-muted ms-3" id="modules-no-changes" style="display:none;">No changes to apply.</span>
				</div>
			</div>
			<div id="modules-terminal" class="mt-3" style="display:none;">
				<div class="subheader mb-1">Output</div>
				<div id="modules-output" class="term-container" style="min-height:80px;max-height:420px;overflow-y:auto;"></div>
			</div>
		</div>
		</div><!-- #tab-modules -->

		</div><!-- .tab-content -->
		</div><!-- .card -->
	<?php ?>

<? if (!empty($missing_php_versions)): ?>
<div class="modal modal-blur fade" id="modal-install-php" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Install PHP Version</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3 row align-items-center">
          <label class="col-3 col-form-label">PHP Version</label>
          <div class="col">
            <select id="install-php-select" class="form-select" style="max-width:200px;">
              <? foreach ($missing_php_versions as $v): ?>
              <option value="<?=htmlspecialchars($v)?>"><?=htmlspecialchars($v)?></option>
              <? endforeach; ?>
            </select>
          </div>
        </div>
        <div id="install-php-terminal" style="display:none;">
          <div class="subheader mb-1">Output</div>
          <div id="install-php-output" class="term-container" style="min-height:80px;max-height:360px;overflow-y:auto;"></div>
        </div>
      </div>
      <div class="modal-footer">
        <a href="#" id="install-php-cancel" class="btn btn-link link-secondary" data-bs-dismiss="modal">Cancel</a>
        <button id="install-php-close" class="btn btn-primary" style="display:none;" type="button">Close</button>
        <button id="install-php-btn" class="btn btn-primary" type="button">
          <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2"/><polyline points="7 11 12 16 17 11"/><line x1="12" y1="4" x2="12" y2="16"/></svg>
          Install
        </button>
      </div>
    </div>
  </div>
</div>
<? endif; ?>
	<?php
	    include('templates/footer.php'); 
?>
<script>
jQuery(document).ready(function () {
	'use strict';
	$('.alert').delay(5000).fadeOut(2000);

	$('.use-recommended').on('click', function (e) {
		e.preventDefault();
		$('input[name="' + $(this).data('target') + '"]').val($(this).data('value'));
	});

	var originalModules = {};
	var phpVer = '<?= addslashes($phpver) ?>';

	if ($('#tab-modules').hasClass('active')) {
		loadModules();
	}

	function loadModules() {
		$.post('/?ajax=1', { action: 'ajax-php-modules-list', version: phpVer })
			.done(function (r) {
				if (r.error) {
					$('#modules-loading').html('<div class="text-danger">' + $('<span>').text(r.error).html() + '</div>');
					return;
				}
				originalModules = {};
				var rows = '';
				$.each(r.modules, function (i, m) {
					if (m.installed) originalModules[m.pkg] = true;
					rows += '<tr>' +
						'<td><input type="checkbox" class="form-check-input module-check" data-pkg="' + $('<span>').text(m.pkg).html() + '"' + (m.installed ? ' checked' : '') + '></td>' +
						'<td style="cursor:pointer"><code>' + $('<span>').text(m.name).html() + '</code></td>' +
						'<td class="text-muted" style="font-size:.85rem;cursor:pointer">' + $('<span>').text(m.summary).html() + '</td>' +
						'</tr>';
				});
				$('#modules-list').html(rows);
				$('#modules-loading').hide();
				$('#modules-content').show();
			})
			.fail(function () {
				$('#modules-loading').html('<div class="text-danger">Failed to load module list.</div>');
			});
	}

	$('#modules-list').on('click', 'td:not(:first-child)', function () {
		$(this).closest('tr').find('.module-check').trigger('click');
	});

	$('#apply-modules-btn').on('click', function () {
		var to_install = [], to_uninstall = [];
		$('.module-check').each(function () {
			var pkg = $(this).data('pkg');
			var chk = $(this).is(':checked');
			if (chk && !originalModules[pkg]) to_install.push(pkg);
			if (!chk && originalModules[pkg]) to_uninstall.push(pkg);
		});
		if (!to_install.length && !to_uninstall.length) {
			$('#modules-no-changes').show().delay(2000).fadeOut();
			return;
		}
		$('#apply-modules-btn').prop('disabled', true).text('Processing...');
		$('#modules-terminal').show();
		$('#modules-output').text('');
		$.post('/?ajax=1', { action: 'ajax-php-modules-apply', version: phpVer, install: to_install, uninstall: to_uninstall })
			.done(function (r) {
				if (r.error) {
					$('#modules-output').text('Error: ' + r.error);
					$('#apply-modules-btn').prop('disabled', false).text('Apply Changes');
					return;
				}
				pollJob(r.job_id, 0);
			})
			.fail(function () {
				$('#modules-output').text('Request failed.');
				$('#apply-modules-btn').prop('disabled', false).text('Apply Changes');
			});
	});

	function pollJob(job_id, offset) {
		$.post('/?ajax=1', { action: 'ajax-php-modules-status', job_id: job_id, offset: offset })
			.done(function (r) {
				if (r.output) {
					$('#modules-output').append(r.output);
					var el = document.getElementById('modules-output');
					el.scrollTop = el.scrollHeight;
				}
				if (r.done) {
					$('#apply-modules-btn').prop('disabled', false).text('Apply Changes');
					if (r.success) {
						$('#page-alert').html('<div class="alert alert-success">Modules updated successfully.</div>').show();
						$('#modules-terminal').hide();
						$('#modules-loading').show().find('.text-muted').text('Refreshing module list...');
						$('#modules-content').hide();
						loadModules();
					} else {
						$('#page-alert').html('<div class="alert alert-danger">Completed with errors. See output above.</div>').show();
					}
				} else {
					setTimeout(function () { pollJob(job_id, r.offset); }, 1000);
				}
			})
			.fail(function () {
				setTimeout(function () { pollJob(job_id, offset); }, 2000);
			});
	}
	$('#install-php-btn').on('click', function () {
		var ver = $('#install-php-select').val();
		if (!ver) return;
		$(this).prop('disabled', true).text('Installing...');
		$('#install-php-terminal').show();
		$('#install-php-output').text('');
		$.post('/?ajax=1', { action: 'ajax-php-install', version: ver })
			.done(function (r) {
				if (r.error) {
					$('#install-php-output').text('Error: ' + r.error);
					$('#install-php-btn').prop('disabled', false).text('Install');
					return;
				}
				pollInstall(r.job_id, 0, ver);
			})
			.fail(function () {
				$('#install-php-output').text('Request failed.');
				$('#install-php-btn').prop('disabled', false).text('Install');
			});
	});

	function pollInstall(job_id, offset, ver) {
		$.post('/?ajax=1', { action: 'ajax-php-modules-status', job_id: job_id, offset: offset })
			.done(function (r) {
				if (r.output) {
					$('#install-php-output').append(r.output);
					var el = document.getElementById('install-php-output');
					el.scrollTop = el.scrollHeight;
				}
				if (r.done) {
					var msg = r.success
						? '\n\u2713 Done. Exit code: 0\n'
						: '\n\u2717 Completed with errors. Exit code: 1\n';
					$('#install-php-output').append(msg);
					var el = document.getElementById('install-php-output');
					el.scrollTop = el.scrollHeight;
					$('#install-php-btn').hide();
					$('#install-php-cancel').hide();
					$('#install-php-close').show().off('click').on('click', function () { window.location.href = '/php-settings/?phpver=' + encodeURIComponent(ver); });
				} else {
					setTimeout(function () { pollInstall(job_id, r.offset, ver); }, 1000);
				}
			})
			.fail(function () {
				setTimeout(function () { pollInstall(job_id, offset, ver); }, 2000);
			});
	}
});
</script>

</body>
</html>
