-- Web login, part 3: new devices.
--
-- auth_devices     now one row per browser that logged in (the
--                  __Host-reqad_dev cookie, 180 days), not only the ones
--                  that skip the 2FA code: trusted_until > now marks those.
--                  A browser without a row is a "new device".
-- auth_challenges  emailed 6-digit codes, stored hashed, one per session and
--                  purpose: 'login' = confirm a login from a new device
--                  (session state 'confirm'), 'verify' = prove an email
--                  address before codes are sent there (target = address).
--                  The 1037 table was never used, so it is recreated.
-- (auth_ip_allow scope 'app' was planned for addresses that skip the code - dropped.)
--
-- settings: auth-email-code (1 = new devices need the code), auth-login-alert
-- (1 = email after each login from a new device).

DROP TABLE IF EXISTS auth_challenges;
CREATE TABLE auth_challenges (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id      TEXT NOT NULL,                      -- auth_sessions.id
    purpose         TEXT NOT NULL,                      -- 'login' | 'verify'
    code_hash       TEXT NOT NULL,
    target          TEXT NOT NULL DEFAULT '',
    remember        INTEGER NOT NULL DEFAULT 0,         -- login: also skip 2FA on this device
    attempts        INTEGER NOT NULL DEFAULT 0,
    sends           INTEGER NOT NULL DEFAULT 1,
    sent_at         INTEGER NOT NULL,
    expires_at      INTEGER NOT NULL,
    UNIQUE (session_id, purpose)
);

INSERT OR REPLACE INTO settings (name, value) VALUES ('db-version', '1039');
