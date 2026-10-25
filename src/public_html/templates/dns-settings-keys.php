<?php
	/* Settings > DNS Settings, tab API keys (included by templates/dns-settings.php):
	   other servers keep their DNS zones on this server's PowerDNS, each key
	   seeing only its own zones. The switch, the keys and their zones, the
	   latest calls. Actions: modules/dns_keys_actions.php; the API itself:
	   app/functions/dns_keys.php (served by app/api/pdns.php). Script: dist/js/dns-settings.js. */
	$dk_ok     = dns_api_ready();
	$dk_avail  = dns_api_available();
	$dk_on     = $dk_ok && dns_api_enabled();
	$dk_keys   = $dk_ok ? dns_keys_list() : array();
	$dk_active = array_values(array_filter($dk_keys, function ($k) { return $k['state'] === 'active'; }));
	$dk_calls_n = isset($_GET['calls']) && ctype_digit((string)$_GET['calls']) ? min(1000, max(20, (int)$_GET['calls'])) : 20;
	$dk_calls  = $dk_ok ? api_log_rows($dk_calls_n + 1, 0, 'dns') : array();
	$dk_more   = count($dk_calls) > $dk_calls_n;
	$dk_calls  = array_slice($dk_calls, 0, $dk_calls_n);
	$dk_new    = isset($dns_new_key) && is_array($dns_new_key) ? $dns_new_key : null;
	$dk_nginx  = trim((string)shell_exec("sudo grep -rls --include='*.conf' 'location ^~ /pdns/' /etc/reqad/conf.d 2>/dev/null")) !== '';
	$dk_fw     = auth_ready() ? auth_fw_status() : array();
	$dk_ns     = $dk_avail ? dns_expected_nameservers() : array();
	$dk_url    = dns_api_url();
	$dk_time   = function ($ts) { return (int)$ts ? date('Y-m-d H:i', (int)$ts) : '-'; };
	$dk_ago    = function ($ts) {
		$d = time() - (int)$ts;
		if ($d < 90)    return 'just now';
		if ($d < 3600)  return floor($d / 60).' min ago';
		if ($d < 86400) return floor($d / 3600).' h ago';
		return floor($d / 86400).' days ago';
	};
	$dk_perm = array('read' => array('bg-azure-lt', 'read'), 'write' => array('bg-yellow-lt', 'create & change'), 'delete' => array('bg-red-lt', 'remove zones'));
?>

<style>
	#dns-keys details summary { cursor: pointer; font-weight: 600; color: #206bc4; }
	#dns-keys .dk-steps { margin: 0; padding-left: 1.2rem; }
	#dns-keys .dk-steps li { margin-bottom: 6px; }
	#dns-keys .api-method { display: inline-block; min-width: 4.2rem; font-family: monospace; font-weight: 600; }
	#dns-keys tr.dk-target td { background: #f1f6fd; }
	.dk-mono { font-family: monospace; font-size: .95rem; }
	.dk-zone-list { max-height: 280px; overflow-y: auto; border: 1px solid rgba(98,105,118,.16); border-radius: 4px; }
	.dk-zone-list .dk-zone { display: flex; align-items: center; gap: 8px; padding: 5px 10px; border-top: 1px solid rgba(98,105,118,.1); }
	.dk-zone-list .dk-zone:first-child { border-top: 0; }
	.dk-zone-list .dk-zone a.dk-z { font-family: monospace; color: inherit; }
	.dk-zone-list .dk-zone form { margin-left: auto; }
	.dk-conn th { white-space: nowrap; font-weight: 600; width: 1%; padding-right: 14px; }
</style>

<div id="dns-keys">

<?php if (!$dk_ok): ?>
	<div class="alert alert-warning">The DNS API tables are missing. Run the Reqad update (database 1055) first.</div>
<?php else: ?>

