-- Git deployments (app/functions/deploy.php, Accounts > Deployments,
-- `reqad deploy`, /api/v1/deployments).
--
-- deployments  one per domain (main or addon domain of an account). Each
--              deploy exports the branch into ~/apps/<domain>/releases/<time>,
--              links the shared paths, runs the build as the account and
--              points ~/apps/<domain>/current at it; the domain's document
--              root is a symlink to current[/<public_dir>].
--              webhook_secret signs the push webhooks (kept readable: the
--              HMAC needs it). repo_url may carry an HTTPS token - never shown.
-- deploy_runs  one row per deploy or rollback. source: manual | webhook |
--              cli | api | rollback. status: queued | running | ok | failed |
--              skipped (a newer queued run replaced it). The output is in
--              /var/log/reqad/deploy/<id>.log.

CREATE TABLE IF NOT EXISTS deployments (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    account         TEXT NOT NULL,
    domain          TEXT NOT NULL UNIQUE,
    repo_url        TEXT NOT NULL,
    branch          TEXT NOT NULL DEFAULT 'main',
    public_dir      TEXT NOT NULL DEFAULT '',
    build_cmd       TEXT NOT NULL DEFAULT '',
    shared_paths    TEXT NOT NULL DEFAULT '',
    keep_releases   INTEGER NOT NULL DEFAULT 5,
    health_path     TEXT NOT NULL DEFAULT '',
    auto_deploy     INTEGER NOT NULL DEFAULT 1,
    webhook_secret  TEXT NOT NULL,
    current_release TEXT NOT NULL DEFAULT '',
    current_commit  TEXT NOT NULL DEFAULT '',
    last_status     TEXT NOT NULL DEFAULT '',
    last_run_id     INTEGER NOT NULL DEFAULT 0,
    created         INTEGER NOT NULL,
    created_by      TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS deployments_account ON deployments(account);

CREATE TABLE IF NOT EXISTS deploy_runs (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    deployment_id   INTEGER NOT NULL,
    source          TEXT NOT NULL,
    by_user         TEXT NOT NULL DEFAULT '',
    target          TEXT NOT NULL DEFAULT '',
    status          TEXT NOT NULL DEFAULT 'queued',
    commit_sha      TEXT NOT NULL DEFAULT '',
    commit_msg      TEXT NOT NULL DEFAULT '',
    release_name    TEXT NOT NULL DEFAULT '',
    message         TEXT NOT NULL DEFAULT '',
    queued          INTEGER NOT NULL,
    started         INTEGER NOT NULL DEFAULT 0,
    finished        INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS deploy_runs_dep ON deploy_runs(deployment_id, id);

INSERT OR REPLACE INTO settings (name, value) VALUES ('db-version', '1043');
