# Saved reporting-period changes

Research → Stock fundamentals → View a statement snapshot now includes a **Changes from previous available period** table. It uses saved statement evidence, without provider calls, AI calls or ledger writes. Annual and quarterly reports remain separate.

`fundamental-comparisons-1` is an additive result on the existing private fundamental-metrics API. The existing `fundamental-metrics-1` formulas remain unchanged. Each comparison retains current/prior fiscal dates, currencies, days between dates, exact values, change and coverage. The response's snapshot IDs, fingerprints and retrieval times identify its evidence.

For each period type, compare the immediately preceding available fiscal date within the selected evidence. Never skip a changed currency to find an older matching currency. Missing prior periods, changed currencies, multiple currencies on the same fiscal date, and unavailable metrics produce a null change with an explicit reason. Ambiguous prior currencies also suppress the prior value/currency rather than choosing one arbitrarily. Missing fields and unverified capital-expenditure signs remain unavailable.

Change = current metric − prior metric, using decimal-string arithmetic. Margin changes use **percentage points**, ratios use multiples, and debt/cash-flow changes use reported currency. Example: net margin from −5% to 10% changes by 15 percentage points. No percentage-growth calculation is inferred from negative or zero baselines.

These are **previous available period comparisons**, not verified year-over-year or quarter-over-quarter growth. Gaps may include missing reports. Fiscal end dates do not establish equal reporting duration, unchanged accounting policy, or comparability after restatement. The UI states these limits; it does not issue exit recommendations. Reporting periods are compared within the selected saved evidence, not against a later retrieval of the same fiscal period.

No migration or new SQL is needed. Development schema remains 12; the released ZIP remains 0.26.0/schema 8. Future packaging still requires all twelve bundled migrations and the documented backup/activation checks.
