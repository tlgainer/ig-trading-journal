# ADR 003: Draft revisions and promotion

Status: accepted for build 0.3.0.

Draft edits require an expected revision and append immutable transaction_revisions and audit evidence inside a workspace-serialized transaction. Contributors edit only drafts they created; owners/managers may edit any workspace draft. Current membership is rechecked after acquiring the workspace lock.

Promotion creates a separate posted event using current ledger validation, then archives the source draft as promoted and appends a revision linking the posted event ID. All effects, source changes, revision/audit evidence and saved retry result commit atomically. Keeping a distinct posted ID preserves same-day committed financial order; the earlier draft ID never influences FIFO. Posted facts remain immutable.

Idempotency is scoped to workspace, operation and source ID. Expected revision is part of the request hash. Identical retries return the original result after authorization; changed bodies and stale requests conflict. Failed promotions roll back and leave the draft editable. Two different concurrent posting keys serialize: only one can promote.

Existing tables support these contracts; no migration is required. Historical posting stays blocked until correction and replay are implemented. Revision payloads provide source-to-posted linkage; explicit searchable links and richer history UI can be added later.
