<?php
/*
 * Domains of a hosting account, for the API (and so MCP and the account
 * panel on 2083): its sites - the main domain and its addon domains (a
 * subdomain is an addon domain too: its own folder and vhost) -, the alias
 * domains of the main site, and the certificates they are served with.
 *
 * Addon domains are made by scripts/adddomain and removed by
 * scripts/deldomain (both root); the vhost file is their inventory
 * (addon_domain_list()). Acting for an account, nothing is made inside its
 * home by root: the document root is made AS the account (files_mkdir, the
 * fm-helper) and adddomain gets --docroot-ready, so it only gives a folder
 * right under the home the web server's group (chgrp -h, no symlink is
 * followed). The files of a removed domain are deleted the same way
 * (files_delete). Aliases are rows of the aliases table, written into the
 * main vhost's server names (apply_account_vhost_names()).
 *
 * Certificates: Let's Encrypt over HTTP-01 (certbot with the front server's
 * plugin, as the SSL page of 2087 does), only for the names that already
 * point at this server, so a request that cannot pass is not sent (Let's
 * Encrypt counts failed validations).
 *
 * DNS: when the zone is at the DNS provider of Settings > DNS, a subdomain or
 * an alias gets its A record (dns_rrset_get/set; never a new zone), and it
 * goes again when the name is removed, if it still points here.
 */

/* Top-level folders of a home a new site cannot use. */
function domain_reserved_folders() {
	return array('public_html', 'www', 'mail', 'logs', 'tmp', 'etc', 'apps', 'ssl', '.ssh', 'wp-backups', 'backups');
}

/* The addresses that are "this server" for an account's sites: every global
   address of the server, and the account's own public ones (Hosting Pro,
   behind NAT the address outside). */
function domain_server_ips($account = '') {
	$ips = array();
	foreach (explode("\n", (string)shell_exec('/usr/sbin/ip -o addr show scope global 2>/dev/null')) as $l)
		if (preg_match('/\binet6?\s+([0-9a-f.:]+)\//i', $l, $m))
			$ips[] = strtolower($m[1]);
	if ($account !== '' && function_exists('hosting_enabled') && hosting_enabled() && function_exists('hosting_account_ips'))
		foreach (hosting_account_ips($account) as $ip)
			if ($ip)
				foreach (array('public', 'address') as $k)
					if (($ip[$k] ?? '') !== '')
						$ips[] = strtolower($ip[$k]);
	return array_values(array_unique($ips));
}

/* The IPv4 address an account's sites should point at. */
function domain_site_ip($account) {
	if (function_exists('hosting_enabled') && hosting_enabled() && function_exists('hosting_account_ips')) {
		$own = hosting_account_ips($account);
		if (!empty($own[4]))
			return ($own[4]['public'] ?? '') !== '' ? $own[4]['public'] : $own[4]['address'];
	}
	return server_primary_ip();
}

/* Where names point: name => array(ips (A and AAAA, CNAMEs followed),
   here (one of them is this server)). Asked in parallel, 3 s at most. */
function domain_points(array $names, $account = '') {
	$here = domain_server_ips($account);
	$run = array();
	foreach (array_unique($names) as $n) {
		$n = strtolower((string)$n);
		if (!valid_domain($n))
			continue;
		foreach (array('A', 'AAAA') as $t) {
			$p = proc_open(array('dig', '+short', '+time=2', '+tries=1', $t, $n), array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes);
			if (is_resource($p))
				$run[] = array($n, $p, $pipes[1]);
		}
	}
	$out = array();
	foreach ($run as $r) {
		list($n, $p, $o) = $r;
		$out[$n] = $out[$n] ?? array('ips' => array(), 'here' => false);
		foreach (explode("\n", (string)stream_get_contents($o)) as $l) {
			$l = strtolower(trim($l));
			if (filter_var($l, FILTER_VALIDATE_IP))
				$out[$n]['ips'][] = $l;
		}
		fclose($o);
		proc_close($p);
	}
	foreach ($out as $n => $v) {
		$out[$n]['ips'] = array_values(array_unique($v['ips']));
		$out[$n]['here'] = (bool)array_intersect($out[$n]['ips'], $here);
	}
	return $out;
}

/* The account's domains a site or alias can be a subdomain of: the main
   domain, its aliases (no wildcards) and its addon domains. */
function domain_account_names($account) {
	global $db, $ini;
	$acct = account_get($db, $account);
	if (!$acct)
		return array();
	$names = array(strtolower($acct['domain']));
	foreach (get_aliases($db, $acct['id']) as $a)
		if ((int)$a['is_wildcard'] !== 1)
			$names[] = strtolower($a['alias']);
	foreach (addon_domain_list($ini) as $d => $a)
		if ($a['user'] === $account)
			$names[] = strtolower($d);
	return array_values(array_unique($names));
}

/* The account's domain $name is a subdomain of ('' = none). $names:
   domain_account_names($account), when the caller asks for several. */
function domain_parent_of($account, $name, array $names = null) {
	$best = '';
	foreach ($names ?? domain_account_names($account) as $d)
		if (substr($name, -strlen($d) - 1) === '.'.$d && strlen($d) > strlen($best))
			$best = $d;
	return $best;
}

/* '' when $account may give a new site or alias the name $name, else why
   not. Never the server's own name (or a name under it), a domain, alias,
   addon or mail domain of any account, a name that already has a vhost, or
   a subdomain of another account's domain. "*.example.com" (aliases only)
   only for the account's own domains. */
function domain_claim_error($account, $name) {
	global $db, $ini;
	$name = strtolower(rtrim(trim((string)$name), '.'));
	$wild = substr($name, 0, 2) === '*.';
	$bare = $wild ? substr($name, 2) : $name;
	if ($bare === '' || !valid_domain($bare) || strpos($bare, '.') === false)
		return 'Enter a domain name such as example.com.';
	$host = strtolower(trim((string)shell_exec('hostname')));
	if ($host !== '' && ($bare === $host || substr($bare, -strlen($host) - 1) === '.'.$host))
		return $bare === $host ? "$bare is the name of this server, so it cannot be used for a site." : "$bare is under $host, the name of this server, so it cannot be used for a site.";
	if ($wild) {
		if (scope_domain_owner($bare) !== $account)
			return "A wildcard alias works only for one of your own domains, such as *.".(account_get($db, $account)['domain'] ?? 'example.com').'.';
		$st = $db->prepare('SELECT 1 FROM aliases WHERE alias = :a');
		$st->bindValue(':a', $name, SQLITE3_TEXT);
		$res = $st->execute();
		return $res && $res->fetchArray() ? "$name is already an alias." : '';
	}
	$owner = scope_domain_owner($bare);
	if ($owner !== '')
		return $owner === $account ? "$bare is already one of your domains." : "$bare is used by another account on this server.";
	if (($e = dns_key_domain_error($bare, !defined('RA_STATE') && (string)($GLOBALS['api_account'] ?? '') === '')) !== '')
		return $e;
	if (preg_match('/^mail\.(.+)$/', $bare, $m) && account_get($db, '', $m[1]))
		return "$bare is the mail server name of {$m[1]}.";
	foreach (array_unique(array(web_front($ini), web_php_server($ini))) as $srv)
		if (is_file(web_vhost_path($bare, $srv)))
			return "$bare already has a site on this server.";
	for ($p = $bare; ($i = strpos($p, '.')) !== false && strpos($p = substr($p, $i + 1), '.') !== false; ) {
		$o = scope_domain_owner($p);
		if ($o !== '' && $o !== $account)
			return "$bare is under $p, a domain of another account on this server.";
	}
	return '';
}

/* '' when $folder (relative to the home) can hold a new site: a folder right
   in the home (shop.example.com) or one inside public_html (public_html/shop). */
function domain_folder_error($folder) {
	$segs = explode('/', $folder);
	if ($folder === '' || count($segs) > 4)
		return 'Choose a folder in your home folder, at most four levels deep.';
	foreach ($segs as $s)
		if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $s))
			return 'Folder names can have letters, digits, dots, dashes and underscores.';
	if ($segs[0] === 'public_html')
		return count($segs) === 1 ? 'public_html is the folder of your main site; choose a folder inside it, such as public_html/shop.' : '';
	if (count($segs) > 1)
		return 'Use a folder right in your home folder (shop.example.com) or one inside public_html (public_html/shop).';
	if (in_array(strtolower($segs[0]), domain_reserved_folders(), true))
		return "~/{$segs[0]} is used for other things; choose another folder.";
	return '';
}

