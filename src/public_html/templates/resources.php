<?php
	/* Accounts & Domains > Resource Usage: every account's CPU, memory and
	   processes over 24 hours / 7 days / 30 days, busiest first, and the whole
	   server with what is not any account's (resources_usage(),
	   app/functions/resources.php). Read-only: an account's own page (with
	   Stop) is Metrics > Resource usage in its account panel. */
	$ru_range = isset($_GET['range']) && in_array($_GET['range'], array('day', 'week', 'month'), true) ? $_GET['range'] : 'day';
	$ru = resources_usage($ru_range)['data'];
	$ru_panel = function_exists('ap_ticket') && function_exists('ap_account_map');
	$ru_map = $ru_panel ? ap_account_map() : array();
	$ru_cores = max(1, (int)$ru['cores']);
	$ru_pc = function ($v) { $v = (float)$v; return ($v < 10 ? rtrim(rtrim(number_format($v, 1), '0'), '.') : number_format($v)).'%'; };
	$ru_mb = function ($v) { $v = (float)$v; return $v >= 1024 ? number_format($v / 1024, $v >= 10240 ? 0 : 1).' GB' : number_format($v).' MB'; };
	$ru_when = function ($t) { return $t ? date(date('Y-m-d', $t) === date('Y-m-d') ? 'H:i' : 'M j, H:i', $t) : ''; };
	$ru_label = array('day' => 'the last 24 hours', 'week' => 'the last 7 days', 'month' => 'the last 30 days');
	$ru_events = array('php_full' => array('PHP workers all busy', 'All of a site\'s PHP workers were busy: visitors waited, some got 502/504'),
	                   'timeout' => array('requests stopped', 'A PHP request was stopped at the site\'s time limit'),
	                   'db_full' => array('DB connections full', 'A database user was at its connection limit'),
	                   'mem_kill' => array('stopped by memory limit', 'The session memory limit stopped a process'),
	                   'tasks' => array('processes refused', 'A new process was refused at the session process limit'),
	                   'cpu_held' => array('held at CPU limit', 'SSH, cron and terminal were held at their CPU limit (minutes)'));
	$ru_max = 0.1;
	foreach ($ru['accounts'] as $a) $ru_max = max($ru_max, $a['cpu']);
	$ru_plans = (bool)array_filter(array_column($ru['accounts'], 'package'), 'strlen');
	$ru_cols = $ru_plans ? 11 : 10;
	$srv = $ru['server'];
	$rest = max(0, $srv['cpu'] - $ru['accounts_cpu']);
	$ru_open = function ($user) use ($ru_panel, $ru_map, $ru_range) {
		$a = $ru_map[$user] ?? null;
		if (!$ru_panel || ($a && $a['status'] !== 'active'))
			return '';
		return '<form method="post" action="/" target="_blank" class="d-inline-block me-1"><input type="hidden" name="action" value="account-panel-open">'
			.'<input type="hidden" name="user" value="'.h($user).'"><input type="hidden" name="to" value="/metrics/resources/?range='.h($ru_range).'">'
			.'<button type="submit" class="btn btn-white btn-md" title="The account\'s Resource usage page, with what it runs now, in its account panel">Details</button></form>';
	};
	include('templates/header.php');
?>
        <div class="page-header d-print-none">
            <div class="row align-items-center">
              <div class="col" style="padding-left:22px;">
                <div class="page-pretitle">Accounts &amp; Domains</div>
                <h2 class="page-title">Resource Usage</h2>
              </div>
            </div>
        </div>

<?php msg_render(); ?>

