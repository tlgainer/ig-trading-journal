# Build 0.2.0 handoff

Implemented: explicit workspaces and memberships, accounts/assets, native-currency deposits/withdrawals/buys/sells, draft records, FIFO quantities/remaining basis/realized gains, immutable initial revisions, private REST lists/posting, audit and workspace-scoped idempotency, and responsive WordPress admin forms/cards.

Validation on October 2, 2026: PHP 8.3.35 with BCMath, WordPress 7.1.2, MySQL 8.0.26, custom `fixture_` table prefix. 31 domain/permission tests and 15 real WordPress/MySQL integration checks passed. Production/test PHP syntax and admin JavaScript syntax passed. WordPress Coding Standards 3.4.1 passes with the documented namespaced-class filename exception and narrow, explained suppressions for prepared repository SQL, local schema reads and uncached schema locks. Composer's locked development dependencies have no reported vulnerability advisories at the time of validation.

Database checks include initial activation and repeated additive installation, persisted AC 01 results, idempotent retries and changed-payload conflicts, foreign-account rejection, oversell/overdraft rollback, drafts, last-owner protection, two-workspace isolation, denied viewer posting and immediate revocation. Additional checks force an audit-write failure after ledger writes and verify total rollback, reject backdating, validate REST money/unknown fields/page limits, deny anonymous and nonmember administrator access, and check private response headers. Two independent PHP workers concurrently sell more units than jointly available: exactly one posts and the second is rejected, leaving the correct balance.

The disposable test MySQL instance used its own local port and files under `tmp`; no existing database was contacted. Installed development tools are pinned in `composer.lock` and excluded from production packaging. Browser/mobile visual and accessibility review, HTTP cookie/application-password CSRF tests, MariaDB and minimum-version CI matrix, deployment and complete PRD release acceptance remain pending.

Known boundaries: no historical correction/backdating or draft promotion, no prices/FX/base totals, no opening lots, journal/images, importer, export, jobs or commercial module. The workspace setup requires explicit base currency/timezone choices. Development defaults and rationale are in ADR 001. Full Phase 1/MVP acceptance and deployment are pending.

No existing production WordPress database was modified. The initial schema is supplied separately in `001-ledger-foundation.sql` and installed on activation. The plugin is not deployed.

## PHP 8.1 hosting compatibility update

The owner confirmed a target of PHP 8.1 and WordPress 7.1.2; the supplied screenshot identifies MySQL 8.0.46, nginx and utf8mb4 but reports PHP 7.4.33 for phpMyAdmin. The plugin header, activation/readiness checks, Composer requirement/lock metadata and installation documentation now require PHP 8.1+ with BCMath. No SQL migration is needed.

Revalidated all 31 unit tests and 15 integration checks on PHP 8.1.34, WordPress 7.1.2 and the isolated local MySQL 8.0.26 server. All PHP source/test syntax checks and JavaScript syntax pass. WordPress's actual host runtime and BCMath remain to be confirmed; the exact MySQL 8.0.46 host has not been accessed or tested. See ADR 002.

Subsequent Site Health screenshots confirm the actual host runtime: PHP 8.1.2, WordPress 7.1.2, Apache 2.4.52, MySQL 8.0.46, wp_ prefix, plain permalinks and Europe/London site timezone. BCMath remains unconfirmed. The site is production with HTTPS disabled, so the current API transport gate will refuse requests until WordPress HTTPS is enabled. No remote-host changes were performed.

Fixed admin REST URL construction for plain permalinks so pagination does not become part of rest_route. Six URL regression cases cover pretty/plain/subdirectory routing and pagination; JavaScript syntax and PHP coding-standard checks pass. No database schema change or extra SQL is needed.

Owner confirmed USD and America/New_York defaults. Workspace creation now applies these server-side when omitted, and the setup form is prefilled from the same constants. Explicit settings remain validated; existing records are unchanged. No SQL migration is needed. New York time handles both EST and EDT.
