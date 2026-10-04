<?php
/**
 * WordPress admin controls for manual valuations and reports.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Admin;

/** Render scoped controls; server authorization remains authoritative. */
final class ReportScreen {
	/**
	 * Render the report and observation section.
	 *
	 * @return void
	 */
	public static function render(): void {
		?>
	<section id="tgit-reports-section">
	<h2><?php esc_html_e( 'Prices, FX and reports', 'ig-trading-journal' ); ?></h2>
	<p id="tgit-report-status" role="status" aria-live="polite"></p>
	<div id="tgit-observation-editor" hidden>
	<h3><?php esc_html_e( 'Record a manual observation', 'ig-trading-journal' ); ?></h3>
	<p><?php esc_html_e( 'FX rate means one unit of native currency in workspace base currency. Example: EUR to USD at 1.10 means 1 EUR = 1.10 USD. Prices use the asset quote currency. Expired observations remain visibly stale. Corrections retain the original record.', 'ig-trading-journal' ); ?></p>
	<form id="tgit-observation-form" class="tgit-form">
	<label><?php esc_html_e( 'Kind', 'ig-trading-journal' ); ?><select name="kind"><option value="price"><?php esc_html_e( 'Asset price', 'ig-trading-journal' ); ?></option><option value="fx"><?php esc_html_e( 'Native to base FX', 'ig-trading-journal' ); ?></option></select></label>
	<label id="tgit-observation-asset-label"><?php esc_html_e( 'Asset', 'ig-trading-journal' ); ?><select name="asset_id" required></select></label>
	<label><?php esc_html_e( 'Native / quote currency', 'ig-trading-journal' ); ?><input name="currency" required pattern="[A-Z]{3}" maxlength="3"></label>
	<label><?php esc_html_e( 'Price or FX rate', 'ig-trading-journal' ); ?><input name="value" required inputmode="decimal"></label>
	<label><?php esc_html_e( 'Effective date', 'ig-trading-journal' ); ?><input name="effective_date" type="date" required></label>
	<label><?php esc_html_e( 'Valid through', 'ig-trading-journal' ); ?><input name="expires_on" type="date" required></label>
	<label><?php esc_html_e( 'Source', 'ig-trading-journal' ); ?><input name="source" required maxlength="190"></label>
	<label><?php esc_html_e( 'Reason / valuation note', 'ig-trading-journal' ); ?><input name="reason" required maxlength="500"></label>
	<button class="button button-primary"><?php esc_html_e( 'Save observation', 'ig-trading-journal' ); ?></button>
	<button id="tgit-observation-cancel" type="button" class="button" hidden><?php esc_html_e( 'Cancel correction', 'ig-trading-journal' ); ?></button>
	</form></div>
	<h3><?php esc_html_e( 'Observation history', 'ig-trading-journal' ); ?></h3>
	<div id="tgit-observations" class="tgit-cards"></div><button id="tgit-observations-more" type="button" class="button" hidden><?php esc_html_e( 'Load more observations', 'ig-trading-journal' ); ?></button>
	<h3><?php esc_html_e( 'Run a report', 'ig-trading-journal' ); ?></h3>
	<p><?php esc_html_e( 'Reports save an immutable snapshot of selected observations and calculation metadata. As-of reconstructs posted history through that date. Start date filters activity and gains. Allocation includes filtered cash. Income posting remains unavailable.', 'ig-trading-journal' ); ?></p>
	<form id="tgit-report-form" class="tgit-form">
	<label><?php esc_html_e( 'Report', 'ig-trading-journal' ); ?><select name="type">
		<?php
		foreach ( array(
			'holdings'   => __( 'Holdings', 'ig-trading-journal' ),
			'activity'   => __( 'Activity', 'ig-trading-journal' ),
			'gains'      => __( 'Realized gains', 'ig-trading-journal' ),
			'cash'       => __( 'Cash activity', 'ig-trading-journal' ),
			'income'     => __( 'Income availability', 'ig-trading-journal' ),
			'allocation' => __( 'Allocation', 'ig-trading-journal' ),
			'strategy'   => __( 'Strategy performance', 'ig-trading-journal' ),
		) as $value => $label ) :
			?>
	<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
	<?php endforeach; ?></select></label>
	<label><?php esc_html_e( 'Start date (optional)', 'ig-trading-journal' ); ?><input name="from" type="date"></label>
	<label><?php esc_html_e( 'As-of date', 'ig-trading-journal' ); ?><input name="as_of" type="date" required></label>
	<label><?php esc_html_e( 'Account', 'ig-trading-journal' ); ?><select name="account_id"></select></label>
	<label><?php esc_html_e( 'Asset / symbol', 'ig-trading-journal' ); ?><select name="asset_id"></select></label>
	<label><?php esc_html_e( 'Currency (optional)', 'ig-trading-journal' ); ?><input name="currency" pattern="[A-Z]{3}" maxlength="3"></label>
	<label><?php esc_html_e( 'Asset class', 'ig-trading-journal' ); ?><select name="asset_class"><option value=""><?php esc_html_e( 'All', 'ig-trading-journal' ); ?></option><option value="stock"><?php esc_html_e( 'Stock', 'ig-trading-journal' ); ?></option><option value="etf"><?php esc_html_e( 'ETF', 'ig-trading-journal' ); ?></option><option value="crypto"><?php esc_html_e( 'Crypto', 'ig-trading-journal' ); ?></option></select></label>
	<div id="tgit-report-trade-filters">
	<label><?php esc_html_e( 'Action', 'ig-trading-journal' ); ?><select name="action"><option value=""><?php esc_html_e( 'All', 'ig-trading-journal' ); ?></option>
		<?php
		foreach ( array( 'buy', 'sell', 'deposit', 'withdrawal', 'opening_cash', 'opening_lot' ) as $action ) :
			?>
		<option value="<?php echo esc_attr( $action ); ?>"><?php echo esc_html( $action ); ?></option><?php endforeach; ?></select></label>
	<label><?php esc_html_e( 'Captured strategy version', 'ig-trading-journal' ); ?><select name="strategy_version_id"></select></label>
	<label><?php esc_html_e( 'Journal tags (comma separated)', 'ig-trading-journal' ); ?><input name="tags" maxlength="1000"></label>
	<label><?php esc_html_e( 'Ready private images', 'ig-trading-journal' ); ?><select name="images"><option value=""><?php esc_html_e( 'Any', 'ig-trading-journal' ); ?></option><option value="yes"><?php esc_html_e( 'With images', 'ig-trading-journal' ); ?></option><option value="no"><?php esc_html_e( 'Without images', 'ig-trading-journal' ); ?></option></select></label>
	</div>
	<button class="button button-primary"><?php esc_html_e( 'Generate report', 'ig-trading-journal' ); ?></button>
	</form>
	<form id="tgit-view-form" class="tgit-form">
	<label><?php esc_html_e( 'My saved views', 'ig-trading-journal' ); ?><select name="view_id"><option value=""><?php esc_html_e( 'New view', 'ig-trading-journal' ); ?></option></select></label>
	<label><?php esc_html_e( 'View name', 'ig-trading-journal' ); ?><input name="name" required maxlength="190"></label>
	<button class="button"><?php esc_html_e( 'Save current filters', 'ig-trading-journal' ); ?></button>
	</form>
	<form id="tgit-report-load-form" class="tgit-form"><label><?php esc_html_e( 'Saved report ID', 'ig-trading-journal' ); ?><input name="report_id" required type="number" min="1" step="1" list="tgit-report-history"></label><datalist id="tgit-report-history"></datalist><button class="button"><?php esc_html_e( 'Load saved report', 'ig-trading-journal' ); ?></button></form>
	<button id="tgit-reports-more" type="button" class="button" hidden><?php esc_html_e( 'Load more saved reports', 'ig-trading-journal' ); ?></button>
	<div id="tgit-report-summary"></div><div id="tgit-report-results" class="tgit-cards"></div>
	</section>
		<?php
	}
}
