# Financial data integration and AI fundamental reviews

Owner-confirmed scope, October 5, 2026. This extends the original PRD. Latest released package remains 0.26.0/schema 8. Development source is now 0.27.0-dev/schema 13; integration is unfinished and no new installable release is claimed.

## Scope and priority

1. Automatically value stock holdings, showing unrealized gain/loss, daily price movement and combined stock totals across accounts.
2. Collect fundamental snapshots on a schedule and on demand from the trade journal. **Include an AI summary of these fundamentals**, changes since the preceding review and implications for the recorded investment thesis. AI is part of the fundamental review, not only a separate news feature.
3. Add sourced news, related-company relationships and upcoming company/economic events to identify indirect exposure and catalysts.

This is the latest owner priority, ahead of queued subaccount/currency extensions. Reuse the existing WordPress admin shell, cards, collections, controls and readable decimal display. No React migration.

## Free-tier constraints

Owner has FMP Basic/free (250 requests/day) and Alpha Vantage free (25 requests/day). Request allowance is separate from endpoint/symbol entitlement. FMP Basic emphasizes end-of-day historical and profile/reference data; other datasets/symbols can be restricted. Alpha Vantage's default quote updates at end of day. Do not promise intraday freshness, quarterly statements, calendars, peers or transcripts before verifying permitted access for the requested identity.