/* Make $folder (relative) AS the account, each missing level in turn; a
   folder right in the home gets mode 0750 (adddomain gives its group to the
   web server). array(error or '', made: the levels made, outermost first). */
function domain_folder_make($account, $folder) {
	$made = array();
	$segs = explode('/', $folder);
	for ($i = 1; $i <= count($segs); $i++) {
		$rel = implode('/', array_slice($segs, 0, $i));
		$st = files_run($account, array('verb' => 'stat', 'path' => $rel));
		if (!empty($st['ok'])) {
			$t = $st['entry']['t'] ?? '';
			if ($t !== 'd')
				return array("~/$rel is ".($t === 'l' ? 'a link' : 'a file').', not a folder; choose another folder.', $made);
			continue;
		}
		if (($st['code'] ?? '') !== 'missing')
			return array((string)($st['error'] ?? "~/$rel could not be checked."), $made);
		$r = files_mkdir($account, $rel);
		if (!$r['ok'])
			return array($r['error'], $made);
		$made[] = $rel;
	}
	if (count($segs) === 1) {
		$r = files_chmod($account, $folder, '750');
		if (!$r['ok'])
			return array($r['error'], $made);
	}
	return array('', $made);
}

/* "~/x" for an absolute path in the home, else the path */
function domain_tilde($path, $account) {
	$home = '/home/'.$account;
	return $path === $home ? '~' : (strpos($path, $home.'/') === 0 ? '~/'.substr($path, strlen($home) + 1) : $path);
}

/* The account's sites, main domain first: domain, kind (main|addon),
   subdomain_of ('' or the account's domain it is under), docroot (absolute),
   folder (~/...), www (www.<domain> is served too), aliases (main only),
   created (addon domains), cert (domain_cert(), null = none). */
function domain_sites($account, $certs = true) {
	global $db, $ini;
	$acct = account_get($db, $account);
	if (!$acct)
		return array();
	$main = strtolower($acct['domain']);
	$aliases = array_column(get_aliases($db, $acct['id']), 'alias');
	$on = domain_mail_available($account) === '';
	$mail = $on ? email_userdomains() : array();
	$prefs = $on ? domain_mail_prefs($account) : array();
	$had = $on ? domain_mail_had() : array();
	$sites = array(array('domain' => $main, 'kind' => 'main', 'subdomain_of' => '', 'docroot' => '/home/'.$account.'/public_html',
	                     'folder' => '~/public_html', 'www' => in_array('www.'.$main, $aliases, true),
	                     'aliases' => array_values(array_diff($aliases, array('www.'.$main))), 'created' => (string)($acct['created'] ?? ''),
	                     'mail' => ($mail[$main] ?? '') === $account, 'mail_state' => $on ? 'on' : '',
	                     'cert' => $certs ? domain_cert($main) : null));
	$names = domain_account_names($account);
	foreach (addon_domain_list($ini) as $d => $a) {
		if ($a['user'] !== $account)
			continue;
		$conf = account_vhost_file_read($a['vhost']);
		$sub = '';
		foreach ($names as $n)
			if ($n !== $d && substr($d, -strlen($n) - 1) === '.'.$n && strlen($n) > strlen($sub))
				$sub = $n;
		$has = ($mail[$d] ?? '') === $account;
		$sites[] = array('domain' => $d, 'kind' => 'addon', 'subdomain_of' => $sub, 'docroot' => (string)$a['docroot'],
		                 'folder' => domain_tilde((string)$a['docroot'], $account),
		                 'www' => (bool)preg_match('/^\s*(?:server_name|ServerAlias)\s[^\n;]*\bwww\.'.preg_quote($d, '/').'\b/mi', $conf),
		                 'aliases' => array(), 'created' => (string)$a['created'], 'mail' => $has,
		                 /* on | off (chosen) | missing (should have mail, has none) | '' (the account has no email) */
		                 'mail_state' => !$on ? '' : ($has ? 'on' : (domain_mail_wanted($d, $sub, $prefs, $had) ? 'missing' : 'off')),
		                 'cert' => $certs ? domain_cert($d) : null);
	}
	return $sites;
}

function domain_site($account, $domain) {
	foreach (domain_sites($account, false) as $s)
		if ($s['domain'] === strtolower(trim((string)$domain)))
			return $s;
	return null;
}

/* local_ssl_cert_info(), without its five shell calls when running as root
   (the account panel's broker): the vhosts and certificates are read
   directly. The files are server configuration, not inside a home. */
function domain_cert_info($domain) {
	if (!function_exists('posix_geteuid') || posix_geteuid() !== 0 || !valid_domain($domain))
		return local_ssl_cert_info($domain);
	/* the last one: certbot's own https copy of an Apache vhost that had no :443
	   block (scripts/update/merge_le_ssl_vhosts.sh moves it into the site's file) */
	foreach (array('/etc/reqad/conf.d/'.$domain.'.conf', '/etc/nginx/conf.d/'.$domain.'.conf', '/etc/httpd/conf.d/'.$domain.'.conf',
	               '/etc/httpd/conf.d/'.$domain.'-le-ssl.conf') as $file) {
		$conf = @file_get_contents($file);
		if ($conf === false || !preg_match('/^[ \t]*(?:ssl_certificate|SSLCertificateFile)[ \t]+"?([^";\s]+)/m', $conf, $m) || !is_file($m[1]))
			continue;
		$pem = (string)@file_get_contents($m[1]);
		$c = strpos($pem, '-----BEGIN CERTIFICATE-----') !== false ? @openssl_x509_parse($pem) : false;
		if (!is_array($c) || !isset($c['validTo_time_t']))
			return false;
		$c['_file'] = $m[1];
		return $c;
	}
	return false;
}

/* The certificate the site of $domain is served with: issuer, until (unix
   time), expired, self_signed, lets_encrypt, names (what it covers); null
   when its vhost points at none. */
function domain_cert($domain) {
	$c = domain_cert_info($domain);
	if ($c === false)
		return null;
	$names = array();
	if (preg_match_all('/DNS:([^,\s]+)/', (string)($c['extensions']['subjectAltName'] ?? ''), $m))
		$names = array_values(array_unique(array_map('strtolower', $m[1])));
	elseif (!empty($c['subject']['CN']))
		$names = array(strtolower((string)$c['subject']['CN']));
	$until = (int)$c['validTo_time_t'];
	$self = ssl_is_self_signed($c);
	return array('issuer' => $self ? '' : (string)($c['issuer']['O'] ?? ($c['issuer']['CN'] ?? '')), 'until' => $until,
	             'expired' => $until < time(), 'self_signed' => $self,
	             'lets_encrypt' => strpos((string)$c['_file'], '/etc/letsencrypt/') === 0, 'names' => $names);
}

/* Is $name covered by a certificate for $names (exactly, or by a *.x)? */
function domain_cert_covers(array $names, $name) {
	return in_array($name, $names, true) || alias_is_covered($name, $names);
}

/* The names a site's certificate should cover: the domain, www., for the
   main site its aliases, and mail.<domain> when the site has mail (mail
   programs connect to it). Wildcards apart: array(names, wildcards). */
function domain_site_cert_names($account, array $site) {
	global $db;
	$names = array($site['domain']);
	$wild = array();
	if ($site['www'])
		$names[] = 'www.'.$site['domain'];
	if ($site['kind'] === 'main') {
		foreach ($site['aliases'] as $a) {
			if (substr($a, 0, 2) === '*.')
				$wild[] = $a;
			else
				$names[] = $a;
		}
		$acct = account_get($db, $account);
		if ($acct && ($acct['has_email'] === 'true' || $acct['has_email'] == 1))
			$names[] = 'mail.'.$site['domain'];
	} elseif (!empty($site['mail']))
		$names[] = 'mail.'.$site['domain'];
	return array(array_values(array_unique($names)), $wild);
}

