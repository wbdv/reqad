-- Health checks: what needs attention on the server and in each account
-- (app/functions/health.php, the dashboard's "Needs attention" card,
-- `reqad health`).
--
-- health_items      the result of the last run of each check, one row per
--                   problem. id is stable across runs (check:account:subject),
--                   so first_seen survives and a dismissal keeps applying.
--                   account '' = the server itself.
-- health_dismissed  items hidden from the card: until = unix time, 0 = for good.
--                   Rows stay when the problem goes away, so a dismissed
--                   problem that comes back stays hidden until `until`.

CREATE TABLE IF NOT EXISTS health_items (
    id          TEXT PRIMARY KEY,
    check_id    TEXT NOT NULL,
    account     TEXT NOT NULL DEFAULT '',
    subject     TEXT NOT NULL DEFAULT '',
    severity    TEXT NOT NULL,
    title       TEXT NOT NULL,
    detail      TEXT NOT NULL DEFAULT '',
    fix_url     TEXT NOT NULL DEFAULT '',
    fix_label   TEXT NOT NULL DEFAULT '',
    dashboard   INTEGER NOT NULL DEFAULT 1,
    first_seen  INTEGER NOT NULL,
    last_seen   INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS health_items_account ON health_items(account);
CREATE INDEX IF NOT EXISTS health_items_check ON health_items(check_id);

CREATE TABLE IF NOT EXISTS health_dismissed (
    id          TEXT PRIMARY KEY,
    until       INTEGER NOT NULL DEFAULT 0,
    by_user     TEXT NOT NULL DEFAULT '',
    created     INTEGER NOT NULL
);

INSERT OR REPLACE INTO settings (name, value) VALUES ('db-version', '1041');
