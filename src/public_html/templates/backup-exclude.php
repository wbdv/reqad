<?php
/*
 * Exclusions pane of /backup/ — included from templates/backup.php.
 *
 * What the account backups (nightly remote and manual local) leave out. The
 * rules are rows in backup_excludes (db/1036.sql); scripts/backup_excludes.sh
 * is what applies them, and also answers the two on-demand questions asked from
 * here over AJAX: what does each rule match right now (sizes), and what else is
 * worth excluding (suggestions). Both walk the home directory, so neither runs
 * on page load.
 */

$bx_accounts = array();
$rs = $db->query('SELECT user, domain FROM accounts ORDER BY domain');
while ($rs && ($r = $rs->fetchArray(SQLITE3_ASSOC))) $bx_accounts[$r['user']] = $r['domain'];

$bx_user = (isset($_GET['user']) && valid_username($_GET['user']) && isset($bx_accounts[$_GET['user']])) ? $_GET['user'] : '';

/* a missing row is the default: .nobackup honoured, mounts skipped,
   CACHEDIR.TAG not honoured */
$bx_markers_on = setting_get('backup-exclude-markers') !== '0';
$bx_mounts_on = setting_get('backup-exclude-mounts') !== '0';
$bx_caches_on = setting_get('backup-exclude-caches') === '1';

$bx_scopes = backup_exclude_scopes();
$bx_global = backup_exclude_rules('*');

if ($bx_user !== '') {
	$bx_rules  = backup_exclude_rules($bx_user);
	$bx_mounts = backup_exclude_mounts($bx_user);
	$bx_cpanel = backup_exclude_cpanel_file($bx_user) !== false;
	$bx_dbsize = array();
	foreach (database_list($bx_user) as $d) $bx_dbsize[$d['name']] = $d['size_mb'];
	$bx_dbfree = $bx_dbsize;      /* databases not excluded yet, for the add form */
	foreach ($bx_rules as $r) if ($r['kind'] === 'db') unset($bx_dbfree[$r['pattern']]);
}

/* one table row per rule; $global = a server-wide rule shown on an account */
function bx_rule_row($r, $scopes, $user, $global = false, $dbsize = array()) {
	$is_db = $r['kind'] === 'db';
	echo '<tr data-id="'.(int)$r['id'].'" data-scope="'.h($r['scope']).'" data-kind="'.h($r['kind']).'">';
	echo '<td><code>'.h($r['pattern']).'</code>';
	if ($is_db) echo ' <span class="badge bg-azure-lt">database</span>';
	if ($global) echo ' <span class="badge bg-secondary-lt" title="A server-wide rule — edit it under Global settings">global</span>';
	echo '</td>';
	echo '<td>'.h($scopes[$r['scope']] ?? $r['scope']).'</td>';
	echo '<td class="text-muted" style="white-space:normal;">'.h($r['note']).'</td>';
	if ($user !== '*') {      /* the server-wide table has no "matches" column */
		echo '<td class="bx-match text-muted">';
		if ($is_db) echo isset($dbsize[$r['pattern']]) ? h(human_kb($dbsize[$r['pattern']] * 1024)).' <span style="font-size:85%;">(restored empty)</span>' : '<span class="text-danger">database not found</span>';
		else echo '<span class="bx-pending">—</span>';
		echo '</td>';
	}
	echo '<td class="text-end">';
	if (!$global) {
		echo '<form method="post" action="/backup/?tab=exclude" class="d-inline">'
		   . '<input type="hidden" name="action" value="backup-exclude"><input type="hidden" name="op" value="delete">'
		   . '<input type="hidden" name="id" value="'.(int)$r['id'].'"><input type="hidden" name="user" value="'.h($user).'">'
		   . '<button type="submit" class="btn btn-sm">Remove</button></form>';
	}
	echo '</td></tr>';
}

