<?php
/*
 * Visitor stats: visitors, page views, requests and bandwidth per site and day,
 * read from the sites' web server logs (2083 Metrics > Visitors and bandwidth,
 * 2087 Accounts > Bandwidth Usage, GET /v1/stats, `reqad stats`).
 *
 * Collected by `reqad stats collect` (cron, every hour, root: the logs are
 * nginx:adm 0640). Each log is read from where the last run stopped (inode +
 * offset, the rotated copy after a rotation); a log seen for the first time
 * is read with its rotated copies of the last 60 days, so the page starts with
 * history. The vhost log also holds the site's error lines: they do not match
 * the access format and are skipped. Hosting Pro's bandwidth limits use these
 * numbers too (stats_account_bytes()): one count, the same figure everywhere.
 *
 * Counted per site and day (stats_days): requests, bytes, answers 2xx-5xx, bot
 * requests and bytes, page views (GET 200/304 that is not a file, by people)
 * and their hours, and visitors = different address + browser that day. No
 * address is stored: a visitor is the first 16 hex of sha1(day secret, address,
 * browser), kept in stats_seen only while that day can still get lines
 * (today and yesterday), the day's secret with it. A month's visitors are its
 * days added up. Countries come from the address before it is dropped (DB-IP
 * Lite or GeoLite2 country database, stats_geo_*).
 *
 * Lists (stats_top, per site and day, then per month): pages, sites the
 * visits came from (host only), 404s (all requests), bots, files by bytes;
 * countries, browsers, systems and devices per visitor. Query strings are cut.
 * A finished day keeps its top 100 of each list; after 35 days its lists are
 * added to the month's (top 200 kept). History: 25 months.
 *
 * Every row carries the account that owned the site when it was counted, and
 * every read asks for the site's current owner: a domain that moves to another
 * account starts empty there. Rows with src 'awstats' are imported months of
 * an old server (Phase 8): a day that has both keeps Reqad's own numbers.
 *
 * Database: its own SQLite file (STATS_DB, root:reqad 0640) so the hourly
 * writes never lock the panel's reqad.db. Settings in reqad.db:
 * stats-enabled (default on), stats-geoip-update (monthly DB-IP Lite, default on).
 * Off, accounts no longer see their stats; the logs are still counted while
 * an add-on needs them (filter stats.needed: packages with a bandwidth limit).
 */

if (!defined('STATS_DB')) define('STATS_DB', '/var/lib/reqad/stats/stats.db');   // another file only for tests
define('STATS_GEO_DIR', '/var/lib/reqad/geoip');
define('STATS_KEEP_MONTHS', 25);
define('STATS_DAY_LISTS_DAYS', 35);

function stats_enabled() {
	return setting_get('stats-enabled', '1') !== '0';
}

/* Whether the logs are counted: the switch is on, or an add-on needs the
   numbers while it is off (Hosting Pro's bandwidth limits). */
function stats_counting() {
	return stats_enabled() || (bool)plugin_filter('stats.needed', false);
}

/* The stats database; null when it cannot be opened. Root makes it and its
   tables; everyone else opens it read-only (the panel runs as reqad). */
function stats_db() {
	static $sdb = null, $tried = false;
	if ($tried)
		return $sdb;
	$tried = true;
	$root = function_exists('posix_geteuid') && posix_geteuid() === 0;
	if (!$root) {
		if (!is_readable(STATS_DB))
			return null;
		try {
			$sdb = new SQLite3(STATS_DB, SQLITE3_OPEN_READONLY);
			$sdb->busyTimeout(5000);
		} catch (Exception $e) { $sdb = null; }
		return $sdb;
	}
	$dir = dirname(STATS_DB);
	if (!is_dir($dir)) {
		@mkdir($dir, 0750, true);
		@chgrp($dir, 'reqad');
	}
	$new = !is_file(STATS_DB);
	try {
		$sdb = new SQLite3(STATS_DB);
	} catch (Exception $e) { return $sdb = null; }
	$sdb->busyTimeout(15000);
	if ($new) {
		@chmod(STATS_DB, 0640);
		@chgrp(STATS_DB, 'reqad');
	}
	$sdb->exec("CREATE TABLE IF NOT EXISTS stats_days (
		site TEXT NOT NULL, user TEXT NOT NULL, day TEXT NOT NULL, src TEXT NOT NULL DEFAULT 'log',
		visitors INTEGER NOT NULL DEFAULT 0, pages INTEGER NOT NULL DEFAULT 0, requests INTEGER NOT NULL DEFAULT 0,
		bytes INTEGER NOT NULL DEFAULT 0, bot_requests INTEGER NOT NULL DEFAULT 0, bot_bytes INTEGER NOT NULL DEFAULT 0,
		s2 INTEGER NOT NULL DEFAULT 0, s3 INTEGER NOT NULL DEFAULT 0, s4 INTEGER NOT NULL DEFAULT 0, s5 INTEGER NOT NULL DEFAULT 0,
		hours TEXT NOT NULL DEFAULT '',
		PRIMARY KEY (site, user, day, src)) WITHOUT ROWID");
	$sdb->exec("CREATE TABLE IF NOT EXISTS stats_top (
		site TEXT NOT NULL, user TEXT NOT NULL, period TEXT NOT NULL, kind TEXT NOT NULL, item TEXT NOT NULL,
		n INTEGER NOT NULL DEFAULT 0, bytes INTEGER NOT NULL DEFAULT 0,
		PRIMARY KEY (site, user, period, kind, item)) WITHOUT ROWID");
	$sdb->exec("CREATE TABLE IF NOT EXISTS stats_seen (site TEXT NOT NULL, day TEXT NOT NULL, h TEXT NOT NULL,
		PRIMARY KEY (site, day, h)) WITHOUT ROWID");
	$sdb->exec("CREATE TABLE IF NOT EXISTS stats_logs (path TEXT PRIMARY KEY, inode INTEGER, offset INTEGER, updated INTEGER)");
	$sdb->exec("CREATE TABLE IF NOT EXISTS stats_meta (k TEXT PRIMARY KEY, v TEXT)");
	return $sdb;
}

function stats_meta_get($k, $default = '') {
	$sdb = stats_db();
	if (!$sdb) return $default;
	$st = $sdb->prepare('SELECT v FROM stats_meta WHERE k = :k');
	$st->bindValue(':k', $k, SQLITE3_TEXT);
	$r = $st->execute();
	$row = $r ? $r->fetchArray(SQLITE3_NUM) : false;
	return $row ? (string)$row[0] : $default;
}

function stats_meta_put($k, $v) {
	$st = stats_db()->prepare('INSERT OR REPLACE INTO stats_meta (k, v) VALUES (:k, :v)');
	$st->bindValue(':k', $k, SQLITE3_TEXT);
	$st->bindValue(':v', (string)$v, SQLITE3_TEXT);
	$st->execute();
}

/* domain => account of every site: main domains and addon domains / subdomains
   (aliases and www share their domain's vhost and log). */
function stats_sites() {
	global $db, $ini;
	$out = array();
	$r = $db->query('SELECT user, domain FROM accounts');
	while ($r && ($row = $r->fetchArray(SQLITE3_ASSOC)))
		if (valid_domain($row['domain']))
			$out[strtolower($row['domain'])] = $row['user'];
	foreach (addon_domain_list($ini) as $d => $a)
		$out[strtolower($d)] = $a['user'];
	return $out;
}

/* The sites of one account, its main domain first. */
function stats_account_sites($user) {
	global $db;
	$main = (string)$db->querySingle("SELECT domain FROM accounts WHERE user = '".$db->escapeString($user)."'");
	$list = array();
	foreach (stats_sites() as $d => $u)
		if ($u === $user && $d !== $main)
			$list[] = $d;
	sort($list);
	return $main !== '' ? array_merge(array($main), $list) : $list;
}

