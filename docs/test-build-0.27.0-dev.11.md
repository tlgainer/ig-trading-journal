# 0.27.0-dev.11 test build - schema 18

Price refresh failures expose a safe HTTP status, error category and explanation, including symbol/endpoint access denial, subscription restrictions, authentication and throttling. No raw provider body, URL or key is exposed. Existing failed requests retain generic saved status; new explicit refreshes receive diagnostics. Retry and quota rules are unchanged.

Upload the test ZIP to replace the plugin and reload Investment Tracker. No schema change. This is a development test build; live provider entitlement and production acceptance remain outstanding.
