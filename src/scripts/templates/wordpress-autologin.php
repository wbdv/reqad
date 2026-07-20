<?php
/*
Plugin Name: OTL (mu)
Description: One-time login endpoint (signed, short-lived). Place in wp-content/mu-plugins/
*/

add_action('init', function() {
    if (! isset($_GET['otl_expires'], $_GET['otl_sig']) ) return;
    if (! is_ssl() ) wp_die('HTTPS required.');

    $expires = intval($_GET['otl_expires']);
    $sig     = $_GET['otl_sig'];
    if ( time() > $expires ) wp_die('Expired.');

    // Use existing WP salt as HMAC key
    if ( ! defined('SECURE_AUTH_KEY') ) wp_die('Not configured.');

    $expected = hash_hmac('sha256', (string)$expires, SECURE_AUTH_KEY);

    if ( ! hash_equals($expected, $sig) ) wp_die('Invalid signature.');

    // single-use transient
    $key = 'otl_' . substr(hash('sha256', $sig . ':' . $expires),0,32);
    if ( get_transient($key) ) wp_die('Already used.');

    // find first administrator
    $admins = get_users(['role'=>'Administrator','number'=>1,'orderby'=>'ID','order'=>'ASC']);
    if ( empty($admins) ) wp_die('No admin.');
    $u = $admins[0];

    wp_set_current_user($u->ID);
    wp_set_auth_cookie($u->ID);
    set_transient($key, 1, 300); // single-use window
    wp_redirect(admin_url());
    exit;
});