function stats_log_path($domain) {
	return str_replace('{domain}', $domain, server_logs()['site']['path']);
}

/* ---- what a request is ---------------------------------------------------- */

/* Named bots: the name shown => a pattern of its User-Agent. */
function stats_bot_names() {
	return array(
		'Googlebot' => 'Googlebot|Google-InspectionTool|GoogleOther|Storebot-Google|AdsBot-Google|Mediapartners-Google',
		'bingbot' => 'bingbot|BingPreview|msnbot', 'YandexBot' => 'Yandex', 'Baiduspider' => 'Baiduspider',
		'DuckDuckBot' => 'DuckDuck', 'Applebot' => 'Applebot', 'SeznamBot' => 'SeznamBot',
		'AhrefsBot' => 'AhrefsBot|AhrefsSiteAudit', 'SemrushBot' => 'SemrushBot|SiteAuditBot', 'MJ12bot' => 'MJ12bot',
		'DotBot' => 'DotBot', 'PetalBot' => 'PetalBot', 'DataForSeoBot' => 'DataForSeoBot', 'BLEXBot' => 'BLEXBot',
		'Bytespider' => 'Bytespider', 'GPTBot' => 'GPTBot', 'ChatGPT-User' => 'ChatGPT-User|OAI-SearchBot',
		'ClaudeBot' => 'ClaudeBot|Claude-User|Claude-SearchBot|anthropic-ai', 'PerplexityBot' => 'Perplexity',
		'meta-externalagent' => 'meta-externalagent|meta-externalfetcher', 'facebookexternalhit' => 'facebookexternalhit|facebookcatalog',
		'Amazonbot' => 'Amazonbot', 'CCBot' => 'CCBot', 'Twitterbot' => 'Twitterbot', 'LinkedInBot' => 'LinkedInBot',
		'Slackbot' => 'Slackbot', 'Discordbot' => 'Discordbot', 'TelegramBot' => 'TelegramBot', 'WhatsApp' => 'WhatsApp',
		'Uptime monitors' => 'UptimeRobot|Pingdom|StatusCake|Better ?Uptime|Site24x7|Uptime-Kuma|HetrixTools|Freshping|monitoring',
	);
}

/* The bots that collect pages for AI models or answers. */
function stats_ai_bots() {
	return array('GPTBot', 'ChatGPT-User', 'ClaudeBot', 'PerplexityBot', 'meta-externalagent', 'Bytespider', 'CCBot', 'Amazonbot');
}

/* array(bot name or '', browser, system, device) of a User-Agent; cached per run. */
function stats_ua($ua) {
	static $cache = array(), $re = null;
	if (isset($cache[$ua]))
		return $cache[$ua];
	if (count($cache) > 20000)
		$cache = array();
	if ($re === null) {
		$re = array();
		foreach (stats_bot_names() as $name => $p)
			$re[$name] = '/'.$p.'/i';
	}
	$bot = '';
	if ($ua === '' || $ua === '-')
		$bot = 'Other bots and scanners';
	else {
		foreach ($re as $name => $p)
			if (preg_match($p, $ua)) { $bot = $name; break; }
		if ($bot === '' && preg_match('/bot\b|bot\/|crawl|spider|slurp|scan|curl|wget|python|go-http|java\/|okhttp|libwww|httpclient|headless|phantom|lighthouse|check|fetch|preview|scrapy|axios|node-fetch|guzzle|php\/|ruby|perl|masscan|zgrab|nmap|nikto|^mozilla\/[45]\.0$/i', $ua))
			$bot = 'Other bots and scanners';
	}
	if ($bot !== '')
		return $cache[$ua] = array($bot, '', '', '');

	if (preg_match('/Edg(e|A|iOS)?\//', $ua))           $b = 'Edge';
	elseif (preg_match('/OPR\/|Opera|OPT\//', $ua))     $b = 'Opera';
	elseif (strpos($ua, 'SamsungBrowser') !== false)   $b = 'Samsung Internet';
	elseif (strpos($ua, 'YaBrowser') !== false)        $b = 'Yandex Browser';
	elseif (preg_match('/Firefox\/|FxiOS/', $ua))       $b = 'Firefox';
	elseif (preg_match('/CriOS|Chrome\//', $ua))        $b = 'Chrome';
	elseif (preg_match('/Version\/[\d.]+.*Safari/', $ua) || preg_match('/iPhone|iPad/', $ua)) $b = 'Safari';
	else                                               $b = 'Other';

	if (preg_match('/iPhone|iPad|iPod/', $ua))          $o = 'iOS';
	elseif (strpos($ua, 'Android') !== false)          $o = 'Android';
	elseif (strpos($ua, 'Windows') !== false)          $o = 'Windows';
	elseif (strpos($ua, 'CrOS') !== false)             $o = 'ChromeOS';
	elseif (strpos($ua, 'Mac OS X') !== false || strpos($ua, 'Macintosh') !== false) $o = 'macOS';
	elseif (preg_match('/Linux|X11/', $ua))             $o = 'Linux';
	else                                               $o = 'Other';

	if (preg_match('/iPad|Tablet|Kindle|Silk/', $ua) || ($o === 'Android' && strpos($ua, 'Mobile') === false)) $d = 'Tablet';
	elseif (preg_match('/Mobi|iPhone|iPod|Android/', $ua)) $d = 'Phone';
	else $d = 'Computer';
	return $cache[$ua] = array('', $b, $o, $d);
}

/* A file rather than a page: images, styles, scripts, fonts, media, downloads. */
function stats_is_file($path) {
	if (preg_match('#\.(css|js|mjs|map|json|xml|txt|png|jpe?g|gif|webp|avif|svg|ico|bmp|tiff?|woff2?|ttf|otf|eot|mp4|webm|ogg|ogv|mp3|wav|m4a|mov|avi|pdf|zip|gz|tgz|rar|7z|tar|docx?|xlsx?|pptx?|csv|apk|exe|dmg|iso|wasm|ya?ml|sql|env|ini|conf|cfg|log|bak|old|swp|sh|py|rb|pl|asp|aspx|jsp|cgi|key|pem|toml|lock|md|rst)$#i', $path))
		return true;
	return (bool)preg_match('#^/(wp-json/|wp-admin/admin-ajax\.php|wp-cron\.php|xmlrpc\.php|\.well-known/|reqad-error/|feed/?$|.*/feed/?$|favicon|robots\.txt|sitemap)#i', $path);
}

/* A site's back end, not a page people read: WordPress's admin and login. */
function stats_not_page($path) {
	return (bool)preg_match('#^/(wp-admin|wp-login\.php|wp-includes/|wp-content/|wp-signup\.php|wp-trackback\.php|wp-activate\.php|xmlrpc\.php)#i', $path);
}

/* Addresses only scanners ask for (secrets, version control, admin tools):
   whoever asks for one is a scanner for the rest of the day. */
function stats_probe_path($path) {
	return (bool)preg_match('#^/(wp-load\.php|wp-config|\.?env\b|\.git|\.aws|\.ssh|\.svn|\.hg|\.DS_Store|phpmyadmin|pma/|cgi-bin/|vendor/|server-status|actuator|HNAP1|boaform|credentials|docker-compose|config\.(ya?ml|json|php|js)|database\.sql|backup\.)#i', $path);
}

/* Addresses that scanners try on every site: shown as "scanner" in the 404 list. */
function stats_scanner_path($path) {
	return (bool)preg_match('#(^/(wp-login\.php|xmlrpc\.php|wp-admin|wp-includes|wp-content/plugins/[^/]+/readme|\.env|\.git|\.aws|\.ssh|\.DS_Store|phpmyadmin|pma|admin|administrator|vendor/phpunit|cgi-bin|boaform|HNAP1|actuator|solr|owa|autodiscover|config\.|server-status|info\.php|phpinfo|shell|wp-config|backup|old|bak|test\.php)|\.(php\d?|asp|aspx|jsp|cgi|env|sql|bak|old|swp)$)#i', $path);
}

