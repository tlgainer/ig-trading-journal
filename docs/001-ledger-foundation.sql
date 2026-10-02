-- TGIT schema version 1. Replace every {{prefix}} with your WordPress table prefix (usually wp_).
-- Activation installs this automatically. Manual installation: select the WordPress database first.
-- Additive schema only; no production data or users are inserted.
CREATE TABLE {{prefix}}tgit_workspaces (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 uuid char(36) NOT NULL,
 name varchar(190) NOT NULL,
 base_currency char(3) NOT NULL,
 timezone varchar(64) NOT NULL,
 status varchar(20) NOT NULL DEFAULT 'active',
 settings_version int unsigned NOT NULL DEFAULT 1,
 created_at datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY uuid (uuid)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE {{prefix}}tgit_memberships (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 wp_user_id bigint unsigned NOT NULL,
 role varchar(20) NOT NULL,
 state varchar(20) NOT NULL DEFAULT 'active',
 invited_by bigint unsigned NOT NULL,
 joined_at datetime NOT NULL,
 revoked_at datetime NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY workspace_user (workspace_id,wp_user_id),
 KEY user_state (wp_user_id,state)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE {{prefix}}tgit_accounts (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 uuid char(36) NOT NULL,
 name varchar(190) NOT NULL,
 broker varchar(190) NOT NULL DEFAULT '',
 native_currency char(3) NOT NULL,
 type varchar(20) NOT NULL DEFAULT 'brokerage',
 cash_balance decimal(38,12) NOT NULL DEFAULT 0,
 archived_at datetime NULL,
 created_at datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY uuid (uuid),
 KEY workspace (workspace_id,id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE {{prefix}}tgit_assets (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 uuid char(36) NOT NULL,
 symbol varchar(32) NOT NULL,
 exchange varchar(64) NOT NULL,
 asset_class varchar(20) NOT NULL,
 quote_currency char(3) NOT NULL,
 created_at datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY uuid (uuid),
 UNIQUE KEY instrument (workspace_id,symbol,exchange,asset_class,quote_currency)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE {{prefix}}tgit_transactions (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 uuid char(36) NOT NULL,
 account_id bigint unsigned NOT NULL,
 asset_id bigint unsigned NULL,
 action varchar(20) NOT NULL,
 effective_date date NOT NULL,
 executed_at datetime NULL,
 date_precision varchar(10) NOT NULL DEFAULT 'date',
 state varchar(20) NOT NULL,
 revision int unsigned NOT NULL DEFAULT 1,
 quantity decimal(38,18) NOT NULL DEFAULT 0,
 unit_price decimal(38,18) NOT NULL DEFAULT 0,
 fees decimal(38,12) NOT NULL DEFAULT 0,
 amount decimal(38,12) NOT NULL DEFAULT 0,
 currency char(3) NOT NULL,
 realized_gain decimal(38,12) NOT NULL DEFAULT 0,
 created_by bigint unsigned NOT NULL,
 created_at datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY uuid (uuid),
 KEY account_date (workspace_id,account_id,effective_date,id),
 KEY asset_date (workspace_id,asset_id,effective_date,id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE {{prefix}}tgit_transaction_legs (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 transaction_id bigint unsigned NOT NULL,
 account_id bigint unsigned NOT NULL,
 asset_id bigint unsigned NULL,
 quantity_delta decimal(38,18) NOT NULL DEFAULT 0,
 cash_delta decimal(38,12) NOT NULL,
 currency char(3) NOT NULL,
 role varchar(20) NOT NULL,
 PRIMARY KEY  (id),
 KEY transaction_scope (workspace_id,transaction_id),
 KEY account_scope (workspace_id,account_id,id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE {{prefix}}tgit_transaction_revisions (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 transaction_id bigint unsigned NOT NULL,
 revision int unsigned NOT NULL,
 payload longtext NOT NULL,
 actor_id bigint unsigned NOT NULL,
 reason varchar(190) NOT NULL,
 created_at datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY revision_scope (workspace_id,transaction_id,revision)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE {{prefix}}tgit_lots (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 account_id bigint unsigned NOT NULL,
 asset_id bigint unsigned NOT NULL,
 acquisition_leg_id bigint unsigned NOT NULL,
 quantity_initial decimal(38,18) NOT NULL,
 quantity_remaining decimal(38,18) NOT NULL,
 basis_initial decimal(38,12) NOT NULL,
 basis_remaining decimal(38,12) NOT NULL,
 acquired_on date NOT NULL,
 PRIMARY KEY  (id),
 KEY fifo (workspace_id,account_id,asset_id,acquired_on,id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE {{prefix}}tgit_lot_allocations (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 disposal_leg_id bigint unsigned NOT NULL,
 lot_id bigint unsigned NOT NULL,
 quantity decimal(38,18) NOT NULL,
 basis_native decimal(38,12) NOT NULL,
 proceeds decimal(38,12) NOT NULL,
 realized_gain decimal(38,12) NOT NULL,
 PRIMARY KEY  (id),
 KEY disposal_scope (workspace_id,disposal_leg_id),
 KEY lot_scope (workspace_id,lot_id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE {{prefix}}tgit_idempotency (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 operation varchar(40) NOT NULL,
 request_key varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_hash char(64) NOT NULL,
 result longtext NOT NULL,
 created_at datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY request_scope (workspace_id,operation,request_key)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE {{prefix}}tgit_audit_events (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 actor_id bigint unsigned NOT NULL,
 action varchar(64) NOT NULL,
 entity varchar(40) NOT NULL,
 entity_id bigint unsigned NOT NULL,
 revision int unsigned NOT NULL DEFAULT 1,
 correlation_id char(36) NOT NULL,
 created_at datetime NOT NULL,
 PRIMARY KEY  (id),
 KEY workspace_time (workspace_id,created_at,id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
