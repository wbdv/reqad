<?php
	/* Exim / Dovecot configuration. $which is a whitelisted key resolved from the
	   URL sub-path (/email-config/exim/), never a path — mail_config_target()
	   rejects anything else. Two ways in:
	     Settings  — a curated form over a fixed whitelist of keys
	     Advanced  — the raw file, with validate / save / version history
	   Both write through apply_mail_config(), which backs up the version being
	   replaced, preflights against the daemon's own parser, writes, re-tests and
	   reverts if the live config disagrees. */

	/* Resolve and validate BEFORE including the header: that template starts
	   emitting HTML immediately, and a redirect issued after it has run leaves
	   a truncated page instead of going anywhere. */
	$which = isset($reqs[2]) ? clean($reqs[2]) : '';
	if (!mail_config_target($which)) {
		header('Location: /email/');
		exit;
	}

	include('templates/header.php');

	$target = mail_config_target($which);
	$info    = mail_stack_info($which);
	$groups  = mail_setting_defs($which);
	$current = mail_settings_current($which);
	$raw     = (string)mail_config_read($which);
	$rbls    = ($which === 'exim') ? exim_dnslists_read() : array();
	$dead    = exim_dnslist_dead();

	$tab = isset($_GET['tab']) ? clean($_GET['tab']) : 'settings';
	if (!in_array($tab, array('settings', 'blocklists', 'advanced'), true)) $tab = 'settings';
	if ($tab === 'blocklists' && $which !== 'exim') $tab = 'settings';

	/* The command whose verdict decides whether a save is allowed through --
	   named in the page text so the admin can run it themselves. */
	$checker = isset($target['checker']) ? $target['checker'] : '';

	/* SpamAssassin .cf is `key value` with # comments, which is what
	   CodeMirror's properties mode already highlights. */
	$cm_mode = ($which === 'exim') ? 'shell' : 'properties';

	/* Effective values straight from the daemon, for the fields left blank.
	   Batched: one exim -bP / doveconf call for the whole page. */
	$defaults = mail_setting_defaults($which, array_keys(mail_setting_keys($which)));

	/* Render one field from its definition. */
	function mailcfg_field($key, $def, $value, $default = '') {
		$type = isset($def['type']) ? $def['type'] : 'text';
		$id   = 'f-'.$key;
		echo '<div class="mb-3">';

		if ($type === 'toggle') {
			/* an unchecked checkbox posts nothing, so a companion hidden field
			   tells the handler this toggle was on the submitted form at all */
			echo '<input type="hidden" name="_group_'.h($key).'" value="1">';
			echo '<label class="form-check form-switch">';
			echo '<input class="form-check-input" type="checkbox" name="'.h($key).'" id="'.h($id).'" value="yes"'.($value === 'yes' ? ' checked' : '').'>';
			echo '<span class="form-check-label'.(!empty($def['danger']) ? ' text-danger' : '').'">'.h($def['label']).'</span>';
			echo '</label>';
		} else {
			/* Not in the config file means the daemon is using its own default,
			   not that the setting is off -- show that value rather than an
			   empty box, and say what clearing the field will do. */
			$ph = ($value === '' && $default !== '')
				? ' placeholder="'.h($default).'"'
				: '';
			echo '<label class="form-label" for="'.h($id).'">'.h($def['label']).'</label>';
			if ($type === 'select') {
				echo '<select class="form-select" name="'.h($key).'" id="'.h($id).'">';
				/* absent from the config file: offer that state explicitly, so
				   saving the page unchanged does not write the first choice in
				   as though it had been chosen */
				if ($value === '') {
					/* the raw default of an inverted boolean ("0" for
					   skip_rbl_checks) says nothing -- show the choice it
					   corresponds to instead */
					$dlbl = ($default !== '' && isset($def['choices'][$default]))
						? $def['choices'][$default] : $default;
					echo '<option value="" selected>Use the built-in default'.
					     ($dlbl !== '' ? ' ('.h($dlbl).')' : '').'</option>';
				}
				foreach ($def['choices'] as $v => $lbl)
					echo '<option value="'.h($v).'"'.($value === $v ? ' selected' : '').'>'.h($lbl).'</option>';
				/* a value already in the file that is not one of ours must not be
				   silently rewritten by simply opening the page */
				if ($value !== '' && !isset($def['choices'][$value]))
					echo '<option value="'.h($value).'" selected>'.h($value).' (current)</option>';
				echo '</select>';
			} elseif ($type === 'textarea') {
				echo '<textarea class="form-control" name="'.h($key).'" id="'.h($id).'" rows="'.(int)(isset($def['rows']) ? $def['rows'] : 3).'"'.$ph.'>'.h($value).'</textarea>';
			} elseif ($type === 'number') {
				echo '<input type="number" class="form-control" name="'.h($key).'" id="'.h($id).'" value="'.h($value).'"'.$ph.
				     (isset($def['min']) ? ' min="'.(int)$def['min'].'"' : '').
				     (isset($def['max']) ? ' max="'.(int)$def['max'].'"' : '').'>';
			} else {
				echo '<input type="text" class="form-control" name="'.h($key).'" id="'.h($id).'" value="'.h($value).'"'.$ph.'>';
			}
		}

		$hint = !empty($def['help']) ? $def['help'] : '';
		if ($type !== 'toggle' && $type !== 'select' && $default !== '') {
			$note = ($value === '')
				? 'Not set — '.h($def['label'] === '' ? $key : strtolower($def['label'])).' is currently <code>'.h($default).'</code> (the built-in default).'
				: 'Leave empty to fall back to the built-in default, <code>'.h($default).'</code>.';
			$hint = ($hint !== '' ? $hint.'<br>' : '').'<span class="text-muted">'.$note.'</span>';
		}
		if ($hint !== '')
			echo '<small class="form-hint">'.$hint.'</small>';   // help text is ours, and carries <code>
		echo '</div>';
	}

	function mailcfg_tab_url($which, $tab) {
		return '/email-config/'.$which.'/'.($tab !== 'settings' ? '?tab='.$tab : '');
	}
