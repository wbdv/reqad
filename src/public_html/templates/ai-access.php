<?php
	/* Settings > AI access: the MCP switch, the tokens assistants use, their
	   latest tool calls and the tools. Actions: modules/ai_access_actions.php;
	   the MCP server: app/mcp/mcp.php (`reqad mcp serve`). */
	$ai_ok     = mcp_ready();
	$ai_on     = $ai_ok && mcp_enabled();
	$ai_tokens = $ai_ok ? api_tokens_list(false, 'mcp') : array();
	$ai_calls_n = isset($_GET['calls']) && ctype_digit((string)$_GET['calls']) ? min(1000, max(20, (int)$_GET['calls'])) : 20;
	$ai_calls  = $ai_ok ? api_log_rows($ai_calls_n + 1, 0, 'mcp') : array();
	$ai_more   = count($ai_calls) > $ai_calls_n;
	$ai_calls  = array_slice($ai_calls, 0, $ai_calls_n);
	$ai_new    = isset($ai_new_token) && is_array($ai_new_token) ? $ai_new_token : null;
	$ai_tools  = $ai_ok ? mcp_tool_routes() : array();
	$ai_time   = function ($ts) { return (int)$ts ? date('Y-m-d H:i', (int)$ts) : '-'; };
	$ai_ago    = function ($ts) {
		$d = time() - (int)$ts;
		if ($d < 90)    return 'just now';
		if ($d < 3600)  return floor($d / 60).' min ago';
		if ($d < 86400) return floor($d / 3600).' h ago';
		return floor($d / 86400).' days ago';
	};
	$ai_perm_badge = array('read' => 'bg-azure-lt', 'write' => 'bg-yellow-lt', 'delete' => 'bg-red-lt');
	/* the settings an assistant needs, per token (the token file never changes) */
	$ai_clients = array();
	foreach ($ai_tokens as $t)
		if ($t['state'] === 'active')
			$ai_clients[(int)$t['id']] = mcp_client_config(mcp_token_file($t['id'])) + array('token' => $t['name']);
	if ($ai_new)
		$ai_clients[(int)$ai_new['id']] = $ai_new['client'] + array('token' => $ai_new['name']);
	include('templates/header.php');
?>
        <div class="page-header d-print-none">
            <div class="row align-items-center">
              <div class="col" style="padding-left:22px;">
                <div class="page-pretitle">Settings</div>
                <h2 class="page-title">AI access</h2>
              </div>
              <div class="col-auto ms-auto">
                <a href="https://www.reqad.com/docs/mcp" target="_blank" rel="noopener" class="btn">
                  <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 19a9 9 0 0 1 9 0a9 9 0 0 1 9 0" /><path d="M3 6a9 9 0 0 1 9 0a9 9 0 0 1 9 0" /><path d="M3 6l0 13" /><path d="M12 6l0 13" /><path d="M21 6l0 13" /></svg>
                  Documentation
                </a>
              </div>
            </div>
        </div>

<?php msg_render(); ?>

<style>
	#ai-access .set-row { padding: 1.25rem 0; border-top: 1px solid rgba(98,105,118,.16); }
	#ai-access .set-row:first-child { border-top: 0; padding-top: 0; }
	#ai-access .set-label { font-weight: 600; padding-top: 2px; }
	#ai-access .set-hint { display: block; color: #656d77; font-size: 90%; margin-top: 4px; font-weight: 400; }
	#ai-access .table td, #ai-access .table th { vertical-align: middle; }
	#ai-access .ai-steps { margin: .5rem 0 0; padding-left: 1.2rem; color: #656d77; font-size: 90%; }
	#ai-access .ai-steps li { margin-bottom: 2px; }
	.ai-code { font-family: monospace; font-size: .85rem; white-space: pre; overflow-x: auto; background: rgba(98,105,118,.06);
	           border: 1px solid rgba(98,105,118,.18); border-radius: 4px; padding: .6rem .75rem; margin: 0; max-height: 18rem; }
	.ai-code.ai-wrap { white-space: pre-wrap; overflow-wrap: anywhere; padding-right: 4.5rem; }
	.ai-copy-wrap { position: relative; }
	.ai-variant { white-space: normal; }
	.ai-copy-wrap .btn { position: absolute; top: 6px; right: 6px; }
