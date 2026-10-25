<?php
/*
 * WordPress Toolkit > Clone or staging > Push to live: the code of a staging
 * copy goes back to its live site, never its database (GET/POST
 * /v1/wordpress/{copy}/push, the account panel).
 *
 *   wordpress_push_preview($copy)        what a push would change
 *   wordpress_push_start($copy, $o, $by) check, then push in the background
 *                                        (a wordpress_jobs row of kind push of
 *                                        the LIVE site: never next to an update of it)
 *   wordpress_push_run($live, $o)        the push itself (inside the job's unit)
 *
 * A live shop or blog keeps changing while the copy is tested (orders,
 * comments, customers), so its database is never overwritten. What goes:
 * WordPress itself, plugins, themes, must-use plugins and translations, only
 * where the files differ (fm-helper verbs wppushdiff / wppush, rsync -c AS
 * the account); never wp-config.php, .htaccess, robots.txt, the panel's own
 * must-use plugins (reqad-*: the staging one stays on the copy) or caches.
 * New uploads (files the live site does not have) only when asked. Plugins
 * switched on and the theme chosen on the copy are switched the same way
 * on the live site; a plugin switched OFF on the copy is not switched off on
 * the live site unless asked (copies are told to switch off plugins that
 * send). Then the live site's database is updated the way WordPress does it
 * after an update (wp core update-db, WooCommerce's wp wc update; other
 * plugins update their tables when they next load).
 *
 * Settings only when chosen, a few groups of options (wordpress_push_settings():
 * homepage, permalinks, WooCommerce's store details and setup...), each one
 * all or nothing; page ids are matched by the page's path. A new staging
 * copy records how those were when it was made (its option
 * reqad_staging_base, wordpress_push_snapshot()), so a setting changed on
 * the live site since is not ticked at first.
 *
 * Before: the live database is saved to ~/wp-backups/ (wordpress_db_backup)
 * and every file the push replaces or deletes is moved to
 * ~/wp-backups/<site>-push-<date>/ (the 3 newest are kept). After: the caches
 * are emptied and the home page is opened; when it worked before and does not
 * now, the files and the plugin switches are put back (wppushundo). The
 * database is never put back by itself: by then it may hold new orders.
 */

/* The live site of a staging copy: array(live row, copies row, '') or array(null, null, why not). */
function wordpress_push_pair(array $copy) {
	$c = wordpress_copy_get($copy['id']);
	if (!$c || $c['kind'] !== 'staging' || $c['account'] !== $copy['user'])
		return array(null, null, 'Only staging copies made in Clone or staging can be pushed to their live site.');
	$live = wordpress_find($c['source_id']);
	if (!$live || $live['user'] !== $copy['user'])
		return array(null, null, 'The live site of this copy ('.$c['source_label'].') is no longer in the list.');
	foreach (array($copy, $live) as $r) {
		$doc = rtrim(wordpress_docroot($r), '/');
		if (strpos($doc, '/home/'.$r['user'].'/') !== 0)
			return array(null, null, wordpress_site_label($r).' is not in the account\'s home folder.');
		if (is_link($doc))
			return array(null, null, wordpress_site_label($r).' is deployed from Git: its files come from the deployment.');
	}
	return array($live, $c, '');
}

/*
 * Settings a push can carry: a few WordPress options people change on a
 * staging copy and then expect on the live site, in groups that go all or
 * nothing (never the database as such). opts: option => label; ids: options
 * that hold a page's id (found on the live site by the page's path, as ids
 * differ once either site made pages); plugin: shown only while that plugin
 * is on on the copy; ask: never ticked at first; flush: the address rules
 * are made again after it.
 */
