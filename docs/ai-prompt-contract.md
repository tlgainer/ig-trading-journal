# Saved fundamental review request

Development internal foundation, `ai-prompt-1`. No new migration, transport, generation control or paid processing is enabled.

`AiPrompt::build` accepts the exact saved approval JSON and checks its SHA-256 before building a deterministic Responses request. It never refreshes evidence or silently adds a newer journal revision. The exact model and total output-token bound are supplied explicitly; model access, pricing, owner consent and budget authorization remain the application's responsibility.

The request contains instructions, one user text message containing the approved JSON, and a strict JSON response schema for summary and cited findings. Citation IDs are restricted to approved snapshots. Tools, streaming, background processing and provider response storage are disabled; default service tier is explicit. There is no conversation or previous response, external URL, image or news input. Thesis text remains untrusted data. These instructions do not establish semantic accuracy or guarantee prompt-injection resistance; response validation and human review remain necessary.

Two fingerprints are returned: the complete execution request (including the output bound), and the input-count projection (model, instructions, message content, schema, tools and tool choice). Changing the output bound changes the execution fingerprint without changing identical input. Changing approved evidence changes both. Evidence byte length is never used as a token estimate.

The [official token-counting guide](https://developers.openai.com/api/docs/guides/token-counting) explains that complete input includes message framing, instructions and schemas. The [input token count endpoint](https://developers.openai.com/api/reference/resources/responses/subresources/input_tokens/methods/count) returns a token count rather than echoing a request fingerprint. Future trusted server counting must therefore associate the receipt with this exact projection, credential, model and freshness before reserving cost. Counting itself sends evidence externally and must honor consent and the disabled processing gate. Endpoint cost and account availability must be verified; this milestone makes no assumption that counting is free.

Remaining: trusted count receipt verification, immutable execution manifest persistence linked atomically to reservation, dispatch revalidation of the manifest, guarded HTTP transport and explicit owner generation controls. Existing internal reservations do not acquire a full prompt binding retroactively. Do not expose these pure builder inputs as a browser generation API.

Validation: deterministic fixtures cover exact evidence preservation, count projection completeness, output-bound changes, thesis changes, approved source enumeration, invalid fingerprints/models/bounds and malformed/duplicate evidence. Full hosting and real model acceptance are not established by these fixtures.
