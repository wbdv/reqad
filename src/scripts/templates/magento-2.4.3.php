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

$nginx_file = '/etc/nginx/conf.d/'.$domain.'.conf';
$nginx_content = 'server {
	listen 80;
	access_log off;
	error_log off;
	server_name '.$domain.' www.'.$domain.';
	return 301 https://$host$request_uri;
}

server {
	listen 443 ssl http2;
	http2_push_preload on; # enable preload
	http2_max_concurrent_pushes 50; # set max pushes per request
	access_log off;
	error_log off;
	server_name '.$domain.' www.'.$domain.';
	ssl_certificate /etc/ssl/certs/'.$domain.'.crt;
	ssl_certificate_key /etc/ssl/certs/'.$domain.'.key;

    location @backend {
        internal;
        proxy_pass http://127.0.0.1:6081;
        proxy_set_header X-Real-IP  $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Forwarded-Port 443;
        proxy_set_header Host $host;
    }

  	location / {
		location ~*.*\.(3gp|gif|jpg|jpeg|png|ico|wmv|avi|asf|asx|mpg|mpeg|mp4|pls|mp3|mid|wav|swf|flv|html|htm|txt|js|css|exe|zip|tar|rar|gz|tgz|bz2|uha|7z|doc|docx|xls|xlsx|pdf|iso|webp)$ {
			expires 1y;
			try_files $uri @backend;
  	    }
		proxy_pass http://127.0.0.1:6081;
    	proxy_set_header X-Real-IP  $remote_addr;
    	proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
	    proxy_set_header X-Forwarded-Proto https;
    	proxy_set_header X-Forwarded-Port 443;
	    proxy_set_header Host $host;
  	}
}

upstream fastcgi_backend {
    server   unix:/run/php-fpm.sock;
}

