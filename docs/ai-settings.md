# AI summary Settings (development)

Owners can prepare AI settings under **Settings → AI summary settings**. Enter a monthly USD budget (the initial suggestion is 15; 10 or another amount is supported) and the exact OpenAI API model ID, or leave the model blank to choose later. Zero pauses the budget. No model is automatically selected.

The first workspace whose owner saves the shared policy becomes its controller. Owners in that workspace can change the shared model and budget. Owners in other workspaces can read the shared spending totals and save their own workspace consent, but cannot change the shared policy. Consent is separate from the shared policy and defaults to No; save the shared policy before consent. Saves create append-only revisions and reject stale revisions. Disabling future processing does not refund unresolved reservations.

The panel shows current estimated spending, outstanding reservations, remaining allowance, warning level and the New York monthly reset in UTC. Amounts display trimmed decimals. Shared accounting remains one pool across workspaces and credential rotations. These are plugin accounting estimates, not an OpenAI billing statement.

This development panel prepares settings only. Processing remains unavailable and every public policy save requires `enabled: false`; there is no enable button, provider credential input, summary request or scheduled summary. Saving workspace consent does not send data. Account access and dated prices still require trusted verification through the model catalog gate before a later transport milestone. An entered model ID alone supplies neither access evidence nor pricing evidence.

Private owner-only routes: GET/POST `/workspaces/{id}/ai-settings`, POST `/workspaces/{id}/ai-enrollment`. Policy requires `enabled`, decimal-string `monthly_cap`, string `model`, integer `expected_config_id`. Enrollment requires boolean `enabled` and integer `expected_enrollment_id`. Responses omit credential fingerprints, pricing JSON, foreign controller IDs and actor identities. Unknown input fields are rejected. Existing HTTPS, membership, nonce and no-store response protections apply.

Both forms use existing admin cards, grids and native controls. Initial load failure keeps controls inert; reload is available. Saving one form preserves unsaved fields in the other. Workspace/section navigation and Back/reload guard unsaved changes. An uncertain save requires reloading current revisions before retrying.

No schema change in this milestone. Development remains schema 13; all thirteen migrations remain required for future packaging. No new installation ZIP or production migration.
