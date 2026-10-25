<?php
	/* DNS Zones: every zone at the server's DNS provider (Settings > DNS
	   Settings) and the records of one.
	     /dns/          the zones: filter by zone or account, pages, a check
	                    of each zone on the page, new and removed zones
	     /dns/<zone>/   the zone's records (add, change, remove, search,
	                    filter by type), the zone check with fixes, import and
	                    export of zone files, name servers, SOA, and for a zone
	                    of an account its mail provider / SPF / DMARC helpers
	   Everything is read and changed through ajax-dns (modules/dns_admin.php
	   -> app/functions/dns.php + dns_tools.php); dist/js/dns-zone.js draws
	   both views. NS and PTR records are edited where the provider takes them
	   (PowerDNS); SOA is only shown. */
	dns_admin_mode(true);
	$dz_prov = dns_provider_load($db);
	$dz_zone = isset($reqs[2]) ? dns_zone_name(rawurldecode($reqs[2])) : '';
	if ($dz_zone !== '' && !valid_domain($dz_zone))
		$dz_zone = '';
	$dz_hidden_master = $dz_prov === 'powerdns' && ($settings['powerdns-mode'] ?? '') === 'hidden-master' && !empty($settings['powerdns-agent-url']);
	$dz_err = '';
	$dz_data = array('view' => $dz_zone !== '' ? 'zone' : 'list', 'zone' => $dz_zone, 'provider' => $dz_prov,
	                 'provider_name' => (string)$dns_provider_name, 'server_ip' => server_primary_ip(), 'host' => (string)gethostname());
	if ($dz_prov === 'none')
		$dz_err = 'No DNS provider is set up on this server. Choose one under <a href="/dns-settings/" class="text-danger" style="text-decoration:underline">DNS Settings</a>.';
	elseif ($dz_zone === '') {
		$r = dns_zone_list();
		if ($r['ok'])
			$dz_data['zones'] = $r['data']['zones'];
		else
			$dz_err = h($r['error']);
	}
	$dz_types = array(
		'A'     => 'The IPv4 address of a name: where its website is.',
		'AAAA'  => 'The IPv6 address of a name.',
		'CNAME' => 'This name is an alias of another name and takes all its records from it. It cannot have other records.',
		'MX'    => 'A server that takes mail for this name. A lower priority is tried first.',
		'TXT'   => 'Text for other services: SPF, DKIM, DMARC, proof that you own the domain.',
		'SRV'   => 'Where a service runs (calls, chat, mail setup): its name starts with the service and the protocol, such as _sip._tcp.',
		'CAA'   => 'Which certificate authorities may issue certificates for this name.',
		'NS'    => 'Name servers: at the domain itself, those that answer for it; at a name in it, the servers it is handed to (a delegated subdomain).',
		'PTR'   => 'The host name of an address (reverse zones, such as 2.0.192.in-addr.arpa).',
	);
	$dz_data['help'] = array_intersect_key($dz_types, array_flip(dns_editable_types()));
	$dz_js = '/dist/js/dns-zone.js?v='.@filemtime(__DIR__.'/../dist/js/dns-zone.js');
	include('templates/header.php');
