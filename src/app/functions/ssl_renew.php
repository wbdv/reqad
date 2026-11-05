<?php
/*
 * Certificates certbot does not renew.
 *
 * A Let's Encrypt certificate issued here (its file is under /etc/letsencrypt/)
 * is renewed by certbot itself, about 30 days before it expires. A certificate
 * that came from elsewhere - copied by the cPanel transfer, or installed by
 * hand - is a plain file (/etc/ssl/certs/<domain>_newcert.pem) that nothing
 * renews. ssl_renew_kind() tells them apart:
 *   certbot  issued here: certbot renews it
 *   auto     a free certificate from elsewhere (Let's Encrypt, AutoSSL, ZeroSSL):
 *            once fewer than ssl_renew_days() are left, `reqad ssl renew`
 *            (cron, daily) gets the site a Let's Encrypt certificate in its
 *            place through ssl_site_issue(), so certbot renews it from then on.
 *            Only for active accounts whose package gives free certificates,
 *            and only for domains that point here (ssl_site_issue() checks).
 *            Never earlier: a certificate with more time left is not touched.
 *   manual   bought: renewed where it was bought, then installed here
 *   ''       none, or self-signed
 * The last failed attempt per domain is kept in the setting ssl-renew-state
 * (JSON domain => {t, e}), so the SSL pages and the health check can say why a
 * certificate was not replaced yet; a success removes it.
 */

/* Days before expiry when a free certificate from elsewhere is replaced
   (certbot renews its own the same time ahead). */
function ssl_renew_days() {
	return 30;
}

/* Is $issuer (the issuer's O, else CN) a free, automated certificate
   authority? Bought certificates from the same companies have other issuer
   names (AutoSSL's issuer is "cPanel, Inc.", a bought Sectigo one "Sectigo Limited"). */
function ssl_free_issuer($issuer) {
	return (bool)preg_match("/let'?s ?encrypt|^cpanel\b|zerossl/i", trim((string)$issuer));
}

/* certbot | auto | manual | '' for a certificate as domain_cert() gives it. */
function ssl_renew_kind($cert) {
	if (!is_array($cert) || !empty($cert['self_signed']))
		return '';
	if (!empty($cert['lets_encrypt']))
		return 'certbot';
	return ssl_free_issuer($cert['issuer'] ?? '') ? 'auto' : 'manual';
}

/* When the certificate gets renewed or replaced (unix time), 0 = not here. */
function ssl_renew_at($cert, $kind) {
	return in_array($kind, array('certbot', 'auto'), true) ? max(0, (int)$cert['until'] - ssl_renew_days() * 86400) : 0;
}

/* The last failed attempts: domain => array('t' => time, 'e' => error). */
function ssl_renew_state() {
	$s = json_decode((string)setting_get('ssl-renew-state', ''), true);
	return is_array($s) ? $s : array();
}

/* Note how the attempt for $domain went ('' = it worked, forget it). */
function ssl_renew_note($domain, $error) {
	$s = ssl_renew_state();
	if ($error === '')
		unset($s[$domain]);
	else
		$s[$domain] = array('t' => time(), 'e' => (string)$error);
	/* forget domains that are no site here any more */
	foreach ($s as $d => $x)
		if ((int)($x['t'] ?? 0) < time() - 90 * 86400)
			unset($s[$d]);
	setting_put('ssl-renew-state', $s ? json_encode($s, JSON_UNESCAPED_SLASHES) : '');
}

/*
 * Every site of every account with its certificate and how it renews:
 * rows of account, domain, active (the account is not suspended), free
 * (its package gives free certificates), cert (domain_cert()), kind (as
 * ssl_renew_kind(), 'auto' only with free), renew_at, days (left, -1 = none),
 * due (auto and fewer than ssl_renew_days() left), error / tried (the last
 * failed attempt). Sorted by expiry, soonest first.
 */
