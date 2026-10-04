<?php
/*
 * Hosting accounts: create / edit / delete / list.
 *
 * Shared by the web panel (modules/create_account.php etc. are thin wrappers
 * around these), bin/reqad and the API. Nothing here may touch $_POST, send
 * headers or exit -- every function returns result_ok() / result_error()
 * (modules/functions.php) and the caller decides how to show it.
 *
 * Runs both as the panel user (reqad, via php-fpm) and as root (CLI), so all
 * privileged work goes through `sudo`, which is a no-op for root.
 */

/* Linux accounts that must never be created, edited or deleted as a hosting account. */
function account_reserved_names() {
	return array('root', 'reqad', 'test', 'bin', 'daemon', 'adm', 'lp', 'sync', 'shutdown', 'halt', 'mail',
		'operator', 'games', 'ftp', 'nobody', 'systemd-network', 'dbus', 'polkitd', 'sshd', 'postfix',
		'chrony', 'apache', 'cjdns', 'vnstat', 'postgres', 'redis', 'awx', 'nginx', 'tss');
}

/* Primary IPv4 of the server (first global address). */
function server_primary_ip() {
	return trim((string)shell_exec("/usr/sbin/ip address show | grep 'scope global' | grep 'inet ' | head -n 1 | awk {'print \$2'} | awk -F/ {'print \$1'}"));
}

/* Password rules for the system (SSH/SFTP) account. '' when acceptable.
   `:` and newlines are the chpasswd record separators; any other character is
   safe because set_system_password() writes to chpasswd's stdin. */
function account_password_error($password) {
	if (strlen($password) < 8)
		return "Password should be at least 8 characters long.";
	if (strpos($password, ':') !== false || strpos($password, "\n") !== false || strpos($password, "\r") !== false)
		return "Password cannot contain a colon (:) or a line break.";
	return '';
}

/* One account row by username or by domain, or null. */
function account_get($db, $user = '', $domain = '') {
	if ($user !== '') {
		$stmt = $db->prepare('SELECT * FROM accounts WHERE user = :v');
		$stmt->bindValue(':v', $user, SQLITE3_TEXT);
	} else {
		$stmt = $db->prepare('SELECT * FROM accounts WHERE domain = :v');
		$stmt->bindValue(':v', $domain, SQLITE3_TEXT);
	}
	$row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
	return $row ? $row : null;
}

/* All accounts, ordered by username. */
function account_list($db) {
	$rows = array();
	$res  = $db->query('SELECT id, user, domain, disk_usage, disk_quota, has_email, status, created_at FROM accounts ORDER BY user');
	while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) {
		$row['has_email'] = ($row['has_email'] === 'true' || $row['has_email'] == 1);
		$rows[] = $row;
	}
	return $rows;
}

/* PHP version an account currently runs and how: array(version, 'fpm'|'mod_php'),
   or array('off', 'off') when PHP is disabled (see account_php_disable()).
   The pool file's location is the authority -- a non-default version has its
   pool under /etc/opt/remi/phpXY/, the default one under /etc/php-fpm.d/, and
   on Apache no pool at all means mod_php. */
function account_php($domain) {
	global $ini;
	if (account_php_disabled($domain))
		return array('off', 'off');
	$is_apache = (isset($ini['template']) && substr(trim($ini['template']), 0, 7) == 'apache_');
	$version   = $ini['php'];
	$handler   = $is_apache ? 'mod_php' : 'fpm';
	foreach (array_map('trim', explode(',', $ini['php_versions'])) as $phpv) {
		if ($phpv == $ini['php']) continue;
		if (is_file('/etc/opt/remi/php'.str_replace('.', '', $phpv).'/php-fpm.d/'.$domain.'.conf'))
			return array($phpv, 'fpm');
	}
	if ($is_apache && is_file('/etc/php-fpm.d/'.$domain.'.conf'))
		$handler = 'fpm';
	return array($version, $handler);
}

/* Run the web server template (scripts/templates/<template>.php) for a new
   account: vhost, php-fpm pool, home skeleton and the optional background
   Let's Encrypt request. Kept in its own function so the template's variables
   ($file, $IP, ...) cannot clobber the caller's. Returns the template's $errmsg. */
function account_vhost_template($domain, $user, $has_email, $letsencrypt, $account_aliases, $sslmsgtoken) {
	global $ini;
	if (!defined('REQAD_TEMPLATE_RETURN'))
		define('REQAD_TEMPLATE_RETURN', true);
	$errmsg = '';
	require _PATH.'/scripts/templates/'.basename($ini['template']).'.php';
	return trim($errmsg);
}

/* Turn on mail for an account's domain: exim userdomains/domains/forwards,
   DKIM keys, DNS records (MX/DKIM/SPF/DMARC) and the SNI/autoconfig refresh.
   $mx_if_missing: only add the MX record when the domain has none (edit path);
   the create path always writes the local MX. Returns warnings (array). */
function account_email_enable($domain, $user, $mx_if_missing) {
	$warnings = array();

	$domains = array();   // user => domain, from /etc/exim/userdomains
	foreach (explode("\n", (string)shell_exec('sudo cat /etc/exim/userdomains')) as $line) {
		if (strpos($line, ':') === false) continue;
		list($d, $u) = explode(':', $line, 2);
		$domains[trim($u)] = trim($d);
	}
	if (!in_array($domain, $domains, true) && !array_key_exists($user, $domains)) {
		shell_exec('echo '.escapeshellarg($domain.':'.$user).' | sudo tee --append /etc/exim/userdomains > /dev/null');
		shell_exec('sudo chown exim:exim /etc/exim/userdomains');
		shell_exec('sudo chmod a+r,u+w /etc/exim/userdomains');
		shell_exec('sudo touch '.escapeshellarg('/etc/exim/domains/'.$domain));
		shell_exec('sudo chown exim:exim '.escapeshellarg('/etc/exim/domains/'.$domain));
	}

	// Catch-all :fail: entry in the forwards file
	$fwd = escapeshellarg('/etc/exim/forwards/'.$domain);
	shell_exec('sudo grep -q \'^\\*:\' '.$fwd.' 2>/dev/null || echo \'*: :fail: No Such User Here\' | sudo tee --append '.$fwd.' > /dev/null');
	shell_exec('sudo chown exim:exim '.$fwd.' 2>/dev/null');

	$priv = '/etc/exim/keys/'.$domain.'.private.key';
	$pub  = '/etc/exim/keys/'.$domain.'.public.key';
	if (!is_file($priv) || !is_file($pub)) {
		shell_exec('sudo rm -f '.escapeshellarg($priv).' '.escapeshellarg($pub));
		shell_exec('sudo openssl genrsa -out '.escapeshellarg($priv).' 2048 2>/dev/null');
		shell_exec('sudo openssl rsa -in '.escapeshellarg($priv).' -out '.escapeshellarg($pub).' -pubout -outform PEM 2>/dev/null');
		shell_exec('sudo chown exim:mail '.escapeshellarg($priv).' '.escapeshellarg($pub));
		shell_exec('sudo chmod g+r '.escapeshellarg($priv).' '.escapeshellarg($pub));
		if (!is_file($priv) || !is_file($pub))
			$warnings[] = 'Cannot generate the pair of keys for DKIM.';
	}

	// Ask the domain's own nameserver, so records just written elsewhere are not missed
	$ns = trim((string)shell_exec('dig +short NS '.escapeshellarg($domain)." | head -n 1 | sed 's/\\.$//'"));
	$at = ($ns === '') ? '' : ' '.escapeshellarg('@'.$ns);
	/* The provider helpers return '' or an error string; they used to be
	   discarded, which hid a missing zone or an API failure. */
	if (!$mx_if_missing || trim((string)shell_exec('dig +short MX '.escapeshellarg($domain).$at)) === '')
		$warnings[] = (string)add_update_local_mx($domain);
	$warnings[] = (string)add_update_dkim($domain);
	if (trim((string)shell_exec('dig +short TXT '.escapeshellarg($domain).$at." | grep 'v=spf1'")) === '')
		$warnings[] = (string)add_update_spf($domain);
	if (trim((string)shell_exec('dig +short TXT '.escapeshellarg('_dmarc.'.$domain).$at)) === '')
		$warnings[] = (string)add_update_dmarc($domain);

	/* Mail TLS (dovecot/exim SNI) and the static Thunderbird/Outlook
	   autoconfiguration files both key off /etc/exim/domains, which the
	   block above just added to. update_email_sni regenerates both. */
	shell_exec('sudo '._PATH.'/scripts/update_email_sni >> '._PATH.'/log/debug_log 2>&1 &');
	return $warnings;
}

