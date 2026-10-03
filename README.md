# IG Trading Journal

A private investment tracker and trading journal for WordPress, implementing the TG Investment Tracker PRD in staged slices.

## Installation

Requires PHP 8.1+ with BCMath, WordPress 6.8+, and MySQL 8.0+/MariaDB 10.6+ with InnoDB and utf8mb4. Use HTTPS outside local/development environments.

Copy the plugin into `wp-content/plugins/`, excluding `tmp`, `tests`, and development tools, and activate **IG Trading Journal**. Keep all three bundled SQL files in `docs` in the package: activation reads them to create/upgrade the custom tables. Then open **Investment Tracker** in WordPress administration and explicitly create a workspace with its base currency and IANA timezone.

New workspaces default to **USD** and **America/New_York**, as confirmed by the owner. New York time automatically uses EST or EDT according to the date. Setup fields remain editable; existing workspaces retain their stored settings.

Activation/reactivation installs schema version 3, including the additive journal/media and opening-balance tables. Manual SQL is isolated in [001-ledger-foundation.sql](docs/001-ledger-foundation.sql), [002-trade-journal-media.sql](docs/002-trade-journal-media.sql), and [003-opening-balances.sql](docs/003-opening-balances.sql); replace `{{prefix}}` with the site's actual table prefix. See [installation and recovery](docs/operations.md) before running it. No SQL was run against an existing WordPress site during development.

The creator becomes the workspace owner. Other WordPress administrators do not gain access automatically. Owners can add existing WordPress users as owners, managers, contributors (drafts and journals), or viewers. Network activation is not supported; activate on each site separately.

## Current build: 0.5.0

- Workspace setup, explicit memberships and revocation; separate native-currency accounts and stock/ETF/crypto identities.
- Deposits, withdrawals, buys and sells, immutable posted entries, and editable drafts with revision history and atomic posting.
- Exact decimal arithmetic, fee-inclusive FIFO basis, partial sales, remaining quantities/basis and realized gains.
- Documented opening cash and pre-existing asset lots, preserving original acquisition dates. Opening shares do not deduct cash. Unknown basis stays visibly unresolved and blocks sales until a supported resolution workflow exists.
- Trade journals with entry/exit fills, thesis, levels, checked confluences, emotions, lessons, tags and immutable revisions.
- Versioned strategies captured on trades; later strategy edits preserve the original rationale.
- Multiple private JPEG/PNG/WebP images, captions, ordering, comparison, retry/replacement, recoverable trash and owner-configured quotas. Image uploads additionally require PHP GD and private local storage; see [setup](docs/private-images.md).
- Versioned authenticated REST API, atomic posting and audit evidence, workspace-scoped idempotency keys, paginated lists, and a responsive admin screen.

Record funding before purchases. For an account with pre-existing cash or shares, enter its documented opening balances in Settings before ordinary posting; all entries in that account share one opening date. Existing accounts with posted transactions cannot receive retroactive openings until chronological replay is implemented. Dates are date-only; same-day order follows committed transaction IDs. Earlier-dated posting is blocked until corrections and chronological replay are implemented. Account and asset currencies must match; no cross-currency posting, overdraft, shorts or unsupported instruments. Price-dependent values remain unknown, and no consolidated base-currency totals are invented.

This is a staged ledger and journal build, not the complete MVP. Historical corrections/replay and unknown-basis resolution, watchlists/research/calculators/reports, imports/reconciliation/exports/restore testing, broader accounting/valuation and commercial adapters remain. Image processing is bounded and synchronous per file; asynchronous processing and crash/orphan reconciliation remain operational release work. See [requirements and phase gates](docs/requirements.md).

See the [remaining-work checklist](docs/remaining-work.md) for the prioritized implementation sequence and hosting/pilot prerequisites.

The admin screen groups forms into Overview, Transactions, Trade Journal, Strategies and Settings tabs. Switching tabs preserves unsaved inputs; Settings is available to owners and managers and now includes opening balances. Image controls include contextual tooltips and a clearly labelled stage dropdown. Quote currency explains valid examples and the matching cash account rule. Image upload health and per-file errors identify missing host prerequisites and upload rejections. Image memory-limit errors give a resize and hosting remedy.

## Development

- Plugin entry point: `ig-trading-journal.php`
- Domain: `src/Domain/`; application and workspace permissions: `src/Application/`
- WordPress persistence: `src/Infrastructure/`; REST: `src/Http/`; admin UI: `src/Admin/` and `assets/`
- Translations: `languages/`
- Follow the WordPress Coding Standards for PHP changes.

Run `php tests/run.php` (BCMath required), PHP syntax checks, and JavaScript syntax checks. Use `composer install` and `composer check-cs` for the locked WordPress coding-standard tools. [Validation instructions](docs/testing.md) include the disposable WordPress integration suite. Keep all real portfolio records out of fixtures.
