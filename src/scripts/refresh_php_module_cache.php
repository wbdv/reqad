#!/usr/bin/php82
<?php
require_once '/usr/local/reqad/public_html/defines.php';

$php_versions = array_map('trim', explode(',', $ini['php_versions']));
$exclude_re   = '/(common|cli|fpm|dbg|devel|embedded|runtime|build|scldevel|fedora-autoloader)$/';

foreach ($php_versions as $version) {
	$ver_suffix = ($version == $ini['php']) ? '' : str_replace('.', '', $version);
	$pkg_prefix = $ver_suffix ? "php{$ver_suffix}-php-" : 'php-';
	$cache_key  = $ver_suffix ?: 'default';
	$cache_file = _PATH . '/log/php_modules_' . $cache_key . '.cache';
	$pkg_glob   = $ver_suffix ? "php{$ver_suffix}-php-*" : "php-*";

	$repoq = trim(shell_exec("dnf repoquery -y '$pkg_glob' --queryformat '%{name} : %{summary}' 2>/dev/null") ?: '');
	$avail = [];
	foreach (array_filter(array_map('trim', explode("\n", $repoq))) as $line) {
		if (!preg_match('/^(' . preg_quote($pkg_prefix, '/') . '[a-z0-9][a-z0-9\-]*)\s*:\s*(.*)$/', $line, $lm)) continue;
		$pkg = $lm[1]; $sum = trim($lm[2]);
		if (preg_match($exclude_re, $pkg)) continue;
		if (!$ver_suffix && (preg_match('/[A-Z]/', $pkg) || strlen(str_replace('php-', '', $pkg)) > 30)) continue;
		$avail[$pkg] = $sum;
	}
	file_put_contents($cache_file, json_encode($avail));
	echo date('Y-m-d H:i:s') . " PHP $version: cached " . count($avail) . " packages\n";
}
