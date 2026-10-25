<?php
	/* Settings > DNS Settings, tab Cluster (included by templates/dns-settings.php):
	   this server's PowerDNS is the primary, the secondaries copy every zone
	   (app/functions/dns_cluster.php). Health = what the last `reqad dns check`
	   kept (app/functions/dns_cluster_health.php, cron every 5 minutes); Check
	   now runs it again (ajax-dns op cluster-check). Actions: modules/dns_cluster_actions.php. */
	$dc_why  = dns_cluster_unavailable();
	$dc_sec  = dns_cluster_secondaries();
	$dc_conf = $dc_why === '' ? dns_cluster_conf() : array();
	$dc_cat  = dns_cluster_catalog();
	$dc_ns   = $dc_why === '' ? dns_expected_nameservers() : array();
	$dc_cmd  = $dc_why === '' && !isset($dc_conf['error']) ? dns_cluster_setup_command() : '';
	$dc_old  = $dc_why === '' && !isset($dc_conf['error']) && version_compare((string)($dc_conf['version'] ?? '0'), '4.7', '<');
	$dc_kept = array_unique(array_merge((array)($dc_conf['kept']['also-notify'] ?? array()), (array)($dc_conf['kept']['allow-axfr-ips'] ?? array())));
	$dc_v    = $dc_why === '' && $dc_sec ? dns_cluster_health_view() : null;
	$dc_ago  = function ($ts) { return $ts ? dns_cluster_health_ago(time() - (int)$ts).' ago' : 'never'; };
	$dc_when = function ($ts) { return date(date('Y-m-d', $ts) === date('Y-m-d') ? '\t\o\d\a\y H:i' : (date('Y-m-d', $ts) === date('Y-m-d', time() - 86400) ? '\y\e\s\t\e\r\d\a\y H:i' : 'M j H:i'), $ts); };
	/* a check's icon: ok, warn, bad, unknown */
	$dc_icon = function ($s) {
		$c = array('ok' => array('#e7f6ea', '#1d7a33', 'M5 12l5 5l10 -10'), 'warn' => array('#fff4dc', '#8a5300', 'M12 8v4M12 16h.01'),
		           'bad' => array('#fdeaea', '#b42323', 'M18 6l-12 12M6 6l12 12'), 'unknown' => array('#eef0f3', '#5c6470', 'M12 16h.01M10 9a2 2 0 1 1 3 1.7c-.6.4-1 .8-1 1.3'));
		$x = $c[$s] ?? $c['unknown'];
		return '<span class="dc-ic" style="background:'.$x[0].'"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="'.$x[1].'" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="'.$x[2].'"/></svg></span>';
	};
	$dc_state = array('o' => array('bg-green-lt', 'in step'), 'b' => array('bg-yellow-lt', 'behind'), 'd' => array('bg-red-lt', 'not answering'), '' => array('bg-secondary-lt', 'not checked yet'));
	$dc_seg   = array('o' => '#2fb344', 'b' => '#e09b18', 'd' => '#d63939', '' => '#dfe2e6');
