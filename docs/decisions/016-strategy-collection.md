# Strategy collection and focused editor

Build 0.16.0 applies the shared WordPress admin patterns to Strategies. Schema remains 8; no SQL migration is required.

Strategies use the shared searchable table with Active/Archived/All views, sorting, pagination and actor/workspace column/density preferences. Rows show current name, status, latest version and tags. New/Edit uses one focused form, explicit Create strategy / Save new version actions, the shared tag controls, cancel/back navigation and visible Strategy feedback. Pending form/tag changes are protected on cancellation, section/workspace changes, browser navigation and unload. Revision conflicts retain entered text and offer explicit reload.

Version viewing uses a read-only collection and sanitized description/rules previews. Every version cursor is followed before collection search/sort and before assembling the available captured-version choices for journals. Existing active historical versions remain available under the prior linking contract. Strategy edits append immutable versions; existing trade snapshots are unchanged. Reloading a conflict refreshes the table as well as the form.

Owners/managers can create/edit; other members can read the collections and version evidence. Workspace changes clear editor/view state and cached collections. No React, new runtime dependency, financial posting or SQL changes are introduced. Description/rules currently retain the existing basic-HTML textarea contract; a richer WordPress editor, scalable server queries, performance and full accessibility acceptance remain future work.

Remaining shared-pattern screens: Transactions, Settings and report-history/observation collections. Original accounting/import/recovery and operational release gates remain documented separately.

Validation: 41 unit and 81 disposable WordPress/database integration checks passed, together with PHP/JavaScript syntax, Composer coding standards and REST URL checks. Strategy browser coverage verifies focused creation/editing, dirty navigation, retained search, conflict recovery and immutable version previews. Journal desktop/mobile, complete history, shared collection and private-media HTTP regressions also passed. Production deployment and full performance/accessibility acceptance remain pending.
