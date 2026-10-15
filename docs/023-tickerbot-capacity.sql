-- Schema 23: scalar shared-account cadence capacity, separate from request usage.
-- Back up database/private media before reactivation. Keep every earlier SQL file.
-- Forward repair keeps Tickerbot off and reruns the matching installer.
CREATE TABLE {{prefix}}tgit_tickerbot_capacity (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 account_id bigint unsigned NOT NULL,
 cadence_id bigint unsigned NOT NULL,
 settings_revision bigint unsigned NOT NULL,
 scope_revision bigint unsigned NOT NULL,
 projected_calls int unsigned NOT NULL,
 actor_id bigint unsigned NOT NULL,
 updated_at datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY workspace_capacity (workspace_id),
 KEY account_capacity (account_id,projected_calls)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE {{prefix}}tgit_tickerbot_capacity_revisions (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 account_id bigint unsigned NOT NULL,
 cadence_id bigint unsigned NOT NULL,
 settings_revision bigint unsigned NOT NULL,
 scope_revision bigint unsigned NOT NULL,
 projected_calls int unsigned NOT NULL,
 actor_id bigint unsigned NOT NULL,
 created_at datetime NOT NULL,
 PRIMARY KEY  (id),
 KEY capacity_history (workspace_id,id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
