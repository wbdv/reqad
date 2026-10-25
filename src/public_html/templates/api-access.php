<?php
	/* Settings > API access: the REST API switch, its tokens and the latest
	   calls. Actions: modules/api_access_actions.php; the API itself: app/api/. */
	$api_ok     = api_ready();
	$api_on     = $api_ok && api_enabled();
	$api_tokens = $api_ok ? api_tokens_list() : array();
	$api_calls_n = isset($_GET['calls']) && ctype_digit((string)$_GET['calls']) ? min(1000, max(20, (int)$_GET['calls'])) : 20;
	$api_calls  = $api_ok ? api_log_rows($api_calls_n + 1) : array();
	$api_more   = count($api_calls) > $api_calls_n;
	$api_calls  = array_slice($api_calls, 0, $api_calls_n);
	$api_new    = isset($api_new_token) && is_array($api_new_token) ? $api_new_token : null;
	$api_nginx  = trim((string)shell_exec("sudo grep -rls --include='*.conf' 'reqad-api BEGIN' /etc/reqad/conf.d 2>/dev/null")) !== '';
	$api_fw     = auth_ready() ? auth_fw_status() : array();
	$api_time   = function ($ts) { return (int)$ts ? date('Y-m-d H:i', (int)$ts) : '-'; };
	$api_ago    = function ($ts) {
		$d = time() - (int)$ts;
		if ($d < 90)    return 'just now';
		if ($d < 3600)  return floor($d / 60).' min ago';
		if ($d < 86400) return floor($d / 3600).' h ago';
		return floor($d / 86400).' days ago';
	};
	$api_perm_badge = array('read' => 'bg-azure-lt', 'write' => 'bg-yellow-lt', 'delete' => 'bg-red-lt');
	include('templates/header.php');
?>
        <div class="page-header d-print-none">
            <div class="row align-items-center">
              <div class="col" style="padding-left:22px;">
                <div class="page-pretitle">Settings</div>
                <h2 class="page-title">API access</h2>
              </div>
            </div>
        </div>

<?php msg_render(); ?>

<style>
	#api-access .set-row { padding: 1.25rem 0; border-top: 1px solid rgba(98,105,118,.16); }
	#api-access .set-row:first-child { border-top: 0; padding-top: 0; }
	#api-access .set-label { font-weight: 600; padding-top: 2px; }
	#api-access .set-hint { display: block; color: #656d77; font-size: 90%; margin-top: 4px; font-weight: 400; }
	#api-access .table td, #api-access .table th { vertical-align: middle; }
	#api-access .api-method { display: inline-block; min-width: 4.2rem; font-family: monospace; font-weight: 600; }
	.api-token-value { font-family: monospace; font-size: .95rem; }
</style>

<div id="api-access">

<?php if (!$api_ok): ?>
	<div class="alert alert-warning">The API tables are missing. Run the Reqad update (database 1042) first.</div>
<?php else: ?>

<?php if ($api_new): $api_curl = 'curl -H "Authorization: Bearer '.$api_new['token'].'" '.api_url(); ?>
	<div class="modal modal-blur fade" id="modal-api-new-token" tabindex="-1" data-bs-backdrop="static" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered modal-lg" role="document">
			<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Your new API token</h5>
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
				</div>
				<div class="modal-body">
					<p>Token <strong><?=h($api_new['name'])?></strong> - <strong>it is shown only now</strong>. Store it where the program that uses it can read it,
						for example in your password manager or the program's secret settings. If you lose it, revoke it and make a new one.</p>
					<div class="input-group mb-3">
						<input type="text" class="form-control api-token-value" id="api-token-value" value="<?=h($api_new['token'])?>" readonly>
						<button type="button" class="btn" data-copy="#api-token-value"><span>Copy</span></button>
					</div>
					<label class="form-label">Try it</label>
					<div class="input-group">
						<input type="text" class="form-control api-token-value" id="api-token-curl" value="<?=h($api_curl)?>" readonly>
						<button type="button" class="btn" data-copy="#api-token-curl"><span>Copy</span></button>
					</div>
<?php if (!$api_on): ?>
					<div class="alert alert-warning mt-3 mb-0">The API is off. Turn it on at the top of this page before using the token.</div>
