# Validation evidence and commands

## Development 0.27.0-dev provider foundation

`php tests/run.php` passes 51 deterministic checks, including lossless JSON/scientific notation, provider response validation and exact fixed-holding price movement. `tests/provider-integration.php`, included by the full disposable WordPress integration suite, extends total integration coverage to 98 checks. It tests schema 8-to-9 repair, scoped immutable mappings/quotes, exact persistence, both provider quotas, retry/dispatch state, known failures/unknown outcomes, revocation and audit rollback. The final-slot race runs ten two-workspace attempts per suite to detect InnoDB lock-upgrade regressions.

The quote worker fixtures intercept every WordPress HTTP attempt with `pre_http_request`, asserting fixed HTTPS host, TLS verification, no redirects, bounded timeout/response and no second send after success or uncertainty. Their synthetic API key exists only in the test process, never configuration files. One-shot scheduling/owner permission/deactivation are covered; no real provider or OpenAI request is made. This does not establish live entitlement coverage or constitute a production release. Recurring calendars, FMP transport, valuation/UI and AI-review gates remain.

## Build 0.10.0 reporting validation

PHP 8.1.34 with BCMath/GD, WordPress 7.1.2 and disposable MySQL 8.0.26 pass 41 domain and 79 integration checks. `tests/report-integration.php` covers additive schema-7-to-8 repair/repetition, exact AC02 native valuation, cash-inclusive allocation, acquisition/disposal-date AC07 FX, missing/stale values, immutable report retention after a price correction, saved-view actor isolation/revisions, viewer permissions/revocation, documented zero valuation/unknown basis/zero denominators, economic gain excluding funding, and audit failure rollback/retry for observations/reports/views. Run it through the full disposable integration suite, never on production.

`node tests/reports-browser.cjs` passes at 1100 and 360 pixels: manual price/FX entry, correction, earlier report load, saved-filter reapplication, unavailable-income messaging, no JavaScript errors and no horizontal page scrolling. Run with the locked Playwright tools under `tests/browser` or the installed ignored `tmp/browser-tests` tool path. Temporary cookies, credentials and screenshots remain ignored under `tmp`.

Required release checks: `php tests/run.php`, PHP syntax, `composer check-cs`, `node tests/rest-url.cjs`, JavaScript syntax, the full WordPress/database integration suite, `node tests/media-http.cjs`, and the research/opening/journal/report browser workflows. Do not run browser/HTTP mutations concurrently with the integration suite's deliberate database-failure triggers or schema repairs. Local evidence does not establish Apache PHP BCMath/GD configuration, MariaDB/minimum-version compatibility, performance or production backup/restore readiness.

Sanitized fixtures only. Do not run integration tests on a production database.

## Unit/domain tests

```text
php tests/run.php
node --check assets/admin.js
node --check assets/rest-url.js
node tests/rest-url.cjs
```

Lint every production and test PHP file with `php -l`. BCMath is mandatory. The self-contained runner fails on the first discrepancy; tests do not rely on PHP's optionally disabled assertions.

Coverage: AC 01 and AC 03, fractional crypto, full residual-basis consumption, proceeds conservation, input precision, overflow, floating-point avoidance, negative fees, oversell, role-policy denial, contributor restrictions and owner revocation. All amounts remain decimal strings. These unit tests do not prove database isolation or concurrency.

## Disposable WordPress integration

Activate the plugin in a throwaway WordPress/MySQL site. Add `define('TGIT_DISPOSABLE_TEST_SITE', true);` to that site's wp-config.php, then run:

```text
wp eval-file tests/wordpress-integration.php
```

The suite creates synthetic users and two workspaces and checks native cash/FIFO persistence, idempotency, rejected foreign relationships, oversell/overdraft rollback, draft independence, last-owner protection, denied viewer posting, foreign workspace access and immediate revocation. It also tests internal REST dispatch, deliberately forces an audit-write failure using a disposable database trigger, and starts two PHP workers to prove concurrent sales cannot oversell. The test user needs CREATE TRIGGER privileges; PHP must permit `proc_open`. It retains fixtures for inspection; recreate the throwaway site afterwards. It is not a production cleanup or migration script.

## Coding standards and development dependencies

Run `composer install` from the committed lockfile, then `composer check-cs`. `composer fix-cs` formats production PHP. Dependencies are development-only; runtime code uses its own namespaced class loader. `phpcs.xml.dist` preserves WordPress formatting/security checks while allowing PSR-style namespaced class filenames. Narrow source comments explain the SQL-wrapper false positives and uncached migration queries; no broad SQL-security rule is disabled for application code.

Run `composer audit --locked` when changing the lockfile. Tool licenses are recorded in `docs/dependencies.md`.

## Outstanding release gates

- REST HTTP authentication/CSRF and broader malicious payload fixtures (internal REST dispatch and concurrent/fault-injection database cases have passed).
- Fresh activation, partial schema repair and minimum/runtime matrix; MariaDB.
- Broader desktop/mobile form behavior and independent accessibility review; targeted opening and journal browser workflows passed, but no full E2E release gate is claimed.
- Complete correction/replay/media/import/export/restore acceptance tests as those features land.
- Current minimum WordPress and benchmark/performance hosting validation.

Current executed results are recorded in `docs/implementation-status.md`.

REST URL regressions cover pretty permalinks, plain query routing and subdirectory/index.php installations, including paginated requests. These are URL-construction tests, not claims of remote-host HTTP access or HTTPS verification.


