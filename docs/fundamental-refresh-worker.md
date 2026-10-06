# Fundamental refresh worker

Development milestone, October 6, 2026. Source remains 0.27.0-dev/schema 11. This is an internal worker and explicitly queued one-shot job contract; recurring enrollment, journal review screens and AI processing remain pending. No new release ZIP or SQL migration is introduced.

## Configuration and scope

`FundamentalRefresh` supports Alpha Vantage `OVERVIEW`, `INCOME_STATEMENT`, `BALANCE_SHEET` and `CASH_FLOW`. Requests use only the fixed HTTPS query host and an allowlisted function, confirmed mapping symbol and server key. The [official fundamental endpoint documentation](https://www.alphavantage.co/documentation/#fundamentals) was checked October 6. No live entitlement or symbol-coverage claim is made; provider restrictions are handled as failed attempts.

Fundamental fetching remains disabled unless `TGIT_FUNDAMENTALS_ENABLED` is strictly true, existing `TGIT_MARKET_DATA_ENABLED` is strictly true and the existing server-only `TGIT_ALPHA_VANTAGE_API_KEY` passes its configuration checks. Quote enablement alone cannot enable fundamental requests. Configuration constants are developer controls, not released owner-facing settings. Do not put keys into journal text, browser settings, exports or repository files.

There is no FMP fundamental fallback. There are no page-load fetches, automatic portfolio enrollment, OpenAI calls or automatic investment/ledger actions. The capex sign policy is still unknown by default; fetching a statement does not silently enable free-cash-flow calculations.

## Request lifecycle

The worker rechecks the authorizing owner and current enabled stock mapping before quota reservation, dispatch and snapshot completion. Fundamental request datasets are bound to their retry identity. It uses the same SHA-256 server credential identity and atomic rolling allowance as quotes, with five requests preserved for on-demand operations. Each endpoint request consumes one attempt; collecting all four datasets requires four requests and can complete partially.

Transport uses WordPress's safe HTTP client, verified TLS, zero redirects, a 20-second timeout, JSON accept header and a 2 MiB response limit. Raw URLs, response diagnostics and HTTP exception text are not returned. Successful responses pass through the existing exact parser and append-only snapshot operation. Saved snapshots are returned on completed retries without another HTTP call. Missing evidence on a completed request remains unavailable and requires inspection.

Non-200 responses and rejected limit/entitlement/malformed bodies are recorded as failed attempts and are not resent with the same key. A timeout or uncertain transport delivery leaves the dispatch counted and returns uncertain. Audit/storage failure after delivery also leaves an uncertain request rather than resending. Revocation or mapping changes during delivery block persistence while retaining the consumed attempt. New explicit requests need a new identity; uncertain completion must be investigated rather than retried blindly.

## One-shot jobs

The internal schedule operation authorizes the owner/current mapping and permits a supported dataset and execution time within the coming week. It deduplicates the full workspace/actor/mapping/dataset/original-time event. The registered `tgit_fundamental_refresh` handler derives a stable request key from the original job identity and rechecks authorization at execution. It reports only the fixed processing state through the status hook. No recurring schedule is created automatically.

Plugin deactivation removes pending fundamental jobs together with quote hooks, preserving every snapshot and quota record. Reactivation does not automatically rebuild these one-shot jobs. Future recurring enrollment and recovery need their own reviewed contracts.

## Validation

Seven deterministic WordPress integration groups intercept every HTTP attempt and check the fixed host/function, key handling, TLS, redirect/timeout/body bounds, separate enablement, provider/dataset restrictions, one-shot deduplication, all four endpoint completions, exact stored evidence, completed retries, uncertain timeouts, HTTP/entitlement/malformed responses, owner changes, mapping restrictions, audit rollback and exhausted shared quota.

73 unit and 126 disposable WordPress/MySQL integration checks passed, with coding standards, PHP/JavaScript syntax and six REST URL checks. No real provider/demo API or OpenAI request was sent. The admin/HTTP interface and schema are unchanged; the latest full browser/HTTP regression remains the schema-11 storage milestone in testing.md.

Next: owner-visible review history and bounded on-demand actions, explicit recurring review enrollment, comparable-period change handling and configurable AI summary/model/budget controls.
