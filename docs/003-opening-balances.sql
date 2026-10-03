-- Additive schema 3: documented opening cash and asset-lot provenance.
-- Replace {{prefix}} with the WordPress table prefix only for a reviewed manual install.
CREATE TABLE {{prefix}}tgit_opening_balances (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 transaction_id bigint unsigned NOT NULL,
 account_id bigint unsigned NOT NULL,
 asset_id bigint unsigned NULL,
 acquired_on date NULL,
 basis_status varchar(20) NOT NULL,
 source_note varchar(190) NOT NULL,
 created_at datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY transaction_scope (workspace_id,transaction_id),
 KEY account_scope (workspace_id,account_id,id),
 KEY asset_scope (workspace_id,asset_id,id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
