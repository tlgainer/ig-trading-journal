# Next requirements: accounts, leveraged scenarios and derivatives

Owner-requested additions, October 5, 2026. These extend the original PRD; they are not claims of delivered financial support. Existing decimal-string, workspace-scope, immutable-posting, replay, privacy and separate-SQL contracts remain mandatory.

## Delivery order

1. Finish Holdings/Transactions readability using the shared tables. Transactions already use the shared table in 0.18.0; Holdings is delivered in 0.19.0. Preserve account names, exact values, missing-price/basis statuses, whole-result search and scoped preferences.
2. Extend non-posting calculators for leveraged crypto, stock long/short profit and short-position risk, then bought-option premium/expiry scenarios. Linear leveraged crypto is delivered in 0.20.0; stock scenarios are next. Keep calculations separate from ledger posting.
3. Design subaccounts, USDT/USDC currency identities and settlement conversions.
4. Implement instrument-specific margin/derivative accounting and reports only after product/contract rules are confirmed. Do not treat derivative exposure as owned spot units.

## Brokerage/exchange subaccounts

- Represent separately funded exchange subaccounts and their parent broker/exchange; support descriptive purpose labels such as long-term/short-term.
- Keep balances, holdings, collateral and journal relationships scoped to their actual subaccount. A shared balance must not be duplicated into virtual strategy accounts.
- Allow combined portfolio views with account/subaccount filters.
- Transfers need linked, auditable legs and reconciliation; they must not inflate deposits, equity or returns.

## USDT and USDC

- Replace the ISO-only three-letter currency restriction with a reviewed currency/asset identity model supporting USD, USDT and USDC as distinct units, plus relevant network identity where needed.
- Distinguish quote currency, settlement currency, collateral currency and workspace reporting currency.
- Use sourced, dated conversion observations for base valuation. Do not assume a stablecoin permanently equals one USD or relabel stablecoin cash as USD.
- Plan additive schema changes in separate docs SQL, with backup, forward repair and exact-decimal isolation tests before release.

## Leveraged crypto scenario calculator

Delivered in 0.20.0: hypothetical linear long/short, collateral times leverage, same-currency entry/exit costs and non-negative borrowing/funding costs; exposure, equivalent units, gross/net profit and return on collateral. Liquidation remains unavailable. Inverse products, settlement conversions, funding credits and actual positions remain unsupported; the ISO currency restriction is unchanged.

- Inputs: product type, long/short direction, entry/exit price, collateral, leverage or notional exposure, quote/settlement units, entry/exit fees and applicable borrowing/funding costs.
- For a simple linear contract, show exposure, equivalent units, gross/net P&L and return on entered collateral. Explicitly distinguish collateral from notional; $2,000 collateral at 2x means $4,000 exposure.
- Provide both favorable and adverse price scenarios. Do not present the spot calculator's percentage on notional as return on collateral.
- Product-specific handling is required for inverse contracts, funding, isolated/cross margin, maintenance margin and liquidation. Do not derive a real liquidation price from leverage alone; show unavailable without the required contract/account inputs.
- This calculator must not open a position, borrow cash or change balances.

## Stock profit and short-position risk calculators

- Long profit: quantity × (exit − entry), less entered costs.
- Short profit: quantity × (entry − cover), less entered fees, borrow charges and applicable dividend payments.
- Short risk sizing uses an adverse stop above entry, with explicit risk budget and assumptions.
- Distinguish selling owned shares from opening a short. Existing ledger sell operations cannot be reused to simulate short positions.
- Margin, borrow availability, corporate-action obligations and short position accounting are separate ledger requirements.

## Bought-option scenarios (owner-confirmed scope)

- Support bought calls/puts only for this phase, contract count and configurable contract multiplier. Standard equity options often use 100, but adjusted contracts require their actual multiplier.
- Sold/written options and multi-leg spreads are deferred; they are outside the currently authorized calculator scope.
- Basic close-out P&L uses entry/exit **option premiums**, contracts, multiplier and fees. It must not substitute the underlying stock price for an option premium.
- Expiration payoff uses call/put, strike, underlying expiry price, premium, contracts and multiplier; distinguish payoff from profit.
- Pre-expiry valuation needs either a user-supplied expected option premium or an explicitly labeled model with time to expiry, volatility and other assumptions. A stock price target alone is insufficient.
- Design multi-leg spreads as a later extension with per-leg direction, strike, expiry, premium and multiplier. Do not claim one-leg scenarios model all options strategies.
- Actual option ledger support requires instrument identity, opening/closing actions, expiry, exercise/assignment and resulting cash/stock events. Scenario support does not imply those accounting workflows are delivered.

## Margin and derivative ledger

- Confirm whether the owner's BloFin activity is perpetual futures, spot borrowing or both; determine linear/inverse contracts and isolated/cross margin before finalizing the model.
- Preserve position direction, size, contract identity, collateral, debt, accrued interest/funding, commissions, realized/unrealized P&L and liquidation/close evidence as separate concepts.
- Reports must distinguish owned assets from exposure and liabilities; leveraged notional must not be counted as owned portfolio equity.
- Require deterministic reconciliation, replay/revision, workspace isolation and failure/rollback fixtures before financial posting is enabled.

## Acceptance and unresolved choices

- Example: BTC entry 75000, exit 82500, spot principal 2000 gives approximately 0.026666666666666666 units and $200 gross P&L; collateral 2000 at 2x linear exposure gives $400 gross P&L. A move to 67500 instead gives approximately −$200/−$400 respectively. Costs and contract details are excluded from these examples.
- Example: short 10 shares at 100, cover at 90 gives $100 gross P&L; cover at 110 gives −$100, before costs.
- Example: buy one option at premium 2 and close at premium 3 with multiplier 100 gives $100 gross P&L, before fees. Stock price alone cannot determine that exit premium.
- Existing ledger and calculators must remain backward compatible; no float financial arithmetic or silent rewrite of posted history.
- Still to confirm: BloFin product/margin types, supported contract units, actual derivative ledger entry. Bought call/put scenarios are authorized; sold options remain deferred. Short/options calculators are owner-requested; actual short/options financial accounting needs its own reviewed scope.

## Educational references

- [CFTC: virtual currency trading risks](https://www.cftc.gov/LearnAndProtect/AdvisoriesAndArticles/understand_risks_of_virtual_currency.html): leverage amplifies gains and losses; contract/margin rules matter.
- [Investor.gov: stock purchases and sales, long and short](https://www.investor.gov/introduction-investing/investing-basics/how-stock-markets-work/stock-purchases-and-sales-long-and): distinguish owned-share sales from short selling.
- [Investor.gov: introduction to options](https://www.investor.gov/introduction-investing/general-resources/news-alerts/alerts-bulletins/investor-bulletins-63): option premiums, underlying price, strike and expiration are distinct inputs.
