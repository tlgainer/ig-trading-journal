# UI/UX rollout coverage

Source: owner's UI/UX Addendum v1.0, October 3, 2026. Scope is WordPress admin. The owner confirmed that React is unnecessary and requested the same patterns on screens added after the original review.

Build 0.26.0 continues the foundation and adds Trade Journal collection/detail navigation and private image tables, a transaction picker, grouped journal fields, label controls and revision tables. No lifecycle, posted-history or media-authorization contracts change. Schema remains 8.

## Shared rules

All eight sections use the same scoped control sizes, typography, focus treatment, desktop section rail and mobile menu. Collections reuse `assets/collection.js`; forms retain their existing domain-specific validation. Cards remain appropriate for metrics and calculated summaries. A collection adapter must supply a complete authorized dataset; never pass an API page to controls claiming full-result search/sort. Server query contracts, focused editors and route restoration are subsequent work.

| Screen | Delivered | Remaining application of shared patterns |
| --- | --- | --- |
| Overview | Cash account and Holdings tables, complete-cursor search, account filters, readable string decimals/raw value titles, explicit price/basis coverage | Compact metrics, contextual empty-state actions, scalable server queries and accessibility/performance acceptance |
| Transactions | Complete-cursor searchable table, state filter, scoped preferences, focused New/Edit draft forms, dirty guards, separate draft/post actions, read-only details/revisions, conflict reload and funding guidance | Detail deep links, Correct eligibility/action UX, structured server field errors and accessibility/performance acceptance |
| Trade Journal | Planned/Open default table; full-result search/sort, detail tabs, minimal creation, deep links/Back, dirty guards and revision-conflict reload; Active/Trash image table, focused metadata editor and independent upload; transaction picker and grouped Plan fields; tag/confluence controls and read-only revision table/details | Server filters/sorts, performance and accessibility acceptance |
| Strategies | Searchable Active/Archived table, focused New/Edit, shared tags, dirty guards/conflict reload, visible feedback and immutable version table/preview | Rich editor, scalable server queries, performance/accessibility acceptance and detail deep links |
| Calculators | Shared shell and controls; all scenarios use readable decimal outputs; bought options separate premium sale from expiration payoff, adjacent explanatory help and alert/status feedback; late workspace responses are discarded; short-only costs are shown by direction and risk-budget help explains sizing | Ordered native calculator accordions are delivered; full accessibility review remains |
| Research | Shared watchlist-item and research-note tables, supported sorts, whole-result search, pagination, scoped column/density preferences | Watchlist management, focused New/Edit, contextual filters, route/context preservation and scalable server queries |
| Reports | Shared shell and controls; reproducible calculations retained | Observation/run/saved-view collections, focused observation/correction editor, consistent filter controls and report row presentation |
| Settings | Workspace creation collapsed after onboarding; shared controls | Workspace/Accounts/Assets/Members/Preferences organization, collection adapters, permission-controlled user picker, focused editors |
| Future imports/reconciliation/exports | No new screens in this slice | Start with the same collection/editor/feedback components rather than a separate visual system |

## Evidence and remaining gates

Foundation evidence: UIR 02/03/05/06/40 and initial UIR 07/08/09/13/14/24/35 support. Semantic table fixtures verify 101 records, search beyond page one, sorting/pagination, Unicode, exact large decimal display, preference isolation and no page-wide overflow. Shell fixtures check 360/768/1440px, section deep links/Back, retained form input and role-controlled Settings.

The disposable WordPress HTTP adapter also passes navigation/overflow checks for every current section at all three widths, including Calculators, Research and Reports. Real research create/revise checks verify UTF-8 persistence through database/API/editor/table. Visual review corrected stretched form controls and desktop/mobile menu visibility. Actual hosting admin/theme validation remains pending.

No full UIR or UXA acceptance is claimed. Server-side table queries, 1,000-trade/100-image benchmarks, full 200-percent zoom/screen-reader checks, dirty navigation handling outside Trade Journal, upload queue table and remaining focused forms remain. The current library decision and compatibility reasoning are in `decisions/011-admin-ux-foundation.md`.

