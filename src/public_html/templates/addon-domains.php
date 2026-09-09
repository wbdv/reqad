<?php
	/* Addon domains — extra domains hosted under an existing account, created
	   with scripts/adddomain. They have no accounts row (unique indexes on user
	   and domain), so the list is built from the vhost files themselves; see
	   addon_domain_list() in modules/functions.php. */
	$items       = 10;
	$addons      = addon_domain_list($ini);
	$nb_addons   = count($addons);
	$php_versions = array_map('trim', explode(',', $ini['php_versions']));
	$is_apache   = addon_is_apache($ini);
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

	if(!isset($errmsg))
		$errmsg = '';
	include('templates/header.php');
?>
          <!-- Page title -->
          <div class="page-header d-print-none">
            <div class="row align-items-center">
              <div class="col" style="padding-left:22px;">
                <!-- Page pre-title -->
                <div class="page-pretitle">
                  Accounts
                </div>
                <h2 class="page-title" style="white-space:nowrap !important;">
                  Addon Domains
                </h2>
              </div>
            </div>
          </div>

<?php msg_render(); /* flash message (PRG) */ ?>

<?	if($nb_addons == 0) { ?>
		<p style="padding:14px;">There are no addon domains on this server.</p>
		<p class="text-muted" style="padding:0 14px;">
			Add one from the command line:<br>
			<code>/usr/local/reqad/scripts/adddomain --user=&lt;account&gt; --domain=&lt;domain&gt; --php=<?=h($ini['php']);?><?=$is_apache?' --handler=fpm':'';?></code>
		</p>
<? 	} else { ?>

		<div class="col-12">
            <div class="card">
                <div class="table-responsive">
                  <table class="table table-vcenter card-table table-nowrap">
                    <thead>
                      <tr>
                        <th class="w-1" style="background-color:#DEF;">ID</th>
                        <th style="background-color:#DEF;">Domain <svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' width='16' height='16'><path fill='none' stroke='currentColor' stroke-linecap='round' stroke-linejoin='round' stroke-width='1' d='M5 10l3 -3l3 3'/></svg></th>
                        <th style="background-color:#DEF;">User</th>
                        <th style="background-color:#DEF;">Document Root</th>
                        <th style="background-color:#DEF;">PHP Version</th>
					<? if($is_apache): ?>
                        <th style="background-color:#DEF;">Handler</th>
					<? endif; ?>
                        <th style="background-color:#DEF;">Created At</th>
                        <th class="w-5" style="background-color:#DEF;"></th>
                      </tr>
					<? if($nb_addons > 1 ) { ?>
                      <tr style="background-color:#FFF;">
                        <td style="padding:4px 8px;">&nbsp;</td>
                        <td style="padding:4px 8px;">
                          <div style="position:relative;">
                            <input type="text" id="addon-search" class="form-control" placeholder="Filter domains..." style="padding:6px;padding-right:26px;line-height:8pt;font-size:10pt;border:none;" autocomplete="off">
                            <button id="addon-search-clear" type="button" title="Clear" style="display:none;position:absolute;right:7px;top:50%;transform:translateY(-50%);background:none;border:none;padding:0;cursor:pointer;color:#aaa;font-size:15px;line-height:1;">&#x2715;</button>
                          </div>
						</td>
                        <td colspan="<?=5 + ($is_apache ? 1 : 0);?>" style="padding:4px 8px;"></td>
                      </tr>
					<? } ?>
                    </thead>
                    <tbody>
                    <?
                    	$i = 0;
                      	foreach($addons as $row) {
                        	$i++;
							$domain = $row["domain"];
                    ?>
                      <tr class="addon-row" data-domain="<?=h($domain);?>" data-idx="<?=$i;?>" style="<?=$i>$items?'display:none':'';?>">
                        <td data-label="ID">
                          <div class="d-flex">
                            <div class="flex-fill">
                              <div class="text-muted"><?=$i;?>.</div>
                            </div>
                          </div>
                        </td>
                        <td data-label="Domain">
                          <div class="d-flex py-1 align-items-center">
                            <div class="flex-fill">
                              <div class="font-weight-medium"><a href="http<?=$row["ssl"]!=''?'s':'';?>://<?=h($domain);?>" target="_blank">
								  <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-link" width="24" height="24" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">   <path stroke="none" d="M0 0h24v24H0z" fill="none"></path>   <path d="M10 14a3.5 3.5 0 0 0 5 0l4 -4a3.5 3.5 0 0 0 -5 -5l-.5 .5"></path>   <path d="M14 10a3.5 3.5 0 0 0 -5 0l-4 4a3.5 3.5 0 0 0 5 5l.5 -.5"></path></svg>
								  <?=h($domain);?></a>
								  <? if($row["ssl"] == 'self-signed') { ?>
									<span class="badge bg-orange-lt border text-orange" style="font-size:.7em;" title="No Let's Encrypt certificate — a self-signed one is in use">self-signed</span>
								  <? } ?>
							  </div>
                            </div>
                          </div>
                        </td>
                        <td data-label="User">
                          <div class="d-flex py-1 align-items-center">
                            <div class="flex-fill">
<?php /*                              <a href="/account/<?=h($row["user"]);?>/"><?=h($row["user"]);?></a> */ ?>
                              <?=h($row["user"]);?>
                            </div>
                          </div>
                        </td>
                        <td class="text-muted" data-label="Document Root">
                          <span style="font-family:monospace;white-space:nowrap;"><?=h($row["docroot"]);?></span>
                        </td>
                        <td class="text-muted" data-label="PHP Version">
							<? $php_color = $php_version_colors[$row["version"]] ?? '#aaa'; ?>
							<? if($row["handler"] == 'none') { ?>
							<span class="badge bg-danger" title="No php-fpm pool for this domain — PHP requests will fail">no pool</span>
							<? } else { ?>
							<span class="badge" style="background-color:<?=$php_color;?>">PHP <?=h($row["version"]);?></span>
							<? } ?>
                        </td>
					<? if($is_apache): ?>
                        <td class="text-muted" data-label="Handler">
							<span class="text-muted" style="font-size:0.85em;"><?=$row["handler"] == 'fpm' ? 'php-fpm' : $row["handler"];?></span>
                        </td>
					<? endif; ?>
                        <td data-label="Created on" class="text-muted">
                          <div class="font-weight-medium"><?=date("M jS, Y - H:i", strtotime($row["created"]));?></div>
                        </td>
                        <td>
                          <div class="btn-list flex-nowrap">
                            <a href="#" class="btn btn-white btn-md" data-bs-toggle="modal" data-bs-target="#modal-edit-addon" data-bs-domain="<?=h($domain);?>" data-bs-user="<?=h($row["user"]);?>" data-bs-phpversion="<?=h($row["version"]);?>" data-bs-phphandler="<?=h($row["handler"]);?>">Manage</a>
                            <a href="#" class="btn btn-white btn-md" data-bs-toggle="modal" data-bs-target="#modal-delete-addon" data-bs-domain="<?=h($domain);?>" data-bs-docroot="<?=h($row["docroot"]);?>">Delete</a>
                          </div>
                        </td>
                      </tr>
                      <? } ?>
                    </tbody>
                  </table>
                </div>
		        <div id="addon-footer" class="card-footer d-flex align-items-center">
				<? if($nb_addons > $items): ?>
					<p class="m-0 text-muted"><span class="d-none d-xl-inline">Showing </span>1 to <?=$items;?> of <?=$nb_addons;?> addon domains</p>
					<ul class="pagination m-0 ms-auto">
						<li class="page-item disabled"><a class="page-link" href="#" data-start="1"><svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><polyline points="15 6 9 12 15 18"></polyline></svg> prev</a></li>
						<? for($p = 1; $p <= ceil($nb_addons/$items); $p++): ?>
						<li class="page-item <?=$p===1?'active':'';?>"><a class="page-link" href="#" data-start="<?=($p-1)*$items+1;?>"><?=$p;?></a></li>
						<? endfor; ?>
						<li class="page-item"><a class="page-link" href="#" data-start="<?=$items+1;?>">next <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><polyline points="9 6 15 12 9 18"></polyline></svg></a></li>
					</ul>
				<? else: ?>
					<p class="m-0 text-muted">Total: <?=$nb_addons;?> addon domain<?=$nb_addons>1?'s':'';?>.</p>
				<? endif; ?>
				</div>
            </div>
			<p class="text-muted" style="padding:18px 14px;font-size:0.9em;">
				<b>Note:</b> Addon domains are created from the command line:
				<code>/usr/local/reqad/scripts/adddomain --user=&lt;account&gt; --domain=&lt;domain&gt; --php=<?=h($ini['php']);?><?=$is_apache?' --handler=fpm':'';?></code>
			</p>
			<?  } ?>

            </div>
          </div>
        </div>
      </div>
    </div>

	<form method="post" action="/" id="edit-addon" class="needs-validation" novalidate>
    <input type="hidden" name="action" value="edit-addon-domain">
    <input type="hidden" name="domain" id="addon-domain-edit" value="">
    <div class="modal modal-blur fade" id="modal-edit-addon" tabindex="-1" role="dialog" aria-hidden="true">
      <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Manage addon domain</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <p>Change the PHP version for this addon domain.</p>
            <div class="mb-3">
              <label class="form-label">Domain name:</label>
              <code id="addon-domain-title"></code>
            </div>
            <div class="mb-3">
              <label class="form-label">Account:</label>
              <code id="addon-user-title"></code>
            </div>
			<div class="row">
              <div class="col-lg-6">
                <div class="mb-3">
          			<label class="form-label">PHP version:</label>
		            <select name="phpversion" id="addon-phpversion" class="form-select">
					<? if($is_apache): ?>
						<option value="<?=$ini['php'];?>:mod_php">PHP <?=$ini['php'];?> (mod_php)</option>
						<option value="<?=$ini['php'];?>:fpm">PHP <?=$ini['php'];?> (php-fpm)</option>
						<? foreach ($php_versions as $pv): if($pv == $ini['php']) continue; ?>
						<option value="<?=$pv;?>:fpm">PHP <?=$pv;?> (php-fpm)</option>
						<? endforeach; ?>
					<? else: ?>
						<? foreach ($php_versions as $pv): ?>
						<option value="<?=$pv;?>">PHP <?=$pv;?></option>
						<? endforeach; ?>
					<? endif; ?>
        		    </select>
		            <div class="invalid-feedback">
        		        Please select a php version to use on this domain.
            		</div>
           		</div>
			  </div>
		    </div>
          </div>
          <div class="modal-footer">
            <a href="#" class="btn btn-link link-secondary" data-bs-dismiss="modal">
              Cancel
            </a>
            <button id="addon-submit-edit" class="btn btn-primary" type="submit">Save changes</button>
          </div>
        </div>
      </div>
    </div>
