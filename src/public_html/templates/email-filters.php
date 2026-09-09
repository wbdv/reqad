<?php
	include('templates/header.php');

	/* Scope is chosen with plain GET links rather than Bootstrap tabs, so the
	   page is server-rendered and bookmarkable, and so it does not depend on the
	   Bootstrap data-api (dropdowns are known not to be wired up in this app). */
	$scope  = isset($_GET['scope'])  ? clean($_GET['scope'])  : 'global';
	$target = isset($_GET['target']) ? clean($_GET['target']) : '';
	if (!in_array($scope, array('global', 'domain', 'account'), true))
		$scope = 'global';

	$output  = shell_exec('sudo ls -1 /etc/exim/domains/ 2>/dev/null | sort');
	$domains = array_filter(explode("\n", trim((string)$output)));
	$mailboxes = mailbox_list();

	if ($scope === 'global')
		$target = '';

	/* Validate the target against what actually exists — never trust the query
	   string to name a file or a mailbox. An empty or unknown target is not an
	   error: it means "every domain" / "every mailbox", which is the default. */
	if ($scope === 'domain' && $target !== '' && !in_array($target, $domains, true))
		$target = '';
	if ($scope === 'account' && $target !== '' && !in_array($target, $mailboxes, true))
		$target = '';

	$is_admin_tier = ($scope !== 'account');
	$show_all      = ($scope !== 'global' && $target === '');
	/* Programs a "pipe to a program" action may name. Root installs them into
	   EF_PIPE_BIN_DIR; the panel only ever lists what is there. */
	$pipe_programs = ef_pipe_programs();

	/* Folders for the "File into folder" picker. Only one mailbox has a folder
	   list worth showing: a global or domain rule files into every mailbox it
	   touches, and the "all mailboxes" view has one rule per mailbox, so both
	   keep the plain free-text field. */
	$folders = (!$is_admin_tier && $target !== '') ? sieve_user_folders($target) : array();

	/* One group per target. A single-target view has exactly one group; the
	   default "all" view has one per domain / mailbox that actually has rules. */
	$groups     = array();
	$unparsed   = array();      /* mailboxes we cannot show as a list of rules */
	$raw_source = '';
	$parse_ok   = true;

	if ($is_admin_tier) {
		$sql = 'SELECT * FROM email_filters WHERE scope = :s'
		     . ($show_all ? '' : ' AND target = :t')
		     . ' ORDER BY target ASC, priority ASC, id ASC';
		$stmt = $db->prepare($sql);
		$stmt->bindValue(':s', $scope, SQLITE3_TEXT);
		if (!$show_all)
			$stmt->bindValue(':t', $target, SQLITE3_TEXT);
		$res = $stmt->execute();
		while ($row = $res->fetchArray(SQLITE3_ASSOC))
			$groups[$row['target']][] = $row;
		if (!$show_all && !isset($groups[$target]))
			$groups[$target] = array();
	} else {
		/* One helper call for every mailbox, instead of one per mailbox: the
		   per-mailbox doveadm round trip is ~60ms and dominated this page. */
		$scripts = $show_all ? sieve_user_get_all() : array();
		$boxes   = $show_all ? array_keys($scripts)
		                     : ($target !== '' ? array($target) : array());
		foreach ($boxes as $mb) {
			$src    = $show_all ? $scripts[$mb] : sieve_user_get($mb);
			$parsed = sieve_parse_script($src, false);
			if ($parsed === false) {   /* hand-written, or uses what we cannot model */
				$unparsed[] = $mb;
				if (!$show_all) {
					$parse_ok   = false;
					$raw_source = $src;
				}
				continue;
			}
			foreach ($parsed as $i => $r)
				$parsed[$i]['id'] = $i;   /* account rules are addressed by index */
			if ($parsed || !$show_all)
				$groups[$mb] = $parsed;
		}
	}

	$total = 0;
	foreach ($groups as $g)
		$total += count($g);

	$scope_url = function($sc, $tg = '') {
		return '/email-filters/?scope=' . urlencode($sc) . ($tg !== '' ? '&target=' . urlencode($tg) : '');
	};

	$tier_label = array(
		'global'  => 'every mailbox on this server',
		'domain'  => 'every mailbox in this domain',
		'account' => 'this mailbox only',
	);

	/* Human summary of a rule's conditions/actions for the table. */
	$describe = function($json, $kind) {
		$a = json_decode($json, true);
		if (!is_array($a) || !$a)
			return '<span class="text-muted">—</span>';
		$out = array();
		foreach ($a as $x) {
			if ($kind === 'cond') {
				$op = str_replace('not-', 'not ', isset($x['op']) ? $x['op'] : '');
				$out[] = htmlspecialchars($x['field'] . ' ' . $op . ' ' . $x['value']);
			} else {
				$t = isset($x['type']) ? $x['type'] : '';
				$v = isset($x['value']) ? $x['value'] : '';
				/* The old shortcut actions are literally addflag with one flag. */
				if ($t === 'seen')    { $t = 'addflag'; $v = '\\Seen'; }
				if ($t === 'flagged') { $t = 'addflag'; $v = '\\Flagged'; }
				if ($t === 'addflag' || $t === 'setflag' || $t === 'removeflag') {
					/* Show flags by the name the editor uses, not the wire form. */
					$names = array('\\Seen' => 'Read', '\\Answered' => 'Answered',
					               '\\Flagged' => 'Flagged', '\\Deleted' => 'Deleted',
					               '\\Draft' => 'Draft');
					$list = array();
					foreach (preg_split('/\s+/', trim($v)) as $f) {
						if ($f === '') continue;
						$list[] = isset($names[$f]) ? $names[$f] : $f;
					}
					$verb = array('addflag' => 'add flags', 'setflag' => 'set flags',
					              'removeflag' => 'remove flags');
					$out[] = htmlspecialchars($verb[$t] . ($list ? ': ' . implode(', ', $list) : ''));
					continue;
				}
				$map = array('fileinto' => 'file into', 'pipe' => 'pipe to');
				$t = isset($map[$t]) ? $map[$t] : $t;
				$out[] = htmlspecialchars($t . ($v !== '' ? ' "' . $v . '"' : ''));
			}
		}
		return implode('<br>', $out);
	};
