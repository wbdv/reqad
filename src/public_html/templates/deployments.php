<?php
	/* Accounts > Deployments: sites whose code comes from Git and/or that run
	   on Node.js / Python instead of PHP. Each site is one card with both parts.
	   Logic: app/functions/deploy.php + apps.php; form actions:
	   modules/deploy_actions.php; deploy / rollback / logs / start-stop /
	   environment / runtimes: ajax-deploy-* and ajax-app-* in modules/ajax.php.
	   Feature switch: deployments=1 in etc/server-software.ini. */
	$dep_ok    = deploy_ready() && apps_ready();
	$dep_front = web_front() === 'nginx';
	$dep_deps  = array();
	$dep_apps  = array();
	if ($dep_ok) {
		foreach (deploy_list() as $d) $dep_deps[$d['domain']] = $d;
		foreach (app_list() as $a)    $dep_apps[$a['domain']] = $a;
	}
	$dep_sites = array_unique(array_merge(array_keys($dep_deps), array_keys($dep_apps)));
	sort($dep_sites);
	$dep_rt = $dep_ok ? app_runtimes() : array();
	$dep_domains = array();       // account => [domain, ...] with nothing set up yet
	foreach (account_list($db) as $acc)
		if (!in_array($acc['domain'], $dep_sites, true))
			$dep_domains[$acc['user']][] = $acc['domain'];
	foreach (addon_domain_list($ini) as $ad)
		if (!in_array($ad['domain'], $dep_sites, true))
			$dep_domains[$ad['user']][] = $ad['domain'];
	ksort($dep_domains);
	$dep_connect = isset($_GET['connect']) ? (int)$_GET['connect'] : 0;
	$dep_when = function ($ts) {
		if (!(int)$ts) return '';
		$d = time() - (int)$ts;
		if ($d < 90)    return 'just now';
		if ($d < 3600)  return floor($d / 60).' min ago';
		if ($d < 86400) return floor($d / 3600).' h ago';
		return date('Y-m-d H:i', (int)$ts);
	};
	$dep_badge = array('ok' => 'bg-green-lt', 'failed' => 'bg-red-lt', 'running' => 'bg-blue-lt', 'queued' => 'bg-blue-lt', 'skipped' => 'bg-muted-lt');
	$app_badge = array('active' => 'bg-green-lt', 'failed' => 'bg-red-lt', 'inactive' => 'bg-muted-lt', 'activating' => 'bg-blue-lt');
	$app_pkg_badge = array('ok' => 'bg-green-lt', 'warn' => 'bg-yellow-lt', 'failed' => 'bg-red-lt', 'running' => 'bg-blue-lt');
	include('templates/header.php');
?>
        <div class="page-header d-print-none">
            <div class="row align-items-center">
              <div class="col" style="padding-left:22px;">
                <div class="page-pretitle">Accounts &amp; Domains</div>
                <h2 class="page-title">Deployments</h2>
              </div>
<?php if ($dep_ok): ?>
              <div class="col-12 col-sm-auto ms-auto mt-2 mt-sm-0 d-flex gap-2" style="padding-left:22px">
                <a href="https://www.reqad.com/docs/deployments" target="_blank" rel="noopener" class="btn">Documentation<svg xmlns="http://www.w3.org/2000/svg" class="icon ms-1" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 6h-6a2 2 0 0 0 -2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-6" /><path d="M11 13l9 -9" /><path d="M15 4h5v5" /></svg></a>
                <button type="button" class="btn btn-primary" id="dep-new"<?=$dep_domains ? '' : ' disabled'?>>New deployment</button>
              </div>
<?php endif; ?>
            </div>
        </div>

<?php msg_render(); ?>