function wordpress_push_settings() {
	$wc = 'woocommerce/woocommerce.php';
	return array(
		'title' => array('name' => 'Site title and tagline', 'opts' => array('blogname' => 'Site title', 'blogdescription' => 'Tagline')),
		'front' => array('name' => 'Homepage', 'opts' => array('show_on_front' => 'Your homepage displays', 'page_on_front' => 'Homepage',
		                 'page_for_posts' => 'Posts page'), 'ids' => array('page_on_front', 'page_for_posts')),
		'reading' => array('name' => 'Posts per page and feeds', 'opts' => array('posts_per_page' => 'Blog pages show at most',
		                   'posts_per_rss' => 'Feeds show the most recent', 'rss_use_excerpt' => 'Feeds show excerpts')),
		'time' => array('name' => 'Time zone and date format', 'opts' => array('timezone_string' => 'Time zone', 'gmt_offset' => 'UTC offset',
		                'date_format' => 'Date format', 'time_format' => 'Time format', 'start_of_week' => 'Week starts on')),
		'language' => array('name' => 'Site language', 'opts' => array('WPLANG' => 'Site language')),
		'permalinks' => array('name' => 'Permalinks', 'opts' => array('permalink_structure' => 'Permalink structure', 'category_base' => 'Category base',
		                      'tag_base' => 'Tag base'), 'flush' => true),
		'discussion' => array('name' => 'Discussion settings', 'opts' => array('default_comment_status' => 'Allow comments on new posts',
		                      'default_ping_status' => 'Allow pingbacks on new posts', 'require_name_email' => 'Name and email required',
		                      'comment_registration' => 'Users must be logged in to comment', 'close_comments_for_old_posts' => 'Close comments on old posts',
		                      'close_comments_days_old' => 'Close comments after (days)', 'thread_comments' => 'Threaded comments',
		                      'thread_comments_depth' => 'Threaded comments levels', 'page_comments' => 'Break comments into pages',
		                      'comments_per_page' => 'Comments per page', 'comment_order' => 'Comment order', 'comments_notify' => 'Email on new comments',
		                      'moderation_notify' => 'Email on comments held for moderation', 'comment_moderation' => 'Comments approved by hand',
		                      'comment_previously_approved' => 'Commenter must have an approved comment', 'show_avatars' => 'Show avatars')),
		'media' => array('name' => 'Image sizes', 'opts' => array('thumbnail_size_w' => 'Thumbnail width', 'thumbnail_size_h' => 'Thumbnail height',
		                 'thumbnail_crop' => 'Crop thumbnails', 'medium_size_w' => 'Medium width', 'medium_size_h' => 'Medium height',
		                 'large_size_w' => 'Large width', 'large_size_h' => 'Large height', 'uploads_use_yearmonth_folders' => 'Uploads in month folders')),
		'wc_store' => array('name' => 'WooCommerce: store address, currency and units', 'plugin' => $wc, 'opts' => array(
		                    'woocommerce_store_address' => 'Address', 'woocommerce_store_address_2' => 'Address line 2', 'woocommerce_store_city' => 'City',
		                    'woocommerce_store_postcode' => 'Postcode', 'woocommerce_default_country' => 'Country / state',
		                    'woocommerce_allowed_countries' => 'Selling locations', 'woocommerce_all_except_countries' => 'Sell to all countries except',
		                    'woocommerce_specific_allowed_countries' => 'Sell to specific countries', 'woocommerce_ship_to_countries' => 'Shipping locations',
		                    'woocommerce_specific_ship_to_countries' => 'Ship to specific countries', 'woocommerce_default_customer_address' => 'Default customer location',
		                    'woocommerce_calc_taxes' => 'Taxes', 'woocommerce_enable_coupons' => 'Coupons', 'woocommerce_calc_discounts_sequentially' => 'Coupons one after another',
		                    'woocommerce_currency' => 'Currency', 'woocommerce_currency_pos' => 'Currency position', 'woocommerce_price_thousand_sep' => 'Thousand separator',
		                    'woocommerce_price_decimal_sep' => 'Decimal separator', 'woocommerce_price_num_decimals' => 'Number of decimals',
		                    'woocommerce_weight_unit' => 'Weight unit', 'woocommerce_dimension_unit' => 'Dimensions unit')),
		'wc_pages' => array('name' => 'WooCommerce: store pages', 'plugin' => $wc, 'opts' => array('woocommerce_shop_page_id' => 'Shop page',
		                    'woocommerce_cart_page_id' => 'Cart page', 'woocommerce_checkout_page_id' => 'Checkout page',
		                    'woocommerce_myaccount_page_id' => 'My account page', 'woocommerce_terms_page_id' => 'Terms and conditions'),
		                    'ids' => array('woocommerce_shop_page_id', 'woocommerce_cart_page_id', 'woocommerce_checkout_page_id',
		                                   'woocommerce_myaccount_page_id', 'woocommerce_terms_page_id'), 'flush' => true),
		'wc_setup' => array('name' => 'WooCommerce: setup finished', 'plugin' => $wc, 'opts' => array('woocommerce_onboarding_profile' => 'Setup wizard',
		                    'woocommerce_onboarding_profile_progress' => 'Setup steps', 'woocommerce_task_list_tracked_completed_tasks' => 'Tasks done',
		                    'woocommerce_task_list_hidden' => 'Task list hidden', 'woocommerce_task_list_complete' => 'Task list complete',
		                    'woocommerce_admin_customize_store_completed' => 'Store design done', 'woocommerce_no_sales_tax' => 'No sales tax',
		                    'woocommerce_allow_tracking' => 'Usage tracking')),
		'wc_payments' => array('name' => 'WooCommerce: bank transfer, check and cash on delivery', 'plugin' => $wc, 'opts' => array(
		                       'woocommerce_bacs_settings' => 'Direct bank transfer', 'woocommerce_bacs_accounts' => 'Bank accounts',
		                       'woocommerce_cheque_settings' => 'Check payments', 'woocommerce_cod_settings' => 'Cash on delivery',
		                       'woocommerce_gateway_order' => 'Order of payment methods')),
		'wc_visible' => array('name' => 'WooCommerce: store visibility', 'plugin' => $wc, 'ask' => true, 'opts' => array(
		                      'woocommerce_coming_soon' => 'Coming soon', 'woocommerce_store_pages_only' => 'Coming soon on store pages only')),
	);
}

/* Every option name of wordpress_push_settings(), and those that hold a page id. */
function wordpress_push_option_names() {
	$names = $ids = array();
	foreach (wordpress_push_settings() as $g) {
		$names = array_merge($names, array_keys($g['opts']));
		$ids = array_merge($ids, $g['ids'] ?? array());
	}
	return array($names, $ids);
}

/* One side of a push, read by wp-cli (plugins and themes off): version,
   plugins {file: name, version, active}, themes {stylesheet: name, version},
   stylesheet; the settings: opts {option: base64 of the stored value | null},
   posts {id: type, path, title} for the page ids among them, found
   {type:path => id} for $find (array of [type, path]), base (what the
   settings were when a staging copy was made: option => base64 | null, or
   null). $path: another folder than the site's own (a copy being made).
   array(data, '') or array(null, why not). */