The integration suite includes tests/draft-integration.php, tests/opening-integration.php and tests/replay-integration.php: revision conflicts, promotion retries, source linkage, rollback, exact quantities, REST isolation, simultaneous promotion, schema 2-to-3 and 3-to-4 repair, opening source evidence, known/unknown basis, cash/lot atomicity, cash corrections and historical cash replay. Run them through wordpress-integration.php in the disposable site; never directly on production. Current totals: 35 domain/permission/replay, 60 integration and 6 REST URL checks. The localhost HTTP and browser fixtures additionally exercise 11 media cases, desktop/mobile journals, and an opening cash/lot followed by a FIFO sale.

Market-data development browser fixture: `tests/market-data-browser.cjs` runs against the authenticated disposable preview and synthetic stock records. It verifies mappings/revisions, disabled refresh, dirty navigation and 360/768/1440px overflow. Browser-only intercepted refresh/configuration responses verify that uncertain retry keys survive reload without making external calls. Current provider coverage: 53 unit and 102 WordPress/MySQL integration checks; final run evidence is in implementation-status.

Recurring quote fixtures add weekday/DST slot policy, schema-9-to-10 enrollment repair, owner-only configuration, append-only revisions, disable/revocation checks, duplicate/late/future job behavior, audit rollback and recovery scan. Current totals: 55 unit and 106 disposable WordPress/MySQL integration checks. The market-data browser fixture additionally saves and disables a schedule while server providers remain disabled; synthetic intercepted uncertain refresh responses still preserve retry keys across reload.

## October 6, 2026: cached provider holdings and stock totals

Development source remains 0.27.0-dev/schema 10; the released ZIP remains 0.26.0/schema 8. Overview now offers an explicit manual/FMP/Alpha Vantage stock price source and exact open-stock market value and unrealized gain/loss totals across all authorized accounts and asset pages. Other asset classes keep manual pricing. Values are grouped by native currency, exclude cash and closed positions, and do not change with table filters.

Provider valuations require the latest enabled matching mapping and a saved quote no more than three calendar days old. Missing/stale provider prices remain unavailable without fallback. Unknown basis leaves gain unavailable. Partial coverage shows covered subtotals; mixed price dates cannot claim a complete total. Source preference is scoped to the current user/workspace browser session. Existing posted facts, manual observations and saved reports remain unchanged; reads send no external requests.

Validation: 58 deterministic unit checks and 110 disposable WordPress/MySQL integration checks passed, including full pagination, source validation, membership, staleness, mapping replacement, unknown basis and immutable manual reports. Coding standards, PHP/JavaScript syntax and six REST URL checks passed. The cached valuation browser fixture passed source switching, exact values, missing coverage, scoped restoration and desktop/mobile layout. No new SQL, live provider calls or release ZIP.

Next: corporate-action compatibility before displaying daily change, then fundamental snapshots and AI summaries with configurable model and monthly budget. Exchange holidays and operational monitoring remain open.
Final regression: market-data Settings, 11 real HTTP media checks, desktop/mobile journal and all eight admin sections at 360/768/1440px passed on the disposable site. No live provider traffic was used.

## October 6 continuation: fundamental parsing

Added an internal lossless Alpha Vantage overview/income/balance-sheet/cash-flow parser. It keeps annual, quarterly and trailing figures distinct, preserves signed decimals and reported currencies, distinguishes missing values from zero and excludes provider narrative. The [fundamental evidence contract](fundamental-evidence-contract.md) records bounds, validation and the next persistence/metric/request/UI steps. This does not yet deliver scheduled or on-demand fundamental reviews or AI summaries. Daily-change corporate-action compatibility remains open; no paid endpoint is enabled.

Validation: 66 unit checks, coding standards, PHP/JavaScript syntax and six REST URL checks passed. This isolated parser changes no database or browser workflow, so the preceding milestone's 110 integration/HTTP/browser checks are prior evidence, not newly rerun checks. No new SQL or installation ZIP; source remains 0.27.0-dev/schema 10 and the released ZIP remains 0.26.0/schema 8.
## Schema 11 fundamental storage validation

66 unit and 116 disposable WordPress/MySQL integration checks passed. New integration groups cover upgrade from schema 10, retained quote reservations/default dataset, missing-table repair, repeat activation, incompatible-column blocking/forward repair, exact normalized evidence, immutable restatements, identical/conflicting retries, future fiscal dates, mapping changes, viewer/other-owner/revocation checks, cursor bounds, shared quote/fundamental quota with on-demand headroom and transactional audit rollback. Historical migration fixtures now compare the current Installer::VERSION rather than hard-code the preceding schema; their financial assertions are unchanged.

Coding standards, PHP/JavaScript syntax and six REST URL checks passed. Cached valuation and market-data Settings browser checks, 11 real HTTP private-media checks, desktop/mobile journal workflows and all eight admin sections at 360/768/1440px passed against schema 11. The final executable ALTER/repair path was then verified again by the full database suite. Tests used the isolated disposable site, synthetic evidence and mocked quote responses; no live provider/OpenAI calls, production SQL or deployment occurred. Source remains 0.27.0-dev; no new release ZIP was created.
## October 6 continuation: exact fundamental metrics

Added pure decimal margin, liquidity, liabilities/assets, reported debt, net debt and free-cash-flow calculations. They group matching fiscal periods and reporting currencies and retain explicit missing/sign/denominator coverage. Free cash flow requires a verified source capex convention; its default is unavailable. The authorized application operation uses explicit immutable snapshot identifiers and retains source fingerprints, retrieval times and formula version without rewriting facts. See [formula contract](fundamental-metric-formulas.md).

73 unit and 119 disposable WordPress/MySQL integration checks passed, with coding standards, PHP/JavaScript syntax and six REST URL checks. The new checks cover exact numbers, losses, missing versus zero, signs/denominators, mixed periods/currencies, deterministic results, provenance, foreign/damaged evidence and read-only historical access. UI/HTTP behavior is unchanged; the preceding schema-11 storage milestone remains the latest browser/HTTP regression evidence. Source remains 0.27.0-dev/schema 11; no new SQL, release ZIP or live provider/OpenAI call.