<style>
	#ru-page .table td, #ru-page .table th { vertical-align: middle; }
	#ru-page .ru-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
	#ru-page .ru-num small { display: block; color: #656d77; font-size: 78%; }
	#ru-page .ru-bar { height: 8px; border: 1px solid rgba(98,105,118,.24); background: rgba(98,105,118,.06); min-width: 60px; }
	#ru-page .ru-bar i { display: block; height: 100%; background: #206bc4; }
	#ru-page .ru-bar i.rest { background: #8a96a6; }
	#ru-page .ru-sum { display: flex; flex-wrap: wrap; gap: 8px 32px; }
	#ru-page .ru-sum div { display: grid; }
	#ru-page .ru-sum b { font-size: 1.25rem; font-variant-numeric: tabular-nums; }
	#ru-page .ru-sum b small { font-size: .8rem; color: #656d77; font-weight: 500; }
	#ru-page .ru-sum span { color: #656d77; font-size: .8rem; text-transform: uppercase; letter-spacing: .04em; font-weight: 600; }
	#ru-page .ru-sum em { font-style: normal; color: #656d77; font-size: .8rem; }
	#ru-page #ru-table td:first-child { min-width: 160px; }
	#ru-page tr.ru-kind td:first-child, #ru-page tr.ru-srv td:first-child b { white-space: nowrap; }
	#ru-page tr.ru-srv td { background: rgba(98,105,118,.045); }
	#ru-page tr.ru-kind td { background: rgba(98,105,118,.03); font-size: .85rem; }
	#ru-page tr.ru-kind td:first-child { padding-left: 2.6rem; color: #656d77; }
	#ru-page tr.ru-acct { cursor: pointer; }
	#ru-page tr.ru-acct .ru-chev { display: inline-block; width: 1rem; color: #9aa0ac; transition: transform .15s; }
	#ru-page tr.ru-acct.open .ru-chev { transform: rotate(90deg); }
	#ru-page tr[hidden] { display: none; }
	#ru-page .ru-ev { display: inline-block; margin: 1px 4px 1px 0; }
	#ru-page th .ru-sort { all: unset; cursor: pointer; display: inline-flex; gap: 4px; align-items: center; text-transform: inherit; letter-spacing: inherit; font: inherit; color: inherit; }
	#ru-page th .ru-sort:hover { color: #206bc4; }
	#ru-page th .ru-sort:focus-visible { outline: 2px solid #206bc4; outline-offset: 2px; }
	#ru-page th .ru-sort::after { content: "\2195"; opacity: .35; font-size: .9em; }
	#ru-page th[aria-sort="descending"] .ru-sort, #ru-page th[aria-sort="ascending"] .ru-sort { color: #206bc4; }
	#ru-page th[aria-sort="descending"] .ru-sort::after { content: "\2193"; opacity: 1; }
	#ru-page th[aria-sort="ascending"] .ru-sort::after { content: "\2191"; opacity: 1; }
</style>

<div id="ru-page">
	<div class="card mb-3">
		<div class="card-body">
			<div class="row g-2 align-items-center mb-3">
				<div class="col-auto">
					<div class="btn-group" role="group" aria-label="Period">
<?php foreach (array('day' => '24 hours', 'week' => '7 days', 'month' => '30 days') as $k => $l): ?>
						<a href="/resources/?range=<?=$k?>" class="btn<?=$k === $ru_range ? ' btn-primary' : ''?>"<?=$k === $ru_range ? ' aria-current="true"' : ''?>><?=$l?></a>
<?php endforeach; ?>
					</div>
				</div>
				<div class="col-auto">
					<input type="search" class="form-control" id="ru-q" placeholder="Find an account or a site" autocomplete="off">
				</div>
				<div class="col text-muted text-end small">
					<?=$ru['collected'] ? 'Checked every minute, last at '.h(date('Y-m-d H:i', $ru['collected'])) : 'Not checked yet: the first check runs within a minute'?>
				</div>
			</div>
			<div class="ru-sum">
				<div><b><?=h($ru_pc($srv['cpu']))?> <small>of <?=$ru_cores * 100?>%</small></b><span>Server CPU</span><em>highest <?=h($ru_pc($srv['cpu_max']))?><?=$srv['at_cpu'] ? ' at '.h($ru_when($srv['at_cpu'])) : ''?></em></div>
				<div><b><?=h($ru_mb($srv['mem']))?> <small>of <?=h($ru_mb($ru['ram_mb']))?></small></b><span>Server memory in use</span><em>highest <?=h($ru_mb($srv['mem_max']))?></em></div>
				<div><b><?=h($ru_pc($ru['accounts_cpu']))?></b><span>Accounts' CPU</span><em>their sites, mail, sessions, jobs</em></div>
				<div><b><?=h($ru_pc($rest))?></b><span>Rest of the server</span><em>database, web and mail servers, the panel</em></div>
			</div>
		</div>
		<div class="table-responsive">
			<table class="table card-table table-vcenter" id="ru-table">
				<thead>
					<tr>
						<th><button type="button" class="ru-sort" data-s="user" title="Sort by account name">Account</button></th>
