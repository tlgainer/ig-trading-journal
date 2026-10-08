# Cancel an unsent AI request

In Settings > AI summaries > Saved AI request activity, select **Cancel unsent request** to release an unused budget reservation. The action appears only for the owner who originally authorized a request that remains Reserved — not sent. Processing, consent and credentials do not need to be enabled to recover an unused reservation.

Delivery claimed, uncertain, settled, overrun and receipt-backed requests cannot be cancelled this way. Their costs require usage evidence. Cancellation does not contact OpenAI or retry delivery.

A successful cancellation appends an audited lifecycle event and releases the hold; original request facts and execution evidence remain unchanged. Repeating the same cancellation returns its original result. A shared spending lock serializes cancellation with dispatch. If dispatch wins, cancellation fails and retains the hold.

The activity table reloads and displayed allowance refreshes after cancellation. Unsaved form values remain intact. If the result is unclear because of a connection failure, reload saved requests before retrying. No automatic retry occurs.

The owner-only POST `/workspaces/{workspace}/ai-requests/{request_id}/cancel` accepts an empty JSON object and returns only request_id and state. Client supplied amounts, usage, refund overrides and state are rejected. Eligibility in history is advisory; authorization, original actor, workspace and lifecycle are rechecked on write.

Source version: 0.27.0-dev.4. Schema remains 18. No SQL migration or new install package is included in this change.
