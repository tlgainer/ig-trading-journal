# 0.27.0-dev.12 test build - schema 18

Adds optional fee-aware risk-to-reward results to all six calculators. Profit scenarios accept stop loss; long/short sizing accepts take profit. Bought options use premium levels only in close-out mode. Results reuse the existing decimal profit scenarios, preserving full precision and non-posting behavior. Invalid level directions fail validation; fee-erased target rewards have no ratio.

Upload the test ZIP to replace the plugin and reload Investment Tracker. No database change. Existing profit/sizing workflows work with optional fields blank. This remains a development test build; full production acceptance remains outstanding.
