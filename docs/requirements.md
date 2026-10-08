# PRD implementation coverage

Source: `TG_Investment_Tracker_PRD.pdf`, v1.0, October 2, 2026. Current build: 0.26.0, schema 8. Coverage describes implemented development workflows; complete PRD phase/MVP and production acceptance are not claimed.

Development source: 0.27.0-dev/schema 13. Owner's latest priority is [financial data integration](financial-data-integration.md). Provider identity/quote evidence, shared request quotas, disabled-by-default transport, owner controls and explicit quote/fundamental schedules are implemented. Manual/provider stock valuation selection, all-account totals, saved fundamental history/metrics and previous available reporting-period differences are implemented. AI policy/enrollment persistence and shared atomic reservations are implemented internally. Owner AI controls/reviews/transport, corporate-action compatibility for daily change, exchange calendars and verified growth comparisons remain pending. The released ZIP is unchanged.

| Requirements | Current implementation | Remaining gate |
| --- | --- | --- |
| ACL 01-04 | Explicit memberships/capabilities, revocation, owner protection; journal/media services and streaming check workspace/relationships; retention jobs recheck their authorizing owner | Delegated posting, invitations/support grants, export and broader worker isolation/matrix |
| Sections 8/9, OPS 01 | Versioned InnoDB/utf8mb4 custom tables, scoped indexes, additive schema 1-through-7-to-8 reactivation and named migration lock | Future modules, interrupted-upgrade/forward-repair and full host/matrix evidence |
| TX 01-02, CAL 01/03 | Draft edits/promotion, exact decimal FIFO, native cash/basis/gains, atomic ledger and audit, idempotency, no overdraft/oversell | Remaining classified accounting actions/valuation |
| TX 07-09 | Immutable posted history, date-only precision, draft revision checks; promoted fill keeps its trade. Documented opening cash/lots preserve acquisition date and known/unknown basis; reviewed retroactive openings replay later history, and unknown basis gains append-only revision evidence. Cash and unlinked security corrections use revision-checked source/replacement links; historical cash/buys/sells and later events are validated by chronological replay. Versioned snapshots retain current lots, gains and allocations while old posted evidence remains. | Trade-linked fill corrections, imported identity and large-history performance |
| JR 01-02 | Trade groups/fills, optional thesis/rationales/levels/stop/target/confidence/emotions/lessons/tags/notes; checked confluences plus text; original sheet confluences preserved; immutable bounded journal history | Import mapping and richer search/filter/analytics |
| JR 03 | Strategy name/status, safe rich description/rules/tags, immutable versions; trade captures a workspace-scoped version | Richer editor, analytics and complete localization/a11y review |
| MED 01-06 | Multiple optional JPEG/PNG/WebP files, owner policy/count/storage reservation, MIME/magic/decoder/memory bounds, random private keys, authenticated originals/thumbs, normalized files and metadata stripping | Actual Apache/GD/storage configuration and complete hosting/security matrix |
| MED 07-09 | Independent multipart upload/progress/cancel, retry or pending-file replacement, idempotent finalize, ready/failed states, captions/alt/stage/timeframe/order/comparison, soft deletion/restore, bounded owner-authorized retention cron | Synchronous processing stages are transactional; asynchronous processing leases, crash/orphan reconciliation, fuller job status/monitoring and backup expiry evidence remain |
| API 01-04, SEC 01/04 | Authenticated/private versioned API, decimal strings, WP cookie/nonce checks, object/capability checks, strict JSON keys, idempotency/revisions, bounded list/history pages and private binary routes | Detailed field errors and broader abuse/rate/host tests |
| AUD 01-03 | Append-only ledger/journal/strategy revisions, actor/UTC/correlation evidence, media lifecycle/metadata audit; correction links and replay runs retain source/replacement, reason and prior calculations | Stronger old/new role/media payloads, independent digests/export |
| PF 01, UX 01/03/04/05 | Dated native/base holdings, cash, FIFO basis/gains, manual prices/FX and explicit null/stale coverage; desktop/360-pixel journal/gallery workflow; workspace-zone history presentation | Income posting, wider asset/accounting coverage and full WordPress-theme/browser/WCAG audit |
| CA 01-02 | Exact-decimal spot/linear leveraged crypto, stock long/short profit, bought-option premium/expiry and long/short position-risk scenarios, separate fee inputs, optional note, private REST and admin tab; no ledger posting | Broader scenario/strategy analytics and accessibility review |
| WL 01, RS 01 | Private manual watchlists with asset identity, targets, thesis, tags and user-set status; separate sanitized authored research with immutable revisions and admin tab | Provider observations/history, richer search and migration mapping |
| CAL 02/04/05/06, RP 01, UX 02, MKT 02 | Dated manual prices/direct native-to-base FX with expiry, sources, reasons and immutable corrections; acquisition/disposal/valuation-date FX; immutable filtered report inputs/results; cash-inclusive allocation, unrealized return, coverage-aware economic gain; closed/flat/fully posted strategy groups with explicit fee and break-even policy; personal saved views | Income accounting, CSV/portable export, provider policy, larger-history asynchronous reporting and host/a11y gates |
| OPS 02, PRI 02 | Deactivation/uninstall preserve data; private retained trash policy; no external telemetry/AI/providers | Closure/privacy/export, consistent DB/media restore and full operational recovery |
| DEV 01-08 | Contracts, deterministic fixtures, SQL/API/setup/decision docs, locked PHP and browser test tools | CI compatibility matrix, discovery/reconciliation/cutover and release signoff |