?>
<style>
	#dns-cluster .dc-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); }
	#dns-cluster .dc-tile { padding: 14px 20px 16px; border-left: 1px solid rgba(98,105,118,.16); }
	#dns-cluster .dc-tile:first-child { border-left: 0; }
	#dns-cluster .dc-tk { font-size: .75rem; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: #656d77; margin-bottom: 4px; }
	#dns-cluster .dc-tv { font-size: 1.25rem; font-weight: 700; color: #232e3c; }
	#dns-cluster .dc-ts { color: #656d77; margin-top: 2px; }
	#dns-cluster .dc-checks { display: grid; grid-template-columns: repeat(auto-fit, minmax(290px, 1fr)); gap: 10px; }
	#dns-cluster .dc-check { display: flex; gap: 10px; align-items: flex-start; padding: 12px 14px; border: 1px solid rgba(98,105,118,.16); border-radius: 4px; }
	#dns-cluster .dc-ic { flex: none; width: 20px; height: 20px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; margin-top: 1px; }
	#dns-cluster .dc-bar { display: flex; gap: 2px; }
	#dns-cluster .dc-seg { flex: 1 1 0; height: 28px; border-radius: 2px; min-width: 3px; }
	#dns-cluster .dc-key { display: inline-flex; align-items: center; gap: 5px; }
	#dns-cluster .dc-sec + .dc-sec { border-top: 1px solid rgba(98,105,118,.16); }
	#dns-cluster .dc-grid2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 16px; }
	#dns-cluster .dc-grid2 > .card { margin-bottom: 0; }
	#dns-cluster .dc-kv td:first-child { color: #656d77; white-space: nowrap; width: 1%; }
	#dns-cluster code + code { margin-left: 4px; }
	@media (max-width: 460px) { #dns-cluster .dc-grid2 { grid-template-columns: 1fr; } #dns-cluster .dc-checks { grid-template-columns: 1fr; } }
	.dc-cmd { font-family: monospace; font-size: 12px; white-space: pre; height: 220px; }
</style>

<div id="dns-cluster">

<?php if ($dc_why !== ''): ?>
	<div class="alert alert-warning mb-0"><?=h($dc_why)?></div>
<?php elseif (isset($dc_conf['error'])): ?>
	<div class="alert alert-danger mb-0">The PowerDNS settings could not be read: <?=h($dc_conf['error'])?></div>
<?php else: ?>
<?php if ($dc_old): ?>
	<div class="alert alert-warning">PowerDNS <?=h($dc_conf['version'])?> is too old for the cluster: it needs 4.7 or newer (catalog zones).</div>
<?php endif; ?>

<?php if (!$dc_sec): ?>
	<div class="card mb-3">
		<div class="card-header">
			<h3 class="card-title">Secondary servers</h3>
<?php if (!$dc_old): ?>
			<div class="card-actions"><button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#dc-m-add">
				<svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
				Add a secondary</button></div>
<?php endif; ?>
		</div>
		<div class="card-body">
			<p>This server's PowerDNS is the <strong>primary</strong>: zones are made and changed only here (DNS Zones, the account panel, the DNS API).
				Each <strong>secondary</strong> is another machine running PowerDNS that copies every zone: when a zone changes, this server tells the
				secondaries within about 15 seconds and they fetch it; new zones appear there and removed ones go away by themselves.
				A secondary needs nothing else of Reqad, only a setup command, run once.</p>
			<p class="text-muted mb-0">None yet. Add one with <strong>Add a secondary</strong>: its name (the name server it answers as, e.g. ns2.example.com)
				and its address, then run the setup command on it.</p>
		</div>
	</div>
<?php else: $v = $dc_v; $p = $v['primary']; ?>

	<!-- health at a glance -->
	<div class="card mb-3">
		<div class="card-header">
			<h3 class="card-title">Cluster health <span class="text-muted fw-normal ms-2"><?=$v['checked'] ? 'checked '.h($dc_ago($v['checked'])).' · every 5 minutes' : 'not checked yet'?></span></h3>
			<div class="card-actions"><button type="button" class="btn btn-white" id="dc-check-now"><span>Check now</span></button></div>
		</div>
		<div class="dc-tiles">
			<div class="dc-tile">
				<div class="dc-tk">Cluster</div>
<?php if ($v['overall'] === 'ok'): ?>
				<div class="dc-tv d-flex align-items-center" style="gap:8px;color:#1d7a33"><span class="dns-dot" style="width:12px;height:12px;background:#2fb344"></span>Healthy</div>
				<div class="dc-ts"><?=$v['last_problem'] ? 'last problem '.h($dc_ago($v['last_problem'])) : 'no problem in the last 7 days'?></div>
<?php elseif ($v['overall'] === 'bad'): ?>
				<div class="dc-tv d-flex align-items-center" style="gap:8px;color:#b42323"><span class="dns-dot" style="width:12px;height:12px;background:#d63939"></span>Needs a look</div>
				<div class="dc-ts"><?=h($v['problem'])?></div>
<?php else: ?>
				<div class="dc-tv text-muted">Not checked yet</div>
				<div class="dc-ts">the check runs every 5 minutes</div>
<?php endif; ?>
			</div>
			<div class="dc-tile">
				<div class="dc-tk">Primary (this server)</div>
				<div class="dc-tv"<?=$v['checked'] && !$p['running'] ? ' style="color:#b42323"' : ''?>><?=!$v['checked'] ? '-' : ($p['running'] ? 'Running' : 'Not answering')?></div>
				<div class="dc-ts">PowerDNS <?=h($p['version'] ?: ($dc_conf['version'] ?? '?'))?><?=$p['uptime'] ? ' · up '.h(dns_cluster_health_ago($p['uptime'])) : ''?></div>
			</div>
			<div class="dc-tile">
				<div class="dc-tk">Secondaries</div>
				<div class="dc-tv"><?=(int)$v['answering']?> of <?=count($v['secondaries'])?></div>
				<div class="dc-ts">answering</div>
			</div>
			<div class="dc-tile">
				<div class="dc-tk">Zones</div>
				<div class="dc-tv"><?=$v['checked'] ? (int)$v['in_step_min'].' of '.(int)$v['zones'] : '-'?></div>
				<div class="dc-ts"><?=count($v['secondaries']) === 1 ? 'in step on the secondary' : 'in step on every secondary'?></div>
			</div>
			<div class="dc-tile">
				<div class="dc-tk">Copy delay</div>
				<div class="dc-tv"><?=$v['delay'] !== null ? (int)$v['delay'].' s' : '-'?></div>
				<div class="dc-ts"><?=$v['delay'] !== null ? 'a test change, '.h($dc_ago($v['checked'])) : ($v['probe_error'] !== '' ? h($v['probe_error']) : 'a test change, each check')?></div>
			</div>
		</div>
	</div>

<?php if ($v['out']): ?>
	<div class="alert alert-warning d-flex flex-wrap align-items-center" style="gap:10px">
		<span><?=count($v['out']) === 1 ? 'A zone is' : count($v['out']).' zones are'?> not copied to the secondaries yet: <?=h(implode(', ', array_slice($v['out'], 0, 5)))?><?=count($v['out']) > 5 ? ' &hellip;' : ''?></span>
		<form method="post" action="/" class="ms-auto"><input type="hidden" name="action" value="dns-cluster-sync"><button type="submit" class="btn btn-sm">Add them</button></form>
	</div>
<?php endif; ?>

	<!-- secondaries -->
	<div class="card mb-3">
		<div class="card-header">
			<h3 class="card-title">Secondary servers</h3>
<?php if (!$dc_old): ?>
			<div class="card-actions"><button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#dc-m-add">
				<svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
				Add a secondary</button></div>
<?php endif; ?>
		</div>
<?php foreach ($v['secondaries'] as $s): $l = $s['last'] ?: array(); $st = $dc_state[$s['state']]; ?>
		<div class="card-body dc-sec">
			<div class="d-flex flex-wrap align-items-center mb-3" style="gap:8px 14px">
				<span style="font-family:monospace;font-weight:600;font-size:1rem"><?=h($s['name'])?></span>
				<code><?=h($s['addr'])?></code>
				<span class="badge border <?=$st[0]?>"><?=h($st[1])?></span>
<?php if ($s['state'] !== 'o' && !empty($l['since'])): ?>
				<span class="text-muted">since <?=h($dc_when((int)$l['since']))?></span>
<?php elseif ($s['added']): ?>
				<span class="text-muted">added <?=h(date('Y-m-d', $s['added']))?></span>
<?php endif; ?>
				<span class="ms-auto d-flex" style="gap:8px">
					<button type="button" class="btn" data-bs-toggle="modal" data-bs-target="#dc-m-setup">Setup command</button>
					<button type="button" class="btn" data-bs-toggle="modal" data-bs-target="#dc-m-remove" data-name="<?=h($s['name'])?>">Remove</button>
				</span>
			</div>
<?php if (!$l): ?>
			<p class="text-muted mb-0">Not checked yet: the check runs every 5 minutes, or press Check now.</p>
<?php else:
		/* answers */
		$udp = $l['udp_ms'] ?? null; $tcp = $l['tcp_ms'] ?? null;
		$c_ans = $udp !== null && $tcp !== null ? 'ok' : ($udp !== null || $tcp !== null ? 'warn' : 'bad');
		$t_ans = $udp === null && $tcp === null ? 'no reply on port 53 - set it up, or open the port'
			: 'UDP '.($udp !== null ? $udp.' ms' : 'no reply').' · TCP '.($tcp !== null ? $tcp.' ms' : 'no reply');
		/* zones */
		$late = array_merge((array)($l['behind'] ?? array()), (array)($l['missing'] ?? array()));
		$c_z = $s['state'] === 'd' ? 'unknown' : ((int)$l['in_step'] === (int)$l['total'] ? 'ok' : 'warn');
		$t_z = $s['state'] === 'd' ? 'not answering' : (int)$l['in_step'].' of '.(int)$l['total'].' have the same serial as here'
			.($late ? ' - behind: '.implode(', ', array_slice($late, 0, 5)).(count($late) > 5 ? ' ...' : '') : '');
		/* the test change */
		$pr = $l['probe'] ?? 'none';
		$c_p = $pr === 'ok' ? 'ok' : ($pr === 'old' ? 'bad' : 'unknown');
		$t_p = $pr === 'ok' ? 'a test change arrived after '.(int)$l['delay'].' s'.($s['slowest'] !== null && $s['slowest'] > (int)$l['delay'] ? ' (slowest this week '.(int)$s['slowest'].' s)' : '')
			: ($pr === 'old' ? 'the test change did not arrive in time - check that it can reach this server on port 53 (TCP)'
			: ($v['probe_error'] !== '' ? $v['probe_error'] : 'the test zone is not there yet: it arrives with the next copy of the catalog'));
		/* its name */
		$c_n = ($l['ns'] ?? '') === 'ok' ? 'ok' : 'warn';
		$t_n = ($l['ns'] ?? '') === 'ok' ? '<code>'.h($s['name']).'</code> points to this address'
			: (($l['ns'] ?? '') === 'other' ? '<code>'.h($s['name']).'</code> points to '.h(implode(', ', (array)($l['ns_ips'] ?? array()))).', not to this address'
			: '<code>'.h($s['name']).'</code> does not resolve yet - give it an A record');
		/* transfers and recursion */
		$ax = $l['axfr'] ?? 'unknown';
		$c_x = $ax === 'refused' ? 'ok' : ($ax === 'allowed' ? 'bad' : 'unknown');
		$t_x = $ax === 'refused' ? 'nobody else can download the zones from it' : ($ax === 'allowed' ? 'anyone can download every zone from it - run the setup command on it again' : 'could not be tried');
		$op = $l['open'] ?? 'unknown';
		$c_o = $op === 'no' ? 'ok' : ($op === 'yes' ? 'bad' : 'unknown');
		$t_o = $op === 'no' ? 'refuses to look up other domains for strangers' : ($op === 'yes' ? 'it looks up any domain for anyone, which can be abused for attacks - run the setup command on it again' : 'could not be tried');
?>
			<div class="dc-checks mb-4">
				<div class="dc-check"><?=$dc_icon($c_ans)?><div><div class="fw-bold">Answers on port 53</div><div class="text-muted"><?=h($t_ans)?></div></div></div>
				<div class="dc-check"><?=$dc_icon($c_z)?><div><div class="fw-bold">Zones in step</div><div class="text-muted"><?=h($t_z)?></div></div></div>
				<div class="dc-check"><?=$dc_icon($c_p)?><div><div class="fw-bold">Gets changes</div><div class="text-muted"><?=h($t_p)?></div></div></div>
				<div class="dc-check"><?=$dc_icon($c_n)?><div><div class="fw-bold">Name server name</div><div class="text-muted"><?=$t_n?></div></div></div>
				<div class="dc-check"><?=$dc_icon($c_x)?><div><div class="fw-bold">Zone copies refused to others</div><div class="text-muted"><?=h($t_x)?></div></div></div>
				<div class="dc-check"><?=$dc_icon($c_o)?><div><div class="fw-bold">Not an open resolver</div><div class="text-muted"><?=h($t_o)?></div></div></div>
			</div>
<?php endif; ?>

			<div class="d-flex flex-wrap align-items-baseline mb-2" style="gap:6px 14px">
				<span class="fw-bold">Last 24 hours</span>
<?php if ($s['pct'] !== null): ?>
				<span class="text-muted">in step <?=h(rtrim(rtrim(number_format($s['pct'], 1), '0'), '.'))?>% of the checks this week</span>
<?php endif; ?>
				<span class="ms-auto d-flex flex-wrap text-muted" style="gap:12px">
					<span class="dc-key"><span class="dns-dot" style="background:#2fb344"></span>in step</span>
					<span class="dc-key"><span class="dns-dot" style="background:#e09b18"></span>behind</span>
					<span class="dc-key"><span class="dns-dot" style="background:#d63939"></span>not answering</span>
					<span class="dc-key"><span class="dns-dot" style="background:#dfe2e6"></span>no check</span>
				</span>
			</div>
			<div class="dc-bar" role="img" aria-label="The checks of the last 24 hours, half an hour each">
<?php $b0 = time() - 86400; foreach ($s['bars'] as $i => $b): ?>
				<span class="dc-seg" style="background:<?=$dc_seg[$b]?>" title="<?=h(date('H:i', $b0 + $i * 1800).'-'.date('H:i', $b0 + ($i + 1) * 1800).': '.$dc_state[$b][1])?>"></span>
<?php endforeach; ?>
			</div>
			<div class="d-flex justify-content-between text-muted mt-1"><span><?=h(date('H:i', $b0))?> yesterday</span><span>now</span></div>
		</div>
<?php endforeach; ?>
	</div>

	<div class="dc-grid2 mb-3">

		<!-- name servers -->
		<div class="card">
			<div class="card-header"><h3 class="card-title">Name servers <span class="text-muted fw-normal ms-2">set on the Provider tab</span></h3></div>
<?php if (!$dc_ns): ?>
			<div class="card-body"><div class="alert alert-warning mb-0">Not set yet: set them on the <a href="/dns-settings/">Provider</a> tab, e.g. ns1 for this server and ns2 for the secondary.</div></div>
<?php else: ?>
			<div class="table-responsive">
				<table class="table card-table table-vcenter">
					<thead><tr><th>Name</th><th>Points to</th><th>Is</th></tr></thead>
					<tbody>
<?php foreach ($v['ns'] ?: array_map(function ($n) { return array('name' => $n, 'ips' => null, 'who' => ''); }, $dc_ns) as $n): ?>
					<tr>
						<td><code><?=h($n['name'])?></code></td>
						<td><?=$n['ips'] === null ? '<span class="text-muted">not checked yet</span>' : ($n['ips'] ? h(implode(', ', $n['ips'])) : '<span class="text-danger">does not resolve yet</span>')?></td>
						<td><?php if ($n['ips'] === null): ?>
						<?php elseif ($n['who'] === 'this server'): ?><span class="badge border bg-green-lt">this server</span>
						<?php elseif ($n['who'] !== ''): ?><span class="badge border bg-green-lt">secondary <?=h($n['who'])?></span>
						<?php elseif ($n['ips']): ?><span class="badge border bg-yellow-lt">neither this server nor a secondary</span>
						<?php else: ?><span class="text-muted">give it an A record</span><?php endif; ?></td>
					</tr>
<?php endforeach; ?>
					</tbody>
				</table>
			</div>
<?php endif; ?>
		</div>

		<!-- where the domains point -->
<?php $dl = $v['delegation']; $dl_label = array('other' => array('bg-yellow-lt', 'other servers'), 'partial' => array('bg-yellow-lt', 'not all'), 'extra' => array('bg-yellow-lt', 'also others'),
	'none' => array('bg-azure-lt', 'not registered'), 'unknown' => array('bg-secondary-lt', 'no answer')); ?>
		<div class="card">
			<div class="card-header"><h3 class="card-title">Where the domains point <span class="text-muted fw-normal ms-2">at their registry<?=$dl['checked'] ? ' · checked '.h($dc_ago($dl['checked'])) : ''?> · hourly</span></h3></div>
<?php if (!$dl['checked']): ?>
			<div class="card-body text-muted">Not looked up yet: the first check does it.</div>
<?php else: ?>
			<div class="card-body pb-2">
				<span style="font-size:1.25rem;font-weight:700"><?=(int)$dl['ours']?> of <?=(int)$dl['total']?></span>
				<span class="text-muted ms-1">domains use <?=$v['expected'] ? h(implode(' + ', $v['expected'])) : 'this server\'s name servers'?> at their registry</span>
			</div>
<?php if ($dl['rows']): ?>
			<div class="table-responsive" style="max-height:340px;overflow-y:auto">
				<table class="table card-table table-vcenter">
					<thead><tr><th>Domain</th><th>Its registry says</th></tr></thead>
					<tbody>
<?php foreach ($dl['rows'] as $r): $lb = $dl_label[$r['kind']] ?? $dl_label['unknown']; ?>
					<tr>
						<td><a href="/dns/<?=h(rawurlencode($r['zone']))?>/"><code><?=h($r['zone'])?></code></a></td>
						<td><span class="badge border <?=$lb[0]?>"><?=h($lb[1])?></span>
							<?php if ($r['names']): ?><span class="text-muted ms-1"><?=h(implode(', ', $r['names']))?></span><?php endif; ?>
							<?php if ($r['kind'] === 'partial' && $r['missing']): ?><span class="text-muted"> - add <?=h(implode(', ', $r['missing']))?> at the registrar</span><?php endif; ?></td>
					</tr>
<?php endforeach; ?>
					</tbody>
				</table>
			</div>
<?php endif; ?>
<?php endif; ?>
		</div>

	</div>

	<div class="dc-grid2 mb-1">

		<!-- this server -->
		<div class="card">
			<div class="card-header"><h3 class="card-title">This server (primary)</h3></div>
			<div class="table-responsive">
				<table class="table card-table table-vcenter dc-kv">
					<tbody>
					<tr><td>PowerDNS</td><td><?=h($dc_conf['version'] ?? '?')?> · <?=!empty($dc_conf['active']) ? 'running' : '<span class="text-danger">not running</span>'?><?=$p['uptime'] ? ' · up '.h(dns_cluster_health_ago($p['uptime'])) : ''?></td></tr>
					<tr><td>Secondaries reach it at</td><td><code><?=h(dns_cluster_primary_addr())?></code> <span class="text-muted">TCP and UDP must be open to them</span></td></tr>
					<tr><td>Answers, last hour</td><td><?=$p['queries_hour'] !== null ? number_format($p['queries_hour']).' queries · '.number_format((int)$p['servfail_hour']).' failed (SERVFAIL)' : '<span class="text-muted">known after an hour of checks</span>'?></td></tr>
					<tr><td>Catalog zone</td><td><code><?=h($dc_cat)?></code> <span class="text-muted">the list of zones the secondaries copy</span></td></tr>
					<tr><td>Changes sent</td><td>within 15 s (<code>xfr-cycle-interval</code>)</td></tr>
<?php if ($dc_kept): ?>
					<tr><td>Also sent to</td><td><code><?=implode('</code> <code>', array_map('h', $dc_kept))?></code> <span class="text-muted">were in pdns.conf before the cluster</span>
						<form method="post" action="/" class="d-inline ms-2"><input type="hidden" name="action" value="dns-cluster-drop-kept"><button type="submit" class="btn btn-sm">Stop sending there</button></form></td></tr>
<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>

		<!-- events -->
<?php $ev_label = array('ok' => array('bg-green-lt', 'in step'), 'behind' => array('bg-yellow-lt', 'behind'), 'down' => array('bg-red-lt', 'not answering'),
	'added' => array('bg-azure-lt', 'added'), 'removed' => array('bg-secondary-lt', 'removed')); ?>
		<div class="card">
			<div class="card-header"><h3 class="card-title">Events <span class="text-muted fw-normal ms-2">last 7 days</span></h3></div>
<?php if (!$v['events']): ?>
			<div class="card-body text-muted">Nothing happened: every check found the same.</div>
<?php else: ?>
			<div class="table-responsive" style="max-height:340px;overflow-y:auto">
				<table class="table card-table table-vcenter dc-kv">
					<tbody>
<?php foreach (array_slice($v['events'], 0, 50) as $e): $lb = $ev_label[$e[2]] ?? $ev_label['removed']; ?>
					<tr><td><?=h($dc_when($e[0]))?></td><td><span class="badge border <?=$lb[0]?> me-1"><?=h($lb[1])?></span> <?=h($e[3])?></td></tr>
<?php endforeach; ?>
					</tbody>
				</table>
			</div>
<?php endif; ?>
			<div class="card-footer text-muted">The dashboard's Needs attention shows a secondary that stops answering for 10 minutes, or stays behind for 15.</div>
		</div>

	</div>
<?php endif; /* secondaries */ ?>

	<!-- add -->
	<div class="modal modal-blur fade dc-modal" id="dc-m-add" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered" role="document">
			<div class="modal-content">
				<form method="post" action="/" autocomplete="off" data-sudo>
					<input type="hidden" name="action" value="dns-cluster-add">
					<div class="modal-header">
						<h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Add a secondary</h5>
						<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
					</div>
					<div class="modal-body">
						<div class="mb-3">
							<label class="form-label required" for="dc-name">Name</label>
							<input type="text" class="form-control" id="dc-name" name="name" required maxlength="253" placeholder="<?=h($dc_ns[1] ?? 'ns2.example.com')?>">
							<small class="form-hint">The name server it answers as - usually one of the names on the Provider tab.</small>
						</div>
						<div class="mb-1">
							<label class="form-label required" for="dc-addr">Address</label>
							<input type="text" class="form-control" id="dc-addr" name="addr" required maxlength="60" placeholder="203.0.113.5">
							<small class="form-hint">Its public IP address. This server sends it the zones and lets it - and only it - copy them.</small>
						</div>
					</div>
					<div class="modal-footer">
						<button type="button" class="btn me-auto" data-bs-dismiss="modal">Cancel</button>
						<button type="submit" class="btn btn-primary">Add</button>
					</div>
				</form>
			</div>
		</div>
	</div>

	<!-- setup command -->
	<div class="modal modal-blur fade dc-modal" id="dc-m-setup" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered modal-lg" role="document">
			<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Set up the secondary</h5>
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
				</div>
				<div class="modal-body">
					<ol class="mb-3">
						<li>Log in to the secondary as root (Rocky, Alma or RHEL 8 to 10, Debian or Ubuntu; a small VM is enough).</li>
						<li>Paste the whole command below and press Enter. It installs PowerDNS, makes it a secondary of this server and copies the zones.
							Running it again is safe.</li>
						<li>Come back here: the secondary's checks show how many zones it has.</li>
					</ol>
					<textarea class="form-control dc-cmd" id="dc-cmd" readonly><?=h($dc_cmd)?></textarea>
					<small class="form-hint">The secondary must reach this server on port 53 (TCP for the copies), and this server the secondary on port 53 (UDP for the notices).</small>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn me-auto" id="dc-copy"><span>Copy the command</span></button>
					<button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
				</div>
			</div>
		</div>
	</div>

	<!-- remove -->
	<form method="post" action="/" id="dc-remove-form">
		<input type="hidden" name="action" value="dns-cluster-remove">
		<input type="hidden" name="name" id="dc-remove-name" value="">
		<div class="modal modal-blur fade" id="dc-m-remove" tabindex="-1" role="dialog" aria-hidden="true">
			<div class="modal-dialog modal-sm modal-dialog-centered" role="document">
				<div class="modal-content">
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
					<div class="modal-status bg-danger"></div>
					<div class="modal-body text-center py-4">
						<h3>Remove <span id="dc-remove-label"></span>?</h3>
						<div class="text-muted">This server stops sending it the zones. Its copies stay until you switch it off, and go stale - take its name out of
							the Provider tab and the domains' name servers first.</div>
					</div>
					<div class="modal-footer">
						<div class="w-100"><div class="row">
							<div class="col"><a href="#" class="btn w-100" data-bs-dismiss="modal">Cancel</a></div>
							<div class="col"><button type="submit" class="btn btn-danger w-100">Remove</button></div>
						</div></div>
					</div>
				</div>
			</div>
		</div>
	</form>

<?php endif; ?>
</div>