<?php if ($dk_new): $dk_curl = 'curl -H "X-API-Key: '.$dk_new['key'].'" '.$dk_new['url'].'/api/v1/servers/localhost/zones'; ?>
	<div class="modal modal-blur fade" id="dk-m-new" tabindex="-1" data-bs-backdrop="static" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered modal-lg" role="document">
			<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Your new DNS API key</h5>
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
				</div>
				<div class="modal-body">
					<p>Key <strong><?=h($dk_new['name'])?></strong> - <strong>it is shown only now</strong>. If you lose it, revoke it, make a new one and move its zones to the new key.</p>
					<div class="input-group mb-3">
						<input type="text" class="form-control dk-mono" id="dk-new-key" value="<?=h($dk_new['key'])?>" readonly>
						<button type="button" class="btn" data-copy="#dk-new-key"><span>Copy</span></button>
					</div>
					<label class="form-label">On the other Reqad server: Settings &gt; DNS Settings &gt; Provider &gt; PowerDNS</label>
					<table class="table table-sm dk-conn mb-3">
						<tr><th>Server address</th><td><code><?=h($dk_new['url'])?></code></td></tr>
						<tr><th>API Key</th><td>the key above</td></tr>
						<tr><th>This server's name servers</th><td><?=$dk_ns ? '<code>'.implode('</code>, <code>', array_map('h', $dk_ns)).'</code> (the same as here)' : '<span class="text-danger">none set here yet - set them on the Provider tab first</span>'?></td></tr>
					</table>
					<label class="form-label">Try it</label>
					<div class="input-group">
						<input type="text" class="form-control dk-mono" id="dk-new-curl" value="<?=h($dk_curl)?>" readonly>
						<button type="button" class="btn" data-copy="#dk-new-curl"><span>Copy</span></button>
					</div>
<?php if ($dk_new['zones']): ?>
					<div class="alert alert-success mt-3 mb-0">It holds <?=count($dk_new['zones']) === 1 ? 'the zone '.h($dk_new['zones'][0]) : count($dk_new['zones']).' zones'?> already.</div>
<?php endif; ?>
<?php foreach ($dk_new['warnings'] as $w): ?>
					<div class="alert alert-warning mt-3 mb-0"><?=h($w)?></div>
<?php endforeach; ?>
<?php if (!$dk_on): ?>
					<div class="alert alert-warning mt-3 mb-0">The DNS API is off. Turn it on at the top of this page before using the key.</div>
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
			<div class="col-md-3 set-label">DNS API
				<span class="set-hint">Other servers keep their DNS zones on this server, each with its own key, and see only their own zones.</span>
			</div>
			<div class="col-md-9">
				<form method="post" action="/" id="dk-toggle" class="mb-1"<?=$dk_on ? '' : ' data-sudo'?>>
					<input type="hidden" name="action" value="dns-keys-toggle">
					<input type="hidden" name="op" value="<?=$dk_on ? 'off' : 'on'?>">
					<label class="form-check form-switch d-inline-flex align-items-center mb-0" style="cursor:pointer">
						<input class="form-check-input state-toggle" type="checkbox" style="margin-right:10px"<?=$dk_on ? ' checked' : ''?><?=!$dk_avail && !$dk_on ? ' disabled' : ''?>>
						<span class="form-check-label<?=$dk_on ? '' : ' text-red'?>">DNS API</span>
						<span class="badge border ms-2 <?=$dk_on ? 'bg-green-lt' : 'bg-red-lt'?>"><?=$dk_on ? 'Active' : 'Disabled'?></span>
					</label>
				</form>
				<span class="set-hint">Address: <code><?=h($dk_url)?></code>. It answers like PowerDNS's own API, so a program that works with
					PowerDNS works here too, with a key from this page in the header <code>X-API-Key</code>. A wrong key counts as a failed login:
					<?=auth_cfg('auth-lock-failures')?> lock the address out for <?=auth_cfg('auth-lock-minutes')?> minutes, and more block it in the firewall.
					While the API is off, every request is refused; keys and zones are kept.</span>