/* Search engines and AI assistants among the sites visits came from. */
function stats_ref_kind($host) {
	if (preg_match('/(^|\.)(google\.[a-z.]+|bing\.com|duckduckgo\.com|yahoo\.com|yandex\.[a-z]+|ecosia\.org|baidu\.com|search\.brave\.com|qwant\.com|startpage\.com|seznam\.cz)$/', $host) && strpos($host, 'mail.google') === false)
		return 'search';
	if (preg_match('/(^|\.)(chatgpt\.com|chat\.openai\.com|perplexity\.ai|claude\.ai|gemini\.google\.com|copilot\.microsoft\.com|chat\.deepseek\.com|you\.com|phind\.com)$/', $host))
		return 'ai';
	return '';
}

/* ---- country database (MaxMind DB format: DB-IP Lite or GeoLite2) ------- */

/* array(path, source label, date) of the database in use, or null. */
function stats_geo_file() {
	$dbip = STATS_GEO_DIR.'/dbip-country-lite.mmdb';
	if (is_file($dbip))
		return array($dbip, 'DB-IP Lite', date('Y-m-d', filemtime($dbip)));
	foreach (array('/usr/share/GeoIP/GeoLite2-Country.mmdb', '/usr/share/GeoIP/dbip-country-lite.mmdb') as $f)
		if (is_file($f))
			return array($f, strpos($f, 'dbip') !== false ? 'DB-IP Lite' : 'GeoLite2', date('Y-m-d', filemtime($f)));
	return null;
}

/* The database read into memory (a country database is 4-10 MB). */
function stats_geo_open($path) {
	static $open = array();
	if (isset($open[$path]))
		return $open[$path];
	$data = @file_get_contents($path);
	$mark = "\xAB\xCD\xEFMaxMind.com";
	if ($data === false || ($p = strrpos($data, $mark)) === false)
		return $open[$path] = null;
	$g = array('data' => $data, 'base' => 0);
	list($meta) = stats_geo_decode($g, $p + strlen($mark), $p + strlen($mark));
	if (!is_array($meta) || empty($meta['node_count']) || !in_array((int)($meta['record_size'] ?? 0), array(24, 28, 32), true))
		return $open[$path] = null;
	$g['meta'] = $meta;
	$g['nodes'] = (int)$meta['node_count'];
	$g['rs'] = (int)$meta['record_size'];
	$g['base'] = $g['nodes'] * $g['rs'] / 4 + 16;
	/* IPv4 addresses sit under 96 zero bits in an IPv6 tree */
	$n = 0;
	if ((int)$meta['ip_version'] === 6)
		for ($i = 0; $i < 96 && $n < $g['nodes']; $i++)
			$n = stats_geo_record($g, $n, 0);
	$g['v4'] = $n;
	return $open[$path] = $g;
}

function stats_geo_record($g, $node, $bit) {
	$d = $g['data'];
	switch ($g['rs']) {
	case 24:
		$o = $node * 6 + $bit * 3;
		return (ord($d[$o]) << 16) | (ord($d[$o + 1]) << 8) | ord($d[$o + 2]);
	case 28:
		$o = $node * 7;
		if ($bit === 0)
			return ((ord($d[$o + 3]) & 0xF0) << 20) | (ord($d[$o]) << 16) | (ord($d[$o + 1]) << 8) | ord($d[$o + 2]);
		return ((ord($d[$o + 3]) & 0x0F) << 24) | (ord($d[$o + 4]) << 16) | (ord($d[$o + 5]) << 8) | ord($d[$o + 6]);
	default:
		$o = $node * 8 + $bit * 4;
		return unpack('N', substr($d, $o, 4))[1];
	}
}

/* Decode the value at $pos: array(value, next position). $base: start of the
   data section (pointers count from it). */
function stats_geo_decode($g, $pos, $base = null) {
	$d = $g['data'];
	$base = $base === null ? $g['base'] : $base;
	$c = ord($d[$pos++]);
	$type = $c >> 5;
	if ($type === 1) {                          // pointer
		$ss = ($c >> 3) & 3; $v = $c & 7;
		if ($ss === 0)      { $p = ($v << 8) | ord($d[$pos]); $pos += 1; }
		elseif ($ss === 1)  { $p = (($v << 16) | (ord($d[$pos]) << 8) | ord($d[$pos + 1])) + 2048; $pos += 2; }
		elseif ($ss === 2)  { $p = (($v << 24) | (ord($d[$pos]) << 16) | (ord($d[$pos + 1]) << 8) | ord($d[$pos + 2])) + 526336; $pos += 3; }
		else                { $p = unpack('N', substr($d, $pos, 4))[1]; $pos += 4; }
		list($val) = stats_geo_decode($g, $base + $p, $base);
		return array($val, $pos);
	}
	if ($type === 0) { $type = 7 + ord($d[$pos++]); }
	$size = $c & 0x1f;
	if ($size === 29)      { $size = 29 + ord($d[$pos++]); }
	elseif ($size === 30)  { $size = 285 + ((ord($d[$pos]) << 8) | ord($d[$pos + 1])); $pos += 2; }
	elseif ($size === 31)  { $size = 65821 + ((ord($d[$pos]) << 16) | (ord($d[$pos + 1]) << 8) | ord($d[$pos + 2])); $pos += 3; }
	switch ($type) {
	case 2: case 4:                             // string, bytes
		return array(substr($d, $pos, $size), $pos + $size);
	case 3:                                     // double
		return array(unpack('E', substr($d, $pos, 8))[1], $pos + 8);
	case 15:                                    // float
		return array(unpack('G', substr($d, $pos, 4))[1], $pos + 4);
	case 5: case 6: case 8: case 9: case 10:    // unsigned / signed integers
		$v = 0;
		for ($i = 0; $i < $size; $i++) $v = $v * 256 + ord($d[$pos + $i]);
		return array($v, $pos + $size);
	case 7:                                     // map
		$m = array();
		for ($i = 0; $i < $size; $i++) {
			list($k, $pos) = stats_geo_decode($g, $pos, $base);
			list($v, $pos) = stats_geo_decode($g, $pos, $base);
			$m[$k] = $v;
		}
		return array($m, $pos);
	case 11:                                    // array
		$a = array();
		for ($i = 0; $i < $size; $i++) { list($v, $pos) = stats_geo_decode($g, $pos, $base); $a[] = $v; }
		return array($a, $pos);
	case 14:                                    // boolean
		return array($size !== 0, $pos);
	default:
		return array(null, $pos + $size);
	}
}

/* The ISO country code of an address, '' when unknown. */
function stats_geo_country($ip) {
	static $g = false, $cache = array();
	if ($g === false) {
		$f = stats_geo_file();
		$g = $f ? stats_geo_open($f[0]) : null;
	}
	if (!$g)
		return '';
	if (isset($cache[$ip]))
		return $cache[$ip];
	if (count($cache) > 50000)
		$cache = array();
	$bin = @inet_pton($ip);
	if ($bin === false)
		return $cache[$ip] = '';
	$bits = strlen($bin) * 8;
	$n = $bits === 32 ? $g['v4'] : 0;
	if ($bits === 128 && (int)$g['meta']['ip_version'] === 4)
		return $cache[$ip] = '';
	for ($i = 0; $i < $bits && $n < $g['nodes']; $i++)
		$n = stats_geo_record($g, $n, (ord($bin[$i >> 3]) >> (7 - ($i & 7))) & 1);
	if ($n <= $g['nodes'])
		return $cache[$ip] = '';
	list($rec) = stats_geo_decode($g, $g['base'] + ($n - $g['nodes']) - 16);
	$cc = is_array($rec) ? (string)($rec['country']['iso_code'] ?? $rec['registered_country']['iso_code'] ?? '') : '';
	return $cache[$ip] = preg_match('/^[A-Z]{2}$/', $cc) ? $cc : '';
}

