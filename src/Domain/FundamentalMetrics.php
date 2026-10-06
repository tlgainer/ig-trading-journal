<?php
/**
 * Reproducible statement metrics with explicit period and currency coverage.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Domain;

/** Pure calculations; does not infer reporting dates, growth or investment decisions. */
final class FundamentalMetrics {

	/**
	 * Calculate only within matching statement periods and reporting currencies.
	 * Capital expenditures require an explicit, verified provider sign convention.
	 *
	 * @param array       $evidence Normalized statement envelopes keyed by dataset.
	 * @param string|null $capex_convention Positive or negative cash outflow, or unknown.
	 * @return array
	 * @throws \InvalidArgumentException On incompatible identities or malformed evidence.
	 */
	public static function calculate( array $evidence, ?string $capex_convention = null ): array {
		if ( ! in_array( $capex_convention, array( null, 'positive_outflow', 'negative_outflow' ), true ) ) {
			throw new \InvalidArgumentException( 'Unsupported capital-expenditure sign convention.' );
		}
		$groups   = array();
		$identity = null;
		ksort( $evidence, SORT_STRING );
		foreach ( $evidence as $dataset => $envelope ) {
			if ( ! in_array( $dataset, array( 'INCOME_STATEMENT', 'BALANCE_SHEET', 'CASH_FLOW' ), true ) || ! is_array( $envelope ) || ( $envelope['dataset'] ?? null ) !== $dataset || ! is_string( $envelope['provider'] ?? null ) || ! is_string( $envelope['provider_symbol'] ?? null ) || '' === $envelope['provider_symbol'] ) {
				throw new \InvalidArgumentException( 'Metric evidence identity or dataset is invalid.' );
			}
			$current = array( $envelope['provider'], $envelope['provider_symbol'] );
			if ( null !== $identity && $identity !== $current ) {
				throw new \InvalidArgumentException( 'Metric evidence must describe one provider identity.' );
			}
			$identity = $current;
			$reports  = $envelope['reports'] ?? null;
			if ( ! is_array( $reports ) || ! array_is_list( $reports ) || count( $reports ) > 200 ) {
				throw new \InvalidArgumentException( 'Metric report collection is invalid or exceeds limits.' );
			}
			$seen = array();
			foreach ( $reports as $report ) {
				self::validate_report( $report );
				$period = $report['period_type'] . ':' . $report['fiscal_date_ending'];
				if ( isset( $seen[ $period ] ) ) {
					throw new \InvalidArgumentException( 'Metric evidence has duplicate fiscal periods.' );
				}
				$seen[ $period ]            = true;
				$key                        = $period . ':' . $report['reported_currency'];
				$groups[ $key ][ $dataset ] = $report;
			}
		}
		ksort( $groups, SORT_STRING );
		$results = array();
		foreach ( $groups as $statements ) {
			$context   = reset( $statements );
			$income    = $statements['INCOME_STATEMENT']['values'] ?? array();
			$balance   = $statements['BALANCE_SHEET']['values'] ?? array();
			$cash      = $statements['CASH_FLOW']['values'] ?? array();
			$revenue   = $income['totalRevenue'] ?? null;
			$debt      = self::debt( $balance );
			$metrics   = array(
				'gross_margin_percent'     => self::ratio( $income['grossProfit'] ?? null, $revenue, '100', 'percent' ),
				'operating_margin_percent' => self::ratio( $income['operatingIncome'] ?? null, $revenue, '100', 'percent' ),
				'net_margin_percent'       => self::ratio( $income['netIncome'] ?? null, $revenue, '100', 'percent' ),
				'current_ratio'            => self::ratio( $balance['totalCurrentAssets'] ?? null, $balance['totalCurrentLiabilities'] ?? null, '1', 'multiple', true ),
				'liabilities_to_assets'    => self::ratio( $balance['totalLiabilities'] ?? null, $balance['totalAssets'] ?? null, '1', 'multiple', true ),
				'total_reported_debt'      => $debt,
				'net_reported_debt'        => self::net_debt( $debt, $balance['cashAndCashEquivalentsAtCarryingValue'] ?? null ),
				'free_cash_flow'           => self::free_cash_flow( $cash, $capex_convention ),
			);
			$results[] = array(
				'period_type'        => $context['period_type'],
				'fiscal_date_ending' => $context['fiscal_date_ending'],
				'reported_currency'  => $context['reported_currency'],
				'datasets'           => array_keys( $statements ),
				'metrics'            => $metrics,
			);
		}
		return array(
			'formula_version'    => 'fundamental-metrics-1',
			'capex_convention'   => $capex_convention,
			'reports'            => $results,
			'comparison_version' => 'fundamental-comparisons-1',
			'comparisons'        => self::comparisons( $results ),
		);
	}

