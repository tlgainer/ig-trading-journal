# Financial data integration and AI fundamental reviews

Owner-confirmed scope, October 5, 2026. This extends the original PRD. Latest released package remains 0.26.0/schema 8. Development source is now 0.27.0-dev/schema 9; provider integration is unfinished and no new installable release is claimed.

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

October 6 continuation: added `FmpEodQuote`, a parsing-only boundary for the documented stable historical-price-eod/light endpoint (https://site.financialmodelingprep.com/developer/docs/stable/historical-price-eod-light). It preserves numeric precision, validates every symbol/date/positive price, selects the latest two distinct sessions regardless of input order, and rejects conflicting duplicate sessions, incomplete history, error objects and oversized collections. Currency/exchange still require verified mapping; corporate-action compatibility remains a separate gate before displaying daily movement. This parser does not establish free-tier entitlement or send requests. FMP transport, recurring settings, holdings integration and AI reviews remain pending. No schema change or production SQL is needed for this parsing-only addition. Validation: 53 unit checks, coding standards and six REST URL checks passed. Database/HTTP/browser checks from the previous milestone were not rerun for this isolated parser; this is not a production release.

| Work | Status | Next step |
| --- | --- | --- |
| Scope and free-tier design | Documented | Owner confirmed both free tiers and AI summaries of fundamentals. Existing valuation/research separation reviewed. |
| Provider foundation and stock valuations | In progress | Lossless JSON, Alpha Vantage EOD parsing, exact daily movement, immutable mappings/quotes, shared quotas and disabled-by-default Alpha Vantage transport/one-shot jobs implemented. Next: FMP parser/transport, recurring market-aware scheduling, manual/provider selection policy, valuation and UI integration. |
| Fundamental snapshots and AI summaries | Planned | Build on provider evidence; configurable model and monthly budget confirmed, initially $10-$15 with proposed $15 cap. |
| Related news/events | Planned | Verify entitlement coverage and approved relationships. |

Initial foundation code is implemented in `src/Infrastructure/ProviderJson.php`, `src/Infrastructure/AlphaVantageQuote.php` and `src/Domain/Valuation.php`. Five deterministic test groups cover numeric fidelity/exponents, malformed and excessive inputs, quote identity/dates/prices, provider error redaction and movement versus unrealized gain. Provider quote currency/exchange remain unknown until verified mapping supplies them; the parser is not authorization to value a holding.

Quota reservations commit before network dispatch. Dispatch is single-claim; expired reservations cannot send. All recent attempts count, known failures do not refund the allowance, and uncertain dispatched calls stay counted until explicit reconciliation. Scheduled work reserves five requests for on-demand use (20/25 Alpha Vantage and 245/250 FMP). Quota reads use current locking reads under InnoDB so separate workspaces cannot consume the same final slot. Retry keys are hashed to preserve exact case-sensitive identity despite database collation. Mapping changes and revoked owner membership block in-flight completion. Quote/audit failures roll back evidence while preserving the original dispatched reservation.

The Alpha Vantage worker uses a fixed HTTPS host, TLS verification, no redirects, a 20-second timeout and a 2 MiB response limit. It checks owner membership and current mapping before dispatch and completion, returns stored evidence on completed retries, and never resends uncertain attempts automatically. Credential digests are stable across WordPress salt changes. A per-credential named reservation lock is acquired before workspace transactions to avoid shared-to-exclusive lock-upgrade deadlocks; pool/current reads remain locked for correct rolling-window accounting. The last-slot concurrency fixture runs ten races per integration run.

One-shot WordPress jobs retain workspace, owner, mapping and original execution time. Stable request identity prevents duplicate sends; deactivation unschedules these jobs without deleting evidence or quota history. Recurring calendar enrollment and owner-facing settings are not implemented yet. Developer-only server switches are `TGIT_MARKET_DATA_ENABLED` (disabled unless strictly true) and `TGIT_ALPHA_VANTAGE_API_KEY` (server-only). Do not enable these on production merely to test unfinished source. FMP refresh remains unsupported by this worker until its parser/transport is implemented.

Validation: 51 unit and 98 disposable WordPress/MySQL integration checks pass, including bounded HTTP mocks, failure/uncertainty handling, owner-only jobs, deduplication and deactivation. PHP syntax, coding standards, six REST URL checks and JavaScript syntax pass; HTTP/browser evidence is in implementation-status. No real provider traffic or credentials were used; mocked credentials existed only in the disposable test process. No new production package is claimed; recurring scheduling, valuation/UI and AI reviews remain incomplete.