Remaining: comparable-period growth/restatement interpretation, fundamental transport, scheduled/on-demand review screens and AI summaries with configurable model/monthly budget. Daily-change corporate-action compatibility remains open.
## October 6 continuation: bounded fundamental transport

Added a separately disabled-by-default Alpha Vantage fundamental worker and explicitly owner-authorized one-shot jobs for overview/income/balance-sheet/cash-flow snapshots. It uses fixed HTTPS transport bounds, the existing shared quote/fundamental request pool, typed retry identity and immutable completion. Completed requests reuse evidence; timeouts/audit failures after delivery remain uncertain and are not resent. Provider/entitlement errors retain consumed quota. Revocation and mapping changes block dispatch or completion. Deactivation removes pending fundamental jobs while retaining snapshots and allowances. See [worker contract](fundamental-refresh-worker.md).

Configuration additionally requires TGIT_FUNDAMENTALS_ENABLED strictly true. Price enablement alone cannot enable fundamental fetching. No recurring review enrollment, owner-facing analyze action or OpenAI processing is enabled by this milestone. The official Alpha Vantage endpoint documentation was verified; responses remain synthetic mocks and no live entitlement is claimed.

73 unit and 126 disposable WordPress/MySQL integration checks, coding standards, PHP/JavaScript syntax and six REST URL checks passed. UI/HTTP and schema are unchanged; their latest full regression remains the schema-11 storage milestone. Source stays 0.27.0-dev/schema 11; no SQL or release ZIP is added. Next: review screens/on-demand actions, recurring enrollment, comparable-period changes and configurable AI summaries.
## Fundamental Research development controls — October 6, 2026

73 unit / 129 disposable WordPress/MySQL checks pass. New tests/fundamental-browser.cjs covers owner/viewer history, exact statement metrics, disabled refresh, same uncertain request key across reload and 360/768/1440 layouts. Market-data Settings and all eight admin-shell sections pass. All 11 real HTTP media checks pass. Coding standards, PHP/JavaScript syntax and six REST URL checks pass. Synthetic saved evidence and intercepted browser refresh responses only; no provider or OpenAI calls. Journal-to-fundamentals navigation and recurring/AI review workflows remain pending; no release ZIP.

The desktop/mobile journal regression also passed: minimal creation, detail tabs, immutable strategy capture, transaction links, private thumbnails, three uploads, image actions, dirty guards, list context, Back and deep links.

## Journal-to-fundamentals shortcut — October 6, 2026

73 unit and 130 disposable WordPress/MySQL integration checks pass. The new required-field regression verifies missing snapshot/dataset fields return 400 without PHP warnings. Extended fundamental browser checks pass owner/viewer shortcuts, dirty cancellation/discard, Back/reload, exact metrics, disabled refresh, preserved uncertain identity and responsive tables. Desktop/mobile journal, all 11 real HTTP media checks and all eight admin-shell sections at 360/768/1440 pass. Coding standards, PHP/JavaScript syntax and six REST URL checks pass. One initial journal workspace load timed out; isolated startup returned no browser error and the complete retry passed. No live provider/AI requests, schema changes or release ZIP.

## Weekly fundamental schedules — October 6, 2026

74 unit / 135 disposable WordPress/MySQL integration checks pass. Clock fixtures cover strict future slots, weekdays and New York DST. Schema-12 repair preserves snapshots; enrollment tests cover owner-only routes, dataset isolation, optimistic revisions, foreign mappings, provider restrictions and audit rollback. Worker tests cover queue deduplication, stable completed slot reuse, late/future suppression, disable/revocation/mapping changes, recovery scan and preserved data on deactivation. The fundamental browser fixture passes weekly enrollment/disable, dirty dataset/section guards, owner/viewer history, journal shortcuts, Back/reload, exact metrics, uncertain identity and responsive tables. Desktop/mobile journal, all 11 real HTTP media checks and all eight admin-shell sections at 360/768/1440 pass. Coding standards, PHP/JavaScript syntax and six REST URL checks pass. Synthetic fixtures/intercepted provider responses only; no live provider/AI or production operations. No new release ZIP.

### AI Settings preparation controls

The schema-13 integration suite now includes owner-only Settings API checks for disabled policy saves, decimal/string types, required/unknown fields, revision conflicts, shared-controller restrictions, private projections and separate workspace consent. A fresh retained `fixture_ais_*` table prefix generates `tmp/ai-settings-browser-fixtures.json`; no synthetic history is purged.

Run `node tests/ai-settings-browser.cjs` against the disposable HTTP adapter after the integration suite. The adapter's local-only `tgit_ai_fixture=1` flag selects that validated synthetic prefix for preview and REST requests. It is unavailable outside the CLI disposable development server. Browser checks cover actual owner saves, viewer exclusion, unsaved field preservation, cancel/discard, reload, zero-budget pause and 360/768/1440 layouts. No credentials or paid transport are involved.

AI evidence preview tests are included in the unit and full WordPress integration suites. They use saved synthetic snapshots and journals, assert unchanged financial/spending/audit history on preview reads, and restore deliberate damaged-journal fixture mutations. No provider or AI request is made.

## Immutable evidence approval — October 7, 2026

93 unit / 156 disposable WordPress/MySQL checks pass. Schema-14 fixtures cover upgrading from schema 13 and repeated activation without losing saved evidence. Approval fixtures verify exact canonical JSON/fingerprints, sorted-selection retries, actor-bound identities, stale or changed evidence rejection, scoped owner reads and immutable history after journal changes. An audit fault rolls back both approval and retry records; a safe retry succeeds after the fault is removed. Damaged stored evidence is rejected and fixture bytes are restored. No budget reservation or external request occurs.