/* The DNS provider's zone holds $name: array(provider, values of its A set) or null. */
function domain_dns_a($name) {
	global $db;
	if (dns_provider_load($db) === 'none' || !function_exists('dns_rrset_get'))
		return null;
	$v = dns_rrset_get($name, 'A');
	return is_array($v) ? $v : null;
}

/* A new name of the account: its A record at the DNS provider when its zone
   is there and the name has no A record yet. 'added', 'exists' (it has one
   already), 'none' (no zone for it at the provider) or 'Error: ...'. */
function domain_dns_add($account, $name) {
	$cur = domain_dns_a($name);
	if ($cur === null)
		return 'none';
	if ($cur)
		return 'exists';
	$r = dns_rrset_set($name, 'A', array(domain_site_ip($account)));
	return $r === '' ? 'added' : (string)$r;
}

/* A name that goes: its A record, when it still points only here. */
function domain_dns_remove($account, $name) {
	$cur = domain_dns_a($name);
	if (!$cur || array_diff($cur, domain_server_ips($account)))
		return '';
	$r = dns_rrset_set($name, 'A', array());
	return $r === '' ? 'removed' : '';
}

/* A note for a new name that does not point here yet ('' when it does). */
function domain_points_note($account, $name, array $p) {
	if (!empty($p['here']))
		return '';
	$ip = domain_site_ip($account);
	return !empty($p['ips'])
		? "$name points to ".implode(', ', $p['ips']).", not to this server: change its A record to $ip."
		: "$name has no address in DNS yet: give it an A record with $ip.";
}

/* ---- Mail of an addon site --------------------------------------------------
   A site's mail is on when /etc/exim/userdomains maps its domain to the
   account (account_email_enable() writes that line with /etc/exim/domains,
   the forwards file and the DKIM keys). The site's vhost then also answers to
   mail.<domain>, so its certificate can cover the name mail programs use.

   On or off per site: the account's switch (accounts.has_email) is the
   master - off, no site has mail. On, each addon site follows its site_mail
   row (db 1050): chosen when the site was made (addon domains on, subdomains
   off) or turned on/off since. Without a row: on when it had mail before
   (/etc/exim/domains/<domain>), else on for an addon domain, off for a
   subdomain. A site that should have mail but has no userdomains line is a
   problem: 'missing' (health check site_mail, "Fix mail problem"). */

/* The sites' chosen mail: domain => true/false ('' = every account's). */
function domain_mail_prefs($account = '') {
	global $db;
	$out = array();
	if (!($db instanceof SQLite3))
		return $out;
	$stmt = @$db->prepare('SELECT domain, mail FROM site_mail'.($account !== '' ? ' WHERE account = :a' : ''));
	if (!$stmt)
		return $out;
	if ($account !== '')
		$stmt->bindValue(':a', $account, SQLITE3_TEXT);
	$res = $stmt->execute();
	while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC)))
		$out[$r['domain']] = (int)$r['mail'] === 1;
	return $out;
}

/* The mail domains this server keeps files for (/etc/exim/domains): domain => true. */
function domain_mail_had() {
	$out = array();
	foreach (explode("\n", trim((string)shell_exec('sudo -n ls -1 /etc/exim/domains/ 2>/dev/null'))) as $d)
		if ($d !== '')
			$out[strtolower($d)] = true;
	return $out;
}

/* Should the addon site $domain have mail when its account has email?
   $sub: what domain_parent_of() says ('' = not a subdomain of the account's
   domains). $prefs / $had: domain_mail_prefs() / domain_mail_had(), when the
   caller asks for several sites. */
function domain_mail_wanted($domain, $sub, array $prefs = null, array $had = null) {
	$prefs = $prefs ?? domain_mail_prefs();
	if (isset($prefs[$domain]))
		return $prefs[$domain];
	$had = $had ?? domain_mail_had();
	return isset($had[$domain]) || $sub === '';
}

/* Remember whether the addon site $domain should have mail. */
function domain_mail_want_set($account, $domain, $on) {
	global $db;
	if (!($db instanceof SQLite3))
		return;
	$stmt = @$db->prepare('INSERT OR REPLACE INTO site_mail (domain, account, mail, changed) VALUES (:d, :a, :m, :t)');
	if (!$stmt)
		return;
	$stmt->bindValue(':d', $domain, SQLITE3_TEXT);
	$stmt->bindValue(':a', $account, SQLITE3_TEXT);
	$stmt->bindValue(':m', $on ? 1 : 0, SQLITE3_INTEGER);
	$stmt->bindValue(':t', time(), SQLITE3_INTEGER);
	$stmt->execute();
}

/* A removed site (or account: $account set) leaves no choice behind. */
function domain_mail_forget($domain, $account = '') {
	global $db;
	if (!($db instanceof SQLite3))
		return;
	$stmt = @$db->prepare($account !== '' ? 'DELETE FROM site_mail WHERE account = :v' : 'DELETE FROM site_mail WHERE domain = :v');
	if (!$stmt)
		return;
	$stmt->bindValue(':v', $account !== '' ? $account : strtolower((string)$domain), SQLITE3_TEXT);
	$stmt->execute();
}

/* '' when the account may have mail on its sites, else why not. */
function domain_mail_available($account) {
	global $db, $ini;
	if (($ini['email'] ?? '') != 1)
		return 'This server does not handle email.';
	$acct = account_get($db, $account);
	if (!$acct || !($acct['has_email'] === 'true' || $acct['has_email'] == 1))
		return 'Email is off for this account.';
	return '';
}

/* Add (or drop) mail.<domain> in the server names of an addon site's vhost,
   and of its backend vhost. '' or the error. */
function domain_vhost_mail_name($domain, $on) {
	global $ini;
	$mail = 'mail.'.$domain;
	$fix = function ($names) use ($mail, $on) {
		$list = array_values(array_diff(preg_split('/\s+/', trim($names)), array($mail, '')));
		if ($on)
			$list[] = $mail;
		return implode(' ', $list);
	};
	$servers = array(web_front($ini) => addon_vhost_path($ini, $domain));
	if (web_backend() !== 'none')
		$servers[web_backend()] = web_vhost_path($domain, web_backend());
	foreach ($servers as $server => $file) {
		$orig = account_vhost_file_read($file);
		if (trim($orig) === '')
			continue;
		$new = $server === 'nginx'
			? preg_replace_callback('/^([ \t]*server_name[ \t]+)([^;]*);/m', function ($m) use ($fix) { return $m[1].$fix($m[2]).';'; }, $orig)
			: preg_replace_callback('/^([ \t]*ServerAlias[ \t]+)(.*)$/m', function ($m) use ($fix) { return $m[1].$fix($m[2]); }, $orig);
		if ($new !== null && $new !== $orig && ($e = account_vhost_apply($file, $new, $orig, $server)) !== '')
			return $e;
	}
	return '';
}

/*
 * Turn on mail for an addon site (or subdomain) of the account: its
 * mailboxes, forwarders and autoresponders can then be made. Its mail DNS
 * records (MX when it has none, SPF, DKIM, DMARC) and mail.<domain> are added
 * when its zone is at the DNS provider; a Let's Encrypt certificate gets
 * mail.<domain> the next time it is issued (ssl_site_issue()).
 */
