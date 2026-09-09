<?php
/*
 * Remote backup pane of /backup/ — included from templates/backup.php.
 * This is the tab the page opens on, so everything it needs from the backup
 * server comes from ONE cached ssh call (remote_backup_dates): the connection
 * state, the number of backups and the free space all come out of it.
 */

/* One-time move of the credentials out of defines.php and into the settings
   table, so the form below has something to edit. defines.php is left alone. */
if (remote_backup_import_defines())
	echo '<div class="alert alert-info" style="background:#EEF0FF;">Imported the existing backup server settings from <code>defines.php</code>. They are now editable here.</div>';

/* Downloads pull whole account archives (shadow hashes, keys) and the system/
   bundle (/root/.ssh, /root/.my.cnf, /etc) off the backup server, so they are
   root-level access and are gated the same way the terminal and root cron are. */
$rb_root = isset($ini['root_access']) ? (int)$ini['root_access'] : 1;

$rb_cfg = remote_backup_config();

/* whole-server restore: may we, and is one running right now */
$rb_srv_allowed = restore_server_allowed($db, $ini);
$rb_srv_status  = restore_server_status();
$rb_configured = remote_backup_configured($rb_cfg);

/* the nightly entry, read from /etc/crontab so the switch reflects reality
   rather than a stored flag */
$rb_cron_line = trim((string)shell_exec("sudo grep -F 'backup_remote.sh' /etc/crontab 2>/dev/null | grep -v '^#' | head -1"));
$rb_cron_on   = ($rb_cron_line !== '');
$rb_cron_min  = '15'; $rb_cron_hour = '2';
if ($rb_cron_on && preg_match('/^\s*(\S+)\s+(\S+)\s/', $rb_cron_line, $m)) { $rb_cron_min = $m[1]; $rb_cron_hour = $m[2]; }

/* accounts that still exist — decides which restore modes are offered */
$rb_live = array();
$rs = $db->query('SELECT user FROM accounts');
while ($rs && ($r = $rs->fetchArray(SQLITE3_ASSOC))) $rb_live[$r['user']] = true;

$rb_date = (isset($_GET['date']) && valid_backup_date($_GET['date'])) ? $_GET['date'] : '';
$rb_list = $rb_configured ? remote_backup_dates($rb_cfg, isset($_GET['refresh'])) : null;
?>

<!-- The pane lives inside .card-body, whose 1.25rem left padding pushed every
     heading in past the tab strip (#backup-tabs pulls its links back to the card
     edge with margin-left:-16px). Drop the left padding here and on the two
     borderless status cards so the headings line up with the tabs and the page
     title above them. -->
<style>
	#tab-backup > .card-body { padding-left: 1.25rem; }
	#tab-backup .rb-card { border: none; padding: 0; }
	#tab-backup .rb-card > .card-body { padding-left: 0; }
	/* h-100 lets the second card's .card-footer{margin-top:auto} push its
	   Configure button to the bottom regardless of how many rows it holds */
	#tab-backup .rb-card { height: 100%; }
	#tab-backup .rb-card > .card-footer { padding-left: 0; background: transparent; }
</style>

<?php if ($rb_srv_status['found']): ?>
<!-- ─── whole-server restore progress ──────────────────────────────────────
     Rendered by JS from one JSON blob so the polling refresh and the first
     paint cannot drift apart. -->
<div class="card mb-3" id="rb-srv-progress" data-state="<?=h(json_encode($rb_srv_status));?>">
	<div class="card-body"><div class="text-muted">Loading restore status…</div></div>
</div>
<?php endif; ?>

