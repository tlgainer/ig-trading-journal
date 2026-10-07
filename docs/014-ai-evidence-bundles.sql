-- Schema 14: immutable owner-approved summary evidence.
-- Back up database/private media and disable external processing before activation.
-- Keep all fourteen bundled migrations. Forward repair repeats explicit activation.
-- Preserve approvals and their source snapshots/journal revisions; never purge history.
-- Manual execution: replace {{prefix}} with the configured WordPress prefix.
CREATE TABLE {{prefix}}tgit_ai_evidence_bundles (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 asset_id bigint unsigned NOT NULL,
 actor_id bigint unsigned NOT NULL,
 version varchar(40) NOT NULL,
 fingerprint char(64) NOT NULL,
 bundle_json longtext NOT NULL,
 created_at datetime NOT NULL,
 PRIMARY KEY  (id),
 KEY evidence_scope (workspace_id,asset_id,id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
