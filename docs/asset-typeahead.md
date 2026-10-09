# Asset search and ticker suggestions

Development source: 0.27.0-dev.8, schema 18.

In Settings > Accounts and assets, use the ticker/company search beside Symbol. Type a ticker (AAPL) or company name (Apple), then choose a suggestion to populate Symbol. Confirm exchange, asset class and quote currency yourself before saving. The public file does not supply these facts. Custom symbols remain available through manual Symbol entry; see crypto suggestions below.

Asset selection fields across opening balances, transactions, journals, watchlists, research, fundamentals, manual prices, report filters and market-data mappings have the same search helper. These suggestions select only an existing saved asset from the current workspace. Type its ticker or company name and choose a suggestion; the original dropdown is also available. Clear the search or press Escape to return to the original field. Unmatched search text cannot silently select or create an asset. Add a new asset in Settings first.

The source is https://terrellgainer.com/tickers.json. The server reads this fixed HTTPS URL with certificate validation, no redirects, bounded time/bytes and a 24-hour cache. The source receives no portfolio data or search text. Company-name matching supplements existing saved symbols; titles are suggestions, not verified provider mappings. If the file is unavailable, saved dropdowns and manual entry still work. The list is not complete coverage of every market, ETF, crypto or derivative. No database migration is added. Use the 0.27.0-dev.8 test ZIP; earlier installation ZIPs do not include this change.


## Crypto suggestions

Choose Crypto in the asset form before searching. Type a coin symbol (BTC), name (Bitcoin) or catalogue ID (bitcoin), then choose a suggestion. The source is https://terrellgainer.com/coins.json, cached separately for 24 hours with the same bounded HTTPS transport and workspace access rules as stocks. The source receives no search terms or portfolio data.

Repeated symbols are preserved as distinct choices labelled with name and catalogue ID. Selection fills only Symbol; catalogue IDs and coin names are not currently stored as asset identity. Confirm the coin/network, exchange and quote currency yourself. Existing saved crypto asset searches include possible matching coin names/IDs, but a name match does not verify which coin the saved symbol represents. The original workspace asset ID remains authoritative. Stock names never decorate crypto assets, and coin names never decorate stock assets. Unavailable lists do not prevent manual entry or use of the original dropdown.
