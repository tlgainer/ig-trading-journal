# 0.27.0-dev.6 test build - schema 18

This development build adds explicit owner AI enable/generation controls, setup checks, saved-request recovery, cancellation and separate saving of completed summaries to Research history. Processing remains off by default. See [AI summary usage](ai-owner-generation.md) and [the user guide](user-guide.md).

Back up the database and private images together. Upload `ig-trading-journal-0.27.0-dev.6-test.zip` through Plugins > Add New > Upload Plugin and replace the installed plugin. The archive has one stable `ig-trading-journal/` folder. Explicitly deactivate/reactivate after backup to apply the bundled additive migrations through schema 18; no intermediate packages or manual SQL execution are needed. Reload Investment Tracker afterward. Do not downgrade code or manually change the schema marker. Restore matching code/database/private-media backups together if recovery is needed.

Keys stay in server configuration. Genuine verified model, pricing and counting-cost evidence is required before enabling generation; installing this ZIP alone does not enable external requests. This is a development test build, not production acceptance. Live API entitlement, production hosting compatibility, independent accessibility/performance and backup/restore acceptance remain outstanding.
