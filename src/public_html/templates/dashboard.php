<?php
    include('templates/header.php');
    include('modules/version.php');
    $HOSTNAME = trim(shell_exec('hostname'));
    if($route == 'cancel-reboot') shell_exec("sudo shutdown -c");
    $reboot_date = trim(shell_exec("head -1 /run/systemd/shutdown/scheduled 2>/dev/null | cut -c6-15"));

    /* Server (root) SSH public key — shown in the key modal so it can be copied
       to other servers. PHP runs as the reqad user and cannot read /root at all,
       so this needs sudo (see backupdb.php / remote_backup_ssh). */
    $server_pubkey = '';
    foreach(array('/root/.ssh/id_rsa.pub', '/root/.ssh/id_ed25519.pub') as $_pk) {
        $_out = trim(shell_exec('sudo -n cat '.escapeshellarg($_pk).' 2>/dev/null'));
        if($_out != '') { $server_pubkey = $_out; break; }
    }
?>
          <!-- Page title -->
          <div class="page-header d-print-none">
            <div class="row align-items-center">
              <div class="col" style="padding-left:22px;padding-right:0;overflow:hidden;">
                <div class="page-pretitle">Dashboard</div>
                <h2 class="page-title">Server Information</h2>
		        <h3 class="d-inline-block"><?=$HOSTNAME;?></h3>
                <span type="button" id="server-pubkey-btn" class="btn btn-icon btn-sm ms-2 align-middle" data-bs-toggle="modal" data-bs-target="#modal-server-pubkey" title="Server SSH public key" style="margin-top:-6px;margin-left:-5px !important;border:none;background:transparent;color:#206bc4CC !important">
				  <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-key-round h-3.5 w-3.5" aria-hidden="true"><path d="M2.586 17.414A2 2 0 0 0 2 18.828V21a1 1 0 0 0 1 1h3a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h1a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h.172a2 2 0 0 0 1.414-.586l.814-.814a6.5 6.5 0 1 0-4-4z"></path><circle cx="16.5" cy="7.5" r=".5" fill="currentColor"></circle></svg>
				</span><br>
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
                      <?php $_rv = reqad_version_label($reqad_version); ?><tr><td>Reqad Version:</td><td><b><?=$_rv[0];?></b> <span class="text-muted">(<?=$_rv[1];?>)</span></td></tr>
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
                <div class="text-muted">Reboot scheduled for <?=date('M j, Y H:i T', $reboot_date);?>. Timezone: <?=date('e', $reboot_date);?>. You can <a href="/cancel-reboot" style="color:#f76707">click here to cancel</a>.</div>
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
              <div class="flex-fill">
                <h4 class="alert-title">Kernel Updated</h4>
                <form method="post">
                <input type="hidden" name="action" value="reboot-server">
                <div class="d-flex flex-wrap align-items-center gap-2">
                  <div class="text-muted" style="min-width:200px;">Reboot is required to ensure that your system benefits from kernel updates.</div>
                  <div class="d-flex align-items-center gap-2">
                    <select name="time" class="form-select" style="width:auto;">
                      <option>now</option>
                      <option>22:00</option>
                      <option>23:00</option>
                      <option>00:00</option>
                      <option>01:00</option>
                      <option>02:00</option>
                    </select>
                    <input type="submit" value="Reboot" class="btn btn-warning">
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

          <!-- Needs attention: app/functions/health.php. A tile in the grid below,
               the full list in #modal-health; both filled by the script at the end. -->
          <?php $health_payload = health_payload($db); ?>
          <style>
            #modal-health .card-header { flex-wrap:wrap; gap:6px 10px; }
            #modal-health .h-row { display:flex; gap:12px; align-items:flex-start; padding:10px 16px 10px 0px; border-left:0px solid transparent; margin: 4px 0; border-bottom: 1px solid #FFF;}
            #modal-health .h-list > .h-row + .h-row, #modal-health .h-list > .h-row + .h-group, #modal-health .h-list > .h-group + .h-row, #modal-health .h-list > .h-group + .h-group { }
            #modal-health .h-group .h-items .h-row { border-top:1px solid #f2f4f7; padding-left:36px; }
            #modal-health .h-sev-critical { border-left-color:#d63939; }
            #modal-health .h-sev-warning  { border-left-color:#f59f00; }
            #modal-health .h-sev-info     { border-left-color:#4299e1; }
            #modal-health .h-icon { flex:none; width:20px; margin-top:1px; }
            #modal-health .h-body { flex:1; min-width:0; }
            #modal-health .h-title { font-weight:500; color:#1d273b; overflow-wrap:anywhere; }
            #modal-health .h-meta { font-size:.85rem; color:#6c7a91; margin-top:2px; overflow-wrap:anywhere; }
            #modal-health .h-acct { color:#354052; font-weight:500; }
            #modal-health .modal-body { padding:4px 1.5rem 1rem; }
            #modal-health .h-list { overflow:hidden; }
            #modal-health .h-actions { flex:none; display:flex; gap:6px; align-items:center; }
            #modal-health .h-actions .btn { flex:none; width:max-content; white-space:nowrap; }
            #modal-health .h-toggle { cursor:pointer; user-select:none; }
            #modal-health .h-toggle .h-chev { display:inline-block; transition:transform .15s; color:#8a97ab; }
            #modal-health .h-group.open .h-chev { transform:rotate(90deg); }
            #modal-health .h-group .h-items { display:none; }
            #modal-health .h-group.open .h-items { display:block; }
            #modal-health .h-hidden { opacity:.6; }
            #modal-health .h-more, #modal-health .h-hidden-link { color:#206bc4; cursor:pointer; }
            #modal-health .h-empty { padding:18px 16px; color:#6c7a91; display:flex; gap:10px; align-items:center; }
            #modal-health .dropdown-menu { min-width:150px; }
            .h-counts .badge { font-weight:600; margin-right:4px; border:1px solid; }
            .h-counts .bg-red-lt { border-color:rgba(214,57,57,.45); }
            .h-counts .bg-orange-lt { border-color:rgba(247,103,7,.45); }
            .h-counts .bg-azure-lt { border-color:rgba(66,153,225,.45); }
            .h-counts .bg-green-lt { border-color:rgba(47,179,68,.45); }
            .h-counts .bg-muted-lt { border-color:rgba(98,105,118,.35); }
            @media (max-width: 575px) { #modal-health .h-row { flex-wrap:wrap; } #modal-health .h-actions { width:100%; padding-left:32px; } }
          </style>

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
            <div class="col-sm-6 col-lg-3" id="d-health-col">
              <div class="card" id="health-card">
                <div class="card-body">
                  <div class="subheader">Needs attention</div>
                  <div class="h-counts mt-2 mb-2" id="health-counts">&nbsp;</div>
                  <div class="text-muted mb-3" id="health-top">&nbsp;</div>
                  <button type="button" class="btn btn-white" data-bs-toggle="modal" data-bs-target="#modal-health" id="health-open">Show all</button>
                </div>
              </div>
            </div>
<?php
              // Hosting actions (email/db/phpMyAdmin) require accounts to be
              // enabled; accounts=0 marks a non-hosting box (e.g. a WireGuard
              // appliance) and hides them. Plugin tiles (below) always show.
              $_accounts_on   = !isset($ini['accounts']) || (int)$ini['accounts'] != 0;
              $_plugin_tiles  = plugin_dashboard_actions($ini);
              if ($_accounts_on || !empty($_plugin_tiles)):
?>
            <div class="col-lg-9" id="d-common-actions-col">
                  <div class="card">
                    <div class="card-body">
                      <h3 class="card-title">Common Actions</h3>
                      <style>
                        .ca-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(185px, 1fr)); gap:18px 12px; }
                        .ca-item { display:flex; align-items:center; gap:10px; min-width:0; }
                        .ca-icon { flex:none; width:48px; height:48px; display:flex; align-items:center; justify-content:center; }
                        .ca-icon img, .ca-icon svg { max-width:48px; max-height:48px; }
                      </style>
                      <div class="ca-grid">
