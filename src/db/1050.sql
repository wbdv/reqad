-- Email on or off per addon site (app/functions/domains.php domain_mail_*).
-- The account's switch (accounts.has_email) stays the master: with it off no
-- site has mail. With it on, each addon domain or subdomain follows its row
-- here: what was chosen when the site was made (addon domains on, subdomains
-- off) or turned on/off since. A site without a row: on when it had mail
-- before (/etc/exim/domains/<domain>), else on for an addon domain and off
-- for a subdomain. A site whose row says on but which has no
-- /etc/exim/userdomains line is a problem (health check site_mail).

CREATE TABLE IF NOT EXISTS site_mail (
	domain  TEXT PRIMARY KEY,
	account TEXT NOT NULL,
	mail    INTEGER NOT NULL,
	changed INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS site_mail_account ON site_mail (account);

INSERT OR REPLACE INTO settings (name, value) VALUES ('db-version', '1050');
