-- Schema 8: append-only manual observations, reproducible reports and private saved views.
-- Back up database/private media before activation. Replace {{prefix}} for manual execution.
CREATE TABLE {{prefix}}tgit_market_observations (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 kind varchar(10) NOT NULL,
 asset_id bigint unsigned DEFAULT NULL,
 currency char(3) NOT NULL,
 base_currency char(3) DEFAULT NULL,
 value decimal(38,18) NOT NULL,
 effective_date date NOT NULL,
 expires_on date NOT NULL,
 source varchar(190) NOT NULL,
 reason varchar(500) NOT NULL,
 supersedes_id bigint unsigned DEFAULT NULL,
 actor_id bigint unsigned NOT NULL,
 observed_at datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY replacement_scope (workspace_id,supersedes_id),
 KEY asset_date (workspace_id,kind,asset_id,effective_date,id),
 KEY currency_date (workspace_id,kind,currency,base_currency,effective_date,id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE {{prefix}}tgit_report_runs (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 actor_id bigint unsigned NOT NULL,
 report_type varchar(20) NOT NULL,
 as_of date NOT NULL,
 payload longtext NOT NULL,
 created_at datetime NOT NULL,
 PRIMARY KEY  (id),
 KEY workspace_scope (workspace_id,id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE {{prefix}}tgit_saved_views (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 actor_id bigint unsigned NOT NULL,
 name varchar(190) NOT NULL,
 revision int unsigned NOT NULL DEFAULT 1,
 payload longtext NOT NULL,
 created_at datetime NOT NULL,
 PRIMARY KEY  (id),
 KEY actor_scope (workspace_id,actor_id,id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE {{prefix}}tgit_saved_view_revisions (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 view_id bigint unsigned NOT NULL,
 actor_id bigint unsigned NOT NULL,
 revision int unsigned NOT NULL,
 payload longtext NOT NULL,
 created_at datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY revision_scope (workspace_id,view_id,revision)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
