<?php
/**
 * Small accessible admin surface for the first ledger slice.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Admin;

use GainerInteractive\IGTradingJournal\Application\Tracker;

/** Screen service for the current implementation slice. */
final class Screen {
	/**
	 * Register the authenticated administration page.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_menu_page( __( 'TG Investment Tracker', 'ig-trading-journal' ), __( 'Investment Tracker', 'ig-trading-journal' ), 'read', 'tgit', array( self::class, 'render' ), 'dashicons-chart-line' );
	}
	/**
	 * Load page assets and the current REST nonce.
	 *
	 * @param string $hook hook input.
	 * @return void
	 */
	public static function enqueue( string $hook ): void {
		if ( 'toplevel_page_tgit' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'tgit-admin', plugins_url( 'assets/admin.css', IG_TRADING_JOURNAL_FILE ), array(), IG_TRADING_JOURNAL_VERSION );
		wp_enqueue_script( 'tgit-collection', plugins_url( 'assets/collection.js', IG_TRADING_JOURNAL_FILE ), array(), IG_TRADING_JOURNAL_VERSION, true );
		wp_enqueue_script( 'tgit-rest-url', plugins_url( 'assets/rest-url.js', IG_TRADING_JOURNAL_FILE ), array(), IG_TRADING_JOURNAL_VERSION, true );
		wp_enqueue_script( 'tgit-tabs', plugins_url( 'assets/tabs.js', IG_TRADING_JOURNAL_FILE ), array(), IG_TRADING_JOURNAL_VERSION, true );
		wp_enqueue_script( 'tgit-admin', plugins_url( 'assets/admin.js', IG_TRADING_JOURNAL_FILE ), array( 'tgit-rest-url', 'tgit-tabs', 'tgit-collection' ), IG_TRADING_JOURNAL_VERSION, true );
		wp_enqueue_script( 'tgit-opening', plugins_url( 'assets/opening.js', IG_TRADING_JOURNAL_FILE ), array( 'tgit-admin' ), IG_TRADING_JOURNAL_VERSION, true );
		wp_enqueue_script( 'tgit-journal', plugins_url( 'assets/journal.js', IG_TRADING_JOURNAL_FILE ), array( 'tgit-admin' ), IG_TRADING_JOURNAL_VERSION, true );
		wp_enqueue_script( 'tgit-calculators', plugins_url( 'assets/calculators.js', IG_TRADING_JOURNAL_FILE ), array( 'tgit-admin' ), IG_TRADING_JOURNAL_VERSION, true );
		wp_enqueue_script( 'tgit-research', plugins_url( 'assets/research.js', IG_TRADING_JOURNAL_FILE ), array( 'tgit-admin' ), IG_TRADING_JOURNAL_VERSION, true );
		wp_enqueue_script( 'tgit-reports', plugins_url( 'assets/reports.js', IG_TRADING_JOURNAL_FILE ), array( 'tgit-admin' ), IG_TRADING_JOURNAL_VERSION, true );
		wp_enqueue_script( 'tgit-market-data', plugins_url( 'assets/market-data.js', IG_TRADING_JOURNAL_FILE ), array( 'tgit-admin' ), IG_TRADING_JOURNAL_VERSION, true );
		wp_enqueue_script( 'tgit-ai-settings', plugins_url( 'assets/ai-settings.js', IG_TRADING_JOURNAL_FILE ), array( 'tgit-admin' ), IG_TRADING_JOURNAL_VERSION, true );
		wp_enqueue_script( 'tgit-fundamentals', plugins_url( 'assets/fundamentals.js', IG_TRADING_JOURNAL_FILE ), array( 'tgit-admin' ), IG_TRADING_JOURNAL_VERSION, true );
		wp_enqueue_script( 'tgit-ai-evidence', plugins_url( 'assets/ai-evidence.js', IG_TRADING_JOURNAL_FILE ), array( 'tgit-fundamentals' ), IG_TRADING_JOURNAL_VERSION, true );
		wp_enqueue_script( 'tgit-ai-reviews', plugins_url( 'assets/ai-reviews.js', IG_TRADING_JOURNAL_FILE ), array( 'tgit-fundamentals' ), IG_TRADING_JOURNAL_VERSION, true );
		wp_localize_script(
			'tgit-admin',
			'tgitConfig',
			array(
				'root'      => esc_url_raw( rest_url( 'tgit/v1/' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'actorId'   => get_current_user_id(),
				'canCreate' => current_user_can( 'manage_options' ),
				'i18n'      => array(
					'loading'      => __( 'Loading…', 'ig-trading-journal' ),
					'empty'        => __( 'No records yet.', 'ig-trading-journal' ),
					'saved'        => __( 'Saved.', 'ig-trading-journal' ),
					'unknown'      => __( 'Missing price', 'ig-trading-journal' ),
					'edit'         => __( 'Edit draft', 'ig-trading-journal' ),
					'post'         => __( 'Post draft', 'ig-trading-journal' ),
					'editing'      => __( 'Editing draft', 'ig-trading-journal' ),
					'network'      => __( 'Request failed. Retry to use the same transaction key.', 'ig-trading-journal' ),
					'editResearch' => __( 'Edit', 'ig-trading-journal' ),
				),
			)
		);
	}
	/**
	 * Render accessible setup and ledger forms.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'read' ) ) {
			return;
		}
		nocache_headers();
		$quote_help = __( 'Enter the three-letter currency in which this asset is priced, such as USD for a US stock or a BTC/USD pair, or GBP for a London stock. It must match the cash account currency used for trades.', 'ig-trading-journal' );
		?>
	<div class="wrap tgit" id="tgit-app">
	<h1><?php esc_html_e( 'TG Investment Tracker', 'ig-trading-journal' ); ?></h1>

	<p id="tgit-status" role="status" aria-live="polite"></p>
	<section id="tgit-setup" hidden>
	<h2><?php esc_html_e( 'Create a private workspace', 'ig-trading-journal' ); ?></h2>
	<p><?php esc_html_e( 'Creating a workspace explicitly grants you owner membership.', 'ig-trading-journal' ); ?></p>
	<form id="tgit-workspace-form" class="tgit-form">
	<label><?php esc_html_e( 'Workspace name', 'ig-trading-journal' ); ?><input name="name" required maxlength="190"></label>
	<label><?php esc_html_e( 'Base currency (ISO code)', 'ig-trading-journal' ); ?><input name="base_currency" required pattern="[A-Z]{3}" maxlength="3" value="<?php echo esc_attr( Tracker::DEFAULT_BASE_CURRENCY ); ?>"></label>
	<label><?php esc_html_e( 'Workspace timezone (IANA)', 'ig-trading-journal' ); ?><input name="timezone" required value="<?php echo esc_attr( Tracker::DEFAULT_TIMEZONE ); ?>"></label>
	<button class="button button-primary"><?php esc_html_e( 'Create workspace', 'ig-trading-journal' ); ?></button>
	</form>
	</section>
	<label id="tgit-workspace-label" hidden><?php esc_html_e( 'Workspace', 'ig-trading-journal' ); ?><select id="tgit-workspace"></select></label>
	<div id="tgit-content" hidden>
	<div id="tgit-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Investment tracker sections', 'ig-trading-journal' ); ?>" hidden>
		<?php
		foreach ( array(
			'overview'     => __( 'Overview', 'ig-trading-journal' ),
			'transactions' => __( 'Transactions', 'ig-trading-journal' ),
			'journal'      => __( 'Trade Journal', 'ig-trading-journal' ),
			'strategies'   => __( 'Strategies', 'ig-trading-journal' ),
			'calculators'  => __( 'Calculators', 'ig-trading-journal' ),
			'research'     => __( 'Research', 'ig-trading-journal' ),
			'reports'      => __( 'Reports', 'ig-trading-journal' ),
			'settings'     => __( 'Settings', 'ig-trading-journal' ),
		) as $tab => $label ) :
			?>
		<button type="button" id="tgit-tab-<?php echo esc_attr( $tab ); ?>" role="tab" aria-controls="tgit-panel-<?php echo esc_attr( $tab ); ?>" aria-selected="false" tabindex="-1" data-tab="<?php echo esc_attr( $tab ); ?>"><?php echo esc_html( $label ); ?></button>
		<?php endforeach; ?>
	</div>
		<?php ReportScreen::render(); ?>
	<section><h2><?php esc_html_e( 'Cash accounts', 'ig-trading-journal' ); ?></h2><div id="tgit-accounts" class="tgit-cards"></div></section>
	<div class="tgit-grid" id="tgit-management" hidden>
	<section><h2><?php esc_html_e( 'Add account', 'ig-trading-journal' ); ?></h2>
		<form id="tgit-account-form" class="tgit-form">
		<label><?php esc_html_e( 'Account name', 'ig-trading-journal' ); ?><input name="name" required maxlength="190"></label>
		<label><?php esc_html_e( 'Broker or exchange', 'ig-trading-journal' ); ?><input name="broker" maxlength="190"></label>
		<label><?php esc_html_e( 'Native currency', 'ig-trading-journal' ); ?><input name="native_currency" required pattern="[A-Z]{3}" maxlength="3"></label>
		<button class="button"><?php esc_html_e( 'Add account', 'ig-trading-journal' ); ?></button>
		</form>
	</section>
	<section><h2><?php esc_html_e( 'Add asset', 'ig-trading-journal' ); ?></h2>
		<form id="tgit-asset-form" class="tgit-form">
		<label><?php esc_html_e( 'Symbol', 'ig-trading-journal' ); ?><input name="symbol" required maxlength="32"></label>
		<label><?php esc_html_e( 'Exchange or network identity', 'ig-trading-journal' ); ?><input name="exchange" required maxlength="64"></label>
		<label><?php esc_html_e( 'Asset class', 'ig-trading-journal' ); ?><select name="asset_class"><option value="stock"><?php esc_html_e( 'Stock', 'ig-trading-journal' ); ?></option><option value="etf"><?php esc_html_e( 'ETF', 'ig-trading-journal' ); ?></option><option value="crypto"><?php esc_html_e( 'Crypto', 'ig-trading-journal' ); ?></option></select></label>
		<label><?php esc_html_e( 'Quote currency', 'ig-trading-journal' ); ?><input name="quote_currency" required pattern="[A-Z]{3}" maxlength="3" aria-description="<?php echo esc_attr( $quote_help ); ?>"></label>
		<span class="tgit-help" tabindex="0" role="note" aria-label="<?php echo esc_attr( $quote_help ); ?>" data-tip="<?php echo esc_attr( $quote_help ); ?>">?</span>
		<button class="button"><?php esc_html_e( 'Add asset', 'ig-trading-journal' ); ?></button>
		</form>
	</section>
	</div>
	<section id="tgit-opening-section" hidden>
	<h2><?php esc_html_e( 'Opening balances', 'ig-trading-journal' ); ?></h2>
	<p><?php esc_html_e( 'Record documented starting cash or pre-existing shares before posting ordinary transactions in this account. Opening shares do not deduct cash. Use the same opening date for all starting entries in an account.', 'ig-trading-journal' ); ?></p>
	<p id="tgit-opening-status" role="status" aria-live="polite"></p>
	<form id="tgit-opening-form" class="tgit-form tgit-grid">
	<label><?php esc_html_e( 'Account', 'ig-trading-journal' ); ?><select name="account_id" required></select></label>
	<label><?php esc_html_e( 'Opening type', 'ig-trading-journal' ); ?><select name="kind"><option value="cash"><?php esc_html_e( 'Starting cash', 'ig-trading-journal' ); ?></option><option value="lot"><?php esc_html_e( 'Pre-existing shares', 'ig-trading-journal' ); ?></option></select></label>
	<label><?php esc_html_e( 'Opening date', 'ig-trading-journal' ); ?><input name="effective_date" type="date" required></label>
	<label data-opening-cash><?php esc_html_e( 'Starting cash amount', 'ig-trading-journal' ); ?><input name="cash_amount" inputmode="decimal" required pattern="[0-9]+([.][0-9]+)?"></label>
	<label data-opening-lot hidden><?php esc_html_e( 'Asset', 'ig-trading-journal' ); ?><select name="asset_id"></select></label>
	<label data-opening-lot hidden><?php esc_html_e( 'Original acquisition date', 'ig-trading-journal' ); ?><input name="acquired_on" type="date"></label>
	<label data-opening-lot hidden><?php esc_html_e( 'Quantity held at opening', 'ig-trading-journal' ); ?><input name="quantity" inputmode="decimal" pattern="[0-9]+([.][0-9]+)?"></label>
	<label data-opening-lot hidden><?php esc_html_e( 'Cost basis status', 'ig-trading-journal' ); ?><select name="basis_status"><option value="complete"><?php esc_html_e( 'Known', 'ig-trading-journal' ); ?></option><option value="unresolved"><?php esc_html_e( 'Unknown, resolve before selling', 'ig-trading-journal' ); ?></option></select></label>
	<label data-opening-lot hidden><?php esc_html_e( 'Total cost basis in account currency', 'ig-trading-journal' ); ?><input name="basis_amount" inputmode="decimal" pattern="[0-9]+([.][0-9]+)?"></label>
	<label><?php esc_html_e( 'Source note (statement or record reference)', 'ig-trading-journal' ); ?><input name="source_note" required maxlength="190"></label>
	<button type="submit" class="button button-primary"><?php esc_html_e( 'Record opening balance', 'ig-trading-journal' ); ?></button>
	</form>
	</section>
	<section id="tgit-entry" hidden><h2><?php esc_html_e( 'Record transaction', 'ig-trading-journal' ); ?></h2>
	<form id="tgit-transaction-form" class="tgit-form tgit-grid">
		<label><?php esc_html_e( 'Account', 'ig-trading-journal' ); ?><select name="account_id" required></select></label>
		<label><?php esc_html_e( 'Action', 'ig-trading-journal' ); ?><select name="action"><option value="deposit"><?php esc_html_e( 'Deposit', 'ig-trading-journal' ); ?></option><option value="withdrawal"><?php esc_html_e( 'Withdrawal', 'ig-trading-journal' ); ?></option><option value="buy"><?php esc_html_e( 'Buy', 'ig-trading-journal' ); ?></option><option value="sell"><?php esc_html_e( 'Sell', 'ig-trading-journal' ); ?></option></select></label>
		<label><?php esc_html_e( 'Effective date', 'ig-trading-journal' ); ?><input name="effective_date" type="date" required></label>
		<label><?php esc_html_e( 'State', 'ig-trading-journal' ); ?><select name="state"><option value="posted"><?php esc_html_e( 'Post to ledger', 'ig-trading-journal' ); ?></option><option value="draft"><?php esc_html_e( 'Draft (no financial effect)', 'ig-trading-journal' ); ?></option></select></label>
		<label data-cash><?php esc_html_e( 'Amount', 'ig-trading-journal' ); ?><input name="amount" inputmode="decimal" pattern="[0-9]+([.][0-9]+)?"></label>
		<label data-security hidden><?php esc_html_e( 'Asset', 'ig-trading-journal' ); ?><select name="asset_id"></select></label>
		<label data-security hidden><?php esc_html_e( 'Quantity', 'ig-trading-journal' ); ?><input name="quantity" inputmode="decimal" pattern="[0-9]+([.][0-9]+)?"></label>
		<label data-security hidden><?php esc_html_e( 'Unit price', 'ig-trading-journal' ); ?><input name="unit_price" inputmode="decimal" pattern="[0-9]+([.][0-9]+)?"></label>
		<label data-security hidden><?php esc_html_e( 'Fees', 'ig-trading-journal' ); ?><input name="fees" inputmode="decimal" value="0" pattern="[0-9]+([.][0-9]+)?"></label>
		<p><?php esc_html_e( 'Posted entries are immutable. Draft edits retain revision history. Owners and managers can post drafts to the ledger.', 'ig-trading-journal' ); ?></p>
		<p id="tgit-editing" hidden></p>
		<button type="button" id="tgit-cancel-edit" class="button" hidden><?php esc_html_e( 'Cancel editing', 'ig-trading-journal' ); ?></button>
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Save transaction', 'ig-trading-journal' ); ?></button>
	</form>
	</section>
	<section><h2><?php esc_html_e( 'Holdings', 'ig-trading-journal' ); ?></h2>
	<label><?php esc_html_e( 'Stock price source', 'ig-trading-journal' ); ?><select id="tgit-price-source"><option value="manual"><?php esc_html_e( 'Manual prices', 'ig-trading-journal' ); ?></option><option value="fmp">FMP</option><option value="alpha_vantage">Alpha Vantage</option></select></label>
	<p><?php esc_html_e( 'Provider prices require current enabled mappings in Settings. Quotes older than three calendar days are stale and are excluded from provider valuations. No manual fallback is applied to stocks. Other assets retain manual pricing.', 'ig-trading-journal' ); ?></p>
	<h3><?php esc_html_e( 'Open stock totals — all accounts', 'ig-trading-journal' ); ?></h3><p><?php esc_html_e( 'Totals are grouped by native currency and exclude cash. Table filters do not change these totals. Daily movement awaits corporate-action compatibility checks.', 'ig-trading-journal' ); ?></p>
	<div id="tgit-stock-summary" class="tgit-cards"></div>
	<div id="tgit-holdings" class="tgit-cards"></div><button id="tgit-more-holdings" class="button" hidden><?php esc_html_e( 'Load more holdings', 'ig-trading-journal' ); ?></button></section>
	<section><h2><?php esc_html_e( 'Transactions', 'ig-trading-journal' ); ?></h2><div id="tgit-transactions" class="tgit-cards"></div><button id="tgit-more-transactions" class="button" hidden><?php esc_html_e( 'Load more transactions', 'ig-trading-journal' ); ?></button></section>
	<section id="tgit-calculators-section">
	<h2><?php esc_html_e( 'Scenario calculators', 'ig-trading-journal' ); ?></h2>
	<p><?php esc_html_e( 'Scenarios use the currency you enter and never post transactions. Fees are entered separately from investment principal.', 'ig-trading-journal' ); ?></p>
	<div class="tgit-calculator-accordions">
	<details class="tgit-calculator-accordion" open>
	<summary><?php esc_html_e( 'Stock profit', 'ig-trading-journal' ); ?></summary>
	<form id="tgit-stock-calculator" class="tgit-form">
	<p><?php esc_html_e( 'Long estimates buying shares and selling later. Short estimates selling borrowed shares and buying them back. Use the same currency for prices and all cost amounts.', 'ig-trading-journal' ); ?></p>
	<label><?php esc_html_e( 'Currency', 'ig-trading-journal' ); ?><input name="currency" required pattern="[A-Z]{3}" maxlength="3" value="USD"></label>
	<label><?php esc_html_e( 'Direction', 'ig-trading-journal' ); ?><select name="direction"><option value="long"><?php esc_html_e( 'Long', 'ig-trading-journal' ); ?></option><option value="short"><?php esc_html_e( 'Short', 'ig-trading-journal' ); ?></option></select></label>
	<label><?php esc_html_e( 'Entry price per share', 'ig-trading-journal' ); ?><input name="entry_price" required inputmode="decimal" pattern="[0-9]+([.][0-9]+)?"></label>
	<label><?php esc_html_e( 'Take-profit / exit price per share', 'ig-trading-journal' ); ?><input name="exit_price" required inputmode="decimal" pattern="[0-9]+([.][0-9]+)?"></label>
	<label><?php esc_html_e( 'Share quantity', 'ig-trading-journal' ); ?><input name="quantity" required inputmode="decimal" pattern="[0-9]+([.][0-9]+)?"></label>
	<label><?php esc_html_e( 'Entry fee amount', 'ig-trading-journal' ); ?><input name="entry_fee" inputmode="decimal" pattern="[0-9]+([.][0-9]+)?" value="0"></label>
	<label><?php esc_html_e( 'Exit fee amount', 'ig-trading-journal' ); ?><input name="exit_fee" inputmode="decimal" pattern="[0-9]+([.][0-9]+)?" value="0"></label>
	<label data-stock-short hidden><?php esc_html_e( 'Total estimated borrowing cost', 'ig-trading-journal' ); ?><input name="borrow_cost" inputmode="decimal" pattern="[0-9]+([.][0-9]+)?" value="0" disabled></label>
	<label data-stock-short hidden><?php esc_html_e( 'Total estimated dividend payments', 'ig-trading-journal' ); ?><input name="dividend_cost" inputmode="decimal" pattern="[0-9]+([.][0-9]+)?" value="0" disabled></label>
	<label><?php esc_html_e( 'Scenario note', 'ig-trading-journal' ); ?><input name="note" maxlength="500"></label>
	<p><?php esc_html_e( 'Short-sale proceeds are not available investment capital. Broker margin and borrow availability are not calculated. Return is measured against entry share value, not margin. Short losses are not capped by that value.', 'ig-trading-journal' ); ?></p>
	<button class="button button-primary"><?php esc_html_e( 'Calculate stock profit', 'ig-trading-journal' ); ?></button>
	<output id="tgit-stock-result" aria-live="polite"></output>
	</form>
	</details>
	<details class="tgit-calculator-accordion">
	<summary><?php esc_html_e( 'Crypto profit', 'ig-trading-journal' ); ?></summary>
	<form id="tgit-crypto-calculator" class="tgit-form">
	<label><?php esc_html_e( 'Currency', 'ig-trading-journal' ); ?><input name="currency" required pattern="[A-Z]{3}" maxlength="3" value="USD"></label>
	<label><?php esc_html_e( 'Entry price per unit', 'ig-trading-journal' ); ?><input name="buy_price" required inputmode="decimal" pattern="[0-9]+([.][0-9]+)?"></label>
	<label><?php esc_html_e( 'Take-profit / exit price per unit', 'ig-trading-journal' ); ?><input name="sell_price" required inputmode="decimal" pattern="[0-9]+([.][0-9]+)?"></label>
	<label><?php esc_html_e( 'Starting capital, excluding fees', 'ig-trading-journal' ); ?><input name="investment" required inputmode="decimal" pattern="[0-9]+([.][0-9]+)?"></label>
	<label><?php esc_html_e( 'Entry fee', 'ig-trading-journal' ); ?><input name="buy_fee" inputmode="decimal" pattern="[0-9]+([.][0-9]+)?" value="0"></label>
	<label><?php esc_html_e( 'Exit fee', 'ig-trading-journal' ); ?><input name="sell_fee" inputmode="decimal" pattern="[0-9]+([.][0-9]+)?" value="0"></label>
	<label><?php esc_html_e( 'Scenario note', 'ig-trading-journal' ); ?><input name="note" maxlength="500"></label>
	<button class="button button-primary"><?php esc_html_e( 'Calculate profit', 'ig-trading-journal' ); ?></button>
	<output id="tgit-crypto-result" aria-live="polite"></output>
	</form>
	</details>
	<details class="tgit-calculator-accordion">
	<summary><?php esc_html_e( 'Bought call / put profit', 'ig-trading-journal' ); ?></summary>
	<form id="tgit-option-calculator" class="tgit-form">
	<p><?php esc_html_e( 'Buy an option, then estimate selling it or its payoff at expiration. Option premiums are prices per share, not the underlying stock price. This scenario does not model exercise, assignment or stock purchases.', 'ig-trading-journal' ); ?></p>
	<label><?php esc_html_e( 'Currency', 'ig-trading-journal' ); ?><input name="currency" required pattern="[A-Z]{3}" maxlength="3" value="USD"></label>
	<label><?php esc_html_e( 'Entry option price (premium per share)', 'ig-trading-journal' ); ?><input name="entry_premium" required pattern="[0-9]+([.][0-9]+)?" inputmode="decimal"></label>
	<label><?php esc_html_e( 'Number of contracts', 'ig-trading-journal' ); ?><input name="contracts" required pattern="[0-9]+" inputmode="decimal" value="1"></label>
	<label><?php esc_html_e( 'Contract multiplier', 'ig-trading-journal' ); ?><input name="multiplier" required pattern="[0-9]+([.][0-9]+)?" inputmode="decimal" aria-describedby="tgit-option-multiplier-help" value="100"></label>
	<label><?php esc_html_e( 'Entry fee amount', 'ig-trading-journal' ); ?><input name="entry_fee" required pattern="[0-9]+([.][0-9]+)?" inputmode="decimal" value="0"></label>
	<label><?php esc_html_e( 'Exit / settlement fee amount', 'ig-trading-journal' ); ?><input name="exit_fee" required pattern="[0-9]+([.][0-9]+)?" inputmode="decimal" value="0"></label>
	<label><?php esc_html_e( 'Option type', 'ig-trading-journal' ); ?><select name="option_type"><option value="call">Call</option><option value="put">Put</option></select></label>
	<label><?php esc_html_e( 'Exit scenario', 'ig-trading-journal' ); ?><select name="mode"><option value="close">Sell option before expiration</option><option value="expiry">Payoff at expiration</option></select></label>
	<label data-option-mode="close"><?php esc_html_e( 'Take-profit / exit option price (premium per share)', 'ig-trading-journal' ); ?><input name="exit_premium" inputmode="decimal" pattern="[0-9]+([.][0-9]+)?" required></label>
	<label data-option-mode="expiry" hidden><?php esc_html_e( 'Strike price', 'ig-trading-journal' ); ?><input name="strike" inputmode="decimal" pattern="[0-9]+([.][0-9]+)?" disabled></label>
	<label data-option-mode="expiry" hidden><?php esc_html_e( 'Underlying stock price at expiration', 'ig-trading-journal' ); ?><input name="underlying_price" inputmode="decimal" pattern="[0-9]+([.][0-9]+)?" disabled></label>
	<p id="tgit-option-multiplier-help"><?php esc_html_e( 'Use the actual contract multiplier; 100 is common but adjusted contracts can differ. Starting capital is option premium times contracts times multiplier, plus entry fees. Before expiration, enter your expected exit option premium: a stock price target alone cannot determine it.', 'ig-trading-journal' ); ?></p>
	<label><?php esc_html_e( 'Scenario note', 'ig-trading-journal' ); ?><input name="note" maxlength="500"></label>
	<button class="button button-primary"><?php esc_html_e( 'Calculate bought-option profit', 'ig-trading-journal' ); ?></button>
	<output id="tgit-option-result" aria-live="polite"></output>
	</form>
	</details>
	<details class="tgit-calculator-accordion">
	<summary><?php esc_html_e( 'Linear leveraged crypto', 'ig-trading-journal' ); ?></summary>
	<form id="tgit-leveraged-calculator" class="tgit-form">
	<p><?php esc_html_e( 'Hypothetical linear exposure only. Entry, exit, collateral and costs must use the same currency. Inverse contracts and currency conversions are not supported.', 'ig-trading-journal' ); ?></p>
	<label><?php esc_html_e( 'Currency', 'ig-trading-journal' ); ?><input name="currency" required pattern="[A-Z]{3}" maxlength="3" value="USD"></label>
	<label><?php esc_html_e( 'Direction', 'ig-trading-journal' ); ?><select name="direction"><option value="long"><?php esc_html_e( 'Long', 'ig-trading-journal' ); ?></option><option value="short"><?php esc_html_e( 'Short', 'ig-trading-journal' ); ?></option></select></label>
	<label><?php esc_html_e( 'Entry price per unit', 'ig-trading-journal' ); ?><input name="entry_price" required inputmode="decimal" pattern="[0-9]+([.][0-9]+)?"></label>
	<label><?php esc_html_e( 'Take-profit / exit price per unit', 'ig-trading-journal' ); ?><input name="exit_price" required inputmode="decimal" pattern="[0-9]+([.][0-9]+)?"></label>
	<label><?php esc_html_e( 'Starting capital (collateral), excluding costs', 'ig-trading-journal' ); ?><input name="collateral" required inputmode="decimal" pattern="[0-9]+([.][0-9]+)?" aria-describedby="tgit-leverage-help"></label>
	<label><?php esc_html_e( 'Leverage multiplier', 'ig-trading-journal' ); ?><input name="leverage" required inputmode="decimal" pattern="[0-9]+([.][0-9]+)?" value="2" aria-describedby="tgit-leverage-help"></label>
	<p id="tgit-leverage-help"><?php esc_html_e( 'For example, 2,000 collateral at 2x creates 4,000 exposure. Return is measured against entered collateral, excluding costs.', 'ig-trading-journal' ); ?></p>
	<label><?php esc_html_e( 'Entry fee amount', 'ig-trading-journal' ); ?><input name="entry_fee" inputmode="decimal" pattern="[0-9]+([.][0-9]+)?" value="0"></label>
	<label><?php esc_html_e( 'Exit fee amount', 'ig-trading-journal' ); ?><input name="exit_fee" inputmode="decimal" pattern="[0-9]+([.][0-9]+)?" value="0"></label>
	<label><?php esc_html_e( 'Borrowing and funding cost amount', 'ig-trading-journal' ); ?><input name="other_costs" inputmode="decimal" pattern="[0-9]+([.][0-9]+)?" value="0"></label>
	<label><?php esc_html_e( 'Scenario note', 'ig-trading-journal' ); ?><input name="note" maxlength="500"></label>
	<p><?php esc_html_e( 'Liquidation price is unavailable without contract and account rules. This arithmetic assumes the position reaches the entered exit; actual liquidation may occur first. Loss can exceed collateral.', 'ig-trading-journal' ); ?></p>
	<button class="button button-primary"><?php esc_html_e( 'Calculate leveraged profit', 'ig-trading-journal' ); ?></button>
	<output id="tgit-leveraged-result" aria-live="polite"></output>
	</form>
	</details>
	<details class="tgit-calculator-accordion">
	<summary><?php esc_html_e( 'Long-position risk', 'ig-trading-journal' ); ?></summary>
	<form id="tgit-risk-calculator" class="tgit-form">
	<p><?php esc_html_e( 'Risk budget is the amount you are willing to lose, not the amount you invest. Entry 100, stop-loss price 95 and risk budget 200 sizes 40 units. Assumes exit at the stop; fees, slippage and gaps can increase loss.', 'ig-trading-journal' ); ?></p>
	<label><?php esc_html_e( 'Currency', 'ig-trading-journal' ); ?><input name="currency" required pattern="[A-Z]{3}" maxlength="3" value="USD"></label>
	<label><?php esc_html_e( 'Entry price per unit', 'ig-trading-journal' ); ?><input name="entry_price" required inputmode="decimal" pattern="[0-9]+([.][0-9]+)?"></label>
	<label><?php esc_html_e( 'Maximum loss (risk budget)', 'ig-trading-journal' ); ?><input name="risk_budget" required inputmode="decimal" pattern="[0-9]+([.][0-9]+)?"></label>
	<label><?php esc_html_e( 'Stop-loss price below entry', 'ig-trading-journal' ); ?><input name="stop_price" required inputmode="decimal" pattern="[0-9]+([.][0-9]+)?"></label>
	<label><?php esc_html_e( 'Scenario note', 'ig-trading-journal' ); ?><input name="note" maxlength="500"></label>
	<button class="button button-primary"><?php esc_html_e( 'Calculate size', 'ig-trading-journal' ); ?></button>
	<output id="tgit-risk-result" aria-live="polite"></output>
	</form>
	</details>
	<details class="tgit-calculator-accordion">
	<summary><?php esc_html_e( 'Short-position risk', 'ig-trading-journal' ); ?></summary>
	<form id="tgit-short-risk-calculator" class="tgit-form">
	<p><?php esc_html_e( 'Size whole shares using a stop price above entry. Risk budget is the total loss you are willing to accept, not margin or investment capital.', 'ig-trading-journal' ); ?></p>
	<label><?php esc_html_e( 'Currency', 'ig-trading-journal' ); ?><input name="currency" required pattern="[A-Z]{3}" maxlength="3" value="USD"></label>
	<label><?php esc_html_e( 'Entry price per share', 'ig-trading-journal' ); ?><input name="entry_price" required inputmode="decimal" pattern="[0-9]+([.][0-9]+)?"></label>
	<label><?php esc_html_e( 'Stop-loss price above entry', 'ig-trading-journal' ); ?><input name="stop_price" required inputmode="decimal" pattern="[0-9]+([.][0-9]+)?"></label>
	<label><?php esc_html_e( 'Maximum loss (risk budget)', 'ig-trading-journal' ); ?><input name="risk_budget" required inputmode="decimal" pattern="[0-9]+([.][0-9]+)?"></label>
	<label><?php esc_html_e( 'Estimated total costs at the stop', 'ig-trading-journal' ); ?><input name="estimated_costs" inputmode="decimal" pattern="[0-9]+([.][0-9]+)?" value="0" aria-describedby="tgit-short-risk-help"></label>
	<p id="tgit-short-risk-help"><?php esc_html_e( 'Include estimated entry/exit fees, borrowing, dividend payments and slippage as a total amount. Costs are reserved before sizing; recheck the estimate for the resulting share count. Gaps and changing costs can increase actual loss.', 'ig-trading-journal' ); ?></p>
	<label><?php esc_html_e( 'Scenario note', 'ig-trading-journal' ); ?><input name="note" maxlength="500"></label>
	<button class="button button-primary"><?php esc_html_e( 'Calculate short size', 'ig-trading-journal' ); ?></button>
	<output id="tgit-short-risk-result" aria-live="polite"></output>
	</form>
	</details>
	</div>
	</section>
	<section id="tgit-research-section">
	<h2><?php esc_html_e( 'Watchlists and research', 'ig-trading-journal' ); ?></h2>
	<p><?php esc_html_e( 'Targets and Buy/Sell/Hold labels are your own notes, not automated recommendations. Research notes are separate from provider data.', 'ig-trading-journal' ); ?></p>
	<p id="tgit-research-status" role="status" aria-live="polite"></p>
	<div class="tgit-grid">
	<form id="tgit-watchlist-form" class="tgit-form">
	<h3><?php esc_html_e( 'Create watchlist', 'ig-trading-journal' ); ?></h3>
	<label><?php esc_html_e( 'Name', 'ig-trading-journal' ); ?><input name="name" required maxlength="190"></label>
	<button class="button button-primary"><?php esc_html_e( 'Create watchlist', 'ig-trading-journal' ); ?></button>
	</form>
	<form id="tgit-watchlist-item-form" class="tgit-form">
	<h3><?php esc_html_e( 'Watchlist item', 'ig-trading-journal' ); ?></h3>
	<label><?php esc_html_e( 'Watchlist', 'ig-trading-journal' ); ?><select name="watchlist_id" required></select></label>
	<label><?php esc_html_e( 'Asset', 'ig-trading-journal' ); ?><select name="asset_id" required></select></label>
	<label><?php esc_html_e( 'Target buy price', 'ig-trading-journal' ); ?><input name="target_buy" inputmode="decimal" pattern="[0-9]+([.][0-9]+)?"></label>
	<label><?php esc_html_e( 'Target sell price', 'ig-trading-journal' ); ?><input name="target_sell" inputmode="decimal" pattern="[0-9]+([.][0-9]+)?"></label>
	<label><?php esc_html_e( 'Your status', 'ig-trading-journal' ); ?><select name="status"><option value="watch"><?php esc_html_e( 'Watch', 'ig-trading-journal' ); ?></option><option value="buy"><?php esc_html_e( 'Buy', 'ig-trading-journal' ); ?></option><option value="sell"><?php esc_html_e( 'Sell', 'ig-trading-journal' ); ?></option><option value="hold"><?php esc_html_e( 'Hold', 'ig-trading-journal' ); ?></option></select></label>
	<label><?php esc_html_e( 'Thesis', 'ig-trading-journal' ); ?><textarea name="thesis" maxlength="5000"></textarea></label>
	<label><?php esc_html_e( 'Tags, comma separated', 'ig-trading-journal' ); ?><input name="tags" maxlength="500"></label>
	<button class="button button-primary"><?php esc_html_e( 'Save watchlist item', 'ig-trading-journal' ); ?></button>
	<button type="button" id="tgit-watchlist-cancel" class="button" hidden><?php esc_html_e( 'Cancel editing', 'ig-trading-journal' ); ?></button>
	</form>
	</div>
	<div id="tgit-watchlist-items" class="tgit-cards"></div>
	<form id="tgit-research-note-form" class="tgit-form">
	<h3><?php esc_html_e( 'Authored research note', 'ig-trading-journal' ); ?></h3>
	<label><?php esc_html_e( 'Asset', 'ig-trading-journal' ); ?><select name="asset_id" required></select></label>
	<label><?php esc_html_e( 'Note', 'ig-trading-journal' ); ?><textarea name="content" required maxlength="10000"></textarea></label>
	<label id="tgit-research-reason-label" hidden><?php esc_html_e( 'Revision reason', 'ig-trading-journal' ); ?><input name="reason" maxlength="190"></label>
	<button class="button button-primary"><?php esc_html_e( 'Save research note', 'ig-trading-journal' ); ?></button>
	<button type="button" id="tgit-research-cancel" class="button" hidden><?php esc_html_e( 'Cancel editing', 'ig-trading-journal' ); ?></button>
	</form>
	<div id="tgit-research-notes" class="tgit-cards"></div>
	<div class="tgit-form" id="tgit-fundamental-card">
	<h3><?php esc_html_e( 'Stock fundamentals', 'ig-trading-journal' ); ?></h3>
	<p><?php esc_html_e( 'Saved provider facts and calculated metrics. These are not AI investment reviews. Loading history sends no provider requests.', 'ig-trading-journal' ); ?></p>
	<label><?php esc_html_e( 'Stock asset', 'ig-trading-journal' ); ?><select id="tgit-fundamental-asset"></select></label>
	<button type="button" class="button" id="tgit-fundamental-reload"><?php esc_html_e( 'Reload saved history', 'ig-trading-journal' ); ?></button>
	<div id="tgit-fundamental-owner" hidden>
	<label><?php esc_html_e( 'Dataset to refresh', 'ig-trading-journal' ); ?><select id="tgit-fundamental-dataset"><option value="OVERVIEW">Company overview</option><option value="INCOME_STATEMENT">Income statement</option><option value="BALANCE_SHEET">Balance sheet</option><option value="CASH_FLOW">Cash flow</option></select></label>
	<button type="button" class="button" id="tgit-fundamental-refresh"><?php esc_html_e( 'Refresh selected dataset', 'ig-trading-journal' ); ?></button>
	<p id="tgit-fundamental-config"></p>
	<p id="tgit-fundamental-schedule-availability" role="status" aria-live="polite"></p>
	<form id="tgit-fundamental-schedule-form" class="tgit-grid">
	<label><?php esc_html_e( 'Automatic refresh for selected dataset', 'ig-trading-journal' ); ?><select name="frequency" disabled aria-describedby="tgit-fundamental-schedule-availability"><option value="off">Off</option><option value="weekly">Weekly</option></select></label>
	<label><?php esc_html_e( 'Weekday at 7:30 PM New York', 'ig-trading-journal' ); ?><select name="weekday" disabled aria-describedby="tgit-fundamental-schedule-availability"><option value="1">Monday</option><option value="2">Tuesday</option><option value="3">Wednesday</option><option value="4">Thursday</option><option value="5">Friday</option></select></label>
	<button type="submit" class="button" disabled aria-describedby="tgit-fundamental-schedule-availability"><?php esc_html_e( 'Save fundamental schedule', 'ig-trading-journal' ); ?></button>
	<p><?php esc_html_e( 'Each dataset uses one request per week and shares the quote allowance. Spread stocks and datasets across weekdays. Site cron, quota and provider availability can delay or skip a refresh; missed slots are not replayed.', 'ig-trading-journal' ); ?></p>
	</form>
	<p id="tgit-fundamental-schedule-status" role="status" aria-live="polite"></p>
	</div>
	<p id="tgit-fundamental-status" role="status" aria-live="polite"></p>
	<div id="tgit-fundamental-history"></div>
	<div id="tgit-fundamental-detail"></div>
	<div id="tgit-ai-evidence" class="tgit-form" hidden>
	<h3><?php esc_html_e( 'Prepare evidence for an AI summary', 'ig-trading-journal' ); ?></h3>
	<p><?php esc_html_e( 'Select saved statements and optionally a saved trade thesis, preview the exact evidence, then approve it. Approval saves a private record. It does not send data, spend money or generate a summary.', 'ig-trading-journal' ); ?></p>
	<form id="tgit-ai-evidence-form" class="tgit-grid">
	<div id="tgit-ai-evidence-snapshots"></div>
	<label><?php esc_html_e( 'Saved trade thesis (optional)', 'ig-trading-journal' ); ?><select id="tgit-ai-evidence-trade"><option value="">No thesis</option></select></label>
	<button type="submit" class="button" id="tgit-ai-evidence-preview"><?php esc_html_e( 'Preview selected evidence', 'ig-trading-journal' ); ?></button>
	</form>
	<div id="tgit-ai-evidence-detail"></div>
	<button type="button" class="button button-primary" id="tgit-ai-evidence-approve" disabled><?php esc_html_e( 'Approve this evidence', 'ig-trading-journal' ); ?></button>
	<button type="button" class="button" id="tgit-ai-evidence-retry" hidden><?php esc_html_e( 'Check approval outcome', 'ig-trading-journal' ); ?></button>
	<p id="tgit-ai-evidence-status" role="status" aria-live="polite"></p>
	</div>
	<div id="tgit-ai-reviews" class="tgit-form" hidden>
	<h3><?php esc_html_e( 'Saved AI reviews', 'ig-trading-journal' ); ?></h3>
	<p><?php esc_html_e( 'Read saved interpretation alongside its approved evidence. AI claims are not verified facts; citations identify included sources, not claim accuracy. Summary generation is not available yet.', 'ig-trading-journal' ); ?></p>
	<button type="button" class="button" id="tgit-ai-reviews-reload"><?php esc_html_e( 'Reload saved reviews', 'ig-trading-journal' ); ?></button>
	<p id="tgit-ai-reviews-status" role="status" aria-live="polite"></p>
	<div id="tgit-ai-reviews-history"></div>
	<div id="tgit-ai-reviews-detail"></div>
	</div>
	</div>
	</section>
		<?php JournalScreen::render(); ?>
	<section id="tgit-api-setup">
	<h2><?php esc_html_e( 'API setup', 'ig-trading-journal' ); ?></h2>
	<p><?php esc_html_e( 'Provider keys currently belong in wp-config.php on your server, before the line that loads WordPress. Ask your host to edit this file if needed. Keys are not entered into these forms or saved in journal notes.', 'ig-trading-journal' ); ?></p>
	<details><summary><?php esc_html_e( 'FMP and Alpha Vantage configuration', 'ig-trading-journal' ); ?></summary>
	<pre><?php echo esc_html( "define('TGIT_FMP_API_KEY', 'YOUR_FMP_KEY');\ndefine('TGIT_ALPHA_VANTAGE_API_KEY', 'YOUR_ALPHA_VANTAGE_KEY');\ndefine('TGIT_MARKET_DATA_ENABLED', false);\ndefine('TGIT_FUNDAMENTALS_ENABLED', false);" ); ?></pre>
	<p><?php esc_html_e( 'Replace only the placeholder for each provider you use. Do not duplicate an existing definition. Keep both switches false while preparing mappings. When ready to allow real requests, set TGIT_MARKET_DATA_ENABLED to true for prices; also set TGIT_FUNDAMENTALS_ENABLED to true for Alpha Vantage fundamentals. Then reload the page. Server enablement does not create a mapping or schedule.', 'ig-trading-journal' ); ?></p>
	</details>
	<p><?php esc_html_e( 'OpenAI: API key entry and summary generation are not available in this build. Model, budget, consent and evidence approval are preparation only. There is no supported OpenAI key setting to configure yet.', 'ig-trading-journal' ); ?></p>
	</section>
		<section id="tgit-ai-section" hidden>
	<h2><?php esc_html_e( 'AI summary settings', 'ig-trading-journal' ); ?></h2>
	<p><?php esc_html_e( 'Prepare your model and shared monthly budget. AI summaries are not available yet; saving sends no financial data to an AI provider.', 'ig-trading-journal' ); ?></p>
	<p id="tgit-ai-summary"></p>
	<form id="tgit-ai-policy" class="tgit-form tgit-grid">
	<label><?php esc_html_e( 'Monthly budget (USD)', 'ig-trading-journal' ); ?><input name="monthly_cap" inputmode="decimal" required pattern="[0-9]+(\.[0-9]{1,12})?"></label>
	<label><?php esc_html_e( 'Model ID', 'ig-trading-journal' ); ?><input name="model" maxlength="128" pattern="[a-zA-Z0-9][a-zA-Z0-9._\-]*" aria-describedby="tgit-ai-model-help"></label>
	<p id="tgit-ai-model-help"><?php esc_html_e( 'Enter the exact OpenAI API model ID, or leave blank to choose later. Selection does not verify account access or current pricing. A zero budget pauses processing.', 'ig-trading-journal' ); ?></p>
	<button type="submit" class="button button-primary"><?php esc_html_e( 'Save model and budget', 'ig-trading-journal' ); ?></button>
	</form>
	<form id="tgit-ai-consent" class="tgit-form tgit-grid">
	<label><?php esc_html_e( 'Allow future AI summaries in this workspace', 'ig-trading-journal' ); ?><select name="enabled"><option value="false"><?php esc_html_e( 'No', 'ig-trading-journal' ); ?></option><option value="true"><?php esc_html_e( 'Yes', 'ig-trading-journal' ); ?></option></select></label>
	<p><?php esc_html_e( 'Save the model and budget before workspace consent. Consent is separate for each workspace. Future summaries will use approved saved fundamentals and your selected trade thesis; images and unrelated notes are excluded.', 'ig-trading-journal' ); ?></p>
	<button type="submit" class="button"><?php esc_html_e( 'Save workspace consent', 'ig-trading-journal' ); ?></button>
	</form>
	<button type="button" id="tgit-ai-reload" class="button"><?php esc_html_e( 'Reload AI settings', 'ig-trading-journal' ); ?></button>
	<p id="tgit-ai-status" role="status" aria-live="polite"></p>
	</section>
	<section id="tgit-market-data-section" hidden>
	<h2><?php esc_html_e( 'Stock market data', 'ig-trading-journal' ); ?></h2>
	<p><?php esc_html_e( 'Confirm the provider symbol, exchange and currency before requesting a price. These are end-of-day observations. Choose the stock price source in Overview to use saved provider prices.', 'ig-trading-journal' ); ?></p>
	<p id="tgit-market-config"></p>
	<p id="tgit-market-status" role="status" aria-live="polite"></p>
	<form id="tgit-market-form" class="tgit-form tgit-grid">
	<label><?php esc_html_e( 'Stock asset', 'ig-trading-journal' ); ?><select name="asset_id" required></select></label>
	<label><?php esc_html_e( 'Price provider', 'ig-trading-journal' ); ?><select name="provider"><option value="fmp">FMP</option><option value="alpha_vantage">Alpha Vantage</option></select></label>
	<label><?php esc_html_e( 'Provider symbol', 'ig-trading-journal' ); ?><input name="provider_symbol" required maxlength="80"></label>
	<label><?php esc_html_e( 'Exchange', 'ig-trading-journal' ); ?><input name="exchange" readonly required></label>
	<label><?php esc_html_e( 'Quote currency', 'ig-trading-journal' ); ?><input name="currency" readonly required></label>
	<label><?php esc_html_e( 'Price requests', 'ig-trading-journal' ); ?><select name="enabled"><option value="true"><?php esc_html_e( 'Enabled for this mapping', 'ig-trading-journal' ); ?></option><option value="false"><?php esc_html_e( 'Disabled', 'ig-trading-journal' ); ?></option></select></label>
	<label><?php esc_html_e( 'How you verified this symbol', 'ig-trading-journal' ); ?><input name="evidence" required maxlength="500"></label>
	<label><?php esc_html_e( 'Automatic refresh (New York weekdays)', 'ig-trading-journal' ); ?><select name="frequency"><option value="off"><?php esc_html_e( 'Off', 'ig-trading-journal' ); ?></option><option value="once"><?php esc_html_e( 'Once: 6:30 PM', 'ig-trading-journal' ); ?></option><option value="twice"><?php esc_html_e( 'Twice: 6:30 PM and 10:30 PM', 'ig-trading-journal' ); ?></option></select></label>
	<p><?php esc_html_e( 'Save the mapping first, then save its schedule. Weekday refresh skips weekends; exchange holidays may still run. Shared quotas can defer requests. Site cron can delay execution; missed slots are skipped.', 'ig-trading-journal' ); ?></p>
	<button type="submit" class="button button-primary"><?php esc_html_e( 'Save provider mapping', 'ig-trading-journal' ); ?></button>
	<button type="button" id="tgit-market-save-schedule" class="button"><?php esc_html_e( 'Save refresh schedule', 'ig-trading-journal' ); ?></button>
	</form>
	<div id="tgit-market-mappings"></div>
	</section>
	<section id="tgit-members-section" hidden><h2><?php esc_html_e( 'Workspace members', 'ig-trading-journal' ); ?></h2><div id="tgit-members" class="tgit-cards"></div>
	<form id="tgit-member-form" class="tgit-form">
		<label><?php esc_html_e( 'Existing WordPress user ID', 'ig-trading-journal' ); ?><input name="wp_user_id" type="number" min="1" step="1" required></label>
		<label><?php esc_html_e( 'Role', 'ig-trading-journal' ); ?><select name="role"><option value="viewer"><?php esc_html_e( 'Viewer', 'ig-trading-journal' ); ?></option><option value="contributor"><?php esc_html_e( 'Contributor (drafts only)', 'ig-trading-journal' ); ?></option><option value="manager"><?php esc_html_e( 'Manager', 'ig-trading-journal' ); ?></option><option value="owner"><?php esc_html_e( 'Owner', 'ig-trading-journal' ); ?></option></select></label>
		<label><?php esc_html_e( 'Membership state', 'ig-trading-journal' ); ?><select name="state"><option value="active"><?php esc_html_e( 'Active', 'ig-trading-journal' ); ?></option><option value="revoked"><?php esc_html_e( 'Revoked', 'ig-trading-journal' ); ?></option></select></label>
		<button class="button"><?php esc_html_e( 'Save membership', 'ig-trading-journal' ); ?></button>
	</form>
	</section>
	</div>
	</div>
		<?php
	}
}
