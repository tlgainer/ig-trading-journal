-- Schema 11: typed provider requests and append-only fundamental snapshots.
-- Back up database/private media; keep external processing disabled during upgrade.
-- Explicit reactivation upgrades 1-10. Forward repair: restore matching code and rerun installation.
-- Replace {{prefix}} for manual execution. Existing request rows receive dataset=quote.
CREATE TABLE {{prefix}}tgit_fundamental_snapshots (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 asset_id bigint unsigned NOT NULL,
 mapping_id bigint unsigned NOT NULL,
 request_id bigint unsigned NOT NULL,
 provider varchar(20) NOT NULL,
 provider_symbol varchar(80) NOT NULL,
 dataset varchar(30) NOT NULL,
 quote_currency char(3) NOT NULL,
 exchange varchar(80) NOT NULL,
 actor_id bigint unsigned NOT NULL,
 previous_snapshot_id bigint unsigned DEFAULT NULL,
 evidence_version varchar(20) NOT NULL,
 evidence_fingerprint char(64) NOT NULL,
 evidence_json longtext NOT NULL,
 retrieved_at datetime NOT NULL,
 published_at datetime DEFAULT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY request_scope (workspace_id,request_id),
 KEY asset_history (workspace_id,asset_id,dataset,id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- On manual repair, skip this ALTER only if SHOW COLUMNS confirms dataset already exists.
ALTER TABLE {{prefix}}tgit_provider_requests ADD COLUMN dataset varchar(30) NOT NULL DEFAULT 'quote';
