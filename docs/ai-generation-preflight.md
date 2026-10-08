# AI summary preparation

Research > Stock fundamentals > AI evidence: preview and approve saved evidence, then choose **Check summary setup** beneath the approved bundle. The panel checks the saved model, current reviewed server evidence, original authorizer, shared processing policy, workspace consent and remaining shared budget. It also explicitly reports that generation controls remain unavailable.

This is a local advisory check. It sends no external request, counts no tokens, reserves no budget and does not estimate the cost of a summary. Positive remaining allowance does not establish affordability; exact complete-input counting and conservative cost admission remain required before future delivery. A current reviewed record is not a new authenticated account verification. Setup can change after checking; generation must revalidate every gate.

The owner-only GET `/workspaces/{workspace}/ai-evidence/{approval}/preflight` returns approval/asset IDs, saved model, remaining decimal allowance, named checks and blocker codes. It omits approved evidence bytes, thesis, keys, fingerprints, pricing and provider details. Another owner can inspect setup but receives an original-owner blocker; viewers and foreign workspace approvals are denied. Damaged approval evidence fails closed.

The panel clears when evidence selection or workspace changes. Responses arriving after reset cannot overwrite the new context. A failed check can be explicitly retried. Approved evidence and unsaved forms remain unchanged.

Official reference: [OpenAI complete-input token counting](https://developers.openai.com/api/docs/guides/token-counting). The current implementation retains its separate verified counting-access/cost requirement; no free-counting assumption is added.

Source 0.27.0-dev.5, schema 18. No new SQL or release ZIP. Next: genuine model/pricing/count-cost verification and the explicit guarded generation/enable controls.


October 8, 2026: Preflight now reports workflow availability and the reviewed config_id; can_generate is true only when every check passes. The explicit default-off controls are implemented in [owner generation](ai-owner-generation.md). Preflight itself remains local and read-only.
