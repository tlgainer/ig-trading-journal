-- Schema 25: immutable mapping command bindings and selective outcome receipts.
-- Back up database/private media before reactivation; retain all prior migrations.
-- Forward repair keeps Tickerbot off and reruns the matching installer.
CREATE TABLE {{prefix}}tgit_tickerbot_mapping_commands (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 run_id bigint unsigned NOT NULL,
 asset_id bigint unsigned NOT NULL,
 provider_symbol varchar(80) NOT NULL,
 expected_mapping_id bigint unsigned NOT NULL,
 asset_fingerprint char(64) NOT NULL,
 created_at datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY mapping_command (workspace_id,run_id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE {{prefix}}tgit_tickerbot_mapping_results (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 run_id bigint unsigned NOT NULL,
 attempt_id bigint unsigned DEFAULT NULL,
 mapping_id bigint unsigned DEFAULT NULL,
 state varchar(30) NOT NULL,
 checked_at datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY mapping_result (workspace_id,run_id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
