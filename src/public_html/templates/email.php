<?php
	include('templates/header.php');

	/* Overview of the mail stack. Counts are cheap; the queue is NOT — exim -bp
	   walks the whole spool — so the table below is loaded over AJAX and gated on
	   the queue depth (see the ajax-mq-* handlers and MQ_MAX_LIST). */

	$nb_mailboxes  = count(mailbox_list());
	$nb_forwarders = forwarder_count();

	$nb_autoresponders = 0;
	$res = $db->query('SELECT COUNT(*) AS n FROM autoresponders');
	if ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) $nb_autoresponders = (int)$row['n'];

	$queue_count = mq_count();

	$exim    = mail_stack_info('exim');
	$dovecot = mail_stack_info('dovecot');
	$spamd   = mail_stack_info('spamassassin');
	$fts     = dovecot_fts_info();
	$rcube   = roundcube_info();
	$sa      = spamassassin_summary();
	$clamd   = mail_stack_info('clamav');
	$clam    = clamav_summary();

	/* The cards are narrow, so the absolute ActiveEnterTimestamp does not fit
	   on one line next to its label. Show how long it has been up instead --
	   which is the thing being checked -- with the timestamp in the tooltip. */
	function mailstack_uptime($svc) {
		if (empty($svc['active']) || empty($svc['since_ts'])) return '—';
		return human_duration(time() - (int)$svc['since_ts']);
	}
?>
          <!-- Page title -->
          <div class="page-header d-print-none">
            <div class="row align-items-center">
              <div class="col" style="padding-left:22px;">
                <div class="page-pretitle">Email</div>
                <h2 class="page-title">Email Overview</h2>
              </div>
              <div class="col-auto ms-auto d-print-none">
                <div class="btn-list">
                  <a href="/email-stats/" class="btn btn-white">SMTP Statistics</a>
                  <a href="/check-email-settings/" class="btn btn-white">Check Email Settings</a>
                </div>
              </div>
            </div>
          </div>

<?php msg_render(); ?>

       <div class="row row-deck row-cards">
            <div class="col-sm-6 col-lg-3">
              <div class="card">
                <div class="card-body">
                  <div class="d-flex align-items-center">
                    <div class="subheader">Email accounts</div>
                  </div>
                  <div class="h1 mb-3"><a href="/email-accounts/" class="text-reset text-decoration-none"><?=$nb_mailboxes;?></a></div>
                </div>
              </div>
            </div>

            <div class="col-sm-6 col-lg-3">
              <div class="card">
                <div class="card-body">
                  <div class="d-flex align-items-center">
                    <div class="subheader">Forwarders</div>
                  </div>
                  <div class="h1 mb-3"><a href="/forwarders/" class="text-reset text-decoration-none"><?=$nb_forwarders;?></a></div>
                </div>
              </div>
            </div>

            <div class="col-sm-6 col-lg-3">
              <div class="card">
                <div class="card-body">
                  <div class="d-flex align-items-center">
                    <div class="subheader">Autoresponders</div>
                  </div>
                  <div class="h1 mb-3"><a href="/autoresponders/" class="text-reset text-decoration-none"><?=$nb_autoresponders;?></a></div>
                </div>
              </div>
            </div>

            <div class="col-sm-6 col-lg-3">
              <div class="card">
                <div class="card-body">
                  <div class="d-flex align-items-center">
                    <div class="subheader">Messages in queue</div>
                  </div>
                  <div class="h1 mb-3"><a href="/email/#queue" class="text-reset text-decoration-none"><?=($queue_count < 0 ? '?' : $queue_count);?></a></div>
                </div>
              </div>
            </div>
		</div>
		<br />

          <div class="page-header d-print-none">
            <div class="row align-items-center">
              <div class="col" style="padding-left:22px;">
                <h2 class="page-title">Mail Stack</h2>
              </div>
            </div>
          </div>

       <div class="row row-deck row-cards">
