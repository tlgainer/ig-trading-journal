-- Schema 22: explicit recurring intent and immutable run-to-enrollment binding.
-- Back up database/private media before activation; retain earlier SQL files.
-- Forward repair: keep all Tickerbot processing off and rerun matching installer.
CREATE TABLE {{prefix}}tgit_tickerbot_cadences (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 revision bigint unsigned NOT NULL,
 enabled tinyint unsigned NOT NULL,
 actor_id bigint unsigned NOT NULL,
 settings_revision bigint unsigned NOT NULL,
 scope_revision bigint unsigned NOT NULL,
 created_at datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY workspace_revision (workspace_id,revision)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE {{prefix}}tgit_tickerbot_run_cadences (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 run_id bigint unsigned NOT NULL,
 cadence_id bigint unsigned NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY run_cadence (workspace_id,run_id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
