<?php
/*
Plugin Name: Hide WordPress Version (Reqad)
Description: Removes the WordPress version from the page head, feeds and asset URLs. Managed from the Reqad WP Toolkit — turn it off there, or delete this file.
*/

// <meta name="generator">, RSS/Atom/OPML generator tags — and the ones plugins
// such as WooCommerce append to it — all pass through this filter.
add_filter('the_generator', '__return_empty_string', 99);
remove_action('wp_head', 'wp_generator');

// Core scripts and styles are loaded as ?ver=<WordPress version>. Swap that for
// a salted hash of it: the version is gone, but the value still changes on
// every update, so browsers and caches still fetch the new files.
function reqad_hide_wp_version_src($src) {
	global $wp_version;
	parse_str((string)parse_url($src, PHP_URL_QUERY), $q);
	if (!isset($q['ver']) || $q['ver'] !== $wp_version)   // exact: a plugin's own 7.1.2.1 stays
		return $src;
	return add_query_arg('ver', substr(wp_hash('ver' . $wp_version), 0, 8), $src);
}
add_filter('script_loader_src', 'reqad_hide_wp_version_src', 99);
add_filter('style_loader_src', 'reqad_hide_wp_version_src', 99);
