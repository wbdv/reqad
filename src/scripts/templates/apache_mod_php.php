<?php

#if(php_sapi_name() != "cli" || posix_getuid() != 0) {
#     echo "You can only run this script as root from cli.\n";
#     exit;
#}

error_log("Start ".__FILE__."\n",  3, __DIR__.'/../../log/debug_log');

if($domain=='' || !isset($domain) || $user=='' || !isset($user)) {
    echo "Undefined user/domain.\n";
	exit;
}

    $template_ssl = file_get_contents(__DIR__.'/apache-ssl.conf');
#    $template     = file_get_contents(__DIR__.'/apache.conf');
    if($template === false || $template_ssl === false)
        error_log("Cannot load template files ".__DIR__.'/apache.conf and '.__DIR__.'/apache-ssl.conf'."\n",  3, __DIR__.'/../../log/debug_log');
    $file = '/etc/httpd/conf.d/'.$domain.'.conf';
    if(!is_file($file)) {
        if(!is_file('/etc/letsencrypt/live/'.$domain.'/cert.pem')) {
			if(!is_file('/etc/ssl/certs/'.$domain.'.crt') || !is_file('/etc/ssl/certs/'.$domain.'.key')) {
		    	shell_exec('sudo '.__DIR__.'/../genselfsigned.sh '.$domain.' >> '.__DIR__.'/../../log/debug_log 2>&1');
			}

			$certs = '
		SSLCertificateFile /etc/ssl/certs/%DOMAIN%.crt
    	SSLCertificateKeyFile /etc/ssl/certs/%DOMAIN%.key';
		} else {
			$certs = '
		SSLCertificateFile /etc/letsencrypt/live/%DOMAIN%/cert.pem
    	SSLCertificateKeyFile /etc/letsencrypt/live/%DOMAIN%/privkey.pem
   	 	SSLCertificateChainFile /etc/letsencrypt/live/%DOMAIN%/chain.pem';
		}

        $file_contents = str_replace('%DOMAIN%', $domain, str_replace('%USER%', $user, str_replace('%CERTS%', $certs, $template_ssl)));
	    $tmpfname = tempnam("/tmp", "reqad");
    	error_log("tmpfname $tmpfname\n", 3, __DIR__.'/../../log/debug_log');
        file_put_contents($tmpfname, $file_contents);
	    #shell_exec("sudo cat $tmpfname > $file");
	    shell_exec("sudo cp $tmpfname $file");
    	error_log("apache conf file $file was written\n", 3, __DIR__.'/../../log/debug_log');
        #echo $file."\n";
		#echo '<pre>'; echo htmlspecialchars(file_het_contents($file)); exit;
    } else {
        $errmsg = 'Error: file '.$file.' already exists.'."\n";
    	error_log("Error: $file exists.\n", 3, __DIR__.'/../../log/debug_log');
    }

#if(!is_file('/etc/ssl/certs/'.$domain.'.crt') || !is_file('/etc/ssl/certs/'.$domain.'.key')) {
#	shell_exec('sudo '.__DIR__.'/../genselfsigned.sh '.$domain.' >> '.__DIR__.'/../../log/debug_log 2>&1');
#}

if($errmsg=='') {
	// nginx should run with magento user
	shell_exec('sudo systemctl restart httpd >> '.__DIR__.'/../../log/debug_log 2>&1');
    shell_exec('sudo su - '.$user.' -c "mkdir public_html logs tmp && ln -s public_html www"');
    error_log ('sudo su - '.$user.' -c "mkdir public_html logs tmp && ln -s public_html www"'."\n", 3, __DIR__.'/../../log/debug_log');
	#shell_exec('sudo cp '.__DIR__.'/apache-2.4-template.html ~'.$user.'/public_html/index.php');
	shell_exec('sudo cp '.__DIR__.'/default-template.html ~'.$user.'/public_html/index.php');
    shell_exec('sudo chown '.$user.':'.$user.' ~'.$user.'/public_html/index.php');
    shell_exec('sudo chmod a+x ~'.$user);

    if($letsencrypt) {
		$IP = trim(shell_exec("/usr/sbin/ip address show | grep 'scope global' | grep 'inet ' | head -n 1 | awk {'print \$2'} | awk -F/ {'print \$1'}"));
		$domain_main = false;
		$domain_www = false;
		if(trim(shell_exec('dig +short a '.$domain))==$IP)
			$domain_main = true;
		if(trim(shell_exec('dig +short a www.'.$domain))==$IP)
			$domain_www = true;

		if($domain_main && $domain_www) {
			shell_exec('sudo certbot --non-interactive --apache -d '.$domain.',www.'.$domain.' >> '.__DIR__.'/../../log/debug_log 2>&1');
		} else if($domain_main) {
			shell_exec('sudo certbot --non-interactive --apache -d '.$domain.' >> '.__DIR__.'/../../log/debug_log 2>&1');
        } else {
            $errmsg = "Error: Cannot obtain an Let's Encrypt certificate because domain $domain does not resolve to this server IP $IP.";
            error_log ($errmsg."\n", 3, __DIR__.'/../../log/debug_log');
        }
	}

} else {
	if(php_sapi_name() != "cli" || posix_getuid() != 0) {
		echo $errmsg;
		exit;
	}
}

?>