?>
          <!-- Page title -->
          <div class="page-header d-print-none">
            <div class="row align-items-center">
              <div class="col" style="padding-left:22px;">
                <div class="page-pretitle">Email</div>
                <h2 class="page-title"><?=h($info['label']);?> Configuration</h2>
                <div style="min-width:250px">
                  Server: <?=h($info['label']);?> <?=h($info['version']);?>
                  <?php if ($info['active']) { ?>
                    <span class="badge bg-success sm" style="position:relative;top:-2px;">up and running</span>
                  <?php } else { ?>
                    <span class="badge bg-danger sm" style="position:relative;top:-2px;">not running</span>
                  <?php } ?>
                  <?php if (!empty($info['since_ts'])) { ?>
                    &nbsp; <span class="d-none d-sm-inline" title="<?=h($info['since']);?>">Running for <?=h(human_duration(time() - (int)$info['since_ts']));?></span>
                  <?php } ?>
                </div>
              </div>
              <div class="col-auto ms-auto d-print-none">
                <div class="btn-list">
                  <a href="/email/" class="btn btn-white">
					<svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><path d="M5 12l14 0"></path><path d="M5 12l6 6"></path><path d="M5 12l6 -6"></path></svg>
					Back to Email Overview</a>
                </div>
              </div>
            </div>
          </div>

<?php msg_render(); ?>

