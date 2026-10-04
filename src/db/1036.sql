-- What the account backups leave out.
--
-- One row per rule. user = '*' is a server-wide rule applied to every account;
-- otherwise it is accounts.user. Two kinds:
--
--   path  a gitignore-style pattern relative to the home directory:
--           node_modules           any depth (no slash)
--           /tmp                   only <home>/tmp (leading slash = anchored)
--           public_html/cache      anchored (a slash anywhere = anchored)
--           **/wp-content/cache    any depth
--         scripts/backup_excludes.sh is the only place that turns these into
--         tar/find patterns, and its `check` command is the only validator.
--   db    a database name owned by the account (database_owner()); it is
--         still created empty on restore, only its data is left out.
--
-- scope: which backups the rule applies to -- the nightly remote backup, the
-- manual (Generate backup) one, or both.
--
-- Server-wide switches live in the settings table instead:
--   backup-exclude-markers '0' = ignore .nobackup files (folders holding one
--                          are backed up like any other); default honour them
--   backup-exclude-mounts  '0' = also back up filesystems mounted inside a home
--                          directory (sshfs, NFS, another disk); default skip
--   backup-exclude-caches  '1' = skip directories holding a CACHEDIR.TAG

CREATE TABLE IF NOT EXISTS backup_excludes (
    id      INTEGER PRIMARY KEY AUTOINCREMENT,
    user    TEXT NOT NULL,                  -- accounts.user, or '*'
    kind    TEXT NOT NULL DEFAULT 'path',   -- 'path' | 'db'
    pattern TEXT NOT NULL,
    scope   TEXT NOT NULL DEFAULT 'both',   -- 'both' | 'nightly' | 'manual'
    note    TEXT NOT NULL DEFAULT '',
    created INTEGER NOT NULL,               -- unix time
    UNIQUE (user, kind, pattern)
);
CREATE INDEX IF NOT EXISTS backup_excludes_user ON backup_excludes(user);

INSERT OR REPLACE INTO settings (name, value) VALUES ('db-version', '1036');
