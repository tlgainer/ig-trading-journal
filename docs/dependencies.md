# Dependency inventory

Production: PHP 8.1+ including BCMath, WordPress, MySQL/MariaDB. The plugin has no runtime Composer or Node dependency and makes no provider, AI or billing calls.

Development-only dependencies are pinned in `composer.lock`:

| Package | Version | License |
| --- | --- | --- |
| wp-coding-standards/wpcs | 3.4.1 | MIT |
| squizlabs/php_codesniffer | 3.13.6 | BSD-3-Clause |
| phpcsstandards/phpcsutils | 1.2.3 | LGPL-3.0-or-later |
| phpcsstandards/phpcsextra | 1.5.1 | LGPL-3.0-or-later |
| dealerdirect/phpcodesniffer-composer-installer | 1.2.1 | MIT |

Keep upstream license files with any redistributed development tooling. Omit `vendor`, `tmp`, tests and development tools from the WordPress package. Include `src`, `includes`, `assets`, plugin bootstrap and `docs/001-ledger-foundation.sql`. The SQL file is required at runtime by the activation installer.

Composer advisory audit on October 2, 2026 reported no advisories for these locked packages. Recheck when updating dependencies; this is not a security review of the product.
