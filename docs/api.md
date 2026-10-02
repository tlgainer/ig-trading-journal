# REST contract: tgit/v1, build 0.2.0

All routes are private. Use a WordPress cookie session plus `X-WP-Nonce` from `wp_create_nonce('wp_rest')`, or WordPress application-password authentication over HTTPS. Production and staging require HTTPS; only explicitly local/development environments allow HTTP. Every service checks current membership; site administrator status cannot bypass workspace authorization.

Base path: `/wp-json/tgit/v1`.

| Route | Methods | Permission |
| --- | --- | --- |
| `/workspaces` | GET | Logged-in actor, returns only active memberships |
| `/workspaces` | POST | Site operator `manage_options`; explicitly creates owner membership |
| `/workspaces/{workspace}/members` | GET, POST | Workspace owner |
| `/workspaces/{workspace}/accounts` | GET, POST | Read: active member; create: owner/manager |
| `/workspaces/{workspace}/assets` | GET, POST | Read: active member; create: owner/manager |
| `/workspaces/{workspace}/transactions` | GET, POST | Read: active member; post: owner/manager; draft: owner/manager/contributor |
| `/workspaces/{workspace}/holdings` | GET | Active member |

GET accounts/assets/transactions use `after` (numeric ID cursor, default 0) and `limit` (1-100, default 100). Response `data.items`, `data.next_cursor`; continue until cursor is null. An exact full last page may give one extra empty request. Holdings cursor is an asset ID; it groups that page's asset/account positions. It does not support historical as-of queries yet. GET workspaces/members are setup lists.

POST bodies must be JSON objects with no unknown fields. IDs are positive JSON integers. Decimal inputs are plain unsigned JSON strings, never numbers, exponent notation or localized commas. Server-generated IDs currently serialize as database strings on reads. Currency fields are uppercase three-letter codes; semantic ISO registry validation will be added with currency metadata. Asset classes supported: `stock`, `etf`, `crypto`; exchange/network identity is mandatory.

Example workspace body:

Only `name` is required. Omitted `base_currency` defaults to `USD`; omitted `timezone` defaults to `America/New_York`. Explicit overrides must still pass validation. New York follows EST/EDT seasonally, rather than a fixed UTC offset.

```json
{"name":"My Portfolio","base_currency":"USD","timezone":"America/New_York"}
```

Account body: `name`, `native_currency`, optional `broker` and `type` (`brokerage`, `exchange`, `bank`, `wallet`). Assets: `symbol`, `exchange`, `asset_class`, `quote_currency`. Members: `wp_user_id`, `role` (`owner`, `manager`, `contributor`, `viewer`), `state` (`active`, `revoked`). Users must already exist in WordPress. You cannot revoke/demote the last active owner.

Every transaction POST requires `Idempotency-Key` (8-128 ASCII letters/digits or `._:-`). Reuse the exact key and payload after an uncertain response. Changed payload with the same key returns 409. Keys are scoped to workspace and transaction creation and retained with financial evidence. The key is an operation identity, not an access token; retries still require current permission.

```json
{"account_id":1,"action":"deposit","effective_date":"2026-01-01","state":"posted","amount":"2000","currency":"USD"}
```

```json
{"account_id":1,"asset_id":1,"action":"buy","effective_date":"2026-01-02","state":"posted","quantity":"10","unit_price":"100","fees":"5","currency":"USD"}
```

`sell` uses the buy-shaped body. `withdrawal` uses the deposit-shaped body. `state` may be `draft` (no legs, lots or cash effects). Cash movements reject asset/quantity/price/fee fields; buys/sells reject amount because the server calculates it. Currency must match the account and asset. Dates are `YYYY-MM-DD`; execution timestamps are not fabricated. Prior-account-date postings return conflict. Negative cash and overselling are rejected. Posted financial events remain immutable. Draft editing and promotion use the endpoints below.

Success: `{"data": ..., "correlation_id": "uuid"}`. Stable errors: `tgit_validation` (400), `tgit_unauthenticated` (401), `tgit_forbidden`/`tgit_https_required` (403), `tgit_not_found` (404), `tgit_conflict` (409), `tgit_unavailable` (503), `tgit_internal_error` (500). Application errors include a correlation ID; every routed response carries `X-Correlation-ID` and no-store headers. Field-detail error arrays are not yet implemented.

Holdings return `quantity`, `remaining_basis`, `realized_gain`, native `currency`, `calculation_version`, `as_of`, `market_value: null`, `unrealized_gain: null`, `price_status: missing`. This version returns live ledger positions, not historical snapshots; `as_of` is response time. There are no base totals or complete valuation claims.


## Draft revisions (0.3.0)

- GET /workspaces/{workspace}/transactions/{id}: returns transaction and ordered immutable revisions. Revision payload is stored JSON; each revision includes actor, reason and timestamp.
- POST /workspaces/{workspace}/transactions/{id}/draft: replace a draft using {"expected_revision":1,"transaction":{...complete draft creation facts...}}. Owners/managers can edit any draft; contributors can edit only their own. State must be draft.
- POST /workspaces/{workspace}/transactions/{id}/post: post using {"expected_revision":2}. Only owners/managers can post. Posting validates current account/cash/lots and chronological ordering.

Both writes require Idempotency-Key and an integer expected_revision. Stale revisions, promoted drafts, posted events and conflicting key reuse return 409. Keys are scoped to workspace, operation and source draft. A retry with the identical key/body returns the original result after current authorization. Financial validation failure rolls back all changes and leaves the source draft editable.

Promotion creates a distinct immutable posted transaction, marks the source draft promoted, and appends a source revision linking posted_transaction_id. The source retains its creator and complete history; it has no ledger effects. The response includes transaction (posted event) and draft (archived source). Same-day FIFO follows committed posted IDs. No SQL migration is required.