<?php foreach (array($exim, $dovecot) as $svc) { ?>
            <div class="col-md-6 col-xl-3">
              <div class="card">
                <div class="card-body">
                  <div class="d-flex align-items-center mb-3">
                    <h3 class="card-title m-0"><?=h($svc['label']);?></h3>
                    <span class="ms-auto">
                      <?php if ($svc['active']) { ?>
                        <span class="badge bg-success sm">running</span>
                      <?php } else { ?>
                        <span class="badge bg-error sm">stopped</span>
                      <?php } ?>
                    </span>
                  </div>
                  <!-- Label and value on one line: Tabler's .datagrid stacks the
                       title above the content, which turned three facts into six
                       lines and pushed the card past the queue table. -->
                  <dl class="row mb-0 mq-stack">
                    <dt>Version</dt>
                    <dd><?=h($svc['version']);?></dd>
                    <dt><?=($svc['which'] == 'exim' ? 'Ports' : 'Protocols');?></dt>
                    <dd><?=h($svc['ports']);?></dd>
                    <dt>Running for</dt>
                    <dd title="<?=h($svc['since']);?>"><?=h(mailstack_uptime($svc));?></dd>
                    <?php if ($svc['which'] == 'exim') {
                          /* virtual_user's transport — which side actually writes
                             to the mailbox. LMTP is the current path; the exim
                             appender is the rollback one, so flag it. */
                          $tr = isset($svc['delivery']) ? $svc['delivery'] : '';
                          if ($tr === 'dovecot_lmtp')          { $dl = 'Dovecot LMTP';    $db_ = 'bg-green-lt'; }
                          else if ($tr === 'virtual_users_trans') { $dl = 'Exim appender (legacy)'; $db_ = 'bg-yellow-lt'; }
                          else if ($tr !== '')                 { $dl = $tr;               $db_ = 'bg-yellow-lt'; }
                          else                                 { $dl = 'unknown';         $db_ = 'bg-red-lt'; }
                    ?>
                    <dt>Local delivery</dt>
                    <dd><span class="badge <?=h($db_);?>" title="<?=h($tr !== '' ? 'virtual_user transport = '.$tr : 'virtual_user router has no transport');?>"><?=h($dl);?></span></dd>
                    <?php } ?>
                    <?php if ($svc['which'] == 'dovecot') { ?>
                    <dt>Full-text search</dt>
                    <dd><span class="badge <?=h($fts['badge']);?>"><?=h($fts['label']);?></span></dd>
                    <?php } ?>
                  </dl>
                </div>
                <div class="card-footer">
                  <div class="btn-list">
                    <a href="/email-config/<?=h($svc['which']);?>/" class="btn btn-primary">
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><path d="M10.325 4.317c.426 -1.756 2.924 -1.756 3.35 0a1.724 1.724 0 0 0 2.573 1.066c1.543 -.94 3.31 .826 2.37 2.37a1.724 1.724 0 0 0 1.065 2.572c1.756 .426 1.756 2.924 0 3.35a1.724 1.724 0 0 0 -1.066 2.573c.94 1.543 -.826 3.31 -2.37 2.37a1.724 1.724 0 0 0 -2.572 1.065c-.426 1.756 -2.924 1.756 -3.35 0a1.724 1.724 0 0 0 -2.573 -1.066c-1.543 .94 -3.31 -.826 -2.37 -2.37a1.724 1.724 0 0 0 -1.065 -2.572c-1.756 -.426 -1.756 -2.924 0 -3.35a1.724 1.724 0 0 0 1.066 -2.573c-.94 -1.543 .826 -3.31 2.37 -2.37c1 .608 2.296 .07 2.572 -1.065z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                      Configure
                    </a>
                    <a href="/services/" class="btn btn-white">Services</a>
                  </div>
                </div>
              </div>
            </div>
<?php } ?>

			<!-- SpamAssassin -->
            <div class="col-md-6 col-xl-3">
              <div class="card">
                <div class="card-body">
                  <div class="d-flex align-items-center mb-3">
                    <h3 class="card-title m-0">SpamAssassin</h3>
                    <span class="ms-auto">
                      <?php if (!$sa['installed']) { ?>
                        <span class="badge bg-red-lt">not installed</span>
                      <?php } elseif ($spamd['active']) { ?>
                        <span class="badge bg-success sm" title="Port: <?=h($spamd['ports']);?>">running</span>
                      <?php } else { ?>
                        <span class="badge bg-error sm">stopped</span>
                      <?php } ?>
                    </span>
                  </div>
                  <?php if ($sa['installed']) { ?>
                  <dl class="row mb-0 mq-stack">
                    <dt>Version</dt>
                    <dd><?=h($spamd['version']);?></dd>
                    <dt>Spam threshold</dt>
                    <dd><?=h($sa['required_score']);?><?php if (!$sa['score_in_file']) { ?> <span class="text-muted">(default)</span><?php } ?></dd>
                    <dt>Running for</dt>
                    <dd title="<?=h($spamd['since']);?>"><?=h(mailstack_uptime($spamd));?></dd>
                    <dt>Rules updated</dt>
                    <dd><?=h($sa['rules_age']);?></dd>
                    <dt>Bayes</dt>
                    <dd><span class="badge <?=($sa['use_bayes'] ? 'bg-green-lt' : 'bg-yellow-lt');?>"><?=($sa['use_bayes'] ? 'Enabled' : 'Disabled');?></span></dd>
                  </dl>
                  <?php } else { ?>
                    <p class="text-muted mb-0">SpamAssassin is not installed — inbound mail is accepted without being scored.</p>
                  <?php } ?>
                </div>
                <?php if ($sa['installed']) { ?>
                <div class="card-footer">
                  <div class="btn-list">
                    <a href="/email-config/spamassassin/" class="btn btn-primary">
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><path d="M10.325 4.317c.426 -1.756 2.924 -1.756 3.35 0a1.724 1.724 0 0 0 2.573 1.066c1.543 -.94 3.31 .826 2.37 2.37a1.724 1.724 0 0 0 1.065 2.572c1.756 .426 1.756 2.924 0 3.35a1.724 1.724 0 0 0 -1.066 2.573c.94 1.543 -.826 3.31 -2.37 2.37a1.724 1.724 0 0 0 -2.572 1.065c-.426 1.756 -2.924 1.756 -3.35 0a1.724 1.724 0 0 0 -2.573 -1.066c-1.543 .94 -3.31 -.826 -2.37 -2.37a1.724 1.724 0 0 0 -1.065 -2.572c-1.756 -.426 -1.756 -2.924 0 -3.35a1.724 1.724 0 0 0 1.066 -2.573c-.94 -1.543 .826 -3.31 2.37 -2.37c1 .608 2.296 .07 2.572 -1.065z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                      Configure
                    </a>
                    <a href="/spam-filters/" class="btn btn-white">Spam Filters</a>
                  </div>
                </div>
                <?php } ?>
              </div>
            </div>

			<!-- ClamAV -->				
            <?php if (feature_enabled($ini, 'clamav')) { ?>
            <div class="col-md-6 col-xl-3">
              <div class="card">
                <div class="card-body">
                  <div class="d-flex align-items-center mb-3">
                    <h3 class="card-title m-0">ClamAV</h3>
                    <span class="ms-auto">
                      <?php if (!$clam['installed']) { ?>
                        <span class="badge bg-red-lt">not installed</span>
                      <?php } elseif ($clamd['active']) { ?>
                        <span class="badge bg-success sm">running</span>
                      <?php } else { ?>
                        <span class="badge bg-error sm">stopped</span>
                      <?php } ?>
                    </span>
                  </div>
                  <?php if ($clam['installed']) { ?>
                  <dl class="row mb-0 mq-stack">
                    <dt>Version</dt>
                    <dd><?=h($clamd['version']);?></dd>
                    <dt>Signatures</dt>
                    <dd><?=($clam['signatures'] > 0 ? number_format($clam['signatures']) : 'unknown');?></dd>
                    <dt>Updated</dt>
                    <dd><?=h($clam['db_age']);?></dd>
                    <dt>Scanning</dt>
                    <dd><?php
                      if      ($clam['exim_mode'] === 'deny') { $vbadge = 'bg-green-lt';  $vlabel = 'rejecting infected'; }
                      else if ($clam['exim_mode'] === 'tag')  { $vbadge = 'bg-blue-lt';   $vlabel = 'tagging only'; }
                      else                                    { $vbadge = 'bg-yellow-lt'; $vlabel = 'not wired into exim'; }
                    ?><span class="badge <?=$vbadge;?>"><?=$vlabel;?></span></dd>
                    <dt>Extra databases</dt>
                    <dd><?php if ($clam['third_party'] === 0) { ?><span class="badge bg-yellow-lt ms-1">official only</span><?php } else { ?><span class="badge bg-green-lt ms-1" title="<?php foreach ($clam['feeds'] as $f) echo h($f['name']).' ';?>">Yes</span><?php } ?></dd>
                  </dl>
                  <?php } else { ?>
                    <p class="text-muted mb-0">ClamAV is not installed &mdash; mail is delivered without being scanned for malware.</p>
                  <?php } ?>
                </div>
                <?php if ($clam['installed']) { ?>
                <div class="card-footer">
                  <form method="post" class="btn-list">
                    <input type="hidden" name="action" value="clamav-exim">
                    <?php if ($clam['exim_mode'] === 'disabled') { ?>
                      <button type="submit" name="mode" value="tag" class="btn btn-primary">Enable scanning</button>
                    <?php } else { ?>
                      <?php if ($clam['exim_mode'] === 'tag') { ?>
                        <button type="submit" name="mode" value="deny" class="btn btn-primary">Reject infected</button>
                      <?php } else { ?>
                        <button type="submit" name="mode" value="tag" class="btn btn-white">Tag only</button>
                      <?php } ?>
                      <button type="submit" name="mode" value="disabled" class="btn btn-white">Turn off</button>
                    <?php } ?>
                  </form>
                </div>
                <?php } ?>
              </div>
            </div>
            <?php } ?>

			<!-- Roundcube -->				
            <div class="col-md-6 col-xl-3">
              <div class="card">
                <div class="card-body">
                  <div class="d-flex align-items-center mb-3">
                    <h3 class="card-title m-0">Roundcube</h3>
                    <span class="ms-auto">
                      <?php if ($rcube['installed']) { ?>
                        <span class="badge bg-green-lt">installed</span>
                      <?php } else { ?>
                        <span class="badge bg-red-lt">not installed</span>
                      <?php } ?>
                    </span>
                  </div>
                  <?php if ($rcube['installed']) { ?>
                  <dl class="row mb-0 mq-stack">
                    <dt>Version</dt>
                    <dd><?=h($rcube['version'] !== '' ? $rcube['version'] : 'unknown');?></dd>
                    <dt style="padding-top:8px;">Plugins</dt>
                    <dd><?php if ($rcube['plugins']) { ?>
                    <div class="mt-2">
                      <?php foreach ($rcube['plugins'] as $p) { ?>
                        <?php /* a plugin listed in config.inc.php whose directory is gone
                                 stops Roundcube from starting, so flag it rather than
                                 listing it as though it were fine */ ?>
                        <span class="badge <?=($p['present'] ? 'bg-blue-lt' : 'bg-red-lt');?> me-1 mb-1"><?=h($p['name']);?><?=($p['present'] ? '' : ' — missing');?></span>
                      <?php } ?>
                    </div>
                    <?php } ?>
					</dd>
                  </dl>
                  <?php } else { ?>
                    <p class="text-muted mb-0">Roundcube is not installed at <code><?=h($rcube['path']);?></code>.</p>
                  <?php } ?>
                </div>
              </div>
            </div>
		</div>
		<br />

          <div class="page-header d-print-none" id="queue">
            <div class="row align-items-center">
              <div class="col" style="padding-left:22px;">
                <h2 class="page-title">Mail Queue</h2>
              </div>
            </div>
          </div>

