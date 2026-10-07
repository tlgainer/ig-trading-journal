# Immutable approved AI evidence (schema 14)

The internal `AiEvidencePreview::approve` operation records an owner's explicit approval of an exact preview fingerprint. It is separate from workspace consent, model/budget policy, summary generation and external processing. It neither spends funds nor makes a request. REST/UI approval controls remain pending.

Approval resolves the same explicitly selected workspace/stock snapshots and optional expected current thesis revision under the workspace command lock. The newly captured fingerprint must exactly match the reviewed fingerprint. Changed evidence or a stale journal revision requires a fresh preview and approval; submitted payload text cannot replace saved sources. Approved canonical JSON, version, SHA-256 fingerprint, asset/workspace and approving actor are append-only in `ai_evidence_bundles`, with an audit event in the same transaction.

The retry identity is scoped by workspace and operation and bound to the original actor, asset, sorted snapshot selection, fingerprint and thesis revision. An identical retry returns the original approval metadata even after the journal changes; it does not renew approval or replace its contents/time. Changed facts or another actor using that same identity conflict. A distinct explicit approval can create a new immutable record. Audit or idempotency failure rolls back the whole operation.

Owner-only `approved` reads resolve within the current workspace and verify the stored version/fingerprint before returning the saved bundle. History retains its original content after later journal revisions or membership revocation; a revoked reader is denied. No edit/delete/purge operation is provided. Corrupted storage fails closed.

Future dispatch must separately recheck current consent, model/pricing/access evidence, budget and the original approving owner's authority. Saving an approval does not authorize a future network call by itself. New reviews must point to the immutable approval ID/fingerprint; saved generated review history and output/citation validation are still pending.

## Upgrade and forward repair

Back up the database and private media, disable external processing, deploy compatible development code with **all fourteen** SQL migrations and explicitly reactivate. Schema 14 adds only `docs/014-ai-evidence-bundles.sql`; the installer accepts schema 13 and preserves earlier evidence/spending records. Manual SQL must replace `{{prefix}}` with the configured prefix (confirmed hosting uses `wp_`). Never run a placeholder script unchanged.

If activation fails, preserve the backup/history, inspect the reported database permissions or missing bundled file, correct the cause and repeat explicit activation with the complete matching code/migrations. Do not downgrade the schema marker or delete approvals to repair the upgrade. Deactivation/uninstall continue to preserve data. The previously released 0.26.0/schema-8 ZIP is unchanged; this is development schema 14 and no new release ZIP or production migration is performed.

Synthetic integration fixtures cover upgrades/repetition, exact approved bytes, canonical retries, stale fingerprints/theses, membership/scope, audit rollback with safe retry, immutable history and damaged-bundle rejection. Temporary fixture corruption is restored; no production data, credentials or paid requests are used.
