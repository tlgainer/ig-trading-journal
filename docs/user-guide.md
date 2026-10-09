# IG Trading Journal user guide

Current development source: **0.27.0-dev.9/schema 18** includes asset typeahead and default-off owner AI generation controls. See [asset search](asset-typeahead.md), [AI summary setup](ai-owner-generation.md) and [test-build installation](test-build-0.27.0-dev.9.md). Versioned rollout notes below describe earlier builds.

For **plugin 0.27.0-dev.1 test build (schema 17)**, used inside WordPress administration. This guide describes the current screens; later UI updates may change their layout. Base currency and timezone for new workspaces default to **USD** and **America/New_York** (EST or EDT according to the date).

For an **on-demand price check**, open **Settings → Stock market data** and click **Refresh price** beside the stock's enabled provider mapping. Provider keys and refresh must be enabled first. Each check shares the provider allowance with scheduled work. It retrieves the latest available end-of-day session, so repeated checks can return the same price; it is not a live quote. Reloading saved data uses no provider request. If a request is uncertain, use its existing retry action rather than creating another request.

## Contents

- [New features: setup and everyday use](#new-features-setup-and-everyday-use)
- [API keys and server configuration](#api-keys-and-server-configuration)
- [Getting started](#getting-started)
- [Navigation and tables](#navigation-and-tables)
- [Accounts, assets and starting balances](#accounts-assets-and-starting-balances)
- [Transactions](#transactions)
- [Overview](#overview)
- [Trade Journal](#trade-journal)
- [Strategies](#strategies)
- [Private images](#private-images)
- [Research and watchlists](#research-and-watchlists)
- [Calculators](#calculators)
- [Prices, FX and reports](#prices-fx-and-reports)
- [Members and access](#members-and-access)
- [Troubleshooting](#troubleshooting)
- [Backups and current limits](#backups-and-current-limits)

## New features: setup and everyday use

Research and Settings have a top menu of section shortcuts. Use these to jump straight to Stock fundamentals, API setup, Stock market data or AI settings. They move within the page without discarding a form or starting a request. Hidden role-restricted sections are excluded. On smaller screens the shortcuts wrap.

For your Merrill and Interactive Brokers stocks:

1. Check the stock's symbol, exchange and quote currency in Settings → Accounts and assets. Crypto is not supported by these stock data adapters.
2. Configure the server keys as described below. Then open Settings → Stock market data, select a stock and provider, verify its provider symbol against the exchange/currency, add a verification note, and save the mapping. A mapping identifies the stock; saving it does not fetch data.
3. Once the server provider is enabled, use the mapping table's Refresh action for an end-of-day price. In Overview, select that provider as the stock price source. Saved quotes supply market value and unrealized gain/loss for covered open stocks. Totals cover all authorized accounts and remain grouped by currency. Missing/stale prices and unknown basis remain explicit. Daily price change is not available yet.
4. For fundamentals, create an enabled **Alpha Vantage** mapping even if FMP is your price source. In Research → Stock fundamentals, choose the stock and dataset, then **Refresh selected dataset**. Company overview, income statement, balance sheet and cash flow are separate requests. FMP does not supply this fundamental workflow.
5. **Reload saved history** reads local snapshots without using quota. Choose **View** beside a snapshot to read its saved facts and metrics. Unavailable values are not zero. Period changes compare the previous available period and do not establish verified year/quarter growth.
6. To automate fundamentals, choose a dataset, set **Weekly**, choose a weekday, and save. Repeat separately for the datasets you want. **Off** disables automatic refresh; the weekday dropdown is disabled while Off. Saving a schedule does not fetch immediately. A saved schedule can remain pending while server refresh is disabled.

Start with on-demand refresh for a few stocks. Alpha Vantage's 25 requests per rolling 24 hours are shared between quotes and fundamentals across workspaces; four fundamental datasets for ten stocks would require 40 requests. FMP has a separate 250-request allowance. Scheduled work reserves room for on-demand requests. Spread stocks/datasets across weekdays rather than scheduling the entire portfolio together. Provider endpoint access must also be available under your plan; a key and quota do not establish entitlement. Site cron, holidays and provider availability can delay or skip work.

**AI preparation versus generation:** Settings → AI settings lets you save the model, monthly budget and separate workspace consent. Research → AI evidence lets you preview and approve saved statements and an optional saved trade thesis. These operations send no data to OpenAI and spend nothing. **Saved AI reviews** reads previously stored reviews; a new installation normally has none. This build cannot generate a summary, research news or monitor related-company events yet. Do not expect approval or consent to create a review.

## API keys and server configuration

There is currently no API-key entry form. A WordPress/server administrator adds provider keys to `wp-config.php`, before the line that loads `wp-settings.php`. The same instructions are visible at Settings → API setup. Never enter keys into provider-symbol, verification-note, research or model fields. Do not send your keys in chat.

```php
define('TGIT_FMP_API_KEY', 'YOUR_FMP_KEY');
define('TGIT_ALPHA_VANTAGE_API_KEY', 'YOUR_ALPHA_VANTAGE_KEY');
define('TGIT_MARKET_DATA_ENABLED', false);
define('TGIT_FUNDAMENTALS_ENABLED', false);
```

Replace the relevant placeholders with your keys. Add only the provider you intend to use and do not duplicate existing definitions. Keep the switches `false` while preparing mappings. When you are ready to permit real requests, set `TGIT_MARKET_DATA_ENABLED` to `true` for quotes. For Alpha Vantage fundamentals, also set `TGIT_FUNDAMENTALS_ENABLED` to `true`. Reload the plugin page afterward. Adding keys does not enroll schedules or fetch a stock automatically. Scheduled jobs need reliable WordPress cron; ask your host to configure server-driven cron if needed.

**OpenAI in the latest installed test ZIP:** summary generation and OpenAI key handling are not available in 0.27.0-dev.1/schema 17. You can prepare model, budget, consent and approved evidence.

**Newer development source:** Settings → API setup → OpenAI credential preparation now recognizes `TGIT_OPENAI_API_KEY` in server `wp-config.php`. The card shows only whether the key is configured; it never displays the value or verifies account access. Add `define('TGIT_OPENAI_API_KEY', 'YOUR_OPENAI_KEY');` before WordPress loads, replacing the placeholder and avoiding duplicate definitions. Do not put the key in model fields or journal notes. Preparing it sends no request and does not enable generation. This change is not in the existing test ZIP.

**Why a control is disabled:** no stock means no history to select; no enabled Alpha Vantage mapping means no fundamental schedule to edit; failed schedule loading requires Reload saved history; disabled server refresh prevents fetching while preserving saved-history reads. AI consent requires a saved model/budget policy, and the shared policy can be edited only from its controlling workspace. Disabled controls now show their inactive state and accompanying explanation. Choose Weekly before choosing a fundamental weekday.

## Getting started

1. Install the supplied plugin ZIP through **Plugins → Add New Plugin → Upload Plugin**, then activate **IG Trading Journal**. Use the prepared release ZIP, rather than zipping the entire project directory.
2. Open **Investment Tracker** in the WordPress admin menu.
3. Create a workspace with a recognizable name. Confirm its base currency and timezone before creating it.
4. Open **Settings**, add your brokerage/cash account and the assets you want to track.
5. Record documented opening balances or actual deposits before posting purchases.
6. Record transactions, then add journal notes and link the relevant transaction fills.
7. Enter manual prices/FX in **Reports** when you need valuations.

The workspace creator becomes its owner. Each workspace has separate members and records. Select the intended workspace before entering data; being a WordPress administrator does not automatically grant access to another person's workspace.

For installation and updates, see [Installation, SQL and recovery](operations.md). Install the latest package directly; older ZIPs do not need to be installed sequentially. Versions 0.11.0–0.26.0 use schema 8. Older schemas require the documented backup and activation upgrade procedure. Ordinary installation handles the bundled SQL; manual SQL is an administrator task.

The server needs PHP 8.1+ with BCMath, a supported database and HTTPS outside local development. Private images also need GD and private storage. BCMath and GD are PHP extensions, not WordPress plugins. Have the host verify them in the PHP runtime serving WordPress, rather than relying on the phpMyAdmin or command-line PHP version.

## Navigation and tables

Choose **Overview**, **Transactions**, **Trade Journal**, **Strategies**, **Calculators**, **Research**, **Reports** or **Settings**. On a narrow screen, use **Sections** to open the navigation menu. Available controls depend on your workspace role.

Shared tables provide:

- **Search** and **Clear search**. Search covers the full loaded collection, including records beyond the displayed page.
- Clickable headings for supported sorting.
- State/view filters where available. **Clear filters** also clears search.
- **Previous page**, **Next page** and **Rows per page**.
- **Columns and density** to choose optional columns and compact desktop rows. These preferences belong to your user and workspace.

On mobile, scroll inside a wide table horizontally to reach its remaining columns and actions. **Back to transactions**, **Back to trades** and **Back to strategies** return to their collection context.

Save before leaving a screen. Transaction, Journal and Strategy editors protect unsaved changes with discard prompts; that protection has not yet been applied to every form. Wait for a save or upload operation to finish before navigating or starting another write.

## Accounts, assets and starting balances

### Add an account

In **Settings → Add account**, enter:

| Field | What to enter |
| --- | --- |
| Account name | A name you recognize, such as `Merrill brokerage` |
| Broker or exchange | The institution or trading platform; optional |
| Native currency | The account's currency code, such as `USD` |

An account's cash balance starts at zero. Creating the account does not import its actual balance or existing shares.

### Add an asset

In **Settings → Add asset**, enter the symbol, exchange/network identity, asset class and quote currency. For example, Microsoft shares could use `MSFT`, `NASDAQ`, `Stock`, `USD`.

**Quote currency** is the currency in which that asset's unit price is expressed. Enter a three-letter uppercase code such as `USD`, `EUR` or `GBP`. If the price is US$450 per share, enter `USD`. Entering an asset creates its identity; it does not purchase shares or set a current market price.

For ordinary buys/sells in this build, the asset's quote currency must match the selected account's native currency. Manual FX observations support reporting; they do not enable cross-currency trading or cash conversion.

### Record opening balances

Use **Settings → Opening balances** for documented balances you already held at the start of tracking:

1. Select the account and opening type: **Starting cash** or **Pre-existing shares**.
2. Enter the opening date. All opening entries in an account use the same date.
3. For cash, enter the starting amount.
4. For shares, select the asset, original acquisition date, quantity and cost-basis status. A known basis is the **total** basis for that opening lot, not the price per share.
5. Enter a source note identifying the statement or record.
6. Select **Record opening balance**.

Opening shares do not deduct cash. If cost basis is unknown, choose **Unknown**; do not substitute a fabricated zero. Unknown opening basis blocks selling that account/asset until it is resolved with documented evidence through the supported administrator/API workflow.

Record openings before ordinary posted activity. Retroactive openings for an account with existing posted history require the dedicated replay workflow; the ordinary form is not a shortcut for backdating.

## Transactions

Open **Transactions → New transaction**. Choose the account, action and effective date. Use plain decimal values such as `450` or `0.125`, without currency symbols, thousands separators or scientific notation.

| Action | Required financial fields | Effect when posted |
| --- | --- | --- |
| Deposit | Amount | Adds account cash |
| Withdrawal | Amount | Removes account cash |
| Buy | Asset, quantity, unit price, fees | Deducts cash and adds shares/units |
| Sell | Asset, quantity, unit price, fees | Removes shares/units and adds net cash |

**Save draft** retains the entry without changing cash or holdings. It is the default submission action. **Post transaction** records the financial event and is available to owners/managers.

### Example: fund an account, then buy shares

For a new training account, record a deposit of `15000`, then buy `30` shares at `450` with fees of `0`, using a deposit date no later than the purchase date and entering the deposit first. After both events post, cash is **USD 1,500** and holdings are **30 shares**. Use your actual documented funding/balances in your real workspace.

If the purchase is rejected for insufficient cash, it has not created a holding or reduced the balance. The form retains your input. Save it as a draft, record the appropriate earlier funding/opening balance, then post the draft when the ledger supports it. Overdrafts and selling more units than you hold are blocked.

### Edit or post a draft

1. Filter the table to **Draft**.
2. Select **Edit** (Edit draft) to load its latest revision.
3. Change the fields and select **Save draft changes**.
4. Return to the table and select **Post** (Post draft) when ready.

Posting preserves the draft as a **Promoted source** and creates a separate **Posted** transaction. The two rows are retained evidence, not two financial postings. The **Promoted sources** filter shows these source records. Contributors can edit their own drafts but cannot post them.

### View details and recover from a conflict

Select **View** (View transaction) to see the original facts, current replayed realized gain where available, revisions and related entries. Select **View** (View revision) for a historical snapshot. The raw revision payload is available in a separate disclosure. Related-entry buttons let you follow a promoted draft or correction relationship. These views do not edit posted entries. Compact table buttons retain full action names on hover and for screen readers.

If a draft changed elsewhere, a stale save is rejected and your typed values remain. **Reload latest transaction** offers the discard prompt before replacing them. Dismiss the prompt to keep your input; copy anything you need before accepting a reload.

Posted entries cannot be edited through the draft form. Corrections and historical replay are controlled administrator/API workflows; there is no general **Correct** action in this screen yet.

## Overview

**Cash accounts** shows available cash, not the total value of shares plus cash. **Holdings** is a searchable table showing account, remaining units, cost basis and realized gain. Use its account filter and Columns and density controls; hover formatted values for their raw decimal strings. Market value/unrealized gain depend on manual prices and known basis; missing inputs are shown as unavailable.

A zero cash balance with no holdings after an attempted purchase usually means the purchase was rejected. Check the save message and Transactions table. A successful purchase appears as a posted entry and a holding.

## Trade Journal

1. Open **Trade Journal → New trade**.
2. Enter a title and select the asset. You can start with a planned journal without financial entries or images.
3. Select **Create trade**.
4. Open its detail tabs to add the remaining information.

| Tab | Purpose |
| --- | --- |
| Summary | Trade identity and captured strategy/context |
| Plan and journal | Thesis, rationale, notes, emotions, lessons, confidence, levels, tags and confluences |
| Transactions | Select eligible entry/exit fills and review linked facts |
| Images | Upload, view, describe, compare and trash private images |
| History | Read earlier journal revisions |

Trade states are **Planned**, **Open**, **Closed** and **Archived**. The collection defaults to planned/open trades; change its view to find closed or archived entries. Journal state describes the trade's organization and does not itself post a buy/sell or close a financial position.

Use the tag/confluence entry controls and **Add** or Enter to commit each label. Text still waiting in an entry box is not yet an added label. Check the confluences that applied to the trade. Optional plan levels and notes can be filled in later.

In **Transactions**, choose eligible buy/sell fills for the selected asset. Transactions already assigned to another trade are excluded. Linking/unlinking fills changes the journal relationship, not cash, shares or FIFO allocations. Select **Save changes** after editing the journal or links. Journal edits retain revision history.

## Strategies

Owners/managers can select **Strategies → New strategy**, enter a name, description, rules and tags, then select **Create strategy**. Description/rules currently use textareas with basic HTML support, rather than a rich editor.

**Edit → Save new version** appends an immutable version. **Versions** lets members inspect previous versions. A trade journal retains its captured strategy version when the strategy is revised; choose the intended version when assigning a strategy to a trade.

The strategy collection offers Active/Archived/All views. Unsaved-change and conflict-reload behavior follows the same pattern as the Journal and Transactions editors.

## Private images

Before the first upload, the workspace owner must save **Settings → Image quota and retention**. The host must enable GD and configure a writable private directory outside every public web root. See [Private-image setup](private-images.md) for the administrator configuration.

Do not put these private files in the WordPress Media Library or a public `journal-images` folder. The plugin serves originals/thumbnails through authenticated membership checks.

To upload:

1. Save/open a journal and choose **Images**.
2. Select one or more JPEG, PNG or WebP files.
3. Select **Upload selected images** and wait for each file's result.
4. Retry a failed file individually, using the same file or a corrected/resized replacement where offered.

One failed upload does not undo the journal or the other successful images. Uploading does not require resaving the whole financial transaction. Ready images can have captions, accessible descriptions, stage/timeframe and display order. Select exactly two ready images to enable **Compare two selected images**.

The gallery has **Active** and **Trash** views. Trashing an image immediately denies byte access, but it still counts toward quota until cleanup. Restore it within the retention window. **Run expired-image cleanup** is an owner action that can permanently remove eligible expired files; restore anything you need beforehand.

Uploaded originals are normalized and metadata is removed; the plugin does not retain the untouched camera/source file. Keep a separate copy if you need an archival original.

## Research and watchlists

In **Research**, create a named watchlist, then add existing assets using the **Watchlist item** form. Enter optional target buy/sell prices, your status, thesis and comma-separated tags. Changing the selected watchlist loads its items.

Watch/Buy/Sell/Hold labels and target prices are your authored notes; they do not execute trades. Use a row's edit action to revise an item.

Use **Authored research note** to save a note for an asset. Editing an existing note requires a revision reason and preserves its prior evidence. Provider feeds and automated recommendations are not implemented in this build.

## Calculators

The active screen sits in one white card with 15px padding and 10px rounded corners. Sections inside it retain their spacing without additional outer card borders. Text inputs, dropdowns and text areas share a `1px solid #949494` border, with visible keyboard focus.

Calculators are expandable accordions in this order: Stock profit, Crypto profit, Bought call / put profit, Linear leveraged crypto, Long-position risk, Short-position risk. Stock profit opens initially. Select a heading to expand or collapse it; multiple calculators can remain open. Collapsing a calculator retains its entered values and result. Heading toggles also work with Enter or Space.

**Crypto profit** uses buy price, sell/current price, investment principal excluding fees, and separately entered buy/sell fees. It returns units, position value, net exit value and scenario profit.

**Long-position risk** uses entry price, maximum loss (risk budget) and an actual stop-loss price below entry. Entry `300`, stop loss `295` and risk budget `50` sizes `10` units and needs `3,000` starting capital before fees. Risk budget means potential loss, not money invested. The API also retains the older stop-distance input; do not supply both price and distance.

**Linear leveraged crypto** takes long/short direction, entry/exit price, collateral and leverage. Use the same currency for every amount; fees and borrowing/funding costs are entered as amounts, not percentages. For BTC entry 75,000 and exit 82,500, collateral 2,000 at 2x gives exposure 4,000 and gross profit 400 before costs. An exit at 67,500 gives a long loss of 400. Return is measured against entered collateral. Liquidation is unavailable without contract/account rules; the calculation assumes the entered exit is reached. Inverse contracts and currency conversions are unsupported. USDT/USDC identities remain planned.

**Stock profit** takes long/short direction, entry price, proposed sale/cover price, share quantity and fee amounts. Short scenarios additionally deduct entered borrowing and dividend payment costs. Ten shares entered at 100 and covered at 90 give 100 gross short profit before costs; covering at 110 gives a loss of 100. Return is measured against entry share value, not margin. Short-sale proceeds are not treated as available investment capital.

**Short-position risk** sizes whole shares with a stop above entry. Enter a total risk budget and estimated total costs at the stop (fees, borrowing, dividend payments and estimated slippage). Entry 100, stop 105, budget 200 and costs 12 give 37 shares: price loss 185 plus costs 12 = 197, leaving 3 unused. The calculator rounds down and never increases the entered risk budget to fit another share. Recheck estimated costs against the resulting share count. Actual gaps or changing costs can increase loss. Margin and borrow availability are unavailable.

**Bought call / put profit** supports selling an option before expiration or estimating its expiration payoff. Enter option premium per share, whole contract count, actual contract multiplier and fee amounts. One contract entered at premium `2` and sold at premium `3`, with multiplier `100`, yields `100` gross profit before fees. Starting capital is the entry premium value plus entry fees. For expiration, enter strike and underlying stock price at expiration; calls pay the amount above strike and puts the amount below strike, each floored at zero, times contracts and multiplier. Payoff is shown separately from net profit after the entry premium and fees. A stock price target alone cannot determine an option premium before expiration. Sold options, spreads, exercise/assignment and resulting stock/cash accounting are outside this calculator.

**Readable values:** ordinary financial displays remove redundant trailing zeros using strings; amounts show at least two decimal places and quantities trim unnecessary zeros. Significant fractional precision is preserved, including small crypto quantities and FX rates. Stored inputs, ledger values, raw history and exports remain exact. Holdings and Transactions use tables; if older cards remain after updating, confirm the active plugin version and hard-refresh the page.

These are scenarios. Running a calculator does not save a transaction, fund an account or create a holding.

## Prices, FX and reports

### Record manual prices or FX

Owners/managers use **Reports → Record a manual observation**:

1. Choose **Asset price** or **Native to base FX**.
2. For a price, select the asset and its quote currency. Enter the price per unit.
3. For FX, enter the native currency and its rate into the workspace base currency. In a USD workspace, EUR at `1.10` means **1 EUR = 1.10 USD**.
4. Enter the effective date, valid-through date, source and reason/note.
5. Select **Save observation**.

Expired observations remain visibly stale. **Correct observation** appends correction evidence; the original remains in history. Manual observations are not live market feeds and do not change an immutable report already generated.

### Generate and revisit reports

Select a report type, **As-of date** and any supported account/asset/currency/class filters. **Start date** filters activity and gains; it does not discard the earlier history needed to reconstruct balances through the as-of date. Select **Generate report**.

Available reports include holdings, activity, realized gains, cash activity, allocation and strategy performance. Strategy performance uses eligible closed trades and their captured strategy versions. **Income availability** explains that income posting is not implemented; an empty result is not proof of zero income.

Read the coverage summary before relying on base-currency totals: it identifies missing/stale prices or FX and unresolved basis. Allocation includes the selected cash balances. Missing coverage is not silently treated as zero, and reports currently state **Not reconciled**.

Each generated report is an immutable saved snapshot with an ID and calculation metadata. Use **Saved report ID → Load saved report** to retrieve it; **Load more saved reports** extends the available history where shown. Generating again after corrections creates a new snapshot; it does not overwrite the earlier one.

### Save filter views

Enter a **View name** and select **Save current filters**. Choose that view under **My saved views** to reapply its filters, check the dates, then generate a report. Saved views belong to you within the workspace. A saved view stores filter choices; a saved report stores the calculated snapshot.

## Members and access

| Role | Everyday access |
| --- | --- |
| Owner | Read, journals/drafts, posting/settings, membership management and image policy |
| Manager | Read, journals/drafts, posting and supported settings/strategies; no membership or owner image-policy management |
| Contributor | Read, journal/research work and permitted drafts; no financial posting or Settings management |
| Viewer | Read-only portfolio/journal/research access, with viewing/report/scenario capabilities where provided |

Owners manage membership in **Settings → Workspace members**, using an existing WordPress user ID, role and Active/Revoked state. To change or revoke membership, submit the existing user's ID with the intended role/state. Revocation stops future authorized access. The last active owner cannot be removed without transferring ownership appropriately.

Settings is hidden from contributors/viewers. If controls are missing, first check the selected workspace and your role. WordPress site administration alone does not bypass workspace membership.

## Troubleshooting

| Message or symptom | What to do |
| --- | --- |
| Insufficient cash; purchase not in Holdings | Save a draft if needed; record actual earlier funding or documented openings, then post when supported |
| No records match | Clear search and filters; check workspace and page/view selection |
| Draft changed / revision conflict | Keep/copy typed input, then use the explicit latest-revision reload where available |
| Historical posting rejected | Ordinary posting is chronological; use administrator-supported replay/correction workflows for earlier events |
| Unknown opening basis blocks a sale | Resolve the basis with documented evidence through the supported administrator/API workflow |
| Image decoding/memory limit | Resize the image, for example to about 1600 pixels on the longest edge, then retry that file; ask the host about PHP memory if needed |
| Image quota reached | Review owner policy and retained trash/failed uploads; trash alone does not immediately free quota |
| Images unavailable / private storage not ready | Ask the host to verify GD codecs, private-directory location/write access and saved owner policy |
| Values unavailable or stale in Reports | Check manual observations, valid-through dates, currency direction and cost-basis coverage |
| Request/session error | Check login and membership; reload to refresh the session/nonce. Preserve unsaved text first |
| Plugin file does not exist after upload | Reopen the Plugins list and check the installed folder/entry file; do not reuse an old activation URL. Ask the host to resolve an incomplete replacement or filesystem ownership issue |

For a network failure during a transaction save, retry the unchanged form rather than immediately making a fresh duplicate entry. The plugin uses a retry identity for unchanged commands. If the form clears or you reload the page, first inspect existing records before submitting the same event again.

## Backups and current limits

### Development: stock fundamentals

In the development source, open **Research → Stock fundamentals** and choose a stock asset. **Reload saved history** reads stored snapshots without contacting a provider. The table includes every page of saved history for that asset; **View** shows company overview evidence or metrics for the selected statement snapshot. Fiscal periods and unavailable metrics are shown explicitly. These are provider facts, not AI investment reviews.

Owners can select one dataset and use **Refresh selected dataset** after configuring an enabled Alpha Vantage mapping in Settings and enabling the fundamental worker on the server. Each dataset consumes one request from the shared quote/fundamental allowance; four datasets require four requests. Viewers can read history but cannot refresh. If delivery is uncertain, use **Check refresh outcome**; it keeps the same request identity across page reloads and does not resend an uncertain dispatch. Missing data never becomes a fabricated zero. Free cash flow remains unavailable until the source's capex sign convention is verified.

These controls are not included in the released 0.26.0 ZIP. AI summaries remain pending.

Back up the database and private image directory together. Database-only backups do not preserve image bytes. Deactivation/uninstall preserve portfolio data. Administrator backup, upgrade and recovery procedures are in [operations.md](operations.md).

This build is still a staged implementation. Spreadsheet imports, reconciliation, export and automated restore verification are pending. Dividends/interest and several corporate-action/transfer workflows, provider feeds and broader performance/accessibility/production-host acceptance also remain. Some correction/replay/basis-resolution workflows exist through APIs without a complete admin UI. See [remaining work](remaining-work.md) for the current scope.

The guide documents available controls; it does not establish that the production host has passed the outstanding release gates. Use a disposable or staging workspace to learn the workflows before entering real records.

Requested subaccount, stablecoin, margin, short and options capabilities are described in [next requirements](next-requirements.md); they are not delivered accounting/calculator features in this build.

### Development: fundamentals from a trade journal

For a saved stock trade, open its **Summary** and choose **View stock fundamentals**. Research selects that trade's saved stock asset and shows stored history. Unsaved journal or image changes use the normal discard confirmation: Cancel keeps the journal open; accepting discards the unsaved changes. Back reopens the saved journal, and reloading Research retains the selected asset in the URL. The shortcut is available to viewers with workspace membership and never starts a provider request or AI review.

### Development: weekly fundamentals

In Research → Stock fundamentals, select a stock and dataset, then choose **Weekly** and a weekday at **7:30 PM New York**. Save the schedule separately for each dataset. Choose **Off** and save to disable it. A pending-queue message means the enrollment was saved but server configuration or cron still needs attention. Spread holdings/datasets across weekdays: each dataset uses one request and shares the Alpha Vantage quote allowance. Missed slots are skipped, and scheduled refresh does not run an AI review. Owner access and an enabled Alpha Vantage mapping are required. Unsaved schedule changes prompt before changing datasets, assets or sections. See [schedule details](fundamental-schedules.md).

### Development: reporting-period changes

Choose **View** on a saved statement snapshot to see metrics and **Changes from previous available period**. The table shows prior/current values, fiscal dates, gaps and currencies. Margin changes are percentage points: −5% to 10% is +15 points. Missing prior periods, different currencies and unavailable facts show explicit reasons. These comparisons do not verify equal reporting durations or accounting policies and are not labeled annual/quarterly growth. Viewing comparisons uses stored evidence and does not consume provider requests or AI budget. See [comparison details](fundamental-period-comparisons.md).

## Prepare AI summaries (development)

As a workspace owner, open Settings → AI summary settings. Save a monthly USD budget and an exact model ID (optional until you choose one). The suggested starting budget is $15; $10 is supported, and $0 pauses processing. The workspace that first saves the policy controls the shared budget across workspaces. Save workspace consent separately; it defaults to No. Processing defaults off and requires verified server evidence before explicit enablement. Saving policy and consent sends no data to OpenAI. See [owner summary workflow](ai-owner-generation.md).

### Development: approve evidence for an AI summary

As an owner, open **Research → Stock fundamentals** and choose a stock. Under **Prepare evidence for an AI summary**, choose at least one saved statement snapshot and optionally a saved trade thesis. Click **Preview selected evidence**, then expand **Exact evidence and source fingerprints** to review the included metrics, coverage and thesis. Changing a selection requires another preview. Click **Approve this evidence** to save that exact evidence privately.

Approval does not spend money, send data or generate an AI summary. A changed thesis or evidence requires reloading saved history and previewing again. If the result is uncertain, use **Check approval outcome**; it reuses the original request and cannot create a duplicate. The same browser session remembers the latest approval and uncertain request across reloads when session storage is available. The saved record remains unchanged as your journal evolves. After approval, Check summary setup can enable the explicit Generate summary action when every gate passes. Read saved reviews through the owner history controls.

### Development: saved AI review foundation

The development source now has schema-15 storage for immutable, validated review output tied to approved evidence and a matching settled request. Owner history screens and explicit generation controls are available in current source; processing requires verified setup, shared enablement and separate workspace consent. Review screens label summaries as AI-generated interpretation and show approved-source citations; a citation confirms the source belongs to the reviewed evidence, not that the model's claim is correct. See [review storage details](ai-review-storage.md).

### Development: read saved AI reviews

As an owner, open **Research → Stock fundamentals**, select a stock and find **Saved AI reviews**. The table loads all saved review pages for that stock. Search covers review IDs, evidence approval IDs and saved dates. Choose **View** to read the summary, findings, cited snapshots and source provenance. Expand **Exact approved metrics and optional thesis** to inspect the evidence as it was approved; later journal changes do not replace it.

**Reload saved reviews** refreshes stored history without contacting an AI or market-data provider. Empty history means no reviews have been saved. Generation and saving the completed review are separate explicit actions. These are AI-generated interpretations, not verified facts. Citations identify included snapshots and do not establish that a claim is accurate. Viewer roles cannot access this owner-only history. This update requires no new SQL migration.

### Development sender status

An internal OpenAI sender is now implemented and disabled by default. The current owner workflow connects complete-input counting and one-time generation only after approved evidence and all setup gates pass. Preparing your server key or approving evidence does not run this sender. The existing test ZIP is unchanged; no SQL migration is needed for this sender slice.

### Development count status

Internal token counting is now implemented, with consent, budget and verified counting-cost/access checks. It remains unavailable through the admin screens. You do not need to change your setup or run SQL for this slice. The next work is trusted model/pricing setup and the explicit Generate summary workflow; adding a key still does not trigger AI requests.

### Development model setup status

The source now supports reviewed model/pricing and counting-access records stored by your server administrator. These are separate from the model ID and budget fields in Settings. Keep the OpenAI processing switch off until genuine account/model/pricing/count-cost verification is complete. The owner can then explicitly enable shared processing and save separate workspace consent. Adding a key, model ID or evidence record does not generate a summary. Generate summary controls are implemented; automatic account-specific verification remains unfinished.

### Development generation workflow status

The internal count-and-send steps are now connected, including safe retries. The current admin Generate summary action uses the exact approved evidence after Check summary setup passes. Keep processing off until genuine server setup verification is complete. Approval alone still does not generate a summary; see ai-owner-generation.md.

### Check AI readiness and saved request activity

As an owner, open **Settings > AI summary settings**. **Saved AI setup readiness** explains whether your server key is configured, your saved model has current reviewed access/pricing records, counting has reviewed access/cost records, and consent/budget are prepared. These checks do not call OpenAI or independently authenticate access. They describe saved settings, so save a changed model before checking its readiness. Generation requires an explicit Research action after every setup check passes.

Expand **Saved AI request activity** to see saved requests in a table. **Reload saved AI requests** reads stored history only and preserves unsaved settings. Search covers all loaded request metadata for this workspace. Reserved means not sent; delivery uncertain retains its budget hold; usage settled does not mean a review was published. Cancelled requests show no charge. Legacy entries are labelled unbound. No saved requests is normal before you have generated anything. Eligible original-owner rows offer Cancel unsent request or Save as review. Neither action sends an AI request; uncertain delivery holds cannot be cancelled. If loading fails, reload the table; no API charge is created by these reads.

This source update is version 0.27.0-dev.2, schema 18, and introduces no additional SQL migration. A new install ZIP has not been produced for this slice.

### Save a completed AI response as a review

As the original authorizing owner, open **Settings > AI summary settings > Saved AI request activity**. An eligible completed response has **Save as review** in the Actions column. Choose it to save the already stored output as a private review. It makes no AI call, adds no API charge and preserves unsaved settings. A saved row shows its review number.

Open **Research > Stock fundamentals**, select the stock and reload **Saved AI reviews** to read it with its approved evidence. Saving does not validate the truth of AI claims or share anything publicly.

If the save outcome is uncertain, choose **Reload saved AI requests** before retrying. A successful earlier write appears as a saved review ID; otherwise an eligible response can be saved again safely. Incomplete, refused, uncertain, invalid and over-budget results have no save action. Another owner cannot save output authorized by someone else. No button is normal when no eligible stored responses exist; new summary generation is still unavailable. This update is 0.27.0-dev.3/schema18 and adds no SQL migration or install ZIP.


## Unsent AI request recovery (October 8, 2026)

Settings > AI summaries > Saved AI request activity now offers Cancel unsent request for eligible original-owner reservations. Sent or uncertain requests retain their holds. See [recovery guide](ai-unsent-cancellation.md). Source 0.27.0-dev.4, schema 18; no new ZIP or SQL.


October 8, 2026: Approved AI evidence now offers Check summary setup in Research. The local preflight explains saved model, consent, budget and server evidence blockers without counting, reserving or sending. Generation remains unavailable pending genuine evidence verification and guarded enable/delivery controls. See [preflight guide](ai-generation-preflight.md). Source 0.27.0-dev.5/schema18; no SQL or ZIP.


October 8, 2026: Added read-only saved AI operation lookup by original-owner approval and retained UUID. No counting, sending or budget/history mutation. Owner enable/generation implementation was rejected by automatic approval review because explicit authorization for live-capable controls is required; see ai-owner-generation-proposal.md. Those controls remain disabled. See ai-operation-status.md. Schema18/source0.27.0-dev.5 unchanged; no SQL/ZIP.


October 8, 2026: Owner explicitly approved enable/generation controls. Added guarded Shared AI processing and Generate summary for exact approved evidence, with a 2,000-output-token ceiling, reviewed-policy binding under the reservation lock, retained browser UUID and read-only result recovery. No automatic generation/publication/resend. See [owner workflow](ai-owner-generation.md). Source 0.27.0-dev.6/schema18; no new SQL or ZIP. Genuine account/model/pricing/count-cost verification remains necessary before actual use.


October 9, 2026: source 0.27.0-dev.7 adds shared ticker/company suggestions from the owner public catalogue, plus workspace-only saved-asset search on all asset selectors. See asset-typeahead.md. Manual/crypto fallback and explicit exchange/currency verification remain; no schema change.


October 9, 2026: crypto name/symbol suggestions now use coins.json, with distinct catalogue IDs for repeated symbols. Select Crypto before searching. Confirm network, exchange and currency; no schema change. See asset-typeahead.md.
