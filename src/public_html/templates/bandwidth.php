<?php
	/* Accounts & Domains > Bandwidth Usage: every account and its sites in a
	   month, busiest first (stats_usage(), app/functions/stats.php), and the
	   server's visitor stats switches. A site's own stats open in its account
	   panel (Metrics > Visitors and bandwidth) when the account panel is there.
	   Actions: modules/stats_settings.php, modules/stats_geoip_update.php. */
	$bw_month = isset($_GET['month']) && is_string($_GET['month']) ? substr($_GET['month'], 0, 7) : '';
	$bw = stats_usage($bw_month)['data'];
	$bw_panel = function_exists('ap_ticket') && function_exists('ap_account_map');
	$bw_map = $bw_panel ? ap_account_map() : array();
	$bw_bytes = function ($b) {
		$b = (float)$b;
		if ($b >= 1099511627776) return number_format($b / 1099511627776, 2).' TB';
		if ($b >= 1073741824) return number_format($b / 1073741824, $b >= 107374182400 ? 0 : 1).' GB';
		if ($b >= 1048576) return round($b / 1048576).' MB';
		if ($b >= 1024) return round($b / 1024).' KB';
		return (int)$b.' B';
	};
	$bw_pct = function ($a, $b, $d = 2) { return $b ? number_format($a / $b * 100, $d).'%' : '0%'; };
	$bw_label = function ($m) { return date('F Y', strtotime($m.'-01')); };
	$bw_max = 1;
	foreach ($bw['accounts'] as $a) $bw_max = max($bw_max, $a['bytes']);
	$bw_open = function ($user, $site) use ($bw_panel, $bw_map) {
		$a = $bw_map[$user] ?? null;
		if (!$bw_panel || ($a && $a['status'] !== 'active'))
			return '';
		$to = '/metrics/visitors/?site='.rawurlencode($site).'&period=30d';
		return '<form method="post" action="/" target="_blank" class="d-inline"><input type="hidden" name="action" value="account-panel-open">'
			.'<input type="hidden" name="user" value="'.h($user).'"><input type="hidden" name="to" value="'.h($to).'">'
			.'<button type="submit" class="btn btn-sm btn-ghost-primary" title="Visitors, pages, referrers and bots of '.h($site).', in the account panel">Stats</button></form>';
	};
	include('templates/header.php');
?>
        <div class="page-header d-print-none">
            <div class="row align-items-center">
              <div class="col" style="padding-left:22px;">
                <div class="page-pretitle">Accounts &amp; Domains</div>
                <h2 class="page-title">Bandwidth Usage</h2>
              </div>
            </div>
        </div>

<?php msg_render(); ?>

<style>
	#bw-page .table td, #bw-page .table th { vertical-align: middle; }
	#bw-page .bw-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
	#bw-page .bw-site td { background: rgba(98,105,118,.03); }
	#bw-page .bw-site td:first-child { padding-left: 2.2rem; color: #656d77; }
	#bw-page .bw-bar { height: 8px; border: 1px solid rgba(98,105,118,.24); background: rgba(98,105,118,.06); min-width: 90px; }
	#bw-page .bw-bar i { display: block; height: 100%; background: #206bc4; }
	#bw-page .bw-bar i.high { background: #f76707; } #bw-page .bw-bar i.crit { background: #d63939; }
	#bw-page .bw-sum { display: flex; flex-wrap: wrap; gap: 8px 28px; }
	#bw-page .bw-sum div { display: grid; }
	#bw-page .bw-sum b { font-size: 1.25rem; font-variant-numeric: tabular-nums; }
	#bw-page .bw-sum span { color: #656d77; font-size: .8rem; text-transform: uppercase; letter-spacing: .04em; font-weight: 600; }
	#bw-page .set-row { padding: 1rem 0; border-top: 1px solid rgba(98,105,118,.16); }
	#bw-page .set-row:first-child { border-top: 0; padding-top: 0; }
	#bw-page .set-label { font-weight: 600; padding-top: 2px; }
	#bw-page .set-hint { display: block; color: #656d77; font-size: 90%; margin-top: 4px; font-weight: 400; }
	#bw-page tr[hidden] { display: none; }
</style>

<div id="bw-page">

<?php if (!$bw['enabled'] && $bw['counting']): ?>
	<div class="alert alert-warning">Visitor stats are off on this server: accounts no longer see them. The logs are still counted, because hosting packages have a bandwidth limit. Turn them on under Settings below.</div>
<?php elseif (!$bw['enabled']): ?>
	<div class="alert alert-warning">Visitor stats are off on this server: nothing is counted (what was counted stays). Turn them on under Settings below.</div>
<?php endif; ?>

	<div class="card mb-3">
		<div class="card-body">
			<form method="get" action="/bandwidth/" class="row g-2 align-items-center mb-3">
				<div class="col-auto">
					<select name="month" class="form-select" onchange="this.form.submit()" aria-label="Month">
