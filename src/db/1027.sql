ALTER TABLE accounts ADD COLUMN dkim_selector VARCHAR(64) NOT NULL DEFAULT 'default';
UPDATE settings SET value = '1027' WHERE name = 'db-version';