<?php if (!$dk_avail): ?>
				<div class="alert alert-warning mt-2 mb-0">The DNS API shares this server's PowerDNS: choose PowerDNS on the <a href="/dns-settings/">Provider</a> tab first.</div>
<?php elseif (!$dk_ns): ?>
				<div class="alert alert-warning mt-2 mb-0">Set this server's name servers on the <a href="/dns-settings/">Provider</a> tab first: every zone made through the API gets them, and other servers cannot make zones until they are set.</div>
<?php endif; ?>
<?php if (!$dk_nginx): ?>
				<div class="alert alert-warning mt-2 mb-0">The panel's web server does not route <code>/pdns/</code> yet. Run
					<code>/usr/local/reqad/scripts/update/setup_panel_api.sh</code> as root (the Reqad update does it).</div>
<?php endif; ?>
<?php if (!empty($dk_fw['on'])): ?>
				<div class="alert alert-info mt-2 mb-0">The panel's IP restriction is on (Settings &gt; <a href="/security/#firewall">Security</a>):
					port 2087 answers only the addresses on that list, so add the addresses of the servers that use these keys.</div>
<?php endif; ?>
				<details class="mt-3">
					<summary>Connect another Reqad server</summary>
					<ol class="dk-steps mt-2">
						<li>Make a key below for it, allowed only from its address.</li>
						<li>On that server, Settings &gt; DNS Settings &gt; Provider &gt; PowerDNS: <strong>Server address</strong> <code><?=h($dk_url)?></code>, <strong>API Key</strong> the key,
							and <strong>This server's name servers</strong> <?=$dk_ns ? '<code>'.implode('</code> and <code>', array_map('h', $dk_ns)).'</code>' : 'the same as here'?>.
							Saving there opens the outgoing port 2087 in its csf firewall.</li>
						<li>From then on its zones are made and changed here, and its DNS Zones page shows only them. Zones it already has elsewhere:
							on that server <code>reqad dns zones --export=/root/zones</code> lists them and writes their zone files; import them here
							(DNS Zones &gt; Import zone), then give them to its key (<strong>Zones</strong> on the key).</li>
					</ol>
					<span class="set-hint">A key never gets this server's own name, the domains of hosting accounts here, or another key's zones. Its zones always
						name this server's name servers; the key cannot change their SOA or NS records.</span>
				</details>
			</div>
		</div>

	</div>
	</div>

	<div class="card mb-3">
		<div class="card-header">
			<h3 class="card-title">Keys</h3>
			<div class="card-actions"><button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#dk-m-create">New key</button></div>
		</div>
<?php if (!$dk_keys): ?>
		<div class="card-body text-muted">No keys yet. Make one for each server that keeps its zones here.</div>
<?php else: ?>
		<div class="table-responsive">
			<table class="table card-table table-vcenter">
				<thead><tr><th>Name</th><th>Key</th><th>Zones</th><th>Permissions</th><th>Allowed from</th><th>Expires</th><th>Last used</th><th></th></tr></thead>
				<tbody>
