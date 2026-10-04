# ADR 009: Manual watchlists and authored research

Watchlist targets and Buy/Sell/Hold labels are explicitly user-authored. They never post transactions, generate recommendations or trigger automatic alerts. A watchlist item references an existing workspace asset, which includes symbol and exchange/network identity; duplicate item assets in one list are refused. Item changes require an expected revision and retain immutable prior payloads.

Research notes are sanitized plain text with separate append-only revisions. Provider observations, fundamentals and price history require distinct tables with timestamps, source and licensing policy before integration. Neither user notes nor watchlist targets can masquerade as a current market quote. Every read and relationship check carries workspace context; site administrators still require explicit membership.
