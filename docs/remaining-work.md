# Prioritized remaining work

Latest released build: 0.26.0/schema 8; development source: 0.27.0-dev.1/schema 18 (latest test ZIP: 0.27.0-dev.1/schema 17). This checklist follows the owner's explicit order. The ledger and journal workflows are usable development slices; complete PRD MVP and production acceptance remain pending.

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

Fundamental transport and internal owner-authorized one-shot jobs are implemented, separately disabled by default. See [worker contract](fundamental-refresh-worker.md). Review UI, recurring enrollment, comparable-period changes and AI summaries remain pending.

Development weekly fundamentals are now implemented: explicit owner enrollment, per-dataset weekday slots, shared quota, recovery and revocation checks. Previous available reporting-period metric changes are also implemented with explicit gaps, currency blocking and percentage-point differences. Verified year/quarter growth, reporting-duration/accounting-policy compatibility and raw revenue/earnings growth remain pending. Remaining financial-data work includes configurable AI summaries/model/budget, daily-change/corporate-action compatibility and live entitlement/host acceptance. See [schedule contract](fundamental-schedules.md) and [comparison contract](fundamental-period-comparisons.md). Earlier milestone notes above describe historical scope.

AI cost policy and [schema-13 shared spending persistence](ai-spending-persistence.md) are implemented internally. Next: owner model/budget controls and verified catalog, approved evidence/thesis bundles, immutable review history and disabled-by-default text transport. No paid processing is enabled by the budget foundation.

An internal credential-bound model evidence gate is now available; wiring it into owner Settings and dispatch remains pending. See [catalog contract](ai-model-catalog.md).

Owner model/budget preparation controls and separate workspace consent are implemented in Settings. AI processing is still unavailable. Next: bind verified catalog evidence to dispatch, approved saved fundamentals/thesis bundles, immutable review history and disabled-by-default transport. See [AI Settings](ai-settings.md).

An internal owner-only bounded evidence/thesis preview is implemented; it does not record approval or generate summaries. Fingerprint-bound approval, evidence/review persistence, dispatch catalog enforcement and text transport remain pending. See [preview contract](ai-evidence-preview.md).

Development schema 14 now persists owner approval of exact preview fingerprints through an internal immutable operation. Owner REST/UI approval controls, saved generated reviews and dispatch/transport remain pending. Keep all fourteen migrations for future packaging. See [approval contract](ai-evidence-approvals.md).

Owner evidence preview/approval controls are now available in development Research, with exact evidence disclosure and safe uncertain retries. Next: immutable generated reviews and full history browsing, dispatch-time catalog/consent/budget checks and disabled-by-default text transport. Development remains schema 14; no new migration or release ZIP for these controls.

Internal review storage and cursor history are now implemented in development schema 15, with exact model matching, approved-source citations, immutable retries and integrity-checked owner reads. Remaining AI work: REST/UI review-history browsing, explicit execution/approval-ID binding, verified response/model/usage adapter, catalog/consent/budget dispatch checks and disabled-by-default transport. Overrun/quarantine and semantic claim checks remain explicit follow-ups. No paid processing is enabled. See [review storage](ai-review-storage.md).

Owner saved-review history controls are now available in development Research, using all scoped cursor pages and original approved evidence. Next AI work is generation/execution controls, immutable approval-ID binding at admission, verified response/model/usage handling and catalog/consent/budget-gated disabled-by-default transport. Schema remains 15; no additional SQL for this UI slice.


Development schema 16 now binds AI reservations to an exact approval ID and enforces credential-bound catalog evidence at admission and dispatch. Bound review provenance rejects substituted approvals. No transport or paid processing is enabled; generation controls, prompt/token bounds and verified response/usage handling remain pending. See [execution binding](ai-execution-binding.md) and [migration](016-ai-request-approvals.sql).


Development schema 17 now records immutable server Responses receipts and budget settlement atomically, with separate guarded publication. Exact model/usage validation, refusal/incomplete handling, unknown-cost holds, token-bound quarantine and immutable recovery are implemented internally. Sending/generation remains disabled and unimplemented pending the full prompt/token-bound contract. See [response receipt contract](ai-response-receipts.md) and [migration](017-ai-response-receipts.sql).

The versioned complete prompt and input-count projection are now implemented internally, preserving exact approved evidence and including instructions, message framing and JSON schema. Schema remains 17. Trusted token-count receipts, persisted execution binding, HTTP transport and owner generation controls remain pending. See [prompt contract](ai-prompt-contract.md).

## Current AI summary milestone

Verified complete input counts and immutable execution manifests now bind approved evidence, exact model, credential, prompt, output ceiling and conservative reserved cost. Schema 18 is additive; see [execution contract](ai-execution-manifests.md). Next highest priority: consent-gated count/Responses HTTP transport, credential setup and explicit owner generation controls. AI summary generation remains unavailable. No new ZIP is produced for this internal slice.

## OpenAI connection preparation

Development source now recognizes the server-only TGIT_OPENAI_API_KEY and shows a redacted configuration status under Settings > API setup. It verifies neither account access nor pricing and enables no requests. Adding the external sender was rejected by automatic approval review; the reviewable payload/destination and requested implementation authorization are in [sender proposal](openai-sender-proposal.md). Count transport and generation controls remain pending. No schema change or new ZIP for this slice.
