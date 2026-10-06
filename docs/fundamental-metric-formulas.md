# Fundamental metric formulas

October 6, 2026 development milestone. Source remains 0.27.0-dev/schema 11. These calculations are internal building blocks for later fundamental reviews; no new admin screen, provider fetch or AI processing is enabled.

`FundamentalMetrics` is pure decimal-string code with formula version `fundamental-metrics-1`. `MarketData::fundamental_metrics` reads one to three explicitly selected immutable statement snapshots for an authorized workspace asset and returns their identifiers, fingerprints and retrieval times. It verifies evidence hashes, parser version, dataset and identity, and requires the same mapping revision across the selected statements. Saved evidence remains readable after that mapping is superseded. Calculations do not overwrite snapshots or financial history.

## Formulas and units

| Metric | Formula | Output unit and coverage |
| --- | --- | --- |
| Gross margin | grossProfit / totalRevenue × 100 | Percent; revenue must be positive |
| Operating margin | operatingIncome / totalRevenue × 100 | Percent; losses can produce negative margins |
| Net margin | netIncome / totalRevenue × 100 | Percent; losses can produce negative margins |
| Current ratio | totalCurrentAssets / totalCurrentLiabilities | Multiple; assets must be nonnegative and denominator positive |
| Liabilities to assets | totalLiabilities / totalAssets | Multiple; liabilities must be nonnegative and denominator positive |
| Total reported debt | shortTermDebt + longTermDebt | Reporting currency; both supplied debt fields must be known and nonnegative |
| Net reported debt | total reported debt − cashAndCashEquivalentsAtCarryingValue | Reporting currency; negative output represents net cash, cash input must be nonnegative |
| Free cash flow | operatingCashflow − positive capex outflow, or operatingCashflow + negative capex outflow | Reporting currency; explicit verified source convention required |

Reported debt means exactly the sum of the selected provider fields. It is not total liabilities or a claim that every lease, borrowing or debt classification is represented. No missing debt field is replaced with zero. Free cash flow retains negative operating cash flow; it does not guess expense signs or silently take absolute values. Zero capex is valid once a convention is established.

Calculations keep full existing Decimal precision (36-place intermediates) and leave display rounding to the UI. No binary floats are accepted for reported financial facts. Each metric returns `value`, `status` and `unit`; unavailable values are null. Status is `complete`, `missing_input`, `nonpositive_denominator`, `incompatible_sign` or `unknown_capex_convention`. A true reported numeric zero can produce a complete zero result.

## Period and identity rules

Reports are grouped by annual/quarterly period type, fiscal end date and reporting currency. Matching dates alone cannot merge annual and quarterly reports. A reporting-currency mismatch creates separate groups with missing-input coverage for absent datasets; no FX or overview currency is substituted. Duplicate fiscal periods in one dataset are rejected, as are mixed provider symbols or providers. Evidence ordering does not change results.

Overview ratios and TTM facts are not used to fill statement gaps. Fiscal end dates do not establish period-start dates, duration or publication time. Automatic quarter/year growth, annualized returns, ROE using average equity, valuation ratios and claims of deterioration remain pending comparable-period/source contracts. No calculation classifies a change as a reason to exit an investment.

## Source policy

The development API also returns versioned [previous available period changes](fundamental-period-comparisons.md). These exact metric differences preserve gaps and block incompatible currencies; they do not infer year-over-year or quarter-over-quarter growth. The metric formula version remains unchanged.

The capital-expenditure convention argument is trusted server policy, not a client field. The default is unknown and leaves free cash flow unavailable. Fundamental transport must verify the endpoint's expense convention before setting it; this milestone does not claim that verification for a live provider. Later immutable reviews must retain the formula version, exact source vector and convention along with their outputs.

## Validation

Seven new unit groups cover exact formulas, negative earnings/cash, zero versus missing facts, tiny/large decimal inputs, denominator/sign rejection, currency/period separation, deterministic ordering and malformed/duplicate identity evidence. Three new disposable database groups cover fingerprint-linked output, read-only history, viewer/revoked access, foreign/wrong-dataset/mapping selections, historical mappings and corrupted-evidence blocking.

73 unit and 119 WordPress/MySQL integration checks passed, with coding standards, PHP/JavaScript syntax and six REST URL checks. No schema change or new SQL is required. Existing browser/HTTP workflows were unchanged; their last full regression evidence is the schema-11 storage milestone in testing.md.
