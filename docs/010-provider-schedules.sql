-- Schema 10: append-only, explicitly owner-authorized weekday refresh enrollment.
-- Back up the database and private media before explicit reactivation.
-- Forward repair: disable processing, restore matching code and rerun installation.
-- Preserve schedule revisions and provider request/quote evidence; never downgrade markers.
-- Manual execution: replace {{prefix}} with the configured WordPress prefix.
CREATE TABLE {{prefix}}tgit_provider_schedules (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 mapping_id bigint unsigned NOT NULL,
 actor_id bigint unsigned NOT NULL,
 frequency varchar(10) NOT NULL,
 created_at datetime NOT NULL,
 PRIMARY KEY  (id),
 KEY schedule_scope (workspace_id,mapping_id,id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
