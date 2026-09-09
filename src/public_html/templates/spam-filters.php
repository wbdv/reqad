<?php
	include('templates/header.php');

	/* The account is chosen with a plain GET parameter rather than Bootstrap
	   tabs, so the page is server-rendered and bookmarkable - and so it does not
	   depend on the Bootstrap data-api (dropdowns are known not to be wired up
	   in this app). Same shape as the Email Filters page. */
	$account = isset($_GET['account']) ? clean($_GET['account']) : '';

	$accounts = sa_accounts();

	/* Never trust the query string to name an account: only one that actually
	   owns a mail domain can have a list, and the helper would refuse anything
	   else anyway. An unknown account is not an error, it means "all". */
	if ($account !== '' && !isset($accounts[$account]))
		$account = '';

	$show_all = ($account === '');

	/* One helper call for every account instead of one per account: the overview
	   is the common landing page and a sudo round trip each would dominate it. */
	if ($show_all) {
		$all    = sa_lists_get_all();
		$groups = array();
		foreach ($accounts as $a => $doms)
			if (isset($all[$a]) && ($all[$a]['white'] || $all[$a]['black']))
				$groups[$a] = $all[$a];
	} else {
		$groups = array($account => sa_lists_get($account));
	}

	$total = 0;
	foreach ($groups as $g)
		$total += count($g['white']) + count($g['black']);

	$sa_active = (trim((string)shell_exec('sudo systemctl is-active spamassassin 2>&1')) === 'active');

	$acct_url = function($a = '') {
		return '/spam-filters/' . ($a !== '' ? '?account=' . urlencode($a) : '');
	};

	$list_label = array('white' => 'Whitelist', 'black' => 'Blocklist');
?>
          <!-- Page title -->
          <div class="page-header d-print-none">
            <div class="row align-items-center">
              <div class="col" style="padding-left:22px;">
                <div class="page-pretitle">Email</div>
                <h2 class="page-title">Spam Filters</h2>
              </div>
              <div class="col-auto ms-auto d-print-none">
                <div class="btn-list">
                  <?php if (!$show_all) { ?>
                  <a href="#" class="btn btn-white" id="sf-bulk-btn" data-bs-toggle="modal" data-bs-target="#modal-sf-bulk">
                    Bulk&nbsp;edit
                  </a>
                  <a href="#" class="btn btn-primary" id="sf-add-btn" data-bs-toggle="modal" data-bs-target="#modal-sf-rule">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    Add&nbsp;<span class="d-none d-sm-inline">a new</span>&nbsp;entry
                  </a>
                  <?php } ?>
                </div>
              </div>
            </div>
          </div>

<?php msg_render(); ?>

<?php if (!$sa_active) { ?>
  <div class="alert alert-warning" style="max-width:900px;">
    <strong>SpamAssassin is not running.</strong> These lists are still saved, but nothing
    reads them until the <code>spamassassin</code> service is started - see
    <a href="/services/">Services</a>.
  </div>
<?php } ?>

<?php if (!$accounts) { ?>
  <p style="padding:14px;">No hosting account on this server has a mail domain yet, so there is
     nothing to filter for. Enable email on an account first.</p>
<?php } else { ?>

  <div class="mb-3" style="max-width:520px;">
    <label class="form-label" style="margin-left:12px;"><b>Hosting account:</b></label>
    <select class="form-select" id="sf-account-select" style="margin-bottom:10px;">
      <option value="" <?=($show_all?'selected':'');?>>All accounts</option>
      <?php foreach ($accounts as $a => $doms) { ?>
        <option value="<?=htmlspecialchars($a);?>" <?=($a===$account?'selected':'');?>>
          <?=htmlspecialchars($a);?> (<?=htmlspecialchars(implode(', ', $doms));?>)
        </option>
      <?php } ?>
    </select>
    <small class="form-text text-muted" style="margin-left:12px;">
      Select an account to add or remove spam filters.
    </small>
  </div>

<?php if ($show_all) { ?>
  <?php if (!$groups) { ?>
    <p style="padding:14px;">No account has a whitelist or blocklist yet. Pick one above to add
       the first entry.</p>
  <?php } else { ?>
    <div class="col-12">
      <div class="card mb-3">
        <div class="table-responsive">
          <table class="table table-vcenter card-table">
            <thead>
              <tr>
                <th style="background-color:#DEF;">Account</th>
                <th style="background-color:#DEF;">Domains</th>
                <th style="background-color:#DEF;" class="w-1">Whitelist</th>
                <th style="background-color:#DEF;" class="w-1">Blocklist</th>
                <th style="background-color:#DEF;" class="w-1"></th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($groups as $a => $g) { ?>
              <tr>
                <td><?=htmlspecialchars($a);?></td>
                <td class="small text-muted"><?=htmlspecialchars(implode(', ', $accounts[$a]));?></td>
                <td><span class="badge bg-success"><?=count($g['white']);?></span></td>
                <td><span class="badge bg-danger"><?=count($g['black']);?></span></td>
                <td><a href="<?=$acct_url($a);?>" class="btn btn-white btn-sm">Open</a></td>
              </tr>
            <?php } ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  <?php } ?>
<?php } else {
        $g = $groups[$account];
        foreach (array('white', 'black') as $kind) {
          $entries = $g[$kind];
?>
    <div class="col-12">
      <div class="card mb-3">
        <div class="card-header">
          <h3 class="card-title">
            <?=$list_label[$kind];?>
          </h3>
          <div class="ms-auto text-muted small">
            <?=$kind === 'white'
               ? 'Mail from these senders scores -100 and is never marked spam.'
               : 'Mail from these senders scores +100 and is always marked spam.';?>
          </div>
        </div>
        <?php if (!$entries) { ?>
          <div class="card-body text-muted">Nothing on this list.</div>
        <?php } else { ?>
        <div class="table-responsive">
          <table class="table table-vcenter card-table">
            <thead>
              <tr>
                <th style="background-color:#DEF;" class="w-1">#</th>
                <th style="background-color:#DEF;">Sender</th>
                <th style="background-color:#DEF;" class="w-1"></th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($entries as $n => $p) { ?>
              <tr>
                <td class="text-muted"><?=($n+1);?></td>
                <td><?=htmlspecialchars($p);?></td>
                <td>
                  <a href="#" class="btn btn-white btn-md"
                     data-bs-toggle="modal" data-bs-target="#modal-sf-delete"
                     data-list="<?=$kind;?>"
                     data-pattern="<?=htmlspecialchars($p);?>">Delete</a>
                </td>
              </tr>
            <?php } ?>
            </tbody>
          </table>
        </div>
        <?php } ?>
      </div>
    </div>
<?php   }
      }
      } /* $accounts */ ?>

        </div>
      </div>
    </div>

