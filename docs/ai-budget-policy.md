# AI summary spending policy foundation

This is an internal, pure decimal policy milestone. It does not yet expose owner settings, save budget configuration, reserve spending atomically or generate summaries. No default model, live price catalog, credentials, network calls or paid processing are introduced. Development remains schema 12; no new SQL or ZIP is required.

`src/Domain/AiBudget.php` provides:

- Explicit model identifiers without fallback; USD input/cached-input/output prices supplied by a trusted server catalog with capability/access confirmation and official pricing-source evidence.
- Strict UTC verification/expiry timestamps. New reservations reject future, expired or more-than-30-day pricing validity. This is the plugin's refresh policy, not an OpenAI guarantee. Truthful pricing/access verification still belongs to the future catalog/application boundary.
- Reservation estimates using a confirmed input-token upper bound and maximum generated output tokens. Cache savings are not assumed: input uses the higher configured input rate. Costs round upward to twelve decimal places so tiny charges never disappear.
- Charge estimates using total input, its cached subset and total output, with captured dispatch-time prices. Reasoning tokens must already be included in total output, not charged twice. Unsupported/missing usage fails closed.
- Admission arithmetic using settled current-month estimates plus **all unresolved holds, including older months**. Zero pauses new requests; lowering a cap cannot erase accrued costs; warning levels are 80%, 90% and limit reached.
- Settlement arithmetic retaining unknown charges, releasing only a verified unused reservation and flagging cost overruns while retaining the full reported charge. The persistent layer must record overruns and stop further dispatch pending review; it must not silently clamp costs to the original estimate.
- Calendar-month boundaries fixed to America/New_York, with UTC storage timestamps and DST-aware resets. Budget timezone changes are not supported, preventing an artificial usage reset.

Amounts are estimated plugin spending, not guaranteed invoices or account-wide billing caps. Text-only summaries exclude tools, images, audio, web research, conversation continuation and alternate service tiers. Any later transport must enforce this scope; separately priced capabilities require their own verified cost bounds.

## Next integration contracts

Persist append-only configuration and request/pricing evidence in separate migrations. Serialize admission through a credential-wide lock and transaction across workspaces; this pure policy alone provides **no concurrency enforcement**. Keep uncertain requests reserved across monthly boundaries and do not resend uncertain delivery. Capture original model/prices/input fingerprint and authorizing membership on every request. Recheck authorization/configuration before dispatch; changing model affects future requests only. Credential changes must not silently create a fresh allowance or discard unresolved spending.

Proposed owner default remains 15.00 USD, editable including zero. External processing starts disabled; an explicit owner save and server enablement/key are required. Owner screens, model catalog verification, atomic reservations, evidence/thesis bundles, review persistence, response/citation validation and transport remain pending. No real keys are required for mocked development.

## Official API references checked October 6, 2026

The [token-counting guide](https://developers.openai.com/api/docs/guides/token-counting) documents input counting and total generated output, including non-visible tokens. The [Responses create reference](https://developers.openai.com/api/reference/python/resources/responses/methods/create) documents output limits and usage. A future adapter must account for its complete serialized prompt/schema and verified token-counting overhead; byte length or a characters-per-token guess does not establish the input bound.

Tests use fictitious model identifiers and prices exclusively, not published pricing or assertions of account access.