/* English name of a country code. */
function stats_country_name($cc) {
	if (class_exists('Locale')) {
		$n = Locale::getDisplayRegion('-'.$cc, 'en');
		if ($n !== '' && $n !== $cc) return $n;
	}
	return $cc;
}

/*
 * Download this month's DB-IP Lite country database (CC BY 4.0, updated at the
 * start of each month), check it answers, and put it in place. Last month's
 * file when this month's is not out yet. $force: also when the one we have is
 * from this month.
 */
function stats_geo_update($force = false) {
	$dst = STATS_GEO_DIR.'/dbip-country-lite.mmdb';
	if (!$force && is_file($dst) && date('Y-m', filemtime($dst)) === date('Y-m'))
		return result_ok('The country database is from this month already.', array('file' => $dst));
	if (!is_dir(STATS_GEO_DIR))
		@mkdir(STATS_GEO_DIR, 0755, true);
	$tmp = STATS_GEO_DIR.'/.dbip-'.getmypid().'.gz';
	$got = '';
	foreach (array(date('Y-m'), date('Y-m', strtotime(date('Y-m-01').' -1 month'))) as $m) {
		$url = 'https://download.db-ip.com/free/dbip-country-lite-'.$m.'.mmdb.gz';
		exec('curl -fsS --max-time 120 -o '.escapeshellarg($tmp).' '.escapeshellarg($url).' 2>&1', $out, $rc);
		if ($rc === 0 && filesize($tmp) > 100000) { $got = $m; break; }
	}
	if ($got === '') {
		@unlink($tmp);
		return result_error('Could not download the DB-IP country database: '.trim(implode(' ', $out ?? array())));
	}
	$raw = @gzdecode((string)file_get_contents($tmp));
	@unlink($tmp);
	$new = STATS_GEO_DIR.'/.dbip-'.getmypid().'.mmdb';
	if ($raw === false || strlen($raw) < 1000000 || file_put_contents($new, $raw) === false)
		return result_error('The downloaded country database could not be unpacked.');
	$g = stats_geo_open($new);
	if (!$g || stripos((string)($g['meta']['database_type'] ?? ''), 'country') === false) {
		@unlink($new);
		return result_error('The downloaded file is not a country database.');
	}
	chmod($new, 0644);
	/* its date is the day DB-IP built it (what the pages show) */
	if (!empty($g['meta']['build_epoch']))
		touch($new, (int)$g['meta']['build_epoch']);
	rename($new, $dst);
	route_log("stats: DB-IP country database $got");
	return result_ok("Country database updated (DB-IP Lite, $got).", array('file' => $dst, 'month' => $got));
}

/* ---- collecting ------------------------------------------------------------ */

/*
 * Read what the sites' logs got since the last run. Returns stats
 * (logs, lines, requests, seconds). $only: one domain (testing).
 */
function stats_collect($only = '') {
	$t0 = microtime(true);
	if (!stats_counting())
		return result_ok('Visitor stats are off on this server.', array('off' => true));
	$lock = @fopen('/run/reqad-stats.lock', 'c');
	if (!$lock || !flock($lock, LOCK_EX | LOCK_NB))
		return result_error('Another count is still running.', array('busy' => true));
	$sdb = stats_db();
	if (!$sdb)
		return result_error('Could not open '.STATS_DB.'.');

	/* no DB-IP file yet (a new server): get it now rather than at the next 04:41 */
	if ($only === '' && setting_get('stats-geoip-update', '1') !== '0' && !is_file(STATS_GEO_DIR.'/dbip-country-lite.mmdb')
	    && (int)stats_meta_get('geo-tried', '0') < time() - 86400) {
		stats_meta_put('geo-tried', (string)time());
		stats_geo_update(false);
	}
	$state = array();
	$r = $sdb->query('SELECT path, inode, offset FROM stats_logs');
	while ($r && ($row = $r->fetchArray(SQLITE3_ASSOC)))
		$state[$row['path']] = $row;
	$own = array_flip(wp_sec_server_ips());
	$since = time() - 60 * 86400;
	$sum = array('logs' => 0, 'lines' => 0, 'requests' => 0, 'missing' => 0);

	foreach (stats_sites() as $site => $user) {
		if ($only !== '' && $site !== $only)
			continue;
		$path = stats_log_path($site);
		clearstatcache(true, $path);
		$st = @stat($path);
		if (!$st) { $sum['missing']++; continue; }
		$acc = stats_acc_new($site, $user, $own);
		$old = $state[$path] ?? null;
		$from = 0;
		if ($old && (int)$old['inode'] === (int)$st['ino']) {
			$from = (int)$old['offset'] <= $st['size'] ? (int)$old['offset'] : 0;
		} else {
			/* rotated since (the rest of the old inode is in <log>-<date>), or new to
			   us: its rotated copies of the last 60 days, oldest first */
			$rot = array();
			foreach (glob($path.'-*') ?: array() as $f)
				if (($rs = @stat($f)) && ($old ? (int)$rs['ino'] === (int)$old['inode'] : $rs['mtime'] >= $since))
					$rot[$f] = $rs['mtime'];
			asort($rot);
			foreach (array_keys($rot) as $f) {
				$gz = substr($f, -3) === '.gz';
				$fh = $gz ? @gzopen($f, 'r') : @fopen($f, 'r');
				if (!$fh) continue;
				if ($old) $gz ? gzseek($fh, (int)$old['offset']) : fseek($fh, (int)$old['offset']);
				stats_read($fh, $acc, $gz);
				$gz ? gzclose($fh) : fclose($fh);
			}
		}
		if (($fh = @fopen($path, 'r'))) {
			fseek($fh, $from);
			stats_read($fh, $acc, false);
			$pos = ftell($fh);
			fclose($fh);
			$sdb->exec('BEGIN IMMEDIATE');
			stats_acc_write($sdb, $acc);
			$s = $sdb->prepare('INSERT OR REPLACE INTO stats_logs (path, inode, offset, updated) VALUES (:p, :i, :o, :t)');
			$s->bindValue(':p', $path, SQLITE3_TEXT);
			$s->bindValue(':i', (int)$st['ino'], SQLITE3_INTEGER);
			$s->bindValue(':o', (int)$pos, SQLITE3_INTEGER);
			$s->bindValue(':t', time(), SQLITE3_INTEGER);
			$s->execute();
			$sdb->exec('COMMIT');
		}
		$sum['logs']++;
		$sum['lines'] += $acc['lines'];
		$sum['requests'] += $acc['requests'];
	}
	if ($only === '')
		stats_maintain($sdb, array_keys($state));
	stats_meta_put('collected', (string)time());
	flock($lock, LOCK_UN);
	$sum['seconds'] = round(microtime(true) - $t0, 1);
	return result_ok('Counted '.$sum['requests'].' requests in '.$sum['logs'].' logs ('.$sum['seconds'].' s).', $sum);
}

function stats_acc_new($site, $user, $own) {
	return array('site' => $site, 'user' => $user, 'own' => $own, 'days' => array(), 'top' => array(), 'probe' => array(),
	             'seen' => array(), 'new' => array(), 'lines' => 0, 'requests' => 0);
}

