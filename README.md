# IG Trading Journal

A private investment tracker and trading journal for WordPress, implementing the TG Investment Tracker PRD in staged slices.

## Installation

Requires PHP 8.1+ with BCMath, WordPress 6.8+, and MySQL 8.0+/MariaDB 10.6+ with InnoDB and utf8mb4. Use HTTPS outside local/development environments.

Copy the plugin into `wp-content/plugins/`, excluding `tmp`, `tests`, and development tools, and activate **IG Trading Journal**. Keep `docs/001-ledger-foundation.sql` in the package: activation reads it to create the custom tables. Then open **Investment Tracker** in WordPress administration and explicitly create a workspace with its base currency and IANA timezone.

New workspaces default to **USD** and **America/New_York**, as confirmed by the owner. New York time automatically uses EST or EDT according to the date. Setup fields remain editable; existing workspaces retain their stored settings.

Activation automatically installs schema version 1. Manual SQL, if needed, is isolated in [docs/001-ledger-foundation.sql](docs/001-ledger-foundation.sql); replace `{{prefix}}` with the site's actual table prefix. See [installation and recovery](docs/operations.md) before running it. No SQL was run against an existing WordPress site during development.

The creator becomes the workspace owner. Other WordPress administrators do not gain access automatically. Owners can add existing WordPress users as owners, managers, contributors (drafts only), or viewers. Network activation is not supported; activate on each site separately.

## Current build: 0.2.0

- Workspace setup, explicit memberships and revocation; separate native-currency accounts and stock/ETF/crypto identities.
- Deposits, withdrawals, buys and sells, immutable posted entries, and saved non-posting draft records.
- Exact decimal arithmetic, fee-inclusive FIFO basis, partial sales, remaining quantities/basis and realized gains.
- Versioned authenticated REST API, atomic posting and audit evidence, workspace-scoped idempotency keys, paginated lists, and a responsive admin screen.

Record funding before purchases. Dates are date-only; same-day order follows committed transaction IDs. Earlier-dated posting is blocked until corrections and chronological replay are implemented. Account and asset currencies must match; no cross-currency posting, overdraft, shorts or unsupported instruments. Price-dependent values remain unknown, and no consolidated base-currency totals are invented.

This is the first ledger slice, not the complete MVP. Draft editing/promotion, historical corrections, opening lots, dividends, transfers, splits, FX, manual prices, trade journal/multiple private images, imports, exports, jobs, watchlists and commercial adapters remain in subsequent slices. See [requirements and phase gates](docs/requirements.md).

See the [remaining-work checklist](docs/remaining-work.md) for the prioritized implementation sequence and hosting/pilot prerequisites.

## Development

- Plugin entry point: `ig-trading-journal.php`
- Domain: `src/Domain/`; application and workspace permissions: `src/Application/`
- WordPress persistence: `src/Infrastructure/`; REST: `src/Http/`; admin UI: `src/Admin/` and `assets/`
- Translations: `languages/`
- Follow the WordPress Coding Standards for PHP changes.

Run `php tests/run.php` (BCMath required), PHP syntax checks, and `node --check assets/admin.js`. Use `composer install` and `composer check-cs` for the locked WordPress coding-standard tools. [Validation instructions](docs/testing.md) include the disposable WordPress integration suite. Keep all real portfolio records out of fixtures.
