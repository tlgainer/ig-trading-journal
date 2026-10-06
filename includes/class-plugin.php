<?php
/**
 * Plugin initialization.
 *
 * @package IGTradingJournal
 */

namespace GainerInteractive\IGTradingJournal;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Boots the plugin and its WordPress integrations.
 */
final class Plugin {
	/**
	 * Register plugin hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( self::class, 'load_textdomain' ) );
		add_action( 'rest_api_init', array( \GainerInteractive\IGTradingJournal\Http\Controller::class, 'register' ) );
		add_action( 'admin_menu', array( \GainerInteractive\IGTradingJournal\Admin\Screen::class, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( \GainerInteractive\IGTradingJournal\Admin\Screen::class, 'enqueue' ) );
		add_action( 'tgit_media_cleanup', array( \GainerInteractive\IGTradingJournal\Infrastructure\MediaJobs::class, 'cleanup' ), 10, 2 );
		add_action( 'admin_notices', array( self::class, 'notice' ) );
		add_action( 'tgit_quote_refresh', array( \GainerInteractive\IGTradingJournal\Infrastructure\QuoteRefresh::class, 'job' ), 10, 4 );
		add_action( 'init', array( \GainerInteractive\IGTradingJournal\Infrastructure\RecurringQuotes::class, 'boot' ) );
		add_action( 'tgit_quote_schedule_scan', array( \GainerInteractive\IGTradingJournal\Infrastructure\RecurringQuotes::class, 'scan' ) );
		add_action( 'tgit_scheduled_quote_refresh', array( \GainerInteractive\IGTradingJournal\Infrastructure\RecurringQuotes::class, 'job' ), 10, 3 );
	}

	/** Explain unmet prerequisites without automatically applying future migrations. */
	public static function notice() {
		if ( current_user_can( 'manage_options' ) && ! \GainerInteractive\IGTradingJournal\Infrastructure\Installer::ready() ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'TG Investment Tracker needs PHP 8.1+, BCMath, and the current database schema. Back up the database, then reactivate the plugin to apply the bundled additive migrations.', 'ig-trading-journal' ) . '</p></div>';
		}
	}

	/**
	 * Load translations bundled with the plugin.
	 *
	 * @return void
	 */
	public static function load_textdomain() {
		load_plugin_textdomain(
			'ig-trading-journal',
			false,
			dirname( plugin_basename( IG_TRADING_JOURNAL_FILE ) ) . '/languages'
		);
	}
}
