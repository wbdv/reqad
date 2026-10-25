-- Messages to hosting accounts (account panel > Profile, reqad-account;
-- app/functions/notify.php).
--
-- The address is the account's auth_principals.email (type 'account'). It
-- is written only after a code mailed to it was entered on the Profile page,
-- so it always receives mail from this server.
--
-- account_notify   the kinds of message an account changed from their
--                  default (account_notify_kinds()): one row per account and
--                  kind, enabled 0 / 1. No row = the kind's default.
-- account_notices  what was mailed: key = a health item id or 'login', so the
--                  same problem is mailed at most once a week even when it
--                  comes and goes between checks.

CREATE TABLE IF NOT EXISTS account_notify (
	account TEXT NOT NULL,
	kind    TEXT NOT NULL,
	enabled INTEGER NOT NULL,
	PRIMARY KEY (account, kind)
);

CREATE TABLE IF NOT EXISTS account_notices (
	account TEXT NOT NULL,
	key     TEXT NOT NULL,
	sent_at INTEGER NOT NULL,
	PRIMARY KEY (account, key)
);

INSERT OR REPLACE INTO settings (name, value) VALUES ('db-version', '1048');
