# Dependency inventory

Production: PHP 8.1+ including BCMath, WordPress, MySQL/MariaDB. Private image uploads also require PHP GD with JPEG/PNG/WebP support and a configured private local directory. The plugin has no runtime Composer or Node dependency and makes no provider, AI or billing calls.

Development-only dependencies are pinned in `composer.lock`:

| Package | Version | License |
| --- | --- | --- |
| wp-coding-standards/wpcs | 3.4.1 | MIT |
| squizlabs/php_codesniffer | 3.13.6 | BSD-3-Clause |
| phpcsstandards/phpcsutils | 1.2.3 | LGPL-3.0-or-later |
| phpcsstandards/phpcsextra | 1.5.1 | LGPL-3.0-or-later |
| dealerdirect/phpcodesniffer-composer-installer | 1.2.1 | MIT |

Keep upstream license files with any redistributed development tooling. Omit `vendor`, `tmp`, tests and development tools from the WordPress package. Include `src`, `includes`, `assets`, plugin bootstrap and all four bundled SQL files: `docs/001-ledger-foundation.sql`, `docs/002-trade-journal-media.sql`, `docs/003-opening-balances.sql`, and `docs/004-cash-corrections.sql`. All four are required at runtime by the activation installer.

Composer advisory audit on October 2, 2026 reported no advisories for these locked packages. Recheck when updating dependencies; this is not a security review of the product.

Browser tests use playwright-core 1.63.0 (Apache-2.0), locked in tests/browser/package-lock.json. Node 20+ and a locally installed Chrome/Chromium browser are development dependencies only. No browser binary or Node package belongs in the production plugin.
