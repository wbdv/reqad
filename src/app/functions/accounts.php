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

/*
 * The account panel (2083) signs in with the account's own system password:
 * its hash in /etc/shadow, the one SSH, SFTP and FTP use, and the one an
 * account migrated from another server brings along unchanged. Root only
 * (the 2083 broker, the CLI): anyone else cannot read /etc/shadow.
 *
 * 'ok', 'bad' (wrong password, no password, not a hosting account), 'locked'
 * ('!' in front of the hash: passwd -l / usermod -L) or 'expired' (the
 * account's expiry date has passed, e.g. a Hosting Pro suspension). Locked and
 * expired are only said when the password itself is right.
 */
function account_password_verify($user, $password) {
	global $db;
	$user = (string)$user;
	$password = (string)$password;
	$e = ($password !== '' && strlen($password) <= 256 && valid_username($user) && account_get($db, $user))
	   ? account_shadow_entry($user) : null;
	$hash = $e ? $e[1] : '';
	$locked = $hash !== '' && $hash[0] === '!';
	if ($locked)
		$hash = ltrim($hash, '!');
	if (!preg_match('/^\$[0-9a-z]+\$/', $hash)) {
		/* no password ('', '!!', '*'): spend about the time a real check takes */
		crypt($password, '$6$'.bin2hex(random_bytes(8)).'$');
		return 'bad';
	}
	if (!system_hash_verify($hash, $password))
		return 'bad';
	if ($locked)
		return 'locked';
	if ($e[7] !== '' && (int)floor(time() / 86400) >= (int)$e[7])
		return 'expired';
	return 'ok';
}

/* The /etc/shadow fields of $user (0 name, 1 hash, ... 7 expiry day), or null. */
function account_shadow_entry($user) {
	if (function_exists('posix_geteuid') && posix_geteuid() !== 0)
		return null;
	$fh = @fopen('/etc/shadow', 'r');
	if (!$fh)
		return null;
	$pre = $user.':';
	$found = null;
	while (($line = fgets($fh)) !== false)
		if (strncmp($line, $pre, strlen($pre)) === 0) {
			$found = explode(':', rtrim($line, "\r\n"));
			break;
		}
	fclose($fh);
	return $found && count($found) >= 8 ? $found : null;
}

/* $password against a crypt(3) hash from /etc/shadow. PHP knows MD5, SHA-256,
   SHA-512 and bcrypt; anything else (yescrypt $y$ ...) goes to the system's
   own crypt through Python, hash and password on stdin (never in ps). */
