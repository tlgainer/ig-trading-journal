# IG Trading Journal

A private investment tracker and trading journal for WordPress, implementing the TG Investment Tracker PRD in staged slices.

## Installation

Requires PHP 8.1+ with BCMath, WordPress 6.8+, and MySQL 8.0+/MariaDB 10.6+ with InnoDB and utf8mb4. Use HTTPS outside local/development environments.

Copy the plugin into `wp-content/plugins/`, excluding `tmp`, `tests`, and development tools, and activate **IG Trading Journal**. Keep all eight bundled SQL files in `docs` in the package: activation reads them to create/upgrade the custom tables. Then open **Investment Tracker** in WordPress administration and explicitly create a workspace with its base currency and IANA timezone.

New workspaces default to **USD** and **America/New_York**, as confirmed by the owner. New York time automatically uses EST or EDT according to the date. Setup fields remain editable; existing workspaces retain their stored settings.

Activation/reactivation installs schema version 8, including the additive journal/media, opening-balance, correction-link, replay-run, opening-basis-resolution, watchlist, research, observation, report and saved-view tables. Manual SQL is isolated in separate [docs SQL files](docs/operations.md), from [001-ledger-foundation.sql](docs/001-ledger-foundation.sql) through [008-valuations-reports.sql](docs/008-valuations-reports.sql); replace `{{prefix}}` with the site's actual table prefix. See [installation and recovery](docs/operations.md) before running it. No SQL was run against an existing WordPress site during development.

The creator becomes the workspace owner. Other WordPress administrators do not gain access automatically. Owners can add existing WordPress users as owners, managers, contributors (drafts and journals), or viewers. Network activation is not supported; activate on each site separately.

## Current build: 0.18.0

Transactions now include read-only details, revision evidence and related-entry navigation. New entries have separate Save draft/Post transaction actions; errors retain input and revision conflicts offer an explicit reload. See the [Transaction details decision](docs/decisions/018-transaction-detail-recovery.md). Schema remains version 8; this update adds no SQL migration.

- Workspace setup, explicit memberships and revocation; separate native-currency accounts and stock/ETF/crypto identities.
- Deposits, withdrawals, buys and sells, immutable posted entries, and editable drafts with revision history and atomic posting.
- Exact decimal arithmetic, fee-inclusive FIFO basis, partial sales, remaining quantities/basis and realized gains.
- Documented opening cash and pre-existing asset lots, preserving original acquisition dates. Opening shares do not deduct cash. Reviewed retroactive openings replay later events. Unknown basis stays visibly unresolved and blocks sales until documented, revision-checked resolution is recorded.
- Immutable cash and security corrections with reason, expected revision, source/replacement links and rollback when later cash or lots would be invalid. Historical cash and security entries use chronological replay checks. Versioned replay runs retain recalculated gains and allocations without overwriting posted evidence. A read-only preview shows proposed cash, FIFO gain and allocation changes.
- Trade journals with entry/exit fills, thesis, levels, checked confluences, emotions, lessons, tags and immutable revisions.
- Versioned strategies captured on trades; later strategy edits preserve the original rationale.
- Multiple private JPEG/PNG/WebP images, captions, ordering, comparison, retry/replacement, recoverable trash and owner-configured quotas. Image uploads additionally require PHP GD and private local storage; see [setup](docs/private-images.md).
- Versioned authenticated REST API, atomic posting and audit evidence, workspace-scoped idempotency keys, paginated lists, and a responsive admin screen.
- Private crypto-profit and long-position risk scenarios with exact decimal arithmetic, explicit fee treatment, and no ledger posting.
- Manual watchlists with identified assets, user-set targets/status/thesis/tags, and authored research notes with revision history. These are private workspace records, never automated recommendations.

- Manual price/FX observation history and corrections; dated activity, cash, holdings, FIFO gain, allocation and closed-group strategy reports with immutable input evidence. Income remains explicitly unavailable pending income posting.
- Personal saved report filters with revision checks; saved reports retain their original result after later corrections.

Record funding before purchases. For an account with pre-existing cash or shares, enter its documented opening balances in Settings before ordinary posting; all entries in that account share one opening date. Existing accounts with posted history use the dedicated retroactive-opening API, which validates later cash and lots. Dates are date-only; same-day opening entries precede ordinary events, and corrections keep their source order. Ordinary earlier-dated posting remains blocked; use the dedicated historical APIs for reviewed entries. Account and asset currencies must match; no cross-currency posting, overdraft, shorts or unsupported instruments. Manual prices and FX in Reports provide dated valuation. Missing values remain null; stale observations are labeled. Foreign basis and proceeds use their historical FX, rather than the current rate.

This is a staged ledger and journal build, not the complete MVP. Trade-linked fill corrections remain. Imports/reconciliation/exports/restore testing, income and other accounting actions, provider integrations and operational release gates remain. Image processing is bounded and synchronous per file; asynchronous processing and crash/orphan reconciliation remain operational release work. See [requirements and phase gates](docs/requirements.md).

See the [remaining-work checklist](docs/remaining-work.md) for the prioritized implementation sequence and hosting/pilot prerequisites.

The admin screen groups forms into Overview, Transactions, Trade Journal, Strategies, Calculators, Research, Reports and Settings tabs. Switching tabs preserves unsaved inputs; Settings is available to owners and managers and now includes opening balances. Image controls include contextual tooltips and a clearly labelled stage dropdown. Quote currency explains valid examples and the matching cash account rule. Image upload health and per-file errors identify missing host prerequisites and upload rejections. Image memory-limit errors give a resize and hosting remedy.

## Development

- Plugin entry point: `ig-trading-journal.php`
- Domain: `src/Domain/`; application and workspace permissions: `src/Application/`
- WordPress persistence: `src/Infrastructure/`; REST: `src/Http/`; admin UI: `src/Admin/` and `assets/`
- Translations: `languages/`
- Follow the WordPress Coding Standards for PHP changes.

Run `php tests/run.php` (BCMath required), PHP syntax checks, and JavaScript syntax checks. Use `composer install` and `composer check-cs` for the locked WordPress coding-standard tools. [Validation instructions](docs/testing.md) include the disposable WordPress integration suite. Keep all real portfolio records out of fixtures.