The owner's order is authoritative: **journals/strategies/multiple images; remaining ledger revisions/replay/opening balances; watchlists/research/calculators/reports; imports/reconciliation/exports/restore testing**. See `remaining-work.md` for current gates within that order.

Established workspace defaults: USD and America/New_York. Proposed media defaults are prefilled but require an explicit owner policy save; local private storage requires host configuration. Full source discovery, retention/backup operations, licensed providers and commercial policies remain open. No production migration or deployment has occurred.

## October 6, 2026: cached provider holdings and stock totals

Development source remains 0.27.0-dev/schema 10; the released ZIP remains 0.26.0/schema 8. Overview now offers an explicit manual/FMP/Alpha Vantage stock price source and exact open-stock market value and unrealized gain/loss totals across all authorized accounts and asset pages. Other asset classes keep manual pricing. Values are grouped by native currency, exclude cash and closed positions, and do not change with table filters.

Provider valuations require the latest enabled matching mapping and a saved quote no more than three calendar days old. Missing/stale provider prices remain unavailable without fallback. Unknown basis leaves gain unavailable. Partial coverage shows covered subtotals; mixed price dates cannot claim a complete total. Source preference is scoped to the current user/workspace browser session. Existing posted facts, manual observations and saved reports remain unchanged; reads send no external requests.

Validation: 58 deterministic unit checks and 110 disposable WordPress/MySQL integration checks passed, including full pagination, source validation, membership, staleness, mapping replacement, unknown basis and immutable manual reports. Coding standards, PHP/JavaScript syntax and six REST URL checks passed. The cached valuation browser fixture passed source switching, exact values, missing coverage, scoped restoration and desktop/mobile layout. No new SQL, live provider calls or release ZIP.

Next: corporate-action compatibility before displaying daily change, then fundamental snapshots and AI summaries with configurable model and monthly budget. Exchange holidays and operational monitoring remain open.
Fundamental parsing foundation: exact overview and annual/quarterly statement normalization is implemented internally; snapshot persistence, comparable metrics, fundamental dispatch/scheduling and AI review controls remain pending. See [evidence contract](fundamental-evidence-contract.md).

Development schema 11 now stores immutable fundamental snapshots and binds provider requests to datasets under the existing shared quota. See [migration](011-fundamental-snapshots.sql). Fundamental transport, metrics, review UI and AI processing remain pending.

Snapshot-backed exact fundamental metrics are now implemented internally. See [formula contract](fundamental-metric-formulas.md). Growth/comparison contracts, provider transport and fundamental/AI review workflows remain pending.

Fundamental transport and internal owner-authorized one-shot jobs are implemented, separately disabled by default. See [worker contract](fundamental-refresh-worker.md). Review UI, recurring enrollment, comparable-period changes and AI summaries remain pending.

