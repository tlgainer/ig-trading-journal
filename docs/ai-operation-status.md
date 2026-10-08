# Read-only AI operation recovery

The owner-only GET `/workspaces/{workspace}/ai-evidence/{approval}/generation?operation_key={original-uuid}` looks up an existing 2,000-output-token operation. It returns only state and, when a reservation exists, request_id. If no reservation exists, it returns not_reserved. That does not prove a previous counting attempt did not happen; it is not authorization to send or retry.

The original approving owner must retain the exact operation UUID. Foreign approvals, substituted owners, invalid UUIDs and viewers are rejected. Existing request context must match the approval, original actor and fixed output ceiling. The operation lock prevents a lookup from claiming a final result during an active attempt; an active lock returns in_progress, which requires a later explicit lookup and does not authorize a retry.

This endpoint never counts, creates a reservation, dispatches, settles, refunds, publishes or calls a provider. It works with server processing disabled and exposes no credentials, prompt, prices, usage or receipt content. Reserved, dispatched, uncertain, settled and cancelled lifecycle states are read without changing facts.

No browser generation or enable control is added. Those live-capable controls await explicit approval of [the concrete proposal](ai-owner-generation-proposal.md). Source remains 0.27.0-dev.5/schema18; no SQL or new ZIP.
