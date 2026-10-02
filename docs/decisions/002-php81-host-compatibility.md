# ADR 002: support the owner's PHP 8.1 runtime

Status: WordPress runtime confirmed by Site Health; BCMath verification and HTTPS configuration pending.

Requirements: NFR 03, DEV 04, OPS 01. Supersedes the provisional PHP 8.3 minimum in ADR 001 and the initial development contracts.

The owner reports PHP 8.1 and WordPress 7.1.2. Their database screenshot identifies MySQL 8.0.46, Ubuntu, nginx and utf8mb4. It also reports PHP 7.4.33 for phpMyAdmin; do not assume this establishes the PHP version used by WordPress. Record the discrepancy and verify the site's runtime before activation.

Subsequent WordPress Site Health screenshots resolve the runtime: PHP 8.1.2-1ubuntu2.26, Apache 2.4.52/apache2handler, WordPress 7.1.2, MySQL 8.0.46. The site has plain permalinks, wp_ prefix and Europe/London timezone. It is production on HTTP. Retain the HTTPS requirement; fix admin URL construction to support rest_route query-based routing instead of requiring pretty permalinks. BCMath is not shown in these screenshots.

The implemented PHP syntax and functions are available in PHP 8.1, including typed properties, string prefix helpers and array_is_list. Lower the plugin header, runtime checks and Composer requirement to PHP 8.1, keeping BCMath mandatory. Verify the actual ledger and WordPress integration suite under PHP 8.1 rather than relying on syntax inspection alone.

No schema change or manual SQL is required. Financial arithmetic, workspace isolation and database precision contracts remain the same. The target MySQL patch version still requires a staging check; local integration uses the available isolated MySQL 8.0.26 runtime.