<!-- ─── the two status cards ───────────────────────────────────────────── -->
<?php if ($rb_date === '') { ?>
	<div class="row row-cards mb-3">
	<div class="col-md-6">
		<div class="card rb-card">
			<div class="card-body">
				<h3 class="card-title">Backup server</h3>
<?php if (!$rb_configured): ?>
				<span class="badge bg-orange">not configured</span>
				<div class="text-muted mt-2">No backup server is set. Backups cannot run until one is configured.</div>
<?php else: ?>
				<!-- table + button sit side by side; the table is width:auto so the
				     button lands right next to it instead of drifting to the far
				     right edge of the card on wide screens -->
				<div class="d-flex align-items-start gap-3 flex-wrap">
				<table class="table table-sm table-borderless mb-0" style="width:auto;min-width:320px;">
					<tr>
						<td class="text-muted pe-3 ps-0" style="width:35%">Server</td>
						<td><?=h($rb_cfg['user'].'@'.$rb_cfg['host']);?><span class="text-muted">:<?=h($rb_cfg['port']);?></span></td>
					</tr>
					<tr>
						<td class="text-muted pe-3 ps-0">Connection</td>
						<td>
<?php	if ($rb_list['ok']): ?>
							<span class="badge bg-success">working</span>
<?php	else: ?>
							<span class="badge bg-red">not working</span>
<?php	endif; ?>
						</td>
					</tr>
<?php	if ($rb_list['ok']): ?>
					<tr>
						<td class="text-muted pe-3 ps-0">Backups</td>
						<td><?=count($rb_list['rows']);?></td>
					</tr>
					<tr>
						<td class="text-muted pe-3 ps-0">Free space</td>
						<td><?=h(human_kb($rb_list['free_kb']));?></td>
					</tr>
<?php	endif; ?>
				</table>
				<button type="button" class="btn" style="margin-top:-4px;" data-bs-toggle="modal" data-bs-target="#modal-rb-settings">Configure</button>
				</div>
<?php	if (!$rb_list['ok']): ?>
				<div class="text-danger mt-2" style="font-size:90%;white-space:pre-wrap;"><?=h($rb_list['error']);?></div>
<?php	endif; ?>
<?php endif; ?>
			</div>
		</div>
	</div>

	<div class="col-md-6">
		<div class="card rb-card">
			<div class="card-body">
				<h3 class="card-title">Nightly backup</h3>
<?php if ($rb_cron_on): ?>
				<div class="d-flex align-items-start gap-3 flex-wrap">
				<table class="table table-sm table-borderless mb-0" style="width:auto;min-width:320px;">
					<tr><td class="text-muted pe-3 ps-0" style="width:35%">Schedule</td><td><span class="badge bg-success">enabled</span></td></tr>
					<tr><td class="text-muted pe-3 ps-0">Runs at</td><td><?=h(sprintf('%02d:%02d', (int)$rb_cron_hour, (int)$rb_cron_min));?> every night</td></tr>
					<tr><td class="text-muted pe-3 ps-0">Keep</td>
						<td><?=((int)$rb_cfg['keep'] > 0 ? (int)$rb_cfg['keep'].' backups' : 'everything');?></td></tr>
				</table>
				<button type="button" class="btn" style="margin-top:-4px;" data-bs-toggle="modal" data-bs-target="#modal-rb-cron">Configure</button>
				</div>
<?php else: ?>
				<span class="badge bg-orange">not enabled</span>
				<div class="text-muted mt-2">Backups run only when started by hand. Enable a nightly run to keep this server backed up automatically.</div>
<?php endif; ?>
			</div>
		</div>
	</div>
</div>
<? } ?>

