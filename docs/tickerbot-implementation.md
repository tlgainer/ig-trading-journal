# Tickerbot implementation progress

Source plan: TG_Investment_Tracker_Tickerbot_Implementation_Plan.pdf, October 14, 2026. Base source 0.27.0-dev.12, schema 18. This document tracks implementation, not production cutover.

## Stage A discovery (TB01-04)

- `src/Infrastructure/QuoteRefresh.php`: FMP stable historical-price-eod/light with fourteen calendar days; Alpha Vantage GLOBAL_QUOTE. Explicit refresh and scheduled jobs share request reservations. No neutral provider interface exists.
- `src/Domain/QuoteSchedule.php`, `src/Infrastructure/RecurringQuotes.php`: explicit mapping enrollments, weekdays 18:30 and optionally 22:30 America/New_York. Hourly recovery scan, not hourly quote polling. WordPress cron remains traffic dependent unless the host invokes it.
- `src/Application/MarketData.php`, `docs/009-provider-quotes.sql`: stock-only identity mappings, credential-key quotas, one mapping/request/quote and required previous close/session date. This cannot safely store Tickerbot marks by pretending they are EOD closes.
- `src/Application/ReportingOperations.php`: current holdings select manual/FMP/Alpha Vantage prices, exclude stale provider marks after three calendar days, and stock totals group by native currency. Reports use explicit manually dated prices/FX; no historical FX is fetched. Tickerbot cutover must preserve these dated report observations and posted ledger facts.
- `src/Http/Controller.php`, `assets/market-data.js`, `assets/admin.js`, `src/Admin/Screen.php`: explicit refresh routes and shared Settings/table controls. Quote/history reads do not fetch external prices. Public symbol search can fetch cached ticker/coin catalogues on focus; neither sends portfolio facts. `QuoteRefresh`, `FundamentalRefresh`, `AiCounts` and `AiTransport` own explicit/background external operations.
- Production inventory and FMP failure causes are unavailable to this workspace. Do not read production records or claim coverage. Stage C must add a scoped inventory of open holdings/enabled watchlists, plus opted-in planned trades, with every asset mapped/ambiguous/unsupported/manual. Existing FMP failures retain only generic state for older attempts, so historical HTTP causes cannot be recovered.

## Stage B adapter boundary

Implement `QuoteProvider`, pure `TickerbotQuote` normalization/identity checks and `TickerbotProvider` bounded transport. Reuse `ProviderJson` for decimal tokens. Transport is disabled unless TGIT_TICKERBOT_ENABLED is strictly true, the server key is valid, and an injected request-admission callback explicitly approves each attempt. No runtime route, cron registration, database write or automatic fallback is installed in this stage. The callback is a mandatory boundary for the durable shared-account budget in Stage C, not an implemented durable quota by itself.

Current contracts reviewed October 14, 2026:

