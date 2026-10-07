-- Schema 17: immutable server AI response receipts, separate from review publication.
-- Back up database and private media; keep external processing disabled for upgrade.
-- Package all seventeen SQL files and reactivate. Forward repair reruns the installer.
-- Preserve receipts, requests, budget events and reviews; no purge or provenance backfill.
-- Manual execution: replace {{prefix}} with the configured WordPress table prefix.
CREATE TABLE {{prefix}}tgit_ai_response_receipts (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 request_id bigint unsigned NOT NULL,
 approval_id bigint unsigned NOT NULL,
 actor_id bigint unsigned NOT NULL,
 version varchar(30) NOT NULL,
 result_fingerprint char(64) NOT NULL,
 result_json longtext NOT NULL,
 created_at datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY receipt_identity (workspace_id,request_id,result_fingerprint),
 KEY request_receipts (workspace_id,request_id,id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
