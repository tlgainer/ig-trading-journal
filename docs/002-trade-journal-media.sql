-- Schema version 2: journals, strategy snapshots and private images.
-- Back up first. Replace {{prefix}} with your WordPress prefix (wp_ on your host).
-- Schema 2 originally required this file; schema 3 activation also reads 003-opening-balances.sql.
CREATE TABLE {{prefix}}tgit_strategies (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 uuid char(36) NOT NULL,
 name varchar(190) NOT NULL,
 status varchar(20) NOT NULL,
 revision int unsigned NOT NULL DEFAULT 1,
 created_by bigint unsigned NOT NULL,
 created_at datetime NOT NULL,
 UNIQUE KEY uuid (uuid),
 KEY workspace_id (workspace_id,id),
 PRIMARY KEY  (id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE {{prefix}}tgit_strategy_versions (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 strategy_id bigint unsigned NOT NULL,
 revision int unsigned NOT NULL,
 payload longtext NOT NULL,
 actor_id bigint unsigned NOT NULL,
 created_at datetime NOT NULL,
 UNIQUE KEY revision_scope (workspace_id,strategy_id,revision),
 PRIMARY KEY  (id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE {{prefix}}tgit_trades (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 uuid char(36) NOT NULL,
 asset_id bigint unsigned NOT NULL,
 title varchar(190) NOT NULL,
 state varchar(20) NOT NULL,
 opened_on date DEFAULT NULL,
 closed_on date DEFAULT NULL,
 strategy_version_id bigint unsigned DEFAULT NULL,
 revision int unsigned NOT NULL DEFAULT 1,
 created_by bigint unsigned NOT NULL,
 created_at datetime NOT NULL,
 UNIQUE KEY uuid (uuid),
 KEY workspace_id (workspace_id,id),
 KEY asset_scope (workspace_id,asset_id,id),
 PRIMARY KEY  (id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE {{prefix}}tgit_trade_journals (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 trade_id bigint unsigned NOT NULL,
 revision int unsigned NOT NULL,
 payload longtext NOT NULL,
 actor_id bigint unsigned NOT NULL,
 created_at datetime NOT NULL,
 UNIQUE KEY revision_scope (workspace_id,trade_id,revision),
 PRIMARY KEY  (id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE {{prefix}}tgit_trade_fills (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 trade_id bigint unsigned NOT NULL,
 transaction_id bigint unsigned NOT NULL,
 UNIQUE KEY fill_scope (workspace_id,transaction_id),
 KEY trade_scope (workspace_id,trade_id,id),
 PRIMARY KEY  (id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE {{prefix}}tgit_media_settings (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 max_images int unsigned NOT NULL,
 max_file_bytes bigint unsigned NOT NULL,
 max_pixels bigint unsigned NOT NULL,
 quota_bytes bigint unsigned NOT NULL,
 trash_days int unsigned NOT NULL,
 revision int unsigned NOT NULL DEFAULT 1,
 UNIQUE KEY workspace_id (workspace_id),
 PRIMARY KEY  (id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE {{prefix}}tgit_media (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 trade_id bigint unsigned NOT NULL,
 uuid char(36) NOT NULL,
 revision int unsigned NOT NULL DEFAULT 1,
 filename varchar(190) NOT NULL,
 expected_hash char(64) NOT NULL,
 expected_bytes bigint unsigned NOT NULL,
 reserved_bytes bigint unsigned NOT NULL,
 storage_key varchar(64) DEFAULT NULL,
 thumb_key varchar(64) DEFAULT NULL,
 content_hash char(64) DEFAULT NULL,
 mime varchar(32) DEFAULT NULL,
 bytes bigint unsigned NOT NULL DEFAULT 0,
 thumb_bytes bigint unsigned NOT NULL DEFAULT 0,
 width int unsigned NOT NULL DEFAULT 0,
 height int unsigned NOT NULL DEFAULT 0,
 state varchar(20) NOT NULL,
 previous_state varchar(20) DEFAULT NULL,
 caption text NOT NULL,
 alt_text text NOT NULL,
 stage varchar(20) NOT NULL,
 timeframe varchar(64) NOT NULL,
 sort_order int unsigned NOT NULL DEFAULT 0,
 uploader_id bigint unsigned NOT NULL,
 failure_code varchar(64) DEFAULT NULL,
 created_at datetime NOT NULL,
 updated_at datetime NOT NULL,
 deleted_at datetime DEFAULT NULL,
 UNIQUE KEY uuid (uuid),
 KEY gallery_scope (workspace_id,trade_id,sort_order,id),
 KEY job_state (state,updated_at,id),
 PRIMARY KEY  (id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
