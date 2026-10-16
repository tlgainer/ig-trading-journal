-- Schema 27: revisioned shared pause metadata and immutable owner recovery evidence.
-- Back up database/private media before reactivation; retain all prior SQL files.
-- Legacy pauses keep unknown reasons; no inferred provider evidence or usage reset.
-- Forward repair keeps Tickerbot off and reruns the matching installer.
ALTER TABLE {{prefix}}tgit_tickerbot_accounts ADD COLUMN pause_revision bigint unsigned NOT NULL DEFAULT 0;
ALTER TABLE {{prefix}}tgit_tickerbot_accounts ADD COLUMN pause_kind varchar(20) DEFAULT NULL;
ALTER TABLE {{prefix}}tgit_tickerbot_accounts ADD COLUMN recovery_not_before datetime DEFAULT NULL;
CREATE TABLE {{prefix}}tgit_tickerbot_pause_recoveries (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 account_id bigint unsigned NOT NULL,
 expected_revision bigint unsigned NOT NULL,
 actor_id bigint unsigned NOT NULL,
 prior_kind varchar(20) DEFAULT NULL,
 prior_until datetime NOT NULL,
 created_at datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY recovered_pause (account_id,expected_revision),
 KEY workspace_history (workspace_id,id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
