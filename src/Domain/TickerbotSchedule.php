<?php
/**
 * UTC elapsed cadence and conservative quote-call projections.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Domain;

/** No exchange calendar or page-visit dependency is implied by this policy. */
final class TickerbotSchedule {
	/**
	 * Calculate the next future slot without replaying missed intervals.
	 *
	 * @param \DateTimeImmutable $now Current instant.
	 * @param int                $interval Elapsed seconds.
	 * @return string UTC database timestamp.
	 * @throws \InvalidArgumentException On unsupported cadence.
	 */
	public static function next( \DateTimeImmutable $now, int $interval = 7200 ): string {
		if ( ! in_array( $interval, array( 3600, 7200, 86400 ), true ) ) {
			throw new \InvalidArgumentException( 'Choose hourly, every two hours or daily.' );
		}
		return $now->setTimezone( new \DateTimeZone( 'UTC' ) )->modify( '+' . $interval . ' seconds' )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Estimate thirty-one days of deduplicated quote batches, excluding retries/discovery.
	 *
	 * @param int $symbols Unique mapped symbols.
	 * @param int $interval Elapsed seconds.
	 * @return int Planned quote attempts, never a guarantee of remaining provider usage.
	 * @throws \InvalidArgumentException On unsupported size/cadence.
	 */
	public static function projected( int $symbols, int $interval = 7200 ): int {
		self::next( new \DateTimeImmutable( '2000-01-01T00:00:00Z' ), $interval );
		if ( $symbols < 0 || $symbols > 10000 ) {
			throw new \InvalidArgumentException( 'Invalid projected symbol count.' );
		}
		return intdiv( $symbols + 49, 50 ) * intdiv( 86400, $interval ) * 31;
	}
}
