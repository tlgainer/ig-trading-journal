# Asset search and ticker suggestions

Development source: 0.27.0-dev.7, schema 18.

In Settings > Accounts and assets, use the ticker/company search beside Symbol. Type a ticker (AAPL) or company name (Apple), then choose a suggestion to populate Symbol. Confirm exchange, asset class and quote currency yourself before saving. The public file does not supply these facts. Custom symbols and crypto remain available through manual Symbol entry.

Asset selection fields across opening balances, transactions, journals, watchlists, research, fundamentals, manual prices, report filters and market-data mappings have the same search helper. These suggestions select only an existing saved asset from the current workspace. Type its ticker or company name and choose a suggestion; the original dropdown is also available. Clear the search or press Escape to return to the original field. Unmatched search text cannot silently select or create an asset. Add a new asset in Settings first.

The source is https://terrellgainer.com/tickers.json. The server reads this fixed HTTPS URL with certificate validation, no redirects, bounded time/bytes and a 24-hour cache. The source receives no portfolio data or search text. Company-name matching supplements existing saved symbols; titles are suggestions, not verified provider mappings. If the file is unavailable, saved dropdowns and manual entry still work. The list is not complete coverage of every market, ETF, crypto or derivative. No database migration is added. Use the 0.27.0-dev.7 test ZIP; earlier installation ZIPs do not include this change.
