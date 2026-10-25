-- Web login for the panel (replaces nginx basic auth when the panel vhost is
-- switched with `reqad auth mode web`; see app/auth/auth.php).
--
-- auth_principals  who can log in. type 'admin' is the 2087 panel; 'account'
--                  is reserved for the per-account panel. pw_hash is bcrypt,
--                  or a $6$ hash imported from nginx_auth until the first
--                  login rehashes it. totp_* is RFC 6238 two-factor auth;
--                  totp_last_step blocks replaying a code inside its window.
-- auth_sessions    one row per login. id is sha256(cookie token) - the token
--                  itself is never stored. state 'pending' = password ok,
--                  waiting for the second factor; only 'active' rows pass.
-- auth_recovery_codes  single-use 2FA backup codes, stored hashed.
-- auth_attempts    every login attempt, for lockouts and the security log.
-- auth_bans        IPs this panel handed to csf (level 1 = temporary,
--                  2 = permanent).
-- auth_log         security events (login, logout, 2FA, password, bans).
-- auth_devices, auth_ip_allow, auth_challenges: known devices, allowlists and
--                  emailed confirmation codes (used by the next step).

CREATE TABLE IF NOT EXISTS auth_principals (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    type            TEXT NOT NULL DEFAULT 'admin',      -- 'admin' | 'account'
    username        TEXT NOT NULL,
    pw_hash         TEXT NOT NULL DEFAULT '',
    email           TEXT NOT NULL DEFAULT '',
    totp_secret     TEXT NOT NULL DEFAULT '',
    totp_pending    TEXT NOT NULL DEFAULT '',           -- secret shown during enrollment
    totp_enabled    INTEGER NOT NULL DEFAULT 0,
    totp_last_step  INTEGER NOT NULL DEFAULT 0,
    status          TEXT NOT NULL DEFAULT 'active',     -- 'active' | 'disabled'
    pw_changed_at   INTEGER NOT NULL DEFAULT 0,
    created_at      INTEGER NOT NULL,
    UNIQUE (type, username)
);

CREATE TABLE IF NOT EXISTS auth_sessions (
    id              TEXT PRIMARY KEY,                   -- sha256 of the cookie token
    principal_id    INTEGER NOT NULL,
    state           TEXT NOT NULL DEFAULT 'active',     -- 'pending' | 'active'
    csrf            TEXT NOT NULL,
    ip              TEXT NOT NULL DEFAULT '',
    ua              TEXT NOT NULL DEFAULT '',
    created_at      INTEGER NOT NULL,
    last_seen       INTEGER NOT NULL,
    expires_at      INTEGER NOT NULL,
    mfa             INTEGER NOT NULL DEFAULT 0,         -- 1 = second factor passed
    device_id       INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS auth_sessions_principal ON auth_sessions(principal_id);

CREATE TABLE IF NOT EXISTS auth_recovery_codes (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    principal_id    INTEGER NOT NULL,
    code_hash       TEXT NOT NULL,
    used_at         INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS auth_recovery_principal ON auth_recovery_codes(principal_id);

CREATE TABLE IF NOT EXISTS auth_attempts (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    ts              INTEGER NOT NULL,
    ip              TEXT NOT NULL,
    username        TEXT NOT NULL DEFAULT '',
    outcome         TEXT NOT NULL                       -- ok | bad_password | unknown_user | bad_2fa | locked
);
CREATE INDEX IF NOT EXISTS auth_attempts_ip ON auth_attempts(ip, ts);
CREATE INDEX IF NOT EXISTS auth_attempts_user ON auth_attempts(username, ts);

CREATE TABLE IF NOT EXISTS auth_bans (
    ip              TEXT PRIMARY KEY,
    level           INTEGER NOT NULL DEFAULT 1,         -- 1 = temporary, 2 = permanent
    until           INTEGER NOT NULL DEFAULT 0,         -- unix time, 0 = permanent
    count           INTEGER NOT NULL DEFAULT 1,         -- temporary bans so far
    reason          TEXT NOT NULL DEFAULT '',
    created_at      INTEGER NOT NULL,
    updated_at      INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS auth_log (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    ts              INTEGER NOT NULL,
    principal_id    INTEGER NOT NULL DEFAULT 0,
    username        TEXT NOT NULL DEFAULT '',
    ip              TEXT NOT NULL DEFAULT '',
    event           TEXT NOT NULL,
    detail          TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS auth_log_ts ON auth_log(ts);

CREATE TABLE IF NOT EXISTS auth_devices (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    principal_id    INTEGER NOT NULL,
    token_hash      TEXT NOT NULL UNIQUE,
    label           TEXT NOT NULL DEFAULT '',
    first_seen      INTEGER NOT NULL,
    last_seen       INTEGER NOT NULL,
    last_ip         TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS auth_ip_allow (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    cidr            TEXT NOT NULL,
    label           TEXT NOT NULL DEFAULT '',
    scope           TEXT NOT NULL DEFAULT 'app',        -- 'app' | 'firewall'
    created_at      INTEGER NOT NULL,
    UNIQUE (cidr, scope)
);

CREATE TABLE IF NOT EXISTS auth_challenges (
    id              TEXT PRIMARY KEY,                   -- sha256 of the code
    session_id      TEXT NOT NULL,
    purpose         TEXT NOT NULL DEFAULT 'new_device',
    expires_at      INTEGER NOT NULL,
    attempts        INTEGER NOT NULL DEFAULT 0
);

INSERT OR REPLACE INTO settings (name, value) VALUES ('db-version', '1037');
