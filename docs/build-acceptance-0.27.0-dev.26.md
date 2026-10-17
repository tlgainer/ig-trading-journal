# Build acceptance: 0.27.0-dev.26

Development test artifact: `output/ig-trading-journal-0.27.0-dev.26-test.zip`, schema 28 (no migration added).
SHA-256: `776df94d11e758ef66904cc95bd8a1ba3840fb65f8a132e1d8ba0424440cc5dc`.

Implemented: owner-only immediate refresh for 1-50 explicitly selected saved mapped assets, including assets outside recurring scope. One bounded API batch is admitted through existing shared budget/rate-limit/pause/lease accounting. Selection, actor and reviewed revisions are frozen with the UUID. Explicit outcome checks cannot start work or send, including after a blocked/uncertain request. No automatic retry or worker job is queued. Direct refresh preserves recurring due time, saved scope, pricing policy and ledger facts. Cached diagnostics include selected assets outside recurring inventory using their current mapping.

Verified locally with synthetic data on WordPress 7.1.2, PHP 8.1.34 with BCMath/GD and disposable MySQL 8.0.26:

- 136 unit checks and all 98 focused Tickerbot integration checks passed, including seven new immediate-refresh checks.
- The complete settled-source run passed 136 unit and 316 WordPress/database integration checks.
- Extracted ZIP fresh activation, direct schema 18/27 upgrades, repeat activation, preserved posted facts and transport-off rollback passed; native WordPress plugin activation passed.
- Installed-package genuine wp-admin/REST passed with worker and scheduler explicitly OFF: two selected assets in one request, a single asset in one request, same-key outcome checks across reload without sends, exact displayed holdings/gain, preserved ledger/recurring due time, no queued jobs and responsive controls. Two successful new synthetic provider calls were used; an earlier malformed synthetic batch response was corrected to include its required count metadata, without relaxing parser validation.
- A separate PHP process with worker and scheduler explicitly false completed an immediate request and created no worker event.
- One/group requests, exact selected symbols, unchanged ledger/scope/due time, UUID binding/replay, invalid/foreign/unmapped selections, stale review, busy account, shared pause, transient failure accounting, no automatic retry, and read-only outcome lookup passed.
- Immediate-refresh UI passed full-list search, selections across pages, fixed UUID and reload recovery, mapping/dirty guards, safe rendering, stale workspace fencing, uncertain-response recovery and 360/768/1440 layout. Existing controls, readiness, saved diagnostics and pricing-policy UI fixtures passed.
- Runtime PHP/asset JavaScript syntax, Composer coding standards, six REST URL checks, ten decimal-display checks and synthetic provider transport checks passed.
- All 11 real HTTP private-image checks passed against the installed package. Holdings cursor/search/filter/mobile and cached manual/provider valuation/source-restoration browser regressions also passed.
- ZIP hash/CRC and per-file manifest passed: 148 allowlisted files, all 28 SQL migrations, exact source/extracted bytes. No credentials, private media, disposable fixtures or personal setup notes are packaged.

The earlier large disposable database startup stalled. Its files were preserved; a new isolated data directory was initialized for this acceptance run. No production system was accessed. All provider responses used injected synthetic transports or a loopback-only provider hook; external provider HTTP was blocked.

Live provider coverage/prices, actual host configuration/cron and first-run/24-hour/seven-day observation remain host acceptance tasks. This development build is not production certification. Back up the database and private images together before replacing the plugin. See the updated user guide and `docs/test-build-0.27.0-dev.26.md`.