function ssl_renew_sites($account = '') {
	global $db, $ini;
	$accts = array();
	foreach (account_list($db) as $a)
		if ($account === '' || $a['user'] === $account)
			$accts[$a['user']] = $a;
	$sites = array();
	foreach ($accts as $u => $a)
		$sites[strtolower($a['domain'])] = $u;
	foreach (addon_domain_list($ini) as $d => $ad)
		if (isset($accts[$ad['user']]))
			$sites[strtolower($d)] = $ad['user'];
	$state = ssl_renew_state();
	$free = $rows = array();
	foreach ($sites as $d => $u) {
		if (!isset($free[$u]))
			$free[$u] = ssl_free_error($u) === '';
		$c = domain_cert($d);
		$kind = ssl_renew_kind($c);
		if ($kind === 'auto' && !$free[$u])
			$kind = 'manual';
		$days = $c ? (int)floor(((int)$c['until'] - time()) / 86400) : -1;
		$rows[] = array('account' => $u, 'domain' => $d, 'active' => ($accts[$u]['status'] ?? 'active') === 'active',
		                'free' => $free[$u], 'cert' => $c, 'kind' => $kind, 'renew_at' => $c ? ssl_renew_at($c, $kind) : 0,
		                'days' => $days, 'due' => $kind === 'auto' && (int)$c['until'] - time() < ssl_renew_days() * 86400,
		                'error' => (string)($state[$d]['e'] ?? ''), 'tried' => (int)($state[$d]['t'] ?? 0));
	}
	usort($rows, function ($x, $y) {
		$a = $x['cert'] ? (int)$x['cert']['until'] : PHP_INT_MAX;
		$b = $y['cert'] ? (int)$y['cert']['until'] : PHP_INT_MAX;
		return $a <=> $b ?: strcmp($x['domain'], $y['domain']);
	});
	return $rows;
}

/*
 * Replace the free certificates from elsewhere that expire within
 * ssl_renew_days() with Let's Encrypt ones (cron: `reqad ssl renew`, daily).
 * $o: dry_run (only list what would be asked), domain (just that one, also
 * when it is not due yet only if force), force, max (requests a run, default
 * 20: certbot runs one at a time, up to ~3 minutes each, and Let's Encrypt
 * limits new certificates per 3 hours). Suspended accounts wait until they are
 * active again. Returns result_ok with 'sites' => rows of domain, account,
 * until, issuer, result (issued | failed | due | later), message.
 */
function ssl_renew_run(array $o = array()) {
	$dry = !empty($o['dry_run']);
	$only = strtolower(trim((string)($o['domain'] ?? '')));
	$max = max(1, (int)($o['max'] ?? 20));
	$out = array();
	$asked = 0;
	foreach (ssl_renew_sites() as $s) {
		if ($only !== '' && $s['domain'] !== $only)
			continue;
		$force = $only !== '' && !empty($o['force']) && $s['kind'] === 'auto';
		if ((!$s['due'] && !$force) || !$s['active'])
			continue;
		$row = array('domain' => $s['domain'], 'account' => $s['account'], 'until' => (int)$s['cert']['until'],
		             'issuer' => (string)$s['cert']['issuer'], 'result' => '', 'message' => '');
		if ($dry) {
			$row['result'] = 'due';
			$row['message'] = $s['error'] !== '' ? 'last try '.date('Y-m-d H:i', $s['tried']).': '.$s['error'] : '';
		} elseif ($asked >= $max) {
			$row['result'] = 'later';
			$row['message'] = "at most $max a run; the next run asks for it";
		} else {
			$asked++;
			$r = ssl_site_issue($s['account'], $s['domain']);
			$row['result'] = $r['ok'] ? 'issued' : 'failed';
			$row['message'] = $r['ok'] ? $r['message'] : $r['error'];
			ssl_renew_note($s['domain'], $r['ok'] ? '' : $r['error']);
			route_log('ssl renew '.$s['domain'].' (was '.$row['issuer'].', until '.date('Y-m-d', $row['until']).'): '.$row['result']);
			if ($r['ok'])
				plugin_hook('ssl.renewed', array('account' => $s['account'], 'domain' => $s['domain'], 'was' => $row['issuer']));
		}
		$out[] = $row;
	}
	if ($only !== '' && !$out)
		return result_error("$only has no free certificate from elsewhere that is due (fewer than ".ssl_renew_days().' days left)'
		                    .(empty($o['force']) ? '; --force asks now if it has one' : '').'.');
	$n = count(array_filter($out, function ($r) { return $r['result'] === 'issued'; }));
	$f = count(array_filter($out, function ($r) { return $r['result'] === 'failed'; }));
	return result_ok($dry ? count($out).' certificate(s) due.' : "$n replaced, $f failed.", array('sites' => $out));
}
