-- Additive schema 5: immutable, versioned account replay evidence.
-- Back up database and private media before activation. Keep prior SQL bundled.
-- Replace {{prefix}} only with the configured WordPress prefix for a reviewed manual install.
CREATE TABLE {{prefix}}tgit_replay_runs (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 account_id bigint unsigned NOT NULL,
 trigger_transaction_id bigint unsigned NOT NULL,
 trigger_action varchar(32) NOT NULL,
 source_fingerprint char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 calculation_version varchar(40) NOT NULL,
 projection_json longtext NOT NULL,
 actor_id bigint unsigned NOT NULL,
 created_at datetime NOT NULL,
 PRIMARY KEY  (id),
 KEY account_run (workspace_id,account_id,id),
 KEY trigger_scope (workspace_id,trigger_transaction_id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