Schema-14 browser/HTTP regressions pass for AI Settings, market-data Settings, fundamentals/weekly schedules/journal shortcuts, desktop/mobile journal workflows, all eleven real HTTP private-image checks and all eight admin sections at 360/768/1440px. No live provider/AI calls were used.

## Owner evidence REST and Research controls — October 7, 2026

93 unit / 157 disposable WordPress/MySQL integration checks pass. REST fixtures verify owner-only preview/approval/read, private no-store headers, exact thesis exclusions, required/unknown fields, integer paired revisions, fingerprint conflicts, immutable retries/reads and foreign-workspace rejection without spending records. `node tests/ai-evidence-browser.cjs` passes real owner/viewer controls, selected source/thesis preview, selection invalidation, successful immutable approval/reload, responsive cards and a lost server response followed by reload/retry with the original key. The disposable HTTP adapter explicitly allows only the new local script alongside existing assets; no production bypass or external API request is added.

Existing browser/HTTP regressions pass with the new controls loaded: AI Settings, market-data Settings, fundamentals/schedules/journal shortcuts, desktop/mobile journals, eleven real HTTP media checks and all eight responsive admin sections. Expanded REST checks also cover current journal revision changes, conflict on a new stale approval and unchanged original retry/read. Local synthetic fixtures only.

## Immutable AI review storage — October 7, 2026

96 unit / 162 disposable WordPress/MySQL integration checks pass. Pure output tests cover exact identity, canonical citation order, unknown/duplicate/foreign citations, UTF-8/control characters, byte limits, escaped JSON expansion and unsupported fields. Fresh retained `fixture_rev_*` prefixes isolate synthetic settled requests, approvals and output. Integration covers schema-14 migration/repetition, unchanged spending/ledger, immutable retries, request/evidence mismatch, cursor bounds, policy disable and author revocation, viewer/revoked/foreign denial, audit rollback with safe retry and restored damaged-output fixtures. No network transport or real model output is used. Coding standards, PHP/JavaScript syntax and six REST URL checks pass.

Schema-15 browser regressions pass for evidence approval/reload/lost-response recovery, AI Settings, market-data Settings, fundamentals/schedules/journal shortcuts, desktop/mobile journal workflows, all eleven real HTTP media checks and all eight admin sections at 360/768/1440px. Synthetic local fixtures only.

## Owner saved-review REST/UI — October 7, 2026

96 unit / 163 disposable WordPress/MySQL integration checks pass. Read-only routes verify owner access, private no-store responses, cursor bounds, foreign workspace/asset isolation and no POST write endpoints. The new `tests/ai-review-browser.cjs` fixture uses a retained validated `fixture_rev_*` prefix, two synthetic settled reviews and actual one-row REST pages. It verifies full cursor traversal, plain-text rendering of HTML-like output without DOM execution, original approved provenance, 360/768/1440 layouts, reload/failed-detail recovery, empty stock history, viewer exclusion and delayed detail suppression after a workspace switch. The local-only adapter flag remains behind CLI-server/disposable/development/loopback guards; no production bypass or external request exists. Coding standards, PHP/JavaScript syntax and six REST URL checks pass.

Existing browser regressions also pass with the new review-history controls loaded: evidence approval, AI Settings, market-data Settings, fundamentals/schedules/journal shortcuts, desktop/mobile journals, all eleven real HTTP private-media checks and all eight responsive admin sections. All provider/AI inputs remain synthetic or locally intercepted.


## Approval-bound admission and catalog dispatch � October 7, 2026

96 unit / 165 disposable WordPress/MySQL integration checks pass. Schema 16 verifies repeatable activation and unchanged legacy requests with NULL approval identity. Bound fixtures cover missing/expired/rotated-credential/changed-price catalog evidence, unchanged allowance after denied admission, identical-fingerprint approval substitution, bound-to-legacy retry conflicts, missing catalog at dispatch, single claims, settled review approval substitution and current owner revocation. Existing consent, expiry, policy revision, cap, month, audit rollback and shared spending race fixtures remain green. No transport or live API calls were used. Coding standards, PHP/JavaScript syntax and six REST URL checks pass.

Schema-16 validation: 96 unit / 165 disposable WordPress/MySQL integration checks, coding standards, PHP/JavaScript syntax and six REST URL tests pass. Browser regressions pass for saved reviews, evidence approval, AI Settings, desktop/mobile journals, all eleven authenticated private-media HTTP checks and all eight admin sections at 360/768/1440px. No live provider/AI call, production change or release ZIP was made.


## Atomic AI response receipts � October 7, 2026

102 unit / 171 disposable WordPress/MySQL integration checks pass. New pure tests cover exact response/model identity, final status, standard text-only billing, cached and reasoning totals, refusal/incomplete/invalid output, unsupported usage, source citations, duplicate escaped/nested JSON keys and bounded malformed bodies. Server receipt fixtures cover exactly-once settlement/publication, immutable retries, quarantined billable failures, unknown-cost holds and same-response recovery, generic-reconciliation bypass denial, audit failure after the settlement event with complete rollback, restored damaged receipt data, overrun blocking, token-bound quarantine within the budget, schema-16 upgrade/repetition and workspace/original-owner isolation. No live OpenAI/model output or transport was used. Coding standards, PHP/JavaScript syntax and six REST URL checks pass.

Schema-17 browser/HTTP regressions pass for saved reviews, evidence preview/approval/recovery, AI Settings, desktop/mobile journals, all eleven authenticated private-media checks and all eight admin sections at 360/768/1440px. 102 unit / 171 disposable WordPress/MySQL integration checks, coding standards, PHP/JavaScript syntax and six REST URL checks pass. No live provider/OpenAI calls, production changes or release ZIP were made.

