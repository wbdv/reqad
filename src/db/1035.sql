-- Databases assigned to a hosting account by hand.
--
-- A database belongs to an account by its name: "<user>_<name>" is owned by
-- <user>. Databases created outside the panel (imported from another server,
-- made in phpMyAdmin, ...) have no such prefix and belong to nobody, so no
-- account backup includes them. The Databases page can assign one of those to
-- an account; the assignment is recorded here and counts exactly like the
-- prefix: the database is listed under that account, dumped by its backups and
-- restored with it.
--
-- A row wins over the prefix. Only a database with no owner can be assigned,
-- so the two only disagree when an account whose name happens to be the
-- prefix is created afterwards -- and then the database stays where it was put.

CREATE TABLE IF NOT EXISTS db_owners (
    dbname  TEXT NOT NULL PRIMARY KEY,   -- MySQL database name (exact)
    user    TEXT NOT NULL,               -- accounts.user
    created INTEGER NOT NULL             -- unix time
);
CREATE INDEX IF NOT EXISTS db_owners_user ON db_owners(user);

INSERT OR REPLACE INTO settings (name, value) VALUES ('db-version', '1035');
