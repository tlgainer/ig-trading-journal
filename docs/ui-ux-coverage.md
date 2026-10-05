# UI/UX rollout coverage

Source: owner's UI/UX Addendum v1.0, October 3, 2026. Scope is WordPress admin. The owner confirmed that React is unnecessary and requested the same patterns on screens added after the original review.

Build 0.24.0 continues the foundation and adds Trade Journal collection/detail navigation and private image tables, a transaction picker, grouped journal fields, label controls and revision tables. No lifecycle, posted-history or media-authorization contracts change. Schema remains 8.

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

Build 0.24.0 applies white section cards and consistent `1px solid #949494` control borders across all screens. Cards reduce padding on mobile and retain keyboard focus outlines. Financial, authorization and schema contracts are unchanged.
