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

/* Alias domains to seed into server_name. create_account.php sets $account_aliases
   (e.g. array('www.<domain>') when the "Create www alias" box is checked, or an
   empty array when unchecked). The CLI adduserdomain path doesn't set it, so default
   to www.<domain> for backward compatibility. */
$alias_list = (isset($account_aliases) && is_array($account_aliases)) ? $account_aliases : array('www.'.$domain);
$alias_str = '';
foreach($alias_list as $__al) $alias_str .= ' '.$__al;
/* mail.<domain> is added to server_name only when the account has email (matches
   account_server_names() used on alias add/remove). $has_email is set by
   create_account.php; the CLI path leaves it unset -> no mail entry. */
$mail_str = (isset($has_email) && $has_email) ? ' mail.'.$domain : '';

$nginx_file = '/etc/nginx/conf.d/'.$domain.'.conf';
$nginx_content = '
upstream php-fpm-'.$user.' {
    server   unix:/run/php-fpm-'.$user.'.sock;
}

server {
	listen 80;
	access_log off;
	error_log off;
	server_name '.$domain.$alias_str.$mail_str.';
	return 301 https://$host$request_uri;
}

server {
	listen 443 ssl;
	http2 on;

	server_name '.$domain.$alias_str.$mail_str.';
	ssl_certificate /etc/ssl/certs/'.$domain.'.crt;
	ssl_certificate_key /etc/ssl/certs/'.$domain.'.key;

	#access_log off;
	#error_log /dev/null crit;
	error_log /var/log/nginx/'.$domain.'_log;
	access_log /var/log/nginx/'.$domain.'_log;

   	root        /home/'.$user.'/public_html;
   	autoindex   off;
   	index       index.php index.html index.htm;

    # Rocket-Nginx configuration
    # include rocket-nginx/conf.d/default.conf;

    # Allow OPTIONS for Wordpress
    allow_methods "^(GET|POST|HEAD|OPTIONS)$";

	# Block bad bots
    # if ($http_user_agent ~* (AhrefsBot|AhrefsSiteAudit|SeznamBot|SEOkicks|vip0|Re-re|dataforseo|Semrush|Rogerbot|mj12bot|Webmeup|SeekportBot|serpstatbot|FriendlyCrawler|Timpibot) ) {
    #    return 444;
    # }

  	location / {

		# wordpress rewrite
        location /wp-json {
            rewrite ^/wp-json(.*)$ /?rest_route=$1;
        }

        try_files $uri $uri/ /index.php$is_args$args;

        location ~ \.php$ {
            try_files                   $uri =404;
            fastcgi_split_path_info     ^(.+\.php)(/.+)$;
            fastcgi_intercept_errors    on;

            #NOTE: You should have "cgi.fix_pathinfo = 0;" in php.ini
            include         /etc/nginx/fastcgi_params;
            fastcgi_index   index.php;
            fastcgi_param   SCRIPT_FILENAME     $document_root$fastcgi_script_name;
            fastcgi_pass    php-fpm-'.$user.';
        }

        location ~* \.(js|css|png|jpg|jpeg|gif|ico|woff2)$ {
            expires max;
            log_not_found off;
        }
        location = /favicon.ico {
            log_not_found off;
            access_log off;
        }

        location = /robots.txt {
            allow all;
            log_not_found off;
            access_log off;
        }

        # location = /xmlrpc.php {
        #     #deny all;
        #     access_log off;
        #     log_not_found off;
        #     return 404;
        # }

    	# location = /2x {
        #	return 200;
    	# }

        # Deny all attempts to access hidden files such as .htaccess, .htpasswd, .DS_Store
        location ~ /\. {
            deny all;
        }

        # Deny access to any files with a .php extension in the uploads directory
        # Works in sub-directory installs and also in multisite network
        location ~* /(?:uploads|files)/.*\.php$ {
            deny all;
        }
    }
}
';


if(!is_file('/etc/ssl/certs/'.$domain.'.crt') || !is_file('/etc/ssl/certs/'.$domain.'.key')) {
	shell_exec('sudo '.__DIR__.'/../genselfsigned.sh '.$domain.' >> '.__DIR__.'/../../log/debug_log 2>&1');
}


if(!is_file($nginx_file)) {
	$tmpfname = tempnam("/tmp", "reqad");
    file_put_contents($tmpfname, $nginx_content);
	shell_exec("sudo mv $tmpfname $nginx_file");
	error_log("nginx conf file $nginx_file was written\n", 3, __DIR__.'/../../log/debug_log');
} else {
    $errmsg = 'Error: file '.$nginx_file.' already exists.'."\n";
	error_log("Error: $nginx_file exists.\n", 3, __DIR__.'/../../log/debug_log');
}

$phpfpm_file = '/etc/php-fpm.d/'.$domain.'.conf';
$phpfpm_content = '['.$domain.']
user = '.$user.'
group = nginx
listen = /run/php-fpm-'.$user.'.sock
listen.owner = '.$user.'
listen.group = nginx
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

if(!is_file($phpfpm_file)) {
	$tmpfname = tempnam("/tmp", "reqad");
    file_put_contents($tmpfname, $phpfpm_content);
	shell_exec("sudo mv $tmpfname $phpfpm_file");
	error_log("php-fpm conf file $phpfpm_file was written\n", 3, __DIR__.'/../../log/debug_log');
} else {
    $errmsg = 'Error: file '.$phpfpm_file.' already exists.'."\n";
	error_log("Error: php-fpm file $nginx_file exists\n", 3, __DIR__.'/../../log/debug_log');
}

#file_put_contents('/etc/php-fpm.d/www.conf', '');
if($errmsg=='') {
	shell_exec('sudo systemctl restart nginx php-fpm >> '.__DIR__.'/../../log/debug_log 2>&1');
	shell_exec('sudo su - '.$user.' -c "mkdir public_html logs tmp && ln -s public_html www"');
	error_log ('sudo su - '.$user.' -c "mkdir public_html logs tmp && ln -s public_html www"'."\n", 3, __DIR__.'/../../log/debug_log');

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

        /* Optional message-queue token (set by create_account.php) — the script
           posts the issuance result to messages.db under it so the accounts list
           can show a toast when the cert is ready. Token is hex, safe to pass. */
        $ssltok = isset($sslmsgtoken) && $sslmsgtoken !== '' ? ' '.$sslmsgtoken : '';

        if($domain_main && $domain_www) {
			// sleep 60s for dns to propagate
			// TODO add to task queue because dns propagation sometimes does not work
            //shell_exec('sudo certbot --non-interactive --nginx -d '.$domain.',www.'.$domain.' >> '.__DIR__.'/../../log/debug_log 2>&1');
			shell_exec(__DIR__.'/../obtain_letsencrypt_cert.sh '.$domain.',www.'.$domain.$ssltok.' 2>/dev/null >/dev/null &');
        } else if($domain_main) {
			// sleep 60s for dns to propagate
            //shell_exec('sudo certbot --non-interactive --nginx -d '.$domain.' >> '.__DIR__.'/../../log/debug_log 2>&1');
			shell_exec(__DIR__.'/../obtain_letsencrypt_cert.sh '.$domain.$ssltok.' 2>/dev/null >/dev/null &');
        } else {
            $errmsg = "Error: Cannot obtain an Let's Encrypt certificate because domain $domain does not resolve to this server IP $IP.";
			error_log ($errmsg."\n", 3, __DIR__.'/../../log/debug_log');
		}
    }
}

if(php_sapi_name() == "cli") {
	echo $errmsg;
	exit;
}
?>