/* Turn off mail for an account's domain (the mailboxes themselves are kept).
   Returns warnings (array). */
function account_email_disable($domain, $user) {
	$warnings = array();
	$domains  = array();
	foreach (explode("\n", (string)shell_exec('sudo cat /etc/exim/userdomains')) as $line) {
		if (strpos($line, ':') === false) continue;
		list($d, $u) = explode(':', $line, 2);
		$domains[trim($u)] = trim($d);
	}
	if (isset($domains[$user]) && $domains[$user] == $domain)
		shell_exec('sudo sed -i '.escapeshellarg('/^'.addcslashes($domain, '.').':'.$user.'$/d').' /etc/exim/userdomains');
	else
		$warnings[] = 'This user / domain combination does not exist in /etc/exim/userdomains.';
	shell_exec('sudo '._PATH.'/scripts/update_email_sni >> '._PATH.'/log/debug_log 2>&1 &');
	return $warnings;
}

/*
 * Create a hosting account.
 *
 * $o keys:
 *   user, domain, password   required
 *   disk_quota   MB, 0 = unlimited (forced to 0 when quota is off in the ini)
 *   dns          bool  create the DNS zone at the configured provider
 *   letsencrypt  bool  request a certificate in the background
 *   email        bool  enable mail for the domain
 *   www          bool  add the www.<domain> alias
 *   ssl_msg_token  web only: message-queue token the background certificate
 *                  job posts its result to (the accounts page polls it)
 *
 * Validation failures and a failed DNS zone creation change nothing. Problems
 * after the Linux user exists (vhost file already present, domain not resolving
 * for Let's Encrypt, DKIM key generation) come back as warnings with ok = true,
 * because the account has been created.
 */
function account_create($db, array $o) {
	global $ini;

	$user        = trim((string)($o['user'] ?? ''));
	$domain      = strtolower(trim((string)($o['domain'] ?? '')));
	$password    = trim((string)($o['password'] ?? ''));
	$disk_quota  = (int)($o['disk_quota'] ?? 0);
	$adddns      = !empty($o['dns']);
	$letsencrypt = !empty($o['letsencrypt']);
	$has_email   = !empty($o['email']);
	$create_www  = !empty($o['www']);
	$sslmsgtoken = $letsencrypt ? (string)($o['ssl_msg_token'] ?? '') : '';

	if ($ini['quota'] == 0)
		$disk_quota = 0;

	/* `accounts` in server-software.ini is the account limit (0/unset = none).
	   The accounts page only hides its Create button; this is the real check. */
	$limit = isset($ini['accounts']) ? (int)$ini['accounts'] : 0;
	if ($limit > 0 && count(account_list($db)) >= $limit)
		return result_error("Account limit reached ($limit), see 'accounts' in etc/server-software.ini.");

	if (!valid_domain($domain))
		return result_error("Domain name is wrong, please check what you typed.");
	if ($row = account_get($db, '', $domain))
		return result_error("Domain name already exists on this server, assigned to user ".$row['user'].".");

	if (in_array($user, account_reserved_names(), true))
		return result_error("Username already exists. Please choose a distinct one.");
	if (!valid_username($user))
		return result_error("Username must be 2-16 characters, lowercase letters and numbers only, starting with a letter.");
	if ($row = account_get($db, $user))
		return result_error("Username already exists (UID=".$row['id']."). Please choose a different one.");
	if ((int)trim((string)shell_exec('(id '.escapeshellarg($user).') > /dev/null 2>&1; echo $?')) == 0)
		return result_error("Username already exists on server. Please choose a different one.");

	if (($err = account_password_error($password)) !== '')
		return result_error($err);

	$provider = dns_provider_load($db);
	if ($adddns) {
		$err = add_domain_in_dns($domain, server_primary_ip());
		if ($err != '')
			return result_error($err);
	}

	route_log("create account $user");
	shell_exec('sudo useradd '.escapeshellarg($user).' 2>&1');
	$uid = (int)trim((string)shell_exec('id -u '.escapeshellarg($user).' 2>/dev/null'));
	if ($uid < 1000)
		return result_error("User $user does not exist (cannot be created).");

	set_system_password($user, $password);

	// Cloudflare: no mail for subdomains
	if ($provider == 'cloudflare' && $has_email && main_domain($domain) != '')
		$has_email = false;

	$stmt = $db->prepare('INSERT INTO accounts VALUES (:id, :user, :domain, 0, :quota, :email, "active", datetime("now"), \'default\')');
	$stmt->bindValue(':id', $uid, SQLITE3_INTEGER);
	$stmt->bindValue(':user', $user, SQLITE3_TEXT);
	$stmt->bindValue(':domain', $domain, SQLITE3_TEXT);
	$stmt->bindValue(':quota', $disk_quota, SQLITE3_INTEGER);
	$stmt->bindValue(':email', $has_email ? 1 : 0, SQLITE3_INTEGER);
	$stmt->execute();

	/* Seed the www alias when requested, and pass the alias list to the vhost
	   template so the initial server_name matches (empty = no www). */
	$account_aliases = array();
	if ($create_www) {
		$account_aliases[] = 'www.'.$domain;
		$stmt = $db->prepare('INSERT INTO aliases (account_id, alias, is_wildcard, ssl_status, created_at) VALUES (:id, :alias, 0, "none", datetime("now"))');
		$stmt->bindValue(':id', $uid, SQLITE3_INTEGER);
		$stmt->bindValue(':alias', 'www.'.$domain, SQLITE3_TEXT);
		$stmt->execute();
	}

	/* The template only launches certbot when it wrote the vhost and the domain
	   resolves here; otherwise it reports why in its $errmsg. */
	$tpl_err     = account_vhost_template($domain, $user, $has_email, $letsencrypt, $account_aliases, $sslmsgtoken);
	$warnings    = array($tpl_err);
	$ssl_started = $letsencrypt && $tpl_err === '';

	if ($has_email)
		$warnings = array_merge($warnings, account_email_enable($domain, $user, false));

	$message = "Account $user successfully created.";
	if ($ssl_started)
		$message .= " Please wait one minute until Let's Encrypt Certificate will be installed.";

	return result_ok($message, array(
		'uid' => $uid, 'user' => $user, 'domain' => $domain, 'disk_quota' => $disk_quota,
		'email' => $has_email, 'www' => $create_www, 'letsencrypt_started' => $ssl_started,
	), $warnings);
}

