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
		wp_enqueue_script( 'tgit-rest-url', plugins_url( 'assets/rest-url.js', IG_TRADING_JOURNAL_FILE ), array(), IG_TRADING_JOURNAL_VERSION, true );
		wp_enqueue_script( 'tgit-tabs', plugins_url( 'assets/tabs.js', IG_TRADING_JOURNAL_FILE ), array(), IG_TRADING_JOURNAL_VERSION, true );
		wp_enqueue_script( 'tgit-admin', plugins_url( 'assets/admin.js', IG_TRADING_JOURNAL_FILE ), array( 'tgit-rest-url', 'tgit-tabs' ), IG_TRADING_JOURNAL_VERSION, true );
		wp_enqueue_script( 'tgit-opening', plugins_url( 'assets/opening.js', IG_TRADING_JOURNAL_FILE ), array( 'tgit-admin' ), IG_TRADING_JOURNAL_VERSION, true );
		wp_enqueue_script( 'tgit-journal', plugins_url( 'assets/journal.js', IG_TRADING_JOURNAL_FILE ), array( 'tgit-admin' ), IG_TRADING_JOURNAL_VERSION, true );
		wp_enqueue_script( 'tgit-calculators', plugins_url( 'assets/calculators.js', IG_TRADING_JOURNAL_FILE ), array( 'tgit-admin' ), IG_TRADING_JOURNAL_VERSION, true );
		wp_localize_script(
			'tgit-admin',
			'tgitConfig',
			array(
				'root'      => esc_url_raw( rest_url( 'tgit/v1/' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'actorId'   => get_current_user_id(),
				'canCreate' => current_user_can( 'manage_options' ),
				'i18n'      => array(
					'loading' => __( 'Loading…', 'ig-trading-journal' ),
					'empty'   => __( 'No records yet.', 'ig-trading-journal' ),
					'saved'   => __( 'Saved.', 'ig-trading-journal' ),
					'unknown' => __( 'Missing price', 'ig-trading-journal' ),
					'edit'    => __( 'Edit draft', 'ig-trading-journal' ),
					'post'    => __( 'Post draft', 'ig-trading-journal' ),
					'editing' => __( 'Editing draft', 'ig-trading-journal' ),
					'network' => __( 'Request failed. Retry to use the same transaction key.', 'ig-trading-journal' ),
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
	<p><?php esc_html_e( 'FIFO ledger · native currencies · chronological entry. Record funding before purchases. Prices and FX are not available in this build.', 'ig-trading-journal' ); ?></p>
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
			'settings'     => __( 'Settings', 'ig-trading-journal' ),
		) as $tab => $label ) :
			?>
		<button type="button" id="tgit-tab-<?php echo esc_attr( $tab ); ?>" role="tab" aria-controls="tgit-panel-<?php echo esc_attr( $tab ); ?>" aria-selected="false" tabindex="-1" data-tab="<?php echo esc_attr( $tab ); ?>"><?php echo esc_html( $label ); ?></button>
		<?php endforeach; ?>
	</div>
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
	<label><?php esc_html_e( 'Currency', 'ig-trading-journal' ); ?><input name="currency" required pattern="[A-Z]{3}" maxlength="3" value="USD"></label>
	<label><?php esc_html_e( 'Entry price per unit', 'ig-trading-journal' ); ?><input name="entry_price" required inputmode="decimal" pattern="[0-9]+([.][0-9]+)?"></label>
	<label><?php esc_html_e( 'Risk budget', 'ig-trading-journal' ); ?><input name="risk_budget" required inputmode="decimal" pattern="[0-9]+([.][0-9]+)?"></label>
	<label><?php esc_html_e( 'Stop distance below entry', 'ig-trading-journal' ); ?><input name="stop_distance" required inputmode="decimal" pattern="[0-9]+([.][0-9]+)?"></label>
	<label><?php esc_html_e( 'Scenario note', 'ig-trading-journal' ); ?><input name="note" maxlength="500"></label>
	<button class="button button-primary"><?php esc_html_e( 'Calculate size', 'ig-trading-journal' ); ?></button>
	<output id="tgit-risk-result" aria-live="polite"></output>
	</form>
	</div>
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
