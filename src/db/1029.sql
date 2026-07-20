ALTER TABLE wordpress ADD COLUMN path varchar(255) not null default '';
DROP INDEX "wordpress_index_2";
DROP INDEX "wordpress_index_3";
CREATE UNIQUE INDEX "wordpress_index_4" on "wordpress"("domain" ASC, "path" ASC);
UPDATE settings SET value='1029', updated_at=datetime('now') WHERE name='db-version';
