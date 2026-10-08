# Verified AI execution manifests

Development source schema 18 adds immutable full-request evidence. The latest 0.27.0-dev.1 test ZIP still contains schema 17; this milestone creates no new ZIP and enables no external processing.

The server count verifier accepts an actual successful input-count response and trusted execution metadata identifying the exact count projection, complete request, credential and UTC execution time. Counts must be positive bounded integers and less than five minutes old. Ambiguous JSON, duplicate fields, extra fields, different credentials and changed instructions or schema fail closed. Metadata is server provenance, never a browser assertion.

Reservation rebuilds the request from exact stored approved evidence, configured model and full output-token ceiling. It atomically stores the complete request, count receipt and verified conservative cost alongside the reservation and audit event. Identical retries retain the original record and hold; changed counts or missing execution evidence cannot replace it. Dispatch rechecks count freshness and agreement with captured model, prices, evidence, token bounds and reserved cost, plus existing consent, catalog, budget and owner checks.

Future HTTP senders must require complete execution evidence when claiming a request (`dispatch(..., true)`). Legacy internal reservations remain readable and acquire no fabricated prompt binding. The private manifest reader is not a REST response and must never expose approved evidence through generic browser budget/status APIs. External counting itself must first honor consent and the disabled processing gate; no count transport or generation controls are implemented here.

The [official token-counting guide](https://developers.openai.com/api/docs/guides/token-counting) describes complete input counting, including instructions and schemas. The [count endpoint](https://developers.openai.com/api/reference/resources/responses/subresources/input_tokens/methods/count) returns the count without an execution-fingerprint echo, so the trusted sender must associate it with its exact request. Account availability and counting costs still need verification; counting is not assumed free. Full output capacity, including reasoning tokens, is reserved using verified model-specific pricing.

## Upgrade and forward repair

Back up the database and private image storage before activating compatible schema-18 source. Keep all eighteen bundled migrations, including [018-ai-execution-manifests.sql](018-ai-execution-manifests.sql). The installer replaces `{{prefix}}`; do not run the placeholder SQL unchanged. Reactivation adds an InnoDB table without rewriting older financial, approval, request or receipt history. Deactivation/uninstall preserve records. For failed upgrades, restore service with a backup or repair forward and repeat activation; never drop historical tables to clear a failure.

The disabled internal Responses sender and local credential setup are now implemented; see [sender contract](ai-http-transport.md). Remaining: consent/budget-gated count transport, verified catalog/pricing acquisition, explicit owner generation actions, operational recovery and real model/hosting acceptance. Daily-change compatibility and related news/events remain separate requirements.