<?php if ($ru_plans): ?>
						<th><button type="button" class="ru-sort" data-s="plan" title="Sort by plan">Plan</button></th>
<?php endif; ?>
						<th class="ru-num" aria-sort="descending"><button type="button" class="ru-sort" data-s="cpu" title="Sort by average CPU, highest first">CPU</button></th>
						<th style="width:9%"></th>
						<th class="ru-num"><button type="button" class="ru-sort" data-s="cpumax" title="Sort by the highest CPU check">Highest</button></th>
						<th class="ru-num"><button type="button" class="ru-sort" data-s="mem" title="Sort by average memory, highest first">Memory</button></th>
						<th class="ru-num"><button type="button" class="ru-sort" data-s="memmax" title="Sort by the highest memory check">Highest</button></th>
						<th class="ru-num"><button type="button" class="ru-sort" data-s="pr" title="Sort by processes, most first">Processes</button></th>
						<th class="ru-num"><button type="button" class="ru-sort" data-s="wk" title="Sort by PHP workers, most first">PHP workers</button></th>
						<th>Limits reached</th>
						<th></th>
					</tr>
				</thead>
				<tbody class="ru-top">
					<tr class="ru-srv">
						<td><b>Rest of the server</b> <span class="text-muted small ms-1" title="MariaDB, the web and mail servers, the panel, system services">not any account's</span></td>
<?php if ($ru_plans): ?>
						<td></td>
<?php endif; ?>
						<td class="ru-num"><b><?=h($ru_pc($rest))?></b></td>
						<td><div class="ru-bar" title="Compared with the busiest account"><i class="rest" style="width:<?=number_format(min(100, $rest / max($ru_max, $rest) * 100), 1, '.', '')?>%"></i></div></td>
						<td class="ru-num"></td>
						<td class="ru-num"></td>
						<td class="ru-num"></td>
						<td class="ru-num"></td>
						<td class="ru-num"></td>
						<td></td>
						<td></td>
					</tr>
				</tbody>
<?php foreach ($ru['accounts'] as $a):
		$sites = array_keys($a['workers']);
		$keys = strtolower($a['user'].' '.$a['domain'].' '.implode(' ', $sites));
		$wk = $a['workers'];
		arsort($wk);
		$wtop = $wk ? array_key_first($wk) : '';
		$kinds = $a['kinds'];
		uasort($kinds, function ($x, $y) { return $y['cpu'] <=> $x['cpu'] ?: $y['mem'] <=> $x['mem']; }); ?>
				<tbody class="ru-grp" data-user="<?=h($a['user'])?>" data-plan="<?=h(strtolower($a['package']))?>" data-cpu="<?=h($a['cpu'])?>" data-cpumax="<?=h($a['cpu_max'])?>"
				       data-mem="<?=h($a['mem'])?>" data-memmax="<?=h($a['mem_max'])?>" data-pr="<?=(int)$a['pr_max']?>" data-wk="<?=$wtop !== '' ? (int)$wk[$wtop] : 0?>">
					<tr class="ru-acct" data-k="<?=h($keys)?>" data-u="<?=h($a['user'])?>" tabindex="0" aria-expanded="false">
						<td><span class="ru-chev" aria-hidden="true"><?=$kinds ? '&rsaquo;' : ''?></span><a href="/account/<?=h($a['user'])?>"><b><?=h($a['user'])?></b></a> <span class="text-muted ms-1"><?=h($a['domain'])?></span></td>