function system_hash_verify($hash, $password) {
	if (preg_match('/^\$(1|5|6|2[abxy])\$/', $hash)) {
		$c = crypt($password, $hash);
		return is_string($c) && strlen($c) > 13 && hash_equals($hash, $c);
	}
	$py = is_executable('/usr/libexec/platform-python') ? '/usr/libexec/platform-python' : '/usr/bin/python3';
	$code = 'import sys,hmac,warnings;warnings.simplefilter("ignore");import crypt;'
	      .'d=sys.stdin.buffer.read().decode("utf-8","surrogateescape");h,_,p=d.partition("\n");'
	      .'c=crypt.crypt(p,h);sys.exit(0 if c and hmac.compare_digest(c,h) else 1)';
	$proc = @proc_open(array($py, '-I', '-c', $code), array(0 => array('pipe', 'r'), 1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes);
	if (!is_resource($proc))
		return false;
	fwrite($pipes[0], $hash."\n".$password);
	fclose($pipes[0]);
	return proc_close($proc) === 0;
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

/* Everything about one account, for `reqad account show` and GET
   /api/v1/accounts/<user>; null when there is no such account. */
function account_info($db, $user) {
	$row = account_get($db, $user);
	if (!$row)
		return null;
	list($php, $handler) = account_php($row['domain']);
	$data = array(
		'uid' => $row['id'], 'user' => $row['user'], 'domain' => $row['domain'],
		'status' => $row['status'], 'created_at' => $row['created_at'],
		'email' => ($row['has_email'] === 'true' || $row['has_email'] == 1),
		'disk_usage' => $row['disk_usage'], 'disk_quota' => $row['disk_quota'],
		'php' => $php, 'php_handler' => $handler, 'shell' => account_shell_get($row['user']),
		'mailboxes' => array_column(email_list($row['domain']), 'email'),
		'databases' => array_column(database_list($row['user']), 'name'),
	);
	/* the configured limits; usage now and hits: 'account limits <user>' */
	$lim = account_session_limits($row['user'], 0);
	$data['session_limits'] = $lim ? array('mode' => $lim['mode'], 'cpu' => $lim['cpu'],
		'memory_mb' => $lim['memory_mb'], 'processes' => $lim['tasks']) : null;
	$data['db_connections'] = array('per_user' => database_conn_limit($row['user']),
		'default' => database_conn_is_default($row['user']));
	/* plugins add theirs (hosting packages: the package, its limits, usage) */
	$more = plugin_filter('account.info', $data, $row['user']);
	return is_array($more) ? $more : $data;
}

/* PHP version an account currently runs and how: array(version, 'fpm'|'mod_php'),
   or array('off', 'off') when PHP is disabled (see account_php_disable()).
   The pool file's location is the authority -- a non-default version has its
   pool under /etc/opt/remi/phpXY/, the default one under /etc/php-fpm.d/, and
   on Apache no pool at all means mod_php. */
function account_php($domain) {
	global $ini;
	/* a switch earlier in this request moved the pool file: no cached stat */
	clearstatcache();
	if (account_php_disabled($domain))
		return array('off', 'off');
	$modphp    = (php_mode() === 'modphp');
	$version   = $ini['php'];
	$handler   = $modphp ? 'mod_php' : 'fpm';
	foreach (array_map('trim', explode(',', $ini['php_versions'])) as $phpv) {
		if ($phpv == $ini['php']) continue;
		if (is_file('/etc/opt/remi/php'.str_replace('.', '', $phpv).'/php-fpm.d/'.$domain.'.conf'))
			return array($phpv, 'fpm');
	}
	if ($modphp && is_file('/etc/php-fpm.d/'.$domain.'.conf'))
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
   the create path always writes the local MX. $dns = false: no DNS records
   (an addon domain's, see domain_mail_enable()). An account can have several
   mail domains: one userdomains line each. Returns warnings (array). */
function account_email_enable($domain, $user, $mx_if_missing, $dns = true) {
	$warnings = array();

	if (!isset(email_userdomains()[$domain])) {
		shell_exec('echo '.escapeshellarg($domain.':'.$user).' | sudo tee --append /etc/exim/userdomains > /dev/null');
		shell_exec('sudo chown exim:exim /etc/exim/userdomains');
		shell_exec('sudo chmod a+r,u+w /etc/exim/userdomains');
		shell_exec('sudo touch '.escapeshellarg('/etc/exim/domains/'.$domain));
		shell_exec('sudo chown exim:exim '.escapeshellarg('/etc/exim/domains/'.$domain));
		/* the account panel's broker runs with umask 077 */
		shell_exec('sudo chmod 0644 '.escapeshellarg('/etc/exim/domains/'.$domain));
	}

	// Catch-all :fail: entry in the forwards file
	$fwd = escapeshellarg('/etc/exim/forwards/'.$domain);
	shell_exec('sudo grep -q \'^\\*:\' '.$fwd.' 2>/dev/null || echo \'*: :fail: No Such User Here\' | sudo tee --append '.$fwd.' > /dev/null');
	shell_exec('sudo chown exim:exim '.$fwd.' 2>/dev/null');
	shell_exec('sudo chmod 0644 '.$fwd.' 2>/dev/null');

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

	if (!$dns) {
		shell_exec('sudo '._PATH.'/scripts/update_email_sni >> '._PATH.'/log/debug_log 2>&1 &');
		return $warnings;
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
	if ((email_userdomains()[$domain] ?? '') === $user)
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
 *   php, shell   '' = the server defaults
 *   package      hosting package name (the Hosting Pro plugin); '' = its default
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
	if (($e = limit_allows('', 'account', array('user' => $user, 'domain' => $domain))) !== '')
		return result_error($e);
	/* a hosting package (plugin) checks the one asked for; without the plugin
	   there are no packages to ask for */
	$package = trim((string)($o['package'] ?? ''));
	$e = plugin_filter('account.create.check', $package !== '' ? 'Hosting packages need the Reqad Hosting Pro add-on.' : '', $o);
	if (is_string($e) && $e !== '')
		return result_error($e);

	if (!valid_domain($domain))
		return result_error("Domain name is wrong, please check what you typed.");
	if ($row = account_get($db, '', $domain))
		return result_error("Domain name already exists on this server, assigned to user ".$row['user'].".");
	if (($e = dns_key_domain_error($domain)) !== '')
		return result_error($e);

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

	/* PHP version (or 'off') and shell access, checked before anything is made;
	   '' = the server defaults. The template sets up the default PHP; another
	   choice is applied right after it, like Manage would. */
	$php   = trim((string)($o['php'] ?? ''));
	$shell = trim((string)($o['shell'] ?? ''));
	if ($php !== '' && $php !== 'off') {
		$pv = strpos($php, ':') !== false ? explode(':', $php, 2)[0] : $php;
		if (!in_array($pv, array_map('trim', explode(',', $ini['php_versions'])), true))
			return result_error("PHP $pv is not installed (available: ".$ini['php_versions'].").");
	}
	if ($shell !== '' && !isset(account_shell_modes()[$shell]))
		return result_error(account_shell_mode_error($shell));

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
	/* CPU/memory/process caps for its SSH, cron and terminal sessions */
	account_session_limits_set($user, 'default');

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

	/* PHP: only when it differs from what the template set up */
	if ($php !== '') {
		list($cur_v, $cur_h) = account_php($domain);
		$cur = $cur_v === 'off' ? 'off' : $cur_v;
		$want_v = $php === 'off' ? 'off' : (strpos($php, ':') !== false ? explode(':', $php, 2)[0] : $php);
		$want_h = strpos($php, ':') !== false ? explode(':', $php, 2)[1] : $cur_h;
		if ($want_v !== $cur || ($want_v !== 'off' && $want_h !== $cur_h)) {
			$r = account_edit($db, array('user' => $user, 'php' => $php));
			if (!$r['ok']) $warnings[] = 'PHP was left at the default: '.$r['error'];
			else           $warnings = array_merge($warnings, $r['warnings']);
			/* no PHP: the welcome page the template copied is index.php and would
			   answer 403 - replace it with a static copy (index.html) */
			if ($r['ok'] && $php === 'off')
				account_static_welcome($domain, $user);
		}
	}

	/* shell access: the server default for new accounts (Settings > Security) */
	if ($shell === '')
		$shell = account_shell_default();
	if ($shell !== 'full') {
		$r = account_shell_set($user, $shell);
		if (!$r['ok']) $warnings[] = $r['error'];
	}
	account_bash_profile($user);

	$message = "Account $user successfully created.";
	if ($ssl_started)
		$message .= " Please wait one minute until Let's Encrypt Certificate will be installed.";

	plugin_hook('account.created', array('user' => $user, 'domain' => $domain, 'uid' => $uid, 'package' => $package));
	return result_ok($message, array(
		'uid' => $uid, 'user' => $user, 'domain' => $domain, 'disk_quota' => $disk_quota,
		'email' => $has_email, 'www' => $create_www, 'letsencrypt_started' => $ssl_started,
		'php' => implode(':', account_php($domain)), 'shell' => account_shell_get($user),
	), $warnings);
}

/* Build the Apache php-fpm pool for an account switched off mod_php. */
function apache_fpm_pool($domain, $user) {
	return account_fpm_pool($domain, $user, 'apache');
}

/* An account's php-fpm pool. $web_group is the web server's group (nginx or
   apache), which must reach the socket; PHP itself runs in the account's own
   group, so it cannot read other docroots (those are <user>:<web> 0750). Same pool as the one
   scripts/templates/nginx_php-fpm.php writes at account creation -- keep the two
   in step (the template also runs from scripts/adduserdomain, which does not
   load app/functions/, so it cannot call this). */
function account_fpm_pool($domain, $user, $web_group) {
	return '['.$domain.']
user = '.$user.'
group = '.$user.'
listen = /run/php-fpm-'.$user.'.sock
listen.owner = '.$user.'
listen.group = '.$web_group.'
listen.allowed_clients = 127.0.0.1

; workers start with the first request and stop after 10 s idle, so a quiet
; site holds no memory; 20 at once per site, a stuck script is killed at 300 s
pm = ondemand
pm.max_children = 20
pm.process_idle_timeout = 10s
pm.max_requests = 500
request_terminate_timeout = 300s

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

/* Where Apache reads the files as apache (php-fpm, no mod_ruid2), a symbolic
   link is followed only when the link and its target have the same owner, and
   .htaccess may not turn FollowSymLinks back on: otherwise an account could
   link another account's file into its site and download it. The options
   .htaccess may still set (scripts/update/setup_symlink_protection.sh applies
   the same to existing vhosts). */
function apache_override_options() {
	return 'Options=Indexes,MultiViews,SymLinksIfOwnerMatch,IncludesNOEXEC';
}

function apache_symlinks_owner_only($conf) {
	$conf = preg_replace('/^([ \t]*Options[ \t][^\n]*?)[ \t]+\+?FollowSymLinks\b/mi', '$1', $conf);
	return preg_replace('/^([ \t]*AllowOverride[ \t]+All)[ \t]*$/mi', '$1 '.apache_override_options(), $conf);
}

/* The vhost block that hands .php to the account's php-fpm socket instead of mod_php. */
function apache_fpm_handler_block($user) {
	return "\n    <FilesMatch \\.php\$>\n        SetHandler \"proxy:unix:/run/php-fpm-".$user.".sock|fcgi://localhost\"\n    </FilesMatch>";
}

/* An account's vhost on a php-fpm-only Apache template (apache_phpfpm): the
   template account creation uses (scripts/templates/apache-ssl.conf) without its
   mod_php lines -- httpd -t rejects php_* without mod_php, and the pool sets the
   same values -- and with the SetHandler to the account's pool in the :443 vhost,
   where apache_vhost_add_fpm() puts it. $certs replaces %CERTS%, $names
   (template_server_names()) %NAMES%. */
function apache_fpm_vhost($domain, $user, $certs, $names = null) {
	$tpl  = (string)@file_get_contents(_PATH.'/scripts/templates/apache-ssl.conf');
	$tpl  = apache_symlinks_owner_only($tpl);
	$tpl  = preg_replace('/^[ \t]*php_(admin_)?(value|flag)\b[^\n]*\n/m', '', $tpl);
	/* mod_ruid2 is prefork only; Apache stays apache here */
	$tpl  = preg_replace('/^[ \t]*<IfModule mod_ruid2\.c>.*?<\/IfModule>[ \t]*\n/ms', '', $tpl);
	$conf = str_replace(array('%CERTS%', '%NAMES%', '%USER%', '%DOMAIN%'), array($certs, $names ?? template_server_names($domain), $user, $domain), $tpl);
	$pos  = strrpos($conf, '</VirtualHost>');
	if ($pos === false) return '';
	return substr($conf, 0, $pos).apache_fpm_handler_block($user)."\n".substr($conf, $pos);
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

/* ---- Apache behind nginx -----------------------------------------------------
   On a template with a backend (nginx_apache_php-fpm, Hosting Pro) nginx keeps
   :80/:443, the certificates and the static files, and passes everything else
   to Apache on loopback (web_backend_addr()), which reads .htaccess and hands
   PHP to the same per-account php-fpm pool. The PHP handler, and so the
   PHP-off block, lives in the backend vhost (web_php_server() is httpd); the
   names, markers and own addresses stay in the nginx one. nginx reaches Apache
   through /etc/nginx/reqad-backend.conf (proxy settings, included per
   location) and the upstreams in /etc/nginx/conf.d/00-reqad-backend.conf. */

/* Shared certificate of every https backend vhost. nginx does not check it
   (loopback), and one certificate with the same SSL settings everywhere lets
   nginx reuse a kept-alive connection for any site. */
function backend_cert_files() {
	return array('/etc/httpd/reqad-backend/backend.crt', '/etc/httpd/reqad-backend/backend.key');
}

/* A site's backend vhost: one <VirtualHost> on the https backend address (the
   http one for a site without certificate). ServerName carries the visitor's
   port, so PHP and rewrite rules see 443 / 80 and not the loopback port.
   $socket is the site's php-fpm socket; null leaves PHP off (the PHP-off
   block instead of the handler). $header is a first comment line (the addon
   domain marker). */
function backend_vhost($domain, $docroot, $names, $socket, $https = true, $header = '') {
	list($crt, $key) = backend_cert_files();
	$names = trim($names) !== '' ? trim($names) : $domain;
	$conf = ($header !== '' ? $header."\n" : '')
		.'# Apache behind nginx: nginx serves the static files and passes the rest here.
<VirtualHost '.web_backend_addr($https).'>
    ServerName     '.$domain.':'.($https ? '443' : '80').'
    ServerAlias    '.$names.'
    DocumentRoot   '.$docroot.'
    UseCanonicalName Off
    <Directory "'.$docroot.'">
        AllowOverride All '.apache_override_options().'
        Options MultiViews SymLinksIfOwnerMatch IncludesNoExec
        Require all granted
    </Directory>
    Options -ExecCGI -Includes
'.($https ? '    SSLEngine on
    SSLCertificateFile '.$crt.'
    SSLCertificateKeyFile '.$key.'
' : '');
	if ($socket !== null)
		$conf .= '
    <FilesMatch \.php$>
        SetHandler "proxy:unix:'.$socket.'|fcgi://localhost"
    </FilesMatch>
';
	else
		$conf .= conf_indent(apache_php_off_block(), '    ')."\n";
	return $conf.'</VirtualHost>
';
}

/* nginx's `location /` with Apache behind it: static files straight from
   disk - one that does not exist goes to Apache too, so rewrites that build
   images or sitemaps keep working - and everything else (PHP, folders, URLs
   only a .htaccess rule knows) to Apache. Same denies as nginx_php-fpm; a
   .htaccess rule cannot protect a static file nginx serves itself. The WP
   Toolkit's rules go after the first include line (wp_sec_nginx_render()).
   The server needs nginx_backend_named() next to it. */
function nginx_backend_root() {
	return 'location / {
    # everything that is not a static file: Apache behind nginx (.htaccess, PHP)
    include /etc/nginx/reqad-backend.conf;

    # Deny all attempts to access hidden files such as .htaccess, .htpasswd, .DS_Store
    location ~ /\. {
        deny all;
    }

    # Deny access to any files with a .php extension in the uploads directory
    # Must stay ABOVE the PHP location (nested regex locations match in file order).
    location ~* /(?:uploads|files)/.*\.php$ {
        deny all;
    }

    location ~ \.php$ {
        include /etc/nginx/reqad-backend.conf;
    }

    location ~* \.(js|css|png|jpg|jpeg|gif|ico|woff2|woff|webp|avif|svg)$ {
        try_files $uri @reqad_backend;
        expires max;
        log_not_found off;
    }
    location ~* \.(map|bmp|tif|tiff|ttf|otf|eot|mp4|webm|ogg|ogv|mp3|m4a|wav|flac|pdf|txt|xml|csv|zip|gz|tgz|rar|7z|doc|docx|xls|xlsx|ppt|pptx|odt|ods)$ {
        try_files $uri @reqad_backend;
    }
    location = /favicon.ico {
        try_files $uri @reqad_backend;
        log_not_found off;
        access_log off;
    }
    location = /robots.txt {
        try_files $uri @reqad_backend;
        allow all;
        log_not_found off;
        access_log off;
    }
}';
}

/* Where a static file that is not on disk goes (server level, beside `location /`). */
function nginx_backend_named() {
	return 'location @reqad_backend {
    include /etc/nginx/reqad-backend.conf;
}';
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

/* The account's vhost on $server ('nginx' | 'httpd'), the front server by default. */
function account_vhost_path($domain, $server = null) {
	return web_vhost_path($domain, $server);
}

/* Vhost content, '' when missing. conf.d files are normally world-readable, so
   sudo is only the fallback (the accounts list calls this once per account). */
function account_vhost_read($domain, $server = null) {
	$file = account_vhost_path($domain, $server);
	if (is_readable($file))
		return (string)file_get_contents($file);
	return (string)shell_exec('sudo cat '.escapeshellarg($file).' 2>/dev/null');
}

function account_php_disabled($domain) {
	return strpos(account_vhost_read($domain, web_php_server()), '# BEGIN reqad-php-disabled') !== false;
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
    fastcgi_param   SCRIPT_FILENAME     $realpath_root$fastcgi_script_name;
    fastcgi_param   DOCUMENT_ROOT       $realpath_root;
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
   directives go back into the first handler, as wp_nginx_cache_enable() puts them,
   and all the cache blocks are written again (an older http block lacks the
   maps the current directives use). */
function nginx_vhost_php_on($conf, $user, $domain = '') {
	$fcgi = '';
	if (preg_match('/^# BEGIN reqad-nginx-cache[ \t]*$/m', $conf) && strpos($conf, '# BEGIN reqad-nginx-cache-fcgi') === false) {
		/* the zone the vhost's cache block names (an account's other sites have their own) */
		$frag = wp_nginx_cache_fragments(preg_match('/keys_zone=([a-z0-9_]+):/', $conf, $zm) ? $zm[1] : wp_cache_zone($user), '');
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
	$conf = nginx_php_on_lines($conf);
	if ($domain !== '' && ($c = wp_nginx_cache_rewrite($conf, $domain)) !== null)
		$conf = $c;
	return $conf;
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
	/* with the settings block of PHP settings (app/functions/php.php): PHP that comes back starts fresh */
	$conf = preg_replace('/^[ \t]*# BEGIN reqad-php-settings[^\n]*\n.*?^[ \t]*# END reqad-php-settings[^\n]*\n/ms', '', $conf);
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
	/* mod_php settings only where mod_php exists; on php-fpm-only templates the pool has them */
	$add  = php_mode() === 'modphp' ? "\n".apache_php_directives($domain, $user)."\n" : "\n";
	if ($handler == 'fpm')
		$add .= ltrim(apache_fpm_handler_block($user), "\n")."\n";
	return substr($conf, 0, $ls).$add.substr($conf, $ls);
}

/* Write a vhost, test the web server config and reload it. A failed test puts
   $orig back. Returns '' or the error. */
function account_vhost_apply($file, $new, $orig, $server) {
	$ws  = web_server($server);
	$tmp = tempnam('/tmp', 'reqad');
	file_put_contents($tmp, $new);
	shell_exec('sudo cp '.escapeshellarg($tmp).' '.escapeshellarg($file));
	$test = (string)shell_exec('sudo '.$ws['test'].' 2>&1');
	if (stripos($test, 'test is successful') === false && stripos($test, 'syntax ok') === false) {
		file_put_contents($tmp, $orig);
		shell_exec('sudo cp '.escapeshellarg($tmp).' '.escapeshellarg($file));
		unlink($tmp);
		return 'The web server configuration test failed, nothing was changed: '.trim(preg_replace('/\s+/', ' ', $test));
	}
	unlink($tmp);
	shell_exec('sudo systemctl reload '.$ws['service'].' >> '._PATH.'/log/debug_log 2>&1');
	return '';
}

/* The front server's vhost files of an account: its main domain and its addon
   domains (aliases and mail.<domain> live in their domain's file). */
function account_vhost_files($user) {
	global $db, $ini;
	$files = array();
	$acc = account_get($db, $user);
	if ($acc && is_file(account_vhost_path($acc['domain'])))
		$files[] = account_vhost_path($acc['domain']);
	foreach (addon_domain_list($ini) as $d => $a)
		if ($a['user'] === $user && is_file($a['vhost']))
			$files[] = $a['vhost'];
	return $files;
}

/* $conf without the named marker block, and with it (the lines in $lines) at
   the top of every nginx `server` / Apache <VirtualHost>, when $lines is not
   null. A block at the top of the server is read before the site's own rules:
   a `return` there wins over every location. Null when the file has nothing to
   put it in (no server block, or unbalanced braces). */
function vhost_marker_edit($conf, $name, $lines, $server) {
	$conf = preg_replace('/\n[ \t]*# BEGIN reqad-'.preg_quote($name, '/').'\n.*?\n[ \t]*# END reqad-'.preg_quote($name, '/').'(?=\n|$)/s', '', $conf);
	if ($lines === null)
		return $conf;
	$block = function ($indent) use ($name, $lines) {
		return "\n".conf_indent("# BEGIN reqad-$name\n".rtrim($lines)."\n# END reqad-$name", $indent);
	};
	if ($server === 'httpd') {
		if (!preg_match('/^[ \t]*<VirtualHost\b[^\n]*>/mi', $conf))
			return null;
		return preg_replace_callback('/^([ \t]*)<VirtualHost\b[^\n]*>/mi', function ($m) use ($block) {
			return $m[0].$block($m[1].'    ');
		}, $conf);
	}
	$blocks = nginx_conf_blocks($conf);
	if (!$blocks)
		return null;
	$servers = array_filter($blocks, function ($b) { return $b[3] === 'server'; });
	if (!$servers)
		return null;
	usort($servers, function ($a, $b) { return $b[1] - $a[1]; });   // back to front keeps the offsets valid
	foreach ($servers as $b) {
		$ls = strrpos(substr($conf, 0, $b[0]), "\n");
		$ls = $ls === false ? 0 : $ls + 1;
		$lead = substr($conf, $ls, $b[0] - $ls);
		$conf = substr($conf, 0, $b[1] + 1).$block((trim($lead) === '' ? $lead : '').'    ').substr($conf, $b[1] + 1);
	}
	return $conf;
}

/*
 * Put a named marker block ("# BEGIN reqad-<name>" ... "# END reqad-<name>") at
 * the top of every server of every front vhost of the account, or take it out
 * ($nginx / $apache null). The lines for the front server in use are taken.
 * All files are written, the config tested once, and on a failed test every
 * file is put back. Add-ons use it for state that must reach every site of an
 * account (Hosting Pro: suspended, slowed down). A vhost written later (a new
 * addon domain) does not have it: callers re-apply. Returns '' or the error.
 */
function account_vhost_marker($user, $name, $nginx, $apache) {
	if (!preg_match('/^[a-z][a-z0-9-]{1,30}$/', $name))
		return "Bad marker name $name.";
	$server = web_front();
	$ws = web_server($server);
	$lines = $server === 'httpd' ? $apache : $nginx;
	$changed = array();
	foreach (account_vhost_files($user) as $file) {
		$orig = account_vhost_file_read($file);
		$new = vhost_marker_edit($orig, $name, $lines, $server);
		if ($new === null) {
			if ($lines === null) continue;
			return "Could not read the server blocks of $file.";
		}
		if ($new !== $orig)
			$changed[$file] = array($orig, $new);
	}
	return account_vhost_files_apply($changed);
}

function account_vhost_file_read($file) {
	$c = (string)@file_get_contents($file);
	return $c !== '' ? $c : (string)shell_exec('sudo cat '.escapeshellarg($file).' 2>/dev/null');
}

/* Write file => array(old, new) pairs, test the front server's config once,
   reload it; on a failed test every file is put back. '' or the error. */
function account_vhost_files_apply(array $changed) {
	if (!$changed)
		return '';
	$ws = web_server(web_front());
	$put = function ($file, $text) {
		$tmp = tempnam('/tmp', 'reqad');
		file_put_contents($tmp, $text);
		shell_exec('sudo cp '.escapeshellarg($tmp).' '.escapeshellarg($file));
		unlink($tmp);
	};
	foreach ($changed as $file => $c)
		$put($file, $c[1]);
	$test = (string)shell_exec('sudo '.$ws['test'].' 2>&1');
	if (stripos($test, 'test is successful') === false && stripos($test, 'syntax ok') === false) {
		foreach ($changed as $file => $c)
			$put($file, $c[0]);
		return 'The web server configuration test failed, nothing was changed: '.trim(preg_replace('/\s+/', ' ', $test));
	}
	shell_exec('sudo systemctl reload '.$ws['service'].' >> '._PATH.'/log/debug_log 2>&1');
	return '';
}

/*
 * $conf with the account's own addresses added to its listen lines, or with
 * them taken out ($ip4 and $ip6 null). The lines already there stay, so the
 * sites keep answering on the shared address while DNS moves over (and Let's
 * Encrypt can reach them); the added lines carry "# reqad-ip" and are the only
 * thing removed again.
 *   nginx   after each "listen [*:|0.0.0.0:]<port> ...;" a "listen <ip4>:<port>",
 *           after each "listen [::]:<port>" a "listen [<ip6>]:<port>" - and
 *           when the file has no IPv6 listen at all, one after each IPv4 one.
 *           Socket options that may be set only once per address (backlog,
 *           reuseport, default_server, ipv6only...) are left off the copies.
 *   Apache  the addresses join the <VirtualHost *:<port>> line; the line as it
 *           was is kept in a "# reqad-ip was:" comment above it.
 */
function vhost_listen_edit($conf, $ip4, $ip6, $server) {
	if ($server === 'httpd') {
		$conf = preg_replace_callback('/^[ \t]*# reqad-ip was: (.*)\n[^\n]*\n/m', function ($m) { return $m[1]."\n"; }, $conf);
		if ($ip4 === null && $ip6 === null)
			return $conf;
		return preg_replace_callback('/^([ \t]*)<VirtualHost\s+([^>]+)>[ \t]*$/mi', function ($m) use ($ip4, $ip6) {
			$add = array();
			foreach (preg_split('/\s+/', trim($m[2])) as $a)
				if (preg_match('/^(?:\*|_default_):(\d+)$/', $a, $p)) {
					if ($ip4 !== null) $add[] = "$ip4:{$p[1]}";
					if ($ip6 !== null) $add[] = "[$ip6]:{$p[1]}";
				}
			if (!$add)
				return $m[0];
			return $m[1].'# reqad-ip was: '.trim($m[0])."\n".$m[1].'<VirtualHost '.trim($m[2]).' '.implode(' ', array_unique($add)).'>';
		}, $conf);
	}
	$conf = preg_replace('/^[ \t]*listen[^\n]*# reqad-ip\b[^\n]*\n/m', '', $conf);
	if ($ip4 === null && $ip6 === null)
		return $conf;
	$has6 = (bool)preg_match('/^[ \t]*listen\s+\[/m', $conf);
	$once = '/\s+(?:default_server|default|reuseport|ipv6only=\S+|backlog=\S+|deferred|bind|fastopen=\S+|so_keepalive=\S+|rcvbuf=\S+|sndbuf=\S+|setfib=\S+|accept_filter=\S+)\b/';
	return preg_replace_callback('/^([ \t]*)listen\s+([^\s;]+)([^;\n]*);([^\n]*)$/m', function ($m) use ($ip4, $ip6, $has6, $once) {
		$addr = $m[2]; $opts = preg_replace($once, '', $m[3]);
		$v6 = preg_match('/^\[::\]:(\d+)$/', $addr, $p6);
		$v4 = preg_match('/^(?:\*:|0\.0\.0\.0:)?(\d+)$/', $addr, $p4);
		$out = $m[0];
		if ($v4 && $ip4 !== null)
			$out .= "\n{$m[1]}listen $ip4:{$p4[1]}$opts; # reqad-ip";
		if ($v4 && $ip6 !== null && !$has6)
			$out .= "\n{$m[1]}listen [$ip6]:{$p4[1]}$opts; # reqad-ip";
		if ($v6 && $ip6 !== null)
			$out .= "\n{$m[1]}listen [$ip6]:{$p6[1]}$opts; # reqad-ip";
		return $out;
	}, $conf);
}

/* Bind every front vhost of the account to its own addresses as well ($ip4 /
   $ip6, null = none of that kind), or take them away (both null). Add-ons
   (Hosting Pro dedicated IPs) call it again for vhosts written later. */
function account_vhost_listen($user, $ip4, $ip6) {
	$server = web_front();
	$changed = array();
	foreach (account_vhost_files($user) as $file) {
		$orig = account_vhost_file_read($file);
		$new = vhost_listen_edit($orig, $ip4, $ip6, $server);
		if ($new !== $orig)
			$changed[$file] = array($orig, $new);
	}
	return account_vhost_files_apply($changed);
}

/* The account's front vhost files that lack the named marker block. */
function account_vhost_marker_missing($user, $name) {
	$missing = array();
	foreach (account_vhost_files($user) as $file) {
		$c = (string)@file_get_contents($file);
		if ($c === '')
			$c = (string)shell_exec('sudo cat '.escapeshellarg($file).' 2>/dev/null');
		if (strpos($c, "# BEGIN reqad-$name\n") === false)
			$missing[] = $file;
	}
	return $missing;
}

/* Turn PHP off for an account's main domain. Returns '' or why nothing changed. */
function account_php_disable($domain, $user) {
	global $ini;
	$server = web_php_server();
	$file = account_vhost_path($domain, $server);
	$orig = account_vhost_read($domain, $server);
	if (trim($orig) === '')
		return "Vhost $file not found.";
	$new = $server === 'httpd' ? apache_vhost_php_off($orig, $user)
	                           : nginx_vhost_php_off($orig, $user, function($name) use ($file) { return nginx_upstream_used_elsewhere($name, $file); });
	if ($new === null)
		return $server === 'httpd' ? "No <VirtualHost> found in $file." : "Could not read the block structure of $file (unbalanced braces, or no server block).";
	if (($err = account_vhost_apply($file, $new, $orig, $server)) !== '')
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
	$server = web_php_server();
	$file = account_vhost_path($domain, $server);
	$orig = account_vhost_read($domain, $server);
	if (trim($orig) === '')
		return "Vhost $file not found.";
	$new = $server === 'httpd' ? apache_vhost_php_on($orig, $domain, $user, $handler) : nginx_vhost_php_on($orig, $user, $domain);
	if ($new === null || $new === $orig)
		return "No PHP-disabled block found in $file.";

	// Pool first, so the socket the new vhost points at comes up with the restart
	$pool = null;
	if ($handler == 'fpm') {
		list($pool, $service) = account_fpm_pool_target($domain, $version);
		$tmp = tempnam('/tmp', 'reqad');
		file_put_contents($tmp, account_fpm_pool($domain, $user, web_group()));
		shell_exec('sudo cp '.escapeshellarg($tmp).' '.escapeshellarg($pool));
		unlink($tmp);
		if (!is_file($pool))
			return "Could not write the php-fpm pool $pool.";
	}
	if (($err = account_vhost_apply($file, $new, $orig, $server)) !== '') {
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
   latter mod_php templates + default version only), then restart what changed. */
function account_switch_php($domain, $user, $old_version, $old_handler, $new_version, $new_handler) {
	global $ini;
	$restart_services = '';

	if (php_mode() === 'modphp') {
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

		// mod_php runs PHP as apache, so then tmp/ (sessions, uploads) and
		// logs/ must be writable by apache; php-fpm runs it as the account
		if ($old_handler != $new_handler)
			shell_exec('sudo '._PATH.'/scripts/update/harden_account_perms.sh --apply --quiet --user='.escapeshellarg($user).' >/dev/null 2>&1');
	} else {
		// php-fpm only: move the pool file between version directories
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
	$want_shell = isset($o['shell']) ? trim((string)$o['shell']) : '';
	if ($want_shell !== '' && !isset(account_shell_modes()[$want_shell]))
		return result_error(account_shell_mode_error($want_shell));
	/* a hosting package (plugin) checks the one asked for, before anything changes */
	$package = trim((string)($o['package'] ?? ''));
	$e = plugin_filter('account.edit.check', $package !== '' ? 'Hosting packages need the Reqad Hosting Pro add-on.' : '', $o);
	if (is_string($e) && $e !== '')
		return result_error($e);

	$modphp = (php_mode() === 'modphp');
	list($old_version, $old_handler) = account_php($domain);
	$new_version = $old_version;
	$new_handler = $old_handler;
	if (isset($o['php']) && trim($o['php']) === 'off') {
		$new_version = $new_handler = 'off';
	} elseif (isset($o['php']) && trim($o['php']) !== '') {
		$php = trim($o['php']);
		if (strpos($php, ':') !== false)
			list($new_version, $new_handler) = explode(':', $php, 2);
		elseif ($modphp && $old_handler == 'off') {
			// PHP coming back on mod_php with no handler given: what account creation picks
			$new_version = $php;
			$new_handler = ($php == $ini['php']) ? 'mod_php' : 'fpm';
		} else {
			$new_version = $php;
			$new_handler = $modphp ? $old_handler : 'fpm';
		}
		if (!in_array($new_version, array_map('trim', explode(',', $ini['php_versions'])), true))
			return result_error("PHP $new_version is not installed (available: ".$ini['php_versions'].").");
		if (!in_array($new_handler, $modphp ? array('fpm', 'mod_php') : array('fpm'), true))
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
		$changes[] = 'php '.$new_version.($modphp ? ' ('.$new_handler.')' : '');
	} elseif ($new_version != $old_version || $new_handler != $old_handler) {
		account_switch_php($domain, $user, $old_version, $old_handler, $new_version, $new_handler);
		$changes[] = 'php '.$new_version.($modphp ? ' ('.$new_handler.')' : '');
	}

	if ($password !== '') {
		if (!set_system_password($user, $password))
			return result_error("Failed to set the account password.");
		$changes[] = 'password';
		/* the account panel signs in with it too: whoever knew the old one is logged out */
		if (function_exists('auth_ready') && auth_ready() && ($ap = auth_principal($user, 'account')))
			auth_sessions_revoke_all((int)$ap['id']);
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
			$warnings[] = apply_account_vhost_names($db, $domain);
			reissue_letsencrypt_cert($db, $domain, $want_email);
			/* the addon sites follow the account (their exim files and
			   mailboxes are kept, as the main domain's): off, every site
			   that had mail; on, the sites that should have it
			   (domain_mail_wanted()) - a site that never had mail gets it
			   set up whole, DNS included */
			$names = domain_account_names($user);
			foreach (addon_domain_list($ini) as $ad => $addon) {
				if ($addon['user'] !== $user)
					continue;
				if (!$want_email) {
					if (!email_domain_exists($ad))
						continue;
					$warnings = array_merge($warnings, account_email_disable($ad, $user));
					$warnings[] = domain_vhost_mail_name($ad, false);
				} elseif (domain_mail_wanted($ad, domain_parent_of($user, $ad, $names))) {
					if (email_domain_exists($ad)) {
						$warnings = array_merge($warnings, account_email_enable($ad, $user, true, false));
						$warnings[] = domain_vhost_mail_name($ad, true);
					} else {
						$r = domain_mail_enable($user, $ad);
						$warnings = array_merge($warnings, $r['ok'] ? $r['warnings'] : array($r['error']));
					}
				}
			}
			$changes[] = 'email '.($want_email ? 'on' : 'off');
		}
	}

	if ($want_shell !== '' && $want_shell !== account_shell_get($user)) {
		$r = account_shell_set($user, $want_shell);
		if ($r['ok']) $changes[] = 'shell '.$want_shell;
		else          $warnings[] = $r['error'];
	}

	/* plugins apply theirs last (hosting package: its shell wins over the one above) */
	$more = plugin_filter('account.edit.apply', array('changes' => array(), 'warnings' => array()), $user, $o);
	if (is_array($more)) {
		$changes  = array_merge($changes, (array)($more['changes'] ?? array()));
		$warnings = array_merge($warnings, (array)($more['warnings'] ?? array()));
	}

	if ($changes)
		plugin_hook('account.edited', array('user' => $user, 'domain' => $domain, 'changed' => $changes));
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
	$servers   = array_unique(array_diff(array(web_front(), web_backend()), array('none')));
	$qd        = escapeshellarg($domain);
	$warnings  = array();

	route_log("delete account $user");
	/* its apps: stop the processes and remove their services */
	if (function_exists('app_list'))
		foreach (app_list($user) as $app) {
			$r = app_delete($app['id']);
			if (!$r['ok']) $warnings[] = $r['error'];
		}
	/* its addon domains first: their vhosts and php-fpm pools name the user,
	   and a pool whose user is gone stops php-fpm from starting */
	foreach (addon_domain_list($ini) as $addon)
		if ($addon['user'] === $user) {
			/* its mail (mailboxes included: the account and its files go) */
			$mo = email_userdomains()[$addon['domain']] ?? '';
			if ($mo === $user || ($mo === '' && email_domain_exists($addon['domain'])))
				domain_mail_remove($user, $addon['domain'], true);
			exec('sudo '._PATH.'/scripts/deldomain --domain='.escapeshellarg($addon['domain']).' 2>&1', $out, $rc);
			if ($rc !== 0)
				$warnings[] = 'Addon domain '.$addon['domain'].' could not be removed: '.trim(implode(' ', array_slice($out, -2)));
			else
				wp_cache_rules_forget($addon['domain']);
			$out = array();
		}
	domain_mail_forget('', $user);
	wp_cache_rules_forget($domain);
	if (!$db->exec('DELETE FROM accounts WHERE user="'.$db->escapeString($user).'"'))
		$warnings[] = 'The account could not be removed from the panel database ('.$db->lastErrorMsg().'); run the delete again.';
	$db->exec('DELETE FROM wordpress WHERE user="'.$db->escapeString($user).'"');
	$db->exec('DELETE FROM emails WHERE email LIKE "%@'.$db->escapeString($domain).'"');
	/* aliases.alias is UNIQUE: a leftover row made re-creating the same domain
	   with its www alias silently skip the alias insert. */
	$db->exec('DELETE FROM aliases WHERE account_id='.(int)$row['id']);
	$db->exec("DELETE FROM settings WHERE name = 'db-conn-limit-".$db->escapeString($user)."'");

	$output = '';
	foreach ($servers as $server)
		$output .= shell_exec('sudo rm -f '.escapeshellarg(account_vhost_path($domain, $server)).' 2>&1');

	shell_exec('sudo sed -i '.escapeshellarg('/^'.addcslashes($domain, '.').':'.$user.'$/d').' /etc/exim/userdomains');
	shell_exec('sudo rm -f '.escapeshellarg('/etc/exim/domains/'.$domain));
	shell_exec('sudo rm -f '.escapeshellarg('/etc/exim/forwards/'.$domain));
	shell_exec('sudo rm -f '.escapeshellarg('/etc/exim/keys/'.$domain.'.private.key'));
	shell_exec('sudo rm -f '.escapeshellarg('/etc/exim/keys/'.$domain.'.public.key'));
	shell_exec('sudo sed -i '.escapeshellarg('/@'.addcslashes($domain, '.').':/d').' /etc/dovecot/users');
	shell_exec('sudo rm -rf '.escapeshellarg('/etc/exim/autoreply/'.$domain));
	mail_remote_set($domain, false);

	$restart_services = in_array('httpd', $servers, true) ? 'httpd.service ' : '';
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
	/* dovecot/exim SNI still names the certificate removed above: dovecot
	   refuses to start (next restart or reboot) until it is regenerated */
	shell_exec('sudo '._PATH.'/scripts/update_email_sni >> '._PATH.'/log/debug_log 2>&1 &');
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
	/* its Git deployments (the files went with the home directory) */
	if (function_exists('deploy_list'))
		foreach (deploy_list($user) as $dep)
			deploy_forget((int)$dep['id']);

	if ($released_dbs)
		$warnings[] = 'Database'.(count($released_dbs) > 1 ? 's' : '').' '.implode(', ', $released_dbs)
			.' assigned to '.$user.' '.(count($released_dbs) > 1 ? 'were' : 'was').' kept and '
			.(count($released_dbs) > 1 ? 'are' : 'is').' now unassigned.';

	plugin_hook('account.deleted', array('user' => $user, 'domain' => $domain));
	return result_ok("Account $user successfully removed.", array(
		'user' => $user, 'domain' => $domain, 'databases_dropped' => $dropped_dbs,
		'databases_released' => $released_dbs,
	), $warnings);
}

/* ---- session limits ------------------------------------------------------
   CPU, memory and process caps for an account's SSH, cron and web-terminal
   sessions (systemd slice user-<uid>.slice). scripts/session_limits.sh owns
   the drop-in and the hit log; these are the web/CLI front ends. */

/* Current limits, usage and recent hits of an account (null = not a hosting
   account). mode: default | custom | none | missing. */
function account_session_limits($user, $events = 10) {
	if (!valid_username($user))
		return null;
	$out = (string)shell_exec('sudo '._PATH.'/scripts/session_limits.sh show '.escapeshellarg($user).' 2>/dev/null');
	$r = array();
	foreach (explode("\n", trim($out)) as $line)
		if (($p = strpos($line, '=')) !== false)
			$r[substr($line, 0, $p)] = substr($line, $p + 1);
	if (!isset($r['mode']))
		return null;
	foreach (array('cpu', 'memory_mb', 'tasks') as $k)
		$r[$k] = ($r[$k] ?? '-') === '-' ? null : (int)$r[$k];
	$r['active'] = !empty($r['active']);
	$r['events'] = array();
	if ($events > 0) {
		$ev = (string)shell_exec('sudo '._PATH.'/scripts/session_limits.sh events '.escapeshellarg($user).' '.(int)$events.' 2>/dev/null');
		foreach (array_reverse(array_filter(explode("\n", trim($ev)), 'strlen')) as $line) {
			$f = explode("\t", $line);
			if (count($f) >= 4)
				$r['events'][] = array('time' => $f[0], 'limit' => $f[2], 'detail' => $f[3]);
		}
	}
	return $r;
}

/* mode: default | none | custom (with cpu %, memory MB, processes). */
function account_session_limits_set($user, $mode, $cpu = 0, $memory_mb = 0, $tasks = 0) {
	if (!valid_username($user) || in_array($user, account_reserved_names(), true))
		return result_error("Not a hosting account: $user");
	if ($mode === 'default' || $mode === 'none')
		$args = $mode;
	else if ($mode === 'custom')
		$args = (int)$cpu.' '.(int)$memory_mb.' '.(int)$tasks;
	else
		return result_error("Unknown limits mode '$mode'.");
	exec('sudo '._PATH.'/scripts/session_limits.sh set '.escapeshellarg($user).' '.$args.' 2>&1', $out, $rc);
	$msg = trim(implode(' ', $out));
	if ($rc !== 0)
		return result_error($msg !== '' ? $msg : "Could not set the session limits of $user.");
	route_log("session limits $user: $args");
	return result_ok($msg, array('user' => $user, 'mode' => $mode));
}

/* ---- shell access --------------------------------------------------------
   full (bash) | jailed (bash in a sandbox: own home only) | none (SFTP only).
   The login shell is the state; scripts/account_shell.sh sets it and keeps the
   crontab's SHELL= line in step (cron does not use the login shell).
   root_access=0 in server-software.ini: no full shell at all - only jailed or
   none can be chosen, account_shell.sh refuses full too, and its `restrict`
   (post-install) switches accounts that still have one to jailed. */

function account_shell_full_allowed() {
	global $ini;
	return feature_enabled($ini, 'root_access');
}

function account_shell_modes() {
	$m = array('full' => 'Full', 'jailed' => 'Jailed', 'none' => 'None (SFTP only)');
	if (!account_shell_full_allowed())
		unset($m['full']);
	return $m;
}

/* The error for a shell access that is not one of account_shell_modes(). */
function account_shell_mode_error($mode = '') {
	if ($mode === 'full' && !account_shell_full_allowed())
		return 'Full shell access is off on this server (root_access=0): choose jailed or none.';
	return 'Shell access must be '.(account_shell_full_allowed() ? 'full, jailed or none' : 'jailed or none').'.';
}

/* full | jailed | none | other (a shell Reqad did not set, e.g. /sbin/nologin) */
function account_shell_get($user) {
	if (!valid_username($user))
		return 'other';
	$sh = (string)(posix_getpwnam($user)['shell'] ?? '');
	if ($sh === '/bin/bash' || $sh === '/usr/bin/bash') return 'full';
	if ($sh === '/usr/local/bin/reqad-jailshell')       return 'jailed';
	if ($sh === '/usr/local/bin/reqad-sftponly')        return 'none';
	return 'other';
}

function account_shell_set($user, $mode) {
	if (!valid_username($user) || in_array($user, account_reserved_names(), true))
		return result_error("Not a hosting account: $user");
	if (!isset(account_shell_modes()[$mode]))
		return result_error(account_shell_mode_error($mode));
	exec('sudo '._PATH.'/scripts/account_shell.sh set '.escapeshellarg($user).' '.escapeshellarg($mode).' 2>&1', $out, $rc);
	$msg = trim(implode(' ', $out));
	if ($rc !== 0)
		return result_error($msg !== '' ? $msg : "Could not change the shell access of $user.");
	route_log("shell access $user: $mode");
	return result_ok($msg, array('user' => $user, 'shell' => $mode));
}

/* Shell access for new accounts (Settings > Security), default full
   (jailed when full shell access is off on this server). */
function account_shell_default() {
	$v = (string)setting_get('account-default-shell');
	return isset(account_shell_modes()[$v]) ? $v : (account_shell_full_allowed() ? 'full' : 'jailed');
}

/* A new account's ~/.bash_profile gets the Reqad prompt and vi = vim, for
   SSH and the terminal (full or jailed shell). Written as the account; left
   alone when it already sets a prompt. The account may change or remove it. */
function account_bash_profile($user) {
	$r = files_read($user, '.bash_profile');
	$content = $r['ok'] ? (string)$r['data']['content'] : '';
	if (preg_match('/^\s*(export\s+)?PS1=/m', $content))
		return;
	if ($content !== '' && substr($content, -1) !== "\n")
		$content .= "\n";
	$content .= "\n# Reqad: coloured prompt, vi opens vim (change or remove as you like)\n"
	          ."PS1='\\[\\033[01;34m\\]\\u\\[\\033[01;90m\\].\\[\\033[01;32m\\]\\h \\[\\033[01;96m\\]\\w \\[\\033[01;90m\\]\\$ \\[\\033[00m\\]'\n"
	          ."command -v vim >/dev/null 2>&1 && alias vi='vim'\n";
	$w = files_save($user, '.bash_profile', $content, $r['ok'] ? (int)$r['data']['mtime'] : null, !$r['ok']);
	if (!$w['ok'])
		route_log("bash_profile $user: ".$w['error']);
}

/* After the panel edits a crontab: jailed/none accounts keep SHELL= first. */
function account_shell_cron_sync($user) {
	if (valid_username($user) && account_shell_get($user) !== 'full')
		shell_exec('sudo '._PATH.'/scripts/account_shell.sh cron-sync '.escapeshellarg($user).' >/dev/null 2>&1');
}

/* The default welcome page without PHP, for an account created with no PHP:
   the template's index.php is replaced by index.html, unless the docroot
   already has an index.html or index.php was changed. */
function account_static_welcome($domain, $user) {
	$tpl = (string)@file_get_contents(_PATH.'/scripts/templates/default-template.html');
	$docroot = '/home/'.$user.'/public_html';
	if ($tpl === '' || !valid_username($user))
		return;
	$h = function ($v) { return htmlspecialchars($v, ENT_QUOTES); };
	$html = preg_replace('/^<\?php header\([^)]*\); \?>/', '', $tpl);
	$html = str_replace("<?php echo \$_SERVER['SERVER_NAME'];?>", $h($domain), $html);
	$html = str_replace("<?php \$user = explode('/', \$_SERVER['DOCUMENT_ROOT']); echo \$user[2]; ?>", $h($user), $html);
	$html = str_replace("<?php echo \$_SERVER['DOCUMENT_ROOT']; ?>", $h($docroot), $html);
	$html = preg_replace('#<p>Running on <\?php.*?\?></p>#s', '<p>Static site, no PHP.</p>', $html);
	if (strpos($html, '<?') !== false)
		return;   // the template changed - leave index.php alone
	$q = escapeshellarg($user);
	/* compared here: the account cannot read the panel tree */
	if (trim((string)shell_exec('sudo test -e '.escapeshellarg($docroot.'/index.html').' && echo y')) === 'y'
	    || (string)shell_exec('sudo cat '.escapeshellarg($docroot.'/index.php').' 2>/dev/null') !== $tpl)
		return;
	$p = proc_open('sudo -u '.$q.' tee '.escapeshellarg($docroot.'/index.html').' > /dev/null', array(0 => array('pipe', 'r')), $pipes);
	if (is_resource($p)) {
		fwrite($pipes[0], $html);
		fclose($pipes[0]);
		if (proc_close($p) === 0)
			shell_exec('sudo -u '.$q.' rm -f '.escapeshellarg($docroot.'/index.php'));
	}
}

