/*
 * Settings > DNS Settings (templates/dns-settings.php): the script of each tab.
 * Only the open tab is on the page; each part looks for its own elements.
 */

/* ---- Provider (templates/dns-settings-provider.php) ---- */
(function () {
	if (!document.getElementById('dns-settings')) return;
	var esc = function (s) { return $('<span>').text(String(s)).html(); };
	$('.fadeout').delay(5000).fadeOut(2000);
	$('input[type=radio][name=dns-provider]').on('change', function () {
		$('#cloudflare-settings').toggle($('#cloudflare').prop('checked'));
		$('#cpanel-settings').toggle($('#cpanel').prop('checked'));
		$('#powerdns-settings').toggle($('input[name=dns-provider][value=powerdns]').prop('checked'));
	});
	$('input[type=radio][name=powerdns-mode]').on('change', function () {
		$('#powerdns-hidden-master-settings').toggle($('#pdns-mode-hidden-master').prop('checked'));
	});
	$('#pd-key-show').on('click', function () {
		var i = $('#pd-key'), shown = i.attr('type') === 'text';
		i.attr('type', shown ? 'password' : 'text');
		$(this).text(shown ? 'Show' : 'Hide');
	});
	$('#dns-settings').on('submit', function () {
		$('#submit-btn').prop('value', 'Saving...').prop('disabled', true);
	});

	/* the saved PowerDNS connection: answering, version, zones */
	function test() {
		var c = $('#pd-conn');
		if (!c.length) return;
		c.html('<span class="text-muted">checking&hellip;</span>');
		$.ajax({ url: '/', method: 'POST', dataType: 'json', headers: { 'X-CSRF-Token': window.CSRF }, data: { action: 'ajax-dns', op: 'provider-test' } }).done(function (j) {
			if (j.ok)
				c.html('<span class="badge border bg-green-lt">Connected</span><span>PowerDNS ' + esc(j.data.version || '?') + ' · ' + j.data.zones + (j.data.zones === 1 ? ' zone' : ' zones') + '</span>');
			else
				c.html('<span class="badge border bg-red-lt">Not connected</span><span class="text-danger">' + esc(j.error) + '</span>');
			c.append(' <a href="#" id="pd-retest">Test again</a>');
		}).fail(function () {
			c.html('<span class="badge border bg-red-lt">Not connected</span><span class="text-danger">the test did not answer</span> <a href="#" id="pd-retest">Test again</a>');
		});
	}
	$(document).on('click', '#pd-retest', function (e) { e.preventDefault(); test(); });
	test();
})();

/* ---- Cluster (templates/dns-settings-cluster.php) ---- */
(function () {
	if (!document.getElementById('dns-cluster')) return;
	/* the modals live outside the footer's unclosed div */
	$('#dns-cluster .dc-modal, #dc-remove-form').appendTo(document.body);

	$('#dc-m-remove').on('show.bs.modal', function (e) {
		var n = $(e.relatedTarget).attr('data-name');
		$('#dc-remove-name').val(n); $('#dc-remove-label').text(n);
	});
	$('#dc-copy').on('click', function () {
		var b = $(this);
		navigator.clipboard.writeText($('#dc-cmd').val()).then(function () { b.find('span').text('Copied'); });
	});
	/* just added: the setup command comes next */
	if (location.hash === '#setup' && $('#dc-m-setup').length) {
		bootstrap.Modal.getOrCreateInstance(document.getElementById('dc-m-setup')).show();
		try { history.replaceState(null, '', location.pathname); } catch (x) { /* fine */ }
	}

	/* "Confirm it's you" on Add: one dialog at a time */
	var away = null, sent = false;
	document.addEventListener('submit', function () { sent = true; }, true);
	$(document).on('show.bs.modal', '#modal-sudo', function () {
		sent = false; away = $('.dc-modal.show')[0] || null;
		if (away) bootstrap.Modal.getOrCreateInstance(away).hide();
	}).on('hidden.bs.modal', '#modal-sudo', function () {
		if (away && !sent) bootstrap.Modal.getOrCreateInstance(away).show();
		away = null;
	});

	/* Check now: the same check as every 5 minutes (it waits for the test change), then the page again */
	$('#dc-check-now').on('click', function () {
		var b = $(this).prop('disabled', true);
		b.find('span').text('Checking… up to 40 s');
		$.ajax({ url: '/', method: 'POST', dataType: 'json', timeout: 120000, headers: { 'X-CSRF-Token': window.CSRF }, data: { action: 'ajax-dns', op: 'cluster-check' } }).done(function (j) {
			if (j.ok) { location.reload(); return; }
			b.prop('disabled', false).find('span').text('Check now');
			alert(j.error);
		}).fail(function () {
			b.prop('disabled', false).find('span').text('Check now');
			alert('The check did not answer.');
		});
	});
})();

