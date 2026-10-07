# Persistent AI spending coordination

Development schema 13 adds [013-ai-spending.sql](013-ai-spending.sql). This is an internal application foundation, not an enabled AI summary feature. No OpenAI transport, cron job, paid call, live model catalog, REST controls or new ZIP is introduced.

## Shared policy and explicit consent

One singleton pool coordinates spending for this WordPress site's plugin tables. It deliberately spans all configured credential digests, so rotating a key cannot create another allowance. The first explicitly saved configuration establishes its controller workspace; only current owners of that workspace may append shared policy revisions. Site administrator status alone grants no access. There is no automatic controller transfer; a reviewed recovery contract is required if its ownership becomes inaccessible.

The default read-only suggestion is 15.00 USD with processing disabled and no model. Enabling policy requires a trusted server catalog entry for the selected model, current pricing, required text capabilities and confirmed access. Client-supplied pricing is not accepted by the configuration contract. Each workspace separately opts in through append-only enrollment. New admissions and dispatch recheck the original configuration/enrollment authorizers' current owner membership as well as the requesting owner.

Owner status contains only shared model/cap, aggregate estimated spending, unresolved holds, remaining allowance, period/reset and the current workspace's enrollment. It excludes foreign request rows, controller workspace/actor identifiers and credential digests. Shared aggregate spending is intentionally site-wide; it is not a per-workspace entitlement to the entire cap.

## Atomic lifecycle

Every operation acquires the same named database lock **before** opening its InnoDB transaction and workspace locks. Operations also lock the singleton pool and relevant policy/enrollment revisions. This serializes admission across all workspaces and avoids a transaction snapshot preceding another AI commit. Other financial operations cannot bypass membership checks: dispatch additionally locks the controller workspace while rechecking its authorizer.

Requests are immutable reservations containing the authorizer, configuration/enrollment revisions, credential digest, exact hashed retry key, approved-input fingerprint, selected model, pricing JSON/fingerprint, input/output token bounds, maximum USD cost, original New York budget month and ten-minute expiry. No raw API key, account balance, journal text or private media is stored in these budget tables.

Lifecycle facts append to workspace-scoped events: reserved → dispatched → settled/uncertain/overrun. Explicit unsent cancellation appends cancelled. Each event and its audit commit together; audit failure rolls the operation back. Reusing the same retry identity returns its original request; changed actor, credential, input fingerprint or bounds conflict. Keys are hashed case-sensitively.

Dispatch is a single claim, not a network send. It rejects changed configuration/enrollment, disabled consent, revoked authorizers, changed credential, pricing corruption/expiry, reservation expiry, month rollover, zero/lowered cap or unresolved overruns. A changed model affects future reservations; an older unsent request must be explicitly cancelled and a new request created. Dispatched or uncertain requests never automatically resend or receive a cancellation refund.

Settlement validates captured pricing at the original dispatch time, preserving old model/prices even after disable or expiry. Normalized usage is canonicalized for idempotency. Malformed usage cannot refund the hold. Unknown delivery remains fully reserved, including in later months. Verified settled usage is assigned to the reservation's original budget month; dispatch across a month boundary is blocked. An expired unsent reservation continues to consume allowance until an authorized cancellation; no implicit cleanup releases possibly sent work.

An overrun retains the full calculated charge and blocks all new reservations/dispatch, even after raising the cap. Automated overrun acknowledgment/repair is not implemented; preserve its evidence for a reviewed reconciliation operation rather than deleting events. Future transport must also validate that a reported charge represents the expected response/model/usage before supplying it to this internal API.

## Upgrade and repair

Back up the database and private media, keep external processing disabled, and explicitly reactivate matching development code. The installer accepts versions 1–12 and applies all thirteen bundled migrations; it also repairs missing additive tables. MySQL DDL is not atomic. Repair permissions/definitions and reactivate after partial failure. Never downgrade the schema marker, recreate the singleton pool to reset spending, or delete requests/events. Deactivation/uninstall preserve all accounting; this milestone schedules no jobs.

The released ZIP remains 0.26.0/schema 8. Do not run development SQL on production simply to enable AI. Owner controls, catalog verification, approved evidence/thesis bundles, saved reviews, response/citation validation and disabled-by-default transport remain next work.

## Validation

Disposable fixtures isolate AI tables in a fresh synthetic prefix for every run, retaining prior fixtures without deleting history. Tests cover schema repair, owner isolation, explicit opt-in, immutable revisions, rotation-resistant shared admission, retry conflicts, single claims, expiry/month changes, pricing fingerprints, retained uncertainties, usage idempotency, revocation, audit rollback and ten two-workspace races for the final allowance. Prices/models are fictional; no requests leave the test host.