<? if( $_accounts_on ) { ?>
<? if( isset($ini["email"]) && $ini["email"]==1 ) { ?>
                        <div class="ca-item"><a class="ca-icon" href="email-accounts/"><img src="images/email-accounts.png" alt=""></a><a href="email-accounts/">Email Accounts</a></div>
                        <div class="ca-item"><a class="ca-icon" href="webmail/"><img src="images/rouncube.png" width="48" height="48" alt=""></a><a href="webmail/">Webmail</a></div>
<? } ?>
                        <div class="ca-item"><a class="ca-icon" href="databases/"><img src="images/databases.png" alt=""></a><a href="databases/">Manage Databases</a></div>
                        <div class="ca-item"><a class="ca-icon" href="phpmyadmin/" target="_blank"><img src="images/phpmyadmin.png" alt=""></a><a href="phpmyadmin/" target="_blank">PhpMyAdmin</a></div>
<? } ?>
<?php foreach($_plugin_tiles as $_a): ?>
                        <div class="ca-item"><a class="ca-icon" href="<?=htmlspecialchars($_a['url'])?>"><?=isset($_a['icon'])?$_a['icon']:''?></a><a href="<?=htmlspecialchars($_a['url'])?>"><?=htmlspecialchars($_a['label'])?></a></div>
