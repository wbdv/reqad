<?php
	/* Per-account ADVANCED management page: /account/<user>
	   Account editing (quota/password/PHP/email) lives in the accounts-list modal.
	   This page holds the premium-only Alias Domains and Advanced Config tabs.
	   $acct_user is set by index.php. */

	$php_versions = array_map('trim', explode(',', $ini['php_versions']));
	$front_httpd = (web_front($ini) === 'httpd');   // the vhost editor shows the front server's vhost
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

	/* The account's active PHP version + handler ('off'/'off' when PHP is disabled). */
	list($phpversion, $phphandler) = account_php($domain);

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
		/* php-fpm pool exists when account_php() says fpm: nginx (unless PHP is off) and apache accounts on fpm */
		$has_fpm = ($phphandler === 'fpm');
		if($has_fpm) {
			$cfg_fpm = account_config_target($ini, $domain, 'fpm');
			$cfg_fpm_content = (string)shell_exec('sudo cat '.escapeshellarg($cfg_fpm['path']).' 2>/dev/null');
			if(trim($cfg_fpm_content) === '') $cfg_fpm = null;  // no pool file -> hide the tab
		}
		/* Process manager helper: what one worker of this pool really costs - PSS,
		   so the code and libraries all workers share are counted once. 0 = none
		   running right now (ondemand pool after 10 s idle); the dialog then
		   uses a typical figure. */
		$pm_worker_mb = 0;
		if($cfg_fpm) {
			$pm_pss = array();
			foreach(preg_split('/\s+/', trim((string)shell_exec('pgrep -f '.escapeshellarg('^php-fpm: pool '.$domain.'$').' 2>/dev/null'))) as $pm_pid) {
				if(!ctype_digit($pm_pid)) continue;
				if(preg_match('/^Pss:\s+(\d+)/m', (string)shell_exec('sudo cat /proc/'.$pm_pid.'/smaps_rollup 2>/dev/null'), $m))
					$pm_pss[] = (int)$m[1];
			}
			if($pm_pss) $pm_worker_mb = max(1, (int)round(array_sum($pm_pss) / count($pm_pss) / 1024));
		}

		/* Session limits: CPU/memory/processes of the account's SSH, cron and
		   terminal sessions (scripts/session_limits.sh), plus recent hits. */
		$limits    = account_session_limits($acct['user'], 10);
		/* database connections: per MySQL user of the account (phase 2.7) */
		$dbc_limit = database_conn_limit($acct['user']);
		$dbc_def   = database_conn_is_default($acct['user']);
		$dbc_users = database_account_users($acct['user']);
		$dbc_open  = $dbc_users ? database_conn_now() : array();
		$dbc_pool  = preg_match('/^\s*pm\.max_children\s*=\s*(\d+)/m', $cfg_fpm_content, $m) ? (int)$m[1] : 0;
		$cpu_cores = max(1, (int)trim((string)shell_exec('nproc 2>/dev/null')));
		/* root_access=0: vhost and pool are shown read-only (account_config_editable()) */
		$cfg_edit  = account_config_editable($ini);
		$cfg_want  = (string)($_GET['cfg'] ?? '');
		if($cfg_want === 'limits' && $limits)       $cfg_sel = 'cfg-limits';
		else if($cfg_want === 'fpm' && $cfg_fpm)    $cfg_sel = 'cfg-fpm';
		else                                        $cfg_sel = 'cfg-nginx';

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
			'Enable slow-request log'=> "slowlog = /var/log/php-fpm/\$pool-slow.log\nrequest_slowlog_timeout = 10s\n",
		),
	);
	/* pick the right web-server snippet set for the panel */
	$cfg_web_snippets = $front_httpd ? $cfg_snippets['apache'] : $cfg_snippets['nginx'];

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
                  Advanced settings for &nbsp;<b><?=h($domain);?></b>
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
          #account-main-card { border: none; box-shadow: none; background: transparent; }
          #account-main-card > .card-header { padding-bottom: 0; background: transparent; border-bottom-color: #dee2e6; }
          #account-tabs { border-bottom: 0; gap: 30px; }
          #account-tabs .nav-link {
              display: block;
              transition: background-color .15s ease-in-out, color .15s ease-in-out;   /* the border goes at once: no ghost of it */
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
          .account-tab-pane { border: 1px solid; border-color: transparent #dee2e6 #dee2e6 #dee2e6; background: #fff; box-shadow: rgba(35,46,60,.04) 0 2px 4px 0; }
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
          .CodeMirror.cm-readonly, .CodeMirror.cm-readonly .CodeMirror-gutters { background:#f8f9fa; }
          .CodeMirror.cm-readonly .CodeMirror-cursor { display:none; }
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
                          <div class="text-muted"><b>Note:</b> Alias domains are supplementary domains that share the same document root, config files and serve the same site as main domain, <code><?=h($domain);?></code>. Wildcard domains like <code>*.<?=h($domain);?></code> are allowed.</div>
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
                                <?php if($is_wild): ?><span class="badge bg-purple-lt border">wildcard</span>
                                <?php else: ?><span class="badge bg-blue-lt border">standard</span><?php endif; ?>
                              </td>
                              <td>
                                <?php
                                  /* Live SSL coverage from the installed cert. Wildcards are validated
                                     via DNS-01 and appear in the SAN once the re-issue completes. */
                                  if(!$uses_le): ?><span class="text-muted" title="This account is not using a Let's Encrypt certificate">&mdash;</span>
                                <?php elseif(alias_is_covered($a['alias'], $cert_san)): ?><span class="badge bg-success-lt border">covered</span>
                                <?php elseif($is_wild && !$dns_ready): ?><span class="text-muted" title="Wildcard SSL needs a DNS provider (DNS-01). Configure one in DNS Settings.">needs DNS</span>
                                <?php elseif($is_wild): ?><span class="badge bg-orange-lt border" title="Validated via DNS-01. Click Reissue SSL to obtain/refresh the wildcard certificate.">pending (DNS-01)</span>
                                <?php else: ?><span class="badge bg-orange-lt border" title="Not in the certificate yet — resolve the domain to this server, then Reissue SSL">pending</span><?php endif; ?>
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
                                <span class="font-weight-medium">mail.<?=h($domain);?></span>
                                <span class="badge bg-secondary-lt border ms-1">mail</span>
                              </td>
                              <td><span class="badge bg-blue-lt border">standard</span></td>
                              <td>
                                <?php if(!$uses_le): ?><span class="text-muted">&mdash;</span>
                                <?php elseif(alias_is_covered('mail.'.$domain, $cert_san)): ?><span class="badge bg-success-lt">covered</span>
                                <?php else: ?><span class="badge bg-orange-lt" title="Reissue SSL to cover mail.<?=h($domain);?>">pending</span><?php endif; ?>
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
                      <?php if($cfg_edit): ?>
                        Edit this account's server configuration. Changes are <b>validated before saving</b> and
                        automatically reverted if the config test fails.
                      <?php else: ?>
                        This account's server configuration, <b>read-only</b>: changing the vhost and pool files needs root access
                        (<code>root_access=1</code> in <code>server-software.ini</code>). The session limits can still be changed.
                      <?php endif; ?>
                      </div>

                      <div class="form-selectgroup form-selectgroup-boxes d-flex flex-wrap mb-3" id="config-subtabs">
                        <label class="form-selectgroup-item" style="min-width:210px;">
                          <input type="radio" name="config-file" value="cfg-nginx" data-cfg="cfg-nginx" class="form-selectgroup-input" <?=$cfg_sel==='cfg-nginx'?'checked=""':'';?>>
                          <div class="form-selectgroup-label d-flex align-items-center p-3" style="height:72px;">
                            <div class="me-3"><span class="form-selectgroup-check"></span></div>
                            <span class="me-2 d-inline-flex align-items-center">
                            <?php if($front_httpd): ?>
                              <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="#d22128" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20 L20 4"/><path d="M20 4 C20 12 14 18 6 18 L4 20"/><path d="M9 15 L14 15"/><path d="M12 11 L16 11"/></svg>
                            <?php else: ?>
                              <svg width="32" height="36" viewBox="0 0 100 112" xmlns="http://www.w3.org/2000/svg"><path d="M50 1 4 27.5v57L50 111l46-26.5v-57z" fill="#009639"/><path d="M35 80V37l30 38V32" fill="none" stroke="#fff" stroke-width="8" stroke-linejoin="miter"/></svg>
                            <?php endif; ?>
                            </span>
                            <span style="font-size:15px;color:#354052;font-weight:500;"><?=$front_httpd?'apache vhost':'nginx vhost';?></span>
                          </div>
                        </label>
                        <?php if($cfg_fpm): ?>
                        <label class="form-selectgroup-item" style="min-width:210px;">
                          <input type="radio" name="config-file" value="cfg-fpm" data-cfg="cfg-fpm" class="form-selectgroup-input" <?=$cfg_sel==='cfg-fpm'?'checked=""':'';?>>
                          <div class="form-selectgroup-label d-flex align-items-center p-3" style="height:72px;">
                            <div class="me-3"><span class="form-selectgroup-check"></span></div>
                            <span class="me-2 d-inline-flex align-items-center">
                              <svg width="54" height="29" viewBox="0 0 100 54" xmlns="http://www.w3.org/2000/svg"><ellipse cx="50" cy="27" rx="49" ry="26" fill="#777BB3"/><text x="50" y="37" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-weight="bold" font-style="italic" font-size="27" fill="#fff">php</text></svg>
                            </span>
                            <span style="font-size:15px;color:#354052;font-weight:500;">php-fpm pool</span>
                          </div>
                        </label>
                        <?php endif; ?>
                        <?php if($limits): ?>
                        <label class="form-selectgroup-item" style="min-width:210px;">
                          <input type="radio" name="config-file" value="cfg-limits" data-cfg="cfg-limits" class="form-selectgroup-input" <?=$cfg_sel==='cfg-limits'?'checked=""':'';?>>
                          <div class="form-selectgroup-label d-flex align-items-center p-3" style="height:72px;">
                            <div class="me-3"><span class="form-selectgroup-check"></span></div>
                            <span class="me-2 d-inline-flex align-items-center">
                              <svg xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="#f76707" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0"/><path d="M12 12m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0"/><path d="M13.41 10.59l2.59 -2.59"/><path d="M7 12a5 5 0 0 1 5 -5"/></svg>
                            </span>
                            <span style="font-size:15px;color:#354052;font-weight:500;">Limits</span>
                          </div>
                        </label>
                        <?php endif; ?>
                      </div>

                      <div class="config-panel" id="cfg-nginx" <?=$cfg_sel==='cfg-nginx'?'':'style="display:none;"';?>>
                        <div class="d-flex align-items-center mb-2" style="gap:10px;">
                          <div class="text-muted" style="font-size:.85rem;">File: <code><?=$cfg_nginx['path'];?></code><?php if(!$cfg_edit): ?> <span class="badge ms-1" style="background:#f1f3f5;color:#6c757d;">Read-only</span><?php endif; ?></div>
                          <div class="ms-auto btn-list">
                            <?php if($cfg_edit): ?>
                            <?php cfg_snippet_menu('cfg-nginx', $cfg_web_snippets); ?>
                            <?php cfg_version_menu('cfg-nginx', $cfg_versions['cfg-nginx'], $acct['user'], 'nginx'); ?>
                            <?php endif; ?>
                          </div>
                        </div>
                        <?php if(!$cfg_edit): ?>
                        <textarea class="config-editor" data-mode="nginx" data-readonly="1" readonly><?=htmlspecialchars($cfg_nginx_content);?></textarea>
                        <?php else: ?>
                        <form method="post" action="/">
                          <input type="hidden" name="action" value="config-save">
                          <input type="hidden" name="user" value="<?=$acct['user'];?>">
                          <input type="hidden" name="file" value="nginx">
                          <textarea name="content" class="config-editor" data-mode="nginx"><?=htmlspecialchars($cfg_nginx_content);?></textarea>
                          <div class="mt-2">
                            <button class="btn btn-primary" type="submit">Save &amp; reload</button>
							<!--
                            <span class="text-muted ms-2" style="font-size:.85rem;">Validated with <code><?=$front_httpd?'httpd -t':'nginx -t';?></code>; reverted if invalid.</span>
							-->
                          </div>
                        </form>
                        <?php endif; ?>
                      </div>

                      <?php if($cfg_fpm): ?>
                      <div class="config-panel" id="cfg-fpm" <?=$cfg_sel==='cfg-fpm'?'':'style="display:none;"';?>>
                        <div class="d-flex align-items-center mb-2" style="gap:10px;">
                          <div class="text-muted" style="font-size:.85rem;">File: <code><?=$cfg_fpm['path'];?></code> &middot; <?=$cfg_fpm['label'];?><?php if(!$cfg_edit): ?> <span class="badge ms-1" style="background:#f1f3f5;color:#6c757d;">Read-only</span><?php endif; ?></div>
                          <div class="ms-auto btn-list">
                            <?php if($cfg_edit): ?>
                            <button type="button" class="btn btn-white btn-sm" id="pm-open">
                              <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-sm" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 6l8 0"/><path d="M16 6l4 0"/><path d="M8 12l12 0"/><path d="M4 12l0 0"/><path d="M4 18l12 0"/><path d="M20 18l0 0"/><path d="M14 4v4"/><path d="M6 10v4"/><path d="M18 16v4"/></svg>
                              Process manager
                            </button>
                            <?php endif; ?>
                            <?php if($cfg_edit): ?>
                            <?php cfg_snippet_menu('cfg-fpm', $cfg_snippets['fpm']); ?>
                            <?php cfg_version_menu('cfg-fpm', $cfg_versions['cfg-fpm'], $acct['user'], 'fpm'); ?>
                            <?php endif; ?>
                          </div>
                        </div>
                        <?php if(!$cfg_edit): ?>
                        <textarea class="config-editor" data-mode="properties" data-readonly="1" readonly><?=htmlspecialchars($cfg_fpm_content);?></textarea>
                        <?php else: ?>
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
                        <?php endif; ?>
                      </div>
                      <?php endif; ?>

                      <?php if($limits):
                        $lm_mode  = in_array($limits['mode'], array('custom', 'none'), true) ? $limits['mode'] : 'default';
                        $lm_def   = array('cpu' => (int)$limits['default_cpu'], 'mem' => (int)$limits['default_memory_mb'], 'tasks' => (int)$limits['default_tasks']);
                        $lm_cur   = array('cpu' => $limits['cpu'] ?? $lm_def['cpu'], 'mem' => $limits['memory_mb'] ?? $lm_def['mem'], 'tasks' => $limits['tasks'] ?? $lm_def['tasks']);
                        $lm_on    = $limits['active'];
                        $lm_hm    = (int)$limits['hits_memory_30d'];
                        $lm_ht    = (int)$limits['hits_tasks_30d'];
                        $lm_mem_gb = function ($mb) { return $mb >= 1024 ? rtrim(rtrim(number_format($mb / 1024, 1), '0'), '.').' GB' : $mb.' MB'; };
                        /* one card per limit: key, title, unit, input attrs, value now, % of the cap, note, icon path */
                        $lm_cards = array(
                          array('cpu', 'CPU', '%', 'name="cpu" min="5" max="'.($cpu_cores*100).'" step="5"',
                                $lm_on ? ((int)$limits['cpu_throttled_s'] > 0 ? 'slowed down for '.(int)$limits['cpu_throttled_s'].' s' : 'not slowed down') : null, null,
                                '100% = one core, this server has '.$cpu_cores.'. A busy job is slowed down, never stopped.',
                                '<path d="M5 5m0 1a1 1 0 0 1 1 -1h12a1 1 0 0 1 1 1v12a1 1 0 0 1 -1 1h-12a1 1 0 0 1 -1 -1z"/><path d="M9 9h6v6h-6z"/><path d="M3 10h2"/><path d="M3 14h2"/><path d="M10 3v2"/><path d="M14 3v2"/><path d="M21 10h-2"/><path d="M21 14h-2"/><path d="M14 21v-2"/><path d="M10 21v-2"/>'),
                          array('mem', 'Memory', 'MB', 'name="memory" min="64" step="64"',
                                $lm_on ? (int)$limits['mem_now_mb'].' MB in use'.(isset($limits['mem_peak_mb']) ? ', peak '.(int)$limits['mem_peak_mb'].' MB' : '') : null,
                                $lm_on && $lm_mode !== 'none' ? min(100, round((int)$limits['mem_now_mb'] * 100 / max(1, $lm_cur['mem']))) : null,
                                'At the cap the kernel stops the account\'s largest process.',
                                '<path d="M6 4h12a1 1 0 0 1 1 1v14a1 1 0 0 1 -1 1h-12a1 1 0 0 1 -1 -1v-14a1 1 0 0 1 1 -1z"/><path d="M9 8v8"/><path d="M12 8v8"/><path d="M15 8v8"/>'),
                          array('tasks', 'Processes', '', 'name="processes" min="16" max="100000"',
                                $lm_on ? (int)$limits['tasks_now'].' running' : null,
                                $lm_on && $lm_mode !== 'none' ? min(100, round((int)$limits['tasks_now'] * 100 / max(1, $lm_cur['tasks']))) : null,
                                'At the cap new processes are refused.',
                                '<path d="M6 20a2 2 0 1 0 -4 0a2 2 0 0 0 4 0z"/><path d="M16 4a2 2 0 1 0 -4 0a2 2 0 0 0 4 0z"/><path d="M16 20a2 2 0 1 0 -4 0a2 2 0 0 0 4 0z"/><path d="M11 12a2 2 0 1 0 -4 0a2 2 0 0 0 4 0z"/><path d="M21 12a2 2 0 1 0 -4 0a2 2 0 0 0 4 0z"/><path d="M5.058 18.306l2.88 -4.606"/><path d="M10.061 10.303l2.877 -4.604"/><path d="M10.065 13.705l2.876 4.6"/><path d="M15.063 5.7l2.881 4.61"/>'),
                        );
                      ?>
                      <div class="config-panel" id="cfg-limits" <?=$cfg_sel==='cfg-limits'?'':'style="display:none;"';?>>
                        <style>
                          #cfg-limits .lm-card { height:100%; }
                          #cfg-limits .lm-icon { display:inline-flex; align-items:center; color:#495057; }
                          #limits-mode .btn-check:not(:checked) + .btn:hover { background:rgba(32,107,196,.5); border-color:rgba(32,107,196,.5); color:#fff; }
                          #limits-mode .btn-check:checked + .btn { background:#206bc4; border-color:#206bc4; color:#fff; }
                          #cfg-limits .lm-value { font-size:1.6rem; font-weight:600; color:#1d273b; line-height:1.2; }
                          #cfg-limits .lm-value small { font-size:.95rem; font-weight:500; color:#6c757d; }
                          #cfg-limits .lm-note { font-size:.8rem; color:#6c757d; }
                          #cfg-limits .lm-input { max-width:170px; }
                          #cfg-limits[data-mode="custom"] .lm-show, #cfg-limits:not([data-mode="custom"]) .lm-edit { display:none !important; }
                          #cfg-limits[data-mode="none"] .lm-lim { display:none; }
                          #cfg-limits:not([data-mode="none"]) .lm-unl { display:none; }
                          #cfg-limits:not([data-mode="default"]) .lm-def, #cfg-limits:not([data-mode="custom"]) .lm-cus { display:none; }
                        </style>
                        <form method="post" action="/" id="limits-form">
                          <input type="hidden" name="action" value="account-limits">
                          <input type="hidden" name="user" value="<?=$acct['user'];?>">

                          <div class="d-flex flex-wrap align-items-center mb-3" style="gap:12px;">
                            <div style="flex:1 1 300px;min-width:0;">
                              <div class="fw-bold" style="font-size:1rem;">SSH, cron and web terminal</div>
                              <div class="text-muted" style="font-size:.85rem;">Everything <b><?=h($acct['user']);?></b> runs there shares these limits. The website's PHP is set by the php-fpm pool.</div>
                              <div class="mt-1" style="font-size:.8rem;">
                                <?php if($lm_on): ?><span class="badge bg-green-lt">Session running - usage below</span>
                                <?php else: ?><span class="badge" style="background:#f1f3f5;color:#6c757d;">No session running now</span><?php endif; ?>
                              </div>
                            </div>
                            <div class="ms-auto d-flex align-items-center flex-wrap" style="gap:10px;">
                            <span class="text-muted" style="font-size:.9rem;" id="limits-mode-label">Switch limits:</span>
                            <div class="btn-group" role="group" id="limits-mode" aria-labelledby="limits-mode-label">
                              <?php foreach(array('default' => 'Server defaults', 'custom' => 'Custom', 'none' => 'No limit') as $lm_k => $lm_l): ?>
                              <input type="radio" class="btn-check" name="mode" value="<?=$lm_k;?>" id="lm-mode-<?=$lm_k;?>" autocomplete="off" <?=$lm_mode===$lm_k?'checked':'';?>>
                              <label class="btn btn-outline-primary" for="lm-mode-<?=$lm_k;?>"><?=$lm_l;?></label>
                              <?php endforeach; ?>
                            </div>
                            </div>
                          </div>

                          <div class="row row-cards mb-3">
                            <?php foreach($lm_cards as $c):
                              list($k, $title, $unit, $attrs, $now, $pct, $note, $icon) = $c;
                              $fmt = function ($v) use ($k, $lm_mem_gb) { return $k === 'mem' ? $lm_mem_gb($v) : ($k === 'cpu' ? $v.'%' : number_format($v)); };
                            ?>
                            <div class="col-md-4">
                              <div class="card card-sm lm-card">
                                <div class="card-body">
                                  <div class="d-flex align-items-center mb-2" style="gap:10px;">
                                    <span class="lm-icon"><svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?=$icon;?></svg></span>
                                    <span class="fw-bold"><?=$title;?></span>
                                  </div>
                                  <div class="lm-value lm-show">
                                    <span class="lm-unl">No limit</span>
                                    <span class="lm-lim lm-def"><?=$fmt($lm_def[$k]);?></span>
                                  </div>
                                  <div class="lm-edit mb-1">
                                    <div class="input-group lm-input">
                                      <input type="number" class="form-control" <?=$attrs;?> value="<?=$lm_cur[$k];?>">
                                      <?php if($unit !== ''): ?><span class="input-group-text"><?=$unit;?></span><?php endif; ?>
                                    </div>
                                  </div>
                                  <?php if($pct !== null): ?>
                                  <div class="progress progress-sm mt-2 lm-lim"><div class="progress-bar bg-<?=$pct>=90?'red':($pct>=70?'orange':'green');?>" style="width:<?=max(2, $pct);?>%"></div></div>
                                  <?php endif; ?>
                                  <?php if($now !== null): ?><div class="mt-1" style="font-size:.85rem;"><?=h($now);?></div><?php endif; ?>
                                  <div class="lm-note mt-2"><?=h($note);?></div>
                                </div>
                              </div>
                            </div>
                            <?php endforeach; ?>
                          </div>

                          <div class="d-flex align-items-center flex-wrap" style="gap:10px;">
                            <button class="btn btn-primary" type="submit" id="limits-save" disabled>Save</button>
                            <span class="text-muted" style="font-size:.85rem;">Applies at once, also to sessions already running.</span>
                          </div>
                        </form>

                        <hr class="my-4">

                        <!-- database connections (MAX_USER_CONNECTIONS per MySQL user) -->
                        <form method="post" action="/" id="dbc-form">
                          <input type="hidden" name="action" value="account-db-limit">
                          <input type="hidden" name="user" value="<?=$acct['user'];?>">
                          <div class="d-flex flex-wrap align-items-center mb-3" style="gap:12px;">
                            <div style="flex:1 1 300px;min-width:0;">
                              <div class="fw-bold" style="font-size:1rem;">Databases</div>
                              <div class="text-muted" style="font-size:.85rem;">Connections at once for each of <b><?=h($acct['user']);?></b>'s MySQL users, so one site cannot take all of the server's connections. More are refused with "too many connections".</div>
                            </div>
                          </div>
                          <div class="row row-cards mb-3">
                            <div class="col-md-4">
                              <div class="card card-sm lm-card">
                                <div class="card-body">
                                  <div class="d-flex align-items-center mb-2" style="gap:10px;">
                                    <span class="lm-icon"><svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 6m-8 0a8 3 0 1 0 16 0a8 3 0 1 0 -16 0"/><path d="M4 6v6a8 3 0 0 0 16 0v-6"/><path d="M4 12v6a8 3 0 0 0 16 0v-6"/></svg></span>
                                    <span class="fw-bold">Connections per database user</span>
                                  </div>
                                  <div class="d-flex align-items-center" style="gap:8px;">
                                    <input type="number" class="form-control" name="connections" id="dbc-num" min="0" max="10000" value="<?=$dbc_limit;?>" style="max-width:110px;">
                                    <label class="form-check mb-0"><input class="form-check-input" type="checkbox" name="default" id="dbc-default" value="1" <?=$dbc_def?'checked':'';?>><span class="form-check-label">Server default (<?=database_conn_default();?>)</span></label>
                                  </div>
                                  <div class="lm-note mt-2">0 = no limit. Takes effect for new connections.</div>
                                  <?php if($dbc_pool && $dbc_limit && $dbc_pool >= $dbc_limit): ?>
                                  <div class="text-warning mt-2" style="font-size:.8rem;">The PHP pool may run <?=$dbc_pool;?> workers; with <?=$dbc_limit;?> connections a busy site can be refused. Use at least <?=$dbc_pool + 10;?>.</div>
                                  <?php endif; ?>
                                </div>
                              </div>
                            </div>
                            <div class="col-md-8">
                              <div class="card card-sm lm-card">
                                <div class="card-body">
                                  <div class="fw-bold mb-2">Database users</div>
                                  <?php if(!$dbc_users): ?>
                                  <div class="text-muted" style="font-size:.85rem;">None yet - users created later get this limit.</div>
                                  <?php else: ?>
                                  <table class="table table-sm table-vcenter mb-0" style="font-size:.85rem;">
                                    <thead><tr><th>User</th><th>Open now</th><th>Limit</th></tr></thead>
                                    <tbody>
                                    <?php foreach($dbc_users as $du): $dopen = (int)($dbc_open[$du['user']] ?? 0); ?>
                                      <tr>
                                        <td><code><?=h($du['user']);?></code><?=$du['host'] !== 'localhost' ? ' <span class="text-muted">@'.h($du['host']).'</span>' : '';?></td>
                                        <td><?=$dopen;?><?php if($du['limit'] && $dopen >= $du['limit']): ?> <span class="badge bg-red-lt">at the limit</span><?php endif; ?></td>
                                        <td><?=$du['limit'] ? (int)$du['limit'] : 'none';?><?php if($du['limit'] !== $dbc_limit): ?> <span class="text-muted">(not applied yet)</span><?php endif; ?></td>
                                      </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                  </table>
                                  <?php endif; ?>
                                </div>
                              </div>
                            </div>
                          </div>
                          <button class="btn btn-primary" type="submit" id="dbc-save" disabled>Save</button>
                        </form>

                        <hr class="my-4">

                        <div class="d-flex align-items-center mb-2" style="gap:10px;">
                          <div class="fw-bold" style="font-size:1rem;">Limit hits</div>
                          <span class="text-muted" style="font-size:.85rem;">last 30 days</span>
                          <?php if($lm_hm): ?><span class="badge bg-red-lt"><?=$lm_hm;?> &times; memory</span><?php endif; ?>
                          <?php if($lm_ht): ?><span class="badge bg-orange-lt"><?=$lm_ht;?> &times; processes</span><?php endif; ?>
                        </div>
                        <?php if($limits['events']): ?>
                        <div class="table-responsive" style="max-width:900px;">
                          <table class="table table-sm table-vcenter card-table mb-2" style="font-size:.875rem;">
                            <thead><tr><th style="width:170px;">Time</th><th style="width:110px;">Limit</th><th>What happened</th></tr></thead>
                            <tbody>
                            <?php foreach($limits['events'] as $ev): ?>
                              <tr>
                                <td class="text-nowrap text-muted"><?=h($ev['time']);?></td>
                                <td><span class="badge bg-<?=$ev['limit']==='memory'?'red':'orange';?>-lt"><?=$ev['limit']==='memory'?'Memory':'Processes';?></span></td>
                                <td><?=h(ucfirst($ev['detail']));?></td>
                              </tr>
                            <?php endforeach; ?>
                            </tbody>
                          </table>
                        </div>
                        <?php else: ?>
                        <div class="d-flex align-items-center text-muted mb-2" style="gap:8px;font-size:.9rem;">
                          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#2fb344" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0"/><path d="M9 12l2 2l4 -4"/></svg>
                          No process was stopped or refused<?=$lm_mode==='none'?'':' - the limits were never reached';?>.
                        </div>
                        <?php endif; ?>
                        <div class="text-muted" style="font-size:.8rem;">
                          Log: <code>/var/log/reqad/session-limits.log</code> &middot; over SSH: <code>reqad account limits <?=h($acct['user']);?></code>
                        </div>
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
            <input type="text" name="alias" id="alias-input" class="form-control" placeholder="alias.example.com or *.<?=h($domain);?>" autocomplete="off">
            <div class="invalid-feedback" id="alias-invalid">Please enter a valid domain.</div>
            <small class="form-text text-muted" style="display:block;margin-top:8px;">
              Serves the same site as <b><?=h($domain);?></b>. A <b>standard</b> alias must point to this server's IP.
              A <b>wildcard</b> (<code>*.domain</code>) matches every subdomain.
            </small>
          </div>
          <?php if($dns_ready): ?>
          <div class="mb-3">
            <label class="form-check">
              <input class="form-check-input" type="checkbox" name="add_dns" checked="true">
              <span class="form-check-label">Create the DNS record for this alias (<?=$dns_provider;?>)</span>
              <span class="form-check-description">Adds an <b>A</b> record (or wildcard <b>A</b>) pointing to this server. A subdomain of <b><?=h($domain);?></b> is added to its zone; a separate domain gets a new zone. Uncheck if DNS is managed elsewhere.</span>
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

<?php if($tab === 'config' && $cfg_fpm && $cfg_edit): ?>
<!-- Process manager helper (root_access=1 only): "Apply to editor" rewrites the
     pm.* lines in the editor; "Save & reload" has the server rewrite them in the
     pool file (action pool-pm: php-fpm -t validated, versioned). -->
<div class="modal modal-blur fade" id="pm-modal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document" style="max-width:40rem">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">PHP process manager</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted mb-3">How many PHP workers <strong><?=h($domain);?></strong> keeps ready. More workers answer faster under load but stay in memory.</p>
        <div class="form-selectgroup form-selectgroup-boxes d-flex flex-column mb-3" id="pm-presets">
          <label class="form-selectgroup-item">
            <input type="radio" name="pm-preset" value="light" class="form-selectgroup-input">
            <div class="form-selectgroup-label d-flex align-items-center p-3 text-start">
              <div><strong>Light</strong> - the default. Workers start with the first request and stop after 10 seconds idle: no memory while the site is quiet, a few milliseconds more on its first request.</div>
            </div>
          </label>
          <label class="form-selectgroup-item">
            <input type="radio" name="pm-preset" value="busy" class="form-selectgroup-input">
            <div class="form-selectgroup-label d-flex align-items-center p-3 text-start">
              <div><strong>Busy</strong> - 4 workers always ready, more as traffic grows (up to 40). No start-up delay; for shops and busy sites.</div>
            </div>
          </label>
          <label class="form-selectgroup-item">
            <input type="radio" name="pm-preset" value="priority" class="form-selectgroup-input">
            <div class="form-selectgroup-label d-flex align-items-center p-3 text-start">
              <div><strong>Priority</strong> - a fixed 10 workers, always running. The fastest and most predictable; uses the most memory.</div>
            </div>
          </label>
          <label class="form-selectgroup-item">
            <input type="radio" name="pm-preset" value="custom" class="form-selectgroup-input">
            <div class="form-selectgroup-label d-flex align-items-center p-3 text-start">
              <div><strong>Custom</strong> - set the values below.</div>
            </div>
          </label>
        </div>
        <div class="row g-2">
          <div class="col-6">
            <label class="form-label mb-1" for="pm-type">Mode (<code>pm</code>)</label>
            <select class="form-select" id="pm-type">
              <option value="ondemand">ondemand - start on request</option>
              <option value="dynamic">dynamic - keep some ready</option>
              <option value="static">static - fixed number</option>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label mb-1" for="pm-max">Workers at most (<code>pm.max_children</code>)</label>
            <input type="number" class="form-control pm-num" id="pm-max" min="1" max="500">
          </div>
          <div class="col-6 pm-only-ondemand">
            <label class="form-label mb-1" for="pm-idle">Stop idle workers after (s)</label>
            <input type="number" class="form-control pm-num" id="pm-idle" min="1" max="3600">
          </div>
          <div class="col-4 pm-only-dynamic">
            <label class="form-label mb-1" for="pm-start">Start with</label>
            <input type="number" class="form-control pm-num" id="pm-start" min="1" max="500">
          </div>
          <div class="col-4 pm-only-dynamic">
            <label class="form-label mb-1" for="pm-minsp">Keep ready at least</label>
            <input type="number" class="form-control pm-num" id="pm-minsp" min="1" max="500">
          </div>
          <div class="col-4 pm-only-dynamic">
            <label class="form-label mb-1" for="pm-maxsp">... and at most</label>
            <input type="number" class="form-control pm-num" id="pm-maxsp" min="1" max="500">
          </div>
          <div class="col-6">
            <label class="form-label mb-1" for="pm-reqs">Recycle a worker after (requests)</label>
            <input type="number" class="form-control pm-num" id="pm-reqs" min="0" max="100000">
          </div>
          <div class="col-6">
            <label class="form-label mb-1" for="pm-term">Kill a script stuck for (s, 0 = never)</label>
            <input type="number" class="form-control pm-num" id="pm-term" min="0" max="86400">
          </div>
        </div>
        <div class="alert alert-info mt-3 mb-0 py-2" id="pm-memory"></div>
        <div class="text-danger small mt-2" id="pm-error"></div>
      </div>
      <div class="modal-footer">
        <span class="text-muted small me-auto">Saved and checked with <code>php-fpm -t</code>; the old version stays in the history.</span>
        <button type="button" class="btn" data-bs-dismiss="modal">Cancel</button>
        <?php if($cfg_edit): ?>
        <button type="button" class="btn btn-white" id="pm-apply" title="Put the lines into the editor, to save them with other edits">Apply to editor</button>
        <?php endif; ?>
        <button type="button" class="btn btn-primary" id="pm-save">Save &amp; reload</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

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
			readOnly: ta.hasAttribute('data-readonly'),   // root_access=0
			viewportMargin: Infinity,  // render all lines so the editor grows to content
			extraKeys: {
				'Ctrl-S': function () { saveShortcut(); },
				'Cmd-S':  function () { saveShortcut(); }
			}
		});
		if (ta.hasAttribute('data-readonly')) cm.getWrapperElement().classList.add('cm-readonly');
		if (panel) editors[panel.id] = cm;
	});

	// Session limits: the mode switch shows defaults / number fields / "No limit";
	// Save is enabled once something differs from what is saved.
	(function () {
		var panel = document.getElementById('cfg-limits');
		if (!panel) return;
		var form = document.getElementById('limits-form');
		var save = document.getElementById('limits-save');
		var snap = function () { return new URLSearchParams(new FormData(form)).toString(); };
		var saved;
		var sync = function () {
			var m = form.querySelector('input[name="mode"]:checked').value;
			panel.setAttribute('data-mode', m);
			save.disabled = (snap() === saved);
		};
		form.querySelectorAll('input').forEach(function (i) { i.addEventListener('input', sync); i.addEventListener('change', sync); });
		panel.setAttribute('data-mode', form.querySelector('input[name="mode"]:checked').value);
		saved = snap();

		// Databases: "server default" fills and locks the number; Save once changed
		var df = document.getElementById('dbc-form');
		if (df) {
			var num = document.getElementById('dbc-num'), def = document.getElementById('dbc-default');
			var dsave = document.getElementById('dbc-save'), dsaved;
			var dsync = function () {
				if (def.checked) num.value = <?=database_conn_default();?>;
				num.readOnly = def.checked;
				dsave.disabled = (new URLSearchParams(new FormData(df)).toString() === dsaved);
			};
			num.addEventListener('input', dsync); def.addEventListener('change', dsync);
			num.readOnly = def.checked;
			dsaved = new URLSearchParams(new FormData(df)).toString();
		}
	})();

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
		if (!form.querySelector('.config-editor')) return;   // e.g. the session limits form
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

	// PHP process manager helper (php-fpm panel): read the pm.* lines from the
	// editor, offer presets, write the chosen block back in place of the old
	// lines. Saving stays with "Save & reload" (php-fpm -t + version history).
	var pmModal = document.getElementById('pm-modal');
	if (pmModal) {
		document.body.appendChild(pmModal);             // outside the footer's unclosed div
		var cmF = editors['cfg-fpm'];                  // read-only with root_access=0
		var poolText = function () { return cmF.getValue(); };
		var WORKER_MB = <?=(int)$pm_worker_mb;?> || 40;  // measured on this pool, else typical
		var MEASURED  = <?=$pm_worker_mb ? 'true' : 'false';?>;
		var PM_KEYS = ['pm', 'pm.max_children', 'pm.start_servers', 'pm.min_spare_servers', 'pm.max_spare_servers',
		               'pm.process_idle_timeout', 'pm.max_requests', 'request_terminate_timeout'];
		var PRESETS = {
			light:    { pm: 'ondemand', max: 20, idle: 10, reqs: 500, term: 300 },
			busy:     { pm: 'dynamic',  max: 40, start: 4, minsp: 2, maxsp: 8, reqs: 500, term: 300 },
			priority: { pm: 'static',   max: 10, reqs: 1000, term: 300 }
		};
		var byId = function (id) { return document.getElementById(id); };
		var num = function (id) { return parseInt(byId(id).value, 10); };
		var keyRe = function (k) { return new RegExp('^\\s*' + k.replace(/\./g, '\\.') + '\\s*=\\s*([^;\\s]*)', 'm'); };
		var read = function () {
			var t = poolText(), v = {};
			PM_KEYS.forEach(function (k) { var m = t.match(keyRe(k)); if (m) v[k] = m[1]; });
			return v;
		};
		var secs = function (s) { var m = String(s || '').match(/^(\d+)([smhd]?)$/); if (!m) return NaN; return +m[1] * ({ '': 1, s: 1, m: 60, h: 3600, d: 86400 })[m[2]]; };
		var fill = function (p) {
			byId('pm-type').value = p.pm;
			byId('pm-max').value = p.max; byId('pm-idle').value = p.idle || 10;
			byId('pm-start').value = p.start || 2; byId('pm-minsp').value = p.minsp || 1; byId('pm-maxsp').value = p.maxsp || 3;
			byId('pm-reqs').value = p.reqs; byId('pm-term').value = p.term;
			show();
		};
		var show = function () {
			var t = byId('pm-type').value;
			document.querySelectorAll('.pm-only-ondemand').forEach(function (e) { e.style.display = t === 'ondemand' ? '' : 'none'; });
			document.querySelectorAll('.pm-only-dynamic').forEach(function (e) { e.style.display = t === 'dynamic' ? '' : 'none'; });
			var max = num('pm-max') || 0, idle = t === 'static' ? max : (t === 'dynamic' ? (num('pm-start') || 0) : 0);
			byId('pm-memory').innerHTML = 'Each worker of this site uses about <strong>' + WORKER_MB + ' MB</strong>'
				+ (MEASURED ? ' (measured now)' : ' (typical; none is running right now)') + '. '
				+ 'In memory while quiet: <strong>' + (idle ? '~' + idle * WORKER_MB + ' MB' : 'none') + '</strong>; '
				+ 'at the busiest: <strong>~' + max * WORKER_MB + ' MB</strong>.';
		};
		var matchPreset = function (p) {
			for (var name in PRESETS) {
				var q = PRESETS[name];
				if (q.pm === p.pm && q.max === p.max && q.reqs === p.reqs && q.term === p.term
				    && (q.pm !== 'ondemand' || q.idle === p.idle)
				    && (q.pm !== 'dynamic' || (q.start === p.start && q.minsp === p.minsp && q.maxsp === p.maxsp)))
					return name;
			}
			return 'custom';
		};
		byId('pm-open').addEventListener('click', function () {
			var v = read();
			var p = {
				pm: ['ondemand', 'dynamic', 'static'].indexOf(v['pm']) >= 0 ? v['pm'] : 'ondemand',
				max: parseInt(v['pm.max_children'], 10) || 20, idle: secs(v['pm.process_idle_timeout']) || 10,
				start: parseInt(v['pm.start_servers'], 10) || 2, minsp: parseInt(v['pm.min_spare_servers'], 10) || 1,
				maxsp: parseInt(v['pm.max_spare_servers'], 10) || 3,
				reqs: v['pm.max_requests'] !== undefined ? parseInt(v['pm.max_requests'], 10) || 0 : 500,
				term: v['request_terminate_timeout'] !== undefined ? (secs(v['request_terminate_timeout']) || 0) : 0
			};
			fill(p);
			var preset = matchPreset(p);
			document.querySelector('input[name="pm-preset"][value="' + preset + '"]').checked = true;
			byId('pm-error').textContent = '';
			bootstrap.Modal.getOrCreateInstance(pmModal).show();
		});
		document.querySelectorAll('input[name="pm-preset"]').forEach(function (r) {
			r.addEventListener('change', function () { if (PRESETS[this.value]) fill(PRESETS[this.value]); });
		});
		var toCustom = function () { document.querySelector('input[name="pm-preset"][value="custom"]').checked = true; show(); };
		byId('pm-type').addEventListener('change', toCustom);
		document.querySelectorAll('.pm-num').forEach(function (i) { i.addEventListener('input', toCustom); });

		// the chosen values, checked like the server does; err = '' when fine
		var collect = function () {
			var t = byId('pm-type').value, max = num('pm-max'), err = '';
			var reqs = num('pm-reqs'), term = num('pm-term');
			if (!(max >= 1 && max <= 500)) err = 'Workers at most must be between 1 and 500.';
			else if (!(reqs >= 0) || !(term >= 0)) err = 'Requests and seconds must be 0 or more.';
			var block = [];
			if (t === 'ondemand') {
				var idle = num('pm-idle');
				if (!err && !(idle >= 1)) err = 'Stop idle workers after must be at least 1 second.';
				block = ['; workers start with the first request and stop after ' + idle + ' s idle',
				         'pm = ondemand', 'pm.max_children = ' + max, 'pm.process_idle_timeout = ' + idle + 's'];
			} else if (t === 'dynamic') {
				var st = num('pm-start'), mn = num('pm-minsp'), mx = num('pm-maxsp');
				if (!err && !(mn >= 1 && mn <= st && st <= mx && mx <= max))
					err = 'Needs: at least <= start with <= at most <= workers at most.';
				block = ['; ' + mn + ' to ' + mx + ' idle workers kept ready, up to ' + max + ' in all',
				         'pm = dynamic', 'pm.max_children = ' + max, 'pm.start_servers = ' + st,
				         'pm.min_spare_servers = ' + mn, 'pm.max_spare_servers = ' + mx];
			} else {
				block = ['; a fixed ' + max + ' workers, always running', 'pm = static', 'pm.max_children = ' + max];
			}
			block.push('pm.max_requests = ' + reqs, 'request_terminate_timeout = ' + term + 's');
			return { err: err, block: block };
		};

		// Save & reload: the server rewrites the pm lines of the pool file itself
		byId('pm-save').addEventListener('click', function () {
			var c = collect();
			if (c.err) { byId('pm-error').textContent = c.err; return; }
			var f = document.createElement('form');
			f.method = 'post'; f.action = '/'; f.style.display = 'none';
			var vals = { action: 'pool-pm', user: <?=json_encode($acct['user']);?>, pm: byId('pm-type').value,
			             max: byId('pm-max').value, idle: byId('pm-idle').value, start: byId('pm-start').value,
			             minsp: byId('pm-minsp').value, maxsp: byId('pm-maxsp').value,
			             reqs: byId('pm-reqs').value, term: byId('pm-term').value, csrf: window.CSRF };
			Object.keys(vals).forEach(function (k) {
				var i = document.createElement('input'); i.type = 'hidden'; i.name = k; i.value = vals[k]; f.appendChild(i);
			});
			document.body.appendChild(f);
			this.disabled = true; this.textContent = 'Saving…';
			f.submit();
		});

		if (byId('pm-apply')) byId('pm-apply').addEventListener('click', function () {
			var c = collect(), block = c.block;
			if (c.err) { byId('pm-error').textContent = c.err; return; }

			// drop the old pm lines (and the comment lines Reqad wrote above them),
			// put the new block where "pm =" was - or after the listen lines
			var lines = cmF.getValue().split('\n'), out = [], at = -1;
			var isOld = function (l) {
				if (/^\s*;\s*(workers start with the first request|site holds no memory|a fixed \d+ workers|\d+ to \d+ idle workers)/.test(l)) return true;
				return PM_KEYS.some(function (k) { return keyRe(k).test(l); });
			};
			lines.forEach(function (l) {
				if (isOld(l)) { if (at < 0 && /^\s*pm\s*=/.test(l)) at = out.length; if (at < 0 && /^\s*;/.test(l)) at = out.length; return; }
				out.push(l);
			});
			if (at < 0) {
				at = out.length;
				for (var i = 0; i < out.length; i++) if (/^\s*listen/.test(out[i])) at = i + 1;
				block.unshift('');
			}
			out.splice.apply(out, [at, 0].concat(block));
			cmF.setValue(out.join('\n'));
			bootstrap.Modal.getOrCreateInstance(pmModal).hide();
			cmF.focus();
		});
	}

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
