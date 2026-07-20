CREATE TABLE `errors` (`id` integer not null primary key autoincrement, `date` datetime not null, `email` varchar(100) not null, `errmsg` varchar(255) not null, unique (`id`));
