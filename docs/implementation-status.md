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


## Build 0.3.0: draft revision workflow

Draft editing and promotion are implemented in the service, REST API and WordPress admin. Edits append immutable revisions and require the current revision. Contributors can edit their own drafts; owners/managers can edit and post workspace drafts. Promotion archives the source and links it to a new immutable financial event. Workspace serialization and operation-scoped idempotency prevent duplicate financial effects, including concurrent requests. Failed posting retains an editable source draft.

Validated on PHP 8.1.34 / WordPress 7.1.2 / isolated MySQL 8.0.26: 31 unit checks, 22 integration checks and 6 REST URL checks. New checks cover stale edits/promotions, permission boundaries, retry/conflicting-key behavior, exact 18-place quantities, immutable posted facts, failed promotion rollback, workspace isolation and concurrent promotion. PHP/JavaScript syntax and WPCS checks pass. Admin browser interaction and visual review remain pending.

No schema change or additional SQL is required. Corrections/replay, historical posting and opening balances/lots remain the next ledger work. No deployment was performed.

## Build 0.5.0: documented opening balances

Schema 3 adds `docs/003-opening-balances.sql` for account-scoped opening provenance. Owners and managers can record starting cash or pre-existing asset lots before ordinary posting in that account. Cash changes cash only; lots add FIFO inventory without a cash debit and retain original acquisition dates. A source note is required. Explicit zero basis is known; missing basis remains visibly unresolved and blocks sales of the affected account/asset. All opening entries in an account use one opening date. Posted history remains immutable, and existing posted accounts cannot accept retroactive opening entries without the future replay workflow.

Validation on October 3, 2026 used PHP 8.1.34, WordPress 7.1.2 and isolated MySQL 8.0.26 with `fixture_` prefix. The 31 domain/permission checks, 48 WordPress/MySQL integration checks, six REST URL checks, 11 real HTTP media checks, opening-balance browser workflow and desktop/mobile journal workflow passed. Integration checks cover additive migration, duplicate/retry conflicts, acquisition-date FIFO, known/unknown basis, sale blocking, membership, and audit-failure rollback. The actual production host, MariaDB/minimum-version matrix, broader accessibility/security review and restore testing remain outside this local validation. No production database or deployment was changed.

## Build 0.6.0: cash corrections and replay foundation

Schema 4 adds `docs/004-cash-corrections.sql`. Owners and managers may correct a posted deposit or withdrawal using its expected revision, a reason and a complete replacement. The source amount/action/date and old leg remain as immutable evidence; a correction record and source revision identify the active replacement. Replaying later balances rejects any overdraft and commits the replacement, cash balance, audit and idempotency evidence atomically. Further corrections keep the original same-day order. A dedicated API inserts reviewed historical cash events after validating later balances. A private read-only preview computes projected cash and FIFO gain changes for a proposed backdated transaction without writing it.

Historical buy/sell posting, security corrections, versioned recalculation of realized gains and lot allocations, and retroactive opening entries remain blocked. A preview result is not a posting authorization. No UI redesign is included in this release.

On the disposable PHP 8.1.34/WordPress 7.1.2/MySQL 8.0.26 fixture, 35 unit/domain and 60 integration checks passed, including schema-3-to-4 repair, chain ordering, REST permissions, idempotency, later-overdraft refusal and forced audit-failure rollback. Six REST URL checks, 11 real HTTP media checks, and the opening-balance and desktop/mobile journal browser workflows also passed. PHP/JavaScript syntax and WPCS checks passed. Production-host and broader release gates remain separate. No production migration or deployment was performed.

## Build 0.7.0: security history and versioned replay

Schema 5 adds `docs/005-security-replay.sql`. Historical buys/sells and unlinked posted security corrections now commit after a full account replay validates later cash, units and known FIFO basis. Each replay appends a source fingerprint, calculator version and full lot/gain/allocation projection; posted transaction facts, original legs and older allocation evidence remain unchanged. Normal later posting on a replayed account also appends a projection. Holdings and the admin transaction list use the active calculation; transaction detail exposes the original and current calculation. A same-day corrected acquisition retains its source lot order. Trade-linked fills still require a journal/link revision contract before correction.

Disposable PHP 8.1.34/WordPress 7.1.2/MySQL 8.0.26 fixtures pass 36 unit and 63 integration checks, including schema-4-to-5 repair, idempotency, later posting from projected lots, corrected later gains, immutable source evidence, oversell rejection and REST posting permissions. Six REST URL checks, 11 real HTTP media checks, desktop/mobile journal and opening-balance browser workflows, PHP/JavaScript syntax and WPCS pass. Production migration, host validation, large-history benchmarking, forward-repair exercise and restore testing have not occurred.

## Build 0.8.0: retroactive openings and documented basis resolution

Schema 6 adds `docs/006-opening-basis-resolutions.sql`. A separate retroactive-opening API allows documented cash or pre-existing lots before posted activity after chronological validation; same-day openings sort before ordinary events. The original posted sales and allocations stay intact, with current results recorded in a new replay run. Unknown opening basis can be resolved, including an explicit documented zero, through append-only revisions with optimistic concurrency. Later basis corrections recalculate current gains without changing the original opening or sale rows.

