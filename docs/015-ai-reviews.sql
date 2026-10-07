-- Schema 15: immutable validated AI review output linked to approved evidence.
-- Back up database/private media; keep external processing disabled.
-- Bundle all fifteen migrations and explicitly reactivate compatible code.
-- Forward repair corrects permissions/missing files and repeats activation.
-- Preserve reviews, approvals, spending and audit history; never downgrade markers.
-- Manual execution: replace {{prefix}} with the configured WordPress prefix.
CREATE TABLE {{prefix}}tgit_ai_reviews (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 asset_id bigint unsigned NOT NULL,
 approval_id bigint unsigned NOT NULL,
 request_id bigint unsigned NOT NULL,
 actor_id bigint unsigned NOT NULL,
 evidence_fingerprint char(64) NOT NULL,
 output_fingerprint char(64) NOT NULL,
 output_json longtext NOT NULL,
 created_at datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY review_request (workspace_id,request_id),
 KEY review_history (workspace_id,asset_id,id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