<?php foreach ($dk_keys as $k): $kid = (int)$k['id']; $nz = count($k['zones']); ?>
				<tr id="key-<?=$kid?>"<?=$k['state'] !== 'active' ? ' class="text-muted"' : ''?>>
					<td><?=h($k['name'])?><?php if ($k['created_by'] !== ''): ?><span class="set-hint mt-0">by <?=h($k['created_by'])?>, <?=h($dk_time($k['created']))?></span><?php endif; ?></td>
					<td><code><?=h($k['prefix'])?>&hellip;</code></td>
					<td><a href="#" data-bs-toggle="modal" data-bs-target="#dk-m-zones-<?=$kid?>"><?=$nz?></a><?=$k['max_zones'] ? '<span class="text-muted"> / '.(int)$k['max_zones'].'</span>' : ''?></td>
					<td><?php foreach (api_token_scopes($k) as $s): ?><span class="badge border <?=$dk_perm[$s][0]?> me-1"><?=h($dk_perm[$s][1])?></span><?php endforeach; ?></td>
					<td><?=$k['allow_ips'] !== '' ? h(str_replace(',', ', ', $k['allow_ips'])) : '<span class="text-warning">anywhere</span>'?></td>
					<td><?php if ($k['state'] === 'revoked'): ?><span class="badge border bg-red-lt">revoked</span><?php elseif ($k['state'] === 'expired'): ?><span class="badge border bg-red-lt">expired</span><?php else: ?><?=(int)$k['expires'] ? h(date('Y-m-d', (int)$k['expires'])) : 'never'?><?php endif; ?></td>
					<td><?=(int)$k['last_used'] ? h($dk_ago($k['last_used'])).(trim((string)$k['last_ip']) !== '' && trim((string)$k['last_ip']) !== trim((string)$k['allow_ips']) ? '<span class="set-hint mt-0">'.h($k['last_ip']).'</span>' : '') : '<span class="text-muted">never</span>'?></td>
					<td class="text-end text-nowrap">
						<button type="button" class="btn" data-bs-toggle="modal" data-bs-target="#dk-m-zones-<?=$kid?>">Zones</button>
<?php if ($k['state'] !== 'revoked'): ?>
						<button type="button" class="btn" data-bs-toggle="modal" data-bs-target="#dk-m-revoke" data-key="<?=$kid?>" data-name="<?=h($k['name'])?>" data-zones="<?=$nz?>">Revoke</button>
<?php endif; ?>
					</td>
				</tr>
<?php endforeach; ?>
				</tbody>
			</table>
		</div>
<?php endif; ?>
	</div>

	<div class="card mb-3" id="dk-calls">
		<div class="card-header">
			<h3 class="card-title">Latest calls <span class="text-muted fw-normal ms-2">kept 30 days</span></h3>
		</div>
<?php if (!$dk_calls): ?>
		<div class="card-body text-muted">No calls yet.</div>
<?php else: ?>
		<div class="table-responsive">
			<table class="table card-table table-vcenter">
				<thead><tr><th>Time</th><th>Key</th><th>From</th><th>Request</th><th>Status</th><th class="text-end">Took</th></tr></thead>
				<tbody>
<?php foreach ($dk_calls as $c): $st = (int)$c['status']; ?>
				<tr>
					<td><?=h(date('Y-m-d H:i:s', (int)$c['ts']))?></td>
					<td><?=h($c['token_name'] ?? '#'.$c['token_id'])?></td>
					<td><?=h($c['ip'])?></td>
					<td><span class="api-method"><?=h($c['method'])?></span> <code><?=h($c['path'])?></code></td>
					<td><span class="badge border <?=$st < 300 ? 'bg-green-lt' : ($st < 500 ? 'bg-yellow-lt' : 'bg-red-lt')?>"><?=$st?></span></td>
					<td class="text-end text-muted"><?=(int)$c['ms']?> ms</td>
				</tr>
<?php endforeach; ?>
				</tbody>
			</table>
		</div>
<?php if ($dk_more): ?>
		<div class="card-footer text-center"><a href="/dns-settings/keys/?calls=<?=$dk_calls_n + 100?>#dk-calls">Show more</a></div>