<?php if ($rb_configured): ?>
<?php
	/* ─── the listing ─────────────────────────────────────────────────── */
	if ($rb_date === '') {
?>
	<div class="d-flex align-items-center mb-3">
		<h3 class="mb-0">Backups on <?=h($rb_cfg['user'].'@'.$rb_cfg['host']);?></h3>
		<div class="ms-auto"><a href="/backup/?tab=remote&amp;refresh=1" class="btn btn-sm btn-outline-secondary">Refresh</a></div>
	</div>
<?php   if (!$rb_list['ok']): ?>
	<div class="alert alert-danger">
		<h4 class="alert-title">Cannot reach the backup server</h4>
		<div class="text"><pre style="white-space:pre-wrap;margin:0;"><?=h($rb_list['error']);?></pre></div>
	</div>
<?php   elseif (count($rb_list['rows']) === 0): ?>
	<div class="alert alert-info" style="background:#EEF0FF;">
		<div class="text">The backup server is reachable, but there are no backups in
		<code><?=h($rb_cfg['dest'] !== '' ? $rb_cfg['dest'] : "the ssh user's home");?></code> yet.</div>
	</div>
<?php   else: ?>
	<div class="table-responsive">
		<table class="table table-vcenter card-table table-nowrap">
			<thead>
				<tr>
					<th style="background-color:#DEF;">Date</th>
					<th style="background-color:#DEF;">Size</th>
					<th style="background-color:#DEF;">Accounts</th>
					<th style="background-color:#DEF;">Status</th>
					<th style="background-color:#DEF;"></th>
				</tr>
			</thead>
			<tbody>
<?php		foreach ($rb_list['rows'] as $row): ?>
				<tr>
					<td><a href="/backup/?tab=remote&amp;date=<?=rawurlencode($row['date']);?>"><?=h($row['date']);?></a></td>
					<td><?=h(human_kb($row['kb']));?></td>
					<td><?=(int)$row['accounts'];?></td>
					<td>
<?php			if ($row['status'] === 'ok'): ?>
						<span class="badge bg-success">completed</span>
<?php			elseif ($row['status'] === 'failed'): ?>
						<span class="badge bg-red">failed</span>
						<span class="text-muted" style="font-size:85%;"><?=h($row['failed']);?></span>
<?php			else: ?>
						<span class="badge bg-orange">incomplete</span>
						<span class="text-muted" style="font-size:85%;">no manifest — the run did not finish</span>
<?php			endif; ?>
					</td>
					<td class="text-end"><a href="/backup/?tab=remote&amp;date=<?=rawurlencode($row['date']);?>" class="btn btn-sm">Open</a></td>
				</tr>
<?php		endforeach; ?>
			</tbody>
		</table>
	</div>
<?php   endif; ?>

<?php
	} else {
		/* ─── one date ────────────────────────────────────────────────── */
		$rb_det = remote_backup_date_detail($rb_cfg, $rb_date);
?>
	<div class="d-flex align-items-center mb-3">
		<h3 class="mb-0">Backup of <?=h($rb_date);?>
			<?php if ($rb_det['ok']): ?><span class="text-muted" style="font-size:75%;">(<?=h(human_kb($rb_det['totalkb']));?>)</span><?php endif; ?>
		</h3>
		<div class="ms-auto">
<?php if ($rb_srv_allowed['ok'] && !$rb_srv_status['running']): ?>
			<button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#modal-rb-srv">Restore entire server</button>
<?php elseif (!$rb_srv_allowed['ok']): ?>
			<button type="button" class="btn btn-sm" disabled title="<?=h($rb_srv_allowed['reason']);?>">Restore entire server</button>
<?php endif; ?>
			<a href="/backup/?tab=remote" class="btn btn-sm btn-outline-secondary">&laquo; All backups</a>
		</div>
	</div>
<?php	if (!$rb_det['ok']): ?>
	<div class="alert alert-danger"><div class="text"><?=h($rb_det['error']);?></div></div>
<?php	else: ?>
	<div class="table-responsive">
		<table class="table table-vcenter card-table table-nowrap">
			<thead>
				<tr>
					<th style="background-color:#DEF;">Account</th>
					<th style="background-color:#DEF;">Domain</th>
					<th style="background-color:#DEF;">Size</th>
					<th style="background-color:#DEF;">In this backup</th>
					<th style="background-color:#DEF;"></th>
				</tr>
			</thead>
			<tbody>
<?php		foreach ($rb_det['accounts'] as $u => $a):
				$items = array();
				if (isset($a['home']['public_html'])) $items[] = 'public_html';
				foreach ($a['db'] as $dbn => $dbi) $items[] = $dbn;
				$exists = isset($rb_live[$u]);
?>
				<tr>
					<td><?=h($u);?> <?php if (!$exists): ?><span class="badge bg-orange">deleted</span><?php endif; ?></td>
					<td class="text-muted"><?=h($a['domain']);?></td>
					<td><?=h(human_kb($a['bytes'] / 1024));?></td>
					<td class="text-muted" style="white-space:normal;font-size:90%;"><?=h(implode(', ', $items) ?: 'archive only');?></td>
					<td class="text-end" style="white-space:nowrap;">
<?php			if ($rb_root): ?>
						<a href="/backup/?tab=remote&amp;date=<?=rawurlencode($rb_date);?>&amp;download=<?=rawurlencode('accounts/'.$u.'.tar.gz');?>" class="btn btn-sm">Download</a>
<?php			endif; ?>
						<button type="button" class="btn btn-sm rb-restore"
							data-user="<?=h($u);?>"
							data-exists="<?=$exists?'1':'0';?>"
							data-haveph="<?=isset($a['home']['public_html'])?'1':'0';?>"
							data-dbs="<?=h(implode(',', array_keys($a['db'])));?>">Restore</button>
					</td>
				</tr>
<?php		endforeach; ?>
<?php		foreach ($rb_det['system'] as $sf): ?>
				<tr>
					<td colspan="2" class="text-muted">system / <?=h($sf['name']);?></td>
					<td><?=h(human_kb($sf['bytes'] / 1024));?></td>
					<td class="text-muted" style="font-size:90%;">server-level files (restored from the command line)</td>
					<td class="text-end"><?php if ($rb_root): ?><a href="/backup/?tab=remote&amp;date=<?=rawurlencode($rb_date);?>&amp;download=<?=rawurlencode('system/'.$sf['name']);?>" class="btn btn-sm">Download</a><?php endif; ?></td>
				</tr>
<?php		endforeach; ?>
			</tbody>
		</table>
	</div>
<?php		if ($rb_det['manifest'] !== ''): ?>
	<details class="mt-3"><summary class="text-muted">MANIFEST.txt</summary>
		<pre style="white-space:pre-wrap;background:#f6f8fb;padding:10px;"><?=h($rb_det['manifest']);?></pre>
	</details>
<?php		endif; ?>

	<!-- ─── whole-server restore ─────────────────────────────────────────── -->
	<form method="post" action="/backup/?tab=remote">
	<input type="hidden" name="action" value="remote-backup-restore-server">
	<input type="hidden" name="date" value="<?=h($rb_date);?>">
	<div class="modal modal-blur fade" id="modal-rb-srv" tabindex="-1" role="dialog" aria-hidden="true">
		<div class="modal-dialog modal-lg modal-dialog-centered" role="document">
			<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title">Restore this entire server from <?=h($rb_date);?></h5>
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
				</div>
				<div class="modal-body">
					<p class="text-muted">
						For a rebuilt box: reqad installed, backup credentials set, no accounts yet.
						It runs in this order, and each step is reported in the progress panel.
					</p>
					<ol class="text-muted" style="font-size:90%;">
						<li><b>Panel database</b> — restored from the backup, so the DNS provider and its API
							token, the backup credentials and the feature config come back. The accounts rows
							are cleared first: each one is re-inserted by the per-account restore.</li>
						<li><b><?=count($rb_det['accounts']);?> account<?=count($rb_det['accounts'])===1?'':'s';?></b> — fetched and rebuilt one at a time, with the original
							uid/gid, password hash, databases, mail, SSL and cron. One that fails does not
							stop the rest; the failures are listed at the end and can be retried
							individually with the per-account Restore button.</li>
						<li><b>Email filters and Sieve</b> — the global and per-domain scripts, pipe programs,
							and <code>server-software.ini</code>.</li>
						<li><b>System files</b> — see below.</li>
					</ol>
					<label class="form-label">System files (<code>/etc</code>, cron spool, <code>/root/.ssh</code>, DNS data)</label>
					<div class="mb-2">
						<label class="form-check">
							<input class="form-check-input" type="radio" name="system_files" value="safe" checked>
							<span class="form-check-label"><b>Restore, keeping this box's identity</b> <span class="badge bg-blue-lt">recommended</span><br>
							<span class="text-muted" style="font-size:90%;">Unpacks the backup's <code>/etc</code> over this server, but keeps the files that
							belong to <i>this</i> machine: network config, <code>fstab</code>, hostname,
							<code>machine-id</code>, ssh host keys and <code>sshd_config</code>. Restoring those onto
							different hardware or a different IP is how a remote restore ends with a server that
							never comes back.</span></span>
						</label>
					</div>
					<div class="mb-2">
						<label class="form-check">
							<input class="form-check-input" type="radio" name="system_files" value="everything">
							<span class="form-check-label"><b>Restore everything, including network and host identity</b><br>
							<span class="text-muted" style="font-size:90%;">Only when rebuilding onto the same hardware and the same IP.</span></span>
						</label>
					</div>
					<div class="mb-3">
						<label class="form-check">
							<input class="form-check-input" type="radio" name="system_files" value="none">
							<span class="form-check-label"><b>Skip system files</b><br>
							<span class="text-muted" style="font-size:90%;">Accounts and panel state only. <code>/etc</code> stays as this fresh install left it.</span></span>
						</label>
					</div>
					<label class="form-check mb-3">
						<input class="form-check-input" type="checkbox" name="force_packages" value="1">
						<span class="form-check-label">Restore system files even if package versions differ
						<span class="text-muted" style="font-size:90%;">— by default the packages that own this config (nginx, exim, dovecot,
						MariaDB, the php-fpm builds, pdns) are compared against the backup first, and a
						mismatch skips step 4 rather than dropping an incompatible config onto a running
						daemon. The accounts are restored either way.</span></span>
					</label>
					<div class="alert alert-danger mb-3" style="font-size:90%;">
						<b>This rewrites the server.</b> It only starts on a server with no accounts, and
						<code>/var/lib/rpm</code> is never overwritten, but everything else listed above is
						replaced. There is no undo.
					</div>
					<label class="form-label">Type <code>RESTORE</code> to confirm</label>
					<input type="text" name="confirm" class="form-control" style="max-width:220px;" autocomplete="off" placeholder="RESTORE" required>
				</div>
				<div class="modal-footer">
					<a href="#" class="btn btn-link link-secondary" data-bs-dismiss="modal">Cancel</a>
					<button type="submit" class="btn btn-danger ms-auto">Restore the whole server</button>
				</div>
			</div>
		</div>
	</div>
	</form>

	<!-- restore: pick the account (the button above), then what to restore -->
	<form method="post" action="/backup/?tab=remote" id="rb-restore-form">
	<input type="hidden" name="action" value="remote-backup-restore">
	<input type="hidden" name="date" value="<?=h($rb_date);?>">
	<input type="hidden" name="user" id="rb-user" value="">
	<div class="modal modal-blur fade" id="modal-rb-restore" tabindex="-1" role="dialog" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered" role="document">
			<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title">Restore <span id="rb-user-label"></span> from <?=h($rb_date);?></h5>
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
				</div>
				<div class="modal-body">
					<div id="rb-opt-account" class="mb-2">
						<label class="form-check">
							<input class="form-check-input" type="radio" name="mode" value="account">
							<span class="form-check-label"><b>The entire account</b><br>
							<span class="text-muted" style="font-size:90%;">Recreates the user, homedir, databases, mail, DNS and SSL. Only possible while the account does not exist.</span></span>
						</label>
					</div>
					<div id="rb-opt-ph" class="mb-2">
						<label class="form-check">
							<input class="form-check-input" type="radio" name="mode" value="public_html">
							<span class="form-check-label"><b>public_html only</b><br>
							<span class="text-muted" style="font-size:90%;">Streamed straight from the backup server — the archive is never downloaded.</span></span>
						</label>
						<div class="ms-4 mt-1">
							<label class="form-check">
								<input class="form-check-input" type="checkbox" name="replace" value="1">
								<span class="form-check-label">Replace, don't merge
								<span class="text-muted" style="font-size:90%;">— move the current files to <code>public_html.prerestore-&lt;date&gt;</code> first, so the result is exactly the backup. Without this, files created since the backup are left in place.</span></span>
							</label>
						</div>
					</div>
					<div id="rb-opt-db" class="mb-2">
						<label class="form-check">
							<input class="form-check-input" type="radio" name="mode" value="database">
							<span class="form-check-label"><b>One database</b></span>
						</label>
						<div class="ms-4 mt-1">
							<select name="db" id="rb-db" class="form-select" style="max-width:320px;"></select>
							<label class="form-check mt-1">
								<input class="form-check-input" type="checkbox" name="drop" value="1">
								<span class="form-check-label">Drop and recreate first
								<span class="text-muted" style="font-size:90%;">— otherwise the dump is imported over the current data and tables added since the backup survive.</span></span>
							</label>
						</div>
					</div>
					<div class="alert alert-warning mt-3 mb-0" style="font-size:90%;">
						The restore runs in the background; you will get a message here when it finishes.
						A copy of the current database is saved first, and replaced files are kept, but
						<b>this overwrites live data</b>.
					</div>
				</div>
				<div class="modal-footer">
					<a href="#" class="btn btn-link link-secondary" data-bs-dismiss="modal">Cancel</a>
					<button type="submit" class="btn btn-primary ms-auto">Restore</button>
				</div>
			</div>
		</div>
	</div>
	</form>
<?php	endif; ?>
<?php
	} /* end of the date drill-down */
