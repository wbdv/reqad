<?php
    include('templates/header.php');
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
                  MySQLTuner
                </h2>
              </div>
              <div class="col-auto ms-auto d-print-none">
                <div class="btn-list">
                  <a href="#" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal-report">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    Generate <span class="d-none d-sm-inline">&nbsp;a new&nbsp;</span> report
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

<?
    $uptime = (int)(trim(shell_exec("sudo mysql -NB -e \"SHOW STATUS WHERE Variable_name='Uptime'\" | awk {'print $2'}")));
    if($uptime==0) {
?>
	<p style="padding:14px;">MySQL Server is not running.</p>
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
<?
		$report = _PATH.'/reports/mysqltuner.txt';
		if(is_file($report)) {
			/* plain text, the marks coloured: [OK] green, [!!] red, [--] grey; section lines bold */
			$text = htmlspecialchars(rtrim((string)@file_get_contents($report)), ENT_QUOTES);
			$text = preg_replace('/^\[OK\]/m', '[<span class="mt-ok">OK</span>]', $text);
			$text = preg_replace('/^\[!!\]/m', '[<span class="mt-bad">!!</span>]', $text);
			$text = preg_replace('/^\[--\]/m', '[<span class="mt-info">--</span>]', $text);
			$text = preg_replace('/^(-{8} .*)$/m', '<span class="mt-head">$1</span>', $text);
?>
		<div class="alert alert-success" role="alert" style="background:#EFE;">
			<div class="text">
				<b>Note:</b> MySQLTuner report was generated on: <?=date("F d, Y \a\\t H:i", filemtime($report));?>.
			</div>
		</div><br/>
		<div class="col-12">
			<div class="card" id="mysqltuner"><div class="term-container"><?=$text;?></div></div>
		</div>
<?
		} else {
?>
			<div class="alert alert-info" role="alert" style="background:#EEF0FF;">
				<div class="text"><b>Note:</b> there is no MySQLTuner report yet, please <a href="#" data-bs-toggle="modal" data-bs-target="#modal-report">generate a new report</a> first.</div>
			</div>
<?
		}
	} 
?>
          </div>
        </div>
      </div>
    </div>

<div class="modal modal-blur fade" id="modal-report" tabindex="-1" role="dialog" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-xl modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Generate a new MySQLTuner report</h5>
      </div>
      <div class="modal-body">
        <div class="subheader mb-1">Output</div>
        <div id="report-output" class="term-container" style="min-height:80px;max-height:460px;overflow-y:auto;"></div>
      </div>
      <div class="modal-footer">
        <button id="report-close" class="btn btn-primary" type="button" disabled>Close</button>
      </div>
    </div>
  </div>
</div>

<?php
    include('templates/footer.php');
?>
<style>
	#mysqltuner .mt-ok   { color:#5fd35f; }
	#mysqltuner .mt-bad  { color:#ff6b6b; }
	#mysqltuner .mt-info { color:#8aa4c8; }
	#mysqltuner .mt-head { color:#fff; font-weight:bold; }
</style>
<script>
jQuery(document).ready(function () {
	'use strict';
	var running = false;

	function out(text) {
		var el = document.getElementById('report-output');
		$(el).append(document.createTextNode(text));
		el.scrollTop = el.scrollHeight;
	}

	function poll(job_id, offset) {
		$.post('/?ajax=1', { action: 'ajax-php-modules-status', job_id: job_id, offset: offset })
			.done(function (r) {
				if (r.output) out(r.output);
				if (r.done) {
					out(r.success ? '\n✓ Done. Exit code: 0\n' : '\n✗ Completed with errors. Exit code: 1\n');
					$('#report-close').prop('disabled', false);
				} else {
					setTimeout(function () { poll(job_id, r.offset); }, 1000);
				}
			})
			.fail(function () { setTimeout(function () { poll(job_id, offset); }, 2000); });
	}

	$('#modal-report').on('show.bs.modal', function () {
		if (running) return;
		running = true;
		$('#report-output').text('');
		$('#report-close').prop('disabled', true);
		$.post('/?ajax=1', { action: 'ajax-mysqltuner' }, null, 'json')
			.done(function (r) {
				if (r.error) { out('Error: ' + r.error + '\n'); $('#report-close').prop('disabled', false); return; }
				if (r.running) out('A report is being generated already, showing its output.\n');
				poll(r.job_id, 0);
			})
			.fail(function () { out('Request failed.\n'); $('#report-close').prop('disabled', false); });
	});

	$('#report-close').on('click', function () { window.location.reload(); });
});
</script>
</body>
</html>
