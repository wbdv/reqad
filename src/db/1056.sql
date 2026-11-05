-- 1056: who owns a hosting account (resellers, as in WHM).
--
-- account_owners  one row per account a reseller owns; no row = the server
--                 admin's. A reseller's own account owns itself (user = owner),
--                 so it counts in the reseller's limits like the rest.
--                 Rows are written only by account_owner_set() in
--                 app/functions/accounts.php; the reseller add-on
--                 (plugins/reseller) says who may own accounts and keeps the
--                 resellers' limits in its own table.

CREATE TABLE IF NOT EXISTS account_owners (
	user   TEXT PRIMARY KEY,
	owner  TEXT NOT NULL,
	since  INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS account_owners_owner ON account_owners(owner);

INSERT OR REPLACE INTO settings (name, value) VALUES ('db-version', '1056');