<?php if ($ru_plans): ?>
						<td><?=$a['package'] !== '' ? '<span class="badge bg-azure-lt border">'.h($a['package']).'</span>' : '<span class="text-muted">-</span>'?></td>
<?php endif; ?>
						<td class="ru-num"><b><?=h($ru_pc($a['cpu']))?></b></td>
						<td><div class="ru-bar" title="Compared with the busiest account"><i style="width:<?=number_format($a['cpu'] ? max(0.5, $a['cpu'] / $ru_max * 100) : 0, 1, '.', '')?>%"></i></div></td>
						<td class="ru-num"><?=h($ru_pc($a['cpu_max']))?><?php if ($a['at_cpu'] && $a['cpu_max'] >= 1): ?><small><?=h($ru_when($a['at_cpu']))?></small><?php endif; ?></td>
						<td class="ru-num"><?=h($ru_mb($a['mem']))?></td>
						<td class="ru-num"><?=h($ru_mb($a['mem_max']))?><?php if ($a['at_mem'] && $a['mem_max'] >= 1): ?><small><?=h($ru_when($a['at_mem']))?></small><?php endif; ?></td>
						<td class="ru-num"><?=number_format($a['pr_max'])?></td>
						<td class="ru-num"><?=$wtop !== '' ? number_format($wk[$wtop]).'<small>'.h($wtop).'</small>' : '<span class="text-muted">-</span>'?></td>
						<td><?php foreach ($a['events'] as $k => $e): $ev = $ru_events[$k] ?? array($k, $k); ?><span class="badge <?=in_array($k, array('mem_kill', 'tasks', 'php_full'), true) ? 'bg-orange-lt' : 'bg-yellow-lt'?> border ru-ev" title="<?=h($ev[1])?>"><?=$k === 'cpu_held' ? number_format($e['n']).' min' : number_format($e['times']).'×'?> <?=h($ev[0])?></span><?php endforeach; ?></td>
						<td class="text-end text-nowrap"><?=$ru_open($a['user'])?><a class="btn btn-white btn-md" href="/account/<?=h($a['user'])?>/?tab=config&amp;cfg=limits" title="CPU, memory and process limits of its SSH, cron and terminal sessions">Limits</a></td>
					</tr>
<?php	foreach ($kinds as $k => $x): ?>
					<tr class="ru-kind" data-k="<?=h($keys)?>" data-of="<?=h($a['user'])?>" hidden>
						<td><?=strncmp($k, 'php:', 4) === 0 ? 'PHP of '.h($x['name']) : h($x['name'])?></td>
<?php if ($ru_plans): ?>
						<td></td>
<?php endif; ?>
						<td class="ru-num"><?=h($ru_pc($x['cpu']))?></td>
						<td></td>
						<td class="ru-num"><?=h($ru_pc($x['cpu_max']))?></td>
						<td class="ru-num"><?=h($ru_mb($x['mem']))?></td>
						<td class="ru-num"><?=h($ru_mb($x['mem_max']))?></td>
						<td class="ru-num"><?=number_format($x['pr_max'])?></td>
						<td class="ru-num"><?=strncmp($k, 'php:', 4) === 0 ? number_format($x['pr_max']) : ''?></td>
						<td></td>
						<td></td>
					</tr>
<?php	endforeach; ?>
				</tbody>
<?php endforeach; ?>
<?php if (!$ru['accounts']): ?>
				<tbody><tr><td colspan="<?=$ru_cols?>" class="text-muted">There are no hosting accounts yet.</td></tr></tbody>