function domain_mail_enable($account, $domain) {
	global $db, $ini;
	$domain = strtolower(trim((string)$domain));
	$a = addon_domain_get($ini, $domain);
	if (!$a || $a['user'] !== $account)
		return result_error("$domain is not an addon domain of $account.");
	if (($e = domain_mail_available($account)) !== '')
		return result_error($e);
	$owner = email_userdomains()[$domain] ?? '';
	if ($owner === $account) {
		domain_mail_want_set($account, $domain, true);
		return result_ok("Email is already on for $domain.", array('domain' => $domain, 'dns' => 'none'));
	}
	if ($owner !== '')
		return result_error("$domain gets its mail through another account on this server.");
	route_log("account $account: mail on for $domain");
	$warn = array_values(array_filter(account_email_enable($domain, $account, true, false), 'strlen'));
	if ((email_userdomains()[$domain] ?? '') !== $account)
		return result_error("Email could not be turned on for $domain.");
	domain_mail_want_set($account, $domain, true);
	if (($e = domain_vhost_mail_name($domain, true)) !== '')
		$warn[] = "mail.$domain was not added to the site: $e";

	$dns = 'none';
	if (domain_dns_a($domain) !== null) {
		$r = mail_dns_fix($domain);
		$dns = $r['ok'] ? 'added' : 'error';
		if (!$r['ok'])
			$warn[] = 'Its mail DNS records were not added: '.$r['error'];
		$warn = array_merge($warn, $r['warnings'] ?? array());
		$m = domain_dns_add($account, 'mail.'.$domain);
		if (strpos($m, 'Error') === 0)
			$warn[] = "The DNS record of mail.$domain was not made: ".preg_replace('/^Error:\s*/', '', $m);
	} else
		$warn[] = "The DNS of $domain is not on this server: add its mail records (MX, SPF, DKIM, DMARC) where it is hosted. Mail DNS lists them.";
	plugin_hook('mail_domain.created', array('account' => $account, 'domain' => $domain));
	/* reissue: its Let's Encrypt certificate does not cover mail.<domain> yet
	   (ssl_site_issue() adds it; the caller asks, it takes a while) */
	$c = domain_cert($domain);
	$reissue = $c && $c['lets_encrypt'] && !$c['expired'] && !domain_cert_covers($c['names'], 'mail.'.$domain);
	return result_ok("Email is on for $domain.", array('domain' => $domain, 'dns' => $dns, 'reissue' => $reissue), $warn);
}

/*
 * Turn off mail for an addon site (or subdomain): refused while it has
 * mailboxes. Its forwarders, autoresponders, domain filters and DKIM keys
 * go (domain_mail_remove()), mail.<domain> leaves its server names, and it
 * stays off when the account's email is turned off and on again. When its
 * zone is at the DNS provider and its mail came here (or it has no MX), its
 * MX, its DKIM record and mail.<domain> go, SPF becomes "v=spf1 -all" and a
 * DMARC record this server wrote becomes p=reject: the name sends no mail.
 * A domain whose MX points elsewhere keeps its DNS as it is. data.reissue:
 * its Let's Encrypt certificate covers mail.<domain>, so the caller has it
 * issued again (ssl_site_issue()); otherwise renewing it would fail once
 * mail.<domain> is gone from DNS.
 */
function domain_mail_disable($account, $domain) {
	global $ini;
	$domain = strtolower(trim((string)$domain));
	$a = addon_domain_get($ini, $domain);
	if (!$a || $a['user'] !== $account)
		return result_error("$domain is not an addon domain of $account.");
	if (($ini['email'] ?? '') != 1)
		return result_error('This server does not handle email.');
	$owner = email_userdomains()[$domain] ?? '';
	if ($owner !== '' && $owner !== $account)
		return result_error("$domain gets its mail through another account on this server.");
	$boxes = domain_mailboxes($domain);
	if ($boxes)
		return result_error(count($boxes) === 1
			? "$domain still has the mailbox {$boxes[0]}. Delete it in Email first."
			: "$domain still has ".count($boxes)." mailboxes. Delete them in Email first.");
	$had = $owner === $account || email_domain_exists($domain);
	route_log("account $account: mail off for $domain");
	$warn = array();
	/* DNS first: the check compares DKIM with the key that is about to go */
	$dns = $had ? domain_mail_dns_off($account, $domain, $warn) : array();
	if ($had)
		domain_mail_remove($account, $domain);
	if (($e = domain_vhost_mail_name($domain, false)) !== '')
		$warn[] = "mail.$domain is still a name of the site: $e";
	domain_mail_want_set($account, $domain, false);
	if ($had)
		plugin_hook('mail_domain.deleted', array('account' => $account, 'domain' => $domain));
	$c = domain_cert($domain);
	$reissue = $c && $c['lets_encrypt'] && !$c['expired'] && domain_cert_covers($c['names'], 'mail.'.$domain);
	return result_ok("Email is off for $domain.", array('domain' => $domain, 'dns' => $dns, 'reissue' => $reissue), $warn);
}

/* The DNS part of domain_mail_disable(): what was changed (mx, dkim, spf,
   dmarc, mail), problems added to $warn. */
function domain_mail_dns_off($account, $domain, array &$warn) {
	$c = mail_dns_check($domain);
	if (!$c)
		return array();
	if ($c['mx'] && empty($c['mx_local']))
		return array();
	if (domain_dns_a($domain) === null) {
		if ($c['mx'])
			$warn[] = "The DNS of $domain is not on this server: remove its MX record where it is hosted, and set its SPF record to v=spf1 -all.";
		return array();
	}
	$done = array();
	$set = function ($what, $name, $type, array $values) use (&$done, &$warn) {
		$e = (string)dns_rrset_set($name, $type, $values);
		if ($e === '')
			$done[] = $what;
		else
			$warn[] = strtoupper($what).' was not changed: '.preg_replace('/^Error:\s*/', '', $e);
	};
	if ($c['mx'])
		$set('mx', $domain, 'MX', array());
	if (($c['dkim'] ?? '') !== '' && ($c['dkim_match'] ?? null) === true)
		$set('dkim', $c['dkim_selector'].'._domainkey.'.$domain, 'TXT', array());
	$txt = dns_rrset_get($domain, 'TXT');
	if (is_array($txt)) {
		$new = array_values(array_filter($txt, function ($v) { return stripos(ltrim($v), 'v=spf1') !== 0; }));
		$new[] = 'v=spf1 -all';
		if ($new != $txt)
			$set('spf', $domain, 'TXT', $new);
	}
	$dm = dns_rrset_get('_dmarc.'.$domain, 'TXT');
	$ours = preg_replace('/\s+/', '', mail_dns_expected($domain, '', '')['dmarc']['value']);
	if (is_array($dm) && (!$dm || (count($dm) === 1 && preg_replace('/\s+/', '', $dm[0]) === $ours)))
		$set('dmarc', '_dmarc.'.$domain, 'TXT', array('v=DMARC1; p=reject;'));
	if (domain_dns_remove($account, 'mail.'.$domain) === 'removed')
		$done[] = 'mail';
	return $done;
}

/* An addon site's mail goes with the site: its userdomains line, exim files,
   DKIM keys, domain filters and autoresponder rows. Mailboxes are not
   deleted here (domain_addon_delete() refuses while there are any) except
   with $mailboxes, when the whole account goes. */
function domain_mail_remove($account, $domain, $mailboxes = false) {
	global $db;
	if ((email_userdomains()[$domain] ?? '') === $account)
		shell_exec('sudo -n sed -i '.escapeshellarg('/^'.sed_bre_escape($domain).':'.$account.'$/d').' /etc/exim/userdomains');
	foreach (array('/etc/exim/domains/'.$domain, '/etc/exim/forwards/'.$domain, '/etc/exim/keys/'.$domain.'.private.key',
	               '/etc/exim/keys/'.$domain.'.public.key', '/var/lib/reqad/sieve/domains/'.$domain.'.sieve',
	               '/var/lib/reqad/sieve/domains/'.$domain.'.svbin') as $f)
		shell_exec('sudo -n rm -f '.escapeshellarg($f));
	shell_exec('sudo -n rm -rf '.escapeshellarg('/etc/exim/autoreply/'.$domain));
	mail_remote_set($domain, false);
	if ($mailboxes)
		shell_exec('sudo -n sed -i '.escapeshellarg('/^[^:]*@'.sed_bre_escape($domain).':/d').' /etc/dovecot/users');
	if ($db instanceof SQLite3) {
		foreach (array("DELETE FROM email_filters WHERE scope = 'domain' AND target = :d", 'DELETE FROM autoresponders WHERE domain = :d') as $q) {
			$st = @$db->prepare($q);
			if ($st) {
				$st->bindValue(':d', $domain, SQLITE3_TEXT);
				$st->execute();
			}
		}
	}
	shell_exec('sudo -n '._PATH.'/scripts/update_email_sni >/dev/null 2>&1 &');
}

