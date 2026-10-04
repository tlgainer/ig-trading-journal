# REST contract: tgit/v1, build 0.8.0

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
| `/workspaces/{workspace}/opening-balances` | POST | Owner/manager; documented starting cash or pre-existing asset lot |
| `/workspaces/{workspace}/opening-balances/retroactive` | POST | Owner/manager; reviewed opening before posted history |
| `/workspaces/{workspace}/opening-balances/{id}/basis-resolutions` | POST | Owner/manager; revise evidence for unknown opening lot basis |
| `/workspaces/{workspace}/replay-preview` | POST | Owner/manager; read-only chronological preview |
| `/workspaces/{workspace}/historical-cash` | POST | Owner/manager; posted historical deposit/withdrawal |
| `/workspaces/{workspace}/historical-transactions` | POST | Owner/manager; posted historical buy/sell |
| `/workspaces/{workspace}/transactions/{id}/corrections` | POST | Owner/manager; posted cash or security correction |

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

## Journal, strategy and private images (0.4.0)

All routes are relative to `/tgit/v1/workspaces/{workspace}` and use the existing private envelopes/errors/transport/authentication. Every JSON write and multipart finalize requires Idempotency-Key. Existing-object JSON edits additionally require integer expected_revision. Viewers read; owner/manager/contributor edit journals/media; owner/manager manage strategies; only owners manage media policy/cleanup.

| Route | Methods | Contract |
| --- | --- | --- |
| /trades | GET, POST | Cursor after/limit (max 100); create full trade/journal facts |
| /trades/{id} | GET, POST | Current trade/fills/captured strategy and latest 20 journal revisions; full replacement with expected_revision |
| /trades/{id}/revisions | GET | Immutable history with exclusive before revision cursor; limit 1–20 |
| /strategies | GET, POST | Cursor list; create strategy version |
| /strategies/{id} | GET, POST | Current strategy/latest 20 versions; full replacement with expected_revision |
| /strategies/{id}/versions | GET | Older immutable versions with before/limit |
| /media-settings | GET, POST | Health/used bytes/limits; owner explicit policy with expected_revision=0 initially |
| /trades/{id}/images | GET, POST | Active/recoverable gallery; reserve filename/hash/size and optional metadata |
| /images/{id}/upload | POST | Exactly one multipart file; size/hash must match reservation; bounded synchronous normalization |
| /images/{id}/retry | POST | Replace reserved/failed file declaration using expected_revision, filename, hash, size; ready images conflict |
| /images/{id}/metadata | POST | Expected revision and caption/alt_text/stage/timeframe/sort_order |
| /images/{id}/delete | POST | Expected revision; block access and retain recoverable trash |
| /images/{id}/restore | POST | Expected revision; restore only within retention |
| /images/{id}/content | GET | variant=original or thumbnail; authenticated binary with private/no-store, nosniff/noindex headers |
| /media-cleanup | POST | Empty JSON object; owner-only bounded expired-trash/abandoned-reservation cleanup |

Trade input: asset_id, title, state (planned/open/closed/archived), optional opened_on/closed_on, optional nullable strategy_version_id, transaction_ids (up to 200), journal object, and expected_revision for edits. Linked transactions must be workspace buy/sell fills for the asset and cannot belong to another trade. Promoted source drafts are replaced by their posted fill while preserving journal revisions.

Journal fields: thesis, entry_rationale, exit_rationale, emotions, lessons, notes, confluence_text, original_confluences, tags, confluences, confidence; optional premarket_low/high, previous_day_low/high, planned_stop/target. Level values are decimal strings or blank/null. Confidence is optional integer 0–100. Confluences accept up to 50 `{label,checked}` items with boolean checked state (plain string labels are normalized as checked for compatibility). Text is bounded to 20000 bytes/field; tags/labels to 50 items and 190 bytes/item. Original confluence text remains plain data, not executable content.

Strategy input: name, status (active/archived), description, rules, tags and expected_revision for edits. Rich text uses a basic allowlist without remote image embeds. A trade's captured strategy version remains unchanged unless explicitly changed through a new journal revision.

Media reserve requires SHA-256 input hash, integer size, filename and optional caption/alt_text/stage/timeframe/sort_order. Metadata fields default blank/review/order 0. File names are display metadata only. Gallery responses omit internal keys and expected input hashes; content_hash is the normalized original hash. Thumbnail/original byte access rechecks membership and the trade relationship. Deleted/not-ready bytes return 404; anonymous/revoked/foreign requests are denied through the normal permission/object contracts.

Limits: max_images 1–100; max_file_bytes 1–10485760; max_pixels 1–40000000; quota_bytes 1–107374182400; trash_days 1–365. Existing usage cannot exceed a newly selected quota. Trash and pending/failed reservations count until purged. Settings increases cannot bypass reservation/quota revalidation during finalize.