/* the "add a rule" row; $user = '*' for the server-wide table */
function bx_add_form($user, $scopes, $dbfree = array()) {
	$id = $user === '*' ? 'bx-add-global' : 'bx-add-account';
?>
	<form method="post" action="/backup/?tab=exclude" class="row g-2 align-items-end mt-1" id="<?=$id;?>">
		<input type="hidden" name="action" value="backup-exclude">
		<input type="hidden" name="op" value="add">
		<input type="hidden" name="user" value="<?=h($user);?>">
<?php if ($user !== '*' && $dbfree): ?>
		<div class="col-sm-auto">
			<label class="form-label">Type</label>
			<select name="kind" class="form-select bx-kind">
				<option value="path">Files / folders</option>
				<option value="db">Database</option>
			</select>
		</div>
		<div class="col-sm bx-db" style="display:none;">
			<label class="form-label">Database</label>
			<select name="dbname" class="form-select">
<?php	foreach ($dbfree as $n => $mb): ?>
				<option value="<?=h($n);?>"><?=h($n);?> (<?=h(human_kb($mb * 1024));?>)</option>
<?php	endforeach; ?>
			</select>
		</div>
<?php else: ?>
		<input type="hidden" name="kind" value="path">
<?php endif; ?>
		<div class="col-sm bx-path">
			<label class="form-label">Pattern <span class="text-muted" style="font-weight:normal;">(relative to the home directory)</span></label>
			<input type="text" name="pattern" class="form-control" placeholder="e.g. backup, node_modules, public_html/wp-content/cache, *.log" maxlength="255">
		</div>
		<div class="col-sm-auto">
			<label class="form-label">Applies to</label>
			<select name="scope" class="form-select">
<?php	foreach ($scopes as $k => $label): ?>
				<option value="<?=h($k);?>"><?=h($label);?></option>
<?php	endforeach; ?>
			</select>
		</div>
		<div class="col-sm">
			<label class="form-label">Note <span class="text-muted" style="font-weight:normal;">(optional)</span></label>
			<input type="text" name="note" class="form-control" maxlength="200">
		</div>
		<div class="col-sm-auto"><button type="submit" class="btn btn-primary">Add rule</button></div>
	</form>
<?php
}
?>

<style>
	#tab-backup .bx-section { border: none; }
	#tab-backup .bx-section > .card-body { padding-left: 0; padding-right: 0; }
</style>

<!-- ─── global settings ─────────────────────────────────────────────────── -->
<div class="card bx-section">
	<div class="card-body">
		<h3 class="card-title">Global settings</h3>

		<!-- the switches save on change (ajax-backup-exclude-setting) -->
		<div class="mb-3" id="bx-settings">
			<label class="form-check form-switch mb-2">
				<input class="form-check-input bx-setting" type="checkbox" id="bx-set-markers" data-setting="markers" <?=$bx_markers_on ? 'checked' : '';?>>
				<span class="form-check-label">Skip folders holding a <code>.nobackup</code> file <span class="bx-saved text-success ms-2" style="display:none;font-size:85%;"></span></span>
				<span class="form-check-description">An empty file with that name leaves the folder's contents out of every backup; the folder itself is kept. It works over SFTP, without the panel: <code>touch ~/public_html/videos/.nobackup</code>. Switch off to back such folders up anyway.</span>
			</label>
			<label class="form-check form-switch mb-2">
				<input class="form-check-input bx-setting" type="checkbox" id="bx-set-mounts" data-setting="mounts" <?=$bx_mounts_on ? 'checked' : '';?>>
				<span class="form-check-label">Skip filesystems mounted inside a home directory <span class="bx-saved text-success ms-2" style="display:none;font-size:85%;"></span></span>
				<span class="form-check-description">sshfs, NFS, CIFS, another disk… They are read from the mount table, never walked, so a dead network mount cannot stall the backup.</span>
			</label>
			<label class="form-check form-switch mb-2">
				<input class="form-check-input bx-setting" type="checkbox" id="bx-set-caches" data-setting="caches" <?=$bx_caches_on ? 'checked' : '';?>>
				<span class="form-check-label">Skip cache folders marked with <code>CACHEDIR.TAG</code> <span class="bx-saved text-success ms-2" style="display:none;font-size:85%;"></span></span>
				<span class="form-check-description">The standard marker many tools write into folders whose contents they can rebuild. The folder and its tag are kept, the rest is left out.</span>
			</label>
		</div>

<?php if ($bx_global): ?>
		<div class="table-responsive">
			<table class="table table-vcenter card-table">
				<thead><tr>
					<th style="background-color:#DEF;">Pattern</th>
					<th style="background-color:#DEF;">Applies to</th>
					<th style="background-color:#DEF;">Note</th>
					<th style="background-color:#DEF;"></th>
				</tr></thead>
				<tbody>
<?php	foreach ($bx_global as $r) bx_rule_row($r, $bx_scopes, '*'); ?>
				</tbody>
			</table>
		</div>
<?php else: ?>
		<p class="text-muted mb-1">No rules apply to every account.</p>
