<?php
/**
 * Plugin Name: IG Trading Journal
 * Description: A trading journal plugin for WordPress.
 * Version: 0.14.0
 * Requires at least: 6.8
 * Requires PHP: 8.1
 * Author: Gainer Interactive
 * Text Domain: ig-trading-journal
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package IGTradingJournal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'IG_TRADING_JOURNAL_VERSION', '0.14.0' );
define( 'IG_TRADING_JOURNAL_FILE', __FILE__ );

require_once __DIR__ . '/includes/class-plugin.php';

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'GainerInteractive\\IGTradingJournal\\';
		if ( strpos( $class_name, $prefix ) !== 0 ) {
			return;
		}
		$path = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_file( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook( __FILE__, array( \GainerInteractive\IGTradingJournal\Infrastructure\Installer::class, 'activate' ) );

\GainerInteractive\IGTradingJournal\Plugin::init();