Prompt-contract validation (October 7, 2026): 105 unit and 171 disposable WordPress/MySQL integration checks pass, along with coding standards, PHP/JavaScript syntax and six REST URL checks. Three new deterministic tests cover exact approved bytes, instruction/schema inclusion, fingerprints, output bounds, thesis-as-data and invalid provenance. No UI, REST route, database schema or browser behavior changed; previous schema-17 browser/media evidence remains applicable, and browsers were not rerun for this pure domain slice. No live token counting, provider/AI calls, production changes or ZIP.

## 0.27.0-dev test package - October 7, 2026

Created output/ig-trading-journal-0.27.0-dev-test.zip (259194 bytes, 90 files), with stable ig-trading-journal/ root and all 17 SQL migrations. SHA-256: e7209eae3f180d0ac37f9da7288167bc77e8f53a678f20acf81d59ff56c40ec2. This is a development test artifact, not a production release; the latest production-designated ZIP remains 0.26.0. AI generation remains unavailable. Runtime files were not changed for packaging. Tests, temporary fixtures, credentials, vendor dependencies and the personal setup document are excluded. Archive integrity, exact packaged-file bytes, safe extraction and packaged PHP/JavaScript syntax pass. Actual extracted-package fresh activation, workspace creation, repeat installation and isolated schema-8 to schema-17 upgrade pass.

105 unit and 171 disposable WordPress/MySQL integration checks, coding standards, PHP/JavaScript syntax and six REST URL checks pass. Browser checks pass for AI review history, evidence approval, AI Settings, market-data Settings, fundamentals/schedules/journal shortcuts, cached stock valuations, opening cash/lots with FIFO sale, watchlists/research/calculators, desktop/mobile reports and journals. All 11 real HTTP private-image checks and all eight admin sections at 360/768/1440px pass. The first integration attempts ran before the slow disposable database startup completed; the complete rerun passed after readiness. No real provider/AI requests, production changes or deployment occurred. Actual host/minimum-version/MariaDB, live entitlement, accessibility, performance and backup/restore acceptance remain unverified. See test-build-0.27.0-dev.md for staging installation instructions.

0.27.0-dev.1 validation: 105 unit and 171 disposable WordPress/MySQL integration checks, coding standards, PHP/JavaScript syntax and six REST URL checks pass. Browser regressions pass for all eight admin sections at 360/768/1440px, shortcut focus/overflow, native schedule Off/missing-mapping states, weekly enrollment and dirty guards, AI Settings including shortcut preservation of unsaved budget/consent, evidence approval/recovery, saved reviews, provider settings, watchlists/research/calculators and desktop/mobile journals. All 11 real HTTP private-image checks pass. A variable-scope error in the new browser assertion was corrected before the complete rerun passed; no runtime fix was needed for that test-script failure.

The verified test artifact is output/ig-trading-journal-0.27.0-dev.1-test.zip: 90 files, 17 migrations, 262512 bytes; SHA-256 05d9f14ea2cd2113c6a65eabe252f20823f9d59861618abf710fff6523a77738. Archive integrity, exact packaged bytes, safe extraction, extracted runtime syntax, actual fresh/repeat activation, workspace creation and isolated schema-8-to-17 upgrade pass. The package excludes tests, temporary data, credentials and the personal portfolio document. Source/runtime is 0.27.0-dev.1/schema 17; no new SQL, real provider/OpenAI call or production operation occurred. Host compatibility, live entitlement, independent accessibility/performance and consistent backup/restore acceptance remain outstanding. See test-build-0.27.0-dev.1.md and the updated user-guide.md.

## Schema-18 execution manifest validation - October 7, 2026

108 unit / 176 disposable WordPress/MySQL integration checks, coding standards, PHP/JavaScript syntax and six REST URL checks pass. Pure tests cover complete input projection/full-request binding, exact credential/model/pricing identity, five-minute UTC freshness, malformed/duplicate/extra response fields and conservative full-output cost. Integration tests cover atomic manifest/reservation/audit persistence, normalized identical retries, contradictory retry rejection, required-plan claims, stale-count hold preservation, bounds/credential admission rejection, damaged manifest quarantine, audit fault rollback, workspace isolation and schema-17 repeat activation preserving manifests and legacy unbound history. One initial assertion expected validation rather than conflict for mismatched reservation tokens; the assertion was corrected and the complete rerun passed. No live external requests.

Schema-18 browser/HTTP regressions pass for saved AI review history, evidence approval/recovery, AI Settings, desktop/mobile journals, all eleven authenticated private-media checks and all eight admin sections at 360/768/1440px. No live provider/OpenAI calls or new ZIP.

## Local OpenAI credential setup - October 7, 2026

110 unit / 176 disposable WordPress/MySQL integration checks, coding standards, PHP/JavaScript syntax and six REST URL checks pass. Missing configuration and isolated synthetic valid/invalid key fixtures verify no secret/digest output, no falsely verified access and no enabled processing. The external sender was blocked by automatic approval review and was not written; all checks remain local. No live provider/OpenAI call, schema change or new ZIP. Browser setup-card/layout results follow below.

Browser checks pass for AI Settings owner/viewer access, the new credential-preparation accordion and redacted missing-key status, separate consent, unsaved preservation and responsive cards; all eight admin sections pass at 360/768/1440px. No live external requests. Unchanged journal/private-media surfaces retain the preceding schema-18 regression evidence; their HTTP/browser fixtures were not rerun for this local setup-card slice.

## Disabled OpenAI sender validation - October 7, 2026