Trade slice evidence and limits are in `decisions/012-trade-journal-navigation.md`. Browser fixtures cover minimal creation, private images, active image counts, retained list context, dirty cancellation, deep-link reload and concurrent-revision recovery.

The private image collection and its remaining gates are documented in `decisions/013-private-image-collection.md`. No full UI/UX acceptance is claimed.

Transaction picker eligibility, corrected-source revalidation and grouped form decisions are in `decisions/014-journal-fill-picker.md`.

Label controls, complete revision cursor loading and read-only history evidence are documented in `decisions/015-journal-labels-history.md`.

Strategy collection/editor and immutable version viewing are documented in `decisions/016-strategy-collection.md`.

Transaction collection and focused-entry evidence are documented in `decisions/017-transaction-collection.md`.

Read-only transaction details, explicit draft/post actions and error recovery are documented in `decisions/018-transaction-detail-recovery.md`.

Holdings table and the extended-requirements separation are documented in `decisions/019-holdings-collection.md`.

Build 0.26.0 applies white section cards and consistent `1px solid #949494` control borders across all screens. Cards reduce padding on mobile and retain keyboard focus outlines. Financial, authorization and schema contracts are unchanged.

Build 0.26.0 separates collection headings from toolbar controls, aligns clear/filter/pagination buttons to input baselines and applies consistent inline table action groups and action column widths. Compact visible View/Edit/Post labels retain full accessible names and title text; financial actions and permissions are unchanged.

Build 0.26.0 adopts the owner-provided `.tgit-pages` white background, 15px padding and 10px radius. Sections inside the page card have no additional outer borders or padding; tables, calculator accordions and focused editors keep their own boundaries.

Development market-data Settings reuses the shared collection and form controls: complete current-mapping cursor loading, scoped preferences, readable decimal strings, aligned row actions, role-gated controls, unsaved-edit guards and stale-response protection. It shows explicit end-of-day sessions and configuration/coverage limitations. Recurring enrollment and holdings valuation selection remain pending; this does not change released 0.26.0 coverage.

Development schema 10 adds an explicit weekday refresh control and frequency column in the existing owner Settings form/table. Enrollment has its own Save action, revision checks and clear pending-queue status. The existing full-result collection, scoped preferences, decimal formatting, aligned actions and unsaved-edit guard remain in use. New York/DST slots and weekend/holiday/site-cron limitations are stated in the control help.

## October 6, 2026: cached provider holdings and stock totals

Development source remains 0.27.0-dev/schema 10; the released ZIP remains 0.26.0/schema 8. Overview now offers an explicit manual/FMP/Alpha Vantage stock price source and exact open-stock market value and unrealized gain/loss totals across all authorized accounts and asset pages. Other asset classes keep manual pricing. Values are grouped by native currency, exclude cash and closed positions, and do not change with table filters.

Provider valuations require the latest enabled matching mapping and a saved quote no more than three calendar days old. Missing/stale provider prices remain unavailable without fallback. Unknown basis leaves gain unavailable. Partial coverage shows covered subtotals; mixed price dates cannot claim a complete total. Source preference is scoped to the current user/workspace browser session. Existing posted facts, manual observations and saved reports remain unchanged; reads send no external requests.

Validation: 58 deterministic unit checks and 110 disposable WordPress/MySQL integration checks passed, including full pagination, source validation, membership, staleness, mapping replacement, unknown basis and immutable manual reports. Coding standards, PHP/JavaScript syntax and six REST URL checks passed. The cached valuation browser fixture passed source switching, exact values, missing coverage, scoped restoration and desktop/mobile layout. No new SQL, live provider calls or release ZIP.

Next: corporate-action compatibility before displaying daily change, then fundamental snapshots and AI summaries with configurable model and monthly budget. Exchange holidays and operational monitoring remain open.
## Development: fundamental Research screen

Stock fundamentals now use the scoped page/card, full-cursor collection, readable decimal display and aligned View action patterns. Owners get explicit single-dataset refresh controls; members can browse immutable history and statement metrics. Configuration stays server-side. Uncertain request identities persist by actor/workspace/mapping/dataset across reloads. Generation guards prevent stale history/detail responses. Mobile tables scroll within the page card. Journal navigation, recurring review enrollment and AI review controls remain pending.

