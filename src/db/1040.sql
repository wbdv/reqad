-- Web login, part 4: passkeys (WebAuthn).
--
-- auth_passkeys  one row per registered passkey. credential_id is the
--                authenticator's id (base64url), public_key its PEM public
--                key; the private key never leaves the device. sign_count
--                detects cloned security keys (synced passkeys report 0).
-- auth_principals.webauthn_user  random user handle given to authenticators
--                (hex); it says nothing about the user name.
-- The WebAuthn challenges ride in auth_challenges (purpose 'passkey-*').

CREATE TABLE IF NOT EXISTS auth_passkeys (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    principal_id    INTEGER NOT NULL,
    credential_id   TEXT NOT NULL UNIQUE,
    public_key      TEXT NOT NULL,
    sign_count      INTEGER NOT NULL DEFAULT 0,
    name            TEXT NOT NULL DEFAULT '',
    aaguid          TEXT NOT NULL DEFAULT '',
    created_at      INTEGER NOT NULL,
    last_used       INTEGER NOT NULL DEFAULT 0,
    last_ip         TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS auth_passkeys_principal ON auth_passkeys(principal_id);

ALTER TABLE auth_principals ADD COLUMN webauthn_user TEXT NOT NULL DEFAULT '';

INSERT OR REPLACE INTO settings (name, value) VALUES ('db-version', '1040');