<?php endforeach; ?>
                      </div>
                    </div>
                  </div>
              </div>
<?php endif; ?>
          </div>

<!-- Needs attention: the full list -->
<div class="modal modal-blur fade" id="modal-health" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Needs attention</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="h-list" id="health-list"></div>
      </div>
      <div class="modal-footer">
        <div class="me-auto small text-muted">
          <span class="h-counts" id="health-counts2"></span>
          <span id="health-last2"></span><span id="health-foot"></span>
        </div>
        <button type="button" class="btn health-run">Check now</button>
        <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
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

<!-- Server SSH public key modal -->
<div class="modal modal-blur fade" id="modal-server-pubkey" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Server SSH Public Key</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
<?php if($server_pubkey != ''): ?>
        <p class="text-muted">Add this key to <code>~/.ssh/authorized_keys</code> on another server to let this server connect to it over SSH without a password.</p>
        <div style="position:relative">
          <button id="server-pubkey-copy" type="button" title="Copy to clipboard" style="position:absolute;top:6px;right:6px;background:none;border:none;cursor:pointer;color:#6c757d;padding:4px;line-height:1;">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M9 5h-2a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-12a2 2 0 0 0 -2 -2h-2" /><path d="M13 3m0 2a2 2 0 0 1 2 -2h0a2 2 0 0 1 2 2v0a2 2 0 0 1 -2 2h0a2 2 0 0 1 -2 -2z" /></svg>
          </button>
          <pre id="server-pubkey-text" style="font-family:monospace monospace;word-break:break-all;white-space:pre-wrap;color:#000;display:block;line-height:1.2rem;padding-right:36px;margin-bottom:0"><?=htmlspecialchars($server_pubkey);?></pre>
        </div>
        <div id="server-pubkey-note" class="mt-2 small" style="display:none"></div>
<?php else: ?>
        <div class="alert alert-warning mb-0" role="alert">
          No SSH key pair was found at <code>/root/.ssh/id_rsa.pub</code>. Generate one with
          <code>ssh-keygen -t rsa -b 4096 -f /root/.ssh/id_rsa -N ""</code> and reload this page.
        </div>