</form>

<form method="post" action="/" id="delete-addon" class="needs-validation" novalidate>
    <input type="hidden" name="action" value="delete-addon-domain">
    <input type="hidden" name="domain" id="addon-domain-delete" value="">
    <div class="modal modal-blur fade" id="modal-delete-addon" tabindex="-1" role="dialog" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Delete addon domain</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-status bg-danger"></div>
          <div class="modal-body text-center py-4">
			<svg xmlns="http://www.w3.org/2000/svg" class="icon mb-2 text-danger icon-lg" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="#ff2825" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="12" cy="12" r="9" /><line x1="12" y1="8" x2="12" y2="12" /><line x1="12" y1="16" x2="12.01" y2="16" /></svg>
            <h3>Delete <span id="addon-domain-title2"></span></h3>
            <div class="text-muted">The vhost and the php-fpm pool are removed. The account and its main domain stay untouched.</div>
            <label class="form-check form-switch d-inline-flex mt-3" style="text-align:left;">
              <input class="form-check-input" type="checkbox" name="delete_docroot" id="addon-delete-docroot">
              <span class="form-check-label">Also delete the document root <span class="text-muted" id="addon-docroot-title"></span> and all its files</span>
            </label>
          </div>
          <div class="modal-footer">
            <div class="w-100">
              <div class="row">
                <div class="col"><a href="#" class="btn btn-white w-100" data-bs-dismiss="modal">
                    Cancel
                </a></div>
                <div class="col"><button id="addon-submit-delete" class="btn btn-primary w-100" type="submit">
					Delete domain
				</button></div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