?>
<?php endif; /* $rb_configured */ ?>

<!-- ─── settings modal ─────────────────────────────────────────────────── -->
<form method="post" action="/backup/?tab=remote">
<input type="hidden" name="action" value="remote-backup-settings">
<div class="modal modal-blur fade" id="modal-rb-settings" tabindex="-1" role="dialog" aria-hidden="true">
	<div class="modal-dialog modal-lg modal-dialog-centered" role="document">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title">Backup server</h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body">
				<div class="text-muted mb-3" style="font-size:90%;">
					Every account is streamed to this server over ssh, so nothing large is stored here.
					Authentication is by key: the panel connects as <code>root</code>, so the key must be
					one root can read and the backup server must accept it.
				</div>
				<div class="row g-2 mb-2">
					<div class="col-md-5">
						<label class="form-label">Server (host or IP)</label>
						<input type="text" name="host" value="<?=h($rb_cfg['host']);?>" class="form-control" placeholder="10.0.0.5" required>
					</div>
					<div class="col-md-4">
						<label class="form-label">SSH user</label>
						<input type="text" name="user" value="<?=h($rb_cfg['user']);?>" class="form-control" placeholder="bkpv208" required>
					</div>
					<div class="col-md-3">
						<label class="form-label">Port</label>
						<input type="text" name="port" value="<?=h($rb_cfg['port']);?>" class="form-control" placeholder="22" required>
					</div>
				</div>
				<div class="row g-2 mb-2">
					<div class="col-md-6">
						<label class="form-label">Private key <span class="text-muted">(optional)</span></label>
						<input type="text" name="key" value="<?=h($rb_cfg['key']);?>" class="form-control" placeholder="/root/.ssh/backup">
					</div>
					<div class="col-md-6">
						<label class="form-label">Remote directory <span class="text-muted">(optional)</span></label>
						<input type="text" name="dest" value="<?=h($rb_cfg['dest']);?>" class="form-control" placeholder="the ssh user's home">
						<small class="form-hint">Dated folders are created here.</small>
					</div>
				</div>
				<div class="row g-2">
					<div class="col-md-6">
						<label class="form-label">Stream databases over</label>
						<div class="input-group">
							<input type="text" name="dbmax" value="<?=h($rb_cfg['dbmax']);?>" class="form-control">
							<span class="input-group-text">MB</span>
						</div>
						<small class="form-hint">Bigger databases are dumped straight to the backup server instead of being staged on disk.</small>
					</div>
				</div>
			</div>
			<div class="modal-footer">
				<a href="#" class="btn btn-link link-secondary" data-bs-dismiss="modal">Cancel</a>
				<button type="submit" name="test" value="1" class="btn btn-outline-secondary">Save and test</button>
				<button type="submit" class="btn btn-primary">Save</button>
			</div>
		</div>
	</div>
