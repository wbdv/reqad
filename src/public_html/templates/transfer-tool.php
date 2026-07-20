<?php
include('templates/header.php');
$local_accounts = [];
$_res = $db->query('SELECT user, domain, has_email FROM accounts ORDER BY domain');
while($_row = $_res->fetchArray()) {
    $local_accounts[] = ['user' => $_row['user'], 'domain' => $_row['domain'], 'has_email' => $_row['has_email']];
}
?>
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
          You can find and manage this server's SSH keys under <a href="/ssh-keys/">SSH Keys</a>.
        </div>
<?php
$pubkey_files = ['/usr/local/reqad/.ssh/id_rsa.pub', '/usr/local/reqad/.ssh/id_ed25519.pub'];
$pubkey = '';
foreach($pubkey_files as $f) {
    if(is_file($f)) { $pubkey = trim(file_get_contents($f)); break; }
}
if($pubkey) {
    echo '<div style="position:relative">';
    echo '<button id="pubkey-copy-btn" type="button" title="Copy to clipboard" style="position:absolute;top:6px;right:6px;background:none;border:none;cursor:pointer;color:#6c757d;padding:4px;line-height:1;">';
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M9 5h-2a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-12a2 2 0 0 0 -2 -2h-2" /><path d="M13 3m0 2a2 2 0 0 1 2 -2h0a2 2 0 0 1 2 2v0a2 2 0 0 1 -2 2h0a2 2 0 0 1 -2 -2z" /></svg>';
    echo '</button>';
    echo '<pre id="pubkey-text" style="word-break:break-all;color:#000;display:block;line-height:1.2rem;padding-right:36px">'.htmlspecialchars($pubkey).'</pre>';
    echo '</div>';
}
?>
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
          </div>
          <div class="row g-3 mb-3">
            <div class="col-md-3">
              <label class="form-label">SSH Username <span class="text-danger">*</span></label>
              <input type="text" class="form-control" id="tf-ruser" value="root">
            </div>
            <div class="col-md-3">
              <label class="form-label">cPanel Account Username <span class="text-danger">*</span></label>
              <input type="text" class="form-control" id="tf-raccount" placeholder="myuser" required>
            </div>
          </div>
          <div id="step1-error" class="alert alert-danger mb-3" style="display:none"></div>
          <button type="submit" class="btn btn-primary">
            Connect to cPanel server
          </button>
        </form>
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
        <p class="text-muted mt-3">Connecting to remote server and discovering account data</p>
      </div>
    </div>
  </div>
</div>