</style>

<div id="ai-access">

<?php if (!$ai_ok): ?>
	<div class="alert alert-warning">The AI access tables are missing. Run the Reqad update (database 1045) first.</div>
<?php else: ?>

	<div class="card mb-3">
	<div class="card-body">

		<div class="row set-row">
			<div class="col-md-3 set-label">AI assistants (MCP)
				<span class="set-hint">Claude, Cursor and other MCP clients manage this server for you.</span>
			</div>
			<div class="col-md-9">
				<form method="post" action="/" id="ai-toggle" class="mb-1"<?=$ai_on ? '' : ' data-sudo'?>>
					<input type="hidden" name="action" value="ai-access-toggle">
					<input type="hidden" name="op" value="<?=$ai_on ? 'off' : 'on'?>">
					<label class="form-check form-switch d-inline-flex align-items-center mb-0" style="cursor:pointer">
						<input class="form-check-input state-toggle" type="checkbox" style="margin-right:10px"<?=$ai_on ? ' checked' : ''?>>
						<span class="form-check-label<?=$ai_on ? '' : ' text-red'?>">AI access</span>
						<span class="badge border ms-2 <?=$ai_on ? 'bg-green-lt' : 'bg-red-lt'?>"><?=$ai_on ? 'Active' : 'Disabled'?></span>
					</label>
				</form>
				<span class="set-hint">The assistant logs in to the server over SSH as root, with your SSH key, and runs
					<code>reqad mcp serve</code>. No port is opened for it, and the panel's IP restriction and the REST API switch do not matter.
					A token decides what it may do: read only, or also create, change and delete, for the whole server or for one hosting account.</span>
				<ol class="ai-steps">
					<li>Make a token below and copy the settings it shows into your assistant.</li>
					<li>The computer running the assistant needs an SSH key that logs in to <code>root@<?=h(gethostname())?></code> without a prompt.
						Add its public key for <strong>root</strong> under <a href="/ssh-keys/">SSH Keys</a>.</li>
					<li>Ask, for example: <em>"What needs attention on my server?"</em>, <em>"Why does mail to gmail.com bounce?"</em>, <em>"Create a mailbox office@example.com."</em></li>
				</ol>
				<span class="set-hint">The assistant gets the operations of the <a href="/api-access/">REST API</a> as tools, never a shell. Deleting needs a second call
					after it has asked you, and every call is listed below.</span>
			</div>
		</div>

	</div>
	</div>

	<div class="card mb-3">
		<div class="card-header">
			<h3 class="card-title">Tokens</h3>
			<button type="button" class="btn btn-primary ms-auto" data-bs-toggle="modal" data-bs-target="#modal-ai-token">New token</button>
		</div>
<?php if (!$ai_tokens): ?>
		<div class="card-body text-muted">No tokens yet. A token is for the whole server or for one hosting account, can only read or also change and
			delete, and expires after 7 to 90 days.</div>
<?php else: ?>
		<div class="table-responsive">
			<table class="table card-table table-vcenter">
				<thead><tr><th>Name</th><th>For</th><th>Permissions</th><th>SSH from</th><th>Expires</th><th>Last used</th><th></th></tr></thead>
				<tbody>
