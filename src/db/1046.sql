-- Webmail auto-login sessions end with the panel session that opened them.
--
-- webmail_tickets.session_id is the auth_sessions.id of the panel login that
-- minted the ticket ('' in basic-auth mode, which has no panel sessions). The
-- Roundcube plugin copies it into the webmail session and, on every request,
-- logs the mailbox out once that panel session is gone: logout, revoked,
-- expired or idle.

ALTER TABLE webmail_tickets ADD COLUMN session_id TEXT NOT NULL DEFAULT '';

INSERT OR REPLACE INTO settings (name, value) VALUES ('db-version', '1046');
