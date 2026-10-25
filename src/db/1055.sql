-- DNS API keys (Settings > DNS API keys, app/functions/dns_keys.php): other
-- servers - usually other Reqad servers with DNS provider PowerDNS - keep
-- their zones on this server's PowerDNS through a PowerDNS-compatible API at
-- https://<host>:2087/pdns/ (app/api/pdns.php). Each key sees and changes
-- only its own zones.
--
-- The keys themselves are api_tokens rows with kind 'dns' (sha256 only, shown
-- once, address allowlist, expiry, permissions read / write / delete); the
-- REST API and MCP refuse them.
--
-- dns_keys       per key: max_zones (0 = no limit).
-- dns_key_zones  the zone each key owns, one key per zone. A zone a key
--                creates is added; the admin can add zones already here and
--                move them between keys. Removing the zone (by the key or on
--                2087) removes the row; revoking a key keeps its zones, so
--                they can be moved to its replacement.
--
-- The API is off until the setting dns-api-enabled is 1.

CREATE TABLE IF NOT EXISTS dns_keys (
	token_id  INTEGER PRIMARY KEY,
	max_zones INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS dns_key_zones (
	zone      TEXT PRIMARY KEY,
	token_id  INTEGER NOT NULL,
	added     INTEGER NOT NULL,
	added_by  TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS dns_key_zones_token ON dns_key_zones(token_id);

INSERT OR IGNORE INTO settings (name, value) VALUES ('dns-api-enabled', '0');

INSERT OR REPLACE INTO settings (name, value) VALUES ('db-version', '1055');
