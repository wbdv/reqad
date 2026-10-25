-- WordPress auto-updates (WP Toolkit > Auto-updates, app/functions/wordpress_auto.php).
-- One row per install that the panel updates by itself; no row = off (the
-- site's own WordPress settings apply). The runs are wordpress_jobs rows with
-- by_user 'auto' (started by `reqad wordpress auto-run`, every 15 minutes).
--
-- core      off | minor (security and fix releases: 7.1.3 -> 7.1.4) | all
-- plugins   off | all | some (the folder names in plugin_list, JSON)
-- themes    off | all | some (theme_list, JSON)
-- weekday   -1 = every day, 0 (Sunday) .. 6 = that day of the week
-- hour      0..23, the server's time
-- delay     days a plugin or theme release must be old before it is installed
-- next_run  when the next run is due; last_* = how the last run ended
-- fails     runs in a row that failed (3+ = a health item)

CREATE TABLE IF NOT EXISTS wordpress_auto (
	site_id      INTEGER PRIMARY KEY,
	core         TEXT NOT NULL DEFAULT 'off',
	plugins      TEXT NOT NULL DEFAULT 'off',
	plugin_list  TEXT NOT NULL DEFAULT '[]',
	themes       TEXT NOT NULL DEFAULT 'off',
	theme_list   TEXT NOT NULL DEFAULT '[]',
	weekday      INTEGER NOT NULL DEFAULT -1,
	hour         INTEGER NOT NULL DEFAULT 3,
	delay        INTEGER NOT NULL DEFAULT 0,
	next_run     INTEGER NOT NULL DEFAULT 0,
	last_run     INTEGER NOT NULL DEFAULT 0,
	last_status  TEXT NOT NULL DEFAULT '',
	last_message TEXT NOT NULL DEFAULT '',
	fails        INTEGER NOT NULL DEFAULT 0,
	updated      INTEGER NOT NULL DEFAULT 0
);

INSERT OR REPLACE INTO settings (name, value) VALUES ('db-version', '1051');
