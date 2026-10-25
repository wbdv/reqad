-- Node.js / Python apps (app/functions/apps.php, Accounts > Deployments, `reqad app`).
--
-- One app per domain (main or addon domain of an account). It runs as the
-- account in its own systemd unit reqad-app-<id> (root writes the unit,
-- the process is the account's), listens on 127.0.0.1:<port> (30000-39999,
-- reachable only by its own account and nginx - setup_panel_loopback_guard.sh),
-- and the domain's nginx vhost proxies to it.
--
--   runtime/version  node + major ("24") | python + "3.12"  (scripts/apps/app_runtime.sh)
--   app_dir          '' = the deployment's ~/apps/<domain>/current when the
--                    domain is deployed from Git, else ~/apps/<domain>/app;
--                    or a path relative to the account's home
--   start_cmd        run in app_dir with PORT and HOST set
--   serve_static     nginx serves existing files from the document root first
--   php_upstream     the PHP handler the vhost had, to put it back on removal
--                    ('' = none, 'off' = PHP was disabled)
--   enabled          0 = stopped on purpose (not started at boot)

CREATE TABLE IF NOT EXISTS apps (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    account       TEXT NOT NULL,
    domain        TEXT NOT NULL UNIQUE,
    runtime       TEXT NOT NULL,
    version       TEXT NOT NULL,
    app_dir       TEXT NOT NULL DEFAULT '',
    start_cmd     TEXT NOT NULL,
    port          INTEGER NOT NULL UNIQUE,
    serve_static  INTEGER NOT NULL DEFAULT 0,
    memory_mb     INTEGER NOT NULL DEFAULT 512,
    php_upstream  TEXT NOT NULL DEFAULT '',
    enabled       INTEGER NOT NULL DEFAULT 1,
    created       INTEGER NOT NULL,
    created_by    TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS apps_account ON apps(account);

INSERT OR REPLACE INTO settings (name, value) VALUES ('db-version', '1044');
