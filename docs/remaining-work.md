# Remaining work after the initial ledger check-in

Current build: 0.3.0. This is a working first ledger slice, not the complete PRD MVP. Requirement-by-requirement coverage is in `requirements.md`; contracts and validation evidence are in `api.md` and `implementation-status.md`.

## Implemented

- Explicit workspace ownership/membership, roles, revocation and last-owner protection.
- Accounts and stock/ETF/crypto assets; USD and America/New_York new-workspace defaults.
- Deposits, withdrawals, buys, sells and editable non-posting drafts, immutable revision history and atomic promotion.
- Exact decimal FIFO, fee-inclusive basis, partial sales, native cash and realized gains.
- Atomic posting, immutable initial revisions, audit records and idempotency.
- Authenticated workspace-scoped REST API, pagination and WordPress admin forms; plain permalink support.
- Automatic schema installation and separate manual SQL file: `001-ledger-foundation.sql`.

## Next implementation slices

1. **Complete ledger revision workflows:** corrections with reasons and expected revisions, chronological replay, historical entries and documented opening cash/asset lots.
2. **Complete accounting and valuation:** dividends/withholding, interest, fees, rewards/reinvestment policies, linked cash/asset transfers, swaps and splits; transaction-date and valuation-date FX; manual prices and provenance; holdings market values and complete dashboard totals.
3. **Trade journal and multiple private images:** trade groups/fills, journal fields, strategies/version snapshots, private storage, authenticated originals/thumbnails, image validation/normalization, quotas, job queue, captions/order/comparison, retry and recoverable deletion.
4. **Migration and portability:** full workbook inventory, import mapping/preview, duplicate/change detection, per-row outcomes, reconciliation, controlled commit, CSV/portable exports including image manifests, and restore verification.
5. **Spreadsheet parity:** watchlists, research, calculators, saved views/filters, income/activity/allocation reports and strategy analytics.
6. **Commercial readiness:** extension/provider and entitlement interfaces, licensing/billing adapters, hosted provisioning, support access, quotas and operational/legal/provider review. No provider or billing vendor is selected yet.

## Before staging/pilot or production use

- Confirm BCMath is enabled for WordPress's Apache PHP 8.1 runtime. It is not established by the provided screenshots.
- Enable HTTPS and update WordPress home/site URLs. The current production HTTP setup is intentionally refused by the plugin API.
- Select private media storage, limits, retention and consistent backup infrastructure before the image slice.
- Test on the actual Ubuntu/PHP 8.1.2/MySQL 8.0.46 host; local validation uses PHP 8.1.34/WordPress 7.1.2/MySQL 8.0.26.
- Complete browser/mobile/accessibility review, HTTP cookie/application-password and CSRF tests, broader malicious-input cases, CI/runtime matrix, schema repair/upgrade tests and performance benchmarks.
- Complete privacy/closure and export/retention workflows; rehearse recovery and source import before cutover.

## Validation available now

31 unit/accounting/role tests, 22 real WordPress/MySQL integration checks and 6 REST URL regression cases. Integration checks include two-workspace denial, revocation, idempotency, full rollback after a forced failure and concurrent oversell prevention. PHP/JavaScript syntax and WordPress coding-standard checks pass. These do not constitute complete PRD acceptance or a production deployment.

Posted history cannot currently be corrected or backdated, and market values are missing rather than guessed. Keep this build on a disposable/staging installation until the remaining release gates are met.
