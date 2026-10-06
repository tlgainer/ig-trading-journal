<?php
/**
 * Manual observations and reproducible, workspace-scoped reports.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Application;

use GainerInteractive\IGTradingJournal\Domain\Decimal;
use GainerInteractive\IGTradingJournal\Domain\Ledger;
use GainerInteractive\IGTradingJournal\Domain\Replay;
use GainerInteractive\IGTradingJournal\Domain\Valuation;

/** Shared operations use Tracker's authorization, locking and audit contract. */
trait ReportingOperations {

	/**
	 * Calculate totals from every authorized asset page, never just a loaded UI page.
	 *
	 * @param int    $workspace Workspace identifier.
	 * @param string $source Explicit stock quote source.
	 * @return array
	 * @throws \RuntimeException On excessive synchronous position history.
	 */
	public function stock_summary( int $workspace, string $source = 'manual' ): array {
		$this->authorize( $workspace, 'tgit_view' );
		$positions = array();
		$after     = 0;
		do {
			$page      = $this->holdings( $workspace, $after, 100, $source );
			$positions = array_merge( $positions, $page['items'] );
			if ( count( $positions ) > 10000 ) {
				throw new \RuntimeException( 'Stock summary exceeds the synchronous position limit.' );
			}
			$after = (int) $page['next_cursor'];
		} while ( null !== $page['next_cursor'] );
		return array(
			'currency_groups' => \GainerInteractive\IGTradingJournal\Domain\StockTotals::calculate( $positions ),
			'price_source'    => $source,
			'scope'           => 'all_accounts_open_stocks',
			'as_of'           => gmdate( 'c' ),
		);
	}

	/**
	 * Calculate equity change less external funding only with comparable coverage.
	 *
	 * @param array       $accounts Scoped accounts.
	 * @param array       $events Posted events through the ending date.
	 * @param array       $observations Known active observations.
	 * @param string      $base Base currency.
	 * @param array       $filter Stated period.
	 * @param string|null $ending Ending equity.
	 * @param array       $coverage Missing/stale counts.
	 * @return array
	 */
	private function economic_summary( array $accounts, array $events, array $observations, string $base, array $filter, ?string $ending, array $coverage ): array {
		$missing = array(
			'amount'                     => null,
			'status'                     => 'incomplete_valuation',
			'opening_equity'             => null,
			'external_net_contributions' => null,
		);
		if ( '1000-01-01' === $filter['from'] ) {
			return array_replace( $missing, array( 'status' => 'period_start_required' ) );
		}
		if ( array_intersect( array( 'asset_id', 'asset_class', 'action', 'strategy_version_id', 'tags', 'images' ), array_keys( $filter ) ) ) {
			return array_replace( $missing, array( 'status' => 'requires_account_portfolio_scope' ) );
		}
		if ( null === $ending || $coverage['missing_prices'] || $coverage['stale_prices'] || $coverage['missing_fx'] || $coverage['stale_fx'] ) {
			return $missing; }
		$start         = ( new \DateTimeImmutable( $filter['from'] ) )->modify( '-1 day' )->format( 'Y-m-d' );
		$opening       = '0';
		$contributions = '0';
		foreach ( $accounts as $account ) {
			if ( ( isset( $filter['account_id'] ) && (int) $account['id'] !== $filter['account_id'] ) || ( isset( $filter['currency'] ) && $account['native_currency'] !== $filter['currency'] ) ) {
				continue; }
			$account_events = array_values( array_filter( $events, static fn( array $event ): bool => (int) $event['account_id'] === (int) $account['id'] ) );
			$before         = array_values( array_filter( $account_events, static fn( array $event ): bool => $event['effective_date'] <= $start ) );
			if ( $before ) {
				$projection = Replay::calculate( $before );
				$fx         = self::report_fx( $observations, $account['native_currency'], $base, $start );
				if ( 'complete' !== $fx['status'] ) {
					return $missing; }
				$opening = Decimal::add( $opening, Valuation::convert( $projection['cash_balance'], $fx['rate'] ) );
				foreach ( $projection['lots'] as $lot ) {
					if ( Decimal::compare( $lot['quantity_remaining'], '0' ) <= 0 ) {
						continue; }
					$price = Valuation::select( array_filter( $observations, static fn( array $row ): bool => 'price' === $row['kind'] && (int) $row['asset_id'] === (int) $lot['asset_id'] ), $start );
					if ( 'complete' !== $price['status'] ) {
						return $missing; }
					$opening = Decimal::add( $opening, Decimal::mul( Decimal::mul( $lot['quantity_remaining'], $price['observation']['value'] ), $fx['rate'] ) );
				}
			}
			foreach ( $account_events as $event ) {
				if ( $event['effective_date'] < $filter['from'] ) {
					continue; }
				if ( str_starts_with( $event['action'], 'opening_' ) ) {
					return array_replace( $missing, array( 'status' => 'starting_balance_inside_period' ) );
				}
				if ( ! in_array( $event['action'], array( 'deposit', 'withdrawal' ), true ) ) {
					continue; }
				$fx = self::report_fx( $observations, $event['currency'], $base, $event['effective_date'] );
				if ( 'complete' !== $fx['status'] ) {
					return $missing; }
				$delta         = 'deposit' === $event['action'] ? $event['amount'] : Decimal::sub( '0', $event['amount'] );
				$contributions = Decimal::add( $contributions, Decimal::mul( $delta, $fx['rate'] ) );
			}
		}
		return array(
			'amount'                     => Decimal::sub( Decimal::sub( $ending, $opening ), $contributions ),
			'status'                     => 'complete',
			'opening_equity'             => $opening,
			'external_net_contributions' => $contributions,
			'opening_as_of'              => $start,
			'policy'                     => 'Ending equity minus opening equity minus external net contributions; retained income cash is not added twice.',
		);
	}

