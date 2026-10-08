# Proposed owner AI enablement and generation controls

Status: explicitly approved by the owner on October 8, 2026 ("you can implement"). The guarded owner controls are now implemented with default-off processing and synthetic tests only. The earlier automatic approval rejection was resolved by this authorization. No real API call or production deployment is performed.

## Concrete implementation scope

1. Add Shared AI processing: Off / On for explicit summaries in Settings. Default Off. On requires a configured server key, the explicit TGIT_OPENAI_ENABLED server switch, current credential-bound model/access/pricing evidence and verified counting-access/cost records. Save settings performs no external call. Separate per-workspace consent remains required. Turning Off preserves all data and does not release potentially billed reservations.
2. After Research evidence approval, Check summary setup discloses the saved model, remaining allowance and blockers. Add Generate summary only when every gate passes. The action uses the exact approval and a fixed full output ceiling of 2,000 tokens. Bind the clicked saved policy revision so an intervening model/budget change requires checking setup again.
3. Save a stable operation UUID in browser session storage before sending. If storage is unavailable, block the action rather than risk losing retry identity. One explicit POST may count complete approved input, reserve its conservative verified cost and send once. No schedule, automatic generation, fallback model or automatic retry.
4. Recover an unclear result with a read-only GET using the original operation UUID. Looking up an existing reservation never counts or sends it. If no reservation exists, an owner may explicitly retry with the same identity after checking setup. Dispatched/uncertain requests retain their holds and are never resent. Settings retains the existing Cancel unsent request and Save as review actions.
5. Keep publication separate: a completed settled response must pass strict cited-output checks and be saved using the stored receipt. Known usage settlement does not imply a usable review.

## Data and exact destinations

Only the approved saved stock fundamentals and optional explicitly selected trade thesis are sent. This includes stock identity, selected statement snapshot IDs/fingerprints/timestamps, reporting periods, calculated metrics and limitations. It excludes account identities, balances, holdings quantities, transaction history, private images and unrelated journal notes.

The only allowed destinations are https://api.openai.com/v1/responses/input_tokens for complete-input counting and https://api.openai.com/v1/responses for generation. The configured exact model is used. The server key goes only in the HTTPS authorization header. Provider storage, tools, browsing, background mode, streaming, redirects and automatic resend remain off.

## Cost and verification

Actual owner use can incur configured-model API charges. The conservative generation bound must fit the shared monthly budget, with decimal pricing, complete-input count, current evidence and consent rechecked before dispatch. Unknown/paid counting remains blocked until a separate counting spending lifecycle is implemented; no free-counting assumption or fabricated access flag is introduced. Setup records must be genuinely verified for the owner's account; this change does not claim to verify real account access automatically.

## Validation and authorization boundary

Implementation uses synthetic keys/models/prices and intercepted HTTP only. Regression coverage includes permissions, stale policy, overridden browser prompts/prices, default-off configuration, budget/consent denial, one-time delivery, unclear results, retained operation identities, immutable publication and desktop/mobile layouts. No production deployment, actual API key use or real portfolio transmission is requested.

Approved scope: implement the described guarded owner enable/generation controls and test them synthetically. The owner explicitly approved adding the live-capable workflow; this does not authorize the assistant to make a real API call. The owner would later choose to use the explicit controls after genuine setup verification.
