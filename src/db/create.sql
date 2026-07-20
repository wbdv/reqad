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

CREATE TABLE `emails` (
	`id` integer not null primary key autoincrement,
	`email` varchar(255) not null,
	`disk_usage` INTEGER,
	`disk_quota` INTEGER,
	`status` varchar(10) NOT NULL,
    `created_at` datetime not null default CURRENT_TIMESTAMP,
    unique (`id`)
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
CREATE UNIQUE INDEX "emails_index_2" on "emails"("email" ASC);
CREATE UNIQUE INDEX "wordpress_index_2" on "wordpress"("user" ASC);
CREATE UNIQUE INDEX "wordpress_index_3" on "wordpress"("domain" ASC);
COMMIT;