- [Ticker state](https://tickerbot.io/docs/endpoints/tickers/get/): one row or symbol-keyed batches, maximum fifty; requested/not_found identify partial results. No guessed tickers query parameter.
- [Catalogue](https://tickerbot.io/docs/endpoints/tickers/catalog/): search, asset_class, exchange, cursor; identity rows include currency_name and exchange_mic. Resolution must compare identity, not names alone.
- [Authentication](https://tickerbot.io/docs/authentication/): server bearer header, account-wide limits shared between keys.
- [Price provenance](https://tickerbot.io/docs/refresh-cadence/): source type, response time and source time differ. Fair-value source time denotes provider fetch, not exchange trade.
- [Quota](https://tickerbot.io/pricing/) and [errors](https://tickerbot.io/docs/errors/): monthly exhaustion is HTTP 402, capability failure 403, rate limit 429 with Retry-After. These are separate states.
- [Currency](https://tickerbot.io/docs/signals/currency_name/): quote currency comes from metadata; missing currency is unresolved, never USD inferred from price.

## Remaining delivery and exact integration files

Stage C: separate additive `docs/019-tickerbot-quotes.sql` after backup/repair design; extend Installer and Database table allowlists, add workspace-scoped mappings/observations and immutable run/batch/attempt evidence, shared account-scope budget/lease/cooldown and UTC next_due_at. Account identity must survive key rotation; a digest of the current key alone cannot establish shared-account identity. Resolve thirty-one-day projections and retries before dispatch, 80% warning and 8000 default soft cap. Include search/connection attempts. Retry-After must delay, never trigger immediate loops. One run per account joins concurrent manual requests and skips missed intervals. Do not invent a scheduler outside this durable state.

Next integrate Controller/Screen/market-data.js and shared collection controls for coverage issues, Test connection, interval/scope/budget, progress and explicit cutover. Extend ReportingOperations/Tracker/admin.js/watchlist presentation for cached mark provenance, interval-aware source staleness, mixed-currency incompleteness and manual override policy. Legacy FMP polling must be suppressed only after successful explicit cutover; fallback remains off initially. No daily research/history feature is required for cutover.

Stages D-F: fixture acceptance first, then owner-run live connection/coverage smoke test with server-only key; record comparable instruments/timestamps. Host must invoke WordPress cron without page visits. Do not claim background cadence, live coverage, rollback verification or seven-day observation before evidence exists. Rollback should restore prior provider/cadence settings without touching financial data. Leave first-run, 24-hour and seven-day checks documented when cutover is implemented.

## Data and operational boundaries

No Tickerbot key supplied/read, no authenticated/live API request, no production inventory, no SQL run. Schema remains 18 for adapter work. Future migration must be separate/additive and installer-driven after a joint database/private-media backup. Provider-derived records remain separate from user financial/journal/image records. Review provider [terms](https://tickerbot.io/terms/) before retention/export or commercial distribution changes; do not add purge to user-owned data.

Test matrix: PHP 8.1 + BCMath, WordPress disposable fixture, MySQL 8.0, synthetic HTTP, PHP syntax/Composer, JS syntax/REST URL, existing accounting/media/import/table regressions before release. Production PHP8.1.2/WordPress7.1.2/MySQL8.0.46 hosting and MariaDB10.6 remain distinct acceptance targets, not claims derived from this local fixture.


## Acceptance state after adapter foundation

TB01/02: repository and current cadence inspected; exact integration files and migration needs recorded. TB03: production instrument inventory not performed; scoped inventory delivery remains Stage C. TB04: synthetic fixtures only. TB05-10: adapter interface, catalogue resolution, batching, mark validation and selective provenance implemented/tested; database persistence and presentation remain pending. TB15-24: durable scheduler/leases, account usage controls, retry worker, settings and cutover remain pending. AC01/03/07/08/09 have adapter-level fixture evidence only, not end-to-end acceptance. AC02/04-06/10-12 require the subsequent integrated staging gate. No new ZIP or activation/cutover is appropriate for this internal foundation.


## Server preparation (keep disabled)

Follow the existing wp-config.php secret pattern, before WordPress loads. Do not duplicate definitions or share the key in chat/source control:

```php
define('TGIT_TICKERBOT_API_KEY', 'YOUR_TICKERBOT_KEY');
define('TGIT_TICKERBOT_ENABLED', false);
```

Leave the flag false during this foundation stage. There is no owner-facing Tickerbot setup/refresh yet. A future counted owner command will perform connection and coverage checks before activation; adding a key alone creates no jobs and does not prove coverage. No live key is required for fixture development.


## Stage C1 - durable storage and admission (October 14, 2026)

Development source is now 0.27.0-dev.13/schema 19. The prior dev.12 test ZIP remains schema 18. No Tickerbot cutover ZIP is published by this step.

`docs/019-tickerbot-quotes.sql` and Installer add eight isolated tables for shared account controls, workspace enrollment, runs, attempts, mappings, deduplicated observations, per-attempt quote receipts and append-only policy history. Database allowlists include those tables. No transaction, cash, lot, realized-gain or historical FX table is changed. Back up database and private images together before explicitly reactivating source builds; do not run migration SQL on a live site as a connectivity experiment. Forward repair keeps Tickerbot disabled and reruns the matching installer after inspecting permissions/partial tables. Deactivation/uninstall still preserve records.

`TickerbotStore` is an internal application boundary, not a browser API. Its account grouping comes from trusted server context and must identify the actual account across key rotations. It is not derived from the current key, not returned to browsers and must never be editable through owner form input. The first workspace controls the shared cap; other explicitly enrolled workspaces cannot replace it. All workspace mutations lock and reauthorize the workspace, then lock shared account state. Current locking reads count committed attempts after concurrent waits.

- Default budget 8000, configurable 0-10000. Zero pauses admission. Every admitted attempt counts, including failures and uncertain delivery. Monthly counters use UTC calendar months; a separate rolling-minute guard allows at most 45 locally admitted attempts. External applications using the account remain unknown. Cap checks never assume provider usage equals local counts.
- Optional history/enrichment is blocked at 80% of the soft cap. Core admitted attempts return a warning at that point. Projection is for thirty-one days of unique symbols at batches of fifty; retries and discovery consume extra allowance. Eligibility/account-wide projected scope still needs the worker inventory boundary before UI exposure.
- Account-wide run leases last five minutes and can be renewed only while active. Same-workspace concurrent refreshes join without receiving the private lease token. Other workspaces get only busy. Expired/replaced workers cannot reserve, save marks, renew or finish. Existing command identities never cause an automatic resend. Manual requests have a persistent five-minute workspace cooldown.
- Safe outcomes persist pauses: 429 uses bounded Retry-After/default delay; 402 pauses to next UTC month; authentication/capability blocks require deliberate controller intervention. Clearing a pause is recorded in policy history. This does not yet implement the three-attempt backoff worker or an automatic retry loop.
- Verified mappings are append-only and reject stale expected revisions or incompatible class/exchange/currency metadata. Quote receipts bind an admitted quote attempt to its exact symbols, current mapping and positive decimal mark. Repeated equivalent observations share storage but retain each receipt. Cached reads order by receipt time/ID, not the observation ID; prior valid marks survive failures.
- Price source, source-time meaning, response time, first fetched time and latest receipt time stay distinct. Source-age/stale presentation and valuation selection remain the next integration layer. Cached reads make no external call and do not assert freshness.

`TickerbotSchedule` validates 3600/7200/86400 elapsed-second intervals and computes the next future UTC slot from completion, without missed-slot replay. Stored next_due_at and admission are ready for worker integration; there is still no Tickerbot cron registration or owner UI. Real host-driven cron, eligible-instrument inventory, batch dispatch/retry records, fallback, source staleness, manual overrides and default-provider cutover remain pending. In particular, an admission record is not proof that HTTP was sent; a worker must send immediately at the admitted boundary, preserve its attempt identity and never treat an uncertain attempt as a free retry.

Acceptance remains partial: storage/lease/budget/quote persistence have synthetic database coverage. No production holdings inventory, live key/coverage check, background cadence evidence or seven-day observation is claimed.

C1 verification: 127 unit and 226 full disposable database integration checks passed, including eight dedicated store checks and a real two-process last-budget-slot race. Syntax/coding standards and existing URL/display/adapter fixtures passed. No new browser controls or live Tickerbot traffic; current end-to-end cutover acceptance remains pending. Next delivery is the eligible-asset inventory and bounded worker that connects the adapter to these admissions/receipts, then owner Settings and valuation integration.

## Stage C2a - read-only eligible inventory (October 14, 2026)

Added internal `TickerbotInventory` preparation for the worker. It enumerates all holdings pages, includes only positive remaining quantities, and includes items from explicitly supplied workspace-owned watchlist IDs. Buy/sell/hold/watch labels are not enablement flags. No watchlist selection is persisted or enabled by this step; planned trades remain excluded. Customer queries and relationships are workspace scoped and require owner authorization. No new routes, jobs, external requests, financial writes, schema changes or ZIP are introduced.

The inventory retains one entry per eligible local asset and deduplicates compatible canonical symbols for batches of at most fifty. It distinguishes mapped, needs_mapping and unsupported; it does not claim that an unresolved asset is provider-unsupported or invent a match. Currency/class/exchange and workspace identity must still match the latest verified mapping. Crypto non-USD quotes remain unsupported in this adapter, with no USDT/USD substitution. Plans project normal thirty-one-day quote attempts only; discovery and retries require extra budget. This is a per-workspace plan, not a completed shared-account inventory/projection.

Verification: 130 unit checks passed, including mapping identity, stale crypto symbols, unsupported quote currencies and the fifty/one batch boundary. PHP syntax, coding standards, six REST URL and ten decimal display checks passed; JavaScript syntax passed. The new database inventory reader has not yet passed a dedicated full database fixture in this step. Earlier 226 database checks cover C1, not this new reader. Production release remains blocked on integrated worker/database/HTTP/browser acceptance.

Next: persist explicit watchlist/planned-trade enrollment, test complete workspace inventory with database fixtures, then durable batch/retry dispatch and honest per-receipt response timestamps. Connection/coverage controls, cached valuations, host-driven cadence and explicit cutover remain outstanding. Tickerbot stays disabled; this is an internal incremental milestone, not a live refresh feature.

## Stage C2b - explicit inventory selections (October 14, 2026)

Development source is 0.27.0-dev.14/schema 20. Migration `docs/020-tickerbot-inventory.sql` adds append-only workspace selection revisions; retain all twenty bundled SQL files. After a joint database/private-media backup, explicit source reactivation upgrades schema 19 and older versions. Forward repair keeps Tickerbot disabled, restores matching source and reruns the installer. No customer ledger, historical FX or image record is changed. No new ZIP is published by this step.

`TickerbotStore::select_scope` saves explicitly enabled watchlist IDs and individually opted-in planned-trade IDs. It locks and reauthorizes the workspace, verifies its configured shared account, validates every relationship in the workspace, checks the last reviewed revision and appends both the selection and transactional audit evidence. A stale save fails; it cannot overwrite newer selections. IDs cannot be strings or inferred from user-authored watch/buy/sell/hold labels. Saving selections does not enable HTTP or a worker.

`scope_selection` defaults to no optional enrollment; `inventory` uses that persisted selection and the configured cadence. Open holdings remain eligible automatically. Selected planned trades are included only while their current state is planned; changing them to open/closed/archived removes that eligibility reason. An existing open holding or enabled watchlist can independently keep the same asset eligible. Inventory traverses all asset/watchlist pages, deduplicates local assets and canonical symbols, and retains origins without including quantities or journal prose in the resulting plan. This remains an internal API with no owner-facing route or form yet.

The per-workspace inventory projection is not yet account-wide capacity approval. Scope saving does not activate a polling promise. Durable batches/retries, shared-account inventory accounting, connection/coverage verification, per-receipt response timestamps, Settings, valuations and explicit cutover remain subsequent milestones. Existing active pricing is unchanged.

C2b verification: 131 unit and 229 full disposable WordPress/MySQL integration checks passed. Eleven focused Tickerbot database checks also passed. New fixtures cover saved opt-ins, revision conflicts, foreign/viewer rejection, planned-to-open exclusion, more than one hundred asset/watchlist rows, closed lots, deduplication, configured projection, audit rollback, repeated schema-19-to-20 upgrade and unchanged lot records. PHP syntax, Composer coding standards, six REST URL checks, ten decimal display checks, all asset JavaScript syntax and isolated enabled/disabled/invalid-key adapter fixtures passed. No new browser controls, live provider requests or production acceptance are claimed. Next delivery remains the durable batch/retry worker, followed by setup and valuation integration.

## Stage C3 - bounded batch worker (October 14, 2026)

Development source is 0.27.0-dev.15/schema 21. Separate additive `docs/021-tickerbot-dispatch.sql` adds immutable run plans, durable batches, immutable attempt-to-batch slots and per-receipt response timestamps. Keep all twenty-one migrations bundled. Back up database/private media before explicit source reactivation. Forward repair keeps Tickerbot disabled and reruns the matching installer after reviewing partial tables and permissions. Prior financial, journal, media and historical FX records are unchanged. No installation ZIP or default-provider cutover is published.

`TickerbotDispatch` freezes each run's complete local inventory, scope revision, settings revision and canonical batches once. Existing plans are reused without expanding their intent. Before every dispatch, the application rechecks owner membership, account/run lease, configured revisions and latest exact asset mappings. The per-workspace normal-cycle projection must fit the shared cap; account-wide inventory projection/deduplication remains pending. Every actual batch admission still enforces the shared account's monthly and rolling-minute usage.

One batch can be in flight per run. Its request attempt, counted quota reservation, active batch binding and immutable ordinal are committed together immediately before transport. Batches contain at most fifty unique canonical symbols. Retryable transport/5xx outcomes commit a future UTC due time with bounded jitter (15-20 seconds, then 30-35 seconds), with at most three attempts total per batch/run. Every retry consumes fresh admission. No sleep loop, free retry or immediate Retry-After loop exists. Authentication/capability, monthly quota and rate-limit responses persist account pauses and stop remaining dispatch in that run. Other batch failures retain prior valid marks and allow independent batches to finish. Partial results retain valid quotes and safe per-asset issues; a partial batch/run is not reported as fully successful.

`TickerbotRefresh::step` executes at most one batch. Completing a successful HTTP batch requires exactly one saved quote receipt or safe issue per requested local asset, with no contradictory/foreign issues. Attempt completion and batch outcome/backoff commit atomically with audits. Failed audit writes leave the original admitted attempt counted and in-flight; they do not authorize a resend. A crashed in-flight batch is not automatically reclaimed. Expired/replaced/revoked workers cannot continue; later recovery/cadence controls must distinguish this uncertainty from a clean retry and never refund it.

A gated `tgit_tickerbot_batch` hook can resume an explicitly started run. Cron arguments contain workspace/run IDs only; private capabilities and original owner context come from trusted database/server configuration. The extra `TGIT_TICKERBOT_WORKER_ENABLED` gate defaults off; it is not enabled by adding an API key, saving scope, activating the schema or merely registering the hook. `TGIT_TICKERBOT_ACCOUNT_SCOPE` must be a stable trusted identifier for the actual shared provider account across key rotation, never browser input or a new value chosen to reset usage. Do not enable either processing switch yet. There is no automatic cadence enrollment/scan or owner refresh route in this milestone. Deactivation cancels queued batch jobs while preserving durable records.

Receipts now retain each response's own `response_as_of`, even when equivalent source marks reuse the same observation. Cached reads distinguish first-observation response time, latest receipt response time and latest local receipt time. Existing receipts without the new metadata return unknown for their per-receipt response time; no backfill invents that evidence. Source/price timestamps and ledger values are not rewritten.

Next: account-wide inventory/projection and due-run recovery, verified connection/coverage setup, safe progress/settings controls, cached valuation/source-age presentation and explicit cutover with legacy polling suppression. Host-driven cron without page visits, live coverage smoke tests and first-run/24-hour/seven-day observations remain required acceptance gates; fixture tests do not prove those production behaviors.

C3 verification: 131 unit and 237 full disposable WordPress/MySQL integration checks passed. Nineteen focused Tickerbot database checks passed, including the final issue-envelope validation. New dispatcher fixtures cover 51-symbol/two-batch partial results, one in-flight binding, completed-batch receipt completeness, jittered bounded retries with fresh counted attempts, 401/402/403/429 pauses, changed scope/mappings, exhausted admission, crash/expired lease fencing, distinct receipt response timestamps, migration 20-to-21 repeatability, transactional batch-audit failure, queued IDs without tokens, revoked original owners and preserving evidence on deactivation. The first full run caught a synthetic queued-job account reused from a prior run; the fixture now isolates that account per test run, and the rerun passed without weakening the account lease contract.

PHP syntax, Composer coding standards, six REST URL checks, ten decimal display checks, all asset JavaScript syntax and isolated adapter enabled/disabled/invalid-key fixtures passed. No real provider key, live provider request, production SQL, new browser UI, deployment or ZIP was used. Real host cadence, owner connection/coverage verification, valuation integration and cutover remain pending. The disposable database is stopped after testing, with fixture records retained.
