-- Additive schema 6: versioned evidence for resolving an opening lot's cost basis.
-- Back up database and private media before activation. Keep prior SQL bundled.
-- Replace {{prefix}} only with the configured WordPress prefix for a reviewed manual install.
CREATE TABLE {{prefix}}tgit_opening_basis_resolutions (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 opening_transaction_id bigint unsigned NOT NULL,
 revision int unsigned NOT NULL,
 amount decimal(38,12) NOT NULL,
 reason varchar(190) NOT NULL,
 actor_id bigint unsigned NOT NULL,
 created_at datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY opening_revision (workspace_id,opening_transaction_id,revision),
 KEY opening_latest (workspace_id,opening_transaction_id,id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