/* The mailboxes of a mail domain. */
function domain_mailboxes($domain) {
	return array_values(array_filter(mailbox_list(true), function ($m) use ($domain) {
		return substr($m, -strlen($domain) - 1) === '@'.$domain;
	}));
}

/*
 * A new site of the account: an addon domain or a subdomain, with its own
 * folder ($folder relative to the home; '' = a folder named after the domain)
 * and its own vhost and PHP pool (the main site's PHP version). $www: serve
 * www.<domain> too (null: yes, except for a subdomain of the account's own
 * domains). $mail: email for it when the account has email (null: yes for an
 * addon domain, no for a subdomain of the account's own domains; the choice
 * is kept, see domain_mail_wanted()). data: the site (domain_site()), dns
 * (domain_dns_add()), points (domain_points()), mail (it is on).
 */
function domain_addon_add($account, $domain, $folder = '', $www = null, $mail = null) {
	global $db, $ini;
	if (($e = files_account_error($account)) !== '')
		return result_error($e);
	$acct = account_get($db, $account);
	$domain = strtolower(rtrim(trim((string)$domain), '.'));
	if (substr($domain, 0, 2) === '*.')
		return result_error('A site needs a real name; a wildcard can only be an alias of your main site.');
	if (($e = domain_claim_error($account, $domain)) !== '')
		return result_error($e);
	if (($e = limit_allows($account, 'addon', array('domain' => $domain))) !== '')
		return result_error($e);
	$folder = trim(preg_replace('#/+#', '/', str_replace('\\', '/', trim((string)$folder))), '/');
	if (substr($folder, 0, 2) === '~/')
		$folder = substr($folder, 2);
	if ($folder === '')
		$folder = $domain;
	if (($e = domain_folder_error($folder)) !== '')
		return result_error($e);
	$sub = domain_parent_of($account, $domain);
	if ($www === null)
		$www = $sub === '';
	if ($mail === null)
		$mail = $sub === '';

	/* the main site's PHP, so the new site behaves the same */
	list($ver, $handler) = account_php($acct['domain']);
	$versions = array_map('trim', explode(',', (string)($ini['php_versions'] ?? '')));
	if (!in_array($ver, $versions, true))
		$ver = $ini['php'];
	$handler = $handler === 'mod_php' && $ver === $ini['php'] ? 'mod_php' : 'fpm';
	if (php_mode($ini) !== 'modphp')
		$handler = 'fpm';

	list($err, $made) = domain_folder_make($account, $folder);
	$undo = function () use ($account, $made) {
		if ($made)
			files_delete($account, array($made[0]));
	};
	if ($err !== '') {
		$undo();
		return result_error($err);
	}
	if (in_array($folder, $made, true)
	    && ($tpl = @file_get_contents(_PATH.'/scripts/templates/default-template.html')) !== false)
		files_save($account, $folder.'/index.php', $tpl, null, true);

	$docroot = '/home/'.$account.'/'.$folder;
	route_log("account $account: add site $domain ($docroot)");
	$out = (string)shell_exec('sudo -n '._PATH.'/scripts/adddomain --user='.escapeshellarg($account).' --domain='.escapeshellarg($domain)
		.' --docroot='.escapeshellarg($docroot).' --docroot-ready --php='.escapeshellarg($ver).' --handler='.$handler.($www ? '' : ' --no-www').' 2>&1');
	if (!addon_domain_get($ini, $domain)) {
		$undo();
		$why = preg_match_all('/^Error:\s*(.+)$/m', $out, $m) ? trim(end($m[1])) : trim((string)preg_replace('/\s+/', ' ', substr($out, -300)));
		return result_error("$domain was not added: ".($why !== '' ? $why : 'the web server could not be set up.'));
	}

	$warn = array();
	$dns = domain_dns_add($account, $domain);
	if (strpos($dns, 'Error') === 0) {
		$warn[] = "The DNS record of $domain was not made: ".preg_replace('/^Error:\s*/', '', $dns);
		$dns = 'error';
	}
	$points = domain_points($www ? array($domain, 'www.'.$domain) : array($domain), $account);
	if ($dns !== 'added' && ($n = domain_points_note($account, $domain, $points[$domain] ?? array())) !== '')
		$warn[] = $n;
	plugin_hook('addon.created', array('account' => $account, 'domain' => $domain, 'docroot' => $docroot));
	/* mail too, as the account's main domain has it (unless asked not to) */
	domain_mail_want_set($account, $domain, (bool)$mail);
	$mail_on = false;
	if ($mail && domain_mail_available($account) === '') {
		$r = domain_mail_enable($account, $domain);
		$mail_on = $r['ok'];
		$warn = array_merge($warn, $r['ok'] ? $r['warnings'] : array('Email was not turned on for it: '.$r['error']));
	}
	return result_ok("$domain added. Its files go in ~/$folder.".($mail_on ? " Its email addresses can be made in Email." : ''),
	                 array('site' => domain_site($account, $domain), 'dns' => $dns, 'points' => $points, 'ip' => domain_site_ip($account),
	                       'mail' => $mail_on), $warn);
}

/* Remove an addon domain or subdomain of the account: its vhost, PHP pool
   and certificate; its folder too with $files (not when another site still
   uses it). */
function domain_addon_delete($account, $domain, $files = false) {
	global $ini;
	$domain = strtolower(trim((string)$domain));
	$a = addon_domain_get($ini, $domain);
	if (!$a || $a['user'] !== $account)
		return result_error("$domain is not an addon domain of $account.");
	if (function_exists('deploy_get') && deploy_get($domain))
		return result_error("$domain is deployed from Git; remove its deployment first.");
	if (function_exists('app_get') && app_get($domain))
		return result_error("$domain runs an app; remove the app first.");
	$boxes = domain_mailboxes($domain);
	if ($boxes)
		return result_error(count($boxes) === 1
			? "$domain still has the mailbox {$boxes[0]}. Delete it in Email first, or keep the site."
			: "$domain still has ".count($boxes)." mailboxes. Delete them in Email first, or keep the site.");
	$home = '/home/'.$account;
	$docroot = rtrim((string)$a['docroot'], '/');
	$folder = strpos($docroot, $home.'/') === 0 ? substr($docroot, strlen($home) + 1) : '';
	if ($files) {
		if ($folder === '' || $folder === 'public_html' || in_array($folder, domain_reserved_folders(), true))
			return result_error("The files of $domain are in ".domain_tilde($docroot, $account).', which this panel does not delete. Remove the site without its files.');
		foreach (domain_sites($account, false) as $s) {
			if ($s['domain'] === $domain)
				continue;
			$o = rtrim($s['docroot'], '/');
			if ($o === $docroot || strpos($o.'/', $docroot.'/') === 0)
				return result_error(domain_tilde($docroot, $account)." also holds the files of {$s['domain']}. Remove $domain without its files.");
		}
	}
	route_log("account $account: remove site $domain".($files ? " with $docroot" : ''));
	$out = (string)shell_exec('sudo -n '._PATH.'/scripts/deldomain --domain='.escapeshellarg($domain).' --delete-cert 2>&1');
	if (is_file(addon_vhost_path($ini, $domain))) {
		$why = preg_match_all('/^Error:\s*(.+)$/m', $out, $m) ? trim(end($m[1])) : '';
		return result_error("$domain could not be removed".($why !== '' ? ": $why" : '.'));
	}
	$warn = array();
	$msg = "$domain removed.";
	if ($files) {
		$r = files_delete($account, array($folder));
		if ($r['ok'])
			$msg = "$domain removed, with its files.";
		else
			$warn[] = 'Its files were not all deleted: '.$r['error'];
	} else
		$msg = "$domain removed. Its files stay in ".domain_tilde($docroot, $account).'.';
	$owner = email_userdomains()[$domain] ?? '';
	$mail = $owner === $account;
	if ($mail || ($owner === '' && email_domain_exists($domain)))
		domain_mail_remove($account, $domain);
	foreach (array_merge(array($domain, 'www.'.$domain), $mail ? array('mail.'.$domain) : array()) as $n)
		domain_dns_remove($account, $n);
	domain_mail_forget($domain);
	wp_cache_rules_forget($domain);
	plugin_hook('addon.deleted', array('account' => $account, 'domain' => $domain, 'files' => (bool)$files));
	return result_ok($msg, array('domain' => $domain), $warn);
}

