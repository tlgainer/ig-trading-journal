# Weekly fundamental enrollment — development

Schema 12 adds only the append-only `fundamental_schedules` table. SQL is separate in [012-fundamental-schedules.sql](012-fundamental-schedules.sql). Back up database and private media, bundle all twelve migrations, and explicitly reactivate matching code to upgrade schemas 1–11. There is no request-time migration or ledger replay. After partial DDL failure, disable processing, repair permissions/prerequisites and reactivate the same code; preserve enrollment, request and snapshot evidence rather than downgrading the marker or deleting data. The released ZIP remains 0.26.0/schema 8.

## Owner controls

In Research → Stock fundamentals, choose an asset and dataset. Owners can save **Off** or **Weekly**, with Monday–Friday at **7:30 PM America/New_York** (EST/EDT). Each dataset has independent enrollment and an expected revision check. Saving a schedule sends no provider request. Only a current enabled Alpha Vantage mapping can enroll; server fundamental processing must also be enabled before a job can queue. Disabled server configuration preserves the enrollment with a pending-queue message.

Schedule edits use the shared control layout and confirmation before abandoning unsaved changes. Viewers/managers cannot read or write owner schedule configuration. Provider history and metrics remain readable by authorized viewers.

The owner API uses GET/POST `/workspaces/{workspace}/provider-mappings/{mapping}/fundamentals/schedule`. GET returns the latest revision for each of the four supported datasets. POST accepts only `dataset`, `frequency`, integer `weekday` (1–5), and integer `expected_schedule_id` (0 when absent). Dataset/provider/mapping/owner validation, workspace locking, revision append and audit occur before recoverable cron queueing.

## Clock, authorization and recovery

The pure clock returns a strictly future weekly slot and honors New York DST. The hourly recovery scan queues only durable enrollment; holdings and API keys never enroll assets. Every queued job rechecks the original owner, the latest dataset revision, the current enabled mapping and server flags. A stable original-slot request key prevents duplicate completed or uncertain dispatches.

Jobs more than two hours late skip their request and queue the next future weekly slot. Quota failures, entitlement failures and uncertain transport do not cause automatic retries or catch-up bursts. Site cron is traffic-dependent; a real host cron is needed for reliable wakeups. Exchange holidays do not suppress these fundamental jobs, and a schedule does not guarantee newly published statements or provider access.

Turning Off makes older jobs unauthorized. Replacing/disabling a mapping or revoking the original owner also stops old jobs; a replacement mapping needs new explicit enrollment. Deactivation removes scheduled fundamental jobs and the recovery scan while preserving records. After reactivation, separately enabled server processing and the recovery scan rebuild future jobs from still-authorized enrollment.

## Shared allowance

Each dataset uses one attempt per weekly slot. The four datasets for ten holdings require up to 40 attempts per week, before quotes. Alpha Vantage fundamentals and quotes share the configured credential's rolling 25-attempt allowance across workspaces, with scheduled processing capped at 20 to preserve five on-demand attempts. Spread datasets/stocks across weekdays and account for automatic quotes; enrollment does not reserve future allowance or guarantee capacity. FMP quote requests use their own separate pool. No automatic provider fallback is added.

## Validation and remaining work

74 unit and 135 disposable WordPress/MySQL integration checks pass: DST/strict future slots, migration repair, immutable history, owner-only routes, dataset-specific revisions, foreign mappings, current provider identity, rollback, deduplication, completed retry reuse, late/future jobs, disabling, revocation and scan/deactivation recovery. Browser controls and full journal/media regressions are required before a release. Synthetic fixtures and intercepted responses only; no live provider, production or OpenAI requests.

Comparable-period changes and AI investment summaries/model/monthly-budget controls remain pending. Weekly provider refreshes do not constitute scheduled AI reviews.

Browser validation completed: weekly enrollment/disable and dirty dataset/section guards, owner/viewer history, journal shortcuts, Back/reload, exact metrics and uncertain identity pass. Desktop/mobile journal, all 11 real HTTP media checks and all eight responsive shell sections pass. Production-host and release packaging acceptance remain outstanding.