<?php foreach ($ai_tokens as $t): ?>
				<tr<?=$t['state'] !== 'active' ? ' class="text-muted"' : ''?>>
					<td><?=h($t['name'])?><?php if ($t['created_by'] !== ''): ?><span class="set-hint mt-0">by <?=h($t['created_by'])?>, <?=h($ai_time($t['created']))?></span><?php endif; ?></td>
					<td><?=$t['account'] !== '' ? '<span class="badge border bg-blue-lt">'.h($t['account']).'</span>' : 'Whole server'?></td>
					<td><?php foreach (api_token_scopes($t) as $s): ?><span class="badge border <?=$ai_perm_badge[$s]?> me-1"><?=h($s)?></span><?php endforeach; ?></td>
					<td><?=$t['allow_ips'] !== '' ? h(str_replace(',', ', ', $t['allow_ips'])) : '<span class="text-muted">anywhere</span>'?></td>
					<td><?php if ($t['state'] === 'expired'): ?><span class="badge border bg-red-lt">expired</span><?php else: ?><?=h(date('Y-m-d', (int)$t['expires']))?><?php endif; ?></td>
					<td><?=(int)$t['last_used'] ? h($ai_ago($t['last_used'])).'<span class="set-hint mt-0">'.h($t['last_ip']).'</span>' : '<span class="text-muted">never</span>'?></td>
					<td class="text-end text-nowrap">
<?php if (isset($ai_clients[(int)$t['id']])): ?>
						<button type="button" class="btn ai-connect" data-token="<?=(int)$t['id']?>">Connect</button>
<?php endif; ?>
						<button type="button" class="btn" data-bs-toggle="modal" data-bs-target="#modal-ai-revoke" data-token="<?=(int)$t['id']?>" data-name="<?=h($t['name'])?>">Revoke</button>
					</td>
				</tr>
<?php endforeach; ?>
				</tbody>
			</table>
		</div>
<?php endif; ?>
	</div>

	<div class="card mb-3" id="ai-calls">
		<div class="card-header">
			<h3 class="card-title">Latest tool calls</h3>
			<span class="text-muted ms-2">kept 30 days</span>
		</div>
<?php if (!$ai_calls): ?>
		<div class="card-body text-muted">No calls yet.</div>
<?php else: ?>
		<div class="table-responsive">
			<table class="table card-table table-vcenter">
				<thead><tr><th>Time</th><th>Token</th><th>From</th><th>Tool</th><th>Result</th><th class="text-end">Took</th></tr></thead>
				<tbody>
<?php foreach ($ai_calls as $c): $st = (int)$c['status']; ?>
				<tr>
					<td><?=h(date('Y-m-d H:i:s', (int)$c['ts']))?></td>
					<td><?=h($c['token_name'] ?? '#'.$c['token_id'])?></td>
					<td><?=h($c['ip'])?></td>
					<td><code><?=h($c['path'])?></code></td>
					<td><span class="badge border <?=$st < 300 ? 'bg-green-lt' : ($st < 500 ? 'bg-yellow-lt' : 'bg-red-lt')?>"><?=$st === 202 ? 'asked to confirm' : ($st < 300 ? 'done' : 'refused '.$st)?></span></td>
					<td class="text-end text-muted"><?=(int)$c['ms']?> ms</td>
				</tr>
<?php endforeach; ?>
				</tbody>
			</table>
		</div>
<?php if ($ai_more): ?>
		<div class="card-footer text-center"><a href="/ai-access/?calls=<?=$ai_calls_n + 100?>#ai-calls">Show more</a></div>
<?php endif; ?>
<?php endif; ?>
	</div>

	<div class="card mb-3">
		<div class="card-header">
			<h3 class="card-title">Tools</h3>
			<span class="text-muted ms-2">what assistants can do; each token sees only the tools its permissions allow</span>
		</div>
		<div class="table-responsive">
			<table class="table card-table table-vcenter">
				<thead><tr><th>Tool</th><th>Permission</th><th>What it does</th></tr></thead>
				<tbody>
<?php foreach ($ai_tools as $name => $r): ?>
				<tr>
					<td class="text-nowrap"><code><?=h($name)?></code></td>
					<td class="text-nowrap"><span class="badge border <?=$ai_perm_badge[$r['perm']] ?? ''?>"><?=h($r['perm'])?></span><?php if (!empty($r['admin'])): ?> <span class="badge border bg-muted-lt" title="Not with a token for one account">server</span><?php endif; ?></td>
					<td><?=h(mcp_tool_description($r))?></td>
				</tr>