<?php endif; ?>
			</table>
		</div>
		<div class="card-footer text-muted small">
			In <?=h($ru_label[$ru_range])?>, average and highest check. CPU: 100% = one core (this server has <?=$ru_cores?>). Memory: what an account's processes hold, a page several of them share counted once.
			Counted per account: each site's PHP-FPM workers, IMAP / POP3 logins, SSH / cron / terminal sessions (their own counters) and background jobs;
			not counted per account: database queries (they run in MariaDB) and sites on PHP inside Apache (mod_php). A process shorter than a minute may not show.
			Also <code>reqad resources usage --range=<?=h($ru_range)?></code>.
		</div>
	</div>
</div>

<script>
(function () {
	var q = document.getElementById('ru-q'), open = {};
	function show() {
		var v = q ? q.value.trim().toLowerCase() : '';
		document.querySelectorAll('#ru-table tbody tr[data-k]').forEach(function (tr) {
			var hit = v === '' || tr.getAttribute('data-k').indexOf(v) >= 0, of = tr.getAttribute('data-of');
			tr.hidden = !hit || (of !== null && !open[of]);
		});
	}
	if (q) q.addEventListener('input', show);

	/* sorting: each account is a tbody with its breakdown rows, so they move
	   together; numbers sort highest first, names A to Z; a second click turns
	   it around. The choice stays in the address and in the period links. */
	var table = document.getElementById('ru-table'), TEXT = { user: 1, plan: 1 };
	function sortBy(key, dir) {
		var groups = Array.prototype.slice.call(table.querySelectorAll('tbody.ru-grp'));
		groups.sort(function (a, b) {
			var x = a.getAttribute('data-' + key), y = b.getAttribute('data-' + key), r;
			if (TEXT[key]) r = (x === '') - (y === '') || x.localeCompare(y);
			else r = parseFloat(y) - parseFloat(x);
			if (dir === 'asc') r = -r;
			return r || a.getAttribute('data-user').localeCompare(b.getAttribute('data-user'));
		});
		groups.forEach(function (g) { table.appendChild(g); });
		table.querySelectorAll('th').forEach(function (th) {
			var b = th.querySelector('.ru-sort');
			if (b && b.getAttribute('data-s') === key) th.setAttribute('aria-sort', dir === 'asc' ? 'ascending' : 'descending');
			else th.removeAttribute('aria-sort');
		});
		try {
			var u = new URL(location.href);
			u.searchParams.set('sort', key); u.searchParams.set('dir', dir);
			history.replaceState(null, '', u);
			document.querySelectorAll('#ru-page a[href^="/resources/?range="]').forEach(function (a) {
				var l = new URL(a.href); l.searchParams.set('sort', key); l.searchParams.set('dir', dir); a.href = l.pathname + l.search;
			});
		} catch (e) { /* the address stays as it was */ }
	}
	var cur = { key: 'cpu', dir: 'desc' };
	table.querySelectorAll('.ru-sort').forEach(function (b) {
		b.addEventListener('click', function () {
			var key = b.getAttribute('data-s'), first = TEXT[key] ? 'asc' : 'desc';
			cur = { key: key, dir: cur.key === key ? (cur.dir === 'asc' ? 'desc' : 'asc') : first };
			sortBy(cur.key, cur.dir);
		});
	});
	try {
		var p = new URL(location.href).searchParams, k = p.get('sort');
		if (k && table.querySelector('.ru-sort[data-s="' + k + '"]')) {
			cur = { key: k, dir: p.get('dir') === 'asc' ? 'asc' : 'desc' };
			sortBy(cur.key, cur.dir);
		}
	} catch (e) { /* default order: CPU */ }
	document.querySelectorAll('#ru-table tr.ru-acct').forEach(function (tr) {
		var go = function (e) {
			if (e.target.closest('a, button, form')) return;
			var u = tr.getAttribute('data-u');
			open[u] = !open[u];
			tr.classList.toggle('open', open[u]);
			tr.setAttribute('aria-expanded', open[u] ? 'true' : 'false');
			show();
		};
		tr.addEventListener('click', go);
		tr.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(e); } });
	});
})();
</script>

<?php include('templates/footer.php'); ?>