?>
          <!-- Page title -->
          <div class="page-header d-print-none">
            <div class="row align-items-center">
              <div class="col" style="padding-left:22px;">
                <div class="page-pretitle">Email</div>
                <h2 class="page-title">Email Filters</h2>
              </div>
              <div class="col-auto ms-auto d-print-none">
                <div class="btn-list">
                  <?php if ($scope === 'global' || $target !== '') { ?>
                  <a href="#" class="btn btn-primary" id="ef-add-btn" data-bs-toggle="modal" data-bs-target="#modal-ef-rule">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    Add&nbsp;<span class="d-none d-sm-inline">a new</span>&nbsp;filter
                  </a>
                  <?php } ?>
                </div>
              </div>
            </div>
          </div>

<?php msg_render(); ?>

  <div class="btn-list mb-3">
    <a href="<?=$scope_url('global');?>"  class="btn <?=$scope==='global'?'btn-primary':'btn-white';?>">Server-wide</a>
    <a href="<?=$scope_url('domain');?>"  class="btn <?=$scope==='domain'?'btn-primary':'btn-white';?>">Per domain</a>
    <a href="<?=$scope_url('account');?>" class="btn <?=$scope==='account'?'btn-primary':'btn-white';?>">Per mailbox</a>
  </div><br />

<?php if ($scope === 'domain') { ?>
  <div class="mb-3" style="max-width:420px;">
    <label class="form-label" style="margin-left:12px;"><b>Domain:</b></label>
    <select class="form-select" id="ef-target-select">
      <option value="" <?=($target===''?'selected':'');?>>All domains</option>
      <?php foreach ($domains as $d) { ?>
        <option value="<?=htmlspecialchars($d);?>" <?=($d===$target?'selected':'');?>><?=htmlspecialchars($d);?></option>
      <?php } ?>
    </select>
  </div>
<?php } else if ($scope === 'account') { ?>
  <div class="mb-3" style="max-width:420px;">
    <label class="form-label" style="margin-left:12px;"><b>Mailbox:</b></label>
    <select class="form-select" id="ef-target-select">
      <option value="" <?=($target===''?'selected':'');?>>All mailboxes</option>
      <?php foreach ($mailboxes as $m) { ?>
        <option value="<?=htmlspecialchars($m);?>" <?=($m===$target?'selected':'');?>><?=htmlspecialchars($m);?></option>
      <?php } ?>
    </select>
  </div>
<?php } ?>

