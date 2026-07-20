<?php
    include('templates/header.php');
    include('modules/version.php');
    $HOSTNAME = trim(shell_exec('hostname'));
    if($route == 'cancel-reboot') shell_exec("sudo shutdown -c");
    $reboot_date = trim(shell_exec("head -1 /run/systemd/shutdown/scheduled 2>/dev/null | cut -c6-15"));
?>
          <!-- Page title -->
          <div class="page-header d-print-none">
            <div class="row align-items-center">
              <div class="col" style="padding-left:22px;padding-right:0;overflow:hidden;">
                <div class="page-pretitle">Dashboard</div>
                <h2 class="page-title">Server Information</h2>
		        <h3><?=$HOSTNAME;?></h3><br>
                <style>
                  	.sysinfo-table th { background-color:#DEF; white-space:nowrap; }
					@media (min-width: 991px) {
	  			  	    .sysinfo-row { max-width: 100%; min-width: 100%; }
    					.sysinfo-row div:first-child { margin-left:10px; margin-right:-10px; background:#fafafa;border-right:10px solid #fafafa; }
    					.sysinfo-row div:last-child { background:#fafafa; margin-left: 10px; margin-right: -10px;}
						.sysinfo-table th { display: table-cell !important; }
					}
			  	  	.sysinfo-row { margin-left:-15px; margin-right:0; }
                  	.sysinfo-table tr:nth-child(even) td { background:#f6f8fb; }
                  	.sysinfo-table tr:nth-child(odd) td { background:#fff; }
	            	.sysinfo-table td { padding: 6px 15px; }
                  	.sysinfo-table th { padding: 6px 15px; white-space:nowrap; }
                  	.sysinfo-table tr:first-child { color: #6c757d; white-space:nowrap; }
				  	.card:hover { border: 1px solid #cccccc; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
                  	.dash-loading { background: linear-gradient(90deg, #e8eaed 25%, #f5f5f5 50%, #e8eaed 75%); background-size: 200% 100%; animation: dash-shimmer 1.2s infinite; border-radius: 3px; display: inline-block; min-width: 80px; height: 0.85em; vertical-align: middle; }
                  	@keyframes dash-shimmer { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }
                </style>
                <div class="row sysinfo-row p-0">
                  <div class="col-12 col-lg-6 p-0 m-0" style="overflow:hidden;">
                    <table class="sysinfo-table table table-sm w-100" style="margin-bottom:0">
                      <tr><th></th><th></th></tr>
                      <tr><td style="width:20%;min-width:130px;">Processor:</td><td style="width:80%"><b><span id="d-cpu" class="dash-loading"></span></b></td></tr>
                      <tr><td>vCores:</td><td><b><span id="d-vcores" class="dash-loading"></span></b></td></tr>
                      <tr><td>Memory:</td><td><b><span id="d-memory" class="dash-loading"></span></b></td></tr>
                      <tr><td>Diskspace:</td><td><b><span id="d-diskspace" class="dash-loading"></span></b></td></tr>
                      <tr><td>OS:</td><td><b><span id="d-os" class="dash-loading"></span></b></td></tr>
                      <tr><td>Virtualization:</td><td><b><span id="d-virt" class="dash-loading"></span></b></td></tr>
                    </table>
                  </div>
				  <div class="col-12 col-lg-6 p-0" style="overflow:hidden;">
                    <table class="sysinfo-table table table-sm w-100" style="margin-bottom:0">
                      <tr><th style="display:none;" colspan="2"></th></tr>
                      <tr><td style="width:20%;min-width:130px;">IP:</td><td style="width:80%"><b><span id="d-ip" class="dash-loading"></span></b></td></tr>
                      <tr><td>Timezone:</td><td><b><span id="d-timezone" class="dash-loading"></span></b></td></tr>
                      <tr><td>Template:</td><td><b><span id="d-template" class="dash-loading"></span></b></td></tr>
                      <tr><td>PHP Versions:</td><td><span id="d-php" class="dash-loading"></span></td></tr>
                      <tr><td>Reqad Version:</td><td><b><?=$reqad_version[0];?></b> <span class="text-muted">(<?=date("M j, Y", strtotime($reqad_version[1]));?>)</span></td></tr>
                      <tr><td>Uptime:</td><td><b><span id="d-uptime" class="dash-loading"></span></b></td></tr>
                    </table>
                  </div>
                </div>
              </div>
            </div>
          </div>

<?php if($reboot_date != ''): ?>
          <div class="alert alert-warning" role="alert" style="background:#FFE;">
            <div class="d-flex">
              <div>
                  <svg xmlns="http://www.w3.org/2000/svg" class="icon alert-icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><path d="M12 9v2m0 4v.01"></path><path d="M5 19h14a2 2 0 0 0 1.84 -2.75l-7.1 -12.25a2 2 0 0 0 -3.5 0l-7.1 12.25a2 2 0 0 0 1.75 2.75"></path></svg>
              </div>
              <div>
                <h4 class="alert-title">Reboot Scheduled</h4>
                <div class="text-muted">Reboot scheduled for <?=date('M j, Y H:i', $reboot_date);?>. Timezone: <?=date('e');?>. You can <a href="/cancel-reboot" style="color:#f76707">click here to cancel</a>.</div>
              </div>
            </div>
          </div>
<?php endif; ?>

          <!-- Reboot required alert — shown via JS when AJAX data arrives -->
          <div id="d-reboot-req-alert" style="display:none" class="alert alert-warning" role="alert">
            <div class="d-flex">
              <div>
                  <svg xmlns="http://www.w3.org/2000/svg" class="icon alert-icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><path d="M12 9v2m0 4v.01"></path><path d="M5 19h14a2 2 0 0 0 1.84 -2.75l-7.1 -12.25a2 2 0 0 0 -3.5 0l-7.1 12.25a2 2 0 0 0 1.75 2.75"></path></svg>
              </div>
              <div>
                <h4 class="alert-title">Kernel Updated</h4>
                <form method="post">
                <input type="hidden" name="action" value="reboot-server">
                <div class="container-xl" style="margin-left:0; padding:0;">
                  <div class="row">
                    <div class="col-8">
                      <div class="text-muted">Reboot is required to ensure that your system benefits from kernel updates.</div>
                    </div>
                    <div class="col-2">
                      <select name="time" class="form-select" style="min-width:88px;">
                        <option>now</option>
                        <option>22:00</option>
                        <option>23:00</option>
                        <option>00:00</option>
                        <option>01:00</option>
                        <option>02:00</option>
                      </select>
                    </div>
                    <div class="col-2">
                      <input type="submit" value="Reboot" class="btn btn-warning">
                    </div>
                  </div>
                </div>
                </form>
              </div>
            </div>
          </div>

          <!-- Update available alert — shown via JS when AJAX data arrives -->
          <div id="d-update-alert" style="display:none" class="alert alert-success" role="alert">
            <div class="d-flex align-items-center">
              <div>
                <svg xmlns="http://www.w3.org/2000/svg" class="icon alert-icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="12" cy="12" r="9"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
              </div>
              <div class="flex-fill">
                <h4 class="alert-title">Reqad Update Available<span id="d-update-ver"></span></h4>
                <div class="text-muted">A new version is available from the Reqad repository.</div>
              </div>
              <div>
                <button class="btn btn-success" id="reqad-update-open-btn">Update now</button>
              </div>
            </div>
          </div>

          <div class="row row-deck row-cards">
            <div class="col-sm-6 col-lg-3">
              <div class="card">
                <div class="card-body">
                  <div class="subheader">Memory</div>
                  <div class="h1 mb-3" id="d-mem-pct">&nbsp;</div>
                  <div class="d-flex mb-2" id="d-mem-usage">&nbsp;</div>
                  <div class="progress progress-separated">
                    <div id="d-mem-bar" class="progress-bar" style="width:0%" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
                  </div>
                </div>
              </div>
            </div>
            <div class="col-sm-6 col-lg-3">
              <div class="card" style="overflow:visible;">
                <div class="card-body">
                  <div class="d-flex align-items-center">
                    <div class="subheader">Load average</div>
                    <div class="ms-auto lh-1">
                      <div class="dropdown">
                        <a class="dropdown-toggle text-muted chart-dropdown-toggle" href="#" style="cursor:pointer;">Last hour</a>
                        <div class="dropdown-menu dropdown-menu-end">
                          <a class="dropdown-item load-period-item active" href="#" data-period="1h">Last hour</a>
                          <a class="dropdown-item load-period-item" href="#" data-period="24h">Last 24 hours</a>
                          <a class="dropdown-item load-period-item" href="#" data-period="7d">Last 7 days</a>
                        </div>
                      </div>
                    </div>
                  </div>
                  <div class="d-flex align-items-baseline">
                    <div class="h1 mb-0 me-2" id="d-load">&nbsp;</div>
                    <div class="me-auto">
                      <span class="text-green d-inline-flex align-items-center lh-1">
                        0% <svg xmlns="http://www.w3.org/2000/svg" class="icon ms-1" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><polyline points="3 17 9 11 13 15 21 7" /><polyline points="14 7 21 7 21 14" /></svg>
                      </span>
                    </div>
                  </div>
                </div>
                <div id="chart-load" class="chart-sm" style="margin-right:1px;margin-left:1px;height:4rem;margin-bottom:4px;background:linear-gradient(to bottom, transparent calc(100% - 23px), #dbe7f5 calc(100% - 23px), #fff 100%);"></div>
              </div>
            </div>
            <div class="col-sm-6 col-lg-3">
              <div class="card">
                <div class="card-body">
                  <div class="subheader">Disk space</div>
                  <div class="d-flex align-items-baseline">
                    <div class="h1 mb-3 me-2" id="d-disk-pct">&nbsp;</div>
                  </div>
                  <div class="d-flex mb-2" id="d-disk-usage">&nbsp;</div>
                  <div class="progress progress-separated">
                    <div id="d-disk-bar" class="progress-bar" role="progressbar" style="width:0%"></div>
                  </div>
                </div>
              </div>
            </div>
            <div class="col-sm-6 col-lg-3">
              <div class="card" style="overflow:visible;">
                <div class="card-body">
                  <div class="d-flex align-items-center">
                    <div class="subheader">Traffic</div>
                    <div class="ms-auto lh-1">
                      <div class="dropdown">
                        <a class="dropdown-toggle text-muted chart-dropdown-toggle" href="#" style="cursor:pointer;">Last 7 days</a>
                        <div class="dropdown-menu dropdown-menu-end">
                          <a class="dropdown-item traffic-period-item active" href="#" data-period="7d">Last 7 days</a>
                          <a class="dropdown-item traffic-period-item" href="#" data-period="30d">Last 30 days</a>
                          <a class="dropdown-item traffic-period-item" href="#" data-period="90d">Last 3 months</a>
                        </div>
                      </div>
                    </div>
                  </div>
                  <div class="d-flex align-items-baseline">
                    <div class="h1 mb-3 me-2" id="traffic-total">&nbsp;</div>
                    <div class="me-auto" id="d-traffic-trend"></div>
                  </div>
                </div>
                <div id="chart-traffic" class="chart-sm"></div>
              </div>
            </div>
<? if( isset($ini["email"]) && $ini["email"]==1 ) { ?>
            <div class="col-sm-6 col-lg-3" id="d-mailqueue-card" style="display:none;">
              <a href="/email-stats/" class="card card-link">
                <div class="card-body">
                  <div class="d-flex align-items-center">
                    <div class="subheader">Mail queue</div>
                  </div>
                  <div class="d-flex align-items-baseline">
                    <div class="h1 mb-3 me-2"><span id="d-mailqueue">&nbsp;</span></div>
                    <div class="me-auto">
                      <span class="text-yellow d-inline-flex align-items-center lh-1">
                        <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 7a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v10a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-10z"/><path d="M3 7l9 6l9 -6"/></svg>
                      </span>
                    </div>
                  </div>
                  <div class="text-muted">messages waiting to be delivered</div>
                </div>
              </a>
            </div>
<? } ?>
<?php
              // Hosting actions (email/db/phpMyAdmin) require accounts to be
              // enabled; accounts=0 marks a non-hosting box (e.g. a WireGuard
              // appliance) and hides them. Plugin tiles (below) always show.
              $_accounts_on   = !isset($ini['accounts']) || (int)$ini['accounts'] != 0;
              $_plugin_tiles  = plugin_dashboard_actions($ini);
              if ($_accounts_on || !empty($_plugin_tiles)):
?>
            <div class="col-lg-12" id="d-common-actions-col">
                  <div class="card">
                    <div class="card-body">
                      <h3 class="card-title">Common Actions</h3>
<? if( $_accounts_on ) { ?>
<? if( isset($ini["email"]) && $ini["email"]==1 ) { ?>
                      <div style="width:190px;float:left;margin-bottom:20px;">
                        <a href="email-accounts/"><img src="images/email-accounts.png" style="vertical-align:-10px;"></a> <a href="email-accounts/">Email Accounts</a><br />
                      </div>
                      <div style="width:150px;float:left;margin-bottom:20px;">
                        <a href="webmail/"><img src="images/rouncube.png" width="48" height="48" style="vertical-align:-10px;"></a> &nbsp;<a href="webmail/">Webmail</a><br />
                      </div>
<? } ?>
                      <div style="width:210px;float:left;margin-bottom:20px;">
                        <a href="databases/"><img src="images/databases.png" style="vertical-align:-10px;"></a> <a href="databases/">Manage Databases </a>
                      </div>
                      <div style="width:190px;float:left">
                        <a href="phpmyadmin/" target="_blank"><img src="images/phpmyadmin.png" style="vertical-align:-10px;"></a> &nbsp;<a href="phpmyadmin/" target="_blank">PhpMyAdmin</a><br />
                      </div>
<? } ?>
<?php foreach($_plugin_tiles as $_a): ?>
                      <div style="width:190px;float:left;margin-bottom:20px;">
                        <a href="<?=htmlspecialchars($_a['url'])?>"><?=isset($_a['icon'])?$_a['icon']:''?></a> <a href="<?=htmlspecialchars($_a['url'])?>"><?=htmlspecialchars($_a['label'])?></a><br />
                      </div>
<?php endforeach; ?>
                    </div>
                  </div>
              </div>
<?php endif; ?>
          </div>

<?php
$_welcome_dismissed = $db->querySingle('SELECT value FROM settings WHERE name="welcome_dismissed"');
if (!$_welcome_dismissed) {
?>
<!-- Welcome modal -->
<div class="modal modal-blur fade" id="modal-welcome" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-md" role="document">
    <div class="modal-content" style="border-top: 8px solid #2a6099">
      <div class="modal-header">
        <h1 class="modal-title" style="font-size:18pt;color:#2a6099;line-height:50pt;margin-top:20px;">Welcome to Reqad</h1>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p>Thank you for installing Reqad, an open-source control panel for web hosting.
		A few things to do next:</p>
        <ol style="padding-left:18px;line-height:2.2;">
          <li>Configure your <a href="/settings/">notification email and SMTP</a> settings</li>
          <li>Set up a <a href="/dns-settings/">DNS provider</a> if you manage DNS zones</li>
          <li>Add your first <a href="/accounts/">hosting account</a></li>
          <li>Add your <a href="/ssh-keys/">SSH keys</a> for secure server access</li>
        </ol>
      </div>
      <div class="modal-footer d-flex justify-content-between align-items-center">
        <label class="form-check mb-0" style="cursor:pointer;">
          <input class="form-check-input" type="checkbox" id="welcome-dismiss-check">
          <span class="form-check-label text-muted">Don't show this again</span>
        </label>
        <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Get started</button>
      </div>
    </div>
  </div>
</div>
<script>
window.addEventListener('load', function () {
  var el = document.getElementById('modal-welcome');
  if (!el) return;
  var modal = new bootstrap.Modal(el);
  modal.show();
  el.addEventListener('hide.bs.modal', function () {
    if (document.getElementById('welcome-dismiss-check').checked) {
      $.post('/', { action: 'ajax-welcome-dismiss' });
    }
  });
});
</script>
<?php } ?>

<!-- Update modal — always rendered, opened via JS when update is available -->
<div class="modal modal-blur fade" id="modal-reqad-update" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Update Reqad<span id="d-update-modal-ver"></span></h5>
      </div>
      <div class="modal-body">
        <div class="subheader mb-1">Output</div>
        <div id="reqad-update-output" class="term-container" style="min-height:80px;max-height:360px;overflow-y:auto;"></div>
      </div>
      <div class="modal-footer">
        <button id="reqad-update-close" class="btn btn-success" type="button" disabled>Close</button>
      </div>
    </div>
  </div>
</div>

<?php include('templates/footer.php'); ?>
<script>
(function () {
  var loadChart = null, trafficChart = null;

  function fetchLoad(period) {
    $.getJSON('/?action=ajax-chart-load&period=' + period, function (r) {
      if (!loadChart) {
        loadChart = new ApexCharts(document.getElementById('chart-load'), {
          chart: { type: 'area', height: 64, offsetY: -20, sparkline: { enabled: true }, animations: { enabled: false }, background: 'transparent' },
          series: [{ name: 'Load avg', data: r.series }],
          stroke: { width: 2, lineCap: 'round', curve: 'smooth' },
          fill: { type: 'gradient', gradient: { type: 'vertical', colorStops: [{ offset: 0, color: '#b2cbea', opacity: 1 }, { offset: 100, color: '#dbe7f5', opacity: 1 }] } },
          dataLabels: { enabled: false },
          xaxis: { type: 'datetime', labels: { datetimeUTC: false } },
          yaxis: {
				min: function(min) { return Math.min(min, -0.01); },
				max: function(max) { return Math.max(max, 0.3); },
				forceNiceScale: false
		  },
          colors: ['#206bc4'],
          legend: { show: false },
          tooltip: { x: { format: period === '1h' ? 'HH:mm' : 'MMM dd HH:mm' }, y: { formatter: function (v) { return parseFloat(v).toFixed(2); } } }
        });
        loadChart.render();
      } else {
        loadChart.updateOptions({ tooltip: { x: { format: period === '1h' ? 'HH:mm' : 'MMM dd HH:mm' } } }, false, false);
        loadChart.updateSeries([{ name: 'Load avg', data: r.series }]);
      }
    });
  }

  function fetchTraffic(period) {
    $.getJSON('/?action=ajax-chart-traffic&period=' + period, function (r) {
      if (!r.series || r.series.length === 0) {
        $('#traffic-total').text('—');
        $('#chart-traffic').html('<p class="text-muted" style="font-size:13px;padding:4px 8px;margin-top:-30px;text-align:center;">No data. <a href="/services/">Check if <strong>vnstat</strong> service</a> is running.</p>');
        return;
      }
      if (r.total) $('#traffic-total').text(r.total);
      var fmt = period === '24h' ? 'HH:00' : 'MMM dd';
      if (!trafficChart) {
        trafficChart = new ApexCharts(document.getElementById('chart-traffic'), {
          chart: { type: 'bar', height: 40, sparkline: { enabled: true }, animations: { enabled: false } },
          plotOptions: { bar: { columnWidth: '60%' } },
          series: [{ name: 'Traffic (MB)', data: r.series }],
          fill: { opacity: 1 },
          dataLabels: { enabled: false },
          xaxis: { type: 'datetime', labels: { datetimeUTC: false } },
          yaxis: {
				min: -10,
				max: function(max) { return Math.max(max, 0.1)+2; },
				forceNiceScale: true
		  },
          colors: ['#206bc4'],
          legend: { show: false },
          tooltip: { x: { format: fmt }, y: { formatter: function (v) { return v + ' MB'; } } }
        });
        trafficChart.render();
      } else {
        trafficChart.updateOptions({ tooltip: { x: { format: fmt } } }, false, false);
        trafficChart.updateSeries([{ name: 'Traffic (MB)', data: r.series }]);
      }
    });
  }

  $(document).ready(function () {
    fetchLoad('1h');
    fetchTraffic('7d');

    $.post('/', { action: 'ajax-dashboard-memory' }, function (r) {
      var memColor = r.mem_pct >= 95 ? 'bg-danger' : (r.mem_pct >= 90 ? 'bg-warning' : 'bg-primary');
      $('#d-mem-pct').text(r.mem_pct + '%');
      $('#d-mem-usage').html('<div>Using <b>' + r.mem_used_h + '</b> of <b>' + r.mem_total_h + '</b></div>');
      $('#d-mem-bar').css('width', Math.max(1, r.mem_pct) + '%').addClass(memColor).attr('aria-valuenow', r.mem_pct);
    }, 'json');

    $.post('/', { action: 'ajax-dashboard-disk' }, function (r) {
      var diskColor = parseInt(r.disk_used_p) >= 95 ? 'bg-danger' : (parseInt(r.disk_used_p) >= 90 ? 'bg-warning' : 'bg-primary');
      $('#d-disk-pct').text(r.disk_used_p);
      $('#d-disk-usage').html('<div>Using Storage <strong>' + r.disk_used_h + '</strong> of ' + r.disk_total_h + '</div>');
      $('#d-disk-bar').css('width', r.disk_used_p).addClass(diskColor);
    }, 'json');

    // Custom dropdown open/close (bypasses Bootstrap dropdown init)
    $('.chart-dropdown-toggle').on('click', function (e) {
      e.preventDefault();
      var $menu = $(this).siblings('.dropdown-menu');
      var isOpen = $menu.hasClass('show');
      $('.chart-dropdown-toggle').siblings('.dropdown-menu').removeClass('show');
      if (!isOpen) $menu.addClass('show');
    });

    $(document).on('click', function (e) {
      if (!$(e.target).closest('.dropdown').length) {
        $('.chart-dropdown-toggle').siblings('.dropdown-menu').removeClass('show');
      }
    });

    $('.load-period-item').on('click', function (e) {
      e.preventDefault();
      var $this = $(this);
      $this.closest('.dropdown-menu').find('.dropdown-item').removeClass('active');
      $this.addClass('active');
      $this.closest('.dropdown').find('.chart-dropdown-toggle').text($this.text());
      $this.closest('.dropdown-menu').removeClass('show');
      fetchLoad($this.data('period'));
    });

    $('.traffic-period-item').on('click', function (e) {
      e.preventDefault();
      var $this = $(this);
      $this.closest('.dropdown-menu').find('.dropdown-item').removeClass('active');
      $this.addClass('active');
      $this.closest('.dropdown').find('.chart-dropdown-toggle').text($this.text());
      $this.closest('.dropdown-menu').removeClass('show');
      fetchTraffic($this.data('period'));
    });

    // Load dashboard stats via AJAX so the page renders immediately
    $.post('/', { action: 'ajax-dashboard-data' }, function (r) {
      // Sysinfo table
      var cpu = r.cpu_name + (r.cpu_freq ? ' @ ' + r.cpu_freq : '');
      var cpuIcon = '';
      if (/intel/i.test(r.cpu_name)) cpuIcon = '<img src="/dist/img/brand-intel.svg" style="height:14px;vertical-align:middle;margin-right:5px;margin-top:-2px;">';
      else if (/amd/i.test(r.cpu_name)) cpuIcon = '<img src="/dist/img/brand-amd.svg" style="height:14px;vertical-align:middle;margin-right:5px;margin-top:-2px;">';
      else if (/arm|cortex|neoverse/i.test(r.cpu_name)) cpuIcon = '<img src="/dist/img/brand-arm.svg" style="height:14px;vertical-align:middle;margin-right:5px;margin-top:-2px;">';
      $('#d-cpu').html(cpuIcon + $('<span>').text(cpu).html()).removeClass('dash-loading');
      $('#d-vcores').text(r.vcores).removeClass('dash-loading');
      $('#d-memory').text(r.memory).removeClass('dash-loading');
      $('#d-diskspace').text(r.diskspace).removeClass('dash-loading');
      $('#d-os').text(r.os).removeClass('dash-loading');
      $('#d-virt').text(r.virt ? r.virt : '—').removeClass('dash-loading');
      $('#d-ip').text(r.ip).removeClass('dash-loading');
      $('#d-timezone').text(r.timezone).removeClass('dash-loading');
      $('#d-template').text(r.template).attr('title', r.template_details).removeClass('dash-loading');
      $('#d-php').html(r.php_versions.map(function(v) {
        var isDefault = v.indexOf('(default)') !== -1;
        var ver = v.replace(' (default)', '');
        return '<span class="badge ' + (isDefault ? 'bg-blue' : 'bg-blue-lt') + ' p-2 me-2">' + ver + '</span>';
      }).join('')).removeClass('dash-loading');
      $('#d-uptime').text(r.uptime).removeClass('dash-loading');
    }, 'json');


    $.post('/', { action: 'ajax-dashboard-status' }, function (r) {
      $('#d-load').text(r.load);
      if (r.traffic_trend !== null) {
        var svgUp = '<svg xmlns="http://www.w3.org/2000/svg" class="icon ms-1" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><polyline points="3 17 9 11 13 15 21 7" /><polyline points="14 7 21 7 21 14" /></svg>';
        var svgDn = '<svg xmlns="http://www.w3.org/2000/svg" class="icon ms-1" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><polyline points="3 7 9 13 13 9 21 17" /><polyline points="14 17 21 17 21 10" /></svg>';
        var trendClass = r.traffic_trend_dir === 'up' ? 'text-green' : 'text-red';
        var trendSvg   = r.traffic_trend_dir === 'up' ? svgUp : svgDn;
        $('#d-traffic-trend').html('<span class="' + trendClass + ' d-inline-flex align-items-center lh-1" title="vs. same days last month">' + r.traffic_trend + '% ' + trendSvg + '</span>');
      }
      if (r.mail_queue > 10) {
        $('#d-mailqueue').text(r.mail_queue);
        $('#d-mailqueue-card').css('display', '');
        $('#d-common-actions-col').removeClass('col-lg-12').addClass('col-lg-9');
      }
      var rebootDate = r.reboot_date;
      setTimeout(function () {
        $.post('/', { action: 'ajax-dashboard-reboot' }, function (r) {
          if (r.reboot_req && !rebootDate && <?= $reboot_date !== '' ? 'false' : 'true' ?>) $('#d-reboot-req-alert').show();
        }, 'json');
        $.post('/', { action: 'ajax-dashboard-update' }, function (r) {
          if (r.update_avail) {
            $('#d-update-ver').text(r.update_ver ? ' — ' + r.update_ver : '');
            $('#d-update-modal-ver').text(r.update_ver ? ' to ' + r.update_ver : '');
            $('#d-update-alert').show();
          }
        }, 'json');
      }, 1000);
    }, 'json');


    // Reqad update modal
    var reqadModal = new bootstrap.Modal(document.getElementById('modal-reqad-update'), { backdrop: 'static', keyboard: false });
    var STORAGE_KEY = 'reqad_update_job';

    function startPolling(job_id, offset) {
      sessionStorage.setItem(STORAGE_KEY, JSON.stringify({ job_id: job_id, offset: offset }));
      pollReqadUpdate(job_id, offset, false);
    }

    var pending = sessionStorage.getItem(STORAGE_KEY);
    if (pending) {
      try { pending = JSON.parse(pending); } catch(e) { pending = null; }
      if (pending && pending.job_id) {
        reqadModal.show();
        $('#reqad-update-output').text('Reconnecting...\n');
        pollReqadUpdate(pending.job_id, pending.offset, false);
      }
    }

    $(document).on('click', '#reqad-update-open-btn', function () {
      reqadModal.show();
      $.post('/?ajax=1', { action: 'ajax-reqad-update' })
        .done(function (r) {
          if (r.error) {
            $('#reqad-update-output').text('Error: ' + r.error);
            $('#reqad-update-close').prop('disabled', false);
            return;
          }
          startPolling(r.job_id, 0);
        })
        .fail(function () {
          $('#reqad-update-output').text('Request failed.');
          $('#reqad-update-close').prop('disabled', false);
        });
    });

    $('#reqad-update-close').on('click', function () { window.location.reload(); });

    function pollReqadUpdate(job_id, offset, restarting) {
      $.post('/?ajax=1', { action: 'ajax-php-modules-status', job_id: job_id, offset: offset })
        .done(function (r) {
          sessionStorage.setItem(STORAGE_KEY, JSON.stringify({ job_id: job_id, offset: r.offset }));
          if (r.output) {
            $('#reqad-update-output').append(r.output);
            document.getElementById('reqad-update-output').scrollTop = 9999;
          }
          if (r.done) {
            sessionStorage.removeItem(STORAGE_KEY);
            var msg = r.success ? '\n✓ Update complete.\n' : '\n✗ Completed with errors.\n';
            $('#reqad-update-output').append(msg);
            document.getElementById('reqad-update-output').scrollTop = 9999;
            $('#reqad-update-close').prop('disabled', false);
          } else {
            setTimeout(function () { pollReqadUpdate(job_id, r.offset, false); }, 1000);
          }
        })
        .fail(function () {
          if (!restarting) {
            $('#reqad-update-output').append('\nServer restarting...\n');
            document.getElementById('reqad-update-output').scrollTop = 9999;
          }
          setTimeout(function () { pollReqadUpdate(job_id, offset, true); }, 2000);
        });
    }
  });
})();
</script>
	</body>
</html>
