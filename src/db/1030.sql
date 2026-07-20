-- Alias domains: additional domains that share an account's document root and vhost.
-- www.<domain> is stored here as a normal (removable) alias; mail.<domain> is NOT
-- stored (it is derived from accounts.has_email). Wildcards allowed (is_wildcard=1).
CREATE TABLE IF NOT EXISTS aliases (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id  INTEGER NOT NULL,          -- accounts.id (Linux UID)
    alias       TEXT NOT NULL,
    is_wildcard INTEGER NOT NULL DEFAULT 0,
    ssl_status  TEXT NOT NULL DEFAULT 'none',   -- none | pending | covered
    created_at  DATETIME DEFAULT (datetime('now')),
    UNIQUE(alias)
);

-- Seed www.<domain> for every existing account (it was previously hardcoded into
-- the vhost server_name). Skip accounts that are themselves a www.* domain.
INSERT INTO aliases (account_id, alias, is_wildcard, ssl_status, created_at)
    SELECT id, 'www.'||domain, 0, 'none', datetime('now')
    FROM accounts
    WHERE domain NOT LIKE 'www.%';

UPDATE settings SET value='1030', updated_at=datetime('now') WHERE name='db-version';
