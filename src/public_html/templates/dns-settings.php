<?php
	/* Settings > DNS Settings: one page, a tab each for the DNS provider
	   (templates/dns-settings-provider.php), the DNS cluster
	   (dns-settings-cluster.php) and the DNS API keys (dns-settings-keys.php).
	   Cluster and API keys exist only with dnscluster=1 in server-software.ini
	   (dns_cluster_available()); without it the page is the provider form alone.
	   URLs: /dns-settings/, /dns-settings/cluster/, /dns-settings/keys/ (the old
	   /dns-cluster/ and /dns-keys/ lead here). A page drawn after an action
	   (a new API key, shown once) sets $dns_tab itself. Scripts: dist/js/dns-settings.js. */
	$dns_tabs = array('' => 'Provider');
	if (dns_cluster_available())
		$dns_tabs += array('cluster' => 'Cluster', 'keys' => 'API keys');
	$dns_tab = isset($dns_tab) ? (string)$dns_tab : (string)($reqs[2] ?? '');
	if (!isset($dns_tabs[$dns_tab])) {
		header('Location: '.$_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/dns-settings/');
		exit;
	}
	$dns_dot = count($dns_tabs) > 1 ? dns_cluster_health_dot() : '';
	$dns_js = '/dist/js/dns-settings.js?v='.@filemtime(__DIR__.'/../dist/js/dns-settings.js');
	include('templates/header.php');
?>
        <div class="page-header d-print-none">
            <div class="row align-items-center">
              <div class="col" style="padding-left:22px;">
                <div class="page-pretitle">Settings</div>
                <h2 class="page-title">DNS Settings</h2>
              </div>
              <div class="col-auto ms-auto d-print-none">
                <div class="btn-list">
                  <a href="/dns/" class="btn btn-white">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 12a9 9 0 1 0 18 0a9 9 0 0 0 -18 0"/><path d="M3.6 9h16.8"/><path d="M3.6 15h16.8"/><path d="M11.5 3a17 17 0 0 0 0 18"/><path d="M12.5 3a17 17 0 0 1 0 18"/></svg>
                    DNS Zones
                  </a>
                </div>
              </div>
            </div>
        </div>

<?php msg_render(); ?>

<style>
	/* the tabs of the account page (templates/account.php), on the page's own
	   background: no grey header box, the shadow only under the open tab's body */
	#dns-main-card { border: none; box-shadow: none; background: transparent; }
	#dns-main-card > .card-header { padding-bottom: 0; background: transparent; border-bottom-color: #dee2e6; }
	#dns-tabs { border-bottom: 0; gap: 30px; }
	#dns-tabs .nav-link {
		display: flex; align-items: center; gap: 8px;
		border: 1px solid transparent;
		padding: 10px 20px !important;
		color: #6c757d;
		font-weight: 500;
		line-height: 20pt !important;
		margin-bottom: -1px;
		margin-left: -16px !important;
		transition: background-color .15s ease-in-out, color .15s ease-in-out;   /* the border goes at once: no ghost of it */
	}
	#dns-tabs .nav-link:hover { border-color: #dee2e6 #dee2e6 transparent; background: #475db41a; color: #354052; }
	#dns-tabs .nav-link.active { background: #fff; border-color: #dee2e6 #dee2e6 #fff; color: #354052; font-weight: bold; }
	.dns-tab-pane { border: 1px solid; border-color: transparent #dee2e6 #dee2e6 #dee2e6; background: #fff; box-shadow: rgba(35,46,60,.04) 0 2px 4px 0; }
	.dns-dot { width: 9px; height: 9px; border-radius: 50%; display: inline-block; flex: none; }
	/* rows of a setting: label left, value right (as on the other settings pages) */
	.dns-page .set-row { padding: 1.25rem 0; border-top: 1px solid rgba(98,105,118,.16); }
	.dns-page .set-row:first-child { border-top: 0; padding-top: 0; }
	.dns-page .set-row:last-child { padding-bottom: 0; }
	.dns-page .set-label { font-weight: 600; padding-top: 2px; }
	.dns-page .set-hint { display: block; color: #656d77; font-size: 90%; margin-top: 4px; font-weight: 400; }
	.dns-page .table td, .dns-page .table th { vertical-align: middle; }
</style>

<?php if (count($dns_tabs) > 1): ?>
<div class="col-12 dns-page">
	<div class="card mt-3" id="dns-main-card">
		<div class="card-header">
			<ul class="nav nav-tabs card-header-tabs" id="dns-tabs">
<?php foreach ($dns_tabs as $t => $label): ?>
				<li class="nav-item">
					<a class="nav-link<?=$t === $dns_tab ? ' active' : ''?>" href="/dns-settings/<?=$t !== '' ? $t.'/' : ''?>"<?=$t === $dns_tab ? ' aria-current="page"' : ''?>><?=h($label)?>
<?php if ($t === 'cluster' && $dns_dot !== ''): ?>
						<span class="dns-dot" style="background:<?=$dns_dot === 'ok' ? '#2fb344' : '#d63939'?>" title="<?=$dns_dot === 'ok' ? 'Every secondary is in step' : 'Something needs a look'?>"></span>
<?php endif; ?>
					</a>
				</li>
<?php endforeach; ?>
			</ul>
		</div>
		<div class="dns-tab-pane">
			<div class="card-body">
<?php include('templates/dns-settings-'.($dns_tab === '' ? 'provider' : $dns_tab).'.php'); ?>
			</div>
		</div>
	</div>
</div>
<?php else: ?>
<div class="dns-page" style="padding-left:14px;">
<?php include('templates/dns-settings-provider.php'); ?>
</div>
<?php endif; ?>

<?php
	include('templates/sudo-modal.php');
	include('templates/footer.php');
?>
<script src="<?=h($dns_js)?>"></script>
</body>
</html>
