# Prioritized remaining work

Latest released build: 0.26.0/schema 8; development source: 0.27.0-dev/schema 11. This checklist follows the owner's explicit order. The ledger and journal workflows are usable development slices; complete PRD MVP and production acceptance remain pending.

Latest owner priority: [financial data integration](financial-data-integration.md), before queued subaccount/currency extensions. Exact quote parsing/daily movement, provider mapping/quote persistence, shared request quotas and disabled-by-default Alpha Vantage transport/one-shot jobs are implemented internally. FMP transport, owner controls and explicit weekday scheduling are now implemented. Provider/manual stock valuation selection and all-account totals are implemented. Next: corporate-action compatibility for daily change, exchange-holiday calendars, fundamental snapshots with AI summaries and configurable model/monthly budget, then related news/events. Imports and other original PRD gates remain pending below.

UI/UX work is underway after the reporting slice. The shared-shell/table foundation and Trade Journal collection/detail navigation are implemented; see [screen coverage](ui-ux-coverage.md). The remaining original PRD backlog stays listed below.

## Owner priority order

1. **Trade journals, strategies, and multiple private images.** Implemented: trade groups and linked entry/exit fills, optional journal fields, checked confluences and original sheet text, immutable journal revisions, strategy snapshots, multiple JPEG/PNG/WebP images, private authenticated originals/thumbnails, normalization, quotas, individual retry/replacement, captions/order/comparison, recoverable trash and bounded owner-authorized retention jobs. Remaining: asynchronous processing/lease recovery and crash/orphan reconciliation, richer filtering/strategy analytics, full accessibility and hosting validation, operational job monitoring and consistent media backup/restore evidence.
2. **Draft promotion, corrections, historical replay, and opening balances.** Draft editing/promotion and documented opening cash/asset lots are implemented. Cash and unlinked security corrections use reasons, expected revisions and immutable replacements; historical deposits/withdrawals/buys/sells and retroactive openings validate later balances and FIFO. Unknown opening basis has append-only revisions; versioned replay runs retain recalculated allocations and gains. Next: trade-linked fill correction and large-history performance. Ordinary backdating remains blocked.
3. **Watchlists, research, calculators, and reports.** Exact spot/linear leveraged crypto-profit, stock long/short profit and long/short position-risk scenarios, manual watchlists and authored research with revisions are available. Manual price/FX observations and correction history, personal saved filters, immutable dated activity/cash/holdings/gain/allocation/closed-strategy reports and coverage-aware economic gain are implemented. Income reports explicitly await dividend/interest posting. Provider research facts remain separate and need a source policy.
4. **Spreadsheet imports, reconciliation, exports, and restore testing.** Next: full workbook inventory, mapping/preview, duplicate/change detection, per-row outcomes, reconciliation, controlled commit, CSV/portable export with image manifests, and verified database/private-byte restore.

## Other PRD work after the ordered priorities

Owner additions for subaccounts, USDT/USDC, margin, leveraged crypto scenarios, stock short/profit and options calculators are recorded in [next requirements](next-requirements.md). Calculator support must remain separate from derivative/short financial posting.

Dividends/withholding, interest, fees, rewards/reinvestment, linked transfers, swaps and splits; remaining accounting fixtures; provider adapters and entitlements; billing/licensing/hosted provisioning and commercial operational/legal review. No provider or billing vendor has been chosen.

## Before staging/pilot or production use

- Confirm BCMath in WordPress's Apache PHP 8.1 runtime. Screenshots do not establish its presence.
- Enable HTTPS and update both WordPress URLs. Production HTTP is intentionally refused by the API.
- For images, enable GD, configure a directory outside all public roots with server deny rules, and save the workspace owner's quota/retention policy. See `private-images.md`.
- Back up before reactivation/schema upgrade; keep all eight SQL files in the plugin package. Watchlist/research SQL is in `007-watchlists-research.sql`; valuations/reports/views are in `008-valuations-reports.sql`.
- Validate on the actual Ubuntu/PHP 8.1.2/Apache/MySQL 8.0.46 host; local evidence uses PHP 8.1.34/WordPress 7.1.2/MySQL 8.0.26.
- Complete minimum-version/MariaDB/CI matrix, broader accessibility and abuse testing, sustained performance, privacy/closure and recovery gates.

## Validation

Current fixtures cover domain accounting/roles, real WordPress/database isolation and revisions, migration repetition, concurrent quota reservations, bounded history, native image decoding, real multipart/cookie/nonce delivery, rollback after encoding and desktop/360-pixel browser workflows. Exact commands and results are in `testing.md` and `implementation-status.md`.

No production database, server or deployed plugin was changed. Missing/stale quotes and FX are explicit; base report values are calculated only with declared coverage; cash/security corrections and historical cash/security posting are available through private APIs, with trade-linked fill corrections still gated.

Linear leveraged crypto scenarios are available in 0.20.0. Stock long/short profit and short risk are available in 0.21.0. Bought call/put premium and expiry scenarios are available in 0.22.0. Remaining extensions include subaccount and currency identity design, then reviewed product-specific margin/derivative accounting. Sold options are deferred by owner request. These extensions do not enable margin/short/options ledger posting.

Fundamental parsing foundation: exact overview and annual/quarterly statement normalization is implemented internally; snapshot persistence, comparable metrics, fundamental dispatch/scheduling and AI review controls remain pending. See [evidence contract](fundamental-evidence-contract.md).

Development schema 11 now stores immutable fundamental snapshots and binds provider requests to datasets under the existing shared quota. See [migration](011-fundamental-snapshots.sql). Fundamental transport, metrics, review UI and AI processing remain pending.

Snapshot-backed exact fundamental metrics are now implemented internally. See [formula contract](fundamental-metric-formulas.md). Growth/comparison contracts, provider transport and fundamental/AI review workflows remain pending.
