-- Schema 19: isolated Tickerbot state; no ledger or historical FX changes.
-- Back up the database and private images before explicit reactivation.
-- Forward repair: keep Tickerbot disabled, restore matching code and reactivate.
-- Installer reruns additive CREATE statements to repair missing tables/indexes.
-- Manual execution requires replacing {{prefix}} with the configured table prefix.
CREATE TABLE {{prefix}}tgit_tickerbot_accounts (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 scope_digest char(64) NOT NULL,
 controller_workspace_id bigint unsigned NOT NULL,
 monthly_cap int unsigned NOT NULL,
 active_run_id bigint unsigned DEFAULT NULL,
 active_workspace_id bigint unsigned DEFAULT NULL,
 lease_until datetime DEFAULT NULL,
 paused_until datetime DEFAULT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY account_scope (scope_digest)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE {{prefix}}tgit_tickerbot_settings (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 account_id bigint unsigned NOT NULL,
 interval_seconds int unsigned NOT NULL,
 next_due_at datetime NOT NULL,
 last_manual_at datetime DEFAULT NULL,
 revision bigint unsigned NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY workspace_scope (workspace_id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE {{prefix}}tgit_tickerbot_runs (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 account_id bigint unsigned NOT NULL,
 actor_id bigint unsigned NOT NULL,
 request_key varchar(80) NOT NULL,
 cause varchar(20) NOT NULL,
 state varchar(20) NOT NULL,
 lease_token char(64) NOT NULL,
 lease_until datetime NOT NULL,
 started_at datetime NOT NULL,
 completed_at datetime DEFAULT NULL,
 correlation_id varchar(64) NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY request_scope (workspace_id,request_key),
 KEY account_runs (account_id,state,id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE {{prefix}}tgit_tickerbot_attempts (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 account_id bigint unsigned NOT NULL,
 run_id bigint unsigned NOT NULL,
 family varchar(20) NOT NULL,
 symbols_json longtext NOT NULL,
 state varchar(30) NOT NULL,
 created_at datetime NOT NULL,
 completed_at datetime DEFAULT NULL,
 PRIMARY KEY  (id),
 KEY account_usage (account_id,created_at),
 KEY run_scope (workspace_id,run_id,id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE {{prefix}}tgit_tickerbot_mappings (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 asset_id bigint unsigned NOT NULL,
 provider_symbol varchar(80) NOT NULL,
 asset_class varchar(20) NOT NULL,
 exchange varchar(80) NOT NULL,
 currency char(3) NOT NULL,
 actor_id bigint unsigned NOT NULL,
 checked_at datetime NOT NULL,
 PRIMARY KEY  (id),
 KEY current_mapping (workspace_id,asset_id,id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE {{prefix}}tgit_tickerbot_quotes (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 asset_id bigint unsigned NOT NULL,
 mapping_id bigint unsigned NOT NULL,
 fingerprint char(64) NOT NULL,
 price decimal(38,18) NOT NULL,
 currency char(3) NOT NULL,
 price_source varchar(30) NOT NULL,
 source_time_semantics varchar(30) NOT NULL,
 price_asof datetime(6) DEFAULT NULL,
 response_as_of datetime(6) DEFAULT NULL,
 fetched_at datetime(6) NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY mark_identity (workspace_id,mapping_id,fingerprint),
 KEY latest_asset (workspace_id,asset_id,id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE {{prefix}}tgit_tickerbot_quote_receipts (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 attempt_id bigint unsigned NOT NULL,
 mapping_id bigint unsigned NOT NULL,
 quote_id bigint unsigned NOT NULL,
 fetched_at datetime(6) NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY attempt_mark (workspace_id,attempt_id,mapping_id),
 KEY mapping_fetch (workspace_id,mapping_id,fetched_at,id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE {{prefix}}tgit_tickerbot_policy_revisions (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 account_id bigint unsigned NOT NULL,
 actor_id bigint unsigned NOT NULL,
 interval_seconds int unsigned NOT NULL,
 monthly_cap int unsigned NOT NULL,
 clear_pause tinyint unsigned NOT NULL,
 created_at datetime NOT NULL,
 PRIMARY KEY  (id),
 KEY policy_history (workspace_id,id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