</form>

<?php include('templates/footer.php'); ?>
<script>
jQuery(document).ready(function () {
	'use strict';

	// Search / pagination — same behaviour as the accounts list
	var addonStart = 1;
	var addonItems = <?=$items;?>;
	var addonTotal = <?=$nb_addons;?>;
	var svgPrev = '<svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><polyline points="15 6 9 12 15 18"></polyline></svg>';
	var svgNext = '<svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><polyline points="9 6 15 12 9 18"></polyline></svg>';

	function renderAddonFooter(total, start) {
		var html = '';
		if (total > addonItems) {
			var pages = Math.ceil(total / addonItems);
			var curPage = Math.floor((start - 1) / addonItems) + 1;
			html += '<p class="m-0 text-muted"><span class="d-none d-xl-inline">Showing </span>' + start + ' to ' + Math.min(start + addonItems - 1, total) + ' of ' + total + ' addon domains</p>';
			html += '<ul class="pagination m-0 ms-auto">';
			html += '<li class="page-item' + (start === 1 ? ' disabled' : '') + '"><a class="page-link" href="#" data-start="' + Math.max(1, start - addonItems) + '">' + svgPrev + ' prev</a></li>';
			for (var p = 1; p <= pages; p++) {
				html += '<li class="page-item' + (p === curPage ? ' active' : '') + '"><a class="page-link" href="#" data-start="' + ((p - 1) * addonItems + 1) + '">' + p + '</a></li>';
			}
			html += '<li class="page-item' + ((start + addonItems) > total ? ' disabled' : '') + '"><a class="page-link" href="#" data-start="' + (start + addonItems) + '">next ' + svgNext + '</a></li>';
			html += '</ul>';
		} else {
			html = '<p class="m-0 text-muted">Total: ' + total + ' addon domain' + (total !== 1 ? 's' : '') + '.</p>';
		}
		$('#addon-footer').html(html);
	}

	function showAddonPage(start) {
		addonStart = start;
		$('.addon-row').each(function() {
			var idx = parseInt($(this).data('idx'));
			$(this).toggle(idx >= addonStart && idx < addonStart + addonItems);
		});
		renderAddonFooter(addonTotal, addonStart);
	}

	$('#addon-footer').on('click', '.page-link', function(e) {
		e.preventDefault();
		if ($(this).closest('.page-item').hasClass('disabled')) return;
		showAddonPage(parseInt($(this).data('start')));
	});

	$('#addon-search').on('input', function() {
		$('#addon-search-clear').toggle($(this).val() !== '');
		var q = $(this).val().toLowerCase().trim();
		if (q === '') { showAddonPage(addonStart); return; }
		var shown = 0;
		$('.addon-row').each(function() {
			var matches = $(this).data('domain').toLowerCase().indexOf(q) !== -1;
			$(this).toggle(matches);
			if (matches) shown++;
		});
		$('#addon-footer').html('<p class="m-0 text-muted">' + shown + ' result' + (shown !== 1 ? 's' : '') + ' for &ldquo;' + $('<span>').text(q).html() + '&rdquo;</p>');
	});

	$('#addon-search-clear').on('click', function() {
		$('#addon-search').val('');
		$('#addon-search-clear').hide();
		showAddonPage(addonStart);
	});

	$('#edit-addon').on('show.bs.modal', function (event) {
		var button = event.relatedTarget;
		var domain = button.getAttribute('data-bs-domain');
		var user = button.getAttribute('data-bs-user');
		var phpversion = button.getAttribute('data-bs-phpversion');
		var phphandler = button.getAttribute('data-bs-phphandler');
		$('#addon-domain-edit').val(domain);
		$('#addon-domain-title').html(domain);
		$('#addon-user-title').html(user);
	<?php if($is_apache): ?>
		$('#addon-phpversion').val(phpversion + ':' + (phphandler === 'fpm' ? 'fpm' : 'mod_php'));
	<?php else: ?>
		$('#addon-phpversion').val(phpversion);
	<?php endif; ?>
  	});

	$('#edit-addon').submit(function(event) {
		$('#addon-submit-edit').prop('disabled', true);
		$('#edit-addon').unbind('submit').submit();
  	});

	$('#delete-addon').on('show.bs.modal', function (event) {
		var button = event.relatedTarget;
		var domain = button.getAttribute('data-bs-domain');
		var docroot = button.getAttribute('data-bs-docroot');
		$('#addon-domain-delete').val(domain);
		$('#addon-domain-title2').html(domain);
		$('#addon-docroot-title').text(docroot);
		$('#addon-delete-docroot').prop('checked', false);
  	});

	$('#delete-addon').submit(function(event) {
		$('#addon-submit-delete').prop('disabled', true);
		$('#delete-addon').unbind('submit').submit();
  	});
});
</script>
</body>
</html>