/* The aliases of the account's main site: alias, wildcard, www (the
   www.<domain> one), created. */
function domain_aliases($account) {
	global $db;
	$acct = account_get($db, $account);
	if (!$acct)
		return array();
	$out = array();
	$res = $db->query('SELECT alias, is_wildcard, created_at FROM aliases WHERE account_id = '.(int)$acct['id'].' ORDER BY alias');
	while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC)))
		$out[] = array('alias' => $r['alias'], 'wildcard' => (int)$r['is_wildcard'] === 1,
		               'www' => $r['alias'] === 'www.'.strtolower($acct['domain']), 'created' => (string)$r['created_at']);
	usort($out, function ($a, $b) { return $b['www'] - $a['www'] ?: strcmp($a['alias'], $b['alias']); });
	return $out;
}

/* A new alias of the account's main site (the same site under another
   name; "*.example.com" for every subdomain of one of its domains). Its A
   record is made when the zone is at the DNS provider and $dns; a Let's
   Encrypt certificate of the main site is extended to it (in the background). */
function domain_alias_add($account, $alias, $dns = true) {
	global $db;
	if (($e = files_account_error($account)) !== '')
		return result_error($e);
	$acct = account_get($db, $account);
	$alias = strtolower(rtrim(trim((string)$alias), '.'));
	if (($e = domain_claim_error($account, $alias)) !== '')
		return result_error($e);
	if (($e = alias_validation_error($db, strtolower($acct['domain']), $alias)) !== '')
		return result_error($e);
	if (($e = limit_allows($account, 'alias', array('alias' => $alias))) !== '')
		return result_error($e);
	$wild = substr($alias, 0, 2) === '*.';
	$st = $db->prepare('INSERT INTO aliases (account_id, alias, is_wildcard, ssl_status, created_at) VALUES (:id, :a, :w, "none", datetime("now"))');
	$st->bindValue(':id', (int)$acct['id'], SQLITE3_INTEGER);
	$st->bindValue(':a', $alias, SQLITE3_TEXT);
	$st->bindValue(':w', $wild ? 1 : 0, SQLITE3_INTEGER);
	if (!$st->execute())
		return result_error("$alias could not be saved.");
	if (($e = apply_account_vhost_names($db, $acct['domain'])) !== '') {
		$db->exec('DELETE FROM aliases WHERE account_id = '.(int)$acct['id'].' AND alias = \''.SQLite3::escapeString($alias).'\'');
		apply_account_vhost_names($db, $acct['domain']);
		return result_error("$alias was not added: $e");
	}
	route_log("account $account: add alias $alias");
	$warn = array();
	$rec = 'none';
	if ($dns) {
		$rec = domain_dns_add($account, $alias);
		if (strpos($rec, 'Error') === 0) {
			$warn[] = "The DNS record of $alias was not made: ".preg_replace('/^Error:\s*/', '', $rec);
			$rec = 'error';
		}
	}
	$points = $wild ? array() : domain_points(array($alias), $account);
	if (!$wild && $rec !== 'added' && ($n = domain_points_note($account, $alias, $points[$alias] ?? array())) !== '')
		$warn[] = $n;
	plugin_hook('alias.created', array('user' => $account, 'alias' => $alias, 'dns' => $rec === 'added'));
	$msg = "$alias added: it shows the same site as {$acct['domain']}.";
	$cert = reissue_letsencrypt_cert($db, $acct['domain'], $acct['has_email'] === 'true' || $acct['has_email'] == 1);
	if ($cert === '')
		$msg .= ' The certificate is being extended to cover it.';
	elseif ($cert === 'not-free')
		$warn[] = ssl_free_error($account).' Its certificate was not extended to cover it.';
	elseif ($cert === 'no-dns' && $wild)
		$warn[] = 'A certificate for a wildcard alias needs the domain\'s DNS at this server\'s DNS provider.';
	return result_ok($msg, array('alias' => $alias, 'wildcard' => $wild, 'dns' => $rec, 'points' => $points, 'ip' => domain_site_ip($account)), $warn);
}

/* Remove an alias of the account's main site. Its A record goes too when it
   still points only here; a Let's Encrypt certificate is issued again without it. */
function domain_alias_delete($account, $alias) {
	global $db;
	$alias = strtolower(trim((string)$alias));
	$acct = account_get($db, $account);
	if (!$acct || !scope_owns($account, 'alias', $alias))
		return result_error("$alias is not an alias of $account.");
	$st = $db->prepare('SELECT * FROM aliases WHERE account_id = :id AND alias = :a');
	$st->bindValue(':id', (int)$acct['id'], SQLITE3_INTEGER);
	$st->bindValue(':a', $alias, SQLITE3_TEXT);
	$res = $st->execute();
	$row = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;
	if (!$row)
		return result_error("$alias is not an alias of $account.");
	$db->exec('DELETE FROM aliases WHERE id = '.(int)$row['id']);
	if (($e = apply_account_vhost_names($db, $acct['domain'])) !== '') {
		$ins = $db->prepare('INSERT INTO aliases (id, account_id, alias, is_wildcard, ssl_status, created_at) VALUES (:i, :id, :a, :w, :s, :c)');
		foreach (array(':i' => (int)$row['id'], ':id' => (int)$acct['id'], ':a' => $alias, ':w' => (int)$row['is_wildcard'],
		               ':s' => (string)$row['ssl_status'], ':c' => (string)$row['created_at']) as $k => $v)
			$ins->bindValue($k, $v, is_int($v) ? SQLITE3_INTEGER : SQLITE3_TEXT);
		$ins->execute();
		return result_error("$alias was not removed: $e");
	}
	route_log("account $account: remove alias $alias");
	if (substr($alias, 0, 2) !== '*.')
		domain_dns_remove($account, $alias);
	reissue_letsencrypt_cert($db, $acct['domain'], $acct['has_email'] === 'true' || $acct['has_email'] == 1);
	plugin_hook('alias.deleted', array('user' => $account, 'alias' => $alias));
	return result_ok("$alias removed.", array('alias' => $alias));
}

/* The account's sites with their certificates: domain, kind, cert, names
   (what the certificate should cover), wildcards, missing (names it does not
   cover); with $check also points (domain_points() of the names). */
function ssl_sites($account, $check = false) {
	$out = array();
	foreach (domain_sites($account) as $s) {
		list($names, $wild) = domain_site_cert_names($account, $s);
		$have = $s['cert'] && !$s['cert']['self_signed'] && !$s['cert']['expired'] ? $s['cert']['names'] : array();
		$missing = array();
		foreach (array_merge($names, $wild) as $n)
			if (!domain_cert_covers($have, $n))
				$missing[] = $n;
		$out[] = array('domain' => $s['domain'], 'kind' => $s['kind'], 'subdomain_of' => $s['subdomain_of'], 'cert' => $s['cert'],
		               'names' => $names, 'wildcards' => $wild, 'missing' => $missing);
	}
	if ($check) {
		$all = array();
		foreach ($out as $s)
			$all = array_merge($all, $s['names']);
		$p = domain_points($all, $account);
		foreach ($out as $i => $s)
			$out[$i]['points'] = array_intersect_key($p, array_flip($s['names']));
	}
	return $out;
}

/* '' when $account may get free Let's Encrypt certificates, else why not
   (filter ssl.free: Hosting Pro's packages can leave them out). Asked before
   every Let's Encrypt request for an account: ssl_site_issue(),
   reissue_letsencrypt_cert(), account_create() (there with its $o as $create:
   the account does not exist yet) and 2087's SSL page. Bought and self-signed
   certificates are never refused; ones already issued keep renewing. */
function ssl_free_error($account, ?array $create = null) {
	$e = plugin_filter('ssl.free', '', (string)$account, $create);
	return is_string($e) ? $e : '';
}

/*
 * A free Let's Encrypt certificate for one of the account's sites, for its
 * names that point at this server (the domain itself must). Runs certbot
 * (HTTP-01, the front server's plugin; it installs the certificate in the
 * vhost and renews it by itself), at most ~3 minutes. Names left out
 * (pointing elsewhere, wildcards) come back as warnings.
 */
