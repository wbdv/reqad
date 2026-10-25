-- A WordPress site's own page cache rules (WordPress Toolkit > Performance,
-- POST /v1/wordpress/{site}/cache-rules, `reqad wordpress cache`). Per
-- domain: the page cache belongs to the site's vhost, which every install on
-- the domain shares. The rules are written into the vhost's
-- `# BEGIN reqad-nginx-cache` block whenever the cache is on
-- (wp_nginx_cache_fragments()); kept here so they survive the cache being
-- switched off and on, and go with a clone.
--
-- kind   pages   = a path that is never cached (prefix, * = any text)
--        cookies = a cookie name that turns the cache off (trailing * = prefix)

CREATE TABLE IF NOT EXISTS wordpress_cache_rules (
	domain TEXT NOT NULL,
	kind   TEXT NOT NULL,
	value  TEXT NOT NULL,
	PRIMARY KEY (domain, kind, value)
);

INSERT OR REPLACE INTO settings (name, value) VALUES ('db-version', '1054');