</div>
</form>

<!-- ─── nightly schedule modal ─────────────────────────────────────────── -->
<form method="post" action="/backup/?tab=remote">
<input type="hidden" name="action" value="remote-backup-cron">
<div class="modal modal-blur fade" id="modal-rb-cron" tabindex="-1" role="dialog" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered" role="document">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title">Nightly backup</h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body">
				<label class="form-check form-switch mb-3">
					<input class="form-check-input" type="checkbox" name="enabled" value="1" <?=$rb_cron_on?'checked':'';?>>
					<span class="form-check-label">Run a full backup every night</span>
				</label>
				<div class="row g-2">
					<div class="col-4">
						<label class="form-label">Hour</label>
						<input type="text" name="hour" value="<?=h($rb_cron_hour);?>" class="form-control">
					</div>
					<div class="col-4">
						<label class="form-label">Minute</label>
						<input type="text" name="min" value="<?=h($rb_cron_min);?>" class="form-control">
					</div>
					<div class="col-4">
						<label class="form-label">Keep</label>
						<div class="input-group">
							<input type="text" name="keep" value="<?=h($rb_cfg['keep']);?>" class="form-control">
							<span class="input-group-text">backups</span>
						</div>
					</div>
				</div>
				<small class="form-hint mt-2 d-block">
					Older backups are deleted from the backup server once there are more than this many,
					and only after a run that finished with no failures. 0 keeps everything for ever.
				</small>
<?php if ($rb_cron_on): ?>
				<div class="text-muted mt-3" style="font-size:85%;">
					Current entry in <code>/etc/crontab</code>:<br><code><?=h($rb_cron_line);?></code>
				</div>
<?php endif; ?>
			</div>
			<div class="modal-footer">
				<a href="#" class="btn btn-link link-secondary" data-bs-dismiss="modal">Cancel</a>
				<button type="submit" class="btn btn-primary ms-auto">Save schedule</button>
			</div>
		</div>
	</div>
</div>
</form>

