CREATE TABLE IF NOT EXISTS autoresponders (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    user      TEXT NOT NULL,
    domain    TEXT NOT NULL,
    subject   TEXT NOT NULL DEFAULT '',
    message   TEXT NOT NULL DEFAULT '',
    date_from TEXT NOT NULL DEFAULT '',
    date_to   TEXT NOT NULL DEFAULT '',
    created_at DATETIME DEFAULT (datetime('now')),
    UNIQUE(user, domain)
);
INSERT OR REPLACE INTO settings (name, value) VALUES ('db-version', '1028');
