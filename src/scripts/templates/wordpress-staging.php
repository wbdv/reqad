<?php
/*
Plugin Name: Staging copy (Reqad)
Description: Made by the hosting panel (WordPress Toolkit > Clone or staging). While this file is here the site sends no email, asks search engines not to index it and does not update itself, so tests on this copy cannot reach real customers. Delete the copy in the panel when you are done with it.
*/

if (!defined('ABSPATH'))
	exit;

// No email: WordPress's own mail function, replaced before any plugin loads
// (SMTP plugins send through it too). Forms report that sending failed.
if (!function_exists('wp_mail')) {
	function wp_mail($to, $subject, $message, $headers = '', $attachments = array()) {
		do_action('wp_mail_failed', new WP_Error('wp_mail_failed', 'Email is switched off on this staging copy.', array('to' => $to, 'subject' => $subject)));
		return false;
	}
}

// Not for search engines, whatever Settings > Reading says.
add_filter('pre_option_blog_public', function () { return '0'; });
add_filter('wp_robots', function ($robots) { return function_exists('wp_robots_no_robots') ? wp_robots_no_robots($robots) : $robots; });
add_action('send_headers', function () { header('X-Robots-Tag: noindex, nofollow', true); });

// Updates only when you start them here: a copy must not change by itself.
add_filter('automatic_updater_disabled', '__return_true');
add_filter('auto_update_plugin', '__return_false', 100);
add_filter('auto_update_theme', '__return_false', 100);

add_action('admin_bar_menu', function ($bar) {
	$bar->add_node(array(
		'id'    => 'reqad-staging',
		'title' => '<span style="background:#7c3aed;color:#fff;padding:2px 8px;border-radius:3px;">Staging copy</span>',
		'meta'  => array('title' => 'A copy of ' . get_option('reqad_staging_of') . '. No email is sent from it.'),
	));
}, 1);

add_action('admin_notices', function () {
	$s = function_exists('get_current_screen') ? get_current_screen() : null;
	if (!$s || !in_array($s->id, array('dashboard', 'plugins', 'themes', 'update-core'), true))
		return;
	echo '<div class="notice notice-warning"><p><strong>This is a staging copy</strong> of ' . esc_html(get_option('reqad_staging_of'))
	   . '. Email is switched off, search engines are asked not to index it, WordPress does not update itself here and scheduled tasks do not run.'
	   . ' Plugins that send through their own service (newsletters, text messages) may still send: switch them off here.</p></div>';
});

// Content stays here: Push to live in the panel copies code and a few
// settings, never posts, pages, products or menus (they are in the database).
function reqad_staging_content_note() {
	return 'Staging copy: products, pages, posts, menus and media made or changed here are not copied to '
	     . get_option('reqad_staging_of') . ' by Push to live. Make them on the live site.';
}
add_action('admin_notices', function () {
	$s = function_exists('get_current_screen') ? get_current_screen() : null;
	if (!$s || !in_array($s->base, array('edit', 'post', 'edit-tags', 'term', 'nav-menus', 'widgets', 'upload', 'media'), true)
	    || (method_exists($s, 'is_block_editor') && $s->is_block_editor()))
		return;
	echo '<div class="notice notice-warning"><p>' . esc_html(reqad_staging_content_note()) . '</p></div>';
});
add_action('enqueue_block_editor_assets', function () {
	wp_register_script('reqad-staging', false, array('wp-data', 'wp-notices', 'wp-dom-ready'), null, true);
	wp_enqueue_script('reqad-staging');
	wp_add_inline_script('reqad-staging', 'wp.domReady(function(){wp.data.dispatch("core/notices").createWarningNotice('
	                     . wp_json_encode(reqad_staging_content_note()) . ',{id:"reqad-staging",isDismissible:true});});');
});
