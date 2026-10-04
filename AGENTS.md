# Implementation contracts

- Follow `docs/TG_Investment_Tracker_PRD.pdf`; requirement coverage is in `docs/requirements.md`.
- PHP 8.1+ with BCMath; MySQL 8.0+/MariaDB 10.6+, InnoDB and utf8mb4. Confirmed WordPress hosting: WordPress 7.1.2, PHP 8.1.2 (Ubuntu package), Apache 2.4.52/apache2handler, MySQL 8.0.46, wp_ prefix, plain permalinks, Europe/London site timezone. Production currently uses HTTP; plugin API requires HTTPS. BCMath remains unconfirmed.
- Financial values are decimal strings. Domain code in `src/Domain` must not depend on WordPress globals or use floats.
- Owner-confirmed new-workspace defaults: USD base currency and America/New_York timezone (EST/EDT according to date).
- Every customer query and relationship must be workspace scoped. Site administrators need explicit membership.
- Posted history is immutable. Do not add editing or backdating without replay and revision contracts.
- SQL belongs in separate `docs/*.sql` files. `{{prefix}}` is replaced with the configured WordPress table prefix by the installer.
- Use sanitized deterministic fixtures only. No production data, credentials, external purchases, deployments, or purge.
- Run `php tests/run.php`, PHP syntax checks, `composer check-cs`, `node tests/rest-url.cjs`, and JavaScript syntax checks. Full WordPress/database integration gates must pass before a production release.
- Deactivation/uninstall preserve data. Schema changes require documented backup and forward repair.

- Owner priority order: trade journals/strategies/multiple private images; remaining ledger revision/replay/opening balances; watchlists/research/calculators/reports; spreadsheet import/reconciliation/export/restore testing.
- Schema version 6 adds docs/006-opening-basis-resolutions.sql. Keep all six bundled SQL files in production packaging; reactivate explicitly after a backup to upgrade version 1 through 5.
- Cash and unlinked security corrections create an immutable replacement linked to the source, retain posted facts, and replay later cash and FIFO. Historical cash and security insertions use dedicated operations. Trade-linked fill corrections remain blocked pending journal/link revision handling.
- Opening cash and pre-existing asset lots require source provenance. Ordinary opening entry precedes posting; reviewed retroactive entries replay later cash and FIFO. Unresolved basis blocks sales until documented append-only resolution; do not substitute a fabricated zero.
- Journal edits are non-financial and separately authorized. Strategy versions are immutable; transaction-to-trade links live outside posted financial rows.
- Private images use PHP GD and TGIT_PRIVATE_MEDIA_DIR outside every public root. Never use WordPress public uploads/attachments as private storage. Owner quota policy must be saved before reservations. Keep normalized originals only.
- Run the journal/media integration, real HTTP and desktop/mobile browser fixtures on the disposable site with GD and private test storage. Browser tools are locked under tests/browser; never commit temporary cookies, credentials, media bytes or screenshots.