server {
	listen 127.0.0.1:8080;
	set_real_ip_from   127.0.0.1;
	real_ip_header     X-Forwarded-For;  

	server_name '.$domain.' www.'.$domain.';
	access_log /var/log/nginx/'.$domain.'_log;
	error_log /var/log/nginx/'.$domain.'_log;

	set $MAGE_ROOT /home/'.$user.'/public_html;
	# include /home/'.$user.'/public_html/nginx.conf.sample;
	# https://raw.githubusercontent.com/magento/magento2/2.4-develop/nginx.conf.sample
	root $MAGE_ROOT/pub;

	index index.php;
	autoindex off;
	charset UTF-8;
	error_page 404 403 = /errors/404.php;
	#add_header "X-UA-Compatible" "IE=Edge";
	
	# Deny access to sensitive files
	location /.user.ini {
		deny all;
	}
	
	# PHP entry point for setup application
	location ~* ^/setup($|/) {
		root $MAGE_ROOT;
		location ~ ^/setup/index.php {
			fastcgi_pass   fastcgi_backend;
	
			fastcgi_param  PHP_FLAG  "session.auto_start=off \n suhosin.session.cryptua=off";
			fastcgi_param  PHP_VALUE "memory_limit=4096M \n max_execution_time=600";
			fastcgi_read_timeout 600s;
			fastcgi_connect_timeout 600s;
	
			fastcgi_index  index.php;
			fastcgi_param  SCRIPT_FILENAME  $document_root$fastcgi_script_name;
			include        fastcgi_params;
		}
	
		location ~ ^/setup/(?!pub/). {
			deny all;
		}
	
		location ~ ^/setup/pub/ {
			add_header X-Frame-Options "SAMEORIGIN";
		}
	}
	
	# PHP entry point for update application
	location ~* ^/update($|/) {
		root $MAGE_ROOT;
	
		location ~ ^/update/index.php {
			fastcgi_split_path_info ^(/update/index.php)(/.+)$;
			fastcgi_pass   fastcgi_backend;
			fastcgi_index  index.php;
			fastcgi_param  SCRIPT_FILENAME  $document_root$fastcgi_script_name;
			fastcgi_param  PATH_INFO        $fastcgi_path_info;
			include        fastcgi_params;
		}
	
		# Deny everything but index.php
		location ~ ^/update/(?!pub/). {
			deny all;
		}
	
		location ~ ^/update/pub/ {
			add_header X-Frame-Options "SAMEORIGIN";
		}
	}
	
	location / {
		try_files $uri $uri/ /index.php$is_args$args;
	}
	
	location /pub/ {
		location ~ ^/pub/media/(downloadable|customer|import|custom_options|theme_customization/.*\.xml) {
			deny all;
		}
		alias $MAGE_ROOT/pub/;
		add_header X-Frame-Options "SAMEORIGIN";
	}
	
	location /static/ {
		# Uncomment the following line in production mode
		# expires max;
	
		# Remove signature of the static files that is used to overcome the browser cache
		location ~ ^/static/version\d*/ {
			rewrite ^/static/version\d*/(.*)$ /static/$1 last;
		}
	
		location ~* \.(ico|jpg|jpeg|png|gif|svg|svgz|webp|avif|avifs|js|css|eot|ttf|otf|woff|woff2|html|json|webmanifest)$ {
			add_header Cache-Control "public";
			add_header X-Frame-Options "SAMEORIGIN";
			expires +1y;
	
			if (!-f $request_filename) {
				rewrite ^/static/(version\d*/)?(.*)$ /static.php?resource=$2 last;
			}
		}
		location ~* \.(zip|gz|gzip|bz2|csv|xml)$ {
			add_header Cache-Control "no-store";
			add_header X-Frame-Options "SAMEORIGIN";
			expires    off;
	
			if (!-f $request_filename) {
			   rewrite ^/static/(version\d*/)?(.*)$ /static.php?resource=$2 last;
			}
		}
		if (!-f $request_filename) {
			rewrite ^/static/(version\d*/)?(.*)$ /static.php?resource=$2 last;
		}
		add_header X-Frame-Options "SAMEORIGIN";
	}
	
	location /media/ {
	
	## The following section allows to offload image resizing from Magento instance to the Nginx.
	## Catalog image URL format should be set accordingly.
	## See https://docs.magento.com/user-guide/configuration/general/web.html#url-options
	#   location ~* ^/media/catalog/.* {
	#
	#       # Replace placeholders and uncomment the line below to serve product images from public S3
	#       # See examples of S3 authentication at https://github.com/anomalizer/ngx_aws_auth
	#       # resolver 8.8.8.8;
	#       # proxy_pass https://<bucket-name>.<region-name>.amazonaws.com;
	#
	#       set $width "-";
	#       set $height "-";
	#       if ($arg_width != "") {
	#           set $width $arg_width;
	#       }
	#       if ($arg_height != "") {
	#           set $height $arg_height;
	#       }
	#       image_filter resize $width $height;
	#       image_filter_jpeg_quality 90;
	#   }
	
		try_files $uri $uri/ /get.php$is_args$args;
	
		location ~ ^/media/theme_customization/.*\.xml {
			deny all;
		}
	
		location ~* \.(ico|jpg|jpeg|png|gif|svg|svgz|webp|avif|avifs|js|css|eot|ttf|otf|woff|woff2)$ {
			add_header Cache-Control "public";
			add_header X-Frame-Options "SAMEORIGIN";
			expires +1y;
			try_files $uri $uri/ /get.php$is_args$args;
		}
		location ~* \.(zip|gz|gzip|bz2|csv|xml)$ {
			add_header Cache-Control "no-store";
			add_header X-Frame-Options "SAMEORIGIN";
			expires    off;
			try_files $uri $uri/ /get.php$is_args$args;
		}
		add_header X-Frame-Options "SAMEORIGIN";
	}
	
	location /media/customer/ {
		deny all;
	}
	
	location /media/downloadable/ {
		deny all;
	}
	
	location /media/import/ {
		deny all;
	}
	
	location /media/custom_options/ {
		deny all;
	}
	
	location /errors/ {
		location ~* \.xml$ {
			deny all;
		}
	}
	
	# PHP entry point for main application
	location ~ ^/(index|get|static|errors/report|errors/404|errors/503|health_check)\.php$ {
		try_files $uri =404;
		fastcgi_pass   fastcgi_backend;
		fastcgi_buffers 16 16k;
		fastcgi_buffer_size 32k;
	
		fastcgi_param  PHP_FLAG  "session.auto_start=off \n suhosin.session.cryptua=off";
		fastcgi_param  PHP_VALUE "memory_limit=4096M \n max_execution_time=18000";
		fastcgi_read_timeout 600s;
		fastcgi_connect_timeout 600s;
	
		fastcgi_index  index.php;
		fastcgi_param  SCRIPT_FILENAME  $document_root$fastcgi_script_name;
		include        fastcgi_params;
	}
	
	# Banned locations (only reached if the earlier PHP entry point regexes does not match)
	location ~* (\.php$|\.phtml$|\.htaccess$|\.git) {
		deny all;
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
listen = /run/php-fpm.sock
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

php_admin_value[error_log] = /var/log/php-fpm/'.$domain.'.log
php_admin_flag[log_errors] = on
php_admin_value[memory_limit] = 2048M
php_value[session.save_handler] = files
php_value[session.save_path]    = /var/lib/php/session
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
	// nginx should run with magento user
	shell_exec('sudo sed -i "s/user  nginx;/user  '.$user.';/" /etc/nginx/nginx.conf');
	shell_exec('sudo systemctl restart nginx php-fpm varnish >> '.__DIR__.'/../../log/debug_log 2>&1');
	shell_exec('sudo su - '.$user.' -c "mkdir -p public_html/pub && touch public_html/pub/index.php"');
	shell_exec('sudo cp '.__DIR__.'/magento-2.4.3-template.html ~'.$user.'/public_html/pub/index.php');
	shell_exec('sudo certbot --non-interactive --nginx -d '.$domain.',www.'.$domain.' >> '.__DIR__.'/../../log/debug_log 2>&1');
	shell_exec('sudo systemctl restart nginx >> '.__DIR__.'/../../log/debug_log 2>&1');
} else {
	if(php_sapi_name() != "cli" || posix_getuid() != 0) {
		echo $errmsg;
		exit;
	}
}

?>
