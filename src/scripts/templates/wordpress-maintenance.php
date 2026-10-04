<?php
/*
Plugin Name: Maintenance Mode (Reqad)
Description: Shows visitors a "back soon" page (HTTP 503) while logged-in editors see the site. Managed from the Reqad WP Toolkit — turn it off there, or delete this file.
*/

// template_redirect never fires for wp-login.php, wp-admin, admin-ajax, REST or
// cron, so logging in and the dashboard keep working.
add_action('template_redirect', function () {
	if (is_user_logged_in() && current_user_can('edit_posts'))
		return;

	status_header(503);
	header('Retry-After: 3600');
	nocache_headers();
	$name = esc_html(get_bloginfo('name'));
	echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
	   . '<meta name="robots" content="noindex"><title>' . $name . ' — Maintenance</title>'
	   . '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f6f8fb;color:#354052}'
	   . 'div{text-align:center;padding:24px}h1{font-size:28px;margin:0 0 10px}p{color:#6c757d;margin:0}</style></head>'
	   . '<body><div><h1>' . $name . '</h1><p>We are performing scheduled maintenance. Please check back soon.</p></div></body></html>';
	exit;
}, 0);

// Remind logged-in users that visitors cannot see the site.
add_action('admin_bar_menu', function ($bar) {
	$bar->add_node(array(
		'id'    => 'reqad-maintenance',
		'title' => '<span style="background:#d63939;color:#fff;padding:2px 8px;border-radius:3px;">Maintenance mode ON</span>',
	));
}, 100);
