# Approval-bound AI execution foundation

Development schema 16 adds a nullable `approval_id` to immutable AI requests. Admission stores the exact approval identity together with its fingerprint and original authorizer inside the existing shared spending lock and transaction. Retrying the same key with another approval fails even when approved bytes are identical. Approval, catalog, reservation event and audit failures roll back together.

Bound admission and dispatch require server-owned `AiModelCatalog` evidence matching the current credential, exact configured model and captured pricing fingerprint. Missing, expired, rotated-credential or changed-price evidence fails closed. Dispatch also retains existing owner, consent, configuration revision, month, expiry, overrun and shared-budget checks. A bound request cannot bypass catalog validation by using the legacy dispatch signature. No network call occurs.

Immutable approved bytes are used as originally reviewed; later journal changes do not silently replace the thesis. The original approval authorizer must be the requesting owner. Saved review writes and reads reject a different approval identity for a bound request, including identical-fingerprint approvals.

Legacy requests remain NULL and historical storage remains readable; the migration does not fabricate approval provenance. Legacy internal spending fixtures still work, but a future transport must reject unbound requests. There is no generation endpoint or transport in this milestone. Server-verified input token bounds must include the complete future prompt/format envelope; bundle byte limits are not token counts. Provider response/model/usage verification, prompt contract, generation controls, quarantining and semantic checks remain pending. Browser inputs cannot supply pricing, credential evidence or token-verification claims.

## Upgrade and forward repair

Back up the database and private image bytes, keep processing disabled, package all sixteen SQL files and reactivate compatible development code. [016-ai-request-approvals.sql](016-ai-request-approvals.sql) is executable once manually after replacing `{{prefix}}`. Prefer the installer: repeated activation checks the existing column type/nullability/default and preserves requests/events. A partial upgrade is repaired by rerunning activation with matching code and all migrations, never deleting or backfilling request history. No released ZIP or production database changed.