<?php /* helper box — commented out on request; uncomment to bring it back
  <div class="alert <?=$is_admin_tier?'alert-warning':'alert-info';?>" style="max-width:900px;">
    <?php if ($is_admin_tier) { ?>
      These rules run <strong>before</strong> the mailbox owner's own filters and apply to
      <strong><?=$tier_label[$scope];?></strong>.
      <br>
      A rule that files, redirects, rejects or discards a message <strong>ends all further
      filtering</strong> &mdash; the per-domain and per-mailbox filters below it never run.
      Leave <em>&ldquo;Stop all further filtering&rdquo;</em> unticked and Reqad keeps the chain
      alive automatically (the message is then also delivered normally, so filing it here can
      produce a second copy).
    <?php } else { ?>
      These are the mailbox owner's own filters, stored as a Sieve script in the mailbox.
      They are the same filters shown in <a href="/webmail/" target="_blank">Webmail</a> and in
      any IMAP client that speaks ManageSieve, so a change made there appears here and vice
      versa. Reqad re-reads the script before every save.
    <?php } ?>
  </div>
*/ ?>

<?php if (!$is_admin_tier && !$parse_ok) { ?>
  <div class="alert alert-warning" style="max-width:900px;">
    <strong>This script cannot be shown as a list of rules.</strong>
    It was written by hand, or it uses Sieve features Reqad does not model. Rather than
    rewrite it and risk losing what it does, the raw source is shown below for editing.
  </div>
  <form method="post" action="/" id="ef-raw-form" style="max-width:900px;">
    <input type="hidden" name="action" value="save-email-filter-raw">
    <input type="hidden" name="scope"  value="account">
    <input type="hidden" name="target" value="<?=htmlspecialchars($target);?>">
    <div class="mb-3">
      <label class="form-label">Sieve source for <?=htmlspecialchars($target);?></label>
      <textarea class="form-control font-monospace" name="source" rows="18" spellcheck="false"><?=htmlspecialchars($raw_source);?></textarea>
      <small class="form-text text-muted">Saved only if it compiles. On a syntax error the previous script is kept.</small>
    </div>
    <button class="btn btn-primary" type="submit" id="ef-raw-submit">Save script</button>
  </form>
<?php } else if ($scope === 'domain' && !$domains) { ?>
  <p style="padding:14px;">There are no domains on this server yet.</p>
<?php } else if ($scope === 'account' && !$mailboxes) { ?>
  <p style="padding:14px;">There are no mailboxes on this server yet.</p>
<?php } else if ($total === 0) { ?>
  <p style="padding:14px;">No filters are configured for
     <strong><?=htmlspecialchars($show_all
        ? ($scope === 'domain' ? 'any domain' : 'any mailbox')
        : ($scope === 'global' ? 'the whole server' : $target));?></strong>.
     <?php if ($show_all) { ?>Pick <?=($scope === 'domain' ? 'a domain' : 'a mailbox');?>
     above to add one.<?php } ?></p>
<?php }
      if ($total > 0) {
        /* One card per target. In the single-target view there is only one, and it
           carries no header — it looks exactly like every other list page. */
        foreach ($groups as $gt => $grules) {
          if ($show_all && !$grules) continue;
?>
		<div class="col-12">
            <div class="card mb-3">
<?php if ($show_all) { ?>
              <div class="card-header">
                <h3 class="card-title"><?=htmlspecialchars($gt);?></h3>
                <div class="ms-auto">
                  <a href="<?=$scope_url($scope, $gt);?>" class="btn btn-white btn-sm">Open</a>
                </div>
              </div>
<?php } ?>
                <div class="table-responsive">
                  <table class="table table-vcenter card-table">
        <thead>
          <tr>
            <th style="background-color:#DEF;" class="w-1">#</th>
            <th style="background-color:#DEF;">Name</th>
            <th style="background-color:#DEF;">If</th>
            <th style="background-color:#DEF;">Then</th>
            <th style="background-color:#DEF;">Status</th>
            <th style="background-color:#DEF;" class="w-1"></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($grules as $n => $r) {
              $enabled = !isset($r['enabled']) || $r['enabled'];
              $match   = (isset($r['match_type']) && $r['match_type'] === 'any') ? 'any' : 'all';
        ?>
          <tr>
            <td class="text-muted"><?=($n+1);?></td>
            <td>
              <?=htmlspecialchars($r['name']);?>
              <div class="small text-muted">match <?=$match;?></div>
            </td>
            <td class="small"><?=$describe($r['conditions'], 'cond');?></td>
            <td class="small">
              <?=$describe($r['actions'], 'act');?>
              <?php if (!empty($r['stop'])) { ?>
                <div><span class="badge bg-danger" title="<?=$is_admin_tier
                    ? 'No further filtering runs for this message — not the per-domain filters, and not the mailbox owner&#39;s own.'
                    : 'No later rule in this mailbox&#39;s own script runs.';?>">stops filtering</span></div>
              <?php } ?>
            </td>
            <td>
              <?php if ($enabled) { ?><span class="badge bg-success">Enabled</span>
              <?php } else { ?><span class="badge bg-secondary">Disabled</span><?php } ?>
            </td>
            <td>
              <div class="btn-list flex-nowrap">
                <a href="#" class="btn btn-white btn-md ef-edit"
                   data-bs-toggle="modal" data-bs-target="#modal-ef-rule"
                   data-id="<?=htmlspecialchars((string)$r['id']);?>"
                   data-target="<?=htmlspecialchars((string)$gt);?>"
                   data-name="<?=htmlspecialchars($r['name']);?>"
                   data-match="<?=$match;?>"
                   data-enabled="<?=$enabled?'1':'0';?>"
                   data-stop="<?=!empty($r['stop'])?'1':'0';?>"
                   data-priority="<?=htmlspecialchars((string)(isset($r['priority'])?$r['priority']:$n));?>"
                   data-conditions="<?=htmlspecialchars($r['conditions']);?>"
                   data-actions="<?=htmlspecialchars($r['actions']);?>">Edit</a>
                <a href="#" class="btn btn-white btn-md"
                   data-bs-toggle="modal" data-bs-target="#modal-ef-delete"
                   data-id="<?=htmlspecialchars((string)$r['id']);?>"
                   data-target="<?=htmlspecialchars((string)$gt);?>"
                   data-name="<?=htmlspecialchars($r['name']);?>">Delete</a>
              </div>
            </td>
          </tr>
        <?php } ?>
        </tbody>
                  </table>
                </div>
              </div>
            </div>
<?php   }
      }

      /* Mailboxes whose script we cannot model as rules are named rather than
         silently dropped — open one and the raw source editor is shown. */
      if ($show_all && $unparsed) { ?>
		<div class="col-12">
          <div class="alert alert-warning">
            <strong>Some mailboxes have a Sieve script Reqad cannot show as a list of rules.</strong>
            They were written by hand, or use Sieve features Reqad does not model. Open one to
            edit its raw source:
            <div class="mt-2">
              <?php foreach ($unparsed as $mb) { ?>
                <a href="<?=$scope_url('account', $mb);?>" class="btn btn-white btn-sm mb-1"><?=htmlspecialchars($mb);?></a>
              <?php } ?>
            </div>
          </div>
        </div>
<?php } ?>

        </div>
      </div>
    </div>
  </div>

