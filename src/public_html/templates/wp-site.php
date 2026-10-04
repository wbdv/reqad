<?php
	/* Per-site WordPress management page: /wp-toolkit/<user>/
	   Replaces the old "Manage" modal. Summary cards up top, then the
	   Performance and Security tabs. Everything that needs wp-cli is loaded
	   over AJAX (ajax-wp-site-status, one probe) so the page renders at once.
	   $wp_user is set by index.php (already [a-z0-9]). */

	$site = valid_username($wp_user) ? wp_site_row($db, $wp_user) : false;
	if(!$site) {
		header("Location: ".$_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/wp-toolkit/');
		exit;
	}

	// GET /wp-toolkit/<user>/screenshot — the cached home page thumbnail.
	if(($reqs[3] ?? '') === 'screenshot') {
		$shot = wp_screenshot_file($site['user']);
		if(!is_file($shot)) { http_response_code(404); exit; }
		header('Content-Type: image/jpeg');
		header('Cache-Control: private, max-age=86400');
		readfile($shot);
		exit;
	}

	$domain   = $site['domain'];
	$sub      = trim((string)($site['path'] ?? ''), '/');
	$site_url = 'https://'.$domain.'/'.($sub !== '' ? $sub.'/' : '');
	$is_nginx = wp_is_nginx($ini);

	$res  = $db->query('SELECT disk_usage FROM accounts WHERE user="'.$db->escapeString($site['user']).'"');
	$acct = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;
	$disk = $acct ? (int)$acct['disk_usage'] : null;

	$cert = local_ssl_cert_info($domain);
	$cert_days = $cert ? (int)floor(($cert['validTo_time_t'] - time()) / 86400) : null;
	$cert_self = $cert ? ssl_is_self_signed($cert) : false;

	$sec_opts   = wp_security_options();
	$sec_groups = array(
		'Web server rules'   => array('xmlrpc', 'wp_includes_php', 'uploads_php', 'cache_php', 'wp_config', 'sensitive_files', 'author_scans', 'bad_bots', 'security_headers', 'admin_ip_allow'),
		'WordPress'          => array('hide_version', 'file_edit', 'concatenate_scripts', 'pingbacks', 'hide_login'),
		'Files and keys'     => array('security_keys', 'permissions'),
	);
	$default_slug = 'login-'.substr(bin2hex(random_bytes(4)), 0, 6);

	/* First paint: the last cached WordPress probe plus the live checks that
	   need no wp-cli (vhost, cron spool, maintenance file, permissions). The
	   switches are usable at once; ajax-wp-site-status refreshes them ~1s later. */
	$probe0 = wp_site_probe_cached($site['user']);
	list($wp_versions0, ) = wp_stable_versions();
	$shot0 = wp_screenshot_file($site['user']);
	$initial = array(
		'ok'          => true,
		'cached'      => true,
		'probe_error' => '',
		'info'        => $probe0 + array(
			'wp_status'   => $wp_versions0[$probe0['wp_version'] ?? ''] ?? '',
			'screenshot'  => is_file($shot0) ? filemtime($shot0) : 0,
			'pagespeed'   => wp_pagespeed_cached($site['user']),
			'psi_key'     => wp_pagespeed_key() !== '',
			'chromium'    => wp_chromium_bin() !== '',
		),
		'performance' => wp_manage_status($db, $ini, $site['user'], $probe0),
		'security'    => wp_security_status($ini, $site, $probe0),
		'allow_ips'   => wp_sec_allowed_ips($domain),
	);

	include('templates/header.php');
?>
          <!-- Page title -->
          <div class="page-header d-print-none">
            <div class="row align-items-center">
              <div class="col" style="padding-left:22px;">
                <div class="page-pretitle">
                  <a href="/wp-toolkit/" class="text-muted">Wordpress Toolkit</a> / <?=h($domain);?><?=$sub !== '' ? '/'.h($sub) : '';?>
                </div>
                <h2 class="page-title">
                  <span id="wp-title"><?=h($site['title']);?></span>
                </h2>
                <div class="text-muted" id="wp-tagline"></div>
              </div>
              <div class="col-auto ms-auto d-print-none">
                <div class="btn-list">
                  <a href="/wp-toolkit/" class="btn btn-white">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><path d="M5 12l14 0"></path><path d="M5 12l6 6"></path><path d="M5 12l6 -6"></path></svg>
                    Back to list
                  </a>
                  <form method="post" action="./wp-toolkit/" target="_blank" style="display:inline;margin:0;">
                    <input type="hidden" name="action" value="wp-auto-login">
                    <input type="hidden" name="user" value="<?=h($site['user']);?>">
                    <button type="submit" class="btn btn-primary">
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M14 8v-2a2 2 0 0 0 -2 -2h-7a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h7a2 2 0 0 0 2 -2v-2" /><path d="M20 12h-13l3 -3m0 6l-3 -3" /></svg>
                      Log in to WordPress
                    </button>
                  </form>
                </div>
              </div>
            </div>
          </div>

          <style>
          .wp-stat { height:100%; }
          .wp-stat .card-body { padding:14px 18px; }
          .wp-stat .wp-stat-label { color:#6c757d; font-size:.75rem; text-transform:uppercase; letter-spacing:.04em; font-weight:600; }
          .wp-stat .wp-stat-value { font-size:1.35rem; font-weight:600; color:#354052; margin-top:4px; line-height:1.3; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
          .wp-stat .wp-stat-sub { color:#6c757d; font-size:.8rem; margin-top:2px; min-height:1.2em; }
          .wp-shot { position:relative; background:#f6f8fb; border-radius:4px; overflow:hidden; aspect-ratio:16/10; display:flex; align-items:center; justify-content:center; }
          .wp-shot img { width:100%; height:100%; object-fit:cover; object-position:top; display:block; }
          .wp-shot .wp-shot-empty { color:#6c757d; text-align:center; padding:20px; font-size:.85rem; }
          #wp-main-card { border:none; }
          #wp-main-card > .card-header { padding-bottom:0; background:#f6f8fb; }
          #wp-tabs { border-bottom:0; gap:30px; }
          #wp-tabs .nav-link { display:block; border:1px solid transparent; padding:10px 20px !important; color:#6c757d; font-weight:500; line-height:20pt !important; margin-bottom:-1px; margin-left:-16px !important; }
          #wp-tabs .nav-link:hover { border-color:#dee2e6 #dee2e6 transparent; background:#475db41a; color:#354052; }
          #wp-tabs .nav-link.active { background:#fff; border-color:#dee2e6 #dee2e6 #fff; color:#354052; font-weight:bold; }
          .wp-tab-pane { border:1px solid; border-color:transparent #dee2e6 #dee2e6 #dee2e6; display:none; }
          .wp-tab-pane.active { display:block; }
          .wp-sec-group { font-size:.75rem; text-transform:uppercase; letter-spacing:.04em; font-weight:600; color:#6c757d; background:#f6f8fb; padding:8px 20px; border-top:1px solid #e6e7e9; border-bottom:1px solid #e6e7e9; }
          .wp-sec-row { padding:12px 20px; border-bottom:1px solid #f0f1f3; display:flex; gap:14px; align-items:flex-start; }
          .wp-sec-row:last-child { border-bottom:0; }
          .wp-sec-row .form-check-input { margin-top:3px; flex:0 0 auto; width:1.1rem; height:1.1rem; }
          .wp-sec-row .wp-sec-text { flex:1 1 auto; min-width:0; }
          .wp-sec-row .wp-sec-label { font-weight:600; color:#354052; cursor:pointer; }
          .wp-sec-row .wp-sec-desc { color:#6c757d; font-size:.82rem; margin-top:2px; }
          .wp-sec-row .wp-sec-state { flex:0 0 auto; white-space:nowrap; }
          .wp-sec-row.na { opacity:.55; }
          .psi-seg .btn { padding:3px 12px; }
          .psi-seg .btn.active { background:#206bc4; color:#fff; border-color:#206bc4; }
          .psi-gauges { display:flex; flex-wrap:wrap; gap:18px 28px; justify-content:space-around; }
          .psi-gauge { text-align:center; width:96px; }
          .psi-gauge svg { width:88px; height:88px; display:block; margin:0 auto; }
          .psi-gauge .psi-num { font-size:22px; font-weight:600; }
          .psi-gauge .psi-label { font-size:.8rem; color:#354052; margin-top:6px; line-height:1.2; }
          .psi-metrics { list-style:none; margin:0; padding:0; }
          .psi-metrics li { display:flex; align-items:center; padding:6px 0; border-bottom:1px solid #f0f1f3; font-size:.85rem; }
          .psi-metrics li:last-child { border-bottom:0; }
          .psi-metrics .psi-dot { width:9px; height:9px; border-radius:50%; margin-right:9px; flex:0 0 auto; }
          .psi-metrics .psi-val { margin-left:auto; font-weight:600; padding-left:12px; white-space:nowrap; }
          #psi-body .psi-msg { color:#6c757d; text-align:center; padding:26px 10px; }
          </style>

          <!-- Summary cards -->
          <div class="row row-cards mt-1">
            <div class="col-lg-8">
              <div class="row row-cards">
                <div class="col-6 col-md-4">
                  <div class="card wp-stat"><div class="card-body">
                    <div class="wp-stat-label">WordPress</div>
                    <div class="wp-stat-value" id="st-wp"><?=h($site['wp_version']);?></div>
                    <div class="wp-stat-sub" id="st-wp-sub">&nbsp;</div>
                  </div></div>
                </div>
                <div class="col-6 col-md-4">
                  <div class="card wp-stat"><div class="card-body">
                    <div class="wp-stat-label">PHP</div>
                    <div class="wp-stat-value"><?=h(wp_site_php_version($ini, $domain));?></div>
                    <div class="wp-stat-sub"><a href="/accounts/" class="text-muted">Change on the Accounts page</a></div>
                  </div></div>
                </div>
                <div class="col-6 col-md-4">
                  <div class="card wp-stat"><div class="card-body">
                    <div class="wp-stat-label">Theme</div>
                    <div class="wp-stat-value" id="st-theme"><span class="text-muted">&hellip;</span></div>
                    <div class="wp-stat-sub" id="st-theme-sub">&nbsp;</div>
                  </div></div>
                </div>
                <div class="col-6 col-md-4">
                  <div class="card wp-stat"><div class="card-body">
                    <div class="wp-stat-label">Plugins</div>
                    <div class="wp-stat-value" id="st-plugins"><span class="text-muted">&hellip;</span></div>
                    <div class="wp-stat-sub" id="st-plugins-sub">&nbsp;</div>
                  </div></div>
                </div>
                <div class="col-6 col-md-4">
                  <div class="card wp-stat"><div class="card-body">
                    <div class="wp-stat-label">Disk usage</div>
                    <div class="wp-stat-value"><?=$disk === null ? '&ndash;' : ($disk > 1024 ? round($disk/1024, 1).' GB' : $disk.' MB');?></div>
                    <div class="wp-stat-sub">whole account</div>
                  </div></div>
                </div>
                <div class="col-6 col-md-4">
                  <div class="card wp-stat"><div class="card-body">
                    <div class="wp-stat-label">SSL</div>
                    <?php if(!$cert): ?>
                      <div class="wp-stat-value text-red">None</div>
                      <div class="wp-stat-sub"><a href="/ssl/" class="text-muted">Manage certificates</a></div>
                    <?php elseif($cert_days < 0): ?>
                      <div class="wp-stat-value text-red">Expired</div>
                      <div class="wp-stat-sub"><?=date('M j, Y', $cert['validTo_time_t']);?></div>
                    <?php else: ?>
                      <div class="wp-stat-value <?=$cert_self ? 'text-orange' : 'text-green';?>"><?=$cert_self ? 'Self-signed' : 'Valid';?></div>
                      <div class="wp-stat-sub"><?=$cert_days;?> days left</div>
                    <?php endif; ?>
                  </div></div>
                </div>
                <!-- Custom login URL (only while "Hide wp-admin" is applied) -->
                <div class="col-12" id="st-login-wrap" style="display:none;">
                  <div class="card wp-stat"><div class="card-body d-flex align-items-center flex-wrap gap-2">
                    <div style="min-width:0;">
                      <div class="wp-stat-label">Login URL</div>
                      <div class="wp-stat-value" style="font-size:1.1rem;"><a href="#" target="_blank" id="st-login-url"></a></div>
                      <div class="wp-stat-sub">wp-login.php and /wp-admin return 404 to visitors who are not logged in</div>
                    </div>
                    <button type="button" class="btn btn-sm btn-white ms-auto" id="st-login-copy">
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M8 8m0 2a2 2 0 0 1 2 -2h8a2 2 0 0 1 2 2v8a2 2 0 0 1 -2 2h-8a2 2 0 0 1 -2 -2z" /><path d="M16 8v-2a2 2 0 0 0 -2 -2h-8a2 2 0 0 0 -2 2v8a2 2 0 0 0 2 2h2" /></svg>
                      <span>Copy</span>
                    </button>
                  </div></div>
                </div>
              </div>
            </div>
            <div class="col-lg-4">
              <div class="card" style="height:100%;">
                <div class="card-body" style="padding:12px;">
                  <a href="<?=h($site_url);?>" target="_blank" class="wp-shot" id="wp-shot">
                    <?php if($initial['info']['screenshot']): ?>
                    <img alt="Screenshot of the home page" src="/wp-toolkit/<?=h($site['user']);?>/screenshot?t=<?=(int)$initial['info']['screenshot'];?>">
                    <?php else: ?>
                    <div class="wp-shot-empty" id="wp-shot-empty">&nbsp;</div>
                    <?php endif; ?>
                  </a>
                  <div class="d-flex align-items-center mt-2">
                    <a href="<?=h($site_url);?>" target="_blank" class="text-truncate"><svg xmlns="http://www.w3.org/2000/svg" class="icon me-1" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 6h-6a2 2 0 0 0 -2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-6" /><path d="M11 13l9 -9" /><path d="M15 4h5v5" /></svg><?=h($domain);?><?=$sub !== '' ? '/'.h($sub) : '';?></a>
                    <a href="#" class="ms-auto text-muted" id="wp-shot-refresh" title="Take a new screenshot (with a key set, this reruns the PageSpeed test)">
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4" /><path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4" /></svg>
                      Refresh
                    </a>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- PageSpeed Insights -->
          <div class="card mt-3" id="psi-card" style="display:none;">
            <div class="card-header d-flex align-items-center flex-wrap gap-2">
              <h3 class="card-title mb-0">PageSpeed</h3>
              <div class="btn-group psi-seg ms-3" role="group">
                <button type="button" class="btn btn-sm btn-white active" data-strategy="mobile">Mobile</button>
                <button type="button" class="btn btn-sm btn-white" data-strategy="desktop">Desktop</button>
              </div>
              <div class="ms-auto d-flex align-items-center gap-3">
                <span class="text-muted small" id="psi-when"></span>
                <a href="https://pagespeed.web.dev/analysis?url=<?=rawurlencode($site_url);?>" target="_blank" class="small" id="psi-report" style="display:none;">Full report</a>
                <button type="button" class="btn btn-sm btn-white" id="psi-run" style="display:none;">Run test</button>
              </div>
            </div>
            <div class="card-body" id="psi-body">
              <div class="psi-msg"><span class="spinner-border spinner-border-sm me-2"></span>Loading&hellip;</div>
            </div>
          </div>

          <div id="wp-probe-alert" class="alert alert-danger mt-3" role="alert" style="display:none;"></div>

          <!-- Tabs -->
          <div class="col-12">
            <div class="card mt-3" id="wp-main-card">
              <div class="card-header">
                <ul class="nav nav-tabs card-header-tabs" id="wp-tabs">
                  <li class="nav-item"><a class="nav-link active" href="#performance" data-tab="performance">Performance</a></li>
                  <li class="nav-item"><a class="nav-link" href="#security" data-tab="security">Security</a></li>
                </ul>
              </div>
              <div class="tab-content">

                <!-- Performance tab -->
                <div class="wp-tab-pane active" id="tab-performance">
                  <div class="card-body">
                    <p class="text-muted">Changes are applied to the live site immediately.</p>
                    <div id="wp-manage-alert" class="alert" role="alert" style="display:none;"></div>

                    <?php
                    $perf = array(
                      'nginx_cache' => array('Nginx cache',
                        'Serves cached pages straight from nginx (FastCGI microcache) instead of hitting PHP/WordPress on every request &mdash; a large speed-up for anonymous traffic. Logged-in users, the cart/checkout and admin are never cached. Enabling injects the cache config into the vhost, reloads nginx, and installs the <a href="https://github.com/wbdv/reqad-cache-purger" target="_blank">reqad-cache-purger</a> plugin so WordPress clears the cache when content changes.'
                        .'<div class="wp-manage-na text-red mt-1" data-option="nginx_cache" style="display:none;">Unavailable: this server does not use nginx as its web server.</div>'),
                      'wp_cron' => array('Disable WP cron',
                        'WordPress&rsquo; built-in cron runs on page visits, which is unreliable and adds latency. Enabling this sets the <code>DISABLE_WP_CRON</code> constant in <code>wp-config.php</code> and installs a real system cron that runs due tasks every 2 minutes &mdash; more reliable and faster page loads.'),
                      'indexing' => array('Search engine indexing',
                        'When off, WordPress asks search engines not to index the site (<i>Settings &rsaquo; Reading &rsaquo; Discourage search engines</i>): <code>robots.txt</code> disallows crawling and pages get a <code>noindex</code> tag. Turn it off for staging and development copies.'),
                      'maintenance' => array('Maintenance mode',
                        'Visitors get a &ldquo;back soon&rdquo; page with HTTP 503 (which tells search engines to come back later instead of dropping pages). You stay logged in and see the site normally, and the Log in button above keeps working.'),
                    );
                    foreach($perf as $opt => $p): ?>
                    <div class="card mb-3">
                      <div class="card-body">
                        <label class="form-check form-switch form-switch-lg mb-1">
                          <input class="form-check-input wp-manage-toggle" type="checkbox" data-option="<?=$opt;?>" disabled>
                          <span class="form-check-label form-check-label-on"></span>
                          <span class="h3 mb-0 ms-2"><?=$p[0];?></span>
                          <span class="wp-manage-spinner spinner-border spinner-border-sm ms-2" role="status" style="display:none;"></span>
                        </label>
                        <div class="text-muted"><?=$p[1];?></div>
                      </div>
                    </div>
                    <?php endforeach; ?>

                    <pre id="wp-manage-log" style="display:none;background:#222;color:#ccc;padding:10px 14px;max-height:220px;overflow:auto;font-size:11px;border-radius:4px;"></pre>
                  </div>
                </div>

                <!-- Security tab -->
                <div class="wp-tab-pane" id="tab-security">
                  <div class="card-body pb-2">
                    <p class="text-muted mb-2">
                      Tick the measures you want and press <b>Apply changes</b>. Unticking an applied measure reverts it, except the
                      last two, which are one-way.
                      <?php if(!$is_nginx): ?><br><span class="text-orange">Web server rules need nginx; this server uses Apache.</span><?php endif; ?>
                    </p>
                    <div id="wp-sec-alert" class="alert" role="alert" style="display:none;"></div>
                  </div>
                  <?php foreach($sec_groups as $gname => $keys): ?>
                    <div class="wp-sec-group"><?=h($gname);?></div>
                    <?php foreach($keys as $k): $o = $sec_opts[$k]; ?>
                    <div class="wp-sec-row" data-option="<?=$k;?>">
                      <input class="form-check-input wp-sec-cb" type="checkbox" id="sec-<?=$k;?>" value="<?=$k;?>" data-rec="<?=$o['rec'] ? '1' : '0';?>" disabled>
                      <div class="wp-sec-text">
                        <label class="wp-sec-label" for="sec-<?=$k;?>"><?=h($o['label']);?></label>
                        <?php if($o['rec']): ?><span class="badge bg-blue-lt ms-1">Recommended</span><?php endif; ?>
                        <div class="wp-sec-desc"><?=$o['desc'];?></div>
                        <?php if($k === 'hide_login'): ?>
                          <div class="input-group input-group-sm mt-2" style="max-width:460px;">
                            <span class="input-group-text"><?=h(rtrim($site_url, '/'));?>/</span>
                            <input type="text" class="form-control" id="sec-login-slug" value="<?=h($default_slug);?>" maxlength="40" pattern="[a-z0-9][a-z0-9-]{3,39}" autocomplete="off">
                          </div>
                          <div class="wp-sec-desc mt-1" id="sec-login-note" style="display:none;">Bookmark this address &mdash; it is the only way in besides the panel&rsquo;s Log in button.</div>
                        <?php elseif($k === 'admin_ip_allow'): ?>
                          <div class="mt-2" style="max-width:460px;">
                            <textarea class="form-control form-control-sm" id="sec-allow-ips" rows="3" spellcheck="false"
                              placeholder="One IP or range per line, e.g. 203.0.113.7 or 198.51.100.0/24"></textarea>
                            <div class="d-flex align-items-center mt-1 small">
                              <a href="#" id="sec-add-my-ip" data-ip="<?=h($_SERVER['REMOTE_ADDR'] ?? '');?>">Add my IP (<?=h($_SERVER['REMOTE_ADDR'] ?? '');?>)</a>
                              <span class="text-muted ms-auto">This server's own IPs are always allowed.</span>
                            </div>
                          </div>
                        <?php elseif($k === 'security_keys'): ?>
                          <a href="#" class="btn btn-sm btn-white mt-2" id="sec-regen-keys" style="display:none;">Regenerate keys now</a>
                        <?php endif; ?>
                      </div>
                      <div class="wp-sec-state"><span class="badge bg-muted-lt">&hellip;</span></div>
                    </div>
                    <?php endforeach; ?>
                  <?php endforeach; ?>
                  <div class="card-footer d-flex align-items-center flex-wrap gap-2">
                    <a href="#" class="btn btn-white" id="sec-select-rec">Select recommended</a>
                    <button type="button" class="btn btn-primary ms-auto" id="sec-apply" disabled>Apply changes</button>
                  </div>
                  <div class="card-body pt-0" style="display:none;" id="wp-sec-log-wrap">
                    <pre id="wp-sec-log" style="background:#222;color:#ccc;padding:10px 14px;max-height:260px;overflow:auto;font-size:11px;border-radius:4px;margin:0;"></pre>
                  </div>
                </div>

              </div><!-- .tab-content -->
            </div><!-- .card -->
          </div><!-- .col-12 -->

<!-- Confirm dialog (Security tab) -->
<div class="modal modal-blur fade" id="modal-wp-confirm" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-sm modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-status bg-warning" id="wp-confirm-status"></div>
      <div class="modal-body text-center py-4">
        <h3 id="wp-confirm-title">Are you sure?</h3>
        <div class="text-muted" id="wp-confirm-text"></div>
      </div>
      <div class="modal-footer">
        <div class="w-100"><div class="row">
          <div class="col"><a href="#" class="btn btn-white w-100" data-bs-dismiss="modal">Cancel</a></div>
          <div class="col"><button class="btn btn-warning w-100" type="button" id="wp-confirm-ok">Continue</button></div>
        </div></div>
      </div>
    </div>
  </div>
</div>

	<?php
    include('templates/footer.php');
?>
<script>
jQuery(document).ready(function () {
	'use strict';
	// footer.php leaves a <div> unclosed; keep the modal out of it
	$('#modal-wp-confirm').appendTo(document.body);
	var wpUser  = <?=json_encode($site['user']);?>;
	var shotUrl = '/wp-toolkit/' + wpUser + '/screenshot';
	var secState = {};

	function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }

	/* ---- tabs (hand-rolled; remembered in the URL hash) ------------------ */
	function showTab(name) {
		if(!$('#tab-' + name).length) name = 'performance';
		$('#wp-tabs .nav-link').removeClass('active').filter('[data-tab="' + name + '"]').addClass('active');
		$('.wp-tab-pane').removeClass('active');
		$('#tab-' + name).addClass('active');
	}
	$('#wp-tabs .nav-link').on('click', function (e) {
		e.preventDefault();
		var t = $(this).data('tab');
		showTab(t);
		history.replaceState(null, '', '#' + t);
	});
	showTab((location.hash || '#performance').substring(1));

	/* ---- screenshot ------------------------------------------------------ */
	function shotShow(ts) {
		if(ts) {
			$('#wp-shot').html('<img alt="Screenshot of the home page" src="' + shotUrl + '?t=' + ts + '">');
		} else {
			$('#wp-shot').html('<div class="wp-shot-empty">No screenshot yet</div>');
		}
	}
	/* Screenshot and PageSpeed run as background jobs on the server: the
	   request only starts the job and returns at once, then the page polls.
	   So they never hold a connection open while the switches are used. */
	function jobRun(kind, onDone) {
		$.post('./ajax-wp-job/', { action: 'ajax-wp-job', kind: kind, user: wpUser }, null, 'json')
			.done(function () { setTimeout(function () { jobPoll(kind, onDone, 0); }, 3000); })
			.fail(function () { onDone({ error: 'Could not start the job.' }); });
	}
	function jobPoll(kind, onDone, n) {
		$.post('./ajax-wp-job-status/', { action: 'ajax-wp-job-status', kind: kind, user: wpUser }, null, 'json').done(function (r) {
			if(r && r.running && n < 100) { setTimeout(function () { jobPoll(kind, onDone, n + 1); }, 3000); return; }
			onDone(r || { error: 'No answer from the server.' });
		}).fail(function () {
			if(n < 100) setTimeout(function () { jobPoll(kind, onDone, n + 1); }, 5000);
			else onDone({ error: 'No answer from the server.' });
		});
	}
	function shotTake() {
		$('#wp-shot').html('<div class="wp-shot-empty"><span class="spinner-border spinner-border-sm me-2"></span>Taking screenshot&hellip;</div>');
		jobRun('screenshot', function (r) {
			if(!r.error && r.screenshot) shotShow(r.screenshot);
			else $('#wp-shot').html('<div class="wp-shot-empty">' + esc(r.error || 'Screenshot failed.') + '</div>');
		});
	}
	var hasPsiKey = false, hasChromium = false;
	$('#wp-shot-refresh').on('click', function (e) {
		e.preventDefault();
		if(hasPsiKey) psiRun();            // the screenshot comes with the test
		else if(hasChromium) shotTake();
		else location.href = '/settings/';
	});

	/* ---- PageSpeed Insights --------------------------------------------- */
	var psiData = null, psiStrategy = 'mobile';
	var PSI_CATS = [['performance', 'Performance'], ['accessibility', 'Accessibility'],
	                ['best-practices', 'Best Practices'], ['seo', 'SEO']];
	// Lighthouse's own bands and colours
	function psiColor(s) { return s >= 90 ? '#0cce6b' : (s >= 50 ? '#ffa400' : '#ff4e42'); }
	function psiTint(s)  { return s >= 90 ? '#e5faef' : (s >= 50 ? '#fff4e0' : '#ffeceb'); }
	function psiGauge(score, label) {
		if(score === null || score === undefined)
			return '<div class="psi-gauge"><svg viewBox="0 0 100 100"><circle cx="50" cy="50" r="42" fill="#f6f8fb" stroke="#e6e7e9" stroke-width="8"/>'
			     + '<text x="50" y="58" text-anchor="middle" font-size="22" fill="#6c757d">&ndash;</text></svg><div class="psi-label">' + label + '</div></div>';
		var c = 2 * Math.PI * 42, len = c * score / 100;
		return '<div class="psi-gauge"><svg viewBox="0 0 100 100" role="img" aria-label="' + label + ' ' + score + '">'
		     + '<circle cx="50" cy="50" r="42" fill="' + psiTint(score) + '" stroke="' + psiTint(score) + '" stroke-width="8"/>'
		     + '<circle cx="50" cy="50" r="42" fill="none" stroke="' + psiColor(score) + '" stroke-width="8" stroke-linecap="round"'
		     + ' stroke-dasharray="' + len.toFixed(1) + ' ' + c.toFixed(1) + '" transform="rotate(-90 50 50)"/>'
		     + '<text x="50" y="58" text-anchor="middle" font-size="24" font-weight="600" fill="' + (score >= 90 ? '#018642' : (score >= 50 ? '#c33300' : '#c7221f')) + '">' + score + '</text>'
		     + '</svg><div class="psi-label">' + label + '</div></div>';
	}
	function psiAgo(ts) {
		var s = Math.max(0, Math.floor(Date.now() / 1000) - ts);
		if(s < 90) return 'just now';
		if(s < 5400) return Math.round(s / 60) + ' min ago';
		if(s < 129600) return Math.round(s / 3600) + ' h ago';
		return Math.round(s / 86400) + ' days ago';
	}
	function psiMsg(html) { $('#psi-body').html('<div class="psi-msg">' + html + '</div>'); }
	function psiRender() {
		var d = psiData ? psiData[psiStrategy] : null;
		if(!psiData) return;
		$('#psi-when').text('Tested ' + psiAgo(psiData.fetched));
		$('#psi-report').show();
		if(!d) { psiMsg('No ' + psiStrategy + ' result in the last test.'); return; }
		var g = '';
		PSI_CATS.forEach(function (c) { g += psiGauge(d.scores[c[0]], c[1]); });
		var m = '';
		(d.metrics || []).forEach(function (x) {
			var col = x.score === null ? '#adb5bd' : psiColor(Math.round(x.score * 100));
			m += '<li><span class="psi-dot" style="background:' + col + '"></span>' + esc(x.label) + '<span class="psi-val">' + esc(x.value) + '</span></li>';
		});
		$('#psi-body').html('<div class="row align-items-center g-4"><div class="col-lg-7"><div class="psi-gauges">' + g + '</div></div>'
			+ '<div class="col-lg-5"><ul class="psi-metrics">' + m + '</ul></div></div>');
	}
	$('.psi-seg .btn').on('click', function () {
		$('.psi-seg .btn').removeClass('active');
		$(this).addClass('active');
		psiStrategy = $(this).data('strategy');
		psiRender();
	});
	function psiRun() {
		$('#psi-run').prop('disabled', true);
		psiMsg('<span class="spinner-border spinner-border-sm me-2"></span>Google is testing the site on mobile and desktop &mdash; this takes up to a minute&hellip;');
		$('#wp-shot').html('<div class="wp-shot-empty"><span class="spinner-border spinner-border-sm me-2"></span>Waiting for the PageSpeed test&hellip;</div>');
		jobRun('pagespeed', function (r) {
			if(r.pagespeed) { psiData = r.pagespeed; psiRender(); }
			if(r.error) {
				if(r.pagespeed) $('#psi-body').prepend('<div class="alert alert-warning mb-3">' + esc(r.error) + '</div>');
				else psiMsg('<span class="text-red">' + esc(r.error) + '</span>');
			}
			shotShow(r.screenshot || 0);
			$('#psi-run').prop('disabled', false);
		});
	}
	$('#psi-run').on('click', psiRun);

	/* ---- summary cards --------------------------------------------------- */
	function renderInfo(i) {
		if(i.title) $('#wp-title').text(i.title);
		$('#wp-tagline').text(i.tagline || '');
		if(i.wp_version) $('#st-wp').text(i.wp_version);
		var badge = { latest: '<span class="badge bg-success">Latest</span>',
		              outdated: '<span class="badge bg-orange">Outdated</span>',
		              insecure: '<span class="badge bg-red">Insecure</span>' }[i.wp_status] || '&nbsp;';
		$('#st-wp-sub').html(badge);
		if(i.theme !== undefined) {
			$('#st-theme').text(i.theme || '-').attr('title', i.theme || '');
			$('#st-theme-sub').text(i.theme_version ? 'version ' + i.theme_version : '');
		}
		if(i.plugins_total !== undefined) {
			$('#st-plugins').html(esc(i.plugins_active) + ' <span class="text-muted" style="font-size:.9rem;font-weight:400;">of ' + esc(i.plugins_total) + ' active</span>');
			$('#st-plugins-sub').html(i.plugins_updates > 0
				? '<span class="text-orange">' + esc(i.plugins_updates) + ' update' + (i.plugins_updates > 1 ? 's' : '') + ' available</span>'
				: 'all up to date');
		}
	}

	/* ---- Performance tab ------------------------------------------------- */
	function wpManageAlert(type, html) {
		var $a = $('#wp-manage-alert');
		if(!html) { $a.hide(); return; }
		$a.removeClass('alert-success alert-danger alert-info')
		  .addClass(type === 'error' ? 'alert-danger' : (type === 'info' ? 'alert-info' : 'alert-success'))
		  .html(html).show();
	}
	function wpManageRender(st) {
		$('.wp-manage-toggle').each(function () {
			var opt = $(this).data('option');
			var na = (st[opt] === 'na');
			$(this).prop('checked', st[opt] === 'on').prop('disabled', na);
			$('.wp-manage-na[data-option="' + opt + '"]').toggle(na && opt === 'nginx_cache');
		});
	}
	$('.wp-manage-toggle').on('change', function () {
		var $cb    = $(this);
		var option = $cb.data('option');
		var enable = $cb.is(':checked');
		var $spin  = $cb.closest('label').find('.wp-manage-spinner');

		perfBusy = true;
		$('.wp-manage-toggle').prop('disabled', true);
		$spin.show();
		wpManageAlert('info', (enable ? 'Enabling' : 'Disabling') + ' &hellip; this can take a few seconds.');
		$('#wp-manage-log').hide().text('');

		$.post('./ajax-wp-manage-toggle/', { action: 'ajax-wp-manage-toggle', user: wpUser, option: option, enable: enable ? '1' : '0' }, null, 'json')
		.done(function (resp) {
			perfBusy = false;
			$spin.hide();
			if(resp && resp.status) wpManageRender(resp.status);
			else $('.wp-manage-toggle').prop('disabled', false);
			if(resp && resp.ok) wpManageAlert('success', esc(resp.success || 'Done.'));
			else wpManageAlert('error', esc((resp && resp.error) ? resp.error : 'Operation failed.'));
			if(resp && resp.log && resp.log.trim() !== '') $('#wp-manage-log').text(resp.log.trim()).show();
		}).fail(function () {
			perfBusy = false;
			$spin.hide();
			$('.wp-manage-toggle').prop('disabled', false);
			$cb.prop('checked', !enable);
			wpManageAlert('error', 'Request failed. No change was applied.');
		});
	});

	/* ---- Security tab ---------------------------------------------------- */
	var ONE_WAY = ['security_keys', 'permissions'];
	function secAlert(type, html) {
		var $a = $('#wp-sec-alert');
		if(!html) { $a.hide(); return; }
		$a.removeClass('alert-success alert-danger alert-info')
		  .addClass(type === 'error' ? 'alert-danger' : (type === 'info' ? 'alert-info' : 'alert-success'))
		  .html(html).show();
	}
	var siteBase = <?=json_encode(rtrim($site_url, '/'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);?>;
	function loginCard(slug) {
		if(!slug) { $('#st-login-wrap').hide(); return; }
		var url = siteBase + '/' + slug;
		$('#st-login-url').attr('href', url).text(url);
		$('#st-login-wrap').show();
	}
	$('#st-login-copy').on('click', function () {
		var $l = $(this).find('span'), url = $('#st-login-url').text();
		var done = function () { $l.text('Copied'); setTimeout(function () { $l.text('Copy'); }, 1500); };
		if(navigator.clipboard) navigator.clipboard.writeText(url).then(done);
		else { var $t = $('<textarea>').val(url).appendTo('body').select(); document.execCommand('copy'); $t.remove(); done(); }
	});
	function secRender(st, slug) {
		secState = st;
		$('.wp-sec-row').each(function () {
			var k = $(this).data('option'), s = st[k] || 'na';
			var $cb = $(this).find('.wp-sec-cb');
			$(this).toggleClass('na', s === 'na');
			$cb.prop('checked', s === 'on');
			// one-way measures cannot be unticked once in place
			$cb.prop('disabled', s === 'na' || (s === 'on' && ONE_WAY.indexOf(k) >= 0));
			$(this).find('.wp-sec-state').html(
				s === 'on' ? '<span class="badge bg-green-lt">Applied</span>' :
				s === 'off' ? '<span class="badge bg-muted-lt">Not applied</span>' :
				'<span class="badge bg-muted-lt" title="Not available on this server/site">N/A</span>');
		});
		$('#sec-regen-keys').toggle(st.security_keys === 'on');
		if(st.hide_login === 'on' && slug) $('#sec-login-slug').val(slug);
		$('#sec-login-note').toggle(st.hide_login === 'on');
		loginCard(st.hide_login === 'on' ? slug : '');
		$('#sec-apply').prop('disabled', false);
	}
	function ipList() {
		return $('#sec-allow-ips').val().split(/[\s,]+/).filter(function (s) { return s !== ''; });
	}
	function ipRender(ips) {
		if(ips && ips.length) $('#sec-allow-ips').val(ips.join('\n'));
		else if(!$('#sec-allow-ips').val()) $('#sec-allow-ips').val($('#sec-add-my-ip').data('ip') || '');
	}
	$('#sec-add-my-ip').on('click', function (e) {
		e.preventDefault();
		var ip = String($(this).data('ip') || ''), l = ipList();
		if(ip && l.indexOf(ip) < 0) { l.push(ip); $('#sec-allow-ips').val(l.join('\n')); }
	});
	$('#sec-select-rec').on('click', function (e) {
		e.preventDefault();
		$('.wp-sec-cb:not(:disabled)').each(function () {
			if($(this).data('rec') == 1) $(this).prop('checked', true);
		});
	});
	function secApply(regen) {
		secBusy = true;
		var on = $('.wp-sec-cb:checked').map(function () { return this.value; }).get();
		$('#sec-apply').prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Applying&hellip;');
		$('.wp-sec-cb').prop('disabled', true);
		secAlert('info', 'Applying &hellip; this can take a little while.');
		$('#wp-sec-log-wrap').hide();
		$.post('./ajax-wp-security-apply/', { action: 'ajax-wp-security-apply', user: wpUser, on: on,
			login_slug: $('#sec-login-slug').val(), regen_keys: regen ? '1' : '0', allow_ips: ipList().join('\n') }, null, 'json')
		.done(function (r) {
			if(r && r.security) secRender(r.security, r.login_slug);
			if(r && r.allow_ips) ipRender(r.allow_ips);
			if(r && r.ok) secAlert('success', 'Security settings applied.');
			else secAlert('error', esc(r && r.error ? r.error : 'Operation failed.').replace(/\n/g, '<br>'));
			if(r && r.log && r.log.trim() !== '') { $('#wp-sec-log').text(r.log.trim()); $('#wp-sec-log-wrap').show(); }
		}).fail(function () {
			secRender(secState);
			secAlert('error', 'Request failed. Reload the page to see the current state.');
		}).always(function () {
			secBusy = false;
			$('#sec-apply').text('Apply changes');
		});
	}
	/* ---- confirm dialog (Reqad modal instead of window.confirm) ----------- */
	var confirmFn = null;
	function showConfirm(title, html, okLabel, danger, fn) {
		$('#wp-confirm-title').text(title);
		$('#wp-confirm-text').html(html);
		$('#wp-confirm-status').removeClass('bg-warning bg-danger').addClass(danger ? 'bg-danger' : 'bg-warning');
		$('#wp-confirm-ok').text(okLabel).removeClass('btn-warning btn-danger').addClass(danger ? 'btn-danger' : 'btn-warning');
		confirmFn = fn;
		bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-wp-confirm')).show();
	}
	$('#wp-confirm-ok').on('click', function () {
		bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-wp-confirm')).hide();
		// run after the fade-out so a second dialog in the chain can open cleanly
		if(confirmFn) { var f = confirmFn; confirmFn = null; setTimeout(f, 350); }
	});
	// Run each question in turn; apply only when every one was accepted.
	function confirmChain(list, done) {
		if(!list.length) { done(); return; }
		var q = list[0];
		showConfirm(q.title, q.html, q.ok, q.danger, function () { confirmChain(list.slice(1), done); });
	}

	$('#sec-apply').on('click', function () {
		var qs = [];
		var myIp = String($('#sec-add-my-ip').data('ip') || '');
		if($('#sec-admin_ip_allow').is(':checked') && myIp && ipList().indexOf(myIp) < 0)
			qs.push({ title: 'Your IP is not in the list', ok: 'Apply anyway', danger: true,
				html: 'Your current IP <b>' + esc(myIp) + '</b> is not among the allowed addresses, so you will not be able to open <code>wp-login.php</code> yourself. The panel&rsquo;s Log in button will still work.' });
		if($('#sec-hide_login').is(':checked') && secState.hide_login !== 'on')
			qs.push({ title: 'Move the login page?', ok: 'Move it', danger: false,
				html: 'The WordPress login page will move to<br><b>' + esc($('#sec-login-slug').prev().text() + $('#sec-login-slug').val()) + '</b><br>'
				    + '<code>wp-login.php</code> and <code>/wp-admin</code> will return 404 to visitors who are not logged in. Bookmark the new address.' });
		confirmChain(qs, function () { secApply(false); });
	});
	$('#sec-regen-keys').on('click', function (e) {
		e.preventDefault();
		showConfirm('Regenerate security keys?', 'New keys sign <b>every user</b> out of this site, including you.', 'Regenerate', true,
			function () { secApply(true); });
	});

	/* ---- state: first paint from PHP, then a live refresh ----------------- */
	var perfBusy = false, secBusy = false;
	function applyStatus(r, first) {
		if(r.probe_error && r.probe_error !== 'pending')
			$('#wp-probe-alert').text('WordPress could not be read: ' + r.probe_error).show();
		else $('#wp-probe-alert').hide();
		renderInfo(r.info || {});
		// never overwrite a switch or checkbox while its own request is running
		if(r.performance && !perfBusy) wpManageRender(r.performance);
		if(r.security && !secBusy) secRender(r.security, r.info ? r.info.login_slug : '');
		if(!first) return;

		ipRender(r.allow_ips || []);
		var i = r.info || {};
		hasPsiKey = !!i.psi_key; hasChromium = !!i.chromium;
		// the card stays hidden until a key is set (or an old result exists)
		if(hasPsiKey || i.pagespeed) $('#psi-card').show();
		if(i.pagespeed) { psiData = i.pagespeed; psiRender(); }
		if(hasPsiKey) $('#psi-run').show();

		/* Deferred: slow jobs start a few seconds after the page is up, and
		   run as background jobs, so they never compete with the switches. */
		var later = null;
		if(hasPsiKey && !i.pagespeed) later = psiRun;             // first visit: test now (also brings the screenshot)
		else if(!i.screenshot && !hasPsiKey && hasChromium) later = shotTake;
		if(later) setTimeout(later, 3000);

		if(i.screenshot) { /* already rendered by PHP */ }
		else if(later === psiRun) $('#wp-shot').html('<div class="wp-shot-empty">Screenshot follows the PageSpeed test&hellip;</div>');
		else if(later === shotTake) $('#wp-shot').html('<div class="wp-shot-empty">Screenshot in a moment&hellip;</div>');
		else if(!hasPsiKey) $('#wp-shot').html('<div class="wp-shot-empty">No screenshot &mdash; add a PageSpeed API key in <a href="/settings/">Settings</a>.</div>');
		else shotShow(0);
	}
	applyStatus(<?=json_encode($initial, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);?>, true);

	$.post('./ajax-wp-site-status/', { action: 'ajax-wp-site-status', user: wpUser }, null, 'json').done(function (r) {
		if(!r || !r.ok) {
			$('#wp-probe-alert').text((r && r.error) ? r.error : 'Could not load the site status.').show();
			return;
		}
		applyStatus(r, false);
	}).fail(function () {
		$('#wp-probe-alert').text('Could not refresh the site status.').show();
	});
});
</script>
</html>