function ssl_site_issue($account, $domain) {
	global $db, $ini;
	$domain = strtolower(trim((string)$domain));
	$site = domain_site($account, $domain);
	if (!$site)
		return result_error("$domain is not a site of $account.");
	if (($e = ssl_free_error($account)) !== '')
		return result_error($e);
	list($names, $wild) = domain_site_cert_names($account, $site);
	$p = domain_points($names, $account);
	if (empty($p[$domain]['here'])) {
		$ip = domain_site_ip($account);
		return result_error(!empty($p[$domain]['ips'])
			? "$domain points to ".implode(', ', $p[$domain]['ips']).", not to this server, so Let's Encrypt cannot check it. Change its A record to $ip, wait until the change shows, then try again."
			: "$domain has no address in DNS yet, so Let's Encrypt cannot check it. Give it an A record with $ip, then try again.");
	}
	$ok = array();
	$warn = array();
	foreach ($names as $n) {
		if (!empty($p[$n]['here']))
			$ok[] = $n;
		else
			$warn[] = "$n does not point to this server yet, so the certificate leaves it out.";
	}
	foreach ($wild as $w)
		$warn[] = "$w is a wildcard; this certificate leaves it out.";
	$plugin = web_server(web_front($ini))['certbot'];
	/* A vhost with an https block already sends http to https itself; certbot's
	   own redirect step would then only fail when it cannot pair the two blocks
	   (different names), after the certificate was already installed. */
	$redirect = preg_match('~^\s*(RewriteRule\s+\S+\s+https://|return\s+30[18]\s+https://)~mi', account_vhost_file_read(redirect_vhost($site)))
		? ' --no-redirect' : '';
	route_log("account $account: Let's Encrypt for ".implode(',', $ok));
	$out = array();
	exec('sudo -n timeout 170 certbot --non-interactive --agree-tos --'.$plugin.$redirect.' --cert-name '.escapeshellarg($domain)
	     .' --expand -d '.escapeshellarg(implode(',', $ok)).' 2>&1', $out, $rc);
	le_ssl_vhost_merge($domain);
	$text = implode("\n", $out);
	if ($rc !== 0) {
		$head = "Let's Encrypt did not issue the certificate";
		if (stripos($text, 'Another instance of Certbot') !== false)
			$why = 'another certificate is being requested on this server right now. Try again in a minute.';
		elseif (stripos($text, 'too many') !== false || stripos($text, 'rateLimited') !== false)
			$why = 'Let\'s Encrypt has refused too many requests for this domain lately. Try again later.';
		elseif ($rc === 124)
			$why = 'it took too long. Try again in a few minutes.';
		elseif (preg_match_all('/^\s*Detail:\s*(.+)$/mi', $text, $m))
			$why = trim(end($m[1]));
		elseif (stripos($text, 'could not be installed') !== false) {
			/* issued, but the installer failed: its reason comes after the "certbot install" hint */
			$head = "Let's Encrypt issued the certificate, but it could not be added to the site";
			$why = preg_match('/^\s*certbot install\b[^\n]*\n(.*?)(?:^Ask for help|\z)/msi', $text, $m) && trim($m[1]) !== ''
				? trim((string)preg_replace('/\s+/', ' ', $m[1])) : 'see /var/log/letsencrypt/letsencrypt.log.';
		}
		else
			$why = trim((string)preg_replace('/\s+/', ' ', implode(' ', array_slice(array_filter($out, function ($l) { return stripos($l, 'error') !== false; }), -2)))) ?: 'certbot failed.';
		log_debug('[ssl_site_issue] '.$domain.': '.$text);
		return result_error("$head: $why");
	}
	if (in_array('mail.'.$domain, $ok, true))
		shell_exec('sudo -n '._PATH.'/scripts/update_email_sni >/dev/null 2>&1 &');
	plugin_hook('ssl.issued', array('account' => $account, 'domain' => $domain, 'names' => $ok));
	return result_ok("Certificate installed for ".implode(', ', $ok).'. It renews by itself.', array('domain' => $domain, 'names' => $ok, 'cert' => domain_cert($domain)), $warn);
}

/* ---- Redirects ------------------------------------------------------------
   A site's redirects live in its front vhost, in a "# BEGIN reqad-redirects"
   block in every server block, right after its server_name (Apache: its
   ServerAlias, else ServerName) line: below the blocks that must win, such as
   a suspended site's, which sit at the top. The block is the inventory: each
   rule is a "# redirect <code> <exact|prefix> <keep|drop> <from> <to>
   [host=<name>]" line followed by its directive; host= limits it to one of
   the site's names (with or without www.). Requests for Let's Encrypt checks
   (/.well-known/acme-challenge/) are never redirected. */

function redirect_max() {
	return 100;
}

/* The front vhost file of one of the account's sites. */
function redirect_vhost(array $site) {
	global $ini;
	return $site['kind'] === 'main' ? account_vhost_path($site['domain'], web_front($ini)) : addon_vhost_path($ini, $site['domain']);
}

/* The rules in a vhost's text: from, to, code, match, keep. */
function redirect_rules_parse($conf) {
	$out = array();
	if (!preg_match('/# BEGIN reqad-redirects\n(.*?)\n[ \t]*# END reqad-redirects/s', (string)$conf, $b))
		return $out;
	if (preg_match_all('/^[ \t]*# redirect (301|302) (exact|prefix) (keep|drop) (\S+) (\S+)(?: host=([a-z0-9.-]+))?[ \t]*$/m', $b[1], $m, PREG_SET_ORDER))
		foreach ($m as $r)
			$out[] = array('from' => $r[4], 'to' => $r[5], 'code' => (int)$r[1], 'match' => $r[2], 'keep' => $r[3] === 'keep', 'host' => $r[6] ?? '');
	return $out;
}

/* The directive of one rule for $server (nginx | httpd). */
function redirect_directive(array $r, $server) {
	$all = $r['from'] === '/';
	$prefix = $r['match'] === 'prefix';
	$path = str_replace('.', '\\.', rtrim($r['from'], '/'));
	/* never the Let's Encrypt checks, nor the server's own error pages */
	$re = $all ? '^(/(?!\\.well-known/acme-challenge/|reqad-error/).*)$' : ($prefix ? '^'.$path.'(/.*)?$' : '^'.$path.'$');
	$to = $r['to'];
	if ($prefix && $r['keep'])
		$to = rtrim($to, '/').'$1';
	$host = (string)($r['host'] ?? '');
	if ($server === 'httpd') {
		$to = str_replace('%', '\\%', $to);
		return "RewriteEngine On\n".($host !== '' ? 'RewriteCond %{HTTP_HOST} "^'.str_replace('.', '\\.', $host).'$" [NC]'."\n" : '')
		      ."RewriteRule \"$re\" \"$to\" [R={$r['code']},L,NE".($prefix && !$r['keep'] ? ',QSD' : '').']';
	}
	if ($prefix && !$r['keep'])
		$to .= '?';
	$rw = "rewrite \"$re\" \"$to\" ".($r['code'] === 301 ? 'permanent' : 'redirect').';';
	/* an if at server level holding only a rewrite is safe in nginx */
	return $host !== '' ? 'if ($host = '.$host.') { '.$rw.' }' : $rw;
}

/* A vhost's text with its redirects block replaced by $rules (none: taken out). */
function redirect_vhost_edit($conf, array $rules, $server) {
	$conf = preg_replace('/\n[ \t]*# BEGIN reqad-redirects\n.*?\n[ \t]*# END reqad-redirects(?=\n|$)/s', '', $conf);
	if (!$rules)
		return $conf;
	$lines = '';
	foreach ($rules as $r)
		$lines .= '# redirect '.$r['code'].' '.$r['match'].' '.($r['keep'] ? 'keep' : 'drop').' '.$r['from'].' '.$r['to']
		         .(($r['host'] ?? '') !== '' ? ' host='.$r['host'] : '')."\n"
		         .redirect_directive($r, $server)."\n";
	$block = "# BEGIN reqad-redirects\n".$lines."# END reqad-redirects";
	$re = $server === 'httpd'
		? (preg_match('/^[ \t]*ServerAlias\b/mi', $conf) ? '/^([ \t]*)ServerAlias\b[^\n]*$/mi' : '/^([ \t]*)ServerName\b[^\n]*$/mi')
		: '/^([ \t]*)server_name\b[^;]*;[^\n]*$/m';
	$n = 0;
	$conf = preg_replace_callback($re, function ($m) use ($block) {
		return $m[0]."\n".conf_indent($block, $m[1]);
	}, $conf, -1, $n);
	return $n ? $conf : null;
}

