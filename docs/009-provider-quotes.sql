-- Schema 9: provider identity evidence, append-only quotes and shared request quotas.
-- Back up database/private media before explicit reactivation; additive only.
-- Forward repair: keep processing disabled, restore matching code, rerun installation.
-- dbDelta recreates missing tables/indexes; inspect partial tables before enabling jobs.
-- Manual execution: replace {{prefix}} with the configured WordPress prefix.
CREATE TABLE {{prefix}}tgit_provider_mappings (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 asset_id bigint unsigned NOT NULL,
 provider varchar(20) NOT NULL,
 provider_symbol varchar(80) NOT NULL,
 exchange varchar(80) NOT NULL,
 currency char(3) NOT NULL,
 enabled tinyint unsigned NOT NULL,
 evidence varchar(500) NOT NULL,
 actor_id bigint unsigned NOT NULL,
 created_at datetime NOT NULL,
 PRIMARY KEY  (id),
 KEY identity_scope (workspace_id,asset_id,provider,id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE {{prefix}}tgit_provider_pools (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 provider varchar(20) NOT NULL,
 credential_fingerprint char(64) NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY credential_scope (provider,credential_fingerprint)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE {{prefix}}tgit_provider_requests (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 pool_id bigint unsigned NOT NULL,
 workspace_id bigint unsigned NOT NULL,
 mapping_id bigint unsigned NOT NULL,
 actor_id bigint unsigned NOT NULL,
 request_key varchar(80) NOT NULL,
 state varchar(20) NOT NULL,
 created_at datetime NOT NULL,
 dispatched_at datetime DEFAULT NULL,
 completed_at datetime DEFAULT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY retry_scope (pool_id,workspace_id,request_key),
 KEY rolling_quota (pool_id,created_at),
 KEY workspace_scope (workspace_id,id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE {{prefix}}tgit_provider_quotes (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 asset_id bigint unsigned NOT NULL,
 mapping_id bigint unsigned NOT NULL,
 request_id bigint unsigned NOT NULL,
 provider varchar(20) NOT NULL,
 provider_symbol varchar(80) NOT NULL,
 exchange varchar(80) NOT NULL,
 currency char(3) NOT NULL,
 price decimal(38,18) NOT NULL,
 previous_close decimal(38,18) NOT NULL,
 session_date date NOT NULL,
 freshness varchar(20) NOT NULL,
 retrieved_at datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY request_scope (workspace_id,request_id),
 KEY asset_session (workspace_id,asset_id,session_date,id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
