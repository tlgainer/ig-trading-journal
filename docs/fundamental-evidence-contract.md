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