/* Add the log lines in $fh (from where it stands) to $acc. */
function stats_read($fh, array &$acc, $gz) {
	static $mon = array('Jan' => '01', 'Feb' => '02', 'Mar' => '03', 'Apr' => '04', 'May' => '05', 'Jun' => '06',
	                    'Jul' => '07', 'Aug' => '08', 'Sep' => '09', 'Oct' => '10', 'Nov' => '11', 'Dec' => '12');
	$re = '#^(\S+) \S+ \S+ \[(\d{2})/([A-Z][a-z]{2})/(\d{4}):(\d{2}):\d{2}:\d{2} [^\]]*\] "([A-Z]{3,8}) ([^ "]*)[^"]*" (\d{3}) (\d+|-) "([^"]*)" "([^"]*)"#';
	$site = $acc['site'];
	$self = preg_replace('/^www\./', '', $site);
	while (($line = $gz ? gzgets($fh, 16384) : fgets($fh, 16384)) !== false) {
		$acc['lines']++;
		if (!preg_match($re, $line, $m) || !isset($mon[$m[3]])) {
			/* not "METHOD /path" (TLS or SSH on port 80, other junk): only its
			   bytes and answer, so the bandwidth is complete */
			if (preg_match('#^\S+ \S+ \S+ \[(\d{2})/([A-Z][a-z]{2})/(\d{4}):\d{2}:\d{2}:\d{2} [^\]]*\] "(?:[^"\\\\]|\\\\.)*" (\d{3}) (\d+|-) #', $line, $m)
			    && isset($mon[$m[2]])) {
				$day = $m[3].'-'.$mon[$m[2]].'-'.$m[1];
				if (!isset($acc['days'][$day]))
					$acc['days'][$day] = array('v' => 0, 'p' => 0, 'r' => 0, 'b' => 0, 'br' => 0, 'bb' => 0, 's2' => 0, 's3' => 0, 's4' => 0, 's5' => 0, 'h' => array_fill(0, 24, 0));
				$c = intdiv((int)$m[4], 100);
				$acc['requests']++;
				$acc['days'][$day]['r']++;
				$acc['days'][$day]['b'] += $m[5] === '-' ? 0 : (int)$m[5];
				if ($c >= 2 && $c <= 5) $acc['days'][$day]['s'.$c]++;
			}
			continue;
		}
		$day = $m[4].'-'.$mon[$m[3]].'-'.$m[2];
		$ip = $m[1]; $method = $m[6]; $code = (int)$m[8]; $bytes = $m[9] === '-' ? 0 : (int)$m[9];
		$path = $m[7];
		if (($q = strpos($path, '?')) !== false) $path = substr($path, 0, $q);
		if ($path === '' || $path[0] !== '/') $path = '/';
		elseif (isset($path[1]) && $path[1] === '/') $path = '/'.ltrim($path, '/');
		if (strlen($path) > 200) $path = substr($path, 0, 200);
		$ua = $m[11];
		$acc['requests']++;
		if (!isset($acc['days'][$day]))
			$acc['days'][$day] = array('v' => 0, 'p' => 0, 'r' => 0, 'b' => 0, 'br' => 0, 'bb' => 0, 's2' => 0, 's3' => 0, 's4' => 0, 's5' => 0, 'h' => array_fill(0, 24, 0));
		$D = &$acc['days'][$day];
		$D['r']++; $D['b'] += $bytes;
		$c = intdiv($code, 100);
		if ($c >= 2 && $c <= 5) $D['s'.$c]++;
		$T = &$acc['top'][$day];
		if ($bytes > 0) stats_top_add($T, 'file', $path, 1, $bytes);
		if ($code === 404) stats_top_add($T, '404', $path, 1, 0);

		$bot = isset($acc['own'][$ip]) ? 'This server' : stats_ua($ua)[0];
		if ($bot !== '') {
			$D['br']++; $D['bb'] += $bytes;
			stats_top_add($T, 'bot', $bot, 1, $bytes);
			unset($D, $T);
			continue;
		}
		/* a browser that asks for probe addresses is a scanner: none of its pages count, from then on */
		$who = $day.$ip."\n".$ua;
		if (isset($acc['probe'][$who])) { unset($D, $T); continue; }
		if (stats_probe_path($path) || ($c >= 4 && stats_scanner_path($path))) {
			$acc['probe'][$who] = 1;
			unset($D, $T);
			continue;
		}
		if (stats_not_page($path)) { unset($D, $T); continue; }
		if ($method !== 'GET' || ($code !== 200 && $code !== 304) || stats_is_file($path)) { unset($D, $T); continue; }
		$D['p']++;
		$D['h'][(int)$m[5]]++;
		stats_top_add($T, 'page', $path, 1, 0);
		$ref = $m[10];
		if ($ref !== '-' && $ref !== '' && ($host = strtolower((string)parse_url($ref, PHP_URL_HOST))) !== '') {
			$host = preg_replace('/^www\./', '', $host);
			if ($host !== $self && substr($host, -strlen('.'.$self)) !== '.'.$self)
				stats_top_add($T, 'ref', $host, 1, 0);
		}
		/* a visitor: new today? */
		$h = substr(sha1(stats_day_secret($day)."\n".$ip."\n".$ua), 0, 16);
		if (!isset($acc['seen'][$day]))
			$acc['seen'][$day] = stats_seen_load($site, $day);
		if (!isset($acc['seen'][$day][$h])) {
			$acc['seen'][$day][$h] = 1;
			$acc['new'][$day][] = $h;
			$D['v']++;
			$u = stats_ua($ua);
			stats_top_add($T, 'browser', $u[1], 1, 0);
			stats_top_add($T, 'os', $u[2], 1, 0);
			stats_top_add($T, 'device', $u[3], 1, 0);
			$cc = stats_geo_country($ip);
			if ($cc !== '') stats_top_add($T, 'country', $cc, 1, 0);
		}
		unset($D, $T);
	}
}

function stats_top_add(&$T, $kind, $item, $n, $bytes) {
	if (!isset($T[$kind][$item])) {
		/* keep memory bounded on a first run over weeks of a busy log */
		if (isset($T[$kind]) && count($T[$kind]) >= 20000) {
			uasort($T[$kind], function ($a, $b) { return $b[0] <=> $a[0]; });
			$T[$kind] = array_slice($T[$kind], 0, 10000, true);
		}
		$T[$kind][$item] = array(0, 0);
	}
	$T[$kind][$item][0] += $n;
	$T[$kind][$item][1] += $bytes;
}

/* The secret visitors of $day are hashed with; made the first time it is needed. */
function stats_day_secret($day) {
	static $s = array();
	if (!isset($s[$day])) {
		$v = stats_meta_get('salt-'.$day);
		if ($v === '') {
			$v = bin2hex(random_bytes(16));
			stats_meta_put('salt-'.$day, $v);
		}
		$s[$day] = $v;
	}
	return $s[$day];
}

function stats_seen_load($site, $day) {
	$out = array();
	$st = stats_db()->prepare('SELECT h FROM stats_seen WHERE site = :s AND day = :d');
	$st->bindValue(':s', $site, SQLITE3_TEXT);
	$st->bindValue(':d', $day, SQLITE3_TEXT);
	$r = $st->execute();
	while ($r && ($row = $r->fetchArray(SQLITE3_NUM)))
		$out[$row[0]] = 1;
	return $out;
}

