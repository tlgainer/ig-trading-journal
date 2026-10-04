<?php
/**
 * Trade journal and gallery controls.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Admin;

/** Accessible non-financial forms separate from ledger entry. */
final class JournalScreen {
	/** Render journal, strategy and multiple-image workflows.
	 *
	 * @return void
	 */
	public static function render(): void {
		?>
<section id="tgit-journal-section">
<h2><?php esc_html_e( 'Trade journal', 'ig-trading-journal' ); ?></h2>
<p id="tgit-journal-status" role="status" aria-live="polite"></p>
<button type="button" id="tgit-new-trade" class="button"><?php esc_html_e( 'New trade journal', 'ig-trading-journal' ); ?></button>
<div id="tgit-trades" class="tgit-cards"></div>
<button type="button" id="tgit-more-trades" class="button" hidden><?php esc_html_e( 'Load more trades', 'ig-trading-journal' ); ?></button>
<section id="tgit-trade-detail" hidden>
	<h3 id="tgit-trade-heading"></h3>
	<div id="tgit-trade-summary"></div>
	<details><summary><?php esc_html_e( 'Journal revision history', 'ig-trading-journal' ); ?></summary><div id="tgit-trade-history"></div></details>
	<form id="tgit-trade-form" class="tgit-form tgit-grid">
	<label><?php esc_html_e( 'Title', 'ig-trading-journal' ); ?><input name="title" maxlength="190" required></label>
	<label><?php esc_html_e( 'Asset', 'ig-trading-journal' ); ?><select name="asset_id" required></select></label>
	<label><?php esc_html_e( 'State', 'ig-trading-journal' ); ?><select name="state"><option value="planned"><?php esc_html_e( 'Planned', 'ig-trading-journal' ); ?></option><option value="open"><?php esc_html_e( 'Open', 'ig-trading-journal' ); ?></option><option value="closed"><?php esc_html_e( 'Closed', 'ig-trading-journal' ); ?></option><option value="archived"><?php esc_html_e( 'Archived', 'ig-trading-journal' ); ?></option></select></label>
	<label><?php esc_html_e( 'Strategy version', 'ig-trading-journal' ); ?><select name="strategy_version_id"></select></label>
	<label><?php esc_html_e( 'Opened on (optional)', 'ig-trading-journal' ); ?><input name="opened_on" type="date"></label>
	<label><?php esc_html_e( 'Closed on (optional)', 'ig-trading-journal' ); ?><input name="closed_on" type="date"></label>
	<label><?php esc_html_e( 'Entry and exit fill IDs (comma separated)', 'ig-trading-journal' ); ?><input name="transaction_ids" inputmode="numeric" placeholder="12, 15, 18"></label>
	<label><?php esc_html_e( 'Confidence (0–100, optional)', 'ig-trading-journal' ); ?><input name="confidence" type="number" min="0" max="100" step="1"></label>
		<?php
		$fields = array(
			'thesis'               => __( 'Thesis', 'ig-trading-journal' ),
			'entry_rationale'      => __( 'Entry rationale', 'ig-trading-journal' ),
			'exit_rationale'       => __( 'Exit rationale', 'ig-trading-journal' ),
			'emotions'             => __( 'Emotions', 'ig-trading-journal' ),
			'lessons'              => __( 'Lessons', 'ig-trading-journal' ),
			'notes'                => __( 'Notes', 'ig-trading-journal' ),
			'confluence_text'      => __( 'Confluence notes', 'ig-trading-journal' ),
			'original_confluences' => __( 'Original spreadsheet confluences', 'ig-trading-journal' ),
		);
		foreach ( $fields as $name => $label ) {
			?>
	<label><?php echo esc_html( $label ); ?><textarea name="<?php echo esc_attr( $name ); ?>" rows="3" maxlength="20000"></textarea></label>
			<?php
		}
		?>
	<label><?php esc_html_e( 'Tags (one per line)', 'ig-trading-journal' ); ?><textarea name="tags" rows="3"></textarea></label>
	<label><?php esc_html_e( 'Confluence checklist labels (one item per line)', 'ig-trading-journal' ); ?><textarea name="confluences" rows="3"></textarea></label><fieldset id="tgit-confluence-checklist"><legend><?php esc_html_e( 'Confluences present for this trade', 'ig-trading-journal' ); ?></legend><div id="tgit-confluence-items"></div></fieldset>
		<?php
		$levels = array(
			'premarket_low'     => __( 'Premarket low', 'ig-trading-journal' ),
			'premarket_high'    => __( 'Premarket high', 'ig-trading-journal' ),
			'previous_day_low'  => __( 'Previous-day low', 'ig-trading-journal' ),
			'previous_day_high' => __( 'Previous-day high', 'ig-trading-journal' ),
			'planned_stop'      => __( 'Planned stop', 'ig-trading-journal' ),
			'planned_target'    => __( 'Planned target', 'ig-trading-journal' ),
		);
		foreach ( $levels as $name => $label ) {
			?>
	<label><?php echo esc_html( $label ); ?><input name="<?php echo esc_attr( $name ); ?>" inputmode="decimal" pattern="[0-9]+([.][0-9]+)?"></label>
			<?php
		}
		?>
	<label><?php esc_html_e( 'Optional images (JPEG, PNG, WebP; multiple files)', 'ig-trading-journal' ); ?><input name="images" type="file" accept="image/jpeg,image/png,image/webp" multiple></label>
	<span class="tgit-help" tabindex="0" role="note" aria-label="<?php esc_attr_e( 'Choose several files at once, then select Upload selected images. Each image uploads separately.', 'ig-trading-journal' ); ?>" data-tip="<?php esc_attr_e( 'Choose several files at once, then select Upload selected images. Each image uploads separately.', 'ig-trading-journal' ); ?>">?</span>
	<p><?php esc_html_e( 'Journal edits and transaction links do not change cash or lots. Upload charts in the Images tab.', 'ig-trading-journal' ); ?></p>
	<button type="submit" class="button button-primary"><?php esc_html_e( 'Save journal', 'ig-trading-journal' ); ?></button>
	<button type="button" id="tgit-close-trade" class="button"><?php esc_html_e( 'Close detail', 'ig-trading-journal' ); ?></button>
	</form>
	<div id="tgit-fill-facts"></div>
	<section id="tgit-gallery-section" hidden>
	<h3><?php esc_html_e( 'Private images', 'ig-trading-journal' ); ?></h3>
	<p id="tgit-gallery-status"></p>
	<button type="button" id="tgit-compare-images" class="button" disabled><?php esc_html_e( 'Compare two selected images', 'ig-trading-journal' ); ?></button>
	<span class="tgit-help" tabindex="0" role="note" aria-label="<?php esc_attr_e( 'Select exactly two ready images in the gallery to compare them.', 'ig-trading-journal' ); ?>" data-tip="<?php esc_attr_e( 'Select exactly two ready images in the gallery to compare them.', 'ig-trading-journal' ); ?>">?</span>
	<div id="tgit-upload-progress" aria-live="polite"></div>
	<div id="tgit-gallery" class="tgit-cards"></div>
	</section>
</section>
<section id="tgit-strategy-section">
	<h3><?php esc_html_e( 'Strategies', 'ig-trading-journal' ); ?></h3>
	<p id="tgit-strategy-status" role="status" aria-live="polite"></p>
	<div id="tgit-strategies" class="tgit-cards"></div>
	<form id="tgit-strategy-form" class="tgit-form" hidden>
	<p id="tgit-strategy-editing"></p>
	<label><?php esc_html_e( 'Strategy name', 'ig-trading-journal' ); ?><input name="name" required maxlength="190"></label>
	<label><?php esc_html_e( 'Status', 'ig-trading-journal' ); ?><select name="status"><option value="active"><?php esc_html_e( 'Active', 'ig-trading-journal' ); ?></option><option value="archived"><?php esc_html_e( 'Archived', 'ig-trading-journal' ); ?></option></select></label>
	<label><?php esc_html_e( 'Description (basic HTML allowed)', 'ig-trading-journal' ); ?><textarea name="description" rows="4" maxlength="20000"></textarea></label>
	<label><?php esc_html_e( 'Rules (basic HTML allowed)', 'ig-trading-journal' ); ?><textarea name="rules" rows="4" maxlength="20000"></textarea></label>
	<label><?php esc_html_e( 'Tags (one per line)', 'ig-trading-journal' ); ?><textarea name="tags" rows="3"></textarea></label>
	<button type="submit" class="button"><?php esc_html_e( 'Save strategy version', 'ig-trading-journal' ); ?></button>
	<button type="button" id="tgit-new-strategy" class="button"><?php esc_html_e( 'Start a new strategy', 'ig-trading-journal' ); ?></button>
	</form>
</section>
<details id="tgit-media-settings-section" hidden>
	<summary><?php esc_html_e( 'Image quota and retention (workspace owner)', 'ig-trading-journal' ); ?></summary>
	<span class="tgit-help" tabindex="0" role="note" aria-label="<?php esc_attr_e( 'An owner must save this policy before images can be uploaded. Deleted images still use quota until cleanup.', 'ig-trading-journal' ); ?>" data-tip="<?php esc_attr_e( 'An owner must save this policy before images can be uploaded. Deleted images still use quota until cleanup.', 'ig-trading-journal' ); ?>">?</span>
	<p id="tgit-media-health"></p>
	<form id="tgit-media-settings-form" class="tgit-form tgit-grid">
	<label><?php esc_html_e( 'Images per trade, including trash', 'ig-trading-journal' ); ?><input name="max_images" type="number" min="1" max="100" required></label>
	<label><?php esc_html_e( 'Maximum file bytes (10 MiB = 10485760)', 'ig-trading-journal' ); ?><input name="max_file_bytes" type="number" min="1" max="10485760" required></label>
	<label><?php esc_html_e( 'Maximum decoded pixels (up to 40 million)', 'ig-trading-journal' ); ?><input name="max_pixels" type="number" min="1" max="40000000" required></label>
	<label><?php esc_html_e( 'Workspace quota bytes (1 GiB = 1073741824)', 'ig-trading-journal' ); ?><input name="quota_bytes" type="number" min="1" max="107374182400" required></label>
	<label><?php esc_html_e( 'Recoverable trash days', 'ig-trading-journal' ); ?><input name="trash_days" type="number" min="1" max="365" required></label>
	<button type="submit" class="button"><?php esc_html_e( 'Save image settings', 'ig-trading-journal' ); ?></button>
	</form>
	<button type="button" id="tgit-media-cleanup" class="button"><?php esc_html_e( 'Run expired-image cleanup', 'ig-trading-journal' ); ?></button>
</details>
<dialog id="tgit-image-dialog" aria-label="<?php esc_attr_e( 'Private trade images', 'ig-trading-journal' ); ?>"><button type="button" id="tgit-close-image" class="button"><?php esc_html_e( 'Close images', 'ig-trading-journal' ); ?></button><div id="tgit-image-view"></div></dialog>
</section>
		<?php
	}
}
