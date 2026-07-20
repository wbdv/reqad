CREATE TABLE `settings` (
    `name` varchar(30) not null,
    `value` varchar(255) null,
    `updated_at` datetime not null default CURRENT_TIMESTAMP,
    primary key (`name`)
);
INSERT INTO settings VALUES('db-version','1020','2025-02-13 19:42:32');


CREATE TABLE `wordpress` (
    `id` integer not null primary key autoincrement,
    `user` varchar(20) not null,
    `domain` varchar(255) not null,
    `title` varchar(255) not null default "Default Wordpress Site",
    `wp_version` varchar(8) not null,
    `comments` TEXT null,
    `status` varchar(16) not null default active,
    `created_at` datetime not null default CURRENT_TIMESTAMP,
    unique (`id`)
);