111 unit / 181 disposable WordPress/MySQL integration checks, coding standards, PHP/JavaScript syntax and six REST URL checks pass. Every HTTP attempt is intercepted with synthetic responses and a fixed endpoint assertion; no real API call was made. Fixtures verify exact approved payload and header/size/timeout/TLS bounds, default-off hold preservation, overlapping and completed retries without resending, timeout/exception/429/malformed-response holds, stale-count/catalog/workspace pre-HTTP rejection, and audit-trigger failure after delivery that retains a dispatched hold and recovers from verified receipt evidence. Initial formatting/empty-catch violations were fixed before the final full suite passed. Browser/HTTP journal/media evidence is unchanged from the preceding schema-18/local-setup runs; no UI or REST surface changed and those browser fixtures were not rerun. No schema change, new SQL or ZIP.

## Complete-input count transport - October 7, 2026

Added a locked, workspace-scoped count preflight for exact saved approvals, original owner authorization, consent/enrollment, credential-bound model/pricing evidence and monthly-budget feasibility. The one-input-token/full-output lower bound is only a feasibility check; actual complete input is measured before a reservation can be created. The disabled internal count helper sends the exact approved projection to the fixed HTTPS input_tokens endpoint, with bounded bytes/time, certificate verification and no redirects/cookies. All context and budget gates are checked again after HTTP; strict receipt verification binds the resulting count to the full execution manifest.

Counting additionally requires trusted dated zero-charge access evidence for the exact credential/model. Official counting documentation does not establish that counting is free; no production evidence is supplied. Unknown/paid counting is blocked pending a separate spending lifecycle. There is no browser route, generation button, automatic retry or persistent count-attempt history. No real API call, production key/data, schema migration or ZIP. Source remains schema 18; latest packaged test ZIP remains schema 17.

112 unit and 185 disposable WordPress/MySQL integration checks passed, plus coding standards, all PHP/JavaScript syntax and six REST URL checks. New intercepted count fixtures cover exact envelopes, unchanged spending before reservation, usable verified manifests, missing cost/catalog/consent denial before HTTP, in-flight revocation, network/HTTP/duplicate-count failure and insufficient budget. Unchanged browser/private-media surfaces retain preceding regression evidence and were not rerun for this internal-only slice. See ai-count-transport.md. Next: trusted model/pricing/count-cost acquisition and explicit owner generation/publication controls.

## Trusted AI server evidence source - October 7, 2026

Added AiConfiguration to load server-only model and counting evidence arrays for the exact selected model. Each load validates current credential binding, model access/pricing expiry and zero-charge counting policy. Missing records, aliases/fallbacks, future or expired dates, unknown/paid count costs and rotated keys fail closed. Unselected expired records do not block a valid selected model. No model/rate default, authenticated discovery, verification claim, public status/route, processing enablement, network call or database mutation is added. This is a trusted configuration source, not automatic acquisition of account/pricing facts.

116 unit and 185 disposable WordPress/MySQL integration checks pass, plus coding standards, all PHP/JavaScript syntax and six REST URL tests. Isolated-process fixtures cover server constants using synthetic keys and dated records, successful exact selection, rotated keys, missing/malformed metadata and unavailable setup. Full regression continues to intercept provider/OpenAI HTTP. No real API credentials/data, production change, SQL or new ZIP. Source remains schema 18 and the packaged test ZIP remains schema 17. Unchanged browser/private-media surfaces retain previous regression evidence; not rerun for this internal loader. The owned disposable database is stopped with fixture data retained after verification.

Updated roadmap/user guide and ai-server-evidence.md with server evidence fields, expiry, private-file handling and the distinction between reviewed records and actual verification. Next: automatic trusted evidence acquisition/verification, explicit owner generation/publication controls and operational recovery. AI summary generation remains unavailable.

## Internal explicit AI generation coordinator - October 7, 2026

Added AiGeneration to connect fresh trusted selected-model evidence, approved complete-input counting, atomic verified reservation and once-claimed Responses delivery. AiSpending now coordinates workspace/operation-key attempts with a non-waiting named lock, held over HTTP without holding a database transaction. Same-connection re-entry and separate-connection overlap are rejected before a second count. Existing request retries return only saved state/request ID; changed actor/approval/output context conflicts. Abandoned reservations and uncertain/dispatched requests never automatically recount or send.

Publication remains separate and uses only the stored completed settled receipt. Counting failure creates no reservation and releases coordination; a later explicit attempt can count again. Persistent pre-reservation attempts and charged/unknown counting remain unsupported. No owner route, generation/enable button, schedule or automatic publication is added. All server and evidence gates remain required and default off. No real provider/OpenAI request, production credential/data, schema migration or ZIP. Source remains schema 18; packaged test ZIP remains schema 17.

116 unit and 191 disposable WordPress/MySQL integration checks passed, plus coding standards, PHP/JavaScript syntax and six REST URL checks. New intercepted fixtures cover default-off operation, exact count/send envelopes, settlement with no automatic review publication, immutable stored-receipt publication, same/separate-connection overlap, uncertain holds/no resend, conflicting retries, abandoned reservations, invalid identity/output, foreign approval and revoked consent. Initial coding standards found two array formatting issues; these were corrected and final checks passed. Browser/private-media surfaces are unchanged and retain preceding regression evidence; not rerun for this internal coordinator. Owned disposable database stopped with data retained after verification.

Updated roadmap/user guide and ai-generation-coordinator.md. Next: genuine model/pricing/count-cost acquisition/verification, owner enable/generation/status/publication controls and operational recovery. AI summary generation remains unavailable in admin.

## Owner AI readiness and saved request activity - October 7, 2026

