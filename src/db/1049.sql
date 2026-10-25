-- WordPress updates that run in the background (WP Toolkit > Updates of the
-- account panel; app/functions/wordpress.php wordpress_job_*). One row per
-- request: the runner `reqad wordpress __run <id>` (transient unit
-- reqad-wp-<site id>) saves the database once, then runs the steps in order.
--
-- steps    JSON [[what, [items]], ...]: what = core | plugins | themes,
--          items = plugin / theme folder names ([] = all with an update)
-- status   queued | running | ok | warn | failed (warn = updated, but
--          WordPress does not start with its plugins and theme on)
-- backup   the database copy in the account's ~/wp-backups/
-- output   wp-cli's output (first 64 KB)

CREATE TABLE IF NOT EXISTS wordpress_jobs (
	id       INTEGER PRIMARY KEY AUTOINCREMENT,
	site_id  INTEGER NOT NULL,
	account  TEXT NOT NULL,
	steps    TEXT NOT NULL,
	by_user  TEXT NOT NULL DEFAULT '',
	status   TEXT NOT NULL DEFAULT 'queued',
	message  TEXT NOT NULL DEFAULT '',
	backup   TEXT NOT NULL DEFAULT '',
	output   TEXT NOT NULL DEFAULT '',
	queued   INTEGER NOT NULL,
	started  INTEGER NOT NULL DEFAULT 0,
	finished INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS wordpress_jobs_site ON wordpress_jobs (site_id, id);

INSERT OR REPLACE INTO settings (name, value) VALUES ('db-version', '1049');
