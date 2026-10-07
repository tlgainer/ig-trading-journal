# 0.27.0-dev.1 test build — schema 17

This update fixes visibly inactive fundamental schedule controls, adds Research/Settings section shortcuts and API setup instructions, and updates the user guide for provider mappings, saved quotes, fundamentals, schedules and AI preparation. AI generation and OpenAI key entry remain unavailable.

Use `ig-trading-journal-0.27.0-dev.1-test.zip` on your staging copy after backing up the database and private images together. Upload through Plugins → Add New → Upload Plugin and replace the existing plugin. The stable top-level folder is `ig-trading-journal/`. The version changes so WordPress requests the updated CSS and JavaScript; reload the Investment Tracker afterward.

The database schema stays 17. If the preceding 0.27.0-dev test package activated successfully, no additional SQL or sequential packages are needed. For an older schema, explicitly deactivate/reactivate after backup to apply the bundled additive migrations. All 17 SQL files remain in the ZIP. Do not downgrade code against schema 17 or change its marker manually.

See [the updated user guide](user-guide.md#new-features-setup-and-everyday-use) for everyday workflows and [API key setup](user-guide.md#api-keys-and-server-configuration). Provider credentials are server-side wp-config.php constants, with separate explicit refresh switches. Adding credentials does not create mappings, schedules or AI reviews. No live API calls or production deployment were used to prepare this package.

This remains a development test build. Host compatibility, live provider entitlement, independent accessibility/performance and consistent backup/restore acceptance remain outstanding. Keep a matching code/database/private-media backup for recovery; migrations preserve history and quota/spending records.