Settings now displays saved credential/model/pricing/count-cost/server-switch/consent/budget preparation and an owner-only saved-request activity accordion. Readiness validates local reviewed records without network access or authenticated discovery claims; processing remains unavailable. A new GET ai-requests route uses existing validated cursor limits, workspace-scoped request/event relationships and a fixed metadata projection excluding keys, digests, actor IDs, manifests, prompts, usage JSON and provider bodies. The shared collection loads all workspace pages before local search. History reload preserves unsaved forms, clears failed loads and rejects stale workspace responses. Reserved, claimed, uncertain, settled, cancelled and legacy states remain distinct; settlement does not imply publication.

116 unit and 191 disposable WordPress/MySQL integration checks passed, plus coding standards, PHP/JavaScript syntax and six REST URL checks. Extended fixtures cover independent redacted model/count readiness, rotated/missing server evidence, exact history projection, cursor pagination/bounds, foreign-workspace isolation and viewer denial. Real local browser checks pass for readiness, cancelled/legacy history, history error/reload recovery, owner/viewer access, unsaved preservation, consent, pause and responsive cards. All eight admin screens pass at 360/768/1440px with no page overflow. The installed local browser runtime matches the locked tests/browser version 1.63.0. Initial browser invocation lacked its runtime path; the existing matching runtime was selected and final fixtures passed. One PHP line-ending style issue was repaired before final coding standards passed.

Source version 0.27.0-dev.2 refreshes browser assets; schema remains 18, with no new SQL or ZIP. Existing packaged test ZIP remains 0.27.0-dev.1/schema17. No live provider/OpenAI request, production credentials/data or deployed change. Browser fixtures/cookies/logs remain temporary and uncommitted. Unchanged private-media/fill surfaces retain previous HTTP/browser evidence and were not rerun for this read-only Settings slice. Owned HTTP/database fixture processes stopped after validation; database data retained. Initial workspace-read approval review timed out and the standard runner failed to start; the permitted read-only retry succeeded, so no action remains blocked.

Updated user guide, requirements, coverage and ai-readiness-activity.md. Next: genuine evidence acquisition/verification, explicit owner enable/generation/publication controls and guarded recovery. Admin summary generation remains unavailable.

## Owner stored-response review publication - October 8, 2026

Added Save as review to eligible saved AI request rows. Server eligibility validates settled, completed, usable stored output and original-owner evidence; existing reviews show their saved ID. The owner-only empty-body publish route rejects client output and foreign/other-authorizer requests, loads only immutable stored receipts and uses the existing audited immutable command identity. No OpenAI/provider call, token count, delivery retry, spending change or posted-history mutation is added. Exact publication retries reuse one review. Missing result fields fail closed; refused, invalid, pending and overrun output grant no action.

The UI keeps the shared write guard through saved-history refresh, preserves unsaved settings and disables uncertain publication until an explicit reload. Reload resolves a saved ID or still-eligible response; no delivery is retried. The guide explains where to read the saved review in Research and distinguishes private saving from generating new output or sharing publicly.

116 unit and 196 disposable WordPress/MySQL integration checks pass, with coding standards, PHP/JavaScript syntax and six REST URL checks. New publication fixtures intercept every external HTTP request and verify immutable retries, unchanged request/event/receipt/ledger facts, input rejection, workspace isolation, viewer/other-owner denial, unusable-output exclusion and audit-trigger rollback followed by safe retry. The first suite launch preceded database readiness and failed to connect; it was rerun after ready confirmation and passed fully. Real loopback browser fixtures pass publication success, uncertain-outcome/reload recovery, immutable retry, unsaved preservation and 360/768/1440px layouts. AI Settings owner/viewer/readiness/activity/reload regressions pass, and all eight admin sections pass responsive/no-overflow checks. Unchanged media/fill surfaces retain previous dedicated HTTP/browser evidence; no new media logic.

Source version 0.27.0-dev.3 refreshes assets; schema remains 18. No new SQL or install ZIP; latest packaged ZIP remains 0.27.0-dev.1/schema17. No real API credentials/data/calls or production change. Temporary fixtures/cookies/logs remain uncommitted. Owned HTTP/database fixture processes stopped after verification; database data retained. Updated requirements, coverage, guide and ai-stored-publication.md.

Remaining: genuine model/pricing/count-cost verification, owner enable/generation controls and guarded uncertain-delivery recovery. Admin new-summary generation remains unavailable; server processing stays off.


October 8, 2026 unsent cancellation verification: 116 unit and 199 disposable WordPress/database checks passed twice; PHP syntax, composer check-cs, JavaScript syntax and 6 REST URL checks passed. Browser publication/cancellation, unclear result recovery, allowance refresh and unsaved preservation passed at 360/768/1440px. Existing AI Settings and all eight admin sections passed. Initial browser assertion expected 0 rather than the correct 0.00 currency format; corrected and rerun with fresh fixtures. No live provider/AI calls, credentials or production data used. Full private-media dedicated fixtures were unchanged. No new production release ZIP.


October 8, 2026 generation preflight verification: 116 unit and 202 disposable WordPress/database checks passed. New checks prove scoped original-owner flags, viewer/foreign denial, paused-budget blockers, corrupted-evidence denial, redacted projection and unchanged spending/evidence/receipt rows with external HTTP denied. PHP/JavaScript syntax, composer check-cs and 6 REST URL checks passed. Real local browser approved-evidence setup check, approval/reload and owner/viewer workflow passed; all eight admin sections passed at 360/768/1440px. No live API calls or production credentials/data; dedicated private-media tests unchanged. Source 0.27.0-dev.5/schema18, no SQL or new release ZIP.