<?php endif; ?>
<?php endif; ?>
	</div>

	<!-- new key -->
	<div class="modal modal-blur fade dk-modal" id="dk-m-create" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered" style="max-width:640px" role="document">
			<div class="modal-content">
				<form method="post" action="/" autocomplete="off" data-sudo>
					<input type="hidden" name="action" value="dns-key-create">
					<div class="modal-header">
						<h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">New DNS API key</h5>
						<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
					</div>
					<div class="modal-body">
						<div class="mb-3">
							<label class="form-label required" for="dk-name">Name</label>
							<input type="text" class="form-control" id="dk-name" name="name" maxlength="60" required pattern="[A-Za-z0-9][A-Za-z0-9 ._@\-]*" placeholder="e.g. web2.example.com">
							<small class="form-hint">The server that uses it, so you know which one stops working when you revoke it.</small>
						</div>
						<div class="mb-3">
							<label class="form-label" for="dk-ips">Allowed from</label>
							<input type="text" class="form-control" id="dk-ips" name="allow_ips" maxlength="500" placeholder="the server's address, e.g. 203.0.113.7">
							<small class="form-hint">Recommended: the addresses of that server, comma separated (addresses or networks such as 198.51.100.0/24). Empty = from anywhere.</small>
						</div>
						<div class="mb-3">
							<label class="form-label">Permissions</label>
							<label class="form-check"><input class="form-check-input" type="checkbox" checked disabled><span class="form-check-label">Read <span class="text-muted">- list its zones and their records</span></span></label>
							<label class="form-check"><input class="form-check-input" type="checkbox" name="perm_write" value="1" checked><span class="form-check-label">Create and change <span class="text-muted">- make zones, add, change and remove records</span></span></label>
							<label class="form-check mb-0"><input class="form-check-input" type="checkbox" name="perm_delete" value="1" checked><span class="form-check-label">Remove zones <span class="text-muted">- when a site leaves that server</span></span></label>
						</div>
						<div class="row">
							<div class="col-sm-5 mb-3">
								<label class="form-label" for="dk-max">Zone limit</label>
								<input type="number" class="form-control" id="dk-max" name="max_zones" min="0" max="100000" placeholder="no limit">
								<small class="form-hint">The most zones this key may hold. Empty = no limit.</small>
							</div>
							<div class="col-sm-7 mb-3">
								<label class="form-label" for="dk-days">Expires after</label>
								<select class="form-select" id="dk-days" name="days">
<?php foreach (api_expiry_choices() as $d => $label): ?>
									<option value="<?=$d?>"<?=$d === 0 ? ' selected' : ''?>><?=h($label)?></option>
<?php endforeach; ?>
								</select>
							</div>
						</div>
						<div class="mb-1">
							<label class="form-label" for="dk-zones">Zones already here <span class="text-muted fw-normal">(optional)</span></label>
							<textarea class="form-control dk-mono" id="dk-zones" name="zones" rows="3" placeholder="example.com&#10;example.net"></textarea>
							<small class="form-hint">Zones on this server that should belong to it, one per line - for example the ones you imported for it.
								On the other Reqad server, <code>reqad dns zones</code> lists the zones its sites need.</small>
						</div>
					</div>
					<div class="modal-footer">
						<button type="button" class="btn me-auto" data-bs-dismiss="modal">Cancel</button>
						<button type="submit" class="btn btn-primary">Create key</button>
					</div>
				</form>
			</div>
		</div>
	</div>

	<!-- the zones of each key -->
<?php foreach ($dk_keys as $k): $kid = (int)$k['id']; $others = array_filter($dk_active, function ($o) use ($kid) { return (int)$o['id'] !== $kid; }); ?>
	<div class="modal modal-blur fade dk-modal" id="dk-m-zones-<?=$kid?>" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered" style="max-width:640px" role="document">
			<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Zones of <?=h($k['name'])?></h5>
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
				</div>
				<div class="modal-body">
<?php if (!$k['zones']): ?>
					<p class="text-muted">No zones yet. The zones its server makes appear here.</p>
<?php else: ?>
					<?php if (count($k['zones']) > 8): ?><input type="search" class="form-control mb-2 dk-zfilter" placeholder="Find a zone" autocomplete="off"><?php endif; ?>
					<div class="dk-zone-list mb-3">
