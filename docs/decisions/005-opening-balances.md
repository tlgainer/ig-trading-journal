# ADR 005: documented opening balances before ordinary posting

The first ledger event in an account may describe documented starting cash or pre-existing asset lots. A lot is not a buy: recording it must not debit account cash. It retains its original acquisition date for FIFO order and a source note for reconciliation. An opening date groups all starting entries for the account.

Opening cash and lots are immutable posted transactions with revisions, legs, audit events and a separate `tgit_opening_balances` provenance row. The additive schema is `../003-opening-balances.sql`. The existing workspace lock and idempotency contract serialize their creation with other financial posting.

An unknown cost basis cannot be represented as a real zero. The stored placeholder is paired with `basis_status=unresolved`; holdings show null remaining basis and sales of that account/asset are blocked. A documented zero basis uses `complete` explicitly and may be sold. A later resolution will need its own authorized revision and replay contract.

Opening entries are accepted only before any ordinary posted transaction in that account. Retroactively inserting them would change later cash and FIFO outcomes; that belongs to the correction/replay feature. Existing posted rows are never edited by this migration. Activation is repeated after a database backup to upgrade schema 1 or 2; a failed additive DDL attempt is repaired forward, as described in `../operations.md`.