<?php foreach ($bw['months'] as $m): ?>
						<option value="<?=h($m)?>"<?=$m === $bw['month'] ? ' selected' : ''?>><?=h($bw_label($m)).($m === date('Y-m') ? ' (so far)' : '')?></option>
<?php endforeach; ?>
					</select>
				</div>
				<div class="col-auto">
					<input type="search" class="form-control" id="bw-q" placeholder="Find an account or a site" autocomplete="off">
				</div>
				<div class="col text-muted text-end small">
					<?=$bw['collected'] ? 'Counted at '.h(date('Y-m-d H:i', $bw['collected'])).' from the web server logs, every hour' : 'Not counted yet: the first count runs within the hour'?>
				</div>
			</form>
			<div class="bw-sum">
				<div><b><?=h($bw_bytes($bw['totals']['bytes']))?></b><span>Bandwidth</span></div>
				<div><b><?=number_format($bw['totals']['v'])?></b><span>Visitors</span></div>
				<div><b><?=number_format($bw['totals']['pages'])?></b><span>Page views</span></div>
				<div><b><?=number_format($bw['totals']['req'])?></b><span>Requests</span></div>
				<div><b><?=h($bw_pct($bw['totals']['bots'], $bw['totals']['req'], 0))?></b><span>Bots</span></div>
				<div><b><?=number_format($bw['totals']['e5'])?></b><span>Server errors</span></div>
			</div>
		</div>
		<div class="table-responsive">
			<table class="table card-table table-vcenter" id="bw-table">
				<thead>
					<tr>
						<th>Account / site</th>
						<th class="bw-num">Bandwidth</th>
						<th style="width:18%"></th>
						<th class="bw-num">Visitors</th>
						<th class="bw-num">Page views</th>
						<th class="bw-num">Requests</th>
						<th class="bw-num">Server errors</th>
						<th></th>
					</tr>
				</thead>
				<tbody>
<?php foreach ($bw['accounts'] as $a):
		$lim = $a['limit_bytes'];
		$share = $lim ? min(100, $a['bytes'] / $lim * 100) : $a['bytes'] / $bw_max * 100;
		$cls = $lim ? ($share >= 90 ? 'crit' : ($share >= 75 ? 'high' : '')) : '';
		$one = count($a['sites']) <= 1;
		$keys = strtolower($a['user'].' '.implode(' ', array_column($a['sites'], 'site'))); ?>
					<tr class="bw-acct" data-k="<?=h($keys)?>">
						<td><a href="/account/<?=h($a['user'])?>"><b><?=h($a['user'])?></b></a> <span class="text-muted ms-1"><?=h($a['domain'])?></span>
							<?php if ($a['package'] !== ''): ?><span class="badge bg-azure-lt border ms-1"><?=h($a['package'])?></span><?php endif; ?>
							<?php if (!$one): ?><span class="text-muted small ms-1"><?=count($a['sites'])?> sites</span><?php endif; ?></td>
						<td class="bw-num"><b><?=h($bw_bytes($a['bytes']))?></b><?php if ($lim): ?><span class="text-muted small"> of <?=h($bw_bytes($lim))?></span><?php endif; ?></td>
						<td><div class="bw-bar" title="<?=$lim ? h(number_format($share, 1)).'% of the package\'s monthly bandwidth' : 'Compared with the busiest account'?>"><i class="<?=$cls?>" style="width:<?=number_format(max($a['bytes'] ? 0.5 : 0, $share), 1, '.', '')?>%"></i></div></td>
						<td class="bw-num"><?=number_format($a['v'])?></td>
						<td class="bw-num"><?=number_format($a['pages'])?></td>
						<td class="bw-num"><?=number_format($a['req'])?></td>
						<td class="bw-num<?=$a['req'] && $a['e5'] / $a['req'] > 0.01 ? ' text-red' : ''?>"><?=number_format($a['e5'])?><?php if ($a['e5']): ?><span class="text-muted small"> · <?=h($bw_pct($a['e5'], $a['req']))?></span><?php endif; ?></td>
						<td class="text-end"><?=$one && $a['sites'] ? $bw_open($a['user'], $a['sites'][0]['site']) : ''?></td>
					</tr>
<?php	if (!$one) foreach ($a['sites'] as $st): ?>
					<tr class="bw-site" data-k="<?=h($keys)?>">
						<td><?=h($st['site'])?></td>
						<td class="bw-num"><?=h($bw_bytes($st['bytes']))?></td>
						<td></td>
						<td class="bw-num"><?=number_format($st['v'])?></td>
						<td class="bw-num"><?=number_format($st['pages'])?></td>
						<td class="bw-num"><?=number_format($st['req'])?></td>
						<td class="bw-num"><?=number_format($st['e5'])?></td>
						<td class="text-end"><?=$bw_open($a['user'], $st['site'])?></td>
					</tr>
<?php	endforeach;
	endforeach; ?>