function wordpress_push_side(array $row, array $find = array(), $path = '', $since = 0) {
	list($names, $ids) = wordpress_push_option_names();
	$code = <<<'EOT'
require_once ABSPATH.'wp-admin/includes/plugin.php';
global $wpdb;
$act = (array)get_option('active_plugins', array());
$pl = $th = array();
foreach (get_plugins() as $f => $p) $pl[$f] = array('name' => (string)$p['Name'], 'version' => (string)$p['Version'], 'active' => in_array($f, $act, true));
foreach (wp_get_themes() as $s => $t) $th[$s] = array('name' => (string)$t->get('Name'), 'version' => (string)$t->get('Version'));
$names = json_decode(base64_decode('REQAD_NAMES'), true);
$opts = array_fill_keys($names, null);
foreach ($wpdb->get_results("SELECT option_name, option_value FROM $wpdb->options WHERE option_name IN ('".implode("','", array_map('esc_sql', $names))."')") as $o)
	$opts[$o->option_name] = base64_encode($o->option_value);
$posts = array();
foreach (json_decode(base64_decode('REQAD_IDS'), true) as $n) {
	$id = $opts[$n] !== null ? (int)base64_decode($opts[$n]) : 0;
	if ($id > 0 && ($p = get_post($id)) && $p->post_status !== 'trash')
		$posts[$id] = array('type' => $p->post_type, 'path' => get_page_uri($p), 'title' => html_entity_decode($p->post_title, ENT_QUOTES, 'UTF-8'));
}
$found = array();
foreach (json_decode(base64_decode('REQAD_FIND'), true) as $f)
	if (($p = get_page_by_path($f[1], OBJECT, $f[0])) && $p->post_status !== 'trash')
		$found[$f[0].':'.$f[1]] = (int)$p->ID;
$content = array();
if (REQAD_SINCE > 0) {
	$skip = array('revision', 'customize_changeset', 'oembed_cache', 'user_request', 'shop_order', 'shop_order_refund', 'shop_order_placehold', 'shop_subscription', 'scheduled-action');
	$q = $wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE post_modified_gmt > %s AND post_status NOT IN ('auto-draft', 'trash') AND post_type NOT IN ('".implode("','", $skip)."') ORDER BY post_modified_gmt DESC LIMIT 300", gmdate('Y-m-d H:i:s', REQAD_SINCE));
	foreach ($wpdb->get_col($q) as $id) {
		$p = get_post((int)$id);
		if ($p && $p->post_type === 'product_variation' && $p->post_parent)
			$p = get_post($p->post_parent);
		if (!$p || isset($content[$p->ID]))
			continue;
		$content[$p->ID] = array('type' => $p->post_type, 'path' => $p->post_type === 'nav_menu_item' ? '' : (string)get_page_uri($p),
		                         'title' => html_entity_decode($p->post_title, ENT_QUOTES, 'UTF-8'));
	}
}
$base = get_option('reqad_staging_base', null);
echo "\n##REQAD##".json_encode(array('content' => array_values($content), 'version' => (string)$GLOBALS['wp_version'], 'plugins' => (object)$pl, 'themes' => (object)$th,
	'stylesheet' => (string)get_option('stylesheet'), 'multisite' => is_multisite(), 'opts' => (object)$opts, 'posts' => (object)$posts,
	'found' => (object)$found, 'base' => is_array($base) ? (object)$base : null), defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0);
EOT;
	$code = str_replace(array('REQAD_NAMES', 'REQAD_IDS', 'REQAD_FIND', 'REQAD_SINCE'),
	                    array(base64_encode(json_encode($names)), base64_encode(json_encode($ids)), base64_encode(json_encode(array_values($find))), (string)(int)$since), $code);
	$r = wordpress_cli($row, 'eval-file - --skip-plugins --skip-themes --path='.escapeshellarg($path !== '' ? $path : wordpress_docroot($row)), 90, "<?php\n".$code);
	$pos = strrpos($r['out'], '##REQAD##');
	$d = $pos !== false ? json_decode(substr($r['out'], $pos + 9), true) : null;
	if (!is_array($d))
		return array(null, (wp_cli_error(array('out' => $r['out'], 'rc' => $r['rc'] ?: 1)) ?: 'WordPress does not start').'.');
	if (!empty($d['multisite']))
		return array(null, 'It is a WordPress network (multisite): the panel cannot push those.');
	return array($d, '');
}

/* The settings of a new staging copy as they are when it is made (they are
   the live site's then): the option reqad_staging_base of the copy, so a push
   can tell which site changed a setting since. */
function wordpress_push_snapshot(array $row, $path) {
	list($names, ) = wordpress_push_option_names();
	$code = <<<'EOT'
global $wpdb;
$names = json_decode(base64_decode('REQAD_NAMES'), true);
$base = array_fill_keys($names, null);
foreach ($wpdb->get_results("SELECT option_name, option_value FROM $wpdb->options WHERE option_name IN ('".implode("','", array_map('esc_sql', $names))."')") as $o)
	$base[$o->option_name] = base64_encode($o->option_value);
delete_option('reqad_staging_base');
echo add_option('reqad_staging_base', $base, '', 'no') ? "\n##REQAD##ok" : "\n##REQAD##no";
EOT;
	$r = wordpress_cli($row, 'eval-file - --skip-plugins --skip-themes --path='.escapeshellarg($path), 60,
	                   "<?php\n".str_replace('REQAD_NAMES', base64_encode(json_encode($names)), $code));
	return strpos($r['out'], '##REQAD##ok') !== false;
}

/*
 * Write settings into a site (plugins on, so their own hooks run; when it
 * does not start so, with them off). $groups: group => {option => base64 of
 * the value | null (deleted)}; $ids: option => [type, path]: the id of that
 * page there instead; a group whose page is not there is left out whole.
 * Address rules are made again on the next visit when $flush; $wc: the
 * WooCommerce setup wizard is not opened by itself. Returns array(old
 * values {option => base64 | null} of what was written, groups left out,
 * '' | why it failed).
 */
