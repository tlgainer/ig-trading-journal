# Trade Journal collection and detail navigation

Build 0.12.0 continues the shared WordPress admin UI without React or new dependencies. Schema remains 8; no SQL migration is required.

Trades use the shared semantic table with full-result search, stable sorting, pagination, column/density preferences, and a default Planned/Open view. Rows expose asset identity, captured strategy, tags for search, latest journal revision time and active image counts. The workspace-scoped API projection excludes journal prose. The adapter follows every authorized cursor before showing results; server-side filters/sorts and large-history benchmarks remain pending.

Opening a trade shows Summary, Plan and journal, Transactions, Images and History tabs. New trade creation initially asks for title, asset and optional strategy, then exposes the existing journal fields after creation. Posted facts, lifecycle rules, revision authorization and private image storage are unchanged.

Section/workspace/trade identifiers support deep links and browser Back. Private text is never put in URLs or preferences. Returning to the list preserves its current search/view. Dirty journal changes require a discard decision when leaving, including workspace switches and browser navigation. A revision conflict retains entered text and offers an explicit reload of the latest revision.

Validation covers deterministic collection filters, workspace-scoped metadata, minimal creation, detail tabs, private images, dirty cancellation, Back, deep-link reload and concurrent-revision recovery on the disposable WordPress site. This is a partial addendum slice: transaction linking controls, tags/confluences, grouped Plan fields, history tables, active/Trash image tables, server query performance and complete accessibility acceptance remain.
