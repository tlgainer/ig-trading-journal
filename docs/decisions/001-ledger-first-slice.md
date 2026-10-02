# ADR 001: exact native-currency ledger with explicit workspace context

Status: implementation choice for the first development slice; target-host and migration choices await the owner.

Requirements: TX 01/02/07-09, CAL 01/03/04, ACL 01-04, API 01-04, SEC 01, DEV 02/04.

Use PHP BCMath with decimal strings, 36-place intermediate arithmetic, 18-place quantities/prices and 12-place posting amounts. Half-up rounding is applied at the monetary storage boundary; display-level minor-unit formatting remains future work. Product limits are checked after multiplication and before database persistence. Basis allocated from a partially used lot is rounded to monetary storage precision; exhausting a lot consumes all residual basis. Sale proceeds are apportioned over allocations with the final allocation carrying the residual, conserving totals.

Financial calculations are independent of WordPress. Application services receive an explicit actor and database repository; every relationship uses workspace ID. Owner/manager/contributor/viewer grants are contextual `tgit_*` permissions, not global WordPress tenant ownership. `manage_options` authorizes creating a workspace, which explicitly and audibly grants its creator owner membership, but never reading an existing workspace.

Serialize writes through the workspace row with an InnoDB transaction. Recheck membership after taking the lock. Validate foreign IDs, account/asset currency, chronological ordering, cash and available lots. Insert transaction, initial revision, quantity/cash leg, lot changes, audit and idempotency result in one commit. An identical retry returns the original result; a different payload using the same key is a conflict. Failed operations roll back and do not consume keys.

This deliberately small slice blocks earlier-dated entries per account and omits mutation of posted history. Chronological replay and immutable superseding revisions must exist before historical corrections/import. Date-only values remain date-only; transaction IDs provide stable same-day commit order. Drafts are valid saved non-posting records; editing and promotion must use expected revisions in the next slice.

Native reporting is grouped by asset/account/currency. Base-currency aggregation, manual prices, FX and valuation observations must be implemented before aggregate dashboard values. Missing values are `null`, with explicit coverage labels. There is no assumed FX rate of one for mismatched currencies.

Workspace serialization trades throughput for understandable safety in the initial small installation. Measure larger workloads before replacing it with account-level locking and ordering. External interfaces for private media, providers, jobs, imports and entitlements will land alongside their first vertical slices; there are no placeholder implementations that imply unsupported behavior.