/* The redirects of the account's sites: redirects (domain, from, to, code,
   match, keep, host: '' = every name of the site), sites (domain, www: the
   site answers to www.<domain> too). */
function redirect_list($account) {
	$out = $sites = array();
	foreach (domain_sites($account, false) as $s) {
		$sites[] = array('domain' => $s['domain'], 'www' => $s['www']);
		foreach (redirect_rules_parse(account_vhost_file_read(redirect_vhost($s))) as $r)
			$out[] = array('domain' => $s['domain']) + $r;
	}
	return array('redirects' => $out, 'sites' => $sites);
}

/* Write a site's rules to its vhost (tested; put back on a failure). '' or the error. */
function redirect_save(array $site, array $rules) {
	global $ini;
	$file = redirect_vhost($site);
	$orig = account_vhost_file_read($file);
	if (trim($orig) === '')
		return 'The web server settings of '.$site['domain'].' were not found.';
	$new = redirect_vhost_edit($orig, $rules, web_front($ini));
	if ($new === null)
		return 'The web server settings of '.$site['domain'].' have no server name line to put the redirects under.';
	return $new === $orig ? '' : account_vhost_files_apply(array($file => array($orig, $new)));
}

/*
 * Add a redirect to one of the account's sites.
 * $o: domain (the site), from ("/" = the whole site, or a path such as
 * /old-page), to (an http(s) address), code (301 permanent, 302 temporary),
 * match (exact: only that address; prefix: it and everything under it - the
 * whole site always), keep (prefix: add the rest of the address to the
 * target, so /old/a goes to <to>/a), www (any: with or without www., the
 * default; no: only <domain>; only: only www.<domain> - e.g. www to the
 * bare domain).
 */
function redirect_add($account, array $o) {
	$site = domain_site($account, (string)($o['domain'] ?? ''));
	if (!$site)
		return result_error('That site is not one of yours.');
	$from = trim((string)($o['from'] ?? ''));
	if ($from === '')
		$from = '/';
	if ($from[0] !== '/')
		$from = '/'.$from;
	if ($from !== '/')
		$from = rtrim($from, '/');
	if (strlen($from) > 200 || !preg_match('#^/[A-Za-z0-9._~/%-]*$#', $from) || strpos($from, '//') !== false || preg_match('#(^|/)\.\.?(/|$)#', $from))
		return result_error('The address to redirect must be a path such as /old-page, with letters, digits and . _ ~ - / only.');
	if (stripos($from, '/.well-known') === 0)
		return result_error('Addresses under /.well-known/ cannot be redirected: they are used to check certificates.');
	if (stripos($from, '/reqad-error') === 0)
		return result_error('/reqad-error/ holds the server\'s error pages and cannot be redirected.');
	$to = trim((string)($o['to'] ?? ''));
	if (strlen($to) > 500 || !preg_match('#^https?://[A-Za-z0-9.-]+(:[0-9]{1,5})?(/[A-Za-z0-9._~:/?@!&()*+,=%-]*)?$#', $to))
		return result_error('The target must be a full address such as https://example.com/new-page (no spaces, quotes or $).');
	$code = (int)($o['code'] ?? 301);
	if ($code !== 301 && $code !== 302)
		return result_error('The type must be 301 (permanent) or 302 (temporary).');
	$match = $from === '/' ? 'prefix' : (($o['match'] ?? 'exact') === 'prefix' ? 'prefix' : 'exact');
	$keep = $match === 'prefix' && !empty($o['keep']);
	if ($keep && strpos($to, '?') !== false)
		return result_error('A target with ? cannot get the rest of the address added. Untick that option, or take the ? part out.');
	$www = (string)($o['www'] ?? 'any');
	if (!in_array($www, array('any', 'no', 'only'), true))
		return result_error('www must be any, no or only.');
	if ($www !== 'any' && !$site['www'])
		return result_error($site['domain'].' does not answer to www.'.$site['domain'].', so there is nothing to tell apart.');
	$host = $www === 'no' ? $site['domain'] : ($www === 'only' ? 'www.'.$site['domain'] : '');

	/* not to itself */
	$u = parse_url($to);
	/* the names the rule answers for */
	$own = $host !== '' ? array($host) : array_merge(array($site['domain'], 'www.'.$site['domain'], 'mail.'.$site['domain']), $site['aliases']);
	if (in_array(strtolower((string)($u['host'] ?? '')), $own, true)) {
		$tp = '/'.ltrim((string)($u['path'] ?? '/'), '/');
		$tp = $tp === '/' ? '/' : rtrim($tp, '/');
		if ($match === 'exact' ? $tp === $from : ($from === '/' || $tp === $from || strpos($tp.'/', $from.'/') === 0))
			return result_error('That would send visitors in a loop: the target is inside what it redirects.');
	}
	$rules = redirect_rules_parse(account_vhost_file_read(redirect_vhost($site)));
	foreach ($rules as $r)
		if ($r['from'] === $from && $r['match'] === $match && ($r['host'] === '' || $host === '' || $r['host'] === $host))
			return result_error("{$site['domain']}$from is redirected already. Remove that redirect first.");
	if (count($rules) >= redirect_max())
		return result_error('A site can have at most '.redirect_max().' redirects.');
	if (($e = limit_allows($account, 'redirect', array('domain' => $site['domain']))) !== '')
		return result_error($e);
	$rule = array('from' => $from, 'to' => $to, 'code' => $code, 'match' => $match, 'keep' => $keep, 'host' => $host);
	/* the more specific first: a site-wide rule after the others */
	$rules[] = $rule;
	usort($rules, function ($a, $b) {
		return strlen($b['from']) - strlen($a['from']) ?: (int)($a['host'] === '') - (int)($b['host'] === '') ?: strcmp($a['match'], $b['match']);
	});
	route_log("account $account: redirect {$site['domain']}$from -> $to ($code)");
	if (($e = redirect_save($site, $rules)) !== '')
		return result_error($e);
	plugin_hook('redirect.created', array('account' => $account, 'domain' => $site['domain']) + $rule);
	$name = $host !== '' ? $host : $site['domain'];
	return result_ok(($from === '/' ? $name : $name.$from).' now redirects to '.$to.'.', array('domain' => $site['domain']) + $rule);
}

/* Remove a redirect of one of the account's sites ($match '' = whichever
   has that from; $host null = any of its names). */
function redirect_delete($account, $domain, $from, $match = '', $host = null) {
	$site = domain_site($account, (string)$domain);
	if (!$site)
		return result_error('That site is not one of yours.');
	$from = trim((string)$from);
	$rules = redirect_rules_parse(account_vhost_file_read(redirect_vhost($site)));
	$keep = array();
	$gone = null;
	foreach ($rules as $r) {
		if ($gone === null && $r['from'] === $from && ($match === '' || $r['match'] === $match) && ($host === null || $r['host'] === $host))
			$gone = $r;
		else
			$keep[] = $r;
	}
	if ($gone === null)
		return result_error("{$site['domain']}$from has no redirect.");
	route_log("account $account: remove redirect {$site['domain']}$from");
	if (($e = redirect_save($site, $keep)) !== '')
		return result_error($e);
	plugin_hook('redirect.deleted', array('account' => $account, 'domain' => $site['domain'], 'from' => $from, 'host' => $gone['host']));
	$name = $gone['host'] !== '' ? $gone['host'] : $site['domain'];
	return result_ok('The redirect of '.($from === '/' ? $name : $name.$from).' was removed.', array('domain' => $site['domain'], 'from' => $from, 'host' => $gone['host']));
}
