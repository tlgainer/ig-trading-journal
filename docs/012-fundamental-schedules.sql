-- Schema 12: explicitly owner-authorized weekly fundamental dataset enrollment.
-- Back up database and private media before explicit reactivation.
-- Forward repair: disable processing, restore matching code and rerun installation.
-- Preserve enrollment revisions and provider evidence; never downgrade markers.
-- Manual execution: replace {{prefix}} with the configured WordPress prefix.
CREATE TABLE {{prefix}}tgit_fundamental_schedules (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 mapping_id bigint unsigned NOT NULL,
 actor_id bigint unsigned NOT NULL,
 dataset varchar(30) NOT NULL,
 frequency varchar(10) NOT NULL,
 weekday tinyint unsigned NOT NULL,
 created_at datetime NOT NULL,
 PRIMARY KEY  (id),
 KEY schedule_scope (workspace_id,mapping_id,dataset,id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
