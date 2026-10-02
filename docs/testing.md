# Validation evidence and commands

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
- Desktop/mobile form behavior and independent accessibility review; no full E2E release gate is claimed.
- Complete correction/replay/media/import/export/restore acceptance tests as those features land.
- Current minimum WordPress and benchmark/performance hosting validation.

Current executed results are recorded in `docs/implementation-status.md`.

REST URL regressions cover pretty permalinks, plain query routing and subdirectory/index.php installations, including paginated requests. These are URL-construction tests, not claims of remote-host HTTP access or HTTPS verification.
