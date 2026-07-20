<?php
	/* Per-account ADVANCED management page: /account/<user>
	   Account editing (quota/password/PHP/email) lives in the accounts-list modal.
	   This page holds the premium-only Alias Domains and Advanced Config tabs.
	   $acct_user is set by index.php. */

	$php_versions = array_map('trim', explode(',', $ini['php_versions']));
	$is_apache = (substr(trim($ini['template'] ?? ''), 0, 7) == 'apache_');
	$php_version_colors = [
		'7.2' => '#4299e1',
		'7.4' => '#2da6b4',
		'8.0' => '#1ab38c',
		'8.1' => '#09bf62',
		'8.2' => '#01c940',
		'8.3' => '#05ce29',
		'8.4' => '#33cf14',
		'8.5' => '#64cf0c',
	];

	$settings = array();
	$results = $db->query('SELECT name,value FROM settings');
	while ($row = $results->fetchArray()) {
		$settings[$row["name"]] = $row["value"];
	}

	/* Load the account. $acct_user is already sanitized to [a-z0-9] in index.php. */
	$acct = false;
	if($acct_user != '') {
		$results = $db->query('SELECT * FROM accounts WHERE user="'.$acct_user.'"');
		$acct = $results->fetchArray();
	}
	if(!$acct) {
		// Unknown account — back to the list.
		header("Location: ".$_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/accounts/');
		exit;
	}

	$domain = $acct["domain"];

	/* Detect the account's active PHP version + handler (same logic as accounts.php:
	   version is inferred from which php-fpm.d dir holds its pool file). */
	$phpversion = $ini['php'];
	foreach ($php_versions as $pv) {
		$pv2 = str_replace('.', '', $pv);
		if(is_file('/etc/opt/remi/php'.$pv2.'/php-fpm.d/'.$domain.'.conf'))
			$phpversion = $pv;
	}
	$phphandler = 'mod_php';
	if($is_apache) {
		if($phpversion != $ini['php'])
			$phphandler = 'fpm';
		elseif(is_file('/etc/php-fpm.d/'.$domain.'.conf'))
			$phphandler = 'fpm';
	}

	/* Disk usage figures for the summary. */
	$DISKSPACE = `lsblk -b --output TYPE,SIZE | grep 'disk' | awk {'print \$2'}`;
	$DISKSPACE = round((int)($DISKSPACE)/1024/1024);
	if($DISKSPACE == 0)
		$DISKSPACE = 9999999;
	$disk_pct = $DISKSPACE > 0 ? round($acct["disk_usage"]*100/$DISKSPACE, 1) : 0;

	/* URL-based tab selection (same approach as php-settings.php).
	   Account editing lives in the accounts-list modal now, so this advanced
	   page opens on Alias Domains by default. */
	$tab = (isset($_GET['tab']) && $_GET['tab'] === 'config') ? 'config' : 'aliases';

	/* Alias domains for this account + email/DNS state used by the Aliases tab. */
	$aliases      = get_aliases($db, $acct['id']);
	$has_email    = (isset($ini['email']) && $ini['email']==1 && $acct['has_email']);
	$dns_provider = isset($settings['dns-provider']) ? $settings['dns-provider'] : '';
	$dns_ready    = ($dns_provider !== '' && $dns_provider !== null);
	/* Live SAN of the installed Let's Encrypt cert (null = account not on LE),
	   used to show real per-alias SSL coverage. */
	$cert_san     = get_cert_san($domain);
	$uses_le      = ($cert_san !== null);

	/* Advanced Config tab: load the editable files (only when that tab is active). */
	$cfg_nginx = null; $cfg_nginx_content = '';
	$cfg_fpm   = null; $cfg_fpm_content   = '';
	if($tab === 'config') {
		$cfg_nginx = account_config_target($ini, $domain, 'nginx');
		$cfg_nginx_content = (string)shell_exec('sudo cat '.escapeshellarg($cfg_nginx['path']).' 2>/dev/null');
		/* php-fpm pool exists for nginx (always fpm) and for apache accounts on fpm */
		$has_fpm = (!$is_apache || $phphandler === 'fpm');
		if($has_fpm) {
			$cfg_fpm = account_config_target($ini, $domain, 'fpm');
			$cfg_fpm_content = (string)shell_exec('sudo cat '.escapeshellarg($cfg_fpm['path']).' 2>/dev/null');
			if(trim($cfg_fpm_content) === '') $cfg_fpm = null;  // no pool file -> hide the tab
		}

	/* ---- Step 7: Advanced Config snippets & version history --------------------
	   Insertable config snippets, keyed by server type. Chosen at the cursor,
	   not whole-file templates, so an existing vhost/pool is never clobbered.
	   USER/home paths are pre-filled for this account. */
	$acct_home = '/home/'.$acct['user'];
	$cfg_snippets = array(
		'nginx' => array(
			'Force HTTPS redirect'        => "if (\$scheme = http) {\n    return 301 https://\$host\$request_uri;\n}\n",
			'Security headers'            => "add_header X-Frame-Options \"SAMEORIGIN\" always;\nadd_header X-Content-Type-Options \"nosniff\" always;\nadd_header Referrer-Policy \"strict-origin-when-cross-origin\" always;\n",
			'Gzip compression'            => "gzip on;\ngzip_comp_level 5;\ngzip_min_length 256;\ngzip_types text/plain text/css application/json application/javascript text/xml application/xml image/svg+xml;\n",
			'Cache static assets'         => "location ~* \\.(?:css|js|jpg|jpeg|gif|png|webp|svg|woff2?|ico)\$ {\n    expires 30d;\n    add_header Cache-Control \"public, immutable\";\n}\n",
			'Serve WebP/AVIF if present'  => "location ~* ^(?<base>.+)\\.(png|jpe?g)\$ {\n    add_header Vary Accept;\n    set \$img \$uri;\n    if (\$http_accept ~* \"webp\") { set \$img \$base.webp; }\n    if (\$http_accept ~* \"avif\") { set \$img \$base.avif; }\n    try_files \$img \$uri =404;\n}\n",
			'Restrict by IP / password'   => "location /admin/ {\n    # allow these IPs without a password; everyone else must log in\n    satisfy any;\n    allow 203.0.113.10;\n    allow 192.0.2.0/24;\n    deny  all;\n    auth_basic           \"Restricted area\";\n    auth_basic_user_file ".$acct_home."/.htpasswd;\n}\n",
			'Deny hidden files'           => "location ~ /\\.(?!well-known) {\n    deny all;\n}\n",
		),
		'apache' => array(
			'Force HTTPS redirect'        => "RewriteEngine On\nRewriteCond %{HTTPS} off\nRewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]\n",
			'Security headers'            => "Header always set X-Frame-Options \"SAMEORIGIN\"\nHeader always set X-Content-Type-Options \"nosniff\"\nHeader always set Referrer-Policy \"strict-origin-when-cross-origin\"\n",
			'Gzip compression'            => "<IfModule mod_deflate.c>\n    AddOutputFilterByType DEFLATE text/plain text/html text/css application/json application/javascript application/xml image/svg+xml\n</IfModule>\n",
			'Cache static assets'         => "<IfModule mod_expires.c>\n    ExpiresActive On\n    <FilesMatch \"\\.(css|js|jpg|jpeg|gif|png|webp|svg|woff2?|ico)\$\">\n        ExpiresDefault \"access plus 30 days\"\n        Header append Cache-Control \"public, immutable\"\n    </FilesMatch>\n</IfModule>\n",
			'Serve WebP/AVIF if present'  => "<IfModule mod_rewrite.c>\n    RewriteEngine On\n    RewriteCond %{HTTP_ACCEPT} image/avif\n    RewriteCond %{REQUEST_FILENAME}.avif -f\n    RewriteRule ^(.+)\\.(png|jpe?g)\$ \$1.\$2.avif [T=image/avif,L]\n    RewriteCond %{HTTP_ACCEPT} image/webp\n    RewriteCond %{REQUEST_FILENAME}.webp -f\n    RewriteRule ^(.+)\\.(png|jpe?g)\$ \$1.\$2.webp [T=image/webp,L]\n</IfModule>\n",
			'Restrict by IP / password'   => "<Directory \"".$acct_home."/public_html/admin\">\n    AuthType Basic\n    AuthName \"Restricted area\"\n    AuthUserFile ".$acct_home."/.htpasswd\n    <RequireAny>\n        Require ip 203.0.113.10 192.0.2.0/24\n        Require valid-user\n    </RequireAny>\n</Directory>\n",
			'Deny hidden files'           => "<FilesMatch \"^\\.(?!well-known)\">\n    Require all denied\n</FilesMatch>\n",
		),
		'fpm' => array(
			'Increase memory_limit'  => "php_admin_value[memory_limit] = 512M\n",
			'Larger upload size'     => "php_admin_value[upload_max_filesize] = 128M\nphp_admin_value[post_max_size] = 128M\n",
			'Tune process manager'   => "pm = dynamic\npm.max_children = 20\npm.start_servers = 4\npm.min_spare_servers = 2\npm.max_spare_servers = 8\n",
			'Enable slow-request log'=> "slowlog = /var/log/php-fpm/\$pool-slow.log\nrequest_slowlog_timeout = 10s\n",
		),
	);
	/* pick the right web-server snippet set for the panel */
	$cfg_web_snippets = $is_apache ? $cfg_snippets['apache'] : $cfg_snippets['nginx'];

	/* Real version history (last 5 backups per file). */
	$cfg_versions = array(
		'cfg-nginx' => list_config_backups($acct['user'], 'nginx', $cfg_nginx['path']),
		'cfg-fpm'   => $cfg_fpm ? list_config_backups($acct['user'], 'fpm', $cfg_fpm['path']) : array(),
	);

	/* Snippets dropdown for a panel. Items carry the snippet text in data-snippet;
	   JS inserts it at the editor cursor. */
	function cfg_snippet_menu($panel, $snippets) { ?>
		<div class="dropdown">
			<button class="btn btn-white btn-sm dropdown-toggle cfg-dd-toggle" type="button">
				<svg xmlns="http://www.w3.org/2000/svg" class="icon icon-sm" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z"/><path d="M9 12h6"/><path d="M9 16h6"/></svg>
				Snippets
			</button>
			<div class="dropdown-menu dropdown-menu-end">
				<h6 class="dropdown-header">Insert at cursor</h6>
				<?php foreach($snippets as $name => $body): ?>
				<a class="dropdown-item cfg-snippet" href="#" data-target="<?=$panel;?>" data-snippet="<?=htmlspecialchars($body, ENT_QUOTES);?>"><?=htmlspecialchars($name);?></a>
				<?php endforeach; ?>
			</div>
		</div>
	<?php }

	/* Version-history dropdown for a panel. Each item opens the preview modal. */
	function cfg_version_menu($panel, $versions, $user, $file) { ?>
		<div class="dropdown">
			<button class="btn btn-white btn-sm dropdown-toggle cfg-dd-toggle" type="button">
				<svg xmlns="http://www.w3.org/2000/svg" class="icon icon-sm" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 8l0 4l2 2"/><path d="M3.05 11a9 9 0 1 1 .5 4m-.5 5v-5h5"/></svg>
				Version history
			</button>
			<div class="dropdown-menu dropdown-menu-end" style="min-width:230px;">
				<h6 class="dropdown-header">Last 5 saved versions</h6>
				<?php if(!count($versions)): ?>
				<div class="dropdown-item text-muted" style="cursor:default;">No previous versions yet.</div>
				<?php else: foreach($versions as $v): ?>
				<a class="dropdown-item cfg-version d-flex align-items-center" href="#"
				   data-target="<?=$panel;?>" data-user="<?=htmlspecialchars($user, ENT_QUOTES);?>"
				   data-file="<?=htmlspecialchars($file, ENT_QUOTES);?>" data-id="<?=htmlspecialchars($v['id'], ENT_QUOTES);?>"
				   data-when="<?=htmlspecialchars($v['when'], ENT_QUOTES);?>">
					<span><?=htmlspecialchars($v['when']);?></span>
					<span class="text-muted ms-auto ps-3" style="font-size:.8rem;"><?=htmlspecialchars($v['size']);?></span>
				</a>
				<?php endforeach; endif; ?>
				<div class="dropdown-divider"></div>
				<div class="dropdown-item text-muted" style="cursor:default;font-size:.8rem;">Only the last 5 versions are kept.</div>
			</div>
		</div>
	<?php }
	}

	if(!isset($errmsg))
		$errmsg = '';
	include('templates/header.php');
?>
          <!-- Page title -->
          <div class="page-header d-print-none">
            <div class="row align-items-center">
              <div class="col" style="padding-left:22px;">
                <div class="page-pretitle">
                  <a href="/accounts/" class="text-muted">Accounts</a> / <?=$acct["user"];?>
                </div>
                <h2 class="page-title" style="white-space:nowrap !important;">
                  Advanced settings for &nbsp;<b><?=$domain;?></b>
                </h2>
              </div>
              <div class="col-auto ms-auto d-print-none">
                <div class="btn-list">
                  <a href="/accounts/" class="btn btn-white">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><path d="M5 12l14 0"></path><path d="M5 12l6 6"></path><path d="M5 12l6 -6"></path></svg>
                    Back to accounts
                  </a>
                </div>
              </div>
            </div>
          </div>

<?php msg_render(); /* flash message (PRG) */ ?>

          <style>
          #account-main-card { border: none; }
          #account-main-card > .card-header { padding-bottom: 0; background: #f6f8fb; }
          #account-tabs { border-bottom: 0; gap: 30px; }
          #account-tabs .nav-link {
              display: block;
              border: 1px solid transparent;
              padding: 10px 20px !important;
              color: #6c757d;
              font-weight: 500;
              line-height:20pt !important;
              margin-bottom: -1px;
              margin-left: -16px !important;
          }
          #account-tabs .nav-link:hover { border-color: #dee2e6 #dee2e6 transparent; background: #475db41a; color: #354052; }
          #account-tabs .nav-link.active { background: #fff; border-color: #dee2e6 #dee2e6 #fff; color: #354052; font-weight: bold; }
          .account-tab-pane { border: 1px solid; border-color: transparent #dee2e6 #dee2e6 #dee2e6; }
          /* Dashboard-style info box (mirrors .sysinfo-table on the dashboard). */
          .acct-info-table { margin-bottom:0; }
          .acct-info-table th { background-color:#DEF; padding:6px 15px; white-space:nowrap; font-weight:600; color:#354052; }
          .acct-info-table td { padding:6px 15px; }
          .acct-info-table tr:nth-child(even) td { background:#f6f8fb; }
          .acct-info-table tr:nth-child(odd) td { background:#fff; }
          .acct-info-table td:first-child { color:#6c757d; white-space:nowrap; width:38%; }
          </style>
<?php if($tab === 'config'): ?>
          <link href="./dist/libs/codemirror/codemirror.min.css" rel="stylesheet">
          <style>
          /* grow to fit content so there's a single (page) scrollbar — no inner
             editor scroll; a compact floor keeps short files from looking cramped */
          .CodeMirror { height:auto; border:1px solid #dee2e6; font-size:13px; }
          .CodeMirror-scroll { min-height:260px; }
          .CodeMirror .cm-comment { color:#6c757d; }   /* grey comments */
          #config-subtabs .form-selectgroup-item { cursor:pointer; margin-right:12px; }
          .config-editor { display:none; }  /* replaced by CodeMirror on init */
          </style>
<?php endif; ?>

          <div class="col-12">
            <div class="card mt-3" id="account-main-card">
              <div class="card-header">
                <ul class="nav nav-tabs card-header-tabs" id="account-tabs">
                  <li class="nav-item">
                    <a class="nav-link <?=$tab==='aliases'?'active':'';?>" href="/account/<?=$acct_user;?>/">Alias Domains</a>
                  </li>
                  <li class="nav-item">
                    <a class="nav-link <?=$tab==='config'?'active':'';?>" href="/account/<?=$acct_user;?>/?tab=config">Advanced Config</a>
                  </li>
                </ul>
              </div>
              <div class="tab-content">
                  <!-- Alias Domains tab -->
                  <div class="tab-pane account-tab-pane <?=$tab==='aliases'?'active show':'';?>" id="tab-aliases">
                    <div class="card-body">
                      <div class="d-flex align-items-center mb-3">
                        <div style="padding:5px;max-width:720px;">
                          <div class="text-muted"><b>Note:</b> Alias domains are supplementary domains that share the same document root, config files and serve the same site as main domain, <code><?=$domain;?></code>. Wildcard domains like <code>*.<?=$domain;?></code> are allowed.</div>
                        </div>
                        <div class="ms-auto d-flex" style="gap:8px;">
                          <?php if($uses_le): ?>
                          <form method="post" action="/" style="margin:0;">
                            <input type="hidden" name="action" value="reissue-ssl">
                            <input type="hidden" name="user" value="<?=$acct['user'];?>">
                            <button type="submit" class="btn btn-white" title="Re-issue the Let's Encrypt certificate to cover the current standard aliases (and mail).">
                              <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4"></path><path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4"></path></svg>
                              Reissue SSL
                            </button>
                          </form>
                          <?php endif; ?>
                          <a href="#" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal-add-alias">
                            <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                            Add alias domain
                          </a>
                        </div>
                      </div>

                      <div class="table-responsive">
                        <table class="table table-vcenter card-table">
                          <thead>
                            <tr>
                              <th>Alias</th>
                              <th>Type</th>
                              <th>SSL</th>
                              <th class="w-1"></th>
                            </tr>
                          </thead>
                          <tbody>
                          <?php foreach($aliases as $a):
                              $is_wild = ((int)$a['is_wildcard'] === 1);
                              $is_www  = (strpos($a['alias'], 'www.') === 0);
                          ?>
                            <tr>
                              <td>
                                <span class="font-weight-medium"><?=htmlspecialchars($a['alias']);?></span>
                                <?php if($is_www): ?><span class="badge bg-secondary-lt border ms-1">www</span><?php endif; ?>
                              </td>
                              <td>
                                <?php if($is_wild): ?><span class="badge bg-purple-lt">wildcard</span>
                                <?php else: ?><span class="badge bg-blue-lt">standard</span><?php endif; ?>
                              </td>
                              <td>
                                <?php
                                  /* Live SSL coverage from the installed cert. Wildcards are validated
                                     via DNS-01 and appear in the SAN once the re-issue completes. */
                                  if(!$uses_le): ?><span class="text-muted" title="This account is not using a Let's Encrypt certificate">&mdash;</span>
                                <?php elseif(alias_is_covered($a['alias'], $cert_san)): ?><span class="badge bg-success-lt">covered</span>
                                <?php elseif($is_wild && !$dns_ready): ?><span class="text-muted" title="Wildcard SSL needs a DNS provider (DNS-01). Configure one in DNS Settings.">needs DNS</span>
                                <?php elseif($is_wild): ?><span class="badge bg-orange-lt" title="Validated via DNS-01. Click Reissue SSL to obtain/refresh the wildcard certificate.">pending (DNS-01)</span>
                                <?php else: ?><span class="badge bg-orange-lt" title="Not in the certificate yet — resolve the domain to this server, then Reissue SSL">pending</span><?php endif; ?>
                              </td>
                              <td>
                                <form method="post" action="/" style="margin:0;" onsubmit="return confirm('Remove alias <?=htmlspecialchars($a['alias'], ENT_QUOTES);?>?');">
                                  <input type="hidden" name="action" value="alias-delete">
                                  <input type="hidden" name="user" value="<?=$acct['user'];?>">
                                  <input type="hidden" name="alias_id" value="<?=(int)$a['id'];?>">
                                  <button type="submit" class="btn btn-white btn-sm">Delete</button>
                                </form>
                              </td>
                            </tr>
                          <?php endforeach; ?>

                          <?php if($has_email): ?>
                            <!-- mail.<domain> is derived from the email setting, not stored; view-only. -->
                            <tr>
                              <td>
                                <span class="font-weight-medium">mail.<?=$domain;?></span>
                                <span class="badge bg-secondary-lt border ms-1">mail</span>
                              </td>
                              <td><span class="badge bg-blue-lt">standard</span></td>
                              <td>
                                <?php if(!$uses_le): ?><span class="text-muted">&mdash;</span>
                                <?php elseif(alias_is_covered('mail.'.$domain, $cert_san)): ?><span class="badge bg-success-lt">covered</span>
                                <?php else: ?><span class="badge bg-orange-lt" title="Reissue SSL to cover mail.<?=$domain;?>">pending</span><?php endif; ?>
                              </td>
                              <td><span class="text-muted" style="font-size:.85rem;" title="Managed automatically while email is enabled">required</span></td>
                            </tr>
                          <?php endif; ?>

                          <?php if(!count($aliases) && !$has_email): ?>
                            <tr><td colspan="4" class="text-muted">No alias domains yet.</td></tr>
                          <?php endif; ?>
                          </tbody>
                        </table>
                      </div>
                    </div>
                  </div><!-- #tab-aliases -->

                  <!-- Advanced Config tab -->
                  <div class="tab-pane account-tab-pane <?=$tab==='config'?'active show':'';?>" id="tab-config">
                    <div class="card-body">
                    <?php if($tab === 'config'): ?>
                      <div class="text-muted mb-3" style="font-size:.9rem;">
                        Edit this account's server configuration. Changes are <b>validated before saving</b> and
                        automatically reverted if the config test fails.
                      </div>

                      <div class="form-selectgroup form-selectgroup-boxes d-flex flex-wrap mb-3" id="config-subtabs">
                        <label class="form-selectgroup-item" style="min-width:210px;">
                          <input type="radio" name="config-file" value="cfg-nginx" data-cfg="cfg-nginx" class="form-selectgroup-input" checked="">
                          <div class="form-selectgroup-label d-flex align-items-center p-3" style="height:72px;">
                            <div class="me-3"><span class="form-selectgroup-check"></span></div>
                            <span class="me-2 d-inline-flex align-items-center">
                            <?php if($is_apache): ?>
                              <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="#d22128" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20 L20 4"/><path d="M20 4 C20 12 14 18 6 18 L4 20"/><path d="M9 15 L14 15"/><path d="M12 11 L16 11"/></svg>
                            <?php else: ?>
                              <svg width="32" height="36" viewBox="0 0 100 112" xmlns="http://www.w3.org/2000/svg"><path d="M50 1 4 27.5v57L50 111l46-26.5v-57z" fill="#009639"/><path d="M35 80V37l30 38V32" fill="none" stroke="#fff" stroke-width="8" stroke-linejoin="miter"/></svg>
                            <?php endif; ?>
                            </span>
                            <span style="font-size:15px;color:#354052;font-weight:500;"><?=$is_apache?'apache vhost':'nginx vhost';?></span>
                          </div>
                        </label>
                        <?php if($cfg_fpm): ?>
                        <label class="form-selectgroup-item" style="min-width:210px;">
                          <input type="radio" name="config-file" value="cfg-fpm" data-cfg="cfg-fpm" class="form-selectgroup-input">
                          <div class="form-selectgroup-label d-flex align-items-center p-3" style="height:72px;">
                            <div class="me-3"><span class="form-selectgroup-check"></span></div>
                            <span class="me-2 d-inline-flex align-items-center">
                              <svg width="54" height="29" viewBox="0 0 100 54" xmlns="http://www.w3.org/2000/svg"><ellipse cx="50" cy="27" rx="49" ry="26" fill="#777BB3"/><text x="50" y="37" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-weight="bold" font-style="italic" font-size="27" fill="#fff">php</text></svg>
                            </span>
                            <span style="font-size:15px;color:#354052;font-weight:500;">php-fpm pool</span>
                          </div>
                        </label>
                        <?php endif; ?>
                      </div>

                      <div class="config-panel" id="cfg-nginx">
                        <div class="d-flex align-items-center mb-2" style="gap:10px;">
                          <div class="text-muted" style="font-size:.85rem;">File: <code><?=$cfg_nginx['path'];?></code></div>
                          <div class="ms-auto btn-list">
                            <?php cfg_snippet_menu('cfg-nginx', $cfg_web_snippets); ?>
                            <?php cfg_version_menu('cfg-nginx', $cfg_versions['cfg-nginx'], $acct['user'], 'nginx'); ?>
                          </div>
                        </div>
                        <form method="post" action="/">
                          <input type="hidden" name="action" value="config-save">
                          <input type="hidden" name="user" value="<?=$acct['user'];?>">
                          <input type="hidden" name="file" value="nginx">
                          <textarea name="content" class="config-editor" data-mode="nginx"><?=htmlspecialchars($cfg_nginx_content);?></textarea>
                          <div class="mt-2">
                            <button class="btn btn-primary" type="submit">Save &amp; reload</button>
							<!--
                            <span class="text-muted ms-2" style="font-size:.85rem;">Validated with <code><?=$is_apache?'httpd -t':'nginx -t';?></code>; reverted if invalid.</span>
							-->
                          </div>
                        </form>
                      </div>

                      <?php if($cfg_fpm): ?>
                      <div class="config-panel" id="cfg-fpm" style="display:none;">
                        <div class="d-flex align-items-center mb-2" style="gap:10px;">
                          <div class="text-muted" style="font-size:.85rem;">File: <code><?=$cfg_fpm['path'];?></code> &middot; <?=$cfg_fpm['label'];?></div>
                          <div class="ms-auto btn-list">
                            <?php cfg_snippet_menu('cfg-fpm', $cfg_snippets['fpm']); ?>
                            <?php cfg_version_menu('cfg-fpm', $cfg_versions['cfg-fpm'], $acct['user'], 'fpm'); ?>
                          </div>
                        </div>
                        <form method="post" action="/">
                          <input type="hidden" name="action" value="config-save">
                          <input type="hidden" name="user" value="<?=$acct['user'];?>">
                          <input type="hidden" name="file" value="fpm">
                          <textarea name="content" class="config-editor" data-mode="properties"><?=htmlspecialchars($cfg_fpm_content);?></textarea>
                          <div class="mt-2">
                            <button class="btn btn-primary" type="submit">Save &amp; reload</button>
							<!--
                            <span class="text-muted ms-2" style="font-size:.85rem;">Validated with <code>php-fpm -t</code>; reverted if invalid.</span>
					  		-->
                          </div>
                        </form>
                      </div>
                      <?php endif; ?>
                    <?php endif; ?>
                    </div>
                  </div><!-- #tab-config -->

              </div><!-- .tab-content -->
            </div><!-- .card -->
          </div><!-- .col-12 -->

<!-- Add alias domain modal -->
<form method="post" action="/" id="add-alias-form" class="needs-validation" novalidate>
  <input type="hidden" name="action" value="alias-add">
  <input type="hidden" name="user" value="<?=$acct['user'];?>">
  <div class="modal modal-blur fade" id="modal-add-alias" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Add alias domain</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Alias domain</label>
            <input type="text" name="alias" id="alias-input" class="form-control" placeholder="alias.example.com or *.<?=$domain;?>" autocomplete="off">
            <div class="invalid-feedback" id="alias-invalid">Please enter a valid domain.</div>
            <small class="form-text text-muted" style="display:block;margin-top:8px;">
              Serves the same site as <b><?=$domain;?></b>. A <b>standard</b> alias must point to this server's IP.
              A <b>wildcard</b> (<code>*.domain</code>) matches every subdomain.
            </small>
          </div>
          <?php if($dns_ready): ?>
          <div class="mb-3">
            <label class="form-check">
              <input class="form-check-input" type="checkbox" name="add_dns" checked="true">
              <span class="form-check-label">Create the DNS record for this alias (<?=$dns_provider;?>)</span>
              <span class="form-check-description">Adds an <b>A</b> record (or wildcard <b>A</b>) pointing to this server. A subdomain of <b><?=$domain;?></b> is added to its zone; a separate domain gets a new zone. Uncheck if DNS is managed elsewhere.</span>
            </label>
          </div>
          <?php endif; ?>
          <?php if(!$dns_ready): ?>
          <div class="alert alert-warning" role="alert" style="background:#FFE;">
            <b>Note:</b> No DNS provider is configured, so a <b>wildcard</b> alias cannot get an automatic SSL certificate
            (that needs a DNS-01 challenge). You can still add it; SSL for wildcards is handled once a DNS provider is set.
          </div>
          <?php endif; ?>
        </div>
        <div class="modal-footer">
          <a href="#" class="btn btn-link link-secondary" data-bs-dismiss="modal">Cancel</a>
          <button id="submit-alias" class="btn btn-primary" type="submit">Add alias</button>
        </div>
      </div>
    </div>
  </div>
</form>

<?php if($tab === 'config'): ?>
<!-- Config validation error modal (shown when pre-flight validation fails) -->
<div class="modal modal-blur fade" id="config-error-modal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-status bg-danger"></div>
      <div class="modal-body py-4">
        <div class="text-center mb-2">
          <svg xmlns="http://www.w3.org/2000/svg" class="icon mb-2 text-danger icon-lg" width="48" height="48" viewBox="0 0 24 24" stroke-width="2" stroke="#d63939" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="12" cy="12" r="9" /><line x1="12" y1="8" x2="12" y2="12" /><line x1="12" y1="16" x2="12.01" y2="16" /></svg>
          <h3 class="mb-1">Configuration is invalid</h3>
          <div class="text-muted">Your changes were <b>not</b> saved. Fix the error below, or revert to the last saved version.</div>
        </div>
        <pre id="config-error-text" style="text-align:left;background:#fff5f5;border:1px solid #f2d0d0;color:#b42318;padding:10px 12px;margin-top:12px;max-height:240px;overflow:auto;font-size:12px;white-space:pre-wrap;"></pre>
      </div>
      <div class="modal-footer">
        <div class="w-100">
          <div class="row">
            <div class="col"><button type="button" class="btn btn-white w-100" data-bs-dismiss="modal">Continue editing</button></div>
            <div class="col"><button type="button" id="config-revert-btn" class="btn btn-danger w-100">Revert changes</button></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Version-history preview / restore modal -->
<div class="modal modal-blur fade" id="config-version-modal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Saved version &mdash; <span id="config-version-when" class="text-muted fw-normal"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="text-muted mb-2" style="font-size:.85rem;">This is a read-only preview. Restoring validates the version and, if it passes, saves it as the current config and reloads the service.</div>
        <pre id="config-version-text" style="background:#f6f8fb;border:1px solid #dee2e6;padding:10px 12px;max-height:50vh;overflow:auto;font-size:12px;white-space:pre;"></pre>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal">Cancel</button>
        <form method="post" action="/" id="config-restore-form" style="margin:0;">
          <input type="hidden" name="action" value="config-restore">
          <input type="hidden" name="user" id="config-restore-user" value="">
          <input type="hidden" name="file" id="config-restore-file" value="">
          <input type="hidden" name="version" id="config-restore-version" value="">
          <button type="submit" class="btn btn-primary">
            <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M9 14l-4 -4l4 -4"/><path d="M5 10h11a4 4 0 1 1 0 8h-1"/></svg>
            Restore this version
          </button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
    include('templates/footer.php');
?>
<script>
jQuery(document).ready(function () {
	'use strict';

	$('#modal-add-alias').on('shown.bs.modal', function () { $('#alias-input').trigger('focus'); });

	// Validate the alias via ajax-alias before submitting (mirrors the create form).
	$('#add-alias-form').on('submit', function (event) {
		event.preventDefault();
		var alias = $('#alias-input').val().trim();
		if (alias === '') {
			$('#alias-input').addClass('is-invalid');
			$('#alias-invalid').text('Please enter an alias domain.');
			return;
		}
		$('#submit-alias').prop('disabled', true).text('Checking...');
		$.post('/?ajax=1', { action: 'ajax-alias', user: '<?=$acct['user'];?>', alias: alias })
			.done(function (msg) {
				if (msg && msg.trim() !== '') {
					$('#alias-input').addClass('is-invalid');
					$('#alias-invalid').text(msg.replace(/^Error:\s*/, ''));
					$('#submit-alias').prop('disabled', false).text('Add alias');
				} else {
					$('#alias-input').removeClass('is-invalid');
					$('#submit-alias').text('Adding...');
					$('#add-alias-form').off('submit').get(0).submit();
				}
			})
			.fail(function () {
				$('#submit-alias').prop('disabled', false).text('Add alias');
				$('#alias-input').addClass('is-invalid');
				$('#alias-invalid').text('Could not validate alias, please try again.');
			});
	});

	$('#alias-input').on('input', function () { $(this).removeClass('is-invalid'); });
});
</script>
<?php if($tab === 'config'): ?>
<script src="./dist/libs/codemirror/codemirror.min.js"></script>
<script src="./dist/libs/codemirror/mode/nginx/nginx.min.js"></script>
<script src="./dist/libs/codemirror/mode/properties/properties.min.js"></script>
<script src="./dist/libs/codemirror/addon/edit/matchbrackets.min.js"></script>
<script>
(function () {
	'use strict';
	var editors = {};      // panel id -> CodeMirror instance
	var originals = {};    // panel id -> last-saved content (for Revert)
	var pendingPanel = null;

	// Turn each config textarea into a CodeMirror editor.
	document.querySelectorAll('.config-editor').forEach(function (ta) {
		var mode = ta.getAttribute('data-mode') === 'nginx' ? 'nginx' : 'properties';
		var panel = ta.closest('.config-panel');
		if (panel) originals[panel.id] = ta.value;   // capture before CM edits it
		// Ctrl/Cmd+S saves this editor's config (runs the same pre-flight validation
		// as the button) instead of opening the browser's save dialog.
		var saveShortcut = function () {
			var form = ta.form;
			if (!form) return;
			var btn = form.querySelector('button[type="submit"]');
			if (btn) btn.click();   // fires the submit handler -> validate -> save
		};
		var cm = CodeMirror.fromTextArea(ta, {
			lineNumbers: true,
			mode: mode,
			matchBrackets: true,
			indentUnit: 4,
			lineWrapping: true,        // wrap long lines (no bottom horizontal scrollbar)
			viewportMargin: Infinity,  // render all lines so the editor grows to content
			extraKeys: {
				'Ctrl-S': function () { saveShortcut(); },
				'Cmd-S':  function () { saveShortcut(); }
			}
		});
		if (panel) editors[panel.id] = cm;
	});

	// Config file switcher (nginx/apache vhost / php-fpm pool) — selectgroup radios.
	document.querySelectorAll('#config-subtabs input[name="config-file"]').forEach(function (radio) {
		radio.addEventListener('change', function () {
			var target = this.getAttribute('data-cfg');
			document.querySelectorAll('.config-panel').forEach(function (p) {
				p.style.display = (p.id === target) ? '' : 'none';
			});
			if (editors[target]) setTimeout(function () { editors[target].refresh(); }, 1);
		});
	});

	// Pre-flight validation on "Save & reload": validate via AJAX (no live change);
	// on failure show the error modal, letting the user keep editing or revert.
	document.querySelectorAll('.config-panel form').forEach(function (form) {
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var panel = form.closest('.config-panel');
			var cm = editors[panel.id];
			if (cm) cm.save();   // sync editor -> textarea
			var ta = form.querySelector('.config-editor');
			var btn = form.querySelector('button[type="submit"]');
			var fileVal = form.querySelector('input[name="file"]').value;
			var label = btn.textContent;
			btn.disabled = true; btn.textContent = 'Validating…';

			$.post('/?ajax=1', { action: 'ajax-config-validate', user: '<?=$acct['user'];?>', file: fileVal, content: ta.value }, null, 'json')
				.done(function (r) {
					if (r && r.ok) {
						form.submit();   // native submit (skips this handler) -> real save
					} else {
						document.getElementById('config-error-text').textContent = (r && r.error) ? r.error : 'Configuration test failed.';
						pendingPanel = panel.id;
						bootstrap.Modal.getOrCreateInstance(document.getElementById('config-error-modal')).show();
						btn.disabled = false; btn.textContent = label;
					}
				})
				.fail(function () {
					btn.disabled = false; btn.textContent = label;
					document.getElementById('config-error-text').textContent = 'Could not run validation. Please try again.';
					pendingPanel = null;
					bootstrap.Modal.getOrCreateInstance(document.getElementById('config-error-modal')).show();
				});
		});
	});

	// Revert: restore the editor to the last-saved content and close the modal.
	var revertBtn = document.getElementById('config-revert-btn');
	if (revertBtn) revertBtn.addEventListener('click', function () {
		if (pendingPanel && editors[pendingPanel]) editors[pendingPanel].setValue(originals[pendingPanel]);
		bootstrap.Modal.getOrCreateInstance(document.getElementById('config-error-modal')).hide();
	});

	// Toolbar dropdowns: this app hand-rolls dropdown open/close (Bootstrap's
	// data-api dropdown isn't wired here — mirror the dashboard pattern).
	$('.cfg-dd-toggle').on('click', function (e) {
		e.preventDefault();
		var $menu = $(this).siblings('.dropdown-menu');
		var isOpen = $menu.hasClass('show');
		$('.cfg-dd-toggle').siblings('.dropdown-menu').removeClass('show');
		if (!isOpen) $menu.addClass('show');
	});
	$(document).on('click', function (e) {
		if (!$(e.target).closest('.dropdown').length)
			$('.cfg-dd-toggle').siblings('.dropdown-menu').removeClass('show');
	});
	function closeMenus() { $('.cfg-dd-toggle').siblings('.dropdown-menu').removeClass('show'); }

	// Snippets: insert the chosen block at the editor cursor (never clobbers the file).
	document.querySelectorAll('.cfg-snippet').forEach(function (item) {
		item.addEventListener('click', function (e) {
			e.preventDefault();
			var cm = editors[this.getAttribute('data-target')];
			if (!cm) return;
			var text = this.getAttribute('data-snippet');
			var doc = cm.getDoc();
			// ensure the snippet lands on its own lines
			var cur = doc.getCursor();
			var line = doc.getLine(cur.line);
			var prefix = (cur.ch > 0 && line.trim() !== '') ? '\n' : '';
			doc.replaceSelection(prefix + text);
			closeMenus();
			cm.focus();
		});
	});

	// Version history: preview a saved backup, then optionally restore it.
	document.querySelectorAll('.cfg-version').forEach(function (item) {
		item.addEventListener('click', function (e) {
			e.preventDefault();
			var user = this.getAttribute('data-user');
			var file = this.getAttribute('data-file');
			var id   = this.getAttribute('data-id');
			var when = this.getAttribute('data-when');
			document.getElementById('config-version-when').textContent = when;
			document.getElementById('config-restore-user').value    = user;
			document.getElementById('config-restore-file').value    = file;
			document.getElementById('config-restore-version').value = id;
			closeMenus();
			var pre = document.getElementById('config-version-text');
			pre.textContent = 'Loading…';
			bootstrap.Modal.getOrCreateInstance(document.getElementById('config-version-modal')).show();
			// Backend (ajax-config-versions) is wired in the next step; demo preview for now.
			$.post('/?ajax=1', { action: 'ajax-config-versions', user: user, file: file, version: id }, null, 'json')
				.done(function (r) { pre.textContent = (r && r.content) ? r.content : '(preview not available yet — backend pending)'; })
				.fail(function () { pre.textContent = '(preview not available yet — backend pending)'; });
		});
	});
})();
</script>
<?php endif; ?>
</body>
</html>