<style>
/* Tab chrome copied from the PHP Settings page so the two read as one UI. */
#mailcfg-main-card { border: none; }
#mailcfg-main-card > .card-header { padding-bottom: 0; background: #f6f8fb; }
#mailcfg-tabs { border-bottom: 0; gap: 30px; }
#mailcfg-tabs .nav-link {
	display: block;
	border: 1px solid transparent;
	padding: 10px 20px !important;
	color: #6c757d;
	font-weight: 500;
	line-height: 20pt !important;
	margin-bottom: -1px;
	margin-left: -16px !important;
}
#mailcfg-tabs .nav-link:hover { border-color: #dee2e6 #dee2e6 transparent; background: #475db41a; color: #354052; }
#mailcfg-tabs .nav-link.active { background: #fff; border-color: #dee2e6 #dee2e6 #fff; color: #354052; font-weight: bold; }
.mailcfg-pane { border: 1px solid; border-color: transparent #dee2e6 #dee2e6 #dee2e6; }
</style>

<div class="col-12">
  <div class="card mt-3" id="mailcfg-main-card">
    <div class="card-header">
      <ul class="nav nav-tabs card-header-tabs" id="mailcfg-tabs">
        <li class="nav-item"><a class="nav-link <?=($tab=='settings'?'active':'');?>" href="<?=h(mailcfg_tab_url($which,'settings'));?>">Settings</a></li>
        <?php if ($which === 'exim') { ?>
        <li class="nav-item"><a class="nav-link <?=($tab=='blocklists'?'active':'');?>" href="<?=h(mailcfg_tab_url($which,'blocklists'));?>">Blocklists (RBL)</a></li>
        <?php } ?>
        <li class="nav-item"><a class="nav-link <?=($tab=='advanced'?'active':'');?>" href="<?=h(mailcfg_tab_url($which,'advanced'));?>">Advanced</a></li>
      </ul>
    </div>
    <div class="tab-content">

<?php if ($tab === 'settings') { ?>
    <div class="tab-pane active show mailcfg-pane" id="tab-settings">
    <div class="card-body">
      <p class="text-muted" style="margin-left:5px;margin-top:10px;">
        Editing <code><?=h($target['path']);?></code>. Every change is checked with
        <code><?=h($checker);?></code> before it is written, the previous
        version is kept, and the file is restored automatically if
        <?=h($info['service']);?> rejects it.
      </p>

      <form method="post" action="/email-config/<?=h($which);?>/" id="mailcfg-form">
        <input type="hidden" name="action" value="save-mail-settings">
        <input type="hidden" name="which" value="<?=h($which);?>">
        <div class="row">
        <?php foreach ($groups as $gid => $g) { ?>
          <div class="col-md-6">
            <div class="card mb-3" style="border:none;padding:0;">
              <div class="card-header" style="border:none;padding:5px;"><h3 class="card-title" style="margin:10px 0 0 0;font-weight:bold"><?=$g['title'];?></h3></div>
              <div class="card-body" style="padding:5px;">
                <?php if (!empty($g['warn'])) { ?>
                  <div class="alert alert-warning"><?=h($g['warn']);?></div>
                <?php } ?>
                <?php
                  /* A half-installed plugin behaves exactly like a working one
                     from here -- the button appears and the ticket is minted,
                     the user just lands on the login form. Say so up front. */
                  if ($gid === 'webmail' && $which === 'dovecot') {
                      $wst = webmail_plugin_status();
                      if (!$wst['ok']) {
                          echo '<div class="alert alert-warning">'.h($wst['message']);
                          if ($wst['state'] !== 'no-roundcube')
                              echo ' It is installed automatically when you enable the switch below.';
                          echo '</div>';
                      }
                  }
                ?>
                <?php foreach ($g['keys'] as $key => $def)
                        mailcfg_field($key, $def,
                                      isset($current[$key])  ? $current[$key]  : '',
                                      isset($defaults[$key]) ? $defaults[$key] : ''); ?>
              </div>
            </div>
          </div>
        <?php } ?>
        </div>

        <div class="btn-list">
          <button type="submit" class="btn btn-primary" id="mailcfg-submit">Save and <?=h(isset($target['reload']) && $target['reload'] === 'restart' ? 'restart' : 'reload');?> <?=h($info['service']);?></button>
          <a href="/email-config/<?=h($which);?>/" class="btn btn-white">Discard changes</a>
        </div>
      </form>
    </div>

    </div>
<?php } elseif ($tab === 'blocklists') { ?>
    <div class="tab-pane active show mailcfg-pane" id="tab-blocklists">
    <div class="card-body">

      <p class="text-muted" style="margin-top:10px;">
        DNS blocklists exim consults while accepting mail. A blocklist that no longer answers
        does not fail quietly — every lookup waits for a DNS timeout, on every message — so
        remove any marked <span class="badge bg-red-lt">dead</span>.
      </p>

      <?php foreach ($rbls as $i => $list) {
              $is_auth = ($i === 0 && count($rbls) > 1);
      ?>
        <div class="card mb-3" style="border:0;">
          <div class="card-header" style="border:0;padding:5px;">
            <h3 class="card-title" style="margin:10px 0 0 0;font-weight:bold">
              <?=$is_auth ? 'Authenticated senders' : 'Incoming mail (RCPT)';?>
              <small class="text-muted d-block">exim.conf line <?=(int)($list['line'] + 1);?></small>
            </h3>
          </div>
          <div class="card-body" style="padding:5px;">
            <div class="mb-2">
              <?php foreach ($list['hosts'] as $hst) { ?>
                <span class="badge <?=(in_array($hst, $dead, true) ? 'bg-red-lt' : 'bg-green-lt');?> me-1"><?=h($hst);?><?=(in_array($hst, $dead, true) ? ' — dead' : '');?></span>
              <?php } ?>
            </div>
            <form method="post" action="/email-config/exim/">
              <input type="hidden" name="action" value="save-mail-rbl">
              <input type="hidden" name="list" value="<?=(int)$i;?>">
              <div class="mb-3">
                <label class="form-label">Blocklist hostnames, one per line</label>
                <textarea class="form-control" name="hosts" rows="<?=max(4, count($list['hosts']) + 1);?>"><?=h(implode("\n", $list['hosts']));?></textarea>
                <small class="form-hint">Leave empty to disable this check entirely (the condition is commented out, not deleted).</small>
              </div>
              <button type="submit" class="btn btn-primary">Save blocklist</button>
            </form>
          </div>
        </div>
      <?php } ?>
      <?php if (!$rbls) { ?>
        <div class="alert alert-info">No DNS blocklists are configured in <code><?=h($target['path']);?></code>.</div>
      <?php } ?>
    </div>

    </div>
<?php } else { ?>
    <div class="tab-pane active show mailcfg-pane" id="tab-advanced">
    <div class="card-body">
      <p class="text-muted">
        The whole of <code><?=h($target['path']);?></code>.
        <strong>Validate</strong> runs <code><?=h($checker);?></code> against a
        scratch copy without touching the live file. <strong>Save</strong> validates first, keeps the version it
        replaces, and reverts automatically if <?=h($info['service']);?> rejects the result.
        <?php if ($which === 'dovecot') { ?>
          Only <code>local.conf</code> is editable — <code>dovecot.conf</code> and <code>conf.d/</code> belong to the
          package. See the effective configuration below for what dovecot is actually running.
        <?php } elseif ($which === 'spamassassin') { ?>
          Only <code>local.cf</code> is editable — the rule files under
          <code>/etc/mail/spamassassin/</code> and <code>/var/lib/spamassassin/</code> are maintained by
          <code>sa-update</code> and would be overwritten. Per-account whitelists and blacklists live in each
          account's <code>user_prefs</code>, edited on the <a href="/spam-filters/">Spam Filters</a> page.
        <?php } ?>
      </p>

      <div id="mailcfg-alert" class="d-none"></div>

      <textarea id="mailcfg-raw" style="display:none;"><?=h($raw);?></textarea>

      <div class="btn-list mt-3">
        <button class="btn" id="mailcfg-validate">Validate</button>
        <button class="btn btn-primary" id="mailcfg-save">Save and <?=h(isset($target['reload']) && $target['reload'] === 'restart' ? 'restart' : 'reload');?> <?=h($info['service']);?></button>
        <button class="btn btn-white" id="mailcfg-versions-btn">Version history</button>
      </div>

      <div id="mailcfg-versions" class="mt-3 d-none">
        <div class="card">
          <div class="card-header"><h3 class="card-title">Previous versions</h3></div>
          <div class="list-group list-group-flush" id="mailcfg-versions-list"></div>
        </div>
      </div>

      <?php if ($which === 'dovecot') { ?>
        <h3 class="mt-4">Effective configuration</h3>
        <p class="text-muted">Output of <code>doveconf -n</code> — every setting dovecot is running with, from all files.</p>
        <pre style="background:#f6f8fa;border:1px solid #e3e6ea;border-radius:4px;padding:10px;font-size:12px;max-height:400px;overflow:auto;"><?=h((string)shell_exec('sudo doveconf -n 2>&1'));?></pre>
      <?php } ?>
    </div>
    </div>
<?php } ?>

    </div><!-- .tab-content -->
  </div>
</div>

<?php include('templates/footer.php'); ?>

<?php if ($tab === 'advanced') { ?>
<link href="./dist/libs/codemirror/codemirror.min.css" rel="stylesheet">
<script src="./dist/libs/codemirror/codemirror.min.js"></script>
<script src="./dist/libs/codemirror/mode/<?=h($cm_mode);?>/<?=h($cm_mode);?>.min.js"></script>
<script src="./dist/libs/codemirror/addon/edit/matchbrackets.min.js"></script>
<script>
jQuery(function ($) {
	'use strict';
	var WHICH = <?=json_encode($which);?>;
	// restored on the Save button after a save finishes, so it must match the
	// label PHP rendered -- spamassassin restarts where the others reload
	var SAVE_LABEL = <?=json_encode('Save and '.(isset($target['reload']) && $target['reload'] === 'restart' ? 'restart' : 'reload').' '.$info['service']);?>;

	var cm = CodeMirror.fromTextArea(document.getElementById('mailcfg-raw'), {
		lineNumbers: true,
		mode: <?=json_encode($cm_mode);?>,
		matchBrackets: true,
		indentUnit: 4,
		lineWrapping: true
	});
	cm.setSize(null, 520);

	function alertBox(msg, cls) {
		$('#mailcfg-alert').removeClass('d-none').html(
			'<div class="alert alert-' + cls + '"><pre style="margin:0;white-space:pre-wrap;font-size:12px;">' +
			$('<span>').text(msg).html() + '</pre></div>'
		);
		$('html, body').animate({ scrollTop: $('#mailcfg-alert').offset().top - 80 }, 200);
	}
	function clearAlert() { $('#mailcfg-alert').addClass('d-none').empty(); }

	function post(action, data, done, fail) {
		data = data || {};
		data.action = action;
		data.which = WHICH;
		return $.post('/?ajax=1', data, null, 'json')
			.done(function (res) {
				if (!res || res.error) { (fail || function (m) { alertBox(m, 'danger'); })(res && res.error ? res.error : 'Request failed.'); return; }
				if (done) done(res);
			})
			.fail(function () { (fail || function (m) { alertBox(m, 'danger'); })('Request failed. Please try again.'); });
	}

	$('#mailcfg-validate').on('click', function () {
		var $b = $(this).prop('disabled', true);
		clearAlert();
		post('ajax-mailconf-validate', { content: cm.getValue() }, function () {
			$b.prop('disabled', false);
			alertBox('Configuration is valid. Nothing has been written yet — use Save to apply it.', 'success');
		}, function (m) { $b.prop('disabled', false); alertBox(m, 'danger'); });
	});

	$('#mailcfg-save').on('click', function () {
		var $b = $(this).prop('disabled', true).text('Saving…');
		clearAlert();
		post('ajax-mailconf-save', { content: cm.getValue() }, function (res) {
			$b.prop('disabled', false).text(SAVE_LABEL);
			alertBox(res.message || 'Saved.', 'success');
		}, function (m) {
			$b.prop('disabled', false).text(SAVE_LABEL);
			alertBox(m, 'danger');
		});
	});

	$('#mailcfg-versions-btn').on('click', function () {
		var $wrap = $('#mailcfg-versions');
		if (!$wrap.hasClass('d-none')) { $wrap.addClass('d-none'); return; }
		post('ajax-mailconf-versions', {}, function (res) {
			var $list = $('#mailcfg-versions-list').empty();
			if (!res.versions || !res.versions.length) {
				$list.append('<div class="list-group-item text-muted">No previous versions yet — one is kept every time you save.</div>');
			} else {
				res.versions.forEach(function (v) {
					$('<div class="list-group-item d-flex align-items-center">')
						.append($('<div>').text(v.when + '  ·  ' + v.size))
						.append($('<button class="btn btn-sm btn-white ms-auto">').text('Load into editor').attr('data-version', v.id))
						.appendTo($list);
				});
			}
			$wrap.removeClass('d-none');
		});
	});

	// Loading an old version only fills the editor — nothing is written until Save,
	// so a restore is reviewable and goes through the same validation as any edit.
	$('#mailcfg-versions-list').on('click', 'button[data-version]', function () {
		post('ajax-mailconf-restore', { version: $(this).data('version') }, function (res) {
			cm.setValue(res.content);
			$('#mailcfg-versions').addClass('d-none');
			alertBox('That version is loaded in the editor. Review it, then use Save to apply it.', 'info');
		});
	});
});
</script>
<?php } ?>