<?php endif; ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>
<?php include('templates/footer.php'); ?>
<script>
/* Needs attention card: one renderer for the first paint and every refresh. */
$(function () {
  var H = <?=json_encode($health_payload, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);?>;
  var GROUP_AT = 3, SHOW_ROWS = 50, showHidden = false, showAll = false;
  var SEV = { critical: 0, warning: 1, info: 2 };
  var ICON = {
    critical: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#d63939" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v4m0 4h.01"/></svg>',
    warning:  '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#f59f00" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v2m0 4v.01"/><path d="M5 19h14a2 2 0 0 0 1.84 -2.75l-7.1 -12.25a2 2 0 0 0 -3.5 0l-7.1 12.25a2 2 0 0 0 1.75 2.75"/></svg>',
    info:     '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#4299e1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8h.01M11 12h1v4h1"/></svg>'
  };
  var OK_ICON = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#2fb344" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9 12l2 2l4 -4"/></svg>';
  function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }

  function actions(it) {
    var h = '';
    if (it.fix_url) h += '<a class="btn btn-white btn-sm" href="' + esc(it.fix_url) + '">' + esc(it.fix_label || 'Open') + '</a>';
    if (it.hidden) {
      h += '<button type="button" class="btn btn-ghost-secondary btn-sm h-restore" data-id="' + esc(it.id) + '">Show again</button>';
    } else {
      h += '<div class="dropdown"><button type="button" class="btn btn-ghost-secondary btn-sm h-dd-toggle" title="Hide this">Hide</button>'
         + '<div class="dropdown-menu dropdown-menu-end">'
         + '<a class="dropdown-item h-dismiss" href="#" data-id="' + esc(it.id) + '" data-days="7">For 7 days</a>'
         + '<a class="dropdown-item h-dismiss" href="#" data-id="' + esc(it.id) + '" data-days="30">For 30 days</a>'
         + '<a class="dropdown-item h-dismiss" href="#" data-id="' + esc(it.id) + '" data-days="0">Until I show it again</a>'
         + '</div></div>';
    }
    return h;
  }

  function row(it, inGroup) {
    var meta = [];
    if (!inGroup) meta.push(it.account ? '<span class="h-acct">' + esc(it.account) + '</span>' : '<span class="h-acct">Server</span>');
    if (it.detail) meta.push(esc(it.detail));
    if (it.hidden) meta.push(it.hidden_until > 0 ? 'hidden until ' + new Date(it.hidden_until * 1000).toLocaleDateString() : 'hidden');
    return '<div class="h-row h-sev-' + it.severity + (it.hidden ? ' h-hidden' : '') + '">'
         + '<div class="h-icon">' + ICON[it.severity] + '</div>'
         + '<div class="h-body"><div class="h-title">' + esc(it.title) + '</div>'
         + (meta.length ? '<div class="h-meta">' + meta.join(' · ') + '</div>' : '') + '</div>'
         + '<div class="h-actions">' + actions(it) + '</div></div>';
  }

  function render() {
    var shown = H.items.filter(function (it) { return it.dashboard && (showHidden || !it.hidden); });
    var hidden = H.items.filter(function (it) { return it.dashboard && it.hidden; }).length;
    var counts = { critical: 0, warning: 0, info: 0 };
    H.items.forEach(function (it) { if (it.dashboard && !it.hidden) counts[it.severity]++; });

    var c = '';
    if (counts.critical) c += '<span class="badge bg-red-lt">' + counts.critical + ' critical</span> ';
    if (counts.warning)  c += '<span class="badge bg-orange-lt">' + counts.warning + ' warning' + (counts.warning > 1 ? 's' : '') + '</span> ';
    if (counts.info)     c += '<span class="badge bg-azure-lt">' + counts.info + ' info</span>';
    $('#health-counts, #health-counts2').html(c);
    $('#health-last2').text(H.last_run ? 'Checked ' + H.last_run_text + '.' : '');

    /* the tile: the badges and one line */
    var total = counts.critical + counts.warning + counts.info;
    if (!H.last_run) {
      $('#health-counts').html('<span class="badge bg-muted-lt">not checked yet</span>');
      $('#health-top').text('the checks run every 30 minutes');
    } else if (!total) {
      $('#health-counts').html('<span class="badge bg-green-lt">all good</span>');
      $('#health-top').text('Checked ' + H.last_run_text);
    } else {
      $('#health-top').text('Checked ' + H.last_run_text);
    }

    if (!H.last_run) {
      $('#health-list').html('<div class="h-empty">The checks have not run yet. They run every 30 minutes; Check now runs them at once.</div>');
    } else if (!shown.length) {
      $('#health-list').html('<div class="h-empty">' + OK_ICON + '<span>All good: nothing needs attention.</span></div>');
    } else {
      /* a check with GROUP_AT or more problems of one severity becomes one row */
      var groups = {}, order = [];
      shown.forEach(function (it) {
        var k = it.check + '|' + it.severity + '|' + (it.hidden ? 1 : 0);
        if (!groups[k]) { groups[k] = []; order.push(k); }
        groups[k].push(it);
      });
      var blocks = [];
      order.forEach(function (k) {
        var g = groups[k];
        if (g.length < GROUP_AT) { g.forEach(function (it) { blocks.push({ sev: it.severity, html: row(it, false) }); }); return; }
        var accts = g.map(function (it) { return it.account || 'Server'; });
        var head = '<div class="h-row h-sev-' + g[0].severity + ' h-toggle">'
                 + '<div class="h-icon">' + ICON[g[0].severity] + '</div>'
                 + '<div class="h-body"><div class="h-title"><span class="h-chev">&#9656;</span> ' + esc(H.labels[g[0].check] || g[0].check) + ': ' + g.length + '</div>'
                 + '<div class="h-meta">' + esc(accts.slice(0, 6).join(', ') + (accts.length > 6 ? ' and ' + (accts.length - 6) + ' more' : '')) + '</div></div></div>';
        blocks.push({ sev: g[0].severity, html: '<div class="h-group">' + head + '<div class="h-items">'
                     + g.map(function (it) { return row(it, false); }).join('') + '</div></div>' });
      });
      blocks.sort(function (a, b) { return SEV[a.sev] - SEV[b.sev]; });
      var more = blocks.length - SHOW_ROWS;
      var html = blocks.slice(0, showAll ? blocks.length : SHOW_ROWS).map(function (b) { return b.html; }).join('');
      if (more > 0 && !showAll) html += '<div class="h-row"><span class="h-more" id="health-more">Show ' + more + ' more</span></div>';
      $('#health-list').html(html);
    }
    var foot = '';
    if (hidden) foot = ' <span class="h-hidden-link" id="health-toggle-hidden">' + (showHidden ? 'Leave out the hidden ones' : hidden + ' hidden - show them') + '</span>';
    $('#health-foot').html(foot);
  }

  function post(data, $btn) {
    if ($btn) $btn.prop('disabled', true);
    return $.ajax({ method: 'POST', url: './ajax-health/', data: data, dataType: 'json' })
      .done(function (r) {
        if (r && r.items) { H = r; render(); }
        if (r && r.error) alertBox(r.error);
      })
      .fail(function () { alertBox('The request failed. Reload the page and try again.'); })
      .always(function () { if ($btn) $btn.prop('disabled', false); });
  }
  function alertBox(msg) {
    $('#health-list').prepend('<div class="alert alert-danger m-2 py-2">' + esc(msg) + '</div>');
  }

  var running = false;
  $(document).on('click', '.health-run', function (e) {
    e.preventDefault();
    if (running) return;
    running = true;
    var $all = $('.health-run');
    $all.each(function () { $(this).data('label', $(this).text()); }).text('Checking...');
    post({ action: 'ajax-health-run' }).always(function () {
      running = false;
      $all.each(function () { $(this).text($(this).data('label')); });
    });
  });
  var $card = $('#modal-health');
  $card.on('click', '.h-toggle', function () { $(this).closest('.h-group').toggleClass('open'); });
  $card.on('click', '#health-more', function () { showAll = true; render(); });
  $card.on('click', '#health-toggle-hidden', function () { showHidden = !showHidden; render(); });
  /* hand-rolled: Bootstrap's dropdown plugin is not wired up in the panel */
  $card.on('click', '.h-dd-toggle', function (e) {
    e.preventDefault(); e.stopPropagation();
    var $m = $(this).siblings('.dropdown-menu'), open = $m.hasClass('show');
    $card.find('.dropdown-menu').removeClass('show');
    if (!open) $m.addClass('show');
  });
  $(document).on('click', function (e) { if (!$(e.target).closest('#modal-health .dropdown').length) $card.find('.dropdown-menu').removeClass('show'); });
  $card.on('click', '.h-dismiss', function (e) {
    e.preventDefault();
    post({ action: 'ajax-health-dismiss', id: $(this).data('id'), days: $(this).data('days') });
  });
  $card.on('click', '.h-restore', function () { post({ action: 'ajax-health-restore', id: $(this).data('id') }, $(this)); });
  render();
});
</script>
<script>
$(function () {
  var copyIcon  = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M9 5h-2a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-12a2 2 0 0 0 -2 -2h-2" /><path d="M13 3m0 2a2 2 0 0 1 2 -2h0a2 2 0 0 1 2 2v0a2 2 0 0 1 -2 2h0a2 2 0 0 1 -2 -2z" /></svg>';
  var checkIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#2fb344" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 12l5 5l10 -10" /></svg>';

  var $btn  = $('#server-pubkey-copy');
  var $pre  = $('#server-pubkey-text');
  var $note = $('#server-pubkey-note');

  function feedback(ok) {
    $btn.html(ok ? checkIcon : copyIcon);
    $note.text(ok ? 'Copied to clipboard' : 'Press Ctrl+C to copy the selected key')
         .css('color', ok ? '#2fb344' : '#d63939').show();
    if (ok) setTimeout(function () { $btn.html(copyIcon); $note.fadeOut(); }, 2000);
  }

  /* Select the key in the page so Ctrl+C still works if nothing else does. */
  function selectKey() {
    var r = document.createRange();
    r.selectNodeContents($pre[0]);
    var sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(r);
  }

  /*
   * execCommand first, navigator.clipboard second — deliberately the reverse of
   * the usual advice. The async clipboard API needs a focused document and a
   * secure context: over plain http, or whenever the window is not focused, it
   * silently rejects or never settles at all, and the button just does nothing.
   * execCommand is synchronous inside the click gesture and always reports back.
   * The textarea goes inside the modal, not the body: Bootstrap traps focus in
   * the open modal and would yank it straight back off a textarea placed outside.
   */
  function legacyCopy(text) {
    var host = $btn.closest('.modal-content')[0] || document.body;
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.cssText = 'position:absolute;left:-9999px;top:0;opacity:0;';
    host.appendChild(ta);
    ta.focus();
    ta.select();
    try { ta.setSelectionRange(0, text.length); } catch (e) {}
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
    host.removeChild(ta);
    return ok;
  }

  $btn.click(function () {
    var text = $pre.text();

    if (legacyCopy(text)) { feedback(true); return; }

    if (navigator.clipboard && navigator.clipboard.writeText) {
      var settled = false;
      navigator.clipboard.writeText(text).then(
        function () { if (!settled) { settled = true; feedback(true); } },
        function () { if (!settled) { settled = true; selectKey(); feedback(false); } }
      );
      /* writeText can hang indefinitely on an unfocused document — never leave
         the button looking dead. */
      setTimeout(function () {
        if (!settled) { settled = true; selectKey(); feedback(false); }
      }, 1200);
    } else {
      selectKey();
      feedback(false);
    }
  });
});
</script>
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
        $('#d-common-actions-col').removeClass('col-lg-9').addClass('col-lg-6');
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
