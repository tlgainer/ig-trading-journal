# Private image collection

Build 0.13.0 applies the shared collection to the Trade Journal Images tab. Schema remains 8; no SQL migration is required.

The table provides filename/caption search, sorting, pagination, column/density preferences and Active/Trash/All views. It receives the complete authorized gallery (the owner quota allows at most 100 retained images per trade, below the API's 200-row bound). Preferences and in-memory collection context are isolated by actor, workspace and trade. Active includes ready, failed and reserved images; Trash retains quota usage until authorized cleanup.

Metadata edits use one focused editor with caption, accessible description, timeframe, stage and order. Unsaved edits are protected on editor cancellation and journal navigation. Revision conflicts retain draft text; an explicit reload fetches current metadata. Upload selected images is a separate action and does not create a journal revision. Per-file retry/replacement, original viewing, two-image comparison and restore keep the existing API authorization and revision contracts.

Thumbnails and normalized originals remain authenticated private blobs. Closing/changing the journal invalidates late binary responses and releases blob URLs. Table renders cache thumbnail requests only within a gallery refresh. No WordPress public uploads, attachment records, schema, financial posting or storage policy changes are introduced.

Remaining: upload queue table, scalable server queries for other collections, transaction linking controls, 100-image performance benchmarks, screen-reader/zoom acceptance and actual hosting validation. The asynchronous processing and crash/orphan reconciliation backlog is unchanged.

Validation: 41 unit and 80 disposable WordPress/database checks; 11 real HTTP media checks; desktop/mobile journal creation, independent upload without a journal revision, metadata edits and stale-revision recovery, dirty cancellation, Trash/restore, private comparison, Back/deep links; shared collection/reset, research/calculator and all-section shell browser fixtures. PHP/JavaScript syntax, Composer coding standards and REST URL checks pass. Visual review corrected narrow action columns that inflated mobile row height.
