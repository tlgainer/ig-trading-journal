# Prioritized remaining work

Current build: 0.8.1, schema 6. This checklist follows the owner's explicit order. The ledger and journal workflows are usable development slices; complete PRD MVP and production acceptance remain pending.

## Owner priority order

1. **Trade journals, strategies, and multiple private images.** Implemented: trade groups and linked entry/exit fills, optional journal fields, checked confluences and original sheet text, immutable journal revisions, strategy snapshots, multiple JPEG/PNG/WebP images, private authenticated originals/thumbnails, normalization, quotas, individual retry/replacement, captions/order/comparison, recoverable trash and bounded owner-authorized retention jobs. Remaining: asynchronous processing/lease recovery and crash/orphan reconciliation, richer filtering/strategy analytics, full accessibility and hosting validation, operational job monitoring and consistent media backup/restore evidence.
2. **Draft promotion, corrections, historical replay, and opening balances.** Draft editing/promotion and documented opening cash/asset lots are implemented. Cash and unlinked security corrections use reasons, expected revisions and immutable replacements; historical deposits/withdrawals/buys/sells and retroactive openings validate later balances and FIFO. Unknown opening basis has append-only revisions; versioned replay runs retain recalculated allocations and gains. Next: trade-linked fill correction and large-history performance. Ordinary backdating remains blocked.
3. **Watchlists, research, calculators, and reports.** Exact crypto-profit and long-position risk scenarios are available without ledger posting. Next: manual watchlists/research, saved views/filters and activity/holdings/gain/income/allocation/strategy reports. Valuation-dependent reports need manual prices, FX provenance and clearly incomplete totals before providers are added.
4. **Spreadsheet imports, reconciliation, exports, and restore testing.** Next: full workbook inventory, mapping/preview, duplicate/change detection, per-row outcomes, reconciliation, controlled commit, CSV/portable export with image manifests, and verified database/private-byte restore.

## Other PRD work after the ordered priorities

Dividends/withholding, interest, fees, rewards/reinvestment, linked transfers, swaps and splits; remaining accounting fixtures; provider adapters and entitlements; billing/licensing/hosted provisioning and commercial operational/legal review. No provider or billing vendor has been chosen.

## Before staging/pilot or production use

- Confirm BCMath in WordPress's Apache PHP 8.1 runtime. Screenshots do not establish its presence.
- Enable HTTPS and update both WordPress URLs. Production HTTP is intentionally refused by the API.
- For images, enable GD, configure a directory outside all public roots with server deny rules, and save the workspace owner's quota/retention policy. See `private-images.md`.
- Back up before reactivation/schema upgrade; keep all six SQL files in the plugin package. Manual basis-resolution SQL is in `006-opening-basis-resolutions.sql`.
- Validate on the actual Ubuntu/PHP 8.1.2/Apache/MySQL 8.0.46 host; local evidence uses PHP 8.1.34/WordPress 7.1.2/MySQL 8.0.26.
- Complete minimum-version/MariaDB/CI matrix, broader accessibility and abuse testing, sustained performance, privacy/closure and recovery gates.

## Validation

Current fixtures cover domain accounting/roles, real WordPress/database isolation and revisions, migration repetition, concurrent quota reservations, bounded history, native image decoding, real multipart/cookie/nonce delivery, rollback after encoding and desktop/360-pixel browser workflows. Exact commands and results are in `testing.md` and `implementation-status.md`.

No production database, server or deployed plugin was changed. Prices and base totals remain visibly missing; cash/security corrections and historical cash/security posting are available through private APIs, with trade-linked fill corrections still gated.
