# Fundamental evidence boundary

Development milestone, October 6, 2026. Source remains 0.27.0-dev/schema 10. This is an internal parser contract, not a delivered Analyze holding action or a new installation package.

## Implemented boundary

`AlphaVantageFundamentals` accepts bounded JSON for an explicitly mapped provider symbol. It supports `OVERVIEW`, `INCOME_STATEMENT`, `BALANCE_SHEET` and `CASH_FLOW`, using the existing lossless numeric decoder. No HTTP, credentials, storage, WordPress globals or investment decisions occur in this boundary.

Statements retain annual and quarterly reports separately, with fiscal end date, reported currency and an allowlist of values needed for later revenue, margin, debt, liquidity and cash-flow calculations. Annual and quarterly entries can share a fiscal date; duplicate dates within one collection are rejected even when values match. A future persistence operation must append restatements as separate snapshots, rather than overwrite a report. Each collection is limited to 100 reports and both collection keys must be present; empty collections are allowed individually, but a wholly empty result fails.

Values remain decimal strings, including negative earnings/equity/cash flows and expanded scientific notation. Precision is bounded to the current decimal(38,18) contract. Missing fields, JSON null and recognized unavailable markers (`None`, `N/A`, blank, `-`, `NaN`) become null. An explicit numeric zero stays zero. Unexpected types, malformed values, excessive precision, bad dates, missing currencies and identity mismatches fail rather than fabricate usable evidence. Provider error diagnostics are never reflected in error messages.

The overview requires a Common Stock identity. Explicit TTM fields retain trailing-twelve-month classification; other metric periods remain provider-unspecified. `LatestQuarter` is contextual metadata, not a publication date, retrieval date or an assertion that every ratio describes that quarter. Ratios retain the provider's numeric scale; no automatic percentage conversion is performed. The overview's currency must not replace a statement's reported currency. Unknown fields, descriptions and provider narrative are excluded from normalized evidence and future AI inputs by default.

Cash-flow values retain their supplied signs. Free cash flow is not computed yet: the next metric boundary must establish the capital-expenditure sign convention. No values are merged across datasets, currencies or fiscal periods here. No future/published-as-of claim is made; persistence must validate fiscal dates against its retrieval context and store retrieval/publication evidence separately.

Official endpoint reference: [Alpha Vantage fundamental data documentation](https://www.alphavantage.co/documentation/#fundamentals), checked October 6. Documentation availability does not establish the owner's endpoint/symbol entitlement. Fixtures are synthetic and do not call the demo API or use real keys.

## Next implementation

1. Add workspace-scoped immutable snapshot persistence with dataset, provider/mapping identity, retrieval time, normalized evidence version and fingerprint. Store absent publication/revision evidence explicitly. Supply any migration in a separate docs SQL file with backup and forward-repair instructions.
2. Extend request dispatch to identify quote versus fundamental datasets, preserving existing quote retries and sharing the provider credential's atomic quota across both. Recheck owner membership/current mapping before dispatch and completion. Never retrieve data on page load.
3. Compute documented decimal metrics only from compatible currencies and fiscal periods, retaining missing inputs and zero-denominator coverage. Add comparable-period change and restatement handling before claiming deterioration.
4. Add on-demand journal reviews and staggered scheduling using the shared admin patterns. Link exact evidence and thesis revisions without modifying authored notes or ledger facts.
5. Add AI summaries after configurable model/pricing/budget reservations and explicit processing scope are implemented. No AI request or new paid processing is enabled by this parser.

## Daily movement remains separate

The existing quote payloads do not establish compatibility between saved prices and recorded share quantities around corporate actions. Alpha Vantage documents its adjusted daily time-series endpoint as premium; the free allowance alone does not grant access. FMP's split-adjustment conventions also require verification with the selected endpoint and ledger quantity context. Keep daily change unavailable until those contracts are established; never infer compatibility merely because two prices exist. References: [Alpha Vantage adjusted daily series](https://www.alphavantage.co/documentation/#dailyadj), [FMP historical-price guide](https://site.financialmodelingprep.com/how-to/fmp-historical-price-apis-from-light-charts-to-dividendadjusted-analysis).

## Validation

Eight new deterministic fixture groups bring the unit suite to 66 checks. Coverage includes numeric JSON precision, negative facts, explicit zero versus missing data, reporting-currency separation, annual/quarterly identity, ordering, unavailable markers, invalid types/precision, wrong symbols/asset types, invalid dates, duplicate/bounded collections, omitted untrusted text and redacted provider errors. Required syntax, coding standards and REST URL checks are recorded in testing.md. No database, HTTP, browser or production behavior is changed by this parsing-only addition; the previous valuation milestone's integration evidence is retained separately.

## October 6 continuation: immutable fundamental snapshot storage

Development source is now 0.27.0-dev/schema 11. Separate migration [011-fundamental-snapshots.sql](011-fundamental-snapshots.sql) adds append-only snapshots and a dataset discriminator on provider requests. Existing requests default to quote; all eleven SQL migrations belong in future packages. The latest released ZIP remains 0.26.0/schema 8.

Internal MarketData operations now reserve supported Alpha Vantage fundamental datasets through the same atomic credential-wide quota as quotes. Dataset changes conflict with an existing retry identity, and quote/fundamental completions cannot substitute for each other. Completion requires the original authorized owner, current enabled mapping and a compatible dispatched request. Normalized evidence, identity, reporting currencies, parser version, fingerprint, retrieval time and previous-snapshot link commit with the request state and audit. Publication time remains unknown; fiscal dates cannot be in the future. Retry returns the same snapshot; later requests append history, including restatements, without changing earlier evidence or financial facts. Authorized reads are workspace/asset scoped and cursor bounded.

Backup and forward repair: back up database and private images, keep external processing disabled, retain the matching development code and explicitly reactivate. The installer upgrades versions 1-10, recreates missing snapshot storage and adds the dataset column only when absent. An incompatible existing column blocks completion of the schema marker; inspect and repair its definition before reactivation. MySQL DDL is not atomic. Manual file 011 adds the column with ALTER; on repeated manual repair check SHOW COLUMNS first. Do not delete request or quota history to repair an upgrade.

Next: comparable decimal metrics and the disabled-by-default fundamental transport, then on-demand/scheduled review controls and AI summaries with configured model and spending reservations. This milestone adds no fundamental HTTP calls, review UI or OpenAI processing. Daily-change corporate-action compatibility remains open.
## October 6 continuation: exact fundamental metrics

Added pure decimal margin, liquidity, liabilities/assets, reported debt, net debt and free-cash-flow calculations. They group matching fiscal periods and reporting currencies and retain explicit missing/sign/denominator coverage. Free cash flow requires a verified source capex convention; its default is unavailable. The authorized application operation uses explicit immutable snapshot identifiers and retains source fingerprints, retrieval times and formula version without rewriting facts. See [formula contract](fundamental-metric-formulas.md).

73 unit and 119 disposable WordPress/MySQL integration checks passed, with coding standards, PHP/JavaScript syntax and six REST URL checks. The new checks cover exact numbers, losses, missing versus zero, signs/denominators, mixed periods/currencies, deterministic results, provenance, foreign/damaged evidence and read-only historical access. UI/HTTP behavior is unchanged; the preceding schema-11 storage milestone remains the latest browser/HTTP regression evidence. Source remains 0.27.0-dev/schema 11; no new SQL, release ZIP or live provider/OpenAI call.

Remaining: comparable-period growth/restatement interpretation, fundamental transport, scheduled/on-demand review screens and AI summaries with configurable model/monthly budget. Daily-change corporate-action compatibility remains open.