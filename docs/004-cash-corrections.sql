-- Additive schema 4: immutable source/replacement links for reviewed corrections.
-- Replace {{prefix}} with the WordPress table prefix only for a reviewed manual install.
CREATE TABLE {{prefix}}tgit_transaction_corrections (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 source_transaction_id bigint unsigned NOT NULL,
 replacement_transaction_id bigint unsigned NOT NULL,
 chronology_id bigint unsigned NOT NULL,
 source_revision int unsigned NOT NULL,
 actor_id bigint unsigned NOT NULL,
 reason varchar(190) NOT NULL,
 created_at datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY source_scope (workspace_id,source_transaction_id),
 UNIQUE KEY replacement_scope (workspace_id,replacement_transaction_id),
 KEY workspace_time (workspace_id,created_at,id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