/* Build the Apache php-fpm pool for an account switched off mod_php. */
function apache_fpm_pool($domain, $user) {
	return account_fpm_pool($domain, $user, 'apache');
}

/* An account's php-fpm pool. $web_group is the web server's group (nginx or
   apache), which must reach the socket. Same pool as the one
   scripts/templates/nginx_php-fpm.php writes at account creation -- keep the two
   in step (the template also runs from scripts/adduserdomain, which does not
   load app/functions/, so it cannot call this). */
function account_fpm_pool($domain, $user, $web_group) {
	return '['.$domain.']
user = '.$user.'
group = '.$web_group.'
listen = /run/php-fpm-'.$user.'.sock
listen.owner = '.$user.'
listen.group = '.$web_group.'
listen.allowed_clients = 127.0.0.1

pm = dynamic
pm.max_children = 200
pm.start_servers = 10
pm.min_spare_servers = 5
pm.max_spare_servers = 50
pm.process_idle_timeout = 1s;
pm.max_requests = 1000

ping.path = /ping
slowlog = /var/log/php-fpm/'.$domain.'-slow.log
chdir = /

php_admin_value[disable_functions] = show_source, system, shell_exec, passthru, exec, popen, proc_open
php_admin_value[open_basedir] = /home/'.$user.'
;php_admin_value[error_reporting] = E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT
php_admin_value[error_log] = "/home/'.$user.'/logs/'.$domain.'-error.log"
php_admin_flag[log_errors] = on
php_admin_value[sys_temp_dir] = "/home/'.$user.'/tmp"
php_admin_value[upload_tmp_dir] = "/home/'.$user.'/tmp"
php_admin_value[memory_limit] = 2048M
php_value[session.save_handler] = files
php_value[session.save_path] = "/home/'.$user.'/tmp"
php_value[soap.wsdl_cache_dir]  = /var/lib/php/wsdlcache
';
}

/* The vhost block that hands .php to the account's php-fpm socket instead of mod_php. */
function apache_fpm_handler_block($user) {
	return "\n    <FilesMatch \\.php\$>\n        SetHandler \"proxy:unix:/run/php-fpm-".$user.".sock|fcgi://localhost\"\n    </FilesMatch>";
}

function apache_vhost_add_fpm($domain, $user) {
	$file = '/etc/httpd/conf.d/'.$domain.'.conf';
	$content = shell_exec('sudo cat '.escapeshellarg($file));
	if (!$content) return;
	$pos = strrpos($content, '</VirtualHost>');
	if ($pos === false) return;
	$content = substr($content, 0, $pos) . apache_fpm_handler_block($user) . "\n" . substr($content, $pos);
	$tmp = tempnam('/tmp', 'reqad');
	file_put_contents($tmp, $content);
	shell_exec('sudo cp '.escapeshellarg($tmp).' '.escapeshellarg($file));
	unlink($tmp);
}

function apache_vhost_remove_fpm($domain) {
	$file = '/etc/httpd/conf.d/'.$domain.'.conf';
	$content = shell_exec('sudo cat '.escapeshellarg($file));
	if (!$content) return;
	$content = preg_replace('/\n[ \t]*<FilesMatch[^>]*>.*?<\/FilesMatch>/s', '', $content);
	$tmp = tempnam('/tmp', 'reqad');
	file_put_contents($tmp, $content);
	shell_exec('sudo cp '.escapeshellarg($tmp).' '.escapeshellarg($file));
	unlink($tmp);
}

/* ---- PHP off / on -----------------------------------------------------------
   "Disable PHP" (Accounts > Manage, `reqad account edit --php=off`) takes PHP
   out of an account's main domain at every layer: the php-fpm pool is deleted,
   the vhost stops handing .php to an interpreter (on Apache also mod_php's
   engine is switched off and the php_admin_* lines go) and .php requests answer
   403 instead of leaking source. Nothing is saved aside to restore from:
   turning PHP back on regenerates the pool and the vhost's PHP parts from the
   same builders account creation uses, for whichever version is picked then --
   so hand edits to the old pool or PHP handler (Advanced Config) do not return.

   The marker block that takes the PHP handler's place in the vhost is also the
   state: a vhost carrying it means PHP is off (account_php() says 'off'), and on
   nginx it keeps the spot the handler goes back to -- regex locations match in
   file order, so the handler must stay below the uploads/ deny. */

function account_vhost_path($domain) {
	global $ini;
	$is_apache = (substr(trim($ini['template'] ?? ''), 0, 7) == 'apache_');
	return ($is_apache ? '/etc/httpd/conf.d/' : '/etc/nginx/conf.d/').$domain.'.conf';
}

/* Vhost content, '' when missing. conf.d files are normally world-readable, so
   sudo is only the fallback (the accounts list calls this once per account). */
function account_vhost_read($domain) {
	$file = account_vhost_path($domain);
	if (is_readable($file))
		return (string)file_get_contents($file);
	return (string)shell_exec('sudo cat '.escapeshellarg($file).' 2>/dev/null');
}

function account_php_disabled($domain) {
	return strpos(account_vhost_read($domain), '# BEGIN reqad-php-disabled') !== false;
}

/* Pool file and php-fpm service of an account's main domain on PHP $version. */
function account_fpm_pool_target($domain, $version) {
	global $ini;
	if ($version == $ini['php'])
		return array('/etc/php-fpm.d/'.$domain.'.conf', 'php-fpm.service');
	$short = str_replace('.', '', $version);
	return array('/etc/opt/remi/php'.$short.'/php-fpm.d/'.$domain.'.conf', 'php'.$short.'-php-fpm.service');
}

/* Indent every non-empty line of $block by $indent. */
function conf_indent($block, $indent) {
	return preg_replace('/^(?=.)/m', $indent, $block);
}

/* The nginx PHP handler as scripts/templates/nginx_php-fpm.php writes it, at
   column 0. $extra: directives appended after fastcgi_pass (the FastCGI cache). */
function nginx_php_location($user, $extra = '', $location = 'location ~ \.php$') {
	$extra = ($extra === '') ? '' : "\n".conf_indent(rtrim($extra, "\n"), '    ');
	return $location.' {
    try_files                   $uri =404;
    fastcgi_split_path_info     ^(.+\.php)(/.+)$;
    fastcgi_intercept_errors    off;

    #NOTE: You should have "cgi.fix_pathinfo = 0;" in php.ini
    include         /etc/nginx/fastcgi_params;
    fastcgi_index   index.php;
    fastcgi_param   SCRIPT_FILENAME     $document_root$fastcgi_script_name;
    fastcgi_pass    php-fpm-'.$user.';'.$extra.'
}';
}

/* What stands in for the nginx PHP handler while PHP is off: a .php that
   exists is refused rather than sent as text, a missing one answers 404.
   $location is the handler's own `location` line, kept so the handler comes
   back matching what it matched before. */
