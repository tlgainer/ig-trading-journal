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
	<section><h2><?php esc_html_e( 'Holdings', 'ig-trading-journal' ); ?></h2><div id="tgit-holdings" class="tgit-cards"></div><button id="tgit-more-holdings" class="button" hidden><?php esc_html_e( 'Load more holdings', 'ig-trading-journal' ); ?></button></section>
	<section><h2><?php esc_html_e( 'Transactions', 'ig-trading-journal' ); ?></h2><div id="tgit-transactions" class="tgit-cards"></div><button id="tgit-more-transactions" class="button" hidden><?php esc_html_e( 'Load more transactions', 'ig-trading-journal' ); ?></button></section>
	<section id="tgit-calculators-section">
	<h2><?php esc_html_e( 'Scenario calculators', 'ig-trading-journal' ); ?></h2>
	<p><?php esc_html_e( 'Scenarios use the currency you enter and never post transactions. Fees are entered separately from investment principal.', 'ig-trading-journal' ); ?></p>
	<div class="tgit-grid">
	<form id="tgit-crypto-calculator" class="tgit-form">
	<h3><?php esc_html_e( 'Crypto profit', 'ig-trading-journal' ); ?></h3>
	<label><?php esc_html_e( 'Currency', 'ig-trading-journal' ); ?><input name="currency" required pattern="[A-Z]{3}" maxlength="3" value="USD"></label>
	<label><?php esc_html_e( 'Buy price per unit', 'ig-trading-journal' ); ?><input name="buy_price" required inputmode="decimal" pattern="[0-9]+([.][0-9]+)?"></label>
	<label><?php esc_html_e( 'Sell or current price per unit', 'ig-trading-journal' ); ?><input name="sell_price" required inputmode="decimal" pattern="[0-9]+([.][0-9]+)?"></label>
	<label><?php esc_html_e( 'Investment principal, excluding fees', 'ig-trading-journal' ); ?><input name="investment" required inputmode="decimal" pattern="[0-9]+([.][0-9]+)?"></label>
	<label><?php esc_html_e( 'Buy fee', 'ig-trading-journal' ); ?><input name="buy_fee" inputmode="decimal" pattern="[0-9]+([.][0-9]+)?" value="0"></label>
	<label><?php esc_html_e( 'Sell fee', 'ig-trading-journal' ); ?><input name="sell_fee" inputmode="decimal" pattern="[0-9]+([.][0-9]+)?" value="0"></label>
	<label><?php esc_html_e( 'Scenario note', 'ig-trading-journal' ); ?><input name="note" maxlength="500"></label>
	<button class="button button-primary"><?php esc_html_e( 'Calculate profit', 'ig-trading-journal' ); ?></button>
	<output id="tgit-crypto-result" aria-live="polite"></output>
	</form>
	<form id="tgit-risk-calculator" class="tgit-form">
	<h3><?php esc_html_e( 'Long-position risk', 'ig-trading-journal' ); ?></h3>
	<p><?php esc_html_e( 'Risk budget is the amount you are willing to lose, not the amount you invest. Entry 100, stop distance 5 and risk budget 200 sizes 40 units. Assumes exit at the stop; fees, slippage and gaps can increase loss.', 'ig-trading-journal' ); ?></p>
	<label><?php esc_html_e( 'Currency', 'ig-trading-journal' ); ?><input name="currency" required pattern="[A-Z]{3}" maxlength="3" value="USD"></label>
	<label><?php esc_html_e( 'Entry price per unit', 'ig-trading-journal' ); ?><input name="entry_price" required inputmode="decimal" pattern="[0-9]+([.][0-9]+)?"></label>
	<label><?php esc_html_e( 'Risk budget', 'ig-trading-journal' ); ?><input name="risk_budget" required inputmode="decimal" pattern="[0-9]+([.][0-9]+)?"></label>
	<label><?php esc_html_e( 'Stop distance below entry', 'ig-trading-journal' ); ?><input name="stop_distance" required inputmode="decimal" pattern="[0-9]+([.][0-9]+)?"></label>
	<label><?php esc_html_e( 'Scenario note', 'ig-trading-journal' ); ?><input name="note" maxlength="500"></label>
	<button class="button button-primary"><?php esc_html_e( 'Calculate size', 'ig-trading-journal' ); ?></button>
	<output id="tgit-risk-result" aria-live="polite"></output>
	</form>
	<form id="tgit-leveraged-calculator" class="tgit-form">
	<h3><?php esc_html_e( 'Linear leveraged crypto', 'ig-trading-journal' ); ?></h3>
	<p><?php esc_html_e( 'Hypothetical linear exposure only. Entry, exit, collateral and costs must use the same currency. Inverse contracts and currency conversions are not supported.', 'ig-trading-journal' ); ?></p>
	<label><?php esc_html_e( 'Currency', 'ig-trading-journal' ); ?><input name="currency" required pattern="[A-Z]{3}" maxlength="3" value="USD"></label>
	<label><?php esc_html_e( 'Direction', 'ig-trading-journal' ); ?><select name="direction"><option value="long"><?php esc_html_e( 'Long', 'ig-trading-journal' ); ?></option><option value="short"><?php esc_html_e( 'Short', 'ig-trading-journal' ); ?></option></select></label>
	<label><?php esc_html_e( 'Entry price per unit', 'ig-trading-journal' ); ?><input name="entry_price" required inputmode="decimal" pattern="[0-9]+([.][0-9]+)?"></label>
	<label><?php esc_html_e( 'Proposed exit price per unit', 'ig-trading-journal' ); ?><input name="exit_price" required inputmode="decimal" pattern="[0-9]+([.][0-9]+)?"></label>
	<label><?php esc_html_e( 'Collateral, excluding costs', 'ig-trading-journal' ); ?><input name="collateral" required inputmode="decimal" pattern="[0-9]+([.][0-9]+)?" aria-describedby="tgit-leverage-help"></label>
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
	<form id="tgit-stock-calculator" class="tgit-form">
	<h3><?php esc_html_e( 'Stock profit', 'ig-trading-journal' ); ?></h3>
	<p><?php esc_html_e( 'Long estimates buying shares and selling later. Short estimates selling borrowed shares and buying them back. Use the same currency for prices and all cost amounts.', 'ig-trading-journal' ); ?></p>
	<label><?php esc_html_e( 'Currency', 'ig-trading-journal' ); ?><input name="currency" required pattern="[A-Z]{3}" maxlength="3" value="USD"></label>
	<label><?php esc_html_e( 'Direction', 'ig-trading-journal' ); ?><select name="direction"><option value="long"><?php esc_html_e( 'Long', 'ig-trading-journal' ); ?></option><option value="short"><?php esc_html_e( 'Short', 'ig-trading-journal' ); ?></option></select></label>
	<label><?php esc_html_e( 'Entry price per share', 'ig-trading-journal' ); ?><input name="entry_price" required inputmode="decimal" pattern="[0-9]+([.][0-9]+)?"></label>
	<label><?php esc_html_e( 'Proposed sale or cover price per share', 'ig-trading-journal' ); ?><input name="exit_price" required inputmode="decimal" pattern="[0-9]+([.][0-9]+)?"></label>
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
	<form id="tgit-short-risk-calculator" class="tgit-form">
	<h3><?php esc_html_e( 'Short-position risk', 'ig-trading-journal' ); ?></h3>
	<p><?php esc_html_e( 'Size whole shares using a stop price above entry. Risk budget is the total loss you are willing to accept, not margin or investment capital.', 'ig-trading-journal' ); ?></p>
	<label><?php esc_html_e( 'Currency', 'ig-trading-journal' ); ?><input name="currency" required pattern="[A-Z]{3}" maxlength="3" value="USD"></label>
	<label><?php esc_html_e( 'Entry price per share', 'ig-trading-journal' ); ?><input name="entry_price" required inputmode="decimal" pattern="[0-9]+([.][0-9]+)?"></label>
	<label><?php esc_html_e( 'Stop price above entry', 'ig-trading-journal' ); ?><input name="stop_price" required inputmode="decimal" pattern="[0-9]+([.][0-9]+)?"></label>
	<label><?php esc_html_e( 'Total risk budget', 'ig-trading-journal' ); ?><input name="risk_budget" required inputmode="decimal" pattern="[0-9]+([.][0-9]+)?"></label>
	<label><?php esc_html_e( 'Estimated total costs at the stop', 'ig-trading-journal' ); ?><input name="estimated_costs" inputmode="decimal" pattern="[0-9]+([.][0-9]+)?" value="0" aria-describedby="tgit-short-risk-help"></label>
	<p id="tgit-short-risk-help"><?php esc_html_e( 'Include estimated entry/exit fees, borrowing, dividend payments and slippage as a total amount. Costs are reserved before sizing; recheck the estimate for the resulting share count. Gaps and changing costs can increase actual loss.', 'ig-trading-journal' ); ?></p>
	<label><?php esc_html_e( 'Scenario note', 'ig-trading-journal' ); ?><input name="note" maxlength="500"></label>
	<button class="button button-primary"><?php esc_html_e( 'Calculate short size', 'ig-trading-journal' ); ?></button>
	<output id="tgit-short-risk-result" aria-live="polite"></output>
	</form>
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
	</section>
		<?php JournalScreen::render(); ?>
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