function wordpress_push_settings_write(array $row, array $groups, array $ids, $flush, $wc = false) {
	$code = <<<'EOT'
global $wpdb;
$in = json_decode(base64_decode('REQAD_IN'), true);
$out = array('old' => array(), 'missing' => array());
foreach ($in['groups'] as $g => $set) {
	foreach ($set as $n => $v)
		if (isset($in['ids'][$n])) {
			$p = get_page_by_path($in['ids'][$n][1], OBJECT, $in['ids'][$n][0]);
			if (!$p || $p->post_status === 'trash') { $out['missing'][] = $g; continue 2; }
			$set[$n] = base64_encode((string)$p->ID);
		}
	foreach ($set as $n => $v) {
		$old = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name = %s", $n));
		$out['old'][$n] = $old === null ? null : base64_encode($old);
		if ($v === null) delete_option($n);
		else update_option($n, maybe_unserialize(base64_decode($v)));
	}
}
if ($in['flush']) delete_option('rewrite_rules');
if ($in['wc']) delete_transient('_wc_activation_redirect');
echo "\n##REQAD##".json_encode($out);
EOT;
	$code = "<?php\n".str_replace('REQAD_IN', base64_encode(json_encode(array('groups' => (object)array_map(function ($x) { return (object)$x; }, $groups),
	                                                                                  'ids' => (object)$ids, 'flush' => (bool)$flush, 'wc' => (bool)$wc))), $code);
	$p = ' --path='.escapeshellarg(wordpress_docroot($row));
	$r = wordpress_cli($row, 'eval-file -'.$p, 120, $code);
	if (strpos($r['out'], '##REQAD##') === false)
		$r = wordpress_cli($row, 'eval-file - --skip-plugins --skip-themes'.$p, 120, $code);
	$pos = strrpos($r['out'], '##REQAD##');
	$d = $pos !== false ? json_decode(substr($r['out'], $pos + 9), true) : null;
	if (!is_array($d))
		return array(array(), array(), wp_cli_error(array('out' => $r['out'], 'rc' => $r['rc'] ?: 1)) ?: 'WordPress does not start.');
	return array((array)$d['old'], (array)$d['missing'], '');
}

/* After a push: the copy's record of how its settings started takes the
   values pushed (they are the live site's now). */
function wordpress_push_rebase(array $copy, array $values) {
	$code = <<<'EOT'
$b = get_option('reqad_staging_base', null);
$b = is_array($b) ? $b : array();
foreach (json_decode(base64_decode('REQAD_IN'), true) as $n => $v) $b[$n] = $v;
delete_option('reqad_staging_base');
add_option('reqad_staging_base', $b, '', 'no');
EOT;
	wordpress_cli($copy, 'eval-file - --skip-plugins --skip-themes --path='.escapeshellarg(wordpress_docroot($copy)), 60,
	              "<?php\n".str_replace('REQAD_IN', base64_encode(json_encode((object)$values)), $code));
}

/* How a stored setting is shown in the preview. */
function wordpress_push_show($n, $b64, array $posts) {
	if ($b64 === null)
		return '(not set)';
	$v = base64_decode($b64);
	$named = array('show_on_front' => array('posts' => 'Your latest posts', 'page' => 'A static page'),
	               'woocommerce_coming_soon' => array('yes' => 'Coming soon', 'no' => 'Open to everyone'));
	if (isset($named[$n][$v]))
		return $named[$n][$v];
	if ($n === 'woocommerce_onboarding_profile') {
		$a = @unserialize($v, array('allowed_classes' => false));
		return is_array($a) && (!empty($a['completed']) || !empty($a['skipped'])) ? 'Finished' : 'Not finished';
	}
	list(, $ids) = wordpress_push_option_names();
	if (in_array($n, $ids, true))
		return (int)$v > 0 ? (isset($posts[(int)$v]) ? '"'.$posts[(int)$v]['title'].'"' : 'page #'.(int)$v) : '(none)';
	if ($v === '')
		return '(empty)';
	if (preg_match('/^([aOs]:\d+:|[bid]:[^;]*;$|N;$)/', $v))
		return '(set)';
	return mb_strimwidth($v, 0, 60, '…', 'UTF-8');
}

/* wp-cli's name of a plugin file: its folder, or the file without .php */
function wordpress_push_cli_name($file) {
	return dirname($file) === '.' ? basename($file, '.php') : dirname($file);
}

/*
 * What a push of the staging copy $copy would change on its live site.
 * items [{key (core | languages | plugin:<name> | theme:<name> | mu:<name>),
 * kind, slug, name, from (the live version), to (the copy's), state (added:
 * only on the copy | removed: only on the live site | changed), added,
 * changed, deleted (files), sample, default (ticked at first), why
 * (live_only | live_newer | '')}], switches [{key (on:|off:<plugin file>,
 * use:<theme>), op (on|off|use), slug, name, default, needs (the item that
 * brings its files, when the live site lacks them)}], settings [{key (a group
 * of wordpress_push_settings()), name, diff [{option, label, live, copy}],
 * needs (the switch that turns its plugin on), default, why (missing_page:
 * the page it points at is not on the live site | no_base: the copy does not
 * know how it started | live_changed | both | ask), missing / later (the
 * page's title; later: made by a plugin the push switches on, perhaps),
 * flush; with $full also set {option: base64 | null} and ids {option: [type,
 * path]}}], uploads {files, bytes}
 * (new ones), content [{type, title, new: not on the live site}] (posts, pages,
 * products, menu items... made or changed on the copy since it was made, at
 * most 300: never pushed), since, and live, copy, php {copy, live}, pushed
 * (last time), job.
 */