<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>

	<div class="modal modal-blur fade" id="modal-ai-token" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered" style="max-width:640px" role="document">
			<div class="modal-content">
				<form method="post" action="/" autocomplete="off" data-sudo>
					<input type="hidden" name="action" value="ai-access-token-create">
					<div class="modal-header">
						<h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">New AI access token</h5>
						<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
					</div>
					<div class="modal-body">
						<div class="mb-3">
							<label class="form-label required" for="ai-name">Name</label>
							<input type="text" class="form-control" id="ai-name" name="name" maxlength="60" required pattern="[A-Za-z0-9][A-Za-z0-9 ._@\-]*" placeholder="e.g. Claude laptop">
							<small class="form-hint">Which assistant on which computer, so you know what to revoke.</small>
						</div>
						<div class="mb-3">
							<label class="form-label" for="ai-account">For</label>
							<select class="form-select" id="ai-account" name="account">
								<option value="">The whole server</option>
<?php foreach (account_list($db) as $a): ?>
								<option value="<?=h($a['user'])?>">Account <?=h($a['user'])?> (<?=h($a['domain'])?>)</option>
<?php endforeach; ?>
							</select>
							<small class="form-hint">With one account, the assistant sees and changes only what that account owns, and none of the server's own tools.</small>
						</div>
						<div class="mb-3">
							<label class="form-label">Permissions</label>
							<label class="form-check"><input class="form-check-input" type="checkbox" checked disabled><span class="form-check-label">Read <span class="text-muted perm-hint" data-server="status, health, lists, logs, mail queue, WordPress" data-account="its databases, mailboxes and WordPress sites, web and PHP logs, health">- status, health, lists, logs, mail queue, WordPress</span></span></label>
							<label class="form-check"><input class="form-check-input" type="checkbox" name="perm_write" value="1"><span class="form-check-label">Create and change <span class="text-muted perm-hint" data-server="accounts, databases, mailboxes, WordPress updates, service restarts, deploys" data-account="its password and PHP version, databases, mailboxes, WordPress updates, deploys">- accounts, databases, mailboxes, WordPress updates, service restarts, deploys</span></span></label>
							<label class="form-check mb-0"><input class="form-check-input" type="checkbox" name="perm_delete" value="1"><span class="form-check-label">Delete <span class="text-muted perm-hint" data-server="accounts, databases, mailboxes, queued mail; each after a second, confirmed call" data-account="databases, mailboxes; each after a second, confirmed call">- accounts, databases, mailboxes, queued mail; each after a second, confirmed call</span></span></label>
						</div>
						<div class="row">
							<div class="col-sm-5 mb-3">
								<label class="form-label" for="ai-days">Expires after</label>
								<select class="form-select" id="ai-days" name="days">
<?php foreach (mcp_expiry_choices() as $d => $label): ?>
									<option value="<?=$d?>"<?=$d === 30 ? ' selected' : ''?>><?=h($label)?></option>