	/**
	 * Compare previous available periods, without inferring year/quarter growth.
	 *
	 * @param array $reports Validated calculated reports.
	 * @return array
	 */
	private static function comparisons( array $reports ): array {
		$periods = array();
		foreach ( $reports as $report ) {
			$periods[ $report['period_type'] ][ $report['fiscal_date_ending'] ][] = $report;
		}
		$output = array();
		foreach ( $periods as $type => $dates ) {
			ksort( $dates, SORT_STRING );
			$previous = null;
			foreach ( $dates as $date => $current ) {
				foreach ( $current as $report ) {
					$prior   = null === $previous ? null : $previous[0];
					$context = null === $prior ? 'missing_prior_period' : ( count( $current ) > 1 || count( $previous ) > 1 ? 'ambiguous_currency' : ( $prior['reported_currency'] !== $report['reported_currency'] ? 'currency_changed' : 'complete' ) );
					$changes = array();
					foreach ( $report['metrics'] as $name => $metric ) {
						$baseline         = null !== $previous && count( $previous ) > 1 ? null : ( $prior['metrics'][ $name ]['value'] ?? null );
						$status           = 'complete' !== $context ? $context : ( null === $metric['value'] || null === $baseline ? 'unavailable_metric' : 'complete' );
						$delta            = 'complete' === $status ? Decimal::sub( $metric['value'], $baseline ) : null;
						$changes[ $name ] = array(
							'prior_value'   => $baseline,
							'current_value' => $metric['value'],
							'change'        => $delta,
							'unit'          => 'percent' === $metric['unit'] ? 'percentage_points' : $metric['unit'],
							'status'        => $status,
						);
					}
					$output[] = array(
						'period_type'        => $type,
						'fiscal_date_ending' => $date,
						'prior_date_ending'  => $prior['fiscal_date_ending'] ?? null,
						'reported_currency'  => $report['reported_currency'],
						'prior_currency'     => null !== $previous && count( $previous ) > 1 ? null : ( $prior['reported_currency'] ?? null ),
						'basis'              => 'previous_available_period',
						'days_between'       => null === $prior ? null : (int) ( new \DateTimeImmutable( $prior['fiscal_date_ending'] ) )->diff( new \DateTimeImmutable( $date ) )->format( '%a' ),
						'changes'            => $changes,
					);
				}
				$previous = $current;
			}
		}
		return $output;
	}

	/**
	 * Validate normalized signed values without floats or WordPress dependencies.
	 *
	 * @param mixed $report Normalized report.
	 * @return void
	 * @throws \InvalidArgumentException On invalid normalized evidence.
	 */
	private static function validate_report( $report ): void {
		if ( ! is_array( $report ) || ! in_array( $report['period_type'] ?? null, array( 'annual', 'quarterly' ), true ) || ! is_string( $report['reported_currency'] ?? null ) || ! preg_match( '/^[A-Z]{3}$/D', $report['reported_currency'] ) || ! is_string( $report['fiscal_date_ending'] ?? null ) || ! preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $report['fiscal_date_ending'] ) || ! is_array( $report['values'] ?? null ) ) {
			throw new \InvalidArgumentException( 'Metric report context is invalid.' );
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $report['fiscal_date_ending'] );
		if ( false === $date || $date->format( 'Y-m-d' ) !== $report['fiscal_date_ending'] ) {
			throw new \InvalidArgumentException( 'Metric fiscal date is invalid.' );
		}
		foreach ( $report['values'] as $value ) {
			if ( null === $value ) {
				continue;
			}
			if ( ! is_string( $value ) || ! preg_match( '/^-?(0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $value ) ) {
				throw new \InvalidArgumentException( 'Metric facts must be exact decimal strings.' );
			}
			Decimal::input( ltrim( $value, '-' ) );
		}
	}

