-- Schema 18: immutable complete AI execution plans and verified input-count bounds.
-- Back up database and private media; keep external processing disabled.
-- Package all eighteen migrations and reactivate for additive forward repair.
-- Preserve existing requests/receipts; do not fabricate plans for older requests.
-- Manual execution: replace {{prefix}} with the configured WordPress prefix.
CREATE TABLE {{prefix}}tgit_ai_execution_manifests (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 request_id bigint unsigned NOT NULL,
 actor_id bigint unsigned NOT NULL,
 approval_id bigint unsigned NOT NULL,
 fingerprint char(64) NOT NULL,
 payload_json longtext NOT NULL,
 created_at datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY workspace_request (workspace_id,request_id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;