/* Add one site's counts to the database (inside the caller's transaction). */
function stats_acc_write($sdb, array $acc) {
	$site = $acc['site']; $user = $acc['user'];
	$keep_seen = date('Y-m-d', strtotime('-1 day'));
	$get = $sdb->prepare("SELECT hours FROM stats_days WHERE site = :s AND user = :u AND day = :d AND src = 'log'");
	$up = $sdb->prepare("INSERT INTO stats_days (site, user, day, src, visitors, pages, requests, bytes, bot_requests, bot_bytes, s2, s3, s4, s5, hours)
		VALUES (:s, :u, :d, 'log', :v, :p, :r, :b, :br, :bb, :s2, :s3, :s4, :s5, :h)
		ON CONFLICT(site, user, day, src) DO UPDATE SET visitors = visitors + excluded.visitors, pages = pages + excluded.pages,
		requests = requests + excluded.requests, bytes = bytes + excluded.bytes, bot_requests = bot_requests + excluded.bot_requests,
		bot_bytes = bot_bytes + excluded.bot_bytes, s2 = s2 + excluded.s2, s3 = s3 + excluded.s3, s4 = s4 + excluded.s4,
		s5 = s5 + excluded.s5, hours = excluded.hours");
	foreach ($acc['days'] as $day => $D) {
		$get->bindValue(':s', $site, SQLITE3_TEXT); $get->bindValue(':u', $user, SQLITE3_TEXT); $get->bindValue(':d', $day, SQLITE3_TEXT);
		$r = $get->execute();
		$row = $r ? $r->fetchArray(SQLITE3_NUM) : false;
		$get->reset();
		$h = $D['h'];
		if ($row && ($old = json_decode((string)$row[0], true)) && count($old) === 24)
			foreach ($old as $i => $n) $h[$i] += (int)$n;
		foreach (array(':s' => $site, ':u' => $user, ':d' => $day, ':h' => json_encode($h)) as $k => $v)
			$up->bindValue($k, $v, SQLITE3_TEXT);
		foreach (array(':v' => 'v', ':p' => 'p', ':r' => 'r', ':b' => 'b', ':br' => 'br', ':bb' => 'bb', ':s2' => 's2', ':s3' => 's3', ':s4' => 's4', ':s5' => 's5') as $k => $f)
			$up->bindValue($k, (int)$D[$f], SQLITE3_INTEGER);
		$up->execute();
		$up->reset();
	}
	$top = $sdb->prepare('INSERT INTO stats_top (site, user, period, kind, item, n, bytes) VALUES (:s, :u, :p, :k, :i, :n, :b)
		ON CONFLICT(site, user, period, kind, item) DO UPDATE SET n = n + excluded.n, bytes = bytes + excluded.bytes');
	foreach ($acc['top'] as $day => $kinds)
		foreach ($kinds as $kind => $items) {
			foreach ($items as $item => $v) {
				foreach (array(':s' => $site, ':u' => $user, ':p' => $day, ':k' => $kind, ':i' => (string)$item) as $k => $x)
					$top->bindValue($k, $x, SQLITE3_TEXT);
				$top->bindValue(':n', $v[0], SQLITE3_INTEGER);
				$top->bindValue(':b', $v[1], SQLITE3_INTEGER);
				$top->execute();
				$top->reset();
			}
			stats_top_cap($sdb, $site, $user, $day, $kind, 5000, 3000);
		}
	$seen = $sdb->prepare('INSERT OR IGNORE INTO stats_seen (site, day, h) VALUES (:s, :d, :h)');
	foreach ($acc['new'] as $day => $hs) {
		if ($day < $keep_seen) continue;           // old days need no more checks
		foreach ($hs as $h) {
			$seen->bindValue(':s', $site, SQLITE3_TEXT); $seen->bindValue(':d', $day, SQLITE3_TEXT); $seen->bindValue(':h', $h, SQLITE3_TEXT);
			$seen->execute();
			$seen->reset();
		}
	}
}

/* Over $max rows in one list of one period: keep the biggest $keep. */
function stats_top_cap($sdb, $site, $user, $period, $kind, $max, $keep) {
	$w = "site = '".$sdb->escapeString($site)."' AND user = '".$sdb->escapeString($user)."' AND period = '".$sdb->escapeString($period)."' AND kind = '".$sdb->escapeString($kind)."'";
	if ((int)$sdb->querySingle("SELECT COUNT(*) FROM stats_top WHERE $w") <= $max)
		return;
	$order = $kind === 'file' ? 'bytes' : 'n';
	$sdb->exec("DELETE FROM stats_top WHERE $w AND item NOT IN (SELECT item FROM stats_top WHERE $w ORDER BY $order DESC LIMIT ".(int)$keep.')');
}

/* After a run: finished days keep their top 100; lists older than 35 days go
   into their month (top 200); old secrets, visitor hashes and history go. */
function stats_maintain($sdb, array $paths) {
	$today = date('Y-m-d');
	$yesterday = date('Y-m-d', strtotime('-1 day'));
	$sdb->exec('BEGIN IMMEDIATE');
	$sdb->exec("DELETE FROM stats_seen WHERE day < '$yesterday'");
	$sdb->exec("DELETE FROM stats_meta WHERE k LIKE 'salt-%' AND k < 'salt-$yesterday'");

	$done = stats_meta_get('trimmed-until', '');
	$r = $sdb->query("SELECT DISTINCT site, user, period, kind FROM stats_top WHERE length(period) = 10 AND period < '$yesterday'".($done !== '' ? " AND period > '".$sdb->escapeString($done)."'" : ''));
	$todo = array();
	while ($r && ($row = $r->fetchArray(SQLITE3_ASSOC))) $todo[] = $row;
	foreach ($todo as $t)
		stats_top_cap($sdb, $t['site'], $t['user'], $t['period'], $t['kind'], 100, 100);
	stats_meta_put('trimmed-until', date('Y-m-d', strtotime('-2 days')));

	$cut = date('Y-m-d', strtotime('-'.STATS_DAY_LISTS_DAYS.' days'));
	$sdb->exec("INSERT INTO stats_top (site, user, period, kind, item, n, bytes)
		SELECT site, user, substr(period, 1, 7), kind, item, SUM(n), SUM(bytes) FROM stats_top WHERE length(period) = 10 AND period < '$cut'
		GROUP BY site, user, substr(period, 1, 7), kind, item
		ON CONFLICT(site, user, period, kind, item) DO UPDATE SET n = n + excluded.n, bytes = bytes + excluded.bytes");
	$r = $sdb->query("SELECT DISTINCT site, user, substr(period, 1, 7) AS m FROM stats_top WHERE length(period) = 10 AND period < '$cut'");
	$months = array();
	while ($r && ($row = $r->fetchArray(SQLITE3_ASSOC))) $months[] = $row;
	$sdb->exec("DELETE FROM stats_top WHERE length(period) = 10 AND period < '$cut'");
	foreach ($months as $m)
		foreach (array('page', 'ref', '404', 'bot', 'file', 'country', 'browser', 'os', 'device') as $k)
			stats_top_cap($sdb, $m['site'], $m['user'], $m['m'], $k, 200, 200);

	$old = date('Y-m', strtotime(date('Y-m-01').' -'.STATS_KEEP_MONTHS.' months'));
	$sdb->exec("DELETE FROM stats_days WHERE day < '$old-01'");
	$sdb->exec("DELETE FROM stats_top WHERE period < '$old'");
	/* logs of sites that are gone */
	$keep = array();
	foreach (stats_sites() as $d => $u) $keep[stats_log_path($d)] = 1;
	foreach ($paths as $p)
		if (!isset($keep[$p])) {
			$s = $sdb->prepare('DELETE FROM stats_logs WHERE path = :p');
			$s->bindValue(':p', $p, SQLITE3_TEXT);
			$s->execute();
		}
	$sdb->exec('COMMIT');
}

/* ---- reading ---------------------------------------------------------------- */

/* Day rows of sites (owned by $user) between two days: day => summed row with
   src log|awstats (own numbers win over imported ones). */
function stats_day_rows($user, array $sites, $from, $to) {
	$sdb = stats_db();
	$out = array();
	if (!$sdb || !$sites)
		return $out;
	$in = implode(',', array_map(function ($s) use ($sdb) { return "'".$sdb->escapeString($s)."'"; }, $sites));
	$r = $sdb->query("SELECT site, day, src, visitors, pages, requests, bytes, bot_requests, bot_bytes, s2, s3, s4, s5, hours
		FROM stats_days WHERE user = '".$sdb->escapeString($user)."' AND site IN ($in) AND day >= '$from' AND day <= '$to' ORDER BY src DESC");
	$by = array();          // site|day => row ('log' sorts after 'awstats' DESC: log first)
	while ($r && ($row = $r->fetchArray(SQLITE3_ASSOC))) {
		$k = $row['site'].'|'.$row['day'];
		if (isset($by[$k])) continue;
		$by[$k] = $row;
	}
	foreach ($by as $row) {
		$d = $row['day'];
		if (!isset($out[$d]))
			$out[$d] = array('v' => 0, 'pages' => 0, 'req' => 0, 'bytes' => 0, 'bots' => 0, 'bot_bytes' => 0, 'e2' => 0, 'e3' => 0, 'e4' => 0, 'e5' => 0, 'h' => array_fill(0, 24, 0), 'src' => $row['src']);
		$o = &$out[$d];
		$o['v'] += $row['visitors']; $o['pages'] += $row['pages']; $o['req'] += $row['requests']; $o['bytes'] += $row['bytes'];
		$o['bots'] += $row['bot_requests']; $o['bot_bytes'] += $row['bot_bytes'];
		$o['e2'] += $row['s2']; $o['e3'] += $row['s3']; $o['e4'] += $row['s4']; $o['e5'] += $row['s5'];
		if ($o['src'] !== $row['src']) $o['src'] = 'log';
		if (($h = json_decode((string)$row['hours'], true)) && count($h) === 24)
			foreach ($h as $i => $n) $o['h'][$i] += (int)$n;
		unset($o);
	}
	ksort($out);
	return $out;
}

/* kind => [[item, n, bytes]...] for days $from..$to: the day lists plus the
   month lists of the months they cover (a day's lists move into its month
   after 35 days). */
function stats_lists($user, array $sites, $from, $to, $limit = 50) {
	$sdb = stats_db();
	$out = array();
	foreach (array('page', 'ref', '404', 'bot', 'file', 'country', 'browser', 'os', 'device') as $k)
		$out[$k] = array();
	if (!$sdb || !$sites)
		return $out;
	$in = implode(',', array_map(function ($s) use ($sdb) { return "'".$sdb->escapeString($s)."'"; }, $sites));
	$months = array();
	for ($m = substr($from, 0, 7); $m <= substr($to, 0, 7); $m = date('Y-m', strtotime($m.'-01 +1 month')))
		$months[] = "'".$m."'";
	$u = $sdb->escapeString($user);
	$r = $sdb->query("SELECT kind, item, SUM(n) AS n, SUM(bytes) AS b FROM stats_top WHERE user = '$u' AND site IN ($in)
		AND ((length(period) = 10 AND period >= '$from' AND period <= '$to') OR (length(period) = 7 AND period IN (".implode(',', $months).")))
		GROUP BY kind, item");
	while ($r && ($row = $r->fetchArray(SQLITE3_ASSOC)))
		if (isset($out[$row['kind']]))
			$out[$row['kind']][] = array($row['item'], (int)$row['n'], (int)$row['b']);
	foreach ($out as $k => &$rows) {
		usort($rows, $k === 'file' ? function ($a, $b) { return $b[2] <=> $a[2]; } : function ($a, $b) { return $b[1] <=> $a[1]; });
		$rows = array_slice($rows, 0, $limit);
	}
	return $out;
}

/*
 * Everything the Visitors page shows for $site ('all' = every site of $user)
 * over $period: '30d', '12m' or 'YYYY-MM'. The caller has checked that $user
 * may see it.
 */
function stats_report($user, $site, $period = '30d') {
	$sites = stats_account_sites($user);
	if ($site !== 'all') {
		if (!in_array($site, $sites, true))
			return result_error("$site is not a site of $user.");
		$sel = array($site);
	} else
		$sel = $sites;
	$today = date('Y-m-d');
	if ($period === '30d') {
		$from = date('Y-m-d', strtotime('-29 days')); $to = $today; $unit = 'day';
		$pfrom = date('Y-m-d', strtotime('-59 days')); $pto = date('Y-m-d', strtotime('-30 days'));
	} elseif ($period === '12m') {
		$from = date('Y-m-01', strtotime(date('Y-m-01').' -11 months')); $to = $today; $unit = 'month';
		$pfrom = $pto = null;
	} elseif (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $period)) {
		$from = $period.'-01'; $to = min($today, date('Y-m-t', strtotime($from))); $unit = 'day';
		if ($from > $today)
			return result_error('That month has not started yet.');
		$pfrom = date('Y-m-01', strtotime($from.' -1 month')); $pto = date('Y-m-t', strtotime($pfrom));
	} else
		return result_error('Period: 30d, 12m or a month (YYYY-MM).');

	$rows = stats_day_rows($user, $sel, $from, $to);
	$first = stats_first_day($user, $sel);
	$buckets = array();
	$tot = stats_zero();
	$hours = array_fill(0, 24, 0);
	if ($unit === 'day') {
		for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d.' +1 day'))) {
			$b = isset($rows[$d]) ? stats_bucket($rows[$d]) : null;
			$buckets[] = array('d' => $d, 'none' => $b === null, 'today' => $d === $today) + ($b ?: array());
		}
	} else {
		for ($m = substr($from, 0, 7); $m <= substr($to, 0, 7); $m = date('Y-m', strtotime($m.'-01 +1 month'))) {
			$s = stats_zero(); $n = 0; $imp = 0;
			foreach ($rows as $d => $row)
				if (substr($d, 0, 7) === $m) { stats_add($s, $row); $n++; if ($row['src'] === 'awstats') $imp++; }
			$b = array('d' => $m, 'none' => $n === 0, 'today' => $m === date('Y-m'), 'days' => $n, 'imp_days' => $imp);
			if ($n) {
				$b += stats_bucket($s);
				$b['src'] = $imp === $n ? 'awstats' : ($imp ? 'mixed' : 'log');
				if ($imp && $imp < $n) {
					$ip = stats_zero();
					foreach ($rows as $d => $row)
						if (substr($d, 0, 7) === $m && $row['src'] === 'awstats') stats_add($ip, $row);
					$b['imp_part'] = stats_bucket($ip);
				}
			}
			$buckets[] = $b;
		}
	}
	$imp_days = 0; $last_imp = '';
	foreach ($rows as $d => $row) {
		stats_add($tot, $row);
		foreach ($row['h'] as $i => $n) $hours[$i] += $n;
		if ($row['src'] === 'awstats') { $imp_days++; $last_imp = max($last_imp, $d); }
	}
	$prev = null;
	if ($pfrom !== null && $first !== '' && $first <= $pfrom) {
		$prev = stats_zero();
		foreach (stats_day_rows($user, $sel, $pfrom, $pto) as $row) stats_add($prev, $row);
		$prev = stats_bucket($prev);
	}
	$lists = stats_lists($user, $sel, $from, $to);
	foreach ($lists['country'] as &$c) $c[] = stats_country_name($c[0]);
	unset($c);
	foreach ($lists['ref'] as &$c) $c[] = stats_ref_kind($c[0]);
	unset($c);
	foreach ($lists['404'] as &$c) $c[] = stats_scanner_path($c[0]);
	unset($c);
	$ai = array_flip(stats_ai_bots());
	foreach ($lists['bot'] as &$c) $c[] = isset($ai[$c[0]]);
	unset($c);

	/* the account's traffic this month against its package (Hosting Pro) */
	$month = date('Y-m');
	$acct = stats_zero();
	foreach (stats_day_rows($user, $sites, $month.'-01', $today) as $row) stats_add($acct, $row);
	$bw = array('month' => $month, 'bytes' => $acct['bytes'], 'limit_bytes' => null);
	if (function_exists('hosting_enabled') && hosting_enabled() && function_exists('hosting_account_package') && ($pkg = hosting_account_package($user))) {
		if (($pkg['bandwidth_gb'] ?? null) !== null && $pkg['bandwidth_gb'] !== '')
			$bw['limit_bytes'] = (int)round((float)$pkg['bandwidth_gb'] * 1073741824);
		if (function_exists('hosting_bandwidth'))
			$bw['bytes'] = (int)(hosting_bandwidth()[$user] ?? $bw['bytes']);
	}
	$geo = stats_geo_file();
	$collected = (int)stats_meta_get('collected', '0');
	return result_ok('', array(
		'site' => $site, 'sites' => $sites, 'period' => $period, 'from' => $from, 'to' => $to, 'unit' => $unit,
		'enabled' => stats_enabled(), 'first_day' => $first, 'imported_days' => $imp_days, 'imported_until' => $last_imp,
		'collected' => $collected ?: null, 'next' => $collected ? stats_next_run($collected) : null, 'today' => $today,
		'totals' => stats_bucket($tot) + array('days' => count($rows)), 'prev' => $prev, 'buckets' => $buckets,
		'hours' => $hours, 'lists' => $lists, 'bandwidth' => $bw,
		'countries' => $geo ? array('source' => $geo[1], 'date' => $geo[2]) : null,
	));
}

function stats_zero() {
	return array('v' => 0, 'pages' => 0, 'req' => 0, 'bytes' => 0, 'bots' => 0, 'bot_bytes' => 0, 'e2' => 0, 'e3' => 0, 'e4' => 0, 'e5' => 0);
}

function stats_add(array &$s, array $row) {
	foreach (stats_zero() as $k => $z)
		$s[$k] += (int)($row[$k] ?? 0);
}

function stats_bucket(array $row) {
	$b = array();
	foreach (stats_zero() as $k => $z) $b[$k] = (int)($row[$k] ?? 0);
	if (isset($row['src'])) $b['src'] = $row['src'];
	return $b;
}

/* The first day counted (or imported) for these sites, '' = none. */
function stats_first_day($user, array $sites) {
	$sdb = stats_db();
	if (!$sdb || !$sites) return '';
	$in = implode(',', array_map(function ($s) use ($sdb) { return "'".$sdb->escapeString($s)."'"; }, $sites));
	return (string)$sdb->querySingle("SELECT MIN(day) FROM stats_days WHERE user = '".$sdb->escapeString($user)."' AND site IN ($in)");
}

/* The cron runs at minute 12 of every hour. */
function stats_next_run($last) {
	$t = strtotime(date('Y-m-d H:12:00', max($last, time())));
	return $t <= time() ? $t + 3600 : $t;
}

/*
 * 2087 Bandwidth Usage: every account with its sites' traffic in $month
 * (YYYY-MM), busiest first.
 */
function stats_usage($month = '') {
	global $db;
	$month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) ? $month : date('Y-m');
	$sdb = stats_db();
	$sites = stats_sites();
	$acc = array();
	$r = $db->query('SELECT user, domain FROM accounts ORDER BY user');
	while ($r && ($row = $r->fetchArray(SQLITE3_ASSOC)))
		$acc[$row['user']] = array('user' => $row['user'], 'domain' => $row['domain'], 'sites' => array(), 'limit_bytes' => null, 'package' => '') + stats_zero();
	if ($sdb) {
		$res = $sdb->query("SELECT site, user, SUM(visitors) v, SUM(pages) pages, SUM(requests) req, SUM(bytes) bytes, SUM(bot_requests) bots,
			SUM(bot_bytes) bot_bytes, SUM(s2) e2, SUM(s3) e3, SUM(s4) e4, SUM(s5) e5
			FROM stats_days d WHERE day >= '$month-01' AND day <= '$month-31' AND (src = 'log' OR NOT EXISTS
			(SELECT 1 FROM stats_days l WHERE l.site = d.site AND l.user = d.user AND l.day = d.day AND l.src = 'log'))
			GROUP BY site, user");
		while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) {
			$u = $row['user'];
			/* only rows of the site's current owner; a day's own numbers before imported ones */
			if (!isset($acc[$u]) || ($sites[$row['site']] ?? '') !== $u) continue;
			$b = stats_bucket($row);
			$acc[$u]['sites'][$row['site']] = $b + array('site' => $row['site']);
			stats_add($acc[$u], $b);
		}
	}
	$hp = function_exists('hosting_enabled') && hosting_enabled() && function_exists('hosting_account_package');
	foreach ($acc as $u => &$a) {
		foreach ($sites as $d => $su)
			if ($su === $u && !isset($a['sites'][$d]))
				$a['sites'][$d] = stats_zero() + array('site' => $d);
		uasort($a['sites'], function ($x, $y) { return $y['bytes'] <=> $x['bytes']; });
		$a['sites'] = array_values($a['sites']);
		if ($hp && ($pkg = hosting_account_package($u))) {
			$a['package'] = (string)$pkg['name'];
			if (($pkg['bandwidth_gb'] ?? null) !== null && $pkg['bandwidth_gb'] !== '')
				$a['limit_bytes'] = (int)round((float)$pkg['bandwidth_gb'] * 1073741824);
		}
	}
	unset($a);
	$list = array_values($acc);
	usort($list, function ($x, $y) { return $y['bytes'] <=> $x['bytes'] ?: strcmp($x['user'], $y['user']); });
	$months = array();
	if ($sdb) {
		$res = $sdb->query("SELECT DISTINCT substr(day, 1, 7) FROM stats_days ORDER BY 1 DESC");
		while ($res && ($row = $res->fetchArray(SQLITE3_NUM))) $months[] = $row[0];
	}
	if (!in_array(date('Y-m'), $months, true)) array_unshift($months, date('Y-m'));
	$tot = stats_zero();
	foreach ($list as $a) stats_add($tot, $a);
	$geo = stats_geo_file();
	return result_ok('', array('month' => $month, 'months' => $months, 'accounts' => $list, 'totals' => $tot,
		'enabled' => stats_enabled(), 'counting' => stats_counting(), 'collected' => ((int)stats_meta_get('collected', '0')) ?: null,
		'geo' => $geo ? array('source' => $geo[1], 'date' => $geo[2], 'file' => $geo[0]) : null,
		'geo_update' => setting_get('stats-geoip-update', '1') !== '0'));
}