	/**
	 * Ratio with a positive denominator and explicit missing/invalid coverage.
	 *
	 * @param string|null $numerator Numerator.
	 * @param string|null $denominator Denominator.
	 * @param string      $scale Percent or multiple scaling.
	 * @param string      $unit Output unit.
	 * @param bool        $nonnegative Whether negative numerators are incompatible.
	 * @return array
	 */
	private static function ratio( ?string $numerator, ?string $denominator, string $scale, string $unit, bool $nonnegative = false ): array {
		if ( null === $numerator || null === $denominator ) {
			return self::result( null, 'missing_input', $unit );
		}
		if ( Decimal::compare( $denominator, '0' ) <= 0 ) {
			return self::result( null, 'nonpositive_denominator', $unit );
		}
		if ( $nonnegative && Decimal::compare( $numerator, '0' ) < 0 ) {
			return self::result( null, 'incompatible_sign', $unit );
		}
		return self::result( Decimal::mul( Decimal::div( $numerator, $denominator ), $scale ), 'complete', $unit );
	}

	/**
	 * Sum only the two reported debt fields; never equate liabilities with debt.
	 *
	 * @param array $balance Balance-sheet facts.
	 * @return array
	 */
	private static function debt( array $balance ): array {
		$short = $balance['shortTermDebt'] ?? null;
		$long  = $balance['longTermDebt'] ?? null;
		if ( null === $short || null === $long ) {
			return self::result( null, 'missing_input', 'currency' );
		}
		if ( Decimal::compare( $short, '0' ) < 0 || Decimal::compare( $long, '0' ) < 0 ) {
			return self::result( null, 'incompatible_sign', 'currency' );
		}
		return self::result( Decimal::add( $short, $long ), 'complete', 'currency' );
	}

	/**
	 * Report debt less cash; a negative result represents net cash.
	 *
	 * @param array       $debt Reported debt result.
	 * @param string|null $cash Reported cash/equivalents.
	 * @return array
	 */
	private static function net_debt( array $debt, ?string $cash ): array {
		if ( null === $debt['value'] || null === $cash ) {
			return self::result( null, null === $debt['value'] ? $debt['status'] : 'missing_input', 'currency' );
		}
		if ( Decimal::compare( $cash, '0' ) < 0 ) {
			return self::result( null, 'incompatible_sign', 'currency' );
		}
		return self::result( Decimal::sub( $debt['value'], $cash ), 'complete', 'currency' );
	}

	/**
	 * Subtract verified cash outflow; never guess or use absolute value for capex.
	 *
	 * @param array       $cash Cash-flow facts.
	 * @param string|null $convention Explicit source convention.
	 * @return array
	 */
	private static function free_cash_flow( array $cash, ?string $convention ): array {
		$operating = $cash['operatingCashflow'] ?? null;
		$capex     = $cash['capitalExpenditures'] ?? null;
		if ( null === $operating || null === $capex ) {
			return self::result( null, 'missing_input', 'currency' );
		}
		if ( null === $convention ) {
			return self::result( null, 'unknown_capex_convention', 'currency' );
		}
		$sign = Decimal::compare( $capex, '0' );
		if ( ( 'positive_outflow' === $convention && $sign < 0 ) || ( 'negative_outflow' === $convention && $sign > 0 ) ) {
			return self::result( null, 'incompatible_sign', 'currency' );
		}
		return self::result( 'positive_outflow' === $convention ? Decimal::sub( $operating, $capex ) : Decimal::add( $operating, $capex ), 'complete', 'currency' );
	}

	/**
	 * One metric with its exact value, unit and reason when unavailable.
	 *
	 * @param string|null $value Value.
	 * @param string      $status Coverage reason.
	 * @param string      $unit Unit.
	 * @return array
	 */
	private static function result( ?string $value, string $status, string $unit ): array {
		return array(
			'value'  => $value,
			'status' => $status,
			'unit'   => $unit,
		);
	}
}