<?php endif; ?>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
				</div>
			</div>
		</div>
	</div>
<?php endif; ?>

	<div class="card mb-3">
	<div class="card-body">

		<div class="row set-row">
			<div class="col-md-3 set-label">REST API
				<span class="set-hint">Scripts and programs manage this server over HTTPS with a token.</span>
			</div>
			<div class="col-md-9">
				<form method="post" action="/" id="api-toggle" class="mb-1"<?=$api_on ? '' : ' data-sudo'?>>
					<input type="hidden" name="action" value="api-access-toggle">
					<input type="hidden" name="op" value="<?=$api_on ? 'off' : 'on'?>">
					<label class="form-check form-switch d-inline-flex align-items-center mb-0" style="cursor:pointer">
						<input class="form-check-input state-toggle" type="checkbox" style="margin-right:10px"<?=$api_on ? ' checked' : ''?>>
						<span class="form-check-label<?=$api_on ? '' : ' text-red'?>">API</span>
						<span class="badge border ms-2 <?=$api_on ? 'bg-green-lt' : 'bg-red-lt'?>"><?=$api_on ? 'Active' : 'Disabled'?></span>
					</label>
				</form>
				<span class="set-hint">Address: <code><?=h(api_url())?></code>. Every request sends a token in the header
					<code>Authorization: Bearer &lt;token&gt;</code>; the panel login is not used.
					A wrong token counts as a failed login: <?=auth_cfg('auth-lock-failures')?> lock the address out for <?=auth_cfg('auth-lock-minutes')?> minutes,
					and more block it in the firewall. While the API is off, every request is refused and the tokens are kept.
					See the <a href="https://www.reqad.com/docs/api" target="_blank" rel="noopener">API documentation</a>.</span>
<?php if (!$api_nginx): ?>
				<div class="alert alert-warning mt-2 mb-0">The panel's web server does not route <code>/api/</code> yet. Run
					<code>/usr/local/reqad/scripts/update/setup_panel_api.sh</code> as root (the Reqad update does it).</div>
<?php endif; ?>
<?php if (!empty($api_fw['on'])): ?>
				<div class="alert alert-info mt-2 mb-0">The panel's IP restriction is on (Settings &gt; <a href="/security/#firewall">Security</a>):
					port 2087 answers only the addresses on that list, so add the addresses your scripts call from.</div>
<?php endif; ?>
			</div>
		</div>

	</div>
	</div>

	<div class="card mb-3">
		<div class="card-header">
			<h3 class="card-title">Tokens</h3>
			<button type="button" class="btn btn-primary ms-auto" data-bs-toggle="modal" data-bs-target="#modal-api-token">New token</button>
		</div>
<?php if (!$api_tokens): ?>
		<div class="card-body text-muted">No tokens yet. A token can be for the whole server or for one hosting account, can only read
			or also change and delete, and can be limited to some addresses.</div>
<?php else: ?>
		<div class="table-responsive">
			<table class="table card-table table-vcenter">
				<thead><tr><th>Name</th><th>Token</th><th>For</th><th>Permissions</th><th>Allowed from</th><th>Expires</th><th>Last used</th><th></th></tr></thead>
				<tbody>
<?php foreach ($api_tokens as $t): ?>
				<tr<?=$t['state'] !== 'active' ? ' class="text-muted"' : ''?>>
					<td><?=h($t['name'])?><?php if ($t['created_by'] !== ''): ?><span class="set-hint mt-0">by <?=h($t['created_by'])?>, <?=h($api_time($t['created']))?></span><?php endif; ?></td>
					<td><code><?=h($t['prefix'])?>&hellip;</code></td>
					<td><?=$t['account'] !== '' ? '<span class="badge border bg-blue-lt">'.h($t['account']).'</span>' : 'Whole server'?></td>
					<td><?php foreach (api_token_scopes($t) as $s): ?><span class="badge border <?=$api_perm_badge[$s]?> me-1"><?=h($s)?></span><?php endforeach; ?></td>
					<td><?=$t['allow_ips'] !== '' ? h(str_replace(',', ', ', $t['allow_ips'])) : '<span class="text-muted">anywhere</span>'?></td>
					<td><?php if ($t['state'] === 'expired'): ?><span class="badge border bg-red-lt">expired</span><?php else: ?><?=(int)$t['expires'] ? h(date('Y-m-d', (int)$t['expires'])) : 'never'?><?php endif; ?></td>
					<td><?=(int)$t['last_used'] ? h($api_ago($t['last_used'])).'<span class="set-hint mt-0">'.h($t['last_ip']).'</span>' : '<span class="text-muted">never</span>'?></td>
					<td class="text-end">
						<button type="button" class="btn" data-bs-toggle="modal" data-bs-target="#modal-api-revoke" data-token="<?=(int)$t['id']?>" data-name="<?=h($t['name'])?>">Revoke</button>
					</td>
				</tr>