Development stock journal summaries now offer a fundamentals shortcut through the existing section navigation, preserving unsaved journal/image confirmation and list context. Research selects only an asset from the current workspace's loaded stock choices, waits for initialization before loading it, focuses the selector and retains selection in its scoped URL. Back returns to the saved journal; no automatic provider refresh is triggered.

Weekly fundamental controls reuse the Research grid, native selects, separate Save action, revision/pending status and role gating. Dataset and asset changes, section navigation, keyboard navigation, Back and unload protect unsaved schedule edits. Each selected dataset has independent enrollment; viewer history remains separate from owner configuration.

Reporting-period changes reuse Research's scoped collection tables, decimal display, column preferences and contained horizontal scrolling. Prior/current values, fiscal dates, period gaps, currencies and unavailable reasons are visible. A plain-language explanation distinguishes percentage-point differences from verified period growth and explains unverified duration/accounting-policy compatibility. Owner and viewer reads use the same saved evidence without triggering refreshes.

AI Settings now uses the existing Settings panel, white section cards, native grid controls, exact trimmed amounts, owner-only visibility and dirty-navigation guards. Model/budget and consent saves preserve the other form's unsaved fields. Initial load failure keeps forms inert; reload is explicit.

Owner evidence controls now reuse the Research white card, native grid/labels, separate Preview/Approve actions, live status and owner role gating. Explicit dataset/thesis choices invalidate previews when changed; workspace/asset reloads clear stale local evidence. Exact evidence uses a wrapping disclosure, while uncertain approvals preserve actor/workspace/asset-bound retry identity across session reloads without storing thesis text. No new schema is needed for this control slice.

Development schema 15 adds internal immutable review output and owner-scoped cursor history. Research preview/approval controls remain unchanged. Full review-history screens and summary generation are still pending; future screens must use the shared card/collection/control patterns, load all history pages before complete-dataset search claims, and render model output as untrusted escaped text.

Owner-only saved AI review history now uses the Research white card, full-cursor collection, aligned accessible View action, explicit metadata-only search scope, live status and contained 360/768/1440 scrolling. Detail uses escaped plain text for summaries/findings, original approval provenance and wrapping evidence disclosure. Generation guards clear old detail on stock/workspace changes or failed reads; viewing/reloading never starts provider/AI processing.

## 0.27.0-dev.1 Research and Settings usability

Both screens now use the same sticky, wrapping in-page section shortcut menu. Native links move focus to an existing card/form without rebuilding controls, altering the URL, discarding unsaved edits or making requests. Hidden role-restricted targets are excluded; a shortcut to an existing details section expands it. Other screen navigation and dirty guards retain their existing behavior. Settings includes server-side FMP/Alpha Vantage configuration instructions and explicitly unavailable OpenAI key/generation status.

Fundamental schedule controls use native disabled states with a live prerequisite explanation instead of an invisible inert form. Missing enabled mapping and load failures disable save; Off disables only the weekday selector. Valid mappings can still save pending enrollment while server processing is disabled. Dirty tracking includes disabled values, and workspace changes clear schedule readiness. AI policy/consent and evidence selects also show actual disabled states while retaining values and approval retry identities. The guide explains current workflows and preparation versus live processing. Schema remains 17.

AI Settings now uses the existing scoped card, native accordion and collection patterns for reviewed readiness and saved request activity. Metadata search starts only after all workspace request cursor pages load. History reload preserves unsaved forms; workspace changes clear history and stale responses. Read failure clears the table and exposes a reload path. No generation/retry actions added. See ai-readiness-activity.md.

AI request activity adds a compact Save as review action using shared table/button patterns. It preserves drafts, blocks overlapping writes, refreshes saved identity after success and requires reloading before retrying uncertain publication. Saved review IDs replace actions; unavailable output grants no button. No delivery retry is added. See ai-stored-publication.md.


October 8, 2026: Owner recovery now includes cancelling original-owner unsent AI reservations from the shared activity table. Dispatch/uncertain/receipt-backed work remains protected. Unsaved Settings values survive recovery; unclear outcomes require explicit reload. See ai-unsent-cancellation.md. Generation controls remain unavailable pending their next verification slice.
