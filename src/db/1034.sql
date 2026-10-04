-- SMTP delivery failures move out of the database and into a log file.
--
-- The `errors` table was written to every minute by scripts/exim_error_parser.php
-- and read by exactly one page. Its contents were never app state: each row was
-- re-derived from a line of exim's main.log, so the table was a second copy of a
-- log that already exists, one that grew without bound and put the panel
-- database under constant write traffic to hold it.
--
-- They are collected in log/smtp_errors.log now. The rows still in the table are
-- rescued first by scripts/update/migrate_smtp_errors_log.sh, which the
-- post-install script runs before this migration -- some of them predate the
-- current main.log and could not be re-derived otherwise.

DROP TABLE IF EXISTS errors;

INSERT OR REPLACE INTO settings (name, value) VALUES ('db-version', '1034');
