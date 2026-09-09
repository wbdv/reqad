-- Demote `emails` from a (fossilised) inventory to a pure disk-usage cache.
--
-- What it was: seeded once in 2024 by a block in templates/email-accounts.php
-- that has been commented out ("initial data") ever since, so nothing has
-- written to it in two years. create_email.php never inserted a row at all.
-- The result was an inventory that disagreed with reality — 29 rows against 16
-- real mailboxes here, including two `repo/mail/<domain>/<user>` path fragments
-- and one empty string left by insert paths that no longer exist. Letting it
-- drive a picker is what allowed an autoresponder to be configured for an
-- address exim never delivers to (see db/1031.sql's era in REQAD_TODO.md).
--
-- What it is now: a cache of ONE derived number, keyed by address. The mailbox
-- inventory is /etc/dovecot/users and nothing else (mailbox_list() in
-- functions.php). A row for a mailbox that no longer exists is therefore inert:
-- lookups are driven by the dovecot list, so nothing ever reads it. A MISSING
-- row is not an error — it means "not measured yet", and the caller computes
-- live and writes the result back.
--
-- Dropped columns: disk_quota, status, created_at were write-only. Nothing in
-- the panel ever read them (per-mailbox quotas are not implemented; the
-- disk_quota the UI edits lives on `accounts`).
--
-- Rows are deliberately NOT carried over. Every disk_usage value in the old
-- table dates from 2024 and would be served as if current; starting empty makes
-- every mailbox take the correct live-measurement path until the cache warms.

DROP TABLE IF EXISTS emails_new;

CREATE TABLE emails_new (
    email      TEXT NOT NULL PRIMARY KEY,   -- full address, lowercased
    disk_usage INTEGER NOT NULL DEFAULT 0,  -- MB, as reported by du -skm
    updated_at DATETIME                     -- NULL = never measured
);

DROP TABLE IF EXISTS emails;
ALTER TABLE emails_new RENAME TO emails;

INSERT OR REPLACE INTO settings (name, value) VALUES ('db-version', '1032');