<?php if (!$bw['accounts']): ?>
					<tr><td colspan="8" class="text-muted">There are no hosting accounts yet.</td></tr>
<?php endif; ?>
				</tbody>
			</table>
		</div>
		<div class="card-footer text-muted small">
			Bandwidth = bytes the web server sent, bots included: the number the hosting packages' monthly limits use. Visitors are counted per day
			(the same address and browser once a day; no addresses are stored). Mail and FTP are not counted here.
			<?php if (!$bw_panel): ?>A site's visitors, pages and referrers are in its account panel (the Reqad account panel add-on), or <code>reqad stats show &lt;domain&gt;</code>.<?php endif; ?>
		</div>
	</div>

	<div class="card">
		<div class="card-header"><h3 class="card-title">Settings</h3></div>
		<div class="card-body">
			<div class="row set-row">
				<div class="col-md-3 set-label">Visitor stats
					<span class="set-hint">Count visitors, pages and bandwidth of every site from the web server logs, every hour.</span>
				</div>
				<div class="col-md-9">
					<form method="post" action="/" class="mb-1">
						<input type="hidden" name="action" value="stats-settings">
						<input type="hidden" name="enabled" value="<?=$bw['enabled'] ? '0' : '1'?>">
						<label class="form-check form-switch d-inline-flex align-items-center mb-0" style="cursor:pointer">
							<input class="form-check-input state-toggle" type="checkbox" style="margin-right:10px"<?=$bw['enabled'] ? ' checked' : ''?>>
							<span class="form-check-label">Count visits</span>
							<span class="badge border ms-2 <?=$bw['enabled'] ? 'bg-green-lt' : 'bg-red-lt'?>"><?=$bw['enabled'] ? 'Active' : 'Disabled'?></span>
						</label>
					</form>
					<span class="set-hint">Off: nothing is counted and accounts no longer see Metrics &gt; Visitors and bandwidth; what was counted stays.
						While hosting packages have a bandwidth limit, the logs are still counted for it. Kept for 25 months. Also <code>reqad stats on|off</code>.</span>
				</div>
			</div>
			<div class="row set-row">
				<div class="col-md-3 set-label">Countries
					<span class="set-hint">Which country a visitor came from, looked up before the address is dropped.</span>
				</div>
				<div class="col-md-9">
					<div class="mb-2">
<?php if ($bw['geo']): ?>
						Country database: <b><?=h($bw['geo']['source'])?></b> from <?=h($bw['geo']['date'])?>
						<?php if ($bw['geo']['source'] !== 'DB-IP Lite' || $bw['geo']['date'] < date('Y-m-d', strtotime('-70 days'))): ?><span class="badge bg-yellow-lt border ms-1">out of date</span><?php endif; ?>
<?php else: ?>
						<span class="text-red">No country database: countries are not counted.</span>
<?php endif; ?>
					</div>
					<form method="post" action="/" class="mb-2">
						<input type="hidden" name="action" value="stats-settings">
						<input type="hidden" name="geoip_update" value="<?=$bw['geo_update'] ? '0' : '1'?>">
						<label class="form-check form-switch d-inline-flex align-items-center mb-0" style="cursor:pointer">
							<input class="form-check-input state-toggle" type="checkbox" style="margin-right:10px"<?=$bw['geo_update'] ? ' checked' : ''?>>
							<span class="form-check-label">Update it every month from DB-IP</span>
							<span class="badge border ms-2 <?=$bw['geo_update'] ? 'bg-green-lt' : 'bg-red-lt'?>"><?=$bw['geo_update'] ? 'Active' : 'Disabled'?></span>
						</label>
					</form>
					<form method="post" action="/" class="d-inline">
						<input type="hidden" name="action" value="stats-geoip-update">
						<button type="submit" class="btn btn-sm">Update now</button>
					</form>
					<span class="set-hint">The free <a href="https://db-ip.com/db/lite.php" target="_blank" rel="noopener">DB-IP Lite</a> country database
						(<a href="https://db-ip.com" target="_blank" rel="noopener">IP Geolocation by DB-IP</a>, CC BY 4.0), about 4 MB, downloaded once a month
						from download.db-ip.com. Without it, an older GeoLite2 file in /usr/share/GeoIP is used when the server has one.</span>
				</div>
			</div>
		</div>
	</div>
</div>

<script>
(function () {
	var q = document.getElementById('bw-q');
	if (q) q.addEventListener('input', function () {
		var v = q.value.trim().toLowerCase();
		document.querySelectorAll('#bw-table tbody tr[data-k]').forEach(function (tr) { tr.hidden = v !== '' && tr.getAttribute('data-k').indexOf(v) < 0; });
	});
	document.querySelectorAll('#bw-page .state-toggle').forEach(function (sw) {
		sw.addEventListener('change', function () { var f = sw.form; if (f.requestSubmit) f.requestSubmit(); else f.submit(); });
	});
})();
</script>

<?php include('templates/footer.php'); ?>
