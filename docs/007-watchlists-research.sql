-- Additive schema 7: private manual watchlists and authored research.
-- Back up database and private media before activation. Keep prior SQL bundled.
-- Replace {{prefix}} only with the configured WordPress prefix for a reviewed manual install.
CREATE TABLE {{prefix}}tgit_watchlists (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 uuid char(36) NOT NULL,
 name varchar(190) NOT NULL,
 created_by bigint unsigned NOT NULL,
 created_at datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY uuid (uuid),
 KEY workspace_list (workspace_id,id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE {{prefix}}tgit_watchlist_items (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 watchlist_id bigint unsigned NOT NULL,
 asset_id bigint unsigned NOT NULL,
 target_buy decimal(38,18) NULL,
 target_sell decimal(38,18) NULL,
 thesis text NOT NULL,
 tags_json text NOT NULL,
 status varchar(20) NOT NULL,
 revision int unsigned NOT NULL DEFAULT 1,
 updated_by bigint unsigned NOT NULL,
 updated_at datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY list_asset (workspace_id,watchlist_id,asset_id),
 KEY asset_scope (workspace_id,asset_id,id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE {{prefix}}tgit_watchlist_item_revisions (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 item_id bigint unsigned NOT NULL,
 revision int unsigned NOT NULL,
 payload longtext NOT NULL,
 actor_id bigint unsigned NOT NULL,
 created_at datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY item_revision (workspace_id,item_id,revision)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE {{prefix}}tgit_research_notes (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 asset_id bigint unsigned NOT NULL,
 content longtext NOT NULL,
 revision int unsigned NOT NULL DEFAULT 1,
 created_by bigint unsigned NOT NULL,
 created_at datetime NOT NULL,
 updated_at datetime NOT NULL,
 PRIMARY KEY  (id),
 KEY asset_scope (workspace_id,asset_id,id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE {{prefix}}tgit_research_note_revisions (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 note_id bigint unsigned NOT NULL,
 revision int unsigned NOT NULL,
 content longtext NOT NULL,
 reason varchar(190) NOT NULL,
 actor_id bigint unsigned NOT NULL,
 created_at datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY note_revision (workspace_id,note_id,revision)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