History pages return items in ascending order within the newest page and next_cursor pointing to the exclusive before value. Trade detail uses revisions_cursor; strategy detail uses versions_cursor. Stale edits, wrong retry identity, double finalize and attempts to replace ready images return conflict. Ordinary upload/decoder/DB failures preserve a saved journal and independent ready images. See private-images.md for hosting and operational boundaries.


## Documented opening balances (0.5.0)

POST `/workspaces/{workspace}/opening-balances` requires `Idempotency-Key` and owner/manager membership. It atomically creates an immutable posted opening event, revision, ledger leg, source record and audit event. Cash adds to the account balance; a pre-existing lot adds FIFO inventory without deducting cash. All opening entries for an account share one opening date and the ordinary route requires them before posted trading.

Cash JSON: `{"account_id":1,"kind":"cash","effective_date":"2026-01-01","amount":"2000","source_note":"Broker statement reference"}`. One opening cash entry per account. The amount must be positive.

Lot JSON: `{"account_id":1,"asset_id":2,"kind":"lot","effective_date":"2026-01-01","acquired_on":"2021-06-01","quantity":"10","basis_status":"complete","amount":"1000","source_note":"Original lot statement"}`. A documented known zero basis is accepted. For unknown basis, set `basis_status` to `unresolved` and omit `amount`. Holdings then return `basis_status: unresolved` and `remaining_basis: null`; sales of that account/asset are blocked until basis evidence is recorded.

`POST /workspaces/{workspace}/opening-balances/retroactive` accepts the same cash or lot body and `Idempotency-Key`. The opening date must match any existing opening entries and be no later than the first ordinary posted event. Replay validates every later balance and FIFO allocation, then appends a versioned calculation run without editing old posted facts. Same-day opening entries precede ordinary events.

`POST /workspaces/{workspace}/opening-balances/{id}/basis-resolutions` accepts `{"expected_revision":0,"amount":"1000","reason":"Broker basis statement"}` and `Idempotency-Key`. The ID identifies an original unresolved opening-lot transaction. A first resolution uses revision 0; subsequent corrections use the latest resolution revision. Explicit zero is valid with documentary reason. Original opening facts remain unchanged; each resolution and replay run is append-only. A stale revision returns conflict, and later sales use the active documented basis.

## Corrections and replay (0.7.0)

`POST /workspaces/{workspace}/transactions/{id}/corrections` requires an `Idempotency-Key`, owner/manager membership, `expected_revision`, a nonempty `reason`, and a complete `replacement` posted deposit, withdrawal, buy or sell body. The replacement must retain the account and currency; its asset must belong to the workspace and use that currency. The original posted facts and leg remain immutable; a correction link and source revision mark it superseded. The original same-day chronological position survives correction chains. All later cash balances and FIFO lots are replayed atomically; an overdraft, oversell or unresolved sale basis rejects the entire correction. A retry of the same key and body returns the original result. Trade-linked fills are currently refused until their journal/link revision workflow is defined.

Example: `{"expected_revision":1,"reason":"Broker statement corrected","replacement":{"account_id":1,"action":"deposit","effective_date":"2026-01-01","state":"posted","amount":"1500","currency":"USD"}}`. Transaction list rows include `corrected_by_id`, `supersedes_id` and `current_realized_gain`; transaction detail includes correction evidence, all incoming/outgoing `correction_links`, ordered revisions and `current_calculation`. The original `realized_gain` field stays as posted evidence; later FIFO changes appear in the current calculation. Do not sum historical posted legs or gains without excluding superseded sources and using the active replay projection.

`POST /workspaces/{workspace}/historical-cash` accepts the same complete transaction shape as ordinary posting, limited to posted deposits/withdrawals, and requires `Idempotency-Key`. It validates every subsequent balance in account date/order sequence before inserting one immutable event. It cannot precede an account opening date. Ordinary transaction posting still blocks backdating.

`POST /workspaces/{workspace}/historical-transactions` accepts a complete posted buy or sell and requires `Idempotency-Key`. It validates every subsequent cash balance, FIFO lot and sale basis. A successful historical write appends a full account replay run with a source fingerprint, calculator version, current lots, gains and allocations. Later normal posting on that account appends another run. Prior posted rows and allocations remain immutable evidence; current holdings and gains come from the active projection.

`POST /workspaces/{workspace}/replay-preview` accepts a complete proposed posted transaction body and returns `applied:false`, current/projected cash, proposed cash delta/gain, changed realized gains and allocations for later transactions, and a source fingerprint. It performs no write and is not a reservation or approval to post.