	/**
	 * Enrich native positions with the explicitly selected stock quote source.
	 *
	 * @param int    $workspace Workspace identifier.
	 * @param array  $positions Current ledger positions.
	 * @param string $source Explicit stock price source.
	 * @return array
	 * @throws \RuntimeException When observation bounds are exceeded.
	 */
	private function value_holdings( int $workspace, array $positions, string $source = 'manual' ): array {
		$settings = $this->authorize( $workspace, 'tgit_view' );
		$date     = ( new \DateTimeImmutable( 'now', new \DateTimeZone( $settings['timezone'] ) ) )->format( 'Y-m-d' );
		$rows     = $this->db->rows( 'SELECT o.* FROM ' . $this->db->table( 'market_observations' ) . ' o LEFT JOIN ' . $this->db->table( 'market_observations' ) . ' s ON s.workspace_id = o.workspace_id AND s.supersedes_id = o.id WHERE o.workspace_id = %d AND s.id IS NULL ORDER BY o.id LIMIT 10001', array( $workspace ) );
		if ( count( $rows ) > 10000 ) {
			throw new \RuntimeException( 'Observation history exceeds the synchronous valuation limit.' );
		}
		$quotes = 'manual' === $source ? array() : $this->holding_quotes( $workspace, $positions, $source, $date );
		foreach ( $positions as &$position ) {
			$asset_id = $position['asset_id'];
			$price    = Valuation::select( array_filter( $rows, static fn( array $row ): bool => 'price' === $row['kind'] && (int) $row['asset_id'] === $asset_id ), $date );
			if ( 'manual' !== $source && 'stock' === $position['asset_class'] ) {
				$quote   = $quotes[ $asset_id ] ?? null;
				$expires = $quote ? ( new \DateTimeImmutable( $quote['session_date'] ) )->modify( '+3 days' )->format( 'Y-m-d' ) : null;
				$price   = array(
					'status'      => null === $quote ? 'missing' : ( $expires < $date ? 'stale' : 'complete' ),
					'observation' => $quote ? array(
						'id'             => $quote['id'],
						'kind'           => 'provider_price',
						'source'         => $source . ' end-of-day',
						'effective_date' => $quote['session_date'],
						'expires_on'     => $expires,
						'value'          => $quote['price'],
						'retrieved_at'   => $quote['retrieved_at'],
						'mapping_id'     => $quote['mapping_id'],
					) : null,
				);
			}
			$value = Valuation::position( $position['quantity'], $price['observation']['value'] ?? null, $position['remaining_basis'] );
			if ( 'manual' !== $source && 'stock' === $position['asset_class'] && 'complete' !== $price['status'] ) {
				$value = Valuation::position( $position['quantity'], null, $position['remaining_basis'] );
			}
			$position['market_value']      = $value['market_value'];
			$position['unrealized_gain']   = $value['unrealized_gain'];
			$position['price_status']      = $price['status'];
			$position['price_observation'] = $price['observation'];
			$position['valuation_date']    = $date;
		}
		unset( $position );
		return $positions;
	}

	/**
	 * Select dated quotes from current enabled mappings with matching asset identity.
	 *
	 * @param int    $workspace Workspace identifier.
	 * @param array  $positions Current page of positions.
	 * @param string $source Selected provider.
	 * @param string $date Valuation date.
	 * @return array
	 */
	private function holding_quotes( int $workspace, array $positions, string $source, string $date ): array {
		$ids = array_values( array_unique( array_column( $positions, 'asset_id' ) ) );
		if ( ! $ids ) {
			return array();
		}
		$q     = $this->db->table( 'provider_quotes' );
		$m     = $this->db->table( 'provider_mappings' );
		$a     = $this->db->table( 'assets' );
		$slots = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql   = 'SELECT q.* FROM ' . $q . ' q JOIN ' . $m . ' m ON m.workspace_id = q.workspace_id AND m.id = q.mapping_id JOIN ' . $a . ' a ON a.workspace_id = q.workspace_id AND a.id = q.asset_id WHERE q.workspace_id = %d AND m.provider = %s AND m.enabled = 1 AND a.asset_class = %s AND m.asset_id = q.asset_id AND m.provider = q.provider AND m.provider_symbol = q.provider_symbol AND m.currency = q.currency AND m.exchange = q.exchange AND a.quote_currency = q.currency AND a.exchange = q.exchange AND q.asset_id IN (' . $slots . ') AND NOT EXISTS (SELECT newer.id FROM ' . $m . ' newer WHERE newer.workspace_id = m.workspace_id AND newer.asset_id = m.asset_id AND newer.provider = m.provider AND newer.id > m.id) AND q.id = (SELECT latest.id FROM ' . $q . ' latest WHERE latest.workspace_id = q.workspace_id AND latest.mapping_id = q.mapping_id AND latest.session_date <= %s ORDER BY latest.session_date DESC, latest.id DESC LIMIT 1)';
		$rows  = $this->db->rows( $sql, array_merge( array( $workspace, $source, 'stock' ), $ids, array( $date ) ) );
		return array_column( $rows, null, 'asset_id' );
	}
	/**
	 * Filter an event or position using the same scoped identities and journal context.
	 *
	 * @param array $row Candidate.
	 * @param array $filter Saved filter contract.
	 * @param array $assets Asset lookup.
	 * @param array $context Linked trade metadata.
	 * @return bool
	 */
	private static function report_matches( array $row, array $filter, array $assets, array $context ): bool {
		foreach ( array( 'account_id', 'asset_id', 'action', 'currency' ) as $field ) {
			if ( isset( $filter[ $field ] ) && (string) ( $row[ $field ] ?? '' ) !== (string) $filter[ $field ] ) {
				return false;
			}
		}
		$asset = $assets[ (int) ( $row['asset_id'] ?? 0 ) ] ?? array();
		if ( isset( $filter['asset_class'] ) && ( $asset['asset_class'] ?? '' ) !== $filter['asset_class'] ) {
			return false;
		}
		foreach ( array( 'strategy_version_id', 'images' ) as $field ) {
			if ( isset( $filter[ $field ] ) && (string) ( $context[ $field ] ?? '' ) !== (string) $filter[ $field ] ) {
				return false;
			}
		}
		return ! isset( $filter['tags'] ) || ! array_diff( $filter['tags'], $context['tags'] ?? array() );
	}