function wordpress_push_preview(array $copy, $full = false) {
	global $ini;
	list($live, $c, $err) = wordpress_push_pair($copy);
	if (!$live)
		return result_error($err);
	$user = $copy['user'];
	$home = '/home/'.$user.'/';
	list($s, $e) = wordpress_push_side($copy, array(), '', (int)$c['created']);
	if (!$s)
		return result_error('The staging copy does not start: '.$e);
	$find = array();
	foreach (array_merge(array_values((array)$s['posts']), (array)$s['content']) as $p)
		if ($p['path'] !== '')
			$find[$p['type'].':'.$p['path']] = array($p['type'], $p['path']);
	list($l, $e) = wordpress_push_side($live, $find);
	if (!$l)
		return result_error('The live site does not start: '.$e);
	$f = files_run($user, array('verb' => 'wppushdiff', 'from' => substr(rtrim(wordpress_docroot($copy), '/'), strlen($home)),
	                            'to' => substr(rtrim(wordpress_docroot($live), '/'), strlen($home)), 'uploads' => true), null, null, 280);
	if (empty($f['ok']))
		return result_error('The sites could not be compared: '.files_result($f)['error']);
	$find = function (array $list, $name) {
		foreach ($list as $file => $p)
			if ($file === $name || strpos($file, $name.'/') === 0)
				return array($file, $p);
		return array('', null);
	};
	$order = array('core' => 0, 'plugin' => 1, 'theme' => 2, 'mu' => 3, 'languages' => 4);
	$items = array();
	foreach ((array)$f['items'] as $key => $x) {
		list($kind, $slug) = strpos($key, ':') !== false ? explode(':', $key, 2) : array($key, '');
		if (!isset($order[$kind]))
			continue;
		$it = array('key' => $key, 'kind' => $kind, 'slug' => $slug, 'name' => $slug, 'from' => '', 'to' => '', 'state' => $x['state'],
		            'added' => (int)$x['added'], 'changed' => (int)$x['changed'], 'deleted' => (int)$x['deleted'], 'sample' => $x['sample'],
		            'default' => true, 'why' => '');
		if ($kind === 'core')
			$it = array_merge($it, array('name' => 'WordPress', 'from' => $l['version'], 'to' => $s['version']));
		elseif ($kind === 'languages')
			$it['name'] = 'Translations';
		elseif ($kind === 'plugin') {
			list($sf, $sp) = $find($s['plugins'], $slug);
			list($lf, $lp) = $find($l['plugins'], $slug);
			$p = $sp ?: $lp;
			$it = array_merge($it, array('name' => $p && $p['name'] !== '' ? $p['name'] : $slug, 'from' => $lp['version'] ?? '', 'to' => $sp['version'] ?? '',
			                             'live_active' => !empty($lp['active']), 'cli' => wordpress_push_cli_name($lf ?: ($sf ?: $slug))));
		} elseif ($kind === 'theme') {
			$sp = $s['themes'][$slug] ?? null;
			$lp = $l['themes'][$slug] ?? null;
			$p = $sp ?: $lp;
			$it = array_merge($it, array('name' => $p && $p['name'] !== '' ? $p['name'] : $slug, 'from' => $lp['version'] ?? '', 'to' => $sp['version'] ?? ''));
		}
		if ($it['state'] === 'removed')
			$it = array_merge($it, array('default' => false, 'why' => 'live_only'));
		elseif ($it['from'] !== '' && $it['to'] !== '' && version_compare($it['from'], $it['to'], '>'))
			$it = array_merge($it, array('default' => false, 'why' => 'live_newer'));
		$items[] = $it;
	}
	usort($items, function ($a, $b) use ($order) {
		return $order[$a['kind']] - $order[$b['kind']] ?: strnatcasecmp($a['name'], $b['name']);
	});
	$keys = array_column($items, null, 'key');

	/* switched on or off on the copy, and its theme */
	$sw = array();
	foreach ($s['plugins'] as $file => $p) {
		$lp = $l['plugins'][$file] ?? null;
		$cli = wordpress_push_cli_name($file);
		$x = array('slug' => $cli, 'name' => $p['name'] !== '' ? $p['name'] : $cli,
		           'needs' => !$lp && isset($keys['plugin:'.(dirname($file) === '.' ? $file : dirname($file))]) ? 'plugin:'.(dirname($file) === '.' ? $file : dirname($file)) : '');
		if ($p['active'] && !($lp && $lp['active']) && ($lp || $x['needs'] !== ''))
			$sw[] = array('key' => 'on:'.$file, 'op' => 'on', 'default' => true) + $x;
		elseif (!$p['active'] && $lp && $lp['active'])
			$sw[] = array('key' => 'off:'.$file, 'op' => 'off', 'default' => false) + $x;
	}
	$st = $s['stylesheet'];
	if ($st !== '' && $st !== $l['stylesheet'] && isset($s['themes'][$st]) && (isset($l['themes'][$st]) || isset($keys['theme:'.$st])))
		$sw[] = array('key' => 'use:'.$st, 'op' => 'use', 'slug' => $st, 'name' => $s['themes'][$st]['name'] ?: $st, 'default' => true,
		              'needs' => isset($l['themes'][$st]) ? '' : 'theme:'.$st, 'was' => $l['stylesheet']);

	/* settings, by group: what differs (page ids compared through the page
	   they point at), and which site changed it since the copy was made */
	$base = is_array($s['base']) ? $s['base'] : null;
	$sw_keys = array_column($sw, null, 'key');
	$plugins_on = (bool)array_filter($sw, function ($x) { return $x['op'] === 'on'; });
	$settings = array();
	foreach (wordpress_push_settings() as $gk => $g) {
		$needs = '';
		if (!empty($g['plugin'])) {
			if (empty($s['plugins'][$g['plugin']]['active']))
				continue;
			if (empty($l['plugins'][$g['plugin']]['active'])) {
				if (!isset($sw_keys['on:'.$g['plugin']]))
					continue;
				$needs = 'on:'.$g['plugin'];
			}
		}
		$diff = $set = $ids = array();
		$missing = $later = '';
		$live_changed = $copy_changed = $unknown = false;
		foreach ($g['opts'] as $n => $label) {
			$cv = $s['opts'][$n] ?? null;
			$lv = $l['opts'][$n] ?? null;
			if ($cv === null)
				continue;
			$want = $cv;
			$id = in_array($n, $g['ids'] ?? array(), true) ? (int)base64_decode($cv) : 0;
			if ($id > 0) {
				$p = $s['posts'][$id] ?? null;
				$lid = $p ? ($l['found'][$p['type'].':'.$p['path']] ?? 0) : 0;
				/* not there (yet): a plugin the push switches on may make it */
				if (!$lid && (!$p || !$plugins_on)) {
					$missing = $p ? $p['title'] : 'page #'.$id;
					continue;
				}
				if (!$lid)
					$later = $p['title'];
				if ($lid)
					$want = base64_encode((string)$lid);
				$ids[$n] = array($p['type'], $p['path']);
			}
			if ($want === $lv || ($lv === null && $want === base64_encode('')))     // not there = empty, for WordPress
				continue;
			$diff[] = array('option' => $n, 'label' => $label, 'live' => wordpress_push_show($n, $lv, (array)$l['posts']),
			                'copy' => wordpress_push_show($n, $cv, (array)$s['posts']));
			$set[$n] = $cv;
			if ($base === null || !array_key_exists($n, $base))
				$unknown = true;
			else {
				if ($base[$n] !== null && $lv !== $base[$n])
					$live_changed = true;
				if ($cv !== $base[$n])
					$copy_changed = true;
			}
		}
		if (!$diff && $missing === '')
			continue;
		$why = $missing !== '' ? 'missing_page' : ($unknown ? 'no_base' : ($live_changed ? ($copy_changed ? 'both' : 'live_changed') : (!empty($g['ask']) ? 'ask' : '')));
		$settings[] = array('key' => $gk, 'name' => $g['name'], 'diff' => $diff, 'set' => $set, 'ids' => $ids, 'needs' => $needs,
		                    'default' => $why === '', 'why' => $why, 'missing' => $missing, 'later' => $later, 'flush' => !empty($g['flush']),
		                    'value' => $gk === 'wc_visible' ? (string)base64_decode((string)($s['opts']['woocommerce_coming_soon'] ?? '')) : '');
	}

	$j = wordpress_job_latest($live['id'], 'push');
	$jp = $j ? json_decode($j['steps'], true) : null;
	if ($j && (int)($jp['copy'] ?? 0) !== (int)$copy['id'] && !in_array($j['status'], array('queued', 'running'), true))
		$j = null;
	return result_ok('', array('copy' => wordpress_public($copy), 'live' => wordpress_public($live), 'items' => $items, 'switches' => $sw,
		'settings' => $full ? $settings : array_map(function ($x) { return array_diff_key($x, array('set' => 1, 'ids' => 1)); }, $settings),
		'uploads' => array('files' => (int)($f['uploads']['files'] ?? 0), 'bytes' => (int)($f['uploads']['bytes'] ?? 0)),
		'versions' => array('copy' => $s['version'], 'live' => $l['version']), 'live_theme' => $l['stylesheet'],
		'php' => array('copy' => (string)wp_site_php_version($ini, $copy['domain']), 'live' => (string)wp_site_php_version($ini, $live['domain'])),
		'content' => array_map(function ($p) use ($l) {
			return array('type' => $p['type'], 'title' => $p['title'], 'new' => $p['path'] !== '' && !isset($l['found'][$p['type'].':'.$p['path']]));
		}, (array)$s['content']), 'since' => (int)$c['created'],
		'pushed' => (int)$c['pushed'], 'job' => $j ? wordpress_job_public($j, true) : null));
}