function nginx_php_off_location($location) {
	return '# BEGIN reqad-php-disabled
# PHP is disabled for this account (Accounts > Manage > PHP version). Choosing
# a version there writes the PHP handler back in place of this block.
'.$location.' {
    if (!-f $request_filename) {
        return 404;
    }
    return 403;
}
# END reqad-php-disabled';
}

/* Every {} block of an nginx config, in closing order, as array(start, open,
   close, head): start is where its directive begins, open/close the offsets of
   its braces, head the directive text before `{` (e.g. "location ~ \.php$").
   Comments and quoted strings are skipped (nginx makes a regex containing `{`
   be quoted). Null when the braces do not balance, rather than a guess. */
function nginx_conf_blocks($conf) {
	$blocks = array();
	$stack  = array();
	$stmt   = -1;                 // where the directive being read starts
	$len    = strlen($conf);
	for ($i = 0; $i < $len; $i++) {
		$c = $conf[$i];
		$token_start = ($i === 0 || strpos(" \t\r\n;{}", $conf[$i - 1]) !== false);
		if ($c === '#' && $token_start) {
			$nl = strpos($conf, "\n", $i);
			if ($nl === false) break;
			$i = $nl;
		} elseif (($c === '"' || $c === "'") && $token_start) {
			if ($stmt < 0) $stmt = $i;
			for ($i++; $i < $len && $conf[$i] !== $c; $i++)
				if ($conf[$i] === '\\') $i++;
		} elseif ($c === '{') {
			$stack[] = array($stmt, $i);
			$stmt = -1;
		} elseif ($c === '}') {
			if (!$stack) return null;
			list($s, $open) = array_pop($stack);
			if ($s >= 0)
				$blocks[] = array($s, $open, $i, preg_replace('/\s+/', ' ', trim(substr($conf, $s, $open - $s))));
			$stmt = -1;
		} elseif ($c === ';') {
			$stmt = -1;
		} elseif ($stmt < 0 && !ctype_space($c)) {
			$stmt = $i;
		}
	}
	return $stack ? null : $blocks;
}

/* Byte ranges of the nginx `location` blocks that pass requests to PHP, as
   array(start, end, indent, location line, fastcgi_pass targets): start is the
   beginning of the `location` line, end is just past its closing brace. Any
   fastcgi_pass counts, whatever it points at -- the vhost belongs to this one
   domain, and vhosts from older versions or edited by hand do not all use the
   template's `php-fpm-<user>` upstream. Only the innermost match is kept, so
   the `location /` around the handler is not taken. */
function nginx_php_handler_ranges($conf) {
	$blocks = nginx_conf_blocks($conf);
	if (!$blocks) return array();
	$found = array();
	foreach ($blocks as $b)
		if (preg_match('/^location\b/', $b[3])
		    && preg_match_all('/^[ \t]*fastcgi_pass\s+([^;\s]+)\s*;/m', substr($conf, $b[1] + 1, $b[2] - $b[1] - 1), $t))
			$found[] = array($b[0], $b[2] + 1, $b[3], $t[1]);

	$ranges = array();
	foreach ($found as $b) {
		foreach ($found as $o)
			if ($o !== $b && $o[0] >= $b[0] && $o[1] <= $b[1]) continue 2;
		$ls   = strrpos(substr($conf, 0, $b[0]), "\n");
		$ls   = ($ls === false) ? 0 : $ls + 1;
		$lead = substr($conf, $ls, $b[0] - $ls);
		$ranges[] = (trim($lead) === '') ? array($ls, $b[1], $lead, $b[2], $b[3]) : array($b[0], $b[1], '', $b[2], $b[3]);
	}
	usort($ranges, function($a, $b) { return $a[0] - $b[0]; });
	return $ranges;
}

/* nginx vhost with PHP off. Every PHP handler is swapped for the PHP-off block;
   a vhost with no handler to swap (PHP passed through an include, or not at
   all) gets the block added to each server that serves files (has a `root`),
   so the state is recorded and .php is refused either way. The upstreams the
   handlers passed to, and `php-fpm-<user>`, go too when nothing passes to
   them any more -- unless $used_elsewhere($name) says another file does
   (upstream names are global to nginx, so removing it would fail nginx -t).
   Null only when the braces do not balance. */
function nginx_vhost_php_off($conf, $user, $used_elsewhere = null) {
	$ranges  = nginx_php_handler_ranges($conf);
	$targets = array('php-fpm-'.$user);
	if ($ranges) {
		foreach (array_reverse($ranges) as $r) {   // back to front keeps the offsets valid
			$conf = substr($conf, 0, $r[0]).conf_indent(nginx_php_off_location($r[3]), $r[2]).substr($conf, $r[1]);
			$targets = array_merge($targets, $r[4]);
		}
	} else {
		$blocks = nginx_conf_blocks($conf);
		if ($blocks === null) return null;
		$servers = array();
		foreach ($blocks as $b)
			if ($b[3] === 'server')
				$servers[] = $b;
		$with_root = array_filter($servers, function($b) use ($conf) {
			return preg_match('/^[ \t]*root\s/m', substr($conf, $b[1], $b[2] - $b[1]));
		});
		if ($with_root) $servers = $with_root;
		if (!$servers) return null;
		usort($servers, function($a, $b) { return $b[2] - $a[2]; });   // back to front
		foreach ($servers as $b) {
			// before the line holding the server's closing brace, one level in
			$ls     = strrpos(substr($conf, 0, $b[2]), "\n");
			$ls     = ($ls === false) ? 0 : $ls + 1;
			$lead   = substr($conf, $ls, $b[2] - $ls);
			$indent = (trim($lead) === '' ? $lead : '').'    ';
			$at     = (trim($lead) === '') ? $ls : $b[2];
			$conf   = substr($conf, 0, $at)."\n".conf_indent(nginx_php_off_location('location ~ \.php$'), $indent)."\n"
			        .(trim($lead) === '' ? '' : "\n").substr($conf, $at);
		}
	}
	foreach (array_unique($targets) as $name) {
		if (strpos($name, 'unix:') === 0 || strpos($name, '$') !== false) continue;  // not an upstream
		if (preg_match('/^[ \t]*fastcgi_pass\s+'.preg_quote($name, '/').'\s*;/m', $conf)) continue;  // still used here
		if ($used_elsewhere && $used_elsewhere($name)) continue;
		$conf = preg_replace(nginx_php_upstream_re($name), '', $conf);
	}
	return nginx_php_off_lines($conf);
}

/* The `upstream <name> { ... }` block, with the blank line after it. */
function nginx_php_upstream_re($name) {
	return '/^[ \t]*upstream[ \t]+'.preg_quote($name, '/').'[ \t]*\{[^}]*\}[^\n]*\n(?:[ \t]*\n)?/m';
}

/* The upstream block as scripts/templates/nginx_php-fpm.php writes it. */
function nginx_php_upstream($user) {
	return 'upstream php-fpm-'.$user.' {
    server   unix:/run/php-fpm-'.$user.'.sock;
}
';
}

/* Does an nginx config file other than $own pass to upstream $name? Then its
   upstream block must stay even while the account's own PHP is off. */