<!-- Rule editor (create + edit share one form) -->
<form method="post" action="/" id="ef-rule-form">
  <input type="hidden" name="action" id="ef-action" value="create-email-filter">
  <input type="hidden" name="scope"  value="<?=htmlspecialchars($scope);?>">
  <input type="hidden" name="target" id="ef-target" value="<?=htmlspecialchars($target);?>">
  <input type="hidden" name="id"     id="ef-id" value="">
  <div class="modal modal-blur fade" id="modal-ef-rule" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;" id="ef-modal-title">Add a filter</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="row">
            <div class="col-md-8 mb-3">
              <label class="form-label">Filter name</label>
              <input type="text" class="form-control" name="name" id="ef-name" maxlength="120" required autocomplete="off" placeholder="File newsletters">
              <div class="invalid-feedback">Please enter a name.</div>
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label">Match</label>
              <select class="form-select" name="match_type" id="ef-match">
                <option value="all">all conditions</option>
                <option value="any">any condition</option>
              </select>
            </div>
          </div>

          <label class="form-label">Conditions</label>
          <div id="ef-conds"></div>
          <button type="button" class="btn btn-white btn-sm mb-3" id="ef-add-cond">+ Add condition</button>
          <small class="form-text text-muted mb-3 d-block">No conditions means the rule matches every message.</small>

          <label class="form-label">Actions</label>
          <div id="ef-acts"></div>
          <button type="button" class="btn btn-white btn-sm mb-3" id="ef-add-act">+ Add action</button>
<?php if ($is_admin_tier) { ?>
          <small class="form-text text-muted mb-3" id="ef-pipe-hint" style="display:none;">
            <em>Pipe to a program</em> runs one of the programs installed in
            <code><?=EF_PIPE_BIN_DIR;?></code><?php if (!$pipe_programs) { ?> —
            that directory is empty, so put an executable there (as root) first<?php } ?>.
            A filter can only name a program from that directory, never a path.
          </small>
<?php } ?>

          <div class="row">