/* Check, then push in the background ($o: items, switches, settings (keys of the preview), uploads). */
function wordpress_push_start(array $copy, array $o, $by = '') {
	list($live, $c, $err) = wordpress_push_pair($copy);
	if (!$live)
		return result_error($err);
	if (wordpress_unit_active($copy['id']))
		return result_error($copy['domain'].' is being updated right now. Push it once that is done.');
	$clean = function ($list, $re) {
		$out = array();
		foreach (is_array($list) ? $list : array() as $k)
			if (is_string($k) && strlen($k) <= 300 && strpos($k, "\0") === false && preg_match($re, $k))
				$out[] = $k;
		return array_slice(array_values(array_unique($out)), 0, 500);
	};
	$items = $clean($o['items'] ?? array(), '~^(core|languages|(plugin|theme|mu):[^/]+)$~');
	$switches = $clean($o['switches'] ?? array(), '~^((on|off):[^/]+(/[^/]+)?|use:[^/]+)$~');
	$settings = $clean($o['settings'] ?? array(), '~^[a-z_]{1,30}$~');
	$uploads = !empty($o['uploads']);
	if (!$items && !$switches && !$uploads && !$settings)
		return result_error('Choose what to push.');
	$r = wordpress_job_start($live, array('copy' => (int)$copy['id'], 'items' => $items, 'switches' => $switches, 'settings' => $settings,
	                                      'uploads' => $uploads), $by, 'push');
	if ($r['ok'])
		$r['message'] = 'Pushing '.$copy['domain'].' to '.wordpress_site_label($live).'.';
	return $r;
}

/*
 * The push (the live site's job unit, root; see the top of this file). $o:
 * copy (its site id), items, switches, settings, uploads, as chosen from the preview;
 * what no longer differs is left out. data: backup (the database file),
 * files_backup, output, rolled_back.
 */
