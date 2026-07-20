<?php

include_once(__DIR__.'/../public_html/defines.php');

error_log("update_ssl domain: $domain ".__FILE__."\n", 3, __DIR__.'/../log/debug_log');

$newcert_file = "/etc/ssl/certs/".$domain."_newcert.pem";
$privkey_file = "/etc/ssl/certs/".$domain."_privkey.pem";

error_log("update_ssl files: $newcert_file $privkey_file ".__FILE__."\n", 3, __DIR__.'/../log/debug_log');

if(!is_file($newcert_file) || !is_file($privkey_file)) return;

$TEMPLATE = trim(shell_exec("grep -e '^template=' /usr/local/reqad/etc/server-software.ini | awk -F= '{print \$2}'"));

if(substr($TEMPLATE, 0, 7) == 'apache_') {
    $httpdcf_file = "/etc/httpd/conf.d/".$domain.".conf";
    if(is_file($httpdcf_file)) {
        shell_exec("sudo sed -i 's#SSLCertificateFile .*#SSLCertificateFile ".$newcert_file."#' ".$httpdcf_file);
        shell_exec("sudo sed -i 's#SSLCertificateKeyFile .*#SSLCertificateKeyFile ".$privkey_file."#' ".$httpdcf_file);
        $output = trim(shell_exec("sudo apachectl configtest 2>&1 | grep -i error"));
        if($output == '') {
            shell_exec("sudo systemctl restart httpd");
        } else {
            error_log("update_ssl httpd configtest error: $output\n", 3, __DIR__.'/../log/debug_log');
        }
    }
} else {
    $nginxcf_file = "/etc/nginx/conf.d/".$domain.".conf";
    shell_exec("sudo sed -i 's#ssl_certificate[[:blank:]].*;#ssl_certificate ".$newcert_file.";#' ".$nginxcf_file);
    shell_exec("sudo sed -i 's#ssl_certificate_key[[:blank:]].*;#ssl_certificate_key ".$privkey_file.";#' ".$nginxcf_file);
    $output = trim(shell_exec("sudo nginx -t 2>&1 | grep '\[emerg\]'"));
    if($output == '') {
        shell_exec("sudo systemctl restart nginx");
    } else {
        error_log("update_ssl nginx -t error: $output\n", 3, __DIR__.'/../log/debug_log');
    }
}
