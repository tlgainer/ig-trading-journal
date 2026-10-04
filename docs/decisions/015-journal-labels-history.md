# Journal label controls and revision collection

Build 0.15.0 continues the shared WordPress admin UI. Schema remains 8; no SQL migration is required.

Tags and confluence labels use explicit Add/Enter and Remove controls with readable chips. They retain the existing limits of 50 items and 190 UTF-8 bytes per item, reject blank/multiline/control-character additions and duplicate trimmed labels. Adding/removing other confluences preserves checked states. Original spreadsheet confluence text remains a separate field. Pending text is included in dirty navigation checks; Save asks the user to add or clear it rather than silently discarding it. Viewers see existing labels and checked states without editing controls.

History uses the same semantic collection: newest revision first, author, workspace-timezone timestamp, compact field-change summary, whole-result search, sorting, pagination and scoped column/density preferences. The adapter follows every authorized revision cursor before rendering the searchable collection. Read-only revision details show stored field values and checkbox states, with exact stored JSON available in a secondary evidence disclosure. Viewing evidence never restores or edits an old revision.

The authorized history projection adds current WordPress display names, falling back to User #ID when the account no longer exists. Stored actor IDs and revision payloads remain immutable; changing a WordPress display name changes its presentation, not historical evidence. No emails, user profiles or member-management data are exposed. All names and evidence render as text. Workspace/trade changes clear private history and detail state.

Validation includes deterministic journal persistence and bounded history API fixtures, real WordPress browser label editing/Unicode limits/checklist preservation, private images and transaction linking, a 25-revision history spanning multiple API pages, oldest-revision viewing and viewer read-only controls, plus shared collection/shell and media HTTP checks. Full large-history benchmarks, screen-reader/zoom acceptance and actual hosting validation remain pending.

Next shared-pattern work: focused Strategy collection/editor, then Transactions, Settings and report-history collections. The upload queue, scalable server queries, full accessibility and original operational/accounting backlog remain separate requirements.
