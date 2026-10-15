-- Schema 20: append-only explicit Tickerbot inventory selections.
-- Back up database and private media before explicit activation.
-- Keep Tickerbot disabled during forward repair; rerun the matching installer.
-- No job is enabled and no financial or media record is changed.
CREATE TABLE {{prefix}}tgit_tickerbot_scope_revisions (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 workspace_id bigint unsigned NOT NULL,
 revision bigint unsigned NOT NULL,
 watchlists_json longtext NOT NULL,
 trades_json longtext NOT NULL,
 actor_id bigint unsigned NOT NULL,
 created_at datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY scope_revision (workspace_id,revision)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
