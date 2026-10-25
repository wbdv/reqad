<?php
/* New account on the apache_phpfpm template: Apache (event MPM) alone on :80/:443,
   every site's PHP in its own php-fpm pool running as the account
   (scripts/templates/apache_phpfpm.ini). No mod_php and no mod_ruid2: Apache runs
   as apache and reaches the docroot through its group (<user>:apache 0750).

   Runs inside account_create() (app/functions/accounts.php), which defines
   REQAD_TEMPLATE_RETURN and reads $errmsg; scripts/adduserdomain includes it too. */

error_log("Start ".__FILE__."\n",  3, __DIR__.'/../../log/debug_log');

if($domain=='' || !isset($domain) || $user=='' || !isset($user)) {
    echo "Undefined user/domain.\n";
	exit;
}

/* the vhost and pool builders; adduserdomain loads only modules/functions.php */
if(!function_exists('apache_fpm_vhost'))
	require_once __DIR__.'/../../app/functions/accounts.php';

$errmsg = isset($errmsg) ? $errmsg : '';
$file   = '/etc/httpd/conf.d/'.$domain.'.conf';
$pool   = '/etc/php-fpm.d/'.$domain.'.conf';

if(is_file($file) || is_file($pool)) {
	$errmsg = 'Error: file '.(is_file($file) ? $file : $pool).' already exists.'."\n";
	error_log($errmsg, 3, __DIR__.'/../../log/debug_log');
} else {
	if(is_file('/etc/letsencrypt/live/'.$domain.'/cert.pem')) {
		$certs = '
    SSLCertificateFile /etc/letsencrypt/live/'.$domain.'/cert.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/'.$domain.'/privkey.pem
    SSLCertificateChainFile /etc/letsencrypt/live/'.$domain.'/chain.pem';
	} else {
		if(!is_file('/etc/ssl/certs/'.$domain.'.crt') || !is_file('/etc/ssl/certs/'.$domain.'.key'))
			shell_exec('sudo '.__DIR__.'/../genselfsigned.sh '.escapeshellarg($domain).' >> '.__DIR__.'/../../log/debug_log 2>&1');
		$certs = '
    SSLCertificateFile /etc/ssl/certs/'.$domain.'.crt
    SSLCertificateKeyFile /etc/ssl/certs/'.$domain.'.key';
	}

	$names = template_server_names($domain, $account_aliases ?? null, !empty($has_email));
	$vhost = apache_fpm_vhost($domain, $user, $certs, $names);
	if($vhost === '') {
		$errmsg = 'Error: cannot read the vhost template '.__DIR__.'/apache-ssl.conf.'."\n";
	} else {
		/* pool first: the vhost points at its socket */
		foreach(array($pool => account_fpm_pool($domain, $user, 'apache'), $file => $vhost) as $path => $content) {
			$tmp = tempnam('/tmp', 'reqad');
			file_put_contents($tmp, $content);
			shell_exec('sudo install -m 0644 -o root -g root '.escapeshellarg($tmp).' '.escapeshellarg($path));
			unlink($tmp);
		}
		error_log("apache vhost $file and pool $pool written\n", 3, __DIR__.'/../../log/debug_log');
	}
}

if($errmsg=='') {
	/* one bad vhost must not stop every site: test first, then reload (a
	   running httpd keeps serving while it loads); a failing vhost is set aside */
	shell_exec('sudo systemctl restart php-fpm >> '.__DIR__.'/../../log/debug_log 2>&1');
	if(stripos((string)shell_exec('sudo httpd -t 2>&1'), 'syntax ok') !== false) {
		shell_exec('sudo systemctl reload httpd >> '.__DIR__.'/../../log/debug_log 2>&1');
	} else {
		shell_exec('sudo mv '.escapeshellarg($file).' '.escapeshellarg($file.'.failed').' 2>&1');
		$errmsg = 'Error: the new Apache vhost did not pass "httpd -t", so it was set aside as '.$file.'.failed - Apache keeps running.';
		error_log($errmsg."\n", 3, __DIR__.'/../../log/debug_log');
	}
}

if($errmsg=='') {
	shell_exec('sudo su - '.$user.' -c "mkdir public_html logs tmp && ln -s public_html www"');
	shell_exec('sudo cp '.__DIR__.'/default-template.html ~'.$user.'/public_html/index.php');
	shell_exec('sudo chown '.$user.':'.$user.' ~'.$user.'/public_html/index.php');
	shell_exec('sudo chmod a+x ~'.$user);
	/* only the account and apache reach the docroot; PHP runs as the account, so
	   tmp/ (sessions, uploads) and logs/ stay its own (harden_account_perms.sh) */
	shell_exec('sudo chgrp apache ~'.$user.'/public_html && sudo chmod 0750 ~'.$user.'/public_html');
	shell_exec('sudo chmod 0770 ~'.$user.'/tmp ~'.$user.'/logs');

	if($letsencrypt) {
		$IP = trim(shell_exec("/usr/sbin/ip address show | grep 'scope global' | grep 'inet ' | head -n 1 | awk {'print \$2'} | awk -F/ {'print \$1'}"));
		$domain_main = (trim(shell_exec('dig +short a '.escapeshellarg($domain))) == $IP);
		$domain_www  = strpos(' '.$names.' ', ' www.'.$domain.' ') !== false
		               && trim(shell_exec('dig +short a '.escapeshellarg('www.'.$domain))) == $IP;
		/* the flash-message token from account_create() (hex), for the "certificate ready" toast */
		$ssltok = isset($sslmsgtoken) && $sslmsgtoken !== '' ? ' '.$sslmsgtoken : '';
		if($domain_main) {
			$names = $domain.($domain_www ? ',www.'.$domain : '');
			shell_exec(__DIR__.'/../obtain_letsencrypt_cert.sh '.escapeshellarg($names).$ssltok.' 2>/dev/null >/dev/null &');
		} else {
			$errmsg = "Error: Cannot obtain an Let's Encrypt certificate because domain $domain does not resolve to this server IP $IP.";
			error_log($errmsg."\n", 3, __DIR__.'/../../log/debug_log');
		}
	}
}

/* Standalone CLI use prints the result and stops; account_create() needs control back. */
if(php_sapi_name() == "cli" && !defined('REQAD_TEMPLATE_RETURN')) {
	echo $errmsg;
	exit;
}
?>
