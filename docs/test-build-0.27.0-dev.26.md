# 0.27.0-dev.26 development test build (schema 28)

Adds immediate refresh for one asset or up to 50 selected assets. The previous queued full-scope and recurring refresh options remain available.

1. Back up the database and private-image directory together.
2. Upload `ig-trading-journal-0.27.0-dev.26-test.zip` and replace the existing plugin. The ZIP has one stable `ig-trading-journal` folder.
3. Reactivate if upgrading from a schema older than 28; all 28 migrations are bundled and intermediate ZIPs are unnecessary. This update adds no schema migration.
4. Go to Settings > API setup > Tickerbot > Assets and recurring refresh > Refresh individual assets now.
5. Search and select 1-50 mapped assets, then choose Refresh selected assets now. Unmapped/unsupported assets require mapping first. This action uses one request, saves available prices immediately and updates Overview when Tickerbot is the active pricing source.

A valid server key, account reference, workspace setup and `TGIT_TICKERBOT_ENABLED=true` are required. `TGIT_TICKERBOT_WORKER_ENABLED`, `TGIT_TICKERBOT_SCHEDULER_ENABLED` and cron can remain off for this action. The five-minute manual cooldown and shared-account request limits still apply. No automatic retries are queued.

Check manual refresh outcome reads only the retained original operation, including after reload or network uncertainty. It never sends a provider request. New manual selection is an explicit new operation. Selection does not alter recurring scope, next due time, pricing policy, posted facts or cash. A partial selection may leave stock totals marked partial because different receipt cohorts are not combined as a complete portfolio snapshot.

Keep keys server-side. Do not enable processing while configuration or mappings are incomplete. Local synthetic verification does not replace live coverage, HTTPS/private-storage, real-host cron and observation acceptance. See `user-guide.md` for setup and `test-build-0.27.0-dev.25.md` for the earlier full cutover/rollback workflow.
