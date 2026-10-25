<?php
	/* Accounts & Domains > Shell Activity: the programs hosting accounts start
	   (SSH, the account panel's Terminal, cron, a site's PHP), read from the
	   kernel's audit log by `reqad shelllog collect` (app/functions/shelllog.php).
	   /shell-activity/            every account, red flags first
	   /shell-activity/<user>/     one account's commands, newest first
	   The menu entry shows only while logging is on (Settings > Security). */
	$sa_user = isset($reqs[2]) && valid_username($reqs[2]) && account_get($db, $reqs[2]) ? $reqs[2] : '';
	$sa_on = shelllog_enabled();
	$sa_st = shelllog_status();
	$sa_src = shelllog_sources();
	$sa_when = function ($t) {
		if (!$t) return '';
		if (date('Y-m-d', $t) === date('Y-m-d')) return date('H:i', $t);
		return date(date('Y', $t) === date('Y') ? 'M j, H:i' : 'Y-m-d H:i', $t);
	};
	$sa_shell = function ($sh) {
		$l = array('full' => array('full shell', 'bg-azure-lt', 'Full shell: bash with the whole server visible (as the account)'),
		           'jailed' => array('jailed shell', '', 'Jailed: a sandbox with its own home only'),
		           'none' => array('SFTP only', '', 'No shell: SFTP over SSH; cron jobs run jailed'),
		           'other' => array('other shell', 'bg-yellow-lt', 'A login shell Reqad did not set'));
		$x = $l[$sh] ?? $l['other'];
		return '<span class="badge border '.$x[1].'" title="'.h($x[2]).'"'.($x[1] === '' ? ' style="background:#f1f3f5;color:#495057;"' : '').'>'.h($x[0]).'</span>';
	};
	$sa_badge = function ($src, $addr = '', $via = '') use ($sa_src) {
		$c = array('ssh' => 'bg-blue-lt', 'terminal' => 'bg-indigo-lt', 'cron' => 'bg-teal-lt', 'account' => 'bg-orange-lt');
		$x = $sa_src[$src] ?? array($src, '');
		return '<span class="badge border '.($c[$src] ?? '').'" title="'.h($x[1]).'"'.(isset($c[$src]) ? '' : ' style="background:#f1f3f5;color:#495057;"').'>'.h($x[0]).'</span>'
		      .($addr !== '' ? ' <span class="text-muted small text-nowrap">'.h($addr).'</span>' : '')
		      .($via !== '' ? ' <span class="text-muted small text-nowrap">by '.h($via).'</span>' : '');
	};
	$sa_stale = $sa_on && (($sa_st['collected'] && $sa_st['collected'] < time() - 900) || (!$sa_st['collected'] && $sa_st['since'] && $sa_st['since'] < time() - 900));

	if ($sa_user !== '') {
		$sa_f = array(
			'day'     => isset($_GET['day']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['day']) ? $_GET['day'] : '',
			'src'     => isset($_GET['src']) && isset($sa_src[$_GET['src']]) ? $_GET['src'] : '',
			'admin'   => isset($_GET['src']) && $_GET['src'] === 'all',
			'flagged' => !empty($_GET['flagged']),
			'q'       => isset($_GET['q']) && is_string($_GET['q']) ? substr(trim($_GET['q']), 0, 200) : '',
			'page'    => isset($_GET['page']) ? max(0, (int)$_GET['page']) : 0,
		);
		$sa_list = shelllog_list($sa_user, $sa_f);
		$sa_logins = shelllog_logins($sa_user);
		$sa_acct = account_get($db, $sa_user);
		$sa_home = (string)(posix_getpwnam($sa_user)['dir'] ?? '/home/'.$sa_user);
		$sa_page_url = function ($page) use ($sa_user, $sa_f) {
			$q = array_filter(array('day' => $sa_f['day'], 'src' => $sa_f['admin'] ? 'all' : $sa_f['src'], 'flagged' => $sa_f['flagged'] ? '1' : '',
			                        'q' => $sa_f['q'], 'page' => $page ?: ''), 'strlen');
			return '/shell-activity/'.rawurlencode($sa_user).'/'.($q ? '?'.http_build_query($q) : '');
		};
	} else {
		$sa_accounts = shelllog_accounts();
	}
	include('templates/header.php');
?>
        <div class="page-header d-print-none">
            <div class="row align-items-center">
              <div class="col" style="padding-left:22px;">
