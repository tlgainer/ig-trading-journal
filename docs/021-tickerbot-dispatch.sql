-- Schema 21: bounded dispatch plans, batches and per-receipt response times.
-- Back up database/private media before reactivation. Retain all earlier SQL.
-- Forward repair: keep Tickerbot disabled and rerun the matching installer.
CREATE TABLE {{prefix}}tgit_tickerbot_plans (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 run_id bigint unsigned NOT NULL,
 settings_revision bigint unsigned NOT NULL,
 scope_revision bigint unsigned NOT NULL,
 inventory_json longtext NOT NULL,
 created_at datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY run_plan (workspace_id,run_id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE {{prefix}}tgit_tickerbot_batches (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 run_id bigint unsigned NOT NULL,
 plan_id bigint unsigned NOT NULL,
 ordinal int unsigned NOT NULL,
 symbols_json longtext NOT NULL,
 mappings_json longtext NOT NULL,
 state varchar(20) NOT NULL,
 attempts int unsigned NOT NULL DEFAULT 0,
 active_attempt_id bigint unsigned DEFAULT NULL,
 next_due_at datetime NOT NULL,
 outcome_json longtext DEFAULT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY run_batch (workspace_id,run_id,ordinal)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE {{prefix}}tgit_tickerbot_batch_attempts (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 run_id bigint unsigned NOT NULL,
 batch_id bigint unsigned NOT NULL,
 attempt_id bigint unsigned NOT NULL,
 ordinal int unsigned NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY attempt_link (workspace_id,attempt_id),
 UNIQUE KEY batch_slot (workspace_id,batch_id,ordinal)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE {{prefix}}tgit_tickerbot_receipt_times (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 receipt_id bigint unsigned NOT NULL,
 response_as_of datetime(6) DEFAULT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY receipt_time (workspace_id,receipt_id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
