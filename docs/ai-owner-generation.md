# Owner AI summary workflow

Source: 0.27.0-dev.6, schema 18. Owner implementation approval was received October 8, 2026. Processing is off by default. No real account, API key, portfolio transmission or production deployment was used during implementation. No SQL change or new release ZIP is included.

## 1. Prepare verified server setup

In Settings > API setup, keep the OpenAI key on the server. A server administrator must provide current exact-model access/pricing records and verified counting-access/cost records using [trusted server evidence](ai-server-evidence.md). Records must match the actual server key, selected model and UTC dates. Do not fabricate confirmation flags. A model listing or configured key alone does not establish every capability, current rates or counting entitlement.

`TGIT_OPENAI_ENABLED` defaults off. After genuine setup verification, the administrator can explicitly set it to true. This switch alone sends nothing. Missing/expired evidence, a rotated key, paid or unknown counting cost, unavailable models and unsupported capabilities remain blocked. Unknown/paid counting needs its own future spending lifecycle; the current controls make no assumption that counting is free.

## 2. Save model, budget and processing policy

In Settings > AI summary settings, select the exact model ID and shared monthly USD budget. Choose Shared AI processing: On for explicit summaries, then Save model and budget. Enabling requires the prepared server switch/key and current evidence. Failed enablement leaves the prior saved policy intact. The controller workspace owns this shared policy; other workspaces may read it but cannot replace it.

A zero budget pauses admission. Saving policy or changing the model sends no API request. Selecting Off remains possible without valid credentials/evidence and preserves existing requests and costs.

## 3. Save workspace consent separately

Choose Yes for this workspace, then Save workspace consent. Every workspace requires its own explicit opt-in. Shared policy enablement enrolls no other workspace. Consent does not generate a summary or create a schedule.

## 4. Approve the exact fundamentals and optional thesis

In Research > Stock fundamentals, choose saved statement snapshots. Select a saved trade thesis only if you want it included. Preview the exact evidence and approve it. Only the original approving owner may generate or recover its operation. Approval is immutable; subsequent journal changes do not replace the approved text.

No images, account names/numbers, balances, position sizes, transaction history, unrelated notes, news browsing or tools are sent. Only the approved stock fundamentals and optional thesis are used.

## 5. Check setup and explicitly generate

Below Approved evidence, choose Check summary setup. It shows the saved model, remaining allowance and blockers. This local check neither calls OpenAI nor estimates the summary cost. Positive remaining allowance does not prove affordability.

Generate summary becomes available only when all gates pass. It sends one explicit server command, using a fixed full output ceiling of 2,000 tokens. The browser retains an operation UUID before sending; blocked/invalid browser storage prevents generation. The server binds the clicked policy revision, verifies complete-input counting, admits the conservative cost atomically against the shared budget, and claims delivery once. If model/budget policy changes, check setup again. Counting and generation use only the fixed HTTPS OpenAI endpoints approved in the [proposal](ai-owner-generation-proposal.md).

There is no automatic generation, schedule, model substitution or automatic resend. Actual owner use may incur model API charges. Incomplete/refused/invalid output can still consume tokens; settlement does not imply a publishable review.

## 6. Recover an unclear result safely

Choose Check saved summary result. This is a read-only lookup using the retained identity, including after reloading the approved evidence. It never counts, reserves or sends.

- Not reserved: check current setup and explicitly Retry same summary request if appropriate. A previous counting attempt may have occurred. The same UUID is retained; a refreshed reviewed policy may be used only after confirming no reservation exists.
- In progress: wait and explicitly check again. Do not start another request.
- Reserved: it was not sent. Use Cancel unsent request in Settings to release the unused hold. Checking/retrying never sends that reservation.
- Delivery claimed or uncertain: do not resend. The existing budget hold remains until verified usage is available.
- Usage settled: inspect Saved AI request activity for Save as review eligibility.
- Cancelled or overrun: the same operation is not resent. An overrun blocks new admission pending budget review.

Do not clear a retained operation identity to bypass an unclear outcome. If it is unavailable, inspect saved request activity in Settings before planning new work. Generating another review uses newly approved evidence; the current panel retains one operation per approval/session.

## 7. Save and read the review

In Settings > Saved AI request activity, choose Save as review for an eligible completed response. This uses the stored response and makes no API call. Read it in Research > Stock fundamentals > Saved AI reviews. Claims must reference the approved source snapshots; missing metrics and compatibility limitations remain explicit. This feature summarizes saved fundamentals, not live news or a recommendation to trade.

## Endpoint and recovery contracts

POST `/workspaces/{workspace}/ai-evidence/{approval}/generate` accepts only integer expected_config_id and an Idempotency-Key UUID. Output ceiling, prompt, model, pricing, destination and evidence content cannot be overridden by the browser. Exact retry returns the original lifecycle state without another count/send once a reservation exists, even if shared processing is subsequently disabled. Conflicting original policy context is rejected.

GET generation status remains [read-only](ai-operation-status.md). Preview readiness is advisory and revalidated at count, reservation and dispatch boundaries. Financial values and prices remain decimal strings. Evidence, requests, responses, charges and reviews retain immutable history.