<?php if ($sa_user !== ''): ?>
                <div class="page-pretitle">Accounts &amp; Domains / <a href="/shell-activity/" class="text-muted">Shell Activity</a></div>
                <h2 class="page-title"><?=h($sa_user)?> <span class="text-muted fw-normal ms-2" style="font-size:.95rem"><?=h($sa_acct['domain'] ?? '')?></span> <span class="ms-2"><?=$sa_shell(account_shell_get($sa_user))?></span></h2>
<?php else: ?>
                <div class="page-pretitle">Accounts &amp; Domains</div>
                <h2 class="page-title">Shell Activity</h2>
<?php endif; ?>
              </div>
              <div class="col-auto ms-auto d-print-none">
                <a href="/security/#shell-log" class="btn btn-white">Logging settings</a>
              </div>
            </div>
        </div>

<?php msg_render(); ?>

<style>
	#sa-page .table td, #sa-page .table th { vertical-align: middle; }
	#sa-page .sa-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
	#sa-page .sa-cmd { font-family: var(--tblr-font-monospace, ui-monospace, SFMono-Regular, Menlo, monospace); font-size: .82rem; white-space: pre-wrap; word-break: break-all; color: #1d273b; }
	#sa-page .sa-last { max-width: 30rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: inline-block; vertical-align: middle; }
	#sa-page tr.sa-acct { cursor: pointer; }
	#sa-page tr.sa-acct:hover td { background: rgba(32,107,196,.035); }
	#sa-page tr.sa-flag td { background: rgba(214,57,57,.055); }
	#sa-page tr.sa-flag td:first-child { box-shadow: inset 3px 0 0 #d63939; }
	#sa-page .sa-dir { font-family: var(--tblr-font-monospace, ui-monospace, monospace); font-size: .78rem; color: #656d77; white-space: nowrap; max-width: 14rem; overflow: hidden; text-overflow: ellipsis; display: inline-block; vertical-align: middle; }
	#sa-page .sa-times { font-size: .75rem; color: #656d77; display: block; }
	#sa-page .sa-sum { display: flex; flex-wrap: wrap; gap: 8px 32px; }
	#sa-page .sa-sum div { display: grid; }
	#sa-page .sa-sum b { font-size: 1.25rem; font-variant-numeric: tabular-nums; }
	#sa-page .sa-sum span { color: #656d77; font-size: .8rem; text-transform: uppercase; letter-spacing: .04em; font-weight: 600; }
	#sa-page tr[hidden] { display: none; }
	@media (max-width: 767px) { #sa-page .sa-hide-sm { display: none; } }
</style>

<div id="sa-page">
<?php if (!$sa_on): ?>
	<div class="alert alert-info">Command logging is off, so nothing new is logged. Turn it on in <a href="/security/#shell-log">Settings &gt; Security</a>.<?=$sa_st['commands'] ? ' What was logged before is kept '.(int)$sa_st['days'].' days.' : ''?></div>
<?php elseif ($sa_st['rule_error'] !== ''): ?>
	<div class="alert alert-warning">Commands are not being logged: <?=h($sa_st['rule_error'])?></div>
<?php elseif ($sa_stale): ?>
	<div class="alert alert-warning">The audit log was not read since <?=h($sa_st['collected'] ? date('Y-m-d H:i', $sa_st['collected']) : date('Y-m-d H:i', $sa_st['since']))?>. Cron runs <code>reqad shelllog collect</code> every minute: check that <code>/etc/cron.d/reqad</code> has that line, or run it by hand to see why.</div>
<?php endif; ?>

<?php if ($sa_user === ''): ?>
	<div class="card mb-3">
		<div class="card-body">
			<div class="row g-2 align-items-center">
				<div class="col-auto">
					<input type="search" class="form-control" id="sa-q" placeholder="Find an account or a site" autocomplete="off">
				</div>
				<div class="col text-muted text-end small">
					<?=$sa_st['since'] ? 'Logged since '.h(date('Y-m-d H:i', $sa_st['since'])).', ' : ''?>kept <?=(int)$sa_st['days']?> days<?=$sa_st['collected'] ? '; read every minute, last at '.h(date('H:i', $sa_st['collected'])) : ''?>
				</div>
			</div>
		</div>
		<div class="table-responsive">
			<table class="table card-table table-vcenter" id="sa-table">
				<thead>
					<tr>
						<th>Account</th>
						<th class="sa-hide-sm">Shell</th>
						<th class="sa-num">24 hours</th>
						<th class="sa-num">7 days</th>
						<th>Red flags</th>
						<th>Last command</th>
					</tr>
				</thead>
				<tbody>
<?php foreach ($sa_accounts as $a): $url = '/shell-activity/'.rawurlencode($a['user']).'/'; ?>
					<tr class="sa-acct<?=$a['flag_new'] ? ' sa-flag' : ''?>" data-k="<?=h(strtolower($a['user'].' '.$a['domain']))?>" data-href="<?=h($url)?>" tabindex="0">
						<td class="text-nowrap"><a href="<?=h($url)?>"><b><?=h($a['user'])?></b></a> <span class="text-muted ms-1"><?=h($a['domain'])?></span></td>
						<td class="sa-hide-sm"><?=$sa_shell($a['shell'])?></td>
						<td class="sa-num"><?=$a['day'] ? number_format($a['day']) : '<span class="text-muted">-</span>'?></td>
						<td class="sa-num"><?=$a['week'] ? number_format($a['week']) : '<span class="text-muted">-</span>'?></td>
						<td class="text-nowrap"><?php if ($a['flagged']): ?><a href="<?=h($url)?>?flagged=1" class="badge bg-red-lt border text-decoration-none" title="Commands that look like abuse, in the days kept"><?=number_format($a['flagged'])?> flagged</a><?=$a['flag_new'] ? ' <span class="text-danger small">'.number_format($a['flag_new']).' in 24 h</span>' : ''?><?php else: ?><span class="text-muted">-</span><?php endif; ?></td>
						<td><?php if ($a['last']): ?><span class="text-muted small text-nowrap me-1"><?=h($sa_when($a['last']))?></span> <code class="sa-last" title="<?=h($a['last_cmd'])?>"><?=h($a['last_cmd'])?></code><?php else: ?><span class="text-muted">-</span><?php endif; ?></td>
					</tr>
<?php endforeach; ?>
<?php if (!$sa_accounts): ?>
					<tr><td colspan="6" class="text-muted">There are no hosting accounts yet.</td></tr>
<?php endif; ?>
				</tbody>
			</table>
		</div>
		<div class="card-footer text-muted small">
			Every program an account starts: over SSH, in the account panel's Terminal, from its cron jobs and from its sites' PHP. Logged by the kernel, also inside the jailed shell;
			commands built into the shell (<code>cd</code>, <code>echo &gt; file</code>) start no program and do not show. What root or an admin runs as an account is left out of these counts.
			Also <code>reqad shelllog accounts</code>.
		</div>
	</div>

<?php else: $rows = $sa_list['rows']; ?>
	<div class="card mb-3">
		<div class="card-body">
			<form method="get" action="/shell-activity/<?=h(rawurlencode($sa_user))?>/" class="row g-2 align-items-center">
				<div class="col-auto">
					<input type="date" name="day" class="form-control" value="<?=h($sa_f['day'])?>" max="<?=date('Y-m-d')?>" aria-label="Day" title="One day (empty: all days kept)">
				</div>
				<div class="col-auto">
					<select name="src" class="form-select" aria-label="From">
						<option value="">From anywhere but admins</option>
<?php foreach ($sa_src as $k => $x): ?>
						<option value="<?=h($k)?>" <?=$sa_f['src'] === $k ? 'selected' : ''?>><?=h($x[0])?></option>
<?php endforeach; ?>
						<option value="all" <?=$sa_f['admin'] ? 'selected' : ''?>>Everything, admins too</option>
					</select>
				</div>
				<div class="col-auto">
					<input type="search" name="q" class="form-control" value="<?=h($sa_f['q'])?>" placeholder="Command, folder or address" autocomplete="off">
				</div>
				<div class="col-auto">
					<label class="form-check mb-0"><input type="checkbox" class="form-check-input" name="flagged" value="1" <?=$sa_f['flagged'] ? 'checked' : ''?>><span class="form-check-label">Red flags only</span></label>
				</div>
				<div class="col-auto">
					<button type="submit" class="btn">Show</button>
<?php if ($sa_f['day'] !== '' || $sa_f['src'] !== '' || $sa_f['admin'] || $sa_f['flagged'] || $sa_f['q'] !== ''): ?>
					<a href="/shell-activity/<?=h(rawurlencode($sa_user))?>/" class="btn btn-ghost-secondary">Clear</a>
<?php endif; ?>
				</div>
				<div class="col text-muted text-end small"><?=number_format($sa_list['total'])?> command<?=$sa_list['total'] == 1 ? '' : 's'?></div>
			</form>
<?php if ($sa_logins): ?>
			<div class="mt-3 small"><span class="text-muted me-2">SSH logins from</span>
<?php foreach ($sa_logins as $l): ?>
				<a href="<?=h('/shell-activity/'.rawurlencode($sa_user).'/?q='.rawurlencode($l['addr']))?>" class="badge bg-blue-lt border text-decoration-none me-1" title="<?=(int)$l['n']?> login<?=$l['n'] == 1 ? '' : 's'?>, last <?=h($sa_when((int)$l['t']))?>"><?=h($l['addr'])?> <span class="text-muted">&times;<?=(int)$l['n']?></span></a>
<?php endforeach; ?>
			</div>
<?php endif; ?>
		</div>
		<div class="table-responsive">
			<table class="table card-table table-vcenter">
				<thead>
					<tr>
						<th style="width:1%">When</th>
						<th style="width:1%">From</th>
						<th class="sa-hide-sm" style="width:1%">Folder</th>
						<th>Command</th>
					</tr>
				</thead>
				<tbody>
<?php foreach ($rows as $r):
		$cwd = (string)$r['cwd'];
		$home = rtrim($sa_home, '/');
		if ($cwd === $home || strncmp($cwd, $home.'/', strlen($home) + 1) === 0)
			$cwd = '~'.substr($cwd, strlen($home));
		$n = (int)$r['n']; ?>
					<tr<?=$r['flag'] !== '' ? ' class="sa-flag"' : ''?>>
						<td class="text-nowrap"><?=h($sa_when((int)$r['first']))?><?php if ($n > 1): ?><span class="sa-times" title="The same command <?=$n?> times, the last at <?=h(date('H:i', (int)$r['last']))?>">&times;<?=number_format($n)?> to <?=h(date('H:i', (int)$r['last']))?></span><?php endif; ?></td>
						<td class="text-nowrap"><?=$sa_badge($r['src'], (string)$r['addr'], (string)$r['via'])?></td>
						<td class="sa-hide-sm"><span class="sa-dir" title="<?=h($r['cwd'])?>"><?=h($cwd)?></span></td>
						<td>
<?php if ($r['flag'] !== ''): ?>
							<span class="badge bg-red-lt border mb-1"><?=h(shelllog_flag_label($r['flag']))?></span>
<?php endif; ?>
<?php if ((int)$r['err']): ?>
							<span class="badge bg-yellow-lt border mb-1" title="The program did not start">refused: <?=h(shelllog_err_label($r['err']))?></span>
<?php endif; ?>
							<div class="sa-cmd"><?=h($r['cmd'])?></div>
						</td>
					</tr>
<?php endforeach; ?>
<?php if (!$rows): ?>
					<tr><td colspan="4" class="text-muted"><?=$sa_list['total'] === 0 && $sa_f['q'] === '' && !$sa_f['flagged'] && $sa_f['day'] === '' ? 'Nothing logged for this account yet.' : 'Nothing matches.'?></td></tr>
<?php endif; ?>
				</tbody>
			</table>
		</div>
<?php if ($sa_list['page'] > 0 || $sa_list['more']): ?>
		<div class="card-footer d-flex gap-2">
<?php if ($sa_list['page'] > 0): ?>
			<a class="btn" href="<?=h($sa_page_url($sa_list['page'] - 1))?>">&larr; Newer</a>
<?php endif; ?>
<?php if ($sa_list['more']): ?>
			<a class="btn ms-auto" href="<?=h($sa_page_url($sa_list['page'] + 1))?>">Older &rarr;</a>
<?php endif; ?>
		</div>
<?php endif; ?>
		<div class="card-footer text-muted small">
			Newest first. The same command from the same place again within the hour is one line (&times;N). Red flags are a hint, not proof: a developer may well
			pipe an installer into a shell. Also <code>reqad shelllog show <?=h($sa_user)?></code>.
		</div>
	</div>
<?php endif; ?>
</div>

<script>
(function () {
	var q = document.getElementById('sa-q');
	if (q) q.addEventListener('input', function () {
		var v = q.value.trim().toLowerCase();
		document.querySelectorAll('#sa-table tbody tr[data-k]').forEach(function (tr) {
			tr.hidden = v !== '' && tr.getAttribute('data-k').indexOf(v) < 0;
		});
	});
	document.querySelectorAll('#sa-table tr.sa-acct').forEach(function (tr) {
		var go = function (e) {
			if (e.target.closest('a, button')) return;
			location.href = tr.getAttribute('data-href');
		};
		tr.addEventListener('click', go);
		tr.addEventListener('keydown', function (e) { if (e.key === 'Enter') go(e); });
	});
})();
</script>

<?php include('templates/footer.php'); ?>
