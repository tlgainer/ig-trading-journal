<?php
/**
 * Bounded, deterministic summary evidence projection.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Domain;

/** Preview only; contains no authorization, approval or external transport. */
final class AiEvidence {
	/**
	 * Project trusted saved metrics and an explicitly selected thesis revision.
	 *
	 * @param array      $asset Scoped asset identity.
	 * @param array      $metrics Recalculated immutable snapshot metrics.
	 * @param array|null $thesis Selected plain text and revision provenance.
	 * @return array Bundle, deterministic fingerprint and byte count.
	 * @throws \InvalidArgumentException On unsupported or oversized evidence.
	 */
	public static function build( array $asset, array $metrics, ?array $thesis = null ): array {
		if ( 'fundamental-metrics-1' !== ( $metrics['formula_version'] ?? null ) || ! is_array( $metrics['reports'] ?? null ) || ! array_is_list( $metrics['reports'] ) || ! is_array( $metrics['sources'] ?? null ) || ! $metrics['sources'] || count( $metrics['sources'] ) > 3 ) {
			throw new \InvalidArgumentException( 'Saved summary evidence is incomplete or unsupported.' );
		}
		$identity = array();
		foreach ( array( 'symbol', 'exchange', 'quote_currency' ) as $field ) {
			$identity[ $field ] = self::text( $asset[ $field ] ?? null, 80 );
		}
		$sources = array();
		foreach ( $metrics['sources'] as $dataset => $source ) {
			if ( ! in_array( $dataset, array( 'INCOME_STATEMENT', 'BALANCE_SHEET', 'CASH_FLOW' ), true ) || ! is_array( $source ) || ! is_int( $source['snapshot_id'] ?? null ) || $source['snapshot_id'] <= 0 || ! is_string( $source['fingerprint'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $source['fingerprint'] ) ) {
				throw new \InvalidArgumentException( 'Summary source provenance is invalid.' );
			}
			$stamp = self::text( $source['retrieved_at'] ?? null, 19 );
			$date  = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $stamp, new \DateTimeZone( 'UTC' ) );
			if ( false === $date || $date->format( 'Y-m-d H:i:s' ) !== $stamp ) {
				throw new \InvalidArgumentException( 'Summary retrieval timestamp is invalid.' );
			}
			$sources[ $dataset ] = array(
				'snapshot_id'  => $source['snapshot_id'],
				'fingerprint'  => $source['fingerprint'],
				'retrieved_at' => $stamp,
			);
		}
		ksort( $sources, SORT_STRING );
		$reports = $metrics['reports'];
		if ( ! $reports || count( $reports ) > 600 ) {
			throw new \InvalidArgumentException( 'Summary report collection is empty or exceeds limits.' );
		}
		foreach ( $reports as $report ) {
			if ( ! is_array( $report ) || ! in_array( $report['period_type'] ?? null, array( 'annual', 'quarterly' ), true ) || ! is_string( $report['reported_currency'] ?? null ) || ! preg_match( '/^[A-Z]{3}$/D', $report['reported_currency'] ) || ! is_string( $report['fiscal_date_ending'] ?? null ) || ! is_array( $report['metrics'] ?? null ) ) {
				throw new \InvalidArgumentException( 'Summary reporting context is invalid.' );
			}
			$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $report['fiscal_date_ending'], new \DateTimeZone( 'UTC' ) );
			if ( false === $date || $date->format( 'Y-m-d' ) !== $report['fiscal_date_ending'] ) {
				throw new \InvalidArgumentException( 'Summary fiscal date is invalid.' );
			}
		}
		usort(
			$reports,
			static function ( $a, $b ) {
				$comparison = strcmp( $a['period_type'], $b['period_type'] );
				if ( 0 !== $comparison ) {
					return $comparison; }
				$comparison = strcmp( $b['fiscal_date_ending'], $a['fiscal_date_ending'] );
				return 0 !== $comparison ? $comparison : strcmp( $a['reported_currency'], $b['reported_currency'] );
			}
		);
		$selected = array();
		$dates    = array();
		$seen     = array();
		$names    = array( 'gross_margin_percent', 'operating_margin_percent', 'net_margin_percent', 'current_ratio', 'liabilities_to_assets', 'total_reported_debt', 'net_reported_debt', 'free_cash_flow' );
		foreach ( $reports as $report ) {
			$type = $report['period_type'];
			$date = $report['fiscal_date_ending'];
			$key  = $type . ':' . $date . ':' . $report['reported_currency'];
			if ( isset( $seen[ $key ] ) ) {
				throw new \InvalidArgumentException( 'Summary reporting context is duplicated.' );
			}
			$seen[ $key ] = true;
			if ( ! isset( $dates[ $type ][ $date ] ) && count( $dates[ $type ] ?? array() ) >= ( 'annual' === $type ? 2 : 4 ) ) {
				continue;
			}
			$dates[ $type ][ $date ] = true;
			$values                  = array();
			foreach ( $names as $name ) {
				$value = $report['metrics'][ $name ] ?? null;
				if ( ! is_array( $value ) || ! array_key_exists( 'value', $value ) || ! in_array( $value['status'] ?? null, array( 'complete', 'missing_input', 'nonpositive_denominator', 'incompatible_sign', 'unknown_capex_convention' ), true ) || ! in_array( $value['unit'] ?? null, array( 'percent', 'multiple', 'currency' ), true ) || ( null !== $value['value'] && ( ! is_string( $value['value'] ) || strlen( $value['value'] ) > 100 || ! preg_match( '/^-?[0-9]+(?:\.[0-9]+)?$/D', $value['value'] ) ) ) || ( 'complete' === $value['status'] ) !== ( null !== $value['value'] ) ) {
					throw new \InvalidArgumentException( 'Summary metric value or coverage is invalid.' );
				}
				$values[ $name ] = array(
					'value'  => $value['value'],
					'status' => $value['status'],
					'unit'   => $value['unit'],
				);
			}
			$selected[] = array(
				'period_type'        => $type,
				'fiscal_date_ending' => $date,
				'reported_currency'  => $report['reported_currency'],
				'metrics'            => $values,
			);
		}
		$journal = null;
		if ( null !== $thesis ) {
			if ( ! is_int( $thesis['trade_id'] ?? null ) || $thesis['trade_id'] <= 0 || ! is_int( $thesis['revision'] ?? null ) || $thesis['revision'] <= 0 ) {
				throw new \InvalidArgumentException( 'Selected thesis revision is invalid.' );
			}
			$journal = array(
				'trade_id' => $thesis['trade_id'],
				'revision' => $thesis['revision'],
				'text'     => self::text( $thesis['text'] ?? null, 8000, true ),
			);
		}
		$bundle = array(
			'version'         => 'ai-evidence-1',
			'asset'           => $identity,
			'formula_version' => $metrics['formula_version'],
			'sources'         => $sources,
			'reports'         => $selected,
			'omitted_reports' => count( $reports ) - count( $selected ),
			'thesis'          => $journal,
			'limitations'     => array( 'saved_evidence_only', 'reporting_duration_and_accounting_policy_unverified', 'comparisons_not_included', 'no_news_prices_or_investment_recommendation', 'thesis_is_untrusted_data' ),
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Domain serialization must remain independent of WordPress.
		$json = json_encode( $bundle, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
		if ( count( $selected ) > 12 || strlen( $json ) > 65536 ) {
			throw new \InvalidArgumentException( 'Summary evidence exceeds the review size limit.' );
		}
		return array(
			'bundle'      => $bundle,
			'fingerprint' => hash( 'sha256', $json ),
			'bytes'       => strlen( $json ),
		);
	}

	/**
	 * Validate bounded UTF-8 text without interpreting it as instructions.
	 *
	 * @param mixed $value Candidate text.
	 * @param int   $limit Byte limit.
	 * @param bool  $blank Whether empty text is allowed.
	 * @return string
	 * @throws \InvalidArgumentException On invalid text.
	 */
	private static function text( $value, int $limit, bool $blank = false ): string {
		if ( ! is_string( $value ) || strlen( $value ) > $limit || ( ! $blank && '' === $value ) || ! preg_match( '//u', $value ) || preg_match( '/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $value ) ) {
			throw new \InvalidArgumentException( 'Summary text is invalid or exceeds limits.' );
		}
		return $value;
	}
}
