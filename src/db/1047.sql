-- phpMyAdmin from the account panel (2083 /sql/, reqad-account).
--
-- One row per MySQL login the panel made for opening phpMyAdmin
-- (rqpma-<hex>@localhost, all privileges on the account's databases only;
-- database_pma_open() in app/functions/databases.php). session_id is the
-- auth_sessions.id of the panel session that opened it: the login is dropped
-- when that session ends (database_pma_close / database_pma_purge).
-- ticket (sha256 of the single-use token) and secret (the login's password)
-- are kept only until phpMyAdmin redeems the ticket, at most 60 seconds.

CREATE TABLE IF NOT EXISTS sql_logins (
	user       TEXT PRIMARY KEY,
	account    TEXT NOT NULL,
	session_id TEXT NOT NULL,
	created    INTEGER NOT NULL,
	ticket     TEXT NOT NULL DEFAULT '',
	secret     TEXT NOT NULL DEFAULT '',
	ticket_at  INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS sql_logins_session ON sql_logins (session_id);
CREATE INDEX IF NOT EXISTS sql_logins_ticket ON sql_logins (ticket);

INSERT OR REPLACE INTO settings (name, value) VALUES ('db-version', '1047');
