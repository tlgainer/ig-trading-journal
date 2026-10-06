<?php
/**
 * Exact, bounded fundamental evidence from Alpha Vantage.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Infrastructure;

use GainerInteractive\IGTradingJournal\Domain\Decimal;

/** Parsing only; never fetches, persists, authorizes or generates investment advice. */
final class AlphaVantageFundamentals {

	private const FIELDS = array(
		'INCOME_STATEMENT' => array( 'totalRevenue', 'grossProfit', 'operatingIncome', 'netIncome', 'ebitda', 'interestExpense' ),
		'BALANCE_SHEET'    => array( 'totalAssets', 'totalLiabilities', 'totalShareholderEquity', 'totalCurrentAssets', 'totalCurrentLiabilities', 'cashAndCashEquivalentsAtCarryingValue', 'shortTermDebt', 'longTermDebt' ),
		'CASH_FLOW'        => array( 'operatingCashflow', 'capitalExpenditures', 'cashflowFromInvestment', 'cashflowFromFinancing', 'netIncome', 'dividendPayout' ),
	);

	/**
	 * Normalize separate annual and quarterly reports, retaining reporting currencies.
	 * Retrieval/publication dates and endpoint entitlement remain caller responsibilities.
	 *
	 * @param string $body Provider JSON.
	 * @param string $symbol Expected explicitly mapped symbol.
	 * @param string $dataset One supported statement endpoint.
	 * @return array
	 * @throws \InvalidArgumentException On malformed or ambiguous evidence.
	 */
	public static function statements( string $body, string $symbol, string $dataset ): array {
		if ( ! isset( self::FIELDS[ $dataset ] ) ) {
			throw new \InvalidArgumentException( 'Unsupported fundamental dataset.' );
		}
		$payload = self::payload( $body, $symbol, 'symbol' );
		$reports = array();
		foreach ( array(
			'annualReports'    => 'annual',
			'quarterlyReports' => 'quarterly',
		) as $key => $period ) {
			$rows = $payload[ $key ] ?? null;
			if ( ! is_array( $rows ) || ! array_is_list( $rows ) || count( $rows ) > 100 ) {
				throw new \InvalidArgumentException( 'Fundamental report collection is missing or exceeds limits.' );
			}
			$seen = array();
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					throw new \InvalidArgumentException( 'Fundamental report is invalid.' );
				}
				$date     = self::date( $row['fiscalDateEnding'] ?? null );
				$currency = self::currency( $row['reportedCurrency'] ?? null );
				if ( isset( $seen[ $date ] ) ) {
					throw new \InvalidArgumentException( 'Duplicate fiscal periods require separate revision evidence.' );
				}
				$seen[ $date ] = true;
				$values        = array();
				foreach ( self::FIELDS[ $dataset ] as $field ) {
					$values[ $field ] = self::number( $row[ $field ] ?? null );
				}
				$reports[] = array(
					'period_type'        => $period,
					'fiscal_date_ending' => $date,
					'reported_currency'  => $currency,
					'values'             => $values,
				);
			}
		}
		if ( ! $reports ) {
			throw new \InvalidArgumentException( 'Provider returned no fundamental reports.' );
		}
		usort(
			$reports,
			static function ( array $left, array $right ): int {
				$period_order = strcmp( $left['period_type'], $right['period_type'] );
				return 0 !== $period_order ? $period_order : strcmp( $right['fiscal_date_ending'], $left['fiscal_date_ending'] );
			}
		);
		return array(
			'provider'        => 'alpha_vantage',
			'provider_symbol' => $symbol,
			'dataset'         => $dataset,
			'reports'         => $reports,
		);
	}

	/**
	 * Normalize a bounded overview without treating LatestQuarter as a metric timestamp.
	 * Provider ratios remain supplied facts, not locally computed statement metrics.
	 *
	 * @param string $body Provider JSON.
	 * @param string $symbol Expected mapped symbol.
	 * @return array
	 * @throws \InvalidArgumentException On malformed identity or values.
	 */
	public static function overview( string $body, string $symbol ): array {
		$payload = self::payload( $body, $symbol, 'Symbol' );
		if ( 'Common Stock' !== ( $payload['AssetType'] ?? null ) ) {
			throw new \InvalidArgumentException( 'Company overview requires a common stock identity.' );
		}
		$periods = array(
			'MarketCapitalization' => 'provider_unspecified',
			'PERatio'              => 'provider_unspecified',
			'PriceToBookRatio'     => 'provider_unspecified',
			'RevenueTTM'           => 'trailing_twelve_months',
			'EPS'                  => 'provider_unspecified',
			'ProfitMargin'         => 'provider_unspecified',
			'OperatingMarginTTM'   => 'trailing_twelve_months',
			'ReturnOnEquityTTM'    => 'trailing_twelve_months',
			'SharesOutstanding'    => 'provider_unspecified',
		);
		$metrics = array();
		foreach ( $periods as $field => $period ) {
			$metrics[ $field ] = array(
				'value'       => self::number( $payload[ $field ] ?? null ),
				'period_type' => $period,
			);
		}
		return array(
			'provider'        => 'alpha_vantage',
			'provider_symbol' => $symbol,
			'dataset'         => 'OVERVIEW',
			'currency'        => self::currency( $payload['Currency'] ?? null ),
			'latest_quarter'  => self::date( $payload['LatestQuarter'] ?? null ),
			'metrics'         => $metrics,
		);
	}

	/**
	 * Reject provider errors without reflecting diagnostics or unrelated text.
	 *
	 * @param string $body JSON.
	 * @param string $symbol Mapped symbol.
	 * @param string $key Identity field.
	 * @return array
	 * @throws \InvalidArgumentException On provider errors or mismatched identity.
	 */
	private static function payload( string $body, string $symbol, string $key ): array {
		$payload = ProviderJson::decode( $body );
		foreach ( array( 'Note', 'Information', 'Error Message' ) as $error ) {
			if ( array_key_exists( $error, $payload ) ) {
				throw new \InvalidArgumentException( 'Provider returned a limit, entitlement or request error.' );
			}
		}
		if ( '' === $symbol || strlen( $symbol ) > 80 || ( $payload[ $key ] ?? null ) !== $symbol ) {
			throw new \InvalidArgumentException( 'Fundamental identity is missing or mismatched.' );
		}
		return $payload;
	}

	/**
	 * Preserve signed exact values; unavailable sentinels never become zero.
	 *
	 * @param mixed $value Provider value.
	 * @return string|null
	 * @throws \InvalidArgumentException On invalid types or precision.
	 */
	private static function number( $value ): ?string {
		if ( null === $value || in_array( $value, array( '', 'None', 'N/A', '-', 'NaN' ), true ) ) {
			return null;
		}
		if ( ! is_string( $value ) ) {
			throw new \InvalidArgumentException( 'Fundamental value is invalid.' );
		}
		$number = ProviderJson::number( $value );
		Decimal::input( ltrim( $number, '-' ) );
		return $number;
	}

	/**
	 * Validate a reporting currency without substituting quote/base currency.
	 *
	 * @param mixed $value Provider currency.
	 * @return string
	 * @throws \InvalidArgumentException On missing or malformed currency.
	 */
	private static function currency( $value ): string {
		if ( ! is_string( $value ) || ! preg_match( '/^[A-Z]{3}$/D', $value ) ) {
			throw new \InvalidArgumentException( 'Fundamental reporting currency is invalid.' );
		}
		return $value;
	}

	/**
	 * Validate a fiscal date; it does not establish publication time.
	 *
	 * @param mixed $value Provider date.
	 * @return string
	 * @throws \InvalidArgumentException On an invalid date.
	 */
	private static function date( $value ): string {
		if ( ! is_string( $value ) || ! preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value ) ) {
			throw new \InvalidArgumentException( 'Fundamental fiscal date is invalid.' );
		}
		$parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
		if ( false === $parsed || $parsed->format( 'Y-m-d' ) !== $value ) {
			throw new \InvalidArgumentException( 'Fundamental fiscal date is invalid.' );
		}
		return $value;
	}
}