<!-- Step 3: Domain / DB selection -->
<div id="step3" style="display:none">
  <div class="col-12">
    <div class="card">
      <div class="card-header">
        <h4 class="card-title">Select Items to Transfer</h4>
        <div class="card-options">
          <a href="#" id="btn-back" class="btn btn-sm btn-outline-secondary">&#8592; Back</a>
        </div>
      </div>
      <div class="card-body">
        <div id="step3-warning" class="alert alert-warning mb-3" style="display:none"></div>
        <div class="mb-1">
			<strong>Remote server:</strong> <span id="info-server"></span>
        	<strong>Remote user:</strong> <span id="info-ruser"></span></div>

        <hr>
        <div class="row g-3 mb-3 align-items-start">
          <div class="col-md-5">
            <label class="form-label fw-bold">Remote domain to transfer</label>
            <div class="table-responsive">
              <table class="table table-sm table-vcenter mb-0" id="domains-table">
                <thead>
                  <tr>
                    <th class="w-1"></th>
                    <th>Domain</th>
                    <th>Document root</th>
                    <th>PHP</th>
                  </tr>
                </thead>
                <tbody id="domains-list"></tbody>
              </table>
            </div>
          </div>
          <div class="col-md-1 text-center d-flex align-items-start justify-content-center" style="font-size:2rem;padding-top:32px;">
            &rarr;
          </div>
          <div class="col-md-5">
            <label class="form-label fw-bold">Local account (destination)</label>
            <select class="form-select" id="local-user-select">
              <option value="">— select local account —</option>
              <?php foreach($local_accounts as $acc): ?>
              <option value="<?=htmlspecialchars($acc['user'])?>" data-email="<?=$acc['has_email'] ? 'true' : 'false'?>" data-domain="<?=htmlspecialchars($acc['domain'])?>">
                <?=htmlspecialchars($acc['domain'])?> (<?=htmlspecialchars($acc['user'])?>)
              </option>
              <?php endforeach; ?>
            </select>
            <div class="text-muted mt-2" style="font-size:12px;">Files will be placed in <code>/home/<span id="local-path-preview">LOCALUSER</span>/public_html/</code></div>
          </div>
        </div>

        <div class="table-responsive mb-3">
          <table class="table table-sm table-vcenter">
            <thead>
              <tr>
                <th class="w-1"></th>
                <th>Database</th>
              </tr>
            </thead>
            <tbody id="db-list"></tbody>
          </table>
        </div>

        <label class="form-check mt-3 mb-1" style="cursor:pointer">
          <input class="form-check-input" type="checkbox" id="transfer-email">
          <span class="form-check-label" style="cursor:pointer"><strong>Transfer email accounts</strong></span>
        </label>

        <label class="form-check mt-1 mb-3" style="cursor:pointer">
          <input class="form-check-input" type="checkbox" id="transfer-cron">
          <span class="form-check-label" style="cursor:pointer"><strong>Transfer cron jobs</strong></span>
        </label>

        <div id="step3-error" class="alert alert-danger mb-3" style="display:none"></div>
        <button class="btn btn-primary" id="generate-btn">
          <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M7 8l-4 4l4 4" /><path d="M17 8l4 4l-4 4" /><path d="M14 4l-4 16" /></svg>
          Generate Transfer Commands
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Step 4: Generated commands -->
<div id="step4" style="display:none">
  <div class="col-12">
    <div class="card">
      <div class="card-header">
        <h4 class="card-title">Transfer Commands</h4>
        <div class="card-options">
          <button class="btn btn-sm btn-outline-secondary me-2" id="copy-btn">Copy to clipboard</button>
          <a href="#" id="btn-back2" class="btn btn-sm btn-outline-secondary">&#8592; Back</a>
        </div>
      </div>
      <div class="card-body">
        <div class="alert alert-warning mb-3" role="alert">
          <strong>Review before running.</strong> Make sure local hosting accounts for each domain are already created in Reqad before importing files and databases.
        </div>
        <pre id="commands-output" style="background:#1e293b;color:#e2e8f0;padding:20px;border-radius:6px;font-size:13px;overflow-x:auto;overflow-y:auto;white-space:pre-wrap;line-height:1.6;max-height:320px;"></pre>
        <div class="mt-3">
          <button class="btn btn-success" id="run-btn">&#9654; Run Transfer</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Step 5: Transfer running -->
<div id="step5" style="display:none">
  <div class="col-12">
    <div class="card">
      <div class="card-header">
        <h4 class="card-title">Transfer Progress</h4>
        <div class="card-options">
          <a href="#" id="btn-back3" class="btn btn-sm btn-outline-secondary">&#8592; Back to commands</a>
        </div>
      </div>
      <div class="card-body p-0">
        <pre id="transfer-output" style="background:#1e293b;color:#e2e8f0;padding:20px;margin:0;border-radius:0 0 4px 4px;font-size:12px;min-height:300px;max-height:600px;overflow-y:auto;white-space:pre-wrap;line-height:1.5;"><span id="transfer-status-badge"></span></pre>
      </div>
    </div>
  </div>
</div>

