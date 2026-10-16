-- Schema 28: explicit reversible workspace pricing policy and frozen plan policy fence.
-- Back up database/private media before activation. Keep all prior migrations.
-- Forward repair: keep provider/worker flags off, rerun the matching installer.
-- Rollback uses an appended policy revision, never deletion or an older plugin/schema.
CREATE TABLE {{prefix}}tgit_pricing_policies (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 revision bigint unsigned NOT NULL,
 source varchar(20) NOT NULL,
 previous_source varchar(20) NOT NULL,
 account_id bigint unsigned DEFAULT NULL,
 settings_revision bigint unsigned NOT NULL DEFAULT 0,
 scope_revision bigint unsigned NOT NULL DEFAULT 0,
 actor_id bigint unsigned NOT NULL,
 created_at datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY workspace_revision (workspace_id,revision)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE {{prefix}}tgit_tickerbot_plan_policies (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 plan_id bigint unsigned NOT NULL,
 policy_revision bigint unsigned NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY plan_policy (workspace_id,plan_id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