?>
<style>
	#dz-page .table td, #dz-page .table th { vertical-align: middle; }
	#dz-page th { background-color: #DEF; }
	#dz-page .mono, #dz-page code { font-family: var(--tblr-font-monospace, ui-monospace, SFMono-Regular, Menlo, monospace); }
	#dz-page code { color: #1d273b; background: none; padding: 0; font-size: .82rem; word-break: break-all; white-space: pre-wrap; }
	#dz-page code.clip { display: block; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
	/* the value column takes what the others leave, so long TXT values never push TTL and the buttons out */
	#dz-tbl td.dz-val { width: 100%; max-width: 0; }
	#dz-tbl td.dz-name, #dz-tbl td.dz-ttl { white-space: nowrap; }
	/* the first column starts where the search text above does (bar 14px + border 1px + input 12px) */
	#dz-page:not(.dz-selecting) #dz-tbl tr > :nth-child(2), #dz-page.dz-selecting #dz-tbl tr > :first-child { padding-left:18px; }
	#dz-page .dz-filter td { background: #fff; padding: 4px 8px; }
	#dz-page .dz-filter input, #dz-page .dz-filter select { font-size: 10pt; padding: 5px 8px; }
	#dz-page .dz-q { position: relative; }
	#dz-page .dz-q button { display: none; position: absolute; right: 7px; top: 50%; transform: translateY(-50%); background: none; border: none; padding: 0; color: #aaa; font-size: 15px; line-height: 1; cursor: pointer; }
	#dz-page .dz-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
	#dz-page .dz-shimmer { display: inline-block; width: 60px; height: 9px; border-radius: 4px; background: linear-gradient(90deg, #eef1f5 25%, #f8f9fb 50%, #eef1f5 75%); background-size: 200% 100%; animation: dzsh 1.2s infinite; }
	@keyframes dzsh { 0% { background-position: 200% 0 } 100% { background-position: -200% 0 } }
	/* monospaced names: no bold, the label dark and the domain grey */
	#dz-page .dz-name .dz-lbl { color: #1d273b; }
	#dz-page .dz-name .dz-dom { color: #8a94a6; }
	#dz-page .dz-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 16px; }
	@media (min-width: 1200px) { #dz-page .dz-grid { grid-template-columns: minmax(0, 1fr) 360px; } }
	@media (min-width: 1800px) { #dz-page .dz-grid { grid-template-columns: minmax(0, 1fr) 400px; } }
	#dz-page .dz-bar { padding: 14px 18px 14px 12px; display: flex; flex-wrap: wrap; align-items: center; gap: 12px 16px; }
	#dz-page .dz-rq { flex: 0 1 240px; }
	#dz-page .dz-bar-end { margin-left: auto; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
	#dz-page .dz-bar-end .btn { margin-left: 4px; }
	#dz-page .dz-selc { display: none; }
	#dz-page.dz-selecting .dz-selc { display: table-cell; }
	#dz-page .dz-selc input { cursor: pointer; }
	#dz-page tr.dz-picked td { background: #f1f6fd; }
	#dz-page .dz-types { flex-wrap: wrap; }
	#dz-page .dz-types .btn.active { background: var(--tblr-primary, #206bc4); color: #fff; border-color: var(--tblr-primary, #206bc4); }
	#dz-page .dz-act { white-space: nowrap; text-align: right; }
	#dz-page .dz-act .btn { padding: 3px 7px; }
	#dz-page .dz-act .icon { margin: 0; width: 16px; height: 16px; }
	#dz-page .dz-lock { color: #999; display: inline-flex; padding: 3px 7px; }
	#dz-page .dz-warn-ic { color: #f59f00; cursor: help; }
	#dz-page .dz-err-ic { color: #d63939; cursor: help; }
	#dz-page .dz-ty { display: inline-block; min-width: 46px; text-align: center; font-weight: 600; font-size: 11px; padding: 2px 6px; border-radius: 4px; background: #eef3fb; color: #1d4f91; border: 1px solid #d5e2f5; }
	#dz-page .dz-chk li { padding: 9px 0; border-top: 1px solid #eef0f3; display: flex; gap: 9px; align-items: flex-start; }
	#dz-page .dz-chk li:first-child { border-top: 0; }
	#dz-page .dz-chk .dz-lv { flex: 0 0 auto; margin-top: 1px; }
	#dz-page .dz-chk .dz-txt { flex: 1 1 auto; font-size: 13px; word-break: break-word; }
	#dz-page .dz-chk .btn { margin-top: 6px; padding: 2px 9px; font-size: 12px; white-space: normal; text-align: left; }
	#dz-page .dz-side .card-body { padding: 14px 18px; }
	#dz-page .dz-side ul { list-style: none; margin: 0; padding: 0; }
	#dz-page .dz-ns li { font-family: var(--tblr-font-monospace, monospace); font-size: 13px; padding: 3px 0; }
	#dz-page .dz-soa td { padding: 3px 0; font-size: 13px; border: 0; }
	#dz-page .dz-soa td:first-child { color: #777; width: 42%; }
	#dz-page .dz-mp li { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-top: 1px solid #eef0f3; }
	#dz-page .dz-mp li:first-child { border-top: 0; }
	#dz-page .dz-mp .dz-mp-t { flex: 1 1 auto; min-width: 0; font-size: 13px; }
	#dz-page .dz-mp .dz-mp-t b { display: block; font-family: var(--tblr-font-monospace, monospace); font-weight: 400; color: #1d273b; overflow: hidden; text-overflow: ellipsis; }
	.dz-logo { width: 26px; height: 26px; flex: 0 0 26px; display: inline-flex; align-items: center; justify-content: center; border-radius: 6px; background: #f4f6fa; color: #556; }
	.dz-logo svg { width: 18px; height: 18px; }
	.dz-linkish { background: none; border: 0; padding: 0; color: var(--tblr-primary, #206bc4); cursor: pointer; font-size: 12px; margin-right: 10px; }
	.dz-linkish:hover { text-decoration: underline; }
	.dz-modal .form-label { font-weight: 600; }
	.dz-modal .mono { font-family: var(--tblr-font-monospace, ui-monospace, SFMono-Regular, Menlo, monospace); }
	.dz-presets .btn { padding: 2px 10px; }
	.dz-presets .btn.active { background: var(--tblr-primary, #206bc4); color: #fff; border-color: var(--tblr-primary, #206bc4); }
	.dz-builder { border: 1px solid #e2e7ef; background: #fafbfd; border-radius: 8px; padding: 12px 14px; margin-bottom: 14px; }
	.dz-builder .dz-b-h { font-weight: 600; margin-bottom: 8px; }
	.dz-builder .dz-opt { padding: 5px 10px; font-size: 12.5px; background: #fff; }
	.dz-saved { background: #f6f8fb; border: 1px solid #e2e7ef; border-radius: 6px; padding: 8px 11px; font-size: 12.5px; word-break: break-all; }
	.dz-saved .lbl { color: #777; font-family: inherit; margin-right: 6px; }
	.dz-hints .alert { padding: 8px 11px; margin: 8px 0 0; font-size: 13px; }
	.dz-hints .btn { margin-left: 8px; padding: 1px 9px; font-size: 12px; }
	.dz-imp-tbl { font-size: 12.5px; }
	.dz-imp-tbl td { padding: 4px 8px !important; vertical-align: top !important; }
	.dz-imp-tbl code { font-size: 12px; word-break: break-all; white-space: pre-wrap; color: #1d273b; background: none; padding: 0; }
	.dz-imp-wrap { max-height: 46vh; overflow: auto; border: 1px solid #e6e8ec; border-radius: 6px; }
	.dz-opts { display: flex; flex-wrap: wrap; gap: 8px; }
	.dz-opt { display: flex; align-items: center; gap: 8px; border: 1px solid #dfe3ea; border-radius: 8px; padding: 7px 11px; cursor: pointer; font-size: 13px; margin: 0; }
	.dz-opt.on { border-color: var(--tblr-primary, #206bc4); background: #f1f6fd; }
	.dz-opt input { margin: 0; }
	.dz-plan li { margin: 6px 0; font-size: 13px; }
	.dz-plan .was { color: #b13; text-decoration: line-through; font-family: var(--tblr-font-monospace, monospace); font-size: 12px; word-break: break-all; }
	.dz-plan .new { color: #186a3b; font-family: var(--tblr-font-monospace, monospace); font-size: 12px; word-break: break-all; }
	#dz-toasts { position: fixed; right: 18px; bottom: 18px; z-index: 2000; display: flex; flex-direction: column; gap: 8px; max-width: 460px; }
	#dz-toasts .alert { margin: 0; box-shadow: 0 6px 22px rgba(0,0,0,.12); background: #fff; }
	/* phones: the header's buttons go under the zone's name, and each record is
	   a small block (name + buttons, type + TTL, then the value at full width)
	   instead of a table row wider than the screen */
	@media (max-width: 767px) {
		#dz-page .page-header .row > .col { flex: 0 0 100%; max-width: 100%; }
		#dz-page .page-header .row > .col-auto { margin-left: 0 !important; margin-top: 12px; padding-left: 22px; }
		#dz-page .page-header .page-title { flex-wrap: wrap; gap: 6px 0; }
		#dz-page .page-header .page-title .mono { word-break: break-all; margin-right: 4px; }
		#dz-page .page-header .page-title #dz-head-badges { margin-left: 0 !important; }
		#dz-wrap { overflow-x: visible; }
		#dz-tbl, #dz-tbl tbody { display: block; width: 100%; }
		#dz-tbl thead { display: none; }
		#dz-tbl tbody tr { display: grid; grid-template-columns: auto auto minmax(0, 1fr) auto; align-items: center;
			grid-template-areas: "sel name name act" "sel type ttl act" "sel val val val";
			padding: 10px 16px; border-top: 1px solid rgba(98,105,118,.16); }
		#dz-tbl tbody tr:first-child { border-top: 0; }
		#dz-tbl tbody td { display: block; border: 0; padding: 2px 0 !important; }
		#dz-tbl tbody td.dz-selc { grid-area: sel; padding-right: 12px !important; }
		#dz-page:not(.dz-selecting) #dz-tbl tbody td.dz-selc { display: none; }
		#dz-tbl tbody td.dz-name { grid-area: name; white-space: normal; word-break: break-all; }
		#dz-tbl tbody td.dz-type { grid-area: type; padding-right: 10px !important; }
		#dz-tbl tbody td.dz-ttl { grid-area: ttl; font-size: 12px; }
		#dz-tbl tbody td.dz-act { grid-area: act; align-self: start; padding-left: 8px !important; }
		#dz-tbl tbody td.dz-val { grid-area: val; width: auto; max-width: none; padding-top: 4px !important; }
		#dz-tbl tbody td[colspan] { grid-column: 1 / -1; }
		#dz-page #dz-tbl tbody tr.dz-picked { background: #f1f6fd; }
		#dz-page #dz-tbl tbody tr.dz-picked td { background: transparent; }
		/* the zone list: name + Remove, then who it belongs to, records and the check */
		#dz-list, #dz-list thead, #dz-list tbody { display: block; width: 100%; }
		#dz-list thead tr:first-child { display: none; }
		#dz-list thead tr.dz-filter { display: flex; gap: 8px; padding: 8px 16px; border-bottom: 1px solid rgba(98,105,118,.16); }
		#dz-list thead tr.dz-filter td { display: block; border: 0; padding: 0; }
		#dz-list thead tr.dz-filter td:first-child, #dz-list thead tr.dz-filter td[colspan] { display: none; }
		#dz-list thead tr.dz-filter td:nth-child(2) { flex: 1 1 auto; min-width: 0; }
		#dz-list thead tr.dz-filter #dz-q { margin: 0 !important; border: 1px solid #dadfe5 !important; }
		#dz-list tbody tr { display: grid; grid-template-columns: auto auto minmax(0, 1fr) auto; align-items: center; column-gap: 12px;
			grid-template-areas: "zn zn zn act" "own recs chk chk"; padding: 10px 16px; border-top: 1px solid rgba(98,105,118,.16); }
		#dz-list tbody tr:first-child { border-top: 0; }
		#dz-list tbody td { display: block; border: 0; padding: 2px 0 !important; white-space: normal; }
		#dz-list tbody td.dz-no, #dz-list tbody td.dz-ser, #dz-list tbody td.dz-kind, #dz-list .dz-editbtn { display: none; }
		#dz-list tbody td.dz-zn { grid-area: zn; word-break: break-all; }
		#dz-list tbody td.dz-own { grid-area: own; }
		#dz-list tbody td.dz-recs { grid-area: recs; text-align: left; color: #656d77; }
		#dz-list tbody td.dz-recs::after { content: " records"; }
		#dz-list tbody td.dz-chkc { grid-area: chk; }
		#dz-list tbody td.dz-act { grid-area: act; }
		#dz-list tbody td[colspan] { grid-column: 1 / -1; }
	}
</style>

<div id="dz-page">
<?php if ($dz_zone === ''): ?>
        <div class="page-header d-print-none">
            <div class="row align-items-center">
              <div class="col" style="padding-left:22px;">
                <div class="page-pretitle">DNS</div>
                <h2 class="page-title">DNS Zones <?php if ($dz_prov !== 'none'): ?><span class="badge bg-azure-lt border ms-2" style="font-size:.7rem;"><?=h($dns_provider_name)?></span><?php endif; ?></h2>
              </div>
              <div class="col-auto ms-auto d-print-none">
                <div class="btn-list">
<?php if ($dz_hidden_master): ?>
                  <a href="#" class="btn btn-white" data-bs-toggle="modal" data-bs-target="#modal-sync-local" title="Pull all zones from the cPanel DNS agent into local PowerDNS">Sync to local</a>
<?php endif; ?>
                  <a href="/dns-settings/" class="btn btn-white">DNS Settings</a>
<?php if ($dz_prov === 'powerdns' && dns_cluster_available()): ?>
                  <a href="/dns-settings/keys/" class="btn btn-white" title="Other servers that keep their zones here">API keys</a>
<?php endif; ?>
<?php if ($dz_prov !== 'none' && $dz_err === ''): ?>
                  <a href="#" class="btn btn-white" id="dz-import-zone" title="Make a new zone from a zone file, such as one exported from another panel">Import zone</a>
                  <a href="#" class="btn btn-primary" id="dz-new-zone">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Add a zone
                  </a>
<?php endif; ?>
                </div>
              </div>
            </div>
        </div>
<?php else: ?>
        <div class="page-header d-print-none">
            <div class="row align-items-center">
              <div class="col" style="padding-left:22px;">
                <div class="page-pretitle"><a href="/dns/" class="text-muted">DNS Zones</a> /</div>
                <h2 class="page-title"><span class="mono"><?=h($dz_zone)?></span> <span id="dz-head-badges" class="ms-2" style="font-size:.75rem;"></span></h2>
              </div>
              <div class="col-auto ms-auto d-print-none">
                <div class="btn-list">
                  <a href="#" class="btn btn-white" id="dz-export-btn" title="The zone as a zone file, to copy or download">Zone file</a>
                  <a href="#" class="btn btn-white" id="dz-import-btn" title="Add records from a zone file, such as one exported from another panel">Import</a>
                </div>
              </div>
            </div>
        </div>
<?php endif; ?>

<?php msg_render(); ?>
<?php if ($dz_err !== ''): ?>
        <div class="alert alert-warning" role="alert" style="background:#FFE;"><div class="text-danger"><?=$dz_err?></div></div>
<?php elseif ($dz_zone === ''): ?>
        <div class="col-12">
          <div class="card">
            <div class="table-responsive">
              <table class="table table-vcenter card-table table-nowrap" id="dz-list">
                <thead>
                  <tr>
                    <th class="w-1">#</th>
                    <th>Zone</th>
                    <th><?=$dz_prov === 'powerdns' ? 'Belongs to' : 'Account'?></th>
                    <th class="dz-num">Records</th>
                    <th>Check</th>
                    <th>Serial</th>
<?php if ($dz_prov === 'powerdns'): ?>
                    <th>Kind</th>
<?php endif; ?>
                    <th class="w-1"></th>
                  </tr>
                  <tr class="dz-filter">
                    <td></td>
                    <td><div class="dz-q"><input type="search" id="dz-q" class="form-control" style="border:none;margin:0 0 0 -8px;;" placeholder="Filter zones or accounts..." autocomplete="off" aria-label="Filter zones or accounts"><button type="button" id="dz-q-clear" title="Clear" aria-label="Clear">&#x2715;</button></div></td>
                    <td><select id="dz-f" class="form-select" aria-label="Which zones">
                      <option value="">All zones</option>
                      <option value="acct">Zones of an account</option>
<?php if ($dz_prov === 'powerdns'): ?>
                      <option value="key">Zones of a DNS API key</option>
<?php endif; ?>
                      <option value="none">Zones of no account<?=$dz_prov === 'powerdns' ? ' or key' : ''?></option>
                      <option value="bad">With problems</option>
                    </select></td>
                    <td colspan="<?=$dz_prov === 'powerdns' ? 5 : 4?>"></td>
                  </tr>
                </thead>
                <tbody></tbody>
              </table>
            </div>
            <div class="card-footer d-flex align-items-center flex-wrap gap-2" id="dz-list-foot"></div>
          </div>
          <p class="text-muted small mt-3 ms-1">The check counts mistakes such as a missing final dot, a CNAME next to other records, two SPF records or a mail server with no address. Open a zone to see them and fix them.</p>
        </div>
<?php else: ?>
        <div class="card mb-3">
          <div class="card-body dz-bar">
            <div class="dz-q dz-rq"><input type="search" id="dz-rq" class="form-control" placeholder="Search names and values" autocomplete="off" aria-label="Search names and values"><button type="button" id="dz-rq-clear" title="Clear" aria-label="Clear">&#x2715;</button></div>
            <div class="btn-group dz-types" id="dz-types" role="group" aria-label="Record type"></div>
            <div class="dz-bar-end">
              <span class="text-muted" id="dz-count"></span>
              <span id="dz-tools">
                <button type="button" class="btn btn-white" id="dz-sel-btn" disabled>
                  <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3.5 5.5l1.5 1.5l2.5 -2.5"/><path d="M3.5 11.5l1.5 1.5l2.5 -2.5"/><path d="M3.5 17.5l1.5 1.5l2.5 -2.5"/><path d="M11 6l9 0"/><path d="M11 12l9 0"/><path d="M11 18l9 0"/></svg>
                  Select
                </button>
                <button type="button" class="btn btn-primary" id="dz-add-btn" disabled>
                  <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                  Add a record
                </button>
              </span>
              <span id="dz-sel-tools" style="display:none;">
                <button type="button" class="btn btn-danger" id="dz-sel-del" disabled>Delete selected</button>
                <button type="button" class="btn btn-white" id="dz-sel-cancel">Cancel</button>
              </span>
            </div>
          </div>
        </div>
        <div class="dz-grid">
          <div class="dz-main">
            <div class="card">
              <div id="dz-load" class="card-body text-muted"><span class="spinner-border spinner-border-sm me-2"></span>Reading the zone…</div>
              <div class="table-responsive" id="dz-wrap" style="display:none;">
                <table class="table table-vcenter card-table" id="dz-tbl">
                  <thead><tr><th class="dz-selc w-1"><input type="checkbox" class="form-check-input m-0" id="dz-sel-all" aria-label="Select all on this page"></th><th>Name</th><th>Type</th><th>Value</th><th>TTL</th><th class="w-1"></th></tr></thead>
                  <tbody></tbody>
                </table>
                <div class="card-body text-muted" id="dz-none" style="display:none;">No records match.</div>
              </div>
              <div class="card-footer d-flex align-items-center flex-wrap gap-2" id="dz-rec-foot" style="display:none !important;"></div>
            </div>
            <p class="text-muted small mt-3 ms-1">Changes are made at once at the DNS provider<?= $dz_hidden_master ? ' and pushed to the DNS agent' : '' ?>. Other DNS servers may keep the old answer until its TTL runs out, so a change can take that long to be seen everywhere.</p>
          </div>
          <div class="dz-side">
            <div class="card mb-3">
              <div class="card-header" style="padding:10px 18px;"><h3 class="card-title">Zone check</h3><a href="#" class="ms-auto small" id="dz-recheck">Check again</a></div>
              <div class="card-body"><ul class="dz-chk" id="dz-chk"><li class="text-muted">…</li></ul></div>
            </div>
            <div class="card mb-3" id="dz-mp-card" style="display:none;">
              <div class="card-header" style="padding:10px 18px;"><h3 class="card-title">Mail provider</h3><a href="#" class="ms-auto small" id="dz-acct-link"></a></div>
              <div class="card-body"><ul class="dz-mp" id="dz-mp"></ul>
                <div class="text-muted small mt-2">Who receives the email of these sites: this server, or a provider such as Google Workspace, Microsoft 365 or Zoho Mail. Choosing one sets its MX and SPF records.</div></div>
            </div>
            <div class="card mb-3">
              <div class="card-header" style="padding:10px 18px;"><h3 class="card-title">Name servers</h3></div>
              <div class="card-body"><ul class="dz-ns" id="dz-ns"></ul>
                <div class="text-muted small mt-2" id="dz-ns-note">The servers that answer for the zone. Which ones the internet asks is set where the domain is registered.</div></div>
            </div>
            <div class="card mb-3" id="dz-soa-card" style="display:none;">
              <div class="card-header" style="padding:10px 18px;"><h3 class="card-title">SOA</h3><a href="#" class="ms-auto small" id="dz-soa-edit" style="display:none;">Edit</a></div>
              <div class="card-body"><table class="table dz-soa mb-0" id="dz-soa"></table>
                <div class="text-muted small mt-2" id="dz-soa-note">The provider keeps it and raises the serial with every change.</div>
                <button type="button" class="btn btn-white btn-sm mt-2" id="dz-soa-raise" style="display:none;" title="Secondary servers fetch the zone again when the serial goes up">Raise serial</button></div>
            </div>
            <div class="card mb-3">
              <div class="card-body">
                <a href="#" class="btn btn-outline-danger w-100" id="dz-remove-btn">Remove this zone</a>
              </div>
            </div>
          </div>
        </div>
<?php endif; ?>
</div>

<div id="dz-toasts" aria-live="polite"></div>

<!-- dns-zone.js moves these to <body> (the footer leaves a <div> open) -->
<div id="dz-modals">
  <div class="modal modal-blur fade dz-modal" id="dz-m-zone" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
      <form class="modal-content" id="dz-zone-form" autocomplete="off" novalidate>
        <div class="modal-header"><h5 class="modal-title">Add a DNS zone</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
          <div class="alert alert-danger dz-err" style="display:none;" role="alert"></div>
          <div class="mb-3">
            <label class="form-label" for="dz-zone-name">Domain</label>
            <input class="form-control mono" id="dz-zone-name" placeholder="example.com" maxlength="253" autocapitalize="none" spellcheck="false" required>
          </div>
          <div class="mb-2">
            <label class="form-label" for="dz-zone-ip">Address of its website</label>
            <input class="form-control mono" id="dz-zone-ip" maxlength="45" autocapitalize="none" spellcheck="false">
            <div class="form-hint">The zone starts with an A record for the domain with this address, www and mail as aliases of it, and an MX record. Hosting accounts get their zone made when they are created; add one here for a domain that has no account.</div>
          </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Add zone</button></div>
      </form>
    </div>
  </div>

  <div class="modal modal-blur fade dz-modal" id="dz-m-remove" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
      <form class="modal-content" id="dz-remove-form" autocomplete="off" novalidate>
        <div class="modal-status bg-danger"></div>
        <div class="modal-header"><h5 class="modal-title">Remove the zone <span class="mono" id="dz-remove-name"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
          <div class="alert alert-danger dz-err" style="display:none;" role="alert"></div>
          <p>Every record of the zone is removed from the DNS provider. While the domain's registration points to these name servers, its website and mail stop working.</p>
          <p class="text-danger" id="dz-remove-acct" style="display:none;"></p>
          <label class="form-label" for="dz-remove-confirm">Type the zone's name to remove it</label>
          <input class="form-control mono" id="dz-remove-confirm" autocapitalize="none" spellcheck="false">
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-danger" disabled>Remove zone</button></div>
      </form>
    </div>
  </div>

<?php if ($dz_zone === ''): ?>
  <div class="modal modal-blur fade dz-modal" id="dz-m-newimp" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered" role="document">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Import a zone</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
          <div class="alert alert-danger dz-err" style="display:none;" role="alert"></div>
          <div id="dz-ni-1">
            <p class="text-muted">A new zone is made from a zone file (the form name servers use, as cPanel and other providers export it: <span class="mono">example.com.db</span>), with exactly the file's records. SOA and the name servers are this server's own. Names and host names without a final dot are read as names of the zone.</p>
            <div class="mb-3" style="max-width:420px;">
              <label class="form-label" for="dz-ni-zone">Domain</label>
              <input class="form-control mono" id="dz-ni-zone" maxlength="253" autocapitalize="none" spellcheck="false" placeholder="found in the file">
              <div class="form-hint">Leave it empty to take it from the file ($ORIGIN or its SOA record) or from the file's name.</div>
            </div>
            <textarea class="form-control mono" id="dz-ni-text" rows="12" spellcheck="false" placeholder="$ORIGIN example.com.&#10;$TTL 14400&#10;@      IN A     192.0.2.10&#10;www    IN CNAME @&#10;@      IN MX    10 mail&#10;@      IN TXT   &quot;v=spf1 +a +mx ~all&quot;"></textarea>
            <input type="file" class="form-control mt-2" id="dz-ni-file" accept=".db,.zone,.txt,text/plain">
          </div>
          <div id="dz-ni-2" style="display:none;">
            <p class="mb-2">The zone <span class="mono" id="dz-ni-name"></span> is made with the records ticked below.</p>
            <div class="dz-imp-wrap"><table class="table table-sm dz-imp-tbl mb-0"><thead><tr><th class="w-1"><input type="checkbox" class="form-check-input" id="dz-ni-all" checked aria-label="All"></th><th>Name</th><th>Type</th><th>TTL</th><th>Value</th><th></th></tr></thead><tbody id="dz-ni-rows"></tbody></table></div>
            <div class="text-muted small mt-2" id="dz-ni-sum"></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-white" id="dz-ni-back" style="display:none;">Back</button>
          <button type="button" class="btn btn-primary" id="dz-ni-read">Read the file</button>
          <button type="button" class="btn btn-primary" id="dz-ni-go" style="display:none;">Create the zone</button>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>
<?php if ($dz_zone !== ''): ?>
  <div class="modal modal-blur fade dz-modal" id="dz-m-rec" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
      <form class="modal-content" id="dz-rec-form" autocomplete="off" novalidate>
        <div class="modal-header"><h5 class="modal-title" id="dz-rec-h">Add a record</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
          <div class="row">
            <div class="col-md-3 mb-3">
              <label class="form-label" for="dz-type">Type</label>
              <select class="form-select" id="dz-type"><?php foreach ($dz_data['help'] as $k => $_): ?><option><?=h($k)?></option><?php endforeach; ?></select>
            </div>
            <div class="col-md-9 mb-3">
              <label class="form-label" for="dz-name">Name</label>
              <div class="input-group"><input class="form-control mono" id="dz-name" maxlength="253" autocapitalize="none" spellcheck="false" placeholder="@"><span class="input-group-text mono" id="dz-sfx"></span></div>
              <div class="form-hint">@ (or empty) for the domain itself; www, shop, _dmarc for a name in it; * for every name that has no records of its own. The full name works too.</div>
            </div>
          </div>
          <div class="form-hint mb-3" id="dz-type-help" style="margin-top:-6px;"></div>
          <div class="dz-presets mb-3" id="dz-presets">
            <span class="text-muted me-1">Ready-made:</span>
            <button type="button" class="btn btn-sm btn-white" data-preset="spf" title="Which servers may send mail for the domain">SPF</button>
            <button type="button" class="btn btn-sm btn-white" data-preset="dkim" title="The public key receivers check the mail's signature with">DKIM</button>
            <button type="button" class="btn btn-sm btn-white" data-preset="dmarc" title="What receivers do with mail that fails SPF and DKIM">DMARC</button>
          </div>
          <div class="mb-3" data-for="A AAAA">
            <label class="form-label" for="dz-ip">Address</label>
            <input class="form-control mono" id="dz-ip" maxlength="45" autocapitalize="none" spellcheck="false">
          </div>
          <div class="mb-3" data-for="CNAME NS PTR">
            <label class="form-label" for="dz-target" id="dz-target-l">Alias of</label>
            <input class="form-control mono" id="dz-target" maxlength="253" autocapitalize="none" spellcheck="false" placeholder="example.com">
            <div class="form-hint">The full name, with or without the final dot (it is added when saved), such as example.com or www.example.com.</div>
          </div>
          <div class="row" data-for="MX SRV">
            <div class="col-4 col-md-3 mb-3"><label class="form-label" for="dz-prio">Priority</label><input class="form-control mono" id="dz-prio" type="number" min="0" max="65535" value="10"></div>
            <div class="col-4 col-md-3 mb-3" data-for="SRV"><label class="form-label" for="dz-weight">Weight</label><input class="form-control mono" id="dz-weight" type="number" min="0" max="65535" value="5"></div>
            <div class="col-4 col-md-3 mb-3" data-for="SRV"><label class="form-label" for="dz-port">Port</label><input class="form-control mono" id="dz-port" type="number" min="0" max="65535"></div>
          </div>
          <div class="mb-3" data-for="MX SRV">
            <label class="form-label" for="dz-host">Server</label>
            <input class="form-control mono" id="dz-host" maxlength="253" autocapitalize="none" spellcheck="false" placeholder="mail.example.com">
          </div>
          <div class="dz-builder" id="dz-b-spf" data-kind="spf" style="display:none;">
            <div class="dz-b-h">SPF: the servers that may send mail as this name</div>
            <label class="form-check"><input class="form-check-input" type="checkbox" data-b="a"><span class="form-check-label">Its website's address <span class="text-muted mono">+a</span></span></label>
            <label class="form-check"><input class="form-check-input" type="checkbox" data-b="mx"><span class="form-check-label">The servers that receive its mail <span class="text-muted mono">+mx</span></span></label>
            <label class="form-check"><input class="form-check-input" type="checkbox" data-b="ip"><span class="form-check-label">This server <span class="text-muted mono" id="dz-b-ip"></span></span></label>
            <div class="form-label mt-2">Services that send mail for it</div>
            <div class="dz-opts" id="dz-b-senders"></div>
            <div class="row mt-2">
              <div class="col-md-7 mb-2"><label class="form-label" for="dz-b-other">Other servers (optional)</label><input class="form-control mono" id="dz-b-other" spellcheck="false" autocapitalize="none" placeholder="ip4:192.0.2.10 include:spf.example.net"></div>
              <div class="col-md-5 mb-2"><label class="form-label" for="dz-b-all">Mail from any other server</label><select class="form-select" id="dz-b-all">
                <option value="~all">Suspicious (~all)</option><option value="-all">Refused (-all)</option><option value="?all">No opinion (?all)</option></select></div>
            </div>
            <div class="form-hint" id="dz-b-lookups"></div>
          </div>
          <div class="dz-builder" id="dz-b-dkim" data-kind="dkim" style="display:none;">
            <div class="dz-b-h">DKIM: the public key of a sender's signature</div>
            <div class="row">
              <div class="col-md-5 mb-2"><label class="form-label" for="dz-b-sel">Selector</label><input class="form-control mono" id="dz-b-sel" spellcheck="false" autocapitalize="none" placeholder="default">
                <div class="form-hint">As the sender gives it (default, google, selector1...). The record's name is <span class="mono">&lt;selector&gt;._domainkey</span>.</div></div>
              <div class="col-md-3 mb-2"><label class="form-label" for="dz-b-k">Key type</label><select class="form-select" id="dz-b-k"><option value="rsa">RSA</option><option value="ed25519">Ed25519</option></select></div>
              <div class="col-md-4 mb-2 d-flex align-items-end"><button type="button" class="btn btn-white w-100" id="dz-b-local" style="display:none;">Use this server's key</button></div>
            </div>
            <label class="form-label" for="dz-b-p">Public key</label>
            <textarea class="form-control mono" id="dz-b-p" rows="3" spellcheck="false" placeholder="MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA..."></textarea>
            <div class="form-hint">Paste the key, the whole record (v=DKIM1; ... p=...) or the key file (-----BEGIN PUBLIC KEY-----).</div>
          </div>
          <div class="dz-builder" id="dz-b-dmarc" data-kind="dmarc" style="display:none;">
            <div class="dz-b-h">DMARC: what receivers do with mail that fails SPF and DKIM</div>
            <div class="dz-opts mb-2">
              <label class="dz-opt"><input type="radio" name="dz-b-p" value="none"><span>Deliver it, only report</span></label>
              <label class="dz-opt"><input type="radio" name="dz-b-p" value="quarantine"><span>Spam folder</span></label>
              <label class="dz-opt"><input type="radio" name="dz-b-p" value="reject"><span>Refuse it</span></label>
            </div>
            <div class="row">
              <div class="col-md-8 mb-2"><label class="form-label" for="dz-b-rua">Daily reports to (optional)</label><input class="form-control mono" id="dz-b-rua" spellcheck="false" autocapitalize="none" placeholder="dmarc@example.com"></div>
              <div class="col-md-4 mb-2"><label class="form-label" for="dz-b-pct">Share of mail %</label><input class="form-control mono" id="dz-b-pct" type="number" min="1" max="100" placeholder="100"></div>
              <div class="col-md-4 mb-2"><label class="form-label" for="dz-b-sp">Subdomains</label><select class="form-select" id="dz-b-sp"><option value="">As the domain</option><option value="none">Deliver, report</option><option value="quarantine">Spam folder</option><option value="reject">Refuse</option></select></div>
              <div class="col-md-4 mb-2"><label class="form-label" for="dz-b-adkim">DKIM alignment</label><select class="form-select" id="dz-b-adkim"><option value="r">Relaxed</option><option value="s">Strict</option></select></div>
              <div class="col-md-4 mb-2"><label class="form-label" for="dz-b-aspf">SPF alignment</label><select class="form-select" id="dz-b-aspf"><option value="r">Relaxed</option><option value="s">Strict</option></select></div>
            </div>
          </div>
          <div class="mb-3" data-for="TXT">
            <label class="form-label" for="dz-txt">Text</label>
            <textarea class="form-control mono" id="dz-txt" maxlength="4096" rows="3" spellcheck="false"></textarea>
            <div class="form-hint">Paste it as you were given it: quotes around it are taken off, and text longer than 255 characters is split as DNS needs.</div>
          </div>
          <div class="row" data-for="CAA">
            <div class="col-md-4 mb-3"><label class="form-label" for="dz-tag">Tag</label>
              <select class="form-select" id="dz-tag"><option value="issue">issue</option><option value="issuewild">issuewild</option><option value="iodef">iodef</option></select></div>
            <div class="col-md-8 mb-3"><label class="form-label" for="dz-caa">Value</label><input class="form-control mono" id="dz-caa" maxlength="255" autocapitalize="none" spellcheck="false" placeholder="letsencrypt.org"></div>
          </div>
          <div class="row">
            <div class="col-md-4 mb-3">
              <label class="form-label" for="dz-ttl">TTL</label>
              <select class="form-select" id="dz-ttl"></select>
            </div>
            <div class="col-md-8 mb-3 d-flex align-items-end"><div class="form-hint">How long other DNS servers may keep the answer. Records with the same name and type share one TTL.</div></div>
          </div>
          <div class="dz-saved mono" id="dz-saved" style="display:none;"><span class="lbl">Saved as</span><span></span></div>
          <div class="alert alert-danger dz-err mt-2 mb-0" style="display:none;" role="alert"></div>
          <div class="dz-hints" id="dz-hints"></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary" id="dz-rec-save">Add record</button></div>
      </form>
    </div>
  </div>

  <div class="modal modal-blur fade dz-modal" id="dz-m-confirm" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered" role="document">
      <div class="modal-content">
        <div class="modal-status bg-danger"></div>
        <div class="modal-body text-center py-4">
          <h3 id="dz-confirm-h">Remove this record?</h3>
          <div class="text-muted mono" id="dz-confirm-t" style="word-break:break-all;font-size:12.5px;white-space:pre-line;max-height:40vh;overflow:auto;text-align:left;"></div>
        </div>
        <div class="modal-footer"><div class="w-100"><div class="row">
          <div class="col"><button type="button" class="btn btn-white w-100" data-bs-dismiss="modal">Cancel</button></div>
          <div class="col"><button type="button" class="btn btn-danger w-100" id="dz-confirm-ok">Remove</button></div>
        </div></div></div>
      </div>
    </div>
  </div>

  <div class="modal modal-blur fade dz-modal" id="dz-m-import" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered" role="document">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Import a zone file into <span class="mono"><?=h($dz_zone)?></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
          <div class="alert alert-danger dz-err" style="display:none;" role="alert"></div>
          <div id="dz-imp-1">
            <p class="text-muted">Paste a zone file (the form name servers use, as other panels and providers export it), or choose the file. Names and host names without a final dot are read as names of the zone, as in any zone file. SOA and the zone's own NS records are left as they are here.</p>
            <textarea class="form-control mono" id="dz-imp-text" rows="12" spellcheck="false" placeholder="$TTL 14400&#10;@      IN A     192.0.2.10&#10;www    IN CNAME @&#10;@      IN MX    10 mail&#10;@      IN TXT   &quot;v=spf1 +a +mx ~all&quot;"></textarea>
            <input type="file" class="form-control mt-2" id="dz-imp-file" accept=".txt,.zone,.db,text/plain">
          </div>
          <div id="dz-imp-2" style="display:none;">
            <label class="form-check mb-2"><input class="form-check-input" type="checkbox" id="dz-imp-replace" checked>
              <span class="form-check-label">Replace the records of the same name and type <span class="text-muted">(a name and type the file has gets exactly the file's records, such as the A record the zone started with; off: the file's records are added next to them)</span></span></label>
            <div class="dz-imp-wrap"><table class="table table-sm dz-imp-tbl mb-0"><thead><tr><th class="w-1"><input type="checkbox" class="form-check-input" id="dz-imp-all" checked aria-label="All"></th><th>Name</th><th>Type</th><th>TTL</th><th>Value</th><th></th></tr></thead><tbody id="dz-imp-rows"></tbody></table></div>
            <div class="text-muted small mt-2" id="dz-imp-sum"></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-white" id="dz-imp-back" style="display:none;">Back</button>
          <button type="button" class="btn btn-primary" id="dz-imp-read">Read the file</button>
          <button type="button" class="btn btn-primary" id="dz-imp-go" style="display:none;">Import</button>
        </div>
      </div>
    </div>
  </div>

  <div class="modal modal-blur fade dz-modal" id="dz-m-export" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered" role="document">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Zone file of <span class="mono"><?=h($dz_zone)?></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body"><textarea class="form-control mono" id="dz-exp-text" rows="18" readonly spellcheck="false" style="font-size:12px;white-space:pre;"></textarea></div>
        <div class="modal-footer"><button type="button" class="btn btn-white" id="dz-exp-copy">Copy</button><button type="button" class="btn btn-primary" id="dz-exp-dl">Download</button></div>
      </div>
    </div>
  </div>

  <div class="modal modal-blur fade dz-modal" id="dz-m-soa" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
      <form class="modal-content" id="dz-soa-form" autocomplete="off" novalidate>
        <div class="modal-header"><h5 class="modal-title">SOA of <span class="mono"><?=h($dz_zone)?></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
          <div class="alert alert-danger dz-err" style="display:none;" role="alert"></div>
          <div class="mb-3"><label class="form-label" for="dz-soa-primary">Primary server</label><input class="form-control mono" id="dz-soa-primary" name="primary" maxlength="253" autocapitalize="none" spellcheck="false">
            <div class="form-hint">The name server that holds the master copy - usually the first of the zone's NS records.</div></div>
          <div class="mb-3"><label class="form-label" for="dz-soa-contact">Contact</label><input class="form-control mono" id="dz-soa-contact" name="contact" maxlength="253" autocapitalize="none" spellcheck="false">
            <div class="form-hint">The address of whoever runs the zone (written into the SOA without the @).</div></div>
          <div class="mb-3"><label class="form-label" for="dz-soa-serial">Serial</label><input class="form-control mono" id="dz-soa-serial" name="serial" inputmode="numeric" maxlength="10">
            <div class="form-hint" id="dz-soa-serial-hint"></div></div>
          <div class="row">
            <div class="col-6 mb-3"><label class="form-label" for="dz-soa-refresh">Refresh <span class="text-muted fw-normal dz-soa-h"></span></label><input class="form-control mono" id="dz-soa-refresh" name="refresh" inputmode="numeric" maxlength="8"></div>
            <div class="col-6 mb-3"><label class="form-label" for="dz-soa-retry">Retry <span class="text-muted fw-normal dz-soa-h"></span></label><input class="form-control mono" id="dz-soa-retry" name="retry" inputmode="numeric" maxlength="8"></div>
            <div class="col-6 mb-3"><label class="form-label" for="dz-soa-expire">Expire <span class="text-muted fw-normal dz-soa-h"></span></label><input class="form-control mono" id="dz-soa-expire" name="expire" inputmode="numeric" maxlength="8"></div>
            <div class="col-6 mb-3"><label class="form-label" for="dz-soa-minimum">Negative TTL <span class="text-muted fw-normal dz-soa-h"></span></label><input class="form-control mono" id="dz-soa-minimum" name="minimum" inputmode="numeric" maxlength="6"></div>
          </div>
          <div class="form-hint">In seconds. Refresh: how often secondaries check for a new serial; Retry: how soon after a failed check;
            Expire: how long they keep answering without reaching the primary; Negative TTL: how long resolvers remember that a name does not exist.</div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-white" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary" id="dz-soa-save">Save</button></div>
      </form>
    </div>
  </div>

  <div class="modal modal-blur fade dz-modal" id="dz-m-mp" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
      <form class="modal-content" id="dz-mp-form" autocomplete="off" novalidate>
        <div class="modal-header"><h5 class="modal-title" id="dz-mp-h">Mail provider</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
          <div class="alert alert-danger dz-err" style="display:none;" role="alert"></div>
          <p class="text-muted" id="dz-mp-now"></p>
          <div class="dz-opts mb-3" id="dz-mp-opts" role="radiogroup" aria-label="Mail provider"></div>
          <div class="mb-3" data-mp-for="zoho"><label class="form-label" for="dz-mp-region">Where the Zoho account is</label><select class="form-select" id="dz-mp-region"></select></div>
          <div class="mb-3" data-mp-for="microsoft"><label class="form-label" for="dz-mp-msmx">Mail server (MX)</label><input class="form-control mono" id="dz-mp-msmx" maxlength="253" autocapitalize="none" spellcheck="false">
            <div class="form-hint">As Microsoft 365 shows it for the domain; usually the domain with dashes, then .mail.protection.outlook.com.</div></div>
          <div class="mb-3" data-mp-for="other"><label class="form-label" for="dz-mp-mx">Mail servers (MX)</label><textarea class="form-control mono" id="dz-mp-mx" rows="3" spellcheck="false" placeholder="10 mx1.example.net&#10;20 mx2.example.net"></textarea>
            <div class="form-hint">One per line: the priority and the name, as the provider gives them.</div></div>
          <div class="mb-3" data-mp-for="other"><label class="form-label" for="dz-mp-spf">SPF include (optional)</label><input class="form-control mono" id="dz-mp-spf" maxlength="253" autocapitalize="none" spellcheck="false" placeholder="spf.example.net"></div>
          <div class="mb-3" data-mp-for="google microsoft zoho other"><label class="form-label" for="dz-mp-verify">Verification code (optional)</label><input class="form-control mono" id="dz-mp-verify" maxlength="1000" autocapitalize="none" spellcheck="false">
            <div class="form-hint">The TXT value the provider asks for, to show the domain is yours.</div></div>
          <div class="dz-plan" id="dz-mp-plan" style="display:none;"><b id="dz-mp-plan-h"></b><ul id="dz-mp-plan-l" class="list-unstyled mb-2"></ul><div class="alert alert-warning mb-0" id="dz-mp-plan-w" style="display:none;"></div></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal" id="dz-mp-cancel">Cancel</button><button type="submit" class="btn btn-primary" id="dz-mp-save" disabled>Switch</button></div>
      </form>
    </div>
  </div>

  <div class="modal modal-blur fade dz-modal" id="dz-m-spf" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
      <form class="modal-content dz-hp" data-kind="spf" autocomplete="off" novalidate>
        <div class="modal-header"><h5 class="modal-title" data-hp-h>SPF</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
          <div class="alert alert-danger dz-err" style="display:none;" role="alert"></div>
          <p class="text-muted">SPF tells receivers which servers may send mail for the domain. Mail from other servers fails the check.</p>
          <div class="mb-3"><span class="text-muted me-2">Now</span><code class="mono" data-hp-now></code></div>
          <label class="form-check"><input class="form-check-input" type="checkbox" name="server"><span class="form-check-label">This server <span class="text-muted">(the websites here send mail from it: contact forms, orders)</span></span></label>
          <label class="form-check"><input class="form-check-input" type="checkbox" name="mx"><span class="form-check-label">The domain's MX servers <span class="text-muted">(the servers that receive its mail also send it)</span></span></label>
          <div class="mb-3 mt-2"><div class="form-label">Services that send mail for it</div><div class="dz-opts" data-hp-senders></div></div>
          <div class="mb-3"><label class="form-label">Other servers (optional)</label><textarea class="form-control mono" name="custom" rows="2" spellcheck="false" placeholder="ip4:192.0.2.10 include:spf.example.net"></textarea>
            <div class="form-hint">Addresses (ip4:, ip6:) or includes, as the service gives them; separated by spaces.</div></div>
          <div class="mb-3"><label class="form-label">Mail from any other server</label><select class="form-select" name="all">
            <option value="~all">Mark it as suspicious (~all, recommended)</option><option value="-all">Refuse it (-all)</option><option value="?all">No opinion (?all)</option></select></div>
          <div data-hp-out style="display:none;"><div class="form-label">The record</div><div class="dz-saved mono" data-hp-rec></div><div class="form-hint" data-hp-info></div>
            <div class="alert alert-warning mt-2 mb-0" data-hp-warn style="display:none;"></div></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary" data-hp-save disabled>Save</button></div>
      </form>
    </div>
  </div>

  <div class="modal modal-blur fade dz-modal" id="dz-m-dmarc" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
      <form class="modal-content dz-hp" data-kind="dmarc" autocomplete="off" novalidate>
        <div class="modal-header"><h5 class="modal-title" data-hp-h>DMARC</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
          <div class="alert alert-danger dz-err" style="display:none;" role="alert"></div>
          <p class="text-muted">DMARC tells receivers what to do with mail that claims to come from the domain but fails both SPF and DKIM, and where to send reports about it.</p>
          <div class="mb-3"><span class="text-muted me-2">Now</span><code class="mono" data-hp-now></code></div>
          <div class="mb-3"><div class="form-label">Mail that fails the checks</div><div class="dz-opts">
            <label class="dz-opt"><input type="radio" name="p" value="none"><span>Deliver it, only report</span></label>
            <label class="dz-opt"><input type="radio" name="p" value="quarantine"><span>Spam folder</span></label>
            <label class="dz-opt"><input type="radio" name="p" value="reject"><span>Refuse it</span></label></div></div>
          <div class="mb-3"><label class="form-label">Send daily reports to (optional)</label><input class="form-control mono" name="rua" maxlength="500" autocapitalize="none" spellcheck="false">
            <div class="form-hint">One or more addresses, separated by commas.</div></div>
          <div class="row">
            <div class="col-md-4 mb-3"><label class="form-label">Subdomains</label><select class="form-select" name="sp"><option value="">As the domain</option><option value="none">Deliver, only report</option><option value="quarantine">Spam folder</option><option value="reject">Refuse</option></select></div>
            <div class="col-md-2 mb-3"><label class="form-label">Share %</label><input class="form-control mono" name="pct" type="number" min="1" max="100"></div>
            <div class="col-md-3 mb-3"><label class="form-label">DKIM alignment</label><select class="form-select" name="adkim"><option value="r">Relaxed</option><option value="s">Strict</option></select></div>
            <div class="col-md-3 mb-3"><label class="form-label">SPF alignment</label><select class="form-select" name="aspf"><option value="r">Relaxed</option><option value="s">Strict</option></select></div>
          </div>
          <div data-hp-out style="display:none;"><div class="form-label">The record <span class="text-muted mono" data-hp-name></span></div><div class="dz-saved mono" data-hp-rec></div><div class="form-hint" data-hp-info></div>
            <div class="alert alert-warning mt-2 mb-0" data-hp-warn style="display:none;"></div></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary" data-hp-save disabled>Save</button></div>
      </form>
    </div>
  </div>
<?php endif; ?>

<?php if ($dz_hidden_master && $dz_zone === ''): ?>
  <div class="modal modal-blur fade" id="modal-sync-local" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document" style="min-width:700px">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Sync zones to local PowerDNS</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
          <p id="sync-local-intro">This pulls every DNS zone from the cPanel agent and imports it into local PowerDNS:
          zones that are missing locally are created, zones that already exist are updated to match.
          SOA and NS records are kept local. This does not change anything on the cPanel server.</p>
          <pre id="sync-local-result" style="display:none;max-height:340px;overflow:auto;background:#F6F8FB;border:1px solid #DEF;border-radius:6px;padding:12px;margin:0;white-space:pre-wrap;"></pre>
        </div>
        <div class="modal-footer">
          <a href="#" class="btn btn-link link-secondary" data-bs-dismiss="modal" id="sync-local-close">Cancel</a>
          <button class="btn btn-primary" id="sync-local-start" type="button">Start sync</button>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>
</div>

<script type="application/json" id="dz-data"><?=json_encode($dz_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP)?></script>
<?php include('templates/footer.php'); ?>
<script src="<?=h($dz_js)?>"></script>
<?php if ($dz_hidden_master && $dz_zone === ''): ?>
<script>
jQuery(function ($) {
	$('#modal-sync-local').appendTo(document.body);
	$('#sync-local-start').on('click', function () {
		var btn = $(this);
		btn.prop('disabled', true).html('Syncing&hellip;');
		$('#sync-local-intro').hide();
		$('#sync-local-result').show().text('Contacting cPanel agent, please wait…');
		$.ajax({ method: 'POST', url: './ajax-dns-sync-local/', data: { action: 'ajax-dns-sync-local' } }).done(function (msg) {
			$('#sync-local-result').text(msg && msg.length ? msg : 'Done.');
			btn.hide();
			$('#sync-local-close').text('Close').removeClass('btn-link link-secondary').addClass('btn-primary').attr('data-bs-dismiss', '').on('click', function (e) {
				e.preventDefault();
				location.reload();
			});
		}).fail(function (xhr) {
			$('#sync-local-result').text('Request failed (' + xhr.status + ').');
			btn.prop('disabled', false).text('Retry');
		});
	});
});
</script>
<?php endif; ?>
</body>
</html>
