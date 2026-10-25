-- REST API (app/api/, https://<host>:2087/api/v1/, `reqad api`).
--
-- api_tokens  Bearer tokens. Only the sha256 of the token is stored; the
--             token itself is shown once, when it is made. prefix = its first
--             characters, to recognise it in lists and logs.
--             account '' = the whole server, else the token acts only on what
--             that hosting account owns (app/functions/scope.php).
--             scopes: comma list of read, write, delete.
--             allow_ips: comma list of addresses/CIDRs, '' = any.
--             expires 0 = never; revoked 0 = in use, else when it was revoked.
-- api_log     one row per authenticated call (pruned after 30 days).
--
-- The API is off until the setting api-enabled is 1 (Settings > API access,
-- `reqad api on`).

CREATE TABLE IF NOT EXISTS api_tokens (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT NOT NULL,
    token_hash  TEXT NOT NULL UNIQUE,
    prefix      TEXT NOT NULL,
    account     TEXT NOT NULL DEFAULT '',
    scopes      TEXT NOT NULL DEFAULT 'read',
    allow_ips   TEXT NOT NULL DEFAULT '',
    created     INTEGER NOT NULL,
    created_by  TEXT NOT NULL DEFAULT '',
    expires     INTEGER NOT NULL DEFAULT 0,
    last_used   INTEGER NOT NULL DEFAULT 0,
    last_ip     TEXT NOT NULL DEFAULT '',
    revoked     INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS api_log (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    ts          INTEGER NOT NULL,
    token_id    INTEGER NOT NULL,
    ip          TEXT NOT NULL DEFAULT '',
    method      TEXT NOT NULL,
    path        TEXT NOT NULL,
    status      INTEGER NOT NULL,
    ms          INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS api_log_ts ON api_log(ts);
CREATE INDEX IF NOT EXISTS api_log_token ON api_log(token_id);

INSERT OR IGNORE INTO settings (name, value) VALUES ('api-enabled', '0');

INSERT OR REPLACE INTO settings (name, value) VALUES ('db-version', '1042');
