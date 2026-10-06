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