function nginx_upstream_used_elsewhere($name, $own) {
	$re = '/^[ \t]*fastcgi_pass\s+'.preg_quote($name, '/').'\s*;/m';
	foreach ((array)glob('/etc/nginx/conf.d/*.conf') as $f) {
		if ($f === $own) continue;
		$c = is_readable($f) ? (string)file_get_contents($f) : (string)shell_exec('sudo cat '.escapeshellarg($f).' 2>/dev/null');
		if (preg_match($re, $c)) return true;
	}
	return false;
}

/* While PHP is off, nothing may route to a .php file: a leftover index.php would
   otherwise win `index` over index.html, and the `try_files ... /index.php`
   fallback would send every missing page to it -- both then answer 403. So
   index.php leaves the `index` lists and a .php try_files fallback becomes
   =404. Each line changed is tagged with a trailing `# reqad-php-disabled`,
   which is what nginx_php_on_lines() regenerates. Safe to run twice. */
function nginx_php_off_lines($conf) {
	$conf = preg_replace_callback('/^([ \t]*index\s+)([^;#\n]*);[ \t]*$/m', function($m) {
		$names = preg_split('/\s+/', trim($m[2]));
		if (!in_array('index.php', $names, true)) return $m[0];
		$names = array_values(array_diff($names, array('index.php')));
		if (!$names) $names = array('index.html', 'index.htm');
		return $m[1].implode(' ', $names).'; # reqad-php-disabled';
	}, $conf);
	return preg_replace_callback('/^([ \t]*try_files\s+)([^;#\n]*);[ \t]*$/m', function($m) {
		$args = preg_split('/\s+/', trim($m[2]));
		if (count($args) < 2 || strpos(end($args), '.php') === false) return $m[0];
		$args[count($args) - 1] = '=404';
		return $m[1].implode(' ', $args).'; # reqad-php-disabled';
	}, $conf);
}

/* The lines nginx_php_off_lines() tagged, rebuilt as the vhost template writes
   them: index.php first in `index`, /index.php$is_args$args as the fallback. */
function nginx_php_on_lines($conf) {
	$conf = preg_replace_callback('/^([ \t]*index\s+)([^;#\n]*);[ \t]*# reqad-php-disabled[ \t]*$/m', function($m) {
		$names = array_values(array_diff(preg_split('/\s+/', trim($m[2])), array('index.php')));
		return $m[1].implode(' ', array_merge(array('index.php'), $names)).';';
	}, $conf);
	return preg_replace_callback('/^([ \t]*try_files\s+)([^;#\n]*);[ \t]*# reqad-php-disabled[ \t]*$/m', function($m) {
		$args = preg_split('/\s+/', trim($m[2]));
		$args[count($args) - 1] = '/index.php$is_args$args';
		return $m[1].implode(' ', $args).';';
	}, $conf);
}

/* nginx vhost with each PHP-off block replaced by a freshly built handler. When
   the WP Toolkit FastCGI cache is on (its http-level block is still there), its
   directives go back into the first handler, as wp_nginx_cache_enable() puts them. */
function nginx_vhost_php_on($conf, $user) {
	$fcgi = '';
	if (preg_match('/^# BEGIN reqad-nginx-cache[ \t]*$/m', $conf) && strpos($conf, '# BEGIN reqad-nginx-cache-fcgi') === false) {
		$frag = wp_nginx_cache_fragments(wp_cache_zone($user), '');
		$fcgi = preg_replace('/^[ \t]+/m', '', $frag['fcgi']);
	}
	$conf = preg_replace_callback('/^([ \t]*)# BEGIN reqad-php-disabled\b.*?# END reqad-php-disabled[^\n]*/ms',
		function($m) use ($user, &$fcgi) {
			$location = preg_match('/^[ \t]*(location\b.*?)\s*\{[ \t]*$/m', $m[0], $l) ? $l[1] : 'location ~ \.php$';
			$block = conf_indent(nginx_php_location($user, $fcgi, $location), $m[1]);
			$fcgi  = '';
			return $block;
		}, $conf);
	// upstream back where the template has it: right before the first server block
	if (!preg_match('/^upstream[ \t]+php-fpm-'.preg_quote($user, '/').'[ \t]*\{/m', $conf)
	    && preg_match('/^server[ \t]*\{/m', $conf, $sv, PREG_OFFSET_CAPTURE))
		$conf = substr($conf, 0, $sv[0][1]).nginx_php_upstream($user)."\n".substr($conf, $sv[0][1]);
	return nginx_php_on_lines($conf);
}

/* What goes into each Apache <VirtualHost> while PHP is off: mod_php may not run
   (php_admin_flag cannot be undone from .htaccess) and .php is refused, not
   sent as text. Both module names: PHP 7 registers php7_module, PHP 8 php_module.
   DirectoryIndex leaves index.php out, so a leftover one does not shadow
   index.html; removing the block brings back the server-wide list. */
function apache_php_off_block() {
	return '# BEGIN reqad-php-disabled
# PHP is disabled for this account (Accounts > Manage > PHP version). Choosing
# a version there takes this block out and writes the PHP settings back.
<IfModule php7_module>
    php_admin_flag engine off
</IfModule>
<IfModule php_module>
    php_admin_flag engine off
</IfModule>
<FilesMatch "\.(php|phar|phtml)$">
    Require all denied
</FilesMatch>
DirectoryIndex index.html index.htm
# END reqad-php-disabled';
}

/* The account's mod_php settings, taken from the vhost template account
   creation uses (scripts/templates/apache-ssl.conf), so there is one source. */
function apache_php_directives($domain, $user) {
	$tpl = (string)@file_get_contents(_PATH.'/scripts/templates/apache-ssl.conf');
	preg_match_all('/^[ \t]*php_(admin_)?(value|flag)\b[^\n]*$/m', $tpl, $m);
	return str_replace(array('%USER%', '%DOMAIN%'), array($user, $domain), implode("\n", $m[0]));
}

/* Apache vhost with PHP off: the php-fpm SetHandler and every php_* directive
   removed, the PHP-off block added to each <VirtualHost>. Null when there is
   no <VirtualHost> to put it in. */
function apache_vhost_php_off($conf, $user) {
	if (strpos($conf, '</VirtualHost>') === false) return null;
	$conf = preg_replace('/\n[ \t]*<FilesMatch[^>]*>\s*SetHandler\s+"proxy:unix:\/run\/php-fpm-'.preg_quote($user, '/').'\.sock[^"]*"\s*<\/FilesMatch>/', '', $conf);
	$conf = preg_replace('/^[ \t]*php_(admin_)?(value|flag)\b[^\n]*\n/m', '', $conf);
	return preg_replace_callback('/^[ \t]*<\/VirtualHost>/m', function($m) {
		return conf_indent(apache_php_off_block(), '    ')."\n".$m[0];
	}, $conf);
}

/* Apache vhost with PHP back on: PHP-off blocks removed, the mod_php settings
   (and for $handler 'fpm' the SetHandler) added to the last <VirtualHost> --
   the :443 one, where the template keeps them. */