<?php endforeach; ?>
								</select>
							</div>
							<div class="col-sm-7 mb-3">
								<label class="form-label" for="ai-ips">SSH from</label>
								<input type="text" class="form-control" id="ai-ips" name="allow_ips" maxlength="500" placeholder="any address">
								<small class="form-hint">Optional: the addresses the assistant's computer connects from, comma separated.</small>
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

	<div class="modal modal-blur fade" id="modal-ai-connect" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered modal-lg" role="document">
			<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Connect an assistant</h5>
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
				</div>
				<div class="modal-body">
					<p class="mb-3">Token <strong id="ai-connect-name"></strong>. Its secret stays on this server (<code id="ai-connect-file"></code>, readable by root only),
						so the settings below hold none: the SSH key is what lets the assistant in.</p>
					<div class="btn-group w-100 mb-3" role="group" aria-label="SSH client">
						<button type="button" class="btn ai-variant" data-variant="ssh">Linux, macOS (OpenSSH)</button>
						<button type="button" class="btn ai-variant" data-variant="plink">Windows (PuTTY / Pageant)</button>
					</div>
					<label class="form-label">1. Test the SSH login<span class="ai-v-plink"> and accept the server's key</span></label>
					<div class="ai-copy-wrap"><pre class="ai-code ai-wrap" id="ai-connect-test"></pre><button type="button" class="btn btn-sm" data-copy="#ai-connect-test"><span>Copy</span></button></div>
					<small class="form-hint mt-1 mb-3 ai-v-ssh">It must print <code>mcp: on</code> without asking anything. If it asks for a password or passphrase, add your key to the SSH agent first.
						The server must know the key: add its public key for <strong>root</strong> under <a href="/ssh-keys/">SSH Keys</a>.</small>
					<small class="form-hint mt-1 mb-3 ai-v-plink">Run it once in a Command Prompt with your key loaded in Pageant: answer <strong>y</strong> when plink asks about the server's host key.
						It must then print <code>mcp: on</code> without asking anything else. <code>plink</code> comes with PuTTY; Windows' own <code>ssh</code> does not use Pageant.
						The server must know the key: add its public key (PuTTYgen shows it in OpenSSH format) for <strong>root</strong> under <a href="/ssh-keys/">SSH Keys</a>.</small>
					<label class="form-label">2. Claude Code - run on the computer with the assistant</label>
					<div class="ai-copy-wrap mb-3"><pre class="ai-code ai-wrap" id="ai-connect-cc"></pre><button type="button" class="btn btn-sm" data-copy="#ai-connect-cc"><span>Copy</span></button></div>
					<label class="form-label">Or: Claude Desktop, Cursor and other MCP clients - add to their JSON settings</label>
					<div class="ai-copy-wrap"><pre class="ai-code" id="ai-connect-json"></pre><button type="button" class="btn btn-sm" data-copy="#ai-connect-json"><span>Copy</span></button></div>
					<small class="form-hint mt-2 ai-v-ssh">Desktop apps start <code>ssh</code> without your terminal's SSH agent on some systems: add
						<code>"-i", "/path/to/key"</code> to the args if it does not connect.</small>
					<small class="form-hint mt-2 ai-v-plink">If <code>plink</code> is not found, use its full path, e.g. <code>"C:\\Program Files\\PuTTY\\plink.exe"</code> in the JSON
						(<code>"C:\Program Files\PuTTY\plink.exe"</code> in the Claude Code command, in quotes).</small>
<?php if (!$ai_on): ?>
					<div class="alert alert-warning mt-3 mb-0">AI access is off. Turn it on at the top of this page before connecting.</div>
<?php endif; ?>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
				</div>
			</div>
		</div>
	</div>

	<form method="post" action="/" id="ai-revoke-form">
		<input type="hidden" name="action" value="ai-access-token-revoke">
		<input type="hidden" name="token" id="ai-revoke-id" value="">
		<div class="modal modal-blur fade" id="modal-ai-revoke" tabindex="-1" role="dialog" aria-hidden="true">
			<div class="modal-dialog modal-sm modal-dialog-centered" role="document">
				<div class="modal-content">
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
					<div class="modal-status bg-danger"></div>
					<div class="modal-body text-center py-4">
						<svg xmlns="http://www.w3.org/2000/svg" class="icon mb-2 text-danger icon-lg" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="#ff2825" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="12" cy="12" r="9" /><line x1="12" y1="8" x2="12" y2="12" /><line x1="12" y1="16" x2="12.01" y2="16" /></svg>
						<h3>Revoke token <span id="ai-revoke-name"></span></h3>
						<div class="text-muted">Assistants using it stop working at once, also in a running session, and its file on the server is deleted.</div>
					</div>
					<div class="modal-footer">
						<div class="w-100">
							<div class="row">
								<div class="col"><a href="#" class="btn w-100" data-bs-dismiss="modal">Cancel</a></div>
								<div class="col"><button type="submit" class="btn btn-danger w-100" id="ai-revoke-submit">Revoke</button></div>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</form>

<?php endif; /* mcp_ready */ ?>
</div>