<style>
/* Mail stack facts: one line each, labels aligned in their own column. */
.mq-stack { display: grid; grid-template-columns: max-content 1fr; column-gap: 12px; row-gap: 2px; }
.mq-stack dt { font-weight: 400; color: var(--tblr-secondary, #667382); white-space: nowrap; }
.mq-stack dt::after { content: ':'; }
.mq-stack dd { margin: 0; }

/* selection mode: checkbox column (hidden until active) + hidden per-row actions.
   Same shape as the file manager, so the two tables behave identically. */
.mq-sel-col { display: none; width: 1%; white-space: nowrap; text-align: center; }
#mq-browser.selecting .mq-sel-col { display: table-cell; }
#mq-browser.selecting .mq-row-actions > * { display: none; }
#mq-browser.selecting #mq-tbody tr { cursor: pointer; }
.mq-sel, #mq-selectall { cursor: pointer; }
/* Nothing in this table wraps: a wrapped "626 B" or a two-line address makes
   the rows jump around and the eye lose the column. Free-text columns get a
   width and an ellipsis instead, with the full value in the tooltip. */
#mq-browser table th, #mq-browser table td { white-space: nowrap; vertical-align: middle; }
.mq-id { font-family: var(--tblr-font-monospace, monospace); font-size: 12px; }
.mq-rcpt { max-width: 240px; overflow: hidden; text-overflow: ellipsis; }
.mq-subject { max-width: 320px; overflow: hidden; text-overflow: ellipsis; }
.mq-sender { max-width: 220px; overflow: hidden; text-overflow: ellipsis; }
#mq-log-out, #mq-view-headers, #mq-view-body {
    background: #f6f8fa; border: 1px solid #e3e6ea; border-radius: 4px;
    padding: 10px; font-size: 12px; max-height: 340px; overflow: auto;
    white-space: pre-wrap; word-break: break-word; margin: 0;
}
</style>

<div class="col-12" id="mq-browser">
  <div class="card">
    <div class="card-header d-flex flex-wrap align-items-center" style="gap:8px;">

      <!-- What you are looking at goes on the left; what you can do to it on
           the right. The two right-hand toolbars swap in place, so the filter
           and search stay put when selection mode is entered. -->
      <div class="d-flex align-items-center" style="gap:8px;">
        <select class="form-select" id="mq-filter" style="width:auto;">
          <option value="all">All messages</option>
          <option value="frozen">Frozen only</option>
        </select>
        <input type="search" class="form-control" id="mq-search" placeholder="Sender or recipient&hellip;" style="width:220px;">
      </div>

      <div class="ms-auto">
        <div class="btn-list" id="mq-tools-normal">
          <button class="btn" id="mq-select-btn">
            <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><path d="M5 12l5 5l10 -10"></path></svg>
            Select
          </button>
          <button class="btn" id="mq-refresh">Refresh</button>
          <button class="btn" id="mq-runq">Run queue</button>
          <button class="btn btn-outline-danger" id="mq-purge-frozen">Delete all frozen</button>
        </div>

        <div class="btn-list d-none" id="mq-tools-select">
          <button class="btn btn-primary" id="mq-deliver-selected" disabled>Deliver<span class="mq-count"></span></button>
          <button class="btn" id="mq-freeze-selected" disabled>Freeze<span class="mq-count"></span></button>
          <button class="btn" id="mq-thaw-selected" disabled>Thaw<span class="mq-count"></span></button>
          <button class="btn btn-outline-danger" id="mq-delete-selected" disabled>Delete<span class="mq-count"></span></button>
          <button class="btn" id="mq-cancel-select">Cancel selection</button>
        </div>
      </div>
    </div>

    <div id="mq-notice" class="d-none"></div>

    <div class="table-responsive">
      <table class="table table-vcenter card-table table-hover mb-0">
        <thead>
          <tr>
            <th style="background-color:#DEF;" class="mq-sel-col"><input type="checkbox" class="form-check-input m-0" id="mq-selectall" title="Select all"></th>
            <th style="background-color:#DEF;">Message ID</th>
            <th style="background-color:#DEF;" class="w-1">Age</th>
            <th style="background-color:#DEF;" class="w-1">Size</th>
            <th style="background-color:#DEF;">Sender</th>
            <th style="background-color:#DEF;">Recipient(s)</th>
            <!-- Subject costs a spool read per row, so it is fetched for the
                 visible page only; dropped below xl, where there is no room -->
            <th style="background-color:#DEF;" class="d-none d-xl-table-cell">Subject</th>
            <th style="background-color:#DEF;" class="w-1">Status</th>
            <th style="background-color:#DEF;" class="w-1"></th>
          </tr>
        </thead>
        <tbody id="mq-tbody"></tbody>
      </table>
      <div id="mq-empty" class="text-center text-muted d-none" style="padding:28px;">The mail queue is empty.</div>
    </div>

    <div id="mq-footer" class="card-footer d-flex align-items-center"></div>
  </div>
</div>

<?php include('templates/footer.php'); ?>

<!-- View one message: headers + body -->
<div class="modal modal-blur fade" id="modal-mq-view" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Message <span id="mq-view-id" class="mq-id"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="mq-view-loading" class="text-muted">Loading&hellip;</div>
        <div id="mq-view-wrap" class="d-none">
          <p class="mb-1"><strong>Headers</strong></p>
          <pre id="mq-view-headers"></pre>
          <p class="mb-1 mt-3"><strong>Body</strong> <span class="text-muted" id="mq-view-bodynote"></span></p>
          <pre id="mq-view-body"></pre>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-white" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Delivery / bulk-action log -->
<div class="modal modal-blur fade" id="modal-mq-log" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="mq-log-title">Delivery log</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="mq-log-progress" class="mb-2 text-muted"></div>
        <pre id="mq-log-out"></pre>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-white" data-bs-dismiss="modal" id="mq-log-close">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Confirm a destructive queue operation -->
<div class="modal modal-blur fade" id="modal-mq-confirm" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-sm modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-status bg-danger"></div>
      <div class="modal-body text-center py-4">
        <h3 id="mq-confirm-title">Are you sure?</h3>
        <div class="text-muted" id="mq-confirm-text"></div>
      </div>
      <div class="modal-footer">
        <div class="w-100"><div class="row">
          <div class="col"><a href="#" class="btn btn-white w-100" data-bs-dismiss="modal">Cancel</a></div>
          <div class="col"><button class="btn btn-danger w-100" type="button" id="mq-confirm-ok">Confirm</button></div>
        </div></div>
      </div>
    </div>
  </div>
</div>

<script>
jQuery(function ($) {
	'use strict';

	/* footer.php leaves a <div> unclosed, so anything included after it nests
	   inside the hidden reboot modal and never renders. Move the modals out. */
	$('#modal-mq-view, #modal-mq-log, #modal-mq-confirm').appendTo(document.body);

	var mq = {
		selectMode: false,
		filter: 'all',
		q: '',
		start: 1,
		items: <?=MQ_PAGE_ITEMS;?>,
		total: 0,
		loading: false
	};

	var svgPrev = '<svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><polyline points="15 6 9 12 15 18"></polyline></svg>';
	var svgNext = '<svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><polyline points="9 6 15 12 9 18"></polyline></svg>';

	// Longest subject rendered in a row. The CSS ellipsis already handles the
	// visual overflow; this keeps a pathological 900-character subject from
	// bloating the DOM and the title tooltip.
	var SUBJECT_MAX = 80;

	// Sender addresses are truncated much harder — the column only needs to be
	// wide enough to recognise the account; the full address is in the tooltip.
	var SENDER_MAX = 25;

	function esc(s) { return $('<span>').text(s === null || s === undefined ? '' : s).html(); }
	function trunc(s, n) { s = String(s); return s.length > n ? s.slice(0, n - 1) + '\u2026' : s; }

	// POST an ajax-mq-* action. done(res) on success; on {error} or transport
	// failure, fail(msg) runs (defaults to the notice bar).
	function mqPost(action, data, done, fail) {
		data = data || {};
		data.action = action;
		$.post('/?ajax=1', data, null, 'json')
			.done(function (res) {
				if (!res || res.error) { (fail || notice)(res && res.error ? res.error : 'Request failed.'); return; }
				if (done) done(res);
			})
			.fail(function () { (fail || notice)('Request failed. Please try again.'); });
	}

	function notice(msg, cls) {
		$('#mq-notice')
			.removeClass('d-none')
			.html('<div class="alert alert-' + (cls || 'danger') + ' m-3">' + esc(msg) + '</div>');
	}
	function clearNotice() { $('#mq-notice').addClass('d-none').empty(); }

	// exim -bp prints a bare byte count for small messages but an abbreviated
	// size for larger ones ("30K", "2M"). parseInt on "30K" yields 30, which
	// would render a 30 KB message as "30 B" -- so pass the suffixed form
	// through instead of re-deriving it.
	function humanSize(size) {
		var s = String(size == null ? '' : size).trim();
		if (/^\d+$/.test(s)) {
			var n = parseInt(s, 10);
			if (n < 1024) return n + ' B';
			if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
			return (n / 1048576).toFixed(1) + ' MB';
		}
		var m = s.match(/^(\d+(?:\.\d+)?)([KMGT])$/i);
		if (m) return m[1] + ' ' + m[2].toUpperCase() + 'B';
		return esc(s);
	}

	/* ---- rendering -------------------------------------------------------- */

	function paintRows(rows) {
		var tb = $('#mq-tbody').empty();
		$('#mq-empty').toggleClass('d-none', rows.length > 0);

		rows.forEach(function (r) {
			var rcpt = (r.recipients || []).join(', ');
			var actions =
				'<button class="btn btn-white btn-sm mq-a-view" title="View">View</button> ' +
				(r.frozen
					? '<button class="btn btn-white btn-sm mq-a-thaw" title="Thaw">Thaw</button> '
					: '<button class="btn btn-white btn-sm mq-a-deliver" title="Deliver now">Deliver</button> ' +
					  '<button class="btn btn-white btn-sm mq-a-freeze" title="Freeze">Freeze</button> ') +
				'<button class="btn btn-white btn-sm text-danger mq-a-delete" title="Delete">Delete</button>';

			$('<tr>')
				.data('row', r)
				.html(
					'<td class="mq-sel-col"><input type="checkbox" class="form-check-input m-0 mq-sel"></td>' +
					'<td class="mq-id">' + esc(r.id) + '</td>' +
					'<td class="text-muted">' + esc(r.age) + '</td>' +
					'<td class="text-muted">' + humanSize(r.size) + '</td>' +
					'<td class="mq-sender" title="' + esc(r.sender || '') + '">' +
						(r.sender ? esc(trunc(r.sender, SENDER_MAX)) : '<span class="text-muted">&lt;&gt; (bounce)</span>') + '</td>' +
					'<td class="mq-rcpt" title="' + esc(rcpt) + '">' + esc(rcpt) + '</td>' +
					'<td class="mq-subject d-none d-xl-table-cell" title="' + esc(r.subject || '') + '">' +
						(r.subject ? esc(trunc(r.subject, SUBJECT_MAX)) : '<span class="text-muted">(none)</span>') + '</td>' +
					'<td>' + (r.frozen ? '<span class="badge bg-blue-lt">Frozen</span>' : '<span class="badge bg-yellow-lt">Queued</span>') + '</td>' +
					'<td class="mq-row-actions text-end text-nowrap">' + actions + '</td>'
				)
				.appendTo(tb);
		});

		if (mq.selectMode) updateSelCount();
	}

	function paintFooter() {
		var total = mq.total, start = mq.start, items = mq.items;
		if (total === 0) { $('#mq-footer').html('<p class="m-0 text-muted">Nothing in the queue.</p>'); return; }

		var html = '<p class="m-0 text-muted"><span class="d-none d-xl-inline">Showing </span>' +
			start + ' to ' + Math.min(start + items - 1, total) + ' of ' + total + ' message' + (total !== 1 ? 's' : '') + '</p>';

		if (total > items) {
			var pages = Math.ceil(total / items);
			var cur = Math.floor((start - 1) / items) + 1;
			// A 5000-message queue is 100 pages; render a window, not all of them.
			var from = Math.max(1, cur - 3), to = Math.min(pages, cur + 3);
			html += '<ul class="pagination m-0 ms-auto">';
			html += '<li class="page-item' + (start === 1 ? ' disabled' : '') + '"><a class="page-link" href="#" data-start="' + Math.max(1, start - items) + '">' + svgPrev + ' prev</a></li>';
			if (from > 1) html += '<li class="page-item"><a class="page-link" href="#" data-start="1">1</a></li>' + (from > 2 ? '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>' : '');
			for (var p = from; p <= to; p++)
				html += '<li class="page-item' + (p === cur ? ' active' : '') + '"><a class="page-link" href="#" data-start="' + ((p - 1) * items + 1) + '">' + p + '</a></li>';
			if (to < pages) html += (to < pages - 1 ? '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>' : '') + '<li class="page-item"><a class="page-link" href="#" data-start="' + ((pages - 1) * items + 1) + '">' + pages + '</a></li>';
			html += '<li class="page-item' + ((start + items) > total ? ' disabled' : '') + '"><a class="page-link" href="#" data-start="' + (start + items) + '">next ' + svgNext + '</a></li>';
			html += '</ul>';
		}
		$('#mq-footer').html(html);
	}

	/* ---- loading ---------------------------------------------------------- */

	function mqLoad(start) {
		if (mq.loading) return;
		mq.loading = true;
		mq.start = start || 1;
		exitSelect();
		clearNotice();

		mqPost('ajax-mq-list', { filter: mq.filter, q: mq.q, start: mq.start }, function (res) {
			mq.loading = false;
			mq.total = res.total;
			mq.start = res.start;
			mq.items = res.items;
			paintRows(res.rows || []);
			paintFooter();
		}, function (msg) {
			mq.loading = false;
			paintRows([]);
			$('#mq-empty').addClass('d-none');
			$('#mq-footer').empty();
			if (msg === 'toobig') tooBig();
			else notice(msg);
		});
	}

	// The queue is past MQ_MAX_LIST and unfiltered: listing it would hang the
	// page, so offer the tools that still work instead.
	function tooBig() {
		mqPost('ajax-mq-count', {}, function (c) {
			$('#mq-notice').removeClass('d-none').html(
				'<div class="alert alert-warning m-3">' +
				'<h4 class="alert-title">' + esc(c.total) + ' messages in the queue</h4>' +
				'<p class="mb-0">That is too many to list (the limit is ' + esc(c.max) + '). ' +
				'Search by sender or recipient, or switch to <strong>Frozen only</strong>, to narrow it down. ' +
				'<strong>Run queue</strong> and <strong>Delete all frozen</strong> still work.</p></div>'
			);
		});
	}

	/* ---- multi-select ----------------------------------------------------- */

	function enterSelect() {
		mq.selectMode = true;
		$('#mq-browser').addClass('selecting');
		$('#mq-tools-normal').addClass('d-none');
		$('#mq-tools-select').removeClass('d-none');
		$('#mq-selectall').prop('checked', false).prop('indeterminate', false);
		updateSelCount();
	}
	function exitSelect() {
		mq.selectMode = false;
		$('#mq-browser').removeClass('selecting');
		$('#mq-tools-select').addClass('d-none');
		$('#mq-tools-normal').removeClass('d-none');
		$('#mq-tbody .mq-sel').prop('checked', false);
	}
	function selectedRows() {
		var out = [];
		$('#mq-tbody .mq-sel:checked').each(function () { out.push($(this).closest('tr').data('row')); });
		return out;
	}
	function selectedIds() { return selectedRows().map(function (r) { return r.id; }); }

	function updateSelCount() {
		var n = $('#mq-tbody .mq-sel:checked').length, total = $('#mq-tbody .mq-sel').length;
		$('#mq-deliver-selected, #mq-delete-selected, #mq-freeze-selected, #mq-thaw-selected').prop('disabled', n === 0);
		// selection never spans pages, so say so rather than implying it does
		$('.mq-count').text(n ? ' (' + n + ' on this page)' : '');
		$('#mq-selectall').prop('checked', total > 0 && n === total).prop('indeterminate', n > 0 && n < total);
	}

	$('#mq-select-btn').on('click', enterSelect);
	$('#mq-cancel-select').on('click', exitSelect);
	$('#mq-selectall').on('change', function () { $('#mq-tbody .mq-sel').prop('checked', this.checked); updateSelCount(); });
	$('#mq-tbody').on('change', '.mq-sel', updateSelCount);
	$('#mq-tbody').on('click', 'tr', function (e) {
		if (!mq.selectMode || $(e.target).is('.mq-sel') || $(e.target).is('button')) return;
		var cb = $(this).find('.mq-sel');
		cb.prop('checked', !cb.prop('checked'));
		updateSelCount();
	});

	/* ---- confirm dialog --------------------------------------------------- */

	var confirmFn = null;
	function showConfirm(title, text, fn) {
		$('#mq-confirm-title').text(title);
		$('#mq-confirm-text').text(text);
		confirmFn = fn;
		bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-mq-confirm')).show();
	}
	$('#mq-confirm-ok').on('click', function () {
		bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-mq-confirm')).hide();
		if (confirmFn) { var f = confirmFn; confirmFn = null; f(); }
	});

	/* ---- queue actions ---------------------------------------------------- */

	var VERB_LABEL = { deliver: 'Delivering', remove: 'Deleting', freeze: 'Freezing', thaw: 'Thawing' };

	function openLog(title) {
		$('#mq-log-title').text(title);
		$('#mq-log-out').text('');
		$('#mq-log-progress').text('');
		bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-mq-log')).show();
	}
	function appendLog(s) {
		var $o = $('#mq-log-out');
		$o.text($o.text() + s);
		$o.scrollTop($o[0].scrollHeight);
	}

	/* Run a verb over ids one at a time, appending each message's exim output as
	   it arrives. Serial on purpose: a bulk `exim -M` fired all at once would
	   stampede the mailer, and the log would interleave into nonsense. */
	function runVerb(verb, ids, showLog) {
		if (!ids.length) return;
		if (showLog) openLog(VERB_LABEL[verb] + ' ' + ids.length + ' message' + (ids.length !== 1 ? 's' : ''));

		var i = 0, failed = 0;
		(function next() {
			if (i >= ids.length) {
				if (showLog) {
					$('#mq-log-progress').text(failed
						? (ids.length - failed) + ' succeeded, ' + failed + ' failed.'
						: 'Done — ' + ids.length + ' message' + (ids.length !== 1 ? 's' : '') + ' processed.');
				}
				mqLoad(mq.start);
				return;
			}
			var id = ids[i++];
			if (showLog) $('#mq-log-progress').text(VERB_LABEL[verb] + ' ' + i + ' of ' + ids.length + '…');

			mqPost('ajax-mq-action', { verb: verb, ids: [id] }, function (res) {
				var r = (res.results && res.results[0]) || {};
				if (!r.ok) failed++;
				if (showLog) appendLog('=== ' + id + (r.ok ? '' : '  [failed]') + ' ===\n' + (r.log || '(no output)') + '\n\n');
				next();
			}, function (msg) {
				failed++;
				if (showLog) appendLog('=== ' + id + '  [failed] ===\n' + msg + '\n\n');
				next();
			});
		})();
	}

	// per-row buttons
	$('#mq-tbody').on('click', '.mq-a-view', function (e) {
		e.stopPropagation();
		var r = $(this).closest('tr').data('row');
		$('#mq-view-id').text(r.id);
		$('#mq-view-wrap').addClass('d-none');
		$('#mq-view-loading').removeClass('d-none').text('Loading…');
		bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-mq-view')).show();
		mqPost('ajax-mq-view', { id: r.id }, function (res) {
			$('#mq-view-loading').addClass('d-none');
			$('#mq-view-wrap').removeClass('d-none');
			$('#mq-view-headers').text(res.headers || '(none)');
			$('#mq-view-body').text(res.body || '(empty)');
			// the helper caps the body at 300 lines / 64 KB -- say so rather
			// than letting a truncated message look like the whole thing
			$('#mq-view-bodynote').text(res.truncated ? '— showing the first ' + res.lines + ' lines' : '');
		}, function (msg) {
			$('#mq-view-loading').text(msg);
		});
	});
	$('#mq-tbody').on('click', '.mq-a-deliver', function (e) {
		e.stopPropagation();
		runVerb('deliver', [$(this).closest('tr').data('row').id], true);
	});
	$('#mq-tbody').on('click', '.mq-a-freeze', function (e) {
		e.stopPropagation();
		runVerb('freeze', [$(this).closest('tr').data('row').id], false);
	});
	$('#mq-tbody').on('click', '.mq-a-thaw', function (e) {
		e.stopPropagation();
		runVerb('thaw', [$(this).closest('tr').data('row').id], false);
	});
	$('#mq-tbody').on('click', '.mq-a-delete', function (e) {
		e.stopPropagation();
		var id = $(this).closest('tr').data('row').id;
		showConfirm('Delete message', 'Permanently remove ' + id + ' from the queue? This cannot be undone.', function () {
			runVerb('remove', [id], false);
		});
	});

	// bulk buttons
	$('#mq-deliver-selected').on('click', function () { runVerb('deliver', selectedIds(), true); });
	$('#mq-freeze-selected').on('click', function () { runVerb('freeze', selectedIds(), false); });
	$('#mq-thaw-selected').on('click', function () { runVerb('thaw', selectedIds(), false); });
	$('#mq-delete-selected').on('click', function () {
		var ids = selectedIds();
		if (!ids.length) return;
		showConfirm('Delete ' + ids.length + ' message(s)',
			'Permanently remove ' + ids.length + ' selected message(s) from the queue? This cannot be undone.',
			function () { runVerb('remove', ids, false); });
	});

	// whole-queue buttons: these stay usable when the queue is too large to list
	$('#mq-runq').on('click', function () {
		var $b = $(this).prop('disabled', true);
		mqPost('ajax-mq-runq', {}, function (res) {
			$b.prop('disabled', false);
			openLog('Queue run');
			appendLog(res.log || 'Queue run started.');
			mqLoad(1);
		}, function (msg) { $b.prop('disabled', false); notice(msg); });
	});
	$('#mq-purge-frozen').on('click', function () {
		showConfirm('Delete all frozen messages',
			'Permanently remove every frozen message from the queue. This cannot be undone.',
			function () {
				mqPost('ajax-mq-purge-frozen', {}, function (res) {
					notice('Removed ' + res.removed + ' frozen message(s).', 'success');
					mqLoad(1);
				});
			});
	});

	/* ---- filter / search / paging ----------------------------------------- */

	$('#mq-footer').on('click', '.page-link', function (e) {
		e.preventDefault();
		if ($(this).closest('.page-item').hasClass('disabled')) return;
		mqLoad(parseInt($(this).data('start'), 10));
	});
	$('#mq-filter').on('change', function () { mq.filter = $(this).val(); mqLoad(1); });

	var searchTimer = null;
	$('#mq-search').on('input', function () {
		var v = $(this).val();
		clearTimeout(searchTimer);
		searchTimer = setTimeout(function () { mq.q = v.trim(); mqLoad(1); }, 350);
	});
	$('#mq-refresh').on('click', function () { mqLoad(mq.start); });

	/* ---- boot ------------------------------------------------------------- */

	// Ask for the depth first: on a runaway queue this is the only cheap answer,
	// and it decides whether a listing is even attempted.
	mqPost('ajax-mq-count', {}, function (c) {
		mq.items = c.items;
		if (c.total > c.max) { tooBig(); $('#mq-footer').html('<p class="m-0 text-muted">' + esc(c.total) + ' messages queued.</p>'); return; }
		mqLoad(1);
	}, function (msg) { notice(msg); });
});
</script>
