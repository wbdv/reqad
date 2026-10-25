-- Restores an account starts from its own backups (Backups on the account
-- panel, /v1/backups/{id}/restore; app/functions/backups.php). One row per
-- request: the runner `reqad backup __run <id>` (transient unit
-- reqad-restore-<account>, one at a time per account) puts back files, a
-- database or a mailbox.
--
-- backup   the backup's id: remote-YYYYMMDD (nightly, on the backup server)
--          or local-YYYYMMDD-HHMM (/usr/local/reqad/backup)
-- kind     files | database | email
-- target   files: a path relative to the home folder ('' = all of it);
--          database: the backup's database; email: the mailbox
-- mode     files: missing | overwrite | replace; database: tables | replace |
--          new; email: missing | replace
-- options  JSON: database mode new: name (without the account's prefix),
--          grant (a database user given it)
-- status   queued | running | ok | failed
-- kept     where what the restore replaced was saved, relative to the home
--          (~/backup-restores/...)

CREATE TABLE IF NOT EXISTS backup_restores (
	id       INTEGER PRIMARY KEY AUTOINCREMENT,
	account  TEXT NOT NULL,
	backup   TEXT NOT NULL,
	kind     TEXT NOT NULL,
	target   TEXT NOT NULL DEFAULT '',
	mode     TEXT NOT NULL,
	options  TEXT NOT NULL DEFAULT '{}',
	by_user  TEXT NOT NULL DEFAULT '',
	status   TEXT NOT NULL DEFAULT 'queued',
	message  TEXT NOT NULL DEFAULT '',
	kept     TEXT NOT NULL DEFAULT '',
	output   TEXT NOT NULL DEFAULT '',
	queued   INTEGER NOT NULL,
	started  INTEGER NOT NULL DEFAULT 0,
	finished INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS backup_restores_account ON backup_restores (account, id);

INSERT OR REPLACE INTO settings (name, value) VALUES ('db-version', '1053');
