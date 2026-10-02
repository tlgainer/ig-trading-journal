# Installation, SQL and recovery

Requires PHP 8.1+ with BCMath, WordPress 6.8+, MySQL 8.0+/MariaDB 10.6+, InnoDB, utf8mb4 and HTTPS outside development. Private images additionally require GD and configured private storage; journals/strategies do not require GD. Minimum WordPress/MariaDB and the actual host still need matrix validation.

Confirmed host: WordPress 7.1.2, PHP 8.1.2-1ubuntu2.26 on Apache 2.4.52/apache2handler, MySQL 8.0.46/mysqli, wp_ prefix, utf8mb4/utf8mb4_unicode_520_ci, plain permalinks and Europe/London site timezone. Earlier phpMyAdmin PHP 7.4/nginx details are a different runtime. BCMath/GD presence in the WordPress Apache runtime is not established by screenshots.

The site currently reports production HTTP URLs. Configure HTTPS and update both WordPress URLs before real use; do not switch production to development to bypass transport checks. Plain permalinks work without a permalink change. Workspaces default to USD/America/New_York (EST/EDT); WordPress's site timezone does not override the workspace.

## Activation and schema 2 upgrade

Back up the database before replacing code/reactivating. Keep **both** `docs/001-ledger-foundation.sql` and `docs/002-trade-journal-media.sql` in the production package. Exclude tmp, tests, vendor, node_modules and development tools.

Activation/reactivation reads both bundled SQL files, replaces `{{prefix}}` with `$wpdb->prefix`, applies additive `dbDelta` under a named database lock, verifies InnoDB and records `tgit_schema_version=2` after success. This upgrades schema 1 or installs a fresh schema. Existing ledger rows are retained. No workspace is created implicitly.

There is no request-time migration. Until reactivation succeeds, the API readiness gate and admin notice explain the mismatch. MySQL DDL is not transactional: after a partial failure, fix permissions/prerequisites and reactivate to repeat the additive repair. Unknown future schema versions are refused. No table is dropped and no financial replay occurs in this migration.

## Separate manual SQL

Ordinarily activation handles SQL. If manual execution is necessary, select the correct backed-up WordPress database and confirm its prefix in wp-config.php.

- Fresh installation: `001-ledger-foundation.sql` followed by `002-trade-journal-media.sql`.
- Existing schema-1 ledger installation: `002-trade-journal-media.sql` adds journal, strategy and private-media tables.

Replace every `{{prefix}}` in a working copy with the configured prefix (`wp_` on the confirmed host). The files contain CREATE statements and fail on existing table names; do not convert them to destructive replacements. Reactivate afterward so the installer verifies both schemas and records version 2. Do not manually forge or downgrade the schema marker. Relationship integrity is enforced by scoped application transactions; do not write ledger/journal/media rows manually.

## Private images and jobs

Follow `private-images.md` for the GD/runtime check, private directory, Apache deny rule, owner quota policy, upload behavior and retention jobs. Images never use public WordPress attachments. Owner-authorized daily cleanup is the only retention operation introduced here; deactivation/uninstall do not purge the portfolio.

## Backup and rollback

Back up the database and private normalized bytes consistently. Database-only backup loses the gallery. Restore into an isolated installation and compare financial legs/lots/cash/revisions, journals, captured strategies and media hashes before cutover. Automated restore/export reconciliation is still pending under the owner's fourth priority.

Schema 2 code refuses incompatible markers. Old 0.3.0 code expects schema 1 and cannot safely operate the new journal-linked workflow. Rollback requires a matching complete backup or a reviewed forward-compatible repair; do not delete additive tables or force a schema downgrade. No production backup, restore, purge, migration or deployment was performed during development.

## Staging smoke test

Create two workspaces; confirm that a viewer/member cannot access foreign data and loses future access after revocation. Deposit 2000, buy 10 at 100 with fee 5, sell 4 at 120 with fee 2: expect cash 1473, units 6, basis 603 and gain 76. Retry with the same key; no duplicate should appear.

Create a strategy and trade journal with optional fields and fills. Edit the strategy and verify the trade retains its captured version. Save a journal with zero images, then three valid images plus one rejected file. Ready images should work and failed images should retry independently. Caption/order/compare, delete and restore; originals and thumbnails must deny anonymous, revoked and foreign-workspace requests. Verify the directory has no public URL. Complete the remaining release gates before production.
