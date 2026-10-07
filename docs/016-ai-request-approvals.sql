-- Schema 16: exact immutable approval identity on AI reservations.
-- Back up database and private bytes; keep processing disabled during upgrade.
-- Keep all sixteen bundled SQL files and reactivate to run the additive installer.
-- Forward repair: restore compatible code and rerun activation; preserve requests/events.
-- Existing reservations remain NULL; never infer approval IDs from matching fingerprints.
-- Manual execution: replace {{prefix}} with the configured WordPress table prefix.
-- Run once only; the installer checks existing column compatibility before skipping it.
ALTER TABLE {{prefix}}tgit_ai_requests ADD COLUMN approval_id bigint unsigned NULL;