function apache_vhost_php_on($conf, $domain, $user, $handler) {
	$conf = preg_replace('/^[ \t]*# BEGIN reqad-php-disabled\b.*?# END reqad-php-disabled[^\n]*\n/ms', '', $conf);
	$pos  = strrpos($conf, '</VirtualHost>');
	if ($pos === false) return null;
	$ls   = strrpos(substr($conf, 0, $pos), "\n");
	$ls   = ($ls === false) ? 0 : $ls + 1;
	$add  = "\n".apache_php_directives($domain, $user)."\n";
	if ($handler == 'fpm')
		$add .= ltrim(apache_fpm_handler_block($user), "\n")."\n";
	return substr($conf, 0, $ls).$add.substr($conf, $ls);
}

/* Write a vhost, test the web server config and reload it. A failed test puts
   $orig back. Returns '' or the error. */
function account_vhost_apply($file, $new, $orig, $is_apache) {
	$tmp = tempnam('/tmp', 'reqad');
	file_put_contents($tmp, $new);
	shell_exec('sudo cp '.escapeshellarg($tmp).' '.escapeshellarg($file));
	$test = (string)shell_exec($is_apache ? 'sudo httpd -t 2>&1' : 'sudo nginx -t 2>&1');
	if (stripos($test, 'test is successful') === false && stripos($test, 'syntax ok') === false) {
		file_put_contents($tmp, $orig);
		shell_exec('sudo cp '.escapeshellarg($tmp).' '.escapeshellarg($file));
		unlink($tmp);
		return 'The web server configuration test failed, nothing was changed: '.trim(preg_replace('/\s+/', ' ', $test));
	}
	unlink($tmp);
	shell_exec('sudo systemctl reload '.($is_apache ? 'httpd' : 'nginx').' >> '._PATH.'/log/debug_log 2>&1');
	return '';
}

/* Turn PHP off for an account's main domain. Returns '' or why nothing changed. */
function account_php_disable($domain, $user) {
	global $ini;
	$is_apache = (substr(trim($ini['template']), 0, 7) == 'apache_');
	$file = account_vhost_path($domain);
	$orig = account_vhost_read($domain);
	if (trim($orig) === '')
		return "Vhost $file not found.";
	$new = $is_apache ? apache_vhost_php_off($orig, $user)
	                  : nginx_vhost_php_off($orig, $user, function($name) use ($file) { return nginx_upstream_used_elsewhere($name, $file); });
	if ($new === null)
		return $is_apache ? "No <VirtualHost> found in $file." : "Could not read the block structure of $file (unbalanced braces, or no server block).";
	if (($err = account_vhost_apply($file, $new, $orig, $is_apache)) !== '')
		return $err;

	// The vhost no longer points at it, so the pool can go
	$restart = array();
	foreach (array_map('trim', explode(',', $ini['php_versions'])) as $phpv) {
		list($pool, $service) = account_fpm_pool_target($domain, $phpv);
		if (is_file($pool)) {
			shell_exec('sudo rm -f '.escapeshellarg($pool));
			$restart[] = $service;
		}
	}
	if ($restart)
		shell_exec('sudo '._PATH.'/scripts/restart_services.sh '.implode(' ', array_unique($restart)).' 2>/dev/null >/dev/null &');
	return '';
}

/* Turn PHP back on for an account's main domain, on $version run by $handler
   ('fpm', or 'mod_php' on Apache). Returns '' or why nothing changed. */
function account_php_enable($domain, $user, $version, $handler) {
	global $ini;
	$is_apache = (substr(trim($ini['template']), 0, 7) == 'apache_');
	$file = account_vhost_path($domain);
	$orig = account_vhost_read($domain);
	if (trim($orig) === '')
		return "Vhost $file not found.";
	$new = $is_apache ? apache_vhost_php_on($orig, $domain, $user, $handler) : nginx_vhost_php_on($orig, $user);
	if ($new === null || $new === $orig)
		return "No PHP-disabled block found in $file.";

	// Pool first, so the socket the new vhost points at comes up with the restart
	$pool = null;
	if ($handler == 'fpm') {
		list($pool, $service) = account_fpm_pool_target($domain, $version);
		$tmp = tempnam('/tmp', 'reqad');
		file_put_contents($tmp, account_fpm_pool($domain, $user, $is_apache ? 'apache' : 'nginx'));
		shell_exec('sudo cp '.escapeshellarg($tmp).' '.escapeshellarg($pool));
		unlink($tmp);
		if (!is_file($pool))
			return "Could not write the php-fpm pool $pool.";
	}
	if (($err = account_vhost_apply($file, $new, $orig, $is_apache)) !== '') {
		if ($pool !== null)
			shell_exec('sudo rm -f '.escapeshellarg($pool));
		return $err;
	}
	if ($pool !== null)
		shell_exec('sudo '._PATH.'/scripts/restart_services.sh '.$service.' 2>/dev/null >/dev/null &');
	account_bashrc_php($user, $version);
	return '';
}

/* Point the account's CLI `php` (~/.bashrc PATH) at $version. */
function account_bashrc_php($user, $version) {
	global $ini;
	$bashrc = '/home/'.$user.'/.bashrc';
	shell_exec('sudo sed -i \'/^export PATH="\/opt\/remi\/php/d\' '.escapeshellarg($bashrc).' 2>/dev/null');
	if ($version != $ini['php']) {
		$path_export = 'export PATH="/opt/remi/php'.str_replace('.', '', $version).'/root/usr/bin/:$PATH"';
		shell_exec('echo '.escapeshellarg($path_export).' | sudo tee -a '.escapeshellarg($bashrc).' > /dev/null');
	}
}

/* Move an account to another PHP version and/or handler (fpm | mod_php, the
   latter Apache + default version only), then restart what changed. */
