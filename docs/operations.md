# Installation, SQL and recovery

Requires PHP 8.1+ with BCMath, WordPress 6.8+, MySQL 8.0+/MariaDB 10.6+, InnoDB, utf8mb4 and HTTPS outside development. Private images additionally require GD and configured private storage; journals/strategies do not require GD. Minimum WordPress/MariaDB and the actual host still need matrix validation.

Confirmed host: WordPress 7.1.2, PHP 8.1.2-1ubuntu2.26 on Apache 2.4.52/apache2handler, MySQL 8.0.46/mysqli, wp_ prefix, utf8mb4/utf8mb4_unicode_520_ci, plain permalinks and Europe/London site timezone. Earlier phpMyAdmin PHP 7.4/nginx details are a different runtime. BCMath/GD presence in the WordPress Apache runtime is not established by screenshots.

The site currently reports production HTTP URLs. Configure HTTPS and update both WordPress URLs before real use; do not switch production to development to bypass transport checks. Plain permalinks work without a permalink change. Workspaces default to USD/America/New_York (EST/EDT); WordPress's site timezone does not override the workspace.

## Activation and development schema 13 upgrade

Development source is 0.27.0-dev/schema 13. The last released 0.26.0 ZIP still uses schema 8 and its eight migrations. There is no new provider-enabled package yet; do not apply development migrations to production merely to enable prices or AI. Quote/fundamental storage, scheduling, valuations and reporting-period differences are implemented in development. Schema 13 adds persistent AI policy, workspace opt-in and shared spending reservations; it sends no requests. Owner AI controls, model catalog, reviews/transport, daily-change compatibility and exchange calendars remain pending.

Back up the database and private image bytes before replacing code/reactivating. Keep **all thirteen** separate SQL files in `docs`, from `001-ledger-foundation.sql` through `013-ai-spending.sql`, in a future development package. Exclude tmp, tests, vendor, node_modules and development tools.

Activation/reactivation reads all thirteen bundled SQL files, replaces `{{prefix}}` with `$wpdb->prefix`, applies additive `dbDelta` and the checked dataset-column ALTER under a named database lock, verifies InnoDB and records `tgit_schema_version=13` after success. This upgrades schema 1 through 12, or installs a fresh schema. Existing ledger rows are retained. No workspace or AI enrollment is created implicitly. Preserve the singleton spending pool, all policy/enrollment revisions, requests and events during forward repair; credential rotation cannot reset accounting.

There is no request-time migration. Until reactivation succeeds, the API readiness gate and admin notice explain the mismatch. MySQL DDL is not transactional: after a partial failure, fix permissions/prerequisites and reactivate to repeat the additive repair. Unknown future schema versions are refused. No table is dropped and no financial replay occurs in this migration.

Install a released ZIP directly; earlier packages do not need sequential installation. After backup and explicit reactivation, verify its matching schema (currently 8 for 0.26.0; 13 only for this development source). There is no new schema-13 ZIP yet.

## Separate manual SQL

Ordinarily activation handles SQL. If manual execution is necessary, select the correct backed-up WordPress database and confirm its prefix in wp-config.php.

- Fresh installation: run SQL files 001 through 011 in order.
- Existing schema-1 ledger installation: run files 002 through 011 in order.
- Existing schema-2 journal installation: run files 003 through 011 in order.
- Existing schema-3 opening installation: run files 004 through 011 in order.
- Existing schema-4 correction installation: run files 005 through 011 in order.
- Existing schema-5 replay installation: run files 006 through 011 in order.
- Existing schema-7 research installation: run files 008 through 011 in order.
- Existing schema-6 basis installation: run files 007 through 011 in order.
- Existing schema-8 reporting installation: run files 009 through 011 in order.
- Existing schema-9 provider installation: run files 010 and 011 in order.
- Existing schema-10 schedule installation: run file 011.

Schema 9 adds provider mapping revisions, append-only quotes, credential-wide quota pools and scoped request records; schema 10 adds append-only owner refresh enrollment; it does not copy or modify manual observations or financial history. Back up all existing tables before upgrading. After an interrupted migration, keep processing disabled, inspect new tables/indexes and rerun matching source installation. Retain all provider evidence and quota reservations during repair; do not delete pools to reset allowances. Previously dispatched requests with unknown outcomes remain counted until explicitly reconciled.

Development registers disabled-by-default FMP/Alpha Vantage workers, one-shot hooks and an explicit weekday schedule. No workspace is automatically enrolled. Owner enrollment commits before cron queueing; an hourly recovery scan repairs missing jobs. Weekday slots are 18:30 and optionally 22:30 America/New_York, preserving DST. Exchange holidays are not included in this clock policy. Jobs more than two hours late skip the missed request and queue the next slot. Configure a dependable server-driven WordPress cron for timely execution. The internal scheduling operation requires a current workspace owner and a current confirmed stock mapping. Keys stay in server configuration; no owner-facing key/model/budget controls are released yet. Deactivation unschedules quote jobs and preserves tables, quotes and quota records. Keep live processing disabled until configuration/UI and release validation are complete. Rotating a key must not be used to evade an account's actual provider allowance; local pools are keyed by credential identity and cannot infer that two different keys belong to the same vendor account.

Replace every `{{prefix}}` in a working copy with the configured prefix (`wp_` on the confirmed host). The files contain CREATE statements and fail on existing table names; file 011 also adds a dataset column to existing requests. On manual repair, check whether that column exists before repeating its ALTER; do not convert them to destructive replacements. Reactivate afterward so the installer verifies all schemas and records version 11. Do not manually forge or downgrade the schema marker. Relationship integrity is enforced by scoped application transactions; do not write ledger/journal/media/opening/correction/replay/basis/watchlist/research/observation/report/view rows manually.

