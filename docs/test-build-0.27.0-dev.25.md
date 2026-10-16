# 0.27.0-dev.25 development test build - schema 28

This ZIP includes the complete Tickerbot implementation through holdings valuation, explicit provider switching/rollback, manual queued refresh, scheduling controls and saved progress/cache diagnostics. It is an installable development test build, not a claim of production acceptance.

## Install or upgrade

1. Back up the WordPress database and private image directory together. Preserve your current plugin ZIP and server configuration.
2. Confirm PHP 8.1+ with BCMath and GD, MySQL 8.0+/MariaDB 10.6+, InnoDB and HTTPS. Local tests use PHP 8.1/MySQL 8.0 and a disposable WordPress site; your exact hosting version still needs acceptance.
3. Upload `ig-trading-journal-0.27.0-dev.25-test.zip` through Plugins > Add New > Upload Plugin and replace the installed copy. Its single top-level folder is `ig-trading-journal`, with `ig-trading-journal.php` directly inside. Do not zip/upload the entire repository or install a second versioned outer folder.
4. Explicitly deactivate/reactivate after the backup to apply the bundled additive migrations. A direct upgrade from schema 18 (dev.12) or 27 is supported; intermediate ZIPs are unnecessary. Retain all 28 `docs/*.sql` files. The installer supplies your table prefix. Manual SQL is not normally required.
5. Reload Investment Tracker and review Settings > API setup. Financial history, journal notes, private images and dated reports are preserved. Existing provider/AI keys and server switches are not changed by installation.

Schema 28 adds `028-pricing-cutover.sql`: append-only workspace source policies and per-plan source-revision fences. It rewrites no posted financial rows. If installation is interrupted, keep provider/worker flags off, preserve the backup, fix the hosting/permission problem and reactivate the same matching build. Do not uninstall, purge data or downgrade PHP code against a newer schema.

## Prepare Tickerbot on a test copy first

Keep the existing FMP/Alpha Vantage/OpenAI configuration. Add these once to `wp-config.php` before WordPress loads; use your actual server-only key and a stable non-secret reference for the same Tickerbot account across key rotations:

```php
define('TGIT_TICKERBOT_API_KEY', 'YOUR_TICKERBOT_KEY');
define('TGIT_TICKERBOT_ACCOUNT_SCOPE', 'YOUR_STABLE_ACCOUNT_REFERENCE');
define('TGIT_TICKERBOT_ENABLED', false);
define('TGIT_TICKERBOT_WORKER_ENABLED', false);
define('TGIT_TICKERBOT_SCHEDULER_ENABLED', false);
```

Save local setup in Settings > API setup > Tickerbot setup and progress: choose the interval and shared monthly cap. Zero prevents new requests. Keys alone do not enroll assets or switch pricing.

For a staging connection/mapping/coverage check, enable only `TGIT_TICKERBOT_ENABLED`. Test connection, verify each asset mapping and inspect coverage. Each explicit operation can consume requests and uses the shared five-minute manual cooldown. Coverage metadata alone does not prove populated prices. Unknown exchange/currency identities must be corrected; crypto mappings currently support USD identities, not USDT/USDC accounting.

Select optional watchlists/planned trades in Assets and recurring refresh, then save. Positive open holdings are automatic. Review the complete saved inventory and estimate. Fix unsupported/unmapped entries before switching.

## Populate prices and switch

1. On the test copy, enable transport and worker (`TGIT_TICKERBOT_ENABLED` and `TGIT_TICKERBOT_WORKER_ENABLED`), keeping scheduler off. Select Request selected prices. This saves a fixed operation and queues bounded batches; it does not make the browser send the provider request.
2. WordPress cron must execute the queued batches. A host cron invoking WordPress cron without page visits is required for dependable background updates. For hosts using WP-CLI, the equivalent operation is `wp cron event run --due-now --path=/path/to/wordpress`; ask your host to configure the actual path/timer. Reload saved progress and prices. If queueing or delivery is uncertain, check the same retained operation; starting a separate operation is explicit and can consume more requests.
3. Review Workspace price source and rollback. If the desired pre-switch source is FMP or Alpha Vantage, save that workspace source first so rollback records it. Browser preview overrides do not change this saved source.
4. Choose Tickerbot and acknowledge the scheduling effect. Switching requires the current server-key connection check, recent cached prices for the entire saved scope, unchanged reviewed settings/scope, no active refresh in this workspace and no unresolved dispatched legacy price request. Resolve the displayed blockers rather than forcing the switch.
5. After switching, Overview's default Workspace pricing policy uses Tickerbot for stock, ETF and supported crypto holdings. Legacy price requests for this workspace are blocked at reservation and dispatch; their mappings/schedules remain saved. Alpha Vantage fundamentals and AI are unaffected.
6. For recurring updates, enable the server scheduler on the test copy, then choose On, acknowledge the saved scope/interval and Save recurring intent. Save again after changing flags or reactivation if queue repair is needed. Verify host cron actually runs with no page visits. Stored switches/due times alone are not proof.

Cached prices are unusable for valuation when missing, source time is unknown/future, or older than twice the saved interval. Receiving an old mark again does not renew source age. Fair-value time means provider fetch time rather than an exchange trade. Current stock totals require one receipt-run cohort; partial/mixed coverage shows covered subtotals instead of a fabricated complete total. Currencies stay separate; no FX is guessed. Daily price change from Tickerbot is unavailable. Unresolved basis keeps gain unavailable.

Overview also offers explicit local previews for manual/FMP/Alpha Vantage/Tickerbot cache. An explicit Manual preview is the manual override; there is no automatic fallback to manual or a different provider. Preview overrides do not resume polling or change the shared source policy. Historical reports retain their dated manual price/FX evidence.

## Roll back the pricing choice

In Workspace price source and rollback, choose Roll back to previous source, acknowledge and save. This appends a revision, restores the saved previous source and repairs the previously saved legacy schedules when their server flags permit it. Subsequent scheduled Tickerbot work and old queued batches are fenced. An already admitted request can finish and retain its evidence. Request counts are not refunded. Rollback remains available if the Tickerbot key/flags/reference are missing or disabled.

This is an application source rollback, not a database downgrade. Leave the matching schema-28 plugin installed. If you need to return to an older plugin, restore a reviewed matching database/private-media backup as a separate operation; do not install old code over a newer schema.

## Acceptance still required on your host

Before treating this as production-ready: compare live prices/source timestamps for representative instruments; confirm your API plan and monthly budget; verify HTTPS, BCMath/GD/private storage; demonstrate updates without page visits; exercise the application rollback; and observe first-run, 24-hour and seven-day behavior. Those live/hosting checks are not performed by local synthetic fixtures. Use the updated `user-guide.md` for the rest of the plugin.
