# Linear leveraged scenarios

Build 0.20.0 adds a private, non-posting linear crypto scenario calculator. Inputs are long/short direction, entry/exit price, collateral, leverage and non-negative entry/exit/borrowing/funding cost amounts. Every amount uses one entered ISO currency; stablecoin identity and settlement conversions remain separate work. Existing spot and long-risk endpoints are unchanged.

Exposure equals collateral times leverage. Gross profit uses exposure times signed price change divided by entry, preserving full decimal intermediates rather than using rounded displayed equivalent units. Net profit subtracts entered costs; return uses entered collateral as its denominator. Domain code uses decimal strings and BCMath with no WordPress dependency. Only output boundaries round monetary values.

The model assumes the entered exit is reached. It does not predict liquidation or account for inverse contracts, cross/isolated margin rules or unentered costs. Liquidation is explicitly unavailable, and losses can exceed entered collateral. No balance, holding, transaction or derivative position is created. Scenario responses retain authorization and no-store behavior; workspace switches discard late calculator results.

Owner scope for later options work is bought calls and puts only: premium close-out and expiration payoff scenarios with editable contract multiplier and explicit costs. Sold options and multi-leg spreads remain deferred. Stock long/short profit and short risk precede bought options in the delivery list.

Schema remains 8; no SQL migration or database update is required. All eight bundled SQL files remain in the package. Temporary fixtures, screenshots and the local personal setup guide are excluded from packaging and Git.

Validation: 43 unit checks and 82 disposable WordPress/MySQL integration checks passed, including linear gain/loss/cost arithmetic, zero exits, invalid direction/leverage/decimal inputs, unauthorized workspace access and zero ledger events. PHP/JavaScript syntax, Composer coding standards and six REST URL checks passed. The real browser fixture covers favorable/adverse exits, short direction, fees, accessible validation feedback, mobile overflow and late workspace responses. All eight admin sections and existing spot/research browser workflows are checked on the disposable site. Actual-host, full accessibility, sustained performance and restore acceptance remain separate gates.