	/**
	 * Generate an immutable report with selected inputs and calculation watermark.
	 *
	 * @param int    $workspace Workspace identifier.
	 * @param array  $input Report/filter command.
	 * @param string $key Retry identity.
	 * @return array
	 * @throws \InvalidArgumentException For filters incompatible with position reports.
	 */
	public function generate_report( int $workspace, array $input, string $key ): array {
		$this->authorize( $workspace, 'tgit_view' );
		$filter = $this->report_input( $workspace, $input );
		if ( in_array( $filter['type'], array( 'holdings', 'allocation' ), true ) && array_intersect( array( 'action', 'strategy_version_id', 'tags', 'images' ), array_keys( $filter ) ) ) {
			throw new \InvalidArgumentException( 'Position reports support account, asset, class and currency filters; trade filters apply to activity, gains and strategy reports.' );
		}
		return $this->mutation(
			$workspace,
			'tgit_view',
			'report',
			$key,
			$input,
			function () use ( $workspace, $filter ) {
				$settings    = $this->authorize( $workspace, 'tgit_view' );
				$base        = $settings['base_currency'];
				$event_count = $this->db->row( 'SELECT COUNT(*) AS total FROM ' . $this->db->table( 'transactions' ) . ' WHERE workspace_id = %d', array( $workspace ) );
				if ( (int) $event_count['total'] > 10000 ) {
					throw new \RuntimeException( 'Report exceeds the synchronous workspace event limit.' );
				}

				$observations = $this->db->rows( 'SELECT o.* FROM ' . $this->db->table( 'market_observations' ) . ' o LEFT JOIN ' . $this->db->table( 'market_observations' ) . ' s ON s.workspace_id = o.workspace_id AND s.supersedes_id = o.id WHERE o.workspace_id = %d AND s.id IS NULL ORDER BY o.id LIMIT 10001', array( $workspace ) );
				$accounts     = $this->db->rows( 'SELECT * FROM ' . $this->db->table( 'accounts' ) . ' WHERE workspace_id = %d ORDER BY id LIMIT 1001', array( $workspace ) );
				$asset_rows   = $this->db->rows( 'SELECT * FROM ' . $this->db->table( 'assets' ) . ' WHERE workspace_id = %d ORDER BY id LIMIT 10001', array( $workspace ) );
				$trades       = $this->db->rows( 'SELECT t.*,j.payload AS journal FROM ' . $this->db->table( 'trades' ) . ' t LEFT JOIN ' . $this->db->table( 'trade_journals' ) . ' j ON j.workspace_id = t.workspace_id AND j.trade_id = t.id AND j.revision = t.revision WHERE t.workspace_id = %d ORDER BY t.id LIMIT 10001', array( $workspace ) );
				$fills        = $this->db->rows( 'SELECT * FROM ' . $this->db->table( 'trade_fills' ) . ' WHERE workspace_id = %d ORDER BY id LIMIT 10001', array( $workspace ) );
				$images       = $this->db->rows( 'SELECT DISTINCT trade_id FROM ' . $this->db->table( 'media' ) . ' WHERE workspace_id = %d AND state = %s LIMIT 10001', array( $workspace, 'ready' ) );
				foreach ( array( $observations, $asset_rows, $trades, $fills, $images ) as $records ) {
					if ( count( $records ) > 10000 ) {
						throw new \RuntimeException( 'Report exceeds the synchronous 10000-record limit; narrow the workspace before reporting.' );
					}
				}
				if ( count( $accounts ) > 1000 ) {
					throw new \RuntimeException( 'Report exceeds the synchronous account limit.' );
				}
				$assets    = array_column( $asset_rows, null, 'id' );
				$trade_map = array_column( $trades, null, 'id' );
				$image_ids = array_column( $images, 'trade_id' );
				$contexts  = array();
				foreach ( $fills as $fill ) {
					$trade = $trade_map[ $fill['trade_id'] ] ?? null;
					if ( ! $trade ) {
						throw new \RuntimeException( 'Trade relationship is inconsistent.' );
					}
					$journal                             = json_decode( $trade['journal'] ?? '{}', true, 512, JSON_THROW_ON_ERROR );
					$contexts[ $fill['transaction_id'] ] = array(
						'trade_id'            => (int) $trade['id'],
						'strategy_version_id' => $trade['strategy_version_id'],
						'tags'                => $journal['fields']['tags'] ?? array(),
						'images'              => in_array( $trade['id'], $image_ids, true ) ? 'yes' : 'no',
					);
				}
				$activity     = array();
				$positions    = array();
				$cash         = array();
				$all_events   = array();
				$trade_events = array();
				$watermark    = array();
				$coverage     = array(
					'missing_prices'   => 0,
					'stale_prices'     => 0,
					'missing_fx'       => 0,
					'stale_fx'         => 0,
					'unresolved_basis' => 0,
				);
				foreach ( $accounts as $account ) {
					if ( isset( $filter['account_id'] ) && (int) $account['id'] !== $filter['account_id'] ) {
						continue;
					}
					$events  = $this->active_account_events( $workspace, (int) $account['id'] );
					$current = $this->replay_projection( $workspace, (int) $account['id'] ) ?? Replay::calculate( $events );
					if ( Decimal::compare( $current['cash_balance'], $account['cash_balance'] ) !== 0 ) {
						throw new \RuntimeException( 'Account projection differs from posted history; repair is required.' );
					}
					$events                      = array_values( array_filter( $events, static fn( array $event ): bool => $event['effective_date'] <= $filter['as_of'] ) );
					$watermark[ $account['id'] ] = hash( 'sha256', wp_json_encode( $events ) );
					$all_events                  = array_merge( $all_events, $events );
					if ( count( $all_events ) > 10000 ) {
						throw new \RuntimeException( 'Report exceeds the synchronous event limit.' );
					}
					$projection   = Replay::calculate( $events );
					$account_rows = array();
					$fx           = self::report_fx( $observations, $account['native_currency'], $base, $filter['as_of'] );
					$cash_row     = array(
						'account_id'   => (int) $account['id'],
						'name'         => $account['name'],
						'currency'     => $account['native_currency'],
						'cash_balance' => $projection['cash_balance'],
						'base_value'   => Valuation::convert( $projection['cash_balance'], $fx['rate'] ),
						'fx'           => $fx,
					);
					if ( self::report_matches( $cash_row, array_intersect_key( $filter, array_flip( array( 'account_id', 'currency' ) ) ), $assets, array() ) ) {
						$cash[] = $cash_row;
					}
					foreach ( $events as $event ) {
						$id         = (int) $event['id'];
						$effect     = $projection['effects'][ $id ];
						$event_fx   = self::report_fx( $observations, $event['currency'], $base, $event['effective_date'] );
						$basis_base = '0';
						$basis_fx   = array();
						foreach ( $effect['allocations'] as $allocation ) {
							$lot        = $projection['lots'][ $allocation['lot_id'] ];
							$rate       = self::report_fx( $observations, $event['currency'], $base, $lot['acquired_on'] );
							$basis_fx[] = $rate;
							$converted  = Valuation::convert( $allocation['basis_native'], $rate['rate'] );
							$basis_base = null === $basis_base || null === $converted ? null : Decimal::add( $basis_base, $converted );
						}
						$base_proceeds        = Valuation::convert( $effect['cash_delta'], $event_fx['rate'] );
						$row                  = $event + array(
							'symbol'             => $assets[ $event['asset_id'] ]['symbol'] ?? '',
							'cash_delta'         => $effect['cash_delta'],
							'base_cash_delta'    => $base_proceeds,
							'base_realized_gain' => 'sell' !== $event['action'] ? '0' : ( null === $base_proceeds || null === $basis_base ? null : Decimal::sub( $base_proceeds, $basis_base ) ),
							'fx'                 => $event_fx,
							'basis_fx'           => $basis_fx,
						);
						$row['realized_gain'] = $effect['realized_gain'];
						$account_rows[]       = $row;
						$row['trade_context'] = $contexts[ $id ] ?? array(
							'images' => 'no',
							'tags'   => array(),
						);
						if ( isset( $contexts[ $id ] ) ) {
							$trade_events[ $contexts[ $id ]['trade_id'] ][] = $row;
						}
						if ( $event['effective_date'] >= $filter['from'] && self::report_matches( $row, $filter, $assets, $row['trade_context'] ) ) {
							$activity[] = $row;
						}
					}
					$by_asset = array();
					foreach ( $projection['lots'] as $lot ) {
						if ( Decimal::compare( $lot['quantity_remaining'], '0' ) <= 0 ) {
							continue;
						}
						$asset_id                    = (int) $lot['asset_id'];
						$position                    = $by_asset[ $asset_id ] ?? array(
							'account_id'      => (int) $account['id'],
							'asset_id'        => $asset_id,
							'symbol'          => $assets[ $asset_id ]['symbol'],
							'asset_class'     => $assets[ $asset_id ]['asset_class'],
							'currency'        => $assets[ $asset_id ]['quote_currency'],
							'quantity'        => '0',
							'remaining_basis' => '0',
							'base_basis'      => '0',
							'basis_fx'        => array(),
						);
						$rate                        = self::report_fx( $observations, $position['currency'], $base, $lot['acquired_on'] );
						$basis                       = 'unresolved' === $lot['basis_status'] ? null : $lot['basis_remaining'];
						$position['quantity']        = Decimal::add( $position['quantity'], $lot['quantity_remaining'] );
						$position['remaining_basis'] = null === $position['remaining_basis'] || null === $basis ? null : Decimal::add( $position['remaining_basis'], $basis );
						$converted                   = Valuation::convert( $basis, $rate['rate'] );
						$position['base_basis']      = null === $position['base_basis'] || null === $converted ? null : Decimal::add( $position['base_basis'], $converted );
						$position['basis_fx'][]      = $rate;
						$by_asset[ $asset_id ]       = $position;
					}
					foreach ( $by_asset as $asset_id => $position ) {
						if ( ! self::report_matches( $position, array_intersect_key( $filter, array_flip( array( 'account_id', 'asset_id', 'currency', 'asset_class' ) ) ), $assets, array() ) ) {
							continue;
						}
						$price                               = Valuation::select( array_filter( $observations, static fn( array $row ): bool => 'price' === $row['kind'] && (int) $row['asset_id'] === $asset_id ), $filter['as_of'] );
						$rate                                = self::report_fx( $observations, $position['currency'], $base, $filter['as_of'] );
						$position                           += Valuation::position( $position['quantity'], $price['observation']['value'] ?? null, $position['remaining_basis'] );
						$position['base_value']              = Valuation::convert( $position['market_value'], $rate['rate'] );
						$position['base_unrealized_gain']    = null === $position['base_value'] || null === $position['base_basis'] ? null : Decimal::sub( $position['base_value'], $position['base_basis'] );
						$position['realized_gain']           = '0';
						$position['base_realized_gain']      = '0';
						$position['lifetime_purchase_spend'] = '0';
						$position['period_realized_gain']    = '0';
						foreach ( $account_rows as $event_row ) {
							if ( (int) $event_row['asset_id'] !== $asset_id ) {
								continue; }
							if ( 'buy' === $event_row['action'] ) {
								$position['lifetime_purchase_spend'] = Decimal::sub( $position['lifetime_purchase_spend'], $event_row['cash_delta'] );
							}
							if ( 'sell' === $event_row['action'] ) {
								$position['realized_gain']      = Decimal::add( $position['realized_gain'], $event_row['realized_gain'] );
								$position['base_realized_gain'] = null === $position['base_realized_gain'] || null === $event_row['base_realized_gain'] ? null : Decimal::add( $position['base_realized_gain'], $event_row['base_realized_gain'] );
								if ( $event_row['effective_date'] >= $filter['from'] ) {
													$position['period_realized_gain'] = Decimal::add( $position['period_realized_gain'], $event_row['realized_gain'] );
								}
							}
						}
						$position['income']                    = null;
						$position['unrealized_return_percent'] = null === $position['unrealized_gain'] || null === $position['remaining_basis'] || Decimal::compare( $position['remaining_basis'], '0' ) <= 0 ? null : Decimal::mul( Decimal::div( $position['unrealized_gain'], $position['remaining_basis'] ), '100' );

						$position['price'] = $price;
						$position['fx']    = $rate;
						$positions[]       = $position;
					}
				}
				$denominator = '0';
				foreach ( array_merge( $positions, $cash ) as $row ) {
					$denominator = null === $denominator || null === $row['base_value'] ? null : Decimal::add( $denominator, $row['base_value'] );
					if ( isset( $row['price'] ) && 'complete' !== $row['price']['status'] ) {
						++$coverage[ $row['price']['status'] . '_prices' ];
					}
					foreach ( array_merge( array( $row['fx'] ), $row['basis_fx'] ?? array() ) as $rate ) {
						if ( 'complete' !== $rate['status'] ) {
							++$coverage[ $rate['status'] . '_fx' ];
						}
					}
					if ( array_key_exists( 'remaining_basis', $row ) && null === $row['remaining_basis'] ) {
						++$coverage['unresolved_basis'];
					}
				}
				foreach ( $positions as &$position ) {
					$position['allocation_percent'] = null === $denominator || null === $position['base_value'] || Decimal::compare( $denominator, '0' ) <= 0 ? null : Decimal::mul( Decimal::div( $position['base_value'], $denominator ), '100' );
				}
				unset( $position );
				$rows = match ( $filter['type'] ) {
					'holdings', 'allocation' => $positions,
					'gains' => array_values( array_filter( $activity, static fn( array $row ): bool => 'sell' === $row['action'] ) ),
					'cash' => $activity,
					'income' => array(),
					'strategy' => $this->strategy_report_rows( $trades, $trade_events, $filter, $assets ),
					default => $activity,
				};
				$period_gain  = '0';
				$native_gains = array();
				foreach ( $activity as $row ) {
					foreach ( array_merge( array( $row['fx'] ), $row['basis_fx'] ) as $rate ) {
						if ( 'complete' !== $rate['status'] ) {
							++$coverage[ $rate['status'] . '_fx' ];
						}
					}
					if ( 'sell' !== $row['action'] ) {
						continue;
					}
					$period_gain                      = null === $period_gain || null === $row['base_realized_gain'] ? null : Decimal::add( $period_gain, $row['base_realized_gain'] );
					$native_gains[ $row['currency'] ] = Decimal::add( $native_gains[ $row['currency'] ] ?? '0', $row['realized_gain'] );
				}
				$result = array(
					'filters'                => $filter,
					'base_currency'          => $base,
					'timezone'               => $settings['timezone'],
					'as_of'                  => $filter['as_of'],
					'generated_at'           => gmdate( 'c' ),
					'calculation_version'    => Ledger::VERSION . '/valuation-1',
					'lot_policy'             => 'FIFO',
					'price_selection'        => 'Latest effective date <= as-of, highest ID on ties; stale values labeled',
					'fx_selection'           => 'Direct native-to-base; acquisition-date basis, disposal-date proceeds, valuation-date market value',
					'input_watermark'        => hash( 'sha256', wp_json_encode( array( $watermark, $observations, $trades, $fills, $images ) ) ),
					'observations'           => $observations,
					'ledger_inputs'          => $all_events,
					'trade_inputs'           => array(
						'trades'       => $trades,
						'fills'        => $fills,
						'ready_images' => $images,
					),
					'coverage'               => $coverage,
					'items'                  => $rows,
					'cash_accounts'          => $cash,
					'base_equity'            => $denominator,
					'economic_gain'          => $this->economic_summary( $accounts, $all_events, $observations, $base, $filter, $denominator, $coverage ),
					'valuation_scope'        => 'Equity uses account/asset/class/currency filters only; action and journal filters affect activity rows.',
					'allocation_denominator' => 'Filtered holdings plus filtered cash; includes cash',
					'native_realized_gain'   => $native_gains,
					'base_realized_gain'     => $period_gain,
					'income_status'          => 'unsupported_accounting_actions',
					'reconciliation_status'  => 'not_reconciled',
					'notes'                  => array( 'Only supported posted actions are included; drafts and superseded transactions are excluded.', 'Income posting is not implemented. An empty income report does not establish zero income.', 'Historical reports use current corrected ledger and current known observations; immutable prior report runs retain earlier evidence.', 'From-date filters activity and realized gains; positions and cash are reconstructed through as-of.' ),
				);
				$id     = $this->db->insert(
					'report_runs',
					array(
						'workspace_id' => $workspace,
						'actor_id'     => $this->actor,
						'payload'      => wp_json_encode( $result ),
						'report_type'  => $filter['type'],
						'as_of'        => $filter['as_of'],
						'created_at'   => gmdate( 'Y-m-d H:i:s' ),
					)
				);
				$this->audit( $workspace, 'report_created', 'report_runs', $id );
				return array( 'id' => $id ) + $result;
			}
		);
	}

