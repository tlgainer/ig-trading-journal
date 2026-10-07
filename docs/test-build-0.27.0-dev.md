# 0.27.0-dev test build — schema 17

This is an installable development test package, not a production release. It adds the financial-data and saved fundamental review workflows developed since 0.26.0. AI budget/model preparation, evidence approval and saved-review history are available; live AI generation is still unavailable. Token-count verification, execution-manifest persistence and guarded sending remain unfinished.

## Install on a staging copy

1. Back up the database and private image directory together. Prefer an isolated copy of the site for this test build. Keep a matching code/database/media backup for rollback.
2. Upload `ig-trading-journal-0.27.0-dev-test.zip` using WordPress **Plugins → Add New → Upload Plugin**, then replace the existing plugin. The ZIP contains the stable `ig-trading-journal/` folder directly, with no version folder around it.
3. Explicitly deactivate and reactivate after replacing the code. Activation upgrades supported schema versions 1–16 to 17 using all 17 bundled SQL files. Earlier ZIPs do not need sequential installation. Ordinary activation handles SQL; do not run raw migrations or change the schema marker yourself.
4. Confirm the Investment Tracker opens and existing cash, holdings, transactions, strategies and private images remain available. Confirm provider mappings and schedules before enabling any provider refresh.

PHP 8.1 with BCMath, MySQL 8.0+/MariaDB 10.6+, InnoDB and utf8mb4 are required. Private images additionally require GD and private storage outside public roots. HTTPS is required outside development. Do not use development settings to bypass HTTPS on production.

Keep FMP, Alpha Vantage and AI processing disabled until their server configuration, consent, limits and host acceptance are reviewed. This build makes no live provider or OpenAI request during packaging. Local fixtures do not establish free-tier endpoint entitlement, actual host compatibility or successful backup restoration.

Schema upgrades are additive but MySQL DDL is not transactional. On an interrupted upgrade, fix the reported prerequisite and reactivate for forward repair. Do not downgrade code against schema 17, delete tables, reset quota/spending records or forge the schema marker. Rollback requires restoring the matching backup.

The packaged operations/user-guide documents include historical release notes. This file takes precedence for the test package's version, migration count and installation instructions. Original PRD features still pending include imports/reconciliation/exports, verified restore workflows and additional accounting operations.
