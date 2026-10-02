# PRD implementation coverage

Source: `TG_Investment_Tracker_PRD.pdf`, v1.0, October 2, 2026. This file tracks shipped code separately from release acceptance. Version 0.2.0 begins Phase 1 and the first Phase 2 vertical slice; neither complete phase nor complete MVP acceptance is claimed.

| Requirements | Current implementation | Remaining gate |
| --- | --- | --- |
| ACL 01-04 | Explicit workspace membership; contextual tgit capabilities; owner/manager/contributor/viewer; per-service authorization; revocation; last-owner protection | Delegated posting, invitation flow, support grants, jobs/media/export isolation |
| Section 8/9, OPS 01 | Versioned InnoDB/utf8mb4 tables, configured prefix, decimal storage, scoped indexes; activation installer; schema lock | Full logical schema as modules land; upgrade backup/resume/recovery contracts |
| TX 01-02, CAL 01/03 | Posted buys/sells and cash; draft records with no effects; exact decimal FIFO; fee-inclusive basis; atomic writes; oversell and overdraft rejection | Draft revisions/promotion; all other financial actions |
| TX 07-09 | Posted history is immutable; stable same-day ordering; date precision preserved; historical posting blocked | Corrections/replay, imported identity, opening lots, overdraft policy |
| API 01-04, SEC 01/04 | Authenticated versioned API; role/object checks; JSON decimal strings; CSRF through WP REST authentication; unknown-field rejection; private no-store responses; posting keys; bounded ID pagination | Detailed field errors, filters, stale-edit contracts when edits exist, per-resource limits |
| AUD 01-03 | Append-only application audit events and initial immutable financial payloads; actors, UTC time, correlation and calculation version | Superseding revisions, prior/new role payloads, export/media audit, independent digests |
| PF 01, UX 01/03/04/05 | Native quantities, cash, remaining basis and realized gains; null missing valuation; responsive form/cards and accessible labels/focus | Prices/FX, complete dashboard/reporting, full browser/a11y review |
| OPS 02, PRI 02 | No deactivation purge or uninstall deletion; no telemetry/provider calls | Owner closure, privacy hooks, retention policy/export |
| DEV 01-08 | Architecture boundary, deterministic tests, API/schema/operations docs, requirement tracking and initial ADR | Complete CI matrix, full source discovery and migration signoff |

## Next slices in dependency order

1. Transaction revision/correction and chronological replay, draft promotion, explicit opening balances/lots; concurrency gates.
2. Manual prices/FX with observation provenance, income/transfers/splits with the remaining AC 01-08 fixtures.
3. Trade groups, journal revisions and strategy snapshots; private storage, queue, quota reservation and multiple-image upload/gallery (MED 01-09). No public WordPress Media Library shortcut.
4. Full workbook discovery, controlled import preview/commit, reconciliation and rejection report, then portable exports and restore evidence.
5. Watchlists/research/calculators/analytics, then vendor-neutral entitlements and commercial launch gates.

## Phase 0 decisions still open

- Site Health confirms WordPress 7.1.2, PHP 8.1.2, Apache 2.4.52, MySQL 8.0.46, wp_ and utf8mb4. BCMath remains unconfirmed. Production HTTP must be converted to HTTPS before plugin API use. Plain permalinks are supported; Europe/London is the WordPress site timezone.
- Owner confirmed USD base currency and America/New_York as the new-workspace defaults. Use date-appropriate EST/EDT, not fixed-offset EST. Existing workspace settings are not rewritten.
- Complete source inventory, approved sanitized import fixtures and reconciliation differences. The source sheet is not fetched or altered in this slice.
- Historical completeness/opening lots, private storage location, quotas, retention and backup/restore infrastructure.

Defaults accepted for this development slice: FIFO, long-only stock/ETF/crypto, account-native cash and matching quote currency, no overdrafts, no providers/billing. These do not authorize historical migration or production launch.