function account_switch_php($domain, $user, $old_version, $old_handler, $new_version, $new_handler) {
	global $ini;
	$is_apache        = (substr(trim($ini['template']), 0, 7) == 'apache_');
	$restart_services = '';

	if ($is_apache) {
		$new_short = str_replace('.', '', $new_version);
		$old_short = str_replace('.', '', $old_version);
		$old_is_default = ($old_version == $ini['php']);
		$new_is_default = ($new_version == $ini['php']);

		// Pool file paths (null = mod_php, no pool)
		if ($old_is_default && $old_handler == 'fpm')  $old_pool = '/etc/php-fpm.d/'.$domain.'.conf';
		elseif (!$old_is_default)                       $old_pool = '/etc/opt/remi/php'.$old_short.'/php-fpm.d/'.$domain.'.conf';
		else                                            $old_pool = null;

		if ($new_is_default && $new_handler == 'fpm')  $new_pool = '/etc/php-fpm.d/'.$domain.'.conf';
		elseif (!$new_is_default)                       $new_pool = '/etc/opt/remi/php'.$new_short.'/php-fpm.d/'.$domain.'.conf';
		else                                            $new_pool = null;

		// SetHandler in the vhost when switching between mod_php and fpm
		if ($old_handler == 'mod_php' && $new_handler == 'fpm')
			apache_vhost_add_fpm($domain, $user);
		elseif ($old_handler == 'fpm' && $new_handler == 'mod_php')
			apache_vhost_remove_fpm($domain);

		if ($old_pool === null && $new_pool !== null) {
			// mod_php -> fpm: create pool
			$tmp = tempnam('/tmp', 'reqad');
			file_put_contents($tmp, apache_fpm_pool($domain, $user));
			shell_exec('sudo cp '.escapeshellarg($tmp).' '.escapeshellarg($new_pool));
			unlink($tmp);
		} elseif ($old_pool !== null && $new_pool === null) {
			// fpm -> mod_php: delete pool
			shell_exec('sudo rm -f '.escapeshellarg($old_pool));
		} elseif ($old_pool !== null && $new_pool !== null && $old_pool !== $new_pool) {
			// fpm -> fpm on another version: move pool (socket path unchanged)
			shell_exec('sudo mv '.escapeshellarg($old_pool).' '.escapeshellarg(dirname($new_pool).'/'));
		}

		if ($old_pool !== null)
			$restart_services .= ($old_is_default ? 'php-fpm.service' : 'php'.$old_short.'-php-fpm.service').' ';
		if ($new_pool !== null && $new_pool !== $old_pool)
			$restart_services .= ($new_is_default ? 'php-fpm.service' : 'php'.$new_short.'-php-fpm.service').' ';
		$restart_services .= 'httpd.service';
	} else {
		// nginx: always fpm, move the pool file between version directories
		if ($old_version == $ini['php']) {
			$move_from = '/etc/php-fpm.d/'.$domain.'.conf';
			$restart_services = 'php-fpm.service ';
		} else {
			$short = str_replace('.', '', $old_version);
			$move_from = '/etc/opt/remi/php'.$short.'/php-fpm.d/'.$domain.'.conf';
			$restart_services = 'php'.$short.'-php-fpm.service ';
		}
		if ($new_version == $ini['php']) {
			$move_to = '/etc/php-fpm.d/';
			$restart_services .= 'php-fpm.service';
		} else {
			$short = str_replace('.', '', $new_version);
			$move_to = '/etc/opt/remi/php'.$short.'/php-fpm.d/';
			$restart_services .= 'php'.$short.'-php-fpm.service';
		}
		shell_exec('sudo mv '.escapeshellarg($move_from).' '.escapeshellarg($move_to));
	}

	shell_exec('sudo '._PATH.'/scripts/restart_services.sh '.trim($restart_services).' 2>/dev/null >/dev/null &');

	// ~/.bashrc CLI PHP path follows the version (not the handler)
	if ($new_version != $old_version)
		account_bashrc_php($user, $new_version);
}

/*
 * Edit a hosting account. Only the keys present in $o are changed.
 *
 * $o keys:
 *   user       required -- the account to edit
 *   domain     optional -- if given it must be the account's main domain
 *   password   new system password ('' or absent = unchanged)
 *   php        "8.2" or "8.2:fpm" / "8.2:mod_php" (Apache), or "off" to disable PHP
 *   email      bool -- enable / disable mail for the domain
 */
function account_edit($db, array $o) {
	global $ini;

	$user     = trim((string)($o['user'] ?? ''));
	$domain   = strtolower(trim((string)($o['domain'] ?? '')));
	$password = trim((string)($o['password'] ?? ''));

	if (!valid_username($user))
		return result_error("Username must be 2-16 characters, lowercase letters and numbers only, starting with a letter.");
	$row = account_get($db, $user);
	if (!$row)
		return result_error("User $user does not exist on server.");
	if ($domain !== '' && $domain !== $row['domain'])
		return result_error("Domain $domain does not belong to account $user.");
	$domain = $row['domain'];

	if ($password !== '' && ($err = account_password_error($password)) !== '')
		return result_error($err);

	$is_apache = (substr(trim($ini['template']), 0, 7) == 'apache_');
	list($old_version, $old_handler) = account_php($domain);
	$new_version = $old_version;
	$new_handler = $old_handler;
	if (isset($o['php']) && trim($o['php']) === 'off') {
		$new_version = $new_handler = 'off';
	} elseif (isset($o['php']) && trim($o['php']) !== '') {
		$php = trim($o['php']);
		if (strpos($php, ':') !== false)
			list($new_version, $new_handler) = explode(':', $php, 2);
		elseif ($is_apache && $old_handler == 'off') {
			// PHP coming back on Apache with no handler given: what account creation picks
			$new_version = $php;
			$new_handler = ($php == $ini['php']) ? 'mod_php' : 'fpm';
		} else {
			$new_version = $php;
			$new_handler = $is_apache ? $old_handler : 'fpm';
		}
		if (!in_array($new_version, array_map('trim', explode(',', $ini['php_versions'])), true))
			return result_error("PHP $new_version is not installed (available: ".$ini['php_versions'].").");
		if (!in_array($new_handler, $is_apache ? array('fpm', 'mod_php') : array('fpm'), true))
			return result_error("Unknown PHP handler '$new_handler'.");
		if ($new_handler == 'mod_php' && $new_version != $ini['php'])
			return result_error("mod_php is only available for the default PHP version ".$ini['php'].".");
	}

	$has_email = ($row['has_email'] === 'true' || $row['has_email'] == 1);
	$want_email = array_key_exists('email', $o) && $o['email'] !== null ? (bool)$o['email'] : $has_email;

	$provider = dns_provider_load($db);
	$warnings = array();
	$changes  = array();

	route_log("edit account $user");

	/* PHP first: turning it off or on can fail (vhost without a PHP handler,
	   web server test failing), and then nothing else may have changed yet. */
	if ($new_version == 'off' && $old_version != 'off') {
		if (($err = account_php_disable($domain, $user)) !== '')
			return result_error("PHP was not disabled: $err");
		$changes[] = 'php off';
	} elseif ($old_version == 'off' && $new_version != 'off') {
		if (($err = account_php_enable($domain, $user, $new_version, $new_handler)) !== '')
			return result_error("PHP was not enabled: $err");
		$changes[] = 'php '.$new_version.($is_apache ? ' ('.$new_handler.')' : '');
	} elseif ($new_version != $old_version || $new_handler != $old_handler) {
		account_switch_php($domain, $user, $old_version, $old_handler, $new_version, $new_handler);
		$changes[] = 'php '.$new_version.($is_apache ? ' ('.$new_handler.')' : '');
	}

	if ($password !== '') {
		if (!set_system_password($user, $password))
			return result_error("Failed to set the account password.");
		$changes[] = 'password';
	}

	if (isset($ini['email']) && $ini['email'] == 1 && $want_email != $has_email) {
		if ($want_email && $provider == 'cloudflare' && main_domain($domain) != '') {
			$warnings[] = 'Cannot enable email for a subdomain on Cloudflare.';
		} else {
			if ($want_email) {
				$warnings = array_merge($warnings, account_email_enable($domain, $user, true));
				$db->exec('UPDATE accounts SET has_email=1 WHERE user="'.$db->escapeString($user).'"');
				log_debug("[account_edit] enable mail for $domain $user");
			} else {
				$warnings = array_merge($warnings, account_email_disable($domain, $user));
				$db->exec('UPDATE accounts SET has_email=0 WHERE user="'.$db->escapeString($user).'"');
				log_debug("[account_edit] disable mail for $domain $user");
			}
			/* mail.<domain> vhost server_name + cert coverage depends on email
			   state; rewrite the vhost and (if Let's Encrypt) re-issue the
			   certificate to add/remove mail.<domain>. */
			$warnings[] = apply_account_vhost_names($db, $domain, $is_apache);
			reissue_letsencrypt_cert($db, $domain, $want_email);
			$changes[] = 'email '.($want_email ? 'on' : 'off');
		}
	}

	return result_ok("Account $user successfully updated.", array(
		'user' => $user, 'domain' => $domain, 'changed' => $changes,
		'php' => $new_version, 'php_handler' => $new_handler,
	), $warnings);
}

