-- Created: Feb 21, 2025
-- Reqad Version:	1.0.20 (Feb 13, 2025)

BEGIN TRANSACTION;
CREATE TABLE `accounts` (
    `id` integer not null primary key autoincrement,
    `user` varchar(30) not null,
    `domain` varchar(255) not null,
    `disk_usage` INTEGER,
    `disk_quota` INTEGER,
    `has_email` BOOLEAN not null default "false",
    `status` varchar(16) not null default "active",
    `created_at` datetime not null default CURRENT_TIMESTAMP,
    `dkim_selector` VARCHAR(64) NOT NULL DEFAULT 'default',
    unique (`id`)
);

-- Cache of the per-mailbox du -skm figure, keyed by address. NOT an inventory:
-- the list of mailboxes is /etc/dovecot/users (mailbox_list() in functions.php),
-- so a row for a mailbox that no longer exists is inert. A missing row means
-- "not measured yet" and the caller measures live and writes back. See
-- db/1032.sql for why the old id/disk_quota/status/created_at columns went away.
CREATE TABLE `emails` (
    email      TEXT NOT NULL PRIMARY KEY,
    disk_usage INTEGER NOT NULL DEFAULT 0,
    updated_at DATETIME
);

CREATE TABLE `settings` (
    `name` varchar(30) not null,
    `value` varchar(255) null,
    `updated_at` datetime not null default CURRENT_TIMESTAMP,
    primary key (`name`)
);
INSERT INTO settings VALUES('db-version','1020','2025-02-13 19:42:32');
INSERT INTO settings VALUES('dns-provider',NULL,'2025-02-13 19:42:32');

CREATE TABLE `wordpress` (
    `id` integer not null primary key autoincrement,
    `user` varchar(30) not null,
    `domain` varchar(255) not null,
    `title` varchar(255) not null default "Default Wordpress Site",
    `wp_version` varchar(8) not null,
    `comments` TEXT null,
    `status` varchar(16) not null default "active",
    `created_at` datetime not null default CURRENT_TIMESTAMP,
    unique (`id`)
);

CREATE UNIQUE INDEX "accounts_index_2" on "accounts"("user" ASC);
CREATE UNIQUE INDEX "accounts_index_3" on "accounts"("domain" ASC);
CREATE UNIQUE INDEX "wordpress_index_2" on "wordpress"("user" ASC);
CREATE UNIQUE INDEX "wordpress_index_3" on "wordpress"("domain" ASC);
COMMIT;