<?php if ($is_admin_tier) { ?>
            <div class="col-md-4 mb-3">
              <label class="form-label">Priority</label>
              <input type="number" class="form-control" name="priority" id="ef-priority" value="10" min="0" max="9999">
              <small class="form-text text-muted">Lower runs first. New filters start at 10, so a
              rule can be moved ahead of them without renumbering everything.</small>
            </div>
<?php } ?>
            <div class="col-md-12 mb-3">
              <label class="form-label">Status</label>
              <!-- A cleared checkbox posts nothing, so the hidden field carries the "0".
                   It comes first: PHP keeps the last value for a repeated name. -->
              <input type="hidden" name="enabled" value="0">
              <label class="form-check form-switch mt-2">
                <input type="checkbox" class="form-check-input" name="enabled" id="ef-enabled" value="1" checked>
                <span class="form-check-label">Enabled</span>
              </label>
              <small class="form-text text-muted"><?=$is_admin_tier
                 ? 'A disabled filter is kept here but left out of the generated script.'
                 : 'A disabled rule stays in the script, neutered as <code>if false #&hellip;</code>.';?></small>
            </div>
          </div>
          <div class="row">
<?php if ($is_admin_tier) { ?>
            <div class="col-12 mb-3">
              <label class="form-check">
                <input type="checkbox" class="form-check-input" name="stop" id="ef-stop" value="1">
                <span class="form-check-label">Stop all further filtering</span>
              </label>
              <small class="form-text text-muted">
                Also skips the per-domain and per-mailbox filters. Without this, filing or
                redirecting here still lets them run, which can leave a second copy.
              </small>
            </div>
<?php } /* Account tier: hidden on request, kept for later. Uncomment to restore.
           NOTE: an account rule's `stop;` is PRESERVED while this is hidden —
           edit_email_filter.php carries the existing value forward, so a rule
           that stops (one written in Roundcube, say) is not quietly un-stopped
           by editing something else about it.

            <div class="col-12 mb-3">
              <label class="form-check">
                <input type="checkbox" class="form-check-input" name="stop" id="ef-stop" value="1">
                <span class="form-check-label">Stop processing later rules</span>
              </label>
              <small class="form-text text-muted">
                Later rules in this mailbox's own script are skipped.
              </small>
            </div>
*/ ?>
          </div>
        </div>
        <div class="modal-footer">
          <a href="#" class="btn btn-link link-secondary" data-bs-dismiss="modal">Cancel</a>
          <button id="ef-submit" class="btn btn-primary" type="submit">Save filter</button>
        </div>
      </div>
    </div>
  </div>
</form>

<!-- Delete -->
<form method="post" action="/" id="ef-delete-form">
  <input type="hidden" name="action" value="delete-email-filter">
  <input type="hidden" name="scope"  value="<?=htmlspecialchars($scope);?>">
  <input type="hidden" name="target" id="ef-del-target" value="<?=htmlspecialchars($target);?>">
  <input type="hidden" name="id"     id="ef-del-id" value="">
  <div class="modal modal-blur fade" id="modal-ef-delete" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Delete filter</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p>Delete the filter <strong id="ef-del-name"></strong>?</p>
        </div>
        <div class="modal-footer">
          <a href="#" class="btn btn-link link-secondary" data-bs-dismiss="modal">Cancel</a>
          <button id="ef-del-submit" class="btn btn-danger" type="submit">Delete filter</button>
        </div>
      </div>
    </div>
  </div>
</form>

<?php include('templates/footer.php'); ?>

