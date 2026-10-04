# UI/UX rollout coverage

Source: owner's UI/UX Addendum v1.0, October 3, 2026. Scope is WordPress admin. The owner confirmed that React is unnecessary and requested the same patterns on screens added after the original review.

Build 0.11.0 is the first slice. No lifecycle, posted-history or media-authorization contracts change. Schema remains 8.

## Shared rules

All eight sections use the same scoped control sizes, typography, focus treatment, desktop section rail and mobile menu. Collections reuse `assets/collection.js`; forms retain their existing domain-specific validation. Cards remain appropriate for metrics and calculated summaries. A collection adapter must supply a complete authorized dataset; never pass an API page to controls claiming full-result search/sort. Server query contracts, focused editors and route restoration are subsequent work.

| Screen | Delivered | Remaining application of shared patterns |
| --- | --- | --- |
| Overview | Cash account table, string decimal display, shared shell | Holdings table, compact metrics, contextual empty-state actions, full precision on demand |
| Transactions | Shared shell and controls; draft/posting workflows retained | Collection first, focused entry, separate draft/post actions, detail/Correct eligibility, field errors and funding recovery |
| Trade Journal | Shared shell; malformed confidence separator fixed | Planned/open default collection, full server filters/sorts, dedicated detail/tabs, transaction picker, tag/confluence controls, revision conflicts and dirty guards |
| Strategies | Shared shell and controls | Table, focused New/Edit, safe rich editor, captured-version view |
| Calculators | Shared shell and controls; scenarios remain separate from posting | Grouped forms, shared monetary presentation/help/error patterns and accessibility review |
| Research | Shared watchlist-item and research-note tables, supported sorts, whole-result search, pagination, scoped column/density preferences | Watchlist management, focused New/Edit, contextual filters, route/context preservation and scalable server queries |
| Reports | Shared shell and controls; reproducible calculations retained | Observation/run/saved-view collections, focused observation/correction editor, consistent filter controls and report row presentation |
| Settings | Workspace creation collapsed after onboarding; shared controls | Workspace/Accounts/Assets/Members/Preferences organization, collection adapters, permission-controlled user picker, focused editors |
| Future imports/reconciliation/exports | No new screens in this slice | Start with the same collection/editor/feedback components rather than a separate visual system |

## Evidence and remaining gates

Foundation evidence: UIR 02/03/05/06/40 and initial UIR 07/08/09/13/14/24/35 support. Semantic table fixtures verify 101 records, search beyond page one, sorting/pagination, Unicode, exact large decimal display, preference isolation and no page-wide overflow. Shell fixtures check 360/768/1440px, section deep links/Back, retained form input and role-controlled Settings.

The disposable WordPress HTTP adapter also passes navigation/overflow checks for every current section at all three widths, including Calculators, Research and Reports. Real research create/revise checks verify UTF-8 persistence through database/API/editor/table. Visual review corrected stretched form controls and desktop/mobile menu visibility. Actual hosting admin/theme validation remains pending.

No full UIR or UXA acceptance is claimed. Server-side table queries, 1,000-trade/100-image benchmarks, full 200-percent zoom/screen-reader checks, dirty navigation handling, active/Trash image tables and focused forms remain. The current library decision and compatibility reasoning are in `decisions/011-admin-ux-foundation.md`.
