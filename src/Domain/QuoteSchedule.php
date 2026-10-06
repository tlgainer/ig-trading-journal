<?php
/**
 * Explicit weekday end-of-day refresh times in New York.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Domain;

/** Weekday clock policy; does not claim an exchange holiday calendar. */
final class QuoteSchedule {
	/**
	 * Return the next strictly future slot, honoring New York daylight saving.
	 *
	 * @param \DateTimeImmutable $now Current instant.
	 * @param string             $frequency off, once or twice.
	 * @return int|null UTC timestamp or null for disabled enrollment.
	 * @throws \InvalidArgumentException On unsupported frequency.
	 * @throws \LogicException On an unavailable future slot.
	 */
	public static function next( \DateTimeImmutable $now, string $frequency ): ?int {
		if ( 'off' === $frequency ) {
			return null;
		}
		if ( ! in_array( $frequency, array( 'once', 'twice' ), true ) ) {
			throw new \InvalidArgumentException( 'Invalid quote refresh frequency.' );
		}
		$local = $now->setTimezone( new \DateTimeZone( 'America/New_York' ) );
		$hours = 'twice' === $frequency ? array( 18, 22 ) : array( 18 );
		for ( $day = 0; $day < 8; ++$day ) {
			$date = $local->modify( '+' . $day . ' days' );
			if ( (int) $date->format( 'N' ) > 5 ) {
				continue;
			}
			foreach ( $hours as $hour ) {
				$slot = $date->setTime( $hour, 30 );
				if ( $slot > $now ) {
					return $slot->getTimestamp();
				}
			}
		}
		throw new \LogicException( 'No future quote slot found.' );
	}
}