<?php endforeach; ?>
				</tbody>
			</table>
		</div>
<?php endif; ?>
	</div>

	<div class="card mb-3" id="api-calls">
		<div class="card-header">
			<h3 class="card-title">Latest calls</h3>
			<span class="text-muted ms-2">kept 30 days</span>
		</div>
<?php if (!$api_calls): ?>
		<div class="card-body text-muted">No calls yet.</div>
<?php else: ?>
		<div class="table-responsive">
			<table class="table card-table table-vcenter">
				<thead><tr><th>Time</th><th>Token</th><th>From</th><th>Request</th><th>Status</th><th class="text-end">Took</th></tr></thead>
				<tbody>
<?php foreach ($api_calls as $c): $st = (int)$c['status']; ?>
				<tr>
					<td><?=h(date('Y-m-d H:i:s', (int)$c['ts']))?></td>
					<td><?=h($c['token_name'] ?? '#'.$c['token_id'])?></td>
					<td><?=h($c['ip'])?></td>
					<td><span class="api-method"><?=h($c['method'])?></span> <code>/api<?=h($c['path'])?></code></td>
					<td><span class="badge border <?=$st < 300 ? 'bg-green-lt' : ($st < 500 ? 'bg-yellow-lt' : 'bg-red-lt')?>"><?=$st?></span></td>
					<td class="text-end text-muted"><?=(int)$c['ms']?> ms</td>
				</tr>
<?php endforeach; ?>
				</tbody>
			</table>
		</div>
<?php if ($api_more): ?>
		<div class="card-footer text-center"><a href="/api-access/?calls=<?=$api_calls_n + 100?>#api-calls">Show more</a></div>
<?php endif; ?>
<?php endif; ?>
	</div>

	<div class="card mb-3">
		<div class="card-header">
			<h3 class="card-title">Endpoints</h3>
			<span class="text-muted ms-2">JSON in and out; <code>GET /api/v1/</code> returns this list</span>
		</div>
		<div class="table-responsive">
			<table class="table card-table table-vcenter">
				<thead><tr><th>Request</th><th>Permission</th><th>What it does</th></tr></thead>
				<tbody>
<?php foreach (api_routes() as $r): if (!empty($r['feature']) && !feature_enabled($ini, $r['feature'])) continue; ?>
				<tr>
					<td class="text-nowrap"><span class="api-method"><?=h($r['method'])?></span> <code>/api<?=h($r['path'])?></code></td>
					<td><span class="badge border <?=$api_perm_badge[$r['perm']] ?? ''?>"><?=h($r['perm'])?></span><?php if (!empty($r['admin'])): ?> <span class="badge border bg-muted-lt" title="Not with a token for one account">server</span><?php endif; ?></td>
					<td><?=h($r['summary'] ?? '')?><?php if (!empty($r['body'])): ?><span class="set-hint mt-0">Body: <?=h($r['body'])?></span><?php endif; ?></td>
				</tr>
