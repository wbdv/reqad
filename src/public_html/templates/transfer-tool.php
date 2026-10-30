<?php include('templates/header.php'); ?>
        <div class="page-header d-print-none">
            <div class="row align-items-center">
            	<div class="col" style="padding-left:22px;">
					<div class="page-pretitle">Backup</div>
					<h2 class="page-title">Transfer Tool</h2>
              	</div>
            </div>
        </div>

<!-- Step 1: Connection form -->
<div id="step1">
  <div class="col-12">
    <div class="card">
      <div class="card-header"><h4 class="card-title">Remote cPanel Server</h4></div>
      <div class="card-body">
        <div class="alert alert-info mb-3" role="alert">
          <strong>Before you start:</strong> The SSH public key of the <em>reqad</em> system user
          must be added to <code>root@remote-server:~/.ssh/authorized_keys</code>.
        </div>
<?php
$pubkey = transfer_ssh_pubkey();
if(!$pubkey) {
    echo '<div class="alert alert-danger mb-3">The <em>reqad</em> system user has no SSH key and one could not be made. As root, run <code>runuser -u reqad -- ssh-keygen -t ed25519 -N "" -f /usr/local/reqad/.ssh/id_ed25519</code> and open this page again.</div>';
} else {
    echo '<div style="position:relative">';
    echo '<button id="pubkey-copy-btn" type="button" title="Copy to clipboard" style="position:absolute;top:6px;right:6px;background:none;border:none;cursor:pointer;color:#6c757d;padding:4px;line-height:1;">';
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M9 5h-2a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-12a2 2 0 0 0 -2 -2h-2" /><path d="M13 3m0 2a2 2 0 0 1 2 -2h0a2 2 0 0 1 2 2v0a2 2 0 0 1 -2 2h0a2 2 0 0 1 -2 -2z" /></svg>';
    echo '</button>';
    echo '<pre id="pubkey-text" style="word-break:break-all;color:#000;display:block;line-height:1.2rem;padding-right:36px">'.htmlspecialchars($pubkey).'</pre>';
    echo '</div>';
}
?>
        <div id="tf-latest" class="alert mb-3 d-flex align-items-center" style="display:none !important"></div>
        <form id="transfer-form">
          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label">cPanel Server <span class="text-danger">*</span></label>
              <input type="text" class="form-control" id="tf-server" placeholder="hostname or IP address" required>
            </div>
            <div class="col-md-2">
              <label class="form-label">SSH Port <span class="text-danger">*</span></label>
              <input type="number" class="form-control" id="tf-port" value="22" min="1" max="65535">
            </div>
            <div class="col-md-3">
              <label class="form-label">SSH Username <span class="text-danger">*</span></label>
              <input type="text" class="form-control" id="tf-ruser" value="root">
            </div>
          </div>
          <div id="step1-error" class="alert alert-danger mb-3" style="display:none"></div>
          <button type="submit" class="btn btn-primary">
            Connect to cPanel server
          </button>
        </form>
      </div>
    </div>

    <div class="card mt-3">
      <div class="card-header">
        <h4 class="card-title">History</h4>
        <div class="card-options">
          <label class="btn btn-sm btn-outline-secondary mb-0" title="An IP map saved from a transfer (a .txt file)">
            Restore IP addresses from a file<input type="file" id="ips-file" accept=".txt,text/plain" hidden>
          </label>
        </div>
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-vcenter card-table mb-0">
          <thead><tr><th>Started</th><th>From</th><th>Accounts</th><th>Status</th><th>By</th><th class="text-end"></th></tr></thead>
          <tbody id="hist-list"><tr><td colspan="6" class="text-muted">Loading...</td></tr></tbody>
        </table>
      </div>
      <div class="card-body border-top py-2" id="hist-restores-wrap" style="display:none">
        <a href="#" id="hist-restores-toggle" class="small">IP address restores so far</a>
        <pre id="hist-restores" class="mt-2 mb-0" style="display:none;background:#1e293b;color:#e2e8f0;padding:12px 16px;font-size:12px;max-height:360px;overflow-y:auto;white-space:pre-wrap;line-height:1.5"></pre>
      </div>
    </div>
  </div>
</div>

<!-- Step 2: Loading spinner -->
<div id="step2" style="display:none">
  <div class="col-12">
    <div class="card">
      <div class="card-body text-center py-5">
        <div class="progress mb-3" style="max-width:400px;margin:auto;">
          <div class="progress-bar progress-bar-indeterminate"></div>
        </div>
        <p class="text-muted mt-3" id="step2-text">Connecting to remote server and discovering account data</p>
      </div>
    </div>
  </div>
</div>

<!-- Accounts on the cPanel server -->
<div id="step-accounts" style="display:none">
  <div class="col-12">
    <div class="card">
      <div class="card-header">
        <h4 class="card-title">Accounts on <span id="acc-server"></span></h4>
        <div class="card-options">
          <a href="#" id="btn-acc-back" class="btn btn-sm btn-outline-secondary">&#8592; Back</a>
        </div>
      </div>
      <div class="card-body">
        <div id="acc-warning" class="alert alert-warning mb-3" style="display:none"></div>
        <div class="row g-2 mb-3 align-items-center">
          <div class="col-md-4">
            <input type="search" class="form-control" id="acc-filter" placeholder="Search domain, user, package or IP">
          </div>
          <div class="col-md-3">
            <select class="form-select" id="acc-owner"><option value="">All resellers</option></select>
          </div>
          <div class="col-md-5 text-md-end text-muted" id="acc-count"></div>
        </div>
        <div class="table-responsive">
          <table class="table table-sm table-vcenter table-hover mb-0">
            <thead>
              <tr>
                <th class="w-1"><input class="form-check-input" type="checkbox" id="acc-all" title="Select all shown"></th>
                <th>Domain</th>
                <th>User</th>
                <th>Reseller</th>
                <th>Package</th>
                <th>IP</th>
                <th class="text-end">Disk used</th>
                <th></th>
              </tr>
            </thead>
            <tbody id="acc-list"></tbody>
          </table>
        </div>
        <div id="acc-error" class="alert alert-danger mt-3 mb-0" style="display:none"></div>
        <button class="btn btn-primary mt-3" id="btn-check-php" disabled>Check PHP versions</button>
      </div>
    </div>
  </div>
</div>

<!-- PHP versions of the chosen accounts -->
<div id="step-php" style="display:none">
  <div class="col-12">
    <div class="card">
      <div class="card-header">
        <h4 class="card-title">PHP versions</h4>
        <div class="card-options">
          <a href="#" id="btn-php-back" class="btn btn-sm btn-outline-secondary">&#8592; Back to accounts</a>
        </div>
      </div>
      <div class="card-body">
        <p class="text-muted mb-2">The PHP version each site uses on the cPanel server, and whether this server has it.
          For a version this server does not have, choose the one its sites get here.</p>
        <div class="table-responsive mb-4" style="max-width:820px">
          <table class="table table-sm table-vcenter mb-0">
            <thead><tr><th>PHP version</th><th class="text-end">Sites</th><th>On this server</th><th>Sites get here</th></tr></thead>
            <tbody id="php-summary"></tbody>
          </table>
        </div>
        <div id="php-accounts"></div>
        <button class="btn btn-primary mt-2" id="btn-precheck">Run pre-transfer check &rarr;</button>
      </div>
    </div>
  </div>