/* Bytes sent per account in $month (YYYY-MM): user => bytes, for the sites it
   owns now; the same figure as stats_usage(). Hosting Pro's bandwidth limits. */
function stats_account_bytes($month) {
	$sdb = stats_db();
	if (!$sdb || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month))
		return array();
	$sites = stats_sites();
	$out = array();
	$res = $sdb->query("SELECT site, user, SUM(bytes) b FROM stats_days d WHERE day >= '$month-01' AND day <= '$month-31' AND (src = 'log' OR NOT EXISTS
		(SELECT 1 FROM stats_days l WHERE l.site = d.site AND l.user = d.user AND l.day = d.day AND l.src = 'log'))
		GROUP BY site, user");
	while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC)))
		if (($sites[$row['site']] ?? '') === $row['user'])
			$out[$row['user']] = ($out[$row['user']] ?? 0) + (int)$row['b'];
	return $out;
}

/* Server switches: visitor stats on/off, monthly DB-IP updates on/off. */
function stats_settings_set($enabled = null, $geo_update = null) {
	if ($enabled !== null) {
		setting_put('stats-enabled', $enabled ? '1' : '0');
		route_log('stats: visitor stats '.($enabled ? 'on' : 'off'));
	}
	if ($geo_update !== null) {
		setting_put('stats-geoip-update', $geo_update ? '1' : '0');
		route_log('stats: monthly country database updates '.($geo_update ? 'on' : 'off'));
	}
	return result_ok('Saved.');
}
