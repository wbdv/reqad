-- WordPress Toolkit > Clone or staging (app/functions/wordpress_copy.php).
--
-- wordpress_jobs.kind: what a background job does - update (core, plugins,
-- themes; also the auto-update runs) or copy (a clone or staging copy of the
-- site, made into a new site of the account).
--
-- wordpress_copies: one row per copy made here, keyed by the copy's
-- wordpress row.
--   source_id     the wordpress row it was copied from (the live site)
--   kind          staging (private: not indexed, no email, no scheduled
--                 tasks) | clone (an independent second site)
--   domain        the copy's site (an addon domain or subdomain made for it)
--   source_label  domain[/folder] of the live site when it was copied
--   db_name       the copy's own database (dropped with the copy)
--   created       when it was made; pushed: when it was last copied back

ALTER TABLE wordpress_jobs ADD COLUMN kind TEXT NOT NULL DEFAULT 'update';

CREATE TABLE IF NOT EXISTS wordpress_copies (
	site_id      INTEGER PRIMARY KEY,
	source_id    INTEGER NOT NULL,
	account      TEXT NOT NULL,
	kind         TEXT NOT NULL DEFAULT 'staging',
	domain       TEXT NOT NULL,
	source_label TEXT NOT NULL DEFAULT '',
	db_name      TEXT NOT NULL DEFAULT '',
	created      INTEGER NOT NULL DEFAULT 0,
	pushed       INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS wordpress_copies_source ON wordpress_copies (source_id);

INSERT OR REPLACE INTO settings (name, value) VALUES ('db-version', '1052');