<?php if ($accounts) { ?>
<!-- Add one entry -->
<form method="post" action="/" id="sf-rule-form">
  <input type="hidden" name="action"  value="create-spam-rule">
  <input type="hidden" name="account" value="<?=htmlspecialchars($account);?>">
  <div class="modal modal-blur fade" id="modal-sf-rule" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Add an entry</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">List</label>
            <select class="form-select" name="list" id="sf-list">
              <option value="white">Whitelist - never spam</option>
              <option value="black">Blocklist - always spam</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Sender address</label>
            <input type="text" class="form-control" name="pattern" id="sf-pattern"
                   maxlength="255" required autocomplete="off" placeholder="someone@example.com">
            <small class="form-text text-muted">
              <code>*</code> and <code>?</code> are wildcards, so <code>*@example.com</code> covers a
              whole domain. A bare domain is read as <code>*@domain</code>.
            </small>
            <div class="invalid-feedback">Please enter a sender address.</div>
          </div>
        </div>
        <div class="modal-footer">
          <a href="#" class="btn btn-link link-secondary" data-bs-dismiss="modal">Cancel</a>
          <button id="sf-submit" class="btn btn-primary" type="submit">Add entry</button>
        </div>
      </div>
    </div>
  </div>
</form>

<!-- Bulk edit: both lists as plain text, one address per line -->
<form method="post" action="/" id="sf-bulk-form">
  <input type="hidden" name="action"  value="save-spam-rules">
  <input type="hidden" name="account" value="<?=htmlspecialchars($account);?>">
  <div class="modal modal-blur fade" id="modal-sf-bulk" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Bulk edit
            <?=htmlspecialchars($account);?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted">One address per line. Anything else already in this account's
             <code>user_prefs</code> (a score threshold, a hand-added rule) is left untouched.</p>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Whitelist</label>
              <textarea class="form-control" name="white" id="sf-white" rows="14"
                        spellcheck="false"><?=htmlspecialchars($show_all ? '' : implode("\n", $groups[$account]['white']));?></textarea>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Blocklist</label>
              <textarea class="form-control" name="black" id="sf-black" rows="14"
                        spellcheck="false"><?=htmlspecialchars($show_all ? '' : implode("\n", $groups[$account]['black']));?></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <a href="#" class="btn btn-link link-secondary" data-bs-dismiss="modal">Cancel</a>
          <button id="sf-bulk-submit" class="btn btn-primary" type="submit">Save both lists</button>
        </div>
      </div>
    </div>
  </div>
</form>

<!-- Delete one entry -->
<form method="post" action="/" id="sf-delete-form">
  <input type="hidden" name="action"  value="delete-spam-rule">
  <input type="hidden" name="account" value="<?=htmlspecialchars($account);?>">
  <input type="hidden" name="list"    id="sf-del-list"    value="">
  <input type="hidden" name="pattern" id="sf-del-pattern" value="">
  <div class="modal modal-blur fade" id="modal-sf-delete" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Delete entry</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p>Remove <strong id="sf-del-name"></strong> from the
             <span id="sf-del-list-label"></span>?</p>
        </div>
        <div class="modal-footer">
          <a href="#" class="btn btn-link link-secondary" data-bs-dismiss="modal">Cancel</a>
          <button id="sf-del-submit" class="btn btn-danger" type="submit">Delete entry</button>
        </div>
      </div>
    </div>
  </div>
</form>
<?php } ?>

<?php include('templates/footer.php'); ?>

<script>
$(function() {
  $('#sf-account-select').on('change', function() {
    var a = $(this).val();
    window.location = '/spam-filters/' + (a ? '?account=' + encodeURIComponent(a) : '');
  });

  $('#modal-sf-delete').on('show.bs.modal', function(ev) {
    var b = ev.relatedTarget;
    var l = b.getAttribute('data-list');
    $('#sf-del-list').val(l);
    $('#sf-del-pattern').val(b.getAttribute('data-pattern'));
    $('#sf-del-name').text(b.getAttribute('data-pattern'));
    $('#sf-del-list-label').text(l === 'white' ? 'whitelist' : 'blocklist');
  });

  $('#sf-rule-form').on('submit', function(e) {
    if (!$('#sf-pattern').val().trim()) {
      e.preventDefault();
      $('#sf-pattern').addClass('is-invalid');
      return;
    }
    $('#sf-submit').prop('disabled', true);
  });
  $('#sf-delete-form').on('submit', function(){ $('#sf-del-submit').prop('disabled', true); });
  $('#sf-bulk-form').on('submit',   function(){ $('#sf-bulk-submit').prop('disabled', true); });
});
</script>
</body>
</html>