<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>

	<div class="modal modal-blur fade" id="modal-api-token" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered" style="max-width:640px" role="document">
			<div class="modal-content">
				<form method="post" action="/" autocomplete="off" data-sudo>
					<input type="hidden" name="action" value="api-access-token-create">
					<div class="modal-header">
						<h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">New API token</h5>
						<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
					</div>
					<div class="modal-body">
						<div class="mb-3">
							<label class="form-label required" for="api-name">Name</label>
							<input type="text" class="form-control" id="api-name" name="name" maxlength="60" required pattern="[A-Za-z0-9][A-Za-z0-9 ._@\-]*" placeholder="e.g. monitoring, billing">
							<small class="form-hint">What uses it, so you know what breaks when you revoke it.</small>
						</div>
						<div class="mb-3">
							<label class="form-label" for="api-account">For</label>
							<select class="form-select" id="api-account" name="account">
								<option value="">The whole server</option>
<?php foreach (account_list($db) as $a): ?>
								<option value="<?=h($a['user'])?>">Account <?=h($a['user'])?> (<?=h($a['domain'])?>)</option>
<?php endforeach; ?>
							</select>
							<small class="form-hint">A token for one account sees and changes only what that account owns, and cannot create or delete accounts.</small>
						</div>
						<div class="mb-3">
							<label class="form-label">Permissions</label>
							<label class="form-check"><input class="form-check-input" type="checkbox" checked disabled><span class="form-check-label">Read <span class="text-muted perm-hint" data-server="status, accounts, databases, mailboxes, mail queue, logs, health, WordPress" data-account="its databases, mailboxes and WordPress sites, web and PHP logs, health">- status, accounts, databases, mailboxes, mail queue, logs, health, WordPress</span></span></label>
							<label class="form-check"><input class="form-check-input" type="checkbox" name="perm_write" value="1"><span class="form-check-label">Create and change <span class="text-muted perm-hint" data-server="accounts, databases, mailboxes, WordPress updates, service restarts, deploys" data-account="its password and PHP version, databases, mailboxes, WordPress updates, deploys">- accounts, databases, mailboxes, WordPress updates, service restarts, deploys</span></span></label>
							<label class="form-check mb-0"><input class="form-check-input" type="checkbox" name="perm_delete" value="1"><span class="form-check-label">Delete <span class="text-muted perm-hint" data-server="accounts, databases, mailboxes, queued mail" data-account="databases, mailboxes">- accounts, databases, mailboxes, queued mail</span></span></label>
						</div>
						<div class="row">
							<div class="col-sm-5 mb-3">
								<label class="form-label" for="api-days">Expires after</label>
								<select class="form-select" id="api-days" name="days">
<?php foreach (api_expiry_choices() as $d => $label): ?>
									<option value="<?=$d?>"<?=$d === 90 ? ' selected' : ''?>><?=h($label)?></option>
<?php endforeach; ?>
								</select>
							</div>
							<div class="col-sm-7 mb-3">
								<label class="form-label" for="api-ips">Allowed from</label>
								<input type="text" class="form-control" id="api-ips" name="allow_ips" maxlength="500" placeholder="any address">
								<small class="form-hint">Optional: addresses or networks, comma separated - e.g. 203.0.113.7, 198.51.100.0/24.</small>
							</div>
						</div>
					</div>
					<div class="modal-footer">
						<button type="button" class="btn me-auto" data-bs-dismiss="modal">Cancel</button>
						<button type="submit" class="btn btn-primary">Create token</button>
					</div>
				</form>
			</div>
		</div>
	</div>

	<form method="post" action="/" id="api-revoke-form">
		<input type="hidden" name="action" value="api-access-token-revoke">
		<input type="hidden" name="token" id="api-revoke-id" value="">
		<div class="modal modal-blur fade" id="modal-api-revoke" tabindex="-1" role="dialog" aria-hidden="true">
			<div class="modal-dialog modal-sm modal-dialog-centered" role="document">
				<div class="modal-content">
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
					<div class="modal-status bg-danger"></div>
					<div class="modal-body text-center py-4">
						<svg xmlns="http://www.w3.org/2000/svg" class="icon mb-2 text-danger icon-lg" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="#ff2825" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="12" cy="12" r="9" /><line x1="12" y1="8" x2="12" y2="12" /><line x1="12" y1="16" x2="12.01" y2="16" /></svg>
						<h3>Revoke token <span id="api-revoke-name"></span></h3>
						<div class="text-muted">Programs using it are refused from the next request on. This cannot be undone: make a new token if you need one again.</div>
					</div>
					<div class="modal-footer">
						<div class="w-100">
							<div class="row">
								<div class="col"><a href="#" class="btn w-100" data-bs-dismiss="modal">Cancel</a></div>
								<div class="col"><button type="submit" class="btn btn-danger w-100" id="api-revoke-submit">Revoke</button></div>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</form>

