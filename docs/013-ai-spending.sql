-- Schema 13: persistent, disabled-by-default AI spending coordination.
-- Back up database/private media; disable external processing before reactivation.
-- Forward repair reruns the additive installer with all thirteen bundled SQL files.
-- Preserve pool identity, configurations, enrollments, requests and events.
-- Never delete budget history or rotate a credential to reset the allowance.
-- Manual execution: replace {{prefix}} with the configured WordPress prefix.
CREATE TABLE {{prefix}}tgit_ai_pools (
 id bigint unsigned NOT NULL,
 controller_workspace_id bigint unsigned NOT NULL,
 created_at datetime NOT NULL,
 PRIMARY KEY  (id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE {{prefix}}tgit_ai_configs (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 actor_id bigint unsigned NOT NULL,
 enabled tinyint unsigned NOT NULL,
 monthly_cap decimal(38,12) NOT NULL,
 model varchar(128) NOT NULL,
 pricing_json longtext NULL,
 pricing_fingerprint char(64) NULL,
 created_at datetime NOT NULL,
 PRIMARY KEY  (id),
 KEY config_scope (workspace_id,id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE {{prefix}}tgit_ai_enrollments (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 actor_id bigint unsigned NOT NULL,
 enabled tinyint unsigned NOT NULL,
 created_at datetime NOT NULL,
 PRIMARY KEY  (id),
 KEY enrollment_scope (workspace_id,id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE {{prefix}}tgit_ai_requests (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 actor_id bigint unsigned NOT NULL,
 config_id bigint unsigned NOT NULL,
 enrollment_id bigint unsigned NOT NULL,
 credential_fingerprint char(64) NOT NULL,
 request_key char(64) NOT NULL,
 input_fingerprint char(64) NOT NULL,
 model varchar(128) NOT NULL,
 pricing_json longtext NOT NULL,
 pricing_fingerprint char(64) NOT NULL,
 input_tokens int unsigned NOT NULL,
 output_tokens int unsigned NOT NULL,
 maximum_cost decimal(38,12) NOT NULL,
 budget_period char(7) NOT NULL,
 created_at datetime NOT NULL,
 expires_at datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY request_identity (workspace_id,request_key),
 KEY period_budget (budget_period,id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE {{prefix}}tgit_ai_request_events (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 request_id bigint unsigned NOT NULL,
 actor_id bigint unsigned NOT NULL,
 state varchar(16) NOT NULL,
 charge decimal(38,12) NULL,
 usage_json longtext NULL,
 created_at datetime NOT NULL,
 PRIMARY KEY  (id),
 KEY request_scope (workspace_id,request_id,id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