function wordpress_push_run(array $live, array $o) {
	global $db;
	$user = $live['user'];
	$home = '/home/'.$user.'/';
	$site = wordpress_site_label($live);
	$copy = wordpress_find((int)($o['copy'] ?? 0));
	if (!$copy || $copy['user'] !== $user)
		return result_error('Nothing was pushed: the staging copy is no longer in the list.');
	list($pl, $c, $err) = wordpress_push_pair($copy);
	if (!$pl || (int)$pl['id'] !== (int)$live['id'])
		return result_error('Nothing was pushed: '.($err !== '' ? $err : 'this is not the copy\'s live site.'));
	$p = wordpress_push_preview($copy, true);
	if (!$p['ok'])
		return result_error('Nothing was pushed: '.$p['error']);
	$d = $p['data'];
	$have = array_column($d['items'], null, 'key');
	$sw_have = array_column($d['switches'], null, 'key');
	$items = array_values(array_intersect(array_keys($have), (array)($o['items'] ?? array())));
	$log = $warn = array();
	$switches = array();
	foreach (array_intersect(array_keys($sw_have), (array)($o['switches'] ?? array())) as $k) {
		$x = $sw_have[$k];
		if ($x['needs'] !== '' && !in_array($x['needs'], $items, true))
			$warn[] = ($x['op'] === 'use' ? 'The theme ' : '').$x['name'].' was not switched on: its files were not pushed.';
		else
			$switches[] = $x;
	}
	$sets = array();
	foreach ($d['settings'] as $g)
		if (in_array($g['key'], (array)($o['settings'] ?? array()), true)) {
			if ($g['why'] === 'missing_page')
				$warn[] = $g['name'].' was left out: the page "'.$g['missing'].'" is not on the live site.';
			elseif ($g['needs'] !== '' && !in_array($g['needs'], array_column($switches, 'key'), true))
				$warn[] = $g['name'].' was left out: its plugin is not switched on on the live site.';
			else
				$sets[] = $g;
		}
	$uploads = !empty($o['uploads']) && $d['uploads']['files'] > 0;
	if (!$items && !$switches && !$uploads && !$sets)
		return result_ok('Nothing to push: the live site has these changes already.', array('nothing' => true), $warn);

	$path = ' --path='.escapeshellarg(wordpress_docroot($live));
	$cli = function ($cmd, $secs = 300) use ($live, $path, &$log) {
		$r = wordpress_cli($live, $cmd.$path, $secs);
		$log[] = '$ wp '.$cmd."\n".$r['out'];
		return $r;
	};
	$out = function (array $more = array()) use (&$log) { return array('output' => trim(implode("\n\n", $log))) + $more; };
	/* the home page as it is: after the push it must open as well (an error page the
	   check lets through, e.g. a 403 or 404 from the web server, counts as broken
	   when the page opened before) */
	list($was_ok, $was_code, ) = wordpress_site_check($live);
	$check = function () use ($live, $was_code) {
		list($ok, $code, $why) = wordpress_site_check($live);
		if ($ok && $was_code >= 200 && $was_code < 400 && $code >= 400)
			return array(false, 'it answers with an error ('.$code.')');
		return array($ok, $why);
	};
	$backup = wordpress_db_backup($live, $err);
	if ($backup === '')
		return result_error('Nothing was pushed: the database backup failed first. '.$err);
	$saved = ' The live database was saved first to ~/'.substr($backup, strlen($home)).'.';
	$log[] = '# the live database saved to ~/'.substr($backup, strlen($home));

	/* plugins about to be deleted: switched off first, as WordPress does */
	$off_first = array();
	foreach ($items as $k)
		if ($have[$k]['kind'] === 'plugin' && $have[$k]['state'] === 'removed' && !empty($have[$k]['live_active'])
		    && $cli('plugin deactivate '.escapeshellarg($have[$k]['cli']))['rc'] === 0)
			$off_first[] = $have[$k]['cli'];

	/* the files; the ones replaced or deleted are kept */
	$bk = 'wp-backups/'.wordpress_backup_base($live).'-push-'.date('Ymd-His');
	$files = $items || $uploads;
	if ($files) {
		$f = files_run($user, array('verb' => 'wppush', 'from' => substr(rtrim(wordpress_docroot($copy), '/'), strlen($home)),
		                            'to' => substr(rtrim(wordpress_docroot($live), '/'), strlen($home)), 'items' => $items,
		                            'uploads' => $uploads, 'backup' => $bk), null, null, 3000);
		if (empty($f['ok'])) {
			foreach ($off_first as $n)
				$cli('plugin activate '.escapeshellarg($n));
			return result_error('Nothing was pushed: '.files_result($f)['error'].$saved, $out(array('backup' => $backup)));
		}
		$log[] = '# files: '.$f['created'].' new, '.$f['replaced'].' replaced, '.$f['deleted'].' deleted'
		       .($uploads ? ', '.$f['uploads'].' uploads added' : '').'; the replaced and deleted ones are kept in ~/'.$bk;
	}

	/* the plugins and theme switched as on the copy */
	$done_sw = array();
	foreach ($switches as $x) {
		$r = $x['op'] === 'use' ? $cli('theme activate '.escapeshellarg($x['slug']))
		   : $cli('plugin '.($x['op'] === 'on' ? 'activate' : 'deactivate').' '.escapeshellarg($x['slug']));
		if ($r['rc'] === 0)
			$done_sw[] = $x;
		else
			$warn[] = ($x['op'] === 'use' ? 'The theme '.$x['name'].' could not be chosen' : $x['name'].' could not be switched '.$x['op']).': '.wp_cli_error($r);
	}

	/* the database, updated as after an update (never overwritten) */
	$r = $cli('core update-db', 600);
	if ($r['rc'] !== 0)
		$warn[] = 'WordPress could not update its database: '.wp_cli_error($r);
	if ($cli('plugin is-active woocommerce', 60)['rc'] === 0) {
		$r = $cli('wc update', 1200);
		if ($r['rc'] !== 0 && stripos($r['out'], 'is not a registered') === false)
			$warn[] = 'WooCommerce could not update its database: '.wp_cli_error($r).' Open WooCommerce in the dashboard: it offers the update there.';
	}

	/* the settings chosen (a group whose plugin did not get switched on stays out) */
	$set_old = $set_done = array();
	if ($sets) {
		$on_now = array_column($done_sw, 'key');
		$groups = $ids = array();
		$flush = $wc = false;
		foreach ($sets as $g) {
			if ($g['needs'] !== '' && !in_array($g['needs'], $on_now, true)) {
				$warn[] = $g['name'].' was left out: its plugin could not be switched on.';
				continue;
			}
			$groups[$g['key']] = $g['set'];
			$ids += $g['ids'];
			$flush = $flush || $g['flush'];
			$wc = $wc || $g['key'] === 'wc_setup';
		}
		if ($groups) {
			list($set_old, $left, $e) = wordpress_push_settings_write($live, $groups, $ids, $flush, $wc);
			if ($e !== '')
				$warn[] = 'The settings could not be written: '.$e;
			$byk = array_column($sets, null, 'key');
			foreach ($groups as $k => $x)
				if ($e === '' && in_array($k, $left, true))
					$warn[] = $byk[$k]['name'].' was left out: the page it points at is not on the live site.';
				elseif ($e === '')
					$set_done[] = $byk[$k];
			if ($set_done)
				$log[] = '# settings written: '.implode(', ', array_column($set_done, 'name'));
		}
	}

	/* does the live site still open? */
	list($purged, ) = wordpress_purge_caches($live);
	list($ok, $why) = $check();
	if ($was_ok && !$ok) {
		$back = array();
		if ($set_old && wordpress_push_settings_write($live, array('undo' => $set_old), array(), true)[2] === '')
			$back[] = 'the settings';
		foreach (array_reverse($done_sw) as $x)
			if ($x['op'] === 'use')
				$cli('theme activate '.escapeshellarg($d['live_theme']).' --skip-plugins --skip-themes');
			else
				$cli('plugin '.($x['op'] === 'on' ? 'deactivate' : 'activate').' '.escapeshellarg($x['slug']).($x['op'] === 'on' ? ' --skip-plugins --skip-themes' : ''));
		if ($done_sw)
			$back[] = 'the plugin and theme switches';
		$undo_err = '';
		if ($files) {
			$u = files_run($user, array('verb' => 'wppushundo', 'to' => substr(rtrim(wordpress_docroot($live), '/'), strlen($home)), 'backup' => $bk), null, null, 1800);
			if (empty($u['ok']))
				$undo_err = files_result($u)['error'];
			else {
				$back[] = 'the files';
				files_delete($user, array($bk));        // they are back in place
			}
		}
		foreach ($off_first as $n)
			$cli('plugin activate '.escapeshellarg($n));
		wordpress_purge_caches($live);
		list($ok2, $why2) = $check();
		$log[] = '# the home page failed after the push ('.$why.'): put back '.implode(' and ', $back);
		$msg = 'After the push '.$site.' no longer opened ('.$why.')'.($back ? ', so '.implode(' and ', $back).' were put back as they were' : '').'.'
		     .($undo_err !== '' ? ' '.$undo_err : '')
		     .($ok2 ? ' The site opens again.' : ' The site still does not open ('.$why2.'): look at it now.')
		     .' Try the change on the staging copy again.'.$saved;
		return result_ok($msg, $out(array('backup' => $backup, 'rolled_back' => true)), $warn);
	}

	$db->exec('UPDATE wordpress_copies SET pushed = '.time().' WHERE site_id = '.(int)$copy['id']);
	if ($files)
		wordpress_push_prune($live);
	if ($set_done) {
		$values = array();
		foreach ($set_done as $g)
			$values += $g['set'];
		wordpress_push_rebase($copy, $values);
	}
	wordpress_info($live, true);
	$what = array();
	foreach ($items as $k)
		$what[] = $have[$k]['state'] === 'removed' ? $have[$k]['name'].' (deleted)' : $have[$k]['name'].($have[$k]['to'] !== '' ? ' '.$have[$k]['to'] : '');
	foreach ($done_sw as $x)
		$what[] = ($x['op'] === 'use' ? 'the theme '.$x['name'] : $x['name'].' switched '.$x['op']);
	foreach ($set_done as $g)
		$what[] = $g['name'];
	if ($uploads)
		$what[] = $f['uploads'].' new '.($f['uploads'] === 1 ? 'upload' : 'uploads');
	$list = count($what) > 8 ? implode(', ', array_slice($what, 0, 8)).' and '.(count($what) - 8).' more' : implode(', ', $what);
	log_debug('[wp] pushed '.$copy['domain'].' to '.$site.': '.implode(', ', array_merge($items, array_column($switches, 'key'), array_column($set_done, 'key'))).($uploads ? ', uploads' : ''));
	plugin_hook('wordpress.pushed', array('account' => $user, 'domain' => $live['domain'], 'path' => (string)$live['path'], 'from' => $copy['domain'],
	                                      'items' => $items, 'switches' => array_column($done_sw, 'key'),
	                                      'settings' => array_column($set_done, 'key'), 'uploads' => $uploads));
	return result_ok('Pushed to '.$site.': '.$list.'.'.$saved.($files ? ' The files it replaced or deleted are kept in ~/'.$bk.'.' : '')
	                 .($purged ? ' Emptied: '.implode(', ', $purged).'.' : '')
	                 .(!$was_ok ? ' (The home page did not open before the push either, so it was not checked afterwards.)' : ''),
	                 $out(array('backup' => $backup, 'files_backup' => $files ? '~/'.$bk : '', 'rolled_back' => false)), $warn);
}

/* Keep the 3 newest folders of replaced files of a site's pushes. */
function wordpress_push_prune(array $live) {
	$l = files_list($live['user'], 'wp-backups');
	if (!$l['ok'])
		return;
	$re = '/^'.preg_quote(wordpress_backup_base($live), '/').'-push-\d{8}-\d{6}$/';
	$names = array();
	foreach ($l['data']['entries'] as $e)
		if ($e['t'] === 'd' && preg_match($re, $e['n']))
			$names[] = $e['n'];
	rsort($names);
	$old = array_slice($names, 3);
	if ($old)
		files_delete($live['user'], array_map(function ($n) { return 'wp-backups/'.$n; }, $old));
}