Opening balances entered through Settings require a source note and precede ordinary posting. Existing accounts with posted history use the dedicated retroactive-opening API, which validates later chronology and appends a replay run. Every opening entry in an account uses the same date. An unresolved asset basis is displayed as unknown and blocks a sale of that account/asset; do not enter a fabricated zero. The basis-resolution API appends documented, revision-checked evidence while preserving the original unknown opening record. A documented zero basis is allowed explicitly.

## Private images and jobs

Follow `private-images.md` for the GD/runtime check, private directory, Apache deny rule, owner quota policy, upload behavior and retention jobs. Images never use public WordPress attachments. Owner-authorized daily cleanup is the only retention operation introduced here; deactivation/uninstall do not purge the portfolio.

## Backup and rollback

Back up the database and private normalized bytes consistently. Database-only backup loses the gallery. Restore into an isolated installation and compare financial legs/lots/cash/revisions, journals, captured strategies and media hashes before cutover. Automated restore/export reconciliation is still pending under the owner's fourth priority.

Schema 10 code refuses incompatible markers. Earlier code expects schema 1 through 9 and cannot safely operate after this migration. Rollback requires a matching complete backup or a reviewed forward-compatible repair; do not delete additive tables or force a schema downgrade. Correction source rows, legs, prior replay runs, basis-resolution revisions, watchlist/research/view revisions, observation corrections and report snapshots remain for audit. Active cash, holdings and gains exclude superseded sources. A stale source fingerprint or calculation version stops affected account posting/reporting; restore the matching code and data, or review a forward repair that appends a new calculation run after independent reconciliation. Never rewrite old posted facts or replay runs. No production backup, restore, purge, migration or deployment was performed during development.

## Staging smoke test

Create two workspaces; confirm that a viewer/member cannot access foreign data and loses future access after revocation. Deposit 2000, buy 10 at 100 with fee 5, sell 4 at 120 with fee 2: expect cash 1473, units 6, basis 603 and gain 76. Retry with the same key; no duplicate should appear.

Create a strategy and trade journal with optional fields and fills. Edit the strategy and verify the trade retains its captured version. Save a journal with zero images, then three valid images plus one rejected file. Ready images should work and failed images should retry independently. Caption/order/compare, delete and restore; originals and thumbnails must deny anonymous, revoked and foreign-workspace requests. Verify the directory has no public URL. Complete the remaining release gates before production.

## October 6, 2026: cached provider holdings and stock totals

Development source remains 0.27.0-dev/schema 10; the released ZIP remains 0.26.0/schema 8. Overview now offers an explicit manual/FMP/Alpha Vantage stock price source and exact open-stock market value and unrealized gain/loss totals across all authorized accounts and asset pages. Other asset classes keep manual pricing. Values are grouped by native currency, exclude cash and closed positions, and do not change with table filters.

Provider valuations require the latest enabled matching mapping and a saved quote no more than three calendar days old. Missing/stale provider prices remain unavailable without fallback. Unknown basis leaves gain unavailable. Partial coverage shows covered subtotals; mixed price dates cannot claim a complete total. Source preference is scoped to the current user/workspace browser session. Existing posted facts, manual observations and saved reports remain unchanged; reads send no external requests.

Validation: 58 deterministic unit checks and 110 disposable WordPress/MySQL integration checks passed, including full pagination, source validation, membership, staleness, mapping replacement, unknown basis and immutable manual reports. Coding standards, PHP/JavaScript syntax and six REST URL checks passed. The cached valuation browser fixture passed source switching, exact values, missing coverage, scoped restoration and desktop/mobile layout. No new SQL, live provider calls or release ZIP.

Next: corporate-action compatibility before displaying daily change, then fundamental snapshots and AI summaries with configurable model and monthly budget. Exchange holidays and operational monitoring remain open.
Development schema 11 now stores immutable fundamental snapshots and binds provider requests to datasets under the existing shared quota. See [migration](011-fundamental-snapshots.sql). Fundamental transport, metrics, review UI and AI processing remain pending.

## October 6 continuation: bounded fundamental transport

Added a separately disabled-by-default Alpha Vantage fundamental worker and explicitly owner-authorized one-shot jobs for overview/income/balance-sheet/cash-flow snapshots. It uses fixed HTTPS transport bounds, the existing shared quote/fundamental request pool, typed retry identity and immutable completion. Completed requests reuse evidence; timeouts/audit failures after delivery remain uncertain and are not resent. Provider/entitlement errors retain consumed quota. Revocation and mapping changes block dispatch or completion. Deactivation removes pending fundamental jobs while retaining snapshots and allowances. See [worker contract](fundamental-refresh-worker.md).

Configuration additionally requires TGIT_FUNDAMENTALS_ENABLED strictly true. Price enablement alone cannot enable fundamental fetching. No recurring review enrollment, owner-facing analyze action or OpenAI processing is enabled by this milestone. The official Alpha Vantage endpoint documentation was verified; responses remain synthetic mocks and no live entitlement is claimed.

73 unit and 126 disposable WordPress/MySQL integration checks, coding standards, PHP/JavaScript syntax and six REST URL checks passed. UI/HTTP and schema are unchanged; their latest full regression remains the schema-11 storage milestone. Source stays 0.27.0-dev/schema 11; no SQL or release ZIP is added. Next: review screens/on-demand actions, recurring enrollment, comparable-period changes and configurable AI summaries.
