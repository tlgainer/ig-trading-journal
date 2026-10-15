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