/* ---- API keys (templates/dns-settings-keys.php) ---- */
(function () {
	if (!document.getElementById('dns-keys')) return;

	/* the modals live outside the footer's unclosed div */
	document.querySelectorAll('#dns-keys .dk-modal, #dk-revoke-form, #dk-m-new').forEach(function (m) { document.body.appendChild(m); });
	var fresh = document.getElementById('dk-m-new');
	if (fresh) {
		var showFresh = function () { bootstrap.Modal.getOrCreateInstance(fresh).show(); };
		if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', showFresh); else showFresh();
	}

	/* a link to #key-<id> (after a change of its zones) marks that row */
	var hit = location.hash.match(/^#key-(\d+)$/);
	if (hit && document.getElementById('key-' + hit[1]))
		document.getElementById('key-' + hit[1]).classList.add('dk-target');

	/* "Confirm it's you" may open on a form inside a dialog: one dialog at a
	   time - that dialog steps aside and comes back if the confirmation is cancelled */
	var away = null, sent = false;
	document.addEventListener('submit', function () { sent = true; }, true);
	document.addEventListener('show.bs.modal', function (e) {
		if (e.target.id !== 'modal-sudo') return;
		sent = false;
		document.querySelectorAll('.dk-modal.show').forEach(function (m) { away = m; bootstrap.Modal.getOrCreateInstance(m).hide(); });
	});
	document.addEventListener('hidden.bs.modal', function (e) {
		if (e.target.id !== 'modal-sudo') return;
		if (away && !sent) bootstrap.Modal.getOrCreateInstance(away).show();
		away = null;
	});

	document.querySelectorAll('[data-copy]').forEach(function (b) {
		b.addEventListener('click', function () {
			var input = document.querySelector(b.getAttribute('data-copy'));
			navigator.clipboard.writeText(input.value).then(function () { b.querySelector('span').textContent = 'Copied'; });
		});
	});

	/* find a zone in a long list */
	document.querySelectorAll('.dk-zfilter').forEach(function (inp) {
		inp.addEventListener('input', function () {
			var q = inp.value.trim().toLowerCase();
			inp.parentNode.querySelectorAll('.dk-zone').forEach(function (r) { r.style.display = !q || r.getAttribute('data-z').indexOf(q) >= 0 ? '' : 'none'; });
		});
	});

	/* Revoke: confirmation dialog, one form for every row */
	var revokeModal = document.getElementById('dk-m-revoke');
	if (revokeModal) {
		revokeModal.addEventListener('show.bs.modal', function (e) {
			var b = e.relatedTarget, n = +b.getAttribute('data-zones');
			document.getElementById('dk-revoke-id').value = b.getAttribute('data-key');
			document.getElementById('dk-revoke-name').textContent = '"' + b.getAttribute('data-name') + '"';
			document.getElementById('dk-revoke-zones').textContent = n ? 'Its ' + (n === 1 ? 'zone stays' : n + ' zones stay') + ' on this server, and you can move them to another key.' : '';
		});
		document.getElementById('dk-revoke-form').addEventListener('submit', function () {
			document.getElementById('dk-revoke-submit').disabled = true;
		});
	}

	/* the switch submits its form at once; turning the API on may open the
	   confirmation dialog first, and cancelling it puts the switch back */
	document.querySelectorAll('#dns-keys .state-toggle').forEach(function (sw) {
		var form = sw.form, orig = sw.checked, done = false;
		form.addEventListener('submit', function () { done = true; });
		sw.addEventListener('change', function () {
			var b = form.querySelector('.badge'), l = form.querySelector('.form-check-label');
			b.textContent = sw.checked ? 'Active' : 'Disabled';
			b.classList.toggle('bg-green-lt', sw.checked); b.classList.toggle('bg-red-lt', !sw.checked);
			l.classList.toggle('text-red', !sw.checked);
			if (form.requestSubmit) form.requestSubmit(); else form.submit();
		});
		document.addEventListener('hidden.bs.modal', function (e) {
			if (e.target.id === 'modal-sudo' && !done && sw.checked !== orig) { sw.checked = orig; location.reload(); }
		});
	});
})();
