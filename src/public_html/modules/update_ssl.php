<?php
$domain   	 = trim($_POST["domain"]);
$ssltype   	 = trim($_POST["ssltype"]);
$newcert   	 = trim($_POST["newcert"]);
$privkey   	 = trim($_POST["privkey"]);

$errmsg 	= '';
$successmsg = '';

$TEMPLATE = shell_exec("grep -e '^template=' ../etc/server-software.ini | awk -F= {'print \$2'}");
#sleep(5);
#echo '<pre>'; print_r($_POST); exit;

if(preg_match('/[a-z0-9]+[a-z0-9\-\.]*[a-z0-9]+\.[a-z]{2,}/', $domain)) {
    $results = $db->query('SELECT * FROM accounts WHERE domain="'.$domain.'"');
    if ($row = $results->fetchArray()) {
		if($ssltype == 'letsencrypt') {
			#shell_exec('sudo certbot --non-interactive --nginx -d '.$domain.',www.'.$domain.' >> '.__DIR__.'/../../log/debug_log 2>&1');

		    if(substr($TEMPLATE,0,7)=='apache_')
				shell_exec('sudo certbot --non-interactive --apache -d '.$domain.' >> '.__DIR__.'/../../log/debug_log 2>&1');
			else
				shell_exec('sudo certbot --non-interactive --nginx -d '.$domain.' >> '.__DIR__.'/../../log/debug_log 2>&1');
			shell_exec('sudo /usr/local/reqad/scripts/update_email_sni >> '.__DIR__.'/../../log/debug_log 2>&1 &');
		} else if($ssltype == 'own' && substr($newcert,0,27)=='-----BEGIN CERTIFICATE-----' && (substr($privkey,0,27)=='-----BEGIN PRIVATE KEY-----' || substr($privkey,0,31)=='-----BEGIN RSA PRIVATE KEY-----')) {
			$x = trim(shell_exec("echo '".$newcert."' | openssl x509 -in /dev/stdin -noout -text| grep -E 'Issuer:|Not Before:|Not After :|Subject:|DNS:'"));	
			if($x=='') {
				$errmsg = "Error: Cannot parse certificate.";
			} else {
				preg_match('/Not After : (.+)/', $x, $matches);
				#print_r($matches);
				if(date('U', strtotime($matches[1]))-date('U') < 0) {
					$errmsg = "Error: Certificate is expired!";
				} else {
					// TODO check CM & DNS
					$x1 = trim(shell_exec("echo '".$newcert."' | openssl x509 -in /dev/stdin -noout -modulus"));
					$x2 = trim(shell_exec("echo '".$privkey."' | openssl rsa -in /dev/stdin -noout -modulus"));
					if($x1!=$x2) {
						$errmsg = "Error: Private key and certificate don't match.";
					} else {
				
						// Add intermediate certificate
						#$intercert_url = trim(shell_exec("echo '".$newcert."' | openssl x509  -in /dev/stdin -noout -text"));
						$intercert_url = trim(shell_exec("echo '".$newcert."' | openssl x509  -in /dev/stdin -noout -text | grep -E 'CA Issuers[ \t]+-[ \t]+URI:' | awk -FURI: {'print $2'}"));
						#echo('#2<pre>'.$intercert_url.'</pre>');
				    	error_log("Intermediate url: ".$intercert_url."\n", 3, '../log/debug_log');

						if($intercert_url!='' && substr($intercert_url,0,4)=='http') {
							$intercert = trim(shell_exec("curl -s $intercert_url | openssl x509 -in /dev/stdin -inform der -outform PEM"));
							#echo('#3<pre>'.$intercert.'</pre>');
					    	error_log("Intermediate cert:\n".$intercert."\n", 3, '../log/debug_log');
							if(substr($intercert,0,27)=='-----BEGIN CERTIFICATE-----') {
								$newcert.="\n".$intercert;
							}
						}

						#exit;

						// All clear, update certificate and reload web server
						shell_exec("echo '".$newcert."' | sudo tee /etc/ssl/certs/".$domain."_newcert.pem");
						shell_exec("echo '".$privkey."' | sudo tee /etc/ssl/certs/".$domain."_privkey.pem");
						require_once(__DIR__.'/../../scripts/update_ssl.php');
						shell_exec('sudo /usr/local/reqad/scripts/update_email_sni >> '.__DIR__.'/../../log/debug_log 2>&1 &');
					}
				}
			}
		} else {
			$errmsg = "Error: Please check the form for errors, not all fields are filled in.";
		}
	} else {
		// TODO if domain == hostname
        $errmsg = "Error: Domain name does not exists on this server.";
    }
} else {
    $errmsg =  "Error: Domain name is wrong, please check what you typed.";
}
//    	error_log(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$_SERVER["REMOTE_ADDR"]." ".$_SERVER['USER'].'INSERT INTO accounts VALUES ('.$uid.', "'.$user.'", "'.$domain.'", 0, '.$disk_quota.', '.($has_email?'true':'false').', active, datetime("now"))'."\n", 3, '../log/route_log');

if($errmsg == '') {
	$successmsg =  "Certificate successfully installed.";
//    header("Location: ".$_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/accounts/');
//    exit;
}
?>