	/**
	 * Evaluate fully closed groups, with fees and break-even treatment explicit.
	 *
	 * @param array $trades Trade groups.
	 * @param array $events Posted fills through as-of.
	 * @param array $filter Report filter.
	 * @param array $assets Asset identities.
	 * @return array
	 */
	private function strategy_report_rows( array $trades, array $events, array $filter, array $assets ): array {
		$groups = array();
		foreach ( $trades as $trade ) {
			if ( 'closed' !== $trade['state'] || ! $trade['closed_on'] || $trade['closed_on'] < $filter['from'] || $trade['closed_on'] > $filter['as_of'] ) {
				continue;
			}
			$fills        = $events[ $trade['id'] ] ?? array();
			$quantity     = '0';
			$gain         = '0';
			$base_gain    = '0';
			$currency     = null;
			$eligible     = ! empty( $fills );
			$has_buy      = false;
			$has_sell     = false;
			$linked_count = $this->db->row( 'SELECT COUNT(*) AS total FROM ' . $this->db->table( 'trade_fills' ) . ' WHERE workspace_id = %d AND trade_id = %d', array( (int) $trade['workspace_id'], (int) $trade['id'] ) );
			$eligible     = $eligible && count( $fills ) === (int) $linked_count['total'];
			foreach ( $fills as $fill ) {
				$has_buy   = $has_buy || 'buy' === $fill['action'];
				$has_sell  = $has_sell || 'sell' === $fill['action'];
				$quantity  = 'buy' === $fill['action'] ? Decimal::add( $quantity, $fill['quantity'] ) : Decimal::sub( $quantity, $fill['quantity'] );
				$gain      = Decimal::add( $gain, $fill['realized_gain'] );
				$base_gain = null === $base_gain || null === $fill['base_realized_gain'] ? null : Decimal::add( $base_gain, $fill['base_realized_gain'] );
				$eligible  = $eligible && ( null === $currency || $currency === $fill['currency'] );
				$currency  = $fill['currency'];
				$eligible  = $eligible && self::report_matches( $fill, array_diff_key( $filter, array_flip( array( 'action' ) ) ), $assets, $fill['trade_context'] );
			}
			if ( ! $eligible || ! $has_buy || ! $has_sell || Decimal::compare( $quantity, '0' ) !== 0 ) {
				continue;
			}
			$version = $trade['strategy_version_id'] ?? 'unassigned';
			$key     = $version . ':' . $currency;
			$group   = $groups[ $key ] ?? array(
				'strategy_version_id' => $version,
				'currency'            => $currency,
				'closed_trades'       => 0,
				'wins'                => 0,
				'losses'              => 0,
				'break_even'          => 0,
				'realized_gain'       => '0',
				'base_realized_gain'  => '0',
			);
			++$group['closed_trades'];
			++$group[ Decimal::compare( $gain, '0' ) > 0 ? 'wins' : ( Decimal::compare( $gain, '0' ) < 0 ? 'losses' : 'break_even' ) ];
			$group['realized_gain']      = Decimal::add( $group['realized_gain'], $gain );
			$group['base_realized_gain'] = null === $group['base_realized_gain'] || null === $base_gain ? null : Decimal::add( $group['base_realized_gain'], $base_gain );
			$group['win_rate_percent']   = Decimal::mul( Decimal::div( (string) $group['wins'], (string) $group['closed_trades'] ), '100' );
			$group['policy']             = 'Closed, fully posted, flat groups only; canonical account FIFO includes acquisition/disposal fees; break-even is included in denominator and is not a win. Grouped by immutable strategy version and native currency.';
			$groups[ $key ]              = $group;
		}
		return array_values( $groups );
	}

