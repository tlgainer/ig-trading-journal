<?php
/**
 * Deterministic chronological projection of one account's immutable events.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Domain;

/** Pure account replay; callers decide which posted revisions are active. */
final class Replay {
	/**
	 * Recalculate cash, lots and gains without changing source events.
	 *
	 * Each event has an integer id, effective_date and action. Security events
	 * include asset_id, quantity, unit_price and fees. Opening lots include
	 * acquired_on, amount and basis_status. Cash events include amount.
	 *
	 * @param array $events Active, validated account events.
	 * @return array Cash balance, current lots and effects keyed by event id.
	 * @throws \InvalidArgumentException When chronology cannot be projected.
	 */
	public static function calculate( array $events ): array {
		usort(
			$events,
			static function ( array $left, array $right ): int {
				$date_order = strcmp( $left['effective_date'], $right['effective_date'] );
				if ( 0 !== $date_order ) {
					return $date_order;
				}
				$left_opening  = str_starts_with( $left['action'], 'opening_' );
				$right_opening = str_starts_with( $right['action'], 'opening_' );
				if ( $left_opening !== $right_opening ) {
					return $left_opening ? -1 : 1;
				}
				return (int) ( $left['order_id'] ?? $left['id'] ) <=> (int) ( $right['order_id'] ?? $right['id'] );
			}
		);
		$cash    = '0';
		$lots    = array();
		$effects = array();
		foreach ( $events as $event ) {
			$id     = (int) $event['id'];
			$action = $event['action'];
			$delta  = '0';
			$gain   = '0';
			$used   = array();
			if ( 'opening_cash' === $action || 'deposit' === $action ) {
				$delta = Decimal::input( $event['amount'], 12, true );
			} elseif ( 'withdrawal' === $action ) {
				$delta = Decimal::sub( '0', Decimal::input( $event['amount'], 12, true ) );
			} elseif ( 'opening_lot' === $action || 'buy' === $action ) {
				$asset    = (int) $event['asset_id'];
				$quantity = Decimal::input( $event['quantity'], 18, true );
				if ( 'buy' === $action ) {
					$purchase = Ledger::buy( $quantity, $event['unit_price'], $event['fees'] );
					$basis    = $purchase['basis'];
					$delta    = $purchase['cash_delta'];
					$acquired = $event['effective_date'];
					$status   = 'complete';
				} else {
					$status = $event['basis_status'];
					if ( ! in_array( $status, array( 'complete', 'unresolved' ), true ) ) {
						throw new \InvalidArgumentException( 'Opening basis status is invalid.' );
					}
					$basis    = 'unresolved' === $status ? '0' : Decimal::input( $event['amount'], 12 );
					$acquired = $event['acquired_on'];
					if ( $acquired > $event['effective_date'] ) {
						throw new \InvalidArgumentException( 'Opening acquisition date is after its effective date.' );
					}
				}
				$lots[ $id ] = array(
					'id'                 => $id,
					'order_id'           => (int) ( $event['order_id'] ?? $id ),
					'asset_id'           => $asset,
					'quantity_initial'   => $quantity,
					'quantity_remaining' => $quantity,
					'basis_initial'      => $basis,
					'basis_remaining'    => $basis,
					'acquired_on'        => $acquired,
					'basis_status'       => $status,
				);
			} elseif ( 'sell' === $action ) {
				$asset     = (int) $event['asset_id'];
				$available = array_filter(
					$lots,
					static fn( array $lot ): bool => $lot['asset_id'] === $asset && Decimal::compare( $lot['quantity_remaining'], '0' ) > 0
				);
				foreach ( $available as $lot ) {
					if ( 'unresolved' === $lot['basis_status'] ) {
						throw new \InvalidArgumentException( 'Resolve opening basis before selling this asset.' );
					}
				}
				uasort(
					$available,
					static function ( array $left, array $right ): int {
						$date_order = strcmp( $left['acquired_on'], $right['acquired_on'] );
						return 0 !== $date_order ? $date_order : $left['order_id'] <=> $right['order_id'];
					}
				);
				$sale  = Ledger::sell( array_values( $available ), $event['quantity'], $event['unit_price'], $event['fees'] );
				$delta = $sale['cash_delta'];
				$gain  = $sale['realized_gain'];
				$used  = $sale['allocations'];
				foreach ( $used as $allocation ) {
					$lot_id                                = (int) $allocation['lot_id'];
					$lots[ $lot_id ]['quantity_remaining'] = $allocation['quantity_remaining'];
					$lots[ $lot_id ]['basis_remaining']    = $allocation['basis_remaining'];
				}
			} else {
				throw new \InvalidArgumentException( 'Replay does not support this financial action.' );
			}
			$cash = Decimal::money( Decimal::add( $cash, $delta ) );
			if ( Decimal::compare( $cash, '0' ) < 0 ) {
				throw new \InvalidArgumentException( 'Historical change would create a cash overdraft.' );
			}
			$effects[ $id ] = array(
				'cash_delta'    => Decimal::money( $delta ),
				'cash_after'    => $cash,
				'realized_gain' => Decimal::money( $gain ),
				'allocations'   => $used,
			);
		}
		return array(
			'cash_balance' => $cash,
			'lots'         => $lots,
			'effects'      => $effects,
		);
	}
}