<script>
(function () {
	var clients = <?=json_encode((object)$ai_clients, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP)?>;
	var fresh = <?=$ai_new ? (int)$ai_new['id'] : 0?>;

	/* New token: the permission lines say what they cover for the chosen "For" */
	var forSel = document.getElementById('ai-account');
	var permHints = function () {
		var acct = forSel && forSel.value !== '';
		document.querySelectorAll('#modal-ai-token .perm-hint').forEach(function (h) {
			h.textContent = '- ' + h.getAttribute(acct ? 'data-account' : 'data-server');
		});
	};
	if (forSel) forSel.addEventListener('change', permHints);
	document.getElementById('modal-ai-token').addEventListener('show.bs.modal', permHints);

	/* the modals live outside the footer's unclosed div */
	['ai-revoke-form', 'modal-ai-token', 'modal-ai-connect'].forEach(function (id) {
		var m = document.getElementById(id);
		if (m) document.body.appendChild(m);
	});

	/* OpenSSH or PuTTY's plink (Windows with the key in Pageant); the choice is remembered */
	var variant = /Win/.test(navigator.platform || navigator.userAgent) ? 'plink' : 'ssh', current = null;
	try { variant = localStorage.getItem('reqad-ai-variant') || variant; } catch (e) {}
	var showVariant = function (v) {
		variant = v;
		try { localStorage.setItem('reqad-ai-variant', v); } catch (e) {}
		document.querySelectorAll('.ai-variant').forEach(function (b) {
			b.classList.toggle('btn-primary', b.getAttribute('data-variant') === v);
		});
		document.querySelectorAll('.ai-v-ssh').forEach(function (el) { el.style.display = v === 'ssh' ? '' : 'none'; });
		document.querySelectorAll('.ai-v-plink').forEach(function (el) { el.style.display = v === 'plink' ? '' : 'none'; });
		if (!current) return;
		var p = v === 'plink';
		document.getElementById('ai-connect-test').textContent = p ? current.plink_test : current.test;
		document.getElementById('ai-connect-cc').textContent = p ? current.plink_claude_code : current.claude_code;
		document.getElementById('ai-connect-json').textContent = p ? current.plink_json : current.json;
		document.querySelectorAll('#modal-ai-connect [data-copy] span').forEach(function (s) { s.textContent = 'Copy'; });
	};
	document.querySelectorAll('.ai-variant').forEach(function (b) {
		b.addEventListener('click', function () { showVariant(b.getAttribute('data-variant')); });
	});

	var connect = function (id) {
		var c = clients[id];
		if (!c) return;
		document.getElementById('ai-connect-name').textContent = c.token;
		document.getElementById('ai-connect-file').textContent = c.ssh.replace(/^.*--token-file=/, '');
		current = c;
		showVariant(variant);
		document.querySelectorAll('#modal-ai-connect [data-copy] span').forEach(function (s) { s.textContent = 'Copy'; });
		bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-ai-connect')).show();
	};
	document.querySelectorAll('.ai-connect').forEach(function (b) {
		b.addEventListener('click', function () { connect(b.getAttribute('data-token')); });
	});
	if (fresh)
		document.addEventListener('DOMContentLoaded', function () { connect(fresh); });

	/* "Confirm it's you" may open on Create token: one dialog at a time - the
	   token dialog steps aside and comes back if the confirmation is cancelled */
	var tokenModal = document.getElementById('modal-ai-token'), reopen = false, sent = false;
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
			var el = document.querySelector(b.getAttribute('data-copy'));
			navigator.clipboard.writeText(el.value !== undefined ? el.value : el.textContent).then(function () { b.querySelector('span').textContent = 'Copied'; });
		});
	});

	/* Revoke: confirmation dialog, one form for every row */
	var revokeModal = document.getElementById('modal-ai-revoke');
	if (revokeModal) {
		revokeModal.addEventListener('show.bs.modal', function (e) {
			var b = e.relatedTarget;
			document.getElementById('ai-revoke-id').value = b.getAttribute('data-token');
			document.getElementById('ai-revoke-name').textContent = '"' + b.getAttribute('data-name') + '"';
		});
		document.getElementById('ai-revoke-form').addEventListener('submit', function () {
			document.getElementById('ai-revoke-submit').disabled = true;
		});
	}

	/* the switch submits its form at once; turning it on may open the
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
