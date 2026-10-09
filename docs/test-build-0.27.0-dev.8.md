# 0.27.0-dev.8 test build - schema 18

Adds crypto symbol/name suggestions from coins.json to the shared asset search. Distinct catalogue IDs distinguish repeated symbols; stock and crypto names are isolated. See [asset search instructions](asset-typeahead.md). Search suggestions do not create assets, infer exchange/currency or verify provider mappings. Crypto and manual entry remain supported.

Back up database and private images together. Upload `ig-trading-journal-0.27.0-dev.8-test.zip` through Plugins > Add New > Upload Plugin and replace the installed plugin. The archive uses the stable `ig-trading-journal/` root. Schema remains 18; users already on dev.6/dev.7 need no database update. Older schemas can upgrade directly by explicit deactivation/reactivation after backup, without intermediate packages or manual SQL. Reload Investment Tracker to use the new asset search script.

This remains a development test build. Real provider/AI entitlement, production hosting compatibility, independent accessibility/performance and backup/restore acceptance remain outstanding. AI processing and its existing setup requirements are unchanged.