<link rel="stylesheet" href="./dist/libs/tom-select/dist/css/tom-select.bootstrap5.min.css">
<style>
/* Same overrides autoresponders.php uses, so the tag input matches the panel. */
.ts-wrapper .ts-control { background-color: #fff; border: 1px solid #c8d3e1; border-radius: 4px; padding: 4px 8px; min-height: 38px; }
/* The folder picker sits beside a .form-select, so it has to be that exact
   height — the flags input is a tag list and is free to grow. */
.ef-folder-wrap .ts-control { height: 38px; min-height: 38px; padding-top: 0; padding-bottom: 0; align-items: center; }
.ts-wrapper.focus .ts-control { border-color: #6ea8fe; box-shadow: 0 0 0 0.2rem rgba(38,143,255,.25); }
.ts-dropdown { background-color: #fff; }
.ts-dropdown .option { padding: 6px 12px; }
.ts-dropdown .option.active { background-color: #206bc4; color: #fff; }
</style>
<script src="./dist/libs/tom-select/dist/js/tom-select.complete.min.js"></script>

<script>
$(function() {
  var OPS = [
    ['contains','contains'], ['is','is'], ['begins','begins with'], ['ends','ends with'],
    ['matches','matches (wildcards)'], ['regex','matches regex'],
    ['not-contains','does not contain'], ['not-is','is not'],
    ['over','is larger than (bytes)'], ['under','is smaller than (bytes)']
  ];
  var ACTS = [
    ['fileinto','File into folder', true],
    ['redirect','Redirect to address', true],
    ['reject','Reject with message', true],
    ['discard','Discard', false],
    ['keep','Keep in Inbox', false],
    ['addflag','Add flags', true],
    ['setflag','Set flags (replace all)', true],
    ['removeflag','Remove flags', true]<?=$is_admin_tier ? ",\n    ['pipe','Pipe to a program', true]" : '';?>
  ];
  /* Pipe is admin-only: sieve_global_extensions enables vnd.dovecot.pipe for the
     global and domain tiers only, and dovecot refuses it in a mailbox's own
     script ("its use is restricted to global scripts"). */

  /* The five IMAP system flags, by the name a person would use. A Sieve flag
     list is one space-separated string, which is exactly what the tag input
     produces. Custom keywords are allowed too (create: true) so a flag set by
     some other client is never quietly dropped. */
  var FLAGS = [
    ['\\Seen',     'Read'],
    ['\\Answered', 'Answered'],
    ['\\Flagged',  'Flagged'],
    ['\\Deleted',  'Deleted'],
    ['\\Draft',    'Draft']
  ];
  var FLAG_ACTS = ['addflag','setflag','removeflag'];

  var PROGRAMS = <?=json_encode(array_values($pipe_programs));?>;

  /* Existing folders of the one mailbox this page is showing, or empty for the
     tiers where "the folder" is not a single mailbox's folder. Empty means the
     folder field stays the plain text input it has always been. */
  var FOLDERS = <?=json_encode($folders);?>;

  /* What the message is tested against, using cPanel's labels so the list reads
     the same to anyone coming from there. Every entry is something the renderer
     AND the parser can round-trip; "body" and "size" are special-cased, "to,cc"
     becomes a single Sieve header list, the rest are plain header names. */
  var FIELDS = [
    ['from','From'], ['subject','Subject'], ['to','To'],
    ['to,cc','Any Recipient'], ['cc','Cc'], ['reply-to','Reply'],
    ['body','Body'], ['size','Message size'], ['list-id','List ID'],
    ['X-Spam-Status','Spam Status'], ['X-Spam-Bar','Spam Bar'],
    ['X-Spam-Score','Spam Score'], ['X-Spam-Flag','Spam Flag']
  ];

  function condRow(c) {
    c = c || {field:'subject', op:'contains', value:''};
    var $r = $('<div class="row g-2 mb-2 ef-cond-row">');

    var $f = $('<select class="form-select" name="cond_field[]">');
    $.each(FIELDS, function(i,f){ $f.append($('<option>').attr('value',f[0]).text(f[1])); });
    /* A script written elsewhere may test a header that is not on the list; keep
       it as its own option rather than silently rewriting the rule. */
    var known = false;
    $.each(FIELDS, function(i,f){
      if (String(f[0]).toLowerCase() === String(c.field).toLowerCase()) known = f[0];
    });
    if (!known && c.field) $f.append($('<option>').attr('value',c.field).text(c.field));
    $f.val(known || c.field);

    var $o = $('<select class="form-select" name="cond_op[]">');
    $.each(OPS, function(i,o){ $o.append($('<option>').attr('value',o[0]).text(o[1])); });
    $o.val(c.op);
    var $v = $('<input type="text" class="form-control" name="cond_value[]" placeholder="value">').val(c.value);
    var $x = $('<button type="button" class="btn btn-white" title="Remove">&times;</button>').on('click', function(){ $r.remove(); });
    $r.append($('<div class="col-md-3">').append($f))
      .append($('<div class="col-md-4">').append($o))
      .append($('<div class="col-md-4">').append($v))
      .append($('<div class="col-md-1">').append($x));
    return $r;
  }

  /* The pipe explanation is only useful once a pipe action is actually chosen. */
  function pipeHint() {
    var on = $('#ef-acts select[name="act_type[]"]').filter(function(){ return this.value === 'pipe'; }).length > 0;
    /* Explicit block: <small> is inline by default, and the d-block utility is
       display:block !important, which an inline style could never turn off. */
    $('#ef-pipe-hint').css('display', on ? 'block' : 'none');
  }

  function actRow(a) {
    a = a || {type:'fileinto', value:''};
    /* Older rules (and scripts) carry the shortcut types this editor used to
       offer. They are exactly `addflag "\\Seen"` / `addflag "\\Flagged"`, so show
       them as what they are — the flag picker says it better than a second
       action ever did. Saving rewrites them as addflag. */
    if (a.type === 'seen')    a = {type:'addflag', value:'\\Seen'};
    if (a.type === 'flagged') a = {type:'addflag', value:'\\Flagged'};

    var $r = $('<div class="row g-2 mb-2 ef-act-row">');
    var $t = $('<select class="form-select" name="act_type[]">');
    $.each(ACTS, function(i,o){ $t.append($('<option>').attr('value',o[0]).text(o[1])); });
    $t.val(a.type);
    /* $v always carries the posted value, whichever control the user actually
       touched — so a row posts exactly one act_value. */
    var $v = $('<input type="text" class="form-control" name="act_value[]" placeholder="value">').val(a.value);

    /* A pipe names a program installed in the bin dir, never a path — so it is a
       picker over what is actually there, not a free-text command line. */
    var $p = $('<select class="form-select">');
    $.each(PROGRAMS, function(i,n){ $p.append($('<option>').attr('value',n).text(n)); });
    if (!PROGRAMS.length)
      $p.append($('<option>').attr('value','').text('no programs installed'));
    if (a.type === 'pipe' && a.value && PROGRAMS.indexOf(a.value) === -1)
      $p.append($('<option>').attr('value',a.value).text(a.value + ' (missing)'));
    if (a.type === 'pipe') $p.val(a.value);
    $p.on('change', function(){ $v.val($p.val()); });

    /* Folders: a combobox, not a plain dropdown — create:true keeps the one
       thing a free-text field could do that a <select> cannot, naming a folder
       that does not exist yet. That is safe because the renderer emits
       `fileinto :create`, so dovecot makes the folder on first delivery. */
    var $ow = $('<div class="ef-folder-wrap">');
    var $fo = $('<select placeholder="Folder…">');
    /* Leading empty option: without one a <select> always has a selection, so
       the field would silently read "INBOX" for a rule nobody has filled in. */
    $fo.append($('<option>').attr('value','').text(''));
    $.each(FOLDERS, function(i,f){ $fo.append($('<option>').attr('value',f).text(f)); });
    $ow.append($fo);
    var tsf = null;

    /* Flags: a tag input over the five system flags. */
    var $fw = $('<div class="ef-flag-wrap">');
    var $fl = $('<select multiple placeholder="Select flags…">');
    $.each(FLAGS, function(i,f){ $fl.append($('<option>').attr('value',f[0]).text(f[1])); });
    $fw.append($fl);
    var ts = null;

    function isFlag() { return FLAG_ACTS.indexOf($t.val()) !== -1; }
    function isFolder() { return FOLDERS.length > 0 && $t.val() === 'fileinto'; }

    function sync() {
      pipeHint();
      var needs = false;
      $.each(ACTS, function(i,o){ if (o[0] === $t.val()) needs = o[2]; });
      var pipe = ($t.val() === 'pipe'), flag = isFlag(), fold = isFolder();
      $p.toggle(pipe);
      $fw.toggle(flag);
      $ow.toggle(fold);
      $v.prop('disabled', !needs).toggle(needs && !pipe && !flag && !fold);
      if (!needs) $v.val('');
      else if (pipe) $v.val($p.val() || '');
      else if (flag) $v.val(ts ? ts.getValue().join(' ') : (a.value || ''));
      else if (fold) $v.val(tsf ? (tsf.getValue() || '') : (a.value || ''));
    }
    $t.on('change', sync);

    var $x = $('<button type="button" class="btn btn-white" title="Remove">&times;</button>')
              .on('click', function(){ $r.remove(); pipeHint(); });
    $r.append($('<div class="col-md-5">').append($t))
      .append($('<div class="col-md-6">').append($p).append($fw).append($ow).append($v))
      .append($('<div class="col-md-1">').append($x));

    /* TomSelect has to attach after the row is in the DOM, so the caller runs
       this. Custom keywords are creatable: a flag some other client set must not
       vanish just because it is not one of the five. */
    $r.data('efInit', function() {
      ts = new TomSelect($fl[0], {
        plugins: ['remove_button'],
        create: true,
        persist: false,
        onChange: function() { if (isFlag()) $v.val(ts.getValue().join(' ')); }
      });
      if (isFlag() && a.value) {
        $.each(String(a.value).split(/\s+/), function(i, f) {
          if (f === '') return;
          if (!ts.options[f]) ts.addOption({value: f, text: f});
          ts.addItem(f, true);
        });
      }
      if (FOLDERS.length) {
        tsf = new TomSelect($fo[0], {
          create: true,
          persist: false,
          maxItems: 1,
          onChange: function() { if (isFolder()) $v.val(tsf.getValue() || ''); }
        });
        /* A rule may already file into a folder that no longer exists — keep it
           as its own option rather than silently emptying the action. */
        if (a.type === 'fileinto' && a.value) {
          if (!tsf.options[a.value]) tsf.addOption({value: a.value, text: a.value});
          tsf.addItem(a.value, true);
        } else {
          tsf.clear(true);
        }
      }
      sync();
    });

    sync();
    return $r;
  }

  /* Append + wire up. Every caller goes through this, or TomSelect never binds. */
  function addAct(a) {
    var $r = actRow(a);
    $('#ef-acts').append($r);
    $r.data('efInit')();
    pipeHint();
    return $r;
  }

  var PAGE_TARGET = <?=json_encode($target);?>;

  function reset(create) {
    $('#ef-target').val(PAGE_TARGET);
    $('#ef-del-target').val(PAGE_TARGET);
    $('#ef-conds').empty();
    $('#ef-acts').empty();
    pipeHint();
    $('#ef-id').val('');
    $('#ef-name').val('').removeClass('is-invalid');
    $('#ef-match').val('all');
    $('#ef-priority').val('10');
    $('#ef-enabled').prop('checked', true);
    $('#ef-stop').prop('checked', false);
    $('#ef-action').val(create ? 'create-email-filter' : 'edit-email-filter');
    $('#ef-modal-title').text(create ? 'Add a filter' : 'Edit filter');
  }

  $('#ef-add-btn').on('click', function() {
    reset(true);
    $('#ef-conds').append(condRow());
    addAct();
  });

  $('.ef-edit').on('click', function() {
    var b = this;
    reset(false);
    $('#ef-id').val(b.getAttribute('data-id'));
    if (b.getAttribute('data-target') !== null) $('#ef-target').val(b.getAttribute('data-target'));
    $('#ef-name').val(b.getAttribute('data-name'));
    $('#ef-match').val(b.getAttribute('data-match'));
    $('#ef-priority').val(b.getAttribute('data-priority'));
    $('#ef-enabled').prop('checked', b.getAttribute('data-enabled') !== '0');
    $('#ef-stop').prop('checked', b.getAttribute('data-stop') === '1');
    var conds = [], acts = [];
    try { conds = JSON.parse(b.getAttribute('data-conditions') || '[]'); } catch(e) {}
    try { acts  = JSON.parse(b.getAttribute('data-actions')    || '[]'); } catch(e) {}
    $.each(conds, function(i,c){ $('#ef-conds').append(condRow(c)); });
    $.each(acts,  function(i,a){ addAct(a); });
    if (!acts.length) addAct();
  });

  $('#ef-add-cond').on('click', function(){ $('#ef-conds').append(condRow()); });
  $('#ef-add-act').on('click',  function(){ addAct(); });

  $('#ef-rule-form').on('submit', function(e) {
    var name = $('#ef-name').val().trim();
    if (!name) { e.preventDefault(); $('#ef-name').addClass('is-invalid'); return; }
    if ($('#ef-acts .ef-act-row').length === 0) {
      e.preventDefault();
      alert('A filter needs at least one action.');
      return;
    }
    $('#ef-submit').prop('disabled', true);
  });

  $('#modal-ef-delete').on('show.bs.modal', function(ev) {
    var b = ev.relatedTarget;
    $('#ef-del-id').val(b.getAttribute('data-id'));
    if (b.getAttribute('data-target') !== null) $('#ef-del-target').val(b.getAttribute('data-target'));
    $('#ef-del-name').text(b.getAttribute('data-name'));
  });
  $('#ef-delete-form').on('submit', function(){ $('#ef-del-submit').prop('disabled', true); });
  $('#ef-raw-form').on('submit', function(){ $('#ef-raw-submit').prop('disabled', true); });

  $('#ef-target-select').on('change', function() {
    var t = $(this).val();
    window.location = '/email-filters/?scope=<?=urlencode($scope);?>'
                    + (t ? '&target=' + encodeURIComponent(t) : '');
  });
});
</script>
</body>
</html>