References checked October 5, 2026: [FMP plans](https://site.financialmodelingprep.com/developer/docs/pricing), [Alpha Vantage documentation](https://www.alphavantage.co/documentation/), [Alpha Vantage limits](https://www.alphavantage.co/support/), [OpenAI web search](https://developers.openai.com/api/docs/guides/tools-web-search).

Keys belong in server configuration, never repository, browser responses, logs or exports. Development uses deterministic mocked responses; no real keys, production portfolio records or paid API calls are needed.

## Slice 1: provider foundation and stock valuations

- Add server-side provider adapters and safe JSON normalization: financial numbers remain decimal strings, including exponent notation, without binary-float rounding.
- Resolve identity by symbol, exchange and currency. Unsupported or unresolved mappings cannot supply a quote.
- Preserve append-only provider observations with market/retrieval timestamps, source, currency, session, delay/status and previous regular-session close. Existing date-only manual observations lack this detail; do not silently repurpose them. Document explicit provider/manual selection policy.
- Calculate market value and unrealized gain from existing quantities and remaining basis. Unknown basis or missing/stale prices produce explicit incomplete coverage, never invented zero values. Provider refreshes do not change posted cash, basis, units or immutable report snapshots.
- Fixed-holding daily movement is quantity times (quote minus previous regular-session close); percentage uses that prior close. Current-quantity price movement is not transaction-aware account-day P&L when shares were bought/sold during the day. Combined percentages use compatible value denominators, not averages of individual percentages.
- Mixed session dates, missing FX and incomplete quotes produce partial totals with coverage. Require compatible split-adjusted prior close and quantities around corporate actions.
- Default to trading-day end-of-day refresh with a bounded later retry. A second fetch cannot imply a second fresh intraday quote on a free EOD feed. Use New York-aware scheduling and session/holiday dates.
- Use persistent atomic quota reservations shared across every job/workspace using the same credential. Count attempts/retries conservatively; keep capacity for explicit refresh. A rolling 24-hour cap is a conservative default until provider reset behavior is verified. Back off rate limits; stop unsupported/entitlement errors without repeated retries.
- Cache and deduplicate internally without exposing another workspace's data; never fetch on each page load. Server cron invokes bounded WordPress jobs with leases/idempotency to prevent overlapping runs.

Acceptance: exact numeric decoding; identity/currency rejection; session/prior-close handling; missing/stale/partial totals; quota concurrency and retries; revoked access; transport failures; unchanged financial history.

## Slice 2: fundamental snapshots with AI summaries

- Proposed default: staggered weekly updates, refresh after newly available statements and an Analyze holding action in the trade journal. Spread initial collection over days within Alpha Vantage's allowance; reuse unchanged evidence.
- Select FMP where entitled and Alpha Vantage for supported overview, income statement, balance-sheet and cash-flow inputs. Never silently merge conflicting currencies or fiscal periods.
- Preserve immutable snapshots with provider, fiscal period/type, reporting currency, retrieval time and available publication/revision evidence. Compare like periods; annual, quarterly and trailing figures are distinct. Restatements append new evidence.
- Calculate growth, margins, free cash flow, debt/liquidity and valuation metrics in decimal code with defined formulas. Missing inputs and zero denominators remain unavailable.
- Reviews link to a workspace asset, optional trade and the exact thesis revision used. Preserve review history separately from authored journal/research notes.
- AI output: financial overview; changes since the preceding review; strengths; deterioration/risks; evidence supporting or weakening the thesis; missing/stale data; conditions or questions requiring an investment review. No automated sell decision or ledger action.
- Send a bounded evidence bundle of computed metrics, source identifiers, periods and approved thesis text. Account balances, private images, keys and unrelated notes are excluded by default. External text is untrusted evidence, never operational instructions.
- Separate reported facts from interpretation and validate citations against supplied evidence identifiers. Save input fingerprint, evidence links, prompt/model version, generation time, status and usage. Failed or truncated output is visibly failed; unchanged evidence reuses its review unless explicitly regenerated.
- OpenAI processing starts disabled. Owner configures the server key, enables external processing with its stated data scope and selects a spending cap. Mock development continues independently; scheduled AI calls await budget configuration. Verify model pricing when choosing the implementation model.

### Configurable OpenAI model

Owner requested configurable model selection. Add owner-only Settings for the OpenAI model used by fundamental summaries, with an optional separate selection for later web research. Store explicit model identifiers; do not silently substitute a different model after a failure or availability change. Model selection does not itself enable paid processing.

- Offer supported models compatible with the required API/output capabilities; show configured pricing and whether capability/access validation is pending or confirmed. Do not assume every model supports web search or the same request parameters.
- Validate the selected identifier and required capabilities server-side. Unknown pricing, unavailable access or unsupported capabilities prevent new requests with an actionable status while saved reviews remain readable.
- Apply the monthly spending limit using the selected model's applicable input, cached-input, output and tool pricing. Verify current official pricing/capabilities when implementing the catalog; do not hard-code an unverified default in this requirements document.
- Capture the actual model identifier and prompt version on every review. Changing models affects future requests; previous reviews and in-flight request reservations retain their original model and pricing evidence.
- Audit configuration changes. No automatic fallback to a more expensive model; any future fallback policy requires explicit owner configuration and its own budget checks.

Acceptance includes invalid/unavailable selections, capability incompatibility, pricing-dependent reservations, model changes during an in-flight request and immutable review provenance.

### Configurable OpenAI budget

Owner confirmed an initial range of $10-$15 per month. Proposed initial setting: `15.00` USD, editable to `10.00` or another non-negative decimal amount in owner-only Settings; zero pauses new paid requests. This is a planned plugin control, not a provider billing setting or a delivered feature.

- Enforce a site-wide monthly cap for the shared OpenAI credential, with optional workspace limits underneath it. Multiple workspaces must not each receive the entire shared budget. Record configuration changes with actor and audit evidence.
- Display estimated month-to-date spend, reserved in-flight spend, remaining budget and next reset. Proposed reset: calendar month in the configured budget timezone, initially America/New_York. Changing timezone cannot reset accrued usage mid-period.
- Show warnings at 80% and 90%. Before each call, atomically reserve a conservative maximum using bounded input/output and tool use at configured model prices. Block new scheduled and on-demand calls when the remaining budget cannot cover the reservation; no silent override.
- Reconcile reservations with reported usage. Include retries and separately priced tools such as web search. Keep uncertain charges reserved until reconciled, including across monthly boundaries. Fail closed if required pricing is unknown or outdated; label totals as estimates rather than guaranteed invoice amounts.
- At the limit, retain existing reviews and continue free-tier data updates. Explain why AI generation is paused; it can resume next month or after an owner increases the cap.
- The control covers requests made by this plugin. Calls using the same key elsewhere, provider billing adjustments and differences in pricing cannot be constrained by this local cap; recommend a dedicated OpenAI project/key for clearer accounting.

Acceptance adds concurrent reservation enforcement, configurable limits, lowering a cap below existing spend, zero-budget pause, warning thresholds, month rollover, uncertain request outcomes and retained access to saved reviews. Live paid processing remains disabled until the owner explicitly saves its configuration.

Acceptance: reproducible evidence; comparable fiscal periods; immutable review history; valid citations; missing-data handling; thesis revision links; workspace/revocation checks; bounded payloads; failure/retry deduplication; cost controls.

## Slice 3: related news and events

- Track sourced news/events for holdings/watchlists and owner-approved related companies. AI can suggest sourced suppliers, customers, competitors and infrastructure dependencies; business relationships remain separate from changing price correlations.
- Show event source, timezone, estimated/confirmed status, last check and relationship to the holding. Distinguish publication dates from event dates and deduplicate repeated news.
- Proposed output: daily digest and on-demand pre-trade review. Missing calendar/news access is explicit; no claim of exhaustive catalyst detection.
- Web research is a separately configurable cost/data scope. Slice 2 summaries of structured fundamentals do not depend on paid web searches.

## Persistence, operations and release

Schema 9 is isolated in [009-provider-quotes.sql](009-provider-quotes.sql). It adds immutable mapping revisions and quote evidence, plus credential-wide quota pools and workspace-scoped request records. Pools contain only provider identifiers and server-derived credential digests; no credentials or customer data. Customer mappings, requests, quotes and audit evidence remain workspace scoped. Keep all nine migrations in future packaging; backup and forward repair are documented in [operations](operations.md). No SQL was run on production.

Every customer relationship/read/job is workspace scoped. Scheduled jobs retain an authorizing membership and recheck revocation. Site administrators still require membership. A configured provider key grants no workspace access. Deactivation stops processing and preserves data; uninstall preserves data.

Required unit, PHP syntax/coding standards, REST URL, JavaScript and disposable WordPress/database/browser gates apply before a release. No production calls, purchases or deployments are authorized by development fixtures.

## Progress

October 6 continuation: added `FmpEodQuote`, a parsing-only boundary for the documented stable historical-price-eod/light endpoint (https://site.financialmodelingprep.com/developer/docs/stable/historical-price-eod-light). It preserves numeric precision, validates every symbol/date/positive price, selects the latest two distinct sessions regardless of input order, and rejects conflicting duplicate sessions, incomplete history, error objects and oversized collections. Currency/exchange still require verified mapping; corporate-action compatibility remains a separate gate before displaying daily movement. This parser does not establish free-tier entitlement or send requests. At that milestone FMP transport, recurring settings, holdings integration and AI reviews remained pending; transport was completed in the continuation below. No schema change or production SQL is needed for this parsing-only addition. Validation: 53 unit checks, coding standards and six REST URL checks passed. Database/HTTP/browser checks from the previous milestone were not rerun for this isolated parser; this is not a production release.

| Work | Status | Next step |
| --- | --- | --- |
| Scope and free-tier design | Documented | Owner confirmed both free tiers and AI summaries of fundamentals. Existing valuation/research separation reviewed. |
| Provider foundation and stock valuations | In progress | Lossless JSON, Alpha Vantage EOD parsing, exact daily movement, immutable mappings/quotes, shared quotas and disabled-by-default Alpha Vantage transport/one-shot jobs implemented. FMP parser/transport now implemented. Next: recurring market-aware scheduling, manual/provider selection policy, valuation and UI integration. |
| Fundamental snapshots and AI summaries | Planned | Build on provider evidence; configurable model and monthly budget confirmed, initially $10-$15 with proposed $15 cap. |
| Related news/events | Planned | Verify entitlement coverage and approved relationships. |

Initial foundation code is implemented in `src/Infrastructure/ProviderJson.php`, `src/Infrastructure/AlphaVantageQuote.php` and `src/Domain/Valuation.php`. Five deterministic test groups cover numeric fidelity/exponents, malformed and excessive inputs, quote identity/dates/prices, provider error redaction and movement versus unrealized gain. Provider quote currency/exchange remain unknown until verified mapping supplies them; the parser is not authorization to value a holding.

Quota reservations commit before network dispatch. Dispatch is single-claim; expired reservations cannot send. All recent attempts count, known failures do not refund the allowance, and uncertain dispatched calls stay counted until explicit reconciliation. Scheduled work reserves five requests for on-demand use (20/25 Alpha Vantage and 245/250 FMP). Quota reads use current locking reads under InnoDB so separate workspaces cannot consume the same final slot. Retry keys are hashed to preserve exact case-sensitive identity despite database collation. Mapping changes and revoked owner membership block in-flight completion. Quote/audit failures roll back evidence while preserving the original dispatched reservation.

The Alpha Vantage worker uses a fixed HTTPS host, TLS verification, no redirects, a 20-second timeout and a 2 MiB response limit. It checks owner membership and current mapping before dispatch and completion, returns stored evidence on completed retries, and never resends uncertain attempts automatically. Credential digests are stable across WordPress salt changes. A per-credential named reservation lock is acquired before workspace transactions to avoid shared-to-exclusive lock-upgrade deadlocks; pool/current reads remain locked for correct rolling-window accounting. The last-slot concurrency fixture runs ten races per integration run.

One-shot WordPress jobs retain workspace, owner, mapping and original execution time. Stable request identity prevents duplicate sends; deactivation unschedules these jobs without deleting evidence or quota history. Recurring calendar enrollment and owner-facing settings are not implemented yet. Developer-only server switches are `TGIT_MARKET_DATA_ENABLED` (disabled unless strictly true) and `TGIT_ALPHA_VANTAGE_API_KEY` (server-only). Do not enable these on production merely to test unfinished source. FMP is now supported through the stable historical-price-eod/light endpoint and server-only TGIT_FMP_API_KEY. It requests a bounded fourteen-day window, requires two distinct daily observations and uses the same immutable completion, quota and retry contracts. No fallback to another provider occurs. Entitlement, corporate-action compatibility and holdings selection remain separate gates.

Validation: 51 unit and 98 disposable WordPress/MySQL integration checks pass, including bounded HTTP mocks, failure/uncertainty handling, owner-only jobs, deduplication and deactivation. PHP syntax, coding standards, six REST URL checks and JavaScript syntax pass; HTTP/browser evidence is in implementation-status. No real provider traffic or credentials were used; mocked credentials existed only in the disposable test process. No new production package is claimed; recurring scheduling, valuation/UI and AI reviews remain incomplete.

### October 6: FMP transport continuation

The disabled-by-default refresh worker now supports both configured providers, with independent server credentials and quota pools. FMP uses a fixed HTTPS host, TLS verification, zero redirects, twenty-second timeout, 2 MiB response limit and a fourteen-day history window. Owner membership and mapping revisions are checked at dispatch and completion; provider identity cannot be changed during completion. One-shot jobs support FMP. Completed retries return saved evidence; uncertain attempts never resend automatically, and known failures retain their request cost. No keys or provider error bodies are exposed in statuses. Manual observations and posted ledger history remain untouched.

Validation: 53 unit and 100 disposable WordPress/MySQL integration checks pass, including FMP missing-key behavior, exact price persistence, provider mismatch rejection, completed retry deduplication, scheduling, entitlement/redirect/error responses and uncertain delivery. Coding standards, PHP/JavaScript syntax and six REST URL checks pass. All external responses are deterministic HTTP mocks; no real credentials or provider traffic were used. No SQL or new installation package is required for this source-only milestone. Recurring enrollment, owner-facing settings, holdings integration and fundamental/AI reviews remain pending.

### October 6: owner market-data controls

Settings now includes an owner-only Stock market data section using the shared shell, form controls, decimal display and fully cursor-loaded collection table. Owners select a stock/provider, confirm the provider symbol against the asset's read-only exchange/currency, record verification evidence and append enabled/disabled mapping revisions. The table shows current mappings only, their latest saved end-of-day price/session and aligned Edit/Refresh actions. Server enablement and per-provider rolling request limits are displayed without keys or credential digests. Mapping saves do not send requests; disabled or unconfigured providers cannot refresh from the screen. Holdings continue to use manual observations until the explicit valuation selection policy is implemented.

New workspace-scoped REST operations expose owner configuration status, current mapping pagination, mapping saves and on-demand refresh. Refresh requires a bounded idempotency key and an empty command body; the existing worker performs provider dispatch/completion checks. Owner authorization is enforced independently of the UI. Current mappings exclude superseded revisions, retain disabled revisions and join quote evidence within the same workspace/mapping. On-demand jobs use the five-request headroom already reserved by scheduled work.

The form guards unsaved changes, freezes during initial loading, ignores stale workspace responses and blocks workspace changes during writes. In-flight/uncertain refresh keys persist in actor/workspace-scoped tab session storage; checking the outcome reuses the same key and cannot resend an already dispatched attempt. Session storage contains request identities only, never keys, quotes, account data or journal text. If browser storage is unavailable, retry identity survives only while the page stays open. Explicit reconciliation of uncertain requests and cross-session recovery remain later work.

Validation: 53 unit and 102 disposable WordPress/MySQL integration checks passed, including owner/manager/viewer isolation, foreign assets/mappings, mapping pagination/current revision selection, conflicting saves, credential redaction, refresh validation and completed REST retry deduplication. Required coding standards, PHP/JavaScript syntax and six REST URL checks passed. A previously time-dependent fill-picker fixture now compares all financial facts while excluding the response's changing `as_of` timestamp; no financial assertion was weakened. Dedicated browser coverage uses synthetic asset identities and mock refresh outcomes. No new SQL, live credentials, production requests or installation package is introduced. Next: recurring scheduling and explicit provider/manual holdings selection, followed by fundamental snapshots and configurable AI summaries.

### October 6: explicit recurring weekday refresh (development schema 10)

Owners can enroll each current mapping as Off, Once (18:30) or Twice (18:30 and 22:30) on New York weekdays. The shared Settings form/table displays the saved frequency and requires an explicit Save refresh schedule action after mapping confirmation. Slots follow America/New_York daylight-saving transitions and skip weekends. This is a weekday clock policy, not a validated exchange holiday calendar; holidays may consume requests and prior-session data remains labeled by its date. Requests still use the existing shared rolling provider cap and on-demand headroom, so an enrolled schedule does not guarantee every slot can dispatch.

`010-provider-schedules.sql` adds append-only workspace/mapping/owner enrollment revisions. Development schema is now 10; preserve all ten migrations in future packaging, back up first and reactivate explicitly. Installer upgrades versions 1–9 and repairs missing schedule tables while retaining quotes/manual observations. Configuration and audit commit together before cron writes. A recovery scan requeues missing jobs from explicit enrollment only. Each job rechecks the original owner's membership, current mapping and latest enrollment, uses a stable original-slot request key, and queues its next strictly future slot. Missed slots over two hours old are skipped rather than replayed in a burst. New mapping revisions require new enrollment; disable/revocation blocks old jobs. Deactivation removes the scan and quote hooks without deleting evidence; reactivation can resume still-authorized explicit enrollment.

Validation: 55 unit and 106 disposable WordPress/MySQL integration checks pass, including DST spring/fall boundaries, weekend selection, two-slot timing, schema 9-to-10 repair, owner REST enrollment/revision conflicts, disabled/superseded schedules, future/late jobs, duplicate request prevention, owner revocation, transactional audit failure and recovery scan. Coding standards, PHP/JavaScript syntax and six REST URL checks pass. The journal unchanged-holdings fixture now excludes only its changing response timestamp, preserving all financial comparisons. No live provider calls, production SQL, deployments or new ZIP are claimed. Remaining: exchange calendars/operational monitoring, explicit provider/manual holdings selection and totals, then fundamental snapshots and configurable AI summaries.

## October 6, 2026: cached provider holdings and stock totals

Development source remains 0.27.0-dev/schema 10; the released ZIP remains 0.26.0/schema 8. Overview now offers an explicit manual/FMP/Alpha Vantage stock price source and exact open-stock market value and unrealized gain/loss totals across all authorized accounts and asset pages. Other asset classes keep manual pricing. Values are grouped by native currency, exclude cash and closed positions, and do not change with table filters.

Provider valuations require the latest enabled matching mapping and a saved quote no more than three calendar days old. Missing/stale provider prices remain unavailable without fallback. Unknown basis leaves gain unavailable. Partial coverage shows covered subtotals; mixed price dates cannot claim a complete total. Source preference is scoped to the current user/workspace browser session. Existing posted facts, manual observations and saved reports remain unchanged; reads send no external requests.

Validation: 58 deterministic unit checks and 110 disposable WordPress/MySQL integration checks passed, including full pagination, source validation, membership, staleness, mapping replacement, unknown basis and immutable manual reports. Coding standards, PHP/JavaScript syntax and six REST URL checks passed. The cached valuation browser fixture passed source switching, exact values, missing coverage, scoped restoration and desktop/mobile layout. No new SQL, live provider calls or release ZIP.

Next: corporate-action compatibility before displaying daily change, then fundamental snapshots and AI summaries with configurable model and monthly budget. Exchange holidays and operational monitoring remain open.
## October 6 continuation: fundamental parsing

Added an internal lossless Alpha Vantage overview/income/balance-sheet/cash-flow parser. It keeps annual, quarterly and trailing figures distinct, preserves signed decimals and reported currencies, distinguishes missing values from zero and excludes provider narrative. The [fundamental evidence contract](fundamental-evidence-contract.md) records bounds, validation and the next persistence/metric/request/UI steps. This does not yet deliver scheduled or on-demand fundamental reviews or AI summaries. Daily-change corporate-action compatibility remains open; no paid endpoint is enabled.

Validation: 66 unit checks, coding standards, PHP/JavaScript syntax and six REST URL checks passed. This isolated parser changes no database or browser workflow, so the preceding milestone's 110 integration/HTTP/browser checks are prior evidence, not newly rerun checks. No new SQL or installation ZIP; source remains 0.27.0-dev/schema 10 and the released ZIP remains 0.26.0/schema 8.
## October 6 continuation: immutable fundamental snapshot storage

Development source is now 0.27.0-dev/schema 11. Separate migration [011-fundamental-snapshots.sql](011-fundamental-snapshots.sql) adds append-only snapshots and a dataset discriminator on provider requests. Existing requests default to quote; all eleven SQL migrations belong in future packages. The latest released ZIP remains 0.26.0/schema 8.

Internal MarketData operations now reserve supported Alpha Vantage fundamental datasets through the same atomic credential-wide quota as quotes. Dataset changes conflict with an existing retry identity, and quote/fundamental completions cannot substitute for each other. Completion requires the original authorized owner, current enabled mapping and a compatible dispatched request. Normalized evidence, identity, reporting currencies, parser version, fingerprint, retrieval time and previous-snapshot link commit with the request state and audit. Publication time remains unknown; fiscal dates cannot be in the future. Retry returns the same snapshot; later requests append history, including restatements, without changing earlier evidence or financial facts. Authorized reads are workspace/asset scoped and cursor bounded.

Backup and forward repair: back up database and private images, keep external processing disabled, retain the matching development code and explicitly reactivate. The installer upgrades versions 1-10, recreates missing snapshot storage and adds the dataset column only when absent. An incompatible existing column blocks completion of the schema marker; inspect and repair its definition before reactivation. MySQL DDL is not atomic. Manual file 011 adds the column with ALTER; on repeated manual repair check SHOW COLUMNS first. Do not delete request or quota history to repair an upgrade.

Next: comparable decimal metrics and the disabled-by-default fundamental transport, then on-demand/scheduled review controls and AI summaries with configured model and spending reservations. This milestone adds no fundamental HTTP calls, review UI or OpenAI processing. Daily-change corporate-action compatibility remains open.
## October 6 continuation: exact fundamental metrics

Added pure decimal margin, liquidity, liabilities/assets, reported debt, net debt and free-cash-flow calculations. They group matching fiscal periods and reporting currencies and retain explicit missing/sign/denominator coverage. Free cash flow requires a verified source capex convention; its default is unavailable. The authorized application operation uses explicit immutable snapshot identifiers and retains source fingerprints, retrieval times and formula version without rewriting facts. See [formula contract](fundamental-metric-formulas.md).

73 unit and 119 disposable WordPress/MySQL integration checks passed, with coding standards, PHP/JavaScript syntax and six REST URL checks. The new checks cover exact numbers, losses, missing versus zero, signs/denominators, mixed periods/currencies, deterministic results, provenance, foreign/damaged evidence and read-only historical access. UI/HTTP behavior is unchanged; the preceding schema-11 storage milestone remains the latest browser/HTTP regression evidence. Source remains 0.27.0-dev/schema 11; no new SQL, release ZIP or live provider/OpenAI call.

Remaining: comparable-period growth/restatement interpretation, fundamental transport, scheduled/on-demand review screens and AI summaries with configurable model/monthly budget. Daily-change corporate-action compatibility remains open.
## October 6 continuation: bounded fundamental transport

Added a separately disabled-by-default Alpha Vantage fundamental worker and explicitly owner-authorized one-shot jobs for overview/income/balance-sheet/cash-flow snapshots. It uses fixed HTTPS transport bounds, the existing shared quote/fundamental request pool, typed retry identity and immutable completion. Completed requests reuse evidence; timeouts/audit failures after delivery remain uncertain and are not resent. Provider/entitlement errors retain consumed quota. Revocation and mapping changes block dispatch or completion. Deactivation removes pending fundamental jobs while retaining snapshots and allowances. See [worker contract](fundamental-refresh-worker.md).

Configuration additionally requires TGIT_FUNDAMENTALS_ENABLED strictly true. Price enablement alone cannot enable fundamental fetching. No recurring review enrollment, owner-facing analyze action or OpenAI processing is enabled by this milestone. The official Alpha Vantage endpoint documentation was verified; responses remain synthetic mocks and no live entitlement is claimed.

73 unit and 126 disposable WordPress/MySQL integration checks, coding standards, PHP/JavaScript syntax and six REST URL checks passed. UI/HTTP and schema are unchanged; their latest full regression remains the schema-11 storage milestone. Source stays 0.27.0-dev/schema 11; no SQL or release ZIP is added. Next: review screens/on-demand actions, recurring enrollment, comparable-period changes and configurable AI summaries.
Development schema 12 now adds explicit per-dataset weekly fundamentals with owner Research controls and recoverable cron. See [schedule contract](fundamental-schedules.md). Comparable-period changes and configurable AI summaries/model/monthly budget remain pending. No live entitlement verification, production migration or new ZIP has occurred.

Subsequent development adds saved reporting-period metric differences and schema-13 [persistent AI spending](ai-spending-persistence.md). Shared configuration, explicit workspace opt-in, immutable pricing/requests/events, atomic cross-workspace admission and usage reconciliation are implemented internally. Owner AI controls/catalog, approved evidence/thesis bundles, saved summaries and external transport remain pending. Earlier progress entries record historical milestone scope. No paid processing, production migration or new ZIP has occurred.