<?php endif; ?>
		<?php bx_add_form('*', $bx_scopes); ?>

		<div class="mt-3">
			<a href="https://reqad.com/docs/backups#patterns" target="_blank" rel="noopener" class="text-muted">How patterns work
			<svg xmlns="http://www.w3.org/2000/svg" class="icon" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 6h-6a2 2 0 0 0 -2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-6"/><path d="M11 13l9 -9"/><path d="M15 4h5v5"/></svg></a>
		</div>
	</div>
</div>

<hr style="margin:6px -16px 10px -22px;">

<!-- ─── one account ─────────────────────────────────────────────────────── -->
<div class="card bx-section">
	<div class="card-body">
		<h3 class="card-title">Individual account settings</h3>
		<div class="d-flex align-items-center flex-wrap gap-2 mb-3">
			<label class="form-label mb-0 me-3" for="bx-user">Account</label>
			<select class="form-select" id="bx-user" style="max-width:360px;">
				<option value="">Choose an account …</option>
<?php foreach ($bx_accounts as $u => $d): ?>
				<option value="<?=h($u);?>" <?=$u === $bx_user ? 'selected' : '';?>><?=h($d);?> — <?=h($u);?></option>
<?php endforeach; ?>
			</select>
		</div>

<?php if ($bx_user === ''): ?>
		<p class="text-muted">Choose an account to see its rules, what they save, and suggestions.</p>
<?php else: ?>

<?php	foreach ($bx_mounts as $m): ?>
		<!-- both wordings are rendered; the mounts switch above flips between them -->
		<div class="alert bx-mount-alert <?=$bx_mounts_on ? 'alert-success' : 'alert-warning';?>">
			<div class="text"><code>~/<?=h($m['path']);?></code> is a mounted filesystem (<?=h($m['fstype']);?> from <code><?=h($m['source']);?></code>) —
			<span class="bx-on" <?=$bx_mounts_on ? '' : 'style="display:none;"';?>><b>skipped</b> by every backup.</span>
			<span class="bx-off" <?=$bx_mounts_on ? 'style="display:none;"' : '';?>><b>included</b> in backups, because skipping mounted filesystems is switched off above.</span></div>
		</div>
<?php	endforeach; ?>

		<div class="table-responsive">
			<table class="table table-vcenter card-table" id="bx-rules">
				<thead><tr>
					<th style="background-color:#DEF;">Pattern / database</th>
					<th style="background-color:#DEF;">Applies to</th>
					<th style="background-color:#DEF;">Note</th>
					<th style="background-color:#DEF;">Matches now</th>
					<th style="background-color:#DEF;"></th>
				</tr></thead>
				<tbody>
<?php	foreach ($bx_rules as $r) bx_rule_row($r, $bx_scopes, $bx_user, false, $bx_dbsize); ?>
<?php	foreach ($bx_global as $r) bx_rule_row($r, $bx_scopes, $bx_user, true); ?>
<?php	if (!$bx_rules && !$bx_global): ?>
					<tr><td colspan="5" class="text-muted">No rules — everything in the home directory is backed up<?=$bx_mounts && $bx_mounts_on ? ' except the mounted filesystem' : '';?>.</td></tr>
<?php	endif; ?>
				</tbody>
			</table>
		</div>
		<?php bx_add_form($bx_user, $bx_scopes, $bx_dbfree); ?>

		<div class="btn-list mt-3">
			<button type="button" class="btn btn-outline-secondary" id="bx-preview-btn">Calculate sizes</button>
			<button type="button" class="btn btn-outline-secondary" id="bx-suggest-btn">Find things to exclude</button>
<?php	if ($bx_cpanel): ?>
			<form method="post" action="/backup/?tab=exclude" class="d-inline">
				<input type="hidden" name="action" value="backup-exclude">
				<input type="hidden" name="op" value="import">
				<input type="hidden" name="user" value="<?=h($bx_user);?>">
				<button type="submit" class="btn btn-outline-secondary" title="~/cpbackup-exclude.conf exists in this home directory">Import cpbackup-exclude.conf</button>
			</form>
<?php	endif; ?>
		</div>

		<div id="bx-preview" class="mt-3" style="display:none;"></div>
		<div id="bx-suggest" class="mt-3" style="display:none;"></div>

		<!-- the suggestions' Add buttons fill this and submit it -->
		<form method="post" action="/backup/?tab=exclude" id="bx-add-suggested" style="display:none;">
			<input type="hidden" name="action" value="backup-exclude">
			<input type="hidden" name="op" value="add">
			<input type="hidden" name="user" value="<?=h($bx_user);?>">
			<input type="hidden" name="kind" value="path">
			<input type="hidden" name="scope" value="both">
			<input type="hidden" name="pattern" value="">
			<input type="hidden" name="note" value="">
		</form>
