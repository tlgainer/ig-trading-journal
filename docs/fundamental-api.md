# Fundamental API — development implementation

The development API exposes saved fundamental evidence and exact calculations. These are provider facts and calculated metrics, not AI investment reviews. No new SQL is required beyond bundled schema 11. The released installation ZIP remains 0.26.0/schema 8.

All routes use existing authentication, HTTPS, explicit workspace membership and private/no-store response contracts. Credentials stay server-side.

## Saved history

`GET /tgit/v1/workspaces/{workspace}/assets/{asset_id}/fundamentals?after=0&limit=100`

Active viewers can read immutable snapshots for their workspace asset. Results contain `items` and `next_cursor`; follow every cursor before claiming search/sort covers all history. Limits are 1–100. Foreign workspace assets are rejected. Reads make no provider requests.

## Exact statement metrics

`POST /tgit/v1/workspaces/{workspace}/assets/{asset_id}/fundamental-metrics`

Supply only `snapshots`: a dataset-to-integer-snapshot-ID object with one to three of `INCOME_STATEMENT`, `BALANCE_SHEET`, and `CASH_FLOW`. The source-vector contract validates workspace, asset, dataset, mapping revision and evidence fingerprint. The response includes formula version and source provenance. Viewers can calculate metrics; this operation writes no financial facts.

Clients cannot set the capex sign convention. Free cash flow stays unavailable until trusted source policy is verified. Incompatible evidence fails without substituting newer snapshots.

## Owner refresh

`POST /tgit/v1/workspaces/{workspace}/provider-mappings/{mapping}/fundamentals/refresh`

Supply only `dataset`, one of `OVERVIEW`, `INCOME_STATEMENT`, `BALANCE_SHEET`, or `CASH_FLOW`, and a nonempty `Idempotency-Key` header of at most 80 bytes. Only owners can refresh. The current enabled mapping and separately enabled fundamental worker are required; client symbols, URLs and credentials are rejected. Alpha Vantage fundamentals share the quote allowance.

Preserve the same key when checking uncertain delivery. Completed retries return saved evidence without resending. Do not issue new keys to retry uncertain dispatches. Each dataset is one request; this is not an atomic four-dataset refresh.

Owner-only `GET /workspaces/{workspace}/market-data` now reports `fundamentals_enabled` without exposing credentials.

## Validation and remaining work

73 unit and 129 disposable WordPress/MySQL integration checks pass. Route tests cover pagination, membership/revocation, foreign assets/snapshots, typed selections, forbidden client policy, unchanged evidence/ledger facts, strict refresh input, disabled behavior and owner-only access. All four enabled refresh routes use intercepted synthetic responses and preserve cached retry identity. Coding standards, PHP/JavaScript syntax and six REST URL checks pass.

No screen or schema changed; browser fixtures were not rerun. No live provider, OpenAI or production request was made. Research history tables and refresh controls, journal navigation, recurring enrollment, comparable-period changes and configurable AI summaries/model/budget remain to implement. A release still requires the full browser/HTTP and packaging gates.

Subsequent development now exposes history, selected-statement metrics and owner refresh controls in Research. See [user guide](user-guide.md). Journal navigation, recurring fundamental enrollment and AI review controls remain pending.
