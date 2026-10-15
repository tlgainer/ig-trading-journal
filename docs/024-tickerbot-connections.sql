-- Schema 24: immutable explicit connection-test receipts; no provider bodies/keys.
-- Back up database/private media before reactivation; retain all earlier SQL files.
-- Forward repair keeps Tickerbot gates off and reruns the matching installer.
CREATE TABLE {{prefix}}tgit_tickerbot_connections (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 run_id bigint unsigned NOT NULL,
 attempt_id bigint unsigned DEFAULT NULL,
 credential_digest char(64) NOT NULL,
 state varchar(30) NOT NULL,
 checked_at datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY connection_run (workspace_id,run_id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