<?php endif; ?>
	</div>
</div>

<script>
/* jQuery is loaded by footer.php, after this pane: wait for it */
document.addEventListener('DOMContentLoaded', function () {
	var $ = jQuery;
	$('#bx-user').on('change', function () {
		location.href = '/backup/?tab=exclude' + (this.value ? '&user=' + encodeURIComponent(this.value) : '');
	});
	/* the switches save themselves; on failure the switch is put back */
	$('.bx-setting').on('change', function () {
		var $c = $(this), on = $c.prop('checked'), $msg = $c.closest('label').find('.bx-saved');
		$c.prop('disabled', true);
		$.post('/?ajax=1', { action: 'ajax-backup-exclude-setting', name: $c.data('setting'), value: on ? 1 : 0 }, null, 'json')
			.done(function (r) {
				if (!r || !r.ok) { $c.prop('checked', !on); $msg.removeClass('text-success').addClass('text-danger').text((r && r.error) || 'Not saved').show(); return; }
				$msg.removeClass('text-danger').addClass('text-success').text('Saved').stop(true, true).show().delay(1500).fadeOut(400);
				if ($c.data('setting') === 'mounts') {
					$('.bx-mount-alert').toggleClass('alert-success', on).toggleClass('alert-warning', !on);
					$('.bx-mount-alert .bx-on').toggle(on);
					$('.bx-mount-alert .bx-off').toggle(!on);
				}
			})
			.fail(function () { $c.prop('checked', !on); $msg.removeClass('text-success').addClass('text-danger').text('Not saved').show(); })
			.always(function () { $c.prop('disabled', false); });
	});

	$('.bx-kind').on('change', function () {
		var db = this.value === 'db', $f = $(this).closest('form');
		$f.find('.bx-db').toggle(db);
		$f.find('.bx-path').toggle(!db);
	});

	var user = <?=json_encode($bx_user);?>;
	if (!user) return;

	function esc(t) { return $('<div>').text(t === undefined || t === null ? '' : String(t)).html(); }
	function size(kb) {
		var u = ['KB', 'MB', 'GB', 'TB'], i = 0, v = +kb || 0;
		while (v >= 1024 && i < u.length - 1) { v /= 1024; i++; }
		return (v >= 10 || i === 0 ? Math.round(v) : v.toFixed(1)) + ' ' + u[i];
	}
	function busy($b, on, label) {
		$b.prop('disabled', on).html(on ? '<span class="spinner-border spinner-border-sm me-2"></span>' + label : $b.data('label'));
	}
	$('#bx-preview-btn, #bx-suggest-btn').each(function () { $(this).data('label', $(this).html()); });

	$('#bx-preview-btn').on('click', function () {
		var $b = $(this);
		busy($b, true, 'Walking the home directory…');
		$.post('/?ajax=1', { action: 'ajax-backup-exclude-preview', user: user }, null, 'json').done(function (r) {
			if (!r || !r.ok) { $('#bx-preview').html('<div class="alert alert-danger">' + esc(r && r.error) + '</div>').show(); return; }
			var by = { nightly: 0, manual: 0 };
			$('#bx-rules tr[data-kind=path]').each(function () {
				var id = $(this).data('id'), sc = $(this).data('scope'), m = r.rules[id];
				var $c = $(this).find('.bx-match');
				if (!m || !m.count) { $c.html('<span class="text-muted">nothing</span>'); return; }
				$c.html(esc(size(m.kb)) + ' <span style="font-size:85%;">· ' + m.count + ' item' + (m.count == 1 ? '' : 's') + '</span>')
				  .attr('title', m.samples.join('\n') + (m.count > m.samples.length ? '\n…' : ''));
				if (sc !== 'manual')  by.nightly += m.kb;
				if (sc !== 'nightly') by.manual  += m.kb;
			});
			var markers = 0, h = '';
			var tags = $('#bx-set-markers').prop('checked');
			$.each(r.markers, function (i, m) { if (tags) markers += m.kb; });
			var caches = $('#bx-set-caches').prop('checked');
			$.each(r.cachedirs, function (i, m) { if (caches) markers += m.kb; });
			by.nightly += markers; by.manual += markers;

			h += '<div class="card"><div class="card-body">';
			h += '<div class="row">';
			h += '<div class="col-sm-4"><div class="text-muted">Home directory</div><div class="h3 mb-0">' + esc(size(r.home_kb)) + '</div>'
			   + (r.mounts.length ? '<div class="text-muted" style="font-size:85%;">not counting mounted filesystems</div>' : '') + '</div>';
			h += '<div class="col-sm-4"><div class="text-muted">Nightly backup leaves out</div><div class="h3 mb-0">≈ ' + esc(size(by.nightly))
			   + '</div><div class="text-muted" style="font-size:85%;">≈ ' + esc(size(Math.max(0, r.home_kb - by.nightly))) + ' of files go in</div></div>';
			h += '<div class="col-sm-4"><div class="text-muted">Manual backup leaves out</div><div class="h3 mb-0">≈ ' + esc(size(by.manual))
			   + '</div><div class="text-muted" style="font-size:85%;">≈ ' + esc(size(Math.max(0, r.home_kb - by.manual))) + ' of files go in</div></div>';
			h += '</div>';
			if (r.markers.length || r.cachedirs.length) {
				h += '<div class="mt-3 text-muted">Folders with a marker file:</div><ul class="mb-0">';
				$.each(r.markers, function (i, m) {
					h += '<li><code>~/' + esc(m.path) + '/</code> — ' + esc(size(m.kb)) + ' (<code>.nobackup</code>'
					   + (tags ? '' : ', <b>ignored</b>: the switch above is off') + ')</li>';
				});
				$.each(r.cachedirs, function (i, m) {
					h += '<li><code>~/' + esc(m.path) + '/</code> — ' + esc(size(m.kb)) + ' (<code>CACHEDIR.TAG</code>'
					   + (caches ? '' : ', <b>ignored</b>: the switch above is off') + ')</li>';
				});
				h += '</ul>';
			}
			h += '<div class="text-muted mt-2" style="font-size:85%;">Before compression. Sizes are approximate when two rules match the same files.</div>';
			h += '</div></div>';
			$('#bx-preview').html(h).show();
		}).fail(function () {
			$('#bx-preview').html('<div class="alert alert-danger">Calculating the sizes failed.</div>').show();
		}).always(function () { busy($b, false); });
	});

	$('#bx-suggest-btn').on('click', function () {
		var $b = $(this);
		busy($b, true, 'Looking…');
		$.post('/?ajax=1', { action: 'ajax-backup-exclude-suggest', user: user }, null, 'json').done(function (r) {
			if (!r || !r.ok) { $('#bx-suggest').html('<div class="alert alert-danger">' + esc(r && r.error) + '</div>').show(); return; }
			if (!r.suggestions.length) {
				$('#bx-suggest').html('<div class="alert alert-success"><div class="text">Nothing obvious to exclude: no caches, '
					+ 'node_modules, backup-plugin archives or large archive files over 1 MB that no rule already covers.</div></div>').show();
				return;
			}
			var h = '<h4>Worth excluding</h4><div class="table-responsive"><table class="table table-vcenter card-table"><thead><tr>'
			      + '<th style="background-color:#DEF;">Pattern</th><th style="background-color:#DEF;">What it is</th>'
			      + '<th style="background-color:#DEF;">Size</th><th style="background-color:#DEF;"></th></tr></thead><tbody>';
			$.each(r.suggestions, function (i, s) {
				h += '<tr><td><code>' + esc(s.pattern) + '</code></td><td class="text-muted">' + esc(s.label)
				   + (s.count > 1 ? ' · ' + s.count + ' found' : '') + '</td><td>' + esc(size(s.kb)) + '</td>'
				   + '<td class="text-end"><button type="button" class="btn btn-sm btn-outline-primary bx-suggest-add" data-pattern="'
				   + esc(s.pattern) + '" data-note="' + esc(s.label) + '">Exclude</button></td></tr>';
			});
			h += '</tbody></table></div><div class="text-muted mt-1" style="font-size:85%;">Mail folders are not searched. '
			   + 'Added rules apply to nightly and manual backups; remove one at any time to include it again.</div>';
			$('#bx-suggest').html(h).show();
		}).fail(function () {
			$('#bx-suggest').html('<div class="alert alert-danger">Looking for suggestions failed.</div>').show();
		}).always(function () { busy($b, false); });
	});

	$(document).on('click', '.bx-suggest-add', function () {
		var $f = $('#bx-add-suggested');
		$f.find('[name=pattern]').val($(this).data('pattern'));
		$f.find('[name=note]').val($(this).data('note'));
		$(this).prop('disabled', true);
		$f[0].submit();
	});
});
</script>
