/* DNS Zones on 2087 (templates/dns.php). Two views, from #dz-data:
     list  every zone at the DNS provider: filter by zone or account, pages,
           records and problems of the zones on the page (op summary)
     zone  one zone's records: search, type filter, pages; add / change /
           remove with a live preview (op preview: what is saved, and hints
           that fix common mistakes); the zone check with fixes; import and
           export of zone files; name servers, SOA; the mail provider, SPF
           and DMARC helpers of an account's sites in it
   Every request is POST / action=ajax-dns op=... (modules/dns_admin.php);
   answers are {ok, message, warnings, error, data}. */
jQuery(function ($) {
  'use strict';
  var D = JSON.parse($('#dz-data').text() || '{}');
  $('#dz-modals .modal').appendTo(document.body);
  var IC = {
    edit: '<svg xmlns="http://www.w3.org/2000/svg" class="icon" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 20h4l10.5 -10.5a2.828 2.828 0 1 0 -4 -4l-10.5 10.5v4"/><path d="M13.5 6.5l4 4"/></svg>',
    trash: '<svg xmlns="http://www.w3.org/2000/svg" class="icon" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 7l16 0"/><path d="M10 11l0 6"/><path d="M14 11l0 6"/><path d="M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2 -2l1 -12"/><path d="M9 7v-3a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v3"/></svg>',
    lock: '<svg xmlns="http://www.w3.org/2000/svg" class="icon" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 13a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v6a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-6z"/><path d="M8 11v-4a4 4 0 1 1 8 0v4"/></svg>',
    warn: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"/><path d="M10.363 3.591l-8.106 13.534a1.914 1.914 0 0 0 1.636 2.871h16.214a1.914 1.914 0 0 0 1.636 -2.87l-8.106 -13.536a1.914 1.914 0 0 0 -3.274 0z"/><path d="M12 16h.01"/></svg>',
    err: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>',
    info: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0"/><path d="M12 9h.01"/><path d="M11 12h1v4h1"/></svg>',
    ok: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0"/><path d="M9 12l2 2l4 -4"/></svg>'
  };
  var LV = { error: ['dz-err-ic', IC.err], warn: ['dz-warn-ic', IC.warn], info: ['text-azure', IC.info] };

  function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }
  function call(op, data) {
    return $.ajax({ url: '/', method: 'POST', dataType: 'json', headers: { 'X-CSRF-Token': window.CSRF },
                    data: $.extend({ action: 'ajax-dns', op: op, zone: D.zone }, data || {}) })
      .then(function (j) { return j && typeof j === 'object' ? j : { ok: false, error: 'The panel gave no answer. Try again.' }; },
            function (x) { return $.Deferred().resolve({ ok: false, error: x.status === 401 ? 'Your session ended: sign in again.' : 'Could not reach the panel (' + (x.status || 'no answer') + '). Try again.' }).promise(); });
  }
  function toast(msg, kind) {
    if (!msg) return;
    var a = $('<div class="alert alert-dismissible" role="status"></div>').addClass(kind === 'error' ? 'alert-danger' : (kind === 'warn' ? 'alert-warning' : 'alert-success'));
    a.append($('<div>').text(msg)).append('<a class="btn-close" aria-label="Close"></a>');
    a.find('.btn-close').on('click', function () { a.remove(); });
    $('#dz-toasts').append(a);
    setTimeout(function () { a.fadeOut(300, function () { a.remove(); }); }, kind === 'error' || kind === 'warn' ? 12000 : 5000);
  }
  function said(j) { toast([j.message].concat(j.warnings || []).filter(Boolean).join(' '), (j.warnings || []).length ? 'warn' : 'ok'); }
  function modal(id) { return bootstrap.Modal.getOrCreateInstance(document.getElementById(id)); }
  function showErr(box, msg) { var e = $(box).find('.dz-err').first(); e.text(msg || '').toggle(!!msg); }
  function busy(btn, on) {
    btn = $(btn);
    if (on) { btn.data('txt', btn.html()).prop('disabled', true).prepend('<span class="spinner-border spinner-border-sm me-2"></span>'); }
    else { btn.prop('disabled', false).find('.spinner-border').remove(); }
  }
  function ttlText(s) {
    s = Number(s);
    if (s === 1) return 'Auto';
    if (s % 86400 === 0) return s / 86400 + (s === 86400 ? ' day' : ' days');
    if (s % 3600 === 0) return s / 3600 + ' h';
    if (s % 60 === 0) return s / 60 + ' min';
    return s + ' s';
  }
  /* page links: 1 … 4 5 [6] 7 8 … 20 */
  function pager(box, total, per, page, go, what) {
    box = $(box).empty();
    var pages = Math.max(1, Math.ceil(total / per));
    if (page > pages) page = pages;
    var from = total ? (page - 1) * per + 1 : 0, to = Math.min(total, page * per);
    box.append($('<p class="m-0 text-muted"></p>').text(total > per ? 'Showing ' + from + ' to ' + to + ' of ' + total + ' ' + what : 'Total: ' + total + ' ' + what));
    if (pages > 1) {
      var ul = $('<ul class="pagination m-0 ms-auto"></ul>');
      var li = function (p, label, dis, act) {
        var l = $('<li class="page-item"></li>').toggleClass('disabled', !!dis).toggleClass('active', !!act);
        l.append($('<a class="page-link" href="#"></a>').attr('data-p', p).html(label)); ul.append(l);
      };
      li(page - 1, '&lsaquo; prev', page === 1);
      var shown = {};
      [1, 2, page - 2, page - 1, page, page + 1, page + 2, pages - 1, pages].forEach(function (p) { if (p >= 1 && p <= pages) shown[p] = 1; });
      var last = 0;
      Object.keys(shown).map(Number).sort(function (a, b) { return a - b; }).forEach(function (p) {
        if (p - last > 1) li(0, '…', true);
        li(p, String(p), false, p === page); last = p;
      });
      li(page + 1, 'next &rsaquo;', page === pages);
      box.append(ul);
      ul.on('click', 'a', function (e) { e.preventDefault(); var p = Number($(this).attr('data-p')); if (p && !$(this).parent().hasClass('disabled')) go(p); });
    }
    return page;
  }
  function kindBadge(k) {
    if (!k) return '';
    var c = { Native: 'bg-muted-lt', Master: 'bg-green-lt', Primary: 'bg-green-lt', Slave: 'bg-azure-lt', Secondary: 'bg-azure-lt', Producer: 'bg-purple-lt', Consumer: 'bg-purple-lt' }[k] || 'bg-muted-lt';
    return '<span class="badge border ' + c + '">' + esc(k) + '</span>';
  }

  /* ---------- new zone / remove zone (both views) ---------- */
  $('#dz-new-zone').on('click', function (e) {
    e.preventDefault();
    var f = $('#dz-zone-form');
    f[0].reset(); showErr(f, '');
    $('#dz-zone-ip').attr('placeholder', D.server_ip || '');
    modal('dz-m-zone').show();
    setTimeout(function () { $('#dz-zone-name').trigger('focus'); }, 300);
  });
  $('#dz-zone-form').on('submit', function (e) {
    e.preventDefault();
    var f = $(this), btn = f.find('[type=submit]');
    busy(btn, true);
    call('create', { zone: $('#dz-zone-name').val(), ip: $('#dz-zone-ip').val() }).then(function (j) {
      busy(btn, false);
      if (!j.ok) { showErr(f, j.error); return; }
      try { sessionStorage.setItem('dz-msg', JSON.stringify([j.message].concat(j.warnings || []))); } catch (x) { /* shown only here */ }
      location.href = '/dns/' + encodeURIComponent(j.data.zone) + '/';
    });
  });
  var removing = '';
  function openRemove(zone, acct) {
    removing = zone;
    var f = $('#dz-remove-form');
    f[0].reset(); showErr(f, '');
    $('#dz-remove-name').text(zone);
    $('#dz-remove-acct').toggle(!!acct).text(acct ? 'This is a domain of the hosting account ' + acct + '. Its sites and mail lose their DNS records.' : '');
    f.find('[type=submit]').prop('disabled', true);
    modal('dz-m-remove').show();
  }
  $('#dz-remove-confirm').on('input', function () { $('#dz-remove-form [type=submit]').prop('disabled', $.trim($(this).val()).toLowerCase() !== removing); });
  $('#dz-remove-form').on('submit', function (e) {
    e.preventDefault();
    var f = $(this), btn = f.find('[type=submit]');
    busy(btn, true);
    call('remove', { zone: removing, confirm: $.trim($('#dz-remove-confirm').val()).toLowerCase() }).then(function (j) {
      busy(btn, false);
      if (!j.ok) { showErr(f, j.error); return; }
      try { sessionStorage.setItem('dz-msg', JSON.stringify([j.message])); } catch (x) { /* shown only here */ }
      location.href = '/dns/';
    });
  });
  try {
    var m0 = sessionStorage.getItem('dz-msg');
    if (m0) { sessionStorage.removeItem('dz-msg'); m0 = JSON.parse(m0); toast(m0.filter(Boolean).join(' '), m0.length > 1 ? 'warn' : 'ok'); }
  } catch (x) { /* nothing to show */ }

  if (D.view === 'list') listView(); else if (D.view === 'zone') zoneView();

  /* =================== the zone list =================== */
  function listView() {
    var Z = D.zones || [], per = 25, page = 1, sum = {}, loading = {};
    var params = new URLSearchParams(location.search);
    $('#dz-q').val(params.get('q') || ''); $('#dz-f').val(params.get('f') || '');
    page = Math.max(1, Number(params.get('p')) || 1);
    var pdns = D.provider === 'powerdns';
    function shown() {
      var q = $.trim($('#dz-q').val()).toLowerCase(), f = $('#dz-f').val();
      return Z.filter(function (z) {
        if (q && z.zone.indexOf(q) < 0 && (z.account || '').toLowerCase().indexOf(q) < 0 && (z.key || '').toLowerCase().indexOf(q) < 0) return false;
        if (f === 'acct' && !z.account) return false;
        if (f === 'key' && !z.key) return false;
        if (f === 'none' && (z.account || z.key)) return false;
        if (f === 'bad') { var s = sum[z.zone]; if (!s || !(s.errors || s.warnings || s.error)) return false; }
        return true;
      });
    }
    function remember() {
      var p = new URLSearchParams();
      if ($('#dz-q').val()) p.set('q', $('#dz-q').val());
      if ($('#dz-f').val()) p.set('f', $('#dz-f').val());
      if (page > 1) p.set('p', page);
      try { history.replaceState(null, '', '/dns/' + (p.toString() ? '?' + p.toString() : '')); } catch (x) { /* the address stays */ }
    }
    function checkCell(z) {
      var s = sum[z];
      if (!s) return '<span class="dz-shimmer"></span>';
      if (s.error) return '<span class="badge bg-red-lt border" title="' + esc(s.error) + '">not readable</span>';
      var out = [];
      if (s.errors) out.push('<span class="badge bg-red-lt border">' + s.errors + (s.errors > 1 ? ' errors' : ' error') + '</span>');
      if (s.warnings) out.push('<span class="badge bg-yellow-lt border">' + s.warnings + (s.warnings > 1 ? ' warnings' : ' warning') + '</span>');
      return out.length ? out.join(' ') : '<span class="text-green" title="No problems found">' + IC.ok + '</span>';
    }
    function draw() {
      $('#dz-q-clear').toggle(!!$('#dz-q').val());
      var list = shown();
      var body = $('#dz-list tbody').empty();
      page = pager('#dz-list-foot', list.length, per, page, function (p) { page = p; draw(); }, list.length === 1 ? 'zone' : 'zones');
      var rows = list.slice((page - 1) * per, page * per), need = [];
      rows.forEach(function (z, i) {
        var url = '/dns/' + encodeURIComponent(z.zone) + '/';
        var tr = $('<tr></tr>').attr('data-zone', z.zone);
        tr.append($('<td class="text-muted dz-no"></td>').text(((page - 1) * per + i + 1) + '.'));
        tr.append($('<td class="dz-zn"></td>').append($('<a class="mono"></a>').attr('href', url).text(z.zone)));
        tr.append($('<td class="dz-own"></td>').append(z.account ? $('<a class="text-reset"></a>').attr('href', '/account/' + encodeURIComponent(z.account) + '/').text(z.account)
          : z.key ? $('<a class="badge bg-purple-lt border text-decoration-none" title="Kept here by another server with this DNS API key"></a>').attr('href', '/dns-settings/keys/').text('key ' + z.key)
          : $('<span class="text-muted">-</span>')));
        var s = sum[z.zone];
        tr.append($('<td class="dz-num dz-recs"></td>').html(s && s.records != null ? String(s.records) : (s ? '-' : '<span class="dz-shimmer" style="width:26px"></span>')));
        tr.append($('<td class="dz-chkc"></td>').html('<a href="' + esc(url) + '" class="text-reset text-decoration-none">' + checkCell(z.zone) + '</a>'));
        tr.append($('<td class="text-muted mono small dz-ser"></td>').text(z.serial || '-'));
        if (pdns) tr.append($('<td class="dz-kind"></td>').html(kindBadge(z.kind)));
        var act = $('<td class="dz-act"></td>');
        act.append($('<a class="btn btn-white btn-sm me-1 dz-editbtn">Edit records</a>').attr('href', url));
        act.append($('<a href="#" class="btn btn-white btn-sm" data-rm>Remove</a>'));
        tr.append(act);
        body.append(tr);
        if (!sum[z.zone] && !loading[z.zone]) need.push(z.zone);
      });
      if (!rows.length)
        body.append($('<tr><td colspan="8" class="text-muted py-4 text-center"></td></tr>').find('td').text(
          Z.length ? ($('#dz-f').val() === 'bad' && !allChecked ? 'Checking the zones…' : 'No zone matches.') : 'There are no zones at ' + D.provider_name + ' yet.').end());
      remember();
      load(need);
    }
    function load(zones) {
      if (!zones.length) return $.Deferred().resolve().promise();
      zones.forEach(function (z) { loading[z] = 1; });
      return call('summary', { zones: zones }).then(function (j) {
        zones.forEach(function (z) { delete loading[z]; });
        if (!j.ok) return;
        $.each(j.data || {}, function (z, s) { sum[z] = s; });
        zones.forEach(function (z) { if (!sum[z]) sum[z] = { error: 'no answer' }; });
        $('#dz-list tbody tr').each(function () {
          var z = $(this).attr('data-zone'), s = sum[z];
          if (!s) return;
          $(this).find('.dz-recs').text(s.records != null ? s.records : '-');
          $(this).find('.dz-chkc a').html(checkCell(z));
        });
      });
    }
    /* "With problems" needs every zone checked: in batches of 25 */
    var allChecked = false;
    function checkAll() {
      var todo = Z.map(function (z) { return z.zone; }).filter(function (z) { return !sum[z] && !loading[z]; });
      if (!todo.length) { allChecked = true; draw(); return; }
      var batch = todo.slice(0, 25);
      load(batch).then(function () { if ($('#dz-f').val() === 'bad') { draw(); checkAll(); } });
    }
    var tmr = null;
    $('#dz-q').on('input', function () { clearTimeout(tmr); tmr = setTimeout(function () { page = 1; draw(); }, 120); });
    $('#dz-q-clear').on('click', function () { $('#dz-q').val(''); page = 1; draw(); $('#dz-q').trigger('focus'); });
    $('#dz-f').on('change', function () { page = 1; draw(); if ($(this).val() === 'bad') checkAll(); });
    $('#dz-list').on('click', '[data-rm]', function (e) {
      e.preventDefault();
      var z = $(this).closest('tr').attr('data-zone');
      openRemove(z, (Z.filter(function (x) { return x.zone === z; })[0] || {}).account);
    });
    draw();
    if ($('#dz-f').val() === 'bad') checkAll();

    /* ---------- a new zone from a zone file ---------- */
    var NI = [], niZone = '';
    function niStep(n) {
      $('#dz-ni-1').toggle(n === 1); $('#dz-ni-2').toggle(n === 2);
      $('#dz-ni-read').toggle(n === 1); $('#dz-ni-go, #dz-ni-back').toggle(n === 2);
    }
    $('#dz-import-zone').on('click', function (e) {
      e.preventDefault();
      showErr('#dz-m-newimp', ''); niStep(1);
      $('#dz-ni-file').val('');
      modal('dz-m-newimp').show();
    });
    $('#dz-ni-file').on('change', function () {
      var f = this.files && this.files[0];
      if (!f) return;
      if (f.size > 1048576) { showErr('#dz-m-newimp', 'The file is larger than 1 MB.'); return; }
      /* cPanel names them example.com.db */
      var nm = f.name.toLowerCase().replace(/\.(db|zone|txt|hosts)$/, '');
      if (!$.trim($('#dz-ni-zone').val()) && /^[a-z0-9.-]+\.[a-z]{2,}$/.test(nm)) $('#dz-ni-zone').val(nm);
      var rd = new FileReader();
      rd.onload = function () { $('#dz-ni-text').val(String(rd.result || '')); };
      rd.readAsText(f);
    });
    $('#dz-ni-back').on('click', function () { niStep(1); });
    function niSum() {
      var n = $('#dz-ni-rows input:checked').length;
      $('#dz-ni-sum').text(n + (n === 1 ? ' record' : ' records') + ' chosen.');
      $('#dz-ni-go').prop('disabled', !n);
    }
    var NST = { 'new': ['bg-green-lt', 'new'], skip: ['bg-muted-lt', 'left out'], bad: ['bg-red-lt', 'not valid'] };
    $('#dz-ni-read').on('click', function () {
      var btn = $(this);
      busy(btn, true); showErr('#dz-m-newimp', '');
      call('import-new-preview', { zone: $.trim($('#dz-ni-zone').val()), text: $('#dz-ni-text').val() }).then(function (j) {
        busy(btn, false);
        if (!j.ok) {
          showErr('#dz-m-newimp', j.error);
          if (j.data && j.data.exists) $('#dz-m-newimp .dz-err').append(' ').append($('<a></a>').attr('href', '/dns/' + encodeURIComponent(j.data.exists) + '/').text('Open ' + j.data.exists));
          return;
        }
        niZone = j.data.zone; NI = j.data.records || [];
        $('#dz-ni-zone').val(niZone); $('#dz-ni-name').text(niZone);
        var body = $('#dz-ni-rows').empty();
        NI.forEach(function (r, i) {
          var st = NST[r.status] || NST.bad, tr = $('<tr></tr>');
          tr.append($('<td></td>').append($('<input type="checkbox" class="form-check-input">').attr('data-i', i).prop('checked', r.status === 'new').prop('disabled', r.status !== 'new')));
          tr.append($('<td class="mono"></td>').text(r.name));
          tr.append($('<td></td>').append($('<span class="dz-ty"></span>').text(r.type)));
          tr.append($('<td class="text-muted"></td>').text(ttlText(r.ttl)));
          tr.append($('<td></td>').append($('<code></code>').text(r.value)).append(r.note ? $('<div class="text-muted small"></div>').text(r.note) : ''));
          tr.append($('<td></td>').append($('<span class="badge border"></span>').addClass(st[0]).text(st[1])));
          body.append(tr);
        });
        $('#dz-ni-all').prop('checked', true);
        niStep(2); niSum();
      });
    });
    $('#dz-ni-rows').on('change', 'input', niSum);
    $('#dz-ni-all').on('change', function () { $('#dz-ni-rows input:enabled').prop('checked', this.checked); niSum(); });
    $('#dz-ni-go').on('click', function () {
      var btn = $(this), recs = [];
      $('#dz-ni-rows input:checked').each(function () { var r = NI[Number($(this).attr('data-i'))]; recs.push({ name: r.name, type: r.type, ttl: r.ttl, value: r.value }); });
      busy(btn, true); showErr('#dz-m-newimp', '');
      call('import-new', { zone: niZone, recs: recs }).then(function (j) {
        busy(btn, false);
        if (!j.ok) { showErr('#dz-m-newimp', j.error); return; }
        try { sessionStorage.setItem('dz-msg', JSON.stringify([j.message].concat(j.warnings || []))); } catch (x) { /* shown only here */ }
        location.href = '/dns/' + encodeURIComponent(j.data.zone) + '/';
      });
    });
  }

  /* =================== one zone =================== */
  function zoneView() {
    var Z = null, ft = '', per = 50, page = 1, editing = null, refs = {};
    var help = D.help || {};
    var TAGS = { spf: ['SPF', 'bg-azure-lt'], dmarc: ['DMARC', 'bg-azure-lt'], dkim: ['DKIM', 'bg-azure-lt'], verify: ['Verification', 'bg-azure-lt'], here: ['This server', 'bg-green-lt'] };

    function load(msg) {
      return call('zone').then(function (j) {
        if (!j.ok) {
          $('#dz-load').removeClass('text-muted').addClass('text-danger').text(j.error).show();
          $('#dz-wrap').hide(); $('#dz-chk').html('<li class="text-muted">-</li>');
          return;
        }
        Z = j.data;
        $('#dz-add-btn, #dz-sel-btn').prop('disabled', false);
        $('#dz-load').hide(); $('#dz-wrap').show();
        head(); render(); side();
        if (msg) said(msg);
      });
    }
    function head() {
      var b = [];
      if (Z.account) b.push('<a href="/account/' + encodeURIComponent(Z.account) + '/" class="badge bg-blue-lt border text-decoration-none" title="Hosting account">' + esc(Z.account) + '</a>');
      else if (Z.key) b.push('<a href="/dns-settings/keys/" class="badge bg-purple-lt border text-decoration-none" title="Kept here by another server with this DNS API key">key ' + esc(Z.key) + '</a>');
      else b.push('<span class="badge bg-muted-lt border" title="No hosting account has this domain">no account</span>');
      b.push('<span class="badge bg-azure-lt border">' + esc(Z.provider) + '</span>');
      if (Z.kind) b.push(kindBadge(Z.kind));
      $('#dz-head-badges').html(b.join(' '));
    }
    function key(n, t, v) { return n + '|' + t + '|' + v; }
    /* ---------- the records ---------- */
    function render() {
      refs = {};
      (Z.check || []).forEach(function (it) {
        (it.refs || []).forEach(function (r) {
          var k = key(r[0], r[1], r[2]);
          if (!refs[k] || it.level === 'error') refs[k] = it;
        });
      });
      var have = {};
      (Z.records || []).forEach(function (r) { have[r.type] = 1; });
      if (ft && !have[ft]) ft = '';
      var box = $('#dz-types').empty();
      [''].concat(Object.keys(have).sort()).forEach(function (k) {
        box.append($('<button type="button" class="btn btn-white"></button>').toggleClass('active', k === ft).attr('data-ft', k).attr('aria-pressed', k === ft ? 'true' : 'false').text(k || 'All'));
      });
      draw();
    }
    function draw() {
      var q = $.trim($('#dz-rq').val()).toLowerCase();
      $('#dz-rq-clear').toggle(!!q);
      var list = [];
      (Z.records || []).forEach(function (r, i) {
        if (ft && r.type !== ft) return;
        if (q && (r.fqdn + ' ' + r.type + ' ' + r.value).toLowerCase().indexOf(q) < 0) return;
        list.push(i);
      });
      var n = (Z.records || []).length;
      $('#dz-count').text(n === 1 ? '1 record' : n + ' records');
      var foot = $('#dz-rec-foot');
      page = pager(foot, list.length, per, page, function (p) { page = p; draw(); }, list.length === 1 ? 'record' : 'records');
      foot.attr('style', list.length > per ? '' : 'display:none !important;');
      var body = $('#dz-tbl tbody').empty();
      list.slice((page - 1) * per, page * per).forEach(function (i) {
        var r = Z.records[i], tr = $('<tr></tr>').attr('data-i', i), k = key(r.fqdn, r.type, r.value);
        var cb = $('<input type="checkbox" class="form-check-input m-0 dz-pick">').prop('disabled', !r.del).prop('checked', !!picked[k]);
        if (!r.del) cb.attr('title', r.locked || 'Not changed here');
        tr.append($('<td class="dz-selc"></td>').append(cb)).toggleClass('dz-picked', !!picked[k]);
        var nm = $('<td class="dz-name mono"></td>');
        if (r.name === '@') nm.append($('<span class="dz-lbl"></span>').text(Z.zone));
        else nm.append($('<span class="dz-lbl"></span>').text(r.name)).append($('<span class="dz-dom"></span>').text('.' + Z.zone));
        nm.attr('title', r.name === '@' ? 'The domain itself' : r.fqdn);
        if (r.note) nm.append(' ').append($('<span class="dz-warn-ic"></span>').html(IC.warn).attr('title', r.note));
        tr.append(nm);
        tr.append($('<td class="dz-type"></td>').append($('<span class="dz-ty"></span>').text(r.type)));
        var vl = $('<td class="dz-val"></td>'), code = $('<code></code>').text(r.value);
        vl.append(code);
        if (r.value.length > 90) { code.addClass('clip'); vl.append(' ').append($('<a href="#" class="small" data-more>Show all</a>')); }
        if (r.tag && TAGS[r.tag]) vl.append(' ').append($('<span class="badge border"></span>').addClass(TAGS[r.tag][1]).text(TAGS[r.tag][0]));
        if (r.proxied) vl.append(' ').append('<span class="badge bg-orange-lt border">Proxied</span>');
        var it = refs[key(r.fqdn, r.type, r.value)];
        if (it) vl.append(' ').append($('<a href="#" data-goto-chk></a>').addClass(LV[it.level][0]).attr('data-id', it.id).attr('title', it.text).html(LV[it.level][1]));
        tr.append(vl);
        tr.append($('<td class="text-muted dz-ttl"></td>').text(ttlText(r.ttl)));
        var act = $('<td class="dz-act"></td>');
        if (r.edit) act.append($('<button type="button" class="btn btn-white btn-sm me-1" data-edit title="Edit" aria-label="Edit"></button>').html(IC.edit));
        if (r.del) act.append($('<button type="button" class="btn btn-white btn-sm" data-del title="Remove" aria-label="Remove"></button>').html(IC.trash));
        if (!r.edit && !r.del) act.append($('<span class="dz-lock"></span>').html(IC.lock).attr('title', r.locked || 'Not changed here'));
        else if (!r.edit) act.prepend($('<span class="dz-lock"></span>').html(IC.lock).attr('title', (r.locked || 'Not changed here') + ' It can be removed.'));
        tr.append(act);
        body.append(tr);
      });
      $('#dz-none').toggle(!list.length);
      selCount();
    }

    /* ---------- select mode: tick records, then remove them together ---------- */
    var picked = {};
    function selCount() {
      var n = Object.keys(picked).length;
      $('#dz-sel-del').prop('disabled', !n).text(n ? 'Delete selected (' + n + ')' : 'Delete selected');
      var boxes = $('#dz-tbl tbody .dz-pick:enabled'), on = boxes.filter(':checked').length;
      $('#dz-sel-all').prop('checked', boxes.length > 0 && on === boxes.length).prop('indeterminate', on > 0 && on < boxes.length);
    }
    function selMode(on) {
      picked = {};
      $('#dz-page').toggleClass('dz-selecting', on);
      $('#dz-tools').toggle(!on); $('#dz-sel-tools').toggle(on);
      draw();
    }
    $('#dz-sel-btn').on('click', function () { selMode(true); });
    $('#dz-sel-cancel').on('click', function () { selMode(false); });
    function pick(tr, on) {
      var r = Z.records[Number(tr.attr('data-i'))], k = key(r.fqdn, r.type, r.value);
      if (on) picked[k] = r; else delete picked[k];
      tr.toggleClass('dz-picked', on).find('.dz-pick').prop('checked', on);
    }
    $('#dz-tbl').on('change', '.dz-pick', function () { pick($(this).closest('tr'), this.checked); selCount(); });
    /* in select mode a click anywhere on the row ticks it */
    $('#dz-tbl').on('click', 'tbody tr', function (e) {
      if (!$('#dz-page').hasClass('dz-selecting') || $(e.target).is('.dz-pick, a, button, button *, a *')) return;
      var cb = $(this).find('.dz-pick');
      if (cb.is(':enabled')) { pick($(this), !cb.is(':checked')); selCount(); }
    });
    $('#dz-sel-all').on('change', function () {
      var on = this.checked;
      $('#dz-tbl tbody .dz-pick:enabled').each(function () { pick($(this).closest('tr'), on); });
      selCount();
    });
    $('#dz-sel-del').on('click', function () {
      var list = Object.keys(picked).map(function (k) { return picked[k]; });
      if (!list.length) return;
      delRec = list;
      $('#dz-confirm-h').text(list.length === 1 ? 'Remove this record?' : 'Remove these ' + list.length + ' records?');
      $('#dz-confirm-t').text(list.slice(0, 30).map(function (r) { return r.type + '  ' + r.fqdn + '  ' + (r.value.length > 60 ? r.value.slice(0, 60) + '…' : r.value); }).join('\n')
        + (list.length > 30 ? '\n… and ' + (list.length - 30) + ' more' : ''));
      modal('dz-m-confirm').show();
    });
    var rq = null;
    $('#dz-rq').on('input', function () { clearTimeout(rq); rq = setTimeout(function () { page = 1; draw(); }, 100); });
    $('#dz-rq-clear').on('click', function () { $('#dz-rq').val(''); page = 1; draw(); });
    $('#dz-types').on('click', 'button', function () { ft = $(this).attr('data-ft'); page = 1; render(); });
    $('#dz-tbl').on('click', '[data-more]', function (e) {
      e.preventDefault();
      var c = $(this).siblings('code').toggleClass('clip');
      $(this).text(c.hasClass('clip') ? 'Show all' : 'Show less');
    });
    $('#dz-tbl').on('click', '[data-goto-chk]', function (e) {
      e.preventDefault();
      var li = $('#dz-chk li[data-id="' + $(this).attr('data-id') + '"]');
      if (li.length) { li[0].scrollIntoView({ behavior: 'smooth', block: 'center' }); li.css('background', '#fff8e1'); setTimeout(function () { li.css('background', ''); }, 1600); }
    });

    /* ---------- the side cards ---------- */
    function side() {
      var ul = $('#dz-chk').empty(), items = Z.check || [];
      if (!items.length) ul.append($('<li></li>').append($('<span class="dz-lv text-green"></span>').html(IC.ok)).append('<span class="dz-txt">No problems found.</span>'));
      items.forEach(function (it) {
        var li = $('<li></li>').attr('data-id', it.id);
        li.append($('<span class="dz-lv"></span>').addClass(LV[it.level][0]).html(LV[it.level][1]));
        var tx = $('<div class="dz-txt"></div>').text(it.text);
        if (it.fix) tx.append('<br>').append($('<button type="button" class="btn btn-sm btn-white" data-fix></button>').attr('data-id', it.id).text(it.fix.label));
        li.append(tx); ul.append(li);
      });
      var ns = $('#dz-ns').empty(), want = Z.expected_ns || [];
      (Z.nameservers || []).forEach(function (n) { ns.append($('<li></li>').text(n)); });
      if (!(Z.nameservers || []).length) ns.append('<li class="text-danger">none</li>');
      $('#dz-ns-note').text('The servers that answer for the zone. Which ones the internet asks is set where the domain is registered.'
        + (want.length ? ' This server\'s name servers: ' + want.join(', ') + '.' : '')
        + ((Z.types || []).indexOf('NS') > -1 ? ' They are records of the zone: change them in the list.' : ''));
      var soa = (Z.soa || '').split(/\s+/), sf = Z.soa_fields;
      $('#dz-soa-card').toggle(soa.length >= 7);
      $('#dz-soa-edit, #dz-soa-raise').toggle(!!sf);
      $('#dz-soa-note').text(sf ? 'Every change raises the serial by itself. Raise it by hand to make the secondary servers fetch the zone again.'
        : 'The provider keeps it and raises the serial with every change.');
      if (soa.length >= 7) {
        var t = $('#dz-soa').empty();
        [['Primary server', soa[0].replace(/\.$/, '')], ['Contact', sf ? sf.contact : soa[1].replace(/\.$/, '').replace(/^([^.]+)\./, '$1@')], ['Serial', soa[2]],
         ['Refresh', ttlText(soa[3])], ['Retry', ttlText(soa[4])], ['Expire', ttlText(soa[5])], ['Negative TTL', ttlText(soa[6])]].forEach(function (x) {
          t.append($('<tr></tr>').append($('<td></td>').text(x[0])).append($('<td class="mono"></td>').text(x[1])));
        });
      }
      if (Z.account) {
        $('#dz-acct-link').text(Z.account).attr('href', '/account/' + encodeURIComponent(Z.account) + '/');
        mpLoad();
      }
    }
    /* ---------- SOA: Raise serial, Edit ---------- */
    function soaSave(fields, btn) {
      busy(btn, true);
      return call('soa', { soa: fields }).then(function (j) {
        busy(btn, false);
        if (j.ok) load(j);
        return j;
      });
    }
    $('#dz-soa-raise').on('click', function () {
      soaSave({}, $(this)).then(function (j) { if (!j.ok) toast(j.error, 'error'); });
    });
    function soaHuman() {
      $('#dz-soa-form .dz-soa-h').each(function () {
        var v = $(this).closest('div').find('input').val();
        $(this).text(/^\d+$/.test(v) ? '(' + ttlText(v) + ')' : '');
      });
    }
    $('#dz-soa-edit').on('click', function (e) {
      e.preventDefault();
      var sf = Z && Z.soa_fields;
      if (!sf) return;
      showErr('#dz-soa-form', '');
      ['primary', 'contact', 'refresh', 'retry', 'expire', 'minimum'].forEach(function (k) { $('#dz-soa-' + k).val(sf[k]); });
      $('#dz-soa-serial').val('').attr('placeholder', String(sf.serial + 1));
      $('#dz-soa-serial-hint').text('Now ' + sf.serial + '. Empty = one higher. Only a higher number: secondary servers keep their copy while the serial is lower.');
      soaHuman();
      modal('dz-m-soa').show();
    });
    $('#dz-soa-form').on('input', 'input', soaHuman).on('submit', function (e) {
      e.preventDefault();
      var f = {};
      ['primary', 'contact', 'serial', 'refresh', 'retry', 'expire', 'minimum'].forEach(function (k) { f[k] = $.trim($('#dz-soa-' + k).val()); });
      soaSave(f, $('#dz-soa-save')).then(function (j) {
        if (!j.ok) { showErr('#dz-soa-form', j.error); return; }
        modal('dz-m-soa').hide();
      });
    });

    $('#dz-recheck').on('click', function (e) { e.preventDefault(); $('#dz-chk').html('<li class="text-muted"><span class="spinner-border spinner-border-sm me-2"></span>Checking…</li>'); load(); });
    $('#dz-chk').on('click', '[data-fix]', function () {
      var btn = $(this);
      busy(btn, true);
      call('fix', { id: btn.attr('data-id') }).then(function (j) {
        busy(btn, false);
        if (!j.ok) { toast(j.error, 'error'); load(); return; }
        load(j);
      });
    });

    /* ---------- add / change a record ---------- */
    var M = $('#dz-m-rec'), F = $('#dz-rec-form'), ty = $('#dz-type'), pv = 0, pvT = null;
    function syncType() {
      var v = ty.val();
      if (v !== 'TXT') showBuilder('');
      F.find('[data-for]').each(function () { $(this).toggle($(this).attr('data-for').split(' ').indexOf(v) > -1); });
      $('#dz-type-help').text(help[v] || '');
      $('#dz-ip').attr('placeholder', v === 'AAAA' ? '2001:db8::10' : '192.0.2.10');
      $('#dz-host').attr('placeholder', v === 'SRV' ? 'sip.example.com' : 'mail.example.com');
      $('#dz-target-l').text(v === 'NS' ? 'Name server' : (v === 'PTR' ? 'Host name' : 'Alias of'));
      $('#dz-target').attr('placeholder', v === 'NS' ? 'ns1.example.net' : (v === 'PTR' ? 'mail.example.com' : 'example.com'));
    }
    function ttlOptions(cur) {
      var s = $('#dz-ttl').empty(), list = (Z.auto_ttl ? [1] : []).concat(Z.ttls || [300, 3600, 14400, 86400]);
      if (cur && list.indexOf(cur) < 0) list.push(cur);
      list.sort(function (a, b) { return a - b; }).forEach(function (x) { s.append($('<option></option>').val(x).text(ttlText(x))); });
      s.val(String(cur || (Z.auto_ttl ? 1 : 14400)));
    }
    /* the value from the fields, as the panel takes it */
    function value() {
      var v = ty.val(), host = $.trim($('#dz-host').val());
      if (v === 'A' || v === 'AAAA') return $.trim($('#dz-ip').val());
      if (v === 'CNAME' || v === 'NS' || v === 'PTR') return $.trim($('#dz-target').val());
      if (v === 'MX') {
        /* "10 mail.example.com" pasted into the server field */
        var m = /^(\d{1,5})\s+(\S+)$/.exec(host);
        if (m) { $('#dz-prio').val(m[1]); $('#dz-host').val(m[2]); host = m[2]; }
        return host === '' ? '' : ($.trim($('#dz-prio').val()) || '0') + ' ' + host;
      }
      if (v === 'SRV') return host === '' ? '' : [$.trim($('#dz-prio').val()) || '0', $.trim($('#dz-weight').val()) || '0', $.trim($('#dz-port').val()) || '0', host].join(' ');
      if (v === 'TXT') return $.trim($('#dz-txt').val());
      if (v === 'CAA') { var c = $.trim($('#dz-caa').val()).replace(/^"|"$/g, ''); return c === '' ? '' : (F.data('flags') || '0') + ' ' + $('#dz-tag').val() + ' "' + c + '"'; }
      return '';
    }
    function fill(type, val) {
      val = String(val == null ? '' : val);
      var p = val.split(/\s+/);
      if (type === 'A' || type === 'AAAA') $('#dz-ip').val(val);
      else if (type === 'CNAME' || type === 'NS' || type === 'PTR') $('#dz-target').val(val);
      else if (type === 'MX') { if (p.length > 1) { $('#dz-prio').val(p[0]); $('#dz-host').val(p[1]); } else $('#dz-host').val(val); }
      else if (type === 'SRV') { $('#dz-prio').val(p[0]); $('#dz-weight').val(p[1] || ''); $('#dz-port').val(p[2] || ''); $('#dz-host').val(p[3] || ''); }
      else if (type === 'TXT') $('#dz-txt').val(val);
      else if (type === 'CAA') {
        var m = /^(\d+)\s+(\S+)\s+"?(.*?)"?$/.exec(val) || [];
        F.data('flags', m[1] || '0'); $('#dz-tag').val(m[2] || 'issue'); $('#dz-caa').val((m[3] || '').replace(/\\"/g, '"'));
      }
    }
    function rec() { return { name: $.trim($('#dz-name').val()) || '@', type: ty.val(), value: value(), ttl: Number($('#dz-ttl').val()) || 0 }; }
    function oldRec() { return editing ? { name: editing.name, type: editing.type, value: editing.value } : null; }
    function preview() {
      var r = rec(), seq = ++pv;
      if (r.value === '') { $('#dz-saved').hide(); showErr(F, ''); $('#dz-hints').empty(); return; }
      var d = { rec: r };
      if (editing) d.old = oldRec();
      call('preview', d).then(function (j) {
        if (seq !== pv) return;
        var data = j.data || {};
        if (j.ok) { $('#dz-saved').show().find('span:last').text(data.line); showErr(F, ''); }
        /* a hint that says what is wrong and fixes it says more than the error */
        else { $('#dz-saved').hide(); showErr(F, (data.hints || []).some(function (h) { return h.level === 'error' && h.fix; }) ? '' : j.error); }
        var hb = $('#dz-hints').empty();
        (data.hints || []).forEach(function (h) {
          var a = $('<div class="alert"></div>').addClass(h.level === 'error' ? 'alert-danger' : (h.level === 'warn' ? 'alert-warning' : 'alert-info'));
          a.append($('<span></span>').text(h.text));
          if (h.fix) a.append($('<button type="button" class="btn btn-sm btn-white"></button>').text(h.label || 'Use it').data('fix', h.fix));
          hb.append(a);
        });
      });
    }
    function later() { clearTimeout(pvT); pvT = setTimeout(preview, 350); }
    F.on('input', 'input, textarea', later);

    /* ---------- ready-made SPF / DKIM / DMARC: a builder writes the text ---------- */
    var B = '', P = {};
    function txtKind(name, v) {
      v = String(v || '').toLowerCase();
      if (v.indexOf('v=spf1') === 0) return 'spf';
      if (v.indexOf('v=dmarc1') === 0) return 'dmarc';
      if (v.indexOf('v=dkim1') === 0 || /(^|\.)_domainkey(\.|$)/.test(name || '')) return 'dkim';
      return '';
    }
    function showBuilder(kind) {
      B = kind || '';
      $('.dz-builder').hide();
      if (B) $('#dz-b-' + B).show();
      $('#dz-presets [data-preset]').each(function () { $(this).toggleClass('active', $(this).attr('data-preset') === B); });
    }
    function tags(v) {
      var o = {}, order = [];
      String(v || '').split(';').forEach(function (p) {
        var m = /^\s*([A-Za-z]+)\s*=\s*(.*?)\s*$/.exec(p);
        if (m) { var k = m[1].toLowerCase(); if (!(k in o)) order.push(k); o[k] = m[2]; }
      });
      o._order = order;
      return o;
    }
    var SPF_LOOKUP = /^[-~?+]?(include:|a\b|mx\b|ptr\b|exists:|redirect=)/i;
    function spfIp() { return P.ip ? (P.ip.indexOf(':') > -1 ? 'ip6:' : 'ip4:') + P.ip : ''; }
    function bParse(v) {
      if (B === 'spf') {
        var t = String(v || '').trim().split(/\s+/).slice(1), other = [], all = '~all', inc = {};
        $('#dz-b-spf [data-b]').prop('checked', false);
        $.each(P.senders || {}, function (id, x) { inc[x.include.toLowerCase()] = id; });
        $('#dz-b-senders input').prop('checked', false);
        t.forEach(function (x) {
          var lx = x.toLowerCase();
          if (/^\+?a$/.test(lx)) $('#dz-b-spf [data-b=a]').prop('checked', true);
          else if (/^\+?mx$/.test(lx)) $('#dz-b-spf [data-b=mx]').prop('checked', true);
          else if (spfIp() && lx.replace(/^\+/, '') === spfIp().toLowerCase()) $('#dz-b-spf [data-b=ip]').prop('checked', true);
          else if (inc[lx.replace(/^\+/, '')]) $('#dz-b-senders input[value=' + inc[lx.replace(/^\+/, '')] + ']').prop('checked', true);
          else if (/^[-~?+]?all$/.test(lx)) all = lx === '-all' || lx === '?all' ? lx : '~all';
          else other.push(x);
        });
        $('#dz-b-other').val(other.join(' ')); $('#dz-b-all').val(all);
      } else if (B === 'dkim') {
        var d = tags(v);
        $('#dz-b-k').val(d.k === 'ed25519' ? 'ed25519' : 'rsa');
        $('#dz-b-p').val(d.p || '');
        var nm = $.trim($('#dz-name').val()).toLowerCase(), m = /^(.+?)\._domainkey(\.|$)/.exec(nm);
        $('#dz-b-sel').val(m ? m[1] : '');
      } else if (B === 'dmarc') {
        var g = tags(v);
        $('input[name=dz-b-p]').prop('checked', false);
        $('input[name=dz-b-p][value=' + (/^(none|quarantine|reject)$/.test(g.p || '') ? g.p : 'none') + ']').prop('checked', true);
        $('#dz-b-rua').val((g.rua || '').split(',').map(function (x) { return $.trim(x).replace(/^mailto:/i, ''); }).filter(Boolean).join(', '));
        $('#dz-b-pct').val(g.pct && g.pct !== '100' ? g.pct : '');
        $('#dz-b-sp').val(/^(none|quarantine|reject)$/.test(g.sp || '') ? g.sp : '');
        $('#dz-b-adkim').val(g.adkim === 's' ? 's' : 'r'); $('#dz-b-aspf').val(g.aspf === 's' ? 's' : 'r');
        F.data('dmarc-extra', g);
      }
      bOpts();
    }
    function bGen() {
      if (B === 'spf') {
        var t = ['v=spf1'];
        if ($('#dz-b-spf [data-b=a]').is(':checked')) t.push('+a');
        if ($('#dz-b-spf [data-b=mx]').is(':checked')) t.push('+mx');
        if ($('#dz-b-spf [data-b=ip]').is(':checked') && spfIp()) t.push(spfIp());
        $('#dz-b-senders input:checked').each(function () { t.push(P.senders[this.value].include); });
        t = t.concat($.trim($('#dz-b-other').val()).split(/\s+/).filter(function (x) { return x && !/^v=spf1$/i.test(x) && !/^[-~?+]?all$/i.test(x); }));
        t.push($('#dz-b-all').val());
        var n = t.filter(function (x) { return SPF_LOOKUP.test(x); }).length;
        $('#dz-b-lookups').text('Uses ' + n + ' of the 10 DNS lookups receivers allow (not counting those inside the includes).').toggleClass('text-danger', n > 10);
        return t.join(' ');
      }
      if (B === 'dkim') {
        var key = $('#dz-b-p').val();
        var m = /(?:^|;)\s*p=([^;]*)/i.exec(key.replace(/"/g, ''));
        key = (m ? m[1] : key).replace(/-----[A-Z ]+-----/g, '').replace(/[\s"]/g, '');
        var sel = $.trim($('#dz-b-sel').val()).toLowerCase().replace(/\._domainkey.*$/, '');
        if (sel) $('#dz-name').val(sel + '._domainkey');
        return 'v=DKIM1; k=' + $('#dz-b-k').val() + '; p=' + key;
      }
      if (B === 'dmarc') {
        var x = F.data('dmarc-extra') || { _order: [] }, out = ['v=DMARC1', 'p=' + ($('input[name=dz-b-p]:checked').val() || 'none')];
        if ($('#dz-b-sp').val()) out.push('sp=' + $('#dz-b-sp').val());
        var pct = $.trim($('#dz-b-pct').val());
        if (pct && pct !== '100') out.push('pct=' + pct);
        var rua = $('#dz-b-rua').val().split(/[\s,;]+/).filter(Boolean).map(function (a) { return /^mailto:/i.test(a) ? a : 'mailto:' + a; });
        if (rua.length) out.push('rua=' + rua.join(','));
        if ($('#dz-b-adkim').val() === 's') out.push('adkim=s');
        if ($('#dz-b-aspf').val() === 's') out.push('aspf=s');
        /* tags the builder does not show are kept */
        x._order.forEach(function (k) { if (['v', 'p', 'sp', 'pct', 'rua', 'adkim', 'aspf'].indexOf(k) < 0) out.push(k + '=' + x[k]); });
        return out.join('; ');
      }
      return $('#dz-txt').val();
    }
    function bOpts() { $('.dz-builder .dz-opt').each(function () { $(this).toggleClass('on', $(this).find('input').is(':checked')); }); }
    $('.dz-builder').on('input change', 'input, textarea, select', function (e) {
      e.stopPropagation();
      bOpts();
      $('#dz-txt').val(bGen());
      later();
    });
    /* the text typed by hand: the builder follows it */
    $('#dz-txt').on('input', function () { if (B && txtKind($('#dz-name').val(), this.value) === B) bParse(this.value); });
    function sendersBox() {
      var box = $('#dz-b-senders').empty();
      $.each(P.senders || {}, function (id, x) {
        box.append($('<label class="dz-opt"></label>').append($('<input type="checkbox">').val(id)).append($('<span></span>').text(x.name)));
      });
      $('#dz-b-ip').text(spfIp());
      $('#dz-b-spf [data-b=ip]').closest('label').toggle(!!spfIp());
      $('#dz-b-local').toggle(!!P.key);
    }
    $('#dz-b-local').on('click', function () {
      $('#dz-b-sel').val(P.selector || 'default'); $('#dz-b-k').val('rsa'); $('#dz-b-p').val(P.key);
      $('#dz-txt').val(bGen()); later();
    });
    var PRE = {
      spf:   { name: function () { return '@'; }, fqdn: function () { return Z.zone; }, init: 'v=spf1 +a +mx ~all' },
      dmarc: { name: function () { return '_dmarc'; }, fqdn: function () { return '_dmarc.' + Z.zone; }, init: 'v=DMARC1; p=none' },
      dkim:  { name: function () { return (P.selector || 'default') + '._domainkey'; }, fqdn: function () { return (P.selector || 'default') + '._domainkey.' + Z.zone; }, init: '' }
    };
    $('#dz-presets').on('click', '[data-preset]', function () {
      var kind = $(this).attr('data-preset'), pre = PRE[kind];
      /* one SPF / DMARC / DKIM record per name: the one there is changed */
      var have = (Z.records || []).filter(function (r) { return r.type === 'TXT' && r.edit && r.fqdn === pre.fqdn() && txtKind(r.fqdn, r.value) === kind; })[0];
      ty.val('TXT'); syncType();
      if (have) { openRec(have); toast('There already is ' + (kind === 'dkim' ? 'a DKIM key' : 'an ' + kind.toUpperCase() + ' record') + ' there: you are changing it.'); return; }
      $('#dz-name').val(pre.name());
      showBuilder(kind);
      var v = pre.init.replace('{zone}', Z.zone);
      if (kind === 'dkim') { $('#dz-b-sel').val(P.selector || 'default'); $('#dz-b-k').val('rsa'); $('#dz-b-p').val(P.key || ''); }
      else bParse(v);
      $('#dz-txt').val(bGen());
      preview();
    });
    F.on('change', 'select', function () { if (this.id === 'dz-type') syncType(); later(); });
    $('#dz-hints').on('click', 'button', function () {
      var f = $(this).data('fix') || {}, t = f.type || ty.val(), v = value();
      if (f.type) { ty.val(f.type); syncType(); }
      if (f.name != null) $('#dz-name').val(f.name);
      if (f.value != null) fill(t, f.value); else if (f.type) fill(t, v);
      if (f.ttl) { ttlOptions(Number(f.ttl)); }
      preview();
    });
    function openRec(r) {
      editing = r || null;
      F[0].reset(); F.data('flags', '0'); showErr(F, '');
      $('#dz-hints').empty(); $('#dz-saved').hide();
      $('#dz-sfx').text('.' + Z.zone);
      $('#dz-rec-h').text(r ? 'Edit the record' : 'Add a record');
      $('#dz-rec-save').text(r ? 'Save' : 'Add record');
      ty.val(r ? r.type : 'A');
      $('#dz-name').val(r ? r.name : '');
      if (r) fill(r.type, r.value);
      ttlOptions(r ? r.ttl : 0);
      P = Z.presets || {};
      sendersBox();
      syncType();
      $('#dz-presets').toggle(!r);
      showBuilder(r && r.type === 'TXT' ? txtKind(r.fqdn, r.value) : '');
      if (B) bParse(r.value);
      modal('dz-m-rec').show();
      if (r) preview();
    }
    M.on('shown.bs.modal', function () { (editing ? $('#dz-name') : ty).trigger('focus'); });
    $('#dz-add-btn').on('click', function (e) { e.preventDefault(); if (Z) openRec(null); });
    F.on('submit', function (e) {
      e.preventDefault();
      var r = rec();
      if (r.value === '') { showErr(F, 'Fill in the value.'); return; }
      var btn = $('#dz-rec-save');
      busy(btn, true);
      (editing ? call('edit', { old: oldRec(), rec: r }) : call('add', { rec: r })).then(function (j) {
        busy(btn, false);
        if (!j.ok) { showErr(F, j.error); return; }
        modal('dz-m-rec').hide();
        load(j);
      });
    });
    var delRec = null;
    $('#dz-tbl').on('click', '[data-edit], [data-del]', function () {
      var r = Z.records[Number($(this).closest('tr').attr('data-i'))];
      if ($(this).is('[data-edit]')) {
        openRec(r); return;
      }
      delRec = r;
      $('#dz-confirm-h').text('Remove the ' + r.type + ' record of ' + r.fqdn + '?');
      $('#dz-confirm-t').text(r.value.length > 160 ? r.value.slice(0, 160) + '…' : r.value);
      modal('dz-m-confirm').show();
    });
    $('#dz-confirm-ok').on('click', function () {
      var btn = $(this);
      busy(btn, true);
      var many = $.isArray(delRec);
      (many ? call('delete-many', { recs: delRec.map(function (r) { return { name: r.name, type: r.type, value: r.value }; }) })
            : call('delete', { rec: { name: delRec.name, type: delRec.type, value: delRec.value } })).then(function (j) {
        busy(btn, false);
        modal('dz-m-confirm').hide();
        if (!j.ok) { toast(j.error, 'error'); return; }
        if (many) selMode(false);
        load(j);
      });
    });

    /* ---------- import / export ---------- */
    var IMP = [];
    function impStep(n) {
      $('#dz-imp-1').toggle(n === 1); $('#dz-imp-2').toggle(n === 2);
      $('#dz-imp-read').toggle(n === 1); $('#dz-imp-go, #dz-imp-back').toggle(n === 2);
    }
    $('#dz-import-btn').on('click', function (e) {
      e.preventDefault();
      showErr('#dz-m-import', ''); impStep(1);
      $('#dz-imp-file').val('');
      modal('dz-m-import').show();
    });
    $('#dz-imp-file').on('change', function () {
      var f = this.files && this.files[0];
      if (!f) return;
      if (f.size > 1048576) { showErr('#dz-m-import', 'The file is larger than 1 MB.'); return; }
      var rd = new FileReader();
      rd.onload = function () { $('#dz-imp-text').val(String(rd.result || '')); };
      rd.readAsText(f);
    });
    $('#dz-imp-back').on('click', function () { impStep(1); });
    var ST = { 'new': ['bg-green-lt', 'new'], same: ['bg-muted-lt', 'already there'], skip: ['bg-muted-lt', 'left out'], bad: ['bg-red-lt', 'not valid'] };
    function impSum() {
      var n = $('#dz-imp-rows input:checked').length, rep = $('#dz-imp-replace').is(':checked'), sets = {}, gone = 0;
      $('#dz-imp-rows input:checked').each(function () { var r = IMP[Number($(this).attr('data-i'))]; sets[r.name + '|' + r.type] = 1; });
      if (rep) Object.keys(sets).forEach(function (k) {
        var now = (IMP.current || {})[k] || [];
        now.forEach(function (v) { if (!IMP.some(function (r, i) { return r.name + '|' + r.type === k && r.value === v && $('#dz-imp-rows input[data-i=' + i + ']').is(':checked'); })) gone++; });
      });
      $('#dz-imp-sum').text(n + (n === 1 ? ' record' : ' records') + ' chosen' + (rep && gone ? '; ' + gone + (gone === 1 ? ' record now in the zone goes' : ' records now in the zone go') + ' (replaced)' : '') + '.');
      $('#dz-imp-go').prop('disabled', !n);
    }
    $('#dz-imp-read').on('click', function () {
      var btn = $(this);
      busy(btn, true); showErr('#dz-m-import', '');
      call('import-preview', { text: $('#dz-imp-text').val() }).then(function (j) {
        busy(btn, false);
        if (!j.ok) { showErr('#dz-m-import', j.error); return; }
        IMP = j.data.records || []; IMP.current = j.data.current || {};
        if (!IMP.length) { showErr('#dz-m-import', 'No records were found in it.'); return; }
        var body = $('#dz-imp-rows').empty();
        IMP.forEach(function (r, i) {
          var ok = r.status === 'new' || r.status === 'same', tr = $('<tr></tr>');
          var cb = $('<input type="checkbox" class="form-check-input">').attr('data-i', i).prop('checked', ok).prop('disabled', r.status === 'skip' || r.status === 'bad');
          tr.append($('<td></td>').append(cb));
          tr.append($('<td class="mono"></td>').text(r.name));
          tr.append($('<td></td>').append($('<span class="dz-ty"></span>').text(r.type)));
          tr.append($('<td class="text-muted"></td>').text(ttlText(r.ttl)));
          tr.append($('<td></td>').append($('<code></code>').text(r.value)).append(r.note ? $('<div class="text-muted small"></div>').text(r.note) : ''));
          tr.append($('<td></td>').append($('<span class="badge border"></span>').addClass(ST[r.status][0]).text(ST[r.status][1])));
          body.append(tr);
        });
        $('#dz-imp-all').prop('checked', true);
        impStep(2); impSum();
      });
    });
    $('#dz-imp-rows').on('change', 'input', impSum);
    $('#dz-imp-replace').on('change', impSum);
    $('#dz-imp-all').on('change', function () { $('#dz-imp-rows input:enabled').prop('checked', this.checked); impSum(); });
    $('#dz-imp-go').on('click', function () {
      var btn = $(this), recs = [];
      $('#dz-imp-rows input:checked').each(function () { var r = IMP[Number($(this).attr('data-i'))]; recs.push({ name: r.name, type: r.type, ttl: r.ttl, value: r.value }); });
      busy(btn, true); showErr('#dz-m-import', '');
      call('import', { recs: recs, replace: $('#dz-imp-replace').is(':checked') ? 1 : 0 }).then(function (j) {
        busy(btn, false);
        if (!j.ok) { showErr('#dz-m-import', j.error); return; }
        modal('dz-m-import').hide();
        load(j);
      });
    });
    $('#dz-export-btn').on('click', function (e) {
      e.preventDefault();
      call('export').then(function (j) {
        if (!j.ok) { toast(j.error, 'error'); return; }
        $('#dz-exp-text').val(j.data.text);
        modal('dz-m-export').show();
      });
    });
    $('#dz-exp-copy').on('click', function () {
      var t = $('#dz-exp-text')[0]; t.select();
      try { navigator.clipboard.writeText(t.value).then(function () { toast('Copied.'); }, function () { document.execCommand('copy'); toast('Copied.'); }); }
      catch (x) { document.execCommand('copy'); toast('Copied.'); }
    });
    $('#dz-exp-dl').on('click', function () {
      var a = document.createElement('a');
      a.href = URL.createObjectURL(new Blob([$('#dz-exp-text').val()], { type: 'text/plain' }));
      a.download = Z.zone + '.zone';
      document.body.appendChild(a); a.click(); a.remove();
    });
    $('#dz-remove-btn').on('click', function (e) { e.preventDefault(); openRemove(D.zone, Z ? Z.account : ''); });

    /* ---------- mail provider (the account panel's helpers, for the zone's account) ---------- */
    var MP = null, mpDom = '', mpT = null, mpSeq = 0, mpDone = false;
    var LOGO = {
      google: '<svg viewBox="0 0 48 48" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>',
      microsoft: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="2" width="9.5" height="9.5" fill="#F25022"/><rect x="12.5" y="2" width="9.5" height="9.5" fill="#7FBA00"/><rect x="2" y="12.5" width="9.5" height="9.5" fill="#00A4EF"/><rect x="12.5" y="12.5" width="9.5" height="9.5" fill="#FFB900"/></svg>',
      zoho: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="0.6" y="8.6" width="5.2" height="6.8" rx="1" fill="#E42527" transform="rotate(-8 3.2 12)"/><rect x="6.6" y="8.6" width="5.2" height="6.8" rx="1" fill="#089949" transform="rotate(6 9.2 12)"/><rect x="12.4" y="8.6" width="5.2" height="6.8" rx="1" fill="#226DB4" transform="rotate(-6 15 12)"/><rect x="18.2" y="8.6" width="5.2" height="6.8" rx="1" fill="#F9B21D" transform="rotate(8 20.8 12)"/></svg>',
      ses: '<svg viewBox="0 0 24 24" aria-hidden="true"><text x="12" y="12.6" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="12" font-weight="700" letter-spacing="-.4" fill="currentColor">aws</text><path d="M2.6 15.4c5.6 3.6 12.8 3.8 17.8.5" fill="none" stroke="#FF9900" stroke-width="2" stroke-linecap="round"/><path d="M17.2 14.5l3.8.9-1 3.6" fill="none" stroke="#FF9900" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
      local: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="8" rx="3"/><rect x="3" y="12" width="18" height="8" rx="3"/><line x1="7" y1="8" x2="7" y2="8.01"/><line x1="7" y1="16" x2="7" y2="16.01"/></svg>',
      mail: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><polyline points="3 7 12 13 21 7"/></svg>'
    };
    function logo(id) { return $('<span class="dz-logo"></span>').html(LOGO[id] || LOGO.mail); }
    function mpSite(d) { return MP ? (MP.sites || []).filter(function (s) { return s.domain === d; })[0] : null; }
    function mpName(id) {
      if (id === 'local') return 'This server';
      if (id === 'other') return 'Another provider';
      return MP && MP.providers[id] ? MP.providers[id].name : id;
    }
    function mpShows(st) { return !st.mx || !st.mx.length ? 'No mail' : (st.provider === 'other' ? st.mx[0].host : mpName(st.provider)); }
    function mpLoad() {
      call('mail').then(function (j) {
        if (!j.ok || !j.data) return;
        MP = j.data;
        var ul = $('#dz-mp').empty();
        (MP.sites || []).forEach(function (st) {
          var li = $('<li></li>'), tx = $('<div class="dz-mp-t"></div>');
          li.append(logo(st.mx && st.mx.length ? st.provider : 'mail'));
          tx.append($('<b></b>').text(st.domain)).append($('<span class="text-muted"></span>').text(mpShows(st)));
          var links = $('<div></div>');
          ['spf', 'dmarc'].forEach(function (k) { links.append($('<button type="button" class="dz-linkish"></button>').attr('data-hp', k).attr('data-domain', st.domain).text(k.toUpperCase())); });
          tx.append(links);
          li.append(tx).append($('<button type="button" class="btn btn-white btn-sm">Change</button>').attr('data-mp', st.domain));
          ul.append(li);
        });
        $('#dz-mp-card').toggle(!!(MP.sites || []).length);
      });
    }
    var PM = $('#dz-m-mp'), PF = $('#dz-mp-form');
    function mpVal() { return PF.find('input[name=mp]:checked').val() || ''; }
    function mpBody(prev) {
      var id = mpVal(), b = { provider: id, preview: prev ? 1 : 0, verify: $.trim($('#dz-mp-verify').val()) };
      if (id === 'zoho') b.region = $('#dz-mp-region').val();
      if (id === 'microsoft') b.mx = '0 ' + $.trim($('#dz-mp-msmx').val());
      if (id === 'other') { b.mx = $('#dz-mp-mx').val(); b.spf = $.trim($('#dz-mp-spf').val()); }
      if (id === 'local') b.verify = '';
      return b;
    }
    function mpSync() {
      var id = mpVal();
      PF.find('[data-mp-for]').each(function () { $(this).toggle($(this).attr('data-mp-for').split(' ').indexOf(id) > -1); });
      PF.find('.dz-opt').each(function () { $(this).toggleClass('on', $(this).find('input').is(':checked')); });
      $('#dz-mp-save').text('Switch to ' + mpName(id));
      clearTimeout(mpT); mpT = setTimeout(mpPreview, 350);
    }
    function mpPlan(d, after) {
      var l = $('#dz-mp-plan-l').empty();
      (d.records || []).forEach(function (r) {
        var li = $('<li></li>').append($('<span class="dz-ty me-2"></span>').text(r.type)).append($('<span class="mono"></span>').text(r.name));
        (r.was || []).filter(function (v) { return r.values.indexOf(v) < 0; }).forEach(function (v) { li.append($('<div class="was"></div>').text(v)); });
        var come = r.values.filter(function (v) { return (r.was || []).indexOf(v) < 0; });
        come.forEach(function (v) { li.append($('<div class="new"></div>').text(v)); });
        if (!r.values.length) li.append('<div class="text-muted small">(removed)</div>');
        l.append(li);
      });
      $('#dz-mp-plan-h').text(!(d.records || []).length ? 'Nothing to change.' : (d.zone ? 'What changes' : 'Set these records where the DNS of ' + d.domain + ' is hosted'));
      var msg = [];
      if (d.remote && d.mailboxes && d.mail_here && !(after && d.switched === false))
        msg.push((d.mailboxes === 1 ? 'The mailbox of ' + d.domain + ' stays here' : 'The ' + d.mailboxes + ' mailboxes of ' + d.domain + ' stay here') + ' and can still be read in webmail, but new mail goes to ' + d.name + '.');
      $('#dz-mp-plan-w').toggle(!!msg.length).text(msg.join(' '));
      $('#dz-mp-plan').show();
    }
    function mpPreview() {
      var seq = ++mpSeq, btn = $('#dz-mp-save');
      btn.prop('disabled', true); showErr(PM, '');
      $('#dz-mp-plan-h').text('Checking…');
      call('mail-set', { domain: mpDom, b: mpBody(true) }).then(function (j) {
        if (seq !== mpSeq) return;
        if (!j.ok) { $('#dz-mp-plan').hide(); showErr(PM, j.error); return; }
        mpPlan(j.data);
        var st = mpSite(mpDom);
        btn.prop('disabled', !(j.data.records || []).length && !(st && st.mail_here && st.remote !== j.data.remote));
      });
    }
    function mpOpen(d) {
      var st = mpSite(d); if (!st) return;
      mpDom = d; mpDone = false;
      PF[0].reset(); showErr(PM, '');
      $('#dz-mp-h').text('Mail provider for ' + d);
      $('#dz-mp-now').text(!st.mx || !st.mx.length ? 'Now: no MX record' : 'Now: ' + mpName(st.provider) + (st.provider === 'local' || st.provider === 'other' ? ' (' + st.mx[0].host + ')' : ''));
      var box = $('#dz-mp-opts').empty();
      Object.keys(MP.providers).forEach(function (id) {
        box.append($('<label class="dz-opt"></label>').append($('<input type="radio" name="mp">').val(id)).append(logo(id)).append($('<span></span>').text(mpName(id))));
      });
      var cur = st.provider && MP.providers[st.provider] ? st.provider : 'local';
      box.find('input[value="' + cur + '"]').prop('checked', true);
      var rg = $('#dz-mp-region').empty();
      $.each((MP.providers.zoho || {}).regions || {}, function (k, v) { rg.append($('<option></option>').val(k).text(v)); });
      var zr = cur === 'zoho' && st.mx[0] ? /zoho(cloud)?\.[a-z.]+$/.exec(st.mx[0].host) : null;
      if (zr && rg.find('option[value="' + zr[0] + '"]').length) rg.val(zr[0]);
      $('#dz-mp-msmx').val(cur === 'microsoft' && st.mx[0] ? st.mx[0].host : d.replace(/\./g, '-') + '.mail.protection.outlook.com');
      if (cur === 'other') $('#dz-mp-mx').val(st.mx.map(function (m) { return m.priority + ' ' + m.host; }).join('\n'));
      $('#dz-mp-plan').hide(); $('#dz-mp-cancel').show();
      modal('dz-m-mp').show();
      mpSync();
    }
    PF.on('change', function (e) {
      if (e.target.name === 'mp') $('#dz-mp-verify').attr('placeholder', (MP.providers[mpVal()] || {}).verify || '');
      mpSync();
    });
    PF.on('input', 'input:not([type=radio]), textarea', function () { clearTimeout(mpT); mpT = setTimeout(mpPreview, 500); });
    PF.on('submit', function (e) {
      e.preventDefault();
      if (mpDone) { modal('dz-m-mp').hide(); return; }
      var btn = $('#dz-mp-save');
      busy(btn, true);
      call('mail-set', { domain: mpDom, b: mpBody(false) }).then(function (j) {
        busy(btn, false);
        if (!j.ok) { showErr(PM, j.error); return; }
        var d = j.data || {};
        if (d.zone) { modal('dz-m-mp').hide(); load(j); return; }
        said(j); mpLoad();
        mpDone = true; mpPlan(d, true);
        btn.text('Done').prop('disabled', false); $('#dz-mp-cancel').hide();
      });
    });
    $(document).on('click', '[data-mp]', function () { mpOpen($(this).attr('data-mp')); });

    /* SPF and DMARC helpers */
    var HP = { kind: '', domain: '', cur: null, rec: null, timer: null, seq: 0, done: false };
    function hpForm() { return $('#dz-m-' + HP.kind + ' form'); }
    function hpBody(prev) {
      var f = hpForm()[0], b = { preview: prev ? 1 : 0 };
      if (HP.kind === 'spf') {
        b.server = f.server.checked ? 1 : 0; b.mx = f.mx.checked ? 1 : 0; b.all = f.all.value; b.custom = f.custom.value;
        b.senders = $(f).find('input[name=sender]:checked').map(function () { return this.value; }).get();
      } else {
        b.p = $(f).find('input[name=p]:checked').val() || 'none'; b.sp = f.sp.value; b.pct = f.pct.value; b.rua = f.rua.value; b.adkim = f.adkim.value; b.aspf = f.aspf.value;
      }
      return b;
    }
    function hpShow(d, warnings) {
      var f = hpForm();
      f.find('[data-hp-rec]').text(d.record);
      f.find('[data-hp-name]').text(d.name || '');
      var info = [];
      if (d.lookups != null) info.push('Uses ' + d.lookups + ' of the 10 DNS lookups receivers allow (not counting those inside the includes).');
      if (!d.zone) info.push('Set this record where the DNS of ' + HP.domain + ' is hosted.');
      f.find('[data-hp-info]').text(info.join(' '));
      f.find('[data-hp-warn]').toggle(!!(warnings || []).length).text((warnings || []).join(' '));
      f.find('[data-hp-out]').show();
    }
    function hpPreview() {
      var seq = ++HP.seq, f = hpForm(), save = f.find('[data-hp-save]');
      save.prop('disabled', true); showErr(f, '');
      call(HP.kind + '-set', { domain: HP.domain, b: hpBody(true) }).then(function (j) {
        if (seq !== HP.seq) return;
        if (!j.ok) { f.find('[data-hp-out]').hide(); showErr(f, j.error); return; }
        hpShow(j.data, j.warnings);
        save.prop('disabled', !j.data.changed);
      });
    }
    function hpSync() {
      hpForm().find('.dz-opt').each(function () { $(this).toggleClass('on', $(this).find('input').is(':checked')); });
      clearTimeout(HP.timer); HP.timer = setTimeout(hpPreview, 350);
    }
    function hpOpen(kind, d, r) {
      HP.kind = kind; HP.domain = d; HP.rec = r || null; HP.done = false;
      var f = hpForm();
      f[0].reset(); showErr(f, '');
      f.find('[data-hp-h]').text((kind === 'spf' ? 'SPF' : 'DMARC') + ' of ' + d);
      f.find('[data-hp-now]').text('…');
      f.find('[data-hp-out]').hide();
      f.find('[data-hp-save]').prop('disabled', true).text('Save');
      modal('dz-m-' + kind).show();
      call(kind, { domain: d }).then(function (j) {
        if (!j.ok) { showErr(f, j.error); return; }
        var c = HP.cur = j.data, fe = f[0];
        f.find('[data-hp-now]').text(c.record || 'No record yet.');
        if (kind === 'spf') {
          var box = f.find('[data-hp-senders]').empty();
          $.each(c.senders_offered || {}, function (k, name) {
            box.append($('<label class="dz-opt"></label>').append($('<input type="checkbox" name="sender">').val(k).prop('checked', (c.senders || []).indexOf(k) > -1))
              .append(LOGO[k] ? logo(k) : '').append($('<span></span>').text(name)));
          });
          var st = mpSite(d) || {};
          fe.server.checked = c.record ? !!c.server : true;
          fe.mx.checked = c.record ? !!c.mx : !!st.mx_local;
          fe.custom.value = (c.custom || []).join(' ');
          fe.all.value = c.all || '~all';
        } else {
          f.find('input[name=p][value="' + (c.p || 'none') + '"]').prop('checked', true);
          fe.sp.value = c.sp || ''; fe.pct.value = c.pct || 100; fe.rua.value = (c.rua || []).join(', ');
          fe.adkim.value = c.adkim || 'r'; fe.aspf.value = c.aspf || 'r';
          if (!c.record) fe.rua.placeholder = 'dmarc@' + d;
        }
        hpSync();
      });
    }
    $('form.dz-hp').each(function () {
      var f = $(this);
      f.on('change', function () { if (!HP.done) hpSync(); });
      f.on('input', 'input:not([type=checkbox]):not([type=radio]), textarea', function () { if (!HP.done) hpSync(); });
      f.on('submit', function (e) {
        e.preventDefault();
        if (HP.done) { modal('dz-m-' + HP.kind).hide(); return; }
        var btn = f.find('[data-hp-save]');
        busy(btn, true);
        call(HP.kind + '-set', { domain: HP.domain, b: hpBody(false) }).then(function (j) {
          busy(btn, false);
          if (!j.ok) { showErr(f, j.error); return; }
          var d = j.data || {};
          if (d.zone) { modal('dz-m-' + HP.kind).hide(); load(j); return; }
          HP.done = true; hpShow(d, j.warnings);
          f.find('[data-hp-now]').text(d.record);
          btn.text('Done'); said(j);
        });
      });
    });
    $(document).on('click', '[data-hp]', function () { hpOpen($(this).attr('data-hp'), $(this).attr('data-domain')); });

    load();
  }
});
