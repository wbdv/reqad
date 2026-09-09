-- Single-use tickets for webmail auto-login.
--
-- The panel can open Roundcube already logged in as any mailbox, using the
-- dovecot master user. The master password must never travel through the
-- browser, so the panel mints a row here instead and hands the browser only an
-- opaque token; the Roundcube plugin exchanges it for the address, deletes the
-- row on read (single use) and refuses anything older than 30 seconds.
--
-- Rows are pruned opportunistically on insert, the same way msg_add() prunes
-- the message queue -- no cron job needed.

CREATE TABLE IF NOT EXISTS webmail_tickets (
    token   TEXT NOT NULL PRIMARY KEY,   -- 32 hex chars, from random_bytes()
    email   TEXT NOT NULL,               -- the mailbox to log in as
    created INTEGER NOT NULL             -- unix time; tickets expire in 30s
);

INSERT OR REPLACE INTO settings (name, value) VALUES ('db-version', '1033');
