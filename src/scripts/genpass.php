#!/usr/bin/php82
<?php

function gen_pass($password) {
    $salt = substr(bin2hex(openssl_random_pseudo_bytes(16)),0,16);
    $hash = crypt($password, sprintf('$6$%s$', $salt));
    return $hash;
}
echo "Enter a password: ";
$handle = fopen ("php://stdin","r");
$line = fgets($handle);
$pass = gen_pass($line);
echo "Encrypted password: ".$pass."\n";