Fundamental history, explicit snapshot metrics and owner-only on-demand refresh now have authenticated REST routes. See [API contract](fundamental-api.md). Research/journal controls, recurring enrollment and AI summaries remain pending.

Development Research now exposes fundamental snapshot history, statement metric details and explicit owner-only single-dataset refresh controls. See the development section in [user guide](user-guide.md). AI investment reviews and recurring fundamentals remain pending.

Development stock journals now link to the selected asset's saved fundamentals through guarded Research navigation. Unsaved-change confirmation, scoped selection, Back and reload are covered. Recurring enrollment, comparable-period changes and AI review controls remain pending.

Development schema 12 adds explicit per-dataset weekly fundamental enrollment, owner Research controls, DST-aware scheduling and recoverable cron jobs. See [schedule contract](fundamental-schedules.md) and [separate SQL](012-fundamental-schedules.sql). Saved reporting-period metric differences now have exact prior/current values, visible gaps, currency compatibility checks and Research tables; see [comparison contract](fundamental-period-comparisons.md). Verified annual/quarterly growth and configurable AI summaries/model/budget remain pending. Earlier milestone notes above describe historical scope.

The internal [AI spending policy](ai-budget-policy.md) supplies exact token costs, dated model/pricing validation, 80%/90% warnings, zero-budget pause, month boundaries and uncertain settlement arithmetic. [Schema-13 persistence](ai-spending-persistence.md) now coordinates shared atomic reservations and saves policy/enrollment/request revisions. Configurable owner screens and generated summaries remain pending.

Development AI owner controls now prepare shared model/monthly budget and separate workspace consent through private revisioned routes. Processing remains unavailable; catalog-to-dispatch verification, approved evidence bundles and saved reviews remain pending. See [AI Settings](ai-settings.md).

AI summary input foundation now projects immutable snapshot metrics and an explicitly selected current thesis revision into a bounded deterministic preview. No approval, paid processing or new UI is enabled. See [preview contract](ai-evidence-preview.md).

Internal fingerprint-bound owner approval and immutable evidence storage are implemented in development schema 14, with actor-bound idempotent retries and transactional audit rollback. No approval UI or paid processing is enabled. See [approval contract](ai-evidence-approvals.md).

Owner REST/UI evidence preview and approval controls are now implemented in development Research. Explicit snapshot/thesis selections, stale-selection invalidation, immutable approval reads and lost-response retries are covered. AI-generated reviews, complete review-history browsing, dispatch catalog enforcement and transport remain pending; processing is unavailable.

Development schema 15 adds internal immutable AI review storage and bounded structured output/citation validation. Saved reviews require matching approved evidence and settled original-owner requests; no browser output submissions, external calls or spending mutations are introduced. Full REST/UI review history, provider response/usage verification, dispatch-time catalog/consent/budget checks and disabled-by-default text transport remain pending. Future packaging requires all fifteen migrations. See [review storage contract](ai-review-storage.md).

Owner REST/UI review-history browsing is now implemented in development Research: all-page scoped metadata, immutable detail/provenance, escaped model text, read-failure clearing and stale-response guards. Generation, explicit execution/approval-ID binding, provider response/model/usage adapter and dispatch catalog/consent/budget enforcement remain pending. Schema remains 15; no new migration or release ZIP.


Development schema 16 now binds AI reservations to an exact approval ID and enforces credential-bound catalog evidence at admission and dispatch. Bound review provenance rejects substituted approvals. No transport or paid processing is enabled; generation controls, prompt/token bounds and verified response/usage handling remain pending. See [execution binding](ai-execution-binding.md) and [migration](016-ai-request-approvals.sql).


Development schema 17 now records immutable server Responses receipts and budget settlement atomically, with separate guarded publication. Exact model/usage validation, refusal/incomplete handling, unknown-cost holds, token-bound quarantine and immutable recovery are implemented internally. Sending/generation remains disabled and unimplemented pending the full prompt/token-bound contract. See [response receipt contract](ai-response-receipts.md) and [migration](017-ai-response-receipts.sql).

The internal versioned full prompt and input-count projection are implemented: exact approved bytes, strict cited JSON schema, explicit total output bound, no tools/storage/background, and separate full-request/input fingerprints. See [prompt contract](ai-prompt-contract.md). Schema remains 17; trusted count receipts, persisted execution binding, guarded transport and owner generation controls are still pending. No external requests or release package were made.

