# Trusted server evidence for AI summaries

`AiConfiguration::current($model)` now reads trusted server records for the exact selected model and validates them against the current server key and UTC time. It returns private catalog, counting policy and exact decimal pricing for future generation orchestration. It makes no HTTP request, changes no spending, enables no processing and exposes no new browser route. Schema remains 18; no SQL or ZIP for this internal setup slice.

Two server-only PHP array constants are supported:

- `TGIT_OPENAI_MODEL_EVIDENCE`: keyed by exact model ID, each record follows the [catalog contract](ai-model-catalog.md): `pricing`, `credential_fingerprint`, `access_verified_at`.
- `TGIT_OPENAI_COUNT_EVIDENCE`: keyed by the same exact model ID, each record follows the [counting contract](ai-count-transport.md): `credential_fingerprint`, `model`, decimal-string `maximum_charge` equal to `0`, `verified_at`, `valid_until`, official `source` and boolean `access_confirmed`.

These are reviewed server evidence, not browser settings. A deployment maintainer may load an evidence file outside all public roots from `wp-config.php` and define the constants from its reviewed arrays. The file must be readable only by the appropriate administrator/PHP service account, excluded from source control and backups intended for sharing, and never exposed through public uploads. Do not place the API key, fingerprint or actual account evidence in this repository. No default model, rates, account access or free counting claim is supplied.

The pricing record contains exact model ID, USD currency, decimal strings for input/cached-input/output rates per million tokens, UTC verification/expiry timestamps, official source URL and confirmed Responses, structured-output and account-access capabilities. All timestamps use `Y-m-d H:i:s` UTC. Pricing and counting evidence expire within thirty days; model-access verification also expires after thirty days. Evidence must come from an actual account-specific verification and documented price/cost review. A successful model listing alone does not establish every required capability or counting entitlement. Simply setting confirmation flags does not perform verification.

Changing the key invalidates records bound to the previous fingerprint. Selecting another model requires its own complete evidence. Aliases and fallback models are never substituted. Missing, malformed, expired, future-dated, paid/unknown counting or mismatched evidence fails closed. Expired records for unselected models do not block a valid selected model. Validation is repeated on each load, so callers must load fresh evidence at count/admission/dispatch boundaries rather than cache a readiness result indefinitely.

The official counting documentation does not establish a zero charge. Until genuine current cost/access evidence is available, counting remains blocked. Supporting a charged count operation requires its own reservation and settlement lifecycle. The current loader provides a trusted configuration source; automatic acquisition and verification of those facts remains unfinished.

Keep `TGIT_OPENAI_ENABLED` off until genuine setup verification. Adding evidence creates no summary, schedule or request. Owner generation/publication and recovery controls are implemented; real account/hosting acceptance remains pending. Existing API setup status continues to describe credential preparation only; it does not report this internal metadata as authenticated access. Unit fixtures use synthetic keys/models/prices and isolated processes only.

The disabled internal [generation coordinator](ai-generation-coordinator.md) now loads this evidence at count, admission and dispatch boundaries. Guarded owner generation and enable controls are now available after explicit implementation approval; see ai-owner-generation.md.


October 8, 2026: Guarded owner enable/generation controls are now implemented after explicit approval. Keep server processing off until genuine setup verification is complete. See [owner workflow](ai-owner-generation.md). No account-specific verification is performed by installing this code.
