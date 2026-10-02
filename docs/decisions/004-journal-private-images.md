# ADR 004: Journal-first priority and private local images

Status: accepted for development build 0.4.0. Requirements: JR 01-03, MED 01-09, ACL 01-04, API/OPS contracts.

The owner explicitly ordered journals/strategies/multiple private images before the remaining correction/replay/opening-balance work. This overrides the original delivery suggestion while preserving the ledger's immutable posting and no-backdating boundaries.

Trade groups and fill links are separate from posted financial rows. One workspace transaction belongs to at most one trade and must match its asset. Linking/editing journals cannot change cash/lots. Promotion moves a linked draft to its new posted fill, bumps the trade revision and preserves its history/gallery. Draft asset/action changes require unlinking first.

Journal payloads and strategy versions are immutable snapshots. Trades capture an explicit strategy-version identity; later library edits do not rewrite rationale. History is cursor paginated with at most 20 revisions/page. Basic rich text is allowlisted without scripts, remote embeds or event handlers; original sheet confluence text is preserved as escaped plain data. Images and journals have independent revision/capability checks.

Private local storage is the first adapter. It requires a configured directory outside every known public root, GD, random workspace-scoped keys and authenticated binary delivery. The UI uses blobs after cookie/nonce authentication. No attachments, public derivatives, external OCR/AI or archival originals are introduced. JPEG/PNG/WebP are decoded within file/pixel/memory bounds and re-encoded; originals and thumbnails have bounded edges and EXIF/geolocation is stripped.

Owner policy is explicit before any reservation. PRD defaults (20 images, 10 MiB, 40 MP, 30-day trash) and an initial 1 GiB quota proposal are prefilled, not silently activated. Quota/count reservation is workspace serialized. Ready bytes replace reservation accounting; changed settings are rechecked before normalization. Failed-file replacement reuses the slot and requires the current revision. Ready images cannot be replaced through that operation.

Journal persistence precedes independent image uploads. Normalization is synchronous per file in this slice; durable ready/failed evidence and same-key retries handle ordinary failures. A tested post-encoding database failure removes generated files and leaves a retryable record. Upload/validation/processing stages are transactional, not an asynchronous leased worker claim. Fully asynchronous processing, crash/orphan reconciliation and richer job monitoring remain operational gates.

Deletion blocks access immediately while retaining bytes/count within quota until expiry. Bounded WordPress cron jobs carry workspace and authorizing owner, recheck current membership, and audit physical cleanup; revoked owners cannot run jobs. Abandoned reserved/failed uploads expire after 24 hours. Backup retention is the operator's policy and restore evidence remains the fourth priority.

Schema 2 is additive via `002-trade-journal-media.sql` with explicit reactivation after backup. Old schema-1 code is not a valid rollback for the new workflow. No actual production schema/storage change or deployment is authorized by this development check-in.
