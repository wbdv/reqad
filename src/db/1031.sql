-- Email filters (3-tier Sieve). Rows here cover the GLOBAL and DOMAIN tiers only.
-- The per-account tier is deliberately absent: its source of truth is the Sieve
-- script itself (~/sieve + ~/dovecot.sieve), because Roundcube and any IMAP
-- client can write it over ManageSieve and a DB copy would silently clobber them.
--
-- Rendered to /var/lib/reqad/sieve/reqad-global.sieve and
-- /var/lib/reqad/sieve/domains/<domain>.sieve, then compiled with sievec.
CREATE TABLE IF NOT EXISTS email_filters (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    scope      TEXT NOT NULL,                  -- 'global' | 'domain'
    target     TEXT NOT NULL DEFAULT '',       -- '' for global, domain name for domain
    name       TEXT NOT NULL,
    enabled    INTEGER NOT NULL DEFAULT 1,
    priority   INTEGER NOT NULL DEFAULT 0,     -- ascending; ties broken by id
    match_type TEXT NOT NULL DEFAULT 'all',    -- 'all' | 'any'
    conditions TEXT NOT NULL,                  -- JSON [{field, op, value}]
    actions    TEXT NOT NULL,                  -- JSON [{type, value}]
    stop       INTEGER NOT NULL DEFAULT 0,     -- ends THIS script only (see note)
    created_at DATETIME DEFAULT (datetime('now')),
    UNIQUE(scope, target, name)
);

-- `stop` does NOT cross script boundaries in Pigeonhole: it ends only the script
-- it appears in, and the sequence continues to the next tier. Only `discard`
-- terminates the whole sequence. The tiers are additive, not hierarchical.
CREATE INDEX IF NOT EXISTS idx_email_filters_scope
    ON email_filters(scope, target, priority);

INSERT OR REPLACE INTO settings (name, value) VALUES ('db-version', '1031');