Disposable fixtures pass 37 unit and 68 WordPress/MySQL integration checks, covering schema-5-to-6 repair, idempotency, REST membership, stale basis revisions, later FIFO gains and unchanged posted evidence. Six REST URL checks, 11 real HTTP media checks, opening and desktop/mobile journal browser workflows, PHP/JavaScript syntax and WPCS pass. Production migration and large-history/restore gates remain pending.

## Build 0.8.1: private scenario calculators

The crypto-profit and long-position risk calculators use BCMath decimal strings and do not write portfolio records. Fee treatment and stop-distance sizing are explicit. Authorized members can run scenarios through private REST routes or a Calculators tab. Disposable validation passes 39 unit and 69 WordPress/MySQL integration checks, including fee-aware and fee-free math, invalid inputs, workspace permission boundaries and absence of ledger writes. PHP/JavaScript syntax, WPCS, six REST URL tests and the desktop/mobile journal workflow pass. Schema remains version 6.

## Build 0.9.0: manual watchlists and authored research

Schema 7 adds `docs/007-watchlists-research.sql`. Watchlists hold workspace-identified assets with optional manual buy/sell targets, thesis, tags and user-set status; item updates preserve revision payloads. Research notes are sanitized user-authored text with their own revisions, separate from future provider facts. Owners, managers and contributors may write; all active workspace members may read. REST lists and histories are bounded, and a Research tab supports creating and revising both record types.

The disposable PHP/WordPress/MySQL suite passes 39 unit and 72 integration checks, including schema-6-to-7 repair, idempotency, stale revisions, duplicate assets, cross-workspace denial and text sanitization. A real browser workflow passes watchlist/note creation and revision plus a calculator submission. Production migration, provider data, larger accessibility and restore gates remain pending.


## Build 0.10.0: manual prices/FX, reproducible reports and personal views

Schema 8 adds `docs/008-valuations-reports.sql`: append-only dated observations/corrections, immutable report runs and actor-scoped saved views/revisions. Manual prices match asset quote currency; direct FX means native units in workspace base currency. Explicit source/reason/expiry is required; missing values are null and expired quotes remain labeled stale. Current overview positions show entered quotes. Reports replay active history through as-of and retain exact ledger/observation/trade inputs, selection policy, coverage, filters, calculation version and watermark. Historical FX differentiates acquired-on basis, sale proceeds and current valuation. Report creation never changes financial entries.

Reports cover dated activity/cash, positions and native/base gains, cash-inclusive allocation, lifetime purchase spend, unrealized return, comparable-period economic gain and closed/flat/fully posted strategy groups. Fees and break-even policy are explicit; native currencies are grouped separately. Income is unavailable pending dividend/interest accounting and reconciliation is not claimed. Personal saved views restore filters and require matching revisions to update; another member's views are private.

Local validation: 41 domain and 79 WordPress/MySQL integration checks, including AC02 market value/unrealized gain, AC07 historical base gain, immutable prior reports after corrections, coverage propagation, zero valuation/unknown basis/zero denominator, schema repair, view isolation/revisions and audit rollback/retry. Required Composer coding standards, PHP/JavaScript syntax and six REST URL checks pass. Eleven real HTTP image checks and report desktop/360px browser workflows pass. Existing research, opening and desktop/mobile journal browser workflows pass. No production migration/deployment, actual-host validation, provider integration or restore exercise occurred. Larger histories, imports/reconciliation/exports/restore, income/other financial actions and broader operational gates remain in the original backlog; the owner's requested next focus is UI/UX.


## Build 0.11.0: shared admin UX foundation

The owner confirmed WordPress admin only, no React migration and shared patterns across screens added after the initial review. All eight sections now share system typography, control sizing, scoped styles and desktop/mobile section navigation. URLs preserve existing WordPress parameters while adding only a section name; browser Back restores the section and forms are moved without replacing their input. Workspace creation is collapsed in Settings after onboarding. The malformed confidence separator is corrected.

Cash accounts, watchlist items and authored research notes use one semantic collection component with counts, full-result search over complete authorized cursor-loaded datasets, supported sorts, stable ID tie-breaks, 25/50/100-row pagination, column visibility and compact desktop density. Preferences contain no record/search text and are keyed by actor, workspace and collection. Display decimals remain strings, with redundant zeros removed and a minimum of two fractional digits; stored precision is untouched. Stale research responses are ignored after workspace/selection changes.

Validation: 41 domain and 79 disposable WordPress/MySQL integration checks; six REST URL checks; 11 real HTTP media checks; PHP/JavaScript syntax and WPCS. Desktop/mobile reporting, opening/FIFO and journal/image browser workflows pass. Research create/revise/calculator checks pass with accented text, punctuation and emoji saved/reloaded through the real REST/database flow. New deterministic shell and 101-record collection fixtures verify routing/Back, form preservation, preferences, corrupted preference fallback, full-result search/sort/pagination, numeric string precision and 360/768/1440px overflow. Desktop/mobile research screenshots were visually inspected; form stretching and desktop menu visibility were corrected with regression checks.

This is a reviewable first slice, not full UIR/UXA acceptance. Dedicated trade detail, full server-side collection queries, focused editors, image tables/Trash/upload queue, transactions/settings/report-history adapters, localization, dirty navigation guards, 1,000-trade/100-image benchmarks, 200-percent zoom/screen-reader checks and actual hosting admin/theme checks remain. See `docs/ui-ux-coverage.md`. Schema stays at 8 with no new SQL. No production deployment was performed.
