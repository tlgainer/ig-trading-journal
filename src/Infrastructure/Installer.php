<?php
/**
 * Versioned additive installation; OPS 01/02.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Infrastructure;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Migration locks and schema validation must query the database directly without a cache.

/** Installer service for the current implementation slice. */
final class Installer {
	public const VERSION = '8';

	/**
	 * Check runtime prerequisites and the installed schema marker.
	 *
	 * @return bool
	 */
	public static function ready(): bool {
		return PHP_VERSION_ID >= 80100 && extension_loaded( 'bcmath' ) && get_option( 'tgit_schema_version' ) === self::VERSION;
	}

	/**
	 * Install the initial schema on explicit single-site activation.
	 *
	 * @param bool $network_wide network wide input.
	 * @return void
	 */
	public static function activate( bool $network_wide = false ): void {
		if ( $network_wide ) {
			wp_die( esc_html__( 'Activate TG Investment Tracker separately on each site; network activation is not supported yet.', 'ig-trading-journal' ) );
		}
		if ( PHP_VERSION_ID < 80100 || ! extension_loaded( 'bcmath' ) ) {
			wp_die( esc_html__( 'TG Investment Tracker requires PHP 8.1 or newer with BCMath.', 'ig-trading-journal' ) );
		}
		self::install();
	}

	/**
	 * Apply the versioned additive schema under an exclusive migration lock.
	 *
	 * @return void
	 * @throws \RuntimeException When the operation contract cannot be satisfied.
	 */
	public static function install(): void {
		global $wpdb;
		$installed = get_option( 'tgit_schema_version' );
		if ( false !== $installed && ! in_array( $installed, array( '1', '2', '3', '4', '5', '6', '7', self::VERSION ), true ) ) {
			throw new \RuntimeException( 'Schema version is incompatible; restore matching code or use a reviewed migration.' );
		}
		$lock = 'tgit_schema_' . substr( hash( 'sha256', $wpdb->prefix . DB_NAME ), 0, 40 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) ) ) {
			throw new \RuntimeException( 'Schema installation is already in progress.' );
		}
		try {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the bundled local schema; there is no remote URL.
			$sql = file_get_contents( dirname( __DIR__, 2 ) . '/docs/001-ledger-foundation.sql' );
			if ( false === $sql ) {
				throw new \RuntimeException( 'Ledger schema file is missing.' );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the bundled additive migration.
			$journal_sql = file_get_contents( dirname( __DIR__, 2 ) . '/docs/002-trade-journal-media.sql' );
			if ( false === $journal_sql ) {
				throw new \RuntimeException( 'Journal migration is missing.' );
			}
			$sql .= $journal_sql;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the bundled additive opening-balance migration.
			$opening_sql = file_get_contents( dirname( __DIR__, 2 ) . '/docs/003-opening-balances.sql' );
			if ( false === $opening_sql ) {
				throw new \RuntimeException( 'Opening balance migration is missing.' );
			}
			$sql .= $opening_sql;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the bundled additive correction migration.
			$correction_sql = file_get_contents( dirname( __DIR__, 2 ) . '/docs/004-cash-corrections.sql' );
			if ( false === $correction_sql ) {
				throw new \RuntimeException( 'Correction migration is missing.' );
			}
			$sql .= $correction_sql;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the bundled additive replay migration.
			$replay_sql = file_get_contents( dirname( __DIR__, 2 ) . '/docs/005-security-replay.sql' );
			if ( false === $replay_sql ) {
				throw new \RuntimeException( 'Replay migration is missing.' );
			}
			$sql .= $replay_sql;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the bundled additive basis-resolution migration.
			$basis_sql = file_get_contents( dirname( __DIR__, 2 ) . '/docs/006-opening-basis-resolutions.sql' );
			if ( false === $basis_sql ) {
				throw new \RuntimeException( 'Basis resolution migration is missing.' );
			}
			$sql .= $basis_sql;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the bundled additive research migration.
			$research_sql = file_get_contents( dirname( __DIR__, 2 ) . '/docs/007-watchlists-research.sql' );
			if ( false === $research_sql ) {
				throw new \RuntimeException( 'Research migration is missing.' );
			}
			$sql .= $research_sql;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads bundled additive valuation/report migration.
			$report_sql = file_get_contents( dirname( __DIR__, 2 ) . '/docs/008-valuations-reports.sql' );
			if ( false === $report_sql ) {
				throw new \RuntimeException( 'Valuation/report migration is missing.' );
			}
			$sql .= $report_sql;
			$sql  = preg_replace( '/^--.*$/m', '', $sql );
			$sql  = str_replace( '{{prefix}}', $wpdb->prefix, $sql );
			foreach ( explode( ';', $sql ) as $statement ) {
				if ( trim( $statement ) === '' ) {
					continue;
				}
				dbDelta( trim( $statement ) . ';' );
				if ( $wpdb->last_error ) {
					throw new \RuntimeException( 'Schema installation failed. Retry after inspecting database permissions.' );
				}
				preg_match( '/CREATE TABLE (\S+)/', $statement, $match );
				$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $match[1] ), ARRAY_A );
				if ( ! $status || 'InnoDB' !== $status['Engine'] ) {
					throw new \RuntimeException( 'Ledger tables require InnoDB.' );
				}
			}
			update_option( 'tgit_schema_version', self::VERSION, false );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}
}
