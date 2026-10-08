# Proposed OpenAI sender: data and destination

This proposal describes the blocked sender implementation, not an enabled feature. Automatic approval review rejected adding the external egress path because it considered authorization for the exact sensitive payload and destination insufficient. No sender was written and no external request was made.

## Exact destination and request

The proposed server implementation would use only `https://api.openai.com/v1/responses` for summary generation and, in a subsequent counting operation, `https://api.openai.com/v1/responses/input_tokens`. Both requests would contain only the exact owner-approved bundle produced by the existing Research > AI evidence preview. The model is the owner's configured exact model ID; no automatic model substitution.

The approved bundle contains:

- Stock symbol, exchange and quote currency.
- Selected saved income-statement, balance-sheet and cash-flow snapshot IDs, fingerprints and retrieval timestamps.
- Selected reporting periods, fiscal dates, currencies and calculated metrics: margins, current ratio, liabilities/assets, reported debt, net debt and free cash flow. Missing values and limitations remain explicit.
- Optionally, the explicitly selected trade thesis text, trade ID and journal revision. Without selection, thesis is null. Thesis text can contain private investment reasoning, so it needs explicit approval in the preview.

The request wraps those exact approved bytes in fixed summary instructions, a strict cited-output schema and the full output-token ceiling. Tools, news browsing, images, background processing, streaming, previous conversations and provider response storage are disabled. It contains no account names/numbers, cash balances, holdings quantities, transaction records, other journal fields or private images. The integration does not add provider keys or other system secrets. Review any optional thesis text before approving it. The OpenAI credential itself is sent only to OpenAI in the authorization header; it is never placed in the evidence, browser output, database status or ordinary logs.

## Proposed safeguards

Server enablement remains false by default. The original authorizing owner, workspace membership, saved approval, exact model access/pricing, separate consent, positive monthly allowance, fresh complete-input count and immutable reserved request must all pass before dispatch. The sender would transmit the stored request, rather than accepting prompt text or arbitrary destinations from the browser. It would make one bounded HTTPS attempt with certificate verification, no redirects and no automatic resend. Uncertain delivery or charge would retain the full budget hold; changing the key or retry parameters would not create another send for the same request.

Implementation/testing authorization would permit writing this disabled sender and testing it with synthetic intercepted HTTP responses. It would not authorize the assistant to use your real keys or send your real portfolio data. Actual use would require the finished owner generation workflow and its preview/consent checks.

The [OpenAI authentication reference](https://developers.openai.com/api/reference/overview) supports server-side Bearer credentials, and the [text generation guide](https://developers.openai.com/api/docs/guides/text) describes Responses requests. The existing request and budget contracts are in [ai-prompt-contract.md](ai-prompt-contract.md) and [ai-execution-manifests.md](ai-execution-manifests.md). Account availability, current pricing and real model/hosting acceptance remain unverified.
