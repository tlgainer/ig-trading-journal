# AI model catalog verification contract

The internal `AiModelCatalog` gate accepts only server-owned evidence keyed by the exact model ID. Each entry contains `pricing`, `credential_fingerprint` (SHA-256), and `access_verified_at` (UTC). Pricing uses the strict AiBudget contract, including Responses/structured-output capabilities, official documentation source, exact decimal prices and a maximum thirty-day verification lifetime.

Access evidence must match the current credential fingerprint and be neither future-dated nor thirty days old. Key rotation invalidates access evidence without resetting shared spending. Model aliases are not substituted, unknown entries fail closed, and an empty catalog enables no models. This gate makes no network request and does not itself verify provider access. Its trusted caller must obtain genuine access evidence; browser payloads must never supply verification flags, prices or fingerprints.

This milestone adds no configured defaults, API-key input, REST endpoint, Settings controls or paid transport. The next step is to connect the catalog gate to owner controls and dispatch verification. Until that connection exists, the internal spending service continues to accept its existing trusted pricing argument; this class alone does not enforce credential binding there.

Official model documentation was inspected on October 6, 2026: [GPT-5.4 mini](https://developers.openai.com/api/docs/models/gpt-5.4-mini) and [GPT-5.4 nano](https://developers.openai.com/api/docs/models/gpt-5.4-nano). No prices or account-access assumptions are bundled from those pages. Future catalog entries require a fresh documented review and account-specific access evidence.

Validation uses synthetic models, decimal prices and fingerprints only. No database migration, provider call, credential or production deployment is required.

Owner model/budget preparation controls are now implemented separately; their model ID input does not create verification evidence. Catalog-to-dispatch binding remains pending. See [AI Settings](ai-settings.md).
