-- Web login, part 2.
--
-- auth_sessions.sudo_until  "sudo mode": until this unix time the session may
--                           make sensitive changes (password, 2FA, panel
--                           firewall, unblocking IPs) without confirming the
--                           password again. Set at login and by the
--                           password (+2FA) confirmation dialog; 10 minutes.
-- auth_devices.trusted_until  a browser that passed 2FA with "don't ask again
--                           on this device" skips the code until then (7 days).
--                           The token lives in the __Host-reqad_dev cookie;
--                           only its sha256 is stored. Password change, 2FA
--                           off/on and "Forget" on the Security page end it.

ALTER TABLE auth_sessions ADD COLUMN sudo_until INTEGER NOT NULL DEFAULT 0;
ALTER TABLE auth_devices  ADD COLUMN trusted_until INTEGER NOT NULL DEFAULT 0;
CREATE INDEX IF NOT EXISTS auth_devices_principal ON auth_devices(principal_id);

INSERT OR REPLACE INTO settings (name, value) VALUES ('db-version', '1038');