<?php endif; /* api_ready */ ?>
</div>

<script>
(function () {
	/* New token: the permission lines say what they cover for the chosen "For" */
	var forSel = document.getElementById('api-account');
	var permHints = function () {
		var acct = forSel && forSel.value !== '';
		document.querySelectorAll('#modal-api-token .perm-hint').forEach(function (h) {
			h.textContent = '- ' + h.getAttribute(acct ? 'data-account' : 'data-server');
		});
	};
	if (forSel) forSel.addEventListener('change', permHints);
	document.getElementById('modal-api-token').addEventListener('show.bs.modal', permHints);

	/* the modals live outside the footer's unclosed div */
	['api-revoke-form', 'modal-api-token', 'modal-api-new-token'].forEach(function (id) {
		var m = document.getElementById(id);
		if (m) document.body.appendChild(m);
	});
	var fresh = document.getElementById('modal-api-new-token');
	if (fresh)
		document.addEventListener('DOMContentLoaded', function () { bootstrap.Modal.getOrCreateInstance(fresh).show(); });

	/* "Confirm it's you" may open on Create token: one dialog at a time - the
	   token dialog steps aside and comes back if the confirmation is cancelled */
	var tokenModal = document.getElementById('modal-api-token'), reopen = false, sent = false;
	if (tokenModal) {
		tokenModal.querySelector('form').addEventListener('submit', function () { sent = true; });
		document.addEventListener('show.bs.modal', function (e) {
			if (e.target.id === 'modal-sudo' && tokenModal.classList.contains('show')) {
				reopen = true;
				bootstrap.Modal.getOrCreateInstance(tokenModal).hide();
			}
		});
		document.addEventListener('hidden.bs.modal', function (e) {
			if (e.target.id === 'modal-sudo' && reopen && !sent)
				bootstrap.Modal.getOrCreateInstance(tokenModal).show();
			if (e.target.id === 'modal-sudo')
				reopen = false;
		});
	}

	document.querySelectorAll('[data-copy]').forEach(function (b) {
		b.addEventListener('click', function () {
			var input = document.querySelector(b.getAttribute('data-copy'));
			navigator.clipboard.writeText(input.value).then(function () { b.querySelector('span').textContent = 'Copied'; });
		});
	});

	/* Revoke: confirmation dialog, one form for every row */
	var revokeModal = document.getElementById('modal-api-revoke');
	if (revokeModal) {
		revokeModal.addEventListener('show.bs.modal', function (e) {
			var b = e.relatedTarget;
			document.getElementById('api-revoke-id').value = b.getAttribute('data-token');
			document.getElementById('api-revoke-name').textContent = '"' + b.getAttribute('data-name') + '"';
		});
		document.getElementById('api-revoke-form').addEventListener('submit', function () {
			document.getElementById('api-revoke-submit').disabled = true;
		});
	}

	/* the switch submits its form at once; turning the API on may open the
	   confirmation dialog first, and cancelling it puts the switch back */
	document.querySelectorAll('.state-toggle').forEach(function (sw) {
		var form = sw.form, orig = sw.checked, sent = false;
		form.addEventListener('submit', function () { sent = true; });
		sw.addEventListener('change', function () {
			var b = form.querySelector('.badge'), l = form.querySelector('.form-check-label');
			b.textContent = sw.checked ? 'Active' : 'Disabled';
			b.classList.toggle('bg-green-lt', sw.checked); b.classList.toggle('bg-red-lt', !sw.checked);
			l.classList.toggle('text-red', !sw.checked);
			if (form.requestSubmit) form.requestSubmit(); else form.submit();
		});
		document.addEventListener('hidden.bs.modal', function (e) {
			if (e.target.id === 'modal-sudo' && !sent && sw.checked !== orig) { sw.checked = orig; location.reload(); }
		});
	});
})();
</script>

<?php
    include('templates/sudo-modal.php');
    include('templates/footer.php');
?>