<?php foreach ($k['zones'] as $z): ?>
						<div class="dk-zone" data-z="<?=h($z)?>">
							<a class="dk-z" href="/dns/<?=h(rawurlencode($z))?>/"><?=h($z)?></a>
							<form method="post" action="/">
								<input type="hidden" name="action" value="dns-key-zone-release">
								<input type="hidden" name="key" value="<?=$kid?>">
								<input type="hidden" name="zone" value="<?=h($z)?>">
								<button type="submit" class="btn btn-sm btn-ghost-secondary" title="The zone stays on this server; the key no longer reaches it">Take away</button>
							</form>
						</div>
<?php endforeach; ?>
					</div>
<?php endif; ?>
<?php if ($k['state'] === 'active'): ?>
					<form method="post" action="/" class="mb-3" data-sudo>
						<input type="hidden" name="action" value="dns-key-zones-add">
						<input type="hidden" name="key" value="<?=$kid?>">
						<label class="form-label">Give it zones already on this server</label>
						<textarea class="form-control dk-mono mb-2" name="zones" rows="2" placeholder="example.com&#10;example.net" required></textarea>
						<button type="submit" class="btn">Give zones</button>
						<small class="form-hint d-inline ms-2">A zone of another key moves to this one.</small>
					</form>
					<form method="post" action="/" class="mb-3 d-flex align-items-end gap-2">
						<input type="hidden" name="action" value="dns-key-limit">
						<input type="hidden" name="key" value="<?=$kid?>">
						<div>
							<label class="form-label">Zone limit <span class="text-muted fw-normal">(empty = no limit)</span></label>
							<input type="number" class="form-control" name="max_zones" min="0" max="100000" value="<?=$k['max_zones'] ? (int)$k['max_zones'] : ''?>" placeholder="no limit" style="width:140px">
						</div>
						<button type="submit" class="btn">Save</button>
					</form>
<?php endif; ?>
<?php if ($k['zones'] && $others): ?>
					<form method="post" action="/" class="d-flex align-items-end gap-2" data-sudo>
						<input type="hidden" name="action" value="dns-key-zones-move">
						<input type="hidden" name="key" value="<?=$kid?>">
						<div class="flex-fill">
							<label class="form-label">Move all its zones to</label>
							<select class="form-select" name="to" required>
								<option value="">Choose a key</option>
<?php foreach ($others as $o): ?>
								<option value="<?=(int)$o['id']?>"><?=h($o['name'])?> (<?=h($o['prefix'])?>&hellip;)</option>
<?php endforeach; ?>
							</select>
						</div>
						<button type="submit" class="btn">Move</button>
					</form>
					<small class="form-hint">For example to a new key that replaces this one.</small>
<?php endif; ?>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
				</div>
			</div>
		</div>
	</div>
<?php endforeach; ?>

	<form method="post" action="/" id="dk-revoke-form">
		<input type="hidden" name="action" value="dns-key-revoke">
		<input type="hidden" name="key" id="dk-revoke-id" value="">
		<div class="modal modal-blur fade" id="dk-m-revoke" tabindex="-1" role="dialog" aria-hidden="true">
			<div class="modal-dialog modal-sm modal-dialog-centered" role="document">
				<div class="modal-content">
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
					<div class="modal-status bg-danger"></div>
					<div class="modal-body text-center py-4">
						<svg xmlns="http://www.w3.org/2000/svg" class="icon mb-2 text-danger icon-lg" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="#ff2825" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="12" cy="12" r="9" /><line x1="12" y1="8" x2="12" y2="12" /><line x1="12" y1="16" x2="12.01" y2="16" /></svg>
						<h3>Revoke key <span id="dk-revoke-name"></span></h3>
						<div class="text-muted">Its server is refused from the next request on and can no longer change its zones. <span id="dk-revoke-zones"></span> This cannot be undone.</div>
					</div>
					<div class="modal-footer">
						<div class="w-100">
							<div class="row">
								<div class="col"><a href="#" class="btn w-100" data-bs-dismiss="modal">Cancel</a></div>
								<div class="col"><button type="submit" class="btn btn-danger w-100" id="dk-revoke-submit">Revoke</button></div>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</form>

<?php endif; /* dns_api_ready */ ?>
</div>