<style>
	.dep-sub { display: block; color: #656d77; font-size: 90%; }
	.dep-mono { font-family: monospace; font-size: .92rem; }
	.dep-part { border-left: 3px solid rgba(98,105,118,.16); padding-left: 14px; height: 100%; }
	.dep-part-title { font-size: .75rem; text-transform: uppercase; letter-spacing: .04em; color: #656d77; font-weight: 600; margin-bottom: 6px; }
	.dep-part .btn-list { margin-top: 10px; }
	#dep-log-text { background: #1e293b; color: #e2e8f0; font-size: 12.5px; line-height: 1.45; max-height: 60vh; min-height: 18rem;
		overflow: auto; padding: 12px 14px; border-radius: 4px; white-space: pre-wrap; word-break: break-word; margin: 0; }
	#app-env-text { font-family: monospace; font-size: 12.5px; }
	#modal-dep-connect ol li { margin-bottom: .6rem; }
	.dep-section { border-top: 1px solid rgba(98,105,118,.16); padding-top: 1rem; margin-top: .5rem; }
	.dep-doc { font-size: .875rem; white-space: nowrap; }
	.dep-presets { display: grid; grid-template-columns: repeat(auto-fill, minmax(100px, 1fr)); gap: 8px; }
	.dep-preset { display: flex; flex-direction: column; align-items: center; gap: 2px; padding: 12px 6px 10px; text-align: center;
		border: 1px solid rgba(98,105,118,.24); border-radius: 6px; background: transparent; color: inherit; cursor: pointer;
		transition: border-color .15s, box-shadow .15s, background-color .15s; }
	.dep-preset .icon { width: 30px; height: 30px; color: var(--dep-c); margin-bottom: 4px; }
	.dep-preset strong { font-size: .875rem; font-weight: 600; }
	.dep-preset small { font-size: .75rem; color: #656d77; }
	.dep-preset:hover { border-color: var(--dep-c); background: rgba(98,105,118,.04); }
	.dep-preset:focus-visible { outline: 2px solid #206bc4; outline-offset: 2px; }
	.dep-preset.active { border-color: var(--dep-c); box-shadow: inset 0 0 0 1px var(--dep-c); background: rgba(98,105,118,.05); }
</style>

<div id="deployments">
<?php if (!$dep_ok): ?>
	<div class="alert alert-warning">The deployment tables are missing. Run the Reqad update (databases 1043 and 1044) first.</div>
<?php else: ?>

<?php if (!$dep_sites): ?>
	<div class="card mb-3">
		<div class="card-body">
			<h3 class="card-title">Deploy sites from Git, run Node.js and Python apps</h3>
			<p class="text-muted mb-2"><strong>Git:</strong> each deploy fetches a branch from GitHub, GitLab, Gitea or Bitbucket, runs your build commands
				as the account in a new release, and switches the site to it in one step. A push can deploy automatically; rolling back takes a click.</p>
			<p class="text-muted mb-0"><strong>Runtime:</strong> a site can run on Node.js or Python instead of PHP - Express, Next.js, Flask, Django, FastAPI.
				The app runs in the background as the account, restarts when it crashes and after every deploy, and nginx passes the domain's visitors to it.</p>
		</div>
	</div>
<?php endif; ?>

<?php foreach ($dep_sites as $dom):
	$d = $dep_deps[$dom] ?? null;
	$a = $dep_apps[$dom] ?? null;
	$acct = $d ? $d['account'] : $a['account'];
	$st = $a ? app_status($a) : null;
	$up = $st && $st['state'] === 'active';
	$edit = array('domain' => $dom, 'account' => $acct, 'git' => $d ? deploy_public($d) : null, 'app' => $a ? app_public($a) : null);
?>
	<div class="card mb-3 dep-site" data-domain="<?=h($dom)?>" data-dep="<?=$d ? (int)$d['id'] : 0?>" data-app="<?=$a ? (int)$a['id'] : 0?>">
		<div class="card-header">
			<h3 class="card-title"><a href="https://<?=h($dom)?>/" target="_blank" rel="noopener"><?=h($dom)?></a></h3>
			<span class="text-muted ms-2">account <?=h($acct)?></span>
			<button type="button" class="btn btn-sm ms-auto dep-edit" data-site="<?=h(json_encode($edit))?>">Settings</button>
		</div>
		<div class="card-body">
			<div class="row g-3">
				<div class="col-md-6"><div class="dep-part">
					<div class="dep-part-title">Code</div>
<?php if ($d): $rs = $d['run_status'] ?? ''; ?>
					<span class="dep-mono"><?=h(deploy_url_safe($d['repo_url']))?></span>
					<span class="dep-sub">branch <strong><?=h($d['branch'])?></strong><?=$d['public_dir'] !== '' ? ' &middot; serves '.h($d['public_dir']).'/' : ''?><?=(int)$d['auto_deploy'] ? '' : ' &middot; deploys on push off'?></span>
					<span class="dep-sub mt-1">
<?php if ($d['current_release'] !== ''): ?>
						live: <span class="dep-mono"><?=h(substr($d['current_commit'], 0, 8))?></span> from <?=h(date('Y-m-d H:i', strtotime($d['current_release'])))?>
<?php else: ?>
						not deployed yet
<?php endif; ?>
<?php if ($rs !== ''): ?>
						&middot; last deploy <span class="badge border <?=$dep_badge[$rs] ?? ''?>" title="<?=h($d['run_message'])?>"><?=h($rs)?></span> <?=h($dep_when($d['run_finished'] ?: $d['run_queued']))?>
<?php endif; ?>
					</span>
					<div class="btn-list">
						<button type="button" class="btn btn-primary btn-sm dep-run">Deploy</button>
						<button type="button" class="btn btn-sm dep-log" data-run="<?=(int)$d['last_run_id']?>"<?=(int)$d['last_run_id'] ? '' : ' disabled'?>>Deploy log</button>
						<button type="button" class="btn btn-sm dep-rollback"<?=$d['current_release'] !== '' ? '' : ' disabled'?>>Roll back</button>
						<button type="button" class="btn btn-sm dep-connect">Connect</button>
					</div>
<?php else: ?>
					<span>Files uploaded to the site</span>
					<span class="dep-sub"><?=$a ? h('~/'.substr(app_dir($a), strlen('/home/'.$a['account'].'/'))) : ''?> &middot; no Git deployment</span>
<?php endif; ?>
				</div></div>
				<div class="col-md-6"><div class="dep-part">
					<div class="dep-part-title">Runtime</div>
<?php if ($a): ?>
					<span><?=h($a['runtime'] === 'node' ? 'Node.js' : 'Python')?> <?=h($a['version'])?></span>
					<?php $crash = $st['state'] === 'activating' && $st['sub'] === 'auto-restart'; ?>
					<span class="badge border ms-1 <?=$crash ? 'bg-red-lt' : ($up && !$st['listening'] ? 'bg-yellow-lt' : ($app_badge[$st['state']] ?? ''))?>"><?=h($crash ? 'crashing, restarting' : ($up ? ($st['listening'] ? 'running' : 'not listening') : ((int)$a['enabled'] ? $st['state'] : 'stopped')))?></span>
					<span class="dep-sub"><?php if ($a['start_cmd'] === ''): ?><span class="text-muted">start command: found at the first deploy</span><?php else: ?><span class="dep-mono"><?=h($a['start_cmd'])?></span><?php endif; ?></span>
					<span class="dep-sub">port <?=(int)$a['port']?><?=$up && $st['memory_mb'] !== null ? ' · '.(int)$st['memory_mb'].' MB' : ''?><?=$st['restarts'] ? ' · '.(int)$st['restarts'].' restarts' : ''?></span>
<?php $ins = app_pkg_state($a); if ($ins): ?>
					<span class="dep-sub">packages <a href="#" class="app-install-log badge border <?=$app_pkg_badge[$ins['status']] ?? ''?>" title="<?=h($ins['message'])?>"><?=h($ins['status'] === 'running' ? 'installing' : $ins['status'])?></a> <?=h($dep_when($ins['finished'] ?: $ins['started']))?></span>
<?php endif; ?>
					<div class="btn-list">
<?php if ($up): ?>
						<button type="button" class="btn btn-sm app-op" data-op="restart">Restart</button>
						<button type="button" class="btn btn-sm app-op" data-op="stop">Stop</button>
<?php else: ?>
						<button type="button" class="btn btn-primary btn-sm app-op" data-op="start">Start</button>
<?php endif; ?>
						<button type="button" class="btn btn-sm app-logs">App log</button>
						<button type="button" class="btn btn-sm app-install" title="<?=h($a['runtime'] === 'node' ? 'npm ci (or npm install), then npm run build if there is one' : 'its .venv, then requirements.txt / pyproject.toml / setup.py')?>; restarts the app">Install packages</button>
						<button type="button" class="btn btn-sm app-env">Environment</button>
					</div>
<?php else: list($pv) = deploy_php($dom); ?>
					<span>PHP <?=h($pv)?></span>
					<span class="dep-sub">served by the site's PHP-FPM pool</span>
<?php endif; ?>
				</div></div>
			</div>
		</div>
	</div>
<?php endforeach; ?>

	<div class="card mb-3">
		<div class="card-header">
			<h3 class="card-title">Runtimes</h3>
			<span class="text-muted ms-2">Node.js and Python versions installed on this server, shared by all apps</span>
		</div>
		<div class="card-body">
<?php if (!$dep_front): ?>
			<p class="text-muted mb-0">Node.js / Python apps need nginx in front; this server runs Apache. Git deployments work for PHP sites.</p>
<?php else: ?>
<?php if (!$dep_rt): ?>
			<p class="text-muted">None yet. Install one to run apps.</p>
<?php else: ?>
			<div class="d-flex flex-wrap gap-2 mb-3">
<?php foreach ($dep_rt as $r): ?>
				<span class="badge border bg-azure-lt fs-5 py-2 px-3"><?=h($r['kind'] === 'node' ? 'Node.js' : 'Python')?> <?=h($r['full'])?>
<?php if ($r['kind'] === 'node'): ?>
					<a href="#" class="ms-2 rt-op" data-op="update" data-kind="node" data-version="<?=h($r['version'])?>" title="Update to the latest <?=h($r['version'])?>.x and restart its apps">update</a>
					<a href="#" class="ms-2 text-danger rt-op" data-op="remove" data-kind="node" data-version="<?=h($r['version'])?>" title="Remove">&times;</a>
<?php endif; ?>
				</span>
<?php endforeach; ?>
			</div>
<?php endif; ?>
			<div class="d-flex flex-wrap gap-2 align-items-center">
				<select class="form-select" id="rt-pick" style="max-width:22rem">
					<option value="">Install a runtime...</option>
					<optgroup label="Node.js (official builds, checksum verified)" id="rt-node"><option disabled>loading...</option></optgroup>
					<optgroup label="Python (OS packages)">
<?php foreach (array('3.12', '3.11') as $pyv): if (app_runtime_find('python', $pyv)) continue; ?>
						<option value="python:<?=$pyv?>">Python <?=$pyv?></option>
<?php endforeach; ?>
					</optgroup>
				</select>
				<button type="button" class="btn" id="rt-install" disabled>Install</button>
				<span class="text-muted" id="rt-msg"></span>
			</div>
<?php endif; ?>
		</div>
	</div>

<?php endif; ?>
</div>

<?php if ($dep_ok): ?>
<!-- new / settings: code (Git or uploaded files) + runtime (PHP or an app) -->
<div class="modal modal-blur fade" id="modal-dep-form" tabindex="-1" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered modal-lg" role="document">
		<div class="modal-content">
			<form method="post" action="/" autocomplete="off" id="dep-form">
				<input type="hidden" name="action" value="deploy-site-save">
				<div class="modal-header">
					<h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;" id="dep-form-title">New deployment</h5>
					<a href="https://www.reqad.com/docs/deployments#setup" target="_blank" rel="noopener" class="dep-doc" style="margin:0 1rem 18px auto;align-self:flex-end">Documentation<svg xmlns="http://www.w3.org/2000/svg" class="icon ms-1" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 6h-6a2 2 0 0 0 -2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-6" /><path d="M11 13l9 -9" /><path d="M15 4h5v5" /></svg></a>
					<button type="button" class="btn-close ms-0" data-bs-dismiss="modal" aria-label="Close"></button>
				</div>
				<div class="modal-body">
					<div class="row">
						<div class="col-md-6 mb-3" id="dep-domain-wrap">
							<label class="form-label required" for="dep-domain">Site</label>
							<select class="form-select" id="dep-domain" name="domain" required>
								<option value="" selected disabled>Choose a site...</option>
<?php foreach ($dep_domains as $acct => $doms): ?>
								<optgroup label="Account <?=h($acct)?>">
<?php foreach ($doms as $dom): ?>
									<option value="<?=h($dom)?>"><?=h($dom)?></option>
<?php endforeach; ?>
								</optgroup>
<?php endforeach; ?>
							</select>
						</div>
						<div class="col-md-6 mb-3" id="dep-domain-fixed" style="display:none">
							<label class="form-label">Site</label>
							<div class="form-control-plaintext fw-bold" id="dep-domain-name"></div>
							<input type="hidden" name="domain" id="dep-domain-hidden" disabled>
						</div>
					</div>
<?php
	/* presets: icon (Tabler brand icons), name, what it sets up */
	$dep_presets = array(
		'php'     => array('PHP', 'Composer', '#777bb4', '<path d="M2 12a10 9 0 1 0 20 0a10 9 0 1 0 -20 0" /><path d="M5.5 15l.395 -1.974l.605 -3.026h1.32a1 1 0 0 1 .986 1.164l-.167 1a1 1 0 0 1 -.986 .836h-1.653" /><path d="M15.5 15l.395 -1.974l.605 -3.026h1.32a1 1 0 0 1 .986 1.164l-.167 1a1 1 0 0 1 -.986 .836h-1.653" /><path d="M12 7.5l-1 5.5" /><path d="M11.6 10h2.4l-.5 3" />'),
		'laravel' => array('Laravel', 'artisan, public/', '#ff2d20', '<path d="M3 17l8 5l7 -4v-8l-4 -2.5l4 -2.5l4 2.5v4l-11 6.5l-4 -2.5v-7.5l-4 -2.5l0 11.5" /><path d="M11 18v4" /><path d="M7 15.5l7 -4" /><path d="M14 7.5v4" /><path d="M14 11.5l4 2.5" /><path d="M11 13v-7.5l-4 -2.5l-4 2.5" /><path d="M7 8l4 -2.5" /><path d="M18 10l4 -2.5" />'),
		'static'  => array('Static site', 'npm build, dist/', '#cb3837', '<path d="M1 8h22v7h-12v2h-4v-2h-6l0 -7" /><path d="M7 8v7" /><path d="M14 8v7" /><path d="M17 11v4" /><path d="M4 11v4" /><path d="M11 11v1" /><path d="M20 11v4" />'),
	);
	if ($dep_front)
		$dep_presets += array(
			'node'    => array('Node.js app', 'Express, Next.js...', '#5fa04e', '<path d="M9 9v8.044a2 2 0 0 1 -2.996 1.734l-1.568 -.9a3 3 0 0 1 -1.436 -2.561v-6.635a3 3 0 0 1 1.436 -2.56l6 -3.667a3 3 0 0 1 3.128 0l6 3.667a3 3 0 0 1 1.436 2.561v6.634a3 3 0 0 1 -1.436 2.56l-6 3.667a3 3 0 0 1 -3.128 0" /><path d="M17 9h-3.5a1.5 1.5 0 0 0 0 3h2a1.5 1.5 0 0 1 0 3h-3.5" />'),
			'python'  => array('Python app', 'Django, Flask...', '#3776ab', '<path d="M12 9h-7a2 2 0 0 0 -2 2v4a2 2 0 0 0 2 2h3" /><path d="M12 15h7a2 2 0 0 0 2 -2v-4a2 2 0 0 0 -2 -2h-3" /><path d="M8 9v-4a2 2 0 0 1 2 -2h4a2 2 0 0 1 2 2v5a2 2 0 0 1 -2 2h-4a2 2 0 0 0 -2 2v5a2 2 0 0 0 2 2h4a2 2 0 0 0 2 -2v-4" /><path d="M11 6l0 .01" /><path d="M13 18l0 .01" />'),
		);
?>
					<div class="mb-3">
						<label class="form-label">Start from <span class="text-muted fw-normal">- fills in the settings below, change anything after
							(<a href="https://www.reqad.com/docs/deployments#presets" target="_blank" rel="noopener">what each sets</a>)</span></label>
						<div class="dep-presets">
<?php foreach ($dep_presets as $key => $p): ?>
							<button type="button" class="dep-preset" data-preset="<?=$key?>" style="--dep-c:<?=$p[2]?>">
								<svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><?=$p[3]?></svg>
								<strong><?=h($p[0])?></strong>
								<small><?=h($p[1])?></small>
							</button>
<?php endforeach; ?>
						</div>
					</div>

					<!-- code -->
					<div class="dep-section">
						<label class="form-check form-switch mb-1">
							<input class="form-check-input" type="checkbox" name="git" value="1" id="dep-git">
							<span class="form-check-label fw-bold">Code from Git</span>
						</label>
						<small class="form-hint mb-2" id="dep-git-off-hint">Off: the site keeps the files uploaded to it.
							<a href="https://www.reqad.com/docs/deployments#how" target="_blank" rel="noopener">How Git deploys work</a></small>
						<div id="dep-git-fields" style="display:none">
							<div class="row mt-2">
								<div class="col-md-8 mb-3">
									<label class="form-label required" for="dep-repo">Repository</label>
									<input type="text" class="form-control dep-mono" id="dep-repo" name="repo_url" maxlength="500" placeholder="git@github.com:you/site.git">
									<small class="form-hint">SSH (read with this site's deploy key) or HTTPS for public repositories. The site's current files are moved aside on the first deploy.
										<a href="https://www.reqad.com/docs/deployments#settings" target="_blank" rel="noopener">All settings</a></small>
								</div>
								<div class="col-md-4 mb-3">
									<label class="form-label required" for="dep-branch">Branch</label>
									<input type="text" class="form-control dep-mono" id="dep-branch" name="branch" maxlength="100" value="main">
								</div>
							</div>
							<div class="mb-3">
								<label class="form-label" for="dep-build">Build commands</label>
								<textarea class="form-control dep-mono" id="dep-build" name="build_cmd" rows="3" maxlength="4000" placeholder="nothing to build"></textarea>
								<small class="form-hint">Run in the new release as the account, one per line, with the site's PHP / Node.js / Python first in <code>PATH</code>. The first that fails stops the deploy; the site stays as it was. A Node.js or Python app gets its packages first by itself (<code>package.json</code> with its build script, or a <code>.venv</code> + <code>requirements.txt</code> / <code>pyproject.toml</code>), unless these commands install them.</small>
							</div>
							<div class="row">
								<div class="col-md-6 mb-3">
									<label class="form-label" for="dep-shared">Shared between releases</label>
									<textarea class="form-control dep-mono" id="dep-shared" name="shared_paths" rows="3" placeholder=".env&#10;storage/&#10;wp-content/uploads/"></textarea>
									<small class="form-hint">One path per line; end folders with <code>/</code>.</small>
								</div>
								<div class="col-md-6">
									<div class="mb-3">
										<label class="form-label" for="dep-public">Public folder</label>
										<input type="text" class="form-control dep-mono" id="dep-public" name="public_dir" maxlength="200" placeholder="the repository's root">
									</div>
									<div class="row">
										<div class="col-6 mb-3">
											<label class="form-label" for="dep-check">Health check</label>
											<input type="text" class="form-control dep-mono" id="dep-check" name="health_path" maxlength="200" placeholder="none" title="A page like / that must answer after the switch, or the deploy goes back">
										</div>
										<div class="col-6 mb-3">
											<label class="form-label" for="dep-keep">Releases kept</label>
											<input type="number" class="form-control" id="dep-keep" name="keep_releases" min="2" max="20" value="5">
										</div>
									</div>
								</div>
							</div>
							<label class="form-check form-switch mb-0">
								<input class="form-check-input" type="checkbox" name="auto_deploy" value="1" id="dep-auto" checked>
								<span class="form-check-label">Deploy when a push to the branch is reported (webhook)</span>
							</label>
							<label class="form-check mt-2 mb-0 dep-only-edit" style="display:none">
								<input class="form-check-input" type="checkbox" name="new_secret" value="1">
								<span class="form-check-label">Make a new webhook secret <span class="text-muted">- the Git host must then get the new one</span></span>
							</label>
						</div>
					</div>

					<!-- runtime -->
					<div class="dep-section mt-3">
						<div class="row">
							<div class="col-md-6 mb-3">
								<label class="form-label fw-bold" for="dep-runtime">Runtime <a href="https://www.reqad.com/docs/apps" target="_blank" rel="noopener" class="fw-normal ms-1">Node.js / Python apps</a></label>
								<select class="form-select" id="dep-runtime" name="runtime">
									<option value="php">PHP (the site's PHP-FPM pool)</option>
<?php if ($dep_front): foreach ($dep_rt as $r): ?>
									<option value="<?=h($r['kind'].':'.$r['version'])?>"><?=h($r['kind'] === 'node' ? 'Node.js' : 'Python')?> <?=h($r['version'])?> (<?=h($r['full'])?>)</option>
<?php endforeach; endif; ?>
								</select>
<?php if ($dep_front && !$dep_rt): ?>
								<small class="form-hint">Install Node.js or Python under Runtimes to run apps.</small>
<?php endif; ?>
							</div>
						</div>
						<div id="dep-app-fields" style="display:none">
							<div class="mb-3">
								<label class="form-label" for="dep-start">Start command</label>
								<div class="btn-list mb-2" id="dep-start-found"></div>
								<div class="btn-list mb-2">
									<button type="button" class="btn btn-sm" data-start="node server.js">node server.js</button>
									<button type="button" class="btn btn-sm" data-start="npm start">npm start</button>
									<button type="button" class="btn btn-sm" data-start=".venv/bin/gunicorn -w 2 -b 127.0.0.1:$PORT app:app">Gunicorn</button>
									<button type="button" class="btn btn-sm" data-start=".venv/bin/uvicorn main:app --host 127.0.0.1 --port $PORT">Uvicorn</button>
								</div>
								<input type="text" class="form-control dep-mono" id="dep-start" name="start_cmd" maxlength="1000">
								<div class="small mt-1" id="dep-detect" role="status" style="display:none"><span id="dep-detect-msg"></span> <a href="#" id="dep-detect-again" class="ms-1">Look again</a></div>
								<ul class="small text-muted mt-1 mb-0 ps-3" id="dep-detect-notes" style="display:none"></ul>
								<small class="form-hint">Read from the site's code (as the account) when the form opens; with Git and no release yet, leave it empty and the first deploy finds it. Every request to the domain goes to the app (PHP is off for it). It must listen on <code>127.0.0.1</code> and the port in <code>$PORT</code>.
									Python: <code>.venv/bin</code> of the app folder comes first in <code>PATH</code>.</small>
							</div>
							<div class="row">
								<div class="col-md-6 mb-3">
									<label class="form-label" for="dep-appdir">App folder</label>
									<input type="text" class="form-control dep-mono" id="dep-appdir" name="app_dir" maxlength="200" placeholder="automatic">
									<small class="form-hint">Empty: the Git deployment's current release, else <code>apps/&lt;domain&gt;/app</code> in the account's home.</small>
								</div>
								<div class="col-md-3 mb-3">
									<label class="form-label" for="dep-mem">Memory (MB)</label>
									<input type="number" class="form-control" id="dep-mem" name="memory_mb" min="64" max="16384" value="512">
								</div>
							</div>
							<label class="form-check mb-0">
								<input class="form-check-input" type="checkbox" name="serve_static" value="1" id="dep-static">
								<span class="form-check-label">nginx serves files that exist in the document root first <span class="text-muted">- for a built public folder; never when it holds the app's source</span></span>
							</label>
						</div>
					</div>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-outline-danger me-auto" id="dep-remove" style="display:none">Remove</button>
					<button type="button" class="btn" data-bs-dismiss="modal">Cancel</button>
					<button type="submit" class="btn btn-primary" id="dep-submit">Create</button>
				</div>
			</form>
			<form method="post" action="/" id="dep-remove-form" class="d-none">
				<input type="hidden" name="action" value="deploy-site-remove">
				<input type="hidden" name="domain" value="">
			</form>
		</div>
	</div>
</div>

<!-- connect the repository -->
<div class="modal modal-blur fade" id="modal-dep-connect" tabindex="-1" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered modal-lg" role="document">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Connect <span id="dc-domain"></span></h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body">
				<ol class="ps-3">
					<li><strong>Read access.</strong> In the repository's settings, add this as a <em>deploy key</em> (read-only is enough).
						GitHub: Settings &gt; Deploy keys. GitLab: Settings &gt; Repository &gt; Deploy keys. Gitea: Settings &gt; Deploy Keys.
						<div class="input-group mt-2">
							<input type="text" class="form-control dep-mono" id="dc-key" readonly>
							<button type="button" class="btn" data-copy="#dc-key"><span>Copy</span></button>
						</div>
						<small class="text-muted d-none" id="dc-https">The repository address is HTTPS: a public repository needs no key. For a private one, use its SSH address instead.</small>
					</li>
					<li><strong>Deploys on push</strong> (optional). Add a webhook for push events, content type <code>application/json</code>, with this address and secret.
						GitLab: put the secret in <em>Secret token</em>.
						<div class="input-group mt-2">
							<span class="input-group-text">URL</span>
							<input type="text" class="form-control dep-mono" id="dc-url" readonly>
							<button type="button" class="btn" data-copy="#dc-url"><span>Copy</span></button>
						</div>
						<div class="input-group mt-2">
							<span class="input-group-text">Secret</span>
							<input type="text" class="form-control dep-mono" id="dc-secret" readonly>
							<button type="button" class="btn" data-copy="#dc-secret"><span>Copy</span></button>
						</div>
						<div class="alert alert-warning mt-2 mb-0 d-none" id="dc-nohook">This server does not receive webhooks yet: run
							<code>/usr/local/reqad/scripts/update/setup_deploy_webhook.sh</code> as root (needs nginx in front). From CI you can also call
							<code>POST /api/v1/deployments/&lt;id&gt;/deploy</code> with an <a href="/api-access/">API token</a>.</div>
					</li>
					<li><strong>Deploy.</strong> Press <em>Deploy</em> to put the branch live.</li>
				</ol>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
			</div>
		</div>
	</div>
</div>

<!-- logs (deploy: live; app: journal) -->
<div class="modal modal-blur fade" id="modal-dep-log" tabindex="-1" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered modal-xl" role="document">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;"><span id="dl-title"></span> <span class="badge border ms-2" id="dl-status"></span></h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body"><pre id="dep-log-text"></pre></div>
			<div class="modal-footer">
				<button type="button" class="btn me-auto" id="dl-refresh" style="display:none">Refresh</button>
				<span class="text-muted me-auto" id="dl-message"></span>
				<button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
			</div>
		</div>
	</div>
</div>

<!-- rollback -->
<div class="modal modal-blur fade" id="modal-dep-rollback" tabindex="-1" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered" role="document">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Roll back <span id="dr-domain"></span></h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body">
				<p class="text-muted">The site switches to the release you choose at once; nothing is rebuilt. The next deploy puts the branch live again.</p>
				<div id="dr-list" class="list-group"></div>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn" data-bs-dismiss="modal">Cancel</button>
				<button type="button" class="btn btn-primary" id="dr-go" disabled>Roll back</button>
			</div>
		</div>
	</div>
</div>

<!-- app environment -->
<div class="modal modal-blur fade" id="modal-app-env" tabindex="-1" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered modal-lg" role="document">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Environment of <span id="ae-domain"></span></h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body">
				<p class="text-muted">One <code>KEY=value</code> per line, read when the app starts: database passwords, API keys...
					Saved in <code id="ae-file"></code>, readable only by the account. Saving restarts the app.</p>
				<div id="ae-error"></div>
				<textarea class="form-control" id="app-env-text" rows="12" spellcheck="false"></textarea>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn" data-bs-dismiss="modal">Cancel</button>
				<button type="button" class="btn btn-primary" id="ae-save">Save and restart</button>
			</div>
		</div>
	</div>
</div>
<?php endif; ?>

<script>
/* jQuery and Bootstrap load in the footer */
document.addEventListener('DOMContentLoaded', function () {
	var $ = jQuery;
	['modal-dep-form', 'modal-dep-connect', 'modal-dep-log', 'modal-dep-rollback', 'modal-app-env'].forEach(function (id) {
		var m = document.getElementById(id);
		if (m) document.body.appendChild(m);     // outside the footer's unclosed div
	});
	var form = document.getElementById('dep-form');
	if (!form) return;
	var modal = function (id) { return bootstrap.Modal.getOrCreateInstance(document.getElementById(id)); };
	/* the CSRF header explicitly: the footer's ajaxSend hook is set up after
	   this handler, so requests made on page load would go without it */
	var post = function (data) {
		return $.ajax({ url: '/', method: 'POST', data: data, dataType: 'json', headers: { 'X-CSRF-Token': window.CSRF } });
	};
	var esc = function (s) { return $('<div>').text(s == null ? '' : String(s)).html(); };
	var badge = { ok: 'bg-green-lt', failed: 'bg-red-lt', running: 'bg-blue-lt', queued: 'bg-blue-lt', skipped: 'bg-muted-lt', warn: 'bg-yellow-lt' };
	var site = function (el) { var c = $(el).closest('.dep-site'); return { domain: c.data('domain'), dep: c.data('dep'), app: c.data('app') }; };
	var changed = false, editing = null;

	/* ---- new / settings ---- */
	var showParts = function () {
		var git = form.git.checked, rt = form.runtime.value;
		$('#dep-git-fields').toggle(git); $('#dep-git-off-hint').toggle(!git);
		$('#dep-app-fields').toggle(rt !== 'php');
		form.repo_url.required = git && !/unchanged/.test(form.repo_url.placeholder);
		form.branch.required = git;
		form.start_cmd.required = rt !== 'php' && !git;
	};
	$(form.git).on('change', showParts);
	$(form.runtime).on('change', showParts);

	/* the start command, read from the site's code (ajax-app-detect, as the account) */
	var detected = [], dSeq = 0, dTimer = null, cmdTouched = false;
	var detectSite = function () { return editing ? editing.domain : document.getElementById('dep-domain').value; };
	var foundButtons = function () {
		var k = form.runtime.value.split(':')[0], box = $('#dep-start-found').empty();
		detected.forEach(function (c) {
			if (c.kind !== k) return;
			$('<button type="button" class="btn btn-sm btn-outline-primary">').text(c.label).attr('title', c.cmd + '\n' + c.why)
				.on('click', function () { form.start_cmd.value = c.cmd; cmdTouched = true; }).appendTo(box);
		});
		box.toggle(!!box.children().length);
	};
	var detect = function () {
		clearTimeout(dTimer);
		var domain = detectSite(), rt = form.runtime.value, seq = ++dSeq;
		if (!domain || rt === 'php') { $('#dep-detect, #dep-detect-notes').hide(); return; }
		var notes = $('#dep-detect-notes').empty().hide();
		if (!editing && form.git.checked && !form.app_dir.value.trim()) {
			detected = []; foundButtons();
			$('#dep-detect-msg').text('The code comes with the first deploy: leave the start command empty and the deploy finds it.');
			$('#dep-detect').attr('class', 'small mt-1 text-muted').show();
			return;
		}
		$('#dep-detect-msg').text('Looking at the code...');
		$('#dep-detect').attr('class', 'small mt-1 text-muted').show();
		post({ action: 'ajax-app-detect', domain: domain, app_dir: form.app_dir.value.trim(), runtime: editing || cmdTouched ? rt.split(':')[0] : '' }).done(function (r) {
			if (seq !== dSeq) return;
			var d = r.data || {};
			detected = d.candidates || [];
			$('#dep-detect-msg').text(r.ok ? r.message : r.error);
			$('#dep-detect').attr('class', 'small mt-1 ' + (r.ok && d.found ? 'text-green' : (r.ok && (d.pending || d.exists === false || d.empty) ? 'text-muted' : 'text-orange')));
			(d.notes || []).forEach(function (n) { $('<li>').text(n).appendTo(notes); });
			notes.toggle(!!(d.notes || []).length);
			if (d.found && !editing && !cmdTouched) {
				if (d.runtime) {
					var v = d.runtime.kind + ':' + d.runtime.version;
					if ($(form.runtime).find('option[value="' + v + '"]').length) { form.runtime.value = v; showParts(); }
				}
				if (d.kind === form.runtime.value.split(':')[0]) form.start_cmd.value = d.start_cmd;
			}
			foundButtons();
		});
	};
	$(form.runtime).on('change', function () { foundButtons(); if (!detected.length) detect(); });
	$('#dep-domain').on('change', detect);
	$(form.git).on('change', detect);
	$(form.app_dir).on('input', function () { clearTimeout(dTimer); dTimer = setTimeout(detect, 600); });
	$(form.start_cmd).on('input', function () { cmdTouched = true; });
	$('#dep-detect-again').on('click', function (e) { e.preventDefault(); detect(); });
	var pickRuntime = function (kind) {
		var o = $(form.runtime).find('option').filter(function () { return this.value.indexOf(kind + ':') === 0; }).last();
		if (o.length) form.runtime.value = o.val();
		else alert('Install ' + (kind === 'node' ? 'Node.js' : 'Python') + ' under Runtimes first.');
	};
	var presets = {
		php:     function () { form.build_cmd.value = 'composer install --no-dev --optimize-autoloader'; form.shared_paths.value = '.env'; form.public_dir.value = ''; form.runtime.value = 'php'; },
		laravel: function () { form.build_cmd.value = 'composer install --no-dev --optimize-autoloader\nphp artisan migrate --force\nphp artisan config:cache\nphp artisan route:cache\nphp artisan view:cache';
		                       form.shared_paths.value = '.env\nstorage/'; form.public_dir.value = 'public'; form.runtime.value = 'php'; },
		static:  function () { form.build_cmd.value = 'npm ci\nnpm run build'; form.shared_paths.value = ''; form.public_dir.value = 'dist'; form.runtime.value = 'php'; },
		node:    function () { form.build_cmd.value = ''; form.shared_paths.value = '.env'; form.public_dir.value = '';
		                       pickRuntime('node'); form.start_cmd.value = ''; },
		python:  function () { form.build_cmd.value = ''; form.shared_paths.value = '.env'; form.public_dir.value = '';
		                       pickRuntime('python'); form.start_cmd.value = ''; }
	};
	$('[data-preset]').on('click', function () {
		$('.dep-preset').removeClass('active');
		$(this).addClass('active');
		presets[this.getAttribute('data-preset')]();
		form.git.checked = true;
		showParts(); detect();
	});
	$('[data-start]').on('click', function () { form.start_cmd.value = this.getAttribute('data-start'); cmdTouched = true; });

	var openForm = function (s) {
		form.reset();
		$('.dep-preset').removeClass('active');
		editing = s;
		var g = s ? s.git : null, a = s ? s.app : null;
		$('#dep-form-title').text(s ? 'Settings of ' + s.domain : 'New deployment');
		$('#dep-submit').text(s ? 'Save' : 'Create');
		$('#dep-domain-wrap').toggle(!s); $('#dep-domain-fixed').toggle(!!s); $('#dep-domain-name').text(s ? s.domain : '');
		document.getElementById('dep-domain').disabled = !!s;
		var hid = document.getElementById('dep-domain-hidden');
		hid.disabled = !s; hid.value = s ? s.domain : '';
		$('#dep-remove').toggle(!!s);
		$('.dep-only-edit').toggle(!!g);
		form.repo_url.placeholder = 'git@github.com:you/site.git';
		form.git.checked = !s || !!g;
		if (g) {
			var tok = g.repo_url.indexOf('***@') >= 0;
			form.repo_url.value = tok ? '' : g.repo_url;
			if (tok) form.repo_url.placeholder = 'unchanged (it has a token in it) - type a new one to replace it';
			form.branch.value = g.branch; form.public_dir.value = g.public_dir; form.build_cmd.value = g.build_cmd;
			form.shared_paths.value = g.shared_paths.join('\n'); form.health_path.value = g.health_path;
			form.keep_releases.value = g.keep_releases; form.auto_deploy.checked = g.auto_deploy;
		}
		if (a) {
			form.runtime.value = a.runtime + ':' + a.version; form.start_cmd.value = a.start_cmd;
			form.app_dir.value = a.app_dir; form.memory_mb.value = a.memory_mb; form.serve_static.checked = a.serve_static;
		}
		showParts();
		detected = []; cmdTouched = false; foundButtons();
		$('#dep-detect, #dep-detect-notes').hide();
		if (s) detect();
		modal('modal-dep-form').show();
	};
	$('#dep-new').on('click', function () { openForm(null); });
	$('.dep-edit').on('click', function () { openForm(JSON.parse(this.getAttribute('data-site'))); });
	form.addEventListener('submit', function (e) {
		var msgs = [];
		if (editing && editing.git && !form.git.checked) msgs.push('stop deploying from Git (the site keeps its current release)');
		if (editing && editing.app && form.runtime.value === 'php') msgs.push('remove the app (the domain goes back to PHP)');
		if (msgs.length && !confirm('This will ' + msgs.join(' and ') + '. Continue?')) e.preventDefault();
	});
	$('#dep-remove').on('click', function () {
		if (!confirm('Remove the deployment of ' + editing.domain + '?\n\nAny app is stopped and the domain goes back to PHP; Git deploys stop. The files stay where they are.'))
			return;
		var f = document.getElementById('dep-remove-form');
		f.domain.value = editing.domain;
		f.submit();
	});

	/* ---- connect ---- */
	var connect = function (id) {
		post({ action: 'ajax-deploy-connect', id: id }).done(function (r) {
			if (r.error) { alert(r.error); return; }
			$('#dc-domain').text(r.domain); $('#dc-key').val(r.public_key); $('#dc-https').toggleClass('d-none', r.ssh);
			$('#dc-url').val(r.webhook_url); $('#dc-secret').val(r.webhook_secret);
			$('#dc-nohook').toggleClass('d-none', r.webhook_ready);
			$('#modal-dep-connect [data-copy] span').text('Copy');
			modal('modal-dep-connect').show();
		});
	};
	$('.dep-connect').on('click', function () { connect(site(this).dep); });
	$('[data-copy]').on('click', function () {
		var b = this;
		navigator.clipboard.writeText($(b.getAttribute('data-copy')).val()).then(function () { $(b).find('span').text('Copied'); });
	});
<?php if ($dep_connect): ?>
	connect(<?=$dep_connect?>);
<?php endif; ?>

	/* ---- logs ---- */
	var timer = null, offset = 0, runId = 0, appId = 0;
	var pre = document.getElementById('dep-log-text');
	var poll = function () {
		post({ action: 'ajax-deploy-log', run: runId, offset: offset }).done(function (r) {
			if (r.error) { $('#dl-message').text(r.error); return; }
			var atEnd = pre.scrollTop + pre.clientHeight >= pre.scrollHeight - 30;
			if (r.text) { pre.appendChild(document.createTextNode(r.text)); if (atEnd) pre.scrollTop = pre.scrollHeight; }
			offset = r.next;
			$('#dl-status').attr('class', 'badge border ms-2 ' + (badge[r.status] || '')).text(r.status);
			$('#dl-message').text(r.message || '');
			if (r.status === 'queued' || r.status === 'running') timer = setTimeout(poll, 1200);
		});
	};
	var showDeployLog = function (run, domain) {
		clearTimeout(timer);
		runId = run; offset = 0; appId = 0;
		$(pre).text(''); $('#dl-title').text('Deploy of ' + domain); $('#dl-status').text('').attr('class', 'badge border ms-2');
		$('#dl-message').text('').show(); $('#dl-refresh').hide();
		modal('modal-dep-log').show();
		poll();
	};
	var loadAppLog = function () {
		$(pre).text('Loading...');
		post({ action: 'ajax-app-logs', id: appId }).done(function (x) {
			pre.textContent = x.text || '(no output yet)';
			pre.scrollTop = pre.scrollHeight;
		});
	};
	document.getElementById('modal-dep-log').addEventListener('hidden.bs.modal', function () {
		clearTimeout(timer);
		if (changed) location.reload();
	});
	$('.dep-log').on('click', function () { showDeployLog(this.getAttribute('data-run'), site(this).domain); });
	$('.app-logs').on('click', function () {
		clearTimeout(timer);
		appId = site(this).app;
		$('#dl-title').text('App log of ' + site(this).domain); $('#dl-status').text('').attr('class', 'd-none');
		$('#dl-message').hide(); $('#dl-refresh').show();
		modal('modal-dep-log').show();
		loadAppLog();
	});
	$('#dl-refresh').on('click', loadAppLog);

	/* ---- Install packages: same window, its own output ---- */
	var insId = 0;
	var pollInstall = function () {
		post({ action: 'ajax-app-install-log', id: insId, offset: offset }).done(function (r) {
			if (r.error) { $('#dl-message').text(r.error); return; }
			var atEnd = pre.scrollTop + pre.clientHeight >= pre.scrollHeight - 30;
			if (r.text) { pre.appendChild(document.createTextNode(r.text)); if (atEnd) pre.scrollTop = pre.scrollHeight; }
			offset = r.next;
			var s = r.install ? r.install.status : '';
			$('#dl-status').attr('class', 'badge border ms-2 ' + (badge[s] || '')).text(s === 'running' ? 'installing' : s);
			$('#dl-message').text(r.install ? r.install.message || '' : '');
			if (s === 'running') timer = setTimeout(pollInstall, 1200);
		});
	};
	var showInstall = function (s) {
		clearTimeout(timer);
		insId = s.app; offset = 0; runId = 0; appId = 0;
		$(pre).text(''); $('#dl-title').text('Packages of ' + s.domain); $('#dl-status').text('').attr('class', 'badge border ms-2');
		$('#dl-message').text('').show(); $('#dl-refresh').hide();
		modal('modal-dep-log').show();
		pollInstall();
	};
	$('.app-install').on('click', function () {
		var b = this, s = site(b);
		b.disabled = true;
		post({ action: 'ajax-app-install', id: s.app }).done(function (r) {
			b.disabled = false;
			if (!r.ok) { alert(r.error); return; }
			changed = true;
			showInstall(s);
		}).fail(function () { b.disabled = false; alert('Could not reach the panel.'); });
	});
	$('.app-install-log').on('click', function (e) { e.preventDefault(); showInstall(site(this)); });
	$('.dep-run').on('click', function () {
		var b = this, s = site(b);
		b.disabled = true;
		post({ action: 'ajax-deploy-run', id: s.dep }).done(function (r) {
			b.disabled = false;
			if (!r.ok) { alert(r.error); return; }
			changed = true;
			showDeployLog(r.data.run, s.domain);
		}).fail(function () { b.disabled = false; alert('Could not reach the panel.'); });
	});

	/* ---- rollback ---- */
	var rbId = 0, rbDomain = '';
	$('.dep-rollback').on('click', function () {
		var s = site(this);
		rbId = s.dep; rbDomain = s.domain;
		$('#dr-domain').text(s.domain);
		$('#dr-list').html('<div class="text-muted">Loading...</div>');
		$('#dr-go').prop('disabled', true);
		modal('modal-dep-rollback').show();
		post({ action: 'ajax-deploy-releases', id: rbId }).done(function (r) {
			if (r.error) { $('#dr-list').text(r.error); return; }
			var html = '';
			r.releases.forEach(function (x) {
				var cur = x.release === r.current, t = x.release.replace(/^(\d{4})(\d\d)(\d\d)(\d\d)(\d\d)(\d\d)$/, '$1-$2-$3 $4:$5');
				html += '<label class="list-group-item d-flex gap-2' + (cur ? ' text-muted' : '') + '"><input class="form-check-input mt-1" type="radio" name="dr-rel" value="'
					+ esc(x.release) + '"' + (cur ? ' disabled' : '') + '><span><strong>' + esc(t) + '</strong> <span class="dep-mono">' + esc((x.commit || '').substr(0, 8))
					+ '</span>' + (cur ? ' <span class="badge border bg-green-lt ms-1">live</span>' : '') + '<span class="dep-sub">' + esc(x.message || '') + '</span></span></label>';
			});
			$('#dr-list').html(html || '<div class="text-muted">No releases on disk.</div>');
			$('#dr-list input').on('change', function () { $('#dr-go').prop('disabled', false); });
		});
	});
	$('#dr-go').on('click', function () {
		var rel = $('#dr-list input:checked').val();
		$('#dr-go').prop('disabled', true);
		post({ action: 'ajax-deploy-rollback', id: rbId, release: rel }).done(function (r) {
			modal('modal-dep-rollback').hide();
			if (!r.ok) { alert(r.error); return; }
			changed = true;
			showDeployLog(r.data.run, rbDomain);
		});
	});

	/* ---- app: start / stop / restart, environment ---- */
	$('.app-op').on('click', function () {
		var b = this;
		b.disabled = true;
		post({ action: 'ajax-app-control', id: site(b).app, op: b.getAttribute('data-op') }).always(function (x) {
			if (x && x.ok === false) alert(x.error);
			else if (x && x.message && /nothing answers/.test(x.message)) alert(x.message);
			location.reload();
		});
	});
	var envId = 0;
	$('.app-env').on('click', function () {
		var s = site(this);
		envId = s.app; $('#ae-domain').text(s.domain); $('#ae-error').html(''); $('#app-env-text').val('');
		post({ action: 'ajax-app-env', id: envId }).done(function (x) {
			$('#app-env-text').val(x.text || ''); $('#ae-file').text(x.file);
			modal('modal-app-env').show();
		});
	});
	$('#ae-save').on('click', function () {
		var b = this;
		b.disabled = true;
		post({ action: 'ajax-app-env-save', id: envId, text: $('#app-env-text').val() }).done(function (x) {
			b.disabled = false;
			if (!x.ok) { $('#ae-error').html('<div class="alert alert-danger py-2">' + esc(x.error) + '</div>'); return; }
			modal('modal-app-env').hide();
			location.reload();
		}).fail(function () { b.disabled = false; });
	});

	/* ---- runtimes ---- */
	var installed = <?=json_encode(array_values(array_map(function ($r) { return $r['kind'].':'.$r['version']; }, $dep_rt)))?>;
	if (document.getElementById('rt-pick')) {
		post({ action: 'ajax-app-available' }).done(function (x) {
			var g = $('#rt-node').empty();
			(x.node || []).forEach(function (n) {
				if (installed.indexOf('node:' + n.major) >= 0) return;
				g.append($('<option>').val('node:' + n.major).text('Node.js ' + n.major + ' (' + n.latest + (n.lts ? ', LTS ' + n.lts : '') + ')'));
			});
			if (!g.children().length) g.append('<option disabled>nothing more to install</option>');
		}).fail(function () { $('#rt-node').html('<option disabled>nodejs.org did not answer</option>'); });
	}
	$('#rt-pick').on('change', function () { $('#rt-install').prop('disabled', !this.value); });
	var rtRun = function (op, kind, version, btn) {
		$('#rt-msg').text((op === 'remove' ? 'Removing' : op === 'update' ? 'Updating' : 'Installing') + ' ' + kind + ' ' + version + '...');
		if (btn) btn.disabled = true;
		post({ action: 'ajax-app-runtime', op: op, kind: kind, version: version }).done(function (x) {
			if (!x.ok) { $('#rt-msg').text(x.error); if (btn) btn.disabled = false; return; }
			location.reload();
		}).fail(function () { $('#rt-msg').text('No answer from the panel.'); if (btn) btn.disabled = false; });
	};
	$('#rt-install').on('click', function () { var v = $('#rt-pick').val().split(':'); rtRun('install', v[0], v[1], this); });
	$('.rt-op').on('click', function (e) {
		e.preventDefault();
		var op = this.getAttribute('data-op'), k = this.getAttribute('data-kind'), v = this.getAttribute('data-version');
		if (op === 'remove' && !confirm('Remove Node.js ' + v + ' from the server?')) return;
		rtRun(op, k, v, null);
	});
});
</script>

<?php include('templates/footer.php'); ?>
