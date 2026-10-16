# Build acceptance: 0.27.0-dev.25

Artifact: `output/ig-trading-journal-0.27.0-dev.25-test.zip` (development test build), schema 28.
SHA-256: `3d8d1f9181844122442663fcad047af86cf1f4a13832dc6353c5cff7aafaf3bd`.

Completed on the owned disposable WordPress 7.1.2 / PHP 8.1.34 / MySQL 8.0 fixture, with BCMath and GD. Only deterministic synthetic data was used. No production database, API credential, deployment, or live provider request was used.

- 136 unit and 309 full WordPress/database integration checks passed, including 91 Tickerbot checks.
- Runtime PHP syntax, all asset JavaScript syntax, Composer coding standards, six REST URL checks and ten decimal-display checks passed.
- Tickerbot transport fixtures passed for enabled, disabled and invalid-key configurations.
- All six focused pricing/Tickerbot UI fixtures passed: readiness, mapping, coverage, controls, diagnostics and pricing-policy switching.
- Built ZIP: 147 allowlisted files, one stable plugin folder, all 28 SQL migrations; CRC, per-file manifest and exact source-byte comparison passed. Configuration, private media, credentials, test fixtures and personal setup notes are excluded.
- Extracted-package fresh installation, direct schema 18 and 27 upgrades, repeat installation, preserved posted rows and transport-disabled policy rollback passed. Native WordPress activation passed.
- Genuine installed-plugin admin passed all eight screens at 360/768/1440 widths. Real REST and queued callbacks verified exact holdings values, same-key refresh reuse, no-page manual and recurring callbacks, rollback to Alpha Vantage, owner restrictions and no-store headers. Exactly two new synthetic provider calls were admitted in the successful complete workflow.
- Desktop/mobile journal workflow passed minimal creation, table/detail tabs, snapshots, exact fields, three image uploads/actions, dirty guards, retained context, browser Back and deep links.
- Cached valuation browser regression passed manual/provider selection, exact totals, missing-provider coverage, saved source restoration and responsive layout after the holdings refresh fix.
- All 11 real HTTP private-media checks passed, including multipart retry, authenticated streams, revoked/anonymous denial, trash/restore, quota handling and database-failure cleanup. Holdings cursor/search/filter/exact-decimal/mobile fixture passed.

Local fixture repairs were needed for native WordPress concatenated/static scripts, using the configured same origin, WordPress overwriting a helper variable, respecting the five-minute manual cooldown on repeat runs, and awaiting async browser navigation. A valuation regression also found a genuine UI issue: clearing the holdings DOM detached its cached collection. The corrected build hides old values during refresh and retains the table instance. The superseded candidate is retained locally outside the final delivery path.

Live price/coverage comparison, actual host cron without page visits, your server configuration/permissions, and first-run/24-hour/seven-day observation remain host acceptance tasks. This is not production certification. Back up database and private images together, upload the ZIP, then explicitly deactivate/reactivate; intermediate packages are unnecessary. See `docs/test-build-0.27.0-dev.25.md` and the updated user guide.