/*
 * Delete a hosting account and everything that belongs to it: vhost, php-fpm
 * pool, mail (exim/dovecot/DKIM/filters/autoresponders), certificates, DNS
 * zone, MySQL databases and users prefixed "<user>_", and the home directory.
 */
function account_delete($db, $user) {
	global $ini;

	$user = trim((string)$user);
	if ($user === '')
		return result_error("Username is empty (missing).");
	if (in_array($user, account_reserved_names(), true))
		return result_error("You cannot delete a system user.");
	if (!valid_username($user))
		return result_error("Username must be 2-16 characters, lowercase letters and numbers only, starting with a letter.");
	$row = account_get($db, $user);
	if (!$row)
		return result_error("Username does not exist in database.");
	$domain = $row['domain'];
	if (!valid_domain($domain))
		return result_error("Account $user has an invalid domain in the database: '$domain'.");

	dns_provider_load($db);
	$is_apache = (substr(trim($ini['template']), 0, 7) == 'apache_');
	$qd        = escapeshellarg($domain);
	$warnings  = array();

	route_log("delete account $user");
	$db->exec('DELETE FROM accounts WHERE user="'.$db->escapeString($user).'"');
	$db->exec('DELETE FROM wordpress WHERE user="'.$db->escapeString($user).'"');
	$db->exec('DELETE FROM emails WHERE email LIKE "%@'.$db->escapeString($domain).'"');
	/* aliases.alias is UNIQUE: a leftover row made re-creating the same domain
	   with its www alias silently skip the alias insert. */
	$db->exec('DELETE FROM aliases WHERE account_id='.(int)$row['id']);

	$output = shell_exec('sudo rm -f '.escapeshellarg(($is_apache ? '/etc/httpd/conf.d/' : '/etc/nginx/conf.d/').$domain.'.conf').' 2>&1');

	shell_exec('sudo sed -i '.escapeshellarg('/^'.addcslashes($domain, '.').':'.$user.'$/d').' /etc/exim/userdomains');
	shell_exec('sudo rm -f '.escapeshellarg('/etc/exim/domains/'.$domain));
	shell_exec('sudo rm -f '.escapeshellarg('/etc/exim/forwards/'.$domain));
	shell_exec('sudo rm -f '.escapeshellarg('/etc/exim/keys/'.$domain.'.private.key'));
	shell_exec('sudo rm -f '.escapeshellarg('/etc/exim/keys/'.$domain.'.public.key'));
	shell_exec('sudo sed -i '.escapeshellarg('/@'.addcslashes($domain, '.').':/d').' /etc/dovecot/users');
	shell_exec('sudo rm -rf '.escapeshellarg('/etc/exim/autoreply/'.$domain));

	$restart_services = $is_apache ? 'httpd.service ' : '';
	foreach (array_map('trim', explode(',', $ini['php_versions'])) as $phpv) {
		$short = str_replace('.', '', $phpv);
		if ($phpv == $ini['php'] && is_file('/etc/php-fpm.d/'.$domain.'.conf')) {
			$restart_services .= 'php-fpm.service ';
			$output .= shell_exec('sudo rm -f '.escapeshellarg('/etc/php-fpm.d/'.$domain.'.conf').' 2>&1');
		} else if (is_file('/etc/opt/remi/php'.$short.'/php-fpm.d/'.$domain.'.conf')) {
			$restart_services .= 'php'.$short.'-php-fpm.service ';
			$output .= shell_exec('sudo rm -f '.escapeshellarg('/etc/opt/remi/php'.$short.'/php-fpm.d/'.$domain.'.conf').' 2>&1');
		}
	}

	/* Email filters and autoresponders live in the panel db and under
	   /var/lib/reqad/sieve -- outside the account's home, so `rm -rf /home/$user`
	   does not touch them. Left behind, they come back to life the moment the
	   same domain is recreated. */
	foreach (ef_purge_domain($db, $domain) as $line)
		route_log("delete account $user: $line");

	$output .= shell_exec('sudo rm -f '.escapeshellarg('/etc/ssl/certs/'.$domain.'.key').' 2>&1');
	$output .= shell_exec('sudo rm -f '.escapeshellarg('/etc/ssl/certs/'.$domain.'.crt').' 2>&1');
	$output .= shell_exec('sudo rm -rf '.escapeshellarg('/home/'.$user).' 2>&1');
	shell_exec('echo Y | sudo certbot --non-interactive delete --cert-name '.$qd.' >> '._PATH.'/log/debug_log 2>&1');
	$warnings[] = delete_domain_from_dns($domain);
	shell_exec('sudo '._PATH.'/scripts/restart_services.sh '.trim($restart_services).' 2>/dev/null >/dev/null &');
	shell_exec('sudo '._PATH.'/scripts/delete_user.sh '.escapeshellarg($user).' 2>/dev/null >/dev/null &');
	if (trim((string)$output) !== '')
		$warnings[] = trim($output);

	/* Drop all databases and MySQL users prefixed with this account username.
	   The '_' is escaped as '\_' because in a LIKE pattern a bare '_' matches
	   ANY single character -- 'foo_%' would also match another account's
	   'food_blog'. '\' is MySQL's default LIKE escape character. */
	$dropped_dbs = array();
	$assigned    = database_assignments();
	foreach (mysql_rows('SHOW DATABASES LIKE '.mysql_quote($user.'\\_%')) as $mysql_db) {
		/* assigned by hand to another account (db_owners wins over the prefix) */
		if (isset($assigned[$mysql_db]) && $assigned[$mysql_db] !== $user)
			continue;
		if ($mysql_db !== '' && valid_mysql_identifier($mysql_db)) {
			mysql_exec('DROP DATABASE IF EXISTS `'.$mysql_db.'`');
			$dropped_dbs[] = $mysql_db;
		}
	}
	foreach (mysql_rows('SELECT User FROM mysql.user WHERE User LIKE '.mysql_quote($user.'\\_%')) as $mysql_user) {
		if ($mysql_user !== '' && valid_mysql_identifier($mysql_user))
			mysql_exec('DROP USER IF EXISTS `'.$mysql_user.'`@`localhost`');
	}
	mysql_exec('FLUSH PRIVILEGES');

	/* Databases assigned to the account by hand are released, not dropped: they
	   were not created for it, and their names do not say they were its. */
	$released_dbs = array_keys($assigned, $user, true);
	$stmt = $db->prepare('DELETE FROM db_owners WHERE user = :u');
	if ($stmt) {
		$stmt->bindValue(':u', $user, SQLITE3_TEXT);
		$stmt->execute();
	}
	backup_exclude_forget($user);

	if ($released_dbs)
		$warnings[] = 'Database'.(count($released_dbs) > 1 ? 's' : '').' '.implode(', ', $released_dbs)
			.' assigned to '.$user.' '.(count($released_dbs) > 1 ? 'were' : 'was').' kept and '
			.(count($released_dbs) > 1 ? 'are' : 'is').' now unassigned.';

	return result_ok("Account $user successfully removed.", array(
		'user' => $user, 'domain' => $domain, 'databases_dropped' => $dropped_dbs,
		'databases_released' => $released_dbs,
	), $warnings);
}