</div>

<!-- Pre-transfer check of the chosen accounts -->
<div id="step-pre" style="display:none">
  <div class="col-12">
    <div class="card">
      <div class="card-header">
        <h4 class="card-title">Pre-transfer check</h4>
        <div class="card-options">
          <a href="#" id="btn-pre-back" class="btn btn-sm btn-outline-secondary">&#8592; Back to PHP versions</a>
        </div>
      </div>
      <div class="card-body">
        <p class="text-muted mb-2">Read-only: nothing is changed on either server. It assumes each account keeps its user name here.
          <span class="badge bg-red-lt">blocker</span> must be solved first,
          <span class="badge bg-yellow-lt">check</span> needs a look or a step by hand,
          <span class="badge bg-blue-lt">info</span> is good to know.</p>
        <div id="pre-progress" class="mb-3 fw-bold"></div>
        <h4 class="mt-2 mb-2">The cPanel server</h4>
        <div id="pre-server"></div>
        <div id="pre-accounts"></div>
        <div id="pre-go" class="mt-4" style="display:none">
          <button class="btn btn-primary" id="btn-transfer-plan">Choose what to transfer &rarr;</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- What to transfer -->
<div id="step-plan" style="display:none">
  <div class="col-12">
    <div class="card">
      <div class="card-header">
        <h4 class="card-title">Transfer</h4>
        <div class="card-options">
          <a href="#" id="btn-plan-back" class="btn btn-sm btn-outline-secondary">&#8592; Back to the check</a>
        </div>
      </div>
      <div class="card-body">
        <p class="text-muted mb-3">Each account is made here with the <strong>same user name and password</strong>, its sites
          (addon domains, subdomains, aliases) with their PHP versions, its files, databases, mail and cron jobs, and its contact
          address (no message is sent to it; of the account's notifications only sign-ins from a new device are on). Nothing is changed on the
          cPanel server, and <strong>DNS is not touched</strong>: the sites keep working there until their DNS points here.
          The accounts are transferred one after the other in the background; you can close this page.</p>
        <div class="table-responsive mb-3">
          <table class="table table-sm table-vcenter mb-0">
            <thead>
              <tr>
                <th class="w-1"><input class="form-check-input" type="checkbox" id="plan-all" title="Select all"></th>
                <th>Account</th>
                <th class="text-end">Sites</th>
                <th class="text-end">Disk used</th>
                <th>Check</th>
                <th></th>
              </tr>
            </thead>
            <tbody id="plan-list"></tbody>
          </table>
        </div>
        <label class="form-label fw-bold">Transfer</label>
        <div class="mb-3" id="plan-parts">
<?php foreach(transfer_job_parts() as $k => $label): ?>
          <label class="form-check mb-1" style="cursor:pointer">
            <input class="form-check-input plan-part" type="checkbox" value="<?=htmlspecialchars($k)?>" checked>
            <span class="form-check-label"><?=htmlspecialchars($label)?></span>
          </label>
<?php endforeach; ?>
        </div>
        <div id="plan-php" class="mb-3" style="display:none"></div>
        <div id="plan-error" class="alert alert-danger mb-3" style="display:none"></div>
        <button class="btn btn-primary" id="btn-start">Start transfer</button>
      </div>
    </div>
  </div>
</div>

<!-- A transfer running (or done) -->
<div id="step-run" style="display:none">
  <div class="col-12">
    <div class="card">
      <div class="card-header">
        <h4 class="card-title">Transfer from <span id="run-server"></span> <span id="run-status" class="ms-2"></span></h4>
        <div class="card-options">
          <button class="btn btn-sm btn-outline-danger me-2" id="btn-stop" style="display:none">Stop</button>
          <a href="#" id="btn-run-log" class="btn btn-sm btn-outline-secondary me-2" title="Every account's log in one file">Download log</a>
          <a href="#" id="btn-run-ipmap" class="btn btn-sm btn-outline-secondary me-2" style="display:none" title="The address each site had on the cPanel server">IP map</a>
          <a href="#" id="btn-run-ips" class="btn btn-sm btn-outline-primary me-2" style="display:none">Restore IP addresses</a>
          <a href="#" id="btn-run-new" class="btn btn-sm btn-outline-secondary">Back</a>
        </div>
      </div>
      <div class="card-body">
        <div id="run-error" class="alert alert-danger mb-3" style="display:none"></div>
        <div class="table-responsive">
          <table class="table table-sm table-vcenter mb-0">
            <thead><tr><th>Account</th><th>Status</th><th>Step</th><th class="text-end"></th></tr></thead>
            <tbody id="run-list"></tbody>
          </table>
        </div>
        <details class="mt-3" id="run-joblog-wrap" style="display:none">
          <summary class="small text-muted" style="cursor:pointer">The transfer's own log</summary>
          <pre id="run-joblog" class="mt-2 mb-0" style="background:#1e293b;color:#e2e8f0;padding:12px 16px;font-size:12px;max-height:300px;overflow-y:auto;white-space:pre-wrap;line-height:1.5"></pre>
        </details>
        <div class="text-muted small mt-3">Lines marked <code>!</code> in an account's log need a look. A stopped or failed account can be
          transferred again: what is already here is kept and the rest is finished (its files, databases and mail are copied again).
          After the transfer: check the sites here (for example with a hosts file entry), then point their DNS to this server and
          issue Let's Encrypt certificates for them - or move the cPanel server's IP addresses to this server and give each
          account its address back with <strong>Restore IP addresses</strong> (the IP map keeps the address each site had), so DNS
          stays as it is.</div>
      </div>
    </div>
  </div>
</div>

<!-- Restore IP addresses from an IP map -->
<div id="step-ips" style="display:none">
  <div class="col-12">
    <div class="card">
      <div class="card-header">
        <h4 class="card-title">Restore IP addresses <span class="text-muted fw-normal small ms-2" id="ips-source"></span></h4>
        <div class="card-options">
          <a href="#" id="btn-ips-back" class="btn btn-sm btn-outline-secondary">Back</a>
        </div>
      </div>
      <div class="card-body">
        <p class="text-muted mb-3">Once the cPanel server's IP addresses have been moved to this server (by the provider) and added in
          <strong>Settings &gt; IP addresses</strong>, the restore gives each account the address that was its own on cPanel, and sends
          each domain's mail from the address it went out from there, so the DNS records can stay as they are. An address several
          accounts shared on cPanel is not given to one account: every site here answers on every address of this server.
          Nothing changes until you press <strong>Restore</strong>.</p>
        <div id="ips-hp" class="alert alert-warning mb-3" style="display:none">Giving accounts their own addresses needs
          <strong>Reqad Hosting Pro</strong> (Settings &gt; IP addresses). The check below shows what the map holds.</div>
        <div id="ips-errors" class="alert alert-warning mb-3" style="display:none"></div>
        <div id="ips-result" class="mb-3" style="display:none"></div>
        <div class="table-responsive">
          <table class="table table-sm table-vcenter mb-0">
            <thead><tr><th>What</th><th>Address on cPanel</th><th>Here</th><th>Details</th></tr></thead>
            <tbody id="ips-list"></tbody>
          </table>
        </div>
        <div class="d-flex align-items-center mt-3">
          <span class="text-muted small" id="ips-count"></span>
          <button class="btn btn-sm btn-outline-secondary ms-auto me-2" id="btn-ips-check">Check again</button>
          <button class="btn btn-primary" id="btn-ips-apply" disabled>Restore</button>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include('templates/footer.php'); ?>
<script>
jQuery(document).ready(function() {
    'use strict';

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    $('#pubkey-copy-btn').click(function() {
        var text = $('#pubkey-text').text();
        var $btn = $(this);
        if(navigator.clipboard) {
            navigator.clipboard.writeText(text).then(function() {
                $btn.find('svg').replaceWith('<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#2fb344" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 12l5 5l10 -10" /></svg>');
                setTimeout(function() {
                    $btn.find('svg').replaceWith('<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M9 5h-2a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-12a2 2 0 0 0 -2 -2h-2" /><path d="M13 3m0 2a2 2 0 0 1 2 -2h0a2 2 0 0 1 2 2v0a2 2 0 0 1 -2 2h0a2 2 0 0 1 -2 -2z" /></svg>');
                }, 2000);
            });
        }
    });

    /* The cPanel server the page is connected to: {server, port, ruser} */
    var conn = null;
    var remoteAccounts = [];

    function showOnly(id) {
        $('#step1, #step2, #step-accounts, #step-php, #step-pre, #step-plan, #step-run, #step-ips').hide();
        $('#' + id).show();
    }
    function busy(text) {
        $('#step2-text').text(text);
        showOnly('step2');
    }

    // 1. Connect: list every account on the server
    $('#transfer-form').submit(function(e) {
        e.preventDefault();
        $('#step1-error').hide();
        var c = { server: $.trim($('#tf-server').val()), port: $('#tf-port').val(), ruser: $.trim($('#tf-ruser').val()) };
        busy('Connecting to the cPanel server and listing its accounts');
        $.ajax({
            url: '/', method: 'POST', dataType: 'json',
            data: $.extend({ action: 'ajax-transfer-accounts' }, c),
            success: function(data) {
                if(data.error) {
                    showOnly('step1');
                    $('#step1-error').text(data.error).show();
                    return;
                }
                conn = { server: data.server, port: data.port, ruser: data.ruser };
                remoteAccounts = data.accounts || [];
                $('#acc-warning').text(data.warning || '').toggle(!!data.warning);
                populateAccounts(data);
                showOnly('step-accounts');
            },
            error: function() {
                showOnly('step1');
                $('#step1-error').text('Request failed. Check server logs.').show();
            }
        });
    });

    function populateAccounts(data) {
        $('#acc-server').text(data.server);
        // resellers: the owners of accounts plus resellers that own none yet
        var owners = {};
        $.each(remoteAccounts, function(i, a) { if(a.owner) owners[a.owner] = true; });
        $.each(data.resellers || [], function(i, r) { owners[r] = true; });
        var opts = '<option value="">All resellers</option>';
        $.each(Object.keys(owners).sort(), function(i, o) {
            var n = remoteAccounts.filter(function(a) { return a.owner === o; }).length;
            opts += '<option value="' + escHtml(o) + '">' + escHtml(o) + ' (' + n + ' account' + (n === 1 ? '' : 's') + ')</option>';
        });
        $('#acc-owner').html(opts).val('');
        $('#acc-filter').val('');

        var rows = '';
        $.each(remoteAccounts, function(i, a) {
            var badges = '';
            if(a.is_reseller) badges += ' <span class="badge bg-purple-lt" title="This account is a reseller">reseller</span>';
            if(a.suspended)   badges += ' <span class="badge bg-red-lt">suspended</span>';
            var here = '';
            if(a.user_here)
                here = '<span class="badge bg-yellow-lt" title="An account with this user name already exists on this server">user exists here</span>';
            else if(a.domain_here)
                here = '<span class="badge bg-yellow-lt" title="This domain is already a site of the account ' + escHtml(a.domain_here) + ' here">domain exists here</span>';
            var ip = escHtml(a.ip) + (a.ipv6 ? '<div class="text-muted small">' + escHtml(a.ipv6) + '</div>' : '');
            rows += '<tr class="acc-row" data-user="' + escHtml(a.user) + '" data-owner="' + escHtml(a.owner) + '"' +
                ' data-text="' + escHtml((a.domain + ' ' + a.user + ' ' + a.plan + ' ' + a.ip + ' ' + a.ipv6).toLowerCase()) + '">' +
                '<td><input class="form-check-input acc-check" type="checkbox" id="acc-' + i + '" value="' + escHtml(a.user) + '"></td>' +
                '<td><label for="acc-' + i + '" style="cursor:pointer">' + escHtml(a.domain) + '</label>' + badges + '</td>' +
                '<td>' + escHtml(a.user) + '</td>' +
                '<td>' + escHtml(a.owner) + '</td>' +
                '<td>' + escHtml(a.plan) + '</td>' +
                '<td>' + ip + '</td>' +
                '<td class="text-end text-nowrap">' + escHtml(a.diskused) + '</td>' +
                '<td>' + here + '</td>' +
                '</tr>';
        });
        $('#acc-list').html(rows || '<tr><td colspan="8" class="text-muted">No accounts on this server.</td></tr>');
        $('#acc-all').prop('checked', false);
        filterAccounts();
    }

    function filterAccounts() {
        var q = $.trim($('#acc-filter').val()).toLowerCase();
        var owner = $('#acc-owner').val();
        $('#acc-list .acc-row').each(function() {
            var $r = $(this);
            // a reseller's own account belongs with the accounts it owns
            var ok = (!owner || $r.attr('data-owner') === owner || $r.attr('data-user') === owner) &&
                     (!q || String($r.attr('data-text')).indexOf(q) !== -1);
            $r.toggle(ok);
        });
        updateAccountCount();
    }

    function updateAccountCount() {
        var shown = $('#acc-list .acc-row:visible').length;
        var sel = $('.acc-check:checked').length;
        $('#acc-count').text(shown + ' of ' + remoteAccounts.length + ' shown, ' + sel + ' selected');
        $('#btn-check-php').prop('disabled', sel === 0)
            .text(sel ? 'Check PHP versions of ' + sel + ' account' + (sel === 1 ? '' : 's') : 'Check PHP versions');
        var $vis = $('#acc-list .acc-row:visible .acc-check');
        $('#acc-all').prop('checked', $vis.length > 0 && $vis.filter(':checked').length === $vis.length);
    }

    $('#acc-filter').on('input', filterAccounts);
    $('#acc-owner').on('change', filterAccounts);
    $(document).on('change', '.acc-check', updateAccountCount);
    $('#acc-all').on('change', function() {
        $('#acc-list .acc-row:visible .acc-check').prop('checked', this.checked);
        updateAccountCount();
    });

    $('#btn-acc-back').click(function(e) {
        e.preventDefault();
        showOnly('step1');
    });

    // 2. PHP version of each site of the chosen accounts
    $('#btn-check-php').click(function() {
        var users = $('.acc-check:checked').map(function() { return $(this).val(); }).get();
        if(!users.length) return;
        $('#acc-error').hide();
        busy('Reading the PHP version of each site on the cPanel server');
        $.ajax({
            url: '/', method: 'POST', dataType: 'json',
            data: $.extend({ action: 'ajax-transfer-php', accounts: users }, conn),
            success: function(data) {
                if(data.error) {
                    showOnly('step-accounts');
                    $('#acc-error').text(data.error).show();
                    return;
                }
                populatePhp(data);
                showOnly('step-php');
            },
            error: function() {
                showOnly('step-accounts');
                $('#acc-error').text('Request failed. Check server logs.').show();
            }
        });
    });

    function phpHereBadge(st, ended) {
        if(st === 'installed')
            return '<span class="badge bg-green-lt">installed</span>' +
                   (ended ? ' <span class="badge bg-yellow-lt" title="This version no longer gets security fixes">end of life</span>' : '');
        if(st === 'installable')
            return '<span class="badge bg-orange-lt">not installed</span> <a href="/php-settings/" class="small">install it in PHP Settings</a>';
        return '<span class="badge bg-red-lt">not available</span> <span class="small text-muted">this server cannot install it</span>';
    }

    var siteTypes = { main: 'Main domain', addon: 'Addon domain', sub: 'Subdomain', parked: 'Alias' };

    /* a version this server does not have -> the one its sites get here
       (sent with the check and the transfer: php_map) */
    var phpMap = {};

    function phpUseSelect(v, info, local) {
        if(!local.length) return '<span class="text-danger small">no PHP version is installed here</span>';
        var opts = local.map(function(l) {
            return '<option value="' + escHtml(l) + '"' + (l === phpMap[v] ? ' selected' : '') + '>PHP ' + escHtml(l) + '</option>';
        }).join('');
        return '<select class="form-select form-select-sm php-use" data-v="' + escHtml(v) + '" style="width:auto;display:inline-block" ' +
               'aria-label="PHP version the PHP ' + escHtml(v) + ' sites get here">' + opts + '</select>';
    }

    function populatePhp(data) {
        phpData = data;
        phpMap = {};
        var local = (data.local_versions || []).slice().sort(function(a, b) {
            var x = a.split('.').map(Number), y = b.split('.').map(Number);
            return (y[0] - x[0]) || (y[1] - x[1]);
        });
        var sum = '';
        $.each(data.versions || {}, function(v, info) {
            if(info.status !== 'installed' && info.instead) phpMap[v] = info.instead;
            sum += '<tr><td><span class="badge bg-blue-lt">PHP ' + escHtml(v) + '</span>' +
                   (v === data.system_default ? ' <span class="text-muted small">cPanel server default</span>' : '') + '</td>' +
                   '<td class="text-end">' + info.sites + '</td>' +
                   '<td>' + phpHereBadge(info.status, info.ended) + '</td>' +
                   '<td>' + (info.status === 'installed' ? '<span class="text-muted">PHP ' + escHtml(v) + '</span>' : phpUseSelect(v, info, local)) + '</td></tr>';
        });
        $('#php-summary').html(sum || '<tr><td colspan="4" class="text-muted">No sites found.</td></tr>');

        var html = '';
        $.each(data.accounts || [], function(i, a) {
            html += '<div class="d-flex align-items-center mt-3 mb-2">' +
                '<h4 class="mb-0">' + escHtml(a.domain || a.user) + ' <span class="text-muted fw-normal">(' + escHtml(a.user) + ')</span></h4>' +
                '</div>';
            if(!a.sites.length) {
                html += '<div class="alert alert-warning">No sites found for this account on the cPanel server.</div>';
                return;
            }
            html += '<div class="table-responsive"><table class="table table-sm table-vcenter mb-3">' +
                '<thead><tr><th>Domain</th><th>Type</th><th>Document root</th><th>PHP on cPanel</th><th>On this server</th></tr></thead><tbody>';
            $.each(a.sites, function(j, s) {
                var info = [];
                if(s.source) info.push(escHtml(s.source));
                if(s.fpm) info.push('PHP-FPM');
                var note = '';
                if(s.htaccess)
                    note = s.fpm
                        ? '<div class="small text-muted">.htaccess names PHP ' + escHtml(s.htaccess) + ', but PHP-FPM uses the version above</div>'
                        : '<div class="small text-warning">set in .htaccess (cPanel\'s setting is ' + escHtml(s.package) + ')</div>';
                var ver = s.version
                    ? '<span class="badge bg-blue-lt">PHP ' + escHtml(s.version) + '</span>'
                    : '<span class="text-muted">' + escHtml(s.package || 'unknown') + '</span>';
                var here = s.version ? phpHereBadge(s.local, (data.versions[s.version] || {}).ended) : '';
                if(s.version && s.local !== 'installed')
                    here += '<div class="small">gets <span class="php-use-to" data-v="' + escHtml(s.version) + '">PHP ' + escHtml(phpMap[s.version] || '?') + '</span> here</div>';
                html += '<tr>' +
                    '<td>' + escHtml(s.domain) + '</td>' +
                    '<td class="text-nowrap">' + escHtml(siteTypes[s.type] || s.type) + '</td>' +
                    '<td><code style="font-size:11px">' + escHtml(s.docroot) + '</code></td>' +
                    '<td class="text-nowrap">' + ver + (info.length ? ' <span class="small text-muted">' + info.join(', ') + '</span>' : '') + note + '</td>' +
                    '<td>' + here + '</td>' +
                    '</tr>';
            });
            html += '</tbody></table></div>';
        });
        $('#php-accounts').html(html);
    }

    $(document).on('change', '.php-use', function() {
        var v = this.getAttribute('data-v');   // not .data(): it makes "8.10" the number 8.1
        phpMap[v] = this.value;
        $('.php-use-to').filter(function() { return this.getAttribute('data-v') === v; }).text('PHP ' + this.value);
    });

    $('#btn-php-back').click(function(e) {
        e.preventDefault();
        showOnly('step-accounts');
    });

    // 2b. Pre-transfer check: the server once, then each account in turn
    var phpData = null;
    var preCtx = null;
    var preRun = 0;          // a newer run makes the answers of an older one stale
    var preResult = {};       // account -> {block, warn} from its check
    var preAreas = ['Account', 'Domains', 'DNS', 'Features', 'Mail', 'PHP', 'Databases', 'Cron jobs', 'Size', 'Certificates', 'Server'];
    var preRank = { block: 0, warn: 1, info: 2, ok: 3 };
    var preBadge = {
        block: '<span class="badge bg-red-lt">blocker</span>',
        warn:  '<span class="badge bg-yellow-lt">check</span>',
        info:  '<span class="badge bg-blue-lt">info</span>',
        ok:    '<span class="badge bg-green-lt">ok</span>'
    };

    function renderPreItems(items) {
        items = (items || []).slice().sort(function(a, b) {
            var x = preAreas.indexOf(a.area), y = preAreas.indexOf(b.area);
            return (x - y) || (preRank[a.level] - preRank[b.level]);
        });
        var html = '<div class="table-responsive"><table class="table table-sm table-vcenter mb-3"><tbody>';
        $.each(items, function(i, it) {
            var list = '';
            if(it.list && it.list.length) {
                list = it.code
                    ? '<pre class="small mb-0 mt-1" style="white-space:pre-wrap;padding:8px">' + escHtml(it.list.join('\n')) + '</pre>'
                    : '<ul class="small mb-0 mt-1 ps-3">' + it.list.map(function(l) { return '<li>' + escHtml(l) + '</li>'; }).join('') + '</ul>';
                if(it.list.length > 8)
                    list = '<details class="mt-1"><summary class="small text-muted" style="cursor:pointer">' + it.list.length + ' lines</summary>' + list + '</details>';
            }
            html += '<tr>' +
                '<td class="w-1 align-top">' + (preBadge[it.level] || '') + '</td>' +
                '<td class="w-1 align-top text-muted small text-nowrap">' + escHtml(it.area) + '</td>' +
                '<td class="align-top"><div>' + escHtml(it.title) + '</div>' +
                    (it.detail ? '<div class="small text-muted">' + escHtml(it.detail) + '</div>' : '') + list + '</td>' +
                '</tr>';
        });
        return html + '</tbody></table></div>';
    }

    function preCounts(items) {
        var c = { block: 0, warn: 0 };
        $.each(items || [], function(i, it) { if(c[it.level] !== undefined) c[it.level]++; });
        return c;
    }

    $('#btn-precheck').click(function() {
        if(!phpData) return;
        var run = ++preRun;
        showOnly('step-pre');
        $('#pre-accounts').html('');
        $('#pre-go').hide();
        preResult = {};
        $('#pre-server').html('<p class="text-muted">Checking the cPanel server&hellip;</p>');
        $('#pre-progress').text('');
        var pkgs = {};
        $.each(phpData.accounts || [], function(i, a) {
            $.each(a.sites, function(j, s) { if(s.package) pkgs[s.package] = true; });
        });
        $.ajax({
            url: '/', method: 'POST', dataType: 'json',
            data: $.extend({ action: 'ajax-transfer-precheck', part: 'server', pkgs: Object.keys(pkgs), php_map: JSON.stringify(phpMap) }, conn),
            success: function(data) {
                if(run !== preRun) return;
                if(data.error) { $('#pre-server').html('<div class="alert alert-danger">' + escHtml(data.error) + '</div>'); return; }
                preCtx = data.ctx || {};
                $('#pre-server').html(renderPreItems(data.items));
                var list = (phpData.accounts || []).filter(function(a) { return a.sites.length; });
                runPreAccount(run, list, 0, { block: 0, warn: 0 });
            },
            error: function() {
                if(run !== preRun) return;
                $('#pre-server').html('<div class="alert alert-danger">Request failed. Check server logs.</div>');
            }
        });
    });

    function runPreAccount(run, list, i, total) {
        if(run !== preRun) return;
        if(i >= list.length) {
            $('#pre-progress').html('Done: ' + list.length + ' account' + (list.length === 1 ? '' : 's') + ' checked, ' +
                (total.block ? '<span class="text-danger">' + total.block + ' blocker' + (total.block === 1 ? '' : 's') + '</span>' : 'no blockers') +
                ', ' + total.warn + ' to check.');
            $('#pre-go').show();
            return;
        }
        var a = list[i];
        $('#pre-progress').text('Checking account ' + (i + 1) + ' of ' + list.length + ': ' + a.user + '…');
        var $box = $('<div class="pre-account"></div>').appendTo('#pre-accounts');
        $box.html('<div class="d-flex align-items-center mt-4 mb-2"><h4 class="mb-0">' + escHtml(a.domain || a.user) +
            ' <span class="text-muted fw-normal">(' + escHtml(a.user) + ')</span></h4>' +
            '<span class="ms-2 pre-counts"></span></div>' +
            '<div class="pre-body"><p class="text-muted">Checking&hellip;</p></div>');
        var acc = remoteAccounts.filter(function(r) { return r.user === a.user; })[0] || {};
        var sites = a.sites.map(function(s) { return { domain: s.domain, type: s.type, version: s.version }; });
        $.ajax({
            url: '/', method: 'POST', dataType: 'json', timeout: 600000,
            data: $.extend({ action: 'ajax-transfer-precheck', part: 'account', user: a.user, ip: acc.ip || '',
                             sites: JSON.stringify(sites), ctx: JSON.stringify(preCtx), php_map: JSON.stringify(phpMap) }, conn),
            success: function(data) {
                if(run !== preRun) return;
                if(data.error) {
                    $box.find('.pre-body').html('<div class="alert alert-danger">' + escHtml(data.error) + '</div>');
                } else {
                    var c = preCounts(data.items);
                    preResult[a.user] = c;
                    total.block += c.block; total.warn += c.warn;
                    $box.find('.pre-counts').html(
                        (c.block ? '<span class="badge bg-red-lt">' + c.block + ' blocker' + (c.block === 1 ? '' : 's') + '</span> ' : '') +
                        (c.warn ? '<span class="badge bg-yellow-lt">' + c.warn + ' to check</span>' : '') +
                        (!c.block && !c.warn ? '<span class="badge bg-green-lt">ready</span>' : ''));
                    $box.find('.pre-body').html(renderPreItems(data.items));
                }
                runPreAccount(run, list, i + 1, total);
            },
            error: function() {
                if(run !== preRun) return;
                $box.find('.pre-body').html('<div class="alert alert-danger">Request failed. Check server logs.</div>');
                runPreAccount(run, list, i + 1, total);
            }
        });
    }

    $('#btn-pre-back').click(function(e) {
        e.preventDefault();
        preRun++;
        showOnly('step-php');
    });

    // 4. The transfer: choose the accounts, then follow the job
    var siteCount = {};
    $('#btn-transfer-plan').click(function() {
        var rows = '';
        $.each((phpData && phpData.accounts) || [], function(i, a) {
            if(!a.sites.length) return;
            var acc = remoteAccounts.filter(function(r) { return r.user === a.user; })[0] || {};
            var c = preResult[a.user];
            var badName = !/^[a-z][a-z0-9]{1,15}$/.test(a.user);
            var note = '';
            if(badName) note = '<span class="text-danger small">the user name cannot be used here</span>';
            else if(acc.user_here) note = '<span class="text-warning small">exists here: continued only if a transfer made it</span>';
            else if(acc.domain_here) note = '<span class="text-danger small">' + escHtml(a.domain) + ' is a site of ' + escHtml(acc.domain_here) + ' here</span>';
            var check = !c ? '<span class="text-muted small">not checked</span>' :
                (c.block ? '<span class="badge bg-red-lt">' + c.block + ' blocker' + (c.block === 1 ? '' : 's') + '</span> ' : '') +
                (c.warn ? '<span class="badge bg-yellow-lt">' + c.warn + ' to check</span>' : '') +
                (!c.block && !c.warn ? '<span class="badge bg-green-lt">ready</span>' : '');
            var on = !badName && !acc.user_here && !acc.domain_here;
            rows += '<tr><td><input class="form-check-input plan-check" type="checkbox" value="' + escHtml(a.user) + '"' +
                (on ? ' checked' : '') + (badName || acc.domain_here ? ' disabled' : '') + '></td>' +
                '<td>' + escHtml(a.domain || a.user) + ' <span class="text-muted">(' + escHtml(a.user) + ')</span></td>' +
                '<td class="text-end">' + a.sites.length + '</td>' +
                '<td class="text-end text-nowrap">' + escHtml(acc.diskused || '') + '</td>' +
                '<td>' + check + '</td><td>' + note + '</td></tr>';
        });
        $('#plan-list').html(rows || '<tr><td colspan="6" class="text-muted">No accounts with sites.</td></tr>');
        var swaps = $.map(phpMap, function(to, from) { return 'PHP ' + escHtml(from) + ' sites get PHP ' + escHtml(to); });
        $('#plan-php').html(swaps.length ? '<span class="badge bg-blue-lt">PHP</span> <span class="small">' + swaps.join(', ') +
            ' (not installed here; change it on the PHP versions step).</span>' : '').toggle(swaps.length > 0);
        $('#plan-error').hide();
        planCount();
        showOnly('step-plan');
    });

    function planCount() {
        var n = $('.plan-check:checked').length;
        var $en = $('.plan-check:not(:disabled)');
        $('#plan-all').prop('checked', $en.length > 0 && $en.filter(':checked').length === $en.length);
        $('#btn-start').prop('disabled', n === 0).text(n ? 'Start transfer of ' + n + ' account' + (n === 1 ? '' : 's') : 'Start transfer');
    }
    $(document).on('change', '.plan-check', planCount);
    $('#plan-all').on('change', function() {
        $('.plan-check:not(:disabled)').prop('checked', this.checked);
        planCount();
    });
    $('#btn-plan-back').click(function(e) { e.preventDefault(); showOnly('step-pre'); });

    $('#btn-start').click(function() {
        var users = $('.plan-check:checked').map(function() { return $(this).val(); }).get();
        var parts = $('.plan-part:checked').map(function() { return $(this).val(); }).get();
        if(!users.length) return;
        if(!parts.length) { $('#plan-error').text('Choose at least one thing to transfer.').show(); return; }
        var accounts = users.map(function(u) {
            var acc = remoteAccounts.filter(function(r) { return r.user === u; })[0] || {};
            return { user: u, domain: acc.domain || '', diskused: acc.diskused || '' };
        });
        $('#btn-start').prop('disabled', true);
        $.ajax({
            url: '/', method: 'POST', dataType: 'json',
            data: $.extend({ action: 'ajax-transfer-start', accounts: JSON.stringify(accounts), parts: parts, php_map: JSON.stringify(phpMap) }, conn),
            success: function(r) {
                $('#btn-start').prop('disabled', false);
                if(r.error) { $('#plan-error').text(r.error).show(); return; }
                showJob(r.id);
            },
            error: function() {
                $('#btn-start').prop('disabled', false);
                $('#plan-error').text('Request failed. Check server logs.').show();
            }
        });
    });

    var job = { id: '', offsets: {}, timer: null, open: {} };
    var runBadge = {
        queued:  '<span class="badge bg-secondary-lt">waiting</span>',
        running: '<span class="badge bg-blue-lt">running</span>',
        done:    '<span class="badge bg-green-lt">done</span>',
        warn:    '<span class="badge bg-yellow-lt">done, to check</span>',
        failed:  '<span class="badge bg-red-lt">failed</span>',
        stopped: '<span class="badge bg-secondary-lt">stopped</span>'
    };

    function showJob(id) {
        if(job.timer) clearTimeout(job.timer);
        job = { id: id, offsets: {}, timer: null, open: {} };
        $('#run-list').html('');
        $('#run-error').hide();
        showOnly('step-run');
        pollJob();
    }

    function pollJob() {
        var id = job.id;
        $.ajax({
            url: '/', method: 'POST', dataType: 'json',
            data: { action: 'ajax-transfer-status', id: id, offsets: JSON.stringify(job.offsets) },
            success: function(d) {
                if(id !== job.id) return;
                if(d.error) { $('#run-error').text(d.error).show(); return; }
                renderJob(d);
                if(d.active) job.timer = setTimeout(pollJob, 2000);
            },
            error: function() {
                if(id === job.id) job.timer = setTimeout(pollJob, 5000);
            }
        });
    }

    function renderJob(d) {
        $('#run-server').text(d.server);
        $('#btn-run-ipmap, #btn-run-ips').toggle(!!d.ips);
        if(d.joblog) {
            var $jl = $('#run-joblog'), end = $jl[0].scrollTop + $jl[0].clientHeight >= $jl[0].scrollHeight - 20;
            $jl.text(d.joblog);
            if(end) $jl[0].scrollTop = $jl[0].scrollHeight;
        }
        $('#run-joblog-wrap').toggle(!!d.joblog);
        $('#run-status').html(d.active ? runBadge.running : (runBadge[d.status] || ''));
        $('#btn-stop').toggle(!!d.active);
        $.each(d.accounts, function(i, a) {
            var $r = $('#run-list tr.run-row[data-user="' + a.user + '"]');
            if(!$r.length) {
                $('#run-list').append(
                    '<tr class="run-row" data-user="' + escHtml(a.user) + '" style="cursor:pointer">' +
                    '<td>' + escHtml(a.domain || a.user) + ' <span class="text-muted">(' + escHtml(a.user) + ')</span></td>' +
                    '<td class="run-st"></td><td class="run-step small"></td>' +
                    '<td class="text-end"><a href="#" class="small run-toggle">log</a></td></tr>' +
                    '<tr class="run-logrow" data-user="' + escHtml(a.user) + '" style="display:none"><td colspan="4" class="p-0">' +
                    '<pre class="run-log mb-0" style="background:#1e293b;color:#e2e8f0;padding:12px 16px;border-radius:0;font-size:12px;max-height:420px;overflow-y:auto;white-space:pre-wrap;line-height:1.5"></pre></td></tr>');
                $r = $('#run-list tr.run-row[data-user="' + a.user + '"]');
            }
            $r.find('.run-st').html((runBadge[a.status] || escHtml(a.status)) +
                (a.warnings ? ' <span class="text-warning small">' + a.warnings + ' !</span>' : ''));
            $r.find('.run-step').text(a.status === 'running' ? a.step + (a.detail ? ' - ' + a.detail : '') : (a.status === 'queued' ? '' : a.step));
            if(a.log) {
                var $pre = $('#run-list tr.run-logrow[data-user="' + a.user + '"] pre');
                var atEnd = $pre[0].scrollTop + $pre[0].clientHeight >= $pre[0].scrollHeight - 20;
                $pre.text($pre.text() + a.log);
                if(atEnd) $pre[0].scrollTop = $pre[0].scrollHeight;
            }
            job.offsets[a.user] = a.offset;
            // follow the account that is running, unless the log was closed by hand
            if(a.status === 'running' && job.open[a.user] === undefined) {
                job.open[a.user] = true;
                $('#run-list tr.run-logrow[data-user="' + a.user + '"]').show();
            }
        });
    }

    $(document).on('click', '#run-list tr.run-row', function(e) {
        e.preventDefault();
        var u = $(this).attr('data-user');
        var $l = $('#run-list tr.run-logrow[data-user="' + u + '"]');
        $l.toggle();
        job.open[u] = $l.is(':visible');
        if(job.open[u]) { var p = $l.find('pre')[0]; p.scrollTop = p.scrollHeight; }
    });

    $('#btn-stop').click(function() {
        if(!confirm('Stop the transfer? The account being transferred is left half done; start it again later to finish it.')) return;
        $.post('/', { action: 'ajax-transfer-stop', id: job.id }, null, 'json').always(function() {
            if(job.timer) clearTimeout(job.timer);
            setTimeout(pollJob, 1500);
        });
    });

    $('#btn-run-new').click(function(e) {
        e.preventDefault();
        if(job.timer) clearTimeout(job.timer);
        job.id = '';
        loadLatest();
        loadHistory();
        showOnly('step1');
    });
    $('#btn-run-log').click(function(e) { e.preventDefault(); getFile(job.id, 'log'); });
    $('#btn-run-ipmap').click(function(e) { e.preventDefault(); getFile(job.id, 'ips'); });
    $('#btn-run-ips').click(function(e) {
        e.preventDefault();
        if(job.timer) clearTimeout(job.timer);
        ipsOpen({ id: job.id }, 'step-run');
    });

    /* ---- files: a transfer's log or IP map, saved by the browser ---- */
    function saveText(name, text) {
        var a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob([text], { type: 'text/plain' }));
        a.download = name;
        document.body.appendChild(a);
        a.click();
        setTimeout(function() { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
    }
    function getFile(id, kind, user) {
        $.post('/', { action: 'ajax-transfer-file', id: id, kind: kind, user: user || '' }, null, 'json').done(function(d) {
            if(!d || d.error) { alert((d && d.error) || 'Request failed.'); return; }
            saveText(d.name, d.text);
        }).fail(function() { alert('Request failed. Check server logs.'); });
    }

    /* ---- history: every transfer, newest first ---- */
    function loadHistory() {
        $.post('/', { action: 'ajax-transfer-history' }, null, 'json').done(function(d) {
            var rows = '';
            $.each((d && d.jobs) || [], function(i, j) {
                var st = j.active ? runBadge.running : (j.status === 'done'
                    ? (j.failed ? runBadge.failed.replace('failed', 'done, ' + j.failed + ' failed') : (j.warn ? runBadge.warn : runBadge.done))
                    : (runBadge[j.status] || escHtml(j.status)));
                var acc = j.users.slice(0, 4).join(', ') + (j.users.length > 4 ? ' +' + (j.users.length - 4) : '');
                rows += '<tr><td class="text-nowrap">' + escHtml(new Date(j.created * 1000).toLocaleString()) + '</td>' +
                    '<td>' + escHtml(j.server) + '</td>' +
                    '<td><span title="' + escHtml(j.users.join(', ')) + '">' + escHtml(acc) + '</span>' +
                    (j.accounts > 1 ? ' <span class="text-muted small">(' + j.accounts + ')</span>' : '') + '</td>' +
                    '<td>' + st + (j.warnings ? ' <span class="text-warning small">' + j.warnings + ' !</span>' : '') + '</td>' +
                    '<td class="text-muted">' + escHtml(j.by || '') + '</td>' +
                    '<td class="text-end text-nowrap">' +
                    '<a href="#" class="small me-2 hist-view" data-id="' + j.id + '">View</a>' +
                    '<a href="#" class="small me-2 hist-log" data-id="' + j.id + '">Log</a>' +
                    (j.ips ? '<a href="#" class="small me-2 hist-ipmap" data-id="' + j.id + '">IP map</a>' +
                             '<a href="#" class="small hist-ips" data-id="' + j.id + '">Restore IP addresses</a>' : '') +
                    '</td></tr>';
            });
            $('#hist-list').html(rows || '<tr><td colspan="6" class="text-muted">No transfers yet.</td></tr>');
            var r = (d && d.restores) || '';
            $('#hist-restores').text(r);
            $('#hist-restores-wrap').toggle(r !== '');
        }).fail(function() {
            $('#hist-list').html('<tr><td colspan="6" class="text-danger">Could not load the history.</td></tr>');
        });
    }
    $(document).on('click', '.hist-view', function(e) { e.preventDefault(); showJob($(this).attr('data-id')); });
    $(document).on('click', '.hist-log', function(e) { e.preventDefault(); getFile($(this).attr('data-id'), 'log'); });
    $(document).on('click', '.hist-ipmap', function(e) { e.preventDefault(); getFile($(this).attr('data-id'), 'ips'); });
    $(document).on('click', '.hist-ips', function(e) { e.preventDefault(); ipsOpen({ id: $(this).attr('data-id') }, 'step1'); });
    $('#hist-restores-toggle').click(function(e) {
        e.preventDefault();
        var $p = $('#hist-restores').toggle();
        if($p.is(':visible')) $p[0].scrollTop = $p[0].scrollHeight;
    });
    $('#ips-file').change(function() {
        var f = this.files && this.files[0];
        this.value = '';
        if(!f) return;
        if(f.size > 2097152) { alert('The file is too big for an IP map (2 MB at most).'); return; }
        var rd = new FileReader();
        rd.onload = function() { ipsOpen({ text: String(rd.result), name: f.name }, 'step1'); };
        rd.readAsText(f);
    });

    /* ---- restore IP addresses ---- */
    var ips = { src: null, back: 'step1' };
    var ipsBadge = {
        done:    '<span class="badge bg-green-lt">as on cPanel</span>',
        ready:   '<span class="badge bg-blue-lt">to restore</span>',
        missing: '<span class="badge bg-secondary-lt">not here yet</span>',
        taken:   '<span class="badge bg-red-lt">another account has it</span>',
        warn:    '<span class="badge bg-yellow-lt">look</span>',
        skip:    '<span class="badge bg-secondary-lt">left out</span>'
    };
    function ipsOpen(src, back) {
        ips = { src: src, back: back };
        $('#ips-source').text(src.id ? 'transfer of ' + (src.id) : 'file ' + (src.name || ''));
        $('#ips-result').hide().html('');
        showOnly('step-ips');
        ipsCheck();
    }
    function ipsRender(d) {
        $('#ips-errors').html(d.errors && d.errors.length ? 'Lines left out of the map: ' + d.errors.slice(0, 8).map(escHtml).join('; ') +
            (d.errors.length > 8 ? ' and ' + (d.errors.length - 8) + ' more.' : '.') : '').toggle(!!(d.errors && d.errors.length));
        var p = d.plan;
        if(!p) return;
        $('#ips-hp').toggle(!p.hosting);
        var rows = '';
        $.each(p.accounts, function(i, a) {
            rows += '<tr class="table-light"><td colspan="4"><strong>' + escHtml(a.domain) + '</strong> <span class="text-muted">(' + escHtml(a.account) + ')</span>' +
                (a.domains.length > 1 ? ' <span class="text-muted small">' + escHtml(a.domains.length + ' sites') + '</span>' : '') + '</td></tr>';
            $.each(a.items, function(j, it) {
                rows += '<tr><td>' + escHtml(it.what) + '</td><td><code>' + escHtml(it.address || '') + '</code></td>' +
                    '<td class="text-nowrap">' + (ipsBadge[it.state] || escHtml(it.state)) + '</td>' +
                    '<td class="small text-muted">' + escHtml(it.text) + '</td></tr>';
            });
            if(!a.items.length)
                rows += '<tr><td colspan="4" class="small text-muted">No addresses in the map for this account.</td></tr>';
        });
        $('#ips-list').html(rows || '<tr><td colspan="4" class="text-muted">The map has no accounts.</td></tr>');
        var c = p.counts;
        $('#ips-count').text(c.ready + ' to restore, ' + c.done + ' as on cPanel already, ' + c.missing + ' not on this server yet' +
            (c.taken ? ', ' + c.taken + ' given to another account' : '') + (c.warn ? ', ' + c.warn + ' to look at' : '') + '.');
        $('#btn-ips-apply').prop('disabled', !p.hosting || !c.ready).text(c.ready ? 'Restore ' + c.ready : 'Restore');
    }
    function ipsPost(apply, done) {
        var data = $.extend({ action: 'ajax-transfer-ips', apply: apply ? '1' : '0' }, ips.src.id ? { id: ips.src.id } : { text: ips.src.text, name: ips.src.name });
        return $.ajax({ url: '/', method: 'POST', dataType: 'json', data: data, success: done,
            error: function() { $('#ips-result').html('<div class="alert alert-danger mb-0">Request failed. Check server logs.</div>').show(); } });
    }
    function ipsCheck() {
        $('#ips-list').html('<tr><td colspan="4" class="text-muted">Checking...</td></tr>');
        $('#btn-ips-apply').prop('disabled', true);
        ipsPost(false, function(d) {
            if(d.error) { $('#ips-list').html(''); $('#ips-result').html('<div class="alert alert-danger mb-0">' + escHtml(d.error) + '</div>').show(); return; }
            ipsRender(d);
        });
    }
    $('#btn-ips-check').click(function(e) { e.preventDefault(); $('#ips-result').hide(); ipsCheck(); });
    $('#btn-ips-apply').click(function(e) {
        e.preventDefault();
        if(!confirm('Give the accounts their addresses and add the outgoing mail rules marked "to restore"?')) return;
        var $b = $(this).prop('disabled', true).text('Restoring...');
        ipsPost(true, function(d) {
            if(d.error) { $('#ips-result').html('<div class="alert alert-danger mb-0">' + escHtml(d.error) + '</div>').show(); $b.text('Restore'); return; }
            var h = '<div class="alert ' + (d.ok ? (d.warnings && d.warnings.length ? 'alert-warning' : 'alert-success') : 'alert-danger') + ' mb-0">' +
                '<strong>' + escHtml(d.message) + '</strong>';
            if(d.results && d.results.length)
                h += '<ul class="mb-0 mt-1">' + d.results.map(function(r) {
                    return '<li>' + (r.ok ? '' : '<strong class="text-danger">Failed:</strong> ') + escHtml(r.what) + ': ' + escHtml(r.text) + '</li>';
                }).join('') + '</ul>';
            if(d.warnings && d.warnings.length)
                h += '<div class="mt-1 small">' + d.warnings.map(escHtml).join('<br>') + '</div>';
            $('#ips-result').html(h + '</div>').show();
            ipsRender(d);
        });
    });
    $('#btn-ips-back').click(function(e) {
        e.preventDefault();
        if(ips.back === 'step-run' && job.id) { showOnly('step-run'); pollJob(); return; }
        loadHistory();
        showOnly('step1');
    });

    // the newest transfer, shown above the connection form
    function loadLatest() {
        $.post('/', { action: 'ajax-transfer-status', id: '', offsets: '{}' }, null, 'json').done(function(d) {
            if(!d || d.none || d.error) { $('#tf-latest').attr('style', 'display:none !important'); return; }
            var n = d.accounts.length, ok = 0;
            $.each(d.accounts, function(i, a) { if(a.status === 'done' || a.status === 'warn') ok++; });
            $('#tf-latest').removeClass('alert-info alert-secondary').addClass(d.active ? 'alert-info' : 'alert-secondary').attr('style', '')
                .html('<div>' + (d.active ? '<strong>A transfer is running</strong>' : 'Last transfer') + ' from ' + escHtml(d.server) +
                      ': ' + ok + ' of ' + n + ' account' + (n === 1 ? '' : 's') + ' done (' + new Date(d.created * 1000).toLocaleString() + ').</div>' +
                      '<a href="#" class="btn btn-sm btn-outline-primary ms-auto" id="btn-latest" data-id="' + escHtml(d.id) + '">View</a>');
        });
    }
    $(document).on('click', '#btn-latest', function(e) {
        e.preventDefault();
        showJob($(this).attr('data-id'));
    });
    loadLatest();
    loadHistory();
});
</script>
</body>
</html>