October 8, 2026 read-only operation recovery verification: 116 unit and 205 disposable WordPress/database checks passed. New original-owner UUID lookup checks cover reserved/dispatched/uncertain/settled/cancelled states, immutable retries, no reservation for missing operations, invalid UUIDs, foreign scope, viewer/other-owner denial and in_progress coordination. External HTTP was denied and request/event/receipt/manifest/config rows remained unchanged by lookup. PHP and JavaScript syntax, composer check-cs and 6 REST URL checks passed. UI/private-media surfaces unchanged; preceding browser regression evidence retained. No live calls, production keys/data, SQL or ZIP. Live-capable enable/generation edits were rejected before execution by automatic approval review and were not applied; explicit proposal awaits owner approval.

### October 8, 2026 - approved owner summary generation (0.27.0-dev.6)

Passed 116 unit checks and 211 disposable WordPress/MySQL integration checks, including guarded owner generation, admission-time policy binding, failed-count recovery and uncertain-send protection. PHP syntax, JavaScript syntax, Composer coding standards and the six REST URL checks passed. Browser fixtures passed for the new generation workflow, AI Settings, evidence approval/preflight, publication, cancellation and all eight admin layouts at 360, 768 and 1440 pixels. Two obsolete Settings text assertions were updated to reflect the implemented controls and passed on rerun.

All generation/count requests were intercepted using synthetic server evidence and responses; no real credentials, paid API requests or portfolio transmission were used. Production server evidence and hosting verification remain required. No SQL change or new installation ZIP was produced.

### October 8, 2026 - 0.27.0-dev.6 installation test artifact

Created output/ig-trading-journal-0.27.0-dev.6-test.zip: 291503 bytes, 103 files, stable ig-trading-journal/ root and all 18 SQL migrations. SHA-256: 8f0ef14f130b2e324f8bc8f5d383585363e5a53f2a95b508137c43e17661daa7. Archive integrity, exact source bytes, safe extraction and extracted PHP/JavaScript syntax passed. Actual extracted-package fresh/repeat installation, workspace creation and direct isolated schema-8/schema-17 upgrades to schema 18 passed. The initial activation attempt preceded disposable database readiness; after startup, both activation harnesses passed. Unit tests (116), Composer coding standards and REST URL checks (6) passed again. The preceding 211 integration checks and browser verification remain the current source regression evidence; packaging changes no runtime source.

This is a development test artifact, not production acceptance. Includes the current summary setup/usage guide; excludes tests, temporary fixtures, credentials, dependencies and the personal portfolio document. No real provider/OpenAI requests or production changes. Owned disposable database stopped with fixture data retained. No sequential packages/manual SQL required; back up database/private media together and explicitly reactivate for schema upgrades.

### October 9, 2026 - asset ticker/company typeahead (0.27.0-dev.7)

Passed 116 unit and 214 disposable WordPress/MySQL integration checks, including catalogue normalization, bounded fixed HTTPS transport, cache/failure behavior and explicit workspace access. Composer coding standards, all PHP/JavaScript syntax and six REST URL checks passed. Asset-search browser coverage passed actual company-name selection of a different saved asset ID, unmatched validation, manual edits during delayed lookup, crypto/manual fallback, workspace reset, hidden controls, outage handling and 360/768/1440 layouts. All eight admin layouts, desktop/mobile journal workflows and all eleven authenticated private-media HTTP checks passed.

The initial disposable HTTP service used an overly broad document root and correctly failed the private-storage guard; the corrected test web root passed all media checks. Search fixtures were updated to include saved stock assets and follow New transaction/reload navigation. A full-label matching edge case was fixed and the strengthened selection regression passed. Final diff review caught a line-ending-sensitive documentation replacement; complete original guide contents were restored, intended paragraphs updated, and the unpublished superseded artifact retained in tmp. The corrected ZIP explicitly verifies full guide/README preservation.

Verified output/ig-trading-journal-0.27.0-dev.7-test.zip: 106 files, 18 migrations, 296273 bytes; SHA-256 be78e02622eca8f5fd8a2285ca41b991c0155b1e010af4f4222cb41d2104875d. Archive integrity, exact packaged bytes, safe extraction, full documentation and extracted PHP/JavaScript syntax passed. Corrected extracted package fresh/repeat installation, workspace creation and isolated schema-17 to schema-18 upgrade passed. No schema change, sequential packages or manual SQL required for schema-18 users. This remains a development test build. The owner public file was read only and yielded 10434 usable suggestions; tests used synthetic catalogues and no live provider/OpenAI calls. No production change. Owned test services stopped with fixture data retained.


### October 9, 2026 - crypto asset typeahead (0.27.0-dev.8)

Passed 116 unit and 217 disposable WordPress/MySQL integration checks, including separate bounded coin transport/cache, duplicate-symbol normalization and explicit membership. Composer coding standards, PHP/JavaScript syntax and six REST URL checks passed. Synthetic crypto and stock browser workflows passed coin IDs, stock/crypto name separation, actual saved IDs, unchanged manual exchange/currency, outage/manual fallback and responsive controls. All eight admin layouts at 360/768/1440 and desktop/mobile journal workflows passed. The initial coin switch fixture retained focus programmatically; moving focus through the asset-class field to reflect real interaction passed.

Verified output/ig-trading-journal-0.27.0-dev.8-test.zip: 107 files, all 18 migrations, 298415 bytes; SHA-256 02eb3c11b2b9970081c69bb720cdafc4598dee70e2f84a15a8d0db83b82aa3ce. Archive integrity, exact source bytes, safe extraction, complete guide/README preservation and extracted PHP/JavaScript syntax passed. Schema remains 18. The public list was read only; 4387 valid suggestions were normalized from 4388 records. Tests use synthetic catalogues, no provider/OpenAI requests or production changes. Production acceptance remains outstanding.

All eleven real authenticated private-media HTTP checks passed. Extracted ZIP fresh/repeat activation, workspace creation and isolated schema-17 to schema-18 upgrade passed. Owned local test services stopped with fixture data retained.