	/**
	 * Validate a real date, without timestamp/timezone ambiguity.
	 *
	 * @param mixed $value Date input.
	 * @return string
	 * @throws \InvalidArgumentException For invalid dates.
	 */
	private static function report_date( $value ): string {
		if ( ! is_string( $value ) || ! preg_match( '/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $value, $parts ) || (int) $parts[1] < 1000 || ! checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ) {
			throw new \InvalidArgumentException( 'Use a valid YYYY-MM-DD date.' );
		}
		return $value;
	}

	/**
	 * Append a price or direct native-to-base FX observation; correction retains evidence.
	 *
	 * @param int    $workspace Workspace identifier.
	 * @param array  $input Observation facts.
	 * @param string $key Retry identity.
	 * @return array
	 * @throws \InvalidArgumentException For invalid observations.
	 */
	public function record_observation( int $workspace, array $input, string $key ): array {
		self::fields( $input, array( 'kind', 'asset_id', 'currency', 'value', 'effective_date', 'expires_on', 'source', 'reason', 'supersedes_id' ), array( 'kind', 'currency', 'value', 'effective_date', 'expires_on', 'source', 'reason' ) );
		if ( ! in_array( $input['kind'], array( 'price', 'fx' ), true ) ) {
			throw new \InvalidArgumentException( 'Observation kind must be price or fx.' );
		}
		$data = array(
			'kind'           => $input['kind'],
			'asset_id'       => 'price' === $input['kind'] ? self::id( $input['asset_id'] ?? null ) : null,
			'currency'       => self::currency( $input['currency'] ),
			'value'          => Decimal::input( $input['value'], 18, 'fx' === $input['kind'] ),
			'effective_date' => self::report_date( $input['effective_date'] ),
			'expires_on'     => self::report_date( $input['expires_on'] ),
			'source'         => sanitize_text_field( self::text( $input['source'], 190 ) ),
			'reason'         => sanitize_text_field( self::text( $input['reason'], 500 ) ),
			'supersedes_id'  => isset( $input['supersedes_id'] ) ? self::id( $input['supersedes_id'] ) : null,
		);
		if ( $data['expires_on'] < $data['effective_date'] || ( 'fx' === $data['kind'] && isset( $input['asset_id'] ) ) || '' === $data['source'] || '' === $data['reason'] ) {
			throw new \InvalidArgumentException( 'Expiry must follow effective date; FX has no asset; source and reason are required.' );
		}
		return $this->mutation(
			$workspace,
			'tgit_manage_settings',
			'observation',
			$key,
			$input,
			function () use ( $workspace, $data ) {
				$settings              = $this->authorize( $workspace, 'tgit_manage_settings' );
				$data['base_currency'] = 'fx' === $data['kind'] ? $settings['base_currency'] : null;
				if ( 'price' === $data['kind'] ) {
					$asset = $this->db->object( 'assets', $workspace, $data['asset_id'] );
					if ( $asset['quote_currency'] !== $data['currency'] ) {
						throw new \InvalidArgumentException( 'Price currency must match the asset quote currency.' );
					}
				} elseif ( $data['currency'] === $data['base_currency'] ) {
					throw new \InvalidArgumentException( 'Base currency uses an exact rate of one; do not enter an override.' );
				}
				if ( null !== $data['supersedes_id'] ) {
					$old = $this->db->object( 'market_observations', $workspace, $data['supersedes_id'] );
					foreach ( array( 'kind', 'asset_id', 'currency', 'base_currency', 'effective_date' ) as $field ) {
						if ( (string) $old[ $field ] !== (string) $data[ $field ] ) {
							throw new \InvalidArgumentException( 'A correction must retain the observation identity and effective date.' );
						}
					}
					if ( $this->db->row( 'SELECT id FROM ' . $this->db->table( 'market_observations' ) . ' WHERE workspace_id = %d AND supersedes_id = %d', array( $workspace, $old['id'] ) ) ) {
						throw new \UnexpectedValueException( 'Observation was already superseded.' );
					}
				}
				$id = $this->db->insert(
					'market_observations',
					$data + array(
						'workspace_id' => $workspace,
						'actor_id'     => $this->actor,
						'observed_at'  => gmdate( 'Y-m-d H:i:s' ),
					)
				);
				$this->audit( $workspace, 'manual_observation', 'market_observations', $id );
				return $this->db->object( 'market_observations', $workspace, $id );
			}
		);
	}

	/**
	 * Read bounded observation history, retaining corrected records.
	 *
	 * @param int $workspace Workspace identifier.
	 * @param int $after Cursor.
	 * @param int $limit Page size.
	 * @return array
	 * @throws \InvalidArgumentException For invalid pagination.
	 */
	public function observations( int $workspace, int $after = 0, int $limit = 100 ): array {
		$this->authorize( $workspace, 'tgit_view' );
		if ( $after < 0 || $limit < 1 || $limit > 100 ) {
			throw new \InvalidArgumentException( 'Invalid pagination.' );
		}
		return $this->db->rows( 'SELECT o.*,s.id AS superseded_by FROM ' . $this->db->table( 'market_observations' ) . ' o LEFT JOIN ' . $this->db->table( 'market_observations' ) . ' s ON s.workspace_id = o.workspace_id AND s.supersedes_id = o.id WHERE o.workspace_id = %d AND o.id > %d ORDER BY o.id LIMIT %d', array( $workspace, $after, $limit ) );
	}

	/**
	 * Validate a report/view filter contract and scoped references.
	 *
	 * @param int   $workspace Workspace identifier.
	 * @param array $input Report settings.
	 * @return array
	 * @throws \InvalidArgumentException For unsupported filters.
	 */
	private function report_input( int $workspace, array $input ): array {
		self::fields( $input, array( 'type', 'from', 'as_of', 'account_id', 'asset_id', 'action', 'currency', 'asset_class', 'strategy_version_id', 'tags', 'images' ), array( 'type', 'as_of' ) );
		if ( ! in_array( $input['type'], array( 'activity', 'holdings', 'gains', 'cash', 'income', 'allocation', 'strategy' ), true ) ) {
			throw new \InvalidArgumentException( 'Unsupported report type.' );
		}
		$input['as_of'] = self::report_date( $input['as_of'] );
		$input['from']  = self::report_date( $input['from'] ?? '1000-01-01' );
		if ( 'strategy' === $input['type'] && isset( $input['action'] ) ) {
			throw new \InvalidArgumentException( 'Strategy reports evaluate complete trade groups; action filters are not supported.' );
		}
		if ( $input['from'] > $input['as_of'] ) {
			throw new \InvalidArgumentException( 'Start date must precede as-of date.' );
		}
		foreach ( array(
			'account_id'          => 'accounts',
			'asset_id'            => 'assets',
			'strategy_version_id' => 'strategy_versions',
		) as $field => $table ) {
			if ( isset( $input[ $field ] ) ) {
				$input[ $field ] = self::id( $input[ $field ] );
				$this->db->object( $table, $workspace, $input[ $field ] );
			}
		}
		if ( isset( $input['currency'] ) ) {
			$input['currency'] = self::currency( $input['currency'] );
		}
		foreach ( array(
			'action'      => array( 'buy', 'sell', 'deposit', 'withdrawal', 'opening_cash', 'opening_lot' ),
			'asset_class' => array( 'stock', 'etf', 'crypto' ),
			'images'      => array( 'yes', 'no' ),
		) as $field => $values ) {
			if ( isset( $input[ $field ] ) && ! in_array( $input[ $field ], $values, true ) ) {
				throw new \InvalidArgumentException( 'Unsupported filter value.' );
			}
		}
		if ( isset( $input['tags'] ) ) {
			$input['tags'] = \GainerInteractive\IGTradingJournal\Domain\JournalInput::labels( $input['tags'] );
		}
		return $input;
	}

	/**
	 * Select and retain an FX reference for the stated date.
	 *
	 * @param array  $observations Active records.
	 * @param string $currency Native code.
	 * @param string $base Base code.
	 * @param string $date Effective date.
	 * @return array
	 */
	private static function report_fx( array $observations, string $currency, string $base, string $date ): array {
		if ( $currency === $base ) {
			return array(
				'rate'        => '1',
				'status'      => 'complete',
				'observation' => null,
			);
		}
		$selected = Valuation::select( array_filter( $observations, static fn( array $row ): bool => 'fx' === $row['kind'] && $currency === $row['currency'] && $base === $row['base_currency'] ), $date );
		return array( 'rate' => $selected['observation']['value'] ?? null ) + $selected;
	}

	/**
	 * Save or revise a personal report view with expected revision and audit.
	 *
	 * @param int    $workspace Workspace identifier.
	 * @param array  $input View command.
	 * @param string $key Retry identity.
	 * @return array
	 * @throws \InvalidArgumentException For invalid view input.
	 */
	public function save_view( int $workspace, array $input, string $key ): array {
		self::fields( $input, array( 'name', 'filters', 'view_id', 'expected_revision' ), array( 'name', 'filters' ) );
		$name = sanitize_text_field( self::text( $input['name'], 190 ) );
		if ( ! is_array( $input['filters'] ) || '' === $name ) {
			throw new \InvalidArgumentException( 'A view requires a name and filter object.' );
		}
		$this->authorize( $workspace, 'tgit_view' );
		$filters = $this->report_input( $workspace, $input['filters'] );
		return $this->mutation(
			$workspace,
			'tgit_view',
			'saved_view',
			$key,
			$input,
			function () use ( $workspace, $input, $name, $filters ) {
				$revision = 1;
				$data     = array(
					'workspace_id' => $workspace,
					'actor_id'     => $this->actor,
					'name'         => $name,
					'payload'      => wp_json_encode( $filters ),
					'created_at'   => gmdate( 'Y-m-d H:i:s' ),
				);
				if ( isset( $input['view_id'] ) ) {
					$id  = self::id( $input['view_id'] );
					$old = $this->db->object( 'saved_views', $workspace, $id );
					if ( (int) $old['actor_id'] !== $this->actor ) {
						throw new \DomainException( 'This view belongs to another member.' );
					}
					if ( self::id( $input['expected_revision'] ?? null ) !== (int) $old['revision'] ) {
						throw new \UnexpectedValueException( 'Saved view revision changed.' );
					}
					$revision = (int) $old['revision'] + 1;
					$this->db->update_object(
						'saved_views',
						$workspace,
						$id,
						array(
							'name'     => $name,
							'payload'  => $data['payload'],
							'revision' => $revision,
						)
					);
				} else {
					if ( isset( $input['expected_revision'] ) ) {
						throw new \InvalidArgumentException( 'Revision requires a view ID.' );
					}
					$id = $this->db->insert( 'saved_views', $data );
				}
				$this->db->insert(
					'saved_view_revisions',
					array(
						'workspace_id' => $workspace,
						'view_id'      => $id,
						'actor_id'     => $this->actor,
						'revision'     => $revision,
						'payload'      => wp_json_encode(
							array(
								'name'    => $name,
								'filters' => $filters,
							)
						),
						'created_at'   => $data['created_at'],
					)
				);
				$this->audit( $workspace, 'saved_view', 'saved_views', $id, $revision );
				return $this->db->object( 'saved_views', $workspace, $id );
			}
		);
	}

	/**
	 * List only the current member's views.
	 *
	 * @param int $workspace Workspace identifier.
	 * @param int $after Cursor.
	 * @param int $limit Page size.
	 * @return array
	 * @throws \InvalidArgumentException For invalid pagination.
	 */
	public function saved_views( int $workspace, int $after = 0, int $limit = 100 ): array {
		$this->authorize( $workspace, 'tgit_view' );
		if ( $after < 0 || $limit < 1 || $limit > 100 ) {
			throw new \InvalidArgumentException( 'Invalid pagination.' );
		}
		return $this->db->rows( 'SELECT * FROM ' . $this->db->table( 'saved_views' ) . ' WHERE workspace_id = %d AND actor_id = %d AND id > %d ORDER BY id LIMIT %d', array( $workspace, $this->actor, $after, $limit ) );
	}

	/**
	 * Retrieve immutable report evidence within a workspace.
	 *
	 * @param int $workspace Workspace identifier.
	 * @param int $id Run identifier.
	 * @return array
	 */
	public function report_run( int $workspace, int $id ): array {
		$this->authorize( $workspace, 'tgit_view' );
		$row = $this->db->object( 'report_runs', $workspace, $id );
		return array( 'id' => $id ) + json_decode( $row['payload'], true, 512, JSON_THROW_ON_ERROR );
	}

	/**
	 * List saved report metadata within a workspace.
	 *
	 * @param int $workspace Workspace identifier.
	 * @param int $after Cursor.
	 * @param int $limit Page size.
	 * @return array
	 * @throws \InvalidArgumentException For invalid pagination.
	 */
	public function reports( int $workspace, int $after = 0, int $limit = 100 ): array {
		$this->authorize( $workspace, 'tgit_view' );
		if ( $after < 0 || $limit < 1 || $limit > 100 ) {
			throw new \InvalidArgumentException( 'Invalid pagination.' );
		}
		return $this->db->rows( 'SELECT id,report_type,as_of,actor_id,created_at FROM ' . $this->db->table( 'report_runs' ) . ' WHERE workspace_id = %d AND id > %d ORDER BY id LIMIT %d', array( $workspace, $after, $limit ) );
	}
}