## 0.27.0-dev.1: Research/Settings navigation and setup clarity

Owner installation feedback identified fundamental scheduling controls that looked active while their entire form was inert. Replaced that behavior with native disabled dropdowns/save, an explicit prerequisite/load explanation and Off-dependent weekday availability. Disabled values remain in the dirty-state snapshot; workspace changes reset schedule readiness. Enabled mappings still support pending schedule enrollment without enabling server requests. Inactive AI settings/evidence fields now visibly disable while retaining values and uncertain approval identity.

Research and Settings share a sticky wrapping section shortcut menu that moves focus and opens existing details without rebuilding forms, changing routes or discarding drafts. Role-hidden targets are omitted. Settings now explains server-side provider credentials and the separate refresh switches; OpenAI key handling/generation are explicitly unavailable. The updated user guide adds current-build setup and step-by-step stock mappings, quote valuation, dataset refresh, schedules, quota planning and AI preparation workflows. Runtime version 0.27.0-dev.1 refreshes browser asset URLs; schema stays 17 and no new SQL is needed.

Development schema 18 adds verified complete input counts and immutable execution manifests linked atomically to reservations. Counts bind exact approved evidence, prompt, model, credential and conservative input/full-output pricing; stale or contradictory dispatch evidence is rejected. Legacy reservations are not retroactively bound. See [execution contract](ai-execution-manifests.md) and [migration](018-ai-execution-manifests.sql). Sending and generation controls remain pending.

OpenAI credential preparation is local-only: server constant recognition and redacted readiness in API setup, with no claim of authenticated access. The owner subsequently authorized the [sender proposal](openai-sender-proposal.md), and the disabled internal Responses sender is now implemented. No live data was sent.

After explicit owner authorization, the disabled internal Responses sender is implemented. It dispatches exact workspace-scoped manifests once, rechecks consent/catalog/prices/budget/count freshness, uses bounded fixed HTTPS transport and retains holds on uncertain delivery or receipt failure. There is no generation route or schedule. The internal [count transport](ai-count-transport.md) now rechecks consent and budget and requires verified zero-charge counting evidence. Trusted model/pricing/count-cost acquisition and owner controls remain pending; see [sender contract](ai-http-transport.md).

Trusted server configuration now resolves selected-model catalog/pricing and counting-cost evidence through AiConfiguration, revalidating credential and expiry on every load. It supplies no fabricated verification or default model/rates and enables no requests. Automatic evidence acquisition and owner generation controls remain pending. See [server evidence](ai-server-evidence.md).

The disabled internal AiGeneration coordinator now connects server evidence, complete-input counting, verified reservation and one-time delivery. Workspace/key coordination rejects overlap and conflicting retries; existing requests return state without recount/resend. Publication remains separate. No owner generation route/control is added. See [coordination contract](ai-generation-coordinator.md).

Owner Settings now projects reviewed AI readiness flags and all cursor pages of scoped saved request metadata, with shared collection/search/density patterns. No secrets, prompt/usage bodies or processing routes are exposed. Source 0.27.0-dev.2 refreshes assets; schema stays 18. See [readiness/activity](ai-readiness-activity.md).

Owner activity now exposes guarded Save as review for completed settled receipts. The empty-body publication route rejects client output, uses original-owner source checks and immutable audited retries, and changes no charges or posted history. Source 0.27.0-dev.3/schema18; no SQL or ZIP. See [publication](ai-stored-publication.md).


October 8, 2026: Owner recovery now includes cancelling original-owner unsent AI reservations from the shared activity table. Dispatch/uncertain/receipt-backed work remains protected. Unsaved Settings values survive recovery; unclear outcomes require explicit reload. See ai-unsent-cancellation.md. Generation controls remain unavailable pending their next verification slice.


October 8, 2026: Approved AI evidence now offers Check summary setup in Research. The local preflight explains saved model, consent, budget and server evidence blockers without counting, reserving or sending. Generation remains unavailable pending genuine evidence verification and guarded enable/delivery controls. See [preflight guide](ai-generation-preflight.md). Source 0.27.0-dev.5/schema18; no SQL or ZIP.
