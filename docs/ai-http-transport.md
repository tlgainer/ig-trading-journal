# Disabled OpenAI Responses sender

The owner approved implementation/testing of the [sender proposal](openai-sender-proposal.md). This internal sender is implemented, with no generation route, cron enrollment or live model call. The internal count transport is implemented separately; trusted cost/access evidence and the owner generation workflow remain pending. Schema stays 18; no new SQL or installation ZIP.

`AiTransport::run` accepts an authorized actor's spending service, a workspace-scoped reserved request ID and fresh trusted model-catalog evidence. It sends only the immutable manifest; it accepts no arbitrary prompt, model override, destination or browser token count. The existing dispatch operation requires the full execution manifest and rechecks original owner, membership, saved approval, consent, captured configuration/prices, credential, monthly allowance and fresh token-count bounds before claiming once.

The fixed destination is `https://api.openai.com/v1/responses`. The WordPress safe HTTP API uses server-only Bearer authentication, a 30-second timeout, certificate verification, zero redirects, no cookies, a 512 KiB response limit and bounded request bytes. The request retains disabled tools, streaming, background execution and provider response storage. A deterministic hashed `X-Client-Request-Id` supports tracing; it is not treated as a provider idempotency guarantee.

Successful HTTP delivery passes through the existing strict response/model/usage validator and atomic receipt/settlement operation. Non-success status, malformed response, timeout, network exception or receipt-audit failure preserves a conservative budget hold. A later invocation sees the claimed state and never resends. Even if the uncertainty audit fails, the original dispatched event retains the hold and prevents another send. Recovery requires trusted response evidence through the existing receipt operation; generic cancellation must not release potentially billed work.

## Server preparation

`TGIT_OPENAI_API_KEY` is read only on the server. `TGIT_OPENAI_ENABLED` must be strictly boolean true before the internal sender can operate; it defaults off when absent. Keep it off while the generation workflow and real model/pricing acceptance are unfinished. Adding a key or server switch alone creates no request, schedule or generation action. The visible API setup card continues to report preparation only, never verified account access or available generation. No key or digest is returned in public status.

The [OpenAI authentication reference](https://developers.openai.com/api/reference/overview) documents Bearer credentials and client request IDs; the [text generation guide](https://developers.openai.com/api/docs/guides/text) describes Responses usage. Model access, current prices, actual hosting and provider acceptance are not established by synthetic tests. This implementation adds no SDK dependency and makes no assumption that counting is free.

Next: trusted catalog/pricing/count-cost acquisition, explicit owner generation controls, publication and operational recovery. See [count transport](ai-count-transport.md). The authorization permits implementation and synthetic intercepted tests; it does not permit the assistant to send real portfolio data or use production credentials.