<?php include('templates/footer.php'); ?>
<script>
jQuery(document).ready(function() {
    'use strict';

    var discoveredData = {};

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

    $('#transfer-form').submit(function(e) {
        e.preventDefault();
        $('#step1-error').hide();
        $('#step1').hide();
        $('#step2').show();

        $.ajax({
            url: '/',
            method: 'POST',
            dataType: 'json',
            data: {
                action:   'ajax-transfer-check',
                server:   $('#tf-server').val(),
                port:     $('#tf-port').val(),
                ruser:    $('#tf-ruser').val(),
                raccount: $('#tf-raccount').val()
            },
            success: function(data) {
                if(data.error) {
                    $('#step2').hide();
                    $('#step1').show();
                    $('#step1-error').text(data.error).show();
                    return;
                }
                discoveredData = data;
                populateStep3(data);
                if(data.warning) {
                    $('#step3-warning').text(data.warning).show();
                } else {
                    $('#step3-warning').hide();
                }
                $('#step2').hide();
                $('#step3').show();
            },
            error: function() {
                $('#step2').hide();
                $('#step1').show();
                $('#step1-error').text('Request failed. Check server logs.').show();
            }
        });
    });

    function populateStep3(data) {
        $('#info-server').text(data.server);
        $('#info-ruser').text(data.raccount);

        var rows = '';
        $.each(data.domains, function(i, d) {
            if(!d.documentroot) return;
            var id = 'dom-' + i;
            rows += '<tr>' +
                '<td><input class="form-check-input dom-check" type="radio" name="dom-radio" id="' + id + '" value="' + escHtml(d.domain) + '"></td>' +
                '<td><label for="' + id + '" style="cursor:pointer">' + escHtml(d.domain) + '</label></td>' +
                '<td><code style="font-size:11px">' + escHtml(d.documentroot || '') + '</code></td>' +
                '<td>' + (d.php_version && d.php_version !== 'unknown' ? '<span class="badge bg-blue-lt">' + escHtml(d.php_version) + '</span>' : '') + '</td>' +
                '</tr>';
        });
        $('#domains-list').html(rows || '<tr><td colspan="4" class="text-muted">No domains found.</td></tr>');

        var dbRows = '';
        $.each(data.databases, function(i, db) {
            var id = 'db-' + i;
            dbRows += '<tr>' +
                '<td><input class="form-check-input db-check" type="checkbox" id="' + id + '" value="' + escHtml(db) + '"></td>' +
                '<td><label for="' + id + '" style="cursor:pointer">' + escHtml(db) + '</label></td>' +
                '</tr>';
        });
        $('#db-list').html(dbRows || '<tr><td colspan="2" class="text-muted">No databases found.</td></tr>');
    }

    $('#local-user-select').on('change', function() {
        $('#local-path-preview').text($(this).val() || 'LOCALUSER');
    });

    $(document).on('change', '.dom-check', function() {
        var remoteDomain = $(this).val();
        var match = $('#local-user-select option').filter(function() {
            return $(this).data('domain') === remoteDomain;
        });
        if(match.length) {
            $('#local-user-select').val(match.val()).trigger('change');
        }
    });

    $('#btn-back').click(function(e) {
        e.preventDefault();
        $('#step3').hide();
        $('#step1').show();
    });

    $('#btn-back2').click(function(e) {
        e.preventDefault();
        $('#step4').hide();
        $('#step3').show();
    });

$('#generate-btn').click(function() {
        var selectedDomainName = $('.dom-check:checked').val();
        var selectedDomain = selectedDomainName
            ? discoveredData.domains.find(function(dom) { return dom.domain === selectedDomainName; })
            : null;
        var localUser = $('#local-user-select').val();
        var selectedDbs = [];
        $('.db-check:checked').each(function() { selectedDbs.push($(this).val()); });

        if(!selectedDomain && selectedDbs.length === 0) {
            $('#step3-error').text('Please select at least one domain or database.').show();
            return;
        }
        if(selectedDomain && !localUser) {
            $('#step3-error').text('Please select a local account as the destination.').show();
            return;
        }
        if($('#transfer-cron').is(':checked') && !localUser) {
            $('#step3-error').text('Please select a local account as the destination to transfer cron jobs.').show();
            return;
        }

        // Check remote email vs local email-enabled status
        if(selectedDomain && $('#transfer-email').is(':checked')) {
            var emailInfo = (discoveredData.email_map || {})[selectedDomain.domain] || {email_count: 0, fwd_count: 0};
            var hasRemoteEmail = (emailInfo.email_count + emailInfo.fwd_count) > 0;
            if(hasRemoteEmail) {
                var localEmailEnabled = $('#local-user-select option:selected').data('email');
                if(!localEmailEnabled) {
                    var detail = [];
                    if(emailInfo.email_count > 0) detail.push(emailInfo.email_count + ' email account' + (emailInfo.email_count > 1 ? 's' : ''));
                    if(emailInfo.fwd_count > 0) detail.push(emailInfo.fwd_count + ' forwarder' + (emailInfo.fwd_count > 1 ? 's' : ''));
                    $('#step3-error').text(
                        'Remote domain has ' + detail.join(' and ') + ', but the local account does not have email enabled. ' +
                        'Enable email for the local account before transferring.'
                    ).show();
                    return;
                }
            }
        }

        $('#step3-error').hide();

        var d = discoveredData;
        var sshBase = 'ssh -p ' + d.port + ' ' + d.ruser + '@' + d.server;
        var cmd = '';

        cmd += '# ══════════════════════════════════════════════════════════\n';
        cmd += '# Transfer account: ' + d.raccount + '  from: ' + d.server + '\n';
        cmd += '# ══════════════════════════════════════════════════════════\n\n';

        if(selectedDomain) {
            var docroot  = selectedDomain.documentroot;
            var drParent = docroot.substring(0, docroot.lastIndexOf('/'));
            var drDir    = docroot.substring(docroot.lastIndexOf('/') + 1);
            var localDest = '/home/' + localUser + '/public_html/';
            cmd += '# ── 1. Transfer document root  (requires pv: dnf install pv) ──\n';
            cmd += '# ' + selectedDomain.domain + '  →  ' + docroot + '  →  ' + localDest + '\n\n';
            cmd += 'echo "[1/5] Transferring public_html: ' + docroot + ' → ' + localDest + '"\n';
            cmd += 'SIZE=$(' + sshBase + ' "du -sb ' + docroot + ' | cut -f1")\n';
            cmd += sshBase + ' "tar czf - -C ' + drParent + ' ' + drDir + '" \\\n';
            cmd += '  | pv -f -s "$SIZE" \\\n';
            cmd += '  | sudo -u ' + localUser + ' tar xzf - --strip-components=1 -C ' + localDest + '\n\n';
        }

        if(selectedDomain) {
            var dom = selectedDomain.domain;
            var certDest = '/etc/ssl/certs/' + dom;
            var whmCert = sshBase + ' "whmapi1 --output=json fetch_ssl_vhost domain=' + dom + ' | python3 -c \\"import json,sys; r=json.load(sys.stdin); d=r.get(\'data\',{}).get(\'ssl\',{}); print(d.get(\'certificate\',\'\'))\\""';
            var whmKey  = sshBase + ' "whmapi1 --output=json fetch_ssl_vhost domain=' + dom + ' | python3 -c \\"import json,sys; r=json.load(sys.stdin); d=r.get(\'data\',{}).get(\'ssl\',{}); print(d.get(\'key\',\'\'))\\""';
            cmd += '# ── 2. Transfer SSL certificate ───────────────────────────\n\n';
            cmd += 'echo "[2/5] Transferring SSL certificate for ' + dom + '"\n';
            cmd += whmCert + ' \\\n';
            cmd += '  | sudo tee ' + certDest + '_newcert.pem > /dev/null\n';
            cmd += 'if grep -q "BEGIN CERTIFICATE" ' + certDest + '_newcert.pem; then\n';
            cmd += '  ' + whmKey + ' \\\n';
            cmd += '    | sudo tee ' + certDest + '_privkey.pem > /dev/null\n\n';
            cmd += '  # Apply certificate to webserver config\n';
            cmd += '  if [ -f /etc/nginx/conf.d/' + dom + '.conf ]; then\n';
            cmd += '    sudo sed -i \'s#ssl_certificate[[:blank:]].*;#ssl_certificate ' + certDest + '_newcert.pem;#\' /etc/nginx/conf.d/' + dom + '.conf\n';
            cmd += '    sudo sed -i \'s#ssl_certificate_key[[:blank:]].*;#ssl_certificate_key ' + certDest + '_privkey.pem;#\' /etc/nginx/conf.d/' + dom + '.conf\n';
            cmd += '    sudo nginx -t && sudo systemctl reload nginx\n';
            cmd += '  fi\n';
            cmd += '  if [ -f /etc/httpd/conf.d/' + dom + '.conf ]; then\n';
            cmd += '    sudo sed -i \'s#SSLCertificateFile .*#SSLCertificateFile ' + certDest + '_newcert.pem#\' /etc/httpd/conf.d/' + dom + '.conf\n';
            cmd += '    sudo sed -i \'s#SSLCertificateKeyFile .*#SSLCertificateKeyFile ' + certDest + '_privkey.pem#\' /etc/httpd/conf.d/' + dom + '.conf\n';
            cmd += '    sudo apachectl configtest && sudo systemctl reload httpd\n';
            cmd += '  fi\n';
            cmd += 'else\n';
            cmd += '  sudo rm -f ' + certDest + '_newcert.pem\n';
            cmd += '  echo "No SSL certificate found on remote for ' + dom + ', skipping."\n';
            cmd += 'fi\n\n';
        }

        if(selectedDbs.length > 0) {
            cmd += '# ── 3. Transfer databases ─────────────────────────────────\n\n';
            var remotePrefix = d.db_prefix || (d.raccount + '_');
            $.each(selectedDbs, function(i, db) {
                // Strip remote account prefix, apply local user prefix
                var dbSuffix = db.startsWith(remotePrefix) ? db.slice(remotePrefix.length) : db;
                var localDb = localUser + '_' + dbSuffix;
                var remoteDbUser = (d.db_users || {})[db] || db;
                var userSuffix = remoteDbUser.startsWith(remotePrefix) ? remoteDbUser.slice(remotePrefix.length) : remoteDbUser;
                var localDbUser = localUser + '_' + userSuffix;

                cmd += '# remote: ' + db + '  →  local: ' + localDb + '\n';
                cmd += 'echo "[3/5] Transferring database: ' + db + ' → ' + localDb + '"\n';
                cmd += 'sudo mysql -e "CREATE DATABASE IF NOT EXISTS \\`' + localDb + '\\`;"\n\n';

                cmd += '# ' + localDb + ' — data\n';
                cmd += sshBase + ' "mysqldump --single-transaction --no-tablespaces ' + db + '" \\\n';
                cmd += '  | pv -f | sudo mysql ' + localDb + '\n\n';

                cmd += '# ' + localDb + ' — user & permissions\n';
                cmd += 'HASH=$(' + sshBase + ' "mysql -N -e \\"SELECT authentication_string FROM mysql.user WHERE user=\'' + remoteDbUser + '\' AND host=\'localhost\' LIMIT 1;\\"")\n';
                cmd += 'echo "[db] remote user: ' + remoteDbUser + '  local user: ' + localDbUser + '"\n';
                cmd += 'echo "[db] CREATE USER IF NOT EXISTS \\`' + localDbUser + '\\`@\\`localhost\\`"\n';
                cmd += 'sudo mysql -e "CREATE USER IF NOT EXISTS \\`' + localDbUser + '\\`@\\`localhost\\` IDENTIFIED BY PASSWORD \'${HASH}\';" \n';
                cmd += 'sudo mysql -e "GRANT ALL PRIVILEGES ON \\`' + localDb + '\\`.* TO \\`' + localDbUser + '\\`@\\`localhost\\`;"\n';
                cmd += 'sudo mysql -e "FLUSH PRIVILEGES;"\n\n';

                if(selectedDomain) {
                    var wpconfig = '/home/' + localUser + '/public_html/wp-config.php';
                    cmd += '# Update wp-config.php if WordPress\n';
                    cmd += '[ -f ' + wpconfig + ' ] && sudo -u ' + localUser + ' sed -i \\\n';
                    cmd += '  -e "s/\'DB_NAME\', \'' + db + '\'/\'DB_NAME\', \'' + localDb + '\'/g" \\\n';
                    cmd += '  -e "s/\'DB_USER\', \'' + remoteDbUser + '\'/\'DB_USER\', \'' + localDbUser + '\'/g" \\\n';
                    cmd += '  ' + wpconfig + '\n\n';
                }
            });
        }

        var transferEmail = $('#transfer-email').is(':checked');
        if(transferEmail && selectedDomain) {
            var dom2 = selectedDomain.domain;
            var emailInfo2 = (discoveredData.email_map || {})[dom2] || {email_count: 0, fwd_count: 0};
            cmd += '# ── 4. Transfer email ─────────────────────────────────────\n\n';

            if(emailInfo2.email_count > 0) {
                var mailSrc = '/home/' + d.raccount + '/mail/' + dom2;
                var mailDest = '/home/' + localUser + '/mail/';
                var remoteEtc = '/home/' + d.raccount + '/etc/' + dom2;
                cmd += 'echo "[4/5] Transferring mail for ' + dom2 + '"\n';
                cmd += '# Mail Maildirs for ' + dom2 + '\n';
                cmd += 'sudo -u ' + localUser + ' mkdir -p /home/' + localUser + '/mail/' + dom2 + '\n';
                cmd += 'SIZE=$(' + sshBase + ' "du -sb ' + mailSrc + ' | cut -f1")\n';
                cmd += sshBase + ' "tar czf - -C /home/' + d.raccount + '/mail ' + dom2 + '" \\\n';
                cmd += '  | pv -f -s "$SIZE" \\\n';
                cmd += '  | sudo -u ' + localUser + ' tar xzf - -C ' + mailDest + '\n\n';

                cmd += '# Email account settings for ' + dom2 + '\n';
                cmd += 'LUID=$(id -u ' + localUser + ') LGID=$(id -g ' + localUser + ')\n';
                cmd += sshBase + ' "cut -d: -f1 ' + remoteEtc + '/passwd" \\\n';
                cmd += '  | grep -vxFf /etc/exim/domains/' + dom2 + ' \\\n';
                cmd += '  | sudo tee -a /etc/exim/domains/' + dom2 + ' > /dev/null\n\n';
                cmd += sshBase + ' "cut -d: -f1,2 ' + remoteEtc + '/shadow" \\\n';
                cmd += '  | awk -F: -v uid=$LUID -v gid=$LGID -v u=' + localUser + ' -v dom=' + dom2 + ' \\\n';
                cmd += "    '{print $1\"@\"dom\":\"$2\":\"uid\":\"gid\"::/home/\"u\"/mail/\"dom\"/\"$1\"::userdb_mail=maildir:~/\"}' \\\n";
                cmd += '  | while IFS= read -r line; do key=${line%%:*}; cut -d: -f1 /etc/dovecot/users | grep -qxF "$key" || echo "$line"; done \\\n';
                cmd += '  | sudo tee -a /etc/dovecot/users > /dev/null\n\n';
            }

            cmd += 'echo "[4/5] Transferring DKIM keys for ' + dom2 + '"\n';
            cmd += '# DKIM keys for ' + dom2 + '\n';
            cmd += sshBase + ' "cat /var/cpanel/domain_keys/private/' + dom2 + '" \\\n';
            cmd += '  | sudo tee /etc/exim/keys/' + dom2 + '.private.key > /dev/null\n';
            cmd += sshBase + ' "cat /var/cpanel/domain_keys/public/' + dom2 + '" \\\n';
            cmd += '  | sudo tee /etc/exim/keys/' + dom2 + '.public.key > /dev/null\n\n';

            if(emailInfo2.fwd_count > 0) {
                var domEscaped = dom2.replace(/\./g, '\\.');
                cmd += '# Forwarders for ' + dom2 + '\n';
                cmd += sshBase + ' "cat /etc/valiases/' + dom2 + '" \\\n';
                cmd += "  | sed 's/@" + domEscaped + "//g' \\\n";
                cmd += '  | sudo tee /etc/exim/forwards/' + dom2 + ' > /dev/null\n\n';
            }
        }

        var transferCron = $('#transfer-cron').is(':checked');
        if(transferCron && localUser) {
            cmd += '# ── 5. Transfer cron jobs ─────────────────────────────────\n\n';
            cmd += 'echo "[5/5] Transferring cron jobs for ' + localUser + '"\n';
            cmd += sshBase + ' "crontab -l -u ' + d.raccount + ' 2>/dev/null" \\\n';
            cmd += "  | grep -v '^#' \\\n";
            cmd += "  | grep -v '^MAILTO' \\\n";
            cmd += "  | grep -v '^SHELL' \\\n";
            cmd += "  | grep -vE '^[[:space:]]*$' \\\n";
            cmd += "  | sed 's|/home/" + d.raccount + "/|/home/" + localUser + "/|g' \\\n";
            cmd += '  | sudo crontab -u ' + localUser + ' -\n\n';
        }

        cmd += 'echo "[done] Transfer complete."\n';

        $('#commands-output').text(cmd);
        $('#step3').hide();
        $('#step4').show();
    });

    $('#copy-btn').click(function() {
        var text = $('#commands-output').text();
        if(navigator.clipboard) {
            navigator.clipboard.writeText(text).then(function() {
                $('#copy-btn').text('Copied!');
                setTimeout(function() { $('#copy-btn').text('Copy to clipboard'); }, 2000);
            });
        } else {
            var ta = $('<textarea>').val(text).appendTo('body').select();
            document.execCommand('copy');
            ta.remove();
            $('#copy-btn').text('Copied!');
            setTimeout(function() { $('#copy-btn').text('Copy to clipboard'); }, 2000);
        }
    });

    $('#run-btn').click(function() {
        var commands = $('#commands-output').text();
        $('#step4').hide();
        $('#transfer-output').text('');
        $('#transfer-output').append('<span id="transfer-status-badge"></span>');

        $('#step5').show();

        $.post('/', {action: 'ajax-transfer-run', commands: commands}, null, 'json')
            .done(function(r) {
                if(r && r.error) {
                    $('#transfer-output').text('Error: ' + r.error);
                    return;
                }
                pollTransfer(r.job_id, 0);
            })
            .fail(function() {
                $('#transfer-output').text('Failed to start transfer.');
            });
    });

    function processOutput(text) {
        // Collapse \r progress lines (pv) — keep last non-empty segment per line
        return text.split('\n').map(function(line) {
            var parts = line.split('\r');
            for (var i = parts.length - 1; i >= 0; i--) {
                if (parts[i].length > 0) return parts[i];
            }
            return '';
        }).join('\n');
    }

    function pollTransfer(job_id, offset) {
        $.post('/', {action: 'ajax-php-modules-status', job_id: job_id, offset: offset}, null, 'json')
            .done(function(r) {
                if(r && r.output) {
                    var $out = $('#transfer-output');
                    $out.text($out.text() + processOutput(r.output));
                    $out[0].scrollTop = $out[0].scrollHeight;
                }
                if(r && r.done) {
                    var badgeClass = r.success ? 'bg-success' : 'bg-danger';
                    var badgeText  = r.success ? 'Transfer complete' : 'Finished with errors';
                    $('#transfer-status-badge').replaceWith('<span id="transfer-status-badge" class="badge ' + badgeClass + '" style="margin-top:12px;display:inline-block">' + badgeText + '</span>');
                    $('#transfer-output')[0].scrollTop = $('#transfer-output')[0].scrollHeight;
                } else {
                    setTimeout(function() { pollTransfer(job_id, r ? r.offset : offset); }, 1000);
                }
            })
            .fail(function() {
                setTimeout(function() { pollTransfer(job_id, offset); }, 2000);
            });
    }

    $('#btn-back3').click(function(e) {
        e.preventDefault();
        $('#step5').hide();
        $('#step4').show();
    });
});
</script>
</body>
</html>
