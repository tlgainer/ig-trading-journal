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
